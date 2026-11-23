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

	/**
	 * Top bar of the other pages of Dolibarr: the unread counter of inbox (lot W8 1.21.0). Counted when the page is
	 * made (one light query), then kept up to date without any call by the tabs of inbox open in the browser (local
	 * storage, js/inbox-topbar.js): no polling from every page of Dolibarr. The menu entries have no hook: the script
	 * moves the badge onto the entry Inbox of the top menu; this envelope stays only when that entry is not in the page.
	 *
	 * @param array<string,mixed> $parameters  Parameters
	 * @param object|null         $object      Unused
	 * @param string              $action      Unused
	 * @param HookManager         $hookmanager Hook manager
	 * @return int 0
	 */
	public function printTopRightMenu($parameters, &$object, &$action, $hookmanager)
	{
		global $conf, $langs, $user;

		if (!isModEnabled('inbox') || empty($user->id) || !empty($user->socid) || !$user->hasRight('inbox', 'webmail', 'use') || empty($conf->use_javascript_ajax)) {
			return 0;
		}
		dol_include_once('/inbox/class/inboxnotification.class.php');
		$params = new InboxUserParam($this->db);
		if ($params->get($user, 'count_in_dolibarr') !== '1') {
			return 0;
		}
		$notify = new InboxNotification($this->db);
		if (!$notify->accountsFor($user, 'count')) {
			return 0;
		}
		$count = $notify->mailCount($user)['mails'];
		$langs->load('inbox@inbox');
		$url = dol_buildpath('/inbox/index.php', 1);
		$label = $langs->trans('InboxTopbarUnread', '%s');
		$none = $langs->trans('InboxTopbarNone');
		$title = $count > 0 ? str_replace('%s', (string) $count, $label) : $none;
		$out = '<div class="inline-block nowrap login_block_elem inbox-topbar-block">';
		$out .= '<a href="'.dol_escape_htmltag($url).'" class="atoplogin inbox-topbar" data-inbox-key="'.dol_escape_htmltag('inbox-unread:'.$url).'"';
		$out .= ' data-label="'.dol_escape_htmltag($label).'" data-label-none="'.dol_escape_htmltag($none).'" title="'.dol_escape_htmltag($title).'" aria-label="'.dol_escape_htmltag($title).'">';
		$out .= '<span class="fa fa-envelope atoplogin valignmiddle" aria-hidden="true"></span>';
		// display, not the attribute hidden: .badge of the theme forces its display
		$out .= '<span class="badge badge-danger inbox-topbar__badge" style="position:relative;top:-0.6em;left:-0.4em;font-size:0.65em'.($count > 0 ? '' : ';display:none').'">'.($count > 99 ? '99+' : $count).'</span>';
		$out .= '</a></div>';
		$out .= '<script'.(function_exists('getNonce') ? ' nonce="'.getNonce().'"' : '').' src="'.dol_escape_htmltag(dol_buildpath('/inbox/js/inbox-topbar.js', 1).'?v='.((int) @filemtime(dol_buildpath('/inbox/js/inbox-topbar.js', 0)))).'" defer></script>';
		$this->resprints = $out;

		return 0;
	}

	/**
	 * A mail sent by Dolibarr itself (CMailFile, context mail): its copy in the Sent folder of the mailbox of its
	 * sender (1.132.0, InboxDolibarrSent). Always 0 and never an error: the mail left, sendfile() would say it failed;
	 * a copy not filed is only written in the log of Dolibarr.
	 *
	 * @param array<string,mixed> $parameters  Parameters (sent: the sending worked)
	 * @param CMailFile|null      $object      Mail sent
	 * @param string              $action      Unused
	 * @param HookManager         $hookmanager Hook manager
	 * @return int 0
	 */
	public function sendMailAfter($parameters, &$object, &$action, $hookmanager)
	{
		if (empty($parameters['sent']) || !($object instanceof CMailFile) || !isModEnabled('inbox')) {
			return 0;
		}
		try {
			dol_include_once('/inbox/class/inboxdolibarrsent.class.php');
			$copy = new InboxDolibarrSent($this->db);
			$copy->file($object);
		} catch (Throwable $e) {
			dol_syslog(__METHOD__.' '.get_class($e).' '.$e->getMessage(), LOG_WARNING);
		}

		return 0;
	}
}
