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
 * \file    inbox/ajax/fulltext.php
 * \ingroup inbox
 * \brief   Settings > General > Search (lot W7 1.20.0): Meilisearch, by the admin of the mailboxes only. The keys
 *          are never sent back to the browser.
 */

require __DIR__.'/inbox_ajax.inc.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
dol_include_once('/inbox/class/inboxfulltext.class.php');

$action = (string) inboxAjaxParam('action', 'aZ09');
inboxAjaxStart(in_array($action, array('test', 'enable', 'disable', 'account_set'), true));
inboxAjaxNeedAdmin();

/**
 * Master key: the one typed, else the one kept, but only for the address it was kept with (audit INBX-003: the key
 * opens every index, it never goes to another address)
 *
 * @param string $url Address of the call
 * @return string
 */
function inboxFulltextMasterKey($url)
{
	global $langs;

	$typed = (string) inboxAjaxParam('key', 'none');
	if ($typed !== '') {
		return $typed;
	}
	$kept = getDolGlobalString('INBOX_MEILI_KEY');
	if ($kept !== '' && rtrim(strtolower(trim((string) $url)), '/') === rtrim(strtolower(trim(getDolGlobalString('INBOX_MEILI_URL'))), '/')) {
		return (string) dolDecrypt($kept);
	}
	inboxAjaxError($langs->transnoentities('InboxMeiliKeyAgain'), 422, array('key' => 1));
}

switch ($action) {
	case 'get':
		$url = getDolGlobalString('INBOX_MEILI_URL');
		inboxAjaxReply(array(
			'enabled' => InboxFulltext::enabled(),
			'url' => $url,
			'prefix' => InboxFulltext::prefix(),
			'has_key' => getDolGlobalString('INBOX_MEILI_KEY') !== '',
			'local' => $url === '' || InboxMeili::isLocal($url),
		));
		// no break

	case 'test':
		$url = trim((string) inboxAjaxParam('url', 'alphanohtml'));
		if (!InboxMeili::validUrl($url)) {
			inboxAjaxError($langs->transnoentities('InboxMeiliBadUrl'), 422, array('url' => 1));
		}
		dol_include_once('/inbox/class/inboxservertester.class.php');
		if (!InboxServerTester::hostAllowed((string) parse_url($url, PHP_URL_HOST))) {
			inboxAjaxError($langs->transnoentities('InboxErrorHostNotAllowed', (string) parse_url($url, PHP_URL_HOST)), 422, array('url' => 1));
		}
		$client = new InboxMeili($url, inboxFulltextMasterKey($url));
		$client->timeout = 5;
		$version = $client->version();
		if ($version === '') {
			// No answer of the private network said to who is not administrator of Dolibarr (audit INBX-010)
			$said = !empty($user->admin) || !InboxMeili::isLocal($url) ? $client->error : '';
			inboxAjaxReply(array('ok' => false, 'error' => $langs->transnoentities('InboxMeiliNoAnswer', $said), 'local' => InboxMeili::isLocal($url)));
		}
		// Only the master key may create the key of inbox
		$master = $client->call('GET', '/keys?limit=1') !== null;
		inboxAjaxReply(array('ok' => $master, 'version' => $version, 'master' => $master, 'local' => InboxMeili::isLocal($url),
			'error' => $master ? '' : $langs->transnoentities('InboxMeiliNotMaster')));
		// no break

	case 'enable':
		$url = trim((string) inboxAjaxParam('url', 'alphanohtml'));
		$prefix = trim((string) inboxAjaxParam('prefix', 'aZ09'));
		$typed = (string) inboxAjaxParam('key', 'none');
		if (!InboxMeili::validUrl($url)) {
			inboxAjaxError($langs->transnoentities('InboxMeiliBadUrl'), 422, array('url' => 1));
		}
		if (!InboxFulltext::validPrefix($prefix)) {
			inboxAjaxError($langs->transnoentities('InboxMeiliBadPrefix'), 422, array('prefix' => 1));
		}
		if (InboxFulltext::enabled()) {
			inboxAjaxError($langs->transnoentities('InboxMeiliAlreadyOn'), 409);
		}
		$master = new InboxMeili($url, inboxFulltextMasterKey($url));
		$key = $master->createKey($prefix, 'inbox '.$prefix);
		if (!$key) {
			inboxAjaxError($langs->transnoentities('InboxMeiliNoKey', $master->error), 422, array('key' => 1));
		}
		$entity = (int) $conf->entity;
		dolibarr_set_const($db, 'INBOX_MEILI_URL', $url, 'chaine', 0, '', $entity);
		dolibarr_set_const($db, 'INBOX_MEILI_PREFIX', $prefix, 'chaine', 0, '', $entity);
		if ($typed !== '') {
			dolibarr_set_const($db, 'INBOX_MEILI_KEY', dolEncrypt($typed), 'chaine', 0, '', $entity);
		}
		dolibarr_set_const($db, 'INBOX_MEILI_WORKKEY', dolEncrypt($key['key']), 'chaine', 0, '', $entity);
		dolibarr_set_const($db, 'INBOX_MEILI_WORKKEY_UID', $key['uid'], 'chaine', 0, '', $entity);
		dolibarr_set_const($db, 'INBOX_MEILI_ENABLED', '1', 'chaine', 0, '', $entity);
		inboxAjaxReply(array('enabled' => true));
		// no break

	case 'disable':
		// Every index of the prefix is removed: asked twice by the application, the second time with confirm=1
		if ((int) inboxAjaxParam('confirm', 'int') !== 1) {
			inboxAjaxError($langs->transnoentities('InboxMeiliDisableConfirm'), 409);
		}
		$ft = new InboxFulltext($db);
		if (!$ft->disableAll()) {
			inboxAjaxError($langs->transnoentities('InboxMeiliDisableError', $ft->error), 502);
		}
		inboxAjaxReply(array('enabled' => false));
		// no break

	case 'account':
		$account = inboxAjaxManagedAccount((int) inboxAjaxParam('id', 'int'), true);
		$ft = new InboxFulltext($db);
		inboxAjaxReply(array('enabled' => InboxFulltext::enabled()) + $ft->accountStatus((int) $account->id));
		// no break

	case 'account_set':
		$account = inboxAjaxManagedAccount((int) inboxAjaxParam('id', 'int'), true);
		$ft = new InboxFulltext($db);
		if (!$ft->setAccount((int) $account->id, (int) inboxAjaxParam('on', 'int') === 1)) {
			inboxAjaxError($langs->transnoentities('InboxMeiliAccountError', $ft->error), 502);
		}
		inboxAjaxReply(array('enabled' => InboxFulltext::enabled()) + $ft->accountStatus((int) $account->id));
		// no break

	default:
		inboxAjaxError('Unknown action', 400);
}
