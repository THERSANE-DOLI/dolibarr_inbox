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
		if ($raw !== null) {
			return $raw;
		}
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
		$allowImages = $remote && $auth['fail'] === false && $this->imagesAllowed($user, (string) $this->row->from_email);

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
			'report' => self::deliveryReport($raw, $headers),
			'sender_check' => $this->senderCheck($raw),
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
		global $db;

		if ($userId <= 0 || $photo === '' || !dolIsAllowedForPreview($photo)) {
			return '';
		}
		require_once DOL_DOCUMENT_ROOT.'/core/lib/images.lib.php';
		$u = new User($db);
		$u->id = $userId;
		$file = get_exdir(0, 0, 0, 0, $u, 'user').'photos/'.getImageFileNameForSize($photo, '_small');

		return DOL_URL_ROOT.'/viewimage.php?modulepart=userphoto&entity='.((int) $entity ?: 1).'&file='.urlencode($file);
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
	 * @return array{https:string,mailto:string,oneclick:bool}|null Null when the mail offers nothing
	 */
	public static function unsubscribeInfo(array $headers)
	{
		$value = self::headerValue($headers, 'List-Unsubscribe');
		if ($value === '' || !preg_match_all('/<([^>]{1,2000})>/', $value, $m)) {
			return null;
		}
		$https = '';
		$mailto = '';
		foreach ($m[1] as $uri) {
			$uri = trim($uri);
			if ($https === '' && preg_match('#^https://[^\s"<>]+$#i', $uri)) {
				$https = $uri;
			} elseif ($mailto === '' && preg_match('/^mailto:([^?\s]+)/i', $uri, $mm)) {
				$address = rawurldecode($mm[1]);
				$mailto = isValidEmail($address) ? $address : '';
			}
		}
		if ($https === '' && $mailto === '') {
			return null;
		}
		$post = self::headerValue($headers, 'List-Unsubscribe-Post');

		return array('https' => $https, 'mailto' => $mailto, 'oneclick' => $https !== '' && stripos($post, 'List-Unsubscribe=One-Click') !== false);
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
		$res = getURLContent($info['https'], 'POST', 'List-Unsubscribe=One-Click', 0, array('Content-Type: application/x-www-form-urlencoded'), array('https'), 0, -1, 5, 15);
		$code = (int) ($res['http_code'] ?? 0);
		if ($code < 200 || $code >= 300) {
			dol_syslog(__METHOD__.' '.$code.' '.($res['curl_error_msg'] ?? '').' '.dol_trunc((string) ($res['content'] ?? ''), 200), LOG_WARNING);
			// An address refused before any call (local or private network) comes with its reason
			$this->error = !empty($res['curl_error_msg']) ? (string) $res['curl_error_msg'] : 'HTTP '.$code;
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
	 * @return array{spf:string,dkim:string,dmarc:string,fail:bool}
	 */
	public static function authResults(array $headers)
	{
		$value = self::headerValue($headers, 'Authentication-Results');
		$out = array('spf' => '', 'dkim' => '', 'dmarc' => '', 'fail' => false);
		foreach (array('spf', 'dkim', 'dmarc') as $k) {
			if (preg_match('/\b'.$k.'=([a-z]+)/i', $value, $m)) {
				$out[$k] = strtolower($m[1]);
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
	 * Report of non delivery (multipart/report; report-type=delivery-status)
	 *
	 * @param string                              $raw     Raw mail
	 * @param array<int,array{0:string,1:string}> $headers Headers
	 * @return array{recipients:string[],status:string,reason:string}|null
	 */
	public static function deliveryReport($raw, array $headers)
	{
		$type = strtolower(self::headerValue($headers, 'Content-Type'));
		if (strpos($type, 'multipart/report') !== 0 || strpos($type, 'delivery-status') === false) {
			return null;
		}
		$body = substr((string) $raw, 0, 262144);
		$out = array('recipients' => array(), 'status' => '', 'reason' => '');
		if (preg_match_all('/^Final-Recipient:\s*[^;]*;\s*<?([^\s>]+)>?/mi', $body, $m)) {
			$out['recipients'] = array_slice(array_values(array_unique(array_map(function ($v) {
				return InboxMimeParser::clean(strtolower($v));
			}, $m[1]))), 0, 20);
		}
		if (preg_match('/^Status:\s*([245]\.\d{1,3}\.\d{1,3})/mi', $body, $m)) {
			$out['status'] = $m[1];
		}
		if (preg_match('/^Diagnostic-Code:\s*[^;]*;\s*(.+(?:\r?\n[ \t].+)*)/mi', $body, $m)) {
			$out['reason'] = mb_substr(InboxMimeParser::clean(preg_replace('/\s+/', ' ', $m[1])), 0, 300, 'UTF-8');
		}
		if (!$out['recipients'] && $out['status'] === '') {
			return null;
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
