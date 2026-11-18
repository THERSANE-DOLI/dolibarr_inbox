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
 * \file    test/phpunit/InboxMailContextTest.php
 * \ingroup inbox
 * \brief   The mail open given to a page of a module by a token of one use ({ctx})
 */

require_once __DIR__.'/InboxTestCase.php';
require_once __DIR__.'/../../class/inboxmailcontext.class.php';

/**
 * Token of one use, kept in the session with the number of the mail only; the values read again with the rights
 */
class InboxMailContextTest extends InboxTestCase
{
	/**
	 * @return void
	 */
	protected function tearDown(): void
	{
		unset($_SESSION[InboxMailContext::SESSION_KEY]);
		parent::tearDown();
	}

	/**
	 * @param int $accountId Mailbox
	 * @return int Mail of the index
	 */
	protected function mail($accountId)
	{
		$p = $this->db->prefix();
		$this->db->query("INSERT INTO ".$p."inbox_folder (fk_account, path, name, delimiter, role, status) VALUES (".((int) $accountId).", 'INBOX', 'INBOX', '.', 'inbox', 1)");
		$folder = (int) $this->db->last_insert_id($p.'inbox_folder');
		$this->db->query("INSERT INTO ".$p."inbox_mail (fk_account, fk_folder, remote_id, sort_key, message_id, date_mail, from_email, to_text, cc_text, subject, date_index) VALUES ("
			.((int) $accountId).", ".$folder.", '9001', 9001, '<ctx-1@inbox-test.invalid>', '2026-11-18 10:00:00', 'Client@Inbox-Test.invalid',"
			." '".$this->db->escape('Devis <'.self::BOX.'>, Paul <paul@inbox-test.invalid>')."', 'anne@inbox-test.invalid, paul@inbox-test.invalid', 'Demande de devis', '".$this->db->idate(dol_now())."')");

		return (int) $this->db->last_insert_id($p.'inbox_mail');
	}

	/**
	 * @return void
	 */
	public function testTokenOfOneUse()
	{
		$box = $this->box(true, array('status' => InboxAccount::STATUS_ACTIVE));
		$alice = $this->user('ctxalice');
		$bob = $this->user('ctxbob');
		(new InboxAccess($this->db))->grant($this->admin, $box->id, $alice->id, 0, InboxAccess::LEVEL_READ);
		InboxAccess::clearCache();
		$mail = $this->mail((int) $box->id);

		$this->assertSame('', InboxMailContext::make($this->db, $bob, $mail), 'a mail he cannot read: no token');
		$token = InboxMailContext::make($this->db, $alice, $mail);
		$this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $token);
		$this->assertSame(array('mail' => $mail), array_intersect_key($_SESSION[InboxMailContext::SESSION_KEY][$token], array('mail' => 1)));
		$this->assertStringNotContainsString('devis', strtolower(json_encode($_SESSION[InboxMailContext::SESSION_KEY])), 'the number of the mail only in the session');

		$this->assertNull(InboxMailContext::take($token, $bob, $this->db), 'another user');
		$this->assertNull(InboxMailContext::take($token, $alice, $this->db), 'refused once: gone');

		$token = InboxMailContext::make($this->db, $alice, $mail);
		$c = InboxMailContext::take($token, $alice, $this->db);
		$this->assertSame($mail, $c['mail']);
		$this->assertSame('Demande de devis', $c['subject']);
		$this->assertSame('ctx-1@inbox-test.invalid', $c['messageid']);
		$this->assertSame('client@inbox-test.invalid', $c['from']);
		$this->assertSame(array('client@inbox-test.invalid', 'paul@inbox-test.invalid', 'anne@inbox-test.invalid'), $c['emails'], 'without the mailbox of the user, once each');
		$this->assertNull(InboxMailContext::take($token, $alice, $this->db), 'one use');

		$this->assertNull(InboxMailContext::take('../etc', $alice, $this->db));
		$token = InboxMailContext::make($this->db, $alice, $mail);
		$_SESSION[InboxMailContext::SESSION_KEY][$token]['expires'] = time() - 1;
		$this->assertNull(InboxMailContext::take($token, $alice, $this->db), 'expired');

		// The rights at the moment of the reading: an access removed after the click counts
		$token = InboxMailContext::make($this->db, $alice, $mail);
		(new InboxAccess($this->db))->revoke($this->admin, $box->id, $alice->id, 0);
		InboxAccess::clearCache();
		$this->assertNull(InboxMailContext::take($token, $alice, $this->db));
	}

	/**
	 * @return void
	 */
	public function testFewTokensKept()
	{
		$box = $this->box(true, array('status' => InboxAccount::STATUS_ACTIVE));
		$reader = $this->user('ctxreader');
		(new InboxAccess($this->db))->grant($this->admin, $box->id, $reader->id, 0, InboxAccess::LEVEL_READ);
		InboxAccess::clearCache();
		$mail = $this->mail((int) $box->id);
		$tokens = array();
		for ($i = 0; $i < InboxMailContext::MAX + 5; $i++) {
			$tokens[] = InboxMailContext::make($this->db, $reader, $mail);
		}
		$this->assertCount(InboxMailContext::MAX, $_SESSION[InboxMailContext::SESSION_KEY]);
		$this->assertNull(InboxMailContext::take($tokens[0], $reader, $this->db), 'the oldest went');
		$this->assertNotNull(InboxMailContext::take(end($tokens), $reader, $this->db));
	}
}
