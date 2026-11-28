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
 * \file    inbox/test/phpunit/InboxThreadLinkTest.php
 * \ingroup inbox
 * \brief   1.73.0: the link follows the conversation (link the thread, new mails linked, detach, undo)
 */

// From the command line only, never from the web: test/ stays in the Git branches
if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

require_once __DIR__.'/InboxTestCase.php';
dol_include_once('/inbox/class/inboxthreadlink.class.php');

/**
 * Mails of the tests: Message-ID of the test domain (removed by InboxTestCase::cleanup())
 */
class InboxThreadLinkTest extends InboxTestCase
{
	/** @var int */
	protected $account = 0;

	/** @var int */
	protected $folder = 0;

	/**
	 * @param string $mid     Message-ID
	 * @param string $thread  Key of the conversation
	 * @param int    $folder  Folder (0: the inbox)
	 * @param string $subject Subject
	 * @return object          Row
	 */
	protected function mail($mid, $thread, $folder = 0, $subject = 'Devis')
	{
		$p = $this->db->prefix();
		$this->db->query("INSERT INTO ".$p."inbox_mail (fk_account, fk_folder, remote_id, sort_key, message_id, date_mail, from_email, subject, size, thread_key, date_index) VALUES ("
			.$this->account.", ".($folder ?: $this->folder).", '".$this->db->escape($mid)."', 1, '".$this->db->escape($mid)."', '2026-11-02 10:00:00', 'client@acme.inbox-test.invalid', '".$this->db->escape($subject)."', 10,"
			." '".$this->db->escape($thread)."', '".$this->db->idate(dol_now())."')");

		return $this->db->getRow("SELECT * FROM ".$p."inbox_mail WHERE rowid = ".((int) $this->db->last_insert_id($p.'inbox_mail')));
	}

	/**
	 * @param object $row Mail
	 * @return array<int,array<string,mixed>> Its links
	 */
	protected function linksOf($row)
	{
		$ref = (new InboxMailRef($this->db))->forRows(array($row));

		return isset($ref[(int) $row->rowid]) ? (new InboxLink($this->db))->listFor(array($ref[(int) $row->rowid])) : array();
	}

	/**
	 * @return void
	 */
	public function testThreadFollows()
	{
		$p = $this->db->prefix();
		$box = $this->box(true, array('status' => InboxAccount::STATUS_ACTIVE));
		$this->account = (int) $box->id;
		(new InboxAccess($this->db))->grant($this->admin, $box->id, $this->admin->id, 0, 'manage');
		InboxAccess::clearCache();
		$this->db->query("INSERT INTO ".$p."inbox_folder (fk_account, path, name, delimiter, role, status) VALUES (".$this->account.", 'INBOX', 'INBOX', '.', 'inbox', 1)");
		$this->folder = (int) $this->db->last_insert_id($p.'inbox_folder');
		$this->db->query("INSERT INTO ".$p."inbox_folder (fk_account, path, name, delimiter, role, status) VALUES (".$this->account.", 'Trash', 'Trash', '.', 'trash', 1)");
		$trash = (int) $this->db->last_insert_id($p.'inbox_folder');
		$this->db->query("INSERT INTO ".$p."societe (nom, entity, status, client, datec) VALUES ('inboxtest_thread', 1, 1, 1, '".$this->db->idate(dol_now())."')");
		$soc = (int) $this->db->last_insert_id($p.'societe');

		$a = $this->mail('t1@inbox-test.invalid', 'tk1');
		$b = $this->mail('t2@inbox-test.invalid', 'tk1');
		$c = $this->mail('t3@inbox-test.invalid', 'tk1');
		$this->mail('t1@inbox-test.invalid', 'tk1', $trash);
		$this->mail('t9@inbox-test.invalid', 'tk1', $trash);
		$other = $this->mail('o1@inbox-test.invalid', 'tk2');
		$threads = new InboxThreadLink($this->db);
		$this->assertSame(array('others' => 2, 'detached' => false, 'stray' => 0), $threads->state($a), 'its copy and the trash left out');

		// Linking a mail links the others of its conversation, origin « thread »
		$refA = (new InboxMailRef($this->db))->fetchOrCreate($a, $this->admin);
		(new InboxLink($this->db))->link($refA, 'societe', $soc, $this->admin);
		$this->assertSame(2, $threads->linkThread($this->admin, $a, 'societe', $soc));
		$this->assertSame(array('thread'), array_column($this->linksOf($b), 'origin'));
		$this->assertSame(array(), $this->linksOf($other), 'another conversation');
		$this->assertSame(0, $threads->linkThread($this->admin, $a, 'societe', $soc), 'never twice');

		// A new mail of the conversation: linked at the synchronisation
		$d = $this->mail('t4@inbox-test.invalid', 'tk1');
		$this->assertSame(1, $threads->followNew($this->account, array((int) $d->rowid)));
		$this->assertSame(array('societe'), array_column($this->linksOf($d), 'type'));
		// Someone who is not a correspondent of the conversation (Message-ID copied): no piece (audit INBX-067)
		$forged = $this->mail('t8@inbox-test.invalid', 'tk1');
		$this->db->query("UPDATE ".$p."inbox_mail SET from_email = 'stranger@evil.inbox-test.invalid' WHERE rowid = ".((int) $forged->rowid));
		$this->assertSame(0, $threads->followNew($this->account, array((int) $forged->rowid)));
		$this->assertSame(array(), $this->linksOf($forged));
		$this->db->query("DELETE FROM ".$p."inbox_mail WHERE rowid = ".((int) $forged->rowid));

		// Detached: its links of the conversation go, it gets no new one, its own links stay on it
		$removed = $threads->detach($this->admin, $c);
		$this->assertSame(array(array('type' => 'societe', 'element' => $soc)), $removed);
		$this->assertSame(array(), $this->linksOf($c));
		$this->assertTrue($threads->state($c)['detached']);
		$this->assertSame(0, $threads->linkThread($this->admin, $c, 'societe', $soc), 'a detached mail does not spread');
		$this->db->query("INSERT INTO ".$p."societe (nom, entity, status, client, datec) VALUES ('inboxtest_other', 1, 1, 1, '".$this->db->idate(dol_now())."')");
		$soc2 = (int) $this->db->last_insert_id($p.'societe');
		$refC = (new InboxMailRef($this->db))->fetchOrCreate($c, $this->admin);
		(new InboxLink($this->db))->link($refC, 'societe', $soc2, $this->admin);
		$this->db->query("INSERT INTO ".$p."societe (nom, entity, status, client, datec) VALUES ('inboxtest_third', 1, 1, 1, '".$this->db->idate(dol_now())."')");
		$soc3 = (int) $this->db->last_insert_id($p.'societe');
		$this->assertSame(2, $threads->linkThread($this->admin, $b, 'societe', $soc3), 'the others are reached (a, d), not the detached one');
		$this->assertNotContains($soc3, array_column($this->linksOf($c), 'element'));
		$e = $this->mail('t5@inbox-test.invalid', 'tk1');
		$threads->followNew($this->account, array((int) $e->rowid));
		$this->assertEqualsCanonicalizing(array($soc, $soc3), array_column($this->linksOf($e), 'element'), 'the piece of the detached mail stays on it');
		$this->assertSame(array($soc2), array_column($this->linksOf($c), 'element'));

		// Undo: back in the conversation with its links
		$this->assertSame(1, $threads->attach($this->admin, $c, $removed));
		$this->assertFalse($threads->state($c)['detached']);
		$this->assertEqualsCanonicalizing(array($soc, $soc2), array_column($this->linksOf($c), 'element'));

		$this->db->query("DELETE FROM ".$p."societe WHERE rowid IN (".$soc.", ".$soc2.", ".$soc3.")");
	}

	/**
	 * @return void
	 */
	public function testSameSubject()
	{
		$this->assertTrue(InboxThreadLink::sameSubject('Enlèvement site A', 'RE: TR: enlèvement  site a'));
		$this->assertTrue(InboxThreadLink::sameSubject('URGENT - Enlèvement site A', 'Enlèvement site A'), 'a subject retouched');
		$this->assertTrue(InboxThreadLink::sameSubject('', 'Enlèvement site A'), 'an empty subject never separates');
		$this->assertFalse(InboxThreadLink::sameSubject('Enlèvement site A // réf. 1', 'Enlèvement site B // réf. 2'));

		// Cases of a real mailbox (measure of 1.139.0): kept together
		$same = array(
			'a long header folded' => array('ECHOREFLEX - N° Exp : 26-03-0211417 - THERSANE - SIEGFRIED EVIONNAZ SA', 'Re: ECHOREFLEX - N° Exp : 26-03-0211417 - THERSANE - SIEGFRI ED EVIONNAZ SA'),
			'a folded word' => array('Votre référence de commande CF2609-01848 AR 29225 attente BL', 'RE: Votre référence de commande CF2609-01848 AR 29225 attente B L'),
			'punctuation lost' => array('Colis N°10713006853563 / Retour sur l’état du colis livré', 'Re: Colis N°10713006853563 / Retour sur létat du colis livré'),
			'a space added' => array('RE: Demande de prix THERSANE RQ2603-30091 / Devisn°1080986', 'RE: Demande de prix THERSANE RQ2603-30091 / Devis n°1080986'),
			'a reference in common' => array('Re: Demande de prix THERSANE RQ2511-28231', '[AG] Offer - your enquiry no. RQ2511-28231, our reference:-30059385'),
			'cancel and replace' => array('Re: INTERINOX - Devis DVV2603662', 'Annule et remplace Devis DVV2603662'),
			'the order of a quote' => array('RE: Offre de prix THERSANE DEVIS2606219', 'THERSANE DEVIS2606219 // BC 9RJ3885'),
			'a typo fixed' => array('Fermeture exceptionelle', 'Rappel : Fermeture exceptionnelle'),
			'an answer to an invitation' => array('Invitation mise à jour : Réunion de chantier', 'Accepté : Réunion de chantier'),
			'an answer declined' => array('Invitation : Réunion de chantier', 'Refusé : Réunion de chantier'),
			'an answer in English' => array('Updated invitation: Site meeting', 'Tentatively accepted: Site meeting'),
			'an event cancelled' => array('Invitation: Site meeting', 'Canceled event: Site meeting'),
		);
		foreach ($same as $why => $pair) {
			$this->assertTrue(InboxThreadLink::sameSubject($pair[0], $pair[1]), $why);
		}
		// Another request
		$other = array(
			'another product' => array('cde COLLET', 'cde Bride et bouchon'),
			'another order' => array('Certificats commande THERSANE CF2512-02434 (AR: 1196786)', 'RE: Certificats commande THERSANE CF2512-02435 (AR: 702541)'),
			'another grade' => array('DEMANDE DE PRIX ROND 304L', 'DEMANDE DE PRIX ROND 316L'),
			'another site' => array('CHANTIER 22607015 EMMI DESSERTS', 'CHANTIER 22607017 EMMI DESSERTS'),
			'another purchase order' => array('THERSANE 4403526578', 'THERSANE 4403527849'),
			'another event' => array('Invitation : Réunion de chantier 12', 'Accepté : Réunion de chantier 14'),
		);
		foreach ($other as $why => $pair) {
			$this->assertFalse(InboxThreadLink::sameSubject($pair[0], $pair[1]), $why);
		}
	}

	/**
	 * A correspondent sends each new request as a reply to the previous one, the subject replaced: the piece of one
	 * request stays on it, and the links made before are offered for removal
	 *
	 * @return void
	 */
	public function testNewRequestAsReply()
	{
		$p = $this->db->prefix();
		$box = $this->box(true, array('status' => InboxAccount::STATUS_ACTIVE));
		$this->account = (int) $box->id;
		(new InboxAccess($this->db))->grant($this->admin, $box->id, $this->admin->id, 0, 'manage');
		InboxAccess::clearCache();
		$this->db->query("INSERT INTO ".$p."inbox_folder (fk_account, path, name, delimiter, role, status) VALUES (".$this->account.", 'INBOX', 'INBOX', '.', 'inbox', 1)");
		$this->folder = (int) $this->db->last_insert_id($p.'inbox_folder');
		$socs = array();
		foreach (array('a', 'b', 'c') as $n) {
			$this->db->query("INSERT INTO ".$p."societe (nom, entity, status, client, datec) VALUES ('inboxtest_req_".$n."', 1, 1, 1, '".$this->db->idate(dol_now())."')");
			$socs[$n] = (int) $this->db->last_insert_id($p.'societe');
		}
		$refs = new InboxMailRef($this->db);
		$linker = new InboxLink($this->db);
		$threads = new InboxThreadLink($this->db);

		$m1 = $this->mail('r1@inbox-test.invalid', 'tkr', 0, 'Enlèvement site A // réf. 1');
		$m2 = $this->mail('r2@inbox-test.invalid', 'tkr', 0, 'Enlèvement site B // réf. 2');
		$a1 = $this->mail('r3@inbox-test.invalid', 'tkr', 0, 'RE: Enlèvement site A // réf. 1');
		$this->assertSame(1, $threads->state($m1)['others'], 'only the answer of the same subject');

		// The piece of request 1 reaches its answer, not request 2
		$linker->link($refs->fetchOrCreate($m1, $this->admin), 'societe', $socs['a'], $this->admin, 'module');
		$this->assertSame(1, $threads->linkThread($this->admin, $m1, 'societe', $socs['a']));
		$this->assertSame(array($socs['a']), array_column($this->linksOf($a1), 'element'));
		$this->assertSame(array(), $this->linksOf($m2));
		$linker->link($refs->fetchOrCreate($m2, $this->admin), 'societe', $socs['b'], $this->admin, 'module');
		$this->assertSame(0, $threads->linkThread($this->admin, $m2, 'societe', $socs['b']));

		// New mails: each gets the pieces of its subject only
		$m3 = $this->mail('r4@inbox-test.invalid', 'tkr', 0, 'Enlèvement site C // réf. 3');
		$a2 = $this->mail('r5@inbox-test.invalid', 'tkr', 0, 'RE: Enlèvement site B // réf. 2');
		$u1 = $this->mail('r6@inbox-test.invalid', 'tkr', 0, 'URGENT - Enlèvement site A // réf. 1');
		$this->assertSame(2, $threads->followNew($this->account, array((int) $m3->rowid, (int) $a2->rowid, (int) $u1->rowid)));
		$this->assertSame(array(), $this->linksOf($m3));
		$this->assertSame(array($socs['b']), array_column($this->linksOf($a2), 'element'));
		$this->assertSame(array($socs['a']), array_column($this->linksOf($u1), 'element'));
		$this->assertSame(0, $threads->state($m1)['stray']);

		// Links made by an earlier version: offered, removed once validated, given back by the undo
		$linker->link($refs->fetchOrCreate($m3, $this->admin), 'societe', $socs['a'], null, InboxThreadLink::ORIGIN);
		$linker->link($refs->fetchOrCreate($m2, $this->admin), 'societe', $socs['a'], null, InboxThreadLink::ORIGIN);
		// Without a mail holding the piece by itself nothing proves it is stray: kept
		$linker->link($refs->fetchOrCreate($m3, $this->admin), 'societe', $socs['c'], null, InboxThreadLink::ORIGIN);
		$stray = $threads->strayLinks($a2);
		$this->assertEqualsCanonicalizing(array((int) $m3->rowid, (int) $m2->rowid), array_column($stray, 'mail'));
		$this->assertSame(2, $threads->state($m1)['stray']);
		$good = 0;
		foreach ($this->linksOf($a1) as $l) {
			$good = $l['id'];
		}
		$onM3 = array_values(array_filter($stray, function ($l) use ($m3) {
			return $l['mail'] === (int) $m3->rowid;
		}))[0]['link'];
		$removed = $threads->removeStray($a2, array($onM3, $good));
		$this->assertSame(array(array('mail' => (int) $m3->rowid, 'type' => 'societe', 'element' => $socs['a'])), $removed, 'a link not stray is never removed');
		$this->assertSame(array($socs['c']), array_column($this->linksOf($m3), 'element'));
		$this->assertSame(array($socs['a']), array_column($this->linksOf($a1), 'element'));
		$this->assertSame(1, $threads->state($m1)['stray']);
		$this->assertSame(1, $threads->restoreStray($this->admin, $a2, $removed));
		$this->assertEqualsCanonicalizing(array($socs['a'], $socs['c']), array_column($this->linksOf($m3), 'element'));
		$this->assertSame(0, $threads->restoreStray($this->admin, $a2, array(array('mail' => 999999999, 'type' => 'societe', 'element' => $socs['a']))), 'only mails of the conversation');

		$this->db->query("DELETE FROM ".$p."societe WHERE rowid IN (".implode(',', $socs).")");
	}

	/**
	 * A mail filed in a folder of the user (no role: « Clients », « Orders »...) is a mail of its conversation
	 *
	 * @return void
	 */
	public function testFolderWithoutRole()
	{
		$p = $this->db->prefix();
		$box = $this->box(true, array('status' => InboxAccount::STATUS_ACTIVE));
		$this->account = (int) $box->id;
		(new InboxAccess($this->db))->grant($this->admin, $box->id, $this->admin->id, 0, 'manage');
		InboxAccess::clearCache();
		$this->db->query("INSERT INTO ".$p."inbox_folder (fk_account, path, name, delimiter, role, status) VALUES (".$this->account.", 'INBOX', 'INBOX', '.', 'inbox', 1)");
		$this->folder = (int) $this->db->last_insert_id($p.'inbox_folder');
		$this->db->query("INSERT INTO ".$p."inbox_folder (fk_account, path, name, delimiter, role, status) VALUES (".$this->account.", 'INBOX.Clients', 'Clients', '.', NULL, 1)");
		$clients = (int) $this->db->last_insert_id($p.'inbox_folder');
		$this->db->query("INSERT INTO ".$p."societe (nom, entity, status, client, datec) VALUES ('inboxtest_folder', 1, 1, 1, '".$this->db->idate(dol_now())."')");
		$soc = (int) $this->db->last_insert_id($p.'societe');
		$a = $this->mail('f1@inbox-test.invalid', 'tkf');
		$b = $this->mail('f2@inbox-test.invalid', 'tkf', $clients);
		$threads = new InboxThreadLink($this->db);
		$this->assertSame(1, $threads->state($a)['others']);
		$this->assertSame(1, $threads->linkThread($this->admin, $a, 'societe', $soc));
		$this->assertSame(array($soc), array_column($this->linksOf($b), 'element'));
		$c = $this->mail('f3@inbox-test.invalid', 'tkf', $clients);
		$this->assertSame(1, $threads->followNew($this->account, array((int) $c->rowid)), 'a new mail filed there too');

		$this->db->query("DELETE FROM ".$p."societe WHERE rowid = ".$soc);
	}
}
