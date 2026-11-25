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
 * \file    inbox/class/inboxthreadsplit.class.php
 * \ingroup inbox
 * \brief   Start a new conversation from a mail, join it back (1.139.0)
 */

require_once __DIR__.'/inboxthreader.class.php';
require_once __DIR__.'/inboxthreadlink.class.php';
require_once __DIR__.'/inboxlink.class.php';
require_once __DIR__.'/inboxmailref.class.php';

/**
 * A cut is a fact on the mail (kept by Message-ID): it holds in every mailbox of the entity that has the mail. The
 * mail and its answers form a new conversation; a late answer to an older mail stays in the old one (the cut follows
 * the answers, not the dates). The links made by the old conversation on the mails that leave are removed, except the
 * ones the user keeps.
 */
class InboxThreadSplit
{
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
	 * What the reading says of a mail: a cut point (and the mail it answers), or a mail that can start a conversation
	 *
	 * @param object $row   Mail of the index (rowid, fk_account, message_id, in_reply_to, refs, thread_key)
	 * @param string $level Level of the user on its mailbox
	 * @return array{root:int,can:bool,from:array{id:int,subject:string,date:int}|null}
	 */
	public function info($row, $level)
	{
		$out = array('root' => 0, 'can' => false, 'from' => null);
		$msgid = (string) ($row->message_id ?? '');
		if ($msgid === '' || (string) ($row->thread_key ?? '') === '') {
			return $out;
		}
		$threader = new InboxThreader($this->db);
		$out['root'] = (int) ($threader->rootsOf(array($msgid))[$msgid] ?? 0);
		$parent = $this->parentRow($row);
		if ($out['root'] > 0 && $parent) {
			$out['from'] = array('id' => (int) $parent->rowid, 'subject' => (string) $parent->subject, 'date' => (int) $this->db->jdate($parent->date_mail ?: $parent->date_received));
		}
		// Not the first mail of its conversation: it answers a mail of the same conversation
		$out['can'] = $out['root'] <= 0 && $parent && (string) $parent->thread_key === (string) $row->thread_key
			&& InboxAccess::atLeast($level, InboxAccess::LEVEL_HANDLE);
		if ($out['root'] > 0) {
			$out['can'] = InboxAccess::atLeast($level, InboxAccess::LEVEL_HANDLE);
		}

		return $out;
	}

	/**
	 * The mail a mail answers, in its mailbox (the nearest parent indexed)
	 *
	 * @param object $row Mail
	 * @return object|null Row (rowid, subject, date_mail, date_received, thread_key)
	 */
	protected function parentRow($row)
	{
		$parents = InboxThreader::parentsOf($row);
		if (!$parents) {
			return null;
		}
		$p = $this->db->prefix();
		$in = implode(',', array_map(function ($v) {
			return "'".$this->db->escape($v)."'";
		}, $parents));
		$rows = $this->db->getRows("SELECT m.rowid, m.message_id, m.subject, m.date_mail, m.date_received, m.thread_key FROM ".$p."inbox_mail as m"
			." INNER JOIN ".$p."inbox_folder as f ON f.rowid = m.fk_folder AND COALESCE(f.role, '') NOT IN ('trash', 'junk')"
			." WHERE m.fk_account = ".((int) $row->fk_account)." AND m.message_id IN (".$in.") LIMIT 200");
		$byId = array();
		foreach (is_array($rows) ? $rows : array() as $r) {
			$byId[(string) $r->message_id] = $byId[(string) $r->message_id] ?? $r;
		}
		foreach ($parents as $id) {
			if (isset($byId[$id])) {
				return $byId[$id];
			}
		}

		return null;
	}

	/**
	 * Mailboxes of the entity that have the mail, with its conversation in each
	 *
	 * @param object $row Mail
	 * @return array<int,string> Mailbox => key of the conversation of the mail
	 */
	protected function boxesOf($row)
	{
		global $conf;

		$p = $this->db->prefix();
		$rows = $this->db->getRows("SELECT m.fk_account, m.thread_key FROM ".$p."inbox_mail as m INNER JOIN ".$p."inbox_account as a ON a.rowid = m.fk_account AND a.entity = ".((int) $conf->entity)
			." WHERE m.message_id = '".$this->db->escape((string) $row->message_id)."' AND m.thread_key IS NOT NULL LIMIT 100");
		$out = array();
		foreach (is_array($rows) ? $rows : array() as $r) {
			$out[(int) $r->fk_account] = (string) $r->thread_key;
		}
		$out[(int) $row->fk_account] = (string) $row->thread_key;

		return $out;
	}

	/**
	 * Mails of a conversation of a mailbox, outside the trash and the junk, a mail once
	 *
	 * @param int    $accountId Mailbox
	 * @param string $key       Key
	 * @return object[] Rows (rowid, fk_account, message_id, subject, from_email, from_name, date_mail, size, thread_key)
	 */
	protected function mailsOf($accountId, $key)
	{
		$p = $this->db->prefix();
		$rows = $this->db->getRows("SELECT m.rowid, m.fk_account, m.message_id, m.subject, m.from_email, m.from_name, m.date_mail, m.date_received, m.size, m.thread_key FROM ".$p."inbox_mail as m"
			." INNER JOIN ".$p."inbox_folder as f ON f.rowid = m.fk_folder AND COALESCE(f.role, '') NOT IN ('trash', 'junk')"
			." WHERE m.fk_account = ".((int) $accountId)." AND m.thread_key = '".$this->db->escape((string) $key)."'"
			." ORDER BY COALESCE(m.date_mail, m.date_received) ASC, m.rowid ASC LIMIT 500");
		$out = array();
		foreach (is_array($rows) ? $rows : array() as $r) {
			$k = (string) $r->message_id !== '' ? (string) $r->message_id : 'row'.$r->rowid;
			$out[$k] = $out[$k] ?? $r;
		}

		return array_values($out);
	}

	/**
	 * Cut on a mail and new keys, in every mailbox that has it
	 *
	 * @param object $row  Mail
	 * @param int    $root InboxThreader::ROOT_USER, or ROOT_JOINED to join back
	 * @return bool
	 */
	protected function apply($row, $root)
	{
		$threader = new InboxThreader($this->db);
		$boxes = $this->boxesOf($row);
		if (!$threader->setRoot($row, $root)) {
			$this->error = 'InboxErrorNoMessageId';
			return false;
		}
		foreach ($boxes as $accountId => $key) {
			$keys = array($key);
			if ($root <= 0) {
				// Joined back: with the conversation of the mail it answers, in this mailbox
				$copy = $this->db->getRow("SELECT rowid, fk_account, message_id, in_reply_to, refs FROM ".$this->db->prefix()."inbox_mail WHERE fk_account = ".((int) $accountId)
					." AND message_id = '".$this->db->escape((string) $row->message_id)."' ORDER BY rowid");
				$parent = is_object($copy) ? $this->parentRow($copy) : null;
				if ($parent) {
					$keys[] = (string) $parent->thread_key;
				}
			}
			$threader->rethread($accountId, $keys);
		}

		return true;
	}

	/**
	 * What a cut on a mail would do, in its mailbox: the mails that leave, the ones that stay, the links of the old
	 * conversation on the mails that leave. Made and rolled back, so that it says exactly what the cut does.
	 *
	 * @param User   $user User
	 * @param object $row  Mail
	 * @return array{moving:array<int,array<string,mixed>>,staying:array<int,array<string,mixed>>,links:array<int,array<string,mixed>>}|null
	 */
	public function preview(User $user, $row)
	{
		$before = $this->mailsOf((int) $row->fk_account, (string) $row->thread_key);
		$this->db->begin();
		if (!$this->apply($row, InboxThreader::ROOT_USER)) {
			$this->db->rollback();
			return null;
		}
		$now = $this->db->getRow("SELECT thread_key FROM ".$this->db->prefix()."inbox_mail WHERE rowid = ".((int) $row->rowid));
		$newKey = is_object($now) ? (string) $now->thread_key : '';
		$moving = array();
		foreach ($this->mailsOf((int) $row->fk_account, $newKey) as $m) {
			$moving[(int) $m->rowid] = true;
		}
		$this->db->rollback();
		$out = array('moving' => array(), 'staying' => array(), 'links' => array());
		$movingRows = array();
		foreach ($before as $m) {
			$item = array('id' => (int) $m->rowid, 'subject' => (string) $m->subject, 'from' => (string) ($m->from_name ?: $m->from_email),
				'date' => (int) $this->db->jdate($m->date_mail ?: $m->date_received));
			if (isset($moving[(int) $m->rowid])) {
				$out['moving'][] = $item;
				$movingRows[] = $m;
			} else {
				$out['staying'][] = $item;
			}
		}
		$out['links'] = $this->threadLinksOf($movingRows);

		return $out;
	}

	/**
	 * Links made by the conversation on some mails
	 *
	 * @param object[] $rows Mails
	 * @return array<int,array{link:int,mail:int,type:string,element:int}>
	 */
	protected function threadLinksOf(array $rows)
	{
		$refOf = (new InboxMailRef($this->db))->forRows($rows);
		$mailOf = array();
		foreach ($refOf as $rowid => $ref) {
			$mailOf[$ref] = $mailOf[$ref] ?? (int) $rowid;
		}
		$out = array();
		foreach ((new InboxLink($this->db))->listFor(array_keys($mailOf)) as $l) {
			if ($l['origin'] === InboxThreadLink::ORIGIN) {
				$out[] = array('link' => $l['id'], 'mail' => $mailOf[$l['mailref']], 'type' => $l['type'], 'element' => $l['element']);
			}
		}

		return $out;
	}

	/**
	 * Start a new conversation from a mail
	 *
	 * @param User   $user User (level « handle » checked by the caller)
	 * @param object $row  Mail
	 * @param int[]  $keep Links of the old conversation kept on the mails that leave
	 * @return array<int,array{mail:int,type:string,element:int}>|null Links removed (for the undo), null KO
	 */
	public function split(User $user, $row, array $keep)
	{
		if ((string) ($row->message_id ?? '') === '') {
			$this->error = 'InboxErrorNoMessageId';
			return null;
		}
		$this->db->begin();
		if (!$this->apply($row, InboxThreader::ROOT_USER)) {
			$this->db->rollback();
			return null;
		}
		$now = $this->db->getRow("SELECT thread_key FROM ".$this->db->prefix()."inbox_mail WHERE rowid = ".((int) $row->rowid));
		$keep = array_flip(array_map('intval', $keep));
		$linker = new InboxLink($this->db);
		$removed = array();
		foreach ($this->threadLinksOf($this->mailsOf((int) $row->fk_account, is_object($now) ? (string) $now->thread_key : '')) as $l) {
			if (isset($keep[$l['link']])) {
				continue;
			}
			if ($linker->unlink($l['link']) < 0) {
				$this->error = $linker->error;
				$this->db->rollback();
				return null;
			}
			unset($l['link']);
			$removed[] = $l;
		}
		$this->db->commit();
		require_once __DIR__.'/inboxactionlog.class.php';
		InboxActionLog::add($this->db, $user, (int) $row->fk_account, 'thread_split', array($row));

		return $removed;
	}

	/**
	 * Join a mail back to the conversation it answers (undo of a cut, or a cut made because the subject changed)
	 *
	 * @param User                                          $user  User (level « handle » checked by the caller)
	 * @param object                                        $row   Mail
	 * @param array<int,array{mail:int,type:string,element:int}> $links Links given back (removed by the cut)
	 * @return int 1 OK, < 0 KO
	 */
	public function join(User $user, $row, array $links)
	{
		$this->db->begin();
		if (!$this->apply($row, InboxThreader::ROOT_JOINED)) {
			$this->db->rollback();
			return -1;
		}
		$this->db->commit();
		$now = $this->db->getRow("SELECT rowid, fk_account, thread_key FROM ".$this->db->prefix()."inbox_mail WHERE rowid = ".((int) $row->rowid));
		$mails = array();
		foreach (is_object($now) ? $this->mailsOf((int) $now->fk_account, (string) $now->thread_key) : array() as $m) {
			$mails[(int) $m->rowid] = $m;
		}
		$refs = new InboxMailRef($this->db);
		$linker = new InboxLink($this->db);
		foreach (array_slice($links, 0, 500) as $l) {
			$mail = $mails[(int) ($l['mail'] ?? 0)] ?? null;
			$type = (string) ($l['type'] ?? '');
			$id = (int) ($l['element'] ?? 0);
			// Only on mails of the conversation, pieces the user may read (the undo comes from the browser)
			if ($mail !== null && $type !== '' && $id > 0 && InboxElements::canRead($user, $type, $id)) {
				$ref = $refs->fetchOrCreate($mail, $user);
				if ($ref > 0) {
					$linker->link($ref, $type, $id, null, InboxThreadLink::ORIGIN);
				}
			}
		}
		require_once __DIR__.'/inboxactionlog.class.php';
		InboxActionLog::add($this->db, $user, (int) $row->fk_account, 'thread_join', array($row));

		return 1;
	}
}
