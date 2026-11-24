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
 * \file    inbox/test/phpunit/InboxImapTestCase.php
 * \ingroup inbox
 * \brief   Base of the tests that write on the local Dovecot: mailbox ready, mails appended, cleaned after each test
 */

// From the command line only, never from the web: test/ stays in the Git branches
if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

require_once __DIR__.'/InboxTestCase.php';
dol_include_once('/inbox/class/inboxactions.class.php');

/**
 * Tests on the local Dovecot: folders ZzAct* and mails « @act.inbox-test.invalid » removed after each test
 */
abstract class InboxImapTestCase extends InboxTestCase
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
		// By UID (Webklex flags($uids, $uid): a third argument made it read sequence numbers)
		$res = $p->flags(array($uid), true);

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
}
