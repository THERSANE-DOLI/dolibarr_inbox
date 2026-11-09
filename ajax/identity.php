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
 * \file    inbox/ajax/identity.php
 * \ingroup inbox
 * \brief   Identities of a mailbox (admins of the mailboxes, or level manage on the mailbox)
 */

require __DIR__.'/inbox_ajax.inc.php';
dol_include_once('/inbox/class/inboxidentity.class.php');

$action = (string) inboxAjaxParam('action', 'aZ09');
inboxAjaxStart(in_array($action, array('save', 'delete'), true));
$account = inboxAjaxManagedAccount((int) inboxAjaxParam('id', 'int'));
$identities = new InboxIdentity($db);

/**
 * @param InboxIdentity $identities Helper
 * @param int           $accountId  Mailbox
 * @return array<int,array<string,mixed>>
 */
function inboxIdentityList(InboxIdentity $identities, $accountId)
{
	return array_map(function ($i) {
		return $i->toArray();
	}, $identities->listForAccount($accountId));
}

switch ($action) {
	case 'list':
		inboxAjaxReply(array('identities' => inboxIdentityList($identities, (int) $account->id)));
		// no break

	case 'save':
		$identity = new InboxIdentity($db);
		$identityId = (int) inboxAjaxParam('identity_id', 'int');
		if ($identityId > 0 && ($identity->fetch($identityId) <= 0 || $identity->fk_account !== (int) $account->id)) {
			inboxAjaxNotFound();
		}
		$identity->fk_account = (int) $account->id;
		$identity->email = (string) inboxAjaxParam('email');
		$identity->name = (string) inboxAjaxParam('name');
		$identity->reply_to = (string) inboxAjaxParam('reply_to');
		$identity->bcc = (string) inboxAjaxParam('bcc');
		$identity->signature = (string) inboxAjaxParam('signature', 'restricthtml');
		$identity->is_default = inboxAjaxParam('is_default', 'int') ? 1 : 0;
		$identity->status = array_key_exists('status', inboxAjaxInput()) ? (inboxAjaxParam('status', 'int') ? 1 : 0) : 1;
		$result = $identityId > 0 ? $identity->update($user) : $identity->create($user);
		if ($result <= 0) {
			inboxAjaxError(inboxAjaxTransErrors($identity->errors ? $identity->errors : array($identity->error)), 422);
		}
		inboxAjaxReply(array('identities' => inboxIdentityList($identities, (int) $account->id)));
		// no break

	case 'delete':
		$identity = new InboxIdentity($db);
		if ($identity->fetch((int) inboxAjaxParam('identity_id', 'int')) <= 0 || $identity->fk_account !== (int) $account->id) {
			inboxAjaxNotFound();
		}
		$identity->delete();
		inboxAjaxReply(array('identities' => inboxIdentityList($identities, (int) $account->id)));
		// no break

	case 'profiles':
		inboxAjaxReply(array('profiles' => $identities->listSenderProfiles($account->email)));
		// no break

	case 'copy':
		// Preview of the copy of a sender profile: nothing saved here
		$identity = new InboxIdentity($db);
		$identity->fk_account = (int) $account->id;
		if (!$identity->copyFromSenderProfile((int) inboxAjaxParam('profile_id', 'int'))) {
			inboxAjaxError($langs->transnoentities($identity->error), 404);
		}
		inboxAjaxReply(array('name' => $identity->name, 'signature' => $identity->signature, 'email' => $identity->email));
		// no break

	default:
		inboxAjaxError('Unknown action', 400);
}
