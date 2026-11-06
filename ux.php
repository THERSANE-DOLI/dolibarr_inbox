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
 * \file    inbox/ux.php
 * \ingroup inbox
 * \brief   Catalogue of the components of the inbox application (admins of the mailboxes, or of Dolibarr)
 */

if (!defined('NOREQUIREMENU')) {
	define('NOREQUIREMENU', '1');
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
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array('inbox@inbox'));
if (!isModEnabled('inbox') || !empty($user->socid) || (!$user->admin && !$user->hasRight('inbox', 'account', 'admin'))) {
	accessforbidden();
}

$asset = function ($path) {
	$file = __DIR__.$path;
	return dol_buildpath('/inbox'.$path, 1).'?v='.(is_file($file) ? filemtime($file) : '');
};
$icons = (string) file_get_contents(__DIR__.'/dev/icons.json');
$html = (string) file_get_contents(__DIR__.'/tpl/ux.html');
$html = strtr($html, array(
	'__ICONS__' => dol_escape_htmltag($asset('/img/inbox-icons.svg')),
	'__APP__' => dol_escape_htmltag(dol_buildpath('/inbox/index.php', 1).'#/settings'),
	'__BACK__' => dol_escape_htmltag($langs->trans('InboxAppName')),
));

top_httphead('text/html');
print '<!DOCTYPE html><html lang="fr" class="ibx-html"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
print '<meta name="robots" content="noindex,nofollow"><title>'.dol_escape_htmltag($langs->trans('InboxAppName')).' UI</title>';
print '<link rel="stylesheet" href="'.$asset('/css/inbox.css').'"><link rel="stylesheet" href="'.$asset('/css/ux.css').'"></head>';
print '<body data-icons="'.dol_escape_htmltag($asset('/img/inbox-icons.svg')).'">';
print $html;
print '<script type="application/json" id="ibx-doc-icons">'.str_replace('</', '<\/', $icons).'</script>';
print '<script nonce="'.getNonce().'" src="'.$asset('/js/ux.js').'"></script>';
print '</body></html>';
