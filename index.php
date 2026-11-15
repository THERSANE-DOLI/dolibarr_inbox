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
 * \file    inbox/index.php
 * \ingroup inbox
 * \brief   The inbox application: full page, own styles, no menu nor theme of Dolibarr, everything in ajax after
 *          this page (principles P19, P20). The screens are built by js/inbox.js from the address (#/...).
 */

if (!defined('NOREQUIREMENU')) {
	define('NOREQUIREMENU', '1');
}

// Load Dolibarr environment
$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;
$j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
	$i--;
	$j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1))."/main.inc.php")) {
	$res = @include substr($tmp, 0, ($i + 1))."/main.inc.php";
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php")) {
	$res = @include dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php";
}
if (!$res && file_exists("../main.inc.php")) {
	$res = @include "../main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var HookManager $hookmanager
 * @var Translate $langs
 * @var User $user
 */

require_once __DIR__.'/lib/inboxapp.lib.php';

$langs->loadLangs(array('inbox@inbox'));

if (!isModEnabled('inbox') || !$user->hasRight('inbox', 'webmail', 'use') || !empty($user->socid)) {
	accessforbidden();
}

$vars = inboxAppContextVars($user);
$params = json_decode($vars['INBOX_PARAMS_JSON'], true);
$version = urlencode((string) $vars['INBOX_VERSION']);
// Address of a file changes with the version and with the file itself (no stale script after an update)
$asset = function ($path) use ($version) {
	$file = __DIR__.$path;
	return dol_buildpath('/inbox'.$path, 1).'?v='.$version.(is_file($file) ? '-'.filemtime($file) : '');
};
// Editor of the writing (lot W6): loaded only when a writing opens
$vars['INBOX_EDITOR_URL'] = $asset('/js/vendor/tiptap/tiptap.min.js');
// Or the CKEditor of Dolibarr (1.94.0, Settings > General)
$vars = array_merge($vars, inboxEditorContextVars());
// QR code reader when the browser has none (import of the mailboxes, 1.24.0): loaded at the first image
$vars['INBOX_JSQR_URL'] = $asset('/js/vendor/jsqr/jsQR.js');
$title = $langs->trans('InboxAppName').($vars['INBOX_COMPANY'] !== '' ? ' '.$vars['INBOX_COMPANY'] : '');
$theme = in_array($params['theme'], array('light', 'dark', 'contrast'), true) ? $params['theme'] : '';
$accent = preg_match('/^#[0-9a-f]{6}$/i', (string) $vars['INBOX_ACCENT']) ? $vars['INBOX_ACCENT'] : '#8a2b6e';

top_httphead('text/html');
print '<!DOCTYPE html>'."\n";
print '<html lang="'.dol_escape_htmltag(substr($langs->getDefaultLang(), 0, 2)).'" class="ibx-html ibx-html--app"'.($theme !== '' ? ' data-ibx-theme="'.$theme.'"' : '')
	.' data-ibx-density="'.dol_escape_htmltag($params['density']).'" style="font-size:'.((int) $params['text_size']).'%">'."\n";
print '<head>'."\n";
print '<meta charset="utf-8">'."\n";
print '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">'."\n";
print '<meta name="robots" content="noindex,nofollow">'."\n";
print '<meta name="theme-color" content="'.$accent.'">'."\n";
print '<title>'.dol_escape_htmltag($title).'</title>'."\n";
print '<link rel="manifest" href="'.dol_buildpath('/inbox/manifest.json.php', 1).'">'."\n";
print '<link rel="icon" type="image/svg+xml" href="'.dol_buildpath('/inbox/img/favicon.svg', 1).'">'."\n";
print '<link rel="apple-touch-icon" href="'.dol_buildpath('/inbox/img/app-192.png', 1).'">'."\n";
// Searchable lists: tool of the JavaScript context, written for the core (the copy of inbox only when the core has none)
print '<link rel="stylesheet" href="'.$asset('/css/dolibarr-combo.css').'">'."\n";
print '<link rel="stylesheet" href="'.$asset('/css/inbox.css').'">'."\n";
print '<style nonce="'.getNonce().'" id="ibx-accent-css">'.inboxAccentCss($accent, getDolGlobalString('INBOX_ACCENT_DARK'), getDolGlobalString('INBOX_GREYS', 'neutral')).'</style>'."\n";
// Styles of the other modules, after the ones of the application (module_parts 'inboxcss'), then their dynamic header
// (hook addInboxHtmlHeader, context inbox): see README « Extend the application »
inboxPrintModuleFiles('inboxcss');
$hookmanager->initHooks(array('inbox'));
$parameters = array();
$reshook = $hookmanager->executeHooks('addInboxHtmlHeader', $parameters);
if ($reshook >= 0 && !empty($hookmanager->resPrint)) {
	print $hookmanager->resPrint."\n";
}
if (!inboxPrintJsContext($vars)) {
	print '</head><body class="ibx-doc"><p class="ibx-notice ibx-notice--error">'.dol_escape_htmltag($langs->trans('InboxNeedBackportDolContext')).'</p></body></html>';
	exit;
}
print '<script nonce="'.getNonce().'" src="'.$asset('/js/dolibarr-combo.js').'" defer></script>'."\n";
print '<script nonce="'.getNonce().'" src="'.$asset('/js/inbox.js').'" defer></script>'."\n";
// HTML of a mail made safe for the page (lot S1): DOMPurify, then the tool of inbox that uses it
// TODO core: when inbox moves to the core, DOMPurify is loaded by the core from htdocs/public/includes/dompurify/
print '<script nonce="'.getNonce().'" src="'.$asset('/js/vendor/dompurify/purify.min.js').'" defer></script>'."\n";
print '<script nonce="'.getNonce().'" src="'.$asset('/js/inbox-safe.js').'" defer></script>'."\n";
print '<script nonce="'.getNonce().'" src="'.$asset('/js/inbox-colors.js').'" defer></script>'."\n";
print '<script nonce="'.getNonce().'" src="'.$asset('/js/inbox-keys.js').'" defer></script>'."\n";
print '<script nonce="'.getNonce().'" src="'.$asset('/js/inbox-mail.js').'" defer></script>'."\n";
print '<script nonce="'.getNonce().'" src="'.$asset('/js/inbox-agenda.js').'" defer></script>'."\n";
print '<script nonce="'.getNonce().'" src="'.$asset('/js/inbox-lead.js').'" defer></script>'."\n";
print '<script nonce="'.getNonce().'" src="'.$asset('/js/inbox-settings.js').'" defer></script>'."\n";
print '<script nonce="'.getNonce().'" src="'.$asset('/js/inbox-notify.js').'" defer></script>'."\n";
print '<script nonce="'.getNonce().'" src="'.$asset('/js/inbox-editor.js').'" defer></script>'."\n";
print '<script nonce="'.getNonce().'" src="'.$asset('/js/inbox-dictation.js').'" defer></script>'."\n";
print '<script nonce="'.getNonce().'" src="'.$asset('/js/inbox-compose.js').'" defer></script>'."\n";
print '<script nonce="'.getNonce().'" src="'.$asset('/js/inbox-invite.js').'" defer></script>'."\n";
print '<script nonce="'.getNonce().'" src="'.$asset('/js/inbox-dmarc.js').'" defer></script>'."\n";
print '<script nonce="'.getNonce().'" src="'.$asset('/js/inbox-rules.js').'" defer></script>'."\n";
print '<script nonce="'.getNonce().'" src="'.$asset('/js/inbox-import-qr.js').'" defer></script>'."\n";
// Scripts of the other modules after the ones of the application (Dolibarr.tools.inbox ready for them)
inboxPrintModuleFiles('inboxjs');
print '</head>'."\n";
print '<body class="ibx-body">'."\n";
// Empty shell: the rail and the spaces are built by inbox.js (nothing works without JavaScript, said plainly)
print '<div class="ibx-app" id="ibx-app" data-loading="1"><noscript><p class="ibx-notice ibx-notice--error">'.dol_escape_htmltag($langs->trans('InboxNeedJavascript')).'</p></noscript></div>'."\n";
print '<div class="ibx-toasts" id="ibx-toasts" role="status" aria-live="polite"></div>'."\n";
print '</body>'."\n";
print '</html>'."\n";

$db->close();
