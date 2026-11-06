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
 * \file    inbox/class/inboxservertester.class.php
 * \ingroup inbox
 * \brief   Complete test of a mailbox: IMAP, SMTP, Sieve
 */

require_once __DIR__.'/connector/inboxconnectorfactory.class.php';

/**
 * Test of a mailbox, read only, step by step (analyse-lot-w1 §6): each step is ok (green), warning (orange) or error
 * (red), with a sentence saying what to do. Works on a saved mailbox or on the values of the form (unsaved object):
 * the password typed is never in the result.
 */
class InboxServerTester
{
	const OK = 'ok';
	const WARNING = 'warning';
	const ERROR = 'error';

	/** @var DoliDB */
	protected $db;

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct(DoliDB $db)
	{
		$this->db = $db;
	}

	/**
	 * Run the test
	 *
	 * @param InboxAccount $account Mailbox (saved or not)
	 * @return array{status:string,duration:float,steps:array<int,array{step:string,status:string,message:string,duration:float}>,server_info:array<string,mixed>,folder_roles:array<string,string>}
	 */
	public function run(InboxAccount $account)
	{
		global $langs;

		$langs->load('inbox@inbox');
		$start = microtime(true);
		$steps = array();
		$serverInfo = array();
		$roles = $account->getFolderRoles();
		$error = '';
		$connector = InboxConnectorFactory::forAccount($this->db, $account, $error);
		if (!$connector) {
			$steps[] = $this->step('imap', self::ERROR, $error, 0);
			return $this->finish($steps, $start, $serverInfo, $roles);
		}

		// IMAP: connection (DNS, port, encryption, login), capabilities, folders
		$t = microtime(true);
		$test = $connector->testConnection();
		if ($test === null) {
			$steps[] = $this->step('imap', self::ERROR, $connector->getError(), $t);
			// No SMTP / Sieve test when the server cannot be reached at all: same host, same answer
			return $this->finish($steps, $start, $serverInfo, $roles);
		}
		$info = $test['info'];
		$steps[] = $this->step('imap', self::OK, $langs->transnoentities('InboxTestImapOk', $account->imap_host, $test['duration']), $t);
		$serverInfo['imap'] = $info;

		$t = microtime(true);
		$missing = array();
		foreach (array('MOVE' => 'move', 'UIDPLUS' => 'uidplus') as $cap => $key) {
			if (empty($info[$key])) {
				$missing[] = $cap;
			}
		}
		$steps[] = $missing
			? $this->step('capabilities', self::WARNING, $langs->transnoentities('InboxTestCapabilitiesMissing', implode(', ', $missing)), $t)
			: $this->step('capabilities', self::OK, $langs->transnoentities('InboxTestCapabilitiesOk', (string) $info['delimiter']), $t);

		$t = microtime(true);
		$found = isset($info['roles']) && is_array($info['roles']) ? $info['roles'] : array();
		// Roles chosen by hand stay; the found ones fill the gaps
		foreach ($found as $role => $path) {
			if ($role !== 'inbox' && empty($roles[$role])) {
				$roles[$role] = $path;
			}
		}
		$absent = array();
		foreach (array('sent', 'drafts', 'trash', 'junk', 'archive') as $role) {
			if (empty($roles[$role])) {
				$absent[] = $langs->transnoentities('InboxFolderRole'.ucfirst($role));
			}
		}
		$total = 0;
		if (method_exists($connector, 'countMessages')) {
			$folders = $connector->listFolders();
			$paths = array();
			foreach ((array) $folders as $folder) {
				if ($folder['selectable']) {
					$paths[] = $folder['path'];
				}
			}
			foreach ($connector->countMessages($paths) as $count) {
				$total += max(0, $count);
			}
		}
		$steps[] = $absent
			? $this->step('folders', self::WARNING, $langs->transnoentities('InboxTestFoldersMissing', implode(', ', $absent)), $t)
			: $this->step('folders', self::OK, $langs->transnoentities('InboxTestFoldersOk', (int) $info['folders'], $total), $t);
		$serverInfo['imap']['messages'] = $total;

		// SMTP and Sieve: connector of inbox only (other connectors send and filter their own way)
		if (method_exists($connector, 'testSmtp')) {
			$t = microtime(true);
			$smtp = $connector->testSmtp();
			$serverInfo['smtp'] = array('extensions' => $smtp['extensions']);
			$steps[] = $this->step('smtp', $smtp['status'], $this->message($smtp['code'], $smtp['detail'], $account->getSmtpHost(), (int) $account->smtp_port), $t);
		}
		if (method_exists($connector, 'testSieve')) {
			$t = microtime(true);
			$sieve = $connector->testSieve();
			$serverInfo['sieve'] = array('extensions' => $sieve['extensions'], 'scripts' => $sieve['scripts']);
			$steps[] = $this->step('sieve', $sieve['status'], $this->message($sieve['code'], $sieve['detail'], (string) $account->imap_host, (int) $account->sieve_port), $t);
		}
		$connector->disconnect();

		return $this->finish($steps, $start, $serverInfo, $roles);
	}

	/**
	 * Run the test and keep its result on the saved mailbox (server_info, folder roles found, date, result, error)
	 *
	 * @param InboxAccount $account Saved mailbox
	 * @param User         $user    User
	 * @return array<string,mixed> Result of run()
	 */
	public function runAndSave(InboxAccount $account, User $user)
	{
		$result = $this->run($account);
		if ($account->id > 0) {
			$errors = array();
			foreach ($result['steps'] as $step) {
				if ($step['status'] === self::ERROR) {
					$errors[] = $step['message'];
				}
			}
			$sql = "UPDATE ".$this->db->prefix()."inbox_account SET";
			$sql .= " date_last_test = '".$this->db->idate(dol_now())."',";
			$sql .= " last_test_result = '".$this->db->escape($result['status'])."',";
			$sql .= " last_error = ".($errors ? "'".$this->db->escape(implode("\n", $errors))."'" : 'NULL');
			if (!empty($result['server_info'])) {
				$sql .= ", server_info = '".$this->db->escape((string) json_encode($result['server_info']))."'";
				$sql .= ", folder_roles = '".$this->db->escape((string) json_encode($result['folder_roles']))."'";
			}
			$sql .= ", fk_user_modif = ".((int) $user->id);
			$sql .= " WHERE rowid = ".((int) $account->id);
			$this->db->query($sql);
		}

		return $result;
	}

	/**
	 * @param string $step   Step code
	 * @param string $status ok, warning, error
	 * @param string $message Sentence for the user
	 * @param float  $t      Start of the step (microtime), 0 for none
	 * @return array{step:string,status:string,message:string,duration:float}
	 */
	protected function step($step, $status, $message, $t)
	{
		return array('step' => $step, 'status' => $status, 'message' => (string) $message, 'duration' => $t > 0 ? round(microtime(true) - $t, 2) : 0.0);
	}

	/**
	 * @param array<int,array{step:string,status:string,message:string,duration:float}> $steps Steps
	 * @param float                $start      Start of the test
	 * @param array<string,mixed>  $serverInfo Server information
	 * @param array<string,string> $roles      Folder roles
	 * @return array<string,mixed>
	 */
	protected function finish($steps, $start, $serverInfo, $roles)
	{
		$status = self::OK;
		foreach ($steps as $step) {
			if ($step['status'] === self::ERROR) {
				$status = self::ERROR;
				break;
			}
			if ($step['status'] === self::WARNING) {
				$status = self::WARNING;
			}
		}

		return array('status' => $status, 'duration' => round(microtime(true) - $start, 2), 'steps' => $steps, 'server_info' => $serverInfo, 'folder_roles' => $roles);
	}

	/**
	 * Sentence of a result code of the SMTP / Sieve tests
	 *
	 * @param string $code   Code
	 * @param string $detail Detail (server answer, other script name)
	 * @param string $host   Host
	 * @param int    $port   Port
	 * @return string
	 */
	protected function message($code, $detail, $host, $port)
	{
		global $langs;

		$map = array(
			'smtp_ok' => 'InboxTestSmtpOk', 'smtp_no_server' => 'InboxTestSmtpNoServer', 'smtp_master' => 'InboxTestSmtpMaster',
			'smtp_no_password' => 'InboxTestSmtpNoPassword', 'smtp_certificate' => 'InboxTestSmtpCertificate', 'smtp_connect' => 'InboxTestSmtpConnect',
			'smtp_greeting' => 'InboxTestSmtpConnect', 'smtp_no_starttls' => 'InboxTestSmtpNoStarttls', 'smtp_no_auth' => 'InboxTestSmtpNoAuth',
			'smtp_auth' => 'InboxTestSmtpAuth',
			'sieve_ok' => 'InboxTestSieveOk', 'sieve_disabled' => 'InboxTestSieveDisabled', 'sieve_connect' => 'InboxTestSieveUnavailable',
			'sieve_tls' => 'InboxTestSieveTls', 'sieve_no_tls' => 'InboxTestSieveNoTls', 'sieve_no_password' => 'InboxTestSieveUnavailable',
			'sieve_auth' => 'InboxTestSieveAuth', 'sieve_other_script' => 'InboxTestSieveOtherScript',
		);
		$key = isset($map[$code]) ? $map[$code] : 'InboxTestUnknown';
		if ($code === 'sieve_other_script') {
			return $langs->transnoentities($key, $detail);
		}
		$text = $langs->transnoentities($key, $host, $port);
		if ($detail !== '' && in_array($code, array('smtp_connect', 'smtp_greeting', 'sieve_connect'), true)) {
			$text .= ' ('.dol_trunc($detail, 200).')';
		}

		return $text;
	}
}
