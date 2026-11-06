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
 * \file    inbox/class/inboxsearchengine.class.php
 * \ingroup inbox
 * \brief   Search of the mails: common contract of the engines, reading of the query typed by the user
 */

/**
 * An engine finds mails in the mailboxes of the user. The SQL engine on the index is always there (lot W3); a full
 * text engine may come later (lot W7) with the same contract. The rights are applied by the caller: the engine only
 * receives mailboxes the user may read.
 */
interface InboxSearchEngine
{
	/**
	 * One page of mails
	 *
	 * @param int[]               $accountIds Mailboxes the user may read (never empty)
	 * @param int                 $folderId   Folder (0 = every folder of the mailboxes)
	 * @param array<string,mixed> $query      Parsed query (see InboxSearchQuery::parse()), filters, sort
	 * @param string              $cursor     Opaque cursor of the previous page, '' for the first
	 * @param int                 $limit      Rows of the page
	 * @return array{rows: array<int,array<string,mixed>>, next: string, total: int}
	 */
	public function search(array $accountIds, $folderId, array $query, $cursor, $limit);
}

/**
 * Query typed in the search field: words (all of them) and operators shown as pills by the application.
 * Operators in English always work (from:, to:, subject:, has:attachment, before:, after:, tag:, is:unread), plus
 * their translation of the language of the user (« de: », « à: »... in French, keys InboxSearchOp*).
 */
class InboxSearchQuery
{
	/** Operators => key of their translation */
	const OPERATORS = array(
		'from' => 'InboxSearchOpFrom', 'to' => 'InboxSearchOpTo', 'subject' => 'InboxSearchOpSubject',
		'before' => 'InboxSearchOpBefore', 'after' => 'InboxSearchOpAfter', 'tag' => 'InboxSearchOpTag',
	);

	/**
	 * @param string    $text  Text typed
	 * @param Translate $langs Language of the user (translated operators)
	 * @return array{words: string[], from: string[], to: string[], subject: string[], before: int, after: int, tag: string[], attachment: bool, unread: bool}
	 */
	public static function parse($text, $langs = null)
	{
		$out = array('words' => array(), 'from' => array(), 'to' => array(), 'subject' => array(), 'before' => 0, 'after' => 0,
			'tag' => array(), 'attachment' => false, 'unread' => false);
		$names = array();
		foreach (self::OPERATORS as $op => $key) {
			$names[$op] = $op;
			if (is_object($langs)) {
				$tr = mb_strtolower(trim((string) $langs->transnoentities($key)), 'UTF-8');
				if ($tr !== '' && $tr !== mb_strtolower($key, 'UTF-8')) {
					$names[$tr] = $op;
				}
			}
		}
		$flags = array('has:attachment' => 'attachment', 'pj:oui' => 'attachment', 'is:unread' => 'unread');
		if (is_object($langs)) {
			foreach (array('InboxSearchOpAttachment' => 'attachment', 'InboxSearchOpUnread' => 'unread') as $key => $flag) {
				$tr = mb_strtolower(trim((string) $langs->transnoentities($key)), 'UTF-8');
				if ($tr !== '' && $tr !== mb_strtolower($key, 'UTF-8')) {
					$flags[$tr] = $flag;
				}
			}
		}
		$text = mb_substr(InboxMimeParserLite::clean((string) $text), 0, 500, 'UTF-8');
		// Tokens: "words in quotes", op:"value in quotes", op:value, word
		preg_match_all('/([^\s:"]+:)?"([^"]*)"|(\S+)/u', $text, $m, PREG_SET_ORDER);
		foreach ($m as $t) {
			if (isset($t[3]) && $t[3] !== '') {
				$token = $t[3];
				$low = mb_strtolower($token, 'UTF-8');
				if (isset($flags[$low])) {
					$out[$flags[$low]] = true;
					continue;
				}
				$pos = mb_strpos($token, ':', 0, 'UTF-8');
				$op = $pos !== false ? mb_strtolower(mb_substr($token, 0, $pos, 'UTF-8'), 'UTF-8') : '';
				$value = $pos !== false ? mb_substr($token, $pos + 1, null, 'UTF-8') : $token;
			} else {
				$op = $t[1] !== '' ? mb_strtolower(rtrim($t[1], ':'), 'UTF-8') : '';
				$value = $t[2];
			}
			$value = trim($value);
			if ($value === '') {
				continue;
			}
			if ($op !== '' && isset($names[$op])) {
				$op = $names[$op];
				if ($op === 'before' || $op === 'after') {
					$ts = self::date($value);
					if ($ts) {
						$out[$op] = $ts;
					}
				} else {
					$out[$op][] = mb_substr($value, 0, 100, 'UTF-8');
				}
				continue;
			}
			// Not an operator (« 10:30 », « http://... »): a word
			$out['words'][] = mb_substr($op !== '' ? $op.':'.$value : $value, 0, 100, 'UTF-8');
		}
		$out['words'] = array_slice($out['words'], 0, 10);

		return $out;
	}

	/**
	 * @param string $value 2026-01-31, 31/01/2026, 2026-01, 2026
	 * @return int Timestamp (GMT midnight), 0 if not a date
	 */
	public static function date($value)
	{
		if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $value, $m)) {
			return (int) gmmktime(0, 0, 0, (int) $m[2], (int) $m[3], (int) $m[1]);
		}
		if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $value, $m)) {
			return (int) gmmktime(0, 0, 0, (int) $m[2], (int) $m[1], (int) $m[3]);
		}
		if (preg_match('/^(\d{4})-(\d{1,2})$/', $value, $m)) {
			return (int) gmmktime(0, 0, 0, (int) $m[2], 1, (int) $m[1]);
		}
		if (preg_match('/^(19|20)\d{2}$/', $value)) {
			return (int) gmmktime(0, 0, 0, 1, 1, (int) $value);
		}

		return 0;
	}

	/**
	 * @param array<string,mixed> $query Parsed query
	 * @return bool Nothing asked
	 */
	public static function isEmpty(array $query)
	{
		foreach (array('words', 'from', 'to', 'subject', 'tag') as $k) {
			if (!empty($query[$k])) {
				return false;
			}
		}

		return empty($query['before']) && empty($query['after']) && empty($query['attachment']) && empty($query['unread']);
	}
}

/**
 * Cleaning of a typed text without loading the MIME library
 */
class InboxMimeParserLite
{
	/**
	 * @param string $text Text
	 * @return string One line, no control character
	 */
	public static function clean($text)
	{
		$text = (string) $text;
		if (!mb_check_encoding($text, 'UTF-8')) {
			$text = mb_convert_encoding($text, 'UTF-8', 'ISO-8859-1');
		}
		if (class_exists('Normalizer') && !Normalizer::isNormalized($text, Normalizer::FORM_C)) {
			$text = (string) Normalizer::normalize($text, Normalizer::FORM_C);
		}

		return trim(preg_replace('/\s+/u', ' ', preg_replace('/[\x00-\x1F\x7F]/u', ' ', $text)));
	}
}
