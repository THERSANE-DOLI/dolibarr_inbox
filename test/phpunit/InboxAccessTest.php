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
 * \file    inbox/test/phpunit/InboxAccessTest.php
 * \ingroup inbox
 * \brief   Levels per mailbox (CA-09 to CA-11, CA-13)
 */

/**
 * Tests of InboxAccess
 */
class InboxAccessTest extends InboxTestCase
{
	/**
	 * @return void
	 */
	public function testRules()
	{
		$p = $this->db->prefix();
		$alice = $this->user('alice');
		$bob = $this->user('bob');
		$carol = $this->user('carol');
		$soc = $this->db->getRow("SELECT rowid FROM ".$p."societe");
		$ext = $this->user('ext', is_object($soc) ? (int) $soc->rowid : 1);
		$this->db->query("INSERT INTO ".$p."usergroup (entity, nom, datec) VALUES (1, 'inboxtest_group', '".$this->db->idate(dol_now())."')");
		$gid = (int) $this->db->last_insert_id($p.'usergroup');
		$this->db->query("INSERT INTO ".$p."usergroup_user (entity, fk_user, fk_usergroup) VALUES (1, ".$bob->id.", ".$gid.")");
		$devis = $this->box(true, array('status' => InboxAccount::STATUS_ACTIVE));
		$perso = $this->box(true, array('email' => 'alice@inbox-test.invalid', 'type' => 'personal', 'fk_user_owner' => $alice->id, 'status' => InboxAccount::STATUS_ACTIVE));
		$acc = new InboxAccess($this->db);

		$this->assertSame('', $acc->getLevel($this->admin, $devis->id), 'admin of Dolibarr: no implicit access');
		$this->assertSame('manage', $acc->getLevel($alice, $perso->id), 'owner');
		$this->assertSame('', $acc->getLevel($bob, $perso->id));
		$this->assertSame(1, $acc->grant($this->admin, $devis->id, $alice->id, 0, 'read'));
		$this->assertSame(0, $acc->grant($this->admin, $devis->id, $alice->id, 0, 'read'), 'same level: nothing');
		$this->assertSame(1, $acc->grant($this->admin, $devis->id, $alice->id, 0, 'send'), 'level changed');
		$this->assertSame(1, $acc->grant($this->admin, $devis->id, $alice->id, 0, 'read'));
		$this->assertTrue($acc->check($alice, $devis->id, 'read'));
		$this->assertFalse($acc->check($alice, $devis->id, 'send'));
		$acc->grant($this->admin, $devis->id, 0, $gid, 'send');
		$this->assertSame('send', $acc->getLevel($bob, $devis->id), 'group: all its members');
		$this->assertSame('', $acc->getLevel($carol, $devis->id));
		$acc->grant($this->admin, $devis->id, $bob->id, 0, 'read');
		$this->assertSame('send', $acc->getLevel($bob, $devis->id), 'highest level wins');
		$this->assertLessThan(0, $acc->grant($this->admin, $devis->id, $ext->id, 0, 'read'), 'external user refused');
		$this->db->query("INSERT INTO ".$p."inbox_account_access (fk_account, fk_user, fk_usergroup, level, date_creation, fk_user_creat) VALUES (".$devis->id.", ".$ext->id.", 0, 'manage', '".$this->db->idate(dol_now())."', 1)");
		InboxAccess::clearCache();
		$this->assertSame('', $acc->getLevel($ext, $devis->id), 'external user never, even with a line');
		$this->assertLessThan(0, $acc->grant($this->admin, $devis->id, $alice->id, $gid, 'read'));
		$this->assertLessThan(0, $acc->grant($this->admin, $devis->id, $carol->id, 0, 'owner'));
		$devis->status = InboxAccount::STATUS_DISABLED;
		$devis->update($this->admin);
		InboxAccess::clearCache();
		$this->assertSame('', $acc->getLevel($bob, $devis->id), 'disabled mailbox: nobody');
		$devis->status = InboxAccount::STATUS_ACTIVE;
		$devis->update($this->admin);
		$this->db->query("UPDATE ".$p."user SET statut = 0 WHERE rowid = ".$alice->id);
		$alice->fetch($alice->id);
		InboxAccess::clearCache();
		$this->assertSame('', $acc->getLevel($alice, $perso->id), 'disabled user: nothing');
		$this->assertSame(1, $acc->revoke($this->admin, $devis->id, 0, $gid));
		$this->assertSame('read', $acc->getLevel($bob, $devis->id));
		$actions = array_column((new InboxAccountLog($this->db))->listForAccount($devis->id, 100), 'action');
		foreach (array('access_add', 'access_change', 'access_remove', 'status') as $a) {
			$this->assertContains($a, $actions, 'CA-13 journal: '.$a);
		}
	}
}
