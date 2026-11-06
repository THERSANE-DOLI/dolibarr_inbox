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
 * \file    inbox/class/inboxheaderparser.class.php
 * \ingroup inbox
 * \brief   Headers of a mail (as read by the synchronisation) into the common format of the index
 */

require_once __DIR__.'/inboxmimeparser.class.php';

/**
 * The content of the headers is hostile: every value is decoded, cleaned (no control character, no HTML kept as
 * such: the index stores text, escaped when shown) and bounded. A malformed header gives what is readable, never an
 * error that stops the synchronisation.
 */
class InboxHeaderParser
{
	/** Bounds of the stored values */
	const MAX_SUBJECT = 255;
	const MAX_NAME = 255;
	const MAX_EMAIL = 255;
	const MAX_ADDRESSES = 200;
	const MAX_REFS = 2000;
	const MAX_TEXT = 2000;
	const MAX_HEADER = 65536;

	/**
	 * Common format of one mail (see analyse-lot-w2 §2.2)
	 *
	 * @param string              $header        Raw header (RFC 822), as fetched
	 * @param array<mixed>|null   $bodystructure BODYSTRUCTURE tokens, null if not fetched
	 * @return array<string,mixed>
	 */
	public static function parse($header, $bodystructure = null)
	{
		$header = substr((string) $header, 0, self::MAX_HEADER);
		$out = array(
			'message_id' => '', 'in_reply_to' => '', 'refs' => '', 'date_mail' => 0, 'from_email' => '', 'from_name' => '',
			'to' => array(), 'cc' => array(), 'reply_to' => array(), 'subject' => '', 'has_attachments' => 0,
		);
		try {
			$m = InboxMimeParser::parse(rtrim($header)."\r\n\r\n");
			$out['message_id'] = self::bound($m->messageId, 255);
			$out['in_reply_to'] = self::bound($m->inReplyTo, 255);
			$out['date_mail'] = (int) $m->date;
			$out['from_email'] = self::email($m->fromEmail);
			$out['from_name'] = self::bound($m->fromName, self::MAX_NAME);
			$out['subject'] = self::bound($m->subject, self::MAX_SUBJECT);
			$out['to'] = self::addresses($m->to);
			$out['cc'] = self::addresses($m->cc);
			if ($m->replyToEmail !== '') {
				$out['reply_to'] = self::addresses(array(array('email' => $m->replyToEmail, 'name' => '')));
			}
		} catch (Throwable $e) {
			// Unreadable header: the mail is indexed with what follows
			dol_syslog(__METHOD__.' '.$e->getMessage(), LOG_DEBUG);
		}
		if (preg_match('/^References:((?:[^\r\n]*)(?:\r?\n[ \t][^\r\n]*)*)/mi', $header, $r)) {
			preg_match_all('/<([^<>\s]{1,250})>/', $r[1], $ids);
			$out['refs'] = self::bound(implode(' ', $ids[1]), self::MAX_REFS);
		}
		if ($out['message_id'] === '' && preg_match('/^Message-ID:\s*<?([^<>\s]{1,250})>?/mi', $header, $mid)) {
			$out['message_id'] = $mid[1];
		}
		if (is_array($bodystructure)) {
			$out['has_attachments'] = self::hasAttachments($bodystructure) ? 1 : 0;
		}

		return $out;
	}

	/**
	 * @param array<int,array{email:string,name:string}> $list Addresses
	 * @return array<int,array{email:string,name:string}>
	 */
	protected static function addresses($list)
	{
		$out = array();
		foreach (array_slice((array) $list, 0, self::MAX_ADDRESSES) as $a) {
			$email = self::email(isset($a['email']) ? $a['email'] : '');
			if ($email !== '') {
				$out[] = array('email' => $email, 'name' => self::bound(isset($a['name']) ? $a['name'] : '', self::MAX_NAME));
			}
		}

		return $out;
	}

	/**
	 * @param string $email Address
	 * @return string        Lower case, bounded, '' when not an address
	 */
	protected static function email($email)
	{
		$email = strtolower(trim((string) $email));

		return (strlen($email) <= self::MAX_EMAIL && strpos($email, '@') !== false && !preg_match('/[\s<>"\x00-\x1f]/', $email)) ? $email : '';
	}

	/**
	 * @param string $text Text
	 * @param int    $max  Characters
	 * @return string       One line, no control character, bounded
	 */
	public static function bound($text, $max)
	{
		$text = InboxMimeParser::clean((string) $text);

		return dol_trunc($text, $max, 'right', 'UTF-8', 1);
	}

	/**
	 * Text of a list of addresses for the display (« Marie <marie@x.fr>, Paul <paul@y.fr> »), bounded
	 *
	 * @param array<int,array{email:string,name:string}> $list Addresses
	 * @return string
	 */
	public static function addressText($list)
	{
		$parts = array();
		foreach ((array) $list as $a) {
			$parts[] = $a['name'] !== '' ? $a['name'].' <'.$a['email'].'>' : $a['email'];
		}

		return dol_trunc(implode(', ', $parts), self::MAX_TEXT, 'right', 'UTF-8', 1);
	}

	/**
	 * Real attachments in a BODYSTRUCTURE: a part with a disposition « attachment », or with a file name and not
	 * inline (pictures of the body are not attachments)
	 *
	 * @param array<mixed> $part Tokens of a part (multipart: sub parts first)
	 * @param int          $depth Depth (bounded)
	 * @return bool
	 */
	public static function hasAttachments(array $part, $depth = 0)
	{
		if ($depth > 20 || !$part) {
			return false;
		}
		if (is_array($part[0])) {
			// Multipart: sub parts, then the subtype and the extensions
			foreach ($part as $sub) {
				if (is_array($sub) && isset($sub[0]) && (is_array($sub[0]) || is_string($sub[0])) && count($sub) >= 7 && self::hasAttachments($sub, $depth + 1)) {
					return true;
				}
			}
			return false;
		}
		$type = strtolower((string) $part[0]);
		$params = isset($part[2]) && is_array($part[2]) ? self::pairs($part[2]) : array();
		// Disposition: after the fields of the type (text: lines; message/rfc822: envelope, structure, lines)
		$disposition = null;
		foreach (array_slice($part, 7) as $token) {
			if (is_array($token) && isset($token[0]) && is_string($token[0]) && in_array(strtolower($token[0]), array('attachment', 'inline'), true)) {
				$disposition = $token;
				break;
			}
		}
		$dispType = $disposition ? strtolower((string) $disposition[0]) : '';
		$dispParams = ($disposition && isset($disposition[1]) && is_array($disposition[1])) ? self::pairs($disposition[1]) : array();
		$hasName = !empty($params['name']) || !empty($dispParams['filename']) || !empty($params['name*']) || !empty($dispParams['filename*']);
		if ($dispType === 'attachment') {
			return true;
		}
		if ($type === 'message' && strtolower((string) $part[1]) === 'rfc822') {
			return true;
		}

		return $hasName && $dispType !== 'inline' && $type !== 'text';
	}

	/**
	 * @param array<mixed> $list ("name" "value" ...)
	 * @return array<string,string>
	 */
	protected static function pairs(array $list)
	{
		$out = array();
		for ($i = 0; $i + 1 < count($list); $i += 2) {
			if (is_string($list[$i]) && is_string($list[$i + 1])) {
				$out[strtolower($list[$i])] = $list[$i + 1];
			}
		}

		return $out;
	}
}
