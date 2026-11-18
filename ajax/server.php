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
 * \file    inbox/ajax/server.php
 * \ingroup inbox
 * \brief   Settings > Server of the application (1.54.0): admins of Dolibarr only (master password of the mail
 *          server, scheduled jobs, refresh of the lists and push service 1.124.0)
 */

require __DIR__.'/inbox_ajax.inc.php';
require_once __DIR__.'/../class/inboxserver.class.php';
require_once __DIR__.'/../class/inboxreactivity.class.php';

$action = (string) inboxAjaxParam('action', 'aZ09');
inboxAjaxStart(in_array($action, array('set', 'master', 'job', 'reactivity', 'push', 'pushtest'), true));
if (empty($user->admin)) {
	inboxAjaxNotFound();
}

$server = new InboxServer($db);
switch ($action) {
	case 'get':
		inboxAjaxReply($server->get() + array('cron' => $server->cronJobs(), 'reactivity' => (new InboxReactivity($db))->data()));
		// no break

	case 'set':
		if (!$server->setNumber((string) inboxAjaxParam('name', 'aZ09'), (string) inboxAjaxParam('value'))) {
			inboxAjaxError($langs->transnoentities($server->error ?: 'InboxErrorServerValue'), 422);
		}
		inboxAjaxReply(array('ok' => 1));
		// no break

	case 'master':
		$separator = (string) inboxAjaxParam('separator');
		// The password raw (never printed, encrypted by the server)
		if (!$server->setMaster((string) inboxAjaxParam('user'), (string) inboxAjaxParam('host'), $separator, (string) inboxAjaxParam('password', 'none'))) {
			inboxAjaxError($langs->transnoentities($server->error, $separator), 422);
		}
		inboxAjaxReply($server->get());
		// no break

	case 'reactivity':
		// Mode and rhythm, global; given back as saved (the idle interval follows the active one)
		$react = new InboxReactivity($db);
		$saved = $react->save((string) inboxAjaxParam('mode', 'aZ09'), (int) inboxAjaxParam('interval', 'int'), (int) inboxAjaxParam('idle', 'int'));
		if ($saved === null) {
			inboxAjaxError($react->error, 422);
		}
		inboxAjaxReply($saved + array('push_ready' => (bool) InboxPush::ready()));
		// no break

	case 'push':
		// Driver and its settings; the secrets raw (encrypted by the server, never given back), empty = kept
		$react = new InboxReactivity($db);
		$values = inboxAjaxParam('values', 'none');
		$values = is_string($values) ? json_decode($values, true) : $values;
		if (!$react->savePush((string) inboxAjaxParam('driver', 'aZ09'), is_array($values) ? array_map('strval', array_filter($values, 'is_scalar')) : array())) {
			inboxAjaxError($react->error, 422);
		}
		inboxAjaxReply(array('push' => $react->pushData(), 'push_ready' => (bool) InboxPush::ready()));
		// no break

	case 'pushtest':
		$react = new InboxReactivity($db);
		$test = $react->testPush();
		inboxAjaxReply(array('test' => $test, 'push' => $react->pushData(), 'push_ready' => (bool) InboxPush::ready()));
		// no break

	case 'job':
		if (!$server->setJobActive((int) inboxAjaxParam('id', 'int'), (int) inboxAjaxParam('active', 'int') === 1)) {
			inboxAjaxNotFound();
		}
		inboxAjaxReply(array('cron' => $server->cronJobs()));
		// no break

	default:
		inboxAjaxError('Unknown action', 400);
}
