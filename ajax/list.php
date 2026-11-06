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
 * \file    inbox/ajax/list.php
 * \ingroup inbox
 * \brief   Lists of mails from the index (pane 2): a page of a folder, a search, the state of the open folder
 */

require __DIR__.'/inbox_ajax.inc.php';
dol_include_once('/inbox/class/inboxsearchsql.class.php');

$action = (string) inboxAjaxParam('action', 'aZ09');
inboxAjaxStart(false);
$access = new InboxAccess($db);
$readable = array_keys(array_filter($access->getLevels($user), function ($level) {
	return InboxAccess::rank($level) >= InboxAccess::rank(InboxAccess::LEVEL_READ);
}));
// Only the active mailboxes
if ($readable) {
	$rows = $db->getRows("SELECT rowid FROM ".$db->prefix()."inbox_account WHERE rowid IN (".implode(',', array_map('intval', $readable)).") AND status = ".InboxAccount::STATUS_ACTIVE." LIMIT 10000");
	$readable = array_map(function ($r) {
		return (int) $r->rowid;
	}, is_array($rows) ? $rows : array());
}

/**
 * Folder asked, in a mailbox the user may read (else 404)
 *
 * @param int   $folderId Folder
 * @param int[] $readable Mailboxes the user may read
 * @return object Row (rowid, fk_account)
 */
function inboxListFolder($folderId, array $readable)
{
	global $db;

	$row = $folderId > 0 ? $db->getRow("SELECT rowid, fk_account, total, unread FROM ".$db->prefix()."inbox_folder WHERE rowid = ".((int) $folderId)." AND status = 1") : null;
	if (!is_object($row) || !in_array((int) $row->fk_account, $readable, true)) {
		inboxAjaxNotFound();
	}

	return $row;
}

/**
 * Version of the content of a folder: count, last row, last change, unread (a removal changes the count)
 *
 * @param int $folderId Folder
 * @return string
 */
function inboxListStamp($folderId)
{
	global $db;

	$row = $db->getRow("SELECT COUNT(rowid) AS n, SUM(CASE WHEN seen = 0 THEN 1 ELSE 0 END) AS u, MAX(tms) AS t, MAX(rowid) AS m FROM ".$db->prefix()."inbox_mail WHERE fk_folder = ".((int) $folderId));

	return (int) $row->n.':'.(int) $row->m.':'.($row->t ? (int) $db->jdate($row->t) : 0).':'.(int) $row->u;
}

switch ($action) {
	case 'page':
		// scope: folder (default), account (every folder of the mailbox of the folder), all (every mailbox)
		$scope = (string) inboxAjaxParam('scope', 'aZ09');
		$folderId = (int) inboxAjaxParam('folder', 'int');
		$accounts = $readable;
		if ($scope !== 'all') {
			$folder = inboxListFolder($folderId, $readable);
			$accounts = array((int) $folder->fk_account);
			if ($scope === 'account') {
				$folderId = 0;
			}
		} else {
			$folderId = 0;
		}
		$query = InboxSearchQuery::parse((string) inboxAjaxParam('q', 'alphanohtml'), $langs);
		$sort = explode(':', (string) inboxAjaxParam('sort', 'alphanohtml'));
		$query['sort'] = $sort[0] ?? 'date';
		$query['dir'] = ($sort[1] ?? 'desc') === 'asc' ? 'asc' : 'desc';
		foreach (array('unread', 'flagged', 'attachment') as $f) {
			if ((int) inboxAjaxParam($f, 'int') === 1) {
				$query[$f] = true;
			}
		}
		$tag = (string) inboxAjaxParam('tag', 'alphanohtml');
		if ($tag !== '') {
			$query['tag'][] = $tag;
		}
		$query['soc'] = (int) inboxAjaxParam('soc', 'int');
		$engine = new InboxSearchSql($db);
		$start = microtime(true);
		$page = $engine->search($accounts, $folderId, $query, (string) inboxAjaxParam('cursor', 'alphanohtml'), (int) (inboxAjaxParam('limit', 'int') ?: 200));
		$page['ms'] = (int) round((microtime(true) - $start) * 1000);
		// Version of the folder read with the page: the refresh compares with it (a change made just after the
		// page is not missed)
		$page['stamp'] = ($scope !== 'all' && $scope !== 'account') ? inboxListStamp((int) $folder->rowid) : '';
		// The pills of the search field
		$page['query'] = array_intersect_key($query, array_flip(array('words', 'from', 'to', 'subject', 'tag', 'before', 'after', 'attachment', 'unread')));
		inboxAjaxReply($page);
		// no break

	case 'state':
		// Every 20 s while the application is shown: counters of the tree, last change of the open folder
		$out = array('folders' => array(), 'open' => null);
		if ($readable) {
			$rows = $db->getRows("SELECT rowid, fk_account, total, unread FROM ".$db->prefix()."inbox_folder WHERE fk_account IN (".implode(',', $readable).") AND status = 1 LIMIT 20000");
			foreach (is_array($rows) ? $rows : array() as $r) {
				$out['folders'][] = array((int) $r->rowid, (int) $r->total, (int) $r->unread);
			}
			$sync = $db->getRows("SELECT rowid, sync_state FROM ".$db->prefix()."inbox_account WHERE rowid IN (".implode(',', $readable).") LIMIT 10000");
			$out['accounts'] = array();
			foreach (is_array($sync) ? $sync : array() as $r) {
				$out['accounts'][] = array((int) $r->rowid, (string) $r->sync_state);
			}
		}
		$folderId = (int) inboxAjaxParam('folder', 'int');
		if ($folderId > 0) {
			inboxListFolder($folderId, $readable);
			$out['open'] = array('folder' => $folderId, 'stamp' => inboxListStamp($folderId));
		}
		inboxAjaxReply($out);
		// no break

	case 'refresh':
		// Folder open in the application: synchronised now (lot W2 §8.3), unless done less than 15 s ago (several users on the same folder: one pass) or a pass
		// runs on the mailbox. Also the « Try again » of a mailbox in error (retry=1: even during the wait).
		$folder = inboxListFolder((int) inboxAjaxParam('folder', 'int'), $readable);
		$row = $db->getRow("SELECT date_sync FROM ".$db->prefix()."inbox_folder WHERE rowid = ".((int) $folder->rowid));
		$retry = (int) inboxAjaxParam('retry', 'int') === 1;
		if (!$retry && is_object($row) && $row->date_sync && (int) $db->jdate($row->date_sync) > dol_now() - 15) {
			inboxAjaxReply(array('done' => 0, 'reason' => 'recent'));
		}
		dol_include_once('/inbox/class/inboxsync.class.php');
		$account = new InboxAccount($db);
		if ($account->fetch((int) $folder->fk_account) <= 0) {
			inboxAjaxNotFound();
		}
		// The session is not needed any more: other calls of the page are not blocked during the pass
		session_write_close();
		$sync = new InboxSync($db);
		$res = $sync->run($account, microtime(true) + 10, array('folder_id' => (int) $folder->rowid, 'force' => $retry));
		inboxAjaxReply(array('done' => $res === 1 ? 1 : 0, 'stats' => $sync->stats, 'error' => $res < 0 ? $sync->error : ''));
		// no break

	default:
		inboxAjaxError('Unknown action', 400);
}
