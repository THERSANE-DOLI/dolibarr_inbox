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
 * \file    inbox/class/inboxelements.class.php
 * \ingroup inbox
 * \brief   Pieces of Dolibarr a mail can be linked to (lot W5 1.13.0): rights, description, search
 */

require_once DOL_DOCUMENT_ROOT.'/core/lib/security.lib.php';

/**
 * A piece is shown or linked only when its module is enabled, the user has the right to read it and the access to
 * this very record (restriction to the thirdparties of a sales representative, entity). Otherwise « not accessible ».
 */
class InboxElements
{
	/**
	 * Types: module (isModEnabled), rights (any of them), access (features, tableandshare, feature2, keyfield, select)
	 * of checkUserAccessToObject, table and fields searched, field of the thirdparty, key of getEntity, icon of inbox
	 */
	const TYPES = array(
		'societe' => array('module' => 'societe', 'rights' => array(array('societe', 'lire')), 'access' => array('societe', '&societe'), 'table' => 'societe',
			'search' => array('nom', 'name_alias', 'email', 'code_client', 'code_fournisseur'), 'soc' => 'rowid', 'entity' => 'societe', 'icon' => 'company', 'label' => 'ThirdParty'),
		'contact' => array('module' => 'societe', 'rights' => array(array('societe', 'contact', 'lire')), 'access' => array('contact', 'socpeople&societe'), 'table' => 'socpeople',
			'search' => array('lastname', 'firstname', 'email'), 'soc' => 'fk_soc', 'entity' => 'contact', 'icon' => 'contact-card', 'label' => 'Contact'),
		'propal' => array('module' => 'propal', 'rights' => array(array('propal', 'lire')), 'access' => array('propal', ''), 'table' => 'propal',
			'search' => array('ref', 'ref_client'), 'soc' => 'fk_soc', 'entity' => 'propal', 'icon' => 'quote', 'label' => 'Proposal'),
		'commande' => array('module' => 'commande', 'rights' => array(array('commande', 'lire')), 'access' => array('commande', ''), 'table' => 'commande',
			'search' => array('ref', 'ref_client'), 'soc' => 'fk_soc', 'entity' => 'commande', 'icon' => 'order', 'label' => 'Order'),
		'facture' => array('module' => 'facture', 'rights' => array(array('facture', 'lire')), 'access' => array('facture', ''), 'table' => 'facture',
			'search' => array('ref', 'ref_client'), 'soc' => 'fk_soc', 'entity' => 'invoice', 'icon' => 'piece', 'label' => 'Invoice'),
		'order_supplier' => array('module' => 'supplier_order', 'rights' => array(array('fournisseur', 'commande', 'lire'), array('supplier_order', 'lire')),
			'access' => array('fournisseur', 'commande_fournisseur', 'commande'), 'table' => 'commande_fournisseur', 'search' => array('ref', 'ref_supplier'), 'soc' => 'fk_soc',
			'entity' => 'supplier_order', 'icon' => 'order', 'label' => 'SupplierOrder'),
		'invoice_supplier' => array('module' => 'supplier_invoice', 'rights' => array(array('fournisseur', 'facture', 'lire'), array('supplier_invoice', 'lire')),
			'access' => array('fournisseur', 'facture_fourn', 'facture'), 'table' => 'facture_fourn', 'search' => array('ref', 'ref_supplier'), 'soc' => 'fk_soc',
			'entity' => 'supplier_invoice', 'icon' => 'piece', 'label' => 'SupplierInvoice'),
		'supplier_proposal' => array('module' => 'supplier_proposal', 'rights' => array(array('supplier_proposal', 'lire')), 'access' => array('supplier_proposal', ''),
			'table' => 'supplier_proposal', 'search' => array('ref'), 'soc' => 'fk_soc', 'entity' => 'supplier_proposal', 'icon' => 'quote', 'label' => 'SupplierProposal'),
		'project' => array('module' => 'project', 'rights' => array(array('projet', 'lire')), 'access' => array('projet', 'projet&project'), 'table' => 'projet',
			'search' => array('ref', 'title'), 'soc' => 'fk_soc', 'entity' => 'project', 'icon' => 'briefcase', 'label' => 'Project'),
		'ticket' => array('module' => 'ticket', 'rights' => array(array('ticket', 'read')), 'access' => array('ticket', ''), 'table' => 'ticket',
			'search' => array('ref', 'subject'), 'soc' => 'fk_soc', 'entity' => 'ticket', 'icon' => 'tasks', 'label' => 'Ticket'),
		'fichinter' => array('module' => 'ficheinter', 'rights' => array(array('ficheinter', 'lire')), 'access' => array('ficheinter', 'fichinter'), 'table' => 'fichinter',
			'search' => array('ref'), 'soc' => 'fk_soc', 'entity' => 'intervention', 'icon' => 'tool', 'label' => 'Intervention'),
		'shipping' => array('module' => 'expedition', 'rights' => array(array('expedition', 'lire')), 'access' => array('expedition', 'expedition'), 'table' => 'expedition',
			'search' => array('ref', 'ref_customer'), 'soc' => 'fk_soc', 'entity' => 'expedition', 'icon' => 'truck', 'label' => 'Shipment'),
		'action' => array('module' => 'agenda', 'rights' => array(array('agenda', 'myactions', 'read')), 'access' => array('agenda', 'actioncomm&societe', 'myactions|allactions', 'fk_soc', 'id'),
			'table' => 'actioncomm', 'key' => 'id', 'search' => array('label'), 'soc' => 'fk_soc', 'entity' => 'agenda', 'icon' => 'agenda', 'label' => 'Event'),
	);

	/** Rights to change a piece (its documents): any of them (lot W5 1.14.0) */
	const WRITE = array(
		'societe' => array(array('societe', 'creer')),
		'contact' => array(array('societe', 'contact', 'creer')),
		'propal' => array(array('propal', 'creer')),
		'commande' => array(array('commande', 'creer')),
		'facture' => array(array('facture', 'creer')),
		'order_supplier' => array(array('fournisseur', 'commande', 'creer'), array('supplier_order', 'creer')),
		'invoice_supplier' => array(array('fournisseur', 'facture', 'creer'), array('supplier_invoice', 'creer')),
		'supplier_proposal' => array(array('supplier_proposal', 'creer')),
		'project' => array(array('projet', 'creer')),
		'ticket' => array(array('ticket', 'write')),
		'fichinter' => array(array('ficheinter', 'creer')),
		'shipping' => array(array('expedition', 'creer')),
		'action' => array(array('agenda', 'myactions', 'create')),
	);

	/** @var DoliDB */
	protected $db;

	/** @var array<string,array<string,mixed>>|null Types of the core and of the modules, read once per request */
	protected static $registry = null;

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct(DoliDB $db)
	{
		$this->db = $db;
	}

	/**
	 * Types of pieces: the ones of the core, then the objects the modules declare (hook inboxElementTypes, 1.164.0)
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function types()
	{
		if (self::$registry === null) {
			self::$registry = self::TYPES;
			global $db, $hookmanager;
			if (is_object($db)) {
				if (!is_object($hookmanager)) {
					include_once DOL_DOCUMENT_ROOT.'/core/class/hookmanager.class.php';
					$hookmanager = new HookManager($db);
				}
				$hookmanager->initHooks(array('inbox'));
				$parameters = array();
				$object = null;
				$action = '';
				if ($hookmanager->executeHooks('inboxElementTypes', $parameters, $object, $action) >= 0 && is_array($hookmanager->resArray['types'] ?? null)) {
					foreach (self::normalizeTypes($hookmanager->resArray['types']) as $t => $def) {
						if (!isset(self::$registry[$t])) {
							self::$registry[$t] = $def;
						}
					}
				}
			}
		}

		return self::$registry;
	}

	/**
	 * The registry read again (tests, a module enabled in the request)
	 *
	 * @param array<string,array<string,mixed>>|null $types Types to use, null to read the hook again
	 * @return void
	 */
	public static function reset($types = null)
	{
		self::$registry = $types;
	}

	/**
	 * Types of pieces declared by modules, checked: names and fields of the database by strict patterns, rights as
	 * lists of words, addresses of this site; the type is « source_name », never one of the core
	 *
	 * @param array<int|string,mixed> $declared Declared
	 * @return array<string,array<string,mixed>>
	 */
	public static function normalizeTypes(array $declared)
	{
		$word = '/^[a-z0-9_]{1,40}$/';
		$rights = function ($list) {
			$out = array();
			foreach (is_array($list) ? $list : array() as $r) {
				if (!is_array($r) || count($r) < 2 || count($r) > 3) {
					return null;
				}
				$r = array_map('strval', array_values($r));
				foreach ($r as $w) {
					if (!preg_match('/^[a-z0-9_]{1,40}$/', $w)) {
						return null;
					}
				}
				$out[] = $r;
			}
			return $out;
		};
		$out = array();
		foreach ($declared as $d) {
			if (!is_array($d)) {
				continue;
			}
			$source = (string) ($d['source'] ?? '');
			$name = (string) ($d['name'] ?? '');
			if (!preg_match('/^[a-z0-9_]{1,30}$/', $source) || !preg_match('/^[a-z0-9_]{1,30}$/', $name)) {
				continue;
			}
			$type = $source.'_'.$name;
			$read = $rights($d['rights'] ?? null);
			$write = $rights($d['write'] ?? array());
			$access = is_array($d['access'] ?? null) ? array_values(array_map('strval', array_slice($d['access'], 0, 5))) : array();
			$search = is_array($d['search'] ?? null) ? array_values(array_map('strval', $d['search'])) : array();
			$table = (string) ($d['table'] ?? '');
			$soc = (string) ($d['soc'] ?? '');
			$key = (string) ($d['key'] ?? 'rowid');
			$element = (string) ($d['element'] ?? '');
			if (isset(self::TYPES[$type]) || !$read || $write === null || !$access || !preg_match('/^[a-z0-9_]{1,40}$/', $access[0])
				|| count(array_filter($access, function ($a) {
					return !preg_match('/^[a-zA-Z0-9_&|@]{0,80}$/', $a);
				})) || !preg_match('/^[a-z0-9_]{1,64}$/', $table) || !preg_match($word, $key) || ($soc !== '' && !preg_match($word, $soc))
				|| !$search || count(array_filter($search, function ($f) use ($word) {
					return !preg_match($word, $f);
				})) || !preg_match('/^[a-z0-9_]{1,40}(@[a-z0-9_]{1,40})?$/', $element) || !preg_match($word, (string) ($d['module'] ?? ''))
				|| empty($d['label'])) {
				continue;
			}
			$def = array('module' => (string) $d['module'], 'rights' => $read, 'write' => $write, 'access' => $access, 'table' => $table, 'key' => $key,
				'search' => $search, 'soc' => $soc, 'entity' => preg_match($word, (string) ($d['entity'] ?? '')) ? (string) $d['entity'] : $name,
				'icon' => preg_match('/^[a-z0-9-]{1,40}$/', (string) ($d['icon'] ?? '')) ? (string) $d['icon'] : 'document',
				'label' => dol_trunc(trim(dol_string_nohtmltag((string) $d['label'])), 60, 'right', 'UTF-8', 1), 'element' => $element, 'source' => $source);
			// Page of creation and triggers: the piece created there linked to its mail, its links forgotten when deleted
			$create = is_array($d['create'] ?? null) ? $d['create'] : array();
			$url = (string) ($create['url'] ?? '');
			$trigger = (string) ($create['trigger'] ?? '');
			require_once __DIR__.'/inboxcreate.class.php';
			if (preg_match('/^[A-Z0-9_]{1,60}$/', $trigger) && InboxCreate::localUrl($url)) {
				$def['create'] = array('url' => $url, 'trigger' => $trigger);
			}
			if (preg_match('/^[A-Z0-9_]{1,60}$/', (string) ($d['delete'] ?? ''))) {
				$def['delete'] = (string) $d['delete'];
			}
			$out[$type] = $def;
		}

		return $out;
	}

	/**
	 * A piece loaded, of the core or of a module (its element for Dolibarr)
	 *
	 * @param int    $id   Record
	 * @param string $type Type
	 * @return object|null
	 */
	public static function fetch($id, $type)
	{
		$element = self::types()[$type]['element'] ?? $type;
		$o = fetchObjectByElement((int) $id, $element, '', 1, 50);

		return is_object($o) && !empty($o->id) ? $o : null;
	}

	/**
	 * Rights to change a piece of this type (core or module), any of them
	 *
	 * @param string $type Type
	 * @return array<int,array<int,string>>|null
	 */
	public static function writeRights($type)
	{
		return self::WRITE[$type] ?? (self::types()[$type]['write'] ?? null);
	}

	/**
	 * @param string $type Type
	 * @return bool A known type whose module is enabled
	 */
	public static function enabled($type)
	{
		$types = self::types();

		return isset($types[$type]) && isModEnabled($types[$type]['module']);
	}

	/**
	 * The user may read pieces of this type (right of the module), whatever the record
	 *
	 * @param User   $user User
	 * @param string $type Type
	 * @return bool
	 */
	public static function canReadType(User $user, $type)
	{
		if (!self::enabled($type)) {
			return false;
		}
		foreach (self::types()[$type]['rights'] as $r) {
			if (count($r) === 3 ? $user->hasRight($r[0], $r[1], $r[2]) : $user->hasRight($r[0], $r[1])) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The user may change pieces of this type (create one, add documents to it)
	 *
	 * @param User   $user User
	 * @param string $type Type
	 * @return bool
	 */
	public static function canWriteType(User $user, $type)
	{
		$write = self::writeRights($type);
		if (!self::enabled($type) || !$write) {
			return false;
		}
		foreach ($write as $r) {
			if (count($r) === 3 ? $user->hasRight($r[0], $r[1], $r[2]) : $user->hasRight($r[0], $r[1])) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The user may read this record
	 *
	 * @param User   $user User
	 * @param string $type Type
	 * @param int    $id   Record
	 * @return bool
	 */
	public static function canRead(User $user, $type, $id)
	{
		if ((int) $id <= 0 || !self::canReadType($user, $type)) {
			return false;
		}
		$a = self::types()[$type]['access'];

		return checkUserAccessToObject($user, array($a[0]), (int) $id, $a[1], $a[2] ?? '', $a[3] ?? '', $a[4] ?? 'rowid');
	}

	/**
	 * Colleagues of an event (its owner first, then the users invited, with their answer) or of a ticket (the user
	 * assigned), as cards of users of Dolibarr
	 *
	 * @param User   $user User reading (the piece is readable)
	 * @param string $type action or ticket
	 * @param object $o    Piece loaded
	 * @return list<array<string,mixed>> Cards with role (owner, attendee, assigned) and answer (accepted, tentative,
	 *                                   declined, or '' when not answered)
	 */
	protected function peopleOf(User $user, $type, $o)
	{
		require_once __DIR__.'/inboxreader.class.php';
		$roles = array();
		$answers = array();
		if ($type === 'ticket') {
			if ((int) ($o->fk_user_assign ?? 0) > 0) {
				$roles[(int) $o->fk_user_assign] = 'assigned';
			}
		} else {
			if ((int) ($o->userownerid ?? 0) > 0) {
				$roles[(int) $o->userownerid] = 'owner';
			}
			$rows = $this->db->getRows("SELECT fk_element, answer_status FROM ".$this->db->prefix()."actioncomm_resources WHERE fk_actioncomm = ".((int) $o->id)
				." AND element_type = 'user' ORDER BY rowid LIMIT 50");
			$names = array(1 => 'accepted', 2 => 'tentative', -1 => 'declined');
			foreach (is_array($rows) ? $rows : array() as $r) {
				$uid = (int) $r->fk_element;
				if (!isset($roles[$uid])) {
					$roles[$uid] = 'attendee';
				}
				$answers[$uid] = $names[(int) $r->answer_status] ?? '';
			}
		}
		$out = array();
		foreach ((new InboxReader($this->db))->peopleByIds($user, array_keys($roles)) as $p) {
			$p['role'] = $roles[$p['id']];
			$p['answer'] = $answers[$p['id']] ?? '';
			$out[] = $p;
		}

		return $out;
	}

	/**
	 * What the bandeau and the cards show of a piece; readable = false gives nothing but the type
	 *
	 * @param User   $user User
	 * @param string $type Type
	 * @param int    $id   Record
	 * @return array{type:string,id:int,readable:bool,ref?:string,label?:string,status?:string,url?:string,icon:string,kind:string}|null Null when the record does not exist any more
	 */
	public function describe(User $user, $type, $id)
	{
		global $langs;

		$types = self::types();
		$icon = $types[$type]['icon'] ?? 'link';
		$kind = isset($types[$type]) ? $langs->transnoentities($types[$type]['label']) : $type;
		if (!self::canRead($user, $type, $id)) {
			return array('type' => $type, 'id' => (int) $id, 'readable' => false, 'icon' => $icon, 'kind' => $kind);
		}
		$o = self::fetch((int) $id, $type);
		if (!$o) {
			return null;
		}
		// Tooltip of Dolibarr, built by the application: the parameters of the ajax tooltip of getNomUrl (data-params, read
		// by core/ajax/ajaxtooltip.php with its own checks and hooks), else its content when the ajax tooltip is off
		$url = '';
		$tipParams = null;
		$tipHtml = '';
		$html = method_exists($o, 'getNomUrl') ? (string) $o->getNomUrl(0) : '';
		if (preg_match('/href="([^"]+)"/', $html, $m)) {
			$url = html_entity_decode($m[1], ENT_QUOTES);
		}
		if (preg_match('/data-params="([^"]+)"/', $html, $m)) {
			$tipParams = json_decode(html_entity_decode($m[1], ENT_QUOTES), true);
			$tipParams = is_array($tipParams) ? $tipParams : null;
		} elseif (preg_match('/\bclassfortooltip\b/', $html) && preg_match('/title="([^"]*)"/', $html, $m)) {
			$tipHtml = html_entity_decode($m[1], ENT_QUOTES);
		}
		// Thirdparty of the piece apart (socid) and title of a project or ticket: the application leaves the thirdparty out
		// when it is the one of the sender (label kept whole for the other uses)
		$socId = 0;
		$title = '';
		if ($type === 'societe') {
			$ref = (string) $o->name;
			$label = (string) ($o->name_alias ?? '');
		} elseif ($type === 'contact') {
			$ref = trim((string) $o->getFullName($langs));
			$label = '';
			if ((int) $o->socid > 0) {
				$socId = (int) $o->socid;
				$soc = fetchObjectByElement((int) $o->socid, 'societe', '', 1, 50);
				$label = is_object($soc) ? (string) $soc->name : '';
			}
		} else {
			$ref = (string) ($o->ref ?? '');
			$label = '';
			$socId = (int) ($o->socid ?? ($o->fk_soc ?? 0));
			if ($socId > 0) {
				$soc = fetchObjectByElement($socId, 'societe', '', 1, 50);
				$label = is_object($soc) ? (string) $soc->name : '';
			}
			if ($type === 'project' || $type === 'ticket') {
				$title = trim((string) ($o->title ?? ($o->subject ?? '')));
				$label = trim($title.($label !== '' ? ' · '.$label : ''));
			} elseif ($type === 'action') {
				// An event has no reference (its ref is its id): its subject, then its date
				$ref = dol_trunc(trim((string) ($o->label ?? '')), 80, 'right', 'UTF-8', 1);
				if ($ref === '') {
					$ref = $kind.' '.((int) $o->id);
				}
				$title = empty($o->datep) ? '' : dol_print_date($o->datep, empty($o->fulldayevent) ? 'dayhour' : 'day', 'tzuserrel');
				$label = trim($title.($label !== '' ? ' · '.$label : ''));
			}
		}
		$out = array('type' => $type, 'id' => (int) $o->id, 'readable' => true, 'ref' => $ref, 'label' => $label, 'socid' => $socId, 'title' => $title, 'status' => '', 'url' => $url, 'tip_params' => $tipParams, 'tip_html' => $tipHtml, 'icon' => $icon, 'kind' => $kind);
		if (method_exists($o, 'getLibStatut')) {
			$out = array_merge($out, self::statusOf((string) $o->getLibStatut(0), (string) $o->getLibStatut(5)));
		}
		// Colleagues of the piece (1.158.0): the owner and the users invited to an event, the user assigned to a ticket
		if ($type === 'action' || $type === 'ticket') {
			$out['people'] = $this->peopleOf($user, $type, $o);
		}
		// Expected status of a sender (open thirdparty, active contact): the application shows a discreet dot, the badge
		// only for another status (closed, inactive)
		if ($type === 'societe' || $type === 'contact') {
			$raw = $type === 'contact' ? ($o->statut ?? ($o->status ?? null)) : ($o->status ?? null);
			$out['status_normal'] = $raw !== null && (int) $raw === 1;
		}

		return $out;
	}

	/**
	 * Status of a piece as Dolibarr shows it: the long label (title), the short one (badge) and the kind of badge
	 * (status0 to status9, read in the html of getLibStatut(5): badge-status4, or status4 of the picto)
	 *
	 * @param string $long  getLibStatut(0)
	 * @param string $short getLibStatut(5)
	 * @return array{status:string,status_short:string,status_type:int} status_type -1 when unknown
	 */
	public static function statusOf($long, $short)
	{
		$type = -1;
		$m = array();
		if (preg_match('/\b(?:badge-)?status(\d)b?\b/', $short, $m)) {
			$type = (int) $m[1];
		}
		$longText = trim(dol_string_nohtmltag($long));
		$shortText = trim(dol_string_nohtmltag($short));
		if ($shortText === '' && preg_match('/title="([^"]*)"/', $short, $m)) {
			// Picto alone: its title is the label
			$shortText = trim(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));
		}

		return array('status' => $longText !== '' ? $longText : $shortText, 'status_short' => $shortText !== '' ? $shortText : $longText, 'status_type' => $type);
	}

	/** Types left out of the search unless asked (« Extend to »): the events, by far the biggest table, searched on a free label */
	const SEARCH_ON_DEMAND = array('action');

	/** Words shorter than this are searched at the start of the fields only (no « contains »: no index) */
	const CONTAINS_FROM = 3;

	/**
	 * Pieces to link (1.46.0): the reference found exactly first, then the pieces of the thirdparty recognized, then the
	 * references starting with the words, then the other fields containing them; only the readable.
	 * The references are searched by their start (index), the other fields (names, references of the customer or of the
	 * supplier) contain the words from 3 characters; the events only when asked (SEARCH_ON_DEMAND).
	 * Only the pieces kept are read in full (describe).
	 *
	 * @param User          $user  User
	 * @param string        $q     Words
	 * @param int           $socId Thirdparty recognized (its pieces first, or alone when there is no word)
	 * @param int           $limit Results
	 * @param string[]|null $types Types searched; null = all but the ones on demand
	 * @return array<int,array<string,mixed>>
	 */
	public function search(User $user, $q, $socId = 0, $limit = 20, $types = null)
	{
		$q = trim((string) $q);
		if ($q === '' && $socId <= 0) {
			return array();
		}
		$p = $this->db->prefix();
		$like = $this->db->escape($this->db->escapeforlike($q));
		$exactValue = $this->db->escape($q);
		$contains = dol_strlen($q) >= self::CONTAINS_FROM;
		$found = array();
		$order = 0;
		foreach (self::types() as $type => $t) {
			$order++;
			if ($types === null ? in_array($type, self::SEARCH_ON_DEMAND, true) : !in_array($type, $types, true)) {
				continue;
			}
			if (!self::canReadType($user, $type)) {
				continue;
			}
			$key = $t['key'] ?? 'rowid';
			$main = $t['search'][0];
			$where = array("t.entity IN (".getEntity($t['entity']).")");
			$exact = "0";
			if ($q !== '') {
				$or = array();
				foreach ($t['search'] as $f) {
					// A reference by its start (index); a name, a reference of the other side may hold the words anywhere
					$or[] = "t.".$f." LIKE '".($f === 'ref' || !$contains ? '' : '%').$like."%'";
				}
				$where[] = "(".implode(' OR ', $or).")";
				$exact = "CASE WHEN t.".$main." = '".$exactValue."' THEN 1 ELSE 0 END";
			} elseif ($t['soc'] !== '') {
				$where[] = "t.".$t['soc']." = ".((int) $socId);
			} else {
				// A type without thirdparty: nothing to show without words
				continue;
			}
			$near = $socId > 0 && $t['soc'] !== '' ? "CASE WHEN t.".$t['soc']." = ".((int) $socId)." THEN 1 ELSE 0 END" : "0";
			$starts = $q !== '' ? "CASE WHEN t.".$main." LIKE '".$like."%' THEN 1 ELSE 0 END" : "0";
			$rows = $this->db->getRows("SELECT t.".$key." as rowid, ".$exact." as exact, ".$near." as near, ".$starts." as starts FROM ".$p.$t['table']." as t"
				." WHERE ".implode(' AND ', $where)." ORDER BY exact DESC, near DESC, starts DESC, t.".$key." DESC LIMIT 8");
			foreach (is_array($rows) ? $rows : array() as $r) {
				$found[] = array('type' => $type, 'id' => (int) $r->rowid, 'score' => 4 * (int) $r->exact + 2 * (int) $r->near + (int) $r->starts, 'order' => $order, 'near' => (bool) $r->near);
			}
		}
		// Best first; same score: the order of the types, then the newest
		usort($found, function ($a, $b) {
			return array($b['score'], $a['order'], $b['id']) <=> array($a['score'], $b['order'], $a['id']);
		});
		$out = array();
		foreach ($found as $f) {
			if (count($out) >= max(1, (int) $limit)) {
				break;
			}
			$d = $this->describe($user, $f['type'], $f['id']);
			if ($d && $d['readable']) {
				$d['near'] = $f['near'];
				$d['exact'] = $f['score'] >= 4;
				$out[] = $d;
			}
		}

		return $out;
	}

	/**
	 * @param string $type Type
	 * @param int    $id   Record
	 * @return int Thirdparty of the record (itself for a thirdparty)
	 */
	public function socOf($type, $id)
	{
		$t = self::types()[$type] ?? null;
		if (!$t || $t['soc'] === '') {
			return 0;
		}
		$row = $this->db->getRow("SELECT ".$t['soc']." as soc FROM ".$this->db->prefix().$t['table']." WHERE ".($t['key'] ?? 'rowid')." = ".((int) $id));

		return is_object($row) ? (int) $row->soc : 0;
	}
}
