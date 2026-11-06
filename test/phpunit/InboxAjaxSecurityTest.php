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
 * \file    inbox/test/phpunit/InboxAjaxSecurityTest.php
 * \ingroup inbox
 * \brief   Endpoints over HTTP: session, CSRF, method, 404, no secret in the answers, XSS (CA-05, CA-11, CA-12)
 *
 * Needs a test instance reachable over HTTP with the same database: INBOX_TEST_URL (htdocs URL), INBOX_TEST_LOGIN and
 * INBOX_TEST_PASSWORD of an admin of that test instance. Skipped without them.
 */

/**
 * Tests over HTTP
 */
class InboxAjaxSecurityTest extends InboxTestCase
{
	/** @var string */
	protected $cookies = '';

	/** @var string */
	protected $base = '';

	/**
	 * @return void
	 */
	protected function setUp(): void
	{
		parent::setUp();
		$this->base = rtrim((string) getenv('INBOX_TEST_URL'), '/');
		if ($this->base === '' || !getenv('INBOX_TEST_LOGIN')) {
			$this->markTestSkipped('INBOX_TEST_URL / INBOX_TEST_LOGIN / INBOX_TEST_PASSWORD not set');
		}
		$this->cookies = tempnam(sys_get_temp_dir(), 'inbox-cookies');
	}

	/**
	 * @return void
	 */
	protected function tearDown(): void
	{
		if ($this->cookies && is_file($this->cookies)) {
			unlink($this->cookies);
		}
		parent::tearDown();
	}

	/**
	 * @param string      $path   Path under htdocs
	 * @param array|null  $json   JSON body (POST), null for GET
	 * @param bool        $session Use the session cookies
	 * @param string      $method Method forced
	 * @return array{code:int,body:string,json:mixed}
	 */
	protected function call($path, $json = null, $session = true, $method = '')
	{
		$ch = curl_init($this->base.$path);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_TIMEOUT, 30);
		if ($session) {
			curl_setopt($ch, CURLOPT_COOKIEFILE, $this->cookies);
			curl_setopt($ch, CURLOPT_COOKIEJAR, $this->cookies);
		}
		if ($json !== null) {
			curl_setopt($ch, CURLOPT_POST, true);
			curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
			curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json));
		}
		if ($method !== '') {
			curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
		}
		$body = (string) curl_exec($ch);
		$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		return array('code' => $code, 'body' => $body, 'json' => json_decode($body, true));
	}

	/**
	 * Log in on the test instance and return the CSRF token of inbox
	 *
	 * @return string
	 */
	protected function login()
	{
		$page = $this->call('/index.php');
		$this->assertMatchesRegularExpression('/name="token" value="([^"]+)"/', $page['body']);
		preg_match('/name="token" value="([^"]+)"/', $page['body'], $m);
		$ch = curl_init($this->base.'/index.php');
		curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $this->cookies, CURLOPT_COOKIEJAR => $this->cookies, CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => http_build_query(array('token' => $m[1], 'actionlogin' => 'login', 'loginfunction' => 'loginfunction', 'username' => getenv('INBOX_TEST_LOGIN'), 'password' => getenv('INBOX_TEST_PASSWORD')))));
		curl_exec($ch);
		curl_close($ch);
		$session = $this->call('/custom/inbox/ajax/session.php');
		$this->assertSame(200, $session['code'], 'logged in: '.$session['body']);

		return (string) $session['json']['token'];
	}

	/**
	 * @return void
	 */
	public function testSessionCsrfMethodNotFound()
	{
		$anon = $this->call('/custom/inbox/ajax/account.php?action=list', null, false);
		$this->assertSame(401, $anon['code'], 'session expired = 401, never the login page');
		$this->assertSame('session_expired', $anon['json']['error']);
		$token = $this->login();
		$this->assertSame(200, $this->call('/custom/inbox/ajax/account.php?action=list')['code']);
		$noToken = $this->call('/custom/inbox/ajax/account.php', array('action' => 'save', 'email' => 'x@inbox-test.invalid'));
		$this->assertSame(403, $noToken['code'], 'write without token refused');
		$badToken = $this->call('/custom/inbox/ajax/account.php', array('action' => 'save', 'token' => 'bad', 'email' => 'x@inbox-test.invalid'));
		$this->assertSame(403, $badToken['code']);
		$get = $this->call('/custom/inbox/ajax/account.php?action=save&token='.urlencode($token).'&email=x@inbox-test.invalid');
		$this->assertSame(405, $get['code'], 'write by GET refused');
		$this->assertSame(0, (int) $this->db->getRow("SELECT COUNT(*) AS n FROM ".$this->db->prefix()."inbox_account WHERE email = 'x@inbox-test.invalid'")->n);
		$this->assertSame(404, $this->call('/custom/inbox/ajax/account.php?action=get&id=99999999')['code']);
		$this->assertSame(404, $this->call('/custom/inbox/ajax/identity.php?action=list&id=99999999')['code']);
	}

	/**
	 * @return void
	 */
	public function testNoSecretAndNoScript()
	{
		$token = $this->login();
		$save = $this->call('/custom/inbox/ajax/account.php', array('action' => 'save', 'token' => $token, 'email' => self::BOX, 'label' => '<img src=x onerror=alert(1)>Devis',
			'imap_host' => self::IMAP_HOST, 'imap_port' => self::IMAP_PORT, 'imap_encryption' => 'none', 'imap_password' => 'http-secret-pass', 'smtp_port' => self::SMTP_PORT, 'smtp_encryption' => 'none'));
		$this->assertSame(200, $save['code'], $save['body']);
		$this->assertStringNotContainsString('http-secret-pass', $save['body']);
		$this->assertStringNotContainsStringIgnoringCase('onerror', $save['body'], 'HTML removed from the texts');
		$id = (int) $save['json']['account']['id'];
		$get = $this->call('/custom/inbox/ajax/account.php?action=get&id='.$id);
		$this->assertStringNotContainsString('http-secret-pass', $get['body']);
		$this->assertArrayNotHasKey('imap_password', $get['json']['account']);
		$this->assertTrue($get['json']['account']['has_imap_password']);
		if (self::dovecotUp()) {
			$test = $this->call('/custom/inbox/ajax/account.php', array('action' => 'test', 'token' => $token, 'id' => $id));
			$this->assertSame(200, $test['code']);
			$this->assertStringNotContainsString('http-secret-pass', $test['body']);
		}
		$identity = $this->call('/custom/inbox/ajax/identity.php', array('action' => 'save', 'token' => $token, 'id' => $id, 'email' => self::BOX,
			'signature' => '<p>Hi</p><script>alert(1)</script><a href="javascript:alert(2)">x</a>'));
		$this->assertSame(200, $identity['code'], $identity['body']);
		$sig = (string) $identity['json']['identities'][0]['signature'];
		$this->assertStringNotContainsString('<script', $sig);
		$this->assertStringNotContainsStringIgnoringCase('javascript:', $sig);
		$log = $this->call('/custom/inbox/ajax/account.php?action=log&id='.$id);
		$this->assertStringNotContainsString('http-secret-pass', $log['body']);
	}
	/**
	 * Lot W3: lists, reading, body and attachments refuse a forged identifier, a write without token or by GET; the
	 * body answers with the headers that forbid any script (CA-01, CA-04)
	 *
	 * @return void
	 */
	public function testReadingEndpoints()
	{
		$this->needDovecot();
		dol_include_once('/inbox/class/inboxsync.class.php');
		$token = $this->login();
		foreach (array('/custom/inbox/ajax/list.php?action=page&folder=99999999', '/custom/inbox/ajax/list.php?action=state&folder=99999999',
			'/custom/inbox/ajax/mail.php?action=open&id=99999999', '/custom/inbox/ajax/attachment.php?mode=check&id=99999999&part=0') as $path) {
			$this->assertSame(404, $this->call($path)['code'], 'forged identifier: '.$path);
		}
		$this->assertSame(404, $this->call('/custom/inbox/ajax/body.php?id=99999999')['code']);
		$this->assertSame(404, $this->call('/custom/inbox/ajax/list.php?action=pump&folder=99999999')['code'], 'relay: forged folder');
		$pump = $this->call('/custom/inbox/ajax/list.php?action=pump');
		$this->assertSame(200, $pump['code'], $pump['body']);
		$this->assertArrayHasKey('cron', $pump['json'], 'relay: says whether the scheduled job runs');

		// A mailbox open to the user of the HTTP tests
		$login = $this->db->getRow("SELECT rowid FROM ".$this->db->prefix()."user WHERE login = '".$this->db->escape((string) getenv('INBOX_TEST_LOGIN'))."'");
		$a = $this->box(true, array('status' => InboxAccount::STATUS_ACTIVE));
		$access = new InboxAccess($this->db);
		$sync = new InboxSync($this->db);
		$a->fetch($a->id);
		$sync->run($a, microtime(true) + 30, array('force' => true));
		$row = $this->db->getRow("SELECT rowid, fk_folder FROM ".$this->db->prefix()."inbox_mail WHERE fk_account = ".((int) $a->id)." AND message_id = 'seed-1@inbox-test.invalid'");
		$this->assertIsObject($row);
		$this->assertSame(404, $this->call('/custom/inbox/ajax/mail.php?action=open&id='.$row->rowid)['code'], 'IDOR: a mailbox without access is not found');
		$this->assertSame(404, $this->call('/custom/inbox/ajax/body.php?id='.$row->rowid)['code']);
		$this->assertSame(404, $this->call('/custom/inbox/ajax/list.php?action=page&folder='.$row->fk_folder)['code']);

		$this->assertGreaterThan(0, $access->grant($this->admin, (int) $a->id, (int) $login->rowid, 0, InboxAccess::LEVEL_READ));
		$open = $this->call('/custom/inbox/ajax/mail.php?action=open&id='.$row->rowid);
		$this->assertSame(200, $open['code'], $open['body']);
		$this->assertSame('Demande de prix raccords inox DN25', $open['json']['subject']);
		$page = $this->call('/custom/inbox/ajax/list.php', array('action' => 'page', 'folder' => (int) $row->fk_folder, 'q' => '"raccords inox"'));
		$this->assertSame(200, $page['code'], 'a phrase in quotes, sent in the body');
		$this->assertSame(1, $page['json']['total']);

		$ch = curl_init($this->base.'/custom/inbox/ajax/body.php?id='.$row->rowid);
		curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIEFILE => $this->cookies));
		$res = (string) curl_exec($ch);
		curl_close($ch);
		$this->assertMatchesRegularExpression("/Content-Security-Policy: default-src 'none';[^\r\n]*script-src 'none'/i", $res);
		$this->assertStringNotContainsString(' https:', (string) preg_replace('/^.*Content-Security-Policy:([^\r\n]*).*$/s', '$1', $res), 'no remote picture by default');
		$this->assertMatchesRegularExpression('/X-Content-Type-Options: nosniff/i', $res);

		$this->assertSame(403, $this->call('/custom/inbox/ajax/mail.php', array('action' => 'seen', 'id' => (int) $row->rowid, 'seen' => 1))['code'], 'read without token refused');
		$this->assertSame(405, $this->call('/custom/inbox/ajax/mail.php?action=seen&seen=1&id='.$row->rowid.'&token='.urlencode($token))['code'], 'write by GET refused');
	}
}
