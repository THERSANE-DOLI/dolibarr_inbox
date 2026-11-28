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
 * \file    inbox/test/phpunit/InboxFolderAdminTest.php
 * \ingroup inbox
 * \brief   1.10.0: folders created, renamed, moved, removed, marked read, emptied, on the server then in the index
 */

// From the command line only, never from the web: test/ stays in the Git branches
if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

require_once __DIR__.'/InboxImapTestCase.php';
dol_include_once('/inbox/class/inboxfolderadmin.class.php');

/**
 * Tests on the local Dovecot (folders ZzAct* removed after each test, in the trash too)
 */
class InboxFolderAdminTest extends InboxImapTestCase
{
	/**
	 * @param InboxAccount $a  Mailbox
	 * @param int          $id Folder
	 * @return object|null Row of the folder
	 */
	protected function frow(InboxAccount $a, $id)
	{
		$r = $this->db->getRow("SELECT * FROM ".$this->db->prefix()."inbox_folder WHERE rowid = ".((int) $id)." AND fk_account = ".((int) $a->id));

		return is_object($r) ? $r : null;
	}

	/**
	 * @param string $path Folder
	 * @return bool The folder answers on the server
	 */
	protected function onServerFolder($path)
	{
		return is_array($this->imap()->examineFolder($path));
	}

	/**
	 * @return void
	 */
	public function testCreateRenameMoveRemove()
	{
		$a = $this->ready();
		$fa = new InboxFolderAdmin($this->db);
		$aId = $this->folder($a, 'INBOX.ZzActA');
		$bId = $this->folder($a, 'INBOX.ZzActB');
		$this->assertGreaterThan(0, $aId);

		// Created at the top (under INBOX. on this server) and inside a folder, accents kept
		$new = $fa->create($this->admin, $a->id, 0, 'ZzActNew Été');
		$this->assertGreaterThan(0, $new, $fa->error);
		$this->assertSame('ZzActNew Été', $this->frow($a, $new)->name);
		$this->assertTrue($this->onServerFolder($this->frow($a, $new)->path));
		$sub = $fa->create($this->admin, $a->id, $aId, 'ZzActSub');
		$this->assertSame('INBOX.ZzActA.ZzActSub', $this->frow($a, $sub)->path);
		$this->assertSame(-1, $fa->create($this->admin, $a->id, $aId, 'ZzActSub'));
		$this->assertSame('InboxErrorFolderExists', $fa->error);
		foreach (array('a"b', 'a.b', '', 'x<y') as $bad) {
			$this->assertSame(-1, $fa->create($this->admin, $a->id, 0, $bad), $bad);
			$this->assertSame('InboxErrorFolderName', $fa->error, $bad);
		}

		// Renamed: same row, its mails and its subfolder follow
		$this->assertSame(1, $fa->rename($this->admin, $aId, 'ZzActA2'), $fa->error);
		$this->assertSame('INBOX.ZzActA2', $this->frow($a, $aId)->path);
		$this->assertSame('INBOX.ZzActA2.ZzActSub', $this->frow($a, $sub)->path);
		$this->assertSame(3, (int) $this->db->getRow("SELECT COUNT(*) AS n FROM ".$this->db->prefix()."inbox_mail WHERE fk_folder = ".$aId)->n);
		$this->assertNotEmpty($this->onServer('INBOX.ZzActA2', 'm1'));
		$this->assertFalse($this->onServerFolder('INBOX.ZzActA'));

		// Moved into another folder; never into itself
		$this->assertSame(1, $fa->move($this->admin, $aId, $bId), $fa->error);
		$this->assertSame('INBOX.ZzActB.ZzActA2', $this->frow($a, $aId)->path);
		$this->assertSame('INBOX.ZzActB.ZzActA2.ZzActSub', $this->frow($a, $sub)->path);
		$this->assertSame(-1, $fa->move($this->admin, $bId, $sub));
		$this->assertSame('InboxErrorFolderIntoItself', $fa->error);
		$inbox = $this->folder($a, 'INBOX');
		$this->assertSame(-1, $fa->rename($this->admin, $inbox, 'Other'));
		$this->assertSame('InboxErrorFolderSpecial', $fa->error);

		// Mark read: on the server, then the index
		$this->assertSame(3, $fa->markRead($this->admin, $aId));
		$uid = $this->onServer('INBOX.ZzActB.ZzActA2', 'm2')[0];
		$this->assertStringContainsString('Seen', $this->flagsOnServer('INBOX.ZzActB.ZzActA2', $uid));
		$this->assertSame(0, (int) $this->db->getRow("SELECT COUNT(*) AS n FROM ".$this->db->prefix()."inbox_mail WHERE fk_folder = ".$aId." AND seen = 0")->n);

		// Removed: into the trash first (rows kept), then for good after the confirmation with the number of mails
		$this->assertSame(1, $fa->remove($this->admin, $bId), $fa->error);
		$inTrash = $this->frow($a, $bId)->path;
		$this->assertSame('INBOX.Trash.ZzActB', $inTrash);
		$this->assertTrue($this->onServerFolder($inTrash.'.ZzActA2'));
		$this->assertSame(-1, $fa->remove($this->admin, $bId));
		$this->assertSame('InboxErrorConfirmFolderDelete', $fa->error);
		$this->assertSame(3, $fa->count);
		$this->assertSame(2, $fa->remove($this->admin, $bId, true), $fa->error);
		$this->assertFalse($this->onServerFolder($inTrash));
		$this->assertNull($this->frow($a, $aId), 'the subfolder too');
		$this->assertSame(0, (int) $this->db->getRow("SELECT COUNT(*) AS n FROM ".$this->db->prefix()."inbox_mail WHERE fk_folder = ".$aId)->n);

		$journal = InboxActionLog::listForAccount($this->db, $a->id, array('action' => 'folder_rename'));
		$this->assertSame('INBOX / ZzActA2', $journal['lines'][0]['to']);
		$this->db->query("DELETE FROM ".$this->db->prefix()."inbox_action_log WHERE fk_account = ".((int) $a->id));
	}

	/**
	 * @return void
	 */
	public function testEmptyAndRights()
	{
		$a = $this->ready();
		$fa = new InboxFolderAdmin($this->db);
		$this->append('INBOX.Junk', 'j1');
		$this->append('INBOX.Junk', 'j2');
		$this->sync($a);
		$junk = $this->folder($a, 'INBOX.Junk');
		$this->assertSame(-1, $fa->emptyFolder($this->admin, $junk));
		$this->assertSame('InboxErrorConfirmEmpty', $fa->error);
		$this->assertGreaterThanOrEqual(2, $fa->count);
		$this->assertGreaterThanOrEqual(2, $fa->emptyFolder($this->admin, $junk, true), $fa->error);
		$this->assertSame(array(), $this->onServer('INBOX.Junk', 'j1'));
		$this->assertSame(0, (int) $this->db->getRow("SELECT COUNT(*) AS n FROM ".$this->db->prefix()."inbox_mail WHERE fk_folder = ".$junk)->n);
		$this->assertSame(-1, $fa->emptyFolder($this->admin, $this->folder($a, 'INBOX.ZzActA'), true), 'only the trash and the junk');

		// Level read: the folder marked read, nothing else; no access: not found
		$alice = $this->user('alice');
		$bob = $this->user('bob');
		$access = new InboxAccess($this->db);
		$access->grant($this->admin, $a->id, $alice->id, 0, 'read');
		InboxAccess::clearCache();
		$aId = $this->folder($a, 'INBOX.ZzActA');
		$this->assertSame(-1, $fa->rename($alice, $aId, 'ZzActX'));
		$this->assertSame('InboxErrorNeedHandle', $fa->error);
		$this->assertSame(-1, $fa->create($alice, $a->id, 0, 'ZzActX'));
		$this->assertSame('InboxErrorNeedHandle', $fa->error);
		$this->assertSame(3, $fa->markRead($alice, $aId), $fa->error);
		$this->assertSame(-1, $fa->remove($bob, $aId, true));
		$this->assertSame('InboxErrorFolderNotFound', $fa->error);
		$this->db->query("DELETE FROM ".$this->db->prefix()."inbox_action_log WHERE fk_account = ".((int) $a->id));
	}

	/**
	 * 1.92.0: level « handle » creates, renames, moves folders and empties the trash or the junk; removing a folder
	 * stays to « manage »; « send » does none of them
	 *
	 * @return void
	 */
	public function testHandleLevel()
	{
		$a = $this->ready();
		$fa = new InboxFolderAdmin($this->db);
		$handler = $this->user('fahandler');
		$sender = $this->user('fasender');
		$access = new InboxAccess($this->db);
		$access->grant($this->admin, $a->id, $handler->id, 0, InboxAccess::LEVEL_HANDLE);
		$access->grant($this->admin, $a->id, $sender->id, 0, InboxAccess::LEVEL_SEND);
		InboxAccess::clearCache();

		$this->assertSame(-1, $fa->create($sender, $a->id, 0, 'ZzActS'));
		$this->assertSame('InboxErrorNeedHandle', $fa->error);
		$new = $fa->create($handler, $a->id, 0, 'ZzActH');
		$this->assertGreaterThan(0, $new, $fa->error);
		$this->assertSame(1, $fa->rename($handler, $new, 'ZzActH2'), $fa->error);
		$this->assertSame(1, $fa->move($handler, $new, $this->folder($a, 'INBOX.ZzActA')), $fa->error);
		$this->assertSame(-1, $fa->remove($handler, $new, true), 'removing a folder needs « manage »');
		$this->assertSame('InboxErrorNeedManage', $fa->error);

		$this->append('INBOX.Junk', 'j3');
		$this->sync($a);
		$junk = $this->folder($a, 'INBOX.Junk');
		$this->assertSame(-1, $fa->emptyFolder($sender, $junk, true));
		$this->assertSame('InboxErrorNeedHandle', $fa->error);
		$this->assertGreaterThanOrEqual(1, $fa->emptyFolder($handler, $junk, true), $fa->error);
		$this->db->query("DELETE FROM ".$this->db->prefix()."inbox_action_log WHERE fk_account = ".((int) $a->id));
	}
}
