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
 * \file    inbox/manifest.json.php
 * \ingroup inbox
 * \brief   Manifest of the installable inbox application, distinct from the one of Dolibarr (own id and scope)
 */

if (!defined('NOTOKENRENEWAL')) {
	define('NOTOKENRENEWAL', '1');
}
if (!defined('NOREQUIREMENU')) {
	define('NOREQUIREMENU', '1');
}
if (!defined('NOREQUIREHTML')) {
	define('NOREQUIREHTML', '1');
}
if (!defined('NOREQUIREAJAX')) {
	define('NOREQUIREAJAX', '1');
}
if (!defined('NOLOGIN')) {
	// The manifest says nothing private: name, colours, icons. The browser asks for it without the session sometimes.
	define('NOLOGIN', '1');
}

$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
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
 * @var Translate $langs
 */

$langs->loadLangs(array('inbox@inbox'));

$company = getDolGlobalString('MAIN_INFO_SOCIETE_NOM');
$accent = getDolGlobalString('INBOX_ACCENT', '#1d6a8a');
if (!preg_match('/^#[0-9a-f]{6}$/i', $accent)) {
	$accent = '#1d6a8a';
}
$scope = dol_buildpath('/inbox/', 1);
$manifest = array(
	'id' => $scope,
	'name' => $langs->transnoentitiesnoconv('InboxAppName').($company !== '' ? ' '.$company : ''),
	'short_name' => $langs->transnoentitiesnoconv('InboxAppName'),
	'start_url' => dol_buildpath('/inbox/index.php', 1),
	'scope' => $scope,
	'display' => 'standalone',
	'display_override' => array('window-controls-overlay', 'standalone'),
	'theme_color' => $accent,
	'background_color' => '#ffffff',
	'launch_handler' => array('client_mode' => 'focus-existing'),
	'icons' => array(
		array('src' => dol_buildpath('/inbox/img/app-192.png', 1), 'sizes' => '192x192', 'type' => 'image/png'),
		array('src' => dol_buildpath('/inbox/img/app-512.png', 1), 'sizes' => '512x512', 'type' => 'image/png'),
		array('src' => dol_buildpath('/inbox/img/app-maskable-512.png', 1), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'),
	),
	'shortcuts' => array(
		array('name' => $langs->transnoentitiesnoconv('InboxSettings'), 'url' => dol_buildpath('/inbox/index.php', 1).'#/settings'),
	),
);
header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: max-age=3600');
print json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
