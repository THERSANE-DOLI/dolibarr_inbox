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
 * \file    inbox/ajax/agenda.php
 * \ingroup inbox
 * \brief   Agenda panel (1.107.0): events of the user, case « done »
 */

require __DIR__.'/inbox_ajax.inc.php';
dol_include_once('/inbox/class/inboxagendapanel.class.php');

$action = (string) inboxAjaxParam('action', 'aZ09');
inboxAjaxStart($action === 'percent');
$panel = new InboxAgendaPanel($db);

switch ($action) {
	case 'list':
		$config = $panel->config($user);
		if (!$config['enabled']) {
			inboxAjaxError($langs->transnoentities('InboxErrorForbidden'), 403);
		}
		$from = (int) inboxAjaxParam('from', 'int');
		// Start of the day of the page; a value far from now is refused (a day either side, time zones)
		if (abs($from - dol_now()) > 2 * 86400) {
			inboxAjaxError('Bad from', 400);
		}
		$days = (int) inboxAjaxParam('days', 'int') ?: InboxAgendaPanel::DAYS_DEFAULT;
		inboxAjaxReply($panel->events($user, $from, $days));
		// no break

	case 'percent':
		$before = $panel->setPercent($user, (int) inboxAjaxParam('id', 'int'), (int) inboxAjaxParam('percent', 'int'));
		if ($before === -1) {
			inboxAjaxError($langs->transnoentities('InboxAgendaNotFound'), 404);
		}
		if ($before < 0) {
			inboxAjaxError($langs->transnoentities('InboxAgendaSaveError'), 500);
		}
		inboxAjaxReply(array('before' => $before));
		// no break

	default:
		inboxAjaxError('Unknown action', 400);
}
