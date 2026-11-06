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
 * \file    inbox/class/inboxmover.class.php
 * \ingroup inbox
 * \brief   Move mails to another mailbox (webmail §5.4 bis): copy, check each copy, then only remove at the source
 */

require_once __DIR__.'/inboxaccount.class.php';
require_once __DIR__.'/inboxactionlog.class.php';
require_once __DIR__.'/inboxsync.class.php';
require_once __DIR__.'/connector/inboxconnectorfactory.class.php';

/**
 * Nothing is lost: a mail is removed at the source only when its copy was found on the target server (APPENDUID, or
 * its Message-ID). A refused copy leaves the source as it was. Time limited: the mails not done are given back, the
 * browser sends them in the next call.
 */
class InboxMover
{
	/** Seconds of copy per call */
	const BUDGET = 20;

	/** @var DoliDB */
	protected $db;

	/** @var string */
	public $error = '';

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct(DoliDB $db)
	{
		$this->db = $db;
	}

	/**
	 * @param User     $user   User (rights already checked on both mailboxes)
	 * @param object[] $list   Rows of one folder of the source mailbox
	 * @param object   $target Target folder (rowid, fk_account, path)
	 * @param string   $kind   Action of the journal
	 * @return array<string,mixed>|null count, undo, ids, rest (rows not done for lack of time)
	 */
	public function move(User $user, array $list, $target, $kind = 'move')
	{
		$first = $list[0];
		$source = new InboxAccount($this->db);
		$dest = new InboxAccount($this->db);
		if ($source->fetch((int) $first->fk_account) <= 0 || $dest->fetch((int) $target->fk_account) <= 0 || !$dest->isActive()) {
			$this->error = 'InboxErrorFolderNotFound';
			return null;
		}
		$error = '';
		$from = InboxConnectorFactory::forAccount($this->db, $source, $error);
		$to = $from ? InboxConnectorFactory::forAccount($this->db, $dest, $error) : null;
		if (!$from || !$to) {
			$this->error = $error;
			return null;
		}
		if (!$to->can(InboxConnectorInterface::CAP_IMPORT)) {
			$this->error = 'InboxErrorNoImport';
			return null;
		}
		$deadline = microtime(true) + self::BUDGET;
		$copied = array();
		$rest = array();
		$failure = '';
		foreach ($list as $r) {
			if ($failure !== '' || ($copied && microtime(true) > $deadline)) {
				$rest[] = (int) $r->rowid;
				continue;
			}
			$raw = $from->fetchRaw((string) $first->path, (string) $r->remote_id);
			if ($raw === null) {
				$failure = $from->getError();
				$rest[] = (int) $r->rowid;
				continue;
			}
			$flags = array();
			foreach (array('seen' => '\\Seen', 'answered' => '\\Answered', 'flagged' => '\\Flagged') as $col => $flag) {
				if ((int) $r->$col) {
					$flags[] = $flag;
				}
			}
			foreach (array_filter(explode(' ', (string) $r->keywords)) as $k) {
				$flags[] = $k;
			}
			$date = $r->date_received ? $this->db->jdate($r->date_received) : null;
			$new = $to->importRaw((string) $target->path, $raw, $flags, $date);
			if ($new === null || $new === '') {
				// Refused, or a copy that cannot be found again (no APPENDUID, no Message-ID): the source stays
				$failure = $new === '' ? 'InboxErrorCopyNotChecked' : $to->getError();
				$rest[] = (int) $r->rowid;
				continue;
			}
			$copied[(int) $r->rowid] = $r;
		}
		$to->disconnect();
		if (!$copied) {
			$from->disconnect();
			$this->error = $failure !== '' ? $failure : 'InboxErrorCopy';
			return null;
		}
		// All the copies are there: now the source
		$remote = array_map(function ($r) {
			return (string) $r->remote_id;
		}, array_values($copied));
		$removed = $from->deleteMails((string) $first->path, $remote);
		$removeError = $removed ? '' : $from->getError();
		$from->disconnect();
		$p = $this->db->prefix();
		if ($removed) {
			$rowIds = array_keys($copied);
			$this->db->query("DELETE FROM ".$p."inbox_mail_address WHERE fk_mail IN (".implode(',', $rowIds).")");
			$this->db->query("DELETE FROM ".$p."inbox_mail WHERE rowid IN (".implode(',', $rowIds).")");
			$n = count($copied);
			$u = count(array_filter($copied, function ($r) {
				return !(int) $r->seen;
			}));
			$this->db->query("UPDATE ".$p."inbox_folder SET total = GREATEST(0, total - ".$n."), unread = GREATEST(0, unread - ".$u."), indexed = GREATEST(0, indexed - ".$n.")"
				." WHERE rowid = ".((int) $first->fk_folder));
		}
		// The target folder read now: the copies get their rows (and the undo their ids)
		$sync = new InboxSync($this->db);
		$sync->run($dest, microtime(true) + 8, array('folder_id' => (int) $target->rowid));
		$ids = array();
		foreach ($copied as $rowId => $r) {
			if ((string) $r->message_id !== '') {
				$row = $this->db->getRow("SELECT rowid FROM ".$p."inbox_mail WHERE fk_folder = ".((int) $target->rowid)." AND message_id = '".$this->db->escape((string) $r->message_id)."'"
					." ORDER BY sort_key DESC");
				if (is_object($row)) {
					$ids[$rowId] = (int) $row->rowid;
				}
			}
		}
		InboxActionLog::add($this->db, $user, (int) $first->fk_account, $kind, array_values($copied), (string) $first->path, (string) $target->path, (int) $target->fk_account,
			$removed ? '' : 'source kept');
		$source->context['inbox'] = array('from' => (string) $first->path, 'to' => (string) $target->path, 'account_to' => (int) $target->fk_account,
			'message_ids' => array_values(array_filter(array_map('strval', array_column(array_values($copied), 'message_id')))));
		$source->call_trigger('INBOX_MAIL_MOVE', $user);
		if (!$removed) {
			// Copied but still at the source: told, never lost (the user removes the source copy by hand)
			$this->error = 'InboxErrorSourceKept';
			dol_syslog(__METHOD__.' source kept: '.$removeError, LOG_WARNING);
		}

		return array('count' => count($copied), 'ids' => $ids, 'rest' => $rest, 'partial_error' => $failure !== '' ? $failure : $this->error,
			'undo' => ($removed && $ids) ? array(array('action' => 'move', 'ids' => array_values($ids), 'folder' => (int) $first->fk_folder)) : array());
	}
}
