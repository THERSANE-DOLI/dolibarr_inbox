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
 * \file    inbox/class/inboxmailbuilder.class.php
 * \ingroup inbox
 * \brief   Source of a mail written in inbox (lot W6 1.16.0): headers, HTML cleaned, text part, attachments
 */

require_once __DIR__.'/inboxmimeparser.class.php';

/**
 * Builds the RFC 822 source of a mail with the MIME library shipped with Dolibarr (Swift Mailer), and nothing of the
 * sending engine of Dolibarr: inbox sets its own Message-ID, the whole chain of References, a real text part, and no
 * header that names the tool or the real author. The only class that knows the library: another one can replace it.
 */
class InboxMailBuilder
{
	/** @var int Recipients at most (To + Cc + Bcc) */
	const MAX_RECIPIENTS = 100;

	/** @var int Links of the References chain at most (the first one and the last ones kept, as Thunderbird does) */
	const MAX_REFERENCES = 20;

	/** @var string[] Tags kept in a mail written in inbox */
	const TAGS = array('p', 'br', 'div', 'span', 'b', 'strong', 'i', 'em', 'u', 's', 'strike', 'del', 'sub', 'sup', 'small',
		'a', 'ul', 'ol', 'li', 'blockquote', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'hr', 'pre', 'code', 'img',
		'table', 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th', 'caption', 'colgroup', 'col', 'center', 'font');

	/** @var string[] Attributes kept */
	const ATTRIBUTES = array('href', 'src', 'alt', 'title', 'style', 'width', 'height', 'align', 'valign', 'colspan', 'rowspan',
		'cellpadding', 'cellspacing', 'border', 'bgcolor', 'color', 'face', 'size', 'dir', 'lang', 'type', 'start', 'cite');

	/** @var bool Keep the variables of a template in the body (a draft) */
	public $keepVars = false;

	/** @var array{email:string,name:string} */
	public $from = array('email' => '', 'name' => '');
	/** @var array<int,array{email:string,name:string}> */
	public $to = array();
	/** @var array<int,array{email:string,name:string}> */
	public $cc = array();
	/** @var array<int,array{email:string,name:string}> */
	public $bcc = array();
	/** @var string */
	public $replyTo = '';
	/** @var string */
	public $subject = '';
	/** @var string HTML of the body (cleaned by build()) */
	public $html = '';
	/** @var string Message-ID answered, without the angle brackets */
	public $inReplyTo = '';
	/** @var string[] Chain of the conversation, without the angle brackets */
	public $references = array();
	/** @var bool Ask a read receipt (Disposition-Notification-To) */
	public $receipt = false;
	/** @var array<int,array{path?:string,content?:string,name:string,mime:string}> */
	public $attachments = array();
	/** @var array<string,string> Private headers (drafts only: X-Inbox-...) */
	public $extraHeaders = array();
	/** @var string Message-ID set (without the angle brackets), made when empty */
	public $messageId = '';
	/** @var int Date of the mail */
	public $date = 0;

	/** @var string */
	public $error = '';

	/**
	 * @return void
	 */
	protected static function load()
	{
		if (!class_exists('Swift_Message')) {
			require_once DOL_DOCUMENT_ROOT.'/includes/swiftmailer/lexer/lib/Doctrine/Common/Lexer/AbstractLexer.php';
			require_once DOL_DOCUMENT_ROOT.'/includes/swiftmailer/autoload.php';
			require_once DOL_DOCUMENT_ROOT.'/includes/swiftmailer/lib/swift_required.php';
		}
	}

	/**
	 * A new Message-ID on the domain of the sender (says nothing of the tool)
	 *
	 * @param string $email Address of the sender
	 * @return string Without the angle brackets
	 */
	public static function newMessageId($email)
	{
		$domain = strtolower((string) substr(strrchr((string) $email, '@') ?: '@localhost', 1));
		$domain = preg_match('/^[a-z0-9.\-]{1,253}$/', $domain) ? $domain : 'localhost';

		return bin2hex(random_bytes(12)).'@'.$domain;
	}

	/**
	 * Addresses typed or pasted ("Name <a@b.c>, d@e.f; ...") => list, each one checked
	 *
	 * @param string|array<int,mixed> $value Text, or list of strings / {email,name}
	 * @param string[]                $bad   Addresses refused
	 * @return array<int,array{email:string,name:string}>
	 */
	public static function parseAddresses($value, &$bad = array())
	{
		$items = array();
		if (is_array($value)) {
			foreach ($value as $v) {
				if (is_array($v)) {
					$items[] = array('email' => (string) ($v['email'] ?? ''), 'name' => (string) ($v['name'] ?? ''));
				} else {
					$items = array_merge($items, self::parseAddresses((string) $v, $bad));
				}
			}
		} else {
			// Split on commas and semicolons outside quotes
			$parts = preg_split('/[,;\n](?=(?:[^"]*"[^"]*")*[^"]*$)/', (string) $value);
			foreach ($parts as $part) {
				$part = trim($part);
				if ($part === '') {
					continue;
				}
				if (preg_match('/^(.*)<([^<>]+)>\s*$/s', $part, $m)) {
					$items[] = array('email' => trim($m[2]), 'name' => trim(trim($m[1]), '"\' '));
				} else {
					$items[] = array('email' => $part, 'name' => '');
				}
			}
		}
		$out = array();
		$seen = array();
		foreach ($items as $it) {
			$email = strtolower(trim($it['email']));
			$name = trim(preg_replace('/[\x00-\x1F\x7F<>"]+/', ' ', (string) $it['name']));
			if ($email === '') {
				continue;
			}
			if (!filter_var($email, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $email)) {
				$bad[] = $email;
				continue;
			}
			if (isset($seen[$email])) {
				continue;
			}
			$seen[$email] = 1;
			$out[] = array('email' => $email, 'name' => dol_trunc($name, 120, 'right', 'UTF-8', 1));
		}

		return $out;
	}

	/**
	 * HTML written in inbox (editor, quote, signature) cleaned for sending: allow list of tags and attributes, links
	 * http / mailto only, pictures data: / cid: / https only, no script, no event, no url() in the styles but https
	 *
	 * @param string $html     HTML
	 * @param bool   $keepVars Keep the variables of a template (span data-inbox-var): drafts, templates; sent mails
	 *                         hold their text only
	 * @param bool   $noRemote Nothing that the browser would load: no picture, no style able to point at one (a
	 *                         proposal of the AI, audit INBX-032)
	 * @return string Fragment (inside of a body)
	 */
	public static function cleanHtml($html, $keepVars = false, $noRemote = false)
	{
		$html = (string) $html;
		if (trim($html) === '') {
			return '';
		}
		$doc = new DOMDocument();
		$previous = libxml_use_internal_errors(true);
		$doc->loadHTML('<?xml encoding="UTF-8"><html><body>'.$html.'</body></html>', LIBXML_NONET | LIBXML_HTML_NODEFDTD);
		libxml_clear_errors();
		libxml_use_internal_errors($previous);
		$body = $doc->getElementsByTagName('body')->item(0);
		if (!$body) {
			return '';
		}
		self::cleanNode($body, (bool) $keepVars, (bool) $noRemote);
		$out = '';
		foreach ($body->childNodes as $child) {
			$out .= $doc->saveHTML($child);
		}

		return $out;
	}

	/**
	 * @param DOMNode $node     Node whose children are cleaned
	 * @param bool    $keepVars Keep the variables of a template
	 * @param bool    $noRemote Nothing that the browser would load
	 * @return void
	 */
	protected static function cleanNode(DOMNode $node, $keepVars = false, $noRemote = false)
	{
		foreach (iterator_to_array($node->childNodes) as $child) {
			if ($child instanceof DOMComment || $child instanceof DOMProcessingInstruction) {
				$node->removeChild($child);
				continue;
			}
			if (!$child instanceof DOMElement) {
				continue;
			}
			$tag = strtolower($child->nodeName);
			if (in_array($tag, InboxMimeParser::HTML_DROP, true) || $tag === 'style' || ($noRemote && $tag === 'img')) {
				$node->removeChild($child);
				continue;
			}
			if (!in_array($tag, self::TAGS, true)) {
				// Unknown tag: its content is kept, the tag goes
				self::cleanNode($child, $keepVars, $noRemote);
				while ($child->firstChild) {
					$node->insertBefore($child->firstChild, $child);
				}
				$node->removeChild($child);
				continue;
			}
			// Width of a column chosen in the editor (data-colwidth): written as width="" for the mail software
			if (($tag === 'td' || $tag === 'th') && preg_match('/^\d{1,4}$/', $child->getAttribute('data-colwidth'))) {
				$child->setAttribute('width', $child->getAttribute('data-colwidth'));
			}
			foreach (iterator_to_array($child->attributes) as $attr) {
				$name = strtolower($attr->name);
				$value = trim($attr->value);
				$keep = in_array($name, self::ATTRIBUTES, true);
				if ($name === 'data-inbox-var') {
					$keep = $keepVars && $tag === 'span' && preg_match('/^[A-Z0-9_]{1,64}$/', $value);
				} elseif ($name === 'class') {
					// Only the marks of inbox (a draft reopened finds its quote and signature again)
					$keep = in_array($value, array('inbox-quote', 'inbox-signature'), true);
				} elseif ($keep && $name === 'href') {
					// A web address in full, a mail, a phone number (no other scheme hidden after it, audit INBX-038)
					$keep = (bool) preg_match('#^(https?://|mailto:|tel:[^:]{1,64}$|\#)#i', $value);
				} elseif ($keep && $name === 'src') {
					$keep = (bool) preg_match('#^(https:|cid:|data:image/(png|jpeg|gif|webp);base64,)#i', $value);
				} elseif ($keep && $name === 'style') {
					$keep = !preg_match('/expression\s*\(|javascript:|behavior\s*:|-moz-binding|url\s*\(\s*[\'"]?\s*(?!https:)/i', $value)
						&& !($noRemote && preg_match('/url|image|@|\\\\/i', $value));
				} elseif ($keep) {
					$keep = !preg_match('/^\s*(javascript|vbscript|data):/i', $value);
				}
				if (!$keep) {
					$child->removeAttribute($attr->name);
				}
			}
			if ($tag === 'a' && $child->hasAttribute('href')) {
				$child->setAttribute('rel', 'noopener noreferrer');
			}
			// Tables of the editor have no border: written in the cells, as the mail software read no stylesheet
			if ($tag === 'table' && stripos($child->getAttribute('style'), 'border-collapse') === false) {
				$child->setAttribute('style', trim($child->getAttribute('style').';border-collapse:collapse', '; '));
			} elseif (($tag === 'td' || $tag === 'th') && stripos($child->getAttribute('style'), 'border') === false) {
				$child->setAttribute('style', trim($child->getAttribute('style').';border:1px solid #d0d5dd;padding:4px 8px;vertical-align:top', '; '));
			}
			self::cleanNode($child, $keepVars, $noRemote);
		}
	}

	/**
	 * Text part of an HTML body: paragraphs, lists, quotes with « > », links written after their text
	 *
	 * @param string $html Clean HTML
	 * @return string
	 */
	public static function toText($html)
	{
		$html = preg_replace_callback('#<a\b[^>]*href="(https?:[^"]+)"[^>]*>(.*?)</a>#is', function ($m) {
			$text = trim(strip_tags($m[2]));
			$url = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
			// Written as entities: the tags are removed after, the angle brackets come back with the entities
			return $text === '' || $text === $url ? htmlspecialchars($url) : $m[2].' &lt;'.htmlspecialchars($url).'&gt;';
		}, (string) $html);
		// The signature delimiter of the text mails (RFC 3676 4.3: « -- » and a space alone on its line), written by a
		// mark that the cleaning of the trailing spaces leaves
		$html = preg_replace('#<div\b[^>]*class="inbox-signature"[^>]*>(\s*--\s*(<br\s*/?>)?)?#i', "\nINBOXSIGDELIM\n", $html);
		$html = preg_replace('#<li\b[^>]*>#i', "\n- ", $html);
		$html = preg_replace('#<(p|div|h\d|tr|blockquote|ul|ol|pre|table)\b[^>]*>#i', "\n", $html);
		// Quotes: each line of a blockquote starts with « > »
		while (preg_match('#<blockquote\b[^>]*>((?:(?!<blockquote\b).)*?)</blockquote>#is', $html)) {
			$html = preg_replace_callback('#<blockquote\b[^>]*>((?:(?!<blockquote\b).)*?)</blockquote>#is', function ($m) {
				$text = trim(InboxMimeParser::htmlToText($m[1]));
				return "\n".preg_replace('/^/m', '> ', $text)."\n";
			}, $html);
		}
		$text = InboxMimeParser::htmlToText($html);
		$text = preg_replace("/[ \t]+\n/", "\n", $text);
		$text = preg_replace("/\n{3,}/", "\n\n", $text);
		$text = preg_replace('/^[ \t]*INBOXSIGDELIM[ \t]*\n+/m', "-- \n", $text);

		return trim($text)."\n";
	}

	/**
	 * Build the mail
	 *
	 * @return array{raw:string,wire:string,message_id:string,recipients:string[]}|null raw = with the Bcc (copy in Sent,
	 *         draft), wire = what goes to the SMTP server (no Bcc header), recipients = envelope; null on error
	 */
	public function build()
	{
		$this->error = '';
		self::load();
		$all = array_merge($this->to, $this->cc, $this->bcc);
		if (count($all) > self::MAX_RECIPIENTS) {
			$this->error = 'too_many_recipients';
			return null;
		}
		if (!filter_var($this->from['email'], FILTER_VALIDATE_EMAIL)) {
			$this->error = 'bad_from';
			return null;
		}
		$messageId = $this->messageId !== '' ? $this->messageId : self::newMessageId($this->from['email']);
		$this->messageId = $messageId;
		$html = self::cleanHtml($this->html, $this->keepVars);
		try {
			$message = new Swift_Message();
			$message->setCharset('utf-8');
			$headers = $message->getHeaders();
			$id = $headers->get('Message-ID');
			if ($id instanceof Swift_Mime_Headers_IdentificationHeader) {
				$id->setId($messageId);
			}
			$message->setDate(new DateTimeImmutable('@'.($this->date ?: dol_now())));
			$message->setSubject(InboxMimeParser::clean($this->subject));
			$message->setFrom(array($this->from['email'] => $this->from['name'] !== '' ? $this->from['name'] : null));
			foreach (array('setTo' => $this->to, 'setCc' => $this->cc, 'setBcc' => $this->bcc) as $method => $list) {
				if ($list) {
					$map = array();
					foreach ($list as $a) {
						$map[$a['email']] = $a['name'] !== '' ? $a['name'] : null;
					}
					$message->$method($map);
				}
			}
			if ($this->replyTo !== '' && filter_var($this->replyTo, FILTER_VALIDATE_EMAIL)) {
				$message->setReplyTo($this->replyTo);
			}
			if ($this->inReplyTo !== '') {
				$headers->addIdHeader('In-Reply-To', trim($this->inReplyTo, " \t<>"));
			}
			$refs = array_values(array_unique(array_filter(array_map(function ($r) {
				return trim((string) $r, " \t<>");
			}, $this->references))));
			if (count($refs) > self::MAX_REFERENCES) {
				$refs = array_merge(array($refs[0]), array_slice($refs, -(self::MAX_REFERENCES - 1)));
			}
			if ($refs) {
				$headers->addIdHeader('References', $refs);
			}
			if ($this->receipt) {
				$headers->addMailboxHeader('Disposition-Notification-To', array($this->from['email']));
			}
			foreach ($this->extraHeaders as $name => $value) {
				if (preg_match('/^X-Inbox-[A-Za-z\-]{1,40}$/', $name)) {
					$headers->addTextHeader($name, preg_replace('/[\r\n]+/', ' ', (string) $value));
				}
			}
			// Pictures of the body given as data: become parts of the mail (cid:), as every client shows them
			$html = preg_replace_callback('#src="data:image/(png|jpeg|gif|webp);base64,([A-Za-z0-9+/=\s]+)"#i', function ($m) use ($message) {
				$content = base64_decode(preg_replace('/\s+/', '', $m[2]), true);
				if ($content === false || $content === '') {
					return 'src=""';
				}
				$cid = $message->embed(new Swift_Image($content, 'image-'.substr(sha1($content), 0, 8).'.'.($m[1] === 'jpeg' ? 'jpg' : $m[1]), 'image/'.$m[1]));
				return 'src="'.$cid.'"';
			}, $html);
			$document = '<!DOCTYPE html><html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head>'
				.'<body style="font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.45">'.$html.'</body></html>';
			$message->setBody($document, 'text/html', 'utf-8');
			$message->addPart(self::toText($html), 'text/plain', 'utf-8');
			foreach ($this->attachments as $a) {
				if (isset($a['content'])) {
					$part = new Swift_Attachment((string) $a['content'], $a['name'], $a['mime'] ?: 'application/octet-stream');
				} else {
					$part = Swift_Attachment::fromPath((string) $a['path'], $a['mime'] ?: 'application/octet-stream');
					$part->setFilename($a['name']);
				}
				$message->attach($part);
			}
			$raw = $message->toString();
			$wire = $raw;
			if ($this->bcc) {
				$wireMessage = clone $message;
				$wireMessage->setBcc(array());
				$wireMessage->getHeaders()->remove('Bcc');
				$wire = $wireMessage->toString();
			}
		} catch (Exception $e) {
			$this->error = 'build: '.$e->getMessage();
			return null;
		}
		$recipients = array_values(array_unique(array_map(function ($a) {
			return $a['email'];
		}, $all)));

		return array('raw' => $raw, 'wire' => $wire, 'message_id' => $messageId, 'recipients' => $recipients);
	}
}
