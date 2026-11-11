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
 * \file    inbox/class/inboxfulltext.class.php
 * \ingroup inbox
 * \brief   Full text search with Meilisearch (lot W7 1.20.0): option of the admin, mailboxes chosen, one index each
 */

require_once __DIR__.'/inboxmeili.class.php';
require_once __DIR__.'/inboxaccount.class.php';
require_once __DIR__.'/inboxmimeparser.class.php';
require_once __DIR__.'/connector/inboxconnectorfactory.class.php';

/**
 * - One index per mailbox, named <prefix>_<entity>_<mailbox>: a search only names the indexes of the mailboxes the
 *   user may read, so it can never reach another one.
 * - inbox works with its own key, limited to the indexes <prefix>_*, created with the master key when the option is
 *   turned on; both are encrypted (dolEncrypt).
 * - The scheduled job sends the mails in the order of their row in the index (last_id); a mail moved has a new row and
 *   is sent again. Once a day the documents whose row is gone are removed. The results are always read again through
 *   the index of Dolibarr (rights, filters): a document left behind is never shown.
 */
class InboxFulltext
{
	/** Mails sent per batch */
	const BATCH = 100;

	/** Bytes of the text of a mail kept in the index */
	const TEXT_MAX = 65536;

	/** @var DoliDB */
	protected $db;

	/** @var string */
	public $error = '';

	/** @var string Output of the scheduled job */
	public $output = '';

	/** @var InboxMeili|null Client (replaceable in the tests) */
	public $client = null;

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct(DoliDB $db)
	{
		$this->db = $db;
	}

	/**
	 * @return bool The option is on
	 */
	public static function enabled()
	{
		return getDolGlobalInt('INBOX_MEILI_ENABLED') === 1 && getDolGlobalString('INBOX_MEILI_URL') !== '' && getDolGlobalString('INBOX_MEILI_WORKKEY') !== '';
	}

	/**
	 * @return string Prefix proposed: inbox + 6 characters of the unique identifier of the instance
	 */
	public static function defaultPrefix()
	{
		global $conf;

		$seed = !empty($conf->file->instance_unique_id) ? (string) $conf->file->instance_unique_id : DOL_DATA_ROOT;

		return 'inbox'.substr(md5($seed), 0, 6);
	}

	/**
	 * @return string Prefix of the indexes
	 */
	public static function prefix()
	{
		return getDolGlobalString('INBOX_MEILI_PREFIX', self::defaultPrefix());
	}

	/**
	 * @param string $prefix Prefix typed
	 * @return bool
	 */
	public static function validPrefix($prefix)
	{
		return (bool) preg_match('/^[a-z][a-z0-9]{1,29}$/', (string) $prefix);
	}

	/**
	 * @param int $accountId Mailbox
	 * @return string Index of the mailbox
	 */
	public static function indexUid($accountId)
	{
		global $conf;

		return self::prefix().'_'.((int) $conf->entity).'_'.((int) $accountId);
	}

	/**
	 * @return InboxMeili Client with the key of inbox
	 */
	public function client()
	{
		if ($this->client === null) {
			$this->client = new InboxMeili(getDolGlobalString('INBOX_MEILI_URL'), (string) dolDecrypt(getDolGlobalString('INBOX_MEILI_WORKKEY')));
		}

		return $this->client;
	}

	/**
	 * @param int[] $accountIds Mailboxes
	 * @return array<int,object> Mailbox => row of llx_inbox_fulltext (mailboxes indexed only)
	 */
	public function states(array $accountIds)
	{
		$accountIds = array_values(array_filter(array_map('intval', $accountIds)));
		if (!$accountIds) {
			return array();
		}
		$rows = $this->db->getRows("SELECT rowid, fk_account, last_id, state, error, date_reconcile FROM ".$this->db->prefix()."inbox_fulltext WHERE fk_account IN (".implode(',', $accountIds).") LIMIT 10000");
		$out = array();
		foreach (is_array($rows) ? $rows : array() as $r) {
			$out[(int) $r->fk_account] = $r;
		}

		return $out;
	}

	/**
	 * State shown on the card of a mailbox
	 *
	 * @param int $accountId Mailbox
	 * @return array{on:bool,state:string,error:string,sent:int,total:int}
	 */
	public function accountStatus($accountId)
	{
		$st = $this->states(array($accountId));
		$row = $st[(int) $accountId] ?? null;
		$total = (int) $this->db->getRow("SELECT COUNT(rowid) AS n FROM ".$this->db->prefix()."inbox_mail WHERE fk_account = ".((int) $accountId))->n;
		$sent = $row ? (int) $this->db->getRow("SELECT COUNT(rowid) AS n FROM ".$this->db->prefix()."inbox_mail WHERE fk_account = ".((int) $accountId)." AND rowid <= ".((int) $row->last_id))->n : 0;

		return array('on' => (bool) $row, 'state' => $row ? (string) $row->state : '', 'error' => $row ? (string) $row->error : '', 'sent' => $sent, 'total' => $total);
	}

	/**
	 * Index a mailbox in Meilisearch, or stop (its index is removed)
	 *
	 * @param int  $accountId Mailbox
	 * @param bool $on        Index it
	 * @return bool
	 */
	public function setAccount($accountId, $on)
	{
		global $conf;

		$this->error = '';
		$accountId = (int) $accountId;
		if (!$on) {
			if (!$this->dropIndex($accountId)) {
				return false;
			}
			return (bool) $this->db->query("DELETE FROM ".$this->db->prefix()."inbox_fulltext WHERE fk_account = ".$accountId);
		}
		if (!self::enabled()) {
			$this->error = 'Meilisearch is not enabled';
			return false;
		}
		if (!$this->client()->ensureIndex(self::indexUid($accountId))) {
			$this->error = $this->client()->error;
			return false;
		}
		if ($this->states(array($accountId))) {
			return true;
		}

		return (bool) $this->db->query("INSERT INTO ".$this->db->prefix()."inbox_fulltext (entity, fk_account, last_id, state, date_creation) VALUES ("
			.((int) $conf->entity).", ".$accountId.", 0, 'indexing', '".$this->db->idate(dol_now())."')");
	}

	/**
	 * Remove the index of a mailbox in Meilisearch (best effort when the option is off or Meilisearch is gone)
	 *
	 * @param int $accountId Mailbox
	 * @return bool Removed or nothing to remove
	 */
	public function dropIndex($accountId)
	{
		if (!$this->states(array((int) $accountId)) || getDolGlobalString('INBOX_MEILI_WORKKEY') === '') {
			return true;
		}
		$ok = $this->client()->deleteIndex(self::indexUid((int) $accountId));
		if (!$ok) {
			$this->error = $this->client()->error;
		}

		return $ok;
	}

	/**
	 * Option off: every index of the prefix removed, the key of inbox removed, the mailboxes forgotten
	 *
	 * @return bool
	 */
	public function disableAll()
	{
		global $conf;

		$this->error = '';
		$master = new InboxMeili(getDolGlobalString('INBOX_MEILI_URL'), (string) dolDecrypt(getDolGlobalString('INBOX_MEILI_KEY')));
		$work = $this->client();
		foreach ($work->indexes(self::prefix().'_'.((int) $conf->entity)) as $uid) {
			if (!$work->deleteIndex($uid)) {
				$this->error = $work->error;
				return false;
			}
		}
		if (getDolGlobalString('INBOX_MEILI_WORKKEY_UID') !== '') {
			$master->deleteKey(getDolGlobalString('INBOX_MEILI_WORKKEY_UID'));
		}
		$this->db->query("DELETE FROM ".$this->db->prefix()."inbox_fulltext WHERE entity = ".((int) $conf->entity));
		require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
		dolibarr_set_const($this->db, 'INBOX_MEILI_ENABLED', '0', 'chaine', 0, '', $conf->entity);
		dolibarr_del_const($this->db, 'INBOX_MEILI_WORKKEY', $conf->entity);
		dolibarr_del_const($this->db, 'INBOX_MEILI_WORKKEY_UID', $conf->entity);

		return true;
	}

	/**
	 * Scheduled job, every minute: the next mails of each mailbox indexed, within a time budget; once a day, the
	 * documents whose row is gone are removed
	 *
	 * @return int 0 OK, < 0 errors
	 */
	public function cronIndex()
	{
		global $conf;

		$this->output = '';
		if (!self::enabled()) {
			$this->output = 'Meilisearch off';
			return 0;
		}
		$start = microtime(true);
		$deadline = $start + max(10, getDolGlobalInt('INBOX_MEILI_BUDGET', 40));
		$rows = $this->db->getRows("SELECT ft.fk_account FROM ".$this->db->prefix()."inbox_fulltext as ft INNER JOIN ".$this->db->prefix()."inbox_account as a ON a.rowid = ft.fk_account"
			." WHERE ft.entity = ".((int) $conf->entity)." AND a.status = ".InboxAccount::STATUS_ACTIVE." AND a.protected = 0 ORDER BY ft.tms LIMIT 500");
		$sent = 0;
		$errors = 0;
		foreach (is_array($rows) ? $rows : array() as $r) {
			if (microtime(true) > $deadline) {
				break;
			}
			$n = $this->indexAccount((int) $r->fk_account, $deadline);
			if ($n < 0) {
				$errors++;
			} else {
				$sent += $n;
			}
			$this->reconcile((int) $r->fk_account, $deadline);
		}
		$this->output = $sent.' mail(s) sent to Meilisearch, '.$errors.' error(s), '.round(microtime(true) - $start, 1).' s';

		return $errors ? -1 : 0;
	}

	/**
	 * Send the next mails of a mailbox, by batches, until the deadline
	 *
	 * @param int   $accountId Mailbox
	 * @param float $deadline  microtime(true)
	 * @return int Mails sent, -1 on error (kept on the row)
	 */
	public function indexAccount($accountId, $deadline)
	{
		$st = $this->states(array($accountId));
		$row = $st[(int) $accountId] ?? null;
		if (!$row) {
			return 0;
		}
		$account = new InboxAccount($this->db);
		if ($account->fetch((int) $accountId) <= 0 || !$account->canWorkNow()) {
			return 0;
		}
		$uid = self::indexUid((int) $accountId);
		$p = $this->db->prefix();
		$last = (int) $row->last_id;
		$sent = 0;
		$connector = null;
		while (microtime(true) < $deadline) {
			$mails = $this->db->getRows("SELECT m.rowid, m.fk_folder, m.remote_id, m.subject, m.from_name, m.from_email, m.to_text, m.cc_text, m.date_received, m.date_mail, f.path"
				." FROM ".$p."inbox_mail as m INNER JOIN ".$p."inbox_folder as f ON f.rowid = m.fk_folder"
				." WHERE m.fk_account = ".((int) $accountId)." AND m.rowid > ".$last." ORDER BY m.rowid LIMIT ".self::BATCH);
			if (!is_array($mails) || !$mails) {
				if ($row->state !== 'ready' || (string) $row->error !== '') {
					$this->db->query("UPDATE ".$p."inbox_fulltext SET state = 'ready', error = NULL WHERE rowid = ".((int) $row->rowid));
					$row->state = 'ready';
				}
				break;
			}
			if ($connector === null) {
				$error = '';
				$connector = InboxConnectorFactory::forAccount($this->db, $account, $error);
				if (!$connector) {
					return $this->fail($row, $error);
				}
			}
			$byFolder = array();
			foreach ($mails as $m) {
				$byFolder[(string) $m->path][] = $m;
			}
			$docs = array();
			foreach ($byFolder as $path => $list) {
				$starts = $connector->fetchStarts($path, array_map(function ($m) {
					return (string) $m->remote_id;
				}, $list));
				if ($starts === null) {
					$connector->disconnect();
					return $this->fail($row, $connector->getError());
				}
				foreach ($list as $m) {
					$docs[] = $this->document($m, $starts[(string) $m->remote_id] ?? '');
				}
			}
			if (!$this->client()->addDocuments($uid, $docs)) {
				if ($connector) {
					$connector->disconnect();
				}
				return $this->fail($row, $this->client()->error);
			}
			$last = (int) end($mails)->rowid;
			$sent += count($mails);
			$this->db->query("UPDATE ".$p."inbox_fulltext SET last_id = ".$last.", error = NULL WHERE rowid = ".((int) $row->rowid));
		}
		if ($connector) {
			$connector->disconnect();
		}

		return $sent;
	}

	/**
	 * @param object $row   Row of llx_inbox_fulltext
	 * @param string $error Error
	 * @return int -1
	 */
	protected function fail($row, $error)
	{
		$this->error = (string) $error;
		$this->db->query("UPDATE ".$this->db->prefix()."inbox_fulltext SET error = '".$this->db->escape(dol_trunc((string) $error, 500, 'right', 'UTF-8', 1))."' WHERE rowid = ".((int) $row->rowid));

		return -1;
	}

	/**
	 * Document of a mail: what is searched, nothing else (no flags: they change, and stay filtered by the index)
	 *
	 * @param object $m     Row of the index
	 * @param string $start Raw start of the mail (headers, first bytes of the body)
	 * @return array<string,mixed>
	 */
	public function document($m, $start)
	{
		$text = '';
		$names = array();
		if ($start !== '') {
			$msg = InboxMimeParser::parse($start);
			if ($msg) {
				$text = trim((string) $msg->bodyText) !== '' ? (string) $msg->bodyText : InboxMimeParser::htmlToText((string) $msg->bodyHtml);
				foreach ((array) $msg->attachments as $a) {
					if (!empty($a['name'])) {
						$names[] = (string) $a['name'];
					}
				}
			}
		}
		$text = trim((string) preg_replace('/[ \t\x{00A0}]+/u', ' ', preg_replace('/\s*\n\s*/u', "\n", $text)));
		if (strlen($text) > self::TEXT_MAX) {
			$text = mb_strcut($text, 0, self::TEXT_MAX, 'UTF-8');
		}
		$date = $m->date_received ?: $m->date_mail;

		return array(
			'id' => (int) $m->rowid,
			'folder' => (int) $m->fk_folder,
			'date' => $date ? (int) $this->db->jdate($date) : 0,
			'subject' => (string) $m->subject,
			'from' => trim($m->from_name.' '.$m->from_email),
			'to' => trim($m->to_text.' '.$m->cc_text),
			'attachments' => implode(' ', $names),
			'text' => $text,
		);
	}

	/**
	 * Once a day per mailbox: documents whose row is gone (mail removed, moved) taken out of the index
	 *
	 * @param int   $accountId Mailbox
	 * @param float $deadline  microtime(true)
	 * @param bool  $force     Even if done less than a day ago (tests)
	 * @return int Documents removed, -1 on error
	 */
	public function reconcile($accountId, $deadline, $force = false)
	{
		$st = $this->states(array($accountId));
		$row = $st[(int) $accountId] ?? null;
		if (!$row || (!$force && $row->date_reconcile && $this->db->jdate($row->date_reconcile) > dol_now() - 86400)) {
			return 0;
		}
		$uid = self::indexUid((int) $accountId);
		$removed = 0;
		for ($offset = 0; microtime(true) < $deadline; $offset += 1000) {
			$ids = $this->client()->documentIds($uid, $offset, 1000);
			if ($ids === null) {
				return -1;
			}
			if (!$ids) {
				$this->db->query("UPDATE ".$this->db->prefix()."inbox_fulltext SET date_reconcile = '".$this->db->idate(dol_now())."' WHERE rowid = ".((int) $row->rowid));
				break;
			}
			$found = $this->db->getRows("SELECT rowid FROM ".$this->db->prefix()."inbox_mail WHERE fk_account = ".((int) $accountId)." AND rowid IN (".implode(',', array_map('intval', $ids)).") LIMIT 1000");
			$keep = array_map(function ($r) {
				return (int) $r->rowid;
			}, is_array($found) ? $found : array());
			$gone = array_values(array_diff($ids, $keep));
			if ($gone) {
				if (!$this->client()->deleteDocuments($uid, $gone)) {
					return -1;
				}
				$removed += count($gone);
				// The pages move once the removal is applied: read this page again
				$offset -= count($gone);
			}
		}

		return $removed;
	}

	/**
	 * Search the indexes of the mailboxes given (readable by the user: checked by the caller)
	 *
	 * @param int[]    $accountIds Mailboxes of the search
	 * @param string[] $words      Words
	 * @return array{ids:int[],crops:array<int,string>,accounts:int[],ready:int[],error:bool}
	 */
	public function search(array $accountIds, array $words)
	{
		$out = array('ids' => array(), 'crops' => array(), 'accounts' => array(), 'ready' => array(), 'error' => false);
		$words = array_values(array_filter(array_map('strval', $words), function ($w) {
			return trim($w) !== '';
		}));
		if (!$words || !self::enabled()) {
			return $out;
		}
		$uids = array();
		foreach ($this->states($accountIds) as $accountId => $row) {
			$out['accounts'][] = (int) $accountId;
			if ($row->state === 'ready') {
				$out['ready'][] = (int) $accountId;
			}
			$uids[] = self::indexUid((int) $accountId);
		}
		if (!$uids) {
			return $out;
		}
		$client = $this->client();
		$client->timeout = 5;
		$hits = $client->federatedSearch($uids, implode(' ', $words), 1000);
		if ($hits === null) {
			dol_syslog(__METHOD__.' Meilisearch: '.$client->error, LOG_WARNING);
			$out['error'] = true;
			$out['ready'] = array();
			return $out;
		}
		foreach ($hits as $h) {
			if ($h['id'] > 0 && in_array($h['index'], $uids, true)) {
				$out['ids'][] = $h['id'];
				if ($h['crop'] !== '') {
					$out['crops'][$h['id']] = $h['crop'];
				}
			}
		}

		return $out;
	}
}
