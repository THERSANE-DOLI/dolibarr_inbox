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
 * folder in the settings of the user (seen_marks of the mailbox: folder => last arrival seen), never on the mail server
 * (\Recent of IMAP is reset by any other client). A folder never opened starts at its last mail: nothing is new.
 * The order of arrival is the key of sort of the connector (UID of IMAP), not the row of the index: the first indexing
 * reads the recent mails first, the older ones get greater rows and would look new. A folder renumbered by the
 * server (its last arrival below the mark) starts again at its last mail.
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
		$rows = $this->db->getRows("SELECT f.rowid, MAX(m.sort_key) AS last FROM ".$this->db->prefix()."inbox_folder as f LEFT JOIN ".$this->db->prefix()."inbox_mail as m ON m.fk_folder = f.rowid"
			." WHERE f.fk_account = ".((int) $accountId)." AND f.status = 1 GROUP BY f.rowid LIMIT 5000");
		$changed = false;
		$out = array();
		foreach (is_array($rows) ? $rows : array() as $r) {
			$id = (int) $r->rowid;
			if (!isset($marks[$id]) || (int) $r->last < $marks[$id]) {
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
				$or[] = "(fk_folder = ".((int) $folderId)." AND sort_key > ".((int) $mark).")";
			}
			// Mails sent by the mailbox are never new (Sent, Drafts, or the sender is the mailbox)
			$p = $this->db->prefix();
			$res = $this->db->query("SELECT m.fk_folder, COUNT(m.rowid) AS n FROM ".$p."inbox_mail as m INNER JOIN ".$p."inbox_folder as f ON f.rowid = m.fk_folder"
				." INNER JOIN ".$p."inbox_account as a ON a.rowid = m.fk_account WHERE m.seen = 0 AND COALESCE(f.role, '') NOT IN ('sent', 'drafts')"
				." AND LOWER(COALESCE(m.from_email, '')) <> LOWER(a.email) AND (".str_replace(array('fk_folder', 'sort_key'), array('m.fk_folder', 'm.sort_key'), implode(' OR ', $or)).") GROUP BY m.fk_folder");
			while ($res && ($o = $this->db->fetch_object($res))) {
				$out[(int) $o->fk_folder] = (int) $o->n;
			}
		}

		return $out;
	}
}
