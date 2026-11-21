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
 * \file    inbox/class/inboxautoconfig.class.php
 * \ingroup inbox
 * \brief   Servers of a new mailbox found from its address (lot H1)
 */

require_once __DIR__.'/inboxservertester.class.php';
require_once __DIR__.'/inboxoauth.class.php';

/**
 * Servers of a mailbox found from its address, by the server of Dolibarr, in the order of Thunderbird:
 *
 * 1. a mailbox of the same domain already in inbox (nothing asked outside);
 * 2. the autoconfig file published by the domain (https://autoconfig.<domain>/mail/config-v1.1.xml, then
 *    /.well-known/autoconfig/ of the domain; http as a last resort, said in the result);
 * 3. Autodiscover of the domain (Exchange, Plesk): the address only, never a password;
 * 4. known providers, by the domain of the address or by its MX (Google, Microsoft 365, OVH...): nothing asked
 *    outside but the DNS;
 * 5. the ISPDB of Thunderbird (autoconfig.thunderbird.net), by the domain then by the domain of its MX: an outside
 *    service of Mozilla, off by default (INBOX_AUTOCONFIG_ISPDB) or asked once by a click;
 * 6. the DNS SRV records of the domain (RFC 6186, RFC 8314);
 * 7. the usual names (imap., mail., smtp.) whose port answers.
 *
 * The result is a proposal: the form shows where it comes from, the connection test checks it. No password is ever
 * sent by the search, and the address goes only to the servers of its own domain (the ISPDB gets the domain only).
 */
class InboxAutoconfig
{
	/** Largest file read (autoconfig, Autodiscover) */
	const MAX_BYTES = 262144;

	/**
	 * Known providers: domains of the addresses, suffixes of their MX, servers, login, OAuth.
	 * oauth: provider whose services of the OAuth module of the core may be used (prefix of their key);
	 * oauth_only: the password is refused by the provider for IMAP (Microsoft 365).
	 * offers: offers of the provider received by the same MX but read on their own numbered server, found by the SRV
	 * record the provider asks for its offers (target matched by « host », its first group = key of « labels »);
	 * without it, the servers of the provider are given with the note « note ».
	 * country: providers whose servers carry the country of the contract (IONOS): suffix of the MX => country, put in
	 * place of {cc} in the servers.
	 * ns: suffixes of the name servers of the domain, for the providers whose MX is the domain itself (o2switch, cPanel).
	 * cert: the real server is the name of the certificate of the mail server of the domain (the shared server answers
	 * with its own certificate when the domain has none): « host » matches it, its first group is the server; without
	 * it, the mail server of the domain is kept with the note « note ».
	 *
	 * @var array<string,array<string,mixed>>
	 */
	const PROVIDERS = array(
		'google' => array('label' => 'Google', 'domains' => array('gmail.com', 'googlemail.com'), 'mx' => array('.google.com', '.googlemail.com'),
			'imap' => array('imap.gmail.com', 993, 'ssl'), 'smtp' => array('smtp.gmail.com', 465, 'ssl'), 'login' => 'email', 'oauth' => 'GOOGLE', 'oauth_only' => false),
		'microsoft' => array('label' => 'Microsoft 365', 'domains' => array(), 'mx' => array('.mail.protection.outlook.com'),
			'imap' => array('outlook.office365.com', 993, 'ssl'), 'smtp' => array('smtp.office365.com', 587, 'tls'), 'login' => 'email', 'oauth' => 'MICROSOFT', 'oauth_only' => true),
		'outlook' => array('label' => 'Outlook.com', 'domains' => array('outlook.com', 'outlook.fr', 'hotmail.com', 'hotmail.fr', 'live.com', 'live.fr', 'msn.com'), 'mx' => array('.olc.protection.outlook.com'),
			'imap' => array('outlook.office365.com', 993, 'ssl'), 'smtp' => array('smtp-mail.outlook.com', 587, 'tls'), 'login' => 'email', 'oauth' => 'MICROSOFT', 'oauth_only' => true),
		// MX Plan, Email Pro and Exchange share the MX: Email Pro (proN) and Exchange (exN) by the SRV autodiscover record
		// that OVHcloud asks for these offers (diagnostic of the domain in its control panel)
		'ovh' => array('label' => 'OVHcloud', 'domains' => array(), 'mx' => array('.mail.ovh.net'),
			'imap' => array('ssl0.ovh.net', 993, 'ssl'), 'smtp' => array('ssl0.ovh.net', 465, 'ssl'), 'login' => 'email', 'oauth' => '', 'oauth_only' => false,
			'offers' => array('record' => '_autodiscover._tcp', 'host' => '/^(pro|ex)[0-9]+\.mail\.ovh\.net$/', 'imap' => array(993, 'ssl'), 'smtp' => array(587, 'tls'),
				'labels' => array('pro' => 'OVHcloud Email Pro', 'ex' => 'OVHcloud Exchange'), 'note' => 'ovh_offer')),
		// IONOS: one pair of MX per country of the contract (mx00/mx01.ionos.fr...), former names 1and1 and kundenserver
		'ionos' => array('label' => 'IONOS', 'domains' => array(),
			'mx' => array('.ionos.fr', '.ionos.de', '.ionos.com', '.ionos.co.uk', '.ionos.es', '.ionos.it', '.1and1.fr', '.1and1.com', '.1and1.co.uk', '.1and1.es', '.1and1.it', '.kundenserver.de'),
			'imap' => array('imap.ionos.{cc}', 993, 'ssl'), 'smtp' => array('smtp.ionos.{cc}', 465, 'ssl'), 'login' => 'email', 'oauth' => '', 'oauth_only' => false,
			'country' => array('.ionos.fr' => 'fr', '.ionos.de' => 'de', '.ionos.com' => 'com', '.ionos.co.uk' => 'co.uk', '.ionos.es' => 'es', '.ionos.it' => 'it',
				'.1and1.fr' => 'fr', '.1and1.com' => 'com', '.1and1.co.uk' => 'co.uk', '.1and1.es' => 'es', '.1and1.it' => 'it', '.kundenserver.de' => 'de')),
		// o2switch: the MX is mail.<domain> on the shared cPanel server; the server is named in its certificate
		'o2switch' => array('label' => 'o2switch', 'domains' => array(), 'mx' => array(), 'ns' => array('.o2switch.net'),
			'imap' => array('{server}', 993, 'ssl'), 'smtp' => array('{server}', 465, 'ssl'), 'login' => 'email', 'oauth' => '', 'oauth_only' => false,
			'cert' => array('port' => 993, 'host' => '/^(?:mail\.)?([a-z0-9-]+\.o2switch\.net)$/', 'note' => 'o2switch_server')),
		'infomaniak' => array('label' => 'Infomaniak', 'domains' => array('ik.me', 'etik.com'), 'mx' => array('.infomaniak.ch'),
			'imap' => array('mail.infomaniak.com', 993, 'ssl'), 'smtp' => array('mail.infomaniak.com', 465, 'ssl'), 'login' => 'email', 'oauth' => '', 'oauth_only' => false),
		'orange' => array('label' => 'Orange', 'domains' => array('orange.fr', 'wanadoo.fr'), 'mx' => array(),
			'imap' => array('imap.orange.fr', 993, 'ssl'), 'smtp' => array('smtp.orange.fr', 465, 'ssl'), 'login' => 'email', 'oauth' => '', 'oauth_only' => false),
		'free' => array('label' => 'Free', 'domains' => array('free.fr'), 'mx' => array(),
			'imap' => array('imap.free.fr', 993, 'ssl'), 'smtp' => array('smtp.free.fr', 465, 'ssl'), 'login' => 'email', 'oauth' => '', 'oauth_only' => false),
		'sfr' => array('label' => 'SFR', 'domains' => array('sfr.fr', 'neuf.fr'), 'mx' => array(),
			'imap' => array('imap.sfr.fr', 993, 'ssl'), 'smtp' => array('smtp.sfr.fr', 465, 'ssl'), 'login' => 'email', 'oauth' => '', 'oauth_only' => false),
		'laposte' => array('label' => 'La Poste', 'domains' => array('laposte.net'), 'mx' => array(),
			'imap' => array('imap.laposte.net', 993, 'ssl'), 'smtp' => array('smtp.laposte.net', 465, 'ssl'), 'login' => 'email', 'oauth' => '', 'oauth_only' => false),
		'yahoo' => array('label' => 'Yahoo', 'domains' => array('yahoo.com', 'yahoo.fr', 'ymail.com'), 'mx' => array(),
			'imap' => array('imap.mail.yahoo.com', 993, 'ssl'), 'smtp' => array('smtp.mail.yahoo.com', 465, 'ssl'), 'login' => 'email', 'oauth' => '', 'oauth_only' => false),
		'icloud' => array('label' => 'iCloud', 'domains' => array('icloud.com', 'me.com', 'mac.com'), 'mx' => array('.mail.icloud.com'),
			'imap' => array('imap.mail.me.com', 993, 'ssl'), 'smtp' => array('smtp.mail.me.com', 587, 'tls'), 'login' => 'email', 'oauth' => '', 'oauth_only' => false),
		'gmx' => array('label' => 'GMX', 'domains' => array('gmx.fr', 'gmx.com', 'gmx.net', 'gmx.de'), 'mx' => array(),
			'imap' => array('imap.gmx.net', 993, 'ssl'), 'smtp' => array('mail.gmx.net', 465, 'ssl'), 'login' => 'email', 'oauth' => '', 'oauth_only' => false),
	);

	/** Second level domains under which a company domain has three labels (co.uk, com.fr...) */
	const SECOND_LEVELS = array('co', 'com', 'net', 'org', 'gov', 'ac', 'edu', 'asso', 'gouv', 'ltd', 'plc');

	/** @var DoliDB */
	protected $db;

	/** @var bool The ISPDB of Thunderbird may be asked (setting, or a click of the user for this search) */
	public $ispdb = false;

	/** @var bool Guessed hosts are tried (TCP connection on their usual ports) */
	public $guess = true;

	/** @var array<int,array{source:string,detail:string,result:string}> What was tried, for the result */
	protected $tried = array();

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct(DoliDB $db)
	{
		$this->db = $db;
		$this->ispdb = getDolGlobalString('INBOX_AUTOCONFIG_ISPDB') === '1';
	}

	/**
	 * Servers of a mailbox
	 *
	 * @param string $email Address of the mailbox
	 * @return array<string,mixed> found, source, detail, insecure, provider, imap, smtp, login, auth_mode, oauth_service,
	 *                             oauth_provider, oauth_services, notes, tried, ispdb_offer
	 */
	public function discover($email)
	{
		$email = strtolower(trim((string) $email));
		$out = array('found' => false, 'source' => '', 'detail' => '', 'insecure' => false, 'provider' => '', 'imap' => null, 'smtp' => null,
			'login' => '', 'auth_mode' => 'login', 'oauth_service' => '', 'oauth_provider' => '', 'oauth_services' => array(), 'notes' => array(),
			'tried' => array(), 'ispdb_offer' => false);
		if (!isValidEmail($email)) {
			return $out;
		}
		$this->tried = array();
		$domain = substr($email, strrpos($email, '@') + 1);
		$mx = $this->mxHosts($domain);

		$found = $this->fromMailboxes($email, $domain);
		if (!$found) {
			$found = $this->fromAutoconfig($email, $domain);
		}
		if (!$found) {
			$found = $this->fromAutodiscover($email, $domain);
		}
		if (!$found) {
			$found = $this->fromProviders($domain, $mx);
		}
		if (!$found && $this->ispdb) {
			$found = $this->fromIspdb($email, $domain, $mx);
		}
		if (!$found) {
			$found = $this->fromSrv($domain);
		}
		if (!$found && $this->guess) {
			$found = $this->fromGuess($domain);
		}
		$out['tried'] = $this->tried;
		// The ISPDB not asked: offered once, as the last chance before typing the servers
		$out['ispdb_offer'] = !$this->ispdb && (!$found || $found['source'] === 'guess');
		if (!$found) {
			return $out;
		}
		$out = array_merge($out, $found, array('found' => true, 'tried' => $this->tried, 'ispdb_offer' => $out['ispdb_offer']));
		$out['smtp'] = $out['smtp'] ?: null;

		return $this->withOAuth($out);
	}

	/**
	 * OAuth for the providers that ask it, with the services set up in the core
	 *
	 * @param array<string,mixed> $out Result
	 * @return array<string,mixed>
	 */
	protected function withOAuth(array $out)
	{
		$provider = $out['provider'] && isset(self::PROVIDERS[$out['provider']]) ? self::PROVIDERS[$out['provider']] : null;
		if (!$provider) {
			// Servers of Google or Microsoft found by another source (autoconfig of the domain, ISPDB, SRV)
			$host = (string) ($out['imap']['host'] ?? '');
			foreach (self::PROVIDERS as $key => $p) {
				if ($p['oauth'] !== '' && $host === $p['imap'][0]) {
					$provider = $p;
					$out['provider'] = $key;
					break;
				}
			}
		}
		if (!$provider || $provider['oauth'] === '' || $out['auth_mode'] === 'master') {
			return $out;
		}
		$out['oauth_provider'] = $provider['oauth'];
		foreach (InboxOAuth::listServices() as $key => $label) {
			if (strpos(strtoupper($key), $provider['oauth']) === 0) {
				$out['oauth_services'][] = array('key' => $key, 'label' => $label);
			}
		}
		if ($out['oauth_services']) {
			$out['auth_mode'] = 'oauth';
			$out['oauth_service'] = $out['oauth_services'][0]['key'];
			$out['notes'][] = 'oauth';
		} else {
			$out['notes'][] = $provider['oauth_only'] ? 'oauth_needed' : 'oauth_offered';
		}

		return $out;
	}

	/**
	 * @param string $source Source
	 * @param string $detail Where (address asked, record)
	 * @param string $result found, none, error
	 * @return void
	 */
	protected function tried($source, $detail, $result)
	{
		$this->tried[] = array('source' => $source, 'detail' => $detail, 'result' => $result);
	}

	/**
	 * @param string $host Host
	 * @param int    $port Port
	 * @param string $enc  ssl, tls, none
	 * @return array{host:string,port:int,encryption:string}|null
	 */
	protected static function server($host, $port, $enc)
	{
		$host = strtolower(rtrim(trim((string) $host), '.'));
		$port = (int) $port;
		if ($host === '' || !preg_match('/^[a-z0-9]([a-z0-9.-]{0,251}[a-z0-9])?$/', $host) || $port < 1 || $port > 65535) {
			return null;
		}

		return array('host' => $host, 'port' => $port, 'encryption' => in_array($enc, array('ssl', 'tls', 'none'), true) ? $enc : 'ssl');
	}

	// ----- 1. A mailbox of the same domain -----

	/**
	 * @param string $email  Address
	 * @param string $domain Domain
	 * @return array<string,mixed>|null
	 */
	protected function fromMailboxes($email, $domain)
	{
		global $conf;

		$sql = "SELECT email, imap_host, imap_port, imap_encryption, imap_auth_mode, imap_login, oauth_service, smtp_host, smtp_port, smtp_encryption";
		$sql .= " FROM ".$this->db->prefix()."inbox_account WHERE entity = ".((int) $conf->entity);
		$sql .= " AND email LIKE '%@".$this->db->escape($this->db->escapeforlike($domain))."' AND imap_host <> ''";
		$sql .= " ORDER BY status DESC, rowid DESC";
		$row = $this->db->getRow($sql);
		if (!is_object($row)) {
			$this->tried('mailbox', $domain, 'none');
			return null;
		}
		$imap = self::server($row->imap_host, $row->imap_port, $row->imap_encryption);
		if (!$imap) {
			return null;
		}
		$this->tried('mailbox', (string) $row->email, 'found');
		// The login of the other mailbox: its address (empty), or its local part
		$other = strtolower((string) $row->email);
		$login = (string) $row->imap_login === '' || strtolower((string) $row->imap_login) === $other ? 'email' : (strtolower((string) $row->imap_login) === substr($other, 0, strpos($other, '@')) ? 'localpart' : '');
		$mode = in_array($row->imap_auth_mode, array('login', 'master', 'oauth'), true) ? (string) $row->imap_auth_mode : 'login';

		return array('source' => 'mailbox', 'detail' => $other, 'imap' => $imap,
			'smtp' => (string) $row->smtp_host !== '' ? self::server($row->smtp_host, $row->smtp_port, $row->smtp_encryption) : null,
			'login' => $login, 'auth_mode' => $mode, 'oauth_service' => $mode === 'oauth' ? (string) $row->oauth_service : '');
	}

	// ----- 2. Autoconfig of the domain, 5. ISPDB -----

	/**
	 * @param string $email  Address
	 * @param string $domain Domain
	 * @return array<string,mixed>|null
	 */
	protected function fromAutoconfig($email, $domain)
	{
		$query = '?emailaddress='.rawurlencode($email);
		$urls = array();
		if ($this->resolves('autoconfig.'.$domain)) {
			$urls[] = 'https://autoconfig.'.$domain.'/mail/config-v1.1.xml'.$query;
		}
		if ($this->resolves($domain)) {
			$urls[] = 'https://'.$domain.'/.well-known/autoconfig/mail/config-v1.1.xml'.$query;
		}
		if (!$urls) {
			$this->tried('autoconfig', 'autoconfig.'.$domain, 'none');
			return null;
		}
		// http as a last resort, as Thunderbird: the servers it gives are said not verified
		if ($this->resolves('autoconfig.'.$domain)) {
			$urls[] = 'http://autoconfig.'.$domain.'/mail/config-v1.1.xml'.$query;
		}
		foreach ($urls as $url) {
			$xml = $this->get($url);
			$found = $xml !== '' ? self::parseAutoconfig($xml, $email) : null;
			$this->tried('autoconfig', strtok($url, '?'), $found ? 'found' : 'none');
			if ($found) {
				return array_merge($found, array('source' => 'autoconfig', 'detail' => strtok($url, '?'), 'insecure' => strpos($url, 'http:') === 0));
			}
		}

		return null;
	}

	/**
	 * @param string   $email  Address
	 * @param string   $domain Domain
	 * @param string[] $mx     MX hosts of the domain
	 * @return array<string,mixed>|null
	 */
	protected function fromIspdb($email, $domain, array $mx)
	{
		// The domain of the address, then the one of its first MX (a company domain hosted by a provider)
		$domains = array($domain);
		if ($mx) {
			$base = self::baseDomain($mx[0]);
			if ($base !== '' && $base !== $domain) {
				$domains[] = $base;
			}
		}
		foreach ($domains as $i => $d) {
			$url = 'https://autoconfig.thunderbird.net/v1.1/'.rawurlencode($d);
			$xml = $this->get($url);
			$found = $xml !== '' ? self::parseAutoconfig($xml, $email) : null;
			$this->tried($i ? 'ispdb_mx' : 'ispdb', $d, $found ? 'found' : 'none');
			if ($found) {
				return array_merge($found, array('source' => $i ? 'ispdb_mx' : 'ispdb', 'detail' => $d));
			}
		}

		return null;
	}

	/**
	 * Servers of an autoconfig file (format of Thunderbird, config-v1.1): the IMAP and SMTP servers, encrypted first
	 *
	 * @param string $xml   File
	 * @param string $email Address
	 * @return array<string,mixed>|null imap, smtp, login, provider_label
	 */
	public static function parseAutoconfig($xml, $email)
	{
		$doc = self::xml($xml);
		if (!$doc) {
			return null;
		}
		$local = substr($email, 0, (int) strpos($email, '@'));
		$domain = substr($email, (int) strpos($email, '@') + 1);
		$fill = function ($s) use ($email, $local, $domain) {
			return str_replace(array('%EMAILADDRESS%', '%EMAILLOCALPART%', '%EMAILDOMAIN%'), array($email, $local, $domain), trim((string) $s));
		};
		$rank = array('ssl' => 0, 'tls' => 1, 'none' => 2);
		$best = array('imap' => null, 'smtp' => null);
		$login = array('imap' => '', 'smtp' => '');
		$oauth = false;
		foreach (array('incomingServer' => 'imap', 'outgoingServer' => 'smtp') as $tag => $kind) {
			foreach ($doc->getElementsByTagName($tag) as $node) {
				/** @var DOMElement $node */
				if (strtolower($node->getAttribute('type')) !== $kind) {
					continue;
				}
				$socket = strtolower(self::child($node, 'socketType'));
				$enc = $socket === 'ssl' ? 'ssl' : ($socket === 'starttls' ? 'tls' : 'none');
				$server = self::server($fill(self::child($node, 'hostname')), (int) self::child($node, 'port'), $enc);
				if (!$server) {
					continue;
				}
				foreach ($node->getElementsByTagName('authentication') as $a) {
					if (strtolower(trim($a->textContent)) === 'oauth2') {
						$oauth = true;
					}
				}
				if ($best[$kind] === null || $rank[$enc] < $rank[$best[$kind]['encryption']]) {
					$best[$kind] = $server;
					$user = trim(self::child($node, 'username'));
					$login[$kind] = $user === '%EMAILADDRESS%' || $user === '' ? 'email' : ($user === '%EMAILLOCALPART%' ? 'localpart' : '');
				}
			}
		}
		if (!$best['imap']) {
			return null;
		}
		$label = '';
		foreach ($doc->getElementsByTagName('displayName') as $n) {
			$label = trim($n->textContent);
			break;
		}

		return array('imap' => $best['imap'], 'smtp' => $best['smtp'], 'login' => $login['imap'], 'provider_label' => dol_trunc($label, 80), 'oauth_offered' => $oauth);
	}

	// ----- 3. Autodiscover -----

	/**
	 * @param string $email  Address
	 * @param string $domain Domain
	 * @return array<string,mixed>|null
	 */
	protected function fromAutodiscover($email, $domain)
	{
		$body = '<?xml version="1.0" encoding="utf-8"?><Autodiscover xmlns="http://schemas.microsoft.com/exchange/autodiscover/outlook/requestschema/2006">'
			.'<Request><EMailAddress>'.htmlspecialchars($email, ENT_XML1).'</EMailAddress>'
			.'<AcceptableResponseSchema>http://schemas.microsoft.com/exchange/autodiscover/outlook/responseschema/2006a</AcceptableResponseSchema></Request></Autodiscover>';
		$urls = array();
		if ($this->resolves('autodiscover.'.$domain)) {
			$urls[] = 'https://autodiscover.'.$domain.'/autodiscover/autodiscover.xml';
		}
		if (!$urls) {
			$this->tried('autodiscover', 'autodiscover.'.$domain, 'none');
			return null;
		}
		foreach ($urls as $url) {
			// Never redirected (a redirection to another domain would take the address there), never a password
			$xml = $this->get($url, $body);
			$found = $xml !== '' ? self::parseAutodiscover($xml) : null;
			$this->tried('autodiscover', $url, $found ? 'found' : 'none');
			if ($found) {
				return array_merge($found, array('source' => 'autodiscover', 'detail' => $url));
			}
		}

		return null;
	}

	/**
	 * IMAP and SMTP of an Autodiscover answer (POX schema of Outlook)
	 *
	 * @param string $xml Answer
	 * @return array<string,mixed>|null
	 */
	public static function parseAutodiscover($xml)
	{
		$doc = self::xml($xml);
		if (!$doc) {
			return null;
		}
		$best = array('imap' => null, 'smtp' => null);
		$login = '';
		foreach ($doc->getElementsByTagNameNS('*', 'Protocol') as $node) {
			/** @var DOMElement $node */
			$kind = strtolower(self::child($node, 'Type'));
			if (!in_array($kind, array('imap', 'smtp'), true) || $best[$kind]) {
				continue;
			}
			$port = (int) self::child($node, 'Port');
			$encTag = strtolower(self::child($node, 'Encryption'));
			$ssl = strtolower(self::child($node, 'SSL'));
			if ($encTag !== '') {
				$enc = $encTag === 'ssl' ? 'ssl' : ($encTag === 'tls' ? 'tls' : ($encTag === 'none' ? 'none' : 'ssl'));
			} else {
				$enc = $ssl === 'off' ? 'none' : (in_array($port, array(993, 465), true) ? 'ssl' : 'tls');
			}
			$best[$kind] = self::server(self::child($node, 'Server'), $port, $enc);
			if ($kind === 'imap') {
				$name = strtolower(self::child($node, 'LoginName'));
				$login = $name === '' || strpos($name, '@') !== false ? 'email' : '';
			}
		}

		return $best['imap'] ? array('imap' => $best['imap'], 'smtp' => $best['smtp'], 'login' => $login) : null;
	}

	// ----- 4. Known providers -----

	/**
	 * @param string   $domain Domain
	 * @param string[] $mx     MX hosts
	 * @return array<string,mixed>|null
	 */
	protected function fromProviders($domain, array $mx)
	{
		$ns = null;
		foreach (self::PROVIDERS as $key => $p) {
			$hit = in_array($domain, $p['domains'], true) ? $domain : '';
			$suffixHit = '';
			foreach ($p['mx'] as $suffix) {
				foreach ($mx as $host) {
					if ($hit === '' && substr('.'.$host, -strlen($suffix)) === $suffix) {
						$hit = $host;
						$suffixHit = $suffix;
					}
				}
			}
			// Name servers, asked once and only for the providers known by them
			if ($hit === '' && !empty($p['ns'])) {
				$ns = $ns ?? $this->nsHosts($domain);
				foreach ($p['ns'] as $suffix) {
					foreach ($ns as $host) {
						if ($hit === '' && substr('.'.$host, -strlen($suffix)) === $suffix) {
							$hit = $host;
						}
					}
				}
			}
			if ($hit !== '') {
				$this->tried('provider', $hit, 'found');
				$names = array($p['imap'][0], $p['smtp'][0]);
				if (!empty($p['country'])) {
					$names = str_replace('{cc}', $p['country'][$suffixHit] ?? 'com', $names);
				}
				$out = array('source' => 'provider', 'detail' => $hit, 'provider' => $key, 'provider_label' => $p['label'],
					'imap' => self::server($names[0], $p['imap'][1], $p['imap'][2]), 'smtp' => self::server($names[1], $p['smtp'][1], $p['smtp'][2]), 'login' => $p['login']);
				if (!empty($p['cert'])) {
					return $this->withCertServer($domain, $mx, $p, $out);
				}

				return empty($p['offers']) ? $out : $this->withOffer($domain, $p['offers'], $out);
			}
		}
		$this->tried('provider', $mx ? implode(', ', array_slice($mx, 0, 2)) : $domain, 'none');

		return null;
	}

	/**
	 * Own server of an offer of the provider, by its SRV record; else the servers of the provider with a note
	 *
	 * @param string              $domain Domain
	 * @param array<string,mixed> $offers Offers of the provider (PROVIDERS)
	 * @param array<string,mixed> $out    Servers of the provider
	 * @return array<string,mixed>
	 */
	protected function withOffer($domain, array $offers, array $out)
	{
		$name = $offers['record'].'.'.$domain;
		$record = $this->srv($name, 'ssl');
		$reg = array();
		if (!$record || !preg_match($offers['host'], $record['host'], $reg)) {
			$this->tried('srv', $name, 'none');
			$out['notes'] = array($offers['note']);
			return $out;
		}
		$this->tried('srv', $name, 'found');
		$host = $record['host'];

		return array_merge($out, array('detail' => $host, 'provider_label' => $offers['labels'][$reg[1]] ?? $out['provider_label'],
			'imap' => self::server($host, $offers['imap'][0], $offers['imap'][1]), 'smtp' => self::server($host, $offers['smtp'][0], $offers['smtp'][1])));
	}

	/**
	 * Server of a shared host named in the certificate of the mail server of the domain (o2switch): without a certificate
	 * of its own, the domain is answered with the one of the server. Nothing is sent, the TLS handshake only.
	 *
	 * @param string              $domain Domain
	 * @param string[]            $mx     MX hosts
	 * @param array<string,mixed> $p      Provider (PROVIDERS)
	 * @param array<string,mixed> $out    Result so far
	 * @return array<string,mixed>
	 */
	protected function withCertServer($domain, array $mx, array $p, array $out)
	{
		$probe = $mx ? $mx[0] : 'mail.'.$domain;
		$name = $this->certName($probe, (int) $p['cert']['port']);
		$reg = array();
		if ($name !== '' && preg_match($p['cert']['host'], $name, $reg)) {
			$server = $reg[1];
			$this->tried('certificate', $probe, 'found');
		} else {
			// A certificate of the domain itself (or no answer): its mail server, to be checked by the test
			$server = $probe;
			$this->tried('certificate', $probe, 'none');
			if ($name === '') {
				$out['notes'] = array($p['cert']['note']);
			}
		}

		return array_merge($out, array('detail' => $server,
			'imap' => self::server($server, $p['imap'][1], $p['imap'][2]), 'smtp' => self::server($server, $p['smtp'][1], $p['smtp'][2])));
	}

	/**
	 * Name (CN) of the certificate a mail server presents, not verified: only read to find the name of a shared server
	 *
	 * @param string $host Host
	 * @param int    $port Port (SSL/TLS)
	 * @return string '' when it does not answer or is refused (private network for a non administrator)
	 */
	protected function certName($host, $port)
	{
		if (!function_exists('openssl_x509_parse') || !$this->resolves($host) || InboxServerTester::privateRefused($host)) {
			return '';
		}
		$context = stream_context_create(array('ssl' => array('capture_peer_cert' => true, 'verify_peer' => false, 'verify_peer_name' => false, 'peer_name' => $host, 'SNI_enabled' => true)));
		$errno = 0;
		$errstr = '';
		$fp = @stream_socket_client('ssl://'.$host.':'.((int) $port), $errno, $errstr, 5, STREAM_CLIENT_CONNECT, $context);
		if (!$fp) {
			return '';
		}
		$params = stream_context_get_params($fp);
		fclose($fp);
		$cert = isset($params['options']['ssl']['peer_certificate']) ? openssl_x509_parse($params['options']['ssl']['peer_certificate']) : false;

		return is_array($cert) && isset($cert['subject']['CN']) ? strtolower((string) (is_array($cert['subject']['CN']) ? reset($cert['subject']['CN']) : $cert['subject']['CN'])) : '';
	}

	// ----- 6. SRV -----

	/**
	 * @param string $domain Domain
	 * @return array<string,mixed>|null
	 */
	protected function fromSrv($domain)
	{
		$imap = $this->srv('_imaps._tcp.'.$domain, 'ssl') ?: $this->srv('_imap._tcp.'.$domain, 'tls');
		$this->tried('srv', '_imaps._tcp.'.$domain, $imap ? 'found' : 'none');
		if (!$imap) {
			return null;
		}
		$smtp = $this->srv('_submissions._tcp.'.$domain, 'ssl') ?: $this->srv('_submission._tcp.'.$domain, 'tls');

		return array('source' => 'srv', 'detail' => '_imaps._tcp.'.$domain, 'imap' => $imap, 'smtp' => $smtp, 'login' => '');
	}

	/**
	 * @param string $name Record name
	 * @param string $enc  Encryption of the service
	 * @return array{host:string,port:int,encryption:string}|null Record of the lowest priority (target « . » = service not offered)
	 */
	protected function srv($name, $enc)
	{
		if (!function_exists('dns_get_record')) {
			return null;
		}
		$records = (array) @dns_get_record($name, DNS_SRV);
		usort($records, function ($a, $b) {
			return ((int) ($a['pri'] ?? 0) - (int) ($b['pri'] ?? 0)) ?: ((int) ($b['weight'] ?? 0) - (int) ($a['weight'] ?? 0));
		});
		foreach ($records as $r) {
			$target = rtrim((string) ($r['target'] ?? ''), '.');
			if ($target === '') {
				return null;
			}
			return self::server($target, (int) ($r['port'] ?? 0), $enc);
		}

		return null;
	}

	// ----- 7. Usual names -----

	/**
	 * @param string $domain Domain
	 * @return array<string,mixed>|null
	 */
	protected function fromGuess($domain)
	{
		$imap = $this->firstOpen(array('imap.'.$domain, 'mail.'.$domain), array(993 => 'ssl', 143 => 'tls'));
		$this->tried('guess', 'imap.'.$domain.', mail.'.$domain, $imap ? 'found' : 'none');
		if (!$imap) {
			return null;
		}
		$smtp = $this->firstOpen(array('smtp.'.$domain, 'mail.'.$domain), array(465 => 'ssl', 587 => 'tls'));

		return array('source' => 'guess', 'detail' => $imap['host'], 'imap' => $imap, 'smtp' => $smtp, 'login' => '');
	}

	/**
	 * First host whose port answers (a TCP connection, nothing sent): a host of the private network only for an
	 * administrator of Dolibarr (audit INBX-010)
	 *
	 * @param string[]          $hosts Hosts
	 * @param array<int,string> $ports Port => encryption
	 * @return array{host:string,port:int,encryption:string}|null
	 */
	protected function firstOpen(array $hosts, array $ports)
	{
		foreach ($hosts as $host) {
			if (!$this->resolves($host) || InboxServerTester::privateRefused($host)) {
				continue;
			}
			foreach ($ports as $port => $enc) {
				$errno = 0;
				$errstr = '';
				$fp = @stream_socket_client('tcp://'.$host.':'.$port, $errno, $errstr, 3);
				if ($fp) {
					fclose($fp);
					return self::server($host, $port, $enc);
				}
			}
		}

		return null;
	}

	// ----- Tools -----

	/**
	 * @param string $domain Domain
	 * @return string[] Name servers of the domain
	 */
	protected function nsHosts($domain)
	{
		if (!function_exists('dns_get_record')) {
			return array();
		}

		return array_values(array_filter(array_map(function ($r) {
			return strtolower(rtrim((string) ($r['target'] ?? ''), '.'));
		}, (array) @dns_get_record($domain, DNS_NS))));
	}

	/**
	 * @param string $domain Domain
	 * @return string[] MX hosts, by priority
	 */
	protected function mxHosts($domain)
	{
		$hosts = array();
		$weights = array();
		if (function_exists('getmxrr') && @getmxrr($domain, $hosts, $weights)) {
			array_multisort($weights, SORT_NUMERIC, $hosts);
		}

		return array_values(array_filter(array_map(function ($h) {
			return strtolower(rtrim((string) $h, '.'));
		}, $hosts)));
	}

	/**
	 * Domain of a company from a host: mx1.mail.ovh.net => ovh.net, mail.example.co.uk => example.co.uk
	 *
	 * @param string $host Host
	 * @return string
	 */
	public static function baseDomain($host)
	{
		$labels = explode('.', strtolower(rtrim((string) $host, '.')));
		$n = count($labels);
		if ($n < 2) {
			return '';
		}
		$take = $n >= 3 && strlen($labels[$n - 1]) === 2 && in_array($labels[$n - 2], self::SECOND_LEVELS, true) ? 3 : 2;

		return implode('.', array_slice($labels, -$take));
	}

	/**
	 * @param string $host Host
	 * @return bool The name has an address (no time lost on a name that does not exist)
	 */
	protected function resolves($host)
	{
		if (!preg_match('/^[a-z0-9.-]+$/', $host)) {
			return false;
		}
		$ip = gethostbyname($host.'.');

		return $ip !== $host.'.' && $ip !== $host;
	}

	/**
	 * Content of an address, the private network refused by the core, no redirection
	 *
	 * @param string      $url  Address
	 * @param string|null $post Body posted (XML), null for a GET
	 * @return string            Content, '' when not a 200 or too large
	 */
	protected function get($url, $post = null)
	{
		require_once DOL_DOCUMENT_ROOT.'/core/lib/geturl.lib.php';
		$https = strpos($url, 'https:') === 0;
		$headers = $post === null ? array('Accept: application/xml, text/xml') : array('Content-Type: text/xml; charset=utf-8');
		$res = getURLContent($url, $post === null ? 'GET' : 'POSTALREADYFORMATED', $post === null ? '' : $post, 0, $headers, array($https ? 'https' : 'http'), 0, $https ? 1 : 0, 3, 6,
			array(CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4));
		if (!is_array($res) || (int) ($res['http_code'] ?? 0) !== 200 || !isset($res['content'])) {
			return '';
		}
		$content = (string) $res['content'];

		return strlen($content) <= self::MAX_BYTES ? $content : '';
	}

	/**
	 * @param string $xml Content
	 * @return DOMDocument|null Parsed without the network nor the entities of the file
	 */
	protected static function xml($xml)
	{
		if (stripos($xml, '<!DOCTYPE') !== false || !class_exists('DOMDocument')) {
			return null;
		}
		$doc = new DOMDocument();
		$prev = libxml_use_internal_errors(true);
		$ok = $doc->loadXML($xml, LIBXML_NONET);
		libxml_clear_errors();
		libxml_use_internal_errors($prev);

		return $ok ? $doc : null;
	}

	/**
	 * @param DOMElement $node Node
	 * @param string     $name Local name of a child
	 * @return string           Text of the first child of this name
	 */
	protected static function child(DOMElement $node, $name)
	{
		foreach ($node->childNodes as $c) {
			if ($c instanceof DOMElement && strcasecmp($c->localName, $name) === 0) {
				return trim($c->textContent);
			}
		}

		return '';
	}
}
