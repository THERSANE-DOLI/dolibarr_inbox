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
 * \file    inbox/class/inboxprotect.class.php
 * \ingroup inbox
 * \brief   Protected mailboxes (1.89.0): keys of the browsers, encrypted copies of the password, unlocking for a session
 */

require_once __DIR__.'/inboxaccount.class.php';
require_once __DIR__.'/inboxaccess.class.php';
require_once __DIR__.'/inboxaccountlog.class.php';
require_once __DIR__.'/inboxconsumer.class.php';

/**
 * A protected mailbox has no password on the server. Its password is encrypted in the browsers (WebCrypto, ECDH P-256
 * then AES-GCM) for the public key of each device of each user who may open it; the server keeps these copies without
 * being able to open them (analyse-boites-protegees.md §3).
 *
 * While a user works, his browser opens its copy and gives the password to the server, which keeps it in the PHP
 * session encrypted with a key that only lives in a cookie of the browser: neither the session file nor the database
 * are enough to read it, and nothing out of a request of that browser (scheduled job, other user) can use it.
 *
 * Never: the password in the database, a log, an error message; an encryption for a device that no human confirmed.
 */
class InboxProtect
{
	/** Entry of the session */
	const SESSION_KEY = 'inbox_unlocked';
	/** Prefix of the cookie holding the key of the session copies */
	const COOKIE_PREFIX = 'DOLINBOXLOCK_';
	/** Active devices of a user at most */
	const MAX_DEVICES = 10;
	/** Version of the encrypted copies */
	const BLOB_VERSION = 1;
	/** Size of an encrypted copy at most (JSON) */
	const BLOB_MAX = 4096;

	const ORIGIN_PASSWORD = 'password';
	const ORIGIN_GRANT = 'grant';
	const ORIGIN_APPROVE = 'approve';

	const LOG_PROTECT = 'protect';
	const LOG_UNPROTECT = 'unprotect';
	const LOG_RESET = 'protect_reset';
	const LOG_ENROL = 'key_enrol';
	const LOG_GRANT = 'key_grant';
	const LOG_APPROVE = 'key_approve';
	const LOG_REVOKE = 'key_revoke';
	const LOG_UNLOCK = 'unlock';
	const LOG_UNLOCK_FAILED = 'unlock_failed';
	const LOG_LEVEL = 'protect_level';

	/** @var array<int,array{imap:string,smtp?:string}> Passwords of this request (tried or read from the session) */
	protected static $memory = array();

	/** @var DoliDB */
	protected $db;

	/** @var string Key of the error */
	public $error = '';

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct(DoliDB $db)
	{
		$this->db = $db;
	}

	// ---------------------------------------------------------------------------------------------------------------
	// Session
	// ---------------------------------------------------------------------------------------------------------------

	/**
	 * @return string Name of the cookie of this instance (one per session cookie of Dolibarr)
	 */
	public static function cookieName()
	{
		return self::COOKIE_PREFIX.substr(md5((string) session_name()), 0, 12);
	}

	/**
	 * Key of the session copies, from the cookie; a new one is made (and the cookie set) when asked
	 *
	 * @param bool $create Make it when missing
	 * @return string      32 bytes, '' if none
	 */
	protected static function cookieKey($create)
	{
		$name = self::cookieName();
		$raw = isset($_COOKIE[$name]) ? base64_decode(strtr((string) $_COOKIE[$name], '-_', '+/'), true) : false;
		if (is_string($raw) && strlen($raw) === 32) {
			return $raw;
		}
		if (!$create) {
			return '';
		}
		$raw = random_bytes(32);
		$value = rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
		if (!headers_sent()) {
			setcookie($name, $value, array(
				'expires' => 0,
				'path' => (DOL_URL_ROOT === '' ? '/' : DOL_URL_ROOT.'/'),
				'secure' => self::isHttps(),
				'httponly' => true,
				'samesite' => 'Strict',
			));
		}
		$_COOKIE[$name] = $value;
		// Copies of an older key cannot be read any more
		unset($_SESSION[self::SESSION_KEY], $_SESSION[self::SESSION_KEY.'_data']);

		return $raw;
	}

	/**
	 * @return bool The page was asked over HTTPS
	 */
	protected static function isHttps()
	{
		return (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
			|| (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
	}

	/**
	 * @param int $accountId Mailbox
	 * @return string        Associated data of the session copy: it does not open for another mailbox or user
	 */
	protected static function sessionAad($accountId)
	{
		global $conf, $user;

		return 'inbox-session|'.((int) $conf->entity).'|'.((int) $accountId).'|'.(is_object($user) ? (int) $user->id : 0);
	}

	/**
	 * Keep the passwords of a mailbox for the rest of the session (after they were tried on the mail server)
	 *
	 * @param int                  $accountId Mailbox
	 * @param array<string,string> $secrets   imap, and smtp when the SMTP server has its own password
	 * @param int                  $deviceId  Device that opened it (journal, once per session)
	 * @return bool
	 */
	public static function unlock($accountId, array $secrets, $deviceId = 0)
	{
		global $conf;

		$key = self::cookieKey(true);
		$clean = array('imap' => (string) ($secrets['imap'] ?? ''));
		if (isset($secrets['smtp']) && (string) $secrets['smtp'] !== '') {
			$clean['smtp'] = (string) $secrets['smtp'];
		}
		$iv = random_bytes(12);
		$tag = '';
		$ct = openssl_encrypt((string) json_encode($clean), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, self::sessionAad($accountId));
		if ($ct === false) {
			return false;
		}
		$_SESSION[self::SESSION_KEY][(int) $conf->entity][(int) $accountId] = array('c' => base64_encode($iv.$tag.$ct), 'd' => (int) $deviceId, 't' => dol_now());
		self::$memory[(int) $accountId] = $clean;

		return true;
	}

	/**
	 * Passwords of an unlocked mailbox, rebuilt in memory for this request only
	 *
	 * @param int $accountId Mailbox
	 * @return array{imap:string,smtp?:string}|null null when locked
	 */
	public static function secrets($accountId)
	{
		global $conf;

		$accountId = (int) $accountId;
		if (isset(self::$memory[$accountId])) {
			return self::$memory[$accountId];
		}
		$entry = $_SESSION[self::SESSION_KEY][(int) $conf->entity][$accountId] ?? null;
		if (!is_array($entry) || empty($entry['c'])) {
			return null;
		}
		$key = self::cookieKey(false);
		$raw = base64_decode((string) $entry['c'], true);
		if ($key === '' || !is_string($raw) || strlen($raw) < 29) {
			return null;
		}
		$plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16), self::sessionAad($accountId));
		$data = $plain === false ? null : json_decode($plain, true);
		if (!is_array($data) || !isset($data['imap'])) {
			return null;
		}
		self::$memory[$accountId] = $data;

		return $data;
	}

	/**
	 * @param int $accountId Mailbox
	 * @return bool          Unlocked in this session
	 */
	public static function isUnlocked($accountId)
	{
		return self::secrets($accountId) !== null;
	}

	/**
	 * @param int $accountId Mailbox
	 * @return int           Device that unlocked the mailbox in this session, 0 if none
	 */
	public static function unlockedBy($accountId)
	{
		global $conf;

		return (int) ($_SESSION[self::SESSION_KEY][(int) $conf->entity][(int) $accountId]['d'] ?? 0);
	}

	/**
	 * Encrypt bytes with the key of the cookie (a mail waiting for its sending): readable only by a request of
	 * this browser in this session
	 *
	 * @param string $bytes Bytes
	 * @param string $aad   Associated data
	 * @return string|null  iv | tag | cipher text, null without key
	 */
	public static function seal($bytes, $aad)
	{
		$key = self::cookieKey(false);
		if ($key === '') {
			return null;
		}
		$iv = random_bytes(12);
		$tag = '';
		$ct = openssl_encrypt((string) $bytes, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, 'inbox-seal|'.$aad.'|'.self::sessionAad(0));

		return $ct === false ? null : $iv.$tag.$ct;
	}

	/**
	 * @param string $sealed Result of seal()
	 * @param string $aad    Associated data
	 * @return string|null   Bytes, null if not readable here
	 */
	public static function unseal($sealed, $aad)
	{
		$key = self::cookieKey(false);
		if ($key === '' || strlen((string) $sealed) < 29) {
			return null;
		}
		$plain = openssl_decrypt(substr($sealed, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($sealed, 0, 12), substr($sealed, 12, 16), 'inbox-seal|'.$aad.'|'.self::sessionAad(0));

		return $plain === false ? null : $plain;
	}

	/**
	 * Keep data of a protected mailbox for the session, encrypted like the passwords (key of the cookie): the live
	 * reading keeps there which folder and which mail each number of the page stands for, never in clear
	 *
	 * @param string              $slot Name
	 * @param array<mixed>|null   $data Data, null to forget
	 * @return bool
	 */
	public static function sessionPut($slot, $data)
	{
		global $conf;

		if ($data === null) {
			unset($_SESSION[self::SESSION_KEY.'_data'][(int) $conf->entity][$slot]);
			return true;
		}
		$key = self::cookieKey(false);
		if ($key === '') {
			return false;
		}
		$iv = random_bytes(12);
		$tag = '';
		$ct = openssl_encrypt((string) json_encode($data), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, 'inbox-data|'.$slot.'|'.self::sessionAad(0));
		if ($ct === false) {
			return false;
		}
		$_SESSION[self::SESSION_KEY.'_data'][(int) $conf->entity][$slot] = base64_encode($iv.$tag.$ct);

		return true;
	}

	/**
	 * @param string $slot Name
	 * @return array<mixed>|null Data kept by sessionPut(), null if none or not readable
	 */
	public static function sessionGet($slot)
	{
		global $conf;

		$raw = $_SESSION[self::SESSION_KEY.'_data'][(int) $conf->entity][$slot] ?? null;
		$key = self::cookieKey(false);
		$raw = is_string($raw) ? base64_decode($raw, true) : false;
		if ($key === '' || !is_string($raw) || strlen($raw) < 29) {
			return null;
		}
		$plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16), 'inbox-data|'.$slot.'|'.self::sessionAad(0));
		$data = $plain === false ? null : json_decode($plain, true);

		return is_array($data) ? $data : null;
	}

	/**
	 * Use passwords for this request only (a try before they are kept), or forget them (null)
	 *
	 * @param int                       $accountId Mailbox
	 * @param array<string,string>|null $secrets   Passwords, null to forget
	 * @return void
	 */
	public static function tryWith($accountId, $secrets)
	{
		if ($secrets === null) {
			unset(self::$memory[(int) $accountId]);
			return;
		}
		self::$memory[(int) $accountId] = array('imap' => (string) ($secrets['imap'] ?? '')) + ((isset($secrets['smtp']) && (string) $secrets['smtp'] !== '') ? array('smtp' => (string) $secrets['smtp']) : array());
	}

	/**
	 * Lock a mailbox now (button « Lock now », mailbox turned back to normal, access removed)
	 *
	 * @param int $accountId Mailbox
	 * @return void
	 */
	public static function lock($accountId)
	{
		global $conf;

		unset($_SESSION[self::SESSION_KEY][(int) $conf->entity][(int) $accountId], self::$memory[(int) $accountId]);
	}

	/**
	 * Lock every mailbox (logout)
	 *
	 * @return void
	 */
	public static function lockAll()
	{
		unset($_SESSION[self::SESSION_KEY], $_SESSION[self::SESSION_KEY.'_data']);
		self::$memory = array();
		$name = self::cookieName();
		if (isset($_COOKIE[$name]) && !headers_sent()) {
			setcookie($name, '', array('expires' => 1, 'path' => (DOL_URL_ROOT === '' ? '/' : DOL_URL_ROOT.'/'), 'secure' => self::isHttps(), 'httponly' => true, 'samesite' => 'Strict'));
		}
		unset($_COOKIE[$name]);
	}

	/**
	 * Forget the passwords kept in memory for this request (tests, end of a long job)
	 *
	 * @return void
	 */
	public static function forgetMemory()
	{
		self::$memory = array();
	}

	// ---------------------------------------------------------------------------------------------------------------
	// Rights (D4)
	// ---------------------------------------------------------------------------------------------------------------

	/**
	 * Who may protect a mailbox or turn it back to normal: the admin of the mailboxes, or the owner of a personal
	 * mailbox. Never a manager only (a shared mailbox is decided by the admin).
	 *
	 * @param User         $user    User
	 * @param InboxAccount $account Mailbox
	 * @return bool
	 */
	public static function canDecide(User $user, InboxAccount $account)
	{
		if ($user->hasRight('inbox', 'account', 'admin')) {
			return true;
		}

		return $account->type === InboxAccount::TYPE_PERSONAL && (int) $account->fk_user_owner > 0 && (int) $account->fk_user_owner === (int) $user->id;
	}

	/**
	 * A mailbox can be protected: login and password only (D5), not the master user nor OAuth
	 *
	 * @param InboxAccount $account Mailbox
	 * @return string               Key of the error, '' if possible
	 */
	public static function refusal(InboxAccount $account)
	{
		if ((string) $account->connector !== 'imap') {
			return 'InboxProtectErrorConnector';
		}
		if ((string) $account->imap_auth_mode !== InboxAccount::AUTH_LOGIN) {
			return 'InboxProtectErrorAuthMode';
		}
		// The master user opens every mailbox of its server: a protection there would promise nothing (audit INBX-057)
		$masterHost = strtolower(trim(getDolGlobalString('INBOX_MASTER_HOST')));
		if (getDolGlobalString('INBOX_MASTER_USER') !== '' && $masterHost !== '' && strtolower(trim((string) $account->imap_host)) === $masterHost) {
			return 'InboxProtectErrorMasterServer';
		}

		return '';
	}

	// ---------------------------------------------------------------------------------------------------------------
	// Devices
	// ---------------------------------------------------------------------------------------------------------------

	/**
	 * Check a public key (JWK of an ECDH P-256 key) and give it back with only its public members
	 *
	 * @param mixed $jwk Key sent by the browser
	 * @return array{kty:string,crv:string,x:string,y:string}|null
	 */
	public static function cleanJwk($jwk)
	{
		if (is_string($jwk)) {
			$jwk = json_decode($jwk, true);
		}
		if (!is_array($jwk) || ($jwk['kty'] ?? '') !== 'EC' || ($jwk['crv'] ?? '') !== 'P-256' || isset($jwk['d'])) {
			return null;
		}
		foreach (array('x', 'y') as $c) {
			if (!is_string($jwk[$c] ?? null) || !preg_match('/^[A-Za-z0-9_-]{43}$/', $jwk[$c]) || strlen((string) self::b64url($jwk[$c])) !== 32) {
				return null;
			}
		}

		return array('kty' => 'EC', 'crv' => 'P-256', 'x' => $jwk['x'], 'y' => $jwk['y']);
	}

	/**
	 * @param string $s Base64url
	 * @return string|false Bytes
	 */
	protected static function b64url($s)
	{
		$s = strtr((string) $s, '-_', '+/');

		return base64_decode($s.str_repeat('=', (4 - strlen($s) % 4) % 4), true);
	}

	/**
	 * Fingerprint shown wherever a device is named: SHA-256 of the raw public key (0x04 | x | y), 16 hexadecimal
	 * characters in 4 groups. The browser computes the same one (js/inbox-keys.js).
	 *
	 * @param array{x:string,y:string} $jwk Public key
	 * @return string XXXX-XXXX-XXXX-XXXX
	 */
	public static function fingerprint(array $jwk)
	{
		$hex = strtoupper(substr(hash('sha256', "\x04".self::b64url($jwk['x']).self::b64url($jwk['y'])), 0, 16));

		return implode('-', str_split($hex, 4));
	}

	/**
	 * Record the key of a browser of the user (the same key again gives the same device)
	 *
	 * @param User   $user  User
	 * @param mixed  $jwk   Public key
	 * @param string $label Label (browser and system, can be changed)
	 * @return array<string,mixed>|null Device, null if refused ($this->error)
	 */
	public function registerDevice(User $user, $jwk, $label)
	{
		global $conf;

		$key = self::cleanJwk($jwk);
		if ($key === null) {
			$this->error = 'InboxProtectErrorKey';
			return null;
		}
		$fp = self::fingerprint($key);
		$prefix = $this->db->prefix();
		// One device per entity: the browser keeps one key for the whole site, registered again in each entity of the
		// user (approved there, revoked there)
		$row = $this->db->getRow("SELECT rowid, status FROM ".$prefix."inbox_device WHERE entity = ".((int) $conf->entity)." AND fk_user = ".((int) $user->id)." AND fingerprint = '".$this->db->escape($fp)."'");
		if (is_object($row)) {
			if ((int) $row->status !== 1) {
				// A revoked key is never taken again: the browser makes a new one
				$this->error = 'InboxProtectErrorRevoked';
				return null;
			}
			$this->db->query("UPDATE ".$prefix."inbox_device SET date_last_use = '".$this->db->idate(dol_now())."' WHERE rowid = ".((int) $row->rowid));
			$device = $this->device((int) $row->rowid);
			if ($device === null) {
				$this->error = 'InboxProtectErrorDeviceRegister';
			}
			return $device;
		}
		$count = $this->db->getRow("SELECT COUNT(rowid) AS n FROM ".$prefix."inbox_device WHERE fk_user = ".((int) $user->id)." AND entity = ".((int) $conf->entity)." AND status = 1");
		if (is_object($count) && (int) $count->n >= self::MAX_DEVICES) {
			$this->error = 'InboxProtectErrorTooManyDevices';
			return null;
		}
		$label = dol_trunc(trim(dol_string_nohtmltag((string) $label)), 120, 'right', 'UTF-8', 1);
		$sql = "INSERT INTO ".$prefix."inbox_device (entity, fk_user, public_key, fingerprint, label, status, date_creation, date_last_use)";
		$sql .= " VALUES (".((int) $conf->entity).", ".((int) $user->id).", '".$this->db->escape((string) json_encode($key))."', '".$this->db->escape($fp)."',";
		$sql .= " ".($label === '' ? 'NULL' : "'".$this->db->escape($label)."'").", 1, '".$this->db->idate(dol_now())."', '".$this->db->idate(dol_now())."')";
		if (!$this->db->query($sql)) {
			// Never the text of the database to the browser; the module not activated again since 1.128.0 (unique
			// index without the entity) ends here in a second entity
			dol_syslog(__METHOD__.' '.$this->db->lasterror(), LOG_ERR);
			$this->error = 'InboxProtectErrorDeviceRegister';
			return null;
		}
		$device = $this->device((int) $this->db->last_insert_id($prefix.'inbox_device'));
		if ($device === null) {
			$this->error = 'InboxProtectErrorDeviceRegister';
		}

		return $device;
	}

	/**
	 * @param int $id Device
	 * @return array<string,mixed>|null
	 */
	public function device($id)
	{
		global $conf;

		$row = $this->db->getRow("SELECT rowid, fk_user, public_key, fingerprint, label, approve_asked, status, date_creation, date_last_use FROM ".$this->db->prefix()."inbox_device"
			." WHERE rowid = ".((int) $id)." AND entity = ".((int) $conf->entity));

		return is_object($row) ? $this->deviceArray($row) : null;
	}

	/**
	 * @param stdClass $row Row of llx_inbox_device
	 * @return array<string,mixed>
	 */
	protected function deviceArray($row)
	{
		// The fingerprint shown is the one of the key, computed again: never the column alone (audit INBX-063)
		$jwk = json_decode((string) $row->public_key, true);

		return array(
			'id' => (int) $row->rowid,
			'fk_user' => (int) $row->fk_user,
			'public_key' => $jwk,
			'fingerprint' => is_array($jwk) && isset($jwk['x'], $jwk['y']) && is_string($jwk['x']) && is_string($jwk['y']) ? self::fingerprint($jwk) : '',
			'label' => (string) $row->label,
			'approve_asked' => $row->approve_asked ? (int) $this->db->jdate($row->approve_asked) : null,
			'status' => (int) $row->status,
			'date_creation' => (int) $this->db->jdate($row->date_creation),
			'date_last_use' => $row->date_last_use ? (int) $this->db->jdate($row->date_last_use) : null,
		);
	}

	/**
	 * Active devices of users
	 *
	 * @param int[] $userIds Users
	 * @return array<int,array<int,array<string,mixed>>> User id => devices
	 */
	public function devicesOf(array $userIds)
	{
		global $conf;

		$ids = array_filter(array_map('intval', $userIds));
		$out = array();
		if (!$ids) {
			return $out;
		}
		$rows = $this->db->getRows("SELECT rowid, fk_user, public_key, fingerprint, label, approve_asked, status, date_creation, date_last_use FROM ".$this->db->prefix()."inbox_device"
			." WHERE entity = ".((int) $conf->entity)." AND status = 1 AND fk_user IN (".implode(',', $ids).") ORDER BY date_last_use DESC, rowid DESC LIMIT 10000");
		foreach (is_array($rows) ? $rows : array() as $row) {
			$out[(int) $row->fk_user][] = $this->deviceArray($row);
		}

		return $out;
	}

	/**
	 * Change the label of one of his devices
	 *
	 * @param User   $user  User
	 * @param int    $id    Device
	 * @param string $label Label
	 * @return bool
	 */
	public function renameDevice(User $user, $id, $label)
	{
		$device = $this->device($id);
		if (!$device || $device['fk_user'] !== (int) $user->id || $device['status'] !== 1) {
			$this->error = 'InboxProtectErrorDevice';
			return false;
		}
		$label = dol_trunc(trim(dol_string_nohtmltag((string) $label)), 120, 'right', 'UTF-8', 1);

		return (bool) $this->db->query("UPDATE ".$this->db->prefix()."inbox_device SET label = ".($label === '' ? 'NULL' : "'".$this->db->escape($label)."'")." WHERE rowid = ".((int) $id));
	}

	/**
	 * The new device asks to be approved from another device of the user (§5.3), or stops asking
	 *
	 * @param User $user User
	 * @param int  $id   Device (his)
	 * @param bool $ask  Ask, or stop asking
	 * @return bool
	 */
	public function askApproval(User $user, $id, $ask = true)
	{
		$device = $this->device($id);
		if (!$device || $device['fk_user'] !== (int) $user->id || $device['status'] !== 1) {
			$this->error = 'InboxProtectErrorDevice';
			return false;
		}

		return (bool) $this->db->query("UPDATE ".$this->db->prefix()."inbox_device SET approve_asked = ".($ask ? "'".$this->db->idate(dol_now())."'" : 'NULL')." WHERE rowid = ".((int) $id));
	}

	/**
	 * Revoke a device: its copies are deleted, a stolen computer keeps its key but has nothing to open (§5.6). The user
	 * himself, or the admin of the mailboxes.
	 *
	 * @param User $author User doing it
	 * @param int  $id     Device
	 * @return bool
	 */
	public function revokeDevice(User $author, $id)
	{
		$device = $this->device($id);
		if (!$device || $device['status'] !== 1 || ($device['fk_user'] !== (int) $author->id && !$author->hasRight('inbox', 'account', 'admin'))) {
			$this->error = 'InboxProtectErrorDevice';
			return false;
		}
		$prefix = $this->db->prefix();
		$this->db->begin();
		$accounts = $this->db->getRows("SELECT DISTINCT fk_account FROM ".$prefix."inbox_account_key WHERE fk_device = ".((int) $id)." LIMIT 10000");
		$ok = $this->db->query("UPDATE ".$prefix."inbox_device SET status = 0, approve_asked = NULL, fk_user_revoke = ".((int) $author->id).", date_revoke = '".$this->db->idate(dol_now())."' WHERE rowid = ".((int) $id))
			&& $this->db->query("DELETE FROM ".$prefix."inbox_account_key WHERE fk_device = ".((int) $id));
		if (!$ok) {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			return false;
		}
		$log = new InboxAccountLog($this->db);
		foreach (is_array($accounts) ? $accounts : array() as $row) {
			$log->add((int) $row->fk_account, $author, self::LOG_REVOKE, null, array('fk_user' => $device['fk_user'], 'device' => $device['fingerprint']));
		}
		$this->db->commit();

		return true;
	}

	/**
	 * Revoke every device of a user (user disabled in Dolibarr)
	 *
	 * @param User $author User doing it
	 * @param int  $userId User whose devices go
	 * @return int         Number of devices revoked
	 */
	public function revokeUser(User $author, $userId)
	{
		$n = 0;
		foreach ($this->devicesOf(array((int) $userId))[(int) $userId] ?? array() as $device) {
			$prefix = $this->db->prefix();
			if ($this->db->query("UPDATE ".$prefix."inbox_device SET status = 0, approve_asked = NULL, fk_user_revoke = ".((int) $author->id).", date_revoke = '".$this->db->idate(dol_now())."' WHERE rowid = ".((int) $device['id']))
				&& $this->db->query("DELETE FROM ".$prefix."inbox_account_key WHERE fk_device = ".((int) $device['id']))) {
				$n++;
			}
		}

		return $n;
	}

	// ---------------------------------------------------------------------------------------------------------------
	// Encrypted copies
	// ---------------------------------------------------------------------------------------------------------------

	/**
	 * Check an encrypted copy made by the browser (the server cannot open it, only its form is checked)
	 *
	 * @param mixed $blob Copy
	 * @return string|null JSON kept, null if refused
	 */
	public static function cleanBlob($blob)
	{
		if (is_string($blob)) {
			$blob = json_decode($blob, true);
		}
		if (!is_array($blob) || (int) ($blob['v'] ?? 0) !== self::BLOB_VERSION || self::cleanJwk($blob['epk'] ?? null) === null) {
			return null;
		}
		if (!is_string($blob['iv'] ?? null) || !preg_match('/^[A-Za-z0-9_-]{16}$/', $blob['iv']) || !is_string($blob['ct'] ?? null) || !preg_match('/^[A-Za-z0-9_-]{24,3000}$/', $blob['ct'])) {
			return null;
		}
		$json = (string) json_encode(array('v' => self::BLOB_VERSION, 'epk' => self::cleanJwk($blob['epk']), 'iv' => $blob['iv'], 'ct' => $blob['ct']));

		return strlen($json) <= self::BLOB_MAX ? $json : null;
	}

	/**
	 * Associated data of a copy (§3.2): a copy written on another line (mailbox, user, device) does not open
	 *
	 * @param int $accountId Mailbox
	 * @param int $userId    User
	 * @param int $deviceId  Device
	 * @return string
	 */
	public static function aad($accountId, $userId, $deviceId)
	{
		global $conf;

		return ((int) $conf->entity).'|'.((int) $accountId).'|'.((int) $userId).'|'.((int) $deviceId).'|'.self::BLOB_VERSION;
	}

	/**
	 * Keep the copy of a mailbox for a device. Checked here, whoever calls:
	 * - the mailbox is protected, the device is active and belongs to the user, who has an access to the mailbox;
	 * - password: for one of his own devices (the password was just tried on the mail server by the caller);
	 * - grant: the author manages the mailbox and has it unlocked in his session;
	 * - approve: for one of his own devices, the mailbox unlocked in his session.
	 *
	 * @param User         $author  User doing it
	 * @param InboxAccount $account Mailbox
	 * @param int          $userId  User of the device
	 * @param int          $deviceId Device
	 * @param mixed        $blob    Copy made by the browser
	 * @param string       $origin  password, grant or approve
	 * @return bool
	 */
	public function storeBlob(User $author, InboxAccount $account, $userId, $deviceId, $blob, $origin)
	{
		$json = self::cleanBlob($blob);
		$device = $this->device($deviceId);
		$access = new InboxAccess($this->db);
		if ($json === null) {
			$this->error = 'InboxProtectErrorBlob';
			return false;
		}
		if (!$account->isProtected() || !$device || $device['status'] !== 1 || $device['fk_user'] !== (int) $userId) {
			$this->error = 'InboxProtectErrorDevice';
			return false;
		}
		$target = new User($this->db);
		if ($target->fetch((int) $userId) <= 0 || !$access->check($target, (int) $account->id, InboxAccess::LEVEL_READ)) {
			$this->error = 'InboxProtectErrorNoAccess';
			return false;
		}
		$own = (int) $userId === (int) $author->id;
		if ($origin === self::ORIGIN_PASSWORD || $origin === self::ORIGIN_APPROVE) {
			if (!$own || ($origin === self::ORIGIN_APPROVE && !self::isUnlocked((int) $account->id))) {
				$this->error = 'InboxProtectErrorNotAllowed';
				return false;
			}
		} elseif ($origin === self::ORIGIN_GRANT) {
			if (!self::isUnlocked((int) $account->id) || (!$access->check($author, (int) $account->id, InboxAccess::LEVEL_MANAGE) && !self::canDecide($author, $account))) {
				$this->error = 'InboxProtectErrorNotAllowed';
				return false;
			}
		} else {
			$this->error = 'InboxProtectErrorNotAllowed';
			return false;
		}
		$prefix = $this->db->prefix();
		$now = $this->db->idate(dol_now());
		$where = " WHERE fk_account = ".((int) $account->id)." AND fk_user = ".((int) $userId)." AND fk_device = ".((int) $deviceId);
		$exists = $this->db->getRow("SELECT rowid FROM ".$prefix."inbox_account_key".$where);
		if (is_object($exists)) {
			$ok = $this->db->query("UPDATE ".$prefix."inbox_account_key SET keydata = '".$this->db->escape($json)."', gen = ".((int) $account->protect_gen).", origin = '".$this->db->escape($origin)."',"
				." fk_user_creat = ".((int) $author->id).", date_creation = '".$now."', date_last_use = NULL".$where);
		} else {
			$ok = $this->db->query("INSERT INTO ".$prefix."inbox_account_key (fk_account, fk_user, fk_device, keydata, gen, origin, fk_user_creat, date_creation)"
				." VALUES (".((int) $account->id).", ".((int) $userId).", ".((int) $deviceId).", '".$this->db->escape($json)."', ".((int) $account->protect_gen).", '".$this->db->escape($origin)."', ".((int) $author->id).", '".$now."')");
		}
		if (!$ok) {
			$this->error = $this->db->lasterror();
			return false;
		}
		if ($origin === self::ORIGIN_APPROVE) {
			$this->db->query("UPDATE ".$prefix."inbox_device SET approve_asked = NULL WHERE rowid = ".((int) $deviceId));
		}
		$log = new InboxAccountLog($this->db);
		$action = array(self::ORIGIN_PASSWORD => self::LOG_ENROL, self::ORIGIN_GRANT => self::LOG_GRANT, self::ORIGIN_APPROVE => self::LOG_APPROVE)[$origin];
		$log->add((int) $account->id, $author, $action, null, array('fk_user' => (int) $userId, 'device' => $device['fingerprint']));

		return true;
	}

	/**
	 * Copy of a mailbox for a device of the user, if it is up to date
	 *
	 * @param User         $user     User
	 * @param InboxAccount $account  Mailbox
	 * @param int          $deviceId Device (his)
	 * @return string|null JSON of the copy
	 */
	public function blobFor(User $user, InboxAccount $account, $deviceId)
	{
		$device = $this->device($deviceId);
		if (!$device || $device['status'] !== 1 || $device['fk_user'] !== (int) $user->id) {
			return null;
		}
		// An access removed through a group (no revoke() of this mailbox): its copy is not given any more
		if (!(new InboxAccess($this->db))->check($user, (int) $account->id, InboxAccess::LEVEL_READ)) {
			$this->pruneBlobs((int) $account->id);
			return null;
		}
		$row = $this->db->getRow("SELECT rowid, keydata FROM ".$this->db->prefix()."inbox_account_key WHERE fk_account = ".((int) $account->id)
			." AND fk_user = ".((int) $user->id)." AND fk_device = ".((int) $deviceId)." AND gen = ".((int) $account->protect_gen));

		return is_object($row) ? (string) $row->keydata : null;
	}

	/**
	 * Note the use of a copy and of the device (after an unlocking that the mail server accepted)
	 *
	 * @param InboxAccount $account  Mailbox
	 * @param int          $userId   User
	 * @param int          $deviceId Device
	 * @return void
	 */
	public function touch(InboxAccount $account, $userId, $deviceId)
	{
		$now = $this->db->idate(dol_now());
		$prefix = $this->db->prefix();
		$this->db->query("UPDATE ".$prefix."inbox_account_key SET date_last_use = '".$now."' WHERE fk_account = ".((int) $account->id)." AND fk_user = ".((int) $userId)." AND fk_device = ".((int) $deviceId));
		$this->db->query("UPDATE ".$prefix."inbox_device SET date_last_use = '".$now."' WHERE rowid = ".((int) $deviceId));
	}

	/**
	 * State of the copies of a mailbox, for its screen « Encrypted accesses »: each user who has an access, his
	 * devices, and for each one: ok, obsolete (older password) or missing
	 *
	 * @param InboxAccount $account Mailbox
	 * @return array<int,array<string,mixed>>
	 */
	public function accessesOf(InboxAccount $account)
	{
		$access = new InboxAccess($this->db);
		$users = $access->usersOf((int) $account->id);
		$devices = $this->devicesOf(array_keys($users));
		$rows = $this->db->getRows("SELECT fk_user, fk_device, gen, origin, date_creation, date_last_use FROM ".$this->db->prefix()."inbox_account_key WHERE fk_account = ".((int) $account->id)." LIMIT 10000");
		$keys = array();
		foreach (is_array($rows) ? $rows : array() as $row) {
			$keys[(int) $row->fk_device] = $row;
		}
		$out = array();
		foreach ($users as $uid => $u) {
			$list = array();
			foreach ($devices[$uid] ?? array() as $d) {
				$k = $keys[$d['id']] ?? null;
				$state = !$k ? 'missing' : ((int) $k->gen === (int) $account->protect_gen ? 'ok' : 'obsolete');
				unset($d['approve_asked']);
				$list[] = $d + array('state' => $state, 'origin' => $k ? (string) $k->origin : '', 'date_key' => $k ? (int) $this->db->jdate($k->date_creation) : null,
					'date_key_use' => ($k && $k->date_last_use) ? (int) $this->db->jdate($k->date_last_use) : null);
			}
			$out[] = $u + array('devices' => $list);
		}

		return $out;
	}

	/**
	 * Delete the copies of the users who lost their access to the mailbox (access removed, group left, user disabled)
	 *
	 * @param int $accountId Mailbox
	 * @return int           Number of copies deleted
	 */
	public function pruneBlobs($accountId)
	{
		$access = new InboxAccess($this->db);
		InboxAccess::clearCache();
		$users = array_keys($access->usersOf((int) $accountId));
		$sql = "DELETE FROM ".$this->db->prefix()."inbox_account_key WHERE fk_account = ".((int) $accountId);
		if ($users) {
			$sql .= " AND fk_user NOT IN (".implode(',', array_map('intval', $users)).")";
		}
		if (!$this->db->query($sql)) {
			return 0;
		}

		return (int) $this->db->affected_rows(true);
	}

	// ---------------------------------------------------------------------------------------------------------------
	// Mode of the mailbox
	// ---------------------------------------------------------------------------------------------------------------

	/**
	 * Try passwords on the mail server (IMAP, then SMTP when it has its own password), without keeping them
	 *
	 * @param InboxAccount         $account Mailbox
	 * @param array<string,string> $secrets imap, smtp
	 * @param string               $code    Code of the refusal: auth, connect
	 * @return string                       Message of the error, '' if accepted
	 */
	public function tryPasswords(InboxAccount $account, array $secrets, &$code = '')
	{
		global $langs;

		require_once __DIR__.'/connector/inboxconnectorfactory.class.php';
		$code = '';
		if ((string) ($secrets['imap'] ?? '') === '') {
			$code = 'auth';
			return $langs->transnoentities('InboxErrorImapNoPassword');
		}
		$wasProtected = $account->protected;
		$account->protected = 1;
		$kept = self::$memory[(int) $account->id] ?? null;
		self::tryWith((int) $account->id, $secrets);
		$error = '';
		$connector = InboxConnectorFactory::forAccount($this->db, $account, $error);
		$message = '';
		if (!$connector) {
			$message = $error;
			$code = 'connect';
		} elseif (!$connector->connect()) {
			$message = $connector->getError();
			$code = method_exists($connector, 'getErrorCode') && $connector->getErrorCode() === 'auth' ? 'auth' : 'connect';
		} else {
			$connector->disconnect();
			if (empty($account->smtp_same_credentials) && method_exists($connector, 'testSmtp') && $account->getSmtpHost() !== '') {
				$smtp = $connector->testSmtp();
				if (($smtp['status'] ?? '') === 'error') {
					$message = $langs->transnoentities('InboxProtectErrorSmtp');
					$code = 'auth';
				}
			}
		}
		$account->protected = $wasProtected;
		if ($kept !== null) {
			self::$memory[(int) $account->id] = $kept;
		} else {
			self::tryWith((int) $account->id, null);
		}

		return $message;
	}

	/**
	 * Protect a mailbox (§5.1): the passwords leave the database, the copy for the device of the user is kept and the
	 * mailbox is unlocked in his session. Level password: the mailbox keeps its index (synchronised by the sessions);
	 * level content: its local data is emptied (irreversible for that data).
	 *
	 * @param User                 $user     User (canDecide, with an access to the mailbox)
	 * @param InboxAccount         $account  Mailbox
	 * @param array<string,string> $secrets  Passwords typed (tried here)
	 * @param int                  $deviceId His device
	 * @param mixed                $blob     Copy for his device
	 * @param string               $level    password or content
	 * @return bool
	 */
	public function protect(User $user, InboxAccount $account, array $secrets, $deviceId, $blob, $level = InboxAccount::PROTECT_PASSWORD)
	{
		$level = $level === InboxAccount::PROTECT_CONTENT ? InboxAccount::PROTECT_CONTENT : InboxAccount::PROTECT_PASSWORD;
		global $langs;

		$access = new InboxAccess($this->db);
		if (!self::canDecide($user, $account)) {
			$this->error = 'InboxProtectErrorNotAllowed';
			return false;
		}
		if ($account->isProtected()) {
			$this->error = 'InboxProtectErrorAlready';
			return false;
		}
		$refusal = self::refusal($account);
		if ($refusal !== '') {
			$this->error = $refusal;
			return false;
		}
		if (!$access->check($user, (int) $account->id, InboxAccess::LEVEL_READ)) {
			$this->error = 'InboxProtectErrorOwnAccess';
			return false;
		}
		// A pass of the synchronisation would write mails again after the emptying
		if ($level === InboxAccount::PROTECT_CONTENT && (int) $account->sync_lock > dol_now()) {
			$this->error = 'InboxProtectErrorSyncRunning';
			return false;
		}
		if (self::cleanBlob($blob) === null) {
			$this->error = 'InboxProtectErrorBlob';
			return false;
		}
		$device = $this->device($deviceId);
		if (!$device || $device['fk_user'] !== (int) $user->id || $device['status'] !== 1) {
			$this->error = 'InboxProtectErrorDevice';
			return false;
		}
		$code = '';
		$message = $this->tryPasswords($account, $secrets, $code);
		if ($message !== '') {
			$this->error = $message;
			return false;
		}

		$prefix = $this->db->prefix();
		$id = (int) $account->id;
		$content = $level === InboxAccount::PROTECT_CONTENT;
		if ($content && ($this->queueWaiting($id) || !$this->dropIndexFirst($id))) {
			return false;
		}
		$this->db->begin();
		$ok = $this->db->query("UPDATE ".$prefix."inbox_account SET protected = 1, protect_level = '".$this->db->escape($level)."', protect_gen = protect_gen + 1, imap_password = NULL, smtp_password = NULL"
			.($content ? ", sync_state = NULL, sync_error = NULL, date_sync = NULL, date_folders = NULL, sync_lock = NULL, sync_retry_at = NULL, sync_fail_count = 0" : ", sync_retry_at = NULL, sync_fail_count = 0")
			." WHERE rowid = ".$id);
		$ok = $ok && (!$content || $this->purgeLocal($id));
		// Modules subscribed (1.112.0): nothing can run in the background on a mailbox protected with its content
		$ok = $ok && (!$content || (new InboxConsumer($this->db))->suspendForProtection($user, $id));
		if ($ok) {
			$account->protected = 1;
			$account->protect_level = $level;
			$account->protect_gen++;
			$account->imap_password = null;
			$account->smtp_password = null;
			$ok = $this->db->query("DELETE FROM ".$prefix."inbox_account_key WHERE fk_account = ".$id) && $this->storeBlob($user, $account, (int) $user->id, (int) $deviceId, $blob, self::ORIGIN_PASSWORD);
		}
		if (!$ok) {
			$this->error = $this->error ?: $this->db->lasterror();
			$this->db->rollback();
			$account->protected = 0;
			return false;
		}
		$log = new InboxAccountLog($this->db);
		$log->add($id, $user, self::LOG_PROTECT, null, array('protected' => 1, 'level' => $level));
		$this->db->commit();
		self::unlock($id, $secrets, (int) $deviceId);
		$langs->load('inbox@inbox');

		return true;
	}

	/**
	 * Empty the local data of a mailbox (§4.4): mails and their addresses, raw cache, index, folders, sendings waiting,
	 * delivery reports, marks of conversations, notifications, journal of the readings. The references of the mails
	 * (subject, sender) with their links, notes and assignations go when no other mailbox holds the same mail.
	 *
	 * @param int $accountId Mailbox
	 * @return bool
	 */
	public function purgeLocal($accountId)
	{
		global $conf;

		$p = $this->db->prefix();
		$id = (int) $accountId;
		$email = $this->db->getRow("SELECT email FROM ".$p."inbox_account WHERE rowid = ".$id);
		$email = is_object($email) ? (string) $email->email : '';
		$mails = "SELECT rowid FROM ".$p."inbox_mail WHERE fk_account = ".$id;
		// Message-ID of this mailbox only (a mail also in another mailbox keeps its reference there)
		$own = "SELECT m.message_id FROM ".$p."inbox_mail as m WHERE m.fk_account = ".$id." AND m.message_id IS NOT NULL"
			." AND NOT EXISTS (SELECT 1 FROM ".$p."inbox_mail as o WHERE o.message_id = m.message_id AND o.fk_account <> ".$id.")";
		$refs = "SELECT r.rowid FROM ".$p."inbox_mailref as r WHERE r.entity = ".((int) $conf->entity)." AND r.message_id IN (".$own.")";
		$sqls = array();
		foreach (array('inbox_link', 'inbox_note', 'inbox_mail_assign') as $table) {
			$sqls[] = "DELETE FROM ".$p.$table." WHERE fk_mailref IN (SELECT x.rowid FROM (".$refs.") as x)";
		}
		$sqls[] = "DELETE FROM ".$p."inbox_notification WHERE fk_account = ".$id." OR fk_mailref IN (SELECT x.rowid FROM (".$refs.") as x)";
		$sqls[] = "DELETE FROM ".$p."inbox_mailref WHERE rowid IN (SELECT x.rowid FROM (".$refs.") as x)";
		// What the files keep of the mailbox (audit INBX-062): sources of the cache (unless another mailbox uses the
		// same file), files of the queue; a rollback leaves lines without file, the cache job removes them
		require_once __DIR__.'/inbox.class.php';
		require_once __DIR__.'/inboxoutbox.class.php';
		if ($email !== '') {
			$cache = Inbox::storageDir();
			$rows = $this->db->getRows("SELECT filepath FROM ".$p."inbox_message as c WHERE c.mailbox = '".$this->db->escape($email)."'"
				." AND NOT EXISTS (SELECT 1 FROM ".$p."inbox_message as o WHERE o.filepath = c.filepath AND o.mailbox <> c.mailbox) LIMIT 100000");
			foreach (is_array($rows) ? $rows : array() as $r) {
				$file = basename((string) $r->filepath);
				if ($file !== '' && is_file($cache.'/'.$file)) {
					@unlink($cache.'/'.$file);
				}
			}
		}
		$rows = $this->db->getRows("SELECT rowid FROM ".$p."inbox_outbox WHERE fk_account = ".$id." LIMIT 100000");
		foreach (is_array($rows) ? $rows : array() as $r) {
			foreach (array('raw', 'wire') as $kind) {
				$file = InboxOutbox::dir().'/'.((int) $r->rowid).'.'.$kind.'.eml';
				if (is_file($file)) {
					@unlink($file);
				}
			}
		}
		$sqls[] = "DELETE FROM ".$p."inbox_action_log WHERE fk_account = ".$id." OR fk_account_to = ".$id;
		$sqls[] = "DELETE FROM ".$p."inbox_mail_address WHERE fk_mail IN (".$mails.")";
		$sqls[] = "DELETE FROM ".$p."inbox_spam_learn WHERE fk_mail IN (".$mails.")";
		foreach (array('inbox_mail', 'inbox_fulltext', 'inbox_folder', 'inbox_outbox', 'inbox_bounce', 'inbox_thread_mark') as $table) {
			$sqls[] = "DELETE FROM ".$p.$table." WHERE fk_account = ".$id;
		}
		if ($email !== '') {
			foreach (array('inbox_message', 'inbox_readlog') as $table) {
				$sqls[] = "DELETE FROM ".$p.$table." WHERE mailbox = '".$this->db->escape($email)."'";
			}
		}
		foreach ($sqls as $sql) {
			if (!$this->db->query($sql)) {
				$this->error = $this->db->lasterror();
				return false;
			}
		}

		return true;
	}

	/**
	 * Back to the normal mode (§5.5): the password of the session goes back to the database (dolEncrypt), the copies
	 * are deleted, the synchronisation starts again from zero
	 *
	 * @param User         $user    User (canDecide, mailbox unlocked)
	 * @param InboxAccount $account Mailbox
	 * @return bool
	 */
	public function unprotect(User $user, InboxAccount $account)
	{
		if (!self::canDecide($user, $account) || !$account->isProtected()) {
			$this->error = 'InboxProtectErrorNotAllowed';
			return false;
		}
		$secrets = self::secrets((int) $account->id);
		if (!is_array($secrets)) {
			$this->error = 'InboxProtectErrorLocked';
			return false;
		}
		$prefix = $this->db->prefix();
		$id = (int) $account->id;
		$imap = dolEncrypt((string) $secrets['imap']);
		$smtp = (empty($account->smtp_same_credentials) && (string) ($secrets['smtp'] ?? '') !== '') ? dolEncrypt((string) $secrets['smtp']) : null;
		$this->db->begin();
		$ok = $this->db->query("UPDATE ".$prefix."inbox_account SET protected = 0, imap_password = '".$this->db->escape($imap)."', smtp_password = ".($smtp === null ? 'NULL' : "'".$this->db->escape($smtp)."'")
			.($account->isLive() ? ", sync_state = NULL, date_sync = NULL" : '').", protect_level = 'password' WHERE rowid = ".$id)
			&& $this->db->query("DELETE FROM ".$prefix."inbox_account_key WHERE fk_account = ".$id);
		if (!$ok) {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			return false;
		}
		(new InboxAccountLog($this->db))->add($id, $user, self::LOG_UNPROTECT, array('protected' => 1), array('protected' => 0));
		$this->db->commit();
		self::lock($id);
		$account->protected = 0;

		return true;
	}

	/**
	 * Change the level of a protected mailbox: to content, its local data is emptied (as at the protection); to
	 * password, its index is built again by the next sessions. Who decides the mode only.
	 *
	 * @param User         $user    User (canDecide)
	 * @param InboxAccount $account Mailbox
	 * @param string       $level   password or content
	 * @return bool
	 */
	public function setLevel(User $user, InboxAccount $account, $level)
	{
		$level = $level === InboxAccount::PROTECT_CONTENT ? InboxAccount::PROTECT_CONTENT : InboxAccount::PROTECT_PASSWORD;
		if (!self::canDecide($user, $account) || !$account->isProtected()) {
			$this->error = 'InboxProtectErrorNotAllowed';
			return false;
		}
		if ($account->protect_level === $level) {
			return true;
		}
		$prefix = $this->db->prefix();
		$id = (int) $account->id;
		$content = $level === InboxAccount::PROTECT_CONTENT;
		// Back to « password », the mails are kept again by the pages of its users: decided from a session that has
		// the password, as the return to the normal mode (audit INBX-055)
		if (!$content && !self::isUnlocked($id)) {
			$this->error = 'InboxProtectErrorLocked';
			return false;
		}
		if ($content && (int) $account->sync_lock > dol_now()) {
			$this->error = 'InboxProtectErrorSyncRunning';
			return false;
		}
		if ($content && ($this->queueWaiting($id) || !$this->dropIndexFirst($id))) {
			return false;
		}
		$this->db->begin();
		$ok = $this->db->query("UPDATE ".$prefix."inbox_account SET protect_level = '".$this->db->escape($level)."', sync_state = NULL, date_sync = NULL, date_folders = NULL WHERE rowid = ".$id)
			&& (!$content || $this->purgeLocal($id))
			&& (!$content || (new InboxConsumer($this->db))->suspendForProtection($user, $id));
		if (!$ok) {
			$this->error = $this->error ?: $this->db->lasterror();
			$this->db->rollback();
			return false;
		}
		(new InboxAccountLog($this->db))->add($id, $user, self::LOG_LEVEL, array('level' => $account->protect_level), array('level' => $level));
		$this->db->commit();
		$account->protect_level = $level;

		return true;
	}

	/**
	 * Reset (§5.5): every copy is deleted, the mailbox stays protected and cannot be opened until someone types its
	 * password again. The way out when every device is lost.
	 *
	 * @param User         $user    User (canDecide)
	 * @param InboxAccount $account Mailbox
	 * @return bool
	 */
	public function reset(User $user, InboxAccount $account)
	{
		if (!self::canDecide($user, $account) || !$account->isProtected()) {
			$this->error = 'InboxProtectErrorNotAllowed';
			return false;
		}
		$prefix = $this->db->prefix();
		$id = (int) $account->id;
		$this->db->begin();
		$ok = $this->db->query("DELETE FROM ".$prefix."inbox_account_key WHERE fk_account = ".$id)
			&& $this->db->query("UPDATE ".$prefix."inbox_account SET protect_gen = protect_gen + 1 WHERE rowid = ".$id);
		if (!$ok) {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			return false;
		}
		(new InboxAccountLog($this->db))->add($id, $user, self::LOG_RESET, null, null);
		$this->db->commit();
		$account->protect_gen++;
		self::lock($id);

		return true;
	}

	/**
	 * The password was typed again after the mail server refused the one of a copy (changed at the host): the copies
	 * of the older password become obsolete, each user types it again or receives a new copy (§5.2)
	 *
	 * @param InboxAccount $account Mailbox
	 * @return bool
	 */
	public function newGeneration(InboxAccount $account)
	{
		if (!$this->db->query("UPDATE ".$this->db->prefix()."inbox_account SET protect_gen = protect_gen + 1 WHERE rowid = ".((int) $account->id))) {
			return false;
		}
		$account->protect_gen++;

		return true;
	}

	/**
	 * Ids of the protected mailboxes of the entity (the scheduled jobs leave them aside)
	 *
	 * @return int[]
	 */
	public function protectedIds()
	{
		global $conf;

		$rows = $this->db->getRows("SELECT rowid FROM ".$this->db->prefix()."inbox_account WHERE entity = ".((int) $conf->entity)." AND protected = 1 LIMIT 10000");

		return array_map(function ($r) {
			return (int) $r->rowid;
		}, is_array($rows) ? $rows : array());
	}

	/**
	 * Protected mailboxes not unlocked in this session: what the server keeps of them (index, cache of the mails,
	 * search) is never read for them, an access given without a copy of the password opens nothing (audit INBX-055)
	 *
	 * @return int[]
	 */
	public function lockedIds()
	{
		return array_values(array_filter($this->protectedIds(), function ($id) {
			return !self::isUnlocked($id);
		}));
	}

	/**
	 * Mails of the mailbox waiting in the queue: the level content empties the queue, they would be lost without a
	 * word (audit INBX-062)
	 *
	 * @param int $accountId Mailbox
	 * @return bool Some are waiting ($this->error set)
	 */
	protected function queueWaiting($accountId)
	{
		require_once __DIR__.'/inboxoutbox.class.php';
		if (is_object($this->db->getRow("SELECT rowid FROM ".$this->db->prefix()."inbox_outbox WHERE fk_account = ".((int) $accountId)." AND status = ".InboxOutbox::WAITING))) {
			$this->error = 'InboxProtectErrorOutbox';
			return true;
		}

		return false;
	}

	/**
	 * The index of a mailbox removed from Meilisearch before its local data is emptied: once the state of the index
	 * is gone, nothing can find the index again (audit INBX-054)
	 *
	 * @param int $accountId Mailbox
	 * @return bool Removed, or nothing to remove
	 */
	protected function dropIndexFirst($accountId)
	{
		require_once __DIR__.'/inboxfulltext.class.php';
		$fulltext = new InboxFulltext($this->db);
		if ($fulltext->dropIndex((int) $accountId)) {
			return true;
		}
		dol_syslog(__METHOD__.' '.$fulltext->error, LOG_WARNING);
		$this->error = 'InboxProtectErrorIndex';

		return false;
	}
}
