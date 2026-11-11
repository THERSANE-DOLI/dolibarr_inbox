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
dol_include_once('/inbox/class/inboxai.class.php');
dol_include_once('/inbox/class/inboxdictation.class.php');

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
	$http = array('already_sent' => 409, 'bad_date' => 422, 'not_found' => 404, 'no_send' => 403, 'bad_mode' => 400, 'bad_address' => 422, 'no_recipient' => 422, 'too_big' => 413,
		'infected' => 422, 'mails_disabled' => 409, 'no_drafts' => 409, 'no_manage' => 403, 'unsub_none' => 404, 'unsub_unsigned' => 422, 'unsub_foreign' => 422);
	$keys = array('already_sent' => 'InboxOutboxAlreadySent', 'bad_date' => 'InboxComposeBadDate', 'interrupted' => 'InboxOutboxInterrupted', 'not_found' => 'InboxComposeGone', 'no_send' => 'InboxErrorNeedSend', 'bad_address' => 'InboxComposeBadAddress',
		'no_recipient' => 'InboxComposeNoRecipient', 'too_big' => 'InboxComposeTooBig', 'infected' => 'InboxComposeInfected',
		'mails_disabled' => 'InboxComposeMailsDisabled', 'no_drafts' => 'InboxComposeNoDrafts', 'smtp_no_server' => 'InboxComposeSmtpNoServer',
		'smtp_auth' => 'InboxComposeSmtpAuth', 'smtp_recipient' => 'InboxComposeSmtpRecipient', 'smtp_too_big' => 'InboxComposeTooBig',
		'smtp_connect' => 'InboxComposeSmtpConnect', 'smtp_certificate' => 'InboxComposeSmtpCertificate', 'smtp_master' => 'InboxComposeSmtpMaster', 'smtp_no_password' => 'InboxComposeSmtpAuth', 'smtp_no_auth' => 'InboxComposeSmtpAuth', 'smtp_no_starttls' => 'InboxComposeSmtpCertificate', 'hook' => 'InboxComposeRefused',
		'no_manage' => 'InboxErrorNeedManage', 'unsub_none' => 'InboxErrorUnsubscribeNone', 'unsub_unsigned' => 'InboxErrorUnsubscribeNotSigned', 'unsub_foreign' => 'InboxErrorUnsubscribeForeign');
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
		// AI in the writing (1.27.0): the mailboxes it may be used from, and whether the mail answered may go with it
		$ai = new InboxAi($db);
		$res['ai'] = array('accounts' => $ai->accountsFor($user), 'mail' => InboxAi::mailAllowed() ? 1 : 0);
		// Voice typing (1.28.0): the mailboxes it may be used from, and the receivers of the voice already agreed to
		$dictation = new InboxDictation($db);
		$res['dictation'] = array('accounts' => $dictation->accountsFor($user), 'consents' => (object) $dictation->consents($user)) + InboxDictation::engine();
		inboxAjaxReply($res);
		break;
	case 'ai':
		// A proposal of the AI for the writing: written, rephrased or translated; never put in the mail by the server
		$dir = $composer->held($user, $token);
		$identity = $dir !== null ? $composer->identityOf($user, (int) inboxAjaxParam('identity', 'int')) : null;
		if ($dir === null || !$identity) {
			$composer->errorCode = 'not_found';
			inboxComposeFail($composer);
		}
		$ai = new InboxAi($db);
		$state = InboxComposer::readState($dir);
		$mailId = (int) inboxAjaxParam('with_mail', 'int') === 1 && !empty($state['source']['mail']) ? (int) $state['source']['mail'] : 0;
		$html = $ai->run($user, (int) $identity['account'], (string) inboxAjaxParam('kind', 'aZ09'), array(
			'prompt' => (string) inboxAjaxParam('prompt', 'none'),
			'text' => (string) inboxAjaxParam('text', 'none'),
			'style' => (string) inboxAjaxParam('style', 'aZ09'),
			'lang' => (string) inboxAjaxParam('lang', 'aZ09'),
			'mail' => $mailId ? $ai->mailText($user, $mailId) : '',
		));
		if ($html === null) {
			$http = array('off' => 403, 'mailbox' => 403, 'right' => 403, 'limit' => 429, 'empty' => 422, 'service' => 502);
			// The text of the AI service (its name, sometimes its address) for the administrators only (audit INBX-037)
			inboxAjaxReply(array('error' => $langs->transnoentities('InboxAiError_'.$ai->errorCode), 'code' => $ai->errorCode, 'detail' => !empty($user->admin) ? $ai->error : ''), $http[$ai->errorCode] ?? 502);
		}
		inboxAjaxReply(array('html' => $html));
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
		// at: date of a sending scheduled; record: pieces (« type:id ») to record the sending on
		$record = inboxAjaxParam('record', 'none');
		// force: « Send anyway » after an answer of a colleague was said (lot E2, 1.77.0)
		$res = $composer->send($user, $token, inboxComposeTyped(), array('at' => (int) inboxAjaxParam('at', 'int'), 'record' => is_array($record) ? array_map('strval', $record) : array(),
			'force' => (int) inboxAjaxParam('force', 'int') === 1, 'catchup' => (string) inboxAjaxParam('catchup', 'aZ09')));
		if ($res === null) {
			inboxComposeFail($composer);
		}
		inboxAjaxReply($res);
		break;
	case 'dispatch':
	case 'cancel':
	case 'record':
	case 'outbox':
		// Queue of the sending (1.17.0): send now, undo (the writing comes back), record on pieces, my mails waiting
		dol_include_once('/inbox/class/inboxoutbox.class.php');
		dol_include_once('/inbox/class/inboxlivesend.class.php');
		$outbox = new InboxOutbox($db);
		$id = (int) inboxAjaxParam('id', 'int');
		$liveSend = new InboxLiveSend($db);
		if ($action === 'outbox') {
			// With the mails of the protected mailboxes in their delay (kept in this session, 1.89.0)
			inboxAjaxReply(array('items' => array_merge($outbox->listMine($user), $liveSend->listMine($user))));
		}
		if (InboxLiveSend::isLive($id)) {
			// Protected mailbox (1.89.0): « beacon » is the page closing (navigator.sendBeacon): sent to the end, even
			// when nobody waits for the answer
			if ($action === 'dispatch' && (int) inboxAjaxParam('beacon', 'int') === 1) {
				ignore_user_abort(true);
				set_time_limit(120);
			}
			if ($action === 'record') {
				inboxAjaxReply(array('recorded' => array()));
			}
			$res = $action === 'dispatch' ? $liveSend->deliver($user, $id, (int) inboxAjaxParam('beacon', 'int') === 1) : $liveSend->cancel($user, $id);
			if ($res === null) {
				$composer->errorCode = $liveSend->errorCode;
				$composer->error = $liveSend->error;
				inboxComposeFail($composer);
			}
			inboxAjaxReply($res);
		}
		if ($action === 'record') {
			$pieces = inboxAjaxParam('pieces', 'none');
			$res = $outbox->record($user, $id, is_array($pieces) ? array_map('strval', $pieces) : array());
			$res = $res === null ? null : array('recorded' => $res);
		} else {
			$res = $action === 'dispatch' ? $outbox->dispatch($user, $id) : $outbox->cancel($user, $id);
		}
		if ($res === null) {
			$composer->errorCode = $outbox->errorCode;
			$composer->error = $outbox->error;
			inboxComposeFail($composer);
		}
		inboxAjaxReply($res);
		break;
	case 'templates':
		// Templates of the mailbox of the identity and of the linked pieces (1.18.0)
		$res = $composer->templates($user, $token, (int) inboxAjaxParam('identity', 'int'));
		if ($res === null) {
			inboxComposeFail($composer);
		}
		inboxAjaxReply(array('groups' => $res));
		break;
	case 'template':
		$res = $composer->applyTemplate($user, $token, (int) inboxAjaxParam('id', 'int'), (int) inboxAjaxParam('identity', 'int'), (string) inboxAjaxParam('piece', 'alphanohtml'),
			(string) inboxAjaxParam('to', 'alphanohtml'));
		if ($res === null) {
			inboxComposeFail($composer);
		}
		inboxAjaxReply($res);
		break;
	case 'values':
		$res = $composer->templateValues($user, $token, (int) inboxAjaxParam('identity', 'int'), (string) inboxAjaxParam('piece', 'alphanohtml'), (string) inboxAjaxParam('to', 'alphanohtml'),
			(string) inboxAjaxParam('lang', 'aZ09'));
		if ($res === null) {
			inboxComposeFail($composer);
		}
		inboxAjaxReply(array('values' => $res));
		break;
	case 'documents':
		// Documents of the linked pieces, to attach them (1.18.0)
		$res = $composer->documents($user, $token);
		if ($res === null) {
			inboxComposeFail($composer);
		}
		inboxAjaxReply(array('pieces' => $res));
		break;
	case 'piecesearch':
		// Insert a piece (lot A5, 1.83.0): the pieces of the thirdparties of the recipients, or of all
		$emails = inboxAjaxParam('emails', 'none');
		$res = $composer->pieceSearch($user, $token, (string) inboxAjaxParam('term', 'alphanohtml'), (int) inboxAjaxParam('all', 'int') === 1, is_array($emails) ? array_map('strval', $emails) : array());
		if ($res === null) {
			inboxComposeFail($composer);
		}
		inboxAjaxReply($res);
		break;
	case 'pieceoffer':
		$res = $composer->pieceOffer($user, $token, (string) inboxAjaxParam('type', 'aZ09'), (int) inboxAjaxParam('id', 'int'));
		if ($res === null) {
			inboxComposeFail($composer);
		}
		inboxAjaxReply($res);
		break;
	case 'pieceinsert':
		$ask = inboxAjaxParam('ask', 'none');
		$res = $composer->pieceInsert($user, $token, (string) inboxAjaxParam('type', 'aZ09'), (int) inboxAjaxParam('id', 'int'), is_array($ask) ? $ask : array());
		if ($res === null) {
			inboxComposeFail($composer);
		}
		inboxAjaxReply($res);
		break;
	case 'attachdoc':
		$res = $composer->attachDocument($user, $token, (string) inboxAjaxParam('piece', 'alphanohtml'), (string) inboxAjaxParam('name', 'none'));
		if ($res === null) {
			inboxComposeFail($composer);
		}
		inboxAjaxReply(array('attachment' => $res));
		break;
	case 'redirect':
		// Redirect a mail (B19): sent at once, as it is, to other recipients
		$to = inboxAjaxParam('to', 'none');
		$res = $composer->redirect($user, (int) inboxAjaxParam('mail', 'int'), is_array($to) ? $to : (string) $to);
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
