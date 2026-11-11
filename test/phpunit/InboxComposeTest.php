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
		// Sent at once unless a test asks a delay (1.17.0)
		$this->delay = getDolGlobalString('INBOX_SEND_DELAY');
		$conf->global->INBOX_SEND_DELAY = 0;
		$_SESSION['inbox_compose'] = array();
	}

	/** @var string|null Delay before the test */
	protected $delay = null;

	/**
	 * @return void
	 */
	protected function tearDown(): void
	{
		global $conf;

		$conf->global->MAIN_DISABLE_ALL_MAILS = $this->disabled;
		$conf->global->INBOX_SEND_DELAY = $this->delay;
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
		// Signature delimiter (1.53.5): not in the HTML, « -- » and its space alone on a line in the text part (RFC 3676)
		$text = InboxMailBuilder::toText(InboxMailBuilder::cleanHtml('<p>Bonjour</p><div class="inbox-signature"><p>John BOTELLA</p></div>'));
		$this->assertStringContainsString("Bonjour\n\n-- \nJohn BOTELLA", $text);
		$text = InboxMailBuilder::toText(InboxMailBuilder::cleanHtml('<p>Bonjour</p><div class="inbox-signature">-- <br><p>John</p></div>'));
		$this->assertSame(1, substr_count($text, '-- '), 'a draft written before 1.53.5: one delimiter');
		$this->assertStringContainsString("\n-- \n", $text);

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

		// A forward: the keyword $Forwarded on the server and in the index, not \Answered (1.26.0)
		$m2 = $this->db->getRow("SELECT rowid FROM ".$p."inbox_mail WHERE message_id = 'm2".self::MARK."'");
		$fwd = $c->open($this->admin, 'forward', (int) $m2->rowid);
		$this->assertNotNull($fwd, $c->errorCode.' '.$c->error);
		$this->assertNotNull($c->send($this->admin, $fwd['token'], array('identity' => $identity, 'to' => 'loin@inbox-test.invalid', 'subject' => 'ZzCompose fwd',
			'html' => '<p>Pour info</p>', 'quote' => 1, 'signature' => 0)), $c->errorCode.' '.$c->error);
		$uids = $this->onServer('INBOX.ZzActA', 'm2');
		$flags = $this->flagsOnServer('INBOX.ZzActA', $uids[0]);
		$this->assertStringContainsString('$Forwarded', $flags);
		$this->assertStringNotContainsString('Answered', $flags);
		$row = $this->db->getRow("SELECT answered, keywords FROM ".$p."inbox_mail WHERE rowid = ".((int) $m2->rowid));
		$this->assertSame(0, (int) $row->answered);
		$this->assertStringContainsString('$Forwarded', (string) $row->keywords);
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
		// A picture pasted (data:), resized: a part of the mail (cid:) in the draft
		$png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';
		$typed['html'] = '<p>Version 2</p><p><img src="data:image/png;base64,'.$png.'" width="120" height="40"></p>';
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
		// The picture comes back in the text, with its size, and not as an attachment (1.25.2)
		$this->assertStringContainsString('src="data:image/png;base64,'.$png.'"', $again['html']);
		$this->assertStringContainsString('width="120"', $again['html']);
		$this->assertStringNotContainsString('cid:', $again['html']);
		$this->assertCount(0, $again['attachments'] ?? array());
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
		// A file of the writing, checked in the viewer before sending (lot A5, 1.82.0): the very file, its author only
		$tmp = tempnam(sys_get_temp_dir(), 'ibxt');
		file_put_contents($tmp, '%PDF-1.4 devis');
		$att = $c->upload($this->admin, $open['token'], 'devis.pdf', $tmp, 'application/pdf');
		@unlink($tmp);
		$this->assertNotNull($att, $c->error);
		$file = $c->attachmentFile($this->admin, $open['token'], $att['id']);
		$this->assertSame(array('devis.pdf', '%PDF-1.4 devis'), array($file['name'], $file['content']));
		$this->assertNull($c->attachmentFile($this->admin, $open['token'], 'deadbeefdeadbeef'), 'not one of the writing');
		$this->assertNull($c->attachmentFile($this->user('composefile'), $open['token'], $att['id']), 'another user');
		$this->assertTrue($c->close($this->admin, $open['token'], true));
	}

	/**
	 * Queue (1.17.0): undo gives the writing back, « send now » sends once, the scheduled job sends what is due,
	 * a failure is told in the bell, the sending recorded on a piece linked to the mail answered
	 *
	 * @return void
	 */
	public function testQueue()
	{
		global $conf;

		$a = $this->ready();
		if ($this->mailpit('/api/v1/messages', 'DELETE') === null) {
			$this->markTestSkipped('Mailpit not started (dev/dovecot/run.sh)');
		}
		dol_include_once('/inbox/class/inboxoutbox.class.php');
		dol_include_once('/inbox/class/inboxlink.class.php');
		dol_include_once('/inbox/class/inboxmailref.class.php');
		$identity = $this->identity($a);
		$p = $this->db->prefix();
		$conf->global->INBOX_SEND_DELAY = 10;
		$c = new InboxComposer($this->db);
		$outbox = new InboxOutbox($this->db);
		$typed = array('identity' => $identity, 'to' => 'jean@client.invalid', 'subject' => 'ZzCompose queue', 'html' => '<p>Bonjour</p>');

		// Delay: queued, nothing left; undo gives the writing back as typed
		$open = $c->open($this->admin, 'new', 0, (int) $a->id);
		$q = $c->send($this->admin, $open['token'], $typed);
		$this->assertNotNull($q, $c->errorCode.' '.$c->error);
		$this->assertGreaterThan(0, $q['queued']);
		$this->assertSame(10, $q['delay']);
		$this->assertSame(0, (int) json_decode((string) $this->mailpit('/api/v1/messages'), true)['total']);
		$back = $outbox->cancel($this->admin, $q['queued']);
		$this->assertNotNull($back, $outbox->errorCode);
		$this->assertSame('ZzCompose queue', $back['subject']);
		$this->assertSame('jean@client.invalid', $back['to'][0]['email']);
		$this->assertSame($open['token'], $back['token'], 'the same writing (its attachments) comes back');

		// Send now: once only
		$q = $c->send($this->admin, $back['token'], $typed);
		$sent = $outbox->dispatch($this->admin, $q['queued']);
		$this->assertNotNull($sent, $outbox->errorCode.' '.$outbox->error);
		$this->assertNull($outbox->dispatch($this->admin, $q['queued']));
		$this->assertSame('already_sent', $outbox->errorCode);
		$this->assertNull($outbox->cancel($this->admin, $q['queued']), 'too late to undo');
		$this->assertSame(1, (int) json_decode((string) $this->mailpit('/api/v1/messages'), true)['total']);

		// Scheduled: not before its date, then by the scheduled job
		$open = $c->open($this->admin, 'new', 0, (int) $a->id);
		$q = $c->send($this->admin, $open['token'], array_merge($typed, array('subject' => 'ZzCompose scheduled')), array('at' => dol_now() + 3600));
		$this->assertSame(1, $q['scheduled']);
		$outbox->cronRun();
		$this->assertSame(0, (int) $this->db->getRow("SELECT status FROM ".$p."inbox_outbox WHERE rowid = ".((int) $q['queued']))->status);
		$this->db->query("UPDATE ".$p."inbox_outbox SET date_send = '".$this->db->idate(dol_now() - 1)."' WHERE rowid = ".((int) $q['queued']));
		$outbox->cronRun();
		$this->assertSame(InboxOutbox::SENT, (int) $this->db->getRow("SELECT status FROM ".$p."inbox_outbox WHERE rowid = ".((int) $q['queued']))->status);
		$this->assertCount(1, $this->onServerSubject('INBOX.Sent', 'ZzCompose scheduled'));
		$this->assertNull($c->send($this->admin, $c->open($this->admin, 'new', 0, (int) $a->id)['token'], $typed, array('at' => dol_now() + 10)), 'a date too close');
		$this->assertSame('bad_date', $c->errorCode);

		// Failure: told in the bell, kept to be taken back
		$open = $c->open($this->admin, 'new', 0, (int) $a->id);
		$q = $c->send($this->admin, $open['token'], array_merge($typed, array('subject' => 'ZzCompose failed')));
		$this->db->query("UPDATE ".$p."inbox_account SET smtp_port = 1 WHERE rowid = ".((int) $a->id));
		$this->db->query("UPDATE ".$p."inbox_outbox SET date_send = '".$this->db->idate(dol_now() - 1)."' WHERE rowid = ".((int) $q['queued']));
		$outbox->cronRun();
		$row = $this->db->getRow("SELECT status, error FROM ".$p."inbox_outbox WHERE rowid = ".((int) $q['queued']));
		$this->assertSame(InboxOutbox::FAILED, (int) $row->status, (string) $row->error);
		$this->assertTrue(is_object($this->db->getRow("SELECT rowid FROM ".$p."inbox_notification WHERE type = 'send_failed' AND fk_user = ".((int) $this->admin->id))));
		$this->assertSame('ZzCompose failed', $outbox->listMine($this->admin)[0]['subject']);
		$this->assertNotNull($outbox->cancel($this->admin, $q['queued']), 'a failed mail is taken back');
		$this->db->query("UPDATE ".$p."inbox_account SET smtp_port = ".self::SMTP_PORT." WHERE rowid = ".((int) $a->id));

		// Record on a piece linked to the mail answered: event of the agenda, mail sent linked to the piece
		$conf->global->INBOX_SEND_DELAY = 0;
		$this->db->query("INSERT INTO ".$p."societe (nom, entity, status, client, datec) VALUES ('inboxtest_record', 1, 1, 1, '".$this->db->idate(dol_now())."')");
		$socid = (int) $this->db->last_insert_id($p.'societe');
		$refs = new InboxMailRef($this->db);
		$links = new InboxLink($this->db);
		$links->link($refs->fetchOrCreateByMessageId('m2'.self::MARK), 'societe', $socid, $this->admin);
		$m2 = $this->db->getRow("SELECT rowid FROM ".$p."inbox_mail WHERE message_id = 'm2".self::MARK."'");
		$open = $c->open($this->admin, 'reply', (int) $m2->rowid);
		$this->assertSame('societe:'.$socid, $open['pieces'][0]['type'].':'.$open['pieces'][0]['id'], 'the piece linked is offered');
		$sent = $c->send($this->admin, $open['token'], array('identity' => $identity, 'to' => $open['to'], 'subject' => 'ZzCompose record', 'html' => '<p>Merci</p>'), array('record' => array('societe:'.$socid, 'societe:999999')));
		$this->assertNotNull($sent, $c->errorCode.' '.$c->error);
		$this->assertSame(array('societe:'.$socid), $sent['recorded'], 'only a piece offered');
		$event = $this->db->getRow("SELECT fk_soc, code FROM ".$p."actioncomm WHERE email_msgid = '".$this->db->escape($sent['message_id'])."'");
		$this->assertSame($socid, (int) $event->fk_soc);
		$sentRef = $refs->fetchOrCreateByMessageId($sent['message_id']);
		// The links of its conversation may follow it too (lot A3, 1.73.0): the piece recorded is among them
		$this->assertContains('societe:'.$socid, array_map(function ($l) {
			return $l['type'].':'.$l['element'];
		}, $links->listFor(array($sentRef))), 'the mail sent is linked to the piece');
		$this->db->query("DELETE FROM ".$p."inbox_link WHERE element_type = 'societe' AND fk_element = ".$socid);
	}

	/**
	 * Templates (1.18.0): offered by mailbox, variables of the recipient as chips, sent as text; rights
	 *
	 * @return void
	 */
	public function testTemplates()
	{
		$a = $this->ready();
		if ($this->mailpit('/api/v1/messages', 'DELETE') === null) {
			$this->markTestSkipped('Mailpit not started (dev/dovecot/run.sh)');
		}
		dol_include_once('/inbox/class/inboxtemplates.class.php');
		$identity = $this->identity($a);
		$p = $this->db->prefix();
		$this->db->query("INSERT INTO ".$p."societe (nom, entity, status, client, datec) VALUES ('inboxtest_tpl', 1, 1, 1, '".$this->db->idate(dol_now())."')");
		$socid = (int) $this->db->last_insert_id($p.'societe');
		$this->db->query("INSERT INTO ".$p."socpeople (entity, fk_soc, lastname, firstname, email, statut, datec, fk_user_creat) VALUES (1, ".$socid.", 'inboxtest_Dupont', 'Jean', 'jean@client-tpl.invalid', 1, '".$this->db->idate(dol_now())."', ".((int) $this->admin->id).")");
		$tpl = new InboxTemplates($this->db);

		// Saved in the format of Dolibarr (__KEY__), given back to the editor as chips
		$id = $tpl->save($this->admin, array('label' => 'ZzTpl hello', 'subject' => 'ZzCompose __CONTACT_FIRSTNAME__', 'accounts' => array((int) $a->id),
			'html' => '<p>Bonjour <span data-inbox-var="CONTACT_FIRSTNAME"></span> de __THIRDPARTY_NAME__<script>x</script></p>'));
		$this->assertGreaterThan(0, $id, $tpl->errorCode.' '.$tpl->error);
		$content = (string) $this->db->getRow("SELECT content FROM ".$p."c_email_templates WHERE rowid = ".$id)->content;
		$this->assertStringContainsString('Bonjour __CONTACT_FIRSTNAME__ de __THIRDPARTY_NAME__', $content);
		$this->assertStringNotContainsString('script', $content);
		$this->assertStringContainsString('data-inbox-var="THIRDPARTY_NAME"', $tpl->get($this->admin, $id)['html']);
		$other = $tpl->save($this->admin, array('label' => 'ZzTpl elsewhere', 'subject' => 'x', 'html' => '<p>x</p>', 'accounts' => array(999999)));
		$this->assertGreaterThan(0, $other);

		// Language and preview of a template (1.26.0)
		$this->assertSame($id, $tpl->save($this->admin, array('id' => $id, 'label' => 'ZzTpl hello', 'subject' => 'ZzCompose __CONTACT_FIRSTNAME__', 'accounts' => array((int) $a->id),
			'html' => '<p>Bonjour <span data-inbox-var="CONTACT_FIRSTNAME"></span> de __THIRDPARTY_NAME__</p>', 'lang' => 'de_DE')));
		$this->assertSame('de_DE', $tpl->get($this->admin, $id)['lang']);
		$this->assertSame($id, $tpl->save($this->admin, array('id' => $id, 'label' => 'ZzTpl hello', 'subject' => 'ZzCompose __CONTACT_FIRSTNAME__', 'accounts' => array((int) $a->id),
			'html' => '<p>Bonjour <span data-inbox-var="CONTACT_FIRSTNAME"></span> de __THIRDPARTY_NAME__</p>', 'lang' => "fr_FR' OR 1")));
		$this->assertSame('', $tpl->get($this->admin, $id)['lang'], 'a bad language is not kept');
		$prev = $tpl->preview($this->admin, (int) $a->id, 'jean@client-tpl.invalid', '', 'Pour __CONTACT_FIRSTNAME__', '<p>Bonjour <span data-inbox-var="CONTACT_FIRSTNAME"></span><img src=x onerror=alert(1)></p>');
		$this->assertSame('Pour Jean', $prev['subject']);
		$this->assertStringContainsString('Jean', $prev['html']);
		$this->assertStringNotContainsString('onerror', $prev['html'], 'cleaned as a mail');

		// Offered in the mailbox it is attached to only; values of the first recipient
		$c = new InboxComposer($this->db);
		$open = $c->open($this->admin, 'new', 0, (int) $a->id);
		$groups = $c->templates($this->admin, $open['token'], $identity);
		$ids = array_column($groups[0]['templates'], 'id');
		$this->assertContains($id, $ids);
		$this->assertNotContains($other, $ids, 'a template of another mailbox is not offered');
		$res = $c->applyTemplate($this->admin, $open['token'], $id, $identity, '', 'jean@client-tpl.invalid');
		$this->assertNotNull($res, $c->errorCode.' '.$c->error);
		$this->assertSame('ZzCompose Jean', $res['subject']);
		$this->assertStringContainsString('data-inbox-var="CONTACT_FIRSTNAME">Jean</span>', $res['html']);
		$this->assertStringContainsString('>inboxtest_tpl</span>', $res['html'], 'thirdparty of the contact');
		$values = $c->templateValues($this->admin, $open['token'], $identity, '', 'nobody@elsewhere-tpl.invalid');
		$this->assertSame('', $values['CONTACT_FIRSTNAME'], 'unknown recipient: no value');
		$this->assertNull($c->applyTemplate($this->admin, $open['token'], $other, $identity, '', ''), 'not usable here');
		$this->assertNull($c->applyTemplate($this->admin, $open['token'], $id, $identity, 'societe:'.$socid, ''), 'a piece not linked to the mail');

		// Sent: the chips are text only
		$sent = $c->send($this->admin, $open['token'], array('identity' => $identity, 'to' => 'jean@client-tpl.invalid', 'subject' => $res['subject'], 'html' => $res['html']));
		$this->assertNotNull($sent, $c->errorCode.' '.$c->error);
		$list = json_decode((string) $this->mailpit('/api/v1/messages'), true);
		$raw = quoted_printable_decode((string) $this->mailpit('/api/v1/message/'.$list['messages'][0]['ID'].'/raw'));
		$this->assertStringContainsString('Bonjour <span>Jean</span>', $raw);
		$this->assertStringNotContainsString('data-inbox-var', $raw);
		// A draft keeps them
		$this->assertStringContainsString('data-inbox-var="REF"', InboxMailBuilder::cleanHtml('<span data-inbox-var="REF">x</span>', true));
		$this->assertStringNotContainsString('data-inbox-var', InboxMailBuilder::cleanHtml('<span data-inbox-var="REF">x</span>'));

		// Rights: a shared template of a mailbox needs to manage it; one's own always
		$writer = $this->user('tplwriter');
		$access = new InboxAccess($this->db);
		$access->grant($this->admin, $a->id, $writer->id, 0, InboxAccess::LEVEL_SEND);
		InboxAccess::clearCache();
		$this->assertSame(0, $tpl->save($writer, array('label' => 'ZzTpl shared', 'html' => '<p>x</p>', 'accounts' => array((int) $a->id))));
		$this->assertSame('no_right', $tpl->errorCode);
		$this->assertSame(0, $tpl->save($writer, array('id' => $id, 'label' => 'ZzTpl changed', 'html' => '<p>x</p>', 'private' => 1)));
		$mine = $tpl->save($writer, array('label' => 'ZzTpl mine', 'html' => '<p>x</p>', 'private' => 1));
		$this->assertGreaterThan(0, $mine);
		$this->assertNotContains($mine, array_column($tpl->listForAccount($this->admin, (int) $a->id), 'id'), 'a private template is seen by its owner only');
		$this->assertFalse($tpl->delete($writer, $id));
		$this->assertTrue($tpl->delete($this->admin, $id));
	}

	/**
	 * Templates and documents of the pieces linked to the mail answered (1.18.0)
	 *
	 * @return void
	 */
	public function testPieceTemplatesAndDocuments()
	{
		global $conf;

		$a = $this->ready();
		dol_include_once('/inbox/class/inboxlink.class.php');
		dol_include_once('/inbox/class/inboxmailref.class.php');
		$identity = $this->identity($a);
		$p = $this->db->prefix();
		$this->db->query("INSERT INTO ".$p."societe (nom, entity, status, client, datec) VALUES ('inboxtest_piece', 1, 1, 1, '".$this->db->idate(dol_now())."')");
		$socid = (int) $this->db->last_insert_id($p.'societe');
		$this->db->query("INSERT INTO ".$p."c_email_templates (entity, type_template, label, private, active, enabled, topic, content, joinfiles) VALUES (1, 'thirdparty', 'ZzTpl soc', 0, 1, '1', 'Pour __THIRDPARTY_NAME__', 'Client __THIRDPARTY_NAME__', 0)");
		$tplId = (int) $this->db->last_insert_id($p.'c_email_templates');
		$refs = new InboxMailRef($this->db);
		$links = new InboxLink($this->db);
		$links->link($refs->fetchOrCreateByMessageId('m3'.self::MARK), 'societe', $socid, $this->admin);
		$m3 = $this->db->getRow("SELECT rowid FROM ".$p."inbox_mail WHERE message_id = 'm3".self::MARK."'");
		$c = new InboxComposer($this->db);
		$open = $c->open($this->admin, 'reply', (int) $m3->rowid);
		$this->assertSame('societe:'.$socid, $open['linked'][0]['type'].':'.$open['linked'][0]['id']);
		$groups = $c->templates($this->admin, $open['token'], $identity);
		$soc = array_values(array_filter($groups, function ($g) {
			return $g['type'] === 'societe';
		}));
		$this->assertContains($tplId, array_column($soc[0]['templates'], 'id'), 'templates of the type of the piece');
		$res = $c->applyTemplate($this->admin, $open['token'], $tplId, $identity, 'societe:'.$socid, '');
		$this->assertNotNull($res, $c->errorCode.' '.$c->error);
		$this->assertSame('Pour inboxtest_piece', $res['subject']);
		$this->assertStringContainsString('>inboxtest_piece</span>', $res['html']);

		// A template for every type (1.26.0): offered, used with the variables of the piece or without a piece
		$this->db->query("INSERT INTO ".$p."c_email_templates (entity, type_template, label, private, active, enabled, topic, content, joinfiles) VALUES (1, 'all', 'ZzTpl all', 0, 1, '1', 'Bonjour __THIRDPARTY_NAME__', 'Texte pour tous', 0)");
		$allId = (int) $this->db->last_insert_id($p.'c_email_templates');
		$groups = $c->templates($this->admin, $open['token'], $identity);
		$all = array_values(array_filter($groups, function ($g) {
			return $g['type'] === 'all';
		}));
		$this->assertContains($allId, array_column($all[0]['templates'], 'id'));
		$res = $c->applyTemplate($this->admin, $open['token'], $allId, $identity, 'societe:'.$socid, '');
		$this->assertNotNull($res, $c->errorCode.' '.$c->error);
		$this->assertSame('Bonjour inboxtest_piece', $res['subject']);
		$this->assertNotNull($c->applyTemplate($this->admin, $open['token'], $allId, $identity, '', ''), 'without a piece');

		// Documents of the piece: listed, attached; nothing outside its folder
		$dir = $conf->societe->multidir_output[1].'/'.$socid;
		dol_mkdir($dir);
		file_put_contents($dir.'/offre.pdf', '%PDF-1.4 test');
		$docs = $c->documents($this->admin, $open['token']);
		$this->assertSame('offre.pdf', $docs[0]['files'][0]['name']);
		$att = $c->attachDocument($this->admin, $open['token'], 'societe:'.$socid, 'offre.pdf');
		$this->assertNotNull($att, $c->errorCode.' '.$c->error);
		$this->assertSame(13, $att['size']);
		$this->assertNull($c->attachDocument($this->admin, $open['token'], 'societe:'.$socid, '../../conf.php'));
		$this->assertNull($c->attachDocument($this->admin, $open['token'], 'societe:1', 'offre.pdf'), 'a piece not linked');
		dol_delete_dir_recursive($dir);
		$this->db->query("DELETE FROM ".$p."inbox_link WHERE element_type = 'societe' AND fk_element = ".$socid);
	}

	/**
	 * Edit as new and redirect (B19, 1.18.0)
	 *
	 * @return void
	 */
	public function testEditAsNewAndRedirect()
	{
		$a = $this->ready();
		if ($this->mailpit('/api/v1/messages', 'DELETE') === null) {
			$this->markTestSkipped('Mailpit not started (dev/dovecot/run.sh)');
		}
		$this->identity($a);
		$p = $this->db->prefix();
		$m1 = $this->db->getRow("SELECT rowid FROM ".$p."inbox_mail WHERE message_id = 'm1".self::MARK."'");
		$c = new InboxComposer($this->db);
		$open = $c->open($this->admin, 'editnew', (int) $m1->rowid);
		$this->assertNotNull($open, $c->errorCode.' '.$c->error);
		$this->assertSame('ZzAct m1', $open['subject']);
		$this->assertStringContainsString('Corps m1', $open['html']);
		$this->assertNull($open['source'], 'a new mail, not an answer');

		$res = $c->redirect($this->admin, (int) $m1->rowid, 'autre@inbox-test.invalid');
		$this->assertNotNull($res, $c->errorCode.' '.$c->error);
		$list = json_decode((string) $this->mailpit('/api/v1/messages'), true);
		$this->assertSame(1, (int) $list['total']);
		$this->assertContains('autre@inbox-test.invalid', array_column((array) $list['messages'][0]['Bcc'], 'Address'), 'envelope: the new recipient only');
		$raw = (string) $this->mailpit('/api/v1/message/'.$list['messages'][0]['ID'].'/raw');
		$this->assertMatchesRegularExpression('/^Resent-To: autre@inbox-test\.invalid/m', $raw);
		$this->assertStringContainsString('Message-ID: <m1'.self::MARK.'>', $raw, 'the very mail');
		$this->assertTrue(is_object($this->db->getRow("SELECT rowid FROM ".$p."inbox_action_log WHERE action = 'redirect' AND fk_account = ".((int) $a->id))));
		$this->assertNull($c->redirect($this->admin, (int) $m1->rowid, 'pas une adresse'));
		$this->assertSame('bad_address', $c->errorCode);
	}

	/**
	 * Unsubscribing by mail (1.53.0): the address, subject and text of the mailto, from the mailbox that got the mail,
	 * logged as unsubscribed; refused when unsigned or without a mailto
	 *
	 * @return void
	 */
	public function testUnsubscribeByMail()
	{
		$a = $this->ready();
		if ($this->mailpit('/api/v1/messages', 'DELETE') === null) {
			$this->markTestSkipped('Mailpit not started (dev/dovecot/run.sh)');
		}
		$identity = $this->identity($a);
		$news = function ($id, $signed) {
			return ($signed ? "Authentication-Results: mx.invalid; dkim=pass header.d=list.invalid\r\n" : '')
				."Message-ID: <".$id.self::MARK.">\r\nFrom: List <news@list.invalid>\r\nTo: ".self::BOX."\r\nSubject: ZzAct ".$id."\r\n"
				."List-Unsubscribe: <mailto:leave@list.invalid?subject=ZzCompose%20unsub&body=id%3D42&bcc=spy@evil.invalid>\r\n"
				."Date: ".date('r', mktime(10, 0, 0, 3, 16, 2025))."\r\n\r\nNews ".$id."\r\n";
		};
		$p = $this->imap();
		$p->appendMessage('INBOX.ZzActA', $news('n1', true));
		$p->appendMessage('INBOX.ZzActA', $news('n2', false));
		$this->sync($a);
		$n1 = $this->row($a, 'n1');
		$this->assertNotNull($n1);
		$c = new InboxComposer($this->db);
		$open = $c->open($this->admin, 'unsubscribe', (int) $n1->rowid);
		$this->assertNotNull($open, $c->errorCode.' '.$c->error);
		$this->assertSame(array('leave@list.invalid'), array_column($open['to'], 'email'), 'the mailto only, never its bcc');
		$this->assertSame(array(), $open['bcc']);
		$this->assertSame('ZzCompose unsub', $open['subject']);
		$this->assertStringContainsString('id=42', $open['html']);
		$this->assertSame($identity, $open['identity'], 'from the address that got the newsletter');
		$this->assertNull($open['source'], 'not an answer: no In-Reply-To, not marked answered');
		$this->assertSame(1, (int) $open['signature_off']);

		$sent = $c->send($this->admin, $open['token'], array('identity' => $identity, 'to' => $open['to'], 'subject' => $open['subject'], 'html' => $open['html'], 'signature' => 0));
		$this->assertNotNull($sent, $c->errorCode.' '.$c->error);
		$list = json_decode((string) $this->mailpit('/api/v1/messages'), true);
		$this->assertSame(1, (int) $list['total']);
		$this->assertSame('leave@list.invalid', $list['messages'][0]['To'][0]['Address']);
		$raw = (string) $this->mailpit('/api/v1/message/'.$list['messages'][0]['ID'].'/raw');
		$this->assertStringNotContainsString('In-Reply-To', $raw);
		$pr = $this->db->prefix();
		$log = $this->db->getRow("SELECT detail, folder_from FROM ".$pr."inbox_action_log WHERE action = 'unsubscribe' AND fk_account = ".((int) $a->id));
		$this->assertTrue(is_object($log), 'logged as unsubscribed');
		$this->assertSame('list.invalid', (string) $log->detail);
		$this->assertSame(0, (int) $this->db->getRow("SELECT answered FROM ".$pr."inbox_mail WHERE rowid = ".((int) $n1->rowid))->answered);

		// Refused: header not signed, no List-Unsubscribe, level below manage
		$this->assertNull($c->open($this->admin, 'unsubscribe', (int) $this->row($a, 'n2')->rowid));
		$this->assertSame('unsub_unsigned', $c->errorCode);
		$this->assertNull($c->open($this->admin, 'unsubscribe', (int) $this->row($a, 'm1')->rowid));
		$this->assertSame('unsub_none', $c->errorCode);
		$sender = $this->user('inboxtest_reader');
		(new InboxAccess($this->db))->grant($this->admin, $a->id, $sender->id, 0, InboxAccess::LEVEL_SEND);
		InboxAccess::clearCache();
		$this->assertNull($c->open($sender, 'unsubscribe', (int) $n1->rowid));
		$this->assertSame('no_manage', $c->errorCode);
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
