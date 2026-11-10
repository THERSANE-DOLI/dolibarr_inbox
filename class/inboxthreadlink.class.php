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
 * \file    inbox/class/inboxthreadlink.class.php
 * \ingroup inbox
 * \brief   The link follows the conversation (lot A3, 1.73.0): linking a mail links its conversation, the mails that
 *          come in a linked conversation are linked, a mail detached from its conversation is left out
 */

require_once __DIR__.'/inboxlink.class.php';
require_once __DIR__.'/inboxmailref.class.php';

/**
 * The links made by the conversation have the origin « thread » (badge in the panel). A mail detached
 * (inbox_mailref.thread_detached, kept by Message-ID so it survives every move) loses its links of the conversation,
 * never gets new ones, and its own links never go to the others.
 */
class InboxThreadLink
{
	/** Origin of the links made by the conversation */
	const ORIGIN = 'thread';

	/** Mails of a conversation looked at */
	const MAX_MAILS = 200;

	/** @var DoliDB */
	protected $db;

	/** @var string */
	public $error = '';

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct(DoliDB $db)
	{
		$this->db = $db;
	}

	/**
	 * Other mails of the conversation of a mail (same mailbox, outside the trash and the junk)
	 *
	 * @param object $row Mail of the index (rowid, fk_account, thread_key)
	 * @return object[]    Rows (rowid, fk_account, message_id, date_mail, from_email, subject, size)
	 */
	public function others($row)
	{
		if ((string) ($row->thread_key ?? '') === '') {
			return array();
		}
		$p = $this->db->prefix();
		$rows = $this->db->getRows("SELECT m.rowid, m.fk_account, m.message_id, m.date_mail, m.from_email, m.subject, m.size FROM ".$p."inbox_mail as m"
			." INNER JOIN ".$p."inbox_folder as f ON f.rowid = m.fk_folder AND f.role NOT IN ('trash', 'junk')"
			." WHERE m.fk_account = ".((int) $row->fk_account)." AND m.thread_key = '".$this->db->escape((string) $row->thread_key)."' AND m.rowid <> ".((int) $row->rowid)
			." ORDER BY m.rowid LIMIT ".self::MAX_MAILS);
		$rows = is_array($rows) ? $rows : array();
		// A copy of the mail itself (same Message-ID in another folder) is not another mail
		$mine = (new InboxMailRef($this->db))->keyOf($row);
		$out = array();
		$seen = array();
		foreach ($rows as $r) {
			$k = (string) $r->message_id !== '' ? (string) $r->message_id : 'row'.$r->rowid;
			if ($k === $mine || isset($seen[$k])) {
				continue;
			}
			$seen[$k] = true;
			$out[] = $r;
		}

		return $out;
	}

	/**
	 * Mailrefs detached among some
	 *
	 * @param int[] $mailrefIds Mailrefs
	 * @return array<int,bool>
	 */
	public function detachedOf(array $mailrefIds)
	{
		$mailrefIds = array_filter(array_map('intval', $mailrefIds));
		if (!$mailrefIds) {
			return array();
		}
		$out = array();
		$rows = $this->db->getRows("SELECT rowid FROM ".$this->db->prefix()."inbox_mailref WHERE thread_detached = 1 AND rowid IN (".implode(',', $mailrefIds).") LIMIT ".count($mailrefIds));
		foreach (is_array($rows) ? $rows : array() as $r) {
			$out[(int) $r->rowid] = true;
		}

		return $out;
	}

	/**
	 * What the panel says of the conversation of a mail
	 *
	 * @param object $row Mail
	 * @return array{others:int,detached:bool}
	 */
	public function state($row)
	{
		$refs = new InboxMailRef($this->db);
		$mine = $refs->forRows(array($row));
		$detached = isset($mine[(int) $row->rowid]) && $this->detachedOf(array($mine[(int) $row->rowid]));
		$others = $this->others($row);
		$byRow = $refs->forRows($others);
		$off = $this->detachedOf(array_values($byRow));
		$n = 0;
		foreach ($others as $r) {
			if (!isset($byRow[(int) $r->rowid]) || !isset($off[$byRow[(int) $r->rowid]])) {
				$n++;
			}
		}

		return array('others' => $n, 'detached' => $detached);
	}

	/**
	 * A piece linked to a mail: the other mails of its conversation too (not the detached ones), origin « thread »
	 *
	 * @param User   $user      User who linked
	 * @param object $row       Mail linked
	 * @param string $type      Type of the piece
	 * @param int    $elementId Piece
	 * @return int               Other mails linked now, < 0 KO
	 */
	public function linkThread(User $user, $row, $type, $elementId)
	{
		$refs = new InboxMailRef($this->db);
		$mine = $refs->forRows(array($row));
		if (isset($mine[(int) $row->rowid]) && $this->detachedOf(array($mine[(int) $row->rowid]))) {
			// Detached on purpose: the piece stays on this mail only
			return 0;
		}
		$linker = new InboxLink($this->db);
		$n = 0;
		foreach ($this->others($row) as $r) {
			$ref = $refs->fetchOrCreate($r, $user);
			if ($ref <= 0 || $this->detachedOf(array($ref))) {
				continue;
			}
			$before = $this->db->getRow("SELECT rowid FROM ".$this->db->prefix()."inbox_link WHERE fk_mailref = ".((int) $ref)." AND element_type = '".$this->db->escape((string) $type)."' AND fk_element = ".((int) $elementId));
			if (is_object($before)) {
				continue;
			}
			if ($linker->link($ref, $type, $elementId, null, self::ORIGIN) > 0) {
				$n++;
			} else {
				$this->error = $linker->error;
				return -1;
			}
		}

		return $n;
	}

	/**
	 * Synchronisation: new mails in a linked conversation get its links (origin « thread »); the links of a detached
	 * mail are not given
	 *
	 * @param int   $accountId Mailbox
	 * @param int[] $mailIds   New mails of the index (their conversation assigned)
	 * @return int             Links made
	 */
	public function followNew($accountId, array $mailIds)
	{
		$mailIds = array_filter(array_map('intval', $mailIds));
		if (!$mailIds) {
			return 0;
		}
		$p = $this->db->prefix();
		$news = $this->db->getRows("SELECT m.rowid, m.fk_account, m.message_id, m.date_mail, m.from_email, m.subject, m.size, m.thread_key FROM ".$p."inbox_mail as m"
			." INNER JOIN ".$p."inbox_folder as f ON f.rowid = m.fk_folder AND f.role NOT IN ('trash', 'junk')"
			." WHERE m.rowid IN (".implode(',', $mailIds).") AND m.thread_key IS NOT NULL AND m.thread_key <> '' LIMIT ".count($mailIds));
		if (!is_array($news) || !$news) {
			return 0;
		}
		// Conversations with at least one link, read once for all the new mails
		$byThread = array();
		foreach ($news as $r) {
			$byThread[(string) $r->thread_key][] = $r;
		}
		$keys = array_map(function ($k) {
			return "'".$this->db->escape((string) $k)."'";
		}, array_keys($byThread));
		$refs = new InboxMailRef($this->db);
		$linker = new InboxLink($this->db);
		$isNew = array_flip($mailIds);
		$made = 0;
		$members = $this->db->getRows("SELECT m.rowid, m.fk_account, m.message_id, m.date_mail, m.from_email, m.subject, m.size, m.thread_key FROM ".$p."inbox_mail as m"
			." WHERE m.fk_account = ".((int) $accountId)." AND m.thread_key IN (".implode(',', $keys).") LIMIT 5000");
		$old = array();
		foreach (is_array($members) ? $members : array() as $r) {
			if (!isset($isNew[(int) $r->rowid])) {
				$old[(string) $r->thread_key][] = $r;
			}
		}
		foreach ($byThread as $key => $rows) {
			if (empty($old[$key])) {
				continue;
			}
			$oldRefs = array_values($refs->forRows($old[$key]));
			$off = $this->detachedOf($oldRefs);
			$links = array();
			foreach ($linker->listFor(array_diff($oldRefs, array_keys($off))) as $l) {
				$links[$l['type'].':'.$l['element']] = $l;
			}
			if (!$links) {
				continue;
			}
			foreach ($rows as $r) {
				$ref = $refs->fetchOrCreate($r);
				if ($ref <= 0 || $this->detachedOf(array($ref))) {
					continue;
				}
				foreach ($links as $l) {
					$exists = $this->db->getRow("SELECT rowid FROM ".$p."inbox_link WHERE fk_mailref = ".((int) $ref)." AND element_type = '".$this->db->escape($l['type'])."' AND fk_element = ".((int) $l['element']));
					if (!is_object($exists) && $linker->link($ref, $l['type'], $l['element'], null, self::ORIGIN) > 0) {
						$made++;
					}
				}
			}
		}

		return $made;
	}

	/**
	 * « Detach this mail from its conversation »: its links made by the conversation go, it gets no new one
	 *
	 * @param User   $user User
	 * @param object $row  Mail (access checked by the caller)
	 * @return array<int,array{type:string,element:int}>|null Links removed (for the undo), null KO
	 */
	public function detach(User $user, $row)
	{
		$refs = new InboxMailRef($this->db);
		$ref = $refs->fetchOrCreate($row, $user);
		if ($ref <= 0) {
			$this->error = $refs->error ?: 'InboxErrorNoMessageId';
			return null;
		}
		$p = $this->db->prefix();
		$this->db->begin();
		$removed = array();
		$linker = new InboxLink($this->db);
		foreach ($linker->listFor(array($ref)) as $l) {
			if ($l['origin'] === self::ORIGIN) {
				if ($linker->unlink($l['id']) < 0) {
					$this->error = $linker->error;
					$this->db->rollback();
					return null;
				}
				$removed[] = array('type' => $l['type'], 'element' => $l['element']);
			}
		}
		if (!$this->db->query("UPDATE ".$p."inbox_mailref SET thread_detached = 1 WHERE rowid = ".((int) $ref))) {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			return null;
		}
		$this->db->commit();

		return $removed;
	}

	/**
	 * Back in its conversation (undo of the detachment): the links given back, the next ones follow again
	 *
	 * @param User                                  $user  User
	 * @param object                                $row   Mail
	 * @param array<int,array{type:string,element:int}> $links Links removed by detach()
	 * @return int 1 OK, < 0 KO
	 */
	public function attach(User $user, $row, array $links)
	{
		$refs = new InboxMailRef($this->db);
		$ref = $refs->fetchOrCreate($row, $user);
		if ($ref <= 0) {
			$this->error = $refs->error ?: 'InboxErrorNoMessageId';
			return -1;
		}
		$this->db->query("UPDATE ".$this->db->prefix()."inbox_mailref SET thread_detached = 0 WHERE rowid = ".((int) $ref));
		$linker = new InboxLink($this->db);
		foreach (array_slice($links, 0, 50) as $l) {
			$type = (string) ($l['type'] ?? '');
			$id = (int) ($l['element'] ?? 0);
			// Only pieces the user may read (the undo comes from the browser)
			if ($type !== '' && $id > 0 && InboxElements::canRead($user, $type, $id)) {
				$linker->link($ref, $type, $id, null, self::ORIGIN);
			}
		}

		return 1;
	}
}
