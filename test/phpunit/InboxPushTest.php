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
 * \file    test/phpunit/InboxPushTest.php
 * \ingroup inbox
 * \brief   Push drivers (1.124.0): the common part with a fake driver, the tokens and updates of Mercure
 */

// From the command line only, never from the web: test/ stays in the Git branches
if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

require_once __DIR__.'/InboxTestCase.php';
require_once __DIR__.'/../../class/inboxevent.class.php';
require_once __DIR__.'/../../class/inboxpush.class.php';
require_once __DIR__.'/../../class/push/inboxpushmercure.class.php';

/**
 * A driver that keeps what it is given: nothing outside the drivers may depend on Mercure
 */
class InboxPushFakeDriver implements InboxPushDriverInterface
{
	/** @var array<int,array<int,array<string,mixed>>> */
	public $published = array();
	/** @var string[] */
	public $topics = array();
	/** @var bool */
	public $fail = false;

	/** @return string */
	public function code()
	{
		return 'fake';
	}
	/** @return string */
	public function label()
	{
		return 'Fake';
	}
	/** @return array<string,array<string,mixed>> */
	public function fields()
	{
		return array();
	}
	/**
	 * @param array<string,string> $values    Values
	 * @param string               $namespace Namespace
	 * @return void
	 */
	public function configure(array $values, $namespace)
	{
	}
	/** @return bool */
	public function isConfigured()
	{
		return true;
	}
	/**
	 * @param array<int,array<string,mixed>> $messages Messages
	 * @return bool
	 */
	public function publish(array $messages)
	{
		$this->published[] = $messages;
		return !$this->fail;
	}
	/**
	 * @param string[] $topics  Topics
	 * @param int      $userId  User
	 * @param int      $expires End
	 * @return array<string,mixed>
	 */
	public function subscriber(array $topics, $userId, $expires)
	{
		$this->topics = $topics;
		return array('topics' => $topics);
	}
	/** @return string */
	public function jsFile()
	{
		return '/inbox/js/push/fake.js';
	}
	/** @return array{ok:bool,message:string,ms:int} */
	public function test()
	{
		return array('ok' => true, 'message' => '', 'ms' => 0);
	}
	/** @return string */
	public function error()
	{
		return $this->fail ? 'down' : '';
	}
}

/**
 * Mercure without network: the bodies it would post
 */
class InboxPushMercureSpy extends InboxPushMercure
{
	/** @var array<int,array{url:string,jwt:string,bodies:string[]}> */
	public $sent = array();

	/**
	 * @param string   $url    Hub
	 * @param string   $jwt    Token
	 * @param string[] $bodies Bodies
	 * @return array<int,array{code:int,error:string}>
	 */
	protected function send($url, $jwt, array $bodies)
	{
		$this->sent[] = array('url' => $url, 'jwt' => $jwt, 'bodies' => $bodies);
		return array_fill(0, count($bodies), array('code' => 200, 'error' => ''));
	}
}

/**
 * Push drivers of the instant refresh
 */
class InboxPushTest extends InboxTestCase
{
	/** @var string Setting before the test */
	protected $mode;

	/**
	 * @return void
	 */
	protected function setUp(): void
	{
		global $conf;

		parent::setUp();
		$this->mode = getDolGlobalString('INBOX_REACTIVITY');
		$conf->global->INBOX_REACTIVITY = 'instant';
		@unlink(InboxPush::pauseFile());
	}

	/**
	 * @return void
	 */
	protected function tearDown(): void
	{
		global $conf;

		$conf->global->INBOX_REACTIVITY = $this->mode;
		@unlink(InboxPush::pauseFile());
		InboxPush::reset();
		parent::tearDown();
	}

	/**
	 * @param string $jwt Token
	 * @return array{0:array<string,mixed>,1:array<string,mixed>,2:string} Header, payload, signed part
	 */
	protected function decode($jwt)
	{
		$p = explode('.', $jwt);
		$this->assertCount(3, $p);
		$d = function ($s) {
			return json_decode(base64_decode(strtr($s, '-_', '+/')), true);
		};

		return array($d($p[0]), $d($p[1]), $p[0].'.'.$p[1]);
	}

	/**
	 * @return InboxPushMercureSpy
	 */
	protected function mercure()
	{
		$m = new InboxPushMercureSpy();
		$m->configure(array(
			'hub_url' => 'https://push.inbox-test.invalid/.well-known/mercure',
			'publish_url' => 'http://127.0.0.1:3001/.well-known/mercure',
			'issuer' => 'https://erp.inbox-test.invalid',
			'publisher_key' => 'publisher-key-publisher-key-publisher-key',
			'subscriber_key' => 'subscriber-key-subscriber-key-subscriber',
		), 'abc123');

		return $m;
	}

	/**
	 * The events of the request go to the driver once, each on the topic of its mailboxes or of its user only
	 *
	 * @return void
	 */
	public function testEventsOfTheRequestGoToTheDriverOnce()
	{
		$fake = new InboxPushFakeDriver();
		InboxPush::reset($fake);
		$box = $this->box(true, array('status' => InboxAccount::STATUS_ACTIVE));
		$alice = $this->user('alice');
		$events = new InboxEvent($this->db);
		$e1 = $events->emit($box->id, 'tag', array(11, 12), 3, $alice);
		$events->emitUsers(array($alice->id), 'access', $this->admin);
		$pending = InboxPush::pending();
		$this->assertCount(2, $pending);
		$this->assertSame('account/'.$box->id, $pending[0]['topic']);
		$this->assertSame($e1, $pending[0]['data']['id'], 'same id as the journal: the browser does not apply it twice');
		$this->assertSame(array(11, 12), $pending[0]['data']['ids']);
		$this->assertSame('user/'.$alice->id, $pending[1]['topic']);

		$this->assertTrue(InboxPush::flush());
		$this->assertCount(1, $fake->published, 'one delivery for the whole request');
		$this->assertCount(2, $fake->published[0]);
		$this->assertSame(array(), InboxPush::pending());
		// Numbers only, as the journal
		$json = json_encode($fake->published);
		$this->assertStringNotContainsString('@', $json);
		$this->assertStringNotContainsString('Secret', $json);

		// Many events in one request: MAX_EVENTS delivered, the rest caught up by the light call
		for ($i = 1; $i <= InboxPush::MAX_EVENTS + 10; $i++) {
			InboxPush::queue('account/'.$box->id, array('id' => 1000 + $i, 'type' => 'flag'));
		}
		$this->assertTrue(InboxPush::flush());
		$this->assertCount(InboxPush::MAX_EVENTS, $fake->published[1]);
		$this->assertSame(array(), InboxPush::pending());
	}

	/**
	 * Fast mode, or no driver configured: nothing queued, the journal alone
	 *
	 * @return void
	 */
	public function testOtherModesQueueNothing()
	{
		global $conf;

		$conf->global->INBOX_REACTIVITY = 'fast';
		InboxPush::reset();
		$box = $this->box(true, array('status' => InboxAccount::STATUS_ACTIVE));
		$this->assertGreaterThan(0, (new InboxEvent($this->db))->emit($box->id, 'flag', array(5)));
		$this->assertSame(array(), InboxPush::pending());
		$this->assertNull(InboxPush::active());

		$conf->global->INBOX_REACTIVITY = 'instant';
		$conf->global->INBOX_PUSH_DRIVER = 'mercure';
		InboxPush::reset();
		$this->assertNull(InboxPush::active(), 'Mercure without its settings: not active');
		$this->assertFalse(InboxPush::queue('account/1', array('id' => 1)));
	}

	/**
	 * A failure never breaks the action: false, the error kept for the administrator
	 *
	 * @return void
	 */
	public function testFailureIsKept()
	{
		$fake = new InboxPushFakeDriver();
		$fake->fail = true;
		InboxPush::reset($fake);
		InboxPush::clearError();
		InboxPush::queue('account/1', array('id' => 1, 'type' => 'flag'));
		$this->assertFalse(InboxPush::flush());
		$last = InboxPush::lastError();
		$this->assertSame('down', $last['message'], 'kept without the database (closed at the end of the request)');
		$fake->fail = false;
		// A failure a moment ago: the next requests do not try (they never wait for a hub that is down)
		$this->assertTrue(InboxPush::paused());
		$sent = count($fake->published);
		InboxPush::queue('account/1', array('id' => 2, 'type' => 'flag'));
		$this->assertFalse(InboxPush::flush());
		$this->assertCount($sent, $fake->published, 'nothing tried during the pause');
		// After the pause: delivered, failure and pause cleared
		touch(InboxPush::pauseFile(), time() - InboxPush::PAUSE - 1);
		$this->assertFalse(InboxPush::paused());
		InboxPush::queue('account/1', array('id' => 3, 'type' => 'flag'));
		$this->assertTrue(InboxPush::flush());
		$this->assertFalse(is_file(InboxPush::pauseFile()));
		$this->assertNull(InboxPush::lastError(), 'cleared by the next success');
	}

	/**
	 * The error shown to the administrator never carries HTML: neither the one written by a driver nor a file put there
	 *
	 * @return void
	 */
	public function testErrorIsPlainText()
	{
		$this->assertSame('Mercure: HTTP 500', InboxPush::safeText('Mercure: HTTP 500'));
		$this->assertSame('Mercure: Couldn t connect to server', InboxPush::safeText("Mercure: Couldn't connect to server"));
		foreach (array('<script>alert(1)</script>', '<a href="javascript:alert(1)">x</a>', '<span onmouseover=alert(1)>x</span>', '&lt;img src=x onerror=alert(1)&gt;', "\"><svg/onload=alert(1)>") as $evil) {
			$clean = InboxPush::safeText($evil);
			$this->assertDoesNotMatchRegularExpression('/[<>"\'&=]/', $clean, $evil);
		}
		// A file written by something else: cleaned when it is read too
		$dir = dirname(InboxPush::errorFile());
		if (!is_dir($dir)) {
			dol_mkdir($dir);
		}
		file_put_contents(InboxPush::errorFile(), json_encode(array('at' => 'x', 'message' => '<span onmouseover="alert(1)">down</span>')));
		$last = InboxPush::lastError();
		$this->assertSame(0, $last['at']);
		$this->assertDoesNotMatchRegularExpression('/[<>"=]/', $last['message']);
		file_put_contents(InboxPush::errorFile(), json_encode(array('at' => 1, 'message' => array('<b>'))));
		$this->assertNull(InboxPush::lastError(), 'not a text: ignored');
		InboxPush::clearError();
	}

	/**
	 * The browser gets the topics of the mailboxes of its token and of its user, nothing else
	 *
	 * @return void
	 */
	public function testSubscriberTopics()
	{
		$fake = new InboxPushFakeDriver();
		InboxPush::reset($fake);
		$sub = InboxPush::subscriber(7, array(3, 5, 0, -2), dol_now() + 900);
		$this->assertSame('fake', $sub['driver']);
		$this->assertSame(array('user/7', 'account/3', 'account/5'), $fake->topics);
	}

	/**
	 * Mercure 1: token of a subscriber narrowed to his topics, signed with the key of the subscribers, no other key
	 *
	 * @return void
	 */
	public function testMercureSubscriberToken()
	{
		$m = $this->mercure();
		$exp = dol_now() + 900;
		$sub = $m->subscriber(array('user/7', 'account/3', 'evil/1'), 7, $exp);
		$this->assertStringStartsWith('https://push.inbox-test.invalid/.well-known/mercure?match_urlpattern=', $sub['url']);
		$this->assertStringNotContainsString('authorization', $sub['url'], 'never the token in the address');
		list($h, $p, $signed) = $this->decode($sub['token']);
		$this->assertSame('at+jwt', $h['typ']);
		$this->assertSame('HS256', $h['alg']);
		$this->assertSame('https://erp.inbox-test.invalid', $p['iss']);
		$this->assertSame('https://push.inbox-test.invalid/.well-known/mercure', $p['aud'], 'the address of the browsers only, never the inner one');
		$this->assertSame($exp, $p['exp']);
		$this->assertCount(1, $p['authorization_details']);
		$g = $p['authorization_details'][0];
		$this->assertSame('https://mercure.rocks/authorization-detail', $g['type']);
		$this->assertSame(array('subscribe'), $g['actions']);
		$this->assertSame(array(
			array('match' => 'https://inbox.invalid/abc123/user/7'),
			array('match' => 'https://inbox.invalid/abc123/account/3'),
		), $g['topics'], 'exact topics, the unknown one left out');
		$sig = rtrim(strtr(base64_encode(hash_hmac('sha256', $signed, 'subscriber-key-subscriber-key-subscriber', true)), '+/', '-_'), '=');
		$this->assertStringEndsWith('.'.$sig, $sub['token']);
		$this->assertStringNotContainsString('publisher-key', json_encode($sub));
	}

	/**
	 * Mercure 1: private updates on the server address, numbers only, token of publication of this instance only
	 *
	 * @return void
	 */
	public function testMercurePublish()
	{
		$m = $this->mercure();
		$this->assertTrue($m->publish(array(
			array('topic' => 'account/3', 'id' => 40, 'data' => array('id' => 40, 'type' => 'tag', 'account' => 3, 'ids' => array(1, 2))),
			array('topic' => '../evil', 'id' => 41, 'data' => array('id' => 41)),
		)));
		$this->assertCount(1, $m->sent);
		$this->assertSame('http://127.0.0.1:3001/.well-known/mercure', $m->sent[0]['url'], 'the address for the server');
		$this->assertCount(1, $m->sent[0]['bodies'], 'unknown topic never published');
		parse_str($m->sent[0]['bodies'][0], $body);
		$this->assertSame('on', $body['private']);
		$this->assertSame('https://inbox.invalid/abc123/account/3', $body['topic']);
		$this->assertSame(array('id' => 40, 'type' => 'tag', 'account' => 3, 'ids' => array(1, 2)), json_decode($body['data'], true));
		list($h, $p, $signed) = $this->decode($m->sent[0]['jwt']);
		$this->assertSame(array('publish'), $p['authorization_details'][0]['actions']);
		$this->assertSame(array(array('match' => 'https://inbox.invalid/abc123/*', 'match_type' => 'urlpattern')), $p['authorization_details'][0]['topics']);
		$sig = rtrim(strtr(base64_encode(hash_hmac('sha256', $signed, 'publisher-key-publisher-key-publisher-key', true)), '+/', '-_'), '=');
		$this->assertStringEndsWith('.'.$sig, $m->sent[0]['jwt']);
	}

	/**
	 * Settings: addresses checked, Mercure in the list, an unknown driver refused
	 *
	 * @return void
	 */
	public function testDriversAndAddresses()
	{
		InboxPush::reset();
		$this->assertArrayHasKey('mercure', InboxPush::drivers());
		$this->assertSame('', InboxPush::drivers()['mercure']['doc'], 'its guide is in the page, no outside link');
		$this->assertInstanceOf('InboxPushMercure', InboxPush::make('mercure'));
		$this->assertNull(InboxPush::make('nope'));
		$this->assertSame('INBOX_PUSH_MERCURE_HUB_URL', InboxPush::constName('mercure', 'hub_url'));
		$this->assertSame('', InboxPush::cleanUrl('ftp://push.example.com/'));
		$this->assertSame('', InboxPush::cleanUrl('https://user:pass@push.example.com/'));
		$this->assertSame('', InboxPush::cleanUrl('https://push.example.com/?authorization=x'));
		$this->assertSame('https://push.example.com/.well-known/mercure', InboxPush::cleanUrl(' https://push.example.com/.well-known/mercure '));
		// Given to the browsers with their token: https, http only on the computer itself
		$this->assertSame('', InboxPush::cleanUrl('http://push.example.com/.well-known/mercure', true));
		$this->assertSame('', InboxPush::cleanUrl('http://10.0.0.5:3001/.well-known/mercure', true));
		$this->assertSame('http://push.example.com/x', InboxPush::cleanUrl('http://push.example.com/x'), 'the address of the server may be http');
		foreach (array('http://localhost:3001/m', 'http://127.0.0.1:3001/m', 'http://[::1]:3001/m', 'http://hub.localhost/m', 'https://push.example.com/m') as $ok) {
			$this->assertSame($ok, InboxPush::cleanUrl($ok, true), $ok);
		}
		$http = new InboxPushMercure();
		$http->configure(array('hub_url' => 'http://push.example.com/.well-known/mercure', 'issuer' => 'x', 'publisher_key' => 'a', 'subscriber_key' => 'b'), 'ns');
		$this->assertFalse($http->isConfigured(), 'an http address for the browsers is never used');
		$m = new InboxPushMercure();
		$m->configure(array('hub_url' => 'https://push.example.com/.well-known/mercure', 'issuer' => 'x', 'publisher_key' => 'a'), 'ns');
		$this->assertFalse($m->isConfigured(), 'a key missing');
		$this->assertNull($m->subscriber(array('user/1'), 1, dol_now() + 60));
	}
}
