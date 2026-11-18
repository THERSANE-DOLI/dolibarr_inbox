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
 * \file    inbox/ajax/event.php
 * \ingroup inbox
 * \brief   Journal of the changes (1.121.0): the token of the light call (live.php), asked again before its end
 *
 * The token holds the user, the entity and the mailboxes he can read now, signed with the key of the instance: the
 * light call reads only his events without starting Dolibarr. It is given in « Rapide » (fast) mode only.
 */

require __DIR__.'/inbox_ajax.inc.php';

dol_include_once('/inbox/class/inboxevent.class.php');

$action = (string) inboxAjaxParam('action', 'aZ09');
inboxAjaxStart(true);

switch ($action) {
	case 'token':
		if (!InboxEvent::enabled()) {
			inboxAjaxReply(array('mode' => 'standard'));
		}
		$events = new InboxEvent($db);
		$t = $events->token($user, InboxEvent::key((string) $conf->file->instance_unique_id));
		if ($t === null) {
			inboxAjaxError('no_instance_id', 500);
		}
		$rhythm = InboxEvent::rhythm();
		inboxAjaxReply(array(
			'mode' => getDolGlobalString('INBOX_REACTIVITY'),
			'token' => $t['token'],
			'expires' => $t['expires'],
			'interval' => $rhythm['interval'],
			'idle' => $rhythm['idle'],
			'url' => dol_buildpath('/inbox/live.php', 1),
			'last' => InboxEvent::cursor($db),
		));
		// no break
	default:
		inboxAjaxError('Unknown action', 400);
}
