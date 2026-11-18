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
 * \file    inbox/class/inboxagendapanel.class.php
 * \ingroup inbox
 * \brief   Agenda panel (1.107.0): the events of the user, coming and to do, on the right of the mails
 *
 * inbox reads the native agenda of Dolibarr only (llx_actioncomm). It knows no other module: an agenda module plugs
 * itself through the hooks of the context « inbox » (README, « Agenda panel »):
 * - inboxAgendaEvents: events of its own added to the list (or the list replaced);
 * - inboxAgendaProvider: pages of its own opened for « create », « open » and « openAll ».
 */

/**
 * Events of the agenda panel
 */
class InboxAgendaPanel
{
	/** Codes of events that record something done (a mail sent or received, a fax, a mail not delivered), never an appointment */
	const RECORD_CODES = array('AC_EMAIL', 'AC_EMAIL_IN', 'AC_FAX', 'AC_INBOX_BOUNCE');

	/** Days shown after today, default and bounds */
	const DAYS_DEFAULT = 7;
	const DAYS_MAX = 31;

	/** Lines read at most */
	/** Longest event still shown while it lasts (days): one started earlier is left out of « today » and the coming days */
	const SPAN_MAX = 31;
	const MAX_COMING = 300;
	const MAX_LATE = 50;

	/** Actions a module may take over by an address */
	const PROVIDER_ACTIONS = array('create', 'open', 'openAll');

	/** @var DoliDB */
	private $db;

	/** @var array<string,mixed>|null Provider declared by a module, read once */
	private static $provider = null;

	/**
	 * @param DoliDB $db Database
	 */
	public function __construct(DoliDB $db)
	{
		$this->db = $db;
	}

	/**
	 * The native agenda is readable by the user
	 *
	 * @param User $user User
	 * @return bool
	 */
	public static function nativeReadable(User $user)
	{
		return isModEnabled('agenda') && $user->hasRight('agenda', 'myactions', 'read');
	}

	/**
	 * What the page needs: panel shown or not, the case « done » allowed, the pages of a module that took over
	 *
	 * @param User $user User
	 * @return array{enabled:bool,native:bool,canDone:bool,provider:array<string,mixed>|null}
	 */
	public function config(User $user)
	{
		$native = self::nativeReadable($user);
		$provider = $this->provider($user);
		$events = $this->moduleDeclaresEvents($user);

		return array(
			'enabled' => $native || $provider !== null || $events,
			'native' => $native,
			'canDone' => $native && $user->hasRight('agenda', 'myactions', 'create'),
			'provider' => $provider,
		);
	}

	/**
	 * Pages declared by a module through the hook inboxAgendaProvider: a label and, per action, an address of this
	 * site with neutral markers ({id}, {socid}, {contactids}, {projectid}, {elements}, {start}). Anything else is
	 * dropped: another site, a scheme, an unknown action.
	 *
	 * @param User $user User
	 * @return array{label:string,source:string,urls:array<string,string>}|null
	 */
	public function provider(User $user)
	{
		if (self::$provider !== null) {
			return self::$provider ?: null;
		}
		self::$provider = array();
		global $hookmanager;
		if (!is_object($hookmanager)) {
			include_once DOL_DOCUMENT_ROOT.'/core/class/hookmanager.class.php';
			$hookmanager = new HookManager($this->db);
		}
		$hookmanager->initHooks(array('inbox'));
		$parameters = array('user' => $user);
		$object = null;
		$action = '';
		if ($hookmanager->executeHooks('inboxAgendaProvider', $parameters, $object, $action) < 0 || !is_array($hookmanager->resArray['provider'] ?? null)) {
			return null;
		}
		$p = $hookmanager->resArray['provider'];
		$urls = array();
		foreach (self::PROVIDER_ACTIONS as $a) {
			$url = self::localUrl((string) ($p['urls'][$a] ?? ''));
			if ($url !== '') {
				$urls[$a] = $url;
			}
		}
		if (!$urls) {
			return null;
		}
		self::$provider = array(
			'label' => dol_trunc(trim(dol_string_nohtmltag((string) ($p['label'] ?? ''))), 60, 'right', 'UTF-8', 1),
			'source' => preg_match('/^[a-z0-9_]{1,40}$/', (string) ($p['source'] ?? '')) ? (string) $p['source'] : 'module',
			'urls' => $urls,
		);

		return self::$provider;
	}

	/**
	 * An address of this site only: an absolute path, no scheme, no other host, no line break
	 *
	 * @param string $url Address
	 * @return string The address, '' when refused
	 */
	public static function localUrl($url)
	{
		$url = trim($url);
		if ($url === '' || strlen($url) > 1000 || $url[0] !== '/' || (isset($url[1]) && ($url[1] === '/' || $url[1] === '\\')) || preg_match('/[\s\x00-\x1f\\\\]/', $url)) {
			return '';
		}

		return $url;
	}

	/**
	 * A module declares events of its own (hook inboxAgendaEvents, mode « declare »)
	 *
	 * @param User $user User
	 * @return bool
	 */
	private function moduleDeclaresEvents(User $user)
	{
		global $hookmanager;
		if (!is_object($hookmanager)) {
			include_once DOL_DOCUMENT_ROOT.'/core/class/hookmanager.class.php';
			$hookmanager = new HookManager($this->db);
		}
		$hookmanager->initHooks(array('inbox'));
		$parameters = array('mode' => 'declare', 'user' => $user);
		$object = null;
		$action = '';

		return $hookmanager->executeHooks('inboxAgendaEvents', $parameters, $object, $action) >= 0 && !empty($hookmanager->resArray['declare']);
	}

	/**
	 * Events of the user: the late ones to do (before $from) and the ones of [$from, $from + days)
	 *
	 * @param User $user User
	 * @param int  $from Start of today for the user (timestamp)
	 * @param int  $days Days shown, today included
	 * @return array{late:list<array<string,mixed>>,lateTotal:int,events:list<array<string,mixed>>,from:int,to:int}
	 */
	public function events(User $user, $from, $days)
	{
		$days = max(1, min(self::DAYS_MAX, (int) $days));
		$from = (int) $from;
		$to = $from + $days * 86400 + 3600; // one hour more: a change of time in the period
		$late = array();
		$lateTotal = 0;
		$events = array();
		if (self::nativeReadable($user)) {
			$canDone = $user->hasRight('agenda', 'myactions', 'create');
			$base = $this->baseSql($user);
			$coming = $this->db->getRows($this->select().$base
				// Started at most SPAN_MAX days before (1.109.1): a range on the index of datep, else the whole history of
				// the agenda is read (7 s for 800 000 events)
				." AND a.datep >= '".$this->db->idate($from - self::SPAN_MAX * 86400)."' AND a.datep < '".$this->db->idate($to)."'"
				." AND COALESCE(a.datep2, a.datep) >= '".$this->db->idate($from)."'"
				." ORDER BY a.datep ASC, a.id ASC LIMIT ".self::MAX_COMING);
			foreach (is_array($coming) ? $coming : array() as $o) {
				$events[] = $this->row($o, $user, $canDone);
			}
			$lateWhere = $base." AND a.percent >= 0 AND a.percent < 100 AND COALESCE(a.datep2, a.datep) < '".$this->db->idate($from)."'";
			$count = $this->db->getRow("SELECT COUNT(a.id) as nb".$lateWhere);
			$lateTotal = is_object($count) ? (int) $count->nb : 0;
			if ($lateTotal) {
				$rows = $this->db->getRows($this->select().$lateWhere." ORDER BY a.datep DESC, a.id DESC LIMIT ".self::MAX_LATE);
				foreach (is_array($rows) ? $rows : array() as $o) {
					$late[] = $this->row($o, $user, $canDone);
				}
			}
		}
		// Events of a module (hook inboxAgendaEvents): added, or the native ones replaced (return 1)
		global $hookmanager;
		if (!is_object($hookmanager)) {
			include_once DOL_DOCUMENT_ROOT.'/core/class/hookmanager.class.php';
			$hookmanager = new HookManager($this->db);
		}
		$hookmanager->initHooks(array('inbox'));
		$parameters = array('mode' => 'list', 'user' => $user, 'from' => $from, 'to' => $to, 'events' => $events, 'late' => $late);
		$object = null;
		$action = '';
		$res = $hookmanager->executeHooks('inboxAgendaEvents', $parameters, $object, $action);
		if ($res >= 0) {
			$added = array_map(array($this, 'clean'), array_filter(array_slice((array) ($hookmanager->resArray['events'] ?? array()), 0, self::MAX_COMING), 'is_array'));
			$addedLate = array_map(array($this, 'clean'), array_filter(array_slice((array) ($hookmanager->resArray['late'] ?? array()), 0, self::MAX_LATE), 'is_array'));
			if ($res > 0) {
				$events = $late = array();
				$lateTotal = 0;
			}
			$events = array_merge($events, array_values(array_filter($added)));
			$addedLate = array_values(array_filter($addedLate));
			$late = array_merge($late, $addedLate);
			$lateTotal += count($addedLate);
			usort($events, function ($a, $b) {
				return $a['start'] <=> $b['start'];
			});
		}
		$this->linkMails($user, $events, $late);

		return array('late' => $late, 'lateTotal' => $lateTotal, 'events' => $events, 'from' => $from, 'to' => $to);
	}

	/**
	 * The mail of an event (Message-ID kept by Dolibarr or by inbox) found in a mailbox the user reads: its folder and
	 * its id, for the jump of the page. Nothing for a mail the user cannot read.
	 *
	 * @param User                           $user   User
	 * @param list<array<string,mixed>>      $events Events (changed)
	 * @param list<array<string,mixed>>      $late   Late events (changed)
	 * @return void
	 */
	private function linkMails(User $user, array &$events, array &$late)
	{
		$ids = array();
		foreach (array_merge($events, $late) as $e) {
			if ($e['messageId'] !== '') {
				$ids[$e['messageId']] = true;
			}
		}
		$found = array();
		if ($ids) {
			dol_include_once('/inbox/class/inboxaccess.class.php');
			$access = new InboxAccess($this->db);
			$accounts = array_map(function ($a) {
				return (int) $a->id;
			}, $access->getAccounts($user));
			if ($accounts) {
				$in = implode(',', array_map(function ($id) {
					return "'".$this->db->escape((string) $id)."'";
				}, array_slice(array_keys($ids), 0, 400)));
				$rows = $this->db->getRows("SELECT rowid, fk_folder, message_id FROM ".$this->db->prefix()."inbox_mail"
					." WHERE message_id IN (".$in.") AND fk_account IN (".implode(',', $accounts).") ORDER BY rowid DESC LIMIT 800");
				foreach (is_array($rows) ? $rows : array() as $r) {
					if (!isset($found[(string) $r->message_id])) {
						$found[(string) $r->message_id] = array('folder' => (int) $r->fk_folder, 'mail' => (int) $r->rowid);
					}
				}
			}
		}
		foreach (array($events, $late) as $k => $list) {
			foreach ($list as $i => $e) {
				$list[$i]['mail'] = $found[$e['messageId']] ?? null;
			}
			if ($k === 0) {
				$events = $list;
			} else {
				$late = $list;
			}
		}
	}

	/**
	 * @return string
	 */
	private function select()
	{
		return "SELECT a.id, a.label, a.datep, a.datep2, a.fulldayevent, a.percent, a.location, a.fk_soc, a.email_msgid, a.fk_user_action, a.fk_user_author,"
			." c.code as type_code, c.color as type_color, s.nom as socname, r.answer_status";
	}

	/**
	 * From and where: events of the user (owner, or attendee who did not decline), neither automatic nor records
	 *
	 * @param User $user User
	 * @return string
	 */
	private function baseSql(User $user)
	{
		$p = $this->db->prefix();
		$uid = (int) $user->id;

		return " FROM ".$p."actioncomm as a"
			." LEFT JOIN ".$p."c_actioncomm as c ON c.id = a.fk_action"
			." LEFT JOIN ".$p."societe as s ON s.rowid = a.fk_soc"
			." LEFT JOIN ".$p."actioncomm_resources as r ON r.fk_actioncomm = a.id AND r.element_type = 'user' AND r.fk_element = ".$uid
			." WHERE a.entity IN (".getEntity('agenda').")"
			." AND (a.fk_user_action = ".$uid." OR (r.fk_element IS NOT NULL AND COALESCE(r.answer_status, '0') <> '-1'))"
			." AND (c.type IS NULL OR c.type <> 'systemauto')"
			." AND (a.code IS NULL OR a.code NOT IN ('".implode("','", self::RECORD_CODES)."'))";
	}

	/**
	 * One event for the page
	 *
	 * @param object $o       Row
	 * @param User   $user    User
	 * @param bool   $canDone Right to change his events
	 * @return array<string,mixed>
	 */
	private function row($o, User $user, $canDone)
	{
		$start = (int) $this->db->jdate($o->datep);
		$end = $o->datep2 ? (int) $this->db->jdate($o->datep2) : $start;
		$color = ltrim((string) $o->type_color, '#');
		$own = (int) $o->fk_user_action === (int) $user->id;

		return array(
			'id' => (int) $o->id,
			'source' => 'dolibarr',
			'label' => dol_trunc((string) $o->label, 200, 'right', 'UTF-8', 1),
			'start' => $start,
			'end' => max($start, $end),
			'allday' => (bool) $o->fulldayevent,
			'percent' => (int) $o->percent,
			'location' => dol_trunc((string) $o->location, 120, 'right', 'UTF-8', 1),
			'color' => preg_match('/^[0-9a-f]{6}$/i', $color) ? '#'.strtolower($color) : '',
			'thirdparty' => (int) $o->fk_soc > 0 && $o->socname !== null ? array('id' => (int) $o->fk_soc, 'name' => (string) $o->socname) : null,
			'url' => DOL_URL_ROOT.'/comm/action/card.php?id='.((int) $o->id),
			'messageId' => trim((string) $o->email_msgid, " <>\t"),
			// Answer of the user: 1 accepted, 2 maybe; the owner has none
			'answer' => $own ? 1 : (int) $o->answer_status,
			'canDone' => $canDone && (int) $o->percent >= 0 && self::mayChange($o, $user),
		);
	}

	/**
	 * The user may change this event as in Dolibarr (comm/action/card.php): every event with « create all », else
	 * the ones he owns or wrote with « create his » ; an attendee only may not (audit INBX-094)
	 *
	 * @param object $o    Row (fk_user_action, fk_user_author)
	 * @param User   $user User
	 * @return bool
	 */
	public static function mayChange($o, User $user)
	{
		if ($user->hasRight('agenda', 'allactions', 'create')) {
			return true;
		}
		$mine = (int) $o->fk_user_action === (int) $user->id || (int) $o->fk_user_author === (int) $user->id;

		return $mine && $user->hasRight('agenda', 'myactions', 'create');
	}

	/**
	 * An event given by a module, kept to the known fields and types
	 *
	 * @param array<string,mixed> $e Event
	 * @return array<string,mixed>|null
	 */
	public function clean(array $e)
	{
		$start = (int) ($e['start'] ?? 0);
		$label = trim(dol_string_nohtmltag((string) ($e['label'] ?? '')));
		if ($start <= 0 || $label === '') {
			return null;
		}
		$color = (string) ($e['color'] ?? '');
		$soc = is_array($e['thirdparty'] ?? null) ? $e['thirdparty'] : null;

		return array(
			'id' => preg_match('/^[\w.:-]{1,64}$/', (string) ($e['id'] ?? '')) ? (string) $e['id'] : '',
			'source' => preg_match('/^[a-z0-9_]{1,40}$/', (string) ($e['source'] ?? '')) && $e['source'] !== 'dolibarr' ? (string) $e['source'] : 'module',
			'label' => dol_trunc($label, 200, 'right', 'UTF-8', 1),
			'start' => $start,
			'end' => max($start, (int) ($e['end'] ?? $start)),
			'allday' => !empty($e['allday']),
			'percent' => max(-1, min(100, (int) ($e['percent'] ?? -1))),
			'location' => dol_trunc(trim(dol_string_nohtmltag((string) ($e['location'] ?? ''))), 120, 'right', 'UTF-8', 1),
			'color' => preg_match('/^#[0-9a-f]{6}$/i', $color) ? strtolower($color) : '',
			'thirdparty' => $soc && (int) ($soc['id'] ?? 0) > 0 ? array('id' => (int) $soc['id'], 'name' => dol_trunc(trim(dol_string_nohtmltag((string) ($soc['name'] ?? ''))), 120, 'right', 'UTF-8', 1)) : null,
			'url' => self::localUrl((string) ($e['url'] ?? '')),
			'messageId' => preg_match('/^[^\s<>"]{1,250}$/', (string) ($e['messageId'] ?? '')) ? (string) $e['messageId'] : '',
			'answer' => in_array((int) ($e['answer'] ?? 1), array(0, 1, 2), true) ? (int) ($e['answer'] ?? 1) : 1,
			// An event of a module is never ticked by inbox: its click goes through the hooks of the page
			'canDone' => false,
		);
	}

	/**
	 * Change the progress of an event of the user (case « done », and its undo)
	 *
	 * @param User $user    User
	 * @param int  $id      Event
	 * @param int  $percent New progress 0 to 100
	 * @return int Progress before, -1 refused or not found, -2 error
	 */
	public function setPercent(User $user, $id, $percent)
	{
		if (!self::nativeReadable($user) || !$user->hasRight('agenda', 'myactions', 'create') || $percent < 0 || $percent > 100) {
			return -1;
		}
		$row = $this->db->getRow("SELECT a.id, a.fk_user_action, a.fk_user_author".$this->baseSql($user)." AND a.id = ".((int) $id)." AND a.percent >= 0");
		if (!is_object($row) || !self::mayChange($row, $user)) {
			return -1;
		}
		require_once DOL_DOCUMENT_ROOT.'/comm/action/class/actioncomm.class.php';
		$event = new ActionComm($this->db);
		if ($event->fetch((int) $id) <= 0) {
			return -1;
		}
		$before = (int) $event->percentage;
		if ($before === (int) $percent) {
			return $before;
		}
		$event->percentage = (int) $percent;
		// The update of the core: its triggers and its history, the attendees kept as they were read
		if ($event->update($user) < 0) {
			dol_syslog('InboxAgendaPanel::setPercent '.$event->error, LOG_ERR);
			return -2;
		}

		return $before;
	}
}
