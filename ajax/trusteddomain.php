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
 * \file    inbox/ajax/trusteddomain.php
 * \ingroup inbox
 * \brief   Recognized domains (1.59.0): list, add (one or several from a mail), change, remove; right « Manage the
 *          recognized domains » only
 */

require __DIR__.'/inbox_ajax.inc.php';
dol_include_once('/inbox/class/inboxtrusteddomain.class.php');

$action = (string) inboxAjaxParam('action', 'aZ09');
inboxAjaxStart(in_array($action, array('create', 'update', 'delete'), true));
if (!$user->hasRight('inbox', 'trusteddomain', 'write')) {
	inboxAjaxNotFound();
}
$trusted = new InboxTrustedDomain($db);

/**
 * @param InboxTrustedDomain $trusted Helper
 * @return array<string,mixed>
 */
function inboxTrustedReply(InboxTrustedDomain $trusted)
{
	return array('domains' => $trusted->fetchAll(), 'many' => InboxTrustedDomain::MANY);
}

/**
 * Refusal of a domain, in words
 *
 * @param InboxTrustedDomain $trusted Helper
 * @return never
 */
function inboxTrustedError(InboxTrustedDomain $trusted)
{
	global $langs;

	$key = (string) $trusted->error;
	inboxAjaxError(strpos($key, 'InboxTrusted') === 0 ? $langs->transnoentities($key, $trusted->errorDomain) : $key, 422, array('domain' => $trusted->errorDomain));
}

switch ($action) {
	case 'list':
		inboxAjaxReply(inboxTrustedReply($trusted));
		// no break

	case 'create':
		// One domain (settings) or several of one mail, under one organisation: all or nothing
		$domains = inboxAjaxParam('domains', 'none');
		$domains = is_array($domains) ? array_slice(array_values(array_filter(array_map('strval', $domains))), 0, 10) : array((string) inboxAjaxParam('domain'));
		$label = (string) inboxAjaxParam('label');
		$note = (string) inboxAjaxParam('note');
		$subdomains = inboxAjaxHas('subdomains') ? (inboxAjaxParam('subdomains', 'int') ? 1 : 0) : 1;
		$db->begin();
		foreach ($domains as $d) {
			if ($trusted->create($user, $d, $label, $subdomains, $note) < 0) {
				$db->rollback();
				inboxTrustedError($trusted);
			}
		}
		$db->commit();
		inboxAjaxReply(inboxTrustedReply($trusted));
		// no break

	case 'update':
		$result = $trusted->update($user, (int) inboxAjaxParam('domain_id', 'int'), (string) inboxAjaxParam('domain'), (string) inboxAjaxParam('label'), inboxAjaxParam('subdomains', 'int') ? 1 : 0, (string) inboxAjaxParam('note'));
		if ($result === 0) {
			inboxAjaxNotFound();
		}
		if ($result < 0) {
			inboxTrustedError($trusted);
		}
		inboxAjaxReply(inboxTrustedReply($trusted));
		// no break

	case 'delete':
		$result = $trusted->delete((int) inboxAjaxParam('domain_id', 'int'));
		if ($result === 0) {
			inboxAjaxNotFound();
		}
		if ($result < 0) {
			inboxAjaxError($trusted->error, 500);
		}
		inboxAjaxReply(inboxTrustedReply($trusted));
		// no break

	default:
		inboxAjaxError('Unknown action', 400);
}
