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
 * \file    inbox/test/phpunit/InboxWidgetsTest.php
 * \ingroup inbox
 * \brief   Registry of the widgets (1.154.0): catalogue by rights, previews never computed, data by the right of the section
 */

if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

require_once __DIR__.'/InboxOverviewTestCase.php';
require_once dirname(__DIR__, 2).'/class/inboxwidgets.class.php';

/**
 * Database only, no mail server
 */
class InboxWidgetsTest extends InboxOverviewTestCase
{
	/**
	 * Every widget has a known template, a size inside its bounds and a preview of its template
	 *
	 * @return void
	 */
	public function testDefinitions()
	{
		$w = new InboxWidgets($this->db);
		foreach (InboxWidgets::WIDGETS as $id => $def) {
			$this->assertArrayHasKey($def['template'], InboxWidgets::TEMPLATES, $id);
			$size = InboxWidgets::size($id);
			$this->assertGreaterThanOrEqual(1, $size['w']);
			$this->assertLessThanOrEqual($size['max_w'], $size['w'], $id);
			$this->assertGreaterThanOrEqual($size['min_h'], $size['h'], $id);
			$this->assertLessThanOrEqual($size['max_h'], $size['h'], $id);
			$this->assertContains($size['resize'], array('both', 'horizontal', 'vertical', 'none'));
			$this->assertIsArray($w->preview($id));
		}
	}

	/**
	 * The catalogue offers only the widgets whose section the user may read; data of a widget not allowed is null
	 *
	 * @return void
	 */
	public function testCatalogueAndDataByRights()
	{
		$box = $this->box(true, array('status' => InboxAccount::STATUS_ACTIVE));
		$withInvoices = $this->withRights('wginv', array(array('facture', 'lire')));
		$noInvoices = $this->withRights('wgnoinv', array());
		$acc = new InboxAccess($this->db);
		$acc->grant($this->admin, $box->id, $withInvoices->id, 0, 'read');
		$acc->grant($this->admin, $box->id, $noInvoices->id, 0, 'read');
		InboxAccess::clearCache();
		$soc = $this->soc('inboxtest_wgsoc', 'compta@wgclient.inbox-test.invalid');
		$this->invoice($soc, true);
		$mail = $this->mail($box->id, 'compta@wgclient.inbox-test.invalid');
		$w = new InboxWidgets($this->db);

		$ids = array_column($w->catalogue($withInvoices), 'id');
		$this->assertContains('invoices_late', $ids);
		$this->assertContains('figure_due', $ids);
		$this->assertContains('notes', $ids, 'the notes need no right of their own');
		$idsNo = array_column($w->catalogue($noInvoices), 'id');
		$this->assertNotContains('invoices_late', $idsNo);
		$this->assertNotContains('chart_revenue', $idsNo);

		$ov = new InboxOverview($this->db);
		$data = $w->data($withInvoices, $ov->context($withInvoices, (int) $mail->rowid), array('invoices_late', 'figure_due', 'list_invoices', 'nope'));
		$this->assertSame('1', $data['invoices_late']['value']);
		$this->assertSame('danger', $data['invoices_late']['level']);
		$this->assertSame('danger', $data['figure_due']['level']);
		$this->assertNotEmpty($data['list_invoices']['rows']);
		$this->assertNull($data['nope'], 'unknown widget');

		$dataNo = $w->data($noInvoices, $ov->context($noInvoices, (int) $mail->rowid), array('invoices_late', 'figure_due'));
		$this->assertNull($dataNo['invoices_late'], 'no right on the invoices: never computed');
		$this->assertNull($dataNo['figure_due']);
	}

	/**
	 * A widget switched off with its section is neither offered nor computed
	 *
	 * @return void
	 */
	public function testSwitchedOff()
	{
		global $conf;

		$w = new InboxWidgets($this->db);
		$conf->global->INBOX_OVERVIEW_HIDE_INVOICES = '1';
		try {
			$ids = array_column($w->catalogue($this->admin), 'id');
			$this->assertNotContains('invoices_late', $ids);
			$this->assertNotContains('list_invoices', $ids);
		} finally {
			unset($conf->global->INBOX_OVERVIEW_HIDE_INVOICES);
		}
	}

	/**
	 * Layouts: the user's for a mailbox he reads, the model by its managers only, checked against the registry
	 *
	 * @return void
	 */
	public function testLayouts()
	{
		$box = $this->box(true, array('status' => InboxAccount::STATUS_ACTIVE));
		$reader = $this->withRights('wglayout', array(array('facture', 'lire')));
		$stranger = $this->withRights('wgstranger', array(array('facture', 'lire')));
		$acc = new InboxAccess($this->db);
		$acc->grant($this->admin, $box->id, $reader->id, 0, 'read');
		$acc->grant($this->admin, $box->id, $this->admin->id, 0, 'manage');
		InboxAccess::clearCache();
		$w = new InboxWidgets($this->db);

		$l = $w->layouts($reader, (int) $box->id);
		$this->assertNull($l['desktop']['user']);
		$this->assertFalse($l['manager']);
		$this->assertSame(array(), $l['preset']['items'], 'no widget by default');
		$this->assertNull($w->layouts($stranger, (int) $box->id), 'a mailbox he cannot read');

		$layout = array('items' => array(array('id' => 'figure_due', 'x' => 3, 'y' => 0, 'w' => 20, 'h' => 50), array('id' => 'invoices_late', 'x' => 0, 'y' => 0)), 'dock' => 240);
		$this->assertSame(1, $w->saveUser($reader, (int) $box->id, 'desktop', $layout));
		$mine = $w->layouts($reader, (int) $box->id)['desktop']['user'];
		$this->assertSame('figure_due', $mine['items'][0]['id']);
		$this->assertSame(12, $mine['items'][0]['w'], 'width kept inside the bounds of its template');
		$this->assertSame(InboxWidgets::COLUMNS, $mine['cols']);
		$this->assertSame(8, $mine['items'][0]['h']);
		$this->assertSame(0, $mine['items'][0]['x'], 'moved back inside the grid');
		$this->assertSame(240, $mine['dock']);
		$this->assertSame(-2, $w->saveUser($reader, (int) $box->id, 'desktop', array('items' => array(array('id' => 'nope')))), 'unknown widget refused');
		$this->assertSame(-2, $w->saveUser($reader, (int) $box->id, 'desktop', array('items' => array(array('id' => 'invoices_late'), array('id' => 'invoices_late')))), 'twice refused');
		$this->assertSame(-1, $w->saveUser($stranger, (int) $box->id, 'desktop', $layout));
		$this->assertSame(-1, $w->saveUser($reader, (int) $box->id, 'tablet', $layout), 'unknown version');

		$this->assertSame(-1, $w->saveModel($reader, (int) $box->id, 'desktop', $layout), 'the model by a manager of the mailbox only');
		$this->assertSame(1, $w->saveModel($this->admin, (int) $box->id, 'desktop', $layout));
		$l = $w->layouts($reader, (int) $box->id);
		$this->assertNotNull($l['desktop']['model']);
		$this->assertNotNull($l['desktop']['user'], 'his own layout is kept when the model changes');
		$this->assertSame(1, $w->saveUser($reader, (int) $box->id, 'desktop', null));
		$this->assertNull($w->layouts($reader, (int) $box->id)['desktop']['user'], 'back to the model');
		$this->assertSame(1, $w->saveModel($this->admin, (int) $box->id, 'desktop', null));
		$this->assertNull($w->layouts($reader, (int) $box->id)['desktop']['model']);

		// A layout saved with 4 columns (before 1.159.0): read with its places and widths scaled to 12
		$old = json_encode(array('items' => array(array('id' => 'event_next', 'x' => 1, 'y' => 0, 'w' => 1, 'h' => 2), array('id' => 'list_invoices', 'x' => 0, 'y' => 2, 'w' => 4, 'h' => 8)), 'dock' => 0, 'at' => 1));
		$this->db->query("INSERT INTO ".$this->db->prefix()."inbox_userparam (fk_user, fk_account, param, value) VALUES (".((int) $reader->id).", ".((int) $box->id).", 'widgets_desktop', '".$this->db->escape($old)."')");
		$mine = $w->layouts($reader, (int) $box->id)['desktop']['user'];
		$this->assertSame(array(3, 3), array($mine['items'][0]['x'], $mine['items'][0]['w']));
		$this->assertSame(array(0, 12), array($mine['items'][1]['x'], $mine['items'][1]['w']));
		$this->assertSame(1, $w->saveUser($reader, (int) $box->id, 'desktop', array('items' => array(array('id' => 'event_next', 'x' => 1, 'y' => 0, 'w' => 1, 'h' => 2)), 'cols' => 4)));
		$this->assertSame(3, $w->layouts($reader, (int) $box->id)['desktop']['user']['items'][0]['x'], 'sent with 4 columns by a page opened before the update');
		$this->assertSame(array('items' => array(array('x' => 11, 'w' => 1)), 'cols' => 12), InboxWidgets::upgrade(array('items' => array(array('x' => 7, 'w' => 1)), 'cols' => 8)), 'kept inside the grid');
		$side = InboxWidgets::upgrade(array('items' => array(array('x' => 2, 'w' => 3), array('x' => 5, 'w' => 3)), 'cols' => 8));
		$this->assertSame($side['items'][0]['x'] + $side['items'][0]['w'], $side['items'][1]['x'], 'side by side, never over each other');
		$this->db->query("DELETE FROM ".$this->db->prefix()."inbox_userparam WHERE fk_account = ".((int) $box->id));
	}

	/**
	 * Quick actions (create button by the right) and the module widgets: the module actions offered but drawn by the
	 * panel, the blocks of the modules and the widgets taken away accepted in a layout
	 *
	 * @return void
	 */
	public function testActionsAndModuleWidgets()
	{
		$box = $this->box(true, array('status' => InboxAccount::STATUS_ACTIVE));
		$creator = $this->withRights('wgcreate', array(array('facture', 'lire'), array('facture', 'creer')));
		$reader = $this->withRights('wgread', array(array('facture', 'lire')));
		$acc = new InboxAccess($this->db);
		$acc->grant($this->admin, $box->id, $creator->id, 0, 'read');
		$acc->grant($this->admin, $box->id, $reader->id, 0, 'read');
		InboxAccess::clearCache();
		$soc = $this->soc('inboxtest_wgact', 'compta@wgact.inbox-test.invalid');
		$this->invoice($soc, true);
		$mail = $this->mail($box->id, 'compta@wgact.inbox-test.invalid');
		$w = new InboxWidgets($this->db);
		$ov = new InboxOverview($this->db);

		$cat = array_column($w->catalogue($creator), null, 'id');
		$this->assertArrayHasKey('action_invoices', $cat);
		$this->assertTrue($cat['mail_actions']['local'], 'the module actions are drawn by the panel');
		$this->assertFalse($cat['action_invoices']['local']);

		$data = $w->data($creator, $ov->context($creator, (int) $mail->rowid), array('action_invoices', 'mail_actions'));
		$this->assertNotEmpty($data['action_invoices']['rows']);
		$this->assertCount(1, $data['action_invoices']['actions']);
		$this->assertStringContainsString('socid='.((int) $soc), $data['action_invoices']['actions'][0]['url']);
		$this->assertNull($data['mail_actions'], 'never computed by the server');
		$dataRead = $w->data($reader, $ov->context($reader, (int) $mail->rowid), array('action_invoices'));
		$this->assertSame(array(), $dataRead['action_invoices']['actions'], 'no button without the right to create');

		$layout = array('items' => array(array('id' => 'panel:my_block', 'x' => 0, 'y' => 0), array('id' => 'mail_actions', 'x' => 0, 'y' => 4)),
			'off' => array('panel:other-block', 'invoices_late', 'panel:my_block', 'panel:bad id', 'panel:other-block'));
		$this->assertSame(1, $w->saveUser($reader, (int) $box->id, 'desktop', $layout));
		$mine = $w->layouts($reader, (int) $box->id)['desktop']['user'];
		$this->assertSame('panel:my_block', $mine['items'][0]['id']);
		$this->assertSame(4, $mine['items'][0]['h'], 'a block of a module takes the size of the free template');
		$this->assertSame(array('panel:other-block'), $mine['off'], 'only module widgets not placed, each once');
		$this->assertSame(-2, $w->saveUser($reader, (int) $box->id, 'desktop', array('items' => array(array('id' => 'panel:Bad')))), 'id of a block checked');
		$this->db->query("DELETE FROM ".$this->db->prefix()."inbox_userparam WHERE fk_account = ".((int) $box->id));
	}

	/**
	 * Widgets of the modules (1.162.0): checked declarations, and the layouts where a block became a widget of its
	 * module (« replaces ») keep its place and its removal
	 *
	 * @return void
	 */
	public function testModuleWidgets()
	{
		$defs = InboxWidgets::normalize($this->admin, array(
			array('source' => 'agenda', 'id' => 'meetings', 'template' => 'list', 'title' => 'Meetings', 'url' => '/custom/agenda/w.php?socid={socid}', 'replaces' => 'panel:agenda_meetings', 'auto' => true),
			array('source' => 'agenda', 'id' => 'far', 'template' => 'mini', 'title' => 'Far', 'url' => 'https://evil.example/x'),
			array('source' => 'agenda', 'id' => 'thread', 'template' => 'thread', 'title' => 'T', 'url' => '/t.php'),
			array('source' => 'agenda', 'id' => 'norights', 'template' => 'mini', 'title' => 'N', 'url' => '/n.php', 'rights' => array(array('nomodule', 'noright'))),
			array('source' => 'Bad Source', 'id' => 'x', 'template' => 'mini', 'title' => 'X', 'url' => '/x.php'),
		));
		$this->assertSame(array('mod:agenda:meetings'), array_keys($defs), 'only the valid declaration');
		$this->assertSame('panel:agenda_meetings', $defs['mod:agenda:meetings']['replaces']);
		$this->assertTrue($defs['mod:agenda:meetings']['auto']);
		$this->assertTrue(InboxWidgets::known('mod:agenda:meetings'));

		$layout = array('items' => array(array('id' => 'invoices_late', 'x' => 0, 'y' => 0, 'w' => 3, 'h' => 2), array('id' => 'panel:agenda_meetings', 'x' => 0, 'y' => 2, 'w' => 6, 'h' => 9)),
			'off' => array('panel:other'), 'cols' => 12);
		$map = array('panel:agenda_meetings' => 'mod:agenda:meetings', 'panel:other' => 'mod:other:w');
		$moved = InboxWidgets::replaceIds($layout, $map);
		$this->assertSame('mod:agenda:meetings', $moved['items'][1]['id']);
		$this->assertSame(array(0, 2, 6, 9), array($moved['items'][1]['x'], $moved['items'][1]['y'], $moved['items'][1]['w'], $moved['items'][1]['h']), 'its place and size kept');
		$this->assertSame(array('mod:other:w'), $moved['off'], 'taken away stays taken away');
		$layout['items'][] = array('id' => 'mod:agenda:meetings', 'x' => 6, 'y' => 2, 'w' => 6, 'h' => 4);
		$twice = InboxWidgets::replaceIds($layout, $map);
		$this->assertSame(array('invoices_late', 'mod:agenda:meetings'), array_column($twice['items'], 'id'), 'the widget already placed wins, no double');
		$this->assertContains('widgets', InboxCreate::CAPABILITIES);
	}
}
