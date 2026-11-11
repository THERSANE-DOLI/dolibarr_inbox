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
 * \file    inbox/test/phpunit/InboxUpdatePendingTest.php
 * \ingroup inbox
 * \brief   1.86.0: an update not finished (files newer than the last activation) is detected
 */

require_once __DIR__.'/InboxTestCase.php';
require_once dirname(__DIR__, 2).'/lib/inboxapp.lib.php';

/**
 * The note of the constant MAIN_MODULE_INBOX is put back as it was after each test
 */
class InboxUpdatePendingTest extends InboxTestCase
{
	/**
	 * @return void
	 */
	public function testDetection()
	{
		global $conf;

		$p = $this->db->prefix();
		$where = " WHERE ".$this->db->decrypt('name')." = 'MAIN_MODULE_INBOX' AND entity IN (0, ".((int) $conf->entity).")";
		$row = $this->db->getRow("SELECT note FROM ".$p."const".$where." ORDER BY entity DESC");
		$this->assertTrue(is_object($row), 'module activated');
		$note = (string) $row->note;
		$text = (string) file_get_contents(dirname(__DIR__, 2).'/core/modules/modInbox.class.php');
		$this->assertSame(1, preg_match("/->version\\s*=\\s*'([^']+)'/", $text, $m));
		$set = function ($version) use ($p, $where) {
			$this->db->query("UPDATE ".$p."const SET note = '".$this->db->escape((string) json_encode(array('authorid' => 1, 'ip' => '', 'lastactivationversion' => $version)))."'".$where);
		};
		try {
			$set('1.0.0');
			$this->assertSame(array('from' => '1.0.0', 'to' => $m[1]), inboxUpdatePending(true));
			$set($m[1]);
			$this->assertNull(inboxUpdatePending(true), 'activated with these files');
			$set('99.0.0');
			$this->assertNull(inboxUpdatePending(true), 'files older: nothing said');
			$set('unknown');
			$this->assertNull(inboxUpdatePending(true), 'no version known: never guessed');
		} finally {
			$this->db->query("UPDATE ".$p."const SET note = '".$this->db->escape($note)."'".$where);
		}
	}
}
