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

require_once __DIR__.'/InboxTestCase.php';
dol_include_once('/inbox/class/inboxactions.class.php');

/**
 * Tests on the local Dovecot: folders ZzAct* and mails « @act.inbox-test.invalid » removed after each test
 */
class InboxActionsTest extends InboxTestCase
{
	const OTHER = 'perso@inbox-test.invalid';
	const OTHER_PASSWORD = 'perso-test-pass';
	const MARK = '@act.inbox-test.invalid';

	/** @var array<string,\Webklex\PHPIMAP\Client> Raw clients per address (checks made beside inbox) */
	protected $raw = array();

	/** @var InboxAccount */
	protected $a;

	/**
	 * @return void
	 */
	protected function tearDown(): void
	{
		foreach ($this->raw as $client) {
			try {
				// A connection closed by the server (folder removed while selected) is opened again
				$client->disconnect();
				$client->connect();
				$p = $client->getConnection();
				$names = array();
				foreach ((array) $p->folders('', '*') as $name => $info) {
					$names[] = (string) $name;
				}
				foreach ($names as $folder) {
					$p->selectFolder($folder);
					foreach ((array) $p->search(array('HEADER', 'Message-ID', '"'.self::MARK.'"'), true) as $u) {
						$p->requestAndResponse('UID STORE', array((string) $u, '+FLAGS.SILENT', '(\\Deleted)'));
					}
					$p->expunge();
				}
				// A folder still selected cannot be removed on every server
				$p->selectFolder('INBOX');
				foreach (array_reverse($names) as $folder) {
					if (strpos($folder, 'ZzAct') !== false) {
						$p->deleteFolder($folder);
					}
				}
				$client->disconnect();
			} catch (Exception $e) {
				// already gone
			}
		}
		$this->db->query("DELETE FROM ".$this->db->prefix()."inbox_action_log WHERE subject LIKE 'ZzAct%'");
		parent::tearDown();
	}

	/**
	 * @param string $email Address
	 * @return \Webklex\PHPIMAP\Connection\Protocols\ProtocolInterface
	 */
	protected function imap($email = self::BOX)
	{
		require_once DOL_DOCUMENT_ROOT.'/includes/webklex/php-imap/vendor/autoload.php';
		if (!isset($this->raw[$email])) {
			$cm = new \Webklex\PHPIMAP\ClientManager();
			$this->raw[$email] = $cm->make(array('host' => self::IMAP_HOST, 'port' => self::IMAP_PORT, 'encryption' => false, 'validate_cert' => false, 'protocol' => 'imap',
				'username' => $email, 'password' => $email === self::BOX ? self::BOX_PASSWORD : self::OTHER_PASSWORD, 'authentication' => 'login'));
			$this->raw[$email]->connect();
		}

		return $this->raw[$email]->getConnection();
	}

	/**
	 * @param string $folder Folder
	 * @param string $id     Local part of the Message-ID
	 * @param string $email  Mailbox
	 * @return void
	 */
	protected function append($folder, $id, $email = self::BOX)
	{
		$raw = "Message-ID: <".$id.self::MARK.">\r\nFrom: Client <client@example.invalid>\r\nTo: ".$email."\r\nSubject: ZzAct ".$id."\r\nDate: ".date('r', mktime(10, 0, 0, 3, 15, 2025))."\r\n\r\nCorps ".$id."\r\n";
		$this->imap($email)->appendMessage($folder, $raw);
	}

	/**
	 * @param string $folder Folder
	 * @param string $id     Local part of the Message-ID
	 * @param string $email  Mailbox
	 * @return int[] UIDs of the mail in the folder on the server
	 */
	protected function onServer($folder, $id, $email = self::BOX)
	{
		$p = $this->imap($email);
		$p->selectFolder($folder);

		return array_map('intval', (array) $p->search(array('HEADER', 'Message-ID', '"'.$id.self::MARK.'"'), true));
	}

	/**
	 * @param string $folder Folder
	 * @param int    $uid    UID
	 * @param string $email  Mailbox
	 * @return string Flags of the mail on the server
	 */
	protected function flagsOnServer($folder, $uid, $email = self::BOX)
	{
		$p = $this->imap($email);
		$p->selectFolder($folder);
		$res = $p->flags(array($uid), null, true);

		return implode(' ', (array) ($res[$uid] ?? array()));
	}

	/**
	 * @param string $email Mailbox
	 * @return InboxAccount Mailbox saved, active, folders ZzActA (3 mails) and ZzActB, indexed
	 */
	protected function ready($email = self::BOX)
	{
		$this->needDovecot();
		$set = array('status' => InboxAccount::STATUS_ACTIVE);
		if ($email !== self::BOX) {
			$set['email'] = $email;
		}
		$a = $this->box(false, $set);
		if ($email !== self::BOX) {
			$a->setImapPassword(self::OTHER_PASSWORD);
		}
		$this->assertGreaterThan(0, $a->create($this->admin), implode(',', $a->errors));
		// The right to manage the mailboxes does not open their mails: an access is needed, as for the reading
		$access = new InboxAccess($this->db);
		$access->grant($this->admin, $a->id, $this->admin->id, 0, InboxAccess::LEVEL_MANAGE);
		InboxAccess::clearCache();
		$p = $this->imap($email);
		foreach (array('INBOX.ZzActA', 'INBOX.ZzActB') as $f) {
			$p->createFolder($f);
		}
		if ($email === self::BOX) {
			foreach (array('m1', 'm2', 'm3') as $id) {
				$this->append('INBOX.ZzActA', $id);
			}
		}
		$a->fetch($a->id);
		$this->sync($a);

		return $a;
	}

	/**
	 * @param InboxAccount $a Mailbox
	 * @return void
	 */
	protected function sync(InboxAccount $a)
	{
		$s = new InboxSync($this->db);
		for ($i = 0; $i < 4; $i++) {
			$s->run($a, microtime(true) + 20, array('force' => true));
		}
	}

	/**
	 * @param InboxAccount $a    Mailbox
	 * @param string       $path Folder
	 * @return int Row of the folder
	 */
	protected function folder(InboxAccount $a, $path)
	{
		$r = $this->db->getRow("SELECT rowid FROM ".$this->db->prefix()."inbox_folder WHERE fk_account = ".((int) $a->id)." AND path = '".$this->db->escape($path)."' AND status = 1");

		return is_object($r) ? (int) $r->rowid : 0;
	}

	/**
	 * @param InboxAccount $a  Mailbox
	 * @param string       $id Local part of the Message-ID
	 * @return object|null Row of the index (rowid, fk_folder, remote_id, flagged, keywords)
	 */
	protected function row(InboxAccount $a, $id)
	{
		$r = $this->db->getRow("SELECT rowid, fk_folder, remote_id, flagged, seen, keywords FROM ".$this->db->prefix()."inbox_mail WHERE fk_account = ".((int) $a->id)
			." AND message_id = '".$this->db->escape($id.self::MARK)."' ORDER BY rowid DESC");

		return is_object($r) ? $r : null;
	}

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
