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
 * \file    inbox/class/inboxthreadmark.class.php
 * \ingroup inbox
 * \brief   Conversations ignored or watched (lot W4 1.11.0, B8, keys K and W of Thunderbird)
 */

require_once __DIR__.'/inboxaccess.class.php';
require_once __DIR__.'/inboxaccount.class.php';
require_once __DIR__.'/inboxactionlog.class.php';
require_once __DIR__.'/inboxactions.class.php';

/**
 * - Ignore: for the whole mailbox, since « read » is shared on the mail server: the mails of the thread are marked read
 *   now, and the answers that come later arrive read (the synchronisation does it). Level « manage », journaled.
 * - Watch: for one user, the lines of the thread are highlighted. Level « read ».
 * A mark is tied to the key of the thread in the mailbox; when the threader merges two threads, the mark follows.
 */
class InboxThreadMark
{
	const IGNORE = 'ignore';
	const WATCH = 'watch';

	/** @var DoliDB */
	protected $db;

	/** @var string */
	public $error = '';

	/** @var int Mails marked read when a thread is ignored */
	public $count = 0;

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct(DoliDB $db)
	{
		$this->db = $db;
	}

	/**
	 * Ignore or watch the thread of a mail, or stop
	 *
	 * @param User   $user   User
	 * @param int    $mailId A mail of the thread
	 * @param string $mode   ignore or watch
	 * @param bool   $on     Set or removed
	 * @return int 1 if changed, 0 if nothing to do, < 0 if KO
	 */
	public function set(User $user, $mailId, $mode, $on)
	{
		global $conf;

		$this->error = '';
		$this->count = 0;
		if (!in_array($mode, array(self::IGNORE, self::WATCH), true)) {
			$this->error = 'Bad mode';
			return -1;
		}
		$p = $this->db->prefix();
		$m = $this->db->getRow("SELECT m.rowid, m.fk_account, m.thread_key, m.message_id, m.subject FROM ".$p."inbox_mail as m WHERE m.rowid = ".((int) $mailId));
		$access = new InboxAccess($this->db);
		if (!is_object($m) || !$access->check($user, (int) $m->fk_account, InboxAccess::LEVEL_READ)) {
			$this->error = 'InboxMailNotFound';
			return -1;
		}
		if ($mode === self::IGNORE && !$access->check($user, (int) $m->fk_account, InboxAccess::LEVEL_MANAGE)) {
			$this->error = 'InboxErrorNeedManage';
			return -1;
		}
		if ((string) $m->thread_key === '') {
			$this->error = 'InboxErrorThreadNotReady';
			return -1;
		}
		$uid = $mode === self::WATCH ? (int) $user->id : 0;
		$where = " WHERE fk_account = ".((int) $m->fk_account)." AND thread_key = '".$this->db->escape((string) $m->thread_key)."' AND fk_user = ".$uid." AND mode = '".$mode."'";
		$has = is_object($this->db->getRow("SELECT rowid FROM ".$p."inbox_thread_mark".$where));
		if ($has === (bool) $on) {
			return 0;
		}
		if ($on) {
			$ok = $this->db->query("INSERT INTO ".$p."inbox_thread_mark (entity, fk_account, thread_key, fk_user, mode, fk_user_creat, date_creation) VALUES (".((int) $conf->entity).","
				." ".((int) $m->fk_account).", '".$this->db->escape((string) $m->thread_key)."', ".$uid.", '".$mode."', ".((int) $user->id).", '".$this->db->idate(dol_now())."')");
		} else {
			$ok = $this->db->query("DELETE FROM ".$p."inbox_thread_mark".$where);
		}
		if (!$ok) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		if ($mode === self::IGNORE) {
			InboxActionLog::add($this->db, $user, (int) $m->fk_account, $on ? 'thread_ignore' : 'thread_unignore', array($m));
			if ($on) {
				// The mails of the thread are read now, on the server then in the index (as Thunderbird does)
				$rows = $this->db->getRows("SELECT rowid FROM ".$p."inbox_mail WHERE fk_account = ".((int) $m->fk_account)." AND thread_key = '".$this->db->escape((string) $m->thread_key)."'"
					." AND seen = 0 LIMIT ".InboxActions::MAX);
				$ids = array_map(function ($r) {
					return (int) $r->rowid;
				}, is_array($rows) ? $rows : array());
				if ($ids) {
					$actions = new InboxActions($this->db);
					$res = $actions->run($user, 'seen', $ids, array('on' => true));
					$this->count = (int) $res['count'];
				}
			}
		}

		return 1;
	}

	/**
	 * Marks of the threads of rows of a list, for a user
	 *
	 * @param User                           $user User
	 * @param array<int,array<string,mixed>> $rows Rows formatted (account, tkey or thread.key), changed: ignored, watched
	 * @return void
	 */
	public function decorate(User $user, array &$rows)
	{
		$keys = array();
		foreach ($rows as $r) {
			$k = self::keyOf($r);
			if ($k !== '') {
				$keys[(int) $r['account']][$k] = "'".$this->db->escape($k)."'";
			}
		}
		$marks = array();
		foreach ($keys as $acc => $list) {
			$found = $this->db->getRows("SELECT thread_key, fk_user, mode FROM ".$this->db->prefix()."inbox_thread_mark WHERE fk_account = ".((int) $acc)
				." AND thread_key IN (".implode(',', $list).") AND (fk_user = 0 OR fk_user = ".((int) $user->id).") LIMIT 10000");
			foreach (is_array($found) ? $found : array() as $f) {
				$marks[$acc.'|'.$f->thread_key][(string) $f->mode] = true;
			}
		}
		foreach ($rows as $i => $r) {
			$m = $marks[(int) $r['account'].'|'.self::keyOf($r)] ?? array();
			$rows[$i]['ignored'] = !empty($m[self::IGNORE]) ? 1 : 0;
			$rows[$i]['watched'] = !empty($m[self::WATCH]) ? 1 : 0;
		}
	}

	/**
	 * @param array<string,mixed> $r Row formatted
	 * @return string Key of its thread ('' when none yet)
	 */
	protected static function keyOf(array $r)
	{
		$k = !empty($r['thread']['key']) ? (string) $r['thread']['key'] : (string) ($r['tkey'] ?? '');

		return preg_match('/^[0-9a-f]{32}$/', $k) ? $k : '';
	}

	/**
	 * Synchronisation: the new mails of ignored threads arrive read (on the server, then in the index)
	 *
	 * @param InboxConnectorInterface $connector Connected to the mailbox
	 * @param int                     $accountId Mailbox
	 * @param string                  $path      Folder of the mails
	 * @param int[]                   $mailIds   New rows of the index
	 * @return int Mails marked read
	 */
	public function applyIgnored($connector, $accountId, $path, array $mailIds)
	{
		$mailIds = array_filter(array_map('intval', $mailIds));
		if (!$mailIds) {
			return 0;
		}
		$p = $this->db->prefix();
		$rows = $this->db->getRows("SELECT m.rowid, m.remote_id FROM ".$p."inbox_mail as m INNER JOIN ".$p."inbox_thread_mark as t ON t.fk_account = m.fk_account AND t.thread_key = m.thread_key"
			." AND t.mode = '".self::IGNORE."' AND t.fk_user = 0 WHERE m.rowid IN (".implode(',', $mailIds).") AND m.seen = 0 LIMIT 5000");
		if (!is_array($rows) || !$rows) {
			return 0;
		}
		$byValidity = array();
		foreach ($rows as $r) {
			$byValidity[strtok((string) $r->remote_id, ':')][] = $r;
		}
		$done = 0;
		foreach ($byValidity as $list) {
			$remote = array_map(function ($r) {
				return (string) $r->remote_id;
			}, $list);
			if (!$connector->setFlags($path, $remote, array('\\Seen'), array())) {
				dol_syslog(__METHOD__.' '.$connector->getError(), LOG_WARNING);
				continue;
			}
			$this->db->query("UPDATE ".$p."inbox_mail SET seen = 1 WHERE rowid IN (".implode(',', array_map(function ($r) {
				return (int) $r->rowid;
			}, $list)).")");
			$done += count($list);
		}

		return $done;
	}

	/**
	 * Threads merged by the threader: the marks of the old keys go to the key kept
	 *
	 * @param DoliDB   $db        Database handler
	 * @param int      $accountId Mailbox
	 * @param string   $key       Key kept
	 * @param string[] $old       Keys merged into it
	 * @return void
	 */
	public static function merge(DoliDB $db, $accountId, $key, array $old)
	{
		$p = $db->prefix();
		foreach ($old as $k) {
			$rows = $db->getRows("SELECT rowid, fk_user, mode FROM ".$p."inbox_thread_mark WHERE fk_account = ".((int) $accountId)." AND thread_key = '".$db->escape((string) $k)."' LIMIT 1000");
			foreach (is_array($rows) ? $rows : array() as $r) {
				$exists = $db->getRow("SELECT rowid FROM ".$p."inbox_thread_mark WHERE fk_account = ".((int) $accountId)." AND thread_key = '".$db->escape((string) $key)."'"
					." AND fk_user = ".((int) $r->fk_user)." AND mode = '".$db->escape((string) $r->mode)."'");
				if (is_object($exists)) {
					$db->query("DELETE FROM ".$p."inbox_thread_mark WHERE rowid = ".((int) $r->rowid));
				} else {
					$db->query("UPDATE ".$p."inbox_thread_mark SET thread_key = '".$db->escape((string) $key)."' WHERE rowid = ".((int) $r->rowid));
				}
			}
		}
	}
}
