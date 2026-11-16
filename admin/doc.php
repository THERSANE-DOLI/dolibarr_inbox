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
 * \file    inbox/admin/doc.php
 * \ingroup inbox
 * \brief   Documentation of the module (1.113.0): the pages of doc/ (administrators, then developers of modules),
 *          by theme, shown as they are in the repository
 */

// Load Dolibarr environment
$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once '../lib/inbox.lib.php';
require_once '../class/inboxdocumentation.class.php';

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array('admin', 'inbox@inbox'));

if (!$user->admin) {
	accessforbidden();
}

// The key asked is only looked for in the index of the pages; out of it, the summary
$doc = new InboxDocumentation();
$key = GETPOST('page', 'aZ09');
if ($key !== '' && $doc->page($key) === null) {
	$key = '';
}
$self = $_SERVER['PHP_SELF'];


/*
 * View
 */

$title = 'InboxSetup';
llxHeader('', $langs->trans('InboxDocTab'), '', '', 0, 0, '', array('/inbox/css/inbox-doc.css'), '', 'mod-inbox page-admin-doc');

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.img_picto($langs->trans("BackToModuleList"), 'back', 'class="pictofixedwidth"')
	.'<span class="hideonsmartphone">'.$langs->trans("BackToModuleList").'</span></a>';
print load_fiche_titre($langs->trans($title), $linkback, 'title_setup');

$head = inboxAdminPrepareHead();
print dol_get_fiche_head($head, 'doc', $langs->trans($title), -1, 'inbox@inbox');

dol_include_once('/inbox/core/modules/modInbox.class.php');
$moduleVersion = (new modInbox($db))->version;
$pageUrl = function ($pageKey) use ($self) {
	return dol_escape_htmltag($self.'?page='.urlencode($pageKey));
};

print '<div class="inbox-doc">';

// Reading first (on a phone the summary comes after it, a link at the top leads to it)
print '<div class="inbox-doc__main">';
print '<a class="inbox-doc__tolist" href="#inbox-doc-toc">'.img_picto('', 'fa-list', 'class="pictofixedwidth"').$langs->trans('InboxDocHome').'</a>';
if ($key === '') {
	print '<p>'.$langs->trans('InboxDocIntro').'</p>';
	print '<h2 class="inbox-doc__h2">'.$langs->trans('InboxDocRecent').'</h2><ul class="inbox-doc__recent">';
	foreach ($doc->recent(4) as $pageKey) {
		print '<li><a href="'.$pageUrl($pageKey).'">'.img_picto('', $doc->page($pageKey)['icon'], 'class="pictofixedwidth"').$langs->trans('InboxDocTitle_'.$pageKey).'</a>'
			.' <span class="inbox-doc__since">'.$langs->trans('InboxDocSince', dol_escape_htmltag($doc->page($pageKey)['version'])).'</span>'
			.'<div class="inbox-doc__desc">'.$langs->trans('InboxDocDesc_'.$pageKey).'</div></li>';
	}
	print '</ul>';
} else {
	$page = $doc->page($key);
	$meta = array();
	if ($page['version'] !== '') {
		$meta[] = $langs->trans('InboxDocSince', dol_escape_htmltag($page['version']));
	}
	$other = $doc->otherLang($key, $langs->defaultlang);
	if ($other !== '') {
		$meta[] = '<span class="badge badge-secondary" title="'.dol_escape_htmltag($langs->trans('InboxDocOtherLang')).'">'.strtoupper(substr($other, 0, 2)).'</span>';
	}
	if ($meta) {
		print '<div class="inbox-doc__meta">'.implode(' · ', $meta).'</div>';
	}
	$html = $doc->render($key, $langs, $self);
	print '<article class="inbox-doc__article">'.($html !== '' ? $html : '<p>'.$langs->trans('InboxDocMissing').'</p>').'</article>';
}
print '</div>';

// One summary, from the index
print '<nav class="inbox-doc__toc" id="inbox-doc-toc" aria-label="'.dol_escape_htmltag($langs->trans('InboxDocHome')).'">';
print '<a class="inbox-doc__item inbox-doc__home'.($key === '' ? ' is-current' : '').'" href="'.dol_escape_htmltag($self).'"'.($key === '' ? ' aria-current="page"' : '').'>'
	.img_picto('', 'fa-home', 'class="pictofixedwidth"').$langs->trans('InboxDocHome').'</a>';
foreach ($doc->menu as $groupKey => $group) {
	print '<div class="inbox-doc__group">'.img_picto('', $group['icon'], 'class="pictofixedwidth"').'<span>'.$langs->trans($groupKey).'</span></div><ul>';
	foreach ($group['pages'] as $pageKey => $page) {
		$current = $pageKey === $key;
		print '<li><a class="inbox-doc__item'.($current ? ' is-current' : '').'" href="'.$pageUrl($pageKey).'"'.($current ? ' aria-current="page"' : '')
			.' title="'.dol_escape_htmltag($langs->transnoentities('InboxDocDesc_'.$pageKey)).'">'.img_picto('', $page['icon'], 'class="pictofixedwidth"').'<span>'.$langs->trans('InboxDocTitle_'.$pageKey).'</span>'
			.($doc->isNew($pageKey, $moduleVersion) ? ' <span class="badge badge-info">'.$langs->trans('InboxDocNew').'</span>' : '').'</a></li>';
	}
	print '</ul>';
}
print '</nav>';

print '</div>';

print dol_get_fiche_end();

llxFooter();
$db->close();
