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
require_once __DIR__.'/inboxaccess.class.php';
require_once __DIR__.'/inboxaccount.class.php';
require_once __DIR__.'/inboxuserparam.class.php';

/**
 * One line per notification of a user. Other modules add theirs with add() (P9: they plug into inbox). The payload
 * is short: nothing of a mail beyond its subject.
 */
class InboxNotification
{
	/** Same notification again within this time (s): the line is refreshed instead of a new one */
	const MERGE_DELAY = 60;

	/** Types of inbox, listed in Settings > Notifications; the types of other modules follow the defaults */
	const TYPES = array('assign', 'send_failed', 'bounce', 'box_error', 'rule_redirect', 'access_self');

	/** A notification still unread after this time (s) goes in the summary by mail */
	const MAIL_AFTER = 900;

	/** A mailbox out of reach for this time (s) is told to its managers */
	const BOX_ERROR_AFTER = 900;

	/** New mails told by the browser: received in this time (s) only */
	const FRESH_WINDOW = 1800;

	/** @var DoliDB */
	protected $db;

	/** @var string */
	public $error = '';

	/** @var string Result of the scheduled job */
	public $output = '';

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
		// A notification about a mailbox the user cannot read any more: still listed, without its subject (audit INBX-018)
		$access = new InboxAccess($this->db);
		$levels = $access->getLevels($user);
		foreach (is_array($rows) ? $rows : array() as $r) {
			$payload = json_decode((string) $r->payload, true);
			if ((int) $r->fk_account > 0 && !isset($levels[(int) $r->fk_account]) && is_array($payload)) {
				$payload['subject'] = '';
			}
			$actor = trim($r->firstname.' '.$r->lastname);
			$items[] = array(
				'id' => (int) $r->rowid,
				'type' => (string) $r->type,
				'actor' => (int) $r->fk_user_actor ? array('id' => (int) $r->fk_user_actor, 'name' => $actor !== '' ? $actor : (string) $r->login) : null,
				'subject' => is_array($payload) ? (string) ($payload['subject'] ?? '') : '',
				'text' => is_array($payload) ? (string) ($payload['text'] ?? '') : '',
				'detail' => is_array($payload) ? (string) ($payload['detail'] ?? '') : '',
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
	 * Mailboxes the user reads, kept for the unread counter (count) or for the new mails told by the browser (notify)
	 *
	 * @param User   $user User
	 * @param string $what count or notify
	 * @return int[]
	 */
	public function accountsFor(User $user, $what)
	{
		$access = new InboxAccess($this->db);
		$ids = array_keys(array_filter($access->getLevels($user), function ($level) {
			return InboxAccess::rank($level) >= InboxAccess::rank(InboxAccess::LEVEL_READ);
		}));
		if (!$ids) {
			return array();
		}
		$params = new InboxUserParam($this->db);
		$scope = $params->get($user, 'count_scope');
		$rows = $this->db->getRows("SELECT rowid, type, fk_user_owner FROM ".$this->db->prefix()."inbox_account WHERE rowid IN (".implode(',', array_map('intval', $ids)).") LIMIT 10000");
		$out = array();
		foreach (is_array($rows) ? $rows : array() as $r) {
			$id = (int) $r->rowid;
			$personal = $r->type === InboxAccount::TYPE_PERSONAL && (int) $r->fk_user_owner === (int) $user->id;
			if ($what === 'count') {
				if (($scope === 'personal' && !$personal) || $params->get($user, 'count_box', $id) !== '1') {
					continue;
				}
			} else {
				// auto: a personal mailbox tells its new mails, a shared one does not (a busy one would ring for the whole team)
				$v = $params->get($user, 'new_mail_notify', $id);
				if ($v === '0' || ($v === 'auto' && !$personal)) {
					continue;
				}
			}
			$out[] = $id;
		}

		return $out;
	}

	/**
	 * @param int[] $accounts Mailboxes
	 * @return int[] Their inbox folders (the counter counts these ones only: not the junk, the trash or the others)
	 */
	protected function inboxFolders(array $accounts)
	{
		if (!$accounts) {
			return array();
		}
		$rows = $this->db->getRows("SELECT rowid FROM ".$this->db->prefix()."inbox_folder WHERE fk_account IN (".implode(',', array_map('intval', $accounts)).")"
			." AND role = 'inbox' AND status = 1 LIMIT 10000");

		return array_map(function ($r) {
			return (int) $r->rowid;
		}, is_array($rows) ? $rows : array());
	}

	/**
	 * Unread counter of the tab, of the installed application and of the top bar of Dolibarr (lot W8 1.21.0)
	 *
	 * @param User $user User
	 * @return array{mails:int,mail_folders:int[]} Unread mails, and the folders counted (the application sums them
	 *                                              again after a local action, without waiting for the server)
	 */
	public function mailCount(User $user)
	{
		$folders = $this->inboxFolders($this->accountsFor($user, 'count'));
		if (!$folders) {
			return array('mails' => 0, 'mail_folders' => array());
		}
		$row = $this->db->getRow("SELECT SUM(unread) as n FROM ".$this->db->prefix()."inbox_folder WHERE rowid IN (".implode(',', $folders).")");

		return array('mails' => is_object($row) ? (int) $row->n : 0, 'mail_folders' => $folders);
	}

	/**
	 * Channel of a type of notification for a user
	 *
	 * @param User   $user    User
	 * @param string $type    Type
	 * @param string $channel b (browser, on by default) or m (mail, off by default)
	 * @return bool
	 */
	public function channelOn(User $user, $type, $channel)
	{
		$params = new InboxUserParam($this->db);
		$all = json_decode($params->get($user, 'notif_channels'), true);
		if (is_array($all) && isset($all[$type][$channel])) {
			return (int) $all[$type][$channel] === 1;
		}

		return $channel === 'b';
	}

	/**
	 * What the browser tells since the last call (asked by the leader tab only, when the user turned it on)
	 *
	 * @param User $user       User
	 * @param int  $sinceMail  Last mail seen
	 * @param int  $sinceNotif Last notification seen
	 * @param bool $first      First call of the tab: only the last ones given back (nothing told)
	 * @return array{mail_last:int,notif_last:int,mails:array<int,array<string,mixed>>,notifs:array<int,array<string,mixed>>}
	 */
	public function fresh(User $user, $sinceMail, $sinceNotif, $first = false)
	{
		global $conf;

		$p = $this->db->prefix();
		$out = array('mail_last' => (int) $sinceMail, 'notif_last' => (int) $sinceNotif, 'mails' => array(), 'notifs' => array());
		$folders = $this->inboxFolders($this->accountsFor($user, 'notify'));
		if ($folders) {
			$in = " WHERE m.fk_folder IN (".implode(',', $folders).")";
			$last = $this->db->getRow("SELECT MAX(m.rowid) as n FROM ".$p."inbox_mail as m".$in);
			$out['mail_last'] = max((int) $sinceMail, is_object($last) ? (int) $last->n : 0);
			if (!$first) {
				// Unread and received a moment ago: a mail moved back into the inbox is a new line of the index, not a new mail
				$rows = $this->db->getRows("SELECT m.rowid, m.fk_folder, m.from_name, m.from_email, m.subject, a.label, a.email FROM ".$p."inbox_mail as m"
					." INNER JOIN ".$p."inbox_account as a ON a.rowid = m.fk_account".$in." AND m.rowid > ".((int) $sinceMail)." AND m.seen = 0"
					." AND m.date_received > '".$this->db->idate(dol_now() - self::FRESH_WINDOW)."' ORDER BY m.rowid ASC LIMIT 20");
				foreach (is_array($rows) ? $rows : array() as $r) {
					$out['mails'][] = array('id' => (int) $r->rowid, 'folder' => (int) $r->fk_folder, 'from' => (string) ($r->from_name ?: $r->from_email),
						'subject' => (string) $r->subject, 'account' => (string) ($r->label ?: $r->email));
				}
			}
		} else {
			// No mailbox told now: the last mail of the index, so that a mailbox turned on later tells only what comes next
			$last = $this->db->getRow("SELECT MAX(rowid) as n FROM ".$p."inbox_mail");
			$out['mail_last'] = max((int) $sinceMail, is_object($last) ? (int) $last->n : 0);
		}
		$last = $this->db->getRow("SELECT MAX(rowid) as n FROM ".$p."inbox_notification WHERE entity = ".((int) $conf->entity)." AND fk_user = ".((int) $user->id));
		$out['notif_last'] = max((int) $sinceNotif, is_object($last) ? (int) $last->n : 0);
		if (!$first && $out['notif_last'] > $sinceNotif) {
			foreach ($this->listFor($user, 20)['items'] as $n) {
				if ($n['id'] > $sinceNotif && !$n['read'] && $this->channelOn($user, $n['type'], 'b')) {
					$out['notifs'][] = $n;
				}
			}
		}

		return $out;
	}

	/**
	 * A mailbox goes on failing (type box_error): its managers are told, once per episode. A refused password at
	 * once; a server out of reach when the last good pass is more than 15 minutes old.
	 *
	 * @param int    $accountId Mailbox
	 * @param string $state     auth_failed, unreachable, error
	 * @return int Users told
	 */
	public function boxError($accountId, $state)
	{
		$p = $this->db->prefix();
		$a = $this->db->getRow("SELECT rowid, entity, label, email, date_sync, sync_error FROM ".$p."inbox_account WHERE rowid = ".((int) $accountId));
		if (!is_object($a)) {
			return 0;
		}
		$lastOk = $a->date_sync ? (int) $this->db->jdate($a->date_sync) : 0;
		if ($state !== 'auth_failed' && $lastOk > dol_now() - self::BOX_ERROR_AFTER) {
			return 0;
		}
		// Once per episode: nothing told since the last good pass
		$told = $this->db->getRow("SELECT rowid FROM ".$p."inbox_notification WHERE type = 'box_error' AND fk_account = ".((int) $a->rowid)
			.($lastOk ? " AND date_creation > '".$this->db->idate($lastOk)."'" : ''));
		if (is_object($told)) {
			return 0;
		}
		$access = new InboxAccess($this->db);
		$users = array_keys(array_filter($access->usersOf((int) $a->rowid), function ($u) {
			return $u['level'] === InboxAccess::LEVEL_MANAGE;
		}));
		if (!$users) {
			// Nobody manages it: the administrators of Dolibarr
			$rows = $this->db->getRows("SELECT rowid FROM ".$p."user WHERE admin = 1 AND statut = 1 AND entity IN (0, ".((int) $a->entity).") LIMIT 50");
			$users = array_map(function ($r) {
				return (int) $r->rowid;
			}, is_array($rows) ? $rows : array());
		}
		$n = 0;
		foreach ($users as $uid) {
			if ($this->add($uid, 'box_error', array('account' => (int) $a->rowid, 'url' => '#/settings/accounts/'.((int) $a->rowid).'/sync',
				'payload' => array('subject' => dol_trunc((string) $a->sync_error, 150), 'state' => (string) $state))) > 0) {
				$n++;
			}
		}

		return $n;
	}

	/**
	 * Scheduled job: the notifications still unread after 15 minutes go by mail to the users who chose it, one
	 * summary per user (a notification seen in the bell in the meantime does not go)
	 *
	 * @return int 0 if OK
	 */
	public function cronMail()
	{
		global $conf, $langs;

		require_once DOL_DOCUMENT_ROOT.'/core/class/CMailFile.class.php';
		$this->output = '';
		$p = $this->db->prefix();
		$now = dol_now();
		// Unread for 15 minutes, made the last day only (the channel turned on later does not send the old ones)
		$where = " WHERE entity = ".((int) $conf->entity)." AND date_read IS NULL AND date_mailed IS NULL"
			." AND date_creation < '".$this->db->idate($now - self::MAIL_AFTER)."' AND date_creation > '".$this->db->idate($now - 86400)."'";
		$userIds = $this->db->getRows("SELECT DISTINCT fk_user FROM ".$p."inbox_notification".$where." LIMIT 500");
		$sent = 0;
		$from = getDolGlobalString('MAIN_MAIL_EMAIL_FROM');
		$disabled = getDolGlobalInt('MAIN_DISABLE_ALL_MAILS') > 0;
		foreach (is_array($userIds) ? $userIds : array() as $row) {
			$u = new User($this->db);
			$rows = $this->db->getRows("SELECT rowid, type FROM ".$p."inbox_notification".$where." AND fk_user = ".((int) $row->fk_user)." ORDER BY rowid LIMIT 200");
			$ids = array();
			$wanted = array();
			if ($u->fetch((int) $row->fk_user, '', '', 1) > 0 && (int) $u->statut === 1 && isValidEmail($u->email)) {
				foreach (is_array($rows) ? $rows : array() as $r) {
					if ($this->channelOn($u, (string) $r->type, 'm')) {
						$wanted[] = (int) $r->rowid;
					}
				}
			}
			foreach (is_array($rows) ? $rows : array() as $r) {
				$ids[] = (int) $r->rowid;
			}
			if ($wanted && !$disabled && $from !== '') {
				$items = array_values(array_filter($this->listFor($u, 100)['items'], function ($n) use ($wanted) {
					return in_array($n['id'], $wanted, true);
				}));
				if ($items && $this->sendSummary($u, $items, $from)) {
					$sent++;
				}
			}
			// Handled, sent or not: never considered again
			if ($ids) {
				$this->db->query("UPDATE ".$p."inbox_notification SET date_mailed = '".$this->db->idate($now)."' WHERE rowid IN (".implode(',', $ids).")");
			}
		}
		$this->output = $sent.' summary(ies) sent'.($disabled ? ' (mails disabled: MAIN_DISABLE_ALL_MAILS)' : '');

		return 0;
	}

	/**
	 * @param User                           $u     User told
	 * @param array<int,array<string,mixed>> $items Notifications (listFor)
	 * @param string                         $from  Sender
	 * @return bool
	 */
	protected function sendSummary(User $u, array $items, $from)
	{
		global $conf, $langs;

		$outlangs = new Translate('', $conf);
		$outlangs->setDefaultLang(!empty($u->conf->MAIN_LANG_DEFAULT) ? $u->conf->MAIN_LANG_DEFAULT : $langs->defaultlang);
		$outlangs->loadLangs(array('main', 'inbox@inbox'));
		$lines = '';
		foreach ($items as $n) {
			$lines .= '<li style="margin:0 0 10px"><b>'.dol_escape_htmltag(self::textOf($n, $outlangs)).'</b>'
				.($n['subject'] !== '' ? '<br>'.dol_escape_htmltag($n['subject']) : '')
				.'<br><span style="color:#666">'.($n['account'] !== '' ? dol_escape_htmltag($n['account']).' · ' : '').dol_print_date($n['date'], 'dayhour', 'tzuserrel', $outlangs).'</span></li>';
		}
		$url = dol_buildpath('/inbox/index.php', 2);
		$body = '<p>'.dol_escape_htmltag($outlangs->transnoentities('InboxNotifMailIntro', count($items))).'</p><ul style="padding-left:18px">'.$lines.'</ul>'
			.'<p><a href="'.dol_escape_htmltag($url).'">'.dol_escape_htmltag($outlangs->transnoentities('InboxNotifMailOpen')).'</a></p>'
			.'<p style="color:#666;font-size:12px">'.dol_escape_htmltag($outlangs->transnoentities('InboxNotifMailFooter')).'</p>';
		$mail = new CMailFile($outlangs->transnoentities('InboxNotifMailSubject', count($items)), $u->email, $from, $body, array(), array(), array(), '', '', 0, 1, '', '', '', '', 'notification');
		if (!$mail->sendfile()) {
			$this->error = $mail->error;
			return false;
		}

		return true;
	}

	/**
	 * What a notification says (same sentences as the bell)
	 *
	 * @param array<string,mixed> $n     Notification (listFor)
	 * @param Translate           $langs Language
	 * @return string
	 */
	public static function textOf(array $n, $langs)
	{
		if ($n['type'] === 'assign') {
			return $langs->transnoentities('InboxNotifAssign', $n['actor'] ? $n['actor']['name'] : '');
		}
		if ($n['type'] === 'send_failed') {
			return $langs->transnoentities('InboxNotifSendFailed');
		}
		if ($n['type'] === 'box_error') {
			return $langs->transnoentities('InboxNotifBoxError');
		}
		if ($n['type'] === 'bounce') {
			return $langs->transnoentities('InboxNotifBounce', (string) ($n['detail'] ?? ''));
		}
		if ($n['type'] === 'access_self') {
			return $langs->transnoentities('InboxNotifAccessSelf', $n['actor'] ? $n['actor']['name'] : '');
		}
		if ($n['type'] === 'rule_redirect') {
			return $langs->transnoentities('InboxNotifRuleRedirect', $n['actor'] ? $n['actor']['name'] : '');
		}

		return (string) ($n['text'] !== '' ? $n['text'] : $n['type']);
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
