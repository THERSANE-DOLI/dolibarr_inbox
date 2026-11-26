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
 * \file    inbox/class/inboxfolder.class.php
 * \ingroup inbox
 * \brief   Folders of a mailbox in the index
 */

require_once __DIR__.'/inboxmail.class.php';
require_once __DIR__.'/connector/inboximapconnector.class.php';

/**
 * Folders of the index (rebuilt from the server at any time). A folder gone from the server is marked, then removed
 * with its mails at the next reading of the folders (a renamed folder is a gone one plus a new one: the links survive,
 * they are on the Message-ID).
 */
class InboxFolder
{
	const STATUS_PRESENT = 1;
	const STATUS_GONE = 9;

	/** Failed passes in a row after which a folder no longer keeps its mailbox in « first synchronisation » (1.145.0) */
	const FAILS_SET_ASIDE = 3;

	/**
	 * Where Dovecot keeps the Sieve scripts of a mailbox (1.145.0): in a Maildir++ mailbox, they show as folders of the
	 * root after the first save of the rules (INBOX.dovecot, INBOX.dovecot.sieve...), never readable as folders of mails
	 */
	const SIEVE_STORAGE = array('dovecot', 'dovecot.sieve', 'dovecot.svbin', 'sieve');

	/** @var DoliDB */
	protected $db;

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct(DoliDB $db)
	{
		$this->db = $db;
	}

	/**
	 * A storage of the Sieve scripts shown as a folder by the server: not followed when it appears (1.145.0), it stays
	 * in the tree and can be followed by hand
	 *
	 * @param string $path      Path on the server
	 * @param string $delimiter Delimiter of the server
	 * @return bool
	 */
	public static function isSieveStorage($path, $delimiter)
	{
		$path = (string) $path;
		$root = 'INBOX'.(string) $delimiter;
		if ((string) $delimiter !== '' && strncasecmp($path, $root, strlen($root)) === 0) {
			$path = substr($path, strlen($root));
		}

		return in_array($path, self::SIEVE_STORAGE, true);
	}

	/**
	 * Condition on the folders whose first synchronisation is left (the state « first_sync » of their mailbox): a folder
	 * that failed FAILS_SET_ASIDE passes in a row is left out until a pass on it succeeds (1.145.0)
	 *
	 * @param string $alias Alias of llx_inbox_folder in the query ('' for none)
	 * @return string SQL, without AND before
	 */
	public static function sqlFirstSyncLeft($alias = '')
	{
		$a = $alias !== '' ? $alias.'.' : '';

		$sql = $a."status = ".self::STATUS_PRESENT." AND ".$a."selectable = 1 AND ".$a."sync_enabled = 1";

		return $sql." AND ".$a."first_sync_done = 0 AND ".$a."sync_fail_count < ".self::FAILS_SET_ASIDE;
	}

	/**
	 * Folders of a mailbox
	 *
	 * @param int  $accountId   Mailbox
	 * @param bool $presentOnly Only the folders present on the server
	 * @return array<int,stdClass> Rows, by path
	 */
	public function listForAccount($accountId, $presentOnly = true)
	{
		$sql = "SELECT * FROM ".$this->db->prefix()."inbox_folder WHERE fk_account = ".((int) $accountId);
		$sql .= $presentOnly ? " AND status = ".self::STATUS_PRESENT : '';
		$sql .= " ORDER BY path LIMIT 5000";
		$rows = $this->db->getRows($sql);

		return is_array($rows) ? $rows : array();
	}

	/**
	 * Bring the folders of the index in line with the list of the server
	 *
	 * @param InboxAccount                      $account Mailbox
	 * @param array<int,array<string,mixed>>    $folders listFolders() of the connector
	 * @return array{added:int,gone:int,removed:int}
	 */
	public function syncTree(InboxAccount $account, array $folders)
	{
		$p = $this->db->prefix();
		// Only the roles chosen by hand force a folder; listFolders() decided the others, one folder per role
		$roles = $account->getChosenRoles();
		$known = array();
		foreach ($this->listForAccount((int) $account->id, false) as $row) {
			$known[$row->path] = $row;
		}
		$seen = array();
		$stats = array('added' => 0, 'gone' => 0, 'removed' => 0);
		foreach ($folders as $f) {
			$path = (string) $f['path'];
			$seen[$path] = true;
			$role = array_search($path, $roles, true);
			$role = $role !== false ? (string) $role : (string) $f['role'];
			$delimiter = (string) $f['delimiter'];
			$parent = ($delimiter !== '' && strpos($path, $delimiter) !== false) ? substr($path, 0, (int) strrpos($path, $delimiter)) : '';
			$name = $delimiter !== '' ? substr($path, ($parent !== '' ? strlen($parent) + strlen($delimiter) : 0)) : $path;
			$values = array(
				'name' => "'".$this->db->escape(dol_trunc(InboxImapConnector::decodeFolderName($name), 250, 'right', 'UTF-8', 1))."'",
				'parent_path' => $parent !== '' ? "'".$this->db->escape($parent)."'" : 'NULL',
				'delimiter' => "'".$this->db->escape(substr($delimiter, 0, 4))."'",
				'role' => $role !== '' ? "'".$this->db->escape($role)."'" : 'NULL',
				'flags' => "'".$this->db->escape(dol_trunc(implode(' ', (array) $f['flags']), 250, 'right', 'UTF-8', 1))."'",
				'selectable' => !empty($f['selectable']) ? 1 : 0,
				'status' => self::STATUS_PRESENT,
			);
			if (isset($known[$path])) {
				$set = array();
				foreach ($values as $k => $v) {
					$set[] = $k.' = '.$v;
				}
				$this->db->query("UPDATE ".$p."inbox_folder SET ".implode(', ', $set)." WHERE rowid = ".((int) $known[$path]->rowid));
			} else {
				// Followed when it appears, except the storage of the Sieve scripts (its reading fails at every pass)
				$follow = !empty($f['selectable']) && !self::isSieveStorage($path, $delimiter);
				$sql = "INSERT INTO ".$p."inbox_folder (fk_account, path, ".implode(', ', array_keys($values)).", sync_enabled) VALUES (".((int) $account->id).", '".$this->db->escape($path)."', ".implode(', ', $values).", ".($follow ? 1 : 0).")";
				$this->db->query($sql);
				$stats['added']++;
			}
		}
		$mail = new InboxMail($this->db);
		foreach ($known as $path => $row) {
			if (isset($seen[$path])) {
				continue;
			}
			if ((int) $row->status === self::STATUS_GONE) {
				// Gone at two readings in a row: removed with its mails from the index
				$mail->clearFolder((int) $row->rowid, true);
				$this->db->query("DELETE FROM ".$p."inbox_folder WHERE rowid = ".((int) $row->rowid));
				$stats['removed']++;
			} else {
				$this->db->query("UPDATE ".$p."inbox_folder SET status = ".self::STATUS_GONE." WHERE rowid = ".((int) $row->rowid));
				$stats['gone']++;
			}
		}

		return $stats;
	}

	/**
	 * Save the result of a pass on a folder
	 *
	 * @param int                 $folderId Folder
	 * @param array<string,mixed> $values   sync_cursor, total, unread, indexed, oldest_indexed (timestamp), first_sync_done, sync_error
	 * @return void
	 */
	public function saveState($folderId, array $values)
	{
		$set = array("date_sync = '".$this->db->idate(dol_now())."'");
		foreach ($values as $k => $v) {
			if (in_array($k, array('total', 'unread', 'indexed', 'first_sync_done'), true)) {
				$set[] = $k.' = '.((int) $v);
			} elseif ($k === 'oldest_indexed') {
				$set[] = 'oldest_indexed = '.($v ? "'".$this->db->idate((int) $v)."'" : 'NULL');
			} elseif (in_array($k, array('sync_cursor', 'sync_error'), true)) {
				$set[] = $k.' = '.($v === null || $v === '' ? 'NULL' : "'".$this->db->escape((string) $v)."'");
			}
		}
		$this->db->query("UPDATE ".$this->db->prefix()."inbox_folder SET ".implode(', ', $set)." WHERE rowid = ".((int) $folderId));
	}

	/**
	 * Count a failed pass on a folder, or start again from zero after a good one (1.145.0). Apart from saveState(): the
	 * state of the folder is saved even before the module is activated again with its column
	 *
	 * @param int  $folderId Folder
	 * @param bool $failed   The pass on it failed
	 * @return void
	 */
	public function countFailure($folderId, $failed)
	{
		$set = $failed ? "sync_fail_count = sync_fail_count + 1" : "sync_fail_count = 0";
		$this->db->query("UPDATE ".$this->db->prefix()."inbox_folder SET ".$set." WHERE rowid = ".((int) $folderId).($failed ? '' : " AND sync_fail_count <> 0"));
	}

	/**
	 * Progress of the repair of the attachments (1.7.1)
	 *
	 * @param int $folderId Folder
	 * @param int $value    -1 done, > 0 done for the UIDs from this one up
	 * @return void
	 */
	public function setAttRepair($folderId, $value)
	{
		$this->db->query("UPDATE ".$this->db->prefix()."inbox_folder SET att_repair = ".((int) $value)." WHERE rowid = ".((int) $folderId));
	}

	/**
	 * Follow or not a folder (tab Synchro)
	 *
	 * @param int  $accountId Mailbox
	 * @param int  $folderId  Folder
	 * @param bool $enabled   Followed
	 * @return bool
	 */
	public function setSyncEnabled($accountId, $folderId, $enabled)
	{
		$sql = " WHERE rowid = ".((int) $folderId)." AND fk_account = ".((int) $accountId)." AND selectable = 1";
		if (!$this->db->query("UPDATE ".$this->db->prefix()."inbox_folder SET sync_enabled = ".($enabled ? 1 : 0).$sql)) {
			return false;
		}
		// Followed again by hand: its failed passes counted from zero (1.145.0)
		$this->db->query("UPDATE ".$this->db->prefix()."inbox_folder SET sync_fail_count = 0".$sql);

		return true;
	}
}
