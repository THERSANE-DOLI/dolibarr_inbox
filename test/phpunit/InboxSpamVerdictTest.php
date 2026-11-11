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
 * \file    inbox/test/phpunit/InboxSpamVerdictTest.php
 * \ingroup inbox
 * \brief   1.86.1: verdict of the anti-spam filter believed only above the entry of the mail into our servers
 */

require_once __DIR__.'/InboxTestCase.php';
dol_include_once('/inbox/class/inboxreader.class.php');

/**
 * Headers in the order of a real mail received by a Plesk server (Postfix + SpamAssassin), names changed
 */
class InboxSpamVerdictTest extends InboxTestCase
{
	/**
	 * @param string $ours    X-Spam-Status written by our server ('' = filter off)
	 * @param string $forged  X-Spam-Status written by the sender ('' = none)
	 * @return array<int,array{0:string,1:string}>
	 */
	protected static function mail($ours, $forged)
	{
		$h = array(array('Return-Path', '<buyer@client.example>'));
		if ($ours !== '') {
			$h[] = array('X-Spam-Checker-Version', 'SpamAssassin 4.0.0 on mx.ours.example');
			$h[] = array('X-Spam-Status', $ours);
		}
		$h[] = array('Delivered-To', 'sales@ours.example');
		$h[] = array('Received', "from out.client.example (out.client.example [192.0.2.10])\r\n\tby mx.ours.example (Postfix) with ESMTPS id ABC\r\n\tfor <sales@ours.example>");
		$h[] = array('Authentication-Results', 'mx.ours.example; dmarc=pass header.from=client.example; dkim=pass header.d=client.example');
		$h[] = array('Received', 'from internal.client.example by out.client.example with SMTP');
		if ($forged !== '') {
			$h[] = array('X-Spam-Status', $forged);
		}
		$h[] = array('From', 'Buyer <buyer@client.example>');

		return $h;
	}

	/**
	 * @return void
	 */
	public function testBelievedAboveOurEntry()
	{
		$servers = array('mx.ours.example');
		// Our filter: its verdict, the « No » of the sender below ignored and named
		$h = self::mail('Yes, score=9.1 required=5.0 tests=BAYES_99', 'No, score=-5.0 required=5.0');
		$v = InboxReader::spamVerdict($h, $servers);
		$this->assertTrue($v['spam']);
		$this->assertSame(9.1, $v['score']);
		$this->assertSame(array('X-Spam-Status'), InboxReader::spamIgnored($h, $servers));
		$this->assertSame(5, InboxReader::trustedEntry($h, $servers), 'the lowest header of our server (Authentication-Results)');

		// Our filter off: the header of the sender is no longer believed
		$h = self::mail('', 'No, score=-5.0 required=5.0');
		$this->assertNull(InboxReader::spamVerdict($h, $servers));
		$this->assertSame(array('X-Spam-Status'), InboxReader::spamIgnored($h, $servers));

		// A mail that never came through our servers (another mailbox host): the topmost header, as before
		$this->assertSame(-1, InboxReader::trustedEntry($h, array('mx.other.example')));
		$this->assertFalse(InboxReader::spamVerdict($h, array('mx.other.example'))['spam']);
		$this->assertSame(array(), InboxReader::spamIgnored($h, array('mx.other.example')));

		// No trusted server declared: the topmost header, nothing said ignored
		$this->assertFalse(InboxReader::spamVerdict($h, array())['spam']);
		$this->assertSame(array(), InboxReader::spamIgnored($h, array()));

		// A false « Received: ... by <our server> » put at the bottom by the sender: our block ends at the first
		// Received that is not ours, its headers stay below (audit INBX-052)
		$h3 = self::mail('', 'No, score=-5.0 required=5.0');
		$h3[] = array('Received', 'from x by mx.ours.example with SMTP');
		$this->assertSame(3, InboxReader::trustedEntry($h3, $servers), 'our Authentication-Results, the false Received below ignored');
		$this->assertNull(InboxReader::spamVerdict($h3, $servers), 'the « No » of the sender never believed');
		$this->assertSame(array('X-Spam-Status'), InboxReader::spamIgnored($h3, $servers));

		// A Received « by » a name that only contains ours: not ours
		$h2 = array(array('X-Spam-Status', 'No, score=0'), array('Received', 'from x by mx.ours.example.evil.test'), array('From', 'a@b.example'));
		$this->assertSame(-1, InboxReader::trustedEntry($h2, $servers));
	}
}
