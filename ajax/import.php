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
 * \file    inbox/ajax/import.php
 * \ingroup inbox
 * \brief   Import of mailboxes: sources declared by other modules, QR codes of Thunderbird (admins of the mailboxes)
 */

require __DIR__.'/inbox_ajax.inc.php';
dol_include_once('/inbox/class/inboxaccountimport.class.php');
dol_include_once('/inbox/class/inboxservertester.class.php');
dol_include_once('/inbox/class/inboxthunderbirdqr.class.php');

$action = (string) inboxAjaxParam('action', 'aZ09');
inboxAjaxStart(in_array($action, array('run', 'qr_read', 'qr_import'), true));
inboxAjaxNeedAdmin();
$import = new InboxAccountImport($db);

switch ($action) {
	case 'sources':
		$out = array();
		foreach ($import->getSources() as $code => $source) {
			$out[] = array('code' => $code, 'label' => $source['label']);
		}
		inboxAjaxReply(array('sources' => $out));
		// no break

	case 'list':
		$list = $import->listNew((string) inboxAjaxParam('source', 'aZ09'));
		if ($list === null) {
			inboxAjaxNotFound();
		}
		inboxAjaxReply(array('accounts' => $list));
		// no break

	case 'run':
		$emails = inboxAjaxParam('emails', 'none');
		$report = $import->import((string) inboxAjaxParam('source', 'aZ09'), is_array($emails) ? $emails : array(), $user);
		if ($report === null) {
			inboxAjaxNotFound();
		}
		// Each new mailbox is tested at once (read only); it stays a draft, enabled by hand after the test
		$tester = new InboxServerTester($db);
		$tests = array();
		foreach ($report['created'] as $email => $id) {
			$account = new InboxAccount($db);
			if ($account->fetch($id) > 0) {
				$result = $tester->runAndSave($account, $user);
				$first = '';
				foreach ($result['steps'] as $step) {
					if ($step['status'] === InboxServerTester::ERROR) {
						$first = $step['message'];
						break;
					}
				}
				$tests[] = array('id' => (int) $id, 'email' => $email, 'status' => $result['status'], 'message' => $first);
			}
		}
		inboxAjaxReply(array('report' => $report, 'tests' => $tests));
		// no break

	case 'qr_read':
		// Texts of the QR codes « Export for mobile » (the image never leaves the browser): checked here, kept encrypted
		// in session; the passwords never go back to the browser
		$texts = inboxAjaxParam('codes', 'none');
		$qr = new InboxThunderbirdQr($db);
		$read = is_array($texts) ? $qr->parseAll(array_values($texts)) : null;
		if ($read === null) {
			inboxAjaxError($langs->transnoentities('InboxQrError'.ucfirst($qr->error ? $qr->error : 'invalid')), 422);
		}
		inboxAjaxReply(array('token' => $qr->keep($read['accounts']), 'end' => $read['end'], 'read' => $read['read'], 'complete' => $read['complete'],
			'accounts' => $qr->forClient($read['accounts'])));
		// no break

	case 'qr_import':
		$qr = new InboxThunderbirdQr($db);
		// One import per reading: a second click does not create twice
		$accounts = $qr->take((string) inboxAjaxParam('qr_token', 'aZ09'));
		$choices = inboxAjaxParam('choices', 'none');
		if ($accounts === null || !is_array($choices)) {
			inboxAjaxError($langs->transnoentities('InboxQrErrorExpired'), 410);
		}
		$tester = new InboxServerTester($db);
		$giveManage = (bool) inboxAjaxParam('give_manage', 'int');
		$results = array();
		foreach ($choices as $choice) {
			$i = is_array($choice) && isset($choice['index']) ? (int) $choice['index'] : -1;
			if (isset($accounts[$i]) && is_array($accounts[$i])) {
				$type = isset($choice['type']) ? (string) $choice['type'] : '';
				$owner = isset($choice['owner']) ? (int) $choice['owner'] : 0;
				$row = $qr->importAccount($accounts[$i], $type, $owner, $giveManage, $user, $tester);
				if ($row !== null && $row['state'] === 'error' && $row['message'] !== '') {
					$row['message'] = inboxAjaxTransErrors(explode(', ', $row['message']));
				}
				$results[] = $row;
			}
		}
		InboxAccess::clearCache();
		inboxAjaxReply(array('results' => array_values(array_filter($results))));
		// no break

	default:
		inboxAjaxError('Unknown action', 400);
}
