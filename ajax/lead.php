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
 * \file    inbox/ajax/lead.php
 * \ingroup inbox
 * \brief   New customer from a mail (lot A1, 1.79.0): panel (values of the mail, same thirdparties, form), quick
 *          creation and its undo, full page of creation, contacts left to create
 */

require __DIR__.'/inbox_ajax.inc.php';
dol_include_once('/inbox/class/inboxlead.class.php');
dol_include_once('/inbox/class/inboxcreate.class.php');
dol_include_once('/inbox/class/inboxmimeparser.class.php');

$action = (string) inboxAjaxParam('action', 'aZ09');
inboxAjaxStart(in_array($action, array('create', 'full', 'undo', 'dismiss'), true));
$langs->loadLangs(array('companies'));
$lead = new InboxLead($db);

/**
 * The mail asked, readable by the user (else 404)
 *
 * @return InboxReader
 */
function inboxLeadMail()
{
	global $db, $user, $langs;

	// Protected mailbox (1.89.0): no link, note, piece nor thirdparty from its mails (D1)
	if (InboxLive::isMail((int) inboxAjaxParam('id', 'int'))) {
		inboxAjaxReply(array('error' => $langs->transnoentities('InboxProtectNoDolibarr'), 'code' => 'protected'), 403);
	}
	$reader = new InboxReader($db);
	if (!$reader->load($user, (int) inboxAjaxParam('id', 'int'))) {
		inboxAjaxNotFound();
	}

	return $reader;
}

/**
 * What the panel sent: thirdparty, contact (null when not created), options
 *
 * @return array{0:array<string,string>,1:array<string,string>|null,2:array<string,mixed>}
 */
function inboxLeadInput()
{
	$soc = InboxLead::clean((array) inboxAjaxParam('soc', 'none'), InboxLead::SOC_FIELDS);
	$c = inboxAjaxParam('contact', 'none');
	$contact = is_array($c) && $c ? InboxLead::clean($c, InboxLead::CONTACT_FIELDS) : null;
	$o = (array) inboxAjaxParam('opts', 'none');
	$opts = array();
	foreach (array('prospect', 'customer', 'supplier', 'private', 'vat_subject', 'use_soc') as $k) {
		$opts[$k] = (int) ($o[$k] ?? 0);
	}
	$opts['categories'] = array_values(array_filter(array_map('intval', (array) ($o['categories'] ?? array()))));
	$opts['commercial'] = array_values(array_filter(array_map('intval', (array) ($o['commercial'] ?? array()))));
	$opts['categories_supplier'] = array_values(array_filter(array_map('intval', (array) ($o['categories_supplier'] ?? array()))));

	return array($soc, $contact, $opts);
}

/**
 * @param InboxLead $lead Lead
 * @return void
 */
function inboxLeadFail(InboxLead $lead)
{
	global $langs;

	$codes = array('rights' => 403, 'missing' => 422, 'need_full' => 409, 'soc' => 422, 'contact' => 422, 'link' => 422, 'undo' => 409);
	$msg = preg_match('/^Inbox[A-Za-z]+$/', $lead->error) ? $langs->transnoentities($lead->error) : $lead->error;
	if ($lead->errorCode === 'missing') {
		$msg = $langs->transnoentities('InboxLeadMissing', $lead->error);
	}
	inboxAjaxReply(array('error' => $msg, 'code' => $lead->errorCode), $codes[$lead->errorCode] ?? 500);
}

switch ($action) {
	case 'open':
		// The panel: what the mail says, the thirdparties that may be the same, what the page of creation would ask
		$reader = inboxLeadMail();
		$raw = $reader->raw($user);
		if ($raw === null) {
			inboxAjaxError($reader->error ?: $langs->transnoentities('InboxMailNotFound'), 502);
		}
		$found = $lead->extract($reader->row, InboxMimeParser::parse($raw));
		inboxAjaxReply(array('found' => $found, 'duplicates' => $lead->duplicates($user, $found), 'form' => $lead->form($user), 'suggest' => $lead->suggestions($reader->row)));
		// no break

	case 'create':
		$reader = inboxLeadMail();
		list($soc, $contact, $opts) = inboxLeadInput();
		$res = $lead->create($user, $reader->row, $soc, $contact, $opts);
		if ($res === null) {
			inboxLeadFail($lead);
		}
		$res['soc_url'] = DOL_URL_ROOT.'/societe/card.php?socid='.$res['soc'];
		// The contact needs its own page: offered in the bandeau, filled and tied to the thirdparty
		if ($res['contact_error'] !== '' && is_array($contact)) {
			$res['contact_url'] = InboxLead::contactUrl($res['soc'], $contact);
			$lead->pending($user, (int) $reader->row->rowid, $res['soc'], $contact);
		}
		inboxAjaxReply($res);
		// no break

	case 'full':
		// The full page of creation, filled with the panel; the contact follows when the thirdparty is saved (trigger)
		$reader = inboxLeadMail();
		list($soc, $contact, $opts) = inboxLeadInput();
		$create = new InboxCreate($db);
		$url = $create->leadIntent($user, $reader, InboxLead::fullUrl($soc, $contact, $opts), empty($opts['private']) ? $contact : null);
		if ($url === '') {
			inboxAjaxError($langs->transnoentities($create->error ?: 'InboxErrorCannotCreate'), 403);
		}
		inboxAjaxReply(array('url' => $url));
		// no break

	case 'undo':
		if (!$lead->undo($user, (int) inboxAjaxParam('soc', 'int'), (int) inboxAjaxParam('contact', 'int'))) {
			inboxLeadFail($lead);
		}
		inboxAjaxReply(array('done' => 1));
		// no break

	case 'pending':
		// Contacts left to create for this mail (their page was needed)
		$reader = inboxLeadMail();
		inboxAjaxReply(array('pending' => $lead->pendingFor($user, (int) $reader->row->rowid)));
		// no break

	case 'dismiss':
		$lead->dismiss($user, (int) inboxAjaxParam('id', 'int'), (int) inboxAjaxParam('soc', 'int'));
		inboxAjaxReply(array('done' => 1));
		// no break

	default:
		inboxAjaxError('Unknown action', 400);
}
