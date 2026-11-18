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
 * \file    inbox/class/inboxdmarc.class.php
 * \ingroup inbox
 * \brief   DMARC aggregate reports (rua, RFC 7489): detection of a report in a mail, reading of its attachment,
 *          analysis for the dialog of the reading, DNS checks of the domain (1.111.0)
 *
 * A report can be forged by anybody (whoever writes to the rua address): its content is hostile.
 * - No DOCTYPE nor ENTITY (in the bytes, and as a node of the reader), UTF-8, ASCII or Latin 1 only (another
 *   encoding would hide them), XMLReader without the network (no XXE, no « billion laughs »).
 * - gzip and zip unpacked by pieces with ceilings (no bomb), no archive inside an archive.
 * - Every value typed: IP by filter_var, domains and selectors as DNS names, counters as integers, results and
 *   dispositions from closed lists; anything else becomes « ? ». The only free texts (organization, contact, id of the
 *   report, comment of a reason) go through InboxSafe::line() and are cut.
 * Nothing is stored: the analysis is made again at each opening of the dialog.
 */

require_once __DIR__.'/inboxsafe.class.php';

/**
 * DMARC aggregate reports
 */
class InboxDmarc
{
	/** Biggest attachment read */
	const MAX_ATTACHMENT = 10485760;

	/** Biggest XML once unpacked, and all the XML of one attachment together */
	const MAX_XML = 52428800;

	/** Files read in a zip */
	const MAX_ZIP_ENTRIES = 20;

	/** Records read (the totals beyond it are not counted: the analysis says it is partial) */
	const MAX_RECORDS = 20000;

	/** Memory the records of one analysis may take at most (less when PHP has less left), then partial */
	const MAX_MEMORY = 67108864;

	/** Rows sent to the dialog (the totals count all the records read) */
	const MAX_ROWS = 2000;

	/** DNS queries of one user per hour (checks of the domain and names of the IP) */
	const DNS_PER_HOUR = 300;

	/** Results of DKIM and SPF (RFC 7489 appendix C, DMARCbis) */
	const RESULTS = array('pass', 'fail', 'none', 'neutral', 'softfail', 'temperror', 'permerror', 'policy');

	/** Disposition applied by the receiver */
	const DISPOSITIONS = array('none', 'quarantine', 'reject');

	/** Reasons of a policy override */
	const REASONS = array('forwarded', 'sampled_out', 'trusted_forwarder', 'mailing_list', 'local_policy', 'other');

	/** File name of a report: receiver!policy domain!begin!end[!unique id].ext (RFC 7489 §7.2.1.1) */
	const NAME_RE = '/^([^!\/\\\\]+)!([^!\/\\\\]+)!(\d{1,12})!(\d{1,12})(?:![^\/\\\\]*)?\.(xml|xml\.gz|gz|zip)$/i';

	/** Subject of a report: « Report Domain: <domain> Submitter: <submitter> [Report-ID: <id>] » */
	const SUBJECT_RE = '/Report\s+Domain:\s*<?([^\s<>]+)>?\s+Submitter:\s*<?([^\s<>]+)>?/i';

	/** DNS name, as in the reports and the DNS (labels with an underscore allowed: _dmarc, selectors) */
	const DNS_NAME_RE = '/^(?=.{1,253}$)([a-z0-9_]([a-z0-9_-]{0,61}[a-z0-9_])?\.)+[a-z0-9-]{2,63}$/i';

	/** DKIM selector */
	const SELECTOR_RE = '/^[a-z0-9_]([a-z0-9_.-]{0,61}[a-z0-9_])?$/i';

	// ---------- Detection ----------

	/**
	 * Report of a mail, from the names of its attachments and its subject; nothing unpacked
	 *
	 * @param string                           $subject     Subject
	 * @param array<int,array<string,mixed>>   $attachments Attachments of the reading (part, name, mime, size)
	 * @return array{part:int,name:string,domain:string,submitter:string,begin:int,end:int}|null
	 */
	public static function detect($subject, array $attachments)
	{
		$fromSubject = null;
		if (preg_match(self::SUBJECT_RE, (string) $subject, $m)) {
			$fromSubject = array('domain' => self::domainOrEmpty($m[1]), 'submitter' => self::domainOrEmpty($m[2]));
		}
		$candidate = null;
		foreach ($attachments as $a) {
			$name = (string) ($a['name'] ?? '');
			if (preg_match(self::NAME_RE, $name, $m)) {
				$begin = (int) $m[3];
				$end = (int) $m[4];

				return array('part' => (int) $a['part'], 'name' => $name, 'domain' => self::domainOrEmpty($m[2]), 'submitter' => self::domainOrEmpty($m[1]),
					'begin' => $begin, 'end' => $end >= $begin ? $end : 0);
			}
			if ($candidate === null && self::looksLikeReportFile($name, (string) ($a['mime'] ?? ''))) {
				$candidate = $a;
			}
		}
		// The subject alone is not enough: it needs a file that may hold the report
		if ($fromSubject !== null && $candidate !== null && $fromSubject['domain'] !== '') {
			return array('part' => (int) $candidate['part'], 'name' => (string) $candidate['name'], 'domain' => $fromSubject['domain'],
				'submitter' => $fromSubject['submitter'], 'begin' => 0, 'end' => 0);
		}

		return null;
	}

	/**
	 * @param string $name Name of the file
	 * @param string $mime Type
	 * @return bool         A file that may hold a report (XML, gzip, zip)
	 */
	protected static function looksLikeReportFile($name, $mime)
	{
		if (preg_match('/\.(xml|xml\.gz|gz|zip)$/i', $name)) {
			return true;
		}

		return in_array(strtolower($mime), array('application/gzip', 'application/x-gzip', 'application/zip', 'application/x-zip-compressed', 'application/xml', 'text/xml'), true);
	}

	// ---------- Unpacking ----------

	/**
	 * XML documents of an attachment: .xml, .gz, .zip (a zip may hold .xml and .gz, never another zip)
	 *
	 * @param string $name    Name of the file
	 * @param string $content Content
	 * @param string $error   Key of the language file when refused
	 * @return string[]        XML documents (empty: refused, see $error)
	 */
	public static function extract($name, $content, &$error = '')
	{
		$error = '';
		if (strlen($content) > self::MAX_ATTACHMENT) {
			$error = 'InboxDmarcTooBig';
			return array();
		}
		$budget = self::MAX_XML;
		$kind = self::kindOf($content);
		if ($kind === 'zip') {
			return self::unzip($content, $budget, $error);
		}
		if ($kind === 'gz') {
			$xml = self::gunzip($content, $budget, $error);
			return $xml === null ? array() : array($xml);
		}
		if ($kind === 'xml') {
			return array($content);
		}
		$error = 'InboxDmarcUnknownFormat';

		return array();
	}

	/**
	 * @param string $content Content
	 * @return string          zip, gz, xml or '' (told by the first bytes, never by the name)
	 */
	protected static function kindOf($content)
	{
		$head = substr($content, 0, 4);
		if ($head === "PK\x03\x04") {
			return 'zip';
		}
		if (substr($head, 0, 2) === "\x1f\x8b") {
			return 'gz';
		}
		$start = ltrim(substr($content, 0, 512), "\xEF\xBB\xBF \t\r\n");
		if ($start !== '' && $start[0] === '<') {
			return 'xml';
		}
		return '';
	}

	/**
	 * gzip unpacked by pieces, stopped beyond the budget
	 *
	 * @param string $data   gzip
	 * @param int    $budget Bytes left (decreased)
	 * @param string $error  Key when refused
	 * @return string|null
	 */
	protected static function gunzip($data, &$budget, &$error)
	{
		if (!function_exists('inflate_init')) {
			$error = 'InboxDmarcNoZlib';
			return null;
		}
		$ctx = @inflate_init(ZLIB_ENCODING_GZIP);
		if ($ctx === false) {
			$error = 'InboxDmarcUnreadable';
			return null;
		}
		$out = '';
		$len = strlen($data);
		// Small pieces: one piece unpacks to 1032 times its size at most, checked before the next one
		for ($pos = 0; $pos < $len; $pos += 4096) {
			$piece = @inflate_add($ctx, substr($data, $pos, 4096), $pos + 4096 >= $len ? ZLIB_FINISH : ZLIB_SYNC_FLUSH);
			if ($piece === false) {
				$error = 'InboxDmarcUnreadable';
				return null;
			}
			$out .= $piece;
			if (strlen($out) > $budget) {
				$error = 'InboxDmarcTooBig';
				return null;
			}
		}
		$budget -= strlen($out);

		return $out;
	}

	/**
	 * XML of a zip: at most MAX_ZIP_ENTRIES files, read by pieces within the budget; a zip inside is refused
	 *
	 * @param string $data   Zip
	 * @param int    $budget Bytes left (decreased)
	 * @param string $error  Key when refused
	 * @return string[]
	 */
	protected static function unzip($data, &$budget, &$error)
	{
		if (!class_exists('ZipArchive')) {
			$error = 'InboxDmarcNoZip';
			return array();
		}
		$tmp = tempnam(sys_get_temp_dir(), 'ibxdm');
		if ($tmp === false) {
			$error = 'InboxDmarcUnreadable';
			return array();
		}
		$out = array();
		$zip = new ZipArchive();
		$opened = false;
		// Written inside the try: a failed write (disk full) never leaves the file behind
		try {
			if (file_put_contents($tmp, $data) === false) {
				$error = 'InboxDmarcUnreadable';
				return array();
			}
			if ($zip->open($tmp, defined('ZipArchive::RDONLY') ? ZipArchive::RDONLY : 0) !== true) {
				$error = 'InboxDmarcUnreadable';
				return array();
			}
			$opened = true;
			if ($zip->numFiles > self::MAX_ZIP_ENTRIES) {
				$error = 'InboxDmarcTooManyFiles';
				return array();
			}
			for ($i = 0; $i < $zip->numFiles; $i++) {
				$stat = $zip->statIndex($i);
				$entry = is_array($stat) ? (string) $stat['name'] : '';
				if ($entry === '' || substr($entry, -1) === '/') {
					continue;
				}
				if (preg_match('/\.zip$/i', $entry)) {
					$error = 'InboxDmarcNested';
					return array();
				}
				if ((int) $stat['size'] > $budget) {
					$error = 'InboxDmarcTooBig';
					return array();
				}
				// The size announced by the zip is not trusted: read by pieces, stopped beyond the budget
				$stream = $zip->getStream($entry);
				if ($stream === false) {
					continue;
				}
				$content = '';
				while (!feof($stream)) {
					$piece = fread($stream, 65536);
					if ($piece === false) {
						break;
					}
					$content .= $piece;
					if (strlen($content) > $budget) {
						fclose($stream);
						$error = 'InboxDmarcTooBig';
						return array();
					}
				}
				fclose($stream);
				$budget -= strlen($content);
				if (substr($content, 0, 2) === "\x1f\x8b") {
					$xml = self::gunzip($content, $budget, $error);
					if ($xml === null) {
						return array();
					}
					$out[] = $xml;
				} elseif (substr($content, 0, 4) === "PK\x03\x04") {
					$error = 'InboxDmarcNested';
					return array();
				} else {
					$out[] = $content;
				}
			}
		} finally {
			// close() of an archive not opened throws (PHP 8), the @ does not stop it
			if ($opened) {
				$zip->close();
			}
			@unlink($tmp);
		}
		if (!$out && $error === '') {
			$error = 'InboxDmarcEmpty';
		}

		return $out;
	}

	// ---------- Reading ----------

	/**
	 * One report: its metadata, its policy and its records, every value typed
	 *
	 * @param string $xml   XML of the report
	 * @param int    $limit Records still allowed (decreased)
	 * @param string $error Key when refused
	 * @return array{org:string,email:string,id:string,begin:int,end:int,policy:array<string,mixed>,records:array<int,array<string,mixed>>,cut:bool}|null
	 */
	public static function parse($xml, &$limit, &$error = '')
	{
		$error = '';
		if (strlen($xml) > self::MAX_XML) {
			$error = 'InboxDmarcTooBig';
			return null;
		}
		// UTF-16 or UTF-32 (or any NUL byte) would hide a DTD from the check below: the reports are UTF-8
		if (strpos($xml, "\0") !== false || strncmp($xml, "\xFE\xFF", 2) === 0 || strncmp($xml, "\xFF\xFE", 2) === 0) {
			$error = 'InboxDmarcUnreadable';
			return null;
		}
		// The bytes are the text: UTF-8 (or ASCII, Latin 1) only. Another encoding declared (UTF-7) or guessed by libxml
		// (EBCDIC, no « < » first) would hide a DTD from the check below
		$head = ltrim(substr($xml, 0, 512), "\xEF\xBB\xBF \t\r\n");
		if ($head === '' || $head[0] !== '<') {
			$error = 'InboxDmarcUnreadable';
			return null;
		}
		if (preg_match('/^<\?xml[^>]*\bencoding\s*=\s*["\']([^"\']*)["\']/i', $head, $m) && !in_array(strtolower($m[1]), array('utf-8', 'utf8', 'us-ascii', 'ascii', 'iso-8859-1', 'latin1'), true)) {
			$error = 'InboxDmarcUnreadable';
			return null;
		}
		// No DTD at all: no external entity, no expansion bomb (and the reader refuses a DOCTYPE node below)
		if (preg_match('/<!(DOCTYPE|ENTITY)/i', $xml)) {
			$error = 'InboxDmarcDoctype';
			return null;
		}
		if (!class_exists('XMLReader') || !class_exists('DOMDocument')) {
			$error = 'InboxDmarcNoXml';
			return null;
		}
		$reader = new XMLReader();
		$prev = libxml_use_internal_errors(true);
		$out = array('org' => '', 'email' => '', 'id' => '', 'begin' => 0, 'end' => 0, 'policy' => self::policyOf(null), 'records' => array(), 'cut' => false);
		$root = false;
		try {
			if (!$reader->XML($xml, null, LIBXML_NONET)) {
				$error = 'InboxDmarcUnreadable';
				return null;
			}
			$doc = new DOMDocument();
			// next() goes to the following sibling: read() only when the node was not expanded
			$more = @$reader->read();
			while ($more) {
				if ($reader->nodeType === XMLReader::DOC_TYPE) {
					$error = 'InboxDmarcDoctype';
					return null;
				}
				if ($reader->nodeType !== XMLReader::ELEMENT) {
					$more = @$reader->read();
					continue;
				}
				$name = strtolower($reader->localName);
				if ($reader->depth === 0) {
					if ($name !== 'feedback') {
						$error = 'InboxDmarcNotReport';
						return null;
					}
					$root = true;
					$more = @$reader->read();
					continue;
				}
				if ($reader->depth !== 1) {
					$more = @$reader->read();
					continue;
				}
				if ($name === 'report_metadata' || $name === 'policy_published' || $name === 'record') {
					$node = @$reader->expand($doc);
					if (!$node instanceof DOMElement) {
						$error = 'InboxDmarcUnreadable';
						return null;
					}
					if ($name === 'report_metadata') {
						$out['org'] = InboxSafe::line(self::text($node, 'org_name'), 200);
						$email = trim(self::text($node, 'email'));
						$out['email'] = filter_var($email, FILTER_VALIDATE_EMAIL) ? strtolower($email) : '';
						$out['id'] = InboxSafe::line(self::text($node, 'report_id'), 200);
						$range = self::childOf($node, 'date_range');
						if ($range !== null) {
							$out['begin'] = self::timestamp(self::text($range, 'begin'));
							$out['end'] = self::timestamp(self::text($range, 'end'));
						}
					} elseif ($name === 'policy_published') {
						$out['policy'] = self::policyOf($node);
					} else {
						// Records count, and so does the memory: a report of repeated records stays partial, never fatal
						if ($limit <= 0 || ($limit % 500 === 0 && memory_get_usage() > self::memoryCeiling())) {
							$out['cut'] = true;
							break;
						}
						$out['records'][] = self::recordOf($node);
						$limit--;
					}
					$more = @$reader->next();
					continue;
				}
				$more = @$reader->read();
			}
			// A document cut or badly formed: unreadable, whatever was read before the error
			foreach (libxml_get_errors() as $e) {
				if ($e->level >= LIBXML_ERR_ERROR) {
					$error = 'InboxDmarcUnreadable';
					return null;
				}
			}
		} finally {
			$reader->close();
			libxml_clear_errors();
			libxml_use_internal_errors($prev);
		}
		if (!$root) {
			$error = 'InboxDmarcNotReport';
			return null;
		}

		return $out;
	}

	/**
	 * Memory used beyond which no more record is read: the one in use at the first call plus MAX_MEMORY, at most
	 * half of what memory_limit leaves (the rest of the analysis and the answer need some)
	 *
	 * @return int Bytes
	 */
	public static function memoryCeiling()
	{
		static $ceiling = 0;
		if (!$ceiling) {
			$used = memory_get_usage();
			$room = self::MAX_MEMORY;
			$limit = self::bytes((string) ini_get('memory_limit'));
			if ($limit > 0) {
				$room = min($room, max(0, (int) (($limit - $used) / 2)));
			}
			$ceiling = $used + $room;
		}

		return $ceiling;
	}

	/**
	 * @param string $value Value of php.ini (« 128M », « -1 »)
	 * @return int          Bytes, 0 = no limit
	 */
	public static function bytes($value)
	{
		$value = trim($value);
		if ($value === '' || (int) $value <= 0) {
			return 0;
		}
		$n = (int) $value;
		switch (strtolower(substr($value, -1))) {
			case 'g':
				$n *= 1024;
				// no break
			case 'm':
				$n *= 1024;
				// no break
			case 'k':
				$n *= 1024;
		}

		return $n;
	}

	/**
	 * @param DOMElement|null $node policy_published
	 * @return array{domain:string,p:string,sp:string,np:string,pct:int,adkim:string,aspf:string,fo:string}
	 */
	protected static function policyOf($node)
	{
		$pick = function ($value, array $allowed) {
			$value = strtolower(trim((string) $value));
			return in_array($value, $allowed, true) ? $value : '';
		};
		if ($node === null) {
			return array('domain' => '', 'p' => '', 'sp' => '', 'np' => '', 'pct' => 100, 'adkim' => '', 'aspf' => '', 'fo' => '');
		}
		$pct = trim(self::text($node, 'pct'));

		return array(
			'domain' => self::domainOrEmpty(self::text($node, 'domain')),
			'p' => $pick(self::text($node, 'p'), self::DISPOSITIONS),
			'sp' => $pick(self::text($node, 'sp'), self::DISPOSITIONS),
			'np' => $pick(self::text($node, 'np'), self::DISPOSITIONS),
			'pct' => $pct !== '' && ctype_digit($pct) ? min(100, (int) $pct) : 100,
			'adkim' => $pick(self::text($node, 'adkim'), array('r', 's')),
			'aspf' => $pick(self::text($node, 'aspf'), array('r', 's')),
			'fo' => preg_match('/^[01ds](:[01ds])*$/i', trim(self::text($node, 'fo'))) ? strtolower(trim(self::text($node, 'fo'))) : '',
		);
	}

	/**
	 * @param DOMElement $node record
	 * @return array<string,mixed> Typed record
	 */
	protected static function recordOf(DOMElement $node)
	{
		$row = self::childOf($node, 'row');
		$eval = $row !== null ? self::childOf($row, 'policy_evaluated') : null;
		$ids = self::childOf($node, 'identifiers');
		$auth = self::childOf($node, 'auth_results');
		$ip = $row !== null ? trim(self::text($row, 'source_ip')) : '';
		$count = $row !== null ? trim(self::text($row, 'count')) : '';
		$reasons = array();
		if ($eval !== null) {
			foreach (self::childrenOf($eval, 'reason') as $r) {
				$type = strtolower(trim(self::text($r, 'type')));
				$reasons[] = array('type' => in_array($type, self::REASONS, true) ? $type : 'other', 'comment' => InboxSafe::line(self::text($r, 'comment'), 200));
				if (count($reasons) >= 5) {
					break;
				}
			}
		}
		$dkim = array();
		$spf = array();
		if ($auth !== null) {
			foreach (self::childrenOf($auth, 'dkim') as $d) {
				$selector = trim(self::text($d, 'selector'));
				$dkim[] = array('domain' => self::domainOrEmpty(self::text($d, 'domain')), 'selector' => preg_match(self::SELECTOR_RE, $selector) ? strtolower($selector) : '',
					'result' => self::result(self::text($d, 'result')));
				if (count($dkim) >= 10) {
					break;
				}
			}
			foreach (self::childrenOf($auth, 'spf') as $s) {
				$scope = strtolower(trim(self::text($s, 'scope')));
				$spf[] = array('domain' => self::domainOrEmpty(self::text($s, 'domain')), 'scope' => in_array($scope, array('mfrom', 'helo'), true) ? $scope : '',
					'result' => self::result(self::text($s, 'result')));
				if (count($spf) >= 5) {
					break;
				}
			}
		}

		return array(
			'ip' => filter_var($ip, FILTER_VALIDATE_IP) ? strtolower($ip) : '',
			'count' => ctype_digit($count) ? min((int) $count, PHP_INT_MAX) : 0,
			'disposition' => self::pick($eval !== null ? self::text($eval, 'disposition') : '', self::DISPOSITIONS),
			'dkim_aligned' => self::pick($eval !== null ? self::text($eval, 'dkim') : '', array('pass', 'fail')),
			'spf_aligned' => self::pick($eval !== null ? self::text($eval, 'spf') : '', array('pass', 'fail')),
			'reasons' => $reasons,
			'header_from' => $ids !== null ? self::domainOrEmpty(self::text($ids, 'header_from')) : '',
			'envelope_from' => $ids !== null ? self::domainOrEmpty(self::text($ids, 'envelope_from')) : '',
			'dkim' => $dkim,
			'spf' => $spf,
		);
	}

	// ---------- Analysis ----------

	/**
	 * Analysis of one attachment for the dialog
	 *
	 * @param string   $name    Name of the attachment
	 * @param string   $content Content
	 * @param string[] $ours    Domains of the mailboxes of inbox (oursDomains())
	 * @return array<string,mixed> {error} or {reports, rows, totals, own_failures, partial, foreign}
	 */
	public static function analyze($name, $content, array $ours = array())
	{
		$error = '';
		$docs = self::extract($name, $content, $error);
		if (!$docs) {
			return array('error' => $error ?: 'InboxDmarcEmpty');
		}
		$limit = self::MAX_RECORDS;
		$reports = array();
		$rows = array();
		$partial = false;
		foreach ($docs as $xml) {
			$report = self::parse($xml, $limit, $error);
			if ($report === null) {
				return array('error' => $error);
			}
			$partial = $partial || $report['cut'];
			$index = count($reports);
			foreach ($report['records'] as $r) {
				$r['report'] = $index;
				$rows[] = $r;
			}
			unset($report['records'], $report['cut']);
			$reports[] = $report;
		}
		$totals = array('messages' => 0, 'ips' => 0, 'dmarc_pass' => 0, 'dkim_aligned' => 0, 'spf_aligned' => 0, 'quarantine' => 0, 'reject' => 0, 'fail' => 0);
		$ips = array();
		$own = array();
		foreach ($rows as $k => $r) {
			$domain = $r['header_from'] !== '' ? $r['header_from'] : (string) ($reports[$r['report']]['policy']['domain'] ?? '');
			$pass = $r['dkim_aligned'] === 'pass' || $r['spf_aligned'] === 'pass';
			$rows[$k]['dmarc'] = $pass ? 'pass' : 'fail';
			$rows[$k]['causes'] = $pass ? array() : self::causes($r, $domain);
			// Our own server: the receiver checked that our SPF allows this IP (SPF pass for the domain of the
			// report), and yet the DKIM aligned fails. An IP that fails SPF (spoofing) or a forward (DKIM pass, SPF
			// fail) is not ours.
			$isOwn = false;
			foreach ($r['spf'] as $s) {
				if ($s['result'] === 'pass' && $s['domain'] !== '' && $domain !== '' && self::aligned($s['domain'], $domain)) {
					$isOwn = true;
				}
			}
			$rows[$k]['own'] = $isOwn;
			$rows[$k]['own_failure'] = $isOwn && $r['dkim_aligned'] !== 'pass';
			if ($rows[$k]['own_failure'] && $pass) {
				// DMARC still passes by the SPF: the cause told is the one of DKIM
				$rows[$k]['causes'] = self::causes(array_merge($r, array('spf' => array())), $domain);
			}
			$n = $r['count'];
			$totals['messages'] += $n;
			$totals['dmarc_pass'] += $pass ? $n : 0;
			$totals['fail'] += $pass ? 0 : $n;
			$totals['dkim_aligned'] += $r['dkim_aligned'] === 'pass' ? $n : 0;
			$totals['spf_aligned'] += $r['spf_aligned'] === 'pass' ? $n : 0;
			$totals['quarantine'] += $r['disposition'] === 'quarantine' ? $n : 0;
			$totals['reject'] += $r['disposition'] === 'reject' ? $n : 0;
			if ($r['ip'] !== '') {
				$ips[$r['ip']] = true;
			}
			if ($rows[$k]['own_failure']) {
				$key = $r['ip'].'|'.$domain;
				if (!isset($own[$key])) {
					$own[$key] = array('ip' => $r['ip'], 'domain' => $domain, 'count' => 0, 'causes' => array());
				}
				$own[$key]['count'] += $n;
				$own[$key]['causes'] = array_values(array_unique(array_merge($own[$key]['causes'], self::causes(array_merge($r, array('spf' => array())), $domain))));
			}
		}
		$totals['ips'] = count($ips);
		// Our servers that fail first, then the failures, then by volume
		usort($rows, function ($a, $b) {
			return array((int) $b['own_failure'], (int) ($b['dmarc'] === 'fail'), $b['count']) <=> array((int) $a['own_failure'], (int) ($a['dmarc'] === 'fail'), $a['count']);
		});
		$cut = count($rows) > self::MAX_ROWS;
		$foreign = array();
		if ($ours) {
			foreach ($reports as $rep) {
				$d = (string) $rep['policy']['domain'];
				if ($d !== '' && !self::isOurs($d, $ours)) {
					$foreign[$d] = true;
				}
			}
		}

		return array(
			'reports' => $reports,
			'rows' => array_slice($rows, 0, self::MAX_ROWS),
			'rows_cut' => $cut,
			'totals' => $totals,
			'own_failures' => array_values($own),
			'partial' => $partial,
			'foreign' => array_keys($foreign),
		);
	}

	/**
	 * Why a record fails DMARC, as keys of the language file (InboxDmarcCause_xxx)
	 *
	 * @param array<string,mixed> $r      Record
	 * @param string              $domain Domain of the From
	 * @return string[]
	 */
	public static function causes(array $r, $domain)
	{
		$out = array();
		if ($r['dkim_aligned'] !== 'pass') {
			if (!$r['dkim']) {
				$out[] = 'nodkim';
			} else {
				$alignedPass = false;
				$otherPass = false;
				foreach ($r['dkim'] as $d) {
					$al = $d['domain'] !== '' && $domain !== '' && self::aligned($d['domain'], $domain);
					if ($d['result'] === 'pass') {
						if ($al) {
							$alignedPass = true;
						} else {
							$otherPass = true;
						}
					}
				}
				if (!$alignedPass) {
					$out[] = $otherPass ? 'dkim_other' : 'dkim_fail';
				}
			}
		}
		if ($r['spf_aligned'] !== 'pass' && !empty($r['spf'])) {
			$spfPass = false;
			$spfAligned = false;
			foreach ($r['spf'] as $s) {
				if ($s['result'] === 'pass') {
					$spfPass = true;
					$spfAligned = $spfAligned || ($s['domain'] !== '' && $domain !== '' && self::aligned($s['domain'], $domain));
				}
			}
			$out[] = $spfPass && !$spfAligned ? 'spf_other' : 'spf_fail';
		}
		foreach ($r['reasons'] as $reason) {
			if ($reason['type'] === 'forwarded' || $reason['type'] === 'mailing_list') {
				$out[] = 'forwarded';
				break;
			}
		}

		return $out;
	}

	/**
	 * Relaxed alignment: same registrable domain (Public Suffix List of inbox)
	 *
	 * @param string $a Domain
	 * @param string $b Domain
	 * @return bool
	 */
	public static function aligned($a, $b)
	{
		$a = strtolower(trim($a, '. '));
		$b = strtolower(trim($b, '. '));
		if ($a === '' || $b === '') {
			return false;
		}
		if ($a === $b || substr($a, -strlen('.'.$b)) === '.'.$b || substr($b, -strlen('.'.$a)) === '.'.$a) {
			return true;
		}
		require_once __DIR__.'/inboxtrusteddomain.class.php';
		$ra = InboxTrustedDomain::registrable($a);

		return $ra !== '' && $ra === InboxTrustedDomain::registrable($b);
	}

	/**
	 * Domains of the mailboxes of inbox and of their sending identities, in the entity
	 *
	 * @param DoliDB $db Database
	 * @return string[]
	 */
	public static function oursDomains($db)
	{
		$p = $db->prefix();
		$entity = getEntity('inbox_account');
		$out = array();
		$rows = $db->getRows("SELECT email FROM ".$p."inbox_account WHERE entity IN (".$entity.") LIMIT 1000");
		$ids = $db->getRows("SELECT i.email FROM ".$p."inbox_identity as i INNER JOIN ".$p."inbox_account as a ON a.rowid = i.fk_account WHERE a.entity IN (".$entity.") LIMIT 5000");
		foreach (array_merge(is_array($rows) ? $rows : array(), is_array($ids) ? $ids : array()) as $r) {
			$d = strtolower((string) substr((string) strrchr((string) $r->email, '@'), 1));
			if ($d !== '') {
				$out[$d] = true;
			}
		}

		return array_keys($out);
	}

	/**
	 * @param string   $domain Domain of a report
	 * @param string[] $ours   Domains of the mailboxes
	 * @return bool             The domain is one of ours (or a subdomain of one)
	 */
	public static function isOurs($domain, array $ours)
	{
		$domain = strtolower($domain);
		foreach ($ours as $o) {
			if ($domain === $o || substr($domain, -strlen('.'.$o)) === '.'.$o) {
				return true;
			}
		}

		return false;
	}

	// ---------- DNS ----------

	/**
	 * Checks of the current DNS of a domain: DMARC, SPF, DKIM of the selectors seen in the report
	 *
	 * @param string   $domain    Domain
	 * @param string[] $selectors Selectors
	 * @return array{dmarc:array<string,mixed>,spf:array<string,mixed>,dkim:array<int,array<string,mixed>>}|null Null when the domain is not valid
	 */
	public static function dnsChecks($domain, array $selectors)
	{
		$domain = self::publicDomainOrEmpty($domain);
		if ($domain === '') {
			return null;
		}
		$dkim = array();
		foreach (array_slice(array_values(array_unique(array_filter($selectors, function ($s) {
			return preg_match(self::SELECTOR_RE, (string) $s);
		}))), 0, 10) as $sel) {
			$dkim[] = self::checkDkim($domain, strtolower($sel));
		}

		return array('dmarc' => self::checkDmarc($domain), 'spf' => self::checkSpf($domain), 'dkim' => $dkim);
	}

	/**
	 * TXT records of a name (the strings of a record joined); null when the DNS did not answer
	 *
	 * @param string $name Name
	 * @return string[]|null
	 */
	protected static function txt($name)
	{
		if (!preg_match(self::DNS_NAME_RE, $name) || !function_exists('dns_get_record')) {
			return null;
		}
		$records = @dns_get_record($name, DNS_TXT);
		if ($records === false) {
			return null;
		}
		$out = array();
		foreach ((array) $records as $r) {
			if (isset($r['entries']) && is_array($r['entries'])) {
				$out[] = implode('', $r['entries']);
			} elseif (isset($r['txt'])) {
				$out[] = (string) $r['txt'];
			}
		}

		return $out;
	}

	/**
	 * @param string $record Record
	 * @return array<string,string> tag => value
	 */
	protected static function tags($record)
	{
		$tags = array();
		foreach (explode(';', $record) as $part) {
			$i = strpos($part, '=');
			if ($i !== false && $i > 0) {
				$tags[strtolower(trim(substr($part, 0, $i)))] = trim(substr($part, $i + 1));
			}
		}

		return $tags;
	}

	/**
	 * @param string $domain Domain
	 * @return array{name:string,records:string[],checks:array<int,array{0:string,1:string,2:array<int,string|int>}>}
	 */
	protected static function checkDmarc($domain)
	{
		$isDmarc = function ($r) {
			return (bool) preg_match('/v\s*=\s*dmarc1/i', $r);
		};
		$policyDomain = $domain;
		$checks = array();
		$found = self::txt('_dmarc.'.$domain);
		if ($found === null) {
			return array('name' => '_dmarc.'.$domain, 'records' => array(), 'checks' => array(array('warn', 'dns_error', array())));
		}
		$records = array_values(array_filter($found, $isDmarc));
		require_once __DIR__.'/inboxtrusteddomain.class.php';
		$org = InboxTrustedDomain::registrable($domain);
		if (!$records && $org !== '' && $org !== $domain) {
			$parent = array_values(array_filter((array) self::txt('_dmarc.'.$org), $isDmarc));
			if ($parent) {
				$records = $parent;
				$policyDomain = $org;
				$checks[] = array('info', 'dns_inherited', array($org));
			}
		}
		$out = array('name' => '_dmarc.'.$policyDomain, 'records' => array_map(function ($r) {
			return InboxSafe::line($r, 1000);
		}, $records), 'checks' => array());
		if (!$records) {
			$checks[] = array('ko', 'dmarc_none', array('_dmarc.'.$domain));
			$out['checks'] = $checks;
			return $out;
		}
		if (count($records) > 1) {
			$checks[] = array('ko', 'dmarc_multiple', array(count($records)));
			$out['checks'] = $checks;
			return $out;
		}
		$rec = $records[0];
		$tags = self::tags($rec);
		$strength = array('none' => 0, 'quarantine' => 1, 'reject' => 2);
		if (!preg_match('/^\s*v\s*=\s*DMARC1\s*(;|$)/i', $rec)) {
			$checks[] = array('ko', 'dmarc_bad_version', array());
		}
		$p = strtolower($tags['p'] ?? '');
		if (!isset($strength[$p])) {
			$checks[] = array('ko', 'dmarc_bad_p', array());
		} else {
			$checks[] = array($p === 'none' ? 'warn' : 'ok', 'dmarc_p_'.$p, array());
			$sp = strtolower($tags['sp'] ?? '');
			if (isset($strength[$sp]) && $strength[$sp] < $strength[$p]) {
				$checks[] = array('warn', 'dmarc_sp_weaker', array($sp, $p));
			}
		}
		if (isset($tags['pct']) && ctype_digit($tags['pct']) && (int) $tags['pct'] < 100) {
			$checks[] = array('warn', 'dmarc_pct', array((int) $tags['pct']));
		}
		if (empty($tags['rua'])) {
			$checks[] = array('warn', 'dmarc_no_rua', array());
		} else {
			foreach (array_slice(array_filter(array_map('trim', explode(',', $tags['rua']))), 0, 5) as $address) {
				if (!preg_match('/^mailto:([^@!\s]+)@([^!\s]+)/i', $address, $m)) {
					continue;
				}
				$email = InboxSafe::line($m[1].'@'.$m[2], 255);
				$dest = strtolower($m[2]);
				if (self::aligned($dest, $policyDomain)) {
					$checks[] = array('ok', 'dmarc_rua_ok', array($email));
					continue;
				}
				// RFC 7489 §7.1: an external destination publishes <domain>._report._dmarc.<destination>
				$authName = $policyDomain.'._report._dmarc.'.$dest;
				$auth = self::txt($authName);
				if ($auth === null) {
					$checks[] = array('warn', 'dmarc_rua_unknown', array($email));
				} else {
					$checks[] = array_filter($auth, $isDmarc) ? array('ok', 'dmarc_rua_auth', array($email)) : array('ko', 'dmarc_rua_unauth', array($email, $authName));
				}
			}
		}
		$out['checks'] = $checks;

		return $out;
	}

	/**
	 * @param string $domain Domain
	 * @return array{name:string,records:string[],checks:array<int,array{0:string,1:string,2:array<int,string|int>}>}
	 */
	protected static function checkSpf($domain)
	{
		$found = self::txt($domain);
		if ($found === null) {
			return array('name' => $domain, 'records' => array(), 'checks' => array(array('warn', 'dns_error', array())));
		}
		$records = array_values(array_filter($found, function ($r) {
			return (bool) preg_match('/^\s*v=spf1(\s|$)/i', $r);
		}));
		$out = array('name' => $domain, 'records' => array_map(function ($r) {
			return InboxSafe::line($r, 1000);
		}, $records), 'checks' => array());
		if (!$records) {
			$out['checks'][] = array('ko', 'spf_none', array());
			return $out;
		}
		if (count($records) > 1) {
			$out['checks'][] = array('ko', 'spf_multiple', array(count($records)));
			return $out;
		}
		$all = null;
		$redirect = '';
		$ptr = false;
		foreach (array_slice(preg_split('/\s+/', trim($records[0])), 1) as $term) {
			if (preg_match('/^([+?~-]?)all$/i', $term, $m)) {
				$all = $m[1] !== '' ? $m[1] : '+';
			} elseif (stripos($term, 'redirect=') === 0) {
				$redirect = self::domainOrEmpty(substr($term, 9));
			} elseif (preg_match('/^[+?~-]?ptr(:|$)/i', $term)) {
				$ptr = true;
			}
		}
		if ($all === '+') {
			$out['checks'][] = array('ko', 'spf_all_pass', array());
		} elseif ($all === '?') {
			$out['checks'][] = array('warn', 'spf_all_neutral', array());
		} elseif ($all === '~') {
			$out['checks'][] = array('ok', 'spf_all_soft', array());
		} elseif ($all === '-') {
			$out['checks'][] = array('ok', 'spf_all_fail', array());
		} elseif ($redirect !== '') {
			$out['checks'][] = array('info', 'spf_redirect', array($redirect));
		} else {
			$out['checks'][] = array('warn', 'spf_no_all', array());
		}
		if ($ptr) {
			$out['checks'][] = array('warn', 'spf_ptr', array());
		}

		return $out;
	}

	/**
	 * @param string $domain   Domain
	 * @param string $selector Selector
	 * @return array{selector:string,name:string,type:string,bits:int,checks:array<int,array{0:string,1:string,2:array<int,string|int>}>}
	 */
	protected static function checkDkim($domain, $selector)
	{
		$name = $selector.'._domainkey.'.$domain;
		$out = array('selector' => $selector, 'name' => $name, 'type' => '', 'bits' => 0, 'checks' => array());
		$found = self::txt($name);
		if ($found === null) {
			$out['checks'][] = array('warn', 'dns_error', array());
			return $out;
		}
		$records = array_values(array_filter($found, function ($r) {
			return (bool) preg_match('/(^|;)\s*p\s*=/i', $r) || (bool) preg_match('/^\s*v\s*=\s*DKIM1/i', $r);
		}));
		if (!$records) {
			$out['checks'][] = array('ko', 'dkim_missing', array());
			return $out;
		}
		if (count($records) > 1) {
			$out['checks'][] = array('warn', 'dkim_multiple', array());
		}
		$tags = self::tags($records[0]);
		$k = strtolower($tags['k'] ?? 'rsa');
		$key = (string) preg_replace('/\s+/', '', $tags['p'] ?? '');
		$out['type'] = in_array($k, array('rsa', 'ed25519'), true) ? $k : '';
		if (isset($tags['v']) && strtoupper($tags['v']) !== 'DKIM1') {
			$out['checks'][] = array('ko', 'dkim_bad', array());
		} elseif ($key === '') {
			$out['checks'][] = array('ko', 'dkim_revoked', array());
		} elseif ($k === 'ed25519') {
			$out['bits'] = 256;
			$out['checks'][] = array('ok', 'dkim_ok', array());
		} elseif ($k === 'rsa') {
			$out['bits'] = self::rsaBits($key);
			if (!$out['bits']) {
				$out['checks'][] = array('warn', 'dkim_size_unknown', array());
			} elseif ($out['bits'] < 1024) {
				$out['checks'][] = array('ko', 'dkim_weak', array($out['bits']));
			} elseif ($out['bits'] < 2048) {
				$out['checks'][] = array('warn', 'dkim_1024', array($out['bits']));
			} else {
				$out['checks'][] = array('ok', 'dkim_ok', array());
			}
		} else {
			$out['checks'][] = array('ko', 'dkim_bad', array());
		}
		if (in_array('y', array_map('trim', explode(':', strtolower($tags['t'] ?? ''))), true)) {
			$out['checks'][] = array('warn', 'dkim_test', array());
		}

		return $out;
	}

	/**
	 * @param string $b64 Public key of a DKIM record (p=)
	 * @return int         Size in bits, 0 when unknown
	 */
	protected static function rsaBits($b64)
	{
		if (!function_exists('openssl_pkey_get_public') || !preg_match('/^[A-Za-z0-9+\/=]+$/', $b64) || strlen($b64) > 4096) {
			return 0;
		}
		$pem = "-----BEGIN PUBLIC KEY-----\n".chunk_split($b64, 64, "\n")."-----END PUBLIC KEY-----\n";
		$key = @openssl_pkey_get_public($pem);
		if ($key === false) {
			return 0;
		}
		$details = openssl_pkey_get_details($key);

		return is_array($details) ? (int) ($details['bits'] ?? 0) : 0;
	}

	/**
	 * A public address: neither private, reserved nor shared (100.64.0.0/10), and an IPv4 written as IPv6
	 * (::ffff:a.b.c.d, ::a.b.c.d) judged as the IPv4 it holds (audit INBX-096: ::ffff:10.0.0.1 passed the filter of PHP)
	 *
	 * @param string $ip IP
	 * @return bool
	 */
	public static function publicIp($ip)
	{
		$bin = @inet_pton(trim($ip));
		if ($bin === false) {
			return false;
		}
		if (strlen($bin) === 16 && (substr($bin, 0, 12) === str_repeat("\0", 10)."\xff\xff" || substr($bin, 0, 12) === str_repeat("\0", 12))) {
			$bin = substr($bin, 12);
		}
		$ip = (string) inet_ntop($bin);
		if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
			return false;
		}
		// Shared address space of the operators (carrier-grade NAT)
		if (strlen($bin) === 4 && (ord($bin[0]) === 100 && (ord($bin[1]) & 0xc0) === 64)) {
			return false;
		}

		return true;
	}

	/**
	 * Name of an IP (reverse DNS)
	 *
	 * @param string $ip IP
	 * @return string|null Name, '' when it has none, null when the IP is not valid
	 */
	public static function ptr($ip)
	{
		// Public addresses only: the names of the private networks of the server are not given to the users
		if (!self::publicIp((string) $ip) || !function_exists('gethostbyaddr')) {
			return null;
		}
		$name = @gethostbyaddr($ip);
		if (!is_string($name) || $name === $ip || !preg_match(self::DNS_NAME_RE, $name)) {
			return '';
		}

		return strtolower($name);
	}

	/**
	 * Queries of a user within the limit of the hour (file of the temporary folder of inbox, the session being closed
	 * for the reads)
	 *
	 * @param int $userId User
	 * @param int $count  Queries asked
	 * @return bool        False beyond DNS_PER_HOUR
	 */
	public static function dnsAllowed($userId, $count)
	{
		global $conf;

		$dir = (!empty($conf->inbox->dir_temp) ? $conf->inbox->dir_temp : DOL_DATA_ROOT.'/inbox/temp');
		if (!is_dir($dir)) {
			dol_mkdir($dir);
		}
		$file = $dir.'/dmarc-dns-'.((int) $userId).'.json';
		$hour = (int) floor(time() / 3600);
		$fh = @fopen($file, 'c+');
		if ($fh === false) {
			return true;
		}
		$ok = true;
		if (flock($fh, LOCK_EX)) {
			$data = json_decode((string) stream_get_contents($fh), true);
			$used = is_array($data) && (int) ($data['hour'] ?? 0) === $hour ? (int) ($data['used'] ?? 0) : 0;
			$ok = $used + $count <= self::DNS_PER_HOUR;
			if ($ok) {
				ftruncate($fh, 0);
				rewind($fh);
				fwrite($fh, json_encode(array('hour' => $hour, 'used' => $used + $count)));
			}
			flock($fh, LOCK_UN);
		}
		fclose($fh);

		return $ok;
	}

	// ---------- Values ----------

	/**
	 * @param string $value Value
	 * @return string        Lower case DNS name, '' when it is not one
	 */
	public static function domainOrEmpty($value)
	{
		$value = strtolower(trim((string) $value, " \t\r\n.<>"));

		return preg_match(self::DNS_NAME_RE, $value) ? $value : '';
	}

	/**
	 * Domain of the internet: a DNS name under a known public suffix (Public Suffix List of inbox), so that the server
	 * never answers on the names of an internal zone (.local, .lan, .corp...)
	 *
	 * @param string $value Value
	 * @return string        Lower case domain, '' else
	 */
	public static function publicDomainOrEmpty($value)
	{
		$domain = self::domainOrEmpty($value);
		if ($domain === '') {
			return '';
		}
		// The default rule of the list (« an unknown last label is a suffix ») would accept .local or .corp: the last
		// label must be in the list itself
		static $rules = null;
		if ($rules === null) {
			$rules = include __DIR__.'/data/inboxpublicsuffix.php';
		}
		$tld = (string) substr((string) strrchr('.'.$domain, '.'), 1);
		if (!is_array($rules) || (!isset($rules[$tld]) && !isset($rules['*.'.$tld]))) {
			return '';
		}
		require_once __DIR__.'/inboxtrusteddomain.class.php';

		return InboxTrustedDomain::registrable($domain) !== '' ? $domain : '';
	}

	/**
	 * @param string $value Value
	 * @return string        One of the results, '?' else
	 */
	protected static function result($value)
	{
		$value = strtolower(trim((string) $value));

		return in_array($value, self::RESULTS, true) ? $value : '?';
	}

	/**
	 * @param string   $value   Value
	 * @param string[] $allowed Allowed values
	 * @return string           The value, '?' else ('' when empty)
	 */
	protected static function pick($value, array $allowed)
	{
		$value = strtolower(trim((string) $value));
		if ($value === '') {
			return '';
		}

		return in_array($value, $allowed, true) ? $value : '?';
	}

	/**
	 * @param string $value Unix time
	 * @return int           0 when it is not one (or out of 1990-2100)
	 */
	protected static function timestamp($value)
	{
		$value = trim((string) $value);
		if (!ctype_digit($value)) {
			return 0;
		}
		$ts = (int) $value;

		return $ts >= 631152000 && $ts <= 4102444800 ? $ts : 0;
	}

	/**
	 * @param DOMElement $node Node
	 * @param string     $name Local name of a child
	 * @return DOMElement|null  First child of this name
	 */
	protected static function childOf(DOMElement $node, $name)
	{
		foreach ($node->childNodes as $c) {
			if ($c instanceof DOMElement && strcasecmp($c->localName, $name) === 0) {
				return $c;
			}
		}

		return null;
	}

	/**
	 * @param DOMElement $node Node
	 * @param string     $name Local name of the children
	 * @return DOMElement[]
	 */
	protected static function childrenOf(DOMElement $node, $name)
	{
		$out = array();
		foreach ($node->childNodes as $c) {
			if ($c instanceof DOMElement && strcasecmp($c->localName, $name) === 0) {
				$out[] = $c;
			}
		}

		return $out;
	}

	/**
	 * @param DOMElement $node Node
	 * @param string     $name Local name of a child
	 * @return string           Its text, cut at 2000 characters
	 */
	protected static function text(DOMElement $node, $name)
	{
		$c = self::childOf($node, $name);

		return $c === null ? '' : substr(trim($c->textContent), 0, 2000);
	}
}
