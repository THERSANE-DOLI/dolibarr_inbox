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
	 * A contact or a thirdparty created, changed or deleted: the mails of its address (old and new) are matched again
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
		if (!isModEnabled('inbox') || !in_array($action, array('CONTACT_CREATE', 'CONTACT_MODIFY', 'CONTACT_DELETE', 'COMPANY_CREATE', 'COMPANY_MODIFY', 'COMPANY_DELETE'), true)) {
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
}
