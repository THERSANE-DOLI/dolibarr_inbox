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
 * \file    inbox/test/phpunit/InboxLot0Test.php
 * \ingroup inbox
 * \brief   Reading by Message-ID on the mailboxes of inbox, hooks for the other modules, import (CA-07, CA-08, CA-17, CA-25)
 */

// From the command line only, never from the web: test/ stays in the Git branches
if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

/**
 * Hooks of a module plugged on inbox (as crmclientconnector will do in its repository)
 */
class InboxTestHooks
{
	/** @var array<string,mixed> */
	public $results = array();
	/** @var string */
	public $resprints = '';
	/** @var string */
	public $error = '';
	/** @var string[] */
	public $errors = array();
	/** @var string[] */
	public $warnings = array();

	/**
	 * @param array<string,mixed> $parameters Parameters
	 * @param mixed               $object     Object
	 * @param string              $action     Action
	 * @param HookManager         $hookmanager Hook manager
	 * @return int
	 */
	public function inboxResolveAccount($parameters, &$object, &$action, $hookmanager)
	{
		if ($parameters['mailbox'] !== 'other@inbox-test.invalid') {
			return 0;
		}
		$a = new InboxAccount($GLOBALS['inbox_test_db']);
		$a->email = 'other@inbox-test.invalid';
		$a->imap_host = InboxTestCase::IMAP_HOST;
		$a->imap_port = InboxTestCase::IMAP_PORT;
		$a->imap_encryption = 'none';
		$a->imap_login = InboxTestCase::BOX;
		$a->setImapPassword(InboxTestCase::BOX_PASSWORD);
		$a->status = InboxAccount::STATUS_ACTIVE;
		$this->results = array('account' => $a);

		return 1;
	}

	/**
	 * @param array<string,mixed> $parameters Parameters
	 * @param mixed               $object     Object
	 * @param string              $action     Action
	 * @param HookManager         $hookmanager Hook manager
	 * @return int
	 */
	public function inboxAccountImportSources($parameters, &$object, &$action, $hookmanager)
	{
		$this->results = array('testsrc' => array('label' => 'Test source', 'accounts' => array(
			array('email' => 'Import1@inbox-test.invalid', 'label' => 'Import 1', 'imap_host' => '127.0.0.1', 'imap_port' => 10143, 'imap_encryption' => 'none', 'imap_password' => 'p1'),
			array('email' => InboxTestCase::BOX, 'label' => 'Already there'),
			array('email' => 'not an email'),
		)));

		return 0;
	}
}

/**
 * Hook manager of the tests: only the hooks above, on the context inbox
 */
class InboxTestHookManager extends HookManager
{
	/**
	 * @param string[] $arraycontext Contexts
	 * @return int
	 */
	public function initHooks($arraycontext)
	{
		$this->contextarray = $arraycontext;
		$this->hooks = array('inbox' => array('inboxtest' => new InboxTestHooks()));
		$this->hooksSorted = $this->hooks;

		return 1;
	}
}

/**
 * Tests of the lot 0 rewired on inbox
 */
class InboxLot0Test extends InboxTestCase
{
	/** @var HookManager|null */
	protected $savedHookManager;

	/**
	 * @return void
	 */
	protected function setUp(): void
	{
		global $hookmanager;

		parent::setUp();
		$this->savedHookManager = $hookmanager;
		$hookmanager = new InboxTestHookManager($this->db);
	}

	/**
	 * @return void
	 */
	protected function tearDown(): void
	{
		global $hookmanager;

		$hookmanager = $this->savedHookManager;
		parent::tearDown();
	}

	/**
	 * CA-17: an inbox mailbox first, then the mailbox given by another module
	 *
	 * @return void
	 */
	public function testReadByMessageId()
	{
		$this->needDovecot();
		$this->box(true, array('status' => InboxAccount::STATUS_ACTIVE));
		$inbox = new Inbox($this->db);
		$raw = $inbox->fetchRaw(self::BOX, '<seed-2@inbox-test.invalid>', $this->admin, 'phpunit', true);
		$this->assertIsString($raw);
		$this->assertFalse($inbox->fromCache);
		$row = $this->db->getRow("SELECT uid FROM ".$this->db->prefix()."inbox_message WHERE mailbox = '".self::BOX."' AND message_id = 'seed-2@inbox-test.invalid'");
		// The UID of the mail on the server, whatever the test box holds (seed.py run again appends the mail again)
		$this->assertGreaterThan(0, (int) $row->uid, 'UID of the mail kept');
		$this->assertMatchesRegularExpression('/^Message-ID:\s*<seed-2@inbox-test\.invalid>/mi', $raw, 'the mail asked');
		$this->assertSame($raw, $inbox->fetchRaw(self::BOX, 'seed-2@inbox-test.invalid', $this->admin, 'phpunit', true));
		$this->assertTrue($inbox->fromCache);
		$this->assertNull($inbox->fetchRaw(self::BOX, 'nope-x@inbox-test.invalid', $this->admin, 'phpunit', true));
		$this->assertSame(Inbox::RESULT_NOT_FOUND, $inbox->result);
		$this->assertIsString($inbox->fetchRaw('other@inbox-test.invalid', 'seed-1@inbox-test.invalid', $this->admin, 'phpunit', true), 'hook inboxResolveAccount');
		$this->assertNull($inbox->fetchRaw('nobody@inbox-test.invalid', 'seed-1@inbox-test.invalid', $this->admin, 'phpunit', true));
		$this->assertSame(Inbox::RESULT_INVALID, $inbox->result);
		$this->assertNull($inbox->fetchRaw(self::BOX, 'seed-1@inbox-test.invalid', new User($this->db), 'phpunit', false));
		$this->assertSame(Inbox::RESULT_FORBIDDEN, $inbox->result);
	}

	/**
	 * CA-07, CA-08: sources declared by a module, drafts without access, no duplicate
	 *
	 * @return void
	 */
	public function testImport()
	{
		$this->box(true);
		$imp = new InboxAccountImport($this->db);
		$this->assertArrayHasKey('testsrc', $imp->getSources());
		$new = $imp->listNew('testsrc');
		$this->assertCount(1, $new);
		$this->assertSame('import1@inbox-test.invalid', $new[0]['email']);
		$this->assertArrayNotHasKey('imap_password', $new[0]);
		$rep = $imp->import('testsrc', array('import1@inbox-test.invalid', self::BOX), $this->admin);
		$this->assertCount(1, $rep['created']);
		$this->assertSame(array(self::BOX), $rep['skipped']);
		$i1 = new InboxAccount($this->db);
		$i1->fetchByEmail('import1@inbox-test.invalid');
		$this->assertSame(InboxAccount::STATUS_DRAFT, (int) $i1->status);
		$this->assertSame('p1', $i1->getImapPassword());
		$this->assertCount(0, $imp->import('testsrc', array('import1@inbox-test.invalid'), $this->admin)['created']);
		$this->assertSame(0, (int) $this->db->getRow("SELECT COUNT(*) AS n FROM ".$this->db->prefix()."inbox_account_access WHERE fk_account = ".((int) $i1->id))->n);
		$this->assertNull($imp->import('nope', array(), $this->admin));
	}

	/**
	 * CA-25: no file of inbox names crmclientconnector (analyses and history of the ChangeLog excepted)
	 *
	 * @return void
	 */
	public function testNoTraceOfCrmClientConnector()
	{
		$root = dirname(__DIR__, 2);
		$hits = array();
		$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
		foreach ($it as $file) {
			$path = substr((string) $file, strlen($root) + 1);
			if (preg_match('#^(analyses|\.git|dev/tabler)/#', $path) || $path === 'ChangeLog.md' || !preg_match('/\.(php|js|lang|sql|md|json|css)$/', $path) || $path === 'test/phpunit/InboxLot0Test.php') {
				continue;
			}
			if (preg_match('/crmclientconnector|crm client connector/i', (string) file_get_contents((string) $file))) {
				$hits[] = $path;
			}
		}
		$this->assertSame(array(), $hits);
	}
}
