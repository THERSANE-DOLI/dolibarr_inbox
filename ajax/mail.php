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
inboxAjaxStart(in_array($action, array('seen', 'images'), true));
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
		inboxAjaxReply($reader->describe($user, $raw));
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

	default:
		inboxAjaxError('Unknown action', 400);
}
