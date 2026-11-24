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
 * \file    inbox/test/phpunit/InboxAutoconfigTest.php
 * \ingroup inbox
 * \brief   Lot H1: servers of a new mailbox found from its address (readers and sources without network)
 */

// From the command line only, never from the web: test/ stays in the Git branches
if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

require_once __DIR__.'/InboxTestCase.php';
dol_include_once('/inbox/class/inboxautoconfig.class.php');

/**
 * Finder without network: the outside sources answer nothing, the others are reachable
 */
class InboxAutoconfigOffline extends InboxAutoconfig
{
	/** @var array<string,string> Address => content served */
	public $served = array();

	/** @var string[] Addresses asked */
	public $asked = array();

	/** @var string[] MX of the domain */
	public $mx = array();

	/** @var array<string,string> SRV record => target */
	public $srvTargets = array();

	/** @var string[] Name servers of the domain */
	public $ns = array();

	/** @var array<string,string> Host => name of its certificate */
	public $certs = array();

	/** @var string[] Hosts whose certificate was read */
	public $certAsked = array();

	/**
	 * @param string      $url  Address
	 * @param string|null $post Body
	 * @return string
	 */
	protected function get($url, $post = null)
	{
		$this->asked[] = $url;
		return $this->served[strtok($url, '?')] ?? '';
	}

	/**
	 * @param string $host Host
	 * @return bool
	 */
	protected function resolves($host)
	{
		return true;
	}

	/**
	 * @param string $domain Domain
	 * @return string[]
	 */
	protected function mxHosts($domain)
	{
		return $this->mx;
	}

	/**
	 * @param string $name Record
	 * @param string $enc  Encryption
	 * @return array{host:string,port:int,encryption:string}|null
	 */
	protected function nsHosts($domain)
	{
		return $this->ns;
	}

	/**
	 * @param string $host Host
	 * @param int    $port Port
	 * @return string
	 */
	protected function certName($host, $port)
	{
		$this->certAsked[] = $host.':'.$port;
		return $this->certs[$host] ?? '';
	}

	/**
	 * @param string $name Record
	 * @param string $enc  Encryption
	 * @return array{host:string,port:int,encryption:string}|null
	 */
	protected function srv($name, $enc)
	{
		return isset($this->srvTargets[$name]) ? self::server($this->srvTargets[$name], 443, $enc) : null;
	}
}

/**
 * Lot H1
 */
class InboxAutoconfigTest extends InboxTestCase
{
	const CONFIG = '<?xml version="1.0"?><clientConfig version="1.1"><emailProvider id="example.org"><domain>example.org</domain><displayName>Example Mail</displayName>'
		.'<incomingServer type="pop3"><hostname>pop.example.org</hostname><port>995</port><socketType>SSL</socketType><username>%EMAILADDRESS%</username></incomingServer>'
		.'<incomingServer type="imap"><hostname>imap.%EMAILDOMAIN%</hostname><port>143</port><socketType>STARTTLS</socketType><username>%EMAILLOCALPART%</username><authentication>password-cleartext</authentication></incomingServer>'
		.'<incomingServer type="imap"><hostname>imap.%EMAILDOMAIN%</hostname><port>993</port><socketType>SSL</socketType><username>%EMAILLOCALPART%</username><authentication>OAuth2</authentication></incomingServer>'
		.'<outgoingServer type="smtp"><hostname>smtp.example.org</hostname><port>587</port><socketType>STARTTLS</socketType><username>%EMAILADDRESS%</username></outgoingServer>'
		.'</emailProvider></clientConfig>';

	/**
	 * Autoconfig: IMAP only, encrypted first, placeholders filled, login read, OAuth offered
	 *
	 * @return void
	 */
	public function testParseAutoconfig()
	{
		$r = InboxAutoconfig::parseAutoconfig(self::CONFIG, 'anne@example.org');
		$this->assertSame(array('host' => 'imap.example.org', 'port' => 993, 'encryption' => 'ssl'), $r['imap']);
		$this->assertSame(array('host' => 'smtp.example.org', 'port' => 587, 'encryption' => 'tls'), $r['smtp']);
		$this->assertSame('localpart', $r['login']);
		$this->assertSame('Example Mail', $r['provider_label']);
		$this->assertTrue($r['oauth_offered']);
	}

	/**
	 * Autoconfig: no IMAP, a DOCTYPE (entities), a bad host: nothing
	 *
	 * @return void
	 */
	public function testParseAutoconfigRefused()
	{
		$pop = '<clientConfig><emailProvider><incomingServer type="pop3"><hostname>pop.example.org</hostname><port>995</port><socketType>SSL</socketType></incomingServer></emailProvider></clientConfig>';
		$this->assertNull(InboxAutoconfig::parseAutoconfig($pop, 'a@example.org'));
		$this->assertNull(InboxAutoconfig::parseAutoconfig('<!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]>'.self::CONFIG, 'a@example.org'));
		$bad = str_replace('imap.%EMAILDOMAIN%', 'imap.example.org/../x', self::CONFIG);
		$this->assertNull(InboxAutoconfig::parseAutoconfig($bad, 'a@example.org'));
		$this->assertNull(InboxAutoconfig::parseAutoconfig('not xml', 'a@example.org'));
	}

	/**
	 * Autodiscover (POX of Outlook): IMAP and SMTP with their encryption
	 *
	 * @return void
	 */
	public function testParseAutodiscover()
	{
		$xml = '<?xml version="1.0" encoding="utf-8"?><Autodiscover xmlns="http://schemas.microsoft.com/exchange/autodiscover/responseschema/2006">'
			.'<Response xmlns="http://schemas.microsoft.com/exchange/autodiscover/outlook/responseschema/2006a"><Account><AccountType>email</AccountType><Action>settings</Action>'
			.'<Protocol><Type>IMAP</Type><Server>mail.example.org</Server><Port>993</Port><LoginName>anne@example.org</LoginName><SSL>on</SSL></Protocol>'
			.'<Protocol><Type>SMTP</Type><Server>mail.example.org</Server><Port>587</Port><SSL>on</SSL><Encryption>TLS</Encryption></Protocol>'
			.'</Account></Response></Autodiscover>';
		$r = InboxAutoconfig::parseAutodiscover($xml);
		$this->assertSame(array('host' => 'mail.example.org', 'port' => 993, 'encryption' => 'ssl'), $r['imap']);
		$this->assertSame(array('host' => 'mail.example.org', 'port' => 587, 'encryption' => 'tls'), $r['smtp']);
		$this->assertSame('email', $r['login']);
		$this->assertNull(InboxAutoconfig::parseAutodiscover('<Autodiscover><Response><Error><ErrorCode>600</ErrorCode></Error></Response></Autodiscover>'));
	}

	/**
	 * Domain of a company from a host
	 *
	 * @return void
	 */
	public function testBaseDomain()
	{
		$this->assertSame('ovh.net', InboxAutoconfig::baseDomain('mx1.mail.ovh.net.'));
		$this->assertSame('example.co.uk', InboxAutoconfig::baseDomain('mx.mail.example.co.uk'));
		$this->assertSame('google.com', InboxAutoconfig::baseDomain('aspmx.l.google.com'));
		$this->assertSame('', InboxAutoconfig::baseDomain('localhost'));
	}

	/**
	 * Order of the sources: the file of the domain before the providers; a provider by its MX with OAuth; the ISPDB
	 * not asked unless allowed, then offered
	 *
	 * @return void
	 */
	public function testSources()
	{
		$f = new InboxAutoconfigOffline($this->db);
		$f->guess = false;
		$f->ispdb = false;
		$f->served['https://autoconfig.example.org/mail/config-v1.1.xml'] = self::CONFIG;
		$r = $f->discover('anne@example.org');
		$this->assertTrue($r['found']);
		$this->assertSame('autoconfig', $r['source']);
		$this->assertFalse($r['insecure']);
		$this->assertStringContainsString('emailaddress=anne%40example.org', $f->asked[0]);

		// Microsoft 365 by the MX: OAuth needed when no service is set up
		$f = new InboxAutoconfigOffline($this->db);
		$f->guess = false;
		$f->ispdb = false;
		$f->mx = array('example-org.mail.protection.outlook.com');
		$r = $f->discover('anne@example.org');
		$this->assertSame('provider', $r['source']);
		$this->assertSame('outlook.office365.com', $r['imap']['host']);
		$this->assertSame('MICROSOFT', $r['oauth_provider']);
		if (!$r['oauth_services']) {
			$this->assertSame(array('oauth_needed'), $r['notes']);
		}
		$this->assertFalse($r['ispdb_offer']);
		foreach ($f->asked as $url) {
			$this->assertStringNotContainsString('thunderbird.net', $url);
		}

		// Nothing found: the ISPDB offered, not asked; asked when allowed, with the domain only
		$f = new InboxAutoconfigOffline($this->db);
		$f->guess = false;
		$f->ispdb = false;
		$r = $f->discover('anne@nowhere.example');
		$this->assertFalse($r['found']);
		$this->assertTrue($r['ispdb_offer']);
		$f->ispdb = true;
		$f->served['https://autoconfig.thunderbird.net/v1.1/nowhere.example'] = self::CONFIG;
		$r = $f->discover('anne@nowhere.example');
		$this->assertSame('ispdb', $r['source']);
		$this->assertContains('https://autoconfig.thunderbird.net/v1.1/nowhere.example', $f->asked);
	}

	/**
	 * OAuth services of the core named as its setup shows them: label and name of the provider, never the name of the
	 * callback file (« Microsoft3 »)
	 *
	 * @return void
	 */
	public function testOAuthServiceLabels()
	{
		global $conf;

		$keep = array($conf->modules['oauth'] ?? null, $conf->global->{'OAUTH_MICROSOFT3-Sales_ID'} ?? null, $conf->global->OAUTH_GOOGLE_ID ?? null);
		$conf->modules['oauth'] = 'oauth';
		$conf->global->{'OAUTH_MICROSOFT3-Sales_ID'} = 'id-1';
		$conf->global->OAUTH_GOOGLE_ID = 'id-2';
		try {
			$list = InboxOAuth::listServices();
			$this->assertSame('Sales (Microsoft Exchange Online [SMTP/IMAP])', $list['MICROSOFT3-Sales'] ?? null);
			$this->assertSame('Google', $list['GOOGLE'] ?? null);
		} finally {
			foreach (array('OAUTH_MICROSOFT3-Sales_ID' => $keep[1], 'OAUTH_GOOGLE_ID' => $keep[2]) as $k => $v) {
				if ($v === null) {
					unset($conf->global->$k);
				} else {
					$conf->global->$k = $v;
				}
			}
			if ($keep[0] === null) {
				unset($conf->modules['oauth']);
			} else {
				$conf->modules['oauth'] = $keep[0];
			}
		}
	}

	/**
	 * IONOS: the servers of the country of the contract, read from the MX (current and former names)
	 *
	 * @return void
	 */
	public function testIonosCountry()
	{
		foreach (array('mx00.ionos.fr' => 'fr', 'mx01.ionos.de' => 'de', 'mx00.ionos.co.uk' => 'co.uk', 'mx00.1and1.com' => 'com', 'mx00.kundenserver.de' => 'de') as $mx => $cc) {
			$f = new InboxAutoconfigOffline($this->db);
			$f->guess = false;
			$f->ispdb = false;
			$f->mx = array($mx);
			$r = $f->discover('anne@example.org');
			$this->assertSame('ionos', $r['provider'], $mx);
			$this->assertSame(array('host' => 'imap.ionos.'.$cc, 'port' => 993, 'encryption' => 'ssl'), $r['imap'], $mx);
			$this->assertSame('smtp.ionos.'.$cc, $r['smtp']['host'], $mx);
		}
	}

	/**
	 * o2switch: known by its name servers, the server read in the certificate of the mail server of the domain
	 *
	 * @return void
	 */
	public function testO2switchServer()
	{
		$f = new InboxAutoconfigOffline($this->db);
		$f->guess = false;
		$f->ispdb = false;
		$f->mx = array('mail.example.org');
		$f->ns = array('ns1.o2switch.net', 'ns2.o2switch.net');
		$f->certs['mail.example.org'] = 'mail.robotcop.o2switch.net';
		$r = $f->discover('anne@example.org');
		$this->assertSame('o2switch', $r['provider']);
		$this->assertSame(array('host' => 'robotcop.o2switch.net', 'port' => 993, 'encryption' => 'ssl'), $r['imap']);
		$this->assertSame(array('host' => 'robotcop.o2switch.net', 'port' => 465, 'encryption' => 'ssl'), $r['smtp']);
		$this->assertSame(array('mail.example.org:993'), $f->certAsked);
		$this->assertSame(array(), $r['notes']);

		// The domain has its own certificate: its mail server is kept, to be checked by the test
		$f->certs['mail.example.org'] = 'example.org';
		$r = $f->discover('anne@example.org');
		$this->assertSame('mail.example.org', $r['imap']['host']);
		$this->assertSame(array(), $r['notes']);

		// No answer: its mail server, and the note telling where to find the name of the server
		unset($f->certs['mail.example.org']);
		$r = $f->discover('anne@example.org');
		$this->assertSame('mail.example.org', $r['imap']['host']);
		$this->assertSame(array('o2switch_server'), $r['notes']);

		// Another host with the same kind of MX: name servers elsewhere, not o2switch
		$f = new InboxAutoconfigOffline($this->db);
		$f->guess = false;
		$f->ispdb = false;
		$f->mx = array('mail.example.org');
		$f->ns = array('ns1.example.net');
		$this->assertNotSame('o2switch', $f->discover('anne@example.org')['provider']);
		$this->assertSame(array(), $f->certAsked);
	}

	/**
	 * OVHcloud: MX Plan, Email Pro and Exchange share the MX; the offers with their own server by their SRV record
	 *
	 * @return void
	 */
	public function testOvhOffers()
	{
		$f = new InboxAutoconfigOffline($this->db);
		$f->guess = false;
		$f->ispdb = false;
		$f->mx = array('mx1.mail.ovh.net', 'mx2.mail.ovh.net');
		// MX Plan: no record, servers of MX Plan and the note for the other offers
		$r = $f->discover('anne@example.org');
		$this->assertSame('provider', $r['source']);
		$this->assertSame('ovh', $r['provider']);
		$this->assertSame(array('host' => 'ssl0.ovh.net', 'port' => 993, 'encryption' => 'ssl'), $r['imap']);
		$this->assertSame(465, $r['smtp']['port']);
		$this->assertSame(array('ovh_offer'), $r['notes']);

		// Email Pro: its own server, IMAP 993 SSL, SMTP 587 STARTTLS, no note
		$f->srvTargets['_autodiscover._tcp.example.org'] = 'pro2.mail.ovh.net.';
		$r = $f->discover('anne@example.org');
		$this->assertSame('OVHcloud Email Pro', $r['provider_label']);
		$this->assertSame(array('host' => 'pro2.mail.ovh.net', 'port' => 993, 'encryption' => 'ssl'), $r['imap']);
		$this->assertSame(array('host' => 'pro2.mail.ovh.net', 'port' => 587, 'encryption' => 'tls'), $r['smtp']);
		$this->assertSame(array(), $r['notes']);

		// Exchange
		$f->srvTargets['_autodiscover._tcp.example.org'] = 'ex4.mail.ovh.net';
		$r = $f->discover('anne@example.org');
		$this->assertSame('OVHcloud Exchange', $r['provider_label']);
		$this->assertSame('ex4.mail.ovh.net', $r['imap']['host']);

		// A record to another host is not trusted: servers of MX Plan and the note
		$f->srvTargets['_autodiscover._tcp.example.org'] = 'pro2.mail.ovh.net.attacker.example';
		$r = $f->discover('anne@example.org');
		$this->assertSame('ssl0.ovh.net', $r['imap']['host']);
		$this->assertSame(array('ovh_offer'), $r['notes']);
	}

	/**
	 * A mailbox of the same domain gives its servers first, without asking anything outside
	 *
	 * @return void
	 */
	public function testSameDomainMailbox()
	{
		$this->box(true, array('imap_login' => ''));
		$domain = substr(self::BOX, strpos(self::BOX, '@') + 1);
		$f = new InboxAutoconfigOffline($this->db);
		$f->guess = false;
		$r = $f->discover('other@'.$domain);
		$this->assertSame('mailbox', $r['source']);
		$this->assertSame(self::IMAP_HOST, $r['imap']['host']);
		$this->assertSame('email', $r['login']);
		$this->assertSame(array(), $f->asked);
	}
}
