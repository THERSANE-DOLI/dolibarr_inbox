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
 * \file    inbox/test/phpunit/InboxLeadTest.php
 * \ingroup inbox
 * \brief   1.79.0 (lot A1): new customer from a mail. Values read in the mail and their source, same thirdparties,
 *          what the page of creation would ask, quick creation, module hook
 */

require_once __DIR__.'/InboxTestCase.php';
dol_include_once('/inbox/class/inboxlead.class.php');
dol_include_once('/inbox/class/inboxmessage.class.php');

/**
 * Thirdparties and contacts of the tests: names « Zzlead ... », removed after each test
 */
class InboxLeadTest extends InboxTestCase
{
	/** @var array<string,mixed> */
	protected $saved = array();

	/**
	 * @return void
	 */
	protected function tearDown(): void
	{
		global $conf;

		$p = $this->db->prefix();
		$rows = $this->db->getRows("SELECT rowid FROM ".$p."societe WHERE nom LIKE 'Zzlead%' LIMIT 100");
		foreach (is_array($rows) ? $rows : array() as $r) {
			foreach (array('socpeople' => 'fk_soc', 'societe_commerciaux' => 'fk_soc', 'categorie_societe' => 'fk_soc') as $t => $col) {
				$this->db->query("DELETE FROM ".$p.$t." WHERE ".$col." = ".((int) $r->rowid));
			}
			$this->db->query("DELETE FROM ".$p."societe WHERE rowid = ".((int) $r->rowid));
		}
		$this->db->query("DELETE FROM ".$p."socpeople WHERE lastname LIKE 'ZZLEAD%'");
		$this->db->query("DELETE FROM ".$p."inbox_link WHERE fk_mailref IN (SELECT rowid FROM ".$p."inbox_mailref WHERE message_id LIKE '%@lead.inbox-test.invalid')");
		$this->db->query("DELETE FROM ".$p."inbox_mailref WHERE message_id LIKE '%@lead.inbox-test.invalid'");
		foreach ($this->saved as $k => $v) {
			if ($v === null) {
				unset($conf->global->$k);
			} else {
				$conf->global->$k = $v;
			}
		}
		parent::tearDown();
	}

	/**
	 * @param string $name  Setting
	 * @param string $value Value
	 * @return void
	 */
	protected function set($name, $value)
	{
		global $conf;

		if (!array_key_exists($name, $this->saved)) {
			$this->saved[$name] = $conf->global->$name ?? null;
		}
		$conf->global->$name = $value;
	}

	/**
	 * A mail of a stranger
	 *
	 * @return array{0:object,1:InboxMessage}
	 */
	protected function mail()
	{
		$m = new InboxMessage();
		$m->fromEmail = 'j.dupont@zzlead-menuiserie.fr';
		$m->fromName = 'Jean DUPONT (Zzlead Menuiserie)';
		$m->subject = 'Demande de devis';
		$m->bodyText = "Bonjour,\n\nNous ouvrons un showroom à Lyon.\n\nCordialement,\n-- \nJean Dupont, Gérant\nZzlead Menuiserie\n12 rue des Artisans, 69003 Lyon\n"
			."Tél. 04 72 10 20 30 · Mobile 06 12 34 56 78\nFax 04 72 10 20 31\nSIRET 999 100 001 00075 · www.zzlead-menuiserie.fr\n\n"
			."Le 10 nov. 2026 à 09:00, Devis <devis@exemple.fr> a écrit :\n> Tél. 01 02 03 04 05\n> SIRET 999 200 017 00039\n";
		$row = (object) array('rowid' => 0, 'from_email' => $m->fromEmail, 'from_name' => $m->fromName, 'requester_email' => '', 'source_data' => '');

		return array($row, $m);
	}

	/**
	 * @param array<string,mixed> $found  extract()
	 * @param string              $part   soc or contact
	 * @param string              $field  Field
	 * @return string[] Values
	 */
	protected static function values(array $found, $part, $field)
	{
		return array_column($found[$part][$field] ?? array(), 'value');
	}

	/**
	 * @return void
	 */
	public function testExtract()
	{
		list($row, $m) = $this->mail();
		$f = (new InboxLead($this->db))->extract($row, $m);
		$this->assertSame('Zzlead Menuiserie', $f['soc']['name'][0]['value'], 'the company of the display name first');
		$this->assertSame('name', $f['soc']['name'][0]['source']);
		$this->assertContains('Zzlead-menuiserie', self::values($f, 'soc', 'name'), 'the domain stays offered');
		$this->assertSame('Jean', $f['contact']['firstname'][0]['value']);
		$this->assertSame('DUPONT', $f['contact']['lastname'][0]['value'], 'the word in capitals is the last name');
		$this->assertSame(array('04 72 10 20 30'), self::values($f, 'soc', 'phone'), 'the fax and the quoted mail left out');
		$this->assertSame(array('06 12 34 56 78'), self::values($f, 'contact', 'phone_mobile'));
		$this->assertSame('signature', $f['contact']['phone_mobile'][0]['source']);
		$this->assertStringContainsString('Mobile 06 12 34 56 78', $f['contact']['phone_mobile'][0]['quote'], 'the line of the mail, to highlight it');
		$this->assertSame(array('999 100 001 00075'), self::values($f, 'soc', 'idprof2'), 'a SIRET checked by its key; the one of the quote is not taken');
		$this->assertSame(array('999100001'), self::values($f, 'soc', 'idprof1'));
		$this->assertSame(array('FR15999100001'), self::values($f, 'soc', 'tva_intra'), 'VAT computed from the SIREN');
		$this->assertSame('12 rue des Artisans', $f['soc']['address'][0]['value']);
		$this->assertSame('69003', $f['soc']['zipcode'][0]['value']);
		$this->assertSame('Lyon', $f['soc']['town'][0]['value']);
		$this->assertSame('Gérant', $f['contact']['poste'][0]['value']);
		$this->assertContains('www.zzlead-menuiserie.fr', self::values($f, 'soc', 'url'));
		$this->assertSame('j.dupont@zzlead-menuiserie.fr', $f['contact']['email'][0]['value']);
		$this->assertFalse($f['private']);

		// A public mail service: no company from the domain, a private person offered
		$m->fromEmail = 'jean.martin@gmail.com';
		$m->fromName = 'Jean Martin';
		$m->bodyText = "Bonjour, une question.\nJean";
		$row->from_email = $m->fromEmail;
		$row->from_name = $m->fromName;
		$f = (new InboxLead($this->db))->extract($row, $m);
		$this->assertArrayNotHasKey('name', $f['soc']);
		$this->assertArrayNotHasKey('email', $f['soc'], 'a personal address is the contact one');
		$this->assertTrue($f['private']);
	}

	/**
	 * @return void
	 */
	public function testPieces()
	{
		$this->assertSame(array('firstname' => 'Jean', 'lastname' => 'Dupont', 'company' => ''), InboxLead::splitDisplayName('Dupont, Jean'));
		$this->assertSame(array('firstname' => 'Marie Claire', 'lastname' => 'DE LA FONTAINE', 'company' => 'ACME'), InboxLead::splitDisplayName('"Marie Claire DE LA FONTAINE (ACME)"'));
		$this->assertSame('', InboxLead::splitDisplayName('x@y.invalid', 'x@y.invalid')['lastname']);
		$this->assertTrue(InboxLead::validLuhn('999100001', 9));
		$this->assertFalse(InboxLead::validLuhn('999100002', 9));
		$this->assertSame('FR15999100001', InboxLead::frenchVat('999100001'));
		$this->assertSame('Dupont-menuiserie', InboxLead::nameOfDomain('mail.dupont-menuiserie.co.uk'));

		$pairs = InboxLead::labelPairs("Nom : Martin\nPrénom: Paul\nSociété : Zzlead Bois\nTéléphone : 05 56 00 00 00\nVorname: Hans\nMessage: bonjour");
		$this->assertSame(array('c:lastname', 'c:firstname', 's:name', 's:phone', 'c:firstname'), array_column($pairs, 0));
		$this->assertSame('Zzlead Bois', $pairs[2][1]);

		$v = InboxLead::parseVcard("BEGIN:VCARD\r\nVERSION:3.0\r\nN:Durand;Sophie;;;\r\nORG:Zzlead Atelier;Ventes\r\nTITLE:Acheteuse\r\nTEL;TYPE=CELL:+33 6 11 22 33 44\r\nTEL;TYPE=WORK:+33 1 44 55 66 77\r\n"
			."TEL;TYPE=FAX:+33 1 44 55 66 78\r\nADR;TYPE=WORK:;;5 avenue\r\n  Foch;Paris;;75016;France\r\nURL:https://zzlead.example\r\nEND:VCARD\r\n");
		$this->assertSame(array('Durand'), $v['contact']['lastname']);
		$this->assertSame(array('Zzlead Atelier'), $v['soc']['name']);
		$this->assertSame(array('+33 6 11 22 33 44'), $v['contact']['phone_mobile']);
		$this->assertSame(array('+33 1 44 55 66 77'), $v['soc']['phone'], 'the fax left out');
		$this->assertSame(array('5 avenue Foch'), $v['soc']['address'], 'a folded line joined');
		$this->assertSame(array('75016'), $v['soc']['zipcode']);
		$this->assertSame(array('zzlead.example'), $v['soc']['url']);

		$this->assertSame("Merci\n--\nPaul", InboxLead::ownText("Merci\n-- \nPaul\n\nOn Mon, 10 Nov 2026, Bob <b@x.invalid> wrote:\n> old"));
	}

	/**
	 * @return void
	 */
	public function testFormAndQuickCreation()
	{
		$this->set('SOCIETE_CODECLIENT_ADDON', 'mod_codeclient_monkey');
		$lead = new InboxLead($this->db);
		$form = $lead->form($this->admin);
		$this->assertTrue($form['code']['auto']);
		$this->assertSame(array('name'), $form['required']['soc']);
		$this->assertSame(array(), $form['blockers']['soc']);

		// A setting makes the email mandatory: asked in the panel; a mandatory field per page the panel has not: full page
		$this->set('SOCIETE_EMAIL_MANDATORY', '1');
		$this->assertContains('email', $lead->form($this->admin)['required']['soc']);
		$this->set('MAIN_ENABLE_DEFAULT_VALUES', '1');
		$this->admin->default_values = array('societe/card.php' => array('mandatory' => array('action=create' => array('town' => '1', 'options_zzsector' => '1')), 'createform' => array('_noquery_' => array('typent_id' => '4'))));
		$form = $lead->form($this->admin);
		$this->assertContains('town', $form['required']['soc']);
		$this->assertSame(array('options_zzsector'), $form['blockers']['soc'], 'an unknown extrafield named by its field');
		$this->assertSame('4', $form['defaults']['soc']['typent_id']);
		$this->assertContains('nature', InboxLead::missing($form, array('name' => 'X'), null, array()));
		$this->admin->default_values = array();
		$this->set('MAIN_ENABLE_DEFAULT_VALUES', '0');

		// Quick creation: the thirdparty (automatic code), its contact, the mail linked to both
		$this->set('SOCIETE_EMAIL_MANDATORY', '0');
		$p = $this->db->prefix();
		$box = $this->box(true, array('status' => InboxAccount::STATUS_ACTIVE));
		(new InboxAccess($this->db))->grant($this->admin, $box->id, $this->admin->id, 0, 'manage');
		$this->db->query("INSERT INTO ".$p."inbox_folder (fk_account, path, name, delimiter, role, status) VALUES (".((int) $box->id).", 'INBOX', 'INBOX', '.', 'inbox', 1)");
		$folder = (int) $this->db->last_insert_id($p.'inbox_folder');
		$this->db->query("INSERT INTO ".$p."inbox_mail (fk_account, fk_folder, remote_id, sort_key, message_id, date_mail, from_email, subject, size, date_index) VALUES ("
			.((int) $box->id).", ".$folder.", '1:1', 1, 'a1@lead.inbox-test.invalid', '".$this->db->idate(dol_now())."', 'paul@zzlead.invalid', 'Devis', 10, '".$this->db->idate(dol_now())."')");
		$row = $this->db->getRow("SELECT * FROM ".$p."inbox_mail WHERE rowid = ".((int) $this->db->last_insert_id($p.'inbox_mail')));
		$res = $lead->create($this->admin, $row, array('name' => 'Zzlead Quick', 'phone' => '04 00 00 00 00', 'town' => 'Lyon'),
			array('firstname' => 'Paul', 'lastname' => 'ZZLEAD Martin', 'email' => 'paul@zzlead.invalid'), array('prospect' => 1, 'commercial' => 1));
		$this->assertIsArray($res, $lead->error);
		$soc = $this->db->getRow("SELECT nom, client, code_client, town FROM ".$p."societe WHERE rowid = ".((int) $res['soc']));
		$this->assertSame('Zzlead Quick', $soc->nom);
		$this->assertSame(2, (int) $soc->client, 'prospect');
		$this->assertNotEmpty($soc->code_client, 'the automatic code given by Dolibarr');
		$contact = $this->db->getRow("SELECT fk_soc, firstname FROM ".$p."socpeople WHERE rowid = ".((int) $res['contact']));
		$this->assertSame((int) $res['soc'], (int) $contact->fk_soc);
		$links = $this->db->getRows("SELECT l.element_type FROM ".$p."inbox_link as l INNER JOIN ".$p."inbox_mailref as r ON r.rowid = l.fk_mailref WHERE r.message_id = 'a1@lead.inbox-test.invalid' ORDER BY l.element_type LIMIT 10");
		$this->assertSame(array('contact', 'societe'), array_column(array_map('get_object_vars', $links), 'element_type'));
		$this->assertSame(1, (int) $this->db->getRow("SELECT COUNT(*) as n FROM ".$p."societe_commerciaux WHERE fk_soc = ".((int) $res['soc']))->n, 'the user as sales representative');

		// The same thirdparty is found again: same phone, near name
		$f = array('soc' => array('phone' => array(array('value' => '+33 4 00 00 00 00')), 'name' => array(array('value' => 'Zzlead Quick'))), 'contact' => array());
		$dups = $lead->duplicates($this->admin, $f);
		$this->assertSame((int) $res['soc'], $dups[0]['id']);
		$this->assertSame(array('phone', 'name'), $dups[0]['why']);

		// Undone: the contact and the thirdparty go
		$this->assertTrue($lead->undo($this->admin, $res['soc'], $res['contact']), $lead->error);
		$this->assertFalse(is_object($this->db->getRow("SELECT rowid FROM ".$p."societe WHERE rowid = ".((int) $res['soc']))));
	}
}
