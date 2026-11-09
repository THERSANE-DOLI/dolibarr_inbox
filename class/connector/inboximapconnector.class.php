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
require_once dirname(__DIR__).'/inboxheaderparser.class.php';
require_once __DIR__.'/inboximapresponse.class.php';

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
	 * Reading, flags (lot W3), tags, move, import, folders (lot W4); sending and drafts come with lot W6, each one checked
	 * against the capabilities of the server.
	 *
	 * @return string[]
	 */
	protected function supportedCapabilities()
	{
		$caps = array(self::CAP_READ, self::CAP_FOLDERS, self::CAP_SERVER_SEARCH, self::CAP_CHANGES, self::CAP_FLAGS);
		$server = $this->serverCapabilities;
		if (!$server) {
			$info = $this->account->getServerInfo();
			$server = isset($info['capabilities']) && is_array($info['capabilities']) ? $info['capabilities'] : array();
		}
		if (in_array('SPECIAL-USE', $server, true)) {
			$caps[] = self::CAP_SPECIAL_USE;
		}
		if (in_array('QUOTA', $server, true)) {
			$caps[] = self::CAP_QUOTA;
		}
		// Writes of lot W4: tags, move (MOVE, or COPY + UID EXPUNGE with UIDPLUS), import (APPEND), folders
		$caps[] = self::CAP_TAGS;
		$caps[] = self::CAP_IMPORT;
		// Rules and out of office (lot W8 1.23.0): by ManageSieve, when the mailbox has its port
		if ((int) $this->account->sieve_port > 0) {
			$caps[] = self::CAP_RULES;
			$caps[] = self::CAP_VACATION;
		}
		$caps[] = self::CAP_FOLDERS_MANAGE;
		if (in_array('MOVE', $server, true) || in_array('UIDPLUS', $server, true)) {
			$caps[] = self::CAP_MOVE;
		}
		// Lot W6: sending by the SMTP server of the mailbox, drafts in its folder (APPEND)
		if ($this->account->getSmtpHost() !== '') {
			$caps[] = self::CAP_SEND;
		}
		$caps[] = self::CAP_DRAFTS;

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
			// The master password opens every mailbox of the mail server: only to the server named with it in the
			// setup (audit INBX-002)
			$masterHost = strtolower(trim(getDolGlobalString('INBOX_MASTER_HOST')));
			if (getDolGlobalString('INBOX_MASTER_USER') === '' || $masterHost === '' || strtolower(trim((string) $account->imap_host)) !== $masterHost) {
				$this->error = 'Master user: not allowed for this server';
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
		$this->notFound = false;
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
			$data = $this->fetchItem((int) $m[2], 'BODY[]');
			if ($data === null) {
				// Removed or moved by another client since the last pass
				$this->notFound = true;
				$this->error = 'Mail not found in the folder';
			}
			return $data;
		} catch (Exception $e) {
			$this->error = $this->explainException($e);
			return null;
		}
	}

	/**
	 * UID SEARCH CHARSET UTF-8 [SINCE d] [BEFORE d] TEXT w1 TEXT w2 in the folder, read only (EXAMINE). Every word is
	 * sent as a literal: no quote or bracket typed by the user can change the command.
	 *
	 * @param string              $folder  Folder path
	 * @param string[]            $words   Words
	 * @param array<string,mixed> $options since, before (timestamps)
	 * @return string[]|null "uidvalidity:uid"
	 */
	public function searchText($folder, array $words, array $options = array())
	{
		$this->error = '';
		$tokens = array('CHARSET', 'UTF-8');
		foreach (array('since' => 'SINCE', 'before' => 'BEFORE') as $key => $criterion) {
			if (!empty($options[$key])) {
				$tokens[] = $criterion;
				$tokens[] = gmdate('j-M-Y', (int) $options[$key]);
			}
		}
		$n = 0;
		foreach ($words as $w) {
			$w = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) $w));
			if ($w === '') {
				continue;
			}
			$w = mb_substr($w, 0, 100, 'UTF-8');
			$tokens[] = 'TEXT';
			$tokens[] = array('{'.strlen($w).'}', $w);
			$n++;
		}
		if (!$n) {
			return array();
		}
		if (!self::isSafeFolderPath($folder)) {
			$this->error = 'Invalid folder';
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
			$out = array();
			foreach ($this->searchUids($tokens) as $uid) {
				$out[] = $this->examinedValidity.':'.$uid;
			}

			return $out;
		} catch (Exception $e) {
			$this->error = $this->explainException($e);
			// A timeout leaves the connection in an unknown state: the next call connects again
			$this->disconnect();
			return null;
		}
	}

	/**
	 * BODY.PEEK[HEADER] and BODY.PEEK[TEXT]<0.max> by chunks: the mails stay unread, the big attachments are not
	 * downloaded (the text parts come first in nearly every mail)
	 *
	 * @param string   $folder    Folder path
	 * @param string[] $remoteIds "uidvalidity:uid"
	 * @param int      $max       Bytes of the body at most
	 * @return array<string,string>|null
	 */
	public function fetchStarts($folder, array $remoteIds, $max = 131072)
	{
		$this->error = '';
		if (!self::isSafeFolderPath($folder) || !$this->connect()) {
			if ($this->error === '') {
				$this->error = 'Invalid folder';
			}
			return null;
		}
		$out = array();
		try {
			if (!$this->examine($folder)) {
				$this->error = 'Folder not found';
				return null;
			}
			$uids = array();
			foreach ($remoteIds as $rid) {
				if (preg_match('/^(\d+):(\d+)$/', (string) $rid, $m) && (int) $m[1] === $this->examinedValidity) {
					$uids[] = (int) $m[2];
				}
			}
			$max = max(1024, (int) $max);
			foreach (array_chunk($uids, 50) as $chunk) {
				$response = $this->fetchDecoded(implode(',', $chunk), '(UID BODY.PEEK[HEADER] BODY.PEEK[TEXT]<0.'.$max.'>)');
				foreach ($this->fetchItems($response) as $item) {
					if (empty($item['UID'])) {
						continue;
					}
					$text = '';
					foreach ($item as $name => $value) {
						if (strpos((string) $name, 'BODY[TEXT]') === 0 && is_string($value)) {
							$text = $value;
						}
					}
					$out[$this->examinedValidity.':'.((int) $item['UID'])] = rtrim((string) ($item['HEADER'] ?? ''))."\r\n\r\n".$text;
				}
			}

			return $out;
		} catch (Exception $e) {
			$this->error = $this->explainException($e);
			$this->disconnect();
			return null;
		}
	}

	/**
	 * Headers of one mail (BODY.PEEK[HEADER]: the mail stays unread), for the checks of the sender (lot W5 1.15.0)
	 *
	 * @param string $folder   Folder path
	 * @param string $remoteId Identifier "uidvalidity:uid"
	 * @return string|null Raw headers, null if not found
	 */
	public function fetchHeaders($folder, $remoteId)
	{
		$this->error = '';
		if (!preg_match('/^(\d+):(\d+)$/', (string) $remoteId, $m) || !self::isSafeFolderPath($folder) || !$this->connect()) {
			$this->error = $this->error ?: 'Invalid identifier';
			return null;
		}
		try {
			if (!$this->examine($folder) || $this->examinedValidity !== (int) $m[1]) {
				$this->error = 'Folder not found';
				return null;
			}
			return $this->fetchItem((int) $m[2], 'BODY[HEADER]');
		} catch (Exception $e) {
			$this->error = $this->explainException($e);
			return null;
		}
	}

	/**
	 * Flags of mails: \\Seen, \\Flagged, \\Answered (UID STORE ... FLAGS.SILENT). The folder is selected (read-write)
	 * for this only, then examined again by the next reading. A mail renumbered by the server (UIDVALIDITY changed)
	 * is never written.
	 *
	 * @param string   $folder    Folder path
	 * @param string[] $remoteIds Identifiers "uidvalidity:uid"
	 * @param string[] $add       Flags to set
	 * @param string[] $remove    Flags to remove
	 * @return bool
	 */
	public function setFlags($folder, array $remoteIds, array $add, array $remove)
	{
		$this->error = '';
		$allowed = array('\\Seen', '\\Flagged', '\\Answered');
		$add = array_values(array_intersect($add, $allowed));
		$remove = array_values(array_intersect($remove, $allowed));
		if (!self::isSafeFolderPath($folder) || (!$add && !$remove)) {
			$this->error = 'Invalid flags';
			return false;
		}
		$validity = 0;
		$uids = array();
		foreach ($remoteIds as $id) {
			if (!preg_match('/^(\d+):(\d+)$/', (string) $id, $m) || ($validity && $validity !== (int) $m[1])) {
				$this->error = 'Invalid identifier';
				return false;
			}
			$validity = (int) $m[1];
			$uids[] = (int) $m[2];
		}
		if (!$uids || !$this->connect()) {
			return false;
		}
		try {
			$conn = $this->client->getConnection();
			$status = $conn->selectFolder($folder);
			$this->examined = '';
			if (!is_array($status) || (int) ($status['uidvalidity'] ?? 0) !== $validity) {
				$this->error = 'UIDVALIDITY changed';
				return false;
			}
			$set = implode(',', $uids);
			foreach (array('+' => $add, '-' => $remove) as $sign => $flags) {
				if ($flags) {
					$conn->requestAndResponse('UID STORE', array($set, $sign.'FLAGS.SILENT', '('.implode(' ', $flags).')'));
				}
			}
			// Back to read only for the next readings of this connection. Never CLOSE here: on a folder selected
			// read-write, CLOSE expunges the mails marked \\Deleted by another client. Without UNSELECT, EXAMINE.
			if (in_array('UNSELECT', $this->serverCapabilities, true)) {
				$conn->requestAndResponse('UNSELECT');
			} else {
				$this->examine($folder);
			}
		} catch (Exception $e) {
			$this->error = $this->explainException($e);
			return false;
		}

		return true;
	}

	/**
	 * Space used and allowed of the mailbox (GETQUOTAROOT INBOX, STORAGE in KB), read only
	 *
	 * @return array{used:int,limit:int}|null Null when the server gives no quota
	 */
	public function quota()
	{
		if (!$this->connect() || !in_array('QUOTA', $this->serverCapabilities, true)) {
			return null;
		}
		try {
			$response = $this->client->getConnection()->requestAndResponse('GETQUOTAROOT', array('INBOX'));
		} catch (Exception $e) {
			return null;
		}
		foreach (is_array($response) ? $response : array() as $line) {
			if (!is_array($line) || ($line[0] ?? '') !== 'QUOTA') {
				continue;
			}
			$items = end($line);
			for ($i = 0; is_array($items) && $i + 2 < count($items) + 0; $i += 3) {
				if (strtoupper((string) $items[$i]) === 'STORAGE' && (int) $items[$i + 2] > 0) {
					return array('used' => (int) $items[$i + 1], 'limit' => (int) $items[$i + 2]);
				}
			}
		}

		return null;
	}

	/**
	 * UIDs of identifiers "uidvalidity:uid" of one folder (all the same uidvalidity)
	 *
	 * @param string[] $remoteIds Identifiers
	 * @param int      $validity  Set to their uidvalidity
	 * @return int[]|null Null if one is not valid
	 */
	protected function uidsOf(array $remoteIds, &$validity)
	{
		$validity = 0;
		$uids = array();
		foreach ($remoteIds as $id) {
			if (!preg_match('/^(\d+):(\d+)$/', (string) $id, $m) || ($validity && $validity !== (int) $m[1])) {
				return null;
			}
			$validity = (int) $m[1];
			$uids[] = (int) $m[2];
		}

		return $uids ?: null;
	}

	/**
	 * Select a folder read-write for one write, after checking its uidvalidity; back to read only by readOnly()
	 *
	 * @param string $folder   Folder path
	 * @param int    $validity Uidvalidity expected (0 = any)
	 * @return bool
	 */
	protected function selectForWrite($folder, $validity)
	{
		if (!self::isSafeFolderPath($folder) || !$this->connect()) {
			$this->error = $this->error ?: 'Invalid folder';
			return false;
		}
		$status = $this->client->getConnection()->selectFolder($folder);
		$this->examined = '';
		if (!is_array($status)) {
			$this->error = 'Folder not found';
			return false;
		}
		if ($validity && (int) ($status['uidvalidity'] ?? 0) !== $validity) {
			$this->error = 'UIDVALIDITY changed';
			return false;
		}

		return true;
	}

	/**
	 * Back to read only after a write. Never CLOSE: on a folder selected read-write it expunges the mails marked
	 * \Deleted by another client. Without UNSELECT, EXAMINE of the same folder.
	 *
	 * @param string $folder Folder path
	 * @return void
	 */
	protected function readOnly($folder)
	{
		try {
			if (in_array('UNSELECT', $this->serverCapabilities, true)) {
				$this->client->getConnection()->requestAndResponse('UNSELECT');
			} else {
				$this->examine($folder);
			}
		} catch (Exception $e) {
			// Next reading examines again
			$this->examined = '';
		}
	}

	/**
	 * UIDs of a folder among a set (to check a write), folder selected
	 *
	 * @param int[] $uids UIDs
	 * @return int[]
	 */
	protected function presentUids(array $uids)
	{
		$found = array();
		foreach (array_chunk($uids, 500) as $chunk) {
			$found = array_merge($found, $this->searchUids(array('UID', implode(',', $chunk))));
		}

		return array_map('intval', $found);
	}

	/**
	 * @param string   $folder    Folder path
	 * @param string[] $remoteIds Identifiers
	 * @param string[] $add       Keywords to add
	 * @param string[] $remove    Keywords to remove
	 * @return bool
	 */
	public function setTags($folder, array $remoteIds, array $add, array $remove)
	{
		$this->error = '';
		$valid = function ($k) {
			return is_string($k) && preg_match('/^[A-Za-z0-9$_.\-]{1,64}$/', $k) && $k[0] !== '\\';
		};
		$add = array_values(array_filter($add, $valid));
		$remove = array_values(array_filter($remove, $valid));
		$uids = $this->uidsOf($remoteIds, $validity);
		if ($uids === null || (!$add && !$remove)) {
			$this->error = 'Invalid tags';
			return false;
		}
		if (!$this->selectForWrite($folder, $validity)) {
			return false;
		}
		try {
			$conn = $this->client->getConnection();
			foreach (array_chunk($uids, 500) as $chunk) {
				foreach (array('+' => $add, '-' => $remove) as $sign => $keys) {
					if ($keys && $conn->requestAndResponse('UID STORE', array(implode(',', $chunk), $sign.'FLAGS.SILENT', '('.implode(' ', $keys).')')) === false) {
						$this->error = 'The server refused the tags (PERMANENTFLAGS)';
						return false;
					}
				}
			}
		} catch (Exception $e) {
			$this->error = $this->explainException($e);
			return false;
		} finally {
			$this->readOnly($folder);
		}

		return true;
	}

	/**
	 * Move mails inside the mailbox: UID MOVE (RFC 6851), else UID COPY + \Deleted + UID EXPUNGE of these UIDs only
	 * (UIDPLUS; without it, nothing is removed: the copy is undone). Checked after: the mails are gone from the source.
	 *
	 * @param string   $folder    Folder path
	 * @param string[] $remoteIds Identifiers
	 * @param string   $target    Target folder path
	 * @return array<string,string>|null Old identifier => new identifier ('' when the server did not tell it), null on error
	 */
	public function move($folder, array $remoteIds, $target)
	{
		$this->error = '';
		$uids = $this->uidsOf($remoteIds, $validity);
		if ($uids === null || !self::isSafeFolderPath($target) || $target === $folder) {
			$this->error = 'Invalid move';
			return null;
		}
		if (!$this->selectForWrite($folder, $validity)) {
			return null;
		}
		$map = array();
		try {
			$conn = $this->client->getConnection();
			$caps = $this->serverCapabilities;
			foreach (array_chunk($uids, 500) as $chunk) {
				$set = implode(',', $chunk);
				if (in_array('MOVE', $caps, true)) {
					$res = $conn->requestAndResponse('UID MOVE', array($set, $conn->escapeString($target)));
				} else {
					if (!in_array('UIDPLUS', $caps, true)) {
						// Without UID EXPUNGE, EXPUNGE would remove every mail marked \Deleted by another client
						$this->error = 'The server knows neither MOVE nor UIDPLUS: mails not moved';
						return null;
					}
					$res = $conn->requestAndResponse('UID COPY', array($set, $conn->escapeString($target)));
					if (is_array($res)) {
						$conn->requestAndResponse('UID STORE', array($set, '+FLAGS.SILENT', '(\\Deleted)'));
						$conn->requestAndResponse('UID EXPUNGE', array($set));
					}
				}
				if (!is_array($res)) {
					$this->error = 'The server refused the move';
					return null;
				}
				$map += self::copyUidMap($res, $chunk, $validity);
			}
			// Checked: no mail left in the source
			$left = $this->presentUids($uids);
			if ($left) {
				$this->error = count($left).' mail(s) still in the source folder after the move';
				return null;
			}
		} catch (Exception $e) {
			$this->error = $this->explainException($e);
			return null;
		} finally {
			$this->readOnly($folder);
		}

		return $map;
	}

	/**
	 * Mapping of the COPYUID answer (UIDPLUS): [COPYUID <validity> <source set> <target set>]
	 *
	 * @param mixed $response Answer
	 * @param int[] $uids     UIDs asked
	 * @param int   $validity Uidvalidity of the source
	 * @return array<string,string> Old identifier => new identifier ('' when unknown)
	 */
	protected static function copyUidMap($response, array $uids, $validity)
	{
		$map = array();
		foreach ($uids as $u) {
			$map[$validity.':'.$u] = '';
		}
		if (!preg_match('/\[COPYUID (\d+) ([\d,:]+) ([\d,:]+)\]/i', self::responseText($response), $m)) {
			return $map;
		}
		$src = self::expandSet($m[2]);
		$dst = self::expandSet($m[3]);
		if (count($src) === count($dst)) {
			foreach ($src as $i => $u) {
				$map[$validity.':'.$u] = $m[1].':'.$dst[$i];
			}
		}

		return $map;
	}

	/**
	 * Response of Webklex (lines cut in words, nested lists) as text: « OK [COPYUID 17 3:4 9:10] Moved »
	 *
	 * @param mixed $response Response
	 * @return string
	 */
	protected static function responseText($response)
	{
		if (!is_array($response)) {
			return is_scalar($response) ? (string) $response : '';
		}
		$parts = array();
		foreach ($response as $item) {
			$parts[] = is_array($item) ? self::responseText($item) : (is_scalar($item) ? (string) $item : '');
		}

		return implode(' ', $parts);
	}

	/**
	 * @param string $set « 3,5:7 »
	 * @return int[]
	 */
	protected static function expandSet($set)
	{
		$out = array();
		foreach (explode(',', $set) as $part) {
			if (strpos($part, ':') !== false) {
				list($a, $b) = array_map('intval', explode(':', $part));
				for ($i = min($a, $b); $i <= max($a, $b) && count($out) < 10000; $i++) {
					$out[] = $i;
				}
			} else {
				$out[] = (int) $part;
			}
		}

		return $out;
	}

	/**
	 * Put a mail in a folder from its source (another mailbox), with its flags and its date of reception; checked by
	 * APPENDUID (UIDPLUS) or by its Message-ID
	 *
	 * @param string   $folder Folder path
	 * @param string   $raw    RFC 822 source
	 * @param string[] $flags  Flags (\Seen, \Answered, \Flagged) and keywords
	 * @param int|null $date   Internal date
	 * @return string|null New identifier ('' when the server did not tell it but the mail is there), null on error
	 */
	public function importRaw($folder, $raw, array $flags = array(), $date = null)
	{
		$this->error = '';
		if (!self::isSafeFolderPath($folder) || !$this->connect() || (string) $raw === '') {
			$this->error = $this->error ?: 'Invalid import';
			return null;
		}
		$flags = array_values(array_filter($flags, function ($f) {
			return is_string($f) && (in_array($f, array('\\Seen', '\\Answered', '\\Flagged', '\\Draft'), true) || preg_match('/^[A-Za-z0-9$_.\-]{1,64}$/', $f));
		}));
		try {
			$conn = $this->client->getConnection();
			$res = $conn->appendMessage($folder, (string) $raw, $flags ?: null, $date ? date('d-M-Y H:i:s O', (int) $date) : null);
			if ($res === false || $res === null) {
				$this->error = 'The server refused the mail';
				return null;
			}
			if (preg_match('/APPENDUID (\d+) (\d+)/i', self::responseText($res), $m)) {
				return $m[1].':'.$m[2];
			}
			// No UIDPLUS: found again by its Message-ID
			if (preg_match('/^Message-ID:\s*<?([^<>\s]+)>?/mi', substr((string) $raw, 0, 65536), $mid)) {
				$this->examined = '';
				if ($this->examine($folder)) {
					$found = $this->searchUids(array('HEADER', 'Message-ID', '"'.str_replace('"', '', $mid[1]).'"'));
					if ($found) {
						return $this->examinedValidity.':'.max(array_map('intval', $found));
					}
				}
				$this->error = 'The mail was not found after the copy';
				return null;
			}

			return '';
		} catch (Exception $e) {
			$this->error = $this->explainException($e);
			return null;
		}
	}

	/**
	 * Remove mails for good: \Deleted then UID EXPUNGE of these UIDs only (UIDPLUS). Without UIDPLUS: refused, as an
	 * EXPUNGE would also remove the mails marked \Deleted by another client.
	 *
	 * @param string   $folder    Folder path
	 * @param string[] $remoteIds Identifiers
	 * @return bool
	 */
	public function deleteMails($folder, array $remoteIds)
	{
		$this->error = '';
		$uids = $this->uidsOf($remoteIds, $validity);
		if ($uids === null) {
			$this->error = 'Invalid identifier';
			return false;
		}
		if (!$this->connect()) {
			return false;
		}
		if (!in_array('UIDPLUS', $this->serverCapabilities, true)) {
			$this->error = 'The server does not know UIDPLUS: mails not removed';
			return false;
		}
		if (!$this->selectForWrite($folder, $validity)) {
			return false;
		}
		try {
			$conn = $this->client->getConnection();
			foreach (array_chunk($uids, 500) as $chunk) {
				$set = implode(',', $chunk);
				$conn->requestAndResponse('UID STORE', array($set, '+FLAGS.SILENT', '(\\Deleted)'));
				$conn->requestAndResponse('UID EXPUNGE', array($set));
			}
			$left = $this->presentUids($uids);
			if ($left) {
				$this->error = count($left).' mail(s) still there after the removal';
				return false;
			}
		} catch (Exception $e) {
			$this->error = $this->explainException($e);
			return false;
		} finally {
			$this->readOnly($folder);
		}

		return true;
	}

	/**
	 * Create a folder (and subscribe to it); an existing folder is not an error
	 *
	 * @param string $path Full path (with the delimiter of the server)
	 * @return bool
	 */
	public function createFolder($path)
	{
		$this->error = '';
		if (!self::isSafeFolderPath($path) || !$this->connect()) {
			$this->error = $this->error ?: 'Invalid folder';
			return false;
		}
		try {
			$conn = $this->client->getConnection();
			$res = $conn->requestAndResponse('CREATE', array($conn->escapeString($path)));
			if ($res === false) {
				// Already there? It answers to STATUS
				$this->examined = '';
				if (!is_array($conn->examineFolder($path))) {
					$this->error = 'The server refused to create the folder';
					return false;
				}
			}
			$conn->requestAndResponse('SUBSCRIBE', array($conn->escapeString($path)));
		} catch (Exception $e) {
			$this->error = $this->explainException($e);
			return false;
		}

		return true;
	}

	/**
	 * The folder answers (EXAMINE), without keeping it examined
	 *
	 * @param string $path Path
	 * @return bool
	 */
	protected function folderExists($path)
	{
		$this->examined = '';
		$ok = is_array($this->client->getConnection()->examineFolder($path));
		$this->examined = '';

		return $ok;
	}

	/**
	 * Rename or move a folder (RENAME: its subfolders follow), subscription moved with it, checked
	 *
	 * @param string $from Current path
	 * @param string $to   New path
	 * @return bool
	 */
	public function renameFolder($from, $to)
	{
		$this->error = '';
		if (!self::isSafeFolderPath($from) || !self::isSafeFolderPath($to) || strtoupper($from) === 'INBOX' || !$this->connect()) {
			$this->error = $this->error ?: 'Invalid folder';
			return false;
		}
		try {
			$conn = $this->client->getConnection();
			// Never the folder renamed while selected
			if (in_array('UNSELECT', $this->serverCapabilities, true)) {
				$conn->requestAndResponse('UNSELECT');
			}
			$this->examined = '';
			if ($this->folderExists($to)) {
				$this->error = 'A folder of this name already exists';
				return false;
			}
			$conn->requestAndResponse('RENAME', array($conn->escapeString($from), $conn->escapeString($to)));
			if (!$this->folderExists($to) || $this->folderExists($from)) {
				$this->error = 'The server refused to rename the folder';
				return false;
			}
			$conn->requestAndResponse('UNSUBSCRIBE', array($conn->escapeString($from)));
			$conn->requestAndResponse('SUBSCRIBE', array($conn->escapeString($to)));
		} catch (Exception $e) {
			$this->error = $this->explainException($e);
			return false;
		}

		return true;
	}

	/**
	 * Remove a folder for good, checked (it does not answer any more)
	 *
	 * @param string $path Path
	 * @return bool
	 */
	public function deleteFolder($path)
	{
		$this->error = '';
		if (!self::isSafeFolderPath($path) || strtoupper($path) === 'INBOX' || !$this->connect()) {
			$this->error = $this->error ?: 'Invalid folder';
			return false;
		}
		try {
			$conn = $this->client->getConnection();
			if (in_array('UNSELECT', $this->serverCapabilities, true)) {
				$conn->requestAndResponse('UNSELECT');
			}
			$this->examined = '';
			$conn->requestAndResponse('UNSUBSCRIBE', array($conn->escapeString($path)));
			$conn->requestAndResponse('DELETE', array($conn->escapeString($path)));
			if ($this->folderExists($path)) {
				$this->error = 'The server refused to remove the folder';
				return false;
			}
		} catch (Exception $e) {
			$this->error = $this->explainException($e);
			return false;
		}

		return true;
	}

	/**
	 * Every unread mail of a folder marked read, checked
	 *
	 * @param string $folder Path
	 * @return int|null Mails changed
	 */
	public function markFolderSeen($folder)
	{
		$this->error = '';
		if (!$this->selectForWrite($folder, 0)) {
			return null;
		}
		try {
			$conn = $this->client->getConnection();
			$uids = array_map('intval', $this->searchUids(array('UNSEEN')));
			foreach (array_chunk($uids, 500) as $chunk) {
				$conn->requestAndResponse('UID STORE', array(implode(',', $chunk), '+FLAGS.SILENT', '(\\Seen)'));
			}
			// Only the mails changed are checked: a mail arrived meanwhile stays new
			$left = $uids ? array_intersect($uids, array_map('intval', $this->searchUids(array('UNSEEN')))) : array();
			if ($left) {
				$this->error = count($left).' mail(s) still unread';
				return null;
			}
		} catch (Exception $e) {
			$this->error = $this->explainException($e);
			return null;
		} finally {
			$this->readOnly($folder);
		}

		return count($uids);
	}

	/**
	 * Every mail of a folder removed for good (UID EXPUNGE of its UIDs only: needs UIDPLUS), checked
	 *
	 * @param string $folder Path
	 * @return int|null Mails removed
	 */
	public function emptyFolder($folder)
	{
		$this->error = '';
		if (!$this->connect()) {
			return null;
		}
		if (!in_array('UIDPLUS', $this->serverCapabilities, true)) {
			$this->error = 'The server does not know UIDPLUS: folder not emptied';
			return null;
		}
		if (!$this->selectForWrite($folder, 0)) {
			return null;
		}
		try {
			$conn = $this->client->getConnection();
			$uids = array_map('intval', $this->searchUids(array('ALL')));
			foreach (array_chunk($uids, 500) as $chunk) {
				$set = implode(',', $chunk);
				$conn->requestAndResponse('UID STORE', array($set, '+FLAGS.SILENT', '(\\Deleted)'));
				$conn->requestAndResponse('UID EXPUNGE', array($set));
			}
			$left = $uids ? $this->presentUids($uids) : array();
			if ($left) {
				$this->error = count($left).' mail(s) still there after the removal';
				return null;
			}
		} catch (Exception $e) {
			$this->error = $this->explainException($e);
			return null;
		} finally {
			$this->readOnly($folder);
		}

		return count($uids);
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
	 * SMTP session opened and authenticated: EHLO, encryption, AUTH. The socket is null when it fails, with the code
	 * of the failure in $result.
	 *
	 * @param array{status:string,code:string,detail:string,extensions:string[]} $result Result (code, extensions)
	 * @return resource|null
	 */
	protected function smtpOpen(array &$result)
	{
		$account = $this->account;
		$host = $account->getSmtpHost();
		$encryption = (string) $account->smtp_encryption;
		$result = array('status' => 'error', 'code' => '', 'detail' => '', 'extensions' => array());
		if ($host === '') {
			$result['code'] = 'smtp_no_server';
			return null;
		}
		$login = $account->getSmtpLogin();
		if ($account->imap_auth_mode === InboxAccount::AUTH_OAUTH) {
			$error = '';
			$secret = InboxOAuth::getAccessToken((string) $account->oauth_service, $error);
		} elseif ($account->imap_auth_mode === InboxAccount::AUTH_MASTER && !empty($account->smtp_same_credentials)) {
			// The master user of Dovecot is not a login for the SMTP server
			$result['status'] = 'warning';
			$result['code'] = 'smtp_master';
			return null;
		} else {
			$secret = $account->getSmtpPassword();
		}
		if ($secret === '') {
			$result['code'] = 'smtp_no_password';
			return null;
		}
		$error = '';
		$socket = $this->openSocket($host, (int) $account->smtp_port, $encryption, $error);
		if (!$socket) {
			$result['code'] = $error === 'certificate' ? 'smtp_certificate' : 'smtp_connect';
			$result['detail'] = $error === 'certificate' ? '' : $error;
			return null;
		}
		$greeting = $this->smtpRead($socket);
		if ($greeting['code'] !== 220) {
			$result['code'] = 'smtp_greeting';
			$result['detail'] = implode(' ', $greeting['lines']);
			fclose($socket);
			return null;
		}
		$ehloName = preg_replace('/[^a-z0-9.\-]/i', '', (string) (gethostname() ?: 'localhost'));
		$ehlo = $this->smtpCommand($socket, 'EHLO '.$ehloName);
		if ($encryption === 'tls') {
			if (!preg_grep('/^250[ -]STARTTLS/i', $ehlo['lines'])) {
				$result['code'] = 'smtp_no_starttls';
				fclose($socket);
				return null;
			}
			$start = $this->smtpCommand($socket, 'STARTTLS');
			if ($start['code'] !== 220 || !$this->startTls($socket)) {
				$result['code'] = 'smtp_certificate';
				fclose($socket);
				return null;
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
			@fwrite($socket, "QUIT\r\n");
			fclose($socket);
			return null;
		}
		if ($answer['code'] !== 235) {
			$result['code'] = 'smtp_auth';
			@fwrite($socket, "QUIT\r\n");
			fclose($socket);
			return null;
		}
		$result['status'] = 'ok';
		$result['code'] = 'smtp_ok';

		return $socket;
	}

	/**
	 * SMTP test: EHLO, encryption, AUTH; no mail is sent
	 *
	 * @return array{status:string,code:string,detail:string,extensions:string[]}
	 */
	public function testSmtp()
	{
		$result = array();
		$socket = $this->smtpOpen($result);
		if ($socket) {
			@fwrite($socket, "QUIT\r\n");
			fclose($socket);
		}

		return $result;
	}

	/**
	 * Send a mail by the SMTP server of the mailbox (lot W6 1.16.0). The copy in the sent folder is put by the caller
	 * (importRaw), as the source kept there has the Bcc that the one sent has not.
	 *
	 * @param string                                  $raw      Source to send (no Bcc header)
	 * @param array{from?:string,recipients?:string[]} $envelope Envelope: sender and every recipient (To, Cc, Bcc)
	 * @return bool
	 */
	public function send($raw, array $envelope = array())
	{
		$this->error = '';
		$this->errorCode = '';
		$from = (string) ($envelope['from'] ?? '');
		$recipients = array_values(array_filter((array) ($envelope['recipients'] ?? array()), function ($r) {
			return is_string($r) && filter_var($r, FILTER_VALIDATE_EMAIL) && !preg_match('/[\r\n<>]/', $r);
		}));
		if ((string) $raw === '' || !filter_var($from, FILTER_VALIDATE_EMAIL) || !$recipients) {
			$this->error = 'Invalid mail';
			$this->errorCode = 'smtp_invalid';
			return false;
		}
		$result = array();
		$socket = $this->smtpOpen($result);
		if (!$socket) {
			$this->errorCode = $result['code'];
			$this->error = $result['code'].($result['detail'] !== '' ? ': '.$result['detail'] : '');
			return false;
		}
		try {
			$size = strlen($raw);
			foreach ($result['extensions'] as $ext) {
				if (preg_match('/^SIZE (\d+)/', $ext, $m) && (int) $m[1] > 0 && $size > (int) $m[1]) {
					$this->errorCode = 'smtp_too_big';
					$this->error = 'smtp_too_big: '.$size.' > '.$m[1];
					return false;
				}
			}
			$eight = preg_match('/[\x80-\xFF]/', $raw) && in_array('8BITMIME', $result['extensions'], true) ? ' BODY=8BITMIME' : '';
			$answer = $this->smtpCommand($socket, 'MAIL FROM:<'.$from.'>'.$eight);
			if ($answer['code'] !== 250) {
				$this->errorCode = 'smtp_refused';
				$this->error = 'MAIL FROM: '.implode(' ', $answer['lines']);
				return false;
			}
			$refused = array();
			foreach ($recipients as $r) {
				$answer = $this->smtpCommand($socket, 'RCPT TO:<'.$r.'>');
				if ($answer['code'] !== 250 && $answer['code'] !== 251) {
					$refused[] = $r.' ('.implode(' ', $answer['lines']).')';
				}
			}
			// One recipient refused: nothing goes (the user corrects the address, the others do not get it twice)
			if ($refused) {
				$this->smtpCommand($socket, 'RSET');
				$this->errorCode = 'smtp_recipient';
				$this->error = 'RCPT TO: '.implode(', ', $refused);
				return false;
			}
			$answer = $this->smtpCommand($socket, 'DATA');
			if ($answer['code'] !== 354) {
				$this->errorCode = 'smtp_refused';
				$this->error = 'DATA: '.implode(' ', $answer['lines']);
				return false;
			}
			// Lines ended by CRLF, a lone CR too (audit INBX-013: « CR . CR LF » is never an end of data), a line
			// starting with a dot doubled (RFC 5321 4.5.2)
			$data = preg_replace("/\r\n|\r|\n/", "\r\n", (string) $raw);
			$data = preg_replace('/^\./m', '..', $data);
			if (substr($data, -2) !== "\r\n") {
				$data .= "\r\n";
			}
			for ($i = 0, $n = strlen($data); $i < $n; $i += 65536) {
				if (fwrite($socket, substr($data, $i, 65536)) === false) {
					$this->errorCode = 'smtp_connect';
					$this->error = 'Connection lost during the sending';
					return false;
				}
			}
			$answer = $this->smtpCommand($socket, '.');
			if ($answer['code'] !== 250) {
				$this->errorCode = 'smtp_refused';
				$this->error = 'DATA: '.implode(' ', $answer['lines']);
				return false;
			}
		} finally {
			@fwrite($socket, "QUIT\r\n");
			fclose($socket);
		}

		return true;
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
	 * Session on the rules of the mailbox (lot W8 1.23.0): ManageSieve opened, STARTTLS (never a password in clear
	 * outside this server), authenticated. The caller closes it.
	 *
	 * @param string $error Code of the error (sieve_disabled, sieve_connect, sieve_tls, sieve_no_tls, sieve_auth...)
	 * @return InboxManageSieve|null
	 */
	public function sieveSession(&$error = '')
	{
		require_once __DIR__.'/inboxmanagesieve.class.php';
		$account = $this->account;
		if ((int) $account->sieve_port <= 0) {
			$error = 'sieve_disabled';
			return null;
		}
		$host = trim((string) $account->imap_host);
		$detail = '';
		$socket = $this->openSocket($host, (int) $account->sieve_port, 'none', $detail);
		if (!$socket) {
			$error = 'sieve_connect';
			$this->error = $detail;
			return null;
		}
		$caps = $this->sieveRead($socket);
		if (preg_grep('/^"STARTTLS"/i', $caps['lines'])) {
			fwrite($socket, "STARTTLS\r\n");
			$answer = $this->sieveRead($socket);
			if ($answer['status'] !== 'OK' || !$this->startTls($socket)) {
				fclose($socket);
				$error = 'sieve_tls';
				return null;
			}
			$caps = $this->sieveRead($socket);
		} elseif (!InboxAccount::isLocalHost($host)) {
			fclose($socket);
			$error = 'sieve_no_tls';
			return null;
		}
		$extensions = array();
		foreach ($caps['lines'] as $line) {
			if (preg_match('/^"SIEVE"\s+"([^"]*)"/i', $line, $m)) {
				$extensions = preg_split('/\s+/', trim($m[1]));
			}
		}
		$secret = $this->getSecret();
		if ($secret === '') {
			fclose($socket);
			$error = 'sieve_no_password';
			return null;
		}
		$login = $this->getLogin();
		$mechanism = $account->imap_auth_mode === InboxAccount::AUTH_OAUTH ? 'XOAUTH2' : 'PLAIN';
		$payload = $mechanism === 'XOAUTH2' ? 'user='.$login."\1auth=Bearer ".$secret."\1\1" : "\0".$login."\0".$secret;
		fwrite($socket, 'AUTHENTICATE "'.$mechanism.'" "'.base64_encode($payload).'"'."\r\n");
		$auth = $this->sieveRead($socket);
		if ($auth['status'] !== 'OK') {
			fclose($socket);
			$error = 'sieve_auth';
			return null;
		}

		return new InboxManageSieve($socket, $extensions);
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

	/** Header fields read by the synchronisation (never the body) */
	const SYNC_HEADERS = 'FROM TO CC REPLY-TO SUBJECT DATE MESSAGE-ID IN-REPLY-TO REFERENCES CONTENT-TYPE';

	/** Mails read per FETCH */
	const FETCH_CHUNK = 200;

	/**
	 * @param string $folder Folder path
	 * @return array{total:int,unread:int,changed:bool,token:string,uidvalidity:int,uidnext:int,modseq:string}|null
	 */
	public function folderStatus($folder)
	{
		if (!self::isSafeFolderPath($folder) || !$this->connect()) {
			return null;
		}
		$items = in_array('CONDSTORE', $this->serverCapabilities, true) ? '(MESSAGES UIDNEXT UIDVALIDITY UNSEEN HIGHESTMODSEQ)' : '(MESSAGES UIDNEXT UIDVALIDITY UNSEEN)';
		try {
			// STATUS of the folder open in this connection can answer old numbers: close it first (it was examined,
			// read only: CLOSE removes nothing)
			if ($this->examined !== '') {
				$this->client->getConnection()->requestAndResponse(in_array('UNSELECT', $this->serverCapabilities, true) ? 'UNSELECT' : 'CLOSE');
				$this->examined = '';
			}
			$response = $this->client->getConnection()->requestAndResponse('STATUS', array($this->client->getConnection()->escapeString($folder), $items));
		} catch (Exception $e) {
			$this->error = $this->explainException($e);
			return null;
		}
		foreach (is_array($response) ? $response : array() as $line) {
			if (is_array($line) && isset($line[0], $line[2]) && $line[0] === 'STATUS' && is_array($line[2])) {
				$v = array();
				for ($i = 0; $i + 1 < count($line[2]); $i += 2) {
					$v[strtoupper((string) $line[2][$i])] = (string) $line[2][$i + 1];
				}
				$token = ($v['UIDVALIDITY'] ?? '').':'.($v['UIDNEXT'] ?? '').':'.($v['MESSAGES'] ?? '').':'.($v['HIGHESTMODSEQ'] ?? '').':'.($v['UNSEEN'] ?? '');
				return array('total' => (int) ($v['MESSAGES'] ?? 0), 'unread' => (int) ($v['UNSEEN'] ?? 0), 'changed' => true, 'token' => $token,
					'uidvalidity' => (int) ($v['UIDVALIDITY'] ?? 0), 'uidnext' => (int) ($v['UIDNEXT'] ?? 0), 'modseq' => (string) ($v['HIGHESTMODSEQ'] ?? ''));
			}
		}
		$this->error = 'STATUS without answer';

		return null;
	}

	/**
	 * @param string              $folder  Folder path
	 * @param string              $cursor  Cursor
	 * @param array<string,mixed> $options Options (see the interface)
	 * @return array<string,mixed>|null
	 */
	public function getChanges($folder, $cursor, array $options = array())
	{
		$this->error = '';
		$max = max(1, (int) ($options['max'] ?? 1000));
		$since = (int) ($options['since'] ?? 0);
		$status = $this->folderStatus($folder);
		if ($status === null) {
			return null;
		}
		$c = json_decode((string) $cursor, true);
		$c = is_array($c) ? $c : array();
		$out = array('cursor' => '', 'reset' => false, 'new' => array(), 'changed' => array(), 'deleted' => array(), 'more' => false,
			'total' => $status['total'], 'unread' => $status['unread']);
		// Nothing changed since the last call, and the first indexing is done: nothing to read
		if (!empty($c['token']) && $c['token'] === $status['token'] && !empty($c['done'])) {
			$out['cursor'] = (string) $cursor;
			return $out;
		}
		if (!empty($c) && (int) ($c['v'] ?? 0) !== $status['uidvalidity']) {
			// The server numbered the mails of this folder again: the index of the folder is rebuilt
			$out['reset'] = true;
			$c = array();
		}
		$condstore = in_array('CONDSTORE', $this->serverCapabilities, true) && $status['modseq'] !== '';
		try {
			$conn = $this->client->getConnection();
			if ($condstore) {
				$conn->requestAndResponse('ENABLE', array('CONDSTORE'));
			}
			// Always examined again: a folder examined earlier in this connection would not show the new mails
			$this->examined = '';
			if (!$this->examine($folder)) {
				$this->error = 'Folder not found';
				return null;
			}
			$v = $status['uidvalidity'];
			$read = 0;
			if (empty($c)) {
				// First call: every UID of the folder, the most recent read first
				$all = $this->searchUids($since > 0 ? array('SINCE', date('d-M-Y', $since)) : array('ALL'));
				rsort($all);
				$take = array_slice($all, 0, $max);
				$out['new'] = $this->fetchMails($take, $v);
				$read = count($take);
				$c = array('v' => $v, 'next' => $status['uidnext'], 'low' => $take ? min($take) : $status['uidnext'], 'done' => count($all) <= $max, 'modseq' => $status['modseq']);
			} else {
				// New mails since the last call
				if ($status['uidnext'] > (int) $c['next']) {
					$uids = $this->searchUids(array('UID', ((int) $c['next']).':*'));
					$uids = array_values(array_filter($uids, function ($u) use ($c) {
						return $u >= (int) $c['next'];
					}));
					sort($uids);
					$take = array_slice($uids, 0, $max);
					$out['new'] = $this->fetchMails($take, $v);
					$read = count($take);
					if (count($uids) > $max) {
						// Budget reached: the next call goes on from the first mail not read
						$c['next'] = max($take) + 1;
						$out['more'] = true;
					} else {
						$c['next'] = $status['uidnext'];
					}
				}
				// Flags changed elsewhere
				if ($condstore && !empty($c['modseq']) && $c['modseq'] !== $status['modseq']) {
					$out['changed'] = $this->fetchFlags(((int) $c['low']).':*', $v, $c['modseq']);
				} elseif (!$condstore && !empty($options['flags'])) {
					$out['changed'] = $this->fetchFlags(((int) $c['low']).':*', $v, '');
				}
				$c['modseq'] = $status['modseq'];
				// Older mails not indexed yet (first indexing), within what is left of the budget
				if (empty($c['done']) && !empty($options['backfill']) && $read < $max && (int) $c['low'] > 1) {
					$older = $this->searchUids(array('UID', '1:'.((int) $c['low'] - 1)));
					$older = array_values(array_filter($older, function ($u) use ($c) {
						return $u < (int) $c['low'];
					}));
					if ($since > 0) {
						$recent = $this->searchUids(array('SINCE', date('d-M-Y', $since)));
						$older = array_values(array_intersect($older, $recent));
					}
					rsort($older);
					$take = array_slice($older, 0, $max - $read);
					$out['new'] = array_merge($out['new'], $this->fetchMails($take, $v));
					if ($take) {
						$c['low'] = min($take);
					}
					$c['done'] = count($older) <= count($take);
				} elseif (empty($c['done']) && (int) $c['low'] <= 1) {
					$c['done'] = true;
				}
				// Mails removed or moved elsewhere: the index is compared only when the numbers differ
				if (isset($options['known']) && is_callable($options['known'])) {
					// indexed: number of mails of the folder in the index; the list is asked only when it differs
					$expected = (int) ($options['indexed'] ?? -1) + count($out['new']);
					$present = $this->searchUids(array('UID', ((int) $c['low']).':*'));
					$present = array_values(array_filter($present, function ($u) use ($c) {
						return $u >= (int) $c['low'];
					}));
					if ($expected !== count($present)) {
						$known = call_user_func($options['known']);
						$here = array_flip(array_map(function ($u) use ($v) {
							return $v.':'.$u;
						}, $present));
						foreach ($known as $remoteId) {
							if (!isset($here[$remoteId])) {
								$out['deleted'][] = $remoteId;
							}
						}
					}
				}
			}
			if (empty($c['done'])) {
				// Mails left to index (first indexing not finished)
				$out['more'] = true;
			}
		} catch (Exception $e) {
			$this->error = $this->explainException($e);
			return null;
		}
		$c['token'] = $status['token'];
		$out['cursor'] = (string) json_encode($c);

		return $out;
	}

	/**
	 * UIDs answered by a UID SEARCH in the examined folder
	 *
	 * @param array<int,string> $criteria Criteria (ALL, SINCE date, UID set)
	 * @return int[]
	 */
	protected function searchUids(array $criteria)
	{
		$response = $this->client->getConnection()->requestAndResponse('UID SEARCH', $criteria);
		$uids = array();
		foreach (is_array($response) ? $response : array() as $line) {
			if (is_array($line) && isset($line[0]) && $line[0] === 'SEARCH') {
				foreach (array_slice($line, 1) as $u) {
					if (is_string($u) && ctype_digit($u)) {
						$uids[] = (int) $u;
					}
				}
			}
		}

		return $uids;
	}

	/**
	 * Headers, flags, dates, size, structure of mails, read only, by chunks
	 *
	 * @param int[] $uids        UIDs in the examined folder
	 * @param int   $uidvalidity Uidvalidity of the folder
	 * @return array<int,array<string,mixed>> Common format (InboxHeaderParser + remote_id, sort_key, flags...)
	 */
	protected function fetchMails(array $uids, $uidvalidity)
	{
		$out = array();
		$preview = in_array('PREVIEW', $this->serverCapabilities, true) ? ' PREVIEW' : '';
		foreach (array_chunk($uids, self::FETCH_CHUNK) as $chunk) {
			$set = implode(',', array_map('intval', $chunk));
			// Decoded by inbox: Webklex merges the sub parts of a BODYSTRUCTURE written « NIL)( » (attachments not seen)
			$response = $this->fetchDecoded($set, '(UID FLAGS INTERNALDATE RFC822.SIZE BODYSTRUCTURE'.$preview.' BODY.PEEK[HEADER.FIELDS ('.self::SYNC_HEADERS.')])');
			foreach ($this->fetchItems($response) as $item) {
				if (empty($item['UID'])) {
					continue;
				}
				$mail = InboxHeaderParser::parse((string) ($item['HEADER'] ?? ''), isset($item['BODYSTRUCTURE']) && is_array($item['BODYSTRUCTURE']) ? $item['BODYSTRUCTURE'] : null);
				$mail['remote_id'] = $uidvalidity.':'.((int) $item['UID']);
				$mail['sort_key'] = (int) $item['UID'];
				$mail['date_received'] = !empty($item['INTERNALDATE']) ? (int) strtotime((string) $item['INTERNALDATE']) : (int) $mail['date_mail'];
				$mail['size'] = (int) ($item['RFC822.SIZE'] ?? 0);
				$mail['snippet'] = isset($item['PREVIEW']) && is_string($item['PREVIEW']) ? InboxHeaderParser::bound($item['PREVIEW'], 200) : '';
				$out[] = $mail + self::flagFields(isset($item['FLAGS']) ? (array) $item['FLAGS'] : array());
			}
		}

		return $out;
	}

	/**
	 * UID FETCH sent by Webklex, response read and decoded by InboxImapResponse
	 *
	 * @param string $set   UID set
	 * @param string $items Items asked, in parentheses
	 * @return array<int,array<int,mixed>>|null Untagged responses, null if refused
	 */
	protected function fetchDecoded($set, $items)
	{
		$conn = $this->client->getConnection();
		$tag = null;
		$conn->sendRequest('UID FETCH', array($set, $items), $tag);

		return InboxImapResponse::read($conn, (string) $tag);
	}

	/**
	 * Attachments of indexed mails read again from their structure (repair of the index of 1.7.1), read only
	 *
	 * @param string $folder    Folder path
	 * @param string[] $remoteIds "uidvalidity:uid" of the index
	 * @return array<string,int>|null remote_id => 1 / 0 (mails gone or of another uidvalidity left out), null if KO
	 */
	public function attachmentFlags($folder, array $remoteIds)
	{
		$this->error = '';
		if (!self::isSafeFolderPath($folder) || !$this->connect()) {
			return null;
		}
		$out = array();
		try {
			if (!$this->examine($folder)) {
				$this->error = 'Folder not found';
				return null;
			}
			$uids = array();
			foreach ($remoteIds as $rid) {
				if (preg_match('/^(\d+):(\d+)$/', (string) $rid, $m) && (int) $m[1] === $this->examinedValidity) {
					$uids[] = (int) $m[2];
				}
			}
			foreach (array_chunk($uids, self::FETCH_CHUNK) as $chunk) {
				$response = $this->fetchDecoded(implode(',', $chunk), '(UID BODYSTRUCTURE)');
				foreach ($this->fetchItems($response) as $item) {
					if (!empty($item['UID']) && isset($item['BODYSTRUCTURE']) && is_array($item['BODYSTRUCTURE'])) {
						$out[$this->examinedValidity.':'.((int) $item['UID'])] = InboxHeaderParser::hasAttachments($item['BODYSTRUCTURE']) ? 1 : 0;
					}
				}
			}
		} catch (Throwable $e) {
			$this->error = $e->getMessage();
			return null;
		}

		return $out;
	}

	/**
	 * Flags of mails (changed since a modseq with CONDSTORE)
	 *
	 * @param string $set         UID set
	 * @param int    $uidvalidity Uidvalidity
	 * @param string $modseq      Changed since this modseq, '' for all
	 * @return array<int,array<string,mixed>> remote_id + flag fields
	 */
	protected function fetchFlags($set, $uidvalidity, $modseq)
	{
		$args = array($set, '(UID FLAGS)');
		if ($modseq !== '') {
			$args[] = '(CHANGEDSINCE '.preg_replace('/\D/', '', $modseq).')';
		}
		$out = array();
		foreach ($this->fetchItems($this->client->getConnection()->requestAndResponse('UID FETCH', $args)) as $item) {
			if (!empty($item['UID'])) {
				$out[] = array('remote_id' => $uidvalidity.':'.((int) $item['UID'])) + self::flagFields(isset($item['FLAGS']) ? (array) $item['FLAGS'] : array());
			}
		}

		return $out;
	}

	/**
	 * Items of the FETCH answers: name => value; the header literal under HEADER (the library cuts the name
	 * BODY[HEADER.FIELDS (...)] in pieces, the literal is the last string of the list that follows)
	 *
	 * @param mixed $response Answer of requestAndResponse()
	 * @return array<int,array<string,mixed>>
	 */
	protected function fetchItems($response)
	{
		$out = array();
		foreach (is_array($response) ? $response : array() as $line) {
			if (!is_array($line) || !isset($line[1], $line[2]) || $line[1] !== 'FETCH' || !is_array($line[2])) {
				continue;
			}
			$t = $line[2];
			$item = array();
			$n = count($t);
			for ($i = 0; $i < $n; $i++) {
				$name = is_string($t[$i]) ? strtoupper($t[$i]) : '';
				if (strpos($name, 'BODY[HEADER') === 0) {
					$value = $t[$i + 1] ?? null;
					if (is_string($value)) {
						$item['HEADER'] = $value;
					} elseif (is_array($value)) {
						$last = end($value);
						$item['HEADER'] = is_string($last) ? $last : '';
					}
					$i++;
				} elseif ($name !== '' && $i + 1 < $n) {
					$item[$name] = $t[$i + 1];
					$i++;
				}
			}
			$out[] = $item;
		}

		return $out;
	}

	/**
	 * @param array<int,mixed> $flags Flags and keywords of IMAP
	 * @return array{seen:int,answered:int,flagged:int,draft:int,keywords:string[]}
	 */
	public static function flagFields(array $flags)
	{
		$out = array('seen' => 0, 'answered' => 0, 'flagged' => 0, 'draft' => 0, 'keywords' => array());
		foreach ($flags as $f) {
			if (!is_string($f)) {
				continue;
			}
			$l = strtolower($f);
			if ($l === '\\seen') {
				$out['seen'] = 1;
			} elseif ($l === '\\answered') {
				$out['answered'] = 1;
			} elseif ($l === '\\flagged') {
				$out['flagged'] = 1;
			} elseif ($l === '\\draft') {
				$out['draft'] = 1;
			} elseif ($f !== '' && $f[0] !== '\\' && preg_match('/^[\x21-\x7E]{1,64}$/', $f)) {
				$out['keywords'][] = $f;
			}
		}

		return $out;
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
		$this->errorCode = 'connect';
		foreach ($warnings as $warning) {
			if (stripos($warning, 'certificate verify failed') !== false) {
				$this->errorCode = 'certificate';
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
			$this->errorCode = 'auth';
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
