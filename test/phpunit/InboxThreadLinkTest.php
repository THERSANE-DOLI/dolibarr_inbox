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
 * \brief   1.73.0 (lot A3): the link follows the conversation (link the thread, new mails linked, detach, undo)
 */

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
	 * @param string $mid    Message-ID
	 * @param string $thread Key of the conversation
	 * @param int    $folder Folder (0: the inbox)
	 * @return object         Row
	 */
	protected function mail($mid, $thread, $folder = 0)
	{
		$p = $this->db->prefix();
		$this->db->query("INSERT INTO ".$p."inbox_mail (fk_account, fk_folder, remote_id, sort_key, message_id, date_mail, from_email, subject, size, thread_key, date_index) VALUES ("
			.$this->account.", ".($folder ?: $this->folder).", '".$this->db->escape($mid)."', 1, '".$this->db->escape($mid)."', '2026-11-02 10:00:00', 'client@acme.inbox-test.invalid', 'Devis', 10,"
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
		$this->assertSame(array('others' => 2, 'detached' => false), $threads->state($a), 'its copy and the trash left out');

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
}
