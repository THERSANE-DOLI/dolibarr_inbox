<?php
/* Copyright (C) 2004-2018	Laurent Destailleur			<eldy@users.sourceforge.net>
 * Copyright (C) 2026		John BOTELLA
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * 	\defgroup   inbox     Module Inbox
 *  \brief      Mail foundation: one message read by its Message-ID, mails (.eml, .msg) parsed, raw cache
 *
 *  \file       htdocs/inbox/core/modules/modInbox.class.php
 *  \ingroup    inbox
 *  \brief      Description and activation file for module Inbox
 */
include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

/**
 *  Description and activation class for module Inbox
 */
class modInbox extends DolibarrModules
{
	/**
	 * Constructor. Define names, constants, directories, boxes, permissions
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		global $conf;

		$this->db = $db;

		$this->numero = 141930;
		$this->rights_class = 'inbox';
		$this->family = "interface";
		$this->module_position = '90';
		$this->name = preg_replace('/^mod/i', '', get_class($this));
		$this->description = "ModuleInboxDesc";
		$this->descriptionlong = "ModuleInboxDescLong";
		$this->editor_name = 'THERSANE';
		$this->editor_url = 'https://www.thersane.fr';
		$this->version = '1.0.2';
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
		$this->picto = 'fa-inbox';

		$this->module_parts = array();
		// Raw mails kept for a while (never served by document.php: no read right at the root of the module)
		$this->dirs = array('/inbox/raw');
		$this->config_page_url = array("setup.php@inbox");

		$this->hidden = false;
		// The mailboxes and their IMAP access live in crmclientconnector (optional: without it, only the mails given
		// as files are read)
		$this->depends = array();
		$this->requiredby = array();
		$this->conflictwith = array();
		$this->langfiles = array("inbox@inbox");
		$this->phpmin = array(7, 4);
		$this->need_dolibarr_version = array(23, -3);
		$this->need_javascript_ajax = 0;
		$this->warnings_activation = array();
		$this->warnings_activation_ext = array();

		$this->const = array(
			array('INBOX_CACHE_DAYS', 'chaine', '30', 'Days a raw mail read from a mailbox stays in the cache', 0, 'current', 0),
			array('INBOX_LOG_DAYS', 'chaine', '365', 'Days the journal of the readings is kept', 0, 'current', 0),
		);

		if (!isModEnabled("inbox")) {
			$conf->inbox = new stdClass();
			$conf->inbox->enabled = 0;
		}

		$this->tabs = array();
		$this->dictionaries = array();
		$this->boxes = array();

		$this->cronjobs = array(
			0 => array(
				'label' => 'InboxCronPurge',
				'jobtype' => 'method',
				'class' => '/inbox/class/inbox.class.php',
				'objectname' => 'Inbox',
				'method' => 'cronPurge',
				'parameters' => '',
				'comment' => 'InboxCronPurgeComment',
				'frequency' => 1,
				'unitfrequency' => 86400,
				'datestart' => strtotime('tomorrow 03:30'),
				'status' => 1,
				'test' => 'isModEnabled("inbox")',
				'priority' => 50,
			),
		);

		$this->rights = array();
		$r = 0;
		$this->rights[$r][0] = $this->numero.sprintf('%02d', $r + 1);
		$this->rights[$r][1] = 'InboxRightMessageRead';
		$this->rights[$r][4] = 'message';
		$this->rights[$r][5] = 'read';
		$r++;

		$this->menu = array();
	}

	/**
	 *  Function called when module is enabled.
	 *
	 *  @param      string  $options    Options when enabling module ('', 'noboxes')
	 *  @return     int<-1,1>          	1 if OK, <=0 if KO
	 */
	public function init($options = '')
	{
		$result = $this->_load_tables('/inbox/sql/');
		if ($result < 0) {
			return -1;
		}

		$this->remove($options);

		$sql = array();
		$result = $this->_init($sql, $options);

		// README of the cache directory (created by _init from $this->dirs)
		if ($result > 0) {
			dol_include_once('/inbox/class/inbox.class.php');
			if (is_dir(Inbox::storageDir())) {
				Inbox::writeReadme(Inbox::storageDir());
			}
		}

		return $result;
	}

	/**
	 *	Function called when module is disabled.
	 *
	 *	@param	string		$options	Options when enabling module ('', 'noboxes')
	 *	@return	int<-1,1>				1 if OK, <=0 if KO
	 */
	public function remove($options = '')
	{
		$sql = array();
		return $this->_remove($sql, $options);
	}
}
