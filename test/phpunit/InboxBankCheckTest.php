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
 * \file    inbox/test/phpunit/InboxBankCheckTest.php
 * \ingroup inbox
 * \brief   1.75.0 (lot A6): bank details in a mail, never green; numbers to call from the cards, never from the mail
 */

require_once __DIR__.'/InboxTestCase.php';
dol_include_once('/inbox/class/inboxbankcheck.class.php');

/**
 * Thirdparties and contacts of the tests: « inboxtest_… » (removed by InboxTestCase::cleanup())
 */
class InboxBankCheckTest extends InboxTestCase
{
	/** A valid IBAN (example of the ISO standard) and another one */
	const IBAN = 'FR7630006000011234567890189';
	const OTHER = 'DE89370400440532013000';

	/**
	 * @return void
	 */
	public function testIbans()
	{
		$this->assertTrue(InboxBankCheck::validIban(self::IBAN));
		$this->assertTrue(InboxBankCheck::validIban(self::OTHER));
		$this->assertFalse(InboxBankCheck::validIban('FR7630006000011234567890188'), 'wrong key');
		$this->assertSame(array(self::IBAN, self::OTHER), InboxBankCheck::ibans("Notre IBAN : FR76 3000 6000 0112 3456 7890 189\nNew: de89 3704 0044 0532 0130 00"));
		$this->assertSame(array(), InboxBankCheck::ibans('Commande FR12 3456 7890 1234 5678'), 'a reference that looks like it');
		$this->assertSame('FR76 •••• 0189', InboxBankCheck::mask(self::IBAN));
		$this->assertNull(InboxBankCheck::topic('Bonjour, voici le devis.', array('devis.pdf')));
		$this->assertTrue(InboxBankCheck::topic('Merci de noter nos nouvelles coordonnées bancaires', array())['words']);
		$this->assertSame(array('RIB_2026.pdf'), InboxBankCheck::topic('Ci-joint', array('RIB_2026.pdf', 'facture.pdf'))['files']);
	}

	/**
	 * @return void
	 */
	public function testCheck()
	{
		$p = $this->db->prefix();
		$this->db->query("INSERT INTO ".$p."societe (nom, entity, status, fournisseur, phone, datec) VALUES ('inboxtest_bank', 1, 1, 1, '04 78 00 00 01', '".$this->db->idate(dol_now())."')");
		$soc = (int) $this->db->last_insert_id($p.'societe');
		$this->db->query("INSERT INTO ".$p."socpeople (fk_soc, lastname, firstname, entity, statut, phone_mobile, fk_user_creat, datec) VALUES (".$soc.", 'inboxtest_compta', 'Anne', 1, 1, '06 11 22 33 44', ".((int) $this->admin->id).", '".$this->db->idate(dol_now())."')");
		$contact = (int) $this->db->last_insert_id($p.'socpeople');
		$this->db->query("INSERT INTO ".$p."societe_rib (fk_soc, type, label, iban_prefix, entity, datec) VALUES (".$soc.", 'ban', 'inboxtest', '".self::IBAN."', 1, '".$this->db->idate(dol_now())."')");
		$row = (object) array('rowid' => 0, 'fk_account' => 0, 'from_email' => 'compta@bank.inbox-test.invalid', 'fk_soc' => $soc, 'fk_socpeople' => $contact);
		$bank = new InboxBankCheck($this->db);
		try {
			// The foot of an invoice: the IBAN of the card, no word of a change: nothing
			$this->assertNull($bank->check($this->admin, $row, "Facture 42\nIBAN ".self::IBAN, array(), true, null, ''));

			// Authenticated, new IBAN, a number of the signature not on the cards, urgency: orange, never green
			$text = "Bonjour,\nmerci de noter nos nouvelles coordonnées bancaires, IBAN ".self::OTHER.", urgent avant le prochain règlement.\nAnne\nTél. 01 99 88 77 66";
			$c = $bank->check($this->admin, $row, $text, array(), true, null, 'other@elsewhere.inbox-test.invalid');
			$this->assertSame('warning', $c['level'], 'authenticated is not enough');
			$ids = array_column($c['signals'], 'state', 'id');
			$this->assertSame('ok', $ids['auth_ok']);
			$this->assertSame('warn', $ids['iban_differs']);
			$this->assertSame('warn', $ids['phone_differs']);
			$this->assertSame('warn', $ids['first_time']);
			$this->assertSame('warn', $ids['reply_to']);
			$this->assertSame('warn', $ids['pressure']);
			$this->assertSame(array('DE89 •••• 3000'), $c['ibans']);
			$this->assertSame(5, $c['warnings']);
			// The numbers to call: the ones of the cards, never the one of the mail
			$this->assertEqualsCanonicalizing(array('06 11 22 33 44', '04 78 00 00 01'), array_column($c['call'], 'number'));
			$this->assertNotContains('01 99 88 77 66', array_column($c['call'], 'number'));
			$this->assertStringNotContainsString(self::OTHER, json_encode($c), 'the IBAN of the mail never given whole');

			// Not authenticated: red; a RIB in an attachment
			$c = $bank->check($this->admin, $row, 'Ci-joint notre RIB', array('RIB.pdf'), false, null, '');
			$this->assertSame('danger', $c['level']);
			$this->assertSame('bad', array_column($c['signals'], 'state', 'id')['auth_bad']);
			$this->assertArrayHasKey('iban_file', array_column($c['signals'], 'state', 'id'));

			// Without the right to read the thirdparty: no IBAN of the card, no number
			$c = $bank->check($this->user('nobank'), $row, 'Nouvel IBAN '.self::OTHER, array(), true, null, '');
			$this->assertSame(array(), $c['call']);
			$this->assertFalse($c['known']);
			$this->assertArrayHasKey('iban_nosoc', array_column($c['signals'], 'state', 'id'));
		} finally {
			$this->db->query("DELETE FROM ".$p."societe_rib WHERE fk_soc = ".$soc);
			$this->db->query("DELETE FROM ".$p."socpeople WHERE rowid = ".$contact);
			$this->db->query("DELETE FROM ".$p."societe WHERE rowid = ".$soc);
		}
	}
}
