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
 * \brief   Technical setup of Inbox (the webmail settings are in the inbox application): cache, journals, master
 *          user of the mail server, timeouts, tests of reading
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

$numericConsts = array(
	'INBOX_CACHE_DAYS' => array('min' => 1, 'default' => 30),
	'INBOX_LOG_DAYS' => array('min' => 1, 'default' => 365),
	'INBOX_ACCOUNT_LOG_DAYS' => array('min' => 30, 'default' => 730),
);
// Seconds (not days)
$secondConsts = array(
	'INBOX_IMAP_TIMEOUT' => array('min' => 5, 'max' => 120, 'default' => 20),
	'INBOX_TEST_TIMEOUT' => array('min' => 3, 'max' => 60, 'default' => 15),
);

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

if ($action == 'update' && $_SERVER['REQUEST_METHOD'] == 'POST') {
	$error = 0;
	foreach ($numericConsts as $constName => $def) {
		$value = max($def['min'], GETPOSTINT($constName));
		if (dolibarr_set_const($db, $constName, $value, 'chaine', 0, '', $conf->entity) < 0) {
			$error++;
		}
	}
	foreach ($secondConsts as $constName => $def) {
		$value = min($def['max'], max($def['min'], GETPOSTINT($constName)));
		if (dolibarr_set_const($db, $constName, $value, 'chaine', 0, '', $conf->entity) < 0) {
			$error++;
		}
	}
	if ($error) {
		setEventMessages($langs->trans("Error"), null, 'errors');
	} else {
		setEventMessages($langs->trans("SetupSaved"), null, 'mesgs');
	}
	header("Location: ".$_SERVER["PHP_SELF"]);
	exit;
}

// Master user of the mail server (Dovecot): password encrypted, never shown again; an empty field keeps it
if ($action == 'updatemaster' && $_SERVER['REQUEST_METHOD'] == 'POST') {
	$masterUser = trim(GETPOST('INBOX_MASTER_USER', 'alphanohtml'));
	$separator = trim(GETPOST('INBOX_MASTER_SEPARATOR', 'alphanohtml'));
	$password = GETPOST('INBOX_MASTER_PASSWORD', 'none');
	if ($separator === '' || strlen($separator) > 2 || preg_match('/[\s"\']/', $separator)) {
		setEventMessages($langs->trans('InboxErrorMasterSeparator'), null, 'errors');
	} elseif ($masterUser !== '' && strpos($masterUser, $separator) !== false) {
		setEventMessages($langs->trans('InboxErrorMasterUserSeparator', $separator), null, 'errors');
	} else {
		dolibarr_set_const($db, 'INBOX_MASTER_USER', $masterUser, 'chaine', 0, '', $conf->entity);
		dolibarr_set_const($db, 'INBOX_MASTER_SEPARATOR', $separator, 'chaine', 0, '', $conf->entity);
		if ($masterUser === '') {
			dolibarr_del_const($db, 'INBOX_MASTER_PASSWORD', $conf->entity);
		} elseif ($password !== '') {
			dolibarr_set_const($db, 'INBOX_MASTER_PASSWORD', dolEncrypt($password), 'chaine', 0, '', $conf->entity);
		}
		setEventMessages($langs->trans("SetupSaved"), null, 'mesgs');
	}
	header("Location: ".$_SERVER["PHP_SELF"]);
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
llxHeader('', $langs->trans($title), '', '', 0, 0, '', '', '', 'mod-inbox page-admin');

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.img_picto($langs->trans("BackToModuleList"), 'back', 'class="pictofixedwidth"')
	.'<span class="hideonsmartphone">'.$langs->trans("BackToModuleList").'</span></a>';
print load_fiche_titre($langs->trans($title), $linkback, 'title_setup');

$head = inboxAdminPrepareHead();
print dol_get_fiche_head($head, 'settings', $langs->trans($title), -1, 'inbox@inbox');

print '<span class="opacitymedium">'.$langs->trans('InboxSetupHelp').'</span><br>';
// The webmail settings live in the application, not here (analyse-lot-w1 §5)
print '<span class="opacitymedium">'.$langs->trans('InboxSetupAppHelp').'</span> <a href="'.dol_buildpath('/inbox/index.php', 1).'#/settings">'.img_picto('', 'fa-external-link-alt', 'class="pictofixedwidth"').$langs->trans('InboxOpenSettings').'</a><br><br>';

// Mailboxes: those of inbox (managed in the inbox application), others given by modules through a hook
$mailboxes = $inbox->listMailboxes();
print load_fiche_titre($langs->trans('InboxMailboxes'), '', '');
print '<span class="opacitymedium">'.$langs->trans('InboxMailboxesHelp', count($mailboxes)).'</span><br>';
print '<br>';

// Settings
print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="update">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans("Parameter").'</td><td>'.$langs->trans("Value").'</td></tr>';
foreach ($numericConsts as $constName => $def) {
	print '<tr class="oddeven"><td>'.$form->textwithpicto($langs->trans($constName), $langs->trans($constName.'Help')).'</td>';
	print '<td><input type="number" class="maxwidth75" min="'.$def['min'].'" name="'.$constName.'" value="'.getDolGlobalInt($constName, $def['default']).'"> '.$langs->trans('days').'</td></tr>';
}
foreach ($secondConsts as $constName => $def) {
	print '<tr class="oddeven"><td>'.$form->textwithpicto($langs->trans($constName), $langs->trans($constName.'Help')).'</td>';
	print '<td><input type="number" class="maxwidth75" min="'.$def['min'].'" max="'.$def['max'].'" name="'.$constName.'" value="'.getDolGlobalInt($constName, $def['default']).'"> '.$langs->trans('InboxSecondsShort').'</td></tr>';
}
print '</table>';
print '<div class="center"><input type="submit" class="button button-save" value="'.$langs->trans("Save").'"></div>';
print '</form>';
print '<br>';

// Master user of the mail server
print load_fiche_titre($langs->trans('InboxMasterUser'), '', '');
print '<span class="opacitymedium">'.$langs->trans('InboxMasterUserHelp').'</span><br><br>';
print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'" autocomplete="off">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="updatemaster">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans("Parameter").'</td><td>'.$langs->trans("Value").'</td></tr>';
print '<tr class="oddeven"><td class="titlefield">'.$langs->trans('InboxMasterUserName').'</td><td><input type="text" class="minwidth200" name="INBOX_MASTER_USER" value="'.dol_escape_htmltag(getDolGlobalString('INBOX_MASTER_USER')).'" autocomplete="off"></td></tr>';
print '<tr class="oddeven"><td>'.$form->textwithpicto($langs->trans('InboxMasterSeparator'), $langs->trans('InboxMasterSeparatorHelp')).'</td><td><input type="text" class="maxwidth50" maxlength="2" name="INBOX_MASTER_SEPARATOR" value="'.dol_escape_htmltag(getDolGlobalString('INBOX_MASTER_SEPARATOR', '*')).'"></td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('Password').'</td><td><input type="password" class="minwidth200" name="INBOX_MASTER_PASSWORD" value="" autocomplete="new-password" placeholder="'.dol_escape_htmltag(getDolGlobalString('INBOX_MASTER_PASSWORD') !== '' ? $langs->trans('InboxPasswordKept') : '').'"></td></tr>';
print '</table>';
print '<div class="center"><input type="submit" class="button button-save" value="'.$langs->trans("Save").'"></div>';
print '</form>';
print '<br>';

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
