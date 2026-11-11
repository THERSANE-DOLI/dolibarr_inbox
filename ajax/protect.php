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
 * \file    inbox/ajax/protect.php
 * \ingroup inbox
 * \brief   Protected mailboxes (1.89.0): devices, unlocking, protection, encrypted accesses (analyse-boites-protegees §5)
 */

require __DIR__.'/inbox_ajax.inc.php';
dol_include_once('/inbox/class/inboxprotect.class.php');

$action = (string) inboxAjaxParam('action', 'aZ09');
// The notices told once are forgotten by take=1: a write, never by a GET (audit INBX-065)
$write = !in_array($action, array('devices', 'state', 'accesses', 'pending'), true) || ($action === 'pending' && (int) inboxAjaxParam('take', 'int') === 1);
inboxAjaxStart($write);

$protect = new InboxProtect($db);
$access = new InboxAccess($db);

/**
 * Tries of a password refused by the mail server: 5 per user and per mailbox in 15 minutes, counted in the journal of
 * the mailbox (never in the session, a new session would start again): Dolibarr is not a way to guess the password,
 * nor a reason for the mail server to ban its address (audit INBX-064)
 *
 * @param InboxAccount $account Mailbox
 * @return void Answers 429 above
 */
function inboxProtectThrottle(InboxAccount $account)
{
	global $db, $langs, $user;

	$row = $db->getRow("SELECT COUNT(rowid) as n FROM ".$db->prefix()."inbox_account_log WHERE fk_account = ".((int) $account->id)." AND fk_user = ".((int) $user->id)
		." AND action = '".$db->escape(InboxProtect::LOG_UNLOCK_FAILED)."' AND datec > '".$db->idate(dol_now() - 900)."'");
	if (is_object($row) && (int) $row->n >= 5) {
		inboxAjaxReply(array('error' => $langs->transnoentities('InboxProtectErrorTooManyTries'), 'code' => 'throttled'), 429);
	}
}

/**
 * A mailbox the user can read (else 404), protected or not
 *
 * @param int    $id    Mailbox
 * @param string $level Level needed
 * @return InboxAccount
 */
function inboxProtectAccount($id, $level = InboxAccess::LEVEL_READ)
{
	global $db, $user;

	$account = new InboxAccount($db);
	if ((int) $id <= 0 || $account->fetch((int) $id) <= 0) {
		inboxAjaxNotFound();
	}
	$access = new InboxAccess($db);
	// 'decide': who decides the mode (admin, owner), or who reads the mailbox (the screen says what he may do)
	$allowed = $level === 'decide' ? (InboxProtect::canDecide($user, $account) || $access->check($user, (int) $account->id, InboxAccess::LEVEL_READ)) : $access->check($user, (int) $account->id, $level);
	if (!$allowed) {
		inboxAjaxNotFound();
	}

	return $account;
}

/**
 * Passwords posted (never cleaned: a password may hold any character)
 *
 * @return array{imap:string,smtp:string}
 */
function inboxProtectSecrets()
{
	return array('imap' => (string) inboxAjaxParam('imap_password', 'none'), 'smtp' => (string) inboxAjaxParam('smtp_password', 'none'));
}

/**
 * Error of InboxProtect, translated
 *
 * @param InboxProtect $protect Object
 * @param int          $code    HTTP status
 * @return never
 */
function inboxProtectFail(InboxProtect $protect, $code = 422)
{
	global $langs;

	inboxAjaxError(strpos($protect->error, 'Inbox') === 0 ? $langs->transnoentities($protect->error) : $protect->error, $code);
}

/**
 * One of the active devices of the user, else 404
 *
 * @param InboxProtect $protect Object
 * @param int          $id      Device
 * @return array<string,mixed>
 */
function inboxProtectOwnDevice(InboxProtect $protect, $id)
{
	global $user, $langs;

	$device = $protect->device((int) $id);
	if (!$device || $device['fk_user'] !== (int) $user->id || $device['status'] !== 1) {
		inboxAjaxReply(array('error' => $langs->transnoentities('InboxProtectErrorDevice'), 'code' => 'device_revoked'), 409);
	}

	return $device;
}

switch ($action) {
	case 'register':
		// A browser of the user gives its public key (first opening of inbox, or a key made again)
		$device = $protect->registerDevice($user, inboxAjaxParam('public_key', 'none'), (string) inboxAjaxParam('label'));
		if (!$device) {
			inboxProtectFail($protect, $protect->error === 'InboxProtectErrorRevoked' ? 409 : 422);
		}
		unset($device['public_key']);
		inboxAjaxReply(array('device' => $device));
		// no break

	case 'devices':
		// The devices of the user, those waiting for his approval (§5.3) with what they lack
		$mine = $protect->devicesOf(array((int) $user->id))[(int) $user->id] ?? array();
		$current = (int) inboxAjaxParam('device', 'int');
		$protectedOfMine = array();
		foreach ($access->getAccounts($user) as $a) {
			if ($a->isProtected()) {
				$protectedOfMine[(int) $a->id] = $a;
			}
		}
		$out = array();
		foreach ($mine as $d) {
			$missing = array();
			if ($d['approve_asked'] && $d['id'] !== $current) {
				foreach ($protectedOfMine as $a) {
					if ($protect->blobFor($user, $a, $d['id']) === null) {
						$missing[] = array('id' => (int) $a->id, 'label' => (string) $a->label, 'aad' => InboxProtect::aad((int) $a->id, (int) $user->id, $d['id']));
					}
				}
			}
			$d['missing'] = $missing;
			$out[] = $d;
		}
		inboxAjaxReply(array('devices' => $out, 'max' => InboxProtect::MAX_DEVICES));
		// no break

	case 'state':
		// Protected mailboxes of the user: unlocked in this session, or the copy of this device to open
		$device = (int) inboxAjaxParam('device', 'int');
		$out = array();
		foreach ($access->getAccounts($user) as $a) {
			if (!$a->isProtected()) {
				continue;
			}
			$unlocked = InboxProtect::isUnlocked((int) $a->id);
			$out[] = array(
				'id' => (int) $a->id,
				'label' => (string) $a->label,
				'email' => (string) $a->email,
				'unlocked' => $unlocked,
				// Its own copy, also when unlocked: an approval of another of his devices opens it (§5.3)
				'blob' => $device > 0 ? $protect->blobFor($user, $a, $device) : null,
				'aad' => $device > 0 ? InboxProtect::aad((int) $a->id, (int) $user->id, $device) : '',
				'smtp_own' => empty($a->smtp_same_credentials) ? 1 : 0,
				'failed' => !empty($_SESSION['inbox_unlock_failed'][(int) $a->id]),
			);
		}
		inboxAjaxReply(array('accounts' => $out));
		// no break

	case 'unlock':
		// The browser opened its copy (or the user typed the password): tried on the mail server, then kept for the
		// session. With a copy refused by the server (password changed at the host), the user is asked to type it.
		$account = inboxProtectAccount((int) inboxAjaxParam('id', 'int'));
		$device = inboxProtectOwnDevice($protect, (int) inboxAjaxParam('device', 'int'));
		if (!$account->isProtected()) {
			inboxAjaxNotFound();
		}
		inboxProtectThrottle($account);
		$secrets = inboxProtectSecrets();
		$code = '';
		$message = $protect->tryPasswords($account, $secrets, $code);
		$log = new InboxAccountLog($db);
		if ($message !== '') {
			if ($code === 'auth') {
				$_SESSION['inbox_unlock_failed'][(int) $account->id] = 1;
				$log->add((int) $account->id, $user, InboxProtect::LOG_UNLOCK_FAILED, null, array('device' => $device['fingerprint']));
			}
			// Never 401 (the page would take it for an expired session): 422 with the code
			inboxAjaxReply(array('error' => $message, 'code' => $code === 'auth' ? 'auth' : 'connect'), $code === 'auth' ? 422 : 502);
		}
		unset($_SESSION['inbox_unlock_failed'][(int) $account->id]);
		$first = !InboxProtect::isUnlocked((int) $account->id);
		InboxProtect::unlock((int) $account->id, $secrets, $device['id']);
		// The password is there again: a wait after failures (synchronised while it was missing) starts again from zero
		$db->query("UPDATE ".$db->prefix()."inbox_account SET sync_retry_at = NULL, sync_fail_count = 0 WHERE rowid = ".((int) $account->id)." AND protect_level = 'password' AND sync_retry_at IS NOT NULL");
		$protect->touch($account, (int) $user->id, $device['id']);
		if ($first) {
			$log->add((int) $account->id, $user, InboxProtect::LOG_UNLOCK, null, array('device' => $device['fingerprint']));
		}
		InboxAccess::clearCache();
		inboxAjaxReply(array('unlocked' => true));
		// no break

	case 'enrol':
		// Password typed on a device without copy (§5.2): tried, then the copy made by this browser is kept. After a
		// copy refused by the server in this session, the older copies of every user become obsolete.
		$account = inboxProtectAccount((int) inboxAjaxParam('id', 'int'));
		$device = inboxProtectOwnDevice($protect, (int) inboxAjaxParam('device', 'int'));
		if (!$account->isProtected()) {
			inboxAjaxNotFound();
		}
		inboxProtectThrottle($account);
		$secrets = inboxProtectSecrets();
		$code = '';
		$message = $protect->tryPasswords($account, $secrets, $code);
		if ($message !== '') {
			if ($code === 'auth') {
				(new InboxAccountLog($db))->add((int) $account->id, $user, InboxProtect::LOG_UNLOCK_FAILED, null, array('device' => $device['fingerprint']));
			}
			// Never 401 (the page would take it for an expired session): 422 with the code
			inboxAjaxReply(array('error' => $message, 'code' => $code === 'auth' ? 'auth' : 'connect'), $code === 'auth' ? 422 : 502);
		}
		$db->begin();
		// The older copies made obsolete by who decides or manages the mailbox only: a reader would cut the copies of
		// every colleague with a wrong try then the right password (audit INBX-064)
		$renew = !empty($_SESSION['inbox_unlock_failed'][(int) $account->id])
			&& (InboxProtect::canDecide($user, $account) || $access->check($user, (int) $account->id, InboxAccess::LEVEL_MANAGE));
		if ($renew && !$protect->newGeneration($account)) {
			$db->rollback();
			inboxAjaxError($db->lasterror(), 500);
		}
		if (!$protect->storeBlob($user, $account, (int) $user->id, $device['id'], inboxAjaxParam('blob', 'none'), InboxProtect::ORIGIN_PASSWORD)) {
			$db->rollback();
			inboxProtectFail($protect);
		}
		$db->commit();
		unset($_SESSION['inbox_unlock_failed'][(int) $account->id]);
		InboxProtect::unlock((int) $account->id, $secrets, $device['id']);
		// The password is there again: a wait after failures (synchronised while it was missing) starts again from zero
		$db->query("UPDATE ".$db->prefix()."inbox_account SET sync_retry_at = NULL, sync_fail_count = 0 WHERE rowid = ".((int) $account->id)." AND protect_level = 'password' AND sync_retry_at IS NOT NULL");
		$protect->touch($account, (int) $user->id, $device['id']);
		inboxAjaxReply(array('unlocked' => true, 'gen' => (int) $account->protect_gen));
		// no break

	case 'lock':
		$account = inboxProtectAccount((int) inboxAjaxParam('id', 'int'));
		InboxProtect::lock((int) $account->id);
		inboxAjaxReply(array('locked' => true));
		// no break

	case 'protect':
		// Admin of the mailboxes, or owner of a personal mailbox (D4); the address typed again (irreversible for the
		// local data)
		$account = inboxProtectAccount((int) inboxAjaxParam('id', 'int'), 'decide');
		$device = inboxProtectOwnDevice($protect, (int) inboxAjaxParam('device', 'int'));
		if (strtolower(trim((string) inboxAjaxParam('confirm_email'))) !== $account->email) {
			inboxAjaxError($langs->transnoentities('InboxProtectErrorConfirm'), 422, array('confirm_email' => 1));
		}
		if (!$protect->protect($user, $account, inboxProtectSecrets(), $device['id'], inboxAjaxParam('blob', 'none'), (string) inboxAjaxParam('level', 'aZ09'))) {
			inboxProtectFail($protect);
		}
		InboxAccess::clearCache();
		inboxAjaxReply(array('account' => $account->toArrayForClient()));
		// no break

	case 'level':
		// Level of the protection (password / content), who decides the mode
		$account = inboxProtectAccount((int) inboxAjaxParam('id', 'int'), 'decide');
		if (!$protect->setLevel($user, $account, (string) inboxAjaxParam('level', 'aZ09'))) {
			inboxProtectFail($protect);
		}
		InboxAccess::clearCache();
		inboxAjaxReply(array('account' => $account->toArrayForClient()));
		// no break

	case 'unprotect':
		$account = inboxProtectAccount((int) inboxAjaxParam('id', 'int'), 'decide');
		if (!$protect->unprotect($user, $account)) {
			inboxProtectFail($protect);
		}
		InboxAccess::clearCache();
		inboxAjaxReply(array('account' => $account->toArrayForClient()));
		// no break

	case 'reset':
		$account = inboxProtectAccount((int) inboxAjaxParam('id', 'int'), 'decide');
		if (strtolower(trim((string) inboxAjaxParam('confirm_email'))) !== $account->email) {
			inboxAjaxError($langs->transnoentities('InboxProtectErrorConfirm'), 422, array('confirm_email' => 1));
		}
		if (!$protect->reset($user, $account)) {
			inboxProtectFail($protect);
		}
		inboxAjaxReply(array('account' => $account->toArrayForClient()));
		// no break

	case 'accesses':
		// Screen « Encrypted accesses » (§5.4): managers of the mailbox and who decides its mode (also without an access of
		// his own: told why he cannot protect it)
		$account = inboxProtectAccount((int) inboxAjaxParam('id', 'int'), 'decide');
		$manager = $access->check($user, (int) $account->id, InboxAccess::LEVEL_MANAGE) || InboxProtect::canDecide($user, $account);
		if (!$manager) {
			inboxAjaxNotFound();
		}
		$list = $account->isProtected() ? $protect->accessesOf($account) : array();
		foreach ($list as $k => $u) {
			foreach ($u['devices'] as $j => $d) {
				$list[$k]['devices'][$j]['aad'] = InboxProtect::aad((int) $account->id, (int) $u['id'], (int) $d['id']);
			}
		}
		inboxAjaxReply(array(
			'protected' => $account->isProtected(),
			'level' => $account->isProtected() ? (string) $account->protect_level : '',
			'unlocked' => InboxProtect::isUnlocked((int) $account->id),
			'can_decide' => InboxProtect::canDecide($user, $account),
			'refusal' => InboxProtect::refusal($account) !== '' ? $langs->transnoentities(InboxProtect::refusal($account)) : '',
			'own_access' => $access->check($user, (int) $account->id, InboxAccess::LEVEL_READ),
			'smtp_own' => empty($account->smtp_same_credentials) ? 1 : 0,
			'users' => $list,
		));
		// no break

	case 'grant':
		// A manager who has the mailbox unlocked encrypts its password for the device of a colleague, after he saw its
		// fingerprint (never automatic)
		$account = inboxProtectAccount((int) inboxAjaxParam('id', 'int'));
		if (!$protect->storeBlob($user, $account, (int) inboxAjaxParam('fk_user', 'int'), (int) inboxAjaxParam('device', 'int'), inboxAjaxParam('blob', 'none'), InboxProtect::ORIGIN_GRANT)) {
			inboxProtectFail($protect);
		}
		inboxAjaxReply(array('granted' => true));
		// no break

	case 'approve':
		// From a device already enrolled: the copies of every protected mailbox of the user for his new device (§5.3)
		$target = inboxProtectOwnDevice($protect, (int) inboxAjaxParam('target', 'int'));
		$copies = inboxAjaxParam('copies');
		$done = 0;
		$errors = array();
		foreach (is_array($copies) ? $copies : array() as $copy) {
			$account = new InboxAccount($db);
			if (!is_array($copy) || $account->fetch((int) ($copy['id'] ?? 0)) <= 0 || !$access->check($user, (int) $account->id)) {
				continue;
			}
			if ($protect->storeBlob($user, $account, (int) $user->id, $target['id'], $copy['blob'] ?? null, InboxProtect::ORIGIN_APPROVE)) {
				$done++;
			} else {
				$errors[] = (string) $account->label.' : '.$langs->transnoentities($protect->error);
			}
		}
		if ($done === 0 && $errors) {
			inboxAjaxError(implode(' ', $errors), 422);
		}
		$protect->askApproval($user, $target['id'], false);
		inboxAjaxReply(array('approved' => $done, 'errors' => $errors));
		// no break

	case 'ask':
		$device = inboxProtectOwnDevice($protect, (int) inboxAjaxParam('device', 'int'));
		if (!$protect->askApproval($user, $device['id'], (bool) inboxAjaxParam('ask', 'int'))) {
			inboxProtectFail($protect);
		}
		inboxAjaxReply(array('asked' => (bool) inboxAjaxParam('ask', 'int')));
		// no break

	case 'rename':
		if (!$protect->renameDevice($user, (int) inboxAjaxParam('device', 'int'), (string) inboxAjaxParam('label'))) {
			inboxProtectFail($protect);
		}
		inboxAjaxReply(array('renamed' => true));
		// no break

	case 'revoke':
		if (!$protect->revokeDevice($user, (int) inboxAjaxParam('device', 'int'))) {
			inboxProtectFail($protect);
		}
		inboxAjaxReply(array('revoked' => true));
		// no break

	case 'pending':
		// Lot S3 (§7.1 point 5 and 6, §7.2): the copies left in Drafts by a page gone during its delay, the sendings
		// scheduled, the failures told once (then forgotten)
		dol_include_once('/inbox/class/inboxlivesend.class.php');
		$liveSend = new InboxLiveSend($db);
		inboxAjaxReply(array('drafts' => $liveSend->waitingDrafts($user), 'notices' => $liveSend->notices($user, (int) inboxAjaxParam('take', 'int') === 1), 'now' => dol_now()));
		// no break

	case 'senddraft':
		// « Send now » of a copy found in Drafts, or a sending scheduled that is due (by any user who may send from the
		// mailbox: as a scheduled job would)
		dol_include_once('/inbox/class/inboxlivesend.class.php');
		$liveSend = new InboxLiveSend($db);
		$res = $liveSend->sendDraft($user, (int) inboxAjaxParam('id', 'int'));
		if ($res === null) {
			inboxAjaxReply(array('error' => $liveSend->error !== '' ? $liveSend->error : $langs->transnoentities('InboxProtectSendFailed'), 'code' => $liveSend->errorCode), 502);
		}
		inboxAjaxReply($res);
		// no break

	default:
		inboxAjaxError('Unknown action', 400);
}
