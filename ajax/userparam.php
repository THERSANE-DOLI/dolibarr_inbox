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
 * \file    inbox/ajax/userparam.php
 * \ingroup inbox
 * \brief   Settings of the current user (Settings > My account), for all the mailboxes or for one the user can read
 */

require __DIR__.'/inbox_ajax.inc.php';
dol_include_once('/inbox/class/inboxuserparam.class.php');

$action = (string) inboxAjaxParam('action', 'aZ09');
inboxAjaxStart(in_array($action, array('set', 'remove'), true));
$params = new InboxUserParam($db);
$accountId = (int) inboxAjaxParam('id', 'int');
if ($accountId > 0) {
	$access = new InboxAccess($db);
	if (!$access->check($user, $accountId, InboxAccess::LEVEL_READ)) {
		inboxAjaxNotFound();
	}
}

switch ($action) {
	case 'get':
		$mailboxes = array();
		$access = new InboxAccess($db);
		foreach ($access->getAccounts($user) as $account) {
			$mailboxes[] = array('id' => (int) $account->id, 'email' => $account->email, 'label' => $account->label, 'params' => $params->getAll($user, (int) $account->id));
		}
		inboxAjaxReply(array('params' => $params->getAll($user), 'mailboxes' => $mailboxes));
		// no break

	case 'set':
		if ($params->set($user, (string) inboxAjaxParam('param', 'aZ09'), (string) inboxAjaxParam('value', 'none'), $accountId) < 0) {
			inboxAjaxError($langs->transnoentities($params->error), 422);
		}
		inboxAjaxReply(array('params' => $params->getAll($user, $accountId)));
		// no break

	case 'remove':
		$params->remove($user, (string) inboxAjaxParam('param', 'aZ09'), $accountId);
		inboxAjaxReply(array('params' => $params->getAll($user, $accountId)));
		// no break

	default:
		inboxAjaxError('Unknown action', 400);
}
