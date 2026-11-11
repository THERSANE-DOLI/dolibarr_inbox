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
 * \file    inbox/class/inboxretention.class.php
 * \ingroup inbox
 * \brief   Trash and junk emptied after some days, per mailbox (1.41.0)
 */

require_once __DIR__.'/inboxaccount.class.php';
require_once __DIR__.'/inboxfolder.class.php';
require_once __DIR__.'/inboxmail.class.php';
require_once __DIR__.'/inboxactionlog.class.php';
require_once __DIR__.'/connector/inboxconnectorfactory.class.php';

/**
 * A mailbox may have its trash and its junk emptied: the mails there for more than N days removed for good on the
 * server, by the nightly job. The age is the time the mail is in the folder (date_folder: its arrival seen by the
 * synchronisation, or its move by inbox), never its date of reception. Nothing goes before N days after the setting
 * was turned on; the mails followed (star) stay.
 */
class InboxRetention
{
	/** Days offered (0: never) */
	const CHOICES = array(0, 7, 30, 60, 90);

	/** Roles concerned and their keys in the configuration of the mailbox */
	const ROLES = array('trash' => 'trash_days', 'junk' => 'junk_days');

	/** Mails removed per folder and per night at most (the next nights go on) */
	const BATCH = 500;

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
	 * Days of a mailbox for a role (0: never)
	 *
	 * @param InboxAccount $account Mailbox
	 * @param string       $role    trash or junk
	 * @return int
	 */
	public static function daysOf(InboxAccount $account, $role)
	{
		$config = $account->getConnectorConfig();
		$days = (int) ($config[self::ROLES[$role] ?? ''] ?? 0);

		return in_array($days, self::CHOICES, true) ? $days : 0;
	}

	/**
	 * Settings of a mailbox changed: a delay turned on (or shortened) counts from now
	 *
	 * @param InboxAccount $account Mailbox
	 * @param int          $trash   Days for the trash
	 * @param int          $junk    Days for the junk
	 * @return bool false when a value is not offered
	 */
	public static function set(InboxAccount $account, $trash, $junk)
	{
		$config = $account->getConnectorConfig();
		foreach (array('trash' => (int) $trash, 'junk' => (int) $junk) as $role => $days) {
			if (!in_array($days, self::CHOICES, true)) {
				return false;
			}
			$key = self::ROLES[$role];
			$before = (int) ($config[$key] ?? 0);
			if ($days > 0 && ($before === 0 || $days < $before)) {
				$config[$key.'_since'] = dol_now();
			}
			$config[$key] = $days;
		}
		$account->setConnectorConfig($config);

		return true;
	}

	/**
	 * Nightly job: every active mailbox of the entity with a delay
	 *
	 * @param User $user User of the job (author of the lines of the journal)
	 * @return int Mails removed
	 */
	public function run(User $user)
	{
		global $conf;

		if (!InboxMail::hasDateFolder($this->db)) {
			return 0;
		}
		$removed = 0;
		$rows = $this->db->getRows("SELECT rowid FROM ".$this->db->prefix()."inbox_account WHERE entity = ".((int) $conf->entity)." AND status = ".InboxAccount::STATUS_ACTIVE." AND protected = 0 LIMIT 10000");
		foreach (is_array($rows) ? $rows : array() as $r) {
			$account = new InboxAccount($this->db);
			if ($account->fetch((int) $r->rowid) <= 0) {
				continue;
			}
			$n = $this->purgeAccount($account, $user);
			$removed += max(0, $n);
		}

		return $removed;
	}

	/**
	 * The trash and the junk of a mailbox emptied of their old mails
	 *
	 * @param InboxAccount $account Mailbox
	 * @param User         $user    Author of the lines of the journal
	 * @return int Mails removed, < 0 on error
	 */
	public function purgeAccount(InboxAccount $account, User $user)
	{
		$p = $this->db->prefix();
		$config = $account->getConnectorConfig();
		$connector = null;
		$removed = 0;
		foreach (self::ROLES as $role => $key) {
			$days = self::daysOf($account, $role);
			if ($days <= 0) {
				continue;
			}
			$cutoff = dol_now() - $days * 86400;
			// Nothing before N days after the delay was set: the mails already there count from that day
			if ((int) ($config[$key.'_since'] ?? 0) > $cutoff) {
				continue;
			}
			$folder = $this->db->getRow("SELECT rowid, path FROM ".$p."inbox_folder WHERE fk_account = ".((int) $account->id)." AND role = '".$this->db->escape($role)."'"
				." AND status = ".InboxFolder::STATUS_PRESENT." ORDER BY rowid");
			if (!is_object($folder)) {
				continue;
			}
			// Mails indexed before 1.41.0: in the folder from now
			$this->db->query("UPDATE ".$p."inbox_mail SET date_folder = '".$this->db->idate(dol_now())."' WHERE fk_folder = ".((int) $folder->rowid)." AND date_folder IS NULL");
			$old = $this->db->getRows("SELECT remote_id FROM ".$p."inbox_mail WHERE fk_folder = ".((int) $folder->rowid)." AND flagged = 0"
				." AND date_folder < '".$this->db->idate($cutoff)."' ORDER BY date_folder LIMIT ".self::BATCH);
			$ids = array_map(function ($o) {
				return (string) $o->remote_id;
			}, is_array($old) ? $old : array());
			if (!$ids) {
				continue;
			}
			if ($connector === null) {
				$error = '';
				$connector = InboxConnectorFactory::forAccount($this->db, $account, $error);
				if (!$connector || !$connector->connect()) {
					$this->error = $connector ? $connector->getError() : $error;
					return -1;
				}
			}
			if (!$connector->deleteMails((string) $folder->path, $ids)) {
				$this->error = $connector->getError();
				dol_syslog(__METHOD__.' '.$account->email.' '.$folder->path.': '.$this->error, LOG_WARNING);
				continue;
			}
			(new InboxMail($this->db))->removeMany((int) $folder->rowid, $ids);
			InboxActionLog::add($this->db, $user, (int) $account->id, 'auto_purge', array((object) array('subject' => '', 'nb' => count($ids))), (string) $folder->path, '', 0, $role.' '.$days);
			$removed += count($ids);
		}
		if ($connector) {
			$connector->disconnect();
		}

		return $removed;
	}
}
