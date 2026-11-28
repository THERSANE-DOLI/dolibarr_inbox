<?php
/* Copyright (C) 2026 Air MKG
 * Copyright (C) 2026 THERSANE www.thersane.fr
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
 * \file    inbox/class/inboxoverview.class.php
 * \ingroup inbox
 * \brief   Overview of the other side of a mail (1.153.0), in its own column of the strip: mails exchanged, and for a
 *          thirdparty of Dolibarr its revenue by year, weight and seasonality, orders, deliveries, proposals,
 *          invoices, late payments, tickets, agenda, projects, contracts, products; for a supplier what we buy and
 *          what is late; for a prospect its status. Taken from the module InboxInsight of Max (Air MKG), GPL v3+.
 */

require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/security.lib.php';
require_once __DIR__.'/inboxaccess.class.php';
require_once __DIR__.'/inboxreader.class.php';
require_once __DIR__.'/inboxcounterpart.class.php';
require_once __DIR__.'/inboxdatasource.class.php';
require_once __DIR__.'/inboxsituation.class.php';
require_once __DIR__.'/inboxcited.class.php';

/**
 * Everything the overview shows for a mail. Read only, computed only when asked: the head (tags, key figures, the
 * list of the sections) when the column opens, each section when it is unfolded.
 *
 * Rights: the mail must be in a mailbox the user can read (InboxReader, else nothing at all; a protected mailbox gives
 * nothing either); the thirdparty is the one Inbox finds for the mail (index, address, domain), never one given by
 * the browser, and it must be readable by the user (rights of Dolibarr, salesmen restriction included); each section
 * is computed only with its module enabled and the right of Dolibarr on its data. The mails counted are the ones of
 * the mailboxes the user can read. Amounts are without tax, in the main currency. Every text is formatted here
 * (language of the user); the browser only draws.
 *
 * A section is: id, title, sum (one line read when it is folded), sumLevel, open, rows, and for some a hero figure,
 * bars (chart, spark, season) or a list of mails.
 */
class InboxOverview
{
	/** Sections, in their default order */
	const SECTIONS = array('revenue', 'supplier', 'mails', 'invoices', 'orders', 'proposals', 'tickets', 'agenda', 'projects', 'contracts', 'interventions', 'products', 'identity');
	/** Sections put first by the usage of the mailbox (InboxSituation::USAGES), the others follow in the default order */
	const USAGE_ORDER = array(
		'sales' => array('revenue', 'invoices', 'orders', 'proposals'),
		'accounting' => array('invoices', 'revenue', 'contracts'),
		'support' => array('tickets', 'interventions', 'mails', 'contracts'),
		'purchase' => array('supplier', 'orders'),
		'transport' => array('orders', 'supplier'),
	);
	/** Seconds the ranking of the customers (revenue of every thirdparty over 12 months) is kept */
	const RANK_CACHE = 3600;
	/** Years of the chart of the revenue (the current one included) */
	const YEARS = 6;
	/** Months without invoice after which a customer is said inactive */
	const INACTIVE_MONTHS = 12;
	/** Products shown, and months they are looked for */
	const TOP_PRODUCTS = 3;
	const TOP_PRODUCTS_MONTHS = 24;
	/** Paid invoices read for the payment delay, and months they are looked for */
	const DELAY_INVOICES = 100;
	const DELAY_MONTHS = 36;
	/** Days after the due date, on average, that make a slow payer */
	const SLOW_PAYER_DAYS = 15;
	/** Last mails listed */
	const LAST_MAILS = 5;
	/** Mails received since our last one that are worth a word on top */
	const UNANSWERED_MAILS = 2;
	/** Orders read for the usual time between two orders */
	const RHYTHM_ORDERS = 12;
	/** Characters of the notes of the thirdparty */
	const NOTE_LENGTH = 320;
	/** Last mails read for our reply time and the last ones listed */
	const REPLY_MAILS = 200;
	/** Days a conversation may wait for our answer before it is said on top */
	const WAIT_DAYS = 3;
	/** Days before the end of validity of an open proposal that are worth a word */
	const PROPAL_EXPIRE_DAYS = 15;
	/** Rank among the customers shown as a tag, up to this one */
	const TOP_RANK = 10;

	/** @var DoliDB */
	protected $db;
	/** @var array<int,array{label:string,level:string}> What must be seen first (late invoices, closed thirdparty...) */
	protected $alerts = array();
	/** @var string[] Lines of the plain summary (kept from InboxInsight, not shown) */
	protected $lines = array();
	/** @var array<int,array<string,mixed>> Key figures, by priority */
	protected $kpis = array();
	/** @var array{label:string,text:string}|null Notes written on the card of the thirdparty */
	protected $note = null;

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct(DoliDB $db)
	{
		$this->db = $db;
	}

	/**
	 * What the administrator can switch off on the setup page of inbox, by group. Everything is shown until it is
	 * switched off: a switch off is the constant INBOX_OVERVIEW_HIDE_<key>, so a new piece of information of a later
	 * version shows without activating the module again. INBOX_OVERVIEW_OFF removes the column itself.
	 *
	 * @return array<string,string[]> Group => keys (label: translation key InboxOvSet<key>)
	 */
	public static function settings()
	{
		return array(
			'TOP' => array('KPIS', 'NOTES'),
			'SECTIONS' => array('REVENUE', 'SUPPLIER', 'MAILS', 'INVOICES', 'ORDERS', 'PROPOSALS', 'TICKETS', 'AGENDA', 'PROJECTS', 'CONTRACTS', 'INTERVENTIONS', 'PRODUCTS', 'IDENTITY'),
			'DETAILS' => array('REVENUE_WEIGHT', 'REVENUE_SEASON', 'REVENUE_MARGIN', 'MAIL_LAST', 'MAIL_REPLY', 'MAIL_WHO', 'MAIL_BOUNCE', 'PAY_DELAY', 'ORDER_DELIVERY', 'ORDER_RHYTHM', 'PROPAL_EXPIRE', 'PROSPECT'),
		);
	}

	/**
	 * The column is offered to this user: not switched off, an internal user of the webmail
	 *
	 * @param User $user User
	 * @return bool
	 */
	public static function enabled(User $user)
	{
		return !getDolGlobalInt('INBOX_OVERVIEW_OFF') && !empty($user->id) && empty($user->socid) && $user->hasRight('inbox', 'webmail', 'use');
	}

	/**
	 * A piece of information is shown unless the administrator switched it off
	 *
	 * @param string $key Key of settings()
	 * @return bool
	 */
	protected function shown($key)
	{
		return !getDolGlobalString('INBOX_OVERVIEW_HIDE_'.$key);
	}

	// ---------- Mail, other side, thirdparty ----------

	/**
	 * What the overview is about, from the mail only: its row (mailbox readable by the user), the address of the other
	 * side, the thirdparty Inbox finds for it when the user may read it, the contact, the usage of the mailbox
	 *
	 * @param User $user   User
	 * @param int  $mailId Row of the index
	 * @return array{mail:object,email:string,soc:object|null,socId:int,contactId:int,usage:string,ours:array{emails:string[],domains:string[]}}|null Null: not readable, protected mailbox
	 */
	public function context(User $user, $mailId)
	{
		if (!self::enabled($user) || (int) $mailId <= 0 || InboxLive::isMail((int) $mailId)) {
			return null;
		}
		$reader = new InboxReader($this->db);
		if (!$reader->load($user, (int) $mailId) || !is_object($reader->row)) {
			return null;
		}
		$row = $reader->row;
		$requester = (new InboxDataSource($this->db))->ofRow($row);
		$counterpart = $requester ? array('email' => strtolower((string) $requester['email']), 'side' => 'sender') : InboxCounterpart::of($this->db, $row);
		$email = isValidEmail($counterpart['email']) ? $counterpart['email'] : '';
		$found = InboxCounterpart::thirdparty($this->db, $row, $email);
		$soc = $found['soc'] > 0 ? $this->thirdparty($user, $found['soc']) : null;
		$usage = 'auto';
		$acc = $this->db->getRow("SELECT usage_profile FROM ".$this->db->prefix()."inbox_account WHERE rowid = ".((int) $row->fk_account));
		if (is_object($acc) && InboxSituation::isUsage((string) $acc->usage_profile)) {
			$usage = (string) $acc->usage_profile;
		}
		if ($usage === 'auto' || $usage === 'none') {
			$usage = $soc && in_array((int) $soc->client, array(1, 2, 3), true) ? 'sales' : ($soc && (int) $soc->fournisseur === 1 ? 'purchase' : 'sales');
		}

		return array('mail' => $row, 'email' => $email, 'soc' => $soc, 'socId' => $soc ? (int) $soc->rowid : 0,
			'contactId' => $soc ? (int) $found['contact'] : 0, 'usage' => $usage, 'ours' => $this->ownAddresses());
	}

	// ---------- Head: tags, key figures, the sections offered ----------

	/**
	 * Head of the column, computed when it opens: tags of the thirdparty, alerts that are cheap to know, four key
	 * figures (short totals, not the sections), the notes of the card, and the sections offered in the order of the
	 * usage of the mailbox (rights and modules only: their content waits for their unfolding)
	 *
	 * @param User                $user User
	 * @param array<string,mixed> $ctx  context()
	 * @return array<string,mixed>
	 */
	public function head(User $user, array $ctx)
	{
		global $langs;

		$this->loadLangs();
		$this->alerts = array();
		$this->kpis = array();
		$this->note = null;
		$soc = $ctx['soc'];
		$tags = array();
		if ($soc) {
			$tags = $this->tags($user, $soc, (int) $ctx['contactId']);
			if ($this->shown('NOTES')) {
				$this->note = $this->noteOf($soc);
			}
		} else {
			$tags[] = array('label' => $langs->transnoentities('InboxOvUnknown'), 'level' => 'neutral');
		}
		if ($this->shown('KPIS')) {
			$this->headFigures($user, $ctx);
		}
		ksort($this->kpis);
		$sections = array();
		foreach ($this->offered($user, $ctx) as $id) {
			$sections[] = array('id' => $id, 'title' => $this->title($id), 'url' => $this->listUrl($id, (int) $ctx['socId']));
		}

		return array(
			'v' => 3,
			'soc' => $soc ? array('id' => (int) $soc->rowid, 'name' => (string) $soc->nom, 'url' => DOL_URL_ROOT.'/societe/card.php?socid='.((int) $soc->rowid)) : null,
			'email' => $ctx['email'],
			'usage' => $ctx['usage'],
			'tags' => $tags,
			'alerts' => $this->sortedAlerts(),
			'kpis' => array_slice(array_values($this->kpis), 0, 4),
			'note' => $this->note,
			'sections' => $sections,
			'labels' => array('open' => $langs->transnoentities('InboxOvOpenMail'), 'received' => $langs->transnoentities('InboxOvReceived'), 'sent' => $langs->transnoentities('InboxOvSent'),
				'current' => $langs->transnoentities('InboxOvCurrentMail'), 'empty' => $langs->transnoentities('InboxOvSectionEmpty')),
		);
	}

	/**
	 * One section, computed when it is unfolded, and the alerts it found (they join the tags on top)
	 *
	 * @param User                $user User
	 * @param array<string,mixed> $ctx  context()
	 * @param string              $id   Section (SECTIONS)
	 * @return array{section:array<string,mixed>|null,alerts:array<int,array{label:string,level:string}>}|null Null: not offered to this user
	 */
	public function section(User $user, array $ctx, $id)
	{
		if (!in_array((string) $id, $this->offered($user, $ctx), true)) {
			return null;
		}
		$this->loadLangs();
		$this->alerts = array();
		$this->kpis = array();
		$soc = $ctx['soc'];
		$s = (int) $ctx['socId'];
		$isCustomer = $soc && in_array((int) $soc->client, array(1, 2, 3), true);
		$out = null;
		switch ((string) $id) {
			case 'mails':
				$out = $this->mails($user, $ctx['mail'], $s, $ctx['email'], $ctx['ours']);
				break;
			case 'revenue':
				$r = $this->revenue($user, $soc, $isCustomer);
				$out = $r['section'];
				if ($r['rank'] > 0 && $r['rank'] <= self::TOP_RANK) {
					global $langs;
					$this->alerts[] = array('label' => $langs->transnoentities('InboxOvTagRank', $r['rank']), 'level' => 'info');
				}
				break;
			case 'supplier':
				$out = $this->supplier($user, $s);
				break;
			case 'invoices':
				$out = $this->invoices($soc);
				break;
			case 'orders':
				$out = $this->orders($user, $s, $isCustomer);
				break;
			case 'proposals':
				$out = $this->proposals($s);
				break;
			case 'tickets':
				$out = $this->tickets($user, $s);
				break;
			case 'agenda':
				$out = $this->agenda($user, $s);
				break;
			case 'projects':
				$out = $this->projects($user, $s);
				break;
			case 'contracts':
				$out = $this->contracts($s);
				break;
			case 'interventions':
				$out = $this->interventions($s);
				break;
			case 'products':
				$out = $this->products($s);
				break;
			case 'identity':
				$out = $this->identity($user, $soc, (int) $ctx['contactId'], null);
				break;
		}

		return array('section' => $out, 'alerts' => $this->sortedAlerts());
	}

	/**
	 * Key figures alone (widgets, 1.154.0): the same short totals as the head, by priority (10 revenue, 15 purchases,
	 * 20 left to pay, 25 to pay to the supplier, 30 last order, 40 previous mail)
	 *
	 * @param User                $user User
	 * @param array<string,mixed> $ctx  context()
	 * @return array<int,array<string,mixed>>
	 */
	public function figures(User $user, array $ctx)
	{
		$this->loadLangs();
		$this->alerts = array();
		$this->kpis = array();
		$this->headFigures($user, $ctx);

		return $this->kpis;
	}

	/**
	 * Sections offered to the user for this mail (widgets, 1.154.0)
	 *
	 * @param User                $user User
	 * @param array<string,mixed> $ctx  context()
	 * @return string[]
	 */
	public function offers(User $user, array $ctx)
	{
		return $this->offered($user, $ctx);
	}

	/**
	 * Mails exchanged with the other side, in the mailboxes the user can read (widgets, 1.154.0)
	 *
	 * @param User                $user User
	 * @param array<string,mixed> $ctx  context()
	 * @return array{nIn:int,nOut:int,lastIn:int}|null
	 */
	public function mailCounts(User $user, array $ctx)
	{
		if (!$this->shown('MAILS')) {
			return null;
		}

		return $this->mailTotals($user, $ctx['mail'], (int) $ctx['socId'], $ctx['email'], $ctx['ours']);
	}

	/**
	 * Right on a module and its permissions (widgets, 1.154.0)
	 *
	 * @param User                         $user   User
	 * @param string                       $module Module
	 * @param array<int,array<int,string>> $rights Rights (any of them)
	 * @return bool
	 */
	public function allows(User $user, $module, array $rights)
	{
		return $this->can($user, $module, $rights);
	}

	/**
	 * A piece of information is not switched off (widgets, 1.154.0)
	 *
	 * @param string $key Key of settings()
	 * @return bool
	 */
	public function isShown($key)
	{
		return $this->shown($key);
	}

	/**
	 * Sections offered to the user for this mail: by the switches, the modules and the rights, in the order of the
	 * usage of the mailbox; only the mails without a thirdparty
	 *
	 * @param User                $user User
	 * @param array<string,mixed> $ctx  context()
	 * @return string[]
	 */
	protected function offered(User $user, array $ctx)
	{
		$soc = $ctx['soc'];
		$canInvoice = $this->can($user, 'facture', array(array('facture', 'lire')));
		$ok = array(
			'mails' => $this->shown('MAILS') && ($ctx['socId'] || $ctx['email'] !== ''),
			'revenue' => $soc && $canInvoice && $this->shown('REVENUE'),
			'supplier' => $soc && (int) $soc->fournisseur === 1 && $this->shown('SUPPLIER')
				&& ($this->can($user, 'supplier_invoice', array(array('fournisseur', 'facture', 'lire'), array('supplier_invoice', 'lire')))
					|| $this->can($user, 'supplier_order', array(array('fournisseur', 'commande', 'lire'), array('supplier_order', 'lire')))),
			'invoices' => $soc && $canInvoice && $this->shown('INVOICES'),
			'orders' => $soc && $this->shown('ORDERS') && $this->can($user, 'commande', array(array('commande', 'lire'))),
			'proposals' => $soc && $this->shown('PROPOSALS') && $this->can($user, 'propal', array(array('propal', 'lire'))),
			'tickets' => $soc && $this->shown('TICKETS') && $this->can($user, 'ticket', array(array('ticket', 'read'))),
			'agenda' => $soc && $this->shown('AGENDA') && $this->can($user, 'agenda', array(array('agenda', 'allactions', 'read'), array('agenda', 'myactions', 'read'))),
			'projects' => $soc && $this->shown('PROJECTS') && $this->can($user, 'project', array(array('projet', 'lire'))),
			'contracts' => $soc && $this->shown('CONTRACTS') && $this->can($user, 'contrat', array(array('contrat', 'lire'))),
			'interventions' => $soc && $this->shown('INTERVENTIONS') && $this->can($user, 'ficheinter', array(array('ficheinter', 'lire'))),
			'products' => $soc && $canInvoice && $this->shown('PRODUCTS') && $this->can($user, 'product', array(array('produit', 'lire'))),
			'identity' => $soc && $this->shown('IDENTITY'),
		);
		$order = array_values(array_unique(array_merge(self::USAGE_ORDER[$ctx['usage']] ?? array(), self::SECTIONS)));

		return array_values(array_filter($order, function ($id) use ($ok) {
			return !empty($ok[$id]);
		}));
	}

	/**
	 * @param string $id Section
	 * @return string Title
	 */
	protected function title($id)
	{
		global $langs;

		$keys = array('revenue' => 'InboxOvSecRevenue', 'supplier' => 'InboxOvSecSupplier', 'mails' => 'InboxOvSecMails', 'invoices' => 'InboxOvSecInvoices',
			'orders' => 'InboxOvSecOrders', 'proposals' => 'InboxOvSecProposals', 'tickets' => 'InboxOvSecTickets', 'agenda' => 'InboxOvSecAgenda',
			'projects' => 'InboxOvSecProjects', 'contracts' => 'InboxOvSecContracts', 'interventions' => 'InboxOvSecInterventions', 'identity' => 'InboxOvSecIdentity');
		if ($id === 'products') {
			return $langs->transnoentities('InboxOvSecProducts', self::TOP_PRODUCTS_MONTHS);
		}

		return $langs->transnoentities($keys[$id] ?? $id);
	}

	/**
	 * List of Dolibarr opened by the icon of a section
	 *
	 * @param string $id    Section
	 * @param int    $socId Thirdparty
	 * @return string '' when none
	 */
	protected function listUrl($id, $socId)
	{
		if ($socId <= 0) {
			return '';
		}
		$urls = array('revenue' => '/compta/facture/list.php', 'invoices' => '/compta/facture/list.php', 'orders' => '/commande/list.php', 'proposals' => '/comm/propal/list.php',
			'tickets' => '/ticket/list.php', 'agenda' => '/comm/action/list.php', 'projects' => '/projet/list.php', 'contracts' => '/contrat/list.php',
			'interventions' => '/fichinter/list.php', 'identity' => '/societe/card.php', 'supplier' => '/fourn/facture/list.php');

		return isset($urls[$id]) ? DOL_URL_ROOT.$urls[$id].'?socid='.((int) $socId) : '';
	}

	/**
	 * The four key figures of the head, by short totals: revenue of the year and its change, left to pay, last order,
	 * previous mail; for a supplier what we bought this year and what we have to pay. Alerts of the late invoices and
	 * of the outstanding limit, known from the same totals.
	 *
	 * @param User                $user User
	 * @param array<string,mixed> $ctx  context()
	 * @return void
	 */
	protected function headFigures(User $user, array $ctx)
	{
		global $langs;

		$p = $this->db->prefix();
		$soc = $ctx['soc'];
		$s = (int) $ctx['socId'];
		$now = dol_now();
		$year = (int) dol_print_date($now, '%Y');
		if ($soc && $this->can($user, 'facture', array(array('facture', 'lire')))) {
			// Revenue of the year, the same period of the last one, the last one
			if ($this->shown('REVENUE')) {
				$where = $this->revenueWhere('f', $s);
				$sameDay = dol_time_plus_duree($now, -1, 'y');
				$r = $this->db->getRow("SELECT SUM(CASE WHEN f.datef >= '".$this->db->idate(dol_mktime(0, 0, 0, 1, 1, $year))."' THEN f.total_ht ELSE 0 END) as ytd,"
					." SUM(CASE WHEN f.datef >= '".$this->db->idate(dol_mktime(0, 0, 0, 1, 1, $year - 1))."' AND f.datef <= '".$this->db->idate($sameDay)."' THEN f.total_ht ELSE 0 END) as same,"
					." SUM(CASE WHEN f.datef >= '".$this->db->idate(dol_mktime(0, 0, 0, 1, 1, $year - 1))."' AND f.datef < '".$this->db->idate(dol_mktime(0, 0, 0, 1, 1, $year))."' THEN f.total_ht ELSE 0 END) as prev,"
					." COUNT(*) as n FROM ".$p."facture as f WHERE ".$where." AND f.datef >= '".$this->db->idate(dol_mktime(0, 0, 0, 1, 1, $year - 1))."'");
				if (is_object($r) && ((int) $r->n || in_array((int) $soc->client, array(1, 3), true))) {
					$ytd = (float) $r->ytd;
					$same = (float) $r->same;
					$delta = null;
					if ($same > 0) {
						$pct = (int) round(($ytd - $same) / $same * 100);
						$delta = array('text' => ($pct >= 0 ? '+' : '-').abs($pct).' %', 'level' => $pct >= 0 ? 'ok' : 'warning', 'dir' => $pct >= 0 ? 'up' : 'down',
							'title' => $langs->transnoentities('InboxOvDeltaTitle', $this->money($same), dol_print_date($sameDay, 'day')));
					}
					$this->kpis[10] = array('label' => $langs->transnoentities('InboxOvKpiRevenue', $year), 'value' => $this->money($ytd),
						'note' => $langs->transnoentities('InboxOvKpiRevenueNote', $year - 1, $this->money($r->prev)), 'level' => '', 'delta' => $delta);
				}
			}
			// Left to pay, what is late, the outstanding limit
			if ($this->shown('INVOICES')) {
				$base = "f.fk_soc = ".$s." AND f.entity IN (".getEntity('invoice').")";
				$rest = "(f.total_ttc - COALESCE((SELECT SUM(pf.amount) FROM ".$p."paiement_facture as pf WHERE pf.fk_facture = f.rowid), 0)"
					." - COALESCE((SELECT SUM(rc.amount_ttc) FROM ".$p."societe_remise_except as rc WHERE rc.fk_facture = f.rowid), 0))";
				$late = "f.date_lim_reglement < '".$this->db->idate(dol_get_first_hour($now))."'";
				$o = $this->db->getRow("SELECT COUNT(*) as n, SUM(".$rest.") as amount, SUM(CASE WHEN ".$late." THEN 1 ELSE 0 END) as n_late, SUM(CASE WHEN ".$late." THEN ".$rest." ELSE 0 END) as amount_late"
					." FROM ".$p."facture as f WHERE ".$base." AND f.fk_statut = 1 AND f.paye = 0 AND f.type IN (0, 1, 3, 5)");
				$nOpen = is_object($o) ? (int) $o->n : 0;
				$nLate = is_object($o) ? (int) $o->n_late : 0;
				$amount = is_object($o) ? (float) $o->amount : 0.0;
				if ($nOpen || in_array((int) $soc->client, array(1, 3), true)) {
					$this->kpis[20] = array('label' => $langs->transnoentities('InboxOvKpiDue'), 'value' => $this->money($amount),
						'note' => $nLate ? $langs->transnoentities('InboxOvSumLate', $this->money($o->amount_late)) : ($nOpen ? $this->plural($nOpen, 'InboxOvNInvoice') : $langs->transnoentities('InboxOvKpiDueNone')),
						'level' => $nLate ? 'danger' : ($nOpen ? '' : 'ok'));
				}
				if ($nLate) {
					$this->alerts[] = array('label' => $this->plural($nLate, 'InboxOvAlertLate').' · '.$this->money($o->amount_late), 'level' => 'danger');
				}
				if ((float) $soc->outstanding_limit > 0 && $amount > (float) $soc->outstanding_limit) {
					$this->alerts[] = array('label' => $langs->transnoentities('InboxOvOverLimit'), 'level' => 'danger');
				}
			}
		}
		// Last order
		if ($soc && $this->shown('ORDERS') && $this->can($user, 'commande', array(array('commande', 'lire')))) {
			$c = $this->db->getRow("SELECT MAX(c.date_commande) as d, SUM(CASE WHEN c.fk_statut IN (1, 2) THEN 1 ELSE 0 END) as n_open FROM ".$p."commande as c"
				." WHERE c.fk_soc = ".$s." AND c.entity IN (".getEntity('commande').") AND c.fk_statut > 0");
			if (is_object($c) && $c->d) {
				$d = $this->db->jdate($c->d);
				$this->kpis[30] = array('label' => $langs->transnoentities('InboxOvKpiLastOrder'), 'value' => $this->ago($d),
					'note' => (int) $c->n_open ? $langs->transnoentities('InboxOvSumOpen', (int) $c->n_open) : dol_print_date($d, 'day'), 'level' => '');
			}
		}
		// What we buy from a supplier
		if ($soc && (int) $soc->fournisseur === 1 && $this->shown('SUPPLIER') && $this->can($user, 'supplier_invoice', array(array('fournisseur', 'facture', 'lire'), array('supplier_invoice', 'lire')))) {
			$base = "ff.fk_soc = ".$s." AND ff.entity IN (".getEntity('supplier_invoice').")";
			$t = $this->db->getRow("SELECT SUM(CASE WHEN ff.datef >= '".$this->db->idate(dol_mktime(0, 0, 0, 1, 1, $year))."' THEN ff.total_ht ELSE 0 END) as ht_year,"
				." SUM(CASE WHEN ff.datef >= '".$this->db->idate(dol_mktime(0, 0, 0, 1, 1, $year - 1))."' AND ff.datef < '".$this->db->idate(dol_mktime(0, 0, 0, 1, 1, $year))."' THEN ff.total_ht ELSE 0 END) as ht_prev,"
				." COUNT(*) as n FROM ".$p."facture_fourn as ff WHERE ".$base." AND ff.fk_statut IN (1, 2) AND ff.type IN (0, 1, 2) AND ff.datef >= '".$this->db->idate(dol_mktime(0, 0, 0, 1, 1, $year - 1))."'");
			if (is_object($t) && (int) $t->n) {
				$this->kpis[15] = array('label' => $langs->transnoentities('InboxOvKpiPurchase', $year), 'value' => $this->money($t->ht_year),
					'note' => $langs->transnoentities('InboxOvKpiRevenueNote', $year - 1, $this->money($t->ht_prev)), 'level' => '');
			}
			$rest = "(ff.total_ttc - COALESCE((SELECT SUM(pf.amount) FROM ".$p."paiementfourn_facturefourn as pf WHERE pf.fk_facturefourn = ff.rowid), 0))";
			$o = $this->db->getRow("SELECT COUNT(*) as n, SUM(".$rest.") as amount, SUM(CASE WHEN ff.date_lim_reglement < '".$this->db->idate(dol_get_first_hour($now))."' THEN 1 ELSE 0 END) as n_late"
				." FROM ".$p."facture_fourn as ff WHERE ".$base." AND ff.fk_statut = 1 AND ff.paye = 0 AND ff.type IN (0, 1, 3)");
			if (is_object($o) && (int) $o->n) {
				$this->kpis[25] = array('label' => $langs->transnoentities('InboxOvKpiToPay'), 'value' => $this->money($o->amount),
					'note' => (int) $o->n_late ? $langs->transnoentities('InboxOvLateCount', (int) $o->n_late) : $this->plural((int) $o->n, 'InboxOvNInvoice'), 'level' => (int) $o->n_late ? 'warning' : '');
			}
		}
		// Previous mail received from them, in the mailboxes the user can read
		if ($this->shown('MAILS') && ($s || $ctx['email'] !== '')) {
			$m = $this->mailTotals($user, $ctx['mail'], $s, $ctx['email'], $ctx['ours']);
			if ($m) {
				$this->kpis[40] = $m['lastIn'] ? array('label' => $langs->transnoentities('InboxOvKpiPrevMail'), 'value' => $this->ago($m['lastIn']), 'note' => $langs->transnoentities('InboxOvSumMails', $m['nIn'], $m['nOut']), 'level' => '')
					: array('label' => $langs->transnoentities('InboxOvKpiMails'), 'value' => (string) ($m['nIn'] + $m['nOut']), 'note' => $langs->transnoentities('InboxOvSumMails', $m['nIn'], $m['nOut']), 'level' => '');
			}
		}
	}

	/**
	 * Notes of the card of the thirdparty, the private one first, shown to who may read the thirdparty (as on its card)
	 *
	 * @param object $soc Thirdparty
	 * @return array{label:string,text:string}|null
	 */
	protected function noteOf($soc)
	{
		global $langs;

		$text = '';
		foreach (array($soc->note_private, $soc->note_public) as $note) {
			$note = trim((string) preg_replace('/\s+/u', ' ', dol_string_nohtmltag((string) $note, 1)));
			if ($note !== '') {
				$text .= ($text !== '' ? ' · ' : '').$note;
			}
		}

		return $text !== '' ? array('label' => $langs->transnoentities('InboxOvNotes'), 'text' => dol_trunc($text, self::NOTE_LENGTH)) : null;
	}

	/**
	 * Alerts, the most serious first, each once
	 *
	 * @return array<int,array{label:string,level:string}>
	 */
	protected function sortedAlerts()
	{
		$rank = array('danger' => 0, 'warning' => 1, 'info' => 2, 'ok' => 3, 'neutral' => 4);
		$seen = array();
		$out = array();
		foreach ($this->alerts as $a) {
			if (!isset($seen[$a['label']])) {
				$seen[$a['label']] = 1;
				$out[] = $a;
			}
		}
		usort($out, function ($a, $b) use ($rank) {
			return ($rank[$a['level']] ?? 4) <=> ($rank[$b['level']] ?? 4);
		});

		return $out;
	}

	/**
	 * @return void
	 */
	protected function loadLangs()
	{
		global $langs;

		$langs->loadLangs(array('inbox@inbox', 'companies', 'bills', 'orders', 'propal', 'commercial'));
	}

	// ---------- Rights ----------

	/**
	 * A module enabled and one of the rights given
	 *
	 * @param User                         $user   User
	 * @param string                       $module Module
	 * @param array<int,array<int,string>> $rights Rights (any of them)
	 * @return bool
	 */
	protected function can(User $user, $module, array $rights)
	{
		if (!isModEnabled($module)) {
			return false;
		}
		foreach ($rights as $r) {
			if (count($r) === 3 ? $user->hasRight($r[0], $r[1], $r[2]) : $user->hasRight($r[0], $r[1])) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The thirdparty, when the user may read it
	 *
	 * @param User $user  User
	 * @param int  $socId Thirdparty
	 * @return object|null
	 */
	protected function thirdparty(User $user, $socId)
	{
		if (!$this->can($user, 'societe', array(array('societe', 'lire')))) {
			return null;
		}
		if (!checkUserAccessToObject($user, array('societe'), (int) $socId, '&societe', '', '', 'rowid')) {
			return null;
		}
		$row = $this->db->getRow("SELECT s.rowid, s.nom, s.client, s.fournisseur, s.status, s.datec, s.remise_client, s.outstanding_limit, s.code_client, s.tva_intra,"
			." s.note_private, s.note_public, s.cond_reglement, s.mode_reglement, s.price_level, s.fk_stcomm, s.fk_prospectlevel FROM ".$this->db->prefix()."societe as s"
			." WHERE s.rowid = ".((int) $socId)." AND s.entity IN (".getEntity('societe').")");

		return is_object($row) ? $row : null;
	}

	// ---------- Mails ----------

	/**
	 * Addresses and domains of the mailboxes of Inbox: a mail written from them is one of ours
	 *
	 * @return array{emails:string[],domains:string[]}
	 */
	protected function ownAddresses()
	{
		global $conf;

		$p = $this->db->prefix();
		$emails = array();
		$rows = $this->db->getRows("SELECT a.email FROM ".$p."inbox_account as a WHERE a.entity = ".((int) $conf->entity)
			." UNION SELECT i.email FROM ".$p."inbox_identity as i INNER JOIN ".$p."inbox_account as a2 ON a2.rowid = i.fk_account WHERE a2.entity = ".((int) $conf->entity).$this->db->plimit(2000));
		foreach (is_array($rows) ? $rows : array() as $r) {
			$e = strtolower(trim((string) $r->email));
			if ($e !== '') {
				$emails[$e] = $e;
			}
		}

		return array('emails' => array_values($emails), 'domains' => (new InboxCited($this->db))->ownDomains());
	}

	/**
	 * SQL of the mails exchanged with the thirdparty or the address in the mailboxes the user can read (junk, trash and
	 * drafts apart): its FROM part, the expression « written by us », the date, the key of a mail filed twice
	 *
	 * @param User                                    $user  User
	 * @param int                                     $socId Thirdparty, 0 if none
	 * @param string                                  $email Address of the other side, '' if none
	 * @param array{emails:string[],domains:string[]} $ours  Our addresses
	 * @return array{base:string,where:string,from:string,isOut:string,date:string,key:string,accounts:int[]}|null
	 */
	protected function mailSql(User $user, $socId, $email, array $ours)
	{
		$accounts = array_map('intval', array_keys((new InboxAccess($this->db))->getLevels($user)));
		if (!$accounts || (!$socId && $email === '')) {
			return null;
		}
		$p = $this->db->prefix();
		$e = $this->db->escape($email);
		// Rows by their indexes, one lookup each (an OR would read the whole index)
		$found = array();
		if ($socId) {
			$found[] = "SELECT rowid as id FROM ".$p."inbox_mail WHERE fk_soc = ".((int) $socId);
		}
		if ($email !== '') {
			$found[] = "SELECT rowid as id FROM ".$p."inbox_mail WHERE from_email = '".$e."'";
			$found[] = "SELECT fk_mail as id FROM ".$p."inbox_mail_address WHERE email = '".$e."' AND type IN ('to', 'cc')";
		}
		// Written by us: in a Sent folder, or from one of our addresses or domains
		$out = array("f.role = 'sent'");
		if ($ours['emails']) {
			$out[] = "m.from_email IN ('".implode("', '", array_map(array($this->db, 'escape'), $ours['emails']))."')";
		}
		foreach ($ours['domains'] as $d) {
			$out[] = "m.from_email LIKE '%@".$this->db->escape($this->db->escapeforlike($d))."'";
		}
		$base = " INNER JOIN (".implode(' UNION ', $found).") as x ON x.id = m.rowid LEFT JOIN ".$p."inbox_folder as f ON f.rowid = m.fk_folder";
		$where = " WHERE m.fk_account IN (".implode(', ', $accounts).") AND m.draft = 0 AND (f.role IS NULL OR f.role NOT IN ('junk', 'trash', 'drafts'))";

		return array('base' => $base, 'where' => $where, 'from' => " FROM ".$p."inbox_mail as m".$base.$where,
			// A row without folder gives NULL on the role: said « not ours » rather than unknown
			'isOut' => "COALESCE(".implode(' OR ', $out).", 0) = 1", 'date' => "COALESCE(m.date_mail, m.date_received)",
			'key' => "COALESCE(NULLIF(m.message_id, ''), CONCAT('#', m.rowid))", 'accounts' => $accounts);
	}

	/**
	 * Totals of the mails exchanged, for the key figure of the head: received, sent, the previous one received
	 *
	 * @param User                                    $user  User
	 * @param object                                  $mail  Mail opened
	 * @param int                                     $socId Thirdparty, 0 if none
	 * @param string                                  $email Address of the other side
	 * @param array{emails:string[],domains:string[]} $ours  Our addresses
	 * @return array{nIn:int,nOut:int,lastIn:int}|null
	 */
	protected function mailTotals(User $user, $mail, $socId, $email, array $ours)
	{
		$q = $this->mailSql($user, $socId, $email, $ours);
		if (!$q) {
			return null;
		}
		$cur = (string) $mail->message_id;
		$other = $cur !== '' ? "(m.message_id IS NULL OR m.message_id <> '".$this->db->escape($cur)."')" : "m.rowid <> ".((int) $mail->rowid);
		$row = $this->db->getRow("SELECT COUNT(DISTINCT CASE WHEN ".$q['isOut']." THEN NULL ELSE ".$q['key']." END) as n_in,"
			." COUNT(DISTINCT CASE WHEN ".$q['isOut']." THEN ".$q['key']." ELSE NULL END) as n_out,"
			." MAX(CASE WHEN NOT (".$q['isOut'].") AND ".$other." THEN ".$q['date']." ELSE NULL END) as last_in".$q['from']);

		return is_object($row) ? array('nIn' => (int) $row->n_in, 'nOut' => (int) $row->n_out, 'lastIn' => $row->last_in ? (int) $this->db->jdate($row->last_in) : 0) : null;
	}

	/**
	 * @param string                                  $email Address, lower case
	 * @param array{emails:string[],domains:string[]} $ours  Our addresses
	 * @return bool
	 */
	protected function isOurs($email, array $ours)
	{
		$at = strrpos($email, '@');

		return in_array($email, $ours['emails'], true) || ($at !== false && in_array(substr($email, $at + 1), $ours['domains'], true));
	}

	/**
	 * Mails exchanged with the thirdparty or the address, in the mailboxes the user can read: the previous one
	 * received, the previous one sent, how many, since when, what is waiting for an answer, the last ones, the
	 * months, who writes and which mailboxes are concerned. Junk, trash and drafts apart; a mail filed in two
	 * folders counts once.
	 *
	 * @param User                                    $user  User
	 * @param object                                  $mail  Mail opened
	 * @param int                                     $socId Thirdparty, 0 if none
	 * @param string                                  $email Address of the other side, '' if none
	 * @param array{emails:string[],domains:string[]} $ours  Our addresses
	 * @return array<string,mixed>|null
	 */
	protected function mails(User $user, $mail, $socId, $email, array $ours)
	{
		global $conf, $langs;

		$q = $this->mailSql($user, $socId, $email, $ours);
		if (!$q) {
			return null;
		}
		$p = $this->db->prefix();
		$e = $this->db->escape($email);
		$accounts = $q['accounts'];
		$isOut = $q['isOut'];
		$date = $q['date'];
		$key = $q['key'];
		$base = $q['base'];
		$where = $q['where'];
		$from = $q['from'];
		$cur = (string) $mail->message_id;
		$other = $cur !== '' ? "(m.message_id IS NULL OR m.message_id <> '".$this->db->escape($cur)."')" : "m.rowid <> ".((int) $mail->rowid);
		$row = $this->db->getRow("SELECT COUNT(DISTINCT CASE WHEN ".$isOut." THEN NULL ELSE ".$key." END) as n_in,"
			." COUNT(DISTINCT CASE WHEN ".$isOut." THEN ".$key." ELSE NULL END) as n_out,"
			." MAX(CASE WHEN NOT (".$isOut.") AND ".$other." THEN ".$date." ELSE NULL END) as last_in,"
			." MAX(CASE WHEN ".$isOut." AND ".$other." THEN ".$date." ELSE NULL END) as last_out,"
			." MAX(CASE WHEN ".$isOut." THEN ".$date." ELSE NULL END) as last_out_any,"
			." MIN(".$date.") as first_any".$from);
		if (!is_object($row)) {
			return null;
		}
		$nIn = (int) $row->n_in;
		$nOut = (int) $row->n_out;
		$lastIn = $row->last_in ? $this->db->jdate($row->last_in) : 0;
		$lastOut = $row->last_out ? $this->db->jdate($row->last_out) : 0;
		$first = $row->first_any ? $this->db->jdate($row->first_any) : 0;
		$none = $langs->transnoentities('InboxOvNone');

		// The last mails, newest first (the same mail in two folders read once): the ones listed, our reply time
		$last = array();
		$seen = array();
		$timeline = array();
		$recent = $this->db->getRows("SELECT m.rowid, m.fk_folder, m.message_id, m.subject, m.thread_key, m.from_name, m.from_email, ".$date." as d, CASE WHEN ".$isOut." THEN 1 ELSE 0 END as is_out".$from
			." ORDER BY d DESC, m.rowid DESC".$this->db->plimit(self::REPLY_MAILS));
		foreach (is_array($recent) ? $recent : array() as $r) {
			$k = (string) $r->message_id !== '' ? (string) $r->message_id : '#'.$r->rowid;
			if (isset($seen[$k])) {
				continue;
			}
			$seen[$k] = 1;
			$isCurrent = $cur !== '' ? (string) $r->message_id === $cur : (int) $r->rowid === (int) $mail->rowid;
			$name = trim(dol_string_nohtmltag((string) $r->from_name, 1));
			$timeline[] = array('ts' => $r->d ? $this->db->jdate($r->d) : 0, 'out' => (int) $r->is_out === 1, 'thread' => (string) $r->thread_key !== '' ? (string) $r->thread_key : $k, 'current' => $isCurrent,
				'msgid' => (string) $r->message_id, 'name' => $name !== '' ? $name : (string) $r->from_email);
			if (count($last) < self::LAST_MAILS) {
				$subject = trim(dol_string_nohtmltag((string) $r->subject, 1));
				$last[] = array('id' => (int) $r->rowid, 'folder' => (int) $r->fk_folder, 'out' => (int) $r->is_out === 1, 'date' => $r->d ? dol_print_date($this->db->jdate($r->d), '%d/%m/%y') : '',
					'subject' => dol_trunc($subject !== '' ? $subject : $langs->transnoentities('InboxOvNoSubject'), 90), 'current' => $isCurrent);
			}
		}
		// Who wrote our previous mail: the user of Dolibarr when Inbox sent it, else the name it was sent under
		$answerer = '';
		foreach ($this->shown('MAIL_WHO') ? $timeline : array() as $m) {
			if ($m['out'] && !$m['current']) {
				$answerer = $m['name'];
				if ($m['msgid'] !== '') {
					$sender = $this->db->getRow("SELECT u.firstname, u.lastname, u.login FROM ".$p."inbox_outbox as o INNER JOIN ".$p."user as u ON u.rowid = o.fk_user"
						." WHERE o.entity = ".((int) $conf->entity)." AND o.message_id = '".$this->db->escape($m['msgid'])."'");
					if (is_object($sender)) {
						$answerer = trim($sender->firstname.' '.$sender->lastname) ?: (string) $sender->login;
					}
				}
				break;
			}
		}

		$rows = array();
		if ($lastIn) {
			$rows[] = $this->row($langs->transnoentities('InboxOvMailPrevIn'), dol_print_date($lastIn, 'day'), $this->ago($lastIn));
		} else {
			$firstMail = $nIn <= 1 && !$lastOut;
			$rows[] = $this->row($langs->transnoentities('InboxOvMailPrevIn'), $none, $firstMail ? $langs->transnoentities('InboxOvMailFirst') : '');
			if ($firstMail) {
				$this->alerts[] = array('label' => $langs->transnoentities('InboxOvMailFirstShort'), 'level' => 'info');
			}
		}
		$rows[] = $lastOut ? $this->row($langs->transnoentities('InboxOvMailPrevOut'), dol_print_date($lastOut, 'day'),
				$this->ago($lastOut).($answerer !== '' ? ' · '.$langs->transnoentities('InboxOvAnsweredBy', dol_trunc($answerer, 40)) : ''))
			: $this->row($langs->transnoentities('InboxOvMailPrevOut'), $none);
		$rows[] = $this->row($langs->transnoentities('InboxOvMailCount'), $langs->transnoentities('InboxOvMailCountValue', $nIn, $nOut),
			$first ? $langs->transnoentities('InboxOvSince', dol_print_date($first, '%m/%Y')) : '');

		$replies = $this->shown('MAIL_REPLY') ? $this->replyRows($timeline) : array('rows' => array(), 'waiting' => false);
		foreach ($replies['rows'] as $extra) {
			$rows[] = $extra;
		}
		// Who of us deals with them: the users their mails are assigned to in Inbox
		$assigned = !$this->shown('MAIL_WHO') ? array() : $this->db->getRows("SELECT u.rowid, u.firstname, u.lastname, u.login, COUNT(DISTINCT r.rowid) as n FROM ".$p."inbox_mail as m".$base
			." INNER JOIN ".$p."inbox_mailref as r ON r.entity = ".((int) $conf->entity)." AND r.message_id = m.message_id"
			." INNER JOIN ".$p."inbox_mail_assign as ma ON ma.fk_mailref = r.rowid INNER JOIN ".$p."user as u ON u.rowid = ma.fk_user".$where
			." GROUP BY u.rowid, u.firstname, u.lastname, u.login ORDER BY n DESC".$this->db->plimit(3));
		if (is_array($assigned) && $assigned) {
			$rows[] = $this->row($langs->transnoentities('InboxOvMailAssigned'), '', implode(' · ', array_map(function ($r) {
				return dol_trunc(trim($r->firstname.' '.$r->lastname) ?: (string) $r->login, 28).' ('.((int) $r->n).')';
			}, $assigned)));
		}

		// Waiting for us: what they wrote since our last mail
		if ($row->last_out_any && $nIn) {
			$wait = $this->db->getRow("SELECT COUNT(DISTINCT ".$key.") as n".$from." AND NOT (".$isOut.") AND ".$date." > '".$this->db->escape((string) $row->last_out_any)."'");
			$pending = is_object($wait) ? (int) $wait->n : 0;
			if ($pending >= self::UNANSWERED_MAILS) {
				$rows[] = $this->row($langs->transnoentities('InboxOvMailPending'), $langs->transnoentities('InboxOvMailPendingValue', $pending), '', 'warning');
				$this->alerts[] = array('label' => $langs->transnoentities('InboxOvAlertPending', $pending), 'level' => 'warning');
			}
		} elseif (!$row->last_out_any && $nIn >= self::UNANSWERED_MAILS && !$replies['waiting']) {
			$rows[] = $this->row($langs->transnoentities('InboxOvMailPending'), $langs->transnoentities('InboxOvMailNeverAnswered'), '', 'info');
		}

		// Who writes on their side, which mailboxes of ours are concerned
		if ($socId && $this->shown('MAIL_WHO')) {
			$people = $this->db->getRows("SELECT m.from_email, MAX(m.from_name) as name, COUNT(DISTINCT ".$key.") as n".$from." AND NOT (".$isOut.")"
				." GROUP BY m.from_email ORDER BY n DESC".$this->db->plimit(4));
			if (is_array($people) && count($people) > 1) {
				$rows[] = $this->row($langs->transnoentities('InboxOvMailPeople'), '', implode(' · ', array_map(function ($r) {
					$name = trim(dol_string_nohtmltag((string) $r->name, 1));

					return dol_trunc($name !== '' ? $name : (string) $r->from_email, 28).' ('.((int) $r->n).')';
				}, $people)));
			}
		}
		$boxes = !$this->shown('MAIL_WHO') ? array() : $this->db->getRows("SELECT a.rowid, a.label, a.email, COUNT(DISTINCT ".$key.") as n FROM ".$p."inbox_mail as m".$base
			." INNER JOIN ".$p."inbox_account as a ON a.rowid = m.fk_account".$where." GROUP BY a.rowid, a.label, a.email ORDER BY n DESC".$this->db->plimit(4));
		if (is_array($boxes) && count($boxes) > 1) {
			$rows[] = $this->row($langs->transnoentities('InboxOvMailBoxes'), '', implode(' · ', array_map(function ($r) {
				return dol_trunc((string) ($r->label !== null && $r->label !== '' ? $r->label : $r->email), 24).' ('.((int) $r->n).')';
			}, $boxes)));
		}

		// A mail of ours that could not be delivered to this address
		if ($email !== '' && $this->shown('MAIL_BOUNCE')) {
			$bounce = $this->db->getRow("SELECT COUNT(*) as n, MAX(b.date_report) as last_date FROM ".$p."inbox_bounce as b WHERE b.entity = ".((int) $conf->entity)
				." AND b.recipient = '".$e."' AND b.action = 'failed' AND b.fk_account IN (".implode(', ', $accounts).")");
			if (is_object($bounce) && (int) $bounce->n) {
				$when = $bounce->last_date ? dol_print_date($this->db->jdate($bounce->last_date), 'day') : '';
				$rows[] = $this->row($langs->transnoentities('InboxOvMailBounce'), (string) (int) $bounce->n, $when !== '' ? $langs->transnoentities('InboxOvMailBounceLast', $when) : '', 'danger');
				$this->alerts[] = array('label' => $langs->transnoentities('InboxOvAlertBounce'), 'level' => 'danger');
			}
		}

		// The twelve last months
		$now = dol_now();
		$start = dol_time_plus_duree(dol_mktime(0, 0, 0, (int) dol_print_date($now, '%m'), 1, (int) dol_print_date($now, '%Y')), -11, 'm');
		$byMonth = array();
		$months = $this->db->getRows("SELECT date_format(".$date.", '%Y-%m') as ym, COUNT(DISTINCT CASE WHEN ".$isOut." THEN NULL ELSE ".$key." END) as n_in,"
			." COUNT(DISTINCT CASE WHEN ".$isOut." THEN ".$key." ELSE NULL END) as n_out".$from." AND ".$date." >= '".$this->db->idate($start)."'"
			." GROUP BY date_format(".$date.", '%Y-%m')".$this->db->plimit(13));
		foreach (is_array($months) ? $months : array() as $r) {
			$byMonth[(string) $r->ym] = array((int) $r->n_in, (int) $r->n_out);
		}
		$bars = array();
		$total = 0;
		for ($i = 0; $i < 12; $i++) {
			$ts = dol_time_plus_duree($start, $i, 'm');
			$v = $byMonth[dol_print_date($ts, '%Y-%m')] ?? array(0, 0);
			$total += $v[0] + $v[1];
			$bars[] = array('x' => dol_print_date($ts, '%b %Y'), 'v' => $v[0] + $v[1], 'text' => dol_print_date($ts, '%B %Y').' : '.$langs->transnoentities('InboxOvMailCountValue', $v[0], $v[1]));
		}

		$sum = $langs->transnoentities('InboxOvSumMails', $nIn, $nOut);
		$this->lines[] = $langs->transnoentities('InboxOvLineMails', $nIn, $nOut, $lastIn ? $this->ago($lastIn) : $none);
		$this->kpis[40] = $lastIn ? array('label' => $langs->transnoentities('InboxOvKpiPrevMail'), 'value' => $this->ago($lastIn), 'note' => $langs->transnoentities('InboxOvSumMails', $nIn, $nOut), 'level' => '')
			: array('label' => $langs->transnoentities('InboxOvKpiMails'), 'value' => (string) ($nIn + $nOut), 'note' => $langs->transnoentities('InboxOvSumMails', $nIn, $nOut), 'level' => '');

		return $this->makeSection('mails', $langs->transnoentities($socId ? 'InboxOvSecMailsSoc' : 'InboxOvSecMails'), $sum, $rows, array(
			'note' => $socId ? '' : $email, 'open' => true, 'mails' => $this->shown('MAIL_LAST') ? $last : array(), 'mailsLabel' => $langs->transnoentities('InboxOvMailLast'),
			'spark' => $total >= 3 ? array('label' => $langs->transnoentities('InboxOvMailMonths'), 'value' => (string) $total, 'bars' => $bars) : null,
		));
	}

	/**
	 * Our reply time and what is waiting, from the last mails: in each conversation, the time from the first mail
	 * not answered yet to our next one. The median, so that one forgotten mail does not hide the habit.
	 *
	 * @param array<int,array<string,mixed>> $timeline Last mails, newest first (ts, out, thread, current)
	 * @return array{rows:array<int,array<string,mixed>>,waiting:bool} Lines, and whether the conversation opened is waiting
	 */
	protected function replyRows(array $timeline)
	{
		global $langs;

		$rows = array();
		$delays = array();
		$waiting = array();
		$current = null;
		$isWaiting = false;
		foreach (array_reverse($timeline) as $m) {
			if (!$m['ts']) {
				continue;
			}
			if ($m['out']) {
				if (isset($waiting[$m['thread']])) {
					$delays[] = max(0, $m['ts'] - $waiting[$m['thread']]);
					unset($waiting[$m['thread']]);
				}
			} elseif (!isset($waiting[$m['thread']])) {
				$waiting[$m['thread']] = $m['ts'];
			}
			if ($m['current']) {
				$current = $m;
			}
		}
		// The conversation of the mail opened: received, and nothing sent since
		if ($current && !$current['out'] && isset($waiting[$current['thread']]) && $waiting[$current['thread']] <= $current['ts']) {
			$since = $waiting[$current['thread']];
			$isWaiting = true;
			$long = dol_now() - $since >= self::WAIT_DAYS * 86400;
			$rows[] = $this->row($langs->transnoentities('InboxOvMailWaiting'), $this->duration(dol_now() - $since), dol_print_date($since, 'dayhour'), $long ? 'warning' : '');
			if ($long) {
				$this->alerts[] = array('label' => $langs->transnoentities('InboxOvAlertWaiting', $this->duration(dol_now() - $since)), 'level' => 'warning');
			}
		}
		if (count($delays) >= 2) {
			sort($delays);
			$n = count($delays);
			$median = $n % 2 ? $delays[($n - 1) / 2] : ($delays[$n / 2 - 1] + $delays[$n / 2]) / 2;
			$rows[] = $this->row($langs->transnoentities('InboxOvReplyTime'), $this->duration($median), $langs->transnoentities('InboxOvReplyTimeNote', $n));
		}

		return array('rows' => $rows, 'waiting' => $isWaiting);
	}

	/**
	 * A length of time: minutes, hours, then days
	 *
	 * @param int|float $seconds Seconds
	 * @return string
	 */
	protected function duration($seconds)
	{
		global $langs;

		if ($seconds < 3600) {
			return $langs->transnoentities('InboxOvDurMin', max(1, (int) round($seconds / 60)));
		}
		if ($seconds < 2 * 86400) {
			return $langs->transnoentities('InboxOvDurHours', (int) round($seconds / 3600));
		}

		return $langs->transnoentities('InboxOvDays', (int) round($seconds / 86400));
	}

	/**
	 * Prospecting status and potential of a prospect, as the core translates them (label of the dictionary otherwise)
	 *
	 * @param object $soc Thirdparty
	 * @return array{status:string,level:string} '' when not a prospect or not set
	 */
	protected function prospect($soc)
	{
		global $langs;

		$out = array('status' => '', 'level' => '');
		if (!$this->shown('PROSPECT') || !in_array((int) $soc->client, array(2, 3), true)) {
			return $out;
		}
		$p = $this->db->prefix();
		if ($soc->fk_stcomm !== null && $soc->fk_stcomm !== '') {
			$k = 'StatusProspect'.((int) $soc->fk_stcomm);
			$out['status'] = $langs->transnoentities($k);
			if ($out['status'] === $k) {
				$r = $this->db->getRow("SELECT libelle FROM ".$p."c_stcomm WHERE id = ".((int) $soc->fk_stcomm));
				$out['status'] = is_object($r) ? (string) $r->libelle : '';
			}
		}
		$code = trim((string) $soc->fk_prospectlevel);
		if ($code !== '' && $code !== 'PL_NONE') {
			$out['level'] = $langs->transnoentities($code);
			if ($out['level'] === $code) {
				$r = $this->db->getRow("SELECT label FROM ".$p."c_prospectlevel WHERE code = '".$this->db->escape($code)."'");
				$out['level'] = is_object($r) ? (string) $r->label : '';
			}
		}

		return $out;
	}

	// ---------- Thirdparty ----------

	/**
	 * What kind of thirdparty: customer, prospect, supplier, closed, its categories
	 *
	 * @param User   $user      User
	 * @param object $soc       Thirdparty
	 * @param int    $contactId Contact of the mail, 0 if none
	 * @return array<int,array{label:string,level:string}>
	 */
	protected function tags(User $user, $soc, $contactId)
	{
		global $langs;

		$p = $this->db->prefix();
		$tags = array();
		$client = (int) $soc->client;
		if ($client === 1 || $client === 3) {
			$tags[] = array('label' => $langs->transnoentities('Customer'), 'level' => 'info');
		}
		if ($client === 2 || $client === 3) {
			$tags[] = array('label' => $langs->transnoentities('Prospect'), 'level' => 'neutral');
			$prospect = $this->prospect($soc);
			if ($prospect['level'] !== '') {
				$tags[] = array('label' => $langs->transnoentities('InboxOvTagPotential', dol_strtolower($prospect['level'])), 'level' => 'neutral');
			}
		}
		if ((int) $soc->fournisseur === 1) {
			$tags[] = array('label' => $langs->transnoentities('Supplier'), 'level' => 'neutral');
		}
		if ((int) $soc->status === 0) {
			$this->alerts[] = array('label' => $langs->transnoentities('InboxOvClosed'), 'level' => 'danger');
		}
		if ($this->can($user, 'categorie', array(array('categorie', 'lire')))) {
			$rows = $this->db->getRows("SELECT DISTINCT c.label FROM ".$p."categorie_societe as cs INNER JOIN ".$p."categorie as c ON c.rowid = cs.fk_categorie"
				." WHERE cs.fk_soc = ".((int) $soc->rowid)." AND c.entity IN (".getEntity('category').") ORDER BY c.label ASC".$this->db->plimit(3));
			foreach (is_array($rows) ? $rows : array() as $r) {
				$tags[] = array('label' => dol_trunc((string) $r->label, 28), 'level' => 'neutral');
			}
		}
		// The contact asked not to receive the mailings
		if ($contactId > 0 && $user->hasRight('societe', 'contact', 'lire')) {
			$c = $this->db->getRow("SELECT c.no_email FROM ".$p."socpeople as c WHERE c.rowid = ".((int) $contactId)." AND c.fk_soc = ".((int) $soc->rowid));
			if (is_object($c) && (int) $c->no_email === 1) {
				$tags[] = array('label' => $langs->transnoentities('InboxOvNoEmailing'), 'level' => 'neutral');
			}
		}

		return $tags;
	}

	/**
	 * Who they are for us: customer since, code, salesmen, job of the contact, contacts, terms, discount, notes
	 *
	 * @param User                     $user      User
	 * @param object                   $soc       Thirdparty
	 * @param int                      $contactId Contact of the mail, 0 if none
	 * @param array<string,mixed>|null $revenue   Result of revenue(), null without the right on the invoices
	 * @return array<string,mixed>|null
	 */
	protected function identity(User $user, $soc, $contactId, $revenue)
	{
		global $langs;

		$p = $this->db->prefix();
		$s = (int) $soc->rowid;
		$rows = array();
		$sum = array();
		if ($revenue === null && $this->can($user, 'facture', array(array('facture', 'lire')))) {
			$first = $this->db->getRow("SELECT MIN(f.datef) as d FROM ".$p."facture as f WHERE ".$this->revenueWhere('f', $s));
			$revenue = array('first' => is_object($first) && $first->d ? (int) $this->db->jdate($first->d) : 0);
		}
		if ($revenue && $revenue['first']) {
			$rows[] = $this->row($langs->transnoentities('InboxOvCustomerSince'), dol_print_date($revenue['first'], '%m/%Y'), $this->ago($revenue['first']));
			$sum[] = $langs->transnoentities('InboxOvSumSince', dol_print_date($revenue['first'], '%Y'));
		} elseif ($soc->datec) {
			$created = $this->db->jdate($soc->datec);
			$rows[] = $this->row($langs->transnoentities('InboxOvCreated'), dol_print_date($created, 'day'), $this->ago($created));
			$sum[] = $langs->transnoentities('InboxOvSumCreated', dol_print_date($created, '%m/%Y'));
		}
		$prospect = $this->prospect($soc);
		if ($prospect['status'] !== '') {
			$rows[] = $this->row($langs->transnoentities('InboxOvProspectStatus'), $prospect['status']);
			$sum[] = $prospect['status'];
		}
		if ($prospect['level'] !== '') {
			$rows[] = $this->row($langs->transnoentities('InboxOvProspectLevel'), $prospect['level']);
		}
		if (trim((string) $soc->code_client) !== '') {
			$rows[] = $this->row($langs->transnoentities('InboxOvCustomerCode'), (string) $soc->code_client);
		}
		$reps = $this->db->getRows("SELECT u.firstname, u.lastname, u.login FROM ".$p."societe_commerciaux as sc INNER JOIN ".$p."user as u ON u.rowid = sc.fk_user"
			." WHERE sc.fk_soc = ".$s." ORDER BY u.lastname ASC, u.firstname ASC".$this->db->plimit(4));
		$names = array();
		foreach (is_array($reps) ? $reps : array() as $r) {
			$names[] = trim($r->firstname.' '.$r->lastname) ?: (string) $r->login;
		}
		if ($names) {
			$rows[] = $this->row($langs->transnoentities('InboxOvSalesRep'), implode(', ', $names));
			$sum[] = $langs->transnoentities('InboxOvSumRep', $names[0]);
		}
		if ($user->hasRight('societe', 'contact', 'lire')) {
			if ($contactId > 0) {
				$c = $this->db->getRow("SELECT c.poste FROM ".$p."socpeople as c WHERE c.rowid = ".((int) $contactId)." AND c.fk_soc = ".$s." AND c.entity IN (".getEntity('contact').")");
				if (is_object($c) && trim((string) $c->poste) !== '') {
					$rows[] = $this->row($langs->transnoentities('InboxOvContactJob'), dol_trunc(trim((string) $c->poste), 60));
				}
			}
			$n = $this->db->getRow("SELECT COUNT(*) as n FROM ".$p."socpeople as c WHERE c.fk_soc = ".$s." AND c.statut = 1 AND c.entity IN (".getEntity('contact').")");
			if (is_object($n) && (int) $n->n > 1) {
				$rows[] = $this->row($langs->transnoentities('InboxOvContacts'), (string) (int) $n->n, '', '', DOL_URL_ROOT.'/societe/contact.php?socid='.$s);
			}
		}
		// Payment terms and mode, as their codes are translated by the core (label of the dictionary otherwise)
		if ((int) $soc->cond_reglement > 0) {
			$t = $this->db->getRow("SELECT code, libelle FROM ".$p."c_payment_term WHERE rowid = ".((int) $soc->cond_reglement));
			if (is_object($t)) {
				$k = 'PaymentConditionShort'.strtoupper((string) $t->code);
				$rows[] = $this->row($langs->transnoentities('InboxOvPayTerms'), $langs->transnoentities($k) !== $k ? $langs->transnoentities($k) : (string) $t->libelle);
			}
		}
		if ((int) $soc->mode_reglement > 0) {
			$t = $this->db->getRow("SELECT code, libelle FROM ".$p."c_paiement WHERE id = ".((int) $soc->mode_reglement));
			if (is_object($t)) {
				$k = 'PaymentTypeShort'.strtoupper((string) $t->code);
				$rows[] = $this->row($langs->transnoentities('InboxOvPayMode'), $langs->transnoentities($k) !== $k ? $langs->transnoentities($k) : (string) $t->libelle);
			}
		}
		if ((float) $soc->remise_client > 0) {
			$rows[] = $this->row($langs->transnoentities('InboxOvDiscount'), $this->number($soc->remise_client).' %');
		}
		if ((int) $soc->price_level > 0 && (getDolGlobalString('PRODUIT_MULTIPRICES') || getDolGlobalString('PRODUIT_CUSTOMER_PRICES_BY_QTY_MULTIPRICES'))) {
			$rows[] = $this->row($langs->transnoentities('InboxOvPriceLevel'), (string) (int) $soc->price_level);
		}
		if (trim((string) $soc->tva_intra) !== '') {
			$rows[] = $this->row($langs->transnoentities('InboxOvVat'), (string) $soc->tva_intra);
		}
		// What was written about them: the private note first
		$text = '';
		foreach (array($soc->note_private, $soc->note_public) as $note) {
			$note = trim((string) preg_replace('/\s+/u', ' ', dol_string_nohtmltag((string) $note, 1)));
			if ($note !== '') {
				$text .= ($text !== '' ? ' · ' : '').$note;
			}
		}
		// Read before anything else: shown on top of the block, not inside this section
		$this->note = $text !== '' ? array('label' => $langs->transnoentities('InboxOvNotes'), 'text' => dol_trunc($text, self::NOTE_LENGTH)) : null;

		return $rows ? $this->makeSection('identity', $langs->transnoentities('InboxOvSecIdentity'), implode(' · ', $sum), $rows, array('url' => DOL_URL_ROOT.'/societe/card.php?socid='.$s)) : null;
	}

	/**
	 * Revenue of every thirdparty over the twelve months of the chart, highest first, kept RANK_CACHE seconds per entity
	 * in a file of the temporary folder of inbox: the rank of a customer reads every invoice of the entity
	 *
	 * @param int $from Start of the twelve months
	 * @return array{total:float,sums:float[]}
	 */
	protected function ranking($from)
	{
		global $conf;

		$dir = !empty($conf->inbox->dir_temp) ? $conf->inbox->dir_temp : DOL_DATA_ROOT.'/inbox/temp';
		$file = $dir.'/overview-ranking-'.((int) $conf->entity).'.json';
		$key = dol_print_date($from, '%Y-%m');
		if (is_readable($file) && dol_now() - (int) @filemtime($file) < self::RANK_CACHE) {
			$cached = json_decode((string) @file_get_contents($file), true);
			if (is_array($cached) && ($cached['from'] ?? '') === $key && isset($cached['sums']) && is_array($cached['sums'])) {
				return array('total' => (float) $cached['total'], 'sums' => array_map('floatval', $cached['sums']));
			}
		}
		$p = $this->db->prefix();
		$rows = $this->db->getRows("SELECT SUM(f.total_ht) as ht FROM ".$p."facture as f WHERE ".$this->revenueWhere('f', 0)." AND f.datef >= '".$this->db->idate($from)."'"
			." GROUP BY f.fk_soc HAVING SUM(f.total_ht) > 0 ORDER BY ht DESC".$this->db->plimit(100000));
		$sums = array();
		foreach (is_array($rows) ? $rows : array() as $r) {
			$sums[] = round((float) $r->ht, 2);
		}
		$out = array('total' => array_sum($sums), 'sums' => $sums);
		if (dol_mkdir($dir) >= 0) {
			@file_put_contents($file, json_encode(array('from' => $key) + $out));
		}

		return $out;
	}

	/**
	 * Invoices that make the revenue: validated or paid, credit notes subtracted; drafts, replaced and abandoned
	 * invoices apart; deposits apart when Dolibarr counts them as payments (as the statistics of the core)
	 *
	 * @param string $alias Alias of the table of the invoices
	 * @param int    $socId Thirdparty, 0 for all of them
	 * @return string       SQL after WHERE
	 */
	protected function revenueWhere($alias, $socId)
	{
		$types = getDolGlobalString('FACTURE_DEPOSITS_ARE_JUST_PAYMENTS') ? '0, 1, 2, 5' : '0, 1, 2, 3, 5';

		return ((int) $socId > 0 ? $alias.".fk_soc = ".((int) $socId)." AND " : '').$alias.".entity IN (".getEntity('invoice').") AND ".$alias.".fk_statut IN (1, 2) AND ".$alias.".type IN (".$types.")";
	}

	/**
	 * Revenue without tax: current year and its change against the same period of the last one, the years as bars,
	 * the last twelve months as small bars, the total, the margin, the credit notes
	 *
	 * @param User   $user       User
	 * @param object $soc        Thirdparty
	 * @param bool   $isCustomer Customer or prospect: the section shows even without any invoice
	 * @return array{first:int,last:int,rank:int,section:array<string,mixed>|null}
	 */
	protected function revenue(User $user, $soc, $isCustomer)
	{
		global $langs;

		$p = $this->db->prefix();
		$s = (int) $soc->rowid;
		$where = $this->revenueWhere('f', $s);
		$now = dol_now();
		$year = (int) dol_print_date($now, '%Y');
		$month = (int) dol_print_date($now, '%m');
		$title = $langs->transnoentities('InboxOvSecRevenue');

		$tot = $this->db->getRow("SELECT COUNT(*) as n, SUM(f.total_ht) as ht, MIN(f.datef) as first_date, MAX(f.datef) as last_date FROM ".$p."facture as f WHERE ".$where);
		$n = is_object($tot) ? (int) $tot->n : 0;
		$first = $n && $tot->first_date ? $this->db->jdate($tot->first_date) : 0;
		$last = $n && $tot->last_date ? $this->db->jdate($tot->last_date) : 0;
		if (!$n) {
			// Said plainly for a customer: an empty history is an information too
			return array('first' => 0, 'last' => 0, 'rank' => 0, 'section' => $isCustomer ? $this->makeSection('revenue', $title, $langs->transnoentities('InboxOvNoInvoice'),
				array($this->row($langs->transnoentities('InboxOvRevenueTotal'), $langs->transnoentities('InboxOvNoInvoice'))), array('open' => true)) : null);
		}
		$total = (float) $tot->ht;

		// By year
		$byYear = array();
		$rows = $this->db->getRows("SELECT date_format(f.datef, '%Y') as y, SUM(f.total_ht) as ht, COUNT(*) as n FROM ".$p."facture as f WHERE ".$where
			." AND f.datef >= '".$this->db->idate(dol_mktime(0, 0, 0, 1, 1, $year - self::YEARS + 1))."' GROUP BY date_format(f.datef, '%Y')".$this->db->plimit(self::YEARS + 1));
		foreach (is_array($rows) ? $rows : array() as $r) {
			$byYear[(int) $r->y] = array((float) $r->ht, (int) $r->n);
		}
		// The years before the first invoice are not drawn (two bars at least)
		$start = max($year - self::YEARS + 1, min((int) dol_print_date($first, '%Y'), $year - 1));
		$bars = array();
		$history = array();
		for ($y = $start; $y <= $year; $y++) {
			$v = $byYear[$y][0] ?? 0.0;
			$nb = $byYear[$y][1] ?? 0;
			$bars[] = array('x' => (string) $y, 'v' => round($v, 2), 'short' => $this->compact($v), 'partial' => $y === $year,
				'text' => $langs->transnoentities($y === $year ? 'InboxOvBarYearPartial' : 'InboxOvBarYear', $y, $this->money($v), $this->plural($nb, 'InboxOvNInvoice')));
			// The same years as figures, the newest first: read without the chart, and by a screen reader
			array_unshift($history, $this->row($y === $year ? $langs->transnoentities('InboxOvRevenueYtd', $y) : (string) $y, $this->money($v), $nb ? $this->plural($nb, 'InboxOvNInvoice') : ''));
		}
		$ytd = $byYear[$year][0] ?? 0.0;
		$prevYear = $byYear[$year - 1][0] ?? 0.0;

		// Same period of the last year: the only fair comparison before the year ends
		$sameDay = dol_time_plus_duree($now, -1, 'y');
		$same = $this->db->getRow("SELECT SUM(f.total_ht) as ht FROM ".$p."facture as f WHERE ".$where
			." AND f.datef >= '".$this->db->idate(dol_mktime(0, 0, 0, 1, 1, $year - 1))."' AND f.datef <= '".$this->db->idate($sameDay)."'");
		$samePeriod = is_object($same) ? (float) $same->ht : 0.0;
		$delta = null;
		if ($samePeriod > 0) {
			$pct = (int) round(($ytd - $samePeriod) / $samePeriod * 100);
			$delta = array('text' => ($pct >= 0 ? '+' : '-').abs($pct).' %', 'level' => $pct >= 0 ? 'ok' : 'warning', 'dir' => $pct >= 0 ? 'up' : 'down',
				'title' => $langs->transnoentities('InboxOvDeltaTitle', $this->money($samePeriod), dol_print_date($sameDay, 'day')));
		}

		// Last twelve months, the current one included
		$from = dol_time_plus_duree(dol_mktime(0, 0, 0, $month, 1, $year), -11, 'm');
		$byMonth = array();
		$rows = $this->db->getRows("SELECT date_format(f.datef, '%Y-%m') as ym, SUM(f.total_ht) as ht FROM ".$p."facture as f WHERE ".$where
			." AND f.datef >= '".$this->db->idate($from)."' GROUP BY date_format(f.datef, '%Y-%m')".$this->db->plimit(13));
		foreach (is_array($rows) ? $rows : array() as $r) {
			$byMonth[(string) $r->ym] = (float) $r->ht;
		}
		$months = array();
		$rolling = 0.0;
		for ($i = 0; $i < 12; $i++) {
			$ts = dol_time_plus_duree($from, $i, 'm');
			$v = $byMonth[dol_print_date($ts, '%Y-%m')] ?? 0.0;
			$rolling += $v;
			$months[] = array('x' => dol_print_date($ts, '%b %Y'), 'v' => round($v, 2), 'text' => dol_print_date($ts, '%B %Y').' : '.$this->money($v));
		}

		$rowsOut = $history;
		$rowsOut[] = $this->row($langs->transnoentities('InboxOvRevenueTotal'), $this->money($total),
			$langs->transnoentities('InboxOvRevenueTotalNote', $this->plural($n, 'InboxOvNInvoice'), dol_print_date($first, '%Y'), $this->money($total / $n)));
		// Their weight among the customers over the same twelve months, for who sees every thirdparty
		$rank = 0;
		if ($rolling > 0 && $this->shown('REVENUE_WEIGHT') && $user->hasRight('societe', 'client', 'voir')) {
			$ranking = $this->ranking($from);
			if ($ranking['total'] > 0) {
				$rank = 1;
				foreach ($ranking['sums'] as $sum) {
					if ($sum > $rolling + 0.005) {
						$rank++;
					} else {
						break;
					}
				}
				$share = $rolling / $ranking['total'] * 100;
				$rowsOut[] = $this->row($langs->transnoentities('InboxOvWeight'), $langs->transnoentities('InboxOvWeightValue', $share < 1 ? '< 1' : (string) (int) round($share)),
					$langs->transnoentities('InboxOvWeightNote', $rank, count($ranking['sums'])));
			}
		}
		// The months they usually buy in: share of each month over the full years of the chart
		$season = null;
		$fullYears = 0;
		$fullCount = 0;
		for ($y = $year - self::YEARS + 1; $y < $year; $y++) {
			$fullYears += empty($byYear[$y][1]) ? 0 : 1;
			$fullCount += $byYear[$y][1] ?? 0;
		}
		if ($fullYears >= 2 && $fullCount >= 6 && $this->shown('REVENUE_SEASON')) {
			$byM = array();
			$rows = $this->db->getRows("SELECT date_format(f.datef, '%m') as m, SUM(f.total_ht) as ht FROM ".$p."facture as f WHERE ".$where
				." AND f.datef >= '".$this->db->idate(dol_mktime(0, 0, 0, 1, 1, $year - self::YEARS + 1))."' AND f.datef < '".$this->db->idate(dol_mktime(0, 0, 0, 1, 1, $year))."'"
				." GROUP BY date_format(f.datef, '%m')".$this->db->plimit(12));
			foreach (is_array($rows) ? $rows : array() as $r) {
				$byM[(int) $r->m] = max(0.0, (float) $r->ht);
			}
			$sumM = array_sum($byM);
			if ($sumM > 0) {
				$seasonBars = array();
				$strong = array();
				for ($m = 1; $m <= 12; $m++) {
					$part = ($byM[$m] ?? 0.0) / $sumM * 100;
					$name = dol_print_date(dol_mktime(12, 0, 0, $m, 1, 2001), '%B');
					$seasonBars[] = array('x' => dol_print_date(dol_mktime(12, 0, 0, $m, 1, 2001), '%b'), 'v' => round($part, 1), 'text' => $langs->transnoentities('InboxOvSeasonBar', ucfirst($name), (int) round($part)));
					// Half more than an even month
					if ($part >= 12.5) {
						$strong[$m] = $part;
					}
				}
				arsort($strong);
				$strong = array_slice($strong, 0, 4, true);
				ksort($strong);
				$firstFull = max($year - self::YEARS + 1, (int) dol_print_date($first, '%Y'));
				$season = array('label' => $langs->transnoentities('InboxOvSeason', $firstFull, $year - 1), 'value' => '', 'current' => $month - 1, 'bars' => $seasonBars);
				$rowsOut[] = $this->row($langs->transnoentities('InboxOvSeasonMonths'), $strong ? implode(', ', array_map(function ($m) {
					return dol_strtolower(dol_print_date(dol_mktime(12, 0, 0, $m, 1, 2001), '%b'));
				}, array_keys($strong))) : $langs->transnoentities('InboxOvSeasonNone'), isset($strong[$month]) ? $langs->transnoentities('InboxOvSeasonNow') : '');
			}
		}
		// Margin of the last twelve months: sale price less the cost price kept on each line (a credit note takes it back)
		if ($this->shown('REVENUE_MARGIN') && $this->can($user, 'margin', array(array('margins', 'liretous')))) {
			$m = $this->db->getRow("SELECT SUM(d.total_ht) as ht, SUM(d.total_ht - (CASE WHEN d.total_ht < 0 THEN -1 ELSE 1 END) * COALESCE(d.buy_price_ht, 0) * ABS(d.qty)) as margin,"
				." SUM(CASE WHEN COALESCE(d.buy_price_ht, 0) <> 0 THEN 1 ELSE 0 END) as n_cost FROM ".$p."facturedet as d"
				." INNER JOIN ".$p."facture as f ON f.rowid = d.fk_facture WHERE ".$where." AND f.datef >= '".$this->db->idate($from)."' AND d.special_code = 0");
			if (is_object($m) && (float) $m->ht > 0 && (int) $m->n_cost > 0) {
				$rowsOut[] = $this->row($langs->transnoentities('InboxOvMargin'), $this->money($m->margin), $langs->transnoentities('InboxOvMarginRate', (int) round((float) $m->margin / (float) $m->ht * 100)));
			}
		}
		// Credit notes of the last months: returns, disputes
		$since = $this->db->idate(dol_time_plus_duree($now, -self::TOP_PRODUCTS_MONTHS, 'm'));
		$credit = $this->db->getRow("SELECT COUNT(*) as n, SUM(f.total_ht) as ht FROM ".$p."facture as f WHERE ".$where." AND f.type = 2 AND f.datef >= '".$since."'");
		if (is_object($credit) && (int) $credit->n) {
			$rowsOut[] = $this->row($langs->transnoentities('InboxOvCreditNotes', self::TOP_PRODUCTS_MONTHS), $this->money(abs((float) $credit->ht)), $this->plural((int) $credit->n, 'InboxOvNCredit'));
		}
		// A customer who stopped buying
		$quiet = (int) floor(($now - $last) / (30.44 * 86400));
		if ($quiet >= self::INACTIVE_MONTHS && in_array((int) $soc->client, array(1, 3), true)) {
			$this->alerts[] = array('label' => $langs->transnoentities('InboxOvInactive', $quiet), 'level' => 'warning');
		}
		$this->lines[] = $langs->transnoentities('InboxOvLineRevenue', $year, $this->money($ytd), $year - 1, $this->money($prevYear), $this->money($total));
		$this->kpis[10] = array('label' => $langs->transnoentities('InboxOvKpiRevenue', $year), 'value' => $this->money($ytd), 'note' => $langs->transnoentities('InboxOvKpiRevenueNote', $year - 1, $this->money($prevYear)),
			'level' => '', 'delta' => $delta);

		return array('first' => $first, 'last' => $last, 'rank' => $rank, 'section' => $this->makeSection('revenue', $title,
			$langs->transnoentities('InboxOvSumRevenue', $year, $this->money($ytd), $this->money($total)), $rowsOut, array(
				'url' => DOL_URL_ROOT.'/compta/facture/list.php?socid='.$s, 'open' => true,
				'hero' => array('value' => $this->money($ytd), 'label' => $langs->transnoentities('InboxOvRevenueYtd', $year), 'delta' => $delta),
				'chart' => array('label' => $langs->transnoentities('InboxOvChartYears'), 'hint' => $langs->transnoentities('InboxOvChartHint', $year), 'bars' => $bars),
				'spark' => array('label' => $langs->transnoentities('InboxOvChartMonths'), 'value' => $this->money($rolling), 'bars' => $months),
				'season' => $season,
			)));
	}

	/**
	 * Orders: the last one, the ones in progress, how many, their average, their usual rhythm, the last shipment
	 *
	 * @param User $user       User
	 * @param int  $s          Thirdparty
	 * @param bool $isCustomer Customer or prospect: « none » is said; nothing for a supplier that never ordered
	 * @return array<string,mixed>|null
	 */
	protected function orders(User $user, $s, $isCustomer)
	{
		global $langs;

		$p = $this->db->prefix();
		$where = "c.fk_soc = ".((int) $s)." AND c.entity IN (".getEntity('commande').")";
		$title = $langs->transnoentities('InboxOvSecOrders');
		$tot = $this->db->getRow("SELECT COUNT(*) as n, SUM(c.total_ht) as ht, SUM(CASE WHEN c.fk_statut IN (1, 2) THEN 1 ELSE 0 END) as n_open,"
			." SUM(CASE WHEN c.fk_statut IN (1, 2) THEN c.total_ht ELSE 0 END) as ht_open FROM ".$p."commande as c WHERE ".$where." AND c.fk_statut > 0");
		if (!is_object($tot)) {
			return null;
		}
		$n = (int) $tot->n;
		$list = DOL_URL_ROOT.'/commande/list.php?socid='.((int) $s);
		if (!$n && !$isCustomer) {
			return null;
		}
		if (!$n) {
			return $this->makeSection('orders', $title, $langs->transnoentities('InboxOvNoneF'), array($this->row($langs->transnoentities('InboxOvOrderLast'), $langs->transnoentities('InboxOvNoneF'))), array('url' => $list));
		}
		$rows = array();
		$sum = array();
		$open = (int) $tot->n_open;
		// The dates of the last orders: the last one, and the usual time between two of them
		$recent = $this->db->getRows("SELECT c.rowid, c.ref, c.date_commande, c.total_ht, c.fk_statut FROM ".$p."commande as c WHERE ".$where." AND c.fk_statut > 0 AND c.date_commande IS NOT NULL"
			." ORDER BY c.date_commande DESC, c.rowid DESC".$this->db->plimit(self::RHYTHM_ORDERS));
		$recent = is_array($recent) ? $recent : array();
		$lastDate = 0;
		if ($recent) {
			$last = $recent[0];
			$lastDate = $this->db->jdate($last->date_commande);
			$rows[] = $this->row($langs->transnoentities('InboxOvOrderLast'), dol_print_date($lastDate, 'day'), $this->ago($lastDate).' · '.$last->ref.' · '.$this->money($last->total_ht),
				'', DOL_URL_ROOT.'/commande/card.php?id='.((int) $last->rowid));
			$this->lines[] = $langs->transnoentities('InboxOvLineOrders', dol_print_date($lastDate, 'day'), $open);
			$this->kpis[30] = array('label' => $langs->transnoentities('InboxOvKpiLastOrder'), 'value' => $this->ago($lastDate),
				'note' => $open ? $langs->transnoentities('InboxOvSumOpen', $open) : dol_print_date($lastDate, 'day'), 'level' => '');
			$sum[] = $langs->transnoentities('InboxOvSumLast', $this->ago($lastDate));
		}
		$refs = array();
		foreach ($recent as $r) {
			if (in_array((int) $r->fk_statut, array(1, 2), true) && count($refs) < 3) {
				$refs[] = (string) $r->ref;
			}
		}
		$rows[] = $this->row($langs->transnoentities('InboxOvOrderOpen'), $open ? $this->money($tot->ht_open) : $langs->transnoentities('InboxOvNoneF'),
			$open ? $this->plural($open, 'InboxOvNOrder').($refs ? ' · '.implode(', ', $refs).($open > count($refs) ? '…' : '') : '') : '', $open ? 'info' : '', $list.'&search_status=-2');
		if ($open) {
			array_unshift($sum, $langs->transnoentities('InboxOvSumOpen', $open));
		}
		// Deliveries promised and not done yet; else the next one planned
		$sumLevel = '';
		if ($open && $this->shown('ORDER_DELIVERY')) {
			$today = dol_get_first_hour(dol_now());
			$due = $this->db->getRows("SELECT c.rowid, c.ref, c.date_livraison FROM ".$p."commande as c WHERE ".$where." AND c.fk_statut IN (1, 2) AND c.date_livraison IS NOT NULL"
				." ORDER BY c.date_livraison ASC".$this->db->plimit(50));
			$lateOrders = array();
			$next = null;
			foreach (is_array($due) ? $due : array() as $r) {
				if ($this->db->jdate($r->date_livraison) < $today) {
					$lateOrders[] = $r;
				} elseif ($next === null) {
					$next = $r;
				}
			}
			if ($lateOrders) {
				$days = max(1, (int) round(($today - $this->db->jdate($lateOrders[0]->date_livraison)) / 86400));
				$rows[] = $this->row($langs->transnoentities('InboxOvDeliveryLate'), $this->plural(count($lateOrders), 'InboxOvNOrder'),
					$langs->transnoentities('InboxOvInvoiceLateNote', $days).' · '.implode(', ', array_map(function ($r) {
						return (string) $r->ref;
					}, array_slice($lateOrders, 0, 3))).(count($lateOrders) > 3 ? '…' : ''),
					'warning', count($lateOrders) === 1 ? DOL_URL_ROOT.'/commande/card.php?id='.((int) $lateOrders[0]->rowid) : $list.'&search_status=-2');
				$this->alerts[] = array('label' => $langs->transnoentities('InboxOvAlertDelivery', $days), 'level' => 'warning');
				array_unshift($sum, $langs->transnoentities('InboxOvSumDeliveryLate', count($lateOrders)));
				$sumLevel = 'warning';
			}
			if ($next) {
				$d = $this->db->jdate($next->date_livraison);
				$rows[] = $this->row($langs->transnoentities('InboxOvDeliveryNext'), dol_print_date($d, 'day'), $this->ago($d).' · '.$next->ref, '', DOL_URL_ROOT.'/commande/card.php?id='.((int) $next->rowid));
			}
			// What is left to ship: validated and nothing sent (1), partly sent (2)
			if ($this->can($user, 'expedition', array(array('expedition', 'lire')))) {
				$ship = $this->db->getRow("SELECT SUM(CASE WHEN c.fk_statut = 2 THEN 1 ELSE 0 END) as n_part FROM ".$p."commande as c WHERE ".$where." AND c.fk_statut IN (1, 2)");
				$rows[] = $this->row($langs->transnoentities('InboxOvToShip'), $this->plural($open, 'InboxOvNOrder'),
					is_object($ship) && (int) $ship->n_part ? $langs->transnoentities('InboxOvToShipNote', (int) $ship->n_part) : '');
			}
		}
		$rows[] = $this->row($langs->transnoentities('InboxOvOrderTotal'), (string) $n, $langs->transnoentities('InboxOvAverage', $this->money((float) $tot->ht / $n)));
		if (count($recent) >= 3 && $this->shown('ORDER_RHYTHM')) {
			$span = ($lastDate - $this->db->jdate($recent[count($recent) - 1]->date_commande)) / 86400;
			$every = (int) round($span / (count($recent) - 1));
			if ($every >= 1) {
				$since = (int) round((dol_now() - $lastDate) / 86400);
				$overdue = $since > 2 * $every && $every <= 366;
				$rows[] = $this->row($langs->transnoentities('InboxOvOrderRhythm'), $langs->transnoentities('InboxOvOrderRhythmValue', $every),
					$overdue ? $langs->transnoentities('InboxOvOrderOverdue', $since) : '', $overdue ? 'warning' : '');
				if ($overdue) {
					$this->alerts[] = array('label' => $langs->transnoentities('InboxOvAlertRhythm'), 'level' => 'warning');
				}
			}
		}
		if ($this->shown('ORDER_DELIVERY') && $this->can($user, 'expedition', array(array('expedition', 'lire')))) {
			$ship = $this->db->getRow("SELECT e.rowid, e.ref, e.tracking_number, COALESCE(e.date_expedition, e.date_valid, e.date_creation) as d FROM ".$p."expedition as e"
				." WHERE e.fk_soc = ".((int) $s)." AND e.entity IN (".getEntity('expedition').") AND e.fk_statut > 0 ORDER BY d DESC, e.rowid DESC");
			if (is_object($ship) && $ship->d) {
				$d = $this->db->jdate($ship->d);
				$rows[] = $this->row($langs->transnoentities('InboxOvShipLast'), dol_print_date($d, 'day'), $this->ago($d).' · '.$ship->ref.(trim((string) $ship->tracking_number) !== '' ? ' · '.dol_trunc((string) $ship->tracking_number, 30) : ''),
					'', DOL_URL_ROOT.'/expedition/card.php?id='.((int) $ship->rowid));
			}
		}

		return $this->makeSection('orders', $title, implode(' · ', $sum), $rows, array('url' => $list, 'sumLevel' => $sumLevel, 'open' => $sumLevel !== ''));
	}

	/**
	 * Proposals: the open ones, the share that gets signed
	 *
	 * @param int $s Thirdparty
	 * @return array<string,mixed>|null Null when there is none at all
	 */
	protected function proposals($s)
	{
		global $langs;

		$p = $this->db->prefix();
		$tot = $this->db->getRow("SELECT SUM(CASE WHEN pr.fk_statut = 1 THEN 1 ELSE 0 END) as n_open, SUM(CASE WHEN pr.fk_statut = 1 THEN pr.total_ht ELSE 0 END) as ht_open,"
			." SUM(CASE WHEN pr.fk_statut IN (2, 4) THEN 1 ELSE 0 END) as n_signed, SUM(CASE WHEN pr.fk_statut = 3 THEN 1 ELSE 0 END) as n_refused, MAX(pr.datep) as last_date"
			." FROM ".$p."propal as pr WHERE pr.fk_soc = ".((int) $s)." AND pr.entity IN (".getEntity('propal').") AND pr.fk_statut > 0");
		if (!is_object($tot) || !$tot->last_date) {
			return null;
		}
		$list = DOL_URL_ROOT.'/comm/propal/list.php?socid='.((int) $s);
		$open = (int) $tot->n_open;
		$sum = array($open ? $langs->transnoentities('InboxOvSumOpenAmount', $open, $this->money($tot->ht_open)) : $langs->transnoentities('InboxOvSumNoneOpen'));
		$rows = array($this->row($langs->transnoentities('InboxOvPropalOpen'), $open ? $this->money($tot->ht_open) : $langs->transnoentities('InboxOvNone'), $open ? $this->plural($open, 'InboxOvNPropal') : '',
			$open ? 'info' : '', $list.'&search_status=1'));
		// Open proposals whose validity ends soon, or is over: to chase
		$sumLevel = '';
		if ($open && $this->shown('PROPAL_EXPIRE')) {
			$today = dol_get_first_hour(dol_now());
			$ends = $this->db->getRows("SELECT pr.rowid, pr.ref, pr.fin_validite FROM ".$p."propal as pr WHERE pr.fk_soc = ".((int) $s)." AND pr.entity IN (".getEntity('propal').") AND pr.fk_statut = 1"
				." AND pr.fin_validite IS NOT NULL ORDER BY pr.fin_validite ASC".$this->db->plimit(50));
			$expired = array();
			$soon = array();
			foreach (is_array($ends) ? $ends : array() as $r) {
				$end = $this->db->jdate($r->fin_validite);
				if ($end < $today) {
					$expired[] = $r;
				} elseif ($end <= $today + self::PROPAL_EXPIRE_DAYS * 86400) {
					$soon[] = $r;
				}
			}
			if ($soon) {
				$end = $this->db->jdate($soon[0]->fin_validite);
				$rows[] = $this->row($langs->transnoentities('InboxOvPropalSoon'), $this->plural(count($soon), 'InboxOvNPropal'),
					$langs->transnoentities('InboxOvPropalSoonNote', dol_print_date($end, 'day'), $this->ago($end)).' · '.$soon[0]->ref, 'warning', DOL_URL_ROOT.'/comm/propal/card.php?id='.((int) $soon[0]->rowid));
				$this->alerts[] = array('label' => $langs->transnoentities('InboxOvAlertPropalSoon', $this->ago($end)), 'level' => 'info');
				$sum[] = $langs->transnoentities('InboxOvSumExpiring', count($soon));
				$sumLevel = 'warning';
			}
			if ($expired) {
				$rows[] = $this->row($langs->transnoentities('InboxOvPropalExpired'), $this->plural(count($expired), 'InboxOvNPropal'), implode(', ', array_map(function ($r) {
					return (string) $r->ref;
				}, array_slice($expired, 0, 3))).(count($expired) > 3 ? '…' : ''), 'warning', $list.'&search_status=1');
				$sumLevel = 'warning';
			}
		}
		$decided = (int) $tot->n_signed + (int) $tot->n_refused;
		if ($decided >= 3) {
			$rate = round((int) $tot->n_signed / $decided * 100);
			$rows[] = $this->row($langs->transnoentities('InboxOvPropalRate'), $rate.' %', $langs->transnoentities('InboxOvPropalRateNote', (int) $tot->n_signed, $decided));
			$sum[] = $langs->transnoentities('InboxOvSumRate', $rate);
		}
		$d = $this->db->jdate($tot->last_date);
		$rows[] = $this->row($langs->transnoentities('InboxOvPropalLast'), dol_print_date($d, 'day'), $this->ago($d));

		return $this->makeSection('proposals', $langs->transnoentities('InboxOvSecProposals'), implode(' · ', $sum), $rows, array('url' => $list, 'sumLevel' => $sumLevel));
	}

	/**
	 * Invoices: the last one, what is left to pay, what is late, credits to use, how long they take to pay
	 *
	 * @param object $soc Thirdparty
	 * @return array<string,mixed>|null Null when there is none at all
	 */
	protected function invoices($soc)
	{
		global $langs;

		$p = $this->db->prefix();
		$s = (int) $soc->rowid;
		$list = DOL_URL_ROOT.'/compta/facture/list.php?socid='.$s;
		$base = "f.fk_soc = ".$s." AND f.entity IN (".getEntity('invoice').")";
		$last = $this->db->getRow("SELECT f.rowid, f.ref, f.datef, f.total_ht FROM ".$p."facture as f WHERE ".$base." AND f.fk_statut > 0 AND f.type IN (0, 1, 3, 5)"
			." ORDER BY f.datef DESC, f.rowid DESC");
		if (!is_object($last)) {
			return null;
		}
		$rows = array();
		$d = $this->db->jdate($last->datef);
		$rows[] = $this->row($langs->transnoentities('InboxOvInvoiceLast'), dol_print_date($d, 'day'), $this->ago($d).' · '.$last->ref.' · '.$this->money($last->total_ht),
			'', DOL_URL_ROOT.'/compta/facture/card.php?facid='.((int) $last->rowid));

		// Left to pay of an invoice: its payments and the credit notes or deposits used on it taken away
		$rest = "(f.total_ttc - COALESCE((SELECT SUM(pf.amount) FROM ".$p."paiement_facture as pf WHERE pf.fk_facture = f.rowid), 0)"
			." - COALESCE((SELECT SUM(rc.amount_ttc) FROM ".$p."societe_remise_except as rc WHERE rc.fk_facture = f.rowid), 0))";
		$today = $this->db->idate(dol_get_first_hour(dol_now()));
		$late = "f.date_lim_reglement < '".$today."'";
		$unpaid = " FROM ".$p."facture as f WHERE ".$base." AND f.fk_statut = 1 AND f.paye = 0 AND f.type IN (0, 1, 3, 5)";
		$open = $this->db->getRow("SELECT COUNT(*) as n, SUM(".$rest.") as amount, SUM(CASE WHEN ".$late." THEN 1 ELSE 0 END) as n_late,"
			." SUM(CASE WHEN ".$late." THEN ".$rest." ELSE 0 END) as amount_late, MIN(CASE WHEN ".$late." THEN f.date_lim_reglement ELSE NULL END) as oldest".$unpaid);
		$nOpen = is_object($open) ? (int) $open->n : 0;
		$nLate = is_object($open) ? (int) $open->n_late : 0;
		$amountOpen = is_object($open) ? (float) $open->amount : 0.0;
		$rows[] = $this->row($langs->transnoentities('InboxOvInvoiceOpen'), $this->money($amountOpen),
			$nOpen ? $this->plural($nOpen, 'InboxOvNInvoice').' · '.$langs->transnoentities('InboxOvInclTax') : '', $nOpen ? 'info' : '', $list.'&search_status=1');
		$sumLevel = '';
		$sum = array($nOpen ? $langs->transnoentities('InboxOvSumDue', $this->money($amountOpen)) : $langs->transnoentities('InboxOvSumNothingDue'));
		if ($nLate) {
			$oldest = $this->db->jdate($open->oldest);
			$days = max(1, (int) round((dol_get_first_hour(dol_now()) - $oldest) / 86400));
			$refs = $this->db->getRows("SELECT f.ref".$unpaid." AND ".$late." ORDER BY f.date_lim_reglement ASC".$this->db->plimit(3));
			$refList = implode(', ', array_map(function ($r) {
				return (string) $r->ref;
			}, is_array($refs) ? $refs : array()));
			$rows[] = $this->row($langs->transnoentities('InboxOvInvoiceLate'), $this->money($open->amount_late),
				$this->plural($nLate, 'InboxOvNInvoice').' · '.$langs->transnoentities('InboxOvInvoiceLateNote', $days).($refList !== '' ? ' · '.$refList.($nLate > 3 ? '…' : '') : ''),
				'danger', $list.'&search_status=1&search_option=late');
			$this->alerts[] = array('label' => $this->plural($nLate, 'InboxOvAlertLate').' · '.$this->money($open->amount_late), 'level' => 'danger');
			$sum[] = $langs->transnoentities('InboxOvSumLate', $this->money($open->amount_late));
			$sumLevel = 'danger';
		} else {
			$rows[] = $this->row($langs->transnoentities('InboxOvInvoiceLate'), $langs->transnoentities('InboxOvNone'), '', 'ok');
		}
		$limit = (string) $soc->outstanding_limit;
		if ($limit !== '' && (float) $limit > 0) {
			$over = $amountOpen > (float) $limit;
			$rows[] = $this->row($langs->transnoentities('InboxOvOutstanding'), $this->money($amountOpen).' / '.$this->money($limit), $over ? $langs->transnoentities('InboxOvOverLimit') : '', $over ? 'danger' : '');
			if ($over) {
				$this->alerts[] = array('label' => $langs->transnoentities('InboxOvOverLimit'), 'level' => 'danger');
				$sumLevel = 'danger';
			}
		}
		// Credit notes and deposits not used yet: to offer on the next invoice
		$credit = $this->db->getRow("SELECT SUM(rc.amount_ttc) as amount FROM ".$p."societe_remise_except as rc WHERE rc.fk_soc = ".$s." AND rc.entity IN (".getEntity('invoice').")"
			." AND rc.discount_type = 0 AND rc.fk_facture IS NULL AND rc.fk_facture_line IS NULL");
		if (is_object($credit) && (float) $credit->amount > 0) {
			$rows[] = $this->row($langs->transnoentities('InboxOvCreditAvailable'), $this->money($credit->amount), $langs->transnoentities('InboxOvInclTax'), 'info', DOL_URL_ROOT.'/comm/remx.php?id='.$s);
		}
		$this->lines[] = $langs->transnoentities('InboxOvLineInvoices', $this->money($amountOpen), $this->plural($nOpen, 'InboxOvNInvoice'), $nLate);
		$this->kpis[20] = array('label' => $langs->transnoentities('InboxOvKpiDue'), 'value' => $this->money($amountOpen),
			'note' => $nLate ? $langs->transnoentities('InboxOvSumLate', $this->money($open->amount_late)) : ($nOpen ? $this->plural($nOpen, 'InboxOvNInvoice') : $langs->transnoentities('InboxOvKpiDueNone')),
			'level' => $nLate ? 'danger' : ($nOpen ? '' : 'ok'));

		// How long they take to pay: date of the invoice to its last payment, and against the due date
		$since = $this->db->idate(dol_time_plus_duree(dol_now(), -self::DELAY_MONTHS, 'm'));
		$paid = !$this->shown('PAY_DELAY') ? array() : $this->db->getRows("SELECT f.rowid, f.datef, f.date_lim_reglement, MAX(pa.datep) as paid_date FROM ".$p."facture as f"
			." INNER JOIN ".$p."paiement_facture as pf ON pf.fk_facture = f.rowid INNER JOIN ".$p."paiement as pa ON pa.rowid = pf.fk_paiement"
			." WHERE ".$base." AND f.fk_statut = 2 AND f.paye = 1 AND f.type IN (0, 1) AND f.datef >= '".$since."'"
			." GROUP BY f.rowid, f.datef, f.date_lim_reglement ORDER BY f.datef DESC".$this->db->plimit(self::DELAY_INVOICES));
		$delays = array();
		$lates = array();
		foreach (is_array($paid) ? $paid : array() as $r) {
			$paidAt = $this->db->jdate($r->paid_date);
			$delays[] = max(0, ($paidAt - $this->db->jdate($r->datef)) / 86400);
			if ($r->date_lim_reglement) {
				$lates[] = ($paidAt - $this->db->jdate($r->date_lim_reglement)) / 86400;
			}
		}
		if (count($delays) >= 2) {
			$avg = (int) round(array_sum($delays) / count($delays));
			$avgLate = $lates ? (int) round(array_sum($lates) / count($lates)) : 0;
			$slow = $avgLate > self::SLOW_PAYER_DAYS;
			$rows[] = $this->row($langs->transnoentities('InboxOvPayDelay'), $langs->transnoentities('InboxOvDays', $avg),
				$langs->transnoentities($avgLate > 0 ? 'InboxOvPayLate' : 'InboxOvPayOnTime', $avgLate > 0 ? $avgLate : '', $this->plural(count($delays), 'InboxOvNInvoice')),
				$slow ? 'warning' : ($avgLate <= 0 ? 'ok' : ''));
			if ($slow) {
				$this->alerts[] = array('label' => $langs->transnoentities('InboxOvAlertSlowPayer', $avgLate), 'level' => 'warning');
			}
		}

		return $this->makeSection('invoices', $langs->transnoentities('InboxOvSecInvoices'), implode(' · ', $sum), $rows, array('url' => $list, 'sumLevel' => $sumLevel, 'open' => $sumLevel === 'danger'));
	}

	/**
	 * Tickets: open ones (the last three named), how many, the last one
	 *
	 * @param User $user User
	 * @param int  $s    Thirdparty
	 * @return array<string,mixed>|null Null when there is none at all
	 */
	protected function tickets(User $user, $s)
	{
		global $langs;

		$p = $this->db->prefix();
		$where = "t.fk_soc = ".((int) $s)." AND t.entity IN (".getEntity('ticket').")";
		// As the list of the core (ticket/list.php): with « assigned tickets only », a user who is not an administrator
		// sees the tickets assigned to him
		if (getDolGlobalString('TICKET_LIMIT_VIEW_ASSIGNED_ONLY') && !$user->admin) {
			$where .= " AND t.fk_user_assign = ".((int) $user->id);
		}
		// Closed (8) and cancelled (9) apart
		$tot = $this->db->getRow("SELECT COUNT(*) as n, SUM(CASE WHEN t.fk_statut NOT IN (8, 9) THEN 1 ELSE 0 END) as n_open, MAX(t.datec) as last_date FROM ".$p."ticket as t WHERE ".$where);
		if (!is_object($tot) || !(int) $tot->n) {
			return null;
		}
		$list = DOL_URL_ROOT.'/ticket/list.php?socid='.((int) $s);
		$open = (int) $tot->n_open;
		$d = $this->db->jdate($tot->last_date);
		$rows = array($this->row($langs->transnoentities('InboxOvTicketOpen'), (string) $open, '', $open ? 'info' : '', $list.'&search_fk_statut[]=openall'));
		if ($open) {
			$this->alerts[] = array('label' => $this->plural($open, 'InboxOvAlertTicket'), 'level' => 'info');
			$found = $this->db->getRows("SELECT t.rowid, t.ref, t.subject, t.datec FROM ".$p."ticket as t WHERE ".$where." AND t.fk_statut NOT IN (8, 9) ORDER BY t.datec DESC".$this->db->plimit(3));
			foreach (is_array($found) ? $found : array() as $r) {
				$rows[] = $this->row(dol_trunc(trim(dol_string_nohtmltag((string) $r->subject, 1)), 60), dol_print_date($this->db->jdate($r->datec), '%d/%m/%y'), (string) $r->ref, '',
					DOL_URL_ROOT.'/ticket/card.php?id='.((int) $r->rowid), true);
			}
		}
		$rows[] = $this->row($langs->transnoentities('InboxOvTicketTotal'), (string) (int) $tot->n, $langs->transnoentities('InboxOvTicketLast', dol_print_date($d, 'day'), $this->ago($d)));

		return $this->makeSection('tickets', $langs->transnoentities('InboxOvSecTickets'), $langs->transnoentities('InboxOvSumTickets', $open, (int) $tot->n), $rows, array('url' => $list));
	}

	/**
	 * Agenda: the next event planned with them and the last one (events written by Dolibarr itself apart)
	 *
	 * @param User $user User
	 * @param int  $s    Thirdparty
	 * @return array<string,mixed>|null Null when there is none
	 */
	protected function agenda(User $user, $s)
	{
		global $langs;

		$p = $this->db->prefix();
		$from = " FROM ".$p."actioncomm as a LEFT JOIN ".$p."c_actioncomm as ca ON ca.id = a.fk_action WHERE a.fk_soc = ".((int) $s)." AND a.entity IN (".getEntity('agenda').")"
			." AND (ca.type IS NULL OR ca.type <> 'systemauto')";
		// Without the right on the events of the others: his own only
		if (!$user->hasRight('agenda', 'allactions', 'read')) {
			$from .= " AND (a.fk_user_author = ".((int) $user->id)." OR a.fk_user_action = ".((int) $user->id).")";
		}
		$now = $this->db->idate(dol_now());
		$next = $this->db->getRow("SELECT a.id, a.label, a.datep, ca.code".$from." AND a.datep >= '".$now."' ORDER BY a.datep ASC");
		$last = $this->db->getRow("SELECT a.id, a.label, a.datep, ca.code".$from." AND a.datep < '".$now."' ORDER BY a.datep DESC");
		$rows = array();
		$sum = array();
		foreach (array('InboxOvEventNext' => $next, 'InboxOvEventLast' => $last) as $key => $ev) {
			if (!is_object($ev) || !$ev->datep) {
				continue;
			}
			$d = $this->db->jdate($ev->datep);
			$k = 'Action'.$ev->code;
			$type = (string) $ev->code !== '' && $langs->transnoentities($k) !== $k ? $langs->transnoentities($k) : '';
			$label = trim(dol_string_nohtmltag((string) $ev->label, 1));
			$rows[] = $this->row($langs->transnoentities($key), dol_print_date($d, 'day'), implode(' · ', array_filter(array($this->ago($d), $type, dol_trunc($label, 60)))),
				$key === 'InboxOvEventNext' ? 'info' : '', DOL_URL_ROOT.'/comm/action/card.php?id='.((int) $ev->id));
			$sum[] = $langs->transnoentities($key === 'InboxOvEventNext' ? 'InboxOvSumNext' : 'InboxOvSumLastEvent', dol_print_date($d, '%d/%m/%y'));
		}
		if (is_object($next) && $next->datep) {
			$this->alerts[] = array('label' => $langs->transnoentities('InboxOvAlertEvent', dol_print_date($this->db->jdate($next->datep), '%d/%m')), 'level' => 'info');
		}

		return $rows ? $this->makeSection('agenda', $langs->transnoentities('InboxOvSecAgenda'), implode(' · ', $sum), $rows, array('url' => DOL_URL_ROOT.'/comm/action/list.php?socid='.((int) $s))) : null;
	}

	/**
	 * Projects: the open ones (three named), the opportunities still open
	 *
	 * @param User $user User
	 * @param int  $s    Thirdparty
	 * @return array<string,mixed>|null Null when there is none
	 */
	protected function projects(User $user, $s)
	{
		global $langs;

		$p = $this->db->prefix();
		$where = "pj.fk_soc = ".((int) $s)." AND pj.entity IN (".getEntity('project').") AND pj.fk_statut = 1";
		// Without the right on every project: the public ones only
		if (!$user->hasRight('projet', 'all', 'lire')) {
			$where .= " AND pj.public = 1";
		}
		$opp = "pj.usage_opportunity = 1 AND (ls.code IS NULL OR ls.code NOT IN ('WON', 'LOST'))";
		$tot = $this->db->getRow("SELECT COUNT(*) as n, SUM(CASE WHEN ".$opp." THEN 1 ELSE 0 END) as n_opp, SUM(CASE WHEN ".$opp." THEN COALESCE(pj.opp_amount, 0) ELSE 0 END) as opp"
			." FROM ".$p."projet as pj LEFT JOIN ".$p."c_lead_status as ls ON ls.rowid = pj.fk_opp_status WHERE ".$where);
		if (!is_object($tot) || !(int) $tot->n) {
			return null;
		}
		$list = DOL_URL_ROOT.'/projet/list.php?socid='.((int) $s);
		$rows = array($this->row($langs->transnoentities('InboxOvProjectOpen'), (string) (int) $tot->n, '', 'info', $list.'&search_status=1'));
		$sum = array($langs->transnoentities('InboxOvSumProjects', (int) $tot->n));
		if ((int) $tot->n_opp && (float) $tot->opp > 0) {
			$rows[] = $this->row($langs->transnoentities('InboxOvProjectOpp'), $this->money($tot->opp), $langs->transnoentities('InboxOvProjectOppNote', (int) $tot->n_opp), 'info');
			$sum[] = $this->money($tot->opp);
		}
		$found = $this->db->getRows("SELECT pj.rowid, pj.ref, pj.title, pj.dateo FROM ".$p."projet as pj WHERE ".$where." ORDER BY pj.rowid DESC".$this->db->plimit(3));
		foreach (is_array($found) ? $found : array() as $r) {
			$rows[] = $this->row(dol_trunc(trim(dol_string_nohtmltag((string) $r->title, 1)), 60), $r->dateo ? dol_print_date($this->db->jdate($r->dateo), '%d/%m/%y') : '', (string) $r->ref, '',
				DOL_URL_ROOT.'/projet/card.php?id='.((int) $r->rowid), true);
		}

		return $this->makeSection('projects', $langs->transnoentities('InboxOvSecProjects'), implode(' · ', $sum), $rows, array('url' => $list));
	}

	/**
	 * Contracts: services running, the next one to end, the ones that ended without being closed
	 *
	 * @param int $s Thirdparty
	 * @return array<string,mixed>|null Null when there is none
	 */
	protected function contracts($s)
	{
		global $langs;

		$p = $this->db->prefix();
		$now = $this->db->idate(dol_now());
		// Lines of services running (4) of the validated contracts
		$tot = $this->db->getRow("SELECT COUNT(DISTINCT c.rowid) as n, COUNT(cd.rowid) as n_lines, SUM(CASE WHEN cd.date_fin_validite < '".$now."' THEN 1 ELSE 0 END) as n_expired,"
			." MIN(CASE WHEN cd.date_fin_validite >= '".$now."' THEN cd.date_fin_validite ELSE NULL END) as next_end"
			." FROM ".$p."contrat as c INNER JOIN ".$p."contratdet as cd ON cd.fk_contrat = c.rowid AND cd.statut = 4"
			." WHERE c.fk_soc = ".((int) $s)." AND c.entity IN (".getEntity('contract').") AND c.statut = 1");
		if (!is_object($tot) || !(int) $tot->n) {
			return null;
		}
		$list = DOL_URL_ROOT.'/contrat/list.php?socid='.((int) $s);
		$rows = array($this->row($langs->transnoentities('InboxOvContractActive'), (string) (int) $tot->n, $langs->transnoentities('InboxOvContractLines', (int) $tot->n_lines), 'info', $list));
		$sum = array($langs->transnoentities('InboxOvSumContracts', (int) $tot->n));
		if ($tot->next_end) {
			$d = $this->db->jdate($tot->next_end);
			$rows[] = $this->row($langs->transnoentities('InboxOvContractNextEnd'), dol_print_date($d, 'day'), $this->ago($d));
			$sum[] = $langs->transnoentities('InboxOvSumEnd', dol_print_date($d, '%d/%m/%y'));
		}
		if ((int) $tot->n_expired) {
			$rows[] = $this->row($langs->transnoentities('InboxOvContractExpired'), (string) (int) $tot->n_expired, '', 'warning');
			$this->alerts[] = array('label' => $langs->transnoentities('InboxOvAlertContract', (int) $tot->n_expired), 'level' => 'warning');
		}

		return $this->makeSection('contracts', $langs->transnoentities('InboxOvSecContracts'), implode(' · ', $sum), $rows, array('url' => $list, 'sumLevel' => (int) $tot->n_expired ? 'warning' : ''));
	}

	/**
	 * Interventions: the ones in progress, the last one
	 *
	 * @param int $s Thirdparty
	 * @return array<string,mixed>|null Null when there is none
	 */
	protected function interventions($s)
	{
		global $langs;

		$tot = $this->db->getRow("SELECT COUNT(*) as n, SUM(CASE WHEN fi.fk_statut = 1 THEN 1 ELSE 0 END) as n_open, MAX(COALESCE(fi.dateo, fi.date_valid, fi.datec)) as last_date"
			." FROM ".$this->db->prefix()."fichinter as fi WHERE fi.fk_soc = ".((int) $s)." AND fi.entity IN (".getEntity('intervention').") AND fi.fk_statut > 0");
		if (!is_object($tot) || !(int) $tot->n) {
			return null;
		}
		$list = DOL_URL_ROOT.'/fichinter/list.php?socid='.((int) $s);
		$open = (int) $tot->n_open;
		$rows = array($this->row($langs->transnoentities('InboxOvInterOpen'), (string) $open, '', $open ? 'info' : '', $list.'&search_status=1'));
		$note = '';
		if ($tot->last_date) {
			$d = $this->db->jdate($tot->last_date);
			$note = $langs->transnoentities('InboxOvTicketLast', dol_print_date($d, 'day'), $this->ago($d));
		}
		$rows[] = $this->row($langs->transnoentities('InboxOvInterTotal'), (string) (int) $tot->n, $note);

		return $this->makeSection('interventions', $langs->transnoentities('InboxOvSecInterventions'), $langs->transnoentities('InboxOvSumTickets', $open, (int) $tot->n), $rows, array('url' => $list));
	}

	/**
	 * What they buy most: products of the invoices of the last months, by amount
	 *
	 * @param int $s Thirdparty
	 * @return array<string,mixed>|null Null when there is none
	 */
	protected function products($s)
	{
		global $langs;

		$p = $this->db->prefix();
		$since = $this->db->idate(dol_time_plus_duree(dol_now(), -self::TOP_PRODUCTS_MONTHS, 'm'));
		$found = $this->db->getRows("SELECT pd.rowid, pd.ref, pd.label, SUM(d.total_ht) as ht, SUM(d.qty) as qty FROM ".$p."facturedet as d"
			." INNER JOIN ".$p."facture as f ON f.rowid = d.fk_facture INNER JOIN ".$p."product as pd ON pd.rowid = d.fk_product"
			." WHERE ".$this->revenueWhere('f', $s)." AND f.datef >= '".$since."' AND pd.entity IN (".getEntity('product').")"
			." GROUP BY pd.rowid, pd.ref, pd.label HAVING SUM(d.total_ht) > 0 ORDER BY ht DESC".$this->db->plimit(self::TOP_PRODUCTS));
		$rows = array();
		$names = array();
		foreach (is_array($found) ? $found : array() as $r) {
			$label = (string) ($r->label !== '' && $r->label !== null ? $r->label : $r->ref);
			$names[] = dol_trunc($label, 30);
			$rows[] = $this->row(dol_trunc($label, 48), $this->money($r->ht), $r->ref.' · '.$langs->transnoentities('InboxOvQty', $this->number($r->qty)), '', DOL_URL_ROOT.'/product/card.php?id='.((int) $r->rowid), true);
		}

		return $rows ? $this->makeSection('products', $langs->transnoentities('InboxOvSecProducts', self::TOP_PRODUCTS_MONTHS), $names[0], $rows) : null;
	}

	/**
	 * A supplier: what we buy from them, orders in progress, invoices to pay
	 *
	 * @param User $user User
	 * @param int  $s    Thirdparty
	 * @return array<string,mixed>|null
	 */
	protected function supplier(User $user, $s)
	{
		global $langs;

		$p = $this->db->prefix();
		$rows = array();
		$sum = array();
		if ($this->can($user, 'supplier_invoice', array(array('fournisseur', 'facture', 'lire'), array('supplier_invoice', 'lire')))) {
			$year = (int) dol_print_date(dol_now(), '%Y');
			$base = "ff.fk_soc = ".((int) $s)." AND ff.entity IN (".getEntity('supplier_invoice').")";
			$list = DOL_URL_ROOT.'/fourn/facture/list.php?socid='.((int) $s);
			$tot = $this->db->getRow("SELECT COUNT(*) as n, SUM(ff.total_ht) as ht,"
				." SUM(CASE WHEN ff.datef >= '".$this->db->idate(dol_mktime(0, 0, 0, 1, 1, $year))."' THEN ff.total_ht ELSE 0 END) as ht_year,"
				." SUM(CASE WHEN ff.datef >= '".$this->db->idate(dol_mktime(0, 0, 0, 1, 1, $year - 1))."' AND ff.datef < '".$this->db->idate(dol_mktime(0, 0, 0, 1, 1, $year))."' THEN ff.total_ht ELSE 0 END) as ht_prev,"
				." MAX(ff.datef) as last_date FROM ".$p."facture_fourn as ff WHERE ".$base." AND ff.fk_statut IN (1, 2) AND ff.type IN (0, 1, 2)");
			if (is_object($tot) && (int) $tot->n) {
				$rows[] = $this->row($langs->transnoentities('InboxOvPurchaseYear', $year), $this->money($tot->ht_year), $langs->transnoentities('InboxOvPurchasePrev', $year - 1, $this->money($tot->ht_prev)), '', $list);
				$rows[] = $this->row($langs->transnoentities('InboxOvPurchaseTotal'), $this->money($tot->ht), $this->plural((int) $tot->n, 'InboxOvNInvoice'));
				$sum[] = $langs->transnoentities('InboxOvSumPurchase', $year, $this->money($tot->ht_year));
				$this->kpis[15] = array('label' => $langs->transnoentities('InboxOvKpiPurchase', $year), 'value' => $this->money($tot->ht_year),
					'note' => $langs->transnoentities('InboxOvKpiRevenueNote', $year - 1, $this->money($tot->ht_prev)), 'level' => '');
				if ($tot->last_date) {
					$d = $this->db->jdate($tot->last_date);
					$rows[] = $this->row($langs->transnoentities('InboxOvPurchaseLast'), dol_print_date($d, 'day'), $this->ago($d));
				}
			}
			$rest = "(ff.total_ttc - COALESCE((SELECT SUM(pf.amount) FROM ".$p."paiementfourn_facturefourn as pf WHERE pf.fk_facturefourn = ff.rowid), 0))";
			$today = $this->db->idate(dol_get_first_hour(dol_now()));
			$open = $this->db->getRow("SELECT COUNT(*) as n, SUM(".$rest.") as amount, SUM(CASE WHEN ff.date_lim_reglement < '".$today."' THEN 1 ELSE 0 END) as n_late"
				." FROM ".$p."facture_fourn as ff WHERE ".$base." AND ff.fk_statut = 1 AND ff.paye = 0 AND ff.type IN (0, 1, 3)");
			if (is_object($open) && (int) $open->n) {
				$nLate = (int) $open->n_late;
				$rows[] = $this->row($langs->transnoentities('InboxOvPurchaseToPay'), $this->money($open->amount),
					$this->plural((int) $open->n, 'InboxOvNInvoice').' · '.($nLate ? $langs->transnoentities('InboxOvLateCount', $nLate) : $langs->transnoentities('InboxOvInclTax')),
					$nLate ? 'warning' : 'info', $list.'&search_status=1');
				$sum[] = $langs->transnoentities('InboxOvSumToPay', $this->money($open->amount));
				$this->kpis[25] = array('label' => $langs->transnoentities('InboxOvKpiToPay'), 'value' => $this->money($open->amount),
					'note' => $nLate ? $langs->transnoentities('InboxOvLateCount', $nLate) : $this->plural((int) $open->n, 'InboxOvNInvoice'), 'level' => $nLate ? 'warning' : '');
			}
		}
		if ($this->can($user, 'supplier_order', array(array('fournisseur', 'commande', 'lire'), array('supplier_order', 'lire')))) {
			// Validated, approved, ordered, partly received
			$open = $this->db->getRow("SELECT COUNT(*) as n, SUM(cf.total_ht) as ht FROM ".$p."commande_fournisseur as cf WHERE cf.fk_soc = ".((int) $s)
				." AND cf.entity IN (".getEntity('supplier_order').") AND cf.fk_statut IN (1, 2, 3, 4)");
			if (is_object($open) && (int) $open->n) {
				$rows[] = $this->row($langs->transnoentities('InboxOvPurchaseOrders'), $this->money($open->ht), $this->plural((int) $open->n, 'InboxOvNOrder'), 'info',
					DOL_URL_ROOT.'/fourn/commande/list.php?socid='.((int) $s).'&search_status=1,2,3,4');
			}
			// Ordered (3) or partly received (4), and the delivery date is over
			$late = $this->db->getRow("SELECT COUNT(*) as n, MIN(cf.date_livraison) as oldest FROM ".$p."commande_fournisseur as cf WHERE cf.fk_soc = ".((int) $s)
				." AND cf.entity IN (".getEntity('supplier_order').") AND cf.fk_statut IN (3, 4) AND cf.date_livraison IS NOT NULL AND cf.date_livraison < '".$this->db->idate(dol_get_first_hour(dol_now()))."'");
			if (is_object($late) && (int) $late->n) {
				$days = max(1, (int) round((dol_get_first_hour(dol_now()) - $this->db->jdate($late->oldest)) / 86400));
				$rows[] = $this->row($langs->transnoentities('InboxOvReceptionLate'), $this->plural((int) $late->n, 'InboxOvNOrder'), $langs->transnoentities('InboxOvInvoiceLateNote', $days), 'warning',
					DOL_URL_ROOT.'/fourn/commande/list.php?socid='.((int) $s).'&search_status=3,4');
				$this->alerts[] = array('label' => $langs->transnoentities('InboxOvAlertReception', $days), 'level' => 'warning');
				$sum[] = $langs->transnoentities('InboxOvSumReceptionLate', (int) $late->n);
			}
		}
		// What we buy most from them
		if ($this->can($user, 'supplier_invoice', array(array('fournisseur', 'facture', 'lire'), array('supplier_invoice', 'lire'))) && $this->can($user, 'product', array(array('produit', 'lire'))) && $this->shown('PRODUCTS')) {
			$since = $this->db->idate(dol_time_plus_duree(dol_now(), -self::TOP_PRODUCTS_MONTHS, 'm'));
			$found = $this->db->getRows("SELECT pd.rowid, pd.ref, pd.label, SUM(d.total_ht) as ht, SUM(d.qty) as qty FROM ".$p."facture_fourn_det as d"
				." INNER JOIN ".$p."facture_fourn as ff ON ff.rowid = d.fk_facture_fourn INNER JOIN ".$p."product as pd ON pd.rowid = d.fk_product"
				." WHERE ff.fk_soc = ".((int) $s)." AND ff.entity IN (".getEntity('supplier_invoice').") AND ff.fk_statut IN (1, 2) AND ff.datef >= '".$since."' AND pd.entity IN (".getEntity('product').")"
				." GROUP BY pd.rowid, pd.ref, pd.label HAVING SUM(d.total_ht) > 0 ORDER BY ht DESC".$this->db->plimit(self::TOP_PRODUCTS));
			if (is_array($found) && $found) {
				$rows[] = $this->row($langs->transnoentities('InboxOvSecProducts', self::TOP_PRODUCTS_MONTHS), '');
				foreach ($found as $r) {
					$label = (string) ($r->label !== '' && $r->label !== null ? $r->label : $r->ref);
					$rows[] = $this->row(dol_trunc($label, 48), $this->money($r->ht), $r->ref.' · '.$langs->transnoentities('InboxOvQty', $this->number($r->qty)), '', DOL_URL_ROOT.'/product/card.php?id='.((int) $r->rowid), true);
				}
			}
		}

		return $rows ? $this->makeSection('supplier', $langs->transnoentities('InboxOvSecSupplier'), implode(' · ', $sum), $rows, array('open' => true)) : null;
	}

	// ---------- Texts ----------

	/**
	 * One section of the block
	 *
	 * @param string                         $id    Id
	 * @param string                         $title Title
	 * @param string                         $sum   One line read when the section is folded
	 * @param array<int,array<string,mixed>> $rows  Lines
	 * @param array<string,mixed>            $more  url, note, open, sumLevel, hero, chart, spark, season, mails, mailsLabel
	 * @return array<string,mixed>
	 */
	protected function makeSection($id, $title, $sum, array $rows, array $more = array())
	{
		return array_filter($more, function ($v) {
			return $v !== null && $v !== '';
		}) + array('id' => (string) $id, 'title' => (string) $title, 'sum' => (string) $sum, 'sumLevel' => '', 'open' => false, 'rows' => $rows);
	}

	/**
	 * One line of a section
	 *
	 * @param string $label Label
	 * @param string $value Value
	 * @param string $note  Detail under the value
	 * @param string $level '', ok, info, warning or danger
	 * @param string $url   Address inside Dolibarr
	 * @param bool   $item  A named thing (ticket, project, product) rather than a figure: its label is the text read
	 * @return array{label:string,value:string,note:string,level:string,url:string,item:bool}
	 */
	protected function row($label, $value, $note = '', $level = '', $url = '', $item = false)
	{
		return array('label' => (string) $label, 'value' => (string) $value, 'note' => (string) $note, 'level' => (string) $level, 'url' => (string) $url, 'item' => (bool) $item);
	}

	/**
	 * A count with its noun, singular or plural: keys <key>1 and <key>N
	 *
	 * @param int    $n   Count
	 * @param string $key Start of the two keys
	 * @return string
	 */
	protected function plural($n, $key)
	{
		global $langs;

		return $langs->transnoentities($key.((int) $n > 1 ? 'N' : '1'), (int) $n);
	}

	/**
	 * Amount in the main currency, without decimals (as the indicators of Inbox)
	 *
	 * @param float|string|null $amount Amount
	 * @return string
	 */
	protected function money($amount)
	{
		global $langs;

		return price((float) $amount, 0, $langs, 1, -1, 0, 'auto');
	}

	/**
	 * Number with two decimals at most, in the language of the user (quantity, percentage)
	 *
	 * @param float|string|null $v Number
	 * @return string
	 */
	protected function number($v)
	{
		global $langs;

		$dec = $langs->transnoentitiesnoconv('SeparatorDecimal');

		return str_replace('.', $dec === 'SeparatorDecimal' || $dec === '' ? ',' : $dec, (string) round((float) $v, 2));
	}

	/**
	 * Short amount for the top of a bar: 842, 1,2 k, 48 k, 1,3 M
	 *
	 * @param float $v Amount
	 * @return string
	 */
	protected function compact($v)
	{
		global $langs;

		$abs = abs($v);
		if ($abs < 1000) {
			return (string) round($v);
		}
		$dec = $langs->transnoentitiesnoconv('SeparatorDecimal');
		$dec = $dec === 'SeparatorDecimal' || $dec === '' ? ',' : $dec;
		$n = $abs >= 1000000 ? $v / 1000000 : $v / 1000;
		$text = abs($n) >= 100 ? (string) round($n) : rtrim(rtrim(number_format($n, 1, '.', ''), '0'), '.');

		return str_replace('.', $dec, $text).($abs >= 1000000 ? ' M' : ' k');
	}

	/**
	 * How long ago (or in how long, for a date to come), in whole days, months or years
	 *
	 * @param int $ts Date
	 * @return string
	 */
	protected function ago($ts)
	{
		global $langs;

		$days = (int) round((dol_get_first_hour(dol_now()) - dol_get_first_hour($ts)) / 86400);
		if ($days === 0) {
			return $langs->transnoentities('InboxOvToday');
		}
		if ($days === 1) {
			return $langs->transnoentities('InboxOvYesterday');
		}
		$n = abs($days);
		$key = $days > 0 ? 'InboxOvAgo' : 'InboxOvIn';
		if ($n < 60) {
			return $langs->transnoentities($key.'Days', $n);
		}
		if ($n < 730) {
			return $langs->transnoentities($key.'Months', (int) round($n / 30.44));
		}

		return $langs->transnoentities($key.'Years', (int) floor($n / 365.25));
	}
}
