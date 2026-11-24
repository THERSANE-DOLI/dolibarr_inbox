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
 * \file    inbox/test/phpunit/InboxSpamEngineTest.php
 * \ingroup inbox
 * \brief   1.88.0: anti-spam engine of inbox (client of Rspamd on recorded answers, judgement at the synchronisation,
 *          learning queue, training)
 */

// From the command line only, never from the web: test/ stays in the Git branches
if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

require_once __DIR__.'/InboxImapTestCase.php';
dol_include_once('/inbox/class/inboxspamcheck.class.php');
dol_include_once('/inbox/class/inboxactions.class.php');

/**
 * Rspamd answering recorded answers, the calls kept
 */
class InboxRspamdRecorded extends InboxSpamRspamd
{
	/** @var array<string,array{code:int,body:string}|null> Path => answer */
	public $answers = array();

	/** @var array<int,array{url:string,headers:array<string,string>}> */
	public $calls = array();

	/**
	 * @param string               $url     URL
	 * @param string               $body    Body
	 * @param array<string,string> $headers Headers
	 * @return array{code:int,body:string}|null
	 */
	protected function http($url, $body, array $headers)
	{
		$this->calls[] = array('url' => $url, 'headers' => $headers);
		$path = (string) parse_url($url, PHP_URL_PATH);
		if (!array_key_exists($path, $this->answers) || $this->answers[$path] === null) {
			$this->error = 'unreachable';
			return null;
		}

		return $this->answers[$path];
	}
}

/**
 * Engine of the tests: spam when the subject says so
 */
class InboxSpamFake implements InboxSpamEngine
{
	/** @var string */
	public $error = '';

	/** @var array<int,array{spam:bool,subject:string}> */
	public $learned = array();

	/** @var int */
	public $checked = 0;

	/**
	 * @param string $raw Source
	 * @return array<string,mixed>
	 */
	public function check($raw)
	{
		$this->checked++;
		$spam = strpos((string) $raw, 'ZzSpam') !== false;

		return array('spam' => $spam, 'action' => $spam ? 'add header' : 'no action', 'score' => $spam ? 9.5 : 0.4, 'required' => 7.0, 'symbols' => $spam ? array('BAYES_SPAM') : array());
	}

	/**
	 * @param string $raw  Source
	 * @param bool   $spam Spam
	 * @return bool
	 */
	public function learn($raw, $spam)
	{
		$this->learned[] = array('spam' => (bool) $spam, 'subject' => preg_match('/^Subject: (.*)$/mi', (string) $raw, $m) ? trim($m[1]) : '');

		return true;
	}

	/**
	 * @return array<string,mixed>
	 */
	public function status()
	{
		return array('ok' => true, 'version' => 'fake', 'learned_spam' => 0, 'learned_ham' => 0, 'error' => '');
	}
}

/**
 * The settings of the engine are put back after each test
 */
class InboxSpamEngineTest extends InboxImapTestCase
{
	/** @var array<string,mixed> */
	protected $saved = array();

	/**
	 * @return void
	 */
	protected function setUp(): void
	{
		global $conf;

		parent::setUp();
		foreach (array('INBOX_RSPAMD_ENABLED', 'INBOX_RSPAMD_MOVE', 'INBOX_RSPAMD_LEARN', 'INBOX_RSPAMD_SCOPE', 'INBOX_RSPAMD_LAST_ERROR') as $k) {
			$this->saved[$k] = $conf->global->$k ?? null;
		}
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
		parent::tearDown();
	}

	/**
	 * @return void
	 */
	public function testRspamdClient()
	{
		$r = new InboxRspamdRecorded('http://127.0.0.1:11333/', 'http://127.0.0.1:11334', 'pw', false);
		$r->answers['/checkv2'] = array('code' => 200, 'body' => (string) json_encode(array('action' => 'add header', 'score' => 8.25, 'required_score' => 7,
			'symbols' => array('R_SPF_ALLOW' => array('score' => -0.2), 'BAYES_SPAM' => array('score' => 5.1), 'URIBL_BLACK' => array('score' => 2.5), 'ARC_NA' => array('score' => 0)))));
		$v = $r->check("Subject: x\r\n\r\nbody");
		$this->assertSame(array('spam' => true, 'action' => 'add header', 'score' => 8.25, 'required' => 7.0, 'symbols' => array('BAYES_SPAM', 'URIBL_BLACK', 'R_SPF_ALLOW')), $v);
		$this->assertSame('http://127.0.0.1:11333/checkv2', $r->calls[0]['url']);
		$this->assertStringContainsString('"rbl"', $r->calls[0]['headers']['Settings'], 'the outside lists off unless allowed');
		$on = new InboxRspamdRecorded('http://127.0.0.1:11333', 'http://127.0.0.1:11334', 'pw', true);
		$on->answers = $r->answers;
		$on->check('x');
		$this->assertArrayNotHasKey('Settings', $on->calls[0]['headers']);

		$r->answers['/checkv2'] = array('code' => 200, 'body' => '{"action":"no action","score":-1.5,"required_score":15}');
		$this->assertFalse($r->check('x')['spam']);
		// The threshold « add header » of Rspamd decides, not the action (made for a mail server), as Rspamd 3.11 answers
		$r->answers['/checkv2'] = array('code' => 200, 'body' => '{"score":11.35,"required_score":15,"action":"soft reject","thresholds":{"reject":15,"add header":6,"greylist":4}}');
		$v = $r->check('x');
		$this->assertTrue($v['spam']);
		$this->assertSame('add header', $v['action']);
		$this->assertSame(6.0, $v['required']);
		// A module of a mail server ended the check: no verdict, said
		$r->answers['/checkv2'] = array('code' => 200, 'body' => '{"passthrough_module":"greylist","score":0,"action":"soft reject","thresholds":{"add header":6}}');
		$this->assertNull($r->check('x'));
		$this->assertStringContainsString('greylist', $r->error);
		$r->answers['/checkv2'] = array('code' => 500, 'body' => '{"error":"oops"}');
		$this->assertNull($r->check('x'));
		$this->assertStringContainsString('oops', $r->error);

		// Learning: the password of the controller; already learned is fine
		$r->answers['/learnspam'] = array('code' => 208, 'body' => '{"error":"already learned"}');
		$this->assertTrue($r->learn('x', true));
		$this->assertSame('pw', end($r->calls)['headers']['Password']);
		$r->answers['/learnham'] = array('code' => 204, 'body' => '');
		$this->assertTrue($r->learn('x', false), 'nothing to learn in it');
		$r->answers['/learnham'] = array('code' => 401, 'body' => '{"error":"Unauthorized"}');
		$this->assertFalse($r->learn('x', false));

		// State
		$r->answers['/ping'] = array('code' => 200, 'body' => "pong\r\n");
		$r->answers['/stat'] = array('code' => 200, 'body' => (string) json_encode(array('version' => '3.11.1', 'statfiles' => array(array('symbol' => 'BAYES_SPAM', 'revision' => 210), array('symbol' => 'BAYES_HAM', 'revision' => 35)))));
		$this->assertSame(array('ok' => true, 'version' => '3.11.1', 'learned_spam' => 210, 'learned_ham' => 35, 'error' => ''), $r->status());
		$r->answers['/ping'] = null;
		$this->assertFalse($r->status()['ok']);

		$this->assertTrue(InboxSpamRspamd::validUrl('http://127.0.0.1:11333'));
		$this->assertFalse(InboxSpamRspamd::validUrl('http://user:pw@host:11333'));
		$this->assertFalse(InboxSpamRspamd::validUrl('file:///etc/passwd'));
	}

	/**
	 * Judged at the synchronisation: spam to the junk, never a known sender nor a mail « not junk »; learning of
	 * « Junk » / « Not junk »; training
	 *
	 * @return void
	 */
	public function testJudgeAndLearn()
	{
		global $conf;

		$a = $this->ready();
		$p = $this->db->prefix();
		$junk = $this->db->getRow("SELECT rowid, path FROM ".$p."inbox_folder WHERE fk_account = ".((int) $a->id)." AND role = 'junk' AND status = 1");
		if (!is_object($junk)) {
			$this->markTestSkipped('the test mailbox has no junk folder');
		}
		$conf->global->INBOX_RSPAMD_ENABLED = 0;
		foreach (array('sp1', 'ok1', 'sp2', 'sp3') as $id) {
			$this->append('INBOX', $id);
		}
		$this->sync($a);
		// The subjects: spam for sp*, a known sender for sp2, « not junk » for sp3
		$rows = array();
		foreach (array('sp1', 'ok1', 'sp2', 'sp3') as $id) {
			$row = $this->db->getRow("SELECT m.rowid FROM ".$p."inbox_mail as m INNER JOIN ".$p."inbox_folder as f ON f.rowid = m.fk_folder AND f.role = 'inbox' WHERE m.fk_account = ".((int) $a->id)." AND m.message_id = '".$this->db->escape($id.self::MARK)."'");
			$this->assertTrue(is_object($row), $id);
			$rows[$id] = (int) $row->rowid;
			$subject = strpos($id, 'sp') === 0 ? 'ZzAct ZzSpam '.$id : 'ZzAct '.$id;
			$this->db->query("UPDATE ".$p."inbox_mail SET spam_check = 0".($id === 'sp3' ? ", keywords = '\$NotJunk'" : "")." WHERE rowid = ".$rows[$id]);
		}
		// The fake engine judges by the source: the subject on the server says ZzAct only, so the source is rewritten
		$engine = new InboxSpamFake();
		$this->db->query("INSERT INTO ".$p."socpeople (entity, lastname, email, statut, datec, fk_user_creat) VALUES (1, 'inboxtest_known', 'client@example.invalid', 1, '".$this->db->idate(dol_now())."', ".((int) $this->admin->id).")");
		$conf->global->INBOX_RSPAMD_ENABLED = 1;
		$conf->global->INBOX_RSPAMD_MOVE = 1;
		$conf->global->INBOX_RSPAMD_SCOPE = 'all';
		$check = new InboxSpamCheck($this->db, $engine);
		// The senders of the test mails are all client@example.invalid: known now; the spam is judged, not moved
		$res = $check->checkPending($this->admin, (int) $a->id, microtime(true) + 20);
		$this->assertSame(0, $res['moved'], 'a known sender is never moved by a score');
		$this->assertSame(0, $res['spam'], 'the source on the server has no ZzSpam: judged legitimate');
		$this->assertSame(4, $engine->checked);
		$v = InboxSpamCheck::verdictOf($this->db->getRow("SELECT spam_engine, spam_score, spam_required, spam_action, spam_symbols FROM ".$p."inbox_mail WHERE rowid = ".$rows['ok1']));
		$this->assertTrue($v['local']);
		$this->assertFalse($v['spam']);
		$this->assertSame(0.4, $v['score']);
		$this->db->query("DELETE FROM ".$p."socpeople WHERE lastname = 'inboxtest_known'");

		// A spam of an unknown sender goes to the junk; a mail « not junk » stays
		$spamEngine = new class extends InboxSpamFake {
			/**
			 * @param string $raw Source
			 * @return array<string,mixed>
			 */
			public function check($raw)
			{
				$this->checked++;
				return array('spam' => true, 'action' => 'reject', 'score' => 20.0, 'required' => 15.0, 'symbols' => array('BAYES_SPAM'));
			}
		};
		$this->db->query("UPDATE ".$p."inbox_mail SET spam_check = 0 WHERE rowid IN (".$rows['sp1'].", ".$rows['sp3'].")");
		$res = (new InboxSpamCheck($this->db, $spamEngine))->checkPending($this->admin, (int) $a->id, microtime(true) + 20);
		$this->assertSame(2, $res['spam']);
		$this->assertSame(1, $res['moved'], 'the mail « not junk » stays');
		$this->assertSame(array(), $this->onServer('INBOX', 'sp1'));
		$this->assertNotEmpty($this->onServer((string) $junk->path, 'sp1'));
		$this->assertNotEmpty($this->onServer('INBOX', 'sp3'));
		$this->assertTrue(is_object($this->db->getRow("SELECT rowid FROM ".$p."inbox_action_log WHERE action = 'spam' AND fk_account = ".((int) $a->id))), 'journal');

		// « Junk » by a user: queued, then learned by the job
		$conf->global->INBOX_RSPAMD_LEARN = 1;
		$run = (new InboxActions($this->db))->run($this->admin, 'junk', array($rows['ok1']));
		$this->assertTrue($run['ok'], $run['error']);
		$q = $this->db->getRow("SELECT rowid, is_spam, state FROM ".$p."inbox_spam_learn WHERE fk_mail IN (".implode(',', array_map('intval', array_values($run['ids']) ?: array(0))).")");
		$this->assertTrue(is_object($q), 'queued at its new place');
		$this->assertSame(1, (int) $q->is_spam);
		$learner = new InboxSpamCheck($this->db, $engine);
		$out = $learner->processQueue(microtime(true) + 20);
		$this->assertGreaterThanOrEqual(1, $out['learned']);
		$this->assertSame(1, (int) $this->db->getRow("SELECT state FROM ".$p."inbox_spam_learn WHERE rowid = ".((int) $q->rowid))->state);
		$this->assertTrue($engine->learned[0]['spam']);

		// Training: the junk as spam (sp1 and ok1 are there now), nothing queued twice
		$t = $learner->train($this->admin);
		$this->assertGreaterThanOrEqual(1, $t['spam']);
		$this->assertSame(0, $learner->train($this->admin)['spam'], 'never queued twice');
		$this->db->query("DELETE FROM ".$p."inbox_action_log WHERE fk_account = ".((int) $a->id));
	}
}
