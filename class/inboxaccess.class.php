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
 * Rules:
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
	/** Handle the mails (1.92.0): tag, move, delete (also for good), create and rename folders; not the mailbox itself */
	const LEVEL_HANDLE = 'handle';
	const LEVEL_MANAGE = 'manage';

	/** @var array<string,int> */
	const RANKS = array(self::LEVEL_NONE => 0, self::LEVEL_READ => 1, self::LEVEL_SEND => 2, self::LEVEL_HANDLE => 3, self::LEVEL_MANAGE => 4);

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
		return in_array($level, array(self::LEVEL_READ, self::LEVEL_SEND, self::LEVEL_HANDLE, self::LEVEL_MANAGE), true);
	}

	/**
	 * @param string $level Level missing
	 * @return string       Key of the error that names it
	 */
	public static function needKey($level)
	{
		return $level === self::LEVEL_HANDLE ? 'InboxErrorNeedHandle' : 'InboxErrorNeedManage';
	}

	/**
	 * @param string $level Level held
	 * @param string $min   Level needed
	 * @return bool         The level held is at least the one needed (a level added later is placed by its rank)
	 */
	public static function atLeast($level, $min)
	{
		return self::rank((string) $level) >= self::rank($min) && self::rank($min) > 0;
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
	 * Address of the small photo of a user ('' when none): the same file the cards of Dolibarr show
	 *
	 * @param int    $userId User
	 * @param string $photo  File name of the photo
	 * @param int    $entity Entity of the user
	 * @return string
	 */
	public static function userPhotoUrl($userId, $photo, $entity)
	{
		global $db;

		$userId = (int) $userId;
		$photo = (string) $photo;
		if ($userId <= 0 || $photo === '' || !dolIsAllowedForPreview($photo)) {
			return '';
		}
		require_once DOL_DOCUMENT_ROOT.'/core/lib/images.lib.php';
		require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';
		$u = new User($db);
		$u->id = $userId;
		$file = get_exdir(0, 0, 0, 0, $u, 'user').'photos/'.getImageFileNameForSize($photo, '_small');

		return DOL_URL_ROOT.'/viewimage.php?modulepart=userphoto&entity='.((int) $entity ?: 1).'&file='.urlencode($file);
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
	 * Mailboxes the user can use with at least a level, in the order he chose (My account), the others by label
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
		$pos = array();
		if ($user instanceof User && $out) {
			require_once __DIR__.'/inboxuserparam.class.php';
			$order = json_decode((string) (new InboxUserParam($this->db))->get($user, 'accounts_order'), true);
			$pos = array_flip(array_map('intval', is_array($order) ? $order : array()));
		}
		// Those he placed first, in his order; a mailbox given later goes after them, by label
		uasort($out, function ($a, $b) use ($pos) {
			$pa = $pos[(int) $a->id] ?? PHP_INT_MAX;
			$pb = $pos[(int) $b->id] ?? PHP_INT_MAX;

			return $pa !== $pb ? ($pa < $pb ? -1 : 1) : strcasecmp((string) $a->label, (string) $b->label);
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
		$sql = "SELECT aa.rowid, aa.fk_user, aa.fk_usergroup, aa.level, aa.date_creation, u.firstname, u.lastname, u.login, u.photo, u.entity as user_entity, g.nom as groupname, g.color as groupcolor";
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
				// Picture of the row: the photo of the user, the colour of the group
				'photo' => (int) $row->fk_user > 0 ? self::userPhotoUrl((int) $row->fk_user, (string) $row->photo, (int) $row->user_entity) : '',
				'color' => (int) $row->fk_usergroup > 0 ? (string) $row->groupcolor : '',
			);
		}

		return $out;
	}

	/**
	 * People who can use a mailbox, whatever the way (owner, direct access, group): active internal users only
	 *
	 * @param int $accountId Mailbox
	 * @return array<int,array{id:int,name:string,level:string}> User id => person, ordered by name
	 */
	public function usersOf($accountId)
	{
		global $conf;

		$prefix = $this->db->prefix();
		$account = $this->db->getRow("SELECT rowid, type, fk_user_owner, status FROM ".$prefix."inbox_account WHERE rowid = ".((int) $accountId)." AND entity = ".((int) $conf->entity));
		if (!is_object($account) || (int) $account->status !== InboxAccount::STATUS_ACTIVE) {
			return array();
		}
		$levels = array();
		if ($account->type === InboxAccount::TYPE_PERSONAL && (int) $account->fk_user_owner > 0) {
			$levels[(int) $account->fk_user_owner] = self::LEVEL_MANAGE;
		}
		$sql = "SELECT COALESCE(ug.fk_user, aa.fk_user) as uid, aa.level FROM ".$prefix."inbox_account_access as aa";
		$sql .= " LEFT JOIN ".$prefix."usergroup_user as ug ON aa.fk_usergroup > 0 AND ug.fk_usergroup = aa.fk_usergroup AND ug.entity IN (0, ".((int) $conf->entity).")";
		$sql .= " WHERE aa.fk_account = ".((int) $accountId)." LIMIT 100000";
		$rows = $this->db->getRows($sql);
		foreach (is_array($rows) ? $rows : array() as $row) {
			$uid = (int) $row->uid;
			if ($uid > 0 && self::rank((string) $row->level) > self::rank(isset($levels[$uid]) ? $levels[$uid] : self::LEVEL_NONE)) {
				$levels[$uid] = (string) $row->level;
			}
		}
		if (!$levels) {
			return array();
		}
		$out = array();
		$users = $this->db->getRows("SELECT rowid, entity, firstname, lastname, login, photo FROM ".$prefix."user WHERE rowid IN (".implode(',', array_keys($levels)).")"
			." AND statut = 1 AND (fk_soc IS NULL OR fk_soc = 0) ORDER BY lastname, firstname, login LIMIT 100000");
		foreach (is_array($users) ? $users : array() as $u) {
			$name = trim($u->firstname.' '.$u->lastname);
			$out[(int) $u->rowid] = array('id' => (int) $u->rowid, 'name' => $name !== '' ? $name : (string) $u->login, 'level' => $levels[(int) $u->rowid],
				'photo' => self::userPhotoUrl((int) $u->rowid, (string) $u->photo, (int) $u->entity));
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
		$this->emitAccess($author, $fkUser, $fkGroup);

		return 1;
	}

	/**
	 * Journal of the changes (1.121.0): the users concerned ask a new token at once (their mailboxes changed)
	 *
	 * @param User $author  User doing it
	 * @param int  $fkUser  User, or 0
	 * @param int  $fkGroup Group, or 0
	 * @return void
	 */
	protected function emitAccess($author, $fkUser, $fkGroup)
	{
		require_once __DIR__.'/inboxevent.class.php';
		if (InboxEvent::enabled()) {
			$events = new InboxEvent($this->db);
			$events->emitUsers($events->usersOfTarget((int) $fkUser, (int) $fkGroup), 'access', $author instanceof User ? $author : null);
		}
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
		$this->emitAccess($author, (int) $fkUser, (int) $fkGroup);
		// Protected mailbox (1.89.0): the encrypted copies of who lost the access go with it
		require_once __DIR__.'/inboxprotect.class.php';
		(new InboxProtect($this->db))->pruneBlobs((int) $accountId);

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
