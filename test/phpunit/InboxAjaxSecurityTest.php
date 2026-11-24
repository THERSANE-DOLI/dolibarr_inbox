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

// From the command line only, never from the web: test/ stays in the Git branches
if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

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
	 * @param string[]    $headers Headers added
	 * @return array{code:int,body:string,json:mixed}
	 */
	protected function call($path, $json = null, $session = true, $method = '', $headers = array())
	{
		$ch = curl_init($this->base.$path);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_TIMEOUT, 30);
		if ($session) {
			curl_setopt($ch, CURLOPT_COOKIEFILE, $this->cookies);
			curl_setopt($ch, CURLOPT_COOKIEJAR, $this->cookies);
		}
		if ($json !== null) {
			$headers[] = 'Content-Type: application/json';
			curl_setopt($ch, CURLOPT_POST, true);
			curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json));
		}
		if ($method !== '') {
			curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
		}
		if ($headers) {
			curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
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
	 * A page loaded in another entity (the entity of the session changed in another tab): nothing read nor written,
	 * 409 entity_changed; a call without the header (a link, another page) is not concerned
	 *
	 * @return void
	 */
	public function testEntityOfThePage()
	{
		global $conf;

		$token = $this->login();
		$here = 'X-Inbox-Entity: '.((int) $conf->entity);
		$other = 'X-Inbox-Entity: '.((int) $conf->entity + 1000);
		$this->assertSame(200, $this->call('/custom/inbox/ajax/account.php?action=list', null, true, '', array($here))['code']);
		$this->assertSame(200, $this->call('/custom/inbox/ajax/account.php?action=list')['code'], 'no header: not checked');
		$read = $this->call('/custom/inbox/ajax/account.php?action=list', null, true, '', array($other));
		$this->assertSame(409, $read['code']);
		$this->assertSame('entity_changed', $read['json']['error']);
		$this->assertSame((int) $conf->entity, $read['json']['entity']);
		$this->assertArrayNotHasKey('accounts', $read['json'], 'nothing read');
		$write = $this->call('/custom/inbox/ajax/account.php', array('action' => 'save', 'token' => $token, 'email' => 'entity@inbox-test.invalid'), true, '', array($other));
		$this->assertSame(409, $write['code']);
		$this->assertSame(0, (int) $this->db->getRow("SELECT COUNT(*) AS n FROM ".$this->db->prefix()."inbox_account WHERE email = 'entity@inbox-test.invalid'")->n, 'nothing written');
		$this->assertSame(200, $this->call('/custom/inbox/ajax/account.php?action=list', null, true, '', array('X-Inbox-Entity: x'))['code'], 'not a number: ignored');
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
		foreach (array('/custom/inbox/ajax/list.php?action=page&folder=99999999',
			'/custom/inbox/ajax/mail.php?action=open&id=99999999', '/custom/inbox/ajax/attachment.php?mode=check&id=99999999&part=0') as $path) {
			$this->assertSame(404, $this->call($path)['code'], 'forged identifier: '.$path);
		}
		$this->assertSame(404, $this->call('/custom/inbox/ajax/body.php?id=99999999')['code']);
		// State and relay write (presence, synchronisation): POST and token (audit INBX-017)
		$this->assertSame(405, $this->call('/custom/inbox/ajax/list.php?action=state&folder=0')['code'], 'state by GET refused');
		$this->assertSame(404, $this->call('/custom/inbox/ajax/list.php', array('action' => 'state', 'folder' => 99999999, 'token' => $token))['code'], 'forged identifier: state');
		$this->assertSame(404, $this->call('/custom/inbox/ajax/list.php', array('action' => 'pump', 'folder' => 99999999, 'token' => $token))['code'], 'relay: forged folder');
		$pump = $this->call('/custom/inbox/ajax/list.php', array('action' => 'pump', 'token' => $token));
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
		// Deep search (lot W7 1.19.0): a mailbox without access is never searched on its server
		$this->assertSame(404, $this->call('/custom/inbox/ajax/list.php', array('action' => 'content', 'account' => (int) $a->id, 'scope' => 'all', 'q' => 'raccords', 'token' => $token))['code'], 'IDOR: deep search');
		$this->assertSame(404, $this->call('/custom/inbox/ajax/list.php', array('action' => 'content', 'account' => (int) $a->id, 'scope' => 'folder', 'folder' => (int) $row->fk_folder, 'q' => 'raccords', 'token' => $token))['code']);
		$sug = $this->call('/custom/inbox/ajax/list.php', array('action' => 'suggest', 'q' => 'dupont'));
		$this->assertSame(array(), $sug['json']['senders'], 'no sender of a mailbox without access');

		$this->assertGreaterThan(0, $access->grant($this->admin, (int) $a->id, (int) $login->rowid, 0, InboxAccess::LEVEL_READ));
		$open = $this->call('/custom/inbox/ajax/mail.php?action=open&id='.$row->rowid);
		$this->assertSame(200, $open['code'], $open['body']);
		$this->assertSame('Demande de prix raccords inox DN25', $open['json']['subject']);
		$page = $this->call('/custom/inbox/ajax/list.php', array('action' => 'page', 'folder' => (int) $row->fk_folder, 'q' => '"raccords inox"'));
		$this->assertSame(200, $page['code'], 'a phrase in quotes, sent in the body');
		$this->assertSame(1, $page['json']['total']);
		$deep = $this->call('/custom/inbox/ajax/list.php', array('action' => 'content', 'account' => (int) $a->id, 'scope' => 'folder', 'folder' => (int) $row->fk_folder, 'q' => 'raccords', 'token' => $token));
		$this->assertSame(200, $deep['code'], $deep['body']);
		$this->assertTrue($deep['json']['complete']);
		$this->assertGreaterThanOrEqual(1, $deep['json']['found']);
		$this->assertSame(400, $this->call('/custom/inbox/ajax/list.php', array('action' => 'content', 'account' => (int) $a->id, 'scope' => 'folder', 'folder' => (int) $row->fk_folder, 'q' => 'from:dupont', 'token' => $token))['code'], 'deep search without a word refused');
		$sug = $this->call('/custom/inbox/ajax/list.php', array('action' => 'suggest', 'q' => 'dupont'));
		$this->assertSame('j.dupont@acme.invalid', $sug['json']['senders'][0]['email'] ?? '', 'known sender suggested');

		$ch = curl_init($this->base.'/custom/inbox/ajax/body.php?id='.$row->rowid);
		curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIEFILE => $this->cookies));
		$res = (string) curl_exec($ch);
		curl_close($ch);
		$this->assertMatchesRegularExpression("/Content-Security-Policy: default-src 'none';[^\r\n]*script-src 'none'/i", $res);
		$this->assertStringNotContainsString(' https:', (string) preg_replace('/^.*Content-Security-Policy:([^\r\n]*).*$/s', '$1', $res), 'no remote picture by default');
		$this->assertMatchesRegularExpression('/X-Content-Type-Options: nosniff/i', $res);

		$this->assertSame(403, $this->call('/custom/inbox/ajax/mail.php', array('action' => 'seen', 'id' => (int) $row->rowid, 'seen' => 1))['code'], 'read without token refused');
		$this->assertSame(405, $this->call('/custom/inbox/ajax/mail.php?action=seen&seen=1&id='.$row->rowid.'&token='.urlencode($token))['code'], 'write by GET refused');

		// Actions of lot W4 (CA-04): token and POST, level « manage » checked by the server whatever the page says
		$act = array('action' => 'run', 'what' => 'move', 'ids' => array((int) $row->rowid), 'folder' => (int) $row->fk_folder);
		$this->assertSame(403, $this->call('/custom/inbox/ajax/action.php', $act)['code'], 'action without token refused');
		$this->assertSame(405, $this->call('/custom/inbox/ajax/action.php?action=run&what=flag&on=1&ids='.$row->rowid.'&token='.urlencode($token))['code'], 'action by GET refused');
		$forged = $this->call('/custom/inbox/ajax/action.php', array('action' => 'run', 'what' => 'flag', 'on' => 1, 'ids' => array(99999999), 'token' => $token));
		$this->assertSame(404, $forged['code'], 'forged mail');
		$readOnly = $this->call('/custom/inbox/ajax/action.php', array('action' => 'run', 'what' => 'archive', 'ids' => array((int) $row->rowid), 'token' => $token));
		$this->assertSame(403, $readOnly['code'], 'level read: no archive');
		$this->assertSame('InboxErrorNeedManage', $readOnly['json']['code']);
		// Journal: it names the mails, so the level « manage » on the mailbox only, even for an admin of the mailboxes
		// (audit INBX-006, 1.23.1); a forged one not found
		$this->assertSame(403, $this->call('/custom/inbox/ajax/action.php?action=journal&id='.$a->id)['code'], 'level read: no journal');
		$me = new User($this->db);
		$me->fetch(0, (string) getenv('INBOX_TEST_LOGIN'));
		(new InboxAccess($this->db))->grant($this->admin, $a->id, $me->id, 0, InboxAccess::LEVEL_MANAGE);
		$journal = $this->call('/custom/inbox/ajax/action.php?action=journal&id='.$a->id);
		$this->assertSame(200, $journal['code'], $journal['body']);
		$this->assertArrayHasKey('lines', $journal['json']);
		$this->assertSame(404, $this->call('/custom/inbox/ajax/action.php?action=journal&id=99999999')['code']);
		$flag = $this->call('/custom/inbox/ajax/action.php', array('action' => 'run', 'what' => 'flag', 'on' => 1, 'ids' => array((int) $row->rowid), 'token' => $token));
		$this->assertSame(200, $flag['code'], 'level read: starred allowed '.$flag['body']);
		$this->assertSame(1, $flag['json']['count']);
		// Assignment and bell (1.9.0): token and POST for the writes, a forged mail or mailbox not found
		$set = array('action' => 'set', 'ids' => array((int) $row->rowid), 'add' => array(99999999));
		$this->assertSame(403, $this->call('/custom/inbox/ajax/assign.php', $set)['code'], 'assign without token refused');
		$this->assertSame(405, $this->call('/custom/inbox/ajax/assign.php?action=set&ids='.$row->rowid.'&add=1&token='.urlencode($token))['code'], 'assign by GET refused');
		$this->assertSame(404, $this->call('/custom/inbox/ajax/assign.php', array('action' => 'set', 'ids' => array(99999999), 'add' => array(1), 'token' => $token))['code']);
		$this->assertSame(403, $this->call('/custom/inbox/ajax/assign.php', $set + array('token' => $token))['code'], 'a person without access to the mailbox');
		$this->assertSame(404, $this->call('/custom/inbox/ajax/assign.php?action=people&id=99999999')['code']);
		$this->assertSame(200, $this->call('/custom/inbox/ajax/notification.php?action=count')['code']);
		$this->assertSame(403, $this->call('/custom/inbox/ajax/notification.php', array('action' => 'read'))['code'], 'read without token refused');
		$open = $this->call('/custom/inbox/ajax/notification.php', array('action' => 'open', 'id' => 99999999, 'token' => $token));
		$this->assertSame('not_found', $open['json']['problem'], 'the notification of nobody');
		// Bandeau Dolibarr (1.13.0): token and POST for the writes, a forged mail not found, a forged piece refused
		$this->assertSame(404, $this->call('/custom/inbox/ajax/dolibarr.php?action=panel&id=99999999')['code']);
		$this->assertSame(403, $this->call('/custom/inbox/ajax/dolibarr.php', array('action' => 'note', 'id' => (int) $row->rowid, 'text' => 'x'))['code'], 'note without token refused');
		$this->assertSame(405, $this->call('/custom/inbox/ajax/dolibarr.php?action=link&id='.$row->rowid.'&type=societe&element=1&token='.urlencode($token))['code'], 'link by GET refused');
		$this->assertSame(404, $this->call('/custom/inbox/ajax/dolibarr.php', array('action' => 'link', 'id' => 99999999, 'type' => 'societe', 'element' => 1, 'token' => $token))['code']);
		// Writing (1.16.0): token and POST always, a forged writing or mail not found, a mail of a mailbox without access too
		$this->assertSame(403, $this->call('/custom/inbox/ajax/compose.php', array('action' => 'open', 'mode' => 'new'))['code'], 'open without token refused');
		$this->assertSame(405, $this->call('/custom/inbox/ajax/compose.php?action=send&compose=0123456789abcdef0123456789abcdef&token='.urlencode($token))['code'], 'send by GET refused');
		$this->assertSame(404, $this->call('/custom/inbox/ajax/compose.php', array('action' => 'send', 'compose' => '0123456789abcdef0123456789abcdef', 'to' => 'x@inbox-test.invalid', 'token' => $token))['code']);
		$this->assertSame(404, $this->call('/custom/inbox/ajax/compose.php', array('action' => 'save', 'compose' => '../../etc', 'token' => $token))['code'], 'a path is not a writing');
		// Queue (1.17.0): a mail of the queue of nobody (or of another user) not found, never sent nor taken back
		$this->assertSame(405, $this->call('/custom/inbox/ajax/compose.php?action=dispatch&id=1&token='.urlencode($token))['code'], 'send now by GET refused');
		$this->assertSame(404, $this->call('/custom/inbox/ajax/compose.php', array('action' => 'dispatch', 'id' => 99999999, 'token' => $token))['code']);
		$this->assertSame(404, $this->call('/custom/inbox/ajax/compose.php', array('action' => 'cancel', 'id' => 99999999, 'token' => $token))['code']);
		// Templates, documents, redirect (1.18.0): a forged writing, document, mail or template not found
		$this->assertSame(404, $this->call('/custom/inbox/ajax/compose.php', array('action' => 'attachdoc', 'compose' => '0123456789abcdef0123456789abcdef', 'piece' => 'societe:1', 'name' => '../../conf/conf.php', 'token' => $token))['code']);
		$this->assertSame(405, $this->call('/custom/inbox/ajax/compose.php?action=redirect&mail='.$row->rowid.'&to=x@inbox-test.invalid&token='.urlencode($token))['code'], 'redirect by GET refused');
		$this->assertSame(404, $this->call('/custom/inbox/ajax/compose.php', array('action' => 'redirect', 'mail' => 99999999, 'to' => 'x@inbox-test.invalid', 'token' => $token))['code']);
		$this->assertSame(403, $this->call('/custom/inbox/ajax/template.php', array('action' => 'save', 'label' => 'ZzTpl x'))['code'], 'save without token refused');
		$this->assertSame(404, $this->call('/custom/inbox/ajax/template.php?action=list&id=99999999')['code'], 'tab of a mailbox not managed');
		$this->assertSame(404, $this->call('/custom/inbox/ajax/template.php?action=get&template=99999999')['code']);
	}

	/**
	 * Voice typing (1.28.0): the consent given only by POST with the token, for a known receiver and a mailbox the user
	 * may dictate from, never written by the generic settings; withdrawn the same way
	 *
	 * @return void
	 */
	public function testDictationConsent()
	{
		global $conf;

		require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
		dol_include_once('/inbox/class/inboxdictation.class.php');
		$token = $this->login();
		$me = new User($this->db);
		$this->assertGreaterThan(0, $me->fetch(0, (string) getenv('INBOX_TEST_LOGIN')));
		$before = getDolGlobalString('INBOX_DICTATION_ENABLED');
		dolibarr_set_const($this->db, 'INBOX_DICTATION_ENABLED', '1', 'chaine', 0, '', $conf->entity);
		$a = $this->box(true, array('status' => InboxAccount::STATUS_ACTIVE));
		$access = new InboxAccess($this->db);
		$access->grant($this->admin, $a->id, $me->id, 0, InboxAccess::LEVEL_SEND);
		InboxAccess::clearCache();
		$params = new InboxUserParam($this->db);
		$params->remove($me, InboxDictation::PARAM);
		try {
			$get = $this->call('/custom/inbox/ajax/dictation.php?action=get');
			$this->assertSame(200, $get['code']);
			$this->assertSame(1, $get['json']['enabled']);
			$consent = array('action' => 'consent', 'provider' => 'google', 'account' => (int) $a->id);
			$this->assertSame(403, $this->call('/custom/inbox/ajax/dictation.php', $consent)['code'], 'consent without token refused');
			$this->assertSame(405, $this->call('/custom/inbox/ajax/dictation.php?action=consent&provider=google&account='.$a->id.'&token='.urlencode($token))['code'], 'consent by GET refused');
			$this->assertSame(403, $this->call('/custom/inbox/ajax/dictation.php', array('provider' => 'evil') + $consent + array('token' => $token))['code'], 'unknown receiver');
			$this->assertSame(403, $this->call('/custom/inbox/ajax/dictation.php', array('account' => 99999999) + $consent + array('token' => $token))['code'], 'mailbox not allowed');
			// Never written by the generic settings (the journal would be bypassed)
			$this->assertSame(400, $this->call('/custom/inbox/ajax/userparam.php', array('action' => 'set', 'param' => 'dictation_consent', 'value' => '{"google":1}', 'token' => $token))['code']);
			$ok = $this->call('/custom/inbox/ajax/dictation.php', $consent + array('token' => $token));
			$this->assertSame(200, $ok['code'], $ok['body']);
			$this->assertArrayHasKey('google', $ok['json']['consents']);
			$this->assertSame(403, $this->call('/custom/inbox/ajax/dictation.php', array('action' => 'revoke', 'provider' => 'google'))['code'], 'withdrawal without token refused');
			$gone = $this->call('/custom/inbox/ajax/dictation.php', array('action' => 'revoke', 'provider' => 'google', 'token' => $token));
			$this->assertSame(array(), (array) $gone['json']['consents']);
			// A mailbox excluded: no consent from it
			$a->setConnectorConfig(array('dictation_off' => 1));
			$a->update($this->admin);
			$this->assertSame(403, $this->call('/custom/inbox/ajax/dictation.php', $consent + array('token' => $token))['code'], 'mailbox excluded');
		} finally {
			$params->remove($me, InboxDictation::PARAM);
			$this->db->query("DELETE FROM ".$this->db->prefix()."inbox_action_log WHERE action IN ('dictation_on', 'dictation_off') AND fk_user = ".((int) $me->id));
			dolibarr_set_const($this->db, 'INBOX_DICTATION_ENABLED', $before === '' ? '0' : $before, 'chaine', 0, '', $conf->entity);
		}
	}

	/**
	 * DMARC reports (1.111.0): session required, a mail out of reach is 404, values checked before any DNS query
	 *
	 * @return void
	 */
	public function testDmarcEndpoint()
	{
		$this->assertSame(401, $this->call('/custom/inbox/ajax/dmarc.php?action=analyze&id=1&part=0', null, false)['code']);
		$this->login();
		$this->assertSame(404, $this->call('/custom/inbox/ajax/dmarc.php?action=analyze&id=999999999&part=0')['code']);
		$this->assertSame(422, $this->call('/custom/inbox/ajax/dmarc.php?action=dns&domain='.rawurlencode('x y;rm'))['code']);
		$this->assertSame(422, $this->call('/custom/inbox/ajax/dmarc.php?action=ptr&ip=not-an-ip')['code']);
		$this->assertSame(404, $this->call('/custom/inbox/ajax/dmarc.php?action=other')['code']);
	}
}
