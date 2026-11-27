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
 * \file    inbox/class/inboxsync.class.php
 * \ingroup inbox
 * \brief   Synchronisation of the index of the mailboxes (read only)
 */

require_once __DIR__.'/inboxaccount.class.php';
require_once __DIR__.'/inboxfolder.class.php';
require_once __DIR__.'/inboxmail.class.php';
require_once __DIR__.'/connector/inboxconnectorfactory.class.php';

/**
 * A pass on a mailbox (analyse-lot-w2 §3): lock, folders, changes of each followed folder, index written, state.
 * Read only on the server. Order of the folders: inbox and sent at every pass, the others every 15 min, then the
 * first indexing of the older mails, within the time budget.
 */
class InboxSync
{
	/** Pass considered dead after this time (lock taken again) */
	const LOCK_SECONDS = 600;

	/** Folders other than inbox and sent: every */
	const OTHER_FOLDERS_SECONDS = 900;

	/** List of the folders read again every */
	const LIST_SECONDS = 900;

	/** Mails read per call of the connector */
	const CHUNK = 500;

	/** Mails whose structure is read again per call (repair of the attachments, 1.7.1) */
	const REPAIR_CHUNK = 1000;

	/** Waits after errors in a row: 5 min, 15 min, then every hour */
	const RETRY = array(300, 900, 3600);

	/** @var DoliDB */
	protected $db;

	/** @var string */
	public $error = '';

	/** @var array<string,int> Counters of the last pass */
	public $stats = array();

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct(DoliDB $db)
	{
		$this->db = $db;
	}

	/**
	 * Take the lock of a mailbox: only one pass at a time (scheduled job, button, application)
	 *
	 * @param int $accountId Mailbox
	 * @return bool
	 */
	protected function lock($accountId)
	{
		$now = dol_now();
		$sql = "UPDATE ".$this->db->prefix()."inbox_account SET sync_lock = '".$this->db->idate($now)."'";
		$sql .= " WHERE rowid = ".((int) $accountId)." AND (sync_lock IS NULL OR sync_lock < '".$this->db->idate($now - self::LOCK_SECONDS)."')";

		return $this->db->query($sql) && (int) $this->db->affected_rows(true) === 1;
	}

	/**
	 * @param int $accountId Mailbox
	 * @return void
	 */
	protected function unlock($accountId)
	{
		$this->db->query("UPDATE ".$this->db->prefix()."inbox_account SET sync_lock = NULL WHERE rowid = ".((int) $accountId));
	}

	/**
	 * State of the mailbox after a pass
	 *
	 * @param int    $accountId Mailbox
	 * @param string $state     ok, first_sync, auth_failed, unreachable, error
	 * @param string $error     Message (never a password)
	 * @return void
	 */
	protected function saveState($accountId, $state, $error = '')
	{
		$p = $this->db->prefix();
		$now = dol_now();
		if (in_array($state, array('ok', 'first_sync'), true)) {
			$sql = "UPDATE ".$p."inbox_account SET sync_state = '".$this->db->escape($state)."', sync_error = NULL, date_sync = '".$this->db->idate($now)."', sync_fail_count = 0, sync_retry_at = NULL WHERE rowid = ".((int) $accountId);
		} else {
			$row = $this->db->getRow("SELECT sync_fail_count FROM ".$p."inbox_account WHERE rowid = ".((int) $accountId));
			$fails = (is_object($row) ? (int) $row->sync_fail_count : 0) + 1;
			$wait = self::RETRY[min($fails, count(self::RETRY)) - 1];
			$sql = "UPDATE ".$p."inbox_account SET sync_state = '".$this->db->escape($state)."', sync_error = '".$this->db->escape(dol_trunc($error, 1000, 'right', 'UTF-8', 1))."',";
			$sql .= " date_sync_error = '".$this->db->idate($now)."', sync_fail_count = ".$fails.", sync_retry_at = '".$this->db->idate($now + $wait)."' WHERE rowid = ".((int) $accountId);
		}
		$this->db->query($sql);
		if (!in_array($state, array('ok', 'first_sync'), true)) {
			// Lot W8 1.21.0: the managers of the mailbox are told (bell, and the browser or a mail if they chose it)
			dol_include_once('/inbox/class/inboxnotification.class.php');
			$notify = new InboxNotification($this->db);
			$notify->boxError((int) $accountId, (string) $state);
		}
	}

	/**
	 * One pass on a mailbox
	 *
	 * @param InboxAccount $account  Mailbox (active)
	 * @param int          $deadline Timestamp (microtime) not to go beyond
	 * @param array<string,mixed> $options force (bool: even during the wait after an error, every folder now),
	 *                                     phase (1: inbox and sent only; 2: the others and the first indexing; 0: both),
	 *                                     folder_id (int: this folder only, now: folder open in the application),
	 *                                     no_delivery (bool: the events of the modules subscribed are written, not given:
	 *                                     a synchronisation run by an action on mails, 1.146.1)
	 * @return int 1 done, 0 skipped (lock, wait after an error), -1 error
	 */
	public function run(InboxAccount $account, $deadline, array $options = array())
	{
		$this->error = '';
		$this->stats = array('folders' => 0, 'new' => 0, 'changed' => 0, 'deleted' => 0);
		$force = !empty($options['force']);
		$phase = (int) ($options['phase'] ?? 0);
		// A protected mailbox (1.89.0): its password only, synchronised by the session that unlocked it (never a scheduled
		// job); with its content, never (nothing of it is kept, it is read live on the server)
		if (!$account->isActive() || !$account->canWorkNow()) {
			return 0;
		}
		$account->loadSyncState();
		if (!$force && $account->sync_retry_at && $account->sync_retry_at > dol_now()) {
			return 0;
		}
		if (!$this->lock((int) $account->id)) {
			return 0;
		}
		$this->blockedNew = array();
		$this->rescueNew = array();
		$this->consumerNew = array();
		// What inbox does here is never written in the name of a module that runs an action (1.146.1)
		$journal = class_exists('InboxActionLog', false) ? InboxActionLog::$consumer : null;
		if ($journal !== null) {
			InboxActionLog::$consumer = null;
		}
		try {
			return $this->runLocked($account, $deadline, $force, $phase, $options);
		} finally {
			if ($journal !== null) {
				InboxActionLog::$consumer = $journal;
			}
		}
	}

	/**
	 * The pass and what follows it, the lock taken
	 *
	 * @param InboxAccount        $account  Mailbox
	 * @param float               $deadline Microtime limit
	 * @param bool                $force    Every folder now
	 * @param int                 $phase    0, 1, 2
	 * @param array<string,mixed> $options  As run()
	 * @return int
	 */
	protected function runLocked(InboxAccount $account, $deadline, $force, $phase, array $options)
	{
		try {
			$res = $this->pass($account, $deadline, $force, $phase, (int) ($options['folder_id'] ?? 0));
		} finally {
			$this->unlock((int) $account->id);
		}
		// Blocked senders (1.85.0): the new mails of the inbox from a blocked sender go to the junk, once the pass is
		// over (the move reads the junk again). The only write of the synchronisation on the server with the
		// ignored threads; covers a mailbox without Sieve, a script of another tool, a block not written yet.
		if ($this->blockedNew) {
			global $user;
			require_once __DIR__.'/inboxblockedsender.class.php';
			$moved = (new InboxBlockedSender($this->db))->moveNew($user, (int) $account->id, $this->blockedNew);
			$this->stats['blocked'] = $moved;
		}
		// Junk (1.147.0): a known sender, authenticated, back to the inbox (setting « Take the known senders out of the
		// junk »); after the blocked senders, before the modules subscribed: they get it as a new mail of the inbox
		if ($this->rescueNew) {
			global $user;
			require_once __DIR__.'/inboxknownsenders.class.php';
			$known = new InboxKnownSenders($this->db);
			$this->stats['rescued'] = $known->rescue($user, (int) $account->id, $this->rescueNew);
			$this->consumerNew = array_merge($this->consumerNew, $known->lastRescued);
		}
		// Anti-spam engine of inbox (1.88.0): the new mails of the inbox judged, within the time left
		// (marked at once, judged now or at a next pass)
		require_once __DIR__.'/inboxspamcheck.class.php';
		if (InboxSpamCheck::enabled()) {
			global $user;
			$spam = new InboxSpamCheck($this->db);
			$spam->markNew($this->blockedNew);
			if (microtime(true) < $deadline - 1) {
				$this->stats['spam'] = $spam->checkPending($user, (int) $account->id, $deadline - 0.5)['moved'];
			}
		}
		// Modules subscribed (1.146.0): the new mails written in their queue once the blocked senders and the spams
		// are in the junk, then given to them now (a few seconds at least), the rest at the next pass or by the
		// scheduled job. A module never stops the synchronisation (InboxConsumerDelivery).
		require_once __DIR__.'/inboxconsumer.class.php';
		if (InboxConsumer::enabled()) {
			require_once __DIR__.'/inboxconsumerdelivery.class.php';
			$delivery = new InboxConsumerDelivery($this->db);
			$this->stats['consumer_events'] = $this->consumerNew ? $delivery->createReceived($account, $this->consumerNew) : 0;
			if (empty($options['no_delivery'])) {
				$given = $delivery->deliver($account, max((float) $deadline, microtime(true) + 2));
				$this->stats['consumer_given'] = $given['done'] + $given['skipped'];
			}
		}

		return $res;
	}

	/** @var int[] Mails of the junk found probably legitimate in this pass (taken out of the junk, 1.147.0) */
	protected $rescueNew = array();

	/** @var int[] New mails of the inbox in this pass (blocked senders, 1.85.0) */
	protected $blockedNew = array();

	/** @var int[] New mails of this pass in the folders that may give events to the modules subscribed (1.146.0) */
	protected $consumerNew = array();

	/**
	 * @param InboxAccount $account  Mailbox
	 * @param float        $deadline Microtime limit
	 * @param bool         $force    Every folder now
	 * @param int          $phase    0, 1, 2
	 * @param int          $onlyFolder This folder only (0: all)
	 * @return int
	 */
	protected function pass(InboxAccount $account, $deadline, $force, $phase, $onlyFolder = 0)
	{
		global $langs;

		$error = '';
		$connector = InboxConnectorFactory::forAccount($this->db, $account, $error);
		if (!$connector || !$connector->can(InboxConnectorInterface::CAP_CHANGES)) {
			$this->saveState((int) $account->id, 'error', $connector ? $langs->transnoentities('InboxErrorNotSupported', 'getChanges') : $error);
			return -1;
		}
		if (!$connector->connect()) {
			$msg = $connector->getError();
			$state = method_exists($connector, 'getErrorCode') && $connector->getErrorCode() === 'auth' ? 'auth_failed' : 'unreachable';
			$this->saveState((int) $account->id, $state, $msg);
			$this->error = $msg;
			return -1;
		}
		$folders = new InboxFolder($this->db);
		$mails = new InboxMail($this->db);
		$p = $this->db->prefix();
		// Folders of the server
		if ($force || !$account->date_folders || $account->date_folders < dol_now() - self::LIST_SECONDS || !$folders->listForAccount((int) $account->id)) {
			$list = $connector->listFolders();
			if ($list === null) {
				$this->saveState((int) $account->id, 'error', $connector->getError());
				return -1;
			}
			$folders->syncTree($account, $list);
			$this->db->query("UPDATE ".$p."inbox_account SET date_folders = '".$this->db->idate(dol_now())."' WHERE rowid = ".((int) $account->id));
			// Quota read with the folders (every 15 min): kept on the mailbox for the tree and the tab Synchro
			if ($connector->can(InboxConnectorInterface::CAP_QUOTA) && method_exists($connector, 'quota')) {
				$q = $connector->quota();
				$this->db->query("UPDATE ".$p."inbox_account SET quota_used = ".($q ? (int) $q['used'] : "NULL").", quota_limit = ".($q ? (int) $q['limit'] : "NULL")
					." WHERE rowid = ".((int) $account->id));
			}
		}
		$rows = array_filter($folders->listForAccount((int) $account->id), function ($f) {
			return (int) $f->selectable === 1 && (int) $f->sync_enabled === 1;
		});
		if ($onlyFolder > 0) {
			$rows = array_filter($rows, function ($f) use ($onlyFolder) {
				return (int) $f->rowid === $onlyFolder;
			});
			$force = true;
		}
		// Order: inbox and sent first, then the others (due ones), then the first indexing
		$first = array();
		$others = array();
		foreach ($rows as $f) {
			if (in_array((string) $f->role, array('inbox', 'sent'), true)) {
				$first[] = $f;
			} else {
				$others[] = $f;
			}
		}
		$queue = array();
		if ($phase !== 2) {
			foreach ($first as $f) {
				$queue[] = array($f, false);
			}
		}
		if ($phase !== 1) {
			foreach ($others as $f) {
				$due = $force || !$f->date_sync || $this->db->jdate($f->date_sync) < dol_now() - self::OTHER_FOLDERS_SECONDS;
				if ($due) {
					$queue[] = array($f, false);
				}
			}
			foreach (array_merge($first, $others) as $f) {
				// A folder that fails pass after pass (1.145.0) is tried again at its usual pace only, not for its
				// first indexing at every pass: a good pass counts its failures from zero, the first indexing goes on
				if (!(int) $f->first_sync_done && (int) ($f->sync_fail_count ?? 0) < InboxFolder::FAILS_SET_ASIDE) {
					$queue[] = array($f, true);
				}
			}
		}
		$maxDays = (int) $account->sync_max_days;
		$firstSyncLeft = false;
		$done = array();
		$failed = array();
		$readOk = array();
		$incomplete = array();
		$reports = array();
		foreach ($queue as $item) {
			list($f, $backfill) = $item;
			if (microtime(true) > $deadline) {
				$firstSyncLeft = true;
				break;
			}
			if (!$backfill && isset($done[$f->rowid])) {
				continue;
			}
			$done[$f->rowid] = true;
			$since = $maxDays > 0 ? dol_now() - $maxDays * 86400 : 0;
			// Trash and junk were indexed on their last 30 days only (before 1.138.1): a cursor of then (no « all ») is
			// emptied once, the folder indexed again in full; the mails already indexed are not read again
			if (in_array((string) $f->role, array('trash', 'junk'), true) && (string) $f->sync_cursor !== '') {
				$cur = json_decode((string) $f->sync_cursor, true);
				if (is_array($cur) && !isset($cur['all'])) {
					$f->sync_cursor = '';
				}
			}
			$folderId = (int) $f->rowid;
			$stats = $mails->folderStats($folderId);
			$maxMails = (int) $account->sync_max_mails;
			$allowed = $maxMails > 0 ? max(0, $maxMails - $stats['count']) : self::CHUNK;
			$res = $connector->getChanges((string) $f->path, (string) $f->sync_cursor, array(
				'max' => max(1, min(self::CHUNK, $allowed ?: 1)),
				'backfill' => $backfill && $allowed > 0,
				'since' => $since,
				'indexed' => $stats['count'],
				'known' => function () use ($mails, $folderId) {
					return $mails->remoteIds($folderId);
				},
			));
			if ($res === null) {
				$folders->saveState($folderId, array('sync_error' => $connector->getError()));
				// Once per pass (a folder may be read twice in a pass: changes, then first indexing)
				if (!isset($failed[$folderId])) {
					$failed[$folderId] = true;
					$folders->countFailure($folderId, true);
				}
				continue;
			}
			// Counted from zero at the end of the pass, if no reading of it failed (1.145.0)
			$readOk[$folderId] = (int) ($f->sync_fail_count ?? 0);
			if ($res['reset']) {
				$mails->clearFolder($folderId);
			}
			$added = $mails->addMany((int) $account->id, $folderId, $res['new']);
			$this->stats['new'] += $added;
			// New mails of the inbox (never the first indexing of the older ones): checked against the blocked senders
			if ((string) $f->role === 'inbox' && !$backfill && $mails->lastNewIds) {
				$this->blockedNew = array_merge($this->blockedNew, array_map('intval', $mails->lastNewIds));
			}
			// Modules subscribed (1.146.0): the new mails of any folder but Sent, Drafts, Junk, Trash (a rule of the
			// server puts a mail at once in « Tickets »), never the first indexing
			if (!$backfill && $mails->lastNewIds && !in_array((string) $f->role, array('sent', 'drafts', 'junk', 'trash'), true)) {
				$this->consumerNew = array_merge($this->consumerNew, array_map('intval', $mails->lastNewIds));
			}
			// Delivery reports among the new mails (1.25.0): their start read (the report comes before the original),
			// linked to the mail sent; never in Sent or Drafts. Read at the end of the pass, once the copy in Sent of
			// the mail they are about is indexed too: a report counts only for a mail sent from this mailbox (INBX-031)
			if (!in_array((string) $f->role, array('sent', 'drafts'), true)) {
				$reports[] = array((string) $f->path, array_filter($res['new'], function ($m) {
					return !empty($m['is_report']);
				}));
			}
			// Answers in an ignored thread arrive read (1.11.0); never in Sent or Drafts
			$readNow = 0;
			if ($mails->lastNewIds && !in_array((string) $f->role, array('sent', 'drafts'), true)) {
				require_once __DIR__.'/inboxthreadmark.class.php';
				$marks = new InboxThreadMark($this->db);
				$readNow = $marks->applyIgnored($connector, (int) $account->id, (string) $f->path, $mails->lastNewIds);
			}
			$indexError = $mails->failed ? $mails->failed.' mail(s) not indexed: '.$mails->lastError : '';
			$flagged = $mails->updateFlags($folderId, $res['changed']);
			$this->stats['changed'] += $flagged;
			// Junk: a known sender, authenticated, is probably legitimate (lot W5 1.15.0; headers of these mails only);
			// setting of Settings > Anti-spam (1.85.0)
			if ((string) $f->role === 'junk' && getDolGlobalInt('INBOX_JUNK_LEGIT_CHECK', 1)) {
				require_once __DIR__.'/inboxknownsenders.class.php';
				$known = new InboxKnownSenders($this->db);
				$known->checkJunk($connector, $folderId, (string) $f->path, $deadline);
				// Taken out of the junk at the end of the pass when the setting says so (1.147.0)
				$this->rescueNew = array_merge($this->rescueNew, $known->lastLegit);
			}
			$removed = $mails->removeMany($folderId, $res['deleted']);
			$this->stats['deleted'] += $removed;
			$this->stats['folders']++;
			// Journal of the changes (1.121.0): one event per folder and pass that found something, never one per mail
			if ($added || $flagged || $removed || $res['reset']) {
				require_once __DIR__.'/inboxevent.class.php';
				(new InboxEvent($this->db))->emit((int) $account->id, 'folder', array(), $folderId);
			}
			$after = $mails->folderStats($folderId);
			$cursor = json_decode((string) $res['cursor'], true);
			$complete = is_array($cursor) && !empty($cursor['done']) || ($maxMails > 0 && $after['count'] >= $maxMails);
			$folders->saveState($folderId, array(
				'sync_cursor' => $res['cursor'], 'total' => $res['total'], 'unread' => max(0, (int) $res['unread'] - $readNow), 'indexed' => $after['count'],
				'oldest_indexed' => $after['oldest'], 'first_sync_done' => $complete ? 1 : 0, 'sync_error' => $indexError,
			));
			$f->first_sync_done = $complete ? 1 : 0;
			$f->sync_cursor = $res['cursor'];
			if (!$complete) {
				$incomplete[$folderId] = true;
			}
		}
		// First indexing left on a folder: the mailbox waits for it, unless another reading of it failed in this pass
		// (1.145.0), then the count of its failures decides (sqlFirstSyncLeft() at the end)
		if (array_diff_key($incomplete, $failed)) {
			$firstSyncLeft = true;
		}
		// A folder read well in this pass, none of its readings failed: its failures counted from zero (1.145.0). Not
		// at each good reading: changes read, then first indexing failed, the folder would never be set aside
		foreach ($readOk as $folderId => $fails) {
			if ($fails > 0 && !isset($failed[$folderId])) {
				$folders->countFailure($folderId, false);
			}
		}
		foreach ($reports as $r) {
			if ($r[1]) {
				$this->bounces($connector, (int) $account->id, $r[0], $r[1]);
			}
		}
		$this->repairAttachments($connector, $rows, $mails, $folders, $deadline);
		$connector->disconnect();
		// Mails indexed before the conversations (1.5.0): keys given within the time left
		if (microtime(true) < $deadline - 1) {
			$threader = new InboxThreader($this->db);
			$threader->backfill((int) $account->id, $deadline - 0.5);
		}
		if ($onlyFolder > 0) {
			// One folder: the state of the mailbox is the business of the full passes
			return 1;
		}
		// A folder that fails pass after pass no longer counts (1.145.0): it stays shown in error in the tab Synchro
		$left = $firstSyncLeft || (bool) $this->db->getRow("SELECT rowid FROM ".$p."inbox_folder WHERE fk_account = ".((int) $account->id)." AND ".InboxFolder::sqlFirstSyncLeft($this->db));
		$this->saveState((int) $account->id, $left ? 'first_sync' : 'ok');

		return 1;
	}

	/**
	 * Delivery reports among new mails: the start of each read, recorded by InboxBounce
	 *
	 * @param InboxConnectorInterface         $connector Connected
	 * @param int                             $accountId Mailbox
	 * @param string                          $path      Folder path
	 * @param array<int,array<string,mixed>>  $new       New mails (common format)
	 * @return void
	 */
	protected function bounces($connector, $accountId, $path, array $new)
	{
		$dates = array();
		foreach ($new as $m) {
			if (!empty($m['is_report']) && !empty($m['remote_id'])) {
				$dates[(string) $m['remote_id']] = (int) ($m['date_received'] ?: $m['date_mail']);
			}
		}
		if (!$dates) {
			return;
		}
		require_once __DIR__.'/inboxbounce.class.php';
		$starts = $connector->fetchStarts($path, array_slice(array_keys($dates), 0, 200), 65536);
		$bounce = new InboxBounce($this->db);
		foreach (is_array($starts) ? $starts : array() as $rid => $raw) {
			$bounce->record($accountId, (string) $raw, $dates[$rid] ?? 0);
		}
	}

	/**
	 * Attachments of the mails indexed before 1.7.1 read again from their structure (the decoder of Webklex merged the
	 * sub parts written « NIL)( », most attachments were not seen), the most recent first, within the time left
	 *
	 * @param InboxConnectorInterface $connector Connected
	 * @param array<int,object>        $rows      Folders followed
	 * @param InboxMail                $mails     Helper
	 * @param InboxFolder              $folders   Helper
	 * @param float                    $deadline  Microtime limit
	 * @return void
	 */
	protected function repairAttachments($connector, array $rows, InboxMail $mails, InboxFolder $folders, $deadline)
	{
		if (!method_exists($connector, 'attachmentFlags')) {
			return;
		}
		foreach ($rows as $f) {
			$below = (int) ($f->att_repair ?? -1);
			if ($below === -1) {
				continue;
			}
			$byUid = array();
			foreach ($mails->remoteIds((int) $f->rowid) as $rid) {
				$uid = (int) substr($rid, (int) strpos($rid, ':') + 1);
				if ($below === 0 || $uid < $below) {
					$byUid[$uid] = $rid;
				}
			}
			krsort($byUid);
			while (microtime(true) < $deadline - 2) {
				if (!$byUid) {
					$folders->setAttRepair((int) $f->rowid, -1);
					break;
				}
				$take = array_slice($byUid, 0, self::REPAIR_CHUNK, true);
				$flags = $connector->attachmentFlags((string) $f->path, array_values($take));
				if ($flags === null) {
					break;
				}
				$mails->setAttachments((int) $f->rowid, $flags);
				$byUid = array_slice($byUid, count($take), null, true);
				$folders->setAttRepair((int) $f->rowid, $byUid ? min(array_keys($take)) : -1);
				if (!$byUid) {
					break;
				}
			}
		}
	}

	/**
	 * Progress of the mailbox (tab Synchro)
	 *
	 * @param int $accountId Mailbox
	 * @return array{indexed:int,total:int,folders:int,first_sync_left:int}
	 */
	public function progress($accountId)
	{
		$row = $this->db->getRow("SELECT SUM(indexed) AS i, SUM(total) AS t, COUNT(rowid) AS n, SUM(CASE WHEN ".InboxFolder::sqlFirstSyncLeft($this->db)." THEN 1 ELSE 0 END) AS left_n"
			." FROM ".$this->db->prefix()."inbox_folder WHERE fk_account = ".((int) $accountId)." AND status = 1");

		return array('indexed' => (int) ($row->i ?? 0), 'total' => (int) ($row->t ?? 0), 'folders' => (int) ($row->n ?? 0), 'first_sync_left' => (int) ($row->left_n ?? 0));
	}

	/**
	 * Scheduled job « Synchronise the mailboxes » (every minute): inbox and sent of every active mailbox first, then the
	 * other folders and the first indexing, within the time budget (INBOX_SYNC_BUDGET, 45 s by default)
	 *
	 * @return int 0 if OK (output in $this->output)
	 */
	public function cronRun()
	{
		global $conf;

		$start = microtime(true);
		$deadline = $start + max(10, getDolGlobalInt('INBOX_SYNC_BUDGET', 45));
		$rows = $this->db->getRows("SELECT rowid FROM ".$this->db->prefix()."inbox_account WHERE entity = ".((int) $conf->entity)." AND status = ".InboxAccount::STATUS_ACTIVE." AND protected = 0 ORDER BY date_sync, rowid LIMIT 500");
		$accounts = array();
		foreach (is_array($rows) ? $rows : array() as $r) {
			$a = new InboxAccount($this->db);
			if ($a->fetch((int) $r->rowid) > 0) {
				$accounts[] = $a;
			}
		}
		$total = array('new' => 0, 'changed' => 0, 'deleted' => 0, 'errors' => 0);
		foreach (array(1, 2) as $phase) {
			foreach ($accounts as $a) {
				if (microtime(true) > $deadline) {
					break 2;
				}
				$res = $this->run($a, $deadline, array('phase' => $phase));
				if ($res < 0) {
					$total['errors']++;
				}
				foreach (array('new', 'changed', 'deleted') as $k) {
					$total[$k] += $this->stats[$k] ?? 0;
				}
			}
		}
		// Blocked senders (1.85.0): the Sieve block written again where the list changed, with the time left
		$blocked = 0;
		if (microtime(true) < $deadline - 2) {
			require_once __DIR__.'/inboxblockedsender.class.php';
			$blocked = (new InboxBlockedSender($this->db))->applyAll($deadline);
		}
		// Anti-spam engine of inbox (1.88.0): « Junk » and « Not junk » of the users learned, with the time left
		$learned = 0;
		require_once __DIR__.'/inboxspamcheck.class.php';
		if (InboxSpamCheck::enabled() && microtime(true) < $deadline - 2) {
			$learned = (new InboxSpamCheck($this->db))->processQueue($deadline)['learned'];
		}
		$this->output = ($blocked ? $blocked.' Sieve block(s) of the blocked senders written, ' : '').($learned ? $learned.' mail(s) learned by the anti-spam engine, ' : '').count($accounts).' mailbox(es), '.$total['new'].' new, '.$total['changed'].' changed, '.$total['deleted'].' removed, '.$total['errors'].' error(s), '.round(microtime(true) - $start, 1).' s';

		return 0;
	}

	/** @var string Output of the scheduled job */
	public $output = '';
}
