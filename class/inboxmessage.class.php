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
 * \file    inbox/class/inboxmessage.class.php
 * \ingroup inbox
 * \brief   One mail once parsed, whatever its source (.eml, .msg, IMAP)
 */

/**
 * One mail once parsed. Nothing in it is trusted: texts are cleaned of control characters, file names are sanitized,
 * attachments are typed by their content (see InboxMimeParser::kind()), the HTML body is raw and must go through
 * InboxMimeParser::safeHtml() before being shown.
 */
class InboxMessage
{
	/** @var string 'eml' or 'msg' */
	public $source = 'eml';

	/** @var string Message-ID without the angle brackets */
	public $messageId = '';

	/** @var string */
	public $subject = '';

	/** @var string Lower case */
	public $fromEmail = '';

	/** @var string */
	public $fromName = '';

	/** @var string Lower case */
	public $replyToEmail = '';

	/** @var array<int,array{email:string,name:string}> */
	public $to = array();

	/** @var array<int,array{email:string,name:string}> */
	public $cc = array();

	/** @var int Timestamp, 0 if unknown */
	public $date = 0;

	/** @var string Message-ID this mail answers, without the angle brackets */
	public $inReplyTo = '';

	/** @var string Invitation in the body (text/calendar part, not an attachment), lot W8 1.22.0 */
	public $calendar = '';

	/** @var string Text body (the HTML one converted when the mail has no text part) */
	public $bodyText = '';

	/** @var string HTML body, raw: never shown without InboxMimeParser::safeHtml() */
	public $bodyHtml = '';

	/**
	 * Attachments, those of the mails attached to this one included (embedded = 1)
	 *
	 * @var array<int,array{name:string,content:string,kind:string,mime:string,cid:string,size:int,sha1:string,inline:int,embedded:int}>
	 */
	public $attachments = array();

	/** @var InboxMessage[] Mails attached to this one (forward as attachment) */
	public $embedded = array();

	/**
	 * Add an attachment, size and checksum computed here
	 *
	 * @param string $name     Sanitized file name
	 * @param string $content  Content
	 * @param string $mime     Declared MIME type (information only, see kind)
	 * @param string $cid      Content-ID (picture of the HTML body), '' if none
	 * @param int    $inline   1 if shown in the body (signature logos...)
	 * @param int    $embedded 1 if it comes from a mail attached to this one
	 * @return void
	 */
	public function addAttachment($name, $content, $mime = '', $cid = '', $inline = 0, $embedded = 0)
	{
		$this->attachments[] = array(
			'name' => $name,
			'content' => $content,
			'kind' => InboxMimeParser::kind($content),
			'mime' => strtolower(trim($mime)),
			'cid' => $cid,
			'size' => strlen($content),
			'sha1' => sha1($content),
			'inline' => $inline ? 1 : 0,
			'embedded' => $embedded ? 1 : 0,
		);
	}

	/**
	 * Attachments and embedded mails of a mail attached to this one, added as embedded
	 *
	 * @param InboxMessage $child Mail attached to this one
	 * @return void
	 */
	public function addEmbedded(InboxMessage $child)
	{
		$this->embedded[] = $child;
		foreach ($child->attachments as $attachment) {
			// The pictures of the attached mail do not take the Content-ID of the pictures of this one
			$attachment['cid'] = '';
			$attachment['embedded'] = 1;
			$this->attachments[] = $attachment;
		}
	}

	/**
	 * Attachments a reader works on: pictures of the body (logos, signatures) left aside
	 *
	 * @return array<int,array{name:string,content:string,kind:string,mime:string,cid:string,size:int,sha1:string,inline:int,embedded:int}>
	 */
	public function getFileAttachments()
	{
		return array_values(array_filter($this->attachments, function ($attachment) {
			return empty($attachment['inline']);
		}));
	}
}
