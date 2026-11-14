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
 * \file    inbox/ajax/invite.php
 * \ingroup inbox
 * \brief   Invitations and read receipts of the mail read (lot W8 1.22.0): answer, agenda, receipt
 */

require __DIR__.'/inbox_ajax.inc.php';
dol_include_once('/inbox/class/inboxinvite.class.php');
dol_include_once('/inbox/class/inboxreceipt.class.php');

$action = (string) inboxAjaxParam('action', 'aZ09');
// Every action writes (a mail sent, the agenda, a keyword on the server)
inboxAjaxStart(true);

/**
 * Error of an action, said to the user
 *
 * @param string $code   Code
 * @param string $detail Detail (server answer)
 * @return never
 */
function inboxInviteFail($code, $detail)
{
	global $langs;

	$http = array('not_found' => 404, 'gone' => 404, 'no_send' => 403, 'no_agenda' => 403, 'bad_value' => 400, 'no_invite' => 409, 'no_receipt' => 409,
		'receipt_abuse' => 409, 'no_organizer' => 409, 'mails_disabled' => 409, 'bad_address' => 422);
	$keys = array('not_found' => 'InboxComposeGone', 'gone' => 'InboxComposeGone', 'no_send' => 'InboxErrorNeedSend', 'no_agenda' => 'InboxInviteNoAgenda',
		'no_invite' => 'InboxInviteGone', 'no_receipt' => 'InboxReceiptGone', 'receipt_abuse' => 'InboxReceiptAbuse', 'no_organizer' => 'InboxInviteNoOrganizer',
		'mails_disabled' => 'InboxComposeMailsDisabled', 'bad_address' => 'InboxComposeBadAddress', 'smtp_no_server' => 'InboxComposeSmtpNoServer',
		'smtp_auth' => 'InboxComposeSmtpAuth', 'smtp_recipient' => 'InboxComposeSmtpRecipient', 'smtp_connect' => 'InboxComposeSmtpConnect',
		'smtp_certificate' => 'InboxComposeSmtpCertificate', 'smtp_master' => 'InboxComposeSmtpMaster', 'smtp_no_password' => 'InboxComposeSmtpAuth',
		'smtp_no_auth' => 'InboxComposeSmtpAuth', 'smtp_no_starttls' => 'InboxComposeSmtpCertificate', 'agenda_error' => 'InboxInviteAgendaError');
	$shown = in_array($code, array('smtp_recipient', 'smtp_refused', 'error', 'smtp_connect', 'agenda_error'), true) ? (string) $detail : '';
	dol_syslog('inbox invite '.$code.' '.$detail, LOG_WARNING);
	// The option of Dolibarr that stops the sending, cited by its own label
	if ($code === 'mails_disabled') {
		$langs->load('admin');
	}
	inboxAjaxReply(array('error' => $langs->transnoentities($keys[$code] ?? 'InboxComposeFailed', $code === 'mails_disabled' ? $langs->transnoentities('MAIN_DISABLE_ALL_MAILS') : ''), 'code' => $code, 'detail' => dol_trunc($shown, 300)), $http[$code] ?? 502);
}

$mailId = (int) inboxAjaxParam('mail', 'int');

switch ($action) {
	case 'reply':
		// Accept, maybe, decline: the answer to the organizer (unless unchecked) and the agenda (if checked)
		$invite = new InboxInvite($db);
		$res = $invite->reply($user, $mailId, strtoupper((string) inboxAjaxParam('partstat', 'aZ09')), (int) inboxAjaxParam('send', 'int') === 1,
			(string) inboxAjaxParam('comment', 'alphanohtml'), (int) inboxAjaxParam('agenda', 'int') === 1);
		if ($res === null) {
			inboxInviteFail($invite->errorCode, $invite->error);
		}
		inboxAjaxReply($res);
		// no break

	case 'agenda':
		// add: the event in the agenda (or brought up to date after an update); cancel: marked cancelled
		$invite = new InboxInvite($db);
		$res = $invite->agenda($user, $mailId, (string) inboxAjaxParam('what', 'aZ09'));
		if ($res === null) {
			inboxInviteFail($invite->errorCode, $invite->error);
		}
		inboxAjaxReply($res);
		// no break

	case 'receipt':
		// send=1: the read receipt goes; send=0: the question is ignored; both for good ($MDNSent)
		$receipt = new InboxReceipt($db);
		$res = $receipt->answer($user, $mailId, (int) inboxAjaxParam('send', 'int') === 1);
		if ($res === null) {
			inboxInviteFail($receipt->errorCode, $receipt->error);
		}
		inboxAjaxReply($res);
		// no break

	default:
		inboxAjaxError('Unknown action', 400);
}
