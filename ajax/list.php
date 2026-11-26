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
dol_include_once('/inbox/class/inboxtextsearch.class.php');
dol_include_once('/inbox/class/inboxfulltext.class.php');
dol_include_once('/inbox/class/inboxuserparam.class.php');
dol_include_once('/inbox/class/inboxlive.class.php');

$action = (string) inboxAjaxParam('action', 'aZ09');
// The calls that write (presence, synchronisation, results of a search in the text) need POST and the token (audit INBX-017)
inboxAjaxStart(in_array($action, array('state', 'pump', 'refresh', 'content'), true));
$access = new InboxAccess($db);

/**
 * Lines of a list: the thirdparty of a line kept only when the user may read it (right of the thirdparties, and the
 * restriction « his own customers » of Dolibarr); else only that one exists (hidden, no number, no name)
 *
 * @param array<int,array<string,mixed>> $rows Lines
 * @return array<int,array<string,mixed>>
 */
function inboxListReadableSocs(array $rows)
{
	global $db, $user;

	$ids = array();
	foreach ($rows as $r) {
		if (!empty($r['soc']['id'])) {
			$ids[(int) $r['soc']['id']] = true;
		}
	}
	if (!$ids) {
		return $rows;
	}
	$allowed = array();
	if (isModEnabled('societe') && $user->hasRight('societe', 'lire')) {
		if ($user->hasRight('societe', 'client', 'voir')) {
			$allowed = $ids;
		} else {
			$own = $db->getRows("SELECT fk_soc FROM ".$db->prefix()."societe_commerciaux WHERE fk_user = ".((int) $user->id)." AND fk_soc IN (".implode(',', array_keys($ids)).") LIMIT 10000");
			foreach (is_array($own) ? $own : array() as $o) {
				$allowed[(int) $o->fk_soc] = true;
			}
		}
	}
	foreach ($rows as $k => $r) {
		if (!empty($r['soc']['id']) && !isset($allowed[(int) $r['soc']['id']])) {
			// The line says a thirdparty exists (never which one): the user asks for an access instead of creating it
			// again
			$rows[$k]['soc'] = array('id' => 0, 'name' => '', 'hidden' => true);
		}
	}

	return $rows;
}

/**
 * Live page of a protected mailbox (1.89.0): same answer as a page of the index, nothing of the index around it
 *
 * @param stdClass            $folder Folder (inboxListFolder)
 * @param array<string,mixed> $query  Parsed query
 * @return never
 */
function inboxListLivePage($folder, array $query)
{
	global $db, $inboxTypedQuery;

	$account = new InboxAccount($db);
	if ($account->fetch((int) $folder->fk_account) <= 0) {
		inboxAjaxNotFound();
	}
	$live = new InboxLive($db);
	$start = microtime(true);
	$page = $live->page($account, (int) $folder->rowid, $query, (string) inboxAjaxParam('cursor', 'alphanohtml'), (int) (inboxAjaxParam('limit', 'int') ?: 50));
	$live->close();
	if ($page === null) {
		inboxAjaxReply(array('error' => $live->error, 'code' => InboxProtect::isUnlocked((int) $account->id) ? 'live' : 'locked'), 502);
	}
	$page['threads'] = false;
	$page['people'] = new stdClass();
	$page['ms'] = (int) round((microtime(true) - $start) * 1000);
	$page['fulltext'] = array('ready' => array(), 'error' => false, 'relevance' => false);
	$page['crops'] = new stdClass();
	$page['live'] = 1;
	$page['query'] = array_intersect_key((array) $inboxTypedQuery, array_flip(array('words', 'from', 'to', 'subject', 'tag', 'before', 'after', 'attachment', 'unread')));
	inboxAjaxReply($page);
}

$readable = array_keys(array_filter($access->getLevels($user), function ($level) {
	return InboxAccess::rank($level) >= InboxAccess::rank(InboxAccess::LEVEL_READ);
}));
// A protected mailbox not unlocked in this session: its index and its cache are never read (audit INBX-055).
// Its folders are in the tree all the same: asked, it answers « locked » and not « not found », the page unlocks it
// in silence just after the first load of a new session (PWA) and loads the list again.
$inboxLockedReadable = array_values(array_intersect($readable, (new InboxProtect($db))->lockedIds()));
$readable = array_values(array_diff($readable, $inboxLockedReadable));
// Only the active mailboxes
if ($readable) {
	$rows = $db->getRows("SELECT rowid FROM ".$db->prefix()."inbox_account WHERE rowid IN (".implode(',', array_map('intval', $readable)).") AND status = ".InboxAccount::STATUS_ACTIVE." LIMIT 10000");
	$readable = array_map(function ($r) {
		return (int) $r->rowid;
	}, is_array($rows) ? $rows : array());
}

/**
 * Mailbox the user may read but protected and not unlocked in this session: answer « locked » (423) and stop
 *
 * @param int $accountId Mailbox of the folder asked
 * @return void
 */
function inboxListLockedStop($accountId)
{
	global $langs, $inboxLockedReadable;

	if ($accountId > 0 && in_array($accountId, (array) $inboxLockedReadable, true)) {
		inboxAjaxReply(array('error' => $langs->transnoentities('InboxProtectLockedShort'), 'code' => 'locked', 'account' => $accountId, 'fields' => new stdClass()), 423);
	}
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

	// Folder of a protected mailbox (1.89.0): a number of the live reading, known in this session only
	if (InboxLive::isLive($folderId) && !InboxLive::isMail($folderId)) {
		$accountId = InboxLive::accountOf($folderId);
		if (!in_array($accountId, $readable, true) || InboxLive::folderPath($folderId) === null) {
			inboxAjaxNotFound();
		}
		$live = new stdClass();
		$live->rowid = (int) $folderId;
		$live->fk_account = $accountId;
		$live->total = 0;
		$live->unread = 0;
		$live->live = 1;

		return $live;
	}
	$row = $folderId > 0 ? $db->getRow("SELECT rowid, fk_account, total, unread FROM ".$db->prefix()."inbox_folder WHERE rowid = ".((int) $folderId)." AND status = 1") : null;
	inboxListLockedStop(is_object($row) ? (int) $row->fk_account : 0);
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

	// Count, last arrival (sort key of the connector: also the mark of the new mails), last change, unread
	$row = $db->getRow("SELECT COUNT(rowid) AS n, SUM(CASE WHEN seen = 0 THEN 1 ELSE 0 END) AS u, MAX(tms) AS t, MAX(sort_key) AS m FROM ".$db->prefix()."inbox_mail WHERE fk_folder = ".((int) $folderId));

	return (int) $row->n.':'.(int) $row->m.':'.($row->t ? (int) $db->jdate($row->t) : 0).':'.(int) $row->u;
}

/**
 * Search of the input: words and operators, sort, quick filters
 *
 * @return array<string,mixed>
 */
function inboxListQuery()
{
	global $langs, $user;

	global $inboxTypedQuery;

	$query = InboxSearchQuery::parse((string) inboxAjaxParam('q', 'alphanohtml'), $langs);
	// Pills of the field typed by the user, without the ones chosen in the search panel
	$inboxTypedQuery = $query;
	// Criteria chosen in the search panel (lot W7 1.19.0): operators in English, merged with the typed ones
	$more = InboxSearchQuery::parse((string) inboxAjaxParam('qx', 'alphanohtml'));
	foreach (array('from', 'to', 'subject', 'tag') as $k) {
		$query[$k] = array_merge($query[$k], $more[$k]);
	}
	foreach (array('attachment', 'unread') as $k) {
		$query[$k] = $query[$k] || $more[$k];
	}
	if ($more['after'] > $query['after']) {
		$query['after'] = $more['after'];
	}
	if ($more['before'] && (!$query['before'] || $more['before'] < $query['before'])) {
		$query['before'] = $more['before'];
	}
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
	// « My mails » (lot W4 1.9.0): the mails where the user is assigned; out of a folder, the archived ones only if asked
	if ((int) inboxAjaxParam('mine', 'int') === 1) {
		$query['mine'] = (int) $user->id;
		$query['mine_archived'] = (int) inboxAjaxParam('archived', 'int') === 1;
	}

	return $query;
}

/**
 * Rows found in their text by the deep search of the same search, when the list asks for them (text=1)
 *
 * @param array<string,mixed> $query    Parsed query, text_ids added
 * @param string              $scope    folder, account, all
 * @param int                 $scopeId  Folder, mailbox or 0
 * @param int[]               $readable Mailboxes the user may read
 * @return void
 */
function inboxListTextIds(array &$query, $scope, $scopeId, array $readable)
{
	global $db, $user;

	if ((int) inboxAjaxParam('text', 'int') !== 1 || empty($query['words'])) {
		return;
	}
	$search = new InboxTextSearch($db);
	$query['text_ids'] = $search->load($user, InboxTextSearch::key($user, $query, $scope, $scopeId), $readable);
}

/**
 * Meilisearch (lot W7 1.20.0): the words searched in the indexes of the mailboxes of the scope that are indexed; the
 * mails found join the list (rows read again through the index: rights and filters), most relevant first when the
 * list is in its default order
 *
 * @param array<string,mixed> $query    Parsed query, text_ids / rank / sort changed
 * @param int[]               $accounts Mailboxes of the scope (readable)
 * @return array{ready:int[],error:bool,relevance:bool,crops:array<int,string>}
 */
function inboxListFulltext(array &$query, array $accounts)
{
	global $db;

	$out = array('ready' => array(), 'error' => false, 'relevance' => false, 'crops' => array());
	if (empty($query['words']) || !InboxFulltext::enabled()) {
		return $out;
	}
	$ft = new InboxFulltext($db);
	$res = $ft->search($accounts, $query['words']);
	$out['ready'] = $res['ready'];
	$out['error'] = $res['error'];
	if ($res['ids']) {
		$query['text_ids'] = array_merge((array) ($query['text_ids'] ?? array()), $res['ids']);
		$query['rank'] = $res['ids'];
		if (($query['sort'] ?? 'date') === 'date' && ($query['dir'] ?? 'desc') === 'desc') {
			$query['sort'] = 'relevance';
			$out['relevance'] = true;
		}
		$out['crops'] = $res['crops'];
	}

	return $out;
}

/** Unified views (1.29.0): what each one lists, on top of what the user types */
const INBOX_LIST_VIEWS = array(
	'inbox' => array('roles' => array('inbox')),
	'unread' => array('unread' => true, 'not_roles' => array('trash', 'junk', 'sent', 'drafts')),
	'flagged' => array('flagged' => true, 'not_roles' => array('trash', 'junk')),
	'mine' => array('mine' => true),
	'sent' => array('roles' => array('sent')),
	'drafts' => array('roles' => array('drafts')),
);

switch ($action) {
	case 'ids':
		// « Select the 3 245 mails of the folder » (lot W4 point 3): their rows, the browser sends the action by batches
		$folder = inboxListFolder((int) inboxAjaxParam('folder', 'int'), $readable);
		$query = inboxListQuery();
		if (!empty($folder->live)) {
			// Protected mailbox: every UID matching, numbered for the session
			$account = new InboxAccount($db);
			$account->fetch((int) $folder->fk_account);
			$live = new InboxLive($db);
			$c = $live->connector($account);
			$path = (string) InboxLive::folderPath((int) $folder->rowid);
			$empty = false;
			$found = $c ? $c->liveSearch($path, InboxLive::criteria($query, $empty)) : null;
			if ($found === null || $empty) {
				inboxAjaxReply(array('ids' => array(), 'more' => false));
			}
			$uids = array_slice($found['uids'], 0, 20000);
			$ids = InboxLive::mailIds((int) $account->id, $path, array_map(function ($u) use ($found) {
				return $found['uidvalidity'].':'.$u;
			}, $uids));
			$live->close();
			inboxAjaxReply(array('ids' => array_values($ids), 'more' => count($found['uids']) > 20000));
		}
		inboxListTextIds($query, 'folder', (int) $folder->rowid, $readable);
		inboxListFulltext($query, array((int) $folder->fk_account));
		$engine = new InboxSearchSql($db);
		$ids = array();
		$cursor = '';
		do {
			$page = $engine->search(array((int) $folder->fk_account), (int) $folder->rowid, $query, $cursor, 500);
			foreach ($page['rows'] as $r) {
				$ids[] = (int) $r['id'];
			}
			$cursor = (string) $page['next'];
		} while ($cursor !== '' && count($ids) < 20000);
		inboxAjaxReply(array('ids' => $ids, 'more' => $cursor !== ''));
		// no break

	case 'page':
		// scope: folder (default), account (every folder of the mailbox of the folder), all (every mailbox), view (a
		// unified view: every mailbox read and not left out by the user, filtered by the role of the folders, 1.29.0)
		$scope = (string) inboxAjaxParam('scope', 'aZ09');
		$folderId = (int) inboxAjaxParam('folder', 'int');
		$accounts = $readable;
		$view = '';
		if ($scope === 'view') {
			$view = (string) inboxAjaxParam('view', 'aZ09');
			if (!isset(INBOX_LIST_VIEWS[$view])) {
				inboxAjaxError('Unknown view', 400);
			}
			// The mailboxes of the view: its own list, the common list, or all of them (InboxUnifiedView, 1.141.0)
			dol_include_once('/inbox/class/inboxunifiedview.class.php');
			$params = new InboxUserParam($db);
			$accounts = InboxUnifiedView::accounts($readable, $view, (string) $params->get($user, 'unified_exclude'), (string) $params->get($user, 'views_scope'));
			if (is_array(inboxAjaxParam('accounts', 'none'))) {
				// The view in one mailbox (entry « Followed » of a mailbox in the tree): that one if the user reads it,
				// even left out of the view
				$accounts = array_values(array_intersect($readable, array_map('intval', inboxAjaxParam('accounts', 'none'))));
			}
			if (!$accounts) {
				// Every mailbox left out of the views: nothing to list
				inboxAjaxReply(array('rows' => array(), 'next' => '', 'total' => 0, 'threads' => false, 'people' => (object) array(), 'stamp' => '',
					'query' => array(), 'fulltext' => array('ready' => array(), 'error' => false, 'relevance' => false), 'crops' => (object) array()));
			}
			$scope = 'all';
			$folderId = 0;
		} elseif ($scope === 'all' && is_array(inboxAjaxParam('accounts', 'none'))) {
			// A virtual view on some mailboxes (1.31.0): those of them the user reads, nothing else
			$accounts = array_values(array_intersect($readable, array_map('intval', inboxAjaxParam('accounts', 'none'))));
			if (!$accounts) {
				inboxAjaxReply(array('rows' => array(), 'next' => '', 'total' => 0, 'threads' => false, 'people' => (object) array(), 'stamp' => '',
					'query' => array(), 'fulltext' => array('ready' => array(), 'error' => false, 'relevance' => false), 'crops' => (object) array()));
			}
			$folderId = 0;
		} elseif ($scope !== 'all') {
			$folder = inboxListFolder($folderId, $readable);
			if (!empty($folder->live)) {
				// A protected mailbox is read one folder at a time on its server (no search over the whole mailbox)
				inboxListLivePage($folder, inboxListQuery());
			}
			$accounts = array((int) $folder->fk_account);
			if ($scope === 'account') {
				$folderId = 0;
			}
		} else {
			$folderId = 0;
		}
		$query = inboxListQuery();
		if ((int) inboxAjaxParam('vcount', 'int') === 1) {
			// Counter of a virtual view (1.31.0): the unread as the unified view counts them
			$query['not_roles'] = INBOX_LIST_VIEWS['unread']['not_roles'];
		}
		if ($view !== '') {
			$query = array_merge($query, INBOX_LIST_VIEWS[$view]);
			if (!empty($query['mine'])) {
				$query['mine'] = (int) $user->id;
			}
		}
		inboxListTextIds($query, $scope === 'all' ? 'all' : ($scope === 'account' ? 'account' : 'folder'), $scope === 'all' ? 0 : ($scope === 'account' ? (int) $folder->fk_account : (int) $folder->rowid), $readable);
		$fulltext = inboxListFulltext($query, $accounts);
		$engine = new InboxSearchSql($db);
		$start = microtime(true);
		// Conversations: one line per thread, when the list is sorted by date (a search lists the mails one by one)
		$threads = (int) inboxAjaxParam('threads', 'int') === 1 && $query['sort'] === 'date' && InboxSearchQuery::isEmpty($query);
		$cursor = (string) inboxAjaxParam('cursor', 'alphanohtml');
		$limit = (int) (inboxAjaxParam('limit', 'int') ?: 200);
		$page = $threads ? $engine->searchThreads($accounts, $folderId, $query, $cursor, $limit) : $engine->search($accounts, $folderId, $query, $cursor, $limit);
		$page['threads'] = $threads;
		// The thirdparty of each line as Dolibarr shows it: with the right to read the thirdparties, his own customers
		// only for a sales representative restricted to them; else « Thirdparty not accessible » (neither its name nor
		// its number)
		$page['rows'] = inboxListReadableSocs($page['rows']);
		// People assigned to each line, and their names
		dol_include_once('/inbox/class/inboxassign.class.php');
		$assign = new InboxAssign($db);
		$page['people'] = (object) $assign->decorate($page['rows'], $threads);
		// Threads ignored (the mailbox) or watched (this user)
		dol_include_once('/inbox/class/inboxthreadmark.class.php');
		$threadMarks = new InboxThreadMark($db);
		$threadMarks->decorate($user, $page['rows']);
		// Mails sent not delivered to someone (1.25.0)
		dol_include_once('/inbox/class/inboxbounce.class.php');
		$bounces = new InboxBounce($db);
		$bounces->decorate($page['rows']);
		$page['ms'] = (int) round((microtime(true) - $start) * 1000);
		// Version of the folder read with the page: the refresh compares with it (a change made just after the
		// page is not missed)
		$page['stamp'] = ($scope !== 'all' && $scope !== 'account') ? inboxListStamp((int) $folder->rowid) : '';
		// The pills of the search field
		// Meilisearch: mailboxes fully indexed (their text needs no deep search), error, order, crops of the rows shown
		$page['fulltext'] = array('ready' => $fulltext['ready'], 'error' => $fulltext['error'], 'relevance' => $fulltext['relevance']);
		$page['crops'] = new stdClass();
		foreach ($page['rows'] as $r) {
			if (isset($fulltext['crops'][(int) $r['id']])) {
				$page['crops']->{(int) $r['id']} = $fulltext['crops'][(int) $r['id']];
			}
		}
		$page['query'] = array_intersect_key($inboxTypedQuery, array_flip(array('words', 'from', 'to', 'subject', 'tag', 'before', 'after', 'attachment', 'unread')));
		inboxAjaxReply($page);
		// no break

	case 'content':
		// Deep search (lot W7 1.19.0): the text of the mails of ONE mailbox searched by its mail server, within
		// 20 s; the browser calls the mailboxes one after the other, then reads the list again with text=1
		$accountId = (int) inboxAjaxParam('account', 'int');
		$scope = (string) inboxAjaxParam('scope', 'aZ09');
		if (!in_array($accountId, $readable, true)) {
			inboxAjaxNotFound();
		}
		$folderId = 0;
		$scopeId = 0;
		if ($scope === 'all') {
			$scopeId = 0;
		} else {
			$folder = inboxListFolder((int) inboxAjaxParam('folder', 'int'), $readable);
			if ((int) $folder->fk_account !== $accountId) {
				inboxAjaxNotFound();
			}
			$scope = $scope === 'account' ? 'account' : 'folder';
			$folderId = (int) $folder->rowid;
			$scopeId = $scope === 'account' ? $accountId : $folderId;
		}
		$query = inboxListQuery();
		if (empty($query['words'])) {
			inboxAjaxError($langs->transnoentities('InboxDeepSearchNoWords'));
		}
		$account = new InboxAccount($db);
		if ($account->fetch($accountId) <= 0) {
			inboxAjaxNotFound();
		}
		// The session is released: a slow mail server never blocks the other calls of the user
		session_write_close();
		$search = new InboxTextSearch($db);
		$start = microtime(true);
		$result = $search->run($account, $search->folders($accountId, $scope, $folderId), $query, $start + InboxTextSearch::DEADLINE);
		$search->save($user, InboxTextSearch::key($user, $query, $scope, $scopeId), $accountId, $result['ids']);
		inboxAjaxReply(array(
			'account' => $accountId,
			'found' => count($result['ids']),
			'complete' => $result['complete'],
			'done' => $result['done'],
			'total' => $result['total'],
			'unsupported' => $result['unsupported'],
			'ms' => (int) round((microtime(true) - $start) * 1000),
		));
		// no break

	case 'suggest':
		// Suggestions of the search panel (lot W7 1.19.0): known senders and third parties of the readable mailboxes
		// kind (1.22.0): from = after de:, to = after à: (the recipients of the mails, then the people of Dolibarr)
		$text = trim((string) inboxAjaxParam('q', 'alphanohtml'));
		$kind = (string) inboxAjaxParam('kind', 'aZ09');
		$out = array('senders' => array(), 'socs' => array(), 'people' => array());
		if (mb_strlen($text, 'UTF-8') >= 2 && $readable) {
			$like = "'%".$db->escape($db->escapeforlike(mb_substr($text, 0, 100, 'UTF-8')))."%'";
			$in = implode(',', $readable);
			if ($kind === 'to') {
				$rows = $db->getRows("SELECT a.email AS from_email, MAX(a.name) AS from_name, COUNT(a.rowid) AS n FROM ".$db->prefix()."inbox_mail_address as a"
					." INNER JOIN ".$db->prefix()."inbox_mail as m ON m.rowid = a.fk_mail WHERE m.fk_account IN (".$in.") AND a.type IN ('to', 'cc')"
					." AND (a.name LIKE ".$like." OR a.email LIKE ".$like.") GROUP BY a.email ORDER BY n DESC LIMIT 5");
			} else {
				$rows = $db->getRows("SELECT m.from_email, MAX(m.from_name) AS from_name, COUNT(m.rowid) AS n FROM ".$db->prefix()."inbox_mail as m"
					." WHERE m.fk_account IN (".$in.") AND m.from_email <> '' AND (m.from_name LIKE ".$like." OR m.from_email LIKE ".$like.")"
					." GROUP BY m.from_email ORDER BY n DESC LIMIT ".($kind === 'from' ? 5 : 4));
			}
			foreach (is_array($rows) ? $rows : array() as $r) {
				$out['senders'][] = array('email' => strtolower((string) $r->from_email), 'name' => (string) $r->from_name, 'n' => (int) $r->n);
			}
			if ($kind === 'from' || $kind === 'to') {
				// Then the contacts, third parties and users of Dolibarr the user may see (as the recipients of a writing)
				dol_include_once('/inbox/class/inboxcomposer.class.php');
				$composer = new InboxComposer($db);
				$known = array_column($out['senders'], 'email');
				foreach ($composer->recipients($user, $text) as $r) {
					if ($r['kind'] !== 'recent' && !in_array($r['email'], $known, true) && count($out['people']) < 5) {
						$out['people'][] = array('email' => $r['email'], 'name' => $r['name'], 'detail' => $r['detail']);
					}
				}
				inboxAjaxReply($out);
			}
			// Third parties only with the right to read them, and only those of a sales representative when he sees
			// only his own (audit INBX-020)
			$socRight = isModEnabled('societe') && $user->hasRight('societe', 'lire');
			$restrict = $socRight && !$user->hasRight('societe', 'client', 'voir') ? " AND EXISTS (SELECT sc.rowid FROM ".$db->prefix()."societe_commerciaux as sc WHERE sc.fk_soc = s.rowid AND sc.fk_user = ".((int) $user->id).")" : '';
			$rows = !$socRight ? array() : $db->getRows("SELECT s.rowid, s.nom FROM ".$db->prefix()."societe as s WHERE s.entity IN (".getEntity('societe').") AND s.nom LIKE ".$like.$restrict
				." AND EXISTS (SELECT m.rowid FROM ".$db->prefix()."inbox_mail as m WHERE m.fk_soc = s.rowid AND m.fk_account IN (".$in."))"
				." ORDER BY s.nom LIMIT 3");
			foreach (is_array($rows) ? $rows : array() as $r) {
				$out['socs'][] = array('id' => (int) $r->rowid, 'name' => (string) $r->nom);
			}
		}
		inboxAjaxReply($out);
		// no break

	case 'state':
		// Every 20 s while the application is shown: counters of the tree, last change of the open folder
		$out = array('folders' => array(), 'open' => null);
		if ($readable) {
			dol_include_once('/inbox/class/inboxnewmarks.class.php');
			$newMarks = new InboxNewMarks($db);
			$news = array();
			foreach ($readable as $accId) {
				$news += $newMarks->counts($newMarks->marks($user, (int) $accId));
			}
			$rows = $db->getRows("SELECT rowid, fk_account, total, unread FROM ".$db->prefix()."inbox_folder WHERE fk_account IN (".implode(',', $readable).") AND status = 1 LIMIT 20000");
			foreach (is_array($rows) ? $rows : array() as $r) {
				$out['folders'][] = array((int) $r->rowid, (int) $r->total, (int) $r->unread, $news[(int) $r->rowid] ?? 0);
			}
			$sync = $db->getRows("SELECT rowid, sync_state FROM ".$db->prefix()."inbox_account WHERE rowid IN (".implode(',', $readable).") LIMIT 10000");
			$out['accounts'] = array();
			foreach (is_array($sync) ? $sync : array() as $r) {
				$out['accounts'][] = array((int) $r->rowid, (string) $r->sync_state);
			}
		}
		// The bell: unread notifications and the last one (a new one rings in every tab)
		dol_include_once('/inbox/class/inboxnotification.class.php');
		$notify = new InboxNotification($db);
		$out['notif'] = $notify->counts($user) + $notify->mailCount($user);
		// Presence in the shared mailboxes: the mail open here is kept (60 s), the other users reading it are given back
		dol_include_once('/inbox/class/inboxuserparam.class.php');
		$mailId = (int) inboxAjaxParam('mail', 'int');
		// Lot W6: the mail answered in a writing open in front counts more than the mail read
		$writing = (int) inboxAjaxParam('writing', 'int');
		$params = new InboxUserParam($db);
		$mine = '';
		if ($writing > 0) {
			$mailId = $writing;
		}
		if ($mailId > 0) {
			$m = $db->getRow("SELECT m.fk_account, m.message_id, a.type FROM ".$db->prefix()."inbox_mail as m INNER JOIN ".$db->prefix()."inbox_account as a ON a.rowid = m.fk_account WHERE m.rowid = ".$mailId);
			if (is_object($m) && in_array((int) $m->fk_account, $readable, true) && $m->type === InboxAccount::TYPE_SHARED && (string) $m->message_id !== '') {
				$key = (string) json_encode(array('a' => (int) $m->fk_account, 'm' => (string) $m->message_id));
				$mine = $writing > 0 ? substr($key, 0, -1).',"w":1}' : $key;
				// The others on the same mail, reading it or writing an answer (same key, « w » added)
				$like = $db->escape($db->escapeforlike(substr($key, 0, -1)));
				$others = $db->getRows("SELECT u.rowid, u.entity, u.firstname, u.lastname, u.login, u.photo, p.value FROM ".$db->prefix()."inbox_userparam as p INNER JOIN ".$db->prefix()."user as u ON u.rowid = p.fk_user"
					." WHERE p.param = 'presence' AND p.fk_account = 0 AND p.fk_user <> ".((int) $user->id)." AND (p.value = '".$db->escape($key)."' OR p.value LIKE '".$like."%')"
					." AND p.tms > '".$db->idate(dol_now() - 60)."' AND u.statut = 1 LIMIT 20");
				$out['presence'] = array('mail' => $mailId, 'users' => array_map(function ($u) {
					return array('id' => (int) $u->rowid, 'name' => trim($u->firstname.' '.$u->lastname) ?: (string) $u->login, 'writing' => strpos((string) $u->value, '"w":1') !== false,
						'photo' => InboxAccess::userPhotoUrl((int) $u->rowid, (string) $u->photo, (int) $u->entity));
				}, is_array($others) ? $others : array()));
			}
		}
		if ($mine === '') {
			if ($params->get($user, 'presence') !== '') {
				$params->remove($user, 'presence');
			}
		} else {
			if ($mine !== $params->get($user, 'presence')) {
				$params->set($user, 'presence', $mine);
			}
			// The date (written with the clock of PHP, as the one compared above) says the user is still there
			$db->query("UPDATE ".$db->prefix()."inbox_userparam SET tms = '".$db->idate(dol_now())."' WHERE fk_user = ".((int) $user->id)." AND fk_account = 0 AND param = 'presence'");
		}
		$folderId = (int) inboxAjaxParam('folder', 'int');
		if ($folderId > 0) {
			inboxListFolder($folderId, $readable);
			$out['open'] = array('folder' => $folderId, 'stamp' => inboxListStamp($folderId));
		} elseif (InboxLive::isLive($folderId)) {
			// Protected mailbox (1.89.0): STATUS of the open folder at the rhythm of this call, its counters given to the tree
			$folder = inboxListFolder($folderId, $readable);
			$account = new InboxAccount($db);
			$account->fetch((int) $folder->fk_account);
			$live = new InboxLive($db);
			$c = $live->connector($account);
			$st = $c ? $c->folderStatus((string) InboxLive::folderPath($folderId)) : null;
			$live->close();
			if ($st) {
				$out['folders'][] = array($folderId, (int) $st['total'], (int) $st['unread'], 0);
				$out['open'] = array('folder' => $folderId, 'stamp' => (string) $st['token']);
			}
		}
		inboxAjaxReply($out);
		// no break

	case 'pump':
		// Synchronisation driven by the browser, called by ONE tab only (the leader tab of the browser):
		// 1. the folder asked (open in a tab), unless synchronised less than 15 s ago;
		// 2. when the scheduled job does not run (no run for 3 min), a pass on the mailboxes of the user, the least
		//    recent first (inbox and sent, folders due, first indexing), within 10 s per call; « more » tells the tab
		//    to call again soon while a first indexing is left.
		// The session is released first: a slow mail server never blocks the other calls of the user. The lock per
		// mailbox makes several users (or browsers) never work twice on the same mailbox.
		dol_include_once('/inbox/class/inboxsync.class.php');
		$folderId = (int) inboxAjaxParam('folder', 'int');
		$folder = $folderId > 0 ? inboxListFolder($folderId, $readable) : null;
		// « Refresh » clicked by the user (1.97.0): the folder synchronised again after 3 s instead of 15 s, never at
		// each click
		$minAge = (int) inboxAjaxParam('force', 'int') ? 3 : 15;
		$cron =$db->getRow("SELECT datelastrun, status FROM ".$db->prefix()."cronjob WHERE classesname = '/inbox/class/inboxsync.class.php' AND methodename = 'cronRun'");
		$cronAlive = is_object($cron) && (int) $cron->status === 1 && $cron->datelastrun && (int) $db->jdate($cron->datelastrun) > dol_now() - 180;
		session_write_close();
		$deadline = microtime(true) + 10;
		$sync = new InboxSync($db);
		$stats = array('new' => 0, 'changed' => 0, 'deleted' => 0, 'folders' => 0);
		$add = function () use ($sync, &$stats) {
			foreach ($stats as $k => $v) {
				$stats[$k] = $v + (int) ($sync->stats[$k] ?? 0);
			}
		};
		$done = array();
		// Why the folder was not synchronised: recent (done a moment ago), busy (a pass holds the mailbox, try again)
		$skipped = '';
		if ($folder) {
			$row = $db->getRow("SELECT date_sync FROM ".$db->prefix()."inbox_folder WHERE rowid = ".((int) $folder->rowid));
			if (!is_object($row) || !$row->date_sync || (int) $db->jdate($row->date_sync) <= dol_now() - $minAge) {
				$account = new InboxAccount($db);
				if ($account->fetch((int) $folder->fk_account) > 0 && $sync->run($account, microtime(true) + 8, array('folder_id' => (int) $folder->rowid)) === 1) {
					$add();
					$done[] = 'folder';
				} else {
					$skipped = 'busy';
				}
			} else {
				$skipped = 'recent';
			}
		}
		// « Refresh » clicked: the folder asked only, the answer at once; the passes on the mailboxes (protected ones,
		// no scheduled job) wait for the next automatic call, else the click waited for them (up to 10 s more)
		if ($minAge === 3) {
			inboxAjaxReply(array('cron' => $cronAlive, 'done' => $done, 'stats' => $stats, 'more' => false, 'skipped' => $skipped));
		}
		$more = false;
		// Protected for their password (1.89.0): the scheduled job cannot open them, the page of a user who unlocked them
		// synchronises them, whatever the job does (one pass per minute at most, the least recent first)
		if ($readable) {
			$rows = $db->getRows("SELECT rowid FROM ".$db->prefix()."inbox_account WHERE rowid IN (".implode(',', $readable).") AND status = ".InboxAccount::STATUS_ACTIVE
				." AND protected = 1 AND protect_level = 'password' AND (date_sync IS NULL OR date_sync < '".$db->idate(dol_now() - 60)."') ORDER BY date_sync ASC LIMIT 20");
			foreach (is_array($rows) ? $rows : array() as $r) {
				if (microtime(true) > $deadline - 2) {
					$more = true;
					break;
				}
				$account = new InboxAccount($db);
				if ($account->fetch((int) $r->rowid) > 0 && $account->canWorkNow() && $sync->run($account, $deadline, array()) === 1) {
					$add();
					$done[] = (int) $r->rowid;
					$account->loadSyncState();
					$more = $more || $account->sync_state === 'first_sync';
				}
			}
		}
		if (!$cronAlive && $readable) {
			// A first indexing is left: state « first_sync », or a folder not indexed yet (index rebuilt, folder followed
			// again, mailbox just enabled)
			$firstLeft = "(sync_state = 'first_sync' OR EXISTS (SELECT f.rowid FROM ".$db->prefix()."inbox_folder as f WHERE f.fk_account = ".$db->prefix()."inbox_account.rowid"
				." AND f.status = 1 AND f.selectable = 1 AND f.sync_enabled = 1 AND f.first_sync_done = 0))";
			$rows = $db->getRows("SELECT rowid FROM ".$db->prefix()."inbox_account WHERE rowid IN (".implode(',', $readable).") AND status = ".InboxAccount::STATUS_ACTIVE
				." AND (date_sync IS NULL OR date_sync < '".$db->idate(dol_now() - 60)."' OR ".$firstLeft.") ORDER BY date_sync ASC LIMIT 100");
			foreach (is_array($rows) ? $rows : array() as $r) {
				if (microtime(true) > $deadline - 2) {
					$more = true;
					break;
				}
				$account = new InboxAccount($db);
				if ($account->fetch((int) $r->rowid) > 0 && $sync->run($account, $deadline, array()) === 1) {
					$add();
					$done[] = (int) $r->rowid;
				}
			}
			$left = $db->getRow("SELECT COUNT(rowid) AS n FROM ".$db->prefix()."inbox_account WHERE rowid IN (".implode(',', $readable).") AND status = ".InboxAccount::STATUS_ACTIVE
				." AND ".$firstLeft." AND (sync_retry_at IS NULL OR sync_retry_at < '".$db->idate(dol_now())."')");
			$more = $more || (is_object($left) && (int) $left->n > 0);
		}
		inboxAjaxReply(array('cron' => $cronAlive, 'done' => $done, 'stats' => $stats, 'more' => $more));
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
