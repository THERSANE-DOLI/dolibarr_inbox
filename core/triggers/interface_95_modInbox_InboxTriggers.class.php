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
	 * A user or a group deleted: what inbox kept for it is removed (settings, accesses, images allowed)
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
		if ($action === 'USER_DELETE' || $action === 'USERGROUP_DELETE') {
			return $this->forget($action === 'USER_DELETE' ? 'fk_user' : 'fk_usergroup', (int) $object->id, $user);
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
		// Mailboxes the group gave access to: its members may lose their assignments there (lot W4 1.9.0)
		$accounts = array();
		if ($column === 'fk_usergroup') {
			$rows = $this->db->getRows("SELECT DISTINCT fk_account FROM ".$this->db->prefix()."inbox_account_access WHERE fk_usergroup = ".((int) $id)." LIMIT 10000");
			foreach (is_array($rows) ? $rows : array() as $r) {
				$accounts[] = (int) $r->fk_account;
			}
		}
		$tables = $column === 'fk_user' ? array('inbox_userparam', 'inbox_account_access', 'inbox_image_allow', 'inbox_mail_assign', 'inbox_notification', 'inbox_thread_mark') : array('inbox_account_access');
		foreach ($tables as $table) {
			if (!$this->db->query("DELETE FROM ".$this->db->prefix().$table." WHERE ".$column." = ".((int) $id))) {
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
