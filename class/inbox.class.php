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
require_once __DIR__.'/inboximapreader.class.php';

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
	 * @param string $mailbox   Email address of the mailbox (declared in crmclientconnector)
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

		$accounts = new InboxAccount($this->db);
		if (!InboxAccount::isAvailable()) {
			return $this->finish(self::RESULT_ERROR, 'Module crmclientconnector (mailboxes) not enabled', $user, $origin, $mailbox, $normalized);
		}
		$account = $accounts->fetchByEmail($mailbox);
		if ($account === null) {
			return $this->finish(self::RESULT_INVALID, 'Mailbox not declared in crmclientconnector', $user, $origin, $mailbox, $normalized);
		}

		$reader = new InboxImapReader($this->db);
		$found = $reader->fetchRawByMessageId($account, $normalized);
		if ($found === null) {
			if ($reader->notFound) {
				return $this->finish(self::RESULT_NOT_FOUND, 'Mail not found in the mailbox', $user, $origin, $mailbox, $normalized);
			}
			return $this->finish(self::RESULT_ERROR, $reader->error, $user, $origin, $mailbox, $normalized);
		}

		$this->folder = $found['folder'];
		$this->store($mailbox, $normalized, $found, $user);
		$this->finish(self::RESULT_FOUND, '', $user, $origin, $mailbox, $normalized);

		return $found['raw'];
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
		if (file_put_contents($dir.'/'.$file, $found['raw']) === false) {
			dol_syslog(__METHOD__.' cannot write '.$dir.'/'.$file, LOG_WARNING);
			return;
		}
		dolChmod($dir.'/'.$file);
		$header = InboxMimeParser::parse(substr($found['raw'], 0, (int) (strpos($found['raw'], "\r\n\r\n") ?: 65536))."\r\n\r\n");
		$sql = "INSERT INTO ".$this->db->prefix()."inbox_message (entity, mailbox, folder, uid, message_id, sha1, date_mail, from_email, subject, filepath, size, date_fetch, fk_user_fetch)"
			." VALUES (".((int) $conf->entity).", '".$this->db->escape($mailbox)."', '".$this->db->escape(dol_trunc($found['folder'], 250, 'right', 'UTF-8', 1))."',"
			." ".((int) $found['uid']).", '".$this->db->escape($messageId)."', '".$this->db->escape($sha1)."',"
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
		$this->output = $removed.' mail(s) removed from the cache';

		return 0;
	}
}
