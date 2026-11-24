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
 * \file    inbox/class/inboxdolibarrsent.class.php
 * \ingroup inbox
 * \brief   Copy in Sent of the mails Dolibarr sends itself from the address of a mailbox (1.132.0)
 */

require_once __DIR__.'/inboxaccount.class.php';
require_once __DIR__.'/connector/inboxconnectorfactory.class.php';

/**
 * Dolibarr sends its own mails with CMailFile (answers of tickets, proposals and invoices sent by mail, notifications...)
 * through its own sending server: the mailbox of the sender never gets them. Called by the hook sendMailAfter (context
 * mail) once a sending worked, this class files the source of the mail, marked read, in the Sent folder of the active
 * mailbox of the current entity whose address is the From sent, as inbox does for its own mails: same Message-ID, so
 * the answers join the conversation. The mail answered (In-Reply-To) gets \Answered, on the server then in the index.
 *
 * The mailbox is the one whose address is the From, else the only one that has it as an active identity (1.133.0):
 * a sender profile of Dolibarr sending from an alias of a mailbox. An identity of several mailboxes files nothing
 * (never a guess). When MAIN_MAIL_FORCE_FROM replaces the From, the address chosen by the user is the Reply-To that
 * CMailFile keeps: it is looked up first, then the forced From.
 *
 * Never for the mass emailing (one connection per recipient) nor for the new passwords (a secret in a mailbox read by
 * several people), nor when the mailbox turned it off (card of the mailbox, on by default), nor when the sending
 * server of Dolibarr files the copy itself. A protected mailbox gets it only from a session that unlocked it.
 *
 * Done during the sending, in the request that sent: the copy is in Sent when the user opens inbox, a protected
 * mailbox can be written only there, and no queue is needed. One connection per mailbox and per request (a list of
 * pieces sent by mail at once logs in once). The sending never waits for an error of the copy: written in the log
 * of Dolibarr only. The index gets the copy at the next synchronisation; its conversation is found in any order.
 */
class InboxDolibarrSent
{
	/** Contexts of CMailFile never filed: mass emailing, new password (or the link to choose it) */
	const EXCLUDED_CONTEXTS = array('emailing', 'passwordreset');

	/** @var DoliDB */
	protected $db;

	/** @var string Result of the last call: filed, excluded, no_from, not_managed, ambiguous, off, protected, server_copy, no_source, no_folder, failed */
	public $result = '';

	/** @var string Detail of the result (never a secret) */
	public $error = '';

	/** @var array<int,array{main:array<string,int>,identity:array<string,int[]>}> Active mailboxes per entity: address => id, identity address => ids, read once per request */
	protected static $addresses = array();

	/** @var array<int,InboxConnectorInterface> Connectors opened during the request, one per mailbox */
	protected static $connectors = array();

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct(DoliDB $db)
	{
		$this->db = $db;
	}

	/**
	 * Forget the mailboxes read and close the connections of the request (tests, mailbox changed meanwhile)
	 *
	 * @return void
	 */
	public static function reset()
	{
		foreach (self::$connectors as $connector) {
			$connector->disconnect();
		}
		self::$connectors = array();
		self::$addresses = array();
	}

	/**
	 * File the copy of a mail just sent by Dolibarr
	 *
	 * @param CMailFile $mail Mail sent (sendfile() worked)
	 * @return string Result (see $result)
	 */
	public function file($mail)
	{
		$this->error = '';
		$why = self::excluded($mail);
		if ($why !== '') {
			return $this->done('excluded', $why);
		}
		$candidates = self::candidateAddresses($mail);
		if (!$candidates) {
			return $this->done('no_from');
		}
		$id = 0;
		$from = $candidates[0];
		foreach ($candidates as $address) {
			$id = $this->accountIdOf($address);
			if ($id !== 0) {
				$from = $address;
				break;
			}
		}
		if ($id < 0) {
			return $this->done('ambiguous', $from);
		}
		$account = new InboxAccount($this->db);
		if ($id <= 0 || $account->fetch($id) <= 0 || !$account->isActive()) {
			return $this->done('not_managed');
		}
		if (!$account->filesDolibarrSent()) {
			return $this->done('off', $from);
		}
		// Protected mailbox: only while this request has it unlocked; never one protected with its content
		if (!$account->canWorkNow()) {
			return $this->done('protected', $from);
		}
		$host = self::hostOf($mail);
		if (self::copyBySendingServer($account, $host)) {
			return $this->done('server_copy', $from.' '.$host);
		}
		$raw = self::rawOf($mail);
		if ($raw === '') {
			return $this->done('no_source', (string) $mail->sendmode);
		}
		require_once __DIR__.'/inboxcomposer.class.php';
		$composer = new InboxComposer($this->db);
		$folder = $composer->roleFolder($account, 'sent');
		if ($folder === '') {
			return $this->done('no_folder', $from);
		}
		$reused = isset(self::$connectors[(int) $account->id]);
		$connector = $this->connector($account);
		if (!$connector) {
			return $this->done('failed', $this->error);
		}
		$copy = $connector->importRaw($folder, $raw, array('\\Seen'));
		if ($copy === null && $reused) {
			// A connection kept from a previous mail of the request may have been closed by the server: once again
			$this->forget((int) $account->id);
			$connector = $this->connector($account);
			$copy = $connector ? $connector->importRaw($folder, $raw, array('\\Seen')) : null;
		}
		if ($copy === null) {
			$detail = $connector ? $connector->getError() : $this->error;
			$this->forget((int) $account->id);
			return $this->done('failed', $from.' '.$detail);
		}
		$this->markAnswered($account, $connector, $raw);

		return $this->done('filed', $from.' '.$folder);
	}

	/**
	 * @param string $result Result
	 * @param string $detail Detail for the log
	 * @return string Result
	 */
	protected function done($result, $detail = '')
	{
		$this->result = $result;
		if ($detail !== '') {
			$this->error = $detail;
		}
		$level = $result === 'failed' || $result === 'no_folder' || $result === 'no_source' ? LOG_WARNING : LOG_DEBUG;
		dol_syslog('InboxDolibarrSent '.$result.($detail !== '' ? ' '.$detail : ''), $level);

		return $result;
	}

	/**
	 * @param CMailFile $mail Mail
	 * @return string Reason when the mail is never filed (context), '' otherwise
	 */
	public static function excluded($mail)
	{
		$context = strtolower((string) $mail->sendcontext);
		if (in_array($context, self::EXCLUDED_CONTEXTS, true)) {
			return $context;
		}
		// Mass emailing sent by another path than its context
		if (strpos((string) $mail->trackid, 'emailing-') === 0) {
			return 'emailing';
		}

		return '';
	}

	/**
	 * @param string $from From of CMailFile ('Name <a@b.c>' or 'a@b.c')
	 * @return string Address sent, lower case, '' if none
	 */
	public static function fromAddress($from)
	{
		$from = trim((string) $from);
		if (preg_match('/<([^<>\s]+@[^<>\s]+)>/', $from, $m)) {
			$from = $m[1];
		}
		$from = strtolower(trim($from));

		return isValidEmail($from) ? $from : '';
	}

	/**
	 * Addresses that may designate the mailbox of the sender, in the order they are looked up
	 *
	 * @param CMailFile $mail Mail
	 * @return string[] Lower case addresses; the From alone, or the Reply-To then the From when MAIN_MAIL_FORCE_FROM
	 *                  replaced it; empty when the From is not an address
	 */
	public static function candidateAddresses($mail)
	{
		$from = self::fromAddress((string) $mail->addr_from);
		if ($from === '') {
			return array();
		}
		$forced = self::fromAddress(getDolGlobalString('MAIN_MAIL_FORCE_FROM'));
		if ($forced !== '' && $forced === $from) {
			// CMailFile keeps the From asked as Reply-To when none was given: the identity chosen by the user
			$reply = self::firstAddress((string) $mail->reply_to);
			if ($reply !== '' && $reply !== $from) {
				return array($reply, $from);
			}
		}

		return array($from);
	}

	/**
	 * @param string $list Addresses separated by commas ('Name <a@b.c>, d@e.f')
	 * @return string First address, lower case, '' if none
	 */
	protected static function firstAddress($list)
	{
		// A name holding a comma (« Doe, John <a@b.c> ») gives no address: the From is then looked up alone
		return self::fromAddress((string) strtok(trim((string) $list), ','));
	}

	/**
	 * @param string $email Address, lower case
	 * @return int Active mailbox of the current entity with this address, else the only one having it as an active
	 *             identity; 0 if none, -1 when several mailboxes have this identity
	 */
	protected function accountIdOf($email)
	{
		global $conf;

		$entity = (int) $conf->entity;
		if (!isset(self::$addresses[$entity])) {
			self::$addresses[$entity] = array('main' => array(), 'identity' => array());
			$p = $this->db->prefix();
			$rows = $this->db->getRows("SELECT rowid, email FROM ".$p."inbox_account WHERE entity = ".$entity." AND status = ".InboxAccount::STATUS_ACTIVE." LIMIT 5000");
			foreach (is_array($rows) ? $rows : array() as $r) {
				self::$addresses[$entity]['main'][strtolower(trim((string) $r->email))] = (int) $r->rowid;
			}
			$sql = "SELECT i.fk_account, i.email FROM ".$p."inbox_identity as i INNER JOIN ".$p."inbox_account as a ON a.rowid = i.fk_account";
			$sql .= " WHERE a.entity = ".$entity." AND a.status = ".InboxAccount::STATUS_ACTIVE." AND i.status = 1 LIMIT 20000";
			$rows = $this->db->getRows($sql);
			foreach (is_array($rows) ? $rows : array() as $r) {
				$key = strtolower(trim((string) $r->email));
				$ids = self::$addresses[$entity]['identity'][$key] ?? array();
				if (!in_array((int) $r->fk_account, $ids, true)) {
					$ids[] = (int) $r->fk_account;
				}
				self::$addresses[$entity]['identity'][$key] = $ids;
			}
		}
		if (isset(self::$addresses[$entity]['main'][$email])) {
			return self::$addresses[$entity]['main'][$email];
		}
		$ids = self::$addresses[$entity]['identity'][$email] ?? array();

		return count($ids) === 1 ? $ids[0] : (count($ids) > 1 ? -1 : 0);
	}

	/**
	 * @param CMailFile $mail Mail
	 * @return string Sending server used by Dolibarr, '' for the mail() function of PHP (server of the system)
	 */
	public static function hostOf($mail)
	{
		$host = '';
		if ($mail->sendmode === 'smtps' && is_object($mail->smtps) && method_exists($mail->smtps, 'getHost')) {
			$host = (string) $mail->smtps->getHost();
		} elseif ($mail->sendmode === 'swiftmailer' && is_object($mail->transport) && method_exists($mail->transport, 'getHost')) {
			$host = (string) $mail->transport->getHost();
		}

		return self::bareHost($host);
	}

	/**
	 * @param string $host Host, maybe with its scheme (ssl://smtp.example.com)
	 * @return string Host name, lower case
	 */
	protected static function bareHost($host)
	{
		return strtolower(trim((string) preg_replace('/^[a-z]+:\/\//i', '', trim((string) $host)), " .[]"));
	}

	/**
	 * The sending server of Dolibarr files the copy in Sent itself: one known to do it (Gmail, Microsoft 365), or the
	 * sending server of the mailbox when its card says that this server makes the copy
	 *
	 * @param InboxAccount $account Mailbox
	 * @param string       $host    Sending server used by Dolibarr ('' for the mail() function of PHP)
	 * @return bool
	 */
	public static function copyBySendingServer(InboxAccount $account, $host)
	{
		$host = self::bareHost($host);
		if ($host === '') {
			return false;
		}
		if ($host === self::bareHost($account->getSmtpHost())) {
			return $account->sentCopyByServer();
		}

		return InboxAccount::serverKeepsSent($host);
	}

	/**
	 * Source of the mail as it left, built again from CMailFile for its sending mode, without Bcc header, lines ended by
	 * CRLF (IMAP)
	 *
	 * @param CMailFile $mail Mail sent
	 * @return string '' when the mode is not known
	 */
	public static function rawOf($mail)
	{
		$raw = '';
		if ($mail->sendmode === 'smtps' && is_object($mail->smtps) && method_exists($mail->smtps, 'getHeader')) {
			// What smtps.class.php writes after DATA (boundaries and Date made again, same Message-ID)
			$raw = $mail->smtps->getHeader().$mail->smtps->getBodyContent();
		} elseif ($mail->sendmode === 'swiftmailer' && is_object($mail->message) && method_exists($mail->message, 'toString')) {
			$raw = (string) $mail->message->toString();
		} elseif ($mail->sendmode === 'mail' && is_string($mail->headers) && is_string($mail->message)) {
			// mail() of PHP gets To and Subject apart from the headers and writes them first
			$head = rtrim($mail->headers, "\r\n");
			$subject = (string) $mail->subject;
			$top = '';
			if (!preg_match('/^To:/mi', $head)) {
				$top .= 'To: '.CMailFile::getValidAddress((string) $mail->addr_to, 2)."\r\n";
			}
			$top .= 'Subject: '.(ascii_check($subject) ? $subject : CMailFile::encodetorfc2822($subject))."\r\n";
			$raw = $top.$head."\r\n\r\n".$mail->message;
		}
		if ($raw === '') {
			return '';
		}
		$raw = preg_replace("/\r?\n/", "\r\n", $raw);

		return self::withoutHeader($raw, 'Bcc');
	}

	/**
	 * A header removed from a source, with its folded lines; the body is never touched
	 *
	 * @param string $raw  Source, lines ended by CRLF
	 * @param string $name Header name
	 * @return string
	 */
	public static function withoutHeader($raw, $name)
	{
		$end = strpos((string) $raw, "\r\n\r\n");
		$head = $end === false ? (string) $raw : substr((string) $raw, 0, $end);
		$body = $end === false ? '' : substr((string) $raw, $end);
		$kept = array();
		$skip = false;
		foreach (explode("\r\n", $head) as $line) {
			if ($line !== '' && ($line[0] === ' ' || $line[0] === "\t")) {
				if (!$skip) {
					$kept[] = $line;
				}
				continue;
			}
			$skip = stripos($line, $name.':') === 0;
			if (!$skip) {
				$kept[] = $line;
			}
		}

		return implode("\r\n", $kept).$body;
	}

	/**
	 * Connector of the mailbox, kept for the next mails of the request
	 *
	 * @param InboxAccount $account Mailbox
	 * @return InboxConnectorInterface|null
	 */
	protected function connector(InboxAccount $account)
	{
		$id = (int) $account->id;
		if (!isset(self::$connectors[$id])) {
			$error = '';
			$connector = InboxConnectorFactory::forAccount($this->db, $account, $error);
			if (!$connector) {
				$this->error = $error;
				return null;
			}
			if (!$connector->can(InboxConnectorInterface::CAP_IMPORT)) {
				$this->error = 'The connector cannot file a mail';
				return null;
			}
			self::$connectors[$id] = $connector;
		}

		return self::$connectors[$id];
	}

	/**
	 * @param int $id Mailbox
	 * @return void
	 */
	protected function forget($id)
	{
		if (isset(self::$connectors[$id])) {
			self::$connectors[$id]->disconnect();
			unset(self::$connectors[$id]);
		}
	}

	/**
	 * The mail answered (In-Reply-To), in the index of the mailbox: \Answered on the server, then in the index (P8)
	 *
	 * @param InboxAccount            $account   Mailbox
	 * @param InboxConnectorInterface $connector Its connector
	 * @param string                  $raw       Source filed
	 * @return int Mails marked
	 */
	protected function markAnswered(InboxAccount $account, $connector, $raw)
	{
		if ($account->isLive() || !$connector->can(InboxConnectorInterface::CAP_FLAGS)) {
			return 0;
		}
		require_once __DIR__.'/inbox.class.php';
		require_once __DIR__.'/inboxheaderparser.class.php';
		$end = strpos($raw, "\r\n\r\n");
		$header = InboxHeaderParser::parse($end === false ? $raw : substr($raw, 0, $end));
		$answered = Inbox::normalizeMessageId((string) $header['in_reply_to']);
		if ($answered === '') {
			return 0;
		}
		$p = $this->db->prefix();
		$sql = "SELECT m.rowid, m.remote_id, f.path FROM ".$p."inbox_mail as m INNER JOIN ".$p."inbox_folder as f ON f.rowid = m.fk_folder";
		$sql .= " WHERE m.fk_account = ".((int) $account->id)." AND m.message_id = '".$this->db->escape($answered)."' AND m.answered = 0 LIMIT 10";
		$rows = $this->db->getRows($sql);
		$done = 0;
		foreach (is_array($rows) ? $rows : array() as $r) {
			if ($connector->setFlags((string) $r->path, array((string) $r->remote_id), array('\\Answered'), array())) {
				$this->db->query("UPDATE ".$p."inbox_mail SET answered = 1 WHERE rowid = ".((int) $r->rowid));
				$done++;
			}
		}

		return $done;
	}
}
