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
 * \file    inbox/test/phpunit/InboxSyncTest.php
 * \ingroup inbox
 * \brief   Synchronisation of the index on the local test Dovecot (lot W2: CA-01 to CA-11)
 */

dol_include_once('/inbox/class/inboxsync.class.php');
dol_include_once('/inbox/class/connector/inboximapresponse.class.php');

/**
 * Tests of InboxSync, InboxFolder, InboxMail, InboxContactMatcher
 */
class InboxSyncTest extends InboxTestCase
{
	/** @var \Webklex\PHPIMAP\Client|null Another client (as Thunderbird), on the test mailbox only */
	protected $other = null;

	/**
	 * @return void
	 */
	protected function tearDown(): void
	{
		if ($this->other) {
			try {
				foreach (array('INBOX.ZzTestFolder') as $f) {
					$this->other->getConnection()->requestAndResponse('DELETE', array('"'.$f.'"'));
				}
				$this->other->disconnect();
			} catch (Exception $e) {
				// already gone
			}
		}
		parent::tearDown();
	}

	/**
	 * @return \Webklex\PHPIMAP\Connection\Protocols\ImapProtocol
	 */
	protected function other()
	{
		require_once DOL_DOCUMENT_ROOT.'/includes/webklex/php-imap/vendor/autoload.php';
		if (!$this->other) {
			$cm = new \Webklex\PHPIMAP\ClientManager();
			$this->other = $cm->make(array('host' => self::IMAP_HOST, 'port' => self::IMAP_PORT, 'encryption' => false, 'validate_cert' => false, 'protocol' => 'imap',
				'username' => self::BOX, 'password' => self::BOX_PASSWORD, 'authentication' => 'login'));
			$this->other->connect();
		}

		return $this->other->getConnection();
	}

	/**
	 * @param string $msgid Message-ID
	 * @return void
	 */
	protected function append($msgid)
	{
		$raw = "Message-ID: <".$msgid.">\r\nFrom: Client Test <client.sync@example.invalid>\r\nTo: ".self::BOX."\r\nSubject: Test ".$msgid."\r\nDate: ".date('r')."\r\n\r\nCorps\r\n";
		$this->other()->appendMessage('INBOX', $raw);
	}

	/**
	 * @param string $msgid Message-ID
	 * @return void
	 */
	protected function remove($msgid)
	{
		$p = $this->other();
		$p->selectFolder('INBOX');
		$uids = $p->search(array('HEADER', 'Message-ID', '"'.$msgid.'"'), true);
		foreach ((array) $uids as $u) {
			$p->requestAndResponse('UID STORE', array((string) $u, '+FLAGS.SILENT', '(\\Deleted)'));
		}
		$p->expunge();
	}

	/**
	 * @param InboxAccount $a Mailbox
	 * @return InboxSync
	 */
	protected function sync(InboxAccount $a)
	{
		$s = new InboxSync($this->db);
		$a->fetch($a->id);
		$this->assertGreaterThanOrEqual(0, $s->run($a, microtime(true) + 30, array('force' => true)), $s->error);

		return $s;
	}

	/**
	 * @param InboxAccount $a     Mailbox
	 * @param string       $msgid Message-ID
	 * @return stdClass|null
	 */
	protected function row(InboxAccount $a, $msgid)
	{
		$r = $this->db->getRow("SELECT m.*, f.path FROM ".$this->db->prefix()."inbox_mail m INNER JOIN ".$this->db->prefix()."inbox_folder f ON f.rowid = m.fk_folder WHERE m.fk_account = ".((int) $a->id)." AND m.message_id = '".$this->db->escape($msgid)."'");

		return is_object($r) ? $r : null;
	}

	/**
	 * CA-01, CA-02, CA-03, CA-04, CA-06: first indexing, new mail, flags changed elsewhere, removed, read only
	 *
	 * @return void
	 */
	public function testIndexAndFollow()
	{
		$this->needDovecot();
		$a = $this->box(true, array('status' => InboxAccount::STATUS_ACTIVE));
		$before = json_encode($this->other()->requestAndResponse('UID FETCH', array('1:*', '(FLAGS)')));
		$s = $this->sync($a);
		$a->loadSyncState();
		$this->assertSame('ok', $a->sync_state);
		$seed = $this->row($a, 'seed-1@inbox-test.invalid');
		$this->assertNotNull($seed, 'seeded mail indexed');
		$this->assertSame('INBOX', $seed->path);
		$this->assertNotNull($this->row($a, 'seed-3@inbox-test.invalid'), 'other folders indexed');
		$this->assertSame('Demande de prix raccords inox DN25', $seed->subject);
		$this->assertSame($before, json_encode($this->other()->requestAndResponse('UID FETCH', array('1:*', '(FLAGS)'))), 'CA-06: no flag written by the synchronisation');

		$this->append('sync-new@inbox-test.invalid');
		$this->sync($a);
		$this->assertNotNull($this->row($a, 'sync-new@inbox-test.invalid'), 'CA-02 new mail');

		$p = $this->other();
		$p->selectFolder('INBOX');
		$uid = (int) $p->search(array('HEADER', 'Message-ID', '"sync-new@inbox-test.invalid"'), true)[0];
		$p->requestAndResponse('UID STORE', array((string) $uid, '+FLAGS.SILENT', '(\\Seen \\Flagged $label2)'));
		$this->sync($a);
		$r = $this->row($a, 'sync-new@inbox-test.invalid');
		$this->assertSame(1, (int) $r->seen, 'CA-03 seen');
		$this->assertSame(1, (int) $r->flagged);
		$this->assertStringContainsString('$label2', (string) $r->keywords);

		$this->remove('sync-new@inbox-test.invalid');
		$this->sync($a);
		$this->assertNull($this->row($a, 'sync-new@inbox-test.invalid'), 'CA-04 removed');
	}

	/**
	 * CA-05: folder created then removed on the server
	 *
	 * @return void
	 */
	public function testFolderTree()
	{
		$this->needDovecot();
		$a = $this->box(true, array('status' => InboxAccount::STATUS_ACTIVE));
		$this->sync($a);
		$this->other()->createFolder('INBOX.ZzTestFolder');
		$this->db->query("UPDATE ".$this->db->prefix()."inbox_account SET date_folders = NULL WHERE rowid = ".((int) $a->id));
		$this->sync($a);
		$paths = array_column((new InboxFolder($this->db))->listForAccount($a->id), 'path');
		$this->assertContains('INBOX.ZzTestFolder', $paths);
		$this->other()->requestAndResponse('DELETE', array('"INBOX.ZzTestFolder"'));
		$this->sync($a);
		$this->sync($a);
		$this->assertNotContains('INBOX.ZzTestFolder', array_column((new InboxFolder($this->db))->listForAccount($a->id, false), 'path'), 'gone twice: removed');
	}

	/**
	 * CA-07, CA-08, CA-09: lock, credentials refused, time budget
	 *
	 * @return void
	 */
	public function testLockErrorsBudget()
	{
		$this->needDovecot();
		$a = $this->box(true, array('status' => InboxAccount::STATUS_ACTIVE));
		$this->db->query("UPDATE ".$this->db->prefix()."inbox_account SET sync_lock = '".$this->db->idate(dol_now())."' WHERE rowid = ".((int) $a->id));
		$s = new InboxSync($this->db);
		$this->assertSame(0, $s->run($a, microtime(true) + 10, array('force' => true)), 'CA-07 locked: skipped');
		$this->db->query("UPDATE ".$this->db->prefix()."inbox_account SET sync_lock = NULL WHERE rowid = ".((int) $a->id));

		$s->run($a, microtime(true) - 1, array('force' => true));
		$a->loadSyncState();
		$this->assertSame('first_sync', $a->sync_state, 'CA-09 budget spent: goes on later');
		$this->sync($a);
		$this->sync($a);
		$a->loadSyncState();
		$this->assertSame('ok', $a->sync_state, 'finished at the next passes');

		$a->setImapPassword('wrong-password-zz');
		$a->update($this->admin);
		$a->fetch($a->id);
		$this->assertSame(-1, $s->run($a, microtime(true) + 10, array('force' => true)));
		$a->loadSyncState();
		$this->assertSame('auth_failed', $a->sync_state, 'CA-08');
		$this->assertGreaterThan(dol_now(), (int) $a->sync_retry_at, 'waits before the next try');
		$this->assertStringNotContainsString('wrong-password-zz', (string) $a->sync_error);
		$this->assertSame(0, $s->run($a, microtime(true) + 10), 'not tried again during the wait');
	}

	/**
	 * CA-11 and thirdparty: delete the mailbox empties its index; recognition by the exact address
	 *
	 * @return void
	 */
	public function testIndexRemovedAndContact()
	{
		$this->needDovecot();
		$a = $this->box(true, array('status' => InboxAccount::STATUS_ACTIVE));
		$this->sync($a);
		$p = $this->db->prefix();
		$this->assertGreaterThan(0, (int) $this->db->getRow("SELECT COUNT(*) AS n FROM ".$p."inbox_mail WHERE fk_account = ".((int) $a->id))->n);
		$soc = $this->db->getRow("SELECT rowid FROM ".$p."societe ORDER BY rowid");
		if (is_object($soc)) {
			// fk_user_creat: a foreign key to the users on recent databases
			$this->assertTrue((bool) $this->db->query("INSERT INTO ".$p."socpeople (entity, fk_soc, lastname, email, statut, datec, fk_user_creat) VALUES (1, ".((int) $soc->rowid).", 'inboxtest_contact',"
				." 'j.dupont@acme.invalid', 1, '".$this->db->idate(dol_now())."', ".((int) $this->admin->id).")"), (string) $this->db->lasterror());
			$cid = (int) $this->db->last_insert_id($p.'socpeople');
			// The mails of the address matched again from nothing; the first active contact with it wins, as in match()
			// (the base may hold another contact with this address)
			$this->db->query("UPDATE ".$p."inbox_mail SET fk_soc = NULL, fk_socpeople = NULL WHERE fk_account = ".((int) $a->id)." AND from_email = 'j.dupont@acme.invalid'");
			$first = (int) $this->db->getRow("SELECT MIN(rowid) as id FROM ".$p."socpeople WHERE LOWER(email) = 'j.dupont@acme.invalid' AND statut = 1")->id;
			$m = new InboxContactMatcher($this->db);
			$this->assertGreaterThan(0, $m->refreshAddress('j.dupont@acme.invalid'));
			$r = $this->row($a, 'seed-1@inbox-test.invalid');
			$this->assertSame($first, (int) $r->fk_socpeople);
			$this->assertGreaterThanOrEqual($first, $cid);
			$this->db->query("DELETE FROM ".$p."socpeople WHERE rowid = ".$cid);
			$m->refreshAddress('j.dupont@acme.invalid');
		}
		$a->status = InboxAccount::STATUS_DISABLED;
		$a->update($this->admin);
		$a->delete($this->admin);
		$this->assertSame(0, (int) $this->db->getRow("SELECT COUNT(*) AS n FROM ".$p."inbox_mail WHERE fk_account = ".((int) $a->id))->n, 'CA-11 index emptied');
		$this->assertSame(0, (int) $this->db->getRow("SELECT COUNT(*) AS n FROM ".$p."inbox_folder WHERE fk_account = ".((int) $a->id))->n);
	}

	/**
	 * CA-10: hostile and malformed headers bounded
	 *
	 * @return void
	 */
	public function testHeaderParser()
	{
		$h = "Message-ID: <x@y>\r\nFrom: =?UTF-8?B?w4dhIHZh?= <A@B.C>\r\nSubject: <script>alert(1)</script>\x01\x02 ".str_repeat('A', 400)."\r\nTo: ".implode(', ', array_map(function ($i) {
			return 'u'.$i.'@x.invalid';
		}, range(1, 300)))."\r\nReferences: <a@b>\r\n <c@d>\r\n";
		$m = InboxHeaderParser::parse($h, array('text', 'plain', array('charset', 'utf-8'), 'NIL', 'NIL', '7bit', '10', '1'));
		$this->assertSame('x@y', $m['message_id']);
		$this->assertSame('a@b.c', $m['from_email'], 'lower case');
		$this->assertSame('Ça va', $m['from_name']);
		$this->assertLessThanOrEqual(255, mb_strlen($m['subject']));
		$this->assertDoesNotMatchRegularExpression('/[\x00-\x1f]/', $m['subject']);
		$this->assertCount(InboxHeaderParser::MAX_ADDRESSES, $m['to']);
		$this->assertSame('a@b c@d', $m['refs']);
		$this->assertSame(0, $m['has_attachments']);
		$att = array(array('text', 'plain', array('charset', 'utf-8'), 'NIL', 'NIL', '7bit', '10', '1', 'NIL', 'NIL', 'NIL', 'NIL'),
			array('application', 'pdf', array('name', 'a.pdf'), 'NIL', 'NIL', 'base64', '100', 'NIL', array('attachment', array('filename', 'a.pdf')), 'NIL', 'NIL'), 'mixed');
		$this->assertTrue(InboxHeaderParser::hasAttachments($att));
		$inline = array(array('text', 'html', array('charset', 'utf-8'), 'NIL', 'NIL', '7bit', '10', '1', 'NIL', 'NIL', 'NIL', 'NIL'),
			array('image', 'png', array('name', 'logo.png'), '<logo>', 'NIL', 'base64', '100', 'NIL', array('inline', array('filename', 'logo.png')), 'NIL', 'NIL'), 'related');
		$this->assertFalse(InboxHeaderParser::hasAttachments($inline), 'picture of the body is not an attachment');
	}

	/**
	 * 1.7.1: BODYSTRUCTURE of a mail of Exchange as Dovecot writes it (parts glued « NIL)( »): the attachment is seen;
	 * literals, sections with spaces and NIL decoded; the response read line by line through the connection
	 *
	 * @return void
	 */
	public function testImapResponseDecoder()
	{
		$bs = '(((("text" "plain" ("charset" "utf-8") NIL NIL "base64" 624 8 NIL NIL NIL NIL)("text" "html" ("charset" "utf-8") NIL NIL "base64" 9608 124 NIL NIL NIL NIL) "alternative" ("boundary" "_000_") NIL NIL NIL)'
			.'("image" "png" ("name" "image.png") "<27be70d8>" "image.png" "base64" 22346 NIL ("inline" ("filename" "image.png" "size" "16329")) NIL NIL) "related" ("boundary" "_005_" "type" "multipart/alternative") NIL NIL NIL)'
			.'("application" "pdf" ("name" "ACME CF2699-02134.pdf") NIL "ACME CF2699-02134.pdf" "base64" 139034 NIL ("attachment" ("filename" "ACME CF2699-02134.pdf" "size" "101599")) NIL NIL) "mixed" ("boundary" "_006_") NIL ("fr-FR") NIL)';
		$header = "Subject: a (b) \"c\"\r\nFrom: x@y.invalid\r\n\r\n";
		$raw = '7 FETCH (UID 7884 FLAGS (\\Seen $label5) RFC822.SIZE 183964 BODYSTRUCTURE '.$bs.' BODY[HEADER.FIELDS (FROM SUBJECT)] {'.strlen($header)."}\r\n".$header.")\r\n";
		$t = InboxImapResponse::decode($raw);
		$this->assertSame(array('7', 'FETCH'), array_slice($t, 0, 2));
		$items = $t[2];
		$this->assertSame(array('UID', '7884', 'FLAGS', array('\\Seen', '$label5'), 'RFC822.SIZE', '183964', 'BODYSTRUCTURE'), array_slice($items, 0, 7));
		$this->assertCount(3 + 4, $items[7], 'mixed: related, pdf, subtype and 4 extension fields');
		$this->assertSame('pdf', $items[7][1][1]);
		$this->assertNull($items[7][1][3], 'NIL is null');
		$this->assertSame('BODY[HEADER.FIELDS (FROM SUBJECT)]', $items[8]);
		$this->assertSame($header, $items[9], 'literal kept byte for byte');
		$this->assertTrue(InboxHeaderParser::hasAttachments($items[7]));
		$this->assertSame(1, InboxHeaderParser::parse($header, $items[7])['has_attachments']);
		// Only the inline picture: no attachment; a multipart without extension data is still walked
		$this->assertFalse(InboxHeaderParser::hasAttachments($items[7][0]));
		$this->assertTrue(InboxHeaderParser::hasAttachments(InboxImapResponse::decode('(("text" "plain" NIL NIL NIL "7bit" 1 1)("application" "zip" ("name" "a.zip") NIL NIL "base64" 10) "mixed")')[0]));
		$this->assertSame(array('a"b\\c'), InboxImapResponse::decode('"a\\"b\\\\c"'), 'quoted string escapes');

		// Read through the connection: literal over several lines and the rest of the line after it, tagged end
		$conn = new class ("* ".$raw."* 8 FETCH (UID 9 BODYSTRUCTURE (\"text\" \"plain\" NIL NIL NIL \"7bit\" 1 1))\r\nTAG3 OK Fetch completed.\r\n") {
			/** @var string[] */
			private $lines;
			/** @param string $s Stream */
			public function __construct($s)
			{
				preg_match_all('/[^\n]*\n/', $s, $m);
				$this->lines = $m[0];
			}
			/** @return string */
			public function nextLine()
			{
				return (string) array_shift($this->lines);
			}
		};
		$res = InboxImapResponse::read($conn, 'TAG3');
		$this->assertCount(2, $res);
		$this->assertSame($header, $res[0][2][9]);
		$this->assertSame(array('UID', '9', 'BODYSTRUCTURE'), array_slice($res[1][2], 0, 3));
		$no = new class ("TAG4 NO [NONEXISTENT] Unknown\r\n") {
			/** @var string */
			private $s;
			/** @param string $s Stream */
			public function __construct($s)
			{
				$this->s = $s;
			}
			/** @return string */
			public function nextLine()
			{
				$l = $this->s;
				$this->s = '';
				return $l;
			}
		};
		$this->assertNull(InboxImapResponse::read($no, 'TAG4'));
	}

	/**
	 * One folder per role (1.38.0): the choice of the administrator, else the mark of the server, else the usual name;
	 * the others ordinary, told as duplicates
	 *
	 * @return void
	 */
	public function testUniqueRoles()
	{
		$f = function ($path, $flags = array()) {
			return array('path' => $path, 'role' => InboxImapConnector::guessRole($path, $flags), 'flags' => $flags);
		};
		$list = array($f('INBOX.Junk'), $f('INBOX.Spam', array('\\junk')), $f('INBOX.Corbeille'), $f('INBOX.Trash'), $f('INBOX.Drafts'));
		InboxImapConnector::uniqueRoles($list);
		$roles = array_column($list, 'role', 'path');
		$this->assertSame('junk', $roles['INBOX.Spam'], 'the mark of the server first');
		$this->assertSame('', $roles['INBOX.Junk']);
		$this->assertSame('junk', $list[0]['twin']);
		$this->assertSame('trash', $roles['INBOX.Trash'], 'the usual name');
		$this->assertSame('', $roles['INBOX.Corbeille']);
		$this->assertSame('drafts', $roles['INBOX.Drafts'], 'one folder: nothing changes');
		$this->assertArrayNotHasKey('twin', $list[4]);

		// The choice of the administrator before the mark of the server
		$list = array($f('INBOX.Junk'), $f('INBOX.Spam', array('\\junk')));
		InboxImapConnector::uniqueRoles($list, array('junk' => 'INBOX.Junk'));
		$this->assertSame(array('INBOX.Junk' => 'junk', 'INBOX.Spam' => ''), array_column($list, 'role', 'path'));

		// A role only found by a test (kept before 1.40.1): after the mark of the server
		$list = array($f('INBOX.Corbeille'), $f('INBOX.Trash', array('\\trash')));
		InboxImapConnector::uniqueRoles($list, array(), array('trash' => 'INBOX.Corbeille'));
		$this->assertSame(array('INBOX.Corbeille' => '', 'INBOX.Trash' => 'trash'), array_column($list, 'role', 'path'));

		// The roles chosen by hand kept apart from those found
		$a = new InboxAccount($this->db);
		$a->setFolderRoles(array('trash' => 'INBOX.Corbeille', 'junk' => 'INBOX.Spam'), array('trash'));
		$this->assertSame(array('trash' => 'INBOX.Corbeille'), $a->getChosenRoles());
		$this->assertSame(array('trash' => 'INBOX.Corbeille', 'junk' => 'INBOX.Spam'), $a->getFolderRoles());
		$a->setFolderRoles(array('trash' => 'INBOX.Trash'));
		$this->assertSame(array('trash' => 'INBOX.Trash'), $a->getChosenRoles(), 'null keeps the roles chosen before');
	}

	/**
	 * Trash and junk emptied after some days (1.41.0): delays offered only, a delay turned on or shortened counts
	 * from now
	 *
	 * @return void
	 */
	public function testRetentionSettings()
	{
		dol_include_once('/inbox/class/inboxretention.class.php');
		$a = new InboxAccount($this->db);
		$this->assertSame(0, InboxRetention::daysOf($a, 'trash'), 'never by default');
		$this->assertFalse(InboxRetention::set($a, 5, 0), 'a delay not offered');
		$this->assertTrue(InboxRetention::set($a, 30, 0));
		$c = $a->getConnectorConfig();
		$this->assertSame(30, InboxRetention::daysOf($a, 'trash'));
		$this->assertGreaterThan(dol_now() - 5, (int) $c['trash_days_since']);
		$c['trash_days_since'] = 1000;
		$a->setConnectorConfig($c);
		InboxRetention::set($a, 60, 0);
		$this->assertSame(1000, (int) $a->getConnectorConfig()['trash_days_since'], 'a longer delay keeps its start');
		InboxRetention::set($a, 7, 0);
		$this->assertGreaterThan(1000, (int) $a->getConnectorConfig()['trash_days_since'], 'a shorter delay starts again');
	}
}
