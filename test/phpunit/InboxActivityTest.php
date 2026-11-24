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
 * \file    inbox/test/phpunit/InboxActivityTest.php
 * \ingroup inbox
 * \brief   1.77.0 (lot E2): answers sent in the conversation of a mail (author by the journal, other software, no
 *          forward), answer of a colleague since a writing was opened
 */

// From the command line only, never from the web: test/ stays in the Git branches
if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

require_once __DIR__.'/InboxTestCase.php';
dol_include_once('/inbox/class/inboxactivity.class.php');

/**
 * Mails of the tests: Message-ID of the test domain, in a test mailbox (removed by InboxTestCase::cleanup())
 */
class InboxActivityTest extends InboxTestCase
{
	/**
	 * @return void
	 */
	public function testActivity()
	{
		$p = $this->db->prefix();
		$box = $this->box(true, array('status' => InboxAccount::STATUS_ACTIVE));
		$acc = (int) $box->id;
		$folder = function ($path, $role) use ($p, $acc) {
			$this->db->query("INSERT INTO ".$p."inbox_folder (fk_account, path, name, delimiter, role, status) VALUES (".$acc.", '".$path."', '".$path."', '.', '".$role."', 1)");
			return (int) $this->db->last_insert_id($p.'inbox_folder');
		};
		$inbox = $folder('INBOX', 'inbox');
		$sent = $folder('Sent', 'sent');
		$mail = function ($fld, $mid, $thread, $replyTo, $date, $subject = 'Devis') use ($p, $acc) {
			$this->db->query("INSERT INTO ".$p."inbox_mail (fk_account, fk_folder, remote_id, sort_key, message_id, in_reply_to, date_mail, from_email, subject, to_text, size, thread_key, date_index) VALUES ("
				.$acc.", ".$fld.", '".$mid."', 1, '".$mid."', ".($replyTo ? "'".$replyTo."'" : 'NULL').", '".$this->db->idate($date)."', 'devis@inbox-test.invalid', '".$subject."', 'client@acme.invalid', 10,"
				." ".($thread ? "'".$thread."'" : 'NULL').", '".$this->db->idate(dol_now())."')");
			return $this->db->getRow("SELECT * FROM ".$p."inbox_mail WHERE rowid = ".((int) $this->db->last_insert_id($p.'inbox_mail')));
		};
		$now = dol_now();
		$m1 = $mail($inbox, 'e2-m1@inbox-test.invalid', 'tk-e2', null, $now - 7200);
		$r1 = $mail($sent, 'e2-r1@inbox-test.invalid', 'tk-e2', 'e2-m1@inbox-test.invalid', $now - 3600, 'Re: Devis');
		$mail($sent, 'e2-r2@inbox-test.invalid', 'tk-e2', 'e2-m1@inbox-test.invalid', $now - 1800, 'Re: Devis');
		// A forward with the same subject, nothing tying it to the mail: never in its thread
		$mail($sent, 'e2-fw@inbox-test.invalid', 'tk-other', null, $now - 900, 'Devis');
		InboxActionLog::add($this->db, $this->admin, $acc, 'send', array((object) array('message_id' => 'e2-r1@inbox-test.invalid', 'subject' => 'Re: Devis')));

		$activity = new InboxActivity($this->db);
		$list = $activity->sentIn($m1);
		$this->assertSame(array((int) $r1->rowid, (int) $r1->rowid + 1), array_column($list, 'mail'), 'the two answers, oldest first, not the forward');
		$this->assertTrue($list[0]['by_inbox']);
		$this->assertSame((int) $this->admin->id, $list[0]['author']);
		$this->assertTrue($list[0]['reply']);
		$this->assertFalse($list[1]['by_inbox'], 'sent from another software: no author');
		$this->assertSame('', $list[1]['author_name']);

		// Before a sending: the answer seen in the Sent folder after the opening of the writing
		$c = $activity->answeredSince($acc, 'e2-m1@inbox-test.invalid', $now - 2400, 'tok1');
		$this->assertSame((int) $r1->rowid + 1, $c['mail']);
		$this->assertSame('', $c['author_name']);
		$this->assertNull($activity->answeredSince($acc, 'e2-m1@inbox-test.invalid', $now - 600, 'tok1'), 'nothing since');

		// Sent by inbox by a colleague, still in the queue: said with his name; this writing itself left out
		$actions = json_encode(array('source' => array('mail' => (int) $m1->rowid, 'account' => $acc, 'message_id' => 'e2-m1@inbox-test.invalid', 'mode' => 'reply')));
		$this->db->query("INSERT INTO ".$p."inbox_outbox (entity, fk_user, fk_account, message_id, subject, compose_token, actions, date_send, status, date_creation) VALUES (1, ".((int) $this->admin->id).", ".$acc.","
			." 'e2-q1@inbox-test.invalid', 'Re: Devis', 'tok2', '".$this->db->escape((string) $actions)."', '".$this->db->idate($now + 30)."', 0, '".$this->db->idate($now)."')");
		try {
			$c = $activity->answeredSince($acc, 'e2-m1@inbox-test.invalid', $now - 60, 'tok1');
			$this->assertTrue($c['queued']);
			$this->assertNotSame('', $c['author_name']);
			$this->assertNull($activity->answeredSince($acc, 'e2-m1@inbox-test.invalid', $now - 60, 'tok2'), 'its own sending is not a colleague');
			$this->assertNull($activity->answeredSince($acc, 'other@inbox-test.invalid', $now - 60, 'tok1'));
		} finally {
			$this->db->query("DELETE FROM ".$p."inbox_outbox WHERE compose_token = 'tok2'");
		}
	}
}
