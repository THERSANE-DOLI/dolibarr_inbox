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
 * \file    inbox/ajax/dmarc.php
 * \ingroup inbox
 * \brief   DMARC aggregate reports (1.111.0), for the dialog of the reading
 *
 * action = analyze (id, part): the attachment of a mail the user may read, unpacked and read on the server, nothing
 *          stored; an attachment found infected by the antivirus is refused;
 *          dns (domain, selectors): checks of the current DMARC, SPF and DKIM records of a domain;
 *          ptr (ip): name of an IP.
 * The DNS queries are counted per user and per hour (InboxDmarc::DNS_PER_HOUR).
 */

require __DIR__.'/inbox_ajax.inc.php';
require_once __DIR__.'/inbox_attserve.inc.php';
dol_include_once('/inbox/class/inboxreader.class.php');
dol_include_once('/inbox/class/inboxdmarc.class.php');

inboxAjaxStart(false);
$action = (string) inboxAjaxParam('action', 'aZ09');

switch ($action) {
	case 'analyze':
		$reader = new InboxReader($db);
		if (!$reader->load($user, (int) inboxAjaxParam('id', 'int'))) {
			inboxAjaxError($langs->transnoentities('InboxMailNotFound'), 404);
		}
		$raw = $reader->raw($user);
		if ($raw === null) {
			inboxAjaxError($langs->transnoentities($reader->errorCode === 'gone' ? 'InboxMailGone' : 'InboxMailReadError', $reader->error), $reader->errorCode === 'gone' ? 410 : 502);
		}
		$message = InboxMimeParser::parse($raw);
		$part = (int) inboxAjaxParam('part', 'int');
		$a = $message->attachments[$part] ?? null;
		if (!is_array($a) || !empty($a['inline']) || !empty($a['embedded'])) {
			inboxAjaxError($langs->transnoentities('InboxMailNotFound'), 404);
		}
		$scan = new InboxAttachmentScan($db);
		$scan->keep = !$reader->isLive();
		$v = inboxAttVerdict($scan, $a);
		if ($v['blocked']) {
			inboxAjaxError($langs->transnoentities('InboxAttInfected', $v['message']), 403, array('state' => 'infected'));
		}
		$result = InboxDmarc::analyze((string) $a['name'], (string) $a['content'], InboxDmarc::oursDomains($db));
		if (isset($result['error'])) {
			inboxAjaxError($langs->transnoentities($result['error']), 422);
		}
		// A report can be forged: said when its sender is not proven (DMARC or DKIM of its domain)
		$result['proven'] = InboxReader::authPass(InboxReader::authResults(InboxReader::headers($raw)), (string) $message->fromEmail);
		$result['name'] = InboxSafe::line((string) $a['name'], 255);
		// The domains of the report may be checked in the DNS (signed for this user), no other one
		$result['dns_sign'] = array();
		foreach ($result['reports'] as $rep) {
			$d = InboxDmarc::publicDomainOrEmpty((string) ($rep['policy']['domain'] ?? ''));
			if ($d !== '') {
				$result['dns_sign'][$d] = InboxDmarc::domainSignature((int) $user->id, $d);
			}
		}
		$result['dns_sign'] = (object) $result['dns_sign'];
		inboxAjaxReply($result);
		// no break

	case 'dns':
		$domain = InboxDmarc::publicDomainOrEmpty((string) inboxAjaxParam('domain', 'alphanohtml'));
		// A domain of a report the user analyzed only (signed by « analyze »): never a domain typed
		if ($domain === '' || !InboxDmarc::domainSigned((int) $user->id, $domain, (string) inboxAjaxParam('sign', 'alphanohtml'))) {
			inboxAjaxError($langs->transnoentities('InboxDmarcBadDomain'), 422);
		}
		$selectors = array_slice(array_filter(array_map('trim', explode(',', (string) inboxAjaxParam('selectors', 'alphanohtml')))), 0, 10);
		// The worst case taken before the queries (DMARC and its organisation, external destinations, SPF, selectors)
		if (!InboxDmarc::dnsAllowed((int) $user->id, InboxDmarc::dnsCost(count($selectors)))) {
			inboxAjaxError($langs->transnoentities('InboxDmarcDnsLimit'), 429);
		}
		inboxAjaxReply(array('domain' => $domain) + InboxDmarc::dnsChecks($domain, $selectors));
		// no break

	case 'ptr':
		$ip = (string) inboxAjaxParam('ip', 'alphanohtml');
		if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
			inboxAjaxError($langs->transnoentities('InboxDmarcBadIp'), 422);
		}
		if (!InboxDmarc::dnsAllowed((int) $user->id, 1)) {
			inboxAjaxError($langs->transnoentities('InboxDmarcDnsLimit'), 429);
		}
		inboxAjaxReply(array('ip' => $ip, 'name' => (string) InboxDmarc::ptr($ip)));
		// no break

	default:
		inboxAjaxNotFound();
}
