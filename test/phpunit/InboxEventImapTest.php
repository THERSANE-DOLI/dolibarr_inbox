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
 * \file    test/phpunit/InboxEventImapTest.php
 * \ingroup inbox
 * \brief   Journal of the changes (1.121.0): the actions on the mails and the synchronisation write it
 */

require_once __DIR__.'/InboxImapTestCase.php';
dol_include_once('/inbox/class/inboxactions.class.php');
dol_include_once('/inbox/class/inboxevent.class.php');

/**
 * Actions and synchronisation with the test mail server
 */
class InboxEventImapTest extends InboxImapTestCase
{
	/** @var string Setting before the test */
	protected $mode;

	/**
	 * @return void
	 */
	protected function setUp(): void
	{
		global $conf;

		parent::setUp();
		$this->mode = getDolGlobalString('INBOX_REACTIVITY');
		$conf->global->INBOX_REACTIVITY = 'fast';
	}

	/**
	 * @return void
	 */
	protected function tearDown(): void
	{
		global $conf;

		$conf->global->INBOX_REACTIVITY = $this->mode;
		parent::tearDown();
	}

	/**
	 * @param int $since Cursor
	 * @return object[]  Events after it
	 */
	protected function eventsAfter($since)
	{
		$rows = $this->db->getRows("SELECT fk_account, type, fk_folder, ids, fk_user_author, origin FROM ".$this->db->prefix()."inbox_event WHERE rowid > ".((int) $since)." ORDER BY rowid LIMIT 100");

		return is_array($rows) ? $rows : array();
	}

	/**
	 * @return int
	 */
	protected function cursor()
	{
		$row = $this->db->getRow("SELECT MAX(rowid) AS m FROM ".$this->db->prefix()."inbox_event");

		return is_object($row) ? (int) $row->m : 0;
	}

	/**
	 * Flag, tag, move: one event each, the folder where the mails arrive read again; the tab that asked is named
	 *
	 * @return void
	 */
	public function testActionsWriteTheJournal()
	{
		$a = $this->ready();
		$act = new InboxActions($this->db);
		$m1 = $this->row($a, 'm1');
		$src = $this->folder($a, 'INBOX.ZzActA');
		$dst = $this->folder($a, 'INBOX.ZzActB');
		$_SERVER['HTTP_X_INBOX_ORIGIN'] = 'tab-42';
		try {
			$start = $this->cursor();
			$this->assertTrue($act->run($this->admin, 'flag', array((int) $m1->rowid), array('on' => true))['ok']);
			$this->assertTrue($act->run($this->admin, 'tag', array((int) $m1->rowid), array('add' => array('$label1')))['ok']);
			$this->assertTrue($act->run($this->admin, 'move', array((int) $m1->rowid), array('folder' => $dst))['ok']);
			$ev = $this->eventsAfter($start);
		} finally {
			unset($_SERVER['HTTP_X_INBOX_ORIGIN']);
		}
		$this->assertSame(array('flag', 'tag', 'move', 'folder'), array_column($ev, 'type'));
		foreach (array(0, 1, 2) as $i) {
			$this->assertSame((string) $m1->rowid, (string) $ev[$i]->ids);
			$this->assertSame($src, (int) $ev[$i]->fk_folder);
		}
		$this->assertSame($dst, (int) $ev[3]->fk_folder, 'the target folder');
		$this->assertNull($ev[3]->ids);
		$this->assertSame('tab-42', (string) $ev[0]->origin);
		$this->assertSame((int) $this->admin->id, (int) $ev[0]->fk_user_author);
		$this->assertSame((int) $a->id, (int) $ev[0]->fk_account);
	}

	/**
	 * The synchronisation: one event per folder that changed, none for a pass that found nothing
	 *
	 * @return void
	 */
	public function testSyncWritesOneEventPerFolder()
	{
		$a = $this->ready();
		$src = $this->folder($a, 'INBOX.ZzActA');
		$start = $this->cursor();
		$this->sync($a);
		$this->assertSame(array(), $this->eventsAfter($start), 'nothing new: nothing written');

		foreach (array('n1', 'n2', 'n3') as $id) {
			$this->append('INBOX.ZzActA', $id);
		}
		$start = $this->cursor();
		$this->sync($a);
		$ev = $this->eventsAfter($start);
		$this->assertCount(1, $ev, 'three new mails, one event');
		$this->assertSame('folder', (string) $ev[0]->type);
		$this->assertSame($src, (int) $ev[0]->fk_folder);
		$this->assertSame(0, (int) $ev[0]->fk_user_author);
	}
}
