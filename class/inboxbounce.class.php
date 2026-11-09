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
 * \file    inbox/class/inboxbounce.class.php
 * \ingroup inbox
 * \brief   Delivery reports of the mails sent (DSN, RFC 3464): read, linked to the mail sent, told (1.25.0)
 */

require_once __DIR__.'/inboxmimeparser.class.php';
require_once __DIR__.'/inboxmailref.class.php';
require_once __DIR__.'/inboxnotification.class.php';

/**
 * Only the standard report is read: multipart/report; report-type=delivery-status (Postfix, Exim, Gmail, Exchange,
 * OVH). The other bounces (free text) are left as ordinary mails: nothing is guessed (analyse-non-remise §7).
 * A report gives, per recipient, Action (failed, delayed...), Status (5.1.1...) and the sentence of the remote
 * server; the mail sent is found by the Message-ID of the original part.
 */
class InboxBounce
{
	/** A report older than this (s) is recorded, never told (first indexing of a mailbox with years of reports) */
	const TELL_WITHIN = 604800;

	/** Recipients read in one report at most */
	const MAX_RECIPIENTS = 20;

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
	 * @param string $raw Source of a mail (whole, or its start: the report comes before the original)
	 * @return bool The mail is a standard delivery report
	 */
	public static function isReport($raw)
	{
		$type = self::header(self::headBlock((string) $raw), 'Content-Type');

		return (bool) preg_match('#^multipart/report\b#i', $type) && stripos($type, 'delivery-status') !== false;
	}

	/**
	 * Read a delivery report
	 *
	 * @param string $raw Source (whole, or at least its first 64 KB)
	 * @return array{report_id:string,original:string,recipients:array<int,array{email:string,action:string,status:string,family:string,diagnostic:string}>}|null
	 */
	public static function parse($raw)
	{
		$raw = substr((string) $raw, 0, 524288);
		$head = self::headBlock($raw);
		$type = self::header($head, 'Content-Type');
		if (!preg_match('#^multipart/report\b#i', $type) || stripos($type, 'delivery-status') === false) {
			return null;
		}
		$status = '';
		$original = '';
		$boundary = preg_match('/boundary\s*=\s*"([^"]{1,200})"|boundary\s*=\s*([^\s;]{1,200})/i', $type, $b) ? ($b[1] !== '' ? $b[1] : $b[2]) : '';
		if ($boundary !== '') {
			$parts = preg_split('/\r?\n--'.preg_quote($boundary, '/').'(?:--)?[ \t]*(?=\r?\n|$)/', "\n".substr($raw, strlen($head)));
			foreach (array_slice((array) $parts, 1) as $part) {
				$part = ltrim($part, "\r\n");
				$partHead = self::headBlock($part);
				$partType = strtolower(self::header($partHead, 'Content-Type'));
				$body = (string) substr($part, strlen($partHead));
				$encoding = strtolower(self::header($partHead, 'Content-Transfer-Encoding'));
				if ($encoding === 'base64') {
					$body = (string) base64_decode(preg_replace('/\s+/', '', $body));
				} elseif ($encoding === 'quoted-printable') {
					$body = quoted_printable_decode($body);
				}
				if (preg_match('#^message/(global-)?delivery-status\b#', $partType)) {
					$status = $body;
				} elseif ($original === '' && preg_match('#^(message/rfc822|text/rfc822-headers|message/global|message/global-headers)\b#', $partType)) {
					$original = self::messageId(self::headBlock(ltrim($body, "\r\n")));
				}
			}
		}
		if ($status === '') {
			// No boundary readable: the fields of the report are searched in the whole source
			$status = $raw;
		}
		if ($original === '') {
			// Some servers give only In-Reply-To / References on the report
			$original = self::messageId($head, 'In-Reply-To');
			if ($original === '' && preg_match('/<([^<>\s]{1,250})>/', self::header($head, 'References'), $r)) {
				$original = $r[1];
			}
		}
		$recipients = array();
		$groups = preg_split('/\r?\n[ \t]*\r?\n/', trim(str_replace("\r", '', $status)));
		foreach ((array) $groups as $group) {
			$fields = self::fields($group);
			$email = '';
			foreach (array('final-recipient', 'original-recipient') as $k) {
				if ($email === '' && isset($fields[$k]) && preg_match('/^(?:[^;]*;)?\s*<?([^\s<>;]+@[^\s<>;]+)>?/', $fields[$k], $m)) {
					$email = strtolower(InboxMimeParser::clean($m[1]));
				}
			}
			if ($email === '' || !isset($fields['action'])) {
				continue;
			}
			$action = strtolower(trim(strtok($fields['action'], " \t(;")));
			if (!in_array($action, array('failed', 'delayed'), true)) {
				// delivered, relayed, expanded: nothing went wrong for this one
				continue;
			}
			$code = isset($fields['status']) && preg_match('/^\s*([245]\.\d{1,3}\.\d{1,3})/', $fields['status'], $m) ? $m[1] : '';
			$diagnostic = '';
			if (isset($fields['diagnostic-code'])) {
				// Text of the remote server, given by anyone who sends a report: no < > (never a tag, wherever it
				// goes, audit INBX-030)
				$diagnostic = preg_replace('/^[^;]{0,20};\s*/', '', $fields['diagnostic-code']);
				$diagnostic = str_replace(array('<', '>'), '', InboxMimeParser::clean(preg_replace('/\s+/', ' ', (string) $diagnostic)));
				$diagnostic = mb_substr(trim($diagnostic), 0, 300, 'UTF-8');
			}
			$recipients[$email] = array('email' => $email, 'action' => $action, 'status' => $code, 'family' => self::family($action, $code), 'diagnostic' => $diagnostic);
			if (count($recipients) >= self::MAX_RECIPIENTS) {
				break;
			}
		}
		if (!$recipients) {
			return null;
		}
		$reportId = self::messageId($head);

		return array(
			'report_id' => $reportId !== '' ? $reportId : 'fp:'.sha1(substr($raw, 0, 8192)),
			'original' => $original,
			'recipients' => array_values($recipients),
		);
	}

	/**
	 * What happened, in words a person understands (key InboxBounce_<family>)
	 *
	 * @param string $action failed or delayed
	 * @param string $status Status code (5.1.1), '' if none
	 * @return string no_user, no_domain, full, too_big, refused, temporary, delayed
	 */
	public static function family($action, $status)
	{
		if ($action === 'delayed') {
			return 'delayed';
		}
		$families = array(
			'no_user' => array('5.1.0', '5.1.1', '5.1.6'),
			'no_domain' => array('5.1.2', '4.4.4', '5.4.4', '5.1.10'),
			'full' => array('5.2.2', '4.2.2'),
			'too_big' => array('5.3.4', '5.2.3'),
			'refused' => array('5.2.1'),
		);
		foreach ($families as $family => $codes) {
			if (in_array($status, $codes, true)) {
				return $family;
			}
		}
		if (strpos($status, '5.7.') === 0) {
			return 'refused';
		}

		return strpos($status, '4.') === 0 ? 'temporary' : 'refused';
	}

	/**
	 * Record a report met by the synchronisation: one line per recipient; for a new failure of a recent report, the
	 * author of the sending is told and an event is added on the pieces of Dolibarr where the mail was recorded
	 *
	 * @param int    $accountId  Mailbox where the report arrived
	 * @param string $raw        Source of the report (its start)
	 * @param int    $dateReport Date of the report
	 * @return int Lines added, < 0 if KO
	 */
	public function record($accountId, $raw, $dateReport)
	{
		global $conf;

		$report = self::parse($raw);
		if ($report === null || $report['original'] === '') {
			return 0;
		}
		// A report counts only in the mailbox the mail was sent from: anyone who received the mail knows its
		// Message-ID and could send a report about it to another mailbox (audit INBX-031)
		if (!$this->sentFrom($report['original'], (int) $accountId)) {
			return 0;
		}
		$p = $this->db->prefix();
		$now = dol_now();
		$added = array();
		foreach ($report['recipients'] as $r) {
			$sql = "INSERT INTO ".$p."inbox_bounce (entity, message_id, report_id, fk_account, recipient, action, status, diagnostic, date_report, date_creation) VALUES ("
				.((int) $conf->entity).", '".$this->db->escape(substr($report['original'], 0, 255))."', '".$this->db->escape(substr($report['report_id'], 0, 255))."',"
				." ".((int) $accountId).", '".$this->db->escape(substr($r['email'], 0, 255))."', '".$this->db->escape($r['action'])."',"
				." ".($r['status'] !== '' ? "'".$this->db->escape($r['status'])."'" : 'NULL').", ".($r['diagnostic'] !== '' ? "'".$this->db->escape($r['diagnostic'])."'" : 'NULL').","
				." ".($dateReport ? "'".$this->db->idate((int) $dateReport)."'" : 'NULL').", '".$this->db->idate($now)."')";
			// The same report seen again (another folder, a new indexing): the unique key refuses it, nothing told twice
			$exists = $this->db->getRow("SELECT rowid FROM ".$p."inbox_bounce WHERE entity = ".((int) $conf->entity)
				." AND report_id = '".$this->db->escape(substr($report['report_id'], 0, 255))."' AND recipient = '".$this->db->escape(substr($r['email'], 0, 255))."'");
			if (is_object($exists)) {
				continue;
			}
			if (!$this->db->query($sql)) {
				$this->error = $this->db->lasterror();
				continue;
			}
			$added[] = $r;
		}
		$failed = array_values(array_filter($added, function ($r) {
			return $r['action'] === 'failed';
		}));
		if ($failed && (!$dateReport || (int) $dateReport >= $now - self::TELL_WITHIN)) {
			$this->tell($report['original'], $failed, (int) $accountId);
		}

		return count($added);
	}

	/**
	 * The mail was sent from this mailbox: its sending by inbox, else a copy in its folder Sent, else an event of
	 * Dolibarr recording a sending from its address or from one of its identities (a piece sent by mail)
	 *
	 * @param string $messageId Message-ID of the mail sent, without < >
	 * @param int    $accountId Mailbox where the report arrived
	 * @return bool
	 */
	protected function sentFrom($messageId, $accountId)
	{
		global $conf;

		$p = $this->db->prefix();
		$ids = "('".$this->db->escape($messageId)."', '<".$this->db->escape($messageId).">')";
		$sent = $this->db->getRow("SELECT fk_account FROM ".$p."inbox_outbox WHERE entity = ".((int) $conf->entity)." AND message_id IN ".$ids." ORDER BY rowid DESC");
		if (is_object($sent)) {
			return (int) $sent->fk_account === (int) $accountId;
		}
		$copy = $this->db->getRow("SELECT m.rowid FROM ".$p."inbox_mail as m INNER JOIN ".$p."inbox_folder as f ON f.rowid = m.fk_folder AND f.role = 'sent'"
			." WHERE m.fk_account = ".((int) $accountId)." AND m.message_id = '".$this->db->escape($messageId)."'");
		if (is_object($copy)) {
			return true;
		}
		$addresses = array();
		$rows = $this->db->getRows("SELECT email FROM ".$p."inbox_account WHERE rowid = ".((int) $accountId)." AND entity = ".((int) $conf->entity)
			." UNION SELECT email FROM ".$p."inbox_identity WHERE fk_account = ".((int) $accountId)." LIMIT 500");
		foreach (is_array($rows) ? $rows : array() as $r) {
			$addresses[strtolower(trim((string) $r->email))] = true;
		}
		$events = $this->db->getRows("SELECT email_from FROM ".$p."actioncomm WHERE entity = ".((int) $conf->entity)." AND email_msgid IN ".$ids." LIMIT 20");
		foreach (is_array($events) ? $events : array() as $e) {
			$from = (string) $e->email_from;
			$from = strtolower(trim(preg_match('/<([^<>]+)>/', $from, $m) ? $m[1] : $from));
			if ($from !== '' && isset($addresses[$from])) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Note of an event of Dolibarr from a text: escaped, lines kept; the screens of Dolibarr show a note as HTML
	 * (audit INBX-030)
	 *
	 * @param string $text Text
	 * @return string HTML
	 */
	public static function noteHtml($text)
	{
		return dol_nl2br(dol_escape_htmltag((string) $text, 0, 1));
	}

	/**
	 * Tell a failure: the bell of the author of the sending (sent by inbox), an event on the pieces of the mail sent
	 *
	 * @param string $original  Message-ID of the mail sent
	 * @param array<int,array{email:string,action:string,status:string,family:string,diagnostic:string}> $failed Recipients
	 * @param int    $accountId Mailbox the mail was sent from (where the report arrived)
	 * @return void
	 */
	protected function tell($original, array $failed, $accountId)
	{
		global $conf, $langs, $user;

		$p = $this->db->prefix();
		$emails = implode(', ', array_column($failed, 'email'));
		$sent = $this->db->getRow("SELECT fk_user, fk_account, subject FROM ".$p."inbox_outbox WHERE entity = ".((int) $conf->entity)
			." AND message_id IN ('".$this->db->escape($original)."', '<".$this->db->escape($original).">') ORDER BY rowid DESC");
		$subject = is_object($sent) ? (string) $sent->subject : '';
		if ($subject === '') {
			$known = $this->db->getRow("SELECT subject FROM ".$p."inbox_mail WHERE fk_account = ".((int) $accountId)." AND message_id = '".$this->db->escape($original)."'");
			$subject = is_object($known) ? (string) $known->subject : '';
		}
		$author = null;
		if (is_object($sent) && (int) $sent->fk_user > 0) {
			$author = new User($this->db);
			if ($author->fetch((int) $sent->fk_user) <= 0) {
				$author = null;
			}
		}
		if ($author) {
			$refs = new InboxMailRef($this->db);
			$mailref = $refs->fetchOrCreateByMessageId($original, array('subject' => $subject, 'origin' => 'inbox', 'last_account' => (int) $sent->fk_account));
			$n = new InboxNotification($this->db);
			$n->add((int) $author->id, 'bounce', array('mailref' => $mailref > 0 ? $mailref : 0, 'account' => (int) $sent->fk_account,
				'payload' => array('subject' => $subject, 'detail' => $emails)));
		}

		// The pieces where the mail was recorded (by inbox, or by the sending of Dolibarr itself): the same event
		$events = $this->db->getRows("SELECT DISTINCT elementtype, fk_element, fk_soc, fk_contact FROM ".$p."actioncomm WHERE entity = ".((int) $conf->entity)
			." AND email_msgid IN ('".$this->db->escape($original)."', '<".$this->db->escape($original).">') AND (code IS NULL OR code <> 'AC_INBOX_BOUNCE') LIMIT 20");
		$owner = $author ? $author : ((is_object($user) && (int) $user->id > 0) ? $user : null);
		if (!is_array($events) || !$events || !$owner) {
			return;
		}
		require_once DOL_DOCUMENT_ROOT.'/comm/action/class/actioncomm.class.php';
		$langs->load('inbox@inbox');
		$note = array();
		foreach ($failed as $r) {
			$note[] = $r['email'].' : '.$langs->transnoentities('InboxBounce_'.$r['family']).($r['status'] !== '' ? ' ('.$r['status'].')' : '')
				.($r['diagnostic'] !== '' ? "\n".$langs->transnoentities('InboxBounceServerSaid', $r['diagnostic']) : '');
		}
		foreach ($events as $e) {
			$event = new ActionComm($this->db);
			$event->type_code = 'AC_OTH_AUTO';
			$event->code = 'AC_INBOX_BOUNCE';
			$event->label = dol_trunc($langs->transnoentities('InboxEventNotDelivered', $emails, $subject), 250, 'right', 'UTF-8', 1);
			$event->note_private = self::noteHtml(implode("\n\n", $note));
			$event->datep = dol_now();
			$event->datef = dol_now();
			$event->percentage = -1;
			$event->userownerid = (int) $owner->id;
			$event->socid = (int) $e->fk_soc;
			$event->contact_id = (int) $e->fk_contact;
			$event->elementtype = (string) $e->elementtype;
			$event->fk_element = (int) $e->fk_element;
			if ($event->create($owner) <= 0) {
				dol_syslog(__METHOD__.' event: '.$event->error, LOG_WARNING);
			}
		}
	}

	/**
	 * The report read, for the reading pane: per recipient what happened, and where the mail sent is
	 *
	 * @param string $raw      Source of the report
	 * @param int[]  $accounts Mailboxes the user reads
	 * @return array<string,mixed>|null
	 */
	public function view($raw, array $accounts)
	{
		$report = self::parse($raw);
		if ($report === null) {
			return null;
		}

		return array('recipients' => $report['recipients'], 'sent' => $report['original'] !== '' ? $this->locate($report['original'], $accounts, true) : null);
	}

	/**
	 * Failures known for a mail sent, for the reading pane: recipient, family, code, sentence, where the report is
	 *
	 * @param string $messageId Message-ID of the mail
	 * @param int[]  $accounts  Mailboxes the user reads
	 * @return array<int,array<string,mixed>>
	 */
	public function forMail($messageId, array $accounts)
	{
		global $conf;

		$messageId = trim((string) $messageId, " <>\t");
		$accounts = array_filter(array_map('intval', $accounts));
		if ($messageId === '' || !$accounts) {
			return array();
		}
		// Reports arrived in a mailbox the user reads (audit INBX-031)
		$rows = $this->db->getRows("SELECT recipient, status, diagnostic, report_id FROM ".$this->db->prefix()."inbox_bounce WHERE entity = ".((int) $conf->entity)
			." AND message_id = '".$this->db->escape($messageId)."' AND fk_account IN (".implode(',', $accounts).") AND action = 'failed' ORDER BY rowid LIMIT 50");
		$out = array();
		foreach (is_array($rows) ? $rows : array() as $r) {
			$out[(string) $r->recipient] = array(
				'email' => (string) $r->recipient, 'status' => (string) $r->status, 'family' => self::family('failed', (string) $r->status),
				'diagnostic' => (string) $r->diagnostic, 'report' => $this->locate((string) $r->report_id, $accounts, false),
			);
		}

		return array_values($out);
	}

	/**
	 * Rows of a list whose mail was not delivered to someone: « bounced » = 1
	 *
	 * @param array<int,array<string,mixed>> $rows Rows formatted (id)
	 * @return void
	 */
	public function decorate(array &$rows)
	{
		global $conf;

		$ids = array_filter(array_map(function ($r) {
			return (int) $r['id'];
		}, $rows));
		$hit = array();
		if ($ids) {
			$p = $this->db->prefix();
			$found = $this->db->getRows("SELECT m.rowid FROM ".$p."inbox_mail as m WHERE m.rowid IN (".implode(',', $ids).") AND m.message_id IS NOT NULL AND m.message_id <> ''"
				." AND EXISTS (SELECT 1 FROM ".$p."inbox_bounce as b WHERE b.message_id = m.message_id AND b.fk_account = m.fk_account AND b.entity = ".((int) $conf->entity)." AND b.action = 'failed') LIMIT 1000");
			foreach (is_array($found) ? $found : array() as $f) {
				$hit[(int) $f->rowid] = true;
			}
		}
		foreach ($rows as $i => $r) {
			$rows[$i]['bounced'] = isset($hit[(int) $r['id']]) ? 1 : 0;
		}
	}

	/**
	 * A copy of a mail in the mailboxes given (the copy in Sent first for a mail sent)
	 *
	 * @param string $messageId Message-ID
	 * @param int[]  $accounts  Mailboxes
	 * @param bool   $sentFirst Prefer the folder Sent
	 * @return array{mail:int,folder:int}|null
	 */
	protected function locate($messageId, array $accounts, $sentFirst)
	{
		$accounts = array_filter(array_map('intval', $accounts));
		if (!$accounts || $messageId === '' || strpos($messageId, 'fp:') === 0) {
			return null;
		}
		$p = $this->db->prefix();
		$row = $this->db->getRow("SELECT m.rowid, m.fk_folder FROM ".$p."inbox_mail as m INNER JOIN ".$p."inbox_folder as f ON f.rowid = m.fk_folder AND f.status = 1"
			." WHERE m.message_id = '".$this->db->escape($messageId)."' AND m.fk_account IN (".implode(',', $accounts).")"
			." ORDER BY CASE WHEN f.role IN ('trash', 'junk') THEN 2 WHEN f.role = '".($sentFirst ? 'sent' : 'inbox')."' THEN 0 ELSE 1 END, m.rowid DESC");

		return is_object($row) ? array('mail' => (int) $row->rowid, 'folder' => (int) $row->fk_folder) : null;
	}

	/**
	 * @param string $raw Source
	 * @return string Header block (up to the first empty line, included)
	 */
	protected static function headBlock($raw)
	{
		if (preg_match('/\r?\n\r?\n/', $raw, $m, PREG_OFFSET_CAPTURE)) {
			return substr($raw, 0, $m[0][1] + strlen($m[0][0]));
		}

		return $raw;
	}

	/**
	 * @param string $head Header block
	 * @param string $name Header name
	 * @return string Value unfolded, '' if none
	 */
	protected static function header($head, $name)
	{
		if (preg_match('/^'.preg_quote($name, '/').':[ \t]*([^\r\n]*(?:\r?\n[ \t][^\r\n]*)*)/mi', $head, $m)) {
			return trim(preg_replace('/\r?\n[ \t]+/', ' ', $m[1]));
		}

		return '';
	}

	/**
	 * @param string $head Header block
	 * @param string $name Message-ID or In-Reply-To
	 * @return string Identifier without < >, '' if none
	 */
	protected static function messageId($head, $name = 'Message-ID')
	{
		$value = self::header($head, $name);
		if (preg_match('/<([^<>\s]{1,250})>/', $value, $m) || preg_match('/^([^<>\s]{1,250}@[^<>\s]{1,250})$/', $value, $m)) {
			return $m[1];
		}

		return '';
	}

	/**
	 * @param string $group Lines of one group of the delivery status
	 * @return array<string,string> Field (lower case) => value unfolded
	 */
	protected static function fields($group)
	{
		$out = array();
		$group = preg_replace('/\n[ \t]+/', ' ', (string) $group);
		foreach (explode("\n", (string) $group) as $line) {
			if (preg_match('/^([A-Za-z-]{1,40}):\s*(.*)$/', $line, $m)) {
				$k = strtolower($m[1]);
				if (!isset($out[$k])) {
					$out[$k] = trim($m[2]);
				}
			}
		}

		return $out;
	}
}
