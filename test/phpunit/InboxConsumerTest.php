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
 * \file    inbox/test/phpunit/InboxConsumerTest.php
 * \ingroup inbox
 * \brief   1.112.0: modules subscribed to the mailboxes (declarations, switch of the instance, authorization limited
 *          to what is declared, folders of the mailbox only, new requests, protection with the content, journal)
 */

require_once __DIR__.'/InboxTestCase.php';
dol_include_once('/inbox/class/inboxconsumer.class.php');
dol_include_once('/inbox/class/inboxconsumerinterface.class.php');

/**
 * The mailboxes of the tests are removed by InboxTestCase::cleanup(), their subscriptions with them
 */
class InboxConsumerTest extends InboxTestCase
{
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
		InboxConsumer::useDeclarations(array('zztickets' => self::declaration()));
	}

	/**
	 * @return void
	 */
	protected function tearDown(): void
	{
		global $conf;

		$conf->global->INBOX_CONSUMERS_ENABLED = $this->enabledBefore;
		InboxConsumer::useDeclarations(null);
		parent::tearDown();
	}

	/**
	 * Declaration of a module of the tests
	 *
	 * @param array<string,mixed> $set Values to change
	 * @return array<string,mixed>
	 */
	protected static function declaration(array $set = array())
	{
		return $set + array('module' => 'zztickets', 'label' => 'ZzTickets', 'purpose' => 'Creates tickets', 'class' => 'ZzTicketsConsumer',
			'file' => '/zztickets/class/zzticketsconsumer.class.php', 'events' => array('mail.received'), 'actions' => array('read', 'move', 'link'));
	}

	/**
	 * A folder of a mailbox of the tests
	 *
	 * @param int    $accountId Mailbox
	 * @param string $path      Path
	 * @return int
	 */
	protected function folder($accountId, $path)
	{
		$p = $this->db->prefix();
		$this->assertTrue((bool) $this->db->query("INSERT INTO ".$p."inbox_folder (fk_account, path, name) VALUES (".((int) $accountId).", '".$this->db->escape($path)."', '".$this->db->escape($path)."')"));

		return (int) $this->db->last_insert_id($p.'inbox_folder');
	}

	/**
	 * Lines of the journal of a mailbox for an action
	 *
	 * @param int    $accountId Mailbox
	 * @param string $action    Action
	 * @return int
	 */
	protected function logged($accountId, $action)
	{
		$row = $this->db->getRow("SELECT COUNT(rowid) as n FROM ".$this->db->prefix()."inbox_account_log WHERE fk_account = ".((int) $accountId)." AND action = '".$this->db->escape($action)."'");

		return is_object($row) ? (int) $row->n : 0;
	}

	/**
	 * @return void
	 */
	public function testNormalize()
	{
		$d = InboxConsumer::normalize(array(
			'zzok' => self::declaration(array('events' => array('mail.received', 'mail.unknown'), 'actions' => array('read', 'send', 'destroy', 'tag'))),
			'Bad-Code' => self::declaration(),
			'zznoclass' => self::declaration(array('class' => '')),
			'zzbadclass' => self::declaration(array('class' => 'A;B')),
			'zznoevent' => self::declaration(array('events' => array('mail.unknown'))),
			'zznomodule' => self::declaration(array('module' => '')),
		));
		$this->assertSame(array('zzok'), array_keys($d));
		$this->assertSame(array('mail.received'), $d['zzok']['events']);
		// « send » only comes with the guards against loops; never an action outside the list
		$this->assertSame(array('read', 'tag'), $d['zzok']['actions']);
	}

	/**
	 * @return void
	 */
	public function testOffByDefaultRefusesEverything()
	{
		global $conf;

		$box = $this->box(true);
		$conf->global->INBOX_CONSUMERS_ENABLED = 0;
		$c = new InboxConsumer($this->db);
		$this->assertFalse(InboxConsumer::enabled());
		$this->assertSame('InboxConsumersOff', InboxConsumer::blockedReason($box));
		$this->assertFalse($c->save($this->admin, $box, 'zztickets', array('events' => array('mail.received'))));
		$this->assertSame('InboxConsumersOff', $c->error);
		$this->assertSame(array(), $c->grants((int) $box->id));
	}

	/**
	 * @return void
	 */
	public function testGrantOnlyWhatIsDeclared()
	{
		$box = $this->box(true);
		$c = new InboxConsumer($this->db);
		$this->assertFalse($c->save($this->admin, $box, 'zzunknown', array('events' => array('mail.received'))));
		$this->assertSame('InboxConsumerUnknown', $c->error);
		$this->assertFalse($c->save($this->admin, $box, 'zztickets', array('events' => array())));
		$this->assertSame('InboxConsumerNoEvent', $c->error);

		$this->assertTrue($c->save($this->admin, $box, 'zztickets', array('events' => array('mail.received', 'mail.other'), 'actions' => array('read', 'move', 'send', 'tag'), 'mode' => 'bogus')));
		$g = $c->grants((int) $box->id);
		$this->assertSame(array('zztickets'), array_keys($g));
		$this->assertSame(array('mail.received'), $g['zztickets']['events']);
		$this->assertSame(array('read', 'move'), $g['zztickets']['actions']);
		$this->assertSame(array(), $g['zztickets']['folders']);
		// Test mode when nothing valid is asked, never active by surprise
		$this->assertSame(InboxConsumer::MODE_TEST, $g['zztickets']['mode']);
		$this->assertFalse($g['zztickets']['paused']);
		$this->assertSame(1, $this->logged((int) $box->id, InboxConsumer::LOG_GRANT));

		// The same values again: nothing written in the journal
		$this->assertTrue($c->save($this->admin, $box, 'zztickets', array('events' => array('mail.received'), 'actions' => array('read', 'move'), 'mode' => 'test')));
		$this->assertSame(0, $this->logged((int) $box->id, InboxConsumer::LOG_CHANGE));
		$this->assertTrue($c->save($this->admin, $box, 'zztickets', array('events' => array('mail.received'), 'actions' => array('link'), 'mode' => 'active')));
		$g = $c->grants((int) $box->id);
		$this->assertSame(array('link'), $g['zztickets']['actions']);
		$this->assertSame(InboxConsumer::MODE_ACTIVE, $g['zztickets']['mode']);
		$this->assertSame(1, $this->logged((int) $box->id, InboxConsumer::LOG_CHANGE));
	}

	/**
	 * A module allowed on a personal mailbox by someone else than its owner: the owner is told (bell), as for an access
	 * given to oneself; not when he sets his own mailbox, not when nothing more is given
	 *
	 * @return void
	 */
	public function testOwnerToldWhenAModuleGetsMore()
	{
		$owner = $this->user('consowner');
		$box = $this->box(true, array('type' => InboxAccount::TYPE_PERSONAL, 'fk_user_owner' => (int) $owner->id));
		$p = $this->db->prefix();
		$told = function () use ($owner, $p) {
			return (int) $this->db->getRow("SELECT COUNT(*) AS n FROM ".$p."inbox_notification WHERE fk_user = ".((int) $owner->id)." AND type = 'consumer_grant'")->n;
		};
		$c = new InboxConsumer($this->db);
		$this->assertTrue($c->save($this->admin, $box, 'zztickets', array('events' => array('mail.received'), 'actions' => array('link'))));
		$this->assertSame(1, $told(), 'allowed by an administrator: the owner is told');
		$row = $this->db->getRow("SELECT payload, url, fk_user_actor FROM ".$p."inbox_notification WHERE fk_user = ".((int) $owner->id)." AND type = 'consumer_grant'");
		$this->assertSame((int) $this->admin->id, (int) $row->fk_user_actor);
		$this->assertStringContainsString('/consumers', (string) $row->url);
		$this->assertSame('ZzTickets', json_decode((string) $row->payload, true)['detail']);

		$this->db->query("DELETE FROM ".$p."inbox_notification WHERE fk_user = ".((int) $owner->id));
		$this->assertTrue($c->save($this->admin, $box, 'zztickets', array('events' => array('mail.received'), 'actions' => array())));
		$this->assertSame(0, $told(), 'less given: nothing to tell');
		$this->assertTrue($c->save($this->admin, $box, 'zztickets', array('events' => array('mail.received'), 'actions' => array('read'))));
		$this->assertSame(1, $told(), 'reading given: told');

		$this->db->query("DELETE FROM ".$p."inbox_notification WHERE fk_user = ".((int) $owner->id));
		$this->assertTrue($c->save($owner, $box, 'zztickets', array('events' => array('mail.received'), 'actions' => array('read', 'move'))));
		$this->assertSame(0, $told(), 'the owner sets his own mailbox: not told');
	}

	/**
	 * @return void
	 */
	public function testFoldersOfTheMailboxOnly()
	{
		$box = $this->box(true);
		$other = $this->box(true, array('email' => 'other@inbox-test.invalid'));
		$mine = $this->folder((int) $box->id, 'INBOX.Tickets');
		$foreign = $this->folder((int) $other->id, 'INBOX.Secret');
		$c = new InboxConsumer($this->db);
		$this->assertFalse($c->save($this->admin, $box, 'zztickets', array('events' => array('mail.received'), 'folders' => array($foreign))));
		$this->assertSame('InboxConsumerNoFolder', $c->error);
		$this->assertTrue($c->save($this->admin, $box, 'zztickets', array('events' => array('mail.received'), 'folders' => array($foreign, $mine, $mine))));
		$g = $c->grants((int) $box->id);
		$this->assertSame(array($mine), $g['zztickets']['folders']);
	}

	/**
	 * @return void
	 */
	public function testNewRequestIsPendingNeverGranted()
	{
		$box = $this->box(true);
		$c = new InboxConsumer($this->db);
		$this->assertTrue($c->save($this->admin, $box, 'zztickets', array('events' => array('mail.received'), 'actions' => array('read'))));
		$declared = InboxConsumer::declared();
		$g = $c->grants((int) $box->id);
		// Not ticked on purpose: not a new request
		$this->assertSame(array('events' => array(), 'actions' => array()), InboxConsumer::pending($declared['zztickets'], $g['zztickets']));

		// A new version of the module asks more
		InboxConsumer::useDeclarations(array('zztickets' => self::declaration(array('actions' => array('read', 'move', 'link', 'tag')))));
		$declared = InboxConsumer::declared();
		$g = $c->grants((int) $box->id);
		$this->assertSame(array('tag'), InboxConsumer::pending($declared['zztickets'], $g['zztickets'])['actions']);
		$this->assertSame(array('read'), $g['zztickets']['actions']);
		// Decided again: the request is no longer new
		$this->assertTrue($c->save($this->admin, $box, 'zztickets', array('events' => array('mail.received'), 'actions' => array('read'))));
		$g = $c->grants((int) $box->id);
		$this->assertSame(array(), InboxConsumer::pending($declared['zztickets'], $g['zztickets'])['actions']);
	}

	/**
	 * @return void
	 */
	public function testPauseResumeRevokeAndCounts()
	{
		$box = $this->box(true);
		$c = new InboxConsumer($this->db);
		$this->assertFalse($c->setPaused($this->admin, $box, 'zztickets', true));
		$this->assertSame('InboxConsumerNotGranted', $c->error);
		$this->assertTrue($c->save($this->admin, $box, 'zztickets', array('events' => array('mail.received'), 'mode' => 'active')));
		$this->assertTrue($c->setPaused($this->admin, $box, 'zztickets', true));
		$g = $c->grants((int) $box->id)['zztickets'];
		$this->assertTrue($g['paused']);
		$this->assertSame(InboxConsumer::PAUSE_MANAGER, $g['pause_reason']);
		// Paused, the mode is kept: it runs again as it was set
		$this->assertSame(InboxConsumer::MODE_ACTIVE, $g['mode']);
		$counts = $c->counts();
		$this->assertSame(1, $counts['zztickets']['paused']);
		$this->assertTrue($c->setPaused($this->admin, $box, 'zztickets', false));
		$this->assertFalse($c->grants((int) $box->id)['zztickets']['paused']);
		$this->assertSame(1, $this->logged((int) $box->id, InboxConsumer::LOG_PAUSE));
		$this->assertSame(1, $this->logged((int) $box->id, InboxConsumer::LOG_RESUME));

		$this->assertTrue($c->revoke($this->admin, $box, 'zztickets'));
		$this->assertSame(array(), $c->grants((int) $box->id));
		$this->assertSame(1, $this->logged((int) $box->id, InboxConsumer::LOG_REVOKE));
		// Nothing to remove: no error, nothing written
		$this->assertTrue($c->revoke($this->admin, $box, 'zztickets'));
		$this->assertSame(1, $this->logged((int) $box->id, InboxConsumer::LOG_REVOKE));
	}

	/**
	 * @return void
	 */
	public function testProtectedWithContent()
	{
		$box = $this->box(true);
		$c = new InboxConsumer($this->db);
		$this->assertTrue($c->save($this->admin, $box, 'zztickets', array('events' => array('mail.received'), 'mode' => 'active')));

		// Protected with its content: every subscription paused, written once in the journal
		$this->assertTrue($c->suspendForProtection($this->admin, (int) $box->id));
		$g = $c->grants((int) $box->id)['zztickets'];
		$this->assertTrue($g['paused']);
		$this->assertSame(InboxConsumer::PAUSE_PROTECTED, $g['pause_reason']);
		$this->assertTrue($c->suspendForProtection($this->admin, (int) $box->id));
		$this->assertSame(1, $this->logged((int) $box->id, InboxConsumer::LOG_SUSPEND));

		$box->protected = 1;
		$box->protect_level = InboxAccount::PROTECT_CONTENT;
		$this->assertSame('InboxConsumersLive', InboxConsumer::blockedReason($box));
		$this->assertFalse($c->setPaused($this->admin, $box, 'zztickets', false));
		$this->assertSame('InboxConsumersLive', $c->error);
		$this->assertFalse($c->save($this->admin, $box, 'zztickets', array('events' => array('mail.received'))));

		// Protected with its password only: the content is in the index, a module may subscribe
		$box->protect_level = InboxAccount::PROTECT_PASSWORD;
		$this->assertSame('', InboxConsumer::blockedReason($box));
		$this->assertTrue($c->setPaused($this->admin, $box, 'zztickets', false));
	}
}
