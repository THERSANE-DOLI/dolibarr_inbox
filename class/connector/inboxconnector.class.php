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
 * \file    inbox/class/connector/inboxconnector.class.php
 * \ingroup inbox
 * \brief   Base of the connectors: everything "not supported" until a connector does it
 */

require_once __DIR__.'/inboxconnectorinterface.class.php';
require_once dirname(__DIR__).'/inboxaccount.class.php';

/**
 * Base class of the connectors. A connector extends it and overrides what it supports, declared in
 * supportedCapabilities(); every other method answers "not supported" so that a connector of another module keeps
 * working when inbox adds a method to the interface.
 */
abstract class InboxConnector implements InboxConnectorInterface
{
	/** @var DoliDB */
	protected $db;

	/** @var InboxAccount */
	protected $account;

	/** @var string */
	protected $error = '';

	/** @var string Kind of the last error: auth (credentials refused), connect (server unreachable), certificate, '' */
	protected $errorCode = '';

	/** @var bool */
	protected $notFound = false;

	/**
	 * @param DoliDB       $db      Database handler
	 * @param InboxAccount $account Mailbox
	 */
	public function __construct(DoliDB $db, InboxAccount $account)
	{
		$this->db = $db;
		$this->account = $account;
	}

	/**
	 * Closes the connection when the object is released
	 */
	public function __destruct()
	{
		$this->disconnect();
	}

	/**
	 * @return string
	 */
	public static function getFamily()
	{
		return 'mail';
	}

	/**
	 * @return string[] Capabilities really supported by the connector (CAP_ constants)
	 */
	abstract protected function supportedCapabilities();

	/**
	 * @return array<string,bool>
	 */
	public function getCapabilities()
	{
		$all = array(self::CAP_READ, self::CAP_FOLDERS, self::CAP_LABELS, self::CAP_SPECIAL_USE, self::CAP_CHANGES, self::CAP_FLAGS,
			self::CAP_TAGS, self::CAP_MOVE, self::CAP_IMPORT, self::CAP_SEND, self::CAP_DRAFTS, self::CAP_FOLDERS_MANAGE,
			self::CAP_SERVER_SEARCH, self::CAP_IDLE_PUSH, self::CAP_QUOTA, self::CAP_RULES, self::CAP_VACATION, self::CAP_UNSUBSCRIBE);
		$supported = $this->supportedCapabilities();
		$out = array();
		foreach ($all as $cap) {
			$out[$cap] = in_array($cap, $supported, true);
		}

		return $out;
	}

	/**
	 * @param string $capability Capability
	 * @return bool
	 */
	public function can($capability)
	{
		return in_array($capability, $this->supportedCapabilities(), true);
	}

	/**
	 * @return string
	 */
	public function getError()
	{
		return $this->error;
	}

	/**
	 * @return string Kind of the last error (auth, connect, certificate) or ''
	 */
	public function getErrorCode()
	{
		return $this->errorCode;
	}

	/**
	 * @return bool
	 */
	public function notFound()
	{
		return $this->notFound;
	}

	/**
	 * @param string $what Method or capability
	 * @return null
	 */
	protected function unsupported($what)
	{
		global $langs;

		$langs->load('inbox@inbox');
		$this->error = $langs->transnoentities('InboxErrorNotSupported', $what);

		return null;
	}

	/**
	 * @param string              $folder  Folder
	 * @param string              $cursor  Cursor
	 * @param array<string,mixed> $options Options
	 * @return null
	 */
	public function getChanges($folder, $cursor, array $options = array())
	{
		return $this->unsupported('getChanges');
	}

	/**
	 * @param string $folder Folder
	 * @return null
	 */
	public function folderStatus($folder)
	{
		return $this->unsupported('folderStatus');
	}

	/**
	 * @param string   $folder    Folder
	 * @param string[] $remoteIds Identifiers
	 * @param string[] $add       Flags
	 * @param string[] $remove    Flags
	 * @return bool
	 */
	public function setFlags($folder, array $remoteIds, array $add, array $remove)
	{
		$this->unsupported('setFlags');
		return false;
	}

	/**
	 * @param string   $folder    Folder
	 * @param string[] $remoteIds Identifiers
	 * @param string[] $add       Keywords
	 * @param string[] $remove    Keywords
	 * @return bool
	 */
	public function setTags($folder, array $remoteIds, array $add, array $remove)
	{
		$this->unsupported('setTags');
		return false;
	}

	/**
	 * @param string   $folder    Folder
	 * @param string[] $remoteIds Identifiers
	 * @param string   $target    Target
	 * @return null
	 */
	public function move($folder, array $remoteIds, $target)
	{
		return $this->unsupported('move');
	}

	/**
	 * @param string   $folder Folder
	 * @param string   $raw    Source
	 * @param string[] $flags  Flags
	 * @param int|null $date   Date
	 * @return null
	 */
	public function importRaw($folder, $raw, array $flags = array(), $date = null)
	{
		return $this->unsupported('importRaw');
	}

	/**
	 * @param string   $folder    Folder
	 * @param string[] $remoteIds Identifiers
	 * @return bool
	 */
	public function deleteMails($folder, array $remoteIds)
	{
		$this->unsupported('deleteMails');
		return false;
	}

	/**
	 * @param string $path Folder
	 * @return bool
	 */
	public function createFolder($path)
	{
		$this->unsupported('createFolder');
		return false;
	}

	/**
	 * @param string $from Current path
	 * @param string $to   New path
	 * @return bool
	 */
	public function renameFolder($from, $to)
	{
		$this->unsupported('renameFolder');
		return false;
	}

	/**
	 * @param string $path Path
	 * @return bool
	 */
	public function deleteFolder($path)
	{
		$this->unsupported('deleteFolder');
		return false;
	}

	/**
	 * @param string $folder Path
	 * @return int|null
	 */
	public function markFolderSeen($folder)
	{
		return $this->unsupported('markFolderSeen');
	}

	/**
	 * @param string $folder Path
	 * @return int|null
	 */
	public function emptyFolder($folder)
	{
		return $this->unsupported('emptyFolder');
	}

	/**
	 * @param string $raw Source
	 * @return bool
	 */
	public function send($raw)
	{
		$this->unsupported('send');
		return false;
	}

	/**
	 * @param string      $raw      Source
	 * @param string|null $replaces Previous draft
	 * @return null
	 */
	public function saveDraft($raw, $replaces = null)
	{
		return $this->unsupported('saveDraft');
	}
}
