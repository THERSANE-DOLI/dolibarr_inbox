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
 * \file    inbox/class/inboxmailcontext.class.php
 * \ingroup inbox
 * \brief   The mail open given to a page of a module by a token of one use (marker {ctx})
 */

require_once __DIR__.'/inboxaccess.class.php';
require_once __DIR__.'/inboxmailbuilder.class.php';

/**
 * A page of a module opened from a mail (action of a mail, agenda panel) receives `?inboxctx={ctx}` instead of the
 * subject and the addresses in its address: the token is drawn when the user clicks, kept in his session with the
 * number of the mail only, for 5 minutes and one use. The page reads the mail by take() in PHP: the values are read
 * again in the index, with the rights of the user at that moment. Nothing of the mail in an address, the history of
 * the browser or the logs of the web server.
 */
class InboxMailContext
{
	/** Seconds a token may be used */
	const LIFE = 300;

	/** Tokens kept in a session at most (the oldest goes) */
	const MAX = 20;

	/** Addresses given at most */
	const MAX_EMAILS = 20;

	/** Key of the session */
	const SESSION_KEY = 'inbox_mail_context';

	/**
	 * Token of a mail the user can read, kept in his session
	 *
	 * @param DoliDB $db     Database handler
	 * @param User   $user   User
	 * @param int    $mailId Mail of the index
	 * @return string        Token, '' when the mail is not readable by him
	 */
	public static function make($db, User $user, $mailId)
	{
		global $conf;

		if (self::row($db, $user, (int) $mailId) === null) {
			return '';
		}
		$now = time();
		$kept = array();
		foreach ((array) ($_SESSION[self::SESSION_KEY] ?? array()) as $t => $c) {
			if (is_array($c) && (int) ($c['expires'] ?? 0) > $now) {
				$kept[$t] = $c;
			}
		}
		$token = bin2hex(random_bytes(16));
		$kept[$token] = array('mail' => (int) $mailId, 'user' => (int) $user->id, 'entity' => (int) $conf->entity, 'expires' => $now + self::LIFE);
		$_SESSION[self::SESSION_KEY] = array_slice($kept, -self::MAX, null, true);

		return $token;
	}

	/**
	 * Values of the mail of a token, for the page of a module (once: the token is removed). The rights are checked
	 * again: a mailbox no longer readable, another user, another entity, a token expired give null.
	 *
	 * @param string    $token Token (GETPOST('inboxctx', 'alpha'))
	 * @param User|null $user  User (default: the current one)
	 * @param DoliDB    $db    Database handler (default: the current one)
	 * @return array{mail:int,account:int,subject:string,messageid:string,from:string,emails:string[]}|null
	 */
	public static function take($token, $user = null, $db = null)
	{
		global $conf;

		if ($user === null) {
			$user = $GLOBALS['user'];
		}
		if ($db === null) {
			$db = $GLOBALS['db'];
		}
		$token = (string) $token;
		if (!preg_match('/^[0-9a-f]{32}$/', $token) || !($user instanceof User) || empty($_SESSION[self::SESSION_KEY][$token])) {
			return null;
		}
		$c = $_SESSION[self::SESSION_KEY][$token];
		unset($_SESSION[self::SESSION_KEY][$token]);
		if (!is_array($c) || (int) $c['user'] !== (int) $user->id || (int) $c['entity'] !== (int) $conf->entity || (int) $c['expires'] < time()) {
			return null;
		}
		$row = self::row($db, $user, (int) $c['mail']);
		if ($row === null) {
			return null;
		}

		return array(
			'mail' => (int) $row->rowid,
			'account' => (int) $row->fk_account,
			'subject' => (string) $row->subject,
			'messageid' => trim((string) $row->message_id, " <>"),
			'from' => strtolower(trim((string) $row->from_email)),
			'emails' => self::correspondents($db, $user, $row),
		);
	}

	/**
	 * @param DoliDB $db     Database handler
	 * @param User   $user   User
	 * @param int    $mailId Mail
	 * @return object|null   Row of the index, when its mailbox is readable by the user (not protected: its index is
	 *                       empty, its values are never given)
	 */
	protected static function row($db, User $user, $mailId)
	{
		if ($mailId <= 0) {
			return null;
		}
		$row = $db->getRow("SELECT m.rowid, m.fk_account, m.subject, m.message_id, m.from_email, m.to_text, m.cc_text FROM ".$db->prefix()."inbox_mail as m"
			." INNER JOIN ".$db->prefix()."inbox_account as a ON a.rowid = m.fk_account WHERE m.rowid = ".((int) $mailId)." AND a.protected = 0");
		if (!is_object($row)) {
			return null;
		}
		$access = new InboxAccess($db);

		return $access->check($user, (int) $row->fk_account, InboxAccess::LEVEL_READ) ? $row : null;
	}

	/**
	 * The sender and the recipients, without the mailboxes the user reads (as the marker {emails})
	 *
	 * @param DoliDB $db   Database handler
	 * @param User   $user User
	 * @param object $row  Row of the index
	 * @return string[]
	 */
	protected static function correspondents($db, User $user, $row)
	{
		$own = array();
		$ids = array_map('intval', array_keys((new InboxAccess($db))->getLevels($user)));
		if ($ids) {
			$rows = $db->getRows("SELECT email FROM ".$db->prefix()."inbox_account WHERE rowid IN (".implode(',', $ids).") LIMIT 10000");
			foreach (is_array($rows) ? $rows : array() as $r) {
				$own[strtolower(trim((string) $r->email))] = true;
			}
		}
		$all = array(strtolower(trim((string) $row->from_email)));
		foreach (array((string) $row->to_text, (string) $row->cc_text) as $text) {
			foreach (InboxMailBuilder::parseAddresses($text) as $a) {
				$all[] = strtolower($a['email']);
			}
		}
		$out = array();
		foreach ($all as $e) {
			if ($e !== '' && strpos($e, '@') > 0 && !isset($own[$e]) && !in_array($e, $out, true)) {
				$out[] = $e;
			}
		}

		return array_slice($out, 0, self::MAX_EMAILS);
	}
}
