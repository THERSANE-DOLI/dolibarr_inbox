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
dol_include_once('/inbox/class/inboxdatasource.class.php');
dol_include_once('/inbox/class/inboxthreadlink.class.php');
dol_include_once('/inbox/class/inboxactivity.class.php');
dol_include_once('/inbox/class/inboxcreate.class.php');
dol_include_once('/inbox/class/inboxmimeparser.class.php');
dol_include_once('/inbox/class/inboxsenderauth.class.php');
dol_include_once('/inbox/class/inboxattachmentscan.class.php');
dol_include_once('/inbox/class/inboxcited.class.php');
dol_include_once('/inbox/class/inboxsituation.class.php');
dol_include_once('/inbox/class/inboxlead.class.php');

$action = (string) inboxAjaxParam('action', 'aZ09');
inboxAjaxStart(in_array($action, array('link', 'unlink', 'detach', 'attach_thread', 'note', 'noteedit', 'notedelete', 'intent', 'attach', 'resoc', 'phone'), true));
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
		'InboxErrorAttachmentInfected' => 422, 'InboxErrorResocRemember' => 403);
	inboxAjaxReply(array('error' => preg_match('/^Inbox[A-Za-z]+$/', $key) ? $langs->transnoentities($key) : $key, 'code' => $key), $codes[$key] ?? 500);
}

/**
 * The sender of the mail is proven (audit INBX-059): authenticated by our receiving server for its own domain, no
 * Reply-To elsewhere, not the requester of a data source (typed by anyone in a form). Only then are the numbers of
 * its signature offered to replace the ones of the card, the numbers the alerts tell to call.
 *
 * @param InboxReader                         $reader  Mail loaded
 * @param array<int,array{0:string,1:string}> $headers Its headers
 * @return bool
 */
function inboxDolibarrSenderProven(InboxReader $reader, array $headers)
{
	if ((string) ($reader->row->requester_email ?? '') !== '') {
		return false;
	}
	$from = strtolower(trim((string) $reader->row->from_email));
	$replyTo = strtolower(trim((string) InboxSenderAuth::extractAddress(InboxReader::headerValue($headers, 'reply-to'))));
	if ($from === '' || ($replyTo !== '' && $replyTo !== $from)) {
		return false;
	}

	return InboxReader::authPass(InboxReader::authResults($headers), $from);
}

/**
 * The mail asked, readable by the user (else 404)
 *
 * @return InboxReader
 */
function inboxDolibarrMail()
{
	global $db, $user, $langs;

	// Protected mailbox (1.89.0): no link, note, piece nor thirdparty from its mails (D1)
	if (InboxLive::isMail((int) inboxAjaxParam('id', 'int'))) {
		inboxAjaxReply(array('error' => $langs->transnoentities('InboxProtectNoDolibarr'), 'code' => 'protected'), 403);
	}
	$reader = new InboxReader($db);
	if (!$reader->load($user, (int) inboxAjaxParam('id', 'int'))) {
		inboxAjaxNotFound();
	}

	return $reader;
}

/**
 * Usage of the mailbox of a mail (1.67.0), 'auto' by default
 *
 * @param int $accountId Mailbox
 * @return string
 */
function inboxDolibarrUsage($accountId)
{
	global $db;

	$row = $db->getRow("SELECT usage_profile FROM ".$db->prefix()."inbox_account WHERE rowid = ".((int) $accountId));

	return is_object($row) && InboxSituation::isUsage($row->usage_profile) ? (string) $row->usage_profile : 'auto';
}

/**
 * The other side of a mail for its thirdparty (1.92.2): the requester of a data source, else the sender, else when
 * the sender is one of us (the mailbox, one of its identities or a domain of our mailboxes) the first recipient (to,
 * then cc) outside of us, preferably one known in Dolibarr; '' for a mail between us
 *
 * @param object $row Row of the index (from_email, to_text, cc_text, requester_email, fk_account)
 * @return array{email:string,side:string} side: sender, recipient or internal
 */
function inboxDolibarrCounterpart($row)
{
	global $db;

	$requester = strtolower(trim((string) ($row->requester_email ?? '')));
	if ($requester !== '') {
		return array('email' => $requester, 'side' => 'sender');
	}
	$p = $db->prefix();
	$own = array();
	$rows = $db->getRows("SELECT email FROM ".$p."inbox_account WHERE rowid = ".((int) $row->fk_account)
		." UNION SELECT email FROM ".$p."inbox_identity WHERE fk_account = ".((int) $row->fk_account)." LIMIT 500");
	foreach (is_array($rows) ? $rows : array() as $r) {
		if ((string) $r->email !== '') {
			$own[] = strtolower(trim((string) $r->email));
		}
	}
	$domains = (new InboxCited($db))->ownDomains();
	$isOurs = function ($email) use ($own, $domains) {
		$at = strrpos($email, '@');
		return in_array($email, $own, true) || ($at !== false && in_array(substr($email, $at + 1), $domains, true));
	};
	$from = strtolower(trim((string) $row->from_email));
	if ($from !== '' && !$isOurs($from)) {
		return array('email' => $from, 'side' => 'sender');
	}
	if ($from === '') {
		return array('email' => '', 'side' => 'sender');
	}
	$m = array();
	preg_match_all('/[^\s<>,;"\']+@[^\s<>,;"\']+/', (string) $row->to_text.', '.(string) $row->cc_text, $m);
	$others = array_values(array_filter(array_unique(array_map('strtolower', $m[0])), function ($e) use ($isOurs) {
		return !$isOurs($e);
	}));
	if (!$others) {
		return array('email' => '', 'side' => 'internal');
	}
	$matcher = new InboxContactMatcher($db);
	foreach (array_slice($others, 0, 5) as $email) {
		$found = $matcher->match($email);
		if ($found['fk_soc'] || $found['fk_socpeople']) {
			return array('email' => $email, 'side' => 'recipient');
		}
	}

	return array('email' => $others[0], 'side' => 'recipient');
}

switch ($action) {
	case 'situation':
		// Situation of the thirdparty of a mail by the usage of its mailbox (1.67.0), asked after the panel
		$reader = inboxDolibarrMail();
		$socId = (int) inboxAjaxParam('soc', 'int');
		if ($socId <= 0 || !InboxElements::canRead($user, 'societe', $socId)) {
			inboxAjaxNotFound();
		}
		$account = new InboxAccount($db);
		if ($account->fetch((int) $reader->row->fk_account) <= 0) {
			inboxAjaxNotFound();
		}
		$situation = new InboxSituation($db);
		// A carrier writes about our parcels (usage Transport, 1.70.0): no situation, the shipment is in the cited pieces
		if ($situation->isCarrier($account, $socId)) {
			inboxAjaxReply(array('usage' => 'transport', 'shown' => array('transport'), 'indicators' => array(), 'carrier' => true));
		}
		$kind = $situation->reach('societe', $socId);
		$list = InboxSituation::listFor($account, (int) ($kind['client'] ?? 0), (int) ($kind['supplier'] ?? 0));
		inboxAjaxReply(array('usage' => $list['usage'], 'shown' => $list['shown'], 'indicators' => $situation->compute($user, $socId, $list['ids'])));
		// no break

	case 'phone':
		// « Replace the number of the card by this one » (1.69.0): the contact of the sender of the mail only
		$reader = inboxDolibarrMail();
		$contactId = (int) $reader->row->fk_socpeople;
		if ($contactId <= 0 || $contactId !== (int) inboxAjaxParam('contact', 'int') || !InboxElements::canRead($user, 'contact', $contactId)
			|| !InboxElements::canWriteType($user, 'contact')) {
			inboxDolibarrFail('InboxErrorPieceNotReadable');
		}
		$raw = $reader->raw($user);
		if ($raw === null || !inboxDolibarrSenderProven($reader, InboxReader::headers($raw))) {
			inboxDolibarrFail('InboxErrorPieceNotReadable');
		}
		$situation = new InboxSituation($db);
		$before = $situation->setContactPhone($user, $contactId, (string) inboxAjaxParam('field', 'aZ09'), (string) inboxAjaxParam('number', 'alphanohtml'));
		if ($before === null) {
			inboxDolibarrFail($situation->error);
		}
		inboxAjaxReply(array('before' => $before));
		// no break

	case 'resoc':
		// « Not the right thirdparty? » (1.67.0): the thirdparty of the conversation chosen by hand
		$reader = inboxDolibarrMail();
		$socId = (int) inboxAjaxParam('soc', 'int');
		if ($socId <= 0 || !InboxElements::canRead($user, 'societe', $socId)) {
			inboxDolibarrFail('InboxErrorPieceNotReadable');
		}
		// Remembered for the address: the mails of every mailbox of the entity follow, a choice of who may write from
		// this mailbox, not of a reader (audit INBX-060)
		$remember = (int) inboxAjaxParam('remember', 'int') === 1;
		if ($remember && !InboxAccess::atLeast($reader->level, InboxAccess::LEVEL_SEND)) {
			inboxDolibarrFail('InboxErrorResocRemember');
		}
		$situation = new InboxSituation($db);
		// The address remembered is the one of the other side (1.92.2): the recipient of a mail written by us
		$n = $situation->setThirdparty($user, $reader->row, $socId, $remember, inboxDolibarrCounterpart($reader->row)['email']);
		if ($n < 0) {
			inboxDolibarrFail($situation->error);
		}
		InboxActionLog::add($db, $user, (int) $reader->row->fk_account, 'resoc', array($reader->row), '', '', 0, (string) (($elements->describe($user, 'societe', $socId) ?? array())['ref'] ?? ''));
		inboxAjaxReply(array('done' => $n));
		// no break

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
		// Mail sent by a site for a requester (data sources, 1.72.0): the requester is the sender here
		$sources = new InboxDataSource($db);
		$requester = $sources->ofRow($row);
		// Mail written by us (1.92.2): its thirdparty is the one of its recipient, never one of our domain
		$counterpart = $requester ? array('email' => strtolower((string) $requester['email']), 'side' => 'sender') : inboxDolibarrCounterpart($row);
		$email = $counterpart['email'];
		$sender['side'] = $counterpart['side'];
		$socId = (int) $row->fk_soc;
		$contactId = (int) $row->fk_socpeople;
		$matcher = null;
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
		// Shared domains (lot A7, 1.76.0): the category of the domain (a disposable address is said), the
		// thirdparties that share it offered to choose (never linked without the user), and who may correct the list
		$matcher = $matcher ?? new InboxContactMatcher($db);
		$byDomain = $email !== '' ? $matcher->domainCandidates($email) : array('socs' => array(), 'category' => '', 'domain' => '');
		$sender['domain'] = $byDomain['domain'];
		$sender['domain_category'] = $byDomain['category'];
		$sender['candidates'] = array();
		if (!$socId) {
			foreach ($byDomain['socs'] as $cand) {
				$dc = $elements->describe($user, 'societe', $cand);
				if (!empty($dc['readable'])) {
					$sender['candidates'][] = $dc;
				}
			}
		}
		$sender['domain_admin'] = (int) $user->hasRight('inbox', 'account', 'admin');
		// Their ways to reach them (card of the thirdparty, 1.67.0) when the user may read them
		$situation = new InboxSituation($db);
		if ($socId) {
			$sender['soc'] = $elements->describe($user, 'societe', $socId);
			if (!empty($sender['soc']['readable'])) {
				$sender['soc']['reach'] = $situation->reach('societe', $socId);
			}
		}
		if ($contactId) {
			$sender['contact'] = $elements->describe($user, 'contact', $contactId);
			if (!empty($sender['contact']['readable'])) {
				$sender['contact']['reach'] = $situation->reach('contact', $contactId);
			}
		}
		$sender['manual'] = (int) ($row->soc_manual ?? 0);
		// The user is the one who wrote, also when the thirdparty is the one of the recipient
		$writer = $requester ? $email : strtolower(trim((string) $row->from_email));
		if ($writer !== '') {
			$u = $db->getRow("SELECT rowid, firstname, lastname, login FROM ".$p."user WHERE LOWER(email) = '".$db->escape($writer)."' AND statut = 1 AND entity IN (0, ".((int) $conf->entity).")");
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
		$hookParams = array('mail' => (int) $row->rowid, 'account' => (int) $row->fk_account, 'message_id' => (string) $row->message_id, 'from' => $requester ? $email : strtolower((string) $row->from_email),
			'subject' => (string) $row->subject, 'mailref' => (int) ($byRow[(int) $row->rowid] ?? 0),
			// Recognized on the mail (1.108.0): the thirdparty and the contact of the sender the user may read, else 0
			'socid' => !empty($sender['soc']['readable']) ? (int) $socId : 0, 'contactid' => !empty($sender['contact']['readable']) ? (int) $contactId : 0);
		$extraLinks = array();
		$alerts = array();
		$hookObject = null;
		if ($hookmanager->executeHooks('inboxMailLinks', $hookParams, $hookObject) >= 0 && is_array($hookmanager->resArray['links'] ?? null)) {
			foreach ($hookmanager->resArray['links'] as $x) {
				if (is_array($x) && !empty($x['type']) && !empty($x['id'])) {
					$d = $elements->describe($user, (string) $x['type'], (int) $x['id']);
					if ($d) {
						$extraLinks[] = array_merge($d, array('origin' => (string) ($x['origin'] ?? 'module')));
					}
				}
			}
		}
		// Actions of the other modules (« Fill the proposal »...): a label and an address inside Dolibarr, opened in a
		// new tab, or in a window of inbox (« window », 1.108.0); the browser fills the markers of the mail
		// An address inside Dolibarr: one starting slash, never « // » nor « /\ » (another host for the browsers), no
		// space, control character, backslash nor quote (audit INBX-101, as INBX-084)
		$localUrl = function ($u) {
			return strlen($u) <= 1000 && $u !== '' && $u[0] === '/' && !(isset($u[1]) && ($u[1] === '/' || $u[1] === '\\')) && !preg_match('/[\s\x00-\x1f\\\\<>"\']/', $u);
		};
		$actions = array();
		if ($hookmanager->executeHooks('inboxMailActions', $hookParams, $hookObject) >= 0 && is_array($hookmanager->resArray['actions'] ?? null)) {
			foreach ($hookmanager->resArray['actions'] as $x) {
				$url = is_array($x) ? (string) ($x['url'] ?? '') : '';
				if ($url !== '' && !empty($x['label']) && $localUrl($url)) {
					$actions[] = array('label' => dol_trunc((string) $x['label'], 80, 'right', 'UTF-8', 1), 'url' => $url, 'icon' => preg_match('/^[a-z0-9-]{1,40}$/', (string) ($x['icon'] ?? '')) ? (string) $x['icon'] : 'magic',
						'window' => !empty($x['window']));
				}
			}
		}
		if ($hookmanager->executeHooks('inboxMailAlerts', $hookParams, $hookObject) >= 0 && is_array($hookmanager->resArray['alerts'] ?? null)) {
			foreach ($hookmanager->resArray['alerts'] as $x) {
				if (is_array($x) && !empty($x['text'])) {
					$alerts[] = array('level' => ($x['level'] ?? '') === 'danger' ? 'danger' : 'warning', 'text' => dol_trunc((string) $x['text'], 500, 'right', 'UTF-8', 1));
				}
			}
		}
		// Blocks of the other modules in the panel (1.47.0): a title and the address of their summary (JSON, inside
		// Dolibarr), read by the panel after it is shown; its action opens a page of the module in a window of inbox
		$panels = array();
		if ($hookmanager->executeHooks('inboxMailPanels', $hookParams, $hookObject) >= 0 && is_array($hookmanager->resArray['panels'] ?? null)) {
			foreach (array_slice($hookmanager->resArray['panels'], 0, 3) as $x) {
				$url = is_array($x) ? (string) ($x['url'] ?? '') : '';
				if ($url !== '' && preg_match('/^[a-z0-9_-]{1,40}$/', (string) ($x['id'] ?? '')) && !empty($x['title']) && $localUrl($url)) {
					$panels[] = array('id' => (string) $x['id'], 'title' => dol_trunc((string) $x['title'], 60, 'right', 'UTF-8', 1), 'url' => $url,
						'icon' => preg_match('/^[a-z0-9-]{1,40}$/', (string) ($x['icon'] ?? '')) ? (string) $x['icon'] : 'magic');
				}
			}
		}
		if ($requester) {
			// The piece the source offers to create, when the user may
			$requester['create'] = $requester['type'] !== '' && InboxElements::canWriteType($user, $requester['type']) ? $requester['type'] : '';
		}
		// Fil of the mail (lot E2, 1.77.0): the answers sent from the mailbox in its conversation, beside the notes
		$activity = array_map(function ($a) use ($user) {
			$a['mine'] = $a['author'] > 0 && $a['author'] === (int) $user->id;
			unset($a['message_id']);
			return $a;
		}, (new InboxActivity($db))->sentIn($row));
		inboxAjaxReply(array('sender' => $sender, 'requester' => $requester, 'activity' => $activity, 'conversation' => (new InboxThreadLink($db))->state($row), 'links' => $links, 'extra' => $extraLinks, 'notes' => $noteList, 'alerts' => $alerts, 'actions' => $actions, 'panels' => $panels,
			// New customer from a mail (lot A1, 1.79.0): contacts left to create from their page
			'lead_pending' => (new InboxLead($db))->pendingFor($user, (int) $row->rowid),
			'create' => InboxCreate::creatable($user), 'known' => array('soc' => $socId, 'contact' => $contactId), 'usage' => inboxDolibarrUsage((int) $row->fk_account), 'from' => $email,
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
		$creator = new InboxCreate($db);
		$done = $creator->attachFromMail($user, $reader, (int) inboxAjaxParam('part', 'int'), (string) inboxAjaxParam('type', 'aZ09'), (int) inboxAjaxParam('element', 'int'));
		if ($done === null) {
			if ($creator->error === 'InboxErrorAttachmentNotFound') {
				inboxAjaxNotFound();
			}
			inboxDolibarrFail($creator->error);
		}
		inboxAjaxReply($done);
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
		// Phones of the signature that the cards of the contact and of the thirdparty do not have (1.69.0)
		$contactId = (int) $reader->row->fk_socpeople;
		$phones = array();
		if ($contactId > 0 && InboxElements::canRead($user, 'contact', $contactId) && inboxDolibarrSenderProven($reader, $headers)) {
			$situation = new InboxSituation($db);
			$phones = $situation->newPhones($body, $contactId, (int) $reader->row->fk_soc);
		}
		inboxAjaxReply(array('cited' => $cited->find($user, (string) $reader->row->subject."\n".$body."\n".implode("\n", $names), $socs), 'socs' => $socs,
			'phones' => $phones, 'phone_edit' => $phones && InboxElements::canWriteType($user, 'contact')));
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
		$type = (string) inboxAjaxParam('type', 'aZ09');
		$element = (int) inboxAjaxParam('element', 'int');
		$id = $linker->link($refId, $type, $element, $user);
		if ($id < 0) {
			inboxDolibarrFail($linker->error);
		}
		// « Link also the other mails of the conversation » (lot A3, 1.73.0), ticked by default
		$others = 0;
		if ((int) inboxAjaxParam('thread', 'int') === 1) {
			$others = max(0, (new InboxThreadLink($db))->linkThread($user, $reader->row, $type, $element));
		}
		inboxAjaxReply(array('link' => $id, 'others' => $others));
		// no break

	case 'unlink':
		$linker = new InboxLink($db);
		if ($linker->unlink((int) inboxAjaxParam('link', 'int'), $user) < 0) {
			inboxDolibarrFail($linker->error);
		}
		inboxAjaxReply(array('done' => 1));
		// no break

	case 'detach':
		// « Detach this mail from its conversation » (lot A3, 1.73.0): its links of the conversation go
		$reader = inboxDolibarrMail();
		// The links of the conversation are shared by every copy of the mail: changed by who may send from the
		// mailbox (as INBX-006), never by a reader (audit INBX-068)
		if (!InboxAccess::atLeast($reader->level, InboxAccess::LEVEL_SEND)) {
			inboxAjaxNotFound();
		}
		$threads = new InboxThreadLink($db);
		$removed = $threads->detach($user, $reader->row);
		if ($removed === null) {
			inboxDolibarrFail($threads->error);
		}
		inboxAjaxReply(array('removed' => $removed));
		// no break

	case 'attach_thread':
		// Undo of the detachment: back in its conversation, the links given back
		$reader = inboxDolibarrMail();
		// The links of the conversation are shared by every copy of the mail: changed by who may send from the
		// mailbox (as INBX-006), never by a reader (audit INBX-068)
		if (!InboxAccess::atLeast($reader->level, InboxAccess::LEVEL_SEND)) {
			inboxAjaxNotFound();
		}
		$links = inboxAjaxParam('links', 'none');
		$threads = new InboxThreadLink($db);
		if ($threads->attach($user, $reader->row, is_array($links) ? $links : array()) < 0) {
			inboxDolibarrFail($threads->error);
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
