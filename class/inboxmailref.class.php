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
 * \file    inbox/class/inboxmailref.class.php
 * \ingroup inbox
 * \brief   A mail for Dolibarr whatever its place (webmail §5.7 bis): assignments now, links and notes in lot W5
 */

/**
 * The key is the Message-ID alone, never the mailbox, the folder or the UID: a move keeps it. The check print (date of
 * sending + sender) tells apart two mails with the same Message-ID (an id reused by a sender). Where the mail is now
 * is never stored: it is looked for in the index, which follows the moves made in inbox and elsewhere.
 */
class InboxMailRef
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
	 * Check print of a mail: date of sending and sender (the same in every copy of the mail)
	 *
	 * @param int|string|null $dateMail Date of sending (timestamp, or date of the database)
	 * @param string|null     $from     Address of the sender
	 * @return string
	 */
	public function printOf($dateMail, $from)
	{
		$ts = is_numeric($dateMail) ? (int) $dateMail : ((string) $dateMail !== '' ? (int) $this->db->jdate($dateMail) : 0);

		return sha1($ts.'|'.strtolower(trim((string) $from)));
	}

	/**
	 * Mailrefs of a list of rows of the index, without creating any
	 *
	 * @param object[] $rows Rows (rowid, message_id, date_mail, from_email)
	 * @return array<int,int> Row of the index => mailref, for the rows that have one
	 */
	public function forRows(array $rows)
	{
		global $conf;

		$ids = array();
		foreach ($rows as $r) {
			if ((string) $r->message_id !== '') {
				$ids[(string) $r->message_id] = "'".$this->db->escape((string) $r->message_id)."'";
			}
		}
		if (!$ids) {
			return array();
		}
		$refs = array();
		$found = $this->db->getRows("SELECT rowid, message_id, check_print FROM ".$this->db->prefix()."inbox_mailref WHERE entity = ".((int) $conf->entity)
			." AND message_id IN (".implode(',', $ids).") ORDER BY rowid LIMIT 100000");
		foreach (is_array($found) ? $found : array() as $f) {
			$refs[(string) $f->message_id][] = $f;
		}
		$out = array();
		foreach ($rows as $r) {
			foreach ($refs[(string) $r->message_id] ?? array() as $f) {
				if ((string) $f->check_print === '' || (string) $f->check_print === $this->printOf($r->date_mail, $r->from_email)) {
					$out[(int) $r->rowid] = (int) $f->rowid;
					break;
				}
			}
		}

		return $out;
	}

	/**
	 * Mailref of a row of the index, created if missing (trigger INBOX_MAILREF_CREATE)
	 *
	 * @param object    $row  Row (rowid, message_id, date_mail, from_email, subject)
	 * @param User|null $user User creating it (trigger)
	 * @return int Mailref, 0 when the mail has no Message-ID, < 0 if KO
	 */
	public function fetchOrCreate($row, $user = null)
	{
		global $conf;

		if ((string) $row->message_id === '') {
			$this->error = 'InboxErrorNoMessageId';
			return 0;
		}
		$known = $this->forRows(array($row));
		if (isset($known[(int) $row->rowid])) {
			return $known[(int) $row->rowid];
		}
		$s = function ($v, $len = 255) {
			$v = (string) $v;
			return $v === '' ? 'NULL' : "'".$this->db->escape(dol_trunc($v, $len, 'right', 'UTF-8', 1))."'";
		};
		$date = (string) $row->date_mail !== '' ? (is_numeric($row->date_mail) ? "'".$this->db->idate((int) $row->date_mail)."'" : "'".$this->db->escape((string) $row->date_mail)."'") : 'NULL';
		$sql = "INSERT INTO ".$this->db->prefix()."inbox_mailref (entity, message_id, check_print, subject, from_email, date_mail, date_creation) VALUES (".((int) $conf->entity).","
			." '".$this->db->escape((string) $row->message_id)."', '".$this->printOf($row->date_mail, $row->from_email)."', ".$s($row->subject ?? '').", ".$s($row->from_email ?? '').","
			." ".$date.", '".$this->db->idate(dol_now())."')";
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$id = (int) $this->db->last_insert_id($this->db->prefix().'inbox_mailref');
		if ($user instanceof User) {
			$this->trigger($user, (int) $row->fk_account, array('mailref' => $id, 'message_id' => (string) $row->message_id));
		}

		return $id;
	}

	/**
	 * Where a mail is now, among the mailboxes given: a copy outside the trash and the junk first
	 *
	 * @param int   $mailrefId  Mailref
	 * @param int[] $accountIds Mailboxes to look in (the ones the user can read)
	 * @return array{mail:int,folder:int,account:int}|null Null when not in the index of these mailboxes
	 */
	public function locate($mailrefId, array $accountIds)
	{
		$all = $this->copies($mailrefId);
		$accountIds = array_map('intval', $accountIds);
		foreach ($all as $c) {
			if (in_array($c['account'], $accountIds, true)) {
				return $c;
			}
		}

		return null;
	}

	/**
	 * Every copy of the mail in the index, whatever the mailbox (a copy outside the trash and the junk first)
	 *
	 * @param int $mailrefId Mailref
	 * @return array<int,array{mail:int,folder:int,account:int}>
	 */
	public function copies($mailrefId)
	{
		$p = $this->db->prefix();
		$ref = $this->db->getRow("SELECT message_id, check_print FROM ".$p."inbox_mailref WHERE rowid = ".((int) $mailrefId));
		if (!is_object($ref) || (string) $ref->message_id === '') {
			return array();
		}
		$rows = $this->db->getRows("SELECT m.rowid, m.fk_account, m.fk_folder, m.date_mail, m.from_email, f.role FROM ".$p."inbox_mail as m"
			." INNER JOIN ".$p."inbox_folder as f ON f.rowid = m.fk_folder AND f.status = 1 WHERE m.message_id = '".$this->db->escape((string) $ref->message_id)."' LIMIT 500");
		$good = array();
		$bad = array();
		foreach (is_array($rows) ? $rows : array() as $r) {
			if ((string) $ref->check_print !== '' && (string) $ref->check_print !== $this->printOf($r->date_mail, $r->from_email)) {
				continue;
			}
			$c = array('mail' => (int) $r->rowid, 'folder' => (int) $r->fk_folder, 'account' => (int) $r->fk_account);
			if (in_array((string) $r->role, array('trash', 'junk'), true)) {
				$bad[] = $c;
			} elseif ((string) $r->role === 'inbox') {
				array_unshift($good, $c);
			} else {
				$good[] = $c;
			}
		}

		return array_merge($good, $bad);
	}

	/**
	 * @param User                $user      User
	 * @param int                 $accountId Mailbox of the copy used
	 * @param array<string,mixed> $data      Data given to the trigger (context['inbox'] of the mailbox)
	 * @return void
	 */
	protected function trigger(User $user, $accountId, array $data)
	{
		require_once __DIR__.'/inboxaccount.class.php';
		$account = new InboxAccount($this->db);
		if ($account->fetch((int) $accountId) > 0) {
			$account->context['inbox'] = $data;
			$account->call_trigger('INBOX_MAILREF_CREATE', $user);
		}
	}
}
