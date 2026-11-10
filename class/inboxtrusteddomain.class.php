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
 * \file    inbox/class/inboxtrusteddomain.class.php
 * \ingroup inbox
 * \brief   Recognized domains (1.59.0): a short list of domains of organisations, shown on the sender (authenticated
 *          mail only) and on the links, and the reference of the lookalike domains
 */

/**
 * A recognized domain says « this domain belongs to this organisation », checked by a user with the right « Manage the
 * recognized domains ». It is never a verdict on a mail: only on its sender (when the mail is authenticated for that
 * domain) and on each link. Matching is exact (the domain, or its subdomains after a dot), never « contains ». Public
 * suffixes and services open to anyone (free mail, emailing, hosting, shorteners) are refused: recognizing them would
 * vouch for any of their users. Analysis: analyses/analyse-domaines-reconnus.md.
 */
class InboxTrustedDomain
{
	/**
	 * Services open to anyone: a domain equal to one of them or under one of them is refused (anyone sends mails or
	 * publishes pages there). The PRIVATE section of the Public Suffix List adds the shared platforms (github.io...).
	 *
	 * @var string[]
	 */
	const OPEN_SERVICES = array(
		// Free mail
		'gmail.com', 'googlemail.com', 'outlook.com', 'outlook.fr', 'hotmail.com', 'hotmail.fr', 'live.com', 'live.fr', 'msn.com',
		'yahoo.com', 'yahoo.fr', 'ymail.com', 'aol.com', 'icloud.com', 'me.com', 'mac.com', 'gmx.com', 'gmx.fr', 'gmx.de', 'gmx.net',
		'web.de', 'mail.com', 'proton.me', 'protonmail.com', 'zoho.com', 'yandex.com', 'yandex.ru', 'mail.ru', 'orange.fr', 'wanadoo.fr',
		'free.fr', 'sfr.fr', 'neuf.fr', 'laposte.net', 'bbox.fr', 'libero.it', 'virgilio.it', 'tiscali.it', 't-online.de', 'aliceadsl.fr',
		// Emailing and sending platforms, redirection of the links
		'sendinblue.com', 'brevo.com', 'sendibm1.com', 'sendibm2.com', 'sendibm3.com', 'sendibm4.com', 'sendibt2.com', 'sendibt3.com',
		'mailchimp.com', 'mailchi.mp', 'list-manage.com', 'mcsv.net', 'mcdlv.net', 'rsgsv.net', 'sendgrid.net', 'sendgrid.com',
		'mailjet.com', 'mjt.lu', 'mandrillapp.com', 'mailgun.org', 'mailgun.net', 'amazonses.com', 'sparkpostmail.com', 'postmarkapp.com',
		'hubspot.com', 'hubspotemail.net', 'hs-sites.com', 'hubspotlinks.com', 'hs-analytics.net', 'constantcontact.com', 'mailerlite.com',
		'klaviyo.com', 'klclick.com', 'activehosted.com', 'acemlna.com', 'getresponse.com', 'cmail19.com', 'cmail20.com', 'createsend.com',
		'exacttarget.com', 'salesforce.com', 'mktomail.com', 'mkt.com', 'emarsys.net', 'sarbacane.com', 'mailpro.com', 'mailpoet.com',
		'awstrack.me', 'safelinks.protection.outlook.com', 'urldefense.com', 'urldefense.proofpoint.com',
		// Hosting, shared pages and files
		'amazonaws.com', 'cloudfront.net', 'azurewebsites.net', 'azureedge.net', 'blob.core.windows.net', 'web.core.windows.net',
		'googleusercontent.com', 'appspot.com', 'firebaseapp.com', 'web.app', 'storage.googleapis.com', 'sites.google.com',
		'docs.google.com', 'drive.google.com', 'forms.gle', 'sharepoint.com', 'onedrive.live.com', '1drv.ms', 'dropbox.com',
		'wetransfer.com', 'we.tl', 'github.io', 'gitlab.io', 'netlify.app', 'vercel.app', 'pages.dev', 'workers.dev', 'herokuapp.com',
		'wordpress.com', 'blogspot.com', 'wixsite.com', 'weebly.com', 'jimdosite.com', 'webflow.io', 'squarespace.com', 'notion.site',
		'typeform.com', 'jotform.com', 'canva.site', 'ipfs.io',
		// Social networks and public platforms: anyone has a page there (the links of a signature, never an organisation)
		'facebook.com', 'fb.com', 'fb.me', 'messenger.com', 'instagram.com', 'linkedin.com', 'twitter.com', 'x.com', 'youtube.com',
		'youtu.be', 'tiktok.com', 'pinterest.com', 'pinterest.fr', 'snapchat.com', 'threads.net', 'bsky.app', 'mastodon.social',
		'reddit.com', 'medium.com', 'substack.com', 'tumblr.com', 'vimeo.com', 'dailymotion.com', 'twitch.tv', 'discord.com',
		'discord.gg', 'whatsapp.com', 'wa.me', 't.me', 'telegram.org', 'google.com', 'g.page', 'maps.app.goo.gl',
		'calendly.com', 'linktr.ee', 'viadeo.com', 'xing.com',
		// Shorteners
		'bit.ly', 'bitly.com', 'tinyurl.com', 't.co', 'lnkd.in', 'goo.gl', 'ow.ly', 'buff.ly', 'is.gd', 'cutt.ly', 'rebrand.ly', 'shorturl.at', 'tiny.cc',
	);

	/** @var int Above this number of domains, the settings remind that a short list stays reliable */
	const MANY = 20;

	/** @var DoliDB */
	protected $db;

	/** @var string */
	public $error = '';

	/** @var array<int,list<array<string,mixed>>> Cache per entity of the list, for one request */
	protected static $cache = array();

	/** @var array<string,int>|null Rules of the Public Suffix List */
	protected static $suffixes = null;

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct(DoliDB $db)
	{
		$this->db = $db;
	}

	// ---------- Domains ----------

	/**
	 * A domain as stored and compared: lower case, punycode, without scheme, path, port, leading « www. », « *. »,
	 * « @ » or final dot; '' when it is not a domain name
	 *
	 * @param string $value Domain, address or URL as typed
	 * @return string
	 */
	public static function normalize($value)
	{
		$v = trim((string) $value);
		if ($v === '') {
			return '';
		}
		if (strpos($v, '@') !== false) {
			$v = substr($v, strrpos($v, '@') + 1);
		}
		if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $v)) {
			$host = parse_url($v, PHP_URL_HOST);
			$v = is_string($host) ? $host : '';
		}
		$v = preg_replace('#[/?\#:].*$#s', '', $v);
		$v = trim(mb_strtolower((string) $v, 'UTF-8'), ". \t");
		$v = preg_replace('/^(\*\.|www\.)/', '', $v);
		if ($v === '' || strlen($v) > 253) {
			return '';
		}
		if (!preg_match('/^[a-z0-9.-]+$/', $v)) {
			if (!function_exists('idn_to_ascii')) {
				return '';
			}
			$ascii = idn_to_ascii($v, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
			if (!is_string($ascii)) {
				return '';
			}
			$v = strtolower($ascii);
		}
		if (strpos($v, '.') === false || strlen($v) > 253) {
			return '';
		}
		foreach (explode('.', $v) as $label) {
			if ($label === '' || strlen($label) > 63 || !preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', $label)) {
				return '';
			}
		}

		return $v;
	}

	/**
	 * Rules of the Public Suffix List (class/data/inboxpublicsuffix.php, built by dev/build-publicsuffix.php)
	 *
	 * @return array<string,int> Rule => 1 ICANN, 2 PRIVATE
	 */
	protected static function suffixes()
	{
		if (self::$suffixes === null) {
			$rules = include __DIR__.'/data/inboxpublicsuffix.php';
			self::$suffixes = is_array($rules) ? $rules : array();
		}

		return self::$suffixes;
	}

	/**
	 * Public suffix of a normalized host (algorithm of publicsuffix.org, default rule « * »)
	 *
	 * @param string $host Normalized host
	 * @return array{suffix:string,type:int} type: 0 not in the list (default rule), 1 ICANN, 2 PRIVATE
	 */
	public static function publicSuffix($host)
	{
		$rules = self::suffixes();
		$labels = explode('.', (string) $host);
		$n = count($labels);
		for ($i = 0; $i < $n; $i++) {
			$candidate = implode('.', array_slice($labels, $i));
			if (isset($rules['!'.$candidate])) {
				// Exception: the suffix is the rule without its first label
				return array('suffix' => implode('.', array_slice($labels, $i + 1)), 'type' => (int) $rules['!'.$candidate]);
			}
			if (isset($rules[$candidate])) {
				return array('suffix' => $candidate, 'type' => (int) $rules[$candidate]);
			}
			if ($i + 1 < $n && isset($rules['*.'.implode('.', array_slice($labels, $i + 1))])) {
				return array('suffix' => $candidate, 'type' => (int) $rules['*.'.implode('.', array_slice($labels, $i + 1))]);
			}
		}

		return array('suffix' => (string) end($labels), 'type' => 0);
	}

	/**
	 * Registrable domain of a normalized host: its public suffix and one more label; '' for a public suffix itself
	 *
	 * @param string $host Normalized host
	 * @return string
	 */
	public static function registrable($host)
	{
		$host = (string) $host;
		$suffix = self::publicSuffix($host)['suffix'];
		if ($suffix === '' || $host === $suffix) {
			return '';
		}
		$rest = substr($host, 0, -strlen('.'.$suffix));
		$pos = strrpos($rest, '.');

		return ($pos === false ? $rest : substr($rest, $pos + 1)).'.'.$suffix;
	}

	/**
	 * The host is a public suffix (anyone registers a name under it)
	 *
	 * @param string $host Normalized host
	 * @return bool
	 */
	public static function isPublicSuffix($host)
	{
		return self::registrable($host) === '';
	}

	/**
	 * The host is an open service, or under one, or under a shared platform (PRIVATE section of the list)
	 *
	 * @param string $host Normalized host
	 * @return bool
	 */
	public static function isOpenService($host)
	{
		foreach (self::OPEN_SERVICES as $s) {
			if ($host === $s || substr($host, -strlen('.'.$s)) === '.'.$s) {
				return true;
			}
		}

		return self::publicSuffix($host)['type'] === 2;
	}

	/**
	 * The host is the domain of the entry, or one of its subdomains when the entry allows them
	 *
	 * @param string              $host  Normalized host
	 * @param array<string,mixed> $entry Entry (domain, subdomains)
	 * @return bool
	 */
	public static function matches($host, array $entry)
	{
		$d = (string) $entry['domain'];
		if ($host === '' || $d === '') {
			return false;
		}

		return $host === $d || (!empty($entry['subdomains']) && substr($host, -strlen('.'.$d)) === '.'.$d);
	}

	/**
	 * Why a domain cannot be recognized, or '' when it can
	 *
	 * @param string   $domain     Normalized domain ('' = not a domain)
	 * @param string[] $ownDomains Domains of the mailboxes of inbox
	 * @return string Key of translation
	 */
	public static function refusal($domain, array $ownDomains = array())
	{
		if ($domain === '') {
			return 'InboxTrustedErrorDomain';
		}
		if (self::isPublicSuffix($domain)) {
			return 'InboxTrustedErrorPublicSuffix';
		}
		if (self::isOpenService($domain)) {
			return 'InboxTrustedErrorOpenService';
		}
		foreach ($ownDomains as $own) {
			if ($own !== '' && ($domain === $own || substr($domain, -strlen('.'.$own)) === '.'.$own || self::registrable($domain) === self::registrable($own))) {
				return 'InboxTrustedErrorOwnDomain';
			}
		}

		return '';
	}

	// ---------- Lookalikes ----------

	/**
	 * Skeleton of a name: what the eye reads (accents and confusable letters of other alphabets brought back to Latin,
	 * 0 = o, 1 = l, rn = m, vv = w, hyphens removed)
	 *
	 * @param string $name Label in punycode or Unicode
	 * @return string
	 */
	public static function skeleton($name)
	{
		$name = (string) $name;
		if (strpos($name, 'xn--') !== false && function_exists('idn_to_utf8')) {
			$u = idn_to_utf8($name, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
			if (is_string($u)) {
				$name = $u;
			}
		}
		$name = mb_strtolower($name, 'UTF-8');
		if (class_exists('Normalizer')) {
			$d = Normalizer::normalize($name, Normalizer::FORM_KD);
			if (is_string($d)) {
				$name = (string) preg_replace('/\p{Mn}+/u', '', $d);
			}
		}
		$name = strtr($name, array(
			// Cyrillic
			'а' => 'a', 'в' => 'b', 'е' => 'e', 'ё' => 'e', 'һ' => 'h', 'н' => 'h', 'і' => 'i', 'ї' => 'i', 'ј' => 'j', 'к' => 'k', 'ӏ' => 'l',
			'м' => 'm', 'о' => 'o', 'р' => 'p', 'ԛ' => 'q', 'ѕ' => 's', 'т' => 't', 'у' => 'y', 'х' => 'x', 'ԁ' => 'd', 'ԝ' => 'w', 'с' => 'c',
			'ь' => 'b', 'п' => 'n',
			// Greek
			'α' => 'a', 'β' => 'b', 'ε' => 'e', 'η' => 'n', 'ι' => 'i', 'κ' => 'k', 'ν' => 'v', 'ο' => 'o', 'ρ' => 'p', 'τ' => 't', 'υ' => 'u',
			'χ' => 'x', 'ω' => 'w',
			// Digits and pairs read as one letter
			'0' => 'o', '1' => 'l', 'rn' => 'm', 'vv' => 'w', '-' => '', '_' => '',
		));

		return $name;
	}

	/**
	 * Distance of Damerau-Levenshtein (optimal string alignment) between two strings, multibyte
	 *
	 * @param string $a First
	 * @param string $b Second
	 * @return int
	 */
	public static function distance($a, $b)
	{
		$x = preg_split('//u', (string) $a, -1, PREG_SPLIT_NO_EMPTY) ?: array();
		$y = preg_split('//u', (string) $b, -1, PREG_SPLIT_NO_EMPTY) ?: array();
		$n = count($x);
		$m = count($y);
		if (abs($n - $m) > 3) {
			return abs($n - $m);
		}
		$d = array();
		for ($i = 0; $i <= $n; $i++) {
			$d[$i][0] = $i;
		}
		for ($j = 0; $j <= $m; $j++) {
			$d[0][$j] = $j;
		}
		for ($i = 1; $i <= $n; $i++) {
			for ($j = 1; $j <= $m; $j++) {
				$cost = $x[$i - 1] === $y[$j - 1] ? 0 : 1;
				$d[$i][$j] = min($d[$i - 1][$j] + 1, $d[$i][$j - 1] + 1, $d[$i - 1][$j - 1] + $cost);
				if ($i > 1 && $j > 1 && $x[$i - 1] === $y[$j - 2] && $x[$i - 2] === $y[$j - 1]) {
					$d[$i][$j] = min($d[$i][$j], $d[$i - 2][$j - 2] + 1);
				}
			}
		}

		return $d[$n][$m];
	}

	/**
	 * Name of a registrable domain (without its public suffix)
	 *
	 * @param string $registrable Registrable domain
	 * @return string
	 */
	protected static function nameOf($registrable)
	{
		$suffix = self::publicSuffix($registrable)['suffix'];

		return substr($registrable, 0, -strlen('.'.$suffix));
	}

	/**
	 * The recognized domain a host imitates, or null
	 *
	 * Cases: 1 same name, other suffix; 2 same skeleton; 3 typing mistake (distance <= 1 for a name of 5 to 8
	 * characters, <= 2 above, shorter names never); 4 name of the entry (8 characters or more) inside the name of the
	 * host, for the sender only. A host matching an entry, of the registrable domain of an entry or of a mailbox, or a
	 * known open service, is never a lookalike.
	 *
	 * @param string                    $host       Normalized host
	 * @param list<array<string,mixed>> $entries    Recognized domains
	 * @param bool                      $sender     Host of the sender (case 4 allowed)
	 * @param string[]                  $ownDomains Domains of the mailboxes
	 * @return array{entry:array<string,mixed>,case:int}|null
	 */
	public static function lookalike($host, array $entries, $sender = false, array $ownDomains = array())
	{
		if ($host === '' || !$entries) {
			return null;
		}
		$reg = self::registrable($host);
		if ($reg === '') {
			return null;
		}
		// A known open service is what it is (laposte.net beside laposte.fr), never an imitation; a name under a
		// shared platform (colissimo-laposte.github.io) is compared
		if (self::publicSuffix($host)['type'] !== 2) {
			foreach (self::OPEN_SERVICES as $s) {
				if ($host === $s || substr($host, -strlen('.'.$s)) === '.'.$s) {
					return null;
				}
			}
		}
		foreach ($ownDomains as $own) {
			if ($own !== '' && self::registrable($own) === $reg) {
				return null;
			}
		}
		foreach ($entries as $e) {
			if (self::matches($host, $e) || self::registrable((string) $e['domain']) === $reg) {
				return null;
			}
		}
		$name = self::nameOf($reg);
		$skel = self::skeleton($name);
		$found = null;
		foreach ($entries as $e) {
			$eReg = self::registrable((string) $e['domain']);
			if ($eReg === '') {
				continue;
			}
			$eName = self::nameOf($eReg);
			$eSkel = self::skeleton($eName);
			$len = mb_strlen($eSkel, 'UTF-8');
			$case = 0;
			if ($name === $eName) {
				$case = 1;
			} elseif ($skel !== '' && $skel === $eSkel) {
				$case = 2;
			} elseif ($len >= 5 && self::distance($skel, $eSkel) <= ($len > 8 ? 2 : 1)) {
				$case = 3;
			} elseif ($sender && mb_strlen($eName, 'UTF-8') >= 8 && strpos($skel, $eSkel) !== false) {
				$case = 4;
			}
			if ($case && (!$found || $case < $found['case'])) {
				$found = array('entry' => $e, 'case' => $case);
			}
		}

		return $found;
	}

	/**
	 * Recognized domain a domain looks like (every case, the containing one too), for the dialog « Recognize this
	 * domain… »: offered unchecked with the warning
	 *
	 * @param string                    $domain  Normalized domain
	 * @param list<array<string,mixed>> $entries Recognized domains
	 * @param string[]                  $own     Domains of the mailboxes
	 * @return array{domain:string,label:string}|null
	 */
	public static function likeOf($domain, array $entries, array $own = array())
	{
		$like = self::lookalike($domain, $entries, true, $own);

		return $like ? array('domain' => (string) $like['entry']['domain'], 'label' => (string) $like['entry']['label']) : null;
	}

	// ---------- List ----------

	/**
	 * Recognized domains of the entity, with who added them
	 *
	 * @return list<array{id:int,domain:string,label:string,subdomains:int,note:string,date_creation:int,user_creat:string,tms:int}>
	 */
	public function fetchAll()
	{
		global $conf;

		$entity = (int) $conf->entity;
		if (isset(self::$cache[$entity])) {
			return self::$cache[$entity];
		}
		$p = $this->db->prefix();
		$rows = $this->db->getRows("SELECT t.rowid, t.domain, t.label, t.subdomains, t.note, t.date_creation, t.tms, u.firstname, u.lastname, u.login"
			." FROM ".$p."inbox_trusted_domain AS t LEFT JOIN ".$p."user AS u ON u.rowid = t.fk_user_creat"
			." WHERE t.entity = ".$entity." ORDER BY t.label, t.domain LIMIT 1000");
		$out = array();
		foreach (is_array($rows) ? $rows : array() as $r) {
			$name = trim($r->firstname.' '.$r->lastname);
			$out[] = array(
				'id' => (int) $r->rowid,
				'domain' => (string) $r->domain,
				'label' => (string) $r->label,
				'subdomains' => (int) $r->subdomains,
				'note' => (string) $r->note,
				'date_creation' => (int) $this->db->jdate($r->date_creation),
				'user_creat' => $name !== '' ? $name : (string) $r->login,
				'tms' => (int) $this->db->jdate($r->tms),
			);
		}
		self::$cache[$entity] = $out;

		return $out;
	}

	/**
	 * Domains of the mailboxes of the entity (never recognized, never lookalikes)
	 *
	 * @return string[]
	 */
	public function ownDomains()
	{
		global $conf;

		$rows = $this->db->getRows("SELECT DISTINCT email FROM ".$this->db->prefix()."inbox_account WHERE entity = ".((int) $conf->entity)." LIMIT 500");
		$out = array();
		foreach (is_array($rows) ? $rows : array() as $r) {
			$d = self::normalize((string) $r->email);
			if ($d !== '') {
				$out[$d] = $d;
			}
		}

		return array_values($out);
	}

	/**
	 * Entry recognizing a host, or null
	 *
	 * @param string $host Normalized host
	 * @return array<string,mixed>|null
	 */
	public function find($host)
	{
		foreach ($this->fetchAll() as $e) {
			if (self::matches($host, $e)) {
				return $e;
			}
		}

		return null;
	}

	/**
	 * Add a recognized domain
	 *
	 * @param User   $user       User
	 * @param string $domain     Domain as typed
	 * @param string $label      Organisation
	 * @param int    $subdomains 1 = its subdomains too
	 * @param string $note       Source of the check
	 * @return int                Id if OK, < 0 if KO ($this->error = key of translation, $this->errorDomain)
	 */
	public function create(User $user, $domain, $label, $subdomains = 1, $note = '')
	{
		global $conf;

		$d = self::normalize($domain);
		if (!$this->check($d, $label, $note, 0)) {
			return -1;
		}
		$p = $this->db->prefix();
		$sql = "INSERT INTO ".$p."inbox_trusted_domain (entity, domain, label, subdomains, note, date_creation, fk_user_creat) VALUES ("
			.((int) $conf->entity).", '".$this->db->escape($d)."', '".$this->db->escape(self::cleanText($label, 128))."', ".($subdomains ? 1 : 0).", "
			.(self::cleanText($note, 255) === '' ? 'NULL' : "'".$this->db->escape(self::cleanText($note, 255))."'").", '".$this->db->idate(dol_now())."', ".((int) $user->id).")";
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -2;
		}
		self::$cache = array();

		return (int) $this->db->last_insert_id($p.'inbox_trusted_domain');
	}

	/**
	 * Change a recognized domain
	 *
	 * @param User   $user       User
	 * @param int    $id         Entry
	 * @param string $domain     Domain as typed
	 * @param string $label      Organisation
	 * @param int    $subdomains 1 = its subdomains too
	 * @param string $note       Source of the check
	 * @return int                > 0 if OK, 0 not found, < 0 if KO
	 */
	public function update(User $user, $id, $domain, $label, $subdomains = 1, $note = '')
	{
		global $conf;

		if (!in_array((int) $id, array_column($this->fetchAll(), 'id'), true)) {
			return 0;
		}
		$d = self::normalize($domain);
		if (!$this->check($d, $label, $note, (int) $id)) {
			return -1;
		}
		$note = self::cleanText($note, 255);
		$sql = "UPDATE ".$this->db->prefix()."inbox_trusted_domain SET domain = '".$this->db->escape($d)."', label = '".$this->db->escape(self::cleanText($label, 128))."',"
			." subdomains = ".($subdomains ? 1 : 0).", note = ".($note === '' ? 'NULL' : "'".$this->db->escape($note)."'").", fk_user_modif = ".((int) $user->id)
			." WHERE rowid = ".((int) $id)." AND entity = ".((int) $conf->entity);
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -2;
		}
		self::$cache = array();

		return 1;
	}

	/**
	 * Remove a recognized domain
	 *
	 * @param int $id Entry
	 * @return int     > 0 if OK, 0 not found, < 0 if KO
	 */
	public function delete($id)
	{
		global $conf;

		if (!in_array((int) $id, array_column($this->fetchAll(), 'id'), true)) {
			return 0;
		}
		if (!$this->db->query("DELETE FROM ".$this->db->prefix()."inbox_trusted_domain WHERE rowid = ".((int) $id)." AND entity = ".((int) $conf->entity))) {
			$this->error = $this->db->lasterror();
			return -2;
		}
		self::$cache = array();

		return 1;
	}

	/** @var string Domain refused by the last create() or update() */
	public $errorDomain = '';

	/**
	 * Checks of a domain before it is written
	 *
	 * @param string $domain Normalized domain
	 * @param string $label  Organisation
	 * @param string $note   Note
	 * @param int    $id     Entry changed (0 = new)
	 * @return bool
	 */
	protected function check($domain, $label, $note, $id)
	{
		$this->errorDomain = $domain;
		$why = self::refusal($domain, $this->ownDomains());
		if ($why !== '') {
			$this->error = $why;
			return false;
		}
		if (self::cleanText($label, 1000) === '' || dol_strlen(self::cleanText($label, 1000)) > 128) {
			$this->error = 'InboxTrustedErrorLabel';
			return false;
		}
		if (dol_strlen(self::cleanText($note, 1000)) > 255) {
			$this->error = 'InboxTrustedErrorNote';
			return false;
		}
		foreach ($this->fetchAll() as $e) {
			if ($e['domain'] === $domain && $e['id'] !== $id) {
				$this->error = 'InboxTrustedErrorExists';
				return false;
			}
		}

		return true;
	}

	/**
	 * Text on one line, without tags
	 *
	 * @param string $text Text
	 * @param int    $max  Characters kept at most
	 * @return string
	 */
	protected static function cleanText($text, $max)
	{
		$text = trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) $text)));

		return dol_trunc($text, $max, 'right', 'UTF-8', 1);
	}

	// ---------- Reading of a mail ----------

	/**
	 * What inbox says of the sender of a mail
	 *
	 * ok: domain recognized and sender proven (InboxReader::authPass); unproven: domain recognized, authentication
	 * failed; lookalike: imitates a recognized domain. Null: nothing to say (no list, or not verifiable).
	 *
	 * @param string              $fromEmail Address of the From:
	 * @param string              $replyTo   Address of the Reply-To:
	 * @param array<string,mixed> $auth      InboxReader::authResults()
	 * @param bool                $passed    InboxReader::authPass() for this sender
	 * @return array<string,mixed>|null
	 */
	public function senderVerdict($fromEmail, $replyTo, array $auth, $passed)
	{
		$entries = $this->fetchAll();
		$host = self::normalize((string) $fromEmail);
		if (!$entries || $host === '' || strpos((string) $fromEmail, '@') === false) {
			return null;
		}
		$e = $this->find($host);
		if ($e) {
			$base = array('domain' => $e['domain'], 'label' => $e['label'], 'note' => $e['note'], 'user' => $e['user_creat'], 'date' => $e['date_creation'], 'host' => $host);
			if ($passed) {
				$reply = self::normalize((string) $replyTo);
				$base['state'] = 'ok';
				$base['by'] = ($auth['dmarc'] ?? '') === 'pass' ? 'DMARC' : 'DKIM';
				$base['reply_to'] = ($reply !== '' && !self::matches($reply, $e) && self::registrable($reply) !== self::registrable($host)) ? $reply : '';
				return $base;
			}
			if (!empty($auth['fail'])) {
				require_once __DIR__.'/inboxknownsenders.class.php';
				if (!InboxKnownSenders::trustedServers() || !empty($auth['trusted'])) {
					$base['state'] = 'unproven';
					return $base;
				}
			}
			return null;
		}
		$like = self::lookalike($host, $entries, true, $this->ownDomains());
		if ($like) {
			return array('state' => 'lookalike', 'case' => $like['case'], 'level' => $like['case'] === 4 ? 'warning' : 'danger',
				'domain' => $like['entry']['domain'], 'label' => $like['entry']['label'], 'host' => $host);
		}

		return null;
	}

	/**
	 * Hosts of the links of a body: the address of each link and the domain its text shows
	 *
	 * @param string $html Body HTML
	 * @param string $text Body text
	 * @param int    $max  Hosts at most
	 * @return array<string,int> Normalized host (without www.) => links to it (texts count 0)
	 */
	public static function linkHosts($html, $text, $max = 300)
	{
		$hosts = array();
		$add = function ($value, $count) use (&$hosts, $max) {
			if (count($hosts) >= $max && !isset($hosts[$value])) {
				return;
			}
			$h = self::normalize($value);
			if ($h !== '') {
				$hosts[$h] = ($hosts[$h] ?? 0) + $count;
			}
		};
		if ((string) $html !== '' && class_exists('DOMDocument')) {
			$doc = new DOMDocument();
			$prev = libxml_use_internal_errors(true);
			$doc->loadHTML('<?xml encoding="UTF-8">'.substr((string) $html, 0, 2000000), LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
			libxml_clear_errors();
			libxml_use_internal_errors($prev);
			$i = 0;
			foreach ($doc->getElementsByTagName('a') as $a) {
				if (++$i > 1000) {
					break;
				}
				$href = trim((string) $a->getAttribute('href'));
				if (preg_match('#^https?://#i', $href)) {
					$add($href, 1);
				}
				// What the text shows: a URL or a bare domain (www.exemple.com, exemple.com/page)
				$shown = trim((string) $a->textContent);
				if ($shown !== '' && strlen($shown) < 300 && preg_match('#^(https?://)?(www\.)?[\p{L}0-9-]+(\.[\p{L}0-9-]+)+(/\S*)?$#ui', $shown)) {
					$add($shown, 0);
				}
			}
		}
		if ((string) $text !== '' && preg_match_all('#https?://[^\s<>"\'()]+#i', substr((string) $text, 0, 500000), $m)) {
			foreach (array_slice($m[0], 0, 1000) as $url) {
				$add($url, 1);
			}
		}

		return $hosts;
	}

	/**
	 * What inbox says of the links of a mail: recognized hosts and lookalikes (cases 1 to 3), only the hosts with
	 * something to say; and the registrable domains of the links that could be recognized (for the dialog
	 * « Recognize this domain… »)
	 *
	 * @param string $html     Body HTML
	 * @param string $text     Body text
	 * @param bool   $withList Also the domains for the dialog (the user may manage the list)
	 * @return array{hosts:array<string,array<string,string>>,domains:list<array{domain:string,links:int,like:array{domain:string,label:string}|null}>}
	 */
	public function linkVerdicts($html, $text, $withList = false)
	{
		$entries = $this->fetchAll();
		$out = array('hosts' => array(), 'domains' => array());
		if (!$entries && !$withList) {
			return $out;
		}
		$hosts = self::linkHosts($html, $text);
		$own = $this->ownDomains();
		$domains = array();
		foreach ($hosts as $h => $count) {
			$e = $this->find($h);
			if ($e) {
				$out['hosts'][$h] = array('t' => 'trusted', 'domain' => $e['domain'], 'label' => $e['label']);
			} elseif ($entries && ($like = self::lookalike($h, $entries, false, $own))) {
				$out['hosts'][$h] = array('t' => 'lookalike', 'domain' => $like['entry']['domain'], 'label' => $like['entry']['label']);
			}
			if ($withList && $count > 0 && !$e) {
				$reg = self::registrable($h);
				if ($reg !== '' && self::refusal($reg, $own) === '') {
					$domains[$reg] = ($domains[$reg] ?? 0) + $count;
				}
			}
		}
		arsort($domains);
		foreach (array_slice($domains, 0, 8, true) as $d => $n) {
			// A lookalike stays offered (a real organisation may write from it) but never checked, and said
			$out['domains'][] = array('domain' => (string) $d, 'links' => (int) $n, 'like' => self::likeOf((string) $d, $entries, $own));
		}

		return $out;
	}
}
