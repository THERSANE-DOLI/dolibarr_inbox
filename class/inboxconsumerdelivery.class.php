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
 * \file    inbox/class/inboxconsumerdelivery.class.php
 * \ingroup inbox
 * \brief   Mails given to the modules subscribed (1.146.0): the queue written by the synchronisation, the delivery at
 *          the end of the pass, the scheduled job for the retries
 */

require_once __DIR__.'/inboxconsumer.class.php';
require_once __DIR__.'/inboxconsumerinterface.class.php';
require_once __DIR__.'/inboxaccount.class.php';
require_once __DIR__.'/inboxactionlog.class.php';

/**
 * The synchronisation writes one row per new mail and subscription (never for the first indexing, never for a mail
 * arrived before the authorization, never in Sent, Drafts, Junk, Trash), then gives the rows to the modules at the
 * end of the same pass. A module that fails never stops the synchronisation: its event is given again later, with a
 * growing delay, 5 times at most. No trigger of Dolibarr: it would reach every module enabled, whatever the
 * mailboxes where a module was allowed.
 */
class InboxConsumerDelivery
{
	const EVENT_RECEIVED = 'mail.received';

	const STATUS_WAITING = 'waiting';
	const STATUS_RUNNING = 'running';
	const STATUS_DONE = 'done';
	const STATUS_SKIPPED = 'skipped';
	const STATUS_FAILED = 'failed';

	/** Delays before the next try after an error, in seconds: 5 tries in all, then failed */
	const DELAYS = array(60, 300, 900, 3600);

	/** An event taken by a delivery that died (fatal error, timeout) is given again after this delay */
	const STALE = 600;

	/** Events given per call, at most */
	const BATCH = 50;

	/** Rows done, skipped or failed kept this long (days); a row still waiting this long is left */
	const KEEP_DAYS = 30;

	/** Roles of the folders where a new mail never makes an event */
	const NO_EVENT_ROLES = array('sent', 'drafts', 'junk', 'trash');

	/** @var DoliDB */
	protected $db;

	/** @var array{created:int,done:int,skipped:int,errors:int,left:int} Counts of the last calls */
	public $stats = array('created' => 0, 'done' => 0, 'skipped' => 0, 'errors' => 0, 'left' => 0);

	/** @var string Output of the scheduled job */
	public $output = '';

	/** @var bool A delivery runs in this process (1.146.1): no delivery inside it */
	protected static $delivering = false;

	/** @var array<string,InboxConsumerInterface|false> Instances of the modules during a call */
	protected $instances = array();

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct(DoliDB $db)
	{
		$this->db = $db;
	}

	/**
	 * The subscriptions of a mailbox that receive an event, by code (only modules that declare themselves, paused
	 * ones included: their queue goes on and runs again at the resume)
	 *
	 * @param int    $accountId Mailbox
	 * @param string $event     Event
	 * @return array<string,array<string,mixed>>
	 */
	protected function subscribed($accountId, $event)
	{
		$declared = InboxConsumer::declared();
		$out = array();
		foreach ((new InboxConsumer($this->db))->grants((int) $accountId) as $code => $g) {
			if (isset($declared[$code]) && in_array($event, $g['events'], true) && in_array($event, $declared[$code]['events'], true)
				&& $g['pause_reason'] !== InboxConsumer::PAUSE_PROTECTED) {
				$out[$code] = $g;
			}
		}

		return $out;
	}

	/**
	 * Key of a mail in the queue, given to the module (InboxConsumerEvent::$key): its Message-ID, or for a mail without
	 * one the print of its date, sender, subject and size, « fp:<sha1> » (1.146.2, the key of the links of the mails):
	 * the same in every copy of the mail, after a move by another mail program too. Its row only when the print cannot
	 * be made.
	 *
	 * @param object $row Row of the index (rowid, message_id, date_mail, from_email, subject, size)
	 * @return string
	 */
	public static function keyOf($row)
	{
		global $db;

		$id = trim((string) $row->message_id);
		if ($id !== '') {
			return dol_trunc($id, 255, 'right', 'UTF-8', 1);
		}
		require_once __DIR__.'/inboxmailref.class.php';
		$key = (new InboxMailRef($db))->keyOf($row);

		return $key !== '' ? $key : 'mail:'.((int) $row->rowid);
	}

	/**
	 * Events « mail.received » for new mails of a mailbox found by the synchronisation (its first indexing excluded
	 * by the caller). One query when no module is subscribed to the mailbox.
	 *
	 * @param InboxAccount $account Mailbox
	 * @param int[]        $mailIds Rows of the index of the new mails
	 * @return int Events written
	 */
	public function createReceived(InboxAccount $account, array $mailIds)
	{
		global $conf;

		$this->stats['created'] = 0;
		$mailIds = array_values(array_unique(array_filter(array_map('intval', $mailIds))));
		if (!$mailIds || !InboxConsumer::enabled() || $account->isLive()) {
			return 0;
		}
		$subs = $this->subscribed((int) $account->id, self::EVENT_RECEIVED);
		if (!$subs) {
			return 0;
		}
		$p = $this->db->prefix();
		$rows = array();
		foreach (array_chunk($mailIds, 500) as $chunk) {
			$part = $this->db->getRows("SELECT m.rowid, m.fk_folder, m.message_id, m.date_received, m.date_mail, m.from_email, m.subject, m.size, f.role FROM ".$p."inbox_mail as m INNER JOIN ".$p."inbox_folder as f ON f.rowid = m.fk_folder"
				." WHERE m.rowid IN (".implode(',', $chunk).") AND m.fk_account = ".((int) $account->id)." LIMIT 501");
			$rows = array_merge($rows, is_array($part) ? $part : array());
		}
		$now = $this->db->idate(dol_now());
		$n = 0;
		foreach ($subs as $g) {
			$keys = array();
			foreach ($rows as $r) {
				// Blocked senders and spams are already in the junk here (moved by the synchronisation before)
				if (in_array((string) $r->role, self::NO_EVENT_ROLES, true)) {
					continue;
				}
				if ($g['folders'] && !in_array((int) $r->fk_folder, $g['folders'], true)) {
					continue;
				}
				// Never a mail that was there before the authorization (the server date of its arrival)
				$received = $r->date_received ? (int) $this->db->jdate($r->date_received) : 0;
				if ($received > 0 && $received < (int) $g['date']) {
					continue;
				}
				$keys[self::keyOf($r)] = (int) $r->rowid;
			}
			if (!$keys) {
				continue;
			}
			// Already in the queue (the same mail seen again in another folder of the subscription): not twice
			$quoted = array_map(function ($k) {
				return "'".$this->db->escape($k)."'";
			}, array_keys($keys));
			$known = $this->db->getRows("SELECT message_id FROM ".$p."inbox_consumer_event WHERE fk_consumer = ".((int) $g['id'])." AND event = '".self::EVENT_RECEIVED."'"
				." AND message_id IN (".implode(',', $quoted).") LIMIT ".(count($keys) + 1));
			foreach (is_array($known) ? $known : array() as $k) {
				unset($keys[(string) $k->message_id]);
			}
			foreach ($keys as $key => $mailId) {
				$sql = "INSERT INTO ".$p."inbox_consumer_event (entity, fk_consumer, fk_account, event, message_id, fk_mail, status, attempts, next_try, datec)"
					." VALUES (".((int) $conf->entity).", ".((int) $g['id']).", ".((int) $account->id).", '".self::EVENT_RECEIVED."', '".$this->db->escape($key)."', ".((int) $mailId).","
					." '".self::STATUS_WAITING."', 0, '".$now."', '".$now."')";
				if ($this->db->query($sql)) {
					$n++;
				} else {
					dol_syslog(__METHOD__.' '.$this->db->lasterror(), LOG_WARNING);
				}
			}
		}
		$this->stats['created'] = $n;

		return $n;
	}

	/**
	 * Give the events due of a mailbox to their modules, within the time given
	 *
	 * @param InboxAccount $account  Mailbox
	 * @param float        $deadline Microtime not to go beyond (one event at least is given)
	 * @return array{done:int,skipped:int,errors:int,left:int}
	 */
	public function deliver(InboxAccount $account, $deadline)
	{
		$out = array('done' => 0, 'skipped' => 0, 'errors' => 0, 'left' => 0);
		// Never inside another delivery (1.146.1): a module that moves a mail may run a synchronisation (server without
		// UIDPLUS), which must not give the next events inside its handle()
		if (self::$delivering || !InboxConsumer::enabled() || !$account->isActive() || !$account->canWorkNow() || $account->isLive()) {
			return $out;
		}
		$declared = InboxConsumer::declared();
		if (!$declared) {
			return $out;
		}
		$p = $this->db->prefix();
		$this->recoverStale((int) $account->id);
		$now = dol_now();
		// Modules that declare themselves only (1.146.1): the events of a module turned off wait for it without taking
		// the places of the others
		$codes = implode(',', array_map(function ($c) {
			return "'".$this->db->escape((string) $c)."'";
		}, array_keys($declared)));
		$rows = $this->db->getRows("SELECT e.rowid, e.fk_consumer, e.message_id, e.fk_mail, e.attempts, e.datec, c.code, c.actions, c.folders, c.mode"
			." FROM ".$p."inbox_consumer_event as e INNER JOIN ".$p."inbox_consumer as c ON c.rowid = e.fk_consumer AND c.paused = 0 AND c.code IN (".$codes.")"
			." WHERE e.fk_account = ".((int) $account->id)." AND e.status = '".self::STATUS_WAITING."' AND (e.next_try IS NULL OR e.next_try <= '".$this->db->idate($now)."')"
			." ORDER BY e.rowid LIMIT ".(self::BATCH + 1));
		if (!is_array($rows) || !$rows) {
			return $out;
		}
		self::$delivering = true;
		try {
			$first = true;
			foreach ($rows as $i => $e) {
				if ($i >= self::BATCH || (!$first && microtime(true) > $deadline)) {
					$out['left'] = count($rows) - $i;
					break;
				}
				$code = (string) $e->code;
				if (!isset($declared[$code])) {
					continue;
				}
				// Taken by this delivery only (the scheduled job and a page may synchronise at the same time), the try
				// counted now (1.146.1): a module that kills the process (memory, time limit) still ends « failed »
				$this->db->query("UPDATE ".$p."inbox_consumer_event SET status = '".self::STATUS_RUNNING."', attempts = attempts + 1, next_try = '".$this->db->idate($now + self::STALE)."'"
					." WHERE rowid = ".((int) $e->rowid)." AND status = '".self::STATUS_WAITING."'");
				if ((int) $this->db->affected_rows(true) !== 1) {
					continue;
				}
				$e->attempts = (int) $e->attempts + 1;
				$first = false;
				$res = $this->deliverOne($account, $e, $declared[$code]);
				$out[$res === 1 ? 'done' : ($res === 0 ? 'skipped' : 'errors')]++;
			}
		} finally {
			self::$delivering = false;
		}
		$this->stats = array_merge($this->stats, $out);

		return $out;
	}

	/**
	 * Events taken by a delivery that died during handle() (fatal error, memory, time limit): counted as an error of
	 * the module (their try was counted when taken), given again later or given up after the last try
	 *
	 * @param int $accountId Mailbox, 0 for every mailbox of the entity
	 * @return int Events recovered
	 */
	public function recoverStale($accountId = 0)
	{
		global $conf;

		$rows = $this->db->getRows("SELECT rowid, fk_consumer, attempts FROM ".$this->db->prefix()."inbox_consumer_event WHERE status = '".self::STATUS_RUNNING."'"
			." AND next_try < '".$this->db->idate(dol_now())."' AND ".($accountId > 0 ? "fk_account = ".((int) $accountId) : "entity = ".((int) $conf->entity))." LIMIT 500");
		$n = 0;
		foreach (is_array($rows) ? $rows : array() as $e) {
			$this->failed($e, 'Delivery stopped during handle() (fatal error, memory or time limit)');
			$n++;
		}

		return $n;
	}

	/**
	 * Give one event, taken (status running)
	 *
	 * @param InboxAccount        $account  Mailbox
	 * @param object              $e        Row of the queue with its subscription
	 * @param array<string,mixed> $declared Declaration of the module
	 * @return int 1 done, 0 skipped or put off, -1 error
	 */
	protected function deliverOne(InboxAccount $account, $e, array $declared)
	{
		$mail = $this->locate((int) $account->id, $e);
		if ($mail === null) {
			$this->finish($e, self::STATUS_SKIPPED, 'Mail gone');
			return 0;
		}
		if (in_array((string) $mail->folder_role, self::NO_EVENT_ROLES, true)) {
			$this->finish($e, self::STATUS_SKIPPED, 'Mail in '.$mail->folder_role);
			return 0;
		}
		$folders = array_map('intval', (array) json_decode((string) $e->folders, true));
		if ($folders && !in_array((int) $mail->fk_folder, $folders, true)) {
			$this->finish($e, self::STATUS_SKIPPED, 'Mail moved out of the folders');
			return 0;
		}
		// Still to be judged by the anti-spam engine of inbox (1.88.0): given once judged, never before
		require_once __DIR__.'/inboxspamcheck.class.php';
		if ($mail->spam_check !== null && (string) $mail->spam_check === '0' && InboxSpamCheck::enabled()
			&& (int) $this->db->jdate($e->datec) > dol_now() - InboxSpamCheck::RECENT) {
			// Put off, not a try of the module
			$this->db->query("UPDATE ".$this->db->prefix()."inbox_consumer_event SET status = '".self::STATUS_WAITING."', attempts = GREATEST(0, attempts - 1),"
				." next_try = '".$this->db->idate(dol_now() + 60)."' WHERE rowid = ".((int) $e->rowid));
			return 0;
		}
		$module = $this->instance($declared);
		if (!$module) {
			return $this->failed($e, 'Class '.$declared['class'].' not found or not an InboxConsumerInterface');
		}
		$event = new InboxConsumerEvent();
		$event->event = self::EVENT_RECEIVED;
		$event->consumer = (string) $e->code;
		$event->accountId = (int) $account->id;
		$event->accountEmail = (string) $account->email;
		$event->messageId = (string) $mail->message_id;
		$event->key = (string) $e->message_id;
		$event->mailId = (int) $mail->rowid;
		$event->folder = (string) $mail->folder_path;
		$event->headers = array(
			'from' => (string) $mail->from_email, 'to' => (string) $mail->to_text, 'cc' => (string) $mail->cc_text, 'subject' => (string) $mail->subject,
			'date' => $mail->date_mail ? (int) $this->db->jdate($mail->date_mail) : 0, 'in_reply_to' => (string) $mail->in_reply_to,
		);
		$event->test = (string) $e->mode !== InboxConsumer::MODE_ACTIVE;
		$event->setContext($this->db, $account, $mail, array(
			'code' => (string) $e->code, 'label' => (string) $declared['label'],
			'actions' => array_values(array_intersect((array) json_decode((string) $e->actions, true), $declared['actions'])),
		));
		// A module never breaks the synchronisation nor the answer of a page (what it prints is dropped)
		$level = ob_get_level();
		$journal = InboxActionLog::$consumer;
		ob_start();
		$error = '';
		$res = -1;
		try {
			$res = (int) $module->handle($event);
		} catch (Throwable $t) {
			$error = get_class($t).': '.$t->getMessage();
			dol_syslog(__METHOD__.' '.$e->code.' '.$error, LOG_ERR);
		} finally {
			while (ob_get_level() > $level) {
				ob_end_clean();
			}
			// As it was before (1.146.1), never null: a delivery may run while something else writes the journal
			InboxActionLog::$consumer = $journal;
		}
		if ($res === 1 || $res === 0) {
			$this->finish($e, $res === 1 ? self::STATUS_DONE : self::STATUS_SKIPPED, '');
			$this->db->query("UPDATE ".$this->db->prefix()."inbox_consumer SET last_ok = '".$this->db->idate(dol_now())."', last_error = NULL, errors_in_row = 0 WHERE rowid = ".((int) $e->fk_consumer));
			return $res;
		}

		return $this->failed($e, $error !== '' ? $error : ($event->error !== '' ? $event->error : 'handle() returned '.$res));
	}

	/**
	 * The mail of an event where it is now: its row when still there, else a copy found by its Message-ID (moved),
	 * outside the trash and the junk first
	 *
	 * @param int    $accountId Mailbox
	 * @param object $e         Row of the queue
	 * @return object|null Row of the index with folder_path, folder_role
	 */
	protected function locate($accountId, $e)
	{
		$p = $this->db->prefix();
		$select = "SELECT m.*, f.path AS folder_path, f.role AS folder_role FROM ".$p."inbox_mail as m INNER JOIN ".$p."inbox_folder as f ON f.rowid = m.fk_folder AND f.status = 1";
		$key = (string) $e->message_id;
		if (strpos($key, 'mail:') === 0) {
			$row = $this->db->getRow($select." WHERE m.rowid = ".((int) substr($key, 5))." AND m.fk_account = ".((int) $accountId));
			return is_object($row) ? $row : null;
		}
		if (strpos($key, 'fp:') === 0) {
			// Without Message-ID (1.146.2): its row when still there, else the copy with the same print (moved)
			$rows = $this->db->getRows($select." WHERE m.fk_account = ".((int) $accountId)." AND (m.message_id IS NULL OR m.message_id = '')"
				." ORDER BY CASE WHEN m.rowid = ".((int) $e->fk_mail)." THEN 0 WHEN f.role IN ('trash', 'junk') THEN 2 ELSE 1 END, m.rowid DESC LIMIT 2000");
			foreach (is_array($rows) ? $rows : array() as $row) {
				if (self::keyOf($row) === $key) {
					return $row;
				}
			}
			return null;
		}
		$row = $this->db->getRow($select." WHERE m.fk_account = ".((int) $accountId)." AND m.message_id = '".$this->db->escape($key)."'"
			." ORDER BY CASE WHEN m.rowid = ".((int) $e->fk_mail)." THEN 0 WHEN f.role IN ('trash', 'junk') THEN 2 ELSE 1 END, m.rowid DESC");

		return is_object($row) ? $row : null;
	}

	/**
	 * Instance of the class declared by a module, once per call
	 *
	 * @param array<string,mixed> $declared Declaration
	 * @return InboxConsumerInterface|null
	 */
	protected function instance(array $declared)
	{
		$code = (string) $declared['code'];
		if (!isset($this->instances[$code])) {
			$this->instances[$code] = false;
			$class = (string) $declared['class'];
			if (!class_exists($class, false)) {
				dol_include_once((string) $declared['file']);
			}
			if (class_exists($class, false) && in_array('InboxConsumerInterface', (array) class_implements($class), true)) {
				try {
					$this->instances[$code] = new $class($this->db);
				} catch (Throwable $t) {
					dol_syslog(__METHOD__.' '.$code.' '.$t->getMessage(), LOG_ERR);
				}
			}
		}

		return $this->instances[$code] ?: null;
	}

	/**
	 * @param object $e      Row of the queue
	 * @param string $status done, skipped, failed
	 * @param string $error  Why
	 * @return void
	 */
	protected function finish($e, $status, $error)
	{
		$this->db->query("UPDATE ".$this->db->prefix()."inbox_consumer_event SET status = '".$this->db->escape($status)."', next_try = NULL, date_done = '".$this->db->idate(dol_now())."',"
			." error = ".($error !== '' ? "'".$this->db->escape(dol_trunc($error, 255, 'right', 'UTF-8', 1))."'" : 'NULL')." WHERE rowid = ".((int) $e->rowid));
	}

	/**
	 * An error: given again later with a growing delay, failed after the last try; seen in the section Modules
	 *
	 * @param object $e     Row of the queue
	 * @param string $error Why (never shown as HTML)
	 * @return int -1
	 */
	protected function failed($e, $error)
	{
		$p = $this->db->prefix();
		// The try was counted when the event was taken (1.146.1)
		$attempts = max(1, (int) $e->attempts);
		$error = dol_trunc($error, 255, 'right', 'UTF-8', 1);
		if ($attempts > count(self::DELAYS)) {
			$this->finish($e, self::STATUS_FAILED, $error);
			$this->db->query("UPDATE ".$p."inbox_consumer_event SET attempts = ".$attempts." WHERE rowid = ".((int) $e->rowid));
		} else {
			$this->db->query("UPDATE ".$p."inbox_consumer_event SET status = '".self::STATUS_WAITING."', attempts = ".$attempts.", next_try = '".$this->db->idate(dol_now() + self::DELAYS[$attempts - 1])."',"
				." error = '".$this->db->escape($error)."' WHERE rowid = ".((int) $e->rowid));
		}
		$this->db->query("UPDATE ".$p."inbox_consumer SET last_error = '".$this->db->escape($error)."', errors_in_row = errors_in_row + 1 WHERE rowid = ".((int) $e->fk_consumer));

		return -1;
	}

	/**
	 * Queue of the subscriptions of a mailbox, by code: waiting (to give), failed (given up)
	 *
	 * @param int $accountId Mailbox
	 * @return array<string,array{waiting:int,failed:int}>
	 */
	public function counts($accountId)
	{
		$p = $this->db->prefix();
		$rows = $this->db->getRows("SELECT c.code, e.status, COUNT(e.rowid) as n FROM ".$p."inbox_consumer_event as e INNER JOIN ".$p."inbox_consumer as c ON c.rowid = e.fk_consumer"
			." WHERE e.fk_account = ".((int) $accountId)." AND e.status IN ('".self::STATUS_WAITING."', '".self::STATUS_RUNNING."', '".self::STATUS_FAILED."') GROUP BY c.code, e.status LIMIT 1000");
		$out = array();
		foreach (is_array($rows) ? $rows : array() as $r) {
			$c = (string) $r->code;
			if (!isset($out[$c])) {
				$out[$c] = array('waiting' => 0, 'failed' => 0);
			}
			$out[$c][(string) $r->status === self::STATUS_FAILED ? 'failed' : 'waiting'] += (int) $r->n;
		}

		return $out;
	}

	/**
	 * Queue of a subscription emptied (authorization removed)
	 *
	 * @param int $consumerId Subscription
	 * @return void
	 */
	public function dropConsumer($consumerId)
	{
		$this->db->query("DELETE FROM ".$this->db->prefix()."inbox_consumer_event WHERE fk_consumer = ".((int) $consumerId));
	}

	/**
	 * Events still to give of a mailbox removed (protected with its content: nothing may run in the background)
	 *
	 * @param int $accountId Mailbox
	 * @return void
	 */
	public function dropWaiting($accountId)
	{
		$this->db->query("DELETE FROM ".$this->db->prefix()."inbox_consumer_event WHERE fk_account = ".((int) $accountId)
			." AND status IN ('".self::STATUS_WAITING."', '".self::STATUS_RUNNING."')");
	}

	/**
	 * Scheduled job « Give the mails to the modules subscribed » (every 5 minutes): the events given again after an
	 * error, and the ones the synchronisation had no time for, of the mailboxes this job may read
	 *
	 * @return int 0 if OK (output in $this->output)
	 */
	public function cronRun()
	{
		global $conf;

		$p = $this->db->prefix();
		if (!InboxConsumer::enabled()) {
			// Turned off on the instance: the authorizations are kept, the queue is emptied
			$this->db->query("DELETE FROM ".$p."inbox_consumer_event WHERE entity = ".((int) $conf->entity)." AND status IN ('".self::STATUS_WAITING."', '".self::STATUS_RUNNING."')");
			$this->output = 'Modules subscribed turned off';
			return 0;
		}
		$start = microtime(true);
		$deadline = $start + max(10, getDolGlobalInt('INBOX_SYNC_BUDGET', 45));
		$now = $this->db->idate(dol_now());
		// Taken by a delivery that died: given again
		$this->recoverStale(0);
		$rows = $this->db->getRows("SELECT DISTINCT e.fk_account FROM ".$p."inbox_consumer_event as e INNER JOIN ".$p."inbox_account as a ON a.rowid = e.fk_account"
			." WHERE e.entity = ".((int) $conf->entity)." AND e.status = '".self::STATUS_WAITING."' AND e.next_try <= '".$now."'"
			." AND a.status = ".InboxAccount::STATUS_ACTIVE." AND a.protected = 0 LIMIT 500");
		$total = array('done' => 0, 'skipped' => 0, 'errors' => 0, 'left' => 0);
		$boxes = 0;
		foreach (is_array($rows) ? $rows : array() as $r) {
			if (microtime(true) > $deadline) {
				break;
			}
			$a = new InboxAccount($this->db);
			if ($a->fetch((int) $r->fk_account) <= 0) {
				continue;
			}
			$boxes++;
			foreach ($this->deliver($a, $deadline) as $k => $v) {
				$total[$k] += $v;
			}
		}
		$this->output = $boxes.' mailbox(es), '.$total['done'].' given, '.$total['skipped'].' left by the modules, '.$total['errors'].' error(s), '.$total['left'].' for later, '
			.round(microtime(true) - $start, 1).' s';

		return 0;
	}

	/**
	 * Rows done, skipped or failed older than KEEP_DAYS removed; rows waiting as long (module turned off) left
	 *
	 * @param DoliDB $db Database handler
	 * @return int Rows removed, < 0 if KO
	 */
	public static function purge(DoliDB $db)
	{
		$p = $db->prefix();
		$limit = $db->idate(dol_now() - self::KEEP_DAYS * 86400);
		if (!$db->DDLDescTable($p.'inbox_consumer_event')) {
			return 0;
		}
		$db->query("UPDATE ".$p."inbox_consumer_event SET status = '".self::STATUS_SKIPPED."', error = 'Waited too long', date_done = '".$db->idate(dol_now())."'"
			." WHERE status = '".self::STATUS_WAITING."' AND datec < '".$limit."'");
		if (!$db->query("DELETE FROM ".$p."inbox_consumer_event WHERE status IN ('".self::STATUS_DONE."', '".self::STATUS_SKIPPED."', '".self::STATUS_FAILED."') AND date_done < '".$limit."'")) {
			return -1;
		}

		return (int) $db->affected_rows(true);
	}
}
