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
 * \file    inbox/class/inboxattachmentscan.class.php
 * \ingroup inbox
 * \brief   Antivirus of the attachments received, verdict kept by content
 */

/**
 * Uses the antivirus of Dolibarr (Setup > Security > Antivirus, class AntiVir): no engine of its own.
 * - setting INBOX_AV_SCAN: auto (default, follows MAIN_ANTIVIRUS_UPLOAD_ON), 1 (always), 0 (never);
 * - verdict « clean » kept 7 days by SHA-1 (signatures change), « infected » kept, « not scanned » never kept;
 * - beyond INBOX_AV_MAX_SIZE (MB, 25 by default, as StreamMaxLength of clamd.conf) the antivirus would answer « OK »
 *   after reading the beginning only: such a file is « not scanned (too big) », never « clean ».
 */
class InboxAttachmentScan
{
	const CLEAN = 'clean';
	const INFECTED = 'infected';
	const UNSCANNED = 'unscanned';
	const TOO_BIG = 'too_big';
	const OFF = 'off';

	/** Days a « clean » verdict is trusted */
	const CLEAN_DAYS = 7;

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
	 * The antivirus runs on the attachments received
	 *
	 * @return bool
	 */
	public static function enabled()
	{
		$mode = getDolGlobalString('INBOX_AV_SCAN', 'auto');
		if ($mode === '0') {
			return false;
		}
		if ($mode === '1') {
			return true;
		}

		return getDolGlobalInt('MAIN_ANTIVIRUS_UPLOAD_ON') > 0;
	}

	/**
	 * Verdict of a file (scan when needed)
	 *
	 * @param string $content Content
	 * @param string $name    File name (the extension .virus is a test of the core: blocked)
	 * @return array{state:string,message:string}
	 */
	public function check($content, $name)
	{
		$content = (string) $content;
		if (preg_match('/\.virus$/i', (string) $name)) {
			return array('state' => self::INFECTED, 'message' => 'File has an extension saying file is a virus');
		}
		if (!self::enabled()) {
			return array('state' => self::OFF, 'message' => '');
		}
		$sha1 = sha1($content);
		$p = $this->db->prefix();
		$row = $this->db->getRow("SELECT result, message, date_scan FROM ".$p."inbox_attachment_scan WHERE sha1 = '".$this->db->escape($sha1)."'");
		if (is_object($row)) {
			$fresh = (int) $this->db->jdate($row->date_scan) > dol_now() - self::CLEAN_DAYS * 86400;
			if ($row->result === self::INFECTED || $fresh) {
				return array('state' => (string) $row->result, 'message' => (string) $row->message);
			}
		}
		$max = max(1, getDolGlobalInt('INBOX_AV_MAX_SIZE', 25)) * 1048576;
		if (strlen($content) > $max) {
			return array('state' => self::TOO_BIG, 'message' => '');
		}
		if (getDolGlobalString('MAIN_ANTIVIRUS_COMMAND') === '') {
			return array('state' => self::UNSCANNED, 'message' => 'No antivirus command in the setup of Dolibarr');
		}
		$verdict = $this->scan($content);
		if ($verdict['state'] === self::CLEAN || $verdict['state'] === self::INFECTED) {
			$this->db->query("DELETE FROM ".$p."inbox_attachment_scan WHERE sha1 = '".$this->db->escape($sha1)."'");
			$this->db->query("INSERT INTO ".$p."inbox_attachment_scan (sha1, result, message, date_scan) VALUES ('".$this->db->escape($sha1)."',"
				." '".$this->db->escape($verdict['state'])."', '".$this->db->escape(dol_trunc($verdict['message'], 250, 'right', 'UTF-8', 1))."', '".$this->db->idate(dol_now())."')");
		}
		if ($verdict['state'] === self::INFECTED) {
			dol_syslog(__METHOD__.' virus found in an attachment '.$sha1.': '.$verdict['message'], LOG_WARNING);
		}

		return $verdict;
	}

	/**
	 * @param string $content Content
	 * @return array{state:string,message:string}
	 */
	protected function scan($content)
	{
		global $conf;

		global $dolibarr_main_restrict_os_commands;

		require_once DOL_DOCUMENT_ROOT.'/core/class/antivir.class.php';
		// A command refused by $dolibarr_main_restrict_os_commands makes Utils::executeCLI() answer -1, that
		// AntiVir::dol_avscan_file() takes for « clean » (it only tests 1 and > 0): never trust that, the file is
		// « not scanned »
		if (!empty($dolibarr_main_restrict_os_commands)) {
			$allowed = array_map('trim', explode(',', (string) $dolibarr_main_restrict_os_commands));
			$command = trim(getDolGlobalString('MAIN_ANTIVIRUS_COMMAND'), " '\"");
			if (!in_array(basename($command), $allowed, true)) {
				return array('state' => self::UNSCANNED, 'message' => basename($command).' is not in $dolibarr_main_restrict_os_commands of conf.php');
			}
		}
		$dir = $conf->inbox->dir_temp ?? (DOL_DATA_ROOT.'/inbox/temp');
		if (!is_dir($dir)) {
			dol_mkdir($dir);
		}
		// The antivirus reads the file sent (--stream): any temporary folder works when the one of the module is not
		// writable (folder made by the web server, scheduled job run by another user)
		if (!is_writable($dir)) {
			$dir = sys_get_temp_dir();
		}
		$file = $dir.'/scan-'.bin2hex(random_bytes(8));
		if (@file_put_contents($file, $content) === false) {
			return array('state' => self::UNSCANNED, 'message' => 'Cannot write the temporary file');
		}
		dolChmod($file);
		$av = new AntiVir($this->db);
		$res = $av->dol_avscan_file($file);
		@unlink($file);
		if ($res === -99) {
			$out = implode(' ', array_map('strval', array_filter((array) $av->errors, 'is_string')));
			$signature = preg_match('/:\s*(\S+)\s+FOUND/', $out, $m) ? $m[1] : 'virus';
			return array('state' => self::INFECTED, 'message' => $signature);
		}
		if ($res < 0) {
			return array('state' => self::UNSCANNED, 'message' => dol_trunc(implode(' ', array_filter((array) $av->errors, 'is_string')), 200));
		}

		return array('state' => self::CLEAN, 'message' => '');
	}

	/**
	 * Checks made by inbox even without antivirus: PDF with JavaScript, misleading double extension, executables
	 *
	 * @param string $content Content
	 * @param string $name    File name
	 * @return string[] Warnings (keys of the language file)
	 */
	public static function warnings($content, $name)
	{
		$out = array();
		$name = (string) $name;
		if (InboxMimeParser::kind($content) === 'pdf' && !getDolGlobalString('MAIN_ANTIVIRUS_ALLOW_JS_IN_PDF')
			&& preg_match('/[\n\s]+\/JavaScript[\n\s]+/m', (string) $content)) {
			$out[] = 'InboxAttPdfJs';
		}
		if (preg_match('/\.(pdf|docx?|xlsx?|jpe?g|png|txt|zip)\.[a-z0-9]{2,4}$/i', $name) || preg_match('/[\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', $name)) {
			$out[] = 'InboxAttDoubleExt';
		}
		if (self::isExecutable($content, $name)) {
			$out[] = 'InboxAttExecutable';
		}

		return $out;
	}

	/**
	 * Program or script: never shown in the preview, download only
	 *
	 * @param string $content Content
	 * @param string $name    File name
	 * @return bool
	 */
	public static function isExecutable($content, $name)
	{
		return strpos((string) $content, 'MZ') === 0 || strpos((string) $content, "\x7FELF") === 0
			|| (bool) preg_match('/\.(exe|com|bat|cmd|scr|pif|msi|msp|js|jse|vbs|vbe|wsf|wsh|ps1|psm1|hta|cpl|jar|lnk|reg|sh|app|dmg|iso|html?|svg|xhtml)$/i', (string) $name);
	}
}
