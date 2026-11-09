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
 * \file    inbox/class/inboxthunderbirdqr.class.php
 * \ingroup inbox
 * \brief   Reading of the QR codes « Export for mobile » of Thunderbird (format version 1)
 */

require_once __DIR__.'/inboxaccount.class.php';
require_once __DIR__.'/inboxservertester.class.php';
require_once __DIR__.'/inboxidentity.class.php';
require_once __DIR__.'/inboxaccess.class.php';
require_once __DIR__.'/inboxmeili.class.php';

/**
 * Public format of Thunderbird for Android, version 1 (2025-02-06): JSON made of nested arrays
 *   [FormatVersion, [SequenceNumber, SequenceEnd], IncomingServer, OutgoingServerGroups, IncomingServer, ...]
 *   IncomingServer       [protocol (0 IMAP, 1 POP3), host, port, security, auth, username, accountName?, password?]
 *   OutgoingServerGroups [[OutgoingServer, Identity, Identity...], ...]
 *   OutgoingServer       [0 (SMTP), host, port, security, auth, username, password?]
 *   Identity             [email, displayName]
 *   security 0 none, 2 STARTTLS, 3 TLS; auth 0 none, 1 and 2 password, 3 GSSAPI, 4 NTLM, 5 certificate, 6 OAuth2
 * Every value is checked, even the ones inbox does not use; an account with a value inbox cannot take is skipped with
 * its reason, never guessed. A wrong structure refuses the whole code. Passwords stay in clear in the result: the
 * caller keeps them encrypted and never sends them back to the browser.
 */
class InboxThunderbirdQr
{
	/** Life of a reading kept in session (seconds) */
	const TTL = 900;

	/** Bounds of one reading */
	const MAX_CODES = 50;
	const MAX_ACCOUNTS = 200;
	const MAX_BYTES = 65536;

	/** Reasons for an account not imported */
	const SKIP_POP3 = 'pop3';
	const SKIP_AUTH = 'auth';
	const SKIP_UNSUPPORTED = 'unsupported';
	const SKIP_HOST = 'host';
	const SKIP_ENCRYPTION = 'encryption';
	const SKIP_IDENTITY = 'identity';

	/** @var array<int,string> security of the format => encryption of inbox */
	const SECURITY = array(0 => 'none', 2 => 'tls', 3 => 'ssl');

	/** @var string Code of the last error: invalid, version, sequence, limit */
	public $error = '';

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
	 * Read one code
	 *
	 * @param string $text Text of the QR code
	 * @return array{number:int,end:int,accounts:array<int,array<string,mixed>>}|null Null if refused ($this->error)
	 */
	public function parseCode($text)
	{
		$this->error = '';
		$text = (string) $text;
		if ($text === '' || strlen($text) > self::MAX_BYTES) {
			return $this->fail('limit');
		}
		$data = json_decode($text, true, 16);
		if (!is_array($data) || !self::isList($data) || count($data) < 2) {
			return $this->fail('invalid');
		}
		if ($data[0] !== 1) {
			return $this->fail('version');
		}
		$seq = $data[1];
		if (!is_array($seq) || !self::isList($seq) || count($seq) !== 2 || !is_int($seq[0]) || !is_int($seq[1])
			|| $seq[0] < 1 || $seq[1] < $seq[0] || $seq[1] > self::MAX_CODES) {
			return $this->fail('sequence');
		}
		$rest = array_slice($data, 2);
		if (count($rest) % 2 !== 0) {
			return $this->fail('invalid');
		}
		$accounts = array();
		for ($i = 0; $i < count($rest); $i += 2) {
			$account = $this->parseAccount($rest[$i], $rest[$i + 1]);
			if ($account === null) {
				return $this->fail('invalid');
			}
			$accounts[] = $account;
		}

		return array('number' => $seq[0], 'end' => $seq[1], 'accounts' => $accounts);
	}

	/**
	 * Read the codes of one export, in any order; a code read twice counts once
	 *
	 * @param string[] $texts Texts of the QR codes
	 * @return array{end:int,read:int[],complete:bool,accounts:array<int,array<string,mixed>>}|null Null if refused
	 */
	public function parseAll(array $texts)
	{
		$this->error = '';
		if (!$texts || count($texts) > self::MAX_CODES) {
			return $this->fail('limit');
		}
		$codes = array();
		$end = 0;
		foreach ($texts as $text) {
			if (!is_string($text)) {
				return $this->fail('invalid');
			}
			$code = $this->parseCode($text);
			if ($code === null) {
				return null;
			}
			// All the codes of one export say the same end
			if ($end && $code['end'] !== $end) {
				return $this->fail('sequence');
			}
			$end = $code['end'];
			$codes[$code['number']] = $code['accounts'];
		}
		ksort($codes);
		$accounts = array();
		foreach ($codes as $list) {
			foreach ($list as $account) {
				$accounts[] = $account;
			}
		}
		if (count($accounts) > self::MAX_ACCOUNTS) {
			return $this->fail('limit');
		}

		return array('end' => $end, 'read' => array_keys($codes), 'complete' => count($codes) === $end, 'accounts' => $accounts);
	}

	/**
	 * Keep a reading in session, encrypted, for the import that follows (15 minutes, 5 readings at most)
	 *
	 * @param array<int,array<string,mixed>> $accounts Accounts read (with their passwords)
	 * @return string Token of the reading
	 */
	public function keep(array $accounts)
	{
		$list = self::readings();
		if (count($list) >= 5) {
			uasort($list, function ($a, $b) {
				return $a['t'] - $b['t'];
			});
			$list = array_slice($list, -4, null, true);
		}
		$token = bin2hex(random_bytes(16));
		$list[$token] = array('t' => dol_now(), 'data' => dolEncrypt((string) json_encode($accounts)));
		$_SESSION['inbox_qr'] = $list;

		return $token;
	}

	/**
	 * Take a reading out of the session (used once)
	 *
	 * @param string $token Token of the reading
	 * @return array<int,array<string,mixed>>|null Null if unknown or expired
	 */
	public function take($token)
	{
		$list = self::readings();
		if ($token === '' || !isset($list[$token])) {
			return null;
		}
		unset($_SESSION['inbox_qr'][$token]);
		$accounts = json_decode((string) dolDecrypt((string) $list[$token]['data']), true);

		return is_array($accounts) ? $accounts : null;
	}

	/**
	 * @return array<string,array{t:int,data:string}> Readings still valid (the expired ones removed)
	 */
	protected static function readings()
	{
		$list = isset($_SESSION['inbox_qr']) && is_array($_SESSION['inbox_qr']) ? $_SESSION['inbox_qr'] : array();
		foreach ($list as $token => $row) {
			if (!is_array($row) || !isset($row['t']) || (int) $row['t'] < dol_now() - self::TTL) {
				unset($list[$token]);
			}
		}
		$_SESSION['inbox_qr'] = $list;

		return $list;
	}

	/**
	 * Accounts for the browser: never a password, only if one is given; mailbox already there; proposed owner
	 *
	 * @param array<int,array<string,mixed>> $accounts Accounts read
	 * @return array<int,array<string,mixed>>
	 */
	public function forClient(array $accounts)
	{
		$out = array();
		foreach ($accounts as $i => $a) {
			$existing = new InboxAccount($this->db);
			$out[] = array(
				'index' => $i,
				'email' => $a['email'],
				'label' => $a['label'],
				'imap_host' => $a['imap_host'],
				'smtp_host' => $a['smtp_host'],
				'identities' => count($a['identities']),
				'password' => $a['imap_password'] !== '',
				'oauth' => $a['oauth'],
				'skip' => $a['skip'],
				'exists' => $a['email'] !== '' && $existing->fetchByEmail($a['email']) > 0,
				'owner' => ($a['email'] !== '' && $a['skip'] === '') ? $this->userOf($a['email']) : null,
			);
		}

		return $out;
	}

	/**
	 * Active internal user of the entity with this address (proposed owner of a personal mailbox)
	 *
	 * @param string $email Address
	 * @return array{id:int,name:string}|null
	 */
	public function userOf($email)
	{
		global $conf, $langs;

		$obj = $this->db->getRow("SELECT rowid FROM ".$this->db->prefix()."user WHERE LOWER(email) = '".$this->db->escape(strtolower($email))."'"
			." AND entity IN (0, ".((int) $conf->entity).") AND statut = 1 AND (fk_soc IS NULL OR fk_soc = 0) ORDER BY rowid");
		if (!is_object($obj)) {
			return null;
		}
		$u = new User($this->db);
		if ($u->fetch((int) $obj->rowid) <= 0) {
			return null;
		}

		return array('id' => (int) $u->id, 'name' => $u->getFullName($langs));
	}

	/**
	 * Create the mailbox of one account read: identities, Manage access to the importer if asked, test then enabled
	 * when it passes. Without password or with OAuth, the mailbox stays inactive (draft) to be completed.
	 *
	 * @param array<string,mixed>     $a          Account read (parseCode)
	 * @param string                  $type       shared or personal
	 * @param int                     $ownerId    Owner of a personal mailbox
	 * @param bool                    $giveManage Give the Manage access to $user
	 * @param User                    $user       Importer (admin of the mailboxes: checked by the caller)
	 * @param InboxServerTester|null  $tester     Test of the new mailbox, null for none
	 * @return array{email:string,id:int,state:string,message:string}|null Null for an account inbox cannot take
	 */
	public function importAccount(array $a, $type, $ownerId, $giveManage, User $user, $tester = null)
	{
		global $conf;

		if (!isset($a['skip'], $a['email']) || $a['skip'] !== '' || $a['email'] === '') {
			return null;
		}
		$row = array('email' => (string) $a['email'], 'id' => 0, 'state' => 'error', 'message' => '');
		$account = new InboxAccount($this->db);
		if ($account->fetchByEmail($a['email']) > 0) {
			$row['state'] = 'exists';
			return $row;
		}
		$account = new InboxAccount($this->db);
		$account->email = $a['email'];
		$account->label = $a['label'];
		$account->connector = 'imap';
		$account->type = InboxAccount::TYPE_SHARED;
		if ($type === InboxAccount::TYPE_PERSONAL && $ownerId > 0) {
			$owner = new User($this->db);
			if ($owner->fetch($ownerId) > 0 && (int) $owner->statut === 1 && empty($owner->socid) && in_array((int) $owner->entity, array(0, (int) $conf->entity), true)) {
				$account->type = InboxAccount::TYPE_PERSONAL;
				$account->fk_user_owner = (int) $owner->id;
			}
		}
		$account->imap_host = $a['imap_host'];
		$account->imap_port = (int) $a['imap_port'];
		$account->imap_encryption = $a['imap_encryption'];
		$account->imap_auth_mode = InboxAccount::AUTH_LOGIN;
		$account->imap_login = $a['imap_login'];
		$account->smtp_host = $a['smtp_host'];
		$account->smtp_port = (int) $a['smtp_port'] > 0 ? (int) $a['smtp_port'] : 465;
		$account->smtp_encryption = $a['smtp_encryption'] !== '' ? $a['smtp_encryption'] : 'ssl';
		$account->smtp_same_credentials = (int) $a['smtp_same_credentials'];
		$account->smtp_login = $a['smtp_login'];
		// A password of the code only goes to the server read in the same code (audit INBX-002)
		if ($a['imap_password'] !== '') {
			$account->setImapPassword($a['imap_password']);
		}
		if ($a['smtp_password'] !== '') {
			$account->setSmtpPassword($a['smtp_password']);
		}
		$account->status = InboxAccount::STATUS_DRAFT;
		$account->import_key = 'tbqr';
		$id = $account->create($user);
		if ($id <= 0) {
			$row['message'] = implode(', ', $account->errors ? $account->errors : array($account->error));
			return $row;
		}
		$row['id'] = (int) $id;
		foreach ($a['identities'] as $pos => $ident) {
			$identity = new InboxIdentity($this->db);
			$identity->fk_account = (int) $id;
			$identity->email = $ident['email'];
			$identity->name = $ident['name'];
			$identity->position = (int) $pos;
			$identity->create($user);
		}
		// The owner of a personal mailbox manages it already
		if ($giveManage && !($account->type === InboxAccount::TYPE_PERSONAL && (int) $account->fk_user_owner === (int) $user->id)) {
			$access = new InboxAccess($this->db);
			$access->grant($user, (int) $id, (int) $user->id, 0, InboxAccess::LEVEL_MANAGE);
		}
		if ($a['oauth']) {
			$row['state'] = 'oauth';
		} elseif ($a['imap_password'] === '') {
			$row['state'] = 'password';
		} else {
			$row['state'] = 'test';
			if ($tester instanceof InboxServerTester) {
				// Tested at once; enabled when the test passes (its first indexing then starts with the scheduled job)
				$tester->withDetail = !empty($user->admin) || !InboxMeili::isLocal('imap://'.trim((string) $account->imap_host, '[]'));
				$test = $tester->runAndSave($account, $user);
				if (in_array($test['status'], array(InboxServerTester::OK, InboxServerTester::WARNING), true)) {
					$account->fetch($id);
					$account->status = InboxAccount::STATUS_ACTIVE;
					if ($account->update($user) > 0) {
						$row['state'] = 'active';
					}
				} else {
					foreach ($test['steps'] as $step) {
						if ($step['status'] === InboxServerTester::ERROR) {
							$row['message'] = $step['message'];
							break;
						}
					}
				}
			}
		}

		return $row;
	}

	/**
	 * One account of the format, as values of an inbox mailbox
	 *
	 * @param mixed $in     IncomingServer
	 * @param mixed $groups OutgoingServerGroups
	 * @return array<string,mixed>|null Null if the structure is wrong
	 */
	protected function parseAccount($in, $groups)
	{
		if (!is_array($in) || !self::isList($in) || count($in) < 6 || count($in) > 8) {
			return null;
		}
		$name = array_key_exists(6, $in) ? $in[6] : null;
		$password = array_key_exists(7, $in) ? $in[7] : null;
		if (!is_int($in[0]) || !self::isServer($in[1], $in[2], $in[3], $in[4], $in[5]) || !self::isOptionalText($name, 255)
			|| !self::isOptionalText($password, 1024)) {
			return null;
		}
		if (!is_array($groups) || !self::isList($groups) || count($groups) > 100) {
			return null;
		}
		$smtp = null;
		$identities = array();
		foreach ($groups as $group) {
			if (!is_array($group) || !self::isList($group) || count($group) < 1) {
				return null;
			}
			$out = $group[0];
			if (!is_array($out) || !self::isList($out) || count($out) < 6 || count($out) > 7) {
				return null;
			}
			$outPassword = array_key_exists(6, $out) ? $out[6] : null;
			if (!is_int($out[0]) || !self::isServer($out[1], $out[2], $out[3], $out[4], $out[5]) || !self::isOptionalText($outPassword, 1024)) {
				return null;
			}
			if ($smtp === null) {
				$smtp = array('protocol' => $out[0], 'host' => strtolower($out[1]), 'port' => $out[2], 'security' => $out[3], 'auth' => $out[4],
					'username' => $out[5], 'password' => (string) $outPassword);
			}
			foreach (array_slice($group, 1) as $identity) {
				if (!is_array($identity) || !self::isList($identity) || count($identity) !== 2 || !is_string($identity[0]) || !is_string($identity[1])
					|| strlen($identity[0]) > 255 || strlen($identity[1]) > 255) {
					return null;
				}
				$email = strtolower(trim($identity[0]));
				if (!isValidEmail($email)) {
					return null;
				}
				if (!isset($identities[$email])) {
					$identities[$email] = array('email' => $email, 'name' => trim(str_replace(array("\r", "\n", "\t"), ' ', $identity[1])));
				}
			}
		}
		$identities = array_values($identities);
		$email = $identities ? $identities[0]['email'] : '';
		$login = (string) $in[5];
		$account = array(
			'email' => $email,
			'label' => trim((string) $name) !== '' ? trim(str_replace(array("\r", "\n", "\t"), ' ', (string) $name)) : $email,
			'imap_host' => strtolower($in[1]),
			'imap_port' => $in[2],
			'imap_encryption' => self::SECURITY[$in[3]] ?? '',
			'imap_login' => strtolower($login) === $email ? '' : $login,
			'imap_password' => (string) $password,
			'oauth' => $in[4] === 6 || ($smtp !== null && $smtp['auth'] === 6),
			'smtp_host' => '',
			'smtp_port' => 0,
			'smtp_encryption' => '',
			'smtp_login' => '',
			'smtp_password' => '',
			'smtp_same_credentials' => 1,
			'identities' => $identities,
			'skip' => '',
		);
		if ($smtp !== null) {
			$account['smtp_host'] = $smtp['host'];
			$account['smtp_port'] = $smtp['port'];
			$account['smtp_encryption'] = self::SECURITY[$smtp['security']] ?? '';
			// Same credentials: same login, and the same password or none given for the sending
			$same = $smtp['username'] === $login && ($smtp['password'] === '' || $smtp['password'] === (string) $password);
			$account['smtp_same_credentials'] = $same ? 1 : 0;
			if (!$same) {
				$account['smtp_login'] = strtolower($smtp['username']) === $email ? '' : $smtp['username'];
				$account['smtp_password'] = $smtp['password'];
			}
		}
		$account['skip'] = $this->skipReason($in, $smtp, $email);
		if ($account['oauth']) {
			// The OAuth connection is set by the administrator of Dolibarr: no password kept
			$account['imap_password'] = '';
			$account['smtp_password'] = '';
		}

		return $account;
	}

	/**
	 * @param array<int,mixed>         $in    IncomingServer (structure checked)
	 * @param array<string,mixed>|null $smtp  First outgoing server (structure checked)
	 * @param string                   $email Address of the account
	 * @return string Reason of the skip, '' when inbox can take the account
	 */
	protected function skipReason(array $in, $smtp, $email)
	{
		if ($in[0] === 1) {
			return self::SKIP_POP3;
		}
		if ($in[0] !== 0 || !isset(self::SECURITY[$in[3]]) || $in[4] < 0 || $in[4] > 6
			|| ($smtp !== null && ($smtp['protocol'] !== 0 || !isset(self::SECURITY[$smtp['security']]) || $smtp['auth'] < 0 || $smtp['auth'] > 6))) {
			return self::SKIP_UNSUPPORTED;
		}
		if (!in_array($in[4], array(1, 2, 6), true) || ($smtp !== null && !in_array($smtp['auth'], array(1, 2, 6), true))) {
			return self::SKIP_AUTH;
		}
		if ($email === '') {
			return self::SKIP_IDENTITY;
		}
		$hosts = array(strtolower($in[1]));
		if ($smtp !== null) {
			$hosts[] = $smtp['host'];
		}
		foreach ($hosts as $host) {
			// Allowed by the setup; a server of the private network, new: by the administrator (audit INBX-010)
			if (!InboxServerTester::hostAllowed($host) || InboxServerTester::privateRefused($host, InboxServerTester::knownMailHosts())) {
				return self::SKIP_HOST;
			}
		}
		// Password in clear on the network: only for a server on the same machine (as a mailbox made by hand)
		if (($in[3] === 0 && !InboxAccount::isLocalHost($in[1])) || ($smtp !== null && $smtp['security'] === 0 && !InboxAccount::isLocalHost($smtp['host']))) {
			return self::SKIP_ENCRYPTION;
		}

		return '';
	}

	/**
	 * @param mixed $host     Host
	 * @param mixed $port     Port
	 * @param mixed $security Security
	 * @param mixed $auth     Authentication
	 * @param mixed $username User name
	 * @return bool Types and ranges of a server of the format
	 */
	protected static function isServer($host, $port, $security, $auth, $username)
	{
		return is_string($host) && $host !== '' && strlen($host) <= 253 && InboxAccount::isValidHost($host)
			&& is_int($port) && $port >= 1 && $port <= 65535
			&& is_int($security) && is_int($auth)
			&& is_string($username) && strlen($username) <= 255 && !preg_match('/[\x00-\x1f\x7f]/', $username);
	}

	/**
	 * @param mixed $value Value
	 * @param int   $max   Maximum length
	 * @return bool Absent (null) or a text without control character
	 */
	protected static function isOptionalText($value, $max)
	{
		return $value === null || (is_string($value) && strlen($value) <= $max && !preg_match('/[\x00-\x08\x0a-\x1f\x7f]/', $value));
	}

	/**
	 * @param array<mixed> $a Array
	 * @return bool JSON array, not an object
	 */
	protected static function isList(array $a)
	{
		return $a === array() || array_keys($a) === range(0, count($a) - 1);
	}

	/**
	 * @param string $code Error code
	 * @return null
	 */
	protected function fail($code)
	{
		$this->error = $code;

		return null;
	}
}
