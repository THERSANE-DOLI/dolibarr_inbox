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
 * \file    inbox/ajax/general.php
 * \ingroup inbox
 * \brief   Settings > General of the application (admins of the mailboxes): defaults of the webmail
 */

require __DIR__.'/inbox_ajax.inc.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';

$action = (string) inboxAjaxParam('action', 'aZ09');
inboxAjaxStart($action === 'set');
inboxAjaxNeedAdmin();

/**
 * Settings of this screen: constant => check. The other technical settings stay on the setup page of Dolibarr.
 *
 * @return array<string,array{default:string,check:string|string[]}>
 */
function inboxGeneralSettings()
{
	return array(
		'INBOX_ACCENT' => array('default' => '#1d6a8a', 'check' => 'color'),
		'INBOX_DEFAULT_READ_MODE' => array('default' => 'delay', 'check' => array('immediate', 'delay', 'manual')),
		'INBOX_DEFAULT_READ_DELAY' => array('default' => '3', 'check' => 'int:0:600'),
		'INBOX_SEND_DELAY' => array('default' => '10', 'check' => 'int:0:120'),
		'INBOX_ANTIVIRUS_MAX_MB' => array('default' => '25', 'check' => 'int:1:200'),
		// Journal of the actions on the mails (lot W4): months kept, 0 = kept without limit
		'INBOX_ACTION_LOG_MONTHS' => array('default' => '12', 'check' => 'int:0:120'),
		// Lot W5 1.15.0: receiving servers whose Authentication-Results are trusted (imitation, junk probably legitimate)
		'INBOX_AUTH_SERVERS' => array('default' => '', 'check' => 'hosts'),
		// Pictures of the correspondents (1.5.0): sources looked for by the server
		'INBOX_AVATAR_DOLIBARR' => array('default' => '1', 'check' => 'int:0:1'),
		'INBOX_AVATAR_BIMI' => array('default' => '1', 'check' => 'int:0:1'),
		'INBOX_AVATAR_GRAVATAR' => array('default' => '0', 'check' => 'int:0:1'),
	);
}

/**
 * @param string|string[] $check Check
 * @param string          $value Value
 * @return bool
 */
function inboxGeneralValid($check, $value)
{
	if (is_array($check)) {
		return in_array($value, $check, true);
	}
	if ($check === 'color') {
		return (bool) preg_match('/^#[0-9a-f]{6}$/i', $value);
	}
	if ($check === 'hosts') {
		return $value === '' || (bool) preg_match('/^[a-z0-9.-]{1,253}(\s*,\s*[a-z0-9.-]{1,253}){0,9}$/i', $value);
	}
	list(, $min, $max) = explode(':', $check);

	return preg_match('/^\d+$/', $value) && (int) $value >= (int) $min && (int) $value <= (int) $max;
}

$settings = inboxGeneralSettings();
switch ($action) {
	case 'get':
		$out = array();
		foreach ($settings as $name => $def) {
			$out[$name] = getDolGlobalString($name, $def['default']);
		}
		inboxAjaxReply(array('settings' => $out));
		// no break

	case 'set':
		$name = (string) inboxAjaxParam('name', 'aZ09');
		$value = trim((string) inboxAjaxParam('value'));
		if (!isset($settings[$name]) || !inboxGeneralValid($settings[$name]['check'], $value)) {
			inboxAjaxError($langs->transnoentities('InboxErrorUserParam'), 422);
		}
		dolibarr_set_const($db, $name, $value, 'chaine', 0, '', $conf->entity);
		inboxAjaxReply(array('name' => $name, 'value' => $value));
		// no break

	default:
		inboxAjaxError('Unknown action', 400);
}
