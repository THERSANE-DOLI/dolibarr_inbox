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
 * \file    inbox/test/phpunit/InboxDmarcTest.php
 * \ingroup inbox
 * \brief   1.111.0: DMARC aggregate reports (detection, unpacking with ceilings, safe reading, typing, causes, our
 *          servers that fail, alignment, domains of the mailboxes)
 */

// From the command line only, never from the web: test/ stays in the Git branches
if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

require_once __DIR__.'/InboxTestCase.php';
dol_include_once('/inbox/class/inboxdmarc.class.php');

/**
 * Reports made by the tests (fictitious domains: example.invalid, IP of the documentation ranges)
 */
class InboxDmarcTest extends InboxTestCase
{
	/**
	 * A report in the format of the receivers
	 *
	 * @param string[] $records XML of the records
	 * @param string   $root    Opening tag of the root
	 * @param string   $org     Name of the reporter
	 * @return string
	 */
	protected static function report(array $records, $root = '<feedback>', $org = 'google.com')
	{
		return '<?xml version="1.0" encoding="UTF-8" ?>'."\n".$root
			.'<report_metadata><org_name>'.$org.'</org_name><email>noreply-dmarc@example.invalid</email><report_id>1234</report_id>'
			.'<date_range><begin>1794700800</begin><end>1794787199</end></date_range></report_metadata>'
			.'<policy_published><domain>example.invalid</domain><adkim>r</adkim><aspf>r</aspf><p>quarantine</p><sp>none</sp><pct>100</pct></policy_published>'
			.implode('', $records).'</feedback>';
	}

	/**
	 * @param string $ip      Source IP
	 * @param int    $count   Messages
	 * @param string $dkimAl  DKIM aligned (pass, fail)
	 * @param string $spfAl   SPF aligned (pass, fail)
	 * @param string $dkim    auth_results/dkim XML
	 * @param string $spf     auth_results/spf XML
	 * @param string $extra   XML added in policy_evaluated
	 * @return string
	 */
	protected static function record($ip, $count, $dkimAl, $spfAl, $dkim, $spf, $extra = '')
	{
		return '<record><row><source_ip>'.$ip.'</source_ip><count>'.$count.'</count><policy_evaluated><disposition>none</disposition>'
			.'<dkim>'.$dkimAl.'</dkim><spf>'.$spfAl.'</spf>'.$extra.'</policy_evaluated></row>'
			.'<identifiers><header_from>example.invalid</header_from><envelope_from>example.invalid</envelope_from></identifiers>'
			.'<auth_results>'.$dkim.$spf.'</auth_results></record>';
	}

	/**
	 * @param string $name    Name in the zip => content
	 * @param array<string,string> $files Files
	 * @return string Zip
	 */
	protected static function zip(array $files)
	{
		$tmp = tempnam(sys_get_temp_dir(), 'ibxdmt');
		$zip = new ZipArchive();
		$zip->open($tmp, ZipArchive::OVERWRITE);
		foreach ($files as $name => $content) {
			$zip->addFromString($name, $content);
		}
		$zip->close();
		$data = (string) file_get_contents($tmp);
		unlink($tmp);

		return $data;
	}

	/**
	 * @return void
	 */
	public function testDetect()
	{
		$name = 'google.com!example.invalid!1794700800!1794787199.zip';
		$d = InboxDmarc::detect('Report domain: example.invalid Submitter: google.com', array(array('part' => 3, 'name' => $name, 'mime' => 'application/zip')));
		$this->assertSame(array('part' => 3, 'name' => $name, 'domain' => 'example.invalid', 'submitter' => 'google.com', 'begin' => 1794700800, 'end' => 1794787199), $d);
		// Name alone (Microsoft: .xml.gz), whatever the subject
		$d = InboxDmarc::detect('Hello', array(array('part' => 1, 'name' => 'protection.outlook.com!example.invalid!1794700800!1794787199!abc.xml.gz', 'mime' => 'application/gzip')));
		$this->assertSame('example.invalid', $d['domain']);
		$this->assertSame('protection.outlook.com', $d['submitter']);
		// Subject with a file that may hold a report
		$d = InboxDmarc::detect('Report Domain: example.invalid Submitter: yahoo.com Report-ID: <x@y>', array(array('part' => 2, 'name' => 'report.xml.gz', 'mime' => 'application/gzip')));
		$this->assertSame(2, $d['part']);
		$this->assertSame('yahoo.com', $d['submitter']);
		// Subject alone, or a file alone without the format of the name: not a report
		$this->assertNull(InboxDmarc::detect('Report Domain: example.invalid Submitter: yahoo.com', array(array('part' => 1, 'name' => 'devis.pdf', 'mime' => 'application/pdf'))));
		$this->assertNull(InboxDmarc::detect('Devis', array(array('part' => 1, 'name' => 'export.zip', 'mime' => 'application/zip'))));
		$this->assertNull(InboxDmarc::detect('Devis', array(array('part' => 1, 'name' => 'a!b.pdf', 'mime' => 'application/pdf'))));
		// A forged name: the domain is not a DNS name, said empty
		$d = InboxDmarc::detect('', array(array('part' => 1, 'name' => '<script>!x y!1!2.xml', 'mime' => 'text/xml')));
		$this->assertSame('', $d['domain']);
		$this->assertSame('', $d['submitter']);
	}

	/**
	 * @return void
	 */
	public function testFormatsAndAnalysis()
	{
		$xml = self::report(array(
			// Our server: SPF pass for the domain, DKIM aligned fails (key missing): the banner
			self::record('192.0.2.10', 12, 'fail', 'pass', '<dkim><domain>example.invalid</domain><selector>default</selector><result>temperror</result></dkim>',
				'<spf><domain>example.invalid</domain><scope>mfrom</scope><result>pass</result></spf>'),
			// Spoofing: nothing passes, not ours
			self::record('198.51.100.7', 340, 'fail', 'fail', '', '<spf><domain>example.invalid</domain><result>fail</result></spf>'),
			// Forward: DKIM aligned passes, SPF fails: compliant, not a failure of ours
			self::record('203.0.113.5', 3, 'pass', 'fail', '<dkim><domain>example.invalid</domain><selector>default</selector><result>pass</result></dkim>',
				'<spf><domain>forwarder.invalid</domain><result>pass</result></spf>', '<reason><type>forwarded</type></reason>'),
		));
		foreach (array('r.xml' => $xml, 'r.xml.gz' => gzencode($xml), 'r.zip' => self::zip(array('r.xml' => $xml))) as $name => $content) {
			$r = InboxDmarc::analyze($name, $content, array('example.invalid'));
			$this->assertArrayNotHasKey('error', $r, $name);
			$this->assertSame(355, $r['totals']['messages'], $name);
			$this->assertSame(3, $r['totals']['ips']);
			// Our server passes DMARC by its SPF aligned (DKIM broken, the banner says it), the forward by its DKIM
			$this->assertSame(15, $r['totals']['dmarc_pass'], $name);
			$this->assertSame(340, $r['totals']['fail'], $name);
			$this->assertSame(12, $r['totals']['spf_aligned']);
			$this->assertSame(array(), $r['foreign']);
		}
		$this->assertCount(1, $r['own_failures']);
		$this->assertSame('192.0.2.10', $r['own_failures'][0]['ip']);
		$this->assertSame(array('dkim_fail'), $r['own_failures'][0]['causes']);
		// Our failure first, then the failures, then the others
		$this->assertSame(array('192.0.2.10', '198.51.100.7', '203.0.113.5'), array_column($r['rows'], 'ip'));
		$this->assertSame(array('dkim_fail'), $r['rows'][0]['causes'], 'DMARC passes by the SPF, the DKIM cause is told');
		$spoof = $r['rows'][1];
		$this->assertFalse($spoof['own']);
		$this->assertSame(array('nodkim', 'spf_fail'), $spoof['causes']);
		$forward = $r['rows'][2];
		$this->assertSame('pass', $forward['dmarc']);
		$this->assertFalse($forward['own_failure']);
		$this->assertSame('forwarded', $forward['reasons'][0]['type']);
		$this->assertSame(array('quarantine', 'none', 100, 'r'), array($r['reports'][0]['policy']['p'], $r['reports'][0]['policy']['sp'], $r['reports'][0]['policy']['pct'], $r['reports'][0]['policy']['adkim']));
		// Several reports in one zip, a .gz inside a zip accepted
		$r = InboxDmarc::analyze('x.zip', self::zip(array('a.xml' => $xml, 'b.xml.gz' => gzencode(self::report(array(), '<feedback>', 'yahoo.com')))), array('example.invalid'));
		$this->assertCount(2, $r['reports']);
		$this->assertSame('yahoo.com', $r['reports'][1]['org']);
		// Report of a domain that is not ours
		$r = InboxDmarc::analyze('r.xml', $xml, array('mine.invalid'));
		$this->assertSame(array('example.invalid'), $r['foreign']);
		// DMARCbis: namespace on the root
		$r = InboxDmarc::analyze('r.xml', self::report(array(self::record('192.0.2.1', 1, 'pass', 'pass', '', '')), '<feedback xmlns="urn:ietf:params:xml:ns:dmarc-2.0">'));
		$this->assertSame(1, $r['totals']['dmarc_pass']);
	}

	/**
	 * Records bounded by number and by memory: a report of repeated records is partial, never a fatal error
	 *
	 * @return void
	 */
	public function testMemoryBounded()
	{
		$this->assertSame(0, InboxDmarc::bytes('-1'));
		$this->assertSame(134217728, InboxDmarc::bytes('128M'));
		$this->assertSame(1073741824, InboxDmarc::bytes('1G'));
		$this->assertSame(65536, InboxDmarc::bytes('64k'));
		$this->assertGreaterThan(0, InboxDmarc::memoryCeiling(), 'a ceiling, fixed at the first record read');
		$this->assertLessThanOrEqual(20000, InboxDmarc::MAX_RECORDS);
		$limit = 3;
		$rec = '<record><row><source_ip>192.0.2.1</source_ip><count>1</count></row><identifiers><header_from>a.example</header_from></identifiers></record>';
		$r = InboxDmarc::parse('<?xml version="1.0"?><feedback>'.str_repeat($rec, 5).'</feedback>', $limit);
		$this->assertCount(3, $r['records']);
		$this->assertTrue($r['cut'], 'partial, said so');
	}

	/**
	 * The check of the DNS: domains of the reports analyzed only (signed per user and day), worst case counted first
	 *
	 * @return void
	 */
	public function testDnsOfTheReportsOnly()
	{
		$sig = InboxDmarc::domainSignature(7, 'example.com');
		$this->assertTrue(InboxDmarc::domainSigned(7, 'example.com', $sig));
		$this->assertTrue(InboxDmarc::domainSigned(7, 'EXAMPLE.com', $sig), 'case of the domain');
		$this->assertFalse(InboxDmarc::domainSigned(8, 'example.com', $sig), 'another user');
		$this->assertFalse(InboxDmarc::domainSigned(7, 'example.org', $sig), 'another domain');
		$this->assertFalse(InboxDmarc::domainSigned(7, 'example.com', ''), 'typed without signature');
		$yesterday = InboxDmarc::domainSignature(7, 'example.com', (int) floor(time() / 86400) - 1);
		$this->assertTrue(InboxDmarc::domainSigned(7, 'example.com', $yesterday), 'yesterday still');
		$old = InboxDmarc::domainSignature(7, 'example.com', (int) floor(time() / 86400) - 2);
		$this->assertFalse(InboxDmarc::domainSigned(7, 'example.com', $old));
		$this->assertSame(18, InboxDmarc::dnsCost(10), 'DMARC and its organisation, 5 destinations, SPF, 10 selectors');
		$this->assertSame(18, InboxDmarc::dnsCost(50));
		$this->assertSame(8, InboxDmarc::dnsCost(0));
	}

	/**
	 * Names asked for public addresses only, an IPv4 written as IPv6 judged as itself (audit INBX-096)
	 *
	 * @return void
	 */
	public function testPublicIp()
	{
		foreach (array('8.8.8.8', '2001:4860:4860::8888', '::ffff:8.8.8.8') as $ip) {
			$this->assertTrue(InboxDmarc::publicIp($ip), $ip);
		}
		foreach (array('10.0.0.1', '127.0.0.1', '192.168.1.2', '::ffff:10.0.0.1', '::ffff:127.0.0.1', '::ffff:192.168.1.2', '::10.0.0.1', '::1', 'fe80::1', 'fd00::1', '100.64.1.1', '::ffff:100.100.0.1', '169.254.169.254', 'nope', '') as $ip) {
			$this->assertFalse(InboxDmarc::publicIp($ip), $ip);
		}
		$this->assertNull(InboxDmarc::ptr('::ffff:127.0.0.1'));
	}

	/**
	 * @return void
	 */
	public function testRefused()
	{
		$xml = self::report(array());
		$this->assertSame('InboxDmarcDoctype', InboxDmarc::analyze('r.xml', '<?xml version="1.0"?><!DOCTYPE feedback [<!ENTITY x "y">]><feedback>&x;</feedback>')['error']);
		$this->assertSame('InboxDmarcDoctype', InboxDmarc::analyze('r.xml', '<!ENTITY x SYSTEM "file:///etc/passwd"><feedback/>')['error']);
		// A DTD hidden by UTF-16 (the check reads bytes)
		$utf16 = mb_convert_encoding('<?xml version="1.0" encoding="UTF-16"?><!DOCTYPE feedback [<!ENTITY x "y">]><feedback>&x;</feedback>', 'UTF-16LE', 'UTF-8');
		$this->assertSame('InboxDmarcUnreadable', InboxDmarc::analyze('r.xml', $utf16)['error'], 'without BOM');
		$this->assertArrayHasKey('error', InboxDmarc::analyze('r.xml', "\xFF\xFE".$utf16), 'with BOM');
		$this->assertSame('InboxDmarcNotReport', InboxDmarc::analyze('r.xml', '<html><body>x</body></html>')['error']);
		$this->assertSame('InboxDmarcUnreadable', InboxDmarc::analyze('r.xml', '<feedback><record>')['error']);
		$this->assertSame('InboxDmarcUnknownFormat', InboxDmarc::analyze('r.pdf', '%PDF-1.4')['error']);
		// Another encoding would hide a DTD from the check of the bytes (UTF-7 « +ADw-!DOCTYPE », EBCDIC)
		$this->assertSame('InboxDmarcUnreadable', InboxDmarc::analyze('r.xml', '<?xml version="1.0" encoding="UTF-7"?>'."\n".'+ADw-!DOCTYPE feedback +AFs-+AD4-<feedback></feedback>')['error']);
		$limit = 10;
		$error = '';
		$this->assertNull(InboxDmarc::parse("\x4C\x6F\xA7\x94\x93\x40\x4C\x6F", $limit, $error), 'EBCDIC (guessed by libxml from its first bytes)');
		$this->assertSame('InboxDmarcUnreadable', $error);
		$this->assertSame('InboxDmarcUnreadable', InboxDmarc::analyze('r.xml', "<?xml version='1.0' encoding='EBCDIC-CP-US'?><feedback/>")['error']);
		$ok = InboxDmarc::analyze('r.xml', "\xEF\xBB\xBF<?xml version=\"1.0\" encoding=\"utf-8\"?>".substr($xml, strpos($xml, '<feedback')));
		$this->assertArrayNotHasKey('error', $ok, 'UTF-8 with its mark and declaration: read');
		// A zip that does not open: refused as unreadable (never a fatal error), its temporary file gone
		$before = glob(sys_get_temp_dir().'/ibxdm*') ?: array();
		$this->assertSame('InboxDmarcUnreadable', InboxDmarc::analyze('r.zip', "PK\x03\x04".str_repeat('x', 200))['error']);
		$this->assertSame($before, glob(sys_get_temp_dir().'/ibxdm*') ?: array(), 'no temporary file left');
		$this->assertSame('InboxDmarcNested', InboxDmarc::analyze('r.zip', self::zip(array('inner.zip' => self::zip(array('r.xml' => $xml)))))['error']);
		$many = array();
		for ($i = 0; $i <= InboxDmarc::MAX_ZIP_ENTRIES; $i++) {
			$many['r'.$i.'.xml'] = $xml;
		}
		$this->assertSame('InboxDmarcTooManyFiles', InboxDmarc::analyze('r.zip', self::zip($many))['error']);
		$this->assertSame('InboxDmarcEmpty', InboxDmarc::analyze('r.zip', self::zip(array('dir/' => '')))['error'] ?? 'InboxDmarcEmpty');
		// Bombs: a few kilobytes that unpack beyond the ceiling, as gzip and inside a zip
		$bomb = gzencode(str_repeat("\0", InboxDmarc::MAX_XML + 1024), 9);
		$this->assertLessThan(InboxDmarc::MAX_ATTACHMENT, strlen($bomb));
		$this->assertSame('InboxDmarcTooBig', InboxDmarc::analyze('r.xml.gz', $bomb)['error']);
		$this->assertSame('InboxDmarcTooBig', InboxDmarc::analyze('r.zip', self::zip(array('r.xml.gz' => $bomb)))['error']);
		$this->assertSame('InboxDmarcTooBig', InboxDmarc::analyze('r.xml', str_repeat(' ', InboxDmarc::MAX_ATTACHMENT + 1))['error']);
		unset($bomb);
	}

	/**
	 * @return void
	 */
	public function testTyping()
	{
		$xml = self::report(array(
			'<record><row><source_ip><![CDATA[not an ip"><script>alert(1)</script>]]></source_ip><count>-5</count><policy_evaluated><disposition>evil</disposition>'
			.'<dkim>maybe</dkim><spf>pass</spf><reason><type>hack</type><comment><![CDATA[<img src=x onerror=alert(2)>]]></comment></reason></policy_evaluated></row>'
			.'<identifiers><header_from><![CDATA["><svg onload=alert(3)>]]></header_from></identifiers>'
			.'<auth_results><dkim><domain>example.invalid</domain><selector>bad selector!</selector><result>owned</result></dkim></auth_results></record>',
		), '<feedback>', '<![CDATA[<b onmouseover=alert(4)>Evil &amp; Co</b>]]>');
		$r = InboxDmarc::analyze('r.xml', $xml);
		$row = $r['rows'][0];
		$this->assertSame('', $row['ip']);
		$this->assertSame(0, $row['count']);
		$this->assertSame('?', $row['disposition']);
		$this->assertSame('?', $row['dkim_aligned']);
		$this->assertSame('', $row['header_from']);
		$this->assertSame('', $row['dkim'][0]['selector']);
		$this->assertSame('?', $row['dkim'][0]['result']);
		$this->assertSame('other', $row['reasons'][0]['type']);
		$json = json_encode($r);
		foreach (array('<script', '<img', '<svg', '<b ', 'onerror', 'onload=') as $bad) {
			$this->assertStringNotContainsString($bad, (string) $json, $bad);
		}
		// Entities decoded then tags removed (InboxSafe::line): the text stays, the tag goes
		$this->assertStringContainsString('Evil & Co', $r['reports'][0]['org']);
	}

	/**
	 * @return void
	 */
	public function testAlignmentAndDomains()
	{
		$this->assertTrue(InboxDmarc::aligned('mail.example.co.uk', 'example.co.uk'));
		$this->assertTrue(InboxDmarc::aligned('a.example.co.uk', 'b.example.co.uk'));
		$this->assertFalse(InboxDmarc::aligned('example.co.uk', 'other.co.uk'), 'co.uk is a public suffix');
		$this->assertFalse(InboxDmarc::aligned('', 'example.invalid'));
		$this->assertTrue(InboxDmarc::isOurs('news.example.invalid', array('example.invalid')));
		$this->assertFalse(InboxDmarc::isOurs('notexample.invalid', array('example.invalid')));
		$this->assertSame('', InboxDmarc::domainOrEmpty('exa mple.fr'));
		$this->assertSame('example.fr', InboxDmarc::domainOrEmpty(' Example.FR. '));
		$this->assertNull(InboxDmarc::ptr('not-an-ip'));
		// Internal names and addresses never asked
		$this->assertNull(InboxDmarc::ptr('192.168.1.20'));
		$this->assertNull(InboxDmarc::ptr('127.0.0.1'));
		$this->assertSame('', InboxDmarc::publicDomainOrEmpty('intranet.local'));
		$this->assertSame('', InboxDmarc::publicDomainOrEmpty('srv.corp'));
		$this->assertSame('example.fr', InboxDmarc::publicDomainOrEmpty('example.fr'));
		$this->assertNull(InboxDmarc::dnsChecks('intranet.local', array()));
		$this->assertNull(InboxDmarc::dnsChecks('not a domain', array()));
		$this->assertIsArray(InboxDmarc::oursDomains($this->db));
	}
}
