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
 * \file    inbox/test/phpunit/InboxCreateTest.php
 * \ingroup inbox
 * \brief   Lot W5 1.14.0: a piece created from a mail is linked to it (and gets the .eml), files put in a piece
 */

require_once dirname(__DIR__, 2).'/class/inboxcreate.class.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';

/**
 * Database and documents folder of the test instance, no mail server
 */
class InboxCreateTest extends InboxTestCase
{
	/** @var string[] Folders of documents made by the test */
	protected $dirs = array();

	/**
	 * @return void
	 */
	protected function tearDown(): void
	{
		foreach ($this->dirs as $dir) {
			dol_delete_dir_recursive($dir);
		}
		unset($_SESSION['inbox_create_intents']);
		parent::tearDown();
	}

	/**
	 * @return void
	 */
	public function testCreateLinkAttach()
	{
		$p = $this->db->prefix();
		$devis = $this->box(true, array('status' => InboxAccount::STATUS_ACTIVE));
		(new InboxAccess($this->db))->grant($this->admin, $devis->id, $this->admin->id, 0, 'manage');
		InboxAccess::clearCache();
		$this->db->query("INSERT INTO ".$p."inbox_folder (fk_account, path, name, delimiter, role, status) VALUES (".((int) $devis->id).", 'INBOX', 'INBOX', '.', 'inbox', 1)");
		$folder = (int) $this->db->last_insert_id($p.'inbox_folder');
		$this->db->query("INSERT INTO ".$p."inbox_mail (fk_account, fk_folder, remote_id, sort_key, message_id, date_mail, from_email, from_name, subject, size, date_index) VALUES ("
			.((int) $devis->id).", ".$folder.", '1', 1, 'c1@inbox-test.invalid', '2026-11-02 10:00:00', 'jane@client.inbox-test.invalid', 'Jane Martin', 'Demande de prix inox', 10,"
			." '".$this->db->idate(dol_now())."')");
		$row = $this->db->getRow("SELECT * FROM ".$p."inbox_mail WHERE message_id = 'c1@inbox-test.invalid'");
		$refs = new InboxMailRef($this->db);
		$ref = $refs->fetchOrCreate($row, $this->admin);

		// Page of creation filled from the mail
		$creator = new InboxCreate($this->db);
		$url = $creator->createUrl('contact', $row, array('soc' => 12, 'contact' => 0));
		$this->assertStringContainsString('/contact/card.php?action=create', $url);
		$this->assertStringContainsString('firstname=Jane', $url);
		$this->assertStringContainsString('lastname=Martin', $url);
		$this->assertStringContainsString('socid=12', $url);
		$this->assertStringContainsString('title=Demande+de+prix+inox', $creator->createUrl('project', $row, array('soc' => 0)));
		$types = array_column(InboxCreate::creatable($this->admin), 'type');
		$this->assertContains('societe', $types);
		$this->assertSame(array(), InboxCreate::creatable($this->user('nobody')), 'no right, nothing to create');

		// The creation in the same session: linked, the .eml in its documents
		$this->db->query("INSERT INTO ".$p."societe (nom, entity, status, client, datec) VALUES ('inboxtest_created', 1, 1, 1, '".$this->db->idate(dol_now())."')");
		$soc = new Societe($this->db);
		$soc->fetch((int) $this->db->last_insert_id($p.'societe'));
		$tmp = sys_get_temp_dir().'/intent-'.bin2hex(random_bytes(8)).'.eml';
		file_put_contents($tmp, "Subject: Demande de prix inox\r\n\r\nBonjour\r\n");
		$_SESSION['inbox_create_intents'] = array(array('type' => 'societe', 'mailref' => $ref, 'mail' => (int) $row->rowid, 'subject' => 'Demande de prix inox',
			'file' => $tmp, 'at' => dol_now(), 'user' => $this->admin->id));
		$this->assertSame(1, $creator->onCreate($this->admin, 'COMPANY_CREATE', $soc));
		$dir = $creator->documentsDir('societe', $soc);
		$this->dirs[] = $dir;
		$this->assertSame(array('societe'), array_column((new InboxLink($this->db))->listFor(array($ref)), 'type'));
		$files = glob($dir.'/*.eml');
		$this->assertCount(1, $files, 'the mail in the documents of the thirdparty');
		$this->assertFileDoesNotExist($tmp, 'the file aside is removed');
		$this->assertSame(0, $creator->onCreate($this->admin, 'COMPANY_CREATE', $soc), 'one intent, one piece');

		// An intent naming any other file of the server: never read, never removed
		$other = sys_get_temp_dir().'/inbox-test-other-'.bin2hex(random_bytes(4)).'.txt';
		file_put_contents($other, 'secret');
		$_SESSION['inbox_create_intents'] = array(array('type' => 'societe', 'mailref' => $ref, 'mail' => 0, 'subject' => 'x', 'file' => $other, 'at' => dol_now(), 'user' => $this->admin->id));
		$creator->onCreate($this->admin, 'COMPANY_CREATE', $soc);
		$this->assertFileExists($other);
		$this->assertCount(1, glob($dir.'/*.eml'));
		@unlink($other);
		// The intent of another user of the session is not used
		$_SESSION['inbox_create_intents'] = array(array('type' => 'societe', 'mailref' => $ref, 'mail' => 0, 'subject' => 'x', 'file' => '', 'at' => dol_now(), 'user' => 999999));
		$this->assertSame(0, $creator->onCreate($this->admin, 'COMPANY_CREATE', $soc));

		// A file of a mail put in the documents of a piece; a name already there gets a number
		$this->assertSame('devis client.pdf', $creator->attach($this->admin, 'societe', $soc->id, 'devis client.pdf', '%PDF-1.4 test'));
		$this->assertSame('devis client (2).pdf', $creator->attach($this->admin, 'societe', $soc->id, 'devis client.pdf', '%PDF-1.4 test'));
		$this->assertSame('', $creator->attach($this->user('reader'), 'societe', $soc->id, 'x.pdf', 'x'));
		$this->assertSame('InboxErrorCannotAttach', $creator->error);
		$name = $creator->attach($this->admin, 'societe', $soc->id, '../../evil.php', 'x');
		$this->assertFileDoesNotExist(dirname($dir, 2).'/evil.php', 'a name never leaves the folder of the piece');
		if ($name !== '') {
			$this->assertFileExists($dir.'/'.$name);
		}
	}
}
