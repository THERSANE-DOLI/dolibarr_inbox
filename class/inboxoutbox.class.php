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
 * \file    inbox/class/inboxoutbox.class.php
 * \ingroup inbox
 * \brief   Queue of the sending (lot W6 1.17.0): delay with « Undo », sending at a date, failures told, record on a piece
 */

require_once __DIR__.'/inboxaccess.class.php';
require_once __DIR__.'/inboxaccount.class.php';
require_once __DIR__.'/inboxactionlog.class.php';
require_once __DIR__.'/inboxcomposer.class.php';
require_once __DIR__.'/connector/inboxconnectorfactory.class.php';

/**
 * A mail of the queue is built and frozen when « Send » is clicked: its source (with the Bcc, for the copy in Sent) and
 * what goes to the SMTP server (without) are files of the queue; the row keeps what remains to do after the sending.
 * It leaves once: the row is locked (status 1) by one UPDATE before the sending, whoever sends it (the page, « Send
 * now », the scheduled job). A sending cut in the middle is never sent again: it is told as failed (« check Sent »).
 */
class InboxOutbox
{
	const WAITING = 0;
	const SENDING = 1;
	const SENT = 2;
	const FAILED = -1;

	/** @var int Hours a mail sent keeps its source (record on a piece asked after the sending) */
	const KEEP_SENT_HOURS = 24;

	/** @var DoliDB */
	protected $db;

	/** @var string */
	public $error = '';

	/** @var string */
	public $errorCode = '';

	/** @var string Output of the scheduled job */
	public $output = '';

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct(DoliDB $db)
	{
		$this->db = $db;
	}

	/**
	 * @param string $code   Code
	 * @param string $detail Detail
	 * @return null
	 */
	protected function fail($code, $detail = '')
	{
		$this->errorCode = $code;
		$this->error = $detail !== '' ? $detail : $code;

		return null;
	}

	/**
	 * @return string Folder of the sources of the queue (temporary folder of the system when the command line may not
	 *                write in the folder of inbox)
	 */
	public static function dir()
	{
		global $conf;

		$dir = (!empty($conf->inbox->dir_output) ? $conf->inbox->dir_output : DOL_DATA_ROOT.'/inbox').'/outbox';
		if (!is_dir($dir)) {
			dol_mkdir($dir);
		}

		return is_writable($dir) ? $dir : sys_get_temp_dir().'/dolibarr-inbox-outbox';
	}

	/**
	 * @param int    $id   Row
	 * @param string $kind raw (with the Bcc) or wire (sent)
	 * @return string
	 */
	protected static function file($id, $kind)
	{
		return self::dir().'/'.((int) $id).'.'.($kind === 'wire' ? 'wire' : 'raw').'.eml';
	}

	/**
	 * Put a mail built in the queue
	 *
	 * @param User                $user     Author
	 * @param InboxAccount        $account  Mailbox of the identity
	 * @param array<string,mixed> $identity Identity
	 * @param InboxMailBuilder    $b        Builder
	 * @param array<string,mixed> $built    Result of build()
	 * @param array<string,mixed> $o        token, source, draft, record, pieces, typed, at, scheduled
	 * @return int Row, < 0 if KO
	 */
	public function queue(User $user, InboxAccount $account, array $identity, InboxMailBuilder $b, array $built, array $o)
	{
		global $conf;

		$dir = self::dir();
		if (dol_mkdir($dir) < 0 && !is_dir($dir)) {
			$this->error = 'Cannot create '.$dir;
			return -1;
		}
		$p = $this->db->prefix();
		$s = function ($v, $len = 255) {
			$v = (string) $v;
			return $v === '' ? 'NULL' : "'".$this->db->escape(dol_trunc($v, $len, 'right', 'UTF-8', 1))."'";
		};
		$actions = array('from' => $b->from['email'], 'envelope' => $built['recipients'], 'source' => $o['source'], 'draft' => $o['draft'],
			'record' => $o['record'], 'pieces' => $o['pieces'], 'identity' => (int) $identity['id']);
		$sql = "INSERT INTO ".$p."inbox_outbox (entity, fk_user, fk_account, fk_identity, message_id, subject, recipients, nb_recipients, compose_token, actions, typed,"
			." scheduled, date_send, status, date_creation) VALUES (".((int) $conf->entity).", ".((int) $user->id).", ".((int) $account->id).", ".((int) $identity['id']).","
			." '".$this->db->escape($built['message_id'])."', ".$s($b->subject).", ".$s(implode(', ', array_column(array_merge($b->to, $b->cc), 'email')), 65000).","
			." ".count($built['recipients']).", ".$s($o['token'], 32).", '".$this->db->escape((string) json_encode($actions))."', '".$this->db->escape((string) json_encode($o['typed']))."',"
			." ".((int) $o['scheduled']).", '".$this->db->idate((int) $o['at'])."', ".self::WAITING.", '".$this->db->idate(dol_now())."')";
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$id = (int) $this->db->last_insert_id($p.'inbox_outbox');
		if (file_put_contents(self::file($id, 'raw'), $built['raw']) === false || file_put_contents(self::file($id, 'wire'), $built['wire']) === false) {
			$this->db->query("DELETE FROM ".$p."inbox_outbox WHERE rowid = ".$id);
			$this->error = 'Cannot write the source of the mail';
			return -1;
		}

		return $id;
	}

	/**
	 * @param int $id Row
	 * @return object|null
	 */
	protected function row($id)
	{
		$row = $this->db->getRow("SELECT * FROM ".$this->db->prefix()."inbox_outbox WHERE rowid = ".((int) $id));

		return is_object($row) ? $row : null;
	}

	/**
	 * Send a mail of the queue (once: the row is locked first). After the sending: copy in Sent, mail answered marked,
	 * draft removed, journal, trigger, record on the pieces chosen.
	 *
	 * @param int       $id     Row
	 * @param User|null $user   User asking (null: scheduled job, the author is used)
	 * @param bool      $direct Sending asked by the page without delay: a failure gives the writing back (no row kept)
	 * @return array<string,mixed>|null
	 */
	public function deliver($id, $user = null, $direct = false)
	{
		global $conf;

		$this->error = '';
		$this->errorCode = '';
		$p = $this->db->prefix();
		$row = $this->row($id);
		if (!$row || ($user && (int) $row->fk_user !== (int) $user->id)) {
			return $this->fail('not_found');
		}
		// The lock: one UPDATE, whoever sends it
		$res = $this->db->query("UPDATE ".$p."inbox_outbox SET status = ".self::SENDING.", tries = tries + 1 WHERE rowid = ".((int) $id)." AND status = ".self::WAITING);
		if (!$res || $this->db->affected_rows($res) < 1) {
			$now = $this->row($id);
			return $this->fail($now && (int) $now->status === self::SENT ? 'already_sent' : 'not_found');
		}
		$author = $user;
		if (!$author) {
			$author = new User($this->db);
			if ($author->fetch((int) $row->fk_user) <= 0 || (int) $author->statut !== 1) {
				return $this->failed($row, 'author', 'The author of the mail is not active any more', $direct);
			}
			$author->loadRights();
		}
		$account = new InboxAccount($this->db);
		if ($account->fetch((int) $row->fk_account) <= 0 || !$account->isActive()) {
			return $this->failed($row, 'no_send', 'Mailbox not active', $direct);
		}
		// The rights may have changed during the delay
		$access = new InboxAccess($this->db);
		InboxAccess::clearCache();
		if (!in_array($access->getLevel($author, (int) $account->id), array(InboxAccess::LEVEL_SEND, InboxAccess::LEVEL_MANAGE), true)) {
			return $this->failed($row, 'no_send', 'No right to send from the mailbox', $direct);
		}
		if (getDolGlobalString('MAIN_DISABLE_ALL_MAILS')) {
			return $this->failed($row, 'mails_disabled', 'Sending disabled', $direct);
		}
		$raw = (string) @file_get_contents(self::file($id, 'raw'));
		$wire = (string) @file_get_contents(self::file($id, 'wire'));
		$actions = json_decode((string) $row->actions, true) ?: array();
		if ($raw === '' || $wire === '') {
			return $this->failed($row, 'error', 'Source of the mail missing', $direct);
		}
		$error = '';
		$connector = InboxConnectorFactory::forAccount($this->db, $account, $error);
		if (!$connector) {
			return $this->failed($row, 'error', $error, $direct);
		}
		if (!$connector->can(InboxConnectorInterface::CAP_SEND)) {
			$connector->disconnect();
			return $this->failed($row, 'smtp_no_server', 'No sending server', $direct);
		}
		if (!$connector->send($wire, array('from' => (string) $actions['from'], 'recipients' => (array) $actions['envelope']))) {
			$code = method_exists($connector, 'getErrorCode') ? (string) $connector->getErrorCode() : '';
			$detail = $connector->getError();
			$connector->disconnect();
			return $this->failed($row, $code !== '' ? $code : 'smtp_refused', $detail, $direct);
		}
		// Sent: from here nothing may make it leave again
		$this->db->query("UPDATE ".$p."inbox_outbox SET status = ".self::SENT.", date_sent = '".$this->db->idate(dol_now())."', error = NULL WHERE rowid = ".((int) $id));
		$composer = new InboxComposer($this->db);
		$roles = array('sent' => $composer->roleFolder($account, 'sent'), 'drafts' => $composer->roleFolder($account, 'drafts'));
		$sentCopy = 0;
		if ($roles['sent'] !== '') {
			$sentCopy = $connector->importRaw($roles['sent'], $raw, array('\\Seen')) !== null ? 1 : 0;
			if (!$sentCopy) {
				dol_syslog(__METHOD__.' copy in Sent failed: '.$connector->getError(), LOG_WARNING);
			}
		}
		$this->db->query("UPDATE ".$p."inbox_outbox SET sent_copy = ".$sentCopy." WHERE rowid = ".((int) $id));
		$source = $actions['source'] ?? null;
		if ($source && !in_array($source['mode'], array('forward', 'forward_attach'), true) && (int) ($source['mail'] ?? 0) > 0) {
			$composer->markAnswered($author, $source, (int) $account->id === (int) $source['account'] ? $connector : null);
		}
		if (!empty($actions['draft'])) {
			$composer->removeDraft($actions['draft'], $connector, (int) $account->id);
		}
		$connector->disconnect();
		$subject = (string) $row->subject;
		InboxActionLog::add($this->db, $author, (int) $account->id, 'send', array((object) array('message_id' => (string) $row->message_id, 'subject' => $subject)),
			'', $roles['sent'], 0, (string) count((array) $actions['envelope']));
		$account->context['inbox'] = array('message_id' => (string) $row->message_id, 'identity' => (int) $row->fk_identity, 'recipients' => (array) $actions['envelope'],
			'subject' => $subject, 'source' => $source ? (string) ($source['message_id'] ?? '') : '', 'mode' => $source ? (string) $source['mode'] : 'new', 'outbox' => (int) $id);
		$account->call_trigger('INBOX_MAIL_SENT', $author);
		// Sent and Drafts read again first: the mail sent is in the index when it is linked to the pieces
		$composer->refreshFolders($account, array_filter(array($roles['sent'], $roles['drafts'])));
		$recorded = array();
		if (!empty($actions['record'])) {
			$recorded = $this->recordOn($author, $row, $raw, (array) $actions['record']);
		}
		// The writing is over: its folder goes; the source stays a day (record on a piece asked after the sending)
		if ((string) $row->compose_token !== '') {
			$dir = InboxComposer::dirOf($author, (string) $row->compose_token);
			if ($dir !== null) {
				dol_delete_dir_recursive($dir);
			}
			if (isset($_SESSION['inbox_compose'][(string) $row->compose_token])) {
				unset($_SESSION['inbox_compose'][(string) $row->compose_token]);
			}
		}
		@unlink(self::file($id, 'wire'));

		return array('message_id' => (string) $row->message_id, 'sent_copy' => $sentCopy, 'account' => (int) $account->id, 'outbox' => (int) $id,
			'recorded' => $recorded, 'pieces' => array_values(array_filter((array) ($actions['pieces'] ?? array()), function ($pc) use ($recorded) {
				return !in_array($pc['type'].':'.$pc['id'], $recorded, true);
			})), 'subject' => $subject);
	}

	/**
	 * A sending failed: back to the writing (direct sending), else kept as failed and told in the bell
	 *
	 * @param object $row    Row
	 * @param string $code   Code
	 * @param string $detail Detail (log, never a secret)
	 * @param bool   $direct Direct sending
	 * @return null
	 */
	protected function failed($row, $code, $detail, $direct)
	{
		$p = $this->db->prefix();
		dol_syslog('inbox outbox '.$row->rowid.' '.$code.' '.$detail, LOG_WARNING);
		if ($direct) {
			$this->forget((int) $row->rowid);
		} else {
			$this->db->query("UPDATE ".$p."inbox_outbox SET status = ".self::FAILED.", error = '".$this->db->escape(dol_trunc($code.': '.$detail, 250, 'right', 'UTF-8', 1))."' WHERE rowid = ".((int) $row->rowid));
			require_once __DIR__.'/inboxnotification.class.php';
			$n = new InboxNotification($this->db);
			$n->add((int) $row->fk_user, 'send_failed', array('account' => (int) $row->fk_account, 'payload' => array('subject' => (string) $row->subject, 'outbox' => (int) $row->rowid), 'url' => '#/outbox/'.((int) $row->rowid)));
		}

		return $this->fail($code, $detail);
	}

	/**
	 * A row and its files removed
	 *
	 * @param int $id Row
	 * @return void
	 */
	protected function forget($id)
	{
		@unlink(self::file($id, 'raw'));
		@unlink(self::file($id, 'wire'));
		$this->db->query("DELETE FROM ".$this->db->prefix()."inbox_outbox WHERE rowid = ".((int) $id));
	}

	/**
	 * « Send now » (delay or date): the row of the user, still waiting
	 *
	 * @param User $user User
	 * @param int  $id   Row
	 * @return array<string,mixed>|null
	 */
	public function dispatch(User $user, $id)
	{
		return $this->deliver($id, $user, false);
	}

	/**
	 * Undo a sending (waiting) or take a failed one back: the writing comes back with what was typed
	 *
	 * @param User $user User
	 * @param int  $id   Row
	 * @return array<string,mixed>|null Data of the writing (as open)
	 */
	public function cancel(User $user, $id)
	{
		$p = $this->db->prefix();
		$row = $this->row($id);
		if (!$row || (int) $row->fk_user !== (int) $user->id) {
			return $this->fail('not_found');
		}
		// Taken back before anyone sends it (one UPDATE as the lock of the sending)
		$res = $this->db->query("UPDATE ".$p."inbox_outbox SET status = -2 WHERE rowid = ".((int) $id)." AND status IN (".self::WAITING.", ".self::FAILED.")");
		if (!$res || $this->db->affected_rows($res) < 1) {
			return $this->fail('already_sent');
		}
		$composer = new InboxComposer($this->db);
		$typed = json_decode((string) $row->typed, true) ?: array();
		$view = $composer->restore($user, (string) $row->compose_token, $typed);
		if ($view === null) {
			// The writing is gone (purge): the row comes back, nothing lost
			$this->db->query("UPDATE ".$p."inbox_outbox SET status = ".((int) $row->status)." WHERE rowid = ".((int) $id));
			return $this->fail($composer->errorCode, $composer->error);
		}
		$this->forget((int) $id);
		require_once __DIR__.'/inboxnotification.class.php';
		$n = new InboxNotification($this->db);
		$n->cancel((int) $user->id, 'send_failed', 0, 0);

		return $view;
	}

	/**
	 * Record a mail sent on pieces (asked after the sending): the source is kept a day
	 *
	 * @param User     $user   User
	 * @param int      $id     Row
	 * @param string[] $pieces « type:id » among the pieces offered
	 * @return string[]|null Pieces recorded
	 */
	public function record(User $user, $id, array $pieces)
	{
		$p = $this->db->prefix();
		$row = $this->row($id);
		if (!$row || (int) $row->fk_user !== (int) $user->id) {
			return $this->fail('not_found');
		}
		$actions = json_decode((string) $row->actions, true) ?: array();
		$offered = array();
		foreach ((array) ($actions['pieces'] ?? array()) as $pc) {
			$offered[$pc['type'].':'.$pc['id']] = array('type' => $pc['type'], 'id' => (int) $pc['id']);
		}
		$chosen = array();
		foreach ($pieces as $key) {
			if (isset($offered[(string) $key])) {
				$chosen[] = $offered[$key];
			}
		}
		if (!$chosen) {
			return $this->fail('not_found');
		}
		if ((int) $row->status === self::WAITING) {
			// Not sent yet: done after the sending
			$actions['record'] = $chosen;
			$this->db->query("UPDATE ".$p."inbox_outbox SET actions = '".$this->db->escape((string) json_encode($actions))."' WHERE rowid = ".((int) $id)." AND status = ".self::WAITING);
			return array_map(function ($c) {
				return $c['type'].':'.$c['id'];
			}, $chosen);
		}
		$raw = (string) @file_get_contents(self::file($id, 'raw'));
		if ((int) $row->status !== self::SENT || $raw === '') {
			return $this->fail('not_found');
		}

		return $this->recordOn($user, $row, $raw, $chosen);
	}

	/**
	 * The sending recorded on pieces: an event of the agenda on the piece (and its thirdparty), the mail (.eml) in the
	 * documents of the piece, the mail sent linked to the piece
	 *
	 * @param User                                  $user   User
	 * @param object                                $row    Row
	 * @param string                                $raw    Source sent
	 * @param array<int,array{type:string,id:int}> $pieces Pieces
	 * @return string[] Pieces recorded (« type:id »)
	 */
	protected function recordOn(User $user, $row, $raw, array $pieces)
	{
		global $langs;

		require_once DOL_DOCUMENT_ROOT.'/comm/action/class/actioncomm.class.php';
		require_once __DIR__.'/inboxelements.class.php';
		require_once __DIR__.'/inboxcreate.class.php';
		require_once __DIR__.'/inboxlink.class.php';
		require_once __DIR__.'/inboxmailref.class.php';
		require_once __DIR__.'/inboxmimeparser.class.php';
		$message = InboxMimeParser::parse($raw);
		$elements = new InboxElements($this->db);
		$create = new InboxCreate($this->db);
		$links = new InboxLink($this->db);
		$refs = new InboxMailRef($this->db);
		$mailref = $refs->fetchOrCreateByMessageId((string) $row->message_id, array('subject' => (string) $row->subject, 'from_email' => $message->fromEmail,
			'date_mail' => (int) $message->date, 'origin' => 'inbox', 'last_account' => (int) $row->fk_account));
		$done = array();
		foreach ($pieces as $pc) {
			$type = (string) $pc['type'];
			$elementId = (int) $pc['id'];
			if (!InboxElements::canWriteType($user, $type) || !InboxElements::canRead($user, $type, $elementId) || !$user->hasRight('agenda', 'myactions', 'create')) {
				continue;
			}
			$event = new ActionComm($this->db);
			$event->type_code = 'AC_EMAIL';
			$event->code = 'AC_EMAIL';
			$event->label = dol_trunc($langs->transnoentities('InboxEventMailSent', (string) $row->subject), 250, 'right', 'UTF-8', 1);
			$event->note_private = dol_trunc(InboxMimeParser::clean($message->bodyText !== '' ? $message->bodyText : InboxMimeParser::htmlToText($message->bodyHtml), true), 60000, 'right', 'UTF-8', 1);
			$event->datep = dol_now();
			$event->datef = dol_now();
			$event->percentage = -1;
			$event->userownerid = (int) $user->id;
			$event->socid = (int) $elements->socOf($type, $elementId);
			$event->elementtype = $type === 'societe' || $type === 'contact' ? '' : $type;
			$event->fk_element = $type === 'societe' || $type === 'contact' ? 0 : $elementId;
			if ($type === 'contact') {
				$event->contact_id = $elementId;
			}
			$event->email_msgid = (string) $row->message_id;
			$event->email_from = $message->fromEmail;
			$event->email_subject = (string) $row->subject;
			$event->email_to = implode(', ', array_column($message->to, 'email'));
			$event->email_tocc = implode(', ', array_column($message->cc, 'email'));
			if ($event->create($user) <= 0) {
				dol_syslog(__METHOD__.' event: '.$event->error, LOG_WARNING);
				continue;
			}
			$create->attach($user, $type, $elementId, dol_sanitizeFileName(dol_trunc((string) $row->subject ?: 'mail', 80, 'right', 'UTF-8', 1)).'.eml', $raw);
			// The author may write in the mailbox and read the piece (checked above): linked even before the copy in
			// Sent is in the index
			if ($mailref > 0 && $links->link($mailref, $type, $elementId, $user, 'inbox') < 0 && $links->error === 'InboxMailNotFound') {
				$links->link($mailref, $type, $elementId, null, 'inbox');
			}
			$done[] = $type.':'.$elementId;
		}

		return $done;
	}

	/**
	 * Mails of the user still in the queue (waiting, scheduled, failed)
	 *
	 * @param User $user User
	 * @return array<int,array<string,mixed>>
	 */
	public function listMine(User $user)
	{
		$p = $this->db->prefix();
		$rows = $this->db->getRows("SELECT o.rowid, o.subject, o.recipients, o.nb_recipients, o.scheduled, o.date_send, o.status, o.error, a.email FROM ".$p."inbox_outbox as o"
			." INNER JOIN ".$p."inbox_account as a ON a.rowid = o.fk_account WHERE o.fk_user = ".((int) $user->id)." AND o.status IN (".self::WAITING.", ".self::FAILED.")"
			." ORDER BY o.date_send LIMIT 200");
		$out = array();
		foreach (is_array($rows) ? $rows : array() as $r) {
			$out[] = array('id' => (int) $r->rowid, 'subject' => (string) $r->subject, 'to' => (string) $r->recipients, 'nb' => (int) $r->nb_recipients, 'scheduled' => (int) $r->scheduled,
				'send_at' => (int) $this->db->jdate($r->date_send), 'status' => (int) $r->status, 'error' => (string) $r->error, 'mailbox' => (string) $r->email);
		}

		return $out;
	}

	/**
	 * Scheduled job (every minute): the mails due leave; a sending cut in the middle is told as failed, never sent
	 * again; the sources of the mails sent go after a day
	 *
	 * @return int 0 (job of Dolibarr)
	 */
	public function cronRun()
	{
		$p = $this->db->prefix();
		$now = dol_now();
		$stuck = $this->db->getRows("SELECT * FROM ".$p."inbox_outbox WHERE status = ".self::SENDING." AND tms < '".$this->db->idate($now - 600)."' LIMIT 50");
		foreach (is_array($stuck) ? $stuck : array() as $row) {
			$this->failed($row, 'interrupted', 'The sending was cut: check the Sent folder before sending it again', false);
		}
		$due = $this->db->getRows("SELECT rowid FROM ".$p."inbox_outbox WHERE status = ".self::WAITING." AND date_send <= '".$this->db->idate($now)."' ORDER BY date_send LIMIT 20");
		$sent = 0;
		$failed = 0;
		$deadline = microtime(true) + 45;
		foreach (is_array($due) ? $due : array() as $d) {
			if (microtime(true) > $deadline) {
				break;
			}
			if ($this->deliver((int) $d->rowid, null, false) !== null) {
				$sent++;
			} elseif ($this->errorCode !== 'not_found' && $this->errorCode !== 'already_sent') {
				$failed++;
			}
		}
		$this->purge();
		$this->output = $sent.' sent, '.$failed.' failed';

		return 0;
	}

	/**
	 * Rows of the mails sent a day ago (and their source), failed ones after 30 days
	 *
	 * @return void
	 */
	public function purge()
	{
		$p = $this->db->prefix();
		$rows = $this->db->getRows("SELECT rowid FROM ".$p."inbox_outbox WHERE (status = ".self::SENT." AND date_sent < '".$this->db->idate(dol_now() - self::KEEP_SENT_HOURS * 3600)."')"
			." OR (status = ".self::FAILED." AND tms < '".$this->db->idate(dol_now() - 30 * 86400)."') OR status = -2 LIMIT 500");
		foreach (is_array($rows) ? $rows : array() as $r) {
			$this->forget((int) $r->rowid);
		}
	}
}
