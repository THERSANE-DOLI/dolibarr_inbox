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
 * \file    inbox/test/phpunit/InboxReadingTest.php
 * \ingroup inbox
 * \brief   Lot W3: lists and search on the index, reading of a mail, safety of the body, antivirus
 */

require_once __DIR__.'/InboxTestCase.php';
dol_include_once('/inbox/class/inboxsync.class.php');
dol_include_once('/inbox/class/inboxsearchsql.class.php');
dol_include_once('/inbox/class/inboxreader.class.php');
dol_include_once('/inbox/class/inboxattachmentscan.class.php');

/**
 * Lot W3 (analyse-lot-w3.md §6.4, CA-01, CA-04, CA-05, CA-07 to CA-09)
 */
class InboxReadingTest extends InboxTestCase
{
	/** @var \Webklex\PHPIMAP\Client|null Another client (as Thunderbird), on the test mailbox only */
	protected $other = null;

	/** @var string[] Message-ID appended by the test, removed after */
	protected $appended = array();

	/**
	 * @return void
	 */
	protected function tearDown(): void
	{
		if ($this->other) {
			try {
				$p = $this->other->getConnection();
				$p->selectFolder('INBOX');
				foreach ($this->appended as $msgid) {
					foreach ((array) $p->search(array('HEADER', 'Message-ID', '"'.$msgid.'"'), true) as $u) {
						$p->requestAndResponse('UID STORE', array((string) $u, '+FLAGS.SILENT', '(\\Deleted)'));
					}
				}
				$p->expunge();
				$this->other->disconnect();
			} catch (Exception $e) {
				// already gone
			}
		}
		parent::tearDown();
	}

	/**
	 * @return \Webklex\PHPIMAP\Connection\Protocols\ImapProtocol
	 */
	protected function other()
	{
		require_once DOL_DOCUMENT_ROOT.'/includes/webklex/php-imap/vendor/autoload.php';
		if (!$this->other) {
			$cm = new \Webklex\PHPIMAP\ClientManager();
			$this->other = $cm->make(array('host' => self::IMAP_HOST, 'port' => self::IMAP_PORT, 'encryption' => false, 'validate_cert' => false, 'protocol' => 'imap',
				'username' => self::BOX, 'password' => self::BOX_PASSWORD, 'authentication' => 'login'));
			$this->other->connect();
		}

		return $this->other->getConnection();
	}

	/**
	 * Flags of the inbox seen by the other client (folder selected again: the changes of another session are shown)
	 *
	 * @return string
	 */
	protected function flags()
	{
		$p = $this->other();
		$p->selectFolder('INBOX');
		$out = array();
		foreach ((array) $p->requestAndResponse('UID FETCH', array('1:*', '(FLAGS)')) as $line) {
			if (is_array($line) && isset($line[1], $line[2]) && $line[1] === 'FETCH') {
				$flags = array_values(array_filter((array) $line[2][3], 'strlen'));
				sort($flags);
				$out[$line[2][1]] = implode(' ', $flags);
			}
		}
		ksort($out);

		return json_encode($out);
	}

	/**
	 * Active test mailbox, synchronised, the admin of the tests with an access
	 *
	 * @param string $level Level of the admin of the tests
	 * @return InboxAccount
	 */
	protected function readyBox($level = InboxAccess::LEVEL_READ)
	{
		$a = $this->box(true, array('status' => InboxAccount::STATUS_ACTIVE));
		$access = new InboxAccess($this->db);
		$this->assertGreaterThan(0, $access->grant($this->admin, (int) $a->id, (int) $this->admin->id, 0, $level));
		InboxAccess::clearCache();
		$s = new InboxSync($this->db);
		$a->fetch($a->id);
		$this->assertGreaterThanOrEqual(0, $s->run($a, microtime(true) + 30, array('force' => true)), $s->error);

		return $a;
	}

	/**
	 * @param string $raw   Raw mail
	 * @param string $msgid Message-ID (removed at the end)
	 * @return void
	 */
	protected function append($raw, $msgid)
	{
		$this->appended[] = $msgid;
		$this->other()->appendMessage('INBOX', $raw);
	}

	/**
	 * @param InboxAccount $a     Mailbox
	 * @param string       $msgid Message-ID
	 * @return int Row of the index
	 */
	protected function rowId(InboxAccount $a, $msgid)
	{
		$r = $this->db->getRow("SELECT rowid FROM ".$this->db->prefix()."inbox_mail WHERE fk_account = ".((int) $a->id)." AND message_id = '".$this->db->escape($msgid)."'");

		return is_object($r) ? (int) $r->rowid : 0;
	}

	/**
	 * Operators of the search, in English and translated
	 *
	 * @return void
	 */
	public function testSearchQuery()
	{
		global $langs;

		$q = InboxSearchQuery::parse('from:dupont "bride dn50" has:attachment before:2026-01-31 subject:devis 10:30 tag:urgent', $langs);
		$this->assertSame(array('dupont'), $q['from']);
		$this->assertSame(array('bride dn50', '10:30'), $q['words'], 'a phrase in quotes, « 10:30 » stays a word');
		$this->assertTrue($q['attachment']);
		$this->assertSame(gmmktime(0, 0, 0, 1, 31, 2026), $q['before']);
		$this->assertSame(array('devis'), $q['subject']);
		$this->assertSame(array('urgent'), $q['tag']);
		$this->assertTrue(InboxSearchQuery::isEmpty(InboxSearchQuery::parse('   ', $langs)));
		$fr = new Translate('', $GLOBALS['conf']);
		$fr->setDefaultLang('fr_FR');
		$fr->loadLangs(array('main', 'inbox@inbox'));
		$q = InboxSearchQuery::parse('de:marie sujet:été pj:oui avant:31/01/2026', $fr);
		$this->assertSame(array('marie'), $q['from']);
		$this->assertSame(array('été'), $q['subject']);
		$this->assertTrue($q['attachment']);
		$this->assertSame(gmmktime(0, 0, 0, 1, 31, 2026), $q['before']);
		// Combining accent (« e » + U+0301) read as the composed form
		$q = InboxSearchQuery::parse("e\u{0301}te\u{0301}", $langs);
		$this->assertSame(array('été'), $q['words']);
	}

	/**
	 * CA-01, CA-08: pages by cursor without hole nor double, never a mail of a mailbox not given, quotes and % harmless
	 *
	 * @return void
	 */
	public function testSearchSql()
	{
		global $langs;

		$this->needDovecot();
		$a = $this->readyBox();
		$inbox = $this->db->getRow("SELECT rowid, total FROM ".$this->db->prefix()."inbox_folder WHERE fk_account = ".((int) $a->id)." AND role = 'inbox'");
		$engine = new InboxSearchSql($this->db);
		$seen = array();
		$cursor = '';
		do {
			$page = $engine->search(array((int) $a->id), (int) $inbox->rowid, array('sort' => 'subject', 'dir' => 'asc'), $cursor, 1);
			foreach ($page['rows'] as $r) {
				$this->assertArrayNotHasKey($r['id'], $seen, 'no mail twice');
				$seen[$r['id']] = true;
			}
			$cursor = $page['next'];
		} while ($cursor !== '');
		$count = (int) $this->db->getRow("SELECT COUNT(rowid) AS n FROM ".$this->db->prefix()."inbox_mail WHERE fk_folder = ".((int) $inbox->rowid))->n;
		$this->assertCount($count, $seen, 'every mail of the folder, page by page');

		$this->assertSame(1, $engine->search(array((int) $a->id), 0, InboxSearchQuery::parse('subject:"raccords inox"', $langs), '', 50)['total'], 'search in the whole mailbox');
		$res = $engine->search(array((int) $a->id), 0, InboxSearchQuery::parse("from:dupont subject:DN25", $langs), '', 50);
		$this->assertSame(1, $res['total']);
		$this->assertSame('j.dupont@acme.invalid', $res['rows'][0]['from_email']);
		foreach (array("%", "_", "' OR 1=1 -- ", '\\', '"') as $evil) {
			$this->assertSame(0, $engine->search(array((int) $a->id), 0, InboxSearchQuery::parse($evil.'zz', $langs), '', 50)['total'], 'harmless: '.$evil);
		}
		$this->assertSame(0, $engine->search(array((int) $a->id + 100000), 0, array(), '', 50)['total'], 'another mailbox: nothing');
		$this->assertSame(0, $engine->search(array(), 0, array(), '', 50)['total'], 'no mailbox: nothing');
		$this->assertSame(0, $engine->search(array((int) $a->id), 0, array(), 'not-a-cursor', 50)['total'], 'a broken cursor is ignored, never an error');
	}

	/**
	 * CA-01, CA-07: a mail of a mailbox without access is « not found »; reading never writes a flag; read / unread
	 * changes \Seen only
	 *
	 * @return void
	 */
	public function testReaderAccessAndFlags()
	{
		$this->needDovecot();
		$a = $this->readyBox();
		$id = $this->rowId($a, 'seed-1@inbox-test.invalid');
		$this->assertGreaterThan(0, $id);

		$stranger = $this->user('reader_stranger');
		$stranger->loadRights();
		$r = new InboxReader($this->db);
		$this->assertFalse($r->load($stranger, $id), 'no access: not found');
		$this->assertSame('not_found', $r->errorCode);
		$this->assertFalse($r->load($this->admin, 99999999));

		$before = $this->flags();
		$this->db->query("DELETE FROM ".$this->db->prefix()."inbox_message WHERE mailbox = '".self::BOX."'");
		$this->assertTrue($r->load($this->admin, $id));
		$raw = $r->raw($this->admin);
		$this->assertNotNull($raw, $r->error);
		$d = $r->describe($this->admin, $raw);
		$this->assertSame('Demande de prix raccords inox DN25', $d['subject']);
		$this->assertSame($raw, $r->raw($this->admin), 'second reading from the cache');
		$this->assertSame($before, $this->flags(), 'CA-07: reading writes no flag');

		$seenBefore = (int) $r->row->seen;
		$this->assertTrue($r->setSeen(!$seenBefore), $r->error);
		$after = $this->flags();
		$this->assertNotSame($before, $after, '\\Seen written');
		$this->assertSame(str_replace(array('\\\\Seen ', ' \\\\Seen', '\\\\Seen'), '', $before), str_replace(array('\\\\Seen ', ' \\\\Seen', '\\\\Seen'), '', $after), 'only \\Seen changed');
		$this->assertSame($seenBefore ? 0 : 1, (int) $this->db->getRow("SELECT seen FROM ".$this->db->prefix()."inbox_mail WHERE rowid = ".$id)->seen, 'index follows');
		$this->assertTrue($r->setSeen((bool) $seenBefore), $r->error);
		$this->assertSame($before, $this->flags(), 'back as before');
	}

	/**
	 * CA-04, CA-09: hostile HTML, remote pictures and trackers, misleading parts of a mail; report of non delivery,
	 * signed and encrypted mails
	 *
	 * @return void
	 */
	public function testMailAnalysis()
	{
		$this->needDovecot();
		$a = $this->readyBox();
		$html = '<p>Bonjour</p><script>alert(1)</script><img src=x onerror="alert(2)"><a href="javascript:alert(3)">x</a>'
			.'<svg onload="alert(4)"></svg><iframe srcdoc="<script>alert(5)</script>"></iframe><form action="https://evil.invalid"><input></form>'
			.'<img src="https://track.example.invalid/open?id=1" width="1" height="1"><img src="https://img.example.invalid/a.png"><base href="https://evil.invalid/">'
			.'<meta http-equiv="refresh" content="0;url=https://evil.invalid"><a href="https://ok.example.org/">https://www.banque.example/</a>';
		$raw = "Message-ID: <reading-html@inbox-test.invalid>\r\nFrom: Lettre <news@shop.example.invalid>\r\nTo: ".self::BOX."\r\nSubject: =?UTF-8?Q?Lettre_=C3=A9t=C3=A9?=\r\nDate: ".date('r')
			."\r\nAuthentication-Results: mx.inbox-test.invalid; spf=pass smtp.mailfrom=x; dkim=fail; dmarc=fail\r\nMIME-Version: 1.0\r\nContent-Type: text/html; charset=utf-8\r\n\r\n".$html."\r\n";
		$this->append($raw, 'reading-html@inbox-test.invalid');
		$dsn = "Message-ID: <reading-dsn@inbox-test.invalid>\r\nFrom: MAILER-DAEMON <mailer-daemon@inbox-test.invalid>\r\nTo: ".self::BOX."\r\nSubject: Undelivered\r\nDate: ".date('r')
			."\r\nMIME-Version: 1.0\r\nContent-Type: multipart/report; report-type=delivery-status; boundary=\"b1\"\r\n\r\n--b1\r\nContent-Type: text/plain\r\n\r\nFailed.\r\n--b1\r\n"
			."Content-Type: message/delivery-status\r\n\r\nReporting-MTA: dns; mx\r\n\r\nFinal-Recipient: rfc822; nobody@client.example.invalid\r\nAction: failed\r\nStatus: 5.1.1\r\n"
			."Diagnostic-Code: smtp; 550 5.1.1 User unknown\r\n--b1--\r\n";
		$this->append($dsn, 'reading-dsn@inbox-test.invalid');
		$enc = "Message-ID: <reading-enc@inbox-test.invalid>\r\nFrom: S <s@bank.example.invalid>\r\nTo: ".self::BOX."\r\nSubject: Chiffre\r\nDate: ".date('r')
			."\r\nMIME-Version: 1.0\r\nContent-Type: application/pkcs7-mime; smime-type=enveloped-data; name=\"smime.p7m\"\r\nContent-Transfer-Encoding: base64\r\n\r\nAAAA\r\n";
		$this->append($enc, 'reading-enc@inbox-test.invalid');
		$s = new InboxSync($this->db);
		$a->fetch($a->id);
		$s->run($a, microtime(true) + 30, array('force' => true));

		$r = new InboxReader($this->db);
		$this->assertTrue($r->load($this->admin, $this->rowId($a, 'reading-html@inbox-test.invalid')));
		$raw = $r->raw($this->admin);
		$d = $r->describe($this->admin, $raw);
		$this->assertSame('Lettre été', $d['subject']);
		$this->assertTrue($d['body']['remote']);
		$this->assertSame(1, $d['body']['trackers']);
		$this->assertTrue($d['auth']['fail'], 'DMARC fail read from the topmost Authentication-Results');
		$this->assertFalse($d['body']['images'], 'never remote pictures for a mail that fails authentication');
		$message = InboxMimeParser::parse($raw);
		$remote = false;
		$doc = InboxMimeParser::safeHtml($message->bodyHtml, array(), $remote);
		foreach (array('<script', 'onerror', 'onload', 'javascript:', '<iframe', '<form', '<input', '<base', 'http-equiv', '<svg') as $bad) {
			$this->assertStringNotContainsStringIgnoringCase($bad, $doc, 'CA-04: '.$bad.' removed');
		}
		$this->assertStringContainsString('rel="noopener noreferrer"', $doc);

		$this->assertTrue($r->load($this->admin, $this->rowId($a, 'reading-dsn@inbox-test.invalid')));
		$d = $r->describe($this->admin, $r->raw($this->admin));
		$this->assertSame('nobody@client.example.invalid', $d['report']['recipients'][0]['email']);
		$this->assertSame(array('failed', '5.1.1', 'no_user'), array($d['report']['recipients'][0]['action'], $d['report']['recipients'][0]['status'], $d['report']['recipients'][0]['family']));
		$this->assertStringContainsString('User unknown', $d['report']['recipients'][0]['diagnostic']);

		$this->assertTrue($r->load($this->admin, $this->rowId($a, 'reading-enc@inbox-test.invalid')));
		$d = $r->describe($this->admin, $r->raw($this->admin));
		$this->assertTrue($d['security']['encrypted'], 'CA-09: encrypted, never an empty body');
	}

	/**
	 * Recette of 2026-11-06: a subject with an emoji (4 bytes) was refused by the database connected in utf8 and the
	 * mail silently left out of the index. It is indexed now (emoji removed from the index only), and an error of the
	 * database is reported on the folder instead of being taken for « already there »
	 *
	 * @return void
	 */
	public function testEmojiIndexed()
	{
		$fake = new stdClass();
		$fake->forcecharset = 'utf8';
		$this->assertSame('Zomb vient de publier', InboxHeaderParser::forDb($fake, "\u{1F44B}Zomb vient de publier"));
		$this->assertSame('Lien : x', InboxHeaderParser::forDb($fake, "Lien : \u{1F517} x"));
		$this->assertSame('Été à 10 €', InboxHeaderParser::forDb($fake, 'Été à 10 €'), '3 bytes characters kept');
		$fake->forcecharset = 'utf8mb4';
		$this->assertSame("\u{1F44B}Zomb", InboxHeaderParser::forDb($fake, "\u{1F44B}Zomb"), 'kept with utf8mb4');

		$this->needDovecot();
		$a = $this->readyBox();
		$raw = "Message-ID: <reading-emoji@inbox-test.invalid>\r\nFrom: =?UTF-8?Q?Zomb_=F0=9F=91=8B?= <x@facebookmail.example.invalid>\r\nTo: ".self::BOX
			."\r\nSubject: =?UTF-8?Q?=F0=9F=91=8BZomb_vient_de_publier?=\r\nDate: ".date('r')."\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nCorps \xF0\x9F\x94\x97 lien\r\n";
		$this->append($raw, 'reading-emoji@inbox-test.invalid');
		$s = new InboxSync($this->db);
		$a->fetch($a->id);
		$s->run($a, microtime(true) + 30, array('force' => true));
		$id = $this->rowId($a, 'reading-emoji@inbox-test.invalid');
		$this->assertGreaterThan(0, $id, 'mail with emojis indexed');
		$row = $this->db->getRow("SELECT subject, from_name FROM ".$this->db->prefix()."inbox_mail WHERE rowid = ".$id);
		$this->assertStringContainsString('Zomb vient de publier', $row->subject);
		$r = new InboxReader($this->db);
		$this->assertTrue($r->load($this->admin, $id));
		$d = $r->describe($this->admin, $r->raw($this->admin));
		$this->assertStringContainsString("\u{1F44B}", $d['subject'], 'the reading keeps the emoji (read on the server)');
	}

	/**
	 * B12: quota read with the folders and kept on the mailbox
	 *
	 * @return void
	 */
	public function testQuota()
	{
		$this->needDovecot();
		$a = $this->readyBox();
		$a->loadSyncState();
		$this->assertSame(102400, (int) $a->quota_limit, 'quota of the test Dovecot: 100 MB');
		$this->assertGreaterThan(0, (int) $a->quota_used);
		$this->assertLessThan((int) $a->quota_limit, (int) $a->quota_used);
	}

	/**
	 * Mail removed by another client after the last pass: « gone », its row leaves the index
	 *
	 * @return void
	 */
	public function testMailGone()
	{
		$this->needDovecot();
		$a = $this->readyBox();
		$raw = "Message-ID: <reading-gone@inbox-test.invalid>\r\nFrom: X <x@example.invalid>\r\nTo: ".self::BOX."\r\nSubject: Gone\r\nDate: ".date('r')."\r\n\r\nBody\r\n";
		$this->append($raw, 'reading-gone@inbox-test.invalid');
		$s = new InboxSync($this->db);
		$a->fetch($a->id);
		$s->run($a, microtime(true) + 30, array('force' => true));
		$id = $this->rowId($a, 'reading-gone@inbox-test.invalid');
		$this->assertGreaterThan(0, $id);
		$p = $this->other();
		$p->selectFolder('INBOX');
		foreach ((array) $p->search(array('HEADER', 'Message-ID', '"reading-gone@inbox-test.invalid"'), true) as $u) {
			$p->requestAndResponse('UID STORE', array((string) $u, '+FLAGS.SILENT', '(\\Deleted)'));
		}
		$p->expunge();
		$this->db->query("DELETE FROM ".$this->db->prefix()."inbox_message WHERE message_id = 'reading-gone@inbox-test.invalid'");
		$r = new InboxReader($this->db);
		$this->assertTrue($r->load($this->admin, $id));
		$this->assertNull($r->raw($this->admin));
		$this->assertSame('gone', $r->errorCode);
		$this->assertSame(0, $this->rowId($a, 'reading-gone@inbox-test.invalid'), 'row removed from the index');
	}

	/**
	 * CA-05: test extension .virus blocked, command refused by $dolibarr_main_restrict_os_commands never « clean »,
	 * file above the size scanned never « clean », EICAR blocked when an antivirus answers; checks without antivirus
	 *
	 * @return void
	 */
	public function testAntivirus()
	{
		global $conf, $dolibarr_main_restrict_os_commands;

		$scan = new InboxAttachmentScan($this->db);
		$this->assertSame(InboxAttachmentScan::INFECTED, $scan->check('abc', 'test.virus')['state']);

		$saved = array('INBOX_AV_SCAN' => getDolGlobalString('INBOX_AV_SCAN'), 'MAIN_ANTIVIRUS_COMMAND' => getDolGlobalString('MAIN_ANTIVIRUS_COMMAND'), 'INBOX_ANTIVIRUS_MAX_MB' => getDolGlobalString('INBOX_ANTIVIRUS_MAX_MB'));
		$restrict = $dolibarr_main_restrict_os_commands;
		try {
			$conf->global->INBOX_AV_SCAN = '1';
			$conf->global->MAIN_ANTIVIRUS_COMMAND = '/usr/bin/clamdscan';
			$dolibarr_main_restrict_os_commands = 'mysqldump, mysql';
			$this->assertSame(InboxAttachmentScan::UNSCANNED, $scan->check('content '.uniqid(), 'a.txt')['state'], 'command not allowed: not scanned, never clean');
			$conf->global->INBOX_ANTIVIRUS_MAX_MB = '1';
			$this->assertSame(InboxAttachmentScan::TOO_BIG, $scan->check(str_repeat('a', 1048577), 'big.bin')['state']);
			$conf->global->INBOX_ANTIVIRUS_MAX_MB = '';
			$conf->global->INBOX_AV_SCAN = '0';
			$this->assertSame(InboxAttachmentScan::OFF, $scan->check('content', 'a.txt')['state']);
			if (is_executable('/usr/bin/clamdscan') && @file_exists('/run/clamav/clamd.ctl')) {
				$conf->global->INBOX_AV_SCAN = '1';
				$conf->global->MAIN_ANTIVIRUS_PARAM = '--stream';
				$dolibarr_main_restrict_os_commands = 'clamdscan';
				$eicar = 'X5O!P%@AP[4\\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*';
				$v = $scan->check($eicar, 'facture.com');
				$this->assertSame(InboxAttachmentScan::INFECTED, $v['state'], 'EICAR blocked: '.$v['message']);
				$this->assertStringContainsString('Eicar', $v['message']);
				$this->assertSame(InboxAttachmentScan::INFECTED, $scan->check($eicar, 'facture.com')['state'], 'verdict kept');
			}
		} finally {
			foreach ($saved as $k => $v) {
				$conf->global->$k = $v;
			}
			$dolibarr_main_restrict_os_commands = $restrict;
			$this->db->query("DELETE FROM ".$this->db->prefix()."inbox_attachment_scan WHERE sha1 = '".sha1('X5O!P%@AP[4\\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*')."'");
		}

		$this->assertContains('InboxAttDoubleExt', InboxAttachmentScan::warnings('MZ', 'facture.pdf.exe'));
		$this->assertContains('InboxAttExecutable', InboxAttachmentScan::warnings('MZ', 'facture.pdf.exe'));
		$this->assertContains('InboxAttPdfJs', InboxAttachmentScan::warnings("%PDF-1.4\n1 0 obj << /JavaScript 2 0 R >>", 'x.pdf'));
		$this->assertContains('InboxAttDoubleExt', InboxAttachmentScan::warnings('x', "facture\u{202E}fdp.exe"), 'direction character');
		$this->assertSame(array(), InboxAttachmentScan::warnings('%PDF-1.4 ok', 'devis.pdf'));
	}

	/**
	 * Types read from the content: a ZIP storing a PDF stays a ZIP; preview kinds
	 *
	 * @return void
	 */
	public function testKinds()
	{
		$zip = "PK\x03\x04".str_repeat("\0", 26).'%PDF-1.4 inside';
		$this->assertSame('zip', InboxMimeParser::kind($zip));
		$this->assertSame('pdf', InboxMimeParser::kind('%PDF-1.7'));
		$this->assertSame('pdf', InboxMimeParser::kind("garbage\n%PDF-1.7"));
		$this->assertSame('zip', InboxReader::previewKind(array('content' => $zip, 'name' => 'dossier.zip', 'mime' => 'application/zip')));
		$this->assertSame('other', InboxReader::previewKind(array('content' => $zip, 'name' => 'plan.docx', 'mime' => '')));
		$this->assertSame('image', InboxReader::previewKind(array('content' => "\x89PNG....", 'name' => 'x.jpg', 'mime' => '')));
		$this->assertSame('csv', InboxReader::previewKind(array('content' => "a;b\n1;2", 'name' => 'liste.csv', 'mime' => '')));
		$this->assertSame('mail', InboxReader::previewKind(array('content' => 'From: x', 'name' => 'a.eml', 'mime' => 'message/rfc822')));
		$this->assertSame(2, InboxReader::countTrackers('<img src="https://a.invalid/o.gif" width="1" height="1"><img src="https://list-manage.com/track/open.php?u=1"><img src="https://a.invalid/logo.png" width="200">'));
		$h = InboxReader::headers("Subject: =?UTF-8?B?w6l0w6k=?=\r\nX-Long: a\r\n b\r\nBad line\r\n\r\nbody");
		$this->assertSame(array(array('Subject', 'été'), array('X-Long', 'a b')), $h);
		$auth = InboxReader::authResults(array(array('Authentication-Results', 'mx; spf=softfail; dkim=fail; dmarc=none'), array('Authentication-Results', 'forged; dmarc=pass')));
		$this->assertTrue($auth['fail'], 'only the topmost result counts');
	}
}
