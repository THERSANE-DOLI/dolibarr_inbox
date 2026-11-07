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
 * \file    class/connector/inboximapresponse.class.php
 * \ingroup inbox
 * \brief   Reader of IMAP responses (RFC 3501 syntax): lists, quoted strings, literals, atoms, NIL
 *
 * The decoder of Webklex splits a line on spaces: a list closed and the next one opened without a space between
 * (« NIL)("text" ») stays one token, so the sub parts of a BODYSTRUCTURE are merged and an attachment is not seen.
 * Servers write the parts of a multipart this way, the mails of Outlook / Exchange (parts ending with NIL) are all hit.
 * The request is still sent by Webklex, this class reads and decodes the response.
 */

/**
 * Reader of IMAP responses
 */
class InboxImapResponse
{
	/**
	 * @var int Depth of the lists (bounded)
	 */
	const MAX_DEPTH = 64;

	/**
	 * Read the response of a command sent by Webklex, until its tagged line
	 *
	 * @param object $conn Webklex ImapProtocol (nextLine())
	 * @param string $tag  Tag of the command
	 * @return array<int,array<int,mixed>>|null Untagged responses decoded (« * » removed), null if NO / BAD
	 */
	public static function read($conn, $tag)
	{
		$out = array();
		while (true) {
			$raw = self::readOne($conn);
			if (strpos($raw, '* ') === 0) {
				$out[] = self::decode(substr($raw, 2));
				continue;
			}
			if (strpos($raw, '+ ') === 0) {
				continue;
			}
			if (strpos($raw, $tag.' ') === 0) {
				return strtoupper(substr($raw, strlen($tag) + 1, 2)) === 'OK' ? $out : null;
			}
		}
	}

	/**
	 * One response line, with its literals ({n} then n bytes) included
	 *
	 * @param object $conn Webklex ImapProtocol
	 * @return string
	 */
	protected static function readOne($conn)
	{
		$buf = '';
		$line = $conn->nextLine();
		while (true) {
			$buf .= $line;
			if (!preg_match('/\{(\d+)\}\r?\n$/', $line, $m)) {
				return $buf;
			}
			$need = (int) $m[1];
			$literal = '';
			while (strlen($literal) < $need) {
				$literal .= $conn->nextLine();
			}
			$buf .= substr($literal, 0, $need);
			// The line read past the literal holds the rest of the response
			$line = (string) substr($literal, $need);
			if ($line === '') {
				$line = $conn->nextLine();
			}
		}
	}

	/**
	 * Decode a response (without the tag): lists as arrays, strings and atoms as strings, NIL as null
	 *
	 * @param string $s Response
	 * @return array<int,mixed>
	 */
	public static function decode($s)
	{
		$pos = 0;

		return self::items((string) $s, $pos, 0);
	}

	/**
	 * Items up to the end of the list (or of the response)
	 *
	 * @param string $s     Response
	 * @param int    $pos   Position, moved past the list
	 * @param int    $depth Depth of the list
	 * @return array<int,mixed>
	 */
	protected static function items($s, &$pos, $depth)
	{
		$out = array();
		$len = strlen($s);
		while ($pos < $len) {
			$ch = $s[$pos];
			if ($ch === ' ' || $ch === "\r" || $ch === "\n") {
				$pos++;
			} elseif ($ch === '(') {
				$pos++;
				if ($depth >= self::MAX_DEPTH) {
					$pos = $len;
					break;
				}
				$out[] = self::items($s, $pos, $depth + 1);
			} elseif ($ch === ')') {
				$pos++;
				if ($depth > 0) {
					return $out;
				}
			} elseif ($ch === '"') {
				$val = '';
				$pos++;
				while ($pos < $len && $s[$pos] !== '"') {
					if ($s[$pos] === '\\' && $pos + 1 < $len) {
						$pos++;
					}
					$val .= $s[$pos];
					$pos++;
				}
				$pos++;
				$out[] = $val;
			} elseif ($ch === '{' && preg_match('/\G\{(\d+)\}\r?\n/', $s, $m, 0, $pos)) {
				$pos += strlen($m[0]);
				$out[] = (string) substr($s, $pos, (int) $m[1]);
				$pos += (int) $m[1];
			} else {
				// Atom: up to a space or a parenthesis, a [section] taken whole (BODY[HEADER.FIELDS (FROM TO)])
				$start = $pos;
				$brackets = 0;
				while ($pos < $len) {
					$c = $s[$pos];
					if ($c === '[') {
						$brackets++;
					} elseif ($c === ']') {
						$brackets = max(0, $brackets - 1);
					} elseif (!$brackets && ($c === ' ' || $c === '(' || $c === ')' || $c === "\r" || $c === "\n")) {
						break;
					}
					$pos++;
				}
				$atom = substr($s, $start, $pos - $start);
				$out[] = strtoupper($atom) === 'NIL' ? null : $atom;
			}
		}

		return $out;
	}
}
