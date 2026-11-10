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
 * \file    inbox/class/inboxreader.class.php
 * \ingroup inbox
 * \brief   Reading of a mail of the index in the application: rights, cache, connector, analysis
 */

require_once __DIR__.'/inbox.class.php';
require_once __DIR__.'/inboxaccess.class.php';
require_once __DIR__.'/inboxaccount.class.php';
require_once __DIR__.'/inboxattachmentscan.class.php';
require_once __DIR__.'/inboxactionlog.class.php';
require_once __DIR__.'/inboxmimeparser.class.php';
require_once __DIR__.'/inboxheaderparser.class.php';
require_once __DIR__.'/inboxuserparam.class.php';
require_once __DIR__.'/connector/inboxconnectorfactory.class.php';
require_once __DIR__.'/inboxreceipt.class.php';

/**
 * A mail of the index read for a user: the row must be in a mailbox the user may read (else « not found », never
 * « forbidden »). The body is read with BODY.PEEK (never marked read by the reading), kept in the raw cache of lot 0
 * and analysed by InboxMimeParser. Nothing of the mail is trusted.
 */
class InboxReader
{
	/** @var DoliDB */
	protected $db;

	/** @var string */
	public $error = '';

	/** @var string not_found, gone (removed from the server), error */
	public $errorCode = '';

	/** @var object|null Row of the index (with folder_path, folder_role) */
	public $row = null;

	/** @var InboxAccount|null */
	public $account = null;

	/** @var string Level of the user on the mailbox */
	public $level = '';

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct(DoliDB $db)
	{
		$this->db = $db;
	}

	/**
	 * Row of the index, if the user may read its mailbox
	 *
	 * @param User $user   User
	 * @param int  $mailId Row
	 * @return bool
	 */
	public function load(User $user, $mailId)
	{
		$this->error = '';
		$this->errorCode = '';
		$p = $this->db->prefix();
		$row = $this->db->getRow("SELECT m.*, f.path AS folder_path, f.role AS folder_role FROM ".$p."inbox_mail as m"
			." INNER JOIN ".$p."inbox_folder as f ON f.rowid = m.fk_folder WHERE m.rowid = ".((int) $mailId));
		if (!is_object($row)) {
			$this->errorCode = 'not_found';
			return false;
		}
		$access = new InboxAccess($this->db);
		$level = $access->getLevel($user, (int) $row->fk_account);
		if ($level === InboxAccess::LEVEL_NONE) {
			$this->errorCode = 'not_found';
			return false;
		}
		$account = new InboxAccount($this->db);
		if ($account->fetch((int) $row->fk_account) <= 0 || !$account->isActive()) {
			$this->errorCode = 'not_found';
			return false;
		}
		$this->row = $row;
		$this->account = $account;
		$this->level = $level;

		return true;
	}

	/**
	 * Raw mail: from the cache, else from the server (and kept)
	 *
	 * @param User $user User
	 * @return string|null
	 */
	public function raw(User $user)
	{
		$inbox = new Inbox($this->db);
		$messageId = (string) $this->row->message_id;
		$raw = $messageId !== '' ? $inbox->cachedRaw($this->account->email, $messageId) : null;
		// The cache is found by the Message-ID, that a sender may copy: served only when it is this mail (its size
		// on the server), else read again from the server (audit INBX-012)
		if ($raw !== null && ((int) $this->row->size <= 0 || strlen($raw) === (int) $this->row->size)) {
			return $raw;
		}
		$raw = null;
		$error = '';
		$connector = InboxConnectorFactory::forAccount($this->db, $this->account, $error);
		if (!$connector) {
			$this->error = $error;
			$this->errorCode = 'error';
			return null;
		}
		$raw = $connector->fetchRaw((string) $this->row->folder_path, (string) $this->row->remote_id);
		$connector->disconnect();
		if ($raw === null || $raw === '') {
			$this->error = $connector->getError();
			// Removed or moved by another client since the last pass: the index row goes
			$this->errorCode = $connector->notFound() || $raw === '' || $this->error === 'UIDVALIDITY changed' ? 'gone' : 'error';
			if ($this->errorCode === 'gone') {
				$this->db->query("DELETE FROM ".$this->db->prefix()."inbox_mail_address WHERE fk_mail = ".((int) $this->row->rowid));
				$this->db->query("DELETE FROM ".$this->db->prefix()."inbox_mail WHERE rowid = ".((int) $this->row->rowid));
			}
			return null;
		}
		$inbox->remember($this->account->email, $messageId, $raw, (string) $this->row->folder_path, (string) $this->row->remote_id, $user);

		return $raw;
	}

	/**
	 * Mail for the reading pane (JSON)
	 *
	 * @param User   $user User
	 * @param string $raw  Raw mail
	 * @return array<string,mixed>
	 */
	public function describe(User $user, $raw)
	{
		$message = InboxMimeParser::parse($raw);
		$headers = self::headers($raw);
		$p = $this->db->prefix();
		// Snippet of the cards, when the server did not give it
		if (trim((string) $this->row->snippet) === '' && $message->bodyText !== '') {
			$snippet = dol_trunc(InboxHeaderParser::forDb($this->db, InboxMimeParser::clean(mb_substr($message->bodyText, 0, 600, 'UTF-8'))), 200, 'right', 'UTF-8', 1);
			$this->db->query("UPDATE ".$p."inbox_mail SET snippet = '".$this->db->escape($snippet)."' WHERE rowid = ".((int) $this->row->rowid));
		}
		$attachments = array();
		foreach ($message->attachments as $i => $a) {
			if (!empty($a['inline']) || !empty($a['embedded'])) {
				continue;
			}
			$attachments[] = array(
				'part' => $i, 'name' => $a['name'], 'size' => (int) $a['size'], 'kind' => self::previewKind($a), 'mime' => (string) $a['mime'],
				'warnings' => InboxAttachmentScan::warnings($a['content'], $a['name']),
			);
		}
		$remote = false;
		$trackers = 0;
		if ($message->bodyHtml !== '') {
			InboxMimeParser::safeHtml($message->bodyHtml, array(), $remote);
			$trackers = self::countTrackers($message->bodyHtml);
		}
		$params = new InboxUserParam($this->db);
		$auth = self::authResults($headers);
		require_once __DIR__.'/inboxbounce.class.php';
		$bounce = new InboxBounce($this->db);
		$readable = array_keys((new InboxAccess($this->db))->getLevels($user));
		$passed = self::authPass($auth, (string) $this->row->from_email);
		$allowImages = $remote && $passed && $this->imagesAllowed($user, (string) $this->row->from_email);
		// Recognized domains (1.59.0): the sender (authenticated only) and the links, lookalikes of the list
		require_once __DIR__.'/inboxtrusteddomain.class.php';
		$trusted = new InboxTrustedDomain($this->db);
		$canTrust = $user->hasRight('inbox', 'trusteddomain', 'write');
		$links = $trusted->linkVerdicts($message->bodyHtml, $message->bodyText, $canTrust);

		return array(
			'id' => (int) $this->row->rowid,
			'account' => (int) $this->row->fk_account,
			'folder' => (int) $this->row->fk_folder,
			'folder_role' => (string) $this->row->folder_role,
			'mailbox' => (string) $this->account->email,
			'level' => $this->level,
			'message_id' => $message->messageId,
			'subject' => $message->subject,
			'from' => array('email' => $message->fromEmail, 'name' => $message->fromName),
			'reply_to' => $message->replyToEmail,
			'to' => $message->to,
			'cc' => $message->cc,
			'date' => (int) $message->date,
			'seen' => (int) $this->row->seen,
			'flagged' => (int) $this->row->flagged,
			'answered' => (int) $this->row->answered,
			'tags' => trim((string) $this->row->keywords) === '' ? array() : array_values(array_filter(explode(' ', (string) $this->row->keywords))),
			'size' => strlen($raw),
			'body' => array('html' => $message->bodyHtml !== '', 'text' => $message->bodyText !== '', 'remote' => $remote, 'trackers' => $trackers, 'images' => $allowImages),
			'attachments' => $attachments,
			'headers' => $headers,
			'auth' => $auth,
			'security' => self::security($raw, $headers, $message),
			// Delivery report (1.25.0): what happened per recipient and where the mail sent is; a mail sent: its failures
			'report' => $bounce->view($raw, $readable),
			'bounces' => $bounce->forMail($message->messageId, $readable),
			'sender_check' => $this->senderCheck($raw),
			'trusted' => $trusted->senderVerdict($message->fromEmail, (string) $message->replyToEmail, $auth, $passed),
			'link_hosts' => (object) $links['hosts'],
			// The user may recognize domains: the domain of the sender when proven, the domains of the links
			'trust_offer' => $canTrust ? array('sender' => $passed ? self::trustableSender($trusted, $message->fromEmail) : null,
				'sender_why' => self::untrustableSenderWhy($trusted, $message->fromEmail, $passed), 'links' => $links['domains']) : null,
			// Verdict of the anti-spam filter of the server (1.44.0): SpamAssassin or Rspamd, explained in the reading
			'spam' => self::spamVerdict($headers),
			// Unsubscribe (lot W4 1.11.0): what the sender offers, never its address (the server calls it, not the browser)
			'unsubscribe' => self::unsubscribeView($headers, $auth),
			// Lot W8 1.22.0: invitation (card with its answers) and read receipt asked (never sent without a click)
			'invite' => $this->inviteView($user, $message),
			'receipt' => InboxReceipt::view($this, $headers, $params->get($user, 'mdn_mode')),
			// Users of Dolibarr among the addresses of the mail: their name, photo and card in the head of the mail
			'people' => (object) $this->people($user, array_merge(array($message->fromEmail), array_column($message->to, 'email'), array_column($message->cc, 'email'))),
			'read_mode' => $params->get($user, 'read_mode', (int) $this->row->fk_account),
			'read_delay' => (int) $params->get($user, 'read_delay', (int) $this->row->fk_account),
			'can_flag' => true,
		);
	}

	/**
	 * Domain of the sender a user may recognize from the mail: proven sender, not recognized yet, not refused; its
	 * registrable domain offered too when the sender writes from a subdomain (mail.exemple.com: exemple.com)
	 *
	 * @param InboxTrustedDomain $trusted   Helper
	 * @param string             $fromEmail Address of the sender
	 * @return array{domain:string,parent:string,like:array{domain:string,label:string}|null}|null Null when there is nothing to offer
	 */
	protected static function trustableSender(InboxTrustedDomain $trusted, $fromEmail)
	{
		$host = InboxTrustedDomain::normalize((string) $fromEmail);
		$own = $trusted->ownDomains();
		if ($host === '' || $trusted->find($host) || InboxTrustedDomain::refusal($host, $own) !== '') {
			return null;
		}
		$parent = InboxTrustedDomain::registrable($host);

		return array('domain' => $host, 'parent' => ($parent !== $host && InboxTrustedDomain::refusal($parent, $own) === '') ? $parent : '',
			'like' => InboxTrustedDomain::likeOf($host, array_merge($trusted->fetchAll(), $trusted->ownRefs()), $own));
	}

	/**
	 * Why the domain of the sender is not offered in « Recognize this domain… », said in the dialog
	 *
	 * @param InboxTrustedDomain $trusted   Helper
	 * @param string             $fromEmail Address of the sender
	 * @param bool               $passed    Sender proven
	 * @return string Key of translation, '' when it is offered or already recognized
	 */
	protected static function untrustableSenderWhy(InboxTrustedDomain $trusted, $fromEmail, $passed)
	{
		$host = InboxTrustedDomain::normalize((string) $fromEmail);
		if ($host === '' || $trusted->find($host)) {
			return '';
		}
		$why = InboxTrustedDomain::refusal($host, $trusted->ownDomains());
		if ($why === 'InboxTrustedErrorOwnDomain') {
			return 'InboxTrustDialogSenderOwn';
		}
		if ($why !== '') {
			return 'InboxTrustDialogSenderOpen';
		}

		return $passed ? '' : 'InboxTrustDialogSenderUnproven';
	}

	/**
	 * Users of Dolibarr (active, internal) behind addresses of the mail
	 *
	 * @param User     $user   User reading
	 * @param string[] $emails Addresses
	 * @return array<string,array<string,mixed>> Address (lower case) => id, name, job, email, phone, mobile, photo, url
	 */
	public function people(User $user, array $emails)
	{
		$emails = array_values(array_unique(array_filter(array_map(function ($e) {
			return strtolower(trim((string) $e));
		}, $emails), function ($e) {
			return $e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL);
		})));
		if (!$emails) {
			return array();
		}
		$in = implode(',', array_map(function ($e) {
			return "'".$this->db->escape($e)."'";
		}, array_slice($emails, 0, 200)));
		$rows = $this->db->getRows("SELECT rowid, entity, firstname, lastname, login, email, job, office_phone, user_mobile, photo FROM ".$this->db->prefix()."user"
			." WHERE LOWER(email) IN (".$in.") AND statut = 1 AND (fk_soc IS NULL OR fk_soc = 0) AND entity IN (0, ".getEntity('user').") ORDER BY rowid LIMIT 200");
		$canCard = $user->hasRight('user', 'user', 'lire') || !empty($user->admin);
		$out = array();
		foreach (is_array($rows) ? $rows : array() as $r) {
			$email = strtolower((string) $r->email);
			if (isset($out[$email])) {
				continue;
			}
			$name = trim($r->firstname.' '.$r->lastname);
			$out[$email] = array(
				'id' => (int) $r->rowid,
				'name' => $name !== '' ? $name : (string) $r->login,
				'job' => (string) $r->job,
				'email' => $email,
				'phone' => (string) $r->office_phone,
				'mobile' => (string) $r->user_mobile,
				'photo' => self::userPhotoUrl((int) $r->rowid, (string) $r->photo, (int) $r->entity),
				'url' => $canCard || (int) $r->rowid === (int) $user->id ? DOL_URL_ROOT.'/user/card.php?id='.((int) $r->rowid) : '',
			);
		}

		return $out;
	}

	/**
	 * Address of the small photo of a user ('' when none): the same file the cards of Dolibarr show
	 *
	 * @param int    $userId User
	 * @param string $photo  File name of the photo
	 * @param int    $entity Entity of the user
	 * @return string
	 */
	public static function userPhotoUrl($userId, $photo, $entity)
	{
		return InboxAccess::userPhotoUrl($userId, $photo, $entity);
	}

	/**
	 * @param User         $user    User
	 * @param InboxMessage $message Mail parsed
	 * @return array<string,mixed>|null Card of the invitation, null when none (or unreadable: the mail is still shown)
	 */
	protected function inviteView(User $user, InboxMessage $message)
	{
		require_once __DIR__.'/inboxinvite.class.php';
		try {
			$invite = new InboxInvite($this->db);
			return $invite->view($user, $this, $message);
		} catch (Throwable $e) {
			dol_syslog(__METHOD__.' '.$e->getMessage(), LOG_WARNING);
			return null;
		}
	}

	/**
	 * Imitation of a known sender (red alert), or a mail of the junk from a known and authenticated sender (lot W5
	 * 1.15.0); null when nothing is to say (unknown sender, or no trusted server in the setup)
	 *
	 * @param string $raw Raw mail
	 * @return array{state:string,address:string,name:string,detail:string}|null
	 */
	protected function senderCheck($raw)
	{
		require_once __DIR__.'/inboxknownsenders.class.php';
		$end = strpos($raw, "\r\n\r\n");
		$end = $end === false ? strpos($raw, "\n\n") : $end;
		$known = new InboxKnownSenders($this->db);
		$res = $known->check(substr($raw, 0, $end === false ? 65536 : min($end, 262144)));
		if ($res['state'] === 'unknown' || ($res['state'] === 'legit' && (string) $this->row->folder_role !== 'junk')) {
			return null;
		}
		$s = $res['sender'];
		$p = $this->db->prefix();
		$name = '';
		if (!empty($s['contactid'])) {
			$o = $this->db->getRow("SELECT c.firstname, c.lastname, s.nom FROM ".$p."socpeople as c LEFT JOIN ".$p."societe as s ON s.rowid = c.fk_soc WHERE c.rowid = ".((int) $s['contactid']));
			$name = is_object($o) ? trim($o->firstname.' '.$o->lastname).($o->nom ? ' ('.$o->nom.')' : '') : '';
		} elseif (!empty($s['socid'])) {
			$o = $this->db->getRow("SELECT nom FROM ".$p."societe WHERE rowid = ".((int) $s['socid']));
			$name = is_object($o) ? (string) $o->nom : '';
		} elseif (!empty($s['userid'])) {
			$o = $this->db->getRow("SELECT firstname, lastname FROM ".$p."user WHERE rowid = ".((int) $s['userid']));
			$name = is_object($o) ? trim($o->firstname.' '.$o->lastname) : '';
		}

		return array('state' => $res['state'], 'address' => $res['address'], 'name' => $name !== '' ? $name : $res['address'], 'detail' => $res['detail']);
	}

	/**
	 * Read / unread on the server, then in the index
	 *
	 * @param bool $seen Read
	 * @return bool
	 */
	public function setSeen($seen)
	{
		$error = '';
		$connector = InboxConnectorFactory::forAccount($this->db, $this->account, $error);
		if (!$connector || !$connector->can(InboxConnectorInterface::CAP_FLAGS)) {
			$this->error = $connector ? 'Not supported' : $error;
			return false;
		}
		$ok = $seen ? $connector->setFlags((string) $this->row->folder_path, array((string) $this->row->remote_id), array('\\Seen'), array())
			: $connector->setFlags((string) $this->row->folder_path, array((string) $this->row->remote_id), array(), array('\\Seen'));
		$connector->disconnect();
		if (!$ok) {
			$this->error = $connector->getError();
			return false;
		}
		$p = $this->db->prefix();
		if ((int) $this->row->seen !== ($seen ? 1 : 0)) {
			$this->db->query("UPDATE ".$p."inbox_mail SET seen = ".($seen ? 1 : 0)." WHERE rowid = ".((int) $this->row->rowid));
			$this->db->query("UPDATE ".$p."inbox_folder SET unread = GREATEST(0, unread ".($seen ? '- 1' : '+ 1').") WHERE rowid = ".((int) $this->row->fk_folder));
			$this->row->seen = $seen ? 1 : 0;
		}

		return true;
	}

	/**
	 * @param User   $user  User
	 * @param string $email Sender
	 * @return bool Remote pictures always shown for this sender or its domain
	 */
	public function imagesAllowed(User $user, $email)
	{
		$email = strtolower(trim((string) $email));
		if ($email === '' || strpos($email, '@') === false) {
			return false;
		}
		$domain = substr($email, strpos($email, '@'));
		$row = $this->db->getRow("SELECT rowid FROM ".$this->db->prefix()."inbox_image_allow WHERE fk_user = ".((int) $user->id)
			." AND address IN ('".$this->db->escape($email)."', '".$this->db->escape($domain)."')");

		return is_object($row);
	}

	/**
	 * Kind used by the preview window
	 *
	 * @param array<string,mixed> $a Attachment
	 * @return string pdf, image, mail, text, csv, zip, other
	 */
	public static function previewKind(array $a)
	{
		$kind = InboxMimeParser::kind($a['content']);
		$name = strtolower((string) $a['name']);
		if ($kind === 'pdf') {
			return 'pdf';
		}
		if (in_array($kind, array('png', 'jpeg', 'gif'), true) || ($kind === 'other' && preg_match('/\.webp$/', $name) && strpos((string) $a['content'], 'WEBP') === 8)) {
			return 'image';
		}
		if (strtolower((string) $a['mime']) === 'message/rfc822' || preg_match('/\.eml$/', $name) || (preg_match('/\.msg$/', $name) && $kind === 'ole')) {
			return 'mail';
		}
		if ($kind === 'zip' && preg_match('/\.zip$/', $name)) {
			return 'zip';
		}
		if ($kind === 'other' && preg_match('/\.(csv)$/', $name) && mb_check_encoding(substr((string) $a['content'], 0, 4096), 'UTF-8')) {
			return 'csv';
		}
		if ($kind === 'other' && preg_match('/\.(txt|log|csv|xml|json|ics|vcf)$/', $name)) {
			return 'text';
		}

		return 'other';
	}

	/**
	 * All the headers, unfolded and decoded (« All the headers » of the reading pane)
	 *
	 * @param string $raw Raw mail
	 * @return array<int,array{0:string,1:string}>
	 */
	public static function headers($raw)
	{
		$raw = str_replace("\r\n", "\n", (string) $raw);
		$end = strpos($raw, "\n\n");
		$head = substr($raw, 0, $end === false ? 65536 : min($end, 262144));
		$head = preg_replace("/\n[ \t]+/", ' ', $head);
		$out = array();
		foreach (explode("\n", $head) as $line) {
			if (count($out) >= 300) {
				break;
			}
			if (!preg_match('/^([!-9;-~]{1,80}):\s?(.*)$/', $line, $m)) {
				continue;
			}
			$value = $m[2];
			if (strpos($value, '=?') !== false) {
				$decoded = @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
				if ($decoded !== false) {
					$value = $decoded;
				}
			}
			$out[] = array($m[1], mb_substr(InboxMimeParser::clean($value), 0, 2000, 'UTF-8'));
		}

		return $out;
	}

	/**
	 * What the reading shows of List-Unsubscribe (not the address of the sender)
	 *
	 * @param array<int,array{0:string,1:string}> $headers Headers
	 * @param array<string,mixed>                 $auth    authResults()
	 * @return array{oneclick:bool,signed:bool,mailto:string}|null
	 */
	public static function unsubscribeView(array $headers, array $auth)
	{
		$info = self::unsubscribeInfo($headers);
		if (!$info) {
			return null;
		}

		return array('oneclick' => $info['oneclick'], 'signed' => $auth['dkim'] === 'pass', 'mailto' => $info['mailto']);
	}

	/**
	 * List-Unsubscribe of a mail (RFC 2369) and its one-click form (RFC 8058: an https address and List-Unsubscribe-Post)
	 *
	 * @param array<int,array{0:string,1:string}> $headers Headers
	 * @return array{https:string,mailto:string,mailto_subject:string,mailto_body:string,oneclick:bool}|null Null when the mail offers nothing
	 */
	public static function unsubscribeInfo(array $headers)
	{
		$value = self::headerValue($headers, 'List-Unsubscribe');
		if ($value === '' || !preg_match_all('/<([^>]{1,2000})>/', $value, $m)) {
			return null;
		}
		$https = '';
		$mailto = '';
		$subject = '';
		$body = '';
		foreach ($m[1] as $uri) {
			$uri = trim($uri);
			if ($https === '' && preg_match('#^https://[^\s"<>]+$#i', $uri)) {
				$https = $uri;
			} elseif ($mailto === '' && preg_match('/^mailto:([^?\s]+)(?:\?(\S*))?/i', $uri, $mm)) {
				$address = rawurldecode($mm[1]);
				$mailto = isValidEmail($address) ? $address : '';
				// Subject and text the list asks for (RFC 6068), plain text and short: never a recipient added
				if ($mailto !== '' && !empty($mm[2])) {
					foreach (explode('&', $mm[2]) as $pair) {
						$kv = explode('=', $pair, 2);
						$k = strtolower(rawurldecode($kv[0]));
						$v = trim((string) preg_replace('/[\x00-\x09\x0B-\x1F\x7F]+/u', ' ', rawurldecode($kv[1] ?? '')));
						if ($k === 'subject') {
							$subject = dol_trunc((string) preg_replace('/\s+/u', ' ', $v), 200, 'right', 'UTF-8', 1);
						} elseif ($k === 'body') {
							$body = dol_trunc($v, 1000, 'right', 'UTF-8', 1);
						}
					}
				}
			}
		}
		if ($https === '' && $mailto === '') {
			return null;
		}
		$post = self::headerValue($headers, 'List-Unsubscribe-Post');

		return array('https' => $https, 'mailto' => $mailto, 'mailto_subject' => $subject, 'mailto_body' => $body, 'oneclick' => $https !== '' && stripos($post, 'List-Unsubscribe=One-Click') !== false);
	}

	/**
	 * Unsubscribe the mailbox from the list of this mail (level manage): the POST of RFC 8058 sent by the server of
	 * Dolibarr, never the address opened in the browser; only when the header is signed (DKIM pass) and the address
	 * is an outside one (private and local addresses refused by getURLContent)
	 *
	 * @param User   $user User
	 * @param string $raw  Raw mail
	 * @return string '' if OK, else the key of the error
	 */
	public function unsubscribe(User $user, $raw)
	{
		if ($this->level !== InboxAccess::LEVEL_MANAGE) {
			return 'InboxErrorNeedManage';
		}
		$headers = self::headers($raw);
		$info = self::unsubscribeInfo($headers);
		if (!$info) {
			return 'InboxErrorUnsubscribeNone';
		}
		if (!$info['oneclick']) {
			return 'InboxErrorUnsubscribeNoOneClick';
		}
		if (self::authResults($headers)['dkim'] !== 'pass') {
			return 'InboxErrorUnsubscribeNotSigned';
		}
		require_once DOL_DOCUMENT_ROOT.'/core/lib/geturl.lib.php';
		// IPv4 only: the check of the private and local addresses of the core resolves IPv4 only, an IPv6 address would
		// pass it (audit INBX-009)
		$res = getURLContent($info['https'], 'POST', 'List-Unsubscribe=One-Click', 0, array('Content-Type: application/x-www-form-urlencoded'), array('https'), 0, -1, 5, 15,
			array(CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4));
		$code = (int) ($res['http_code'] ?? 0);
		if ($code < 200 || $code >= 300) {
			dol_syslog(__METHOD__.' '.$code.' '.($res['curl_error_msg'] ?? '').' '.dol_trunc((string) ($res['content'] ?? ''), 200), LOG_WARNING);
			// No text of the network said (it would tell what answers inside): the code only (audit INBX-009)
			$this->error = $code > 0 ? 'HTTP '.$code : 'no answer';
			return 'InboxErrorUnsubscribeFailed';
		}
		InboxActionLog::add($this->db, $user, (int) $this->row->fk_account, 'unsubscribe', array($this->row), (string) $this->row->folder_path, '', 0,
			(string) parse_url($info['https'], PHP_URL_HOST));

		return '';
	}

	/**
	 * @param array<int,array{0:string,1:string}> $headers Headers
	 * @param string                              $name    Name
	 * @return string First value, '' if none
	 */
	public static function headerValue(array $headers, $name)
	{
		foreach ($headers as $h) {
			if (strcasecmp($h[0], $name) === 0) {
				return $h[1];
			}
		}

		return '';
	}

	/**
	 * Results SPF, DKIM, DMARC written by the receiving server (the first Authentication-Results, the topmost, is
	 * the one of our server; the lower ones may be forged by the sender)
	 *
	 * @param array<int,array{0:string,1:string}> $headers Headers
	 * @return array{spf:string,dkim:string,dmarc:string,fail:bool,trusted:bool,dkim_domains:string[]} trusted: written by
	 *         one of the receiving servers of the setup (INBOX_AUTH_SERVERS), not by the sender (audit INBX-022);
	 *         dkim_domains: domains of the valid DKIM signatures
	 */
	public static function authResults(array $headers)
	{
		require_once __DIR__.'/inboxknownsenders.class.php';
		$servers = InboxKnownSenders::trustedServers();
		$value = '';
		$trusted = false;
		foreach ($headers as $h) {
			if (strcasecmp($h[0], 'Authentication-Results') !== 0) {
				continue;
			}
			if (!$servers) {
				// No server declared: the topmost one, never « trusted »
				$value = $h[1];
				break;
			}
			// The one of our server, named by its authserv-id; the others may come from the sender
			$id = strtolower(trim((string) preg_replace('/\s+\d+\s*$/', '', (string) strtok($h[1], ';'))));
			if (in_array($id, $servers, true)) {
				$value = $h[1];
				$trusted = true;
				break;
			}
		}
		$out = array('spf' => '', 'dkim' => '', 'dmarc' => '', 'fail' => false, 'trusted' => $trusted, 'dkim_domains' => array());
		foreach (array('spf', 'dkim', 'dmarc') as $k) {
			if (preg_match('/\b'.$k.'=([a-z]+)/i', $value, $m)) {
				$out[$k] = strtolower($m[1]);
			}
		}
		// Domains of the valid DKIM signatures (header.d, else header.i), each result being separated by a ;
		foreach (explode(';', $value) as $part) {
			if (preg_match('/^\s*dkim=pass\b/i', $part) && preg_match('/\bheader\.(?:d|i)=@?([a-z0-9.-]{1,253})/i', $part, $m)) {
				$out['dkim_domains'][] = strtolower(trim($m[1], '.'));
			}
		}
		if ($out['spf'] === '') {
			$spf = self::headerValue($headers, 'Received-SPF');
			if (preg_match('/^\s*([a-z]+)/i', $spf, $m)) {
				$out['spf'] = strtolower($m[1]);
			}
		}
		$out['fail'] = $out['dmarc'] === 'fail' || ($out['dkim'] === 'fail' && in_array($out['spf'], array('fail', 'softfail'), true));

		return $out;
	}

	/**
	 * Verdict of the anti-spam filter of the receiving server (1.44.0): SpamAssassin (X-Spam-Status, X-Spam-Flag,
	 * X-Spam-Score) or Rspamd (X-Spamd-Result, X-Rspamd-Score, X-Spam). The topmost header of each name only: the one
	 * of our server, added last; a lower one may come from the sender (a « No » written in advance).
	 *
	 * @param array<int,array{0:string,1:string}> $headers Headers, in their order
	 * @return array{engine:string,spam:bool,score:float|null,required:float|null,tests:string[]}|null Null when no filter
	 */
	public static function spamVerdict(array $headers)
	{
		$top = array();
		foreach ($headers as $h) {
			$name = strtolower((string) $h[0]);
			if (!isset($top[$name])) {
				$top[$name] = trim((string) $h[1]);
			}
		}
		$num = function ($v) {
			return is_numeric($v) ? round((float) $v, 1) : null;
		};
		if (isset($top['x-spam-status'])) {
			// Yes, score=7.4 required=5.0 tests=BAYES_99,URIBL_BLACK autolearn=no version=3.4.6
			$v = $top['x-spam-status'];
			$tests = preg_match('/\btests=([A-Z0-9_,\s]+?)(?:\s+\w+=|$)/i', $v, $m) ? preg_split('/[\s,]+/', trim($m[1]), -1, PREG_SPLIT_NO_EMPTY) : array();
			return array(
				'engine' => 'spamassassin',
				'spam' => (bool) preg_match('/^yes\b/i', $v) || (isset($top['x-spam-flag']) && strtoupper($top['x-spam-flag']) === 'YES'),
				'score' => preg_match('/\bscore=(-?[\d.]+)/i', $v, $m) ? $num($m[1]) : (isset($top['x-spam-score']) ? $num($top['x-spam-score']) : null),
				'required' => preg_match('/\brequired=(-?[\d.]+)/i', $v, $m) ? $num($m[1]) : null,
				'tests' => array_slice(array_values(array_filter($tests, function ($t) {
					return preg_match('/^[A-Z0-9_]{2,40}$/i', $t);
				})), 0, 40),
			);
		}
		if (isset($top['x-spamd-result']) || isset($top['x-rspamd-score'])) {
			// default: False [3.10 / 15.00]; BAYES_HAM(-3.00)[99.99%]; R_SPF_ALLOW(-0.20)[+ip4:1.2.3.4]...
			$v = $top['x-spamd-result'] ?? '';
			$score = preg_match('/\[\s*(-?[\d.]+)\s*\/\s*(-?[\d.]+)\s*\]/', $v, $m) ? array($num($m[1]), $num($m[2])) : array(isset($top['x-rspamd-score']) ? $num($top['x-rspamd-score']) : null, null);
			preg_match_all('/;\s*([A-Z0-9_]{2,40})\(/', $v, $tm);
			return array(
				'engine' => 'rspamd',
				'spam' => (bool) preg_match('/:\s*true\b/i', $v) || (isset($top['x-spam']) && strtolower($top['x-spam']) === 'yes'),
				'score' => $score[0],
				'required' => $score[1],
				'tests' => array_slice($tm[1], 0, 40),
			);
		}
		if (isset($top['x-spam-flag'])) {
			return array('engine' => 'spamassassin', 'spam' => strtoupper($top['x-spam-flag']) === 'YES', 'score' => isset($top['x-spam-score']) ? $num($top['x-spam-score']) : null, 'required' => null, 'tests' => array());
		}

		return null;
	}

	/**
	 * The sender is proven, enough for what happens alone (the pictures of a sender allowed): a positive result, not
	 * only « no failure »: DMARC passed, or a valid DKIM signature of the domain of the sender (or of a parent one);
	 * the result of the server declared when there is one (INBOX_AUTH_SERVERS). Audit INBX-022.
	 *
	 * @param array<string,mixed> $auth      authResults()
	 * @param string              $fromEmail Address of the sender
	 * @return bool
	 */
	public static function authPass(array $auth, $fromEmail)
	{
		require_once __DIR__.'/inboxknownsenders.class.php';
		if (!empty($auth['fail']) || (InboxKnownSenders::trustedServers() && empty($auth['trusted']))) {
			return false;
		}
		if (($auth['dmarc'] ?? '') === 'pass') {
			return true;
		}
		$domain = strtolower((string) substr((string) strrchr((string) $fromEmail, '@'), 1));
		foreach ((array) ($auth['dkim_domains'] ?? array()) as $d) {
			if ($domain !== '' && ($domain === $d || substr($domain, -strlen('.'.$d)) === '.'.$d)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Signed or encrypted mail (S/MIME, PGP)
	 *
	 * @param string                              $raw     Raw mail
	 * @param array<int,array{0:string,1:string}> $headers Headers
	 * @param InboxMessage                        $message Parsed mail
	 * @return array{signed:string,encrypted:bool,signer:string}
	 */
	public static function security($raw, array $headers, InboxMessage $message)
	{
		$type = strtolower(self::headerValue($headers, 'Content-Type'));
		$out = array('signed' => '', 'encrypted' => false, 'signer' => '');
		if (strpos($type, 'multipart/encrypted') === 0 || (strpos($type, 'application/') === 0 && strpos($type, 'pkcs7-mime') !== false && strpos($type, 'signed-data') === false)) {
			$out['encrypted'] = true;
			return $out;
		}
		if (preg_match('/-----BEGIN PGP MESSAGE-----/', $message->bodyText)) {
			$out['encrypted'] = true;
			return $out;
		}
		if (strpos($type, 'multipart/signed') === 0) {
			if (strpos($type, 'pgp-signature') !== false) {
				$out['signed'] = 'unchecked';
				return $out;
			}
			$out['signed'] = 'unchecked';
			if (function_exists('openssl_pkcs7_verify')) {
				$dir = sys_get_temp_dir();
				$in = tempnam($dir, 'ibxs');
				$certs = tempnam($dir, 'ibxc');
				file_put_contents($in, str_replace("\n", "\r\n", str_replace("\r\n", "\n", (string) $raw)));
				// Integrity of the content and certificate of the signer; the chain of trust is not checked here
				$res = @openssl_pkcs7_verify($in, PKCS7_NOVERIFY, $certs);
				if ($res === true) {
					$out['signed'] = 'valid';
					$cert = @openssl_x509_parse((string) file_get_contents($certs));
					if (is_array($cert)) {
						$out['signer'] = (string) ($cert['subject']['emailAddress'] ?? ($cert['subject']['CN'] ?? ''));
					}
				} elseif ($res === false) {
					$out['signed'] = 'invalid';
				}
				@unlink($in);
				@unlink($certs);
			}
		} elseif (preg_match('/-----BEGIN PGP SIGNED MESSAGE-----/', $message->bodyText)) {
			$out['signed'] = 'unchecked';
		}

		return $out;
	}

	/**
	 * Remote pictures that only tell the sender the mail was opened: 1 x 1 pictures, known tracking addresses
	 *
	 * @param string $html HTML body
	 * @return int
	 */
	public static function countTrackers($html)
	{
		$n = 0;
		if (!preg_match_all('/<img\b[^>]*>/i', (string) $html, $m)) {
			return 0;
		}
		foreach ($m[0] as $tag) {
			if (!preg_match('/\bsrc\s*=\s*["\']?\s*https?:/i', $tag)) {
				continue;
			}
			$tiny = preg_match('/\b(width|height)\s*=\s*["\']?\s*[01](px)?\b/i', $tag) || preg_match('/(width|height)\s*:\s*[01]px/i', $tag);
			$known = preg_match('#(/open\b|/track|/pixel|/beacon|/wf/open|list-manage\.com/track|/o\.gif|mailtrack|sendgrid\.net/wf|mandrillapp\.com/track|hubspot.*/e3t/|/e/o/)#i', $tag);
			if ($tiny || $known) {
				$n++;
			}
		}

		return $n;
	}
}
