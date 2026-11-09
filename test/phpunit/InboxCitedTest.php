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
 * \file    inbox/test/phpunit/InboxCitedTest.php
 * \ingroup inbox
 * \brief   1.46.0: pieces cited in a mail, checked against the thirdparty of the one who writes
 */

require_once dirname(__DIR__, 2).'/class/inboxcited.class.php';

/**
 * Database only; the supplier orders module is turned on in memory for the test (not in the base)
 */
class InboxCitedTest extends InboxTestCase
{
	/** @var array<string,mixed> Module and setup before the test */
	protected $saved = array();

	/**
	 * @return void
	 */
	protected function setUp(): void
	{
		global $conf;

		parent::setUp();
		$this->saved = array('modules' => $conf->modules, 'numbering' => $conf->global->COMMANDE_SUPPLIER_ADDON_NUMBER ?? null, 'dirs' => $conf->fournisseur ?? null);
		$dirs = new stdClass();
		foreach (array('commande', 'facture') as $k) {
			$dirs->$k = (object) array('dir_output' => DOL_DATA_ROOT.'/fournisseur/'.$k, 'dir_temp' => DOL_DATA_ROOT.'/fournisseur/'.$k.'/temp');
		}
		$conf->fournisseur = $dirs;
		$conf->modules['fournisseur'] = 'fournisseur';
		$conf->modules['supplier_order'] = 'supplier_order';
		$conf->global->COMMANDE_SUPPLIER_ADDON_NUMBER = 'mod_commande_fournisseur_muguet';
		if (!isset($this->admin->rights->fournisseur)) {
			$this->admin->rights->fournisseur = new stdClass();
		}
		if (!isset($this->admin->rights->fournisseur->commande)) {
			$this->admin->rights->fournisseur->commande = new stdClass();
		}
		$this->admin->rights->fournisseur->commande->lire = 1;
		InboxCited::reset();
	}

	/**
	 * @return void
	 */
	protected function tearDown(): void
	{
		global $conf;

		$this->db->query("DELETE FROM ".$this->db->prefix()."commande_fournisseur WHERE ref LIKE 'PO2699-99%'");
		$conf->modules = $this->saved['modules'];
		if ($this->saved['dirs'] === null) {
			unset($conf->fournisseur);
		} else {
			$conf->fournisseur = $this->saved['dirs'];
		}
		if ($this->saved['numbering'] === null) {
			unset($conf->global->COMMANDE_SUPPLIER_ADDON_NUMBER);
		} else {
			$conf->global->COMMANDE_SUPPLIER_ADDON_NUMBER = $this->saved['numbering'];
		}
		InboxCited::reset();
		parent::tearDown();
	}

	/**
	 * @param string $name  Name
	 * @param string $email Mail
	 * @param int    $parent Parent
	 * @return int
	 */
	protected function soc($name, $email, $parent = 0)
	{
		$p = $this->db->prefix();
		$this->db->query("INSERT INTO ".$p."societe (nom, entity, status, fournisseur, email, parent, datec) VALUES ('".$this->db->escape($name)."', 1, 1, 1, '".$this->db->escape($email)."', "
			.($parent > 0 ? (int) $parent : 'NULL').", '".$this->db->idate(dol_now())."')");

		return (int) $this->db->last_insert_id($p.'societe');
	}

	/**
	 * @param string $ref         Reference
	 * @param int    $soc         Thirdparty
	 * @param string $refSupplier Its reference
	 * @return int
	 */
	protected function order($ref, $soc, $refSupplier = '')
	{
		$p = $this->db->prefix();
		$this->db->query("INSERT INTO ".$p."commande_fournisseur (ref, entity, fk_soc, source, fk_statut, ref_supplier, date_creation) VALUES ('".$this->db->escape($ref)."', 1, ".((int) $soc).", 0, 1, "
			.($refSupplier !== '' ? "'".$this->db->escape($refSupplier)."'" : 'NULL').", '".$this->db->idate(dol_now())."')");

		return (int) $this->db->last_insert_id($p.'commande_fournisseur');
	}

	/**
	 * @return void
	 */
	public function testPatternOf()
	{
		$this->assertSame('(?<![A-Za-z0-9])CF\d{4,6}\-\d{5,7}(?![0-9])', InboxCited::patternOf('CF2699-00001'));
		$r = '~'.InboxCited::patternOf('DEVIS2611001').'~i';
		$this->assertSame(1, preg_match($r, 'Votre devis DEVIS2611046 du 3'));
		$this->assertSame(1, preg_match($r, 'devis2611046'), 'any case');
		$this->assertSame(0, preg_match($r, 'XDEVIS2611046'), 'not inside a word');
		$this->assertSame(1, preg_match('~'.InboxCited::patternOf('CDE2611001').'~', 'CDE26111234'), 'the counter grows');
		$this->assertNull(InboxCited::patternOf('2611-0001'), 'no letters to start with: too many false positives');
		$this->assertNull(InboxCited::patternOf('CF'), 'no digit');
		$this->assertNull(InboxCited::patternOf('Error'), 'not an example');
	}

	/**
	 * @return void
	 */
	public function testCitedAndThirdparty()
	{
		$cited = new InboxCited($this->db);
		$this->assertArrayHasKey('order_supplier', $cited->patterns(), 'pattern from the numbering module in use');

		$parent = $this->soc('inboxtest_group', 'group@group.inbox-test.invalid');
		$fast = $this->soc('inboxtest_fast', 'contact@fast.inbox-test.invalid', $parent);
		$other = $this->soc('inboxtest_other', 'contact@other.inbox-test.invalid');
		$mine = $this->order('PO2699-99001', $fast, 'CC00024342');
		$theirs = $this->order('PO2699-99002', $other);
		$sister = $this->order('PO2699-99003', $parent);

		$socs = $cited->writerSocs('contact@fast.inbox-test.invalid', '', array());
		$this->assertContains($fast, $socs);
		$this->assertContains($parent, $socs, 'the parent company too');
		$this->assertNotContains($other, $socs);

		$text = "ACME PO2699-99001\nVoir aussi po2699-99002 et PO2699-99003. Notre commande CC00024342.";
		$found = $cited->find($this->admin, $text, $socs);
		$byId = array();
		foreach ($found as $f) {
			$byId[$f['id']] = $f;
		}
		$this->assertSame('same', $byId[$mine]['match']);
		$this->assertTrue($byId[$mine]['theirs'], 'its own reference kept on our order: sure');
		$this->assertSame('CC00024342', $byId[$mine]['cited']);
		$this->assertSame($mine, $found[0]['id'], 'its own reference first');
		$this->assertSame('other', $byId[$theirs]['match'], 'same numbering, another thirdparty');
		$this->assertSame('same', $byId[$sister]['match'], 'the parent company');
		$this->assertSame('other', end($found)['match'], 'the other thirdparty last');

		// Nobody known: the pieces are found, not checked
		$unknown = $cited->find($this->admin, 'PO2699-99002', array());
		$this->assertSame('unknown', $unknown[0]['match']);

		// Internal transfer: the sender of the mail transferred, read in the body
		$p = $this->db->prefix();
		$this->db->query("INSERT INTO ".$p."inbox_account (entity, ref, label, email, type, connector, status, date_creation, fk_user_creat) VALUES (1, 'achats-test', 'test', 'achats@inbox-test.invalid', 'shared', 'imap', 0, '"
			.$this->db->idate(dol_now())."', ".((int) $this->admin->id).")");
		$body = "Pour info\n\n-------- Message transféré --------\nDe : Fast <contact@fast.inbox-test.invalid>\nObjet : ACME";
		$this->assertContains($fast, $cited->writerSocs('julien@inbox-test.invalid', $body, array()), 'transfer: the sender of the original mail');
		$this->assertContains($other, $cited->writerSocs('julien@inbox-test.invalid', 'Pour info', array('achats@inbox-test.invalid', 'contact@other.inbox-test.invalid')), 'else the other people');
	}
}
