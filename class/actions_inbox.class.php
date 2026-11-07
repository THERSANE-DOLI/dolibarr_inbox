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
 * \file    inbox/class/actions_inbox.class.php
 * \ingroup inbox
 * \brief   Hooks of inbox in the pages of Dolibarr
 */

/**
 * Hooks of inbox
 */
class ActionsInbox
{
	/** @var DoliDB */
	public $db;
	/** @var string */
	public $error = '';
	/** @var string[] */
	public $errors = array();
	/** @var array<string,mixed> */
	public $results = array();
	/** @var string */
	public $resprints = '';

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Setup > Emails > Templates: the type of the templates of inbox (lot W6 1.18.0), so they are written there too
	 *
	 * @param array<string,mixed> $parameters Parameters (elementList)
	 * @param object|null         $object     Unused
	 * @param string              $action     Unused
	 * @param HookManager         $hookmanager Hook manager
	 * @return int 0
	 */
	public function emailElementlist($parameters, &$object, &$action, $hookmanager)
	{
		global $langs, $user;

		if (!isModEnabled('inbox') || !$user->hasRight('inbox', 'webmail', 'use')) {
			return 0;
		}
		$langs->loadLangs(array('inbox@inbox'));
		$this->results['inbox_send'] = img_picto('', 'fa-inbox', 'class="pictofixedwidth"').dol_escape_htmltag($langs->trans('InboxTemplateType'));

		return 0;
	}
}
