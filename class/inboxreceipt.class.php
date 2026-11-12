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
 * \file    inbox/class/inboxreceipt.class.php
 * \ingroup inbox
 * \brief   Read receipts asked by a mail (lot W8 1.22.0): never sent without a click (RFC 8098)
 */

require_once __DIR__.'/inboxsystemmail.class.php';
require_once __DIR__.'/inboxreader.class.php';
require_once __DIR__.'/inboxaccess.class.php';
require_once __DIR__.'/inboxactionlog.class.php';

/**
 * Sent or ignored, the mail gets the keyword $MDNSent on the server, the one Thunderbird sets and reads: the question is
 * asked once, for every user and every mail software.
 */
class InboxReceipt
{
	/** Keyword of the mails whose receipt was handled */
	const KEYWORD = '$MDNSent';

	/** @var DoliDB */
	protected $db;

	/** @var string */
	public $error = '';

	/** @var string */
	public $errorCode = '';

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct(DoliDB $db)
	{
		$this->db = $db;
	}

	/**
	 * @param string $value Header value
	 * @return string First address in it, lower case, '' if none
	 */
	public static function firstAddress($value)
	{
		if (preg_match('/<([^<>\s]+@[^<>\s]+)>/', (string) $value, $m) || preg_match('/([^\s<>",;]+@[^\s<>",;]+)/', (string) $value, $m)) {
			$email = strtolower(trim($m[1]));
			return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
		}

		return '';
	}

	/**
	 * What the reading pane shows, null when nothing is to ask
	 *
	 * @param InboxReader                         $reader  Mail loaded
	 * @param array<int,array{0:string,1:string}> $headers Headers
	 * @param string                              $mode    Setting mdn_mode: ask or never
	 * @return array{to:string,abuse:bool}|null abuse: the address asked is not the one of the return path (RFC 8098 §2.1), only a discreet mention
	 */
	public static function view(InboxReader $reader, array $headers, $mode)
	{
		$to = self::firstAddress(InboxReader::headerValue($headers, 'Disposition-Notification-To'));
		if ($to === '' || $mode === 'never' || (string) $reader->row->folder_role === 'junk'
			|| !InboxAccess::atLeast($reader->level, InboxAccess::LEVEL_SEND)) {
			return null;
		}
		foreach (preg_split('/\s+/', strtolower((string) $reader->row->keywords)) as $k) {
			if ($k === strtolower(self::KEYWORD)) {
				return null;
			}
		}
		// Our own mail (the copy in Sent of a mail asking a receipt): nothing to answer
		if (strcasecmp((string) $reader->row->from_email, (string) $reader->account->email) === 0 || (string) $reader->row->folder_role === 'sent') {
			return null;
		}
		$returnPath = self::firstAddress(InboxReader::headerValue($headers, 'Return-Path'));

		return array('to' => $to, 'abuse' => $returnPath !== '' && $returnPath !== $to);
	}

	/**
	 * Send the receipt, or ignore the question; both set the keyword
	 *
	 * @param User $user   User
	 * @param int  $mailId Mail
	 * @param bool $send   Send (else ignore)
	 * @return array{sent:bool}|null
	 */
	public function answer(User $user, $mailId, $send)
	{
		global $langs;

		$this->error = '';
		$this->errorCode = '';
		$reader = new InboxReader($this->db);
		if ((int) $mailId <= 0 || !$reader->load($user, (int) $mailId)) {
			$this->errorCode = 'not_found';
			return null;
		}
		if (!InboxAccess::atLeast($reader->level, InboxAccess::LEVEL_SEND)) {
			$this->errorCode = 'no_send';
			return null;
		}
		$raw = $reader->raw($user);
		if ($raw === null) {
			$this->errorCode = $reader->errorCode === 'gone' ? 'gone' : 'error';
			$this->error = $reader->error;
			return null;
		}
		$headers = InboxReader::headers($raw);
		$view = self::view($reader, $headers, 'ask');
		if ($view === null) {
			$this->errorCode = 'no_receipt';
			return null;
		}
		$sent = false;
		if ($send) {
			if ($view['abuse']) {
				// RFC 8098: no receipt to an address that is not the one of the return path without the user knowing;
				// the button is not offered then, the server refuses it too
				$this->errorCode = 'receipt_abuse';
				return null;
			}
			$langs->loadLangs(array('main', 'inbox@inbox'));
			$from = InboxSystemMail::fromOf($this->db, $user, $reader->account);
			$subject = (string) $reader->row->subject;
			$text = $langs->transnoentities('InboxReceiptText', $subject, dol_print_date($this->db->jdate($reader->row->date_mail), 'dayhour', 'tzuserrel', $langs),
				$reader->account->email, dol_print_date(dol_now(), 'dayhour', 'tzuserrel', $langs));
			$messageId = (string) $reader->row->message_id;
			$report = "Reporting-UA: inbox; Dolibarr\r\n"
				.'Final-Recipient: rfc822; '.$reader->account->email."\r\n"
				.($messageId !== '' ? 'Original-Message-ID: <'.$messageId.">\r\n" : '')
				."Disposition: manual-action/MDN-sent-manually; displayed\r\n";
			// Headers of the mail read, given back as the RFC asks (the text is never sent back)
			$split = preg_split('/\r?\n\r?\n/', (string) $raw, 2);
			$boundary = 'inbox-'.bin2hex(random_bytes(12));
			list($type, $body) = InboxSystemMail::multipart('multipart/report; report-type=disposition-notification', array(
				InboxSystemMail::part('text/plain; charset=utf-8', $text),
				InboxSystemMail::part('message/disposition-notification', $report, false),
				InboxSystemMail::part('text/rfc822-headers; charset=utf-8', (string) $split[0]),
			), $boundary);
			$mail = new InboxSystemMail();
			$extra = array('Auto-Submitted' => 'auto-replied');
			if ($messageId !== '') {
				$extra['In-Reply-To'] = '<'.$messageId.'>';
				$extra['References'] = '<'.$messageId.'>';
			}
			if (!$mail->send($this->db, $reader->account, $from, $view['to'], $langs->transnoentities('InboxReceiptSubject', $subject), $type, $body, $extra)) {
				$this->errorCode = $mail->errorCode;
				$this->error = $mail->error;
				return null;
			}
			$sent = true;
			InboxActionLog::add($this->db, $user, (int) $reader->account->id, 'receipt', array((object) array('message_id' => $messageId, 'subject' => $subject)),
				(string) $reader->row->folder_path, '', 0, $view['to']);
		}
		$this->mark($reader);

		return array('sent' => $sent);
	}

	/**
	 * Keyword on the server, then in the index (the question never comes back)
	 *
	 * @param InboxReader $reader Mail
	 * @return void
	 */
	protected function mark(InboxReader $reader)
	{
		$error = '';
		$connector = InboxConnectorFactory::forAccount($this->db, $reader->account, $error);
		if ($connector && $connector->can(InboxConnectorInterface::CAP_TAGS)) {
			if (!$connector->setTags((string) $reader->row->folder_path, array((string) $reader->row->remote_id), array(self::KEYWORD), array())) {
				dol_syslog(__METHOD__.' '.$connector->getError(), LOG_WARNING);
			}
			$connector->disconnect();
		}
		$keywords = trim(trim((string) $reader->row->keywords).' '.self::KEYWORD);
		$this->db->query("UPDATE ".$this->db->prefix()."inbox_mail SET keywords = '".$this->db->escape(dol_trunc($keywords, 1024, 'right', 'UTF-8', 1))."' WHERE rowid = ".((int) $reader->row->rowid));
	}
}
