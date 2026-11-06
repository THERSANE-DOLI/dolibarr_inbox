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
 * \file    inbox/class/inboxaccess.class.php
 * \ingroup inbox
 * \brief   Who can use which mailbox, and at which level
 */

require_once __DIR__.'/inboxaccount.class.php';
require_once __DIR__.'/inboxaccountlog.class.php';

/**
 * Levels of a user on a mailbox: read < send < manage (cumulative).
 *
 * Rules (analyse-lot-w1 §2.3):
 * - effective level = highest of the direct access and of the accesses of the groups of the user (a group gives
 *   its level to all its members, no exception per user);
 * - owner of a personal mailbox: manage, without a line;
 * - mailbox not active (draft or disabled): nobody;
 * - external user (socid > 0) or disabled user: never;
 * - a Dolibarr admin has no implicit access: managing the mailboxes is not reading their mails.
 * A refused access must look like a mailbox that does not exist (404 for the endpoints).
 */
class InboxAccess
{
	const LEVEL_NONE = '';
	const LEVEL_READ = 'read';
	const LEVEL_SEND = 'send';
	const LEVEL_MANAGE = 'manage';

	/** @var array<string,int> */
	const RANKS = array(self::LEVEL_NONE => 0, self::LEVEL_READ => 1, self::LEVEL_SEND => 2, self::LEVEL_MANAGE => 3);

	/** @var DoliDB */
	protected $db;

	/** @var string */
	public $error = '';

	/** @var array<int,array<int,string>> Cache per user: account id => level (only active mailboxes) */
	protected static $cache = array();

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct(DoliDB $db)
	{
		$this->db = $db;
	}

	/**
	 * Empty the cache of the levels (after a change of the accesses, or in tests)
	 *
	 * @return void
	 */
	public static function clearCache()
	{
		self::$cache = array();
	}

	/**
	 * @param string $level Level
	 * @return bool         Level known (none excluded)
	 */
	public static function isLevel($level)
	{
		return in_array($level, array(self::LEVEL_READ, self::LEVEL_SEND, self::LEVEL_MANAGE), true);
	}

	/**
	 * @param string $level Level
	 * @return int          Rank of the level, 0 for none or unknown
	 */
	public static function rank($level)
	{
		return isset(self::RANKS[$level]) ? self::RANKS[$level] : 0;
	}

	/**
	 * Levels of a user on the active mailboxes of the entity that the user can use
	 *
	 * @param User $user User
	 * @return array<int,string> Account id => level
	 */
	public function getLevels($user)
	{
		global $conf;

		if (!($user instanceof User) || empty($user->id) || !empty($user->socid)) {
			return array();
		}
		$status = isset($user->status) ? $user->status : (isset($user->statut) ? $user->statut : 1);
		if ((int) $status === 0) {
			return array();
		}
		$uid = (int) $user->id;
		if (isset(self::$cache[$uid])) {
			return self::$cache[$uid];
		}
		$levels = array();
		$prefix = $this->db->prefix();
		$entity = (int) $conf->entity;

		// Personal mailboxes of the user
		$rows = $this->db->getRows("SELECT rowid FROM ".$prefix."inbox_account WHERE entity = ".$entity
			." AND status = ".InboxAccount::STATUS_ACTIVE." AND type = '".InboxAccount::TYPE_PERSONAL."' AND fk_user_owner = ".$uid." LIMIT 10000");
		foreach (is_array($rows) ? $rows : array() as $row) {
			$levels[(int) $row->rowid] = self::LEVEL_MANAGE;
		}

		// Direct accesses and accesses of the groups of the user
		$sql = "SELECT aa.fk_account, aa.level FROM ".$prefix."inbox_account_access as aa";
		$sql .= " INNER JOIN ".$prefix."inbox_account as a ON a.rowid = aa.fk_account";
		$sql .= " WHERE a.entity = ".$entity." AND a.status = ".InboxAccount::STATUS_ACTIVE;
		$sql .= " AND (aa.fk_user = ".$uid;
		$sql .= " OR (aa.fk_usergroup > 0 AND aa.fk_usergroup IN (SELECT ug.fk_usergroup FROM ".$prefix."usergroup_user as ug WHERE ug.fk_user = ".$uid."";
		$sql .= " AND ug.entity IN (0, ".$entity."))))";
		$sql .= " LIMIT 100000";
		$rows = $this->db->getRows($sql);
		foreach (is_array($rows) ? $rows : array() as $row) {
			$id = (int) $row->fk_account;
			$level = (string) $row->level;
			if (self::rank($level) > self::rank(isset($levels[$id]) ? $levels[$id] : self::LEVEL_NONE)) {
				$levels[$id] = $level;
			}
		}
		self::$cache[$uid] = $levels;

		return $levels;
	}

	/**
	 * Level of a user on a mailbox
	 *
	 * @param User $user      User
	 * @param int  $accountId Mailbox
	 * @return string         Level, '' if none
	 */
	public function getLevel($user, $accountId)
	{
		$levels = $this->getLevels($user);

		return isset($levels[(int) $accountId]) ? $levels[(int) $accountId] : self::LEVEL_NONE;
	}

	/**
	 * @param User   $user      User
	 * @param int    $accountId Mailbox
	 * @param string $level     Level needed
	 * @return bool             The user has at least this level (false = answer 404, never 403)
	 */
	public function check($user, $accountId, $level = self::LEVEL_READ)
	{
		return self::isLevel($level) && self::rank($this->getLevel($user, $accountId)) >= self::rank($level);
	}

	/**
	 * Mailboxes the user can use with at least a level, ordered by label
	 *
	 * @param User   $user     User
	 * @param string $minLevel Level needed
	 * @return array<int,InboxAccount> Account id => mailbox, with its level in ->access_level
	 */
	public function getAccounts($user, $minLevel = self::LEVEL_READ)
	{
		$out = array();
		foreach ($this->getLevels($user) as $id => $level) {
			if (self::rank($level) < self::rank($minLevel)) {
				continue;
			}
			$account = new InboxAccount($this->db);
			if ($account->fetch($id) > 0 && $account->isActive()) {
				$account->access_level = $level;
				$out[$id] = $account;
			}
		}
		uasort($out, function ($a, $b) {
			return strcasecmp((string) $a->label, (string) $b->label);
		});

		return $out;
	}

	/**
	 * Accesses of a mailbox (the owner of a personal mailbox is not a line)
	 *
	 * @param int $accountId Mailbox
	 * @return array<int,array{id:int,fk_user:int,fk_usergroup:int,name:string,level:string,date:int}>
	 */
	public function listForAccount($accountId)
	{
		$out = array();
		$prefix = $this->db->prefix();
		$sql = "SELECT aa.rowid, aa.fk_user, aa.fk_usergroup, aa.level, aa.date_creation, u.firstname, u.lastname, u.login, g.nom as groupname";
		$sql .= " FROM ".$prefix."inbox_account_access as aa";
		$sql .= " LEFT JOIN ".$prefix."user as u ON u.rowid = aa.fk_user AND aa.fk_user > 0";
		$sql .= " LEFT JOIN ".$prefix."usergroup as g ON g.rowid = aa.fk_usergroup AND aa.fk_usergroup > 0";
		$sql .= " WHERE aa.fk_account = ".((int) $accountId);
		$sql .= " ORDER BY aa.fk_usergroup > 0, g.nom, u.lastname, u.firstname LIMIT 10000";
		$rows = $this->db->getRows($sql);
		foreach (is_array($rows) ? $rows : array() as $row) {
			$name = (int) $row->fk_usergroup > 0 ? (string) $row->groupname : trim($row->firstname.' '.$row->lastname);
			$out[] = array(
				'id' => (int) $row->rowid,
				'fk_user' => (int) $row->fk_user,
				'fk_usergroup' => (int) $row->fk_usergroup,
				'name' => $name !== '' ? $name : (string) $row->login,
				'level' => (string) $row->level,
				'date' => (int) $this->db->jdate($row->date_creation),
			);
		}

		return $out;
	}

	/**
	 * Give or change the access of a user or a group (one of the two), written in the journal
	 *
	 * @param User   $author    User doing it (has the right to manage the accesses: checked by the caller)
	 * @param int    $accountId Mailbox
	 * @param int    $fkUser    User, or 0
	 * @param int    $fkGroup   Group, or 0
	 * @param string $level     read, send or manage
	 * @return int              > 0 if OK, 0 if nothing changed, < 0 if KO
	 */
	public function grant($author, $accountId, $fkUser, $fkGroup, $level)
	{
		global $conf;

		$fkUser = (int) $fkUser;
		$fkGroup = (int) $fkGroup;
		if (!self::isLevel($level) || (($fkUser > 0) === ($fkGroup > 0))) {
			$this->error = 'InboxErrorAccessTarget';
			return -1;
		}
		if (!$this->targetExists($fkUser, $fkGroup)) {
			$this->error = 'InboxErrorAccessTarget';
			return -1;
		}
		$prefix = $this->db->prefix();
		$account = $this->db->getRow("SELECT rowid FROM ".$prefix."inbox_account WHERE rowid = ".((int) $accountId)." AND entity = ".((int) $conf->entity));
		if (!is_object($account)) {
			$this->error = 'InboxErrorAccountNotFound';
			return -1;
		}
		$where = " WHERE fk_account = ".((int) $accountId)." AND fk_user = ".$fkUser." AND fk_usergroup = ".$fkGroup;
		$current = $this->db->getRow("SELECT rowid, level FROM ".$prefix."inbox_account_access".$where);
		$log = new InboxAccountLog($this->db);
		$target = array('fk_user' => $fkUser, 'fk_usergroup' => $fkGroup);
		if (is_object($current)) {
			if ((string) $current->level === $level) {
				return 0;
			}
			if (!$this->db->query("UPDATE ".$prefix."inbox_account_access SET level = '".$this->db->escape($level)."'".$where)) {
				$this->error = $this->db->lasterror();
				return -1;
			}
			$log->add($accountId, $author, InboxAccountLog::ACTION_ACCESS_CHANGE, $target + array('level' => (string) $current->level), $target + array('level' => $level));
		} else {
			$sql = "INSERT INTO ".$prefix."inbox_account_access (fk_account, fk_user, fk_usergroup, level, date_creation, fk_user_creat)";
			$sql .= " VALUES (".((int) $accountId).", ".$fkUser.", ".$fkGroup.", '".$this->db->escape($level)."', '".$this->db->idate(dol_now())."', ".((int) $author->id).")";
			if (!$this->db->query($sql)) {
				$this->error = $this->db->lasterror();
				return -1;
			}
			$log->add($accountId, $author, InboxAccountLog::ACTION_ACCESS_ADD, null, $target + array('level' => $level));
		}
		self::clearCache();

		return 1;
	}

	/**
	 * Remove the access of a user or a group, written in the journal
	 *
	 * @param User $author    User doing it
	 * @param int  $accountId Mailbox
	 * @param int  $fkUser    User, or 0
	 * @param int  $fkGroup   Group, or 0
	 * @return int            > 0 if OK, 0 if there was no access, < 0 if KO
	 */
	public function revoke($author, $accountId, $fkUser, $fkGroup)
	{
		$prefix = $this->db->prefix();
		$where = " WHERE fk_account = ".((int) $accountId)." AND fk_user = ".((int) $fkUser)." AND fk_usergroup = ".((int) $fkGroup);
		$current = $this->db->getRow("SELECT rowid, level FROM ".$prefix."inbox_account_access".$where);
		if (!is_object($current)) {
			return 0;
		}
		if (!$this->db->query("DELETE FROM ".$prefix."inbox_account_access".$where)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$log = new InboxAccountLog($this->db);
		$log->add($accountId, $author, InboxAccountLog::ACTION_ACCESS_REMOVE, array('fk_user' => (int) $fkUser, 'fk_usergroup' => (int) $fkGroup, 'level' => (string) $current->level), null);
		self::clearCache();

		return 1;
	}

	/**
	 * @param int $fkUser  User, or 0
	 * @param int $fkGroup Group, or 0
	 * @return bool        The user (internal) or the group exists in the entity
	 */
	protected function targetExists($fkUser, $fkGroup)
	{
		global $conf;

		$prefix = $this->db->prefix();
		if ($fkUser > 0) {
			// External users never get an access
			$row = $this->db->getRow("SELECT rowid FROM ".$prefix."user WHERE rowid = ".((int) $fkUser)
				." AND (fk_soc IS NULL OR fk_soc = 0) AND entity IN (0, ".((int) $conf->entity).")");
		} else {
			$row = $this->db->getRow("SELECT rowid FROM ".$prefix."usergroup WHERE rowid = ".((int) $fkGroup)
				." AND entity IN (0, ".((int) $conf->entity).")");
		}

		return is_object($row);
	}
}
