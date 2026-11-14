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
 * \file    inbox/class/inboxmimeparser.class.php
 * \ingroup inbox
 * \brief   Raw mail (RFC 822 source, from IMAP or a .eml file) => InboxMessage
 */

require_once __DIR__.'/inboxmessage.class.php';
require_once __DIR__.'/inboxsafe.class.php';

/**
 * Raw mail (RFC 822 source) => InboxMessage, with the Webklex classes shipped with Dolibarr (Header, Structure: no
 * IMAP connection needed).
 *
 * Nothing of the mail is trusted: the HTML body is shown only cleaned by an allow list (safeHtml) and inside a
 * sandboxed iframe without scripts, the file names are sanitized, the attachments are typed by their content, not by
 * their name or declared type.
 */
class InboxMimeParser
{
	/** Attachments larger than this are not kept */
	const MAX_ATTACHMENT_SIZE = 31457280;

	/** Text body kept */
	const MAX_BODY_LENGTH = 200000;

	/** Mails attached to mails (.eml, .msg) read down to this level */
	const MAX_EMBEDDED_DEPTH = 3;

	/** HTML body kept (bytes) */
	const MAX_HTML_LENGTH = 1048576;

	/** Elements removed with their content: see InboxSafe::HTML_DROP (lot S1, moved there) */
	const HTML_DROP = InboxSafe::HTML_DROP;

	/**
	 * @param string $raw   RFC 822 source
	 * @param int    $depth Level of a mail attached to a mail, 0 for the mail itself
	 * @return InboxMessage
	 */
	public static function parse($raw, $depth = 0)
	{
		self::loadWebklex();
		// Webklex reads CRLF line ends (IMAP); .eml files often have LF only
		$raw = str_replace("\n", "\r\n", str_replace("\r\n", "\n", (string) $raw));
		$split = explode("\r\n\r\n", $raw, 2);
		$rawHeader = $split[0] ?? '';
		$rawBody = $split[1] ?? '';

		$header = new \Webklex\PHPIMAP\Header($rawHeader);
		$message = new InboxMessage();
		$message->source = 'eml';
		$message->messageId = trim(self::headerString($header, 'message_id'), " <>\t");
		$message->subject = self::clean(self::decodeWords(self::headerString($header, 'subject')));
		$message->inReplyTo = trim(self::headerString($header, 'in_reply_to'), " <>\t");
		$from = self::addresses($header, 'from', $rawHeader);
		if (!empty($from)) {
			$message->fromEmail = $from[0]['email'];
			$message->fromName = $from[0]['name'];
		}
		$replyTo = self::addresses($header, 'reply_to', $rawHeader);
		$message->replyToEmail = !empty($replyTo) ? $replyTo[0]['email'] : '';
		$message->to = self::addresses($header, 'to', $rawHeader);
		$message->cc = self::addresses($header, 'cc', $rawHeader);
		$date = $header->get('date');
		$dateValue = is_object($date) && method_exists($date, 'first') ? $date->first() : $date;
		if ($dateValue instanceof \DateTimeInterface) {
			$message->date = $dateValue->getTimestamp();
		} elseif (is_object($dateValue) && method_exists($dateValue, 'getTimestamp')) {
			$message->date = $dateValue->getTimestamp();
		} else {
			$message->date = (int) strtotime((string) $dateValue);
		}

		$structure = new \Webklex\PHPIMAP\Structure($rawBody, $header);
		$text = '';
		$html = '';
		foreach ($structure->parts as $part) {
			$content = self::decode($part);
			$named = ($part->filename !== null && $part->filename !== '') || ($part->name !== null && $part->name !== '');
			$subtype = strtolower((string) $part->subtype);
			// A text or HTML part without a file name, « inline » or without disposition, is the body: the library
			// takes any part with a disposition for an attachment (a mail whose only HTML part says « inline » was
			// shown empty, its body as a file « piece-jointe »)
			$disposition = strtolower(trim(explode(' ', str_replace(';', ' ', (string) $part->disposition))[0]));
			$body = !$named && (int) $part->type === \Webklex\PHPIMAP\IMAP::MESSAGE_TYPE_TEXT && in_array($subtype, array('plain', 'html', ''), true) && in_array($disposition, array('', 'inline'), true);
			$isAttachment = !$body && ($part->isAttachment() || $named);
			if (!$named && stripos((string) $part->content_type, 'text/calendar') === 0) {
				// Invitation of Outlook, Google... (the library takes it for an attachment): read by InboxInvite, never
				// shown as the text of the mail nor as a file
				if ($message->calendar === '') {
					$message->calendar = self::toUtf8($content, (string) $part->charset);
				}
				continue;
			}
			if (!$isAttachment && (int) $part->type === \Webklex\PHPIMAP\IMAP::MESSAGE_TYPE_TEXT) {
				$content = self::toUtf8($content, (string) $part->charset);
				if ($subtype === 'html') {
					$html .= $content;
				} else {
					$text .= $content."\n";
				}
				continue;
			}
			if (!$isAttachment || $content === '' || strlen($content) > self::MAX_ATTACHMENT_SIZE) {
				continue;
			}
			$name = self::fileName(self::decodeFileName((string) ($part->filename ?: $part->name)));
			$mime = (string) $part->content_type;
			$cid = trim((string) $part->id, " <>\t");
			if (strtolower($mime) === 'message/rfc822' || (preg_match('/\.eml$/i', $name) && self::isMessage($content))) {
				// Mail attached to the mail (forward as attachment): kept as .eml like in Thunderbird, its own
				// attachments read too
				$embedded = $depth < self::MAX_EMBEDDED_DEPTH ? self::parse($content, $depth + 1) : null;
				if ($embedded !== null && $name === 'piece-jointe') {
					$name = self::fileName(str_replace(array('/', '\\'), '-', $embedded->subject !== '' ? $embedded->subject : 'mail').'.eml');
				}
				$message->addAttachment($name, $content, 'message/rfc822');
				if ($embedded !== null) {
					$message->addEmbedded($embedded);
				}
				continue;
			}
			if (preg_match('/\.msg$/i', $name) && self::kind($content) === 'ole') {
				// Outlook mail attached to the mail
				$message->addAttachment($name, $content, $mime);
				if ($depth < self::MAX_EMBEDDED_DEPTH) {
					require_once __DIR__.'/inboxmsgparser.class.php';
					$embedded = InboxMsgParser::parse($content, $depth + 1);
					if ($embedded !== null) {
						$message->addEmbedded($embedded);
					}
				}
				continue;
			}
			// Content-ID: pictures of the HTML body (cid:...), shown inline. A Content-ID alone is not enough
			// (some clients set one on every part): inline only if the body refers to it, decided below
			$message->addAttachment($name, $content, $mime, $cid, 0);
		}
		if (trim($text) === '' && $html !== '') {
			$text = self::htmlToText($html);
		}
		$message->bodyText = mb_substr(self::clean($text, true), 0, self::MAX_BODY_LENGTH, 'UTF-8');
		$message->bodyHtml = mb_strcut($html, 0, self::MAX_HTML_LENGTH, 'UTF-8');
		self::markInline($message);

		return $message;
	}

	/**
	 * Attachments the HTML body shows (cid:...) marked inline: logos and pictures of the signatures
	 *
	 * @param InboxMessage $message Mail
	 * @return void
	 */
	public static function markInline(InboxMessage $message)
	{
		if ($message->bodyHtml === '') {
			return;
		}
		foreach ($message->attachments as $i => $attachment) {
			if ($attachment['cid'] !== '' && empty($attachment['embedded']) && stripos($message->bodyHtml, 'cid:'.$attachment['cid']) !== false) {
				$message->attachments[$i]['inline'] = 1;
			}
		}
	}

	/**
	 * @return void
	 */
	protected static function loadWebklex()
	{
		if (!class_exists('\Webklex\PHPIMAP\ClientManager')) {
			require_once DOL_DOCUMENT_ROOT.'/includes/webklex/php-imap/vendor/autoload.php';
		}
		// The classes read their options from the client manager
		static $config = false;
		if (!$config) {
			new \Webklex\PHPIMAP\ClientManager();
			$config = true;
		}
	}

	/**
	 * @param \Webklex\PHPIMAP\Header $header Header
	 * @param string                  $name   Attribute
	 * @return string
	 */
	protected static function headerString($header, $name)
	{
		$value = $header->get($name);
		if (is_object($value) && method_exists($value, 'first')) {
			$value = $value->first();
		}

		return is_scalar($value) ? (string) $value : (is_object($value) && method_exists($value, '__toString') ? (string) $value : '');
	}

	/**
	 * Addresses of a header: the reader of the library, then a reader of our own when the library missed some (audit
	 * INBX-089: « "Service ; Compta" <x@y> », a semicolon inside the quotes, gave no address at all, the mail had no
	 * sender and the checks made on the address did not run)
	 *
	 * @param \Webklex\PHPIMAP\Header $header    Header
	 * @param string                  $name      Attribute (from, to, cc, reply_to)
	 * @param string                  $rawHeader Raw header of the mail
	 * @return array<int,array{email:string,name:string}>
	 */
	protected static function addresses($header, $name, $rawHeader = '')
	{
		$out = array();
		$value = $header->get($name);
		$list = is_object($value) && method_exists($value, 'toArray') ? $value->toArray() : (is_array($value) ? $value : array());
		foreach ($list as $address) {
			if (is_object($address) && !empty($address->mail)) {
				$out[] = array('email' => strtolower(trim((string) $address->mail)), 'name' => self::clean(self::decodeWords((string) ($address->personal ?? ''))));
			}
		}
		$field = self::rawField($rawHeader, str_replace('_', '-', $name));
		if ($field === '') {
			return $out;
		}
		$own = self::parseAddressList($field);
		if (count($own) > count($out)) {
			// The library missed some: the order of the header is kept, its names when it read them
			$byEmail = array_column($out, null, 'email');
			$out = array();
			foreach ($own as $a) {
				$out[] = isset($byEmail[$a['email']]) && $byEmail[$a['email']]['name'] !== '' ? $byEmail[$a['email']] : $a;
			}
		}

		return $out;
	}

	/**
	 * Value of the first field of a raw header, lines unfolded ('' when absent)
	 *
	 * @param string $rawHeader Raw header
	 * @param string $name      Field (From, Reply-To...)
	 * @return string
	 */
	public static function rawField($rawHeader, $name)
	{
		$unfolded = (string) preg_replace('/\r?\n[ \t]+/', ' ', (string) $rawHeader);
		if (!preg_match('/^'.preg_quote($name, '/').'[ \t]*:[ \t]*(.*)$/mi', $unfolded, $m)) {
			return '';
		}

		return trim($m[1]);
	}

	/**
	 * List of addresses of a header (RFC 5322): display names quoted with escapes, comments, groups, « , » and « ; »
	 * as separators only outside quotes, angle brackets and comments. Encoded words of the names decoded.
	 *
	 * @param string $value Value of the field (unfolded)
	 * @return array<int,array{email:string,name:string}>
	 */
	public static function parseAddressList($value)
	{
		$value = (string) $value;
		$items = array();
		$cur = array('name' => '', 'addr' => '', 'comment' => '', 'angle' => false);
		$quoted = false;
		$angle = false;
		$depth = 0;
		$flush = function () use (&$items, &$cur) {
			$items[] = $cur;
			$cur = array('name' => '', 'addr' => '', 'comment' => '', 'angle' => false);
		};
		$len = strlen($value);
		for ($i = 0; $i < $len; $i++) {
			$c = $value[$i];
			if ($quoted) {
				if ($c === '\\' && $i + 1 < $len) {
					$cur['name'] .= $value[++$i];
				} elseif ($c === '"') {
					$quoted = false;
				} else {
					$cur['name'] .= $c;
				}
				continue;
			}
			if ($depth > 0) {
				if ($c === '\\' && $i + 1 < $len) {
					$cur['comment'] .= $value[++$i];
				} elseif ($c === '(') {
					$depth++;
				} elseif ($c === ')') {
					$depth--;
				} else {
					$cur['comment'] .= $c;
				}
				continue;
			}
			if ($angle) {
				if ($c === '>') {
					$angle = false;
				} else {
					$cur['addr'] .= $c;
				}
				continue;
			}
			if ($c === '"') {
				$quoted = true;
			} elseif ($c === '(') {
				$depth = 1;
			} elseif ($c === '<') {
				$angle = true;
				$cur['angle'] = true;
				$cur['addr'] = '';
			} elseif ($c === ':') {
				// Name of a group (« Equipe: a@x, b@y; »): not an address
				$cur['name'] = '';
				$cur['addr'] = '';
			} elseif ($c === ',' || $c === ';') {
				$flush();
			} elseif (!$cur['angle']) {
				$cur['addr'] .= $c;
				$cur['name'] .= $c;
			}
		}
		$flush();
		$out = array();
		foreach ($items as $it) {
			$email = strtolower(trim($it['addr']));
			if (!$it['angle']) {
				// A bare address: what was read outside quotes and comments, the name in a comment if any
				$email = strtolower(trim((string) preg_replace('/\s+/', '', $email)));
				$name = trim($it['comment']);
			} else {
				$name = trim($it['name']);
			}
			if (!preg_match('/^[^\s@<>"(),;:\\\\]+@[^\s@<>"(),;:\\\\]+\.[^\s@<>"(),;:\\\\]+$/', $email)) {
				continue;
			}
			$out[] = array('email' => $email, 'name' => self::clean(self::decodeWords($name)));
		}

		return $out;
	}

	/**
	 * Encoded words of RFC 2047 left in a header value already decoded (« l'expé=?UTF-8?Q?dition_EXP25?= »): the reader
	 * of the library leaves them when they follow raw UTF-8 or are glued to a word, which some mailers send after a
	 * forward. Adjacent words are joined before decoding (a character may be cut between two of them); a word that does
	 * not give valid UTF-8 is kept as it was.
	 *
	 * @param string $text Value
	 * @return string
	 */
	public static function decodeWords($text)
	{
		$text = (string) $text;
		if (strpos($text, '=?') === false) {
			return $text;
		}
		$word = '=\?([A-Za-z0-9_.:\-]+)(?:\*[A-Za-z\-]+)?\?([QqBb])\?([^?\s]*)\?=';
		$out = preg_replace_callback('/(?:' . $word . ')(?:\s*' . $word . ')*/', function ($run) use ($word) {
			preg_match_all('/' . $word . '/', $run[0], $parts, PREG_SET_ORDER);
			$bytes = '';
			$charset = strtoupper($parts[0][1]);
			foreach ($parts as $p) {
				$bytes .= strtoupper($p[2]) === 'B' ? (string) base64_decode($p[3]) : quoted_printable_decode(str_replace('_', ' ', $p[3]));
			}
			if ($charset !== 'UTF-8' && $charset !== 'UTF8') {
				try {
					$converted = mb_convert_encoding($bytes, 'UTF-8', $charset);
				} catch (Throwable $e) {
					$converted = false;
				}
				if ($converted === false) {
					return $run[0];
				}
				$bytes = $converted;
			}

			return mb_check_encoding($bytes, 'UTF-8') ? $bytes : $run[0];
		}, $text);

		return is_string($out) ? $out : $text;
	}

	/**
	 * File name as declared, encodings of the headers removed: encoded words (« =?ISO-8859-1?Q?bon_de_livraison.pdf?= »,
	 * left as is by some mailers) and RFC 2231 values (« UTF-8''bon%20de%20livraison.pdf »)
	 *
	 * @param string $name Declared name
	 * @return string UTF-8
	 */
	public static function decodeFileName($name)
	{
		$name = trim($name, " \t\"");
		if (strpos($name, '=?') !== false) {
			$decoded = @iconv_mime_decode($name, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
			if ($decoded !== false && $decoded !== '') {
				$name = $decoded;
			}
		} elseif (preg_match("/^([A-Za-z0-9_\\-]+)'[A-Za-z\\-]*'(.+)$/", $name, $m)) {
			$name = self::toUtf8(rawurldecode($m[2]), $m[1]);
		}

		return $name;
	}

	/**
	 * Content of a part, transfer encoding removed
	 *
	 * @param \Webklex\PHPIMAP\Part $part Part
	 * @return string
	 */
	protected static function decode($part)
	{
		$content = (string) $part->content;
		switch ((int) $part->encoding) {
			case \Webklex\PHPIMAP\IMAP::MESSAGE_ENC_BASE64:
				return (string) base64_decode(preg_replace('/\s+/', '', $content));
			case \Webklex\PHPIMAP\IMAP::MESSAGE_ENC_QUOTED_PRINTABLE:
				return quoted_printable_decode($content);
			default:
				return $content;
		}
	}

	/**
	 * @param string $content Text
	 * @param string $charset Declared charset
	 * @return string UTF-8
	 */
	public static function toUtf8($content, $charset)
	{
		$charset = strtoupper(trim($charset));
		if ($charset !== '' && $charset !== 'UTF-8' && $charset !== 'US-ASCII') {
			$converted = @mb_convert_encoding($content, 'UTF-8', $charset);
			if ($converted !== false) {
				return $converted;
			}
		}

		return mb_check_encoding($content, 'UTF-8') ? $content : mb_convert_encoding($content, 'UTF-8', 'ISO-8859-1');
	}

	/**
	 * HTML body => text (never shown as HTML)
	 *
	 * @param string $html HTML
	 * @return string
	 */
	public static function htmlToText($html)
	{
		// Cut first (the text kept is shorter anyway): a forged HTML never makes the expressions below slow (audit INBX-027)
		$html = mb_strcut((string) $html, 0, self::MAX_HTML_LENGTH, 'UTF-8');
		$html = preg_replace('#<(script|style|head)\b[^>]*>.*?</\1>#is', ' ', $html);
		$html = preg_replace('#<(br|/p|/div|/tr|/li|/h\d)\b[^>]*>#i', "\n", $html);
		$html = preg_replace('#</t[dh]>#i', "\t", $html);

		return html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
	}

	/**
	 * HTML body of a mail => document to show in a sandboxed iframe: InboxSafe::display() (lot S1). Allow list on top of
	 * the sandbox: no script, frame, form or event attribute, links opened in a new tab, pictures of the mail (cid:)
	 * as data URIs, remote pictures counted (the CSP of the page showing it must block them)
	 *
	 * @param string                $html      HTML of the mail
	 * @param array<string, string> $inline    Content-ID => data URI of the pictures of the mail
	 * @param bool                  $hasRemote Set when the mail loads remote pictures
	 * @return string Whole HTML document
	 */
	public static function safeHtml($html, array $inline, &$hasRemote)
	{
		return InboxSafe::display($html, $inline, $hasRemote);
	}

	/**
	 * Conversation view: quotes of the previous mails folded, as the usual webmails do (« ... » to show them). The
	 * document is already safe (safeHtml or textDocument): the quotes are wrapped in <details>, that opens without
	 * any script. Known forms: Gmail (div.gmail_quote), Outlook (div#divRplyFwdMsg / #appendonsend and what follows),
	 * Thunderbird (div.moz-cite-prefix + blockquote[type=cite]), Apple Mail (blockquote[type=cite]), Yahoo
	 * (div.yahoo_quoted), text mails (lines starting with « > »).
	 *
	 * @param string $html Whole safe document
	 * @return string
	 */
	public static function foldQuotes($html)
	{
		$doc = new DOMDocument();
		$previous = libxml_use_internal_errors(true);
		$doc->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_HTML_NODEFDTD);
		libxml_clear_errors();
		libxml_use_internal_errors($previous);
		$xpath = new DOMXPath($doc);
		$body = $doc->getElementsByTagName('body')->item(0);
		if (!$body) {
			return $html;
		}
		$wrap = function (array $nodes) use ($doc) {
			if (!$nodes) {
				return;
			}
			$first = $nodes[0];
			$details = $doc->createElement('details');
			$details->setAttribute('class', 'inbox-quote');
			$summary = $doc->createElement('summary', '•••');
			$details->appendChild($summary);
			$first->parentNode->insertBefore($details, $first);
			foreach ($nodes as $n) {
				$details->appendChild($n);
			}
		};
		$done = false;
		// Outlook: the separator block and everything after it, at its level
		foreach (array("//*[@id='divRplyFwdMsg']", "//*[@id='appendonsend']", "//div[contains(concat(' ', normalize-space(@class), ' '), ' gmail_quote ')]",
			"//div[contains(concat(' ', normalize-space(@class), ' '), ' yahoo_quoted ')]", "//div[contains(concat(' ', normalize-space(@class), ' '), ' moz-cite-prefix ')]") as $q) {
			$node = $xpath->query($q)->item(0);
			if (!$node || !$node->parentNode) {
				continue;
			}
			// Outlook puts an <hr> or a styled div just before: fold from there
			$start = $node;
			$prev = $node->previousSibling;
			while ($prev && $prev->nodeType === XML_TEXT_NODE && trim($prev->textContent) === '') {
				$prev = $prev->previousSibling;
			}
			if ($prev && $prev->nodeType === XML_ELEMENT_NODE && strtolower($prev->nodeName) === 'hr') {
				$start = $prev;
			}
			$nodes = array();
			for ($n = $start; $n; $n = $n->nextSibling) {
				$nodes[] = $n;
			}
			$wrap($nodes);
			$done = true;
			break;
		}
		if (!$done) {
			foreach (iterator_to_array($xpath->query("//blockquote[@type='cite']")) as $bq) {
				// Outermost quotes only
				if ($xpath->query("ancestor::blockquote[@type='cite'] | ancestor::details", $bq)->length === 0) {
					$wrap(array($bq));
					$done = true;
				}
			}
		}
		if (!$done) {
			// Text mail: blocks of lines starting with « > » (and the « On ... wrote: » line just before)
			$pre = $xpath->query("//pre[contains(concat(' ', normalize-space(@class), ' '), ' inbox-text ')]")->item(0);
			if ($pre) {
				$lines = preg_split('/\n/', $pre->textContent);
				$out = array();
				$quote = array();
				$flush = function () use (&$out, &$quote) {
					if ($quote) {
						$out[] = array('q', implode("\n", $quote));
						$quote = array();
					}
				};
				foreach ($lines as $l) {
					if (preg_match('/^\s*(&gt;|>)/', $l)) {
						$quote[] = $l;
					} else {
						$flush();
						$out[] = array('t', $l);
					}
				}
				$flush();
				if (count(array_filter($out, function ($o) {
					return $o[0] === 'q';
				}))) {
					while ($pre->firstChild) {
						$pre->removeChild($pre->firstChild);
					}
					$buffer = '';
					foreach ($out as $o) {
						if ($o[0] === 't') {
							$buffer .= $o[1]."\n";
							continue;
						}
						// The line « Le ... a écrit : » just before the quote goes inside the fold
						$intro = '';
						if (preg_match('/\n([^\n]{5,200}(?:wrote|écrit|schrieb|scritto|escribió)\s*:\s*)\n$/iu', "\n".$buffer, $m)) {
							$intro = $m[1]."\n";
							$buffer = substr("\n".$buffer, 0, -strlen($m[1]) - 1);
							$buffer = substr($buffer, 1);
						}
						$pre->appendChild($doc->createTextNode($buffer));
						$buffer = '';
						$details = $doc->createElement('details');
						$details->setAttribute('class', 'inbox-quote');
						$details->appendChild($doc->createElement('summary', '•••'));
						$details->appendChild($doc->createTextNode($intro.$o[1]."\n"));
						$pre->appendChild($details);
					}
					$pre->appendChild($doc->createTextNode($buffer));
					$done = true;
				}
			}
		}
		if (!$done) {
			return $html;
		}
		$head = $doc->getElementsByTagName('head')->item(0);
		if ($head) {
			$style = $doc->createElement('style', 'details.inbox-quote>summary{display:inline-block;list-style:none;cursor:pointer;padding:0 8px;margin:6px 0;border:1px solid #ccd;'
				.'border-radius:8px;color:#556;font-size:12px;line-height:16px;background:#f3f5f7}details.inbox-quote>summary::-webkit-details-marker{display:none}'
				.'details.inbox-quote[open]>summary{margin-bottom:8px}');
			$head->appendChild($style);
		}

		return '<!doctype html>'.$doc->saveHTML($doc->documentElement);
	}

	/**
	 * Text body => same document as an HTML one
	 *
	 * @param string $text Text body
	 * @return string
	 */
	public static function textDocument($text)
	{
		return InboxSafe::textFrame($text);
	}

	/**
	 * Control characters removed (text shown escaped, but no surprise in logs or headers)
	 *
	 * @param string $text      Text
	 * @param bool   $multiline Keep line breaks and tabs
	 * @return string
	 */
	public static function clean($text, $multiline = false)
	{
		$text = (string) $text;
		if (!mb_check_encoding($text, 'UTF-8')) {
			$text = mb_convert_encoding($text, 'UTF-8', 'ISO-8859-1');
		}
		// Composed form (NFC): some mailers send « e » + combining accent, a search typed « été » would not find it
		if (class_exists('Normalizer') && !Normalizer::isNormalized($text, Normalizer::FORM_C)) {
			$text = (string) Normalizer::normalize($text, Normalizer::FORM_C);
		}
		$text = preg_replace($multiline ? '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u' : '/[\x00-\x1F\x7F]/u', $multiline ? '' : ' ', $text);

		return trim($multiline ? preg_replace("/\r\n?/", "\n", $text) : preg_replace('/\s+/u', ' ', $text));
	}

	/**
	 * File name usable on disk: no path, no control or special characters, extension kept
	 *
	 * @param string $name Declared name
	 * @return string
	 */
	public static function fileName($name)
	{
		$name = self::clean(basename(str_replace('\\', '/', $name)));
		$name = preg_replace('/[^\p{L}\p{N} ._()\-]/u', '_', $name);
		$name = trim(preg_replace('/\.{2,}/', '.', $name), ' .');

		if ($name === '') {
			return 'piece-jointe';
		}
		// Cut on the base, the extension kept (audit INBX-091: a long name lost its type)
		if (mb_strlen($name, 'UTF-8') > 150) {
			$ext = (string) pathinfo($name, PATHINFO_EXTENSION);
			$ext = $ext !== '' && mb_strlen($ext, 'UTF-8') <= 10 ? '.'.$ext : '';
			$name = rtrim(mb_substr($name, 0, 150 - mb_strlen($ext, 'UTF-8'), 'UTF-8'), ' .').$ext;
		}

		return $name;
	}

	/**
	 * Content that looks like a mail (RFC 822 headers then a blank line, with From, Date or Message-ID)
	 *
	 * @param string $content Content
	 * @return bool
	 */
	public static function isMessage($content)
	{
		$head = substr((string) $content, 0, 65536);
		$end = strpos(str_replace("\r\n", "\n", $head), "\n\n");
		if ($end === false) {
			return false;
		}
		$header = substr(str_replace("\r\n", "\n", $head), 0, $end);

		return (bool) preg_match('/^[A-Za-z0-9-]+:/', $header) && (bool) preg_match('/^(From|Date|Message-ID):/mi', $header)
			&& !preg_match('/^[^ \t:]+$/m', $header);
	}

	/**
	 * Type of an attachment from its content (magic bytes), not from its name
	 *
	 * @param string $content Content
	 * @return string pdf, png, jpeg, gif, tiff, zip (also docx, xlsx, odt...), ole (msg, doc, xls), other
	 */
	public static function kind($content)
	{
		$head = substr((string) $content, 0, 8);
		// Exact signatures first: a ZIP storing a PDF without compression has « %PDF- » in its first bytes too
		if (strpos($head, "PK\x03\x04") === 0) {
			return 'zip';
		}
		if ($head === "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1") {
			return 'ole';
		}
		if (strpos($head, '%PDF-') === 0 || strpos(substr((string) $content, 0, 1024), '%PDF-') !== false) {
			return 'pdf';
		}
		if (strpos($head, "\x89PNG") === 0) {
			return 'png';
		}
		if (strpos($head, "\xFF\xD8\xFF") === 0) {
			return 'jpeg';
		}
		if (strpos($head, 'GIF8') === 0) {
			return 'gif';
		}
		if (strpos($head, "II*\x00") === 0 || strpos($head, "MM\x00*") === 0) {
			return 'tiff';
		}

		return 'other';
	}

	/**
	 * Files of a ZIP attachment: entries with a path are flattened, at most 50 files and 100 MB unpacked (no zip
	 * bomb), nested archives ignored
	 *
	 * @param string   $content ZIP content
	 * @param string[] $kinds   Kinds kept (see kind()), all if empty
	 * @return array<int, array{name: string, content: string, kind: string}>
	 */
	public static function unzip($content, array $kinds = array())
	{
		$out = array();
		if (!class_exists('ZipArchive')) {
			return $out;
		}
		$tmp = tempnam(sys_get_temp_dir(), 'inboxzip');
		file_put_contents($tmp, $content);
		$zip = new ZipArchive();
		if ($zip->open($tmp) === true) {
			$total = 0;
			for ($i = 0; $i < min($zip->numFiles, 50); $i++) {
				$stat = $zip->statIndex($i);
				if (!$stat || substr($stat['name'], -1) === '/' || $stat['size'] > self::MAX_ATTACHMENT_SIZE || ($total += $stat['size']) > 104857600) {
					continue;
				}
				$data = (string) $zip->getFromIndex($i);
				$kind = self::kind($data);
				if (empty($kinds) || in_array($kind, $kinds, true)) {
					$out[] = array('name' => self::fileName($stat['name']), 'content' => $data, 'kind' => $kind);
				}
			}
			$zip->close();
		}
		@unlink($tmp);

		return $out;
	}

	/**
	 * Original sender of a forwarded mail, read in the body (« De : Jean Dupont <jean@example.com> », « From: ... »,
	 * Outlook and Thunderbird forms)
	 *
	 * @param string $body Text body
	 * @return array{email: string, name: string}|null
	 */
	public static function forwardedSender($body)
	{
		if (preg_match('/^\s*(?:De|From|Von|Da)\s*:\s*(?:"?([^"<\n]*?)"?\s*)?<?([A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,})>?/imu', (string) $body, $m)) {
			return array('email' => strtolower($m[2]), 'name' => self::clean($m[1] ?? ''));
		}

		return null;
	}
}
