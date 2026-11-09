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

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct(DoliDB $db)
	{
		$this->db = $db;
	}

	/**
	 * @param string $type Type
	 * @return bool A known type whose module is enabled
	 */
	public static function enabled($type)
	{
		return isset(self::TYPES[$type]) && isModEnabled(self::TYPES[$type]['module']);
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
		foreach (self::TYPES[$type]['rights'] as $r) {
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
		if (!self::enabled($type) || !isset(self::WRITE[$type])) {
			return false;
		}
		foreach (self::WRITE[$type] as $r) {
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
		$a = self::TYPES[$type]['access'];

		return checkUserAccessToObject($user, array($a[0]), (int) $id, $a[1], $a[2] ?? '', $a[3] ?? '', $a[4] ?? 'rowid');
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

		$icon = self::TYPES[$type]['icon'] ?? 'link';
		$kind = isset(self::TYPES[$type]) ? $langs->transnoentities(self::TYPES[$type]['label']) : $type;
		if (!self::canRead($user, $type, $id)) {
			return array('type' => $type, 'id' => (int) $id, 'readable' => false, 'icon' => $icon, 'kind' => $kind);
		}
		$o = fetchObjectByElement((int) $id, $type, '', 1, 50);
		if (!is_object($o) || empty($o->id)) {
			return null;
		}
		$url = '';
		$html = method_exists($o, 'getNomUrl') ? (string) $o->getNomUrl(0) : '';
		if (preg_match('/href="([^"]+)"/', $html, $m)) {
			$url = html_entity_decode($m[1], ENT_QUOTES);
		}
		if ($type === 'societe') {
			$ref = (string) $o->name;
			$label = (string) ($o->name_alias ?? '');
		} elseif ($type === 'contact') {
			$ref = trim((string) $o->getFullName($langs));
			$label = '';
			if ((int) $o->socid > 0) {
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
				$title = (string) ($o->title ?? ($o->subject ?? ''));
				$label = trim($title.($label !== '' ? ' · '.$label : ''));
			}
		}
		$out = array('type' => $type, 'id' => (int) $o->id, 'readable' => true, 'ref' => $ref, 'label' => $label, 'status' => '', 'url' => $url, 'icon' => $icon, 'kind' => $kind);
		if (method_exists($o, 'getLibStatut')) {
			$out = array_merge($out, self::statusOf((string) $o->getLibStatut(0), (string) $o->getLibStatut(5)));
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
		foreach (self::TYPES as $type => $t) {
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
			} else {
				$where[] = "t.".$t['soc']." = ".((int) $socId);
			}
			$near = $socId > 0 ? "CASE WHEN t.".$t['soc']." = ".((int) $socId)." THEN 1 ELSE 0 END" : "0";
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
		if (!isset(self::TYPES[$type])) {
			return 0;
		}
		$t = self::TYPES[$type];
		$row = $this->db->getRow("SELECT ".$t['soc']." as soc FROM ".$this->db->prefix().$t['table']." WHERE ".($t['key'] ?? 'rowid')." = ".((int) $id));

		return is_object($row) ? (int) $row->soc : 0;
	}
}
