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
 * \file    inbox/class/inboxlivesend.class.php
 * \ingroup inbox
 * \brief   Sending of a protected mailbox (1.89.0, lot S3): delay of the undo in the session, never in the queue
 */

require_once __DIR__.'/inboxprotect.class.php';
require_once __DIR__.'/inboxlive.class.php';
require_once __DIR__.'/inboxoutbox.class.php';
require_once __DIR__.'/inboxcomposer.class.php';
require_once __DIR__.'/inboxreader.class.php';
require_once __DIR__.'/inboxmailbuilder.class.php';
require_once __DIR__.'/connector/inboxconnectorfactory.class.php';

/**
 * The server alone cannot send for a protected mailbox (its password lives in the session of the browser): no row in
 * llx_inbox_outbox, no scheduled job (analyse-boites-protegees §7).
 * - A mail in its delay of undo is kept for the session: its source encrypted with the key of the cookie, in a file
 *   that nothing else can read, its description in the session (encrypted too). Its number is negative.
 * - A copy marked X-Dolibarr-Pending-Send is put in the Drafts of the mail server: if the browser dies before the
 *   end of the delay, the next opening of the mailbox offers to send it, never sends it alone.
 * - The page sends it at the end of the delay, or at once when it closes (navigator.sendBeacon).
 * - A sending that fails while no page is open leaves a notice without any content (llx_inbox_pending_notice).
 */
class InboxLiveSend
{
	/** Slot of the session */
	const SLOT = 'send';
	/** Header of the copy in Drafts of a mail waiting for its sending */
	const PENDING_HEADER = 'X-Dolibarr-Pending-Send';
	/** Headers of a sending scheduled (§7.2) */
	const SCHEDULED_HEADER = 'X-Dolibarr-Scheduled';
	const CATCHUP_HEADER = 'X-Dolibarr-Catchup';
	const AUTHOR_HEADER = 'X-Dolibarr-Author';
	/** A mail bigger than that is sent at once (its source would make the session too heavy) */
	const MAX_KEPT = 20971520;

	/** @var DoliDB */
	protected $db;
	/** @var string */
	public $error = '';
	/** @var string */
	public $errorCode = '';

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct(DoliDB $db)
	{
		$this->db = $db;
	}

	/**
	 * @param string $code   Code
	 * @param string $detail Detail
	 * @return null
	 */
	protected function fail($code, $detail = '')
	{
		$this->errorCode = $code;
		$this->error = $detail;

		return null;
	}

	/**
	 * @return array<string,array<string,mixed>> Mails of this session waiting, by number
	 */
	protected static function entries()
	{
		$list = InboxProtect::sessionGet(self::SLOT);

		return is_array($list) ? $list : array();
	}

	/**
	 * @param array<string,array<string,mixed>> $list Mails waiting
	 * @return void
	 */
	protected static function save(array $list)
	{
		InboxProtect::sessionPut(self::SLOT, $list);
	}

	/**
	 * @param int|string $id Number
	 * @return bool A sending of a protected mailbox (negative number)
	 */
	public static function isLive($id)
	{
		return (int) $id < 0;
	}

	/**
	 * @param string $id Number
	 * @return string Path of the sealed source
	 */
	protected static function file($id)
	{
		$dir = InboxOutbox::dir().'/live';
		if (!is_dir($dir)) {
			dol_mkdir($dir);
		}

		return $dir.'/'.hash('sha256', $id.'|'.session_id()).'.sealed';
	}

	/**
	 * A mail with one header more on top (the copy marked in Drafts)
	 *
	 * @param string $raw    Source
	 * @param string $name   Header
	 * @param string $value  Value
	 * @return string
	 */
	public static function withHeader($raw, $name, $value)
	{
		return $name.': '.preg_replace('/[\r\n]+/', ' ', (string) $value)."\r\n".$raw;
	}

	/**
	 * Put a mail of a protected mailbox in its delay of undo (from InboxComposer::send)
	 *
	 * @param User                $user     Author
	 * @param InboxAccount        $account  Mailbox
	 * @param array<string,mixed> $identity Identity
	 * @param InboxMailBuilder    $b        Builder
	 * @param array<string,mixed> $built    Result of build()
	 * @param array<string,mixed> $o        token, source, draft, typed, at
	 * @return int Number (< 0), 0 if KO
	 */
	public function queue(User $user, InboxAccount $account, array $identity, InboxMailBuilder $b, array $built, array $o)
	{
		if (strlen((string) $built['raw']) + strlen((string) $built['wire']) > self::MAX_KEPT) {
			$this->error = 'too_big';
			return 0;
		}
		$id = (string) (-random_int(1000, PHP_INT_MAX >> 12));
		$sealed = InboxProtect::seal((string) json_encode(array('raw' => base64_encode((string) $built['raw']), 'wire' => base64_encode((string) $built['wire']))), 'send|'.$id);
		if ($sealed === null || file_put_contents(self::file($id), $sealed) === false) {
			$this->error = 'Cannot keep the mail for its sending';
			return 0;
		}
		// The copy marked in Drafts: what is left when the browser dies during the delay
		$marked = '';
		$drafts = (new InboxComposer($this->db))->roleFolder($account, 'drafts');
		$error = '';
		$c = $drafts !== '' ? InboxConnectorFactory::forAccount($this->db, $account, $error) : null;
		if ($c) {
			$marked = (string) $c->importRaw($drafts, self::withHeader((string) $built['raw'], self::PENDING_HEADER, $id), array('\\Draft', '\\Seen'));
			$c->disconnect();
		}
		$list = self::entries();
		$list[$id] = array(
			'user' => (int) $user->id, 'account' => (int) $account->id, 'identity' => (int) $identity['id'], 'from' => (string) $b->from['email'],
			'envelope' => array_values((array) $built['recipients']), 'subject' => (string) $b->subject, 'message_id' => (string) $built['message_id'],
			'to' => implode(', ', array_column(array_merge($b->to, $b->cc), 'email')), 'nb' => count((array) $built['recipients']),
			'source' => $o['source'] ?? null, 'draft' => $o['draft'] ?? null, 'token' => (string) ($o['token'] ?? ''), 'typed' => $o['typed'] ?? array(),
			'at' => (int) $o['at'], 'status' => 0, 'error' => '', 'marked' => $marked !== '' ? array('folder' => $drafts, 'remote_id' => $marked) : null,
		);
		self::save($list);

		return (int) $id;
	}

	/**
	 * Mails of the user waiting in this session, in the form of InboxOutbox::listMine()
	 *
	 * @param User $user User
	 * @return array<int,array<string,mixed>>
	 */
	public function listMine(User $user)
	{
		$out = array();
		foreach (self::entries() as $id => $e) {
			if ((int) $e['user'] !== (int) $user->id) {
				continue;
			}
			$account = new InboxAccount($this->db);
			$account->fetch((int) $e['account']);
			$out[] = array('id' => (int) $id, 'subject' => (string) $e['subject'], 'to' => (string) $e['to'], 'nb' => (int) $e['nb'], 'scheduled' => 0,
				'send_at' => (int) $e['at'], 'status' => (int) $e['status'], 'error' => (string) $e['error'], 'mailbox' => (string) $account->email, 'live' => 1);
		}

		return $out;
	}

	/**
	 * Send a mail waiting (end of its delay, « Send now », or the page closing). Once: the entry leaves the session
	 * before the sending (the session of PHP lets one request of the user at a time).
	 *
	 * @param User $user   User
	 * @param int  $id     Number
	 * @param bool $beacon Asked by a page closing: nobody will see an error, a notice is left for later
	 * @return array<string,mixed>|null
	 */
	public function deliver(User $user, $id, $beacon = false)
	{
		$this->error = '';
		$this->errorCode = '';
		$key = (string) (int) $id;
		$list = self::entries();
		$done = InboxProtect::sessionGet(self::SLOT.'_done') ?: array();
		if (!isset($list[$key]) || (int) $list[$key]['user'] !== (int) $user->id) {
			return $this->fail(in_array($key, $done, true) ? 'already_sent' : 'not_found');
		}
		$e = $list[$key];
		unset($list[$key]);
		self::save($list);
		$done[] = $key;
		InboxProtect::sessionPut(self::SLOT.'_done', array_slice($done, -100));
		$sealed = (string) @file_get_contents(self::file($key));
		$plain = InboxProtect::unseal($sealed, 'send|'.$key);
		$src = $plain !== null ? json_decode($plain, true) : null;
		$raw = is_array($src) ? (string) base64_decode((string) $src['raw']) : '';
		$wire = is_array($src) ? (string) base64_decode((string) $src['wire']) : '';
		$account = new InboxAccount($this->db);
		$code = '';
		$detail = '';
		$sentCopy = 0;
		if ($raw === '' || $wire === '') {
			$code = 'error';
			$detail = 'Source of the mail missing';
		} elseif ($account->fetch((int) $e['account']) <= 0 || !$account->isActive() || !$account->isProtected()) {
			$code = 'no_send';
			$detail = 'Mailbox not active';
		} elseif (!in_array((new InboxAccess($this->db))->getLevel($user, (int) $account->id), array(InboxAccess::LEVEL_SEND, InboxAccess::LEVEL_MANAGE), true)) {
			$code = 'no_send';
			$detail = 'No right to send from the mailbox';
		} elseif (getDolGlobalString('MAIN_DISABLE_ALL_MAILS')) {
			$code = 'mails_disabled';
			$detail = 'Sending disabled';
		} elseif (!$this->identityOk($account, (int) $e['identity'], (string) $e['from'])) {
			$code = 'no_send';
			$detail = 'Identity of the mail not active any more';
		}
		$error = '';
		$c = $code === '' ? InboxConnectorFactory::forAccount($this->db, $account, $error) : null;
		if ($code === '' && !$c) {
			$code = 'error';
			$detail = $error;
		}
		if ($code === '' && !$c->send($wire, array('from' => (string) $e['from'], 'recipients' => (array) $e['envelope']))) {
			$code = method_exists($c, 'getErrorCode') && (string) $c->getErrorCode() !== '' ? (string) $c->getErrorCode() : 'smtp_refused';
			$detail = $c->getError();
		}
		if ($code !== '') {
			if ($c) {
				$c->disconnect();
			}
			dol_syslog('inbox live send '.$key.' '.$code.' '.$detail, LOG_WARNING);
			if ($beacon) {
				// Nobody sees it now: a notice without content, the copy marked stays in Drafts
				$this->notice($user, (int) $e['account'], $e['marked'], $code);
				@unlink(self::file($key));
			} else {
				// Kept failed: the bar of the page offers to take the writing back
				$e['status'] = -1;
				$e['error'] = dol_trunc($code.': '.$detail, 250, 'right', 'UTF-8', 1);
				$list = self::entries();
				$list[$key] = $e;
				self::save($list);
			}
			return $this->fail($code, $detail);
		}
		// Sent: the copy in Sent, the mail answered marked, the drafts removed
		$composer = new InboxComposer($this->db);
		$sent = $composer->roleFolder($account, 'sent');
		if ($account->sentCopyByServer()) {
			$sentCopy = 2;
		} elseif ($sent !== '') {
			$sentCopy = $c->importRaw($sent, $raw, array('\\Seen')) !== null ? 1 : 0;
		}
		$source = $e['source'] ?? null;
		if ($source && (int) ($source['mail'] ?? 0) !== 0) {
			$composer->markAnswered($user, $source, (int) $account->id === (int) $source['account'] ? $c : null, in_array($source['mode'] ?? '', array('forward', 'forward_attach'), true));
		}
		if (!empty($e['draft'])) {
			$composer->removeDraft($e['draft'], $c, (int) $account->id, $user);
		}
		if (!empty($e['marked'])) {
			$c->deleteMails((string) $e['marked']['folder'], array((string) $e['marked']['remote_id']));
		}
		$c->disconnect();
		@unlink(self::file($key));
		if ((string) $e['token'] !== '') {
			$dir = InboxComposer::dirOf($user, (string) $e['token']);
			if ($dir !== null) {
				dol_delete_dir_recursive($dir);
			}
			unset($_SESSION['inbox_compose'][(string) $e['token']]);
		}

		return array('message_id' => (string) $e['message_id'], 'sent_copy' => $sentCopy, 'account' => (int) $account->id, 'outbox' => (int) $id,
			'recorded' => array(), 'pieces' => array(), 'subject' => (string) $e['subject']);
	}

	/**
	 * @param InboxAccount $account    Mailbox
	 * @param int          $identityId Identity (<= 0: the mailbox itself)
	 * @param string       $from       Address of the mail
	 * @return bool The identity is still active, of this mailbox, with this address (audit INBX-015)
	 */
	protected function identityOk(InboxAccount $account, $identityId, $from)
	{
		if ($identityId > 0) {
			$ident = $this->db->getRow("SELECT email FROM ".$this->db->prefix()."inbox_identity WHERE rowid = ".((int) $identityId)." AND fk_account = ".((int) $account->id)." AND status = 1");

			return is_object($ident) && strtolower((string) $ident->email) === strtolower($from);
		}

		return strtolower($from) === strtolower((string) $account->email);
	}

	/**
	 * Undo a mail in its delay (or take a failed one back): the writing comes back, the copy marked leaves Drafts
	 *
	 * @param User $user User
	 * @param int  $id   Number
	 * @return array<string,mixed>|null Data of the writing
	 */
	public function cancel(User $user, $id)
	{
		$key = (string) (int) $id;
		$list = self::entries();
		if (!isset($list[$key]) || (int) $list[$key]['user'] !== (int) $user->id) {
			$done = InboxProtect::sessionGet(self::SLOT.'_done') ?: array();
			return $this->fail(in_array($key, $done, true) ? 'already_sent' : 'not_found');
		}
		$e = $list[$key];
		$composer = new InboxComposer($this->db);
		$view = $composer->restore($user, (string) $e['token'], (array) $e['typed']);
		if ($view === null) {
			return $this->fail($composer->errorCode, $composer->error);
		}
		unset($list[$key]);
		self::save($list);
		@unlink(self::file($key));
		$this->removeMarked((int) $e['account'], $e['marked']);

		return $view;
	}

	/**
	 * @param int                       $accountId Mailbox
	 * @param array<string,string>|null $marked    Copy marked in Drafts
	 * @return void
	 */
	protected function removeMarked($accountId, $marked)
	{
		if (empty($marked)) {
			return;
		}
		$account = new InboxAccount($this->db);
		$error = '';
		if ($account->fetch((int) $accountId) > 0 && ($c = InboxConnectorFactory::forAccount($this->db, $account, $error))) {
			$c->deleteMails((string) $marked['folder'], array((string) $marked['remote_id']));
			$c->disconnect();
		}
	}

	/**
	 * A sending failed while no page was open (§7.1 point 6): where the copy marked is and a code, nothing else
	 *
	 * @param User                      $user      Author
	 * @param int                       $accountId Mailbox
	 * @param array<string,string>|null $marked    Copy marked in Drafts
	 * @param string                    $code      Technical code (never the text of the server: it may quote an address)
	 * @return void
	 */
	public function notice(User $user, $accountId, $marked, $code)
	{
		$uid = 0;
		if (!empty($marked['remote_id']) && preg_match('/:(\d+)$/', (string) $marked['remote_id'], $m)) {
			$uid = (int) $m[1];
		}
		$this->db->query("INSERT INTO ".$this->db->prefix()."inbox_pending_notice (fk_account, fk_user, draft_folder, draft_uid, error_code, date_creation) VALUES ("
			.((int) $accountId).", ".((int) $user->id).", ".(!empty($marked['folder']) ? "'".$this->db->escape(dol_trunc((string) $marked['folder'], 250, 'right', 'UTF-8', 1))."'" : 'NULL').", "
			.($uid ?: 'NULL').", '".$this->db->escape(substr(preg_replace('/[^a-z0-9_]/i', '', $code), 0, 32))."', '".$this->db->idate(dol_now())."')");
	}

	/**
	 * Notices of the user for the mailboxes he reads (shown once, then deleted)
	 *
	 * @param User $user User
	 * @param bool $take Delete them (shown)
	 * @return array<int,array<string,mixed>>
	 */
	public function notices(User $user, $take = false)
	{
		$p = $this->db->prefix();
		$rows = $this->db->getRows("SELECT n.rowid, n.fk_account, n.draft_folder, n.error_code, n.date_creation, a.label, a.email FROM ".$p."inbox_pending_notice as n"
			." INNER JOIN ".$p."inbox_account as a ON a.rowid = n.fk_account WHERE n.fk_user = ".((int) $user->id)." ORDER BY n.rowid LIMIT 50");
		$out = array();
		foreach (is_array($rows) ? $rows : array() as $r) {
			$out[] = array('id' => (int) $r->rowid, 'account' => (int) $r->fk_account, 'mailbox' => (string) ($r->label ?: $r->email), 'code' => (string) $r->error_code,
				'date' => (int) $this->db->jdate($r->date_creation),
				'folder' => (string) $r->draft_folder !== '' ? InboxLive::folderId((int) $r->fk_account, (string) $r->draft_folder) : 0);
		}
		if ($take && $out) {
			$this->db->query("DELETE FROM ".$p."inbox_pending_notice WHERE fk_user = ".((int) $user->id)." AND rowid IN (".implode(',', array_column($out, 'id')).")");
		}

		return $out;
	}

	/**
	 * Copies marked in Drafts of the unlocked protected mailboxes of the user, not in this session any more (the
	 * browser died during a delay, §7.1 point 5), and the sendings scheduled (§7.2)
	 *
	 * @param User $user User
	 * @return array<int,array<string,mixed>>
	 */
	public function waitingDrafts(User $user)
	{
		$mine = array();
		foreach (self::entries() as $e) {
			if (!empty($e['marked']['remote_id'])) {
				$mine[] = (string) $e['marked']['remote_id'];
			}
		}
		$out = array();
		$live = new InboxLive($this->db);
		foreach ((new InboxAccess($this->db))->getAccounts($user, InboxAccess::LEVEL_SEND) as $account) {
			if (!$account->isProtected() || !InboxProtect::isUnlocked((int) $account->id)) {
				continue;
			}
			$drafts = (new InboxComposer($this->db))->roleFolder($account, 'drafts');
			$c = $drafts !== '' ? $live->connector($account) : null;
			if (!$c) {
				continue;
			}
			foreach (array(self::PENDING_HEADER => 'pending', self::SCHEDULED_HEADER => 'scheduled') as $header => $kind) {
				$found = $c->liveSearch($drafts, array('HEADER', $header, '""'));
				if (!$found || !$found['uids']) {
					continue;
				}
				$uids = array_slice($found['uids'], 0, 50);
				$heads = $c->liveHeaders($drafts, $uids, array(self::PENDING_HEADER, self::SCHEDULED_HEADER, self::CATCHUP_HEADER, self::AUTHOR_HEADER));
				$rows = $c->liveFetch($drafts, $uids);
				$ids = InboxLive::mailIds((int) $account->id, $drafts, array_column((array) $rows, 'remote_id'));
				foreach ((array) $rows as $r) {
					if (in_array((string) $r['remote_id'], $mine, true)) {
						continue;
					}
					$h = $heads[(int) $r['sort_key']] ?? array();
					$author = (int) ($h[strtolower(self::AUTHOR_HEADER)] ?? 0);
					$out[] = array(
						'kind' => $kind, 'id' => $ids[$r['remote_id']], 'account' => (int) $account->id, 'mailbox' => (string) ($account->label ?: $account->email),
						'subject' => (string) $r['subject'], 'at' => $kind === 'scheduled' ? (int) strtotime((string) ($h[strtolower(self::SCHEDULED_HEADER)] ?? '')) : 0,
						'catchup' => ($h[strtolower(self::CATCHUP_HEADER)] ?? 'ask') === 'send' ? 'send' : 'ask', 'author' => $author, 'mine' => $kind === 'pending' || $author === (int) $user->id,
					);
				}
			}
		}
		$live->close();

		return $out;
	}

	/**
	 * Send a copy found in Drafts (pending of a session gone, or scheduled and due): its marks removed, the Bcc out of
	 * what leaves, then the copy in Sent and the draft removed. A mail already in Sent (same Message-ID, sent by
	 * another software) is not sent twice.
	 *
	 * @param User $user User
	 * @param int  $id   Number of the live reading of the draft
	 * @return array<string,mixed>|null
	 */
	public function sendDraft(User $user, $id)
	{
		$reader = new InboxReader($this->db);
		if (!$reader->load($user, (int) $id) || !$reader->isLive()) {
			return $this->fail('not_found');
		}
		if (!in_array($reader->level, array(InboxAccess::LEVEL_SEND, InboxAccess::LEVEL_MANAGE), true)) {
			return $this->fail('no_send', 'No right to send from the mailbox');
		}
		if (getDolGlobalString('MAIN_DISABLE_ALL_MAILS')) {
			return $this->fail('mails_disabled', 'Sending disabled');
		}
		$raw = $reader->raw($user);
		if ($raw === null) {
			return $this->fail('not_found', $reader->error);
		}
		$split = preg_split("/\r?\n\r?\n/", $raw, 2);
		$head = preg_replace('/^(X-Dolibarr-(Pending-Send|Scheduled|Catchup|Author)):[^\r\n]*(\r?\n[ \t][^\r\n]*)*\r?\n/mi', '', $split[0]."\r\n");
		$body = $split[1] ?? '';
		$clean = rtrim($head, "\r\n")."\r\n\r\n".$body;
		$wire = preg_replace('/^Bcc:[^\r\n]*(\r?\n[ \t][^\r\n]*)*\r?\n/mi', '', rtrim($head, "\r\n")."\r\n")."\r\n".$body;
		$headers = InboxReader::headers($clean);
		$envelope = array();
		foreach (array('to', 'cc', 'bcc') as $h) {
			foreach (InboxMailBuilder::parseAddresses((string) InboxReader::headerValue($headers, $h)) as $a) {
				$envelope[] = (string) $a['email'];
			}
		}
		$envelope = array_values(array_unique(array_filter($envelope)));
		$from = InboxMailBuilder::parseAddresses((string) InboxReader::headerValue($headers, 'from'));
		$from = $from ? (string) $from[0]['email'] : '';
		if (!$envelope) {
			return $this->fail('no_recipient');
		}
		$account = $reader->account;
		if ($from === '' || ($from !== strtolower((string) $account->email) && !is_object($this->db->getRow("SELECT rowid FROM ".$this->db->prefix()."inbox_identity WHERE fk_account = ".((int) $account->id)." AND status = 1 AND email = '".$this->db->escape($from)."'")))) {
			return $this->fail('no_send', 'Identity of the mail not active any more');
		}
		$error = '';
		$c = InboxConnectorFactory::forAccount($this->db, $account, $error);
		if (!$c) {
			return $this->fail('error', $error);
		}
		$composer = new InboxComposer($this->db);
		$sent = $composer->roleFolder($account, 'sent');
		$mid = trim((string) InboxReader::headerValue($headers, 'message-id'), " <>");
		if ($sent !== '' && $mid !== '' && $c->findByMessageId($mid, array($sent))) {
			// Already sent by another software: the draft goes, nothing leaves
			$c->deleteMails((string) $reader->row->folder_path, array((string) $reader->row->remote_id));
			$c->disconnect();
			return array('already' => 1);
		}
		if (!$c->send($wire, array('from' => $from, 'recipients' => $envelope))) {
			$code = method_exists($c, 'getErrorCode') && (string) $c->getErrorCode() !== '' ? (string) $c->getErrorCode() : 'smtp_refused';
			$detail = $c->getError();
			$c->disconnect();
			return $this->fail($code, $detail);
		}
		$copy = 0;
		if ($account->sentCopyByServer()) {
			$copy = 2;
		} elseif ($sent !== '') {
			$copy = $c->importRaw($sent, $clean, array('\\Seen')) !== null ? 1 : 0;
		}
		$c->deleteMails((string) $reader->row->folder_path, array((string) $reader->row->remote_id));
		$c->disconnect();

		return array('sent' => 1, 'sent_copy' => $copy);
	}

	/**
	 * Schedule a mail of a protected mailbox (§7.2): it waits in Drafts with its date, nothing in Dolibarr
	 *
	 * @param User                $user    Author
	 * @param InboxAccount        $account Mailbox
	 * @param array<string,mixed> $built   Result of build()
	 * @param int                 $at      Date
	 * @param string              $catchup ask or send: what to do when no page was open at the date
	 * @return string|null Remote id of the draft
	 */
	public function schedule(User $user, InboxAccount $account, array $built, $at, $catchup)
	{
		$drafts = (new InboxComposer($this->db))->roleFolder($account, 'drafts');
		if ($drafts === '') {
			return $this->fail('error', 'No Drafts folder');
		}
		$error = '';
		$c = InboxConnectorFactory::forAccount($this->db, $account, $error);
		if (!$c) {
			return $this->fail('error', $error);
		}
		$raw = self::withHeader(self::withHeader(self::withHeader((string) $built['raw'], self::AUTHOR_HEADER, (string) (int) $user->id),
			self::CATCHUP_HEADER, $catchup === 'send' ? 'send' : 'ask'), self::SCHEDULED_HEADER, gmdate('D, d M Y H:i:s', (int) $at).' +0000');
		$rid = $c->importRaw($drafts, $raw, array('\\Draft', '\\Seen'));
		$c->disconnect();
		if ($rid === null) {
			return $this->fail('error', $c->getError());
		}

		return (string) $rid;
	}
}
