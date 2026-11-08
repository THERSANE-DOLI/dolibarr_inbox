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
 * \file    test/phpunit/InboxViewsTest.php
 * \ingroup inbox
 * \brief   Unified views (1.29.0): the search limited to folders of some roles, or never of others, over mailboxes
 */

require_once __DIR__.'/InboxTestCase.php';
dol_include_once('/inbox/class/inboxsearchsql.class.php');

/**
 * Tests of the filters by role of the unified views
 */
class InboxViewsTest extends InboxTestCase
{
	/**
	 * A folder of a role and one mail in it
	 *
	 * @param int    $accountId Mailbox
	 * @param string $role      Role ('' for an ordinary folder)
	 * @param string $id        Message-ID (test domain)
	 * @param array<string,int> $flags seen, flagged
	 * @return int Mail
	 */
	protected function mailIn($accountId, $role, $id, array $flags = array())
	{
		$p = $this->db->prefix();
		$path = $role !== '' ? 'Zz'.ucfirst($role) : 'ZzOther';
		$f = $this->db->getRow("SELECT rowid FROM ".$p."inbox_folder WHERE fk_account = ".((int) $accountId)." AND path = '".$this->db->escape($path)."'");
		if (!is_object($f)) {
			$this->db->query("INSERT INTO ".$p."inbox_folder (fk_account, path, name, role, status) VALUES (".((int) $accountId).", '".$this->db->escape($path)."', '".$this->db->escape($path)."', "
				.($role !== '' ? "'".$this->db->escape($role)."'" : 'NULL').", 1)");
			$folderId = (int) $this->db->last_insert_id($p.'inbox_folder');
		} else {
			$folderId = (int) $f->rowid;
		}
		$this->db->query("INSERT INTO ".$p."inbox_mail (fk_account, fk_folder, remote_id, message_id, subject, date_received, seen, flagged, date_index) VALUES ("
			.((int) $accountId).", ".$folderId.", '1:".mt_rand(1, 999999)."', '".$this->db->escape($id)."@inbox-test.invalid', 'Zz ".$this->db->escape($id)."',"
			." '".$this->db->idate(dol_now())."', ".((int) ($flags['seen'] ?? 1)).", ".((int) ($flags['flagged'] ?? 0)).", '".$this->db->idate(dol_now())."')");

		return (int) $this->db->last_insert_id($p.'inbox_mail');
	}

	/**
	 * @param int[]               $accounts Mailboxes
	 * @param array<string,mixed> $query    Query
	 * @return string[] Subjects found
	 */
	protected function found(array $accounts, array $query)
	{
		$engine = new InboxSearchSql($this->db);
		$page = $engine->search($accounts, 0, $query, '', 100);
		$out = array_column($page['rows'], 'subject');
		sort($out);

		return $out;
	}

	/**
	 * @return void
	 */
	public function testRolesOverMailboxes()
	{
		$a = $this->box(true);
		$b = $this->box(true, array('email' => 'other@inbox-test.invalid'));
		$this->mailIn($a->id, 'inbox', 'a-inbox', array('seen' => 0));
		$this->mailIn($b->id, 'inbox', 'b-inbox', array('seen' => 0, 'flagged' => 1));
		$this->mailIn($a->id, 'sent', 'a-sent', array('seen' => 0));
		$this->mailIn($a->id, 'trash', 'a-trash', array('seen' => 0, 'flagged' => 1));
		$this->mailIn($b->id, '', 'b-other', array('seen' => 0));
		$this->mailIn($b->id, 'drafts', 'b-draft');
		$both = array((int) $a->id, (int) $b->id);
		try {
			// Unified inbox: the inbox of every mailbox, nothing else
			$this->assertSame(array('Zz a-inbox', 'Zz b-inbox'), $this->found($both, array('roles' => array('inbox'))));
			// Unread: everywhere but trash, junk, sent and drafts (an ordinary folder is in)
			$this->assertSame(array('Zz a-inbox', 'Zz b-inbox', 'Zz b-other'), $this->found($both, array('unread' => true, 'not_roles' => array('trash', 'junk', 'sent', 'drafts'))));
			// Starred: not in the trash
			$this->assertSame(array('Zz b-inbox'), $this->found($both, array('flagged' => true, 'not_roles' => array('trash', 'junk'))));
			$this->assertSame(array('Zz b-draft'), $this->found($both, array('roles' => array('drafts'))));
			// A mailbox left out of the views is not searched
			$this->assertSame(array('Zz a-inbox'), $this->found(array((int) $a->id), array('roles' => array('inbox'))));
			// A role is never written as SQL
			$this->assertSame(array(), $this->found($both, array('roles' => array("inbox') OR ('1'='1"))));
		} finally {
			$this->db->query("DELETE FROM ".$this->db->prefix()."inbox_folder WHERE fk_account IN (".implode(',', $both).")");
		}
	}

	/**
	 * @return void
	 */
	public function testSettingsOfTheViews()
	{
		$u = $this->user('views');
		$params = new InboxUserParam($this->db);
		$this->assertSame(array('inbox', 'unread', 'flagged', 'mine'), json_decode($params->get($u, 'views_shown'), true), 'views shown by default');
		$this->assertSame(array(), json_decode($params->get($u, 'unified_exclude'), true), 'every mailbox in the views by default');
		$this->assertGreaterThan(0, $params->set($u, 'unified_exclude', '[12,15]'));
		$this->assertSame(array(12, 15), json_decode($params->get($u, 'unified_exclude'), true));
		$this->assertLessThan(0, $params->set($u, 'views_shown', 'not json'));
	}
}
