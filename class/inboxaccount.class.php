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
 * \file    inbox/class/inboxaccount.class.php
 * \ingroup inbox
 * \brief   Mailboxes readable by inbox: the email accounts of crmclientconnector
 */

/**
 * Mailboxes readable by inbox. They live in crmclientconnector (email accounts, IMAP access set on their anti-spam
 * tab), inbox does not copy them. Only a mailbox declared there can be read.
 */
class InboxAccount
{
	/** @var DoliDB */
	protected $db;

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * @return bool crmclientconnector enabled with its IMAP classes
	 */
	public static function isAvailable()
	{
		return isModEnabled('crmclientconnector') && dol_buildpath('/crmclientconnector/class/antispamimap.class.php', 0) !== ''
			&& is_readable(dol_buildpath('/crmclientconnector/class/antispamimap.class.php', 0));
	}

	/**
	 * Email account of a mailbox
	 *
	 * @param string $mailbox Email address of the mailbox
	 * @return EmailAccount|null Null if the mailbox is not declared in crmclientconnector
	 */
	public function fetchByEmail($mailbox)
	{
		$mailbox = strtolower(trim((string) $mailbox));
		if (!self::isAvailable() || !isValidEmail($mailbox)) {
			return null;
		}
		dol_include_once('/crmclientconnector/class/emailaccount.class.php');
		$obj = $this->db->getRow("SELECT rowid FROM ".$this->db->prefix()."crmclientconnector_emailaccount"
			." WHERE LOWER(email_account) = '".$this->db->escape($mailbox)."' ORDER BY rowid");
		if (!is_object($obj) || !class_exists('EmailAccount')) {
			return null;
		}
		$account = new EmailAccount($this->db);
		if ($account->fetch((int) $obj->rowid) <= 0) {
			return null;
		}

		return $account;
	}

	/**
	 * Mailboxes declared in crmclientconnector
	 *
	 * @return array<int,array{id:int,email:string,label:string}>
	 */
	public function listMailboxes()
	{
		$out = array();
		if (!self::isAvailable()) {
			return $out;
		}
		$rows = $this->db->getRows("SELECT rowid, email_account, label FROM ".$this->db->prefix()."crmclientconnector_emailaccount"
			." ORDER BY email_account LIMIT 1000");
		foreach (is_array($rows) ? $rows : array() as $row) {
			$out[] = array('id' => (int) $row->rowid, 'email' => strtolower(trim((string) $row->email_account)), 'label' => (string) $row->label);
		}

		return $out;
	}
}
