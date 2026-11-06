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
 * \file    inbox/test/phpunit/InboxIdentityTagParamTest.php
 * \ingroup inbox
 * \brief   Identities, tags, settings of the users (CA-14, CA-15, CA-16)
 */

/**
 * Tests of InboxIdentity, InboxTag, InboxUserParam
 */
class InboxIdentityTagParamTest extends InboxTestCase
{
	/**
	 * @return void
	 */
	public function testIdentities()
	{
		$a = $this->box(true);
		$i1 = new InboxIdentity($this->db);
		$i1->fk_account = $a->id;
		$i1->email = ' Devis@Inbox-Test.invalid';
		$i1->name = "Service\ndevis";
		$i1->signature = '<p>Cordialement</p><script>alert(1)</script><img src=x onerror="alert(2)">';
		$this->assertGreaterThan(0, $i1->create($this->admin));
		$this->assertSame(1, $i1->is_default);
		$r = new InboxIdentity($this->db);
		$r->fetch($i1->id);
		$this->assertStringNotContainsString('<script', $r->signature);
		$this->assertStringNotContainsStringIgnoringCase('onerror', $r->signature);
		$this->assertSame('Service devis', $r->name);
		$i2 = new InboxIdentity($this->db);
		$i2->fk_account = $a->id;
		$i2->email = 'offres@inbox-test.invalid';
		$i2->is_default = 1;
		$i2->create($this->admin);
		$list = (new InboxIdentity($this->db))->listForAccount($a->id);
		$this->assertSame($i2->id, $list[0]->id);
		$this->assertSame(0, $list[1]->is_default, 'one default only');
		$i2->fetch($i2->id);
		$i2->delete();
		$this->assertSame(1, (new InboxIdentity($this->db))->listForAccount($a->id)[0]->is_default, 'the default passes to the next one');
		$bad = new InboxIdentity($this->db);
		$bad->fk_account = $a->id;
		$bad->email = 'nope';
		$bad->reply_to = 'bad';
		$this->assertLessThan(0, $bad->create($this->admin));
		$this->assertFalse($bad->copyFromSenderProfile(999999));
	}

	/**
	 * @return void
	 */
	public function testTags()
	{
		$a = $this->box(true);
		$tag = new InboxTag($this->db);
		$id = $tag->create($this->admin, 'ZZ test attente', '#aa00cc', 0);
		$this->assertGreaterThan(0, $id);
		$list = $tag->listTags(0);
		$last = end($list);
		$this->assertSame('zz_test_attente', $last['keyword']);
		$this->assertSame('#AA00CC', $last['color']);
		$this->assertSame('zz_test_attente_2', $tag->makeKeyword('ZZ test attente', 0));
		$this->assertSame('zz_test_a_b_c', $tag->makeKeyword('zz test (a) {b} "c"', 0), 'IMAP atom characters only');
		$this->assertLessThan(0, $tag->create($this->admin, 'x', 'red', 0));
		$tag->update($this->admin, $id, 'Attente', '#00AA00', 1);
		$list = $tag->listTags(0);
		$this->assertSame('zz_test_attente', end($list)['keyword'], 'rename keeps the keyword');
		$tag->setMode($this->admin, $a, 'own');
		$this->assertCount(count($tag->listTags(0)), $tag->listTags($a->id));
		$tag->create($this->admin, 'Propre', '#123456', $a->id);
		$tag->setMode($this->admin, $a, 'global');
		$tag->setMode($this->admin, $a, 'own');
		$this->assertCount(count($tag->listTags(0)) + 1, $tag->listTags($a->id), 'own list kept, not copied again');
		$tag->delete($id);
	}

	/**
	 * CA-16: cascade user + mailbox, user, setup, code
	 *
	 * @return void
	 */
	public function testUserParams()
	{
		$a = $this->box(true);
		$u = $this->user('param');
		$up = new InboxUserParam($this->db);
		$this->assertSame('delay', $up->get($u, 'read_mode'));
		$up->set($u, 'read_delay', '10');
		$this->assertSame('10', $up->get($u, 'read_delay', $a->id));
		$up->set($u, 'read_delay', '0', $a->id);
		$this->assertSame('0', $up->get($u, 'read_delay', $a->id));
		$this->assertSame('10', $up->get($u, 'read_delay'));
		foreach (array(array('read_delay', '9999'), array('read_mode', 'later'), array('unknown', 'x'), array('list_columns', '{bad'), array('theme', '<script>')) as $bad) {
			$this->assertLessThan(0, $up->set($u, $bad[0], $bad[1]), $bad[0]);
		}
		$up->set($u, 'read_delay', '11');
		$this->assertSame(1, (int) $this->db->getRow("SELECT COUNT(*) AS n FROM ".$this->db->prefix()."inbox_userparam WHERE fk_user = ".$u->id." AND fk_account = 0 AND param = 'read_delay'")->n);
	}
}
