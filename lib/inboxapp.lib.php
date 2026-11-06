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
 * \file    inbox/lib/inboxapp.lib.php
 * \ingroup inbox
 * \brief   Page of the inbox application: own head, JavaScript context of Dolibarr, values given to the browser
 */

/**
 * Print the JavaScript context of Dolibarr without the theme nor jQuery: from the core in Dolibarr 24 and more, from
 * the module backportdolcontext in Dolibarr 23 (required there). Same files and same first values as the core.
 *
 * @param array<string,mixed> $vars Values of inbox added to the context (Dolibarr.getContextVar)
 * @return bool                     False when the context is not available (Dolibarr 23 without backportdolcontext)
 */
function inboxPrintJsContext(array $vars)
{
	global $langs;

	if (version_compare(DOL_VERSION, '24.0.0-alpha', '>=') || (int) DOL_VERSION >= 24) {
		$path = DOL_URL_ROOT.'/public/includes/dolibarr-js-context';
		$langUrl = dol_buildpath('public/langs/langs-tool-interface.php', 1);
	} elseif (isModEnabled('backportdolcontext')) {
		$path = dol_buildpath('backportdolcontext/backport/includes/dolibarr-js-context', 1);
		$langUrl = dol_buildpath('backportdolcontext/backport/langs/langs-tool-interface.php', 1);
	} else {
		return false;
	}
	// The interface of the translations answers with an HTTP cache of one day: a path suffix that changes with the
	// language file of inbox makes the browser ask it again after an update (the PHP file is the same)
	$langFile = dirname(__DIR__).'/langs/'.$langs->getDefaultLang().'/inbox.lang';
	$langUrl .= '/ibx'.(is_file($langFile) ? filemtime($langFile) : '0');
	$ext = 'v='.urlencode(DOL_VERSION);
	foreach (array('dolibarr-context.umd.js', 'dolibarr-tool.seteventmessage.js', 'dolibarr-tool.langs.js') as $file) {
		print '<script nonce="'.getNonce().'" src="'.$path.'/'.$file.'?'.$ext.'"></script>'."\n";
	}
	$context = array(
		'DOL_VERSION' => DOL_VERSION,
		'DOL_URL_ROOT' => DOL_URL_ROOT,
		'MAIN_LANG_DEFAULT' => $langs->getDefaultLang(),
		'DOL_LANG_INTERFACE_URL' => $langUrl,
	) + $vars;
	print '<script nonce="'.getNonce().'">Dolibarr.setContextVars('.json_encode($context, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT).');</script>'."\n";

	return true;
}

/**
 * Values of inbox given to the JavaScript context of the application page (never a secret)
 *
 * @param User $user Current user
 * @return array<string,mixed>
 */
function inboxAppContextVars(User $user)
{
	global $conf, $db;

	dol_include_once('/inbox/class/inboxuserparam.class.php');
	$params = new InboxUserParam($db);
	$moduleVersion = '';
	if (class_exists('modInbox') || (include_once dol_buildpath('/inbox/core/modules/modInbox.class.php', 0))) {
		$mod = new modInbox($db);
		$moduleVersion = $mod->version;
	}

	$colorblind = getDolUserString('MAIN_OPTIMIZEFORCOLORBLIND');
	$colorblind = in_array($colorblind, array('flashy', 'protanopia', 'deuteranopes', 'tritanopes'), true) ? $colorblind : '';
	if ($colorblind) {
		$GLOBALS['langs']->load('admin');
	}

	return array(
		'INBOX_VERSION' => $moduleVersion,
		'INBOX_URL' => dol_buildpath('/inbox/index.php', 1),
		'INBOX_AJAX_URL' => dol_buildpath('/inbox/ajax/', 1),
		'INBOX_ICONS_URL' => dol_buildpath('/inbox/img/inbox-icons.svg', 1).'?v='.urlencode($moduleVersion).'-'.@filemtime(dirname(__DIR__).'/img/inbox-icons.svg'),
		'INBOX_TOKEN' => currentToken(),
		// The JS context only takes strings, numbers and booleans: flat keys, JSON text for the settings
		'INBOX_USER_ID' => (int) $user->id,
		'INBOX_USER_NAME' => $user->getFullName($GLOBALS['langs']),
		'INBOX_RIGHT_WEBMAIL' => (bool) $user->hasRight('inbox', 'webmail', 'use'),
		'INBOX_RIGHT_ACCOUNT_ADMIN' => (bool) $user->hasRight('inbox', 'account', 'admin'),
		'INBOX_PARAMS_JSON' => (string) json_encode($params->getAll($user)),
		'INBOX_ACCENT' => getDolGlobalString('INBOX_ACCENT', '#1d6a8a'),
		// Colour blindness declared in the card of the user (tab Interface): flashy, protanopia, deuteranopes, tritanopes
		'INBOX_COLORBLIND' => $colorblind,
		// Labels of Dolibarr for this option and its value (My account cites them)
		'INBOX_COLORBLIND_OPTION' => $colorblind ? $GLOBALS['langs']->transnoentitiesnoconv('MAIN_OPTIMIZEFORCOLORBLIND') : '',
		'INBOX_COLORBLIND_VALUE' => $colorblind ? $GLOBALS['langs']->transnoentitiesnoconv(array('flashy' => 'Flashy', 'protanopia' => 'Protanopia', 'deuteranopes' => 'Deuteranopes', 'tritanopes' => 'Tritanopes')[$colorblind]) : '',
		'INBOX_DOLIBARR_URL' => DOL_URL_ROOT.'/',
		'INBOX_COMPANY' => getDolGlobalString('MAIN_INFO_SOCIETE_NOM'),
		// Changes with the language file: the page clears the translations cached by the browser when it changed
		'INBOX_LANG_STAMP' => $moduleVersion.'-'.@filemtime(dirname(__DIR__).'/langs/'.$GLOBALS['langs']->getDefaultLang().'/inbox.lang'),
	);
}
