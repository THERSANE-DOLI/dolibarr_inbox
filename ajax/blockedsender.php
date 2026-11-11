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
 * \file    inbox/ajax/blockedsender.php
 * \ingroup inbox
 * \brief   Blocked senders (1.85.0): the list (every user of the webmail), what the user may block from a mail, block
 *          (with the mails already received), unblock; the level of the sender is always worked out here
 */

require __DIR__.'/inbox_ajax.inc.php';
dol_include_once('/inbox/class/inboxblockedsender.class.php');
dol_include_once('/inbox/class/inboxactions.class.php');

$action = (string) inboxAjaxParam('action', 'aZ09');
inboxAjaxStart(in_array($action, array('create', 'delete'), true));
$blocked = new InboxBlockedSender($db);

/**
 * A mail of the index the user can read, or 404
 *
 * @param int $id Row of the index
 * @return object
 */
function inboxBlockedMail($id)
{
	global $db, $user;

	$row = $db->getRow("SELECT m.rowid, m.fk_account, m.message_id, m.from_email FROM ".$db->prefix()."inbox_mail as m WHERE m.rowid = ".((int) $id));
	if (!is_object($row) || !(new InboxAccess($db))->check($user, (int) $row->fk_account, InboxAccess::LEVEL_READ)) {
		inboxAjaxNotFound();
	}

	return $row;
}

/**
 * @param InboxBlockedSender $blocked Helper
 * @return array<string,mixed>
 */
function inboxBlockedReply(InboxBlockedSender $blocked)
{
	global $user;

	$out = array('enabled' => InboxBlockedSender::enabled(), 'manager' => InboxBlockedSender::isManager($user), 'me' => (int) $user->id,
		'entries' => $blocked->fetchAll(), 'many' => InboxBlockedSender::MANY);
	// State of the block on each mailbox: the administrators of the mailboxes
	if ($user->hasRight('inbox', 'account', 'admin')) {
		$out['states'] = $blocked->states();
	}

	return $out;
}

switch ($action) {
	case 'list':
		inboxAjaxReply(inboxBlockedReply($blocked));
		// no break

	case 'offer':
		// What the user may block from this mail: the address, the domain, the mails already received of each
		$row = inboxBlockedMail((int) inboxAjaxParam('mail', 'int'));
		$offer = $blocked->offer($user, (string) $row->from_email, (int) $row->fk_account);
		$offer['past_address'] = $offer['can_address'] ? $blocked->pastMails($user, InboxBlockedSender::TYPE_ADDRESS, $offer['address']) : array('ids' => array(), 'accounts' => 0);
		$offer['past_domain'] = $offer['can_domain'] ? $blocked->pastMails($user, InboxBlockedSender::TYPE_DOMAIN, $offer['domain']) : array('ids' => array(), 'accounts' => 0);
		foreach (array('past_address', 'past_domain') as $k) {
			$offer[$k] = array('count' => count($offer[$k]['ids']), 'accounts' => $offer[$k]['accounts']);
		}
		if ($offer['blocked']) {
			$offer['entry'] = $blocked->fetch($offer['blocked']);
		}
		inboxAjaxReply($offer);
		// no break

	case 'create':
		$type = (string) inboxAjaxParam('type', 'aZ09');
		$mailId = (int) inboxAjaxParam('mail', 'int');
		$accountId = 0;
		$mailrefId = 0;
		$value = (string) inboxAjaxParam('value');
		if ($mailId > 0) {
			// From a mail: the sender of the mail, never another value sent with it
			$row = inboxBlockedMail($mailId);
			$accountId = (int) $row->fk_account;
			$from = InboxBlockedSender::normalizeAddress((string) $row->from_email);
			$value = $type === InboxBlockedSender::TYPE_DOMAIN ? InboxBlockedSender::domainOf($from) : $from;
			if ((string) $row->message_id !== '') {
				$ref = $db->getRow("SELECT rowid FROM ".$db->prefix()."inbox_mailref WHERE entity = ".((int) $conf->entity)." AND message_id = '".$db->escape((string) $row->message_id)."' ORDER BY rowid");
				$mailrefId = is_object($ref) ? (int) $ref->rowid : 0;
			}
		} elseif (!InboxBlockedSender::isManager($user)) {
			// From the settings: the managers of the list only
			inboxAjaxError($langs->transnoentities('InboxBlockedAddNeedRight'), 403);
		}
		$id = $blocked->create($user, $type, $value, $accountId, (string) inboxAjaxParam('confirm'), (string) inboxAjaxParam('note'), $mailrefId);
		if ($id < 0) {
			// A known sender: the strong confirmation asked, with who he is
			inboxAjaxError($blocked->error, $id === -2 ? 403 : 422, $id === -3 ? array('confirm' => $blocked->errorParam) : array());
		}
		// The mails already received, in the inbox of the mailboxes where the user has the level « manage »
		$moved = 0;
		if ((int) inboxAjaxParam('past', 'int') === 1) {
			$entry = $blocked->fetch($id);
			$past = $entry ? $blocked->pastMails($user, (string) $entry['type'], (string) $entry['value']) : array('ids' => array());
			if ($past['ids']) {
				$res = (new InboxActions($db))->run($user, 'junk', $past['ids']);
				$moved = (int) $res['count'];
			}
		}
		$reply = inboxBlockedReply($blocked);
		$reply['id'] = $id;
		$reply['moved'] = $moved;
		inboxAjaxReply($reply);
		// no break

	case 'delete':
		$result = $blocked->delete($user, (int) inboxAjaxParam('id', 'int'));
		if ($result === 0) {
			inboxAjaxNotFound();
		}
		if ($result < 0) {
			inboxAjaxError($blocked->error, 403);
		}
		inboxAjaxReply(inboxBlockedReply($blocked));
		// no break

	default:
		inboxAjaxError('Unknown action', 400);
}
