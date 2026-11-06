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
 * \file    inbox/class/inboxthreader.class.php
 * \ingroup inbox
 * \brief   Conversations: key of the thread of each mail of the index (lot W3, 1.5.0)
 */

/**
 * A conversation crosses the folders of a mailbox (inbox, sent, archives). The thread of a mail is found, in order:
 * 1. the mails it answers (In-Reply-To, the whole References chain);
 * 2. its copies in other folders (same Message-ID);
 * 3. the answers that arrived before it (their In-Reply-To is its Message-ID);
 * 4. otherwise the same normalised subject (RE:, TR:, Fwd:, AW:... removed), the same correspondent and less than
 *    30 days between the two mails.
 * When a mail links two threads, they become one (the oldest key is kept). The key is opaque.
 */
class InboxThreader
{
	/** Days between two mails of the same subject and correspondent for the fallback */
	const SUBJECT_DAYS = 30;

	/** Mails without key done per call of backfill() */
	const BACKFILL = 1000;

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
	 * Subject for the comparison: answer and forward prefixes removed (several languages, repeated, « RE[2]: »),
	 * lower case, spaces collapsed
	 *
	 * @param string $subject Subject
	 * @return string
	 */
	public static function normalizeSubject($subject)
	{
		$s = trim((string) $subject);
		$prefix = '/^\s*(?:re|r|tr|fw|fwd|aw|wg|sv|vs|antw|rif|rv|enc|réf|ref)\s*(?:\[\d+\]|\(\d+\))?\s*:\s*/iu';
		do {
			$before = $s;
			$s = preg_replace($prefix, '', $s);
		} while ($s !== $before && $s !== '');

		return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $s)), 'UTF-8');
	}

	/**
	 * Key of the thread of mails of the index, and threads merged when a mail links them
	 *
	 * @param int   $accountId Mailbox
	 * @param int[] $mailIds   Rows (the order of arrival is better: parents first)
	 * @return int Mails done
	 */
	public function assign($accountId, array $mailIds)
	{
		$p = $this->db->prefix();
		$accountId = (int) $accountId;
		$done = 0;
		foreach (array_chunk(array_map('intval', $mailIds), 200) as $chunk) {
			$rows = $this->db->getRows("SELECT rowid, message_id, in_reply_to, refs, subject, from_email, to_text, date_received, date_mail FROM ".$p."inbox_mail"
				." WHERE rowid IN (".implode(',', $chunk).") ORDER BY COALESCE(date_received, date_mail) ASC LIMIT 1000");
			foreach (is_array($rows) ? $rows : array() as $r) {
				$this->assignOne($accountId, $r);
				$done++;
			}
		}

		return $done;
	}

	/**
	 * @param int    $accountId Mailbox
	 * @param object $r         Row of the index
	 * @return string Key given
	 */
	protected function assignOne($accountId, $r)
	{
		$p = $this->db->prefix();
		$keys = array();
		$esc = function ($v) {
			return "'".$this->db->escape((string) $v)."'";
		};
		$msgid = (string) $r->message_id;
		// 1. Parents
		$parents = array_filter(array_unique(array_merge(preg_split('/\s+/', trim((string) $r->refs)) ?: array(), array((string) $r->in_reply_to))), function ($id) use ($msgid) {
			return $id !== '' && $id !== $msgid;
		});
		$parents = array_slice(array_values($parents), -50);
		$where = array();
		if ($parents) {
			$where[] = "message_id IN (".implode(',', array_map($esc, $parents)).")";
		}
		if ($msgid !== '') {
			// 2. Copies in other folders, 3. answers arrived before
			$where[] = "message_id = ".$esc($msgid);
			$where[] = "in_reply_to = ".$esc($msgid);
		}
		if ($where) {
			$found = $this->db->getRows("SELECT DISTINCT thread_key FROM ".$p."inbox_mail WHERE fk_account = ".$accountId." AND rowid <> ".((int) $r->rowid)
				." AND thread_key IS NOT NULL AND (".implode(' OR ', $where).") LIMIT 50");
			foreach (is_array($found) ? $found : array() as $f) {
				$keys[(string) $f->thread_key] = true;
			}
		}
		// 4. Same subject, same correspondent, less than 30 days
		if (!$keys) {
			$norm = self::normalizeSubject($r->subject);
			$date = $r->date_received ? $r->date_received : $r->date_mail;
			$from = strtolower((string) $r->from_email);
			if (mb_strlen($norm, 'UTF-8') >= 4 && $date && $from !== '') {
				$ts = (int) $this->db->jdate($date);
				$like = "%".$this->db->escapeforlike($norm)."%";
				$cands = $this->db->getRows("SELECT rowid, subject, from_email, to_text, thread_key FROM ".$p."inbox_mail WHERE fk_account = ".$accountId." AND rowid <> ".((int) $r->rowid)
					." AND thread_key IS NOT NULL AND subject LIKE '".$this->db->escape($like)."'"
					." AND COALESCE(date_received, date_mail) BETWEEN '".$this->db->idate($ts - self::SUBJECT_DAYS * 86400)."' AND '".$this->db->idate($ts + self::SUBJECT_DAYS * 86400)."' LIMIT 200");
				foreach (is_array($cands) ? $cands : array() as $c) {
					if (self::normalizeSubject($c->subject) !== $norm) {
						continue;
					}
					$cFrom = strtolower((string) $c->from_email);
					// Same correspondent: same sender, or one wrote to the other
					if ($cFrom === $from || stripos((string) $c->to_text, $from) !== false || ($cFrom !== '' && stripos((string) $r->to_text, $cFrom) !== false)) {
						$keys[(string) $c->thread_key] = true;
					}
				}
			}
		}
		if ($keys) {
			$list = array_keys($keys);
			sort($list);
			$key = $list[0];
			if (count($list) > 1) {
				// This mail links several threads: one thread
				$this->db->query("UPDATE ".$p."inbox_mail SET thread_key = ".$esc($key)." WHERE fk_account = ".$accountId." AND thread_key IN (".implode(',', array_map($esc, array_slice($list, 1))).")");
			}
		} else {
			$key = substr(sha1($accountId.':'.($msgid !== '' ? $msgid : 'row'.$r->rowid)), 0, 32);
		}
		$this->db->query("UPDATE ".$p."inbox_mail SET thread_key = ".$esc($key)." WHERE rowid = ".((int) $r->rowid));

		return $key;
	}

	/**
	 * Mails indexed before 1.5.0 (or whose key was lost): keys given by small batches, the oldest first so that the
	 * parents get their key before their answers
	 *
	 * @param int   $accountId Mailbox
	 * @param float $deadline  Microtime limit
	 * @return int Mails done
	 */
	public function backfill($accountId, $deadline)
	{
		$done = 0;
		while (microtime(true) < $deadline) {
			$rows = $this->db->getRows("SELECT rowid FROM ".$this->db->prefix()."inbox_mail WHERE fk_account = ".((int) $accountId)." AND thread_key IS NULL"
				." ORDER BY COALESCE(date_received, date_mail) ASC LIMIT 200");
			if (!is_array($rows) || !$rows) {
				break;
			}
			$done += $this->assign($accountId, array_map(function ($r) {
				return (int) $r->rowid;
			}, $rows));
			if ($done >= self::BACKFILL) {
				break;
			}
		}

		return $done;
	}
}
