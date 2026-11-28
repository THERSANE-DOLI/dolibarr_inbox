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
 * \file    inbox/ajax/overview.php
 * \ingroup inbox
 * \brief   Overview of a mail (1.153.0): its head when the column opens, one section when it is unfolded. Read only.
 *
 * Parameters: id (row of the index), sec (a section, action section). Nothing else is taken from the browser: the
 * mailbox, the other side and the thirdparty are read from the mail, each section checks its rights.
 */

require __DIR__.'/inbox_ajax.inc.php';
dol_include_once('/inbox/class/inboxoverview.class.php');

$action = (string) inboxAjaxParam('action', 'aZ09');
inboxAjaxStart(false);
$overview = new InboxOverview($db);
if (!InboxOverview::enabled($user)) {
	inboxAjaxError($langs->transnoentities('InboxErrorForbidden'), 403);
}
$ctx = $overview->context($user, (int) inboxAjaxParam('id', 'int'));
if ($ctx === null) {
	inboxAjaxNotFound();
}

switch ($action) {
	case 'head':
		inboxAjaxReply($overview->head($user, $ctx));
		// no break
	case 'section':
		$res = $overview->section($user, $ctx, (string) inboxAjaxParam('sec', 'aZ09'));
		if ($res === null) {
			inboxAjaxNotFound();
		}
		inboxAjaxReply($res);
		// no break
	default:
		inboxAjaxError('Unknown action', 400);
}
