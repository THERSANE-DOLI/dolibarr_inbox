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
 * \file    inbox/ajax/folderadmin.php
 * \ingroup inbox
 * \brief   Folders managed from inbox (lot W4 1.10.0): create, rename, move, remove, mark read, empty
 */

require __DIR__.'/inbox_ajax.inc.php';
dol_include_once('/inbox/class/inboxfolderadmin.class.php');

$action = (string) inboxAjaxParam('action', 'aZ09');
inboxAjaxStart(true);
$admin = new InboxFolderAdmin($db);
$folderId = (int) inboxAjaxParam('folder', 'int');
// Raw: checked by InboxFolderAdmin (no quote, no < >, no delimiter), escaped by the database and the screen
$name = (string) inboxAjaxParam('name', 'none');
$confirm = (int) inboxAjaxParam('confirm', 'int') === 1;
// The server keeps the session free: a slow mail server never blocks the other calls of the user
session_write_close();

switch ($action) {
	case 'create':
		$res = $admin->create($user, (int) inboxAjaxParam('account', 'int'), (int) inboxAjaxParam('parent', 'int'), $name);
		break;
	case 'rename':
		$res = $admin->rename($user, $folderId, $name);
		break;
	case 'move':
		$res = $admin->move($user, $folderId, (int) inboxAjaxParam('target', 'int'));
		break;
	case 'delete':
		$res = $admin->remove($user, $folderId, $confirm);
		break;
	case 'markread':
		$res = $admin->markRead($user, $folderId);
		break;
	case 'empty':
		$res = $admin->emptyFolder($user, $folderId, $confirm);
		break;
	default:
		inboxAjaxError('Unknown action', 400);
}

if ($res < 0) {
	$key = (string) $admin->error;
	$codes = array('InboxErrorFolderNotFound' => 404, 'InboxErrorNeedManage' => 403, 'InboxErrorNeedHandle' => 403, 'InboxErrorFolderSpecial' => 403, 'InboxErrorFoldersNotSupported' => 422,
		'InboxErrorFolderName' => 422, 'InboxErrorFolderExists' => 409, 'InboxErrorFolderIntoItself' => 422, 'InboxErrorConfirmFolderDelete' => 409, 'InboxErrorConfirmEmpty' => 409);
	$message = preg_match('/^Inbox[A-Za-z]+$/', $key) ? $langs->transnoentities($key, $admin->count) : $langs->transnoentities('InboxActionServerError', $key);
	inboxAjaxReply(array('error' => $message, 'code' => $key, 'count' => $admin->count), $codes[$key] ?? 502);
}
inboxAjaxReply(array('result' => (int) $res, 'count' => (int) $admin->count));
