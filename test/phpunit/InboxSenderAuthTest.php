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
 * \file    inbox/test/phpunit/InboxSenderAuthTest.php
 * \ingroup inbox
 * \brief   1.88.11: results repeated as is by the receiving server (Plesk) no longer taken for an imitation
 */

require_once __DIR__.'/InboxTestCase.php';
dol_include_once('/inbox/class/inboxsenderauth.class.php');

/**
 * Headers only, no database write, no mail server
 */
class InboxSenderAuthTest extends InboxTestCase
{
	const SERVER = 'mx.inbox-test.invalid';

	/**
	 * Result of the check of raw headers
	 *
	 * @param	string	$raw	Raw headers
	 * @param	string	$from	From address
	 * @return	array{result:string,detail:string}
	 */
	protected function check($raw, $from)
	{
		return InboxSenderAuth::check(InboxSenderAuth::parseHeaders($raw), $from, array(self::SERVER));
	}

	/**
	 * Mail sent by an authenticated user of the server, forwarded to a colleague: Plesk writes dmarc and dkim twice,
	 * then spf, in one header
	 *
	 * @return void
	 */
	public function testPleskRepeatedResultsPass()
	{
		$raw = "Return-Path: <boss@client.inbox-test.invalid>\r\n"
			."Received: from [IPV6:2001:db8::1] by ".self::SERVER." (Postfix) with ESMTPSA id A5BBFF6628E\r\n"
			."Authentication-Results: ".self::SERVER.";\r\n"
			."\tdmarc=pass (p=REJECT sp=REJECT) smtp.from=client.inbox-test.invalid header.from=client.inbox-test.invalid;\r\n"
			."\tdkim=pass header.d=client.inbox-test.invalid;\r\n"
			."\tdmarc=pass (p=REJECT sp=REJECT) smtp.from=client.inbox-test.invalid header.from=client.inbox-test.invalid;\r\n"
			."\tdkim=pass header.d=client.inbox-test.invalid;\r\n"
			."        spf=pass (sender IP is 2001:db8::1) smtp.mailfrom=boss@client.inbox-test.invalid smtp.helo=[IPV6:2001:db8::1]\r\n"
			."Received-SPF: pass (".self::SERVER.": connection is authenticated)\r\n"
			."From: Boss <boss@client.inbox-test.invalid>\r\n";
		$auth = $this->check($raw, 'boss@client.inbox-test.invalid');
		$this->assertSame('pass', $auth['result'], $auth['detail']);
		$this->assertSame('dmarc=pass, dkim=pass (client.inbox-test.invalid), spf=pass (client.inbox-test.invalid) ['.self::SERVER.']', $auth['detail'], 'repeated results shown once');
	}

	/**
	 * Mail written by PHP on the server (no SMTP, no spf), forwarded: same repeated results, ARC headers above
	 *
	 * @return void
	 */
	public function testPleskLocalMailPass()
	{
		$raw = "ARC-Authentication-Results: i=2; ".self::SERVER.";\r\n"
			."  arc=pass;\r\n"
			."  dmarc=pass smtp.from=client.inbox-test.invalid header.from=client.inbox-test.invalid;\r\n"
			."  dkim=pass header.d=client.inbox-test.invalid\r\n"
			."Return-Path: <admin@client.inbox-test.invalid>\r\n"
			."Received: by ".self::SERVER." (Postfix, from userid 30) id 9BBFBF65D3A\r\n"
			."Authentication-Results: ".self::SERVER.";\r\n"
			."\tdmarc=pass (p=REJECT sp=REJECT) smtp.from=client.inbox-test.invalid header.from=client.inbox-test.invalid;\r\n"
			."\tdkim=pass header.d=client.inbox-test.invalid;\r\n"
			."\tdmarc=pass (p=REJECT sp=REJECT) smtp.from=client.inbox-test.invalid header.from=client.inbox-test.invalid;\r\n"
			."\tdkim=pass header.d=client.inbox-test.invalid\r\n"
			."Received: by ".self::SERVER." (Postfix, from userid 999) id 1D46EF65D39\r\n"
			."From: Admin <admin@client.inbox-test.invalid>\r\n";
		$auth = $this->check($raw, 'admin@client.inbox-test.invalid');
		$this->assertSame('pass', $auth['result'], $auth['detail']);
	}

	/**
	 * Two dmarc or two spf that differ in the header of our server: still not authenticated
	 *
	 * @return void
	 */
	public function testConflictingResultsFail()
	{
		$from = 'boss@client.inbox-test.invalid';
		$cases = array(
			'dmarc pass then fail' => 'dmarc=pass header.from=client.inbox-test.invalid; dmarc=fail header.from=client.inbox-test.invalid',
			'dmarc on two domains' => 'dmarc=pass header.from=client.inbox-test.invalid; dmarc=pass header.from=evil.invalid',
			'spf pass then fail' => 'spf=pass smtp.mailfrom=boss@client.inbox-test.invalid; spf=fail smtp.mailfrom=boss@client.inbox-test.invalid',
			'spf on two domains' => 'spf=pass smtp.mailfrom=x@evil.invalid; spf=pass smtp.mailfrom=boss@client.inbox-test.invalid',
		);
		foreach ($cases as $label => $results) {
			$auth = $this->check("Authentication-Results: ".self::SERVER."; ".$results."\r\nFrom: <".$from.">\r\n", $from);
			$this->assertSame('fail', $auth['result'], $label);
			$this->assertStringContainsString('(conflicting results)', $auth['detail'], $label);
		}

		// Two different dkim signatures stay normal
		$auth = $this->check("Authentication-Results: ".self::SERVER."; dkim=pass header.d=client.inbox-test.invalid; dkim=fail header.d=other.invalid\r\nFrom: <".$from.">\r\n", $from);
		$this->assertSame('pass', $auth['result'], $auth['detail']);
	}
}
