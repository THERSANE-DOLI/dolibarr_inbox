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
 * \file    inbox/test/phpunit/InboxTrustedDomainTest.php
 * \ingroup inbox
 * \brief   1.59.0: recognized domains (normalization, refusals, matching, lookalikes, sender and links of a mail)
 */

require_once __DIR__.'/InboxTestCase.php';
dol_include_once('/inbox/class/inboxtrusteddomain.class.php');
dol_include_once('/inbox/class/inboxknownsenders.class.php');

/**
 * The domains of the tests are labelled « ZzTrusted… » (removed by InboxTestCase::cleanup())
 */
class InboxTrustedDomainTest extends InboxTestCase
{
	/** @var string Setting before the test */
	protected $servers = '';

	/**
	 * @return void
	 */
	protected function setUp(): void
	{
		global $conf;

		parent::setUp();
		$this->servers = getDolGlobalString('INBOX_AUTH_SERVERS');
		$conf->global->INBOX_AUTH_SERVERS = '';
	}

	/**
	 * @return void
	 */
	protected function tearDown(): void
	{
		global $conf;

		$conf->global->INBOX_AUTH_SERVERS = $this->servers;
		parent::tearDown();
	}

	/**
	 * @return void
	 */
	public function testNormalize()
	{
		$this->assertSame('colissimo-laposte.info', InboxTrustedDomain::normalize('https://WWW.Colissimo-LaPoste.info/suivi?x=1'));
		$this->assertSame('notif-colissimo-laposte.info', InboxTrustedDomain::normalize('noreply@notif-colissimo-laposte.info'));
		$this->assertSame('exemple.fr', InboxTrustedDomain::normalize('*.exemple.fr.'));
		$this->assertSame('xn--mnchen-3ya.de', InboxTrustedDomain::normalize('MÜNCHEN.de'));
		$this->assertSame('', InboxTrustedDomain::normalize('a..b'));
		$this->assertSame('', InboxTrustedDomain::normalize('localhost'));
		$this->assertSame('', InboxTrustedDomain::normalize('<b>x</b>.com'));
	}

	/**
	 * @return void
	 */
	public function testPublicSuffixAndRefusals()
	{
		$this->assertSame('y.gouv.fr', InboxTrustedDomain::registrable('x.y.gouv.fr'));
		$this->assertSame('foo.co.uk', InboxTrustedDomain::registrable('a.foo.co.uk'));
		$this->assertSame('', InboxTrustedDomain::registrable('co.uk'));
		$this->assertSame('InboxTrustedErrorPublicSuffix', InboxTrustedDomain::refusal('info'));
		$this->assertSame('InboxTrustedErrorPublicSuffix', InboxTrustedDomain::refusal('gouv.fr'));
		$this->assertSame('InboxTrustedErrorOpenService', InboxTrustedDomain::refusal('gmail.com'));
		$this->assertSame('InboxTrustedErrorOpenService', InboxTrustedDomain::refusal('r.sendibm1.com'));
		// Social networks (1.59.1): the links of a signature, never an organisation
		$this->assertSame('InboxTrustedErrorOpenService', InboxTrustedDomain::refusal('facebook.com'));
		$this->assertSame('InboxTrustedErrorOpenService', InboxTrustedDomain::refusal('fr.linkedin.com'));
		// Shared platform of the private section of the list
		$this->assertSame('InboxTrustedErrorOpenService', InboxTrustedDomain::refusal('someone.github.io'));
		$this->assertSame('InboxTrustedErrorOwnDomain', InboxTrustedDomain::refusal('mail.exemple.fr', array('exemple.fr')));
		$this->assertSame('InboxTrustedErrorDomain', InboxTrustedDomain::refusal(''));
		$this->assertSame('', InboxTrustedDomain::refusal('colissimo-laposte.info', array('exemple.fr')));
	}

	/**
	 * @return void
	 */
	public function testMatchesNeverContains()
	{
		$e = array('domain' => 'colissimo-laposte.info', 'subdomains' => 1);
		$this->assertTrue(InboxTrustedDomain::matches('colissimo-laposte.info', $e));
		$this->assertTrue(InboxTrustedDomain::matches('a.b.colissimo-laposte.info', $e));
		$this->assertFalse(InboxTrustedDomain::matches('colissimo-laposte.info.evil.com', $e));
		$this->assertFalse(InboxTrustedDomain::matches('fake-colissimo-laposte.info', $e));
		$e['subdomains'] = 0;
		$this->assertFalse(InboxTrustedDomain::matches('a.colissimo-laposte.info', $e));
	}

	/**
	 * @return void
	 */
	public function testLookalikes()
	{
		$list = array(array('domain' => 'colissimo-laposte.info', 'label' => 'ZzTrusted', 'subdomains' => 1));
		$case = function ($host, $sender = false) use ($list) {
			$r = InboxTrustedDomain::lookalike(InboxTrustedDomain::normalize($host), $list, $sender);
			return $r ? $r['case'] : 0;
		};
		$this->assertSame(1, $case('colissimo-laposte.com'));
		$this->assertSame(1, $case('colissimo-laposte.github.io'));
		$this->assertSame(2, $case('co1issimo-laposte.info'));
		$this->assertSame(2, $case('colissirno-laposte.info'));
		// Cyrillic « о » in place of the Latin one
		$this->assertSame(2, $case("c\u{043E}lissimo-laposte.info"));
		$this->assertSame(3, $case('colissimo-lapost.info'));
		$this->assertSame(3, $case('colisimo-laposte.info'));
		// Containing: the sender only
		$this->assertSame(4, $case('secure-colissimo-laposte.com', true));
		$this->assertSame(0, $case('secure-colissimo-laposte.com'));
		// Never: the domain itself, a subdomain, another domain, an open service
		$this->assertSame(0, $case('suivi.colissimo-laposte.info', true));
		$this->assertSame(0, $case('laposte.fr', true));
		$this->assertSame(0, $case('exemple.com', true));
		$short = array(array('domain' => 'laposte.fr', 'label' => 'ZzTrusted', 'subdomains' => 1));
		$this->assertNull(InboxTrustedDomain::lookalike('laposte.net', $short, true));
		$this->assertNotNull(InboxTrustedDomain::lookalike('lap0ste.fr', $short, true));
		// A mailbox of inbox is never a lookalike
		$this->assertNull(InboxTrustedDomain::lookalike('lapost.fr', $short, true, array('lapost.fr')));
	}

	/**
	 * @return void
	 */
	public function testListAndSender()
	{
		$t = new InboxTrustedDomain($this->db);
		$this->assertLessThan(0, $t->create($this->admin, 'gmail.com', 'ZzTrusted gmail'));
		$this->assertSame('InboxTrustedErrorOpenService', $t->error);
		$this->assertLessThan(0, $t->create($this->admin, 'colissimo-zztest.info', ' '));
		$this->assertSame('InboxTrustedErrorLabel', $t->error);
		$id = $t->create($this->admin, 'https://www.Colissimo-ZzTest.info/suivi', 'ZzTrusted Poste', 1, 'Vu <b>sur</b> le site');
		$this->assertGreaterThan(0, $id);
		$this->assertLessThan(0, $t->create($this->admin, 'colissimo-zztest.info', 'ZzTrusted bis'));
		$this->assertSame('InboxTrustedErrorExists', $t->error);
		$row = null;
		foreach ($t->fetchAll() as $r) {
			if ($r['id'] === $id) {
				$row = $r;
			}
		}
		$this->assertNotNull($row);
		$this->assertSame('colissimo-zztest.info', $row['domain']);
		$this->assertSame('Vu sur le site', $row['note']);

		$dkim = array('spf' => 'pass', 'dkim' => 'pass', 'dmarc' => '', 'fail' => false, 'trusted' => false, 'dkim_domains' => array('colissimo-zztest.info'));
		$ok = $t->senderVerdict('noreply@notif.colissimo-zztest.info', 'support@other-zztest.com', $dkim, true);
		$this->assertSame('ok', $ok['state']);
		$this->assertSame('DKIM', $ok['by']);
		$this->assertSame('other-zztest.com', $ok['reply_to']);
		// Not verifiable: nothing at all; failed: red
		$this->assertNull($t->senderVerdict('noreply@colissimo-zztest.info', '', array('fail' => false), false));
		$fail = $t->senderVerdict('noreply@colissimo-zztest.info', '', array('dmarc' => 'fail', 'fail' => true, 'trusted' => false), false);
		$this->assertSame('unproven', $fail['state']);
		// Declared servers and the result not written by them: not reliable, nothing said
		$GLOBALS['conf']->global->INBOX_AUTH_SERVERS = 'mx.inbox-test.invalid';
		$this->assertNull($t->senderVerdict('noreply@colissimo-zztest.info', '', array('dmarc' => 'fail', 'fail' => true, 'trusted' => false), false));
		$GLOBALS['conf']->global->INBOX_AUTH_SERVERS = '';
		$like = $t->senderVerdict('noreply@co1issimo-zztest.info', '', $dkim, true);
		$this->assertSame('lookalike', $like['state']);
		$this->assertSame('danger', $like['level']);
		$this->assertNull($t->senderVerdict('someone@exemple.com', '', $dkim, true));

		// Links: recognized and lookalikes, the text of a link counts as a host too
		$html = '<p><a href="https://suivi.colissimo-zztest.info/x">www.colissimo-zztest.info</a> <a href="http://colissimo-zztest.com/login">Suivre</a>'
			.' <a href="https://exemple.com/a">exemple.com</a> <a href="mailto:a@b.fr">mail</a> <a href="https://www.facebook.com/x">f</a></p>';
		$v = $t->linkVerdicts($html, '', true);
		$this->assertSame('trusted', $v['hosts']['suivi.colissimo-zztest.info']['t']);
		$this->assertSame('trusted', $v['hosts']['colissimo-zztest.info']['t']);
		$this->assertSame('lookalike', $v['hosts']['colissimo-zztest.com']['t']);
		$this->assertArrayNotHasKey('exemple.com', $v['hosts']);
		$this->assertContains('exemple.com', array_column($v['domains'], 'domain'));
		$this->assertNotContains('colissimo-zztest.info', array_column($v['domains'], 'domain'));
		// Offered in the dialog « Recognize this domain… », a lookalike says what it looks like
		$offer = array_column($v['domains'], 'like', 'domain');
		$this->assertNull($offer['exemple.com']);
		$this->assertArrayNotHasKey('facebook.com', $offer);
		$this->assertSame('colissimo-zztest.info', $offer['colissimo-zztest.com']['domain']);

		$this->assertSame(1, $t->update($this->admin, $id, 'colissimo-zztest.info', 'ZzTrusted La Poste', 0, ''));
		$this->assertNull($t->find('notif.colissimo-zztest.info'));
		$this->assertSame(0, $t->update($this->admin, 999999999, 'x-zztest.com', 'ZzTrusted', 1, ''));
		$this->assertSame(1, $t->delete($id));
		$this->assertSame(0, $t->delete($id));
	}
}
