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
 * \file    inbox/ajax/account.php
 * \ingroup inbox
 * \brief   Mailboxes: list, card, save, connection test, status, deletion, journal, folders
 */

require __DIR__.'/inbox_ajax.inc.php';
dol_include_once('/inbox/class/inboxservertester.class.php');
dol_include_once('/inbox/class/inboxidentity.class.php');
dol_include_once('/inbox/class/connector/inboximapconnector.class.php');

$action = (string) inboxAjaxParam('action', 'aZ09');
$write = in_array($action, array('save', 'test', 'status', 'delete', 'roles'), true);
inboxAjaxStart($write);
$isAdmin = $user->hasRight('inbox', 'account', 'admin');

/**
 * Fill a mailbox from the posted values (only the editable fields; passwords only when typed)
 *
 * @param InboxAccount $account Mailbox
 * @return void
 */
function inboxAccountFromInput(InboxAccount $account)
{
	$typed = array('imap' => false, 'smtp' => false);
	// Only the fields sent are changed: a field not sent keeps its value (or the default of a new mailbox)
	foreach (array('email', 'label', 'type', 'imap_host', 'imap_encryption', 'imap_auth_mode', 'oauth_service', 'imap_login', 'smtp_host', 'smtp_encryption', 'smtp_login') as $field) {
		$value = inboxAjaxHas($field) ? inboxAjaxParam($field) : null;
		if ($value !== null && !is_array($value)) {
			$account->$field = (string) $value;
		}
	}
	foreach (array('imap_port', 'smtp_port', 'sieve_port', 'sync_max_days', 'sync_max_mails', 'fk_user_owner') as $field) {
		if (inboxAjaxHas($field)) {
			$account->$field = (int) inboxAjaxParam($field, 'int');
		}
	}
	foreach (array('imap_validate_cert', 'smtp_same_credentials') as $field) {
		if (inboxAjaxHas($field)) {
			$account->$field = inboxAjaxParam($field, 'int') ? 1 : 0;
		}
	}
	if ((int) $account->fk_user_owner <= 0) {
		$account->fk_user_owner = null;
	}
	// Never the AI (1.27.0), never the voice typing (1.28.0) for this mailbox
	foreach (array('ai_off', 'dictation_off') as $flag) {
		if (inboxAjaxHas($flag)) {
			$config = $account->getConnectorConfig();
			$config[$flag] = inboxAjaxParam($flag, 'int') ? 1 : 0;
			$account->setConnectorConfig($config);
		}
	}
	// Copy of the mails sent (1.26.0): kept in the settings of the connector
	if (inboxAjaxHas('sent_copy')) {
		$mode = (string) inboxAjaxParam('sent_copy', 'aZ09');
		$config = $account->getConnectorConfig();
		$config['sent_copy'] = in_array($mode, array('auto', 'inbox', 'server'), true) ? $mode : 'auto';
		$account->setConnectorConfig($config);
	}
	// A password field left empty keeps the stored password; it is never sent back to the browser
	$imapPassword = (string) inboxAjaxParam('imap_password', 'none');
	if ($imapPassword !== '') {
		$account->setImapPassword($imapPassword);
		$typed['imap'] = true;
	}
	$smtpPassword = (string) inboxAjaxParam('smtp_password', 'none');
	if ($smtpPassword !== '') {
		$account->setSmtpPassword($smtpPassword);
		$typed['smtp'] = true;
	}

	return $typed;
}

/**
 * The form put on the mailbox, then the guard of the secrets (audit INBX-002): a stored password never goes to
 * another server than the one it was typed for
 *
 * @param InboxAccount $account Mailbox (stored, or new)
 * @return void
 */
function inboxAccountApplyForm(InboxAccount $account)
{
	global $langs, $user;

	$old = $account->id > 0 ? clone $account : null;
	$typed = inboxAccountFromInput($account);
	// Servers allowed by the setup, if any (audit INBX-010)
	dol_include_once('/inbox/class/inboxservertester.class.php');
	foreach (array($account->imap_host, $account->smtp_host) as $host) {
		if (!InboxServerTester::hostAllowed((string) $host)) {
			inboxAjaxError($langs->transnoentities('InboxErrorHostNotAllowed', (string) $host), 422, array('imap_host' => 1));
		}
	}
	$error = $account->guardSecrets($old, $typed['imap'], $typed['smtp'], !empty($user->admin));
	if ($error !== '') {
		$field = $error === 'InboxErrorSmtpPasswordAgain' ? 'smtp_password' : ($error === 'InboxErrorPasswordAgain' ? 'imap_password' : 'imap_auth_mode');
		inboxAjaxError($langs->transnoentities($error), 422, array($field => $langs->transnoentities($error)));
	}
}

/**
 * Summary of a mailbox for the list
 *
 * @param InboxAccount $account Mailbox
 * @param string       $level   Level of the user (admin: manage)
 * @return array<string,mixed>
 */
function inboxAccountSummary(InboxAccount $account, $level)
{
	global $db;

	$row = $db->getRow("SELECT COUNT(rowid) AS n FROM ".$db->prefix()."inbox_account_access WHERE fk_account = ".((int) $account->id));
	$owner = '';
	if ($account->type === InboxAccount::TYPE_PERSONAL && $account->fk_user_owner > 0) {
		$u = new User($db);
		if ($u->fetch((int) $account->fk_user_owner) > 0) {
			$owner = $u->getFullName($GLOBALS['langs']);
		}
	}

	return array(
		'id' => (int) $account->id,
		'email' => $account->email,
		'label' => $account->label,
		'type' => $account->type,
		'owner' => $owner,
		'connector' => $account->connector,
		'status' => (int) $account->status,
		'last_test_result' => (string) $account->last_test_result,
		'date_last_test' => $account->date_last_test ? (int) $account->date_last_test : null,
		'sync_state' => (string) $account->sync_state,
		'sync_error' => (string) $account->sync_error,
		'date_sync' => $account->date_sync ? (int) $account->date_sync : null,
		'accesses' => is_object($row) ? (int) $row->n : 0,
		'level' => $level,
	);
}

switch ($action) {
	case 'list':
		$out = array();
		if ($isAdmin) {
			$rows = $db->getRows("SELECT rowid FROM ".$db->prefix()."inbox_account WHERE entity = ".((int) $conf->entity)." ORDER BY label, email LIMIT 1000");
			foreach (is_array($rows) ? $rows : array() as $row) {
				$account = new InboxAccount($db);
				if ($account->fetch((int) $row->rowid) > 0) {
					$out[] = inboxAccountSummary($account, InboxAccess::LEVEL_MANAGE);
				}
			}
		} else {
			$access = new InboxAccess($db);
			foreach ($access->getAccounts($user, InboxAccess::LEVEL_MANAGE) as $account) {
				$out[] = inboxAccountSummary($account, InboxAccess::LEVEL_MANAGE);
			}
		}
		inboxAjaxReply(array('accounts' => $out, 'admin' => $isAdmin));
		// no break (reply exits)

	case 'get':
		$account = inboxAjaxManagedAccount((int) inboxAjaxParam('id', 'int'));
		$data = $account->toArrayForClient();
		$data['summary'] = inboxAccountSummary($account, InboxAccess::LEVEL_MANAGE);
		$data['admin'] = $isAdmin;
		inboxAjaxReply(array('account' => $data));
		// no break

	case 'save':
		inboxAjaxNeedAdmin();
		$id = (int) inboxAjaxParam('id', 'int');
		$account = new InboxAccount($db);
		if ($id > 0) {
			$account = inboxAjaxManagedAccount($id, true);
		}
		inboxAccountApplyForm($account);
		$result = $id > 0 ? $account->update($user) : $account->create($user);
		if ($result <= 0) {
			inboxAjaxError(inboxAjaxTransErrors($account->errors ? $account->errors : array($account->error)), 422);
		}
		$account->fetch((int) $account->id);
		inboxAjaxReply(array('account' => $account->toArrayForClient()));
		// no break

	case 'test':
		inboxAjaxNeedAdmin();
		$id = (int) inboxAjaxParam('id', 'int');
		$account = new InboxAccount($db);
		if ($id > 0) {
			$account = inboxAjaxManagedAccount($id, true);
		}
		// Values of the form, not saved; an empty password field keeps the stored password of the mailbox, as long as
		// the server is the same one (INBX-002)
		inboxAccountApplyForm($account);
		$tester = new InboxServerTester($db);
		// A host of the private network: its answers are not shown to who is not administrator of Dolibarr (INBX-010)
		dol_include_once('/inbox/class/inboxmeili.class.php');
		$tester->withDetail = !empty($user->admin) || !InboxMeili::isLocal('imap://'.trim((string) $account->imap_host, '[]'));
		$saved = $id > 0 && inboxAjaxParam('save', 'int');
		$result = $saved ? $tester->runAndSave($account, $user) : $tester->run($account);
		inboxAjaxReply(array('test' => $result));
		// no break

	case 'status':
		inboxAjaxNeedAdmin();
		$account = inboxAjaxManagedAccount((int) inboxAjaxParam('id', 'int'), true);
		$status = (int) inboxAjaxParam('status', 'int');
		if (!in_array($status, array(InboxAccount::STATUS_ACTIVE, InboxAccount::STATUS_DISABLED), true)) {
			inboxAjaxError($langs->transnoentities('InboxErrorAccountStatus'), 422);
		}
		if ($status === InboxAccount::STATUS_ACTIVE && !in_array((string) $account->last_test_result, array('ok', 'warning'), true)) {
			inboxAjaxError($langs->transnoentities('InboxErrorEnableNeedsTest'), 422);
		}
		$account->status = $status;
		if ($account->update($user) <= 0) {
			inboxAjaxError(inboxAjaxTransErrors($account->errors), 422);
		}
		InboxAccess::clearCache();
		inboxAjaxReply(array('account' => $account->toArrayForClient()));
		// no break

	case 'delete':
		inboxAjaxNeedAdmin();
		$account = inboxAjaxManagedAccount((int) inboxAjaxParam('id', 'int'), true);
		// Definitive: the address must be typed (CA-18); nothing is removed on the mail server
		if (strtolower(trim((string) inboxAjaxParam('confirm_email'))) !== $account->email) {
			inboxAjaxError($langs->transnoentities('InboxErrorDeleteConfirm'), 422);
		}
		if ($account->delete($user) <= 0) {
			inboxAjaxError(inboxAjaxTransErrors($account->errors ? $account->errors : array($account->error)), 422);
		}
		InboxAccess::clearCache();
		inboxAjaxReply(array('deleted' => true));
		// no break

	case 'log':
		$account = inboxAjaxManagedAccount((int) inboxAjaxParam('id', 'int'));
		$log = new InboxAccountLog($db);
		inboxAjaxReply(array('log' => $log->listForAccount((int) $account->id, 100, max(0, (int) inboxAjaxParam('offset', 'int')))));
		// no break

	case 'folders':
		inboxAjaxNeedAdmin();
		$account = inboxAjaxManagedAccount((int) inboxAjaxParam('id', 'int'), true);
		$error = '';
		$connector = InboxConnectorFactory::forAccount($db, $account, $error);
		$folders = $connector ? $connector->listFolders() : null;
		if ($folders === null) {
			inboxAjaxError($connector ? $connector->getError() : $error, 502);
		}
		inboxAjaxReply(array('folders' => $folders, 'roles' => $account->getFolderRoles()));
		// no break

	case 'roles':
		inboxAjaxNeedAdmin();
		$account = inboxAjaxManagedAccount((int) inboxAjaxParam('id', 'int'), true);
		$roles = array();
		foreach (array('sent', 'drafts', 'trash', 'junk', 'archive') as $role) {
			$path = (string) inboxAjaxParam('role_'.$role, 'none');
			if ($path !== '' && InboxImapConnector::isSafeFolderPath($path)) {
				$roles[$role] = $path;
			}
		}
		$account->setFolderRoles($roles);
		$account->update($user);
		inboxAjaxReply(array('roles' => $account->getFolderRoles()));
		// no break

	default:
		inboxAjaxError('Unknown action', 400);
}
