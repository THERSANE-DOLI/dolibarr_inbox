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
 * \file    inbox/ajax/inbox_ajax.inc.php
 * \ingroup inbox
 * \brief   Common start of the endpoints of inbox: JSON only, session expired = 401 (never the login page), CSRF token
 *          on every write, right of Dolibarr checked, a refused mailbox looks like a missing one (404)
 */

if (!defined('NOTOKENRENEWAL')) {
	define('NOTOKENRENEWAL', '1');
}
if (!defined('NOREQUIREMENU')) {
	define('NOREQUIREMENU', '1');
}
if (!defined('NOREQUIREHTML')) {
	define('NOREQUIREHTML', '1');
}
if (!defined('NOREQUIREAJAX')) {
	define('NOREQUIREAJAX', '1');
}
if (!defined('NOREDIRECTBYMAINTOLOGIN')) {
	define('NOREDIRECTBYMAINTOLOGIN', '1');
}
// The CSRF check of main.inc.php answers with an HTML page: inbox checks the token itself and answers in JSON
if (!defined('NOCSRFCHECK')) {
	define('NOCSRFCHECK', '1');
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

$res = 0;
if (!empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
if (!$res && file_exists(__DIR__."/../../main.inc.php")) {
	$res = @include __DIR__."/../../main.inc.php";
}
if (!$res && file_exists(__DIR__."/../../../main.inc.php")) {
	$res = @include __DIR__."/../../../main.inc.php";
}
if ($res === 'ERROR_NOT_LOGGED') {
	http_response_code(401);
	print json_encode(array('error' => 'session_expired'));
	exit;
}
if (!$res) {
	http_response_code(500);
	print json_encode(array('error' => 'Include of main fails'));
	exit;
}

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array('inbox@inbox'));
dol_include_once('/inbox/class/inboxaccess.class.php');

/**
 * Version of inbox on the server, read from its descriptor (the same as the one given to the page)
 *
 * @return string
 */
function inboxAjaxVersion()
{
	$text = (string) @file_get_contents(dirname(__DIR__).'/core/modules/modInbox.class.php', false, null, 0, 8192);

	return preg_match("/->version\s*=\s*'([^']+)'/", $text, $m) ? $m[1] : '';
}

// In every answer: a page loaded before an update of inbox (window left open) offers to reload (1.58.0)
if (!headers_sent()) {
	header('X-Inbox-Version: '.inboxAjaxVersion());
}

/**
 * Answer and stop
 *
 * @param array<string,mixed>|list<mixed> $data Data
 * @param int                             $code HTTP status
 * @return never
 */
function inboxAjaxReply($data, $code = 200)
{
	http_response_code($code);
	print json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	exit;
}

/**
 * Answer an error and stop: message for the user (already translated) and field errors
 *
 * @param string               $message Message
 * @param int                  $code    HTTP status
 * @param array<string,string> $fields  Field => message
 * @return never
 */
function inboxAjaxError($message, $code = 400, $fields = array())
{
	inboxAjaxReply(array('error' => $message, 'fields' => (object) $fields), $code);
}

/**
 * Not found, also for a refused mailbox (its existence is not revealed)
 *
 * @return never
 */
function inboxAjaxNotFound()
{
	global $langs;

	inboxAjaxError($langs->transnoentities('InboxErrorAccountNotFound'), 404);
}

/**
 * Translate a list of language keys of errors
 *
 * @param string[] $keys Keys
 * @return string
 */
function inboxAjaxTransErrors($keys)
{
	global $langs;

	return implode(' ', array_map(function ($k) use ($langs) {
		return $langs->transnoentities((string) $k);
	}, (array) $keys));
}

/**
 * Data posted as JSON (body) or as a form
 *
 * @return array<string,mixed>
 */
function inboxAjaxInput()
{
	static $input = null;

	if ($input !== null) {
		return $input;
	}
	$input = array();
	$type = isset($_SERVER['CONTENT_TYPE']) ? (string) $_SERVER['CONTENT_TYPE'] : '';
	if (stripos($type, 'application/json') !== false) {
		$data = json_decode((string) file_get_contents('php://input'), true);
		$input = is_array($data) ? $data : array();
	}

	return $input;
}

/**
 * The value was sent (JSON body, GET or POST): a field not sent keeps its value
 *
 * @param string $key Key
 * @return bool
 */
function inboxAjaxHas($key)
{
	return array_key_exists($key, inboxAjaxInput()) || GETPOSTISSET($key);
}

/**
 * One value of the input: JSON body first, else GET / POST, cleaned as asked
 *
 * @param string $key    Key
 * @param string $filter alphanohtml, int, email, restricthtml, none (raw, for passwords: never printed)
 * @return mixed
 */
function inboxAjaxParam($key, $filter = 'alphanohtml')
{
	$input = inboxAjaxInput();
	if (array_key_exists($key, $input)) {
		$value = $input[$key];
		if ($filter === 'int') {
			return (int) $value;
		}
		if (is_array($value)) {
			return $value;
		}
		if ($filter === 'none') {
			return (string) $value;
		}
		if ($filter === 'restricthtml') {
			return dol_htmlwithnojs((string) $value);
		}
		return trim(dol_string_nohtmltag((string) $value, 1));
	}
	if ($filter === 'int') {
		return GETPOSTINT($key);
	}
	if ($filter === 'none') {
		return isset($_POST[$key]) ? (string) $_POST[$key] : '';
	}

	return GETPOST($key, $filter === 'email' ? 'alphanohtml' : $filter);
}

/**
 * Start of every endpoint: module enabled, webmail right, internal user; for a write: POST and the CSRF token
 *
 * @param bool $write The action writes something
 * @return void
 */
function inboxAjaxStart($write)
{
	global $user, $langs;

	if (!isModEnabled('inbox') || empty($user->id) || !empty($user->socid) || !$user->hasRight('inbox', 'webmail', 'use')) {
		inboxAjaxError($langs->transnoentities('InboxErrorForbidden'), 403);
	}
	if ($write) {
		if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
			inboxAjaxError('POST required', 405);
		}
		$token = (string) inboxAjaxParam('token', 'alphanohtml');
		if ($token === '' || !hash_equals((string) currentToken(), $token)) {
			// The page asks a new token (ajax/session.php) and sends again
			inboxAjaxError('bad_token', 403);
		}
	}
}

/**
 * The user manages the mailboxes (right of Dolibarr), else 404
 *
 * @return void
 */
function inboxAjaxNeedAdmin()
{
	global $user;

	if (!$user->hasRight('inbox', 'account', 'admin')) {
		inboxAjaxNotFound();
	}
}

/**
 * Load a mailbox the user can manage: admin of the mailboxes, or level manage on it ($adminOnly: admin only).
 * Anything else answers 404.
 *
 * @param int  $id        Mailbox
 * @param bool $adminOnly Only the admins of the mailboxes (server, accesses, status)
 * @return InboxAccount
 */
function inboxAjaxManagedAccount($id, $adminOnly = false)
{
	global $db, $user;

	$account = new InboxAccount($db);
	if ((int) $id <= 0 || $account->fetch((int) $id) <= 0) {
		inboxAjaxNotFound();
	}
	if ($user->hasRight('inbox', 'account', 'admin')) {
		return $account;
	}
	$access = new InboxAccess($db);
	if ($adminOnly || !$access->check($user, (int) $account->id, InboxAccess::LEVEL_MANAGE)) {
		inboxAjaxNotFound();
	}

	return $account;
}
