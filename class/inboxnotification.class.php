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
 * \file    inbox/class/inboxnotification.class.php
 * \ingroup inbox
 * \brief   Notification centre of inbox, the bell (webmail §5.4 sexies)
 */

require_once __DIR__.'/inboxmailref.class.php';

/**
 * One line per notification of a user. Other modules add theirs with add() (P9: they plug into inbox). The payload
 * is short: nothing of a mail beyond its subject.
 */
class InboxNotification
{
	/** Same notification again within this time (s): the line is refreshed instead of a new one */
	const MERGE_DELAY = 60;

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
	 * Add a notification (public: other modules call it)
	 *
	 * @param int                 $fkUser  User told
	 * @param string              $type    assign, box_error... or the type of another module (a-z, 0-9, _)
	 * @param array<string,mixed> $options mailref (int), account (int), actor (int, user who did it), payload (array: subject, label...), url (internal address of inbox, '#/...')
	 * @return int Id of the line, < 0 if KO
	 */
	public function add($fkUser, $type, array $options = array())
	{
		global $conf;

		$type = substr(preg_replace('/[^a-z0-9_]/', '', strtolower((string) $type)), 0, 32);
		if ((int) $fkUser <= 0 || $type === '') {
			$this->error = 'Bad parameters';
			return -1;
		}
		$p = $this->db->prefix();
		$mailref = (int) ($options['mailref'] ?? 0);
		$account = (int) ($options['account'] ?? 0);
		$actor = (int) ($options['actor'] ?? 0);
		$url = (string) ($options['url'] ?? '');
		if ($url !== '' && strpos($url, '#/') !== 0) {
			// Inside inbox only: never an outside address in the bell
			$url = '';
		}
		$payload = json_encode(is_array($options['payload'] ?? null) ? $options['payload'] : array());
		$now = dol_now();
		$same = " WHERE entity = ".((int) $conf->entity)." AND fk_user = ".((int) $fkUser)." AND type = '".$this->db->escape($type)."'"
			." AND ".($mailref ? "fk_mailref = ".$mailref : "fk_mailref IS NULL")." AND ".($actor ? "fk_user_actor = ".$actor : "fk_user_actor IS NULL")
			." AND date_creation > '".$this->db->idate($now - self::MERGE_DELAY)."'";
		$old = $this->db->getRow("SELECT rowid FROM ".$p."inbox_notification".$same." ORDER BY rowid DESC");
		if (is_object($old)) {
			$this->db->query("UPDATE ".$p."inbox_notification SET date_creation = '".$this->db->idate($now)."', date_read = NULL, payload = '".$this->db->escape((string) $payload)."' WHERE rowid = ".((int) $old->rowid));
			return (int) $old->rowid;
		}
		$sql = "INSERT INTO ".$p."inbox_notification (entity, fk_user, type, fk_mailref, fk_account, fk_user_actor, payload, url, date_creation) VALUES (".((int) $conf->entity).","
			." ".((int) $fkUser).", '".$this->db->escape($type)."', ".($mailref ?: 'NULL').", ".($account ?: 'NULL').", ".($actor ?: 'NULL').","
			." '".$this->db->escape((string) $payload)."', ".($url !== '' ? "'".$this->db->escape(substr($url, 0, 255))."'" : 'NULL').", '".$this->db->idate($now)."')";
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}

		return (int) $this->db->last_insert_id($p.'inbox_notification');
	}

	/**
	 * Remove a notification still unread made a moment ago (assigned then removed at once: nothing to tell)
	 *
	 * @param int    $fkUser  User told
	 * @param string $type    Type
	 * @param int    $mailref Mailref
	 * @param int    $actor   User who did it
	 * @return void
	 */
	public function cancel($fkUser, $type, $mailref, $actor)
	{
		global $conf;

		$this->db->query("DELETE FROM ".$this->db->prefix()."inbox_notification WHERE entity = ".((int) $conf->entity)." AND fk_user = ".((int) $fkUser)
			." AND type = '".$this->db->escape((string) $type)."' AND fk_mailref = ".((int) $mailref)." AND fk_user_actor = ".((int) $actor)
			." AND date_read IS NULL AND date_creation > '".$this->db->idate(dol_now() - self::MERGE_DELAY)."'");
	}

	/**
	 * @param User $user User
	 * @return array{unread:int,last:int} Unread notifications and the last id (a new one = another last)
	 */
	public function counts(User $user)
	{
		global $conf;

		$row = $this->db->getRow("SELECT SUM(CASE WHEN date_read IS NULL THEN 1 ELSE 0 END) as n, MAX(rowid) as last FROM ".$this->db->prefix()."inbox_notification"
			." WHERE entity = ".((int) $conf->entity)." AND fk_user = ".((int) $user->id));

		return array('unread' => is_object($row) ? (int) $row->n : 0, 'last' => is_object($row) ? (int) $row->last : 0);
	}

	/**
	 * Notifications of a user, the most recent first
	 *
	 * @param User $user   User
	 * @param int  $limit  Lines
	 * @param int  $before Older than this id (next page), 0 = the first page
	 * @return array{items:array<int,array<string,mixed>>,more:bool}
	 */
	public function listFor(User $user, $limit = 50, $before = 0)
	{
		global $conf;

		$p = $this->db->prefix();
		$limit = max(1, min(100, (int) $limit));
		$rows = $this->db->getRows("SELECT n.rowid, n.type, n.fk_mailref, n.fk_account, n.fk_user_actor, n.payload, n.url, n.date_creation, n.date_read,"
			." u.firstname, u.lastname, u.login, a.label as account_label, a.email as account_email FROM ".$p."inbox_notification as n"
			." LEFT JOIN ".$p."user as u ON u.rowid = n.fk_user_actor LEFT JOIN ".$p."inbox_account as a ON a.rowid = n.fk_account"
			." WHERE n.entity = ".((int) $conf->entity)." AND n.fk_user = ".((int) $user->id).($before > 0 ? " AND n.rowid < ".((int) $before) : '')
			." ORDER BY n.rowid DESC LIMIT ".($limit + 1));
		$items = array();
		foreach (is_array($rows) ? $rows : array() as $r) {
			$payload = json_decode((string) $r->payload, true);
			$actor = trim($r->firstname.' '.$r->lastname);
			$items[] = array(
				'id' => (int) $r->rowid,
				'type' => (string) $r->type,
				'actor' => (int) $r->fk_user_actor ? array('id' => (int) $r->fk_user_actor, 'name' => $actor !== '' ? $actor : (string) $r->login) : null,
				'subject' => is_array($payload) ? (string) ($payload['subject'] ?? '') : '',
				'text' => is_array($payload) ? (string) ($payload['text'] ?? '') : '',
				'account' => (string) ($r->account_email ?: $r->account_label),
				'date' => (int) $this->db->jdate($r->date_creation),
				'read' => $r->date_read ? 1 : 0,
				'mailref' => (int) $r->fk_mailref,
				'url' => (string) $r->url,
			);
		}
		$more = count($items) > $limit;

		return array('items' => array_slice($items, 0, $limit), 'more' => $more);
	}

	/**
	 * Mark notifications read
	 *
	 * @param User  $user User
	 * @param int[] $ids  Lines, empty = all of them
	 * @return void
	 */
	public function markRead(User $user, array $ids = array())
	{
		global $conf;

		$ids = array_filter(array_map('intval', $ids));
		$this->db->query("UPDATE ".$this->db->prefix()."inbox_notification SET date_read = '".$this->db->idate(dol_now())."' WHERE entity = ".((int) $conf->entity)
			." AND fk_user = ".((int) $user->id)." AND date_read IS NULL".($ids ? " AND rowid IN (".implode(',', $ids).")" : ''));
	}

	/**
	 * Where a notification leads, marked read: the mail where it is now (found by its mailref), or its address
	 *
	 * @param User  $user     User
	 * @param int   $id       Notification
	 * @param int[] $readable Mailboxes the user may read
	 * @return array{mail?:int,folder?:int,url?:string,error?:string} error: not_found, gone (mail no more on the server), no_access
	 */
	public function target(User $user, $id, array $readable)
	{
		global $conf;

		$n = $this->db->getRow("SELECT rowid, fk_mailref, url FROM ".$this->db->prefix()."inbox_notification WHERE rowid = ".((int) $id)
			." AND entity = ".((int) $conf->entity)." AND fk_user = ".((int) $user->id));
		if (!is_object($n)) {
			return array('error' => 'not_found');
		}
		$this->markRead($user, array((int) $n->rowid));
		if ((int) $n->fk_mailref > 0) {
			$ref = new InboxMailRef($this->db);
			$where = $ref->locate((int) $n->fk_mailref, $readable);
			if ($where) {
				return array('mail' => $where['mail'], 'folder' => $where['folder']);
			}
			// Still in the index, but in mailboxes the user cannot read any more: nothing of the mail is said
			return array('error' => $ref->copies((int) $n->fk_mailref) ? 'no_access' : 'gone');
		}

		return (string) $n->url !== '' ? array('url' => (string) $n->url) : array('error' => 'not_found');
	}

	/**
	 * Remove the notifications older than the retention (INBOX_NOTIFICATION_DAYS, 90 by default; 0 = kept)
	 *
	 * @param DoliDB $db Database handler
	 * @return int Lines removed, < 0 if KO
	 */
	public static function purge(DoliDB $db)
	{
		$days = getDolGlobalString('INBOX_NOTIFICATION_DAYS') === '' ? 90 : getDolGlobalInt('INBOX_NOTIFICATION_DAYS');
		if ($days <= 0) {
			return 0;
		}
		if (!$db->query("DELETE FROM ".$db->prefix()."inbox_notification WHERE date_creation < '".$db->idate(dol_now() - $days * 86400)."'")) {
			return -1;
		}

		return (int) $db->affected_rows(true);
	}
}
