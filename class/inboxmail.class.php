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
 * \file    inbox/class/inboxmail.class.php
 * \ingroup inbox
 * \brief   Mails of the index: one row per place of a mail
 */

require_once __DIR__.'/inboxheaderparser.class.php';
require_once __DIR__.'/inboxcontactmatcher.class.php';

/**
 * Rows of the index (headers, flags, keywords), written by the synchronisation. Never the body of a mail.
 */
class InboxMail
{
	/** @var DoliDB */
	protected $db;

	/** @var InboxContactMatcher|null */
	protected $matcher = null;

	/** @var int Mails of the last addMany() the database refused (other than already there) */
	public $failed = 0;

	/** @var string First error of the database of the last addMany() */
	public $lastError = '';

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct(DoliDB $db)
	{
		$this->db = $db;
	}

	/**
	 * Add the new mails of a folder (a mail already there is updated, never doubled)
	 *
	 * @param int                            $accountId Mailbox
	 * @param int                            $folderId  Folder
	 * @param array<int,array<string,mixed>> $mails     Common format of the connector
	 * @return int                                       Mails added
	 */
	public function addMany($accountId, $folderId, array $mails)
	{
		if (!$mails) {
			return 0;
		}
		if ($this->matcher === null) {
			$this->matcher = new InboxContactMatcher($this->db);
		}
		$p = $this->db->prefix();
		$existing = array();
		$ids = array_map(function ($m) {
			return "'".$this->db->escape((string) $m['remote_id'])."'";
		}, $mails);
		foreach (array_chunk($ids, 500) as $chunk) {
			$rows = $this->db->getRows("SELECT remote_id FROM ".$p."inbox_mail WHERE fk_folder = ".((int) $folderId)." AND remote_id IN (".implode(',', $chunk).") LIMIT 1000");
			foreach (is_array($rows) ? $rows : array() as $r) {
				$existing[$r->remote_id] = true;
			}
		}
		$added = 0;
		$this->failed = 0;
		$this->lastError = '';
		$now = $this->db->idate(dol_now());
		foreach ($mails as $m) {
			if (isset($existing[$m['remote_id']])) {
				$this->updateFlags($folderId, array($m));
				continue;
			}
			$match = $this->matcher->match((string) $m['from_email']);
			$date = function ($ts) {
				return $ts > 0 ? "'".$this->db->idate((int) $ts)."'" : 'NULL';
			};
			$str = function ($v, $max = 255) {
				$v = InboxHeaderParser::forDb($this->db, (string) $v);
				return $v === '' ? 'NULL' : "'".$this->db->escape(dol_trunc($v, $max, 'right', 'UTF-8', 1))."'";
			};
			$sql = "INSERT INTO ".$p."inbox_mail (fk_account, fk_folder, remote_id, sort_key, message_id, in_reply_to, refs, date_mail, date_received,";
			$sql .= " from_email, from_name, to_text, cc_text, subject, snippet, size, has_attachments, seen, answered, flagged, draft, keywords, fk_soc, fk_socpeople, date_index)";
			$sql .= " VALUES (".((int) $accountId).", ".((int) $folderId).", '".$this->db->escape((string) $m['remote_id'])."', ".((int) $m['sort_key']).",";
			$sql .= " ".$str($m['message_id']).", ".$str($m['in_reply_to']).", ".$str($m['refs'], 2000).", ".$date((int) $m['date_mail']).", ".$date((int) $m['date_received']).",";
			$sql .= " ".$str($m['from_email']).", ".$str($m['from_name']).", ".$str(InboxHeaderParser::addressText($m['to']), 2000).", ".$str(InboxHeaderParser::addressText($m['cc']), 2000).",";
			$sql .= " ".$str($m['subject']).", ".$str($m['snippet']).", ".((int) $m['size']).", ".((int) $m['has_attachments']).",";
			$sql .= " ".((int) $m['seen']).", ".((int) $m['answered']).", ".((int) $m['flagged']).", ".((int) $m['draft']).", ".$str(implode(' ', (array) $m['keywords']), 1024).",";
			$sql .= " ".($match['fk_soc'] ? (int) $match['fk_soc'] : 'NULL').", ".($match['fk_socpeople'] ? (int) $match['fk_socpeople'] : 'NULL').", '".$now."')";
			if (!$this->db->query($sql)) {
				// Another pass wrote it in between (unique key): not an error. Any other error is kept: the mail is not
				// in the index, the folder says it
				if ($this->db->lasterrno() !== 'DB_ERROR_RECORD_ALREADY_EXISTS') {
					$this->failed++;
					if ($this->lastError === '') {
						$this->lastError = $this->db->lasterror();
					}
					dol_syslog(__METHOD__.' mail '.$m['remote_id'].' not indexed: '.$this->db->lasterror(), LOG_WARNING);
				}
				continue;
			}
			$mailId = (int) $this->db->last_insert_id($p.'inbox_mail');
			$this->addAddresses($mailId, $m);
			$added++;
		}

		return $added;
	}

	/**
	 * @param int                 $mailId Row
	 * @param array<string,mixed> $m      Common format
	 * @return void
	 */
	protected function addAddresses($mailId, array $m)
	{
		$values = array();
		$list = array();
		if ($m['from_email'] !== '') {
			$list[] = array('from', $m['from_email'], $m['from_name']);
		}
		foreach (array('to', 'cc', 'reply_to') as $type) {
			foreach ((array) $m[$type] as $a) {
				$list[] = array($type, $a['email'], $a['name']);
			}
		}
		foreach (array_slice($list, 0, InboxHeaderParser::MAX_ADDRESSES) as $a) {
			$name = InboxHeaderParser::forDb($this->db, $a[2]);
			$values[] = "(".((int) $mailId).", '".$a[0]."', '".$this->db->escape($a[1])."', ".($name !== '' ? "'".$this->db->escape($name)."'" : 'NULL').")";
		}
		if ($values) {
			$this->db->query("INSERT INTO ".$this->db->prefix()."inbox_mail_address (fk_mail, type, email, name) VALUES ".implode(', ', $values));
		}
	}

	/**
	 * New flags and keywords of mails of a folder
	 *
	 * @param int                            $folderId Folder
	 * @param array<int,array<string,mixed>> $changed  remote_id + seen, answered, flagged, draft, keywords
	 * @return int                                      Rows updated
	 */
	public function updateFlags($folderId, array $changed)
	{
		$n = 0;
		foreach ($changed as $c) {
			$sql = "UPDATE ".$this->db->prefix()."inbox_mail SET seen = ".((int) $c['seen']).", answered = ".((int) $c['answered']).",";
			$sql .= " flagged = ".((int) $c['flagged']).", draft = ".((int) $c['draft']).",";
			$sql .= " keywords = ".(empty($c['keywords']) ? 'NULL' : "'".$this->db->escape(dol_trunc(implode(' ', (array) $c['keywords']), 1024, 'right', 'UTF-8', 1))."'").",";
			$sql .= " date_index = '".$this->db->idate(dol_now())."'";
			$sql .= " WHERE fk_folder = ".((int) $folderId)." AND remote_id = '".$this->db->escape((string) $c['remote_id'])."'";
			if ($this->db->query($sql)) {
				$n += (int) $this->db->affected_rows(true);
			}
		}

		return $n;
	}

	/**
	 * Remove mails of a folder from the index (deleted or moved elsewhere on the server)
	 *
	 * @param int      $folderId  Folder
	 * @param string[] $remoteIds Identifiers
	 * @return int                 Rows removed
	 */
	public function removeMany($folderId, array $remoteIds)
	{
		$p = $this->db->prefix();
		$n = 0;
		foreach (array_chunk($remoteIds, 500) as $chunk) {
			$in = implode(',', array_map(function ($r) {
				return "'".$this->db->escape((string) $r)."'";
			}, $chunk));
			$this->db->query("DELETE FROM ".$p."inbox_mail_address WHERE fk_mail IN (SELECT rowid FROM ".$p."inbox_mail WHERE fk_folder = ".((int) $folderId)." AND remote_id IN (".$in."))");
			if ($this->db->query("DELETE FROM ".$p."inbox_mail WHERE fk_folder = ".((int) $folderId)." AND remote_id IN (".$in.")")) {
				$n += (int) $this->db->affected_rows(true);
			}
		}

		return $n;
	}

	/**
	 * Empty the index of a folder (UIDVALIDITY changed, folder gone, rebuild)
	 *
	 * @param int $folderId Folder
	 * @return void
	 */
	public function clearFolder($folderId)
	{
		$p = $this->db->prefix();
		$this->db->query("DELETE FROM ".$p."inbox_mail_address WHERE fk_mail IN (SELECT rowid FROM ".$p."inbox_mail WHERE fk_folder = ".((int) $folderId).")");
		$this->db->query("DELETE FROM ".$p."inbox_mail WHERE fk_folder = ".((int) $folderId));
	}

	/**
	 * @param int $folderId Folder
	 * @return array{count:int,oldest:int}
	 */
	public function folderStats($folderId)
	{
		$row = $this->db->getRow("SELECT COUNT(rowid) AS n, MIN(date_received) AS oldest FROM ".$this->db->prefix()."inbox_mail WHERE fk_folder = ".((int) $folderId));

		return array('count' => is_object($row) ? (int) $row->n : 0, 'oldest' => (is_object($row) && $row->oldest) ? (int) $this->db->jdate($row->oldest) : 0);
	}

	/**
	 * Identifiers of the mails of a folder in the index
	 *
	 * @param int $folderId Folder
	 * @return string[]
	 */
	public function remoteIds($folderId)
	{
		$out = array();
		$sql = "SELECT remote_id FROM ".$this->db->prefix()."inbox_mail WHERE fk_folder = ".((int) $folderId);
		$res = $this->db->query($sql);
		while ($res && ($o = $this->db->fetch_object($res))) {
			$out[] = (string) $o->remote_id;
		}

		return $out;
	}
}
