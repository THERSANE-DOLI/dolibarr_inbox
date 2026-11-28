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
 * \file    inbox/mails.php
 * \ingroup inbox
 * \brief   Tab « Mails » of the cards of Dolibarr (1.13.0): mails linked to the piece and, for a thirdparty or a
 *          contact, mails from and to its addresses, in the mailboxes the user can read
 */

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
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

dol_include_once('/inbox/class/inboxlink.class.php');
dol_include_once('/inbox/class/inboxnote.class.php');
dol_include_once('/inbox/class/inboxaccess.class.php');

$langs->loadLangs(array('inbox@inbox', 'companies', 'propal', 'orders', 'bills', 'projects', 'ticket', 'interventions', 'sendings', 'supplier_proposal', 'suppliers', 'mails'));

$type = GETPOST('type', 'aZ09');
$id = GETPOSTINT('id');
$page = max(0, GETPOSTINT('page'));
$limit = 50;

if (!isModEnabled('inbox') || !$user->hasRight('inbox', 'webmail', 'use') || !empty($user->socid) || !InboxElements::canRead($user, $type, $id)) {
	accessforbidden();
}
$object = fetchObjectByElement($id, $type);
if (!is_object($object) || empty($object->id)) {
	accessforbidden();
}

// Head of the card of each type: library, function, picto
$heads = array(
	'societe' => array('/core/lib/company.lib.php', 'societe_prepare_head', 'company'),
	'contact' => array('/core/lib/contact.lib.php', 'contact_prepare_head', 'contact'),
	'propal' => array('/core/lib/propal.lib.php', 'propal_prepare_head', 'propal'),
	'commande' => array('/core/lib/order.lib.php', 'commande_prepare_head', 'order'),
	'facture' => array('/core/lib/invoice.lib.php', 'facture_prepare_head', 'bill'),
	'order_supplier' => array('/core/lib/fourn.lib.php', 'ordersupplier_prepare_head', 'order'),
	'invoice_supplier' => array('/core/lib/fourn.lib.php', 'facturefourn_prepare_head', 'supplier_invoice'),
	'supplier_proposal' => array('/core/lib/supplier_proposal.lib.php', 'supplier_proposal_prepare_head', 'supplier_proposal'),
	'project' => array('/core/lib/project.lib.php', 'project_prepare_head', 'project'),
	'ticket' => array('/core/lib/ticket.lib.php', 'ticket_prepare_head', 'ticket'),
	'fichinter' => array('/core/lib/fichinter.lib.php', 'fichinter_prepare_head', 'intervention'),
	'shipping' => array('/core/lib/sendings.lib.php', 'shipping_prepare_head', 'dolly'),
);
require_once DOL_DOCUMENT_ROOT.$heads[$type][0];

// Mailboxes the user can read
$access = new InboxAccess($db);
$readable = array_keys(array_filter($access->getLevels($user), function ($level) {
	return InboxAccess::rank($level) >= InboxAccess::rank(InboxAccess::LEVEL_READ);
}));
$p = $db->prefix();

// Mails linked (explicitly), and for a thirdparty or a contact, its addresses
$linker = new InboxLink($db);
$refIds = $linker->mailrefsOf($type, $id);
$keys = array();
$refRows = array();
if ($refIds) {
	$found = $db->getRows("SELECT rowid, message_id, subject, from_email, date_mail, last_account, last_folder".(InboxMailRef::hasDateGone($db) ? ", date_gone" : "")
		." FROM ".$p."inbox_mailref WHERE rowid IN (".implode(',', $refIds).") LIMIT 50000");
	foreach (is_array($found) ? $found : array() as $r) {
		$refRows[(int) $r->rowid] = $r;
		if (strpos((string) $r->message_id, 'fp:') !== 0) {
			$keys[] = "'".$db->escape((string) $r->message_id)."'";
		}
	}
}
$addresses = array();
if ($type === 'societe' || $type === 'contact') {
	$sql = $type === 'societe'
		? "SELECT email FROM ".$p."societe WHERE rowid = ".((int) $id)." UNION SELECT email FROM ".$p."socpeople WHERE fk_soc = ".((int) $id)
		: "SELECT email FROM ".$p."socpeople WHERE rowid = ".((int) $id);
	$found = $db->getRows($sql." LIMIT 5000");
	foreach (is_array($found) ? $found : array() as $r) {
		foreach (preg_split('/[\s,;]+/', strtolower(trim((string) $r->email))) as $e) {
			if (isValidEmail($e)) {
				$addresses["'".$db->escape($e)."'"] = true;
			}
		}
	}
}
$or = array();
if ($keys) {
	$or[] = "m.message_id IN (".implode(',', array_unique($keys)).")";
}
if ($addresses) {
	$or[] = "m.rowid IN (SELECT a.fk_mail FROM ".$p."inbox_mail_address as a WHERE a.email IN (".implode(',', array_keys($addresses))."))";
}
$rows = array();
$total = 0;
if ($readable && $or) {
	$where = " FROM ".$p."inbox_mail as m INNER JOIN ".$p."inbox_folder as f ON f.rowid = m.fk_folder AND f.status = 1 INNER JOIN ".$p."inbox_account as acc ON acc.rowid = m.fk_account"
		." WHERE m.fk_account IN (".implode(',', array_map('intval', $readable)).") AND (".implode(' OR ', $or).")";
	$cnt = $db->getRow("SELECT COUNT(DISTINCT COALESCE(m.message_id, CONCAT('row', m.rowid))) as n".$where);
	$total = is_object($cnt) ? (int) $cnt->n : 0;
	// A mail in several folders or mailboxes once: the copy outside the trash and the junk first
	$found = $db->getRows("SELECT m.rowid, m.fk_folder, m.message_id, m.from_email, m.from_name, m.to_text, m.subject, m.size, m.date_mail, m.date_received, m.has_attachments,"
		." f.name as folder_name, f.role, acc.email as account_email".$where." ORDER BY COALESCE(m.date_received, m.date_mail) DESC, m.rowid DESC LIMIT ".(($page + 1) * $limit * 3));
	$seen = array();
	foreach (is_array($found) ? $found : array() as $r) {
		$k = (string) $r->message_id !== '' ? (string) $r->message_id : 'row'.$r->rowid;
		if (isset($seen[$k])) {
			if (in_array((string) $rows[$seen[$k]]->role, array('trash', 'junk'), true) && !in_array((string) $r->role, array('trash', 'junk'), true)) {
				$rows[$seen[$k]] = $r;
			}
			continue;
		}
		$seen[$k] = count($rows);
		$rows[] = $r;
	}
	$rows = array_slice($rows, $page * $limit, $limit);
}
// Linked mails found nowhere any more (removed from the server, or not indexed): what the mailref kept
$refs = new InboxMailRef($db);
$gone = array();
if ($page === 0) {
	foreach ($refRows as $refId => $r) {
		// Its subject only to who could read its last mailbox (audit INBX-018)
		if (!$refs->copies($refId) && in_array((int) $r->last_account, $readable, true)) {
			$gone[] = $r;
		}
	}
}
// Linked or found by an address; notes of each mail
$linkedKeys = array();
foreach ($refRows as $r) {
	$linkedKeys[(string) $r->message_id] = true;
}
$noteCount = array();
if ($rows) {
	$byRow = $refs->forRows($rows);
	if ($byRow) {
		$cnts = $db->getRows("SELECT fk_mailref, COUNT(rowid) as n FROM ".$p."inbox_note WHERE fk_mailref IN (".implode(',', array_unique($byRow)).") GROUP BY fk_mailref LIMIT 5000");
		$byRef = array();
		foreach (is_array($cnts) ? $cnts : array() as $c) {
			$byRef[(int) $c->fk_mailref] = (int) $c->n;
		}
		foreach ($byRow as $rowId => $refId) {
			$noteCount[$rowId] = $byRef[$refId] ?? 0;
		}
	}
}

$title = $langs->trans('InboxMailsTab');
llxHeader('', $title.' - '.(string) ($object->ref ?? $object->name ?? ''), '', '', 0, 0, '', '', '', 'mod-inbox page-mails');
$head = call_user_func($heads[$type][1], $object);
print dol_get_fiche_head($head, 'inboxmails', $langs->trans(InboxElements::TYPES[$type]['label']), -1, $heads[$type][2]);
if ($type === 'societe') {
	dol_banner_tab($object, 'socid', '', ($user->socid ? 0 : 1), 'rowid', 'nom');
} elseif ($type === 'contact') {
	dol_banner_tab($object, 'id', '', 0, 'rowid', 'ref');
} else {
	dol_banner_tab($object, 'ref', '', 0, 'ref', 'ref');
}
print dol_get_fiche_end();

$inboxUrl = dol_buildpath('/inbox/index.php', 1);
print '<div class="div-table-responsive">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><th>'.$langs->trans('Date').'</th><th>'.$langs->trans('InboxSender').'</th><th>'.$langs->trans('InboxSubject').'</th>';
print '<th>'.$langs->trans('InboxMailboxFolder').'</th><th class="center">'.$langs->trans('InboxNotes').'</th><th></th></tr>';
$goneDays = InboxMailRef::goneDays();
foreach ($gone as $r) {
	print '<tr class="oddeven opacitymedium"><td class="nowraponall">'.dol_print_date($db->jdate($r->date_mail), 'dayhour').'</td><td>'.dol_escape_htmltag((string) $r->from_email).'</td>';
	print '<td>'.dol_escape_htmltag((string) $r->subject).'</td>';
	// Gone from the server for sure (1.114.0): since when, and when its link is forgotten unless it comes back
	$goneAt = !empty($r->date_gone) ? (int) $db->jdate($r->date_gone) : 0;
	$state = $goneAt ? $langs->trans('InboxMailGoneSince', dol_print_date($goneAt, 'day')) : $langs->trans('InboxMailGoneEverywhere');
	if ($goneAt && $goneDays['linked'] > 0) {
		$state .= ' '.$langs->trans('InboxMailGoneForgetOn', dol_print_date($goneAt + $goneDays['linked'] * 86400, 'day'));
	}
	print '<td colspan="3">'.dol_escape_htmltag($state.((string) $r->last_folder !== '' ? ' '.$langs->trans('InboxLastSeenIn', mb_convert_encoding((string) $r->last_folder, 'UTF-8', 'UTF7-IMAP')) : '')).'</td></tr>';
}
foreach ($rows as $r) {
	$date = $r->date_received ? $r->date_received : $r->date_mail;
	$linked = isset($linkedKeys[(string) $r->message_id]);
	print '<tr class="oddeven">';
	print '<td class="nowraponall">'.dol_print_date($db->jdate($date), 'dayhour').'</td>';
	print '<td class="tdoverflowmax200" title="'.dol_escape_htmltag((string) $r->from_email).'">'.dol_escape_htmltag((string) ($r->from_name ?: $r->from_email)).'</td>';
	print '<td class="tdoverflowmax400">'.($r->has_attachments ? img_picto($langs->trans('InboxAttachments'), 'paperclip', 'class="paddingright"') : '').dol_escape_htmltag((string) $r->subject)
		.($linked ? ' <span class="badge badge-secondary">'.$langs->trans('InboxLinked').'</span>' : '').'</td>';
	print '<td class="tdoverflowmax200">'.dol_escape_htmltag((string) $r->account_email.' / '.(string) $r->folder_name).'</td>';
	print '<td class="center">'.(!empty($noteCount[(int) $r->rowid]) ? (int) $noteCount[(int) $r->rowid] : '').'</td>';
	print '<td class="right nowraponall"><a href="'.$inboxUrl.'#/mail/'.((int) $r->fk_folder).'/'.((int) $r->rowid).'" target="_blank" rel="noopener">'.$langs->trans('InboxOpenInInbox').'</a></td>';
	print '</tr>';
}
if (!$rows && !$gone) {
	print '<tr class="oddeven"><td colspan="6"><span class="opacitymedium">'.$langs->trans($readable ? 'InboxNoMailForPiece' : 'InboxNoMailboxReadable').'</span></td></tr>';
}
print '</table></div>';
if ($total > $limit) {
	$pages = (int) ceil($total / $limit);
	print '<div class="center paddingtop">';
	for ($i = 0; $i < min($pages, 50); $i++) {
		print $i === $page ? ' <b>'.($i + 1).'</b> ' : ' <a href="'.$_SERVER['PHP_SELF'].'?type='.urlencode($type).'&id='.((int) $id).'&page='.$i.'">'.($i + 1).'</a> ';
	}
	print '</div>';
}

llxFooter();
$db->close();
