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
 * \file    inbox/test/phpunit/InboxBlockedSenderTest.php
 * \ingroup inbox
 * \brief   1.85.0: blocked senders (matching, levels of a sender, rights, Sieve block, synchronisation)
 */

// From the command line only, never from the web: test/ stays in the Git branches
if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

require_once __DIR__.'/InboxImapTestCase.php';
dol_include_once('/inbox/class/inboxblockedsender.class.php');
dol_include_once('/inbox/class/inboxrules.class.php');
dol_include_once('/inbox/class/inboxtrusteddomain.class.php');
dol_include_once('/inbox/class/inboxsync.class.php');

/**
 * The entries of the tests are on *.zzblock-test.invalid (removed by InboxTestCase::cleanup())
 */
class InboxBlockedSenderTest extends InboxImapTestCase
{
	/** @var InboxAccount|null Mailbox whose scripts are removed after the test */
	protected $sieveBox = null;

	/** @var array<string,mixed> Settings before the test */
	protected $saved = array();

	/**
	 * @return void
	 */
	protected function setUp(): void
	{
		global $conf;

		parent::setUp();
		InboxBlockedSender::clearCache();
		foreach (array('INBOX_BLOCKED_SENDERS', 'INBOX_DOMAIN_MATCH') as $k) {
			$this->saved[$k] = $conf->global->$k ?? null;
		}
		$conf->global->INBOX_BLOCKED_SENDERS = 1;
		$this->manager($this->admin, true);
	}

	/**
	 * @return void
	 */
	protected function tearDown(): void
	{
		global $conf;

		foreach ($this->saved as $k => $v) {
			if ($v === null) {
				unset($conf->global->$k);
			} else {
				$conf->global->$k = $v;
			}
		}
		if ($this->sieveBox) {
			$error = '';
			$c = InboxConnectorFactory::forAccount($this->db, $this->sieveBox, $error);
			$s = $c ? $c->sieveSession($error) : null;
			if ($s) {
				$s->setActive('');
				foreach ((array) $s->listScripts() as $x) {
					$s->deleteScript($x['name']);
				}
				$s->close();
			}
		}
		InboxBlockedSender::clearCache();
		parent::tearDown();
	}

	/**
	 * Give or take the right « Manage the blocked senders » (in memory)
	 *
	 * @param User $u  User
	 * @param bool $on Right
	 * @return void
	 */
	protected function manager(User $u, $on)
	{
		if (!isset($u->rights->inbox)) {
			$u->rights->inbox = new stdClass();
		}
		if (!isset($u->rights->inbox->blockedsender)) {
			$u->rights->inbox->blockedsender = new stdClass();
		}
		$u->rights->inbox->blockedsender->write = $on ? 1 : 0;
	}

	/**
	 * @return void
	 */
	public function testNormalizeAndMatch()
	{
		$this->assertSame('john@example.com', InboxBlockedSender::normalizeAddress('  <John@EXAMPLE.Com> '));
		$this->assertSame('', InboxBlockedSender::normalizeAddress('nope'));
		$this->assertSame('', InboxBlockedSender::normalizeAddress('a b@example.com'));
		$this->assertTrue(InboxBlockedSender::under('mail.spam.example', 'spam.example'));
		$this->assertFalse(InboxBlockedSender::under('notspam.example', 'spam.example'), 'never « contains »');

		$b = new InboxBlockedSender($this->db);
		$this->assertGreaterThan(0, $b->create($this->admin, 'address', 'Prospect@Unknown.zzblock-test.invalid'), $b->error);
		$this->assertGreaterThan(0, $b->create($this->admin, 'domain', 'spam.zzblock-test.invalid'), $b->error);
		$this->assertGreaterThan(0, $b->isBlocked('prospect@unknown.zzblock-test.invalid'));
		$this->assertSame(0, $b->isBlocked('other@unknown.zzblock-test.invalid'));
		$this->assertGreaterThan(0, $b->isBlocked('x@spam.zzblock-test.invalid'));
		$this->assertGreaterThan(0, $b->isBlocked('x@mail.spam.zzblock-test.invalid'), 'a subdomain');
		$this->assertSame(0, $b->isBlocked('x@notspam.zzblock-test.invalid'));
		$this->assertSame(0, $b->isBlocked('x@spam.zzblock-test.invalid.evil.com'), 'a false suffix');
		// Blocked again: the same entry, never twice
		$this->assertSame($b->isBlocked('prospect@unknown.zzblock-test.invalid'), $b->create($this->admin, 'address', 'prospect@unknown.zzblock-test.invalid'));

		// Turned off: nothing is blocked, the list is kept
		$GLOBALS['conf']->global->INBOX_BLOCKED_SENDERS = 0;
		$this->assertSame(0, $b->isBlocked('prospect@unknown.zzblock-test.invalid'));
		$this->assertSame(2, count($b->fetchAll(0, array('search' => 'zzblock-test'))));
		$this->assertSame(2, $b->count(array('search' => 'zzblock-test')));
		$this->assertSame(1, $b->count(array('search' => 'zzblock-test', 'type' => 'domain')));
		$GLOBALS['conf']->global->INBOX_BLOCKED_SENDERS = 1;
	}

	/**
	 * @return void
	 */
	public function testDomainRefusals()
	{
		$b = new InboxBlockedSender($this->db);
		$this->assertSame('InboxBlockedErrorOpenService', $b->domainRefusal('gmail.com'));
		$this->assertSame('InboxBlockedErrorOpenService', $b->domainRefusal('sendinblue.com'));
		$this->assertSame('InboxBlockedErrorPublicSuffix', $b->domainRefusal('co.uk'));
		$this->assertSame('', $b->domainRefusal('kloovis.zzblock-test.invalid'));
		$this->assertLessThan(0, $b->create($this->admin, 'domain', 'gmail.com'));
		$this->assertLessThan(0, $b->create($this->admin, 'domain', 'invalid'), 'one label is not a domain');
	}

	/**
	 * @return void
	 */
	public function testLevels()
	{
		$p = $this->db->prefix();
		$b = new InboxBlockedSender($this->db);
		$this->assertSame('unknown', $b->levelOf('address', 'nobody@unknown.zzblock-test.invalid')['level']);

		// A user of Dolibarr: protected, inactive too
		$u = $this->user('blockuser');
		$this->db->query("UPDATE ".$p."user SET email = 'colleague@staff.zzblock-test.invalid', statut = 0 WHERE rowid = ".((int) $u->id));
		$this->assertSame('protected', $b->levelOf('address', 'colleague@staff.zzblock-test.invalid')['level']);
		$this->assertSame('protected', $b->levelOf('domain', 'staff.zzblock-test.invalid')['level'], 'the domain of a user');

		// Our mailbox and its domain
		$this->box(true);
		InboxTrustedDomain::clearCache();
		$this->assertSame('protected', $b->levelOf('address', self::BOX)['level']);
		$this->assertSame('protected', $b->levelOf('address', 'someone@inbox-test.invalid')['level']);

		// A recognized domain
		$this->db->query("INSERT INTO ".$p."inbox_trusted_domain (entity, domain, label, subdomains, date_creation) VALUES (1, 'carrier.zzblock-test.invalid', 'ZzTrusted carrier', 1, '".$this->db->idate(dol_now())."')");
		InboxTrustedDomain::clearCache();
		$this->assertSame('protected', $b->levelOf('address', 'noreply@notif.carrier.zzblock-test.invalid')['level']);
		$this->assertSame('protected', $b->levelOf('domain', 'zzblock-test.invalid')['level'], 'a parent of a recognized domain');

		// A thirdparty (a prospect, inactive) and a contact; the domain of a thirdparty
		$this->db->query("INSERT INTO ".$p."societe (entity, nom, email, client, status, datec) VALUES (1, 'inboxtest_blockcust', 'achat@cust.zzblock-test.invalid, compta@cust.zzblock-test.invalid', 2, 0, '".$this->db->idate(dol_now())."')");
		$socid = (int) $this->db->last_insert_id($p.'societe');
		$this->db->query("INSERT INTO ".$p."socpeople (entity, fk_soc, lastname, firstname, email, statut, datec, fk_user_creat) VALUES (1, ".$socid.", 'inboxtest_Martin', 'Paul', 'paul@other.zzblock-test.invalid', 0, '".$this->db->idate(dol_now())."', ".((int) $this->admin->id).")");
		$lv = $b->levelOf('address', 'compta@cust.zzblock-test.invalid');
		$this->assertSame('known', $lv['level'], 'the second address of the field');
		$this->assertStringContainsString('inboxtest_blockcust', $lv['reason']);
		$lv = $b->levelOf('address', 'paul@other.zzblock-test.invalid');
		$this->assertSame('known', $lv['level']);
		$this->assertStringContainsString('inboxtest_Martin', $lv['reason']);
		$lv = $b->levelOf('address', 'new.buyer@cust.zzblock-test.invalid');
		$this->assertSame('known', $lv['level'], 'a new person of a known thirdparty');
		$this->assertSame('known', $b->levelOf('domain', 'cust.zzblock-test.invalid')['level']);
	}

	/**
	 * @return void
	 */
	public function testRights()
	{
		$p = $this->db->prefix();
		$a = $this->box(true, array('status' => InboxAccount::STATUS_ACTIVE));
		$bob = $this->user('blockbob');
		$this->manager($bob, false);
		$b = new InboxBlockedSender($this->db);

		// Without access to the mailbox: nothing
		$this->assertSame(-2, $b->create($bob, 'address', 'a@unknown.zzblock-test.invalid', (int) $a->id));
		// Level « manage »: an unknown address, never a domain nor a known sender
		(new InboxAccess($this->db))->grant($this->admin, (int) $a->id, (int) $bob->id, 0, InboxAccess::LEVEL_MANAGE);
		InboxAccess::clearCache();
		$id = $b->create($bob, 'address', 'a@unknown.zzblock-test.invalid', (int) $a->id);
		$this->assertGreaterThan(0, $id, $b->error);
		$this->assertSame(-2, $b->create($bob, 'domain', 'unknown.zzblock-test.invalid', (int) $a->id));
		$this->db->query("INSERT INTO ".$p."societe (entity, nom, email, client, status, datec) VALUES (1, 'inboxtest_blockclient', 'boss@client.zzblock-test.invalid', 1, 1, '".$this->db->idate(dol_now())."')");
		$this->assertSame(-2, $b->create($bob, 'address', 'boss@client.zzblock-test.invalid', (int) $a->id), 'a known sender needs the right');
		// From the settings (no mailbox): the right only
		$this->assertSame(-2, $b->create($bob, 'address', 'b@unknown.zzblock-test.invalid', 0));

		// The right: a known sender after the address typed again
		$this->assertSame(-3, $b->create($this->admin, 'address', 'boss@client.zzblock-test.invalid'));
		$this->assertStringContainsString('inboxtest_blockclient', $b->errorParam);
		$this->assertGreaterThan(0, $known = $b->create($this->admin, 'address', 'boss@client.zzblock-test.invalid', 0, ' BOSS@client.zzblock-test.invalid '), $b->error);
		$this->assertStringContainsString('inboxtest_blockclient', (string) $b->fetch($known)['known_reason']);
		// Never a protected sender, even with the right
		$this->assertSame(-2, $b->create($this->admin, 'address', self::BOX));

		// Unblock: its author or a manager
		$this->assertSame(-1, $b->delete($bob, $known));
		$this->assertSame(1, $b->delete($bob, $id));
		$this->assertSame(1, $b->delete($this->admin, $known));
		$this->assertTrue(is_object($this->db->getRow("SELECT rowid FROM ".$p."inbox_action_log WHERE action = 'block' AND subject = 'a@unknown.zzblock-test.invalid'")), 'journal');
		$this->db->query("DELETE FROM ".$p."inbox_action_log WHERE subject LIKE '%zzblock-test.invalid'");

		// What the dialog offers
		$o = $b->offer($bob, 'c@unknown.zzblock-test.invalid', (int) $a->id);
		$this->assertTrue($o['can_address']);
		$this->assertFalse($o['can_domain']);
		$this->assertSame('unknown', $o['level']);
		// From a mail whose sender is not proven: the managers of the list only (audit INBX-076)
		$b->unproven = true;
		$this->assertFalse($b->offer($bob, 'd@unknown.zzblock-test.invalid', (int) $a->id)['can_address']);
		$this->assertSame(-2, $b->create($bob, 'address', 'd@unknown.zzblock-test.invalid', (int) $a->id));
		$b->unproven = false;
	}

	/**
	 * @return void
	 */
	public function testSieveBlock()
	{
		$blocked = array('addresses' => array('a@x.zzblock-test.invalid'), 'domains' => array('spam.zzblock-test.invalid'), 'hash' => 'abc123', 'junk' => 'INBOX.Junk');
		$script = InboxRules::script(array(), InboxRules::defaultAway(), array(), 'Trash', array('fileinto', 'imap4flags'), '', array('saved' => 1, 'by' => 'x'), $blocked);
		$this->assertStringContainsString(InboxRules::BLOCKED_MARK.' abc123', $script);
		$this->assertStringContainsString('address :all :is "from" "a@x.zzblock-test.invalid"', $script);
		$this->assertStringContainsString('address :domain :matches "from" "*.spam.zzblock-test.invalid"', $script);
		$this->assertStringContainsString('fileinto :flags "$Junk" "INBOX.Junk";', $script);
		$this->assertMatchesRegularExpression('/require \[[^\]]*"fileinto"/', $script);
		$model = InboxRules::read($script);
		$this->assertSame('abc123', $model['blocked']);
		$this->assertFalse($model['edited']);
		$this->assertSame(array(), $model['rules'], 'never a rule');
		// Before the out of office and the rules
		$away = array_merge(InboxRules::defaultAway(), array('on' => 1, 'subject' => 'Absent', 'text' => 'Absent', 'days' => 1, 'addr' => array()));
		$script = InboxRules::script(array(), $away, array(), 'Trash', array('fileinto', 'vacation'), '', array(), $blocked);
		$this->assertLessThan(strpos($script, '# Out of office'), strpos($script, InboxRules::BLOCKED_MARK));
		// Nothing blocked: no block
		$script = InboxRules::script(array(), InboxRules::defaultAway(), array(), 'Trash', array('fileinto'), '', array(), array('addresses' => array(), 'domains' => array(), 'hash' => 'x', 'junk' => 'Junk'));
		$this->assertStringNotContainsString(InboxRules::BLOCKED_MARK, $script);
		$this->assertSame('', InboxRules::read($script)['blocked']);
	}

	/**
	 * The block written on the server by the scheduled job, the new mails of a blocked sender put away by the
	 * synchronisation (mailbox whose block is not written)
	 *
	 * @return void
	 */
	public function testServerAndSync()
	{
		$a = $this->ready();
		$this->sieveBox = $a;
		$p = $this->db->prefix();
		$junk = $this->db->getRow("SELECT path FROM ".$p."inbox_folder WHERE fk_account = ".((int) $a->id)." AND role = 'junk' AND status = 1");
		if (!is_object($junk)) {
			$this->markTestSkipped('the test mailbox has no junk folder');
		}
		$b = new InboxBlockedSender($this->db);
		$this->assertGreaterThan(0, $b->create($this->admin, 'address', 'client@example.invalid'), $b->error);

		// Scheduled job: the block written, the state ok
		$rules = new InboxRules($this->db);
		$this->assertSame('ok', $rules->applyBlocked($a), $rules->error);
		$text = (string) $rules->scriptText($a, 'inbox');
		$this->assertStringContainsString('"client@example.invalid"', $text);
		$this->assertSame('ok', $rules->load($a)['state'], 'the rules editor sees a script of inbox, not changed');
		$this->assertSame(array(), $rules->load($a)['rules']);
		$this->assertSame('ok', $rules->applyBlocked($a), 'nothing to write again');
		$b->applyAll(microtime(true) + 20);
		$st = $b->states();
		$this->assertSame('ok', $st[array_search((int) $a->id, array_column($st, 'id'))]['state']);

		// Synchronisation: a new mail of the inbox from the blocked sender goes to the junk (the test appends it
		// with IMAP, Sieve does not run)
		$this->append('INBOX', 'blk1');
		$sync = new InboxSync($this->db);
		$sync->run($a, microtime(true) + 20, array('force' => true));
		$this->assertSame(array(), $this->onServer('INBOX', 'blk1'), 'left the inbox');
		$this->assertNotEmpty($this->onServer((string) $junk->path, 'blk1'), 'in the junk');
		$this->assertTrue(is_object($this->db->getRow("SELECT rowid FROM ".$p."inbox_action_log WHERE action = 'blocked' AND fk_account = ".((int) $a->id))), 'journal');
	}

	/**
	 * Nightly check (1.87.0): became known = told and still blocked, kept on purpose = no longer told; became
	 * protected = unblocked
	 *
	 * @return void
	 */
	public function testNightlyCheck()
	{
		$p = $this->db->prefix();
		$b = new InboxBlockedSender($this->db);
		$id = $b->create($this->admin, 'address', 'buyer@newcust.zzblock-test.invalid');
		$this->assertGreaterThan(0, $id, $b->error);
		$prot = $b->create($this->admin, 'domain', 'carrier2.zzblock-test.invalid');
		$this->assertGreaterThan(0, $prot, $b->error);

		// Nothing changed: nothing said
		$this->assertSame(0, $b->cronCheck(), $b->output);
		$this->assertSame(0, (int) $b->fetch($id)['known_since']);

		// A new customer with this address
		$this->db->query("INSERT INTO ".$p."societe (entity, nom, email, client, status, datec) VALUES (1, 'inboxtest_newcust', 'buyer@newcust.zzblock-test.invalid', 1, 1, '".$this->db->idate(dol_now())."')");
		// The domain becomes a recognized one
		$this->db->query("INSERT INTO ".$p."inbox_trusted_domain (entity, domain, label, subdomains, date_creation) VALUES (1, 'carrier2.zzblock-test.invalid', 'ZzTrusted carrier2', 1, '".$this->db->idate(dol_now())."')");
		InboxTrustedDomain::clearCache();
		$this->assertSame(0, $b->cronCheck(), $b->output);
		InboxBlockedSender::clearCache();
		$e = $b->fetch($id);
		$this->assertGreaterThan(0, $e['known_since'], 'became known: told');
		$this->assertStringContainsString('inboxtest_newcust', $e['known_now']);
		$this->assertGreaterThan(0, $b->isBlocked('buyer@newcust.zzblock-test.invalid'), 'still blocked');
		$this->assertSame(1, count($b->fetchAll(0, array('known' => true, 'search' => 'zzblock-test'))));
		$this->assertNull($b->fetch($prot), 'became protected: unblocked');
		// Became the address of a user of Dolibarr: told, never unblocked alone (audit INBX-076)
		$lift = $b->create($this->admin, 'address', 'lift@other.zzblock-test.invalid');
		$this->assertGreaterThan(0, $lift, $b->error);
		$u = $this->user('blocklift');
		$this->db->query("UPDATE ".$p."user SET email = 'lift@other.zzblock-test.invalid' WHERE rowid = ".((int) $u->id));
		$b->cronCheck();
		InboxBlockedSender::clearCache();
		$this->assertNotNull($b->fetch($lift), 'still blocked');
		$this->assertGreaterThan(0, $b->fetch($lift)['known_since'], 'told to the managers');
		$b->delete($this->admin, $lift);
		$this->assertTrue(is_object($this->db->getRow("SELECT rowid FROM ".$p."inbox_action_log WHERE action = 'unblock_auto' AND subject = 'carrier2.zzblock-test.invalid'")));

		// Kept blocked by a manager: no longer told, the next nights neither
		$bob = $this->user('blockbob2');
		$this->manager($bob, false);
		$this->assertSame(-1, $b->keep($bob, $id), 'a manager only');
		$this->assertSame(1, $b->keep($this->admin, $id));
		$b->cronCheck();
		$e = $b->fetch($id);
		$this->assertSame(0, $e['known_since']);
		$this->assertStringContainsString('inboxtest_newcust', $e['known_reason']);
		$this->db->query("DELETE FROM ".$p."inbox_action_log WHERE subject LIKE '%zzblock-test.invalid'");
	}
}
