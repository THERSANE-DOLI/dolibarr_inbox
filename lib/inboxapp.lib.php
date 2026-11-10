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
 * The user may send from at least one mailbox (level send or manage)
 *
 * @param User $user Current user
 * @return bool
 */
function inboxCanSend(User $user)
{
	global $db;

	dol_include_once('/inbox/class/inboxaccess.class.php');
	$access = new InboxAccess($db);

	return count($access->getAccounts($user, InboxAccess::LEVEL_SEND)) > 0;
}

/**
 * Values of the JavaScript context of the application
 *
 * @param User $user User
 * @return array<string,string|int|bool>
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
		// Tooltips of the Dolibarr objects (parameters given by getNomUrl), their styles and the icons of Dolibarr loaded
		// at the first one
		'INBOX_TOOLTIP_URL' => DOL_URL_ROOT.'/core/ajax/ajaxtooltip.php',
		'INBOX_DOLTIP_CSS_URL' => dol_buildpath('/inbox/css/inbox-doltip.css', 1).'?v='.urlencode($moduleVersion).'-'.@filemtime(dirname(__DIR__).'/css/inbox-doltip.css'),
		'INBOX_FONTAWESOME_URL' => DOL_URL_ROOT.getDolGlobalString('MAIN_FONTAWESOME_DIRECTORY', '/theme/common/fontawesome-5').'/css/all.min.css?v='.urlencode((string) DOL_VERSION),
		'INBOX_ICONS_URL' => dol_buildpath('/inbox/img/inbox-icons.svg', 1).'?v='.urlencode($moduleVersion).'-'.@filemtime(dirname(__DIR__).'/img/inbox-icons.svg'),
		'INBOX_TOKEN' => currentToken(),
		// The JS context only takes strings, numbers and booleans: flat keys, JSON text for the settings
		'INBOX_USER_ID' => (int) $user->id,
		'INBOX_USER_NAME' => $user->getFullName($GLOBALS['langs']),
		'INBOX_RIGHT_WEBMAIL' => (bool) $user->hasRight('inbox', 'webmail', 'use'),
		'INBOX_RIGHT_ACCOUNT_ADMIN' => (bool) $user->hasRight('inbox', 'account', 'admin'),
		'INBOX_RIGHT_TRUSTED_DOMAIN' => (bool) $user->hasRight('inbox', 'trusteddomain', 'write'),
		// Settings > Server (1.54.0): admins of Dolibarr only
		'INBOX_RIGHT_DOLIBARR_ADMIN' => !empty($user->admin),
		// Lot W6: the user may write (an active mailbox with the level « send » at least)
		'INBOX_CAN_SEND' => inboxCanSend($user),
		'INBOX_PARAMS_JSON' => (string) json_encode($params->getAll($user)),
		'INBOX_ACCENT' => getDolGlobalString('INBOX_ACCENT', '#8a2b6e'),
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

/**
 * Look of the setup for each theme: the accent (INBOX_ACCENT) as it is in light (darker if it would not read on white),
 * a lighter tone of it in dark (or INBOX_ACCENT_DARK), the colour of the text written on it, and the greys: pure, or
 * tinted with the hue of the accent (INBOX_GREYS = accent). Same computation in js/inbox-settings.js (lookTokens) for
 * the live preview. The high contrast theme keeps its own colours.
 *
 * @param string $hex     Accent #rrggbb
 * @param string $darkHex Accent of the dark theme, '' = computed
 * @param string $greys   neutral or accent
 * @return array{light:array<string,string>,dark:array<string,string>,auto:string,darkContrast:float} Tokens (without --ibx-)
 */
function inboxLookTokens($hex, $darkHex = '', $greys = 'neutral')
{
	$rgb = function ($h) {
		$h = ltrim((string) $h, '#');
		return array(hexdec(substr($h, 0, 2)), hexdec(substr($h, 2, 2)), hexdec(substr($h, 4, 2)));
	};
	$lum = function ($c) {
		$l = array_map(function ($v) {
			$v /= 255;
			return $v <= 0.03928 ? $v / 12.92 : pow(($v + 0.055) / 1.055, 2.4);
		}, $c);
		return 0.2126 * $l[0] + 0.7152 * $l[1] + 0.0722 * $l[2];
	};
	$contrast = function ($a, $b) use ($lum) {
		$x = $lum($a);
		$y = $lum($b);
		return (max($x, $y) + 0.05) / (min($x, $y) + 0.05);
	};
	$mix = function ($a, $b, $t) {
		return array((int) round($a[0] + ($b[0] - $a[0]) * $t), (int) round($a[1] + ($b[1] - $a[1]) * $t), (int) round($a[2] + ($b[2] - $a[2]) * $t));
	};
	$hexOf = function ($c) {
		return sprintf('#%02x%02x%02x', $c[0], $c[1], $c[2]);
	};
	// The same hue and saturation, another lightness (a mix with white would make it dull)
	$toHsl = function ($c) {
		list($r, $g, $b) = array($c[0] / 255, $c[1] / 255, $c[2] / 255);
		$max = max($r, $g, $b);
		$min = min($r, $g, $b);
		$l = ($max + $min) / 2;
		$h = 0;
		$s = 0;
		if ($max !== $min) {
			$dd = $max - $min;
			$s = $l > 0.5 ? $dd / (2 - $max - $min) : $dd / ($max + $min);
			$h = $max === $r ? ($g - $b) / $dd + ($g < $b ? 6 : 0) : ($max === $g ? ($b - $r) / $dd + 2 : ($r - $g) / $dd + 4);
			$h /= 6;
		}
		return array($h, $s, $l);
	};
	$fromHsl = function ($h, $s, $l) {
		$f = function ($p, $q, $t) {
			$t = $t < 0 ? $t + 1 : ($t > 1 ? $t - 1 : $t);
			return $t < 1 / 6 ? $p + ($q - $p) * 6 * $t : ($t < 1 / 2 ? $q : ($t < 2 / 3 ? $p + ($q - $p) * (2 / 3 - $t) * 6 : $p));
		};
		$q = $l < 0.5 ? $l * (1 + $s) : $l + $s - $l * $s;
		$p = 2 * $l - $q;
		return array((int) round($f($p, $q, $h + 1 / 3) * 255), (int) round($f($p, $q, $h) * 255), (int) round($f($p, $q, $h - 1 / 3) * 255));
	};
	$white = array(255, 255, 255);
	$black = array(0, 0, 0);
	$base = $rgb(preg_match('/^#[0-9a-f]{6}$/i', (string) $hex) ? $hex : '#8a2b6e');
	$hsl = $toHsl($base);
	// Light theme: darker until 4.5:1 on white (a very light colour chosen stays readable)
	$light = $base;
	for ($l = $hsl[2]; $contrast($light, $white) < 4.5 && $l > 0; $l -= 0.02) {
		$light = $fromHsl($hsl[0], $hsl[1], max(0, $l));
	}
	// Greys: pure, or the hue of the accent with a very low saturation (6 % light, 10 % dark)
	$greyLight = array('bg-pane' => '#f7f7f8', 'bg-sunken' => '#efeff1', 'bg-hover' => '#e9e9ec', 'border' => '#dddde1', 'border-strong' => '#c1c1c7', 'text' => '#18181b', 'text-muted' => '#53535a', 'text-faint' => '#86868e');
	$greyDark = array('bg' => '#18181b', 'bg-pane' => '#1f1f23', 'bg-sunken' => '#131316', 'bg-hover' => '#2a2a2f', 'border' => '#34343a', 'border-strong' => '#48484f', 'text' => '#e7e7ea', 'text-muted' => '#a5a5ad', 'text-faint' => '#76767e');
	if ($greys === 'accent') {
		foreach (array(array(&$greyLight, 0.06), array(&$greyDark, 0.10)) as $g) {
			foreach ($g[0] as $k => $v) {
				$g[0][$k] = $hexOf($fromHsl($hsl[0], $g[1], $toHsl($rgb($v))[2]));
			}
		}
		unset($g);
	}
	// Dark theme: lighter until 6:1 on its background
	$bg = $rgb($greyDark['bg']);
	$dark = $base;
	for ($l = $hsl[2]; $contrast($dark, $bg) < 6 && $l < 1; $l += 0.02) {
		$dark = $fromHsl($hsl[0], $hsl[1], min(1, $l));
	}
	$auto = $dark;
	// A colour chosen for the dark theme replaces the computed one (the setup warns when it reads badly)
	if (preg_match('/^#[0-9a-f]{6}$/i', (string) $darkHex)) {
		$dark = $rgb($darkHex);
	}
	// Text on the accent: white, or a very dark tone of the colour, whichever reads better
	$on = function ($c) use ($contrast, $mix, $white, $black, $hexOf) {
		$deep = $mix($c, $black, 0.8);
		return $hexOf($contrast($c, $white) >= $contrast($c, $deep) ? $white : $deep);
	};

	return array('light' => array('accent' => $hexOf($light), 'on-accent' => $on($light)) + $greyLight,
		'dark' => array('accent' => $hexOf($dark), 'on-accent' => $on($dark)) + $greyDark, 'auto' => $hexOf($auto), 'darkContrast' => $contrast($dark, $bg));
}

/**
 * CSS of the look of the setup (inboxLookTokens), for the page
 *
 * @param string $hex     Accent #rrggbb
 * @param string $darkHex Accent of the dark theme, '' = computed
 * @param string $greys   neutral or accent
 * @return string CSS
 */
function inboxAccentCss($hex, $darkHex = '', $greys = 'neutral')
{
	$t = inboxLookTokens($hex, $darkHex, $greys);
	$css = function ($set) {
		$out = '';
		foreach ($set as $k => $v) {
			$out .= '--ibx-'.$k.':'.$v.';';
		}
		return $out;
	};
	$d = $css($t['dark']);

	return ':root:not([data-ibx-theme="contrast"]){'.$css($t['light']).'}'
		.'@media (prefers-color-scheme: dark){:root:not([data-ibx-theme="light"]):not([data-ibx-theme="contrast"]){'.$d.'}}'
		.':root[data-ibx-theme="dark"]{'.$d.'}';
}

/**
 * Files of the other modules for the application, declared in their descriptor like the parts 'css' and 'js' of
 * Dolibarr: module_parts 'inboxcss' and 'inboxjs' => array('/mymodule/css/inbox.css', ...). Own keys: the files 'css'
 * and 'js' of the modules are written for the pages of Dolibarr (theme, jQuery), never loaded here.
 *
 * @param string $part 'inboxcss' or 'inboxjs'
 * @return int         Files printed
 */
function inboxPrintModuleFiles($part)
{
	global $conf;

	$n = 0;
	$parts = isset($conf->modules_parts[$part]) ? (array) $conf->modules_parts[$part] : array();
	foreach ($parts as $module => $files) {
		foreach ((array) $files as $file) {
			$file = (string) $file;
			// A path inside Dolibarr only, as the parts of the core (relative url starting with /)
			if ($file === '' || $file[0] !== '/' || preg_match('#^//|\.\.|[\s<>"\']#', $file)) {
				dol_syslog('inbox: module '.$module.' declared a wrong '.$part.' path: '.$file, LOG_WARNING);
				continue;
			}
			$url = dol_buildpath($file, 1);
			$path = dol_buildpath(preg_replace('/\?.*$/', '', $file), 0);
			// Address changing with the file (no stale file after an update of the module)
			$url .= (strpos($url, '?') === false ? '?' : '&').'v='.(is_file($path) ? filemtime($path) : urlencode((string) DOL_VERSION));
			print '<!-- '.dol_escape_htmltag($part.' of module '.$module).' -->'."\n";
			if ($part === 'inboxjs') {
				print '<script nonce="'.getNonce().'" src="'.dol_escape_htmltag($url).'" defer></script>'."\n";
			} else {
				print '<link rel="stylesheet" href="'.dol_escape_htmltag($url).'">'."\n";
			}
			$n++;
		}
	}

	return $n;
}
