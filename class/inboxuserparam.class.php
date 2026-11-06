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
 * \file    inbox/class/inboxuserparam.class.php
 * \ingroup inbox
 * \brief   Settings of a user, for all the mailboxes or for one
 */

/**
 * Settings of a user (Settings > My account), read in cascade: user + mailbox, then user, then the default of the
 * setup (constant INBOX_DEFAULT_<PARAM>), then the default of the code. Only the known settings are accepted, each
 * one with its check.
 */
class InboxUserParam
{
	/** @var DoliDB */
	protected $db;

	/** @var string */
	public $error = '';

	/** @var array<int,array<string,string>> Cache per user: "account:param" => value */
	protected static $cache = array();

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct(DoliDB $db)
	{
		$this->db = $db;
	}

	/**
	 * Known settings: name => array(default, check). Check: list of values, 'int:min:max', 'json'.
	 *
	 * @return array<string,array{0:string,1:string|string[]}>
	 */
	public static function definitions()
	{
		return array(
			// Mail marked as read: at once, after a delay, or only by hand (key M), lot W3
			'read_mode' => array('delay', array('immediate', 'delay', 'manual')),
			'read_delay' => array('3', 'int:0:600'),
			// Display (lot W3): table or cards for all the folders, as Thunderbird
			'view' => array('cards', array('table', 'cards')),
			'list_columns' => array('', 'json'),
			'pane_widths' => array('', 'json'),
			'density' => array('normal', array('compact', 'normal', 'comfortable')),
			'text_size' => array('100', 'int:90:150'),
			'theme' => array('auto', array('auto', 'light', 'dark', 'contrast')),
			'attachments_open' => array('0', 'int:0:1'),
		);
	}

	/**
	 * @param string $param Setting
	 * @param string $value Value
	 * @return bool
	 */
	public static function isValid($param, $value)
	{
		$defs = self::definitions();
		if (!isset($defs[$param])) {
			return false;
		}
		$check = $defs[$param][1];
		$value = (string) $value;
		if (is_array($check)) {
			return in_array($value, $check, true);
		}
		if (strpos($check, 'int:') === 0) {
			list(, $min, $max) = explode(':', $check);
			return preg_match('/^-?\d+$/', $value) && (int) $value >= (int) $min && (int) $value <= (int) $max;
		}
		if ($check === 'json') {
			return $value === '' || (strlen($value) <= 4000 && is_array(json_decode($value, true)));
		}

		return false;
	}

	/**
	 * Value of a setting for a user (and a mailbox)
	 *
	 * @param User   $user      User
	 * @param string $param     Setting
	 * @param int    $accountId Mailbox, 0 = all
	 * @return string            Value ('' for an unknown setting)
	 */
	public function get(User $user, $param, $accountId = 0)
	{
		$defs = self::definitions();
		if (!isset($defs[$param])) {
			return '';
		}
		$values = $this->load($user);
		if ((int) $accountId > 0 && isset($values[((int) $accountId).':'.$param])) {
			return $values[((int) $accountId).':'.$param];
		}
		if (isset($values['0:'.$param])) {
			return $values['0:'.$param];
		}
		$global = getDolGlobalString('INBOX_DEFAULT_'.strtoupper($param));
		if ($global !== '' && self::isValid($param, $global)) {
			return $global;
		}

		return $defs[$param][0];
	}

	/**
	 * @param User $user User
	 * @return array<string,string> "account:param" => value
	 */
	protected function load(User $user)
	{
		$uid = (int) $user->id;
		if (isset(self::$cache[$uid])) {
			return self::$cache[$uid];
		}
		$values = array();
		$rows = $this->db->getRows("SELECT fk_account, param, value FROM ".$this->db->prefix()."inbox_userparam WHERE fk_user = ".$uid." LIMIT 10000");
		foreach (is_array($rows) ? $rows : array() as $row) {
			$values[((int) $row->fk_account).':'.$row->param] = (string) $row->value;
		}
		self::$cache[$uid] = $values;

		return $values;
	}

	/**
	 * Set a setting (replay safe: update or insert)
	 *
	 * @param User   $user      User
	 * @param string $param     Setting
	 * @param string $value     Value
	 * @param int    $accountId Mailbox, 0 = all
	 * @return int               > 0 if OK, < 0 if KO
	 */
	public function set(User $user, $param, $value, $accountId = 0)
	{
		if (!self::isValid($param, $value)) {
			$this->error = 'InboxErrorUserParam';
			return -1;
		}
		$prefix = $this->db->prefix();
		$where = " WHERE fk_user = ".((int) $user->id)." AND fk_account = ".((int) $accountId)." AND param = '".$this->db->escape($param)."'";
		$exists = $this->db->getRow("SELECT rowid FROM ".$prefix."inbox_userparam".$where);
		if (is_object($exists)) {
			$ok = $this->db->query("UPDATE ".$prefix."inbox_userparam SET value = '".$this->db->escape((string) $value)."'".$where);
		} else {
			$ok = $this->db->query("INSERT INTO ".$prefix."inbox_userparam (fk_user, fk_account, param, value) VALUES (".((int) $user->id).", ".((int) $accountId).", '".$this->db->escape($param)."', '".$this->db->escape((string) $value)."')");
		}
		unset(self::$cache[(int) $user->id]);
		if (!$ok) {
			$this->error = $this->db->lasterror();
			return -2;
		}

		return 1;
	}

	/**
	 * Remove a setting (back to the next level of the cascade)
	 *
	 * @param User   $user      User
	 * @param string $param     Setting
	 * @param int    $accountId Mailbox, 0 = all
	 * @return int               > 0 if OK
	 */
	public function remove(User $user, $param, $accountId = 0)
	{
		unset(self::$cache[(int) $user->id]);
		$ok = $this->db->query("DELETE FROM ".$this->db->prefix()."inbox_userparam WHERE fk_user = ".((int) $user->id)
			." AND fk_account = ".((int) $accountId)." AND param = '".$this->db->escape((string) $param)."'");

		return $ok ? 1 : -1;
	}

	/**
	 * Every setting of a user, with its effective value (for the screen My account and the JS context)
	 *
	 * @param User $user      User
	 * @param int  $accountId Mailbox, 0 = all
	 * @return array<string,string>
	 */
	public function getAll(User $user, $accountId = 0)
	{
		$out = array();
		foreach (array_keys(self::definitions()) as $param) {
			$out[$param] = $this->get($user, $param, $accountId);
		}

		return $out;
	}
}
