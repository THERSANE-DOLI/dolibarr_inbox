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
 * \file    inbox/test/phpunit/InboxConversationTest.php
 * \ingroup inbox
 * \brief   Lot W3 1.5.0: conversations, quotes folded, pictures of the correspondents
 */

// From the command line only, never from the web: test/ stays in the Git branches
if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

require_once __DIR__.'/InboxTestCase.php';
dol_include_once('/inbox/class/inboxsync.class.php');
dol_include_once('/inbox/class/inboxsearchsql.class.php');
dol_include_once('/inbox/class/inboxthreader.class.php');
dol_include_once('/inbox/class/inboxavatar.class.php');
dol_include_once('/inbox/class/inboxnewmarks.class.php');

/**
 * Conversations (CA-14) and the other parts of 1.5.0
 */
class InboxConversationTest extends InboxTestCase
{
	/** @var \Webklex\PHPIMAP\Client|null */
	protected $other = null;

	/** @var array<int,array{0:string,1:string}> Folder, Message-ID appended */
	protected $appended = array();

	/**
	 * @return void
	 */
	protected function tearDown(): void
	{
		if ($this->other) {
			try {
				$p = $this->other->getConnection();
				foreach (array('INBOX', 'INBOX.Sent') as $folder) {
					$p->selectFolder($folder);
					foreach ($this->appended as $a) {
						if ($a[0] !== $folder) {
							continue;
						}
						foreach ((array) $p->search(array('HEADER', 'Message-ID', '"'.$a[1].'"'), true) as $u) {
							$p->requestAndResponse('UID STORE', array((string) $u, '+FLAGS.SILENT', '(\\Deleted)'));
						}
					}
					$p->expunge();
				}
				$this->other->disconnect();
			} catch (Exception $e) {
				// already gone
			}
		}
		parent::tearDown();
	}

	/**
	 * @param string   $folder  Folder
	 * @param string   $msgid   Message-ID
	 * @param string   $from    From
	 * @param string   $subject Subject
	 * @param string[] $refs    References (the last one is In-Reply-To)
	 * @param int      $time    Date of the mail
	 * @return void
	 */
	protected function append($folder, $msgid, $from, $subject, array $refs = array(), $time = 0)
	{
		require_once DOL_DOCUMENT_ROOT.'/includes/webklex/php-imap/vendor/autoload.php';
		if (!$this->other) {
			$cm = new \Webklex\PHPIMAP\ClientManager();
			$this->other = $cm->make(array('host' => self::IMAP_HOST, 'port' => self::IMAP_PORT, 'encryption' => false, 'validate_cert' => false, 'protocol' => 'imap',
				'username' => self::BOX, 'password' => self::BOX_PASSWORD, 'authentication' => 'login'));
			$this->other->connect();
		}
		$raw = "Message-ID: <".$msgid.">\r\nFrom: ".$from."\r\nTo: ".self::BOX."\r\nSubject: ".$subject."\r\nDate: ".date('r', $time ?: time())."\r\n";
		if ($refs) {
			$raw .= "In-Reply-To: <".end($refs).">\r\nReferences: <".implode("> <", $refs).">\r\n";
		}
		$raw .= "\r\nCorps\r\n";
		$this->appended[] = array($folder, $msgid);
		$this->other->getConnection()->appendMessage($folder, $raw);
	}

	/**
	 * @param InboxAccount $a     Mailbox
	 * @param string       $msgid Message-ID
	 * @return string Key of the thread
	 */
	protected function key(InboxAccount $a, $msgid)
	{
		$r = $this->db->getRow("SELECT thread_key FROM ".$this->db->prefix()."inbox_mail WHERE fk_account = ".((int) $a->id)." AND message_id = '".$this->db->escape($msgid)."'");

		return is_object($r) ? (string) $r->thread_key : '';
	}

	/**
	 * @return void
	 */
	public function testNormalizeSubject()
	{
		$this->assertSame('devis brides dn80', InboxThreader::normalizeSubject('RE: TR: Re[2]: Fwd: AW: Devis  brides DN80'));
		$this->assertSame('réf. commande', InboxThreader::normalizeSubject('Réf: Réf. commande'), 'only the prefix « Réf: » goes');
		$this->assertSame('', InboxThreader::normalizeSubject('RE:'));
		// Prefixes of the calendars on an invitation and its answers (1.143.1)
		$this->assertSame('réunion de chantier', InboxThreader::normalizeSubject('Invitation mise à jour : Réunion de chantier'));
		$this->assertSame('site meeting', InboxThreader::normalizeSubject('RE: Tentatively accepted: Site meeting'));
		$this->assertSame('baustellenbesprechung', InboxThreader::normalizeSubject('Mit Vorbehalt angenommen: Baustellenbesprechung'));
		$this->assertSame('accepted offer', InboxThreader::normalizeSubject('Accepted offer'), 'a word without « : » stays');
		// The other side of a mail, never the mailbox (1.53.1)
		$own = array('box@inbox-test.invalid', 'alias@inbox-test.invalid');
		$this->assertSame(array('sophie@example.invalid'), InboxThreader::correspondents('Sophie@Example.invalid', 'Box <box@inbox-test.invalid>', '', $own), 'received: the sender');
		$this->assertSame(array('client@example.invalid', 'copie@example.invalid'), InboxThreader::correspondents('alias@inbox-test.invalid', 'Client <client@example.invalid>, box@inbox-test.invalid', 'copie@example.invalid', $own), 'sent: the recipients');
		$this->assertSame(array(), InboxThreader::correspondents('box@inbox-test.invalid', 'Moi <box@inbox-test.invalid>', '', $own), 'to oneself: nobody');
	}

	/**
	 * CA-14: a conversation across Inbox and Sent, threads merged when a mail links them, fallback on the subject,
	 * one line per thread in the list, pages without double
	 *
	 * @return void
	 */
	public function testThreads()
	{
		$this->needDovecot();
		$a = $this->box(true, array('status' => InboxAccount::STATUS_ACTIVE));
		$c = 'Client Fil <fil@client.example.invalid>';
		$t = time() - 3600;
		// The answer arrives before its original in the index (Sent read after Inbox): threads linked later
		$this->append('INBOX', 'conv-a@inbox-test.invalid', $c, 'Devis brides', array(), $t);
		$this->append('INBOX', 'conv-c@inbox-test.invalid', $c, 'RE: RE: Devis brides', array('conv-a@inbox-test.invalid', 'conv-b@inbox-test.invalid'), $t + 120);
		$this->append('INBOX.Sent', 'conv-b@inbox-test.invalid', self::BOX, 'RE: Devis brides', array('conv-a@inbox-test.invalid'), $t + 60);
		// Fallback: same subject and correspondent, no reference
		$this->append('INBOX', 'conv-d@inbox-test.invalid', $c, 'TR: Devis brides', array(), $t + 180);
		// Other correspondent, same subject: another thread
		$this->append('INBOX', 'conv-e@inbox-test.invalid', 'Autre <autre@other.example.invalid>', 'Devis brides', array(), $t + 240);
		// Written by the mailbox to itself, same subject: the mailbox is never the correspondent (1.53.1)
		$this->append('INBOX', 'conv-f@inbox-test.invalid', self::BOX, 'Devis brides', array(), $t + 300);
		$s = new InboxSync($this->db);
		$a->fetch($a->id);
		$this->assertGreaterThanOrEqual(0, $s->run($a, microtime(true) + 30, array('force' => true)), $s->error);
		$k = $this->key($a, 'conv-a@inbox-test.invalid');
		$this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $k);
		foreach (array('conv-b', 'conv-c', 'conv-d') as $id) {
			$this->assertSame($k, $this->key($a, $id.'@inbox-test.invalid'), $id.' in the conversation');
		}
		$this->assertNotSame($k, $this->key($a, 'conv-e@inbox-test.invalid'), 'another correspondent: another conversation');
		$this->assertNotSame($k, $this->key($a, 'conv-f@inbox-test.invalid'), 'a mail to oneself never joins by the subject');
		$this->assertNotSame($this->key($a, 'conv-e@inbox-test.invalid'), $this->key($a, 'conv-f@inbox-test.invalid'));

		$inbox = (int) $this->db->getRow("SELECT rowid FROM ".$this->db->prefix()."inbox_folder WHERE fk_account = ".((int) $a->id)." AND role = 'inbox'")->rowid;
		$engine = new InboxSearchSql($this->db);
		$keys = array();
		$cursor = '';
		do {
			$page = $engine->searchThreads(array((int) $a->id), $inbox, array(), $cursor, 1);
			foreach ($page['rows'] as $r) {
				$this->assertArrayNotHasKey($r['thread']['key'], $keys, 'a thread once');
				$keys[$r['thread']['key']] = $r;
			}
			$cursor = $page['next'];
		} while ($cursor !== '');
		$this->assertArrayHasKey($k, $keys);
		$this->assertSame(4, $keys[$k]['thread']['count'], 'four mails, the sent one included');
		$this->assertSame('TR: Devis brides', $keys[$k]['subject'], 'the line shows the last mail of the thread in the folder');

		// Backfill: keys lost (mails indexed before 1.5.0) are given again
		$this->db->query("UPDATE ".$this->db->prefix()."inbox_mail SET thread_key = NULL WHERE fk_account = ".((int) $a->id));
		$threader = new InboxThreader($this->db);
		$this->assertGreaterThan(0, $threader->backfill((int) $a->id, microtime(true) + 20));
		$k2 = $this->key($a, 'conv-a@inbox-test.invalid');
		$this->assertSame($k2, $this->key($a, 'conv-c@inbox-test.invalid'));
		$this->assertSame($k2, $this->key($a, 'conv-b@inbox-test.invalid'));
	}

	/**
	 * New mails (not the same as unread): a folder never opened starts with nothing new, a mail arrived after the mark
	 * is new while unread, the mark is per user
	 *
	 * @return void
	 */
	public function testNewMarks()
	{
		$this->needDovecot();
		$a = $this->box(true, array('status' => InboxAccount::STATUS_ACTIVE));
		$s = new InboxSync($this->db);
		$a->fetch($a->id);
		$s->run($a, microtime(true) + 30, array('force' => true));
		$nm = new InboxNewMarks($this->db);
		$marks = $nm->marks($this->admin, (int) $a->id);
		$this->assertNotEmpty($marks);
		$this->assertSame(array(), $nm->counts($marks), 'first visit: nothing new');
		$this->append('INBOX', 'new-a@inbox-test.invalid', 'Neuf <neuf@client.example.invalid>', 'Nouveau');
		$a->fetch($a->id);
		$s->run($a, microtime(true) + 30, array('force' => true));
		$counts = $nm->counts($nm->marks($this->admin, (int) $a->id));
		$this->assertSame(array(1), array_values($counts), 'one new mail in the inbox');
		// First indexing goes from the recent mails to the older ones: an old mail indexed after the mark (greater row,
		// smaller UID) is not new
		$p = $this->db->prefix();
		$old = $this->db->getRow("SELECT * FROM ".$p."inbox_mail WHERE fk_account = ".((int) $a->id)." AND message_id = 'new-a@inbox-test.invalid'");
		$this->db->query("INSERT INTO ".$p."inbox_mail (fk_account, fk_folder, remote_id, sort_key, message_id, subject, seen, date_index) VALUES (".((int) $a->id).", ".((int) $old->fk_folder).", 'old:1', 0, 'old-backfill@inbox-test.invalid', 'Ancien', 0, '".$this->db->idate(dol_now())."')");
		$this->assertSame(array(1), array_values($nm->counts($nm->marks($this->admin, (int) $a->id))), 'old mail indexed late: not new');
		$other = $this->user('newmarks_other');
		$this->assertSame(array(), $nm->counts($nm->marks($other, (int) $a->id)), 'another user starts at his first visit');
		$this->db->query("UPDATE ".$this->db->prefix()."inbox_mail SET seen = 1 WHERE fk_account = ".((int) $a->id)." AND message_id = 'new-a@inbox-test.invalid'");
		$this->assertSame(array(), $nm->counts($nm->marks($this->admin, (int) $a->id)), 'read: not new any more');
	}

	/**
	 * Quotes of the previous mails folded in the conversation, without script
	 *
	 * @return void
	 */
	public function testFoldQuotes()
	{
		$remote = false;
		$gmail = InboxMimeParser::safeHtml('<p>Merci</p><div class="gmail_quote"><div>Le lun., Paul a écrit :</div><blockquote>Ancien</blockquote></div>', array(), $remote);
		$out = InboxMimeParser::foldQuotes($gmail);
		$this->assertStringContainsString('<details class="inbox-quote">', $out);
		$this->assertMatchesRegularExpression('#<p>Merci</p>\s*<details#', $out, 'the new text stays shown');
		$outlook = InboxMimeParser::foldQuotes(InboxMimeParser::safeHtml('<p>Oui</p><hr><div id="divRplyFwdMsg"><b>De :</b> Paul</div><div>Ancien</div>', array(), $remote));
		$this->assertMatchesRegularExpression('#<details class="inbox-quote"><summary>[^<]*</summary><hr>#', $outlook, 'from the separator of Outlook');
		$text = InboxMimeParser::foldQuotes(InboxMimeParser::textDocument("Merci\n\nLe 3 nov., Paul a écrit :\n> Ancien\n> texte"));
		$this->assertStringContainsString('<details class="inbox-quote">', $text);
		$this->assertStringNotContainsString('<script', $text);
		$plain = InboxMimeParser::textDocument('Pas de citation');
		$this->assertSame($plain, InboxMimeParser::foldQuotes($plain), 'nothing to fold: unchanged');
	}

	/**
	 * Pictures: only real pictures are taken, never an HTML page or a script
	 *
	 * @return void
	 */
	public function testAvatarTypes()
	{
		$this->assertSame('image/png', InboxAvatar::imageType("\x89PNG\r\n\x1a\nxxxx"));
		$this->assertSame('image/svg+xml', InboxAvatar::imageType('<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg"></svg>'));
		$this->assertSame('', InboxAvatar::imageType('<html><script>alert(1)</script></html>'));
		$this->assertSame('', InboxAvatar::imageType('GIF'), 'too short');
		$avatar = new InboxAvatar($this->db);
		$this->assertNull($avatar->get('not an address'));
	}
}
