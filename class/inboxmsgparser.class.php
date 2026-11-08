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
 * \file    inbox/class/inboxmsgparser.class.php
 * \ingroup inbox
 * \brief   Outlook .msg file => InboxMessage
 */

require_once __DIR__.'/inboxcfbreader.class.php';
require_once __DIR__.'/inboxmimeparser.class.php';

/**
 * Outlook .msg file ([MS-OXMSG]: MAPI properties inside a compound file) => InboxMessage.
 *
 * Read: subject, sender, recipients, dates, Message-ID, text and HTML bodies, attachments (files, mails attached as
 * .msg objects or .eml files). The internet headers kept by Outlook (PR_TRANSPORT_MESSAGE_HEADERS) are used when the
 * MAPI properties lack something. Not read: the body only stored as compressed RTF (Outlook writes the text body too in
 * nearly every case), OLE objects pasted in the body.
 */
class InboxMsgParser
{
	/** MAPI property types */
	const PT_INT16 = 0x0002;
	const PT_INT32 = 0x0003;
	const PT_BOOLEAN = 0x000B;
	const PT_OBJECT = 0x000D;
	const PT_INT64 = 0x0014;
	const PT_STRING8 = 0x001E;
	const PT_UNICODE = 0x001F;
	const PT_SYSTIME = 0x0040;
	const PT_BINARY = 0x0102;

	/** MAPI properties read */
	const PR_SUBJECT = 0x0037;
	const PR_CLIENT_SUBMIT_TIME = 0x0039;
	const PR_SENT_REPRESENTING_NAME = 0x0042;
	const PR_SENT_REPRESENTING_EMAIL = 0x0065;
	const PR_TRANSPORT_MESSAGE_HEADERS = 0x007D;
	const PR_SENDER_NAME = 0x0C1A;
	const PR_SENDER_EMAIL = 0x0C1F;
	const PR_RECIPIENT_TYPE = 0x0C15;
	const PR_MESSAGE_DELIVERY_TIME = 0x0E06;
	const PR_BODY = 0x1000;
	const PR_BODY_HTML = 0x1013;
	const PR_INTERNET_MESSAGE_ID = 0x1035;
	const PR_IN_REPLY_TO_ID = 0x1042;
	const PR_DISPLAY_NAME = 0x3001;
	const PR_EMAIL_ADDRESS = 0x3003;
	const PR_ATTACH_DATA = 0x3701;
	const PR_ATTACH_FILENAME = 0x3704;
	const PR_ATTACH_METHOD = 0x3705;
	const PR_ATTACH_LONG_FILENAME = 0x3707;
	const PR_ATTACH_MIME_TAG = 0x370E;
	const PR_ATTACH_CONTENT_ID = 0x3712;
	const PR_ATTACH_FLAGS = 0x3714;
	const PR_SMTP_ADDRESS = 0x39FE;
	const PR_INTERNET_CPID = 0x3FDE;
	const PR_MESSAGE_CODEPAGE = 0x3FFD;
	const PR_SENDER_SMTP_ADDRESS = 0x5D01;
	const PR_SENT_REPRESENTING_SMTP_ADDRESS = 0x5D02;
	const PR_LAST_MODIFIER_NAME = 0x3FFA;
	const PR_ATTACHMENT_HIDDEN = 0x7FFE;

	/** PR_ATTACH_METHOD: mail attached as an object */
	const ATTACH_EMBEDDED_MSG = 5;

	/** PR_ATTACH_FLAGS: attachment shown in the HTML body */
	const ATT_MHTML_REF = 4;

	/** @var string Last error */
	public static $error = '';

	/** @var InboxCfbReader */
	protected $cfb;

	/** @var string Encoding of the 8 bits strings of the mail */
	protected $codepage = 'Windows-1252';

	/** Attachments and recipients read at most in one file (audit INBX-011: a forged file is not read for ever) */
	const MAX_ATTACHMENTS = 500;
	const MAX_RECIPIENTS = 1000;

	/** @var int Bytes the attachments may take, all together: 3 times the size of the file (they cannot be larger in an honest one) */
	protected $budget = 0;

	/** @var int Attachments read so far */
	protected $attachmentsRead = 0;

	/**
	 * @param string $content Content of the .msg file
	 * @param int    $depth   Level of a mail attached to a mail, 0 for the mail itself
	 * @return InboxMessage|null Null if the file cannot be read (see self::$error)
	 */
	public static function parse($content, $depth = 0)
	{
		self::$error = '';
		$cfb = new InboxCfbReader();
		if (!$cfb->load($content)) {
			self::$error = $cfb->error;
			return null;
		}
		$parser = new self();
		$parser->cfb = $cfb;
		$parser->budget = 3 * strlen((string) $content);
		$children = $cfb->children($cfb->root());
		if (!isset($children['__properties_version1.0'])) {
			self::$error = 'Not an Outlook mail';
			return null;
		}

		return $parser->readMessage($cfb->root(), 32, $depth);
	}

	/**
	 * One message storage (the file itself or a mail attached to it)
	 *
	 * @param int $storage    Index of the storage
	 * @param int $headerSize Size of the header of its properties stream (32 for the file, 24 for an attached mail)
	 * @param int $depth      Level of the mail
	 * @return InboxMessage
	 */
	protected function readMessage($storage, $headerSize, $depth)
	{
		$children = $this->cfb->children($storage);
		$fixed = $this->fixedProperties($children, $headerSize);
		$cpid = (int) ($fixed[self::PR_INTERNET_CPID] ?? ($fixed[self::PR_MESSAGE_CODEPAGE] ?? 0));
		$this->codepage = self::encodingOf($cpid);

		// Internet headers kept by Outlook for a received mail: same reading as a .eml, the MAPI properties win
		$headers = $this->string($children, self::PR_TRANSPORT_MESSAGE_HEADERS);
		$message = trim($headers) !== '' ? InboxMimeParser::parse(rtrim($headers)."\r\n\r\n", $depth) : new InboxMessage();
		$message->source = 'msg';
		$message->attachments = array();
		$message->embedded = array();

		$subject = $this->string($children, self::PR_SUBJECT);
		if ($subject !== '') {
			$message->subject = InboxMimeParser::clean($subject);
		}
		$messageId = trim($this->string($children, self::PR_INTERNET_MESSAGE_ID), " <>\t\r\n");
		if ($messageId !== '') {
			$message->messageId = $messageId;
		}
		$inReplyTo = trim($this->string($children, self::PR_IN_REPLY_TO_ID), " <>\t\r\n");
		if ($inReplyTo !== '') {
			$message->inReplyTo = $inReplyTo;
		}
		// Sender: SMTP address first, the Exchange one (« /O=ORG/OU=... ») is useless outside of the company. A mail
		// saved from an Exchange mailbox may have no SMTP address of its sender at all: the last modifier, often the
		// sender as an address, is the last resort
		foreach (array(self::PR_SENDER_SMTP_ADDRESS, self::PR_SENT_REPRESENTING_SMTP_ADDRESS, self::PR_SENDER_EMAIL, self::PR_SENT_REPRESENTING_EMAIL, self::PR_LAST_MODIFIER_NAME) as $tag) {
			$email = strtolower(trim($this->string($children, $tag)));
			if (filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
				$message->fromEmail = $email;
				break;
			}
		}
		$fromName = $this->string($children, self::PR_SENDER_NAME);
		if ($fromName === '') {
			$fromName = $this->string($children, self::PR_SENT_REPRESENTING_NAME);
		}
		if ($fromName !== '') {
			$message->fromName = InboxMimeParser::clean($fromName);
		}
		$time = $fixed[self::PR_CLIENT_SUBMIT_TIME] ?? ($fixed[self::PR_MESSAGE_DELIVERY_TIME] ?? 0);
		if ($time > 0) {
			$message->date = $time;
		}

		$this->readRecipients($children, $message);

		$text = $this->string($children, self::PR_BODY);
		$html = $this->htmlBody($children);
		if (trim($text) === '' && $html !== '') {
			$text = InboxMimeParser::htmlToText($html);
		}
		if (trim($text) !== '') {
			$message->bodyText = mb_substr(InboxMimeParser::clean($text, true), 0, InboxMimeParser::MAX_BODY_LENGTH, 'UTF-8');
		}
		if ($html !== '') {
			$message->bodyHtml = mb_strcut($html, 0, InboxMimeParser::MAX_HTML_LENGTH, 'UTF-8');
		}

		$this->readAttachments($children, $message, $depth);
		InboxMimeParser::markInline($message);

		return $message;
	}

	/**
	 * @param array<string,int> $children Entries of the message storage
	 * @param InboxMessage      $message  Mail
	 * @return void
	 */
	protected function readRecipients(array $children, InboxMessage $message)
	{
		$to = array();
		$cc = array();
		ksort($children);
		$read = 0;
		foreach ($children as $name => $index) {
			if (strpos($name, '__recip_version1.0_#') !== 0 || !$this->cfb->isStorage($index)) {
				continue;
			}
			if (++$read > self::MAX_RECIPIENTS) {
				break;
			}
			$recipient = $this->cfb->children($index);
			$fixed = $this->fixedProperties($recipient, 8);
			$email = strtolower(trim($this->string($recipient, self::PR_SMTP_ADDRESS)));
			if (strpos($email, '@') === false) {
				$email = strtolower(trim($this->string($recipient, self::PR_EMAIL_ADDRESS)));
			}
			if (strpos($email, '@') === false) {
				continue;
			}
			$entry = array('email' => $email, 'name' => InboxMimeParser::clean($this->string($recipient, self::PR_DISPLAY_NAME)));
			$type = (int) ($fixed[self::PR_RECIPIENT_TYPE] ?? 1);
			if ($type === 1) {
				$to[] = $entry;
			} elseif ($type === 2) {
				$cc[] = $entry;
			}
		}
		if (!empty($to)) {
			$message->to = $to;
		}
		if (!empty($cc)) {
			$message->cc = $cc;
		}
	}

	/**
	 * @param array<string,int> $children Entries of the message storage
	 * @param InboxMessage      $message  Mail
	 * @param int               $depth    Level of the mail
	 * @return void
	 */
	protected function readAttachments(array $children, InboxMessage $message, $depth)
	{
		ksort($children);
		foreach ($children as $name => $index) {
			if (strpos($name, '__attach_version1.0_#') !== 0 || !$this->cfb->isStorage($index)) {
				continue;
			}
			if (++$this->attachmentsRead > self::MAX_ATTACHMENTS || $this->budget <= 0) {
				break;
			}
			$attachment = $this->cfb->children($index);
			$fixed = $this->fixedProperties($attachment, 8);
			$fileName = $this->string($attachment, self::PR_ATTACH_LONG_FILENAME);
			if ($fileName === '') {
				$fileName = $this->string($attachment, self::PR_ATTACH_FILENAME);
			}
			if ($fileName === '') {
				$fileName = $this->string($attachment, self::PR_DISPLAY_NAME);
			}
			$fileName = InboxMimeParser::fileName($fileName);

			$objectName = self::streamName(self::PR_ATTACH_DATA, self::PT_OBJECT);
			if ((int) ($fixed[self::PR_ATTACH_METHOD] ?? 0) === self::ATTACH_EMBEDDED_MSG && isset($attachment[$objectName]) && $this->cfb->isStorage($attachment[$objectName])) {
				// Mail attached as an object: no file of its own, its body is in InboxMessage::$embedded and its
				// attachments are added to this mail
				if ($depth < InboxMimeParser::MAX_EMBEDDED_DEPTH) {
					$codepage = $this->codepage;
					$embedded = $this->readMessage($attachment[$objectName], 24, $depth + 1);
					$this->codepage = $codepage;
					$message->addEmbedded($embedded);
				}
				continue;
			}

			$content = $this->binary($attachment, self::PR_ATTACH_DATA);
			if ($content === '' || strlen($content) > InboxMimeParser::MAX_ATTACHMENT_SIZE) {
				continue;
			}
			// Streams shared by several entries copy the same bytes again: a budget for the whole file
			$this->budget -= strlen($content);
			if ($this->budget < 0) {
				break;
			}
			$mime = $this->string($attachment, self::PR_ATTACH_MIME_TAG);
			$cid = trim($this->string($attachment, self::PR_ATTACH_CONTENT_ID), " <>\t");
			$inline = (((int) ($fixed[self::PR_ATTACH_FLAGS] ?? 0)) & self::ATT_MHTML_REF) || !empty($fixed[self::PR_ATTACHMENT_HIDDEN]) ? 1 : 0;
			$message->addAttachment($fileName, $content, $mime, $cid, $inline);

			if ($depth < InboxMimeParser::MAX_EMBEDDED_DEPTH) {
				if (preg_match('/\.eml$/i', $fileName) && InboxMimeParser::isMessage($content)) {
					$message->addEmbedded(InboxMimeParser::parse($content, $depth + 1));
				} elseif (preg_match('/\.msg$/i', $fileName) && InboxMimeParser::kind($content) === 'ole') {
					$embedded = self::parse($content, $depth + 1);
					if ($embedded !== null) {
						$message->addEmbedded($embedded);
					}
				}
			}
		}
	}

	/**
	 * HTML body: binary (bytes in the encoding of the mail, or the one of its <meta>) or Unicode string
	 *
	 * @param array<string,int> $children Entries of the message storage
	 * @return string UTF-8
	 */
	protected function htmlBody(array $children)
	{
		$html = $this->binary($children, self::PR_BODY_HTML);
		if ($html === '') {
			return $this->string($children, self::PR_BODY_HTML);
		}
		$charset = $this->codepage;
		if (preg_match('/<meta[^>]+charset\s*=\s*["\']?([A-Za-z0-9_\-]+)/i', substr($html, 0, 4096), $m)) {
			$charset = $m[1];
		}

		return rtrim(InboxMimeParser::toUtf8($html, $charset), "\0");
	}

	/**
	 * Fixed size properties of a storage (integers, booleans, dates), read in its properties stream
	 *
	 * @param array<string,int> $children   Entries of the storage
	 * @param int               $headerSize Size of the header of the stream
	 * @return array<int,int> Property id => value (dates as timestamps)
	 */
	protected function fixedProperties(array $children, $headerSize)
	{
		$out = array();
		if (!isset($children['__properties_version1.0'])) {
			return $out;
		}
		$stream = $this->cfb->stream($children['__properties_version1.0']);
		for ($offset = $headerSize; $offset + 16 <= strlen($stream); $offset += 16) {
			$tag = InboxCfbReader::u32($stream, $offset);
			$type = $tag & 0xFFFF;
			$id = ($tag >> 16) & 0xFFFF;
			switch ($type) {
				case self::PT_INT16:
					$out[$id] = InboxCfbReader::u16($stream, $offset + 8);
					break;
				case self::PT_INT32:
				case self::PT_BOOLEAN:
					$out[$id] = InboxCfbReader::u32($stream, $offset + 8);
					break;
				case self::PT_SYSTIME:
					// FILETIME: 100 ns since 1601-01-01
					$low = InboxCfbReader::u32($stream, $offset + 8);
					$high = InboxCfbReader::u32($stream, $offset + 12);
					$filetime = $high * 4294967296 + $low;
					$out[$id] = $filetime > 0 ? (int) floor($filetime / 10000000) - 11644473600 : 0;
					break;
			}
		}

		return $out;
	}

	/**
	 * String property, Unicode or 8 bits
	 *
	 * @param array<string,int> $children Entries of the storage
	 * @param int               $id       Property id
	 * @return string UTF-8, '' if missing
	 */
	protected function string(array $children, $id)
	{
		$name = self::streamName($id, self::PT_UNICODE);
		if (isset($children[$name])) {
			$value = (string) mb_convert_encoding($this->cfb->stream($children[$name]), 'UTF-8', 'UTF-16LE');
			return rtrim($value, "\0");
		}
		$name = self::streamName($id, self::PT_STRING8);
		if (isset($children[$name])) {
			return rtrim(InboxMimeParser::toUtf8($this->cfb->stream($children[$name]), $this->codepage), "\0");
		}

		return '';
	}

	/**
	 * @param array<string,int> $children Entries of the storage
	 * @param int               $id       Property id
	 * @return string Bytes, '' if missing
	 */
	protected function binary(array $children, $id)
	{
		$name = self::streamName($id, self::PT_BINARY);

		return isset($children[$name]) ? $this->cfb->stream($children[$name]) : '';
	}

	/**
	 * @param int $id   Property id
	 * @param int $type Property type
	 * @return string Name of the stream holding a variable size property
	 */
	protected static function streamName($id, $type)
	{
		return '__substg1.0_'.sprintf('%04X%04X', $id, $type);
	}

	/**
	 * Windows code page => encoding name known by mbstring
	 *
	 * @param int $cpid Code page (0 if unknown)
	 * @return string
	 */
	protected static function encodingOf($cpid)
	{
		if ($cpid === 65001) {
			return 'UTF-8';
		}
		if ($cpid === 20127) {
			return 'ASCII';
		}
		if ($cpid >= 28591 && $cpid <= 28605) {
			return 'ISO-8859-'.($cpid - 28590);
		}
		if ($cpid > 0) {
			$name = 'CP'.$cpid;
			if (in_array(strtoupper($name), array_map('strtoupper', mb_list_encodings()), true)) {
				return $name;
			}
		}

		return 'Windows-1252';
	}
}
