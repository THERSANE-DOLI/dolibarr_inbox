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
 * \file    inbox/ajax/rules.php
 * \ingroup inbox
 * \brief   Rules and out of office of a mailbox (lot W8 1.23.0): managers of the mailbox only, except « away_off »
 */

require __DIR__.'/inbox_ajax.inc.php';
dol_include_once('/inbox/class/inboxrules.class.php');

$action = (string) inboxAjaxParam('action', 'aZ09');
inboxAjaxStart(!in_array($action, array('get', 'script'), true));
$rules = new InboxRules($db);

/**
 * Error said to the user
 *
 * @param InboxRules $rules Rules
 * @return never
 */
function inboxRulesFail(InboxRules $rules)
{
	global $langs;

	$code = (string) $rules->errorCode;
	$http = array('not_found' => 404, 'no_manage' => 403, 'edited' => 409, 'invalid' => 422, 'no_previous' => 409, 'server_refused' => 422);
	$keys = array('not_found' => 'InboxErrorAccountNotFound', 'no_manage' => 'InboxRulesNoManage', 'edited' => 'InboxRulesEdited', 'invalid' => 'InboxRulesInvalid',
		'no_previous' => 'InboxRulesNoPrevious', 'server_refused' => 'InboxRulesRefused', 'sieve_disabled' => 'InboxTestSieveDisabled', 'sieve_auth' => 'InboxRulesAuth',
		'sieve_tls' => 'InboxTestSieveTls', 'sieve_no_tls' => 'InboxTestSieveNoTls');
	$detail = in_array($code, array('server_refused', 'sieve_connect', 'invalid'), true) ? (string) $rules->error : '';
	dol_syslog('inbox rules '.$code.' '.$rules->error, LOG_WARNING);
	inboxAjaxReply(array('error' => $langs->transnoentities($keys[$code] ?? 'InboxRulesUnavailable'), 'code' => $code, 'detail' => dol_trunc($detail, 500)), $http[$code] ?? 502);
}

/**
 * @param string $key Parameter holding JSON
 * @return mixed
 */
function inboxRulesJson($key)
{
	$v = inboxAjaxParam($key, 'none');

	return is_array($v) ? $v : json_decode((string) $v, true);
}

$accountId = (int) inboxAjaxParam('id', 'int');
// « away_off » (banner of the application): the managers; the others only read the banner
$account = $rules->account($user, $accountId, true);
if (!$account) {
	inboxRulesFail($rules);
}

switch ($action) {
	case 'get':
		$state = $rules->load($account);
		if ($state === null) {
			inboxRulesFail($rules);
		}
		$state['folders'] = $rules->foldersOf($account);
		$state['tags'] = $rules->tagsOf($account);
		$state['addresses'] = $rules->addressesOf($account);
		inboxAjaxReply($state);
		// no break

	case 'script':
		// A script of the server, shown read only (changed outside inbox, or kept from before)
		$text = $rules->scriptText($account, (string) inboxAjaxParam('name', 'alphanohtml'));
		if ($text === null) {
			inboxRulesFail($rules);
		}
		inboxAjaxReply(array('text' => $text));
		// no break

	case 'save':
	case 'away':
	case 'away_off':
		// The script holds both: what the screen does not send is taken from the server
		$state = $rules->load($account);
		if ($state === null) {
			inboxRulesFail($rules);
		}
		if ($action === 'save') {
			$list = $rules->cleanRules($account, inboxRulesJson('rules'));
			$away = $state['away'];
		} else {
			$list = $state['rules'];
			$away = $action === 'away_off' ? array_merge($state['away'], array('on' => false)) : $rules->cleanAway($account, inboxRulesJson('away'));
		}
		if ($list === null || $away === null) {
			inboxRulesFail($rules);
		}
		$res = $rules->save($user, $account, $list, $away, (int) inboxAjaxParam('take_over', 'int') === 1, $action === 'save' ? 'rules' : 'away');
		if ($res === null) {
			inboxRulesFail($rules);
		}
		inboxAjaxReply($res);
		// no break

	case 'undo':
		$res = $rules->undo($user, $account);
		if ($res === null) {
			inboxRulesFail($rules);
		}
		inboxAjaxReply($res);
		// no break

	case 'preview':
	case 'apply':
		// The mails of the inbox of the last 30 days a rule concerns; apply: the rule run once on them
		$one = $rules->cleanRules($account, array(inboxRulesJson('rule')));
		if ($one === null) {
			inboxRulesFail($rules);
		}
		if ($action === 'preview') {
			$res = $rules->preview($account, $one[0]);
			unset($res['ids']);
			inboxAjaxReply($res);
		}
		$res = $rules->apply($user, $account, $one[0]);
		if ($res === null) {
			inboxRulesFail($rules);
		}
		inboxAjaxReply($res);
		// no break

	default:
		inboxAjaxError('Unknown action', 400);
}
