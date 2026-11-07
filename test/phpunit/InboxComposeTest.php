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
 * \file    inbox/test/phpunit/InboxComposeTest.php
 * \ingroup inbox
 * \brief   Lot W6 1.16.0: building of a mail, sending (local Dovecot relaying to Mailpit), Sent, drafts, rights
 */

require_once __DIR__.'/InboxImapTestCase.php';
dol_include_once('/inbox/class/inboxcomposer.class.php');
dol_include_once('/inbox/class/inboxidentity.class.php');

/**
 * The sending tests need dev/dovecot/run.sh (Dovecot relays to Mailpit, which delivers nothing)
 */
class InboxComposeTest extends InboxImapTestCase
{
	const MAILPIT = 'http://127.0.0.1:18025';

	/** @var string|null Setting before the test */
	protected $disabled = null;

	/**
	 * @return void
	 */
	protected function setUp(): void
	{
		global $conf;

		parent::setUp();
		$this->disabled = getDolGlobalString('MAIN_DISABLE_ALL_MAILS');
		$conf->global->MAIN_DISABLE_ALL_MAILS = 0;
		$_SESSION['inbox_compose'] = array();
	}

	/**
	 * @return void
	 */
	protected function tearDown(): void
	{
		global $conf;

		$conf->global->MAIN_DISABLE_ALL_MAILS = $this->disabled;
		// Mails written by the tests (subject ZzCompose) removed from Sent and Drafts
		try {
			$p = $this->imap();
			foreach (array('INBOX.Sent', 'INBOX.Drafts') as $folder) {
				$p->selectFolder($folder);
				foreach ((array) $p->search(array('SUBJECT', '"ZzCompose"'), true) as $u) {
					$p->requestAndResponse('UID STORE', array((string) $u, '+FLAGS.SILENT', '(\\Deleted)'));
				}
				$p->expunge();
			}
		} catch (Exception $e) {
			// no server
		}
		parent::tearDown();
	}

	/**
	 * @return array<int,array<string,mixed>> Mails caught by Mailpit, or null when it does not answer
	 */
	protected function mailpit($path, $method = 'GET')
	{
		$ctx = stream_context_create(array('http' => array('method' => $method, 'timeout' => 3, 'ignore_errors' => true)));
		$res = @file_get_contents(self::MAILPIT.$path, false, $ctx);

		return $res === false ? null : $res;
	}

	/**
	 * @param InboxAccount $a Mailbox
	 * @return int Identity
	 */
	protected function identity(InboxAccount $a)
	{
		$i = new InboxIdentity($this->db);
		$i->fk_account = (int) $a->id;
		$i->email = self::BOX;
		$i->name = 'Devis Test';
		$i->signature = '<p>Service devis</p>';
		$i->is_default = 1;
		$this->assertGreaterThan(0, $i->create($this->admin), implode(',', $i->errors));

		return (int) $i->id;
	}

	/**
	 * @return void
	 */
	public function testBuilder()
	{
		$b = new InboxMailBuilder();
		$b->from = array('email' => 'devis@inbox-test.invalid', 'name' => 'Devis');
		$b->to = InboxMailBuilder::parseAddresses('"Dupont, Jean" <Jean@Client.invalid>, bad-address; jean@client.invalid', $bad);
		$this->assertCount(1, $b->to, 'duplicates removed, case ignored');
		$this->assertSame('Dupont, Jean', $b->to[0]['name']);
		$this->assertSame(array('bad-address'), $bad);
		$b->bcc = array(array('email' => 'hidden@inbox-test.invalid', 'name' => ''));
		$b->subject = 'ZzCompose builder';
		$b->html = '<p onclick="x()">Bonjour <script>alert(1)</script><a href="javascript:alert(1)">lien</a> <a href="https://example.invalid">site</a></p>'
			.'<img src="data:image/png;base64,'.base64_encode(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==')).'">';
		$b->inReplyTo = 'orig@client.invalid';
		$b->references = array('<first@client.invalid>', 'orig@client.invalid');
		$res = $b->build();
		$this->assertNotNull($res, $b->error);
		$this->assertStringContainsString('Bcc: hidden@inbox-test.invalid', $res['raw'], 'the copy in Sent keeps the Bcc');
		$this->assertStringNotContainsString('hidden@inbox-test.invalid', $res['wire'], 'the mail sent does not show it');
		$this->assertContains('hidden@inbox-test.invalid', $res['recipients'], 'but it gets it');
		$this->assertStringContainsString('Message-ID: <'.$res['message_id'].'>', $res['wire']);
		$this->assertStringEndsWith('@inbox-test.invalid', $res['message_id'], 'domain of the sender, nothing of the tool');
		$this->assertStringNotContainsStringIgnoringCase('dolibarr', $res['wire']);
		$this->assertMatchesRegularExpression('/^In-Reply-To: <orig@client.invalid>/m', $res['wire']);
		$this->assertMatchesRegularExpression('/^References: <first@client.invalid> <orig@client.invalid>/m', $res['wire']);
		$this->assertStringContainsString('text/plain', $res['wire']);
		$this->assertStringNotContainsString('<script', $res['wire']);
		$this->assertStringNotContainsString('onclick', $res['wire']);
		$this->assertStringNotContainsString('javascript:', $res['wire']);
		$this->assertStringContainsString('cid:', $res['wire'], 'a picture of the text becomes a part of the mail');
		$this->assertStringContainsString('site <https://example.invalid>', InboxMailBuilder::toText(InboxMailBuilder::cleanHtml($b->html)));

		// What the editor writes for the mail software (1.16.1): colours, alignment, tables with their borders in the cells
		$clean = InboxMailBuilder::cleanHtml('<p style="text-align: center"><span style="color: #b42318; background-color: #fef08a">Urgent</span></p>'
			.'<table style="min-width: 75px"><colgroup><col></colgroup><tbody><tr><td colspan="1" rowspan="1"><p>A</p></td></tr></tbody></table><div style="background:url(http://x.invalid/t.png)">x</div>');
		$this->assertStringContainsString('text-align: center', $clean);
		$this->assertStringContainsString('color: #b42318; background-color: #fef08a', $clean);
		$this->assertStringContainsString('border-collapse:collapse', $clean);
		$this->assertMatchesRegularExpression('/<td[^>]*border:1px solid/', $clean);
		$this->assertStringNotContainsString('http://x.invalid', $clean, 'no remote picture by a style');
		// Styles of the table kept as the editor wrote them (lines only, no border), widths of the columns for Outlook
		$lines = InboxMailBuilder::cleanHtml('<table align="center" width="100%" style="width:100%;margin-left:auto;margin-right:auto"><tbody><tr>'
			.'<td data-colwidth="120" style="border:0;border-bottom:1px solid #d0d5dd;padding:6px;background-color:#f6f8fa;vertical-align:middle">A</td>'
			.'<td style="border:0">B</td></tr></tbody></table>');
		$this->assertStringContainsString('border:0;border-bottom:1px solid #d0d5dd;padding:6px', $lines);
		$this->assertStringNotContainsString('border:1px solid #d0d5dd;padding:4px', $lines, 'no grid added over a style chosen');
		$this->assertStringContainsString('width="120"', $lines);
		$this->assertStringContainsString('align="center"', $lines);

		$this->assertSame('Re: Commande', InboxComposer::subjectFor('reply', 'RE: Re: Commande'));
		$this->assertSame('Fwd: Commande', InboxComposer::subjectFor('forward', 'TR: Commande'));
		$this->assertSame('Re: Devis', InboxComposer::subjectFor('replyall', 'Devis'));
	}

	/**
	 * @return void
	 */
	public function testReplySentAndAnswered()
	{
		$a = $this->ready();
		if ($this->mailpit('/api/v1/messages', 'DELETE') === null) {
			$this->markTestSkipped('Mailpit not started (dev/dovecot/run.sh)');
		}
		$identity = $this->identity($a);
		$p = $this->db->prefix();
		$m1 = $this->db->getRow("SELECT rowid FROM ".$p."inbox_mail WHERE message_id = 'm1".self::MARK."'");
		$c = new InboxComposer($this->db);
		$this->assertSame('INBOX.Sent', $c->roleFolder($a, 'sent'), 'Sent known by its special use');
		$open = $c->open($this->admin, 'replyall', (int) $m1->rowid);
		$this->assertNotNull($open, $c->errorCode.' '.$c->error);
		$this->assertSame($identity, $open['identity']);
		$this->assertSame('client@example.invalid', $open['to'][0]['email'], 'answer to the sender');
		$this->assertSame(array(), $open['cc'], 'the mailbox itself is not in copy');
		$this->assertSame('Re: ZzAct m1', $open['subject']);
		$this->assertStringContainsString('Corps m1', $open['quote']);

		$sent = $c->send($this->admin, $open['token'], array('identity' => $identity, 'to' => $open['to'], 'cc' => array(), 'bcc' => 'cachee@inbox-test.invalid',
			'subject' => 'ZzCompose reply', 'html' => '<p>Merci</p><script>x</script>', 'quote' => 1, 'signature' => 1));
		$this->assertNotNull($sent, $c->errorCode.' '.$c->error);
		$this->assertSame(1, $sent['sent_copy']);

		// What left: Mailpit caught it, with the headers of the answer and without the Bcc
		$list = json_decode((string) $this->mailpit('/api/v1/messages'), true);
		$this->assertSame(1, (int) $list['total']);
		$raw = (string) $this->mailpit('/api/v1/message/'.$list['messages'][0]['ID'].'/raw');
		$this->assertStringContainsString('In-Reply-To: <m1'.self::MARK.'>', $raw);
		// Mailpit writes its own « Bcc: » line first (envelope recipients): the mail itself starts at Return-Path
		$this->assertStringNotContainsString('cachee@inbox-test.invalid', substr($raw, (int) strpos($raw, 'Return-Path:')));
		$this->assertStringContainsString('Service devis', quoted_printable_decode($raw), 'signature of the identity');
		$this->assertStringContainsString('Corps m1', quoted_printable_decode($raw), 'quote made by the server');
		$bccs = array_column((array) $list['messages'][0]['Bcc'], 'Address');
		$this->assertContains('cachee@inbox-test.invalid', $bccs, 'the Bcc got it');

		// Copy in Sent (with its Bcc), the mail answered marked on the server and in the index, the journal
		$this->assertCount(1, $this->onServerSubject('INBOX.Sent', 'ZzCompose reply'));
		$uids = $this->onServer('INBOX.ZzActA', 'm1');
		$this->assertStringContainsString('Answered', $this->flagsOnServer('INBOX.ZzActA', $uids[0]));
		$this->assertSame(1, (int) $this->db->getRow("SELECT answered FROM ".$p."inbox_mail WHERE rowid = ".((int) $m1->rowid))->answered);
		$log = $this->db->getRow("SELECT detail FROM ".$p."inbox_action_log WHERE action = 'send' AND fk_account = ".((int) $a->id));
		$this->assertSame('2', (string) $log->detail, 'two recipients counted, never named');
		// The writing is gone: sending it again is refused
		$this->assertNull($c->send($this->admin, $open['token'], array('identity' => $identity, 'to' => 'x@inbox-test.invalid')));
		$this->assertSame('not_found', $c->errorCode);
	}

	/**
	 * @param string $folder  Folder
	 * @param string $subject Subject
	 * @return int[]
	 */
	protected function onServerSubject($folder, $subject)
	{
		$p = $this->imap();
		$p->selectFolder($folder);

		return array_map('intval', (array) $p->search(array('SUBJECT', '"'.$subject.'"'), true));
	}

	/**
	 * @return void
	 */
	public function testDraftsReplacedAndReopened()
	{
		$a = $this->ready();
		$identity = $this->identity($a);
		$c = new InboxComposer($this->db);
		$open = $c->open($this->admin, 'new', 0, (int) $a->id);
		$this->assertNotNull($open, $c->errorCode.' '.$c->error);
		$typed = array('identity' => $identity, 'to' => 'jean@client.invalid', 'subject' => 'ZzCompose draft', 'html' => '<p>Version 1</p>', 'signature' => 1, 'receipt' => 1);
		$this->assertNotNull($c->saveDraft($this->admin, $open['token'], $typed), $c->error);
		$typed['html'] = '<p>Version 2</p>';
		$this->assertNotNull($c->saveDraft($this->admin, $open['token'], $typed), $c->error);
		$this->assertCount(1, $this->onServerSubject('INBOX.Drafts', 'ZzCompose draft'), 'the previous version removed');

		// Closed, then reopened from the Drafts folder: what was typed comes back, the signature apart
		$this->assertTrue($c->close($this->admin, $open['token'], false));
		$this->sync($a);
		$row = $this->db->getRow("SELECT m.rowid FROM ".$this->db->prefix()."inbox_mail as m WHERE m.fk_account = ".((int) $a->id)." AND m.subject = 'ZzCompose draft'");
		$this->assertTrue(is_object($row), 'draft indexed');
		$again = $c->open($this->admin, 'draft', (int) $row->rowid);
		$this->assertNotNull($again, $c->errorCode.' '.$c->error);
		$this->assertSame($identity, $again['identity']);
		$this->assertSame('jean@client.invalid', $again['to'][0]['email']);
		$this->assertStringContainsString('Version 2', $again['html']);
		$this->assertStringNotContainsString('Service devis', $again['html'], 'the signature is not in the text');
		$this->assertSame(1, $again['receipt']);
		$this->assertTrue($c->close($this->admin, $again['token'], true));
		$this->assertCount(0, $this->onServerSubject('INBOX.Drafts', 'ZzCompose draft'), 'removed on demand');
	}

	/**
	 * A mailbox without identity sends with its own address
	 *
	 * @return void
	 */
	public function testMailboxWithoutIdentity()
	{
		$a = $this->ready();
		$c = new InboxComposer($this->db);
		$open = $c->open($this->admin, 'new', 0, (int) $a->id);
		$this->assertNotNull($open, $c->errorCode.' '.$c->error);
		$this->assertSame(-((int) $a->id), $open['identity']);
		$this->assertSame(self::BOX, $open['identities'][0]['email']);
		$this->assertNotNull($c->saveDraft($this->admin, $open['token'], array('identity' => $open['identity'], 'to' => 'jean@client.invalid', 'subject' => 'ZzCompose noid', 'html' => '<p>x</p>')), $c->error);
		$this->assertCount(1, $this->onServerSubject('INBOX.Drafts', 'ZzCompose noid'));
		$this->assertTrue($c->close($this->admin, $open['token'], true));
	}

	/**
	 * @return void
	 */
	public function testRights()
	{
		$a = $this->ready();
		$identity = $this->identity($a);
		$c = new InboxComposer($this->db);
		// The level « read » does not give the identities of the mailbox
		$reader = $this->user('inboxtest_reader');
		$access = new InboxAccess($this->db);
		$access->grant($this->admin, $a->id, $reader->id, 0, InboxAccess::LEVEL_READ);
		InboxAccess::clearCache();
		$this->assertSame(array(), $c->identities($reader));
		$this->assertNull($c->open($reader, 'new', 0, (int) $a->id));
		$this->assertSame('no_send', $c->errorCode);
		// A writing of the admin is unknown to another user, and unknown out of its session
		$open = $c->open($this->admin, 'new', 0, (int) $a->id);
		$access->grant($this->admin, $a->id, $reader->id, 0, InboxAccess::LEVEL_SEND);
		InboxAccess::clearCache();
		$this->assertNull($c->send($reader, $open['token'], array('identity' => $identity, 'to' => 'x@inbox-test.invalid')));
		$this->assertSame('not_found', $c->errorCode);
		unset($_SESSION['inbox_compose'][$open['token']]);
		$this->assertNull($c->saveDraft($this->admin, $open['token'], array('identity' => $identity)));
		$this->assertSame('not_found', $c->errorCode);
		// Sending stopped by the option of Dolibarr
		global $conf;
		$open = $c->open($this->admin, 'new', 0, (int) $a->id);
		$conf->global->MAIN_DISABLE_ALL_MAILS = 1;
		$this->assertNull($c->send($this->admin, $open['token'], array('identity' => $identity, 'to' => 'x@inbox-test.invalid', 'subject' => 'ZzCompose off')));
		$this->assertSame('mails_disabled', $c->errorCode);
		// A bad address refuses the sending, nothing goes
		$conf->global->MAIN_DISABLE_ALL_MAILS = 0;
		$this->assertNull($c->send($this->admin, $open['token'], array('identity' => $identity, 'to' => 'not an address', 'subject' => 'ZzCompose bad')));
		$this->assertSame('bad_address', $c->errorCode);
	}
}
