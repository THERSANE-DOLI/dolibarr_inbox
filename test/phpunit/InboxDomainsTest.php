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
 * \file    inbox/test/phpunit/InboxDomainsTest.php
 * \ingroup inbox
 * \brief   1.76.0: shared domains (shipped list, subdomains, local entries, disabled ones, old setting,
 *          thirdparties offered for a shared domain)
 */

// From the command line only, never from the web: test/ stays in the Git branches
if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

require_once __DIR__.'/InboxTestCase.php';
dol_include_once('/inbox/class/inboxdomains.class.php');
dol_include_once('/inbox/class/inboxnotification.class.php');

/**
 * Local entries of the tests: domains of inbox-test.invalid (removed by InboxTestCase::cleanup())
 */
class InboxDomainsTest extends InboxTestCase
{
	/**
	 * Requests to the managers: one per domain a day, a few domains an hour (the bell is never flooded)
	 *
	 * @return void
	 */
	public function testRequestsLimited()
	{
		$asker = $this->user('domasker');
		$manager = $this->user('dommanager');
		$p = $this->db->prefix();
		$notif = new InboxNotification($this->db);
		$this->assertSame('', InboxDomains::requestLimit($this->db, (int) $asker->id, 'first.example'));
		$notif->add((int) $manager->id, 'domain_request', array('actor' => (int) $asker->id, 'payload' => array('detail' => 'first.example', 'text' => 'x')));
		$this->assertSame('already', InboxDomains::requestLimit($this->db, (int) $asker->id, 'first.example'), 'the same domain today');
		$this->assertSame('', InboxDomains::requestLimit($this->db, (int) $asker->id, 'other.example'));
		$this->assertSame('', InboxDomains::requestLimit($this->db, (int) $manager->id, 'first.example'), 'another user');
		for ($i = 2; $i <= InboxDomains::REQUESTS_PER_HOUR; $i++) {
			$this->db->query("INSERT INTO ".$p."inbox_notification (entity, fk_user, type, fk_user_actor, payload, date_creation) VALUES (1, ".((int) $manager->id).", 'domain_request', ".((int) $asker->id).","
				." '".$this->db->escape(json_encode(array('detail' => 'd'.$i.'.example')))."', '".$this->db->idate(dol_now())."')");
		}
		$this->assertSame('hour', InboxDomains::requestLimit($this->db, (int) $asker->id, 'other.example'), 'too many domains this hour');
		$this->db->query("UPDATE ".$p."inbox_notification SET date_creation = '".$this->db->idate(dol_now() - 2 * 86400)."' WHERE fk_user_actor = ".((int) $asker->id));
		$this->assertSame('', InboxDomains::requestLimit($this->db, (int) $asker->id, 'first.example'), 'the next days again');
	}

	/**
	 * @return void
	 */
	public function testShippedAndLocal()
	{
		$domains = new InboxDomains($this->db);
		$this->assertContains('gmail.com', InboxDomains::readFile('public.txt'));
		$this->assertSame('public', $domains->categoryOf('jean@gmail.com'));
		$this->assertSame('public', $domains->categoryOf('x@smtp.orange.fr'), 'a subdomain of a public one');
		$this->assertSame('disposable', $domains->categoryOf('x@yopmail.com'), 'the copied list of disposable addresses');
		$this->assertSame('', $domains->categoryOf('x@acme.co.uk'));
		$this->assertSame('', $domains->categoryOf('x@mail.com.evil.inbox-test.invalid'), 'a line of the file, never a part of it');
		$this->assertSame('', $domains->categoryOf('not a domain'));

		// A shipped entry disabled: no category any more; never changed, only enabled again
		try {
			$this->assertSame(array('free.fr'), $domains->setShipped(array('free.fr', 'not-shipped.inbox-test.invalid'), false));
			$this->assertSame('', $domains->categoryOf('x@free.fr'));
			$this->assertSame('', $domains->categoryOf('x@smtp.free.fr'), 'its subdomains neither');
			$this->assertSame(array(), $domains->setShipped(array('free.fr'), false), 'already disabled');
			$this->assertSame(1, $domains->page('free.fr', '', 'off', 0)['total']);
			$this->assertContains('free.fr', $domains->restoreShipped());
			$this->assertSame('public', $domains->categoryOf('x@free.fr'));
		} finally {
			$domains->setShipped(array('free.fr'), true);
		}

		// Local entries: pasted, an address gives its domain, a public suffix refused; a local one wins
		$res = $domains->add($this->admin, array('group.inbox-test.invalid', 'jane@temp.inbox-test.invalid', 'co.uk', 'x'), 'shared');
		$this->assertCount(2, $res['added']);
		$this->assertSame(array('co.uk', ''), $res['bad']);
		$this->assertSame('shared', $domains->categoryOf('a@group.inbox-test.invalid'));
		$this->assertSame('shared', $domains->categoryOf('a@mail.group.inbox-test.invalid'));
		$this->assertSame('shared', $domains->setCategory($res['added'][1], 'disposable'));
		$this->assertSame('disposable', $domains->categoryOf('jane@temp.inbox-test.invalid'));
		$this->assertSame(array('domain' => 'temp.inbox-test.invalid', 'category' => 'disposable'), $domains->delete($res['added'][1]));
		$this->assertSame('', $domains->categoryOf('jane@temp.inbox-test.invalid'));

		$page = $domains->page('inbox-test', '', 'local', 0);
		$this->assertSame(1, $page['total']);
		$this->assertSame('group.inbox-test.invalid', $page['rows'][0]['domain']);
		$this->assertArrayHasKey('mails', $page['rows'][0]);
		$this->assertSame(10, count($domains->page('', 'disposable', 'shipped', 0, 10)['rows']));
		$this->assertGreaterThan(100, $page['shipped']['public']['count']);
		$this->assertSame(0, (int) $this->db->getRow("SELECT COUNT(rowid) AS n FROM ".$this->db->prefix()."inbox_domain WHERE origin = 'shipped' AND status = 1")->n, 'the shipped list is never copied in the database');
	}

	/**
	 * The files are read as text, a domain looked for as a whole line: their format must be exact
	 *
	 * @return void
	 */
	public function testFileFormat()
	{
		$sources = InboxDomains::sources();
		foreach (InboxDomains::FILES as $category => $file) {
			$text = (string) file_get_contents(InboxDomains::dataDir().'/'.$file);
			$this->assertStringNotContainsString("\r", $text, $file);
			$this->assertStringEndsWith("\n", $text, $file);
			$bad = array_filter(explode("\n", rtrim($text, "\n")), function ($l) {
				return $l !== '' && $l[0] !== '#' && !InboxDomains::valid($l);
			});
			$this->assertSame(array(), array_values($bad), $file.': lines that are not a domain alone');
			$this->assertSame(hash('sha256', $text), $sources[$category]['sha256'] ?? '', $file.': sources.json to write again (dev/update-domains.php --local)');
		}
	}

	/**
	 * The hidden setting of before becomes local entries
	 *
	 * @return void
	 */
	public function testOldSetting()
	{
		global $conf;

		require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
		dolibarr_set_const($this->db, 'INBOX_PUBLIC_DOMAINS', 'old.inbox-test.invalid, other.inbox-test.invalid', 'chaine', 0, '', $conf->entity);
		$domains = new InboxDomains($this->db);
		$this->assertSame(1, $domains->migrateOldSetting());
		$this->assertSame('public', $domains->categoryOf('a@old.inbox-test.invalid'));
		$this->assertSame(0, $domains->migrateOldSetting(), 'once');
		$this->assertSame('', getDolGlobalString('INBOX_PUBLIC_DOMAINS'));
	}

	/**
	 * @return void
	 */
	public function testCandidates()
	{
		$p = $this->db->prefix();
		$ids = array();
		foreach (array('inboxtest_groupa', 'inboxtest_groupb') as $name) {
			$this->db->query("INSERT INTO ".$p."societe (nom, entity, status, client, email, datec) VALUES ('".$name."', 1, 1, 1, '".$name."@holding.inbox-test.invalid', '".$this->db->idate(dol_now())."')");
			$ids[] = (int) $this->db->last_insert_id($p.'societe');
		}
		$this->db->query("INSERT INTO ".$p."societe (nom, entity, status, client, email, datec) VALUES ('inboxtest_solo', 1, 1, 1, 'info@solo.inbox-test.invalid', '".$this->db->idate(dol_now())."')");
		$solo = (int) $this->db->last_insert_id($p.'societe');
		try {
			$m = new InboxContactMatcher($this->db);
			$this->assertSame(0, $m->matchDomain('new@holding.inbox-test.invalid'), 'two thirdparties: none chosen');
			$c = $m->domainCandidates('new@holding.inbox-test.invalid');
			$this->assertEqualsCanonicalizing($ids, $c['socs'], 'both offered');
			$this->assertSame($solo, $m->matchDomain('new@solo.inbox-test.invalid'));
			$this->assertSame(array(), $m->domainCandidates('new@solo.inbox-test.invalid')['socs'], 'one sure thirdparty: nothing to choose');
			// Marked shared: even one thirdparty is offered, never taken alone
			(new InboxDomains($this->db))->add($this->admin, array('solo.inbox-test.invalid'), 'shared');
			$m = new InboxContactMatcher($this->db);
			$this->assertSame(0, $m->matchDomain('new@solo.inbox-test.invalid'));
			$this->assertSame(array($solo), $m->domainCandidates('new@solo.inbox-test.invalid')['socs']);
			$this->assertSame(array(), $m->domainCandidates('jean@gmail.com')['socs'], 'a public service offers nothing');
			// Recognition by the domain turned off: exact addresses only, the category still said
			global $conf;
			$conf->global->INBOX_DOMAIN_MATCH = 0;
			try {
				$m = new InboxContactMatcher($this->db);
				$this->assertSame(0, $m->matchDomain('other@holding.inbox-test.invalid'));
				$this->assertSame(array(), $m->domainCandidates('other@holding.inbox-test.invalid')['socs']);
				$this->assertSame('disposable', $m->domainCandidates('x@yopmail.com')['category']);
			} finally {
				unset($conf->global->INBOX_DOMAIN_MATCH);
			}
		} finally {
			$this->db->query("DELETE FROM ".$p."societe WHERE rowid IN (".implode(',', array_merge($ids, array($solo))).")");
		}
	}
}
