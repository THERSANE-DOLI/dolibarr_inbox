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
 * \file    inbox/ajax/source.php
 * \ingroup inbox
 * \brief   Settings > Data sources (lot A2 level 1, 1.72.0): list, add, change, remove, test on pasted headers;
 *          admins of Dolibarr only (a source decides who the requester of a mail is)
 */

require __DIR__.'/inbox_ajax.inc.php';
dol_include_once('/inbox/class/inboxdatasource.class.php');
dol_include_once('/inbox/class/inboxknownsenders.class.php');

$action = (string) inboxAjaxParam('action', 'aZ09');
inboxAjaxStart(in_array($action, array('save', 'delete'), true));
if (empty($user->admin)) {
	inboxAjaxNotFound();
}
$sources = new InboxDataSource($db);

/**
 * Values of a source sent by the form
 *
 * @return array<string,mixed>
 */
function inboxSourceInput()
{
	return array(
		'label' => (string) inboxAjaxParam('label'), 'status' => (int) inboxAjaxParam('status', 'int'), 'senders' => (string) inboxAjaxParam('senders'),
		'header_email' => (string) inboxAjaxParam('header_email'), 'header_data' => (string) inboxAjaxParam('header_data'),
		'header_fields' => (string) inboxAjaxParam('header_fields'), 'mapping' => (string) inboxAjaxParam('mapping'), 'type' => (string) inboxAjaxParam('type', 'aZ09'),
		// Signature (1.143.0): the key is only received, never sent back; empty keeps the saved one
		'header_signature' => (string) inboxAjaxParam('header_signature'), 'signature_key' => (string) inboxAjaxParam('signature_key', 'none'),
		'signature_clear' => (int) inboxAjaxParam('signature_clear', 'int'),
	);
}

/**
 * @param InboxDataSource $sources Helper
 * @return array<string,mixed>
 */
function inboxSourceReply(InboxDataSource $sources)
{
	return array('sources' => $sources->fetchAll(), 'fields' => InboxDataSource::FIELDS, 'types' => InboxDataSource::TYPES,
		// Glossary and ready-made sources of the form (1.74.0)
		'glossary' => InboxDataSource::glossary(), 'templates' => InboxDataSource::TEMPLATES,
		// Without the receiving servers of the setup, the authentication read is the one of the topmost header
		'auth_servers' => count(InboxKnownSenders::trustedServers()));
}

switch ($action) {
	case 'list':
		inboxAjaxReply(inboxSourceReply($sources));
		// no break

	case 'save':
		if ($sources->save($user, (int) inboxAjaxParam('source_id', 'int'), inboxSourceInput()) < 0) {
			inboxAjaxError($sources->error, 422);
		}
		inboxAjaxReply(inboxSourceReply($sources));
		// no break

	case 'delete':
		if ($sources->delete((int) inboxAjaxParam('source_id', 'int')) < 0) {
			inboxAjaxError($db->lasterror(), 500);
		}
		inboxAjaxReply(inboxSourceReply($sources));
		// no break

	case 'test':
		// The source of the form (saved or not) on the headers of a mail pasted by the admin; nothing is written
		$in = inboxSourceInput();
		$headers = InboxReader::headers(substr((string) inboxAjaxParam('headers', 'none'), 0, 262144)."\n\n");
		$from = InboxSenderAuth::analyzeFrom(InboxSenderAuth::parseHeaders(implode("\r\n", array_map(function ($h) {
			return $h[0].': '.$h[1];
		}, $headers))));
		// The key typed in the form, else the one saved for this source (read on the server, never sent back)
		$key = trim($in['signature_key']);
		if ($key === '' && empty($in['signature_clear']) && (int) inboxAjaxParam('source_id', 'int') > 0) {
			$key = $sources->signatureKeyOf((int) inboxAjaxParam('source_id', 'int'));
		}
		$source = array('id' => 0, 'label' => $in['label'], 'type' => $in['type'], 'senders' => $in['senders'], 'header_email' => trim($in['header_email']),
			'header_data' => trim($in['header_data']), 'header_signature' => trim($in['header_signature']), 'signature_key' => $key,
			'header_fields' => $in['header_fields'], 'mapping' => $in['mapping']);
		$found = $sources->detect($headers, (string) $from['address'], array($source));
		inboxAjaxReply(array('from' => (string) $from['address'], 'result' => $found));
		// no break

	case 'analyze':
		// « Make the source from a mail » (1.74.0): a mail of the index the admin may read, or pasted headers
		$mailId = (int) inboxAjaxParam('mail', 'int');
		if ($mailId > 0) {
			$reader = new InboxReader($db);
			if (!$reader->load($user, $mailId)) {
				inboxAjaxNotFound();
			}
			$raw = $reader->raw($user);
			if ($raw === null) {
				inboxAjaxError($langs->transnoentities('InboxMailNotFound'), 404);
			}
			$headers = InboxReader::headers($raw);
		} else {
			$headers = InboxReader::headers(substr((string) inboxAjaxParam('headers', 'none'), 0, 262144)."\n\n");
		}
		inboxAjaxReply(InboxDataSource::analyze($headers));
		// no break

	default:
		inboxAjaxNotFound();
}
