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
 * \file    inbox/class/connector/inboxconnectorinterface.class.php
 * \ingroup inbox
 * \brief   Common interface of the connectors of the mail family
 */

/**
 * Everything inbox does with a mailbox goes through this interface (analyse-webmail §5.0): IMAP is one connector
 * among others (Microsoft Graph, Gmail later, in other modules through the hook inboxConnectors).
 *
 * - Identifiers are opaque texts: $remoteId (IMAP: "uidvalidity:uid") and $cursor of a folder. Nothing else in inbox
 *   reads inside them.
 * - MIME is the pivot format: fetchRaw() gives the RFC 822 source, importRaw() / send() take one.
 * - What a connector cannot do is declared by getCapabilities(): the interface hides the action instead of offering
 *   one that fails. A method of a capability not declared returns null / false with the error "not supported".
 * - Read only by default: no write on the server without an action of the user; every write is checked after.
 * - Never a password in an error, a log or a return value.
 */
interface InboxConnectorInterface
{
	/** Capabilities, keys of getCapabilities() */
	const CAP_READ = 'read';
	const CAP_FOLDERS = 'folders';
	const CAP_LABELS = 'labels';
	const CAP_SPECIAL_USE = 'special_use';
	const CAP_CHANGES = 'changes';
	const CAP_FLAGS = 'flags';
	const CAP_TAGS = 'tags';
	const CAP_MOVE = 'move';
	const CAP_IMPORT = 'import';
	const CAP_SEND = 'send';
	const CAP_DRAFTS = 'drafts';
	const CAP_FOLDERS_MANAGE = 'folders_manage';
	const CAP_SERVER_SEARCH = 'server_search';
	const CAP_IDLE_PUSH = 'idle_push';
	const CAP_QUOTA = 'quota';
	const CAP_RULES = 'rules';
	const CAP_VACATION = 'vacation';
	const CAP_UNSUBSCRIBE = 'unsubscribe';

	/**
	 * @return string Code of the connector, value of llx_inbox_account.connector (ex: imap)
	 */
	public static function getCode();

	/**
	 * @return string Family: mail (messaging later, with its own interface)
	 */
	public static function getFamily();

	/**
	 * @return array<string,bool> Capability => supported. Every CAP_ constant is given, false when not supported.
	 */
	public function getCapabilities();

	/**
	 * @param string $capability One of the CAP_ constants
	 * @return bool
	 */
	public function can($capability);

	/**
	 * @return string Last error, plain text, for the user (never a password)
	 */
	public function getError();

	/**
	 * Open the connection (called by the other methods when needed)
	 *
	 * @return bool
	 */
	public function connect();

	/**
	 * Close the connection; safe to call several times
	 *
	 * @return void
	 */
	public function disconnect();

	/**
	 * Login and what the server says of itself
	 *
	 * @return array{ok:bool,duration:float,info:array<string,mixed>}|null Null on error
	 */
	public function testConnection();

	/**
	 * Folders (or labels) of the mailbox
	 *
	 * @return array<int,array{path:string,name:string,delimiter:string,selectable:bool,role:string,flags:string[]}>|null
	 */
	public function listFolders();

	/**
	 * Changes of a folder since a cursor (lot W2)
	 *
	 * @param string $folder Folder path
	 * @param string $cursor Cursor given by the previous call, '' the first time
	 * @return array{cursor:string,reset:bool,new:array<int,array<string,mixed>>,changed:array<int,array<string,mixed>>,deleted:string[]}|null
	 */
	public function getChanges($folder, $cursor);

	/**
	 * RFC 822 source of a mail, read only (the mail stays unread)
	 *
	 * @param string $folder   Folder path
	 * @param string $remoteId Opaque identifier
	 * @return string|null      Null if not found or on error
	 */
	public function fetchRaw($folder, $remoteId);

	/**
	 * Find a mail by its complete Message-ID (lot 0), read only
	 *
	 * @param string        $messageId Message-ID without angle brackets, already checked by Inbox::normalizeMessageId()
	 * @param string[]|null $folders   Folders to search, null = all, in the most likely order
	 * @return array{raw:string,folder:string,remote_id:string}|null Null if not found (notFound()) or on error
	 */
	public function findByMessageId($messageId, $folders = null);

	/**
	 * @return bool The last findByMessageId() searched everything and found nothing (not an error)
	 */
	public function notFound();

	/**
	 * Set or remove flags (lot W4)
	 *
	 * @param string   $folder    Folder path
	 * @param string[] $remoteIds Identifiers
	 * @param string[] $add       Flags to add (seen, answered, flagged)
	 * @param string[] $remove    Flags to remove
	 * @return bool
	 */
	public function setFlags($folder, array $remoteIds, array $add, array $remove);

	/**
	 * Set or remove tags (IMAP keywords, Outlook categories, Gmail labels) (lot W4)
	 *
	 * @param string   $folder    Folder path
	 * @param string[] $remoteIds Identifiers
	 * @param string[] $add       Keywords to add
	 * @param string[] $remove    Keywords to remove
	 * @return bool
	 */
	public function setTags($folder, array $remoteIds, array $add, array $remove);

	/**
	 * Move mails inside the mailbox, checked after (lot W4)
	 *
	 * @param string   $folder    Folder path
	 * @param string[] $remoteIds Identifiers
	 * @param string   $target    Target folder path
	 * @return array<string,string>|null Old identifier => new identifier, null on error
	 */
	public function move($folder, array $remoteIds, $target);

	/**
	 * Put a mail in a folder from its source (move between mailboxes, lot W4)
	 *
	 * @param string   $folder Folder path
	 * @param string   $raw    RFC 822 source
	 * @param string[] $flags  Flags and keywords
	 * @param int|null $date   Internal date (reception date of the original)
	 * @return string|null      New identifier, null on error
	 */
	public function importRaw($folder, $raw, array $flags = array(), $date = null);

	/**
	 * Send a mail and keep its copy in the sent folder (lot W6)
	 *
	 * @param string $raw RFC 822 source
	 * @return bool
	 */
	public function send($raw);

	/**
	 * Save a draft (lot W6)
	 *
	 * @param string      $raw      RFC 822 source
	 * @param string|null $replaces Identifier of the previous version of the draft, removed after the save
	 * @return string|null           Identifier of the draft
	 */
	public function saveDraft($raw, $replaces = null);
}
