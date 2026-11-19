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
dol_include_once('/inbox/class/inboxuserparam.class.php');
dol_include_once('/inbox/class/inboxretention.class.php');
dol_include_once('/inbox/class/inboxlive.class.php');

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
			'legit' => $role === 'junk' && getDolGlobalInt('INBOX_JUNK_LEGIT_CHECK', 1) ? (int) (($legit = $db->getRow("SELECT COUNT(rowid) as n FROM ".$db->prefix()."inbox_mail WHERE fk_folder = ".((int) $f->rowid)." AND junk_hint = 'legit'")) ? $legit->n : 0) : 0,
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
		// Out of office (lot W8 1.23.0) not over yet: banner of the application, for every reader of the mailbox
		$aways = array();
		if ($accounts) {
			$rows = $db->getRows("SELECT rowid, away_from, away_to FROM ".$db->prefix()."inbox_account WHERE rowid IN (".implode(',', array_map('intval', array_keys($accounts))).")"
				." AND away_to IS NOT NULL AND away_to >= '".$db->escape(dol_print_date(dol_now(), '%Y-%m-%d', 'tzserver'))."' LIMIT 1000");
			foreach (is_array($rows) ? $rows : array() as $r) {
				$aways[(int) $r->rowid] = array('from' => substr((string) $r->away_from, 0, 10), 'to' => substr((string) $r->away_to, 0, 10));
			}
		}
		// Mails followed of each mailbox (out of the trash and the junk, as the view « Followed »): the entry « Followed »
		// of the mailbox in the tree, shown only when there is one
		$flagged = array();
		if ($accounts) {
			$rows = $db->getRows("SELECT m.fk_account, COUNT(m.rowid) AS n FROM ".$db->prefix()."inbox_mail as m"
				." INNER JOIN ".$db->prefix()."inbox_folder as f ON f.rowid = m.fk_folder AND f.status = 1"
				." WHERE m.fk_account IN (".implode(',', array_map('intval', array_keys($accounts))).") AND m.flagged = 1"
				." AND COALESCE(f.role, '') NOT IN ('trash', 'junk') GROUP BY m.fk_account LIMIT 1000");
			foreach (is_array($rows) ? $rows : array() as $r) {
				$flagged[(int) $r->fk_account] = (int) $r->n;
			}
		}
		$live = new InboxLive($db);
		foreach ($accounts as $account) {
			$account->loadSyncState();
			// Protected with its content (1.89.0): its folders read live on the server while unlocked, nothing when locked
			if ($account->isLive()) {
				$unlocked = InboxProtect::isUnlocked((int) $account->id);
				$liveTree = $unlocked ? $live->tree($account) : array();
				$list[] = array(
					'id' => (int) $account->id,
					'email' => (string) $account->email,
					'label' => (string) $account->label,
					'personal' => $account->type === InboxAccount::TYPE_PERSONAL && (int) $account->fk_user_owner === (int) $user->id,
					'level' => (string) $account->access_level,
					'color' => (string) InboxLook::cleanColor((string) $account->color),
					'icon' => (string) InboxLook::cleanIcon((string) $account->icon),
					'state' => $liveTree === null ? 'unreachable' : 'ok',
					'error' => $liveTree === null ? $live->error : '',
					'retry_at' => null,
					'progress' => null,
					'quota' => null,
					'folders' => is_array($liveTree) ? $liveTree : array(),
					'fulltext' => '',
					'away' => $aways[(int) $account->id] ?? null,
					'marks' => (object) array(),
					'tags' => array_values(array_map(function ($t) {
						return array('keyword' => $t['keyword'], 'label' => $t['label'], 'color' => $t['color'], 'border' => $t['border'], 'tint' => (int) $t['tint']);
					}, array_filter($tagHelper->listTags($account->tags_mode === 'own' ? (int) $account->id : 0), function ($t) {
						return (int) $t['status'] === 1;
					}))),
					'protected' => 1,
					'protect_level' => 'content',
					'locked' => $unlocked ? 0 : 1,
				);
				continue;
			}
			// New mails of this user (arrived since he last left the folder, still unread)
			$marks = $newMarks->marks($user, (int) $account->id);
			$news = $newMarks->counts($marks);
			$folderTree = inboxFolderTree((int) $account->id);
			foreach ($folderTree as $k => $f) {
				$folderTree[$k]['new'] = $news[$f['id']] ?? 0;
				// Trash and junk emptied after some days (1.41.0): told on the list of the folder
				if (in_array($f['role'], array('trash', 'junk'), true)) {
					$folderTree[$k]['purge'] = InboxRetention::daysOf($account, $f['role']);
				}
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
				'away' => $aways[(int) $account->id] ?? null,
				'marks' => (object) $marks,
				'flagged' => $flagged[(int) $account->id] ?? 0,
				// Tags of the mailbox (its own list or the global one): keyword => label, color, border and colour of the rows
				'tags' => array_values(array_map(function ($t) {
					return array('keyword' => $t['keyword'], 'label' => $t['label'], 'color' => $t['color'], 'border' => $t['border'], 'tint' => (int) $t['tint']);
				}, array_filter($tagHelper->listTags($account->tags_mode === 'own' ? (int) $account->id : 0), function ($t) {
					return (int) $t['status'] === 1;
				}))),
				// Protected for its password (1.89.0): the index as the others, synchronised while a session unlocked it
				'protected' => $account->isProtected() ? 1 : 0,
				'protect_level' => $account->isProtected() ? 'password' : '',
				'locked' => $account->isProtected() && !InboxProtect::isUnlocked((int) $account->id) ? 1 : 0,
			);
		}
		// Size of the folders (1.40.0), asked by the user: the mails indexed, summed by the database (no IMAP call)
		if ($accounts && (new InboxUserParam($db))->get($user, 'folder_sizes') === '1') {
			$bytes = array();
			$rows = $db->getRows("SELECT fk_folder, SUM(size) as b FROM ".$db->prefix()."inbox_mail WHERE fk_account IN (".implode(',', array_map('intval', array_keys($accounts))).")"
				." GROUP BY fk_folder LIMIT 100000");
			foreach (is_array($rows) ? $rows : array() as $r) {
				$bytes[(int) $r->fk_folder] = (float) $r->b;
			}
			foreach ($list as $i => $a) {
				foreach ($a['folders'] as $k => $f) {
					// A protected mailbox has no index to sum (the size is not known)
					if (!empty($a['protected'])) {
						continue;
					}
					$list[$i]['folders'][$k]['bytes'] = $bytes[(int) $f['id']] ?? 0;
				}
			}
		}
		// The order chosen by the user in My account (getAccounts gives it); without one, his personal mailbox first,
		// then the shared ones by label
		$order = json_decode((string) (new InboxUserParam($db))->get($user, 'accounts_order'), true);
		if (!is_array($order) || !$order) {
			usort($list, function ($a, $b) {
				return (int) $b['personal'] <=> (int) $a['personal'] ?: strcasecmp($a['label'] ?: $a['email'], $b['label'] ?: $b['email']);
			});
		}
		$live->close();
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
