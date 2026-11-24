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
 * \file    test/phpunit/InboxThunderbirdQrTest.php
 * \ingroup inbox
 * \brief   Import from the QR codes « Export for mobile » of Thunderbird (1.24.0): format, mapping, session, creation
 */

// From the command line only, never from the web: test/ stays in the Git branches
if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

require_once __DIR__.'/InboxTestCase.php';
dol_include_once('/inbox/class/inboxthunderbirdqr.class.php');

/**
 * Tests of InboxThunderbirdQr
 */
class InboxThunderbirdQrTest extends InboxTestCase
{
	/** @var string|null */
	protected $allowedHosts;

	/**
	 * @return void
	 */
	protected function setUp(): void
	{
		global $conf;

		parent::setUp();
		$this->allowedHosts = isset($conf->global->INBOX_ALLOWED_HOSTS) ? $conf->global->INBOX_ALLOWED_HOSTS : null;
		$_SESSION['inbox_qr'] = array();
	}

	/**
	 * @return void
	 */
	protected function tearDown(): void
	{
		global $conf;

		if ($this->allowedHosts === null) {
			unset($conf->global->INBOX_ALLOWED_HOSTS);
		} else {
			$conf->global->INBOX_ALLOWED_HOSTS = $this->allowedHosts;
		}
		unset($_SESSION['inbox_qr']);
		parent::tearDown();
	}

	/**
	 * One account of the format
	 *
	 * @param array<int,mixed> $in  Changes of the IncomingServer
	 * @param array<int,mixed> $out Changes of the OutgoingServer
	 * @param array<int,array<int,string>> $ids Identities
	 * @return array<int,mixed> IncomingServer, OutgoingServerGroups
	 */
	protected function account($in = array(), $out = array(), $ids = null)
	{
		$incoming = array_replace(array(0, 'imap.example.com', 993, 3, 1, 'jo@inbox-test.invalid', 'Work', 'S3cret!'), $in);
		$outgoing = array_replace(array(0, 'smtp.example.com', 465, 3, 1, 'jo@inbox-test.invalid', 'S3cret!'), $out);
		$ids = $ids ?? array(array('jo@inbox-test.invalid', 'Jo Test'), array('sales@inbox-test.invalid', 'Sales'));

		return array($incoming, array(array_merge(array($outgoing), $ids)));
	}

	/**
	 * Text of one code
	 *
	 * @param int $number Number
	 * @param int $end    End
	 * @param array<int,array<int,mixed>> $accounts Accounts (account())
	 * @return string
	 */
	protected function code($number, $end, array $accounts)
	{
		$data = array(1, array($number, $end));
		foreach ($accounts as $a) {
			$data[] = $a[0];
			$data[] = $a[1];
		}

		return (string) json_encode($data);
	}

	/**
	 * @return void
	 */
	public function testMapping()
	{
		$qr = new InboxThunderbirdQr($this->db);
		$r = $qr->parseCode($this->code(1, 1, array($this->account())));
		$this->assertNotNull($r, $qr->error);
		$this->assertSame(array(1, 1), array($r['number'], $r['end']));
		$a = $r['accounts'][0];
		$this->assertSame('', $a['skip']);
		$this->assertSame('jo@inbox-test.invalid', $a['email']);
		$this->assertSame('Work', $a['label']);
		$this->assertSame(array('imap.example.com', 993, 'ssl', ''), array($a['imap_host'], $a['imap_port'], $a['imap_encryption'], $a['imap_login']), 'login = address: kept empty');
		$this->assertSame('S3cret!', $a['imap_password']);
		$this->assertSame(array('smtp.example.com', 465, 'ssl', 1), array($a['smtp_host'], $a['smtp_port'], $a['smtp_encryption'], $a['smtp_same_credentials']));
		$this->assertSame('', $a['smtp_password'], 'same credentials: no second copy of the password');
		$this->assertCount(2, $a['identities']);
		$this->assertSame(array('email' => 'sales@inbox-test.invalid', 'name' => 'Sales'), $a['identities'][1]);
		$this->assertFalse($a['oauth']);

		// STARTTLS, other login, no name, another password for the sending
		$r = $qr->parseCode($this->code(1, 1, array($this->account(array(2 => 143, 3 => 2, 5 => 'jo', 6 => ''), array(2 => 587, 3 => 2, 5 => 'jo-out', 6 => 'Other')))));
		$a = $r['accounts'][0];
		$this->assertSame(array('tls', 'jo', 'jo@inbox-test.invalid'), array($a['imap_encryption'], $a['imap_login'], $a['label']), 'empty name: the first address');
		$this->assertSame(array(0, 'jo-out', 'Other', 'tls'), array($a['smtp_same_credentials'], $a['smtp_login'], $a['smtp_password'], $a['smtp_encryption']));

		// Password absent: null, empty, or not there at all
		foreach (array(array(7 => null), array(7 => '')) as $in) {
			$a = $qr->parseCode($this->code(1, 1, array($this->account($in, array(6 => null)))))['accounts'][0];
			$this->assertSame('', $a['imap_password']);
			$this->assertSame(1, $a['smtp_same_credentials']);
		}
		$short = $this->account();
		$short[0] = array_slice($short[0], 0, 6);
		$this->assertSame('', $qr->parseCode($this->code(1, 1, array($short)))['accounts'][0]['imap_password']);
	}

	/**
	 * @return void
	 */
	public function testSkippedAccounts()
	{
		global $conf;

		$qr = new InboxThunderbirdQr($this->db);
		$cases = array(
			'pop3' => $this->account(array(0 => 1)),
			'auth' => $this->account(array(4 => 4)),
			'unsupported' => $this->account(array(3 => 1)),
			'encryption' => $this->account(array(3 => 0)),
			'identity' => $this->account(array(), array(), array()),
		);
		foreach ($cases as $reason => $account) {
			$r = $qr->parseCode($this->code(1, 1, array($account, $this->account())));
			$this->assertNotNull($r, $reason.': '.$qr->error);
			$this->assertSame($reason, $r['accounts'][0]['skip'], $reason);
			$this->assertSame('', $r['accounts'][1]['skip'], 'the next account is still read');
		}
		$this->assertSame('auth', $qr->parseCode($this->code(1, 1, array($this->account(array(), array(4 => 5)))))['accounts'][0]['skip'], 'certificate on the sending');
		// No encryption: only toward the same machine
		$this->assertSame('', $qr->parseCode($this->code(1, 1, array($this->account(array(1 => '127.0.0.1', 3 => 0), array(1 => 'localhost', 3 => 0)))))['accounts'][0]['skip']);

		// OAuth: taken, without any password
		$a = $qr->parseCode($this->code(1, 1, array($this->account(array(4 => 6)))))['accounts'][0];
		$this->assertSame(array('', true, '', ''), array($a['skip'], $a['oauth'], $a['imap_password'], $a['smtp_password']));

		// Hosts allowed by the setting
		$conf->global->INBOX_ALLOWED_HOSTS = '.example.org';
		$this->assertSame('host', $qr->parseCode($this->code(1, 1, array($this->account())))['accounts'][0]['skip']);
		$conf->global->INBOX_ALLOWED_HOSTS = '.example.com';
		$this->assertSame('', $qr->parseCode($this->code(1, 1, array($this->account())))['accounts'][0]['skip']);
	}

	/**
	 * @return void
	 */
	public function testRefusedCodes()
	{
		$qr = new InboxThunderbirdQr($this->db);
		$good = $this->account();
		$refused = array(
			'invalid' => array('not json', '{"a":1}', '[1]', (string) json_encode(array(1, array(1, 1), $good[0])), 'https://example.com'),
			'version' => array((string) json_encode(array(2, array(1, 1), $good[0], $good[1]))),
			'sequence' => array((string) json_encode(array(1, array(2, 1))), (string) json_encode(array(1, array(0, 1))), (string) json_encode(array(1, array('1', 1)))),
			'limit' => array('', '['.str_repeat(' ', InboxThunderbirdQr::MAX_BYTES).']'),
		);
		foreach ($refused as $error => $texts) {
			foreach ($texts as $text) {
				$this->assertNull($qr->parseCode($text), substr($text, 0, 40));
				$this->assertSame($error, $qr->error, substr($text, 0, 40));
			}
		}
		// A wrong value anywhere refuses the code: port as text, host with a space, address not valid, object
		$wrong = array(
			$this->account(array(2 => '993')),
			$this->account(array(1 => 'imap example.com')),
			$this->account(array(), array(), array(array('not-an-address', 'X'))),
			$this->account(array(5 => "jo\ninjected")),
			$this->account(array(), array(2 => 70000)),
		);
		foreach ($wrong as $n => $account) {
			$this->assertNull($qr->parseCode($this->code(1, 1, array($account))), 'case '.$n);
			$this->assertSame('invalid', $qr->error);
		}
		$object = $this->account();
		$object[0] = array('protocol' => 0) + $object[0];
		$this->assertNull($qr->parseCode($this->code(1, 1, array($object))));
	}

	/**
	 * @return void
	 */
	public function testSeveralCodes()
	{
		$qr = new InboxThunderbirdQr($this->db);
		$c1 = $this->code(1, 3, array($this->account(array(6 => 'One'))));
		$c2 = $this->code(2, 3, array($this->account(array(6 => 'Two')), $this->account(array(6 => 'Three'))));
		$c3 = $this->code(3, 3, array($this->account(array(6 => 'Four'))));

		// Any order, a code read twice counts once
		$r = $qr->parseAll(array($c3, $c1, $c2, $c1));
		$this->assertNotNull($r, $qr->error);
		$this->assertTrue($r['complete']);
		$this->assertSame(array(1, 2, 3), $r['read']);
		$this->assertSame(array('One', 'Two', 'Three', 'Four'), array_column($r['accounts'], 'label'));

		$r = $qr->parseAll(array($c1, $c3));
		$this->assertFalse($r['complete']);
		$this->assertSame(3, $r['end']);

		// Codes of two exports
		$this->assertNull($qr->parseAll(array($c1, $this->code(2, 2, array($this->account())))));
		$this->assertSame('sequence', $qr->error);
		// Bounds
		$this->assertNull($qr->parseAll(array()));
		$this->assertNull($qr->parseAll(array_fill(0, InboxThunderbirdQr::MAX_CODES + 1, $c1)));
		$this->assertSame('limit', $qr->error);
		$this->assertNull($qr->parseAll(array(array('nested'))));
		$this->assertSame('invalid', $qr->error);
	}

	/**
	 * @return void
	 */
	public function testSessionAndClient()
	{
		$qr = new InboxThunderbirdQr($this->db);
		$accounts = $qr->parseCode($this->code(1, 1, array($this->account(), $this->account(array(0 => 1)))))['accounts'];
		$token = $qr->keep($accounts);
		$this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $token);
		$this->assertStringNotContainsString('S3cret!', serialize($_SESSION['inbox_qr']), 'encrypted in session');

		// The browser never gets a password
		$client = $qr->forClient($accounts);
		$this->assertStringNotContainsString('S3cret!', (string) json_encode($client));
		$this->assertTrue($client[0]['password']);
		$this->assertFalse($client[0]['exists']);
		$this->assertNull($client[0]['owner']);
		$this->assertSame('pop3', $client[1]['skip']);

		// Used once; unknown or expired: nothing
		$this->assertSame($accounts, $qr->take($token));
		$this->assertNull($qr->take($token));
		$this->assertNull($qr->take(''));
		$token = $qr->keep($accounts);
		$_SESSION['inbox_qr'][$token]['t'] = dol_now() - InboxThunderbirdQr::TTL - 1;
		$this->assertNull($qr->take($token));
		// At most 5 readings kept
		for ($i = 0; $i < 7; $i++) {
			$qr->keep($accounts);
		}
		$this->assertLessThanOrEqual(5, count($_SESSION['inbox_qr']));
	}

	/**
	 * @return void
	 */
	public function testImportWithoutPasswordOrWithOAuth()
	{
		$qr = new InboxThunderbirdQr($this->db);
		$importer = $this->user('qrimporter');
		$owner = $this->user('qrowner');
		$this->db->query("UPDATE ".$this->db->prefix()."user SET email = 'jo@inbox-test.invalid' WHERE rowid = ".((int) $owner->id));
		$accounts = $qr->parseCode($this->code(1, 1, array(
			$this->account(array(7 => null), array(6 => null)),
			$this->account(array(4 => 6, 5 => 'oauth@inbox-test.invalid'), array(), array(array('oauth@inbox-test.invalid', 'O'))),
			$this->account(array(0 => 1, 5 => 'pop@inbox-test.invalid'), array(), array(array('pop@inbox-test.invalid', 'P'))),
		)))['accounts'];
		$this->assertSame(array('id' => (int) $owner->id, 'name' => $owner->getFullName($GLOBALS['langs'])), $qr->forClient($accounts)[0]['owner'], 'owner proposed by the address');

		$row = $qr->importAccount($accounts[0], InboxAccount::TYPE_PERSONAL, (int) $owner->id, true, $importer, null);
		$this->assertSame('password', $row['state']);
		$account = new InboxAccount($this->db);
		$this->assertGreaterThan(0, $account->fetch($row['id']));
		$this->assertSame(InboxAccount::STATUS_DRAFT, (int) $account->status);
		$this->assertSame(array(InboxAccount::TYPE_PERSONAL, (int) $owner->id, 'tbqr'), array($account->type, (int) $account->fk_user_owner, $account->import_key));
		$identities = (new InboxIdentity($this->db))->listForAccount($row['id']);
		$this->assertCount(2, $identities);
		$this->assertSame(InboxAccess::LEVEL_MANAGE, $this->levelLine($row['id'], $importer->id));

		// Imported again: not created twice
		$this->assertSame('exists', $qr->importAccount($accounts[0], '', 0, true, $importer, null)['state']);

		$row = $qr->importAccount($accounts[1], 'personal', 0, false, $importer, null);
		$this->assertSame('oauth', $row['state']);
		$account = new InboxAccount($this->db);
		$account->fetch($row['id']);
		$this->assertSame(array(InboxAccount::TYPE_SHARED, InboxAccount::AUTH_LOGIN, ''), array($account->type, $account->imap_auth_mode, $account->getImapPassword()), 'personal without owner: shared');
		$this->assertSame('', $this->levelLine($row['id'], $importer->id), 'no access when not asked');

		// POP3: never created
		$this->assertNull($qr->importAccount($accounts[2], '', 0, true, $importer, null));
		$this->assertSame(0, (new InboxAccount($this->db))->fetchByEmail('pop@inbox-test.invalid') > 0 ? 1 : 0);
	}

	/**
	 * @return void
	 */
	public function testImportTestedAndEnabled()
	{
		$this->needDovecot();
		$qr = new InboxThunderbirdQr($this->db);
		$in = array(0, self::IMAP_HOST, self::IMAP_PORT, 0, 2, self::BOX, 'Devis', self::BOX_PASSWORD);
		$out = array(0, self::IMAP_HOST, self::SMTP_PORT, 0, 2, self::BOX, self::BOX_PASSWORD);
		$accounts = $qr->parseCode($this->code(1, 1, array($this->account($in, $out, array(array(self::BOX, 'Devis'))))))['accounts'];
		$this->assertSame('', $accounts[0]['skip']);
		$row = $qr->importAccount($accounts[0], 'shared', 0, true, $this->admin, new InboxServerTester($this->db));
		$this->assertSame('active', $row['state'], $row['message']);
		$account = new InboxAccount($this->db);
		$account->fetch($row['id']);
		$this->assertTrue($account->isActive());
		$this->assertSame(self::BOX_PASSWORD, $account->getImapPassword());
		$this->assertNotSame(self::BOX_PASSWORD, $account->imap_password, 'encrypted in base');
		// The journal of the mailbox never holds the password
		$log = $this->db->getRows("SELECT value_before, value_after FROM ".$this->db->prefix()."inbox_account_log WHERE fk_account = ".((int) $row['id'])." LIMIT 100");
		$this->assertNotEmpty($log);
		$this->assertStringNotContainsString(self::BOX_PASSWORD, (string) json_encode($log));
	}

	/**
	 * Level of a direct access line (the cache of InboxAccess ignores the draft mailboxes)
	 *
	 * @param int $accountId Mailbox
	 * @param int $userId    User
	 * @return string
	 */
	protected function levelLine($accountId, $userId)
	{
		$row = $this->db->getRow("SELECT level FROM ".$this->db->prefix()."inbox_account_access WHERE fk_account = ".((int) $accountId)." AND fk_user = ".((int) $userId));

		return is_object($row) ? (string) $row->level : '';
	}
}
