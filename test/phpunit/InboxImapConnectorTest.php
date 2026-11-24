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
 * \file    inbox/test/phpunit/InboxImapConnectorTest.php
 * \ingroup inbox
 * \brief   IMAP connector and connection test, on the local test Dovecot (CA-02 to CA-05)
 */

// From the command line only, never from the web: test/ stays in the Git branches
if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

/**
 * Tests of InboxImapConnector and InboxServerTester
 */
class InboxImapConnectorTest extends InboxTestCase
{
	/**
	 * State of the flags of a mail, read by another client (the reference)
	 *
	 * @param int $uid UID in INBOX
	 * @return string
	 */
	protected function flags($uid)
	{
		$this->needDovecot();
		require_once DOL_DOCUMENT_ROOT.'/includes/webklex/php-imap/vendor/autoload.php';
		$cm = new \Webklex\PHPIMAP\ClientManager();
		$c = $cm->make(array('host' => self::IMAP_HOST, 'port' => self::IMAP_PORT, 'encryption' => false, 'validate_cert' => false, 'protocol' => 'imap', 'username' => self::BOX, 'password' => self::BOX_PASSWORD, 'authentication' => 'login'));
		$c->connect();
		$c->getConnection()->examineFolder('INBOX');
		$r = $c->getConnection()->requestAndResponse('UID FETCH', array((string) $uid, '(FLAGS)'));
		$c->disconnect();

		return json_encode($r);
	}

	/**
	 * @return void
	 */
	public function testReadOnlyAndFind()
	{
		$this->needDovecot();
		$c = InboxConnectorFactory::forAccount($this->db, $this->box());
		$this->assertInstanceOf('InboxImapConnector', $c);
		$caps = $c->getCapabilities();
		$this->assertCount(18, $caps);
		$this->assertTrue($caps['read']);
		$this->assertFalse($caps['move'], 'no write in lot W1');
		$before = $this->flags(1);
		$found = $c->findByMessageId('seed-1@inbox-test.invalid');
		$this->assertIsArray($found);
		$this->assertSame('INBOX', $found['folder']);
		$this->assertMatchesRegularExpression('/^\d+:1$/', $found['remote_id']);
		$this->assertStringNotContainsString('Seen', $before);
		$this->assertStringNotContainsString('Seen', $this->flags(1), 'BODY.PEEK: the mail stays unread');
		$this->assertSame('INBOX.Archives', $c->findByMessageId('seed-3@inbox-test.invalid')['folder']);
		$this->assertNull($c->findByMessageId('seed-1@inbox-test'));
		$this->assertTrue($c->notFound(), 'substring is not a match');
		$this->assertNull($c->findByMessageId('a" OR ALL "@x'));
		$this->assertFalse($c->notFound(), 'dangerous Message-ID refused before the server');
		$this->assertStringContainsString('seed-1@', (string) $c->fetchRaw('INBOX', $found['remote_id']));
		$this->assertNull($c->fetchRaw('INBOX', '1:1'));
		$this->assertSame('UIDVALIDITY changed', $c->getError());
		$this->assertNull($c->fetchRaw('INBOX"; DELETE', $found['remote_id']), 'unsafe folder refused');
	}

	/**
	 * @return void
	 */
	public function testErrorsAreReadableWithoutPassword()
	{
		$this->needDovecot();
		$bad = $this->box();
		$bad->setImapPassword('wrong-pass-xyz');
		$c = InboxConnectorFactory::forAccount($this->db, $bad);
		$this->assertNull($c->testConnection());
		$this->assertStringNotContainsString('wrong-pass-xyz', $c->getError());
		$this->assertNotSame('', $c->getError());
		$down = $this->box(false, array('imap_port' => 10999));
		$c = InboxConnectorFactory::forAccount($this->db, $down);
		$this->assertNull($c->testConnection());
		$e = '';
		$this->assertNull(InboxConnectorFactory::forAccount($this->db, $this->box(false, array('connector' => 'nope')), $e));
		$this->assertNotSame('', $e);
	}

	/**
	 * CA-02 to CA-05: step by step, green / orange / red, nothing secret in the result
	 *
	 * @return void
	 */
	public function testServerTester()
	{
		$this->needDovecot();
		$tester = new InboxServerTester($this->db);
		$r = $tester->run($this->box());
		$this->assertSame('ok', $r['status'], json_encode($r['steps']));
		$this->assertCount(5, $r['steps']);
		$this->assertSame('INBOX.Sent', $r['folder_roles']['sent']);
		$this->assertStringNotContainsString(self::BOX_PASSWORD, json_encode($r));
		$r = $tester->run($this->box(false, array('sieve_port' => 14999)));
		$this->assertSame('warning', $r['status']);
		$b = $this->box(false, array('smtp_same_credentials' => 0, 'smtp_login' => self::BOX));
		$b->setSmtpPassword('bad-smtp');
		$steps = array_column($tester->run($b)['steps'], 'status', 'step');
		$this->assertSame('error', $steps['smtp']);
		$this->assertSame('ok', $steps['imap']);
		$saved = $this->box(true);
		$tester->runAndSave($saved, $this->admin);
		$f = new InboxAccount($this->db);
		$f->fetch($saved->id);
		$this->assertSame('ok', $f->last_test_result);
		$this->assertSame('INBOX.Trash', $f->getFolderRoles()['trash']);
	}

	/**
	 * @return void
	 */
	public function testLabels()
	{
		$f = array(
			array('path' => 'INBOX', 'delimiter' => '.'), array('path' => 'INBOX.Sent', 'delimiter' => '.'),
			array('path' => 'INBOX.Archives.2025', 'delimiter' => '.'), array('path' => 'INBOX.Clients &AOk-t&AOk-', 'delimiter' => '.'),
		);
		InboxImapConnector::addLabels($f);
		$this->assertSame(array('INBOX', 'Sent', 'Archives / 2025', 'Clients été'), array_column($f, 'label'), 'common prefix INBOX. hidden');
		$g = array(array('path' => 'INBOX', 'delimiter' => '/'), array('path' => 'Sent', 'delimiter' => '/'), array('path' => 'INBOX/Work', 'delimiter' => '/'));
		InboxImapConnector::addLabels($g);
		$this->assertSame(array('INBOX', 'Sent', 'INBOX / Work'), array_column($g, 'label'), 'no common prefix: kept');
	}

	/**
	 * @return void
	 */
	public function testGuessRole()
	{
		$this->assertSame('sent', InboxImapConnector::guessRole('INBOX.Envoy&AOk-s'));
		$this->assertSame('', InboxImapConnector::guessRole('INBOX.Archives.2025'));
		$this->assertSame('junk', InboxImapConnector::guessRole('Spam'));
		$this->assertSame('trash', InboxImapConnector::guessRole('x', array('\\trash')));
	}
}
