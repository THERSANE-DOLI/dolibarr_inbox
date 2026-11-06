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
 * \file    inbox/class/inboxnewmarks.class.php
 * \ingroup inbox
 * \brief   New mails (not the same as unread): arrived in a folder since the user last looked at it
 */

require_once __DIR__.'/inboxuserparam.class.php';

/**
 * Unread = no \Seen on the server, the same for everybody in a shared mailbox. New = arrived in the folder since
 * this user last left it, and still unread: the dot of the lists, as the usual mail clients. Kept per user and per
 * folder in the settings of the user (seen_marks of the mailbox: folder => last row seen), never on the mail server
 * (\Recent of IMAP is reset by any other client). A folder never opened starts at its last mail: nothing is new.
 */
class InboxNewMarks
{
	/** @var DoliDB */
	protected $db;

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct(DoliDB $db)
	{
		$this->db = $db;
	}

	/**
	 * Marks of a mailbox for a user; a folder without mark gets its last mail now (saved)
	 *
	 * @param User $user      User
	 * @param int  $accountId Mailbox
	 * @return array<int,int> Folder => last row seen
	 */
	public function marks(User $user, $accountId)
	{
		$params = new InboxUserParam($this->db);
		$marks = json_decode((string) $params->get($user, 'seen_marks', (int) $accountId), true);
		$marks = is_array($marks) ? array_map('intval', $marks) : array();
		$rows = $this->db->getRows("SELECT f.rowid, MAX(m.rowid) AS last FROM ".$this->db->prefix()."inbox_folder as f LEFT JOIN ".$this->db->prefix()."inbox_mail as m ON m.fk_folder = f.rowid"
			." WHERE f.fk_account = ".((int) $accountId)." AND f.status = 1 GROUP BY f.rowid LIMIT 5000");
		$changed = false;
		$out = array();
		foreach (is_array($rows) ? $rows : array() as $r) {
			$id = (int) $r->rowid;
			if (!isset($marks[$id])) {
				$marks[$id] = (int) $r->last;
				$changed = true;
			}
			$out[$id] = (int) $marks[$id];
		}
		if ($changed || count($out) !== count($marks)) {
			// Folders removed leave the marks
			$params->set($user, 'seen_marks', (string) json_encode((object) $out), (int) $accountId);
		}

		return $out;
	}

	/**
	 * New mails per folder: unread and after the mark of the user
	 *
	 * @param array<int,int> $marks Folder => last row seen
	 * @return array<int,int> Folder => count (folders with new mails only)
	 */
	public function counts(array $marks)
	{
		$out = array();
		foreach (array_chunk($marks, 100, true) as $chunk) {
			$or = array();
			foreach ($chunk as $folderId => $mark) {
				$or[] = "(fk_folder = ".((int) $folderId)." AND rowid > ".((int) $mark).")";
			}
			$res = $this->db->query("SELECT fk_folder, COUNT(rowid) AS n FROM ".$this->db->prefix()."inbox_mail WHERE seen = 0 AND (".implode(' OR ', $or).") GROUP BY fk_folder");
			while ($res && ($o = $this->db->fetch_object($res))) {
				$out[(int) $o->fk_folder] = (int) $o->n;
			}
		}

		return $out;
	}
}
