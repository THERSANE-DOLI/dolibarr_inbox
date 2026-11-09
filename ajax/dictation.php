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
 * \file    inbox/ajax/dictation.php
 * \ingroup inbox
 * \brief   Voice typing (1.28.0): consents of the current user, given in the writing, withdrawn in My settings; the
 *          transcription server (1.35.0): set by the admin of the mailboxes, a piece of voice turned into text
 */

require __DIR__.'/inbox_ajax.inc.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
dol_include_once('/inbox/class/inboxdictation.class.php');
dol_include_once('/inbox/class/inboxwhisper.class.php');

$action = (string) inboxAjaxParam('action', 'aZ09');
inboxAjaxStart(in_array($action, array('consent', 'revoke', 'transcribe', 'server_test', 'server_save'), true));
$dictation = new InboxDictation($db);

/**
 * Key of the transcription server: the one typed, else the one kept, but only for the address it was kept with
 * (the key never goes to another address)
 *
 * @param string $url Address of the call
 * @return string
 */
function inboxWhisperKey($url)
{
	$typed = (string) inboxAjaxParam('key', 'none');
	if ($typed !== '') {
		return $typed;
	}
	$kept = getDolGlobalString('INBOX_WHISPER_KEY');
	if ($kept !== '' && rtrim(strtolower(trim((string) $url)), '/') === rtrim(strtolower(trim(getDolGlobalString('INBOX_WHISPER_URL'))), '/')) {
		return (string) dolDecrypt($kept);
	}

	return '';
}

/**
 * Address typed by the admin, refused when not http(s) or not allowed by the configuration
 *
 * @return string
 */
function inboxWhisperUrl()
{
	global $langs;

	$url = rtrim(trim((string) inboxAjaxParam('url', 'alphanohtml')), '/');
	if (!InboxMeili::validUrl($url)) {
		inboxAjaxError($langs->transnoentities('InboxWhisperBadUrl'), 422, array('url' => 1));
	}
	dol_include_once('/inbox/class/inboxservertester.class.php');
	if (!InboxServerTester::hostAllowed(InboxWhisper::hostOf($url))) {
		inboxAjaxError($langs->transnoentities('InboxErrorHostNotAllowed', InboxWhisper::hostOf($url)), 422, array('url' => 1));
	}
	// A host of the private network, new: chosen by the administrator of Dolibarr (audit INBX-010)
	if (InboxServerTester::privateRefused(InboxWhisper::hostOf($url), array(InboxWhisper::hostOf(getDolGlobalString('INBOX_WHISPER_URL'))))) {
		inboxAjaxError($langs->transnoentities('InboxErrorPrivateHost', InboxWhisper::hostOf($url)), 422, array('url' => 1));
	}

	return $url;
}

switch ($action) {
	case 'get':
		inboxAjaxReply(array('enabled' => InboxDictation::enabled() ? 1 : 0, 'consents' => (object) $dictation->consents($user)) + InboxDictation::engine());
		// no break

	case 'consent':
		if (!$dictation->consent($user, (string) inboxAjaxParam('provider', 'aZ09'), (int) inboxAjaxParam('account', 'int'))) {
			inboxAjaxError($langs->transnoentities('InboxDictateRefused'), 403);
		}
		inboxAjaxReply(array('consents' => (object) $dictation->consents($user)));
		// no break

	case 'revoke':
		$dictation->revoke($user, (string) inboxAjaxParam('provider', 'aZ09'));
		inboxAjaxReply(array('consents' => (object) $dictation->consents($user)));
		// no break

	case 'transcribe':
		// A piece of voice of a writing: dictation allowed for its mailbox, the server chosen, the consent given (the
		// voice is a biometric data, even when it stays on the private network)
		$engine = InboxDictation::engine();
		if ($engine['engine'] !== 'server' || !in_array((int) inboxAjaxParam('account', 'int'), $dictation->accountsFor($user), true)) {
			inboxAjaxError($langs->transnoentities('InboxDictateRefused'), 403);
		}
		if (empty($dictation->consents($user)['server'])) {
			inboxAjaxError($langs->transnoentities('InboxDictateRefused'), 403, array('consent' => 1));
		}
		if (empty($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name']) || (int) $_FILES['file']['error'] !== UPLOAD_ERR_OK
			|| (int) $_FILES['file']['size'] > InboxWhisper::MAX_BYTES) {
			inboxAjaxError($langs->transnoentities('InboxDictateBadAudio'), 422);
		}
		$mime = strtolower(trim(explode(';', (string) $_FILES['file']['type'])[0]));
		if (!preg_match('/^audio\/[a-z0-9.+-]+$/', $mime)) {
			inboxAjaxError($langs->transnoentities('InboxDictateBadAudio'), 422);
		}
		// A cap per user and per hour (audit INBX-033)
		if (!$dictation->countPiece($user)) {
			inboxAjaxError($langs->transnoentities('InboxDictateLimit'), 429);
		}
		$client = InboxWhisper::fromConf();
		$text = $client->transcribe((string) $_FILES['file']['tmp_name'], $mime, substr((string) $langs->defaultlang, 0, 2), InboxWhisper::model());
		if ($text === null) {
			dol_syslog('inbox: transcription server '.$engine['host'].': '.$client->error, LOG_WARNING);
			inboxAjaxError($langs->transnoentities('InboxDictateServerDown', !empty($user->admin) ? ': '.$client->error : ''), 502);
		}
		inboxAjaxReply(array('text' => $text));
		// no break

	case 'server_get':
		inboxAjaxNeedAdmin();
		$url = getDolGlobalString('INBOX_WHISPER_URL');
		inboxAjaxReply(array(
			'engine' => getDolGlobalString('INBOX_DICTATION_ENGINE', 'browser') === 'server' ? 'server' : 'browser',
			'url' => $url,
			'model' => getDolGlobalString('INBOX_WHISPER_MODEL'),
			'has_key' => getDolGlobalString('INBOX_WHISPER_KEY') !== '',
			'local' => $url === '' || InboxMeili::isLocal($url),
		));
		// no break

	case 'server_test':
		inboxAjaxNeedAdmin();
		$url = inboxWhisperUrl();
		$client = new InboxWhisper($url, inboxWhisperKey($url));
		$client->timeout = 8;
		$models = $client->models();
		if ($models === null) {
			// No answer of the private network said to who is not administrator of Dolibarr
			$said = !empty($user->admin) || !InboxMeili::isLocal($url) ? ': '.$client->error : '';
			inboxAjaxReply(array('ok' => false, 'error' => $langs->transnoentities('InboxWhisperNoAnswer', $said), 'local' => InboxMeili::isLocal($url)));
		}
		inboxAjaxReply(array('ok' => true, 'models' => array_slice($models, 0, 100), 'local' => InboxMeili::isLocal($url)));
		// no break

	case 'server_save':
		inboxAjaxNeedAdmin();
		$entity = (int) $conf->entity;
		if ((string) inboxAjaxParam('engine', 'aZ09') !== 'server') {
			dolibarr_set_const($db, 'INBOX_DICTATION_ENGINE', 'browser', 'chaine', 0, '', $entity);
			inboxAjaxReply(array('engine' => 'browser'));
		}
		$url = inboxWhisperUrl();
		$model = trim((string) inboxAjaxParam('model', 'alphanohtml'));
		if ($model !== '' && !preg_match('/^[A-Za-z0-9._:\/-]{1,128}$/', $model)) {
			inboxAjaxError($langs->transnoentities('InboxWhisperBadModel'), 422, array('model' => 1));
		}
		$key = inboxWhisperKey($url);
		$client = new InboxWhisper($url, $key);
		$client->timeout = 8;
		if ($client->models() === null) {
			inboxAjaxError($langs->transnoentities('InboxWhisperNoAnswer', !empty($user->admin) || !InboxMeili::isLocal($url) ? ': '.$client->error : ''), 422, array('url' => 1));
		}
		// Another host: the consents given to the former one no longer count
		if (InboxWhisper::hostOf($url) !== InboxWhisper::hostOf(getDolGlobalString('INBOX_WHISPER_URL'))) {
			dolibarr_set_const($db, 'INBOX_WHISPER_STAMP', (string) dol_now(), 'chaine', 0, '', $entity);
		}
		dolibarr_set_const($db, 'INBOX_WHISPER_URL', $url, 'chaine', 0, '', $entity);
		dolibarr_set_const($db, 'INBOX_WHISPER_MODEL', $model, 'chaine', 0, '', $entity);
		if ((string) inboxAjaxParam('key', 'none') !== '' || $key === '') {
			dolibarr_set_const($db, 'INBOX_WHISPER_KEY', $key === '' ? '' : dolEncrypt($key), 'chaine', 0, '', $entity);
		}
		dolibarr_set_const($db, 'INBOX_DICTATION_ENGINE', 'server', 'chaine', 0, '', $entity);
		inboxAjaxReply(array('engine' => 'server', 'local' => InboxMeili::isLocal($url)));
		// no break

	default:
		inboxAjaxError('Unknown action', 400);
}
