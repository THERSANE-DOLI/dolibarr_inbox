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
		$this->version = '1.33.0';
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
		$this->picto = 'inbox@inbox';

		// Triggers: the recognised thirdparty of the indexed mails follows the contacts and thirdparties
		// Hooks: the type of the mail templates of inbox in Setup > Emails > Templates (lot W6 1.18.0), the unread
		// counter in the top bar of the other pages of Dolibarr (lot W8 1.21.0)
		$this->module_parts = array('triggers' => 1, 'hooks' => array('data' => array('emailtemplates', 'toprightmenu'), 'entity' => '0'));
		// Raw mails kept for a while (never served by document.php: no read right at the root of the module)
		$this->dirs = array('/inbox/raw');
		$this->config_page_url = array("setup.php@inbox");

		$this->hidden = false;
		// No dependency: other modules plug into inbox through its hooks and triggers, never the reverse
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
			array('INBOX_ACCOUNT_LOG_DAYS', 'chaine', '730', 'Days the journal of the changes of the mailboxes is kept', 0, 'current', 0),
		);

		if (!isModEnabled("inbox")) {
			$conf->inbox = new stdClass();
			$conf->inbox->enabled = 0;
		}

		// Tab « Mails » of the cards (lot W5 1.13.0): mails linked to the piece, and from / to the addresses of a thirdparty or a contact
		$this->tabs = array();
		$cond = 'isModEnabled("inbox") && $user->hasRight("inbox", "webmail", "use") && empty($user->socid)';
		foreach (array('thirdparty' => 'societe', 'contact' => 'contact', 'propal' => 'propal', 'order' => 'commande', 'invoice' => 'facture', 'supplier_order' => 'order_supplier',
			'supplier_invoice' => 'invoice_supplier', 'supplier_proposal' => 'supplier_proposal', 'project' => 'project', 'ticket' => 'ticket', 'intervention' => 'fichinter',
			'delivery' => 'shipping') as $tabType => $element) {
			$this->tabs[] = array('data' => $tabType.':+inboxmails:InboxMailsTab:inbox@inbox:'.$cond.':/inbox/mails.php?type='.$element.'&id=__ID__');
		}
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

		// Synchronisation of the index of the mailboxes (read only), every minute, within a time budget
		$this->cronjobs[] = array(
			'label' => 'InboxCronSync',
			'jobtype' => 'method',
			'class' => '/inbox/class/inboxsync.class.php',
			'objectname' => 'InboxSync',
			'method' => 'cronRun',
			'parameters' => '',
			'comment' => 'InboxCronSyncComment',
			'frequency' => 1,
			'unitfrequency' => 60,
			'status' => 1,
			'test' => 'isModEnabled("inbox")',
			'priority' => 40,
		);

		// Queue of the sending (lot W6 1.17.0): mails due after their delay or at their date, every minute (the page
		// sends them itself when it is open)
		$this->cronjobs[] = array(
			'label' => 'InboxCronOutbox',
			'jobtype' => 'method',
			'class' => '/inbox/class/inboxoutbox.class.php',
			'objectname' => 'InboxOutbox',
			'method' => 'cronRun',
			'parameters' => '',
			'comment' => 'InboxCronOutboxComment',
			'frequency' => 1,
			'unitfrequency' => 60,
			'status' => 1,
			'test' => 'isModEnabled("inbox")',
			'priority' => 30,
		);

		// Full text search (lot W7 1.20.0): the next mails of the mailboxes indexed sent to Meilisearch, every minute
		// (nothing done while the option is off)
		$this->cronjobs[] = array(
			'label' => 'InboxCronFulltext',
			'jobtype' => 'method',
			'class' => '/inbox/class/inboxfulltext.class.php',
			'objectname' => 'InboxFulltext',
			'method' => 'cronIndex',
			'parameters' => '',
			'comment' => 'InboxCronFulltextComment',
			'frequency' => 1,
			'unitfrequency' => 60,
			'status' => 1,
			'test' => 'isModEnabled("inbox")',
			'priority' => 60,
		);

		// Notifications (lot W8 1.21.0): those still unread after 15 minutes go by mail to the users who chose it
		$this->cronjobs[] = array(
			'label' => 'InboxCronNotifMail',
			'jobtype' => 'method',
			'class' => '/inbox/class/inboxnotification.class.php',
			'objectname' => 'InboxNotification',
			'method' => 'cronMail',
			'parameters' => '',
			'comment' => 'InboxCronNotifMailComment',
			'frequency' => 5,
			'unitfrequency' => 60,
			'status' => 1,
			'test' => 'isModEnabled("inbox")',
			'priority' => 70,
		);

		$this->rights = array();
		$r = 0;
		$this->rights[$r][0] = $this->numero.sprintf('%02d', $r + 1);
		$this->rights[$r][1] = 'InboxRightMessageRead';
		$this->rights[$r][4] = 'message';
		$this->rights[$r][5] = 'read';
		$r++;
		$this->rights[$r][0] = $this->numero.sprintf('%02d', $r + 1);
		$this->rights[$r][1] = 'InboxRightWebmailUse';
		$this->rights[$r][4] = 'webmail';
		$this->rights[$r][5] = 'use';
		$r++;
		// Managing the mailboxes never gives the reading of their mails (access per mailbox, llx_inbox_account_access)
		$this->rights[$r][0] = $this->numero.sprintf('%02d', $r + 1);
		$this->rights[$r][1] = 'InboxRightAccountAdmin';
		$this->rights[$r][4] = 'account';
		$this->rights[$r][5] = 'admin';
		$r++;

		$this->menu = array();
		// Top menu: opens the inbox application (full page, without the menus of Dolibarr)
		$this->menu[0] = array(
			'fk_menu' => '',
			'type' => 'top',
			'titre' => 'InboxAppName',
			'prefix' => img_picto('', 'fa-envelope', 'class="pictofixedwidth valignmiddle"'),
			'mainmenu' => 'inbox',
			'leftmenu' => '',
			'url' => '/inbox/index.php',
			'langs' => 'inbox@inbox',
			'position' => 1000,
			'enabled' => 'isModEnabled("inbox")',
			'perms' => '$user->hasRight("inbox", "webmail", "use")',
			'target' => '',
			'user' => 0,
		);
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
		if ($result > 0 && $this->installDefaultTags() < 0) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		if ($result > 0 && $this->migrate() < 0) {
			$this->error = $this->db->lasterror();
			return -1;
		}

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
	 *  Insert the 5 default tags of Thunderbird in the global list of the entity, once: the constant
	 *  INBOX_DEFAULT_TAGS_DONE (kept when the module is disabled) stops a reactivation from bringing back a tag the
	 *  admin removed, and the check per keyword never duplicates one.
	 *
	 *  @return int<-1,1>   1 if OK, -1 if KO
	 */
	private function installDefaultTags()
	{
		global $conf, $langs, $user;

		if (getDolGlobalString('INBOX_DEFAULT_TAGS_DONE')) {
			return 1;
		}
		require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
		$langs->load('inbox@inbox');
		$defaults = array(
			array('$label1', 'InboxTagImportant', '#FF0000'),
			array('$label2', 'InboxTagWork', '#FF9900'),
			array('$label3', 'InboxTagPersonal', '#009900'),
			array('$label4', 'InboxTagToDo', '#3333FF'),
			array('$label5', 'InboxTagLater', '#993399'),
		);
		$entity = (int) $conf->entity;
		$table = MAIN_DB_PREFIX.'inbox_tag';
		foreach ($defaults as $pos => $tag) {
			$sql = "SELECT rowid FROM ".$table." WHERE entity = ".$entity." AND fk_account = 0 AND keyword = '".$this->db->escape($tag[0])."'";
			$resql = $this->db->query($sql);
			if (!$resql) {
				return -1;
			}
			if ($this->db->num_rows($resql) > 0) {
				continue;
			}
			$sql = "INSERT INTO ".$table." (entity, fk_account, keyword, label, color, position, status, date_creation, fk_user_creat)";
			$sql .= " VALUES (".$entity.", 0, '".$this->db->escape($tag[0])."', '".$this->db->escape($langs->transnoentitiesnoconv($tag[1]))."',";
			$sql .= " '".$this->db->escape($tag[2])."', ".($pos + 1).", 1, '".$this->db->idate(dol_now())."', ".(empty($user->id) ? 'NULL' : (int) $user->id).")";
			if (!$this->db->query($sql)) {
				return -1;
			}
		}
		dolibarr_set_const($this->db, 'INBOX_DEFAULT_TAGS_DONE', '1', 'chaine', 0, 'Default tags of Thunderbird installed once', $entity);
		return 1;
	}

	/**
	 * Changes of data between versions, each one run once: the constant INBOX_SCHEMA_VERSION (kept when the module is
	 * disabled) says which ones were done, so that a reactivation never applies a change twice (a cap set on purpose
	 * by an admin after the migration is never reset).
	 *
	 * @return int<-1,1> 1 if OK, -1 if KO
	 */
	private function migrate()
	{
		global $conf;

		require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
		$done = getDolGlobalString('INBOX_SCHEMA_VERSION', '1.2.0');
		$prefix = $this->db->prefix();
		if (version_compare($done, '1.3.0', '<')) {
			// 1.3.0: the whole mailbox is indexed by default; the caps of 1.2.0 left at their default become « everything »
			$sql = "UPDATE ".$prefix."inbox_account SET sync_max_days = 0 WHERE sync_max_days = 365";
			$sql2 = "UPDATE ".$prefix."inbox_account SET sync_max_mails = 0 WHERE sync_max_mails = 20000";
			if (!$this->db->query($sql) || !$this->db->query($sql2)) {
				return -1;
			}
			$done = '1.3.0';
		}
		if (version_compare($done, '1.4.0', '<')) {
			// 1.4.0: texts of the index in composed form (NFC), so that a search typed « été » finds the subjects and
			// names sent with combining accents (bytes CC and CD in UTF-8: U+0300 to U+036F)
			if (class_exists('Normalizer')) {
				$sql = "SELECT rowid, subject, from_name FROM ".$prefix."inbox_mail WHERE INSTR(BINARY CONCAT(COALESCE(subject, ''), COALESCE(from_name, '')), UNHEX('CC')) > 0"
					." OR INSTR(BINARY CONCAT(COALESCE(subject, ''), COALESCE(from_name, '')), UNHEX('CD')) > 0";
				$res = $this->db->query($sql);
				$rows = array();
				while ($res && ($o = $this->db->fetch_object($res))) {
					$rows[] = $o;
				}
				foreach ($rows as $o) {
					$subject = Normalizer::normalize((string) $o->subject, Normalizer::FORM_C);
					$name = Normalizer::normalize((string) $o->from_name, Normalizer::FORM_C);
					$this->db->query("UPDATE ".$prefix."inbox_mail SET subject = ".($o->subject === null ? "NULL" : "'".$this->db->escape((string) $subject)."'")
						.", from_name = ".($o->from_name === null ? "NULL" : "'".$this->db->escape((string) $name)."'")." WHERE rowid = ".((int) $o->rowid));
				}
			}
			$done = '1.4.0';
		}
		dolibarr_set_const($this->db, 'INBOX_SCHEMA_VERSION', $done, 'chaine', 0, 'Data changes of inbox already done', $conf->entity);

		return 1;
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
