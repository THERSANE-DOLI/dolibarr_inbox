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
 * \file    inbox/test/phpunit/InboxThreadSplitTest.php
 * \ingroup inbox
 * \brief   1.139.0: a new conversation started from a mail (by hand, or because its subject changed), joined back
 */

// From the command line only, never from the web: test/ stays in the Git branches
if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

require_once __DIR__.'/InboxTestCase.php';
dol_include_once('/inbox/class/inboxthreadsplit.class.php');

/**
 * Mails of the tests: Message-ID of the test domain (removed by InboxTestCase::cleanup())
 */
class InboxThreadSplitTest extends InboxTestCase
{
	/** @var int */
	protected $account = 0;

	/** @var int */
	protected $folder = 0;

	/** @var int Minutes since the first mail of a test, one per mail: the order of arrival */
	protected $minute = 0;

	/**
	 * A mailbox with its inbox, the user manager of it
	 *
	 * @return void
	 */
	protected function mailbox()
	{
		$p = $this->db->prefix();
		$box = $this->box(true, array('status' => InboxAccount::STATUS_ACTIVE));
		$this->account = (int) $box->id;
		(new InboxAccess($this->db))->grant($this->admin, $box->id, $this->admin->id, 0, 'manage');
		InboxAccess::clearCache();
		$this->db->query("INSERT INTO ".$p."inbox_folder (fk_account, path, name, delimiter, role, status) VALUES (".$this->account.", 'INBOX', 'INBOX', '.', 'inbox', 1)");
		$this->folder = (int) $this->db->last_insert_id($p.'inbox_folder');
		$this->minute = 0;
	}

	/**
	 * A mail arrives and gets its conversation
	 *
	 * @param string   $id      Message-ID (without the domain)
	 * @param string   $subject Subject
	 * @param string[] $parents Mails it answers, the oldest first (the last one is In-Reply-To)
	 * @return object Row
	 */
	protected function arrive($id, $subject, array $parents = array())
	{
		$p = $this->db->prefix();
		$mid = function ($x) {
			return $x.'@split.inbox-test.invalid';
		};
		$date = $this->db->idate(dol_mktime(10, $this->minute++, 0, 11, 2, 2026));
		$this->db->query("INSERT INTO ".$p."inbox_mail (fk_account, fk_folder, remote_id, sort_key, message_id, in_reply_to, refs, date_mail, date_received, from_email, to_text, subject, size, date_index) VALUES ("
			.$this->account.", ".$this->folder.", '".$this->db->escape($id)."', 1, '".$mid($id)."', ".($parents ? "'".$mid(end($parents))."'" : 'NULL').", "
			.($parents ? "'".$this->db->escape(implode(' ', array_map($mid, $parents)))."'" : 'NULL').", '".$date."', '".$date."', 'client@acme.inbox-test.invalid', 'devis@inbox-test.invalid',"
			." '".$this->db->escape($subject)."', 10, '".$this->db->idate(dol_now())."')");
		$rowid = (int) $this->db->last_insert_id($p.'inbox_mail');
		(new InboxThreader($this->db))->assign($this->account, array($rowid));

		return $this->row($rowid);
	}

	/**
	 * @param int $rowid Mail
	 * @return object Its row now
	 */
	protected function row($rowid)
	{
		return $this->db->getRow("SELECT * FROM ".$this->db->prefix()."inbox_mail WHERE rowid = ".((int) $rowid));
	}

	/**
	 * @param object ...$rows Mails
	 * @return void
	 */
	protected function assertSameThread(...$rows)
	{
		$keys = array_unique(array_map(function ($r) {
			return (string) $this->row($r->rowid)->thread_key;
		}, $rows));
		$this->assertCount(1, $keys, 'one conversation');
	}

	/**
	 * @param object $a Mail
	 * @param object $b Mail
	 * @return void
	 */
	protected function assertOtherThread($a, $b)
	{
		$this->assertNotSame((string) $this->row($a->rowid)->thread_key, (string) $this->row($b->rowid)->thread_key, 'two conversations');
	}

	/**
	 * A new request sent as a reply to the previous one, subject replaced: a conversation of its own
	 *
	 * @return void
	 */
	public function testSubjectChangeStartsConversation()
	{
		$this->mailbox();
		$m1 = $this->arrive('m1', 'Enlèvement site A // réf. 1');
		$m2 = $this->arrive('m2', 'Enlèvement site B // réf. 2', array('m1'));
		$a1 = $this->arrive('a1', 'RE: Enlèvement site A // réf. 1', array('m1'));
		$m3 = $this->arrive('m3', 'Enlèvement site C // réf. 3', array('m1', 'm2'));
		$a2 = $this->arrive('a2', 'RE: Enlèvement site B // réf. 2', array('m1', 'm2'));
		$u1 = $this->arrive('u1', 'URGENT - Enlèvement site A // réf. 1', array('m1', 'a1'));
		$this->assertSameThread($m1, $a1, $u1);
		$this->assertSameThread($m2, $a2);
		$this->assertOtherThread($m1, $m2);
		$this->assertOtherThread($m2, $m3);
		$this->assertOtherThread($m1, $m3);
		$this->assertSame(InboxThreader::ROOT_SUBJECT, (new InboxThreader($this->db))->rootsOf(array('m2@split.inbox-test.invalid'))['m2@split.inbox-test.invalid']);

		// Joined back by the user: with m1, never cut again (even when the conversation is made again)
		$split = new InboxThreadSplit($this->db);
		$this->assertSame(1, $split->join($this->admin, $this->row($m2->rowid), array()));
		$this->assertSameThread($m1, $m2, $a2, $a1);
		$this->assertOtherThread($m1, $m3);
		(new InboxThreader($this->db))->rethread($this->account, array((string) $this->row($m1->rowid)->thread_key));
		$this->assertSameThread($m1, $m2, $a2);

		// The option off: a new subject stays in the conversation
		dolibarr_set_const($this->db, 'INBOX_THREAD_SPLIT_SUBJECT', '0', 'chaine', 0, '', $this->conf()->entity);
		$m4 = $this->arrive('m4', 'Enlèvement site D // réf. 4', array('m1'));
		dolibarr_del_const($this->db, 'INBOX_THREAD_SPLIT_SUBJECT', $this->conf()->entity);
		$this->assertSameThread($m1, $m4);
	}

	/**
	 * Cut by hand: the mail and its answers leave, a late answer to an older mail stays; undo gives back the key
	 *
	 * @return void
	 */
	public function testSplitByHand()
	{
		$this->mailbox();
		$a = $this->arrive('a', 'Devis portail');
		$b = $this->arrive('b', 'RE: Devis portail', array('a'));
		$c = $this->arrive('c', 'RE: Devis portail', array('a', 'b'));
		$x = $this->arrive('x', 'RE: Devis portail', array('a', 'b', 'c'));
		$d = $this->arrive('d', 'RE: Devis portail', array('a', 'b', 'c', 'x'));
		$key = (string) $this->row($a->rowid)->thread_key;
		$this->assertSameThread($a, $b, $c, $x, $d);

		// A piece of the old conversation on x (by the conversation): removed at the cut unless kept
		$this->db->query("INSERT INTO ".$this->db->prefix()."societe (nom, entity, status, client, datec) VALUES ('inboxtest_split', 1, 1, 1, '".$this->db->idate(dol_now())."')");
		$soc = (int) $this->db->last_insert_id($this->db->prefix().'societe');
		$refs = new InboxMailRef($this->db);
		(new InboxLink($this->db))->link($refs->fetchOrCreate($a, $this->admin), 'societe', $soc, $this->admin);
		(new InboxThreadLink($this->db))->linkThread($this->admin, $a, 'societe', $soc);

		$split = new InboxThreadSplit($this->db);
		$info = $split->info($this->row($x->rowid), 'handle');
		$this->assertTrue($info['can']);
		$this->assertFalse($split->info($this->row($a->rowid), 'handle')['can'], 'never on the first mail');
		$this->assertFalse($split->info($this->row($x->rowid), 'send')['can'], 'level « handle »');
		$preview = $split->preview($this->admin, $this->row($x->rowid));
		$this->assertSame(array((int) $x->rowid, (int) $d->rowid), array_column($preview['moving'], 'id'));
		$this->assertSame(array((int) $a->rowid, (int) $b->rowid, (int) $c->rowid), array_column($preview['staying'], 'id'));
		$this->assertCount(2, $preview['links'], 'the links of the old conversation on x and d');
		$this->assertSame($key, (string) $this->row($x->rowid)->thread_key, 'the preview changes nothing');

		$removed = $split->split($this->admin, $this->row($x->rowid), array());
		$this->assertCount(2, $removed);
		$this->assertSameThread($a, $b, $c);
		$this->assertSameThread($x, $d);
		$this->assertOtherThread($a, $x);
		$this->assertSame($key, (string) $this->row($a->rowid)->thread_key, 'the old conversation keeps its key');
		$this->assertSame(array(), (new InboxLink($this->db))->listFor(array($refs->fetchOrCreate($this->row($x->rowid)))));

		// Late answer to b: old conversation; answer to d with the whole chain: new conversation
		$y = $this->arrive('y', 'RE: Devis portail', array('a', 'b'));
		$e = $this->arrive('e', 'RE: Devis portail', array('a', 'b', 'c', 'x', 'd'));
		$this->assertSameThread($a, $y);
		$this->assertSameThread($x, $e);
		$this->assertSame(1, (new InboxThreader($this->db))->rootsOf(array('x@split.inbox-test.invalid'))['x@split.inbox-test.invalid']);
		$this->assertSame((int) $c->rowid, $split->info($this->row($x->rowid), 'handle')['from']['id'], 'the mail it answers');

		// Undo: one conversation again, its key and the links back
		$this->assertSame(1, $split->join($this->admin, $this->row($x->rowid), $removed));
		$this->assertSameThread($a, $b, $c, $x, $d, $y, $e);
		$this->assertSame($key, (string) $this->row($x->rowid)->thread_key);
		$this->assertCount(1, (new InboxLink($this->db))->listFor(array($refs->fetchOrCreate($this->row($x->rowid)))));

		$this->db->query("DELETE FROM ".$this->db->prefix()."societe WHERE rowid = ".$soc);
	}

	/**
	 * Without a cut point, the keys are the ones of before: the key of the first mail
	 *
	 * @return void
	 */
	public function testNoCutSameKeys()
	{
		$this->mailbox();
		$a = $this->arrive('n1', 'Commande 12');
		$b = $this->arrive('n2', 'RE: Commande 12', array('n1'));
		$expected = substr(sha1($this->account.':n1@split.inbox-test.invalid'), 0, 32);
		$this->assertSame($expected, (string) $this->row($a->rowid)->thread_key);
		$this->assertSame($expected, (string) $this->row($b->rowid)->thread_key);
	}

	/**
	 * @return Conf
	 */
	protected function conf()
	{
		global $conf;

		return $conf;
	}
}
