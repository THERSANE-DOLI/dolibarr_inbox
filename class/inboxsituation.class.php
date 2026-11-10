<?php
/* Copyright (C) 2026	John BOTELLA
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
 * along with this program.  If not, see https://www.gnu.org/licenses/.
 */

/**
 * \file    inbox/class/inboxsituation.class.php
 * \ingroup inbox
 * \brief   Usage of a mailbox and situation of the thirdparty of a mail (1.67.0, analyses/analyse-usage-boite.md)
 */

require_once __DIR__.'/inboxelements.class.php';
require_once __DIR__.'/inboxaccountlog.class.php';

/**
 * What the card of the thirdparty of a mail shows, by the usage of the mailbox: a list of indicators, each with a level
 * (alert, attention, normal); the closed card shows only what is not normal. An indicator is computed only with the
 * right of Dolibarr on its type and its module enabled; amounts in the main currency.
 */
class InboxSituation
{
	/** Usages of a mailbox and their indicators by default (auto: by the kind of the thirdparty, none: nothing) */
	const USAGES = array(
		'auto' => array(),
		'sales' => array('inv_late', 'outstanding', 'propal_open', 'order_open'),
		'accounting' => array('inv_late', 'outstanding', 'next_due'),
		'support' => array('ticket_open', 'inter_open'),
		'purchase' => array('sorder_open', 'sinv_topay', 'reception_wait'),
		'transport' => array('order_toship', 'ship_open', 'reception_wait'),
		'none' => array(),
	);

	/** Indicators: type of piece whose right is needed (InboxElements::TYPES), key of the label */
	const INDICATORS = array(
		'inv_late' => array('type' => 'facture', 'label' => 'InboxIndInvLate'),
		'outstanding' => array('type' => 'facture', 'label' => 'InboxIndOutstanding'),
		'next_due' => array('type' => 'facture', 'label' => 'InboxIndNextDue'),
		'propal_open' => array('type' => 'propal', 'label' => 'InboxIndPropalOpen'),
		'order_open' => array('type' => 'commande', 'label' => 'InboxIndOrderOpen'),
		'ticket_open' => array('type' => 'ticket', 'label' => 'InboxIndTicketOpen'),
		'inter_open' => array('type' => 'fichinter', 'label' => 'InboxIndInterOpen'),
		'sorder_open' => array('type' => 'order_supplier', 'label' => 'InboxIndSorderOpen'),
		'sinv_topay' => array('type' => 'invoice_supplier', 'label' => 'InboxIndSinvToPay'),
		'order_toship' => array('type' => 'commande', 'label' => 'InboxIndOrderToShip'),
		'ship_open' => array('type' => 'shipping', 'label' => 'InboxIndShipOpen'),
		'reception_wait' => array('type' => 'order_supplier', 'label' => 'InboxIndReceptionWait'),
	);

	/** Days before a customer due date that make it worth a look */
	const DUE_SOON_DAYS = 7;

	/** @var DoliDB */
	protected $db;

	/** @var string */
	public $error = '';

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct(DoliDB $db)
	{
		$this->db = $db;
	}

	/**
	 * @param string $usage Usage
	 * @return bool A known usage
	 */
	public static function isUsage($usage)
	{
		return isset(self::USAGES[(string) $usage]);
	}

	/**
	 * Indicators of a mailbox for a thirdparty: the own list of the mailbox, else the one of its usage; « auto »: the
	 * list Sales for a customer or a prospect, Purchase for a supplier, both for both
	 *
	 * @param InboxAccount $account  Mailbox
	 * @param int          $client   Field client of the thirdparty (1 customer, 2 prospect, 3 both)
	 * @param int          $supplier Field fournisseur of the thirdparty
	 * @return array{usage:string,shown:string[],ids:string[]} shown = usages whose list is used (auto: sales and/or purchase)
	 */
	public static function listFor(InboxAccount $account, $client, $supplier)
	{
		$usage = self::isUsage($account->usage_profile) ? (string) $account->usage_profile : 'auto';
		if ($usage === 'auto') {
			$shown = array();
			$ids = array();
			if ((int) $client > 0) {
				$shown[] = 'sales';
				$ids = self::USAGES['sales'];
			}
			if ((int) $supplier > 0) {
				$shown[] = 'purchase';
				$ids = array_values(array_unique(array_merge($ids, self::USAGES['purchase'])));
			}
			return array('usage' => $usage, 'shown' => $shown, 'ids' => $ids);
		}
		$own = json_decode((string) $account->usage_indicators, true);
		$ids = is_array($own) ? array_values(array_intersect($own, array_keys(self::INDICATORS))) : self::USAGES[$usage];

		return array('usage' => $usage, 'shown' => array($usage), 'ids' => $ids);
	}

	/**
	 * Change the usage of a mailbox (the managers of the mailbox, checked by the caller), written in its journal; the
	 * own list of indicators goes back to the one of the usage
	 *
	 * @param User         $user    User doing it
	 * @param InboxAccount $account Mailbox
	 * @param string       $usage   New usage
	 * @return int 1 OK, 0 unchanged, < 0 KO
	 */
	public function setUsage(User $user, InboxAccount $account, $usage)
	{
		$usage = (string) $usage;
		if (!self::isUsage($usage)) {
			$this->error = 'Bad usage';
			return -1;
		}
		$before = (string) ($account->usage_profile ?: 'auto');
		if ($before === $usage && $account->usage_indicators === null) {
			return 0;
		}
		if (!$this->db->query("UPDATE ".$this->db->prefix()."inbox_account SET usage_profile = '".$this->db->escape($usage)."', usage_indicators = NULL WHERE rowid = ".((int) $account->id))) {
			$this->error = $this->db->lasterror();
			return -2;
		}
		$account->usage_profile = $usage;
		$account->usage_indicators = null;
		$log = new InboxAccountLog($this->db);
		$log->add((int) $account->id, $user, 'usage', array('usage' => $before), array('usage' => $usage));

		return 1;
	}

	/**
	 * Situation of a thirdparty for the indicators asked: the ones the user may read, in this order
	 *
	 * @param User     $user  User reading (rights of Dolibarr)
	 * @param int      $socId Thirdparty (readable by the user: checked by the caller)
	 * @param string[] $ids   Indicators
	 * @return array<int,array{id:string,label:string,count:int,amount:float|null,value:string,level:string,detail:string,url:string}>
	 */
	public function compute(User $user, $socId, array $ids)
	{
		global $langs;

		$socId = (int) $socId;
		$out = array();
		if ($socId <= 0) {
			return $out;
		}
		$langs->loadLangs(array('inbox@inbox', 'bills', 'companies'));
		foreach ($ids as $id) {
			if (!isset(self::INDICATORS[$id]) || !InboxElements::canReadType($user, self::INDICATORS[$id]['type'])) {
				continue;
			}
			$r = $this->indicator($id, $socId);
			if ($r === null) {
				continue;
			}
			$r = array_merge(array('id' => $id, 'label' => $langs->transnoentities(self::INDICATORS[$id]['label']), 'amount' => null, 'detail' => ''), $r);
			$r['short'] = $r['level'] === 'normal' ? '' : $this->short($id, $r);
			$out[] = $r;
		}

		return $out;
	}

	/**
	 * Short text of a signal for the closed card: « 2 late invoices · 3 400 € »
	 *
	 * @param string                                       $id Indicator
	 * @param array{count:int,amount:float|null,value:string,level:string} $r  Its result
	 * @return string
	 */
	protected function short($id, array $r)
	{
		global $langs;

		if ($id === 'outstanding') {
			return $langs->transnoentities($r['level'] === 'alert' ? 'InboxSig_outstanding_over' : 'InboxSig_outstanding', price((float) $r['amount'], 0, $langs, 1, -1, 0, 'auto'));
		}
		if ($id === 'next_due') {
			return $langs->transnoentities('InboxSig_next_due', $r['value']);
		}
		$n = (int) $r['count'];
		$text = $langs->transnoentities('InboxSig_'.$id.($n > 1 ? '_n' : '_1'), $n);
		if ($r['amount'] !== null && (float) $r['amount'] > 0) {
			$text .= ' · '.price((float) $r['amount'], 0, $langs, 1, -1, 0, 'auto');
		}

		return $text;
	}

	/**
	 * One indicator
	 *
	 * @param string $id    Indicator
	 * @param int    $socId Thirdparty
	 * @return array{count:int,amount?:float|null,value:string,level:string,detail?:string,url:string}|null Null on a SQL error
	 */
	protected function indicator($id, $socId)
	{
		global $langs;

		$p = $this->db->prefix();
		$now = $this->db->idate(dol_now());
		$today = $this->db->idate(dol_get_first_hour(dol_now()));
		$s = (int) $socId;
		// Rest to pay of a customer invoice (credit notes apart: they lower what is owed, never late)
		$restInv = "f.total_ttc - COALESCE((SELECT SUM(pf.amount) FROM ".$p."paiement_facture as pf WHERE pf.fk_facture = f.rowid), 0)"
			." - COALESCE((SELECT SUM(rc.amount_ttc) FROM ".$p."societe_remise_except as rc WHERE rc.fk_facture = f.rowid), 0)";
		$restSinv = "ff.total_ttc - COALESCE((SELECT SUM(pf.amount) FROM ".$p."paiementfourn_facturefourn as pf WHERE pf.fk_facturefourn = ff.rowid), 0)";
		$unpaid = " FROM ".$p."facture as f WHERE f.fk_soc = ".$s." AND f.entity IN (".getEntity('invoice').") AND f.fk_statut = 1 AND f.paye = 0 AND f.type IN (0, 1, 3)";
		switch ($id) {
			case 'inv_late':
				$row = $this->db->getRow("SELECT COUNT(*) as n, SUM(".$restInv.") as amount".$unpaid." AND f.date_lim_reglement < '".$today."'");
				if ($row === false) {
					return null;
				}
				$n = (int) ($row->n ?? 0);
				return array('count' => $n, 'amount' => (float) ($row->amount ?? 0), 'value' => $n ? $n.' · '.price((float) $row->amount, 0, $langs, 1, -1, 0, 'auto') : '0',
					'level' => $n ? 'alert' : 'normal', 'url' => DOL_URL_ROOT.'/compta/facture/list.php?socid='.$s.'&search_status=1&search_option=late');

			case 'outstanding':
				require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
				$soc = new Societe($this->db);
				if ($soc->fetch($s) <= 0) {
					return null;
				}
				$bills = $soc->getOutstandingBills('customer');
				$opened = (float) ($bills['opened'] ?? 0);
				$limit = (string) $soc->outstanding_limit;
				$over = $limit !== '' && $opened > (float) $limit;
				return array('count' => $opened > 0 ? 1 : 0, 'amount' => $opened, 'value' => price($opened, 0, $langs, 1, -1, 0, 'auto'),
					'level' => $over ? 'alert' : ($opened > 0 ? 'attention' : 'normal'),
					'detail' => $over ? $langs->transnoentities('InboxIndOverLimit', price((float) $limit, 0, $langs, 1, -1, 0, 'auto')) : '',
					'url' => DOL_URL_ROOT.'/compta/facture/list.php?socid='.$s.'&search_status=1');

			case 'next_due':
				$row = $this->db->getRow("SELECT f.date_lim_reglement as due, ".$restInv." as amount".$unpaid." AND f.date_lim_reglement >= '".$today."' ORDER BY f.date_lim_reglement ASC, f.rowid ASC");
				if ($row === false) {
					return null;
				}
				if (!is_object($row) || empty($row->due)) {
					return array('count' => 0, 'value' => $langs->transnoentities('InboxIndNone'), 'level' => 'normal', 'url' => DOL_URL_ROOT.'/compta/facture/list.php?socid='.$s.'&search_status=1');
				}
				$due = $this->db->jdate($row->due);
				$soon = $due <= dol_now() + self::DUE_SOON_DAYS * 86400;
				return array('count' => 1, 'amount' => (float) $row->amount, 'value' => dol_print_date($due, 'day').' · '.price((float) $row->amount, 0, $langs, 1, -1, 0, 'auto'),
					'level' => $soon ? 'attention' : 'normal', 'url' => DOL_URL_ROOT.'/compta/facture/list.php?socid='.$s.'&search_status=1&sortfield=f.date_lim_reglement&sortorder=asc');

			case 'propal_open':
				return $this->count("SELECT COUNT(*) as n FROM ".$p."propal WHERE fk_soc = ".$s." AND entity IN (".getEntity('propal').") AND fk_statut = 1", 'attention',
					DOL_URL_ROOT.'/comm/propal/list.php?socid='.$s.'&search_status=1');

			case 'order_open':
				return $this->count("SELECT COUNT(*) as n FROM ".$p."commande WHERE fk_soc = ".$s." AND entity IN (".getEntity('commande').") AND fk_statut IN (1, 2)", 'attention',
					DOL_URL_ROOT.'/commande/list.php?socid='.$s.'&search_status=-2');

			case 'ticket_open':
				// Closed (8) and cancelled (9) apart
				return $this->count("SELECT COUNT(*) as n FROM ".$p."ticket WHERE fk_soc = ".$s." AND entity IN (".getEntity('ticket').") AND fk_statut NOT IN (8, 9)", 'attention',
					DOL_URL_ROOT.'/ticket/list.php?socid='.$s.'&search_fk_statut[]=openall');

			case 'inter_open':
				return $this->count("SELECT COUNT(*) as n FROM ".$p."fichinter WHERE fk_soc = ".$s." AND entity IN (".getEntity('intervention').") AND fk_statut = 1", 'attention',
					DOL_URL_ROOT.'/fichinter/list.php?socid='.$s.'&search_status=1');

			case 'sorder_open':
				// Validated, approved, ordered, partly received
				return $this->count("SELECT COUNT(*) as n FROM ".$p."commande_fournisseur WHERE fk_soc = ".$s." AND entity IN (".getEntity('supplier_order').") AND fk_statut IN (1, 2, 3, 4)", 'attention',
					DOL_URL_ROOT.'/fourn/commande/list.php?socid='.$s.'&search_status=1,2,3,4');

			case 'sinv_topay':
				$row = $this->db->getRow("SELECT COUNT(*) as n, SUM(".$restSinv.") as amount, SUM(CASE WHEN ff.date_lim_reglement < '".$today."' THEN 1 ELSE 0 END) as late"
					." FROM ".$p."facture_fourn as ff WHERE ff.fk_soc = ".$s." AND ff.entity IN (".getEntity('supplier_invoice').") AND ff.fk_statut = 1 AND ff.paye = 0 AND ff.type IN (0, 1, 3)");
				if ($row === false) {
					return null;
				}
				$n = (int) ($row->n ?? 0);
				$late = (int) ($row->late ?? 0);
				return array('count' => $n, 'amount' => (float) ($row->amount ?? 0), 'value' => $n ? $n.' · '.price((float) $row->amount, 0, $langs, 1, -1, 0, 'auto') : '0',
					'level' => $late ? 'alert' : ($n ? 'attention' : 'normal'), 'detail' => $late ? $langs->transnoentities('InboxIndLateCount', $late) : '',
					'url' => DOL_URL_ROOT.'/fourn/facture/list.php?socid='.$s.'&search_status=1');

			case 'order_toship':
				return $this->countLate("SELECT COUNT(*) as n, SUM(CASE WHEN date_livraison < '".$today."' THEN 1 ELSE 0 END) as late FROM ".$p."commande WHERE fk_soc = ".$s
					." AND entity IN (".getEntity('commande').") AND fk_statut IN (1, 2)", DOL_URL_ROOT.'/commande/list.php?socid='.$s.'&search_status=-2');

			case 'ship_open':
				return $this->countLate("SELECT COUNT(*) as n, SUM(CASE WHEN date_delivery < '".$now."' THEN 1 ELSE 0 END) as late FROM ".$p."expedition WHERE fk_soc = ".$s
					." AND entity IN (".getEntity('expedition').") AND fk_statut = 1", DOL_URL_ROOT.'/expedition/list.php?socid='.$s.'&search_status=1');

			case 'reception_wait':
				// Ordered (3) or partly received (4)
				return $this->countLate("SELECT COUNT(*) as n, SUM(CASE WHEN date_livraison < '".$today."' THEN 1 ELSE 0 END) as late FROM ".$p."commande_fournisseur WHERE fk_soc = ".$s
					." AND entity IN (".getEntity('supplier_order').") AND fk_statut IN (3, 4)", DOL_URL_ROOT.'/fourn/commande/list.php?socid='.$s.'&search_status=3,4');
		}

		return null;
	}

	/**
	 * A number of records: normal at 0, else the level given
	 *
	 * @param string $sql   Query giving n
	 * @param string $level Level from 1
	 * @param string $url   List in Dolibarr
	 * @return array{count:int,value:string,level:string,url:string}|null
	 */
	protected function count($sql, $level, $url)
	{
		$row = $this->db->getRow($sql);
		if ($row === false) {
			return null;
		}
		$n = (int) ($row->n ?? 0);

		return array('count' => $n, 'value' => (string) $n, 'level' => $n ? $level : 'normal', 'url' => $url);
	}

	/**
	 * A number of records with a planned date: attention from 1, alert when one is past its date
	 *
	 * @param string $sql Query giving n and late
	 * @param string $url List in Dolibarr
	 * @return array{count:int,value:string,level:string,detail:string,url:string}|null
	 */
	protected function countLate($sql, $url)
	{
		global $langs;

		$row = $this->db->getRow($sql);
		if ($row === false) {
			return null;
		}
		$n = (int) ($row->n ?? 0);
		$late = (int) ($row->late ?? 0);

		return array('count' => $n, 'value' => (string) $n, 'level' => $late ? 'alert' : ($n ? 'attention' : 'normal'),
			'detail' => $late ? $langs->transnoentities('InboxIndLateCount', $late) : '', 'url' => $url);
	}

	/**
	 * Ways to reach a thirdparty or a contact: phones, email, address (what the card of Dolibarr shows)
	 *
	 * @param string $type  societe or contact
	 * @param int    $id    Record (readable by the user: checked by the caller)
	 * @return array{phone:string,mobile:string,email:string,address:string,map:string,client:int,supplier:int}|null
	 */
	public function reach($type, $id)
	{
		$p = $this->db->prefix();
		if ($type === 'societe') {
			$r = $this->db->getRow("SELECT phone, phone_mobile, email, address, zip, town, client, fournisseur FROM ".$p."societe WHERE rowid = ".((int) $id));
		} else {
			$r = $this->db->getRow("SELECT phone, phone_mobile, phone_perso, email, address, zip, town FROM ".$p."socpeople WHERE rowid = ".((int) $id));
		}
		if (!is_object($r)) {
			return null;
		}
		$place = trim(trim((string) $r->zip).' '.trim((string) $r->town));
		$full = trim(str_replace(array("\r", "\n"), ' ', (string) $r->address).' '.$place);

		return array(
			'phone' => trim((string) $r->phone),
			'mobile' => trim((string) $r->phone_mobile),
			'email' => trim((string) $r->email),
			'address' => $place,
			// Map: a named way out (OpenStreetMap search), never sent without a click
			'map' => $full !== '' ? 'https://www.openstreetmap.org/search?query='.rawurlencode($full) : '',
			'client' => (int) ($r->client ?? 0),
			'supplier' => (int) ($r->fournisseur ?? 0),
		);
	}

	/**
	 * « Not the right thirdparty? »: the thirdparty of the mails of a conversation of a mailbox chosen by hand (kept
	 * when the contacts change); remembered for the next mails of the address when asked
	 *
	 * @param User   $user      User doing it
	 * @param object $row       Mail of the index (rowid, fk_account, thread_key, from_email)
	 * @param int    $socId     New thirdparty (readable by the user: checked by the caller)
	 * @param bool   $remember  For the next mails of this address too
	 * @return int Mails changed, < 0 KO
	 */
	public function setThirdparty(User $user, $row, $socId, $remember)
	{
		global $conf;

		$p = $this->db->prefix();
		$where = "rowid = ".((int) $row->rowid);
		if ((string) $row->thread_key !== '') {
			$where = "(".$where." OR (fk_account = ".((int) $row->fk_account)." AND thread_key = '".$this->db->escape((string) $row->thread_key)."'))";
		}
		$this->db->begin();
		// The contact: the one of the new thirdparty with the address of the sender, if any
		$contact = $this->db->getRow("SELECT rowid FROM ".$p."socpeople WHERE fk_soc = ".((int) $socId)." AND LOWER(email) = '".$this->db->escape(strtolower(trim((string) $row->from_email)))."' AND statut = 1 ORDER BY rowid");
		$sql = "UPDATE ".$p."inbox_mail SET fk_soc = ".((int) $socId).", fk_socpeople = ".(is_object($contact) ? (int) $contact->rowid : 'NULL').", soc_manual = 1 WHERE ".$where;
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}
		$n = (int) $this->db->affected_rows(true);
		$email = strtolower(trim((string) $row->from_email));
		if ($remember && $email !== '') {
			$this->db->query("DELETE FROM ".$p."inbox_address_soc WHERE entity = ".((int) $conf->entity)." AND email = '".$this->db->escape($email)."'");
			if (!$this->db->query("INSERT INTO ".$p."inbox_address_soc (entity, email, fk_soc, fk_user_creat, date_creation) VALUES (".((int) $conf->entity).", '".$this->db->escape($email)."', ".((int) $socId).", ".((int) $user->id).", '".$this->db->idate(dol_now())."')")) {
				$this->error = $this->db->lasterror();
				$this->db->rollback();
				return -2;
			}
			// The other mails of the address follow, but the ones chosen by hand
			$this->db->query("UPDATE ".$p."inbox_mail SET fk_soc = ".((int) $socId).", fk_socpeople = ".(is_object($contact) ? (int) $contact->rowid : 'NULL')
				." WHERE from_email = '".$this->db->escape($email)."' AND soc_manual = 0");
		}
		$this->db->commit();

		return $n;
	}
}
