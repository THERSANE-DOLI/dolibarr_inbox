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
 * \file    inbox/class/inboxlive.class.php
 * \ingroup inbox
 * \brief   Live reading of a protected mailbox (1.89.0, lot S2): folders and mails read on the mail server, nothing kept
 */

require_once __DIR__.'/inboxprotect.class.php';
require_once __DIR__.'/inboxsearchsql.class.php';
require_once __DIR__.'/connector/inboxconnectorfactory.class.php';
require_once __DIR__.'/connector/inboximapconnector.class.php';

/**
 * A protected mailbox has no row in the index (D1): its folders and mails are read on the mail server at each call,
 * during the session that unlocked it. The page speaks the same language as for the other mailboxes, with numbers
 * of its own, all NEGATIVE (a write of the index on such a number changes nothing):
 * - a folder: -(mailbox * 1000 + n), n < 1000;
 * - a mail: -(10^12 + mailbox * 10^9 + n).
 * Which folder and which mail a number stands for is kept for the session, encrypted with the key of the cookie
 * (InboxProtect::sessionPut), never in the database.
 */
class InboxLive
{
	const MAIL_BASE = 1000000000000;
	const MAIL_SPAN = 1000000000;
	const FOLDER_SPAN = 1000;
	/** Mails remembered per mailbox for a session at most (the oldest numbers are forgotten) */
	const MAX_MAILS = 20000;
	/** Folders whose counters are asked at the opening (STATUS) */
	const MAX_STATUS = 80;

	/** @var DoliDB */
	protected $db;

	/** @var string */
	public $error = '';

	/** @var array<int,InboxConnectorInterface> Connectors of this request */
	protected $connectors = array();

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct(DoliDB $db)
	{
		$this->db = $db;
	}

	/**
	 * @param int|string $id Folder or mail number
	 * @return bool A number of the live reading
	 */
	public static function isLive($id)
	{
		return (int) $id < 0;
	}

	/**
	 * @param int $id Number
	 * @return int    Mailbox of a live folder or mail
	 */
	public static function accountOf($id)
	{
		$abs = -(int) $id;
		if ($abs <= 0) {
			return 0;
		}
		if ($abs >= self::MAIL_BASE) {
			return intdiv($abs - self::MAIL_BASE, self::MAIL_SPAN);
		}

		return intdiv($abs, self::FOLDER_SPAN);
	}

	/**
	 * @param int|string $id Number
	 * @return bool          A live mail (not a folder)
	 */
	public static function isMail($id)
	{
		return -(int) $id >= self::MAIL_BASE;
	}

	// ---------------------------------------------------------------------------------------------------------------
	// Numbers of the session
	// ---------------------------------------------------------------------------------------------------------------

	/**
	 * @param int $accountId Mailbox
	 * @return array{f:array<string,int>,m:array<string,int>,n:int}
	 */
	protected static function map($accountId)
	{
		$map = InboxProtect::sessionGet('live'.(int) $accountId);

		return is_array($map) ? $map + array('f' => array(), 'm' => array(), 'n' => 0, 'r' => array()) : array('f' => array(), 'm' => array(), 'n' => 0, 'r' => array());
	}

	/**
	 * @param int                 $accountId Mailbox
	 * @param array<string,mixed> $map       Map
	 * @return void
	 */
	protected static function saveMap($accountId, array $map)
	{
		if (count($map['m']) > self::MAX_MAILS) {
			$map['m'] = array_slice($map['m'], -self::MAX_MAILS, null, true);
		}
		InboxProtect::sessionPut('live'.(int) $accountId, $map);
	}

	/**
	 * Number of a folder (the same for the whole session)
	 *
	 * @param int    $accountId Mailbox
	 * @param string $path      Path on the server
	 * @return int              < 0
	 */
	public static function folderId($accountId, $path)
	{
		$map = self::map($accountId);
		if (!isset($map['f'][$path])) {
			$n = count($map['f']) + 1;
			if ($n >= self::FOLDER_SPAN) {
				return 0;
			}
			$map['f'][$path] = $n;
			self::saveMap($accountId, $map);
		}

		return -((int) $accountId * self::FOLDER_SPAN + (int) $map['f'][$path]);
	}

	/**
	 * @param int $id Number of a folder
	 * @return string|null Path, null if unknown in this session
	 */
	public static function folderPath($id)
	{
		$accountId = self::accountOf($id);
		$n = -(int) $id - $accountId * self::FOLDER_SPAN;
		$path = array_search($n, self::map($accountId)['f'], true);

		return $path === false ? null : (string) $path;
	}

	/**
	 * Numbers of mails (one call for a page)
	 *
	 * @param int      $accountId Mailbox
	 * @param string   $path      Folder
	 * @param string[] $remoteIds "uidvalidity:uid"
	 * @return array<string,int> remote id => number
	 */
	public static function mailIds($accountId, $path, array $remoteIds)
	{
		$map = self::map($accountId);
		$out = array();
		$changed = false;
		foreach ($remoteIds as $rid) {
			$key = $path."\n".$rid;
			if (!isset($map['m'][$key])) {
				$map['n'] = (int) $map['n'] + 1;
				$map['m'][$key] = (int) $map['n'];
				$changed = true;
			}
			$out[$rid] = -(self::MAIL_BASE + (int) $accountId * self::MAIL_SPAN + (int) $map['m'][$key]);
		}
		if ($changed) {
			self::saveMap($accountId, $map);
		}

		return $out;
	}

	/**
	 * @param int $id Number of a mail
	 * @return array{account:int,path:string,remote_id:string}|null
	 */
	public static function mailOf($id)
	{
		if (!self::isMail($id)) {
			return null;
		}
		$accountId = self::accountOf($id);
		$n = -(int) $id - self::MAIL_BASE - $accountId * self::MAIL_SPAN;
		$key = array_search($n, self::map($accountId)['m'], true);
		if ($key === false) {
			return null;
		}
		list($path, $rid) = explode("\n", (string) $key, 2);

		return array('account' => $accountId, 'path' => $path, 'remote_id' => $rid);
	}

	// ---------------------------------------------------------------------------------------------------------------
	// Reading
	// ---------------------------------------------------------------------------------------------------------------

	/**
	 * Connector of an unlocked protected mailbox (one per request)
	 *
	 * @param InboxAccount $account Mailbox
	 * @return InboxImapConnector|null
	 */
	public function connector(InboxAccount $account)
	{
		global $langs;

		if (isset($this->connectors[(int) $account->id])) {
			return $this->connectors[(int) $account->id];
		}
		if (!$account->isProtected() || !InboxProtect::isUnlocked((int) $account->id)) {
			$this->error = $langs->transnoentities('InboxProtectLocked');
			return null;
		}
		$error = '';
		$c = InboxConnectorFactory::forAccount($this->db, $account, $error);
		if (!$c || !$c->connect()) {
			$this->error = $c ? $c->getError() : $error;
			return null;
		}
		$this->connectors[(int) $account->id] = $c;

		return $c;
	}

	/**
	 * @return void
	 */
	public function close()
	{
		foreach ($this->connectors as $c) {
			$c->disconnect();
		}
		$this->connectors = array();
	}

	/**
	 * Tree of the folders, in the form of the tree of the index (ajax/folder.php)
	 *
	 * @param InboxAccount $account Mailbox
	 * @return array<int,array<string,mixed>>|null
	 */
	public function tree(InboxAccount $account)
	{
		$c = $this->connector($account);
		$folders = $c ? $c->listFolders() : null;
		if ($folders === null) {
			$this->error = $this->error ?: ($c ? $c->getError() : '');
			return null;
		}
		$ids = array();
		foreach ($folders as $f) {
			$ids[$f['label']] = self::folderId((int) $account->id, (string) $f['path']);
		}
		self::keepFolders((int) $account->id, $folders);
		$order = array('inbox' => 0, 'drafts' => 1, 'sent' => 2, 'archive' => 3, 'junk' => 4, 'trash' => 5);
		$out = array();
		$n = 0;
		foreach ($folders as $f) {
			$parts = explode(' / ', (string) $f['label']);
			$name = array_pop($parts);
			$parent = implode(' / ', $parts);
			$role = (string) $f['role'];
			$status = ($f['selectable'] && $n++ < self::MAX_STATUS) ? $c->folderStatus((string) $f['path']) : null;
			$out[] = array(
				'id' => $ids[$f['label']],
				'name' => $name,
				'label' => (string) $f['label'],
				'parent' => $parent !== '' && isset($ids[$parent]) ? $ids[$parent] : 0,
				'role' => $role,
				'primary' => $role !== '',
				'rank' => $order[$role] ?? 9,
				'selectable' => $f['selectable'] ? 1 : 0,
				'followed' => 1,
				'total' => $status ? (int) $status['total'] : 0,
				'unread' => $status ? (int) $status['unread'] : 0,
				'indexed' => 0,
				'legit' => 0,
				'error' => false,
				'color' => '',
				'icon' => '',
				'new' => 0,
				'live' => 1,
			);
		}
		usort($out, function ($a, $b) {
			return $a['rank'] <=> $b['rank'] ?: strnatcasecmp($a['name'], $b['name']);
		});

		return $out;
	}

	/**
	 * Role, separator and state of the folders, kept for the session with their numbers (the actions look for the
	 * trash, the junk, the archives)
	 *
	 * @param int                            $accountId Mailbox
	 * @param array<int,array<string,mixed>> $folders   Folders of the connector
	 * @return void
	 */
	public static function keepFolders($accountId, array $folders)
	{
		foreach ($folders as $f) {
			self::folderId($accountId, (string) $f['path']);
		}
		$map = self::map($accountId);
		$map['r'] = array();
		foreach ($folders as $f) {
			$map['r'][(string) $f['path']] = array((string) $f['role'], (string) $f['delimiter'], $f['selectable'] ? 1 : 0, implode(' ', (array) $f['flags']));
		}
		self::saveMap($accountId, $map);
	}

	/**
	 * A folder of the mailbox as a row of llx_inbox_folder (rowid, fk_account, path, role, delimiter), read from the
	 * session, else from the server
	 *
	 * @param InboxAccount $account  Mailbox
	 * @param int          $folderId Number of the folder, or 0 with a role
	 * @param string       $role     Role looked for (trash, junk, archive, inbox) when no number
	 * @return stdClass|null
	 */
	public function folder(InboxAccount $account, $folderId, $role = '')
	{
		$map = self::map((int) $account->id);
		if (!$map['r']) {
			$c = $this->connector($account);
			$folders = $c ? $c->listFolders() : null;
			if ($folders === null) {
				return null;
			}
			self::keepFolders((int) $account->id, $folders);
			$map = self::map((int) $account->id);
		}
		$found = null;
		if ($folderId) {
			$path = self::folderPath($folderId);
			$found = ($path !== null && isset($map['r'][$path])) ? $path : null;
		} else {
			// The folder with the flag of the role first, then the shortest path (as the tree of the index)
			$best = null;
			foreach ($map['r'] as $path => $info) {
				if ($info[0] !== $role || !$info[2]) {
					continue;
				}
				$score = (stripos($info[3], '\\'.$role) !== false ? 0 : 1000) + strlen((string) $path);
				if ($best === null || $score < $best) {
					$best = $score;
					$found = (string) $path;
				}
			}
		}
		if ($found === null || empty($map['r'][$found][2])) {
			return null;
		}
		$o = new stdClass();
		$o->rowid = self::folderId((int) $account->id, $found);
		$o->fk_account = (int) $account->id;
		$o->path = $found;
		$o->role = (string) $map['r'][$found][0];
		$o->delimiter = (string) $map['r'][$found][1];
		$o->live = 1;

		return $o;
	}

	/**
	 * Forget the folders kept (a folder created or removed on the server: read again at the next use)
	 *
	 * @param int $accountId Mailbox
	 * @return void
	 */
	public static function forgetFolders($accountId)
	{
		$map = self::map($accountId);
		$map['r'] = array();
		self::saveMap($accountId, $map);
	}

	/**
	 * Rows of live mails for the actions (InboxActions::rows): where each one is, its folder as kept for the session
	 *
	 * @param InboxAccount $account Mailbox
	 * @param int[]        $ids     Numbers of mails of this mailbox
	 * @return array<int,stdClass>|null
	 */
	public function actionRows(InboxAccount $account, array $ids)
	{
		$out = array();
		foreach ($ids as $id) {
			$where = self::mailOf($id);
			if (!$where || $where['account'] !== (int) $account->id) {
				return null;
			}
			$folder = $this->folder($account, self::folderId((int) $account->id, $where['path']));
			$r = new stdClass();
			$r->rowid = (int) $id;
			$r->fk_account = (int) $account->id;
			$r->fk_folder = self::folderId((int) $account->id, $where['path']);
			$r->remote_id = $where['remote_id'];
			$r->sort_key = (int) substr($where['remote_id'], strpos($where['remote_id'], ':') + 1);
			$r->message_id = '';
			$r->subject = '';
			$r->date_mail = null;
			$r->date_received = null;
			// Unknown here: counted as changed by an undo
			$r->seen = -1;
			$r->answered = 0;
			$r->flagged = -1;
			$r->keywords = '';
			$r->path = $where['path'];
			$r->role = $folder ? $folder->role : '';
			$r->delimiter = $folder ? $folder->delimiter : '';
			$r->live = 1;
			$out[] = $r;
		}

		return $out;
	}

	/**
	 * Counters of the folders known in this session (polling of the page)
	 *
	 * @param InboxAccount $account Mailbox
	 * @param int[]        $only    Only these folders (the open one), empty = every known one (at most MAX_STATUS)
	 * @return array<int,array{0:int,1:int,2:int,3:int}> [id, total, unread, new]
	 */
	public function counters(InboxAccount $account, array $only = array())
	{
		$c = $this->connector($account);
		if (!$c) {
			return array();
		}
		$out = array();
		$n = 0;
		foreach (self::map((int) $account->id)['f'] as $path => $i) {
			$id = -((int) $account->id * self::FOLDER_SPAN + (int) $i);
			if (($only && !in_array($id, $only, true)) || $n++ >= self::MAX_STATUS) {
				continue;
			}
			$st = $c->folderStatus((string) $path);
			if ($st) {
				$out[] = array($id, (int) $st['total'], (int) $st['unread'], 0);
			}
		}

		return $out;
	}

	/**
	 * IMAP criteria of a search of the list (InboxSearchQuery + quick filters). What the server cannot search (a tag
	 * of inbox, a thirdparty, « mine ») gives an empty list rather than a wrong one.
	 *
	 * @param array<string,mixed> $query Parsed query
	 * @param bool                $empty Set when the query cannot match anything here
	 * @return array<int,mixed>
	 */
	public static function criteria(array $query, &$empty = false)
	{
		$empty = !empty($query['soc']) || !empty($query['mine']) || !empty($query['text_ids']);
		$c = array();
		$lit = function ($w) {
			$w = mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) $w)), 0, 100, 'UTF-8');

			return array('{'.strlen($w).'}', $w);
		};
		$words = false;
		foreach (array('words' => 'TEXT', 'from' => 'FROM', 'to' => 'TO', 'subject' => 'SUBJECT') as $k => $criterion) {
			foreach ((array) ($query[$k] ?? array()) as $w) {
				if (trim((string) $w) === '') {
					continue;
				}
				$c[] = $criterion;
				$c[] = $lit($w);
				$words = true;
			}
		}
		foreach ((array) ($query['tag'] ?? array()) as $tag) {
			if (preg_match('/^[\x21-\x7E]{1,64}$/', (string) $tag) && strpos((string) $tag, '"') === false) {
				$c[] = 'KEYWORD';
				$c[] = (string) $tag;
			}
		}
		if (!empty($query['unread'])) {
			$c[] = 'UNSEEN';
		}
		if (!empty($query['flagged'])) {
			$c[] = 'FLAGGED';
		}
		if (!empty($query['after'])) {
			$c[] = 'SINCE';
			$c[] = gmdate('j-M-Y', (int) $query['after']);
		}
		if (!empty($query['before'])) {
			$c[] = 'BEFORE';
			$c[] = gmdate('j-M-Y', (int) $query['before']);
		}
		if (!$c) {
			return array('ALL');
		}

		return $words ? array_merge(array('CHARSET', 'UTF-8'), $c) : $c;
	}

	/**
	 * A page of a folder, rows in the form of InboxSearchSql::format()
	 *
	 * @param InboxAccount        $account  Mailbox
	 * @param int                 $folderId Number of the folder
	 * @param array<string,mixed> $query    Parsed query (sort, dir, filters)
	 * @param string              $cursor   Offset of the page
	 * @param int                 $limit    Rows
	 * @return array{rows:array<int,array<string,mixed>>,next:string,total:int}|null
	 */
	public function page(InboxAccount $account, $folderId, array $query, $cursor, $limit)
	{
		$path = self::folderPath($folderId);
		if ($path === null) {
			$this->error = 'Folder not found';
			return null;
		}
		$c = $this->connector($account);
		if (!$c) {
			return null;
		}
		$empty = false;
		$criteria = self::criteria($query, $empty);
		if ($empty) {
			return array('rows' => array(), 'next' => '', 'total' => 0);
		}
		// Version of the folder read with the page: the polling of the page compares with it
		$status = $c->folderStatus($path);
		$found = $c->liveSearch($path, $criteria);
		if ($found === null) {
			$this->error = $c->getError();
			return null;
		}
		$uids = $found['uids'];
		if (($query['dir'] ?? 'desc') === 'asc') {
			$uids = array_reverse($uids);
		}
		$offset = max(0, (int) $cursor);
		$limit = max(1, min(500, (int) $limit));
		$slice = array_slice($uids, $offset, $limit);
		$rows = $slice ? $c->liveFetch($path, $slice) : array();
		if ($rows === null) {
			$this->error = $c->getError();
			return null;
		}
		if (!empty($query['attachment'])) {
			$rows = array_values(array_filter($rows, function ($r) {
				return !empty($r['has_attachments']);
			}));
		}
		$ids = self::mailIds((int) $account->id, $path, array_column($rows, 'remote_id'));
		$out = array();
		foreach ($rows as $r) {
			$out[] = self::format($r, (int) $account->id, (int) $folderId, $ids[$r['remote_id']]);
		}
		$next = $offset + $limit < count($uids) ? (string) ($offset + $limit) : '';

		return array('rows' => $out, 'next' => $next, 'total' => count($uids), 'stamp' => $status ? (string) $status['token'] : '');
	}

	/**
	 * A mail read by the connector, as a row of the list
	 *
	 * @param array<string,mixed> $r         Mail of the connector
	 * @param int                 $accountId Mailbox
	 * @param int                 $folderId  Number of the folder
	 * @param int                 $id        Number of the mail
	 * @return array<string,mixed>
	 */
	public static function format(array $r, $accountId, $folderId, $id)
	{
		$to = array();
		foreach ((array) ($r['to'] ?? array()) as $a) {
			$to[] = $a['name'] !== '' ? $a['name'] : $a['email'];
		}

		return array(
			'id' => (int) $id,
			'arrival' => (int) $r['sort_key'],
			'account' => (int) $accountId,
			'folder' => (int) $folderId,
			'from_email' => (string) $r['from_email'],
			'from_name' => (string) $r['from_name'],
			'via' => null,
			'to' => dol_trunc(implode(', ', $to), 300, 'right', 'UTF-8', 1),
			'subject' => (string) $r['subject'],
			'snippet' => (string) ($r['snippet'] ?? ''),
			'date' => (int) ($r['date_received'] ?: $r['date_mail']),
			'size' => (int) $r['size'],
			'attachments' => (int) $r['has_attachments'],
			'seen' => (int) $r['seen'],
			'answered' => (int) $r['answered'],
			'flagged' => (int) $r['flagged'],
			'draft' => (int) $r['draft'],
			'tags' => array_values((array) $r['keywords']),
			'soc' => null,
			'has_mid' => 0,
			'tkey' => '',
			'junk_hint' => '',
			'in_text' => 0,
			'live' => 1,
		);
	}

	/**
	 * Row of a live mail in the form of a row of llx_inbox_mail (with folder_path and folder_role), read on the
	 * server: what InboxReader and the actions read from it
	 *
	 * @param InboxAccount $account Mailbox
	 * @param int          $id      Number of the mail
	 * @return stdClass|null
	 */
	public function row(InboxAccount $account, $id)
	{
		$where = self::mailOf($id);
		if (!$where || $where['account'] !== (int) $account->id) {
			$this->error = 'not_found';
			return null;
		}
		$c = $this->connector($account);
		if (!$c) {
			return null;
		}
		$uid = (int) substr((string) $where['remote_id'], strpos((string) $where['remote_id'], ':') + 1);
		$rows = $c->liveFetch($where['path'], array($uid));
		if ($rows === null) {
			$this->error = $c->getError();
			return null;
		}
		if (!$rows || (string) $rows[0]['remote_id'] !== (string) $where['remote_id']) {
			$this->error = 'gone';
			return null;
		}
		$r = $rows[0];
		$role = '';
		foreach ((array) $c->listFolders() as $f) {
			if ((string) $f['path'] === $where['path']) {
				$role = (string) $f['role'];
				break;
			}
		}
		$to = array();
		foreach ((array) $r['to'] as $a) {
			$to[] = $a['name'] !== '' ? $a['name'] : $a['email'];
		}
		$row = new stdClass();
		$row->rowid = (int) $id;
		$row->entity = 0;
		$row->fk_account = (int) $account->id;
		$row->fk_folder = self::folderId((int) $account->id, $where['path']);
		$row->folder_path = $where['path'];
		$row->folder_role = $role;
		$row->remote_id = (string) $r['remote_id'];
		$row->sort_key = (int) $r['sort_key'];
		$row->message_id = (string) $r['message_id'];
		$row->in_reply_to = (string) $r['in_reply_to'];
		$row->refs = (string) $r['refs'];
		$row->thread_key = '';
		$row->from_email = (string) $r['from_email'];
		$row->from_name = (string) $r['from_name'];
		$row->to_text = implode(', ', $to);
		$row->subject = (string) $r['subject'];
		$row->snippet = (string) ($r['snippet'] ?? '');
		$row->date_mail = $r['date_mail'] ? $this->db->idate((int) $r['date_mail']) : null;
		$row->date_received = $r['date_received'] ? $this->db->idate((int) $r['date_received']) : null;
		$row->size = (int) $r['size'];
		$row->has_attachments = (int) $r['has_attachments'];
		$row->seen = (int) $r['seen'];
		$row->answered = (int) $r['answered'];
		$row->flagged = (int) $r['flagged'];
		$row->draft = (int) $r['draft'];
		$row->keywords = implode(' ', (array) $r['keywords']);
		$row->fk_soc = null;
		$row->fk_source = 0;
		$row->requester_email = '';
		$row->requester_name = '';
		$row->junk_hint = '';
		$row->spam_check = null;
		$row->spam_engine = null;
		$row->spam_score = null;
		$row->spam_required = null;
		$row->spam_action = null;
		$row->spam_symbols = null;
		$row->live = 1;

		return $row;
	}
}
