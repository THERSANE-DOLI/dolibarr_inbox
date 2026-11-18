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
 * \file    inbox/class/inbox.class.php
 * \ingroup inbox
 * \brief   Entry point of inbox: mail read by its Message-ID (cache, rights, journal), mails given as files parsed
 */

require_once __DIR__.'/inboxmessage.class.php';
require_once __DIR__.'/inboxmimeparser.class.php';
require_once __DIR__.'/inboxmsgparser.class.php';
require_once __DIR__.'/inboxaccount.class.php';
require_once __DIR__.'/connector/inboxconnectorfactory.class.php';

/**
 * Entry point of inbox for the other modules.
 *
 * Reading a mail by its Message-ID: knowing the exact Message-ID is the access (long identifier, cannot be guessed:
 * whoever has it had the mail in front of them). So there is no listing and no search by sender, subject or date, the
 * Message-ID must be complete and is compared exactly, and every reading is written in the journal.
 */
class Inbox
{
	/** Results written in the journal */
	const RESULT_FOUND = 'found';
	const RESULT_NOT_FOUND = 'notfound';
	const RESULT_FORBIDDEN = 'forbidden';
	const RESULT_INVALID = 'invalid';
	const RESULT_ERROR = 'error';

	/** Shortest Message-ID accepted: no reading by a short piece of identifier */
	const MIN_MESSAGE_ID_LENGTH = 10;

	/** @var DoliDB */
	protected $db;

	/** @var string */
	public $error = '';

	/** @var string Result of the last reading (see RESULT_*) */
	public $result = '';

	/** @var string Folder of the last mail read */
	public $folder = '';

	/** @var bool Last mail read from the cache */
	public $fromCache = false;

	/** @var string Output of the scheduled job */
	public $output = '';

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Message-ID as compared: without angle brackets and spaces, visible ASCII only, no quote nor backslash (sent as is
	 * in the IMAP search)
	 *
	 * @param string $messageId Message-ID as given (with or without angle brackets)
	 * @return string '' if not acceptable
	 */
	public static function normalizeMessageId($messageId)
	{
		$messageId = trim((string) $messageId, " <>\t\r\n");
		if (strlen($messageId) < self::MIN_MESSAGE_ID_LENGTH || strlen($messageId) > 250 || strpos($messageId, '@') === false
			|| !preg_match('/^[\x21-\x7E]+$/', $messageId) || preg_match('/["\\\\<>]/', $messageId)) {
			return '';
		}

		return $messageId;
	}

	/**
	 * Raw mail read in a mailbox by its Message-ID, from the cache when it was read lately
	 *
	 * @param string $mailbox   Email address of the mailbox (an active mailbox of inbox, else given by another module through the hook inboxResolveAccount)
	 * @param string $messageId Message-ID, complete
	 * @param User   $user      User asking (right inbox > message > read)
	 * @param string $origin    Module or screen asking, written in the journal
	 * @param bool   $system    Call of a scheduled job of a module, without user rights
	 * @return string|null Raw mail, null on failure (see error and result)
	 */
	public function fetchRaw($mailbox, $messageId, $user, $origin = '', $system = false)
	{
		global $conf;

		$this->error = '';
		$this->folder = '';
		$this->fromCache = false;
		$mailbox = strtolower(trim((string) $mailbox));
		$normalized = self::normalizeMessageId($messageId);
		$logId = $normalized !== '' ? $normalized : dol_trunc(trim((string) $messageId), 250, 'right', 'UTF-8', 1);

		if (!$system && (!is_object($user) || !$user->hasRight('inbox', 'message', 'read'))) {
			return $this->finish(self::RESULT_FORBIDDEN, 'Not allowed to read mails', $user, $origin, $mailbox, $logId);
		}
		if ($normalized === '' || !isValidEmail($mailbox)) {
			return $this->finish(self::RESULT_INVALID, 'Invalid mailbox or Message-ID', $user, $origin, $mailbox, $logId);
		}

		$cached = $this->db->getRow("SELECT rowid, folder, filepath FROM ".$this->db->prefix()."inbox_message"
			." WHERE entity = ".((int) $conf->entity)." AND mailbox = '".$this->db->escape($mailbox)."'"
			." AND message_id = '".$this->db->escape($normalized)."'");
		if (is_object($cached)) {
			$path = self::storageDir().'/'.basename((string) $cached->filepath);
			if (is_readable($path)) {
				$this->folder = (string) $cached->folder;
				$this->fromCache = true;
				$raw = (string) file_get_contents($path);
				$this->finish(self::RESULT_FOUND, '', $user, $origin, $mailbox, $normalized);
				return $raw;
			}
			// File purged or lost: read again
			$this->db->query("DELETE FROM ".$this->db->prefix()."inbox_message WHERE rowid = ".((int) $cached->rowid));
		}

		$account = $this->resolveAccount($mailbox);
		if ($account === null) {
			return $this->finish(self::RESULT_INVALID, 'Mailbox unknown', $user, $origin, $mailbox, $normalized);
		}
		$error = '';
		$connector = InboxConnectorFactory::forAccount($this->db, $account, $error);
		if (!$connector) {
			return $this->finish(self::RESULT_ERROR, $error, $user, $origin, $mailbox, $normalized);
		}
		$found = $connector->findByMessageId($normalized);
		$connector->disconnect();
		if ($found === null) {
			if ($connector->notFound()) {
				return $this->finish(self::RESULT_NOT_FOUND, 'Mail not found in the mailbox', $user, $origin, $mailbox, $normalized);
			}
			return $this->finish(self::RESULT_ERROR, $connector->getError(), $user, $origin, $mailbox, $normalized);
		}

		$this->folder = $found['folder'];
		$this->store($mailbox, $normalized, $found, $user);
		$this->finish(self::RESULT_FOUND, '', $user, $origin, $mailbox, $normalized);

		return $found['raw'];
	}

	/**
	 * Mailbox of an address for the reading by Message-ID: an active mailbox of inbox first, else the one given by
	 * another module (hook inboxResolveAccount, context "inbox": its method puts an InboxAccount, not saved, with the
	 * address asked, in $this->results['account'] and returns 1). Knowing the Message-ID is the access: no access
	 * per mailbox here, the right "read a mail from its Message-ID" and the journal apply.
	 *
	 * @param string $mailbox Email address, lower case
	 * @return InboxAccount|null
	 */
	public function resolveAccount($mailbox)
	{
		global $hookmanager;

		$account = new InboxAccount($this->db);
		if ($account->fetchByEmail($mailbox) > 0 && $account->isActive()) {
			// Protected mailbox (1.89.0): not given to the other modules (no copy of its mails out of the webmail), nor
			// to the hook (another module could open the same address with its own password)
			return $account->canWorkNow() ? $account : null;
		}
		if ($account->id > 0 && !$account->canWorkNow()) {
			return null;
		}
		if (!is_object($hookmanager)) {
			include_once DOL_DOCUMENT_ROOT.'/core/class/hookmanager.class.php';
			$hookmanager = new HookManager($this->db);
		}
		$hookmanager->initHooks(array('inbox'));
		$parameters = array('mailbox' => $mailbox);
		$action = '';
		$object = null;
		$reshook = $hookmanager->executeHooks('inboxResolveAccount', $parameters, $object, $action);
		$given = ($reshook >= 0 && isset($hookmanager->resArray['account'])) ? $hookmanager->resArray['account'] : null;
		if ($given instanceof InboxAccount && strtolower(trim((string) $given->email)) === $mailbox) {
			return $given;
		}

		return null;
	}

	/**
	 * Mailboxes readable by Message-ID from the setup: the active mailboxes of inbox
	 *
	 * @return array<int,array{id:int,email:string,label:string}>
	 */
	public function listMailboxes()
	{
		global $conf;

		$out = array();
		$rows = $this->db->getRows("SELECT rowid, email, label FROM ".$this->db->prefix()."inbox_account WHERE entity = ".((int) $conf->entity)
			." AND status = ".InboxAccount::STATUS_ACTIVE." ORDER BY email LIMIT 1000");
		foreach (is_array($rows) ? $rows : array() as $row) {
			$out[] = array('id' => (int) $row->rowid, 'email' => (string) $row->email, 'label' => (string) $row->label);
		}

		return $out;
	}

	/**
	 * Mail read in a mailbox by its Message-ID, parsed
	 *
	 * @param string $mailbox   Email address of the mailbox
	 * @param string $messageId Message-ID, complete
	 * @param User   $user      User asking
	 * @param string $origin    Module or screen asking
	 * @param bool   $system    Call of a scheduled job of a module
	 * @return InboxMessage|null
	 */
	public function getMessage($mailbox, $messageId, $user, $origin = '', $system = false)
	{
		$raw = $this->fetchRaw($mailbox, $messageId, $user, $origin, $system);

		return $raw === null ? null : $this->parseRaw($raw);
	}

	/**
	 * @param string $raw RFC 822 source
	 * @return InboxMessage
	 */
	public function parseRaw($raw)
	{
		return InboxMimeParser::parse($raw);
	}

	/**
	 * Mail given as a file content: Outlook .msg or RFC 822 (.eml), told apart by the content
	 *
	 * @param string $content Content of the file
	 * @return InboxMessage|null Null if it is not a mail
	 */
	public function parseContent($content)
	{
		$this->error = '';
		if (InboxMimeParser::kind($content) === 'ole') {
			$message = InboxMsgParser::parse($content);
			if ($message === null) {
				$this->error = InboxMsgParser::$error;
			}
			return $message;
		}
		if (!InboxMimeParser::isMessage($content)) {
			$this->error = 'Not a mail';
			return null;
		}

		return InboxMimeParser::parse($content);
	}

	/**
	 * Mail given as a file (.eml or .msg)
	 *
	 * @param string $path Path of the file
	 * @return InboxMessage|null
	 */
	public function getMessageFromFile($path)
	{
		if (!is_readable($path) || filesize($path) > InboxCfbReader::MAX_FILE_SIZE) {
			$this->error = 'File not readable or too large';
			return null;
		}

		return $this->parseContent((string) file_get_contents($path));
	}

	/**
	 * @return string Directory of the raw mails of the cache
	 */
	public static function storageDir()
	{
		global $conf;

		$root = !empty($conf->inbox->dir_output) ? $conf->inbox->dir_output : DOL_DATA_ROOT.'/inbox';

		return $root.'/raw';
	}

	/**
	 * Raw mail put in the cache
	 *
	 * @param string                                     $mailbox   Mailbox
	 * @param string                                     $messageId Message-ID
	 * @param array{raw:string,folder:string,uid:int}    $found     Mail read
	 * @param User|null                                  $user      User asking
	 * @return void
	 */
	protected function store($mailbox, $messageId, array $found, $user)
	{
		global $conf;

		$dir = self::storageDir();
		if (!is_dir($dir) && dol_mkdir($dir) < 0) {
			dol_syslog(__METHOD__.' cannot create '.$dir, LOG_WARNING);
			return;
		}
		self::writeReadme($dir);
		$sha1 = sha1($found['raw']);
		$file = $sha1.'.eml';
		// Never a warning in the answer: a cache not written only means a reading on the server next time
		if (@file_put_contents($dir.'/'.$file, $found['raw']) === false) {
			dol_syslog(__METHOD__.' cannot write '.$dir.'/'.$file, LOG_WARNING);
			return;
		}
		dolChmod($dir.'/'.$file);
		// Column uid of the lot 0 cache: the UID part of the opaque identifier of the IMAP connector, 0 for others
		$uid = isset($found['uid']) ? (int) $found['uid'] : (preg_match('/^\d+:(\d+)$/', (string) (isset($found['remote_id']) ? $found['remote_id'] : ''), $m) ? (int) $m[1] : 0);
		$header = InboxMimeParser::parse(substr($found['raw'], 0, (int) (strpos($found['raw'], "\r\n\r\n") ?: 65536))."\r\n\r\n");
		$sql = "INSERT INTO ".$this->db->prefix()."inbox_message (entity, mailbox, folder, uid, message_id, sha1, date_mail, from_email, subject, filepath, size, date_fetch, fk_user_fetch)"
			." VALUES (".((int) $conf->entity).", '".$this->db->escape($mailbox)."', '".$this->db->escape(dol_trunc($found['folder'], 250, 'right', 'UTF-8', 1))."',"
			." ".$uid.", '".$this->db->escape($messageId)."', '".$this->db->escape($sha1)."',"
			." ".($header->date > 0 ? "'".$this->db->idate($header->date)."'" : "NULL").","
			." '".$this->db->escape(dol_trunc($header->fromEmail, 250, 'right', 'UTF-8', 1))."',"
			." '".$this->db->escape(dol_trunc($header->subject, 250, 'right', 'UTF-8', 1))."',"
			." '".$this->db->escape($file)."', ".strlen($found['raw']).", '".$this->db->idate(dol_now())."',"
			." ".(is_object($user) && !empty($user->id) ? (int) $user->id : "NULL").")";
		if (!$this->db->query($sql)) {
			dol_syslog(__METHOD__.' '.$this->db->lasterror(), LOG_WARNING);
		}
	}

	/**
	 * Raw mail kept in the cache (reading in the application, lot W3: no journal, the rights per mailbox apply)
	 *
	 * @param string $mailbox   Email address of the mailbox
	 * @param string $messageId Message-ID
	 * @return string|null Raw mail, null if not in the cache
	 */
	public function cachedRaw($mailbox, $messageId)
	{
		global $conf;

		$normalized = self::normalizeMessageId($messageId);
		if ($normalized === '') {
			return null;
		}
		$cached = $this->db->getRow("SELECT rowid, filepath FROM ".$this->db->prefix()."inbox_message"
			." WHERE entity = ".((int) $conf->entity)." AND mailbox = '".$this->db->escape(strtolower(trim((string) $mailbox)))."'"
			." AND message_id = '".$this->db->escape($normalized)."'");
		if (!is_object($cached)) {
			return null;
		}
		$path = self::storageDir().'/'.basename((string) $cached->filepath);
		if (!is_readable($path)) {
			$this->db->query("DELETE FROM ".$this->db->prefix()."inbox_message WHERE rowid = ".((int) $cached->rowid));
			return null;
		}

		return (string) file_get_contents($path);
	}

	/**
	 * Keep a raw mail read in the application in the cache (not above 25 MB: read again each time)
	 *
	 * @param string $mailbox   Email address of the mailbox
	 * @param string $messageId Message-ID
	 * @param string $raw       Raw mail
	 * @param string $folder    Folder path
	 * @param string $remoteId  Identifier of the connector
	 * @param User   $user      User reading
	 * @return void
	 */
	public function remember($mailbox, $messageId, $raw, $folder, $remoteId, $user)
	{
		$normalized = self::normalizeMessageId($messageId);
		if ($normalized === '' || strlen((string) $raw) > 26214400) {
			return;
		}
		$this->store(strtolower(trim((string) $mailbox)), $normalized, array('raw' => (string) $raw, 'folder' => (string) $folder, 'remote_id' => (string) $remoteId), $user);
	}

	/**
	 * Whole cache emptied (setup page): the mails will be read again on the mail server when needed
	 *
	 * @return int Number of mails removed
	 */
	public function clearCache()
	{
		global $conf;

		$dir = self::storageDir();
		$count = 0;
		$rows = $this->db->getRows("SELECT rowid FROM ".$this->db->prefix()."inbox_message WHERE entity = ".((int) $conf->entity)." LIMIT 100000");
		$count = is_array($rows) ? count($rows) : 0;
		$this->db->query("DELETE FROM ".$this->db->prefix()."inbox_message WHERE entity = ".((int) $conf->entity));
		foreach (is_dir($dir) ? (array) glob($dir.'/*.eml') : array() as $path) {
			@unlink($path);
		}
		if (is_dir($dir)) {
			self::writeReadme($dir);
		}

		return $count;
	}

	/**
	 * @return array{count:int,size:int} Mails in the cache and their total size (bytes)
	 */
	public function cacheStats()
	{
		global $conf;

		$obj = $this->db->getRow("SELECT COUNT(rowid) as nb, SUM(size) as total FROM ".$this->db->prefix()."inbox_message WHERE entity = ".((int) $conf->entity));

		return array('count' => is_object($obj) ? (int) $obj->nb : 0, 'size' => is_object($obj) ? (int) $obj->total : 0);
	}

	/**
	 * README of the cache directory: whoever looks at it on the server knows it can be emptied
	 *
	 * @param string $dir Directory of the cache
	 * @return void
	 */
	public static function writeReadme($dir)
	{
		$file = $dir.'/README.txt';
		if (is_file($file)) {
			return;
		}
		$text = "Cache of the Inbox module (Dolibarr): raw mails read in the mailboxes by their Message-ID.\n"
			."This directory can be emptied at any time: a mail missing here is read again on the mail server when needed.\n"
			."It is purged every night (retention set in Setup > Modules > Inbox, 30 days by default).\n"
			."Do not put other files here: the .eml files no longer known by the module are removed.\n"
			."\n"
			."Cache du module Inbox (Dolibarr) : mails bruts lus dans les boîtes par leur Message-ID.\n"
			."Ce dossier peut être vidé à tout moment : un mail absent ici est relu sur le serveur de messagerie au besoin.\n"
			."Il est purgé chaque nuit (durée réglée dans Configuration > Modules > Inbox, 30 jours par défaut).\n"
			."Ne pas y mettre d'autres fichiers : les fichiers .eml que le module ne connaît plus sont supprimés.\n";
		if (@file_put_contents($file, $text) !== false) {
			dolChmod($file);
		}
	}

	/**
	 * Reading written in the journal
	 *
	 * @param string    $result    See RESULT_*
	 * @param string    $error     Error, '' if none
	 * @param User|null $user      User asking
	 * @param string    $origin    Module or screen asking
	 * @param string    $mailbox   Mailbox
	 * @param string    $messageId Message-ID
	 * @return null
	 */
	protected function finish($result, $error, $user, $origin, $mailbox, $messageId)
	{
		global $conf;

		$this->result = $result;
		$this->error = $error;
		$sql = "INSERT INTO ".$this->db->prefix()."inbox_readlog (entity, datec, fk_user, origin, mailbox, message_id, result, folder, from_cache, error)"
			." VALUES (".((int) $conf->entity).", '".$this->db->idate(dol_now())."', ".(is_object($user) && !empty($user->id) ? (int) $user->id : "NULL").","
			." '".$this->db->escape(dol_trunc((string) $origin, 32, 'right', 'UTF-8', 1))."',"
			." '".$this->db->escape(dol_trunc($mailbox, 250, 'right', 'UTF-8', 1))."',"
			." '".$this->db->escape(dol_trunc($messageId, 250, 'right', 'UTF-8', 1))."',"
			." '".$this->db->escape($result)."', ".($this->folder !== '' ? "'".$this->db->escape(dol_trunc($this->folder, 250, 'right', 'UTF-8', 1))."'" : "NULL").","
			." ".($this->fromCache ? 1 : 0).", ".($error !== '' ? "'".$this->db->escape(dol_trunc($error, 250, 'right', 'UTF-8', 1))."'" : "NULL").")";
		if (!$this->db->query($sql)) {
			dol_syslog(__METHOD__.' '.$this->db->lasterror(), LOG_WARNING);
		}
		if ($error !== '') {
			dol_syslog(__METHOD__.' '.$result.' '.$mailbox.' '.$messageId.': '.$error, LOG_INFO);
		}

		return null;
	}

	/**
	 * Scheduled job: raw mails of the cache and old lines of the journal removed
	 *
	 * @return int 0 if OK
	 */
	public function cronPurge()
	{
		global $conf;

		$cacheDays = max(1, getDolGlobalInt('INBOX_CACHE_DAYS', 30));
		$logDays = max(1, getDolGlobalInt('INBOX_LOG_DAYS', 365));
		$dir = self::storageDir();
		$removed = 0;
		$rows = $this->db->getRows("SELECT rowid, filepath FROM ".$this->db->prefix()."inbox_message"
			." WHERE entity = ".((int) $conf->entity)." AND date_fetch < '".$this->db->idate(dol_now() - $cacheDays * 86400)."' LIMIT 10000");
		foreach (is_array($rows) ? $rows : array() as $row) {
			$file = basename((string) $row->filepath);
			// The same mail may be cached for two mailboxes (sent from one to the other): file kept while used
			$used = $this->db->getRow("SELECT COUNT(rowid) as nb FROM ".$this->db->prefix()."inbox_message"
				." WHERE filepath = '".$this->db->escape($file)."' AND rowid <> ".((int) $row->rowid));
			if ((!is_object($used) || (int) $used->nb === 0) && $file !== '' && is_file($dir.'/'.$file)) {
				@unlink($dir.'/'.$file);
			}
			$this->db->query("DELETE FROM ".$this->db->prefix()."inbox_message WHERE rowid = ".((int) $row->rowid));
			$removed++;
		}
		$this->db->query("DELETE FROM ".$this->db->prefix()."inbox_readlog WHERE entity = ".((int) $conf->entity)
			." AND datec < '".$this->db->idate(dol_now() - $logDays * 86400)."'");
		// Journal of the changes of the mailboxes (own retention, INBOX_ACCOUNT_LOG_DAYS)
		require_once __DIR__.'/inboxaccountlog.class.php';
		$accountLog = new InboxAccountLog($this->db);
		$accountLog->purge();
		// Journal of the actions on the mails (INBOX_ACTION_LOG_MONTHS)
		require_once __DIR__.'/inboxactionlog.class.php';
		InboxActionLog::purge($this->db);
		// Notifications of the bell (INBOX_NOTIFICATION_DAYS)
		require_once __DIR__.'/inboxnotification.class.php';
		InboxNotification::purge($this->db);
		// Journal of the changes for the quicker refresh (1.121.0): 24 h
		require_once __DIR__.'/inboxevent.class.php';
		(new InboxEvent($this->db))->purge();
		// Mails gone from the server for long (1.114.0): their references forgotten with their links and notes
		require_once __DIR__.'/inboxmailref.class.php';
		$forgotten = (new InboxMailRef($this->db))->shed();
		// Writings left open (lot W6): their temporary folder
		require_once __DIR__.'/inboxcomposer.class.php';
		InboxComposer::purge();
		// Trash and junk of the mailboxes emptied after their delay (1.41.0): removed for good on the server
		global $user;
		if ($user instanceof User && (int) $user->id > 0) {
			require_once __DIR__.'/inboxretention.class.php';
			$retention = new InboxRetention($this->db);
			$retention->run($user);
		}

		// Sources of the sendings of protected mailboxes kept during their delay (sealed by a key of the session): left
		// for good when the session died, removed after a day (audit INBX-062)
		require_once __DIR__.'/inboxoutbox.class.php';
		foreach ((array) glob(InboxOutbox::dir().'/live/*.sealed') as $path) {
			if (is_file($path) && filemtime($path) < dol_now() - 86400) {
				@unlink($path);
			}
		}

		// Leftovers of a directory emptied by hand: lines without file, files without line (left a day, a reading may
		// be writing its line right now)
		$known = array();
		$rows = $this->db->getRows("SELECT rowid, filepath FROM ".$this->db->prefix()."inbox_message WHERE entity = ".((int) $conf->entity)." LIMIT 100000");
		foreach (is_array($rows) ? $rows : array() as $row) {
			$file = basename((string) $row->filepath);
			if ($file === '' || !is_file($dir.'/'.$file)) {
				$this->db->query("DELETE FROM ".$this->db->prefix()."inbox_message WHERE rowid = ".((int) $row->rowid));
				$removed++;
				continue;
			}
			$known[$file] = true;
		}
		foreach (is_dir($dir) ? (array) glob($dir.'/*.eml') : array() as $path) {
			if (!isset($known[basename($path)]) && filemtime($path) < dol_now() - 86400) {
				@unlink($path);
			}
		}
		if (is_dir($dir)) {
			self::writeReadme($dir);
		}
		$this->output = $removed.' mail(s) removed from the cache, '.$forgotten.' mail(s) gone from the server forgotten';

		return 0;
	}
}
