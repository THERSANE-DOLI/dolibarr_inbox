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
 * \file    inbox/ajax/action.php
 * \ingroup inbox
 * \brief   Actions on mails: read / unread, starred, tags, move, archive, remove, junk, with their undo; journal
 */

require __DIR__.'/inbox_ajax.inc.php';
dol_include_once('/inbox/class/inboxactions.class.php');

$action = (string) inboxAjaxParam('action', 'aZ09');

if ($action === 'journal') {
	// Journal of a mailbox: its managers only 
	inboxAjaxStart(false);
	$account = inboxAjaxManagedAccount((int) inboxAjaxParam('id', 'int'));
	// The journal names the mails (subjects, Message-ID): the level « manage » on the mailbox itself, the right to
	// manage the mailboxes does not give it (audit INBX-004)
	$access = new InboxAccess($db);
	if (!$access->check($user, (int) $account->id, InboxAccess::LEVEL_MANAGE)) {
		inboxAjaxError($langs->transnoentities('InboxJournalNeedManage'), 403);
	}
	$filters = array(
		'user' => (int) inboxAjaxParam('user', 'int'),
		// A module subscribed (1.146.0): the code of its declaration
		'consumer' => (string) inboxAjaxParam('consumer', 'aZ09'),
		// Inbox itself (1.147.1): blocked senders, spams, known senders taken out of the junk
		'system' => (int) inboxAjaxParam('system', 'int'),
		'action' => (string) inboxAjaxParam('what', 'aZ09'),
		'from' => (int) inboxAjaxParam('from', 'int'),
		'to' => (int) inboxAjaxParam('to', 'int'),
		'q' => (string) inboxAjaxParam('q', 'alphanohtml'),
	);
	$res = InboxActionLog::listForAccount($db, (int) $account->id, $filters, 50, max(0, (int) inboxAjaxParam('offset', 'int')));
	$res['authors'] = InboxActionLog::authors($db, (int) $account->id);
	inboxAjaxReply($res);
}

inboxAjaxStart(true);
$what = (string) inboxAjaxParam('what', 'aZ09');
$ids = inboxAjaxParam('ids', 'none');
$ids = array_map('intval', is_array($ids) ? $ids : explode(',', (string) $ids));
$list = function ($key) {
	$v = inboxAjaxParam($key, 'none');
	return array_values(array_filter(array_map('strval', is_array($v) ? $v : ($v === '' ? array() : explode(' ', (string) $v)))));
};
$params = array(
	'on' => (int) inboxAjaxParam('on', 'int') === 1,
	'add' => $list('add'),
	'remove' => $list('remove'),
	'folder' => (int) inboxAjaxParam('folder', 'int'),
	'confirm' => (int) inboxAjaxParam('confirm', 'int') === 1,
	'expand' => array_map('intval', $list('expand')),
);
if ($action !== 'run' || !in_array($what, array('seen', 'flag', 'tag', 'move', 'archive', 'delete', 'junk', 'notjunk'), true)) {
	inboxAjaxError('Unknown action', 400);
}
// An undo: its own writes are not journaled, the lines it cancels are marked
$undoOf = inboxAjaxParam('undo_of', 'none');
$undoOf = array_filter(array_map('intval', is_array($undoOf) ? $undoOf : array()));
// Not journaled only when it undoes lines of the journal of this user, of the last 10 minutes (an undo sent by
// batches gives the same lines each time), and gives back what they did: no other action hides itself from the
// journal behind a line of its author (audit INBX-016)
if ($undoOf) {
	$lines = $db->getRows("SELECT rowid, action, nb, message_id, fk_account, fk_account_to, folder_from, folder_to FROM ".$db->prefix()."inbox_action_log WHERE rowid IN (".implode(',', $undoOf).")"
		." AND fk_user = ".((int) $user->id)." AND date_action > '".$db->idate(dol_now() - 600)."' LIMIT 1000");
	if (!is_array($lines) || count($lines) !== count(array_unique($undoOf)) || !InboxActionLog::undoes($db, $what, $ids, $params, $lines)) {
		inboxAjaxError('Bad undo', 400);
	}
	InboxActionLog::$paused = true;
}

$actions = new InboxActions($db);
$res = $actions->run($user, $what, $ids, $params);
if ($undoOf && $res['count'] > 0) {
	InboxActionLog::markUndone($db, $user, $undoOf);
}

$message = '';
if ($res['error'] !== '') {
	$key = (string) $res['error'];
	$message = preg_match('/^Inbox[A-Za-z]+$/', $key) ? $langs->transnoentities($key) : $langs->transnoentities('InboxActionServerError', $key);
}
if (!$res['ok']) {
	$codes = array('InboxMailNotFound' => 404, 'InboxErrorNeedManage' => 403, 'InboxErrorNeedManageTarget' => 403, 'InboxErrorNeedHandle' => 403, 'InboxErrorNeedHandleTarget' => 403, 'InboxErrorConfirmDelete' => 409,
		'InboxErrorFolderNotFound' => 404, 'No mail' => 400, 'Invalid tags' => 400);
	inboxAjaxReply(array('error' => $message, 'code' => (string) $res['error']), $codes[(string) $res['error']] ?? 502);
}
inboxAjaxReply(array(
	'count' => (int) $res['count'],
	'undo' => $res['undo'],
	'log' => InboxActionLog::$written,
	// Row of the index before => after (a mail moved to another mailbox gets a new row)
	'ids' => (object) $res['ids'],
	'rest' => array_values($res['rest']),
	'warning' => $message,
));
