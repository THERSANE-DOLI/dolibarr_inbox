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
 *          server, scheduled jobs)
 */

require __DIR__.'/inbox_ajax.inc.php';
require_once __DIR__.'/../class/inboxserver.class.php';

$action = (string) inboxAjaxParam('action', 'aZ09');
inboxAjaxStart(in_array($action, array('set', 'master', 'job'), true));
if (empty($user->admin)) {
	inboxAjaxNotFound();
}

$server = new InboxServer($db);
switch ($action) {
	case 'get':
		inboxAjaxReply($server->get() + array('cron' => $server->cronJobs()));
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

	case 'job':
		if (!$server->setJobActive((int) inboxAjaxParam('id', 'int'), (int) inboxAjaxParam('active', 'int') === 1)) {
			inboxAjaxNotFound();
		}
		inboxAjaxReply(array('cron' => $server->cronJobs()));
		// no break

	default:
		inboxAjaxError('Unknown action', 400);
}
