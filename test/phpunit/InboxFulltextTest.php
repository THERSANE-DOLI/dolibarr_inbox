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
 * \file    inbox/test/phpunit/InboxFulltextTest.php
 * \ingroup inbox
 * \brief   1.20.0: Meilisearch (option, key limited to the prefix, one index per mailbox, indexing, search)
 */

// From the command line only, never from the web: test/ stays in the Git branches
if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

require_once __DIR__.'/InboxTestCase.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
dol_include_once('/inbox/class/inboxsync.class.php');
dol_include_once('/inbox/class/inboxsearchsql.class.php');
dol_include_once('/inbox/class/inboxfulltext.class.php');

/**
 * Full text search with Meilisearch. Needs the test Meilisearch of dev/dovecot/run.sh (test key only).
 */
class InboxFulltextTest extends InboxTestCase
{
	const MEILI_URL = 'http://127.0.0.1:17700';
	const MEILI_MASTER = 'inbox-meili-test-master-key';

	/** @var \Webklex\PHPIMAP\Client|null */
	protected $other = null;

	/** @var string[] */
	protected $appended = array();

	/** @var string Prefix of this run */
	protected $prefix = '';

	/**
	 * @return void
	 */
	protected function setUp(): void
	{
		parent::setUp();
		$this->prefix = 'inboxtest'.getmypid();
	}

	/**
	 * @return void
	 */
	protected function tearDown(): void
	{
		global $conf;

		$master = new InboxMeili(self::MEILI_URL, self::MEILI_MASTER);
		foreach ($master->indexes($this->prefix) as $uid) {
			$master->deleteIndex($uid);
		}
		if (getDolGlobalString('INBOX_MEILI_WORKKEY_UID') !== '') {
			$master->deleteKey(getDolGlobalString('INBOX_MEILI_WORKKEY_UID'));
		}
		foreach (array('INBOX_MEILI_ENABLED', 'INBOX_MEILI_URL', 'INBOX_MEILI_KEY', 'INBOX_MEILI_PREFIX', 'INBOX_MEILI_WORKKEY', 'INBOX_MEILI_WORKKEY_UID') as $k) {
			dolibarr_del_const($this->db, $k, $conf->entity);
		}
		$this->db->query("DELETE FROM ".$this->db->prefix()."inbox_fulltext WHERE fk_account NOT IN (SELECT rowid FROM ".$this->db->prefix()."inbox_account)");
		if ($this->other) {
			try {
				$p = $this->other->getConnection();
				$p->selectFolder('INBOX');
				foreach ($this->appended as $msgid) {
					foreach ((array) $p->search(array('HEADER', 'Message-ID', '"'.$msgid.'"'), true) as $u) {
						$p->requestAndResponse('UID STORE', array((string) $u, '+FLAGS.SILENT', '(\\Deleted)'));
					}
				}
				$p->expunge();
				$this->other->disconnect();
			} catch (Exception $e) {
				// already gone
			}
		}
		parent::tearDown();
	}

	/**
	 * @return void
	 */
	protected function needMeili()
	{
		$this->needDovecot();
		$c = new InboxMeili(self::MEILI_URL, self::MEILI_MASTER);
		$c->timeout = 2;
		if ($c->version() === '') {
			$this->markTestSkipped('Local test Meilisearch not running (dev/dovecot/run.sh)');
		}
	}

	/**
	 * Option on as the settings screen does it: key of inbox created, limited to the prefix
	 *
	 * @return void
	 */
	protected function enable()
	{
		global $conf;

		$master = new InboxMeili(self::MEILI_URL, self::MEILI_MASTER);
		$key = $master->createKey($this->prefix, 'inbox test');
		$this->assertNotNull($key, $master->error);
		dolibarr_set_const($this->db, 'INBOX_MEILI_URL', self::MEILI_URL, 'chaine', 0, '', $conf->entity);
		dolibarr_set_const($this->db, 'INBOX_MEILI_PREFIX', $this->prefix, 'chaine', 0, '', $conf->entity);
		dolibarr_set_const($this->db, 'INBOX_MEILI_KEY', dolEncrypt(self::MEILI_MASTER), 'chaine', 0, '', $conf->entity);
		dolibarr_set_const($this->db, 'INBOX_MEILI_WORKKEY', dolEncrypt($key['key']), 'chaine', 0, '', $conf->entity);
		dolibarr_set_const($this->db, 'INBOX_MEILI_WORKKEY_UID', $key['uid'], 'chaine', 0, '', $conf->entity);
		dolibarr_set_const($this->db, 'INBOX_MEILI_ENABLED', '1', 'chaine', 0, '', $conf->entity);
		$this->assertTrue(InboxFulltext::enabled());
	}

	/**
	 * Test mailbox with a mail whose words are only in its text
	 *
	 * @return InboxAccount
	 */
	protected function readyBox()
	{
		require_once DOL_DOCUMENT_ROOT.'/includes/webklex/php-imap/vendor/autoload.php';
		$a = $this->box(true, array('status' => InboxAccount::STATUS_ACTIVE));
		$access = new InboxAccess($this->db);
		$this->assertGreaterThan(0, $access->grant($this->admin, (int) $a->id, (int) $this->admin->id, 0, InboxAccess::LEVEL_READ));
		InboxAccess::clearCache();
		$cm = new \Webklex\PHPIMAP\ClientManager();
		$this->other = $cm->make(array('host' => self::IMAP_HOST, 'port' => self::IMAP_PORT, 'encryption' => false, 'validate_cert' => false, 'protocol' => 'imap',
			'username' => self::BOX, 'password' => self::BOX_PASSWORD, 'authentication' => 'login'));
		$this->other->connect();
		$msgid = 'w7-meili-'.uniqid().'@inbox-test.invalid';
		$this->appended[] = $msgid;
		$raw = "From: Qualité <qualite@acier.invalid>\r\nTo: ".self::BOX."\r\nSubject: Documents de livraison\r\nMessage-ID: <".$msgid.">\r\n"
			."Date: ".date('r')."\r\nMIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"b1\"\r\n\r\n--b1\r\n"
			."Content-Type: text/html; charset=utf-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n<p>Bonjour,</p><p>Certificats <b>zircophane</b> pour les coulées 24A118.</p>\r\n"
			."--b1\r\nContent-Type: application/pdf; name=\"certificat-quartzite.pdf\"\r\nContent-Disposition: attachment; filename=\"certificat-quartzite.pdf\"\r\nContent-Transfer-Encoding: base64\r\n\r\n"
			.chunk_split(base64_encode(str_repeat('%PDF-1.4 test ', 200)))."--b1--\r\n";
		$this->other->getConnection()->appendMessage('INBOX', $raw);
		$s = new InboxSync($this->db);
		$a->fetch($a->id);
		$this->assertGreaterThanOrEqual(0, $s->run($a, microtime(true) + 30, array('force' => true)), $s->error);

		return $a;
	}

	/**
	 * Search until Meilisearch has applied its tasks (it indexes in the background)
	 *
	 * @param InboxFulltext $ft       Full text
	 * @param int[]         $accounts Mailboxes
	 * @param string[]      $words    Words
	 * @param int           $expect   Row expected
	 * @return array<string,mixed>
	 */
	protected function searchFor(InboxFulltext $ft, array $accounts, array $words, $expect)
	{
		$end = microtime(true) + 10;
		do {
			$res = $ft->search($accounts, $words);
			if (in_array($expect, $res['ids'], true)) {
				return $res;
			}
			usleep(200000);
		} while (microtime(true) < $end);

		return $res;
	}

	/**
	 * CA-69 to CA-71: option off = nothing; key limited to the prefix; one index per mailbox; indexing by the job;
	 * typos and accents tolerated; crop marked; only the indexes given are searched; relevance order; clean up;
	 * Meilisearch gone = error, never a crash
	 *
	 * @return void
	 */
	public function testIndexAndSearch()
	{
		global $langs;

		$this->needMeili();
		$ft = new InboxFulltext($this->db);
		$this->assertFalse(InboxFulltext::enabled());
		$this->assertSame(array(), $ft->search(array(1), array('zircophane'))['ids'], 'option off: nothing asked');
		$this->assertSame('Meilisearch off', ($ft->cronIndex() === 0 ? $ft->output : ''));

		$this->enable();
		$a = $this->readyBox();
		$id = (int) $this->db->getRow("SELECT rowid FROM ".$this->db->prefix()."inbox_mail WHERE fk_account = ".((int) $a->id)." AND message_id = '".$this->db->escape(end($this->appended))."'")->rowid;
		$this->assertGreaterThan(0, $id);
		$uid = InboxFulltext::indexUid((int) $a->id);
		$this->assertMatchesRegularExpression('/^'.$this->prefix.'_\d+_'.((int) $a->id).'$/', $uid);

		// The key of inbox cannot reach an index out of its prefix
		$work = $ft->client();
		$this->assertNull($work->call('POST', '/indexes', array('uid' => 'other'.getmypid().'_1')), 'index out of the prefix refused');
		$this->assertContains($work->code, array(401, 403));

		$this->assertTrue($ft->setAccount((int) $a->id, true), $ft->error);
		$this->assertTrue($ft->setAccount((int) $a->id, true), 'twice: harmless');
		$this->assertSame(0, $ft->cronIndex(), $ft->output.' '.$ft->error);
		$status = $ft->accountStatus((int) $a->id);
		$this->assertSame('ready', $status['state'], (string) $status['error']);
		$this->assertSame($status['total'], $status['sent']);

		$res = $this->searchFor($ft, array((int) $a->id), array('zircophane'), $id);
		$this->assertContains($id, $res['ids'], 'word of the HTML text');
		$this->assertStringContainsString(InboxMeili::MARK_START.'zircophane'.InboxMeili::MARK_END, $res['crops'][$id] ?? '');
		$this->assertSame(array((int) $a->id), $res['ready']);
		$this->assertContains($id, $ft->search(array((int) $a->id), array('zircofane'))['ids'], 'a typo tolerated');
		$this->assertContains($id, $ft->search(array((int) $a->id), array('coulees'))['ids'], 'accents tolerated');
		$this->assertContains($id, $ft->search(array((int) $a->id), array('quartzite'))['ids'], 'name of an attachment');
		$this->assertSame(array(), $ft->search(array((int) $a->id + 100000), array('zircophane'))['ids'], 'a mailbox not indexed: its index is never asked');

		// List: rows of Meilisearch first, read again through the index (another mailbox never listed)
		$engine = new InboxSearchSql($this->db);
		$q = InboxSearchQuery::parse('zircofane', $langs) + array('text_ids' => $res['ids'], 'rank' => $res['ids'], 'sort' => 'relevance');
		$page = $engine->search(array((int) $a->id), 0, $q, '', 1);
		$this->assertSame($id, $page['rows'][0]['id']);
		$this->assertSame(1, $page['rows'][0]['in_text']);
		$this->assertSame(0, $engine->search(array((int) $a->id + 100000), 0, $q, '', 10)['total']);

		// Clean up of the documents whose row is gone
		$task = $work->addDocuments($uid, array(array('id' => 999999999, 'text' => 'zircophane orphan')));
		$this->assertTrue($work->waitTask($task));
		$this->assertSame(1, $ft->reconcile((int) $a->id, microtime(true) + 10, true));

		// Meilisearch gone: an error, the search goes on without it
		$ft2 = new InboxFulltext($this->db);
		$ft2->client = new InboxMeili('http://127.0.0.1:1', 'x');
		$down = $ft2->search(array((int) $a->id), array('zircophane'));
		$this->assertTrue($down['error']);
		$this->assertSame(array(), $down['ready'], 'nothing counted as indexed when Meilisearch is gone');

		// A mailbox turned off: its index removed
		$this->assertTrue($ft->setAccount((int) $a->id, false), $ft->error);
		$this->assertNull($work->call('GET', '/indexes/'.$uid));
		$this->assertSame(404, $work->code);
		$this->assertSame(array(), $ft->states(array((int) $a->id)));

		// Option off: every index of the prefix and the key of inbox removed
		$this->assertTrue($ft->setAccount((int) $a->id, true));
		$this->assertTrue($ft->disableAll(), $ft->error);
		$this->assertFalse(InboxFulltext::enabled());
		$master = new InboxMeili(self::MEILI_URL, self::MEILI_MASTER);
		$this->assertSame(array(), $master->indexes($this->prefix));
		$this->assertSame(array(), $ft->states(array((int) $a->id)));
	}

	/**
	 * Checks of the settings: address, prefix, local or not
	 *
	 * @return void
	 */
	public function testSettingsChecks()
	{
		$this->assertTrue(InboxMeili::validUrl('http://127.0.0.1:7700'));
		$this->assertTrue(InboxMeili::validUrl('https://search.example.com'));
		$this->assertFalse(InboxMeili::validUrl('ftp://127.0.0.1'));
		$this->assertFalse(InboxMeili::validUrl('http://user:pass@127.0.0.1:7700'));
		$this->assertFalse(InboxMeili::validUrl('javascript:alert(1)'));
		$this->assertTrue(InboxMeili::isLocal('http://127.0.0.1:7700'));
		$this->assertTrue(InboxMeili::isLocal('http://192.168.1.20:7700'));
		$this->assertTrue(InboxMeili::isLocal('http://localhost:7700'));
		$this->assertFalse(InboxMeili::isLocal('http://8.8.8.8:7700'));
		$this->assertTrue(InboxFulltext::validPrefix('inbox1a2b3c'));
		$this->assertFalse(InboxFulltext::validPrefix('Inbox'));
		$this->assertFalse(InboxFulltext::validPrefix('in_box'));
		$this->assertFalse(InboxFulltext::validPrefix('1inbox'));
		$this->assertTrue(InboxFulltext::validPrefix(InboxFulltext::defaultPrefix()));
	}
}
