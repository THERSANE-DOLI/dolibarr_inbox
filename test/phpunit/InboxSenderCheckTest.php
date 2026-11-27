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
 * \file    inbox/test/phpunit/InboxSenderCheckTest.php
 * \ingroup inbox
 * \brief   Lot W5 1.15.0: imitation of a known sender, junk probably legitimate; known senders out of the junk (1.147.0)
 */

// From the command line only, never from the web: test/ stays in the Git branches
if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

require_once __DIR__.'/InboxImapTestCase.php';
dol_include_once('/inbox/class/inboxknownsenders.class.php');

/**
 * Headers checked alone, then the pass of the synchronisation on the junk of the local Dovecot
 */
class InboxSenderCheckTest extends InboxImapTestCase
{
	const SERVER = 'mx.inbox-test.invalid';
	const BOSS = 'boss@client.inbox-test.invalid';

	/** @var string Setting before the test */
	protected $servers = '';

	/**
	 * @return void
	 */
	protected function setUp(): void
	{
		global $conf;

		parent::setUp();
		$this->servers = getDolGlobalString('INBOX_AUTH_SERVERS');
		$conf->global->INBOX_AUTH_SERVERS = self::SERVER;
		$p = $this->db->prefix();
		$this->db->query("INSERT INTO ".$p."societe (nom, entity, status, client, email, datec) VALUES ('inboxtest_client', 1, 1, 1, '".self::BOSS."', '".$this->db->idate(dol_now())."')");
	}

	/**
	 * @return void
	 */
	protected function tearDown(): void
	{
		global $conf;

		$conf->global->INBOX_AUTH_SERVERS = $this->servers;
		unset($conf->global->INBOX_JUNK_RESCUE, $conf->global->INBOX_JUNK_RESCUE_SINCE);
		parent::tearDown();
	}

	/**
	 * @param string $from Value of From
	 * @param string $auth Value of the Authentication-Results of the trusted server ('' = none)
	 * @return string Raw headers
	 */
	protected function head($from, $auth)
	{
		return ($auth !== '' ? "Authentication-Results: ".self::SERVER."; ".$auth."\r\n" : '')."From: ".$from."\r\nSubject: Commande\r\n";
	}

	/**
	 * @return void
	 */
	public function testHeaders()
	{
		global $conf;

		$known = new InboxKnownSenders($this->db);
		$ok = 'dkim=pass header.d=client.inbox-test.invalid; spf=pass smtp.mailfrom=client.inbox-test.invalid; dmarc=pass header.from=client.inbox-test.invalid';
		$res = $known->check($this->head('Boss <'.self::BOSS.'>', $ok));
		$this->assertSame('legit', $res['state']);
		$this->assertGreaterThan(0, $res['sender']['socid']);

		$bad = 'dkim=fail header.d=client.inbox-test.invalid; spf=fail smtp.mailfrom=evil.invalid; dmarc=fail header.from=client.inbox-test.invalid';
		$this->assertSame('imitation', $known->check($this->head('Boss <'.self::BOSS.'>', $bad))['state'], 'a known sender not authenticated');

		// Results written by another server (the sender himself): not trusted, nothing said
		$forged = "Authentication-Results: mx.evil.invalid; dmarc=pass header.from=client.inbox-test.invalid\r\nFrom: Boss <".self::BOSS.">\r\n";
		$this->assertSame('unknown', $known->check($forged)['state']);

		// The address of a known sender written in the display name over another address
		$res = $known->check($this->head('"'.self::BOSS.'" <ceo@evil.invalid>', ''));
		$this->assertSame('imitation', $res['state']);
		$this->assertSame(self::BOSS, $res['address']);

		$this->assertSame('unknown', $known->check($this->head('Someone <someone@else.invalid>', $ok))['state'], 'unknown sender');
		$conf->global->INBOX_AUTH_SERVERS = '';
		$this->assertSame('unknown', (new InboxKnownSenders($this->db))->check($this->head('Boss <'.self::BOSS.'>', $bad))['state'], 'no trusted server: nothing said');
	}

	/**
	 * @return void
	 */
	public function testJunkOfTheSynchronisation()
	{
		$a = $this->ready();
		$raw = "Authentication-Results: ".self::SERVER."; dkim=pass header.d=client.inbox-test.invalid; dmarc=pass header.from=client.inbox-test.invalid\r\n"
			."Message-ID: <jk1".self::MARK.">\r\nFrom: Boss <".self::BOSS.">\r\nTo: ".self::BOX."\r\nSubject: ZzAct jk1\r\nDate: ".date('r', mktime(10, 0, 0, 3, 15, 2025))."\r\n\r\nCommande\r\n";
		$this->imap()->appendMessage('INBOX.Junk', $raw);
		$this->append('INBOX.Junk', 'jk2');
		$this->sync($a);
		$p = $this->db->prefix();
		$this->assertSame('legit', (string) $this->db->getRow("SELECT junk_hint FROM ".$p."inbox_mail WHERE message_id = 'jk1".self::MARK."'")->junk_hint);
		$row = $this->db->getRow("SELECT junk_hint, junk_check FROM ".$p."inbox_mail WHERE message_id = 'jk2".self::MARK."'");
		$this->assertSame('', (string) $row->junk_hint, 'an unknown sender');
		$this->assertSame(1, (int) $row->junk_check, 'checked once');
	}

	/**
	 * @param string $id   Local part of the Message-ID
	 * @param string $auth Results of the trusted server
	 * @return string Raw mail of the known sender
	 */
	protected function knownMail($id, $auth)
	{
		return "Authentication-Results: ".self::SERVER."; ".$auth."\r\nMessage-ID: <".$id.self::MARK.">\r\nFrom: Boss <".self::BOSS.">\r\nTo: ".self::BOX
			."\r\nSubject: ZzAct ".$id."\r\nDate: ".date('r')."\r\n\r\nCommande\r\n";
	}

	/**
	 * Known senders taken out of the junk (1.147.0): authenticated, arrived since the switch, never $Junk
	 *
	 * @return void
	 */
	public function testRescueOfTheJunk()
	{
		global $conf;

		$a = $this->ready();
		$ok = 'dkim=pass header.d=client.inbox-test.invalid; dmarc=pass header.from=client.inbox-test.invalid';
		$bad = 'dkim=fail header.d=client.inbox-test.invalid; spf=fail smtp.mailfrom=evil.invalid; dmarc=fail header.from=client.inbox-test.invalid';
		$p = $this->db->prefix();

		// Switch off: pointed out only
		$this->imap()->appendMessage('INBOX.Junk', $this->knownMail('jr0', $ok));
		$this->sync($a);
		$this->assertNotEmpty($this->onServer('INBOX.Junk', 'jr0'), 'switch off: stays in the junk');

		$conf->global->INBOX_JUNK_RESCUE = '1';
		$conf->global->INBOX_JUNK_RESCUE_SINCE = (string) (dol_now() - 3600);
		$this->imap()->appendMessage('INBOX.Junk', $this->knownMail('jr1', $ok));
		$this->imap()->appendMessage('INBOX.Junk', $this->knownMail('jr2', $ok), array('$Junk'));
		$this->imap()->appendMessage('INBOX.Junk', $this->knownMail('jr3', $ok), null, date('d-M-Y H:i:s O', dol_now() - 30 * 86400));
		$this->imap()->appendMessage('INBOX.Junk', $this->knownMail('jr4', $bad));
		$this->append('INBOX.Junk', 'jr5');
		$this->sync($a);

		$uids = $this->onServer('INBOX', 'jr1');
		$this->assertCount(1, $uids, 'known and authenticated: back to the inbox');
		$this->assertEmpty($this->onServer('INBOX.Junk', 'jr1'));
		$this->assertStringContainsString('$NotJunk', $this->flagsOnServer('INBOX', $uids[0]));
		$this->assertSame($this->folder($a, 'INBOX'), (int) $this->row($a, 'jr1')->fk_folder, 'the index follows');
		$this->assertSame(0, (int) $this->row($a, 'jr1')->seen, 'still unread');
		$log = $this->db->getRow("SELECT action, folder_from, folder_to, fk_user, consumer FROM ".$p."inbox_action_log WHERE fk_account = ".((int) $a->id)." AND action = 'rescue'");
		$this->assertIsObject($log, 'written in the journal');
		$this->assertStringContainsString('Junk', (string) $log->folder_from);
		// Written as inbox itself, not as the user of the synchronisation (1.147.1)
		$this->assertSame(0, (int) $log->fk_user);
		$this->assertNull($log->consumer);
		$journal = InboxActionLog::listForAccount($this->db, (int) $a->id, array('system' => 1));
		$this->assertCount(1, $journal['lines']);
		$this->assertTrue($journal['lines'][0]['system']);
		$this->assertSame(array(), array_values(array_filter(InboxActionLog::$written, function ($id) use ($journal) {
			return $id === $journal['lines'][0]['id'];
		})), 'never undone by the page of the user');

		foreach (array('jr2' => 'marked as junk by someone', 'jr3' => 'arrived before the switch', 'jr4' => 'not authenticated', 'jr5' => 'unknown sender', 'jr0' => 'checked before the switch') as $id => $why) {
			$this->assertNotEmpty($this->onServer('INBOX.Junk', $id), $id.': '.$why);
			$this->assertEmpty($this->onServer('INBOX', $id), $id.': '.$why);
		}
		$this->assertSame('legit', (string) $this->db->getRow("SELECT junk_hint FROM ".$p."inbox_mail WHERE message_id = 'jr3".self::MARK."'")->junk_hint, 'still pointed out');

		// Without trusted servers nothing moves
		$conf->global->INBOX_AUTH_SERVERS = '';
		$this->assertFalse(InboxKnownSenders::rescueEnabled());
	}
}
