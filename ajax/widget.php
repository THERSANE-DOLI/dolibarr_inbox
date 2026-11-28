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
 *          the widgets of a mail, the layouts of the panel (1.155.0): read freely, changed by POST with the token.
 *
 * Parameters: id (row of the index) and w (widgets, comma separated) for data; account, version (desktop, mobile)
 * and layout for the layouts (1.155.0). The mailbox, the other side and the
 * thirdparty are read from the mail; each widget follows the right of its section.
 */

require __DIR__.'/inbox_ajax.inc.php';
dol_include_once('/inbox/class/inboxwidgets.class.php');

$action = (string) inboxAjaxParam('action', 'aZ09');
// Layouts (1.155.0): reading is free of token, every change is a POST with the token
inboxAjaxStart(in_array($action, array('save', 'reset', 'savemodel', 'removemodel'), true));
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
	case 'layout':
		// Layouts of the panel of a mailbox: the user's, the model of the mailbox, the preset of the usage
		$res = $widgets->layouts($user, (int) inboxAjaxParam('account', 'int'));
		if ($res === null) {
			inboxAjaxNotFound();
		}
		inboxAjaxReply($res);
		// no break
	case 'save':
	case 'reset':
	case 'savemodel':
	case 'removemodel':
		$account = (int) inboxAjaxParam('account', 'int');
		$version = (string) inboxAjaxParam('version', 'aZ09');
		$layout = in_array($action, array('save', 'savemodel'), true) ? inboxAjaxParam('layout', 'none') : null;
		if (in_array($action, array('save', 'savemodel'), true) && !is_array($layout)) {
			inboxAjaxError('Bad layout', 400);
		}
		$res = in_array($action, array('save', 'reset'), true) ? $widgets->saveUser($user, $account, $version, $layout) : $widgets->saveModel($user, $account, $version, $layout);
		if ($res === -1) {
			inboxAjaxNotFound();
		}
		if ($res < 0) {
			inboxAjaxError($res === -2 ? 'Bad layout' : $langs->transnoentities('InboxErrorServer', 500), $res === -2 ? 400 : 500);
		}
		inboxAjaxReply($widgets->layouts($user, $account));
		// no break
	default:
		inboxAjaxError('Unknown action', 400);
}
