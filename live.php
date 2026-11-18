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
 * \file    inbox/live.php
 * \ingroup inbox
 * \brief   Light call of the journal of the changes (1.121.0): « anything new for me since event N? »
 *
 * Called every few seconds by one tab per browser, so it never starts Dolibarr: no session, no user, no constants,
 * no module. It reads conf.php, checks the token signed by ajax/event.php (user, entity, mailboxes he can read, end
 * of life) with the key of the instance, and reads the events of these mailboxes only. Numbers only come back.
 *
 * GET since=<last event seen>, header X-Inbox-Live: <token> (never in the address: it would be in the logs).
 * 200 {last, events, reset, renew} | 204 nothing new | 401 token refused.
 */

// filefunc.inc.php: conf.php, the database settings and the common functions, nothing else
$res = 0;
if (!empty($_SERVER['CONTEXT_DOCUMENT_ROOT']) && file_exists($_SERVER['CONTEXT_DOCUMENT_ROOT'].'/filefunc.inc.php')) {
	$res = @include_once $_SERVER['CONTEXT_DOCUMENT_ROOT'].'/filefunc.inc.php';
}
if (!$res && file_exists(__DIR__.'/../../filefunc.inc.php')) {
	$res = @include_once __DIR__.'/../../filefunc.inc.php';
}
if (!$res && file_exists(__DIR__.'/../../../filefunc.inc.php')) {
	$res = @include_once __DIR__.'/../../../filefunc.inc.php';
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

/**
 * @param int                 $code HTTP code
 * @param array<string,mixed> $data Answer
 * @return never
 */
function inboxLiveReply($code, $data = array())
{
	http_response_code($code);
	if ($code !== 204) {
		print json_encode($data);
	}
	exit;
}

if (!$res || !defined('DOL_DOCUMENT_ROOT')) {
	inboxLiveReply(500, array('error' => 'setup'));
}
require_once DOL_DOCUMENT_ROOT.'/core/class/conf.class.php';
require_once __DIR__.'/class/inboxevent.class.php';

/**
 * @var string $dolibarr_main_db_type
 * @var string $dolibarr_main_db_host
 * @var string $dolibarr_main_db_user
 * @var string $dolibarr_main_db_pass
 * @var string $dolibarr_main_db_name
 * @var int    $dolibarr_main_db_port
 * @var string $dolibarr_main_db_prefix
 * @var string $dolibarr_main_db_character_set
 * @var string $dolibarr_main_db_collation
 * @var ?string $dolibarr_main_instance_unique_id
 * @var ?string $dolibarr_main_cookie_cryptkey
 */
$key = InboxEvent::key(empty($dolibarr_main_instance_unique_id) ? (empty($dolibarr_main_cookie_cryptkey) ? '' : $dolibarr_main_cookie_cryptkey) : $dolibarr_main_instance_unique_id);
$now = time();
// The token first: a call without a valid token never opens the database
$token = InboxEvent::verify((string) ($_SERVER['HTTP_X_INBOX_LIVE'] ?? ''), $key, $now);
if ($token === null) {
	inboxLiveReply(401, array('error' => 'token'));
}

// The database handler of Dolibarr, without the constants nor the modules (the driver reads the charset in $conf)
$conf = new Conf();
$conf->db->type = $dolibarr_main_db_type;
$conf->db->prefix = $dolibarr_main_db_prefix;
$conf->db->character_set = $dolibarr_main_db_character_set;
$conf->db->dolibarr_main_db_collation = $dolibarr_main_db_collation;
$db = getDoliDBInstance($dolibarr_main_db_type, $dolibarr_main_db_host, $dolibarr_main_db_user, $dolibarr_main_db_pass, $dolibarr_main_db_name, (int) $dolibarr_main_db_port);
if (empty($db->connected)) {
	inboxLiveReply(503, array('error' => 'database'));
}

$since = isset($_GET['since']) ? max(0, (int) $_GET['since']) : 0;
$out = InboxEvent::since($db, $token['e'], $token['u'], $token['a'], $since);
$db->close();
// Less than 3 minutes left: the tab asks a new token (ajax/event.php, which checks the rights again)
$out['renew'] = $token['x'] - $now < 180;
if ($since > 0 && !$out['events'] && !$out['reset'] && !$out['renew'] && $out['last'] === $since) {
	inboxLiveReply(204);
}
inboxLiveReply(200, $out);
