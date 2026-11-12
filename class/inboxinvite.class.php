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
 * \file    inbox/class/inboxinvite.class.php
 * \ingroup inbox
 * \brief   Invitations received (lot W8 1.22.0): card of the reading pane, answer to the organizer, agenda of Dolibarr
 */

require_once __DIR__.'/inboxical.class.php';
require_once __DIR__.'/inboxsystemmail.class.php';
require_once __DIR__.'/inboxreader.class.php';
require_once __DIR__.'/inboxmimeparser.class.php';
require_once __DIR__.'/inboxagenda.class.php';
require_once __DIR__.'/inboxaccess.class.php';
require_once __DIR__.'/inboxactionlog.class.php';

/**
 * Nothing is automatic: the answer leaves and the agenda changes only on a click. The answer and the event made are
 * kept per user and per event (UID), whatever the copy of the mail: a mail of update or of cancel finds them.
 */
class InboxInvite
{
	/** Answers an attendee may give */
	const PARTSTATS = array('ACCEPTED', 'TENTATIVE', 'DECLINED');

	/** @var DoliDB */
	protected $db;

	/** @var string */
	public $error = '';

	/** @var string Code of the error, for the message shown */
	public $errorCode = '';

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct(DoliDB $db)
	{
		$this->db = $db;
	}

	/**
	 * @param string $code Error
	 * @param string $detail Detail (never shown with a secret)
	 * @return null
	 */
	protected function fail($code, $detail = '')
	{
		$this->errorCode = $code;
		$this->error = $detail !== '' ? $detail : $code;

		return null;
	}

	/**
	 * Text of the invitation of a mail: the text/calendar part of the body, else an .ics attached
	 *
	 * @param InboxMessage $message Mail
	 * @return string
	 */
	public static function calendarOf(InboxMessage $message)
	{
		if ($message->calendar !== '') {
			return $message->calendar;
		}
		foreach ($message->attachments as $a) {
			if (preg_match('/\.ics$/i', (string) $a['name']) || stripos((string) $a['mime'], 'text/calendar') === 0 || stripos((string) $a['mime'], 'application/ics') === 0) {
				return (string) $a['content'];
			}
		}

		return '';
	}

	/**
	 * The event of the invitation: the main one (not an occurrence changed alone), else the first
	 *
	 * @param array{method:string,events:array<int,array<string,mixed>>} $cal Parsed
	 * @return array<string,mixed>
	 */
	protected static function mainEvent(array $cal)
	{
		foreach ($cal['events'] as $e) {
			if ($e['recurrence_id'] === '' && $e['uid'] !== '') {
				return $e;
			}
		}

		return $cal['events'][0];
	}

	/**
	 * @param InboxAccount $account Mailbox
	 * @return string[] Addresses of the mailbox (itself and its identities), lower case
	 */
	protected function addressesOf(InboxAccount $account)
	{
		$out = array(strtolower((string) $account->email));
		$rows = $this->db->getRows("SELECT email FROM ".$this->db->prefix()."inbox_identity WHERE fk_account = ".((int) $account->id)." LIMIT 200");
		foreach (is_array($rows) ? $rows : array() as $r) {
			$out[] = strtolower((string) $r->email);
		}

		return array_values(array_unique(array_filter($out)));
	}

	/**
	 * @param User   $user User
	 * @param string $uid  UID of the event
	 * @return object|null Row of llx_inbox_invite
	 */
	public function state(User $user, $uid)
	{
		global $conf;

		$row = $this->db->getRow("SELECT rowid, sequence, partstat, date_reply, fk_actioncomm, agenda_sequence FROM ".$this->db->prefix()."inbox_invite"
			." WHERE entity = ".((int) $conf->entity)." AND fk_user = ".((int) $user->id)." AND uid_hash = '".$this->db->escape(sha1((string) $uid))."'");

		return is_object($row) ? $row : null;
	}

	/**
	 * @param User                $user User
	 * @param string              $uid  UID
	 * @param array<string,mixed> $set  Columns
	 * @return void
	 */
	protected function save(User $user, $uid, array $set)
	{
		global $conf;

		$p = $this->db->prefix();
		$row = $this->state($user, $uid);
		$sql = array();
		foreach ($set as $k => $v) {
			$sql[$k] = $v === null ? 'NULL' : (is_int($v) ? (string) $v : "'".$this->db->escape((string) $v)."'");
		}
		if ($row) {
			$this->db->query("UPDATE ".$p."inbox_invite SET ".implode(', ', array_map(function ($k, $v) {
				return $k.' = '.$v;
			}, array_keys($sql), $sql))." WHERE rowid = ".((int) $row->rowid));
		} else {
			$sql += array('entity' => (string) ((int) $conf->entity), 'fk_user' => (string) ((int) $user->id), 'uid_hash' => "'".$this->db->escape(sha1((string) $uid))."'");
			$this->db->query("INSERT INTO ".$p."inbox_invite (".implode(', ', array_keys($sql)).") VALUES (".implode(', ', $sql).")");
		}
	}

	/**
	 * @param int $id Event of the agenda
	 * @return object|null id, label, datep, datep2 (still there)
	 */
	protected function agendaRow($id)
	{
		if ((int) $id <= 0) {
			return null;
		}
		$row = $this->db->getRow("SELECT id, label, datep, datep2, fk_user_action FROM ".$this->db->prefix()."actioncomm WHERE id = ".((int) $id));

		return is_object($row) ? $row : null;
	}

	/**
	 * @param User $user User
	 * @return bool The agenda of Dolibarr can take the event
	 */
	public static function canAgenda(User $user)
	{
		return isModEnabled('agenda') && $user->hasRight('agenda', 'myactions', 'create');
	}

	/**
	 * Card of the reading pane, null when the mail holds no invitation
	 *
	 * @param User         $user    User
	 * @param InboxReader  $reader  Mail loaded
	 * @param InboxMessage $message Mail parsed
	 * @return array<string,mixed>|null
	 */
	public function view(User $user, InboxReader $reader, InboxMessage $message)
	{
		global $langs;

		$cal = InboxIcal::parse(self::calendarOf($message));
		if ($cal === null) {
			return null;
		}
		$e = self::mainEvent($cal);
		$method = $cal['method'];
		$mine = $this->addressesOf($reader->account);
		$me = null;
		foreach ($e['attendees'] as $a) {
			if (in_array($a['email'], $mine, true)) {
				$me = $a;
				break;
			}
		}
		$org = $e['organizer'];
		$state = $e['uid'] !== '' ? $this->state($user, $e['uid']) : null;
		$agenda = $state ? $this->agendaRow((int) $state->fk_actioncomm) : null;
		$canSend = InboxAccess::atLeast($reader->level, InboxAccess::LEVEL_SEND);
		$cancelled = $method === 'CANCEL' || $e['status'] === 'CANCELLED';
		$conflicts = array();
		if ($method === 'REQUEST' && !$cancelled && $e['start'] && !$e['allday']) {
			$rows = $this->db->getRows("SELECT id, label FROM ".$this->db->prefix()."actioncomm WHERE fk_user_action = ".((int) $user->id)
				." AND datep < '".$this->db->idate((int) $e['end'])."' AND COALESCE(datep2, datep) > '".$this->db->idate((int) $e['start'])."'"
				.($agenda ? " AND id <> ".((int) $agenda->id) : '')." AND (transparency IS NULL OR transparency <> 0) ORDER BY datep LIMIT 5");
			foreach (is_array($rows) ? $rows : array() as $r) {
				$conflicts[] = array('id' => (int) $r->id, 'label' => (string) $r->label);
			}
		}
		$langs->loadLangs(array('main', 'inbox@inbox'));

		return array(
			'method' => $method,
			'uid' => $e['uid'] !== '' ? sha1($e['uid']) : '',
			'sequence' => (int) $e['sequence'],
			'summary' => $e['summary'],
			'location' => $e['location'],
			'description' => mb_substr($e['description'], 0, 2000, 'UTF-8'),
			'start' => (int) $e['start'],
			'end' => (int) $e['end'],
			'allday' => (bool) $e['allday'],
			'tz' => $e['tz'],
			'rrule' => InboxIcal::rruleText($e['rrule'], $langs),
			'occurrence' => $e['recurrence_id'] !== '',
			'organizer' => $org,
			'attendees' => array_slice($e['attendees'], 0, 50),
			'attendees_count' => count($e['attendees']),
			'me' => $me ? $me['email'] : '',
			'cancelled' => $cancelled,
			'reply' => $state && $state->partstat ? array('partstat' => (string) $state->partstat, 'date' => (int) $this->db->jdate($state->date_reply), 'sequence' => (int) $state->sequence) : null,
			'agenda' => $agenda ? array('id' => (int) $agenda->id, 'url' => DOL_URL_ROOT.'/comm/action/card.php?id='.((int) $agenda->id), 'outdated' => (int) $state->agenda_sequence < (int) $e['sequence']) : null,
			// Answer to the organizer: an invitation (REQUEST) not cancelled, the right to send from this mailbox, not our own invitation
			'can_reply' => $method === 'REQUEST' && !$cancelled && $canSend && $org !== null && !in_array($org['email'], $mine, true) && $e['uid'] !== '',
			'can_send' => $canSend,
			'can_agenda' => self::canAgenda($user) && $e['uid'] !== '' && $e['start'] > 0,
			'conflicts' => $conflicts,
		);
	}

	/**
	 * Mail of an invitation, read for an action
	 *
	 * @param User $user   User
	 * @param int  $mailId Mail
	 * @return array{reader:InboxReader,event:array<string,mixed>,method:string}|null
	 */
	protected function load(User $user, $mailId)
	{
		$reader = new InboxReader($this->db);
		if ((int) $mailId <= 0 || !$reader->load($user, (int) $mailId)) {
			return $this->fail('not_found');
		}
		$raw = $reader->raw($user);
		if ($raw === null) {
			return $this->fail($reader->errorCode === 'gone' ? 'gone' : 'error', $reader->error);
		}
		$cal = InboxIcal::parse(self::calendarOf(InboxMimeParser::parse($raw)));
		if ($cal === null) {
			return $this->fail('no_invite');
		}
		$e = self::mainEvent($cal);
		if ($e['uid'] === '') {
			return $this->fail('no_invite');
		}

		return array('reader' => $reader, 'event' => $e, 'method' => $cal['method']);
	}

	/**
	 * Answer an invitation: to the organizer (unless send = false), kept, and the event put in the agenda if asked
	 *
	 * @param User   $user     User
	 * @param int    $mailId   Mail of the invitation
	 * @param string $partstat ACCEPTED, TENTATIVE, DECLINED
	 * @param bool   $send     Send the answer to the organizer
	 * @param string $comment  Comment sent with it
	 * @param bool   $agenda   Put in the agenda of Dolibarr (accepted or tentative)
	 * @return array{sent:bool,agenda:int}|null
	 */
	public function reply(User $user, $mailId, $partstat, $send, $comment, $agenda)
	{
		global $langs;

		$this->error = '';
		$this->errorCode = '';
		if (!in_array($partstat, self::PARTSTATS, true)) {
			return $this->fail('bad_value');
		}
		$l = $this->load($user, $mailId);
		if ($l === null) {
			return null;
		}
		$reader = $l['reader'];
		$e = $l['event'];
		if ($l['method'] !== 'REQUEST') {
			return $this->fail('no_invite');
		}
		$sent = false;
		if ($send) {
			if (!InboxAccess::atLeast($reader->level, InboxAccess::LEVEL_SEND)) {
				return $this->fail('no_send');
			}
			$org = $e['organizer'];
			if ($org === null) {
				return $this->fail('no_organizer');
			}
			$from = InboxSystemMail::fromOf($this->db, $user, $reader->account);
			// The attendee answering: its address in the invitation when it is one of the mailbox, else the sender used
			$mine = $this->addressesOf($reader->account);
			$attendee = $from;
			foreach ($e['attendees'] as $a) {
				if (in_array($a['email'], $mine, true)) {
					$attendee = array('email' => $a['email'], 'name' => $a['name'] !== '' ? $a['name'] : $from['name']);
					break;
				}
			}
			$langs->loadLangs(array('main', 'inbox@inbox'));
			$ics = InboxIcal::reply($e, $attendee['email'], $attendee['name'] !== '' ? $attendee['name'] : $user->getFullName($langs), $partstat, $comment);
			$text = $langs->transnoentities('InboxInviteReplyText_'.$partstat, $attendee['name'] !== '' ? $attendee['name'] : $attendee['email'], $e['summary'])
				.(trim((string) $comment) !== '' ? "\n\n".trim((string) $comment) : '');
			$boundary = 'inbox-'.bin2hex(random_bytes(12));
			list($type, $body) = InboxSystemMail::multipart('multipart/alternative', array(
				InboxSystemMail::part('text/plain; charset=utf-8', $text),
				InboxSystemMail::part('text/calendar; charset=utf-8; method=REPLY', $ics),
			), $boundary);
			$mail = new InboxSystemMail();
			$headers = array();
			if ((string) $reader->row->message_id !== '') {
				$headers['In-Reply-To'] = '<'.$reader->row->message_id.'>';
				$headers['References'] = '<'.$reader->row->message_id.'>';
			}
			if (!$mail->send($this->db, $reader->account, $from, $org['email'], $langs->transnoentities('InboxInviteReplySubject_'.$partstat, $e['summary']), $type, $body, $headers)) {
				return $this->fail($mail->errorCode, $mail->error);
			}
			$sent = true;
			InboxActionLog::add($this->db, $user, (int) $reader->account->id, 'invite_reply', array((object) array('message_id' => (string) $reader->row->message_id, 'subject' => (string) $reader->row->subject)),
				(string) $reader->row->folder_path, '', 0, strtolower($partstat).' > '.$org['email']);
		}
		$this->save($user, $e['uid'], array('sequence' => (int) $e['sequence'], 'partstat' => $partstat, 'date_reply' => $this->db->idate(dol_now())));
		$eventId = 0;
		if ($agenda && $partstat !== 'DECLINED') {
			$eventId = $this->toAgenda($user, $reader, $e);
		}

		return array('sent' => $sent, 'agenda' => $eventId, 'agenda_url' => $eventId > 0 ? DOL_URL_ROOT.'/comm/action/card.php?id='.$eventId : '');
	}

	/**
	 * Add the event to the agenda of the user, or bring it up to date (update received)
	 *
	 * @param User                $user   User
	 * @param InboxReader         $reader Mail
	 * @param array<string,mixed> $e      Event
	 * @return int Event of the agenda, 0 if KO
	 */
	protected function toAgenda(User $user, InboxReader $reader, array $e)
	{
		global $langs;

		require_once DOL_DOCUMENT_ROOT.'/comm/action/class/actioncomm.class.php';
		require_once __DIR__.'/inboxcontactmatcher.class.php';
		require_once __DIR__.'/inboxmailref.class.php';
		require_once __DIR__.'/inboxlink.class.php';
		if (!self::canAgenda($user)) {
			$this->fail('no_agenda');
			return 0;
		}
		$langs->loadLangs(array('main', 'inbox@inbox'));
		$state = $this->state($user, $e['uid']);
		$event = new ActionComm($this->db);
		$exists = $state && $this->agendaRow((int) $state->fk_actioncomm) && $event->fetch((int) $state->fk_actioncomm) > 0;
		if ($exists && (int) $event->userownerid !== (int) $user->id) {
			$this->fail('no_agenda');
			return 0;
		}
		if ($e['allday']) {
			// Whole days: the last day is the one before DTEND (excluded in iCalendar)
			$event->fulldayevent = 1;
			$event->datep = dol_mktime(0, 0, 0, (int) gmdate('n', $e['start']), (int) gmdate('j', $e['start']), (int) gmdate('Y', $e['start']), 'tzuserrel');
			$last = max($e['start'], $e['end'] - 86400);
			$event->datef = dol_mktime(23, 59, 59, (int) gmdate('n', $last), (int) gmdate('j', $last), (int) gmdate('Y', $last), 'tzuserrel');
		} else {
			$event->fulldayevent = 0;
			$event->datep = (int) $e['start'];
			$event->datef = (int) $e['end'];
		}
		// Summary, place, description and organizer are written by the sender of the invitation (audit INBX-046, INBX-048)
		$event->label = InboxAgenda::field($e['summary'] !== '' ? $e['summary'] : $langs->transnoentities('InboxInviteNoTitle'));
		$event->location = InboxAgenda::field($e['location'], 128);
		$org = $e['organizer'];
		$event->note_private = InboxAgenda::note(trim(($org ? $langs->transnoentities('InboxInviteOrganizer').' : '.($org['name'] !== '' ? $org['name'].' <'.$org['email'].'>' : $org['email'])."\n\n" : '').$e['description']));
		$event->transparency = 1;
		if (!$exists) {
			$event->type_code = 'AC_RDV';
			$event->percentage = -1;
			$event->userownerid = (int) $user->id;
			$event->userassigned = array((int) $user->id => array('id' => (int) $user->id, 'transparency' => 1));
			if ($org) {
				$matcher = new InboxContactMatcher($this->db);
				$m = $matcher->match($org['email']);
				$event->socid = (int) $m['fk_soc'];
				$event->contact_id = (int) $m['fk_socpeople'];
			}
			$event->email_msgid = (string) $reader->row->message_id;
			$id = $event->create($user);
		} else {
			$id = $event->update($user) > 0 ? (int) $event->id : -1;
		}
		if ($id <= 0) {
			$this->fail('agenda_error', $event->error);
			return 0;
		}
		$this->save($user, $e['uid'], array('fk_actioncomm' => (int) $id, 'agenda_sequence' => (int) $e['sequence']));
		// The mail linked to the event, as any link of inbox (shown in the bandeau and on the event)
		if ((string) $reader->row->message_id !== '') {
			$refs = new InboxMailRef($this->db);
			$mailref = $refs->fetchOrCreateByMessageId((string) $reader->row->message_id, array('subject' => (string) $reader->row->subject,
				'from_email' => (string) $reader->row->from_email, 'origin' => 'inbox', 'last_account' => (int) $reader->account->id));
			if ($mailref > 0) {
				$links = new InboxLink($this->db);
				$links->link($mailref, 'action', (int) $id, $user, 'inbox', true);
			}
		}

		return (int) $id;
	}

	/**
	 * Agenda on a click: add (or bring up to date) the event of the invitation, or mark it cancelled (cancel received)
	 *
	 * @param User   $user   User
	 * @param int    $mailId Mail
	 * @param string $what   add or cancel
	 * @return array{agenda:int}|null
	 */
	public function agenda(User $user, $mailId, $what)
	{
		global $langs;

		$this->error = '';
		$this->errorCode = '';
		$l = $this->load($user, $mailId);
		if ($l === null) {
			return null;
		}
		$e = $l['event'];
		if ($what === 'add') {
			if ($l['method'] === 'CANCEL' || $e['status'] === 'CANCELLED') {
				return $this->fail('bad_value');
			}
			$id = $this->toAgenda($user, $l['reader'], $e);
			return $id > 0 ? array('agenda' => $id, 'agenda_url' => DOL_URL_ROOT.'/comm/action/card.php?id='.$id) : null;
		}
		if ($what !== 'cancel') {
			return $this->fail('bad_value');
		}
		require_once DOL_DOCUMENT_ROOT.'/comm/action/class/actioncomm.class.php';
		$state = $this->state($user, $e['uid']);
		$event = new ActionComm($this->db);
		if (!$state || !$this->agendaRow((int) $state->fk_actioncomm) || $event->fetch((int) $state->fk_actioncomm) <= 0 || (int) $event->userownerid !== (int) $user->id || !self::canAgenda($user)) {
			return $this->fail('no_agenda');
		}
		// Cancelled, not removed: the history stays; the time is free again
		$langs->loadLangs(array('main', 'inbox@inbox'));
		$prefix = $langs->transnoentities('InboxInviteCancelledPrefix').' ';
		if (strpos((string) $event->label, $prefix) !== 0) {
			$event->label = InboxAgenda::field($prefix.$event->label);
		}
		$event->transparency = 0;
		if ($event->update($user) <= 0) {
			return $this->fail('agenda_error', $event->error);
		}
		$this->save($user, $e['uid'], array('agenda_sequence' => (int) $e['sequence']));

		return array('agenda' => (int) $event->id, 'agenda_url' => DOL_URL_ROOT.'/comm/action/card.php?id='.((int) $event->id));
	}
}
