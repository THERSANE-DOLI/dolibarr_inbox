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
 * \file    inbox/test/phpunit/InboxLinkNoteTest.php
 * \ingroup inbox
 * \brief   Lot W5 1.13.0: links to pieces of Dolibarr and notes, rights, sender by domain, mails without Message-ID
 */

require_once dirname(__DIR__, 2).'/class/inboxlink.class.php';
require_once dirname(__DIR__, 2).'/class/inboxnote.class.php';
require_once dirname(__DIR__, 2).'/class/inboxcontactmatcher.class.php';
require_once dirname(__DIR__, 2).'/core/triggers/interface_95_modInbox_InboxTriggers.class.php';

/**
 * Database only, no mail server
 */
class InboxLinkNoteTest extends InboxTestCase
{
	/**
	 * @param int    $accountId Mailbox
	 * @param string $path      Path
	 * @param string $role      Role
	 * @return int
	 */
	protected function folder($accountId, $path, $role = '')
	{
		$p = $this->db->prefix();
		$this->db->query("INSERT INTO ".$p."inbox_folder (fk_account, path, name, delimiter, role, status) VALUES (".((int) $accountId).", '".$this->db->escape($path)."',"
			." '".$this->db->escape($path)."', '.', ".($role !== '' ? "'".$this->db->escape($role)."'" : 'NULL').", 1)");

		return (int) $this->db->last_insert_id($p.'inbox_folder');
	}

	/**
	 * @param int    $accountId Mailbox
	 * @param int    $folderId  Folder
	 * @param string $messageId Message-ID ('' = none)
	 * @return object Row of the index
	 */
	protected function mail($accountId, $folderId, $messageId)
	{
		static $uid = 5000;
		$p = $this->db->prefix();
		$uid++;
		$this->db->query("INSERT INTO ".$p."inbox_mail (fk_account, fk_folder, remote_id, sort_key, message_id, date_mail, date_received, from_email, subject, size, date_index) VALUES ("
			.((int) $accountId).", ".((int) $folderId).", '".$uid."', ".$uid.", ".($messageId !== '' ? "'".$this->db->escape($messageId)."'" : 'NULL').", '2026-11-02 10:00:00', '2026-11-02 10:00:05',"
			." 'jane@clienttest.inbox-test.invalid', 'Demande de prix', 1234, '".$this->db->idate(dol_now())."')");

		return $this->db->getRow("SELECT * FROM ".$p."inbox_mail WHERE rowid = ".((int) $this->db->last_insert_id($p.'inbox_mail')));
	}

	/**
	 * @return void
	 */
	public function testLinksNotesRights()
	{
		global $langs, $conf;

		$p = $this->db->prefix();
		$alice = $this->user('alice');
		$bob = $this->user('bob');
		$devis = $this->box(true, array('status' => InboxAccount::STATUS_ACTIVE));
		$contact = $this->box(true, array('email' => 'contact@inbox-test.invalid', 'status' => InboxAccount::STATUS_ACTIVE));
		$acc = new InboxAccess($this->db);
		$acc->grant($this->admin, $devis->id, $this->admin->id, 0, 'manage');
		$acc->grant($this->admin, $devis->id, $alice->id, 0, 'read');
		InboxAccess::clearCache();
		$m1 = $this->mail($devis->id, $this->folder($devis->id, 'INBOX', 'inbox'), 'l1@inbox-test.invalid');
		$this->db->query("INSERT INTO ".$p."societe (nom, entity, status, client, email, datec) VALUES ('inboxtest_soc', 1, 1, 1, 'contact@clienttest.inbox-test.invalid', '".$this->db->idate(dol_now())."')");
		$soc = (int) $this->db->last_insert_id($p.'societe');

		$refs = new InboxMailRef($this->db);
		$ref = $refs->fetchOrCreate($m1, $this->admin);
		$this->assertGreaterThan(0, $ref);
		$linker = new InboxLink($this->db);
		$link = $linker->link($ref, 'societe', $soc, $this->admin);
		$this->assertGreaterThan(0, $link, $linker->error);
		$this->assertSame($link, $linker->link($ref, 'societe', $soc, $this->admin), 'no double');
		$this->assertSame(-1, $linker->link($ref, 'societe', $soc, $alice));
		$this->assertSame('InboxErrorPieceNotReadable', $linker->error, 'alice may read the mailbox, not the thirdparties');
		$this->assertSame(-1, $linker->link($ref, 'societe', $soc, $bob));
		$this->assertSame('InboxMailNotFound', $linker->error, 'bob cannot read the mailbox');
		$this->assertContains($ref, $linker->mailrefsOf('societe', $soc));

		$elements = new InboxElements($this->db);
		$d = $elements->describe($this->admin, 'societe', $soc);
		$this->assertTrue($d['readable']);
		$this->assertSame('inboxtest_soc', $d['ref']);
		// Status as the badge of Dolibarr: short label and kind (an open thirdparty = status4)
		$this->assertSame(4, $d['status_type']);
		$this->assertNotSame('', $d['status_short']);
		$this->assertTrue($d['status_normal'], 'open thirdparty: expected status, a dot instead of the badge (1.88.7)');
		$this->assertFalse($elements->describe($alice, 'societe', $soc)['readable'], 'nothing of the piece without the right');

		// Search (1.46.0): the events only when asked, « contains » from 3 characters, the exact name first
		$now = $this->db->idate(dol_now());
		$ok = $this->db->query("INSERT INTO ".$p."actioncomm (ref, entity, label, fk_soc, datep, datec, code, fk_action, percent) VALUES ('inboxtest', 1, 'Rappel inboxtest_soc', ".$soc.", '".$now."', '".$now."', 'AC_OTH', 5, -1)");
		$this->assertNotFalse($ok, (string) $this->db->lasterror());
		$eventId = (int) $this->db->last_insert_id($p.'actioncomm', 'id');
		$this->assertGreaterThan(0, $eventId);
		$types = function ($list) {
			return array_values(array_unique(array_column($list, 'type')));
		};
		$found = $elements->search($this->admin, 'inboxtest_soc');
		$this->assertSame('societe', $found[0]['type']);
		$this->assertTrue($found[0]['exact'], 'the exact name first');
		$this->assertNotContains('action', $types($found), 'no event without asking');
		$events = $elements->search($this->admin, 'inboxtest_soc', 0, 20, array('action'));
		$this->assertSame(array('action'), $types($events), 'the events alone when asked');
		$this->assertContains($eventId, array_column($events, 'id'));
		$this->assertContains($soc, array_column($elements->search($this->admin, 'test_soc'), 'id'), 'contains from 3 characters');
		$this->assertNotContains($soc, array_column($elements->search($this->admin, 'st'), 'id'), 'shorter: at the start only');
		$this->db->query("DELETE FROM ".$p."actioncomm WHERE id = ".$eventId);

		// Notes: author or manager of the mailbox
		$notes = new InboxNote($this->db);
		$n1 = $notes->add($ref, "  Rappeler lundi\r\n<b>urgent</b>  ", $alice);
		$this->assertGreaterThan(0, $n1, $notes->error);
		$list = $notes->listFor(array($ref), $alice);
		$this->assertSame("Rappeler lundi\n<b>urgent</b>", $list[0]['text'], 'plain text, kept as written (escaped when shown)');
		$this->assertTrue($list[0]['mine']);
		$this->assertSame(1, $notes->update($n1, 'Rappeler mardi', $alice));
		$this->assertSame(-1, $notes->update($n1, 'x', $bob));
		$this->assertSame('InboxErrorNoteNotFound', $notes->error);
		$n2 = $notes->add($ref, 'Note de l admin', $this->admin);
		$this->assertSame(-1, $notes->update($n2, 'x', $alice));
		$this->assertSame('InboxErrorNoteNotYours', $notes->error);
		// A note follows the mail; its author only changes or removes it, a manager of the mailbox too (audit INBX-006)
		$this->assertSame(-1, $notes->delete($n1, $this->admin), 'a manager of the mailbox is not its author');
		$this->assertSame(1, $notes->delete($n1, $alice));
		$this->assertSame(-1, $notes->add($ref, '   ', $alice));

		// The mail copied to another mailbox: one mailref, links and notes seen from there by who can read it
		$copy = $this->mail($contact->id, $this->folder($contact->id, 'INBOX', 'inbox'), 'l1@inbox-test.invalid');
		$this->assertSame(array((int) $copy->rowid => $ref), $refs->forRows(array($copy)));
		$this->assertSame('', $refs->levelOf($bob, $ref));
		$this->assertSame('read', $refs->levelOf($alice, $ref));

		// Sender known by the domain of its address, never by a public domain
		$matcher = new InboxContactMatcher($this->db);
		$this->assertSame($soc, $matcher->matchDomain('jane@clienttest.inbox-test.invalid'));
		$this->assertSame(0, $matcher->matchDomain('jane@gmail.com'));
		$this->assertSame(0, $matcher->matchDomain('jane@test.inbox-test.invalid'), 'only this domain, not a longer one ending with it');

		// A piece removed from Dolibarr: its links go, the notes stay
		$socObj = new stdClass();
		$socObj->id = $soc;
		(new InterfaceInboxTriggers($this->db))->runTrigger('COMPANY_DELETE', $socObj, $this->admin, $langs, $conf);
		$this->assertSame(array(), $linker->listFor(array($ref)));
		$this->assertCount(1, $notes->listFor(array($ref)));
	}

	/**
	 * @return void
	 */
	public function testStatusOf()
	{
		$s = InboxElements::statusOf('Tous les produits reçus', '<span class="badge  badge-status6 badge-status" title="Tous les produits reçus">Produits reçus</span>');
		$this->assertSame(array('status' => 'Tous les produits reçus', 'status_short' => 'Produits reçus', 'status_type' => 6), $s);
		$this->assertSame(1, InboxElements::statusOf('Validée', '<span class="badge badge-status1b badge-status">Validée</span>')['status_type'], 'variant 1b');
		$s = InboxElements::statusOf('Brouillon', '<img src="x.png" class="inline-block status0" title="Brouillon">');
		$this->assertSame(array('status' => 'Brouillon', 'status_short' => 'Brouillon', 'status_type' => 0), $s, 'picto alone: kind of the picto, its title');
		$this->assertSame(-1, InboxElements::statusOf('Actif', 'Actif')['status_type'], 'no badge: unknown kind');
	}

	/**
	 * @return void
	 */
	public function testKeysForOtherModules()
	{
		$devis = $this->box(true, array('status' => InboxAccount::STATUS_ACTIVE));
		(new InboxAccess($this->db))->grant($this->admin, $devis->id, $this->admin->id, 0, 'manage');
		InboxAccess::clearCache();
		$folder = $this->folder($devis->id, 'INBOX', 'inbox');
		$refs = new InboxMailRef($this->db);

		// A mail without Message-ID: a print as its key, found again in the index
		$m = $this->mail($devis->id, $folder, '');
		$key = $refs->keyOf($m);
		$this->assertStringStartsWith('fp:', $key);
		$ref = $refs->fetchOrCreate($m, $this->admin);
		$this->assertGreaterThan(0, $ref, $refs->error);
		$this->assertSame((int) $m->rowid, $refs->copies($ref)[0]['mail']);
		$this->assertSame(array((int) $m->rowid => $ref), $refs->forRows(array($m)));

		// A Message-ID given by another module (a link made elsewhere): one mailref, found when the mail is indexed
		$a = $refs->fetchOrCreateByMessageId('<l9@inbox-test.invalid>', array('origin' => 'doliconnector', 'subject' => 'Commande'));
		$this->assertGreaterThan(0, $a);
		$this->assertSame($a, $refs->fetchOrCreateByMessageId('l9@inbox-test.invalid'));
		$m9 = $this->mail($devis->id, $folder, 'l9@inbox-test.invalid');
		$this->assertSame(array((int) $m9->rowid => $a), $refs->forRows(array($m9)), 'no check print yet: any copy');
		$linker = new InboxLink($this->db);
		$this->assertGreaterThan(0, $linker->link($a, 'societe', 1, null, 'doliconnector', true), 'a module links without user and without trigger');
		$this->assertSame('doliconnector', $linker->listFor(array($a))[0]['origin']);
	}
}
