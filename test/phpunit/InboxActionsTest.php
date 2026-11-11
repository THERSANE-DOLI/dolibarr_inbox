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
 * \file    inbox/test/phpunit/InboxActionsTest.php
 * \ingroup inbox
 * \brief   Lot W4 1.6.0: actions on the mails written on the server, checked, undone; rights; only the UID aimed
 *          expunged; another mailbox without loss; journal
 */

require_once __DIR__.'/InboxImapTestCase.php';
dol_include_once('/inbox/class/inboxactions.class.php');

/**
 * Tests on the local Dovecot: folders ZzAct* and mails « @act.inbox-test.invalid » removed after each test
 */
class InboxActionsTest extends InboxImapTestCase
{
	/**
	 * CA-01, CA-02: flags and tags written on the server then in the index; a move keeps the row of the index; each
	 * undo puts the server back as it was
	 *
	 * @return void
	 */
	public function testWritesAndUndo()
	{
		$a = $this->ready();
		$act = new InboxActions($this->db);
		$m1 = $this->row($a, 'm1');
		$this->assertNotNull($m1, 'mail indexed');
		$uid = $this->onServer('INBOX.ZzActA', 'm1')[0];

		$res = $act->run($this->admin, 'flag', array((int) $m1->rowid), array('on' => true));
		$this->assertTrue($res['ok'], $res['error']);
		$this->assertStringContainsString('Flagged', $this->flagsOnServer('INBOX.ZzActA', $uid));
		$this->assertSame(1, (int) $this->row($a, 'm1')->flagged);
		$u = $res['undo'][0];
		$this->assertTrue($act->run($this->admin, $u['action'], $u['ids'], $u)['ok']);
		$this->assertStringNotContainsString('Flagged', $this->flagsOnServer('INBOX.ZzActA', $uid), 'undo of the flag on the server');

		$res = $act->run($this->admin, 'tag', array((int) $m1->rowid), array('add' => array('$label1', 'ZzTag')));
		$this->assertTrue($res['ok'], $res['error']);
		$flags = $this->flagsOnServer('INBOX.ZzActA', $uid);
		$this->assertStringContainsString('$label1', $flags, 'same keyword as the other mail clients');
		$this->assertStringContainsString('ZzTag', $flags);
		foreach ($res['undo'] as $u) {
			$act->run($this->admin, 'tag', $u['ids'], $u);
		}
		$this->assertStringNotContainsString('ZzTag', $this->flagsOnServer('INBOX.ZzActA', $uid));
		$this->assertSame('', (string) $this->row($a, 'm1')->keywords);

		$b = $this->folder($a, 'INBOX.ZzActB');
		$res = $act->run($this->admin, 'move', array((int) $m1->rowid), array('folder' => $b));
		$this->assertTrue($res['ok'], $res['error']);
		$this->assertSame(array(), $this->onServer('INBOX.ZzActA', 'm1'), 'gone from the source');
		$this->assertCount(1, $this->onServer('INBOX.ZzActB', 'm1'), 'once in the target');
		$moved = $this->row($a, 'm1');
		$this->assertSame((int) $m1->rowid, (int) $moved->rowid, 'the row follows the mail (COPYUID)');
		$this->assertSame($b, (int) $moved->fk_folder);
		$u = $res['undo'][0];
		$this->assertTrue($act->run($this->admin, 'move', $u['ids'], array('folder' => $u['folder']))['ok']);
		$this->assertCount(1, $this->onServer('INBOX.ZzActA', 'm1'), 'back in the source');
		$this->assertSame(array(), $this->onServer('INBOX.ZzActB', 'm1'));
	}

	/**
	 * CA-04: « read » may flag, not move, tag nor remove, even by a forged call; a mailbox without access is « not found »
	 *
	 * @return void
	 */
	public function testRights()
	{
		$a = $this->ready();
		$reader = $this->user('actreader');
		$access = new InboxAccess($this->db);
		$access->grant($this->admin, $a->id, $reader->id, 0, InboxAccess::LEVEL_READ);
		InboxAccess::clearCache();
		$act = new InboxActions($this->db);
		$m2 = (int) $this->row($a, 'm2')->rowid;
		$this->assertTrue($act->run($reader, 'flag', array($m2), array('on' => true))['ok']);
		foreach (array('move' => array('folder' => $this->folder($a, 'INBOX.ZzActB')), 'tag' => array('add' => array('ZzTag')), 'delete' => array(), 'archive' => array(), 'junk' => array()) as $what => $params) {
			$res = $act->run($reader, $what, array($m2), $params);
			$this->assertFalse($res['ok'], $what);
			$this->assertSame('InboxErrorNeedManage', $res['error'], $what);
		}
		$this->assertCount(1, $this->onServer('INBOX.ZzActA', 'm2'), 'nothing moved');
		$stranger = $this->user('actstranger');
		$res = $act->run($stranger, 'flag', array($m2), array('on' => false));
		$this->assertSame('InboxMailNotFound', $res['error'], 'no access: the mail does not exist');
	}

	/**
	 * CA-05: a mail marked \Deleted by another client stays: inbox only expunges the UID it moves or removes
	 *
	 * @return void
	 */
	public function testOnlyOwnUidsExpunged()
	{
		$a = $this->ready();
		$uid3 = $this->onServer('INBOX.ZzActA', 'm3')[0];
		$p = $this->imap();
		$p->selectFolder('INBOX.ZzActA');
		$p->requestAndResponse('UID STORE', array((string) $uid3, '+FLAGS.SILENT', '(\\Deleted)'));
		$act = new InboxActions($this->db);
		$res = $act->run($this->admin, 'move', array((int) $this->row($a, 'm1')->rowid), array('folder' => $this->folder($a, 'INBOX.ZzActB')));
		$this->assertTrue($res['ok'], $res['error']);
		$this->assertSame(array($uid3), $this->onServer('INBOX.ZzActA', 'm3'), 'the mail of the other client is still there');
	}

	/**
	 * CA-06 and the trash: archive into Archives / <year of the mail> (created), remove to the trash, then for good
	 * only with the confirmation
	 *
	 * @return void
	 */
	public function testArchiveAndDelete()
	{
		$a = $this->ready();
		$act = new InboxActions($this->db);
		$res = $act->run($this->admin, 'archive', array((int) $this->row($a, 'm1')->rowid));
		$this->assertTrue($res['ok'], $res['error']);
		$archive = $this->db->getRow("SELECT f.path FROM ".$this->db->prefix()."inbox_folder as f INNER JOIN ".$this->db->prefix()."inbox_mail as m ON m.fk_folder = f.rowid WHERE m.rowid = ".((int) $this->row($a, 'm1')->rowid));
		$this->assertMatchesRegularExpression('/Archives.2025$/', (string) $archive->path, 'year of the mail, not of today');
		$this->assertCount(1, $this->onServer((string) $archive->path, 'm1'));

		$m2 = (int) $this->row($a, 'm2')->rowid;
		$res = $act->run($this->admin, 'delete', array($m2));
		$this->assertTrue($res['ok'], $res['error']);
		$this->assertNotEmpty($res['undo'], 'to the trash: undoable');
		$trash = $this->db->getRow("SELECT f.path, f.role FROM ".$this->db->prefix()."inbox_folder as f INNER JOIN ".$this->db->prefix()."inbox_mail as m ON m.fk_folder = f.rowid WHERE m.rowid = ".$m2);
		$this->assertSame('trash', (string) $trash->role);
		$res = $act->run($this->admin, 'delete', array($m2));
		$this->assertSame('InboxErrorConfirmDelete', $res['error'], 'for good: confirmation asked');
		$this->assertCount(1, $this->onServer((string) $trash->path, 'm2'));
		$res = $act->run($this->admin, 'delete', array($m2), array('confirm' => true));
		$this->assertTrue($res['ok'], $res['error']);
		$this->assertSame(array(), $this->onServer((string) $trash->path, 'm2'), 'erased from the server');
		$this->assertNull($this->row($a, 'm2'));
	}

	/**
	 * 1.90.0: a mail put in the trash or in junk is marked read first; the undo gives it back to its folder unread
	 *
	 * @return void
	 */
	public function testTrashAndJunkMarkRead()
	{
		$a = $this->ready();
		$act = new InboxActions($this->db);
		$p = $this->db->prefix();
		$folderOf = function ($id) use ($p) {
			return $this->db->getRow("SELECT f.rowid, f.path, f.role FROM ".$p."inbox_folder as f INNER JOIN ".$p."inbox_mail as m ON m.fk_folder = f.rowid WHERE m.rowid = ".((int) $id));
		};
		foreach (array('m1' => array('delete', 'trash'), 'm2' => array('junk', 'junk')) as $mid => list($action, $role)) {
			$id = (int) $this->row($a, $mid)->rowid;
			$from = $folderOf($id);
			$this->assertSame(0, (int) $this->row($a, $mid)->seen, $mid.' unread at first');
			$res = $act->run($this->admin, $action, array($id));
			$this->assertTrue($res['ok'], $res['error']);
			$to = $folderOf($id);
			$this->assertSame($role, (string) $to->role);
			$this->assertSame(1, (int) $this->row($a, $mid)->seen, $mid.' read in the index');
			$uid = $this->onServer((string) $to->path, $mid)[0];
			$this->assertStringContainsString('Seen', $this->flagsOnServer((string) $to->path, $uid), $mid.' read on the server');
			$this->assertSame('seen', end($res['undo'])['action'], 'unread again by the undo, after the move back');
			foreach ($res['undo'] as $u) {
				$this->assertTrue($act->run($this->admin, $u['action'], $u['ids'], $u)['ok']);
			}
			$this->assertSame((int) $from->rowid, (int) $folderOf($id)->rowid, $mid.' back in its folder');
			$this->assertSame(0, (int) $this->row($a, $mid)->seen, $mid.' unread again');
			$uid = $this->onServer((string) $from->path, $mid)[0];
			$this->assertStringNotContainsString('Seen', $this->flagsOnServer((string) $from->path, $uid));
		}

		// Moved by hand to the trash: read too; to another folder: unchanged
		$id = (int) $this->row($a, 'm3')->rowid;
		$b = $this->db->getRow("SELECT rowid FROM ".$p."inbox_folder WHERE fk_account = ".((int) $a->id)." AND path = 'INBOX.ZzActB'");
		$this->assertTrue($act->run($this->admin, 'move', array($id), array('folder' => (int) $b->rowid))['ok']);
		$this->assertSame(0, (int) $this->row($a, 'm3')->seen, 'another folder: still unread');
		$trash = $this->db->getRow("SELECT rowid FROM ".$p."inbox_folder WHERE fk_account = ".((int) $a->id)." AND role = 'trash' AND status = 1");
		$this->assertTrue($act->run($this->admin, 'move', array($id), array('folder' => (int) $trash->rowid))['ok']);
		$this->assertSame(1, (int) $this->row($a, 'm3')->seen, 'dropped on the trash: read');

		// Setting of My account off: the mail leaves unread
		dol_include_once('/inbox/class/inboxuserparam.class.php');
		$up = new InboxUserParam($this->db);
		$this->assertTrue((bool) $up->set($this->admin, 'read_on_trash', '0'));
		try {
			$id = (int) $this->row($a, 'm2')->rowid;
			$this->assertSame(0, (int) $this->row($a, 'm2')->seen);
			$res = (new InboxActions($this->db))->run($this->admin, 'delete', array($id));
			$this->assertTrue($res['ok'], $res['error']);
			$this->assertSame('trash', (string) $folderOf($id)->role);
			$this->assertSame(0, (int) $this->row($a, 'm2')->seen, 'setting off: still unread');
		} finally {
			$up->remove($this->admin, 'read_on_trash');
		}
	}

	/**
	 * CA-03, CA-10: to another mailbox, the source is removed only after the copy was found; a copy refused leaves
	 * the source as it was; the Message-ID survives
	 *
	 * @return void
	 */
	public function testOtherMailbox()
	{
		$a = $this->ready();
		$o = $this->ready(self::OTHER);
		$act = new InboxActions($this->db);
		$m1 = (int) $this->row($a, 'm1')->rowid;
		$act->run($this->admin, 'flag', array($m1), array('on' => true));
		$target = $this->folder($o, 'INBOX.ZzActB');
		$res = $act->run($this->admin, 'move', array($m1), array('folder' => $target));
		$this->assertTrue($res['ok'], $res['error']);
		$this->assertSame(array(), $this->onServer('INBOX.ZzActA', 'm1'), 'source removed after the copy');
		$there = $this->onServer('INBOX.ZzActB', 'm1', self::OTHER);
		$this->assertCount(1, $there);
		$this->assertStringContainsString('Flagged', $this->flagsOnServer('INBOX.ZzActB', $there[0], self::OTHER), 'flags kept');
		$this->assertSame($target, (int) $this->row($o, 'm1')->fk_folder, 'indexed in the target (same Message-ID)');
		$u = $res['undo'][0];
		$this->assertTrue($act->run($this->admin, 'move', $u['ids'], array('folder' => $u['folder']))['ok'], 'undo to the first mailbox');
		$this->assertCount(1, $this->onServer('INBOX.ZzActA', 'm1'));
		$this->assertSame(array(), $this->onServer('INBOX.ZzActB', 'm1', self::OTHER));

		// A target the server refuses (folder removed there): nothing lost
		$p = $this->imap(self::OTHER);
		$p->selectFolder('INBOX');
		$p->deleteFolder('INBOX.ZzActB');
		$m2 = (int) $this->row($a, 'm2')->rowid;
		$res = $act->run($this->admin, 'move', array($m2), array('folder' => $target));
		$this->assertFalse($res['ok']);
		$this->assertCount(1, $this->onServer('INBOX.ZzActA', 'm2'), 'source intact');
		$this->assertNotNull($this->row($a, 'm2'));
	}

	/**
	 * CA-08: journal lines with the author and readable folders, undo marked, read / unread not written, purge
	 *
	 * @return void
	 */
	public function testJournal()
	{
		$a = $this->ready();
		$act = new InboxActions($this->db);
		$ids = array((int) $this->row($a, 'm1')->rowid, (int) $this->row($a, 'm2')->rowid);
		InboxActionLog::$written = array();
		$act->run($this->admin, 'seen', $ids, array('on' => true));
		$this->assertSame(array(), InboxActionLog::$written, 'read / unread not written');
		$res = $act->run($this->admin, 'move', $ids, array('folder' => $this->folder($a, 'INBOX.ZzActB')));
		$this->assertCount(1, InboxActionLog::$written, 'one line for the two mails');
		$list = InboxActionLog::listForAccount($this->db, $a->id, array('action' => 'move'));
		$line = $list['lines'][0];
		$this->assertSame(2, $line['nb']);
		$this->assertSame('INBOX / ZzActA', $line['from']);
		$this->assertSame('INBOX / ZzActB', $line['to']);
		$this->assertSame((int) $this->admin->id, $line['fk_user']);
		InboxActionLog::markUndone($this->db, $this->admin, InboxActionLog::$written);
		$this->assertSame(1, InboxActionLog::listForAccount($this->db, $a->id, array('action' => 'move'))['lines'][0]['undone']);
		$this->db->query("UPDATE ".$this->db->prefix()."inbox_action_log SET date_action = '".$this->db->idate(dol_now() - 400 * 86400)."' WHERE rowid = ".((int) $line['id']));
		InboxActionLog::purge($this->db);
		$this->assertSame(array(), InboxActionLog::listForAccount($this->db, $a->id, array('action' => 'move'))['lines'], 'purged after 12 months');
	}
}
