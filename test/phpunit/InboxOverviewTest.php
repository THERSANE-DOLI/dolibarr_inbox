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
 * \file    inbox/test/phpunit/InboxOverviewTest.php
 * \ingroup inbox
 * \brief   Overview of a mail (1.153.0): who sees what, the thirdparty read from the mail only, sections by rights
 */

if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

require_once __DIR__.'/InboxOverviewTestCase.php';
require_once dirname(__DIR__, 2).'/class/inboxoverview.class.php';

/**
 * Database only, no mail server
 */
class InboxOverviewTest extends InboxOverviewTestCase
{
	/**
	 * Mailbox not readable: nothing at all; the thirdparty is the one of the sender of the mail
	 *
	 * @return void
	 */
	public function testContextRightsAndThirdparty()
	{
		$box = $this->box(true, array('status' => InboxAccount::STATUS_ACTIVE));
		$reader = $this->withRights('ovreader', array(array('facture', 'lire')));
		$stranger = $this->withRights('ovstranger', array(array('facture', 'lire')));
		$acc = new InboxAccess($this->db);
		$acc->grant($this->admin, $box->id, $reader->id, 0, 'read');
		InboxAccess::clearCache();
		$soc = $this->soc('inboxtest_ovsoc', 'compta@ovclient.inbox-test.invalid');
		$mail = $this->mail($box->id, 'compta@ovclient.inbox-test.invalid');
		$ov = new InboxOverview($this->db);

		$this->assertNull($ov->context($stranger, (int) $mail->rowid), 'no access to the mailbox: nothing');
		$ctx = $ov->context($reader, (int) $mail->rowid);
		$this->assertNotNull($ctx);
		$this->assertSame($soc, $ctx['socId'], 'thirdparty found from the address of the sender');
		$this->assertSame('compta@ovclient.inbox-test.invalid', $ctx['email']);

		// A sender unknown of Dolibarr: only the mails exchanged
		$other = $this->mail($box->id, 'someone@unknown.inbox-test.invalid');
		$ctx2 = $ov->context($reader, (int) $other->rowid);
		$this->assertSame(0, $ctx2['socId']);
		$this->assertSame(array('mails'), array_column($ov->head($reader, $ctx2)['sections'], 'id'));

		// No webmail: no column at all
		$noWebmail = $this->user('ovnowebmail');
		$this->assertFalse(InboxOverview::enabled($noWebmail));
		$this->assertNull($ov->context($noWebmail, (int) $mail->rowid));
	}

	/**
	 * Each section asks its right: without the invoices, no revenue nor invoices, and asking for them gives nothing
	 *
	 * @return void
	 */
	public function testSectionsByRights()
	{
		$box = $this->box(true, array('status' => InboxAccount::STATUS_ACTIVE));
		$withInvoices = $this->withRights('ovinv', array(array('facture', 'lire')));
		$noInvoices = $this->withRights('ovnoinv', array());
		$acc = new InboxAccess($this->db);
		$acc->grant($this->admin, $box->id, $withInvoices->id, 0, 'read');
		$acc->grant($this->admin, $box->id, $noInvoices->id, 0, 'read');
		InboxAccess::clearCache();
		$soc = $this->soc('inboxtest_ovsoc2', 'achat@ovclient2.inbox-test.invalid');
		$this->invoice($soc, true);
		$this->invoice($soc, false);
		$mail = $this->mail($box->id, 'achat@ovclient2.inbox-test.invalid');
		$ov = new InboxOverview($this->db);

		$ctx = $ov->context($withInvoices, (int) $mail->rowid);
		$head = $ov->head($withInvoices, $ctx);
		$ids = array_column($head['sections'], 'id');
		$this->assertContains('revenue', $ids);
		$this->assertContains('invoices', $ids);
		$this->assertNotEmpty(array_filter($head['alerts'], function ($a) {
			return $a['level'] === 'danger';
		}), 'the late invoice is said on top');
		$sec = $ov->section($withInvoices, $ctx, 'invoices');
		$this->assertSame('danger', $sec['section']['sumLevel']);

		$ctx2 = $ov->context($noInvoices, (int) $mail->rowid);
		$ids2 = array_column($ov->head($noInvoices, $ctx2)['sections'], 'id');
		$this->assertNotContains('revenue', $ids2);
		$this->assertNotContains('invoices', $ids2);
		$this->assertNull($ov->section($noInvoices, $ctx2, 'invoices'), 'a section not offered is never computed');
		$this->assertNull($ov->section($withInvoices, $ctx, '../x'), 'unknown section');
	}

	/**
	 * The usage of the mailbox puts its sections first; a piece of information switched off is not offered
	 *
	 * @return void
	 */
	public function testUsageOrderAndSwitches()
	{
		global $conf;

		$box = $this->box(true, array('status' => InboxAccount::STATUS_ACTIVE));
		$this->db->query("UPDATE ".$this->db->prefix()."inbox_account SET usage_profile = 'accounting' WHERE rowid = ".((int) $box->id));
		$acc = new InboxAccess($this->db);
		$acc->grant($this->admin, $box->id, $this->admin->id, 0, 'manage');
		InboxAccess::clearCache();
		$this->soc('inboxtest_ovsoc3', 'info@ovclient3.inbox-test.invalid');
		$mail = $this->mail($box->id, 'info@ovclient3.inbox-test.invalid');
		$ov = new InboxOverview($this->db);
		$ctx = $ov->context($this->admin, (int) $mail->rowid);
		$this->assertSame('accounting', $ctx['usage']);
		$ids = array_column($ov->head($this->admin, $ctx)['sections'], 'id');
		$this->assertSame('invoices', $ids[0], 'accounting: the invoices first');

		$conf->global->INBOX_OVERVIEW_HIDE_INVOICES = '1';
		try {
			$this->assertNotContains('invoices', array_column($ov->head($this->admin, $ctx)['sections'], 'id'));
			$this->assertNull($ov->section($this->admin, $ctx, 'invoices'));
		} finally {
			unset($conf->global->INBOX_OVERVIEW_HIDE_INVOICES);
		}
	}

	/**
	 * Tickets: with « assigned tickets only », a user who is not an administrator sees the tickets assigned to him
	 *
	 * @return void
	 */
	public function testTicketsAssignedOnly()
	{
		global $conf;

		$p = $this->db->prefix();
		if (!$this->db->getRow("SHOW TABLES LIKE '".$p."ticket'")) {
			$this->markTestSkipped('Module Tickets never enabled on this test database');
		}
		$box = $this->box(true, array('status' => InboxAccount::STATUS_ACTIVE));
		$agent = $this->withRights('ovagent', array(array('ticket', 'read')));
		(new InboxAccess($this->db))->grant($this->admin, $box->id, $agent->id, 0, 'read');
		InboxAccess::clearCache();
		$soc = $this->soc('inboxtest_ovsoc4', 'support@ovclient4.inbox-test.invalid');
		foreach (array(1 => $agent->id, 2 => $this->admin->id) as $i => $assigned) {
			$this->db->query("INSERT INTO ".$p."ticket (ref, track_id, entity, fk_soc, subject, message, fk_statut, fk_user_assign, datec) VALUES ('".self::REF.$i."', 'ovtrack".$i."', 1, ".$soc.", 'Ticket ".$i."', 'x', 1, ".((int) $assigned).", '".$this->db->idate(dol_now())."')");
		}
		$mail = $this->mail($box->id, 'support@ovclient4.inbox-test.invalid');
		$wasEnabled = isModEnabled('ticket');
		$conf->modules['ticket'] = 'ticket';
		$conf->global->TICKET_LIMIT_VIEW_ASSIGNED_ONLY = '1';
		try {
			$ov = new InboxOverview($this->db);
			$ctx = $ov->context($agent, (int) $mail->rowid);
			$sec = $ov->section($agent, $ctx, 'tickets');
			$this->assertSame('1', $sec['section']['rows'][0]['value'], 'only the ticket assigned to him');
		} finally {
			unset($conf->global->TICKET_LIMIT_VIEW_ASSIGNED_ONLY);
			if (!$wasEnabled) {
				unset($conf->modules['ticket']);
			}
		}
	}
}
