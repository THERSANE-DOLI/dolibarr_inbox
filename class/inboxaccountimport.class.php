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
 * \file    inbox/class/inboxaccountimport.class.php
 * \ingroup inbox
 * \brief   Import of mailboxes declared by other modules (hook inboxAccountImportSources)
 */

require_once __DIR__.'/inboxaccount.class.php';

/**
 * inbox knows no source of mailboxes itself: a module declares one with the hook inboxAccountImportSources (context
 * "inbox"): its method puts in $this->results, and returns 0 (several modules can declare sources):
 *   code => array('label' => 'Shown name', 'accounts' => array(array(
 *       'email', 'label', 'type' (shared|personal), 'fk_user_owner', 'connector' (imap), 'imap_host', 'imap_port',
 *       'imap_encryption', 'imap_auth_mode' (login|master|oauth), 'oauth_service', 'imap_login', 'imap_password'
 *       (in clear, encrypted again by inbox), 'smtp_host', 'smtp_port', 'smtp_encryption', 'sieve_port'), ...))
 * The mailboxes are created as drafts (to be tested), without any access (given afterwards, nothing is opened
 * automatically). The data of the source are not modified. Importing again creates no duplicate.
 */
class InboxAccountImport
{
	/** @var DoliDB */
	protected $db;

	/** @var string */
	public $error = '';

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct(DoliDB $db)
	{
		$this->db = $db;
	}

	/**
	 * @return array<string,array{label:string,accounts:array<int,array<string,mixed>>}> Sources declared by the modules
	 */
	public function getSources()
	{
		global $hookmanager;

		if (!is_object($hookmanager)) {
			include_once DOL_DOCUMENT_ROOT.'/core/class/hookmanager.class.php';
			$hookmanager = new HookManager($this->db);
		}
		$hookmanager->initHooks(array('inbox'));
		$parameters = array();
		$action = '';
		$object = null;
		$reshook = $hookmanager->executeHooks('inboxAccountImportSources', $parameters, $object, $action);
		$out = array();
		if ($reshook < 0 || !is_array($hookmanager->resArray)) {
			return $out;
		}
		foreach ($hookmanager->resArray as $code => $source) {
			if (!preg_match('/^[a-z0-9_]{1,32}$/', (string) $code) || !is_array($source) || !isset($source['accounts']) || !is_array($source['accounts'])) {
				continue;
			}
			$out[$code] = array('label' => isset($source['label']) ? (string) $source['label'] : (string) $code, 'accounts' => array_values($source['accounts']));
		}

		return $out;
	}

	/**
	 * Mailboxes of a source that are not in inbox yet (compared by address), without their secrets
	 *
	 * @param string $code Source
	 * @return array<int,array<string,mixed>>|null Null if the source is unknown
	 */
	public function listNew($code)
	{
		$sources = $this->getSources();
		if (!isset($sources[$code])) {
			return null;
		}
		$out = array();
		foreach ($sources[$code]['accounts'] as $row) {
			$email = strtolower(trim((string) (isset($row['email']) ? $row['email'] : '')));
			if (!isValidEmail($email)) {
				continue;
			}
			$existing = new InboxAccount($this->db);
			if ($existing->fetchByEmail($email) > 0) {
				continue;
			}
			$out[] = array(
				'email' => $email,
				'label' => isset($row['label']) ? (string) $row['label'] : '',
				'type' => (isset($row['type']) && $row['type'] === InboxAccount::TYPE_PERSONAL && !empty($row['fk_user_owner'])) ? InboxAccount::TYPE_PERSONAL : InboxAccount::TYPE_SHARED,
				'fk_user_owner' => !empty($row['fk_user_owner']) ? (int) $row['fk_user_owner'] : null,
				'imap_host' => isset($row['imap_host']) ? (string) $row['imap_host'] : '',
				'imap_auth_mode' => isset($row['imap_auth_mode']) ? (string) $row['imap_auth_mode'] : InboxAccount::AUTH_LOGIN,
				'has_password' => !empty($row['imap_password']),
			);
		}

		return $out;
	}

	/**
	 * Create the chosen mailboxes of a source, as drafts, without access
	 *
	 * @param string   $code   Source
	 * @param string[] $emails Addresses chosen
	 * @param User     $user   User importing
	 * @return array{created:array<int,int>,skipped:string[],errors:array<string,string>}|null Email => id for the created ones
	 */
	public function import($code, array $emails, User $user)
	{
		$sources = $this->getSources();
		if (!isset($sources[$code])) {
			$this->error = 'Unknown source';
			return null;
		}
		$wanted = array_flip(array_map(function ($e) {
			return strtolower(trim((string) $e));
		}, $emails));
		$report = array('created' => array(), 'skipped' => array(), 'errors' => array());
		foreach ($sources[$code]['accounts'] as $row) {
			$email = strtolower(trim((string) (isset($row['email']) ? $row['email'] : '')));
			if (!isset($wanted[$email])) {
				continue;
			}
			$existing = new InboxAccount($this->db);
			if ($existing->fetchByEmail($email) > 0) {
				$report['skipped'][] = $email;
				continue;
			}
			$account = new InboxAccount($this->db);
			$account->email = $email;
			$account->label = isset($row['label']) ? (string) $row['label'] : '';
			$account->type = (isset($row['type']) && $row['type'] === InboxAccount::TYPE_PERSONAL && !empty($row['fk_user_owner'])) ? InboxAccount::TYPE_PERSONAL : InboxAccount::TYPE_SHARED;
			$account->fk_user_owner = $account->type === InboxAccount::TYPE_PERSONAL ? (int) $row['fk_user_owner'] : null;
			$account->connector = isset($row['connector']) ? (string) $row['connector'] : 'imap';
			foreach (array('imap_host', 'imap_encryption', 'imap_auth_mode', 'oauth_service', 'imap_login', 'smtp_host', 'smtp_encryption') as $field) {
				if (isset($row[$field]) && $row[$field] !== '') {
					$account->$field = (string) $row[$field];
				}
			}
			foreach (array('imap_port', 'smtp_port', 'sieve_port') as $field) {
				if (isset($row[$field]) && (int) $row[$field] > 0) {
					$account->$field = (int) $row[$field];
				}
			}
			if (!empty($row['imap_password'])) {
				$account->setImapPassword((string) $row['imap_password']);
			}
			$account->status = InboxAccount::STATUS_DRAFT;
			$account->import_key = substr('src_'.$code, 0, 14);
			$id = $account->create($user);
			if ($id > 0) {
				$report['created'][$email] = $id;
			} else {
				$report['errors'][$email] = implode(', ', $account->errors);
			}
		}

		return $report;
	}
}
