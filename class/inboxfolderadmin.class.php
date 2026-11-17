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
 * \file    inbox/class/inboxfolderadmin.class.php
 * \ingroup inbox
 * \brief   Folders managed from inbox (lot W4 1.10.0, B10, B11): create, rename, move, remove, mark read, empty
 */

require_once __DIR__.'/inboxaccess.class.php';
require_once __DIR__.'/inboxaccount.class.php';
require_once __DIR__.'/inboxfolder.class.php';
require_once __DIR__.'/inboxmail.class.php';
require_once __DIR__.'/inboxactionlog.class.php';
require_once __DIR__.'/connector/inboxconnectorfactory.class.php';

/**
 * Same order as the actions on the mails (P8): write on the mail server, check, then the index. A folder keeps its
 * row in the index when renamed or moved (its mails, colour, icon and folded state stay). Level « manage » of the
 * mailbox, except « mark the folder read » (level « read », as read / unread). The special folders (inbox, sent,
 * drafts, archives, junk, trash) are never renamed, moved or removed. Removing a folder puts it in the trash, as for
 * a mail; in the trash, or without trash, it is removed for good after a confirmation that says how many mails.
 */
class InboxFolderAdmin
{
	/** @var DoliDB */
	protected $db;

	/** @var string Key of the error (translation) or message of the server */
	public $error = '';

	/** @var int Mails concerned (confirmation asked, mails changed) */
	public $count = 0;

	/** @var InboxAccount|null */
	protected $account;

	/** @var InboxConnectorInterface|null */
	protected $connector;

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
		if ($this->connector) {
			$this->connector->disconnect();
		}
	}

	/**
	 * Create a folder in a folder (0: at the top of the mailbox)
	 *
	 * @param User   $user      User
	 * @param int    $accountId Mailbox
	 * @param int    $parentId  Parent folder, 0 = top
	 * @param string $name      Name as the user writes it
	 * @return int Folder created, < 0 if KO
	 */
	public function create(User $user, $accountId, $parentId, $name)
	{
		// Level « handle » (1.92.0): creating and renaming a folder is part of sorting the mails
		if (!$this->open($user, (int) $accountId, InboxAccess::LEVEL_HANDLE)) {
			return -1;
		}
		$parent = null;
		if ((int) $parentId > 0) {
			$parent = $this->folder((int) $parentId);
			if (!$parent) {
				return -1;
			}
		}
		$path = $this->childPath($parent, $name);
		if ($path === '') {
			return -1;
		}
		if (!$this->connector->createFolder($path)) {
			$this->error = $this->connector->getError();
			return -1;
		}
		$this->refreshTree();
		$row = $this->db->getRow("SELECT rowid FROM ".$this->db->prefix()."inbox_folder WHERE fk_account = ".((int) $this->account->id)." AND path = '".$this->db->escape($path)."'");
		InboxActionLog::add($this->db, $user, (int) $this->account->id, 'folder_create', array($this->stub()), '', $path);
		$this->trigger($user, 'INBOX_FOLDER_CREATE', array('path' => $path));

		return is_object($row) ? (int) $row->rowid : 0;
	}

	/**
	 * Rename a folder (its subfolders follow)
	 *
	 * @param User   $user     User
	 * @param int    $folderId Folder
	 * @param string $name     New name
	 * @return int > 0 if OK, < 0 if KO
	 */
	public function rename(User $user, $folderId, $name)
	{
		$f = $this->managed($user, (int) $folderId, InboxAccess::LEVEL_HANDLE);
		if (!$f) {
			return -1;
		}
		$parent = (string) $f->parent_path !== '' ? $this->byPath((string) $f->parent_path) : null;
		$path = $this->childPath($parent, $name, (string) $f->parent_path, true);
		if ($path === '') {
			return -1;
		}
		// Taken by another folder (the same one: only the case of a letter changes)
		$taken = $this->byPath($path);
		if ($taken && (int) $taken->rowid !== (int) $f->rowid) {
			$this->error = 'InboxErrorFolderExists';
			return -1;
		}
		if ($path === (string) $f->path) {
			return 1;
		}

		return $this->moveTo($user, $f, $path, 'folder_rename');
	}

	/**
	 * Move a folder into another one of the same mailbox (0: at the top)
	 *
	 * @param User $user     User
	 * @param int  $folderId Folder
	 * @param int  $targetId New parent, 0 = top
	 * @return int > 0 if OK, < 0 if KO
	 */
	public function move(User $user, $folderId, $targetId)
	{
		$f = $this->managed($user, (int) $folderId, InboxAccess::LEVEL_HANDLE);
		if (!$f) {
			return -1;
		}
		$target = null;
		if ((int) $targetId > 0) {
			$target = $this->folder((int) $targetId);
			if (!$target) {
				return -1;
			}
			$d = (string) $f->delimiter;
			if ((int) $target->rowid === (int) $f->rowid || ($d !== '' && strpos((string) $target->path, $f->path.$d) === 0)) {
				$this->error = 'InboxErrorFolderIntoItself';
				return -1;
			}
		}
		$path = $this->childPath($target, (string) $f->name);
		if ($path === '') {
			return -1;
		}
		if ($path === (string) $f->path) {
			return 1;
		}

		return $this->moveTo($user, $f, $path, 'folder_move');
	}

	/**
	 * Remove a folder: into the trash; for good when it is in the trash or without trash (confirm: the number of
	 * mails was shown, error InboxErrorConfirmFolderDelete with $this->count otherwise)
	 *
	 * @param User $user     User
	 * @param int  $folderId Folder
	 * @param bool $confirm  Removal for good confirmed
	 * @return int 1 = put in the trash, 2 = removed for good, < 0 if KO
	 */
	public function remove(User $user, $folderId, $confirm = false)
	{
		$f = $this->managed($user, (int) $folderId);
		if (!$f) {
			return -1;
		}
		$trash = $this->roleFolder('trash');
		$d = (string) $f->delimiter;
		$inTrash = $trash && $d !== '' && strpos((string) $f->path, $trash->path.$d) === 0;
		if ($trash && !$inTrash) {
			// Into the trash, under a free name (« Clients », « Clients (2) »...)
			$base = (string) $f->name;
			$path = '';
			for ($i = 1; $i < 50; $i++) {
				$try = $this->childPath($trash, $base.($i > 1 ? ' ('.$i.')' : ''), null, true);
				if ($try !== '' && !$this->byPath($try)) {
					$path = $try;
					break;
				}
			}
			if ($path === '') {
				$this->error = 'InboxErrorFolderExists';
				return -1;
			}
			$this->error = '';
			return $this->moveTo($user, $f, $path, 'folder_delete') > 0 ? 1 : -1;
		}
		// For good: the subfolders first (the deepest first), then the folder
		$tree = $this->subtree($f);
		$this->count = 0;
		foreach ($tree as $row) {
			$this->count += (int) $row->total;
		}
		if (!$confirm) {
			$this->error = 'InboxErrorConfirmFolderDelete';
			return -1;
		}
		$mails = new InboxMail($this->db);
		foreach (array_reverse($tree) as $row) {
			if (!$this->connector->deleteFolder((string) $row->path)) {
				$this->error = $this->connector->getError();
				$this->refreshTree();
				return -1;
			}
			$mails->clearFolder((int) $row->rowid, true);
			$this->db->query("DELETE FROM ".$this->db->prefix()."inbox_folder WHERE rowid = ".((int) $row->rowid));
		}
		$this->refreshTree();
		InboxActionLog::add($this->db, $user, (int) $this->account->id, 'folder_destroy', array($this->stub($this->count)), (string) $f->path);
		$this->trigger($user, 'INBOX_FOLDER_DELETE', array('path' => (string) $f->path, 'mails' => $this->count));

		return 2;
	}

	/**
	 * Every mail of a folder marked read (level read)
	 *
	 * @param User $user     User
	 * @param int  $folderId Folder
	 * @return int Mails changed, < 0 if KO
	 */
	public function markRead(User $user, $folderId)
	{
		$row = $this->db->getRow("SELECT fk_account FROM ".$this->db->prefix()."inbox_folder WHERE rowid = ".((int) $folderId)." AND status = ".InboxFolder::STATUS_PRESENT);
		if (!is_object($row) || !$this->open($user, (int) $row->fk_account, InboxAccess::LEVEL_READ, InboxConnectorInterface::CAP_FLAGS)) {
			$this->error = $this->error ?: 'InboxErrorFolderNotFound';
			return -1;
		}
		$f = $this->folder((int) $folderId);
		if (!$f || (int) $f->selectable !== 1) {
			$this->error = 'InboxErrorFolderNotFound';
			return -1;
		}
		$n = $this->connector->markFolderSeen((string) $f->path);
		if ($n === null) {
			$this->error = $this->connector->getError();
			return -1;
		}
		$p = $this->db->prefix();
		$this->db->query("UPDATE ".$p."inbox_mail SET seen = 1 WHERE fk_folder = ".((int) $f->rowid)." AND seen = 0");
		$this->db->query("UPDATE ".$p."inbox_folder SET unread = 0 WHERE rowid = ".((int) $f->rowid));
		$this->trigger($user, 'INBOX_FOLDER_SEEN', array('path' => (string) $f->path, 'mails' => (int) $n));
		$this->count = (int) $n;

		return (int) $n;
	}

	/**
	 * Empty the trash (its subfolders too) or the junk, for good (confirm: the number of mails was shown)
	 *
	 * @param User $user     User
	 * @param int  $folderId Folder of role trash or junk
	 * @param bool $confirm  Confirmed
	 * @return int Mails removed, < 0 if KO
	 */
	public function emptyFolder(User $user, $folderId, $confirm = false)
	{
		// Level « handle » (1.92.0): removing for good from the trash or junk
		$row = $this->db->getRow("SELECT fk_account FROM ".$this->db->prefix()."inbox_folder WHERE rowid = ".((int) $folderId)." AND status = ".InboxFolder::STATUS_PRESENT);
		if (!is_object($row) || !$this->open($user, (int) $row->fk_account, InboxAccess::LEVEL_HANDLE)) {
			$this->error = $this->error ?: 'InboxErrorFolderNotFound';
			return -1;
		}
		$f = $this->folder((int) $folderId);
		if (!$f || !in_array((string) $f->role, array('trash', 'junk'), true)) {
			$this->error = 'InboxErrorFolderNotFound';
			return -1;
		}
		$subs = (string) $f->role === 'trash' ? array_slice($this->subtree($f), 1) : array();
		$this->count = (int) $f->total;
		foreach ($subs as $s) {
			$this->count += (int) $s->total;
		}
		if (!$confirm) {
			$this->error = 'InboxErrorConfirmEmpty';
			return -1;
		}
		$n = $this->connector->emptyFolder((string) $f->path);
		if ($n === null) {
			$this->error = $this->connector->getError();
			return -1;
		}
		$mails = new InboxMail($this->db);
		$mails->clearFolder((int) $f->rowid, true);
		$p = $this->db->prefix();
		$this->db->query("UPDATE ".$p."inbox_folder SET total = 0, unread = 0 WHERE rowid = ".((int) $f->rowid));
		foreach (array_reverse($subs) as $s) {
			$n += (int) $s->total;
			if (!$this->connector->deleteFolder((string) $s->path)) {
				$this->error = $this->connector->getError();
				$this->refreshTree();
				return -1;
			}
			$mails->clearFolder((int) $s->rowid, true);
			$this->db->query("DELETE FROM ".$p."inbox_folder WHERE rowid = ".((int) $s->rowid));
		}
		$this->refreshTree();
		InboxActionLog::add($this->db, $user, (int) $this->account->id, 'empty', array($this->stub(max(1, $n))), (string) $f->path);
		$this->trigger($user, 'INBOX_FOLDER_EMPTY', array('path' => (string) $f->path, 'mails' => $n));
		$this->count = $n;

		return $n;
	}

	// ---------- Inside ----------

	/**
	 * Mailbox opened for a user with a level, connector able to manage the folders
	 *
	 * @param User   $user       User
	 * @param int    $accountId  Mailbox
	 * @param string $level      Level needed
	 * @param string $capability Capability needed of the connector
	 * @return bool
	 */
	protected function open(User $user, $accountId, $level, $capability = InboxConnectorInterface::CAP_FOLDERS_MANAGE)
	{
		$this->error = '';
		$access = new InboxAccess($this->db);
		if (!$access->check($user, (int) $accountId, InboxAccess::LEVEL_READ)) {
			$this->error = 'InboxErrorFolderNotFound';
			return false;
		}
		if (!$access->check($user, (int) $accountId, $level)) {
			$this->error = InboxAccess::needKey($level);
			return false;
		}
		$account = new InboxAccount($this->db);
		if ($account->fetch((int) $accountId) <= 0 || !$account->isActive()) {
			$this->error = 'InboxErrorFolderNotFound';
			return false;
		}
		$error = '';
		$connector = InboxConnectorFactory::forAccount($this->db, $account, $error);
		if (!$connector || !$connector->can($capability)) {
			$this->error = $connector ? 'InboxErrorFoldersNotSupported' : $error;
			return false;
		}
		$this->account = $account;
		$this->connector = $connector;

		return true;
	}

	/**
	 * A folder that may be renamed, moved or removed: level manage, not special, without special subfolder
	 *
	 * @param User $user     User
	 * @param int  $folderId Folder
	 * @return object|null
	 */
	protected function managed(User $user, $folderId, $level = InboxAccess::LEVEL_MANAGE)
	{
		$row = $this->db->getRow("SELECT fk_account FROM ".$this->db->prefix()."inbox_folder WHERE rowid = ".((int) $folderId)." AND status = ".InboxFolder::STATUS_PRESENT);
		if (!is_object($row) || !$this->open($user, (int) $row->fk_account, $level)) {
			$this->error = $this->error ?: 'InboxErrorFolderNotFound';
			return null;
		}
		$f = $this->folder((int) $folderId);
		if (!$f) {
			return null;
		}
		foreach ($this->subtree($f) as $row) {
			if ((string) $row->role !== '' || strtoupper((string) $row->path) === 'INBOX') {
				$this->error = 'InboxErrorFolderSpecial';
				return null;
			}
		}

		return $f;
	}

	/**
	 * @param int $folderId Folder of the mailbox opened
	 * @return object|null
	 */
	protected function folder($folderId)
	{
		$f = $this->db->getRow("SELECT * FROM ".$this->db->prefix()."inbox_folder WHERE rowid = ".((int) $folderId)." AND fk_account = ".((int) $this->account->id)
			." AND status = ".InboxFolder::STATUS_PRESENT);
		if (!is_object($f)) {
			$this->error = 'InboxErrorFolderNotFound';
			return null;
		}

		return $f;
	}

	/**
	 * @param string $path Path in the mailbox opened
	 * @return object|null
	 */
	protected function byPath($path)
	{
		$f = $this->db->getRow("SELECT * FROM ".$this->db->prefix()."inbox_folder WHERE fk_account = ".((int) $this->account->id)." AND path = '".$this->db->escape((string) $path)."'"
			." AND status = ".InboxFolder::STATUS_PRESENT);

		return is_object($f) ? $f : null;
	}

	/**
	 * The folder of a role (the special one first)
	 *
	 * @param string $role Role
	 * @return object|null
	 */
	protected function roleFolder($role)
	{
		$rows = $this->db->getRows("SELECT * FROM ".$this->db->prefix()."inbox_folder WHERE fk_account = ".((int) $this->account->id)." AND role = '".$this->db->escape($role)."'"
			." AND status = ".InboxFolder::STATUS_PRESENT." AND selectable = 1 ORDER BY LENGTH(path) LIMIT 10");
		if (!is_array($rows) || !$rows) {
			return null;
		}
		foreach ($rows as $r) {
			if (stripos((string) $r->flags, '\\'.$role) !== false) {
				return $r;
			}
		}

		return $rows[0];
	}

	/**
	 * The folder and its subfolders, the folder first, then by depth
	 *
	 * @param object $f Folder
	 * @return object[]
	 */
	protected function subtree($f)
	{
		$d = (string) $f->delimiter;
		$rows = $this->db->getRows("SELECT * FROM ".$this->db->prefix()."inbox_folder WHERE fk_account = ".((int) $f->fk_account)." AND status = ".InboxFolder::STATUS_PRESENT
			." AND (rowid = ".((int) $f->rowid).($d !== '' ? " OR path LIKE '".$this->db->escape($this->db->escapeforlike($f->path.$d))."%'" : '').") LIMIT 5000");
		$rows = is_array($rows) ? $rows : array();
		usort($rows, function ($a, $b) {
			return strlen((string) $a->path) <=> strlen((string) $b->path);
		});

		return $rows;
	}

	/**
	 * Path of a new folder in a parent: name checked and encoded (modified UTF-7), not taken
	 *
	 * @param object|null $parent     Parent folder, null = top of the mailbox
	 * @param string      $name       Name
	 * @param string|null $parentPath Path of the parent when it is not a folder of the index (a namespace)
	 * @param bool        $quiet      No error when the name is taken (the caller tries another one)
	 * @return string '' when refused ($this->error)
	 */
	protected function childPath($parent, $name, $parentPath = null, $quiet = false)
	{
		$name = trim(preg_replace('/\s+/u', ' ', (string) $name));
		$delimiter = $parent ? (string) $parent->delimiter : $this->delimiter();
		if ($name === '' || dol_strlen($name) > 100 || ($delimiter !== '' && strpos($name, $delimiter) !== false) || preg_match('/["\'<>\\\\\x00-\x1f*%]/u', $name) || $name === '.' || $name === '..') {
			$this->error = 'InboxErrorFolderName';
			return '';
		}
		$encoded = mb_convert_encoding($name, 'UTF7-IMAP', 'UTF-8');
		if ($parent) {
			$base = (string) $parent->path.$delimiter;
		} elseif ($parentPath !== null && $parentPath !== '') {
			$base = $parentPath.$delimiter;
		} else {
			$base = $this->topPrefix();
		}
		$path = $base.$encoded;
		if (!InboxImapConnector::isSafeFolderPath($path)) {
			$this->error = 'InboxErrorFolderName';
			return '';
		}
		if (!$quiet && $this->byPath($path)) {
			$this->error = 'InboxErrorFolderExists';
			return '';
		}

		return $path;
	}

	/**
	 * @return string Delimiter of the folders of the mailbox opened
	 */
	protected function delimiter()
	{
		$row = $this->db->getRow("SELECT delimiter FROM ".$this->db->prefix()."inbox_folder WHERE fk_account = ".((int) $this->account->id)." AND delimiter IS NOT NULL AND delimiter <> ''");

		return is_object($row) ? (string) $row->delimiter : '/';
	}

	/**
	 * Start of the path of a folder at the top: « INBOX. » on the servers where every folder lives under the inbox
	 * (personal namespace INBOX.), else nothing
	 *
	 * @return string
	 */
	protected function topPrefix()
	{
		$d = $this->delimiter();
		$rows = $this->db->getRows("SELECT path FROM ".$this->db->prefix()."inbox_folder WHERE fk_account = ".((int) $this->account->id)." AND status = ".InboxFolder::STATUS_PRESENT." LIMIT 5000");
		$others = 0;
		$under = 0;
		foreach (is_array($rows) ? $rows : array() as $r) {
			if (strtoupper((string) $r->path) === 'INBOX') {
				continue;
			}
			$others++;
			if (stripos((string) $r->path, 'INBOX'.$d) === 0) {
				$under++;
			}
		}

		return $others > 0 && $under === $others ? 'INBOX'.$d : '';
	}

	/**
	 * Rename or move on the server, then the rows of the folder and of its subfolders follow (same rows)
	 *
	 * @param User   $user   User
	 * @param object $f      Folder
	 * @param string $path   New path
	 * @param string $action Action of the journal
	 * @return int 1 if OK, -1 if KO
	 */
	protected function moveTo(User $user, $f, $path, $action)
	{
		$taken = $this->byPath($path);
		if ($taken && (int) $taken->rowid !== (int) $f->rowid) {
			$this->error = 'InboxErrorFolderExists';
			return -1;
		}
		$old = (string) $f->path;
		if (!$this->connector->renameFolder($old, $path)) {
			$this->error = $this->connector->getError();
			return -1;
		}
		$p = $this->db->prefix();
		$d = (string) $f->delimiter;
		foreach ($this->subtree($f) as $row) {
			$newPath = $path.substr((string) $row->path, strlen($old));
			$parent = ($d !== '' && strpos($newPath, $d) !== false) ? substr($newPath, 0, (int) strrpos($newPath, $d)) : '';
			$name = $parent !== '' ? substr($newPath, strlen($parent) + strlen($d)) : $newPath;
			$this->db->query("UPDATE ".$p."inbox_folder SET path = '".$this->db->escape($newPath)."', parent_path = ".($parent !== '' ? "'".$this->db->escape($parent)."'" : 'NULL').","
				." name = '".$this->db->escape(dol_trunc(InboxImapConnector::decodeFolderName($name), 250, 'right', 'UTF-8', 1))."' WHERE rowid = ".((int) $row->rowid));
		}
		$this->refreshTree();
		InboxActionLog::add($this->db, $user, (int) $this->account->id, $action, array($this->stub((int) $f->total)), $old, $path);
		$this->trigger($user, $action === 'folder_delete' ? 'INBOX_FOLDER_DELETE' : 'INBOX_FOLDER_MOVE', array('from' => $old, 'to' => $path));

		return 1;
	}

	/**
	 * Folders of the index in line with the server again (new flags, a folder created)
	 *
	 * @return void
	 */
	protected function refreshTree()
	{
		$list = $this->connector->listFolders();
		if (is_array($list)) {
			$folders = new InboxFolder($this->db);
			$folders->syncTree($this->account, $list);
		}
	}

	/**
	 * A line of the journal counts mails: a folder action is written as one line with its number of mails
	 *
	 * @param int $n Mails
	 * @return object
	 */
	protected function stub($n = 1)
	{
		$o = new stdClass();
		$o->message_id = '';
		$o->subject = '';
		$o->nb = max(1, (int) $n);

		return $o;
	}

	/**
	 * @param User                $user User
	 * @param string              $code Trigger
	 * @param array<string,mixed> $data Data (context['inbox'] of the mailbox)
	 * @return void
	 */
	protected function trigger(User $user, $code, array $data)
	{
		$this->account->context['inbox'] = $data;
		$this->account->call_trigger($code, $user);
	}
}
