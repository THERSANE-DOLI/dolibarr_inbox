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
 * \file    inbox/ajax/widget.php
 * \ingroup inbox
 * \brief   Widgets of the Dolibarr panel (1.154.0): the catalogue of the user (previews, never computed), the data of
 *          the widgets of a mail. Read only.
 *
 * Parameters: id (row of the index) and w (widgets, comma separated) for data. The mailbox, the other side and the
 * thirdparty are read from the mail; each widget follows the right of its section.
 */

require __DIR__.'/inbox_ajax.inc.php';
dol_include_once('/inbox/class/inboxwidgets.class.php');

$action = (string) inboxAjaxParam('action', 'aZ09');
inboxAjaxStart(false);
if (!InboxOverview::enabled($user)) {
	inboxAjaxError($langs->transnoentities('InboxErrorForbidden'), 403);
}
$widgets = new InboxWidgets($db);

switch ($action) {
	case 'catalogue':
		inboxAjaxReply(array('widgets' => $widgets->catalogue($user)));
		// no break
	case 'data':
		$ctx = (new InboxOverview($db))->context($user, (int) inboxAjaxParam('id', 'int'));
		if ($ctx === null) {
			inboxAjaxNotFound();
		}
		$ids = array_filter(explode(',', (string) inboxAjaxParam('w', 'alphanohtml')), function ($id) {
			return (bool) preg_match('/^[a-z0-9_]{1,40}$/', $id);
		});
		inboxAjaxReply(array('data' => $widgets->data($user, $ctx, $ids)));
		// no break
	default:
		inboxAjaxError('Unknown action', 400);
}
