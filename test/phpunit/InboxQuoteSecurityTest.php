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
 * \file    inbox/test/phpunit/InboxQuoteSecurityTest.php
 * \ingroup inbox
 * \brief   1.78.0 (lot F1): remote pictures of a quote (trackers always out, others by the setting), security settings
 *          of the administrator (« always show the pictures », unsubscribe, alert on bank details)
 */

// From the command line only, never from the web: test/ stays in the Git branches
if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

require_once __DIR__.'/InboxTestCase.php';
dol_include_once('/inbox/class/inboxreader.class.php');
dol_include_once('/inbox/class/inboxcomposer.class.php');

/**
 * Settings changed in $conf->global only, put back after each test
 */
class InboxQuoteSecurityTest extends InboxTestCase
{
	/** Settings of the tests */
	const SETTINGS = array('INBOX_QUOTE_REMOTE_IMAGES', 'INBOX_IMAGES_ALWAYS', 'INBOX_IMAGES_OWN_DOMAINS', 'INBOX_UNSUBSCRIBE', 'INBOX_BANK_CHECK');

	/** @var array<string,mixed> */
	protected $saved = array();

	/**
	 * @return void
	 */
	protected function setUp(): void
	{
		global $conf;

		parent::setUp();
		foreach (self::SETTINGS as $k) {
			$this->saved[$k] = $conf->global->$k ?? null;
			unset($conf->global->$k);
		}
	}

	/**
	 * @return void
	 */
	protected function tearDown(): void
	{
		global $conf;

		foreach ($this->saved as $k => $v) {
			if ($v === null) {
				unset($conf->global->$k);
			} else {
				$conf->global->$k = $v;
			}
		}
		$this->db->query("DELETE FROM ".$this->db->prefix()."inbox_image_allow WHERE address LIKE '%f1.inbox-test.invalid'");
		parent::tearDown();
	}

	/**
	 * @param string $name Setting
	 * @param string $value Value
	 * @return void
	 */
	protected function set($name, $value)
	{
		global $conf;

		$conf->global->$name = $value;
	}

	/**
	 * @return void
	 */
	public function testQuoteRemotePictures()
	{
		$html = '<p>Hello</p><img src="https://news.invalid/logo.png" alt="ACME &amp; Co">'
			.'<img src="https://news.invalid/open?u=42" width="1" height="1">'
			.'<img src="https://track.invalid/x.gif" style="width:1px;height:1px">'
			.'<img src="data:image/png;base64,iVBORw0KGgo=" alt="inline">'
			.'<td background="https://news.invalid/bg.jpg" style="background-image:url(\'https://news.invalid/bg2.jpg\')">x</td>';

		// Default: keep, the trackers always out
		$r = array('trackers' => 0, 'images' => 0);
		$out = InboxComposer::quoteRemote($html, 'reply', $r);
		$this->assertSame(array('trackers' => 2, 'images' => 0), $r);
		$this->assertStringContainsString('https://news.invalid/logo.png', $out);
		$this->assertStringNotContainsString('open?u=42', $out);
		$this->assertStringNotContainsString('track.invalid', $out);
		$this->assertStringContainsString('https://news.invalid/bg.jpg', $out, 'kept: nothing changed but the trackers');

		// Taken out of the answers: their text stays, the data: picture and the forward untouched
		$this->set('INBOX_QUOTE_REMOTE_IMAGES', 'reply');
		$r = array('trackers' => 0, 'images' => 0);
		$out = InboxComposer::quoteRemote($html, 'replyall', $r);
		$this->assertSame(array('trackers' => 2, 'images' => 1), $r);
		$this->assertStringNotContainsString('news.invalid', $out, 'no remote address left, backgrounds included');
		$this->assertStringContainsString('[ACME &amp; Co]', $out, 'the alternative text, escaped');
		$this->assertStringContainsString('data:image/png', $out);
		$r = array('trackers' => 0, 'images' => 0);
		$out = InboxComposer::quoteRemote($html, 'forward', $r);
		$this->assertSame(array('trackers' => 2, 'images' => 0), $r, 'a forward keeps its pictures with « reply »');
		$this->assertStringContainsString('https://news.invalid/logo.png', $out);

		// Answers and forwards
		$this->set('INBOX_QUOTE_REMOTE_IMAGES', 'all');
		$r = array('trackers' => 0, 'images' => 0);
		$out = InboxComposer::quoteRemote($html, 'forward', $r);
		$this->assertSame(array('trackers' => 2, 'images' => 1), $r);
		$this->assertStringNotContainsString('news.invalid', $out);

		// The alternative text stays text
		$r = array('trackers' => 0, 'images' => 0);
		$this->assertSame('[1 &lt; 2 &quot;b&quot;]', InboxComposer::quoteRemote('<img src="https://x.invalid/a.png" alt=\'1 &lt; 2 "b"\'>', 'reply', $r));

		$this->assertTrue(InboxReader::isTracker('<img src="https://x.invalid/a.png" height="0">'));
		$this->assertFalse(InboxReader::isTracker('<img src="cid:abc" width="1">'), 'a picture of the mail is never a tracker');
		$this->assertFalse(InboxReader::isTracker('<img src="https://x.invalid/photo.jpg" width="120">'));
	}

	/**
	 * @return void
	 */
	public function testImagesAlways()
	{
		$p = $this->db->prefix();
		$uid = (int) $this->admin->id;
		$this->db->query("INSERT INTO ".$p."inbox_image_allow (fk_user, address, date_creation) VALUES (".$uid.", '@f1.inbox-test.invalid', '".$this->db->idate(dol_now())."')");
		$reader = new InboxReader($this->db);
		$this->assertSame('domain', InboxReader::imagesAlwaysMode());
		$this->assertTrue($reader->imagesAllowed($this->admin, 'news@f1.inbox-test.invalid'), 'by its domain');
		$this->set('INBOX_IMAGES_ALWAYS', 'sender');
		$this->assertFalse($reader->imagesAllowed($this->admin, 'news@f1.inbox-test.invalid'), 'a domain no longer counts');
		$this->db->query("INSERT INTO ".$p."inbox_image_allow (fk_user, address, date_creation) VALUES (".$uid.", 'news@f1.inbox-test.invalid', '".$this->db->idate(dol_now())."')");
		$this->assertTrue($reader->imagesAllowed($this->admin, 'news@f1.inbox-test.invalid'));
		$this->set('INBOX_IMAGES_ALWAYS', 'never');
		$this->assertFalse($reader->imagesAllowed($this->admin, 'news@f1.inbox-test.invalid'), 'never: each mail on a click');
		$this->set('INBOX_IMAGES_ALWAYS', 'anything');
		$this->assertSame('domain', InboxReader::imagesAlwaysMode(), 'an unknown value is the default');
	}

	/**
	 * Hosts of the pictures (1.149.0): our own domains and the hosts chosen, whoever sends; the CSP of the frame
	 *
	 * @return void
	 */
	public function testImageHosts()
	{
		$p = $this->db->prefix();
		$this->box(true);
		$reader = new InboxReader($this->db);
		$hosts = array('inbox-test.invalid' => 'inbox-test.invalid', 'www.inbox-test.invalid' => 'www.inbox-test.invalid',
			'cdn.f1.inbox-test.invalid' => 'cdn.f1.inbox-test.invalid', 'img.f1.invalid:8443' => 'img.f1.invalid:8443', 'evil-inbox-test.invalid' => 'evil-inbox-test.invalid');
		$allowed = $reader->imageHostsAllowed($this->admin, $hosts);
		$this->assertSame('own', $allowed['inbox-test.invalid'] ?? '', 'domain of a mailbox');
		$this->assertSame('own', $allowed['www.inbox-test.invalid'] ?? '', 'its subdomains');
		$this->assertSame('own', $allowed['cdn.f1.inbox-test.invalid'] ?? '');
		$this->assertArrayNotHasKey('evil-inbox-test.invalid', $allowed, 'a name ending the same is not a subdomain');
		$this->assertArrayNotHasKey('img.f1.invalid:8443', $allowed);

		// Turned off: only the hosts chosen by the user
		$this->set('INBOX_IMAGES_OWN_DOMAINS', '0');
		$this->assertSame(array(), $reader->imageHostsAllowed($this->admin, $hosts));
		$this->db->query("INSERT INTO ".$p."inbox_image_allow (fk_user, address, date_creation) VALUES (".((int) $this->admin->id).", 'cdn.f1.inbox-test.invalid', '".$this->db->idate(dol_now())."')");
		$this->assertSame(array('cdn.f1.inbox-test.invalid' => 'user'), $reader->imageHostsAllowed($this->admin, $hosts), 'exact host only');
		$this->assertFalse($reader->imagesAllowed($this->admin, 'news@cdn.f1.inbox-test.invalid'), 'a host is not a sender');
		$this->set('INBOX_IMAGES_ALWAYS', 'never');
		$this->assertSame(array(), $reader->imageHostsAllowed($this->admin, $hosts), 'never: the choices of the users stop');
		$this->set('INBOX_IMAGES_OWN_DOMAINS', '1');
		$this->assertSame('own', $reader->imageHostsAllowed($this->admin, $hosts)['inbox-test.invalid'] ?? '', 'never: our own domains still shown');

		// CSP: both schemes, the port kept, nothing else written
		$this->assertSame(' https://a.invalid http://a.invalid https://b.invalid:8443 http://b.invalid:8443',
			InboxReader::imageHostsCsp(array('a.invalid' => 'own', 'b.invalid:8443' => 'user', "x.invalid; script-src *" => 'user')));
		$this->assertSame('', InboxReader::imageHostsCsp(array()));

		// Hosts of trackers only: never offered
		$html = '<img src="https://t.invalid/o.gif" width="1" height="1"><img src="https://mixed.invalid/open?u=1" width="1">';
		$html .= '<img src="https://mixed.invalid/logo.png" width="90"><img src="https://logo.invalid/a.png">';
		$this->assertSame(array('t.invalid' => true), InboxReader::trackerHosts($html));
	}

	/**
	 * @return void
	 */
	public function testUnsubscribeSetting()
	{
		$both = array(array('List-Unsubscribe', '<mailto:leave@list.invalid>, <https://list.invalid/u>'), array('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click'));
		$https = array(array('List-Unsubscribe', '<https://list.invalid/u>'), array('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click'));
		$signed = array('dkim' => 'pass');
		$this->assertTrue(InboxReader::unsubscribeView($both, $signed)['oneclick']);
		$this->set('INBOX_UNSUBSCRIBE', 'mail');
		$view = InboxReader::unsubscribeView($both, $signed);
		$this->assertFalse($view['oneclick'], 'by mail only: the server calls no address of the sender');
		$this->assertSame('leave@list.invalid', $view['mailto']);
		$this->assertNull(InboxReader::unsubscribeView($https, $signed), 'nothing to offer without a mailto');
		$this->set('INBOX_UNSUBSCRIBE', 'off');
		$this->assertNull(InboxReader::unsubscribeView($both, $signed));
	}
}
