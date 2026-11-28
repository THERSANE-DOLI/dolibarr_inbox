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
 * \file    inbox/class/inboxcontactmatcher.class.php
 * \ingroup inbox
 * \brief   Thirdparty and contact of Dolibarr of an address (exact address, then its domain)
 */

/**
 * Exact address of the sender looked for in the contacts, then in the thirdparties of the entity. Cache for the pass.
 */
class InboxContactMatcher
{
	/** @var DoliDB */
	protected $db;

	/** @var array<string,array{fk_soc:int,fk_socpeople:int}> */
	protected $cache = array();

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct(DoliDB $db)
	{
		$this->db = $db;
	}

	/**
	 * @param string $email Address, lower case
	 * @return array{fk_soc:int,fk_socpeople:int} 0 when not found
	 */
	public function match($email)
	{
		$email = strtolower(trim((string) $email));
		if ($email === '' || strpos($email, '@') === false) {
			return array('fk_soc' => 0, 'fk_socpeople' => 0);
		}
		if (isset($this->cache[$email])) {
			return $this->cache[$email];
		}
		$p = $this->db->prefix();
		$e = $this->db->escape($email);
		$out = array('fk_soc' => 0, 'fk_socpeople' => 0);
		// Attached by hand to a thirdparty (« Not the right thirdparty? », 1.67.0): first; its contact of this address
		// when there is one
		$byHand = $this->db->getRow("SELECT a.fk_soc FROM ".$p."inbox_address_soc as a INNER JOIN ".$p."societe as s ON s.rowid = a.fk_soc"
			." WHERE a.entity = ".((int) $GLOBALS['conf']->entity)." AND a.email = '".$e."'");
		$contact = $this->db->getRow("SELECT rowid, fk_soc FROM ".$p."socpeople WHERE LOWER(email) = '".$e."' AND entity IN (".getEntity('contact').") AND statut = 1"
			.(is_object($byHand) ? " AND fk_soc = ".((int) $byHand->fk_soc) : "")." ORDER BY rowid");
		if (is_object($byHand)) {
			$out = array('fk_soc' => (int) $byHand->fk_soc, 'fk_socpeople' => is_object($contact) ? (int) $contact->rowid : 0);
		} elseif (is_object($contact)) {
			$out = array('fk_soc' => (int) $contact->fk_soc, 'fk_socpeople' => (int) $contact->rowid);
		} else {
			$soc = $this->db->getRow("SELECT rowid FROM ".$p."societe WHERE LOWER(email) = '".$e."' AND entity IN (".getEntity('societe').") AND status = 1 ORDER BY rowid");
			if (is_object($soc)) {
				$out['fk_soc'] = (int) $soc->rowid;
			}
		}
		if (count($this->cache) > 5000) {
			$this->cache = array();
		}
		$this->cache[$email] = $out;

		return $out;
	}

	/**
	 * Thirdparty of an address by its domain: a thirdparty or a contact of it with an address at this domain,
	 * or a website on it; never for a shared domain (public mail service, disposable address, domain marked shared:
	 * InboxDomains); nothing when several thirdparties share the domain (domainCandidates() offers them)
	 *
	 * @param string $email Address
	 * @return int Thirdparty, 0 when none or not sure
	 */
	public function matchDomain($email)
	{
		$found = $this->domainSocs($email);

		return count($found['socs']) === 1 && $found['category'] === '' ? $found['socs'][0] : 0;
	}

	/**
	 * Thirdparties that may be the one of an address by its domain, to choose (never linked without the user):
	 * several thirdparties on a domain, or a domain marked shared
	 *
	 * @param string $email Address
	 * @return array{socs:int[],category:string,domain:string} socs empty for a public or disposable domain
	 */
	public function domainCandidates($email)
	{
		$found = $this->domainSocs($email);
		if (count($found['socs']) === 1 && $found['category'] === '') {
			$found['socs'] = array();
		}

		return $found;
	}

	/**
	 * @param string $email Address
	 * @return array{socs:int[],category:string,domain:string} At most 5 thirdparties
	 */
	protected function domainSocs($email)
	{
		$email = strtolower(trim((string) $email));
		$at = strrpos($email, '@');
		$out = array('socs' => array(), 'category' => '', 'domain' => '');
		if ($at === false) {
			return $out;
		}
		$domain = substr($email, $at + 1);
		$out['domain'] = $domain;
		if ($domain === '') {
			return $out;
		}
		require_once __DIR__.'/inboxdomains.class.php';
		$out['category'] = (new InboxDomains($this->db))->categoryOf($domain);
		if (in_array($out['category'], array(InboxDomains::PUBLIC, InboxDomains::DISPOSABLE), true)) {
			return $out;
		}
		// Recognition by the domain turned off (Settings > Public and shared domains, INBOX_DOMAIN_MATCH): the exact address only, no
		// search of the thirdparties of the domain (the category stays: a search in a text, nothing to save)
		if (!getDolGlobalInt('INBOX_DOMAIN_MATCH', 1)) {
			return $out;
		}
		$key = '@'.$domain;
		if (isset($this->cache[$key])) {
			$out['socs'] = $this->cache[$key]['socs'];
			return $out;
		}
		$start = microtime(true);
		$p = $this->db->prefix();
		$like = $this->db->escape($this->db->escapeforlike('@'.$domain));
		$site = $this->db->escape($this->db->escapeforlike($domain));
		$rows = $this->db->getRows("SELECT DISTINCT s.rowid FROM ".$p."societe as s WHERE s.entity IN (".getEntity('societe').") AND s.status = 1 AND (LOWER(s.email) LIKE '%".$like."'"
			// The site: this very domain or a subdomain of it (never « myclient.fr » for « client.fr »)
			." OR LOWER(s.url) IN ('".$site."', 'www.".$site."') OR LOWER(s.url) LIKE '".$site."/%' OR LOWER(s.url) LIKE '%//".$site."' OR LOWER(s.url) LIKE '%//".$site."/%'"
			." OR LOWER(s.url) LIKE '%.".$site."' OR LOWER(s.url) LIKE '%.".$site."/%'"
			." OR s.rowid IN (SELECT c.fk_soc FROM ".$p."socpeople as c WHERE c.statut = 1 AND c.fk_soc > 0 AND LOWER(c.email) LIKE '%".$like."' AND c.entity IN (".getEntity('contact')."))) ORDER BY s.rowid LIMIT 5");
		$out['socs'] = array_map(function ($r) {
			return (int) $r->rowid;
		}, is_array($rows) ? $rows : array());
		// Measured, not guessed: LIKE '%@domain' reads the whole tables, the slow part on a big database
		$ms = (int) round((microtime(true) - $start) * 1000);
		if ($ms > 200) {
			dol_syslog(__METHOD__.' thirdparties of the domain '.$domain.' found in '.$ms.' ms (Settings > Public and shared domains can turn the recognition by the domain off)', LOG_WARNING);
		}
		$this->cache[$key] = array('fk_soc' => count($out['socs']) === 1 ? $out['socs'][0] : 0, 'fk_socpeople' => 0, 'socs' => $out['socs']);

		return $out;
	}

	/**
	 * Match again the mails of an address (a contact or a thirdparty was created or got this address)
	 *
	 * @param string $email Address
	 * @return int           Rows updated
	 */
	public function refreshAddress($email)
	{
		unset($this->cache[strtolower(trim((string) $email))]);
		$m = $this->match($email);
		$sql = "UPDATE ".$this->db->prefix()."inbox_mail SET fk_soc = ".($m['fk_soc'] ? (int) $m['fk_soc'] : 'NULL').", fk_socpeople = ".($m['fk_socpeople'] ? (int) $m['fk_socpeople'] : 'NULL');
		// Never the mails whose thirdparty was chosen by hand
		$sql .= " WHERE ".self::addressWhere($this->db, $email)." AND soc_manual = 0";

		return $this->db->query($sql) ? (int) $this->db->affected_rows(true) : 0;
	}

	/**
	 * Mails of the index whose correspondent is an address: its own mails, but the ones sent by a site for another
	 * requester, and the ones sent by a site for it (data sources, 1.72.0)
	 *
	 * @param DoliDB $db    Database
	 * @param string $email Address
	 * @return string        Condition on inbox_mail (no alias)
	 */
	public static function addressWhere($db, $email)
	{
		$e = $db->escape(strtolower(trim((string) $email)));

		return "((from_email = '".$e."' AND requester_email IS NULL) OR requester_email = '".$e."')";
	}
}
