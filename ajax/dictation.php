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
 * \file    inbox/ajax/dictation.php
 * \ingroup inbox
 * \brief   Voice typing (1.28.0): consents of the current user, given in the writing, withdrawn in My settings
 */

require __DIR__.'/inbox_ajax.inc.php';
dol_include_once('/inbox/class/inboxdictation.class.php');

$action = (string) inboxAjaxParam('action', 'aZ09');
inboxAjaxStart(in_array($action, array('consent', 'revoke'), true));
$dictation = new InboxDictation($db);

switch ($action) {
	case 'get':
		inboxAjaxReply(array('enabled' => InboxDictation::enabled() ? 1 : 0, 'consents' => (object) $dictation->consents($user)));
		// no break

	case 'consent':
		if (!$dictation->consent($user, (string) inboxAjaxParam('provider', 'aZ09'), (int) inboxAjaxParam('account', 'int'))) {
			inboxAjaxError($langs->transnoentities('InboxDictateRefused'), 403);
		}
		inboxAjaxReply(array('consents' => (object) $dictation->consents($user)));
		// no break

	case 'revoke':
		$dictation->revoke($user, (string) inboxAjaxParam('provider', 'aZ09'));
		inboxAjaxReply(array('consents' => (object) $dictation->consents($user)));
		// no break

	default:
		inboxAjaxError('Unknown action', 400);
}
