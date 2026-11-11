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
 * \file    inbox/class/inboxserver.class.php
 * \ingroup inbox
 * \brief   Settings > Server of the application (1.54.0, admins of Dolibarr): keeping, IMAP timeouts, master user of
 *          the mail server, scheduled jobs of the module
 */

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';

/**
 * Settings of the server side of inbox. They were on the setup page of Dolibarr (tab Settings), which now points here.
 */
class InboxServer
{
	/** Scheduled jobs of the module that must run (the others only matter when their option is on) */
	const NEEDED_JOBS = array('InboxCronSync', 'InboxCronOutbox');

	/** @var DoliDB */
	protected $db;

	/** @var string Key of the last error (a translation key) */
	public $error = '';

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct(DoliDB $db)
	{
		$this->db = $db;
	}

	/**
	 * Numeric settings: constant => unit, min, max, default
	 *
	 * @return array<string,array{unit:string,min:int,max:int,default:int}>
	 */
	public static function numbers()
	{
		return array(
			'INBOX_CACHE_DAYS' => array('unit' => 'days', 'min' => 1, 'max' => 3650, 'default' => 30),
			'INBOX_LOG_DAYS' => array('unit' => 'days', 'min' => 1, 'max' => 3650, 'default' => 365),
			'INBOX_ACCOUNT_LOG_DAYS' => array('unit' => 'days', 'min' => 30, 'max' => 3650, 'default' => 730),
			'INBOX_IMAP_TIMEOUT' => array('unit' => 'seconds', 'min' => 5, 'max' => 120, 'default' => 20),
			'INBOX_TEST_TIMEOUT' => array('unit' => 'seconds', 'min' => 3, 'max' => 60, 'default' => 15),
		);
	}

	/**
	 * Everything the page shows; the master password never (only whether one is kept)
	 *
	 * @return array<string,mixed>
	 */
	public function get()
	{
		$numbers = array();
		foreach (self::numbers() as $name => $def) {
			$numbers[$name] = $def + array('value' => getDolGlobalInt($name, $def['default']));
		}

		return array(
			'numbers' => $numbers,
			'master' => array(
				'user' => getDolGlobalString('INBOX_MASTER_USER'),
				'host' => getDolGlobalString('INBOX_MASTER_HOST'),
				'separator' => getDolGlobalString('INBOX_MASTER_SEPARATOR', '*'),
				'password_kept' => getDolGlobalString('INBOX_MASTER_PASSWORD') !== '' ? 1 : 0,
			),
		);
	}

	/**
	 * One numeric setting
	 *
	 * @param string $name  Constant
	 * @param string $value Value typed
	 * @return bool
	 */
	public function setNumber($name, $value)
	{
		global $conf;

		$this->error = '';
		$numbers = self::numbers();
		$value = trim((string) $value);
		if (!isset($numbers[$name]) || !preg_match('/^\d{1,6}$/', $value) || (int) $value < $numbers[$name]['min'] || (int) $value > $numbers[$name]['max']) {
			$this->error = 'InboxErrorServerValue';
			return false;
		}

		return dolibarr_set_const($this->db, $name, (string) ((int) $value), 'chaine', 0, '', $conf->entity) > 0;
	}

	/**
	 * Master user of the mail server (Dovecot): password encrypted and never shown again; an empty password keeps the
	 * one kept, no user removes it
	 *
	 * @param string $masterUser User
	 * @param string $host       The only server the password is sent to (audit INBX-002)
	 * @param string $separator  Separator mailbox / master
	 * @param string $password   Password, '' to keep it
	 * @return bool
	 */
	public function setMaster($masterUser, $host, $separator, $password)
	{
		global $conf;

		$this->error = '';
		$masterUser = trim((string) $masterUser);
		$host = preg_replace('/[^a-z0-9.\-:\[\]]/', '', strtolower(trim((string) $host)));
		$separator = trim((string) $separator);
		if ($separator === '' || strlen($separator) > 2 || preg_match('/[\s"\']/', $separator)) {
			$this->error = 'InboxErrorMasterSeparator';
			return false;
		}
		if ($masterUser !== '' && strpos($masterUser, $separator) !== false) {
			$this->error = 'InboxErrorMasterUserSeparator';
			return false;
		}
		if (strlen($masterUser) > 255 || strlen((string) $password) > 1024) {
			$this->error = 'InboxErrorServerValue';
			return false;
		}
		// A master user would open the protected mailboxes of its server, in any entity (audit INBX-057)
		if ($masterUser !== '' && $host !== '' && is_object($this->db->getRow("SELECT rowid FROM ".$this->db->prefix()."inbox_account WHERE protected = 1"
			." AND LOWER(imap_host) = '".$this->db->escape($host)."'"))) {
			$this->error = 'InboxErrorMasterProtected';
			return false;
		}
		dolibarr_set_const($this->db, 'INBOX_MASTER_USER', $masterUser, 'chaine', 0, '', $conf->entity);
		dolibarr_set_const($this->db, 'INBOX_MASTER_HOST', $host, 'chaine', 0, '', $conf->entity);
		dolibarr_set_const($this->db, 'INBOX_MASTER_SEPARATOR', $separator, 'chaine', 0, '', $conf->entity);
		if ($masterUser === '') {
			dolibarr_del_const($this->db, 'INBOX_MASTER_PASSWORD', $conf->entity);
		} elseif ((string) $password !== '') {
			dolibarr_set_const($this->db, 'INBOX_MASTER_PASSWORD', dolEncrypt((string) $password), 'chaine', 0, '', $conf->entity);
		}

		return true;
	}

	/**
	 * Scheduled jobs of the module, as the page shows them: state, frequency, last and next run, link to their card
	 * (hook inboxCronJobUrl: another interface may give its own address of the card)
	 *
	 * @return array{enabled:int,jobs:array<int,array<string,mixed>>}
	 */
	public function cronJobs()
	{
		global $conf, $hookmanager, $langs;

		$rows = $this->db->getRows("SELECT rowid, label, status, frequency, unitfrequency, datelastrun, datelastresult, lastresult, lastoutput, datenextrun, processing"
			." FROM ".$this->db->prefix()."cronjob WHERE entity IN (0, ".((int) $conf->entity).") AND classesname LIKE '/inbox/class/%' ORDER BY priority ASC, rowid ASC LIMIT 50");
		if (is_object($hookmanager)) {
			$hookmanager->initHooks(array('inbox'));
		}
		$jobs = array();
		foreach (is_array($rows) ? $rows : array() as $r) {
			$url = DOL_URL_ROOT.'/cron/card.php?id='.((int) $r->rowid);
			if (is_object($hookmanager)) {
				$parameters = array('job' => $r, 'url' => $url);
				$action = 'view';
				$reshook = $hookmanager->executeHooks('inboxCronJobUrl', $parameters, $r, $action);
				if ($reshook > 0 && is_string($hookmanager->resPrint) && $hookmanager->resPrint !== '') {
					$url = $hookmanager->resPrint;
				}
				$hookmanager->resPrint = '';
			}
			$last = $r->datelastrun ? (int) $this->db->jdate($r->datelastrun) : 0;
			$ok = $r->lastresult === null || $r->lastresult === '' || (string) $r->lastresult === '0';
			$jobs[] = array(
				'id' => (int) $r->rowid,
				'key' => (string) $r->label,
				'label' => $langs->transnoentities((string) $r->label),
				'active' => (int) $r->status === 1 ? 1 : 0,
				'needed' => in_array((string) $r->label, self::NEEDED_JOBS, true) ? 1 : 0,
				'every' => (int) $r->frequency * (int) $r->unitfrequency,
				'last' => $last,
				'last_ok' => $last ? ($ok ? 1 : 0) : null,
				// Only when it failed, short: the output of a job may be long
				'last_output' => $last && !$ok ? dol_trunc(dol_string_nohtmltag((string) $r->lastoutput, 1), 300, 'right', 'UTF-8', 1) : '',
				'next' => $r->datenextrun ? (int) $this->db->jdate($r->datenextrun) : 0,
				'running' => (int) $r->processing ? 1 : 0,
				'url' => $url,
				// Late: active and not run since three of its periods (and more than 15 minutes)
				'late' => (int) $r->status === 1 && (!$last || dol_now() - $last > max(900, 3 * (int) $r->frequency * (int) $r->unitfrequency)) ? 1 : 0,
			);
		}

		return array('enabled' => isModEnabled('cron') ? 1 : 0, 'jobs' => $jobs);
	}

	/**
	 * A scheduled job of the module on or off
	 *
	 * @param int  $id Job
	 * @param bool $on On
	 * @return bool False when it is not a job of inbox
	 */
	public function setJobActive($id, $on)
	{
		global $conf;

		$this->error = '';
		$p = $this->db->prefix();
		$row = $this->db->getRow("SELECT rowid FROM ".$p."cronjob WHERE rowid = ".((int) $id)." AND entity IN (0, ".((int) $conf->entity).") AND classesname LIKE '/inbox/class/%'");
		if (!is_object($row)) {
			$this->error = 'InboxErrorNotFound';
			return false;
		}

		return (bool) $this->db->query("UPDATE ".$p."cronjob SET status = ".($on ? 1 : 0)." WHERE rowid = ".((int) $row->rowid));
	}
}
