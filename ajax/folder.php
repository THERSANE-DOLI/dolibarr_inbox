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
 * \file    inbox/ajax/folder.php
 * \ingroup inbox
 * \brief   Mailboxes of the user and their folders, from the index (pane 1 of the mail space)
 */

require __DIR__.'/inbox_ajax.inc.php';
dol_include_once('/inbox/class/inboxfolder.class.php');
dol_include_once('/inbox/class/inboxsync.class.php');
dol_include_once('/inbox/class/connector/inboximapconnector.class.php');
dol_include_once('/inbox/class/inboxtag.class.php');

$action = (string) inboxAjaxParam('action', 'aZ09');
inboxAjaxStart(false);

/**
 * Tree of the folders of a mailbox: parent by the readable label (the common prefix « INBOX. » is not a level),
 * special folders first in the usual order, then by name
 *
 * @param int $accountId Mailbox
 * @return array<int,array<string,mixed>>
 */
function inboxFolderTree($accountId)
{
	global $db;

	$folders = new InboxFolder($db);
	$rows = $folders->listForAccount((int) $accountId);
	$tmp = array();
	foreach ($rows as $f) {
		$tmp[] = array('path' => (string) $f->path, 'delimiter' => (string) $f->delimiter);
	}
	InboxImapConnector::addLabels($tmp);
	$byLabel = array();
	foreach ($rows as $i => $f) {
		$byLabel[$tmp[$i]['label']] = (int) $f->rowid;
	}
	$order = array('inbox' => 0, 'drafts' => 1, 'sent' => 2, 'archive' => 3, 'junk' => 4, 'trash' => 5);
	$out = array();
	foreach ($rows as $i => $f) {
		$label = $tmp[$i]['label'];
		$parts = explode(' / ', $label);
		$name = array_pop($parts);
		$parentLabel = implode(' / ', $parts);
		$role = (string) $f->role;
		$out[] = array(
			'id' => (int) $f->rowid,
			'name' => $name,
			'label' => $label,
			'parent' => $parentLabel !== '' && isset($byLabel[$parentLabel]) ? $byLabel[$parentLabel] : 0,
			'role' => $role,
			'rank' => isset($order[$role]) ? $order[$role] : 9,
			'selectable' => (int) $f->selectable,
			'followed' => (int) $f->sync_enabled,
			'total' => (int) $f->total,
			'unread' => (int) $f->unread,
			'error' => (string) $f->sync_error !== '',
		);
	}
	usort($out, function ($a, $b) {
		return $a['rank'] <=> $b['rank'] ?: strnatcasecmp($a['name'], $b['name']);
	});

	return $out;
}

switch ($action) {
	case 'tree':
		$access = new InboxAccess($db);
		$sync = new InboxSync($db);
		$tagHelper = new InboxTag($db);
		$list = array();
		foreach ($access->getAccounts($user) as $account) {
			$account->loadSyncState();
			$list[] = array(
				'id' => (int) $account->id,
				'email' => (string) $account->email,
				'label' => (string) $account->label,
				'personal' => $account->type === InboxAccount::TYPE_PERSONAL && (int) $account->fk_user_owner === (int) $user->id,
				'level' => (string) $account->access_level,
				'state' => (string) $account->sync_state,
				'error' => in_array((string) $account->sync_state, array('auth_failed', 'unreachable', 'error'), true) ? (string) $account->sync_error : '',
				'retry_at' => $account->sync_retry_at ? (int) $account->sync_retry_at : null,
				'progress' => $sync->progress((int) $account->id),
				'quota' => $account->quota_limit ? array('used' => (int) $account->quota_used, 'limit' => (int) $account->quota_limit) : null,
				'folders' => inboxFolderTree((int) $account->id),
				// Tags of the mailbox (its own list or the global one): keyword => label and color
				'tags' => array_values(array_map(function ($t) {
					return array('keyword' => $t['keyword'], 'label' => $t['label'], 'color' => $t['color']);
				}, array_filter($tagHelper->listTags($account->tags_mode === 'own' ? (int) $account->id : 0), function ($t) {
					return (int) $t['status'] === 1;
				}))),
			);
		}
		// Personal mailbox first, then the shared ones by label
		usort($list, function ($a, $b) {
			return (int) $b['personal'] <=> (int) $a['personal'] ?: strcasecmp($a['label'] ?: $a['email'], $b['label'] ?: $b['email']);
		});
		inboxAjaxReply(array('accounts' => $list, 'admin' => (bool) $user->hasRight('inbox', 'account', 'admin')));
		// no break

	default:
		inboxAjaxError('Unknown action', 400);
}
