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
 * \file    inbox/class/inboxconsumer.class.php
 * \ingroup inbox
 * \brief   Modules subscribed to the mailboxes (1.112.0): what the modules declare, what the managers of a mailbox
 *          allow them, the switch of the whole instance
 */

require_once __DIR__.'/inboxaccountlog.class.php';
require_once __DIR__.'/inboxaccount.class.php';

/**
 * A module that wants to react to the mails of a mailbox (create a ticket at the arrival, sort, link to a record)
 * declares itself with the hook inboxConsumers (context "inbox"): its method inboxConsumers() puts in $this->results,
 * keyed by its code, array('module' => rights class of the module, 'label' => translation key, 'purpose' => translation
 * key of one sentence, 'langs' => 'mymodule@mymodule', 'class' => class name, 'file' => path for dol_include_once,
 * 'events' => array('mail.received'), 'actions' => array('read', 'tag', ...)), and returns 0. The class implements
 * InboxConsumerInterface.
 *
 * Nothing runs until a manager of the mailbox allows it (section Modules of the mailbox), and only what was ticked.
 * This is not a security barrier: a module is PHP with every right on the base. It is a control of what runs
 * automatically on a mailbox, seen by its managers and written in its journal (analyse-modules-abonnes.md §1.2).
 */
class InboxConsumer
{
	/** Events a module may receive */
	const EVENTS = array('mail.received');

	/**
	 * Actions a module may be allowed. Never for a module: delete for good, empty the trash, folders, accesses, rules,
	 * identities. « send » (a mail sent by the module, nobody reading it first) comes with the guards against loops
	 */
	const ACTIONS = array('read', 'seen', 'tag', 'move', 'link', 'draft');

	const MODE_TEST = 'test';
	const MODE_ACTIVE = 'active';
	const MODES = array(self::MODE_TEST, self::MODE_ACTIVE);

	/** Why a subscription is paused */
	const PAUSE_MANAGER = 'manager';
	const PAUSE_PROTECTED = 'protected';

	const LOG_GRANT = 'consumer_grant';
	const LOG_CHANGE = 'consumer_change';
	const LOG_PAUSE = 'consumer_pause';
	const LOG_RESUME = 'consumer_resume';
	const LOG_REVOKE = 'consumer_revoke';
	const LOG_SUSPEND = 'consumer_suspend';

	/** Most folders a subscription may be limited to */
	const MAX_FOLDERS = 200;

	/** @var array<string,array<string,mixed>>|null Declarations of the modules, by code */
	protected static $declared = null;

	/** @var DoliDB */
	protected $db;

	/** @var string Translation key of the last error */
	public $error = '';

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct(DoliDB $db)
	{
		$this->db = $db;
	}

	/**
	 * The modules may subscribe to the mailboxes on this instance (Setup of the module inbox, off by default)
	 *
	 * @return bool
	 */
	public static function enabled()
	{
		return getDolGlobalInt('INBOX_CONSUMERS_ENABLED') === 1;
	}

	/**
	 * Declarations of the modules enabled, by code
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function declared()
	{
		global $hookmanager;

		if (self::$declared !== null) {
			return self::$declared;
		}
		if (!is_object($hookmanager)) {
			include_once DOL_DOCUMENT_ROOT.'/core/class/hookmanager.class.php';
			$hookmanager = new HookManager($GLOBALS['db']);
		}
		$hookmanager->initHooks(array('inbox'));
		$parameters = array();
		$action = '';
		$object = null;
		$reshook = $hookmanager->executeHooks('inboxConsumers', $parameters, $object, $action);
		self::$declared = self::normalize($reshook >= 0 && is_array($hookmanager->resArray) ? $hookmanager->resArray : array());

		return self::$declared;
	}

	/**
	 * Declarations as given by the hook, cleaned: a wrong one is left out (logged), never half kept
	 *
	 * @param array<mixed,mixed> $raw Code => declaration
	 * @return array<string,array<string,mixed>>
	 */
	public static function normalize(array $raw)
	{
		global $langs;

		$out = array();
		foreach ($raw as $code => $d) {
			$code = (string) $code;
			if (!preg_match('/^[a-z][a-z0-9_]{1,63}$/', $code) || !is_array($d) || empty($d['class']) || empty($d['file']) || empty($d['module'])
				|| !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string) $d['class']) || !preg_match('/^[a-z0-9_]+$/i', (string) $d['module'])) {
				dol_syslog(__METHOD__.' declaration of a consumer left out: '.$code, LOG_WARNING);
				continue;
			}
			$events = array_values(array_intersect(self::EVENTS, isset($d['events']) && is_array($d['events']) ? array_map('strval', $d['events']) : array()));
			$actions = array_values(array_intersect(self::ACTIONS, isset($d['actions']) && is_array($d['actions']) ? array_map('strval', $d['actions']) : array()));
			if (!$events) {
				dol_syslog(__METHOD__.' consumer '.$code.' asks no known event', LOG_WARNING);
				continue;
			}
			if (!empty($d['langs']) && is_object($langs)) {
				$langs->load((string) $d['langs']);
			}
			$label = isset($d['label']) ? (string) $d['label'] : $code;
			$purpose = isset($d['purpose']) ? (string) $d['purpose'] : '';
			$out[$code] = array(
				'code' => $code,
				'module' => strtolower((string) $d['module']),
				'class' => (string) $d['class'],
				'file' => (string) $d['file'],
				'label' => is_object($langs) ? $langs->transnoentities($label) : $label,
				'purpose' => $purpose !== '' && is_object($langs) ? $langs->transnoentities($purpose) : $purpose,
				'events' => $events,
				'actions' => $actions,
			);
		}

		return $out;
	}

	/**
	 * Declarations used instead of the hook (tests), null to read the hook again
	 *
	 * @param array<mixed,mixed>|null $raw Code => declaration, as given by the hook
	 * @return void
	 */
	public static function useDeclarations($raw)
	{
		self::$declared = $raw === null ? null : self::normalize($raw);
	}

	/**
	 * A JSON list of the base read back
	 *
	 * @param string|null $json JSON
	 * @return array<int,mixed>
	 */
	protected static function jsonList($json)
	{
		$v = json_decode((string) $json, true);

		return is_array($v) ? array_values($v) : array();
	}

	/**
	 * @param array<int,mixed> $list List
	 * @return string SQL value
	 */
	protected function sqlJson(array $list)
	{
		return "'".$this->db->escape(json_encode(array_values($list)))."'";
	}

	/**
	 * Subscriptions of a mailbox, by code
	 *
	 * @param int $accountId Mailbox
	 * @return array<string,array<string,mixed>>
	 */
	public function grants($accountId)
	{
		$rows = $this->db->getRows("SELECT rowid, code, events, actions, folders, requested, mode, paused, pause_reason, last_ok, last_error, errors_in_row, date_creation, fk_user_creat, tms"
			." FROM ".$this->db->prefix()."inbox_consumer WHERE fk_account = ".((int) $accountId)." ORDER BY code LIMIT 500");
		$out = array();
		foreach (is_array($rows) ? $rows : array() as $r) {
			$requested = json_decode((string) $r->requested, true);
			$out[(string) $r->code] = array(
				'id' => (int) $r->rowid,
				'events' => self::jsonList($r->events),
				'actions' => self::jsonList($r->actions),
				'folders' => array_map('intval', self::jsonList($r->folders)),
				'requested' => array('events' => is_array($requested) && isset($requested['events']) ? (array) $requested['events'] : array(),
					'actions' => is_array($requested) && isset($requested['actions']) ? (array) $requested['actions'] : array()),
				'mode' => in_array((string) $r->mode, self::MODES, true) ? (string) $r->mode : self::MODE_TEST,
				'paused' => (int) $r->paused === 1,
				'pause_reason' => (string) $r->pause_reason,
				'last_ok' => $r->last_ok ? (int) $this->db->jdate($r->last_ok) : null,
				'last_error' => (string) $r->last_error,
				'errors_in_row' => (int) $r->errors_in_row,
				'date' => (int) $this->db->jdate($r->date_creation),
				'fk_user' => (int) $r->fk_user_creat,
			);
		}

		return $out;
	}

	/**
	 * What a module asks and was not asked when the manager decided: shown as new, never granted by itself
	 *
	 * @param array<string,mixed> $declared Declaration
	 * @param array<string,mixed> $grant    Subscription
	 * @return array{events:string[],actions:string[]}
	 */
	public static function pending(array $declared, array $grant)
	{
		return array(
			'events' => array_values(array_diff($declared['events'], $grant['requested']['events'])),
			'actions' => array_values(array_diff($declared['actions'], $grant['requested']['actions'])),
		);
	}

	/**
	 * Why no module may subscribe to this mailbox, '' if they may
	 *
	 * @param InboxAccount $account Mailbox
	 * @return string Translation key
	 */
	public static function blockedReason(InboxAccount $account)
	{
		if (!self::enabled()) {
			return 'InboxConsumersOff';
		}
		// Protected with its content: nothing kept on the server, nothing can run in the background
		if ($account->isLive()) {
			return 'InboxConsumersLive';
		}

		return '';
	}

	/**
	 * Subscribe a module, or change what it is allowed: only what it declares, never on a mailbox where it cannot run
	 *
	 * @param User                $user    Manager of the mailbox (checked by the caller)
	 * @param InboxAccount        $account Mailbox
	 * @param string              $code    Code of the declaration
	 * @param array<string,mixed> $set     events, actions, folders (rowids, empty = whole mailbox), mode
	 * @return bool
	 */
	public function save(User $user, InboxAccount $account, $code, array $set)
	{
		global $conf;

		$this->error = '';
		$declared = self::declared();
		if (!isset($declared[$code])) {
			$this->error = 'InboxConsumerUnknown';
			return false;
		}
		$reason = self::blockedReason($account);
		if ($reason !== '') {
			$this->error = $reason;
			return false;
		}
		$d = $declared[$code];
		$events = array_values(array_intersect($d['events'], isset($set['events']) && is_array($set['events']) ? array_map('strval', $set['events']) : array()));
		$actions = array_values(array_intersect($d['actions'], isset($set['actions']) && is_array($set['actions']) ? array_map('strval', $set['actions']) : array()));
		if (!$events) {
			$this->error = 'InboxConsumerNoEvent';
			return false;
		}
		$mode = isset($set['mode']) && in_array((string) $set['mode'], self::MODES, true) ? (string) $set['mode'] : self::MODE_TEST;
		$folders = array();
		if (!empty($set['folders']) && is_array($set['folders'])) {
			$wanted = array_slice(array_unique(array_filter(array_map('intval', $set['folders']))), 0, self::MAX_FOLDERS);
			if ($wanted) {
				// Folders of this mailbox only
				$rows = $this->db->getRows("SELECT rowid FROM ".$this->db->prefix()."inbox_folder WHERE fk_account = ".((int) $account->id)." AND rowid IN (".implode(',', $wanted).") LIMIT ".self::MAX_FOLDERS);
				$folders = array_map(function ($r) {
					return (int) $r->rowid;
				}, is_array($rows) ? $rows : array());
				sort($folders);
			}
			if (!$folders) {
				$this->error = 'InboxConsumerNoFolder';
				return false;
			}
		}
		$requested = "'".$this->db->escape(json_encode(array('events' => $d['events'], 'actions' => $d['actions'])))."'";
		$grants = $this->grants((int) $account->id);
		$before = isset($grants[$code]) ? $grants[$code] : null;
		$after = array('events' => $events, 'actions' => $actions, 'folders' => $folders, 'mode' => $mode);
		$now = $this->db->idate(dol_now());
		if ($before === null) {
			$sql = "INSERT INTO ".$this->db->prefix()."inbox_consumer (entity, fk_account, code, events, actions, folders, requested, mode, paused, date_creation, fk_user_creat)"
				." VALUES (".((int) $conf->entity).", ".((int) $account->id).", '".$this->db->escape($code)."', ".$this->sqlJson($events).", ".$this->sqlJson($actions).","
				." ".$this->sqlJson($folders).", ".$requested.", '".$this->db->escape($mode)."', 0, '".$now."', ".((int) $user->id).")";
		} else {
			$sql = "UPDATE ".$this->db->prefix()."inbox_consumer SET events = ".$this->sqlJson($events).", actions = ".$this->sqlJson($actions).", folders = ".$this->sqlJson($folders).","
				." requested = ".$requested.", mode = '".$this->db->escape($mode)."', fk_user_modif = ".((int) $user->id)." WHERE rowid = ".((int) $before['id']);
		}
		if (!$this->db->query($sql)) {
			dol_syslog(__METHOD__.' '.$this->db->lasterror(), LOG_ERR);
			$this->error = 'InboxConsumerSaveError';
			return false;
		}
		$was = $before === null ? null : array('events' => $before['events'], 'actions' => $before['actions'], 'folders' => $before['folders'], 'mode' => $before['mode']);
		if ($was !== $after) {
			(new InboxAccountLog($this->db))->add((int) $account->id, $user, $before === null ? self::LOG_GRANT : self::LOG_CHANGE,
				$was === null ? null : $was + array('code' => $code, 'label' => $d['label']), $after + array('code' => $code, 'label' => $d['label']));
		}

		return true;
	}

	/**
	 * Pause a subscription, or let it run again (as it was set: test or active)
	 *
	 * @param User         $user    Manager of the mailbox (checked by the caller)
	 * @param InboxAccount $account Mailbox
	 * @param string       $code    Code of the declaration
	 * @param bool         $paused  Pause
	 * @return bool
	 */
	public function setPaused(User $user, InboxAccount $account, $code, $paused)
	{
		$this->error = '';
		$grants = $this->grants((int) $account->id);
		if (!isset($grants[$code])) {
			$this->error = 'InboxConsumerNotGranted';
			return false;
		}
		if (!$paused) {
			$reason = self::blockedReason($account);
			if ($reason !== '') {
				$this->error = $reason;
				return false;
			}
		}
		if ($grants[$code]['paused'] === (bool) $paused) {
			return true;
		}
		$sql = "UPDATE ".$this->db->prefix()."inbox_consumer SET paused = ".($paused ? 1 : 0).", pause_reason = ".($paused ? "'".self::PAUSE_MANAGER."'" : 'NULL').","
			." errors_in_row = 0, fk_user_modif = ".((int) $user->id)." WHERE rowid = ".((int) $grants[$code]['id']);
		if (!$this->db->query($sql)) {
			$this->error = 'InboxConsumerSaveError';
			return false;
		}
		(new InboxAccountLog($this->db))->add((int) $account->id, $user, $paused ? self::LOG_PAUSE : self::LOG_RESUME, null, array('code' => $code, 'label' => $this->labelOf($code)));

		return true;
	}

	/**
	 * Remove a subscription: the module receives nothing more from this mailbox
	 *
	 * @param User         $user    Manager of the mailbox (checked by the caller)
	 * @param InboxAccount $account Mailbox
	 * @param string       $code    Code of the declaration
	 * @return bool
	 */
	public function revoke(User $user, InboxAccount $account, $code)
	{
		$this->error = '';
		$grants = $this->grants((int) $account->id);
		if (!isset($grants[$code])) {
			return true;
		}
		if (!$this->db->query("DELETE FROM ".$this->db->prefix()."inbox_consumer WHERE rowid = ".((int) $grants[$code]['id']))) {
			$this->error = 'InboxConsumerSaveError';
			return false;
		}
		$g = $grants[$code];
		(new InboxAccountLog($this->db))->add((int) $account->id, $user, self::LOG_REVOKE,
			array('code' => $code, 'label' => $this->labelOf($code), 'events' => $g['events'], 'actions' => $g['actions'], 'folders' => $g['folders'], 'mode' => $g['mode']), null);

		return true;
	}

	/**
	 * Every subscription of a mailbox paused, because it is now protected with its content (nothing kept on the
	 * server, nothing can run in the background); written in its journal. Called inside the change of the level
	 *
	 * @param User|null $user      Who protected the mailbox
	 * @param int       $accountId Mailbox
	 * @return bool
	 */
	public function suspendForProtection($user, $accountId)
	{
		$rows = $this->db->getRows("SELECT code FROM ".$this->db->prefix()."inbox_consumer WHERE fk_account = ".((int) $accountId)." AND paused = 0 LIMIT 500");
		if (!is_array($rows) || !$rows) {
			return true;
		}
		if (!$this->db->query("UPDATE ".$this->db->prefix()."inbox_consumer SET paused = 1, pause_reason = '".self::PAUSE_PROTECTED."' WHERE fk_account = ".((int) $accountId)." AND paused = 0")) {
			return false;
		}
		$codes = array_map(function ($r) {
			return (string) $r->code;
		}, $rows);
		(new InboxAccountLog($this->db))->add((int) $accountId, $user, self::LOG_SUSPEND, null, array('codes' => $codes, 'labels' => array_map(array($this, 'labelOf'), $codes)));

		return true;
	}

	/**
	 * Name of a module subscribed, its code when it no longer declares itself (disabled, removed)
	 *
	 * @param string $code Code
	 * @return string
	 */
	public function labelOf($code)
	{
		$declared = self::declared();

		return isset($declared[$code]) ? (string) $declared[$code]['label'] : (string) $code;
	}

	/**
	 * Mailboxes where each module is subscribed, for the setup of the module: code => counts
	 *
	 * @return array<string,array{active:int,test:int,paused:int}>
	 */
	public function counts()
	{
		global $conf;

		$rows = $this->db->getRows("SELECT code, mode, paused, COUNT(rowid) as n FROM ".$this->db->prefix()."inbox_consumer WHERE entity = ".((int) $conf->entity)
			." GROUP BY code, mode, paused LIMIT 1000");
		$out = array();
		foreach (is_array($rows) ? $rows : array() as $r) {
			$c = (string) $r->code;
			if (!isset($out[$c])) {
				$out[$c] = array('active' => 0, 'test' => 0, 'paused' => 0);
			}
			$k = (int) $r->paused === 1 ? 'paused' : ((string) $r->mode === self::MODE_ACTIVE ? 'active' : 'test');
			$out[$c][$k] += (int) $r->n;
		}

		return $out;
	}
}
