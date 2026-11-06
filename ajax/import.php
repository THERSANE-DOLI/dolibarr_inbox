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
 * \brief   Import of mailboxes from the sources declared by other modules (admins of the mailboxes)
 */

require __DIR__.'/inbox_ajax.inc.php';
dol_include_once('/inbox/class/inboxaccountimport.class.php');
dol_include_once('/inbox/class/inboxservertester.class.php');

$action = (string) inboxAjaxParam('action', 'aZ09');
inboxAjaxStart($action === 'run');
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

	default:
		inboxAjaxError('Unknown action', 400);
}
