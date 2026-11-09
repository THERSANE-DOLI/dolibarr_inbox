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
 * \file    inbox/ajax/tag.php
 * \ingroup inbox
 * \brief   Tags: global list (admins of the mailboxes) or own list of a mailbox (admins, or level manage)
 */

require __DIR__.'/inbox_ajax.inc.php';
dol_include_once('/inbox/class/inboxtag.class.php');

$action = (string) inboxAjaxParam('action', 'aZ09');
inboxAjaxStart(in_array($action, array('create', 'update', 'delete', 'reorder', 'mode'), true));
$tags = new InboxTag($db);
$accountId = (int) inboxAjaxParam('id', 'int');
$account = null;
if ($accountId > 0) {
	$account = inboxAjaxManagedAccount($accountId);
} elseif ($action !== 'list') {
	// Global list: only the admins change it
	inboxAjaxNeedAdmin();
}
// List changed: the own list of the mailbox, or the global one
$listId = ($account && $account->tags_mode === 'own') ? (int) $account->id : 0;

/**
 * @param InboxTag          $tags    Helper
 * @param InboxAccount|null $account Mailbox or null for the global list
 * @return array<string,mixed>
 */
function inboxTagReply(InboxTag $tags, $account)
{
	if ($account) {
		return array('mode' => $account->tags_mode, 'tags' => $tags->listTags($account->tags_mode === 'own' ? (int) $account->id : 0), 'global' => $tags->listTags(0));
	}

	return array('mode' => 'global', 'tags' => $tags->listTags(0));
}

switch ($action) {
	case 'list':
		inboxAjaxReply(inboxTagReply($tags, $account));
		// no break

	case 'create':
		if ($account && $account->tags_mode !== 'own') {
			// The global list is changed in Settings > General, not from a mailbox
			inboxAjaxNeedAdmin();
		}
		$result = $tags->create($user, (string) inboxAjaxParam('label'), (string) inboxAjaxParam('color'), $listId, (string) (inboxAjaxParam('border', 'aZ09') ?: 'none'));
		if ($result < 0) {
			inboxAjaxError($langs->transnoentities($tags->error), 422);
		}
		inboxAjaxReply(inboxTagReply($tags, $account));
		// no break

	case 'update':
	case 'delete':
		$tagId = (int) inboxAjaxParam('tag_id', 'int');
		$known = array_column($tags->listTags($listId), 'id');
		if (!in_array($tagId, $known, true)) {
			inboxAjaxNotFound();
		}
		if ($account && $listId === 0) {
			inboxAjaxNeedAdmin();
		}
		$result = $action === 'update'
			? $tags->update($user, $tagId, (string) inboxAjaxParam('label'), (string) inboxAjaxParam('color'), inboxAjaxParam('status', 'int') ? 1 : 0, (string) (inboxAjaxParam('border', 'aZ09') ?: 'none'))
			: $tags->delete($tagId);
		if ($result < 0) {
			inboxAjaxError($langs->transnoentities($tags->error), 422);
		}
		inboxAjaxReply(inboxTagReply($tags, $account));
		// no break

	case 'reorder':
		if ($account && $listId === 0) {
			inboxAjaxNeedAdmin();
		}
		$ids = inboxAjaxParam('ids', 'none');
		$tags->reorder($listId, array_map('intval', is_array($ids) ? $ids : array()));
		inboxAjaxReply(inboxTagReply($tags, $account));
		// no break

	case 'mode':
		if (!$account) {
			inboxAjaxNotFound();
		}
		if ($tags->setMode($user, $account, (string) inboxAjaxParam('mode', 'aZ09')) < 0) {
			inboxAjaxError($langs->transnoentities($tags->error), 422);
		}
		inboxAjaxReply(inboxTagReply($tags, $account));
		// no break

	default:
		inboxAjaxError('Unknown action', 400);
}
