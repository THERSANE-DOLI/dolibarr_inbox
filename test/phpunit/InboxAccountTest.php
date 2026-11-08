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
 * \file    inbox/test/phpunit/InboxAccountTest.php
 * \ingroup inbox
 * \brief   Mailboxes: creation, checks, secrets never given out, deletion (CA-06, CA-18, CA-28)
 */

/**
 * Tests of InboxAccount
 */
class InboxAccountTest extends InboxTestCase
{
	/**
	 * @return void
	 */
	public function testCreateNormalizesAndEncrypts()
	{
		$a = $this->box(false, array('email' => '  Devis@Inbox-Test.INVALID '));
		$a->setImapPassword('S3cret-ÄÖ"\'<x>');
		$id = $a->create($this->admin);
		$this->assertGreaterThan(0, $id);
		$this->assertSame(self::BOX, $a->email);
		$this->assertSame(self::BOX, $a->ref);
		$this->assertSame(self::BOX, $a->label);
		$raw = $this->db->getRow("SELECT imap_password FROM ".$this->db->prefix()."inbox_account WHERE rowid = ".((int) $id));
		$this->assertStringNotContainsString('S3cret', (string) $raw->imap_password);
		$b = new InboxAccount($this->db);
		$this->assertGreaterThan(0, $b->fetchByEmail('DEVIS@inbox-test.invalid'));
		$this->assertSame('S3cret-ÄÖ"\'<x>', $b->getImapPassword());
		$this->assertSame($b->getImapPassword(), $b->getSmtpPassword(), 'SMTP uses the IMAP credentials by default');
	}

	/**
	 * Copy of the mails sent (1.26.0): automatic for the servers that file it themselves, or chosen
	 *
	 * @return void
	 */
	public function testSentCopyMode()
	{
		$a = $this->box();
		$this->assertSame('auto', $a->getSentCopyMode());
		$this->assertFalse($a->sentCopyByServer(), 'local server: inbox files the copy');
		$a->smtp_host = 'smtp.gmail.com';
		$this->assertTrue($a->sentCopyByServer());
		$a->smtp_host = 'smtp.office365.com';
		$this->assertTrue($a->sentCopyByServer());
		$this->assertFalse(InboxAccount::serverKeepsSent('notgmail.com.example.invalid'));
		$a->setConnectorConfig(array('sent_copy' => 'inbox'));
		$this->assertFalse($a->sentCopyByServer(), 'chosen: by inbox');
		$a->smtp_host = 'mail.example.invalid';
		$a->setConnectorConfig(array('sent_copy' => 'server'));
		$this->assertTrue($a->sentCopyByServer(), 'chosen: by the server');
		$a->setConnectorConfig(array('sent_copy' => 'bogus'));
		$this->assertSame('auto', $a->getSentCopyMode());
	}

	/**
	 * CA-06: the array for the browser has no password and no encrypted value
	 *
	 * @return void
	 */
	public function testClientArrayHasNoSecret()
	{
		$a = $this->box(true);
		$a->smtp_same_credentials = 0;
		$a->smtp_login = 'x@inbox-test.invalid';
		$a->setSmtpPassword('smtp-secret');
		$a->update($this->admin);
		$a->fetch($a->id);
		$arr = $a->toArrayForClient();
		$json = json_encode($arr);
		foreach (array('imap_password', 'smtp_password') as $key) {
			$this->assertArrayNotHasKey($key, $arr);
		}
		$this->assertStringNotContainsString(self::BOX_PASSWORD, $json);
		$this->assertStringNotContainsString('smtp-secret', $json);
		$this->assertStringNotContainsString((string) $a->imap_password, $json);
		$this->assertTrue($arr['has_imap_password']);
		$this->assertTrue($arr['has_smtp_password']);
		$this->assertFalse($a->setConnectorConfig(array('client_secret' => 'x')), 'secret refused in the connector config');
	}

	/**
	 * @return void
	 */
	public function testChecks()
	{
		$this->box(true);
		$dup = $this->box(false, array('email' => 'DEVIS@inbox-test.invalid'));
		$this->assertLessThan(0, $dup->create($this->admin));
		$this->assertContains('InboxErrorAccountExists', $dup->errors);
		$perso = $this->box(false, array('email' => 'perso@inbox-test.invalid', 'type' => 'personal'));
		$this->assertLessThan(0, $perso->create($this->admin));
		$this->assertContains('InboxErrorAccountOwner', $perso->errors);
		$clear = $this->box(false, array('email' => 'clear@inbox-test.invalid', 'imap_host' => 'mail.example.invalid'));
		$this->assertLessThan(0, $clear->create($this->admin));
		$this->assertContains('InboxErrorAccountNoEncryption', $clear->errors, 'no encryption only for a local server');
		$bad = $this->box(false, array('email' => 'bad', 'imap_host' => 'a b', 'imap_port' => 70000));
		$bad->create($this->admin);
		foreach (array('InboxErrorAccountEmail', 'InboxErrorAccountHost', 'InboxErrorAccountPort') as $e) {
			$this->assertContains($e, $bad->errors);
		}
	}

	/**
	 * CA-18, CA-28: only a disabled mailbox is deleted, with what belongs to it; the journal stays
	 *
	 * @return void
	 */
	public function testDelete()
	{
		$a = $this->box(true);
		$i = new InboxIdentity($this->db);
		$i->fk_account = $a->id;
		$i->email = self::BOX;
		$i->create($this->admin);
		$up = new InboxUserParam($this->db);
		$up->set($this->admin, 'read_delay', '5', $a->id);
		$this->assertLessThan(0, $a->delete($this->admin));
		$this->assertContains('InboxErrorDeleteNotDisabled', $a->errors);
		$a->status = InboxAccount::STATUS_DISABLED;
		$a->update($this->admin);
		$this->assertGreaterThan(0, $a->delete($this->admin));
		$p = $this->db->prefix();
		foreach (array('inbox_identity', 'inbox_userparam', 'inbox_account_access') as $t) {
			$this->assertSame(0, (int) $this->db->getRow("SELECT COUNT(*) AS n FROM ".$p.$t." WHERE fk_account = ".((int) $a->id))->n, $t);
		}
		$this->assertGreaterThan(0, (int) $this->db->getRow("SELECT COUNT(*) AS n FROM ".$p."inbox_account_log WHERE fk_account = ".((int) $a->id))->n, 'journal kept');
		$this->assertSame(0, (new InboxAccount($this->db))->fetch($a->id));
	}

	/**
	 * Journal of the changes: server and password (only the fact), never a password
	 *
	 * @return void
	 */
	public function testJournalNeverHoldsAPassword()
	{
		$a = $this->box(true);
		$a->imap_host = 'localhost';
		$a->setImapPassword('new-secret-pass');
		$a->update($this->admin);
		$lines = (new InboxAccountLog($this->db))->listForAccount($a->id);
		$actions = array_column($lines, 'action');
		$this->assertContains('server', $actions);
		$this->assertContains('password', $actions);
		$this->assertStringNotContainsString('new-secret-pass', json_encode($lines));
		$this->assertSame(array('imap_password' => '***', 'a' => array('token' => '***'), 'host' => 'h'), InboxAccountLog::scrub(array('imap_password' => 'p', 'a' => array('token' => 't'), 'host' => 'h')));
	}
}
