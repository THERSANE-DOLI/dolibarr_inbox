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
 * \file    inbox/class/inboximapreader.class.php
 * \ingroup inbox
 * \brief   One mail read in a mailbox by its Message-ID, read only
 */

require_once __DIR__.'/inboxmimeparser.class.php';

/**
 * One mail read in a mailbox by its Message-ID. Connection through crmclientconnector (master user or own login, as
 * set on the anti-spam tab of the email account).
 *
 * Read only, always: BODY.PEEK (the mail stays unread), no flag, no move. The mailboxes are production ones.
 */
class InboxImapReader
{
	/** Folders searched at most */
	const MAX_FOLDERS = 300;

	/** Seconds spent searching the folders at most */
	const TIME_BUDGET = 25;

	/** @var DoliDB */
	protected $db;

	/** @var string */
	public $error = '';

	/** @var bool Connected and searched, but the mail is in no folder */
	public $notFound = false;

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * @param EmailAccount $account   Email account of crmclientconnector
	 * @param string       $messageId Message-ID, checked by Inbox::normalizeMessageId()
	 * @return array{raw:string,folder:string,uid:int}|null Null if not found (notFound) or on error
	 */
	public function fetchRawByMessageId($account, $messageId)
	{
		$this->error = '';
		$this->notFound = false;
		dol_include_once('/crmclientconnector/class/antispamimap.class.php');
		if (!class_exists('CrmClientConnectorAntispamImap')) {
			$this->error = 'crmclientconnector IMAP classes not found';
			return null;
		}
		$imap = new CrmClientConnectorAntispamImap($this->db);
		$client = $imap->connect($account);
		if (!$client) {
			$this->error = $imap->error;
			return null;
		}

		$start = microtime(true);
		try {
			foreach ($this->orderedFolders($client) as $path) {
				if (microtime(true) - $start > self::TIME_BUDGET) {
					$this->error = 'Time budget spent before the end of the folders';
					break;
				}
				$found = $this->searchFolder($client, $path, $messageId);
				if ($found !== null) {
					$client->disconnect();
					return $found;
				}
			}
			$client->disconnect();
		} catch (Exception $e) {
			$this->error = $e->getMessage();
			return null;
		}
		if ($this->error === '') {
			$this->notFound = true;
		}

		return null;
	}

	/**
	 * Folders in the order a mail is most likely found: inbox, sent, the others, junk and trash last
	 *
	 * @param \Webklex\PHPIMAP\Client $client Connected client
	 * @return string[] Paths
	 */
	protected function orderedFolders($client)
	{
		$first = array();
		$sent = array();
		$others = array();
		$last = array();
		foreach ($client->getFolders(false) as $folder) {
			if (!empty($folder->no_select) || !CrmClientConnectorAntispamImap::isSafeFolderPath($folder->path)) {
				continue;
			}
			$path = (string) $folder->path;
			$name = strtolower(CrmClientConnectorAntispamImap::decodeFolderName($path));
			if (strtoupper($path) === 'INBOX') {
				$first[] = $path;
			} elseif (preg_match('/(sent|envoy|gesendet|inviat|enviad)/', $name)) {
				$sent[] = $path;
			} elseif (preg_match('/(junk|spam|trash|corbeille|deleted|supprim|ind[ée]sirable|papierkorb|cestino|papelera)/u', $name)) {
				$last[] = $path;
			} else {
				$others[] = $path;
			}
		}

		return array_slice(array_merge($first, $sent, $others, $last), 0, self::MAX_FOLDERS);
	}

	/**
	 * @param \Webklex\PHPIMAP\Client $client    Connected client
	 * @param string                  $path      Folder
	 * @param string                  $messageId Message-ID
	 * @return array{raw:string,folder:string,uid:int}|null
	 */
	protected function searchFolder($client, $path, $messageId)
	{
		$client->openFolder($path);
		// HEADER search is a substring search: every answer is checked against the exact Message-ID below. The value
		// has no quote nor backslash (Inbox::normalizeMessageId), it can be sent quoted as is
		$uids = $client->getConnection()->search(array('HEADER', 'Message-ID', '"'.$messageId.'"'), true);
		if (!is_array($uids) || empty($uids)) {
			return null;
		}
		$folder = $client->getFolderByPath($path);
		if (!$folder) {
			return null;
		}
		foreach (array_slice(array_map('intval', $uids), 0, 20) as $uid) {
			// leaveUnread(): BODY.PEEK, the mail is not marked as read
			$header = $folder->query()->leaveUnread()->setFetchBody(false)->getMessageByUid($uid)->getHeader()->raw;
			if (InboxMimeParser::parse($header."\r\n\r\n")->messageId !== $messageId) {
				continue;
			}
			$message = $folder->query()->leaveUnread()->getMessageByUid($uid);

			return array('raw' => rtrim($message->getHeader()->raw)."\r\n\r\n".$message->getRawBody(), 'folder' => $path, 'uid' => $uid);
		}

		return null;
	}
}
