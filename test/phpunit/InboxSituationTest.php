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
 * \file    inbox/test/phpunit/InboxSituationTest.php
 * \ingroup inbox
 * \brief   Usage of a mailbox and situation of a thirdparty (1.67.0)
 */

require_once __DIR__.'/InboxTestCase.php';
dol_include_once('/inbox/class/inboxsituation.class.php');
dol_include_once('/inbox/class/inboxcontactmatcher.class.php');

/**
 * Lists by usage, change of usage written in the journal, every indicator computed (a SQL error would hide it), the
 * address attached by hand read first by the matcher
 */
class InboxSituationTest extends InboxTestCase
{
	/**
	 * @return void
	 */
	protected function tearDown(): void
	{
		$p = $this->db->prefix();
		$this->db->query("DELETE FROM ".$p."commande_fournisseur WHERE ref LIKE 'PO2699-98%'");
		// Before the thirdparties of the tests are removed (a shipment left would keep them, foreign key)
		$this->db->query("DELETE FROM ".$p."expedition WHERE ref LIKE 'SH2699-96%'");
		$this->db->query("DELETE FROM ".$p."categorie_fournisseur WHERE fk_categorie IN (SELECT rowid FROM ".$p."categorie WHERE label LIKE 'inboxtest_%')");
		$this->db->query("DELETE FROM ".$p."categorie WHERE label LIKE 'inboxtest_%'");
		$this->db->query("DELETE FROM ".$p."inbox_address_soc WHERE email LIKE '%inbox-test.invalid'");
		parent::tearDown();
	}

	/**
	 * @return void
	 */
	public function testListFor()
	{
		$a = $this->box(false, array('usage_profile' => 'auto'));
		$this->assertSame(InboxSituation::USAGES['sales'], InboxSituation::listFor($a, 1, 0)['ids'], 'auto, customer: Sales');
		$this->assertSame(InboxSituation::USAGES['purchase'], InboxSituation::listFor($a, 0, 1)['ids'], 'auto, supplier: Purchasing');
		$both = InboxSituation::listFor($a, 3, 1);
		$this->assertSame(array('sales', 'purchase'), $both['shown']);
		$this->assertSame(array_values(array_unique(array_merge(InboxSituation::USAGES['sales'], InboxSituation::USAGES['purchase']))), $both['ids'], 'both lists, no twice');
		$this->assertSame(array(), InboxSituation::listFor($a, 0, 0)['ids'], 'neither customer nor supplier');

		$a->usage_profile = 'transport';
		$this->assertSame(InboxSituation::USAGES['transport'], InboxSituation::listFor($a, 1, 0)['ids'], 'the usage, whatever the thirdparty');
		$a->usage_indicators = json_encode(array('ship_open', 'unknown', 'inv_late'));
		$this->assertSame(array('ship_open', 'inv_late'), InboxSituation::listFor($a, 1, 0)['ids'], 'own list, unknown ids left out, order kept');
		$a->usage_profile = 'none';
		$a->usage_indicators = null;
		$this->assertSame(array(), InboxSituation::listFor($a, 1, 1)['ids']);
		$a->usage_profile = 'nonsense';
		$this->assertSame('auto', InboxSituation::listFor($a, 1, 0)['usage'], 'unknown usage = auto');
	}

	/**
	 * @return void
	 */
	public function testSetUsage()
	{
		$a = $this->box(true);
		$this->assertSame('auto', $a->usage_profile, 'auto by default');
		$s = new InboxSituation($this->db);
		$this->assertSame(1, $s->setUsage($this->admin, $a, 'accounting'));
		$this->assertSame(0, $s->setUsage($this->admin, $a, 'accounting'), 'unchanged');
		$this->assertLessThan(0, $s->setUsage($this->admin, $a, 'nonsense'));
		$again = new InboxAccount($this->db);
		$again->fetch((int) $a->id);
		$this->assertSame('accounting', $again->usage_profile, 'saved');
		$log = $this->db->getRow("SELECT value_before, value_after FROM ".$this->db->prefix()."inbox_account_log WHERE fk_account = ".((int) $a->id)." AND action = 'usage'");
		$this->assertIsObject($log, 'written in the journal of the mailbox');
		$this->assertStringContainsString('accounting', (string) $log->value_after);
	}

	/**
	 * Own list of a mailbox (1.68.0)
	 *
	 * @return void
	 */
	public function testOwnList()
	{
		$a = $this->box(true);
		$s = new InboxSituation($this->db);
		$this->assertLessThan(0, $s->setIndicators($this->admin, $a, array('inv_late')), 'no list in automatic');
		$s->setUsage($this->admin, $a, 'sales');
		$this->assertSame(1, $s->setIndicators($this->admin, $a, array('outstanding', 'inv_late', 'ship_open')));
		$this->assertSame(array('outstanding', 'inv_late', 'ship_open'), InboxSituation::listFor($a, 1, 0)['ids'], 'own list in its order');
		$this->assertSame(0, $s->setIndicators($this->admin, $a, array('outstanding', 'inv_late', 'ship_open')), 'unchanged');
		$this->assertLessThan(0, $s->setIndicators($this->admin, $a, array('nonsense')), 'unknown indicator refused');
		$this->assertSame(1, $s->setIndicators($this->admin, $a, InboxSituation::defaults('sales')));
		$this->assertNull(InboxSituation::ownList($a), 'the list of the usage itself: no own list');
		$s->setIndicators($this->admin, $a, array('ship_open'));
		$s->setUsage($this->admin, $a, 'accounting');
		$this->assertNull(InboxSituation::ownList($a), 'a new usage takes its own list');
		$again = new InboxAccount($this->db);
		$again->fetch((int) $a->id);
		$this->assertNull($again->usage_indicators, 'saved');
		$this->assertSame(2, (int) $this->db->getRow("SELECT COUNT(*) as n FROM ".$this->db->prefix()."inbox_account_log WHERE fk_account = ".((int) $a->id)." AND action = 'indicators' AND value_after LIKE '%ship_open%'")->n, 'two own lists with ship_open written in the journal');
	}

	/**
	 * Indicators of the other modules (1.68.0): in the catalogue, in the lists of their usages
	 *
	 * @return void
	 */
	public function testModuleIndicators()
	{
		$r = new ReflectionProperty('InboxSituation', 'modules');
		$r->setAccessible(true);
		$r->setValue(null, array('mymodule_prepaid' => array('label' => 'Prepaid time', 'rule' => 'alert at 0', 'usages' => array('support'))));
		try {
			$this->assertArrayHasKey('mymodule_prepaid', InboxSituation::catalogue());
			$this->assertSame(array_merge(InboxSituation::USAGES['support'], array('mymodule_prepaid')), InboxSituation::defaults('support'));
			$this->assertNotContains('mymodule_prepaid', InboxSituation::defaults('sales'));
			$this->assertSame(array(), InboxSituation::defaults('auto'));
		} finally {
			InboxSituation::reset();
		}
	}

	/**
	 * Phones of the signature (1.69.0)
	 *
	 * @return void
	 */
	public function testSignaturePhones()
	{
		$this->assertSame('0612345679', InboxSituation::phoneKey('+33 (0)6 12 34 56 79'));
		$this->assertSame('0612345679', InboxSituation::phoneKey('0033 6.12.34.56.79'));
		$this->assertSame('0612345679', InboxSituation::phoneKey('06-12-34-56-79'));
		$this->assertSame('', InboxSituation::phoneKey('1234'), 'too short');
		$text = "Bonjour,\n\nCommande CF2611-02221 du 09/11/2026, total 1 234,56 EUR.\n\nCordialement,\nClaire Martin\nTél. 04 72 10 20 31\nMob : +33 (0)6 12 34 56 79\n"
			."Fax: 04.72.10.20.32\n\nLe 3 nov. 2026, Paul a écrit :\n> Tel 01 23 45 67 89\n";
		$found = InboxSituation::signaturePhones($text);
		$this->assertSame(array('0472102031', '0612345679'), array_column($found, 'key'), 'no reference, no date, no fax, nothing of the quoted mail');
		$this->assertTrue($found[1]['mobile']);
		$this->assertFalse($found[0]['mobile']);

		$p = $this->db->prefix();
		$this->db->query("INSERT INTO ".$p."societe (nom, entity, status, client, datec) VALUES ('inboxtest_phones', 1, 1, 1, '".$this->db->idate(dol_now())."')");
		$soc = (int) $this->db->last_insert_id($p.'societe');
		$this->db->query("INSERT INTO ".$p."socpeople (entity, fk_soc, lastname, firstname, phone, phone_mobile, statut, datec, fk_user_creat) VALUES (1, ".$soc.", 'inboxtest_Phones', 'Ann', '04.72.10.20.31', '', 1, '"
			.$this->db->idate(dol_now())."', ".((int) $this->admin->id).")");
		$contact = (int) $this->db->last_insert_id($p.'socpeople');
		$s = new InboxSituation($this->db);
		$this->assertSame(array('0612345679'), array_column($s->newPhones($text, $contact, $soc), 'key'), 'the number of the card is not offered again');

		$this->assertSame('', $s->setContactPhone($this->admin, $contact, 'phone_mobile', '+33 (0)6 12 34 56 79'), 'empty before');
		$this->assertSame(array(), $s->newPhones($text, $contact, $soc), 'nothing new any more');
		$this->assertNull($s->setContactPhone($this->admin, $contact, 'email', 'x'), 'only the phones');
	}

	/**
	 * Tracking numbers and carriers (1.70.0)
	 *
	 * @return void
	 */
	public function testTrackingAndCarriers()
	{
		dol_include_once('/inbox/class/inboxcited.class.php');
		$p = $this->db->prefix();
		$this->db->query("INSERT INTO ".$p."societe (nom, entity, status, client, datec) VALUES ('inboxtest_parcel', 1, 1, 1, '".$this->db->idate(dol_now())."')");
		$customer = (int) $this->db->last_insert_id($p.'societe');
		// A number of this run only: other shipments of the database never answer
		$number = 'TST'.date('His').mt_rand(100000, 999999);
		$this->db->query("INSERT INTO ".$p."expedition (ref, entity, fk_soc, fk_statut, tracking_number, date_creation, fk_user_author) VALUES ('SH2699-96001', 1, ".$customer.", 1, '".$number."', '"
			.$this->db->idate(dol_now())."', ".((int) $this->admin->id).")");
		$ship = (int) $this->db->last_insert_id($p.'expedition');
		$this->assertGreaterThan(0, $ship, 'test shipment created');
		$cited = new InboxCited($this->db);
		$found = $cited->trackings("Votre colis ".$number." n'a pas pu être livré.");
		$this->assertSame($number, $found[$ship] ?? null, 'found by its tracking number');
		$this->assertArrayNotHasKey($ship, $cited->trackings('Commande X'.$number.'Y'), 'never inside a longer word');

		$this->db->query("INSERT INTO ".$p."societe (nom, entity, status, fournisseur, datec) VALUES ('inboxtest_carrier', 1, 1, 1, '".$this->db->idate(dol_now())."')");
		$carrier = (int) $this->db->last_insert_id($p.'societe');
		$this->db->query("INSERT INTO ".$p."categorie (entity, label, type, visible) VALUES (1, 'inboxtest_Carriers', 1, 1)");
		$cat = (int) $this->db->last_insert_id($p.'categorie');
		$this->db->query("INSERT INTO ".$p."categorie_fournisseur (fk_categorie, fk_soc) VALUES (".$cat.", ".$carrier.")");
		$this->db->query("INSERT INTO ".$p."categorie (entity, label, type, visible) VALUES (1, 'inboxtest_Customers', 2, 1)");
		$notSupplier = (int) $this->db->last_insert_id($p.'categorie');
		$a = $this->box(true);
		$s = new InboxSituation($this->db);
		$this->assertLessThan(0, $s->setCarrierCategory($this->admin, $a, $notSupplier), 'only a category of suppliers');
		$this->assertSame(1, $s->setCarrierCategory($this->admin, $a, $cat));
		$this->assertFalse($s->isCarrier($a, $carrier), 'only with the usage Transport');
		$s->setUsage($this->admin, $a, 'transport');
		if (isModEnabled('category')) {
			$this->assertTrue($s->isCarrier($a, $carrier));
		}
		$this->assertFalse($s->isCarrier($a, $customer));
	}

	/**
	 * @return void
	 */
	public function testCompute()
	{
		$p = $this->db->prefix();
		$this->db->query("INSERT INTO ".$p."societe (nom, entity, status, client, fournisseur, email, datec) VALUES ('inboxtest_situation', 1, 1, 1, 1, 'contact@situation.inbox-test.invalid', '".$this->db->idate(dol_now())."')");
		$soc = (int) $this->db->last_insert_id($p.'societe');
		// One supplier order ordered, expected yesterday: a reception late
		$this->db->query("INSERT INTO ".$p."commande_fournisseur (ref, entity, fk_soc, source, fk_statut, date_livraison, date_creation) VALUES ('PO2699-98001', 1, ".$soc.", 0, 3, '"
			.$this->db->idate(dol_now() - 86400 * 2)."', '".$this->db->idate(dol_now())."')");

		$s = new InboxSituation($this->db);
		$all = array_keys(InboxSituation::INDICATORS);
		$rows = $s->compute($this->admin, $soc, $all);
		$byId = array();
		foreach ($rows as $r) {
			$byId[$r['id']] = $r;
		}
		foreach ($all as $id) {
			if (InboxElements::canReadType($this->admin, InboxSituation::INDICATORS[$id]['type'])) {
				$this->assertArrayHasKey($id, $byId, $id.' computed (no SQL error)');
				$this->assertContains($byId[$id]['level'], array('alert', 'attention', 'normal'));
				$this->assertNotSame('', $byId[$id]['url']);
			} else {
				$this->assertArrayNotHasKey($id, $byId, $id.' left out without the right or the module');
			}
		}
		if (isset($byId['reception_wait'])) {
			$this->assertSame(1, $byId['reception_wait']['count']);
			$this->assertSame('alert', $byId['reception_wait']['level'], 'past its planned date');
			$this->assertNotSame('', $byId['reception_wait']['short'], 'short text for the closed card');
			$this->assertSame('attention', $byId['sorder_open']['level'], 'one supplier order in progress');
		}
		if (isset($byId['inv_late'])) {
			$this->assertSame('normal', $byId['inv_late']['level']);
			$this->assertSame('', $byId['inv_late']['short'], 'nothing for the closed card when normal');
		}
		$this->assertSame(array(), $s->compute($this->admin, 0, $all), 'no thirdparty');

		$reach = $s->reach('societe', $soc);
		$this->assertSame('contact@situation.inbox-test.invalid', $reach['email']);
		$this->assertSame(1, $reach['client']);
		$this->assertSame(1, $reach['supplier']);
	}

	/**
	 * @return void
	 */
	public function testAddressAttachedByHand()
	{
		$p = $this->db->prefix();
		$this->db->query("INSERT INTO ".$p."societe (nom, entity, status, client, email, datec) VALUES ('inboxtest_byaddress', 1, 1, 1, 'someone@byhand.inbox-test.invalid', '".$this->db->idate(dol_now())."')");
		$first = (int) $this->db->last_insert_id($p.'societe');
		$this->db->query("INSERT INTO ".$p."societe (nom, entity, status, client, datec) VALUES ('inboxtest_byhand', 1, 1, 1, '".$this->db->idate(dol_now())."')");
		$chosen = (int) $this->db->last_insert_id($p.'societe');

		$m = new InboxContactMatcher($this->db);
		$this->assertSame($first, $m->match('someone@byhand.inbox-test.invalid')['fk_soc'], 'by the address of the thirdparty');

		$s = new InboxSituation($this->db);
		$row = (object) array('rowid' => 0, 'fk_account' => 0, 'thread_key' => '', 'from_email' => 'Someone@ByHand.inbox-test.invalid');
		$this->assertGreaterThanOrEqual(0, $s->setThirdparty($this->admin, $row, $chosen, true));
		$m = new InboxContactMatcher($this->db);
		$this->assertSame($chosen, $m->match('someone@byhand.inbox-test.invalid')['fk_soc'], 'the address attached by hand comes first');
		$this->assertSame(1, (int) $this->db->getRow("SELECT COUNT(*) as n FROM ".$p."inbox_address_soc WHERE email = 'someone@byhand.inbox-test.invalid'")->n, 'one line per address, lower case');
		$this->assertGreaterThanOrEqual(0, $s->setThirdparty($this->admin, $row, $first, true));
		$this->assertSame(1, (int) $this->db->getRow("SELECT COUNT(*) as n FROM ".$p."inbox_address_soc WHERE email = 'someone@byhand.inbox-test.invalid'")->n, 'replaced, not added');
	}
}
