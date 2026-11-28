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
 * \brief   Conversations ignored (the mailbox) or watched (the user), 1.11.0; a new conversation started from
 *          a mail, joined back (1.139.0)
 */

require __DIR__.'/inbox_ajax.inc.php';
dol_include_once('/inbox/class/inboxthreadmark.class.php');
dol_include_once('/inbox/class/inboxreader.class.php');
dol_include_once('/inbox/class/inboxthreadsplit.class.php');

$action = (string) inboxAjaxParam('action', 'aZ09');
inboxAjaxStart(true);

if (in_array($action, array('split_preview', 'split', 'join'), true)) {
	$reader = new InboxReader($db);
	if (!$reader->load($user, (int) inboxAjaxParam('id', 'int')) || $reader->isLive()) {
		inboxAjaxNotFound();
	}
	// The cut changes the conversations of every user of the mailbox: level « handle »
	if (!InboxAccess::atLeast($reader->level, InboxAccess::LEVEL_HANDLE)) {
		inboxAjaxReply(array('error' => $langs->transnoentities('InboxErrorNeedHandle'), 'code' => 'InboxErrorNeedHandle'), 403);
	}
	if ((string) $reader->row->message_id === '' || (string) $reader->row->thread_key === '') {
		inboxAjaxReply(array('error' => $langs->transnoentities('InboxErrorNoMessageId'), 'code' => 'InboxErrorNoMessageId'), 422);
	}
	$split = new InboxThreadSplit($db);
	$elements = new InboxElements($db);
	if ($action === 'split_preview') {
		$res = $split->preview($user, $reader->row);
		if ($res === null) {
			inboxAjaxReply(array('error' => $langs->transnoentities($split->error ?: 'Error')), 500);
		}
		$links = array();
		foreach ($res['links'] as $l) {
			$d = $elements->describe($user, $l['type'], $l['element']);
			if ($d !== null) {
				$links[] = array_merge($l, array('kind' => (string) ($d['kind'] ?? ''), 'ref' => (string) ($d['ref'] ?? ''), 'icon' => (string) ($d['icon'] ?? 'link'), 'readable' => !empty($d['readable'])));
			}
		}
		$res['links'] = $links;
		inboxAjaxReply($res);
	}
	if ($action === 'split') {
		$keep = inboxAjaxParam('keep', 'none');
		$removed = $split->split($user, $reader->row, is_array($keep) ? array_map('intval', $keep) : array());
		if ($removed === null) {
			inboxAjaxReply(array('error' => $langs->transnoentities($split->error ?: 'Error')), 500);
		}
		inboxAjaxReply(array('removed' => $removed));
	}
	$links = inboxAjaxParam('links', 'none');
	if ($split->join($user, $reader->row, is_array($links) ? $links : array()) < 0) {
		inboxAjaxReply(array('error' => $langs->transnoentities($split->error ?: 'Error')), 500);
	}
	inboxAjaxReply(array('done' => 1));
}

if ($action !== 'mark') {
	inboxAjaxError('Unknown action', 400);
}
$marks = new InboxThreadMark($db);
$res = $marks->set($user, (int) inboxAjaxParam('id', 'int'), (string) inboxAjaxParam('mode', 'aZ09'), (int) inboxAjaxParam('on', 'int') === 1);
if ($res < 0) {
	$key = (string) $marks->error;
	$codes = array('InboxMailNotFound' => 404, 'InboxErrorNeedManage' => 403, 'InboxErrorNeedHandle' => 403, 'InboxErrorThreadNotReady' => 409, 'Bad mode' => 400);
	inboxAjaxReply(array('error' => preg_match('/^Inbox[A-Za-z]+$/', $key) ? $langs->transnoentities($key) : $key, 'code' => $key), $codes[$key] ?? 500);
}
inboxAjaxReply(array('changed' => $res, 'read' => $marks->count));
