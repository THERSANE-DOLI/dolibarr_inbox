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
 * \file    inbox/class/inboxsearchsql.class.php
 * \ingroup inbox
 * \brief   Search engine on the index (headers in the database): lists of the folders and search
 */

require_once __DIR__.'/inboxsearchengine.class.php';
require_once __DIR__.'/inboxheaderparser.class.php';

/**
 * Pages by cursor (sort value + row), never OFFSET: the page 37 of a folder costs as much as the first one.
 * The list of a folder is a search without words.
 */
class InboxSearchSql implements InboxSearchEngine
{
	/** Sorts => SQL expression (never null, the cursor compares values) */
	const SORTS = array(
		'date' => "COALESCE(m.date_received, m.date_mail, '1970-01-01 00:00:00')",
		'from' => "COALESCE(NULLIF(m.from_name, ''), m.from_email, '')",
		'subject' => "COALESCE(m.subject, '')",
		'size' => 'm.size',
	);

	/** Rows found in their text kept for one search (the deep search stops there) */
	const TEXT_IDS_MAX = 5000;

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
	 * @param int[]               $accountIds Mailboxes the user may read
	 * @param int                 $folderId   Folder, 0 = all the folders of the mailboxes
	 * @param array<string,mixed> $query      Parsed query + sort (date, from, subject, size), dir (asc, desc),
	 *                                        filters flagged, attachment, unread (bool), soc (int)
	 * @param string              $cursor     Cursor of the previous page
	 * @param int                 $limit      Rows of the page (1 to 500)
	 * @return array{rows: array<int,array<string,mixed>>, next: string, total: int}
	 */
	public function search(array $accountIds, $folderId, array $query, $cursor, $limit)
	{
		$accountIds = array_values(array_filter(array_map('intval', $accountIds)));
		if (!$accountIds) {
			return array('rows' => array(), 'next' => '', 'total' => 0);
		}
		$limit = max(1, min(500, (int) $limit));
		$sortKey = isset($query['sort'], self::SORTS[$query['sort']]) ? $query['sort'] : 'date';
		$expr = self::SORTS[$sortKey];
		$desc = !isset($query['dir']) || $query['dir'] !== 'asc';
		$rank = array_slice(array_values(array_unique(array_filter(array_map('intval', (array) ($query['rank'] ?? array()))))), 0, self::TEXT_IDS_MAX);
		if (($query['sort'] ?? '') === 'relevance' && $rank) {
			// Order of Meilisearch first (lot W7 1.20.0), then the other mails, the last arrived first: one number per
			// row, unique, so the cursor stays exact
			$case = "CASE m.rowid";
			foreach ($rank as $i => $id) {
				$case .= " WHEN ".$id." THEN ".($i + 1);
			}
			$sortKey = 'relevance';
			$expr = "((".$case." ELSE ".(count($rank) + 1)." END) * 10000000000 - m.rowid)";
			$desc = false;
		}
		$p = $this->db->prefix();

		$where = $this->where($accountIds, (int) $folderId, $query);
		$from = " FROM ".$p."inbox_mail as m INNER JOIN ".$p."inbox_folder as f ON f.rowid = m.fk_folder AND f.status = 1";

		$total = 0;
		if ($cursor === '') {
			$row = $this->db->getRow("SELECT COUNT(m.rowid) AS n".$from." WHERE ".implode(' AND ', $where));
			$total = is_object($row) ? (int) $row->n : 0;
		}
		$after = $this->decodeCursor($cursor);
		if ($after !== null) {
			$value = in_array($sortKey, array('size', 'relevance'), true) ? (string) ((int) $after[0]) : "'".$this->db->escape((string) $after[0])."'";
			$op = $desc ? '<' : '>';
			$where[] = "(".$expr." ".$op." ".$value." OR (".$expr." = ".$value." AND m.rowid ".$op." ".((int) $after[1])."))";
		}
		$sql = "SELECT m.rowid, m.fk_account, m.fk_folder, m.message_id, m.thread_key, m.junk_hint, m.from_email, m.from_name, m.to_text, m.subject, m.snippet, m.sort_key,";
		$sql .= " m.date_received, m.date_mail, m.size, m.has_attachments, m.seen, m.answered, m.flagged, m.draft, m.keywords, m.fk_soc,";
		$words = $this->wordsCondition($query);
		$sql .= " s.nom AS soc_name, ".$expr." AS sort_value, ".($words !== '' && $this->textIds($query) ? "CASE WHEN ".$words." THEN 0 ELSE 1 END" : "0")." AS in_text";
		$sql .= $from." LEFT JOIN ".$p."societe as s ON s.rowid = m.fk_soc";
		$sql .= " WHERE ".implode(' AND ', $where);
		$sql .= " ORDER BY ".$expr." ".($desc ? 'DESC' : 'ASC').", m.rowid ".($desc ? 'DESC' : 'ASC');
		$sql .= " LIMIT ".($limit + 1);
		$rows = array();
		$res = $this->db->query($sql);
		while ($res && ($o = $this->db->fetch_object($res))) {
			$rows[] = $o;
		}
		$next = '';
		if (count($rows) > $limit) {
			array_pop($rows);
			$last = end($rows);
			$next = rtrim(strtr(base64_encode(json_encode(array((string) $last->sort_value, (int) $last->rowid))), '+/', '-_'), '=');
		}

		return array('rows' => array_map(array($this, 'format'), $rows), 'next' => $next, 'total' => $total);
	}

	/**
	 * One page of conversations (sort by date only): one line per thread found in the folder (its last mail there),
	 * with the number of mails of the thread in the whole mailbox (sent mails included), unread and attachments
	 * of the thread in the folder. A mail without key yet (before its batch) is its own thread.
	 *
	 * @param int[]               $accountIds Mailboxes the user may read
	 * @param int                 $folderId   Folder (0: every folder of the mailboxes)
	 * @param array<string,mixed> $query      Parsed query, filters, dir
	 * @param string              $cursor     Cursor of the previous page
	 * @param int                 $limit      Threads of the page
	 * @return array{rows: array<int,array<string,mixed>>, next: string, total: int}
	 */
	public function searchThreads(array $accountIds, $folderId, array $query, $cursor, $limit)
	{
		$accountIds = array_values(array_filter(array_map('intval', $accountIds)));
		if (!$accountIds) {
			return array('rows' => array(), 'next' => '', 'total' => 0);
		}
		$limit = max(1, min(500, (int) $limit));
		$desc = !isset($query['dir']) || $query['dir'] !== 'asc';
		$p = $this->db->prefix();
		$date = self::SORTS['date'];
		$key = "COALESCE(m.thread_key, CONCAT('m', m.rowid))";
		$where = $this->where($accountIds, (int) $folderId, $query);
		$from = " FROM ".$p."inbox_mail as m INNER JOIN ".$p."inbox_folder as f ON f.rowid = m.fk_folder AND f.status = 1 WHERE ".implode(' AND ', $where);
		$total = 0;
		if ($cursor === '') {
			$row = $this->db->getRow("SELECT COUNT(DISTINCT ".$key.") AS n".$from);
			$total = is_object($row) ? (int) $row->n : 0;
		}
		$having = '';
		$after = $this->decodeCursor($cursor, true);
		if ($after !== null) {
			$op = $desc ? '<' : '>';
			$v = "'".$this->db->escape($after[0])."'";
			$k = "'".$this->db->escape($after[1])."'";
			$having = " HAVING (last_date ".$op." ".$v." OR (last_date = ".$v." AND tkey ".$op." ".$k."))";
		}
		// Unread mails of the thread here, the mails sent by the mailbox apart (never shown unread)
		$mineSql = "(COALESCE(f.role, '') IN ('sent', 'drafts') OR LOWER(COALESCE(m.from_email, '')) = (SELECT LOWER(a.email) FROM ".$p."inbox_account as a WHERE a.rowid = m.fk_account))";
		$sql = "SELECT ".$key." AS tkey, MAX(".$date.") AS last_date, COUNT(m.rowid) AS n_here, SUM(CASE WHEN m.seen = 0 AND NOT ".$mineSql." THEN 1 ELSE 0 END) AS unread,"
			." MAX(m.has_attachments) AS att, MAX(m.flagged) AS flagged, MIN(m.fk_account) AS account".$from
			." GROUP BY tkey".$having." ORDER BY last_date ".($desc ? 'DESC' : 'ASC').", tkey ".($desc ? 'DESC' : 'ASC')." LIMIT ".($limit + 1);
		$groups = array();
		$res = $this->db->query($sql);
		while ($res && ($o = $this->db->fetch_object($res))) {
			$groups[] = $o;
		}
		$next = '';
		if (count($groups) > $limit) {
			array_pop($groups);
			$last = end($groups);
			$next = rtrim(strtr(base64_encode(json_encode(array((string) $last->last_date, (string) $last->tkey))), '+/', '-_'), '=');
		}
		if (!$groups) {
			return array('rows' => array(), 'next' => '', 'total' => $total);
		}
		// Last mail of each thread in the place listed
		$keys = array_map(function ($g) {
			return "'".$this->db->escape((string) $g->tkey)."'";
		}, $groups);
		$sql = "SELECT m.rowid, m.fk_account, m.fk_folder, m.message_id, m.thread_key, m.junk_hint, m.from_email, m.from_name, m.to_text, m.subject, m.snippet, m.sort_key, m.date_received, m.date_mail, m.size,"
			." m.has_attachments, m.seen, m.answered, m.flagged, m.draft, m.keywords, m.fk_soc, s.nom AS soc_name, ".$key." AS tkey, ".$date." AS sort_value"
			." FROM ".$p."inbox_mail as m INNER JOIN ".$p."inbox_folder as f ON f.rowid = m.fk_folder AND f.status = 1 LEFT JOIN ".$p."societe as s ON s.rowid = m.fk_soc"
			." WHERE ".implode(' AND ', $where)." AND ".$key." IN (".implode(',', $keys).") ORDER BY sort_value DESC, m.rowid DESC";
		$best = array();
		$res = $this->db->query($sql);
		while ($res && ($o = $this->db->fetch_object($res))) {
			if (!isset($best[$o->tkey])) {
				$best[$o->tkey] = $o;
			}
		}
		// Size of each thread in the whole mailbox (a mail in two folders counts once)
		$sizes = array();
		$real = array_filter(array_map(function ($g) {
			return strpos((string) $g->tkey, 'm') === 0 && !preg_match('/^[0-9a-f]{32}$/', (string) $g->tkey) ? null : "'".$this->db->escape((string) $g->tkey)."'";
		}, $groups));
		if ($real) {
			$res = $this->db->query("SELECT thread_key, COUNT(DISTINCT COALESCE(message_id, rowid)) AS n FROM ".$p."inbox_mail WHERE fk_account IN (".implode(',', $accountIds).")"
				." AND thread_key IN (".implode(',', $real).") GROUP BY thread_key");
			while ($res && ($o = $this->db->fetch_object($res))) {
				$sizes[$o->thread_key] = (int) $o->n;
			}
		}
		$rows = array();
		foreach ($groups as $g) {
			if (!isset($best[$g->tkey])) {
				continue;
			}
			$r = $this->format($best[$g->tkey]);
			$r['thread'] = array('key' => (string) $g->tkey, 'count' => max((int) $g->n_here, $sizes[$g->tkey] ?? 1), 'unread' => (int) $g->unread);
			// The line is unread / starred / with attachment if one mail of the thread here is
			$r['seen'] = (int) $g->unread > 0 ? 0 : 1;
			$r['attachments'] = (int) $g->att;
			$r['flagged'] = (int) $g->flagged;
			$rows[] = $r;
		}

		return array('rows' => $rows, 'next' => $next, 'total' => $total);
	}

	/**
	 * @param int[]               $accountIds Mailboxes
	 * @param int                 $folderId   Folder or 0
	 * @param array<string,mixed> $query      Query
	 * @return string[] Conditions
	 */
	protected function where(array $accountIds, $folderId, array $query)
	{
		$where = array("m.fk_account IN (".implode(',', $accountIds).")");
		if ($folderId > 0) {
			$where[] = "m.fk_folder = ".((int) $folderId);
		}
		$like = function ($col, $value) {
			return $col." LIKE '%".$this->db->escape($this->db->escapeforlike(InboxHeaderParser::forDb($this->db, (string) $value)))."%'";
		};
		$words = $this->wordsCondition($query);
		if ($words !== '') {
			// Mails found in their text by the deep search (lot W7 1.19.0): found as well, even without the words in the index
			$textIds = $this->textIds($query);
			$where[] = $textIds ? "(".$words." OR m.rowid IN (".implode(',', $textIds)."))" : $words;
		}
		foreach ((array) ($query['from'] ?? array()) as $w) {
			$where[] = "(".$like('m.from_name', $w)." OR ".$like('m.from_email', $w).")";
		}
		foreach ((array) ($query['to'] ?? array()) as $w) {
			$where[] = "(".$like('m.to_text', $w)." OR ".$like('m.cc_text', $w).")";
		}
		foreach ((array) ($query['subject'] ?? array()) as $w) {
			$where[] = $like('m.subject', $w);
		}
		foreach ((array) ($query['tag'] ?? array()) as $w) {
			$where[] = "CONCAT(' ', COALESCE(m.keywords, ''), ' ') LIKE '% ".$this->db->escape($this->db->escapeforlike((string) $w))." %'";
		}
		if (!empty($query['before'])) {
			$where[] = "m.date_received < '".$this->db->idate((int) $query['before'])."'";
		}
		if (!empty($query['after'])) {
			$where[] = "m.date_received >= '".$this->db->idate((int) $query['after'])."'";
		}
		if (!empty($query['unread'])) {
			$where[] = "m.seen = 0";
		}
		if (!empty($query['flagged'])) {
			$where[] = "m.flagged = 1";
		}
		if (!empty($query['attachment'])) {
			$where[] = "m.has_attachments = 1";
		}
		// Unified views (1.29.0): folders of these roles only, or never of those
		$roleList = function ($roles) {
			return implode(',', array_map(function ($r) {
				return "'".$this->db->escape((string) $r)."'";
			}, (array) $roles));
		};
		if (!empty($query['roles'])) {
			$where[] = "f.role IN (".$roleList($query['roles']).")";
		}
		if (!empty($query['not_roles'])) {
			$where[] = "(f.role IS NULL OR f.role NOT IN (".$roleList($query['not_roles'])."))";
		}
		if (!empty($query['soc'])) {
			$where[] = "m.fk_soc = ".((int) $query['soc']);
		}
		if (!empty($query['mine'])) {
			// Assigned by the mailref (the Message-ID): follows the mail in every move
			global $conf;
			$p = $this->db->prefix();
			$where[] = "(m.message_id IN (SELECT r.message_id FROM ".$p."inbox_mailref as r INNER JOIN ".$p."inbox_mail_assign as a ON a.fk_mailref = r.rowid"
				." WHERE a.fk_user = ".((int) $query['mine'])." AND r.entity = ".((int) $conf->entity).")"
				// A mail without Message-ID (1.13.0): its key is a print, found by its sender and its date
				." OR ((m.message_id IS NULL OR m.message_id = '') AND EXISTS (SELECT r2.rowid FROM ".$p."inbox_mailref as r2 INNER JOIN ".$p."inbox_mail_assign as a2 ON a2.fk_mailref = r2.rowid"
				." WHERE a2.fk_user = ".((int) $query['mine'])." AND r2.entity = ".((int) $conf->entity)." AND r2.message_id LIKE 'fp:%' AND r2.from_email = m.from_email AND r2.date_mail = m.date_mail)))";
			if ($folderId <= 0 && empty($query['mine_archived'])) {
				// Handled mails: out of « my mails » (archives, with their subfolders, trash, junk)
				$where[] = "NOT EXISTS (SELECT fa.rowid FROM ".$p."inbox_folder as fa WHERE fa.fk_account = m.fk_account AND fa.role IN ('archive', 'trash', 'junk')"
					." AND (fa.rowid = f.rowid OR f.path LIKE CONCAT(fa.path, COALESCE(fa.delimiter, '/'), '%')))";
			}
		}

		return $where;
	}

	/**
	 * Every word in the index (subject, sender, recipients, snippet)
	 *
	 * @param array<string,mixed> $query Query
	 * @return string Condition, '' without words
	 */
	protected function wordsCondition(array $query)
	{
		$and = array();
		foreach ((array) ($query['words'] ?? array()) as $w) {
			$or = array();
			foreach (array('m.subject', 'm.from_name', 'm.from_email', 'm.to_text', 'm.cc_text', 'm.snippet') as $col) {
				$or[] = $col." LIKE '%".$this->db->escape($this->db->escapeforlike(InboxHeaderParser::forDb($this->db, (string) $w)))."%'";
			}
			$and[] = "(".implode(' OR ', $or).")";
		}

		return $and ? "(".implode(' AND ', $and).")" : '';
	}

	/**
	 * @param array<string,mixed> $query Query (text_ids: rows found in their text by the deep search)
	 * @return int[]
	 */
	protected function textIds(array $query)
	{
		return array_slice(array_values(array_unique(array_filter(array_map('intval', (array) ($query['text_ids'] ?? array()))))), 0, self::TEXT_IDS_MAX);
	}

	/**
	 * @param string $cursor  Cursor
	 * @param bool   $threads Cursor of a page of conversations (date, key of the thread)
	 * @return array{0:string,1:int|string}|null
	 */
	protected function decodeCursor($cursor, $threads = false)
	{
		if ((string) $cursor === '') {
			return null;
		}
		$data = json_decode((string) base64_decode(strtr((string) $cursor, '-_', '+/')), true);
		if (!is_array($data) || count($data) !== 2 || !is_scalar($data[0])) {
			return null;
		}
		if ($threads) {
			return is_string($data[1]) && preg_match('/^(m\d+|[0-9a-f]{32})$/', $data[1]) ? array((string) $data[0], $data[1]) : null;
		}

		return is_int($data[1]) ? array((string) $data[0], (int) $data[1]) : null;
	}

	/**
	 * Row => JSON for the application (texts escaped by the application, never here)
	 *
	 * @param object $o Row
	 * @return array<string,mixed>
	 */
	public function format($o)
	{
		$date = $o->date_received ? $o->date_received : $o->date_mail;

		return array(
			'id' => (int) $o->rowid,
			// Order of arrival in the folder (UID of IMAP): mark of the new mails
			'arrival' => (int) ($o->sort_key ?? 0),
			'account' => (int) $o->fk_account,
			'folder' => (int) $o->fk_folder,
			'from_email' => (string) $o->from_email,
			'from_name' => (string) $o->from_name,
			'to' => dol_trunc((string) $o->to_text, 300, 'right', 'UTF-8', 1),
			'subject' => (string) $o->subject,
			'snippet' => (string) $o->snippet,
			'date' => $date ? (int) $this->db->jdate($date) : 0,
			'size' => (int) $o->size,
			'attachments' => (int) $o->has_attachments,
			'seen' => (int) $o->seen,
			'answered' => (int) $o->answered,
			'flagged' => (int) $o->flagged,
			'draft' => (int) $o->draft,
			'tags' => trim((string) $o->keywords) === '' ? array() : array_values(array_filter(explode(' ', (string) $o->keywords))),
			'soc' => $o->fk_soc ? array('id' => (int) $o->fk_soc, 'name' => (string) $o->soc_name) : null,
			// A Message-ID: the key of an assignment (lot W4 1.9.0)
			// Every mail has a key now (Message-ID, or a print without it, 1.13.0): assignable, linkable
			'has_mid' => 1,
			// Key of the conversation (ignored, watched: lot W4 1.11.0)
			'tkey' => (string) ($o->thread_key ?? ''),
			// Junk: legit (known and authenticated) or imitation (1.15.0)
			'junk_hint' => (string) ($o->junk_hint ?? ''),
			// Found only in its text by the deep search (lot W7 1.19.0)
			'in_text' => (int) ($o->in_text ?? 0),
		);
	}
}
