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
		$before = $access->getLevel($user, (int) $account->id);
		$result = $access->grant($user, (int) $account->id, (int) inboxAjaxParam('fk_user', 'int'), (int) inboxAjaxParam('fk_usergroup', 'int'), (string) inboxAjaxParam('level', 'aZ09'));
		if ($result < 0) {
			inboxAjaxError($langs->transnoentities($access->error), 422);
		}
		// Who manages the mailboxes and gives himself an access (directly or by a group): the owner of the mailbox and
		// the administrators of Dolibarr are told (audit INBX-026: managing never gives the reading silently)
		InboxAccess::clearCache();
		$after = $access->getLevel($user, (int) $account->id);
		if ($result > 0 && InboxAccess::rank($after) > InboxAccess::rank($before)) {
			dol_include_once('/inbox/class/inboxnotification.class.php');
			$notify = new InboxNotification($db);
			$told = array();
			if ($account->type === InboxAccount::TYPE_PERSONAL && (int) $account->fk_user_owner > 0) {
				$told[] = (int) $account->fk_user_owner;
			}
			$admins = $db->getRows("SELECT rowid FROM ".$db->prefix()."user WHERE admin = 1 AND statut = 1 AND entity IN (0, ".((int) $conf->entity).") LIMIT 50");
			foreach (is_array($admins) ? $admins : array() as $a) {
				$told[] = (int) $a->rowid;
			}
			foreach (array_unique($told) as $uid) {
				if ($uid !== (int) $user->id) {
					$notify->add($uid, 'access_self', array('account' => (int) $account->id, 'actor' => (int) $user->id, 'url' => '#/settings/accounts/'.((int) $account->id).'/access',
						'payload' => array('subject' => (string) ($account->label ?: $account->email), 'level' => $after)));
				}
			}
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
		$where = " WHERE statut = 1 AND (fk_soc IS NULL OR fk_soc = 0) AND entity IN (0, ".((int) $conf->entity).")";
		if ($q !== '') {
			// Full name as shown in the list (« John BOTELLA »), in both orders, or a part of it
			$where .= " AND (firstname LIKE '%".$like."%' OR lastname LIKE '%".$like."%' OR login LIKE '%".$like."%' OR email LIKE '%".$like."%'";
			$where .= " OR CONCAT(firstname, ' ', lastname) LIKE '%".$like."%' OR CONCAT(lastname, ' ', firstname) LIKE '%".$like."%')";
		}
		$users = (array) $db->getRows("SELECT rowid, firstname, lastname, login, photo, entity, email FROM ".$db->prefix()."user".$where." ORDER BY lastname, firstname LIMIT 30");
		// How many more than shown: the list says to type more instead of seeming complete
		$total = $db->getRow("SELECT COUNT(*) as nb FROM ".$db->prefix()."user".$where);
		$moreUsers = max(0, (is_object($total) ? (int) $total->nb : 0) - count($users));
		foreach ($users as $row) {
			$name = trim($row->firstname.' '.$row->lastname);
			// Login and photo: two people of the same name told apart, the right one recognized
			// Found by the address only: the address is shown, the search never answers on something hidden
			$byName = $q === '' || stripos($name.' '.$row->lastname.' '.$row->firstname.' '.$row->login, $q) !== false;
			$out[] = array('kind' => 'user', 'id' => (int) $row->rowid, 'name' => $name !== '' ? $name : (string) $row->login, 'login' => (string) $row->login,
				'email' => !$byName && stripos((string) $row->email, $q) !== false ? (string) $row->email : '',
				'photo' => InboxAccess::userPhotoUrl((int) $row->rowid, (string) $row->photo, (int) $row->entity));
		}
		// Groups: their colour and how many active internal users they hold (a group gives the access to all of them)
		$sql = "SELECT g.rowid, g.nom, g.color, (SELECT COUNT(*) FROM ".$db->prefix()."usergroup_user as gu INNER JOIN ".$db->prefix()."user as u ON u.rowid = gu.fk_user"
			." AND u.statut = 1 AND (u.fk_soc IS NULL OR u.fk_soc = 0) WHERE gu.fk_usergroup = g.rowid) as members";
		$where = " WHERE g.entity IN (0, ".((int) $conf->entity).")".($q !== '' ? " AND g.nom LIKE '%".$like."%'" : "");
		$groups = (array) $db->getRows($sql." FROM ".$db->prefix()."usergroup as g".$where." ORDER BY g.nom LIMIT 20");
		$total = $db->getRow("SELECT COUNT(*) as nb FROM ".$db->prefix()."usergroup as g".$where);
		$moreGroups = max(0, (is_object($total) ? (int) $total->nb : 0) - count($groups));
		foreach ($groups as $row) {
			$out[] = array('kind' => 'group', 'id' => (int) $row->rowid, 'name' => (string) $row->nom, 'color' => (string) $row->color, 'members' => (int) $row->members);
		}
		inboxAjaxReply(array('results' => $out, 'more_users' => $moreUsers, 'more_groups' => $moreGroups));
		// no break

	default:
		inboxAjaxError('Unknown action', 400);
}
