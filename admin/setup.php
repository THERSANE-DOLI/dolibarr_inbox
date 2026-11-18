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
 * \file    inbox/admin/setup.php
 * \ingroup inbox
 * \brief   Technical setup of Inbox (the settings are in the inbox application: Settings > General, Settings > Server
 *          since 1.54.0): cache emptied, tests of reading
 */

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
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

require_once DOL_DOCUMENT_ROOT."/core/lib/admin.lib.php";
require_once '../lib/inbox.lib.php';
require_once '../class/inbox.class.php';
require_once '../class/inboxevent.class.php';

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var HookManager $hookmanager
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array("admin", "inbox@inbox"));

$hookmanager->initHooks(array('inboxsetup', 'globalsetup'));

$action = GETPOST('action', 'aZ09');

if (!$user->admin) {
	accessforbidden();
}

$form = new Form($db);
$inbox = new Inbox($db);
$testMessage = null;
$testMailbox = GETPOST('test_mailbox', 'alphanohtml');
// Raw value: a Message-ID is often pasted with its angle brackets, that the HTML filters would remove as a tag. It is
// checked by Inbox::normalizeMessageId() and only shown escaped
$testMessageId = dol_trunc(trim(GETPOST('test_message_id', 'none')), 255, 'right', 'UTF-8', 1);


/*
 * Actions
 */

// Quicker refresh of the lists (1.121.0): a setting of the server (its load), for the administrators of Dolibarr
if (!class_exists('FormSetup')) {
	require_once DOL_DOCUMENT_ROOT.'/core/class/html.formsetup.class.php';
}
$reactSetup = new FormSetup($db);
$reactSetup->formHiddenInputs['action'] = 'update_reactivity';
$item = $reactSetup->newItem('INBOX_REACTIVITY')->setAsSelect(array(
	'standard' => $langs->trans('InboxReactivityStandard'),
	'fast' => $langs->trans('InboxReactivityFast'),
));
$item->nameText = $langs->trans('InboxReactivityMode');
$item->helpText = $langs->trans('InboxReactivityModeHelp');
$item->defaultFieldValue = 'standard';
$item->fieldValue = getDolGlobalString('INBOX_REACTIVITY') === 'fast' ? 'fast' : 'standard';
$item = $reactSetup->newItem('INBOX_REACTIVITY_INTERVAL')->setAsNumber(3, 60, 1);
$item->nameText = $langs->trans('InboxReactivityInterval');
$item->helpText = $langs->trans('InboxReactivityIntervalHelp');
$item->defaultFieldValue = '5';
$rhythm = InboxEvent::rhythm();
$item->fieldValue = (string) $rhythm['interval'];
$item = $reactSetup->newItem('INBOX_REACTIVITY_IDLE')->setAsNumber(3, 300, 1);
$item->nameText = $langs->trans('InboxReactivityIdle');
$item->helpText = $langs->trans('InboxReactivityIdleHelp');
$item->defaultFieldValue = '60';
$item->fieldValue = (string) $rhythm['idle'];
if ($action == 'update_reactivity' && $_SERVER['REQUEST_METHOD'] == 'POST') {
	// Global (entity 0): the load is the one of the server, whatever the entity; idle never quicker than active
	$mode = GETPOST('INBOX_REACTIVITY', 'aZ09') === 'fast' ? 'fast' : 'standard';
	$rhythm = InboxEvent::rhythm(GETPOSTINT('INBOX_REACTIVITY_INTERVAL'), GETPOSTINT('INBOX_REACTIVITY_IDLE'));
	dolibarr_set_const($db, 'INBOX_REACTIVITY', $mode, 'chaine', 0, '', 0);
	dolibarr_set_const($db, 'INBOX_REACTIVITY_INTERVAL', (string) $rhythm['interval'], 'chaine', 0, '', 0);
	dolibarr_set_const($db, 'INBOX_REACTIVITY_IDLE', (string) $rhythm['idle'], 'chaine', 0, '', 0);
	setEventMessages($langs->trans('SetupSaved'), null, 'mesgs');
	header('Location: '.$_SERVER['PHP_SELF'].'#reactivity');
	exit;
}

if ($action == 'confirm_clearcache' && GETPOST('confirm', 'alpha') == 'yes') {
	$removed = $inbox->clearCache();
	setEventMessages($langs->trans('InboxCacheCleared', $removed), null, 'mesgs');
	header("Location: ".$_SERVER["PHP_SELF"]);
	exit;
}

if ($action == 'testread' && $_SERVER['REQUEST_METHOD'] == 'POST') {
	$testMessage = $inbox->getMessage($testMailbox, $testMessageId, $user, 'setup');
	if ($testMessage === null) {
		setEventMessages($langs->trans('InboxTestReadFailed', $langs->transnoentities('InboxResult_'.$inbox->result), $inbox->error), null, 'errors');
	} else {
		setEventMessages($langs->trans($inbox->fromCache ? 'InboxTestReadFoundCache' : 'InboxTestReadFound', $inbox->folder), null, 'mesgs');
	}
}

if ($action == 'testfile' && $_SERVER['REQUEST_METHOD'] == 'POST') {
	if (empty($_FILES['test_file']['tmp_name']) || !is_uploaded_file($_FILES['test_file']['tmp_name'])) {
		setEventMessages($langs->trans('InboxTestFileMissing'), null, 'errors');
	} else {
		// Parsed in memory only: the file is not kept
		$testMessage = $inbox->getMessageFromFile($_FILES['test_file']['tmp_name']);
		if ($testMessage === null) {
			setEventMessages($langs->trans('InboxTestFileFailed', $inbox->error), null, 'errors');
		}
	}
}


/*
 * View
 */

$title = "InboxSetup";
llxHeader('', $langs->trans($title), '', '', 0, 0, array('/inbox/js/inbox-setup-reactivity.js'), '', '', 'mod-inbox page-admin');

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.img_picto($langs->trans("BackToModuleList"), 'back', 'class="pictofixedwidth"')
	.'<span class="hideonsmartphone">'.$langs->trans("BackToModuleList").'</span></a>';
print load_fiche_titre($langs->trans($title), $linkback, 'title_setup');

$head = inboxAdminPrepareHead();
print dol_get_fiche_head($head, 'settings', $langs->trans($title), -1, 'inbox@inbox');

// Update not finished (1.85.0): the module is activated again from the list of the modules, never from here
require_once dirname(__DIR__).'/lib/inboxapp.lib.php';
$pending = inboxUpdatePending();
if ($pending) {
	print info_admin($langs->trans('InboxUpdatePending', $pending['from'], $pending['to']).' '.$langs->trans('InboxUpdatePendingAdmin')
		.' <a href="'.DOL_URL_ROOT.'/admin/modules.php?search_keyword=inbox">'.$langs->trans('InboxUpdatePendingGo').'</a>', 0, 0, 'warning');
}
print '<span class="opacitymedium">'.$langs->trans('InboxSetupHelp').'</span><br>';
// The webmail settings live in the application, not here (analyse-lot-w1 §5)
print '<span class="opacitymedium">'.$langs->trans('InboxSetupAppHelp').'</span> <a href="'.dol_buildpath('/inbox/index.php', 1).'#/settings">'.img_picto('', 'fa-external-link-alt', 'class="pictofixedwidth"').$langs->trans('InboxOpenSettings').'</a><br><br>';
// Companion module for the attachments, suggested only while it is missing: link to the page of its last
// GitHub release (stable URL, the zip there keeps the name module_docstudio-x.y.z.zip asked by Dolibarr)
if (!isModEnabled('docstudio')) {
	print '<span class="opacitymedium">'.$langs->trans('InboxDocStudioHelp').'</span> <a href="https://github.com/THERSANE-DOLI/dolibarr_docstudio/releases/latest" target="_blank" rel="noopener noreferrer">'.img_picto('', 'fa-download', 'class="pictofixedwidth"').$langs->trans('InboxDocStudioDownload').'</a><br><br>';
}

// Mailboxes: those of inbox (managed in the inbox application), others given by modules through a hook
$mailboxes = $inbox->listMailboxes();
print load_fiche_titre($langs->trans('InboxMailboxes'), '', '');
print '<span class="opacitymedium">'.$langs->trans('InboxMailboxesHelp', count($mailboxes)).'</span><br>';
print '<br>';

// Keeping, timeouts, master user of the mail server: in the application, Settings > Server (1.54.0)
print '<div class="info">'.$langs->trans('InboxSetupServerMoved').' <a href="'.dol_buildpath('/inbox/index.php', 1).'#/settings/server">'.img_picto('', 'fa-external-link-alt', 'class="pictofixedwidth"').$langs->trans('InboxNavServer').'</a></div>';
print '<br>';

// Modules subscribed to the mailboxes (1.112.0), with FormSetup: the switch of the instance (off by default, saved at
// once by the ajax switch), then one read-only line per module that declares itself: what it asks, the mailboxes where
// it runs. Each mailbox has its managers allow each module, in its card
require_once '../class/inboxconsumer.class.php';
if (!class_exists('FormSetup')) {
	require_once DOL_DOCUMENT_ROOT.'/core/class/html.formsetup.class.php';
}
$formSetup = new FormSetup($db);
$item = $formSetup->newItem('INBOX_CONSUMERS_TITLE')->setAsTitle();
$item->nameText = $langs->trans('InboxConsumersSetupTitle');
$item = $formSetup->newItem('INBOX_CONSUMERS_ENABLED')->setAsYesNo();
$item->nameText = $langs->trans('InboxConsumersSetupSwitch');
$item->helpText = $langs->trans('InboxConsumersSetupHelp');
$consumerDecl = InboxConsumer::declared();
$consumerCounts = (new InboxConsumer($db))->counts();
if (!$consumerDecl && !$consumerCounts) {
	$item = $formSetup->newItem('INBOX_CONSUMERS_NONE');
	$item->nameText = $langs->trans('InboxConsumersSetupDeclared');
	$item->fieldOverride = '<span class="opacitymedium">'.$langs->trans('InboxConsumersNone').'</span>';
}
foreach (array_unique(array_merge(array_keys($consumerDecl), array_keys($consumerCounts))) as $consumerCode) {
	$d = isset($consumerDecl[$consumerCode]) ? $consumerDecl[$consumerCode] : null;
	$n = isset($consumerCounts[$consumerCode]) ? $consumerCounts[$consumerCode] : array('active' => 0, 'test' => 0, 'paused' => 0);
	$asks = array();
	foreach ($d ? $d['events'] : array() as $e) {
		$asks[] = $langs->trans('InboxConsumerEvent_'.str_replace('.', '_', $e));
	}
	foreach ($d ? $d['actions'] : array() as $a) {
		$asks[] = $langs->trans('InboxConsumerAction_'.$a);
	}
	// Read only: a line of information, never a constant
	$item = $formSetup->newItem('INBOX_CONSUMER_'.strtoupper($consumerCode));
	$item->nameText = dol_escape_htmltag($d ? $d['label'] : $consumerCode);
	$item->helpText = $d ? dol_escape_htmltag($d['purpose']) : $langs->trans('InboxConsumerGoneHelp');
	$item->fieldOverride = ($asks ? $langs->trans('InboxConsumersSetupAsks').' : '.dol_escape_htmltag(implode(', ', $asks)).'<br>' : '')
		.'<span class="opacitymedium">'.$langs->trans('InboxConsumersSetupCounts', $n['active'], $n['test'], $n['paused']).'</span>';
}
print '<span id="consumers"></span>';
print $formSetup->generateOutput();
print '<span class="opacitymedium">'.$langs->trans('InboxConsumersSetupWhere').'</span><br><br>';

// Quicker refresh of the lists (1.121.0): the setting, then the load it may bring on the server
print '<span id="reactivity"></span>';
print load_fiche_titre($langs->trans('InboxReactivitySetupTitle'), '', '');
print '<span class="opacitymedium">'.$langs->trans('InboxReactivitySetupHelp').'</span><br><br>';
if ($action == 'edit_reactivity') {
	print $reactSetup->generateOutput(true);
} else {
	print $reactSetup->generateOutput();
	print '<div class="tabsAction"><a class="butAction" href="'.$_SERVER['PHP_SELF'].'?action=edit_reactivity&token='.newToken().'#reactivity">'.$langs->trans('Modify').'</a></div>';
}
// Users who may call: those who reach a mailbox (owner, access of their own or of a group) and logged in lately
$p = $db->prefix();
$since = $db->idate(dol_now() - 7 * 86400);
$reach = "SELECT fk_user_owner AS u FROM ".$p."inbox_account WHERE status = 1 AND fk_user_owner > 0 AND entity = ".((int) $conf->entity)
	." UNION SELECT aa.fk_user FROM ".$p."inbox_account_access as aa INNER JOIN ".$p."inbox_account as a ON a.rowid = aa.fk_account WHERE a.status = 1 AND aa.fk_user > 0 AND a.entity = ".((int) $conf->entity)
	." UNION SELECT ug.fk_user FROM ".$p."usergroup_user as ug INNER JOIN ".$p."inbox_account_access as aa ON aa.fk_usergroup = ug.fk_usergroup INNER JOIN ".$p."inbox_account as a ON a.rowid = aa.fk_account WHERE a.status = 1 AND a.entity = ".((int) $conf->entity);
$row = $db->getRow("SELECT COUNT(DISTINCT u.rowid) AS n FROM ".$p."user as u INNER JOIN (".$reach.") as r ON r.u = u.rowid WHERE u.statut = 1 AND u.datelastlogin >= '".$since."'");
$activeUsers = is_object($row) ? (int) $row->n : 0;
// Load if they were all active, all idle, or in standard mode; js/inbox-setup-reactivity.js computes it again while
// the intervals are typed (same figures, from the data-* attributes)
$lightMs = 2;
$fullMs = 20;
$decimal = function ($v, $digits) use ($langs) {
	if ($v > 0 && $v < pow(10, -$digits)) {
		return '&lt; '.str_replace('.', $langs->transnoentitiesnoconv('SeparatorDecimal'), (string) pow(10, -$digits));
	}
	return str_replace('.', $langs->transnoentitiesnoconv('SeparatorDecimal'), number_format($v, $digits, '.', ''));
};
$loadRows = array(
	'active' => array('InboxReactivityAllActive', $activeUsers / $rhythm['interval'], $activeUsers / 60),
	'idle' => array('InboxReactivityAllIdle', $activeUsers / $rhythm['idle'], $activeUsers / 300),
	'standard' => array('InboxReactivityStandardRef', 0, $activeUsers / 20),
);
print '<div class="opacitymedium">'.$langs->trans('InboxReactivityLoadUsers', $activeUsers).'</div>';
print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent" id="inbox-reactivity-load" data-users="'.$activeUsers.'" data-light-ms="'.$lightMs.'" data-full-ms="'.$fullMs.'">';
print '<tr class="liste_titre"><th></th><th class="right">'.$langs->trans('InboxReactivityLightCalls').'</th><th class="right">'.$langs->trans('InboxReactivityFullCalls').'</th><th class="right">'.$langs->trans('InboxReactivityCpu').'</th></tr>';
foreach ($loadRows as $k => $r) {
	print '<tr class="oddeven" data-case="'.$k.'"><td>'.$langs->trans($r[0]).'</td>';
	print '<td class="right" data-light>'.($k === 'standard' ? '0' : $decimal($r[1], 1)).'</td>';
	print '<td class="right" data-full>'.$decimal($r[2], 1).'</td>';
	print '<td class="right" data-cpu>'.$decimal(($r[1] * $lightMs + $r[2] * $fullMs) / 1000, 2).'</td></tr>';
}
print '</table></div>';
print '<div class="opacitymedium">'.$langs->trans('InboxReactivityLoadNote').'</div><br>';

// Cache: what it holds, emptied on demand
if ($action == 'clearcache') {
	print $form->formconfirm($_SERVER["PHP_SELF"], $langs->trans('InboxClearCache'), $langs->trans('InboxClearCacheConfirm'), 'confirm_clearcache', '', 0, 1);
}
$stats = $inbox->cacheStats();
print load_fiche_titre($langs->trans('InboxCache'), '', '');
print '<span class="opacitymedium">'.$langs->trans('InboxCacheHelp', $stats['count'], dol_print_size($stats['size'], 1, 1)).'</span><br><br>';
print '<div class="center"><a class="button'.($stats['count'] ? '' : ' butActionRefused').'" href="'.($stats['count'] ? $_SERVER["PHP_SELF"].'?action=clearcache&token='.newToken() : '#').'">'
	.$langs->trans('InboxClearCache').'</a></div>';
print '<br>';

// Test: one mail read by its Message-ID
print load_fiche_titre($langs->trans('InboxTestRead'), '', '');
print '<span class="opacitymedium">'.$langs->trans('InboxTestReadHelp').'</span><br><br>';
print '<datalist id="inbox_mailboxes">';
foreach ($mailboxes as $mailbox) {
	print '<option value="'.dol_escape_htmltag($mailbox['email']).'">'.dol_escape_htmltag($mailbox['label']).'</option>';
}
print '</datalist>';
print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="testread">';
print '<table class="noborder centpercent">';
print '<tr class="oddeven"><td class="titlefield">'.$langs->trans('InboxMailbox').'</td><td><input type="email" class="minwidth300" name="test_mailbox" list="inbox_mailboxes" autocomplete="off" value="'.dol_escape_htmltag($testMailbox).'"></td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('InboxMessageId').'</td><td><input type="text" class="minwidth500" name="test_message_id" value="'.dol_escape_htmltag($testMessageId).'" placeholder="abc123@example.com"></td></tr>';
print '</table>';
print '<div class="center"><input type="submit" class="button" value="'.$langs->trans('InboxTestReadButton').'"></div>';
print '</form>';
print '<br>';

// Test: a .eml or .msg file parsed
print load_fiche_titre($langs->trans('InboxTestFile'), '', '');
print '<span class="opacitymedium">'.$langs->trans('InboxTestFileHelp').'</span><br><br>';
print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'" enctype="multipart/form-data">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="testfile">';
print '<input type="file" name="test_file" accept=".eml,.msg,message/rfc822,application/vnd.ms-outlook"> ';
print '<input type="submit" class="button" value="'.$langs->trans('InboxTestFileButton').'">';
print '</form>';

if ($testMessage !== null) {
	print '<br>';
	print load_fiche_titre($langs->trans('InboxTestResult'), '', '');
	print inboxMessageSummary($testMessage);
}

print dol_get_fiche_end();

llxFooter();
$db->close();
