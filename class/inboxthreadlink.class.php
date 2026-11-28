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
 * \brief   The link follows the conversation (1.73.0): linking a mail links its conversation, the mails that
 *          come in a linked conversation are linked, a mail detached from its conversation is left out
 */

require_once __DIR__.'/inboxlink.class.php';
require_once __DIR__.'/inboxmailref.class.php';
require_once __DIR__.'/inboxthreader.class.php';

/**
 * The links made by the conversation have the origin « thread » (badge in the panel). A mail detached
 * (inbox_mailref.thread_detached, kept by Message-ID so it survives every move) loses its links of the conversation,
 * never gets new ones, and its own links never go to the others.
 *
 * A link follows only between mails of the same subject (1.138.2): a correspondent who sends each new request as a
 * reply to the previous one, the subject replaced, keeps it in the same conversation; the piece of one request must not
 * reach the others.
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
	 * Two mails speak of the same thing: same subject once the prefixes « Re: », « Fwd: »... removed, or one subject
	 * contains the other (« URGENT - X », « [Ticket #12] X »). An empty subject never separates.
	 *
	 * @param string|null $a Subject
	 * @param string|null $b Subject
	 * @return bool
	 */
	public static function sameSubject($a, $b)
	{
		return InboxThreader::sameSubject($a, $b);
	}

	/**
	 * Other mails of the conversation of a mail (same mailbox, outside the trash and the junk)
	 *
	 * @param object $row         Mail of the index (rowid, fk_account, thread_key, subject)
	 * @param bool   $sameSubject Only the mails of the same subject (the ones a link follows to)
	 * @return object[]            Rows (rowid, fk_account, message_id, date_mail, from_email, subject, size)
	 */
	public function others($row, $sameSubject = false)
	{
		if ((string) ($row->thread_key ?? '') === '') {
			return array();
		}
		$p = $this->db->prefix();
		$rows = $this->db->getRows("SELECT m.rowid, m.fk_account, m.message_id, m.date_mail, m.from_email, m.subject, m.size FROM ".$p."inbox_mail as m"
			." INNER JOIN ".$p."inbox_folder as f ON f.rowid = m.fk_folder AND COALESCE(f.role, '') NOT IN ('trash', 'junk')"
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
			if ($sameSubject && !self::sameSubject($row->subject ?? '', $r->subject)) {
				continue;
			}
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
	 * @return array{others:int,detached:bool,stray:int} others: mails a link of this one follows to; stray: links of
	 *                                                    the conversation come from a mail of another subject
	 */
	public function state($row)
	{
		$refs = new InboxMailRef($this->db);
		$mine = $refs->forRows(array($row));
		$detached = isset($mine[(int) $row->rowid]) && $this->detachedOf(array($mine[(int) $row->rowid]));
		$others = $this->others($row, true);
		$byRow = $refs->forRows($others);
		$off = $this->detachedOf(array_values($byRow));
		$n = 0;
		foreach ($others as $r) {
			if (!isset($byRow[(int) $r->rowid]) || !isset($off[$byRow[(int) $r->rowid]])) {
				$n++;
			}
		}

		return array('others' => $n, 'detached' => $detached, 'stray' => count($this->strayLinks($row)));
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
		foreach ($this->others($row, true) as $r) {
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
	 * Synchronisation: new mails in a linked conversation get its links (origin « thread »), each from the mails of its
	 * subject; the links of a detached mail are not given
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
			." INNER JOIN ".$p."inbox_folder as f ON f.rowid = m.fk_folder AND COALESCE(f.role, '') NOT IN ('trash', 'junk')"
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
			$refOf = $refs->forRows($old[$key]);
			$off = $this->detachedOf(array_values($refOf));
			// Subjects of the mails holding each link: a link follows only to a new mail of the same subject
			$subjectsOf = array();
			foreach ($old[$key] as $o) {
				if (isset($refOf[(int) $o->rowid])) {
					$subjectsOf[$refOf[(int) $o->rowid]][] = (string) $o->subject;
				}
			}
			$links = array();
			foreach ($linker->listFor(array_diff(array_values($refOf), array_keys($off))) as $l) {
				$k = $l['type'].':'.$l['element'];
				$links[$k] = $l;
				$links[$k]['subjects'] = array_merge($links[$k]['subjects'] ?? array(), $subjectsOf[$l['mailref']] ?? array());
			}
			if (!$links) {
				continue;
			}
			// Only from a correspondent of the conversation (sender or recipient of its mails): a Message-ID or a
			// subject copied by someone else never gives him the pieces of the conversation (audit INBX-067)
			$known = array();
			$oldIds = array();
			foreach ($old[$key] as $o) {
				$known[strtolower(trim((string) $o->from_email))] = true;
				$oldIds[] = (int) $o->rowid;
			}
			$addrs = $this->db->getRows("SELECT DISTINCT LOWER(email) as e FROM ".$p."inbox_mail_address WHERE fk_mail IN (".implode(',', $oldIds).") LIMIT 5000");
			foreach (is_array($addrs) ? $addrs : array() as $a) {
				$known[trim((string) $a->e)] = true;
			}
			foreach ($rows as $r) {
				if (!isset($known[strtolower(trim((string) $r->from_email))])) {
					continue;
				}
				$ref = $refs->fetchOrCreate($r);
				if ($ref <= 0 || $this->detachedOf(array($ref))) {
					continue;
				}
				foreach ($links as $l) {
					$same = false;
					foreach ($l['subjects'] as $subject) {
						if (self::sameSubject($subject, $r->subject)) {
							$same = true;
							break;
						}
					}
					if (!$same) {
						continue;
					}
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
	 * Links of the conversation (origin « thread ») that came from a mail of another subject: made before 1.138.2, when
	 * a link went to every mail of the conversation. A link is stray when the mails holding the piece by themselves
	 * (any other origin) all have another subject than its mail; without such a mail it is kept (nothing proves it).
	 *
	 * @param object $row Mail of the conversation (rowid, fk_account, thread_key, subject)
	 * @return array<int,array{link:int,mailref:int,mail:int,type:string,element:int,subject:string,date:int}>
	 */
	public function strayLinks($row)
	{
		$mails = array_merge(array($row), $this->others($row));
		if (count($mails) < 2) {
			return array();
		}
		$refOf = (new InboxMailRef($this->db))->forRows($mails);
		$byRef = array();
		foreach ($mails as $m) {
			if (isset($refOf[(int) $m->rowid]) && !isset($byRef[$refOf[(int) $m->rowid]])) {
				$byRef[$refOf[(int) $m->rowid]] = $m;
			}
		}
		if (!$byRef) {
			return array();
		}
		$links = (new InboxLink($this->db))->listFor(array_keys($byRef));
		// Subjects of the mails that hold each piece by themselves
		$sources = array();
		foreach ($links as $l) {
			if ($l['origin'] !== self::ORIGIN && isset($byRef[$l['mailref']])) {
				$sources[$l['type'].':'.$l['element']][] = (string) $byRef[$l['mailref']]->subject;
			}
		}
		$out = array();
		foreach ($links as $l) {
			$k = $l['type'].':'.$l['element'];
			if ($l['origin'] !== self::ORIGIN || empty($sources[$k]) || !isset($byRef[$l['mailref']])) {
				continue;
			}
			$m = $byRef[$l['mailref']];
			$same = false;
			foreach ($sources[$k] as $subject) {
				if (self::sameSubject($subject, $m->subject)) {
					$same = true;
					break;
				}
			}
			if (!$same) {
				$out[] = array('link' => $l['id'], 'mailref' => $l['mailref'], 'mail' => (int) $m->rowid, 'type' => $l['type'], 'element' => $l['element'],
					'subject' => (string) $m->subject, 'date' => (int) $this->db->jdate($m->date_mail));
			}
		}

		return $out;
	}

	/**
	 * Remove the stray links chosen by the user, among the ones strayLinks() finds now (never a link given by the
	 * browser alone)
	 *
	 * @param object $row     Mail of the conversation
	 * @param int[]  $linkIds Links validated
	 * @return array<int,array{mail:int,type:string,element:int}>|null Links removed (for the undo), null KO
	 */
	public function removeStray($row, array $linkIds)
	{
		$wanted = array_flip(array_map('intval', $linkIds));
		$linker = new InboxLink($this->db);
		$removed = array();
		$this->db->begin();
		foreach ($this->strayLinks($row) as $l) {
			if (!isset($wanted[$l['link']])) {
				continue;
			}
			if ($linker->unlink($l['link']) < 0) {
				$this->error = $linker->error;
				$this->db->rollback();
				return null;
			}
			$removed[] = array('mail' => $l['mail'], 'type' => $l['type'], 'element' => $l['element']);
		}
		$this->db->commit();

		return $removed;
	}

	/**
	 * Undo of removeStray(): the links given back, only on mails of this conversation and pieces the user may read
	 *
	 * @param User                                          $user  User
	 * @param object                                        $row   Mail of the conversation
	 * @param array<int,array{mail:int,type:string,element:int}> $links Links removed
	 * @return int Links given back
	 */
	public function restoreStray(User $user, $row, array $links)
	{
		$mails = array();
		foreach (array_merge(array($row), $this->others($row)) as $m) {
			$mails[(int) $m->rowid] = $m;
		}
		$refs = new InboxMailRef($this->db);
		$linker = new InboxLink($this->db);
		$n = 0;
		foreach (array_slice($links, 0, 500) as $l) {
			$mail = $mails[(int) ($l['mail'] ?? 0)] ?? null;
			$type = (string) ($l['type'] ?? '');
			$id = (int) ($l['element'] ?? 0);
			if ($mail === null || $type === '' || $id <= 0 || !InboxElements::canRead($user, $type, $id)) {
				continue;
			}
			$ref = $refs->fetchOrCreate($mail, $user);
			if ($ref > 0 && $linker->link($ref, $type, $id, null, self::ORIGIN) > 0) {
				$n++;
			}
		}

		return $n;
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
