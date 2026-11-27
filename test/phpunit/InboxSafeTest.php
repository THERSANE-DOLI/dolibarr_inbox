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
 * \file    inbox/test/phpunit/InboxSafeTest.php
 * \ingroup inbox
 * \brief   Lot S1: the one door of what comes from a mail (InboxSafe), with a corpus of attacks
 */

use PHPUnit\Framework\TestCase;

// From the command line only, never from the web: test/ stays in the Git branches
if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

dol_include_once('/inbox/class/inboxsafe.class.php');
dol_include_once('/inbox/class/inboxmimeparser.class.php');
dol_include_once('/inbox/class/inboxagenda.class.php');
dol_include_once('/inbox/class/inboxlead.class.php');
dol_include_once('/inbox/class/inboxcreate.class.php');

/**
 * No database: pure functions
 */
class InboxSafeTest extends TestCase
{
	/** Attacks written in a mail body (HTML) */
	const ATTACKS = array(
		'script' => '<p>a<script>alert(1)</script></p>',
		'script upper' => '<SCRIPT SRC=//x.y/z.js></SCRIPT>',
		'img onerror' => '<img src=x onerror=alert(1)>',
		'img onerror no quote' => '<img/src="x"/onerror="alert(1)">',
		'svg onload' => '<svg onload=alert(1)><circle r=1></circle></svg>',
		'math href' => '<math><mi xlink:href="javascript:alert(1)">x</mi></math>',
		'body onload' => '<body onload=alert(1)>x</body>',
		'a javascript' => '<a href="javascript:alert(1)">x</a>',
		'a javascript entities' => '<a href="&#106;&#97;&#118;&#97;&#115;&#99;&#114;&#105;&#112;&#116;&#58;alert(1)">x</a>',
		'a javascript tab' => '<a href="jav&#x09;ascript:alert(1)">x</a>',
		'a javascript spaces' => '<a href="  javascript:alert(1)">x</a>',
		'a vbscript' => '<a href="vbscript:msgbox(1)">x</a>',
		'a data html' => '<a href="data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==">x</a>',
		'iframe' => '<iframe src="https://x.y"></iframe>',
		'iframe srcdoc' => '<iframe srcdoc="<script>alert(1)</script>"></iframe>',
		'object' => '<object data="x.swf"></object><embed src="x.swf">',
		'form' => '<form action="https://x.y"><input name=a><button formaction="javascript:alert(1)">b</button></form>',
		'meta refresh' => '<meta http-equiv="refresh" content="0;url=javascript:alert(1)">',
		'base' => '<base href="javascript:alert(1)//">',
		'style expression' => '<p style="width:expression(alert(1))">x</p>',
		'style url js' => '<p style="background:url(javascript:alert(1))">x</p>',
		'style block' => '<style>@import "https://x.y/a.css"; body{background:url(https://x.y/t.png)}</style><p>x</p>',
		'noscript mxss' => '<noscript><p title="</noscript><img src=x onerror=alert(1)>">',
		'template' => '<template><img src=x onerror=alert(1)></template>',
		'xmp' => '<xmp><img src=x onerror=alert(1)></xmp>',
		'comment' => '<!--><img src=x onerror=alert(1)>-->',
		'outlook conditional' => '<!--[if gte mso 9]><img src=x onerror=alert(1)><![endif]-->',
		'textarea mxss' => '<textarea><img title="</textarea><img src=x onerror=alert(1)>"></textarea>',
		'attribute breakout' => '<p title=\'"><img src=x onerror=alert(1)>\'>x</p>',
		'srcset' => '<img srcset="javascript:alert(1)">',
		'video' => '<video><source onerror="alert(1)"></video>',
		'details ontoggle' => '<details open ontoggle=alert(1)>x</details>',
		'unknown handler' => '<p onpointerenter=alert(1) onfocusin=alert(1)>x</p>',
	);

	/**
	 * @return void
	 */
	protected function setUp(): void
	{
		global $conf;

		// documents/inbox/temp of the test copy belongs to the web server: the cache of HTMLPurifier goes elsewhere
		$conf->inbox->dir_temp = sys_get_temp_dir().'/inbox-safe-test';
	}

	/**
	 * Every attack, through html(): only allowed elements, no event, no script scheme
	 *
	 * @return void
	 */
	public function testHtmlStopsTheAttacks()
	{
		foreach (self::ATTACKS as $name => $attack) {
			$this->assertSafeFragment(InboxSafe::html($attack), $name);
		}
	}

	/**
	 * Every attack, through display() (reading frame): no script, no event, no frame, no form
	 *
	 * @return void
	 */
	public function testDisplayStopsTheAttacks()
	{
		foreach (self::ATTACKS as $name => $attack) {
			$remote = false;
			$doc = InboxSafe::display($attack, array(), $remote);
			$this->assertSafeFragment(preg_replace('#^.*?<body>|</body></html>$#s', '', $doc), $name, false);
		}
	}

	/**
	 * InboxMimeParser keeps its old names and gives the same document
	 *
	 * @return void
	 */
	public function testMimeParserDelegates()
	{
		$html = '<style>.a{color:red}</style><p class="a">Bonjour <img src="cid:logo"></p>';
		$inline = array('logo' => 'data:image/png;base64,iVBORw0KGgo=');
		$r1 = $r2 = null;
		$this->assertSame(InboxSafe::display($html, $inline, $r1), InboxMimeParser::safeHtml($html, $inline, $r2));
		$this->assertSame(InboxSafe::textFrame("a\n<b>"), InboxMimeParser::textDocument("a\n<b>"));
		$this->assertSame(InboxSafe::HTML_DROP, InboxMimeParser::HTML_DROP);
	}

	/**
	 * The display keeps the look of a mail: style blocks, classes, the pictures of the mail; remote pictures counted
	 *
	 * @return void
	 */
	public function testDisplayKeepsTheLook()
	{
		$remote = false;
		$doc = InboxSafe::display('<style>.MsoNormal{color:red}</style><p class="MsoNormal">x <img src="cid:a@b"> <img src="https://x.y/i.png"></p>',
			array('a@b' => 'data:image/png;base64,AAAA'), $remote);
		$this->assertStringContainsString('.MsoNormal{color:red}', $doc);
		$this->assertStringContainsString('class="MsoNormal"', $doc);
		$this->assertStringContainsString('src="data:image/png;base64,AAAA"', $doc);
		$this->assertTrue($remote);
	}

	/**
	 * html() keeps the formatting of a real mail (tables, colours, fonts, links) and drops what would dress it as the
	 * page (class, id, style blocks)
	 *
	 * @return void
	 */
	public function testHtmlKeepsTheFormatting()
	{
		$out = InboxSafe::html('<html><head><style>p{color:red}</style></head><body>'
			.'<table width="600" bgcolor="#eeeeee" cellpadding="4"><tr><td valign="top" style="font-family:Arial;color:#333">'
			.'Bonjour <font color="red" face="Verdana">Paul</font>, <b>merci</b> <a href="https://ex.com/a?b=c">lien</a>'
			.'</td></tr></table><p id="x" class="butAction" style="position:fixed;top:0;margin:0">fin</p></body></html>');
		$this->assertStringContainsString('<table width="600" bgcolor="#eeeeee" cellpadding="4">', $out);
		$this->assertStringContainsString('style="font-family:Arial;color:#333;"', $out);
		$this->assertStringContainsString('<font color="#FF0000" face="Verdana">Paul</font>', $out);
		$this->assertStringContainsString('<b>merci</b>', $out);
		$this->assertStringContainsString('href="https://ex.com/a?b=c"', $out);
		$this->assertStringContainsString('target="_blank"', $out);
		$this->assertStringNotContainsString('class=', $out);
		$this->assertStringNotContainsString('id=', $out);
		$this->assertStringNotContainsString('position', $out);
		$this->assertStringNotContainsString('color:red}', $out);
		$this->assertStringContainsString('<p style="margin:0;">fin</p>', $out);
		$this->assertSame('', InboxSafe::html('   '));
	}

	/**
	 * Pictures: the ones of the mail kept as real pictures, any other data: refused
	 *
	 * @return void
	 */
	public function testHtmlPictures()
	{
		$png = 'data:image/png;base64,'.base64_encode((string) file_get_contents(dirname(__DIR__, 2).'/img/app-192.png'));
		$out = InboxSafe::html('<p><img src="cid:logo" alt="logo"></p>', array('logo' => $png));
		$this->assertStringContainsString('src="'.$png.'"', $out);
		// A data: that is not a picture (HTML disguised)
		$fake = 'data:image/png;base64,'.base64_encode('<script>alert(1)</script>');
		$this->assertStringNotContainsString('base64', InboxSafe::html('<img src="'.$fake.'">'));
		$this->assertStringNotContainsString('src=', InboxSafe::html('<img src="data:text/html;base64,PHNjcmlwdD4=">'));
	}

	/**
	 * line(): no tag (in entities either), no double quote, no control nor bidi override, single spaces, cut
	 *
	 * @return void
	 */
	public function testLine()
	{
		$this->assertSame("L'Atelier du Bois", InboxSafe::line("  L'Atelier \t du   Bois  "));
		$this->assertSame('Paul', InboxSafe::line('<b>Paul</b><script>alert(1)</script>'));
		$this->assertSame('Paul', InboxSafe::line('&lt;img src=x onerror=alert(1)&gt;Paul'));
		$this->assertSame('Paul', InboxSafe::line('&amp;lt;script&amp;gt;Paul'));
		$this->assertSame("x'/autofocus/onfocus=alert(1)//@a.b", InboxSafe::line('x"/autofocus/onfocus=alert(1)//@a.b'));
		$this->assertSame("a 'b'", InboxSafe::line('a `b`'));
		$this->assertSame("a \u{2039} b", InboxSafe::line('a < b'));
		$this->assertSame('Société Générale', InboxSafe::line("Soci\u{0065}\u{0301}te\u{0301} G&eacute;n&eacute;rale"));
		$this->assertSame('evil.exe', InboxSafe::line("evil\u{202E}.exe"));
		$this->assertSame('a b c', InboxSafe::line("a\nb\r\nc\x00"));
		$this->assertSame('a b', InboxSafe::line("a\u{2028}b"));
		$this->assertSame('Abc', InboxSafe::line('Abcdef', 3));
		$this->assertSame(str_repeat('é', 300), InboxSafe::line(str_repeat('é', 300), 0));
		// Not UTF-8 (Latin-1 header): converted, not lost
		$this->assertSame('Café', InboxSafe::line("Caf\xE9"));
	}

	/**
	 * lines(): as line(), line breaks kept (two empty lines at most)
	 *
	 * @return void
	 */
	public function testLines()
	{
		$this->assertSame("12 rue\nBP 4\n\n75000 Paris", InboxSafe::lines("12 rue  &lt;b&gt;  \r\nBP 4\n\n\n\n75000 Paris"));
		$this->assertSame("12 rue\nBP 4\n\n75000 Paris", InboxSafe::lines("12 rue <script>x</script> \r\nBP 4\n\n\n\n75000 Paris"));
		$this->assertSame("a\nb", InboxSafe::lines("a\u{2029}b"));
	}

	/**
	 * textToHtml(): escaped, never stripped; lines as <br>
	 *
	 * @return void
	 */
	public function testTextToHtml()
	{
		$this->assertSame('Utilisez &lt;b&gt; pour le gras', InboxSafe::textToHtml('Utilisez <b> pour le gras'));
		$this->assertStringNotContainsString('<img', InboxSafe::textToHtml('<img src=x onerror=alert(1)>'));
		$this->assertSame("a<br>\nb", InboxSafe::textToHtml("a\r\nb"));
		$this->assertSame('L&#039;Atelier &quot;Bois&quot;', InboxSafe::textToHtml('L\'Atelier "Bois"'));
		$this->assertSame('ab', InboxSafe::textToHtml("a\x01\u{202E}b"));
	}

	/**
	 * An address between angle brackets is not a tag: kept (organizer of an invitation, report of a failed sending)
	 *
	 * @return void
	 */
	public function testLineKeepsAddresses()
	{
		$this->assertSame("Paul Martin \u{2039}paul@ex.fr\u{203A}", InboxSafe::line('Paul Martin <paul@ex.fr>'));
		$this->assertSame("Paul \u{2039}paul@ex.fr\u{203A}", InboxSafe::line('Paul &lt;paul@ex.fr&gt;'));
		// Not an address: a tag with an @ in an attribute stays a tag
		$this->assertSame('x', InboxSafe::line('<img src=x onerror="a@b">x'));
		$this->assertSame('x', InboxSafe::line('<a href="mailto:a@b.c">x</a>'));
	}

	/**
	 * Lot S2: the values written in Dolibarr go through the one door
	 *
	 * @return void
	 */
	public function testWritePoints()
	{
		// Agenda (invitation, report of a failed sending, sending recorded on a piece)
		$this->assertSame("Re: Devis 'urgent'", InboxAgenda::field('Re: Devis "urgent" <script>alert(1)</script>'));
		$this->assertSame('Utilisez &lt;b&gt;', InboxAgenda::note('Utilisez <b>'), 'escaped, not removed');

		// Quick creation of a thirdparty: values of the panel, then extrafields by their type
		$this->assertSame('Paul', InboxLead::cleanValue('name', '&lt;script&gt;alert(1)&lt;/script&gt;Paul'));
		$this->assertSame("L'Atelier 'Bois'", InboxLead::cleanValue('name', 'L\'Atelier "Bois"'));
		$this->assertSame('', InboxLead::cleanValue('email', '<img src=x onerror=alert(1)>'));
		$this->assertSame("o'neil@a.fr", InboxLead::cleanValue('email', 'O"Neil@A.fr'), 'double quote as an apostrophe, as everywhere');
		$def = function ($type, $param = array(), $size = '') {
			return array('type' => $type, 'size' => $size, 'param' => $param);
		};
		$this->assertSame('Abc', InboxLead::extrafieldValue($def('varchar', array(), '3'), 'Abcdef'));
		$this->assertSame('&lt;b&gt;x', InboxLead::extrafieldValue($def('html'), '<b>x'), 'HTML field: escaped');
		$this->assertSame(12, InboxLead::extrafieldValue($def('int'), '12'));
		$this->assertNull(InboxLead::extrafieldValue($def('int'), '12; DROP'));
		$this->assertSame(1.5, InboxLead::extrafieldValue($def('price'), '1,5'));
		$this->assertSame('b', InboxLead::extrafieldValue($def('select', array('options' => array('a' => 'A', 'b' => 'B'))), 'b'));
		$this->assertNull(InboxLead::extrafieldValue($def('select', array('options' => array('a' => 'A'))), '<img src=x>'), 'not a key of the list');
		$this->assertNull(InboxLead::extrafieldValue($def('sellist'), '1'), 'lists of the database: never from the panel');
		$this->assertNull(InboxLead::extrafieldValue($def('varchar'), ''));

		// « Create ▾ »: values of a mail in the address of a page of the core
		$row = (object) array('from_email' => 'jane@client.inbox-test.invalid', 'from_name' => 'Jane "x" <b>Martin</b>', 'subject' => 'Devis <img src=x onerror=alert(1)>',
			'requester_email' => '', 'requester_name' => '', 'source_data' => '');
		$creator = new InboxCreate($GLOBALS['db']);
		$url = urldecode($creator->createUrl('contact', $row, array('soc' => 0)));
		$this->assertStringNotContainsString('<', $url);
		$this->assertStringNotContainsString('"', $url);
		$this->assertStringContainsString("lastname='x' Martin", $url);
		$this->assertStringContainsString('label=Devis', urldecode($creator->createUrl('action', $row, array('soc' => 0))));
		$row->requester_email = 'r@client.inbox-test.invalid';
		$row->requester_name = 'R';
		$row->source_data = json_encode(array('company' => '&lt;svg onload=alert(1)&gt;ACME', 'town' => 'Lyon"/onfocus=x'));
		$url = urldecode($creator->createUrl('societe', $row, array('soc' => 0)));
		$this->assertStringContainsString('name=ACME', $url);
		$this->assertStringContainsString("town=Lyon'/onfocus=x", $url);
		$this->assertStringNotContainsString('svg', $url);
	}

	/**
	 * Audit INBX-089: a semicolon (or any separator) inside a quoted display name never takes the sender away
	 *
	 * @return void
	 */
	public function testSenderWithSemicolon()
	{
		$cases = array(
			'"Service ; Compta" <compta@ex.example>' => array('compta@ex.example', 'Service ; Compta'),
			'"a;b" <x@ex.example>' => array('x@ex.example', 'a;b'),
			'"\\"><img src=x onerror=\\"a(\'N\');b\\">" <n@ex.example>' => array('n@ex.example', '"><img src=x onerror="a(\'N\');b">'),
			'Paul Dupont <paul@ex.example>' => array('paul@ex.example', 'Paul Dupont'),
			'=?UTF-8?B?w4l0w6k=?= <ete@ex.example>' => array('ete@ex.example', 'Été'),
			'paul@ex.example (Paul via site)' => array('paul@ex.example', 'Paul via site'),
		);
		foreach ($cases as $from => $expected) {
			$m = InboxMimeParser::parse("From: ".$from."\r\nTo: a@ex.example\r\nSubject: s\r\n\r\nx");
			$this->assertSame($expected, array($m->fromEmail, $m->fromName), $from);
		}
		$m = InboxMimeParser::parse("From: x@ex.example\r\nTo: \"A;B\" <a@ex.example>, b@ex.example; Equipe: c@ex.example, \"D, E\" <d@ex.example>;\r\n\r\nx");
		$this->assertSame(array('a@ex.example', 'b@ex.example', 'c@ex.example', 'd@ex.example'), array_column($m->to, 'email'));
		$this->assertSame('A;B', $m->to[0]['name']);
		// The index reads the same
		dol_include_once('/inbox/class/inboxheaderparser.class.php');
		$h = InboxHeaderParser::parse("From: \"Service ; Compta\" <compta@ex.example>\r\nSubject: s\r\n");
		$this->assertSame('compta@ex.example', $h['from_email']);
	}

	/**
	 * Audit INBX-091: a long file name keeps its extension
	 *
	 * @return void
	 */
	public function testLongFileNameKeepsExtension()
	{
		$name = InboxMimeParser::fileName(str_repeat('a', 300).'.png');
		$this->assertSame(150, mb_strlen($name, 'UTF-8'));
		$this->assertStringEndsWith('.png', $name);
		$this->assertSame('court.pdf', InboxMimeParser::fileName('court.pdf'));
	}

	/**
	 * Audit INBX-090: values cut to the length of their column before the creation
	 *
	 * @return void
	 */
	public function testFitColumns()
	{
		$lead = new InboxLead($GLOBALS['db']);
		$fit = new ReflectionMethod($lead, 'fitColumns');
		$fit->setAccessible(true);
		$c = new stdClass();
		$c->lastname = str_repeat('é', 300);
		$c->firstname = 'Jean';
		$c->phone_pro = str_repeat('1', 300);
		$fit->invoke($lead, $c, 'socpeople');
		$this->assertLessThanOrEqual(50, mb_strlen($c->lastname, 'UTF-8'));
		$this->assertGreaterThan(10, mb_strlen($c->lastname, 'UTF-8'));
		$this->assertSame('Jean', $c->firstname);
		$this->assertLessThanOrEqual(30, strlen($c->phone_pro));
		$s = new stdClass();
		$s->name = str_repeat('x', 300);
		$fit->invoke($lead, $s, 'societe');
		$this->assertLessThan(300, strlen($s->name));
	}

	/**
	 * A fragment that the page can show: allowed elements only, no event, no script scheme, no class, no id
	 *
	 * @param string $html   Fragment
	 * @param string $name   Name of the case
	 * @param bool   $strict html(): allow list of HTMLPurifier, no class; display(): anything but InboxSafe::HTML_DROP
	 * @return void
	 */
	protected function assertSafeFragment($html, $name, $strict = true)
	{
		$allowed = explode(',', InboxSafe::PURE_ELEMENTS);
		$doc = new DOMDocument();
		$previous = libxml_use_internal_errors(true);
		$doc->loadHTML('<?xml encoding="UTF-8"><div id="root">'.$html.'</div>', LIBXML_NONET | LIBXML_HTML_NODEFDTD | LIBXML_HTML_NOIMPLIED);
		libxml_clear_errors();
		libxml_use_internal_errors($previous);
		$xpath = new DOMXPath($doc);
		foreach ($xpath->query('//div[@id="root"]//*') as $node) {
			/** @var DOMElement $node */
			if ($strict) {
				$this->assertContains(strtolower($node->nodeName), $allowed, $name.': element '.$node->nodeName.' in '.$html);
			} else {
				$this->assertNotContains(strtolower($node->nodeName), InboxSafe::HTML_DROP, $name.': element '.$node->nodeName.' in '.$html);
			}
			foreach ($node->attributes as $attr) {
				$this->assertDoesNotMatchRegularExpression($strict ? '/^on|^id$|^class$|^srcset$|^formaction$/i' : '/^on|^srcset$|^formaction$/i', $attr->name, $name.': attribute '.$attr->name);
				$this->assertDoesNotMatchRegularExpression('/^\s*(javascript|vbscript|data:(?!image\/))/i', $attr->value, $name.': value '.$attr->value);
				if ($strict) {
					$this->assertDoesNotMatchRegularExpression('/expression\s*\(|url\s*\(/i', $attr->name === 'style' ? $attr->value : '', $name.': style');
				}
			}
		}
		$this->assertStringNotContainsStringIgnoringCase('<script', $html, $name);
	}

	/**
	 * Hosts of the remote pictures (1.149.0): img, background, url() of a style; only the ones a CSP can name
	 *
	 * @return void
	 */
	public function testRemoteHosts()
	{
		$html = '<img src="https://WWW.Example.org/logo.png"><img src="http://cdn.example.net:8080/a.gif">'
			.'<td background="https://bg.example.com/b.jpg" style="background:url(\'https://css.example.com/c.png\') no-repeat, url(https://css2.example.com/d.png)">x</td>'
			.'<img src="https://user:pw@login.example.com/x.png"><img src="https://[::1]/x.png"><img src="https://exämple.de/x.png">'
			.'<img src="cid:abc"><img src="data:image/png;base64,iVBORw0KGgo=">';
		$remote = false;
		$hosts = null;
		InboxMimeParser::safeHtml($html, array(), $remote, $hosts);
		$this->assertTrue($remote);
		$this->assertSame(array('www.example.org', 'cdn.example.net:8080', 'bg.example.com', 'css.example.com', 'css2.example.com'), array_values($hosts));

		$remote = true;
		InboxMimeParser::safeHtml('<p>no picture</p>', array(), $remote, $hosts);
		$this->assertFalse($remote);
		$this->assertSame(array(), $hosts);
	}
}
