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
	 * Sizes of the templates in the grid of the panel (4 columns, a row = half a line of list, --ibx-row-h / 2):
	 * w, h = default cells; min_rem = minimum width in rem (each grid turns it into cells); min_h, max_w, max_h in
	 * cells; resize = both, horizontal, vertical or none
	 */
	const TEMPLATES = array(
		'mini' => array('w' => 1, 'h' => 2, 'min_rem' => 3.5, 'min_h' => 2, 'max_w' => 2, 'max_h' => 2, 'resize' => 'horizontal'),
		'line' => array('w' => 4, 'h' => 2, 'min_rem' => 9, 'min_h' => 2, 'max_w' => 4, 'max_h' => 2, 'resize' => 'horizontal'),
		'figure' => array('w' => 2, 'h' => 5, 'min_rem' => 3.5, 'min_h' => 2, 'max_w' => 4, 'max_h' => 8, 'resize' => 'both'),
		'list' => array('w' => 4, 'h' => 8, 'min_rem' => 9, 'min_h' => 4, 'max_w' => 4, 'max_h' => 20, 'resize' => 'both'),
		'chart' => array('w' => 4, 'h' => 8, 'min_rem' => 14, 'min_h' => 6, 'max_w' => 4, 'max_h' => 16, 'resize' => 'vertical'),
		'action' => array('w' => 4, 'h' => 7, 'min_rem' => 9, 'min_h' => 4, 'max_w' => 4, 'max_h' => 14, 'resize' => 'both'),
		'thread' => array('w' => 4, 'h' => 12, 'min_rem' => 14, 'min_h' => 8, 'max_w' => 4, 'max_h' => 40, 'resize' => 'vertical'),
		'free' => array('w' => 4, 'h' => 4, 'min_rem' => 3.5, 'min_h' => 2, 'max_w' => 4, 'max_h' => 20, 'resize' => 'both'),
	);

	/**
	 * Widgets of inbox: template, translation key of the title, the section of the overview whose right and switch
	 * they follow (null: always), and what they are made of (kpi: key figure by priority; icon: of a mini)
	 */
	const WIDGETS = array(
		'invoices_late' => array('template' => 'mini', 'title' => 'InboxWgInvoicesLate', 'section' => 'invoices', 'icon' => 'warning'),
		'tickets_open' => array('template' => 'mini', 'title' => 'InboxWgTicketsOpen', 'section' => 'tickets', 'icon' => 'tasks'),
		'event_next' => array('template' => 'mini', 'title' => 'InboxWgEventNext', 'section' => 'agenda', 'icon' => 'today'),
		'mails_count' => array('template' => 'mini', 'title' => 'InboxWgMailsCount', 'section' => 'mails', 'icon' => 'mail'),
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
		'notes' => array('template' => 'thread', 'title' => 'InboxNotesAndReplies', 'section' => null),
	);

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
		'sales' => array(array('invoices_late', 0, 0), array('event_next', 1, 0), array('mails_count', 2, 0)),
		'accounting' => array(array('invoices_late', 0, 0), array('figure_due', 0, 2)),
		'support' => array(array('tickets_open', 0, 0), array('mails_count', 1, 0)),
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
				'preview' => $this->preview($id));
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
			if (!$w || $w['template'] === 'thread' || ($w['section'] !== null && !in_array($w['section'], $offered, true))) {
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
				return $m ? array('icon' => $w['icon'], 'value' => (string) ($m['nIn'] + $m['nOut']), 'label' => $langs->transnoentities('InboxWgMailsShort'), 'title' => $title, 'level' => '',
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

		$s = (int) $ctx['socId'];
		$sec = $this->sectionOf($user, $ctx, $id === 'action_tickets' ? 'tickets' : 'proposals');
		$actions = array();
		if ($id === 'action_tickets' && $user->hasRight('ticket', 'write')) {
			$actions[] = array('label' => $langs->transnoentities('InboxWgCreateTicket'), 'icon' => 'plus', 'url' => DOL_URL_ROOT.'/ticket/card.php?action=create&socid='.$s);
		}
		if ($id === 'action_proposals' && $user->hasRight('propal', 'creer')) {
			$actions[] = array('label' => $langs->transnoentities('InboxWgCreateProposal'), 'icon' => 'plus', 'url' => DOL_URL_ROOT.'/comm/propal/card.php?action=create&socid='.$s);
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
	 * @return array{items:list<array{id:string,x:int,y:int,w:int,h:int}>,dock:int,at:int}
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

		return array('items' => $items, 'dock' => 0, 'at' => 0);
	}

	/**
	 * A layout checked: known widgets, each once, inside the grid and the bounds of its template
	 *
	 * @param mixed $layout Layout sent
	 * @return array{items:list<array{id:string,x:int,y:int,w:int,h:int}>,dock:int,at:int}|null
	 */
	protected function clean($layout)
	{
		if (!is_array($layout) || !is_array($layout['items'] ?? null) || count($layout['items']) > self::LAYOUT_MAX) {
			return null;
		}
		$items = array();
		$seen = array();
		foreach ($layout['items'] as $it) {
			$id = is_array($it) ? (string) ($it['id'] ?? '') : '';
			if (!isset(self::WIDGETS[$id]) || isset($seen[$id])) {
				return null;
			}
			$seen[$id] = 1;
			$size = self::size($id);
			$w = max(1, min((int) $size['max_w'], (int) ($it['w'] ?? $size['w'])));
			$h = max((int) $size['min_h'], min((int) $size['max_h'], (int) ($it['h'] ?? $size['h'])));
			$x = max(0, min(4 - $w, (int) ($it['x'] ?? 0)));
			$y = max(0, min(1000, (int) ($it['y'] ?? 0)));
			$items[] = array('id' => $id, 'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h);
		}
		// Height of the notes docked at the bottom, in pixels (0: the default)
		$dock = max(0, min(2000, (int) ($layout['dock'] ?? 0)));

		return array('items' => $items, 'dock' => $dock, 'at' => (int) dol_now());
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

		return is_array($v) && is_array($v['items'] ?? null) ? $v : null;
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
					'event_next' => array('02/12', 'info', 'InboxWgEventShort'), 'mails_count' => array('69', '', 'InboxWgMailsShort'));
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
				return array('title' => $title, 'sum' => '', 'rows' => array($row('A-0001', '1 200'), $row('A-0002', '860')),
					'actions' => array(array('label' => $langs->transnoentities($id === 'action_tickets' ? 'InboxWgCreateTicket' : 'InboxWgCreateProposal'), 'icon' => 'plus', 'url' => '')));
			case 'list':
				return array('title' => $title, 'sum' => '', 'rows' => array($row('A-0001', '1 200', '', 'danger'), $row('A-0002', '860'), $row('A-0003', '2 400')));
			case 'thread':
				return array('title' => $title);
		}

		return array();
	}
}
