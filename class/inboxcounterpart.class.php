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
 * \file    inbox/class/inboxcounterpart.class.php
 * \ingroup inbox
 * \brief   The other side of a mail and its thirdparty: shared by the Dolibarr panel and the overview (1.153.0)
 */

require_once __DIR__.'/inboxcited.class.php';
require_once __DIR__.'/inboxcontactmatcher.class.php';

/**
 * Who a mail is with, read from the index only (never from a parameter of the browser)
 */
class InboxCounterpart
{
	/**
	 * The other side of a mail for its thirdparty (1.92.2): the requester of a data source, else the sender, else when
	 * the sender is one of us (the mailbox, one of its identities or a domain of our mailboxes) the first recipient (to,
	 * then cc) outside of us, preferably one known in Dolibarr; '' for a mail between us
	 *
	 * @param DoliDB $db  Database handler
	 * @param object $row Row of the index (from_email, to_text, cc_text, requester_email, fk_account)
	 * @return array{email:string,side:string} side: sender, recipient or internal
	 */
	public static function of(DoliDB $db, $row)
	{
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

	/**
	 * Thirdparty and contact of a mail: the ones linked to the mail in the index, else the ones of the address of the
	 * other side, else the thirdparty of its domain (shared domains apart, InboxContactMatcher)
	 *
	 * @param DoliDB $db    Database handler
	 * @param object $row   Row of the index (fk_soc, fk_socpeople)
	 * @param string $email Address of the other side (of())
	 * @return array{soc:int,contact:int,by:string} by: '' (none), address or domain
	 */
	public static function thirdparty(DoliDB $db, $row, $email)
	{
		$socId = (int) ($row->fk_soc ?? 0);
		$contactId = (int) ($row->fk_socpeople ?? 0);
		$by = '';
		if (!$socId && (string) $email !== '') {
			$matcher = new InboxContactMatcher($db);
			$m = $matcher->match($email);
			$socId = (int) $m['fk_soc'];
			$contactId = $contactId ?: (int) $m['fk_socpeople'];
			if (!$socId) {
				$socId = (int) $matcher->matchDomain($email);
				$by = $socId ? 'domain' : '';
			}
		}

		return array('soc' => $socId, 'contact' => $contactId, 'by' => $by ?: ($socId || $contactId ? 'address' : ''));
	}
}
