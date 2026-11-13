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
require_once __DIR__.'/../lib/inboxapp.lib.php';

$action = (string) inboxAjaxParam('action', 'aZ09');
inboxAjaxStart($action === 'set');
inboxAjaxNeedAdmin();

/**
 * Settings of this screen: constant => check. The other technical settings stay on the setup page of Dolibarr.
 * right: who may change it. manager: the right of this page (managers of the mailboxes); dolibarr_admin: an
 * administrator of Dolibarr only, written even though it is the default (a missing or unknown right is read as
 * dolibarr_admin): a setting added or changed without a right is never opened to more people than meant.
 * Optional: right_error (message of the refusal), clears (constants removed when it changes), clears_on_host (removed
 * when the host of its address changes).
 *
 * @return array<string,array{default:string,check:string|string[],right?:string,right_error?:string,clears?:string[],clears_on_host?:string[]}>
 */
function inboxGeneralSettings()
{
	return array(
		'INBOX_ACCENT' => array('right' => 'manager', 'default' => '#8a2b6e', 'check' => 'color'),
		// Accent of the dark theme: '' = computed from INBOX_ACCENT (lighter, same hue)
		'INBOX_ACCENT_DARK' => array('right' => 'manager', 'default' => '', 'check' => 'color_or_empty'),
		// Greys of the interface: pure, or tinted with the hue of the accent
		'INBOX_GREYS' => array('right' => 'manager', 'default' => 'neutral', 'check' => array('neutral', 'accent')),
		'INBOX_DEFAULT_READ_MODE' => array('right' => 'manager', 'default' => 'delay', 'check' => array('immediate', 'delay', 'manual')),
		'INBOX_DEFAULT_READ_DELAY' => array('right' => 'manager', 'default' => '3', 'check' => 'int:0:600'),
		'INBOX_SEND_DELAY' => array('right' => 'manager', 'default' => '10', 'check' => 'int:0:120'),
		'INBOX_ANTIVIRUS_MAX_MB' => array('right' => 'manager', 'default' => '25', 'check' => 'int:1:200'),
		// Journal of the actions on the mails (lot W4): months kept, 0 = kept without limit
		'INBOX_ACTION_LOG_MONTHS' => array('right' => 'manager', 'default' => '12', 'check' => 'int:0:120'),
		// Lot W5 1.15.0: receiving servers whose Authentication-Results are trusted (imitation, junk probably legitimate)
		'INBOX_AUTH_SERVERS' => array('right' => 'manager', 'default' => '', 'check' => 'hosts'),
		// Lot W6 1.16.0: size of the attachments of a mail, copy of Dolibarr (MAIN_MAIL_AUTOCOPY_TO) added or not
		'INBOX_SEND_MAX_MB' => array('right' => 'manager', 'default' => '20', 'check' => 'int:1:100'),
		'INBOX_SEND_AUTOCOPY' => array('right' => 'manager', 'default' => '0', 'check' => 'int:0:1'),
		// Pictures of the correspondents (1.5.0): sources looked for by the server
		'INBOX_AVATAR_DOLIBARR' => array('right' => 'manager', 'default' => '1', 'check' => 'int:0:1'),
		// Off by default (audit INBX-008): when on, the server asks the domain of the sender at the opening of a mail
		'INBOX_AVATAR_BIMI' => array('right' => 'manager', 'default' => '0', 'check' => 'int:0:1'),
		'INBOX_AVATAR_GRAVATAR' => array('right' => 'manager', 'default' => '0', 'check' => 'int:0:1'),
		// AI in the writing (1.27.0): off by default; the mail answered may go with a request (allowed by default)
		'INBOX_AI_ENABLED' => array('right' => 'manager', 'default' => '0', 'check' => 'int:0:1'),
		'INBOX_AI_WITH_MAIL' => array('right' => 'manager', 'default' => '1', 'check' => 'int:0:1'),
		// Editor of the writing (1.94.0): the one of inbox, or the CKEditor of Dolibarr driven by the toolbar of inbox or
		// with its own toolbar and menu (INBOX_CKEDITOR_NATIVE)
		'INBOX_EDITOR' => array('right' => 'manager', 'default' => 'tiptap', 'check' => array('tiptap', 'ckeditor')),
		'INBOX_CKEDITOR_NATIVE' => array('right' => 'manager', 'default' => '0', 'check' => 'int:0:1'),
		// Editor on a small screen (1.101.0, width of the phone layout, chosen when the writing opens): the one of inbox,
		// CKEditor with the toolbar of inbox, or the same as on a computer
		'INBOX_EDITOR_SMALL' => array('right' => 'manager', 'default' => 'tiptap', 'check' => array('tiptap', 'ckeditor', 'same')),
		// Voice typing (1.28.0): off by default; each user then agrees once per receiver of the voice
		'INBOX_DICTATION_ENABLED' => array('right' => 'manager', 'default' => '0', 'check' => 'int:0:1'),
		// Security (lot F1 1.78.0): remote pictures of a quote (the tracking ones always go), « always show the
		// pictures », unsubscribe, alert on bank details (A6)
		'INBOX_QUOTE_REMOTE_IMAGES' => array('right' => 'manager', 'default' => 'keep', 'check' => array('keep', 'reply', 'all')),
		'INBOX_IMAGES_ALWAYS' => array('right' => 'manager', 'default' => 'domain', 'check' => array('domain', 'sender', 'never')),
		'INBOX_UNSUBSCRIBE' => array('right' => 'manager', 'default' => 'oneclick', 'check' => array('oneclick', 'mail', 'off')),
		'INBOX_BANK_CHECK' => array('right' => 'manager', 'default' => '1', 'check' => 'int:0:1'),
		// Anti-spam (1.85.0): blocked senders, « probably legitimate » in the junk
		'INBOX_BLOCKED_SENDERS' => array('right' => 'manager', 'default' => '1', 'check' => 'int:0:1'),
		'INBOX_JUNK_LEGIT_CHECK' => array('right' => 'manager', 'default' => '1', 'check' => 'int:0:1'),
		// Verdict of the anti-spam filter of the server read in the headers (1.86.1)
		'INBOX_SPAM_SERVER_VERDICT' => array('right' => 'manager', 'default' => '1', 'check' => 'int:0:1'),
		// Anti-spam engine of inbox (1.88.0): Rspamd beside Dolibarr; its password in ajax/rspamd.php (never sent back)
		'INBOX_RSPAMD_ENABLED' => array('right' => 'manager', 'default' => '0', 'check' => 'int:0:1'),
		// The mails and the password of the controller go to these addresses: an administrator of Dolibarr only, as
		// the master user of the mail server (audit INBX-049); the password never goes to another server
		'INBOX_RSPAMD_URL' => array('default' => 'http://127.0.0.1:11333', 'check' => 'url', 'right' => 'dolibarr_admin', 'right_error' => 'InboxRspamdUrlAdminOnly',
			'clears' => array('INBOX_RSPAMD_LAST_ERROR')),
		'INBOX_RSPAMD_CONTROLLER_URL' => array('default' => 'http://127.0.0.1:11334', 'check' => 'url', 'right' => 'dolibarr_admin', 'right_error' => 'InboxRspamdUrlAdminOnly',
			'clears' => array('INBOX_RSPAMD_LAST_ERROR'), 'clears_on_host' => array('INBOX_RSPAMD_PASSWORD')),
		'INBOX_RSPAMD_SCOPE' => array('right' => 'manager', 'default' => 'noverdict', 'check' => array('noverdict', 'all')),
		'INBOX_RSPAMD_MOVE' => array('right' => 'manager', 'default' => '1', 'check' => 'int:0:1'),
		'INBOX_RSPAMD_LEARN' => array('right' => 'manager', 'default' => '1', 'check' => 'int:0:1'),
		// Lists of reputation asked by DNS to outside services (Spamhaus, SURBL...): off by default
		'INBOX_RSPAMD_DNSLISTS' => array('right' => 'manager', 'default' => '0', 'check' => 'int:0:1'),
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
	if ($check === 'color_or_empty') {
		return $value === '' || (bool) preg_match('/^#[0-9a-f]{6}$/i', $value);
	}
	if ($check === 'color') {
		return (bool) preg_match('/^#[0-9a-f]{6}$/i', $value);
	}
	if ($check === 'url') {
		dol_include_once('/inbox/class/inboxspamrspamd.class.php');
		return InboxSpamRspamd::validUrl($value);
	}
	if ($check === 'hosts') {
		return $value === '' || (bool) preg_match('/^[a-z0-9.-]{1,253}(\s*,\s*[a-z0-9.-]{1,253}){0,9}$/i', $value);
	}
	list(, $min, $max) = explode(':', $check);

	return preg_match('/^\d+$/', $value) && (int) $value >= (int) $min && (int) $value <= (int) $max;
}

/**
 * The user may change this setting (its right, beyond the one of the page)
 *
 * @param array<string,mixed> $def Definition of the setting
 * @return bool
 */
function inboxGeneralAllowed(array $def)
{
	global $user;

	// The right of this page is checked at its start (inboxAjaxNeedAdmin): « manager » asks nothing more; anything
	// else (dolibarr_admin, missing, mistyped) is for the administrators of Dolibarr
	return ($def['right'] ?? '') === 'manager' || !empty($user->admin);
}

$settings = inboxGeneralSettings();
switch ($action) {
	case 'get':
		$out = array();
		foreach ($settings as $name => $def) {
			$out[$name] = getDolGlobalString($name, $def['default']);
		}
		// The AI module of Dolibarr is there (the setting of inbox only matters then); the settings this user sees
		// without changing them
		$locked = array_keys(array_filter($settings, function ($def) {
			return !inboxGeneralAllowed($def);
		}));
		// The CKEditor of Dolibarr can be chosen (module FCKeditor on), the name of that module in the setup of Dolibarr
		$langs->load('admin');
		$editor = array('ckeditor' => inboxCkeditorAvailable() ? 1 : 0, 'module_label' => $langs->transnoentities('Module2000Name'));
		inboxAjaxReply(array('settings' => $out, 'ai_module' => isModEnabled('ai') ? 1 : 0, 'locked' => $locked, 'editor' => $editor));
		// no break

	case 'set':
		$name = (string) inboxAjaxParam('name', 'aZ09');
		$value = trim((string) inboxAjaxParam('value'));
		if (!isset($settings[$name]) || !inboxGeneralValid($settings[$name]['check'], $value)) {
			inboxAjaxError($langs->transnoentities('InboxErrorUserParam'), 422);
		}
		// The rules of the setting (right, constants that go with a change) are in its definition
		$def = $settings[$name];
		$previous = getDolGlobalString($name, $def['default']);
		if ($value !== $previous) {
			if (!inboxGeneralAllowed($def)) {
				inboxAjaxError($langs->transnoentities(!empty($def['right_error']) ? $def['right_error'] : 'InboxErrorSettingAdminOnly'), 403);
			}
			$host = function ($url) {
				return strtolower((string) parse_url($url, PHP_URL_HOST)).':'.((int) parse_url($url, PHP_URL_PORT));
			};
			$clears = (array) ($def['clears'] ?? array());
			if ($host($value) !== $host($previous)) {
				$clears = array_merge($clears, (array) ($def['clears_on_host'] ?? array()));
			}
			foreach ($clears as $const) {
				dolibarr_del_const($db, $const, $conf->entity);
			}
			if (($def['right'] ?? '') !== 'manager') {
				dol_syslog('inbox: '.$name.' changed by '.$user->login.' to '.$value, LOG_NOTICE);
			}
		}
		dolibarr_set_const($db, $name, $value, 'chaine', 0, '', $conf->entity);
		inboxAjaxReply(array('name' => $name, 'value' => $value));
		// no break

	default:
		inboxAjaxError('Unknown action', 400);
}
