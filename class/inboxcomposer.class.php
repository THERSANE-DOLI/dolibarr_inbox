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

// dol_delete_dir_recursive(), dol_delete_file()...: the folders of the writings removed after a sending or a closing
require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
require_once __DIR__.'/inboxsafe.class.php';
require_once __DIR__.'/inboxaccess.class.php';
require_once __DIR__.'/inboxaccount.class.php';
require_once __DIR__.'/inboxidentity.class.php';
require_once __DIR__.'/inboxmailbuilder.class.php';
require_once __DIR__.'/inboxmimeparser.class.php';
require_once __DIR__.'/inboxreader.class.php';
require_once __DIR__.'/inboxactionlog.class.php';
require_once __DIR__.'/inboxattachmentscan.class.php';
require_once __DIR__.'/inboxmailref.class.php';
require_once __DIR__.'/inboxthreader.class.php';
require_once __DIR__.'/inboxfolder.class.php';
require_once __DIR__.'/inboxprotect.class.php';
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
	const MODES = array('new', 'reply', 'replyall', 'replylist', 'forward', 'forward_attach', 'draft', 'editnew', 'unsubscribe');

	/** @var int Documents of a piece listed at most */
	const MAX_DOCUMENTS = 100;

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
		// Protected with its content (1.89.0): its folders read on the server (no index)
		if ($account->isLive()) {
			require_once __DIR__.'/inboxlive.class.php';
			$live = new InboxLive($this->db);
			$f = $live->folder($account, 0, $role);
			$live->close();

			return $f ? (string) $f->path : '';
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
	public static function dirOf(User $user, $token)
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
	public static function readState($dir)
	{
		$state = json_decode((string) self::getFile($dir, 'state.json'), true);

		return is_array($state) ? $state : array();
	}

	/** Marker of a writing of a protected mailbox: its files are sealed by the key of the session (audit INBX-062) */
	const SEALED = '.sealed';

	/**
	 * @param string $dir Folder of the writing
	 * @return bool       Its files are sealed
	 */
	public static function isSealed($dir)
	{
		return is_file($dir.'/'.self::SEALED);
	}

	/**
	 * Associated data of a sealed file: it does not open in another writing nor under another name
	 *
	 * @param string $dir  Folder of the writing
	 * @param string $name File
	 * @return string
	 */
	protected static function sealAad($dir, $name)
	{
		return 'compose|'.basename((string) $dir).'|'.$name;
	}

	/**
	 * Write a file of the writing: sealed for a protected mailbox (never in clear on the disk, readable by a request
	 * of this browser in this session only), as is else
	 *
	 * @param string $dir     Folder of the writing
	 * @param string $name    File
	 * @param string $content Content
	 * @return bool
	 */
	public static function putFile($dir, $name, $content)
	{
		$content = (string) $content;
		if (self::isSealed($dir)) {
			$content = InboxProtect::seal($content, self::sealAad($dir, $name));
			if ($content === null) {
				dol_syslog(__METHOD__.' no key of the session: '.$name.' not written', LOG_WARNING);
				return false;
			}
		}

		return file_put_contents($dir.'/'.$name, $content, LOCK_EX) !== false;
	}

	/**
	 * @param string $dir  Folder of the writing
	 * @param string $name File
	 * @return string|null Content, null when missing or not readable in this session
	 */
	public static function getFile($dir, $name)
	{
		$content = @file_get_contents($dir.'/'.$name);
		if ($content === false) {
			return null;
		}

		return self::isSealed($dir) ? InboxProtect::unseal($content, self::sealAad($dir, $name)) : $content;
	}

	/**
	 * Seal the files of a writing written in clear: its mailbox became a protected one (identity changed)
	 *
	 * @param string $dir Folder of the writing
	 * @return bool       Sealed (or already)
	 */
	protected static function sealDir($dir)
	{
		if (self::isSealed($dir)) {
			return true;
		}
		if (InboxProtect::seal('', 'probe') === null) {
			return false;
		}
		$plain = array();
		foreach ((array) glob($dir.'/*') as $path) {
			if (is_file($path)) {
				$plain[basename($path)] = (string) file_get_contents($path);
			}
		}
		touch($dir.'/'.self::SEALED);
		foreach ($plain as $name => $content) {
			if (!self::putFile($dir, $name, $content)) {
				return false;
			}
		}

		return true;
	}

	/**
	 * A writing of this mailbox is sealed: a protected mailbox
	 *
	 * @param int $accountId Mailbox
	 * @return bool
	 */
	protected function protectedAccount($accountId)
	{
		if ((int) $accountId <= 0) {
			return false;
		}
		$account = new InboxAccount($this->db);

		return $account->fetch((int) $accountId) > 0 && $account->isProtected();
	}

	/**
	 * @param string              $dir   Folder of the writing
	 * @param array<string,mixed> $state State
	 * @return void
	 */
	protected static function writeState($dir, array $state)
	{
		$state['tms'] = dol_now();
		self::putFile($dir, 'state.json', (string) json_encode($state));
	}

	/**
	 * Identities the user may send with: active identities of the mailboxes where the user has the level « send »
	 *
	 * @param User $user User
	 * @return array<int,array{id:int,account:int,email:string,name:string,reply_to:string,mailbox:string,mailbox_label:string,signature:string,default:int}>
	 */
	public function identities(User $user)
	{
		$access = new InboxAccess($this->db);
		$out = array();
		foreach ($access->getAccounts($user, InboxAccess::LEVEL_SEND) as $accountId => $account) {
			$identity = new InboxIdentity($this->db);
			$list = $identity->listForAccount((int) $accountId, true);
			foreach ($list as $i) {
				$out[] = array('id' => (int) $i->id, 'account' => (int) $accountId, 'email' => (string) $i->email, 'name' => (string) $i->name, 'reply_to' => (string) $i->reply_to,
					'mailbox' => (string) $account->email, 'mailbox_label' => (string) $account->label, 'signature' => (string) $i->signature, 'default' => (int) $i->is_default);
			}
			// A mailbox without identity sends with its own address (as any mail software), no signature: id = -mailbox
			if (!$list) {
				$label = trim((string) $account->label);
				$out[] = array('id' => -((int) $accountId), 'account' => (int) $accountId, 'email' => (string) $account->email,
					'name' => $label !== '' && strcasecmp($label, (string) $account->email) !== 0 ? $label : '', 'reply_to' => '', 'mailbox' => (string) $account->email, 'mailbox_label' => $label,
					'signature' => '', 'default' => 1);
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
		// opened: an answer sent to the same mail after this moment is said before the sending (lot E2, 1.77.0)
		$state = array('user' => (int) $user->id, 'mode' => $mode, 'source' => null, 'draft' => null, 'attachments' => array(), 'quote' => 0, 'opened' => dol_now());
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
		$sealAccount = $sourceAccount;
		if ($sealAccount <= 0) {
			foreach ($identities as $i) {
				if (!empty($i['default'])) {
					$sealAccount = (int) $i['account'];
				}
			}
		}
		if ($this->protectedAccount($sealAccount)) {
			// The key of the session is there while the mailbox is unlocked: never a file in clear without it
			if (InboxProtect::seal('', 'probe') === null) {
				dol_delete_dir_recursive($dir);
				return $this->fail('error', 'Protected mailbox not unlocked in this session');
			}
			touch($dir.'/'.self::SEALED);
		}
		if ($mode === 'draft') {
			if ((string) $reader->row->folder_role !== 'drafts') {
				dol_delete_dir_recursive($dir);
				return $this->fail('not_found', 'Not a draft');
			}
			// Taking a draft back replaces it, then removes it: the level « send » on its mailbox (audit INBX-005)
			if (!InboxAccess::atLeast($reader->level, InboxAccess::LEVEL_SEND)) {
				dol_delete_dir_recursive($dir);
				return $this->fail('no_send');
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
			$out['new_thread'] = $h('X-Inbox-New-Thread') === '1' ? 1 : 0;
			$identityId = (int) $h('X-Inbox-Identity');
			$parts = self::splitDraft($message->bodyHtml !== '' ? self::inlinePictures($message) : nl2br(dol_escape_htmltag($message->bodyText)));
			$out['html'] = $parts['body'];
			$out['signature_off'] = $parts['signature'] === '' ? 1 : 0;
			if ($parts['quote'] !== '') {
				self::putFile($dir, 'quote.html', $parts['quote']);
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
			$state['source_refs'] = InboxMailBuilder::messageIds($h('References'));
			$state['in_reply_to'] = (string) (InboxMailBuilder::messageIds($h('In-Reply-To'))[0] ?? '');
			foreach ($message->getFileAttachments() as $a) {
				$this->keepFile($dir, $state, $a['name'], $a['content'], $a['mime']);
			}
		} elseif ($mode === 'unsubscribe') {
			// Unsubscribing by mail (RFC 2369 mailto): the address, subject and text the list asks for, written from the
			// address that got the mail; the same rules as the one-click (level manage, header signed)
			if ($reader->level !== InboxAccess::LEVEL_MANAGE) {
				dol_delete_dir_recursive($dir);
				return $this->fail('no_manage');
			}
			$headers = InboxReader::headers($raw);
			$info = InboxReader::unsubscribeInfo($headers);
			if (!$info || $info['mailto'] === '' || InboxReader::unsubscribeMode() === 'off') {
				dol_delete_dir_recursive($dir);
				return $this->fail('unsub_none');
			}
			$auth = InboxReader::authResults($headers);
			if ($auth['dkim'] !== 'pass') {
				dol_delete_dir_recursive($dir);
				return $this->fail('unsub_unsigned');
			}
			// The address asked must belong to who signed the mail or to its sender: never a text of the sender sent
			// to a third party from the address of the company (audit INBX-081)
			require_once __DIR__.'/inboxtrusteddomain.class.php';
			$to = InboxTrustedDomain::registrable(InboxTrustedDomain::normalize($info['mailto']));
			$own = array_map(function ($d) {
				return InboxTrustedDomain::registrable(InboxTrustedDomain::normalize($d));
			}, array_merge($auth['dkim_domains'], array((string) $reader->row->from_email)));
			if ($to === '' || !in_array($to, $own, true)) {
				dol_delete_dir_recursive($dir);
				return $this->fail('unsub_foreign');
			}
			$out['to'] = array(array('email' => $info['mailto'], 'name' => ''));
			$out['subject'] = $info['mailto_subject'] !== '' ? $info['mailto_subject'] : 'unsubscribe';
			$out['html'] = '<p>'.nl2br(dol_escape_htmltag($info['mailto_body'] !== '' ? $info['mailto_body'] : 'unsubscribe')).'</p>';
			$out['signature_off'] = 1;
			$state['unsubscribe'] = array('mail' => (int) $reader->row->rowid, 'account' => $sourceAccount, 'folder' => (string) $reader->row->folder_path,
				'message_id' => (string) $reader->row->message_id, 'subject' => (string) $reader->row->subject, 'domain' => (string) substr(strrchr($info['mailto'], '@'), 1));
			foreach ($identities as $i) {
				if ($i['account'] === $sourceAccount && in_array(strtolower($i['email']), array_map(function ($a) {
					return strtolower($a['email']);
				}, array_merge($message->to, $message->cc)), true)) {
					$identityId = $i['id'];
					break;
				}
			}
		} elseif ($mode === 'editnew') {
			// « Edit as new » (B19): the recipients, subject, text and files of the mail, in a new writing
			$headers = InboxReader::headers($raw);
			$out['to'] = $message->to;
			$out['cc'] = $message->cc;
			foreach ($headers as $pair) {
				if (strcasecmp($pair[0], 'Bcc') === 0) {
					$out['bcc'] = InboxMailBuilder::parseAddresses((string) $pair[1]);
				}
			}
			$out['subject'] = (string) $message->subject;
			// Its tracking pictures never go again, its remote pictures as for a quote (audit INBX-079)
			$removed = array();
			$parts = self::splitDraft($message->bodyHtml !== '' ? self::quoteRemote(self::inlinePictures($message), 'reply', $removed) : nl2br(dol_escape_htmltag($message->bodyText)));
			$out['html'] = $parts['body'];
			foreach ($message->getFileAttachments() as $a) {
				$this->keepFile($dir, $state, $a['name'], $a['content'], $a['mime']);
			}
			// The identity that wrote it, when it is one of the user
			$fromEmail = strtolower((string) ($message->from['email'] ?? ''));
			foreach ($identities as $i) {
				if ($i['account'] === $sourceAccount && strtolower($i['email']) === $fromEmail) {
					$identityId = $i['id'];
					break;
				}
			}
		} elseif ($mode !== 'new') {
			$row = $reader->row;
			$state['source'] = array('mail' => (int) $row->rowid, 'account' => (int) $row->fk_account, 'folder' => (string) $row->folder_path,
				'remote_id' => (string) $row->remote_id, 'message_id' => (string) $message->messageId, 'mode' => $mode);
			$headers = InboxReader::headers($raw);
			$state['source_refs'] = array();
			foreach ($headers as $pair) {
				if (strcasecmp($pair[0], 'References') === 0) {
					$state['source_refs'] = InboxMailBuilder::messageIds((string) $pair[1]);
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
				$removed = array('trackers' => 0, 'images' => 0);
				self::putFile($dir, 'quote.html', $this->quoteOf($mode, $message, $removed));
				$state['quote'] = 1;
				$state['quote_removed'] = $removed;
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
		// Pieces linked to the mail answered: the sending may be recorded on them (lot W6 1.17.0); their templates and
		// their documents are offered (1.18.0)
		$state['linked'] = $this->linkedOf($user, $state['source'] ?? null);
		$state['pieces'] = $this->piecesOf($user, $state['linked']);
		self::writeState($dir, $state);
		$this->hold($token);
		$langs->loadLangs(array('inbox@inbox'));

		return $this->view($user, $token, $dir, $state, $out, $identityId, $identities);
	}

	/**
	 * Data of a writing for the browser (open, or back from the queue)
	 *
	 * @param User                $user       User
	 * @param string              $token      Writing
	 * @param string              $dir        Its folder
	 * @param array<string,mixed> $state      State
	 * @param array<string,mixed> $out        Fields (to, cc, bcc, subject, html, receipt...)
	 * @param int                 $identityId Identity chosen
	 * @param array<int,array<string,mixed>> $identities Identities of the user
	 * @return array<string,mixed>
	 */
	protected function view(User $user, $token, $dir, array $state, array $out, $identityId, array $identities)
	{
		$out['token'] = $token;
		$out['mode'] = (string) $state['mode'];
		$out['identity'] = $identityId;
		// The signatures shown as they will be sent: their variables replaced (identity, user, company, modules)
		require_once __DIR__.'/inboxtemplates.class.php';
		$signed = (new InboxTemplates($this->db))->signatures($user, $identities);
		foreach ($identities as $n => $i) {
			$identities[$n]['signature'] = InboxMailBuilder::cleanHtml($signed[(int) $i['id']] ?? (string) $i['signature']);
		}
		$out['identities'] = $identities;
		$out['quote'] = !empty($state['quote']) ? (string) self::getFile($dir, 'quote.html') : '';
		// Remote pictures taken out of the quote (lot F1 1.78.0), said under it
		$out['quote_removed'] = $state['quote_removed'] ?? array('trackers' => 0, 'images' => 0);
		$out['attachments'] = self::attachmentsView($state);
		$out['source'] = null;
		if (!empty($state['source'])) {
			$box = $this->db->getRow("SELECT email FROM ".$this->db->prefix()."inbox_account WHERE rowid = ".((int) ($state['source']['account'] ?? 0)));
			$out['source'] = array('mail' => (int) ($state['source']['mail'] ?? 0), 'mode' => (string) $state['source']['mode'], 'account' => (int) ($state['source']['account'] ?? 0),
				'mailbox' => is_object($box) ? (string) $box->email : '');
		}
		$out['draft'] = !empty($state['draft']) ? 1 : 0;
		$out['max_size'] = self::maxBytes();
		$out['pieces'] = array_values((array) ($state['pieces'] ?? array()));
		$out['linked'] = array_values((array) ($state['linked'] ?? array()));
		$out['send_delay'] = self::delayFor($user);

		return $out;
	}

	/**
	 * Delay before a mail leaves (seconds): the one of the user (My account), else the one of the setup
	 *
	 * @param User $user User
	 * @return int
	 */
	public static function delayFor(User $user)
	{
		global $db;

		require_once __DIR__.'/inboxuserparam.class.php';
		$params = new InboxUserParam($db);
		$mine = (int) $params->get($user, 'send_delay');

		return $mine >= 0 ? min(120, $mine) : max(0, min(120, getDolGlobalInt('INBOX_SEND_DELAY', 10)));
	}

	/**
	 * Pieces linked to a mail (through its Message-ID) that the user may read, the newest first
	 *
	 * @param User                     $user   User
	 * @param array<string,mixed>|null $source Mail answered
	 * @return array<int,array{type:string,id:int,ref:string,kind:string,icon:string}>
	 */
	public function linkedOf(User $user, $source)
	{
		if (!$source || (string) ($source['message_id'] ?? '') === '') {
			return array();
		}
		require_once __DIR__.'/inboxlink.class.php';
		require_once __DIR__.'/inboxelements.class.php';
		$p = $this->db->prefix();
		$ref = $this->db->getRow("SELECT rowid FROM ".$p."inbox_mailref WHERE message_id = '".$this->db->escape((string) $source['message_id'])."'");
		if (!is_object($ref)) {
			return array();
		}
		$links = new InboxLink($this->db);
		$elements = new InboxElements($this->db);
		$out = array();
		foreach (array_reverse($links->listFor(array((int) $ref->rowid))) as $l) {
			$type = (string) $l['type'];
			if (!InboxElements::enabled($type) || $type === 'action') {
				continue;
			}
			$d = $elements->describe($user, $type, (int) $l['element']);
			if ($d && !empty($d['readable'])) {
				$out[] = array('type' => $type, 'id' => (int) $l['element'], 'ref' => (string) ($d['ref'] ?? ''), 'kind' => (string) $d['kind'], 'icon' => (string) $d['icon']);
			}
			if (count($out) >= 20) {
				break;
			}
		}

		return $out;
	}

	/** Automatic event of the agenda of Dolibarr « sent by mail » per type of piece */
	const SENT_BY_MAIL = array('propal' => 'PROPAL_SENTBYMAIL', 'commande' => 'ORDER_SENTBYMAIL', 'facture' => 'BILL_SENTBYMAIL', 'supplier_proposal' => 'PROPOSAL_SUPPLIER_SENTBYMAIL',
		'order_supplier' => 'ORDER_SUPPLIER_SENTBYMAIL', 'invoice_supplier' => 'BILL_SUPPLIER_SENTBYMAIL', 'fichinter' => 'FICHINTER_SENTBYMAIL', 'shipping' => 'SHIPPING_SENTBYMAIL',
		'project' => 'PROJECT_SENTBYMAIL', 'ticket' => 'TICKET_SENTBYMAIL', 'societe' => 'COMPANY_SENTBYMAIL', 'contact' => 'CONTACT_SENTBYMAIL');

	/**
	 * Linked pieces the user may also write, and record a sending on (an event of the agenda)
	 *
	 * @param User                           $user   User
	 * @param array<int,array<string,mixed>> $linked Readable linked pieces (linkedOf())
	 * @return array<int,array{type:string,id:int,ref:string,kind:string,icon:string}>
	 */
	public function piecesOf(User $user, array $linked)
	{
		if (!$user->hasRight('agenda', 'myactions', 'create')) {
			return array();
		}

		$out = array_values(array_filter($linked, function ($pc) use ($user) {
			return InboxElements::canWriteType($user, (string) $pc['type']);
		}));
		// Ticked at first as Dolibarr does when a piece is sent from its card: the automatic event « sent by mail » of
		// its type in Agenda > Automatic events (1.83.2)
		foreach ($out as $i => $pc) {
			$code = self::SENT_BY_MAIL[(string) $pc['type']] ?? '';
			$out[$i]['auto'] = $code !== '' && getDolGlobalString('MAIN_AGENDA_ACTIONAUTO_'.$code) ? 1 : 0;
		}

		return $out;
	}

	/**
	 * Identity chosen in a writing (one of the user)
	 *
	 * @param User $user       User
	 * @param int  $identityId Identity
	 * @return array<string,mixed>|null
	 */
	public function identityOf(User $user, $identityId)
	{
		foreach ($this->identities($user) as $i) {
			if ($i['id'] === (int) $identityId) {
				return $i;
			}
		}

		return null;
	}

	/**
	 * Context of the variables of a template in a writing
	 *
	 * @param User                $user       User
	 * @param array<string,mixed> $state      State of the writing
	 * @param array<string,mixed> $identity   Identity chosen
	 * @param string              $piece      Piece « type:id » whose variables are used ('' none)
	 * @param string              $to         First recipient
	 * @return array<string,mixed>|null Null when the piece is not one of the linked pieces
	 */
	protected function varContext(User $user, array $state, array $identity, $piece, $to)
	{
		$pc = null;
		if ((string) $piece !== '') {
			foreach ((array) ($state['linked'] ?? array()) as $l) {
				if ($l['type'].':'.$l['id'] === (string) $piece) {
					$pc = array('type' => (string) $l['type'], 'id' => (int) $l['id']);
				}
			}
			if (!$pc) {
				return null;
			}
		}

		return array('piece' => $pc, 'to' => (string) $to, 'identity' => $identity, 'mailbox' => (string) $identity['email'], 'source' => $state['source'] ?? null);
	}

	/**
	 * Templates offered in a writing, for the mailbox of the identity chosen
	 *
	 * @param User   $user       User
	 * @param string $token      Writing
	 * @param int    $identityId Identity chosen
	 * @return array<int,array<string,mixed>>|null
	 */
	public function templates(User $user, $token, $identityId)
	{
		$dir = $this->held($user, $token);
		$identity = $this->identityOf($user, $identityId);
		if ($dir === null || !$identity) {
			return $this->fail('not_found');
		}
		$state = self::readState($dir);
		require_once __DIR__.'/inboxtemplates.class.php';
		$templates = new InboxTemplates($this->db);

		return $templates->offered($user, (int) $identity['account'], (array) ($state['linked'] ?? array()));
	}

	/**
	 * A template put in a writing: its subject, its body with the variables of the piece chosen and of the first
	 * recipient; the main document of the piece attached when the template asks it
	 *
	 * @param User   $user       User
	 * @param string $token      Writing
	 * @param int    $id         Template
	 * @param int    $identityId Identity chosen
	 * @param string $piece      Piece « type:id » ('' none)
	 * @param string $to         First recipient
	 * @return array<string,mixed>|null
	 */
	public function applyTemplate(User $user, $token, $id, $identityId, $piece, $to)
	{
		$dir = $this->held($user, $token);
		$identity = $this->identityOf($user, $identityId);
		if ($dir === null || !$identity) {
			return $this->fail('not_found');
		}
		$state = self::readState($dir);
		$ctx = $this->varContext($user, $state, $identity, $piece, $to);
		if ($ctx === null) {
			return $this->fail('not_found', 'Piece not linked');
		}
		require_once __DIR__.'/inboxtemplates.class.php';
		$templates = new InboxTemplates($this->db);
		$t = $templates->usable($user, (int) $id, (int) $identity['account'], $ctx['piece'] ? $ctx['piece']['type'] : '');
		if (!$t) {
			return $this->fail('not_found', 'Template not usable');
		}
		$ctx['lang'] = (string) $t->lang;
		$values = $templates->values($user, $ctx);
		$out = $templates->render($t, $values);
		$out['values'] = $values;
		$out['piece'] = (string) $piece;
		$out['lang'] = (string) $t->lang;
		$out['attached'] = null;
		// « Attach the main document » of the template: the PDF of the piece, as its card would send it
		if ($out['joinfiles'] && $ctx['piece']) {
			$doc = $this->mainDocument($user, $ctx['piece']['type'], $ctx['piece']['id']);
			if ($doc !== '' && !in_array($doc, array_column((array) $state['attachments'], 'name'), true)) {
				$out['attached'] = $this->attachDocument($user, $token, $ctx['piece']['type'].':'.$ctx['piece']['id'], $doc);
			}
		}

		return $out;
	}

	/**
	 * Default template of the writing (1.118.0): the one of its kind (new mail, answer) for the user in the mailbox of
	 * the identity, filled as applyTemplate() does; nothing for the other kinds (forward, draft...)
	 *
	 * @param User   $user       User
	 * @param string $token      Writing
	 * @param int    $identityId Identity chosen
	 * @param string $to         First recipient
	 * @return array<string,mixed>|null Template filled, or array('none' => 1, 'level' => ...)
	 */
	public function defaultTemplate(User $user, $token, $identityId, $to)
	{
		$dir = $this->held($user, $token);
		$identity = $this->identityOf($user, $identityId);
		if ($dir === null || !$identity) {
			return $this->fail('not_found');
		}
		$state = self::readState($dir);
		require_once __DIR__.'/inboxtemplatedefaults.class.php';
		$usage = InboxTemplateDefaults::usageOfMode((string) ($state['mode'] ?? ''));
		$found = $usage !== '' ? (new InboxTemplateDefaults($this->db))->resolve($user, (int) $identity['account'], $usage) : array('id' => 0, 'level' => '');
		if ($found['id'] <= 0) {
			return array('none' => 1, 'level' => $found['level'], 'usage' => $usage);
		}
		// The variables of the newest piece linked to the mail answered, as a template chosen by hand
		$linked = array_values((array) ($state['linked'] ?? array()));
		$piece = $linked ? ((string) $linked[0]['type']).':'.((int) $linked[0]['id']) : '';
		$out = $this->applyTemplate($user, $token, $found['id'], $identityId, $piece, $to);
		if ($out === null) {
			return null;
		}
		$out['level'] = $found['level'];
		$out['usage'] = $usage;

		return $out;
	}

	/**
	 * Values of the variables again (the recipient or the piece changed)
	 *
	 * @param User   $user       User
	 * @param string $token      Writing
	 * @param int    $identityId Identity chosen
	 * @param string $piece      Piece « type:id » ('' none)
	 * @param string $to         First recipient
	 * @param string $lang       Language of the template
	 * @return array<string,string>|null
	 */
	public function templateValues(User $user, $token, $identityId, $piece, $to, $lang = '')
	{
		$dir = $this->held($user, $token);
		$identity = $this->identityOf($user, $identityId);
		if ($dir === null || !$identity) {
			return $this->fail('not_found');
		}
		$ctx = $this->varContext($user, self::readState($dir), $identity, $piece, $to);
		if ($ctx === null) {
			return $this->fail('not_found', 'Piece not linked');
		}
		$ctx['lang'] = preg_match('/^[a-z]{2}_[A-Z]{2}$/', (string) $lang) ? (string) $lang : '';
		require_once __DIR__.'/inboxtemplates.class.php';
		$templates = new InboxTemplates($this->db);

		return $templates->values($user, $ctx);
	}

	/**
	 * Folder of the documents of a linked piece the user may read
	 *
	 * @param User   $user  User
	 * @param string $type  Type
	 * @param int    $id    Piece
	 * @return array{0:string,1:object}|null
	 */
	protected function documentsDirOf(User $user, $type, $id)
	{
		require_once __DIR__.'/inboxcreate.class.php';
		if (!InboxElements::canRead($user, (string) $type, (int) $id)) {
			return null;
		}
		$object = fetchObjectByElement((int) $id, (string) $type);
		if (!is_object($object) || empty($object->id)) {
			return null;
		}
		$create = new InboxCreate($this->db);
		$dir = $create->documentsDir((string) $type, $object);

		return $dir !== '' ? array($dir, $object) : null;
	}

	/**
	 * Main document of a piece: the PDF named after its reference, when it was generated
	 *
	 * @param User   $user User
	 * @param string $type Type
	 * @param int    $id   Piece
	 * @return string File name, '' when none
	 */
	protected function mainDocument(User $user, $type, $id)
	{
		$found = $this->documentsDirOf($user, $type, $id);
		if (!$found) {
			return '';
		}
		$name = dol_sanitizeFileName((string) ($found[1]->ref ?? '')).'.pdf';

		return $name !== '.pdf' && is_file($found[0].'/'.$name) ? $name : '';
	}

	/**
	 * Documents of the pieces linked to the mail answered, to attach them (B: attachments from Dolibarr)
	 *
	 * @param User   $user  User
	 * @param string $token Writing
	 * @return array<int,array<string,mixed>>|null Pieces: type, id, ref, kind, icon, files (name, size, date)
	 */
	public function documents(User $user, $token)
	{
		$dir = $this->held($user, $token);
		if ($dir === null) {
			return $this->fail('not_found');
		}
		$state = self::readState($dir);
		require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
		$out = array();
		foreach ((array) ($state['linked'] ?? array()) as $pc) {
			$found = $this->documentsDirOf($user, (string) $pc['type'], (int) $pc['id']);
			if (!$found || !is_dir($found[0])) {
				continue;
			}
			$files = array();
			foreach (dol_dir_list($found[0], 'files', 0, '', array('\.meta$', '\.png$', '^\.'), 'date', SORT_DESC) as $f) {
				$files[] = array('name' => (string) $f['name'], 'size' => (int) $f['size'], 'date' => (int) $f['date']);
				if (count($files) >= self::MAX_DOCUMENTS) {
					break;
				}
			}
			if ($files) {
				$out[] = $pc + array('files' => $files, 'main' => dol_sanitizeFileName((string) ($found[1]->ref ?? '')).'.pdf');
			}
		}

		return $out;
	}

	/**
	 * A document of a linked piece attached to the writing
	 *
	 * @param User   $user  User
	 * @param string $token Writing
	 * @param string $piece Piece « type:id », one of the linked pieces
	 * @param string $name  File name in its documents
	 * @return array{id:string,name:string,size:int,mime:string,scan:string}|null
	 */
	public function attachDocument(User $user, $token, $piece, $name)
	{
		$dir = $this->held($user, $token);
		if ($dir === null) {
			return $this->fail('not_found');
		}
		$state = self::readState($dir);
		$pc = null;
		foreach ((array) ($state['linked'] ?? array()) as $l) {
			if ($l['type'].':'.$l['id'] === (string) $piece) {
				$pc = $l;
			}
		}
		$name = basename((string) $name);
		$found = $pc ? $this->documentsDirOf($user, (string) $pc['type'], (int) $pc['id']) : null;
		if (!$found || $name === '' || $name[0] === '.' || !is_file($found[0].'/'.$name)) {
			return $this->fail('not_found');
		}
		$path = $found[0].'/'.$name;
		$size = (int) filesize($path);
		$total = array_sum(array_column((array) $state['attachments'], 'size'));
		if ($total + $size > self::maxBytes()) {
			return $this->fail('too_big');
		}
		require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
		$att = $this->keepFile($dir, $state, $name, (string) file_get_contents($path), dol_mimetype($name));
		if ($att['scan'] === InboxAttachmentScan::INFECTED) {
			@unlink($dir.'/att-'.$att['id']);
			return $this->fail('infected', $name);
		}
		self::writeState($dir, $state);

		return $att;
	}

	/**
	 * Redirect a mail (B19): the very mail sent again to other recipients, its sender and its text unchanged, with
	 * Resent-* headers; sent at once by the server of its mailbox, never copied in Sent
	 *
	 * @param User     $user   User
	 * @param int      $mailId Mail
	 * @param string[] $to     Recipients
	 * @return array{sent:int,recipients:int}|null
	 */
	public function redirect(User $user, $mailId, $to)
	{
		$this->error = '';
		$this->errorCode = '';
		$this->bad = array();
		$reader = new InboxReader($this->db);
		if ((int) $mailId <= 0 || !$reader->load($user, (int) $mailId)) {
			return $this->fail('not_found');
		}
		$account = $reader->account;
		$access = new InboxAccess($this->db);
		if (!InboxAccess::atLeast($access->getLevel($user, (int) $account->id), InboxAccess::LEVEL_SEND)) {
			return $this->fail('no_send');
		}
		$bad = array();
		$list = InboxMailBuilder::parseAddresses($to, $bad);
		if ($bad) {
			$this->bad = $bad;
			return $this->fail('bad_address', implode(', ', $bad));
		}
		if (!$list) {
			return $this->fail('no_recipient');
		}
		if (count($list) > 50) {
			return $this->fail('error', 'Too many recipients');
		}
		if (getDolGlobalString('MAIN_DISABLE_ALL_MAILS')) {
			return $this->fail('mails_disabled');
		}
		$raw = $reader->raw($user);
		if ($raw === null) {
			return $this->fail('not_found');
		}
		// Sender of the redirection: the identity of the user in this mailbox (default first), else the mailbox
		$from = array('email' => (string) $account->email, 'name' => (string) $account->label);
		foreach ($this->identities($user) as $i) {
			if ($i['account'] === (int) $account->id && ($from['email'] === (string) $account->email || $i['default'])) {
				$from = array('email' => (string) $i['email'], 'name' => (string) $i['name']);
			}
		}
		$fold = function ($name, $value) {
			return $name.': '.preg_replace('/[\r\n]+/', ' ', $value)."\r\n";
		};
		$addr = function ($a) {
			return $a['name'] !== '' ? '"'.addcslashes(InboxMimeParser::clean($a['name']), '"\\').'" <'.$a['email'].'>' : $a['email'];
		};
		$head = $fold('Resent-From', $addr($from))
			.$fold('Resent-To', implode(', ', array_map($addr, $list)))
			.$fold('Resent-Date', date('r', dol_now()))
			.$fold('Resent-Message-ID', '<'.InboxMailBuilder::newMessageId($from['email']).'>');
		// The header of a mail ends at the first empty line; Bcc never goes out
		$split = preg_split('/(\r?\n\r?\n)/', (string) $raw, 2, PREG_SPLIT_DELIM_CAPTURE);
		$raw = preg_replace('/^Bcc:.*(\r?\n[ \t].*)*(\r?\n|$)/mi', '', $split[0]).($split[1] ?? '').($split[2] ?? '');
		$error = '';
		$connector = InboxConnectorFactory::forAccount($this->db, $account, $error);
		if (!$connector) {
			return $this->fail('error', $error);
		}
		if (!$connector->can(InboxConnectorInterface::CAP_SEND)) {
			$connector->disconnect();
			return $this->fail('smtp_no_server');
		}
		$ok = $connector->send($head.$raw, array('from' => $from['email'], 'recipients' => array_column($list, 'email')));
		if (!$ok) {
			$code = method_exists($connector, 'getErrorCode') ? (string) $connector->getErrorCode() : '';
			$this->error = $connector->getError();
			$this->errorCode = $code !== '' ? $code : 'smtp_refused';
			$connector->disconnect();
			return null;
		}
		$connector->disconnect();
		InboxActionLog::add($this->db, $user, (int) $account->id, 'redirect', array((object) array('message_id' => (string) $reader->row->message_id, 'subject' => (string) $reader->row->subject)),
			(string) $reader->row->folder_path, '', 0, implode(', ', array_column($list, 'email')));

		return array('sent' => 1, 'recipients' => count($list));
	}

	/**
	 * A writing back from the queue (sending cancelled, or failed): what was typed, in its window again
	 *
	 * @param User                $user  User
	 * @param string              $token Writing
	 * @param array<string,mixed> $typed What was typed
	 * @return array<string,mixed>|null
	 */
	public function restore(User $user, $token, array $typed)
	{
		$dir = self::dirOf($user, $token);
		if ($dir === null) {
			return $this->fail('not_found');
		}
		$state = self::readState($dir);
		$this->hold($token);
		$list = function ($v) {
			return InboxMailBuilder::parseAddresses($v);
		};
		$out = array('to' => $list($typed['to'] ?? array()), 'cc' => $list($typed['cc'] ?? array()), 'bcc' => $list($typed['bcc'] ?? array()),
			'subject' => (string) ($typed['subject'] ?? ''), 'html' => InboxMailBuilder::cleanHtml((string) ($typed['html'] ?? ''), true), 'receipt' => (int) !empty($typed['receipt']),
			'signature_off' => empty($typed['signature']) ? 1 : 0, 'quote_off' => empty($typed['quote']) ? 1 : 0);

		return $this->view($user, $token, $dir, $state, $out, (int) ($typed['identity'] ?? 0), $this->identities($user));
	}

	/**
	 * Token kept in the session of the user (a writing opened in another session is refused)
	 *
	 * @param string $token Token
	 * @return void
	 */
	public function hold($token)
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
	public function held(User $user, $token)
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
			." WHERE m.message_id = '".$this->db->escape($messageId)."' AND m.fk_account IN (".implode(',', $readable).") AND COALESCE(f.role, '') <> 'drafts'"
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
		// Mail sent by a site for a requester (data sources, 1.72.0): the answer goes to the requester, never to the site
		require_once __DIR__.'/inboxdatasource.class.php';
		// A signed source: the reception of this mail in the index, by its Message-ID (1.143.0)
		$found = $mine ? null : (new InboxDataSource($this->db))->detect($headers, $from['email'], null, array('message_id' => (string) $message->messageId));
		if (!empty($found['ok'])) {
			$replyTo = array(array('email' => (string) $found['email'], 'name' => (string) $found['name']));
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
	 * @param string                           $mode    Mode
	 * @param InboxMessage                     $message Mail
	 * @param array{trackers:int,images:int}   $removed Remote pictures taken out
	 * @return string Clean HTML, in a div.inbox-quote
	 */
	protected function quoteOf($mode, $message, &$removed = null)
	{
		global $langs;

		$removed = array('trackers' => 0, 'images' => 0);
		if ($message->bodyHtml !== '') {
			$body = $message->bodyHtml;
			// Pictures of the mail (cid:) are not sent again in an answer: their text stays
			$body = preg_replace('#<img\b[^>]*src=["\']?cid:[^>]*>#i', '', $body);
			$body = self::quoteRemote($body, $mode, $removed);
			// The one door (lot S2): the quote leaves the reading frame for the editor, HTML Purifier first, then the
			// filter of the sending (marks of inbox, borders of the tables)
			$body = InboxMailBuilder::cleanHtml(InboxSafe::html($body));
		} else {
			// htmlspecialchars: dol_escape_htmltag would remove a « <b> » written in the text instead of showing it
			$body = '<div style="white-space:pre-wrap">'.htmlspecialchars(InboxMimeParser::clean($message->bodyText, true), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</div>';
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
	 * Remote pictures of a quote (lot F1 1.78.0). The tracking pictures always go: kept, the reader of the answer
	 * would tell the sender of the first mail that it was answered, and when; the copy in the Sent folder would tell it
	 * again to whoever shows its pictures. The other pictures follow the setting of the administrator
	 * INBOX_QUOTE_REMOTE_IMAGES: keep (default), reply (taken out of the answers), all (answers and forwards); a
	 * picture taken out leaves its alternative text.
	 *
	 * @param string                         $html    HTML of the mail quoted
	 * @param string                         $mode    Mode of the writing
	 * @param array{trackers:int,images:int} $removed Counts, added to
	 * @return string
	 */
	public static function quoteRemote($html, $mode, array &$removed)
	{
		$setting = getDolGlobalString('INBOX_QUOTE_REMOTE_IMAGES', 'keep');
		$forward = in_array($mode, array('forward', 'forward_attach'), true);
		$strip = $setting === 'all' || ($setting === 'reply' && !$forward);
		$removed += array('trackers' => 0, 'images' => 0);
		$html = (string) preg_replace_callback('/<img\b[^>]*>/i', function ($m) use ($strip, &$removed) {
			$tag = $m[0];
			if (InboxReader::isTracker($tag)) {
				$removed['trackers']++;
				return '';
			}
			if (!$strip || !preg_match('/\bsrc\s*=\s*["\']?\s*https?:/i', $tag)) {
				return $tag;
			}
			$removed['images']++;
			$alt = '';
			if (preg_match('/\balt\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $tag, $a)) {
				$alt = trim(html_entity_decode(implode('', array_slice($a, 1)), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
			}

			return $alt !== '' ? '['.dol_escape_htmltag(dol_trunc($alt, 80, 'right', 'UTF-8', 1)).']' : '';
		}, (string) $html);
		if ($strip) {
			// Backgrounds of cells and blocks: an address the reader would load as well
			$html = (string) preg_replace('/url\s*\(\s*[\'"]?\s*https?:[^)]*\)/i', 'none', $html);
			$html = (string) preg_replace('/\sbackground\s*=\s*("https?:[^"]*"|\'https?:[^\']*\'|https?:[^\s>]+)/i', '', $html);
		}

		return $html;
	}

	/**
	 * HTML of a mail taken back in the editor (draft, edit as new): its pictures (cid:, parts of the mail) put back
	 * as data:, as when they were pasted; the browser cannot show a cid: and they would be lost at the next save
	 *
	 * @param InboxMessage $message Mail
	 * @return string HTML
	 */
	public static function inlinePictures($message)
	{
		$pictures = array();
		foreach ($message->attachments as $a) {
			if ((string) $a['cid'] !== '' && preg_match('#^image/(png|jpeg|gif|webp)$#i', (string) $a['mime']) && strlen((string) $a['content']) <= 5 * 1048576) {
				$pictures[strtolower(trim((string) $a['cid'], '<>'))] = 'data:'.strtolower((string) $a['mime']).';base64,'.base64_encode((string) $a['content']);
			}
		}
		if (!$pictures) {
			return (string) $message->bodyHtml;
		}

		return (string) preg_replace_callback('#(\bsrc\s*=\s*)(["\']?)cid:([^"\'\s>]+)\2#i', function ($m) use ($pictures) {
			$cid = strtolower(trim(rawurldecode($m[3]), '<>'));
			return isset($pictures[$cid]) ? $m[1].'"'.$pictures[$cid].'"' : $m[0];
		}, (string) $message->bodyHtml);
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
		$clean = InboxMailBuilder::cleanHtml($html, true);
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
		self::putFile($dir, 'att-'.$id, (string) $content);
		$scan = new InboxAttachmentScan($this->db);
		$res = $scan->check((string) $content, (string) $name);
		$att = array('id' => $id, 'name' => InboxMimeParser::fileName((string) $name) ?: 'file', 'size' => strlen((string) $content),
			'mime' => preg_match('#^[a-z0-9.+\-]+/[a-z0-9.+\-]+$#i', (string) $mime) ? strtolower((string) $mime) : 'application/octet-stream', 'scan' => (string) $res['state']);
		$state['attachments'][] = $att;

		return $att;
	}

	/**
	 * Pieces to insert in a writing (lot A5, 1.83.0): of the thirdparties of the recipients and of the pieces linked to
	 * the mail answered, or of all the thirdparties
	 *
	 * @param User     $user   User
	 * @param string   $token  Writing
	 * @param string   $term   Reference or thirdparty searched
	 * @param bool     $all    All the thirdparties
	 * @param string[] $emails Recipients typed
	 * @return array{pieces:array<int,array<string,mixed>>,scoped:bool}|null
	 */
	public function pieceSearch(User $user, $token, $term, $all, array $emails)
	{
		$dir = $this->held($user, $token);
		if ($dir === null) {
			return $this->fail('not_found');
		}
		require_once __DIR__.'/inboxpieceinsert.class.php';
		$state = self::readState($dir);
		$ins = new InboxPieceInsert($this->db);
		$socs = $ins->socsOf($emails);
		$elements = new InboxElements($this->db);
		foreach ((array) ($state['linked'] ?? array()) as $pc) {
			$soc = (int) $elements->socOf((string) $pc['type'], (int) $pc['id']);
			if ($soc > 0) {
				$socs[] = $soc;
			}
		}
		$socs = array_values(array_unique($socs));

		return array('pieces' => $ins->search($user, $socs, $term, (bool) $all || !$socs), 'scoped' => !$all && (bool) $socs);
	}

	/**
	 * What a piece may give to the writing: its PDF, its online links
	 *
	 * @param User   $user  User
	 * @param string $token Writing
	 * @param string $type  Type
	 * @param int    $id    Piece
	 * @return array<string,mixed>|null
	 */
	public function pieceOffer(User $user, $token, $type, $id)
	{
		if ($this->held($user, $token) === null) {
			return $this->fail('not_found');
		}
		require_once __DIR__.'/inboxpieceinsert.class.php';
		$ins = new InboxPieceInsert($this->db);
		$o = $ins->load($user, (string) $type, (int) $id);
		if (!$o) {
			return $this->fail('not_found');
		}
		$pdf = $this->mainDocument($user, (string) $type, (int) $id);
		$out = $ins->options($o, (string) $type, $pdf);
		if ($pdf !== '') {
			// Seen in Dolibarr before it is attached: its own page of documents checks the rights
			$part = array('propal' => 'propal', 'commande' => 'commande', 'facture' => 'facture')[$type];
			$out['pdf_url'] = DOL_URL_ROOT.'/document.php?modulepart='.$part.'&file='.urlencode(dol_sanitizeFileName((string) $o->ref).'/'.$pdf);
			$found = $this->documentsDirOf($user, (string) $type, (int) $id);
			$out['pdf_size'] = $found ? (int) @filesize($found[0].'/'.$pdf) : 0;
		}
		$out['ref'] = (string) $o->ref;

		return $out;
	}

	/**
	 * A piece inserted: the HTML for the text, its PDF attached when asked, the piece offered for « record the
	 * sending on » (linked to the mail sent)
	 *
	 * @param User                $user  User
	 * @param string              $token Writing
	 * @param string              $type  Type
	 * @param int                 $id    Piece
	 * @param array<string,bool>  $ask   summary, lines, pdf, sign, pay
	 * @return array{html:string,attachment:array<string,mixed>|null,piece:array<string,mixed>|null}|null
	 */
	public function pieceInsert(User $user, $token, $type, $id, array $ask)
	{
		$dir = $this->held($user, $token);
		if ($dir === null) {
			return $this->fail('not_found');
		}
		require_once __DIR__.'/inboxpieceinsert.class.php';
		$ins = new InboxPieceInsert($this->db);
		$o = $ins->load($user, (string) $type, (int) $id);
		if (!$o) {
			return $this->fail('not_found');
		}
		$pdf = $this->mainDocument($user, (string) $type, (int) $id);
		$can = $ins->options($o, (string) $type, $pdf);
		$wantPdf = !empty($ask['pdf']);
		$ask = array('summary' => !empty($ask['summary']), 'lines' => !empty($ask['lines']), 'sign' => !empty($ask['sign']) && $can['sign'], 'pay' => !empty($ask['pay']) && $can['pay']);
		$html = $ins->html($o, (string) $type, $ask);
		$state = self::readState($dir);
		$att = null;
		if ($wantPdf && $pdf !== '') {
			$found = $this->documentsDirOf($user, (string) $type, (int) $id);
			$size = (int) @filesize($found[0].'/'.$pdf);
			if (array_sum(array_column((array) $state['attachments'], 'size')) + $size > self::maxBytes()) {
				return $this->fail('too_big');
			}
			$att = $this->keepFile($dir, $state, $pdf, (string) file_get_contents($found[0].'/'.$pdf), 'application/pdf');
			if ($att['scan'] === InboxAttachmentScan::INFECTED) {
				@unlink($dir.'/att-'.$att['id']);
				return $this->fail('infected', $pdf);
			}
		}
		// The piece joins the pieces of the writing: offered to record the sending on it (and to link the mail sent)
		$piece = null;
		$key = (string) $type.':'.(int) $id;
		$known = array_map(function ($pc) {
			return $pc['type'].':'.$pc['id'];
		}, (array) ($state['linked'] ?? array()));
		$entry = array('type' => (string) $type, 'id' => (int) $id, 'ref' => (string) $o->ref, 'kind' => (string) (new InboxElements($this->db))->describe($user, (string) $type, (int) $id)['kind'],
			'icon' => (string) (InboxElements::TYPES[$type]['icon'] ?? 'document'));
		if (!in_array($key, $known, true)) {
			$state['linked'][] = $entry;
		}
		$state['pieces'] = $this->piecesOf($user, (array) $state['linked']);
		foreach ($state['pieces'] as $pc) {
			if ($pc['type'].':'.$pc['id'] === $key) {
				$piece = $pc;
			}
		}
		self::writeState($dir, $state);

		return array('html' => $html, 'attachment' => $att, 'piece' => $piece);
	}

	/**
	 * A file of a writing, to check it before sending (lot A5, 1.82.0): the very file that leaves with the mail
	 *
	 * @param User   $user  User
	 * @param string $token Writing (of this user, in this session)
	 * @param string $id    Attachment
	 * @return array{name:string,content:string,mime:string}|null
	 */
	public function attachmentFile(User $user, $token, $id)
	{
		$dir = $this->held($user, $token);
		if ($dir === null) {
			return $this->fail('not_found');
		}
		$state = self::readState($dir);
		foreach ((array) ($state['attachments'] ?? array()) as $a) {
			if ((string) $a['id'] === (string) $id && preg_match('/^[0-9a-f]{16}$/', (string) $id) && is_file($dir.'/att-'.$id)) {
				return array('name' => (string) $a['name'], 'content' => (string) self::getFile($dir, 'att-'.$id), 'mime' => (string) $a['mime']);
			}
		}

		return $this->fail('not_found');
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
	public function prepare(User $user, $dir, array $state, array $data, $draft)
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
		if ($account->isProtected() && !self::sealDir($dir)) {
			return $this->fail('error', 'Protected mailbox not unlocked in this session');
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
		$html = InboxMailBuilder::cleanHtml($html, (bool) $draft);
		if (!empty($data['signature']) && trim($identity['signature']) !== '') {
			// Its variables replaced (1.53.0), as the writing showed it
			require_once __DIR__.'/inboxtemplates.class.php';
			$signed = (new InboxTemplates($this->db))->signatures($user, array($identity));
			// No « -- » in the HTML (1.53.5): the text part writes it (RFC 3676), the HTML marks the signature by its class
			$html .= '<div class="inbox-signature">'.InboxMailBuilder::cleanHtml($signed[(int) $identity['id']] ?? $identity['signature']).'</div>';
		}
		if (!empty($state['quote']) && !empty($data['quote']) && is_file($dir.'/quote.html')) {
			$html .= '<br>'.(string) self::getFile($dir, 'quote.html');
		}
		$b->html = $html;
		$b->keepVars = (bool) $draft;
		$b->receipt = !empty($data['receipt']);
		$source = $state['source'] ?? null;
		// « New conversation » (1.139.0): an answer without In-Reply-To nor References, a new thread for everyone
		$newThread = !empty($data['new_thread']);
		if ($newThread) {
			// No header of answer
		} elseif ($source && in_array($source['mode'], array('reply', 'replyall', 'replylist', 'forward', 'forward_attach'), true)) {
			$isReply = !in_array($source['mode'], array('forward', 'forward_attach'), true);
			if ($isReply && (string) ($source['message_id'] ?? '') !== '') {
				$b->inReplyTo = (string) $source['message_id'];
				$b->references = $this->refsFromCut(array_merge((array) ($state['source_refs'] ?? array()), array((string) $source['message_id'])));
			}
		} elseif (!empty($state['in_reply_to'])) {
			$b->inReplyTo = (string) $state['in_reply_to'];
			$b->references = $this->refsFromCut((array) ($state['source_refs'] ?? array()));
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
			// Sealed: given by its content, never written again in clear
			$b->attachments[] = self::isSealed($dir) ? array('content' => (string) self::getFile($dir, 'att-'.$a['id']), 'name' => $a['name'], 'mime' => $a['mime'])
				: array('path' => $path, 'name' => $a['name'], 'mime' => $a['mime']);
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
			if ($newThread) {
				$b->extraHeaders['X-Inbox-New-Thread'] = '1';
			}
		}

		return array('builder' => $b, 'account' => $account, 'identity' => $identity);
	}

	/**
	 * References of an answer from the last cut point (1.139.0): a conversation separated in Inbox is separated for
	 * the correspondent too, his mail program never sees the old mails in the chain
	 *
	 * @param string[] $refs Message-IDs, the oldest first
	 * @return string[]
	 */
	protected function refsFromCut(array $refs)
	{
		$refs = array_values(array_filter(array_map('strval', $refs)));
		if (!$refs) {
			return $refs;
		}
		$roots = (new InboxThreader($this->db))->rootsOf($refs);
		for ($i = count($refs) - 1; $i >= 0; $i--) {
			if (($roots[$refs[$i]] ?? 0) > 0) {
				return array_slice($refs, $i);
			}
		}

		return $refs;
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
		$this->removeDraft($previous, $connector, (int) $account->id, $user);
		$connector->disconnect();

		return array('saved' => dol_now());
	}

	/**
	 * A previous version of a draft removed from the server and the index
	 *
	 * @param array<string,mixed>|null     $draft     Draft (account, folder, remote_id, mail)
	 * @param InboxConnectorInterface|null $connector Connector of the mailbox of the new draft
	 * @param int                          $accountId Its mailbox
	 * @param User|null                    $user      User: the level « send » on the mailbox of the draft is checked again
	 * @return void
	 */
	public function removeDraft($draft, $connector, $accountId, $user = null)
	{
		if (!$draft || (string) ($draft['remote_id'] ?? '') === '') {
			return;
		}
		if ($user instanceof User) {
			$access = new InboxAccess($this->db);
			if (!InboxAccess::atLeast($access->getLevel($user, (int) $draft['account']), InboxAccess::LEVEL_SEND)) {
				return;
			}
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
			$this->removeDraft($state['draft'], null, 0, $user);
		}
		dol_delete_dir_recursive($dir);
		unset($_SESSION['inbox_compose'][$token]);

		return true;
	}

	/**
	 * Send: the mail is built and frozen here, put in the queue (InboxOutbox) with what remains to do after the
	 * sending; it leaves at once when there is no delay, else after the delay (page or scheduled job), or at the date
	 * chosen. Checks of the sending at once (recipients, hook of the other modules).
	 *
	 * @param User                $user  User
	 * @param string              $token Writing
	 * @param array<string,mixed> $data  Typed
	 * @param array<string,mixed> $opts  at (date of a scheduled sending), record (pieces « type:id » to record it on), force (send
	 *                                   even when a colleague answered the same mail meanwhile)
	 * @return array<string,mixed>|null Sent: message_id, sent_copy, account, outbox; queued: queued (id), send_at, delay;
	 *                                   not sent: conflict (an answer of a colleague, lot E2)
	 */
	public function send(User $user, $token, array $data, array $opts = array())
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
		$at = (int) ($opts['at'] ?? 0);
		if ($at && ($at < dol_now() + 60 || $at > dol_now() + 366 * 86400)) {
			return $this->fail('bad_date');
		}
		// An answer already sent to this mail from the mailbox since the writing was opened (lot E2, 1.77.0): said, not
		// sent; « Send anyway » asks again with force
		$src = $state['source'] ?? null;
		if (empty($opts['force']) && is_array($src) && in_array((string) ($src['mode'] ?? ''), array('reply', 'replyall', 'replylist'), true)) {
			require_once __DIR__.'/inboxactivity.class.php';
			$conflict = (new InboxActivity($this->db))->answeredSince((int) ($src['account'] ?? 0), (string) ($src['message_id'] ?? ''), (int) ($state['opened'] ?? 0), (string) $token);
			if ($conflict !== null) {
				return array('conflict' => $conflict);
			}
		}
		// Other modules may check the mail and stop it (P5): hook inboxBeforeSend, context inbox
		if (is_object($hookmanager)) {
			$hookmanager->initHooks(array('inbox'));
			$parameters = array('account' => (int) $account->id, 'identity' => $prepared['identity']['id'], 'to' => $b->to, 'cc' => $b->cc, 'bcc' => $b->bcc, 'subject' => $b->subject, 'source' => $state['source'], 'at' => $at);
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
		// « New conversation » (1.139.0): the mail sent starts its own conversation, also in our index (else the same
		// subject and correspondent would put it back in the old one); nothing kept for a protected mailbox
		if (!empty($data['new_thread']) && !$account->isProtected()) {
			$ref = (new InboxMailRef($this->db))->fetchOrCreateByMessageId((string) $built['message_id'], array('subject' => $b->subject,
				'from_email' => (string) ($b->from['email'] ?? ''), 'date_mail' => dol_now(), 'origin' => 'inbox', 'last_account' => (int) $account->id));
			if ($ref > 0) {
				$this->db->query("UPDATE ".$this->db->prefix()."inbox_mailref SET thread_root = ".InboxThreader::ROOT_USER." WHERE rowid = ".((int) $ref));
			}
		}
		// Pieces to record the sending on: only those offered when the writing opened (linked, readable, writable)
		$allowed = array();
		foreach ((array) ($state['pieces'] ?? array()) as $pc) {
			$allowed[$pc['type'].':'.$pc['id']] = $pc;
		}
		$record = array();
		foreach ((array) ($opts['record'] ?? array()) as $key) {
			if (isset($allowed[(string) $key])) {
				$record[] = array('type' => $allowed[$key]['type'], 'id' => (int) $allowed[$key]['id']);
			}
		}
		// Protected mailbox (1.89.0): never in the queue of the server, kept for the session (lot S3)
		if ($account->isProtected()) {
			return $this->sendProtected($user, $account, $prepared, $b, $built, $state, $token, $data, $at, (string) ($opts['catchup'] ?? 'ask'));
		}
		require_once __DIR__.'/inboxoutbox.class.php';
		$outbox = new InboxOutbox($this->db);
		$delay = $at ? 0 : self::delayFor($user);
		$id = $outbox->queue($user, $account, $prepared['identity'], $b, $built, array(
			'token' => $token, 'source' => $state['source'] ?? null, 'draft' => $state['draft'] ?? null, 'record' => $record, 'pieces' => array_values($allowed),
			'unsubscribe' => $state['unsubscribe'] ?? null, 'typed' => $data, 'at' => $at ?: dol_now() + $delay, 'scheduled' => $at ? 1 : 0,
		));
		if ($id <= 0) {
			return $this->fail('error', $outbox->error);
		}
		if (!$at && $delay === 0) {
			$res = $outbox->deliver($id, $user, true);
			if ($res === null) {
				$this->errorCode = $outbox->errorCode;
				$this->error = $outbox->error;
				return null;
			}
			return $res;
		}

		return array('queued' => $id, 'send_at' => $at ?: dol_now() + $delay, 'delay' => $delay, 'scheduled' => $at ? 1 : 0, 'subject' => $b->subject,
			'pieces' => array_values($allowed), 'record' => $record);
	}

	/**
	 * Sending of a protected mailbox (1.89.0, analyse-boites-protegees §7): the delay of the undo kept in the session
	 * (the page sends it at its end, or at once when it closes); a date: the mail waits in Drafts with its date
	 *
	 * @param User                $user     User
	 * @param InboxAccount        $account  Mailbox
	 * @param array<string,mixed> $prepared Result of prepare()
	 * @param InboxMailBuilder    $b        Builder
	 * @param array<string,mixed> $built    Result of build()
	 * @param array<string,mixed> $state    State of the writing
	 * @param string              $token    Writing
	 * @param array<string,mixed> $data     Values typed
	 * @param int                 $at       Date of a sending scheduled, 0 = now
	 * @param string              $catchup  Scheduled: ask or send when no page was open at the date
	 * @return array<string,mixed>|null
	 */
	protected function sendProtected(User $user, InboxAccount $account, array $prepared, InboxMailBuilder $b, array $built, array $state, $token, array $data, $at, $catchup)
	{
		require_once __DIR__.'/inboxlivesend.class.php';
		$live = new InboxLiveSend($this->db);
		if ($at) {
			$rid = $live->schedule($user, $account, $built, $at, $catchup);
			if ($rid === null) {
				return $this->fail($live->errorCode ?: 'error', $live->error);
			}
			// The writing is over: its draft and its folder go (the mail waits in Drafts on the server)
			if (!empty($state['draft'])) {
				$this->removeDraft($state['draft'], null, (int) $account->id, $user);
			}
			$dir = self::dirOf($user, $token);
			if ($dir !== null) {
				dol_delete_dir_recursive($dir);
			}
			unset($_SESSION['inbox_compose'][$token]);

			return array('queued' => 0, 'send_at' => (int) $at, 'delay' => 0, 'scheduled' => 1, 'subject' => $b->subject, 'pieces' => array(), 'record' => array(), 'live' => 1);
		}
		$delay = self::delayFor($user);
		$id = $live->queue($user, $account, $prepared['identity'], $b, $built, array('token' => $token, 'source' => $state['source'] ?? null, 'draft' => $state['draft'] ?? null,
			'typed' => $data, 'at' => dol_now() + $delay));
		if ($id === 0) {
			if ($live->error !== 'too_big') {
				return $this->fail('error', $live->error);
			}
			$delay = 0;
		}
		if ($delay === 0 || $id === 0) {
			$res = $id ? $live->deliver($user, $id) : null;
			if ($res === null) {
				$this->errorCode = $live->errorCode ?: 'error';
				$this->error = $live->error;
				return null;
			}
			return $res;
		}

		return array('queued' => $id, 'send_at' => dol_now() + $delay, 'delay' => $delay, 'scheduled' => 0, 'subject' => $b->subject, 'pieces' => array(), 'record' => array(), 'live' => 1);
	}

	/**
	 * The mail answered marked \Answered on the server, checked, then in the index (P8); a mail forwarded gets the
	 * keyword $Forwarded instead (1.26.0)
	 *
	 * @param User                         $user      User
	 * @param array<string,mixed>          $source    Mail answered
	 * @param InboxConnectorInterface|null $connector Connector of its mailbox (null: opened here)
	 * @param bool                         $forward   The mail was forwarded
	 * @return void
	 */
	public function markAnswered(User $user, array $source, $connector, $forward = false)
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
		if ($forward) {
			if ($connector && $connector->can(InboxConnectorInterface::CAP_TAGS)
				&& $connector->setTags((string) $source['folder'], array((string) $source['remote_id']), array('$Forwarded'), array())) {
				$row = $this->db->getRow("SELECT keywords FROM ".$this->db->prefix()."inbox_mail WHERE rowid = ".((int) $source['mail']));
				$keywords = is_object($row) ? array_filter(explode(' ', (string) $row->keywords)) : array();
				if (!in_array('$Forwarded', $keywords, true)) {
					$keywords[] = '$Forwarded';
					$this->db->query("UPDATE ".$this->db->prefix()."inbox_mail SET keywords = '".$this->db->escape(dol_trunc(implode(' ', $keywords), 1024, 'right', 'UTF-8', 1))."'"
						." WHERE rowid = ".((int) $source['mail']));
				}
			}
		} elseif ($connector && $connector->setFlags((string) $source['folder'], array((string) $source['remote_id']), array('\\Answered'), array())) {
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
	public function refreshFolders(InboxAccount $account, array $paths)
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

	/** Weights of the ranking of the recipients proposed (1.99.0) */
	const RCPT_MATCH = 15;
	const RCPT_CONTEXT = 100;
	const RCPT_CONTEXT_LINKED = 50;
	const RCPT_SENT = 14;
	const RCPT_RECEIVED = 5;
	/** Exchanges counted at most (log2: 31 mails), so that the context always comes first */
	const RCPT_LOG_CAP = 5;
	/** Days after which an exchange counts half */
	const RCPT_HALF_DAYS = 180;

	/**
	 * Recipients proposed while typing (1.99.0): contacts, thirdparties and users of Dolibarr the user may see and the
	 * correspondents of the mailboxes the user reads, ranked together. First the contacts of the thirdparty of the mail
	 * answered or of the recipients already given (and of the other thirdparties such a contact is linked to), then the
	 * addresses written to often and lately, then those read from, then how well the typed words match. Nothing typed:
	 * only the contacts of these thirdparties, else nothing.
	 *
	 * @param User     $user  User
	 * @param string   $q     Typed (2 characters at least, words in any order)
	 * @param string   $token Writing (its mail answered gives the context), '' for none
	 * @param string[] $given Addresses already in the fields (context, never proposed again)
	 * @return array<int,array{email:string,name:string,detail:string,kind:string}>
	 */
	public function recipients(User $user, $q, $token = '', array $given = array())
	{
		$q = trim((string) $q);
		$words = self::recipientWords($q);
		if ($q !== '' && !$words) {
			return array();
		}
		$given = array_values(array_unique(array_filter(array_map(function ($e) {
			return strtolower(trim((string) $e));
		}, $given), function ($e) {
			return filter_var($e, FILTER_VALIDATE_EMAIL) !== false;
		})));
		$given = array_slice($given, 0, 50);
		$ctx = $this->recipientContext($user, (string) $token, $given);
		if (!$words && !$ctx['primary']) {
			return array();
		}
		$cand = array();
		$put = function ($email, $name, $detail, $kind, array $socs = array(), $contactId = 0) use (&$cand, $given) {
			$email = strtolower(trim((string) $email));
			if ($email === '' || in_array($email, $given, true) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
				return;
			}
			if (!isset($cand[$email])) {
				$cand[$email] = array('email' => $email, 'name' => trim((string) $name), 'detail' => trim((string) $detail), 'kind' => $kind, 'socs' => array(), 'contacts' => array());
			} elseif ($cand[$email]['kind'] === 'recent' && $kind !== 'recent') {
				// The data of Dolibarr wins over the name read in the mails
				$cand[$email]['kind'] = $kind;
				$cand[$email]['name'] = trim((string) $name) !== '' ? trim((string) $name) : $cand[$email]['name'];
				$cand[$email]['detail'] = trim((string) $detail);
			}
			foreach ($socs as $s) {
				if ((int) $s > 0) {
					$cand[$email]['socs'][(int) $s] = true;
				}
			}
			if ((int) $contactId > 0) {
				$cand[$email]['contacts'][(int) $contactId] = true;
			}
		};
		$putContacts = function ($rows) use ($put) {
			foreach ($rows as $r) {
				$put($r->email, trim($r->firstname.' '.$r->lastname), (string) $r->nom, 'contact', array((int) $r->fk_soc), (int) $r->rowid);
			}
		};
		$putThirdparties = function ($rows) use ($put) {
			foreach ($rows as $r) {
				$put($r->email, (string) $r->nom, '', 'thirdparty', array((int) $r->rowid));
			}
		};
		$p = $this->db->prefix();
		$socs = array_merge($ctx['primary'], $ctx['linked']);

		// The contacts of the context first: never pushed out by the limits of the other sources
		if ($socs) {
			$in = implode(',', $socs);
			$cond = "(c.fk_soc IN (".$in.") OR c.rowid IN (SELECT sc2.fk_socpeople FROM ".$p."societe_contacts as sc2 WHERE sc2.fk_soc IN (".$in.")))";
			$putContacts($this->recipientContacts($user, $cond.($words ? ' AND '.$this->likeAll($words, array('c.email', 'c.lastname', 'c.firstname')) : ''), 30));
			$cond = "s.rowid IN (".implode(',', $ctx['primary'] ?: array(0)).")";
			$putThirdparties($this->recipientThirdparties($user, $cond.($words ? ' AND '.$this->likeAll($words, array('s.email', 's.nom')) : ''), 10));
		}
		$stats = array();
		if ($words) {
			$putContacts($this->recipientContacts($user, $this->likeAll($words, array('c.email', 'c.lastname', 'c.firstname', 's.nom')), 30, $words[0], array('c.email', 'c.lastname', 'c.firstname')));
			$putThirdparties($this->recipientThirdparties($user, $this->likeAll($words, array('s.email', 's.nom')), 20, $words[0], array('s.email', 's.nom')));
			$rows = $this->db->getRows("SELECT email, firstname, lastname FROM ".$p."user WHERE statut = 1 AND email <> '' AND fk_soc IS NULL AND entity IN (0, ".getEntity('user').")"
				." AND ".$this->likeAll($words, array('email', 'lastname', 'firstname'))." ORDER BY lastname LIMIT 20");
			foreach (is_array($rows) ? $rows : array() as $r) {
				$put($r->email, trim($r->firstname.' '.$r->lastname), '', 'user');
			}
			// The correspondents of the mailboxes, the most written to first
			foreach ($this->recipientStats($user, $this->likeAll($words, array('a.email', 'a.name')), 40) as $email => $st) {
				$stats[$email] = $st;
				$put($email, $st['name'], '', 'recent');
			}
			// A correspondent known in Dolibarr: its name, its thirdparty
			$known = array_keys(array_filter($cand, function ($c) {
				return $c['kind'] === 'recent';
			}));
			if ($known) {
				$list = "'".implode("','", array_map(array($this->db, 'escape'), $known))."'";
				$putContacts($this->recipientContacts($user, "c.email IN (".$list.")", 50));
				$putThirdparties($this->recipientThirdparties($user, "s.email IN (".$list.")", 50));
			}
		}
		if (!$cand) {
			return array();
		}
		// Other thirdparties of the contacts found (contacts linked to several thirdparties)
		$contactIds = array();
		foreach ($cand as $c) {
			$contactIds = array_merge($contactIds, array_keys($c['contacts']));
		}
		if ($contactIds && $socs) {
			$byContact = array();
			$rows = $this->db->getRows("SELECT fk_socpeople, fk_soc FROM ".$p."societe_contacts WHERE fk_socpeople IN (".implode(',', array_map('intval', $contactIds)).") LIMIT 500");
			foreach (is_array($rows) ? $rows : array() as $r) {
				$byContact[(int) $r->fk_socpeople][] = (int) $r->fk_soc;
			}
			foreach ($cand as $email => $c) {
				foreach (array_keys($c['contacts']) as $id) {
					foreach ($byContact[$id] ?? array() as $s) {
						$cand[$email]['socs'][$s] = true;
					}
				}
			}
		}
		$missing = array_values(array_diff(array_keys($cand), array_keys($stats)));
		if ($missing) {
			$stats += $this->recipientStats($user, "a.email IN ('".implode("','", array_map(array($this->db, 'escape'), $missing))."')", count($missing));
		}
		$now = dol_now();
		foreach ($cand as $email => $c) {
			$cand[$email]['score'] = self::recipientScore($c + ($stats[$email] ?? array()), $words, $ctx['primary'], $ctx['linked'], $now);
		}
		uasort($cand, function ($a, $b) {
			return $b['score'] <=> $a['score'] ?: strcmp($a['name'] ?: $a['email'], $b['name'] ?: $b['email']);
		});
		$out = array();
		foreach (array_slice($cand, 0, $words ? 10 : 6) as $c) {
			$out[] = array('email' => $c['email'], 'name' => $c['name'], 'detail' => $c['detail'], 'kind' => $c['kind']);
		}

		return $out;
	}

	/**
	 * @param string $q Typed
	 * @return string[] Words searched (4 at most), empty when less than 2 characters are typed
	 */
	public static function recipientWords($q)
	{
		$q = trim((string) $q);
		if (mb_strlen($q, 'UTF-8') < 2) {
			return array();
		}

		return array_slice(array_values(array_filter(preg_split('/[\s,;<>"()]+/u', $q), 'strlen')), 0, 4);
	}

	/**
	 * Score of a proposal: context, then sendings, then mails received, then match of the words
	 *
	 * @param array<string,mixed> $c       Candidate: socs (ids as keys), name, email, detail, nb_sent, last_sent, nb_recv, last_recv
	 * @param string[]            $words   Words typed
	 * @param int[]               $primary Thirdparties of the mail answered and of the recipients given
	 * @param int[]               $linked  Other thirdparties of their contacts
	 * @param int                 $now     Now
	 * @return float
	 */
	public static function recipientScore(array $c, array $words, array $primary, array $linked, $now)
	{
		$score = 0.0;
		// Exchanges count fully when a word starts a name or the address, a quarter when found inside only
		$weight = 1.0;
		if ($words) {
			$norm = function ($s) {
				return strtolower(dol_string_unaccent((string) $s));
			};
			$at = strpos((string) $c['email'], '@');
			$split = function ($s) {
				return array_values(array_filter(preg_split('/[^a-z0-9]+/', $s), 'strlen'));
			};
			// Name, thirdparty and part before @ (also whole, « jdupont »); the domain gives a weak match only
			$local = $norm(substr((string) $c['email'], 0, $at === false ? null : $at));
			$tokens = array_merge($split($norm(($c['name'] ?? '').' '.($c['detail'] ?? '').' '.$local)), array($local));
			$domain = $at === false ? '' : $norm(substr((string) $c['email'], $at + 1));
			$match = 0;
			foreach ($words as $w) {
				$w = $norm($w);
				$best = 0;
				foreach ($tokens as $t) {
					if ($t === $w) {
						$best = 3;
						break;
					}
					$best = max($best, strpos($t, $w) === 0 ? 2 : (strpos($t, $w) !== false ? 1 : 0));
				}
				if ($best === 0 && $domain !== '' && strpos($domain, $w) !== false) {
					$best = strpos($domain, $w) === 0 ? 1 : 0.5;
				}
				$match += $best;
			}
			$match /= count($words);
			$score += self::RCPT_MATCH * $match;
			$weight = $match >= 2 ? 1.0 : 0.25;
		}
		$ctx = 0;
		foreach (array_keys($c['socs'] ?? array()) as $s) {
			$ctx = max($ctx, in_array((int) $s, $primary, true) ? self::RCPT_CONTEXT : (in_array((int) $s, $linked, true) ? self::RCPT_CONTEXT_LINKED : 0));
		}
		$score += $ctx;
		$decay = function ($date) use ($now) {
			return $date ? 1 / (1 + max(0, $now - (int) $date) / 86400 / self::RCPT_HALF_DAYS) : 0;
		};
		$count = function ($n) {
			return min(self::RCPT_LOG_CAP, log(1 + max(0, (int) $n), 2));
		};
		$score += $weight * self::RCPT_SENT * $count($c['nb_sent'] ?? 0) * $decay($c['last_sent'] ?? 0);
		$score += $weight * self::RCPT_RECEIVED * $count($c['nb_recv'] ?? 0) * $decay($c['last_recv'] ?? 0);

		return $score;
	}

	/**
	 * Thirdparties giving the context: the one of the mail answered (and of its contact), those of the recipients
	 * already given; the other thirdparties their contacts are linked to count less
	 *
	 * @param User     $user  User
	 * @param string   $token Writing, '' for none
	 * @param string[] $given Addresses given (lower case)
	 * @return array{primary:int[],linked:int[]}
	 */
	protected function recipientContext(User $user, $token, array $given)
	{
		$p = $this->db->prefix();
		$primary = array();
		$contacts = array();
		if ($token !== '' && ($dir = $this->held($user, $token)) !== null) {
			$state = self::readState($dir);
			$mailId = (int) ($state['source']['mail'] ?? 0);
			$row = $mailId ? $this->db->getRow("SELECT fk_soc, fk_socpeople FROM ".$p."inbox_mail WHERE rowid = ".$mailId) : null;
			if (is_object($row)) {
				$primary[] = (int) $row->fk_soc;
				$contacts[] = (int) $row->fk_socpeople;
			}
		}
		if ($given && isModEnabled('societe')) {
			$list = "'".implode("','", array_map(array($this->db, 'escape'), $given))."'";
			$rows = $this->db->getRows("SELECT rowid, fk_soc FROM ".$p."socpeople WHERE entity IN (".getEntity('contact').") AND email IN (".$list.") LIMIT 100");
			foreach (is_array($rows) ? $rows : array() as $r) {
				$contacts[] = (int) $r->rowid;
				$primary[] = (int) $r->fk_soc;
			}
			$rows = $this->db->getRows("SELECT rowid FROM ".$p."societe WHERE entity IN (".getEntity('societe').") AND email IN (".$list.") LIMIT 50");
			foreach (is_array($rows) ? $rows : array() as $r) {
				$primary[] = (int) $r->rowid;
			}
		}
		$contacts = array_values(array_filter(array_unique($contacts)));
		if ($contacts) {
			$rows = $this->db->getRows("SELECT fk_soc FROM ".$p."socpeople WHERE rowid IN (".implode(',', $contacts).") LIMIT 100");
			foreach (is_array($rows) ? $rows : array() as $r) {
				$primary[] = (int) $r->fk_soc;
			}
		}
		$primary = array_values(array_filter(array_unique($primary)));
		$linked = array();
		if ($contacts) {
			$rows = $this->db->getRows("SELECT fk_soc FROM ".$p."societe_contacts WHERE fk_socpeople IN (".implode(',', $contacts).") LIMIT 100");
			foreach (is_array($rows) ? $rows : array() as $r) {
				if (!in_array((int) $r->fk_soc, $primary, true)) {
					$linked[] = (int) $r->fk_soc;
				}
			}
		}

		return array('primary' => array_slice($primary, 0, 50), 'linked' => array_slice(array_values(array_filter(array_unique($linked))), 0, 50));
	}

	/**
	 * Every word in one of the fields at least
	 *
	 * @param string[] $words  Words
	 * @param string[] $fields Columns
	 * @return string SQL condition
	 */
	protected function likeAll(array $words, array $fields)
	{
		$and = array();
		foreach ($words as $w) {
			$like = $this->db->escape($this->db->escapeforlike($w));
			$and[] = '('.implode(' OR ', array_map(function ($f) use ($like) {
				return $f." LIKE '%".$like."%'";
			}, $fields)).')';
		}

		return '('.implode(' AND ', $and).')';
	}

	/**
	 * @param string   $first  First word typed ('' for none)
	 * @param string[] $fields Columns
	 * @return string SQL expression: 0 when a field starts with the word (taken first under the limit), else 1
	 */
	protected function prefixFirst($first, array $fields)
	{
		if ($first === '') {
			return '';
		}
		$like = $this->db->escape($this->db->escapeforlike($first));

		return "CASE WHEN ".implode(' OR ', array_map(function ($f) use ($like) {
			return $f." LIKE '".$like."%'";
		}, $fields))." THEN 0 ELSE 1 END, ";
	}

	/**
	 * Contacts with an address the user may see
	 *
	 * @param User     $user   User
	 * @param string   $where  SQL condition (c: contact, s: thirdparty)
	 * @param int      $limit  Limit
	 * @param string   $first  First word typed: the fields starting with it first
	 * @param string[] $fields Fields for $first
	 * @return array<int,object>
	 */
	protected function recipientContacts(User $user, $where, $limit, $first = '', array $fields = array())
	{
		if (!isModEnabled('societe') || !$user->hasRight('societe', 'lire') || !$user->hasRight('societe', 'contact', 'lire')) {
			return array();
		}
		$p = $this->db->prefix();
		$join = !$user->hasRight('societe', 'client', 'voir') ? " INNER JOIN ".$p."societe_commerciaux as sc ON sc.fk_soc = s.rowid AND sc.fk_user = ".((int) $user->id) : '';
		$rows = $this->db->getRows("SELECT c.rowid, c.email, c.firstname, c.lastname, c.fk_soc, s.nom FROM ".$p."socpeople as c LEFT JOIN ".$p."societe as s ON s.rowid = c.fk_soc".$join
			." WHERE c.entity IN (".getEntity('contact').") AND c.statut = 1 AND c.email <> '' AND (c.priv = 0 OR c.fk_user_creat = ".((int) $user->id).") AND ".$where
			." ORDER BY ".$this->prefixFirst($first, $fields)."c.lastname LIMIT ".((int) $limit));

		return is_array($rows) ? $rows : array();
	}

	/**
	 * Thirdparties with an address the user may see
	 *
	 * @param User     $user   User
	 * @param string   $where  SQL condition (s: thirdparty)
	 * @param int      $limit  Limit
	 * @param string   $first  First word typed: the fields starting with it first
	 * @param string[] $fields Fields for $first
	 * @return array<int,object>
	 */
	protected function recipientThirdparties(User $user, $where, $limit, $first = '', array $fields = array())
	{
		if (!isModEnabled('societe') || !$user->hasRight('societe', 'lire')) {
			return array();
		}
		$p = $this->db->prefix();
		$join = !$user->hasRight('societe', 'client', 'voir') ? " INNER JOIN ".$p."societe_commerciaux as sc ON sc.fk_soc = s.rowid AND sc.fk_user = ".((int) $user->id) : '';
		$rows = $this->db->getRows("SELECT s.rowid, s.email, s.nom FROM ".$p."societe as s".$join." WHERE s.entity IN (".getEntity('societe').") AND s.status = 1 AND s.email <> '' AND ".$where
			." ORDER BY ".$this->prefixFirst($first, $fields)."s.nom LIMIT ".((int) $limit));

		return is_array($rows) ? $rows : array();
	}

	/**
	 * Exchanges with addresses in the mailboxes the user reads: mails sent to them (Sent folders), mails received
	 * from them (out of the junk, trash and drafts)
	 *
	 * @param User   $user  User
	 * @param string $where SQL condition (a: address)
	 * @param int    $limit Limit
	 * @return array<string,array{name:string,nb_sent:int,last_sent:int,nb_recv:int,last_recv:int}> By address (lower case)
	 */
	protected function recipientStats(User $user, $where, $limit)
	{
		$access = new InboxAccess($this->db);
		$readable = array_map('intval', array_keys($access->getAccounts($user, InboxAccess::LEVEL_READ)));
		if (!$readable) {
			return array();
		}
		$p = $this->db->prefix();
		$sent = "f.role = 'sent' AND a.type IN ('to', 'cc')";
		$recv = "(f.role IS NULL OR f.role NOT IN ('sent', 'junk', 'trash', 'drafts')) AND a.type = 'from'";
		$rows = $this->db->getRows("SELECT a.email, MAX(a.name) as name, SUM(CASE WHEN ".$sent." THEN 1 ELSE 0 END) as nb_sent, MAX(CASE WHEN ".$sent." THEN m.date_mail END) as last_sent,"
			." SUM(CASE WHEN ".$recv." THEN 1 ELSE 0 END) as nb_recv, MAX(CASE WHEN ".$recv." THEN m.date_mail END) as last_recv"
			." FROM ".$p."inbox_mail_address as a INNER JOIN ".$p."inbox_mail as m ON m.rowid = a.fk_mail INNER JOIN ".$p."inbox_folder as f ON f.rowid = m.fk_folder"
			." WHERE m.fk_account IN (".implode(',', $readable).") AND a.type IN ('to', 'cc', 'from') AND ".$where
			." GROUP BY a.email ORDER BY nb_sent DESC, last_sent DESC, nb_recv DESC LIMIT ".max(1, (int) $limit));
		$out = array();
		foreach (is_array($rows) ? $rows : array() as $r) {
			$email = strtolower(trim((string) $r->email));
			if (isset($out[$email]) || (int) $r->nb_sent + (int) $r->nb_recv === 0) {
				continue;
			}
			$out[$email] = array('name' => (string) $r->name, 'nb_sent' => (int) $r->nb_sent, 'last_sent' => (int) $this->db->jdate($r->last_sent),
				'nb_recv' => (int) $r->nb_recv, 'last_recv' => (int) $this->db->jdate($r->last_recv));
		}

		return $out;
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
		global $db;

		$queued = array();
		$rows = $db->getRows("SELECT compose_token FROM ".$db->prefix()."inbox_outbox WHERE status IN (0, 1, -1) AND compose_token IS NOT NULL LIMIT 10000");
		foreach (is_array($rows) ? $rows : array() as $q) {
			$queued[(string) $q->compose_token] = true;
		}
		foreach (glob($base.'/*/*', GLOB_ONLYDIR) ?: array() as $dir) {
			if (isset($queued[basename($dir)])) {
				continue;
			}
			if (preg_match('#/\d+/[0-9a-f]{32}$#', $dir) && @filemtime($dir.'/state.json') < dol_now() - self::KEEP_DAYS * 86400) {
				dol_delete_dir_recursive($dir);
				$n++;
			}
		}

		return $n;
	}
}
