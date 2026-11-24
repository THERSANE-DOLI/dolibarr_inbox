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
llxHeader('', $langs->trans($title), '', '', 0, 0, array('/inbox/js/inbox-setup.js'), '', '', 'mod-inbox page-admin');

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
// Advice (not required): without the extension intl of PHP, the domains written with accents or other alphabets
// (the most deceptive lookalikes) are neither recognized nor told
if (!function_exists('idn_to_ascii') || !class_exists('Normalizer')) {
	print info_admin($langs->trans('InboxIntlMissing'), 0, 0, 'warning');
}
// Folders dev/ and test/ of a Git deployment (1.135.0): shown only when the browser can read them (js/inbox-setup.js
// fetches their web-probe.txt without cookies, as anybody would). Never a button that deletes them: in a Git clone
// the next git pull would fail
$devFolders = inboxDevFolders();
$devProbes = array();
foreach ($devFolders['folders'] as $folder) {
	if (is_file(dirname(__DIR__).'/'.$folder.'/web-probe.txt')) {
		$devProbes[$folder] = dol_buildpath('/inbox/'.$folder.'/web-probe.txt', 1);
	}
}
if ($devProbes) {
	if ($devFolders['git']) {
		$devFix = $langs->trans('InboxDevFoldersFixGit', '<code>'.dol_escape_htmltag(dirname(__DIR__)).'</code>', '<code>git sparse-checkout set --no-cone \'/*\' \'!/dev/\' \'!/test/\'</code>', '<code>git sparse-checkout disable</code>');
	} else {
		$devFix = $langs->trans('InboxDevFoldersFixCopy', '<code>rm -rf dev test</code>');
	}
	$devDoc = '<a href="'.dol_buildpath('/inbox/admin/doc.php', 1).'?page=install">'.$langs->trans('InboxDevFoldersDoc').'</a>';
	print '<div id="inbox-devfolders" hidden data-probe="'.dol_escape_htmltag(json_encode($devProbes)).'">';
	print info_admin($langs->trans('InboxDevFoldersExposed', '<span data-devfolders-list></span>').'<br>'.$devFix.'<br>'.$langs->trans('InboxDevFoldersFixServer', $devDoc), 0, 0, 'warning');
	print '</div>';
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
