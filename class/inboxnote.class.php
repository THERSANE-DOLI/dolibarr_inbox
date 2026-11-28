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
 * \file    inbox/class/inboxnote.class.php
 * \ingroup inbox
 * \brief   Internal notes on a mail (1.13.0), public for the other modules
 */

require_once __DIR__.'/inboxmailref.class.php';
require_once __DIR__.'/inboxaccess.class.php';

/**
 * Plain text, seen by everyone who can read a mailbox where the mail is. Changed or removed by its author, or by a
 * person who manages one of these mailboxes. Public (P9): a module writes with its own origin, date and author
 * (recovery of notes made elsewhere). Triggers INBOX_NOTE_CREATE / _MODIFY / _DELETE unless $notrigger.
 */
class InboxNote
{
	/** Longest note */
	const MAX = 10000;

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
	 * Add a note
	 *
	 * @param int                 $mailrefId Mailref
	 * @param string              $text      Text
	 * @param User|null           $user      Author (checked: access to the mail); null = a module (author in $options)
	 * @param array<string,mixed> $options   origin, author (user id when $user is null), date (timestamp), notrigger
	 * @return int Note, < 0 if KO
	 */
	public function add($mailrefId, $text, $user = null, array $options = array())
	{
		global $conf;

		$text = $this->clean($text);
		if ((int) $mailrefId <= 0 || $text === '') {
			$this->error = 'InboxErrorNoteEmpty';
			return -1;
		}
		if ($user instanceof User && (new InboxMailRef($this->db))->levelOf($user, (int) $mailrefId) === '') {
			$this->error = 'InboxMailNotFound';
			return -1;
		}
		$author = $user instanceof User ? (int) $user->id : (int) ($options['author'] ?? 0);
		$date = (int) ($options['date'] ?? 0) ?: dol_now();
		$p = $this->db->prefix();
		$sql = "INSERT INTO ".$p."inbox_note (entity, fk_mailref, note, origin, fk_user_author, date_creation) VALUES (".((int) $conf->entity).", ".((int) $mailrefId).","
			." '".$this->db->escape($text)."', '".$this->db->escape(substr((string) ($options['origin'] ?? 'inbox'), 0, 32))."', ".($author ?: 'NULL').", '".$this->db->idate($date)."')";
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$id = (int) $this->db->last_insert_id($p.'inbox_note');
		if (empty($options['notrigger'])) {
			$this->trigger('INBOX_NOTE_CREATE', $user, array('note' => $id, 'mailref' => (int) $mailrefId, 'origin' => (string) ($options['origin'] ?? 'inbox')));
		}

		return $id;
	}

	/**
	 * Change a note (its author, or a manager of a mailbox of the mail)
	 *
	 * @param int       $noteId Note
	 * @param string    $text   Text
	 * @param User|null $user   User; null = a module
	 * @return int 1 if OK, < 0 if KO
	 */
	public function update($noteId, $text, $user = null)
	{
		$n = $this->allowed($noteId, $user);
		if (!$n) {
			return -1;
		}
		$text = $this->clean($text);
		if ($text === '') {
			$this->error = 'InboxErrorNoteEmpty';
			return -1;
		}
		if (!$this->db->query("UPDATE ".$this->db->prefix()."inbox_note SET note = '".$this->db->escape($text)."', fk_user_modif = ".($user instanceof User ? (int) $user->id : 'NULL')
			." WHERE rowid = ".((int) $n->rowid))) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$this->trigger('INBOX_NOTE_MODIFY', $user, array('note' => (int) $n->rowid, 'mailref' => (int) $n->fk_mailref));

		return 1;
	}

	/**
	 * Remove a note (its author, or a manager of a mailbox of the mail)
	 *
	 * @param int       $noteId    Note
	 * @param User|null $user      User; null = a module
	 * @param bool      $notrigger No trigger
	 * @return int 1 if OK, < 0 if KO
	 */
	public function delete($noteId, $user = null, $notrigger = false)
	{
		$n = $this->allowed($noteId, $user);
		if (!$n) {
			return -1;
		}
		if (!$this->db->query("DELETE FROM ".$this->db->prefix()."inbox_note WHERE rowid = ".((int) $n->rowid))) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		if (!$notrigger) {
			$this->trigger('INBOX_NOTE_DELETE', $user, array('note' => (int) $n->rowid, 'mailref' => (int) $n->fk_mailref));
		}

		return 1;
	}

	/**
	 * Notes of mails, the oldest first
	 *
	 * @param int[]     $mailrefIds Mailrefs
	 * @param User|null $user       User (to say which notes he may change)
	 * @return array<int,array{id:int,mailref:int,text:string,author:int,author_name:string,date:int,edited:bool,mine:bool,origin:string}>
	 */
	public function listFor(array $mailrefIds, $user = null)
	{
		$mailrefIds = array_filter(array_map('intval', $mailrefIds));
		if (!$mailrefIds) {
			return array();
		}
		$p = $this->db->prefix();
		$rows = $this->db->getRows("SELECT n.rowid, n.fk_mailref, n.note, n.origin, n.fk_user_author, n.date_creation, n.fk_user_modif, u.firstname, u.lastname, u.login, u.photo, u.entity FROM ".$p."inbox_note as n"
			." LEFT JOIN ".$p."user as u ON u.rowid = n.fk_user_author WHERE n.fk_mailref IN (".implode(',', $mailrefIds).") ORDER BY n.date_creation, n.rowid LIMIT 1000");
		$out = array();
		foreach (is_array($rows) ? $rows : array() as $r) {
			$name = trim($r->firstname.' '.$r->lastname);
			$out[] = array('id' => (int) $r->rowid, 'mailref' => (int) $r->fk_mailref, 'text' => (string) $r->note, 'author' => (int) $r->fk_user_author,
				'author_name' => $name !== '' ? $name : (string) $r->login, 'date' => (int) $this->db->jdate($r->date_creation), 'edited' => (int) $r->fk_user_modif > 0,
				'mine' => $user instanceof User && (int) $r->fk_user_author === (int) $user->id, 'origin' => (string) $r->origin,
				'photo' => $this->photoUrl((int) $r->fk_user_author, (string) $r->photo, (int) $r->entity));
		}

		return $out;
	}

	/**
	 * Address of the small photo of a user ('' when none): the same file the cards of Dolibarr show
	 *
	 * @param int    $userId User
	 * @param string $photo  File name of the photo
	 * @param int    $entity Entity of the user
	 * @return string
	 */
	protected function photoUrl($userId, $photo, $entity)
	{
		return InboxAccess::userPhotoUrl($userId, $photo, $entity);
	}

	/**
	 * The note, when the user may change it
	 *
	 * @param int       $noteId Note
	 * @param User|null $user   User; null = a module (always)
	 * @return object|null
	 */
	protected function allowed($noteId, $user)
	{
		$n = $this->db->getRow("SELECT rowid, fk_mailref, fk_user_author FROM ".$this->db->prefix()."inbox_note WHERE rowid = ".((int) $noteId));
		if (!is_object($n)) {
			$this->error = 'InboxErrorNoteNotFound';
			return null;
		}
		if ($user instanceof User) {
			$level = (new InboxMailRef($this->db))->levelOf($user, (int) $n->fk_mailref);
			if ($level === '') {
				$this->error = 'InboxErrorNoteNotFound';
				return null;
			}
			// A note follows the mail in every copy (decided): read by all who see a copy, changed by its author only
			// (audit INBX-006)
			if ((int) $n->fk_user_author !== (int) $user->id) {
				$this->error = 'InboxErrorNoteNotYours';
				return null;
			}
		}

		return $n;
	}

	/**
	 * @param string $text Text
	 * @return string Plain text, trimmed, without control characters, cut to the longest note
	 */
	protected function clean($text)
	{
		$text = str_replace("\r\n", "\n", (string) $text);
		$text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);

		return trim(dol_trunc((string) $text, self::MAX, 'right', 'UTF-8', 1));
	}

	/**
	 * @param string              $code Trigger
	 * @param User|null           $user User
	 * @param array<string,mixed> $data Data
	 * @return void
	 */
	protected function trigger($code, $user, array $data)
	{
		require_once __DIR__.'/inboxaccount.class.php';
		$author = $user instanceof User ? $user : ($GLOBALS['user'] ?? null);
		if (!$author instanceof User) {
			return;
		}
		$account = new InboxAccount($this->db);
		$row = $this->db->getRow("SELECT last_account FROM ".$this->db->prefix()."inbox_mailref WHERE rowid = ".((int) $data['mailref']));
		if (is_object($row) && (int) $row->last_account > 0) {
			$account->fetch((int) $row->last_account);
		}
		$account->context['inbox'] = $data;
		$account->call_trigger($code, $author);
	}
}
