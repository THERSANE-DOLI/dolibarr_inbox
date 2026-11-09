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
 * \file    inbox/ajax/thread.php
 * \ingroup inbox
 * \brief   Conversations ignored (the mailbox) or watched (the user), lot W4 1.11.0
 */

require __DIR__.'/inbox_ajax.inc.php';
dol_include_once('/inbox/class/inboxthreadmark.class.php');

$action = (string) inboxAjaxParam('action', 'aZ09');
inboxAjaxStart(true);
if ($action !== 'mark') {
	inboxAjaxError('Unknown action', 400);
}
$marks = new InboxThreadMark($db);
$res = $marks->set($user, (int) inboxAjaxParam('id', 'int'), (string) inboxAjaxParam('mode', 'aZ09'), (int) inboxAjaxParam('on', 'int') === 1);
if ($res < 0) {
	$key = (string) $marks->error;
	$codes = array('InboxMailNotFound' => 404, 'InboxErrorNeedManage' => 403, 'InboxErrorThreadNotReady' => 409, 'Bad mode' => 400);
	inboxAjaxReply(array('error' => preg_match('/^Inbox[A-Za-z]+$/', $key) ? $langs->transnoentities($key) : $key, 'code' => $key), $codes[$key] ?? 500);
}
inboxAjaxReply(array('changed' => $res, 'read' => $marks->count));
