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
 * \file    inbox/test/phpunit/InboxAssignTest.php
 * \ingroup inbox
 * \brief   Assignment of the mails and notifications of the bell (lot W4 1.9.0)
 */

require_once dirname(__DIR__, 2).'/class/inboxassign.class.php';
require_once dirname(__DIR__, 2).'/class/inboxsearchsql.class.php';
require_once dirname(__DIR__, 2).'/core/triggers/interface_95_modInbox_InboxTriggers.class.php';

/**
 * Tests of InboxAssign, InboxMailRef and InboxNotification (database only, no mail server)
 */
class InboxAssignTest extends InboxTestCase
{
	/**
	 * @return void
	 */
	protected function setUp(): void
	{
		parent::setUp();
		InboxAssign::clearCache();
		InboxAccess::clearCache();
	}

	/**
	 * Folder of the index, without the server
	 *
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
	 * Mail of the index, without the server
	 *
	 * @param int    $accountId Mailbox
	 * @param int    $folderId  Folder
	 * @param string $messageId Message-ID ('' = none)
	 * @param string $from      Sender
	 * @return int
	 */
	protected function mail($accountId, $folderId, $messageId, $from = 'client@inbox-test.invalid')
	{
		static $uid = 1000;
		$p = $this->db->prefix();
		$uid++;
		$this->db->query("INSERT INTO ".$p."inbox_mail (fk_account, fk_folder, remote_id, sort_key, message_id, date_mail, date_received, from_email, subject, date_index) VALUES ("
			.((int) $accountId).", ".((int) $folderId).", '".$uid."', ".$uid.", ".($messageId !== '' ? "'".$this->db->escape($messageId)."'" : 'NULL').", '2026-11-02 10:00:00', '2026-11-02 10:00:05',"
			." '".$this->db->escape($from)."', 'Demande de prix', '".$this->db->idate(dol_now())."')");

		return (int) $this->db->last_insert_id($p.'inbox_mail');
	}

	/**
	 * @param int[] $mailIds Rows
	 * @return array<int,int[]>
	 */
	protected function assignees(array $mailIds)
	{
		$rows = $this->db->getRows("SELECT rowid, fk_account, message_id, date_mail, from_email FROM ".$this->db->prefix()."inbox_mail WHERE rowid IN (".implode(',', $mailIds).") LIMIT 100");
		$assign = new InboxAssign($this->db);

		return $assign->forRows($rows);
	}

	/**
	 * An assignment added or removed changes the date of change of the mail: the stamp of its folder changes, the
	 * lists open by the other users are refreshed
	 *
	 * @return void
	 */
	public function testAssignChangesTheMail()
	{
		$alice = $this->user('alice');
		$bob = $this->user('bob');
		$devis = $this->box(true, array('status' => InboxAccount::STATUS_ACTIVE));
		$acc = new InboxAccess($this->db);
		$acc->grant($this->admin, $devis->id, $alice->id, 0, 'read');
		$acc->grant($this->admin, $devis->id, $bob->id, 0, 'read');
		InboxAssign::clearCache();
		$inbox = $this->folder($devis->id, 'INBOX', 'inbox');
		$m1 = $this->mail($devis->id, $inbox, '<t1@inbox-test.invalid>');
		$m2 = $this->mail($devis->id, $inbox, '<t2@inbox-test.invalid>');
		$p = $this->db->prefix();
		$old = function () use ($p, $m1, $m2) {
			$this->db->query("UPDATE ".$p."inbox_mail SET tms = '2020-01-01 00:00:00' WHERE rowid IN (".$m1.", ".$m2.")");
		};
		$changed = function ($id) use ($p) {
			$row = $this->db->getRow("SELECT tms FROM ".$p."inbox_mail WHERE rowid = ".((int) $id));

			return (int) $this->db->jdate($row->tms) > dol_mktime(0, 0, 0, 1, 1, 2021);
		};
		$assign = new InboxAssign($this->db);

		$old();
		$this->assertTrue($assign->set($alice, array($m1), array($bob->id), array())['ok']);
		$this->assertTrue($changed($m1), 'assignment added');
		$this->assertFalse($changed($m2), 'the other mails are not touched');

		$old();
		$assign->set($alice, array($m1), array($bob->id), array());
		$this->assertFalse($changed($m1), 'nothing changed, the date stays');

		$old();
		$assign->set($alice, array($m1), array(), array($bob->id));
		$this->assertTrue($changed($m1), 'assignment removed');
	}

	/**
	 * @return void
	 */
	public function testAssignFollowsTheMail()
	{
		$alice = $this->user('alice');
		$bob = $this->user('bob');
		$carol = $this->user('carol');
		$devis = $this->box(true, array('status' => InboxAccount::STATUS_ACTIVE));
		$contact = $this->box(true, array('email' => 'contact@inbox-test.invalid', 'status' => InboxAccount::STATUS_ACTIVE));
		$acc = new InboxAccess($this->db);
		$acc->grant($this->admin, $devis->id, $alice->id, 0, 'read');
		$acc->grant($this->admin, $devis->id, $bob->id, 0, 'read');
		$acc->grant($this->admin, $contact->id, $alice->id, 0, 'read');
		InboxAssign::clearCache();
		$inbox = $this->folder($devis->id, 'INBOX', 'inbox');
		$m1 = $this->mail($devis->id, $inbox, '<a1@inbox-test.invalid>');
		$m2 = $this->mail($devis->id, $inbox, '<a2@inbox-test.invalid>');
		$none = $this->mail($devis->id, $inbox, '');
		$assign = new InboxAssign($this->db);

		$res = $assign->set($alice, array($m1), array($alice->id), array());
		$this->assertTrue($res['ok'], $res['error']);
		$this->assertSame(1, $res['count']);
		$this->assertSame(array($m1 => array($alice->id)), $this->assignees(array($m1, $m2)));
		$notify = new InboxNotification($this->db);
		$this->assertSame(0, $notify->counts($alice)['unread'], 'nothing when one assigns oneself');

		$res = $assign->set($alice, array($m1), array($bob->id), array());
		$this->assertSame(array($alice->id, $bob->id), $this->assignees(array($m1))[$m1]);
		$this->assertSame(1, $notify->counts($bob)['unread'], 'bob is told');
		$list = $notify->listFor($bob);
		$this->assertSame('assign', $list['items'][0]['type']);
		$this->assertSame('Demande de prix', $list['items'][0]['subject']);
		$this->assertSame((int) $alice->id, $list['items'][0]['actor']['id']);

		// Again within the minute: one notification
		$assign->set($alice, array($m1), array(), array($bob->id));
		$this->assertSame(0, $notify->counts($bob)['unread'], 'removed at once: the notification still unread goes');
		$assign->set($alice, array($m1), array($bob->id), array());
		$assign->set($alice, array($m1), array($bob->id), array());
		$this->assertSame(1, $notify->counts($bob)['unread']);

		$res = $assign->set($alice, array($m1), array($carol->id), array());
		$this->assertSame('InboxErrorAssignNoAccess', $res['error'], 'a person without access is never assigned');
		$res = $assign->set($carol, array($m1), array($carol->id), array());
		$this->assertSame('InboxMailNotFound', $res['error'], 'a mail of a mailbox without access');
		// A mail without Message-ID: its key is a print of the mail (1.13.0), it is assigned as the others
		$res = $assign->set($alice, array($none), array($alice->id), array());
		$this->assertTrue($res['ok'], $res['error']);

		// Journal of the actions of the mailbox
		$journal = InboxActionLog::listForAccount($this->db, $devis->id, array('action' => 'assign'));
		$this->assertNotEmpty($journal['lines']);
		$this->assertStringContainsString('bob', implode(' ', array_column($journal['lines'], 'detail')));

		// Moved to another folder, then copied to another mailbox: the assignment follows (the Message-ID)
		$archive = $this->folder($devis->id, 'Archives', 'archive');
		$this->db->query("UPDATE ".$this->db->prefix()."inbox_mail SET fk_folder = ".$archive.", remote_id = '9999' WHERE rowid = ".$m1);
		$this->assertSame(array($alice->id, $bob->id), $this->assignees(array($m1))[$m1]);
		$box2 = $this->folder($contact->id, 'INBOX', 'inbox');
		$copy = $this->mail($contact->id, $box2, '<a1@inbox-test.invalid>');
		$this->assertSame(array($alice->id), $this->assignees(array($copy))[$copy], 'bob cannot read contact@: not shown there');
		// Same Message-ID, another mail (other sender): not the same mailref
		$other = $this->mail($contact->id, $box2, '<a1@inbox-test.invalid>', 'someone@inbox-test.invalid');
		$this->assertArrayNotHasKey($other, $this->assignees(array($other)));

		// « My mails »: in the folder; out of a folder, the archives only if asked
		$engine = new InboxSearchSql($this->db);
		$found = $engine->search(array($devis->id), $archive, array('mine' => $alice->id), '', 50);
		$this->assertSame(array($m1), array_column($found['rows'], 'id'));
		$found = $engine->search(array($devis->id), 0, array('mine' => $alice->id), '', 50);
		$this->assertSame(array($none), array_column($found['rows'], 'id'), 'archived: out of my mails; the mail without Message-ID is found by its print');
		$found = $engine->search(array($devis->id), 0, array('mine' => $alice->id, 'mine_archived' => true), '', 50);
		$this->assertEqualsCanonicalizing(array($m1, $none), array_column($found['rows'], 'id'));

		// Lines of a list decorated, with the names
		$rows = $engine->search(array($devis->id), $archive, array(), '', 50)['rows'];
		$names = $assign->decorate($rows);
		$this->assertSame(array($alice->id, $bob->id), $rows[0]['assignees']);
		$this->assertSame(1, $rows[0]['has_mid']);
		$this->assertArrayHasKey($bob->id, $names);
	}

	/**
	 * @return void
	 */
	public function testNotificationLeadsToTheMail()
	{
		$alice = $this->user('alice');
		$bob = $this->user('bob');
		$devis = $this->box(true, array('status' => InboxAccount::STATUS_ACTIVE));
		$acc = new InboxAccess($this->db);
		$acc->grant($this->admin, $devis->id, $alice->id, 0, 'read');
		$acc->grant($this->admin, $devis->id, $bob->id, 0, 'read');
		InboxAssign::clearCache();
		$inbox = $this->folder($devis->id, 'INBOX', 'inbox');
		$trash = $this->folder($devis->id, 'Trash', 'trash');
		$m1 = $this->mail($devis->id, $inbox, '<n1@inbox-test.invalid>');
		$assign = new InboxAssign($this->db);
		$assign->set($alice, array($m1), array($bob->id), array());
		$notify = new InboxNotification($this->db);
		$id = $notify->listFor($bob)['items'][0]['id'];

		$this->assertSame(array('mail' => $m1, 'folder' => $inbox), $notify->target($bob, $id, array($devis->id)));
		$this->assertSame(0, $notify->counts($bob)['unread'], 'opened = read');
		$this->assertSame(array('error' => 'not_found'), $notify->target($alice, $id, array($devis->id)), 'the notification of another user');

		// A copy in the trash and one in the inbox: the inbox first
		$this->mail($devis->id, $trash, '<n1@inbox-test.invalid>');
		$this->assertSame($m1, $notify->target($bob, $id, array($devis->id))['mail']);

		// Access removed: the assignment goes, the notification says it without the mail
		$acc->revoke($this->admin, $devis->id, $bob->id, 0);
		InboxAccess::clearCache();
		$this->assertSame(1, $assign->prune($this->admin, $devis->id));
		$this->assertSame(array(), $this->assignees(array($m1)));
		$this->assertSame('no_access', $notify->target($bob, $id, array())['error']);

		// Removed from the index (and the server): gone
		$this->db->query("DELETE FROM ".$this->db->prefix()."inbox_mail WHERE message_id = '<n1@inbox-test.invalid>'");
		$this->assertSame('gone', $notify->target($bob, $id, array($devis->id))['error']);

		// Other modules: their own type and an address inside inbox only
		$own = $notify->add($bob->id, 'Other_Module', array('payload' => array('text' => 'Hello'), 'url' => 'https://evil.invalid/'));
		$this->assertGreaterThan(0, $own);
		$item = $notify->listFor($bob)['items'][0];
		$this->assertSame('other_module', $item['type']);
		$this->assertSame('', $item['url'], 'an outside address is dropped');
		$notify->markRead($bob);
		$this->assertSame(0, $notify->counts($bob)['unread']);
	}

	/**
	 * @return void
	 */
	public function testUserDeletedForgotten()
	{
		global $langs, $conf;

		$alice = $this->user('alice');
		$bob = $this->user('bob');
		$devis = $this->box(true, array('status' => InboxAccount::STATUS_ACTIVE));
		$acc = new InboxAccess($this->db);
		$acc->grant($this->admin, $devis->id, $alice->id, 0, 'read');
		$this->db->query("INSERT INTO ".$this->db->prefix()."usergroup (entity, nom, datec) VALUES (1, 'inboxtest_assign', '".$this->db->idate(dol_now())."')");
		$gid = (int) $this->db->last_insert_id($this->db->prefix().'usergroup');
		$this->db->query("INSERT INTO ".$this->db->prefix()."usergroup_user (entity, fk_user, fk_usergroup) VALUES (1, ".$bob->id.", ".$gid.")");
		$acc->grant($this->admin, $devis->id, 0, $gid, 'read');
		InboxAssign::clearCache();
		$inbox = $this->folder($devis->id, 'INBOX', 'inbox');
		$m1 = $this->mail($devis->id, $inbox, '<d1@inbox-test.invalid>');
		$assign = new InboxAssign($this->db);
		$this->assertTrue($assign->set($alice, array($m1), array($alice->id, $bob->id), array())['ok']);
		$this->assertSame(array($alice->id, $bob->id), $this->assignees(array($m1))[$m1], 'bob by his group');

		$trigger = new InterfaceInboxTriggers($this->db);
		$group = new stdClass();
		$group->id = $gid;
		$trigger->runTrigger('USERGROUP_DELETE', $group, $this->admin, $langs, $conf);
		InboxAssign::clearCache();
		$p = $this->db->prefix();
		$this->assertSame(0, (int) $this->db->getRow("SELECT COUNT(*) AS n FROM ".$p."inbox_mail_assign WHERE fk_user = ".$bob->id)->n, 'the group gone: bob loses the assignment');

		$trigger->runTrigger('USER_DELETE', $alice, $this->admin, $langs, $conf);
		$this->assertSame(0, (int) $this->db->getRow("SELECT COUNT(*) AS n FROM ".$p."inbox_mail_assign WHERE fk_user = ".$alice->id)->n);
		$this->assertSame(0, (int) $this->db->getRow("SELECT COUNT(*) AS n FROM ".$p."inbox_notification WHERE fk_user = ".$alice->id)->n);
	}
}
