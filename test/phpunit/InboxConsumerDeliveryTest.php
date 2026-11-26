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
 * \file    inbox/test/phpunit/InboxConsumerDeliveryTest.php
 * \ingroup inbox
 * \brief   1.146.0: mails given to the modules subscribed, with the test mail server (event made once, never for the
 *          mails already there, folders, test mode, actions refused, errors given again, pause, removal, switch)
 */

// From the command line only, never from the web: test/ stays in the Git branches
if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

require_once __DIR__.'/InboxImapTestCase.php';
dol_include_once('/inbox/class/inboxconsumer.class.php');
dol_include_once('/inbox/class/inboxconsumerdelivery.class.php');

/**
 * Module of the tests: what it does with each mail is set by the test, what it got is kept
 */
class ZzDeliveryConsumer implements InboxConsumerInterface
{
	/** @var callable|null What the module does, null: nothing, handled */
	public static $do = null;

	/** @var InboxConsumerEvent[] Events received */
	public static $got = array();

	/**
	 * @param InboxConsumerEvent $event Event
	 * @return int
	 */
	public function handle(InboxConsumerEvent $event)
	{
		self::$got[] = $event;
		if (self::$do === null) {
			return 1;
		}
		$f = self::$do;

		return $f($event);
	}
}

/**
 * The test mailbox and its subscription are removed by InboxTestCase::cleanup()
 */
class InboxConsumerDeliveryTest extends InboxImapTestCase
{
	const CODE = 'zzdeliver';

	/** @var int Switch before the test */
	protected $enabledBefore = 0;

	/**
	 * @return void
	 */
	protected function setUp(): void
	{
		global $conf;

		parent::setUp();
		$this->enabledBefore = getDolGlobalInt('INBOX_CONSUMERS_ENABLED');
		$conf->global->INBOX_CONSUMERS_ENABLED = 1;
		InboxConsumer::useDeclarations(array(self::CODE => array('module' => 'zzdeliver', 'label' => 'ZzDeliver', 'purpose' => 'Tests',
			'class' => 'ZzDeliveryConsumer', 'file' => '/zzdeliver/class/zzdeliveryconsumer.class.php', 'events' => array('mail.received'),
			'actions' => array('read', 'seen', 'tag', 'move', 'link', 'draft'))));
		ZzDeliveryConsumer::$do = null;
		ZzDeliveryConsumer::$got = array();
	}

	/**
	 * @return void
	 */
	protected function tearDown(): void
	{
		global $conf;

		$conf->global->INBOX_CONSUMERS_ENABLED = $this->enabledBefore;
		InboxConsumer::useDeclarations(null);
		ZzDeliveryConsumer::$do = null;
		parent::tearDown();
	}

	/**
	 * The module allowed on the mailbox
	 *
	 * @param InboxAccount        $a   Mailbox
	 * @param array<string,mixed> $set events, actions, folders, mode
	 * @return void
	 */
	protected function grant(InboxAccount $a, array $set = array())
	{
		$c = new InboxConsumer($this->db);
		$ok = $c->save($this->admin, $a, self::CODE, $set + array('events' => array('mail.received'), 'actions' => array('read', 'seen', 'tag', 'move', 'link', 'draft'), 'mode' => 'active'));
		$this->assertTrue($ok, $c->error);
	}

	/**
	 * Events of the queue of the mailbox
	 *
	 * @param InboxAccount $a Mailbox
	 * @return object[]
	 */
	protected function queue(InboxAccount $a)
	{
		$rows = $this->db->getRows("SELECT rowid, message_id, status, attempts, next_try, error FROM ".$this->db->prefix()."inbox_consumer_event WHERE fk_account = ".((int) $a->id)." ORDER BY rowid LIMIT 100");

		return is_array($rows) ? $rows : array();
	}

	/**
	 * Lines of the journal written by the module
	 *
	 * @param InboxAccount $a Mailbox
	 * @return object[]
	 */
	protected function journal(InboxAccount $a)
	{
		$rows = $this->db->getRows("SELECT fk_user, user_name, consumer, action, folder_to, detail, simulated FROM ".$this->db->prefix()."inbox_action_log WHERE fk_account = ".((int) $a->id)
			." AND consumer IS NOT NULL ORDER BY rowid LIMIT 100");

		return is_array($rows) ? $rows : array();
	}

	/**
	 * The mails of the mailbox before the authorization never make an event, a new one once, given at the end of the
	 * same pass with its headers; seen again at the next passes: not given again
	 *
	 * @return void
	 */
	public function testNewMailGivenOnceNeverTheOldOnes()
	{
		$a = $this->ready();
		$this->grant($a);
		$this->sync($a);
		$this->assertSame(array(), $this->queue($a), 'the mails already there: no event');

		$this->append('INBOX', 'n1');
		$this->sync($a);
		$q = $this->queue($a);
		$this->assertCount(1, $q);
		$this->assertSame('n1'.self::MARK, (string) $q[0]->message_id);
		$this->assertSame('done', (string) $q[0]->status);
		$this->assertCount(1, ZzDeliveryConsumer::$got, 'given once over four passes');
		$e = ZzDeliveryConsumer::$got[0];
		$this->assertSame('mail.received', $e->event);
		$this->assertSame(self::CODE, $e->consumer);
		$this->assertSame((int) $a->id, $e->accountId);
		$this->assertSame('ZzAct n1', $e->headers['subject']);
		$this->assertSame('client@example.invalid', $e->headers['from']);
		$this->assertFalse($e->test);
		$grant = (new InboxConsumer($this->db))->grants((int) $a->id)[self::CODE];
		$this->assertNotNull($grant['last_ok']);
	}

	/**
	 * A mail arrived before the authorization (the server date of its arrival), but seen after: no event
	 *
	 * @return void
	 */
	public function testMailArrivedBeforeTheAuthorization()
	{
		$a = $this->ready();
		$this->append('INBOX', 'early');
		$this->grant($a);
		$this->db->query("UPDATE ".$this->db->prefix()."inbox_consumer SET date_creation = '".$this->db->idate(dol_now() + 120)."' WHERE fk_account = ".((int) $a->id));
		$this->sync($a);
		$this->assertSame(array(), $this->queue($a));
		$this->assertSame(array(), ZzDeliveryConsumer::$got);
	}

	/**
	 * Limited to some folders: only their new mails; never Junk nor Sent
	 *
	 * @return void
	 */
	public function testFoldersOfTheSubscriptionOnly()
	{
		$a = $this->ready();
		$this->grant($a, array('folders' => array($this->folder($a, 'INBOX.ZzActA'))));
		$this->append('INBOX', 'out');
		$this->append('INBOX.ZzActA', 'in');
		$this->append('INBOX.Junk', 'junk');
		$this->sync($a);
		$this->assertSame(array('in'.self::MARK), array_map(function ($r) {
			return (string) $r->message_id;
		}, $this->queue($a)));

		$this->grant($a, array('folders' => array()));
		$this->append('INBOX.Junk', 'junk2');
		$this->append('INBOX.Sent', 'sent2');
		$this->sync($a);
		$this->assertCount(1, $this->queue($a), 'the whole mailbox, junk and sent left out');
	}

	/**
	 * Test mode: the module receives the mail, its actions are only written in the journal, in its name
	 *
	 * @return void
	 */
	public function testTestModeOnlyWritesTheJournal()
	{
		$a = $this->ready();
		$this->grant($a, array('mode' => 'test'));
		$dst = $this->folder($a, 'INBOX.ZzActB');
		ZzDeliveryConsumer::$do = function (InboxConsumerEvent $e) use ($dst) {
			$ok = $e->act('move', array('folder' => $dst)) && $e->act('tag', array('add' => array('$label1'))) && $e->link('societe', 1) && $e->draft('<p>Merci</p>');
			return $ok ? 1 : -1;
		};
		$this->append('INBOX', 't1');
		$this->sync($a);
		$this->assertTrue(ZzDeliveryConsumer::$got[0]->test);
		$this->assertSame('done', (string) $this->queue($a)[0]->status);
		$this->assertCount(1, $this->onServer('INBOX', 't1'), 'not moved');
		$row = $this->row($a, 't1');
		$this->assertStringNotContainsString('$label1', (string) $row->keywords);
		$lines = $this->journal($a);
		$this->assertSame(array('move', 'tag', 'consumer_link', 'consumer_draft'), array_column($lines, 'action'));
		foreach ($lines as $l) {
			$this->assertSame(1, (int) $l->simulated);
			$this->assertSame(0, (int) $l->fk_user);
			$this->assertSame(self::CODE, (string) $l->consumer);
			$this->assertSame('ZzDeliver', (string) $l->user_name);
		}
		$this->assertSame('INBOX / ZzActB', (string) $lines[0]->folder_to);
		$link = $this->db->getRow("SELECT COUNT(rowid) AS n FROM ".$this->db->prefix()."inbox_link WHERE origin = '".self::CODE."'");
		$this->assertSame(0, (int) $link->n);
	}

	/**
	 * Active: reads the content (written once in the journal), tags, links, prepares a draft, moves; all in its name
	 *
	 * @return void
	 */
	public function testActiveModeActs()
	{
		$a = $this->ready();
		$this->grant($a);
		$dst = $this->folder($a, 'INBOX.ZzActB');
		$body = null;
		ZzDeliveryConsumer::$do = function (InboxConsumerEvent $e) use ($dst, &$body) {
			$body = $e->body();
			$e->attachments();
			$ok = $e->act('tag', array('add' => array('$label2'))) && $e->act('seen') && $e->link('societe', 1) && $e->draft('<p>Merci<script>alert(1)</script></p>')
				&& $e->act('move', array('folder' => $dst));
			return $ok ? 1 : -1;
		};
		$this->append('INBOX', 'a1');
		$this->sync($a);
		$this->assertSame('done', (string) $this->queue($a)[0]->status, (string) $this->queue($a)[0]->error);
		$this->assertStringContainsString('Corps a1', (string) $body['text']);
		$this->assertSame(array(), $this->onServer('INBOX', 'a1'), 'moved');
		$this->assertCount(1, $this->onServer('INBOX.ZzActB', 'a1'));
		$row = $this->row($a, 'a1');
		$this->assertSame($dst, (int) $row->fk_folder);
		$this->assertSame(1, (int) $row->seen);
		$this->assertStringContainsString('$label2', (string) $row->keywords);
		$this->assertSame(array('consumer_read', 'tag', 'consumer_seen', 'consumer_link', 'consumer_draft', 'move'), array_column($this->journal($a), 'action'));
		foreach ($this->journal($a) as $l) {
			$this->assertSame(0, (int) $l->simulated);
		}
		$link = $this->db->getRow("SELECT COUNT(rowid) AS n FROM ".$this->db->prefix()."inbox_link WHERE origin = '".self::CODE."' AND element_type = 'societe' AND fk_element = 1");
		$this->assertSame(1, (int) $link->n);
		// The draft: in Drafts on the server, an answer to the mail, the script gone (removed after: its Message-ID is not
		// one of the tests)
		$this->sync($a);
		$draft = $this->db->getRow("SELECT m.remote_id, f.path FROM ".$this->db->prefix()."inbox_mail as m INNER JOIN ".$this->db->prefix()."inbox_folder as f ON f.rowid = m.fk_folder"
			." WHERE m.fk_account = ".((int) $a->id)." AND f.role = 'drafts' AND m.in_reply_to = 'a1".self::MARK."'");
		$this->assertIsObject($draft);
		$error = '';
		$c = InboxConnectorFactory::forAccount($this->db, $a, $error);
		$raw = (string) $c->fetchRaw((string) $draft->path, (string) $draft->remote_id);
		$c->deleteMails((string) $draft->path, array((string) $draft->remote_id));
		$c->disconnect();
		$this->assertStringContainsString('Re: ZzAct a1', $raw);
		$this->assertStringContainsString('Merci', $raw);
		$this->assertStringNotContainsString('<script', $raw);
	}

	/**
	 * What was not ticked is refused, nothing done, nothing written but the reason
	 *
	 * @return void
	 */
	public function testActionNotTickedRefused()
	{
		$a = $this->ready();
		$this->grant($a, array('actions' => array('link')));
		$res = array();
		ZzDeliveryConsumer::$do = function (InboxConsumerEvent $e) use (&$res) {
			$res = array('body' => $e->body(), 'move' => $e->act('junk'), 'error' => $e->error, 'draft' => $e->draft('x'), 'delete' => $e->act('delete'));
			return 1;
		};
		$this->append('INBOX', 'r1');
		$this->sync($a);
		$this->assertNull($res['body']);
		$this->assertFalse($res['move']);
		$this->assertStringContainsString('not allowed', $res['error']);
		$this->assertFalse($res['draft']);
		$this->assertFalse($res['delete'], 'never a deletion');
		$this->assertCount(1, $this->onServer('INBOX', 'r1'));
		$this->assertSame(array(), $this->journal($a));
	}

	/**
	 * A module that fails never stops the synchronisation: its event is given again later, then given up after 5 tries,
	 * seen in the subscription; what it prints is dropped
	 *
	 * @return void
	 */
	public function testErrorsGivenAgainThenGivenUp()
	{
		$a = $this->ready();
		$this->grant($a);
		ZzDeliveryConsumer::$do = function (InboxConsumerEvent $e) {
			echo 'noise';
			throw new RuntimeException('boom');
		};
		$this->append('INBOX', 'e1');
		$this->expectOutputString('');
		$s = new InboxSync($this->db);
		$this->assertSame(1, $s->run($a, microtime(true) + 20, array('force' => true)), 'the synchronisation goes on');
		$q = $this->queue($a)[0];
		$this->assertSame('waiting', (string) $q->status);
		$this->assertSame(1, (int) $q->attempts);
		$this->assertGreaterThan(dol_now(), (int) $this->db->jdate($q->next_try));
		$this->assertStringContainsString('boom', (string) $q->error);
		$d = new InboxConsumerDelivery($this->db);
		for ($i = 0; $i < 4; $i++) {
			$this->db->query("UPDATE ".$this->db->prefix()."inbox_consumer_event SET next_try = '".$this->db->idate(dol_now() - 1)."' WHERE fk_account = ".((int) $a->id));
			$d->deliver($a, microtime(true) + 10);
		}
		$q = $this->queue($a)[0];
		$this->assertSame('failed', (string) $q->status);
		$this->assertSame(5, (int) $q->attempts);
		$g = (new InboxConsumer($this->db))->grants((int) $a->id)[self::CODE];
		$this->assertSame(5, $g['errors_in_row']);
		$this->assertStringContainsString('boom', $g['last_error']);
		$this->assertSame(array('waiting' => 0, 'failed' => 1), $d->counts((int) $a->id)[self::CODE]);
	}

	/**
	 * Paused: the queue goes on, nothing given until the resume; removed: its queue goes with it. Switched off on
	 * the instance: no event, the scheduled job empties the queue
	 *
	 * @return void
	 */
	public function testPauseRemovalAndSwitch()
	{
		global $conf;

		$a = $this->ready();
		$this->grant($a);
		$c = new InboxConsumer($this->db);
		$this->assertTrue($c->setPaused($this->admin, $a, self::CODE, true));
		$this->append('INBOX', 'p1');
		$this->sync($a);
		$this->assertSame('waiting', (string) $this->queue($a)[0]->status);
		$this->assertSame(array(), ZzDeliveryConsumer::$got);
		$this->assertTrue($c->setPaused($this->admin, $a, self::CODE, false));
		$this->sync($a);
		$this->assertSame('done', (string) $this->queue($a)[0]->status);
		$this->assertCount(1, ZzDeliveryConsumer::$got);

		$this->assertTrue($c->revoke($this->admin, $a, self::CODE));
		$this->assertSame(array(), $this->queue($a));

		$this->grant($a);
		$this->assertTrue($c->setPaused($this->admin, $a, self::CODE, true));
		$this->append('INBOX', 'p2');
		$this->sync($a);
		$this->assertCount(1, $this->queue($a));
		$conf->global->INBOX_CONSUMERS_ENABLED = 0;
		$this->append('INBOX', 'p3');
		$this->sync($a);
		$this->assertCount(1, $this->queue($a), 'off: no event');
		$d = new InboxConsumerDelivery($this->db);
		$this->assertSame(0, $d->cronRun());
		$this->assertSame(array(), $this->queue($a), 'off: the queue is emptied');
	}

	/**
	 * The scheduled job gives the events left, the module turned off keeps them waiting
	 *
	 * @return void
	 */
	public function testScheduledJobAndModuleTurnedOff()
	{
		$a = $this->ready();
		$this->grant($a);
		InboxConsumer::useDeclarations(array());
		$this->append('INBOX', 'c1');
		$this->sync($a);
		$this->assertSame(array(), $this->queue($a), 'module turned off: no event');

		InboxConsumer::useDeclarations(array(self::CODE => array('module' => 'zzdeliver', 'label' => 'ZzDeliver', 'class' => 'ZzDeliveryConsumer', 'file' => '/zzdeliver/x.php',
			'events' => array('mail.received'), 'actions' => array('read'))));
		$this->append('INBOX', 'c2');
		$d = new InboxConsumerDelivery($this->db);
		// Written, not given (as when the pass had no time left)
		$this->assertSame(1, $d->createReceived($a, array($this->syncAndRow($a, 'c2'))));
		InboxConsumer::useDeclarations(array());
		$this->assertSame(0, $d->cronRun());
		$this->assertSame('waiting', (string) $this->queue($a)[0]->status, 'module turned off: kept waiting');
		InboxConsumer::useDeclarations(array(self::CODE => array('module' => 'zzdeliver', 'label' => 'ZzDeliver', 'class' => 'ZzDeliveryConsumer', 'file' => '/zzdeliver/x.php',
			'events' => array('mail.received'), 'actions' => array('read'))));
		$this->assertSame(0, $d->cronRun());
		$this->assertSame('done', (string) $this->queue($a)[0]->status);
		$this->assertStringContainsString('1 given', $d->output);
	}

	/**
	 * 1.146.1: a module that kills the process (fatal error, memory, time limit) leaves its event « running »: counted
	 * as a try when taken, recovered as an error, given up after the last try instead of being taken again for ever
	 *
	 * @return void
	 */
	public function testStoppedDeliveryEndsGivenUp()
	{
		$a = $this->ready();
		$this->grant($a);
		$this->append('INBOX', 'f1');
		$this->sync($a);
		$q = $this->queue($a)[0];
		$this->assertSame('done', (string) $q->status);
		$this->assertSame(1, (int) $q->attempts, 'the try counted when taken');

		$p = $this->db->prefix();
		$stop = function ($attempts) use ($p, $a) {
			$this->db->query("UPDATE ".$p."inbox_consumer_event SET status = 'running', attempts = ".((int) $attempts).", date_done = NULL, next_try = '".$this->db->idate(dol_now() - 1)."'"
				." WHERE fk_account = ".((int) $a->id));
		};
		$d = new InboxConsumerDelivery($this->db);
		$stop(1);
		$this->assertSame(0, $d->cronRun());
		$q = $this->queue($a)[0];
		$this->assertSame('waiting', (string) $q->status);
		$this->assertSame(1, (int) $q->attempts);
		$this->assertGreaterThan(dol_now(), (int) $this->db->jdate($q->next_try), 'given again later, not at once');
		$this->assertStringContainsString('stopped', (string) $q->error);
		$stop(5);
		$this->assertSame(1, $d->recoverStale((int) $a->id));
		$this->assertSame('failed', (string) $this->queue($a)[0]->status);
		$this->assertStringContainsString('stopped', (new InboxConsumer($this->db))->grants((int) $a->id)[self::CODE]['last_error']);
	}

	/**
	 * 1.146.1: the events of a module turned off never take the places of the others (more than a batch waiting)
	 *
	 * @return void
	 */
	public function testModuleTurnedOffDoesNotBlockTheOthers()
	{
		$other = array('module' => 'zzother', 'label' => 'ZzOther', 'class' => 'ZzDeliveryConsumer', 'file' => '/zzother/x.php', 'events' => array('mail.received'), 'actions' => array('read'));
		$mine = array('module' => 'zzdeliver', 'label' => 'ZzDeliver', 'class' => 'ZzDeliveryConsumer', 'file' => '/zzdeliver/x.php', 'events' => array('mail.received'), 'actions' => array('read'));
		InboxConsumer::useDeclarations(array(self::CODE => $mine, 'zzother' => $other));
		$a = $this->ready();
		$this->grant($a, array('actions' => array('read')));
		$c = new InboxConsumer($this->db);
		$this->assertTrue($c->save($this->admin, $a, 'zzother', array('events' => array('mail.received'), 'actions' => array(), 'mode' => 'active')), $c->error);
		$otherId = (int) $c->grants((int) $a->id)['zzother']['id'];
		$p = $this->db->prefix();
		$now = $this->db->idate(dol_now() - 60);
		for ($i = 0; $i < InboxConsumerDelivery::BATCH + 10; $i++) {
			$this->db->query("INSERT INTO ".$p."inbox_consumer_event (entity, fk_consumer, fk_account, event, message_id, status, attempts, next_try, datec)"
				." VALUES (1, ".$otherId.", ".((int) $a->id).", 'mail.received', 'zzother-".$i.self::MARK."', 'waiting', 0, '".$now."', '".$now."')");
		}
		InboxConsumer::useDeclarations(array(self::CODE => $mine));
		$this->append('INBOX', 'o1');
		$this->sync($a);
		$mineRow = $this->db->getRow("SELECT e.status FROM ".$p."inbox_consumer_event as e INNER JOIN ".$p."inbox_consumer as c ON c.rowid = e.fk_consumer"
			." WHERE c.code = '".self::CODE."' AND e.fk_account = ".((int) $a->id));
		$this->assertSame('done', (string) $mineRow->status);
		$waiting = $this->db->getRow("SELECT COUNT(rowid) AS n FROM ".$p."inbox_consumer_event WHERE fk_consumer = ".$otherId." AND status = 'waiting'");
		$this->assertSame(InboxConsumerDelivery::BATCH + 10, (int) $waiting->n, 'the module turned off keeps its events waiting');
	}

	/**
	 * 1.146.1: a synchronisation run inside handle() (a move on a server without UIDPLUS) gives nothing: no delivery
	 * inside a delivery; the action of the module after it is still written in its name, never offered to the undo of
	 * the page
	 *
	 * @return void
	 */
	public function testNoDeliveryInsideADelivery()
	{
		$a = $this->ready();
		$this->grant($a);
		$inbox = $this->folder($a, 'INBOX');
		$depth = 0;
		$max = 0;
		ZzDeliveryConsumer::$do = function (InboxConsumerEvent $e) use ($a, $inbox, &$depth, &$max) {
			$depth++;
			$max = max($max, $depth);
			(new InboxSync($this->db))->run($a, microtime(true) + 10, array('folder_id' => $inbox, 'force' => true));
			$ok = $e->act('tag', array('add' => array('$label3')));
			$depth--;
			return $ok ? 1 : -1;
		};
		$this->append('INBOX', 'n1');
		$this->append('INBOX', 'n2');
		InboxActionLog::$written = array();
		$this->sync($a);
		$this->assertSame(1, $max, 'never a handle() inside a handle()');
		$this->assertCount(2, ZzDeliveryConsumer::$got);
		$this->assertSame(array('done', 'done'), array_map('strval', array_column($this->queue($a), 'status')));
		$tags = array_values(array_filter($this->journal($a), function ($l) {
			return (string) $l->action === 'tag';
		}));
		$this->assertCount(2, $tags);
		foreach ($tags as $l) {
			$this->assertSame(self::CODE, (string) $l->consumer, 'written in the name of the module');
		}
		$this->assertSame(array(), InboxActionLog::$written, 'nothing of a module offered to the undo of the page');
		$this->assertNull(InboxActionLog::$consumer);
	}

	/**
	 * Row of a mail once indexed, without giving anything (the switch off during the pass)
	 *
	 * @param InboxAccount $a  Mailbox
	 * @param string       $id Mail
	 * @return int
	 */
	protected function syncAndRow(InboxAccount $a, $id)
	{
		global $conf;

		$conf->global->INBOX_CONSUMERS_ENABLED = 0;
		$this->sync($a);
		$conf->global->INBOX_CONSUMERS_ENABLED = 1;

		return (int) $this->row($a, $id)->rowid;
	}
}
