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
 * \file    inbox/ajax/mailtransfer.php
 * \ingroup inbox
 * \brief   Mails imported into a folder by batches (.eml, mbox), folder exported (.mbox, .zip), lot W4 1.12.0
 */

require __DIR__.'/inbox_ajax.inc.php';
dol_include_once('/inbox/class/inboxmailtransfer.class.php');

$action = (string) inboxAjaxParam('action', 'aZ09');
inboxAjaxStart($action === 'import');
$transfer = new InboxMailTransfer($db);
$folderId = (int) inboxAjaxParam('folder', 'int');

/**
 * Bytes of a size of php.ini (8M, 1G...)
 *
 * @param string $v Value
 * @return int
 */
function inboxIniBytes($v)
{
	$v = trim((string) $v);
	$n = (int) $v;
	switch (strtolower(substr($v, -1))) {
		case 'g':
			$n *= 1024;
			// no break
		case 'm':
			$n *= 1024;
			// no break
		case 'k':
			$n *= 1024;
	}

	return $n;
}

switch ($action) {
	case 'limits':
		// Raw bytes per call: the base64 of the batch (4/3) and the JSON have to fit in post_max_size
		$post = inboxIniBytes(ini_get('post_max_size')) ?: 8388608;
		inboxAjaxReply(array('batch' => (int) min(16777216, floor($post * 0.7 * 3 / 4)), 'mail' => InboxMailTransfer::MAX_MAIL));
		// no break

	case 'import':
		if (!$transfer->open($user, $folderId, InboxAccess::LEVEL_MANAGE)) {
			inboxAjaxReply(array('error' => $langs->transnoentities($transfer->error), 'code' => $transfer->error), $transfer->error === 'InboxErrorNeedManage' ? 403 : 404);
		}
		$list = inboxAjaxParam('mails', 'none');
		$raws = array();
		foreach (is_array($list) ? array_slice($list, 0, 500) : array() as $b64) {
			$raw = base64_decode((string) $b64, true);
			$raws[] = $raw === false ? '' : $raw;
		}
		session_write_close();
		@set_time_limit(300);
		$results = $transfer->import($user, $raws, (int) inboxAjaxParam('mbox', 'int') === 1);
		if ($transfer->error !== '') {
			inboxAjaxReply(array('error' => $langs->transnoentities($transfer->error), 'code' => $transfer->error), 422);
		}
		foreach ($results as $i => $r) {
			if (isset($r['reason']) && preg_match('/^Inbox[A-Za-z]+$/', $r['reason'])) {
				$results[$i]['reason'] = $langs->transnoentities($r['reason']);
			}
		}
		inboxAjaxReply(array('results' => $results));
		// no break

	case 'export':
		$format = (string) inboxAjaxParam('format', 'aZ09') === 'zip' ? 'zip' : 'mbox';
		if (!$transfer->open($user, $folderId, InboxAccess::LEVEL_READ)) {
			inboxAjaxReply(array('error' => $langs->transnoentities($transfer->error)), 404);
		}
		session_write_close();
		if (!$transfer->export($user, $format)) {
			header('Content-Type: application/json');
			inboxAjaxReply(array('error' => $langs->transnoentities($transfer->error)), 422);
		}
		exit;

	default:
		inboxAjaxError('Unknown action', 400);
}
