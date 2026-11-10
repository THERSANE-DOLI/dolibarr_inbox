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
 * \file    inbox/class/inboxattachmentfile.class.php
 * \ingroup inbox
 * \brief   Read-only access to one attachment of a mail, for the viewers of other modules (hook inboxAttachmentViewers)
 *
 * Inbox never gives a path nor an address of the mail server: a module that opens an attachment loads it with this
 * class, with the user who asks. Same checks as the attachment endpoint of inbox:
 * - the user may read the mailbox (InboxReader);
 * - an attachment of the mail itself (pictures of the body and attachments of attached mails apart);
 * - antivirus: an infected attachment is never given; an attachment not scanned (or too big for the antivirus) only
 *   when the user chose to open it anyway (same « force » as the download of inbox).
 * Read only: a received attachment is not changed (no write method).
 */

require_once __DIR__.'/inboxreader.class.php';

/**
 * One attachment of a mail, read only
 */
class InboxAttachmentFile
{
	/** @var DoliDB */
	protected $db;

	/** @var string Error code: not_found, gone, error, infected, unscanned */
	public $errorCode = '';

	/** @var string */
	public $error = '';

	/** @var int */
	protected $mailId = 0;

	/** @var int */
	protected $part = -1;

	/** @var string */
	protected $name = '';

	/** @var string */
	protected $content = '';

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct(DoliDB $db)
	{
		$this->db = $db;
	}

	/**
	 * Attachments the user may open in a mail: index in the mail => array(name, size), pictures of the body apart
	 *
	 * @param InboxMessage $message
	 * @return array<int,array<string,mixed>>
	 */
	public static function listOf($message)
	{
		$out = array();
		foreach ($message->attachments as $i => $a) {
			if (empty($a['inline']) && empty($a['embedded'])) {
				$out[$i] = $a;
			}
		}

		return $out;
	}

	/**
	 * @param User $fuser  User who opens the attachment
	 * @param int  $mailId Mail (row of the index of inbox)
	 * @param int  $part   Index of the attachment in the mail
	 * @param bool $force  The user chose to open an attachment not scanned by the antivirus
	 * @return int 1 loaded, 0 not found, -1 refused (code in $this->errorCode)
	 */
	public function load(User $fuser, $mailId, $part, $force = false)
	{
		global $langs;

		$this->errorCode = '';
		$this->error = '';
		$reader = new InboxReader($this->db);
		if (!$reader->load($fuser, (int) $mailId)) {
			$this->errorCode = 'not_found';
			return 0;
		}
		$raw = $reader->raw($fuser);
		if ($raw === null) {
			$this->errorCode = $reader->errorCode === 'gone' ? 'gone' : 'error';
			$this->error = $reader->error;
			return 0;
		}
		$list = self::listOf(InboxMimeParser::parse($raw));
		if (!isset($list[(int) $part])) {
			$this->errorCode = 'not_found';
			return 0;
		}
		$a = $list[(int) $part];
		$scan = new InboxAttachmentScan($this->db);
		$verdict = $scan->check($a['content'], $a['name']);
		$langs->load('inbox@inbox');
		if ($verdict['state'] === InboxAttachmentScan::INFECTED) {
			$this->errorCode = 'infected';
			$this->error = $langs->transnoentitiesnoconv('InboxAttInfected', (string) $verdict['message']);
			return -1;
		}
		if (in_array($verdict['state'], array(InboxAttachmentScan::UNSCANNED, InboxAttachmentScan::TOO_BIG), true) && !$force) {
			$this->errorCode = 'unscanned';
			$this->error = $langs->transnoentitiesnoconv('InboxAttUnscanned');
			return -1;
		}
		$this->mailId = (int) $mailId;
		$this->part = (int) $part;
		$this->name = (string) $a['name'];
		$this->content = (string) $a['content'];

		return 1;
	}

	/**
	 * @return string Name of the attachment (cleaned by the parser of inbox)
	 */
	public function getFileName()
	{
		return $this->name;
	}

	/**
	 * @return int
	 */
	public function getSize()
	{
		return strlen($this->content);
	}

	/**
	 * @return string Content of the attachment
	 */
	public function getContent()
	{
		return $this->content;
	}

	/**
	 * Address of the download by inbox (its own checks, its name of file)
	 *
	 * @return string
	 */
	public function getDownloadUrl()
	{
		return dol_buildpath('/inbox/ajax/attachment.php', 1).'?id='.$this->mailId.'&part='.$this->part.'&mode=download&force=1';
	}
}
