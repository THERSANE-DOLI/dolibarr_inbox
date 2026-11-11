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
 * \file    inbox/class/inboxactionlog.class.php
 * \ingroup inbox
 * \brief   Journal of the actions on the mails (lot W4 §4.2): who moved, archived, removed, tagged
 */

/**
 * One line per action (an action on several mails = one line with their number). Read / unread and starred are not
 * written. Nothing of the mail beyond its subject; the name of the author is kept in the line (removed users).
 */
class InboxActionLog
{
	/** Actions of the journal */
	const ACTIONS = array('move', 'archive', 'delete', 'destroy', 'junk', 'notjunk', 'tag', 'assign', 'unassign',
		'folder_create', 'folder_rename', 'folder_move', 'folder_delete', 'folder_destroy', 'empty', 'thread_ignore', 'thread_unignore', 'unsubscribe', 'import', 'export', 'attach', 'send', 'redirect', 'invite_reply', 'receipt', 'rules', 'away', 'resoc');

	/** @var int[] Lines written during this request (given back to the browser for the undo) */
	public static $written = array();

	/** @var bool Do not write (the undo marks the line it cancels instead) */
	public static $paused = false;

	/**
	 * Write a line
	 *
	 * @param DoliDB   $db        Database handler
	 * @param User     $user      Author
	 * @param int      $accountId Mailbox
	 * @param string   $action    One of ACTIONS
	 * @param object[] $mails     Rows of the index (message_id, subject)
	 * @param string   $from      Source folder
	 * @param string   $to        Target folder
	 * @param int      $accountTo Target mailbox when it is another one
	 * @param string   $detail    Tags added / removed...
	 * @return int                Id of the line, 0 when not written
	 */
	public static function add(DoliDB $db, User $user, $accountId, $action, array $mails, $from = '', $to = '', $accountTo = 0, $detail = '')
	{
		global $conf;

		if (self::$paused || !$mails) {
			return 0;
		}
		// Protected with its content (1.89.0): the journal of the actions names the mails (subject, Message-ID), nothing kept
		$protected = $db->getRow("SELECT protected, protect_level FROM ".$db->prefix()."inbox_account WHERE rowid = ".((int) $accountId));
		if (is_object($protected) && (int) $protected->protected === 1 && (string) $protected->protect_level === 'content') {
			return 0;
		}
		$n = count($mails);
		$first = reset($mails);
		// An action on a folder: one line carrying its number of mails
		if ($n === 1 && isset($first->nb)) {
			$n = max(1, (int) $first->nb);
		}
		$name = trim($user->firstname.' '.$user->lastname);
		$s = function ($v, $len = 255) use ($db) {
			$v = (string) $v;
			return $v === '' ? 'NULL' : "'".$db->escape(dol_trunc($v, $len, 'right', 'UTF-8', 1))."'";
		};
		$subject = (string) ($first->subject ?? '');
		// Folders as people read them (« INBOX / Clients / Été »), the path of the server is modified UTF-7
		$from = self::readable($db, (int) $accountId, (string) $from);
		$to = self::readable($db, $accountTo ? (int) $accountTo : (int) $accountId, (string) $to);
		$sql = "INSERT INTO ".$db->prefix()."inbox_action_log (entity, fk_account, fk_user, user_name, action, nb, message_id, subject, folder_from, folder_to, fk_account_to,"
			." detail, undone, date_action) VALUES (".((int) $conf->entity).", ".((int) $accountId).", ".((int) $user->id).", ".$s($name !== '' ? $name : $user->login).","
			." '".$db->escape(substr((string) $action, 0, 32))."', ".$n.", ".($n === 1 ? $s($first->message_id ?? '') : 'NULL').", ".$s($subject).", ".$s($from).", ".$s($to).","
			." ".($accountTo ? (int) $accountTo : 'NULL').", ".$s($detail).", 0, '".$db->idate(dol_now())."')";
		if (!$db->query($sql)) {
			dol_syslog(__METHOD__.' '.$db->lasterror(), LOG_WARNING);
			return 0;
		}
		$id = (int) $db->last_insert_id($db->prefix().'inbox_action_log');
		self::$written[] = $id;

		return $id;
	}

	/**
	 * Path of a folder for the journal: decoded, the delimiter of the mailbox shown as « / »
	 *
	 * @param DoliDB $db        Database handler
	 * @param int    $accountId Mailbox of the folder
	 * @param string $path      Path on the server
	 * @return string
	 */
	public static function readable(DoliDB $db, $accountId, $path)
	{
		if ($path === '') {
			return '';
		}
		$text = $path;
		if (strpos($path, '&') !== false && function_exists('mb_convert_encoding')) {
			$decoded = @mb_convert_encoding($path, 'UTF-8', 'UTF7-IMAP');
			if (is_string($decoded) && $decoded !== '') {
				$text = $decoded;
			}
		}
		$row = $db->getRow("SELECT delimiter FROM ".$db->prefix()."inbox_folder WHERE fk_account = ".((int) $accountId)." AND delimiter IS NOT NULL AND delimiter <> ''");
		$delim = is_object($row) ? (string) $row->delimiter : '';

		return $delim !== '' ? str_replace($delim, ' / ', $text) : $text;
	}

	/**
	 * Mark lines as undone (only lines of this author, a few seconds old at most: the undo of the toast)
	 *
	 * @param DoliDB $db   Database handler
	 * @param User   $user Author
	 * @param int[]  $ids  Lines
	 * @return void
	 */
	public static function markUndone(DoliDB $db, User $user, array $ids)
	{
		$ids = array_filter(array_map('intval', $ids));
		if ($ids) {
			$db->query("UPDATE ".$db->prefix()."inbox_action_log SET undone = 1 WHERE rowid IN (".implode(',', $ids).") AND fk_user = ".((int) $user->id)
				." AND date_action > '".$db->idate(dol_now() - 600)."'");
		}
	}

	/**
	 * The action gives back what the lines of the journal did: a tag changed again, or mails moved back from where the
	 * lines took them to where they were (same mailboxes, same folders). Only these two make an undo (InboxActions).
	 * Audit INBX-016: no other action hides itself from the journal behind a line of its author.
	 *
	 * @param DoliDB               $db     Database handler
	 * @param string               $what   Action asked
	 * @param int[]                $ids    Mails
	 * @param array<string,mixed>  $params Parameters (folder)
	 * @param array<int,object>    $lines  Lines of the journal named by the undo
	 * @return bool
	 */
	public static function undoes(DoliDB $db, $what, array $ids, array $params, array $lines)
	{
		$moves = array('move', 'archive', 'delete', 'junk', 'notjunk');
		$nb = 0;
		$mids = array();
		$named = true;
		foreach ($lines as $l) {
			if ($l->action !== 'tag' && !in_array($l->action, $moves, true)) {
				return false;
			}
			$nb += max(1, (int) ($l->nb ?? 1));
			// A line of one mail names it: the undo gives back that mail, not another one (audit INBX-080)
			if ((string) ($l->message_id ?? '') !== '') {
				$mids[] = (string) $l->message_id;
			} else {
				$named = false;
			}
		}
		// Never more mails than the lines did
		if (!$ids || count(array_unique($ids)) > $nb) {
			return false;
		}
		if ($named) {
			$rows = $db->getRows("SELECT message_id FROM ".$db->prefix()."inbox_mail WHERE rowid IN (".implode(',', array_map('intval', $ids)).") LIMIT 1000");
			foreach (is_array($rows) ? $rows : array() as $r) {
				if (!in_array((string) $r->message_id, $mids, true)) {
					return false;
				}
			}
			if (!is_array($rows) || count($rows) !== count(array_unique($ids))) {
				return false;
			}
		}
		if ($what === 'tag') {
			return true;
		}
		if ($what !== 'move' || !$ids) {
			return false;
		}
		$p = $db->prefix();
		$target = $db->getRow("SELECT fk_account, path FROM ".$p."inbox_folder WHERE rowid = ".((int) $params['folder']));
		$mails = $db->getRows("SELECT m.fk_account, f.path FROM ".$p."inbox_mail as m INNER JOIN ".$p."inbox_folder as f ON f.rowid = m.fk_folder"
			." WHERE m.rowid IN (".implode(',', array_map('intval', $ids)).") LIMIT 1000");
		if (!is_object($target) || !is_array($mails) || count($mails) !== count(array_unique($ids))) {
			return false;
		}
		foreach ($mails as $m) {
			$back = false;
			foreach ($lines as $l) {
				$to = (int) $l->fk_account_to > 0 ? (int) $l->fk_account_to : (int) $l->fk_account;
				if (in_array($l->action, $moves, true) && (int) $m->fk_account === $to && (string) $m->path === (string) $l->folder_to
					&& (int) $target->fk_account === (int) $l->fk_account && (string) $target->path === (string) $l->folder_from) {
					$back = true;
					break;
				}
			}
			if (!$back) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Lines of a mailbox, most recent first
	 *
	 * @param DoliDB              $db        Database handler
	 * @param int                 $accountId Mailbox
	 * @param array<string,mixed> $filters   user (id), action, from / to (timestamps), q (subject or Message-ID)
	 * @param int                 $limit     Lines
	 * @param int                 $offset    First line
	 * @return array{lines:array<int,array<string,mixed>>,more:bool}
	 */
	public static function listForAccount(DoliDB $db, $accountId, array $filters = array(), $limit = 50, $offset = 0)
	{
		global $conf;

		$p = $db->prefix();
		$limit = max(1, min(200, (int) $limit));
		$where = " WHERE l.entity = ".((int) $conf->entity)." AND (l.fk_account = ".((int) $accountId)." OR l.fk_account_to = ".((int) $accountId).")";
		if (!empty($filters['user'])) {
			$where .= " AND l.fk_user = ".((int) $filters['user']);
		}
		if (!empty($filters['action']) && in_array($filters['action'], self::ACTIONS, true)) {
			$where .= " AND l.action = '".$db->escape($filters['action'])."'";
		}
		if (!empty($filters['from'])) {
			$where .= " AND l.date_action >= '".$db->idate((int) $filters['from'])."'";
		}
		if (!empty($filters['to'])) {
			$where .= " AND l.date_action < '".$db->idate((int) $filters['to'])."'";
		}
		if (isset($filters['q']) && trim((string) $filters['q']) !== '') {
			$q = $db->escape($db->escapeforlike(trim((string) $filters['q'])));
			$where .= " AND (l.subject LIKE '%".$q."%' OR l.message_id LIKE '%".$q."%')";
		}
		$rows = $db->getRows("SELECT l.rowid, l.fk_user, l.user_name, l.action, l.nb, l.message_id, l.subject, l.folder_from, l.folder_to, l.fk_account, l.fk_account_to, l.detail,"
			." l.undone, l.date_action, a.label as account_to_label FROM ".$p."inbox_action_log as l LEFT JOIN ".$p."inbox_account as a ON a.rowid = l.fk_account_to"
			.$where." ORDER BY l.date_action DESC, l.rowid DESC".$db->plimit($limit + 1, max(0, (int) $offset)));
		$lines = array();
		foreach (is_array($rows) ? $rows : array() as $r) {
			$lines[] = array(
				'id' => (int) $r->rowid, 'date' => (int) $db->jdate($r->date_action), 'fk_user' => (int) $r->fk_user, 'user' => (string) $r->user_name,
				'action' => (string) $r->action, 'nb' => (int) $r->nb, 'message_id' => (string) $r->message_id, 'subject' => (string) $r->subject,
				'from' => (string) $r->folder_from, 'to' => (string) $r->folder_to, 'account_to' => (int) $r->fk_account_to === (int) $accountId ? '' : (string) $r->account_to_label,
				'incoming' => (int) $r->fk_account !== (int) $accountId, 'detail' => (string) $r->detail, 'undone' => (int) $r->undone,
			);
		}
		$more = count($lines) > $limit;

		return array('lines' => array_slice($lines, 0, $limit), 'more' => $more);
	}

	/**
	 * Authors found in the journal of a mailbox (filter by person)
	 *
	 * @param DoliDB $db        Database handler
	 * @param int    $accountId Mailbox
	 * @return array<int,array{id:int,name:string}>
	 */
	public static function authors(DoliDB $db, $accountId)
	{
		$rows = $db->getRows("SELECT fk_user, MAX(user_name) as name FROM ".$db->prefix()."inbox_action_log WHERE fk_account = ".((int) $accountId)
			." GROUP BY fk_user ORDER BY name LIMIT 500");
		$out = array();
		foreach (is_array($rows) ? $rows : array() as $r) {
			$out[] = array('id' => (int) $r->fk_user, 'name' => (string) $r->name);
		}

		return $out;
	}

	/**
	 * Remove the lines older than the retention (setup INBOX_ACTION_LOG_MONTHS, 12 by default; 0 = kept)
	 *
	 * @param DoliDB $db Database handler
	 * @return int Lines removed, < 0 if KO
	 */
	public static function purge(DoliDB $db)
	{
		$months = getDolGlobalString('INBOX_ACTION_LOG_MONTHS') === '' ? 12 : getDolGlobalInt('INBOX_ACTION_LOG_MONTHS');
		if ($months <= 0) {
			return 0;
		}
		if (!$db->query("DELETE FROM ".$db->prefix()."inbox_action_log WHERE date_action < '".$db->idate(dol_time_plus_duree(dol_now(), -$months, 'm'))."'")) {
			return -1;
		}

		return (int) $db->affected_rows(true);
	}
}
