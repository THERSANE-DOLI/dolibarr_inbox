<?php
/* Copyright (C) 2026 THERSANE www.thersane.fr
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    inbox/ajax/mail.php
 * \ingroup inbox
 * \brief   A mail of the index (pane 3): open, read / unread, source, remote pictures of a sender
 */

require __DIR__.'/inbox_ajax.inc.php';
dol_include_once('/inbox/class/inboxreader.class.php');

$action = (string) inboxAjaxParam('action', 'aZ09');
inboxAjaxStart(in_array($action, array('seen', 'images', 'unsubscribe'), true));
$reader = new InboxReader($db);

/**
 * @param InboxReader $reader Reader
 * @return void Answers 404 (not found or not allowed) or 410 (removed from the server since the last pass)
 */
function inboxMailFail(InboxReader $reader)
{
	global $langs;

	if ($reader->errorCode === 'gone') {
		inboxAjaxError($langs->transnoentities('InboxMailGone'), 410);
	}
	if ($reader->errorCode === 'not_found') {
		inboxAjaxError($langs->transnoentities('InboxMailNotFound'), 404);
	}
	inboxAjaxError($langs->transnoentities('InboxMailReadError', $reader->error), 502);
}

if (!$reader->load($user, (int) inboxAjaxParam('id', 'int'))) {
	inboxMailFail($reader);
}

switch ($action) {
	case 'open':
		$raw = $reader->raw($user);
		if ($raw === null) {
			inboxMailFail($reader);
		}
		$out = $reader->describe($user, $raw);
		// « Assigned to » of the reading (lot W4 1.9.0)
		dol_include_once('/inbox/class/inboxassign.class.php');
		$assign = new InboxAssign($db);
		$assignees = $assign->forRows(array($reader->row));
		$out['assignees'] = $assignees[(int) $reader->row->rowid] ?? array();
		// Own key: « people » are the users of Dolibarr among the addresses of the mail (head of the reading)
		$out['assignee_people'] = (object) $assign->cards($assignees);
		$out['has_message_id'] = true;
		inboxAjaxReply($out);
		// no break

	case 'thread':
		// Mails of the conversation in the whole mailbox (sent mails included), the oldest first; a mail in two folders
		// once (the copy outside the trash and the junk first)
		$key = (string) $reader->row->thread_key;
		$list = array();
		if ($key !== '') {
			$p = $db->prefix();
			$rows = $db->getRows("SELECT m.rowid, m.fk_folder, m.message_id, m.from_email, m.from_name, m.to_text, m.subject, m.snippet, m.date_received, m.date_mail, m.seen, m.answered,"
				." m.flagged, m.has_attachments, f.role FROM ".$p."inbox_mail as m INNER JOIN ".$p."inbox_folder as f ON f.rowid = m.fk_folder AND f.status = 1"
				." WHERE m.fk_account = ".((int) $reader->row->fk_account)." AND m.thread_key = '".$db->escape($key)."'"
				// In the order the mails were written (Date header), the arrival when it is missing
				." ORDER BY COALESCE(m.date_mail, m.date_received) ASC, m.rowid ASC LIMIT 200");
			$byMsg = array();
			foreach (is_array($rows) ? $rows : array() as $r) {
				$id = $r->message_id ? (string) $r->message_id : 'row'.$r->rowid;
				$bad = in_array((string) $r->role, array('trash', 'junk'), true);
				if (isset($byMsg[$id]) && !($byMsg[$id]['bad'] && !$bad) && (int) $r->rowid !== (int) $reader->row->rowid) {
					continue;
				}
				$date = $r->date_mail ? $r->date_mail : $r->date_received;
				$byMsg[$id] = array('bad' => $bad, 'mail' => array(
					'id' => (int) $r->rowid, 'folder' => (int) $r->fk_folder, 'role' => (string) $r->role, 'from_email' => (string) $r->from_email, 'from_name' => (string) $r->from_name,
					'to' => dol_trunc((string) $r->to_text, 300, 'right', 'UTF-8', 1), 'subject' => (string) $r->subject, 'snippet' => (string) $r->snippet,
					'date' => $date ? (int) $db->jdate($date) : 0, 'seen' => (int) $r->seen, 'answered' => (int) $r->answered, 'flagged' => (int) $r->flagged,
					'attachments' => (int) $r->has_attachments, 'has_mid' => 1,
				));
			}
			foreach ($byMsg as $v) {
				$list[] = $v['mail'];
			}
		}
		if (!$list) {
			$list[] = array('id' => (int) $reader->row->rowid);
		}
		// People assigned to each mail of the conversation
		dol_include_once('/inbox/class/inboxassign.class.php');
		$assign = new InboxAssign($db);
		$found = $db->getRows("SELECT rowid, fk_account, message_id, date_mail, from_email, subject, size FROM ".$db->prefix()."inbox_mail WHERE rowid IN (".implode(',', array_map(function ($m) {
			return (int) $m['id'];
		}, $list)).") LIMIT 500");
		$assignees = $assign->forRows(is_array($found) ? $found : array());
		foreach ($list as $i => $m) {
			$list[$i]['assignees'] = $assignees[(int) $m['id']] ?? array();
		}
		inboxAjaxReply(array('key' => $key, 'mails' => $list, 'people' => (object) $assign->cards($assignees)));
		// no break

	case 'seen':
		$seen = (int) inboxAjaxParam('seen', 'int') === 1;
		if (!$reader->setSeen($seen)) {
			inboxAjaxError($langs->transnoentities('InboxMailFlagError', $reader->error), 502);
		}
		inboxAjaxReply(array('id' => (int) $reader->row->rowid, 'seen' => $seen ? 1 : 0));
		// no break

	case 'images':
		// Remote pictures always shown (allow) or no more (forget) for the sender or its domain, for this user
		$what = (string) inboxAjaxParam('what', 'aZ09');
		$email = strtolower((string) $reader->row->from_email);
		if (!isValidEmail($email)) {
			inboxAjaxError($langs->transnoentities('InboxErrorEmail'), 422);
		}
		$address = $what === 'domain' ? substr($email, strpos($email, '@')) : $email;
		$p = $db->prefix();
		$db->query("DELETE FROM ".$p."inbox_image_allow WHERE fk_user = ".((int) $user->id)." AND address IN ('".$db->escape($email)."', '".$db->escape(substr($email, strpos($email, '@')))."')");
		if ((string) inboxAjaxParam('mode', 'aZ09') !== 'forget') {
			$db->query("INSERT INTO ".$p."inbox_image_allow (fk_user, address, date_creation) VALUES (".((int) $user->id).", '".$db->escape($address)."', '".$db->idate(dol_now())."')");
		}
		inboxAjaxReply(array('address' => $address));
		// no break

	case 'unsubscribe':
		// List-Unsubscribe one-click (RFC 8058), sent by this server; the mail is read again here, never an address
		// given by the browser
		$raw = $reader->raw($user);
		if ($raw === null) {
			inboxMailFail($reader);
		}
		$key = $reader->unsubscribe($user, $raw);
		if ($key !== '') {
			$codes = array('InboxErrorNeedManage' => 403, 'InboxErrorUnsubscribeNone' => 422, 'InboxErrorUnsubscribeNoOneClick' => 422, 'InboxErrorUnsubscribeNotSigned' => 422);
			inboxAjaxReply(array('error' => $langs->transnoentities($key, $reader->error), 'code' => $key), $codes[$key] ?? 502);
		}
		inboxAjaxReply(array('done' => 1));
		// no break

	default:
		inboxAjaxError('Unknown action', 400);
}
