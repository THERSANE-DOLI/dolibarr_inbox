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
 * \file    inbox/class/inboxactivity.class.php
 * \ingroup inbox
 * \brief   Activity of a mail (lot E2, 1.77.0): the answers sent from the mailbox in its conversation, beside the
 *          internal notes; before a sending, an answer sent to the same mail since the writing was opened
 */

/**
 * Only what is sure: the mails of the Sent folder of the mailbox tied to the mail by In-Reply-To / References or by
 * its conversation (1.5.0). Their author is known when inbox sent them (journal of the mailbox, action « send »,
 * kept 12 months); otherwise the mail went from another software. Forwards are left out: nothing ties them to the
 * mail they come from (no In-Reply-To on purpose, a subject is not a proof).
 */
class InboxActivity
{
	/** Answers shown in the thread of a mail */
	const MAX = 50;

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
	 * The mails sent from the mailbox in the conversation of a mail (the mail itself left out)
	 *
	 * @param object $row Mail of the index (rowid, fk_account, message_id, thread_key)
	 * @return array<int,array{mail:int,folder:int,date:int,subject:string,to:string,reply:bool,author:int,author_name:string,by_inbox:bool}>
	 *         Oldest first
	 */
	public function sentIn($row)
	{
		$p = $this->db->prefix();
		$msgid = (string) ($row->message_id ?? '');
		$or = array();
		if ((string) ($row->thread_key ?? '') !== '') {
			$or[] = "m.thread_key = '".$this->db->escape((string) $row->thread_key)."'";
		}
		if ($msgid !== '') {
			$or[] = "m.in_reply_to = '".$this->db->escape($msgid)."'";
		}
		if (!$or) {
			return array();
		}
		$rows = $this->db->getRows("SELECT m.rowid, m.fk_folder, m.message_id, m.in_reply_to, m.subject, m.to_text, m.date_mail, m.date_received FROM ".$p."inbox_mail as m"
			." INNER JOIN ".$p."inbox_folder as f ON f.rowid = m.fk_folder AND f.role = 'sent'"
			." WHERE m.fk_account = ".((int) $row->fk_account)." AND (".implode(' OR ', $or).") AND m.rowid <> ".((int) $row->rowid)
			.($msgid !== '' ? " AND (m.message_id IS NULL OR m.message_id <> '".$this->db->escape($msgid)."')" : "")
			." ORDER BY COALESCE(m.date_mail, m.date_received), m.rowid LIMIT ".self::MAX);
		$out = array();
		$seen = array();
		foreach (is_array($rows) ? $rows : array() as $r) {
			$k = (string) $r->message_id !== '' ? (string) $r->message_id : 'row'.$r->rowid;
			if (isset($seen[$k])) {
				continue;
			}
			$seen[$k] = true;
			$date = $r->date_mail ? $r->date_mail : $r->date_received;
			$out[] = array('mail' => (int) $r->rowid, 'folder' => (int) $r->fk_folder, 'date' => $date ? (int) $this->db->jdate($date) : 0, 'subject' => (string) $r->subject,
				'to' => dol_trunc((string) $r->to_text, 120, 'right', 'UTF-8', 1), 'reply' => (string) $r->in_reply_to !== '', 'message_id' => (string) $r->message_id,
				'author' => 0, 'author_name' => '', 'by_inbox' => false);
		}

		return $this->authors((int) $row->fk_account, $out);
	}

	/**
	 * Authors of mails sent by inbox: the journal of the mailbox (action « send »)
	 *
	 * @param int                            $accountId Mailbox
	 * @param array<int,array<string,mixed>> $list      Mails (message_id)
	 * @return array<int,array<string,mixed>>
	 */
	protected function authors($accountId, array $list)
	{
		$ids = array_filter(array_map(function ($x) {
			return (string) $x['message_id'] !== '' ? "'".$this->db->escape((string) $x['message_id'])."'" : '';
		}, $list));
		if (!$ids) {
			return $list;
		}
		$p = $this->db->prefix();
		$rows = $this->db->getRows("SELECT l.message_id, l.fk_user, l.user_name, u.firstname, u.lastname, u.login FROM ".$p."inbox_action_log as l LEFT JOIN ".$p."user as u ON u.rowid = l.fk_user"
			." WHERE l.action = 'send' AND l.fk_account = ".((int) $accountId)." AND l.message_id IN (".implode(',', array_unique($ids)).") LIMIT ".(count($ids) * 2));
		$by = array();
		foreach (is_array($rows) ? $rows : array() as $r) {
			$name = trim($r->firstname.' '.$r->lastname);
			$by[(string) $r->message_id] = array((int) $r->fk_user, $name !== '' ? $name : ((string) $r->login !== '' ? (string) $r->login : (string) $r->user_name));
		}
		foreach ($list as $i => $x) {
			if (isset($by[$x['message_id']])) {
				$list[$i]['author'] = $by[$x['message_id']][0];
				$list[$i]['author_name'] = $by[$x['message_id']][1];
				$list[$i]['by_inbox'] = true;
			}
		}

		return $list;
	}

	/**
	 * Before a sending: an answer to the same mail that left the mailbox since the writing was opened, by inbox (queue
	 * of the sending, even not sent yet) or seen in the Sent folder (another software, after its synchronisation)
	 *
	 * @param int    $accountId Mailbox of the mail answered
	 * @param string $messageId Message-ID of the mail answered
	 * @param int    $since     Opening of the writing
	 * @param string $token     Token of this writing (its own sending left out)
	 * @return array{author_name:string,date:int,mail:int,folder:int,queued:bool}|null The last one, null when none
	 */
	public function answeredSince($accountId, $messageId, $since, $token)
	{
		if ((string) $messageId === '' || $since <= 0) {
			return null;
		}
		$p = $this->db->prefix();
		$found = null;
		// Sent by inbox (another user, another window): the queue keeps the mail answered with the sending
		$rows = $this->db->getRows("SELECT o.rowid, o.fk_user, o.message_id, o.actions, o.status, o.date_creation, u.firstname, u.lastname, u.login FROM ".$p."inbox_outbox as o"
			." LEFT JOIN ".$p."user as u ON u.rowid = o.fk_user WHERE o.date_creation > '".$this->db->idate($since)."' AND o.status IN (0, 1, 2)"
			." AND (o.compose_token IS NULL OR o.compose_token <> '".$this->db->escape((string) $token)."') AND o.actions LIKE '%".$this->db->escape($this->db->escapeforlike(json_encode((string) $messageId)))."%'"
			." ORDER BY o.date_creation DESC LIMIT 20");
		foreach (is_array($rows) ? $rows : array() as $r) {
			$a = json_decode((string) $r->actions, true);
			$src = is_array($a) ? ($a['source'] ?? null) : null;
			if (!is_array($src) || (string) ($src['message_id'] ?? '') !== (string) $messageId || !in_array((string) ($src['mode'] ?? ''), array('reply', 'replyall', 'replylist'), true)) {
				continue;
			}
			$name = trim($r->firstname.' '.$r->lastname);
			$mail = $this->db->getRow("SELECT m.rowid, m.fk_folder FROM ".$p."inbox_mail as m WHERE m.fk_account = ".((int) $accountId)." AND m.message_id = '".$this->db->escape((string) $r->message_id)."' ORDER BY m.rowid DESC");
			$found = array('author_name' => $name !== '' ? $name : (string) $r->login, 'date' => (int) $this->db->jdate($r->date_creation), 'mail' => is_object($mail) ? (int) $mail->rowid : 0,
				'folder' => is_object($mail) ? (int) $mail->fk_folder : 0, 'queued' => (int) $r->status !== 2);
			break;
		}
		if ($found) {
			return $found;
		}
		// Sent from another software: in the index once the Sent folder is read again
		$r = $this->db->getRow("SELECT m.rowid, m.fk_folder, m.date_mail, m.date_received FROM ".$p."inbox_mail as m INNER JOIN ".$p."inbox_folder as f ON f.rowid = m.fk_folder AND f.role = 'sent'"
			." WHERE m.fk_account = ".((int) $accountId)." AND m.in_reply_to = '".$this->db->escape((string) $messageId)."'"
			." AND COALESCE(m.date_mail, m.date_received) > '".$this->db->idate($since)."' ORDER BY m.rowid DESC");
		if (is_object($r)) {
			$date = $r->date_mail ? $r->date_mail : $r->date_received;
			return array('author_name' => '', 'date' => (int) $this->db->jdate($date), 'mail' => (int) $r->rowid, 'folder' => (int) $r->fk_folder, 'queued' => false);
		}

		return null;
	}
}
