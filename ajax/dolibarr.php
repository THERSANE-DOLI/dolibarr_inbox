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
 * \file    inbox/ajax/dolibarr.php
 * \ingroup inbox
 * \brief   Bandeau Dolibarr of the reading (lot W5 1.13.0): sender known, pieces linked, internal notes, search of pieces
 */

require __DIR__.'/inbox_ajax.inc.php';
dol_include_once('/inbox/class/inboxreader.class.php');
dol_include_once('/inbox/class/inboxlink.class.php');
dol_include_once('/inbox/class/inboxnote.class.php');
dol_include_once('/inbox/class/inboxcontactmatcher.class.php');
dol_include_once('/inbox/class/inboxcreate.class.php');
dol_include_once('/inbox/class/inboxmimeparser.class.php');
dol_include_once('/inbox/class/inboxattachmentscan.class.php');
dol_include_once('/inbox/class/inboxcited.class.php');

$action = (string) inboxAjaxParam('action', 'aZ09');
inboxAjaxStart(in_array($action, array('link', 'unlink', 'note', 'noteedit', 'notedelete', 'intent', 'attach'), true));
$langs->loadLangs(array('companies', 'propal', 'orders', 'bills', 'projects', 'ticket', 'interventions', 'sendings', 'supplier_proposal', 'suppliers'));
$elements = new InboxElements($db);
$refs = new InboxMailRef($db);

/**
 * Error of a class of inbox as an answer
 *
 * @param string $key Key of the error
 * @return void
 */
function inboxDolibarrFail($key)
{
	global $langs;

	$codes = array('InboxMailNotFound' => 404, 'InboxErrorPieceNotReadable' => 403, 'InboxErrorNoteNotFound' => 404, 'InboxErrorNoteNotYours' => 403,
		'InboxErrorNoteEmpty' => 422, 'InboxErrorLinkNotYours' => 403, 'InboxErrorNoMessageId' => 422, 'Bad parameters' => 400, 'InboxErrorCannotCreate' => 403, 'InboxErrorCannotAttach' => 403,
		'InboxErrorAttachmentInfected' => 422);
	inboxAjaxReply(array('error' => preg_match('/^Inbox[A-Za-z]+$/', $key) ? $langs->transnoentities($key) : $key, 'code' => $key), $codes[$key] ?? 500);
}

/**
 * The mail asked, readable by the user (else 404)
 *
 * @return InboxReader
 */
function inboxDolibarrMail()
{
	global $db, $user;

	$reader = new InboxReader($db);
	if (!$reader->load($user, (int) inboxAjaxParam('id', 'int'))) {
		inboxAjaxNotFound();
	}

	return $reader;
}

switch ($action) {
	case 'panel':
		// Everything the bandeau shows for a mail, or for its conversation (thread=1: links and notes of its mails)
		$reader = inboxDolibarrMail();
		$row = $reader->row;
		$p = $db->prefix();
		$rows = array($row);
		if ((int) inboxAjaxParam('thread', 'int') === 1 && (string) $row->thread_key !== '') {
			$found = $db->getRows("SELECT m.rowid, m.fk_account, m.fk_folder, m.message_id, m.date_mail, m.from_email, m.subject, m.size FROM ".$p."inbox_mail as m"
				." WHERE m.fk_account = ".((int) $row->fk_account)." AND m.thread_key = '".$db->escape((string) $row->thread_key)."' LIMIT 200");
			$rows = is_array($found) && $found ? $found : $rows;
		}
		$byRow = $refs->forRows($rows);
		$mailOf = array_flip($byRow);
		if (isset($byRow[(int) $row->rowid])) {
			$refs->seenAt($byRow[(int) $row->rowid], (int) $row->fk_account, (string) $row->folder_path);
		}
		// Sender: the contact or thirdparty of its address (index), else of its domain; a user of Dolibarr
		$sender = array('soc' => null, 'contact' => null, 'user' => null, 'by' => '');
		$email = strtolower((string) $row->from_email);
		$socId = (int) $row->fk_soc;
		$contactId = (int) $row->fk_socpeople;
		if (!$socId && $email !== '') {
			$matcher = new InboxContactMatcher($db);
			$m = $matcher->match($email);
			$socId = $m['fk_soc'];
			$contactId = $contactId ?: $m['fk_socpeople'];
			if (!$socId) {
				$socId = $matcher->matchDomain($email);
				$sender['by'] = $socId ? 'domain' : '';
			}
		}
		$sender['by'] = $sender['by'] ?: ($socId || $contactId ? 'address' : '');
		if ($socId) {
			$sender['soc'] = $elements->describe($user, 'societe', $socId);
		}
		if ($contactId) {
			$sender['contact'] = $elements->describe($user, 'contact', $contactId);
		}
		if ($email !== '') {
			$u = $db->getRow("SELECT rowid, firstname, lastname, login FROM ".$p."user WHERE LOWER(email) = '".$db->escape($email)."' AND statut = 1 AND entity IN (0, ".((int) $conf->entity).")");
			if (is_object($u)) {
				$sender['user'] = array('id' => (int) $u->rowid, 'name' => trim($u->firstname.' '.$u->lastname) ?: (string) $u->login);
			}
		}
		// Pieces linked; a piece removed from Dolibarr meanwhile: its link goes
		$linker = new InboxLink($db);
		$links = array();
		foreach ($linker->listFor(array_values($byRow)) as $l) {
			$d = $elements->describe($user, $l['type'], $l['element']);
			if ($d === null) {
				$linker->unlink($l['id'], null, true);
				continue;
			}
			$links[] = array_merge($d, array('link' => $l['id'], 'mail' => (int) ($mailOf[$l['mailref']] ?? 0), 'origin' => $l['origin'], 'date' => $l['date']));
		}
		$notes = new InboxNote($db);
		$noteList = array_map(function ($n) use ($mailOf) {
			$n['mail'] = (int) ($mailOf[$n['mailref']] ?? 0);
			return $n;
		}, $notes->listFor(array_values($byRow), $user));
		// Other modules: links made elsewhere, alerts (P9; the headers of the index, never the body)
		global $hookmanager;
		if (!is_object($hookmanager)) {
			include_once DOL_DOCUMENT_ROOT.'/core/class/hookmanager.class.php';
			$hookmanager = new HookManager($db);
		}
		$hookmanager->initHooks(array('inbox'));
		$hookParams = array('mail' => (int) $row->rowid, 'account' => (int) $row->fk_account, 'message_id' => (string) $row->message_id, 'from' => $email,
			'subject' => (string) $row->subject, 'mailref' => (int) ($byRow[(int) $row->rowid] ?? 0));
		$extraLinks = array();
		$alerts = array();
		$hookObject = null;
		if ($hookmanager->executeHooks('inboxMailLinks', $hookParams, $hookObject) >= 0 && is_array($hookmanager->results['links'] ?? null)) {
			foreach ($hookmanager->results['links'] as $x) {
				if (is_array($x) && !empty($x['type']) && !empty($x['id'])) {
					$d = $elements->describe($user, (string) $x['type'], (int) $x['id']);
					if ($d) {
						$extraLinks[] = array_merge($d, array('origin' => (string) ($x['origin'] ?? 'module')));
					}
				}
			}
		}
		// Actions of the other modules (« Fill the proposal »...): a label and an address inside Dolibarr
		$actions = array();
		if ($hookmanager->executeHooks('inboxMailActions', $hookParams, $hookObject) >= 0 && is_array($hookmanager->results['actions'] ?? null)) {
			foreach ($hookmanager->results['actions'] as $x) {
				$url = is_array($x) ? (string) ($x['url'] ?? '') : '';
				if ($url !== '' && !empty($x['label']) && (strpos($url, DOL_URL_ROOT.'/') === 0 || strpos($url, '/') === 0) && strpos($url, '//') !== 0) {
					$actions[] = array('label' => dol_trunc((string) $x['label'], 80, 'right', 'UTF-8', 1), 'url' => $url, 'icon' => preg_match('/^[a-z0-9-]{1,40}$/', (string) ($x['icon'] ?? '')) ? (string) $x['icon'] : 'magic');
				}
			}
		}
		if ($hookmanager->executeHooks('inboxMailAlerts', $hookParams, $hookObject) >= 0 && is_array($hookmanager->results['alerts'] ?? null)) {
			foreach ($hookmanager->results['alerts'] as $x) {
				if (is_array($x) && !empty($x['text'])) {
					$alerts[] = array('level' => ($x['level'] ?? '') === 'danger' ? 'danger' : 'warning', 'text' => dol_trunc((string) $x['text'], 500, 'right', 'UTF-8', 1));
				}
			}
		}
		// Blocks of the other modules in the panel (1.47.0): a title and the address of their summary (JSON, inside
		// Dolibarr), read by the panel after it is shown; its action opens a page of the module in a window of inbox
		$panels = array();
		if ($hookmanager->executeHooks('inboxMailPanels', $hookParams, $hookObject) >= 0 && is_array($hookmanager->results['panels'] ?? null)) {
			foreach (array_slice($hookmanager->results['panels'], 0, 3) as $x) {
				$url = is_array($x) ? (string) ($x['url'] ?? '') : '';
				if ($url !== '' && preg_match('/^[a-z0-9_-]{1,40}$/', (string) ($x['id'] ?? '')) && !empty($x['title']) && (strpos($url, DOL_URL_ROOT.'/') === 0 || strpos($url, '/') === 0) && strpos($url, '//') !== 0) {
					$panels[] = array('id' => (string) $x['id'], 'title' => dol_trunc((string) $x['title'], 60, 'right', 'UTF-8', 1), 'url' => $url,
						'icon' => preg_match('/^[a-z0-9-]{1,40}$/', (string) ($x['icon'] ?? '')) ? (string) $x['icon'] : 'magic');
				}
			}
		}
		inboxAjaxReply(array('sender' => $sender, 'links' => $links, 'extra' => $extraLinks, 'notes' => $noteList, 'alerts' => $alerts, 'actions' => $actions, 'panels' => $panels,
			'create' => InboxCreate::creatable($user), 'known' => array('soc' => $socId, 'contact' => $contactId),
			'level' => $reader->level, 'types' => array_values(array_filter(array_keys(InboxElements::TYPES), function ($t) use ($user) {
				return InboxElements::canReadType($user, $t);
			}))));
		// no break

	case 'intent':
		// « Create ▾ »: the page of creation of Dolibarr, filled from the mail; the piece will be linked to it
		$reader = inboxDolibarrMail();
		$creator = new InboxCreate($db);
		$url = $creator->intent($user, $reader, (string) inboxAjaxParam('type', 'aZ09'), (int) inboxAjaxParam('attach', 'int') === 1,
			array('soc' => (int) inboxAjaxParam('soc', 'int'), 'contact' => (int) inboxAjaxParam('contact', 'int')));
		if ($url === '') {
			inboxDolibarrFail($creator->error);
		}
		inboxAjaxReply(array('url' => $url));
		// no break

	case 'attach':
		// A file of the mail put in the documents of a piece, after the antivirus
		$reader = inboxDolibarrMail();
		$raw = $reader->raw($user);
		if ($raw === null) {
			inboxDolibarrFail('InboxMailNotFound');
		}
		$message = InboxMimeParser::parse($raw);
		$part = (int) inboxAjaxParam('part', 'int');
		$a = $message->attachments[$part] ?? null;
		if (!is_array($a) || !empty($a['inline']) || !empty($a['embedded'])) {
			inboxAjaxNotFound();
		}
		$scan = new InboxAttachmentScan($db);
		$verdict = $scan->check($a['content'], $a['name']);
		if ($verdict['state'] === InboxAttachmentScan::INFECTED) {
			inboxDolibarrFail('InboxErrorAttachmentInfected');
		}
		$type = (string) inboxAjaxParam('type', 'aZ09');
		$element = (int) inboxAjaxParam('element', 'int');
		$creator = new InboxCreate($db);
		$name = $creator->attach($user, $type, $element, (string) $a['name'], (string) $a['content']);
		if ($name === '') {
			inboxDolibarrFail($creator->error);
		}
		// The piece is linked to the mail too (a file of it is there now)
		$refId = $refs->fetchOrCreate($reader->row, $user);
		if ($refId > 0) {
			(new InboxLink($db))->link($refId, $type, $element, $user);
		}
		InboxActionLog::add($db, $user, (int) $reader->row->fk_account, 'attach', array($reader->row), (string) $reader->row->folder_path, '', 0, $name);
		inboxAjaxReply(array('name' => $name, 'unscanned' => $verdict['state'] !== InboxAttachmentScan::CLEAN && $verdict['state'] !== InboxAttachmentScan::OFF));
		// no break

	case 'cited':
		// Pieces cited in the mail (1.46.0), asked after the panel (the body is read): checked against the thirdparty
		// of the one who writes; suggestions only
		$reader = inboxDolibarrMail();
		$raw = $reader->raw($user);
		if ($raw === null) {
			inboxAjaxReply(array('cited' => array(), 'socs' => array()));
		}
		$message = InboxMimeParser::parse($raw);
		$headers = InboxReader::headers($raw);
		$body = $message->bodyText !== '' ? $message->bodyText : InboxMimeParser::htmlToText($message->bodyHtml);
		$names = array();
		foreach ($message->attachments as $a) {
			$names[] = (string) ($a['name'] ?? '');
		}
		$others = array();
		$m = array();
		if (preg_match_all('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', InboxReader::headerValue($headers, 'To').','.InboxReader::headerValue($headers, 'Cc'), $m)) {
			$others = $m[0];
		}
		$cited = new InboxCited($db);
		$socs = $cited->writerSocs((string) $reader->row->from_email, $body, $others);
		inboxAjaxReply(array('cited' => $cited->find($user, (string) $reader->row->subject."\n".$body."\n".implode("\n", $names), $socs), 'socs' => $socs));
		// no break

	case 'search':
		// types: only these (« Extend to »: the events, asked apart so the pieces come first); none = the usual ones
		$types = array_values(array_intersect(explode(',', (string) inboxAjaxParam('types', 'aZ09comma')), array_keys(InboxElements::TYPES)));
		inboxAjaxReply(array('results' => $elements->search($user, (string) inboxAjaxParam('q', 'alphanohtml'), (int) inboxAjaxParam('soc', 'int'), 20, $types ?: null)));
		// no break

	case 'link':
		$reader = inboxDolibarrMail();
		$refId = $refs->fetchOrCreate($reader->row, $user);
		if ($refId <= 0) {
			inboxDolibarrFail($refs->error ?: 'InboxErrorNoMessageId');
		}
		$linker = new InboxLink($db);
		$id = $linker->link($refId, (string) inboxAjaxParam('type', 'aZ09'), (int) inboxAjaxParam('element', 'int'), $user);
		if ($id < 0) {
			inboxDolibarrFail($linker->error);
		}
		inboxAjaxReply(array('link' => $id));
		// no break

	case 'unlink':
		$linker = new InboxLink($db);
		if ($linker->unlink((int) inboxAjaxParam('link', 'int'), $user) < 0) {
			inboxDolibarrFail($linker->error);
		}
		inboxAjaxReply(array('done' => 1));
		// no break

	case 'note':
		$reader = inboxDolibarrMail();
		$refId = $refs->fetchOrCreate($reader->row, $user);
		if ($refId <= 0) {
			inboxDolibarrFail($refs->error ?: 'InboxErrorNoMessageId');
		}
		$notes = new InboxNote($db);
		$id = $notes->add($refId, (string) inboxAjaxParam('text', 'none'), $user);
		if ($id < 0) {
			inboxDolibarrFail($notes->error);
		}
		inboxAjaxReply(array('note' => $id));
		// no break

	case 'noteedit':
	case 'notedelete':
		$notes = new InboxNote($db);
		$res = $action === 'noteedit' ? $notes->update((int) inboxAjaxParam('note', 'int'), (string) inboxAjaxParam('text', 'none'), $user) : $notes->delete((int) inboxAjaxParam('note', 'int'), $user);
		if ($res < 0) {
			inboxDolibarrFail($notes->error);
		}
		inboxAjaxReply(array('done' => 1));
		// no break

	default:
		inboxAjaxError('Unknown action', 400);
}
