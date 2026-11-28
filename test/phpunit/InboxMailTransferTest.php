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
 * \file    inbox/test/phpunit/InboxMailTransferTest.php
 * \ingroup inbox
 * \brief   1.12.0: mails imported (.eml, mbox of Thunderbird with its flags), folders exported (mbox, zip)
 */

// From the command line only, never from the web: test/ stays in the Git branches
if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

require_once __DIR__.'/InboxImapTestCase.php';
dol_include_once('/inbox/class/inboxmailtransfer.class.php');

/**
 * Tests on the local Dovecot (folders ZzAct* and mails « @act.inbox-test.invalid » removed after each test)
 */
class InboxMailTransferTest extends InboxImapTestCase
{
	/**
	 * A mail as an entry of an mbox of Thunderbird (LF lines, its status, a body line « >From » escaped)
	 *
	 * @param string $id     Local part of its Message-ID
	 * @param string $status X-Mozilla-Status
	 * @param string $keys   X-Mozilla-Keys
	 * @return string
	 */
	protected function entry($id, $status = '0000', $keys = '')
	{
		return "X-Mozilla-Status: ".$status."\nX-Mozilla-Keys: ".$keys."\nMessage-ID: <".$id.self::MARK.">\nFrom: Client <client@example.invalid>\nTo: ".self::BOX
			."\nSubject: ZzAct ".$id."\nDate: Sat, 15 Mar 2025 10:00:00 +0100\n\nHello\n>From the start of a line\n";
	}

	/**
	 * @return void
	 */
	public function testImport()
	{
		$a = $this->ready();
		$t = new InboxMailTransfer($this->db);
		$target = $this->folder($a, 'INBOX.ZzActB');
		$this->assertTrue($t->open($this->admin, $target, InboxAccess::LEVEL_MANAGE), $t->error);
		$res = $t->import($this->admin, array(
			$this->entry('i1', '0005', '$label1'),
			$this->entry('i2', '0009'),
			"just some text",
		), true);
		$this->assertSame(array('imported', 'removed', 'failed'), array_column($res, 'state'));
		$this->assertSame('ZzAct i1', $res[0]['subject']);
		$this->assertSame('InboxImportNotAMail', $res[2]['reason']);

		// On the server with the flags and tags of Thunderbird, the escaped line given back
		$uids = $this->onServer('INBOX.ZzActB', 'i1');
		$this->assertCount(1, $uids);
		$flags = $this->flagsOnServer('INBOX.ZzActB', $uids[0]);
		foreach (array('Seen', 'Flagged', '$label1') as $f) {
			$this->assertStringContainsString($f, $flags);
		}
		$this->assertSame(array(), $this->onServer('INBOX.ZzActB', 'i2'), 'removed in Thunderbird: not imported');
		$p = $this->imap();
		$p->selectFolder('INBOX.ZzActB');
		$raw = $p->content(array($uids[0]), 'RFC822', true)[$uids[0]] ?? '';
		$this->assertStringContainsString("\r\nFrom the start of a line", $raw);

		// Imported again after the synchronisation: already there
		$this->sync($a);
		$t2 = new InboxMailTransfer($this->db);
		$t2->open($this->admin, $target, InboxAccess::LEVEL_MANAGE);
		$this->assertSame('duplicate', $t2->import($this->admin, array($this->entry('i1')), true)[0]['state']);
		$this->assertNotEmpty(InboxActionLog::listForAccount($this->db, $a->id, array('action' => 'import'))['lines']);

		// Level read: no import; no access: not found
		$alice = $this->user('alice');
		(new InboxAccess($this->db))->grant($this->admin, $a->id, $alice->id, 0, 'read');
		InboxAccess::clearCache();
		$t3 = new InboxMailTransfer($this->db);
		$this->assertFalse($t3->open($alice, $target, InboxAccess::LEVEL_MANAGE));
		$this->assertSame('InboxErrorNeedManage', $t3->error);
		$this->assertTrue($t3->open($alice, $target, InboxAccess::LEVEL_READ));
		$this->assertFalse((new InboxMailTransfer($this->db))->open($this->user('bob'), $target, InboxAccess::LEVEL_READ));
		$this->db->query("DELETE FROM ".$this->db->prefix()."inbox_action_log WHERE fk_account = ".((int) $a->id));
	}

	/**
	 * @return void
	 */
	public function testExport()
	{
		$a = $this->ready();
		$folder = $this->folder($a, 'INBOX.ZzActA');
		$t = new InboxMailTransfer($this->db);
		$this->assertTrue($t->open($this->admin, $folder, InboxAccess::LEVEL_READ));
		ob_start();
		$this->assertTrue($t->export($this->admin, 'mbox'));
		$mbox = ob_get_clean();
		$this->assertSame(3, preg_match_all('/^From - /m', $mbox), 'one entry per mail');
		$this->assertStringContainsString('X-Mozilla-Status: 0000', $mbox);
		$this->assertStringContainsString('Subject: ZzAct m2', $mbox);
		$this->assertStringNotContainsString("\r\n", $mbox, 'lines of an mbox');

		// The mbox exported imports back into another folder, read as Thunderbird would
		$entries = preg_split('/^From - [^\n]*\n/m', $mbox, -1, PREG_SPLIT_NO_EMPTY);
		$t2 = new InboxMailTransfer($this->db);
		$t2->open($this->admin, $this->folder($a, 'INBOX.ZzActB'), InboxAccess::LEVEL_MANAGE);
		$this->assertSame(array('imported', 'imported', 'imported'), array_column($t2->import($this->admin, $entries, true), 'state'));

		$t3 = new InboxMailTransfer($this->db);
		$t3->open($this->admin, $folder, InboxAccess::LEVEL_READ);
		ob_start();
		$this->assertTrue($t3->export($this->admin, 'zip'));
		$zip = ob_get_clean();
		$this->assertSame('PK', substr($zip, 0, 2));
		$this->assertNotEmpty(InboxActionLog::listForAccount($this->db, $a->id, array('action' => 'export'))['lines']);
		$this->db->query("DELETE FROM ".$this->db->prefix()."inbox_action_log WHERE fk_account = ".((int) $a->id));
	}
}
