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
 * \file    inbox/ajax/avatar.php
 * \ingroup inbox
 * \brief   Picture of the sender of a mail of the index (204 without picture: the application shows the initials)
 *
 * Asked by the row of the mail, never by the address (no address in an URL). reading=1: head of the reading pane,
 * BIMI allowed when this mail passed DMARC (read in the raw mail kept in the cache) and its sender is known: domain
 * recognized, or thirdparty linked to the address (1.88.6: a lookalike domain may publish the logo of another company).
 */

require __DIR__.'/inbox_ajax.inc.php';
dol_include_once('/inbox/class/inboxreader.class.php');
dol_include_once('/inbox/class/inboxavatar.class.php');

inboxAjaxStart(false);
session_write_close();
// Protected mailbox (1.89.0): no picture looked for (one connection to the mail server per row of the list), the
// page shows the initials
if (InboxLive::isMail(GETPOSTINT('id'))) {
	http_response_code(404);
	exit;
}
$reader = new InboxReader($db);
if (!$reader->load($user, GETPOSTINT('id'))) {
	http_response_code(404);
	exit;
}
$bimi = false;
if (GETPOSTINT('reading') === 1 && (string) $reader->row->message_id !== '') {
	$inbox = new Inbox($db);
	$raw = $inbox->cachedRaw($reader->account->email, (string) $reader->row->message_id);
	// The cache only when it is this very mail (audit INBX-012: a Message-ID may be copied by a sender)
	if ($raw !== null && ((int) $reader->row->size <= 0 || strlen($raw) === (int) $reader->row->size)) {
		$auth = InboxReader::authResults(InboxReader::headers($raw));
		// The logo of a brand only when our own server checked the mail (audit INBX-022)
		// DMARC of the domain of the sender shown, never another one (audit INBX-050)
		$bimi = $auth['dmarc'] === 'pass' && InboxReader::authPass($auth, (string) $reader->row->from_email);
	}
	if ($bimi && (int) $reader->row->fk_soc <= 0) {
		dol_include_once('/inbox/class/inboxtrusteddomain.class.php');
		$host = InboxTrustedDomain::normalize((string) $reader->row->from_email);
		$bimi = $host !== '' && (new InboxTrustedDomain($db))->find($host) !== null;
	}
}
$avatar = new InboxAvatar($db);
$pic = $avatar->get((string) $reader->row->from_email, (int) $reader->row->fk_soc, $bimi);
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');
if (!$pic) {
	// No picture: an empty answer (the application shows the initials), not an error in the console of the browser
	http_response_code(204);
	exit;
}
header('Content-Type: '.$pic['type']);
// An SVG opened alone runs no script and loads nothing
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src data:; sandbox");
header('Content-Length: '.strlen($pic['data']));
print $pic['data'];
