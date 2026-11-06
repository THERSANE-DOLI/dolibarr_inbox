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
 * \file    inbox/class/connector/inboximapconnector.class.php
 * \ingroup inbox
 * \brief   Connector IMAP + SMTP (+ Sieve) of inbox
 */

require_once __DIR__.'/inboxconnector.class.php';
require_once dirname(__DIR__).'/inboxoauth.class.php';
require_once dirname(__DIR__).'/inboxmimeparser.class.php';

/**
 * IMAP connector, through the Webklex php-imap library shipped with Dolibarr (2.7.2, same from Dolibarr 18 to 24).
 *
 * Read only, always, in this lot: folders opened with EXAMINE (read-only select), mails read with BODY.PEEK sent as
 * a raw command. The leaveUnread() of Webklex 2.7 is not used: it fetches with RFC822 (which sets \Seen) and then
 * removes the flag, that is a write on the mailbox at every reading.
 *
 * Identifiers: remote_id = "uidvalidity:uid" (opaque for the rest of inbox).
 */
class InboxImapConnector extends InboxConnector
{
	/** Folders searched at most by findByMessageId() */
	const MAX_FOLDERS = 300;

	/** Seconds spent searching the folders at most */
	const TIME_BUDGET = 25;

	/** @var \Webklex\PHPIMAP\Client|null */
	protected $client = null;

	/** @var string[] Capabilities of the server (upper case), after connect() */
	protected $serverCapabilities = array();

	/** @var string Folder opened with EXAMINE */
	protected $examined = '';

	/** @var int Uidvalidity of the examined folder */
	protected $examinedValidity = 0;

	/**
	 * @return string
	 */
	public static function getCode()
	{
		return 'imap';
	}

	/**
	 * Lot W1: reading only. The write capabilities come with the lots that write (W4, W6, W8), each one checked
	 * against the capabilities of the server.
	 *
	 * @return string[]
	 */
	protected function supportedCapabilities()
	{
		$caps = array(self::CAP_READ, self::CAP_FOLDERS, self::CAP_SERVER_SEARCH);
		$server = $this->serverCapabilities;
		if (!$server) {
			$info = $this->account->getServerInfo();
			$server = isset($info['capabilities']) && is_array($info['capabilities']) ? $info['capabilities'] : array();
		}
		if (in_array('SPECIAL-USE', $server, true)) {
			$caps[] = self::CAP_SPECIAL_USE;
		}

		return $caps;
	}

	/**
	 * @return bool
	 */
	public function connect()
	{
		global $langs;

		if ($this->client !== null) {
			return true;
		}
		$langs->load('inbox@inbox');
		$this->error = '';
		$account = $this->account;
		if (trim((string) $account->imap_host) === '') {
			$this->error = $langs->transnoentities('InboxErrorImapNoServer');
			return false;
		}
		$login = $this->getLogin();
		$password = $this->getSecret();
		if ($password === '') {
			if ($this->error === '') {
				$this->error = $langs->transnoentities($account->imap_auth_mode === InboxAccount::AUTH_MASTER ? 'InboxErrorImapNoMaster' : 'InboxErrorImapNoPassword');
			}
			return false;
		}
		require_once DOL_DOCUMENT_ROOT.'/includes/webklex/php-imap/vendor/autoload.php';

		// Socket errors (refused connection, rejected certificate) are PHP warnings, the exception only says
		// "connection failed": keep them for the message instead of printing them
		$warnings = array();
		set_error_handler(function ($errno, $errstr) use (&$warnings) {
			$warnings[] = preg_replace('/^[a-z_]+\(\):\s*/', '', $errstr);
			return true;
		}, E_WARNING | E_NOTICE);
		try {
			$cm = new \Webklex\PHPIMAP\ClientManager();
			$client = $cm->make(array(
				'host' => trim((string) $account->imap_host),
				'port' => (int) $account->imap_port,
				'encryption' => in_array($account->imap_encryption, array('ssl', 'tls'), true) ? $account->imap_encryption : false,
				'validate_cert' => !empty($account->imap_validate_cert),
				'protocol' => 'imap',
				'username' => $login,
				'password' => $password,
				'authentication' => $account->imap_auth_mode === InboxAccount::AUTH_OAUTH ? 'oauth' : 'login',
				'timeout' => getDolGlobalInt('INBOX_IMAP_TIMEOUT', 20),
			));
			$client->connect();
			$this->client = $client;
			$caps = array();
			foreach ((array) $client->getConnection()->getCapabilities() as $cap) {
				if (is_string($cap) && preg_match('/^[A-Z0-9=+.\-]+$/i', $cap) && !in_array(strtoupper($cap), array('CAPABILITY', 'OK'), true)) {
					$caps[] = strtoupper($cap);
				}
			}
			$this->serverCapabilities = array_values(array_unique($caps));
		} catch (Exception $e) {
			restore_error_handler();
			$this->error = $this->explainException($e, $warnings);
			dol_syslog(__METHOD__.' '.$account->email.' '.$this->error, LOG_WARNING);
			$this->client = null;
			return false;
		}
		restore_error_handler();

		return true;
	}

	/**
	 * @return void
	 */
	public function disconnect()
	{
		if ($this->client !== null) {
			try {
				$this->client->disconnect();
			} catch (Exception $e) {
				// Already closed by the server
			}
		}
		$this->client = null;
		$this->examined = '';
		$this->examinedValidity = 0;
	}

	/**
	 * @return string IMAP login, master login in master mode
	 */
	public function getLogin()
	{
		if ($this->account->imap_auth_mode === InboxAccount::AUTH_MASTER) {
			return trim((string) $this->account->email).getDolGlobalString('INBOX_MASTER_SEPARATOR', '*').getDolGlobalString('INBOX_MASTER_USER');
		}

		return $this->account->getImapLogin();
	}

	/**
	 * Password or token of the connection. Never returned to a caller outside the connector.
	 *
	 * @return string
	 */
	protected function getSecret()
	{
		$account = $this->account;
		if ($account->imap_auth_mode === InboxAccount::AUTH_OAUTH) {
			$error = '';
			$token = InboxOAuth::getAccessToken((string) $account->oauth_service, $error);
			$this->error = $error;
			return $token;
		}
		if ($account->imap_auth_mode === InboxAccount::AUTH_MASTER) {
			if (getDolGlobalString('INBOX_MASTER_USER') === '') {
				return '';
			}
			return (string) dolDecrypt(getDolGlobalString('INBOX_MASTER_PASSWORD'));
		}

		return $account->getImapPassword();
	}

	/**
	 * @return string[] Capabilities of the server, after connect()
	 */
	public function getServerCapabilities()
	{
		return $this->serverCapabilities;
	}

	/**
	 * @return array{ok:bool,duration:float,info:array<string,mixed>}|null
	 */
	public function testConnection()
	{
		$start = microtime(true);
		if (!$this->connect()) {
			return null;
		}
		$folders = $this->listFolders();
		if ($folders === null) {
			return null;
		}
		$delimiter = '';
		$roles = array();
		foreach ($folders as $folder) {
			if ($delimiter === '' && $folder['delimiter'] !== '') {
				$delimiter = $folder['delimiter'];
			}
			if ($folder['role'] !== '' && !isset($roles[$folder['role']])) {
				$roles[$folder['role']] = $folder['path'];
			}
		}
		$caps = $this->serverCapabilities;
		$info = array(
			'capabilities' => $caps,
			'move' => in_array('MOVE', $caps, true),
			'uidplus' => in_array('UIDPLUS', $caps, true),
			'condstore' => in_array('CONDSTORE', $caps, true),
			'special_use' => in_array('SPECIAL-USE', $caps, true),
			'idle' => in_array('IDLE', $caps, true),
			'quota' => in_array('QUOTA', $caps, true),
			'delimiter' => $delimiter,
			'folders' => count($folders),
			'roles' => $roles,
		);

		return array('ok' => true, 'duration' => round(microtime(true) - $start, 2), 'info' => $info);
	}

	/**
	 * @return array<int,array{path:string,name:string,delimiter:string,selectable:bool,role:string,flags:string[]}>|null
	 */
	public function listFolders()
	{
		if (!$this->connect()) {
			return null;
		}
		try {
			$items = $this->client->getConnection()->folders('', '*');
		} catch (Exception $e) {
			$this->error = $this->explainException($e);
			return null;
		}
		$roles = $this->account->getFolderRoles();
		$out = array();
		foreach ((array) $items as $path => $item) {
			$path = (string) $path;
			if (!self::isSafeFolderPath($path)) {
				continue;
			}
			$flags = array_map('strtolower', array_map('strval', (array) $item['flags']));
			$role = array_search($path, $roles, true);
			$out[] = array(
				'path' => $path,
				'name' => self::decodeFolderName($path),
				'delimiter' => (string) $item['delimiter'],
				'selectable' => !in_array('\\noselect', $flags, true) && !in_array('\\nonexistent', $flags, true),
				'role' => $role !== false ? (string) $role : self::guessRole($path, $flags),
				'flags' => $flags,
			);
		}
		self::addLabels($out);
		usort($out, function ($a, $b) {
			if (strtoupper($a['path']) === 'INBOX') {
				return -1;
			}
			if (strtoupper($b['path']) === 'INBOX') {
				return 1;
			}
			return strcasecmp($a['name'], $b['name']);
		});

		return $out;
	}

	/**
	 * Readable label of each folder: path decoded, levels joined by " / ", and without the common prefix of the
	 * server when every other folder starts with it (Dovecot, Plesk: « INBOX. »), as « Archives / 2025 ». The stored
	 * value stays the real path.
	 *
	 * @param array<int,array<string,mixed>> $folders Folders of listFolders(), changed in place (key label)
	 * @return void
	 */
	public static function addLabels(array &$folders)
	{
		$prefix = null;
		foreach ($folders as $f) {
			if (strtoupper($f['path']) === 'INBOX' || $f['delimiter'] === '') {
				continue;
			}
			$candidate = 'INBOX'.$f['delimiter'];
			if (stripos($f['path'], $candidate) !== 0) {
				$prefix = '';
				break;
			}
			$prefix = $candidate;
		}
		foreach ($folders as $i => $f) {
			$path = $f['path'];
			if ($prefix && strtoupper($path) !== 'INBOX' && stripos($path, $prefix) === 0) {
				$path = substr($path, strlen($prefix));
			}
			$label = self::decodeFolderName($path);
			if ($f['delimiter'] !== '') {
				$label = implode(' / ', explode($f['delimiter'], $label));
			}
			$folders[$i]['label'] = $label;
		}
	}

	/**
	 * Role of a folder: SPECIAL-USE flag first, else the usual names (several languages)
	 *
	 * @param string   $path  Folder path
	 * @param string[] $flags Flags of LIST, lower case
	 * @return string          inbox, sent, drafts, trash, junk, archive or ''
	 */
	public static function guessRole($path, array $flags = array())
	{
		if (strtoupper($path) === 'INBOX') {
			return 'inbox';
		}
		foreach (array('sent' => '\\sent', 'drafts' => '\\drafts', 'trash' => '\\trash', 'junk' => '\\junk', 'archive' => '\\archive') as $role => $flag) {
			if (in_array($flag, $flags, true)) {
				return $role;
			}
		}
		// Last level of the path only (INBOX.Archives.2025 is not the archive folder itself)
		$name = strtolower(self::decodeFolderName($path));
		$name = preg_replace('/^.*[.\/]/', '', $name);
		$names = array(
			'sent' => '/^(sent|sent items|sent messages|envoy[ée]s|[ée]l[ée]ments envoy[ée]s|messages envoy[ée]s|gesendet|gesendete elemente|inviati|posta inviata|enviados|elementos enviados)$/u',
			'drafts' => '/^(drafts|brouillons|entw[üu]rfe|bozze|borradores)$/u',
			'trash' => '/^(trash|deleted|deleted items|deleted messages|corbeille|[ée]l[ée]ments supprim[ée]s|papierkorb|cestino|papelera)$/u',
			'junk' => '/^(junk|spam|junk e-mail|courrier ind[ée]sirable|ind[ée]sirables|pourriel|spamverdacht|posta indesiderata|correo no deseado)$/u',
			'archive' => '/^(archive|archives|archiv|archivio|archivo)$/u',
		);
		foreach ($names as $role => $re) {
			if (preg_match($re, $name)) {
				return $role;
			}
		}

		return '';
	}

	/**
	 * Open a folder read only (EXAMINE)
	 *
	 * @param string $folder Folder path
	 * @return bool
	 */
	protected function examine($folder)
	{
		if ($this->examined === $folder) {
			return true;
		}
		$status = $this->client->getConnection()->examineFolder($folder);
		if (!is_array($status)) {
			$this->examined = '';
			return false;
		}
		$this->examined = $folder;
		$this->examinedValidity = isset($status['uidvalidity']) ? (int) $status['uidvalidity'] : 0;

		return true;
	}

	/**
	 * @param string $folder Folder path
	 * @param string $remoteId "uidvalidity:uid"
	 * @return string|null
	 */
	public function fetchRaw($folder, $remoteId)
	{
		$this->error = '';
		if (!preg_match('/^(\d+):(\d+)$/', (string) $remoteId, $m) || !self::isSafeFolderPath($folder)) {
			$this->error = 'Invalid identifier';
			return null;
		}
		if (!$this->connect()) {
			return null;
		}
		try {
			if (!$this->examine($folder)) {
				$this->error = 'Folder not found';
				return null;
			}
			if ($this->examinedValidity !== (int) $m[1]) {
				// The folder was rebuilt on the server: the identifier is not valid any more
				$this->error = 'UIDVALIDITY changed';
				return null;
			}
			return $this->fetchItem((int) $m[2], 'BODY[]');
		} catch (Exception $e) {
			$this->error = $this->explainException($e);
			return null;
		}
	}

	/**
	 * One item of a mail, with PEEK (the mail stays unread)
	 *
	 * @param int    $uid  UID in the examined folder
	 * @param string $item BODY[] or BODY[HEADER]
	 * @return string|null
	 */
	protected function fetchItem($uid, $item)
	{
		$peek = str_replace('BODY[', 'BODY.PEEK[', $item);
		$response = $this->client->getConnection()->requestAndResponse('UID FETCH', array((string) (int) $uid, '(UID '.$peek.')'));
		foreach (is_array($response) ? $response : array() as $line) {
			if (!is_array($line) || !isset($line[1], $line[2]) || $line[1] !== 'FETCH' || !is_array($line[2])) {
				continue;
			}
			$tokens = $line[2];
			$count = count($tokens);
			$gotUid = null;
			$data = null;
			for ($i = 0; $i + 1 < $count; $i += 2) {
				if ($tokens[$i] === 'UID') {
					$gotUid = (int) $tokens[$i + 1];
				} elseif ($tokens[$i] === $item && is_string($tokens[$i + 1])) {
					$data = $tokens[$i + 1];
				}
			}
			if ($gotUid === (int) $uid && $data !== null) {
				return $data;
			}
		}

		return null;
	}

	/**
	 * @param string        $messageId Message-ID without angle brackets
	 * @param string[]|null $folders   Folders, null = all
	 * @return array{raw:string,folder:string,remote_id:string}|null
	 */
	public function findByMessageId($messageId, $folders = null)
	{
		$this->error = '';
		$this->notFound = false;
		// Same rule as Inbox::normalizeMessageId(): no quote, backslash, angle bracket, space or line break
		if (!preg_match('/^[\x21-\x7E]{3,250}$/', (string) $messageId) || preg_match('/["\\\\<>]/', (string) $messageId)) {
			$this->error = 'Invalid Message-ID';
			return null;
		}
		if (!$this->connect()) {
			return null;
		}
		$start = microtime(true);
		try {
			if ($folders === null) {
				$folders = $this->orderedFolders();
				if ($folders === null) {
					return null;
				}
			}
			foreach (array_slice($folders, 0, self::MAX_FOLDERS) as $path) {
				if (microtime(true) - $start > self::TIME_BUDGET) {
					$this->error = 'Time budget spent before the end of the folders';
					return null;
				}
				if (!self::isSafeFolderPath($path) || !$this->examine($path)) {
					continue;
				}
				// HEADER search is a substring search: every answer is checked against the exact Message-ID
				$uids = $this->client->getConnection()->search(array('HEADER', 'Message-ID', '"'.$messageId.'"'), true);
				foreach (array_slice(array_map('intval', is_array($uids) ? $uids : array()), 0, 20) as $uid) {
					$header = $this->fetchItem($uid, 'BODY[HEADER]');
					if ($header === null || InboxMimeParser::parse(rtrim($header)."\r\n\r\n")->messageId !== $messageId) {
						continue;
					}
					$raw = $this->fetchItem($uid, 'BODY[]');
					if ($raw === null) {
						continue;
					}
					return array('raw' => $raw, 'folder' => $path, 'remote_id' => $this->examinedValidity.':'.$uid);
				}
			}
		} catch (Exception $e) {
			$this->error = $this->explainException($e);
			return null;
		}
		$this->notFound = true;

		return null;
	}

	/**
	 * Folders in the order a mail is most likely found: inbox, sent, the others, junk and trash last
	 *
	 * @return string[]|null
	 */
	protected function orderedFolders()
	{
		$list = $this->listFolders();
		if ($list === null) {
			return null;
		}
		$groups = array('inbox' => array(), 'sent' => array(), 'other' => array(), 'last' => array());
		foreach ($list as $folder) {
			if (!$folder['selectable']) {
				continue;
			}
			if ($folder['role'] === 'inbox') {
				$groups['inbox'][] = $folder['path'];
			} elseif ($folder['role'] === 'sent') {
				$groups['sent'][] = $folder['path'];
			} elseif (in_array($folder['role'], array('junk', 'trash'), true)) {
				$groups['last'][] = $folder['path'];
			} else {
				$groups['other'][] = $folder['path'];
			}
		}

		return array_merge($groups['inbox'], $groups['sent'], $groups['other'], $groups['last']);
	}

	/**
	 * Number of mails of the folders (STATUS, read only), for the connection test
	 *
	 * @param string[] $paths Folder paths
	 * @return array<string,int> Path => number of mails (-1 if unknown)
	 */
	public function countMessages(array $paths)
	{
		$out = array();
		if (!$this->connect()) {
			return $out;
		}
		foreach (array_slice($paths, 0, self::MAX_FOLDERS) as $path) {
			$out[$path] = -1;
			if (!self::isSafeFolderPath($path)) {
				continue;
			}
			try {
				$response = $this->client->getConnection()->requestAndResponse('STATUS', array($this->client->getConnection()->escapeString($path), '(MESSAGES)'));
				foreach (is_array($response) ? $response : array() as $line) {
					if (is_array($line) && isset($line[0], $line[2]) && $line[0] === 'STATUS' && is_array($line[2]) && isset($line[2][0], $line[2][1]) && $line[2][0] === 'MESSAGES') {
						$out[$path] = (int) $line[2][1];
					}
				}
			} catch (Exception $e) {
				// Count unknown for this folder, the test goes on
			}
		}

		return $out;
	}

	/**
	 * Open a socket to a server, with TLS from the start (ssl) or plain (tls = STARTTLS later, none)
	 *
	 * @param string $host       Host
	 * @param int    $port       Port
	 * @param string $encryption ssl, tls or none
	 * @param string $error      Error, plain text
	 * @return resource|null
	 */
	protected function openSocket($host, $port, $encryption, &$error)
	{
		$timeout = max(3, getDolGlobalInt('INBOX_TEST_TIMEOUT', 15));
		$context = stream_context_create(array('ssl' => array(
			'verify_peer' => !empty($this->account->imap_validate_cert),
			'verify_peer_name' => !empty($this->account->imap_validate_cert),
			'SNI_enabled' => true,
			'peer_name' => $host,
		)));
		$errno = 0;
		$errstr = '';
		$warnings = array();
		set_error_handler(function ($no, $str) use (&$warnings) {
			$warnings[] = preg_replace('/^[a-z_]+\(\):\s*/', '', $str);
			return true;
		}, E_WARNING | E_NOTICE);
		$socket = stream_socket_client(($encryption === 'ssl' ? 'ssl://' : 'tcp://').$host.':'.((int) $port), $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);
		restore_error_handler();
		if (!$socket) {
			$all = implode(' ', $warnings).' '.$errstr;
			$error = stripos($all, 'certificate verify failed') !== false ? 'certificate' : trim($errstr !== '' ? $errstr : implode(' - ', $warnings));
			return null;
		}
		stream_set_timeout($socket, $timeout);

		return $socket;
	}

	/**
	 * Switch a plain socket to TLS (STARTTLS)
	 *
	 * @param resource $socket Socket
	 * @return bool
	 */
	protected function startTls($socket)
	{
		set_error_handler(function () {
			return true;
		}, E_WARNING | E_NOTICE);
		$ok = stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT') ? STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT : 0));
		restore_error_handler();

		return (bool) $ok;
	}

	/**
	 * Read an SMTP answer (several lines "250-...", last one "250 ...")
	 *
	 * @param resource $socket Socket
	 * @return array{code:int,lines:string[]}
	 */
	protected function smtpRead($socket)
	{
		$lines = array();
		$code = 0;
		while (($line = fgets($socket, 4096)) !== false) {
			$lines[] = rtrim($line);
			$code = (int) substr($line, 0, 3);
			if (strlen($line) < 4 || $line[3] !== '-') {
				break;
			}
		}

		return array('code' => $code, 'lines' => $lines);
	}

	/**
	 * @param resource $socket  Socket
	 * @param string   $command Command (never logged: it may hold a credential)
	 * @return array{code:int,lines:string[]}
	 */
	protected function smtpCommand($socket, $command)
	{
		fwrite($socket, $command."\r\n");

		return $this->smtpRead($socket);
	}

	/**
	 * SMTP test: EHLO, encryption, AUTH; no mail is sent
	 *
	 * @return array{status:string,code:string,detail:string,extensions:string[]}
	 */
	public function testSmtp()
	{
		$account = $this->account;
		$host = $account->getSmtpHost();
		$encryption = (string) $account->smtp_encryption;
		$result = array('status' => 'error', 'code' => '', 'detail' => '', 'extensions' => array());
		if ($host === '') {
			$result['code'] = 'smtp_no_server';
			return $result;
		}
		$login = $account->getSmtpLogin();
		if ($account->imap_auth_mode === InboxAccount::AUTH_OAUTH) {
			$error = '';
			$secret = InboxOAuth::getAccessToken((string) $account->oauth_service, $error);
		} elseif ($account->imap_auth_mode === InboxAccount::AUTH_MASTER && !empty($account->smtp_same_credentials)) {
			// The master user of Dovecot is not a login for the SMTP server
			$result['status'] = 'warning';
			$result['code'] = 'smtp_master';
			return $result;
		} else {
			$secret = $account->getSmtpPassword();
		}
		if ($secret === '') {
			$result['code'] = 'smtp_no_password';
			return $result;
		}
		$error = '';
		$socket = $this->openSocket($host, (int) $account->smtp_port, $encryption, $error);
		if (!$socket) {
			$result['code'] = $error === 'certificate' ? 'smtp_certificate' : 'smtp_connect';
			$result['detail'] = $error === 'certificate' ? '' : $error;
			return $result;
		}
		try {
			$greeting = $this->smtpRead($socket);
			if ($greeting['code'] !== 220) {
				$result['code'] = 'smtp_greeting';
				$result['detail'] = implode(' ', $greeting['lines']);
				return $result;
			}
			$ehloName = preg_replace('/[^a-z0-9.\-]/i', '', (string) (gethostname() ?: 'localhost'));
			$ehlo = $this->smtpCommand($socket, 'EHLO '.$ehloName);
			if ($encryption === 'tls') {
				if (!preg_grep('/^250[ -]STARTTLS/i', $ehlo['lines'])) {
					$result['code'] = 'smtp_no_starttls';
					return $result;
				}
				$start = $this->smtpCommand($socket, 'STARTTLS');
				if ($start['code'] !== 220 || !$this->startTls($socket)) {
					$result['code'] = 'smtp_certificate';
					return $result;
				}
				$ehlo = $this->smtpCommand($socket, 'EHLO '.$ehloName);
			}
			foreach ($ehlo['lines'] as $line) {
				$result['extensions'][] = strtoupper(trim(substr($line, 4)));
			}
			$authLine = '';
			foreach ($result['extensions'] as $ext) {
				if (strpos($ext, 'AUTH ') === 0) {
					$authLine = $ext;
				}
			}
			if ($account->imap_auth_mode === InboxAccount::AUTH_OAUTH) {
				$answer = $this->smtpCommand($socket, 'AUTH XOAUTH2 '.base64_encode('user='.$login."\1auth=Bearer ".$secret."\1\1"));
			} elseif (strpos($authLine, 'PLAIN') !== false) {
				$answer = $this->smtpCommand($socket, 'AUTH PLAIN '.base64_encode("\0".$login."\0".$secret));
			} elseif (strpos($authLine, 'LOGIN') !== false) {
				$this->smtpCommand($socket, 'AUTH LOGIN');
				$this->smtpCommand($socket, base64_encode($login));
				$answer = $this->smtpCommand($socket, base64_encode($secret));
			} else {
				$result['code'] = 'smtp_no_auth';
				return $result;
			}
			if ($answer['code'] !== 235) {
				$result['code'] = 'smtp_auth';
				return $result;
			}
			$result['status'] = 'ok';
			$result['code'] = 'smtp_ok';

			return $result;
		} finally {
			@fwrite($socket, "QUIT\r\n");
			fclose($socket);
		}
	}

	/**
	 * Read a ManageSieve answer: lines until OK / NO / BYE
	 *
	 * @param resource $socket Socket
	 * @return array{status:string,lines:string[]}
	 */
	protected function sieveRead($socket)
	{
		$lines = array();
		while (($line = fgets($socket, 8192)) !== false) {
			$line = rtrim($line, "\r\n");
			if (preg_match('/^(OK|NO|BYE)\b/i', $line, $m)) {
				return array('status' => strtoupper($m[1]), 'lines' => $lines, 'last' => $line);
			}
			$lines[] = $line;
		}

		return array('status' => 'BYE', 'lines' => $lines, 'last' => '');
	}

	/**
	 * Sieve test (ManageSieve): capabilities, STARTTLS, AUTHENTICATE, LISTSCRIPTS. Read only.
	 *
	 * @return array{status:string,code:string,detail:string,extensions:string[],scripts:array<int,array{name:string,active:bool}>}
	 */
	public function testSieve()
	{
		$account = $this->account;
		$result = array('status' => 'warning', 'code' => '', 'detail' => '', 'extensions' => array(), 'scripts' => array());
		if ((int) $account->sieve_port <= 0) {
			$result['code'] = 'sieve_disabled';
			return $result;
		}
		$host = trim((string) $account->imap_host);
		$error = '';
		$socket = $this->openSocket($host, (int) $account->sieve_port, 'none', $error);
		if (!$socket) {
			$result['code'] = 'sieve_connect';
			$result['detail'] = $error;
			return $result;
		}
		try {
			$caps = $this->sieveRead($socket);
			$hasTls = (bool) preg_grep('/^"STARTTLS"/i', $caps['lines']);
			if ($hasTls) {
				fwrite($socket, "STARTTLS\r\n");
				$answer = $this->sieveRead($socket);
				if ($answer['status'] !== 'OK' || !$this->startTls($socket)) {
					$result['code'] = 'sieve_tls';
					return $result;
				}
				// Capabilities are sent again after STARTTLS
				$caps = $this->sieveRead($socket);
			} elseif (!InboxAccount::isLocalHost($host)) {
				// Never a password in clear on the network
				$result['code'] = 'sieve_no_tls';
				return $result;
			}
			foreach ($caps['lines'] as $line) {
				if (preg_match('/^"SIEVE"\s+"([^"]*)"/i', $line, $m)) {
					$result['extensions'] = preg_split('/\s+/', trim($m[1]));
				}
			}
			$login = $this->getLogin();
			$secret = $this->getSecret();
			if ($secret === '') {
				$result['code'] = 'sieve_no_password';
				return $result;
			}
			$mechanism = $account->imap_auth_mode === InboxAccount::AUTH_OAUTH ? 'XOAUTH2' : 'PLAIN';
			$payload = $mechanism === 'XOAUTH2' ? 'user='.$login."\1auth=Bearer ".$secret."\1\1" : "\0".$login."\0".$secret;
			fwrite($socket, 'AUTHENTICATE "'.$mechanism.'" "'.base64_encode($payload).'"'."\r\n");
			$auth = $this->sieveRead($socket);
			if ($auth['status'] !== 'OK') {
				$result['status'] = 'error';
				$result['code'] = 'sieve_auth';
				return $result;
			}
			fwrite($socket, "LISTSCRIPTS\r\n");
			$list = $this->sieveRead($socket);
			foreach ($list['lines'] as $line) {
				if (preg_match('/^"((?:[^"\\\\]|\\\\.)*)"(\s+ACTIVE)?/i', $line, $m)) {
					$result['scripts'][] = array('name' => stripcslashes($m[1]), 'active' => !empty($m[2]));
				}
			}
			$result['status'] = 'ok';
			$result['code'] = 'sieve_ok';
			foreach ($result['scripts'] as $script) {
				if ($script['active'] && $script['name'] !== 'inbox') {
					// An active script that is not the one of inbox: inbox will have to include it, not replace it
					$result['status'] = 'warning';
					$result['code'] = 'sieve_other_script';
					$result['detail'] = $script['name'];
				}
			}

			return $result;
		} finally {
			@fwrite($socket, "LOGOUT\r\n");
			fclose($socket);
		}
	}

	/**
	 * A folder path can be stored and shown: no quote, angle bracket, line break nor NUL (it is chosen by the owner
	 * of the mailbox)
	 *
	 * @param string $path Path
	 * @return bool
	 */
	public static function isSafeFolderPath($path)
	{
		$path = (string) $path;

		return $path !== '' && strlen($path) <= 255 && !preg_match('/["\'<>\r\n\x00]/', $path);
	}

	/**
	 * @param string $path Path in modified UTF-7
	 * @return string       Readable name (UTF-8)
	 */
	public static function decodeFolderName($path)
	{
		return mb_convert_encoding((string) $path, 'UTF-8', 'UTF7-IMAP');
	}

	/**
	 * Readable message of an IMAP exception (Webklex wraps the real cause in the previous exception). Never the
	 * password: the messages of the server and of the socket do not hold it.
	 *
	 * @param Exception $e        Exception
	 * @param string[]  $warnings PHP warnings raised during the connection
	 * @return string              Plain text
	 */
	protected function explainException($e, $warnings = array())
	{
		global $langs;

		$langs->load('inbox@inbox');
		foreach ($warnings as $warning) {
			if (stripos($warning, 'certificate verify failed') !== false) {
				return $langs->transnoentities('InboxErrorImapCertificate');
			}
		}
		$messages = array();
		$authFailed = false;
		for ($ex = $e; $ex; $ex = $ex->getPrevious()) {
			if ($ex instanceof \Webklex\PHPIMAP\Exceptions\AuthFailedException) {
				$authFailed = true;
			}
			if ($ex->getMessage() !== '' && !in_array($ex->getMessage(), $messages, true)) {
				$messages[] = $ex->getMessage();
			}
		}
		if ($authFailed) {
			$key = array(InboxAccount::AUTH_MASTER => 'InboxErrorImapAuthMaster', InboxAccount::AUTH_OAUTH => 'InboxErrorImapAuthOAuth');
			return $langs->transnoentities(isset($key[$this->account->imap_auth_mode]) ? $key[$this->account->imap_auth_mode] : 'InboxErrorImapAuthLogin');
		}
		$last = end($warnings);
		if ($last) {
			$messages[] = $last;
		}
		$text = implode(' - ', $messages);
		// Defensive: a server could echo the login line
		$secret = $this->account->imap_auth_mode === InboxAccount::AUTH_LOGIN ? $this->account->getImapPassword() : '';
		if ($secret !== '') {
			$text = str_replace($secret, '***', $text);
		}

		return $langs->transnoentities('InboxErrorImapConnection', $text);
	}
}
