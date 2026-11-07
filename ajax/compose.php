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
 * \file    inbox/ajax/compose.php
 * \ingroup inbox
 * \brief   Writing of a mail, lot W6 1.16.0: open, recipients, attachments, draft, send, close
 */

require __DIR__.'/inbox_ajax.inc.php';
dol_include_once('/inbox/class/inboxcomposer.class.php');

$action = (string) inboxAjaxParam('action', 'aZ09');
inboxAjaxStart(true);
$composer = new InboxComposer($db);

/**
 * Answer of a failure: a code for the screen, its sentence, never a secret
 *
 * @param InboxComposer $composer Composer
 * @return void
 */
function inboxComposeFail(InboxComposer $composer)
{
	global $langs;

	$code = (string) $composer->errorCode;
	$http = array('not_found' => 404, 'no_send' => 403, 'bad_mode' => 400, 'bad_address' => 422, 'no_recipient' => 422, 'too_big' => 413,
		'infected' => 422, 'mails_disabled' => 409, 'no_drafts' => 409);
	$keys = array('not_found' => 'InboxComposeGone', 'no_send' => 'InboxErrorNeedSend', 'bad_address' => 'InboxComposeBadAddress',
		'no_recipient' => 'InboxComposeNoRecipient', 'too_big' => 'InboxComposeTooBig', 'infected' => 'InboxComposeInfected',
		'mails_disabled' => 'InboxComposeMailsDisabled', 'no_drafts' => 'InboxComposeNoDrafts', 'smtp_no_server' => 'InboxComposeSmtpNoServer',
		'smtp_auth' => 'InboxComposeSmtpAuth', 'smtp_recipient' => 'InboxComposeSmtpRecipient', 'smtp_too_big' => 'InboxComposeTooBig',
		'smtp_connect' => 'InboxComposeSmtpConnect', 'smtp_certificate' => 'InboxComposeSmtpCertificate', 'smtp_master' => 'InboxComposeSmtpMaster', 'smtp_no_password' => 'InboxComposeSmtpAuth', 'smtp_no_auth' => 'InboxComposeSmtpAuth', 'smtp_no_starttls' => 'InboxComposeSmtpCertificate', 'hook' => 'InboxComposeRefused');
	$key = $keys[$code] ?? 'InboxComposeFailed';
	$detail = in_array($code, array('smtp_recipient', 'smtp_refused', 'hook', 'error', 'smtp_connect'), true) ? (string) $composer->error : '';
	dol_syslog('inbox compose '.$code.' '.$composer->error, LOG_WARNING);
	inboxAjaxReply(array('error' => $langs->transnoentities($key, implode(', ', $composer->bad)), 'code' => $code, 'detail' => dol_trunc($detail, 300)), $http[$code] ?? 502);
}

/**
 * What the user typed, as the composer reads it
 *
 * @return array<string,mixed>
 */
function inboxComposeTyped()
{
	$list = function ($key) {
		$v = inboxAjaxParam($key, 'none');
		return is_array($v) ? $v : (string) $v;
	};

	return array(
		'identity' => (int) inboxAjaxParam('identity', 'int'),
		'to' => $list('to'),
		'cc' => $list('cc'),
		'bcc' => $list('bcc'),
		'subject' => (string) inboxAjaxParam('subject', 'none'),
		// Cleaned by an allow list by the composer (InboxMailBuilder::cleanHtml), whatever the editor
		'html' => (string) inboxAjaxParam('html', 'none'),
		'quote' => (int) inboxAjaxParam('quote', 'int'),
		'signature' => (int) inboxAjaxParam('signature', 'int'),
		'receipt' => (int) inboxAjaxParam('receipt', 'int'),
	);
}

$token = (string) inboxAjaxParam('compose', 'aZ09');
switch ($action) {
	case 'open':
		$res = $composer->open($user, (string) inboxAjaxParam('mode', 'aZ09'), (int) inboxAjaxParam('mail', 'int'), (int) inboxAjaxParam('account', 'int'));
		if ($res === null) {
			inboxComposeFail($composer);
		}
		inboxAjaxReply($res);
		break;
	case 'recipients':
		inboxAjaxReply(array('items' => $composer->recipients($user, (string) inboxAjaxParam('q', 'alphanohtml'))));
		break;
	case 'upload':
		if (empty($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name']) || (int) $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
			inboxAjaxError($langs->transnoentities('InboxComposeUploadFailed'), 400);
		}
		$res = $composer->upload($user, $token, (string) $_FILES['file']['name'], (string) $_FILES['file']['tmp_name'], (string) $_FILES['file']['type']);
		if ($res === null) {
			inboxComposeFail($composer);
		}
		inboxAjaxReply(array('attachment' => $res));
		break;
	case 'unattach':
		if (!$composer->removeAttachment($user, $token, (string) inboxAjaxParam('id', 'aZ09'))) {
			inboxComposeFail($composer);
		}
		inboxAjaxReply(array('ok' => 1));
		break;
	case 'save':
		$res = $composer->saveDraft($user, $token, inboxComposeTyped());
		if ($res === null) {
			inboxComposeFail($composer);
		}
		inboxAjaxReply($res);
		break;
	case 'send':
		$res = $composer->send($user, $token, inboxComposeTyped());
		if ($res === null) {
			inboxComposeFail($composer);
		}
		inboxAjaxReply($res);
		break;
	case 'close':
		if (!$composer->close($user, $token, (int) inboxAjaxParam('discard', 'int') === 1)) {
			inboxComposeFail($composer);
		}
		inboxAjaxReply(array('ok' => 1));
		break;
	default:
		inboxAjaxError('Unknown action', 400);
}
