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
 * \file    inbox/test/phpunit/InboxSecurityFixes2Test.php
 * \ingroup inbox
 * \brief   Fixes of the security audit of 2026-11-09 (1.35.1): one test per finding fixed in the classes (the
 *          delivery reports, INBX-030 and INBX-031, in InboxBounceTest)
 */

require_once __DIR__.'/InboxTestCase.php';
dol_include_once('/inbox/class/inboxai.class.php');
dol_include_once('/inbox/class/inboxmailbuilder.class.php');
dol_include_once('/inbox/class/inboxservertester.class.php');
dol_include_once('/inbox/class/inboxactionlog.class.php');
dol_include_once('/inbox/class/inboxreader.class.php');
dol_include_once('/inbox/class/inboxdictation.class.php');
dol_include_once('/inbox/class/inboxview.class.php');
dol_include_once('/inbox/class/inboxnotification.class.php');
dol_include_once('/inbox/core/triggers/interface_95_modInbox_InboxTriggers.class.php');

/**
 * Database only, no mail server
 */
class InboxSecurityFixes2Test extends InboxTestCase
{
	/** @var array<string,string> Settings changed by a test */
	protected $saved = array();

	/**
	 * @param string $name  Setting
	 * @param string $value Value for the test
	 * @return void
	 */
	protected function setting($name, $value)
	{
		global $conf;

		if (!array_key_exists($name, $this->saved)) {
			$this->saved[$name] = getDolGlobalString($name);
		}
		$conf->global->$name = $value;
	}

	/**
	 * @return void
	 */
	protected function tearDown(): void
	{
		global $conf, $user;

		foreach ($this->saved as $k => $v) {
			$conf->global->$k = $v;
		}
		$user = $this->admin;
		parent::tearDown();
	}

	/**
	 * @param InboxView $v    Helper
	 * @param User      $user User
	 * @return array<int,array<string,mixed>> His views by id
	 */
	protected function seen(InboxView $v, User $user)
	{
		$out = array();
		foreach ($v->listFor($user) as $view) {
			$out[$view['id']] = $view;
		}

		return $out;
	}

	/**
	 * @param string $name    Group name (test prefix added)
	 * @param User[] $members Members
	 * @param int    $entity  Entity of the membership
	 * @return int Group
	 */
	protected function group($name, array $members, $entity = 1)
	{
		$p = $this->db->prefix();
		$this->db->query("INSERT INTO ".$p."usergroup (entity, nom, datec) VALUES (1, 'inboxtest_".$this->db->escape($name)."', '".$this->db->idate(dol_now())."')");
		$id = (int) $this->db->last_insert_id($p.'usergroup');
		foreach ($members as $u) {
			$this->db->query("INSERT INTO ".$p."usergroup_user (entity, fk_user, fk_usergroup) VALUES (".((int) $entity).", ".((int) $u->id).", ".$id.")");
		}

		return $id;
	}

	/**
	 * INBX-032: nothing a browser would load in a proposal of the AI, an unclosed tag at the end included
	 *
	 * @return void
	 */
	public function testAiProposalNoRemote()
	{
		$this->assertSame('<p>Bonjour</p>', InboxAi::clean('<p>Bonjour</p><img src="https://beacon.invalid/x.png?d=draft"'));
		$this->assertSame('<p>Bonjour</p>', InboxAi::clean('<p>Bonjour</p><img src="https://beacon.invalid/x.png">'));
		$out = InboxAi::clean('<p style="background:url(https://beacon.invalid/y.png)">a</p><p style="background:u\72 l(https://beacon.invalid/z.png)">b</p><p style="text-align:center">c</p>');
		$this->assertStringNotContainsString('beacon', $out);
		$this->assertStringContainsString('style="text-align:center"', $out, 'a style without picture stays');
		// A writing keeps its pictures from the web
		$this->assertStringContainsString('<img src="https://example.invalid/logo.png"', InboxMailBuilder::cleanHtml('<p>x</p><img src="https://example.invalid/logo.png">'));
	}

	/**
	 * INBX-038: a link is a full web address, a mail or a phone number, no other scheme hidden after it
	 *
	 * @return void
	 */
	public function testLinkSchemes()
	{
		$html = InboxMailBuilder::cleanHtml('<p><a href="https:javascript:alert(1)">a</a><a href="tel:javascript:alert(1)">b</a><a href="tel:+33 1 23">c</a>'
			.'<a href="https://ok.invalid/">d</a><a href="mailto:a@b.invalid">e</a><a href="#top">f</a></p>');
		$this->assertStringNotContainsString('javascript', $html);
		$this->assertStringContainsString('href="https://ok.invalid/"', $html);
		$this->assertStringContainsString('href="mailto:a@b.invalid"', $html);
		$this->assertStringContainsString('href="#top"', $html);
		$this->assertStringContainsString('href="tel:', $html);
	}

	/**
	 * INBX-010: a host of the private network chosen first by the administrator; the IMAP answer of a test said with
	 * the details only
	 *
	 * @return void
	 */
	public function testPrivateHosts()
	{
		global $langs, $user;

		foreach (array('127.0.0.1', '10.1.2.3', '[::1]', 'fd00::5', 'mail.lan', 'intranet', 'localhost') as $host) {
			$this->assertTrue(InboxServerTester::isPrivate($host), $host);
		}
		foreach (array('8.8.8.8', '2001:4860:4860::8888') as $host) {
			$this->assertFalse(InboxServerTester::isPrivate($host), $host);
		}
		$this->setting('INBOX_ALLOWED_HOSTS', '');
		$user = $this->user('privhost');
		$this->assertTrue(InboxServerTester::privateRefused('10.1.2.3'));
		$this->assertFalse(InboxServerTester::privateRefused('10.1.2.3', array('10.1.2.3')), 'a host already in use');
		$this->assertFalse(InboxServerTester::privateRefused('8.8.8.8'));
		$this->setting('INBOX_ALLOWED_HOSTS', 'mail.example.org, 10.1.2.3');
		$this->assertFalse(InboxServerTester::privateRefused('10.1.2.3'), 'named by the setup');
		$this->setting('INBOX_ALLOWED_HOSTS', '');
		$user = $this->admin;
		$this->assertFalse(InboxServerTester::privateRefused('10.9.9.9'), 'the administrator chooses any host');

		// Nothing listens on the port 1: refused, said in full with the details only
		$a = new InboxAccount($this->db);
		$a->email = 'probe@inbox-test.invalid';
		$a->imap_host = '127.0.0.1';
		$a->imap_port = 1;
		$a->imap_encryption = 'none';
		$a->imap_login = 'probe';
		$a->imap_auth_mode = InboxAccount::AUTH_LOGIN;
		$a->setImapPassword('x');
		$t = new InboxServerTester($this->db);
		$t->withDetail = false;
		$step = $t->run($a)['steps'][0];
		$this->assertSame('error', $step['status']);
		$this->assertSame($langs->transnoentities('InboxTestImapConnect', '127.0.0.1', 1), $step['message']);
		$t->withDetail = true;
		$this->assertStringContainsString('tcp://', $t->run($a)['steps'][0]['message']);
	}

	/**
	 * INBX-016: an undo goes outside the journal only when it gives back what the lines did
	 *
	 * @return void
	 */
	public function testUndoGivesBack()
	{
		$a = $this->box(true, array('status' => InboxAccount::STATUS_ACTIVE));
		$p = $this->db->prefix();
		$folders = array();
		foreach (array('INBOX' => 'inbox', 'INBOX.Trash' => 'trash') as $path => $role) {
			$this->db->query("INSERT INTO ".$p."inbox_folder (fk_account, path, name, delimiter, role, status) VALUES (".((int) $a->id).", '".$path."', '".$path."', '.', '".$role."', 1)");
			$folders[$path] = (int) $this->db->last_insert_id($p.'inbox_folder');
		}
		$this->db->query("INSERT INTO ".$p."inbox_mail (fk_account, fk_folder, remote_id, sort_key, message_id, date_mail, from_email, subject, date_index) VALUES (".((int) $a->id).", ".$folders['INBOX.Trash'].", '1:1', 1,"
			." 'zzundo@inbox-test.invalid', '2026-11-02 10:00:00', 'client@inbox-test.invalid', 'x', '".$this->db->idate(dol_now())."')");
		$mail = (int) $this->db->last_insert_id($p.'inbox_mail');
		$deleted = (object) array('action' => 'delete', 'fk_account' => (int) $a->id, 'fk_account_to' => null, 'folder_from' => 'INBOX', 'folder_to' => 'INBOX.Trash');
		$tagged = (object) array('action' => 'tag', 'fk_account' => (int) $a->id, 'fk_account_to' => null, 'folder_from' => 'INBOX', 'folder_to' => null);
		$other = (object) array('action' => 'redirect', 'fk_account' => (int) $a->id, 'fk_account_to' => null, 'folder_from' => null, 'folder_to' => null);

		$this->assertTrue(InboxActionLog::undoes($this->db, 'move', array($mail), array('folder' => $folders['INBOX']), array($deleted)), 'back where it was');
		$this->assertFalse(InboxActionLog::undoes($this->db, 'delete', array($mail), array(), array($deleted)), 'a delete never undoes');
		$this->assertFalse(InboxActionLog::undoes($this->db, 'move', array($mail), array('folder' => $folders['INBOX.Trash']), array($deleted)), 'elsewhere than where it was');
		$this->assertFalse(InboxActionLog::undoes($this->db, 'move', array($mail), array('folder' => $folders['INBOX']), array($tagged)), 'a move behind a line of tags');
		$this->assertTrue(InboxActionLog::undoes($this->db, 'tag', array($mail), array(), array($tagged)));
		$this->assertFalse(InboxActionLog::undoes($this->db, 'tag', array($mail), array(), array($other)), 'a line that no undo gives back');
		$this->assertFalse(InboxActionLog::undoes($this->db, 'move', array($mail, 999999999), array('folder' => $folders['INBOX']), array($deleted)), 'a mail that is not there');
	}

	/**
	 * INBX-022: the pictures of a sender allowed load alone only for a mail proven
	 *
	 * @return void
	 */
	public function testAuthPass()
	{
		$ar = function ($value) {
			return InboxReader::authResults(array(array('Authentication-Results', $value)));
		};
		// No receiving server declared: the topmost header may be the sender's, never proven (INBX-071, 2026-11-11)
		$this->setting('INBOX_AUTH_SERVERS', '');
		$this->assertFalse(InboxReader::authPass(InboxReader::authResults(array()), 'boss@client.invalid'), 'no result: not proven');
		$this->assertFalse(InboxReader::authPass($ar('mx.example.com; dmarc=pass header.from=client.invalid'), 'boss@client.invalid'), 'no server declared');

		$this->setting('INBOX_AUTH_SERVERS', 'mx.example.com');
		$dkim = $ar('mx.example.com; spf=pass smtp.mailfrom=client.invalid; dkim=pass header.d=client.invalid header.s=s1; dmarc=none');
		$this->assertSame(array('client.invalid'), $dkim['dkim_domains']);
		$this->assertTrue(InboxReader::authPass($dkim, 'boss@client.invalid'));
		$this->assertTrue(InboxReader::authPass($dkim, 'boss@mail.client.invalid'), 'a subdomain of the signature');
		$this->assertFalse(InboxReader::authPass($dkim, 'boss@other.invalid'), 'a signature of another domain');
		$this->assertTrue(InboxReader::authPass($ar('mx.example.com; dmarc=pass header.from=client.invalid'), 'x@client.invalid'));
		$this->assertFalse(InboxReader::authPass($ar('mx.example.com; dmarc=pass'), 'x@client.invalid'), 'DMARC without the domain it was evaluated on');
		$this->assertFalse(InboxReader::authPass($ar('mx.example.com; dmarc=pass header.from=other.invalid'), 'x@client.invalid'), 'DMARC of another domain (INBX-050)');
		$this->assertFalse(InboxReader::authPass($ar('mx.example.com; dkim=pass header.d=client.invalid; dmarc=fail header.from=client.invalid'), 'boss@client.invalid'));
		$this->assertFalse(InboxReader::authPass($ar('forged.example; dmarc=pass header.from=client.invalid'), 'boss@client.invalid'), 'the result of the server declared only');
		$this->assertTrue(InboxReader::authPass($ar('mx.example.com 1; dmarc=pass header.from=client.invalid'), 'boss@client.invalid'));

		// INBX-050: values chosen by the sender inside a result are not results
		$forged = $ar('mx.example.com; spf=pass (mx.example.com: domain of dmarc=pass@evil.invalid designates 192.0.2.1 as permitted sender)'
			.' smtp.mailfrom=dmarc=pass@evil.invalid; dkim=none; dmarc=fail (p=none) header.from=recognized.invalid');
		$this->assertSame('fail', $forged['dmarc'], 'the address of the envelope is not the verdict');
		$this->assertTrue($forged['fail']);
		$this->assertFalse(InboxReader::authPass($forged, 'compta@recognized.invalid'));
		$this->assertSame('none', $ar('mx.example.com; spf=pass smtp.mailfrom=dkim=pass@evil.invalid; dkim=none')['dkim'], 'nor for DKIM (one-click unsubscribe)');
		$i = $ar('mx.example.com; dkim=pass header.i=recognized.invalid@evil.invalid header.d=evil.invalid');
		$this->assertSame(array('evil.invalid'), $i['dkim_domains']);
		$i = $ar('mx.example.com; dkim=pass header.i=recognized.invalid@evil.invalid');
		$this->assertSame(array('evil.invalid'), $i['dkim_domains'], 'header.i: the part after its @');
		$twice = $ar('mx.example.com; dmarc=pass header.from=client.invalid; dmarc=pass header.from=client.invalid');
		$this->assertTrue(InboxReader::authPass($twice, 'a@client.invalid'), 'a result repeated as is (Plesk) counts once');
		$this->assertTrue($ar('mx.example.com; dmarc=fail header.from=client.invalid; dmarc=pass header.from=client.invalid')['fail'], 'two that differ: failure');
	}

	/**
	 * INBX-033: pieces of voice sent to the transcription server within a cap per user and per hour
	 *
	 * @return void
	 */
	public function testDictationCap()
	{
		$u = $this->user('dictcap');
		$this->setting('INBOX_WHISPER_MAX_PER_HOUR', '2');
		$d = new InboxDictation($this->db);
		$this->assertTrue($d->countPiece($u));
		$this->assertTrue($d->countPiece($u));
		$this->assertFalse($d->countPiece($u), 'the cap of the hour');
		// Another hour: counted again from zero
		$params = new InboxUserParam($this->db);
		$params->set($u, InboxDictation::USAGE, (string) json_encode(array('h' => (int) floor(dol_now() / 3600) - 1, 'n' => 99)));
		$this->assertTrue($d->countPiece($u));
	}

	/**
	 * INBX-034, INBX-036, INBX-041, INBX-042, INBX-043: shared views
	 *
	 * @return void
	 */
	public function testSharedViews()
	{
		$author = $this->user('sva');
		$bob = $this->user('svb');
		$carol = $this->user('svc');
		$a = $this->box(true, array('status' => InboxAccount::STATUS_ACTIVE));
		$b = $this->box(true, array('status' => InboxAccount::STATUS_ACTIVE, 'email' => 'sv2'.self::BOX));
		$access = new InboxAccess($this->db);
		$access->grant($this->admin, $a->id, $author->id, 0, InboxAccess::LEVEL_READ);
		$access->grant($this->admin, $b->id, $author->id, 0, InboxAccess::LEVEL_READ);
		$access->grant($this->admin, $b->id, $bob->id, 0, InboxAccess::LEVEL_READ);
		InboxAccess::clearCache();
		// Carol is a member of the group for another entity only
		$group = $this->group('svg', array($carol), 2);
		$v = new InboxView($this->db);
		$id = $v->save($author, array('label' => 'ZzView <b>x</b>', 'search' => array('q' => 'devis', 'soc' => array('id' => 999999999, 'name' => 'Misleading name')),
			'accounts' => array($a->id, $b->id), 'show_count' => 1, 'shared_edit' => 1,
			'shares' => array(array('kind' => 'user', 'id' => $bob->id), array('kind' => 'group', 'id' => $group))));
		$this->assertGreaterThan(0, $id, $v->errorCode);

		$mine = $this->seen($v, $author)[$id];
		$this->assertStringNotContainsString('<', $mine['label'], 'a name in plain text');
		$this->assertSame(1, $mine['show_count']);
		$this->assertSame('', $mine['search']['soc']['name'], 'the name read in the database, never the text of the author');
		$this->assertSame(0, $this->seen($v, $bob)[$id]['show_count'], 'the counter of the author is not imposed');
		$this->assertTrue($v->counted($bob, $id, true));
		$this->assertSame(1, $this->seen($v, $bob)[$id]['show_count']);
		$this->assertFalse($v->counted($author, $id, true), 'his own view: his choice in the form');
		$this->assertArrayNotHasKey($id, $this->seen($v, $carol), 'a group of another entity');
		$this->db->query("UPDATE ".$this->db->prefix()."usergroup_user SET entity = 1 WHERE fk_usergroup = ".((int) $group));
		$this->assertArrayHasKey($id, $this->seen($v, $carol));

		// Bob changes the view: the mailbox of the author he does not read stays, the counter of the author too
		$this->assertSame($id, $v->save($bob, array('id' => $id, 'label' => 'ZzView y', 'accounts' => array($b->id), 'show_count' => 0)));
		$after = $this->seen($v, $author)[$id];
		$ids = $after['accounts'];
		sort($ids);
		$this->assertSame(array((int) $a->id, (int) $b->id), $ids);
		$this->assertSame(1, $after['show_count']);
		// Bob chooses « all »: the view of the author is not widened
		$this->assertSame($id, $v->save($bob, array('id' => $id, 'label' => 'ZzView y', 'accounts' => array())));
		$this->assertCount(2, $this->seen($v, $author)[$id]['accounts']);
	}

	/**
	 * INBX-035: the views of a user removed go with their shares; the shares to a group removed go
	 *
	 * @return void
	 */
	public function testViewsForgotten()
	{
		global $conf, $langs;

		$author = $this->user('svgone');
		$bob = $this->user('svgb');
		$v = new InboxView($this->db);
		$id = $v->save($author, array('label' => 'ZzView gone', 'shares' => array(array('kind' => 'user', 'id' => $bob->id))));
		$this->assertArrayHasKey($id, $this->seen($v, $bob));
		$t = new InterfaceInboxTriggers($this->db);
		$t->runTrigger('USER_DELETE', $author, $this->admin, $langs, $conf);
		$p = $this->db->prefix();
		$this->assertArrayNotHasKey($id, $this->seen($v, $bob));
		$this->assertFalse(is_object($this->db->getRow("SELECT rowid FROM ".$p."inbox_view_share WHERE fk_view = ".((int) $id))));

		$group = $this->group('svgone', array());
		$id2 = $v->save($bob, array('label' => 'ZzView team', 'shares' => array(array('kind' => 'group', 'id' => $group))));
		$this->assertTrue(is_object($this->db->getRow("SELECT rowid FROM ".$p."inbox_view_share WHERE fk_view = ".((int) $id2))));
		$t->runTrigger('USERGROUP_DELETE', (object) array('id' => $group), $this->admin, $langs, $conf);
		$this->assertFalse(is_object($this->db->getRow("SELECT rowid FROM ".$p."inbox_view_share WHERE fk_usergroup = ".((int) $group))));
	}

	/**
	 * INBX-045: a notification of a mailbox not read any more keeps neither its subject nor its addresses
	 *
	 * @return void
	 */
	public function testNotificationAfterAccessLoss()
	{
		$u = $this->user('notifgone');
		$a = $this->box(true, array('status' => InboxAccount::STATUS_ACTIVE));
		$n = new InboxNotification($this->db);
		$this->assertGreaterThan(0, $n->add((int) $u->id, 'bounce', array('account' => (int) $a->id,
			'payload' => array('subject' => 'Devis 121', 'detail' => 'nobody@client.example.invalid', 'text' => 'x'))));
		$item = $n->listFor($u)['items'][0];
		$this->assertSame(array('', '', ''), array($item['subject'], $item['detail'], $item['text']));
	}
}
