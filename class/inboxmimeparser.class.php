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

/**
 * Raw mail (RFC 822 source) => InboxMessage, with the Webklex classes shipped with Dolibarr (Header, Structure: no
 * IMAP connection needed). Generic MIME parser.
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

	/** Elements removed with their content: scripts, frames, forms, documents inside the document */
	const HTML_DROP = array('script', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'form', 'input', 'button', 'select',
		'textarea', 'link', 'meta', 'base', 'svg', 'math', 'template', 'noscript', 'audio', 'video', 'source', 'track', 'portal', 'title', 'head');

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
		$message->subject = self::clean(self::headerString($header, 'subject'));
		$message->inReplyTo = trim(self::headerString($header, 'in_reply_to'), " <>\t");
		$from = self::addresses($header, 'from');
		if (!empty($from)) {
			$message->fromEmail = $from[0]['email'];
			$message->fromName = $from[0]['name'];
		}
		$replyTo = self::addresses($header, 'reply_to');
		$message->replyToEmail = !empty($replyTo) ? $replyTo[0]['email'] : '';
		$message->to = self::addresses($header, 'to');
		$message->cc = self::addresses($header, 'cc');
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
			$isAttachment = $part->isAttachment() || ($part->filename !== null && $part->filename !== '') || ($part->name !== null && $part->name !== '');
			$subtype = strtolower((string) $part->subtype);
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
	 * @param \Webklex\PHPIMAP\Header $header Header
	 * @param string                  $name   Attribute (from, to, cc, reply_to)
	 * @return array<int,array{email:string,name:string}>
	 */
	protected static function addresses($header, $name)
	{
		$out = array();
		$value = $header->get($name);
		$list = is_object($value) && method_exists($value, 'toArray') ? $value->toArray() : (is_array($value) ? $value : array());
		foreach ($list as $address) {
			if (is_object($address) && !empty($address->mail)) {
				$out[] = array('email' => strtolower(trim((string) $address->mail)), 'name' => self::clean((string) ($address->personal ?? '')));
			}
		}

		return $out;
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
		$html = preg_replace('#<(script|style|head)\b[^>]*>.*?</\1>#is', ' ', (string) $html);
		$html = preg_replace('#<(br|/p|/div|/tr|/li|/h\d)\b[^>]*>#i', "\n", $html);
		$html = preg_replace('#</t[dh]>#i', "\t", $html);

		return html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
	}

	/**
	 * HTML body of a mail => document to show in a sandboxed iframe (no script, no same origin). Allow list on top of
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
		$hasRemote = false;
		$doc = new DOMDocument();
		$previous = libxml_use_internal_errors(true);
		// The body is UTF-8 already (toUtf8): the charset of its <meta> (Outlook « iso-8859-1 ») would win over the
		// encoding given here and double encode the accents
		$html = preg_replace('#<meta\b[^>]*charset[^>]*>#i', '', (string) $html);
		// Conditional comments of Outlook shown to the other readers (« <![if !vml]> »): libxml would print them as text
		$html = preg_replace('#<!\[(?:if\b[^\]]*|endif)\]>#i', '', $html);
		// No network, no entity expansion (LIBXML_NOENT not set)
		$doc->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_HTML_NODEFDTD);
		libxml_clear_errors();
		libxml_use_internal_errors($previous);

		$styles = array();
		foreach (iterator_to_array($doc->getElementsByTagName('style')) as $style) {
			// CSS of the mail kept (layout of the signatures, tables); no url() allowed by the CSP except data:
			$styles[] = $style->textContent;
			$style->parentNode->removeChild($style);
		}
		foreach (self::HTML_DROP as $tag) {
			foreach (iterator_to_array($doc->getElementsByTagName($tag)) as $node) {
				$node->parentNode->removeChild($node);
			}
		}
		$xpath = new DOMXPath($doc);
		foreach (iterator_to_array($xpath->query('//*')) as $node) {
			/** @var DOMElement $node */
			foreach (iterator_to_array($node->attributes) as $attr) {
				$name = strtolower($attr->name);
				$value = trim($attr->value);
				if (strpos($name, 'on') === 0 || in_array($name, array('srcset', 'formaction', 'action', 'ping', 'xmlns', 'target'), true)
					|| preg_match('/^\s*(javascript|vbscript|data):/i', $value) && !in_array($name, array('src', 'alt', 'title'), true)) {
					$node->removeAttribute($attr->name);
				} elseif ($name === 'style' && preg_match('/url\s*\(\s*[\'"]?\s*https?:/i', $value)) {
					$hasRemote = true;
				} elseif ($name === 'background' && preg_match('/^https?:/i', $value)) {
					$hasRemote = true;
				}
			}
			$tag = strtolower($node->nodeName);
			if ($tag === 'a' && $node->hasAttribute('href')) {
				if (preg_match('#^(https?:|mailto:|tel:|\#)#i', trim($node->getAttribute('href')))) {
					$node->setAttribute('target', '_blank');
					$node->setAttribute('rel', 'noopener noreferrer');
				} else {
					$node->removeAttribute('href');
				}
			} elseif ($tag === 'img') {
				$src = trim($node->getAttribute('src'));
				if (stripos($src, 'cid:') === 0 && isset($inline[trim(rawurldecode(substr($src, 4)), '<>')])) {
					$node->setAttribute('src', $inline[trim(rawurldecode(substr($src, 4)), '<>')]);
				} elseif (preg_match('/^https?:/i', $src)) {
					$hasRemote = true;
				} elseif (!preg_match('#^data:image/(png|jpeg|gif|webp);base64,#i', $src)) {
					$node->removeAttribute('src');
				}
			}
		}
		$body = $doc->getElementsByTagName('body')->item(0);
		$inner = '';
		foreach ($body ? $body->childNodes : array() as $child) {
			$inner .= $doc->saveHTML($child);
		}

		return self::document($inner, implode("\n", $styles));
	}

	/**
	 * Text body => same document as an HTML one
	 *
	 * @param string $text Text body
	 * @return string
	 */
	public static function textDocument($text)
	{
		return self::document('<pre class="inbox-text">'.htmlspecialchars((string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8').'</pre>', '');
	}

	/**
	 * @param string $body   Body (already safe)
	 * @param string $styles CSS of the mail
	 * @return string
	 */
	protected static function document($body, $styles)
	{
		// The CSS of a mail cannot close the style element (no « </style » kept)
		$styles = str_ireplace('</style', '', $styles);

		return '<!doctype html><html><head><meta charset="utf-8"><style>'
			.'html{color-scheme:light}body{margin:16px;font:14px/1.45 Arial,Helvetica,sans-serif;color:#222;background:#fff;overflow-wrap:break-word}'
			.'img{max-width:100%;height:auto}pre.inbox-text{white-space:pre-wrap;font:inherit;margin:0}table{max-width:100%}'
			.'</style><style>'.$styles.'</style></head><body>'.$body.'</body></html>';
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

		return $name !== '' ? mb_substr($name, 0, 150, 'UTF-8') : 'piece-jointe';
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
		if (strpos(substr((string) $content, 0, 1024), '%PDF-') !== false) {
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
		if (strpos($head, "PK\x03\x04") === 0) {
			return 'zip';
		}
		if ($head === "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1") {
			return 'ole';
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
