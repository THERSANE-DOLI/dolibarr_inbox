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
 * \file    inbox/test/phpunit/InboxDolibarrSentTest.php
 * \ingroup inbox
 * \brief   Copy in Sent of the mails Dolibarr sends itself from the address of a mailbox (1.132.0)
 */

// From the command line only, never from the web: test/ stays in the Git branches
if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

require_once __DIR__.'/InboxImapTestCase.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/CMailFile.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/hookmanager.class.php';
dol_include_once('/inbox/class/inboxdolibarrsent.class.php');
dol_include_once('/inbox/class/actions_inbox.class.php');
dol_include_once('/inbox/core/modules/modInbox.class.php');

/**
 * Tests of InboxDolibarrSent and of the hook sendMailAfter. The sending tests go to the local test server only
 * (127.0.0.1, test addresses .invalid), the settings of the sending are changed in memory only.
 */
class InboxDolibarrSentTest extends InboxImapTestCase
{
	/** @var array<string,mixed> Settings changed in memory, restored after each test */
	protected $saved = array();

	/** @var HookManager|null Hook manager of the page, restored after each test */
	protected $savedHooks = null;

	/** @var array<string,mixed>|null Hooks of the module in the configuration, restored after each test */
	protected $savedParts = null;

	/**
	 * @return void
	 */
	protected function setUp(): void
	{
		parent::setUp();
		InboxDolibarrSent::reset();
	}

	/**
	 * @return void
	 */
	protected function tearDown(): void
	{
		global $conf, $hookmanager;

		InboxDolibarrSent::reset();
		foreach ($this->saved as $k => $v) {
			if ($v === null) {
				unset($conf->global->$k);
			} else {
				$conf->global->$k = $v;
			}
		}
		$this->saved = array();
		if ($this->savedHooks !== null) {
			$hookmanager = $this->savedHooks;
			$this->savedHooks = null;
		}
		if ($this->savedParts !== null) {
			$conf->modules_parts['hooks']['inbox'] = $this->savedParts['parts'];
			if (empty($this->savedParts['enabled'])) {
				unset($conf->modules['inbox']);
			}
			$this->savedParts = null;
		}
		parent::tearDown();
	}

	/**
	 * Settings of the sending of Dolibarr, in memory only
	 *
	 * @param array<string,mixed> $values Constants
	 * @return void
	 */
	protected function set(array $values)
	{
		global $conf;

		foreach ($values as $k => $v) {
			if (!array_key_exists($k, $this->saved)) {
				$this->saved[$k] = isset($conf->global->$k) ? $conf->global->$k : null;
			}
			if ($v === null) {
				unset($conf->global->$k);
			} else {
				$conf->global->$k = $v;
			}
		}
	}

	/**
	 * Sending of Dolibarr by the local test server (smtps), mails switched on in memory
	 *
	 * @return void
	 */
	protected function smtpToTestServer()
	{
		// « localhost »: smtps refuses an address of a reserved range (127.0.0.1) given as the server
		$this->set(array('MAIN_DISABLE_ALL_MAILS' => 0, 'MAIN_MAIL_SENDMODE' => 'smtps', 'MAIN_MAIL_SENDMODE_TICKET' => null, 'MAIN_MAIL_SMTP_SERVER' => 'localhost',
			'MAIN_MAIL_SMTP_PORT' => self::SMTP_PORT, 'MAIN_MAIL_SMTPS_ID' => self::BOX, 'MAIN_MAIL_SMTPS_PW' => self::BOX_PASSWORD, 'MAIN_MAIL_SMTPS_AUTH_TYPE' => 'LOGIN',
			'MAIN_MAIL_EMAIL_TLS' => 0, 'MAIN_MAIL_EMAIL_STARTTLS' => 0, 'MAIN_MAIL_FORCE_SENDTO' => null, 'MAIN_MAIL_FORCE_FROM' => null, 'MAIN_MAIL_AUTOCOPY_TO' => null,
			'MAIN_MAIL_DEBUG' => 0, 'MAIN_FIX_FOR_BUGGED_MTA' => 0));
	}

	/**
	 * Hooks of the page as Dolibarr loads them: the contexts declared by the descriptor of the module
	 *
	 * @return HookManager
	 */
	protected function hooksOfTheModule()
	{
		global $conf, $hookmanager;

		$mod = new modInbox($this->db);
		$this->savedParts = array('parts' => $conf->modules_parts['hooks']['inbox'] ?? null, 'enabled' => !empty($conf->modules['inbox']));
		$conf->modules_parts['hooks']['inbox'] = $mod->module_parts['hooks']['data'];
		$conf->modules['inbox'] = 'inbox';
		$this->savedHooks = $hookmanager;
		$hookmanager = new HookManager($this->db);

		return $hookmanager;
	}

	/**
	 * A mail of Dolibarr, not sent
	 *
	 * @param string $mode    smtps, mail or swiftmailer
	 * @param string $id      Local part of the Message-ID (the domain of the tests is added, the cleaning finds it)
	 * @param string $from    From
	 * @param string $context Context of CMailFile
	 * @param string $trackid Track id
	 * @param string $force   MAIN_MAIL_FORCE_FROM ('' none)
	 * @return CMailFile
	 */
	protected function mail($mode, $id, $from = 'Service devis <devis@inbox-test.invalid>', $context = 'ticket', $trackid = 'tic42', $force = '')
	{
		$this->set(array('MAIN_MAIL_SENDMODE' => $mode, 'MAIN_MAIL_SENDMODE_TICKET' => null, 'MAIN_MAIL_SENDMODE_EMAILING' => null, 'MAIN_MAIL_SENDMODE_PASSWORDRESET' => null,
			'MAIN_MAIL_FORCE_SENDTO' => null, 'MAIN_MAIL_FORCE_FROM' => $force !== '' ? $force : null, 'MAIN_MAIL_AUTOCOPY_TO' => null, 'MAIN_FIX_FOR_BUGGED_MTA' => 0));
		$more = "In-Reply-To: <m1".self::MARK.">\r\nReferences: <m1".self::MARK.">\r\n";
		$mail = new CMailFile('ZzAct Réponse '.$id, 'Client <client@example.invalid>', $from, '<p>Merci pour votre message</p>', array(), array(), array(), '',
			'cachee@inbox-test.invalid', 0, 1, '', '', $trackid, $more, $context);
		$this->assertSame('', (string) $mail->error);
		if ($mode === 'smtps') {
			$mail->msgid = $id.self::MARK;
			$mail->smtps->setMessageID($mail->msgid);
		}

		return $mail;
	}

	/**
	 * The mailbox of the tests, active, its Sent folder chosen in its card (the test server may have no special use)
	 *
	 * @return InboxAccount
	 */
	protected function readyWithSent()
	{
		$a = $this->ready();
		try {
			$this->imap()->createFolder('INBOX.Sent');
		} catch (Exception $e) {
			// already there (Dovecot makes it)
		}
		$a->setFolderRoles(array('sent' => 'INBOX.Sent'), array('sent'));
		$this->assertGreaterThan(0, $a->update($this->admin), implode(',', $a->errors));

		return $a;
	}

	/**
	 * @param string $folder Folder
	 * @param int    $uid    UID
	 * @return string Source of the mail on the server
	 */
	protected function rawOnServer($folder, $uid)
	{
		$p = $this->imap();
		$p->selectFolder($folder);
		$head = $p->headers(array($uid), 'RFC822', true);
		$body = $p->content(array($uid), 'RFC822', true);

		return (string) ($head[$uid] ?? '').(string) ($body[$uid] ?? '');
	}

	/**
	 * The module declares the context of the sending of Dolibarr, and the setting is on by default
	 *
	 * @return void
	 */
	public function testContextAndSetting()
	{
		$mod = new modInbox($this->db);
		$this->assertContains('mail', $mod->module_parts['hooks']['data']);
		$this->assertContains('toprightmenu', $mod->module_parts['hooks']['data'], 'the other contexts kept');

		$a = $this->box();
		$this->assertTrue($a->filesDolibarrSent(), 'on by default');
		$a->setConnectorConfig(array('dolibarr_sent' => 0));
		$this->assertFalse($a->filesDolibarrSent());
		$a->setConnectorConfig(array('dolibarr_sent' => 1, 'sent_copy' => 'server'));
		$this->assertTrue($a->filesDolibarrSent());
		$this->assertTrue($a->sentCopyByServer(), 'the copy of the mails of inbox is another setting');
	}

	/**
	 * Address of the From sent; mass emailing and new passwords never filed
	 *
	 * @return void
	 */
	public function testFromAndExclusions()
	{
		$this->assertSame('devis@inbox-test.invalid', InboxDolibarrSent::fromAddress('Service « devis », Inbox <Devis@Inbox-Test.invalid>'));
		$this->assertSame('devis@inbox-test.invalid', InboxDolibarrSent::fromAddress(' devis@inbox-test.invalid '));
		$this->assertSame('', InboxDolibarrSent::fromAddress('Robot'));
		$this->assertSame('', InboxDolibarrSent::fromAddress(''));

		$mail = (object) array('sendcontext' => 'emailing', 'trackid' => 'emailing-12');
		$this->assertSame('emailing', InboxDolibarrSent::excluded($mail));
		$mail = (object) array('sendcontext' => 'standard', 'trackid' => 'emailing-12');
		$this->assertSame('emailing', InboxDolibarrSent::excluded($mail), 'mass emailing by its track id');
		$mail = (object) array('sendcontext' => 'passwordreset', 'trackid' => 'use1');
		$this->assertSame('passwordreset', InboxDolibarrSent::excluded($mail));
		foreach (array('standard', 'ticket', 'notification', '') as $context) {
			$mail = (object) array('sendcontext' => $context, 'trackid' => 'pro12');
			$this->assertSame('', InboxDolibarrSent::excluded($mail), $context);
		}
	}

	/**
	 * The sending server of Dolibarr files the copy itself: known servers, or the server of the mailbox set so
	 *
	 * @return void
	 */
	public function testCopyBySendingServer()
	{
		$a = $this->box(false, array('smtp_host' => 'mail.inbox-test.invalid'));
		$this->assertFalse(InboxDolibarrSent::copyBySendingServer($a, ''), 'mail() of PHP: inbox files it');
		$this->assertFalse(InboxDolibarrSent::copyBySendingServer($a, 'smtp.sendgrid.net'), 'a relay files nothing');
		$this->assertTrue(InboxDolibarrSent::copyBySendingServer($a, 'smtp.gmail.com'));
		$this->assertTrue(InboxDolibarrSent::copyBySendingServer($a, 'ssl://smtp.office365.com'), 'scheme of smtps removed');
		$this->assertFalse(InboxDolibarrSent::copyBySendingServer($a, 'tls://MAIL.inbox-test.invalid'), 'server of the mailbox, automatic: inbox files it');
		$a->setConnectorConfig(array('sent_copy' => 'server'));
		$this->assertTrue(InboxDolibarrSent::copyBySendingServer($a, 'tls://MAIL.inbox-test.invalid'), 'server of the mailbox that makes the copy');
		$this->assertFalse(InboxDolibarrSent::copyBySendingServer($a, 'smtp.sendgrid.net'), 'another server than the one of the mailbox');
		$a->setConnectorConfig(array('sent_copy' => 'inbox'));
		$a->smtp_host = 'smtp.gmail.com';
		$this->assertFalse(InboxDolibarrSent::copyBySendingServer($a, 'smtp.gmail.com'), 'the card of the mailbox decides for its own server');
	}

	/**
	 * Source of each sending mode: the Message-ID sent, never the Bcc, CRLF lines; To and Subject for mail()
	 *
	 * @return void
	 */
	public function testSourceOfEachMode()
	{
		$mail = $this->mail('smtps', 'src1');
		$raw = InboxDolibarrSent::rawOf($mail);
		$this->assertStringContainsString("Message-ID: <src1".self::MARK.">\r\n", $raw);
		$this->assertMatchesRegularExpression('/^From: .*devis@inbox-test\.invalid/m', $raw);
		$this->assertStringContainsString('client@example.invalid', $raw);
		$this->assertStringContainsString('In-Reply-To: <m1'.self::MARK.'>', $raw);
		$this->assertStringNotContainsString('cachee@inbox-test.invalid', $raw, 'no Bcc in the copy');
		$this->assertSame(0, preg_match("/(?<!\r)\n/", $raw), 'CRLF only');

		$mail = $this->mail('mail', 'src2');
		$this->assertStringContainsString('Bcc: cachee@inbox-test.invalid', $mail->headers, 'mail() of PHP gets the Bcc in its headers');
		$raw = InboxDolibarrSent::rawOf($mail);
		$this->assertStringStartsWith('To: client@example.invalid'."\r\n".'Subject: =?', $raw, 'To and Subject first, the subject encoded');
		$this->assertStringContainsString('Message-ID: <'.$mail->msgid.'>', $raw);
		$this->assertStringNotContainsString('cachee@inbox-test.invalid', $raw);
		$this->assertStringContainsString("\r\n\r\nThis is a message with multiple parts in MIME format.", $raw);
		$this->assertSame(0, preg_match("/(?<!\r)\n/", $raw));

		$mail = $this->mail('swiftmailer', 'src3');
		$this->assertStringContainsString('cachee@inbox-test.invalid', $mail->message->toString(), 'Swift keeps the Bcc in the message');
		$raw = InboxDolibarrSent::rawOf($mail);
		$this->assertStringContainsString('Message-ID: <'.$mail->msgid.'>', $raw);
		$this->assertStringNotContainsString('cachee@inbox-test.invalid', $raw);

		// A Bcc folded on several lines goes whole; the body is never touched
		$src = "From: a@b.invalid\r\nBcc: x@b.invalid,\r\n y@b.invalid\r\nSubject: s\r\n\r\nBcc: in the body\r\n";
		$this->assertSame("From: a@b.invalid\r\nSubject: s\r\n\r\nBcc: in the body\r\n", InboxDolibarrSent::withoutHeader($src, 'Bcc'));
	}

	/**
	 * What is never filed, before any connection: excluded, address of no mailbox, setting off, disabled, protected,
	 * copy made by the sending server
	 *
	 * @return void
	 */
	public function testNotFiled()
	{
		$copy = new InboxDolibarrSent($this->db);
		$this->assertSame('not_managed', $copy->file($this->mail('smtps', 'nf0')), 'no mailbox yet');

		$a = $this->box(true, array('status' => InboxAccount::STATUS_ACTIVE));
		InboxDolibarrSent::reset();
		$this->assertSame('excluded', $copy->file($this->mail('smtps', 'nf1', 'devis@inbox-test.invalid', 'emailing', 'emailing-3')));
		$this->assertSame('excluded', $copy->file($this->mail('smtps', 'nf2', 'devis@inbox-test.invalid', 'passwordreset', 'use1')));
		$this->assertSame('not_managed', $copy->file($this->mail('smtps', 'nf3', 'Autre <autre@inbox-test.invalid>')));

		$a->setConnectorConfig(array('dolibarr_sent' => 0));
		$this->assertGreaterThan(0, $a->update($this->admin));
		$this->assertSame('off', $copy->file($this->mail('smtps', 'nf4')));

		$a->setConnectorConfig(array('dolibarr_sent' => 1));
		$a->status = InboxAccount::STATUS_DISABLED;
		$this->assertGreaterThan(0, $a->update($this->admin));
		InboxDolibarrSent::reset();
		$this->assertSame('not_managed', $copy->file($this->mail('smtps', 'nf5')), 'disabled mailbox');

		$a->status = InboxAccount::STATUS_ACTIVE;
		$this->assertGreaterThan(0, $a->update($this->admin));
		InboxDolibarrSent::reset();
		// Sent by the server of the mailbox, which makes the copy itself
		$a->setConnectorConfig(array('sent_copy' => 'server'));
		$this->assertGreaterThan(0, $a->update($this->admin));
		$mail = $this->mail('smtps', 'nf6');
		$mail->smtps->setHost(self::IMAP_HOST);
		$this->assertSame('server_copy', $copy->file($mail));

		// Protected, not unlocked in this request (a scheduled job, another user): nothing tried
		$this->db->query("UPDATE ".$this->db->prefix()."inbox_account SET protected = 1, protect_level = 'password' WHERE rowid = ".((int) $a->id));
		$this->assertSame('protected', $copy->file($this->mail('smtps', 'nf7')));
	}

	/**
	 * @param InboxAccount $a      Mailbox
	 * @param string       $email  Address of the identity
	 * @param int          $status 1 active, 0 disabled
	 * @return InboxIdentity
	 */
	protected function identity(InboxAccount $a, $email, $status = 1)
	{
		dol_include_once('/inbox/class/inboxidentity.class.php');
		$i = new InboxIdentity($this->db);
		$i->fk_account = (int) $a->id;
		$i->email = $email;
		$i->status = $status;
		$this->assertGreaterThan(0, $i->create($this->admin), $i->error);

		return $i;
	}

	/**
	 * The mailbox found from an identity (sender profile of Dolibarr on an alias), never guessed when several have it;
	 * with MAIN_MAIL_FORCE_FROM, the Reply-To first. « no_folder » says a mailbox was found (it has no Sent folder).
	 *
	 * @return void
	 */
	public function testMailboxOfAnIdentityAndForcedFrom()
	{
		$copy = new InboxDolibarrSent($this->db);
		$a = $this->box(true, array('status' => InboxAccount::STATUS_ACTIVE));
		$b = $this->box(true, array('status' => InboxAccount::STATUS_ACTIVE, 'email' => 'autre@inbox-test.invalid'));
		$this->identity($a, 'Offres@Inbox-Test.invalid');
		$this->identity($a, 'pause@inbox-test.invalid', 0);
		$this->identity($a, 'commun@inbox-test.invalid');
		$this->identity($b, 'commun@inbox-test.invalid');
		// The address of a mailbox wins over the identity of another one
		$this->identity($b, 'devis@inbox-test.invalid');
		InboxDolibarrSent::reset();

		$this->assertSame('no_folder', $copy->file($this->mail('smtps', 'id1', 'Offres <offres@inbox-test.invalid>')), 'identity of one mailbox');
		$this->assertStringContainsString('offres@inbox-test.invalid', $copy->error);
		$this->assertSame('not_managed', $copy->file($this->mail('smtps', 'id2', 'pause@inbox-test.invalid')), 'disabled identity');
		$this->assertSame('ambiguous', $copy->file($this->mail('smtps', 'id3', 'commun@inbox-test.invalid')), 'identity of two mailboxes');
		$this->assertSame('no_folder', $copy->file($this->mail('smtps', 'id4')));
		$this->assertStringContainsString('devis@inbox-test.invalid', $copy->error);

		// Forced From: the From asked stays as Reply-To, looked up first, then the forced From
		$mail = $this->mail('smtps', 'id5', 'Offres <offres@inbox-test.invalid>', 'ticket', 'tic42', 'robot@inbox-test.invalid');
		$this->assertSame(array('offres@inbox-test.invalid', 'robot@inbox-test.invalid'), InboxDolibarrSent::candidateAddresses($mail));
		$this->assertSame('no_folder', $copy->file($mail));
		$mail = $this->mail('smtps', 'id6', 'Inconnu <inconnu@inbox-test.invalid>', 'ticket', 'tic42', 'Robot <autre@inbox-test.invalid>');
		$this->assertSame('no_folder', $copy->file($mail), 'Reply-To of no mailbox: the forced From');
		$this->assertStringContainsString('autre@inbox-test.invalid', $copy->error);
		// Not forced: the Reply-To is never looked up
		$mail = $this->mail('smtps', 'id7', 'inconnu@inbox-test.invalid');
		$mail->reply_to = 'offres@inbox-test.invalid';
		$this->assertSame(array('inconnu@inbox-test.invalid'), InboxDolibarrSent::candidateAddresses($mail));
		$this->assertSame('not_managed', $copy->file($mail));
	}

	/**
	 * Sent by Dolibarr from an identity of the mailbox of the tests: filed in its Sent folder
	 *
	 * @return void
	 */
	public function testIdentityFiledInSent()
	{
		$a = $this->readyWithSent();
		$this->identity($a, 'offres@inbox-test.invalid');
		InboxDolibarrSent::reset();
		$copy = new InboxDolibarrSent($this->db);
		$this->assertSame('filed', $copy->file($this->mail('smtps', 'idf1', 'Offres <offres@inbox-test.invalid>')), (string) $copy->error);
		$this->assertCount(1, $this->onServer('INBOX.Sent', 'idf1'));
		$mail = $this->mail('smtps', 'idf2', 'Offres <offres@inbox-test.invalid>', 'ticket', 'tic42', 'robot@inbox-test.invalid');
		$this->assertSame('filed', $copy->file($mail), (string) $copy->error);
		$this->assertCount(1, $this->onServer('INBOX.Sent', 'idf2'));
	}

	/**
	 * A copy that cannot be filed never makes the sending fail: the hook answers 0 without error
	 *
	 * @return void
	 */
	public function testFailureNeverFailsTheSending()
	{
		$a = $this->box(false, array('status' => InboxAccount::STATUS_ACTIVE, 'imap_port' => 1));
		$a->setFolderRoles(array('sent' => 'INBOX.Sent'), array('sent'));
		$this->assertGreaterThan(0, $a->create($this->admin), implode(',', $a->errors));
		$mail = $this->mail('smtps', 'fail1');
		$copy = new InboxDolibarrSent($this->db);
		$this->assertSame('failed', $copy->file($mail), 'nothing answers on port 1');

		InboxDolibarrSent::reset();
		$hooks = $this->hooksOfTheModule();
		$hooks->initHooks(array('mail'));
		$action = '';
		$this->assertSame(0, $hooks->executeHooks('sendMailAfter', array('sent' => true), $mail, $action));
		$this->assertSame('', (string) $hooks->error);
		$this->assertSame(array(), (array) $hooks->errors);
		// Not sent: nothing tried
		$this->assertSame(0, $hooks->executeHooks('sendMailAfter', array('sent' => false), $mail, $action));
		// An object that is not a mail of Dolibarr: no exception
		$other = new stdClass();
		$this->assertSame(0, $hooks->executeHooks('sendMailAfter', array('sent' => true), $other, $action));
	}

	/**
	 * Sent by Dolibarr through the test server: copy in Sent, read, same Message-ID, without the Bcc; the mail answered
	 * marked on the server and in the index; one connection for two mails of the request
	 *
	 * @return void
	 */
	public function testSentByDolibarrFiledInSent()
	{
		$a = $this->readyWithSent();
		InboxDolibarrSent::reset();
		$this->smtpToTestServer();
		$this->hooksOfTheModule();

		$mail = $this->mail('smtps', 'dol1');
		$this->smtpToTestServer();
		$this->assertTrue($mail->sendfile(), (string) $mail->error);

		$uids = $this->onServer('INBOX.Sent', 'dol1');
		$this->assertCount(1, $uids, 'one copy in Sent');
		$this->assertStringContainsString('Seen', $this->flagsOnServer('INBOX.Sent', $uids[0]));
		$raw = $this->rawOnServer('INBOX.Sent', $uids[0]);
		$this->assertStringContainsString('Message-ID: <dol1'.self::MARK.'>', $raw);
		$this->assertStringNotContainsString('cachee@inbox-test.invalid', $raw, 'the Bcc is not in the copy');

		// Answered: on the server, then in the index
		$m1 = $this->onServer('INBOX.ZzActA', 'm1');
		$this->assertStringContainsString('Answered', $this->flagsOnServer('INBOX.ZzActA', $m1[0]));
		$row = $this->db->getRow("SELECT answered FROM ".$this->db->prefix()."inbox_mail WHERE fk_account = ".((int) $a->id)." AND message_id = 'm1".self::MARK."'");
		$this->assertSame(1, (int) $row->answered);

		// A second mail of the same request: filed by the same connection
		$mail = $this->mail('smtps', 'dol2');
		$this->smtpToTestServer();
		$this->assertTrue($mail->sendfile(), (string) $mail->error);
		$this->assertCount(1, $this->onServer('INBOX.Sent', 'dol2'));
		// Mass emailing of the same address: sent, never filed
		$mail = $this->mail('smtps', 'dol3', 'devis@inbox-test.invalid', 'emailing', 'emailing-1');
		$this->smtpToTestServer();
		$this->assertTrue($mail->sendfile(), (string) $mail->error);
		$this->assertCount(0, $this->onServer('INBOX.Sent', 'dol3'));
	}

	/**
	 * A connection kept from a previous mail and closed meanwhile by the server: the copy is filed by a new one
	 *
	 * @return void
	 */
	public function testConnectionClosedMeanwhile()
	{
		$this->readyWithSent();
		InboxDolibarrSent::reset();
		$copy = new InboxDolibarrSent($this->db);
		$this->assertSame('filed', $copy->file($this->mail('smtps', 'cl1')));
		// The connection of the request closed under the class (server restarted, timeout)
		$prop = new ReflectionProperty('InboxDolibarrSent', 'connectors');
		$prop->setAccessible(true);
		foreach ($prop->getValue() as $connector) {
			$client = new ReflectionProperty(get_class($connector), 'client');
			$client->setAccessible(true);
			$client->getValue($connector)->getConnection()->logout();
		}
		$this->assertSame('filed', $copy->file($this->mail('smtps', 'cl2')), (string) $copy->error);
		$this->assertCount(1, $this->onServer('INBOX.Sent', 'cl2'));
	}
}
