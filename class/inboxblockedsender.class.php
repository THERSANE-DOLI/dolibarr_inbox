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
 * \file    inbox/class/inboxblockedsender.class.php
 * \ingroup inbox
 * \brief   Blocked senders (1.85.0): one list for all the mailboxes, their mails go to the junk
 */

require_once __DIR__.'/inboxtrusteddomain.class.php';
require_once __DIR__.'/inboxaccess.class.php';
require_once __DIR__.'/inboxactionlog.class.php';

/**
 * A blocked sender is an address or a domain (and its subdomains) whose mails go to the junk of every mailbox: by a
 * block of the Sieve script of the mailbox (InboxRules), else by the synchronisation. Never deleted, never refused:
 * a mistake is undone by unblocking. Who may block depends on the sender, worked out by the server at every step:
 * - protected (users of Dolibarr, our mailboxes and their domains, recognized domains, senders of the data sources):
 *   never;
 * - known (thirdparty or contact, prospects and inactive ones too, domain of a thirdparty, an address we wrote to, the
 *   sender of a mail linked to a piece): the right « Manage the blocked senders » and a strong confirmation;
 * - unknown: the level « manage » on the mailbox of the mail, or the right.
 * A domain needs the right, never a public mail service nor a service open to anyone.
 * Analysis: analyses/analyse-expediteurs-bloques.md.
 */
class InboxBlockedSender
{
	const TYPE_ADDRESS = 'address';
	const TYPE_DOMAIN = 'domain';

	const LEVEL_UNKNOWN = 'unknown';
	const LEVEL_KNOWN = 'known';
	const LEVEL_PROTECTED = 'protected';

	/** Mails already received moved at once, at most (the next ones go by the Sieve block or the synchronisation) */
	const MAX_PAST = 200;

	/** Above this number of entries, the settings warn (size of the Sieve script) */
	const MANY = 5000;

	/** States of the block on a mailbox */
	const STATE_OK = 'ok';
	const STATE_PENDING = 'pending';
	const STATE_FOREIGN = 'foreign';
	const STATE_NOSIEVE = 'nosieve';
	const STATE_ERROR = 'error';

	/** A mailbox where the block could not be written is tried again after (seconds) */
	const RETRY_SECONDS = 3600;

	/** @var DoliDB */
	protected $db;

	/** @var string Key of translation of the last refusal */
	public $error = '';

	/** @var string Parameter of the message of the last refusal */
	public $errorParam = '';

	/** @var array<int,array{address:array<string,int>,domain:array<string,int>}> Entries per entity, for one request */
	protected static $cache = array();

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct(DoliDB $db)
	{
		$this->db = $db;
	}

	/**
	 * Setting of the administrator (Settings > Anti-spam): on by default
	 *
	 * @return bool
	 */
	public static function enabled()
	{
		return (bool) getDolGlobalInt('INBOX_BLOCKED_SENDERS', 1);
	}

	/**
	 * Forget the list read in this request
	 *
	 * @return void
	 */
	public static function clearCache()
	{
		self::$cache = array();
	}

	/**
	 * An address as stored and compared: lower case, trimmed; '' when it is not an address
	 *
	 * @param string $value Address as typed
	 * @return string
	 */
	public static function normalizeAddress($value)
	{
		$v = strtolower(trim((string) $value, " \t\r\n<>\"'"));
		if (strlen($v) > 254 || !preg_match('/^[^@\s<>"(),;:\\\\]+@([^@\s]+)$/', $v, $m) || InboxTrustedDomain::normalize($m[1]) === '') {
			return '';
		}

		return substr($v, 0, strrpos($v, '@') + 1).InboxTrustedDomain::normalize($m[1]);
	}

	/**
	 * Domain of an address ('' when none)
	 *
	 * @param string $email Address
	 * @return string
	 */
	public static function domainOf($email)
	{
		$at = strrpos((string) $email, '@');

		return $at === false ? '' : InboxTrustedDomain::normalize(substr((string) $email, $at + 1));
	}

	/**
	 * The host is the domain or one of its subdomains (never « contains »)
	 *
	 * @param string $host   Normalized host
	 * @param string $domain Normalized domain
	 * @return bool
	 */
	public static function under($host, $domain)
	{
		return $host !== '' && $domain !== '' && ($host === $domain || substr($host, -strlen('.'.$domain)) === '.'.$domain);
	}

	// ---------- The list ----------

	/**
	 * Entries of the entity, read once per request
	 *
	 * @return array{address:array<string,int>,domain:array<string,int>} value => id
	 */
	protected function entries()
	{
		global $conf;

		$entity = (int) $conf->entity;
		if (isset(self::$cache[$entity])) {
			return self::$cache[$entity];
		}
		$out = array('address' => array(), 'domain' => array());
		// Module updated but not reactivated yet: no table, nothing blocked
		$rows = $this->db->getRows("SELECT rowid, type, value FROM ".$this->db->prefix()."inbox_blocked_sender WHERE entity = ".$entity." ORDER BY rowid LIMIT 50000");
		foreach (is_array($rows) ? $rows : array() as $r) {
			if (isset($out[(string) $r->type])) {
				$out[(string) $r->type][(string) $r->value] = (int) $r->rowid;
			}
		}
		self::$cache[$entity] = $out;

		return $out;
	}

	/**
	 * The entry blocking an address (the address itself first, then its domain or a parent domain), 0 when none or
	 * when the list is turned off
	 *
	 * @param string $email Address of the sender
	 * @return int Id of the entry
	 */
	public function isBlocked($email)
	{
		if (!self::enabled()) {
			return 0;
		}
		$email = self::normalizeAddress($email);
		if ($email === '') {
			return 0;
		}
		$e = $this->entries();
		if (isset($e['address'][$email])) {
			return $e['address'][$email];
		}
		$d = self::domainOf($email);
		while ($d !== '') {
			if (isset($e['domain'][$d])) {
				return $e['domain'][$d];
			}
			$pos = strpos($d, '.');
			$d = $pos === false ? '' : substr($d, $pos + 1);
		}

		return 0;
	}

	/**
	 * Fingerprint of the list as written in the Sieve block (empty list or list turned off: the same)
	 *
	 * @return string
	 */
	public function hash()
	{
		$e = self::enabled() ? $this->entries() : array('address' => array(), 'domain' => array());
		$a = array_keys($e['address']);
		$d = array_keys($e['domain']);
		sort($a);
		sort($d);

		return sha1((string) json_encode(array($a, $d)));
	}

	/**
	 * What the Sieve block of a mailbox holds
	 *
	 * @return array{addresses:string[],domains:string[],hash:string}
	 */
	public function forScript()
	{
		$e = self::enabled() ? $this->entries() : array('address' => array(), 'domain' => array());
		$a = array_map('strval', array_keys($e['address']));
		$d = array_map('strval', array_keys($e['domain']));
		sort($a);
		sort($d);

		return array('addresses' => $a, 'domains' => $d, 'hash' => $this->hash());
	}

	/**
	 * The list for the settings, the newest first
	 *
	 * @param int                 $id      One entry only (0: all)
	 * @param array<string,mixed> $filters search (address, domain, note, author), type (address, domain), offset, limit
	 * @return list<array<string,mixed>>
	 */
	public function fetchAll($id = 0, array $filters = array())
	{
		$p = $this->db->prefix();
		$limit = isset($filters['limit']) ? max(1, min(500, (int) $filters['limit'])) : 50000;
		$offset = max(0, (int) ($filters['offset'] ?? 0));
		$rows = $this->db->getRows("SELECT b.rowid, b.type, b.value, b.known_reason, b.note, b.fk_mailref, b.known_since, b.known_now, b.date_creation, b.fk_user_creat,"
			." u.firstname, u.lastname, u.login, r.subject FROM ".$p."inbox_blocked_sender as b LEFT JOIN ".$p."user as u ON u.rowid = b.fk_user_creat"
			." LEFT JOIN ".$p."inbox_mailref as r ON r.rowid = b.fk_mailref WHERE ".$this->where($id, $filters)
			." ORDER BY b.date_creation DESC, b.rowid DESC LIMIT ".$limit.($offset ? " OFFSET ".$offset : ""));
		$out = array();
		foreach (is_array($rows) ? $rows : array() as $r) {
			$name = trim((string) $r->firstname.' '.(string) $r->lastname);
			$out[] = array(
				'id' => (int) $r->rowid, 'type' => (string) $r->type, 'value' => (string) $r->value,
				'known_reason' => (string) $r->known_reason, 'note' => (string) $r->note,
				'known_since' => $r->known_since ? (int) $this->db->jdate($r->known_since) : 0, 'known_now' => (string) $r->known_now,
				'date_creation' => (int) $this->db->jdate($r->date_creation), 'user_id' => (int) $r->fk_user_creat,
				'user_creat' => $name !== '' ? $name : (string) $r->login,
				'mail_subject' => (string) $r->subject,
			);
		}

		return $out;
	}

	/**
	 * Number of entries matching the filters (pages of the settings)
	 *
	 * @param array<string,mixed> $filters search, type
	 * @return int
	 */
	public function count(array $filters = array())
	{
		$p = $this->db->prefix();
		$r = $this->db->getRow("SELECT COUNT(b.rowid) as n FROM ".$p."inbox_blocked_sender as b LEFT JOIN ".$p."user as u ON u.rowid = b.fk_user_creat WHERE ".$this->where(0, $filters));

		return is_object($r) ? (int) $r->n : 0;
	}

	/**
	 * @param int                 $id      One entry only (0: all)
	 * @param array<string,mixed> $filters search, type
	 * @return string Condition on b (entry) and u (author)
	 */
	protected function where($id, array $filters)
	{
		global $conf;

		$w = "b.entity = ".((int) $conf->entity).($id > 0 ? " AND b.rowid = ".((int) $id) : "");
		$type = (string) ($filters['type'] ?? '');
		if (in_array($type, array(self::TYPE_ADDRESS, self::TYPE_DOMAIN), true)) {
			$w .= " AND b.type = '".$this->db->escape($type)."'";
		}
		$q = mb_substr(trim((string) ($filters['search'] ?? '')), 0, 100);
		if ($q !== '') {
			$like = $this->db->escape($this->db->escapeforlike(mb_strtolower($q)));
			$w .= " AND (b.value LIKE '%".$like."%' OR LOWER(b.note) LIKE '%".$like."%' OR LOWER(CONCAT(COALESCE(u.firstname, ''), ' ', COALESCE(u.lastname, ''), ' ', COALESCE(u.login, ''))) LIKE '%".$like."%')";
		}

		return $w;
	}

	/**
	 * One entry
	 *
	 * @param int $id Entry
	 * @return array<string,mixed>|null
	 */
	public function fetch($id)
	{
		$list = (int) $id > 0 ? $this->fetchAll((int) $id) : array();

		return $list ? $list[0] : null;
	}

	/**
	 * The sender of a mail is blocked: what the reading says (who, when, may the user unblock)
	 *
	 * @param User   $user  User
	 * @param string $email Address of the sender
	 * @return array<string,mixed>|null
	 */
	public function viewFor(User $user, $email)
	{
		$id = $this->isBlocked($email);
		$e = $id ? $this->fetch($id) : null;
		if (!$e) {
			return null;
		}

		return array('id' => $e['id'], 'type' => $e['type'], 'value' => $e['value'], 'by' => $e['user_creat'], 'date' => $e['date_creation'],
			'can_unblock' => self::isManager($user) || $e['user_id'] === (int) $user->id);
	}

	// ---------- Who the sender is ----------

	/**
	 * Level of a sender (an address or a domain) and why, worked out from Dolibarr and inbox
	 *
	 * @param string $type  address or domain
	 * @param string $value Normalized address or domain
	 * @return array{level:string,reason:string} reason: sentence in the language of the user ('' when unknown)
	 */
	public function levelOf($type, $value)
	{
		$why = $this->protectedReason($type, $value);
		if ($why !== '') {
			return array('level' => self::LEVEL_PROTECTED, 'reason' => $why);
		}
		$why = $this->knownReason($type, $value);
		if ($why !== '') {
			return array('level' => self::LEVEL_KNOWN, 'reason' => $why);
		}

		return array('level' => self::LEVEL_UNKNOWN, 'reason' => '');
	}

	/**
	 * Why a domain can never be blocked as a whole ('' when it can): not a domain, public suffix, public mail service
	 * or service open to anyone (everybody sends from there)
	 *
	 * @param string $domain Normalized domain
	 * @return string Key of translation
	 */
	public function domainRefusal($domain)
	{
		if ($domain === '') {
			return 'InboxBlockedErrorValue';
		}
		if (InboxTrustedDomain::isPublicSuffix($domain)) {
			return 'InboxBlockedErrorPublicSuffix';
		}
		require_once __DIR__.'/inboxdomains.class.php';
		if (InboxTrustedDomain::isOpenService($domain) || (new InboxDomains($this->db))->categoryOf($domain) === InboxDomains::PUBLIC) {
			return 'InboxBlockedErrorOpenService';
		}

		return '';
	}

	/**
	 * Protected sender: never blocked
	 *
	 * @param string $type  address or domain
	 * @param string $value Normalized value
	 * @return string Sentence ('' when not protected)
	 */
	protected function protectedReason($type, $value)
	{
		global $langs, $conf;

		$p = $this->db->prefix();
		$isDomain = $type === self::TYPE_DOMAIN;
		$domain = $isDomain ? $value : self::domainOf($value);
		// Users of Dolibarr, active or not
		if ($isDomain) {
			$like = $this->db->escape($this->db->escapeforlike($value));
			$u = $this->db->getRow("SELECT rowid FROM ".$p."user WHERE LOWER(email) LIKE '%@".$like."' OR LOWER(email) LIKE '%.".$like."'");
		} else {
			$u = $this->db->getRow("SELECT rowid FROM ".$p."user WHERE LOWER(email) = '".$this->db->escape($value)."'");
		}
		if (is_object($u)) {
			return $langs->transnoentities($isDomain ? 'InboxBlockedProtectedUserDomain' : 'InboxBlockedProtectedUser');
		}
		// Our mailboxes, their sending addresses and their domains
		$trusted = new InboxTrustedDomain($this->db);
		foreach ($trusted->ownDomains() as $own) {
			if (self::under($domain, $own) || ($isDomain && self::under($own, $domain))) {
				return $langs->transnoentities('InboxBlockedProtectedOwn');
			}
		}
		if (!$isDomain) {
			$own = $this->db->getRow("SELECT i.rowid FROM ".$p."inbox_identity as i INNER JOIN ".$p."inbox_account as a ON a.rowid = i.fk_account"
				." WHERE a.entity = ".((int) $conf->entity)." AND LOWER(i.email) = '".$this->db->escape($value)."'");
			if (is_object($own)) {
				return $langs->transnoentities('InboxBlockedProtectedOwn');
			}
		}
		// Recognized domains: the domain, a subdomain of it, or (a domain blocked) a parent of it
		foreach ($trusted->fetchAll() as $t) {
			if (InboxTrustedDomain::matches($domain, $t) || $domain === $t['domain'] || ($isDomain && self::under((string) $t['domain'], $domain))) {
				return $langs->transnoentities('InboxBlockedProtectedTrusted', (string) $t['label']);
			}
		}
		// Senders of the data sources: blocking them would stop every request of the site
		require_once __DIR__.'/inboxdatasource.class.php';
		foreach ((new InboxDataSource($this->db))->fetchAll() as $s) {
			foreach (InboxDataSource::parseSenders((string) $s['senders']) as $sender) {
				$sd = strpos($sender, '@') !== false ? self::domainOf($sender) : $sender;
				$hit = $isDomain ? (self::under($sd, $value) || self::under($value, $sd)) : ($sender === $value || (strpos($sender, '@') === false && self::under($domain, $sender)));
				if ($hit) {
					return $langs->transnoentities('InboxBlockedProtectedSource', (string) $s['label']);
				}
			}
		}

		return '';
	}

	/**
	 * Known sender: blocked by a manager of the list only, after a strong confirmation
	 *
	 * @param string $type  address or domain
	 * @param string $value Normalized value
	 * @return string Sentence ('' when unknown)
	 */
	protected function knownReason($type, $value)
	{
		global $langs, $conf;

		$p = $this->db->prefix();
		$isDomain = $type === self::TYPE_DOMAIN;
		$domain = $isDomain ? $value : self::domainOf($value);
		if (!$isDomain) {
			$e = $this->db->escape($value);
			$like = $this->db->escape($this->db->escapeforlike($value));
			// Attached by hand to a thirdparty
			$r = $this->db->getRow("SELECT s.nom FROM ".$p."inbox_address_soc as a INNER JOIN ".$p."societe as s ON s.rowid = a.fk_soc WHERE a.entity = ".((int) $conf->entity)." AND a.email = '".$e."'");
			if (is_object($r)) {
				return $langs->transnoentities('InboxBlockedKnownSoc', (string) $r->nom);
			}
			// Contacts and thirdparties, inactive and prospects too; a field may hold several addresses
			$rows = $this->db->getRows("SELECT c.email, c.firstname, c.lastname, s.nom FROM ".$p."socpeople as c LEFT JOIN ".$p."societe as s ON s.rowid = c.fk_soc"
				." WHERE c.entity IN (".getEntity('contact').") AND LOWER(c.email) LIKE '%".$like."%' LIMIT 50");
			foreach (is_array($rows) ? $rows : array() as $c) {
				if (self::holds((string) $c->email, $value)) {
					$name = trim((string) $c->firstname.' '.(string) $c->lastname);
					return $c->nom !== null && (string) $c->nom !== '' ? $langs->transnoentities('InboxBlockedKnownContactOf', $name !== '' ? $name : $value, (string) $c->nom)
						: $langs->transnoentities('InboxBlockedKnownContact', $name !== '' ? $name : $value);
				}
			}
			$rows = $this->db->getRows("SELECT email, nom FROM ".$p."societe WHERE entity IN (".getEntity('societe').") AND LOWER(email) LIKE '%".$like."%' LIMIT 50");
			foreach (is_array($rows) ? $rows : array() as $s) {
				if (self::holds((string) $s->email, $value)) {
					return $langs->transnoentities('InboxBlockedKnownSoc', (string) $s->nom);
				}
			}
		}
		// Domain of a thirdparty (its address, its site, the address of a contact), never a shared mail service
		require_once __DIR__.'/inboxdomains.class.php';
		$category = (new InboxDomains($this->db))->categoryOf($domain);
		if ($domain !== '' && !in_array($category, array(InboxDomains::PUBLIC, InboxDomains::DISPOSABLE), true)) {
			$soc = $this->socOfDomain($domain);
			if ($soc !== '') {
				return $langs->transnoentities('InboxBlockedKnownSocDomain', $soc, $domain);
			}
		}
		// An address we wrote to (recipient of a mail of a folder Sent)
		$like = $this->db->escape($this->db->escapeforlike($value));
		$addr = $isDomain ? "(a.email LIKE '%@".$like."' OR a.email LIKE '%.".$like."')" : "a.email = '".$this->db->escape($value)."'";
		$r = $this->db->getRow("SELECT MAX(m.date_mail) as d FROM ".$p."inbox_mail as m INNER JOIN ".$p."inbox_folder as f ON f.rowid = m.fk_folder AND f.role = 'sent'"
			." INNER JOIN ".$p."inbox_account as ac ON ac.rowid = f.fk_account AND ac.entity = ".((int) $conf->entity)
			." INNER JOIN ".$p."inbox_mail_address as a ON a.fk_mail = m.rowid AND a.type IN ('to', 'cc') WHERE ".$addr);
		if (is_object($r) && $r->d) {
			return $langs->transnoentities($isDomain ? 'InboxBlockedKnownWrittenDomain' : 'InboxBlockedKnownWritten', dol_print_date($this->db->jdate($r->d), 'day'));
		}
		// The sender of a mail linked to a piece of Dolibarr (orders of a platform from an address of nobody)
		$from = $isDomain ? "(LOWER(r.from_email) LIKE '%@".$like."' OR LOWER(r.from_email) LIKE '%.".$like."')" : "LOWER(r.from_email) = '".$this->db->escape($value)."'";
		$r = $this->db->getRow("SELECT l.element_type FROM ".$p."inbox_link as l INNER JOIN ".$p."inbox_mailref as r ON r.rowid = l.fk_mailref"
			." WHERE l.entity = ".((int) $conf->entity)." AND ".$from." LIMIT 1");
		if (is_object($r)) {
			return $langs->transnoentities('InboxBlockedKnownPiece');
		}

		return '';
	}

	/**
	 * Name of a thirdparty (active or not) on a domain: its address, its site, the address of a contact
	 *
	 * @param string $domain Normalized domain
	 * @return string '' when none
	 */
	protected function socOfDomain($domain)
	{
		$p = $this->db->prefix();
		$like = $this->db->escape($this->db->escapeforlike('@'.$domain));
		$site = $this->db->escape($this->db->escapeforlike($domain));
		$r = $this->db->getRow("SELECT s.nom FROM ".$p."societe as s WHERE s.entity IN (".getEntity('societe').") AND (LOWER(s.email) LIKE '%".$like."' OR LOWER(s.email) LIKE '%".$like.",%'"
			." OR LOWER(s.url) IN ('".$site."', 'www.".$site."') OR LOWER(s.url) LIKE '".$site."/%' OR LOWER(s.url) LIKE '%//".$site."' OR LOWER(s.url) LIKE '%//".$site."/%'"
			." OR LOWER(s.url) LIKE '%//www.".$site."' OR LOWER(s.url) LIKE '%//www.".$site."/%'"
			." OR s.rowid IN (SELECT c.fk_soc FROM ".$p."socpeople as c WHERE c.fk_soc > 0 AND LOWER(c.email) LIKE '%".$like."' AND c.entity IN (".getEntity('contact')."))) ORDER BY s.rowid");

		return is_object($r) ? (string) $r->nom : '';
	}

	/**
	 * @param string $field Email field (maybe several addresses)
	 * @param string $email Address, lower case
	 * @return bool
	 */
	protected static function holds($field, $email)
	{
		foreach (preg_split('/[\s,;]+/', strtolower((string) $field)) as $x) {
			if (trim($x, '<>"\'') === $email) {
				return true;
			}
		}

		return false;
	}

	// ---------- Rights ----------

	/**
	 * The user manages the list: blocks known senders and domains, unblocks any entry
	 *
	 * @param User $user User
	 * @return bool
	 */
	public static function isManager(User $user)
	{
		return (bool) $user->hasRight('inbox', 'blockedsender', 'write');
	}

	/**
	 * What the user may do with the sender of a mail, said before he confirms (dialog), and checked again at the
	 * creation
	 *
	 * @param User   $user      User
	 * @param string $email     Address of the sender
	 * @param int    $accountId Mailbox of the mail (its level « manage » for an unknown address)
	 * @return array<string,mixed> address, domain, level, reason, can_address, why_address, can_domain, why_domain, blocked
	 */
	public function offer(User $user, $email, $accountId)
	{
		global $langs;

		$email = self::normalizeAddress($email);
		$domain = self::domainOf($email);
		$manager = self::isManager($user);
		$manage = $accountId > 0 && (new InboxAccess($this->db))->check($user, (int) $accountId, InboxAccess::LEVEL_MANAGE);
		$out = array('address' => $email, 'domain' => $domain, 'level' => self::LEVEL_UNKNOWN, 'reason' => '', 'can_address' => false, 'why_address' => '',
			'can_domain' => false, 'why_domain' => '', 'domain_level' => '', 'domain_reason' => '', 'blocked' => $this->isBlocked($email), 'manager' => $manager);
		if ($email === '') {
			$out['why_address'] = $langs->transnoentities('InboxBlockedErrorValue');
			return $out;
		}
		$lv = $this->levelOf(self::TYPE_ADDRESS, $email);
		$out['level'] = $lv['level'];
		$out['reason'] = $lv['reason'];
		$out['why_address'] = $this->refusal($user, self::TYPE_ADDRESS, $lv['level'], $manage, $manager);
		$out['can_address'] = $out['why_address'] === '';
		// The domain: managers only, never refused domains
		$refusal = $this->domainRefusal($domain);
		if ($refusal !== '') {
			$out['why_domain'] = $langs->transnoentities($refusal, $domain);
		} else {
			$dl = $this->levelOf(self::TYPE_DOMAIN, $domain);
			$out['domain_level'] = $dl['level'];
			$out['domain_reason'] = $dl['reason'];
			$out['why_domain'] = $this->refusal($user, self::TYPE_DOMAIN, $dl['level'], $manage, $manager);
		}
		$out['can_domain'] = $out['why_domain'] === '';

		return $out;
	}

	/**
	 * Why this user cannot block this sender ('' = he can)
	 *
	 * @param User   $user    User
	 * @param string $type    address or domain
	 * @param string $level   Level of the sender
	 * @param bool   $manage  Level « manage » on the mailbox of the mail
	 * @param bool   $manager Right « Manage the blocked senders »
	 * @return string Sentence
	 */
	protected function refusal(User $user, $type, $level, $manage, $manager)
	{
		global $langs;

		if (!self::enabled()) {
			return $langs->transnoentities('InboxBlockedOff');
		}
		if ($level === self::LEVEL_PROTECTED) {
			return $langs->transnoentities('InboxBlockedNeverProtected');
		}
		if ($type === self::TYPE_DOMAIN && !$manager) {
			return $langs->transnoentities('InboxBlockedDomainNeedRight');
		}
		if ($level === self::LEVEL_KNOWN && !$manager) {
			return $langs->transnoentities('InboxBlockedKnownNeedRight');
		}
		if (!$manager && !$manage) {
			return $langs->transnoentities('InboxBlockedNeedManage');
		}

		return '';
	}

	// ---------- Changes ----------

	/**
	 * Block an address or a domain. The level is worked out again here (never trusted from the browser).
	 *
	 * @param User   $user      User
	 * @param string $type      address or domain
	 * @param string $value     Address or domain as typed
	 * @param int    $accountId Mailbox of the mail (0: from the settings)
	 * @param string $confirm   Address or domain typed again (strong confirmation of a known sender)
	 * @param string $note      Note
	 * @param int    $mailrefId Mail of origin
	 * @return int Id (> 0, also when it was already blocked), < 0 refused ($this->error = sentence)
	 */
	public function create(User $user, $type, $value, $accountId = 0, $confirm = '', $note = '', $mailrefId = 0)
	{
		global $conf, $langs;

		$this->error = '';
		$this->errorParam = '';
		if (!in_array($type, array(self::TYPE_ADDRESS, self::TYPE_DOMAIN), true)) {
			$this->error = $langs->transnoentities('InboxBlockedErrorValue');
			return -1;
		}
		$value = $type === self::TYPE_ADDRESS ? self::normalizeAddress($value) : InboxTrustedDomain::normalize($value);
		if ($value === '' || ($type === self::TYPE_DOMAIN && strpos((string) $value, '.') === false)) {
			$this->error = $langs->transnoentities('InboxBlockedErrorValue');
			return -1;
		}
		if ($type === self::TYPE_DOMAIN) {
			$refusal = $this->domainRefusal($value);
			if ($refusal !== '') {
				$this->error = $langs->transnoentities($refusal, $value);
				return -1;
			}
		}
		$e = $this->entries();
		if (isset($e[$type][$value])) {
			return $e[$type][$value];
		}
		$manager = self::isManager($user);
		$manage = $accountId > 0 && (new InboxAccess($this->db))->check($user, (int) $accountId, InboxAccess::LEVEL_MANAGE);
		$lv = $this->levelOf($type, $value);
		$why = $this->refusal($user, $type, $lv['level'], $manage, $manager);
		if ($why !== '') {
			$this->error = $why.($lv['reason'] !== '' ? ' ('.$lv['reason'].')' : '');
			return -2;
		}
		if ($lv['level'] === self::LEVEL_KNOWN && strtolower(trim((string) $confirm)) !== $value) {
			$this->error = $langs->transnoentities('InboxBlockedConfirmMismatch', $value);
			$this->errorParam = $lv['reason'];
			return -3;
		}
		$s = function ($v, $len = 255) {
			$v = trim(preg_replace('/[\x00-\x1F\x7F]/', ' ', (string) $v));
			return $v === '' ? 'NULL' : "'".$this->db->escape(dol_trunc($v, $len, 'right', 'UTF-8', 1))."'";
		};
		$sql = "INSERT INTO ".$this->db->prefix()."inbox_blocked_sender (entity, type, value, known_reason, note, fk_mailref, date_creation, fk_user_creat) VALUES ("
			.((int) $conf->entity).", '".$this->db->escape($type)."', '".$this->db->escape($value)."', ".$s($lv['level'] === self::LEVEL_KNOWN ? $lv['reason'] : '').", ".$s($note).", "
			.($mailrefId > 0 ? (int) $mailrefId : 'NULL').", '".$this->db->idate(dol_now())."', ".((int) $user->id).")";
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$id = (int) $this->db->last_insert_id($this->db->prefix().'inbox_blocked_sender');
		$this->changed();
		// Its mails of the junk are no longer « probably legitimate »
		$like = $this->db->escape($this->db->escapeforlike($value));
		$this->db->query("UPDATE ".$this->db->prefix()."inbox_mail SET junk_hint = NULL WHERE junk_hint = 'legit' AND "
			.($type === self::TYPE_DOMAIN ? "(from_email LIKE '%@".$like."' OR from_email LIKE '%.".$like."')" : "from_email = '".$this->db->escape($value)."'"));
		InboxActionLog::add($this->db, $user, (int) $accountId, 'block', array((object) array('message_id' => '', 'subject' => $value)), '', '', 0, $type.' '.$value);

		return $id;
	}

	/**
	 * Unblock: a manager of the list, or the author of the entry
	 *
	 * @param User $user User
	 * @param int  $id   Entry
	 * @return int 1 OK, 0 not found, -1 refused or error
	 */
	public function delete(User $user, $id)
	{
		global $conf, $langs;

		$row = $this->db->getRow("SELECT rowid, type, value, fk_user_creat FROM ".$this->db->prefix()."inbox_blocked_sender WHERE rowid = ".((int) $id)." AND entity = ".((int) $conf->entity));
		if (!is_object($row)) {
			return 0;
		}
		if (!self::isManager($user) && (int) $row->fk_user_creat !== (int) $user->id) {
			$this->error = $langs->transnoentities('InboxBlockedUnblockNeedRight');
			return -1;
		}
		if (!$this->db->query("DELETE FROM ".$this->db->prefix()."inbox_blocked_sender WHERE rowid = ".((int) $id))) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$this->changed();
		InboxActionLog::add($this->db, $user, 0, 'unblock', array((object) array('message_id' => '', 'subject' => (string) $row->value)), '', '', 0, $row->type.' '.$row->value);

		return 1;
	}

	/**
	 * The list changed: every mailbox gets its block again at the next pass of the scheduled job
	 *
	 * @return void
	 */
	public function changed()
	{
		global $conf;

		self::clearCache();
		$this->db->query("UPDATE ".$this->db->prefix()."inbox_account SET blocked_state = '".self::STATE_PENDING."' WHERE entity = ".((int) $conf->entity)
			." AND (blocked_hash IS NULL OR blocked_hash <> '".$this->db->escape($this->hash())."')");
	}

	// ---------- Mails ----------

	/**
	 * Mails already received from a sender, in the inbox of the mailboxes where the user has the level « manage »
	 * (never in the others: their manager puts them away)
	 *
	 * @param User   $user  User
	 * @param string $type  address or domain
	 * @param string $value Normalized value
	 * @return array{ids:int[],accounts:int} At most MAX_PAST mails
	 */
	public function pastMails(User $user, $type, $value)
	{
		$accounts = array_keys((new InboxAccess($this->db))->getAccounts($user, InboxAccess::LEVEL_MANAGE));
		if (!$accounts || $value === '') {
			return array('ids' => array(), 'accounts' => 0);
		}
		$p = $this->db->prefix();
		$like = $this->db->escape($this->db->escapeforlike($value));
		$where = $type === self::TYPE_DOMAIN ? "(m.from_email LIKE '%@".$like."' OR m.from_email LIKE '%.".$like."')" : "m.from_email = '".$this->db->escape($value)."'";
		$rows = $this->db->getRows("SELECT m.rowid, m.fk_account, m.from_email FROM ".$p."inbox_mail as m INNER JOIN ".$p."inbox_folder as f ON f.rowid = m.fk_folder AND f.role = 'inbox' AND f.status = 1"
			." WHERE m.fk_account IN (".implode(',', array_map('intval', $accounts)).") AND ".$where." ORDER BY m.rowid DESC LIMIT ".self::MAX_PAST);
		$ids = array();
		$boxes = array();
		foreach (is_array($rows) ? $rows : array() as $r) {
			// Domain: the exact domain or a subdomain, checked here (LIKE '%.x' would take « notx »)
			if ($type === self::TYPE_DOMAIN && !self::under(self::domainOf((string) $r->from_email), $value)) {
				continue;
			}
			$ids[] = (int) $r->rowid;
			$boxes[(int) $r->fk_account] = 1;
		}

		return array('ids' => $ids, 'accounts' => count($boxes));
	}

	/**
	 * Synchronisation: the new mails of the inbox from a blocked sender go to the junk (a mailbox without Sieve, the
	 * script taken by another tool, the block not written yet). Never a mail marked legitimate ($NotJunk) by someone.
	 *
	 * @param User  $user      User of the job (journal)
	 * @param int   $accountId Mailbox
	 * @param int[] $mailIds   New mails of its inbox
	 * @return int Mails moved
	 */
	public function moveNew(User $user, $accountId, array $mailIds)
	{
		$mailIds = array_values(array_filter(array_map('intval', $mailIds)));
		if (!$mailIds || !self::enabled()) {
			return 0;
		}
		$e = $this->entries();
		if (!$e['address'] && !$e['domain']) {
			return 0;
		}
		$rows = $this->db->getRows("SELECT rowid, from_email, keywords FROM ".$this->db->prefix()."inbox_mail WHERE fk_account = ".((int) $accountId)
			." AND rowid IN (".implode(',', $mailIds).") LIMIT 2000");
		$ids = array();
		foreach (is_array($rows) ? $rows : array() as $r) {
			if (stripos(' '.(string) $r->keywords.' ', ' $NotJunk ') !== false) {
				continue;
			}
			if ($this->isBlocked((string) $r->from_email)) {
				$ids[] = (int) $r->rowid;
			}
		}
		if (!$ids) {
			return 0;
		}
		require_once __DIR__.'/inboxactions.class.php';
		$actions = new InboxActions($this->db);
		$res = $actions->runAsSystem($user, 'blocked', $ids);
		if (!$res['ok']) {
			dol_syslog(__METHOD__.' '.$res['error'], LOG_WARNING);
		}

		return (int) $res['count'];
	}

	// ---------- The Sieve block of each mailbox ----------

	/**
	 * Scheduled job: the block written again on the mailboxes whose block is not the one of the list (list changed, a
	 * mailbox where it failed: again after an hour)
	 *
	 * @param float $deadline Microtime not to go beyond
	 * @return int Mailboxes written
	 */
	public function applyAll($deadline)
	{
		global $conf;

		$hash = $this->hash();
		$p = $this->db->prefix();
		$rows = $this->db->getRows("SELECT rowid, blocked_hash, blocked_state, blocked_date FROM ".$p."inbox_account WHERE entity = ".((int) $conf->entity)
			." AND status = ".InboxAccount::STATUS_ACTIVE." AND (blocked_hash IS NULL OR blocked_hash <> '".$this->db->escape($hash)."'"
			." OR (blocked_state <> '".self::STATE_OK."' AND (blocked_date IS NULL OR blocked_date < '".$this->db->idate(dol_now() - self::RETRY_SECONDS)."'))) ORDER BY rowid LIMIT 200");
		$empty = !$this->forScript()['addresses'] && !$this->forScript()['domains'];
		$done = 0;
		require_once __DIR__.'/inboxrules.class.php';
		foreach (is_array($rows) ? $rows : array() as $r) {
			if (microtime(true) > $deadline) {
				break;
			}
			// Never written and nothing to block: nothing to ask the server
			if ($r->blocked_hash === null && $empty) {
				$this->saveState((int) $r->rowid, $hash, self::STATE_OK, '');
				continue;
			}
			$account = new InboxAccount($this->db);
			if ($account->fetch((int) $r->rowid) <= 0) {
				continue;
			}
			$rules = new InboxRules($this->db);
			$state = $rules->applyBlocked($account);
			$this->saveState((int) $r->rowid, $hash, $state, $state === self::STATE_OK ? '' : $rules->error);
			$done++;
		}

		return $done;
	}

	/**
	 * State of the block on a mailbox
	 *
	 * @param int    $accountId Mailbox
	 * @param string $hash      List written (or tried)
	 * @param string $state     ok, foreign, nosieve, error
	 * @param string $error     Message
	 * @return void
	 */
	public function saveState($accountId, $hash, $state, $error)
	{
		$this->db->query("UPDATE ".$this->db->prefix()."inbox_account SET blocked_hash = ".($hash !== '' ? "'".$this->db->escape($hash)."'" : 'NULL').", blocked_state = '".$this->db->escape($state)."',"
			." blocked_error = ".($error !== '' ? "'".$this->db->escape(dol_trunc($error, 250, 'right', 'UTF-8', 1))."'" : 'NULL').", blocked_date = '".$this->db->idate(dol_now())."'"
			." WHERE rowid = ".((int) $accountId));
	}

	/**
	 * State of the block on every mailbox (Settings > Anti-spam)
	 *
	 * @return list<array<string,mixed>>
	 */
	public function states()
	{
		global $conf;

		$rows = $this->db->getRows("SELECT rowid, email, label, blocked_hash, blocked_state, blocked_error, blocked_date FROM ".$this->db->prefix()."inbox_account WHERE entity = ".((int) $conf->entity)
			." AND status = ".InboxAccount::STATUS_ACTIVE." ORDER BY label, email LIMIT 500");
		$hash = $this->hash();
		$out = array();
		foreach (is_array($rows) ? $rows : array() as $r) {
			$state = (string) $r->blocked_state;
			if ($state === '' || ($state === self::STATE_OK && (string) $r->blocked_hash !== $hash)) {
				$state = self::STATE_PENDING;
			}
			$out[] = array('id' => (int) $r->rowid, 'email' => (string) $r->email, 'label' => (string) $r->label, 'state' => $state,
				'error' => (string) $r->blocked_error, 'date' => $r->blocked_date ? (int) $this->db->jdate($r->blocked_date) : 0);
		}

		return $out;
	}
}
