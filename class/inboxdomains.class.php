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
 * \file    inbox/class/inboxdomains.class.php
 * \ingroup inbox
 * \brief   Shared domains (1.76.0): public mail services, disposable addresses, domains shared by several of
 *          our thirdparties. Never a thirdparty by such a domain; the shared ones offer the thirdparties to choose.
 */

require_once __DIR__.'/inboxtrusteddomain.class.php';

/**
 * List used = list shipped in data/domains/ (text files read where they are, never copied in the database, never
 * downloaded by inbox) + local entries (Settings > Public and shared domains) + entries of other modules (hook inboxSharedDomains) -
 * shipped entries disabled. Only the differences are in the database: an added domain (origin local), a shipped one
 * disabled (origin shipped, status 0). A local entry wins over a shipped one of the same domain.
 *
 * A domain is looked for in the text of the files (« \nname\n », 148 KB for 10 000 domains, about 40 µs a search),
 * kept for the process: one reading for a page, one for a whole job of synchronisation.
 */
class InboxDomains
{
	/** Domains a user may ask the managers to mark in one hour (bell of the managers) */
	const REQUESTS_PER_HOUR = 5;

	const PUBLIC = 'public';
	const DISPOSABLE = 'disposable';
	const SHARED = 'shared';
	const CATEGORIES = array(self::PUBLIC, self::DISPOSABLE, self::SHARED);

	/** Files shipped, by category */
	const FILES = array(self::PUBLIC => 'public.txt', self::DISPOSABLE => 'disposable.txt');

	/** Domains pasted at once */
	const MAX_ADD = 200;

	/** @var DoliDB */
	protected $db;

	/** @var string */
	public $error = '';

	/** @var array<string,string> Category per domain, for the pass ('' = none) */
	protected static $cache = array();

	/** @var array<string,string>|null Domains of the other modules */
	protected static $modules = null;

	/** @var array<string,string>|null Text of each shipped file, « \n » before each line, for the process */
	protected static $texts = null;

	/** @var array{local:array<string,string>,off:array<string,string>,ids:array<string,int>}|null Differences in the database */
	protected static $deltas = null;

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct(DoliDB $db)
	{
		$this->db = $db;
	}

	/**
	 * Forget what was read (after a change, between the tests)
	 *
	 * @return void
	 */
	public static function reset()
	{
		self::$cache = array();
		self::$modules = null;
		self::$texts = null;
		self::$deltas = null;
	}

	/**
	 * @return string Folder of the shipped files
	 */
	public static function dataDir()
	{
		return dirname(__DIR__).'/data/domains';
	}

	/**
	 * @return array<string,array<string,mixed>> sources.json (file, source, licence, copied, count, sha256)
	 */
	public static function sources()
	{
		$f = self::dataDir().'/sources.json';
		$data = is_file($f) ? json_decode((string) file_get_contents($f), true) : null;

		return is_array($data) ? $data : array();
	}

	/**
	 * Domains of a shipped file
	 *
	 * @param string $file File name
	 * @return string[]
	 */
	public static function readFile($file)
	{
		$path = self::dataDir().'/'.basename($file);
		$out = array();
		foreach (is_file($path) ? (array) file($path, FILE_IGNORE_NEW_LINES) : array() as $l) {
			$l = strtolower(trim((string) preg_replace('/#.*$/', '', (string) $l)));
			if ($l !== '' && self::valid($l)) {
				$out[$l] = $l;
			}
		}

		return array_values($out);
	}

	/**
	 * @param string $domain Domain
	 * @return bool           A name with a dot, lower case
	 */
	public static function valid($domain)
	{
		return (bool) preg_match('/^(?=.{4,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', (string) $domain);
	}

	/**
	 * Text of the shipped files, read once for the process
	 *
	 * @return array<string,string> Category => « \n » + lines + « \n »
	 */
	protected static function texts()
	{
		if (self::$texts === null) {
			self::$texts = array();
			foreach (self::FILES as $category => $file) {
				$path = self::dataDir().'/'.$file;
				// The files are written normalized by dev/update-domains.php; a CR left by a hand edit is ignored
				self::$texts[$category] = "\n".str_replace("\r", '', is_file($path) ? (string) file_get_contents($path) : '')."\n";
			}
		}

		return self::$texts;
	}

	/**
	 * Category of a domain in the shipped files only
	 *
	 * @param string $domain Domain
	 * @return string         '' when not shipped
	 */
	public static function shippedCategory($domain)
	{
		foreach (self::texts() as $category => $text) {
			if (strpos($text, "\n".$domain."\n") !== false) {
				return $category;
			}
		}

		return '';
	}

	/**
	 * The differences of the database, read once per pass: local entries, shipped ones disabled
	 *
	 * @return array{local:array<string,string>,off:array<string,string>,ids:array<string,int>}
	 */
	protected function deltas()
	{
		global $conf;

		if (self::$deltas === null) {
			self::$deltas = array('local' => array(), 'off' => array(), 'ids' => array());
			$rows = $this->db->getRows("SELECT rowid, domain, category, origin, status FROM ".$this->db->prefix()."inbox_domain WHERE entity = ".((int) $conf->entity)." LIMIT 20000");
			foreach (is_array($rows) ? $rows : array() as $r) {
				if ((string) $r->origin === 'local') {
					self::$deltas['local'][(string) $r->domain] = (string) $r->category;
					self::$deltas['ids'][(string) $r->domain] = (int) $r->rowid;
				} elseif ((int) $r->status === 0) {
					self::$deltas['off'][(string) $r->domain] = (string) $r->category;
				}
			}
		}

		return self::$deltas;
	}

	/**
	 * The hidden setting of before (W5): its domains become local entries, the setting goes. Replayable (P10)
	 *
	 * @return int 1 done, 0 nothing to do
	 */
	public function migrateOldSetting()
	{
		global $conf, $user;

		$old = array_filter(array_map('trim', explode(',', strtolower(getDolGlobalString('INBOX_PUBLIC_DOMAINS')))));
		if (!$old) {
			return 0;
		}
		$this->add(is_object($user) ? $user : null, $old, self::PUBLIC);
		require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
		dolibarr_del_const($this->db, 'INBOX_PUBLIC_DOMAINS', (int) $conf->entity);

		return 1;
	}

	/**
	 * Domains of the other modules (hook inboxSharedDomains, context inbox): results['domains'] = domain => category
	 *
	 * @return array<string,string>
	 */
	public function moduleDomains()
	{
		global $hookmanager;

		if (self::$modules !== null) {
			return self::$modules;
		}
		self::$modules = array();
		if (!is_object($hookmanager)) {
			include_once DOL_DOCUMENT_ROOT.'/core/class/hookmanager.class.php';
			$hookmanager = new HookManager($this->db);
		}
		$hookmanager->initHooks(array('inbox'));
		$parameters = array();
		$object = null;
		$action = '';
		if ($hookmanager->executeHooks('inboxSharedDomains', $parameters, $object, $action) >= 0 && is_array($hookmanager->resArray['domains'] ?? null)) {
			foreach (array_slice($hookmanager->resArray['domains'], 0, 5000, true) as $d => $c) {
				$d = strtolower(trim((string) $d));
				if (self::valid($d) && in_array($c, self::CATEGORIES, true)) {
					self::$modules[$d] = (string) $c;
				}
			}
		}

		return self::$modules;
	}

	/**
	 * Category of the domain of an address (or of a domain): the domain itself, then its parents up to the registrable
	 * one (mail.acme.com is acme.com; co.uk, com.fr... by the list of the public suffixes)
	 *
	 * @param string $emailOrDomain Address or domain
	 * @return string public, disposable, shared, '' when none
	 */
	public function categoryOf($emailOrDomain)
	{
		$v = strtolower(trim((string) $emailOrDomain));
		$at = strrpos($v, '@');
		$domain = $at === false ? $v : substr($v, $at + 1);
		if ($domain === '' || !self::valid($domain)) {
			return '';
		}
		if (isset(self::$cache[$domain])) {
			return self::$cache[$domain];
		}
		$names = array($domain);
		$top = InboxTrustedDomain::registrable($domain);
		$d = $domain;
		while ($top !== '' && $d !== $top && ($pos = strpos($d, '.')) !== false) {
			$d = substr($d, $pos + 1);
			$names[] = $d;
		}
		$deltas = $this->deltas();
		$modules = $this->moduleDomains();
		$found = '';
		foreach ($names as $n) {
			if (isset($deltas['local'][$n])) {
				$found = $deltas['local'][$n];
				break;
			}
			if (isset($deltas['off'][$n])) {
				// A shipped entry disabled: this domain is not shared, its parents are not looked at
				break;
			}
			$found = self::shippedCategory($n) ?: (string) ($modules[$n] ?? '');
			if ($found !== '') {
				break;
			}
		}
		if (count(self::$cache) > 5000) {
			self::$cache = array();
		}
		self::$cache[$domain] = $found;

		return $found;
	}

	/**
	 * Forget what was read in the database (after a change)
	 *
	 * @return void
	 */
	protected function changed()
	{
		self::$cache = array();
		self::$deltas = null;
	}

	/**
	 * Add local entries (several pasted at once); an entry already there takes the category
	 *
	 * @param User|null $user     User
	 * @param string[]  $domains  Domains (or addresses: their domain)
	 * @param string    $category Category
	 * @return array{added:int[],bad:string[]}|null Ids added or changed, refused names; null KO
	 */
	public function add($user, array $domains, $category)
	{
		global $conf;

		if (!in_array($category, self::CATEGORIES, true)) {
			$this->error = 'InboxDomainsBadCategory';
			return null;
		}
		$p = $this->db->prefix();
		$out = array('added' => array(), 'bad' => array());
		foreach (array_slice($domains, 0, self::MAX_ADD) as $d) {
			$d = InboxTrustedDomain::normalize((string) $d);
			if ($d === '' || !self::valid($d) || InboxTrustedDomain::isPublicSuffix($d)) {
				$out['bad'][] = (string) $d;
				continue;
			}
			$old = $this->db->getRow("SELECT rowid FROM ".$p."inbox_domain WHERE entity = ".((int) $conf->entity)." AND domain = '".$this->db->escape($d)."' AND origin = 'local'");
			if (is_object($old)) {
				$this->db->query("UPDATE ".$p."inbox_domain SET category = '".$category."', status = 1 WHERE rowid = ".((int) $old->rowid));
				$out['added'][] = (int) $old->rowid;
				continue;
			}
			$sql = "INSERT INTO ".$p."inbox_domain (entity, domain, category, origin, status, date_creation, fk_user_creat) VALUES (".((int) $conf->entity).", '".$this->db->escape($d)."',";
			$sql .= " '".$category."', 'local', 1, '".$this->db->idate(dol_now())."', ".($user instanceof User ? (int) $user->id : 'NULL').")";
			if (!$this->db->query($sql)) {
				$this->error = $this->db->lasterror();
				return null;
			}
			$out['added'][] = (int) $this->db->last_insert_id($p.'inbox_domain');
		}
		$this->changed();

		return $out;
	}

	/**
	 * Category of a local entry
	 *
	 * @param int    $id       Entry
	 * @param string $category New category
	 * @return string|null      Category before (for the undo), null KO
	 */
	public function setCategory($id, $category)
	{
		global $conf;

		$p = $this->db->prefix();
		$r = $this->db->getRow("SELECT category FROM ".$p."inbox_domain WHERE rowid = ".((int) $id)." AND origin = 'local' AND entity = ".((int) $conf->entity));
		if (!is_object($r) || !in_array($category, self::CATEGORIES, true)) {
			$this->error = is_object($r) ? 'InboxDomainsBadCategory' : 'InboxDomainsNotFound';
			return null;
		}
		$this->db->query("UPDATE ".$p."inbox_domain SET category = '".$this->db->escape($category)."' WHERE rowid = ".((int) $id));
		$this->changed();

		return (string) $r->category;
	}

	/**
	 * Disable or enable shipped entries (a shipped entry is never changed, only disabled)
	 *
	 * @param string[] $domains Shipped domains
	 * @param bool     $on      Enable (true) or disable
	 * @return string[]          Domains really changed (for the undo)
	 */
	public function setShipped(array $domains, $on)
	{
		global $conf;

		$p = $this->db->prefix();
		$done = array();
		foreach (array_slice($domains, 0, 20000) as $d) {
			$d = strtolower(trim((string) $d));
			$category = self::valid($d) ? self::shippedCategory($d) : '';
			if ($category === '') {
				continue;
			}
			$where = " WHERE entity = ".((int) $conf->entity)." AND domain = '".$this->db->escape($d)."' AND origin = 'shipped'";
			if ($on) {
				$this->db->query("DELETE FROM ".$p."inbox_domain".$where);
				if ($this->db->affected_rows(true)) {
					$done[] = $d;
				}
			} elseif (!is_object($this->db->getRow("SELECT rowid FROM ".$p."inbox_domain".$where))) {
				$this->db->query("INSERT INTO ".$p."inbox_domain (entity, domain, category, origin, status, date_creation) VALUES (".((int) $conf->entity).", '".$this->db->escape($d)."',"
					." '".$category."', 'shipped', 0, '".$this->db->idate(dol_now())."')");
				$done[] = $d;
			}
		}
		$this->changed();

		return $done;
	}

	/**
	 * Remove a local entry
	 *
	 * @param int $id Entry
	 * @return array{domain:string,category:string}|null Removed (for the undo), null KO
	 */
	public function delete($id)
	{
		global $conf;

		$p = $this->db->prefix();
		$r = $this->db->getRow("SELECT domain, category FROM ".$p."inbox_domain WHERE rowid = ".((int) $id)." AND origin = 'local' AND entity = ".((int) $conf->entity));
		if (!is_object($r)) {
			$this->error = 'InboxDomainsNotFound';
			return null;
		}
		$this->db->query("DELETE FROM ".$p."inbox_domain WHERE rowid = ".((int) $id));
		$this->changed();

		return array('domain' => (string) $r->domain, 'category' => (string) $r->category);
	}

	/**
	 * « Restore the shipped list »: every shipped entry active again
	 *
	 * @return string[] Domains turned on (for the undo)
	 */
	public function restoreShipped()
	{
		return $this->setShipped(array_keys($this->deltas()['off']), true);
	}

	/**
	 * Users who manage the settings of the webmail (right « Manage the mailboxes »), to tell them a request
	 *
	 * @return int[]
	 */
	/**
	 * Requests of a user to the managers (bell): one per domain a day, REQUESTS_PER_HOUR domains an hour
	 *
	 * @param DoliDB $db     Database handler
	 * @param int    $userId User asking
	 * @param string $domain Domain asked
	 * @return string        '' allowed, 'already' (this domain today), 'hour' (too many this hour)
	 */
	public static function requestLimit($db, $userId, $domain)
	{
		global $conf;

		$p = $db->prefix();
		$where = " WHERE entity = ".((int) $conf->entity)." AND type = 'domain_request' AND fk_user_actor = ".((int) $userId);
		$day = $db->getRow("SELECT COUNT(rowid) AS n FROM ".$p."inbox_notification".$where." AND date_creation > '".$db->idate(dol_now() - 86400)."'"
			." AND payload LIKE '%".$db->escape($db->escapeforlike(substr(json_encode(array('detail' => (string) $domain)), 1, -1)))."%'");
		if (is_object($day) && (int) $day->n > 0) {
			return 'already';
		}
		$rows = $db->getRows("SELECT payload FROM ".$p."inbox_notification".$where." AND date_creation > '".$db->idate(dol_now() - 3600)."' LIMIT 1000");
		$asked = array();
		foreach (is_array($rows) ? $rows : array() as $r) {
			$d = json_decode((string) $r->payload, true);
			$asked[is_array($d) ? (string) ($d['detail'] ?? '') : ''] = true;
		}

		return count($asked) >= self::REQUESTS_PER_HOUR ? 'hour' : '';
	}

	public function managers()
	{
		global $conf;

		$p = $this->db->prefix();
		$e = (int) $conf->entity;
		// The right given in this entity (a user of several entities who manages another one is not told)
		$right = "SELECT id FROM ".$p."rights_def WHERE module = 'inbox' AND perms = 'account' AND subperms = 'admin' AND entity = ".$e;
		$rows = $this->db->getRows("SELECT DISTINCT u.rowid FROM ".$p."user as u WHERE u.statut = 1 AND u.entity IN (0, ".$e.") AND (u.admin = 1"
			." OR u.rowid IN (SELECT fk_user FROM ".$p."user_rights WHERE fk_id IN (".$right.") AND entity = ".$e.")"
			." OR u.rowid IN (SELECT gu.fk_user FROM ".$p."usergroup_user as gu INNER JOIN ".$p."usergroup_rights as gr ON gr.fk_usergroup = gu.fk_usergroup"
			." WHERE gr.fk_id IN (".$right.") AND gr.entity = ".$e." AND gu.entity IN (0, ".$e.")))"
			." LIMIT 50");

		return array_map(function ($r) {
			return (int) $r->rowid;
		}, is_array($rows) ? $rows : array());
	}

	/**
	 * One page of the list for the settings, with the thirdparties and mails using each domain of the page
	 *
	 * @param string $search   Part of the domain
	 * @param string $category Category ('' all)
	 * @param string $origin   shipped, local, off, module ('' all)
	 * @param int    $page     Page (0 first)
	 * @param int    $limit    Lines of a page
	 * @return array{rows:array<int,array<string,mixed>>,total:int,shipped:array<string,mixed>}
	 */
	public function page($search, $category, $origin, $page, $limit = 50)
	{
		$p = $this->db->prefix();
		$deltas = $this->deltas();
		// Every entry, built here only (an admin page): local ones first, then the shipped ones, then the modules
		$all = array();
		foreach ($deltas['local'] as $d => $c) {
			$all[] = array('id' => $deltas['ids'][$d], 'domain' => $d, 'category' => $c, 'origin' => 'local', 'status' => 1);
		}
		$shipped = array();
		foreach (self::FILES as $c => $file) {
			foreach (self::readFile($file) as $d) {
				$shipped[] = array('id' => 0, 'domain' => $d, 'category' => $c, 'origin' => 'shipped', 'status' => isset($deltas['off'][$d]) ? 0 : 1);
			}
		}
		usort($shipped, function ($x, $y) {
			return strcmp($x['domain'], $y['domain']);
		});
		$all = array_merge($all, $shipped);
		foreach ($this->moduleDomains() as $d => $c) {
			$all[] = array('id' => 0, 'domain' => $d, 'category' => $c, 'origin' => 'module', 'status' => 1);
		}
		$search = strtolower(trim((string) $search));
		$all = array_values(array_filter($all, function ($r) use ($search, $category, $origin) {
			if ($search !== '' && strpos($r['domain'], $search) === false) {
				return false;
			}
			if (in_array($category, self::CATEGORIES, true) && $r['category'] !== $category) {
				return false;
			}
			if ($origin === 'off') {
				return $r['status'] === 0;
			}
			return $origin === '' || ($r['origin'] === $origin && $r['status'] === 1);
		}));
		$total = count($all);
		$rows = array_slice($all, (int) $page * (int) $limit, (int) $limit);
		// Thirdparties and mails using each domain of the page, by the database (never the mail server): one grouped
		// query per table, the domain cut from the address
		$names = array_map(function ($r) {
			return "'".$this->db->escape($r['domain'])."'";
		}, $rows);
		$socs = array();
		$mails = array();
		if ($names) {
			$cut = function ($col) {
				return in_array($this->db->type, array('pgsql', 'postgresql'), true) ? "LOWER(SPLIT_PART(".$col.", '@', 2))" : "LOWER(SUBSTRING_INDEX(".$col.", '@', -1))";
			};
			$in = implode(',', $names);
			foreach (array(
				"SELECT ".$cut('s.email')." AS d, COUNT(s.rowid) AS n FROM ".$p."societe as s WHERE s.entity IN (".getEntity('societe').") AND s.status = 1 AND ".$cut('s.email')." IN (".$in.") GROUP BY d LIMIT 100",
				"SELECT ".$cut('c.email')." AS d, COUNT(DISTINCT c.fk_soc) AS n FROM ".$p."socpeople as c WHERE c.entity IN (".getEntity('contact').") AND c.statut = 1 AND c.fk_soc > 0 AND ".$cut('c.email')." IN (".$in.") GROUP BY d LIMIT 100",
			) as $sql) {
				foreach ((array) $this->db->getRows($sql) as $r) {
					$socs[(string) $r->d] = max($socs[(string) $r->d] ?? 0, (int) $r->n);
				}
			}
			foreach ((array) $this->db->getRows("SELECT ".$cut('from_email')." AS d, COUNT(rowid) AS n FROM ".$p."inbox_mail WHERE ".$cut('from_email')." IN (".$in.") GROUP BY d LIMIT 100") as $r) {
				$mails[(string) $r->d] = (int) $r->n;
			}
		}
		foreach ($rows as $i => $r) {
			$rows[$i]['socs'] = $socs[$r['domain']] ?? 0;
			$rows[$i]['mails'] = $mails[$r['domain']] ?? 0;
		}
		$counts = array();
		foreach (self::sources() as $c => $src) {
			$counts[$c] = array('count' => (int) ($src['count'] ?? 0), 'copied' => (string) ($src['copied'] ?? ''), 'source' => (string) ($src['source'] ?? ''), 'licence' => (string) ($src['licence'] ?? ''));
		}

		return array('rows' => $rows, 'total' => $total, 'shipped' => $counts, 'modules' => count($this->moduleDomains()), 'match' => getDolGlobalInt('INBOX_DOMAIN_MATCH', 1));
	}
}
