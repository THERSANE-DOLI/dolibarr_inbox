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
 * \file    inbox/class/inboxaccount.class.php
 * \ingroup inbox
 * \brief   A mailbox managed by inbox
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonobject.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/security.lib.php';
require_once __DIR__.'/inboxaccountlog.class.php';

/**
 * A mailbox managed by inbox (personal or shared), reached through a connector (imap for now).
 *
 * Secrets (IMAP and SMTP passwords) are stored encrypted with dolEncrypt(), only through the dedicated setters, and
 * never leave the server: toArrayForClient() is the only array given to the browser and it has no secret in it.
 * Managing a mailbox never gives the reading of its mails (that is InboxAccess, per mailbox).
 */
class InboxAccount extends CommonObject
{
	/** @var string */
	public $module = 'inbox';
	/** @var string */
	public $element = 'inbox_account';
	/** @var string */
	public $table_element = 'inbox_account';
	/** @var int */
	public $ismultientitymanaged = 1;
	/** @var int */
	public $isextrafieldmanaged = 0;
	/** @var string */
	public $picto = 'fa-inbox';

	const STATUS_DRAFT = 0;
	const STATUS_ACTIVE = 1;
	const STATUS_DISABLED = 9;

	const TYPE_PERSONAL = 'personal';
	const TYPE_SHARED = 'shared';

	const AUTH_LOGIN = 'login';
	const AUTH_MASTER = 'master';
	const AUTH_OAUTH = 'oauth';

	/** @var string[] Columns holding a secret: never in an output, a log or an error message */
	const SECRET_FIELDS = array('imap_password', 'smtp_password');

	/** @var string[] Keys of connector_config that look like a secret, dropped from toArrayForClient() */
	const SECRET_KEY_PATTERN = '/pass|secret|token|key/i';

	/**
	 * @var array<string,array<string,mixed>> Fields of the table (ModuleBuilder format)
	 */
	public $fields = array(
		'rowid' => array('type' => 'integer', 'label' => 'TechnicalID', 'enabled' => 1, 'position' => 1, 'notnull' => 1, 'visible' => 0, 'noteditable' => 1, 'index' => 1),
		'entity' => array('type' => 'integer', 'label' => 'Entity', 'enabled' => 1, 'position' => 5, 'notnull' => 1, 'default' => '1', 'visible' => 0, 'index' => 1),
		'ref' => array('type' => 'varchar(128)', 'label' => 'Ref', 'enabled' => 1, 'position' => 10, 'notnull' => 1, 'visible' => 0, 'showoncombobox' => 1),
		'email' => array('type' => 'varchar(255)', 'label' => 'Email', 'enabled' => 1, 'position' => 20, 'notnull' => 1, 'visible' => 1),
		'label' => array('type' => 'varchar(255)', 'label' => 'Label', 'enabled' => 1, 'position' => 30, 'notnull' => 0, 'visible' => 1),
		'type' => array('type' => 'varchar(16)', 'label' => 'Type', 'enabled' => 1, 'position' => 40, 'notnull' => 1, 'default' => 'shared', 'visible' => 1),
		'connector' => array('type' => 'varchar(32)', 'label' => 'InboxConnector', 'enabled' => 1, 'position' => 50, 'notnull' => 1, 'default' => 'imap', 'visible' => 0),
		'connector_config' => array('type' => 'text', 'label' => 'InboxConnectorConfig', 'enabled' => 1, 'position' => 55, 'notnull' => 0, 'visible' => 0),
		'fk_user_owner' => array('type' => 'integer:User:user/class/user.class.php', 'label' => 'InboxAccountOwner', 'enabled' => 1, 'position' => 60, 'notnull' => 0, 'visible' => 1),
		'imap_host' => array('type' => 'varchar(255)', 'label' => 'InboxImapHost', 'enabled' => 1, 'position' => 100, 'notnull' => 0, 'visible' => 0),
		'imap_port' => array('type' => 'integer', 'label' => 'InboxImapPort', 'enabled' => 1, 'position' => 101, 'notnull' => 0, 'default' => '993', 'visible' => 0),
		'imap_encryption' => array('type' => 'varchar(8)', 'label' => 'InboxImapEncryption', 'enabled' => 1, 'position' => 102, 'notnull' => 0, 'default' => 'ssl', 'visible' => 0),
		'imap_auth_mode' => array('type' => 'varchar(16)', 'label' => 'InboxImapAuthMode', 'enabled' => 1, 'position' => 103, 'notnull' => 1, 'default' => 'login', 'visible' => 0),
		'oauth_service' => array('type' => 'varchar(128)', 'label' => 'InboxOAuthService', 'enabled' => 1, 'position' => 104, 'notnull' => 0, 'visible' => 0),
		'imap_login' => array('type' => 'varchar(255)', 'label' => 'InboxImapLogin', 'enabled' => 1, 'position' => 105, 'notnull' => 0, 'visible' => 0),
		'imap_password' => array('type' => 'text', 'label' => 'Password', 'enabled' => 1, 'position' => 106, 'notnull' => 0, 'visible' => 0),
		'imap_validate_cert' => array('type' => 'integer', 'label' => 'InboxValidateCert', 'enabled' => 1, 'position' => 107, 'notnull' => 1, 'default' => '1', 'visible' => 0),
		'smtp_host' => array('type' => 'varchar(255)', 'label' => 'InboxSmtpHost', 'enabled' => 1, 'position' => 120, 'notnull' => 0, 'visible' => 0),
		'smtp_port' => array('type' => 'integer', 'label' => 'InboxSmtpPort', 'enabled' => 1, 'position' => 121, 'notnull' => 0, 'default' => '465', 'visible' => 0),
		'smtp_encryption' => array('type' => 'varchar(8)', 'label' => 'InboxSmtpEncryption', 'enabled' => 1, 'position' => 122, 'notnull' => 0, 'default' => 'ssl', 'visible' => 0),
		'smtp_same_credentials' => array('type' => 'integer', 'label' => 'InboxSmtpSameCredentials', 'enabled' => 1, 'position' => 123, 'notnull' => 1, 'default' => '1', 'visible' => 0),
		'smtp_login' => array('type' => 'varchar(255)', 'label' => 'InboxSmtpLogin', 'enabled' => 1, 'position' => 124, 'notnull' => 0, 'visible' => 0),
		'smtp_password' => array('type' => 'text', 'label' => 'Password', 'enabled' => 1, 'position' => 125, 'notnull' => 0, 'visible' => 0),
		'sieve_port' => array('type' => 'integer', 'label' => 'InboxSievePort', 'enabled' => 1, 'position' => 130, 'notnull' => 0, 'default' => '4190', 'visible' => 0),
		'server_info' => array('type' => 'text', 'label' => 'InboxServerInfo', 'enabled' => 1, 'position' => 140, 'notnull' => 0, 'visible' => 0),
		'folder_roles' => array('type' => 'text', 'label' => 'InboxFolderRoles', 'enabled' => 1, 'position' => 141, 'notnull' => 0, 'visible' => 0),
		'sync_max_days' => array('type' => 'integer', 'label' => 'InboxSyncMaxDays', 'enabled' => 1, 'position' => 150, 'notnull' => 0, 'default' => '0', 'visible' => 0),
		'sync_max_mails' => array('type' => 'integer', 'label' => 'InboxSyncMaxMails', 'enabled' => 1, 'position' => 151, 'notnull' => 0, 'default' => '0', 'visible' => 0),
		'tags_mode' => array('type' => 'varchar(8)', 'label' => 'InboxTagsMode', 'enabled' => 1, 'position' => 160, 'notnull' => 1, 'default' => 'global', 'visible' => 0),
		'status' => array('type' => 'integer', 'label' => 'Status', 'enabled' => 1, 'position' => 500, 'notnull' => 1, 'default' => '0', 'visible' => 1, 'arrayofkeyval' => array(0 => 'Draft', 1 => 'Enabled', 9 => 'Disabled')),
		'date_last_test' => array('type' => 'datetime', 'label' => 'InboxDateLastTest', 'enabled' => 1, 'position' => 510, 'notnull' => 0, 'visible' => 0),
		'last_test_result' => array('type' => 'varchar(16)', 'label' => 'InboxLastTestResult', 'enabled' => 1, 'position' => 511, 'notnull' => 0, 'visible' => 0),
		'last_error' => array('type' => 'text', 'label' => 'InboxLastError', 'enabled' => 1, 'position' => 512, 'notnull' => 0, 'visible' => 0),
		'date_creation' => array('type' => 'datetime', 'label' => 'DateCreation', 'enabled' => 1, 'position' => 900, 'notnull' => 1, 'visible' => 0),
		'tms' => array('type' => 'timestamp', 'label' => 'DateModification', 'enabled' => 1, 'position' => 901, 'notnull' => 0, 'visible' => 0),
		'fk_user_creat' => array('type' => 'integer:User:user/class/user.class.php', 'label' => 'UserAuthor', 'enabled' => 1, 'position' => 910, 'notnull' => 1, 'visible' => 0),
		'fk_user_modif' => array('type' => 'integer:User:user/class/user.class.php', 'label' => 'UserModif', 'enabled' => 1, 'position' => 911, 'notnull' => 0, 'visible' => 0),
		'import_key' => array('type' => 'varchar(14)', 'label' => 'ImportId', 'enabled' => 1, 'position' => 1000, 'notnull' => 0, 'visible' => 0),
	);

	/** @var int */
	public $rowid;
	/** @var string */
	public $ref;
	/** @var string Email address of the mailbox, lower case */
	public $email;
	/** @var string */
	public $label;
	/** @var string personal|shared */
	public $type = self::TYPE_SHARED;
	/** @var string */
	public $connector = 'imap';
	/** @var string|null JSON settings of other connectors, never a secret */
	public $connector_config;
	/** @var int|null Owner of a personal mailbox */
	public $fk_user_owner;
	/** @var string|null */
	public $imap_host;
	/** @var int */
	public $imap_port = 993;
	/** @var string ssl|tls|none */
	public $imap_encryption = 'ssl';
	/** @var string login|master|oauth */
	public $imap_auth_mode = self::AUTH_LOGIN;
	/** @var string|null Service of the OAuth module of the core */
	public $oauth_service;
	/** @var string|null */
	public $imap_login;
	/** @var string|null Encrypted, use setImapPassword() / getImapPassword() */
	public $imap_password;
	/** @var int */
	public $imap_validate_cert = 1;
	/** @var string|null */
	public $smtp_host;
	/** @var int */
	public $smtp_port = 465;
	/** @var string ssl|tls|none */
	public $smtp_encryption = 'ssl';
	/** @var int 1 = same login and password as IMAP */
	public $smtp_same_credentials = 1;
	/** @var string|null */
	public $smtp_login;
	/** @var string|null Encrypted, use setSmtpPassword() / getSmtpPassword() */
	public $smtp_password;
	/** @var int 0 = no server filters */
	public $sieve_port = 4190;
	/** @var string|null JSON, filled by the connection test */
	public $server_info;
	/** @var string|null JSON, role => real folder */
	public $folder_roles;
	/** @var int Optional cap of the indexing, days (0 = everything) */
	public $sync_max_days = 0;
	/** @var int Optional cap of the indexing, mails (0 = everything) */
	public $sync_max_mails = 0;
	/** @var string global|own */
	public $tags_mode = 'global';
	/** @var int */
	public $status = self::STATUS_DRAFT;
	/** @var int|string|null */
	public $date_last_test;
	/** @var string|null */
	public $last_test_result;
	/** @var string|null */
	public $last_error;
	/** @var string|null */
	public $sync_state;
	/** @var string|null */
	public $sync_error;
	/** @var int|string|null */
	public $date_sync_error;
	/** @var int|string|null Last pass of the synchronisation that ended */
	public $date_sync;
	/** @var int|string|null Last reading of the folders (LIST) */
	public $date_folders;
	/** @var int|string|null Lock of a pass in progress */
	public $sync_lock;
	/** @var int|string|null After an error: no new try before */
	public $sync_retry_at;
	/** @var int Errors in a row */
	public $sync_fail_count = 0;
	/** @var int|string */
	public $date_creation;
	/** @var int */
	public $fk_user_creat;
	/** @var int|null */
	public $fk_user_modif;
	/** @var string|null */
	public $import_key;
	/** @var string Level of the current user on this mailbox, set by InboxAccess::getAccounts() */
	public $access_level = '';

	/** @var string[] State of the synchronisation: written only by InboxSync (never by create / update, so that saving
	 * the card during a pass does not write old values over it), loaded by fetch() */
	const SYNC_FIELDS = array('sync_state', 'sync_error', 'date_sync_error', 'date_sync', 'date_folders', 'sync_lock', 'sync_retry_at', 'sync_fail_count');

	/** @var string[] Columns of the server: a change is written in the journal (values, never a password) */
	const SERVER_FIELDS = array('imap_host', 'imap_port', 'imap_encryption', 'imap_auth_mode', 'oauth_service', 'imap_login', 'imap_validate_cert',
		'smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_same_credentials', 'smtp_login', 'sieve_port');

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct(DoliDB $db)
	{
		$this->db = $db;
	}

	/**
	 * Create the mailbox in the database (trigger INBOXACCOUNT_CREATE)
	 *
	 * @param User $user      User creating
	 * @param int  $notrigger 1 = no trigger
	 * @return int            Id if OK, < 0 if KO
	 */
	public function create(User $user, $notrigger = 0)
	{
		$this->normalize();
		if (!$this->check()) {
			return -1;
		}
		if ($this->emailExists($this->email)) {
			$this->errors[] = 'InboxErrorAccountExists';
			$this->error = 'InboxErrorAccountExists';
			return -2;
		}
		if ($this->status === null || $this->status === '') {
			$this->status = self::STATUS_DRAFT;
		}

		$result = $this->createCommon($user, $notrigger);
		if ($result > 0) {
			$log = new InboxAccountLog($this->db);
			$log->add($result, $user, InboxAccountLog::ACTION_CREATE, null, array('email' => $this->email, 'type' => $this->type));
		}

		return $result;
	}

	/**
	 * Load a mailbox
	 *
	 * @param int         $id  Id
	 * @param string|null $ref Ref (= email address)
	 * @return int             > 0 if OK, 0 if not found, < 0 if KO
	 */
	public function fetch($id, $ref = null)
	{
		global $conf;

		if ($ref !== null) {
			$ref = strtolower(trim((string) $ref));
		}

		// Always the current entity, also by id: a mailbox of another entity does not exist here
		$result = $this->fetchCommon($id, $ref, " AND t.entity = ".((int) $conf->entity));
		if ($result > 0) {
			$this->loadSyncState();
		}

		return $result;
	}

	/**
	 * Load the state of the synchronisation (columns out of $fields, see SYNC_FIELDS)
	 *
	 * @return void
	 */
	public function loadSyncState()
	{
		$row = $this->db->getRow("SELECT ".implode(', ', self::SYNC_FIELDS)." FROM ".$this->db->prefix().$this->table_element." WHERE rowid = ".((int) $this->id));
		if (!is_object($row)) {
			return;
		}
		foreach (self::SYNC_FIELDS as $field) {
			$value = $row->$field;
			$this->$field = (strpos($field, 'date_') === 0 || in_array($field, array('sync_lock', 'sync_retry_at'), true)) ? ($value ? (int) $this->db->jdate($value) : null) : $value;
		}
		$this->sync_fail_count = (int) $this->sync_fail_count;
	}

	/**
	 * Load the mailbox of an email address, in the current entity
	 *
	 * @param string $email Email address of the mailbox
	 * @return int          > 0 if OK, 0 if not found, < 0 if KO
	 */
	public function fetchByEmail($email)
	{
		$email = strtolower(trim((string) $email));
		if (!isValidEmail($email)) {
			return 0;
		}

		return $this->fetch(0, $email);
	}

	/**
	 * Update the mailbox in the database (trigger INBOXACCOUNT_MODIFY)
	 *
	 * @param User $user      User modifying
	 * @param int  $notrigger 1 = no trigger
	 * @return int            > 0 if OK, < 0 if KO
	 */
	public function update(User $user, $notrigger = 0)
	{
		$this->normalize();
		if (!$this->check()) {
			return -1;
		}
		if ($this->emailExists($this->email, (int) $this->id)) {
			$this->errors[] = 'InboxErrorAccountExists';
			$this->error = 'InboxErrorAccountExists';
			return -2;
		}

		$old = $this->db->getRow("SELECT * FROM ".$this->db->prefix().$this->table_element." WHERE rowid = ".((int) $this->id));
		$result = $this->updateCommon($user, $notrigger);
		if ($result > 0 && is_object($old)) {
			$this->logChanges($old, $user);
		}

		return $result;
	}

	/**
	 * Write in the journal what changed on the server, the passwords (only the fact) and the status
	 *
	 * @param stdClass $old  Row before the update
	 * @param User     $user Author
	 * @return void
	 */
	protected function logChanges($old, $user)
	{
		$log = new InboxAccountLog($this->db);
		$before = array();
		$after = array();
		foreach (self::SERVER_FIELDS as $field) {
			if ((string) $old->$field !== (string) $this->$field) {
				$before[$field] = $old->$field;
				$after[$field] = $this->$field;
			}
		}
		if ($after) {
			$log->add($this->id, $user, InboxAccountLog::ACTION_SERVER, $before, $after);
		}
		// Encrypted values: a new password gives a new cipher text, an untouched one stays the same string
		$changed = array();
		foreach (self::SECRET_FIELDS as $field) {
			if ((string) $old->$field !== (string) $this->$field) {
				$changed[] = $field === 'imap_password' ? 'imap' : 'smtp';
			}
		}
		if ($changed) {
			$log->add($this->id, $user, InboxAccountLog::ACTION_PASSWORD, null, array('changed' => $changed));
		}
		if ((int) $old->status !== (int) $this->status) {
			$log->add($this->id, $user, InboxAccountLog::ACTION_STATUS, array('status' => (int) $old->status), array('status' => (int) $this->status));
		}
	}

	/**
	 * Delete the mailbox and what belongs to it (identities, accesses, own tags, settings of the users). Only a
	 * disabled mailbox can be deleted. Nothing is removed on the mail server. The journal of its changes is kept for
	 * its retention (CA-28). Trigger INBOXACCOUNT_DELETE.
	 *
	 * @param User $user      User deleting
	 * @param int  $notrigger 1 = no trigger
	 * @return int            > 0 if OK, < 0 if KO
	 */
	public function delete(User $user, $notrigger = 0)
	{
		if ((int) $this->status !== self::STATUS_DISABLED) {
			$this->errors[] = 'InboxErrorDeleteNotDisabled';
			$this->error = 'InboxErrorDeleteNotDisabled';
			return -1;
		}

		$this->db->begin();
		// Index of the mailbox (rebuilt from the server at any time) with it
		$this->db->query("DELETE FROM ".$this->db->prefix()."inbox_mail_address WHERE fk_mail IN (SELECT rowid FROM ".$this->db->prefix()."inbox_mail WHERE fk_account = ".((int) $this->id).")");
		foreach (array('inbox_mail', 'inbox_folder', 'inbox_identity', 'inbox_account_access', 'inbox_tag', 'inbox_userparam') as $table) {
			if (!$this->db->query("DELETE FROM ".$this->db->prefix().$table." WHERE fk_account = ".((int) $this->id))) {
				$this->error = $this->db->lasterror();
				$this->db->rollback();
				return -2;
			}
		}
		$result = $this->deleteCommon($user, $notrigger);
		if ($result < 0) {
			$this->db->rollback();
			return $result;
		}
		$this->db->commit();

		return 1;
	}

	/**
	 * Lower case email, ref = email, default label, ports and modes of the connector
	 *
	 * @return void
	 */
	protected function normalize()
	{
		$this->email = strtolower(trim((string) $this->email));
		$this->ref = $this->email;
		$this->label = trim((string) $this->label);
		if ($this->label === '') {
			$this->label = $this->email;
		}
		$this->imap_host = trim((string) $this->imap_host);
		$this->smtp_host = trim((string) $this->smtp_host);
		$this->imap_login = trim((string) $this->imap_login);
		$this->smtp_login = trim((string) $this->smtp_login);
		$this->imap_encryption = strtolower(trim((string) $this->imap_encryption));
		$this->smtp_encryption = strtolower(trim((string) $this->smtp_encryption));
		$this->smtp_same_credentials = empty($this->smtp_same_credentials) ? 0 : 1;
		$this->imap_validate_cert = empty($this->imap_validate_cert) ? 0 : 1;
		if ($this->type !== self::TYPE_PERSONAL) {
			$this->fk_user_owner = null;
		}
		if ($this->smtp_same_credentials) {
			$this->smtp_login = '';
			$this->smtp_password = null;
		}
		if ($this->imap_auth_mode !== self::AUTH_OAUTH) {
			$this->oauth_service = null;
		}
	}

	/**
	 * Check the values before writing them; errors are language keys in $this->errors
	 *
	 * @return bool
	 */
	public function check()
	{
		$this->errors = array();
		if (!isValidEmail($this->email)) {
			$this->errors[] = 'InboxErrorAccountEmail';
		}
		if (!in_array($this->type, array(self::TYPE_PERSONAL, self::TYPE_SHARED), true)) {
			$this->errors[] = 'InboxErrorAccountType';
		}
		if ($this->type === self::TYPE_PERSONAL && (int) $this->fk_user_owner <= 0) {
			$this->errors[] = 'InboxErrorAccountOwner';
		}
		if (!preg_match('/^[a-z0-9_]{1,32}$/', (string) $this->connector)) {
			$this->errors[] = 'InboxErrorAccountConnector';
		}
		if ($this->connector === 'imap') {
			if (!in_array($this->imap_auth_mode, array(self::AUTH_LOGIN, self::AUTH_MASTER, self::AUTH_OAUTH), true)) {
				$this->errors[] = 'InboxErrorAccountAuthMode';
			}
			if ($this->imap_auth_mode === self::AUTH_OAUTH && trim((string) $this->oauth_service) === '') {
				$this->errors[] = 'InboxErrorAccountOAuthService';
			}
			foreach (array('imap' => $this->imap_host, 'smtp' => $this->smtp_host) as $kind => $host) {
				if ($host !== '' && !self::isValidHost($host)) {
					$this->errors[] = 'InboxErrorAccountHost';
				}
				$encryption = $kind === 'imap' ? $this->imap_encryption : $this->smtp_encryption;
				if (!in_array($encryption, array('ssl', 'tls', 'none'), true)) {
					$this->errors[] = 'InboxErrorAccountEncryption';
				} elseif ($encryption === 'none' && $host !== '' && !self::isLocalHost($host)) {
					// Password in clear on the network: only for a server on the same machine
					$this->errors[] = 'InboxErrorAccountNoEncryption';
				}
			}
			foreach (array($this->imap_port, $this->smtp_port) as $port) {
				if ((int) $port < 1 || (int) $port > 65535) {
					$this->errors[] = 'InboxErrorAccountPort';
				}
			}
			if ((int) $this->sieve_port < 0 || (int) $this->sieve_port > 65535) {
				$this->errors[] = 'InboxErrorAccountPort';
			}
		}
		if (!in_array($this->tags_mode, array('global', 'own'), true)) {
			$this->errors[] = 'InboxErrorAccountTagsMode';
		}
		if (!in_array((int) $this->status, array(self::STATUS_DRAFT, self::STATUS_ACTIVE, self::STATUS_DISABLED), true)) {
			$this->errors[] = 'InboxErrorAccountStatus';
		}
		$this->errors = array_values(array_unique($this->errors));
		$this->error = implode(', ', $this->errors);

		return empty($this->errors);
	}

	/**
	 * @param string $email   Email address
	 * @param int    $exclude Id of the mailbox to ignore (update)
	 * @return bool           Another mailbox of the entity has this address
	 */
	protected function emailExists($email, $exclude = 0)
	{
		global $conf;

		$obj = $this->db->getRow("SELECT rowid FROM ".$this->db->prefix().$this->table_element
			." WHERE entity = ".((int) $conf->entity)." AND ref = '".$this->db->escape($email)."'"
			.($exclude > 0 ? " AND rowid <> ".((int) $exclude) : ''));

		return is_object($obj);
	}

	/**
	 * @param string $host Host name or IP
	 * @return bool
	 */
	public static function isValidHost($host)
	{
		$host = trim((string) $host, '[]');
		if (filter_var($host, FILTER_VALIDATE_IP)) {
			return true;
		}

		return (bool) preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/i', $host);
	}

	/**
	 * @param string $host Host name or IP
	 * @return bool        Server on the same machine (the only case where no encryption is allowed)
	 */
	public static function isLocalHost($host)
	{
		$host = strtolower(trim((string) $host, '[] '));

		return in_array($host, array('localhost', '127.0.0.1', '::1'), true);
	}

	/**
	 * Set the IMAP password (encrypted). Written by the next create() / update().
	 *
	 * @param string $plain Password in clear, '' to remove it
	 * @return void
	 */
	public function setImapPassword($plain)
	{
		$this->imap_password = ((string) $plain === '') ? null : dolEncrypt((string) $plain);
	}

	/**
	 * @return string IMAP password in clear, '' if none. Only for the connector, never for an output.
	 */
	public function getImapPassword()
	{
		return empty($this->imap_password) ? '' : (string) dolDecrypt((string) $this->imap_password);
	}

	/**
	 * Set the SMTP password (encrypted), used only when smtp_same_credentials = 0
	 *
	 * @param string $plain Password in clear, '' to remove it
	 * @return void
	 */
	public function setSmtpPassword($plain)
	{
		$this->smtp_password = ((string) $plain === '') ? null : dolEncrypt((string) $plain);
	}

	/**
	 * @return string SMTP password in clear: the IMAP one when the credentials are the same
	 */
	public function getSmtpPassword()
	{
		if (!empty($this->smtp_same_credentials)) {
			return $this->getImapPassword();
		}

		return empty($this->smtp_password) ? '' : (string) dolDecrypt((string) $this->smtp_password);
	}

	/**
	 * @return string IMAP login, the email address by default
	 */
	public function getImapLogin()
	{
		$login = trim((string) $this->imap_login);

		return $login === '' ? (string) $this->email : $login;
	}

	/**
	 * @return string SMTP login: the IMAP one when the credentials are the same
	 */
	public function getSmtpLogin()
	{
		if (!empty($this->smtp_same_credentials)) {
			return $this->getImapLogin();
		}
		$login = trim((string) $this->smtp_login);

		return $login === '' ? (string) $this->email : $login;
	}

	/**
	 * @return string SMTP host, the IMAP host by default
	 */
	public function getSmtpHost()
	{
		$host = trim((string) $this->smtp_host);

		return $host === '' ? (string) $this->imap_host : $host;
	}

	/**
	 * @return bool The mailbox can be read (enabled): a disabled or draft mailbox gives no access to anyone
	 */
	public function isActive()
	{
		return (int) $this->status === self::STATUS_ACTIVE;
	}

	/**
	 * @return array<string,mixed> Server information of the last test
	 */
	public function getServerInfo()
	{
		return self::decodeJson($this->server_info);
	}

	/**
	 * @param array<string,mixed> $info Server information
	 * @return void
	 */
	public function setServerInfo(array $info)
	{
		$this->server_info = json_encode($info);
	}

	/**
	 * @return array<string,string> Role (sent, drafts, trash, junk, archive) => real folder
	 */
	public function getFolderRoles()
	{
		$roles = array();
		foreach (self::decodeJson($this->folder_roles) as $role => $folder) {
			if (is_string($folder) && $folder !== '') {
				$roles[(string) $role] = $folder;
			}
		}

		return $roles;
	}

	/**
	 * @param array<string,string> $roles Role => real folder
	 * @return void
	 */
	public function setFolderRoles(array $roles)
	{
		$this->folder_roles = json_encode($roles);
	}

	/**
	 * @return array<string,mixed> Settings of the connector
	 */
	public function getConnectorConfig()
	{
		return self::decodeJson($this->connector_config);
	}

	/**
	 * @param array<string,mixed> $config Settings of the connector, never a secret (it is rejected)
	 * @return bool                       False if a key looks like a secret
	 */
	public function setConnectorConfig(array $config)
	{
		foreach (array_keys($config) as $key) {
			if (preg_match(self::SECRET_KEY_PATTERN, (string) $key)) {
				$this->error = 'InboxErrorSecretInConfig';
				return false;
			}
		}
		$this->connector_config = json_encode($config);

		return true;
	}

	/**
	 * @param string|null $json JSON text
	 * @return array<mixed>
	 */
	protected static function decodeJson($json)
	{
		if ($json === null || $json === '') {
			return array();
		}
		$data = json_decode((string) $json, true);

		return is_array($data) ? $data : array();
	}

	/**
	 * The only representation of the mailbox given to the browser: no password, no encrypted value, no key of the
	 * connector settings that looks like a secret. The presence of a password is given as a boolean.
	 *
	 * @return array<string,mixed>
	 */
	public function toArrayForClient()
	{
		$out = array(
			'id' => (int) $this->id,
			'email' => (string) $this->email,
			'label' => (string) $this->label,
			'type' => (string) $this->type,
			'connector' => (string) $this->connector,
			'fk_user_owner' => $this->fk_user_owner ? (int) $this->fk_user_owner : null,
			'imap_host' => (string) $this->imap_host,
			'imap_port' => (int) $this->imap_port,
			'imap_encryption' => (string) $this->imap_encryption,
			'imap_auth_mode' => (string) $this->imap_auth_mode,
			'oauth_service' => (string) $this->oauth_service,
			'imap_login' => (string) $this->imap_login,
			'imap_validate_cert' => (int) $this->imap_validate_cert,
			'has_imap_password' => !empty($this->imap_password),
			'smtp_host' => (string) $this->smtp_host,
			'smtp_port' => (int) $this->smtp_port,
			'smtp_encryption' => (string) $this->smtp_encryption,
			'smtp_same_credentials' => (int) $this->smtp_same_credentials,
			'smtp_login' => (string) $this->smtp_login,
			'has_smtp_password' => !empty($this->smtp_password),
			'sieve_port' => (int) $this->sieve_port,
			'server_info' => $this->getServerInfo(),
			'folder_roles' => $this->getFolderRoles(),
			'sync_max_days' => (int) $this->sync_max_days,
			'sync_max_mails' => (int) $this->sync_max_mails,
			'tags_mode' => (string) $this->tags_mode,
			'status' => (int) $this->status,
			'date_last_test' => $this->date_last_test ? (int) $this->date_last_test : null,
			'last_test_result' => (string) $this->last_test_result,
			'last_error' => (string) $this->last_error,
			'sync_state' => (string) $this->sync_state,
			'sync_error' => (string) $this->sync_error,
			'date_sync_error' => $this->date_sync_error ? (int) $this->date_sync_error : null,
			'date_sync' => $this->date_sync ? (int) $this->date_sync : null,
			'sync_retry_at' => $this->sync_retry_at ? (int) $this->sync_retry_at : null,
		);
		$config = array();
		foreach ($this->getConnectorConfig() as $key => $value) {
			if (!preg_match(self::SECRET_KEY_PATTERN, (string) $key)) {
				$config[$key] = $value;
			}
		}
		$out['connector_config'] = $config;

		return $out;
	}

	/**
	 * @param int $mode 0 = long label, 1 = short label, 2 = picto + short label...
	 * @return string   Label of the status
	 */
	public function getLibStatut($mode = 0)
	{
		return $this->LibStatut((int) $this->status, $mode);
	}

	/**
	 * @param int $status Status
	 * @param int $mode   Mode of dolGetStatus()
	 * @return string
	 */
	public function LibStatut($status, $mode = 0)
	{
		global $langs;

		$langs->load('inbox@inbox');
		$labels = array(
			self::STATUS_DRAFT => array('InboxAccountStatusDraft', 'status0'),
			self::STATUS_ACTIVE => array('InboxAccountStatusActive', 'status4'),
			self::STATUS_DISABLED => array('InboxAccountStatusDisabled', 'status9'),
		);
		$info = isset($labels[$status]) ? $labels[$status] : $labels[self::STATUS_DRAFT];

		return dolGetStatus($langs->transnoentitiesnoconv($info[0]), '', '', $info[1], $mode);
	}
}
