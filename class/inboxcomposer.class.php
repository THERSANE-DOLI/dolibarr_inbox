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
 * \file    inbox/class/inboxcomposer.class.php
 * \ingroup inbox
 * \brief   Writing of a mail (lot W6 1.16.0): new, reply, forward, drafts on the server, sending, copy in Sent
 */

require_once __DIR__.'/inboxaccess.class.php';
require_once __DIR__.'/inboxaccount.class.php';
require_once __DIR__.'/inboxidentity.class.php';
require_once __DIR__.'/inboxmailbuilder.class.php';
require_once __DIR__.'/inboxmimeparser.class.php';
require_once __DIR__.'/inboxreader.class.php';
require_once __DIR__.'/inboxactionlog.class.php';
require_once __DIR__.'/inboxattachmentscan.class.php';
require_once __DIR__.'/inboxfolder.class.php';
require_once __DIR__.'/connector/inboxconnectorfactory.class.php';

/**
 * A writing is kept on the server under a random token (folder of the user in the temporary folder of inbox): mode,
 * mail answered, quote, attachments, draft on the mail server. The browser only gives back the token and what the
 * user typed: the quote, the headers of the answer, the files of a forward and the signature are always read again
 * here, never taken from the browser.
 */
class InboxComposer
{
	/** @var string[] */
	const MODES = array('new', 'reply', 'replyall', 'replylist', 'forward', 'forward_attach', 'draft');

	/** @var int Days a writing left open is kept in the temporary folder */
	const KEEP_DAYS = 7;

	/** @var DoliDB */
	protected $db;

	/** @var string */
	public $error = '';

	/** @var string Code for the browser: not_found, no_access, no_send, mails_disabled, bad_address, too_big, smtp_..., no_drafts */
	public $errorCode = '';

	/** @var string[] Addresses refused (bad_address) */
	public $bad = array();

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct(DoliDB $db)
	{
		$this->db = $db;
	}

	/**
	 * @param string $code   Code
	 * @param string $detail Detail (log, never a secret)
	 * @return null
	 */
	protected function fail($code, $detail = '')
	{
		$this->errorCode = $code;
		$this->error = $detail !== '' ? $detail : $code;

		return null;
	}

	/**
	 * @param User $user User
	 * @return string Folder of the writings of the user
	 */
	protected static function userDir(User $user)
	{
		return self::baseDir().'/'.((int) $user->id);
	}

	/**
	 * @return string Folder of the writings: in the temporary folder of inbox, else (command line not allowed to write
	 *                there) in the temporary folder of the system
	 */
	protected static function baseDir()
	{
		global $conf;

		$dir = $conf->inbox->dir_temp ?? (DOL_DATA_ROOT.'/inbox/temp');
		if (!is_dir($dir)) {
			dol_mkdir($dir);
		}
		if (!is_writable($dir)) {
			$dir = sys_get_temp_dir().'/dolibarr-inbox';
		}

		return $dir.'/compose';
	}

	/**
	 * Path of the special folder of a role in the index (the folder flagged by the server first), or the one chosen
	 * in the card of the mailbox
	 *
	 * @param InboxAccount $account Mailbox
	 * @param string       $role    sent, drafts
	 * @return string Path, '' if none
	 */
	public function roleFolder(InboxAccount $account, $role)
	{
		$chosen = $account->getFolderRoles();
		if (!empty($chosen[$role])) {
			return (string) $chosen[$role];
		}
		$rows = $this->db->getRows("SELECT path, flags FROM ".$this->db->prefix()."inbox_folder WHERE fk_account = ".((int) $account->id)." AND role = '".$this->db->escape($role)."'"
			." AND status = ".InboxFolder::STATUS_PRESENT." AND selectable = 1 ORDER BY LENGTH(path) LIMIT 10");
		foreach (is_array($rows) ? $rows : array() as $r) {
			if (stripos((string) $r->flags, '\\'.$role) !== false) {
				return (string) $r->path;
			}
		}

		return is_array($rows) && $rows ? (string) $rows[0]->path : '';
	}

	/**
	 * @param User   $user  User
	 * @param string $token Token
	 * @return string|null Folder of the writing, null if the token is not one of the user
	 */
	protected static function dirOf(User $user, $token)
	{
		if (!preg_match('/^[0-9a-f]{32}$/', (string) $token)) {
			return null;
		}
		$dir = self::userDir($user).'/'.$token;

		return is_file($dir.'/state.json') ? $dir : null;
	}

	/**
	 * @param string $dir Folder of the writing
	 * @return array<string,mixed>
	 */
	protected static function readState($dir)
	{
		$state = json_decode((string) @file_get_contents($dir.'/state.json'), true);

		return is_array($state) ? $state : array();
	}

	/**
	 * @param string              $dir   Folder of the writing
	 * @param array<string,mixed> $state State
	 * @return void
	 */
	protected static function writeState($dir, array $state)
	{
		$state['tms'] = dol_now();
		file_put_contents($dir.'/state.json', json_encode($state), LOCK_EX);
	}

	/**
	 * Identities the user may send with: active identities of the mailboxes where the user has the level « send »
	 *
	 * @param User $user User
	 * @return array<int,array{id:int,account:int,email:string,name:string,mailbox:string,signature:string,default:int}>
	 */
	public function identities(User $user)
	{
		$access = new InboxAccess($this->db);
		$out = array();
		foreach ($access->getAccounts($user, InboxAccess::LEVEL_SEND) as $accountId => $account) {
			$identity = new InboxIdentity($this->db);
			$list = $identity->listForAccount((int) $accountId, true);
			foreach ($list as $i) {
				$out[] = array('id' => (int) $i->id, 'account' => (int) $accountId, 'email' => (string) $i->email, 'name' => (string) $i->name,
					'mailbox' => (string) $account->email, 'signature' => (string) $i->signature, 'default' => (int) $i->is_default);
			}
			// A mailbox without identity sends with its own address (as any mail software), no signature: id = -mailbox
			if (!$list) {
				$label = trim((string) $account->label);
				$out[] = array('id' => -((int) $accountId), 'account' => (int) $accountId, 'email' => (string) $account->email,
					'name' => $label !== '' && strcasecmp($label, (string) $account->email) !== 0 ? $label : '', 'mailbox' => (string) $account->email, 'signature' => '', 'default' => 1);
			}
		}

		return $out;
	}

	/**
	 * Open a writing
	 *
	 * @param User $user      User
	 * @param string $mode    One of MODES
	 * @param int  $mailId    Mail answered, forwarded, or draft reopened (0 for a new mail)
	 * @param int  $accountId Mailbox shown when writing a new mail (identity chosen in it first)
	 * @return array<string,mixed>|null
	 */
	public function open(User $user, $mode, $mailId = 0, $accountId = 0)
	{
		global $langs;

		$this->error = '';
		$this->errorCode = '';
		if (!in_array($mode, self::MODES, true)) {
			return $this->fail('bad_mode');
		}
		$identities = $this->identities($user);
		if (!$identities) {
			return $this->fail('no_send');
		}
		$state = array('user' => (int) $user->id, 'mode' => $mode, 'source' => null, 'draft' => null, 'attachments' => array(), 'quote' => 0);
		$out = array('to' => array(), 'cc' => array(), 'bcc' => array(), 'subject' => '', 'html' => '', 'quote' => '', 'receipt' => 0);
		$sourceAccount = (int) $accountId;
		$raw = null;
		$message = null;
		$reader = null;
		if ($mode !== 'new') {
			$reader = new InboxReader($this->db);
			if ((int) $mailId <= 0 || !$reader->load($user, (int) $mailId)) {
				return $this->fail('not_found');
			}
			$raw = $reader->raw($user);
			if ($raw === null) {
				return $this->fail($reader->errorCode === 'gone' ? 'not_found' : 'error', $reader->error);
			}
			$message = InboxMimeParser::parse($raw);
			$sourceAccount = (int) $reader->row->fk_account;
		}
		$token = bin2hex(random_bytes(16));
		$dir = self::userDir($user).'/'.$token;
		if (dol_mkdir($dir) < 0 || !is_dir($dir)) {
			return $this->fail('error', 'Cannot create '.$dir);
		}
		$identityId = 0;
		if ($mode === 'draft') {
			if ((string) $reader->row->folder_role !== 'drafts') {
				dol_delete_dir_recursive($dir);
				return $this->fail('not_found', 'Not a draft');
			}
			$state['draft'] = array('account' => (int) $reader->row->fk_account, 'folder' => (string) $reader->row->folder_path, 'remote_id' => (string) $reader->row->remote_id, 'mail' => (int) $reader->row->rowid);
			$headers = InboxReader::headers($raw);
			$h = function ($name) use ($headers) {
				foreach ($headers as $pair) {
					if (strcasecmp($pair[0], $name) === 0) {
						return (string) $pair[1];
					}
				}
				return '';
			};
			$out['to'] = $message->to;
			$out['cc'] = $message->cc;
			$out['bcc'] = InboxMailBuilder::parseAddresses($h('Bcc'));
			$out['subject'] = $message->subject;
			$out['receipt'] = $h('Disposition-Notification-To') !== '' ? 1 : 0;
			$identityId = (int) $h('X-Inbox-Identity');
			$parts = self::splitDraft($message->bodyHtml !== '' ? $message->bodyHtml : nl2br(dol_escape_htmltag($message->bodyText)));
			$out['html'] = $parts['body'];
			$out['signature_off'] = $parts['signature'] === '' ? 1 : 0;
			if ($parts['quote'] !== '') {
				file_put_contents($dir.'/quote.html', $parts['quote']);
				$state['quote'] = 1;
			}
			// The mail answered, found again by its Message-ID in the mailboxes of the user
			$replyMode = (string) $h('X-Inbox-Mode');
			$answered = trim($h('X-Inbox-Reply'), " <>");
			if ($answered !== '' && in_array($replyMode, array('reply', 'replyall', 'replylist', 'forward', 'forward_attach'), true)) {
				$found = $this->findMail($user, $answered, (int) $reader->row->fk_account);
				if ($found) {
					$state['source'] = $found + array('mode' => $replyMode);
				}
			}
			$state['source_refs'] = array_values(array_filter(array_map(function ($r) {
				return trim($r, '<>');
			}, preg_split('/\s+/', $h('References')))));
			$state['in_reply_to'] = trim($h('In-Reply-To'), " <>");
			foreach ($message->getFileAttachments() as $a) {
				$this->keepFile($dir, $state, $a['name'], $a['content'], $a['mime']);
			}
		} elseif ($mode !== 'new') {
			$row = $reader->row;
			$state['source'] = array('mail' => (int) $row->rowid, 'account' => (int) $row->fk_account, 'folder' => (string) $row->folder_path,
				'remote_id' => (string) $row->remote_id, 'message_id' => (string) $message->messageId, 'mode' => $mode);
			$headers = InboxReader::headers($raw);
			$state['source_refs'] = array();
			foreach ($headers as $pair) {
				if (strcasecmp($pair[0], 'References') === 0) {
					$state['source_refs'] = array_values(array_filter(array_map(function ($r) {
						return trim($r, '<>');
					}, preg_split('/\s+/', (string) $pair[1]))));
				}
			}
			$own = array_map('strtolower', array_merge(array((string) $reader->account->email), array_column(array_filter($identities, function ($i) use ($sourceAccount) {
				return $i['account'] === $sourceAccount;
			}), 'email')));
			$this->recipientsOf($mode, $message, $headers, $own, $out);
			$out['subject'] = self::subjectFor($mode, (string) $message->subject);
			if ($mode === 'forward_attach') {
				$this->keepFile($dir, $state, InboxMimeParser::fileName(($message->subject !== '' ? $message->subject : 'mail').'.eml'), $raw, 'message/rfc822');
			} else {
				file_put_contents($dir.'/quote.html', $this->quoteOf($mode, $message));
				$state['quote'] = 1;
				if ($mode === 'forward') {
					foreach ($message->getFileAttachments() as $a) {
						$this->keepFile($dir, $state, $a['name'], $a['content'], $a['mime']);
					}
				}
			}
			// The identity whose address the sender wrote to (B18), in the mailbox that got the mail
			foreach ($identities as $i) {
				if ($i['account'] === $sourceAccount && in_array(strtolower($i['email']), array_map(function ($a) {
					return strtolower($a['email']);
				}, array_merge($message->to, $message->cc)), true)) {
					$identityId = $i['id'];
					break;
				}
			}
		}
		// Identity: the one of the draft, else the one written to, else the default of the mailbox, else the first one
		$ids = array_column($identities, 'id');
		if (!in_array($identityId, $ids, true)) {
			$identityId = 0;
			foreach ($identities as $i) {
				if ($i['account'] === $sourceAccount && ($identityId === 0 || $i['default'])) {
					$identityId = $i['id'];
				}
			}
			if ($identityId === 0) {
				$identityId = $identities[0]['id'];
			}
		}
		self::writeState($dir, $state);
		$this->hold($token);

		$out['token'] = $token;
		$out['mode'] = $mode;
		$out['identity'] = $identityId;
		$out['identities'] = $identities;
		$out['quote'] = !empty($state['quote']) ? (string) @file_get_contents($dir.'/quote.html') : '';
		$out['attachments'] = self::attachmentsView($state);
		$out['source'] = $state['source'] ? array('mail' => (int) ($state['source']['mail'] ?? 0), 'mode' => (string) $state['source']['mode']) : null;
		$out['draft'] = $state['draft'] ? 1 : 0;
		$out['max_size'] = self::maxBytes();
		$out['autocopy'] = '';
		$langs->loadLangs(array('inbox@inbox'));

		return $out;
	}

	/**
	 * Token kept in the session of the user (a writing opened in another session is refused)
	 *
	 * @param string $token Token
	 * @return void
	 */
	protected function hold($token)
	{
		if (!isset($_SESSION['inbox_compose']) || !is_array($_SESSION['inbox_compose'])) {
			$_SESSION['inbox_compose'] = array();
		}
		$_SESSION['inbox_compose'][$token] = dol_now();
		if (count($_SESSION['inbox_compose']) > 50) {
			asort($_SESSION['inbox_compose']);
			$_SESSION['inbox_compose'] = array_slice($_SESSION['inbox_compose'], -50, null, true);
		}
	}

	/**
	 * @param User   $user  User
	 * @param string $token Token
	 * @return string|null Folder, if the writing is one of this user and this session
	 */
	protected function held(User $user, $token)
	{
		$dir = self::dirOf($user, $token);
		if ($dir === null || empty($_SESSION['inbox_compose'][$token])) {
			return null;
		}
		$state = self::readState($dir);

		return (int) ($state['user'] ?? 0) === (int) $user->id ? $dir : null;
	}

	/**
	 * A mail of the mailboxes the user reads, by its Message-ID (the given mailbox first)
	 *
	 * @param User   $user      User
	 * @param string $messageId Message-ID
	 * @param int    $accountId Mailbox first
	 * @return array{mail:int,account:int,folder:string,remote_id:string,message_id:string}|null
	 */
	protected function findMail(User $user, $messageId, $accountId)
	{
		$access = new InboxAccess($this->db);
		$readable = array_map('intval', array_keys($access->getAccounts($user, InboxAccess::LEVEL_READ)));
		if (!$readable || $messageId === '') {
			return null;
		}
		$p = $this->db->prefix();
		$row = $this->db->getRow("SELECT m.rowid, m.fk_account, m.remote_id, f.path FROM ".$p."inbox_mail as m INNER JOIN ".$p."inbox_folder as f ON f.rowid = m.fk_folder"
			." WHERE m.message_id = '".$this->db->escape($messageId)."' AND m.fk_account IN (".implode(',', $readable).") AND f.role <> 'drafts'"
			." ORDER BY (m.fk_account = ".((int) $accountId).") DESC, m.rowid DESC");
		if (!is_object($row)) {
			return null;
		}

		return array('mail' => (int) $row->rowid, 'account' => (int) $row->fk_account, 'folder' => (string) $row->path, 'remote_id' => (string) $row->remote_id, 'message_id' => $messageId);
	}

	/**
	 * Recipients of an answer (B20): Reply-To first, all the others for « reply all » without the addresses of the
	 * mailbox, the list alone for « reply to the list »; a mail of the mailbox itself (Sent) is answered to its
	 * recipients
	 *
	 * @param string              $mode    Mode
	 * @param InboxMessage        $message Mail answered
	 * @param array<int,string[]> $headers Headers
	 * @param string[]            $own     Addresses of the mailbox (lower case)
	 * @param array<string,mixed> $out     Data of the writing (to, cc filled)
	 * @return void
	 */
	protected function recipientsOf($mode, $message, array $headers, array $own, array &$out)
	{
		if ($mode === 'forward' || $mode === 'forward_attach') {
			return;
		}
		$from = array('email' => strtolower((string) $message->fromEmail), 'name' => (string) $message->fromName);
		$replyTo = array();
		foreach ($headers as $pair) {
			if (strcasecmp($pair[0], 'Reply-To') === 0) {
				$replyTo = InboxMailBuilder::parseAddresses((string) $pair[1]);
			}
		}
		$mine = in_array($from['email'], $own, true);
		if ($mode === 'replylist') {
			foreach ($headers as $pair) {
				if (strcasecmp($pair[0], 'List-Post') === 0 && preg_match('/<mailto:([^>?]+)/i', (string) $pair[1], $m)) {
					$out['to'] = InboxMailBuilder::parseAddresses(rawurldecode($m[1]));
				}
			}
			if ($out['to']) {
				return;
			}
		}
		$first = $mine ? $message->to : ($replyTo ?: array($from));
		$out['to'] = InboxMailBuilder::parseAddresses($first);
		if ($mode === 'replyall') {
			$taken = array_merge($own, array_column($out['to'], 'email'));
			$others = array();
			foreach (array_merge($mine ? array() : $message->to, $message->cc) as $a) {
				$e = strtolower((string) $a['email']);
				if (!in_array($e, $taken, true)) {
					$others[] = $a;
					$taken[] = $e;
				}
			}
			$out['cc'] = InboxMailBuilder::parseAddresses($others);
		}
	}

	/**
	 * Subject of an answer or a forward: one « Re: » or « Fwd: », never « Re: Re: »
	 *
	 * @param string $mode    Mode
	 * @param string $subject Subject of the mail
	 * @return string
	 */
	public static function subjectFor($mode, $subject)
	{
		$forward = in_array($mode, array('forward', 'forward_attach'), true);
		$prefix = $forward ? 'Fwd: ' : 'Re: ';
		$bare = preg_replace('/^(\s*(re|aw|sv|antw|tr|fwd?|wg|rv|r|réf|ref)\s*(\[\d+\])?\s*:\s*)+/iu', '', (string) $subject);
		if (!$forward && $bare !== $subject && preg_match('/^\s*re\s*:/i', (string) $subject)) {
			return 'Re: '.$bare;
		}

		return $prefix.$bare;
	}

	/**
	 * Quote of the mail answered or forwarded, made here from the source (never from the browser)
	 *
	 * @param string       $mode    Mode
	 * @param InboxMessage $message Mail
	 * @return string Clean HTML, in a div.inbox-quote
	 */
	protected function quoteOf($mode, $message)
	{
		global $langs;

		if ($message->bodyHtml !== '') {
			$body = $message->bodyHtml;
			// Pictures of the mail (cid:) are not sent again in an answer: their text stays
			$body = preg_replace('#<img\b[^>]*src=["\']?cid:[^>]*>#i', '', $body);
			$body = InboxMailBuilder::cleanHtml(preg_replace('#^.*<body\b[^>]*>|</body>.*$#is', '', $body));
		} else {
			$body = '<div style="white-space:pre-wrap">'.dol_escape_htmltag($message->bodyText).'</div>';
		}
		$who = trim($message->fromName) !== '' ? $message->fromName.' <'.$message->fromEmail.'>' : $message->fromEmail;
		$date = $message->date ? dol_print_date($message->date, 'dayhour', 'tzuserrel', $langs) : '';
		if ($mode === 'forward') {
			$list = function ($addresses) {
				return implode(', ', array_map(function ($a) {
					return trim($a['name']) !== '' ? $a['name'].' <'.$a['email'].'>' : $a['email'];
				}, $addresses));
			};
			$rows = array(
				array($langs->transnoentities('InboxSubject'), $message->subject),
				array($langs->transnoentities('InboxDate'), $date),
				array($langs->transnoentities('InboxFrom'), $who),
				array($langs->transnoentities('InboxTo'), $list($message->to)),
			);
			if ($message->cc) {
				$rows[] = array($langs->transnoentities('InboxComposeCc'), $list($message->cc));
			}
			$head = '<p>-------- '.dol_escape_htmltag($langs->transnoentities('InboxForwardedMessage')).' --------</p><table cellpadding="0" cellspacing="0" border="0">';
			foreach ($rows as $r) {
				$head .= '<tr><th align="right" valign="top" style="padding-right:6px;white-space:nowrap">'.dol_escape_htmltag($r[0]).' :</th><td>'.dol_escape_htmltag($r[1]).'</td></tr>';
			}
			$head .= '</table><br>';

			return '<div class="inbox-quote">'.$head.$body.'</div>';
		}

		return '<div class="inbox-quote"><p>'.dol_escape_htmltag($langs->transnoentities('InboxQuoteHeader', $date, $who)).'</p>'
			.'<blockquote type="cite" style="margin:0 0 0 0.8ex;border-left:2px solid #ccc;padding-left:1ex">'.$body.'</blockquote></div>';
	}

	/**
	 * A draft reopened: what the user typed, the signature, the quote
	 *
	 * @param string $html HTML of the draft
	 * @return array{body:string,signature:string,quote:string}
	 */
	protected static function splitDraft($html)
	{
		$html = preg_replace('#^.*<body\b[^>]*>|</body>.*$#is', '', (string) $html);
		$clean = InboxMailBuilder::cleanHtml($html);
		$out = array('body' => $clean, 'signature' => '', 'quote' => '');
		$doc = new DOMDocument();
		$previous = libxml_use_internal_errors(true);
		$doc->loadHTML('<?xml encoding="UTF-8"><html><body>'.$clean.'</body></html>', LIBXML_NONET | LIBXML_HTML_NODEFDTD);
		libxml_clear_errors();
		libxml_use_internal_errors($previous);
		$xpath = new DOMXPath($doc);
		foreach (array('quote' => 'inbox-quote', 'signature' => 'inbox-signature') as $key => $class) {
			$nodes = $xpath->query('//div[@class="'.$class.'"]');
			if ($nodes && $nodes->length) {
				$node = $nodes->item(0);
				$out[$key] = $doc->saveHTML($node);
				$node->parentNode->removeChild($node);
			}
		}
		$body = $doc->getElementsByTagName('body')->item(0);
		$out['body'] = '';
		foreach ($body ? $body->childNodes : array() as $child) {
			$out['body'] .= $doc->saveHTML($child);
		}

		return $out;
	}

	/**
	 * @return int Size of the attachments of a mail at most (bytes)
	 */
	public static function maxBytes()
	{
		return max(1, getDolGlobalInt('INBOX_SEND_MAX_MB', 20)) * 1048576;
	}

	/**
	 * A file of the writing (forwarded, of the draft, or sent by the browser), kept in its folder
	 *
	 * @param string              $dir     Folder
	 * @param array<string,mixed> $state   State (attachments added)
	 * @param string              $name    Name
	 * @param string              $content Content
	 * @param string              $mime    Type
	 * @return array{id:string,name:string,size:int,mime:string,scan:string}
	 */
	protected function keepFile($dir, array &$state, $name, $content, $mime)
	{
		$id = bin2hex(random_bytes(8));
		file_put_contents($dir.'/att-'.$id, (string) $content);
		$scan = new InboxAttachmentScan($this->db);
		$res = $scan->check((string) $content, (string) $name);
		$att = array('id' => $id, 'name' => InboxMimeParser::fileName((string) $name) ?: 'file', 'size' => strlen((string) $content),
			'mime' => preg_match('#^[a-z0-9.+\-]+/[a-z0-9.+\-]+$#i', (string) $mime) ? strtolower((string) $mime) : 'application/octet-stream', 'scan' => (string) $res['state']);
		$state['attachments'][] = $att;

		return $att;
	}

	/**
	 * @param array<string,mixed> $state State
	 * @return array<int,array{id:string,name:string,size:int,mime:string,scan:string}>
	 */
	protected static function attachmentsView(array $state)
	{
		return array_values((array) ($state['attachments'] ?? array()));
	}

	/**
	 * A file sent by the browser
	 *
	 * @param User   $user    User
	 * @param string $token   Writing
	 * @param string $name    Name
	 * @param string $tmpPath Uploaded file
	 * @param string $mime    Type given by the browser
	 * @return array{id:string,name:string,size:int,mime:string,scan:string}|null
	 */
	public function upload(User $user, $token, $name, $tmpPath, $mime)
	{
		$dir = $this->held($user, $token);
		if ($dir === null) {
			return $this->fail('not_found');
		}
		$state = self::readState($dir);
		$size = (int) @filesize($tmpPath);
		$total = array_sum(array_column((array) $state['attachments'], 'size'));
		if ($size <= 0) {
			return $this->fail('error', 'Empty file');
		}
		if ($total + $size > self::maxBytes()) {
			return $this->fail('too_big');
		}
		$att = $this->keepFile($dir, $state, $name, (string) file_get_contents($tmpPath), $mime);
		if ($att['scan'] === InboxAttachmentScan::INFECTED) {
			@unlink($dir.'/att-'.$att['id']);
			return $this->fail('infected');
		}
		self::writeState($dir, $state);

		return $att;
	}

	/**
	 * @param User   $user  User
	 * @param string $token Writing
	 * @param string $id    Attachment
	 * @return bool
	 */
	public function removeAttachment(User $user, $token, $id)
	{
		$dir = $this->held($user, $token);
		if ($dir === null || !preg_match('/^[0-9a-f]{16}$/', (string) $id)) {
			$this->fail('not_found');
			return false;
		}
		$state = self::readState($dir);
		$state['attachments'] = array_values(array_filter((array) $state['attachments'], function ($a) use ($id) {
			return $a['id'] !== $id;
		}));
		@unlink($dir.'/att-'.$id);
		self::writeState($dir, $state);

		return true;
	}

	/**
	 * The mail built from what the user typed and what the server knows
	 *
	 * @param User                $user  User
	 * @param string              $dir   Folder of the writing
	 * @param array<string,mixed> $state State
	 * @param array<string,mixed> $data  Typed: identity, to, cc, bcc, subject, html, quote (0/1), signature (0/1), receipt
	 * @param bool                $draft For a draft (private headers, no check of the recipients)
	 * @return array{builder:InboxMailBuilder,account:InboxAccount,identity:array<string,mixed>}|null
	 */
	protected function prepare(User $user, $dir, array $state, array $data, $draft)
	{
		$identity = null;
		foreach ($this->identities($user) as $i) {
			if ($i['id'] === (int) ($data['identity'] ?? 0)) {
				$identity = $i;
			}
		}
		if (!$identity) {
			return $this->fail('no_send');
		}
		$account = new InboxAccount($this->db);
		if ($account->fetch($identity['account']) <= 0) {
			return $this->fail('no_send');
		}
		$b = new InboxMailBuilder();
		$bad = array();
		$b->from = array('email' => $identity['email'], 'name' => $identity['name']);
		$b->to = InboxMailBuilder::parseAddresses($data['to'] ?? array(), $bad);
		$b->cc = InboxMailBuilder::parseAddresses($data['cc'] ?? array(), $bad);
		$b->bcc = InboxMailBuilder::parseAddresses($data['bcc'] ?? array(), $bad);
		if ($bad && !$draft) {
			$this->bad = $bad;
			return $this->fail('bad_address', implode(', ', $bad));
		}
		$identityObj = new InboxIdentity($this->db);
		if ($identity['id'] > 0 && $identityObj->fetch($identity['id']) > 0) {
			$b->replyTo = (string) $identityObj->reply_to;
			if (!$draft && trim((string) $identityObj->bcc) !== '') {
				$b->bcc = InboxMailBuilder::parseAddresses(array_merge($b->bcc, InboxMailBuilder::parseAddresses((string) $identityObj->bcc)));
			}
		}
		// Copy of Dolibarr (MAIN_MAIL_AUTOCOPY_TO): only when the setup of inbox asks it (decided 2026-11-07)
		if (!$draft && getDolGlobalInt('INBOX_SEND_AUTOCOPY') && getDolGlobalString('MAIN_MAIL_AUTOCOPY_TO') !== '') {
			$copy = array();
			foreach (explode(',', getDolGlobalString('MAIN_MAIL_AUTOCOPY_TO')) as $e) {
				$e = trim($e) === '__USER_EMAIL__' ? (string) $user->email : trim($e);
				if ($e !== '') {
					$copy[] = $e;
				}
			}
			$b->bcc = InboxMailBuilder::parseAddresses(array_merge($b->bcc, InboxMailBuilder::parseAddresses(implode(',', $copy))));
		}
		$b->subject = dol_trunc(InboxMimeParser::clean((string) ($data['subject'] ?? '')), 998, 'right', 'UTF-8', 1);
		$html = (string) ($data['html'] ?? '');
		if (strlen($html) > InboxMimeParser::MAX_HTML_LENGTH) {
			return $this->fail('too_big');
		}
		$html = InboxMailBuilder::cleanHtml($html);
		if (!empty($data['signature']) && trim($identity['signature']) !== '') {
			$html .= '<div class="inbox-signature">-- <br>'.InboxMailBuilder::cleanHtml($identity['signature']).'</div>';
		}
		if (!empty($state['quote']) && !empty($data['quote']) && is_file($dir.'/quote.html')) {
			$html .= '<br>'.(string) file_get_contents($dir.'/quote.html');
		}
		$b->html = $html;
		$b->receipt = !empty($data['receipt']);
		$source = $state['source'] ?? null;
		if ($source && in_array($source['mode'], array('reply', 'replyall', 'replylist', 'forward', 'forward_attach'), true)) {
			$isReply = !in_array($source['mode'], array('forward', 'forward_attach'), true);
			if ($isReply && (string) ($source['message_id'] ?? '') !== '') {
				$b->inReplyTo = (string) $source['message_id'];
				$b->references = array_merge((array) ($state['source_refs'] ?? array()), array((string) $source['message_id']));
			}
		} elseif (!empty($state['in_reply_to'])) {
			$b->inReplyTo = (string) $state['in_reply_to'];
			$b->references = (array) ($state['source_refs'] ?? array());
		}
		$total = 0;
		foreach ((array) $state['attachments'] as $a) {
			$path = $dir.'/att-'.$a['id'];
			if (!is_file($path)) {
				continue;
			}
			if ($a['scan'] === InboxAttachmentScan::INFECTED) {
				return $this->fail('infected', $a['name']);
			}
			$total += (int) $a['size'];
			$b->attachments[] = array('path' => $path, 'name' => $a['name'], 'mime' => $a['mime']);
		}
		if ($total > self::maxBytes()) {
			return $this->fail('too_big');
		}
		if ($draft) {
			$b->extraHeaders['X-Inbox-Identity'] = (string) $identity['id'];
			if ($source) {
				$b->extraHeaders['X-Inbox-Mode'] = (string) $source['mode'];
				$b->extraHeaders['X-Inbox-Reply'] = '<'.((string) ($source['message_id'] ?? '')).'>';
			}
		}

		return array('builder' => $b, 'account' => $account, 'identity' => $identity);
	}

	/**
	 * Save the draft in the drafts folder of the mailbox of the identity: the new one put and checked, then the
	 * previous one removed (P8)
	 *
	 * @param User                $user  User
	 * @param string              $token Writing
	 * @param array<string,mixed> $data  Typed
	 * @return array{saved:int}|null
	 */
	public function saveDraft(User $user, $token, array $data)
	{
		$this->error = '';
		$this->errorCode = '';
		$dir = $this->held($user, $token);
		if ($dir === null) {
			return $this->fail('not_found');
		}
		$state = self::readState($dir);
		$prepared = $this->prepare($user, $dir, $state, $data, true);
		if (!$prepared) {
			return null;
		}
		$account = $prepared['account'];
		$roles = array('drafts' => $this->roleFolder($account, 'drafts'));
		if (empty($roles['drafts'])) {
			return $this->fail('no_drafts');
		}
		$built = $prepared['builder']->build();
		if (!$built) {
			return $this->fail('error', $prepared['builder']->error);
		}
		$error = '';
		$connector = InboxConnectorFactory::forAccount($this->db, $account, $error);
		if (!$connector) {
			return $this->fail('error', $error);
		}
		$id = $connector->importRaw($roles['drafts'], $built['raw'], array('\\Draft', '\\Seen'));
		if ($id === null) {
			$this->fail('error', $connector->getError());
			$connector->disconnect();
			return null;
		}
		$previous = $state['draft'] ?? null;
		$state['draft'] = array('account' => (int) $account->id, 'folder' => (string) $roles['drafts'], 'remote_id' => (string) $id, 'mail' => 0);
		self::writeState($dir, $state);
		$this->removeDraft($previous, $connector, (int) $account->id);
		$connector->disconnect();

		return array('saved' => dol_now());
	}

	/**
	 * A previous version of a draft removed from the server and the index
	 *
	 * @param array<string,mixed>|null     $draft     Draft (account, folder, remote_id, mail)
	 * @param InboxConnectorInterface|null $connector Connector of the mailbox of the new draft
	 * @param int                          $accountId Its mailbox
	 * @return void
	 */
	protected function removeDraft($draft, $connector, $accountId)
	{
		if (!$draft || (string) ($draft['remote_id'] ?? '') === '') {
			return;
		}
		$own = null;
		if ((int) $draft['account'] !== $accountId || !$connector) {
			// The identity changed of mailbox: the old draft is in the other one
			$account = new InboxAccount($this->db);
			$error = '';
			if ($account->fetch((int) $draft['account']) > 0) {
				$own = InboxConnectorFactory::forAccount($this->db, $account, $error);
			}
			$connector = $own;
		}
		if ($connector && $connector->deleteMails((string) $draft['folder'], array((string) $draft['remote_id']))) {
			$p = $this->db->prefix();
			$rows = $this->db->getRows("SELECT m.rowid FROM ".$p."inbox_mail as m INNER JOIN ".$p."inbox_folder as f ON f.rowid = m.fk_folder WHERE m.fk_account = ".((int) $draft['account'])
				." AND f.path = '".$this->db->escape((string) $draft['folder'])."' AND m.remote_id = '".$this->db->escape((string) $draft['remote_id'])."' LIMIT 5");
			foreach (is_array($rows) ? $rows : array() as $r) {
				$this->db->query("DELETE FROM ".$p."inbox_mail_address WHERE fk_mail = ".((int) $r->rowid));
				$this->db->query("DELETE FROM ".$p."inbox_mail WHERE rowid = ".((int) $r->rowid));
			}
		}
		if ($own) {
			$own->disconnect();
		}
	}

	/**
	 * Close a writing without sending: its draft removed from the server when asked, its folder removed
	 *
	 * @param User   $user        User
	 * @param string $token       Writing
	 * @param bool   $removeDraft Remove the draft from the server
	 * @return bool
	 */
	public function close(User $user, $token, $removeDraft)
	{
		$dir = $this->held($user, $token);
		if ($dir === null) {
			$this->fail('not_found');
			return false;
		}
		$state = self::readState($dir);
		if ($removeDraft && !empty($state['draft'])) {
			$this->removeDraft($state['draft'], null, 0);
		}
		dol_delete_dir_recursive($dir);
		unset($_SESSION['inbox_compose'][$token]);

		return true;
	}

	/**
	 * Send: built here, sent by the SMTP server of the mailbox, its copy put in Sent (with the Bcc), the mail answered
	 * marked, the draft removed, the journal written
	 *
	 * @param User                $user  User
	 * @param string              $token Writing
	 * @param array<string,mixed> $data  Typed
	 * @return array{message_id:string,sent_copy:int,account:int}|null
	 */
	public function send(User $user, $token, array $data)
	{
		global $hookmanager;

		$this->error = '';
		$this->errorCode = '';
		$this->bad = array();
		$dir = $this->held($user, $token);
		if ($dir === null) {
			return $this->fail('not_found');
		}
		$state = self::readState($dir);
		$prepared = $this->prepare($user, $dir, $state, $data, false);
		if (!$prepared) {
			return null;
		}
		$b = $prepared['builder'];
		$account = $prepared['account'];
		if (!array_merge($b->to, $b->cc, $b->bcc)) {
			return $this->fail('no_recipient');
		}
		if (getDolGlobalString('MAIN_DISABLE_ALL_MAILS')) {
			return $this->fail('mails_disabled');
		}
		// Other modules may check the mail and stop it (P5): hook inboxBeforeSend, context inbox
		if (is_object($hookmanager)) {
			$hookmanager->initHooks(array('inbox'));
			$parameters = array('account' => (int) $account->id, 'identity' => $prepared['identity']['id'], 'to' => $b->to, 'cc' => $b->cc, 'bcc' => $b->bcc, 'subject' => $b->subject, 'source' => $state['source']);
			$action = 'send';
			$reshook = $hookmanager->executeHooks('inboxBeforeSend', $parameters, $b, $action);
			if ($reshook < 0) {
				return $this->fail('hook', $hookmanager->error ?: implode(', ', (array) $hookmanager->errors));
			}
		}
		$built = $b->build();
		if (!$built) {
			return $this->fail('error', $b->error);
		}
		$error = '';
		$connector = InboxConnectorFactory::forAccount($this->db, $account, $error);
		if (!$connector) {
			return $this->fail('error', $error);
		}
		if (!$connector->can(InboxConnectorInterface::CAP_SEND)) {
			$connector->disconnect();
			return $this->fail('smtp_no_server');
		}
		if (!$connector->send($built['wire'], array('from' => $b->from['email'], 'recipients' => $built['recipients']))) {
			$code = method_exists($connector, 'getErrorCode') ? (string) $connector->getErrorCode() : '';
			$this->fail($code !== '' ? $code : 'smtp_refused', $connector->getError());
			$connector->disconnect();
			return null;
		}
		// Sent: from here nothing may make the user send it again
		$roles = array('sent' => $this->roleFolder($account, 'sent'), 'drafts' => $this->roleFolder($account, 'drafts'));
		$sentCopy = 0;
		if (!empty($roles['sent'])) {
			$sentCopy = $connector->importRaw($roles['sent'], $built['raw'], array('\\Seen')) !== null ? 1 : 0;
			if (!$sentCopy) {
				dol_syslog(__METHOD__.' copy in Sent failed: '.$connector->getError(), LOG_WARNING);
			}
		}
		$source = $state['source'] ?? null;
		if ($source && !in_array($source['mode'], array('forward', 'forward_attach'), true) && (int) $source['mail'] > 0) {
			$this->markAnswered($user, $source, (int) $account->id === (int) $source['account'] ? $connector : null);
		}
		if (!empty($state['draft'])) {
			$this->removeDraft($state['draft'], $connector, (int) $account->id);
		}
		$connector->disconnect();
		InboxActionLog::add($this->db, $user, (int) $account->id, 'send', array((object) array('message_id' => $built['message_id'], 'subject' => $b->subject)),
			'', (string) ($roles['sent'] ?? ''), 0, (string) count($built['recipients']));
		$account->context['inbox'] = array('message_id' => $built['message_id'], 'identity' => $prepared['identity']['id'], 'recipients' => $built['recipients'],
			'subject' => $b->subject, 'source' => $source ? (string) ($source['message_id'] ?? '') : '', 'mode' => $source ? (string) $source['mode'] : 'new');
		$account->call_trigger('INBOX_MAIL_SENT', $user);
		$this->refreshFolders($account, array_filter(array((string) ($roles['sent'] ?? ''), (string) ($roles['drafts'] ?? ''))));
		dol_delete_dir_recursive($dir);
		unset($_SESSION['inbox_compose'][$token]);

		return array('message_id' => $built['message_id'], 'sent_copy' => $sentCopy, 'account' => (int) $account->id);
	}

	/**
	 * The mail answered marked \Answered on the server, checked, then in the index (P8)
	 *
	 * @param User                         $user      User
	 * @param array<string,mixed>          $source    Mail answered
	 * @param InboxConnectorInterface|null $connector Connector of its mailbox (null: opened here)
	 * @return void
	 */
	protected function markAnswered(User $user, array $source, $connector)
	{
		$access = new InboxAccess($this->db);
		if ($access->getLevel($user, (int) $source['account']) === InboxAccess::LEVEL_NONE) {
			return;
		}
		$own = null;
		if (!$connector) {
			$account = new InboxAccount($this->db);
			$error = '';
			if ($account->fetch((int) $source['account']) > 0) {
				$own = InboxConnectorFactory::forAccount($this->db, $account, $error);
			}
			$connector = $own;
		}
		if ($connector && $connector->setFlags((string) $source['folder'], array((string) $source['remote_id']), array('\\Answered'), array())) {
			$this->db->query("UPDATE ".$this->db->prefix()."inbox_mail SET answered = 1 WHERE rowid = ".((int) $source['mail']));
		}
		if ($own) {
			$own->disconnect();
		}
	}

	/**
	 * Sent and Drafts read again (the mail sent shows in Sent at once)
	 *
	 * @param InboxAccount $account Mailbox
	 * @param string[]     $paths   Folders
	 * @return void
	 */
	protected function refreshFolders(InboxAccount $account, array $paths)
	{
		require_once __DIR__.'/inboxsync.class.php';
		$p = $this->db->prefix();
		foreach ($paths as $path) {
			$f = $this->db->getRow("SELECT rowid FROM ".$p."inbox_folder WHERE fk_account = ".((int) $account->id)." AND path = '".$this->db->escape($path)."'");
			if (is_object($f)) {
				$sync = new InboxSync($this->db);
				$sync->run($account, microtime(true) + 5, array('force' => 1, 'folder_id' => (int) $f->rowid));
			}
		}
	}

	/**
	 * Recipients proposed while typing: contacts, thirdparties and users of Dolibarr the user may see, then the
	 * correspondents of the mailboxes the user reads (last exchange first)
	 *
	 * @param User   $user User
	 * @param string $q    Typed (2 characters at least)
	 * @return array<int,array{email:string,name:string,detail:string,kind:string}>
	 */
	public function recipients(User $user, $q)
	{
		$q = trim((string) $q);
		if (mb_strlen($q, 'UTF-8') < 2) {
			return array();
		}
		$p = $this->db->prefix();
		$like = $this->db->escape($this->db->escapeforlike($q));
		$out = array();
		$add = function ($email, $name, $detail, $kind) use (&$out) {
			$email = strtolower(trim((string) $email));
			if ($email === '' || isset($out[$email]) || !filter_var($email, FILTER_VALIDATE_EMAIL) || count($out) >= 10) {
				return;
			}
			$out[$email] = array('email' => $email, 'name' => trim((string) $name), 'detail' => trim((string) $detail), 'kind' => $kind);
		};
		if (isModEnabled('societe') && $user->hasRight('societe', 'lire')) {
			$restrict = !$user->hasRight('societe', 'client', 'voir');
			$join = $restrict ? " INNER JOIN ".$p."societe_commerciaux as sc ON sc.fk_soc = s.rowid AND sc.fk_user = ".((int) $user->id) : '';
			if ($user->hasRight('societe', 'contact', 'lire')) {
				$rows = $this->db->getRows("SELECT c.email, c.firstname, c.lastname, s.nom FROM ".$p."socpeople as c LEFT JOIN ".$p."societe as s ON s.rowid = c.fk_soc".$join
					." WHERE c.entity IN (".getEntity('contact').") AND c.statut = 1 AND c.email <> '' AND (c.email LIKE '%".$like."%' OR c.lastname LIKE '%".$like."%' OR c.firstname LIKE '%".$like."%')"
					." AND (c.priv = 0 OR c.fk_user_creat = ".((int) $user->id).") ORDER BY c.lastname LIMIT 10");
				foreach (is_array($rows) ? $rows : array() as $r) {
					$add($r->email, trim($r->firstname.' '.$r->lastname), (string) $r->nom, 'contact');
				}
			}
			$rows = $this->db->getRows("SELECT s.email, s.nom FROM ".$p."societe as s".$join." WHERE s.entity IN (".getEntity('societe').") AND s.status = 1 AND s.email <> ''"
				." AND (s.email LIKE '%".$like."%' OR s.nom LIKE '%".$like."%') ORDER BY s.nom LIMIT 10");
			foreach (is_array($rows) ? $rows : array() as $r) {
				$add($r->email, (string) $r->nom, '', 'thirdparty');
			}
		}
		$rows = $this->db->getRows("SELECT email, firstname, lastname FROM ".$p."user WHERE statut = 1 AND email <> '' AND fk_soc IS NULL AND entity IN (0, ".getEntity('user').")"
			." AND (email LIKE '%".$like."%' OR lastname LIKE '%".$like."%' OR firstname LIKE '%".$like."%') ORDER BY lastname LIMIT 10");
		foreach (is_array($rows) ? $rows : array() as $r) {
			$add($r->email, trim($r->firstname.' '.$r->lastname), '', 'user');
		}
		$access = new InboxAccess($this->db);
		$readable = array_map('intval', array_keys($access->getAccounts($user, InboxAccess::LEVEL_READ)));
		if ($readable && count($out) < 10) {
			$rows = $this->db->getRows("SELECT a.email, MAX(a.name) as name, MAX(m.rowid) as last FROM ".$p."inbox_mail_address as a INNER JOIN ".$p."inbox_mail as m ON m.rowid = a.fk_mail"
				." WHERE m.fk_account IN (".implode(',', $readable).") AND (a.email LIKE '%".$like."%' OR a.name LIKE '%".$like."%') GROUP BY a.email ORDER BY last DESC LIMIT 20");
			foreach (is_array($rows) ? $rows : array() as $r) {
				$add($r->email, (string) $r->name, '', 'recent');
			}
		}

		return array_values($out);
	}

	/**
	 * Scheduled job / setup: writings left open more than KEEP_DAYS removed from the temporary folder
	 *
	 * @return int Removed
	 */
	public static function purge()
	{
		global $conf;

		$base = self::baseDir();
		$n = 0;
		foreach (glob($base.'/*/*', GLOB_ONLYDIR) ?: array() as $dir) {
			if (preg_match('#/\d+/[0-9a-f]{32}$#', $dir) && @filemtime($dir.'/state.json') < dol_now() - self::KEEP_DAYS * 86400) {
				dol_delete_dir_recursive($dir);
				$n++;
			}
		}

		return $n;
	}
}
