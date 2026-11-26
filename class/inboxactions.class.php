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
 * \file    inbox/class/inboxactions.class.php
 * \ingroup inbox
 * \brief   Actions on mails (lot W4): flags, tags, move, archive, remove, junk, with their undo
 */

require_once __DIR__.'/inboxaccess.class.php';
require_once __DIR__.'/inboxaccount.class.php';
require_once __DIR__.'/inboxfolder.class.php';
require_once __DIR__.'/inboxmail.class.php';
require_once __DIR__.'/inboxreader.class.php';
require_once __DIR__.'/inboxactionlog.class.php';
require_once __DIR__.'/inboxsync.class.php';
require_once __DIR__.'/connector/inboxconnectorfactory.class.php';
require_once __DIR__.'/inboxlive.class.php';

/**
 * Every write follows the same order (P8): write on the mail server, check, then the index. Never the index first.
 * The rights are checked here, per mailbox: « read » for read / unread and starred, « manage » for the rest (lot W4
 * §10 point 2). Each action gives back the steps of its undo (other actions, checked the same way).
 */
class InboxActions
{
	/** Mails per call (the browser sends larger selections by batches of this size) */
	const MAX = 200;

	/** @var DoliDB */
	protected $db;

	/** @var string */
	public $error = '';

	/** @var array<string,InboxConnectorInterface> Connectors opened during the call, per mailbox */
	protected $connectors = array();

	/** @var array<int,InboxAccount> */
	protected $accounts = array();

	/** @var bool Action of inbox itself (blocked senders put away by the synchronisation): no check of the access */
	protected $system = false;

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct(DoliDB $db)
	{
		$this->db = $db;
	}

	/**
	 * @return void
	 */
	public function __destruct()
	{
		foreach ($this->connectors as $c) {
			$c->disconnect();
		}
	}

	/**
	 * Action of inbox itself, not of a user: the mails of a blocked sender put away by the synchronisation (1.85.0).
	 * The access of the user is not checked (he is the user of the job); only « blocked » (junk) is allowed.
	 *
	 * @param User   $user    User written in the journal
	 * @param string $action  blocked (blocked sender), spam (judged spam by the engine of inbox, 1.88.0)
	 * @param int[]  $mailIds Rows of the index
	 * @return array{ok:bool,count:int,undo:array<int,array<string,mixed>>,error:string,ids:array<int,int>,rest:int[]}
	 */
	public function runAsSystem(User $user, $action, array $mailIds)
	{
		if (!in_array($action, array('blocked', 'spam'), true)) {
			return array('ok' => false, 'count' => 0, 'undo' => array(), 'error' => 'Unknown action', 'ids' => array(), 'rest' => array());
		}
		$this->system = true;
		try {
			return $this->run($user, $action, $mailIds);
		} finally {
			$this->system = false;
		}
	}

	/**
	 * Action of a module subscribed to the mailbox (1.146.0), checked by InboxConsumerEvent against what the managers
	 * ticked: the access of a user is replaced by the subscription. Read / unread, tags, a move of the mails inside
	 * their mailbox (archive, junk, trash are folders of it), never a deletion for good nor another mailbox.
	 *
	 * @param User                $user      User of the action (id 0: the journal names the module, InboxActionLog::$consumer)
	 * @param string              $action    seen, tag, move, archive
	 * @param int[]               $mailIds   Rows of the index, all of the mailbox $accountId
	 * @param array<string,mixed> $params    As run()
	 * @param int                 $accountId Mailbox of the subscription
	 * @return array{ok:bool,count:int,undo:array<int,array<string,mixed>>,error:string,ids:array<int,int>,rest:int[]}
	 */
	public function runAsConsumer(User $user, $action, array $mailIds, array $params, $accountId)
	{
		$refused = array('ok' => false, 'count' => 0, 'undo' => array(), 'error' => '', 'ids' => array(), 'rest' => array());
		if (!in_array($action, array('seen', 'tag', 'move', 'archive'), true)) {
			$refused['error'] = 'Unknown action';
			return $refused;
		}
		$mailIds = array_slice(array_values(array_unique(array_filter(array_map('intval', $mailIds)))), 0, self::MAX);
		$p = $this->db->prefix();
		$own = $mailIds ? $this->db->getRows("SELECT rowid FROM ".$p."inbox_mail WHERE rowid IN (".implode(',', $mailIds).") AND fk_account = ".((int) $accountId)." LIMIT ".(self::MAX + 1)) : array();
		if (!$mailIds || !is_array($own) || count($own) !== count($mailIds)) {
			$refused['error'] = 'InboxMailNotFound';
			return $refused;
		}
		if ($action === 'move') {
			$target = $this->db->getRow("SELECT fk_account FROM ".$p."inbox_folder WHERE rowid = ".((int) ($params['folder'] ?? 0)));
			if (!is_object($target) || (int) $target->fk_account !== (int) $accountId) {
				$refused['error'] = 'InboxErrorFolderNotFound';
				return $refused;
			}
		}
		// Never a folded conversation: the mails given, nothing more
		unset($params['expand']);
		$this->system = true;
		try {
			return $this->run($user, $action, $mailIds, $params);
		} finally {
			$this->system = false;
		}
	}

	/**
	 * Journal of the changes (1.121.0): the lists open by the other users follow at once. Numbers only.
	 *
	 * @param User                $user     User
	 * @param string              $action   Action run
	 * @param int                 $folderId Folder of the mails
	 * @param object[]            $list     Rows of the mails (one folder)
	 * @param array<int,int>      $newIds   Rows after a move (old => new)
	 * @return void
	 */
	protected function emitEvent(User $user, $action, $folderId, array $list, array $newIds)
	{
		require_once __DIR__.'/inboxevent.class.php';
		if (!InboxEvent::enabled() || !$list || !empty($list[0]->live)) {
			// Protected mailbox (numbers of the session only): nothing in the journal
			return;
		}
		$types = array('seen' => 'seen', 'flag' => 'flag', 'tag' => 'tag', 'delete' => 'delete');
		$type = $types[$action] ?? 'move';
		$events = new InboxEvent($this->db);
		$accountId = (int) $list[0]->fk_account;
		$events->emit($accountId, $type, array_map(function ($r) {
			return (int) $r->rowid;
		}, $list), (int) $folderId, $user);
		// A move (archive, junk, trash): the folders where the mails arrived are read again
		$newIds = array_values(array_filter(array_map('intval', $newIds)));
		if ($newIds) {
			$rows = $this->db->getRows("SELECT DISTINCT fk_account, fk_folder FROM ".$this->db->prefix()."inbox_mail WHERE rowid IN (".implode(',', $newIds).") LIMIT 100");
			foreach (is_array($rows) ? $rows : array() as $r) {
				if ((int) $r->fk_folder !== (int) $folderId) {
					$events->emit((int) $r->fk_account, 'folder', array(), (int) $r->fk_folder, $user);
				}
			}
		}
	}

	/**
	 * Run an action
	 *
	 * @param User                $user    User
	 * @param string              $action  seen, flag, tag, move, archive, delete, junk, notjunk
	 * @param int[]               $mailIds Rows of the index
	 * @param array<string,mixed> $params  expand (rows of folded conversations); on (seen, flag); add, remove (tag); folder (move: target folder row); confirm (delete in the trash)
	 * @return array{ok:bool,count:int,undo:array<int,array<string,mixed>>,error:string,ids:array<int,int>,rest:int[]}
	 */
	public function run(User $user, $action, array $mailIds, array $params = array())
	{
		$this->error = '';
		$mailIds = array_slice(array_values(array_unique(array_filter(array_map('intval', $mailIds)))), 0, self::MAX);
		$out = array('ok' => false, 'count' => 0, 'undo' => array(), 'error' => '', 'ids' => array(), 'rest' => array());
		if (!$mailIds) {
			$out['error'] = 'No mail';
			return $out;
		}
		// A conversation folded in the list: the action goes to all its mails of the same folder
		$expand = array_values(array_intersect($mailIds, array_map('intval', (array) ($params['expand'] ?? array()))));
		if ($expand) {
			$p = $this->db->prefix();
			$more = $this->db->getRows("SELECT DISTINCT o.rowid FROM ".$p."inbox_mail as m INNER JOIN ".$p."inbox_mail as o ON o.fk_folder = m.fk_folder AND o.thread_key = m.thread_key"
				." WHERE m.rowid IN (".implode(',', $expand).") AND m.thread_key IS NOT NULL LIMIT ".self::MAX);
			foreach (is_array($more) ? $more : array() as $o) {
				$mailIds[] = (int) $o->rowid;
			}
			$mailIds = array_slice(array_values(array_unique($mailIds)), 0, self::MAX);
		}
		$rows = $this->rows($user, $mailIds, in_array($action, array('seen', 'flag'), true) ? InboxAccess::LEVEL_READ : InboxAccess::LEVEL_HANDLE);
		if ($rows === null) {
			$out['error'] = $this->error;
			return $out;
		}
		// By folder: one write on the server per folder
		$groups = array();
		foreach ($rows as $r) {
			$groups[(int) $r->fk_folder][] = $r;
		}
		foreach ($groups as $folderId => $list) {
			$res = null;
			switch ($action) {
				case 'seen':
				case 'flag':
					$res = $this->flag($user, $list, !empty($params['on']), $action === 'seen' ? '\\Seen' : '\\Flagged');
					break;
				case 'tag':
					$res = $this->tag($user, $list, (array) ($params['add'] ?? array()), (array) ($params['remove'] ?? array()));
					break;
				case 'move':
					$folder = (int) ($params['folder'] ?? 0);
					$res = $this->readFirst($user, $list, $folder !== (int) $list[0]->fk_folder && in_array($this->folderRole($list[0], $folder), array('trash', 'junk'), true), function () use ($user, $list, $folder) {
						return $this->moveTo($user, $list, $folder, 'move');
					});
					break;
				case 'archive':
					// Archived = read (1.96.0, setting read_on_archive of My account, apart from trash and junk)
					$res = $this->readFirst($user, $list, true, function () use ($user, $list) {
						return $this->archive($user, $list);
					}, 'read_on_archive');
					break;
				case 'delete':
					$res = $this->readFirst($user, $list, (string) $list[0]->role !== 'trash' && $this->roleFolder((int) $list[0]->fk_account, 'trash'), function () use ($user, $list, $params) {
						return $this->delete($user, $list, !empty($params['confirm']));
					});
					break;
				case 'junk':
				case 'notjunk':
					$res = $this->readFirst($user, $list, $action === 'junk' && (string) $list[0]->role !== 'junk' && $this->roleFolder((int) $list[0]->fk_account, 'junk'), function () use ($user, $list, $action) {
						return $this->junk($user, $list, $action === 'junk');
					});
					break;
				case 'blocked':
				case 'spam':
					// Blocked sender (1.85.0), judged spam by the engine of inbox (1.88.0): junk, written as such in the
					// journal; by inbox itself only
					$res = $this->system ? $this->junk($user, $list, true, $action) : null;
					if ($res === null && !$this->system) {
						$this->error = 'Unknown action';
					}
					break;
				default:
					$this->error = 'Unknown action';
			}
			if ($res === null) {
				$out['error'] = $this->error;
				// What was done before stays done (and undoable): the list is told
				$out['ok'] = $out['count'] > 0;
				return $out;
			}
			$out['count'] += $res['count'];
			if ($res['count'] > 0) {
				$this->emitEvent($user, $action, (int) $folderId, $list, $res['ids'] ?? array());
			}
			$out['undo'] = array_merge($out['undo'], $res['undo']);
			$out['ids'] += $res['ids'] ?? array();
			if (!empty($res['rest'])) {
				// Another mailbox, out of time: the browser sends the rest in the next call
				$out['rest'] = array_merge($out['rest'], $res['rest']);
				if (!empty($res['partial_error'])) {
					$out['error'] = $res['partial_error'];
				}
			}
		}
		$out['ok'] = true;

		return $out;
	}

	/**
	 * Rows of the index with their folder and mailbox, all in mailboxes where the user has the level
	 *
	 * @param User   $user    User
	 * @param int[]  $mailIds Rows
	 * @param string $level   Level needed
	 * @return object[]|null
	 */
	protected function rows(User $user, array $mailIds, $level)
	{
		// Mails of a protected mailbox (1.89.0): numbers of the live reading, all of one mailbox, nothing in the index
		$live = array_filter($mailIds, array('InboxLive', 'isMail'));
		if ($live) {
			$accountId = InboxLive::accountOf(reset($live));
			$access = new InboxAccess($this->db);
			$account = $this->account($accountId);
			if (count($live) !== count($mailIds) || !$account || !$account->isLive() || count(array_unique(array_map(array('InboxLive', 'accountOf'), $live))) !== 1) {
				$this->error = 'InboxMailNotFound';
				return null;
			}
			if (!$this->system && !$access->check($user, $accountId, $level)) {
				$this->error = $access->check($user, $accountId, InboxAccess::LEVEL_READ) ? InboxAccess::needKey($level) : 'InboxMailNotFound';
				return null;
			}
			$rows = $this->live()->actionRows($account, $live);
			if ($rows === null) {
				$this->error = 'InboxMailNotFound';
			}
			return $rows;
		}
		$p = $this->db->prefix();
		$rows = $this->db->getRows("SELECT m.rowid, m.fk_account, m.fk_folder, m.remote_id, m.sort_key, m.message_id, m.subject, m.date_mail, m.date_received, m.seen, m.answered,"
			." m.flagged, m.keywords, f.path, f.role, f.delimiter FROM ".$p."inbox_mail as m INNER JOIN ".$p."inbox_folder as f ON f.rowid = m.fk_folder AND f.status = 1"
			." WHERE m.rowid IN (".implode(',', $mailIds).") LIMIT ".(self::MAX + 1));
		if (!is_array($rows) || count($rows) !== count($mailIds)) {
			$this->error = 'InboxMailNotFound';
			return null;
		}
		if ($this->system) {
			return $rows;
		}
		$access = new InboxAccess($this->db);
		foreach ($rows as $r) {
			if (!$access->check($user, (int) $r->fk_account, $level)) {
				// A mail of a mailbox without access is « not found »; with « read » only, the reason is said
				$this->error = $access->check($user, (int) $r->fk_account, InboxAccess::LEVEL_READ) ? InboxAccess::needKey($level) : 'InboxMailNotFound';
				return null;
			}
		}

		return $rows;
	}

	/** @var InboxLive|null */
	protected $liveReader;

	/**
	 * @return InboxLive Live reading of the protected mailboxes (1.89.0)
	 */
	protected function live()
	{
		if ($this->liveReader === null) {
			$this->liveReader = new InboxLive($this->db);
		}

		return $this->liveReader;
	}

	/**
	 * @param int $accountId Mailbox
	 * @return InboxConnectorInterface|null
	 */
	protected function connector($accountId)
	{
		if (isset($this->connectors[$accountId])) {
			return $this->connectors[$accountId];
		}
		$account = $this->account($accountId);
		if (!$account) {
			return null;
		}
		$error = '';
		$c = InboxConnectorFactory::forAccount($this->db, $account, $error);
		if (!$c) {
			$this->error = $error;
			return null;
		}
		$this->connectors[$accountId] = $c;

		return $c;
	}

	/**
	 * @param int $accountId Mailbox
	 * @return InboxAccount|null
	 */
	protected function account($accountId)
	{
		if (!isset($this->accounts[$accountId])) {
			$a = new InboxAccount($this->db);
			if ($a->fetch((int) $accountId) <= 0 || !$a->isActive()) {
				$this->error = 'InboxMailNotFound';
				return null;
			}
			$this->accounts[$accountId] = $a;
		}

		return $this->accounts[$accountId];
	}

	/**
	 * A mail put in the trash or in junk by a user is marked read first (1.90.0, setting read_on_trash of My account):
	 * it no longer counts as unread there. The undo gives the mails back to their folder, then unread again.
	 * Same for an archived mail, with its own setting read_on_archive (1.96.0).
	 *
	 * @param User     $user  User
	 * @param object[] $list  Rows of one folder
	 * @param bool     $read  Mark read before the action
	 * @param callable $go    The action, returns its result (or null)
	 * @param string   $param Setting of My account that allows it
	 * @return array<string,mixed>|null
	 */
	protected function readFirst(User $user, array $list, $read, callable $go, $param = 'read_on_trash')
	{
		// Setting of My account (on by default)
		if ($read && !$this->system) {
			require_once __DIR__.'/inboxuserparam.class.php';
			$read = (int) (new InboxUserParam($this->db))->get($user, $param) === 1;
		}
		// Unread, or unknown (mail of a protected mailbox, seen = -1: marked read, never made unread by the undo)
		$unread = $read ? array_values(array_filter($list, function ($r) {
			return (int) $r->seen !== 1;
		})) : array();
		$back = array();
		if ($unread) {
			if ($this->flag($user, $unread, true, '\\Seen') === null) {
				// Never blocks the action: the mail moves unread
				$this->error = '';
			} else {
				foreach ($unread as $r) {
					if ((int) $r->seen === 0) {
						$back[] = (int) $r->rowid;
					}
					$r->seen = 1;
				}
			}
		}
		$res = $go();
		if ($res === null || !$back) {
			return $res;
		}
		// Undo: after the move back, with the rows the mails have after the move
		// (a mail not found again after the move is left out)
		$ids = array();
		foreach ($back as $id) {
			if (!empty($res['ids'][$id])) {
				$ids[] = (int) $res['ids'][$id];
			}
		}
		if ($ids) {
			$res['undo'][] = array('action' => 'seen', 'on' => 0, 'ids' => $ids);
		}

		return $res;
	}

	/**
	 * Role of a target folder (trash, junk...), '' when unknown
	 *
	 * @param object $first    A row of the moved mails
	 * @param int    $folderId Target folder
	 * @return string
	 */
	protected function folderRole($first, $folderId)
	{
		if (InboxLive::isLive($folderId)) {
			$account = $this->account((int) $first->fk_account);
			$f = ($account && $account->isLive()) ? $this->live()->folder($account, (int) $folderId) : null;
		} else {
			$f = $this->db->getRow("SELECT role FROM ".$this->db->prefix()."inbox_folder WHERE rowid = ".((int) $folderId));
		}

		return is_object($f) ? (string) $f->role : '';
	}

	/**
	 * Read / unread or starred, on several mails (one mail opened: InboxReader::setSeen)
	 *
	 * @param User     $user User
	 * @param object[] $list Rows of one folder
	 * @param bool     $on   Flag set
	 * @param string   $flag \\Seen or \\Flagged
	 * @return array<string,mixed>|null
	 */
	protected function flag(User $user, array $list, $on, $flag)
	{
		$first = $list[0];
		$c = $this->connector((int) $first->fk_account);
		if (!$c) {
			return null;
		}
		$ids = array_map(function ($r) {
			return (string) $r->remote_id;
		}, $list);
		if (!$c->setFlags((string) $first->path, $ids, $on ? array($flag) : array(), $on ? array() : array($flag))) {
			$this->error = $c->getError();
			return null;
		}
		$col = $flag === '\\Seen' ? 'seen' : 'flagged';
		// Undo: only the mails that changed get their state back
		$changed = array();
		foreach ($list as $r) {
			if ((int) $r->$col !== ($on ? 1 : 0)) {
				$changed[] = (int) $r->rowid;
			}
		}
		if ($changed) {
			$p = $this->db->prefix();
			$this->db->query("UPDATE ".$p."inbox_mail SET ".$col." = ".($on ? 1 : 0)." WHERE rowid IN (".implode(',', $changed).")");
			if ($col === 'seen') {
				$this->db->query("UPDATE ".$p."inbox_folder SET unread = GREATEST(0, unread ".($on ? '-' : '+')." ".count($changed).") WHERE rowid = ".((int) $first->fk_folder));
			}
		}
		$this->trigger($user, 'INBOX_MAIL_FLAG', (int) $first->fk_account, array('ids' => $changed, 'flag' => $col, 'on' => $on));

		return array('count' => count($list), 'undo' => $changed ? array(array('action' => $col === 'seen' ? 'seen' : 'flag', 'ids' => $changed, 'on' => $on ? 0 : 1)) : array());
	}

	/**
	 * Tags (IMAP keywords): the index follows the server
	 *
	 * @param User     $user   User
	 * @param object[] $list   Rows of one folder
	 * @param string[] $add    Keywords added
	 * @param string[] $remove Keywords removed
	 * @return array<string,mixed>|null
	 */
	protected function tag(User $user, array $list, array $add, array $remove)
	{
		$first = $list[0];
		$c = $this->connector((int) $first->fk_account);
		if (!$c) {
			return null;
		}
		$add = array_values(array_filter(array_map('strval', $add), function ($k) {
			return preg_match('/^[A-Za-z0-9$_.\-]{1,64}$/', $k);
		}));
		$remove = array_values(array_filter(array_map('strval', $remove), function ($k) {
			return preg_match('/^[A-Za-z0-9$_.\-]{1,64}$/', $k);
		}));
		if (!$add && !$remove) {
			$this->error = 'Invalid tags';
			return null;
		}
		$ids = array_map(function ($r) {
			return (string) $r->remote_id;
		}, $list);
		if (!$c->setTags((string) $first->path, $ids, $add, $remove)) {
			$this->error = $c->getError();
			return null;
		}
		$p = $this->db->prefix();
		$undo = array();
		foreach ($list as $r) {
			$before = array_values(array_filter(explode(' ', (string) $r->keywords)));
			$lower = array_map('strtolower', $before);
			$after = array_values(array_filter($before, function ($k) use ($remove) {
				return !in_array(strtolower($k), array_map('strtolower', $remove), true);
			}));
			foreach ($add as $k) {
				if (!in_array(strtolower($k), array_map('strtolower', $after), true)) {
					$after[] = $k;
				}
			}
			$this->db->query("UPDATE ".$p."inbox_mail SET keywords = ".($after ? "'".$this->db->escape(dol_trunc(implode(' ', $after), 1024, 'right', 'UTF-8', 1))."'" : "NULL")." WHERE rowid = ".((int) $r->rowid));
			// Undo: give back what this mail had (a tag it had already stays)
			$undo[] = array('action' => 'tag', 'ids' => array((int) $r->rowid),
				'add' => array_values(array_filter($remove, function ($k) use ($lower) {
					return in_array(strtolower($k), $lower, true);
				})),
				'remove' => array_values(array_filter($add, function ($k) use ($lower) {
					return !in_array(strtolower($k), $lower, true);
				})));
		}
		$undo = array_values(array_filter($undo, function ($u) {
			return $u['add'] || $u['remove'];
		}));
		InboxActionLog::add($this->db, $user, (int) $first->fk_account, 'tag', $list, (string) $first->path, '', 0, trim(($add ? '+'.implode(' +', $add) : '').' '.($remove ? '-'.implode(' -', $remove) : '')));
		$this->trigger($user, 'INBOX_MAIL_TAG', (int) $first->fk_account, array('ids' => array_map('intval', array_column($list, 'rowid')), 'add' => $add, 'remove' => $remove));

		return array('count' => count($list), 'undo' => $undo);
	}

	/**
	 * Move to a folder of the same mailbox (or of another one: InboxMover)
	 *
	 * @param User     $user     User
	 * @param object[] $list     Rows of one folder
	 * @param int      $folderId Target folder
	 * @param string   $kind     Action written in the journal (move, archive, delete, junk, notjunk)
	 * @return array<string,mixed>|null
	 */
	protected function moveTo(User $user, array $list, $folderId, $kind)
	{
		$first = $list[0];
		$p = $this->db->prefix();
		if (!empty($first->live) || InboxLive::isLive($folderId)) {
			return $this->moveLive($user, $list, (int) $folderId, $kind);
		}
		$target = $this->db->getRow("SELECT rowid, fk_account, path, role FROM ".$p."inbox_folder WHERE rowid = ".((int) $folderId)." AND status = 1 AND selectable = 1");
		if (!is_object($target)) {
			$this->error = 'InboxErrorFolderNotFound';
			return null;
		}
		if ((int) $target->rowid === (int) $first->fk_folder) {
			return array('count' => 0, 'undo' => array());
		}
		if ((int) $target->fk_account !== (int) $first->fk_account) {
			require_once __DIR__.'/inboxmover.class.php';
			$access = new InboxAccess($this->db);
			if (!$access->check($user, (int) $target->fk_account, InboxAccess::LEVEL_HANDLE)) {
				$this->error = 'InboxErrorNeedHandleTarget';
				return null;
			}
			$mover = new InboxMover($this->db);
			$res = $mover->move($user, $list, $target, $kind);
			if ($res === null) {
				$this->error = $mover->error;
			}
			return $res;
		}
		$c = $this->connector((int) $first->fk_account);
		if (!$c) {
			return null;
		}
		$ids = array_map(function ($r) {
			return (string) $r->remote_id;
		}, $list);
		$map = $c->move((string) $first->path, $ids, (string) $target->path);
		if ($map === null) {
			$this->error = $c->getError();
			return null;
		}
		// Index: the row follows the mail when the server told its new identifier, else it leaves and the folder
		// is read again now (the mail comes back with its new identifier)
		$unknown = false;
		$newIds = array();
		foreach ($list as $r) {
			$new = $map[(string) $r->remote_id] ?? '';
			if ($new !== '' && preg_match('/^\d+:(\d+)$/', $new, $m)) {
				$this->db->query("DELETE FROM ".$p."inbox_mail WHERE fk_folder = ".((int) $target->rowid)." AND remote_id = '".$this->db->escape($new)."'");
				// In this folder from now (the trash emptied after some days counts from here, 1.41.0)
				$this->db->query("UPDATE ".$p."inbox_mail SET fk_folder = ".((int) $target->rowid).", remote_id = '".$this->db->escape($new)."', sort_key = ".((int) $m[1])
					.(InboxMail::hasDateFolder($this->db) ? ", date_folder = '".$this->db->idate(dol_now())."'" : '')." WHERE rowid = ".((int) $r->rowid));
				$newIds[(int) $r->rowid] = (int) $r->rowid;
			} else {
				$unknown = true;
				$this->db->query("DELETE FROM ".$p."inbox_mail_address WHERE fk_mail = ".((int) $r->rowid));
				$this->db->query("DELETE FROM ".$p."inbox_mail WHERE rowid = ".((int) $r->rowid));
			}
		}
		$this->counters((int) $first->fk_folder, (int) $target->rowid, $list);
		if ($unknown) {
			$sync = new InboxSync($this->db);
			// Never gives mails to the modules subscribed: an action of a user or of a module is running (1.146.1)
			$sync->run($this->account((int) $first->fk_account), microtime(true) + 8, array('folder_id' => (int) $target->rowid, 'no_delivery' => true));
			foreach ($list as $r) {
				if (!isset($newIds[(int) $r->rowid]) && (string) $r->message_id !== '') {
					$row = $this->db->getRow("SELECT rowid FROM ".$p."inbox_mail WHERE fk_folder = ".((int) $target->rowid)." AND message_id = '".$this->db->escape((string) $r->message_id)."' ORDER BY sort_key DESC");
					if (is_object($row)) {
						$newIds[(int) $r->rowid] = (int) $row->rowid;
					}
				}
			}
		}
		InboxActionLog::add($this->db, $user, (int) $first->fk_account, $kind, $list, (string) $first->path, (string) $target->path);
		$this->trigger($user, 'INBOX_MAIL_MOVE', (int) $first->fk_account, array('from' => (string) $first->path, 'to' => (string) $target->path, 'account_to' => (int) $target->fk_account,
			'message_ids' => array_values(array_filter(array_map('strval', array_column($list, 'message_id'))))));

		return array('count' => count($list), 'ids' => $newIds,
			'undo' => $newIds ? array(array('action' => 'move', 'ids' => array_values($newIds), 'folder' => (int) $first->fk_folder)) : array());
	}

	/**
	 * Move inside a protected mailbox (1.89.0): on the server only, the mails get their new numbers; never from or to
	 * another mailbox (it would put the mail in an index), never journaled with its subject
	 *
	 * @param User     $user     User
	 * @param object[] $list     Rows of one folder (live)
	 * @param int      $folderId Target folder (live number)
	 * @param string   $kind     Action
	 * @return array<string,mixed>|null
	 */
	protected function moveLive(User $user, array $list, $folderId, $kind)
	{
		$first = $list[0];
		if (empty($first->live) || !InboxLive::isLive($folderId) || InboxLive::accountOf($folderId) !== (int) $first->fk_account) {
			$this->error = 'InboxProtectErrorMoveOut';
			return null;
		}
		$account = $this->account((int) $first->fk_account);
		$target = $account ? $this->live()->folder($account, $folderId) : null;
		if (!$target) {
			$this->error = 'InboxErrorFolderNotFound';
			return null;
		}
		if ((int) $target->rowid === (int) $first->fk_folder) {
			return array('count' => 0, 'undo' => array());
		}
		$c = $this->connector((int) $first->fk_account);
		if (!$c) {
			return null;
		}
		$map = $c->move((string) $first->path, array_map(function ($r) {
			return (string) $r->remote_id;
		}, $list), (string) $target->path);
		if ($map === null) {
			$this->error = $c->getError();
			return null;
		}
		$known = array_values(array_filter(array_map('strval', $map), function ($v) {
			return (bool) preg_match('/^\d+:\d+$/', $v);
		}));
		$numbers = InboxLive::mailIds((int) $first->fk_account, (string) $target->path, $known);
		$newIds = array();
		foreach ($list as $r) {
			$new = (string) ($map[(string) $r->remote_id] ?? '');
			if (isset($numbers[$new])) {
				$newIds[(int) $r->rowid] = $numbers[$new];
			}
		}

		return array('count' => count($list), 'ids' => $newIds,
			'undo' => $newIds ? array(array('action' => 'move', 'ids' => array_values($newIds), 'folder' => (int) $first->fk_folder)) : array());
	}

	/**
	 * Counters of the folders follow at once (the next pass of the synchronisation reads the real ones)
	 *
	 * @param int      $from Source folder
	 * @param int      $to   Target folder
	 * @param object[] $list Rows moved
	 * @return void
	 */
	protected function counters($from, $to, array $list)
	{
		$n = count($list);
		$u = count(array_filter($list, function ($r) {
			return !(int) $r->seen;
		}));
		$p = $this->db->prefix();
		$this->db->query("UPDATE ".$p."inbox_folder SET total = GREATEST(0, total - ".$n."), unread = GREATEST(0, unread - ".$u."), indexed = GREATEST(0, indexed - ".$n.") WHERE rowid = ".((int) $from));
		if ($to) {
			$this->db->query("UPDATE ".$p."inbox_folder SET total = total + ".$n.", unread = unread + ".$u.", indexed = indexed + ".$n." WHERE rowid = ".((int) $to));
		}
	}

	/**
	 * Folder of a role in a mailbox
	 *
	 * @param int    $accountId Mailbox
	 * @param string $role      inbox, trash, junk, archive...
	 * @return object|null
	 */
	protected function roleFolder($accountId, $role)
	{
		$account = $this->account((int) $accountId);
		if ($account && $account->isLive()) {
			return $this->live()->folder($account, 0, $role);
		}
		$f = $this->db->getRow("SELECT rowid, path, delimiter, role FROM ".$this->db->prefix()."inbox_folder WHERE fk_account = ".((int) $accountId)." AND role = '".$this->db->escape($role)."'"
			." AND status = 1 AND selectable = 1 ORDER BY CASE WHEN flags LIKE '%\\\\\\\\".$this->db->escape($role)."%' THEN 0 ELSE 1 END, LENGTH(path)");

		return is_object($f) ? $f : null;
	}

	/**
	 * Archive: Archives / <year of the mail>, created when missing (as the usual mail clients)
	 *
	 * @param User     $user User
	 * @param object[] $list Rows of one folder
	 * @return array<string,mixed>|null
	 */
	protected function archive(User $user, array $list)
	{
		$first = $list[0];
		$accountId = (int) $first->fk_account;
		$base = $this->roleFolder($accountId, 'archive');
		$c = $this->connector($accountId);
		if (!$c) {
			return null;
		}
		$delim = $base ? (string) $base->delimiter : (string) $first->delimiter;
		if ($delim === '') {
			$delim = '.';
		}
		$basePath = $base ? (string) $base->path : ((stripos((string) $first->path, 'INBOX'.$delim) === 0 || strtoupper((string) $first->path) === 'INBOX') ? 'INBOX'.$delim.'Archives' : 'Archives');
		$byYear = array();
		foreach ($list as $r) {
			$date = $r->date_mail ? $r->date_mail : $r->date_received;
			$year = $date ? dol_print_date($this->db->jdate($date), '%Y') : date('Y');
			$byYear[$year][] = $r;
		}
		$out = array('count' => 0, 'undo' => array(), 'ids' => array());
		foreach ($byYear as $year => $rows) {
			$path = $basePath.$delim.$year;
			if (!empty($first->live)) {
				// Protected mailbox: the folder of the year on the server, created when missing
				$account = $this->account($accountId);
				$folder = $this->live()->folder($account, InboxLive::folderId($accountId, $path));
				if (!$folder) {
					if ((!$base && !$c->createFolder($basePath)) || !$c->createFolder($path)) {
						$this->error = $c->getError();
						return null;
					}
					InboxLive::forgetFolders($accountId);
					$folder = $this->live()->folder($account, InboxLive::folderId($accountId, $path));
					if (!$folder) {
						$this->error = 'InboxErrorFolderNotFound';
						return null;
					}
				}
			} else {
				$folder = $this->db->getRow("SELECT rowid FROM ".$this->db->prefix()."inbox_folder WHERE fk_account = ".$accountId." AND path = '".$this->db->escape($path)."' AND status = 1");
			}
			if (!is_object($folder)) {
				if (!$base && !$c->createFolder($basePath)) {
					$this->error = $c->getError();
					return null;
				}
				if (!$c->createFolder($path)) {
					$this->error = $c->getError();
					return null;
				}
				$tree = $c->listFolders();
				if (is_array($tree)) {
					$folders = new InboxFolder($this->db);
					$folders->syncTree($this->account($accountId), $tree);
				}
				$folder = $this->db->getRow("SELECT rowid FROM ".$this->db->prefix()."inbox_folder WHERE fk_account = ".$accountId." AND path = '".$this->db->escape($path)."' AND status = 1");
				if (!is_object($folder)) {
					$this->error = 'InboxErrorFolderNotFound';
					return null;
				}
				if (!$base) {
					$this->db->query("UPDATE ".$this->db->prefix()."inbox_folder SET role = 'archive' WHERE fk_account = ".$accountId." AND path = '".$this->db->escape($basePath)."'");
				}
			}
			$res = $this->moveTo($user, $rows, (int) $folder->rowid, 'archive');
			if ($res === null) {
				return $out['count'] ? $out : null;
			}
			$out['count'] += $res['count'];
			$out['undo'] = array_merge($out['undo'], $res['undo']);
			$out['ids'] += $res['ids'] ?? array();
		}

		return $out;
	}

	/**
	 * Remove: to the trash (undo), or for good from the trash (confirmation asked, no undo)
	 *
	 * @param User     $user    User
	 * @param object[] $list    Rows of one folder
	 * @param bool     $confirm The user confirmed the removal for good
	 * @return array<string,mixed>|null
	 */
	protected function delete(User $user, array $list, $confirm)
	{
		$first = $list[0];
		if ((string) $first->role !== 'trash') {
			$trash = $this->roleFolder((int) $first->fk_account, 'trash');
			if (!$trash) {
				$this->error = 'InboxErrorNoTrash';
				return null;
			}
			return $this->moveTo($user, $list, (int) $trash->rowid, 'delete');
		}
		if (!$confirm) {
			$this->error = 'InboxErrorConfirmDelete';
			return null;
		}
		$c = $this->connector((int) $first->fk_account);
		if (!$c) {
			return null;
		}
		$ids = array_map(function ($r) {
			return (string) $r->remote_id;
		}, $list);
		if (!$c->deleteMails((string) $first->path, $ids)) {
			$this->error = $c->getError();
			return null;
		}
		$p = $this->db->prefix();
		$rowIds = array_map('intval', array_column($list, 'rowid'));
		InboxActionLog::add($this->db, $user, (int) $first->fk_account, 'destroy', $list, (string) $first->path);
		$index = new InboxMail($this->db);
		$gone = $index->keyRows("rowid IN (".implode(',', $rowIds).")");
		$this->db->query("DELETE FROM ".$p."inbox_mail_address WHERE fk_mail IN (".implode(',', $rowIds).")");
		$this->db->query("DELETE FROM ".$p."inbox_mail WHERE rowid IN (".implode(',', $rowIds).")");
		// Removed for good: the links of the mails found nowhere else wait for the mail to come back (1.114.0)
		$index->markGone($gone);
		$this->counters((int) $first->fk_folder, 0, $list);
		$this->trigger($user, 'INBOX_MAIL_DELETE', (int) $first->fk_account, array('folder' => (string) $first->path,
			'message_ids' => array_values(array_filter(array_map('strval', array_column($list, 'message_id'))))));

		return array('count' => count($list), 'undo' => array());
	}

	/**
	 * Junk / not junk: keywords $Junk / $NotJunk (as the other mail clients and the antispam), then the move to Junk
	 * (or back to the inbox)
	 *
	 * @param User     $user User
	 * @param object[] $list Rows of one folder
	 * @param bool     $junk Junk
	 * @param string   $kind Action written in the journal ('' = junk or notjunk)
	 * @return array<string,mixed>|null
	 */
	protected function junk(User $user, array $list, $junk, $kind = '')
	{
		$first = $list[0];
		$target = $this->roleFolder((int) $first->fk_account, $junk ? 'junk' : 'inbox');
		if (!$target) {
			$this->error = $junk ? 'InboxErrorNoJunk' : 'InboxErrorFolderNotFound';
			return null;
		}
		// One line in the journal (junk), not the keywords too
		$paused = InboxActionLog::$paused;
		InboxActionLog::$paused = true;
		$tags = $this->tag($user, $list, $junk ? array('$Junk') : array('$NotJunk'), $junk ? array('$NotJunk') : array('$Junk'));
		InboxActionLog::$paused = $paused;
		if ($tags === null) {
			return null;
		}
		$moved = $this->moveTo($user, $list, (int) $target->rowid, $kind !== '' ? $kind : ($junk ? 'junk' : 'notjunk'));
		if ($moved === null) {
			return null;
		}
		// « Junk » or « Not junk » of a user: learned by the engine of inbox (1.88.0), the mails at their new place
		if ($kind === '' && !empty($moved['ids']) && empty($first->live)) {
			require_once __DIR__.'/inboxspamcheck.class.php';
			(new InboxSpamCheck($this->db))->queue($user, array_values($moved['ids']), $junk);
		}
		// Undo: back to the folder, then the keywords as they were
		$undo = $moved['undo'];
		foreach ($tags['undo'] as $u) {
			$u['ids'] = array_values(array_map(function ($id) use ($moved) {
				return $moved['ids'][$id] ?? $id;
			}, $u['ids']));
			$undo[] = $u;
		}

		return array('count' => $moved['count'], 'undo' => $undo, 'ids' => $moved['ids'] ?? array());
	}

	/**
	 * Trigger of inbox (P9: the other modules plug in), on the mailbox
	 *
	 * @param User                $user      User
	 * @param string              $code      INBOX_MAIL_...
	 * @param int                 $accountId Mailbox
	 * @param array<string,mixed> $data      Data of the action (in $object->context['inbox'])
	 * @return void
	 */
	protected function trigger(User $user, $code, $accountId, array $data)
	{
		$account = $this->account($accountId);
		// Protected with its content (1.89.0): nothing of its mails given to the other modules
		if ($account && !$account->isLive()) {
			$account->context['inbox'] = $data;
			$account->call_trigger($code, $user);
		}
	}
}
