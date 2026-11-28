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
 * \file    inbox/core/triggers/interface_95_modInbox_InboxTriggers.class.php
 * \ingroup inbox
 * \brief   Triggers of inbox: the thirdparty recognised on the indexed mails follows the contacts and thirdparties
 */

require_once DOL_DOCUMENT_ROOT.'/core/triggers/dolibarrtriggers.class.php';

/**
 * Class InterfaceInboxTriggers
 */
class InterfaceInboxTriggers extends DolibarrTriggers
{
	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		parent::__construct($db);
		$this->family = 'crm';
		$this->description = 'Inbox triggers: thirdparty recognised on the indexed mails';
		$this->version = self::VERSIONS['prod'];
		$this->picto = 'fa-inbox';
	}

	/**
	 * A contact or a thirdparty created, changed or deleted: the mails of its address (old and new) are matched again.
	 * A user or a group deleted: what inbox kept for it is removed (settings, accesses, images allowed). A logout locks
	 * the protected mailboxes
	 *
	 * @param string    $action Code
	 * @param Object    $object Object
	 * @param User      $user   User
	 * @param Translate $langs  Languages
	 * @param Conf      $conf   Setup
	 * @return int             0
	 */
	public function runTrigger($action, $object, User $user, Translate $langs, Conf $conf)
	{
		if (!isModEnabled('inbox')) {
			return 0;
		}
		// Logout: the protected mailboxes unlocked are locked, their cookie removed (the session goes anyway, the cookie
		// stayed until its end)
		if ($action === 'USER_LOGOUT') {
			dol_include_once('/inbox/class/inboxprotect.class.php');
			InboxProtect::lockAll();
			return 0;
		}
		// Protected mailboxes (1.89.0): a user disabled loses his devices, so the encrypted copies of the passwords
		// setstatus() calls the trigger before it changes the object: the database tells
		$disabled = false;
		if ($action === 'USER_ENABLEDISABLE' && !empty($object->id)) {
			$row = $this->db->getRow("SELECT statut FROM ".$this->db->prefix()."user WHERE rowid = ".((int) $object->id));
			$disabled = is_object($row) && (int) $row->statut === 0;
		}
		if (($disabled || $action === 'USER_DELETE') && !empty($object->id)) {
			dol_include_once('/inbox/class/inboxprotect.class.php');
			(new InboxProtect($this->db))->revokeUser($user, (int) $object->id);
		}
		if ($action === 'USER_DELETE' || $action === 'USERGROUP_DELETE') {
			return $this->forget($action === 'USER_DELETE' ? 'fk_user' : 'fk_usergroup', (int) $object->id, $user);
		}
		// A piece created from a mail (« Create ▾ », 1.14.0): linked to it, the .eml in its documents when asked
		if (substr($action, -7) === '_CREATE' && !empty($_SESSION['inbox_create_intents'])) {
			dol_include_once('/inbox/class/inboxcreate.class.php');
			$creator = new InboxCreate($this->db);
			$creator->onCreate($user, $action, $object);
		}
		// A piece removed from Dolibarr: its links to mails go, the mails and their notes stay (1.13.0)
		$removed = array('COMPANY_DELETE' => 'societe', 'CONTACT_DELETE' => 'contact', 'PROPAL_DELETE' => 'propal', 'ORDER_DELETE' => 'commande', 'BILL_DELETE' => 'facture',
			'ORDER_SUPPLIER_DELETE' => 'order_supplier', 'BILL_SUPPLIER_DELETE' => 'invoice_supplier', 'SUPPLIER_PROPOSAL_DELETE' => 'supplier_proposal',
			'PROJECT_DELETE' => 'project', 'TICKET_DELETE' => 'ticket', 'FICHINTER_DELETE' => 'fichinter', 'SHIPPING_DELETE' => 'shipping');
		if (isset($removed[$action]) && !empty($object->id)) {
			dol_include_once('/inbox/class/inboxlink.class.php');
			$linker = new InboxLink($this->db);
			$linker->forgetElement($removed[$action], (int) $object->id);
		}
		// An object of a module declared as a type of piece (1.164.0): its trigger of deletion, the same
		if (!isset($removed[$action]) && substr($action, -7) === '_DELETE' && !empty($object->id)) {
			dol_include_once('/inbox/class/inboxelements.class.php');
			foreach (InboxElements::types() as $t => $def) {
				if (($def['delete'] ?? '') === $action) {
					dol_include_once('/inbox/class/inboxlink.class.php');
					(new InboxLink($this->db))->forgetElement($t, (int) $object->id);
				}
			}
		}
		if (!in_array($action, array('CONTACT_CREATE', 'CONTACT_MODIFY', 'CONTACT_DELETE', 'COMPANY_CREATE', 'COMPANY_MODIFY', 'COMPANY_DELETE'), true)) {
			return 0;
		}
		dol_include_once('/inbox/class/inboxcontactmatcher.class.php');
		$matcher = new InboxContactMatcher($this->db);
		$emails = array();
		if (!empty($object->email)) {
			$emails[] = (string) $object->email;
		}
		if (isset($object->oldcopy) && is_object($object->oldcopy) && !empty($object->oldcopy->email)) {
			$emails[] = (string) $object->oldcopy->email;
		}
		foreach (array_unique(array_map('strtolower', $emails)) as $email) {
			$matcher->refreshAddress($email);
			// Known senders changed: their mails of the junk are checked again by the next pass (1.15.0)
			$this->db->query("UPDATE ".$this->db->prefix()."inbox_mail SET junk_check = 0 WHERE from_email = '".$this->db->escape(trim($email))."'");
		}

		return 0;
	}

	/**
	 * Rows of inbox pointing to a user or a group deleted. The journals keep the id on purpose (history); a personal
	 * mailbox stays, without owner, for the admin to decide
	 *
	 * @param string    $column fk_user or fk_usergroup
	 * @param int       $id     User or group
	 * @param User|null $user   User deleting (author of the journal lines)
	 * @return int 0, -1 on error
	 */
	protected function forget($column, $id, $user = null)
	{
		if ($id <= 0) {
			return 0;
		}
		// Mailboxes the group gave access to: its members may lose their assignments there (1.9.0)
		$accounts = array();
		if ($column === 'fk_usergroup') {
			$rows = $this->db->getRows("SELECT DISTINCT fk_account FROM ".$this->db->prefix()."inbox_account_access WHERE fk_usergroup = ".((int) $id)." LIMIT 10000");
			foreach (is_array($rows) ? $rows : array() as $r) {
				$accounts[] = (int) $r->fk_account;
			}
		}
		$p = $this->db->prefix();
		// Views (1.31.0): the shares to the user or the group; the views of a user removed, with their shares, as
		// nobody could remove them any more and an id given again would inherit them (audit INBX-035)
		if ($column === 'fk_user' && !$this->db->query("DELETE FROM ".$p."inbox_view_share WHERE fk_view IN (SELECT rowid FROM ".$p."inbox_view WHERE fk_user = ".((int) $id).")")) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$tables = $column === 'fk_user' ? array('inbox_userparam', 'inbox_account_access', 'inbox_image_allow', 'inbox_mail_assign', 'inbox_notification', 'inbox_thread_mark', 'inbox_view_share', 'inbox_view')
			: array('inbox_account_access', 'inbox_view_share');
		foreach ($tables as $table) {
			if (!$this->db->query("DELETE FROM ".$p.$table." WHERE ".$column." = ".((int) $id))) {
				$this->error = $this->db->lasterror();
				return -1;
			}
		}
		if ($accounts && $user instanceof User) {
			dol_include_once('/inbox/class/inboxaccess.class.php');
			dol_include_once('/inbox/class/inboxassign.class.php');
			InboxAccess::clearCache();
			$assign = new InboxAssign($this->db);
			foreach ($accounts as $accountId) {
				$assign->prune($user, $accountId);
			}
		}

		return 0;
	}
}
