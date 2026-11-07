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
 * \file    inbox/ajax/notification.php
 * \ingroup inbox
 * \brief   Notification centre, the bell (webmail §5.4 sexies): list, count, read, where a notification leads
 */

require __DIR__.'/inbox_ajax.inc.php';
dol_include_once('/inbox/class/inboxnotification.class.php');

$action = (string) inboxAjaxParam('action', 'aZ09');
inboxAjaxStart(in_array($action, array('read', 'open'), true));
$notify = new InboxNotification($db);

switch ($action) {
	case 'count':
		inboxAjaxReply($notify->counts($user));
		// no break

	case 'list':
		$res = $notify->listFor($user, 50, (int) inboxAjaxParam('before', 'int'));
		inboxAjaxReply($res + $notify->counts($user));
		// no break

	case 'read':
		// ids: these ones, none: all of them
		$ids = inboxAjaxParam('ids', 'none');
		$notify->markRead($user, array_map('intval', is_array($ids) ? $ids : array_filter(explode(',', (string) $ids))));
		inboxAjaxReply($notify->counts($user));
		// no break

	case 'open':
		$access = new InboxAccess($db);
		$readable = array_keys(array_filter($access->getLevels($user), function ($level) {
			return InboxAccess::rank($level) >= InboxAccess::rank(InboxAccess::LEVEL_READ);
		}));
		$target = $notify->target($user, (int) inboxAjaxParam('id', 'int'), $readable);
		$messages = array('gone' => 'InboxNotifGone', 'no_access' => 'InboxNotifNoAccess', 'not_found' => 'InboxNotifNotFound');
		// « problem », not « error »: an answer, said to the user (the mail gone is not a failure of the call)
		if (isset($target['error'])) {
			$target['problem'] = $target['error'];
			$target['message'] = $langs->transnoentities($messages[$target['error']]);
			unset($target['error']);
		}
		inboxAjaxReply($target + $notify->counts($user));
		// no break

	default:
		inboxAjaxError('Unknown action', 400);
}
