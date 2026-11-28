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
 * \file    inbox/ajax/assign.php
 * \ingroup inbox
 * \brief   Who handles a mail (1.9.0): people of a mailbox, assign / remove
 */

require __DIR__.'/inbox_ajax.inc.php';
dol_include_once('/inbox/class/inboxassign.class.php');

$action = (string) inboxAjaxParam('action', 'aZ09');
inboxAjaxStart($action === 'set');
$assign = new InboxAssign($db);
$ints = function ($key) {
	$v = inboxAjaxParam($key, 'none');
	return array_values(array_filter(array_map('intval', is_array($v) ? $v : ($v === '' ? array() : explode(',', (string) $v)))));
};

switch ($action) {
	case 'people':
		// People who can read the mailbox: the only ones that may be assigned there
		$accountId = (int) inboxAjaxParam('id', 'int');
		$access = new InboxAccess($db);
		if (!$access->check($user, $accountId, InboxAccess::LEVEL_READ)) {
			inboxAjaxNotFound();
		}
		inboxAjaxReply(array('people' => array_values(array_map(function ($p) {
			return array('id' => $p['id'], 'name' => $p['name'], 'photo' => $p['photo']);
		}, $assign->people($accountId)))));
		// no break

	case 'set':
		$res = $assign->set($user, $ints('ids'), $ints('add'), $ints('remove'), $ints('expand'));
		if (!$res['ok']) {
			$codes = array('InboxMailNotFound' => 404, 'InboxErrorAssignNoAccess' => 403, 'InboxErrorNoMessageId' => 422, 'No mail' => 400);
			$key = (string) $res['error'];
			inboxAjaxReply(array('error' => preg_match('/^Inbox[A-Za-z]+$/', $key) ? $langs->transnoentities($key) : $key, 'code' => $key), $codes[$key] ?? 500);
		}
		inboxAjaxReply(array(
			'count' => (int) $res['count'],
			'assignees' => (object) $res['assignees'],
			'people' => (object) $res['people'],
			'skipped' => (int) $res['skipped'],
			'log' => InboxActionLog::$written,
		));
		// no break

	default:
		inboxAjaxError('Unknown action', 400);
}
