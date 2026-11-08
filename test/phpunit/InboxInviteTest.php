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
 * \file    inbox/test/phpunit/InboxInviteTest.php
 * \ingroup inbox
 * \brief   Invitations (iCalendar, answer, agenda) and read receipts (lot W8 1.22.0)
 */

dol_include_once('/inbox/class/inboxinvite.class.php');
dol_include_once('/inbox/class/inboxreceipt.class.php');
dol_include_once('/inbox/class/inboxidentity.class.php');

/**
 * The reader of iCalendar needs nothing; the answers need dev/dovecot/run.sh (Dovecot relays to Mailpit)
 */
class InboxInviteTest extends InboxImapTestCase
{
	const MAILPIT = 'http://127.0.0.1:18025';

	const UID = 'zz-invite-1@inbox-test.invalid';

	/** @var string|null */
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
		$this->forget();
	}

	/**
	 * @return void
	 */
	protected function tearDown(): void
	{
		global $conf;

		$conf->global->MAIN_DISABLE_ALL_MAILS = $this->disabled;
		$this->forget();
		parent::tearDown();
	}

	/**
	 * Rows and events of the test invitation removed
	 *
	 * @return void
	 */
	protected function forget()
	{
		$p = $this->db->prefix();
		$rows = $this->db->getRows("SELECT fk_actioncomm FROM ".$p."inbox_invite WHERE uid_hash = '".sha1(self::UID)."' LIMIT 100");
		foreach (is_array($rows) ? $rows : array() as $r) {
			if ((int) $r->fk_actioncomm > 0) {
				$this->db->query("DELETE FROM ".$p."actioncomm_resources WHERE fk_actioncomm = ".((int) $r->fk_actioncomm));
				$this->db->query("DELETE FROM ".$p."actioncomm WHERE id = ".((int) $r->fk_actioncomm));
				$this->db->query("DELETE FROM ".$p."inbox_link WHERE element_type = 'action' AND fk_element = ".((int) $r->fk_actioncomm));
			}
		}
		$this->db->query("DELETE FROM ".$p."inbox_invite WHERE uid_hash = '".sha1(self::UID)."'");
	}

	/**
	 * @param string $path Path
	 * @return string|null
	 */
	protected function mailpit($path, $method = 'GET')
	{
		$ctx = stream_context_create(array('http' => array('method' => $method, 'timeout' => 3, 'ignore_errors' => true)));
		$res = @file_get_contents(self::MAILPIT.$path, false, $ctx);

		return $res === false ? null : $res;
	}

	/**
	 * Invitation as Outlook writes it: Windows zone, folded lines, escaped text, an alarm inside the event
	 *
	 * @param string $method   REQUEST or CANCEL
	 * @param int    $sequence Sequence
	 * @param string $start    Local start (Paris)
	 * @return string
	 */
	protected static function ics($method = 'REQUEST', $sequence = 0, $start = '20261110T100000')
	{
		return "BEGIN:VCALENDAR\r\nMETHOD:".$method."\r\nPRODID:Microsoft Exchange Server 2010\r\nVERSION:2.0\r\n"
			."BEGIN:VTIMEZONE\r\nTZID:Romance Standard Time\r\nBEGIN:STANDARD\r\nDTSTART:16010101T030000\r\nTZOFFSETFROM:+0200\r\nTZOFFSETTO:+0100\r\nEND:STANDARD\r\nEND:VTIMEZONE\r\n"
			."BEGIN:VEVENT\r\nORGANIZER;CN=\"Durand, Marc\":mailto:orga@inbox-test.invalid\r\n"
			."ATTENDEE;ROLE=REQ-PARTICIPANT;PARTSTAT=NEEDS-ACTION;RSVP=TRUE;CN=Devis:mailto:DEVIS@inbox-test.invalid\r\n"
			."ATTENDEE;PARTSTAT=ACCEPTED;CN=Autre:mailto:autre@inbox-test.invalid\r\n"
			."DESCRIPTION;LANGUAGE=fr-FR:Ordre du jour\\n1. Prix\\, délais\\; suite\r\n"
			."UID:".self::UID."\r\nSUMMARY;LANGUAGE=fr-FR:Revue du devis \r\n DV2026-001\r\n"
			."DTSTART;TZID=Romance Standard Time:".$start."\r\nDTEND;TZID=Romance Standard Time:20261110T113000\r\n"
			."RRULE:FREQ=WEEKLY;INTERVAL=2;BYDAY=TU;COUNT=4\r\nSEQUENCE:".$sequence."\r\nLOCATION:Salle B\r\n"
			.($method === 'CANCEL' ? "STATUS:CANCELLED\r\n" : '')
			."BEGIN:VALARM\r\nDESCRIPTION:REMINDER\r\nTRIGGER;RELATED=START:-PT15M\r\nACTION:DISPLAY\r\nEND:VALARM\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
	}

	/**
	 * @return void
	 */
	public function testParseOutlook()
	{
		global $langs;

		$cal = InboxIcal::parse(self::ics());
		$this->assertNotNull($cal);
		$this->assertSame('REQUEST', $cal['method']);
		$this->assertCount(1, $cal['events'], 'the alarm is not an event');
		$e = $cal['events'][0];
		$this->assertSame('Revue du devis DV2026-001', $e['summary'], 'folded line joined');
		$this->assertSame("Ordre du jour\n1. Prix, délais; suite", $e['description'], 'escapes read');
		$this->assertSame('Europe/Paris', $e['tz'], 'zone of Windows understood');
		$this->assertSame(gmmktime(9, 0, 0, 11, 10, 2026), $e['start'], '10:00 in Paris in November = 09:00 UTC');
		$this->assertSame(gmmktime(10, 30, 0, 11, 10, 2026), $e['end']);
		$this->assertSame(array('email' => 'orga@inbox-test.invalid', 'name' => 'Durand, Marc', 'partstat' => 'NEEDS-ACTION', 'role' => ''), $e['organizer']);
		$this->assertSame('devis@inbox-test.invalid', $e['attendees'][0]['email'], 'addresses in lower case');
		$this->assertSame('ACCEPTED', $e['attendees'][1]['partstat']);

		$langs->loadLangs(array('main', 'inbox@inbox'));
		$text = InboxIcal::rruleText($e['rrule'], $langs);
		$this->assertStringContainsString($langs->transnoentities('InboxRruleEveryN_WEEKLY', 2), $text);
		$this->assertStringContainsString($langs->transnoentities('Tuesday'), $text);
		$this->assertStringContainsString($langs->transnoentities('InboxRruleCount', 4), $text);

		// A whole day, UTC, duration instead of the end
		$day = InboxIcal::parse("BEGIN:VCALENDAR\nBEGIN:VEVENT\nUID:a\nDTSTART;VALUE=DATE:20261224\nDTEND;VALUE=DATE:20261226\nEND:VEVENT\nBEGIN:VEVENT\nUID:b\nDTSTART:20261201T080000Z\nDURATION:PT45M\nEND:VEVENT\nEND:VCALENDAR");
		$this->assertSame('PUBLISH', $day['method'], 'no method: published, not an invitation');
		$this->assertTrue($day['events'][0]['allday']);
		$this->assertSame(2 * 86400, $day['events'][0]['end'] - $day['events'][0]['start']);
		$this->assertSame(gmmktime(8, 45, 0, 12, 1, 2026), $day['events'][1]['end'], 'end by the duration');

		$this->assertNull(InboxIcal::parse('not a calendar'));
		$this->assertNull(InboxIcal::parse("BEGIN:VCALENDAR\n".str_repeat('X', InboxIcal::MAX_SIZE)), 'too large');

		// The answer: the event as the organizer sent it, the attendee and its choice, lines folded at 75 octets
		$reply = InboxIcal::reply($e, 'devis@inbox-test.invalid', 'Équipe devis', 'ACCEPTED', 'Je serai là, avec un commentaire assez long pour être plié en plusieurs lignes');
		$this->assertStringContainsString("METHOD:REPLY\r\n", $reply);
		$this->assertStringContainsString('UID:'.self::UID, $reply);
		$unfolded = str_replace("\r\n ", '', $reply);
		$this->assertStringContainsString('ATTENDEE;PARTSTAT=ACCEPTED;CN="Équipe devis":mailto:devis@inbox-test.invalid', $unfolded);
		$this->assertStringContainsString('DTSTART;TZID=Romance Standard Time:20261110T100000', $unfolded);
		foreach (explode("\r\n", $reply) as $line) {
			$this->assertLessThanOrEqual(75, strlen($line));
		}
		$back = InboxIcal::parse($reply);
		$this->assertSame('ACCEPTED', $back['events'][0]['attendees'][0]['partstat'], 'our answer reads back');
	}

	/**
	 * The invitation part is not the text of the mail
	 *
	 * @return void
	 */
	public function testMimeCalendarPart()
	{
		$m = InboxMimeParser::parse($this->rawInvite('inv0'));
		$this->assertStringContainsString('BEGIN:VCALENDAR', $m->calendar);
		$this->assertStringNotContainsString('VCALENDAR', $m->bodyText);
		$this->assertStringContainsString('Vous êtes invité', $m->bodyText);
		$this->assertStringContainsString('BEGIN:VCALENDAR', InboxInvite::calendarOf($m));
	}

	/**
	 * @param string $id       Local part of the Message-ID
	 * @param string $method   Method
	 * @param int    $sequence Sequence
	 * @param string $start    Start
	 * @return string
	 */
	protected function rawInvite($id, $method = 'REQUEST', $sequence = 0, $start = '20261110T100000')
	{
		return "Message-ID: <".$id.self::MARK.">\r\nFrom: Marc Durand <orga@inbox-test.invalid>\r\nTo: ".self::BOX."\r\nSubject: ZzInvite ".$id."\r\n"
			."Date: ".date('r', mktime(10, 0, 0, 11, 2, 2026))."\r\nMIME-Version: 1.0\r\nContent-Type: multipart/alternative; boundary=\"b1\"\r\n\r\n"
			."--b1\r\nContent-Type: text/plain; charset=utf-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\nVous êtes invité.\r\n"
			."--b1\r\nContent-Type: text/calendar; charset=utf-8; method=".$method."\r\nContent-Transfer-Encoding: base64\r\n\r\n"
			.chunk_split(base64_encode(self::ics($method, $sequence, $start)))."--b1--\r\n";
	}

	/**
	 * @param string $raw Raw mail
	 * @return void
	 */
	protected function put($raw)
	{
		$this->imap()->appendMessage('INBOX.ZzActA', $raw);
	}

	/**
	 * @return void
	 */
	public function testAnswerAgendaUpdateCancel()
	{
		global $langs;

		$a = $this->ready();
		if ($this->mailpit('/api/v1/messages', 'DELETE') === null) {
			$this->markTestSkipped('Mailpit not started (dev/dovecot/run.sh)');
		}
		if (!isModEnabled('agenda')) {
			$this->markTestSkipped('Module Agenda off on the test database');
		}
		$this->put($this->rawInvite('inv1'));
		$this->sync($a);
		$row = $this->row($a, 'inv1');
		$this->assertNotNull($row);

		$reader = new InboxReader($this->db);
		$this->assertTrue($reader->load($this->admin, (int) $row->rowid));
		$view = $reader->describe($this->admin, $reader->raw($this->admin));
		$inv = $view['invite'];
		$this->assertNotNull($inv);
		$this->assertSame('REQUEST', $inv['method']);
		$this->assertSame('devis@inbox-test.invalid', $inv['me']);
		$this->assertTrue($inv['can_reply']);
		$this->assertNull($inv['reply']);
		$this->assertNull($view['receipt'], 'no receipt asked');

		$invite = new InboxInvite($this->db);
		$res = $invite->reply($this->admin, (int) $row->rowid, 'ACCEPTED', true, 'Merci', true);
		$this->assertNotNull($res, $invite->errorCode.' '.$invite->error);
		$this->assertTrue($res['sent']);
		$this->assertGreaterThan(0, $res['agenda']);
		$list = json_decode((string) $this->mailpit('/api/v1/messages'), true);
		$this->assertSame(1, (int) $list['total']);
		$this->assertSame('orga@inbox-test.invalid', $list['messages'][0]['To'][0]['Address'], 'to the organizer');
		$raw = (string) $this->mailpit('/api/v1/message/'.$list['messages'][0]['ID'].'/raw');
		$this->assertStringContainsString('method=REPLY', $raw);
		$this->assertStringContainsString('In-Reply-To: <inv1'.self::MARK.'>', $raw);
		$this->assertTrue(is_object($this->db->getRow("SELECT rowid FROM ".$this->db->prefix()."inbox_action_log WHERE action = 'invite_reply' AND fk_account = ".((int) $a->id))));

		require_once DOL_DOCUMENT_ROOT.'/comm/action/class/actioncomm.class.php';
		$ev = new ActionComm($this->db);
		$this->assertGreaterThan(0, $ev->fetch($res['agenda']));
		$this->assertSame('Revue du devis DV2026-001', $ev->label);
		$this->assertSame(gmmktime(9, 0, 0, 11, 10, 2026), (int) $ev->datep);
		$this->assertSame('Salle B', $ev->location);
		$this->assertSame((int) $this->admin->id, (int) $ev->userownerid);

		$view = $reader->describe($this->admin, $reader->raw($this->admin));
		$this->assertSame('ACCEPTED', $view['invite']['reply']['partstat'], 'answer kept');
		$this->assertSame($res['agenda'], $view['invite']['agenda']['id']);
		$this->assertFalse($view['invite']['agenda']['outdated']);

		// Update received (sequence 1, one hour later): the card says so, the agenda follows on a click
		$this->put($this->rawInvite('inv2', 'REQUEST', 1, '20261110T110000'));
		$this->sync($a);
		$row2 = $this->row($a, 'inv2');
		$r2 = new InboxReader($this->db);
		$this->assertTrue($r2->load($this->admin, (int) $row2->rowid));
		$v2 = $r2->describe($this->admin, $r2->raw($this->admin));
		$this->assertTrue($v2['invite']['agenda']['outdated']);
		$this->assertNotNull($invite->agenda($this->admin, (int) $row2->rowid, 'add'), $invite->errorCode);
		$ev->fetch($res['agenda']);
		$this->assertSame(gmmktime(10, 0, 0, 11, 10, 2026), (int) $ev->datep, 'the same event moved');

		// Cancel: marked cancelled, not removed
		$this->put($this->rawInvite('inv3', 'CANCEL', 2));
		$this->sync($a);
		$row3 = $this->row($a, 'inv3');
		$r3 = new InboxReader($this->db);
		$this->assertTrue($r3->load($this->admin, (int) $row3->rowid));
		$v3 = $r3->describe($this->admin, $r3->raw($this->admin));
		$this->assertTrue($v3['invite']['cancelled']);
		$this->assertFalse($v3['invite']['can_reply']);
		$this->assertNotNull($invite->agenda($this->admin, (int) $row3->rowid, 'cancel'), $invite->errorCode);
		$ev->fetch($res['agenda']);
		$langs->loadLangs(array('main', 'inbox@inbox'));
		$this->assertStringStartsWith($langs->transnoentities('InboxInviteCancelledPrefix'), $ev->label);

		$this->assertNull($invite->reply($this->admin, (int) $row->rowid, 'MAYBE', true, '', false), 'unknown answer');
		$this->assertSame('bad_value', $invite->errorCode);
		$bob = $this->user('bob');
		$this->assertNull($invite->reply($bob, (int) $row->rowid, 'ACCEPTED', true, '', false), 'a mailbox bob cannot read');
		$this->assertSame('not_found', $invite->errorCode);
	}

	/**
	 * @return void
	 */
	public function testReadReceipt()
	{
		$a = $this->ready();
		if ($this->mailpit('/api/v1/messages', 'DELETE') === null) {
			$this->markTestSkipped('Mailpit not started (dev/dovecot/run.sh)');
		}
		$raw = "Message-ID: <rr1".self::MARK.">\r\nReturn-Path: <client@example.invalid>\r\nFrom: Client <client@example.invalid>\r\nTo: ".self::BOX."\r\n"
			."Subject: ZzInvite accuse\r\nDisposition-Notification-To: Client <client@example.invalid>\r\nDate: ".date('r', mktime(10, 0, 0, 11, 2, 2026))."\r\n\r\nMerci de confirmer.\r\n";
		$this->put($raw);
		$abuse = str_replace(array('<rr1', 'Disposition-Notification-To: Client <client@example.invalid>'), array('<rr2', 'Disposition-Notification-To: tracker@example.invalid'), $raw);
		$this->put($abuse);
		$this->sync($a);
		$row = $this->row($a, 'rr1');
		$reader = new InboxReader($this->db);
		$this->assertTrue($reader->load($this->admin, (int) $row->rowid));
		$view = $reader->describe($this->admin, $reader->raw($this->admin));
		$this->assertSame(array('to' => 'client@example.invalid', 'abuse' => false), $view['receipt']);
		$this->assertNull($view['invite']);

		$params = new InboxUserParam($this->db);
		$params->set($this->admin, 'mdn_mode', 'never');
		$this->assertNull(InboxReceipt::view($reader, InboxReader::headers($reader->raw($this->admin)), 'never'), 'never asked when the user chose so');
		$params->remove($this->admin, 'mdn_mode');

		$rec = new InboxReceipt($this->db);
		$res = $rec->answer($this->admin, (int) $row->rowid, true);
		$this->assertNotNull($res, $rec->errorCode.' '.$rec->error);
		$list = json_decode((string) $this->mailpit('/api/v1/messages'), true);
		$this->assertSame(1, (int) $list['total']);
		$sent = (string) $this->mailpit('/api/v1/message/'.$list['messages'][0]['ID'].'/raw');
		$this->assertStringContainsString('report-type=disposition-notification', $sent);
		$this->assertStringContainsString('Disposition: manual-action/MDN-sent-manually; displayed', $sent);
		$this->assertStringContainsString('Original-Message-ID: <rr1'.self::MARK.'>', $sent);
		$this->assertStringNotContainsString('Merci de confirmer', $sent, 'the text of the mail is never sent back');
		$uids = $this->onServer('INBOX.ZzActA', 'rr1');
		$this->assertStringContainsString('$MDNSent', $this->flagsOnServer('INBOX.ZzActA', $uids[0]), 'keyword on the server, as Thunderbird');
		$reader->load($this->admin, (int) $row->rowid);
		$this->assertNull($reader->describe($this->admin, $reader->raw($this->admin))['receipt'], 'not asked again');
		$this->assertNull($rec->answer($this->admin, (int) $row->rowid, true), 'handled already');

		// Another address than the return path: only said, never sent; ignoring sets the keyword without sending
		$row2 = $this->row($a, 'rr2');
		$reader->load($this->admin, (int) $row2->rowid);
		$v2 = $reader->describe($this->admin, $reader->raw($this->admin));
		$this->assertTrue($v2['receipt']['abuse']);
		$this->assertNull($rec->answer($this->admin, (int) $row2->rowid, true));
		$this->assertSame('receipt_abuse', $rec->errorCode);
		$this->assertNotNull($rec->answer($this->admin, (int) $row2->rowid, false));
		$this->assertSame(1, (int) json_decode((string) $this->mailpit('/api/v1/messages'), true)['total'], 'nothing more sent');
		$uids = $this->onServer('INBOX.ZzActA', 'rr2');
		$this->assertStringContainsString('$MDNSent', $this->flagsOnServer('INBOX.ZzActA', $uids[0]));
	}
}
