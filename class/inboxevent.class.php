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
 * \file    inbox/class/inboxevent.class.php
 * \ingroup inbox
 * \brief   Journal of the changes for the quicker refresh of the lists (1.121.0)
 */

/**
 * What changed and where, so that the lists open by the other users follow without waiting for the refresh of 20 s.
 *
 * Rules:
 * - numbers only: a type from a fixed list, mailbox, folder, mails, users. Never a subject, a text, an address or a
 *   name; this class cannot store one (integers and the fixed list only). The browser reads the rows again through
 *   the calls that check the rights.
 * - each user reads only the events of the mailboxes he can read and his own ones (fk_user_target): the filter is on
 *   the server, in the signed token, never in the browser.
 * - written only when the reactivity is not « Standard » (INBOX_REACTIVITY), kept 24 h (cronPurge).
 *
 * The light call (live.php) does not start Dolibarr: it checks the token signed here with the key of the instance
 * and reads the journal by since().
 */
class InboxEvent
{
	/** Types of events */
	const TYPES = array('assign', 'tag', 'flag', 'seen', 'move', 'delete', 'folder', 'access', 'notif');

	/** Mails named in one event at most; beyond, ids is empty: the folder is read again */
	const MAX_IDS = 40;

	/** Events given back by one call at most; beyond, the browser reads everything again (reset) */
	const MAX_READ = 200;

	/** Life of a token in seconds; the browser asks a new one before the end (the rights are checked again then) */
	const TOKEN_LIFE = 300;

	/** Seconds left under which live.php asks the browser for a new token */
	const RENEW_BEFORE = 120;

	/** Calls of live.php of one user in 10 seconds at most (all his browsers), when the server has APCu */
	const MAX_CALLS_10S = 20;

	/** Events of the journal one call may go through at most; beyond, the browser reads everything again (reset) */
	const MAX_SCAN = 5000;

	/** Hours an event is kept */
	const KEEP_HOURS = 24;

	/** @var DoliDB */
	protected $db;

	/** @var int Events written by this object (tests) */
	public $written = 0;

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * @return bool The journal is written (Settings > Reactivity is not « Standard »): fast, or instant (1.124.0,
	 *              the journal stays the reference, the push driver only delivers it sooner)
	 */
	public static function enabled()
	{
		return in_array(getDolGlobalString('INBOX_REACTIVITY'), array('fast', 'instant'), true);
	}

	/**
	 * Rhythm of the quick refresh, in seconds: while the user is active (3 to 60), after 2 minutes without activity
	 * (from the active one to 300, never quicker than the active one)
	 *
	 * @param int|null $interval Active interval to check (null: the setting)
	 * @param int|null $idle     Idle interval to check (null: the setting)
	 * @return array{interval:int,idle:int}
	 */
	public static function rhythm($interval = null, $idle = null)
	{
		$interval = $interval === null ? getDolGlobalInt('INBOX_REACTIVITY_INTERVAL', 5) : (int) $interval;
		$interval = min(60, max(3, $interval ?: 5));
		$idle = $idle === null ? getDolGlobalInt('INBOX_REACTIVITY_IDLE', 60) : (int) $idle;
		$idle = min(300, max($interval, $idle ?: 60));
		return array('interval' => $interval, 'idle' => $idle);
	}

	/**
	 * Write an event of a mailbox (read by all the users who can read it)
	 *
	 * @param int        $accountId Mailbox
	 * @param string     $type      One of TYPES
	 * @param int[]      $mailIds   Rows of the index concerned (empty: the folder, or the mailbox)
	 * @param int        $folderId  Folder (0: the mailbox)
	 * @param User|null  $author    User who did it (null: the synchronisation)
	 * @return int                  Row of the event, 0 if not written
	 */
	public function emit($accountId, $type, array $mailIds = array(), $folderId = 0, $author = null)
	{
		if ((int) $accountId <= 0) {
			return 0;
		}

		return $this->write((int) $accountId, 0, $type, $mailIds, (int) $folderId, $author);
	}

	/**
	 * Write an event for one user only (his accesses changed: his token is asked again at once)
	 *
	 * @param int[]     $userIds Users
	 * @param string    $type    One of TYPES
	 * @param User|null $author  User who did it
	 * @return int               Events written
	 */
	public function emitUsers(array $userIds, $type, $author = null)
	{
		$n = 0;
		foreach (array_unique(array_filter(array_map('intval', $userIds))) as $uid) {
			$n += $this->write(0, $uid, $type, array(), 0, $author) > 0 ? 1 : 0;
		}

		return $n;
	}

	/**
	 * Users whose token must change after a change of an access (a user, or the members of a group)
	 *
	 * @param int $fkUser  User (0: none)
	 * @param int $fkGroup Group (0: none)
	 * @return int[]
	 */
	public function usersOfTarget($fkUser, $fkGroup)
	{
		$out = array();
		if ((int) $fkUser > 0) {
			$out[] = (int) $fkUser;
		}
		if ((int) $fkGroup > 0) {
			$rows = $this->db->getRows("SELECT fk_user FROM ".$this->db->prefix()."usergroup_user WHERE fk_usergroup = ".((int) $fkGroup)." LIMIT 10000");
			foreach (is_array($rows) ? $rows : array() as $r) {
				$out[] = (int) $r->fk_user;
			}
		}

		return array_values(array_unique($out));
	}

	/**
	 * @param int       $accountId Mailbox (0 with a target)
	 * @param int       $target    User (0 with a mailbox)
	 * @param string    $type      Type
	 * @param int[]     $mailIds   Mails
	 * @param int       $folderId  Folder
	 * @param User|null $author    Author
	 * @return int                 Row, 0 if not written
	 */
	protected function write($accountId, $target, $type, array $mailIds, $folderId, $author)
	{
		global $conf;

		if (!self::enabled() || !in_array($type, self::TYPES, true)) {
			return 0;
		}
		$ids = self::idsText($mailIds);
		$origin = self::origin();
		$sql = "INSERT INTO ".$this->db->prefix()."inbox_event (entity, fk_account, fk_user_target, type, fk_folder, ids, fk_user_author, origin, datec) VALUES ("
			.((int) $conf->entity).", ".((int) $accountId).", ".((int) $target).", '".$this->db->escape($type)."', ".max(0, (int) $folderId).", "
			.($ids === '' ? 'NULL' : "'".$this->db->escape($ids)."'").", ".($author instanceof User ? (int) $author->id : 0).", "
			.($origin === '' ? 'NULL' : "'".$this->db->escape($origin)."'").", '".$this->db->idate(dol_now())."')";
		if (!$this->db->query($sql)) {
			// The journal only makes the lists quicker: the action itself is never refused for it
			dol_syslog(__METHOD__.' '.$this->db->lasterror(), LOG_WARNING);
			return 0;
		}
		$this->written++;
		$id = (int) $this->db->last_insert_id($this->db->prefix().'inbox_event');
		if (getDolGlobalString('INBOX_REACTIVITY') === 'instant') {
			// Delivered at the end of the request to the subscribers of this mailbox (or of this user) only
			require_once __DIR__.'/inboxpush.class.php';
			InboxPush::queue($accountId > 0 ? 'account/'.((int) $accountId) : 'user/'.((int) $target), self::format((object) array(
				'rowid' => $id, 'type' => $type, 'fk_account' => $accountId, 'fk_folder' => max(0, (int) $folderId), 'ids' => $ids,
				'fk_user_author' => $author instanceof User ? (int) $author->id : 0, 'origin' => $origin, 'fk_user_target' => $target,
			)));
		}

		return $id;
	}

	/**
	 * An event as the browser receives it, from live.php or from a push driver: numbers only
	 *
	 * @param object $r Row of the journal
	 * @return array{id:int,type:string,account:int,folder:int,ids:int[]|null,author:int,origin:string,mine:bool}
	 */
	public static function format($r)
	{
		return array(
			'id' => (int) $r->rowid,
			'type' => (string) $r->type,
			'account' => (int) $r->fk_account,
			'folder' => (int) $r->fk_folder,
			'ids' => $r->ids === null || $r->ids === '' ? null : array_map('intval', explode(',', (string) $r->ids)),
			'author' => (int) $r->fk_user_author,
			'origin' => (string) $r->origin,
			'mine' => (int) $r->fk_user_target > 0,
		);
	}

	/**
	 * @param int[] $mailIds Mails
	 * @return string        « 12,13,14 », '' when none or too many (the folder is read again)
	 */
	public static function idsText(array $mailIds)
	{
		$ids = array_values(array_unique(array_filter(array_map('intval', $mailIds), function ($id) {
			return $id > 0;
		})));
		if (!$ids || count($ids) > self::MAX_IDS) {
			return '';
		}
		$text = implode(',', $ids);

		return strlen($text) <= 255 ? $text : '';
	}

	/**
	 * Tab of the browser that asked the action (header X-Inbox-Origin): it does not apply its own event again
	 *
	 * @return string
	 */
	public static function origin()
	{
		$o = (string) ($_SERVER['HTTP_X_INBOX_ORIGIN'] ?? '');

		return preg_match('/^[A-Za-z0-9_-]{1,32}$/', $o) ? $o : '';
	}

	/**
	 * Cursor at the start: the last event of the journal, 0 when it is empty
	 *
	 * @param DoliDB $db Database handler
	 * @return int
	 */
	public static function cursor($db)
	{
		$row = $db->getRow("SELECT MAX(rowid) AS m FROM ".$db->prefix()."inbox_event");

		return is_object($row) ? (int) $row->m : 0;
	}

	/**
	 * Events after a cursor for a user: his mailboxes and his own events. Used by live.php without Dolibarr started:
	 * the database handler and the numbers only.
	 *
	 * @param DoliDB $db       Database handler
	 * @param int    $entity   Entity
	 * @param int    $userId   User
	 * @param int[]  $accounts Mailboxes he can read (from his token)
	 * @param int    $since    Last event seen (cursor() at the start; 0 when the journal was empty)
	 * @return array{last:int,events:array<int,array<string,mixed>>,reset:bool}
	 */
	public static function since($db, $entity, $userId, array $accounts, $since)
	{
		$p = $db->prefix();
		$since = max(0, (int) $since);
		$accounts = array_values(array_filter(array_map('intval', $accounts), function ($a) {
			return $a > 0;
		}));
		$out = array('last' => $since, 'events' => array(), 'reset' => false);
		$bounds = $db->getRow("SELECT MIN(rowid) AS lo, MAX(rowid) AS hi FROM ".$p."inbox_event");
		$min = is_object($bounds) ? (int) $bounds->lo : 0;
		$max = is_object($bounds) ? (int) $bounds->hi : 0;
		if (!$max) {
			return $out;
		}
		// The cursor goes to the last event of the journal (those of the other mailboxes too): never read again
		$out['last'] = max($since, $max);
		if ($since === 0) {
			// The journal was empty at the start: nothing can be missing, it is read from its first event
			$since = $min - 1;
		} elseif ($since < $min - 1 || $max - $since > self::MAX_SCAN) {
			// Events forgotten after the cursor (purged), or too many to go through: the browser reads everything
			// again. Each call goes through MAX_SCAN events at most, whatever the cursor sent
			$out['reset'] = true;
			return $out;
		}
		$where = "(fk_user_target = ".((int) $userId).($accounts ? " OR fk_account IN (".implode(',', $accounts).")" : '').")";
		$rows = $db->getRows("SELECT rowid, fk_account, fk_user_target, type, fk_folder, ids, fk_user_author, origin FROM ".$p."inbox_event"
			." WHERE rowid > ".$since." AND rowid <= ".$max." AND entity = ".((int) $entity)." AND ".$where." ORDER BY rowid LIMIT ".(self::MAX_READ + 1));
		$rows = is_array($rows) ? $rows : array();
		if (count($rows) > self::MAX_READ) {
			$out['reset'] = true;
		} else {
			foreach ($rows as $r) {
				$e = self::format($r);
				// The number of a tab is given to its own user only: nobody learns the tabs of the others
				if ($e['author'] !== (int) $userId) {
					$e['origin'] = '';
				}
				$out['events'][] = $e;
			}
		}

		return $out;
	}

	/**
	 * Light check of a token at each call (live.php): the user still active, still a user of the company, the module
	 * still enabled, and of the mailboxes of the token only those he still reaches (the levels of InboxAccess, less
	 * detailed: any level reads). Never adds a mailbox: a new access comes with a new token.
	 *
	 * @param DoliDB $db       Database handler
	 * @param int    $entity   Entity
	 * @param int    $userId   User
	 * @param int[]  $accounts Mailboxes of the token
	 * @return int[]|null      Mailboxes still reached, null: the token is no longer valid
	 */
	public static function stillReached($db, $entity, $userId, array $accounts)
	{
		$p = $db->prefix();
		$entity = (int) $entity;
		$userId = (int) $userId;
		$u = $db->getRow("SELECT u.statut, u.fk_soc, (SELECT COUNT(*) FROM ".$p."const as c WHERE c.name = 'MAIN_MODULE_INBOX' AND c.entity IN (0, ".$entity.") AND c.value <> '0' AND c.value <> '') AS module"
			." FROM ".$p."user as u WHERE u.rowid = ".$userId);
		if (!is_object($u) || (int) $u->statut !== 1 || (int) $u->fk_soc > 0 || (int) $u->module < 1) {
			return null;
		}
		$accounts = array_values(array_filter(array_map('intval', $accounts), function ($a) {
			return $a > 0;
		}));
		if (!$accounts) {
			return array();
		}
		$rows = $db->getRows("SELECT a.rowid FROM ".$p."inbox_account as a WHERE a.rowid IN (".implode(',', $accounts).") AND a.entity = ".$entity." AND a.status = 1 AND a.protected = 0"
			." AND ((a.type = 'personal' AND a.fk_user_owner = ".$userId.")"
			." OR EXISTS (SELECT 1 FROM ".$p."inbox_account_access as aa WHERE aa.fk_account = a.rowid AND aa.level <> ''"
			." AND (aa.fk_user = ".$userId." OR (aa.fk_usergroup > 0 AND aa.fk_usergroup IN (SELECT ug.fk_usergroup FROM ".$p."usergroup_user as ug WHERE ug.fk_user = ".$userId." AND ug.entity IN (0, ".$entity."))))))"
			." LIMIT 10000");
		$still = array();
		foreach (is_array($rows) ? $rows : array() as $r) {
			$still[] = (int) $r->rowid;
		}
		sort($still);

		return $still;
	}

	/**
	 * @param int $hours Hours kept
	 * @return int       Rows removed
	 */
	public function purge($hours = self::KEEP_HOURS)
	{
		$this->db->query("DELETE FROM ".$this->db->prefix()."inbox_event WHERE datec < '".$this->db->idate(dol_now() - max(1, (int) $hours) * 3600)."'");

		return (int) $this->db->affected_rows(true);
	}

	// ---------- Token of the light call ----------

	/**
	 * Key of the tokens, from the unique id of the instance (conf.php): never stored, never sent
	 *
	 * @param string $instanceId $dolibarr_main_instance_unique_id (or the cookie key, as Dolibarr does)
	 * @return string            Binary key, '' if the instance has no id (no token then)
	 */
	public static function key($instanceId)
	{
		return (string) $instanceId === '' ? '' : hash_hmac('sha256', 'inbox-reactivity-token', (string) $instanceId, true);
	}

	/**
	 * Token of a user: his entity, the mailboxes he can read now, the end of its life. Signed, never stored.
	 *
	 * @param User   $user User
	 * @param string $key  Key (key())
	 * @param int    $now  Time (tests)
	 * @return array{token:string,expires:int,accounts:int[]}|null Null when the key is missing
	 */
	public function token(User $user, $key, $now = 0)
	{
		global $conf;

		if ($key === '') {
			return null;
		}
		require_once __DIR__.'/inboxaccess.class.php';
		$access = new InboxAccess($this->db);
		$accounts = array_map('intval', array_keys($access->getLevels($user)));
		// Protected mailboxes have nothing in the index: no event, never in the token
		if ($accounts) {
			$rows = $this->db->getRows("SELECT rowid FROM ".$this->db->prefix()."inbox_account WHERE rowid IN (".implode(',', $accounts).") AND protected = 1 LIMIT 10000");
			$protected = array_map(function ($r) {
				return (int) $r->rowid;
			}, is_array($rows) ? $rows : array());
			$accounts = array_values(array_diff($accounts, $protected));
		}
		sort($accounts);
		$expires = ($now ?: dol_now()) + self::TOKEN_LIFE;
		$body = self::b64(json_encode(array('u' => (int) $user->id, 'e' => (int) $conf->entity, 'a' => $accounts, 'x' => $expires)));

		return array('token' => $body.'.'.self::b64(hash_hmac('sha256', $body, $key, true)), 'expires' => $expires, 'accounts' => $accounts);
	}

	/**
	 * Check a token (live.php)
	 *
	 * @param string $token Token
	 * @param string $key   Key (key())
	 * @param int    $now   Time
	 * @return array{u:int,e:int,a:int[],x:int}|null Null if missing, badly signed or expired
	 */
	public static function verify($token, $key, $now)
	{
		$parts = explode('.', (string) $token);
		if ($key === '' || count($parts) !== 2 || strlen($parts[0]) > 20000) {
			return null;
		}
		if (!hash_equals(self::b64(hash_hmac('sha256', $parts[0], $key, true)), $parts[1])) {
			return null;
		}
		$data = json_decode((string) base64_decode(strtr($parts[0], '-_', '+/')), true);
		if (!is_array($data) || !isset($data['u'], $data['e'], $data['a'], $data['x']) || !is_array($data['a']) || (int) $data['x'] < (int) $now) {
			return null;
		}

		return array('u' => (int) $data['u'], 'e' => (int) $data['e'], 'a' => array_map('intval', $data['a']), 'x' => (int) $data['x']);
	}

	/**
	 * @param string $bin Binary or text
	 * @return string     Base64 for an URL, without padding
	 */
	protected static function b64($bin)
	{
		return rtrim(strtr(base64_encode((string) $bin), '+/', '-_'), '=');
	}
}
