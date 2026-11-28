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
 * \file    inbox/class/inboxwidgets.class.php
 * \ingroup inbox
 * \brief   Registry of the widgets of the Dolibarr panel (1.154.0): templates, sizes, previews, data of one widget
 */

require_once __DIR__.'/inboxoverview.class.php';
require_once __DIR__.'/inboxaccess.class.php';
require_once __DIR__.'/inboxcreate.class.php';

/**
 * Widgets: small pieces of information about the other side of a mail, laid out by each user in the Dolibarr panel
 * (lot WG2). Each widget has a template (how it is drawn), a size (default, minimum, maximum, direction of resizing),
 * a preview with sample values (the catalogue, never computed) and its data, computed for one mail only when it is
 * shown, from the overview (InboxOverview): same sections, same key figures, same rights.
 *
 * Templates: mini (an icon and a number), line (one piece of information), figure (a value, its label, its change),
 * list (lines of a section), chart (bars), action (a list and buttons to create), thread (the notes, drawn by the
 * panel), free (HTML of a module, lot WG4).
 */
class InboxWidgets
{
	/**
	 * Sizes of the templates in the grid of the panel (12 columns since 1.159.0, a row = half a line of list, --ibx-row-h / 2):
	 * w, h = default cells; min_rem = minimum width in rem (each grid turns it into cells); min_h, max_w, max_h in
	 * cells; resize = both, horizontal, vertical or none
	 */
	const TEMPLATES = array(
		'mini' => array('w' => 3, 'h' => 2, 'min_rem' => 3.5, 'min_h' => 2, 'max_w' => 6, 'max_h' => 2, 'resize' => 'horizontal'),
		'line' => array('w' => 12, 'h' => 2, 'min_rem' => 9, 'min_h' => 2, 'max_w' => 12, 'max_h' => 2, 'resize' => 'horizontal'),
		'figure' => array('w' => 6, 'h' => 5, 'min_rem' => 3.5, 'min_h' => 2, 'max_w' => 12, 'max_h' => 8, 'resize' => 'both'),
		'list' => array('w' => 12, 'h' => 8, 'min_rem' => 9, 'min_h' => 4, 'max_w' => 12, 'max_h' => 20, 'resize' => 'both'),
		'chart' => array('w' => 12, 'h' => 8, 'min_rem' => 14, 'min_h' => 6, 'max_w' => 12, 'max_h' => 16, 'resize' => 'both'),
		'action' => array('w' => 12, 'h' => 7, 'min_rem' => 9, 'min_h' => 4, 'max_w' => 12, 'max_h' => 14, 'resize' => 'both'),
		'thread' => array('w' => 12, 'h' => 12, 'min_rem' => 14, 'min_h' => 8, 'max_w' => 12, 'max_h' => 40, 'resize' => 'vertical'),
		'free' => array('w' => 12, 'h' => 4, 'min_rem' => 3.5, 'min_h' => 2, 'max_w' => 12, 'max_h' => 20, 'resize' => 'both'),
	);
	/**
	 * Columns of the grid: 12, divided by 2, 3, 4 and 6 (as many widgets of the same width on a line). 4 before
	 * 1.159.0: a layout saved without « cols » is read with its places and widths scaled to 12
	 */
	const COLUMNS = 12;

	/**
	 * Widgets of inbox: template, translation key of the title, the section of the overview whose right and switch
	 * they follow (null: always), and what they are made of (kpi: key figure by priority; icon: of a mini)
	 */
	const WIDGETS = array(
		'invoices_late' => array('template' => 'mini', 'title' => 'InboxWgInvoicesLate', 'section' => 'invoices', 'icon' => 'warning'),
		'tickets_open' => array('template' => 'mini', 'title' => 'InboxWgTicketsOpen', 'section' => 'tickets', 'icon' => 'tasks'),
		'event_next' => array('template' => 'mini', 'title' => 'InboxWgEventNext', 'section' => 'agenda', 'icon' => 'today'),
		'mails_count' => array('template' => 'mini', 'title' => 'InboxWgExchangedCount', 'section' => 'mails', 'icon' => 'mail'),
		'customer_since' => array('template' => 'line', 'title' => 'InboxWgCustomerSince', 'section' => 'identity', 'icon' => 'company'),
		'figure_revenue' => array('template' => 'figure', 'title' => 'InboxWgFigureRevenue', 'section' => 'revenue', 'kpi' => 10),
		'figure_due' => array('template' => 'figure', 'title' => 'InboxWgFigureDue', 'section' => 'invoices', 'kpi' => 20),
		'figure_last_order' => array('template' => 'figure', 'title' => 'InboxWgFigureLastOrder', 'section' => 'orders', 'kpi' => 30),
		'figure_prev_mail' => array('template' => 'figure', 'title' => 'InboxWgFigurePrevMail', 'section' => 'mails', 'kpi' => 40),
		'figure_purchases' => array('template' => 'figure', 'title' => 'InboxWgFigurePurchases', 'section' => 'supplier', 'kpi' => 15),
		'figure_to_pay' => array('template' => 'figure', 'title' => 'InboxWgFigureToPay', 'section' => 'supplier', 'kpi' => 25),
		'list_invoices' => array('template' => 'list', 'title' => 'InboxOvSecInvoices', 'section' => 'invoices'),
		'list_orders' => array('template' => 'list', 'title' => 'InboxOvSecOrders', 'section' => 'orders'),
		'list_proposals' => array('template' => 'list', 'title' => 'InboxOvSecProposals', 'section' => 'proposals'),
		'list_supplier' => array('template' => 'list', 'title' => 'InboxOvSecSupplier', 'section' => 'supplier'),
		'list_projects' => array('template' => 'list', 'title' => 'InboxOvSecProjects', 'section' => 'projects'),
		'list_contracts' => array('template' => 'list', 'title' => 'InboxOvSecContracts', 'section' => 'contracts'),
		'list_interventions' => array('template' => 'list', 'title' => 'InboxOvSecInterventions', 'section' => 'interventions'),
		'list_card' => array('template' => 'list', 'title' => 'InboxOvSecIdentity', 'section' => 'identity'),
		'chart_revenue' => array('template' => 'chart', 'title' => 'InboxWgChartRevenue', 'section' => 'revenue'),
		'chart_mails' => array('template' => 'chart', 'title' => 'InboxWgChartMails', 'section' => 'mails'),
		'action_tickets' => array('template' => 'action', 'title' => 'InboxWgActionTickets', 'section' => 'tickets'),
		'action_proposals' => array('template' => 'action', 'title' => 'InboxWgActionProposals', 'section' => 'proposals'),
		'action_invoices' => array('template' => 'action', 'title' => 'InboxWgActionInvoices', 'section' => 'invoices'),
		'action_orders' => array('template' => 'action', 'title' => 'InboxWgActionOrders', 'section' => 'orders'),
		// The buttons of the other modules (hook inboxMailActions), drawn by the panel from its own data
		'mail_actions' => array('template' => 'action', 'title' => 'InboxModuleActions', 'section' => null, 'local' => true),
		'notes' => array('template' => 'thread', 'title' => 'InboxNotesAndReplies', 'section' => null),
	);

	/**
	 * Quick actions: the section listed and the piece created for the thirdparty (right: module, permission)
	 */
	const ACTIONS = array(
		'action_tickets' => array('section' => 'tickets', 'right' => array('ticket', 'write'), 'label' => 'InboxWgCreateTicket', 'url' => '/ticket/card.php?action=create&socid='),
		'action_proposals' => array('section' => 'proposals', 'right' => array('propal', 'creer'), 'label' => 'InboxWgCreateProposal', 'url' => '/comm/propal/card.php?action=create&socid='),
		'action_invoices' => array('section' => 'invoices', 'right' => array('facture', 'creer'), 'label' => 'InboxWgCreateInvoice', 'url' => '/compta/facture/card.php?action=create&socid='),
		'action_orders' => array('section' => 'orders', 'right' => array('commande', 'creer'), 'label' => 'InboxWgCreateOrder', 'url' => '/commande/card.php?action=create&socid='),
	);
	/**
	 * A block of another module (hook inboxMailPanels) placed as a widget: « panel: » and the id of the block. The
	 * blocks and the module actions show at the end of the grid as long as the user did not take them away (« off »)
	 */
	const PANEL_ID = '/^panel:[a-z0-9_-]{1,40}$/';
	/** A widget declared by a module (hook inboxWidgets, 1.162.0): « mod: », its source, its id */
	const MOD_ID = '/^mod:[a-z0-9_]{1,40}:[a-z0-9_-]{1,40}$/';

	/** @var array<int,array<string,array<string,mixed>>> Widgets of the modules, by user, read once per request */
	protected static $modules = array();

	/** Widgets read in one call at most */
	const BATCH_MAX = 30;
	/** Widgets in a layout at most */
	const LAYOUT_MAX = 40;
	/** Versions of a layout: by the width of the screen, never by the device (small: under 64 rem) */
	const VERSIONS = array('desktop', 'mobile');
	/**
	 * Presets of the usage of the mailbox (InboxSituation::USAGES), the layout of who has none and when the mailbox has
	 * no model: kept small on purpose, the panel shows the least by default and each user builds what he needs
	 */
	const PRESETS = array(
		'sales' => array(array('invoices_late', 0, 0), array('event_next', 3, 0), array('mails_count', 6, 0)),
		'accounting' => array(array('invoices_late', 0, 0), array('figure_due', 0, 2)),
		'support' => array(array('tickets_open', 0, 0), array('mails_count', 3, 0)),
		'purchase' => array(array('figure_to_pay', 0, 0)),
		'transport' => array(),
	);

	/** @var DoliDB */
	protected $db;
	/** @var InboxOverview */
	protected $overview;
	/** @var array<string,array<string,mixed>|null> Sections already computed for the mail of this call */
	protected $sections = array();
	/** @var array<int,array<string,mixed>>|null Key figures already computed for the mail of this call */
	protected $figures = null;

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct(DoliDB $db)
	{
		$this->db = $db;
		$this->overview = new InboxOverview($db);
	}

	/**
	 * Size of a widget: the one of its template
	 *
	 * @param string $id Widget
	 * @return array{w:int,h:int,min_rem:float,min_h:int,max_w:int,max_h:int,resize:string}
	 */
	public static function size($id)
	{
		return self::TEMPLATES[self::WIDGETS[$id]['template'] ?? 'free'];
	}

	/**
	 * A widget a layout may hold: one of the registry, or a block of a module
	 *
	 * @param string $id Widget
	 * @return bool
	 */
	public static function known($id)
	{
		return isset(self::WIDGETS[$id]) || preg_match(self::PANEL_ID, $id) || preg_match(self::MOD_ID, $id);
	}

	/**
	 * Size of a widget of the registry or of a module (its template); a block of a module: free
	 *
	 * @param string $id Widget
	 * @return array{w:int,h:int,min_rem:float,min_h:int,max_w:int,max_h:int,resize:string}
	 */
	public function sizeOf($id)
	{
		global $user;

		if (preg_match(self::MOD_ID, (string) $id) && $user instanceof User) {
			$m = $this->modules($user)[$id] ?? null;
			return self::TEMPLATES[$m ? $m['template'] : 'free'];
		}

		return self::size($id);
	}

	/**
	 * Widgets the modules declare (hook inboxWidgets, context inbox, 1.162.0), the ones the user may see; read once
	 *
	 * @param User $user User
	 * @return array<string,array<string,mixed>> Id (mod:source:id) => definition
	 */
	public function modules(User $user)
	{
		global $hookmanager;

		$key = (int) $user->id;
		if (!isset(self::$modules[$key])) {
			self::$modules[$key] = array();
			if (!is_object($hookmanager)) {
				include_once DOL_DOCUMENT_ROOT.'/core/class/hookmanager.class.php';
				$hookmanager = new HookManager($this->db);
			}
			$hookmanager->initHooks(array('inbox'));
			$parameters = array('user' => $user, 'capabilities' => InboxCreate::CAPABILITIES, 'templates' => array_keys(self::TEMPLATES));
			$object = null;
			$action = '';
			if ($hookmanager->executeHooks('inboxWidgets', $parameters, $object, $action) >= 0 && is_array($hookmanager->resArray['widgets'] ?? null)) {
				self::$modules[$key] = self::normalize($user, $hookmanager->resArray['widgets']);
			}
		}

		return self::$modules[$key];
	}

	/**
	 * Widgets declared by modules, checked: source and id, a template of inbox (not the thread), a title, an address
	 * of this site for the data, the rights asked; « replaces » a block of a module (panel:<id>), « auto »: shown by
	 * itself at the end of the grid as long as the user did not place nor take it away
	 *
	 * @param User                    $user     User
	 * @param array<int|string,mixed> $declared Declared
	 * @return array<string,array<string,mixed>>
	 */
	public static function normalize(User $user, array $declared)
	{
		$out = array();
		foreach ($declared as $w) {
			$source = is_array($w) ? (string) ($w['source'] ?? '') : '';
			$id = is_array($w) ? (string) ($w['id'] ?? '') : '';
			$tpl = is_array($w) ? (string) ($w['template'] ?? '') : '';
			$url = is_array($w) ? (string) ($w['url'] ?? '') : '';
			if (!preg_match('/^[a-z0-9_]{1,40}$/', $source) || !preg_match('/^[a-z0-9_-]{1,40}$/', $id) || !isset(self::TEMPLATES[$tpl]) || $tpl === 'thread'
				|| empty($w['title']) || !InboxCreate::localUrl($url) || !InboxCreate::allowed($user, $w['rights'] ?? array())) {
				continue;
			}
			$key = 'mod:'.$source.':'.$id;
			if (isset($out[$key])) {
				continue;
			}
			$replaces = (string) ($w['replaces'] ?? '');
			$out[$key] = array('id' => $key, 'template' => $tpl, 'title' => dol_trunc((string) $w['title'], 60, 'right', 'UTF-8', 1),
				'icon' => preg_match('/^[a-z0-9-]{1,40}$/', (string) ($w['icon'] ?? '')) ? (string) $w['icon'] : 'magic',
				'url' => $url, 'preview' => is_array($w['preview'] ?? null) ? $w['preview'] : array(), 'auto' => !empty($w['auto']),
				'replaces' => preg_match(self::PANEL_ID, $replaces) ? $replaces : '', 'source' => $source);
		}

		return $out;
	}

	/**
	 * A layout where blocks of modules became widgets of theirs (« replaces »): the block's place, size and removal
	 * carried to the widget, unless the widget is already there
	 *
	 * @param array<string,mixed>  $layout Layout
	 * @param array<string,string> $map    Block (panel:<id>) => widget (mod:source:id)
	 * @return array<string,mixed>
	 */
	public static function replaceIds(array $layout, array $map)
	{
		if (!$map) {
			return $layout;
		}
		$ids = array_column(is_array($layout['items'] ?? null) ? $layout['items'] : array(), 'id');
		foreach (is_array($layout['items'] ?? null) ? $layout['items'] : array() as $k => $it) {
			$to = is_array($it) ? ($map[(string) ($it['id'] ?? '')] ?? '') : '';
			if ($to !== '') {
				if (in_array($to, $ids, true)) {
					unset($layout['items'][$k]);
				} else {
					$layout['items'][$k]['id'] = $to;
					$ids[] = $to;
				}
			}
		}
		$layout['items'] = array_values($layout['items'] ?? array());
		if (is_array($layout['off'] ?? null)) {
			$layout['off'] = array_values(array_unique(array_map(function ($id) use ($map) {
				return $map[(string) $id] ?? (string) $id;
			}, $layout['off'])));
		}

		return $layout;
	}

	/**
	 * Catalogue of the widgets the user may place: those whose section he may read in a thirdparty that is both a
	 * customer and a supplier (the panel of each mail then shows only what applies), with their size and a preview
	 *
	 * @param User $user User
	 * @return list<array<string,mixed>>
	 */
	public function catalogue(User $user)
	{
		global $langs;

		if (!InboxOverview::enabled($user)) {
			return array();
		}
		$langs->loadLangs(array('inbox@inbox'));
		$any = (object) array('rowid' => 0, 'client' => 3, 'fournisseur' => 1);
		$offered = $this->overview->offers($user, array('soc' => $any, 'socId' => 1, 'email' => 'x', 'usage' => 'sales'));
		$out = array();
		foreach (self::WIDGETS as $id => $w) {
			if ($w['section'] !== null && !in_array($w['section'], $offered, true)) {
				continue;
			}
			$out[] = array('id' => $id, 'template' => $w['template'], 'title' => $langs->transnoentities($w['title']), 'size' => self::size($id),
				'preview' => $this->preview($id), 'local' => !empty($w['local']));
		}
		// Widgets of the modules (1.162.0): their data read by the panel at their address, never computed here
		foreach ($this->modules($user) as $m) {
			$out[] = array('id' => $m['id'], 'template' => $m['template'], 'title' => $m['title'], 'icon' => $m['icon'], 'size' => self::TEMPLATES[$m['template']],
				'preview' => $m['preview'], 'local' => false, 'module' => true, 'url' => $m['url'], 'auto' => $m['auto'], 'replaces' => $m['replaces']);
		}

		return $out;
	}

	/**
	 * Data of widgets for a mail, each computed only when its section is offered to the user (null otherwise, or
	 * when there is nothing to show): the panel asks the widgets of its layout that are visible
	 *
	 * @param User                $user User
	 * @param array<string,mixed> $ctx  InboxOverview::context()
	 * @param string[]            $ids  Widgets
	 * @return array<string,array<string,mixed>|null>
	 */
	public function data(User $user, array $ctx, array $ids)
	{
		global $langs;

		$langs->loadLangs(array('inbox@inbox', 'companies', 'bills', 'orders', 'propal', 'commercial'));
		$this->sections = array();
		$this->figures = null;
		$offered = $this->overview->offers($user, $ctx);
		$out = array();
		foreach (array_slice(array_values(array_unique(array_map('strval', $ids))), 0, self::BATCH_MAX) as $id) {
			$w = self::WIDGETS[$id] ?? null;
			if (!$w || $w['template'] === 'thread' || !empty($w['local']) || ($w['section'] !== null && !in_array($w['section'], $offered, true))) {
				$out[$id] = null;
				continue;
			}
			$out[$id] = $this->one($user, $ctx, $id, $w);
		}

		return $out;
	}

	/**
	 * @param User                $user User
	 * @param array<string,mixed> $ctx  Context
	 * @param string              $id   Widget
	 * @param array<string,mixed> $w    Definition
	 * @return array<string,mixed>|null
	 */
	protected function one(User $user, array $ctx, $id, array $w)
	{
		global $langs;

		$title = $langs->transnoentities($w['title']);
		switch ($w['template']) {
			case 'figure':
				$k = $this->figure($user, $ctx, (int) $w['kpi']);
				return $k ? array('label' => $k['label'], 'value' => $k['value'], 'note' => $k['note'] ?? '', 'level' => $k['level'] ?? '', 'delta' => $k['delta'] ?? null) : null;
			case 'list':
				$sec = $this->sectionOf($user, $ctx, $w['section']);
				return $sec ? array('title' => $sec['title'], 'sum' => $sec['sum'] ?? '', 'sumLevel' => $sec['sumLevel'] ?? '', 'url' => $sec['url'] ?? '', 'rows' => $sec['rows'] ?? array()) : null;
			case 'chart':
				$sec = $this->sectionOf($user, $ctx, $w['section']);
				$chart = $sec ? ($sec['chart'] ?? ($sec['spark'] ?? null)) : null;
				return $chart ? array('title' => $title, 'chart' => $chart, 'small' => !isset($sec['chart'])) : null;
			case 'action':
				return $this->action($user, $ctx, $id, $title);
			case 'line':
				$sec = $this->sectionOf($user, $ctx, $w['section']);
				$row = $sec && !empty($sec['rows']) ? $sec['rows'][0] : null;
				return $row ? array('icon' => $w['icon'], 'text' => $row['label'].' : '.$row['value'], 'note' => $row['note'] ?? '', 'url' => $sec['url'] ?? '') : null;
			case 'mini':
				return $this->mini($user, $ctx, $id, $w, $title);
		}

		return null;
	}

	/**
	 * A section of the overview, computed once per call
	 *
	 * @param User                $user User
	 * @param array<string,mixed> $ctx  Context
	 * @param string              $sec  Section
	 * @return array<string,mixed>|null
	 */
	protected function sectionOf(User $user, array $ctx, $sec)
	{
		if (!array_key_exists($sec, $this->sections)) {
			$res = $this->overview->section($user, $ctx, $sec);
			$this->sections[$sec] = $res ? $res['section'] : null;
		}

		return $this->sections[$sec];
	}

	/**
	 * @param User                $user User
	 * @param array<string,mixed> $ctx  Context
	 * @param int                 $kpi  Priority of the key figure
	 * @return array<string,mixed>|null
	 */
	protected function figure(User $user, array $ctx, $kpi)
	{
		if ($this->figures === null) {
			$this->figures = $this->overview->figures($user, $ctx);
		}

		return $this->figures[$kpi] ?? null;
	}

	/**
	 * A mini widget: an icon, a number, a short label; a level for what is late
	 *
	 * @param User                $user  User
	 * @param array<string,mixed> $ctx   Context
	 * @param string              $id    Widget
	 * @param array<string,mixed> $w     Definition
	 * @param string              $title Title
	 * @return array<string,mixed>|null
	 */
	protected function mini(User $user, array $ctx, $id, array $w, $title)
	{
		global $langs;

		$p = $this->db->prefix();
		$s = (int) $ctx['socId'];
		switch ($id) {
			case 'invoices_late':
				$r = $this->db->getRow("SELECT COUNT(*) as n FROM ".$p."facture as f WHERE f.fk_soc = ".$s." AND f.entity IN (".getEntity('invoice').") AND f.fk_statut = 1 AND f.paye = 0"
					." AND f.type IN (0, 1, 3, 5) AND f.date_lim_reglement < '".$this->db->idate(dol_get_first_hour(dol_now()))."'");
				$n = is_object($r) ? (int) $r->n : 0;
				return array('icon' => $w['icon'], 'value' => (string) $n, 'label' => $langs->transnoentities('InboxWgLateShort'), 'title' => $title, 'level' => $n ? 'danger' : 'ok',
					'url' => DOL_URL_ROOT.'/compta/facture/list.php?socid='.$s.'&search_status=1&search_option=late');
			case 'tickets_open':
				$sec = $this->sectionOf($user, $ctx, 'tickets');
				$n = $sec && !empty($sec['rows']) ? (int) $sec['rows'][0]['value'] : 0;
				return array('icon' => $w['icon'], 'value' => (string) $n, 'label' => $langs->transnoentities('InboxWgTicketsShort'), 'title' => $title, 'level' => $n ? 'warning' : '',
					'url' => DOL_URL_ROOT.'/ticket/list.php?socid='.$s);
			case 'event_next':
				$where = "a.fk_soc = ".$s." AND a.entity IN (".getEntity('agenda').") AND a.datep >= '".$this->db->idate(dol_now())."'";
				if (!$user->hasRight('agenda', 'allactions', 'read')) {
					$where .= " AND (a.fk_user_author = ".((int) $user->id)." OR a.fk_user_action = ".((int) $user->id).")";
				}
				$r = $this->db->getRow("SELECT MIN(a.datep) as d FROM ".$p."actioncomm as a LEFT JOIN ".$p."c_actioncomm as ca ON ca.id = a.fk_action WHERE ".$where." AND (ca.type IS NULL OR ca.type <> 'systemauto')");
				if (!is_object($r) || !$r->d) {
					return null;
				}
				return array('icon' => $w['icon'], 'value' => dol_print_date($this->db->jdate($r->d), '%d/%m'), 'label' => $langs->transnoentities('InboxWgEventShort'), 'title' => $title, 'level' => 'info',
					'url' => DOL_URL_ROOT.'/comm/action/list.php?socid='.$s);
			case 'mails_count':
				$m = $this->overview->mailCounts($user, $ctx);
				return $m ? array('icon' => $w['icon'], 'value' => (string) ($m['nIn'] + $m['nOut']), 'label' => $langs->transnoentities('InboxWgExchangedShort'), 'title' => $title, 'level' => '',
					'note' => $langs->transnoentities('InboxOvSumMails', $m['nIn'], $m['nOut'])) : null;
		}

		return null;
	}

	/**
	 * An action widget: the open pieces of the section and the button to create one for the thirdparty, when the user
	 * may create it
	 *
	 * @param User                $user  User
	 * @param array<string,mixed> $ctx   Context
	 * @param string              $id    Widget
	 * @param string              $title Title
	 * @return array<string,mixed>|null
	 */
	protected function action(User $user, array $ctx, $id, $title)
	{
		global $langs;

		$def = self::ACTIONS[$id] ?? null;
		if (!$def) {
			return null;
		}
		$sec = $this->sectionOf($user, $ctx, $def['section']);
		$actions = array();
		if ($user->hasRight($def['right'][0], $def['right'][1])) {
			$actions[] = array('label' => $langs->transnoentities($def['label']), 'icon' => 'plus', 'url' => DOL_URL_ROOT.$def['url'].((int) $ctx['socId']));
		}
		if (!$sec && !$actions) {
			return null;
		}

		return array('title' => $sec ? $sec['title'] : $title, 'sum' => $sec['sum'] ?? '', 'sumLevel' => $sec['sumLevel'] ?? '', 'rows' => $sec['rows'] ?? array(), 'actions' => $actions);
	}

	// ---------- Layouts: the user's, the model of the mailbox, the preset of the usage ----------

	/**
	 * Layouts of the Dolibarr panel of a mailbox for a user, for both versions: his own (null when he has none), the
	 * model of the mailbox (null when none), the preset of the usage; whether he may save the model
	 *
	 * @param User $user      User
	 * @param int  $accountId Mailbox
	 * @return array<string,mixed>|null Null when the user cannot read the mailbox
	 */
	public function layouts(User $user, $accountId)
	{
		$access = new InboxAccess($this->db);
		if (!InboxOverview::enabled($user) || !$access->check($user, (int) $accountId)) {
			return null;
		}
		$out = array('manager' => $access->check($user, (int) $accountId, InboxAccess::LEVEL_MANAGE), 'preset' => $this->preset((int) $accountId));
		foreach (self::VERSIONS as $v) {
			$out[$v] = array('user' => $this->stored((int) $user->id, (int) $accountId, 'widgets_'.$v), 'model' => $this->stored(0, (int) $accountId, 'widgets_model_'.$v));
		}

		return $out;
	}

	/**
	 * Save the layout of a user for a mailbox (version desktop or mobile), or remove it (null: back to the model)
	 *
	 * @param User                     $user      User
	 * @param int                      $accountId Mailbox
	 * @param string                   $version   desktop or mobile
	 * @param array<string,mixed>|null $layout    {items: [{id, x, y, w, h}], dock}
	 * @return int 1 saved, 0 nothing to do, -1 not allowed, -2 invalid
	 */
	public function saveUser(User $user, $accountId, $version, $layout)
	{
		if (!in_array($version, self::VERSIONS, true) || !InboxOverview::enabled($user) || !(new InboxAccess($this->db))->check($user, (int) $accountId)) {
			return -1;
		}
		if ($layout === null) {
			return $this->store((int) $user->id, (int) $accountId, 'widgets_'.$version, null);
		}
		$clean = $this->clean($layout);

		return $clean === null ? -2 : $this->store((int) $user->id, (int) $accountId, 'widgets_'.$version, $clean);
	}

	/**
	 * Save the model of a mailbox (its managers), or remove it (null); written in the journal of the mailbox. The
	 * users who have their own layout keep it: they are told the model changed (date of the model)
	 *
	 * @param User                     $user      User
	 * @param int                      $accountId Mailbox
	 * @param string                   $version   desktop or mobile
	 * @param array<string,mixed>|null $layout    Layout
	 * @return int 1 saved, 0 nothing to do, -1 not allowed, -2 invalid
	 */
	public function saveModel(User $user, $accountId, $version, $layout)
	{
		if (!in_array($version, self::VERSIONS, true) || !InboxOverview::enabled($user)
			|| !(new InboxAccess($this->db))->check($user, (int) $accountId, InboxAccess::LEVEL_MANAGE)) {
			return -1;
		}
		$clean = $layout === null ? null : $this->clean($layout);
		if ($layout !== null && $clean === null) {
			return -2;
		}
		$res = $this->store(0, (int) $accountId, 'widgets_model_'.$version, $clean);
		if ($res > 0) {
			require_once __DIR__.'/inboxaccountlog.class.php';
			(new InboxAccountLog($this->db))->add((int) $accountId, $user, 'widgets_model', array('version' => $version),
				$clean === null ? null : array('version' => $version, 'count' => count($clean['items'])));
		}

		return $res;
	}

	/**
	 * Preset of the usage of a mailbox (automatic and none: the one of the sales)
	 *
	 * @param int $accountId Mailbox
	 * @return array{items:list<array{id:string,x:int,y:int,w:int,h:int}>,dock:int,off:list<string>,cols:int,at:int}
	 */
	public function preset($accountId)
	{
		$row = $this->db->getRow("SELECT usage_profile FROM ".$this->db->prefix()."inbox_account WHERE rowid = ".((int) $accountId));
		$usage = is_object($row) ? (string) $row->usage_profile : 'auto';
		$items = array();
		foreach (self::PRESETS[$usage] ?? self::PRESETS['sales'] as $x) {
			$size = self::size($x[0]);
			$items[] = array('id' => $x[0], 'x' => $x[1], 'y' => $x[2], 'w' => $size['w'], 'h' => $size['h']);
		}

		return array('items' => $items, 'dock' => 0, 'off' => array(), 'cols' => self::COLUMNS, 'at' => 0);
	}

	/**
	 * A layout checked: known widgets, each once, inside the grid and the bounds of its template
	 *
	 * @param mixed $layout Layout sent
	 * @return array{items:list<array{id:string,x:int,y:int,w:int,h:int}>,dock:int,off:list<string>,cols:int,at:int}|null
	 */
	protected function clean($layout)
	{
		if (!is_array($layout) || !is_array($layout['items'] ?? null) || count($layout['items']) > self::LAYOUT_MAX) {
			return null;
		}
		// Sent with fewer columns (a page opened before the update): scaled to 12; without « cols », already in 12
		$layout = isset($layout['cols']) ? self::upgrade($layout) : $layout;
		$items = array();
		$seen = array();
		foreach ($layout['items'] as $it) {
			$id = is_array($it) ? (string) ($it['id'] ?? '') : '';
			if (!self::known($id) || isset($seen[$id])) {
				return null;
			}
			$seen[$id] = 1;
			$size = $this->sizeOf($id);
			$w = max(1, min((int) $size['max_w'], (int) ($it['w'] ?? $size['w'])));
			$h = max((int) $size['min_h'], min((int) $size['max_h'], (int) ($it['h'] ?? $size['h'])));
			$x = max(0, min(self::COLUMNS - $w, (int) ($it['x'] ?? 0)));
			$y = max(0, min(1000, (int) ($it['y'] ?? 0)));
			$items[] = array('id' => $id, 'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h);
		}
		// Maximum height of the notes docked at the bottom, in pixels (0: the default)
		$dock = max(0, min(2000, (int) ($layout['dock'] ?? 0)));
		// Module actions and blocks taken away by the user: they no longer show by themselves at the end
		$off = array();
		foreach (is_array($layout['off'] ?? null) ? array_slice($layout['off'], 0, self::LAYOUT_MAX) : array() as $id) {
			$id = (string) $id;
			if (($id === 'mail_actions' || preg_match(self::PANEL_ID, $id) || preg_match(self::MOD_ID, $id)) && !isset($seen[$id]) && !in_array($id, $off, true)) {
				$off[] = $id;
			}
		}

		return array('items' => $items, 'dock' => $dock, 'off' => $off, 'cols' => self::COLUMNS, 'at' => (int) dol_now());
	}

	/**
	 * A layout saved with another number of columns (4 before 1.159.0) in the grid of 12: places and widths scaled,
	 * each widget kept inside the grid
	 *
	 * @param array<string,mixed> $layout Layout
	 * @return array<string,mixed>
	 */
	public static function upgrade(array $layout)
	{
		$cols = (int) ($layout['cols'] ?? 4);
		if ($cols === self::COLUMNS || $cols <= 0 || !is_array($layout['items'] ?? null)) {
			return $layout;
		}
		$f = self::COLUMNS / $cols;
		foreach ($layout['items'] as $k => $it) {
			if (is_array($it)) {
				// Both edges scaled the same way: two widgets side by side stay side by side, never over each other
				$x0 = (int) ($it['x'] ?? 0);
				$x = min(self::COLUMNS - 1, (int) round($x0 * $f));
				$layout['items'][$k]['x'] = $x;
				if (isset($it['w'])) {
					$end = min(self::COLUMNS, (int) round(($x0 + (int) $it['w']) * $f));
					$layout['items'][$k]['w'] = max(1, $end - $x);
				}
			}
		}
		$layout['cols'] = self::COLUMNS;

		return $layout;
	}

	/**
	 * @param int    $userId    User, 0 for the model of the mailbox
	 * @param int    $accountId Mailbox
	 * @param string $param     Name
	 * @return array<string,mixed>|null
	 */
	protected function stored($userId, $accountId, $param)
	{
		$row = $this->db->getRow("SELECT value FROM ".$this->db->prefix()."inbox_userparam WHERE fk_user = ".((int) $userId)." AND fk_account = ".((int) $accountId)
			." AND param = '".$this->db->escape($param)."'");
		$v = is_object($row) ? json_decode((string) $row->value, true) : null;

		if (!is_array($v) || !is_array($v['items'] ?? null)) {
			return null;
		}
		// Blocks of modules now widgets of theirs (« replaces »): their places carried over
		global $user;
		$map = array();
		if ($user instanceof User) {
			foreach ($this->modules($user) as $m) {
				if ($m['replaces'] !== '') {
					$map[$m['replaces']] = $m['id'];
				}
			}
		}

		return self::replaceIds(self::upgrade($v), $map);
	}

	/**
	 * @param int                      $userId    User, 0 for the model of the mailbox
	 * @param int                      $accountId Mailbox
	 * @param string                   $param     Name
	 * @param array<string,mixed>|null $value     Layout, null to remove
	 * @return int 1 done, -3 error of the database
	 */
	protected function store($userId, $accountId, $param, $value)
	{
		$p = $this->db->prefix();
		$where = " WHERE fk_user = ".((int) $userId)." AND fk_account = ".((int) $accountId)." AND param = '".$this->db->escape($param)."'";
		if ($value === null) {
			return $this->db->query("DELETE FROM ".$p."inbox_userparam".$where) ? 1 : -3;
		}
		$json = (string) json_encode($value);
		$exists = $this->db->getRow("SELECT rowid FROM ".$p."inbox_userparam".$where);
		$ok = is_object($exists)
			? $this->db->query("UPDATE ".$p."inbox_userparam SET value = '".$this->db->escape($json)."'".$where)
			: $this->db->query("INSERT INTO ".$p."inbox_userparam (fk_user, fk_account, param, value) VALUES (".((int) $userId).", ".((int) $accountId).", '".$this->db->escape($param)."', '".$this->db->escape($json)."')");

		return $ok ? 1 : -3;
	}

	/**
	 * Preview of a widget in the catalogue: the template with sample values, the same for every user, never computed
	 *
	 * @param string $id Widget
	 * @return array<string,mixed>
	 */
	public function preview($id)
	{
		global $langs;

		$w = self::WIDGETS[$id];
		$title = $langs->transnoentities($w['title']);
		$row = function ($label, $value, $note = '', $level = '') {
			return array('label' => $label, 'value' => $value, 'note' => $note, 'level' => $level, 'url' => '', 'item' => false);
		};
		switch ($w['template']) {
			case 'mini':
				$samples = array('invoices_late' => array('2', 'danger', 'InboxWgLateShort'), 'tickets_open' => array('3', 'warning', 'InboxWgTicketsShort'),
					'event_next' => array('02/12', 'info', 'InboxWgEventShort'), 'mails_count' => array('69', '', 'InboxWgExchangedShort'));
				$x = $samples[$id] ?? array('1', '', '');
				return array('icon' => $w['icon'], 'value' => $x[0], 'level' => $x[1], 'label' => $x[2] !== '' ? $langs->transnoentities($x[2]) : '', 'title' => $title);
			case 'line':
				return array('icon' => $w['icon'], 'text' => $title.' : 03/2019', 'note' => '');
			case 'figure':
				return array('label' => $title, 'value' => '12 345', 'note' => '2025 : 10 980', 'level' => $w['kpi'] === 20 ? 'danger' : '',
					'delta' => $w['kpi'] === 10 ? array('text' => '+12 %', 'level' => 'ok', 'dir' => 'up', 'title' => '') : null);
			case 'chart':
				$bars = array();
				foreach (array(21, 34, 29, 41, 52, 48) as $i => $v) {
					$bars[] = array('x' => (string) (2021 + $i), 'v' => $v, 'short' => (string) $v, 'text' => (string) $v, 'partial' => $i === 5);
				}
				return array('title' => $title, 'chart' => array('label' => $title, 'value' => '', 'hint' => '', 'bars' => $bars), 'small' => $id === 'chart_mails');
			case 'action':
				if ($id === 'mail_actions') {
					return array('title' => $title, 'sum' => '', 'rows' => array(),
						'actions' => array(array('label' => $langs->transnoentities('InboxWgModuleActionSample'), 'icon' => 'magic', 'url' => '')));
				}
				return array('title' => $title, 'sum' => '', 'rows' => array($row('A-0001', '1 200'), $row('A-0002', '860')),
					'actions' => array(array('label' => $langs->transnoentities(self::ACTIONS[$id]['label']), 'icon' => 'plus', 'url' => '')));
			case 'list':
				return array('title' => $title, 'sum' => '', 'rows' => array($row('A-0001', '1 200', '', 'danger'), $row('A-0002', '860'), $row('A-0003', '2 400')));
			case 'thread':
				return array('title' => $title);
		}

		return array();
	}
}
