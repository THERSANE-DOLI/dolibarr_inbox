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
 * \file    inbox/class/inboxassign.class.php
 * \ingroup inbox
 * \brief   Who handles a mail (lot W4 1.9.0, webmail §5.4 quinquies): assignments tied to the mailref
 */

require_once __DIR__.'/inboxaccess.class.php';
require_once __DIR__.'/inboxaccount.class.php';
require_once __DIR__.'/inboxactionlog.class.php';
require_once __DIR__.'/inboxmailref.class.php';
require_once __DIR__.'/inboxnotification.class.php';

/**
 * Anyone who can read a mailbox may assign himself or another person who can read it (lot W4 §10 point 2). An
 * assignment is tied to the mailref: it follows the mail in every move, and shows on every copy of the mail, for the
 * people who can read the mailbox of the copy. A person who cannot read the mailbox any more is never shown there.
 * Nothing is written on the mail server.
 */
class InboxAssign
{
	/** Mails per call */
	const MAX = 200;

	/** @var DoliDB */
	protected $db;

	/** @var string */
	public $error = '';

	/** @var array<int,array<int,array{id:int,name:string,level:string}>> People of each mailbox, read once per request */
	protected static $people = array();

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct(DoliDB $db)
	{
		$this->db = $db;
	}

	/**
	 * Clear the cache of the people of the mailboxes (an access changed)
	 *
	 * @return void
	 */
	public static function clearCache()
	{
		self::$people = array();
	}

	/**
	 * People who can read a mailbox
	 *
	 * @param int $accountId Mailbox
	 * @return array<int,array{id:int,name:string,level:string}>
	 */
	public function people($accountId)
	{
		$accountId = (int) $accountId;
		if (!isset(self::$people[$accountId])) {
			$access = new InboxAccess($this->db);
			self::$people[$accountId] = $access->usersOf($accountId);
		}

		return self::$people[$accountId];
	}

	/**
	 * Assign people to mails, or remove them
	 *
	 * @param User  $user    User doing it (reads the mailboxes of the mails)
	 * @param int[] $mailIds Rows of the index
	 * @param int[] $add     Users to assign
	 * @param int[] $remove  Users to remove
	 * @param int[] $expand  Rows of conversations folded in the list: all their mails of the folder
	 * @return array{ok:bool,count:int,error:string,assignees:array<int,int[]>,people:array<int,string>,skipped:int}
	 */
	public function set(User $user, array $mailIds, array $add, array $remove, array $expand = array())
	{
		global $conf;

		$out = array('ok' => false, 'count' => 0, 'error' => '', 'assignees' => array(), 'people' => array(), 'skipped' => 0);
		$p = $this->db->prefix();
		$mailIds = array_slice(array_values(array_unique(array_filter(array_map('intval', $mailIds)))), 0, self::MAX);
		$add = array_values(array_unique(array_filter(array_map('intval', $add))));
		$remove = array_values(array_diff(array_unique(array_filter(array_map('intval', $remove))), $add));
		if (!$mailIds || (!$add && !$remove)) {
			$out['error'] = 'No mail';
			return $out;
		}
		$expand = array_values(array_intersect($mailIds, array_map('intval', $expand)));
		if ($expand) {
			$more = $this->db->getRows("SELECT DISTINCT o.rowid FROM ".$p."inbox_mail as m INNER JOIN ".$p."inbox_mail as o ON o.fk_folder = m.fk_folder AND o.thread_key = m.thread_key"
				." WHERE m.rowid IN (".implode(',', $expand).") AND m.thread_key IS NOT NULL LIMIT ".self::MAX);
			foreach (is_array($more) ? $more : array() as $o) {
				$mailIds[] = (int) $o->rowid;
			}
			$mailIds = array_slice(array_values(array_unique($mailIds)), 0, self::MAX);
		}
		$rows = $this->db->getRows("SELECT m.rowid, m.fk_account, m.fk_folder, m.message_id, m.date_mail, m.from_email, m.subject, m.size FROM ".$p."inbox_mail as m"
			." INNER JOIN ".$p."inbox_folder as f ON f.rowid = m.fk_folder AND f.status = 1 WHERE m.rowid IN (".implode(',', $mailIds).") LIMIT ".(self::MAX + 1));
		$access = new InboxAccess($this->db);
		if (!is_array($rows) || count($rows) !== count($mailIds)) {
			$out['error'] = 'InboxMailNotFound';
			return $out;
		}
		foreach ($rows as $r) {
			if (!$access->check($user, (int) $r->fk_account, InboxAccess::LEVEL_READ)) {
				$out['error'] = 'InboxMailNotFound';
				return $out;
			}
			// Only people who can read the mailbox of the mail
			foreach ($add as $uid) {
				if (!isset($this->people((int) $r->fk_account)[$uid])) {
					$out['error'] = 'InboxErrorAssignNoAccess';
					return $out;
				}
			}
		}
		// A key for each mail: its Message-ID, or the print of a mail without one (1.13.0)
		$keyer = new InboxMailRef($this->db);
		$withId = array_values(array_filter($rows, function ($r) use ($keyer) {
			return $keyer->keyOf($r) !== '';
		}));
		$out['skipped'] = count($rows) - count($withId);
		if (!$withId) {
			$out['error'] = 'InboxErrorNoMessageId';
			return $out;
		}

		$refs = new InboxMailRef($this->db);
		$notify = new InboxNotification($this->db);
		$byRef = array();
		foreach ($withId as $r) {
			$id = $refs->fetchOrCreate($r, $user);
			if ($id <= 0) {
				$out['error'] = $refs->error;
				return $out;
			}
			$byRef[$id][] = $r;
		}
		$names = array();
		foreach (array_merge($add, $remove) as $uid) {
			foreach ($rows as $r) {
				$people = $this->people((int) $r->fk_account);
				if (isset($people[$uid])) {
					$names[$uid] = $people[$uid]['name'];
					break;
				}
			}
			if (!isset($names[$uid])) {
				$names[$uid] = $this->userName($uid);
			}
		}
		$log = array();
		$now = $this->db->idate(dol_now());
		foreach ($byRef as $refId => $list) {
			$current = array();
			$found = $this->db->getRows("SELECT fk_user FROM ".$p."inbox_mail_assign WHERE fk_mailref = ".((int) $refId)." LIMIT 1000");
			foreach (is_array($found) ? $found : array() as $f) {
				$current[] = (int) $f->fk_user;
			}
			$added = array_values(array_diff($add, $current));
			$removed = array_values(array_intersect($remove, $current));
			foreach ($added as $uid) {
				if (!$this->db->query("INSERT INTO ".$p."inbox_mail_assign (entity, fk_mailref, fk_user, fk_user_assigner, date_assign) VALUES (".((int) $conf->entity).", ".((int) $refId).","
					." ".$uid.", ".((int) $user->id).", '".$now."')")) {
					$out['error'] = 'InboxErrorAssignSave';
					return $out;
				}
			}
			if ($removed) {
				$this->db->query("DELETE FROM ".$p."inbox_mail_assign WHERE fk_mailref = ".((int) $refId)." AND fk_user IN (".implode(',', $removed).")");
			}
			if (!$added && !$removed) {
				continue;
			}
			$out['count'] += count($list);
			$first = $list[0];
			foreach ($added as $uid) {
				if ($uid !== (int) $user->id) {
					$notify->add($uid, 'assign', array('mailref' => $refId, 'account' => (int) $first->fk_account, 'actor' => (int) $user->id, 'payload' => array('subject' => (string) $first->subject)));
				}
			}
			foreach ($removed as $uid) {
				if ($uid !== (int) $user->id) {
					$notify->cancel($uid, 'assign', $refId, (int) $user->id);
				}
			}
			foreach ($list as $r) {
				$acc = (int) $r->fk_account;
				$log[$acc]['mails'][] = $r;
				$log[$acc]['added'] = array_unique(array_merge($log[$acc]['added'] ?? array(), $added));
				$log[$acc]['removed'] = array_unique(array_merge($log[$acc]['removed'] ?? array(), $removed));
			}
			$data = array('mailref' => $refId, 'message_id' => (string) $first->message_id, 'mails' => array_map(function ($r) {
				return (int) $r->rowid;
			}, $list));
			if ($added) {
				$this->trigger($user, 'INBOX_MAIL_ASSIGN', (int) $first->fk_account, $data + array('users' => $added));
			}
			if ($removed) {
				$this->trigger($user, 'INBOX_MAIL_UNASSIGN', (int) $first->fk_account, $data + array('users' => $removed));
			}
		}
		$label = function ($ids) use ($names) {
			return implode(', ', array_map(function ($uid) use ($names) {
				return $names[$uid];
			}, $ids));
		};
		foreach ($log as $acc => $l) {
			if ($l['added']) {
				InboxActionLog::add($this->db, $user, $acc, 'assign', $l['mails'], '', '', 0, $label($l['added']));
			}
			if ($l['removed']) {
				InboxActionLog::add($this->db, $user, $acc, 'unassign', $l['mails'], '', '', 0, $label($l['removed']));
			}
		}
		$out['ok'] = true;
		$out['assignees'] = $this->forRows($rows);
		$out['people'] = $this->names($out['assignees']);

		return $out;
	}

	/**
	 * Assigned people of rows of the index, among the people who can read the mailbox of each row
	 *
	 * @param object[] $rows Rows (rowid, fk_account, message_id, date_mail, from_email)
	 * @return array<int,int[]> Row => users
	 */
	public function forRows(array $rows)
	{
		$refs = new InboxMailRef($this->db);
		$byRow = $refs->forRows($rows);
		if (!$byRow) {
			return array();
		}
		$users = array();
		$found = $this->db->getRows("SELECT fk_mailref, fk_user FROM ".$this->db->prefix()."inbox_mail_assign WHERE fk_mailref IN (".implode(',', array_unique($byRow)).")"
			." ORDER BY date_assign, rowid LIMIT 100000");
		foreach (is_array($found) ? $found : array() as $f) {
			$users[(int) $f->fk_mailref][] = (int) $f->fk_user;
		}
		$out = array();
		foreach ($rows as $r) {
			$ref = $byRow[(int) $r->rowid] ?? 0;
			if (!$ref || empty($users[$ref])) {
				continue;
			}
			$people = $this->people((int) $r->fk_account);
			$list = array_values(array_filter($users[$ref], function ($uid) use ($people) {
				return isset($people[$uid]);
			}));
			if ($list) {
				$out[(int) $r->rowid] = $list;
			}
		}

		return $out;
	}

	/**
	 * Assigned people added to the rows of a list (lines of conversations: the people of any of their mails here)
	 *
	 * @param array<int,array<string,mixed>> $rows     Rows formatted for the application (id, folder, thread)
	 * @param bool                           $threads  Lines of conversations
	 * @return array<int,string> Names of the people shown (user => name)
	 */
	public function decorate(array &$rows, $threads = false)
	{
		if (!$rows) {
			return array();
		}
		$p = $this->db->prefix();
		$ids = array();
		$keys = array();
		foreach ($rows as $r) {
			$ids[] = (int) $r['id'];
			if ($threads && !empty($r['thread']['key']) && (int) ($r['thread']['count'] ?? 1) > 1) {
				$keys[(int) $r['folder']][] = "'".$this->db->escape((string) $r['thread']['key'])."'";
			}
		}
		$sql = "SELECT rowid, fk_account, fk_folder, message_id, date_mail, from_email, subject, size, thread_key FROM ".$p."inbox_mail WHERE rowid IN (".implode(',', $ids).")";
		foreach ($keys as $folder => $k) {
			$sql .= " OR (fk_folder = ".((int) $folder)." AND thread_key IN (".implode(',', array_unique($k))."))";
		}
		$found = $this->db->getRows($sql." LIMIT 20000");
		$found = is_array($found) ? $found : array();
		$assignees = $this->forRows($found);
		$byThread = array();
		foreach ($found as $f) {
			if ((string) $f->thread_key !== '' && isset($assignees[(int) $f->rowid])) {
				$k = (int) $f->fk_folder.'|'.$f->thread_key;
				$byThread[$k] = array_values(array_unique(array_merge($byThread[$k] ?? array(), $assignees[(int) $f->rowid])));
			}
		}
		$shown = array();
		foreach ($rows as $i => $r) {
			$list = $assignees[(int) $r['id']] ?? array();
			if ($threads && !empty($r['thread']['key'])) {
				$list = array_values(array_unique(array_merge($list, $byThread[(int) $r['folder'].'|'.$r['thread']['key']] ?? array())));
			}
			$rows[$i]['assignees'] = $list;
			$shown[(int) $r['id']] = $list;
		}

		return $this->names($shown);
	}

	/**
	 * Names of the people of a list of assignments
	 *
	 * @param array<int,int[]> $assignees Row => users
	 * @return array<int,string> User => name
	 */
	public function names(array $assignees)
	{
		$ids = array();
		foreach ($assignees as $list) {
			foreach ($list as $uid) {
				$ids[(int) $uid] = (int) $uid;
			}
		}
		if (!$ids) {
			return array();
		}
		$out = array();
		$users = $this->db->getRows("SELECT rowid, firstname, lastname, login FROM ".$this->db->prefix()."user WHERE rowid IN (".implode(',', $ids).") LIMIT 10000");
		foreach (is_array($users) ? $users : array() as $u) {
			$name = trim($u->firstname.' '.$u->lastname);
			$out[(int) $u->rowid] = $name !== '' ? $name : (string) $u->login;
		}

		return $out;
	}

	/**
	 * An access was removed: the assignments of the people who cannot read the mailbox any more are removed from its
	 * mails, unless they can still read another mailbox where the mail is (journal: « no more access »)
	 *
	 * @param User $author    User who changed the accesses
	 * @param int  $accountId Mailbox
	 * @return int Assignments removed
	 */
	public function prune(User $author, $accountId)
	{
		global $langs;

		self::clearCache();
		$p = $this->db->prefix();
		$people = $this->people((int) $accountId);
		$found = $this->db->getRows("SELECT DISTINCT a.rowid, a.fk_user, a.fk_mailref FROM ".$p."inbox_mail_assign as a INNER JOIN ".$p."inbox_mailref as r ON r.rowid = a.fk_mailref"
			." INNER JOIN ".$p."inbox_mail as m ON m.message_id = r.message_id AND m.fk_account = ".((int) $accountId)." LIMIT 100000");
		$refs = new InboxMailRef($this->db);
		$removed = 0;
		$byUser = array();
		foreach (is_array($found) ? $found : array() as $f) {
			$uid = (int) $f->fk_user;
			if (isset($people[$uid])) {
				continue;
			}
			$elsewhere = false;
			foreach ($refs->copies((int) $f->fk_mailref) as $c) {
				if ($c['account'] !== (int) $accountId && isset($this->people($c['account'])[$uid])) {
					$elsewhere = true;
					break;
				}
			}
			if ($elsewhere) {
				continue;
			}
			$this->db->query("DELETE FROM ".$p."inbox_mail_assign WHERE rowid = ".((int) $f->rowid));
			$removed++;
			$byUser[$uid][] = (object) array('message_id' => '', 'subject' => '');
		}
		if ($byUser) {
			$names = $this->names(array(array_keys($byUser)));
			foreach ($byUser as $uid => $mails) {
				InboxActionLog::add($this->db, $author, (int) $accountId, 'unassign', $mails, '', '', 0,
					$langs->transnoentities('InboxAssignNoMoreAccess', $names[$uid] ?? $this->userName($uid)));
			}
		}

		return $removed;
	}

	/**
	 * @param int $uid User
	 * @return string
	 */
	protected function userName($uid)
	{
		$u = $this->db->getRow("SELECT firstname, lastname, login FROM ".$this->db->prefix()."user WHERE rowid = ".((int) $uid));
		if (!is_object($u)) {
			return '#'.((int) $uid);
		}
		$name = trim($u->firstname.' '.$u->lastname);

		return $name !== '' ? $name : (string) $u->login;
	}

	/**
	 * @param User                $user      User
	 * @param string              $code      Trigger
	 * @param int                 $accountId Mailbox of a copy of the mail
	 * @param array<string,mixed> $data      Data of the trigger (context['inbox'] of the mailbox)
	 * @return void
	 */
	protected function trigger(User $user, $code, $accountId, array $data)
	{
		$account = new InboxAccount($this->db);
		if ($account->fetch((int) $accountId) > 0) {
			$account->context['inbox'] = $data;
			$account->call_trigger($code, $user);
		}
	}
}
