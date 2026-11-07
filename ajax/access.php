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
 * \file    inbox/ajax/access.php
 * \ingroup inbox
 * \brief   Accesses of a mailbox (admins of the mailboxes only): list, give, change, remove, search of users and groups
 */

require __DIR__.'/inbox_ajax.inc.php';

$action = (string) inboxAjaxParam('action', 'aZ09');
inboxAjaxStart(in_array($action, array('grant', 'revoke'), true));
inboxAjaxNeedAdmin();
$access = new InboxAccess($db);

switch ($action) {
	case 'list':
		$account = inboxAjaxManagedAccount((int) inboxAjaxParam('id', 'int'), true);
		$owner = null;
		if ($account->type === InboxAccount::TYPE_PERSONAL && $account->fk_user_owner > 0) {
			$u = new User($db);
			if ($u->fetch((int) $account->fk_user_owner) > 0) {
				$owner = array('id' => (int) $u->id, 'name' => $u->getFullName($langs));
			}
		}
		inboxAjaxReply(array('accesses' => $access->listForAccount((int) $account->id), 'owner' => $owner));
		// no break

	case 'grant':
		$account = inboxAjaxManagedAccount((int) inboxAjaxParam('id', 'int'), true);
		$result = $access->grant($user, (int) $account->id, (int) inboxAjaxParam('fk_user', 'int'), (int) inboxAjaxParam('fk_usergroup', 'int'), (string) inboxAjaxParam('level', 'aZ09'));
		if ($result < 0) {
			inboxAjaxError($langs->transnoentities($access->error), 422);
		}
		inboxAjaxReply(array('changed' => $result > 0, 'accesses' => $access->listForAccount((int) $account->id)));
		// no break

	case 'revoke':
		$account = inboxAjaxManagedAccount((int) inboxAjaxParam('id', 'int'), true);
		$result = $access->revoke($user, (int) $account->id, (int) inboxAjaxParam('fk_user', 'int'), (int) inboxAjaxParam('fk_usergroup', 'int'));
		if ($result < 0) {
			inboxAjaxError($access->error, 500);
		}
		if ($result > 0) {
			// The people who cannot read the mailbox any more lose their assignments on its mails (lot W4 1.9.0)
			dol_include_once('/inbox/class/inboxassign.class.php');
			$assign = new InboxAssign($db);
			$assign->prune($user, (int) $account->id);
		}
		inboxAjaxReply(array('changed' => $result > 0, 'accesses' => $access->listForAccount((int) $account->id)));
		// no break

	case 'search':
		// Internal active users and groups of the entity, for the access picker
		$q = trim((string) inboxAjaxParam('q'));
		$like = $db->escape($db->escapeforlike($q));
		$out = array();
		$sql = "SELECT rowid, firstname, lastname, login FROM ".$db->prefix()."user WHERE statut = 1 AND (fk_soc IS NULL OR fk_soc = 0)";
		$sql .= " AND entity IN (0, ".((int) $conf->entity).")";
		if ($q !== '') {
			// Full name as shown in the list (« John BOTELLA »), in both orders, or a part of it
			$sql .= " AND (firstname LIKE '%".$like."%' OR lastname LIKE '%".$like."%' OR login LIKE '%".$like."%' OR email LIKE '%".$like."%'";
			$sql .= " OR CONCAT(firstname, ' ', lastname) LIKE '%".$like."%' OR CONCAT(lastname, ' ', firstname) LIKE '%".$like."%')";
		}
		$sql .= " ORDER BY lastname, firstname LIMIT 30";
		foreach ((array) $db->getRows($sql) as $row) {
			$name = trim($row->firstname.' '.$row->lastname);
			$out[] = array('kind' => 'user', 'id' => (int) $row->rowid, 'name' => $name !== '' ? $name : (string) $row->login);
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
