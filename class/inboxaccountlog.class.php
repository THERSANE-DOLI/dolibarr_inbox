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
 * \file    inbox/class/inboxaccountlog.class.php
 * \ingroup inbox
 * \brief   Journal of the changes of a mailbox
 */

/**
 * Journal of the changes of a mailbox: accesses given, changed or removed, server, password (only the fact that it
 * changed), status. Giving the access to a personal mailbox is a sensitive act, so it is always written here.
 * A value is never a password: secret keys are replaced by a mark before writing.
 */
class InboxAccountLog
{
	const ACTION_CREATE = 'create';
	const ACTION_SERVER = 'server';
	const ACTION_PASSWORD = 'password';
	const ACTION_STATUS = 'status';
	const ACTION_ACCESS_ADD = 'access_add';
	const ACTION_ACCESS_CHANGE = 'access_change';
	const ACTION_ACCESS_REMOVE = 'access_remove';

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
	 * Write a line
	 *
	 * @param int                      $accountId Mailbox
	 * @param User|null                $user      Author (null: system)
	 * @param string                   $action    One of the ACTION_ constants
	 * @param array<string,mixed>|null $before    Values before
	 * @param array<string,mixed>|null $after     Values after
	 * @return int                                Id of the line, < 0 if KO
	 */
	public function add($accountId, $user, $action, $before = null, $after = null)
	{
		global $conf;

		$sql = "INSERT INTO ".$this->db->prefix()."inbox_account_log (entity, fk_account, datec, fk_user, action, value_before, value_after)";
		$sql .= " VALUES (".((int) $conf->entity).", ".((int) $accountId).", '".$this->db->idate(dol_now())."',";
		$sql .= " ".(($user instanceof User && $user->id > 0) ? (int) $user->id : 'NULL').",";
		$sql .= " '".$this->db->escape(substr((string) $action, 0, 32))."',";
		$sql .= " ".self::sqlJson($this->db, $before).", ".self::sqlJson($this->db, $after).")";
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}

		return (int) $this->db->last_insert_id($this->db->prefix().'inbox_account_log');
	}

	/**
	 * Lines of a mailbox, most recent first
	 *
	 * @param int $accountId Mailbox
	 * @param int $limit     Number of lines
	 * @param int $offset    First line
	 * @return array<int,array{id:int,date:int,fk_user:int,user:string,action:string,before:array<string,mixed>,after:array<string,mixed>}>
	 */
	public function listForAccount($accountId, $limit = 50, $offset = 0)
	{
		global $conf;

		$out = array();
		$sql = "SELECT l.rowid, l.datec, l.fk_user, l.action, l.value_before, l.value_after, u.firstname, u.lastname, u.login";
		$sql .= " FROM ".$this->db->prefix()."inbox_account_log as l";
		$sql .= " LEFT JOIN ".$this->db->prefix()."user as u ON u.rowid = l.fk_user";
		$sql .= " WHERE l.entity = ".((int) $conf->entity)." AND l.fk_account = ".((int) $accountId);
		$sql .= " ORDER BY l.datec DESC, l.rowid DESC";
		$sql .= $this->db->plimit(max(1, min(500, (int) $limit)), max(0, (int) $offset));
		$rows = $this->db->getRows($sql);
		foreach (is_array($rows) ? $rows : array() as $row) {
			$name = trim($row->firstname.' '.$row->lastname);
			$before = self::decode($row->value_before);
			$after = self::decode($row->value_after);
			$out[] = array(
				'target' => $this->targetName($before + $after),
				'id' => (int) $row->rowid,
				'date' => (int) $this->db->jdate($row->datec),
				'fk_user' => (int) $row->fk_user,
				'user' => $name !== '' ? $name : (string) $row->login,
				'action' => (string) $row->action,
				'before' => $before,
				'after' => $after,
			);
		}

		return $out;
	}

	/**
	 * Name of the user or group an access line is about
	 *
	 * @param array<string,mixed> $values Values of the line
	 * @return string
	 */
	protected function targetName(array $values)
	{
		if (!empty($values['fk_user'])) {
			$row = $this->db->getRow("SELECT firstname, lastname, login FROM ".$this->db->prefix()."user WHERE rowid = ".((int) $values['fk_user']));
			return is_object($row) ? (trim($row->firstname.' '.$row->lastname) ?: (string) $row->login) : '#'.((int) $values['fk_user']);
		}
		if (!empty($values['fk_usergroup'])) {
			$row = $this->db->getRow("SELECT nom FROM ".$this->db->prefix()."usergroup WHERE rowid = ".((int) $values['fk_usergroup']));
			return is_object($row) ? (string) $row->nom : '#'.((int) $values['fk_usergroup']);
		}

		return '';
	}

	/**
	 * Remove the lines older than the retention (setup INBOX_ACCOUNT_LOG_DAYS, 730 days by default)
	 *
	 * @return int Number of lines removed, < 0 if KO
	 */
	public function purge()
	{
		global $conf;

		$days = max(30, getDolGlobalInt('INBOX_ACCOUNT_LOG_DAYS', 730));
		$sql = "DELETE FROM ".$this->db->prefix()."inbox_account_log WHERE entity = ".((int) $conf->entity);
		$sql .= " AND datec < '".$this->db->idate(dol_now() - $days * 86400)."'";
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}

		return (int) $this->db->affected_rows(true);
	}

	/**
	 * Values written as JSON, secrets replaced by a mark
	 *
	 * @param DoliDB                   $db     Database handler
	 * @param array<string,mixed>|null $values Values
	 * @return string                          SQL value
	 */
	protected static function sqlJson($db, $values)
	{
		if ($values === null) {
			return 'NULL';
		}

		return "'".$db->escape((string) json_encode(self::scrub($values)))."'";
	}

	/**
	 * @param array<mixed> $values Values
	 * @return array<mixed>        Same values, secret keys replaced by '***'
	 */
	public static function scrub(array $values)
	{
		foreach ($values as $key => $value) {
			if (is_array($value)) {
				$values[$key] = self::scrub($value);
			} elseif (preg_match('/pass|secret|token/i', (string) $key)) {
				$values[$key] = '***';
			}
		}

		return $values;
	}

	/**
	 * @param string|null $json JSON
	 * @return array<string,mixed>
	 */
	protected static function decode($json)
	{
		$data = ($json === null || $json === '') ? null : json_decode((string) $json, true);

		return is_array($data) ? $data : array();
	}
}
