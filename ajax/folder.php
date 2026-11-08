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
dol_include_once('/inbox/class/inboxnewmarks.class.php');
dol_include_once('/inbox/class/inboxlook.class.php');

$action = (string) inboxAjaxParam('action', 'aZ09');
inboxAjaxStart(in_array($action, array('look', 'lookreset'), true));

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
	// Several folders may have the same role (Junk and Spam): only the first one (SPECIAL-USE flag first, then the
	// shortest path) is shown with the name of the role, the others keep their own name
	$primary = array();
	foreach ($rows as $f) {
		$role = (string) $f->role;
		if ($role === '') {
			continue;
		}
		$special = stripos((string) $f->flags, '\\'.$role) !== false || ($role === 'trash' && stripos((string) $f->flags, '\\trash') !== false);
		$score = ($special ? 0 : 1000) + strlen((string) $f->path);
		if (!isset($primary[$role]) || $score < $primary[$role][1]) {
			$primary[$role] = array((int) $f->rowid, $score);
		}
	}
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
			'primary' => $role !== '' && isset($primary[$role]) && $primary[$role][0] === (int) $f->rowid,
			'rank' => isset($order[$role]) ? $order[$role] + (($role !== '' && isset($primary[$role]) && $primary[$role][0] === (int) $f->rowid) ? 0 : 0.5) : 9,
			'selectable' => (int) $f->selectable,
			'followed' => (int) $f->sync_enabled,
			'total' => (int) $f->total,
			'unread' => (int) $f->unread,
			// Mails known to inbox (the synchronisation may stop before the oldest ones): the export says it
			'indexed' => (int) $f->indexed,
			// Junk: mails probably legitimate (known sender, authenticated), a counter beside the folder
			'legit' => $role === 'junk' ? (int) (($legit = $db->getRow("SELECT COUNT(rowid) as n FROM ".$db->prefix()."inbox_mail WHERE fk_folder = ".((int) $f->rowid)." AND junk_hint = 'legit'")) ? $legit->n : 0) : 0,
			'error' => (string) $f->sync_error !== '',
			// Colour and icon shared by the users of the mailbox ('' = by default; a special folder keeps its icon)
			'color' => (string) InboxLook::cleanColor((string) $f->color),
			'icon' => $role === '' ? (string) InboxLook::cleanIcon((string) $f->icon) : '',
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
		$newMarks = new InboxNewMarks($db);
		$list = array();
		$accounts = $access->getAccounts($user);
		// Meilisearch (lot W7 1.20.0): state of the mailboxes indexed (indexing, ready)
		dol_include_once('/inbox/class/inboxfulltext.class.php');
		$ftOn = InboxFulltext::enabled();
		$ftStates = array();
		if ($ftOn) {
			$fulltext = new InboxFulltext($db);
			$ftStates = $fulltext->states(array_map(function ($a) {
				return (int) $a->id;
			}, $accounts));
		}
		foreach ($accounts as $account) {
			$account->loadSyncState();
			// New mails of this user (arrived since he last left the folder, still unread)
			$marks = $newMarks->marks($user, (int) $account->id);
			$news = $newMarks->counts($marks);
			$folderTree = inboxFolderTree((int) $account->id);
			foreach ($folderTree as $k => $f) {
				$folderTree[$k]['new'] = $news[$f['id']] ?? 0;
			}
			$list[] = array(
				'id' => (int) $account->id,
				'email' => (string) $account->email,
				'label' => (string) $account->label,
				'personal' => $account->type === InboxAccount::TYPE_PERSONAL && (int) $account->fk_user_owner === (int) $user->id,
				'level' => (string) $account->access_level,
				'color' => (string) InboxLook::cleanColor((string) $account->color),
				'icon' => (string) InboxLook::cleanIcon((string) $account->icon),
				'state' => (string) $account->sync_state,
				'error' => in_array((string) $account->sync_state, array('auth_failed', 'unreachable', 'error'), true) ? (string) $account->sync_error : '',
				'retry_at' => $account->sync_retry_at ? (int) $account->sync_retry_at : null,
				'progress' => $sync->progress((int) $account->id),
				'quota' => $account->quota_limit ? array('used' => (int) $account->quota_used, 'limit' => (int) $account->quota_limit) : null,
				'folders' => $folderTree,
				'fulltext' => isset($ftStates[(int) $account->id]) ? (string) $ftStates[(int) $account->id]->state : '',
				'marks' => (object) $marks,
				// Tags of the mailbox (its own list or the global one): keyword => label, color and border
				'tags' => array_values(array_map(function ($t) {
					return array('keyword' => $t['keyword'], 'label' => $t['label'], 'color' => $t['color'], 'border' => $t['border']);
				}, array_filter($tagHelper->listTags($account->tags_mode === 'own' ? (int) $account->id : 0), function ($t) {
					return (int) $t['status'] === 1;
				}))),
			);
		}
		// Personal mailbox first, then the shared ones by label
		usort($list, function ($a, $b) {
			return (int) $b['personal'] <=> (int) $a['personal'] ?: strcasecmp($a['label'] ?: $a['email'], $b['label'] ?: $b['email']);
		});
		inboxAjaxReply(array('accounts' => $list, 'admin' => (bool) $user->hasRight('inbox', 'account', 'admin'), 'icons' => InboxLook::choices()));
		// no break

	case 'look':
		// Colour and icon of a folder (folder > 0) or of the mailbox, by a user who manages the mailbox
		$account = inboxAjaxManagedAccount((int) inboxAjaxParam('id', 'int'));
		$look = new InboxLook($db);
		$folderId = (int) inboxAjaxParam('folder', 'int');
		if ($folderId > 0) {
			$res = $look->setFolder((int) $account->id, $folderId, (string) inboxAjaxParam('color', 'alphanohtml'), (string) inboxAjaxParam('icon', 'aZ09'), (int) inboxAjaxParam('subfolders', 'int') === 1);
		} else {
			$res = $look->setAccount((int) $account->id, (string) inboxAjaxParam('color', 'alphanohtml'), (string) inboxAjaxParam('icon', 'aZ09'));
		}
		if ($res < 0) {
			inboxAjaxError($langs->transnoentities($look->error), 422);
		}
		inboxAjaxReply(array('changed' => $res));
		// no break

	case 'lookreset':
		// Default colours and icons for all the folders of the mailbox
		$account = inboxAjaxManagedAccount((int) inboxAjaxParam('id', 'int'));
		$look = new InboxLook($db);
		$res = $look->resetFolders((int) $account->id);
		if ($res < 0) {
			inboxAjaxError($langs->transnoentities($look->error), 422);
		}
		inboxAjaxReply(array('changed' => $res));
		// no break

	default:
		inboxAjaxError('Unknown action', 400);
}
