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
 * \file    inbox/admin/readlog.php
 * \ingroup inbox
 * \brief   Journal of the readings of mails by their Message-ID (lot 0, readings asked by other modules): admin only
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

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

require_once DOL_DOCUMENT_ROOT."/core/lib/admin.lib.php";
require_once '../lib/inbox.lib.php';

$langs->loadLangs(array("admin", "inbox@inbox"));

if (!$user->admin) {
	accessforbidden();
}

$search_mailbox = trim(GETPOST('search_mailbox', 'alphanohtml'));
$search_result = GETPOST('search_result', 'aZ09');
$search_origin = trim(GETPOST('search_origin', 'alphanohtml'));
$search_msgid = trim(GETPOST('search_msgid', 'alphanohtml'));
$limit = GETPOSTINT('limit') ? GETPOSTINT('limit') : $conf->liste_limit;
$page = GETPOSTISSET('pageplusone') ? (GETPOSTINT('pageplusone') - 1) : GETPOSTINT('page');
if ($page < 0 || GETPOST('button_search', 'alpha') || GETPOST('button_removefilter', 'alpha')) {
	$page = 0;
}
if (GETPOST('button_removefilter', 'alpha') || GETPOST('button_removefilter_x', 'alpha')) {
	$search_mailbox = $search_result = $search_origin = $search_msgid = '';
}
$offset = $limit * $page;
$results = array('found', 'notfound', 'forbidden', 'invalid', 'error');

$where = " WHERE l.entity = ".((int) $conf->entity);
if ($search_mailbox !== '') {
	$where .= natural_search('l.mailbox', $search_mailbox);
}
if (in_array($search_result, $results, true)) {
	$where .= " AND l.result = '".$db->escape($search_result)."'";
}
if ($search_origin !== '') {
	$where .= natural_search('l.origin', $search_origin);
}
if ($search_msgid !== '') {
	$where .= natural_search('l.message_id', $search_msgid);
}
$count = $db->getRow("SELECT COUNT(l.rowid) AS nb FROM ".$db->prefix()."inbox_readlog AS l".$where);
$nbtotal = is_object($count) ? (int) $count->nb : 0;
$sql = "SELECT l.rowid, l.datec, l.fk_user, l.origin, l.mailbox, l.message_id, l.result, l.folder, l.from_cache, l.error, u.login, u.firstname, u.lastname";
$sql .= " FROM ".$db->prefix()."inbox_readlog AS l LEFT JOIN ".$db->prefix()."user AS u ON u.rowid = l.fk_user";
$sql .= $where." ORDER BY l.datec DESC, l.rowid DESC".$db->plimit($limit, $offset);
$rows = $db->getRows($sql);
$rows = is_array($rows) ? $rows : array();

$param = '';
foreach (array('search_mailbox' => $search_mailbox, 'search_result' => $search_result, 'search_origin' => $search_origin, 'search_msgid' => $search_msgid) as $k => $v) {
	if ($v !== '') {
		$param .= '&'.$k.'='.urlencode($v);
	}
}
if ($limit != $conf->liste_limit) {
	$param .= '&limit='.((int) $limit);
}

$form = new Form($db);
llxHeader('', $langs->trans('InboxReadLog'), '', '', 0, 0, '', '', '', 'mod-inbox page-admin-readlog');
$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.img_picto($langs->trans("BackToModuleList"), 'back', 'class="pictofixedwidth"')
	.'<span class="hideonsmartphone">'.$langs->trans("BackToModuleList").'</span></a>';
print load_fiche_titre($langs->trans('InboxSetup'), $linkback, 'title_setup');
print dol_get_fiche_head(inboxAdminPrepareHead(), 'readlog', $langs->trans('InboxSetup'), -1, 'inbox@inbox');
print '<span class="opacitymedium">'.$langs->trans('InboxReadLogHelp', getDolGlobalInt('INBOX_LOG_DAYS', 365)).'</span><br><br>';

print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print_barre_liste($langs->trans('InboxReadLog'), $page, $_SERVER["PHP_SELF"], $param, '', '', '', count($rows), $nbtotal, '', 0, '', '', $limit, 0, 0, 1);
print '<div class="div-table-responsive"><table class="tagtable liste">';
print '<tr class="liste_titre_filter">';
print '<td class="liste_titre"></td><td class="liste_titre"></td>';
print '<td class="liste_titre"><input type="text" class="flat maxwidth100" name="search_origin" value="'.dol_escape_htmltag($search_origin).'"></td>';
print '<td class="liste_titre"><input type="text" class="flat maxwidth150" name="search_mailbox" value="'.dol_escape_htmltag($search_mailbox).'"></td>';
print '<td class="liste_titre"><input type="text" class="flat maxwidth150" name="search_msgid" value="'.dol_escape_htmltag($search_msgid).'"></td>';
$resultOptions = array();
foreach ($results as $r) {
	$resultOptions[$r] = $langs->trans('InboxResult_'.$r);
}
print '<td class="liste_titre">'.$form->selectarray('search_result', $resultOptions, $search_result, 1, 0, 0, '', 0, 0, 0, '', 'maxwidth125').'</td>';
print '<td class="liste_titre"></td>';
print '<td class="liste_titre right">'.$form->showFilterButtons().'</td>';
print '</tr>';
print '<tr class="liste_titre"><th>'.$langs->trans('Date').'</th><th>'.$langs->trans('User').'</th><th>'.$langs->trans('InboxOrigin').'</th><th>'.$langs->trans('InboxMailbox').'</th>';
print '<th>'.$langs->trans('InboxMessageId').'</th><th>'.$langs->trans('InboxResult').'</th><th>'.$langs->trans('InboxFolder').'</th><th></th></tr>';
if (!$rows) {
	print '<tr class="oddeven"><td colspan="8"><span class="opacitymedium">'.$langs->trans('NoRecordFound').'</span></td></tr>';
}
foreach ($rows as $row) {
	$name = trim($row->firstname.' '.$row->lastname);
	print '<tr class="oddeven">';
	print '<td class="nowraponall">'.dol_print_date($db->jdate($row->datec), 'dayhoursec').'</td>';
	print '<td>'.dol_escape_htmltag($name !== '' ? $name : ($row->login ? $row->login : $langs->trans('InboxSystem'))).'</td>';
	print '<td>'.dol_escape_htmltag((string) $row->origin).'</td>';
	print '<td>'.dol_escape_htmltag((string) $row->mailbox).'</td>';
	print '<td class="tdoverflowmax300" title="'.dol_escape_htmltag((string) $row->message_id).'">'.dol_escape_htmltag((string) $row->message_id).'</td>';
	print '<td>'.dol_escape_htmltag($langs->trans('InboxResult_'.$row->result)).($row->from_cache ? ' <span class="opacitymedium">('.$langs->trans('InboxFromCache').')</span>' : '')
		.($row->error ? '<br><span class="opacitymedium small">'.dol_escape_htmltag((string) $row->error).'</span>' : '').'</td>';
	print '<td>'.dol_escape_htmltag((string) $row->folder).'</td><td></td>';
	print '</tr>';
}
print '</table></div></form>';

print dol_get_fiche_end();
llxFooter();
$db->close();
