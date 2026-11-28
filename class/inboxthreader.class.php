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
 * \brief   Conversations: key of the thread of each mail of the index (1.5.0)
 */

/**
 * A conversation crosses the folders of a mailbox (inbox, sent, archives). The thread of a mail is found, in order:
 * 1. the mails it answers (In-Reply-To, the whole References chain);
 * 2. its copies in other folders (same Message-ID);
 * 3. the answers that arrived before it (their In-Reply-To is its Message-ID);
 * 4. otherwise the same normalised subject (RE:, TR:, Fwd:, AW:... removed), the same correspondent and less than
 *    30 days between the two mails. The correspondent is the other side: the sender of a mail received, the
 *    recipients of a mail sent; the addresses of the mailbox (account, identities) never count, else every mail
 *    sent would match every mail received (all written to the mailbox).
 *    A mail sent by a data source (site form, 1.92.1) is from the site, for many requesters: its correspondent is
 *    the requester read in its headers; without a requester known, it never joins by the subject, else every mail of
 *    the form would be one conversation.
 * When a mail links two threads, they become one (the oldest key is kept). The key is opaque.
 *
 * A mail can start a new conversation (cut point, 1.139.0, inbox_mailref.thread_root by Message-ID): the climb up
 * the parents of a mail (nearest first) stops on the first cut point met, included; the cut point itself takes a key
 * of its own. Cut by a user, or because its subject is not the one of the mail it answers (a new request sent as a
 * reply to the previous one); a mail joined back by a user is never cut again automatically.
 */
class InboxThreader
{
	/** Days between two mails of the same subject and correspondent for the fallback */
	const SUBJECT_DAYS = 30;

	/** Mails without key done per call of backfill() */
	const BACKFILL = 1000;

	/** Cut points (inbox_mailref.thread_root): cut by a user, cut because the subject changed, joined back */
	const ROOT_USER = 1;
	const ROOT_SUBJECT = 2;
	const ROOT_JOINED = -1;

	/** @var DoliDB */
	protected $db;

	/** @var array<int,string[]> Addresses of each mailbox (account and identities), lower case */
	protected $own = array();

	/** @var string[]|null Trusted senders of the active data sources (addresses or domains) */
	protected $sourceSenders = null;

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct(DoliDB $db)
	{
		$this->db = $db;
	}

	/**
	 * Subject for the comparison: answer and forward prefixes removed (several languages, repeated, « RE[2]: »),
	 * prefixes of the calendars on an invitation and its answers too (« Accepted: », « Invitation mise à jour : »),
	 * lower case, spaces collapsed
	 *
	 * @param string $subject Subject
	 * @return string
	 */
	public static function normalizeSubject($subject)
	{
		$s = trim((string) $subject);
		$prefix = '/^\s*(?:re|r|tr|fw|fwd|aw|wg|sv|vs|antw|rif|rv|enc|réf|ref)\s*(?:\[\d+\]|\(\d+\))?\s*:\s*/iu';
		// A calendar puts its own prefix before the title of the event, in the language of each correspondent: an
		// answer « Accepté : X » to « Invitation mise à jour : X » is the same subject (1.145.2)
		$calendar = '/^\s*(?:'.implode('|', array(
			'updated\s+invitation', 'invitation\s+mise\s+à\s+jour', 'aktualisierte\s+einladung', 'invito\s+aggiornato', 'invitación\s+actualizada',
			'invitation', 'einladung', 'invito', 'invitación',
			'tentatively\s+accepted', 'accepté\s+provisoirement', 'mit\s+vorbehalt\s+angenommen',
			'accepted', 'accepté', 'angenommen', 'accettato', 'aceptado',
			'declined', 'refusé', 'abgelehnt', 'rifiutato', 'rechazado',
			'tentative', 'provisoire', 'provvisorio', 'provisional',
			'canceled\s+event', 'cancelled\s+event', 'événement\s+annulé', 'canceled', 'cancelled', 'annulé', 'abgesagt', 'annullato', 'cancelado',
		)).')\s*:\s*/iu';
		do {
			$before = $s;
			$s = preg_replace($prefix, '', $s);
			$s = preg_replace($calendar, '', $s);
		} while ($s !== $before && $s !== '');

		return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $s)), 'UTF-8');
	}

	/** Similarity of the letters for a typo fixed (same numbers), in % */
	const SUBJECT_SIMILAR = 85;

	/**
	 * Two mails speak of the same thing (rules measured on a year of a real mailbox, 1.139.0):
	 * - letters and digits only, the prefixes « Re: », « Fwd: »... removed: the spaces a long header gets when folded
	 *   (« siegfri ed », « B L ») and the punctuation (« l’état » / « létat ») never separate;
	 * - one contains the other: « URGENT - X », « [Ticket #12] X »;
	 * - a reference in common (5 characters or more, 4 digits or more): « RQ2511-28231 » and « [AG] Offer - your
	 *   enquiry no. RQ2511-28231 », « Annule et remplace Devis DVV2603662 »;
	 * - the same numbers and almost the same letters: a typo fixed (« exceptionelle »).
	 * Another number (« ROND 304L » / « ROND 316L », another order) is another subject. An empty subject never
	 * separates.
	 *
	 * @param string|null $a Subject
	 * @param string|null $b Subject
	 * @return bool
	 */
	public static function sameSubject($a, $b)
	{
		$na = self::normalizeSubject((string) $a);
		$nb = self::normalizeSubject((string) $b);
		$x = (string) preg_replace('/[^\p{L}\p{N}]+/u', '', $na);
		$y = (string) preg_replace('/[^\p{L}\p{N}]+/u', '', $nb);
		if ($x === '' || $y === '' || $x === $y || mb_strpos($x, $y) !== false || mb_strpos($y, $x) !== false) {
			return true;
		}
		if (array_intersect(self::subjectRefs($na), self::subjectRefs($nb))) {
			return true;
		}
		preg_match_all('/\d+/', $x, $dx);
		preg_match_all('/\d+/', $y, $dy);
		$nx = array_unique($dx[0]);
		$ny = array_unique($dy[0]);
		sort($nx);
		sort($ny);
		if ($nx !== $ny) {
			return false;
		}
		similar_text($x, $y, $percent);

		return $percent >= self::SUBJECT_SIMILAR;
	}

	/**
	 * References of a normalised subject: words of 5 characters or more with 4 digits or more (RQ2511-28231,
	 * DEVIS2606219, HT-26/04328)
	 *
	 * @param string $subject Subject normalised
	 * @return string[]
	 */
	protected static function subjectRefs($subject)
	{
		preg_match_all('/[\p{L}\p{N}][\p{L}\p{N}\-\/]{4,}/u', (string) $subject, $m);

		return array_values(array_filter(array_unique($m[0]), function ($w) {
			return preg_match_all('/\d/', $w) >= 4;
		}));
	}

	/**
	 * Parents of a mail, the nearest first: In-Reply-To, then References from the end
	 *
	 * @param object $r Row (message_id, in_reply_to, refs)
	 * @return string[] 50 at most
	 */
	public static function parentsOf($r)
	{
		$msgid = (string) $r->message_id;
		$refs = preg_split('/\s+/', trim((string) $r->refs)) ?: array();
		$out = array();
		foreach (array_merge(array((string) $r->in_reply_to), array_reverse($refs)) as $id) {
			if ($id !== '' && $id !== $msgid && !isset($out[$id])) {
				$out[$id] = true;
			}
		}

		return array_slice(array_keys($out), 0, 50);
	}

	/**
	 * Cut points among Message-IDs
	 *
	 * @param string[] $messageIds Message-IDs
	 * @return array<string,int> Message-ID => thread_root (1, 2 or -1), only the ones set
	 */
	public function rootsOf(array $messageIds)
	{
		global $conf;

		$messageIds = array_values(array_filter(array_map('strval', $messageIds)));
		if (!$messageIds) {
			return array();
		}
		$out = array();
		foreach (array_chunk($messageIds, 500) as $chunk) {
			$rows = $this->db->getRows("SELECT message_id, thread_root FROM ".$this->db->prefix()."inbox_mailref WHERE entity = ".((int) $conf->entity)." AND thread_root <> 0"
				." AND message_id IN (".implode(',', array_map(function ($v) {
					return "'".$this->db->escape($v)."'";
				}, $chunk)).") LIMIT ".(count($chunk) * 4));
			foreach (is_array($rows) ? $rows : array() as $r) {
				$out[(string) $r->message_id] = (int) $r->thread_root;
			}
		}

		return $out;
	}

	/**
	 * Addresses of a mailbox: its account and its identities (active or not: a mail sent stays one of the mailbox)
	 *
	 * @param int $accountId Mailbox
	 * @return string[] Lower case
	 */
	protected function ownAddresses($accountId)
	{
		if (!isset($this->own[$accountId])) {
			$p = $this->db->prefix();
			$list = array();
			$rows = $this->db->getRows("SELECT email FROM ".$p."inbox_account WHERE rowid = ".((int) $accountId)
				." UNION SELECT email FROM ".$p."inbox_identity WHERE fk_account = ".((int) $accountId)." LIMIT 500");
			foreach (is_array($rows) ? $rows : array() as $r) {
				if ((string) $r->email !== '') {
					$list[] = strtolower(trim((string) $r->email));
				}
			}
			$this->own[$accountId] = array_values(array_unique($list));
		}

		return $this->own[$accountId];
	}

	/**
	 * The other side of a mail: its sender when the mailbox got it, else its recipients (to and cc); never an
	 * address of the mailbox
	 *
	 * @param string   $from   Sender
	 * @param string   $toText Recipients (« Name <a@b>, c@d »)
	 * @param string   $ccText Copies
	 * @param string[] $own    Addresses of the mailbox
	 * @return string[] Lower case, empty for a mail written to oneself
	 */
	public static function correspondents($from, $toText, $ccText, array $own)
	{
		$from = strtolower(trim((string) $from));
		if ($from !== '' && !in_array($from, $own, true)) {
			return array($from);
		}
		preg_match_all('/[^\s<>,;"\']+@[^\s<>,;"\']+/', $toText.', '.$ccText, $m);

		return array_values(array_diff(array_unique(array_map('strtolower', $m[0])), $own));
	}

	/**
	 * Trusted senders of the active data sources: their mails speak for a requester, not for themselves
	 *
	 * @return string[]
	 */
	protected function sourceSenders()
	{
		if ($this->sourceSenders === null) {
			require_once __DIR__.'/inboxdatasource.class.php';
			$list = array();
			foreach ((new InboxDataSource($this->db))->active() as $s) {
				$list = array_merge($list, $s['sender_list']);
			}
			$this->sourceSenders = array_values(array_unique($list));
		}

		return $this->sourceSenders;
	}

	/**
	 * Correspondents of a row of the index for the fallback on the subject: the requester of a mail of a data source,
	 * nobody for a mail of a source whose requester is unknown, else correspondents()
	 *
	 * @param object   $r   Row (from_email, to_text, cc_text, requester_email)
	 * @param string[] $own Addresses of the mailbox
	 * @return string[]
	 */
	protected function correspondentsOf($r, array $own)
	{
		$requester = strtolower(trim((string) ($r->requester_email ?? '')));
		if ($requester !== '') {
			return array($requester);
		}
		$senders = $this->sourceSenders();
		if ($senders && InboxDataSource::senderIn(strtolower(trim((string) $r->from_email)), $senders)) {
			return array();
		}

		return self::correspondents((string) $r->from_email, (string) $r->to_text, (string) $r->cc_text, $own);
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
			$rows = $this->db->getRows("SELECT rowid, fk_account, message_id, in_reply_to, refs, subject, from_email, to_text, cc_text, requester_email, date_received, date_mail, size FROM ".$p."inbox_mail"
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
		$parents = self::parentsOf($r);
		$roots = $this->rootsOf(array_merge($parents, array($msgid)));
		$root = (int) ($roots[$msgid] ?? 0);
		// A new subject in answer to a mail of another subject: a new conversation (1.139.0)
		if ($root === 0 && $msgid !== '' && $parents && getDolGlobalInt('INBOX_THREAD_SPLIT_SUBJECT', 1)) {
			$parent = $this->db->getRow("SELECT subject FROM ".$p."inbox_mail WHERE fk_account = ".((int) $accountId)." AND message_id = ".$esc($parents[0])." AND rowid <> ".((int) $r->rowid)." ORDER BY rowid");
			if (is_object($parent) && !self::sameSubject($parent->subject, $r->subject) && $this->setRoot($r, self::ROOT_SUBJECT)) {
				$root = self::ROOT_SUBJECT;
			}
		}
		if ($root > 0) {
			// Its own conversation: the same key for its copies, never the one of its old first mail
			$key = substr(sha1($accountId.':'.$msgid.':root'), 0, 32);
			$this->db->query("UPDATE ".$p."inbox_mail SET thread_key = ".$esc($key)." WHERE rowid = ".((int) $r->rowid));

			return $key;
		}
		// 1. Parents, up to the first cut point met (included)
		foreach ($parents as $i => $id) {
			if (($roots[$id] ?? 0) > 0) {
				$parents = array_slice($parents, 0, $i + 1);
				break;
			}
		}
		$where = array();
		if ($parents) {
			$where[] = "message_id IN (".implode(',', array_map($esc, $parents)).")";
		}
		if ($msgid !== '') {
			// 2. Copies in other folders
			$where[] = "message_id = ".$esc($msgid);
		}
		if ($where) {
			$found = $this->db->getRows("SELECT DISTINCT thread_key FROM ".$p."inbox_mail WHERE fk_account = ".$accountId." AND rowid <> ".((int) $r->rowid)
				." AND thread_key IS NOT NULL AND (".implode(' OR ', $where).") LIMIT 50");
			foreach (is_array($found) ? $found : array() as $f) {
				$keys[(string) $f->thread_key] = true;
			}
		}
		if ($msgid !== '') {
			// 3. Answers arrived before, except the ones that start a conversation of their own
			$answers = $this->db->getRows("SELECT DISTINCT thread_key, message_id FROM ".$p."inbox_mail WHERE fk_account = ".$accountId." AND rowid <> ".((int) $r->rowid)
				." AND thread_key IS NOT NULL AND in_reply_to = ".$esc($msgid)." LIMIT 50");
			$answers = is_array($answers) ? $answers : array();
			$cut = $this->rootsOf(array_map(function ($a) {
				return (string) $a->message_id;
			}, $answers));
			foreach ($answers as $a) {
				if (($cut[(string) $a->message_id] ?? 0) <= 0) {
					$keys[(string) $a->thread_key] = true;
				}
			}
		}
		// 4. Same subject, same correspondent, less than 30 days
		if (!$keys) {
			$norm = self::normalizeSubject($r->subject);
			$date = $r->date_received ? $r->date_received : $r->date_mail;
			$own = $this->ownAddresses($accountId);
			$mine = $this->correspondentsOf($r, $own);
			if (mb_strlen($norm, 'UTF-8') >= 4 && $date && $mine) {
				$ts = (int) $this->db->jdate($date);
				$like = "%".$this->db->escapeforlike($norm)."%";
				$cands = $this->db->getRows("SELECT rowid, subject, from_email, to_text, cc_text, requester_email, thread_key, COALESCE(date_received, date_mail) as d FROM ".$p."inbox_mail WHERE fk_account = ".$accountId." AND rowid <> ".((int) $r->rowid)
					." AND thread_key IS NOT NULL AND subject LIKE '".$this->db->escape($like)."'"
					." AND COALESCE(date_received, date_mail) BETWEEN '".$this->db->idate($ts - self::SUBJECT_DAYS * 86400)."' AND '".$this->db->idate($ts + self::SUBJECT_DAYS * 86400)."' LIMIT 200");
				$best = null;
				foreach (is_array($cands) ? $cands : array() as $c) {
					if (self::normalizeSubject($c->subject) !== $norm) {
						continue;
					}
					// Same correspondent on the other side (the mailbox itself never counts, a source counts for its requester)
					if (array_intersect($mine, $this->correspondentsOf($c, $own)) && ($best === null || (string) $c->d > (string) $best->d)) {
						$best = $c;
					}
				}
				// Two conversations of the same subject (one cut from the other): the most recent, never both merged
				if ($best !== null) {
					$keys[(string) $best->thread_key] = true;
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
				// Ignored or watched (1.11.0): the mark follows the thread kept
				require_once __DIR__.'/inboxthreadmark.class.php';
				InboxThreadMark::merge($this->db, $accountId, $key, array_slice($list, 1));
			}
		} else {
			$key = substr(sha1($accountId.':'.($msgid !== '' ? $msgid : 'row'.$r->rowid)), 0, 32);
		}
		$this->db->query("UPDATE ".$p."inbox_mail SET thread_key = ".$esc($key)." WHERE rowid = ".((int) $r->rowid));

		return $key;
	}

	/**
	 * Cut point of a mail (inbox_mailref.thread_root), its mailref made when it has none
	 *
	 * @param object $r    Row of the index (rowid, fk_account, message_id, date_mail, from_email, subject, size)
	 * @param int    $root ROOT_USER, ROOT_SUBJECT, ROOT_JOINED or 0
	 * @return bool
	 */
	public function setRoot($r, $root)
	{
		require_once __DIR__.'/inboxmailref.class.php';
		$ref = (new InboxMailRef($this->db))->fetchOrCreate($r);
		if ($ref <= 0) {
			return false;
		}

		return (bool) $this->db->query("UPDATE ".$this->db->prefix()."inbox_mailref SET thread_root = ".((int) $root)." WHERE rowid = ".((int) $ref));
	}

	/**
	 * Keys given again to some conversations of a mailbox (a cut made or undone): their mails in the order of arrival.
	 * The conversation holding the oldest mail of a key keeps that key, so its marks (ignored, watched) stay.
	 *
	 * @param int      $accountId Mailbox
	 * @param string[] $keys      Keys of the conversations
	 * @return int Mails done
	 */
	public function rethread($accountId, array $keys)
	{
		$p = $this->db->prefix();
		$accountId = (int) $accountId;
		$keys = array_values(array_unique(array_filter(array_map('strval', $keys))));
		if (!$keys) {
			return 0;
		}
		$in = implode(',', array_map(function ($k) {
			return "'".$this->db->escape($k)."'";
		}, $keys));
		$rows = $this->db->getRows("SELECT rowid, thread_key FROM ".$p."inbox_mail WHERE fk_account = ".$accountId." AND thread_key IN (".$in.")"
			." ORDER BY COALESCE(date_received, date_mail) ASC, rowid ASC LIMIT 5000");
		if (!is_array($rows) || !$rows) {
			return 0;
		}
		$firstOf = array();
		$ids = array();
		foreach ($rows as $r) {
			$ids[] = (int) $r->rowid;
			if (!isset($firstOf[(string) $r->thread_key])) {
				$firstOf[(string) $r->thread_key] = (int) $r->rowid;
			}
		}
		$this->db->query("UPDATE ".$p."inbox_mail SET thread_key = NULL WHERE rowid IN (".implode(',', $ids).")");
		$done = 0;
		foreach (array_chunk($ids, 200) as $chunk) {
			$done += $this->assign($accountId, $chunk);
		}
		// The oldest conversation first: a conversation made of two (joined back) takes the key of the oldest one only
		$claimed = array();
		foreach ($firstOf as $old => $rowid) {
			$now = $this->db->getRow("SELECT thread_key FROM ".$p."inbox_mail WHERE rowid = ".((int) $rowid));
			$new = is_object($now) ? (string) $now->thread_key : '';
			if ($new === '' || isset($claimed[$new])) {
				continue;
			}
			$taken = $this->db->getRow("SELECT rowid FROM ".$p."inbox_mail WHERE fk_account = ".$accountId." AND thread_key = '".$this->db->escape($old)."' LIMIT 1");
			if ($new !== $old && !is_object($taken)) {
				$this->db->query("UPDATE ".$p."inbox_mail SET thread_key = '".$this->db->escape($old)."' WHERE fk_account = ".$accountId." AND thread_key = '".$this->db->escape($new)."'");
				$new = $old;
			}
			$claimed[$new] = true;
		}

		return $done;
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

	/**
	 * Conversations to make again after a data source is saved (1.92.1): the threads holding a mail of a trusted sender
	 * lose their key, backfill() gives them again with the rule of the sources (every mail of a form was one thread).
	 * The links to Dolibarr stay on each mail; a mark (ignored, watched) stays on the conversation that keeps its key.
	 *
	 * @param int   $accountId Mailbox
	 * @param float $deadline  Microtime limit for the keys given at once (the rest at the next synchronisation)
	 * @return int Mails whose key was taken away
	 */
	public function rethreadSources($accountId, $deadline)
	{
		$this->sourceSenders = null;
		$senders = $this->sourceSenders();
		if (!$senders) {
			return 0;
		}
		$p = $this->db->prefix();
		$accountId = (int) $accountId;
		$emails = array();
		$rows = $this->db->getRows("SELECT DISTINCT from_email FROM ".$p."inbox_mail WHERE fk_account = ".$accountId." AND from_email IS NOT NULL LIMIT 50000");
		foreach (is_array($rows) ? $rows : array() as $r) {
			$from = strtolower(trim((string) $r->from_email));
			if (InboxDataSource::senderIn($from, $senders)) {
				$emails[] = "'".$this->db->escape((string) $r->from_email)."'";
			}
		}
		if (!$emails) {
			return 0;
		}
		$keys = array();
		foreach (array_chunk($emails, 200) as $chunk) {
			$found = $this->db->getRows("SELECT DISTINCT thread_key FROM ".$p."inbox_mail WHERE fk_account = ".$accountId." AND thread_key IS NOT NULL"
				." AND from_email IN (".implode(',', $chunk).") LIMIT 20000");
			foreach (is_array($found) ? $found : array() as $f) {
				$keys["'".$this->db->escape((string) $f->thread_key)."'"] = true;
			}
		}
		$done = 0;
		foreach (array_chunk(array_keys($keys), 500) as $chunk) {
			$res = $this->db->query("UPDATE ".$p."inbox_mail SET thread_key = NULL WHERE fk_account = ".$accountId." AND thread_key IN (".implode(',', $chunk).")");
			if ($res) {
				$done += (int) $this->db->affected_rows($res);
			}
		}
		if ($done) {
			$this->backfill($accountId, $deadline);
		}

		return $done;
	}
}
