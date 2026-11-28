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
 * \file    inbox/class/inboxmailtransfer.class.php
 * \ingroup inbox
 * \brief   Mails imported into a folder (.eml, mbox of the local folders of Thunderbird) and folders exported (1.12.0, B14)
 */

require_once __DIR__.'/inboxaccess.class.php';
require_once __DIR__.'/inboxaccount.class.php';
require_once __DIR__.'/inboxfolder.class.php';
require_once __DIR__.'/inboxactionlog.class.php';
require_once __DIR__.'/inboxattachmentscan.class.php';
require_once __DIR__.'/connector/inboxconnectorfactory.class.php';

/**
 * Import: the browser cuts the files into mails (an mbox holds many) and sends them by batches; each mail is written
 * on the mail server (APPEND) with its flags, never twice in the same folder (same Message-ID), never when Thunderbird
 * had removed it (X-Mozilla-Status), never when the antivirus finds a virus. Level « manage » of the mailbox.
 * Export: the mails of the folder known to inbox, read from the server, as an mbox (opened by Thunderbird) or a zip of
 * .eml files. Level « read ». Both are written in the journal of the actions.
 */
class InboxMailTransfer
{
	/** Largest mail accepted (bytes) */
	const MAX_MAIL = 52428800;

	/** @var DoliDB */
	protected $db;

	/** @var string */
	public $error = '';

	/** @var object|null Folder (row of the index) */
	public $folder;

	/** @var InboxAccount|null */
	protected $account;

	/** @var InboxConnectorInterface|null */
	protected $connector;

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct(DoliDB $db)
	{
		$this->db = $db;
	}

	/**
	 * @return void
	 */
	public function __destruct()
	{
		if ($this->connector) {
			$this->connector->disconnect();
		}
	}

	/**
	 * Folder opened for a user with a level
	 *
	 * @param User   $user     User
	 * @param int    $folderId Folder
	 * @param string $level    Level needed
	 * @return bool
	 */
	public function open(User $user, $folderId, $level)
	{
		$this->error = '';
		$f = $this->db->getRow("SELECT * FROM ".$this->db->prefix()."inbox_folder WHERE rowid = ".((int) $folderId)." AND status = ".InboxFolder::STATUS_PRESENT." AND selectable = 1");
		$access = new InboxAccess($this->db);
		if (!is_object($f) || !$access->check($user, (int) $f->fk_account, InboxAccess::LEVEL_READ)) {
			$this->error = 'InboxErrorFolderNotFound';
			return false;
		}
		if (!$access->check($user, (int) $f->fk_account, $level)) {
			$this->error = InboxAccess::needKey($level);
			return false;
		}
		$account = new InboxAccount($this->db);
		if ($account->fetch((int) $f->fk_account) <= 0 || !$account->isActive()) {
			$this->error = 'InboxErrorFolderNotFound';
			return false;
		}
		$error = '';
		$connector = InboxConnectorFactory::forAccount($this->db, $account, $error);
		if (!$connector) {
			$this->error = $error;
			return false;
		}
		$this->folder = $f;
		$this->account = $account;
		$this->connector = $connector;

		return true;
	}

	/**
	 * Import a batch of mails into the folder opened (level manage)
	 *
	 * @param User     $user User
	 * @param string[] $raws Raw mails (an mbox entry without its « From » line, escaped lines « >From » as written)
	 * @param bool     $mbox The mails come from an mbox (lines « >From » unescaped)
	 * @return array<int,array{state:string,subject:string,reason?:string}> One per mail: imported, duplicate, removed (by Thunderbird), infected, failed
	 */
	public function import(User $user, array $raws, $mbox = false)
	{
		$out = array();
		if (!$this->connector->can(InboxConnectorInterface::CAP_IMPORT)) {
			$this->error = 'InboxErrorImportNotSupported';
			return $out;
		}
		$scan = new InboxAttachmentScan($this->db);
		$scanOn = InboxAttachmentScan::enabled();
		$imported = 0;
		foreach ($raws as $raw) {
			$raw = (string) $raw;
			if ($mbox) {
				$raw = preg_replace('/^>(>*From )/m', '$1', $raw);
			}
			// Lines of the mail as IMAP wants them
			$raw = preg_replace("/\r?\n/", "\r\n", $raw);
			$headers = self::headers($raw);
			$subject = self::decode((string) ($headers['subject'] ?? ''));
			$item = array('state' => 'failed', 'subject' => dol_trunc($subject, 120, 'right', 'UTF-8', 1));
			if (strlen($raw) > self::MAX_MAIL) {
				$item['reason'] = 'InboxImportTooBig';
				$out[] = $item;
				continue;
			}
			if (!isset($headers['from']) && !isset($headers['date']) && !isset($headers['message-id'])) {
				$item['reason'] = 'InboxImportNotAMail';
				$out[] = $item;
				continue;
			}
			// Thunderbird keeps the removed mails in its file until it is compacted: they are not mails any more
			$moz = isset($headers['x-mozilla-status']) ? hexdec(substr(preg_replace('/[^0-9a-f]/i', '', $headers['x-mozilla-status']), 0, 4)) : 0;
			if ($moz & 0x0008) {
				$item['state'] = 'removed';
				$out[] = $item;
				continue;
			}
			$messageId = trim((string) ($headers['message-id'] ?? ''), " \t<>");
			if ($messageId !== '' && is_object($this->db->getRow("SELECT rowid FROM ".$this->db->prefix()."inbox_mail WHERE fk_folder = ".((int) $this->folder->rowid)
				." AND message_id = '".$this->db->escape($messageId)."'"))) {
				$item['state'] = 'duplicate';
				$out[] = $item;
				continue;
			}
			if ($scanOn && preg_match('/^Content-Disposition:\s*attachment|filename\*?=|^Content-Type:\s*application\//mi', $raw)) {
				$verdict = $scan->check($raw, 'import.eml');
				if ($verdict['state'] === InboxAttachmentScan::INFECTED) {
					$item['state'] = 'infected';
					$item['reason'] = (string) $verdict['message'];
					$out[] = $item;
					continue;
				}
			}
			$flags = array();
			foreach (array(0x0001 => '\\Seen', 0x0002 => '\\Answered', 0x0004 => '\\Flagged') as $bit => $flag) {
				if ($moz & $bit) {
					$flags[] = $flag;
				}
			}
			// Tags of Thunderbird ($label1...) kept as keywords
			foreach (preg_split('/\s+/', trim((string) ($headers['x-mozilla-keys'] ?? ''))) ?: array() as $k) {
				if (preg_match('/^[A-Za-z0-9$_.\-]{1,64}$/', $k)) {
					$flags[] = $k;
				}
			}
			$date = isset($headers['date']) ? strtotime((string) $headers['date']) : false;
			$res = $this->connector->importRaw((string) $this->folder->path, $raw, $flags, $date ?: null);
			if ($res === null) {
				$item['reason'] = $this->connector->getError();
				$out[] = $item;
				continue;
			}
			$item['state'] = 'imported';
			$imported++;
			$out[] = $item;
		}
		if ($imported) {
			$o = new stdClass();
			$o->message_id = '';
			$o->subject = '';
			$o->nb = $imported;
			InboxActionLog::add($this->db, $user, (int) $this->account->id, 'import', array($o), '', (string) $this->folder->path);
		}

		return $out;
	}

	/**
	 * Stream the folder opened as an mbox or a zip of .eml (mails of the index, read from the server)
	 *
	 * @param User   $user   User
	 * @param string $format mbox or zip
	 * @return bool False when nothing was sent ($this->error)
	 */
	public function export(User $user, $format)
	{
		global $conf;

		$p = $this->db->prefix();
		$rows = $this->db->getRows("SELECT rowid, remote_id, message_id, subject, seen, answered, flagged, keywords, date_mail, date_received FROM ".$p."inbox_mail"
			." WHERE fk_folder = ".((int) $this->folder->rowid)." ORDER BY COALESCE(date_received, date_mail), rowid LIMIT 100000");
		$rows = is_array($rows) ? $rows : array();
		$name = dol_sanitizeFileName(InboxImapConnector::decodeFolderName((string) $this->folder->name) ?: 'folder');
		$zip = null;
		$tmp = '';
		if ($format === 'zip') {
			if (!class_exists('ZipArchive')) {
				$this->error = 'InboxErrorNoZip';
				return false;
			}
			$dir = $conf->inbox->dir_temp ?? (DOL_DATA_ROOT.'/inbox/temp');
			dol_mkdir($dir);
			// The folder of the module may belong to the web server only (scheduled job, command line): any temporary folder works
			if (!is_writable($dir)) {
				$dir = sys_get_temp_dir();
			}
			$tmp = $dir.'/export-'.bin2hex(random_bytes(8)).'.zip';
			$zip = new ZipArchive();
			if ($zip->open($tmp, ZipArchive::CREATE) !== true) {
				$this->error = 'InboxErrorNoZip';
				return false;
			}
		} else {
			$this->header('Content-Type: application/mbox');
			$this->header('Content-Disposition: attachment; filename="'.$name.'.mbox"');
			$this->header('X-Content-Type-Options: nosniff');
		}
		@set_time_limit(0);
		$n = 0;
		foreach ($rows as $r) {
			$raw = $this->connector->fetchRaw((string) $this->folder->path, (string) $r->remote_id);
			if ($raw === null || $raw === '') {
				continue;
			}
			$n++;
			if ($zip) {
				$file = sprintf('%05d', $n).' '.dol_trunc(dol_sanitizeFileName((string) $r->subject ?: 'mail'), 80, 'right', 'UTF-8', 1).'.eml';
				$zip->addFromString($file, $raw);
				continue;
			}
			// mbox (mboxrd, as Thunderbird reads it): « From » line, read / answered / starred kept, lines « From » escaped
			$ts = $r->date_received ? (int) $this->db->jdate($r->date_received) : ((int) $this->db->jdate($r->date_mail) ?: dol_now());
			$status = ((int) $r->seen ? 0x0001 : 0) | ((int) $r->answered ? 0x0002 : 0) | ((int) $r->flagged ? 0x0004 : 0);
			$body = preg_replace("/\r\n/", "\n", $raw);
			$body = preg_replace('/^(>*From )/m', '>$1', $body);
			print 'From - '.gmdate('D M j H:i:s Y', $ts)."\n".'X-Mozilla-Status: '.sprintf('%04x', $status)."\n".rtrim($body, "\n")."\n\n";
			if ($n % 50 === 0) {
				flush();
			}
		}
		if ($zip) {
			$zip->close();
			if ($n === 0 || !is_file($tmp)) {
				// An empty zip is not written by ZipArchive
				@unlink($tmp);
				$this->error = 'InboxExportEmpty';
				return false;
			}
			$this->header('Content-Type: application/zip');
			$this->header('Content-Disposition: attachment; filename="'.$name.'.zip"');
			$this->header('X-Content-Type-Options: nosniff');
			$this->header('Content-Length: '.filesize($tmp));
			readfile($tmp);
			@unlink($tmp);
		}
		$o = new stdClass();
		$o->message_id = '';
		$o->subject = '';
		$o->nb = max(1, $n);
		InboxActionLog::add($this->db, $user, (int) $this->account->id, 'export', array($o), (string) $this->folder->path, '', 0, $format);

		return true;
	}

	/**
	 * A header of the answer, when still possible (never an error once the output started: tests, a stream)
	 *
	 * @param string $line Header
	 * @return void
	 */
	protected function header($line)
	{
		if (!headers_sent()) {
			header($line);
		}
	}

	/**
	 * Value of a header decoded (RFC 2047), for the report of the import
	 *
	 * @param string $value Header value
	 * @return string
	 */
	public static function decode($value)
	{
		$decoded = function_exists('iconv_mime_decode') ? @iconv_mime_decode((string) $value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8') : false;

		return $decoded !== false ? (string) $decoded : (string) $value;
	}

	/**
	 * Headers of a raw mail (lower case names, the first value, folded lines joined)
	 *
	 * @param string $raw Raw mail
	 * @return array<string,string>
	 */
	public static function headers($raw)
	{
		$end = strpos($raw, "\r\n\r\n");
		$head = substr($raw, 0, $end === false ? min(strlen($raw), 65536) : min($end, 262144));
		$head = preg_replace("/\r\n[ \t]+/", ' ', $head);
		$out = array();
		foreach (explode("\r\n", $head) as $line) {
			if (preg_match('/^([!-9;-~]{1,80}):\s?(.*)$/', $line, $m)) {
				$k = strtolower($m[1]);
				if (!isset($out[$k])) {
					$out[$k] = $m[2];
				}
			}
		}

		return $out;
	}
}

