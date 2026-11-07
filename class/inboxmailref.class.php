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
	 * Key of a mail: its Message-ID, or for a mail without one (some automats), a print of its date, sender, subject
	 * and size, written « fp:<sha1> » (webmail §5.7 bis). '' when the row lacks what the print needs.
	 *
	 * @param object $row Row of the index (message_id; else date_mail, from_email, subject, size)
	 * @return string
	 */
	public function keyOf($row)
	{
		if ((string) ($row->message_id ?? '') !== '') {
			return (string) $row->message_id;
		}
		if (!property_exists($row, 'subject') || !property_exists($row, 'size')) {
			return '';
		}

		return 'fp:'.sha1($this->printOf($row->date_mail ?? null, $row->from_email ?? '').'|'.trim((string) $row->subject).'|'.((int) $row->size));
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
		$keys = array();
		foreach ($rows as $r) {
			$k = $this->keyOf($r);
			$keys[(int) $r->rowid] = $k;
			if ($k !== '') {
				$ids[$k] = "'".$this->db->escape($k)."'";
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
			foreach ($refs[$keys[(int) $r->rowid]] ?? array() as $f) {
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

		$key = $this->keyOf($row);
		if ($key === '') {
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
		$sql = "INSERT INTO ".$this->db->prefix()."inbox_mailref (entity, message_id, check_print, subject, from_email, date_mail, origin, last_account, date_creation) VALUES (".((int) $conf->entity).","
			." '".$this->db->escape($key)."', '".$this->printOf($row->date_mail, $row->from_email)."', ".$s($row->subject ?? '').", ".$s($row->from_email ?? '').","
			." ".$date.", 'inbox', ".((int) ($row->fk_account ?? 0) ?: 'NULL').", '".$this->db->idate(dol_now())."')";
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$id = (int) $this->db->last_insert_id($this->db->prefix().'inbox_mailref');
		if ($user instanceof User) {
			$this->trigger($user, (int) $row->fk_account, array('mailref' => $id, 'message_id' => $key));
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
		$ref = $this->db->getRow("SELECT message_id, check_print, from_email, date_mail FROM ".$p."inbox_mailref WHERE rowid = ".((int) $mailrefId));
		if (!is_object($ref) || (string) $ref->message_id === '') {
			return array();
		}
		$fallback = strpos((string) $ref->message_id, 'fp:') === 0;
		$where = $fallback ? "(m.message_id IS NULL OR m.message_id = '') AND m.from_email = '".$this->db->escape((string) $ref->from_email)."'"
			.($ref->date_mail ? " AND m.date_mail = '".$this->db->escape((string) $ref->date_mail)."'" : " AND m.date_mail IS NULL")
			: "m.message_id = '".$this->db->escape((string) $ref->message_id)."'";
		$rows = $this->db->getRows("SELECT m.rowid, m.fk_account, m.fk_folder, m.message_id, m.date_mail, m.from_email, m.subject, m.size, f.role, f.path FROM ".$p."inbox_mail as m"
			." INNER JOIN ".$p."inbox_folder as f ON f.rowid = m.fk_folder AND f.status = 1 WHERE ".$where." LIMIT 500");
		$good = array();
		$bad = array();
		foreach (is_array($rows) ? $rows : array() as $r) {
			if ($fallback ? $this->keyOf($r) !== (string) $ref->message_id
				: ((string) $ref->check_print !== '' && (string) $ref->check_print !== $this->printOf($r->date_mail, $r->from_email))) {
				continue;
			}
			$c = array('mail' => (int) $r->rowid, 'folder' => (int) $r->fk_folder, 'account' => (int) $r->fk_account, 'path' => (string) $r->path);
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
	 * Mailref of a Message-ID given by another module (a link or a note made elsewhere), created if missing; the check
	 * print stays empty (« to complete ») until the index sees the mail
	 *
	 * @param string              $messageId Message-ID, with or without < >
	 * @param array<string,mixed> $data      subject, from_email, date_mail (timestamp), origin, last_account
	 * @return int Mailref, < 0 if KO
	 */
	public function fetchOrCreateByMessageId($messageId, array $data = array())
	{
		global $conf;

		$messageId = trim((string) $messageId, " \t<>");
		if ($messageId === '' || strlen($messageId) > 255) {
			$this->error = 'Bad Message-ID';
			return -1;
		}
		$p = $this->db->prefix();
		$row = $this->db->getRow("SELECT rowid FROM ".$p."inbox_mailref WHERE entity = ".((int) $conf->entity)." AND message_id = '".$this->db->escape($messageId)."' ORDER BY rowid");
		if (is_object($row)) {
			return (int) $row->rowid;
		}
		$s = function ($v, $len = 255) {
			$v = (string) $v;
			return $v === '' ? 'NULL' : "'".$this->db->escape(dol_trunc($v, $len, 'right', 'UTF-8', 1))."'";
		};
		$sql = "INSERT INTO ".$p."inbox_mailref (entity, message_id, check_print, subject, from_email, date_mail, origin, last_account, date_creation) VALUES (".((int) $conf->entity).","
			." '".$this->db->escape($messageId)."', NULL, ".$s($data['subject'] ?? '').", ".$s(strtolower((string) ($data['from_email'] ?? ''))).","
			." ".(!empty($data['date_mail']) ? "'".$this->db->idate((int) $data['date_mail'])."'" : 'NULL').", ".$s($data['origin'] ?? '', 32).","
			." ".((int) ($data['last_account'] ?? 0) ?: 'NULL').", '".$this->db->idate(dol_now())."')";
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}

		return (int) $this->db->last_insert_id($p.'inbox_mailref');
	}

	/**
	 * Best level of a user on the mailboxes where the mail is (none when the mail is in no mailbox the user can read)
	 *
	 * @param User $user      User
	 * @param int  $mailrefId Mailref
	 * @return string '', read, send or manage
	 */
	public function levelOf(User $user, $mailrefId)
	{
		require_once __DIR__.'/inboxaccess.class.php';
		$access = new InboxAccess($this->db);
		$best = '';
		foreach ($this->copies((int) $mailrefId) as $c) {
			$level = $access->getLevel($user, $c['account']);
			if (InboxAccess::rank($level) > InboxAccess::rank($best)) {
				$best = $level;
			}
		}

		return $best;
	}

	/**
	 * Remember where the mail was seen (shown when it is not found any more)
	 *
	 * @param int    $mailrefId Mailref
	 * @param int    $accountId Mailbox
	 * @param string $folder    Folder path
	 * @return void
	 */
	public function seenAt($mailrefId, $accountId, $folder)
	{
		$this->db->query("UPDATE ".$this->db->prefix()."inbox_mailref SET last_account = ".((int) $accountId).", last_folder = '".$this->db->escape(dol_trunc((string) $folder, 250, 'right', 'UTF-8', 1))."'"
			." WHERE rowid = ".((int) $mailrefId)." AND (last_account IS NULL OR last_account <> ".((int) $accountId)." OR last_folder IS NULL OR last_folder <> '".$this->db->escape((string) $folder)."')");
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
