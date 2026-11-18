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
 * \file    inbox/class/inboxreactivity.class.php
 * \ingroup inbox
 * \brief   Settings of the refresh of the lists and of the push service (Settings > Server of the application)
 */

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once __DIR__.'/inboxevent.class.php';
require_once __DIR__.'/inboxpush.class.php';

/**
 * Refresh of the lists (1.122.0) and push service (1.124.0), set by an administrator of Dolibarr in Settings > Server.
 * Global settings (entity 0): they are about the load of the server, whatever the entity. Secrets of the drivers
 * stored encrypted, never given back.
 */
class InboxReactivity
{
	/** Modes of INBOX_REACTIVITY */
	const MODES = array('standard', 'fast', 'instant');

	/** Measured on a development computer (doc/admin/reactivity.md): a light call, a full refresh of a list */
	const LIGHT_MS = 2;
	const FULL_MS = 20;

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
	 * The settings are the ones of the whole instance: its administrator only (not the administrator of one entity of
	 * a multicompany, audit INBX-093)
	 *
	 * @param User $user User
	 * @return bool
	 */
	public static function canSet(User $user)
	{
		// Without multicompany every administrator is the one of the instance (an administrator made after the
		// installation has entity 1); with it, the superadministrator only (entity 0), as in Dolibarr
		return !empty($user->admin) && (!isModEnabled('multicompany') || empty($user->entity));
	}

	/**
	 * @return string Mode of the settings
	 */
	public static function mode()
	{
		$mode = getDolGlobalString('INBOX_REACTIVITY');

		return in_array($mode, self::MODES, true) ? $mode : 'standard';
	}

	/**
	 * Users who may call: those who reach an active mailbox (owner, access of their own or of a group) and logged in
	 * during the last 7 days, all entities (the server is the same)
	 *
	 * @return int
	 */
	public function activeUsers()
	{
		$p = $this->db->prefix();
		$reach = "SELECT fk_user_owner AS u FROM ".$p."inbox_account WHERE status = 1 AND fk_user_owner > 0"
			." UNION SELECT aa.fk_user FROM ".$p."inbox_account_access as aa INNER JOIN ".$p."inbox_account as a ON a.rowid = aa.fk_account WHERE a.status = 1 AND aa.fk_user > 0"
			." UNION SELECT ug.fk_user FROM ".$p."usergroup_user as ug INNER JOIN ".$p."inbox_account_access as aa ON aa.fk_usergroup = ug.fk_usergroup INNER JOIN ".$p."inbox_account as a ON a.rowid = aa.fk_account WHERE a.status = 1";
		$row = $this->db->getRow("SELECT COUNT(DISTINCT u.rowid) AS n FROM ".$p."user as u INNER JOIN (".$reach.") as r ON r.u = u.rowid"
			." WHERE u.statut = 1 AND u.datelastlogin >= '".$this->db->idate(dol_now() - 7 * 86400)."'");

		return is_object($row) ? (int) $row->n : 0;
	}

	/**
	 * Everything the section needs: the mode, the rhythm, the users counted, the costs, the push service
	 *
	 * @return array<string,mixed>
	 */
	public function data()
	{
		return array(
			'mode' => self::mode(),
			'rhythm' => InboxEvent::rhythm(),
			'users' => $this->activeUsers(),
			'light_ms' => self::LIGHT_MS,
			'full_ms' => self::FULL_MS,
			'push' => $this->pushData(),
			// Instant can be chosen only with a push service tested with its present settings
			'push_ready' => (bool) InboxPush::ready(),
		);
	}

	/**
	 * Save the mode and the rhythm (the idle interval never quicker than the active one)
	 *
	 * @param string $mode     Mode
	 * @param int    $interval Active interval
	 * @param int    $idle     Idle interval
	 * @return array{mode:string,rhythm:array{interval:int,idle:int}}|null Null: instant refused ($this->error)
	 */
	public function save($mode, $interval, $idle)
	{
		global $langs;

		$this->error = '';
		$mode = in_array($mode, self::MODES, true) ? $mode : 'standard';
		// Instant only with a push service that passed its test with its present settings
		if ($mode === 'instant' && self::mode() !== 'instant' && !InboxPush::ready()) {
			$this->error = $langs->transnoentities('InboxReactivityInstantNeedsTest');
			return null;
		}
		$rhythm = InboxEvent::rhythm($interval, $idle);
		dolibarr_set_const($this->db, 'INBOX_REACTIVITY', $mode, 'chaine', 0, '', 0);
		dolibarr_set_const($this->db, 'INBOX_REACTIVITY_INTERVAL', (string) $rhythm['interval'], 'chaine', 0, '', 0);
		dolibarr_set_const($this->db, 'INBOX_REACTIVITY_IDLE', (string) $rhythm['idle'], 'chaine', 0, '', 0);
		InboxPush::reset();

		return array('mode' => $mode, 'rhythm' => $rhythm);
	}

	/**
	 * The drivers with their fields (a secret: only whether it is saved), the one chosen, its last failure
	 *
	 * @return array<string,mixed>
	 */
	public function pushData()
	{
		global $langs;

		$chosen = getDolGlobalString('INBOX_PUSH_DRIVER', 'mercure');
		$drivers = array();
		foreach (InboxPush::drivers() as $code => $desc) {
			$driver = InboxPush::make($code);
			if (!$driver) {
				continue;
			}
			$values = InboxPush::values($driver);
			$fields = array();
			foreach ($driver->fields() as $key => $f) {
				$secret = $f['type'] === 'secret';
				$fields[] = array(
					'key' => $key,
					'type' => $f['type'],
					'label' => $langs->transnoentities($f['label']),
					'help' => empty($f['help']) ? '' : $langs->transnoentities($f['help']),
					'required' => !empty($f['required']),
					'value' => $secret ? '' : $values[$key],
					'saved' => $secret ? $values[$key] !== '' : null,
				);
			}
			$driver->configure($values, InboxPush::namespaceId());
			$drivers[] = array('code' => $code, 'label' => $langs->transnoentities($desc['label']), 'doc' => $desc['doc'], 'fields' => $fields, 'configured' => $driver->isConfigured(),
				'tested' => $driver->isConfigured() && InboxPush::isTested($driver, $values));
		}
		if (!in_array($chosen, array_column($drivers, 'code'), true) && $drivers) {
			$chosen = $drivers[0]['code'];
		}

		return array('driver' => $chosen, 'drivers' => $drivers, 'last_error' => InboxPush::lastError());
	}

	/**
	 * Save the driver chosen and its settings; a secret left empty keeps the one saved
	 *
	 * @param string               $code   Driver
	 * @param array<string,string> $values Values typed
	 * @return bool                        False: $this->error (translation key and the label of the field)
	 */
	public function savePush($code, array $values)
	{
		global $langs;

		$this->error = '';
		$driver = InboxPush::make((string) $code);
		if (!$driver) {
			$this->error = $langs->transnoentities('InboxPushUnknownDriver');
			return false;
		}
		// Every address checked before anything is saved: a refused one changes nothing
		foreach ($driver->fields() as $key => $f) {
			$v = trim((string) ($values[$key] ?? ''));
			if ($f['type'] === 'url' && $v !== '' && InboxPush::cleanUrl($v) === '') {
				$this->error = $langs->transnoentities('InboxPushBadUrl', $langs->transnoentities($f['label']));
				return false;
			}
			if ($f['type'] !== 'secret' && dol_strlen($v) > 255) {
				$this->error = $langs->transnoentities('InboxPushTooLong', $langs->transnoentities($f['label']));
				return false;
			}
		}
		dolibarr_set_const($this->db, 'INBOX_PUSH_DRIVER', $driver->code(), 'chaine', 0, '', 0);
		foreach ($driver->fields() as $key => $f) {
			$const = InboxPush::constName($driver->code(), $key);
			$v = trim((string) ($values[$key] ?? ''));
			if ($f['type'] === 'secret') {
				if ($v !== '') {
					dolibarr_set_const($this->db, $const, dolEncrypt($v), 'chaine', 0, '', 0);
				}
				continue;
			}
			if ($v === '') {
				dolibarr_del_const($this->db, $const, 0);
			} else {
				dolibarr_set_const($this->db, $const, $v, 'chaine', 0, '', 0);
			}
		}
		InboxPush::clearError();
		InboxPush::reset();

		return true;
	}

	/**
	 * Test of the driver of the settings (the button of the section)
	 *
	 * @return array{ok:bool,message:string,ms:int}
	 */
	public function testPush()
	{
		global $langs;

		$driver = InboxPush::make(getDolGlobalString('INBOX_PUSH_DRIVER', 'mercure'));
		if (!$driver) {
			return array('ok' => false, 'message' => $langs->transnoentities('InboxPushUnknownDriver'), 'ms' => 0);
		}
		$values = InboxPush::values($driver);
		$driver->configure($values, InboxPush::namespaceId());
		$res = $driver->test();
		// Passed: these settings may be used (instant); failed: not any more, the fast rhythm until the next success
		if (!empty($res['ok'])) {
			dolibarr_set_const($this->db, 'INBOX_PUSH_TESTED', InboxPush::fingerprint($driver, $values), 'chaine', 0, '', 0);
		} else {
			dolibarr_del_const($this->db, 'INBOX_PUSH_TESTED', 0);
		}
		InboxPush::reset();
		// Plain text for the browser (a driver of another module may quote the answer of its service)
		$res['message'] = html_entity_decode(strip_tags((string) $res['message']), ENT_QUOTES, 'UTF-8');

		return $res;
	}
}
