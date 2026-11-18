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
 * \file    inbox/ajax/domain.php
 * \ingroup inbox
 * \brief   Settings > Public and shared domains (lot A7, 1.76.0): list, add, change, disable, restore; right « Manage the mailboxes ».
 *          « request »: any user asks the managers from a mail (bell)
 */

require __DIR__.'/inbox_ajax.inc.php';
dol_include_once('/inbox/class/inboxdomains.class.php');
dol_include_once('/inbox/class/inboxnotification.class.php');

$action = (string) inboxAjaxParam('action', 'aZ09');
inboxAjaxStart(in_array($action, array('add', 'update', 'toggle', 'delete', 'restore', 'request', 'match'), true));
$domains = new InboxDomains($db);
if ($action !== 'request' && !$user->hasRight('inbox', 'account', 'admin')) {
	inboxAjaxNotFound();
}

/**
 * @param InboxDomains $domains Helper
 * @return never
 */
function inboxDomainsFail(InboxDomains $domains)
{
	global $langs;

	inboxAjaxError($langs->transnoentities($domains->error ?: 'InboxErrorUnknown'), 422);
}

$category = (string) inboxAjaxParam('category', 'aZ09');
switch ($action) {
	case 'list':
		$origin = (string) inboxAjaxParam('origin', 'aZ09');
		inboxAjaxReply($domains->page((string) inboxAjaxParam('search'), $category, $origin, max(0, (int) inboxAjaxParam('page', 'int'))));
		// no break

	case 'add':
		// One or several pasted (one per line, or separated by spaces, commas, semicolons)
		$list = preg_split('/[\s,;]+/', (string) inboxAjaxParam('domains'), -1, PREG_SPLIT_NO_EMPTY);
		$res = $domains->add($user, $list, $category);
		if ($res === null) {
			inboxDomainsFail($domains);
		}
		inboxAjaxReply($res);
		// no break

	case 'update':
		// Category of an added domain
		$before = $domains->setCategory((int) inboxAjaxParam('domain_id', 'int'), $category);
		if ($before === null) {
			inboxDomainsFail($domains);
		}
		inboxAjaxReply(array('before' => $before));
		// no break

	case 'toggle':
		// A shipped domain disabled or enabled again (never changed); several for the undo of a restore
		$list = inboxAjaxParam('domains', 'none');
		$list = is_array($list) ? $list : array((string) inboxAjaxParam('domain'));
		inboxAjaxReply(array('domains' => $domains->setShipped($list, (int) inboxAjaxParam('on', 'int') === 1)));
		// no break

	case 'delete':
		$removed = $domains->delete((int) inboxAjaxParam('domain_id', 'int'));
		if ($removed === null) {
			inboxDomainsFail($domains);
		}
		inboxAjaxReply(array('removed' => $removed));
		// no break

	case 'restore':
		inboxAjaxReply(array('domains' => $domains->restoreShipped()));
		// no break

	case 'match':
		// « Recognize the sender by the domain of his address » (on by default)
		require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
		$on = (int) inboxAjaxParam('on', 'int') === 1 ? 1 : 0;
		dolibarr_set_const($db, 'INBOX_DOMAIN_MATCH', (string) $on, 'chaine', 0, 'Recognition of the sender by the domain of his address', $conf->entity);
		inboxAjaxReply(array('match' => $on));
		// no break

	case 'request':
		// From a mail, a user without the right asks the managers to mark the domain (bell); nothing is changed
		$domain = InboxTrustedDomain::normalize((string) inboxAjaxParam('domain'));
		if (!InboxDomains::valid($domain) || !in_array($category, InboxDomains::CATEGORIES, true)) {
			inboxAjaxError($langs->transnoentities('InboxDomainsBadCategory'), 422);
		}
		// One request per domain and per user a day, a few domains an hour: the bell of the managers is never flooded
		$limit = InboxDomains::requestLimit($db, (int) $user->id, $domain);
		if ($limit !== '') {
			inboxAjaxReply(array('told' => 0, 'limited' => $limit));
		}
		$notif = new InboxNotification($db);
		$n = 0;
		foreach ($domains->managers() as $uid) {
			if ($uid !== (int) $user->id && $notif->add($uid, 'domain_request', array('actor' => (int) $user->id, 'url' => '#/settings/domains',
				'payload' => array('detail' => $domain, 'text' => $langs->transnoentities('InboxDomainsRequestText', trim($user->firstname.' '.$user->lastname) ?: $user->login, $domain, $langs->transnoentities('InboxDomainsCat_'.$category))))) > 0) {
				$n++;
			}
		}
		inboxAjaxReply(array('told' => $n));
		// no break

	default:
		inboxAjaxNotFound();
}
