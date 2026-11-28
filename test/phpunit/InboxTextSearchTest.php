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
 * \file    inbox/test/phpunit/InboxTextSearchTest.php
 * \ingroup inbox
 * \brief   1.19.0: deep search in the text of the mails on the mail server
 */

// From the command line only, never from the web: test/ stays in the Git branches
if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

require_once __DIR__.'/InboxTestCase.php';
dol_include_once('/inbox/class/inboxsync.class.php');
dol_include_once('/inbox/class/inboxsearchsql.class.php');
dol_include_once('/inbox/class/inboxtextsearch.class.php');

/**
 * Deep search in the text of the mails
 */
class InboxTextSearchTest extends InboxTestCase
{
	/** @var \Webklex\PHPIMAP\Client|null Another client, on the test mailbox only */
	protected $other = null;

	/** @var string[] Message-ID appended by the test, removed after */
	protected $appended = array();

	/**
	 * @return void
	 */
	protected function tearDown(): void
	{
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
	 * Test mailbox with a mail whose words are only in its text, synchronised
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
		$msgid = 'w7-text-'.uniqid().'@inbox-test.invalid';
		$this->appended[] = $msgid;
		$raw = "From: Qualité <qualite@acier.invalid>\r\nTo: ".self::BOX."\r\nSubject: Documents de livraison\r\nMessage-ID: <".$msgid.">\r\n"
			."Date: ".date('r')."\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=utf-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n"
			.str_repeat("Bonjour, voici les documents de la livraison.\r\n", 8)
			."Certificats zircophane pour les coulées 24A118.\r\n";
		$this->other->getConnection()->appendMessage('INBOX', $raw);
		$s = new InboxSync($this->db);
		$a->fetch($a->id);
		$this->assertGreaterThanOrEqual(0, $s->run($a, microtime(true) + 30, array('force' => true)), $s->error);

		return $a;
	}

	/**
	 * @param InboxAccount $a Mailbox
	 * @return int Row of the mail appended
	 */
	protected function rowId(InboxAccount $a)
	{
		$r = $this->db->getRow("SELECT rowid FROM ".$this->db->prefix()."inbox_mail WHERE fk_account = ".((int) $a->id)." AND message_id = '".$this->db->escape(end($this->appended))."'");

		return is_object($r) ? (int) $r->rowid : 0;
	}

	/**
	 * CA-66: a word only in the text is found by the mail server; case ignored, accents kept; words sent as
	 * literals (quotes and braces typed are harmless)
	 *
	 * @return void
	 */
	public function testConnectorSearchText()
	{
		$this->needDovecot();
		$a = $this->readyBox();
		$error = '';
		$c = InboxConnectorFactory::forAccount($this->db, $a, $error);
		$this->assertNotNull($c, $error);
		$this->assertTrue($c->can(InboxConnectorInterface::CAP_SERVER_SEARCH));
		$this->assertCount(1, (array) $c->searchText('INBOX', array('zircophane')), 'word only in the text');
		$this->assertCount(1, (array) $c->searchText('INBOX', array('ZIRCOPHANE', 'coulées')), 'case ignored, UTF-8 literal, every word');
		$this->assertCount(0, (array) $c->searchText('INBOX', array('zircophane', 'absentword')), 'every word must be there');
		$this->assertSame(array(), $c->searchText('INBOX', array('  ')), 'no word: nothing asked');
		foreach (array('a"b', '{3}', ") OR (ALL", "x\r\nA1 LOGOUT") as $evil) {
			$this->assertSame(array(), $c->searchText('INBOX', array($evil)), 'harmless: '.$evil.' '.$c->getError());
		}
		$this->assertNull($c->searchText('../INBOX', array('zircophane')), 'unsafe folder refused');
		$this->assertCount(0, (array) $c->searchText('INBOX', array('zircophane'), array('before' => gmmktime(0, 0, 0, 1, 1, 2000))), 'period sent to the server');
		$c->disconnect();
	}

	/**
	 * CA-66, CA-68: one mailbox searched, rows kept per user and search, read back only for the readable mailboxes,
	 * merged with the index by the SQL engine with the mark « found in the text »
	 *
	 * @return void
	 */
	public function testDeepSearchAndList()
	{
		global $langs;

		$this->needDovecot();
		$a = $this->readyBox();
		$id = $this->rowId($a);
		$this->assertGreaterThan(0, $id);
		$inbox = $this->db->getRow("SELECT rowid FROM ".$this->db->prefix()."inbox_folder WHERE fk_account = ".((int) $a->id)." AND role = 'inbox'");
		$search = new InboxTextSearch($this->db);

		$folders = $search->folders((int) $a->id, 'folder', (int) $inbox->rowid);
		$this->assertCount(1, $folders);
		foreach ($search->folders((int) $a->id, 'account', 0) as $f) {
			$role = $this->db->getRow("SELECT role FROM ".$this->db->prefix()."inbox_folder WHERE rowid = ".((int) $f->rowid))->role;
			$this->assertNotContains((string) $role, array('trash', 'junk'), 'trash and junk out of a search of the mailbox');
		}
		$this->assertSame(array(), $search->folders((int) $a->id + 100000, 'account', 0), 'another mailbox: no folder');

		$query = InboxSearchQuery::parse('zircophane', $langs);
		$res = $search->run($a, $folders, $query, microtime(true) + 20);
		$this->assertTrue($res['complete']);
		$this->assertSame(array($id), $res['ids']);
		$expired = $search->run($a, $folders, $query, microtime(true) - 1);
		$this->assertFalse($expired['complete'], 'time limit passed: no folder started, the search says it is not complete');
		$this->assertSame(array(), $expired['ids']);

		// Results kept in a folder of the test (the temp folder of the instance belongs to the web server)
		global $conf;
		$tempBefore = $conf->inbox->dir_temp ?? null;
		$conf->inbox->dir_temp = sys_get_temp_dir().'/inbox-test-'.getmypid();
		$key = InboxTextSearch::key($this->admin, $query, 'folder', (int) $inbox->rowid);
		$this->assertNotSame($key, InboxTextSearch::key($this->admin, InboxSearchQuery::parse('autre', $langs), 'folder', (int) $inbox->rowid));
		$this->assertNotSame($key, InboxTextSearch::key($this->admin, $query, 'account', (int) $a->id), 'the scope is part of the key');
		$this->assertTrue($search->save($this->admin, $key, (int) $a->id, $res['ids']));
		$this->assertSame(array($id), $search->load($this->admin, $key, array((int) $a->id)));
		$this->assertSame(array(), $search->load($this->admin, $key, array((int) $a->id + 1)), 'mailbox not readable any more: nothing');
		$other = $this->user('text_other');
		$this->assertSame(array(), $search->load($other, $key, array((int) $a->id)), 'the results of another user are never read');
		dol_delete_dir_recursive($conf->inbox->dir_temp);
		$conf->inbox->dir_temp = $tempBefore;

		$engine = new InboxSearchSql($this->db);
		$this->assertSame(0, $engine->search(array((int) $a->id), (int) $inbox->rowid, $query, '', 50)['total'], 'the index alone does not know the text');
		$page = $engine->search(array((int) $a->id), (int) $inbox->rowid, $query + array('text_ids' => array($id)), '', 50);
		$this->assertSame(1, $page['total']);
		$this->assertSame(1, $page['rows'][0]['in_text'], 'found only in its text');
		$both = $engine->search(array((int) $a->id), (int) $inbox->rowid, InboxSearchQuery::parse('livraison', $langs) + array('text_ids' => array($id)), '', 50);
		$this->assertSame(0, $both['rows'][0]['in_text'], 'found by the index too: no mark');
		$this->assertSame(0, $engine->search(array((int) $a->id + 100000), 0, $query + array('text_ids' => array($id)), '', 50)['total'], 'a row of a mailbox not given is never listed');
	}
}
