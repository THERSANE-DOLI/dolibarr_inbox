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
 * \file    inbox/test/phpunit/InboxNotifyTest.php
 * \ingroup inbox
 * \brief   Unread counter, notifications of the browser, box_error and summary by mail (1.21.0)
 */

// From the command line only, never from the web: test/ stays in the Git branches
if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

require_once dirname(__DIR__, 2).'/class/inboxnotification.class.php';

/**
 * Tests of InboxNotification (database only, no mail server)
 */
class InboxNotifyTest extends InboxTestCase
{
	/**
	 * @return void
	 */
	protected function setUp(): void
	{
		parent::setUp();
		$this->resetParams();
	}

	/**
	 * Settings are cached per request: emptied between the steps of a test
	 *
	 * @return void
	 */
	protected function resetParams()
	{
		$r = new ReflectionProperty('InboxUserParam', 'cache');
		$r->setAccessible(true);
		$r->setValue(null, array());
		InboxAccess::clearCache();
	}

	/**
	 * @param int    $accountId Mailbox
	 * @param string $path      Path
	 * @param string $role      Role
	 * @param int    $unread    Unread mails
	 * @return int
	 */
	protected function folder($accountId, $path, $role, $unread)
	{
		$p = $this->db->prefix();
		$this->db->query("INSERT INTO ".$p."inbox_folder (fk_account, path, name, delimiter, role, unread, status) VALUES (".((int) $accountId).", '".$this->db->escape($path)."',"
			." '".$this->db->escape($path)."', '.', ".($role !== '' ? "'".$this->db->escape($role)."'" : 'NULL').", ".((int) $unread).", 1)");

		return (int) $this->db->last_insert_id($p.'inbox_folder');
	}

	/**
	 * @param int  $accountId Mailbox
	 * @param int  $folderId  Folder
	 * @param int  $received  Date of arrival
	 * @param bool $seen      Read
	 * @return int
	 */
	protected function mail($accountId, $folderId, $received, $seen = false)
	{
		static $uid = 5000;
		$p = $this->db->prefix();
		$uid++;
		$this->db->query("INSERT INTO ".$p."inbox_mail (fk_account, fk_folder, remote_id, sort_key, message_id, date_mail, date_received, from_email, from_name, subject, seen, date_index) VALUES ("
			.((int) $accountId).", ".((int) $folderId).", '".$uid."', ".$uid.", '<n".$uid."@inbox-test.invalid>', '".$this->db->idate($received)."', '".$this->db->idate($received)."',"
			." 'client@inbox-test.invalid', 'Client', 'Commande ".$uid."', ".($seen ? 1 : 0).", '".$this->db->idate(dol_now())."')");

		return (int) $this->db->last_insert_id($p.'inbox_mail');
	}

	/**
	 * @return void
	 */
	public function testUnreadCounter()
	{
		$alice = $this->user('alice');
		$perso = $this->box(true, array('email' => 'alice@inbox-test.invalid', 'type' => InboxAccount::TYPE_PERSONAL, 'fk_user_owner' => $alice->id, 'status' => InboxAccount::STATUS_ACTIVE));
		$shared = $this->box(true, array('status' => InboxAccount::STATUS_ACTIVE));
		$hidden = $this->box(true, array('email' => 'compta@inbox-test.invalid', 'status' => InboxAccount::STATUS_ACTIVE));
		$acc = new InboxAccess($this->db);
		$acc->grant($this->admin, $shared->id, $alice->id, 0, 'read');
		$this->resetParams();
		$this->folder($perso->id, 'INBOX', 'inbox', 3);
		$this->folder($perso->id, 'Junk', 'junk', 40);
		$this->folder($perso->id, 'Projets', '', 7);
		$sharedInbox = $this->folder($shared->id, 'INBOX', 'inbox', 9);
		$this->folder($hidden->id, 'INBOX', 'inbox', 100);
		$notify = new InboxNotification($this->db);

		$c = $notify->mailCount($alice);
		$this->assertSame(12, $c['mails'], 'inbox folders of the readable mailboxes only: no junk, no other folder, no mailbox not readable');
		$this->assertCount(2, $c['mail_folders']);

		$params = new InboxUserParam($this->db);
		$this->assertSame(1, $params->set($alice, 'count_box', '0', (int) $shared->id));
		$this->resetParams();
		$this->assertSame(3, $notify->mailCount($alice)['mails'], 'a busy shared mailbox left out');
		$this->assertNotContains($sharedInbox, $notify->mailCount($alice)['mail_folders']);

		$params->remove($alice, 'count_box', (int) $shared->id);
		$params->set($alice, 'count_scope', 'personal');
		$this->resetParams();
		$this->assertSame(3, $notify->mailCount($alice)['mails'], 'only the personal mailboxes');

		$bob = $this->user('bob');
		$this->assertSame(array('mails' => 0, 'mail_folders' => array()), $notify->mailCount($bob), 'no mailbox: nothing');
	}

	/**
	 * @return void
	 */
	public function testChannelsAndNewMails()
	{
		$alice = $this->user('alice');
		$perso = $this->box(true, array('email' => 'alice@inbox-test.invalid', 'type' => InboxAccount::TYPE_PERSONAL, 'fk_user_owner' => $alice->id, 'status' => InboxAccount::STATUS_ACTIVE));
		$shared = $this->box(true, array('status' => InboxAccount::STATUS_ACTIVE));
		$acc = new InboxAccess($this->db);
		$acc->grant($this->admin, $shared->id, $alice->id, 0, 'read');
		$this->resetParams();
		$pi = $this->folder($perso->id, 'INBOX', 'inbox', 0);
		$pj = $this->folder($perso->id, 'Junk', 'junk', 0);
		$si = $this->folder($shared->id, 'INBOX', 'inbox', 0);
		$notify = new InboxNotification($this->db);

		$this->assertTrue($notify->channelOn($alice, 'assign', 'b'), 'browser on by default');
		$this->assertFalse($notify->channelOn($alice, 'assign', 'm'), 'mail off by default');
		$this->assertSame(array((int) $perso->id), $notify->accountsFor($alice, 'notify'), 'new mails: personal yes, shared no by default');

		$old = $this->mail($perso->id, $pi, dol_now() - 600);
		$first = $notify->fresh($alice, 0, 0, true);
		$this->assertSame($old, $first['mail_last'], 'first call: the last one only');
		$this->assertSame(array(), $first['mails']);

		$new = $this->mail($perso->id, $pi, dol_now() - 5);
		$this->mail($perso->id, $pi, dol_now() - 5, true);
		$this->mail($perso->id, $pj, dol_now() - 5);
		$this->mail($shared->id, $si, dol_now() - 5);
		$moved = $this->mail($perso->id, $pi, dol_now() - 86400 * 10);
		$r = $notify->fresh($alice, $first['mail_last'], $first['notif_last']);
		$this->assertSame(array($new), array_column($r['mails'], 'id'), 'unread, just received, in the inbox of a mailbox told: not the read one, the junk, the shared mailbox nor an old mail moved back');
		$this->assertSame($moved, $r['mail_last']);

		$params = new InboxUserParam($this->db);
		$params->set($alice, 'new_mail_notify', '1', (int) $shared->id);
		$params->set($alice, 'notif_channels', json_encode(array('assign' => array('b' => 0, 'm' => 1))));
		$this->resetParams();
		$this->assertEqualsCanonicalizing(array((int) $perso->id, (int) $shared->id), $notify->accountsFor($alice, 'notify'));
		$this->assertFalse($notify->channelOn($alice, 'assign', 'b'));
		$this->assertTrue($notify->channelOn($alice, 'assign', 'm'));
		$this->assertTrue($notify->channelOn($alice, 'send_failed', 'b'), 'the other types keep the defaults');

		// Notifications of the bell: only the types with the browser on
		$n1 = $notify->add($alice->id, 'assign', array('payload' => array('subject' => 'A')));
		$n2 = $notify->add($alice->id, 'send_failed', array('payload' => array('subject' => 'B')));
		$r2 = $notify->fresh($alice, $r['mail_last'], $r['notif_last']);
		$this->assertSame(array($n2), array_column($r2['notifs'], 'id'));
		$this->assertSame($n2, $r2['notif_last']);
		$this->assertGreaterThan($n1, $n2);
	}

	/**
	 * @return void
	 */
	public function testBoxErrorOncePerEpisode()
	{
		$alice = $this->user('alice');
		$bob = $this->user('bob');
		$shared = $this->box(true, array('status' => InboxAccount::STATUS_ACTIVE));
		$acc = new InboxAccess($this->db);
		$acc->grant($this->admin, $shared->id, $alice->id, 0, 'manage');
		$acc->grant($this->admin, $shared->id, $bob->id, 0, 'read');
		$p = $this->db->prefix();
		$notify = new InboxNotification($this->db);
		$count = function ($u) use ($p) {
			$row = $this->db->getRow("SELECT COUNT(rowid) as n FROM ".$p."inbox_notification WHERE type = 'box_error' AND fk_user = ".((int) $u->id));
			return (int) $row->n;
		};

		$this->db->query("UPDATE ".$p."inbox_account SET date_sync = '".$this->db->idate(dol_now() - 60)."', sync_error = 'Connection refused' WHERE rowid = ".((int) $shared->id));
		$this->assertSame(0, $notify->boxError((int) $shared->id, 'unreachable'), 'out of reach a minute ago: not yet');
		$this->assertSame(1, $notify->boxError((int) $shared->id, 'auth_failed'), 'password refused: at once, to the managers only');
		$this->assertSame(1, $count($alice));
		$this->assertSame(0, $count($bob), 'reading the mailbox is not managing it');
		$this->assertSame(0, $notify->boxError((int) $shared->id, 'auth_failed'), 'once per episode');

		// Good pass, then out of reach for more than 15 minutes: a new episode
		$this->db->query("UPDATE ".$p."inbox_notification SET date_creation = '".$this->db->idate(dol_now() - 7200)."' WHERE type = 'box_error' AND fk_account = ".((int) $shared->id));
		$this->db->query("UPDATE ".$p."inbox_account SET date_sync = '".$this->db->idate(dol_now() - 3600)."' WHERE rowid = ".((int) $shared->id));
		$this->assertSame(1, $notify->boxError((int) $shared->id, 'unreachable'));
		$this->assertSame(2, $count($alice));
		$items = $notify->listFor($alice, 5)['items'];
		$this->assertSame('box_error', $items[0]['type']);
		$this->assertSame('#/settings/accounts/'.((int) $shared->id).'/sync', $items[0]['url']);
		$this->assertSame('Connection refused', $items[0]['subject']);
	}

	/**
	 * @return void
	 */
	public function testSummaryByMail()
	{
		global $conf;

		$alice = $this->user('alice');
		$bob = $this->user('bob');
		$p = $this->db->prefix();
		$this->db->query("UPDATE ".$p."user SET email = 'alice-user@inbox-test.invalid' WHERE rowid = ".((int) $alice->id));
		$this->db->query("UPDATE ".$p."user SET email = 'bob-user@inbox-test.invalid' WHERE rowid = ".((int) $bob->id));
		$params = new InboxUserParam($this->db);
		$params->set($alice, 'notif_channels', json_encode(array('assign' => array('m' => 1))));
		$this->resetParams();
		$notify = new InboxNotification($this->db);
		$late = $notify->add($alice->id, 'assign', array('payload' => array('subject' => 'Late')));
		$recent = $notify->add($alice->id, 'assign', array('actor' => $bob->id, 'payload' => array('subject' => 'Recent')));
		$seen = $notify->add($alice->id, 'assign', array('actor' => (int) $this->admin->id, 'payload' => array('subject' => 'Seen')));
		$bobs = $notify->add($bob->id, 'assign', array('payload' => array('subject' => 'Bob')));
		$old = $notify->add($alice->id, 'send_failed', array('payload' => array('subject' => 'Old')));
		$at = function ($id, $ago) use ($p) {
			$this->db->query("UPDATE ".$p."inbox_notification SET date_creation = '".$this->db->idate(dol_now() - $ago)."' WHERE rowid = ".((int) $id));
		};
		$at($late, 1200);
		$at($seen, 1200);
		$at($bobs, 1200);
		$at($old, 3 * 86400);
		$notify->markRead($alice, array($seen));

		// Sending refused in the tests: mails disabled, the lines are handled anyway (never twice)
		$saved = getDolGlobalString('MAIN_DISABLE_ALL_MAILS');
		$conf->global->MAIN_DISABLE_ALL_MAILS = 1;
		$this->assertSame(0, $notify->cronMail());
		$conf->global->MAIN_DISABLE_ALL_MAILS = $saved;
		$this->assertStringContainsString('MAIN_DISABLE_ALL_MAILS', $notify->output);
		$mailed = function ($id) use ($p) {
			$row = $this->db->getRow("SELECT date_mailed FROM ".$p."inbox_notification WHERE rowid = ".((int) $id));
			return !empty($row->date_mailed);
		};
		$this->assertTrue($mailed($late), 'unread after 15 minutes: handled');
		$this->assertTrue($mailed($bobs), 'handled even when the user did not choose the mail (never considered again)');
		$this->assertFalse($mailed($recent), 'less than 15 minutes: waits');
		$this->assertFalse($mailed($seen), 'seen in the bell: never mailed');
		$this->assertFalse($mailed($old), 'older than a day: not sent late');

		$langs = new Translate('', $conf);
		$langs->setDefaultLang('en_US');
		$langs->loadLangs(array('main', 'inbox@inbox'));
		$items = $notify->listFor($alice, 10)['items'];
		$byId = array_column($items, null, 'id');
		$this->assertSame('T bob assigned an email to you', InboxNotification::textOf($byId[$recent], $langs));
		$this->assertSame($langs->transnoentities('InboxNotifBoxError'), InboxNotification::textOf(array('type' => 'box_error', 'actor' => null, 'text' => ''), $langs));
	}
}
