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
 * \file    inbox/ajax/view.php
 * \ingroup inbox
 * \brief   Virtual views (1.31.0): list, save, remove, copy, hide, order
 */

require __DIR__.'/inbox_ajax.inc.php';
dol_include_once('/inbox/class/inboxview.class.php');

$action = (string) inboxAjaxParam('action', 'aZ09');
inboxAjaxStart(in_array($action, array('save', 'delete', 'copy', 'hide', 'count', 'order'), true));
$views = new InboxView($db);

/**
 * Answer of a refusal
 *
 * @param InboxView $views Helper
 * @return void
 */
function inboxViewFail(InboxView $views)
{
	global $langs;

	$codes = array('not_found' => array(404, 'InboxViewGone'), 'no_right' => array(403, 'InboxViewNoRight'), 'bad_label' => array(422, 'InboxViewNeedLabel'), 'too_many' => array(422, 'InboxViewTooMany'));
	$c = $codes[$views->errorCode] ?? array(500, 'InboxViewFailed');
	inboxAjaxReply(array('error' => $langs->transnoentities($c[1]), 'code' => $views->errorCode), $c[0]);
}

switch ($action) {
	case 'list':
		inboxAjaxReply(array('views' => $views->listFor($user)));
		// no break

	case 'save':
		$data = array(
			'id' => (int) inboxAjaxParam('id', 'int'),
			'label' => (string) inboxAjaxParam('label'),
			'icon' => (string) inboxAjaxParam('icon', 'aZ09'),
			'color' => (string) inboxAjaxParam('color', 'alphanohtml'),
			'search' => inboxAjaxParam('search', 'none'),
			'accounts' => inboxAjaxParam('accounts', 'none'),
			'show_count' => (int) inboxAjaxParam('show_count', 'int'),
			'shared_edit' => (int) inboxAjaxParam('shared_edit', 'int'),
		);
		if (inboxAjaxHas('shares')) {
			$data['shares'] = inboxAjaxParam('shares', 'none');
		}
		$id = $views->save($user, $data);
		if (!$id) {
			inboxViewFail($views);
		}
		inboxAjaxReply(array('id' => $id, 'views' => $views->listFor($user)));
		// no break

	case 'delete':
		if (!$views->delete($user, (int) inboxAjaxParam('id', 'int'))) {
			inboxViewFail($views);
		}
		inboxAjaxReply(array('views' => $views->listFor($user)));
		// no break

	case 'copy':
		$id = $views->copy($user, (int) inboxAjaxParam('id', 'int'));
		if (!$id) {
			inboxViewFail($views);
		}
		inboxAjaxReply(array('id' => $id, 'views' => $views->listFor($user)));
		// no break

	case 'hide':
		if (!$views->hide($user, (int) inboxAjaxParam('id', 'int'), (int) inboxAjaxParam('hide', 'int') === 1)) {
			inboxViewFail($views);
		}
		inboxAjaxReply(array('views' => $views->listFor($user)));
		// no break

	case 'count':
		// The number of unread of a view shared with the user: his choice (audit INBX-036)
		if (!$views->counted($user, (int) inboxAjaxParam('id', 'int'), (int) inboxAjaxParam('on', 'int') === 1)) {
			inboxViewFail($views);
		}
		inboxAjaxReply(array('views' => $views->listFor($user)));
		// no break

	case 'order':
		$ids = inboxAjaxParam('ids', 'none');
		$views->order($user, is_array($ids) ? $ids : array());
		inboxAjaxReply(array('views' => $views->listFor($user)));
		// no break

	case 'people':
		// Internal active users (not the user himself) and groups of the entity, for the sharing picker of a view
		$q = trim((string) inboxAjaxParam('q'));
		$like = $db->escape($db->escapeforlike($q));
		$out = array();
		// Found by name or address, never by login, and the login never given: no way to guess the logins (audit INBX-039)
		$sql = "SELECT rowid, firstname, lastname, email FROM ".$db->prefix()."user WHERE statut = 1 AND (fk_soc IS NULL OR fk_soc = 0)";
		$sql .= " AND entity IN (0, ".((int) $conf->entity).") AND rowid <> ".((int) $user->id);
		if ($q !== '') {
			$sql .= " AND (firstname LIKE '%".$like."%' OR lastname LIKE '%".$like."%' OR email LIKE '%".$like."%'";
			$sql .= " OR CONCAT(firstname, ' ', lastname) LIKE '%".$like."%' OR CONCAT(lastname, ' ', firstname) LIKE '%".$like."%')";
		}
		$sql .= " ORDER BY lastname, firstname LIMIT 30";
		foreach ((array) $db->getRows($sql) as $row) {
			$name = trim($row->firstname.' '.$row->lastname);
			$out[] = array('kind' => 'user', 'id' => (int) $row->rowid, 'name' => $name !== '' ? $name : ((string) $row->email !== '' ? (string) $row->email : '#'.((int) $row->rowid)));
		}
		$sql = "SELECT rowid, nom FROM ".$db->prefix()."usergroup WHERE entity IN (0, ".((int) $conf->entity).")";
		if ($q !== '') {
			$sql .= " AND nom LIKE '%".$like."%'";
		}
		$sql .= " ORDER BY nom LIMIT 20";
		foreach ((array) $db->getRows($sql) as $row) {
			$out[] = array('kind' => 'group', 'id' => (int) $row->rowid, 'name' => (string) $row->nom);
		}
		inboxAjaxReply(array('results' => $out));
		// no break

	default:
		inboxAjaxError('Unknown action', 400);
}
