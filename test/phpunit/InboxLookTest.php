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
 * \file    inbox/test/phpunit/InboxLookTest.php
 * \ingroup inbox
 * \brief   Colour and icon of the folders and mailboxes (1.8.0), rows forgotten with a user or a group deleted
 */

// From the command line only, never from the web: test/ stays in the Git branches
if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

require_once dirname(__DIR__, 2).'/class/inboxlook.class.php';
require_once dirname(__DIR__, 2).'/core/triggers/interface_95_modInbox_InboxTriggers.class.php';

/**
 * Tests of InboxLook and of the cleaning of the trigger
 */
class InboxLookTest extends InboxTestCase
{
	/**
	 * Folder of the index, without the server
	 *
	 * @param int    $accountId Mailbox
	 * @param string $path      Path
	 * @param string $role      Role
	 * @return int
	 */
	protected function folder($accountId, $path, $role = '')
	{
		$p = $this->db->prefix();
		$this->db->query("INSERT INTO ".$p."inbox_folder (fk_account, path, name, delimiter, role) VALUES (".((int) $accountId).", '".$this->db->escape($path)."', '".$this->db->escape($path)."', '.', ".($role !== '' ? "'".$this->db->escape($role)."'" : 'NULL').")");

		return (int) $this->db->last_insert_id($p.'inbox_folder');
	}

	/**
	 * @param int $folderId Folder
	 * @return object
	 */
	protected function look($folderId)
	{
		return $this->db->getRow("SELECT color, icon FROM ".$this->db->prefix()."inbox_folder WHERE rowid = ".((int) $folderId));
	}

	/**
	 * @return void
	 */
	public function testValues()
	{
		$this->assertContains('account', InboxLook::choices());
		$this->assertContains('certificate', InboxLook::choices());
		$this->assertSame('#aabbcc', InboxLook::cleanColor(' #AABBCC '));
		$this->assertSame('', InboxLook::cleanColor(''));
		foreach (array('red', '#abc', 'url(x)', '#aabbcc;background:url(x)', '#gggggg') as $bad) {
			$this->assertNull(InboxLook::cleanColor($bad), $bad);
		}
		$this->assertSame('award', InboxLook::cleanIcon('award'));
		$this->assertSame('', InboxLook::cleanIcon(''));
		foreach (array('nope', '"><svg onload=alert(1)>', '../x') as $bad) {
			$this->assertNull(InboxLook::cleanIcon($bad), $bad);
		}
	}

	/**
	 * @return void
	 */
	public function testFolderAndMailbox()
	{
		$a = $this->box(true);
		$inbox = $this->folder($a->id, 'INBOX', 'inbox');
		$parent = $this->folder($a->id, 'INBOX.Emailing');
		$child = $this->folder($a->id, 'INBOX.Emailing.Hard bounce');
		$other = $this->folder($a->id, 'INBOX.Emailing2');
		$look = new InboxLook($this->db);

		$this->assertSame(2, $look->setFolder($a->id, $parent, '#E69F00', 'award', true), 'the folder and its subfolder, not the folder of the same prefix');
		$this->assertSame('#e69f00', $this->look($child)->color);
		$this->assertSame('award', $this->look($child)->icon);
		$this->assertNull($this->look($other)->color);
		$this->assertSame(1, $look->setFolder($a->id, $inbox, '#2f855a', 'award'));
		$this->assertSame('#2f855a', $this->look($inbox)->color);
		$this->assertNull($this->look($inbox)->icon, 'a special folder keeps its icon');

		$this->assertSame(-1, $look->setFolder($a->id, $parent, 'url(x)', ''));
		$this->assertSame('InboxErrorBadColor', $look->error);
		$this->assertSame(-1, $look->setFolder($a->id, $parent, '', 'nope'));
		$this->assertSame(-2, $look->setFolder($a->id + 1000000, $parent, '', ''), 'folder of another mailbox');

		$this->assertSame(1, $look->setFolder($a->id, $child, '', ''));
		$this->assertNull($this->look($child)->color, 'back to the default');
		$this->assertSame(2, $look->resetFolders($a->id));
		$this->assertNull($this->look($parent)->icon);
		$this->assertNull($this->look($inbox)->color);

		$this->assertSame(1, $look->setAccount($a->id, '#000000', 'certificate'));
		$b = new InboxAccount($this->db);
		$b->fetch($a->id);
		$this->assertSame('#000000', $b->color);
		$this->assertSame('certificate', $b->icon);
		$this->assertSame(-1, $look->setAccount($a->id, '', 'nope'));
		$b->label = 'Renamed';
		$this->assertGreaterThan(0, $b->update($this->admin));
		$b->fetch($a->id);
		$this->assertSame('certificate', $b->icon, 'kept by the form of the mailbox');
	}

	/**
	 * @return void
	 */
	public function testUserAndGroupForgotten()
	{
		global $langs, $conf;

		$a = $this->box(true);
		$u = $this->user('look');
		$p = $this->db->prefix();
		$now = "'".$this->db->idate(dol_now())."'";
		$this->db->query("INSERT INTO ".$p."inbox_userparam (fk_user, fk_account, param, value) VALUES (".((int) $u->id).", 0, 'theme', 'dark')");
		$this->db->query("INSERT INTO ".$p."inbox_image_allow (fk_user, address, date_creation) VALUES (".((int) $u->id).", 'x@inbox-test.invalid', ".$now.")");
		$this->db->query("INSERT INTO ".$p."inbox_account_access (fk_account, fk_user, level, date_creation, fk_user_creat) VALUES (".((int) $a->id).", ".((int) $u->id).", 'read', ".$now.", 1)");
		$this->db->query("INSERT INTO ".$p."usergroup (entity, nom, datec) VALUES (1, 'inboxtest_look', ".$now.")");
		$gid = (int) $this->db->last_insert_id($p.'usergroup');
		$this->db->query("INSERT INTO ".$p."inbox_account_access (fk_account, fk_usergroup, level, date_creation, fk_user_creat) VALUES (".((int) $a->id).", ".$gid.", 'read', ".$now.", 1)");

		$trigger = new InterfaceInboxTriggers($this->db);
		$this->assertSame(0, $trigger->runTrigger('USER_DELETE', $u, $this->admin, $langs, $conf));
		foreach (array('inbox_userparam', 'inbox_image_allow', 'inbox_account_access') as $t) {
			$this->assertSame(0, (int) $this->db->getRow("SELECT COUNT(*) AS n FROM ".$p.$t." WHERE fk_user = ".((int) $u->id))->n, $t);
		}
		$group = new stdClass();
		$group->id = $gid;
		$this->assertSame(0, $trigger->runTrigger('USERGROUP_DELETE', $group, $this->admin, $langs, $conf));
		$this->assertSame(0, (int) $this->db->getRow("SELECT COUNT(*) AS n FROM ".$p."inbox_account_access WHERE fk_usergroup = ".$gid)->n);
	}
}
