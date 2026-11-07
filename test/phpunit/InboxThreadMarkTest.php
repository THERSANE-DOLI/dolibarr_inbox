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
 * \file    inbox/test/phpunit/InboxThreadMarkTest.php
 * \ingroup inbox
 * \brief   Lot W4 1.11.0: threads ignored (answers arrive read) or watched; unsubscribe refused when unsafe
 */

require_once __DIR__.'/InboxImapTestCase.php';
dol_include_once('/inbox/class/inboxthreadmark.class.php');
dol_include_once('/inbox/class/inboxreader.class.php');
dol_include_once('/inbox/class/inboxsearchsql.class.php');

/**
 * Tests on the local Dovecot (folders ZzAct* and mails « @act.inbox-test.invalid » removed after each test)
 */
class InboxThreadMarkTest extends InboxImapTestCase
{
	/**
	 * An answer to a mail of the test
	 *
	 * @param string $folder Folder
	 * @param string $id     Local part of its Message-ID
	 * @param string $parent Local part of the Message-ID it answers
	 * @return void
	 */
	protected function answer($folder, $id, $parent)
	{
		$raw = "Message-ID: <".$id.self::MARK.">\r\nIn-Reply-To: <".$parent.self::MARK.">\r\nReferences: <".$parent.self::MARK.">\r\nFrom: Client <client@example.invalid>\r\n"
			."To: ".self::BOX."\r\nSubject: Re: ZzAct ".$parent."\r\nDate: ".date('r', mktime(11, 0, 0, 3, 15, 2025))."\r\n\r\nAnswer ".$id."\r\n";
		$this->imap()->appendMessage($folder, $raw);
	}

	/**
	 * @return void
	 */
	public function testIgnoreAndWatch()
	{
		$a = $this->ready();
		$this->answer('INBOX.ZzActA', 'r1', 'm1');
		$this->sync($a);
		$m1 = $this->row($a, 'm1');
		$r1 = $this->row($a, 'r1');
		$this->assertSame(0, (int) $m1->seen);
		$marks = new InboxThreadMark($this->db);

		// Ignored: the thread read now, on the server
		$this->assertSame(1, $marks->set($this->admin, (int) $m1->rowid, InboxThreadMark::IGNORE, true), $marks->error);
		$this->assertSame(2, $marks->count, 'm1 and its answer');
		$this->assertStringContainsString('Seen', $this->flagsOnServer('INBOX.ZzActA', $this->onServer('INBOX.ZzActA', 'r1')[0]));
		$this->assertSame(0, $marks->set($this->admin, (int) $r1->rowid, InboxThreadMark::IGNORE, true), 'same thread: nothing to do');

		// A later answer arrives read, on the server and in the index; another thread stays unread
		$this->answer('INBOX.ZzActA', 'r2', 'r1');
		$this->append('INBOX.ZzActA', 'm4');
		$this->sync($a);
		$this->assertSame(1, (int) $this->row($a, 'r2')->seen);
		$this->assertStringContainsString('Seen', $this->flagsOnServer('INBOX.ZzActA', $this->onServer('INBOX.ZzActA', 'r2')[0]));
		$this->assertSame(0, (int) $this->row($a, 'm4')->seen);

		// Watched: for one user; ignoring needs « manage »
		$alice = $this->user('alice');
		$access = new InboxAccess($this->db);
		$access->grant($this->admin, $a->id, $alice->id, 0, 'read');
		InboxAccess::clearCache();
		$m2 = $this->row($a, 'm2');
		$this->assertSame(1, $marks->set($alice, (int) $m2->rowid, InboxThreadMark::WATCH, true), $marks->error);
		$this->assertSame(-1, $marks->set($alice, (int) $m2->rowid, InboxThreadMark::IGNORE, true));
		$this->assertSame('InboxErrorNeedManage', $marks->error);
		$this->assertSame(-1, $marks->set($this->user('bob'), (int) $m2->rowid, InboxThreadMark::WATCH, true));
		$this->assertSame('InboxMailNotFound', $marks->error);

		$engine = new InboxSearchSql($this->db);
		$folder = $this->folder($a, 'INBOX.ZzActA');
		foreach (array(array($alice, 1), array($this->admin, 0)) as $case) {
			$rows = $engine->search(array($a->id), $folder, array(), '', 50)['rows'];
			$marks->decorate($case[0], $rows);
			$byId = array_column($rows, null, 'id');
			$this->assertSame($case[1], $byId[(int) $m2->rowid]['watched'], 'watched for alice only');
			$this->assertSame(1, $byId[(int) $r1->rowid]['ignored'], 'ignored for everyone');
		}

		// Stop ignoring: the next answers arrive unread again
		$this->assertSame(1, $marks->set($this->admin, (int) $m1->rowid, InboxThreadMark::IGNORE, false));
		$journal = InboxActionLog::listForAccount($this->db, $a->id, array('action' => 'thread_ignore'));
		$this->assertNotEmpty($journal['lines']);
		$this->db->query("DELETE FROM ".$this->db->prefix()."inbox_action_log WHERE fk_account = ".((int) $a->id));

		// Threads merged by the threader: the mark follows the key kept
		$this->db->query("INSERT INTO ".$this->db->prefix()."inbox_thread_mark (entity, fk_account, thread_key, fk_user, mode, date_creation) VALUES (1, ".((int) $a->id).", '"
			.str_repeat('b', 32)."', 0, 'ignore', '".$this->db->idate(dol_now())."')");
		InboxThreadMark::merge($this->db, $a->id, str_repeat('a', 32), array(str_repeat('b', 32)));
		$this->assertSame(1, (int) $this->db->getRow("SELECT COUNT(*) AS n FROM ".$this->db->prefix()."inbox_thread_mark WHERE fk_account = ".((int) $a->id)." AND thread_key = '".str_repeat('a', 32)."'")->n);
	}

	/**
	 * @return void
	 */
	public function testUnsubscribeRules()
	{
		$h = function ($list, $post = '', $auth = '') {
			$out = array(array('List-Unsubscribe', $list));
			if ($post !== '') {
				$out[] = array('List-Unsubscribe-Post', $post);
			}
			if ($auth !== '') {
				$out[] = array('Authentication-Results', $auth);
			}
			return $out;
		};
		$info = InboxReader::unsubscribeInfo($h('<mailto:leave@list.invalid?subject=unsub>, <https://list.invalid/u?x=1>', 'List-Unsubscribe=One-Click'));
		$this->assertSame(array('https' => 'https://list.invalid/u?x=1', 'mailto' => 'leave@list.invalid', 'oneclick' => true), $info);
		$this->assertFalse(InboxReader::unsubscribeInfo($h('<https://list.invalid/u>'))['oneclick'], 'no List-Unsubscribe-Post');
		$this->assertFalse(InboxReader::unsubscribeInfo($h('<http://list.invalid/u>, <mailto:a@list.invalid>', 'List-Unsubscribe=One-Click'))['oneclick'], 'http is never one-click');
		$this->assertNull(InboxReader::unsubscribeInfo($h('<javascript:alert(1)>')));
		$this->assertNull(InboxReader::unsubscribeInfo(array()));
		$view = InboxReader::unsubscribeView($h('<https://list.invalid/u>', 'List-Unsubscribe=One-Click', 'mx.invalid; dkim=pass'), array('dkim' => 'pass'));
		$this->assertArrayNotHasKey('https', $view, 'the address never goes to the browser');

		// Refused: not signed; signed but aimed at the local network (never called)
		$a = $this->ready();
		$m1 = $this->row($a, 'm1');
		$reader = new InboxReader($this->db);
		$this->assertTrue($reader->load($this->admin, (int) $m1->rowid));
		$raw = "Message-ID: <m1".self::MARK.">\r\nFrom: List <news@list.invalid>\r\nSubject: News\r\nList-Unsubscribe: <https://127.0.0.1/u>\r\n"
			."List-Unsubscribe-Post: List-Unsubscribe=One-Click\r\n";
		$this->assertSame('InboxErrorUnsubscribeNotSigned', $reader->unsubscribe($this->admin, $raw."\r\nBody\r\n"));
		$signed = "Authentication-Results: mx.invalid; dkim=pass header.d=list.invalid\r\n".$raw."\r\nBody\r\n";
		$this->assertSame('InboxErrorUnsubscribeFailed', $reader->unsubscribe($this->admin, $signed));
		$this->assertStringContainsString('external url', strtolower($reader->error), 'refused before any call');
		$alice = $this->user('alice');
		(new InboxAccess($this->db))->grant($this->admin, $a->id, $alice->id, 0, 'read');
		InboxAccess::clearCache();
		$reader = new InboxReader($this->db);
		$reader->load($alice, (int) $m1->rowid);
		$this->assertSame('InboxErrorNeedManage', $reader->unsubscribe($alice, $signed));
	}
}
