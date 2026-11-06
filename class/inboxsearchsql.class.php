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
			$value = $sortKey === 'size' ? (string) ((int) $after[0]) : "'".$this->db->escape((string) $after[0])."'";
			$op = $desc ? '<' : '>';
			$where[] = "(".$expr." ".$op." ".$value." OR (".$expr." = ".$value." AND m.rowid ".$op." ".((int) $after[1])."))";
		}
		$sql = "SELECT m.rowid, m.fk_account, m.fk_folder, m.message_id, m.from_email, m.from_name, m.to_text, m.subject, m.snippet,";
		$sql .= " m.date_received, m.date_mail, m.size, m.has_attachments, m.seen, m.answered, m.flagged, m.draft, m.keywords, m.fk_soc,";
		$sql .= " s.nom AS soc_name, ".$expr." AS sort_value";
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
			return $col." LIKE '%".$this->db->escape($this->db->escapeforlike((string) $value))."%'";
		};
		foreach ((array) ($query['words'] ?? array()) as $w) {
			$or = array();
			foreach (array('m.subject', 'm.from_name', 'm.from_email', 'm.to_text', 'm.cc_text', 'm.snippet') as $col) {
				$or[] = $like($col, $w);
			}
			$where[] = "(".implode(' OR ', $or).")";
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
		if (!empty($query['soc'])) {
			$where[] = "m.fk_soc = ".((int) $query['soc']);
		}

		return $where;
	}

	/**
	 * @param string $cursor Cursor
	 * @return array{0:string,1:int}|null
	 */
	protected function decodeCursor($cursor)
	{
		if ((string) $cursor === '') {
			return null;
		}
		$data = json_decode((string) base64_decode(strtr((string) $cursor, '-_', '+/')), true);
		if (!is_array($data) || count($data) !== 2 || !is_scalar($data[0]) || !is_int($data[1])) {
			return null;
		}

		return array((string) $data[0], (int) $data[1]);
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
		);
	}
}
