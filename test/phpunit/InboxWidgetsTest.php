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
		$this->assertNotEmpty($l['preset']['items'], 'the preset of the usage (sales by default)');
		$this->assertNull($w->layouts($stranger, (int) $box->id), 'a mailbox he cannot read');

		$layout = array('items' => array(array('id' => 'figure_due', 'x' => 3, 'y' => 0, 'w' => 9, 'h' => 50), array('id' => 'invoices_late', 'x' => 0, 'y' => 0)), 'dock' => 240);
		$this->assertSame(1, $w->saveUser($reader, (int) $box->id, 'desktop', $layout));
		$mine = $w->layouts($reader, (int) $box->id)['desktop']['user'];
		$this->assertSame('figure_due', $mine['items'][0]['id']);
		$this->assertSame(4, $mine['items'][0]['w'], 'width kept inside the bounds of its template');
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
		$this->db->query("DELETE FROM ".$this->db->prefix()."inbox_userparam WHERE fk_account = ".((int) $box->id));
	}
}
