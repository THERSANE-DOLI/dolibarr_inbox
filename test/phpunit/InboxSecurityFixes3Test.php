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
 * \file    inbox/test/phpunit/InboxSecurityFixes3Test.php
 * \ingroup inbox
 * \brief   Fixes of the security audit of 2026-11-11 (1.89.1 and after): one test per finding fixed in the classes
 *          (the invitation in the agenda, INBX-046, in InboxInviteTest)
 */

require_once __DIR__.'/InboxTestCase.php';
dol_include_once('/inbox/class/inboxagenda.class.php');
dol_include_once('/inbox/class/inboxbounce.class.php');
dol_include_once('/inbox/class/inboxoutbox.class.php');
dol_include_once('/inbox/class/inboxmessage.class.php');

/**
 * Database only, no mail server
 */
class InboxSecurityFixes3Test extends InboxTestCase
{
	/**
	 * A note as comm/action/card.php shows it
	 *
	 * @param string $note Note of the event
	 * @return string HTML
	 */
	protected static function shown($note)
	{
		return dol_string_onlythesehtmltags(dol_htmlentitiesbr($note));
	}

	/**
	 * INBX-046, INBX-047, INBX-048: values written in the agenda
	 *
	 * @return void
	 */
	public function testAgendaValues()
	{
		$note = InboxAgenda::note("Bonjour <img src=x onerror=alert(1)>\n&lt;svg onload=alert(2)&gt;\nfin");
		$shown = self::shown($note);
		$this->assertStringNotContainsString('<img', $shown);
		$this->assertStringNotContainsString('<svg', $shown);
		$this->assertStringContainsString('Bonjour', $shown);
		$this->assertSame(2, substr_count($note, '<br'), 'lines kept');

		$label = InboxAgenda::field("Re: Devis \"urgent\" <b>x</b>\r\n&quot; autofocus onfocus=&quot;alert(1)");
		$this->assertStringNotContainsString('"', $label, 'value="" of the form of the core');
		$this->assertStringNotContainsString('<', $label);
		$this->assertStringNotContainsString("\n", $label, 'one line');
		$this->assertStringStartsWith("Re: Devis 'urgent'", $label);
		$this->assertSame(10, mb_strlen(InboxAgenda::field(str_repeat('é', 40), 10), 'UTF-8'), 'truncated by characters');
		$this->assertSame("Réunion d'équipe", InboxAgenda::field("Réunion d'équipe"), 'apostrophe kept');
	}

	/**
	 * INBX-048: the recipient of a delivery report is an address, never a quote that would end an attribute
	 *
	 * @return void
	 */
	public function testBounceRecipientIsAnAddress()
	{
		$report = function ($recipient) {
			return "Message-ID: <e9@inbox-test.invalid>\r\nIn-Reply-To: <sent9@inbox-test.invalid>\r\nContent-Type: multipart/report; report-type=delivery-status; boundary=b\r\n\r\n"
				."--b\r\nContent-Type: message/delivery-status\r\n\r\nFinal-Recipient: rfc822; ".$recipient."\r\nAction: failed\r\nStatus: 5.1.1\r\n--b--\r\n";
		};
		$r = InboxBounce::parse($report('x"/autofocus/onfocus=alert(1)//@a.b'));
		$this->assertTrue($r === null || $r['recipients'] === array(), 'forged recipient left out');
		$r = InboxBounce::parse($report("a'b@c.invalid"));
		$this->assertTrue($r === null || $r['recipients'] === array());
		$r = InboxBounce::parse($report('<nobody@client.example.invalid>'));
		$this->assertSame('nobody@client.example.invalid', $r['recipients'][0]['email'], 'a real address still read');
	}

	/**
	 * INBX-047: the text written in a mail recorded on a piece is plain text again, escaped for the agenda
	 *
	 * @return void
	 */
	public function testWrittenTextEscaped()
	{
		$m = new InboxMessage();
		$m->bodyHtml = '<p>Bonjour &lt;img src=x onerror=alert(document.domain)&gt; fin</p><div class="inbox-quote">cité</div>';
		$m->bodyText = '';
		$m->attachments = array(array('name' => '<img src=x onerror=alert(5)>.pdf', 'size' => 10));
		$text = InboxOutbox::writtenText($m);
		$this->assertStringContainsString('<img', $text, 'the text typed, as typed');
		$this->assertStringNotContainsString('cité', $text, 'quote left out');
		$shown = self::shown(InboxAgenda::note($text));
		$this->assertStringNotContainsString('<img', $shown, 'never a tag in the card of the event');
		$this->assertStringContainsString('Bonjour', $shown);
	}
}
