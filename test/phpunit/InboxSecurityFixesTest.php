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
 * \file    inbox/test/phpunit/InboxSecurityFixesTest.php
 * \ingroup inbox
 * \brief   Fixes of the security audit of 2026-11-08 (1.23.1): one test per finding fixed in the classes
 */

dol_include_once('/inbox/class/inboxmimeparser.class.php');
dol_include_once('/inbox/class/inboxreader.class.php');
dol_include_once('/inbox/class/inboxrules.class.php');
dol_include_once('/inbox/class/inboxnote.class.php');
dol_include_once('/inbox/class/inboxmailref.class.php');
dol_include_once('/inbox/class/inboxservertester.class.php');

/**
 * Database only, no mail server
 */
class InboxSecurityFixesTest extends InboxTestCase
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
		global $conf;

		foreach ($this->saved as $k => $v) {
			$conf->global->$k = $v;
		}
		parent::tearDown();
	}

	/**
	 * INBX-001: nothing hidden in comments or raw text elements, no tag in the CSS of a mail
	 *
	 * @return void
	 */
	public function testSafeHtml()
	{
		$remote = false;
		$out = InboxMimeParser::safeHtml('<p>Bonjour</p><xmp><!--</xmp><img src=x onerror="alert(1)">--></xmp><noembed><!--</noembed><b onmouseover=x>y</b>--></noembed>'
			.'<noframes>z</noframes><!-- <img src=x onerror=alert(2)> --><style>p{color:red}</sty</stylele><img src=x onerror=alert(3)></style>', array(), $remote);
		$this->assertStringContainsString('<p>Bonjour</p>', $out);
		$body = substr($out, strpos($out, '<body>'));
		$this->assertStringNotContainsString('onerror', $body);
		$this->assertStringNotContainsString('onmouseover', $body);
		$this->assertTrue((bool) preg_match_all('#<style>([^<]*)</style>#', $out, $m), 'no « < » inside the CSS: what remains of a tag is text');
		$this->assertStringContainsString('\\3C img', $m[1][1]);
		$this->assertStringNotContainsString('<!--', $out);
		$this->assertStringNotContainsString('<xmp', $out);
		$this->assertStringNotContainsString('<noembed', $out);
		$this->assertSame(2, substr_count($out, '</style>'), 'the CSS of the mail cannot close its element');
	}

	/**
	 * INBX-002: a stored password only to the server it was typed for; master user and OAuth for the administrator
	 *
	 * @return void
	 */
	public function testGuardSecrets()
	{
		$a = $this->box(true, array('status' => InboxAccount::STATUS_ACTIVE));
		$a->fetch($a->id);
		$old = clone $a;
		$this->assertSame('', $a->guardSecrets($old, false, false, false), 'nothing changed');

		$moved = clone $old;
		$moved->imap_host = 'attacker.example.invalid';
		$this->assertSame('InboxErrorPasswordAgain', $moved->guardSecrets($old, false, false, false), 'another host: the password again');
		$this->assertSame('', $moved->guardSecrets($old, true, false, false), 'typed again: allowed');
		foreach (array('imap_port' => 9999, 'imap_encryption' => 'ssl', 'imap_login' => 'other') as $field => $value) {
			$x = clone $old;
			$x->$field = $value;
			$this->assertSame('InboxErrorPasswordAgain', $x->guardSecrets($old, false, false, false), $field);
		}
		$certOff = clone $old;
		$certOff->imap_validate_cert = 0;
		$old->imap_validate_cert = 1;
		$this->assertSame('InboxErrorPasswordAgain', $certOff->guardSecrets($old, false, false, false), 'certificate check turned off');
		$old->imap_validate_cert = $a->imap_validate_cert;

		// SMTP with the same credentials: the IMAP password would go to the new SMTP server
		$smtp = clone $old;
		$smtp->smtp_same_credentials = 1;
		$smtp->smtp_host = 'attacker.example.invalid';
		$old2 = clone $old;
		$old2->smtp_same_credentials = 1;
		$this->assertSame('InboxErrorPasswordAgain', $smtp->guardSecrets($old2, false, false, false));
		$own = clone $old;
		$own->smtp_same_credentials = 0;
		$own->setSmtpPassword('smtp-secret');
		$oldOwn = clone $own;
		$own->smtp_host = 'attacker.example.invalid';
		$this->assertSame('InboxErrorSmtpPasswordAgain', $own->guardSecrets($oldOwn, false, false, false));

		// Master user: the administrator of Dolibarr only
		$master = clone $old;
		$master->imap_auth_mode = InboxAccount::AUTH_MASTER;
		$this->assertSame('InboxErrorAuthModeAdmin', $master->guardSecrets($old, false, false, false));
		$this->assertSame('', $master->guardSecrets($old, false, false, true));
		$this->assertSame('InboxErrorAuthModeAdmin', $master->guardSecrets(null, false, false, false), 'a new mailbox in master mode');
		$oauth = clone $old;
		$oauth->imap_auth_mode = InboxAccount::AUTH_OAUTH;
		$oauth->oauth_service = 'NOTCONFIGURED';
		$this->assertSame('InboxErrorOAuthService', $oauth->guardSecrets($old, false, false, true), 'a service that does not exist');

		// The master password only goes to the server named with it
		$this->setting('INBOX_MASTER_USER', 'master');
		$this->setting('INBOX_MASTER_PASSWORD', dolEncrypt('master-secret'));
		$this->setting('INBOX_MASTER_HOST', 'mail.example.invalid');
		$m = clone $old;
		$m->imap_auth_mode = InboxAccount::AUTH_MASTER;
		$m->imap_host = 'attacker.example.invalid';
		$c = new InboxImapConnector($this->db, $m);
		$secret = new ReflectionMethod($c, 'getSecret');
		$secret->setAccessible(true);
		$this->assertSame('', $secret->invoke($c), 'another host: no master password');
		$m->imap_host = 'MAIL.example.invalid';
		$this->assertSame('master-secret', $secret->invoke(new InboxImapConnector($this->db, $m)));
		$this->setting('INBOX_MASTER_HOST', '');
		$this->assertSame('', $secret->invoke(new InboxImapConnector($this->db, $m)), 'no host set: never sent');
	}

	/**
	 * INBX-010: hosts allowed by the setup
	 *
	 * @return void
	 */
	public function testHostAllowed()
	{
		$this->setting('INBOX_ALLOWED_HOSTS', '');
		$this->assertTrue(InboxServerTester::hostAllowed('anything.example'));
		$this->setting('INBOX_ALLOWED_HOSTS', 'mail.example.com, .example.org');
		$this->assertTrue(InboxServerTester::hostAllowed('MAIL.example.com'));
		$this->assertTrue(InboxServerTester::hostAllowed('imap.example.org'));
		$this->assertTrue(InboxServerTester::hostAllowed('example.org'));
		$this->assertFalse(InboxServerTester::hostAllowed('evil-example.org'));
		$this->assertFalse(InboxServerTester::hostAllowed('10.0.0.1'));
	}

	/**
	 * INBX-022: the results of authentication of our own server only
	 *
	 * @return void
	 */
	public function testAuthResultsTrusted()
	{
		$headers = array(array('Authentication-Results', 'forged.example; dmarc=pass; dkim=pass'), array('Authentication-Results', 'mx.example.com 1; spf=softfail; dkim=fail; dmarc=fail'));
		$this->setting('INBOX_AUTH_SERVERS', '');
		$auth = InboxReader::authResults($headers);
		$this->assertFalse($auth['trusted'], 'no server declared: never trusted');
		$this->setting('INBOX_AUTH_SERVERS', 'mx.example.com');
		$auth = InboxReader::authResults($headers);
		$this->assertTrue($auth['trusted']);
		$this->assertSame('fail', $auth['dmarc'], 'the header of our server, not the forged one above it');
		$this->assertTrue($auth['fail']);
		$this->assertFalse(InboxReader::authResults(array(array('Authentication-Results', 'forged.example; dmarc=pass')))['trusted']);
	}

	/**
	 * INBX-014: no variable of Sieve in the values typed
	 *
	 * @return void
	 */
	public function testSieveVariables()
	{
		$a = $this->box(true, array('status' => InboxAccount::STATUS_ACTIVE));
		$r = new InboxRules($this->db);
		$rules = $r->cleanRules($a, array(array('name' => 'x', 'on' => 1, 'all' => 1, 'conds' => array(array('f' => 'subject', 'op' => 'contains', 'v' => 'a ${insubject}')),
			'acts' => array(array('a' => 'move', 'v' => 'INBOX.${insubject}')))));
		$away = array('on' => true, 'from' => '2026-11-10', 'to' => '2026-11-14', 'subject' => 'Absent ${1}', 'text' => 'x', 'days' => 1, 'addr' => array());
		$script = InboxRules::script($rules, $away, array(), 'Trash', array('fileinto', 'vacation', 'variables', 'date', 'relational', 'mailbox'), '', array());
		$this->assertStringContainsString('"a $ {insubject}"', $script);
		$this->assertStringContainsString('"INBOX.$ {insubject}"', $script);
		$this->assertStringContainsString(':subject "Absent $ {1} : ${insubject}"', $script, 'only the variable of inbox stays');
	}

	/**
	 * INBX-006: a note follows the mail, its author only changes it
	 *
	 * @return void
	 */
	public function testNoteOfOthers()
	{
		$alice = $this->user('alice');
		$bob = $this->user('bob');
		$a = $this->box(true, array('status' => InboxAccount::STATUS_ACTIVE));
		$access = new InboxAccess($this->db);
		$access->grant($this->admin, $a->id, $alice->id, 0, InboxAccess::LEVEL_MANAGE);
		$access->grant($this->admin, $a->id, $bob->id, 0, InboxAccess::LEVEL_MANAGE);
		InboxAccess::clearCache();
		$p = $this->db->prefix();
		$this->db->query("INSERT INTO ".$p."inbox_folder (fk_account, path, name, delimiter, role, status) VALUES (".((int) $a->id).", 'INBOX', 'INBOX', '.', 'inbox', 1)");
		$folder = (int) $this->db->last_insert_id($p.'inbox_folder');
		$this->db->query("INSERT INTO ".$p."inbox_mail (fk_account, fk_folder, remote_id, sort_key, message_id, date_mail, from_email, subject, date_index) VALUES (".((int) $a->id).", ".$folder.", '1:1', 1,"
			." 'zznote@inbox-test.invalid', '2026-11-02 10:00:00', 'client@inbox-test.invalid', 'x', '".$this->db->idate(dol_now())."')");
		$refs = new InboxMailRef($this->db);
		$ref = $refs->fetchOrCreateByMessageId('zznote@inbox-test.invalid', array('subject' => 'x', 'from_email' => 'client@inbox-test.invalid'));
		$notes = new InboxNote($this->db);
		$id = $notes->add($ref, 'Note of Alice', $alice);
		$this->assertGreaterThan(0, $id, $notes->error);
		$this->assertLessThan(0, $notes->update($id, 'Changed by Bob', $bob), 'manage on the mailbox is not enough');
		$this->assertSame('InboxErrorNoteNotYours', $notes->error);
		$this->assertLessThan(0, $notes->delete($id, $bob));
		$this->assertGreaterThan(0, $notes->update($id, 'Changed by Alice', $alice));
	}
}
