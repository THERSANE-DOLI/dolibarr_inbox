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
 * \file    inbox/class/inboxspamcheck.class.php
 * \ingroup inbox
 * \brief   Anti-spam engine of inbox at work (1.88.0): new mails of the inbox judged, learning from the users, training
 */

require_once __DIR__.'/inboxspamrspamd.class.php';
require_once __DIR__.'/inboxaccount.class.php';
require_once __DIR__.'/inboxsenderauth.class.php';
require_once __DIR__.'/inboxreader.class.php';
require_once __DIR__.'/inboxblockedsender.class.php';
require_once __DIR__.'/connector/inboxconnectorfactory.class.php';

/**
 * The new mails of the inbox are judged by the engine at the synchronisation (after the blocked senders), within a
 * time budget: only those without verdict of the server, unless the administrator asked for all. A mail judged spam
 * goes to the junk, never when its sender is known to Dolibarr or protected (a banner only), never a mail marked
 * « not junk ». « Junk » and « Not junk » of the users are learned by the scheduled job, never during the click.
 */
class InboxSpamCheck
{
	/** Mails judged per pass of a mailbox at most */
	const PER_PASS = 20;

	/** Mails judged only when indexed since (seconds): an engine stopped for days does not judge old mails */
	const RECENT = 172800;

	/** Learning: mails per pass of the scheduled job */
	const LEARN_PER_PASS = 50;

	/** Training: mails of each kind at most, of the last months */
	const TRAIN_MAX = 2000;
	const TRAIN_MONTHS = 6;

	/** @var DoliDB */
	protected $db;

	/** @var InboxSpamEngine|null */
	protected $engine;

	/** @var string */
	public $error = '';

	/** @var array<int,InboxConnectorInterface> */
	protected $connectors = array();

	/**
	 * @param DoliDB               $db     Database handler
	 * @param InboxSpamEngine|null $engine Engine (null: the one of the settings)
	 */
	public function __construct(DoliDB $db, $engine = null)
	{
		$this->db = $db;
		$this->engine = $engine;
	}

	/**
	 * @return void
	 */
	public function __destruct()
	{
		foreach ($this->connectors as $c) {
			$c->disconnect();
		}
	}

	/**
	 * Setting of Settings > Anti-spam: off by default (an engine must be installed first)
	 *
	 * @return bool
	 */
	public static function enabled()
	{
		return (bool) getDolGlobalInt('INBOX_RSPAMD_ENABLED', 0);
	}

	/**
	 * @return InboxSpamEngine
	 */
	protected function engine()
	{
		if (!$this->engine) {
			$this->engine = InboxSpamRspamd::fromSettings();
		}

		return $this->engine;
	}

	/**
	 * New mails of the inbox (synchronisation): to be judged
	 *
	 * @param int[] $mailIds Rows of the index
	 * @return void
	 */
	public function markNew(array $mailIds)
	{
		$mailIds = array_values(array_filter(array_map('intval', $mailIds)));
		if ($mailIds && self::enabled()) {
			$this->db->query("UPDATE ".$this->db->prefix()."inbox_mail SET spam_check = 0 WHERE rowid IN (".implode(',', $mailIds).") AND spam_check IS NULL");
		}
	}

	/**
	 * @param int $accountId Mailbox
	 * @return InboxConnectorInterface|null
	 */
	protected function connector($accountId)
	{
		if (isset($this->connectors[$accountId])) {
			return $this->connectors[$accountId];
		}
		$account = new InboxAccount($this->db);
		if ($account->fetch((int) $accountId) <= 0) {
			return null;
		}
		$error = '';
		$c = InboxConnectorFactory::forAccount($this->db, $account, $error);
		if (!$c || !$c->connect()) {
			$this->error = $c ? $c->getError() : $error;
			return null;
		}
		$this->connectors[$accountId] = $c;

		return $c;
	}

	/**
	 * Judge the mails of a mailbox waiting for it, then the spam to the junk
	 *
	 * @param User  $user      User of the job (journal)
	 * @param int   $accountId Mailbox
	 * @param float $deadline  Microtime not to go beyond
	 * @return array{checked:int,spam:int,moved:int} Counters
	 */
	public function checkPending(User $user, $accountId, $deadline)
	{
		$out = array('checked' => 0, 'spam' => 0, 'moved' => 0);
		if (!self::enabled()) {
			return $out;
		}
		$p = $this->db->prefix();
		$rows = $this->db->getRows("SELECT m.rowid, m.remote_id, m.from_email, m.keywords, m.size, f.path FROM ".$p."inbox_mail as m"
			." INNER JOIN ".$p."inbox_folder as f ON f.rowid = m.fk_folder AND f.role = 'inbox' AND f.status = 1"
			." WHERE m.fk_account = ".((int) $accountId)." AND m.spam_check = 0 AND m.date_index > '".$this->db->idate(dol_now() - self::RECENT)."'"
			." ORDER BY m.rowid DESC LIMIT ".self::PER_PASS);
		if (!is_array($rows) || !$rows) {
			return $out;
		}
		$c = $this->connector((int) $accountId);
		if (!$c) {
			return $out;
		}
		$maxBytes = max(1, getDolGlobalInt('INBOX_ANTIVIRUS_MAX_MB', 25)) * 1048576;
		$all = getDolGlobalString('INBOX_RSPAMD_SCOPE', 'noverdict') === 'all';
		$blocked = new InboxBlockedSender($this->db);
		$move = array();
		foreach ($rows as $r) {
			if (microtime(true) > $deadline) {
				break;
			}
			if ((int) $r->size > $maxBytes) {
				$this->done((int) $r->rowid, null);
				continue;
			}
			$raw = $c->fetchRaw((string) $r->path, (string) $r->remote_id);
			if ($raw === null) {
				// Gone from the server meanwhile: the next pass removes it from the index
				$this->done((int) $r->rowid, null);
				continue;
			}
			$headEnd = strpos($raw, "\r\n\r\n");
			$headers = InboxSenderAuth::parseHeaders($headEnd === false ? $raw : substr($raw, 0, $headEnd));
			if (!$all && InboxReader::spamVerdict($headers) !== null) {
				// Judged by the filter of the server already
				$this->done((int) $r->rowid, null);
				continue;
			}
			$v = $this->engine()->check($raw);
			if ($v === null) {
				// Engine unreachable: left to judge, said in Settings > Anti-spam
				$this->saveError($this->engine()->error);
				break;
			}
			$this->saveError('');
			$this->done((int) $r->rowid, $v);
			$out['checked']++;
			if (!$v['spam']) {
				continue;
			}
			$out['spam']++;
			if (!getDolGlobalInt('INBOX_RSPAMD_MOVE', 1) || stripos(' '.(string) $r->keywords.' ', ' $NotJunk ') !== false) {
				continue;
			}
			// A sender known to Dolibarr or protected: never moved by a score, the banner says it
			$from = InboxBlockedSender::normalizeAddress((string) $r->from_email);
			if ($from === '' || $blocked->levelOf(InboxBlockedSender::TYPE_ADDRESS, $from)['level'] !== InboxBlockedSender::LEVEL_UNKNOWN) {
				continue;
			}
			$move[] = (int) $r->rowid;
		}
		if ($move) {
			require_once __DIR__.'/inboxactions.class.php';
			$res = (new InboxActions($this->db))->runAsSystem($user, 'spam', $move);
			$out['moved'] = (int) $res['count'];
			if (!$res['ok']) {
				dol_syslog(__METHOD__.' '.$res['error'], LOG_WARNING);
			}
		}

		return $out;
	}

	/**
	 * Verdict of the engine kept on the mail (null: not judged by it, the mail is no longer waiting)
	 *
	 * @param int                      $mailId Row of the index
	 * @param array<string,mixed>|null $v      Verdict
	 * @return void
	 */
	protected function done($mailId, $v)
	{
		$set = "spam_check = 1";
		if ($v) {
			$set .= ", spam_engine = 'rspamd', spam_score = ".((float) $v['score']).", spam_required = ".($v['required'] === null ? 'NULL' : (float) $v['required'])
				.", spam_action = '".$this->db->escape(substr((string) $v['action'], 0, 32))."', spam_symbols = '".$this->db->escape(implode(' ', (array) $v['symbols']))."'";
		}
		$this->db->query("UPDATE ".$this->db->prefix()."inbox_mail SET ".$set." WHERE rowid = ".((int) $mailId));
	}

	/**
	 * Last error of the engine, shown in Settings > Anti-spam ('' = it answers)
	 *
	 * @param string $error Message
	 * @return void
	 */
	protected function saveError($error)
	{
		global $conf;

		require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
		$old = getDolGlobalString('INBOX_RSPAMD_LAST_ERROR');
		if ($error === '' && $old === '') {
			return;
		}
		if ($error !== '' && strpos($old, '|') !== false) {
			// The first date of the failures kept: « unreachable since »
			$error = strtok($old, '|').'|'.dol_trunc($error, 200, 'right', 'UTF-8', 1);
		} elseif ($error !== '') {
			$error = dol_now().'|'.dol_trunc($error, 200, 'right', 'UTF-8', 1);
		}
		dolibarr_set_const($this->db, 'INBOX_RSPAMD_LAST_ERROR', $error, 'chaine', 0, '', $conf->entity);
	}

	/**
	 * Verdict of the engine for the reading, as the verdict of the server
	 *
	 * @param object $row Row of the index (spam_engine, spam_score, spam_required, spam_action, spam_symbols)
	 * @return array{engine:string,spam:bool,score:float|null,required:float|null,tests:string[],local:bool}|null
	 */
	public static function verdictOf($row)
	{
		if (!is_object($row) || empty($row->spam_engine)) {
			return null;
		}

		return array('engine' => (string) $row->spam_engine, 'spam' => in_array((string) $row->spam_action, InboxSpamRspamd::SPAM_ACTIONS, true),
			'score' => $row->spam_score === null ? null : round((float) $row->spam_score, 1), 'required' => $row->spam_required === null ? null : round((float) $row->spam_required, 1),
			'tests' => array_values(array_filter(explode(' ', (string) $row->spam_symbols))), 'local' => true);
	}

	// ---------- Learning ----------

	/**
	 * « Junk » or « Not junk » of a user: learned by the scheduled job
	 *
	 * @param User   $user    User
	 * @param int[]  $mailIds Rows of the index (after the move)
	 * @param bool   $spam    Spam (else legitimate)
	 * @param string $origin  user or train
	 * @return int Rows queued
	 */
	public function queue($user, array $mailIds, $spam, $origin = 'user')
	{
		global $conf;

		$mailIds = array_values(array_unique(array_filter(array_map('intval', $mailIds))));
		if (!$mailIds || !self::enabled() || !getDolGlobalInt('INBOX_RSPAMD_LEARN', 1)) {
			return 0;
		}
		$p = $this->db->prefix();
		// Asked again in the other way: the last one wins, the waiting one goes
		$this->db->query("DELETE FROM ".$p."inbox_spam_learn WHERE state = 0 AND fk_mail IN (".implode(',', $mailIds).")");
		$values = array();
		foreach ($mailIds as $id) {
			$values[] = "(".((int) $conf->entity).", ".$id.", ".($spam ? 1 : 0).", '".$this->db->escape($origin)."', ".(is_object($user) && $user->id ? (int) $user->id : 'NULL').", 0, '".$this->db->idate(dol_now())."')";
		}
		$n = 0;
		foreach (array_chunk($values, 200) as $chunk) {
			if ($this->db->query("INSERT INTO ".$p."inbox_spam_learn (entity, fk_mail, is_spam, origin, fk_user, state, date_creation) VALUES ".implode(', ', $chunk))) {
				$n += count($chunk);
			}
		}

		return $n;
	}

	/**
	 * Scheduled job: the mails waiting to be learned
	 *
	 * @param float $deadline Microtime not to go beyond
	 * @return array{learned:int,errors:int}
	 */
	public function processQueue($deadline)
	{
		global $conf;

		$out = array('learned' => 0, 'errors' => 0);
		if (!self::enabled()) {
			return $out;
		}
		$p = $this->db->prefix();
		$rows = $this->db->getRows("SELECT q.rowid, q.is_spam, m.rowid as mail, m.fk_account, m.remote_id, m.size, f.path FROM ".$p."inbox_spam_learn as q"
			." LEFT JOIN ".$p."inbox_mail as m ON m.rowid = q.fk_mail LEFT JOIN ".$p."inbox_folder as f ON f.rowid = m.fk_folder"
			." WHERE q.entity = ".((int) $conf->entity)." AND q.state = 0 ORDER BY q.origin = 'train', q.rowid LIMIT ".self::LEARN_PER_PASS);
		$maxBytes = max(1, getDolGlobalInt('INBOX_ANTIVIRUS_MAX_MB', 25)) * 1048576;
		foreach (is_array($rows) ? $rows : array() as $r) {
			if (microtime(true) > $deadline) {
				break;
			}
			if (!$r->mail || (int) $r->size > $maxBytes) {
				$this->learned((int) $r->rowid, $r->mail ? 'too big' : 'mail gone');
				$out['errors']++;
				continue;
			}
			$c = $this->connector((int) $r->fk_account);
			$raw = $c ? $c->fetchRaw((string) $r->path, (string) $r->remote_id) : null;
			if ($raw === null) {
				$this->learned((int) $r->rowid, $c ? 'mail gone' : 'mailbox unreachable');
				$out['errors']++;
				continue;
			}
			if (!$this->engine()->learn($raw, (bool) $r->is_spam)) {
				// Engine unreachable: everything waits for the next pass
				$this->saveError($this->engine()->error);
				break;
			}
			$this->saveError('');
			$this->learned((int) $r->rowid, '');
			$out['learned']++;
		}

		return $out;
	}

	/**
	 * @param int    $id    Row of the queue
	 * @param string $error '' = learned
	 * @return void
	 */
	protected function learned($id, $error)
	{
		$this->db->query("UPDATE ".$this->db->prefix()."inbox_spam_learn SET state = ".($error === '' ? 1 : -1).", error = ".($error === '' ? 'NULL' : "'".$this->db->escape($error)."'")
			.", date_done = '".$this->db->idate(dol_now())."' WHERE rowid = ".((int) $id));
	}

	/**
	 * Training of the start (administrator): the junk of the last months as spam, the mails answered as legitimate
	 * (surer than the whole inbox), queued for the scheduled job
	 *
	 * @param User $user User
	 * @return array{spam:int,ham:int}
	 */
	public function train(User $user)
	{
		global $conf;

		$p = $this->db->prefix();
		$since = "'".$this->db->idate(dol_now() - self::TRAIN_MONTHS * 30 * 86400)."'";
		$base = " FROM ".$p."inbox_mail as m INNER JOIN ".$p."inbox_folder as f ON f.rowid = m.fk_folder AND f.status = 1"
			." INNER JOIN ".$p."inbox_account as a ON a.rowid = m.fk_account AND a.entity = ".((int) $conf->entity)." AND a.status = ".InboxAccount::STATUS_ACTIVE
			." WHERE m.date_received > ".$since." AND m.rowid NOT IN (SELECT q.fk_mail FROM ".$p."inbox_spam_learn as q WHERE q.entity = ".((int) $conf->entity).")";
		$ids = function ($sql) {
			$rows = $this->db->getRows($sql);
			return array_map(function ($r) {
				return (int) $r->rowid;
			}, is_array($rows) ? $rows : array());
		};
		$spam = $ids("SELECT m.rowid".$base." AND f.role = 'junk' ORDER BY m.rowid DESC LIMIT ".self::TRAIN_MAX);
		$ham = $ids("SELECT m.rowid".$base." AND m.answered = 1 AND (f.role IS NULL OR f.role NOT IN ('junk', 'trash', 'sent', 'drafts')) ORDER BY m.rowid DESC LIMIT ".self::TRAIN_MAX);

		return array('spam' => $this->queue($user, $spam, true, 'train'), 'ham' => $this->queue($user, $ham, false, 'train'));
	}

	/**
	 * State for Settings > Anti-spam: the engine, the queue, the last error
	 *
	 * @param bool $ask Ask the engine (test of the connection)
	 * @return array<string,mixed>
	 */
	public function state($ask)
	{
		global $conf;

		$q = $this->db->getRows("SELECT state, COUNT(rowid) as n FROM ".$this->db->prefix()."inbox_spam_learn WHERE entity = ".((int) $conf->entity)." GROUP BY state LIMIT 10");
		$queue = array('waiting' => 0, 'done' => 0, 'failed' => 0);
		foreach (is_array($q) ? $q : array() as $r) {
			$queue[(int) $r->state === 0 ? 'waiting' : ((int) $r->state === 1 ? 'done' : 'failed')] = (int) $r->n;
		}
		$err = getDolGlobalString('INBOX_RSPAMD_LAST_ERROR');

		return array(
			'queue' => $queue,
			'error' => $err === '' ? null : array('since' => (int) strtok($err, '|'), 'message' => (string) substr($err, strpos($err, '|') + 1)),
			'engine' => $ask ? $this->engine()->status() : null,
			'has_password' => getDolGlobalString('INBOX_RSPAMD_PASSWORD') !== '',
		);
	}
}
