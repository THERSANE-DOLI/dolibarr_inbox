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
 * \file    inbox/ajax/sync.php
 * \ingroup inbox
 * \brief   Synchronisation of a mailbox: state, folders followed, synchronise now, rebuild the index
 */

require __DIR__.'/inbox_ajax.inc.php';
dol_include_once('/inbox/class/inboxsync.class.php');

$action = (string) inboxAjaxParam('action', 'aZ09');
inboxAjaxStart(in_array($action, array('now', 'rebuild', 'folder'), true));
$account = inboxAjaxManagedAccount((int) inboxAjaxParam('id', 'int'));
$sync = new InboxSync($db);
$folders = new InboxFolder($db);

/**
 * State of the synchronisation of a mailbox, for the tab Synchro
 *
 * @param InboxAccount $account Mailbox
 * @return array<string,mixed>
 */
function inboxSyncState(InboxAccount $account)
{
	global $db;

	$account->loadSyncState();
	$sync = new InboxSync($db);
	$folders = new InboxFolder($db);
	$list = array();
	$rows = $folders->listForAccount((int) $account->id);
	$tmp = array();
	foreach ($rows as $f) {
		$tmp[] = array('path' => $f->path, 'delimiter' => (string) $f->delimiter);
	}
	InboxImapConnector::addLabels($tmp);
	foreach ($rows as $i => $f) {
		$list[] = array(
			'id' => (int) $f->rowid, 'path' => (string) $f->path, 'label' => $tmp[$i]['label'], 'role' => (string) $f->role,
			'selectable' => (int) $f->selectable, 'sync_enabled' => (int) $f->sync_enabled, 'total' => (int) $f->total, 'unread' => (int) $f->unread,
			'indexed' => (int) $f->indexed, 'first_sync_done' => (int) $f->first_sync_done, 'date_sync' => $f->date_sync ? (int) $db->jdate($f->date_sync) : null,
			'error' => (string) $f->sync_error,
		);
	}

	return array(
		'state' => (string) $account->sync_state, 'error' => (string) $account->sync_error, 'date_sync' => $account->date_sync ?: null,
		'retry_at' => $account->sync_retry_at ?: null, 'running' => $account->sync_lock && $account->sync_lock > dol_now() - InboxSync::LOCK_SECONDS,
		'active' => $account->isActive(), 'progress' => $sync->progress((int) $account->id), 'folders' => $list,
	);
}

switch ($action) {
	case 'status':
		inboxAjaxReply(inboxSyncState($account));
		// no break

	case 'now':
		if (!$account->isActive()) {
			inboxAjaxError($langs->transnoentities('InboxSyncNeedsActive'), 422);
		}
		// A pass in this request, bounded (the scheduled job goes on with the rest)
		$res = $sync->run($account, microtime(true) + 20, array('force' => true));
		if ($res === 0) {
			inboxAjaxError($langs->transnoentities('InboxSyncAlreadyRunning'), 409);
		}
		$state = inboxSyncState($account);
		$state['stats'] = $sync->stats;
		inboxAjaxReply($state);
		// no break

	case 'rebuild':
		inboxAjaxNeedAdmin();
		// The index is emptied, then read again by the next passes; links and notes are on the Message-ID, not here
		$mail = new InboxMail($db);
		foreach ($folders->listForAccount((int) $account->id, false) as $f) {
			$mail->clearFolder((int) $f->rowid);
		}
		$db->query("UPDATE ".$db->prefix()."inbox_folder SET sync_cursor = NULL, indexed = 0, first_sync_done = 0, oldest_indexed = NULL WHERE fk_account = ".((int) $account->id));
		inboxAjaxReply(inboxSyncState($account));
		// no break

	case 'folder':
		$folderId = (int) inboxAjaxParam('folder_id', 'int');
		$enabled = (bool) inboxAjaxParam('enabled', 'int');
		if (!$folders->setSyncEnabled((int) $account->id, $folderId, $enabled)) {
			inboxAjaxNotFound();
		}
		if (!$enabled) {
			// Not followed any more: its mails leave the index
			(new InboxMail($db))->clearFolder($folderId);
			$db->query("UPDATE ".$db->prefix()."inbox_folder SET sync_cursor = NULL, indexed = 0, first_sync_done = 0 WHERE rowid = ".$folderId." AND fk_account = ".((int) $account->id));
		}
		inboxAjaxReply(inboxSyncState($account));
		// no break

	default:
		inboxAjaxError('Unknown action', 400);
}
