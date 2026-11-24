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
 * \file    test/phpunit/InboxBounceTest.php
 * \ingroup inbox
 * \brief   Delivery reports (1.25.0): reading of the reports, families, record, bell, event on the piece, sync
 */

// From the command line only, never from the web: test/ stays in the Git branches
if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

require_once __DIR__.'/InboxImapTestCase.php';
dol_include_once('/inbox/class/inboxbounce.class.php');
dol_include_once('/inbox/class/inboxsync.class.php');
require_once DOL_DOCUMENT_ROOT.'/comm/action/class/actioncomm.class.php';

/**
 * Tests of InboxBounce
 */
class InboxBounceTest extends InboxImapTestCase
{
	/**
	 * A report as Postfix writes it (original mail attached, folded diagnostic)
	 *
	 * @param string $original Message-ID of the mail sent
	 * @param string $id       Message-ID of the report
	 * @param string $date     Date of the report
	 * @return string
	 */
	protected function postfix($original, $id = 'dsn1@inbox-test.invalid', $date = '')
	{
		return "Message-ID: <".$id.">\r\nFrom: MAILER-DAEMON@mx.example.invalid (Mail Delivery System)\r\nTo: ".self::BOX."\r\nSubject: Undelivered Mail Returned to Sender\r\n"
			."Date: ".($date !== '' ? $date : date('r'))."\r\nMIME-Version: 1.0\r\nContent-Type: multipart/report; report-type=delivery-status;\r\n\tboundary=\"8A2F.1/mx\"\r\n\r\n"
			."This is a MIME-encapsulated message.\r\n\r\n--8A2F.1/mx\r\nContent-Description: Notification\r\nContent-Type: text/plain; charset=us-ascii\r\n\r\n"
			."I'm sorry to have to inform you that your message could not be delivered.\r\n\r\n--8A2F.1/mx\r\nContent-Description: Delivery report\r\nContent-Type: message/delivery-status\r\n\r\n"
			."Reporting-MTA: dns; mx.example.invalid\r\nArrival-Date: Sat,  8 Nov 2026 10:00:00 +0100\r\n\r\n"
			."Final-Recipient: rfc822; nobody@client.example.invalid\r\nOriginal-Recipient: rfc822;nobody@client.example.invalid\r\nAction: failed\r\nStatus: 5.1.1\r\n"
			."Remote-MTA: dns; mx.client.example.invalid\r\nDiagnostic-Code: smtp; 550 5.1.1 <nobody@client.example.invalid>:\r\n    Recipient address rejected: User unknown\r\n\r\n"
			."Final-Recipient: rfc822; full@client.example.invalid\r\nAction: failed\r\nStatus: 5.2.2\r\nDiagnostic-Code: smtp; 552 5.2.2 Mailbox full\r\n\r\n"
			."Final-Recipient: rfc822; ok@client.example.invalid\r\nAction: delivered\r\nStatus: 2.0.0\r\n\r\n"
			."--8A2F.1/mx\r\nContent-Description: Undelivered Message\r\nContent-Type: message/rfc822\r\n\r\n"
			."Message-ID: <".$original.">\r\nFrom: ".self::BOX."\r\nTo: nobody@client.example.invalid\r\nSubject: Devis 118\r\n\r\nBonjour\r\n--8A2F.1/mx--\r\n";
	}

	/**
	 * @return void
	 */
	public function testParse()
	{
		$r = InboxBounce::parse($this->postfix('sent1@inbox-test.invalid'));
		$this->assertNotNull($r);
		$this->assertSame('sent1@inbox-test.invalid', $r['original']);
		$this->assertSame('dsn1@inbox-test.invalid', $r['report_id']);
		$this->assertCount(2, $r['recipients'], 'delivered recipients are left out');
		$this->assertSame(array('nobody@client.example.invalid', 'failed', '5.1.1', 'no_user'), array_slice(array_values($r['recipients'][0]), 0, 4));
		$this->assertSame('550 5.1.1 nobody@client.example.invalid: Recipient address rejected: User unknown', $r['recipients'][0]['diagnostic'], 'folded line joined, never a < > (INBX-030)');
		$this->assertSame('full', $r['recipients'][1]['family']);

		// Gmail: headers of the original only, delivery status in base64, delayed
		$gmail = "Message-ID: <g1@inbox-test.invalid>\r\nFrom: Mail Delivery Subsystem <mailer-daemon@googlemail.com>\r\nSubject: Delivery Status Notification (Delay)\r\n"
			."Content-Type: multipart/report; boundary=\"zz\"; report-type=delivery-status\r\n\r\n--zz\r\nContent-Type: text/plain\r\n\r\nDelayed\r\n"
			."--zz\r\nContent-Type: message/delivery-status\r\nContent-Transfer-Encoding: base64\r\n\r\n"
			.chunk_split(base64_encode("Reporting-MTA: dns; googlemail.com\n\nFinal-Recipient: rfc822; slow@client.example.invalid\nAction: delayed\nStatus: 4.4.1\n"))
			."--zz\r\nContent-Type: text/rfc822-headers\r\n\r\nMessage-ID: <sent2@inbox-test.invalid>\r\nSubject: Commande\r\n\r\n--zz--\r\n";
		$r = InboxBounce::parse($gmail);
		$this->assertSame('sent2@inbox-test.invalid', $r['original']);
		$this->assertSame(array('slow@client.example.invalid', 'delayed', '4.4.1', 'delayed'), array_slice(array_values($r['recipients'][0]), 0, 4));

		// No original part: In-Reply-To of the report
		$bare = "Message-ID: <e1@inbox-test.invalid>\r\nIn-Reply-To: <sent3@inbox-test.invalid>\r\nContent-Type: multipart/report; report-type=delivery-status; boundary=b\r\n\r\n"
			."--b\r\nContent-Type: message/delivery-status\r\n\r\nFinal-Recipient: rfc822;<x@client.example.invalid>\r\nAction: failed\r\nStatus: 5.7.1\r\n--b--\r\n";
		$r = InboxBounce::parse($bare);
		$this->assertSame(array('sent3@inbox-test.invalid', 'x@client.example.invalid', 'refused'), array($r['original'], $r['recipients'][0]['email'], $r['recipients'][0]['family']));

		// Not a standard report: nothing guessed
		$this->assertNull(InboxBounce::parse("Message-ID: <n@inbox-test.invalid>\r\nFrom: MAILER-DAEMON@x.invalid\r\nSubject: Undelivered Mail\r\nContent-Type: text/plain\r\n\r\nFinal-Recipient: rfc822; a@b.invalid\r\nAction: failed\r\n"));
		$this->assertNull(InboxBounce::parse("Content-Type: multipart/report; report-type=disposition-notification; boundary=b\r\n\r\n--b--\r\n"), 'read receipt');
		$this->assertFalse(InboxBounce::isReport("Content-Type: text/plain\r\n\r\nx"));
		$this->assertTrue(InboxBounce::isReport($bare));

		foreach (array('5.1.1' => 'no_user', '5.1.2' => 'no_domain', '5.1.10' => 'no_domain', '4.2.2' => 'full', '5.3.4' => 'too_big', '5.7.26' => 'refused', '4.7.0' => 'temporary', '5.9.9' => 'refused', '' => 'refused') as $code => $family) {
			$this->assertSame($family, InboxBounce::family('failed', (string) $code), (string) $code);
		}
	}

	/**
	 * @return void
	 */
	public function testRecordTellsOnce()
	{
		$a = $this->box(true);
		$author = $this->user('bounceauthor');
		$p = $this->db->prefix();
		$this->db->query("INSERT INTO ".$p."inbox_outbox (entity, fk_user, fk_account, message_id, subject, date_send, status, date_creation) VALUES (1, ".((int) $author->id).", ".((int) $a->id).","
			." 'sent9@inbox-test.invalid', 'Devis 118', '".$this->db->idate(dol_now())."', 1, '".$this->db->idate(dol_now())."')");
		// The sending was recorded on a proposal (1.17.0): the failure is added on the same piece
		$event = new ActionComm($this->db);
		$event->type_code = 'AC_EMAIL';
		$event->code = 'AC_EMAIL';
		$event->label = 'Envoi inbox-test.invalid';
		$event->datep = dol_now();
		$event->userownerid = (int) $this->admin->id;
		$event->elementtype = 'propal';
		$event->fk_element = 999999;
		$event->email_msgid = 'sent9@inbox-test.invalid';
		$this->assertGreaterThan(0, $event->create($this->admin), (string) $event->error.implode(",", (array) $event->errors));

		$b = new InboxBounce($this->db);
		$this->assertSame(2, $b->record((int) $a->id, $this->postfix('sent9@inbox-test.invalid', 'dsn9@inbox-test.invalid'), dol_now()));
		$this->assertSame(0, $b->record((int) $a->id, $this->postfix('sent9@inbox-test.invalid', 'dsn9@inbox-test.invalid'), dol_now()), 'same report again: nothing');
		$n = $this->db->getRows("SELECT type, payload FROM ".$p."inbox_notification WHERE fk_user = ".((int) $author->id)." LIMIT 10");
		$this->assertCount(1, $n);
		$this->assertSame('bounce', $n[0]->type);
		$this->assertStringContainsString('nobody@client.example.invalid', (string) $n[0]->payload);
		$ev = $this->db->getRows("SELECT label, elementtype, fk_element, note FROM ".$p."actioncomm WHERE code = 'AC_INBOX_BOUNCE' AND fk_element = 999999 LIMIT 10");
		$this->assertCount(1, $ev, 'one event on the piece');
		$this->assertSame('propal', $ev[0]->elementtype);
		$this->assertStringContainsString('Devis 118', $ev[0]->label);

		// The mail sent, seen in its list and its reading
		$rows = array(array('id' => 0));
		$this->db->query("INSERT INTO ".$p."inbox_mail (fk_account, fk_folder, remote_id, message_id, subject, date_index) VALUES (".((int) $a->id).", 0, '1:1', 'sent9@inbox-test.invalid', 'Devis 118', '".$this->db->idate(dol_now())."')");
		$rows[0]['id'] = (int) $this->db->last_insert_id($p.'inbox_mail');
		$b->decorate($rows);
		$this->assertSame(1, $rows[0]['bounced']);
		$this->db->query("DELETE FROM ".$p."inbox_mail WHERE rowid = ".((int) $rows[0]['id']));
		$failures = $b->forMail('<sent9@inbox-test.invalid>', array((int) $a->id));
		$this->assertSame(array('nobody@client.example.invalid', 'full@client.example.invalid'), array_column($failures, 'email'));

		// An old report (first indexing of a mailbox): recorded, never told
		$this->assertSame(2, $b->record((int) $a->id, $this->postfix('sent9@inbox-test.invalid', 'dsn-old@inbox-test.invalid'), dol_now() - 30 * 86400));
		$this->assertCount(1, $this->db->getRows("SELECT rowid FROM ".$p."inbox_notification WHERE fk_user = ".((int) $author->id)." LIMIT 10"));
		// A delay alone: recorded, nobody told, no mark on the mail sent
		$this->db->query("INSERT INTO ".$p."inbox_outbox (entity, fk_user, fk_account, message_id, subject, date_send, status, date_creation) VALUES (1, ".((int) $author->id).", ".((int) $a->id).","
			." 'sent8@inbox-test.invalid', 'Devis 119', '".$this->db->idate(dol_now())."', 1, '".$this->db->idate(dol_now())."')");
		$delayed = str_replace(array('Action: failed', 'dsn1@'), array('Action: delayed', 'dsn-delay@'), $this->postfix('sent8@inbox-test.invalid'));
		$this->assertSame(2, $b->record((int) $a->id, $delayed, dol_now()));
		$this->assertSame(array(), $b->forMail('sent8@inbox-test.invalid', array((int) $a->id)));
		// Not a report, or a report without the mail sent: nothing
		$this->assertSame(0, $b->record((int) $a->id, "Content-Type: text/plain\r\n\r\nx", dol_now()));
	}

	/**
	 * @return void
	 */
	public function testSyncAndReading()
	{
		$a = $this->ready();
		$sent = "Message-ID: <sent7".self::MARK.">\r\nFrom: ".self::BOX."\r\nTo: nobody@client.example.invalid\r\nSubject: ZzAct devis\r\nDate: ".date('r')."\r\n\r\nBonjour\r\n";
		// The copy in Sent, indexed in the same pass as the report: the report is read at the end of the pass (INBX-031)
		$this->imap()->appendMessage('INBOX.Sent', $sent);
		$this->imap()->appendMessage('INBOX', $this->postfix('sent7'.self::MARK, 'dsn7'.self::MARK));
		$this->sync($a);
		$row = $this->db->getRow("SELECT recipient, fk_account FROM ".$this->db->prefix()."inbox_bounce WHERE report_id = 'dsn7".self::MARK."' AND recipient = 'nobody@client.example.invalid'");
		$this->assertIsObject($row, 'the report is read by the synchronisation');
		$this->assertSame((int) $a->id, (int) $row->fk_account);

		$r = new InboxReader($this->db);
		$this->assertTrue($r->load($this->admin, (int) $this->row($a, 'dsn7')->rowid));
		$d = $r->describe($this->admin, $r->raw($this->admin));
		$this->assertSame('no_user', $d['report']['recipients'][0]['family']);
		$this->assertSame((int) $this->row($a, 'sent7')->rowid, $d['report']['sent']['mail'], 'link to the mail sent');
		$this->assertTrue($r->load($this->admin, (int) $this->row($a, 'sent7')->rowid));
		$d = $r->describe($this->admin, $r->raw($this->admin));
		$this->assertCount(2, $d['bounces']);
		$this->assertSame((int) $this->row($a, 'dsn7')->rowid, $d['bounces'][0]['report']['mail'], 'link to the report');
		$this->assertSame(array(), $r->describe($this->admin, $r->raw($this->admin))['report'] ?? array(), 'a mail sent is not a report');
	}

	/**
	 * A report forged by anyone (audit of 2026-11-09): about a mail of another mailbox, or of a mail never sent,
	 * nothing; its sentence never goes as HTML in the agenda of Dolibarr (INBX-030, INBX-031)
	 *
	 * @return void
	 */
	public function testForgedReport()
	{
		$a = $this->box(true);
		$other = $this->box(true, array('email' => 'other'.self::BOX));
		$author = $this->user('bounceforged');
		$p = $this->db->prefix();
		$this->db->query("INSERT INTO ".$p."inbox_outbox (entity, fk_user, fk_account, message_id, subject, date_send, status, date_creation) VALUES (1, ".((int) $author->id).", ".((int) $a->id).","
			." 'sent5@inbox-test.invalid', 'Devis 120', '".$this->db->idate(dol_now())."', 1, '".$this->db->idate(dol_now())."')");
		$event = new ActionComm($this->db);
		$event->type_code = 'AC_EMAIL';
		$event->code = 'AC_EMAIL';
		$event->label = 'Envoi inbox-test.invalid';
		$event->datep = dol_now();
		$event->userownerid = (int) $this->admin->id;
		$event->elementtype = 'propal';
		$event->fk_element = 999998;
		$event->email_msgid = 'sent5@inbox-test.invalid';
		$this->assertGreaterThan(0, $event->create($this->admin), (string) $event->error);
		$forged = str_replace('Diagnostic-Code: smtp; 552 5.2.2 Mailbox full', 'Diagnostic-Code: smtp; 550 <img src=x onerror=alert(1)> write to evil@x.invalid',
			$this->postfix('sent5@inbox-test.invalid', 'dsn5@inbox-test.invalid'));

		$b = new InboxBounce($this->db);
		$this->assertSame(0, $b->record((int) $other->id, $forged, dol_now()), 'another mailbox than the one of the sending: nothing');
		$this->assertSame(0, $b->record((int) $a->id, $this->postfix('never@inbox-test.invalid', 'dsn6@inbox-test.invalid'), dol_now()), 'a mail never sent: nothing');
		$this->assertSame(2, $b->record((int) $a->id, $forged, dol_now()));
		$ev = $this->db->getRow("SELECT note FROM ".$p."actioncomm WHERE code = 'AC_INBOX_BOUNCE' AND fk_element = 999998");
		$this->assertIsObject($ev);
		$this->assertDoesNotMatchRegularExpression('/<(?!br>)/i', (string) $ev->note, 'no other tag than <br> in the note');
		$this->assertStringContainsString('img src=x onerror=alert(1)', (string) $ev->note, 'the sentence kept as text');
		$this->assertCount(2, $b->forMail('sent5@inbox-test.invalid', array((int) $a->id)));
		$this->assertSame(array(), $b->forMail('sent5@inbox-test.invalid', array((int) $other->id)), 'reports of the mailboxes read only');

		// A sending of Dolibarr (no outbox), from the address of the mailbox: counted
		$event2 = new ActionComm($this->db);
		$event2->type_code = 'AC_EMAIL';
		$event2->code = 'AC_EMAIL';
		$event2->label = 'Envoi inbox-test.invalid';
		$event2->datep = dol_now();
		$event2->userownerid = (int) $this->admin->id;
		$event2->email_msgid = 'sent4@inbox-test.invalid';
		$event2->email_from = 'Devis <'.self::BOX.'>';
		$this->assertGreaterThan(0, $event2->create($this->admin), (string) $event2->error);
		$this->assertSame(0, $b->record((int) $other->id, $this->postfix('sent4@inbox-test.invalid', 'dsn4@inbox-test.invalid'), dol_now()));
		$this->assertSame(2, $b->record((int) $a->id, $this->postfix('sent4@inbox-test.invalid', 'dsn4@inbox-test.invalid'), dol_now()));

		// The note of an older event, rewritten by the update
		$note = InboxBounce::noteHtml("x@client.example.invalid : refused\n<img src=x onerror=alert(1)>");
		$this->assertStringContainsString('&lt;img', $note);
		$this->assertStringContainsString('<br>', $note);
		$this->assertDoesNotMatchRegularExpression('/<(?!br>)/i', $note);
	}
}
