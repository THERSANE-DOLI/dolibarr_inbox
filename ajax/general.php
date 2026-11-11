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
		'INBOX_ACCENT' => array('default' => '#8a2b6e', 'check' => 'color'),
		// Accent of the dark theme: '' = computed from INBOX_ACCENT (lighter, same hue)
		'INBOX_ACCENT_DARK' => array('default' => '', 'check' => 'color_or_empty'),
		// Greys of the interface: pure, or tinted with the hue of the accent
		'INBOX_GREYS' => array('default' => 'neutral', 'check' => array('neutral', 'accent')),
		'INBOX_DEFAULT_READ_MODE' => array('default' => 'delay', 'check' => array('immediate', 'delay', 'manual')),
		'INBOX_DEFAULT_READ_DELAY' => array('default' => '3', 'check' => 'int:0:600'),
		'INBOX_SEND_DELAY' => array('default' => '10', 'check' => 'int:0:120'),
		'INBOX_ANTIVIRUS_MAX_MB' => array('default' => '25', 'check' => 'int:1:200'),
		// Journal of the actions on the mails (lot W4): months kept, 0 = kept without limit
		'INBOX_ACTION_LOG_MONTHS' => array('default' => '12', 'check' => 'int:0:120'),
		// Lot W5 1.15.0: receiving servers whose Authentication-Results are trusted (imitation, junk probably legitimate)
		'INBOX_AUTH_SERVERS' => array('default' => '', 'check' => 'hosts'),
		// Lot W6 1.16.0: size of the attachments of a mail, copy of Dolibarr (MAIN_MAIL_AUTOCOPY_TO) added or not
		'INBOX_SEND_MAX_MB' => array('default' => '20', 'check' => 'int:1:100'),
		'INBOX_SEND_AUTOCOPY' => array('default' => '0', 'check' => 'int:0:1'),
		// Pictures of the correspondents (1.5.0): sources looked for by the server
		'INBOX_AVATAR_DOLIBARR' => array('default' => '1', 'check' => 'int:0:1'),
		// Off by default (audit INBX-008): when on, the server asks the domain of the sender at the opening of a mail
		'INBOX_AVATAR_BIMI' => array('default' => '0', 'check' => 'int:0:1'),
		'INBOX_AVATAR_GRAVATAR' => array('default' => '0', 'check' => 'int:0:1'),
		// AI in the writing (1.27.0): off by default; the mail answered may go with a request (allowed by default)
		'INBOX_AI_ENABLED' => array('default' => '0', 'check' => 'int:0:1'),
		'INBOX_AI_WITH_MAIL' => array('default' => '1', 'check' => 'int:0:1'),
		// Voice typing (1.28.0): off by default; each user then agrees once per receiver of the voice
		'INBOX_DICTATION_ENABLED' => array('default' => '0', 'check' => 'int:0:1'),
		// Security (lot F1 1.78.0): remote pictures of a quote (the tracking ones always go), « always show the
		// pictures », unsubscribe, alert on bank details (A6)
		'INBOX_QUOTE_REMOTE_IMAGES' => array('default' => 'keep', 'check' => array('keep', 'reply', 'all')),
		'INBOX_IMAGES_ALWAYS' => array('default' => 'domain', 'check' => array('domain', 'sender', 'never')),
		'INBOX_UNSUBSCRIBE' => array('default' => 'oneclick', 'check' => array('oneclick', 'mail', 'off')),
		'INBOX_BANK_CHECK' => array('default' => '1', 'check' => 'int:0:1'),
		// Anti-spam (1.85.0): blocked senders, « probably legitimate » in the junk
		'INBOX_BLOCKED_SENDERS' => array('default' => '1', 'check' => 'int:0:1'),
		'INBOX_JUNK_LEGIT_CHECK' => array('default' => '1', 'check' => 'int:0:1'),
		// Verdict of the anti-spam filter of the server read in the headers (1.86.1)
		'INBOX_SPAM_SERVER_VERDICT' => array('default' => '1', 'check' => 'int:0:1'),
		// Anti-spam engine of inbox (1.88.0): Rspamd beside Dolibarr; its password in ajax/rspamd.php (never sent back)
		'INBOX_RSPAMD_ENABLED' => array('default' => '0', 'check' => 'int:0:1'),
		'INBOX_RSPAMD_URL' => array('default' => 'http://127.0.0.1:11333', 'check' => 'url'),
		'INBOX_RSPAMD_CONTROLLER_URL' => array('default' => 'http://127.0.0.1:11334', 'check' => 'url'),
		'INBOX_RSPAMD_SCOPE' => array('default' => 'noverdict', 'check' => array('noverdict', 'all')),
		'INBOX_RSPAMD_MOVE' => array('default' => '1', 'check' => 'int:0:1'),
		'INBOX_RSPAMD_LEARN' => array('default' => '1', 'check' => 'int:0:1'),
		// Lists of reputation asked by DNS to outside services (Spamhaus, SURBL...): off by default
		'INBOX_RSPAMD_DNSLISTS' => array('default' => '0', 'check' => 'int:0:1'),
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

$settings = inboxGeneralSettings();
switch ($action) {
	case 'get':
		$out = array();
		foreach ($settings as $name => $def) {
			$out[$name] = getDolGlobalString($name, $def['default']);
		}
		// The AI module of Dolibarr is there (the setting of inbox only matters then)
		inboxAjaxReply(array('settings' => $out, 'ai_module' => isModEnabled('ai') ? 1 : 0));
		// no break

	case 'set':
		$name = (string) inboxAjaxParam('name', 'aZ09');
		$value = trim((string) inboxAjaxParam('value'));
		if (!isset($settings[$name]) || !inboxGeneralValid($settings[$name]['check'], $value)) {
			inboxAjaxError($langs->transnoentities('InboxErrorUserParam'), 422);
		}
		// The mails and the password of the controller go to the addresses of the anti-spam engine: chosen by an
		// administrator of Dolibarr, as the master user of the mail server (audit INBX-049)
		$previous = getDolGlobalString($name, $settings[$name]['default']);
		if (in_array($name, array('INBOX_RSPAMD_URL', 'INBOX_RSPAMD_CONTROLLER_URL'), true) && $value !== $previous) {
			if (empty($user->admin)) {
				inboxAjaxError($langs->transnoentities('InboxRspamdUrlAdminOnly'), 403);
			}
			$host = function ($url) {
				return strtolower((string) parse_url($url, PHP_URL_HOST)).':'.((int) parse_url($url, PHP_URL_PORT));
			};
			if ($name === 'INBOX_RSPAMD_CONTROLLER_URL' && $host($value) !== $host($previous)) {
				// Never sent to another server than the one it was given for
				dolibarr_del_const($db, 'INBOX_RSPAMD_PASSWORD', $conf->entity);
			}
			dolibarr_del_const($db, 'INBOX_RSPAMD_LAST_ERROR', $conf->entity);
			dol_syslog('inbox: '.$name.' changed by '.$user->login.' to '.$value, LOG_NOTICE);
		}
		dolibarr_set_const($db, $name, $value, 'chaine', 0, '', $conf->entity);
		inboxAjaxReply(array('name' => $name, 'value' => $value));
		// no break

	default:
		inboxAjaxError('Unknown action', 400);
}
