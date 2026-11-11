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
 * \file    inbox/ajax/rspamd.php
 * \ingroup inbox
 * \brief   Anti-spam engine of inbox (1.88.0), administrators of the mailboxes: state and test of the connection,
 *          password of the controller (written only, never sent back), training of the start
 */

require __DIR__.'/inbox_ajax.inc.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
dol_include_once('/inbox/class/inboxspamcheck.class.php');

$action = (string) inboxAjaxParam('action', 'aZ09');
inboxAjaxStart(in_array($action, array('password', 'train'), true));
inboxAjaxNeedAdmin();
$spam = new InboxSpamCheck($db);

switch ($action) {
	case 'state':
		// ask=1: the engine asked now (button « Test the connection »)
		inboxAjaxReply($spam->state((int) inboxAjaxParam('ask', 'int') === 1));
		// no break

	case 'password':
		$value = (string) inboxAjaxParam('value', 'none');
		if (strlen($value) > 200) {
			inboxAjaxError($langs->transnoentities('InboxErrorUserParam'), 422);
		}
		dolibarr_set_const($db, 'INBOX_RSPAMD_PASSWORD', $value === '' ? '' : dolEncrypt($value), 'chaine', 0, '', $conf->entity);
		inboxAjaxReply($spam->state(false));
		// no break

	case 'train':
		if (!InboxSpamCheck::enabled()) {
			inboxAjaxError($langs->transnoentities('InboxRspamdOff'), 422);
		}
		$res = $spam->train($user);
		$reply = $spam->state(false);
		$reply['queued'] = $res;
		inboxAjaxReply($reply);
		// no break

	default:
		inboxAjaxError('Unknown action', 400);
}
