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
 * \file    inbox/test/phpunit/InboxServerTest.php
 * \ingroup inbox
 * \brief   1.54.0: Settings > Server (keeping, timeouts, master user, scheduled jobs and the hook of their card)
 */

require_once __DIR__.'/InboxTestCase.php';
dol_include_once('/inbox/class/inboxserver.class.php');

/**
 * Hook of a test: the card of a job given by another interface
 */
class InboxServerTestHook
{
	/** @var string */
	public $resprints = '';

	/**
	 * @param array<string,mixed> $parameters Parameters
	 * @param object              $object     Job
	 * @param string              $action     Action
	 * @param HookManager         $hookmanager Hook manager
	 * @return int
	 */
	public function inboxCronJobUrl($parameters, &$object, &$action, $hookmanager)
	{
		$this->resprints = '/neo/cron/'.((int) $object->rowid);

		return 1;
	}
}

/**
 * No mail server needed
 */
class InboxServerTest extends InboxTestCase
{
	/** @var array<string,string> Constants before the test */
	protected $saved = array();

	/** @var array<int,int> Status of the jobs before the test */
	protected $jobs = array();

	/**
	 * @return void
	 */
	protected function setUp(): void
	{
		parent::setUp();
		foreach (array('INBOX_CACHE_DAYS', 'INBOX_IMAP_TIMEOUT', 'INBOX_MASTER_USER', 'INBOX_MASTER_HOST', 'INBOX_MASTER_SEPARATOR', 'INBOX_MASTER_PASSWORD') as $k) {
			$this->saved[$k] = getDolGlobalString($k);
		}
		$rows = $this->db->getRows("SELECT rowid, status FROM ".$this->db->prefix()."cronjob WHERE classesname LIKE '/inbox/class/%' LIMIT 50");
		foreach (is_array($rows) ? $rows : array() as $r) {
			$this->jobs[(int) $r->rowid] = (int) $r->status;
		}
	}

	/**
	 * @return void
	 */
	protected function tearDown(): void
	{
		global $conf, $hookmanager;

		foreach ($this->saved as $k => $v) {
			if ($v === '') {
				dolibarr_del_const($this->db, $k, $conf->entity);
				// dolibarr_del_const() leaves '' in memory: getDolGlobalInt() would then give 0, not the default (an IMAP
				// timeout of 0 for the tests run after this one)
				unset($conf->global->$k);
			} else {
				dolibarr_set_const($this->db, $k, $v, 'chaine', 0, '', $conf->entity);
			}
		}
		foreach ($this->jobs as $id => $status) {
			$this->db->query("UPDATE ".$this->db->prefix()."cronjob SET status = ".$status." WHERE rowid = ".$id);
		}
		if (is_object($hookmanager)) {
			unset($hookmanager->hooks['inbox']['inboxservertest'], $hookmanager->hooksSorted['inbox']['50:inboxservertest']);
		}
		parent::tearDown();
	}

	/**
	 * Numbers within their bounds only; the master user as the old form of the setup page checked it
	 *
	 * @return void
	 */
	public function testSettings()
	{
		$s = new InboxServer($this->db);
		$this->assertTrue($s->setNumber('INBOX_CACHE_DAYS', '12'));
		$this->assertSame(12, $s->get()['numbers']['INBOX_CACHE_DAYS']['value']);
		$this->assertFalse($s->setNumber('INBOX_CACHE_DAYS', '0'), 'below its minimum');
		$this->assertFalse($s->setNumber('INBOX_IMAP_TIMEOUT', '500'), 'above its maximum');
		$this->assertFalse($s->setNumber('INBOX_IMAP_TIMEOUT', '12abc'));
		$this->assertFalse($s->setNumber('MAIN_INFO_SOCIETE_NOM', '5'), 'only the settings of the page');
		$this->assertSame('InboxErrorServerValue', $s->error);

		$this->assertTrue($s->setMaster('master', 'Mail.Example.invalid', '*', 'secret'));
		$m = $s->get()['master'];
		$this->assertSame('mail.example.invalid', $m['host']);
		$this->assertSame(1, $m['password_kept']);
		$this->assertArrayNotHasKey('password', $m, 'never sent back');
		$this->assertNotSame('secret', getDolGlobalString('INBOX_MASTER_PASSWORD'), 'kept encrypted');
		$this->assertSame('secret', dolDecrypt(getDolGlobalString('INBOX_MASTER_PASSWORD')));
		$this->assertTrue($s->setMaster('master', 'mail.example.invalid', '*', ''), 'an empty password keeps it');
		$this->assertSame('secret', dolDecrypt(getDolGlobalString('INBOX_MASTER_PASSWORD')));
		$this->assertFalse($s->setMaster('ma*ster', 'mail.example.invalid', '*', ''));
		$this->assertSame('InboxErrorMasterUserSeparator', $s->error);
		$this->assertFalse($s->setMaster('master', 'mail.example.invalid', ' ', ''));
		$this->assertSame('InboxErrorMasterSeparator', $s->error);
		$this->assertTrue($s->setMaster('', '', '*', ''), 'no user: no password');
		$this->assertSame('', getDolGlobalString('INBOX_MASTER_PASSWORD'));
	}

	/**
	 * The jobs of inbox only, switched on and off; the address of their card given by a hook
	 *
	 * @return void
	 */
	public function testCronJobs()
	{
		global $hookmanager;

		$s = new InboxServer($this->db);
		$cron = $s->cronJobs();
		if (!$cron['jobs']) {
			$this->markTestSkipped('No scheduled job of inbox in this database (module never enabled)');
		}
		$keys = array_column($cron['jobs'], 'key');
		$this->assertContains('InboxCronSync', $keys);
		$sync = $cron['jobs'][array_search('InboxCronSync', $keys, true)];
		$this->assertSame(1, $sync['needed']);
		$this->assertSame(60, $sync['every']);
		$this->assertStringContainsString('/cron/card.php?id='.$sync['id'], $sync['url']);

		$this->assertTrue($s->setJobActive($sync['id'], false));
		$this->assertSame(0, $this->job($s->cronJobs(), $sync['id'])['active']);
		$this->assertTrue($s->setJobActive($sync['id'], true));
		$this->assertSame(1, $this->job($s->cronJobs(), $sync['id'])['active']);
		// A job of another module is never touched
		$other = $this->db->getRow("SELECT rowid FROM ".$this->db->prefix()."cronjob WHERE classesname NOT LIKE '/inbox/class/%'");
		if (is_object($other)) {
			$this->assertFalse($s->setJobActive((int) $other->rowid, false));
		}

		// Another interface gives its own card
		$hookmanager->initHooks(array('inbox'));
		// Both lists: hooksSorted is the one read since Dolibarr 19, hooks before
		$hook = new InboxServerTestHook();
		$hookmanager->hooks['inbox']['inboxservertest'] = $hook;
		$hookmanager->hooksSorted['inbox']['50:inboxservertest'] = $hook;
		$this->assertSame('/neo/cron/'.$sync['id'], $this->job($s->cronJobs(), $sync['id'])['url']);
	}

	/**
	 * @param array<string,mixed> $cron cronJobs()
	 * @param int                 $id   Job
	 * @return array<string,mixed>
	 */
	protected function job(array $cron, $id)
	{
		foreach ($cron['jobs'] as $j) {
			if ($j['id'] === $id) {
				return $j;
			}
		}
		$this->fail('job '.$id);
	}
}
