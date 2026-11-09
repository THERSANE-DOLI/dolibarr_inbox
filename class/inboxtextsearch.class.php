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
 * \file    inbox/class/inboxtextsearch.class.php
 * \ingroup inbox
 * \brief   Deep search (lot W7 1.19.0): the text of the mails searched by the mail server, one mailbox per call
 */

require_once __DIR__.'/connector/inboxconnectorfactory.class.php';
require_once __DIR__.'/inboxsearchsql.class.php';

/**
 * The mail server reads the mails at each search (no full text index on Dovecot without FTS: about 1 ms per mail),
 * so the search is asked by the user, one mailbox per call, within a time limit. The rows found are kept in a small
 * file per user and search (10 minutes): the list reads them back with the index, its filters, sort and pages. Nothing
 * of the text of the mails is kept.
 */
class InboxTextSearch
{
	/** Time limit per mailbox, in seconds */
	const DEADLINE = 20;

	/** Life of the results kept, in seconds */
	const TTL = 600;

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
	 * Key of a search: same words, period and scope = same results
	 *
	 * @param User                $user    User
	 * @param array<string,mixed> $query   Parsed query (words, after, before)
	 * @param string              $scope   folder, account, all
	 * @param int                 $scopeId Folder (folder), mailbox (account), 0 (all)
	 * @return string
	 */
	public static function key(User $user, array $query, $scope, $scopeId)
	{
		global $conf;

		$words = array_map(function ($w) {
			return mb_strtolower((string) $w, 'UTF-8');
		}, (array) ($query['words'] ?? array()));

		return sha1((string) json_encode(array((int) $conf->entity, (int) $user->id, $words, (int) ($query['after'] ?? 0), (int) ($query['before'] ?? 0), (string) $scope, (int) $scopeId)));
	}

	/**
	 * Folders of a mailbox searched: the folder of the scope, or every synchronised folder but the trash and the junk
	 *
	 * @param int    $accountId Mailbox
	 * @param string $scope     folder, account, all
	 * @param int    $folderId  Folder (scope folder)
	 * @return array<int,object> Rows rowid, path
	 */
	public function folders($accountId, $scope, $folderId)
	{
		$sql = "SELECT rowid, path FROM ".$this->db->prefix()."inbox_folder WHERE fk_account = ".((int) $accountId)." AND status = 1 AND selectable = 1";
		if ($scope === 'folder') {
			$sql .= " AND rowid = ".((int) $folderId);
		} else {
			$sql .= " AND sync_enabled = 1 AND COALESCE(role, '') NOT IN ('trash', 'junk')";
		}
		// The big folders last: the time limit stops on them, the others are done
		$rows = $this->db->getRows($sql." ORDER BY CASE WHEN role = 'inbox' THEN 0 ELSE 1 END, total ASC LIMIT 1000");

		return is_array($rows) ? $rows : array();
	}

	/**
	 * Search one mailbox
	 *
	 * @param InboxAccount        $account  Mailbox (readable by the user, checked by the caller)
	 * @param array<int,object>   $folders  Folders (folders())
	 * @param array<string,mixed> $query    Parsed query (words, after, before)
	 * @param float               $deadline microtime(true) after which no folder is started
	 * @return array{ids: int[], complete: bool, done: int, total: int, unsupported: bool, error: string}
	 */
	public function run(InboxAccount $account, array $folders, array $query, $deadline)
	{
		$out = array('ids' => array(), 'complete' => true, 'done' => 0, 'total' => count($folders), 'unsupported' => false, 'error' => '');
		$words = array_values(array_filter(array_map('strval', (array) ($query['words'] ?? array())), function ($w) {
			return trim($w) !== '';
		}));
		if (!$words || !$folders) {
			return $out;
		}
		$error = '';
		$connector = InboxConnectorFactory::forAccount($this->db, $account, $error);
		if (!$connector || !$connector->can(InboxConnectorInterface::CAP_SERVER_SEARCH)) {
			$out['unsupported'] = true;
			$out['complete'] = false;
			$out['error'] = $error;
			return $out;
		}
		$options = array('since' => (int) ($query['after'] ?? 0), 'before' => (int) ($query['before'] ?? 0));
		foreach ($folders as $f) {
			if (microtime(true) > $deadline || count($out['ids']) >= InboxSearchSql::TEXT_IDS_MAX) {
				$out['complete'] = false;
				break;
			}
			$remote = $connector->searchText((string) $f->path, $words, $options);
			if ($remote === null) {
				// Time limit of the socket or a folder gone: the other folders are still searched
				$out['complete'] = false;
				$out['error'] = $connector->getError();
				continue;
			}
			$out['done']++;
			foreach (array_chunk($remote, 500) as $chunk) {
				$in = implode(',', array_map(function ($r) {
					return "'".$this->db->escape((string) $r)."'";
				}, $chunk));
				$rows = $this->db->getRows("SELECT rowid FROM ".$this->db->prefix()."inbox_mail WHERE fk_folder = ".((int) $f->rowid)." AND remote_id IN (".$in.") LIMIT 500");
				foreach (is_array($rows) ? $rows : array() as $r) {
					$out['ids'][] = (int) $r->rowid;
				}
			}
		}
		$connector->disconnect();
		$out['ids'] = array_slice(array_values(array_unique($out['ids'])), 0, InboxSearchSql::TEXT_IDS_MAX);

		return $out;
	}

	/**
	 * @return string Folder of the results kept
	 */
	protected function dir()
	{
		global $conf;

		return (!empty($conf->inbox->dir_temp) ? $conf->inbox->dir_temp : DOL_DATA_ROOT.'/inbox/temp').'/search';
	}

	/**
	 * @param User   $user User
	 * @param string $key  Key of the search
	 * @return string
	 */
	protected function file(User $user, $key)
	{
		return $this->dir().'/'.((int) $user->id).'_'.preg_replace('/[^0-9a-f]/', '', (string) $key).'.json';
	}

	/**
	 * Keep the rows found in a mailbox (the old files of every user are removed on the way)
	 *
	 * @param User   $user      User
	 * @param string $key       Key of the search
	 * @param int    $accountId Mailbox
	 * @param int[]  $ids       Rows found
	 * @return bool
	 */
	public function save(User $user, $key, $accountId, array $ids)
	{
		$dir = $this->dir();
		if (!is_dir($dir) && dol_mkdir($dir) < 0) {
			return false;
		}
		foreach ((array) glob($dir.'/*.json') as $old) {
			if (is_string($old) && @filemtime($old) < time() - self::TTL) {
				@unlink($old);
			}
		}
		$file = $this->file($user, $key);
		$data = $this->read($file);
		$data[(string) ((int) $accountId)] = array_values(array_map('intval', $ids));

		return @file_put_contents($file, json_encode($data), LOCK_EX) !== false;
	}

	/**
	 * Rows found for a search, only in the mailboxes still readable
	 *
	 * @param User   $user     User
	 * @param string $key      Key of the search
	 * @param int[]  $readable Mailboxes the user may read
	 * @return int[]
	 */
	public function load(User $user, $key, array $readable)
	{
		$file = $this->file($user, $key);
		if (!is_file($file) || @filemtime($file) < time() - self::TTL) {
			return array();
		}
		$out = array();
		foreach ($this->read($file) as $accountId => $ids) {
			if (in_array((int) $accountId, array_map('intval', $readable), true)) {
				$out = array_merge($out, array_map('intval', (array) $ids));
			}
		}

		return array_slice(array_values(array_unique($out)), 0, InboxSearchSql::TEXT_IDS_MAX);
	}

	/**
	 * @param string $file File
	 * @return array<string,int[]>
	 */
	protected function read($file)
	{
		$data = is_file($file) ? json_decode((string) @file_get_contents($file), true) : null;

		return is_array($data) ? $data : array();
	}
}
