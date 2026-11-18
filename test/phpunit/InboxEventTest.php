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
 * \file    test/phpunit/InboxEventTest.php
 * \ingroup inbox
 * \brief   Journal of the changes (1.121.0): numbers only, each user reads only his mailboxes, signed token
 */

require_once __DIR__.'/InboxTestCase.php';
require_once __DIR__.'/../../class/inboxevent.class.php';
require_once __DIR__.'/../../class/inboxassign.class.php';
require_once __DIR__.'/../../class/inboxnotification.class.php';

/**
 * Journal of the changes for the quicker refresh of the lists
 */
class InboxEventTest extends InboxTestCase
{
	/** @var string Setting before the test */
	protected $mode;

	/**
	 * @return void
	 */
	protected function setUp(): void
	{
		global $conf;

		parent::setUp();
		$this->mode = getDolGlobalString('INBOX_REACTIVITY');
		$conf->global->INBOX_REACTIVITY = 'fast';
		InboxAssign::clearCache();
	}

	/**
	 * @return void
	 */
	protected function tearDown(): void
	{
		global $conf;

		$conf->global->INBOX_REACTIVITY = $this->mode;
		parent::tearDown();
	}

	/**
	 * @param int    $accountId Mailbox
	 * @param string $path      Path
	 * @return int
	 */
	protected function folder($accountId, $path = 'INBOX')
	{
		$p = $this->db->prefix();
		$this->db->query("INSERT INTO ".$p."inbox_folder (fk_account, path, name, delimiter, role, status) VALUES (".((int) $accountId).", '".$this->db->escape($path)."', '".$this->db->escape($path)."', '.', 'inbox', 1)");

		return (int) $this->db->last_insert_id($p.'inbox_folder');
	}

	/**
	 * @param int    $accountId Mailbox
	 * @param int    $folderId  Folder
	 * @param string $messageId Message-ID
	 * @return int
	 */
	protected function mail($accountId, $folderId, $messageId)
	{
		static $uid = 5000;
		$uid++;
		$p = $this->db->prefix();
		$this->db->query("INSERT INTO ".$p."inbox_mail (fk_account, fk_folder, remote_id, sort_key, message_id, date_mail, date_received, from_email, subject, date_index) VALUES ("
			.((int) $accountId).", ".((int) $folderId).", '".$uid."', ".$uid.", '".$this->db->escape($messageId)."', '2026-11-02 10:00:00', '2026-11-02 10:00:05',"
			." 'secret.sender@inbox-test.invalid', 'Secret subject', '".$this->db->idate(dol_now())."')");

		return (int) $this->db->last_insert_id($p.'inbox_mail');
	}

	/**
	 * @return int Last event now
	 */
	protected function cursor()
	{
		$row = $this->db->getRow("SELECT MAX(rowid) AS m FROM ".$this->db->prefix()."inbox_event");

		return is_object($row) ? (int) $row->m : 0;
	}

	/**
	 * @return void
	 */
	public function testStandardModeWritesNothing()
	{
		global $conf;

		$conf->global->INBOX_REACTIVITY = '';
		$box = $this->box(true, array('status' => InboxAccount::STATUS_ACTIVE));
		$events = new InboxEvent($this->db);
		$this->assertFalse(InboxEvent::enabled());
		$this->assertSame(0, $events->emit($box->id, 'assign', array(1, 2)));
		$this->assertSame(0, $events->emitUsers(array(1), 'access'));
		$this->assertSame(0, $events->written);
	}

	/**
	 * Rhythm: active 3 to 60 s, idle from the active one to 300 s, never quicker than the active one
	 *
	 * @return void
	 */
	public function testRhythmBounds()
	{
		$this->assertSame(array('interval' => 5, 'idle' => 60), InboxEvent::rhythm(0, 0), 'defaults');
		$this->assertSame(array('interval' => 3, 'idle' => 3), InboxEvent::rhythm(1, 1), 'minimum');
		$this->assertSame(array('interval' => 60, 'idle' => 300), InboxEvent::rhythm(90, 900), 'maximum');
		$this->assertSame(array('interval' => 40, 'idle' => 40), InboxEvent::rhythm(40, 20), 'idle follows the active one');
		$this->assertSame(array('interval' => 5, 'idle' => 120), InboxEvent::rhythm(5, 120));
	}

	/**
	 * Each user reads only the mailboxes he can read, and his own events: the filter is on the server
	 *
	 * @return void
	 */
	public function testEachUserReadsOnlyHisMailboxes()
	{
		$alice = $this->user('alice');
		$bob = $this->user('bob');
		$devis = $this->box(true, array('status' => InboxAccount::STATUS_ACTIVE));
		$compta = $this->box(true, array('email' => 'compta@inbox-test.invalid', 'status' => InboxAccount::STATUS_ACTIVE));
		$off = $this->box(true, array('email' => 'off@inbox-test.invalid', 'status' => InboxAccount::STATUS_DISABLED));
		$acc = new InboxAccess($this->db);
		$acc->grant($this->admin, $devis->id, $alice->id, 0, 'read');
		$acc->grant($this->admin, $compta->id, $bob->id, 0, 'read');
		$acc->grant($this->admin, $off->id, $alice->id, 0, 'read');
		InboxAccess::clearCache();
		$key = InboxEvent::key('test-instance');
		$events = new InboxEvent($this->db);
		$ta = $events->token($alice, $key);
		$tb = $events->token($bob, $key);
		$this->assertSame(array((int) $devis->id), $ta['accounts'], 'a disabled mailbox is never in the token');
		$this->assertSame(array((int) $compta->id), $tb['accounts']);

		$start = $this->cursor();
		$e1 = $events->emit($devis->id, 'tag', array(11, 12), 3, $alice);
		$e2 = $events->emit($compta->id, 'flag', array(21), 4, $bob);
		$e3 = $events->emitUsers(array($bob->id), 'access', $this->admin);
		$this->assertGreaterThan(0, $e1);
		$this->assertGreaterThan(0, $e2);
		$this->assertSame(1, $e3);

		$va = InboxEvent::verify($ta['token'], $key, dol_now());
		$ra = InboxEvent::since($this->db, $va['e'], $va['u'], $va['a'], $start);
		$this->assertSame(array($e1), array_column($ra['events'], 'id'), 'alice: her mailbox only');
		$this->assertSame(array(11, 12), $ra['events'][0]['ids']);
		$this->assertGreaterThanOrEqual($e2, $ra['last'], 'the cursor passes the events of the others, never read again');

		$vb = InboxEvent::verify($tb['token'], $key, dol_now());
		$rb = InboxEvent::since($this->db, $vb['e'], $vb['u'], $vb['a'], $start);
		$this->assertSame(array('flag', 'access'), array_column($rb['events'], 'type'), 'bob: his mailbox and his own event');
		$this->assertTrue($rb['events'][1]['mine']);

		// Another entity in a forged call: nothing (the token is signed, the entity comes from it)
		$rx = InboxEvent::since($this->db, 99, $va['u'], array($devis->id, $compta->id), $start);
		$this->assertSame(array(), $rx['events']);
	}

	/**
	 * @return void
	 */
	public function testTokenRefused()
	{
		$alice = $this->user('alice');
		$devis = $this->box(true, array('status' => InboxAccount::STATUS_ACTIVE));
		$prot = $this->box(true, array('email' => 'prot@inbox-test.invalid', 'status' => InboxAccount::STATUS_ACTIVE));
		$this->db->query("UPDATE ".$this->db->prefix()."inbox_account SET protected = 1 WHERE rowid = ".((int) $prot->id));
		$acc = new InboxAccess($this->db);
		$acc->grant($this->admin, $devis->id, $alice->id, 0, 'read');
		$acc->grant($this->admin, $prot->id, $alice->id, 0, 'read');
		InboxAccess::clearCache();
		$key = InboxEvent::key('test-instance');
		$events = new InboxEvent($this->db);
		$now = dol_now();
		$t = $events->token($alice, $key, $now);
		$this->assertSame(array((int) $devis->id), $t['accounts'], 'a protected mailbox is never in the token');
		$this->assertNotNull(InboxEvent::verify($t['token'], $key, $now));
		$this->assertSame($now + InboxEvent::TOKEN_LIFE, InboxEvent::verify($t['token'], $key, $now)['x']);

		list($body, $sig) = explode('.', $t['token']);
		// One more mailbox written in the body: the signature no longer matches
		$forged = rtrim(strtr(base64_encode((string) json_encode(array('u' => $alice->id, 'e' => 1, 'a' => array($devis->id, $prot->id, 999), 'x' => $now + 900))), '+/', '-_'), '=');
		$this->assertNull(InboxEvent::verify($forged.'.'.$sig, $key, $now), 'body changed');
		$this->assertNull(InboxEvent::verify($body.'.'.strrev($sig), $key, $now), 'signature changed');
		$this->assertNull(InboxEvent::verify($t['token'], InboxEvent::key('other-instance'), $now), 'another instance');
		$this->assertNull(InboxEvent::verify($t['token'], $key, $now + InboxEvent::TOKEN_LIFE + 1), 'expired');
		$this->assertNull(InboxEvent::verify('', $key, $now));
		$this->assertNull(InboxEvent::verify($t['token'], '', $now), 'no key: no token');
		$this->assertSame('', InboxEvent::key(''));
		$this->assertNull($events->token($alice, ''));
	}

	/**
	 * Numbers only: never a subject, a text, an address or a name in the journal nor in what is read
	 *
	 * @return void
	 */
	public function testNumbersOnly()
	{
		$alice = $this->user('alice');
		$devis = $this->box(true, array('status' => InboxAccount::STATUS_ACTIVE));
		$acc = new InboxAccess($this->db);
		$acc->grant($this->admin, $devis->id, $alice->id, 0, 'read');
		InboxAccess::clearCache();
		$inbox = $this->folder($devis->id);
		$m1 = $this->mail($devis->id, $inbox, '<ev1@inbox-test.invalid>');
		$start = $this->cursor();
		$events = new InboxEvent($this->db);

		$this->assertSame(0, $events->emit($devis->id, 'Secret subject', array($m1)), 'type out of the list: refused');
		$this->assertSame('', InboxEvent::idsText(range(1, InboxEvent::MAX_IDS + 1)), 'too many: the folder is read again');
		$this->assertSame('3,4', InboxEvent::idsText(array(3, 'x', -2, 0, 4, 3)));

		$assign = new InboxAssign($this->db);
		$this->assertTrue($assign->set($alice, array($m1), array($alice->id), array())['ok']);
		$rows = $this->db->getRows("SELECT * FROM ".$this->db->prefix()."inbox_event WHERE rowid > ".$start." LIMIT 100");
		$this->assertCount(1, $rows);
		$this->assertSame('assign', $rows[0]->type);
		$this->assertSame((string) $m1, (string) $rows[0]->ids);
		$this->assertSame($inbox, (int) $rows[0]->fk_folder);

		$read = InboxEvent::since($this->db, 1, $alice->id, array($devis->id), $start);
		$json = (string) json_encode($read);
		foreach (array('Secret', 'secret.sender', 'inbox-test.invalid', '@', 'ev1') as $forbidden) {
			$this->assertStringNotContainsString($forbidden, $json);
		}
		$this->assertSame(array('id', 'type', 'account', 'folder', 'ids', 'author', 'origin', 'mine'), array_keys($read['events'][0]));
		foreach ($read['events'][0] as $k => $v) {
			if ($k === 'ids') {
				$this->assertContainsOnly('int', $v);
			} elseif (!in_array($k, array('type', 'origin', 'mine'), true)) {
				$this->assertIsInt($v, $k);
			}
		}
	}

	/**
	 * A change of access tells the users concerned at once (their token is asked again)
	 *
	 * @return void
	 */
	public function testAccessChangeTellsTheUsers()
	{
		$alice = $this->user('alice');
		$bob = $this->user('bob');
		$carol = $this->user('carol');
		$devis = $this->box(true, array('status' => InboxAccount::STATUS_ACTIVE));
		$p = $this->db->prefix();
		$this->db->query("INSERT INTO ".$p."usergroup (entity, nom, datec) VALUES (1, 'inboxtest_group', '".$this->db->idate(dol_now())."')");
		$gid = (int) $this->db->last_insert_id($p.'usergroup');
		$this->db->query("INSERT INTO ".$p."usergroup_user (entity, fk_user, fk_usergroup) VALUES (1, ".$bob->id.", ".$gid."), (1, ".$carol->id.", ".$gid.")");
		$acc = new InboxAccess($this->db);
		$start = $this->cursor();

		$this->assertSame(1, $acc->grant($this->admin, $devis->id, $alice->id, 0, 'read'));
		$this->assertSame(1, $acc->grant($this->admin, $devis->id, 0, $gid, 'read'));
		$this->assertSame(1, $acc->revoke($this->admin, $devis->id, $alice->id, 0));
		$rows = $this->db->getRows("SELECT fk_user_target, type FROM ".$p."inbox_event WHERE rowid > ".$start." ORDER BY rowid LIMIT 100");
		$this->assertSame(array($alice->id, $bob->id, $carol->id, $alice->id), array_map('intval', array_column($rows, 'fk_user_target')));
		$this->assertSame(array('access'), array_values(array_unique(array_column($rows, 'type'))));

		// The mailbox disabled: an event of the mailbox, its users ask a new token
		$start = $this->cursor();
		$box = new InboxAccount($this->db);
		$box->fetch($devis->id);
		$box->status = InboxAccount::STATUS_DISABLED;
		$this->assertGreaterThan(0, $box->update($this->admin));
		$row = $this->db->getRow("SELECT fk_account, type FROM ".$p."inbox_event WHERE rowid > ".$start." AND fk_account > 0");
		$this->assertSame('access', (string) $row->type);
		$this->assertSame((int) $devis->id, (int) $row->fk_account);
	}

	/**
	 * The cursor: given at the start, read again from scratch when too many events or events forgotten
	 *
	 * @return void
	 */
	public function testCursorResetAndPurge()
	{
		$devis = $this->box(true, array('status' => InboxAccount::STATUS_ACTIVE));
		$events = new InboxEvent($this->db);
		$first = $events->emit($devis->id, 'folder', array(), 1);
		$this->assertSame($first, InboxEvent::cursor($this->db), 'at the start: the last event');
		$this->assertSame(array(), InboxEvent::since($this->db, 1, 0, array($devis->id), $first)['events'], 'nothing after it');
		// Journal empty at the start (cursor 0): the first event is read, never skipped
		$this->assertContains($first, array_column(InboxEvent::since($this->db, 1, 0, array($devis->id), $first - 1)['events'], 'id'));

		for ($i = 0; $i <= InboxEvent::MAX_READ; $i++) {
			$events->emit($devis->id, 'folder', array(), 1);
		}
		$r = InboxEvent::since($this->db, 1, 0, array($devis->id), $first);
		$this->assertTrue($r['reset'], 'too many: everything read again');
		$this->assertSame(array(), $r['events']);
		$this->assertGreaterThan($first, $r['last']);

		// Events older than 24 h forgotten: a cursor before them reads everything again
		$p = $this->db->prefix();
		$this->db->query("UPDATE ".$p."inbox_event SET datec = '".$this->db->idate(dol_now() - 25 * 3600)."' WHERE fk_account = ".((int) $devis->id)." AND rowid <= ".($first + 5));
		$this->assertGreaterThanOrEqual(6, $events->purge());
		$last = $this->cursor();
		$r = InboxEvent::since($this->db, 1, 0, array($devis->id), $first);
		$this->assertTrue($r['reset'] || $this->db->getRow("SELECT MIN(rowid) AS m FROM ".$p."inbox_event")->m <= $first + 1);
		$this->assertSame($last, $r['last']);
	}

	/**
	 * The bell (1.125.0): a notification made, merged or removed rings its user at once, him only, numbers only
	 *
	 * @return void
	 */
	public function testNotificationRingsItsUserOnly()
	{
		global $conf;

		$alice = $this->user('alice');
		$bob = $this->user('bob');
		$notify = new InboxNotification($this->db);
		$start = $this->cursor();
		$this->assertGreaterThan(0, $notify->add($alice->id, 'assign', array('mailref' => 777001, 'actor' => $bob->id, 'payload' => array('subject' => 'Secret subject'))));
		$ra = InboxEvent::since($this->db, $conf->entity, $alice->id, array(), $start);
		$this->assertSame(array('notif'), array_column($ra['events'], 'type'));
		$this->assertTrue($ra['events'][0]['mine']);
		$this->assertStringNotContainsString('Secret', json_encode($ra['events']), 'the bell reads the notification itself');
		$this->assertSame(array(), InboxEvent::since($this->db, $conf->entity, $bob->id, array(), $start)['events'], 'never the bell of another');

		// Merged with the same one a moment later, then removed: rings again each time
		$cur = $this->cursor();
		$notify->add($alice->id, 'assign', array('mailref' => 777001, 'actor' => $bob->id));
		$notify->cancel($alice->id, 'assign', 777001, $bob->id);
		$this->assertSame(array('notif', 'notif'), array_column(InboxEvent::since($this->db, $conf->entity, $alice->id, array(), $cur)['events'], 'type'));
		// Nothing removed: no ring
		$cur = $this->cursor();
		$notify->cancel($alice->id, 'assign', 777001, $bob->id);
		$this->assertSame(array(), InboxEvent::since($this->db, $conf->entity, $alice->id, array(), $cur)['events']);

		// Standard mode: nothing written
		$conf->global->INBOX_REACTIVITY = '';
		$cur = $this->cursor();
		$notify->add($alice->id, 'assign', array('mailref' => 777002, 'actor' => $bob->id));
		$this->assertSame($cur, $this->cursor());
		$this->db->query("DELETE FROM ".$this->db->prefix()."inbox_notification WHERE fk_mailref IN (777001, 777002)");
	}
}
