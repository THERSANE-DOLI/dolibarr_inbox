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
 * \file    inbox/class/inboxconsumerinterface.class.php
 * \ingroup inbox
 * \brief   What the class of a module subscribed to the mailboxes implements (1.112.0), and the event it receives
 *          with what the module may do on its mail (1.146.0)
 */

/**
 * The class a module declares with the hook inboxConsumers (see InboxConsumer). inbox calls handle() once per event,
 * only for the mailboxes where a manager allowed the module, never twice for the same event.
 */
interface InboxConsumerInterface
{
	/**
	 * React to an event of a mailbox
	 *
	 * @param InboxConsumerEvent $event Event
	 * @return int 1 handled, 0 left (not for this module), -1 error (the event is given again later)
	 */
	public function handle(InboxConsumerEvent $event);
}

/**
 * An event given to a module subscribed: the mailbox, the mail (its Message-ID stays the same when it is moved), its
 * useful headers. Reading the body and acting on the mail go through the event, within what the managers of the
 * mailbox ticked (1.146.0): a method refused returns null or false and says why in $error. Every action is written in
 * the journal of the mailbox in the name of the module; in test mode the actions are only written there.
 *
 * The values of the mail are data from outside: a module writes them in Dolibarr through InboxSafe (doc/dev/mail-data.md).
 */
class InboxConsumerEvent
{
	/** @var string Event (mail.received) */
	public $event = '';

	/** @var string Code of the declaration of the module */
	public $consumer = '';

	/** @var int Mailbox */
	public $accountId = 0;

	/** @var string Address of the mailbox */
	public $accountEmail = '';

	/** @var string Message-ID of the mail */
	public $messageId = '';

	/** @var int Row of the mail in the index of inbox when the event was made */
	public $mailId = 0;

	/** @var string Folder of the mail when the event was made */
	public $folder = '';

	/** @var array{from:string,to:string,cc:string,subject:string,date:int,in_reply_to:string} Useful headers */
	public $headers = array('from' => '', 'to' => '', 'cc' => '', 'subject' => '', 'date' => 0, 'in_reply_to' => '');

	/** @var bool Mode test: the actions are only written in the journal of the mailbox */
	public $test = true;

	/** @var string Why the last method refused or failed ('' when it did not) */
	public $error = '';

	/** @var DoliDB|null */
	protected $db = null;

	/** @var InboxAccount|null */
	protected $account = null;

	/** @var object|null Row of the index of the mail, with folder_path and folder_role */
	protected $mail = null;

	/** @var array{code:string,label:string,actions:string[]} The subscription */
	protected $sub = array('code' => '', 'label' => '', 'actions' => array());

	/** @var InboxMessage|null The mail parsed, once read */
	protected $parsed = null;

	/** @var bool The reading of the content is written in the journal once per event */
	protected $readLogged = false;

	/**
	 * Set by inbox before the event is given (InboxConsumerDelivery), not for the modules
	 *
	 * @param DoliDB                                            $db      Database handler
	 * @param InboxAccount                                      $account Mailbox
	 * @param object                                            $mail    Row of the index, with folder_path, folder_role
	 * @param array{code:string,label:string,actions:string[]} $sub     Subscription
	 * @return void
	 */
	public function setContext($db, $account, $mail, array $sub)
	{
		$this->db = $db;
		$this->account = $account;
		$this->mail = $mail;
		$this->sub = $sub;
	}

	/**
	 * The managers allowed this action to the module on this mailbox
	 *
	 * @param string $action read, seen, tag, move, link, draft
	 * @return bool
	 */
	public function can($action)
	{
		return in_array((string) $action, $this->sub['actions'], true);
	}

	/**
	 * @param string $action Action needed
	 * @return bool It may (else $error says it)
	 */
	protected function allowed($action)
	{
		$this->error = '';
		if ($this->db === null || $this->mail === null) {
			$this->error = 'No mail';
			return false;
		}
		if (!$this->can($action)) {
			$this->error = 'Action not allowed on this mailbox: '.$action;
			return false;
		}

		return true;
	}

	/**
	 * User given to the code of inbox for an action of the module: nobody (id 0), named after the module
	 *
	 * @return User
	 */
	protected function moduleUser()
	{
		$u = new User($this->db);
		$u->id = 0;
		$u->login = $this->sub['code'];
		$u->firstname = '';
		$u->lastname = $this->sub['label'];

		return $u;
	}

	/**
	 * Write a line of the journal in the name of the module
	 *
	 * @param string $action    Action of the journal
	 * @param string $from      Folder of the mail
	 * @param string $to        Target folder
	 * @param string $detail    Detail
	 * @param bool   $simulated Not done (test mode)
	 * @return void
	 */
	protected function log($action, $from = '', $to = '', $detail = '', $simulated = false)
	{
		require_once __DIR__.'/inboxactionlog.class.php';
		$before = InboxActionLog::$consumer;
		InboxActionLog::$consumer = array('code' => $this->sub['code'], 'label' => $this->sub['label'], 'simulated' => $simulated);
		try {
			InboxActionLog::add($this->db, $this->moduleUser(), (int) $this->account->id, $action, array($this->mail), $from, $to, 0, $detail);
		} finally {
			InboxActionLog::$consumer = $before;
		}
	}

	/**
	 * The mail parsed (read on the server when it is not in the cache of inbox), null on error
	 *
	 * @return InboxMessage|null
	 */
	protected function parsed()
	{
		if ($this->parsed === null) {
			require_once __DIR__.'/inboxreader.class.php';
			$reader = new InboxReader($this->db);
			$reader->row = $this->mail;
			$reader->account = $this->account;
			$raw = $reader->raw($this->moduleUser());
			if ($raw === null) {
				$this->error = $reader->errorCode === 'gone' ? 'Mail gone from the server' : 'Mail not read: '.$reader->error;
				return null;
			}
			$this->parsed = InboxMimeParser::parse($raw);
		}
		if (!$this->readLogged) {
			$this->readLogged = true;
			$this->log('consumer_read', (string) $this->mail->folder_path);
		}

		return $this->parsed;
	}

	/**
	 * Text of the mail, and its HTML cleaned (InboxSafe::html: no script, no remote content allowed to run). Needs
	 * the action « read »
	 *
	 * @return array{text:string,html:string}|null
	 */
	public function body()
	{
		if (!$this->allowed('read')) {
			return null;
		}
		$m = $this->parsed();
		if ($m === null) {
			return null;
		}
		require_once __DIR__.'/inboxsafe.class.php';

		return array('text' => (string) $m->bodyText, 'html' => $m->bodyHtml !== '' ? InboxSafe::html($m->bodyHtml) : '');
	}

	/**
	 * Attachments of the mail (pictures inside the text left out). Needs the action « read »
	 *
	 * @return array<int,array{name:string,mime:string,size:int,content:string}>|null
	 */
	public function attachments()
	{
		if (!$this->allowed('read')) {
			return null;
		}
		$m = $this->parsed();
		if ($m === null) {
			return null;
		}
		$out = array();
		foreach ($m->attachments as $a) {
			if (!empty($a['inline']) || !empty($a['embedded'])) {
				continue;
			}
			$out[] = array('name' => (string) $a['name'], 'mime' => (string) $a['mime'], 'size' => (int) $a['size'], 'content' => (string) $a['content']);
		}

		return $out;
	}

	/**
	 * Folders of the mailbox, to choose where to move the mail
	 *
	 * @return array<int,array{path:string,name:string,role:string}> By row
	 */
	public function folders()
	{
		$out = array();
		if ($this->db === null) {
			return $out;
		}
		$rows = $this->db->getRows("SELECT rowid, path, role FROM ".$this->db->prefix()."inbox_folder WHERE fk_account = ".((int) $this->account->id)
			." AND status = 1 AND selectable = 1 ORDER BY path LIMIT 2000");
		require_once __DIR__.'/inboxactionlog.class.php';
		foreach (is_array($rows) ? $rows : array() as $r) {
			$out[(int) $r->rowid] = array('path' => (string) $r->path, 'name' => InboxActionLog::readable($this->db, (int) $this->account->id, (string) $r->path), 'role' => (string) $r->role);
		}

		return $out;
	}

	/**
	 * Act on the mail
	 *
	 * @param string              $action seen (needs « seen »; params on: true read, false unread); tag (needs « tag »;
	 *                                    params add, remove: keywords); move (needs « move »; params folder: row of a
	 *                                    folder of this mailbox, see folders()); archive, junk, trash, inbox (need « move »)
	 * @param array<string,mixed> $params Parameters
	 * @return bool Done (or written in test mode)
	 */
	public function act($action, array $params = array())
	{
		$action = (string) $action;
		$needs = array('seen' => 'seen', 'tag' => 'tag', 'move' => 'move', 'archive' => 'move', 'junk' => 'move', 'trash' => 'move', 'inbox' => 'move');
		if (!isset($needs[$action])) {
			$this->error = 'Unknown action: '.$action;
			return false;
		}
		if (!$this->allowed($needs[$action])) {
			return false;
		}
		$p = $this->db->prefix();
		$run = $action;
		$runParams = array();
		$to = '';
		$detail = '';
		$logAction = $action;
		if ($action === 'seen') {
			$runParams = array('on' => !isset($params['on']) || !empty($params['on']));
			$detail = $runParams['on'] ? 'read' : 'unread';
			$logAction = 'consumer_seen';
		} elseif ($action === 'tag') {
			$clean = function ($list) {
				return array_values(array_filter(array_map('strval', is_array($list) ? $list : array()), function ($k) {
					return preg_match('/^[^\s()\{%*"\\\\\]]{1,64}$/', $k) === 1;
				}));
			};
			$runParams = array('add' => $clean($params['add'] ?? array()), 'remove' => $clean($params['remove'] ?? array()));
			if (!$runParams['add'] && !$runParams['remove']) {
				$this->error = 'No tag';
				return false;
			}
			$detail = trim(($runParams['add'] ? '+'.implode(' +', $runParams['add']) : '').' '.($runParams['remove'] ? '-'.implode(' -', $runParams['remove']) : ''));
		} elseif ($action !== 'archive') {
			// move, or a folder of a role of the mailbox
			if ($action === 'move') {
				$target = $this->db->getRow("SELECT rowid, path FROM ".$p."inbox_folder WHERE rowid = ".((int) ($params['folder'] ?? 0))." AND fk_account = ".((int) $this->account->id)
					." AND status = 1 AND selectable = 1");
			} else {
				$target = $this->db->getRow("SELECT rowid, path FROM ".$p."inbox_folder WHERE fk_account = ".((int) $this->account->id)." AND role = '".$this->db->escape($action)."'"
					." AND status = 1 AND selectable = 1 ORDER BY LENGTH(path)");
			}
			if (!is_object($target)) {
				$this->error = 'Folder not found';
				return false;
			}
			$run = 'move';
			$runParams = array('folder' => (int) $target->rowid);
			$to = (string) $target->path;
			$logAction = 'move';
		}
		if ($this->test) {
			$this->log($logAction, (string) $this->mail->folder_path, $to, $detail, true);
			return true;
		}
		require_once __DIR__.'/inboxactions.class.php';
		require_once __DIR__.'/inboxactionlog.class.php';
		$before = InboxActionLog::$consumer;
		InboxActionLog::$consumer = array('code' => $this->sub['code'], 'label' => $this->sub['label'], 'simulated' => false);
		try {
			$res = (new InboxActions($this->db))->runAsConsumer($this->moduleUser(), $run, array((int) $this->mail->rowid), $runParams, (int) $this->account->id);
		} finally {
			InboxActionLog::$consumer = $before;
		}
		if (empty($res['ok'])) {
			$this->error = (string) ($res['error'] ?: 'Action failed');
			return false;
		}
		// Read / unread is not written by inbox for the users: written for a module
		if ($action === 'seen') {
			$this->log('consumer_seen', (string) $this->mail->folder_path, '', $detail);
		}
		// The mail where it is now (a move keeps its row when the server tells its new place)
		$row = $this->db->getRow("SELECT m.*, f.path AS folder_path, f.role AS folder_role FROM ".$p."inbox_mail as m INNER JOIN ".$p."inbox_folder as f ON f.rowid = m.fk_folder"
			." WHERE m.rowid = ".((int) ($res['ids'][(int) $this->mail->rowid] ?? $this->mail->rowid)));
		if (is_object($row)) {
			$this->mail = $row;
			$this->mailId = (int) $row->rowid;
			$this->folder = (string) $row->folder_path;
		}

		return true;
	}

	/**
	 * Prepare a reply to the sender in the Drafts folder of the mailbox: never sent, a person reads it and sends it.
	 * Needs « draft »
	 *
	 * @param string $html    Body (HTML, cleaned as a mail written in inbox: no script, no form)
	 * @param string $subject Subject, « Re: » and the subject of the mail when empty
	 * @return bool Done (or written in test mode)
	 */
	public function draft($html, $subject = '')
	{
		if (!$this->allowed('draft')) {
			return false;
		}
		$to = trim((string) $this->mail->from_email);
		if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
			$this->error = 'Sender without address';
			return false;
		}
		$folder = $this->db->getRow("SELECT rowid, path FROM ".$this->db->prefix()."inbox_folder WHERE fk_account = ".((int) $this->account->id)." AND role = 'drafts'"
			." AND status = 1 AND selectable = 1 ORDER BY LENGTH(path)");
		if (!is_object($folder)) {
			$this->error = 'No Drafts folder';
			return false;
		}
		if ($this->test) {
			$this->log('consumer_draft', (string) $this->mail->folder_path, (string) $folder->path, $to, true);
			return true;
		}
		require_once __DIR__.'/inboxmailbuilder.class.php';
		require_once __DIR__.'/connector/inboxconnectorfactory.class.php';
		$b = new InboxMailBuilder();
		$b->from = array('email' => (string) $this->account->email, 'name' => (string) $this->account->label);
		$b->to = array(array('email' => $to, 'name' => (string) $this->mail->from_name));
		$original = (string) $this->mail->subject;
		$b->subject = trim((string) $subject) !== '' ? (string) $subject : (preg_match('/^\s*re\s*:/i', $original) ? $original : 'Re: '.$original);
		$b->html = (string) $html;
		$messageId = (string) $this->mail->message_id;
		if ($messageId !== '') {
			$b->inReplyTo = $messageId;
			$refs = preg_split('/[\s,]+/', str_replace(array('<', '>'), ' ', (string) $this->mail->refs), -1, PREG_SPLIT_NO_EMPTY);
			$refs[] = $messageId;
			$b->references = array_values(array_unique($refs));
		}
		$built = $b->build();
		if ($built === null) {
			$this->error = 'Draft not built: '.$b->error;
			return false;
		}
		$error = '';
		$c = InboxConnectorFactory::forAccount($this->db, $this->account, $error);
		if (!$c) {
			$this->error = $error;
			return false;
		}
		$id = $c->importRaw((string) $folder->path, (string) $built['raw'], array('\\Draft', '\\Seen'));
		$c->disconnect();
		if ($id === null) {
			$this->error = 'Draft not saved: '.$c->getError();
			return false;
		}
		$this->log('consumer_draft', (string) $this->mail->folder_path, (string) $folder->path, $to);

		return true;
	}

	/**
	 * Link the mail to a record of Dolibarr (shown on the mail and in the tab Emails of the record). Needs « link »
	 *
	 * @param string $type Type of the record (element of Dolibarr: societe, ticket, commande...)
	 * @param int    $id   Record
	 * @return bool Done (or written in test mode)
	 */
	public function link($type, $id)
	{
		if (!$this->allowed('link')) {
			return false;
		}
		$type = substr(preg_replace('/[^a-z0-9_@]/i', '', (string) $type), 0, 64);
		if ($type === '' || (int) $id <= 0) {
			$this->error = 'Bad record';
			return false;
		}
		$detail = $type.' #'.((int) $id);
		if ($this->test) {
			$this->log('consumer_link', (string) $this->mail->folder_path, '', $detail, true);
			return true;
		}
		require_once __DIR__.'/inboxmailref.class.php';
		require_once __DIR__.'/inboxlink.class.php';
		$mailref = (new InboxMailRef($this->db))->fetchOrCreate($this->mail);
		if ($mailref <= 0) {
			$this->error = 'Mail without Message-ID';
			return false;
		}
		$links = new InboxLink($this->db);
		if ($links->link($mailref, $type, (int) $id, null, substr($this->sub['code'], 0, 32)) < 0) {
			$this->error = $links->error;
			return false;
		}
		$this->log('consumer_link', (string) $this->mail->folder_path, '', $detail);

		return true;
	}
}
