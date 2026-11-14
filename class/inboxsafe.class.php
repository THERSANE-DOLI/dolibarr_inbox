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
 * \file    inbox/class/inboxsafe.class.php
 * \ingroup inbox
 * \brief   The one door of what comes from a mail (analysis analyse-assainissement-xss.md, lot S1)
 */

/**
 * Everything a mail brings goes through here before it is shown or written anywhere: the type of the field it goes
 * to decides, not where it comes from.
 *
 * - display(): the HTML body for the reading frame only (sandboxed iframe, CSP without script). The styles of the
 *   mail are kept, the frame and its CSP are the real barrier.
 * - html(): the HTML body when it leaves the frame (note of an object, documents, draft, quote): display() first,
 *   then HTMLPurifier with a strict allow list. Only the body of a mail is HTML.
 * - line() / lines(): any other value of a mail (name, subject, X-Data-Form / X-Quotation-Data headers, .ics, file
 *   names) for a plain field of Dolibarr: no tag, no control character, no double quote, as the « alphanohtml »
 *   filter of the forms of the core (the pages of the core print some fields raw, audit INBX-048).
 * - textToHtml(): a plain text written in an HTML field of the core (note): escaped, never stripped, so a mail that
 *   talks about « <b> » stays readable.
 */
class InboxSafe
{
	/** Elements removed with their content: scripts, frames, forms, documents inside the document */
	const HTML_DROP = array('script', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'form', 'input', 'button', 'select',
		'textarea', 'link', 'meta', 'base', 'svg', 'math', 'template', 'noscript', 'audio', 'video', 'source', 'track', 'portal', 'title', 'head',
		// Read by the browsers as raw text, by libxml as elements: what they hold would not be the same HTML (audit INBX-001)
		'xmp', 'noembed', 'noframes', 'plaintext', 'listing', 'style');

	/** Elements kept by html(): a formatted mail, nothing that runs, loads a document or asks for an input */
	const PURE_ELEMENTS = 'p,div,span,br,hr,b,strong,i,em,u,s,strike,sub,sup,small,big,font,blockquote,pre,code,ul,ol,li,dl,dt,dd,h1,h2,h3,h4,h5,h6,a,img,table,thead,tbody,tfoot,tr,th,td,caption,colgroup,col,center';

	/** Attributes kept by html(): no id (would collide with the page), no class (its CSS stays in the frame, a class of Dolibarr would dress the mail as the page) */
	const PURE_ATTRIBUTES = '*.style,*.dir,*.lang,*.title,*.align,a.href,img.src,img.alt,img.width,img.height,img.border,font.color,font.face,font.size,table.width,table.border,table.cellpadding,table.cellspacing,table.bgcolor,td.width,td.height,td.valign,td.bgcolor,td.colspan,td.rowspan,th.width,th.valign,th.bgcolor,th.colspan,th.rowspan,tr.valign,tr.bgcolor,col.width,col.span,colgroup.span,ol.start,ol.type,ul.type,li.value,blockquote.cite';

	/** CSS kept by html(): colors, fonts, spaces, borders, sizes; no url(), no position */
	const PURE_CSS = 'color,background-color,font,font-family,font-size,font-weight,font-style,font-variant,text-decoration,text-align,text-indent,text-transform,vertical-align,line-height,letter-spacing,white-space,margin,margin-top,margin-right,margin-bottom,margin-left,padding,padding-top,padding-right,padding-bottom,padding-left,border,border-top,border-right,border-bottom,border-left,border-color,border-style,border-width,border-collapse,border-spacing,width,height,max-width,min-width,list-style-type';

	/** @var HTMLPurifier|null Built once per request */
	protected static $purifier = null;

	/**
	 * HTML body for the reading frame: no script, frame, form or event attribute, links opened in a new tab, pictures
	 * of the mail (cid:) as data URIs, remote pictures counted (the CSP of the page showing it must block them)
	 *
	 * @param string                $html      HTML of the mail
	 * @param array<string, string> $inline    Content-ID => data URI of the pictures of the mail
	 * @param bool                  $hasRemote Set when the mail loads remote pictures
	 * @return string Whole HTML document
	 */
	public static function display($html, array $inline, &$hasRemote)
	{
		$prepared = self::prepare($html, $inline, $hasRemote);

		return self::frame($prepared['body'], $prepared['styles']);
	}

	/**
	 * Text body => same document as an HTML one
	 *
	 * @param string $text Text body
	 * @return string
	 */
	public static function textFrame($text)
	{
		return self::frame('<pre class="inbox-text">'.htmlspecialchars((string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8').'</pre>', '');
	}

	/**
	 * HTML body of a mail that leaves the reading frame (note of an object, documents, draft, quote): what display()
	 * keeps, then HTMLPurifier (strict allow list, no style block, no class, no id)
	 *
	 * @param string                $html   HTML of the mail (whole document or fragment)
	 * @param array<string, string> $inline Content-ID => data URI of the pictures of the mail (else they go)
	 * @return string Fragment
	 */
	public static function html($html, array $inline = array())
	{
		$html = (string) $html;
		if (trim($html) === '') {
			return '';
		}
		$remote = false;
		$prepared = self::prepare($html, $inline, $remote);

		return trim(self::purifier()->purify($prepared['body']));
	}

	/**
	 * Value of a mail for a field of one line (name, town, title, reference, place, file name): no tag (tags written
	 * in entities included), no control nor bidi override character, single spaces, « " » and « ` » as « ' », « < » and
	 * « > » left as « ‹ › », cut on a character
	 *
	 * @param string $text Value
	 * @param int    $max  Characters kept (0: all)
	 * @return string
	 */
	public static function line($text, $max = 255)
	{
		return self::plain($text, false, $max);
	}

	/**
	 * Value of a mail for a text field of several lines (address, description written as text): as line(), line
	 * breaks kept (two empty lines at most)
	 *
	 * @param string $text Value
	 * @param int    $max  Characters kept (0: all)
	 * @return string
	 */
	public static function lines($text, $max = 65000)
	{
		return self::plain($text, true, $max);
	}

	/**
	 * Plain text written in an HTML field of the core (note): escaped, lines kept as <br>
	 *
	 * @param string $text Text
	 * @param int    $max  Characters kept of the text, before escaping (0: all)
	 * @return string HTML
	 */
	public static function textToHtml($text, $max = 60000)
	{
		$text = self::controls(self::utf8($text), true);
		$text = trim((string) preg_replace("/\n{3,}/", "\n\n", $text));
		if ($max > 0) {
			$text = dol_trunc($text, $max, 'right', 'UTF-8', 1);
		}

		// htmlspecialchars, not dol_escape_htmltag: that one removes <b> and <strong> instead of escaping them
		return dol_nl2br(htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
	}

	/**
	 * Whole document of the reading frame
	 *
	 * @param string $body   Body (already safe)
	 * @param string $styles CSS of the mail
	 * @return string
	 */
	public static function frame($body, $styles)
	{
		// The CSS of a mail cannot close the style element nor open a tag: no « < » in it (written \3C, the same
		// character for CSS), whatever the HTML parser of the browser (audit INBX-001)
		$styles = str_replace('<', '\\3C ', (string) $styles);

		return '<!doctype html><html><head><meta charset="utf-8"><style>'
			.'html{color-scheme:light}body{margin:16px;font:14px/1.45 Arial,Helvetica,sans-serif;color:#222;background:#fff;overflow-wrap:break-word}'
			.'img{max-width:100%;height:auto}pre.inbox-text{white-space:pre-wrap;font:inherit;margin:0}table{max-width:100%}'
			.'</style><style>'.$styles.'</style></head><body>'.$body.'</body></html>';
	}

	/**
	 * First pass of an HTML body (allow list on a DOMDocument): body and style blocks apart
	 *
	 * @param string                $html      HTML of the mail
	 * @param array<string, string> $inline    Content-ID => data URI of the pictures of the mail
	 * @param bool                  $hasRemote Set when the mail loads remote pictures
	 * @return array{body:string,styles:string}
	 */
	protected static function prepare($html, array $inline, &$hasRemote)
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
		// Comments and processing instructions: never sent to the browser, nothing hides in them (audit INBX-001)
		foreach (iterator_to_array($xpath->query('//comment() | //processing-instruction()')) as $node) {
			$node->parentNode->removeChild($node);
		}
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

		return array('body' => $inner, 'styles' => implode("\n", $styles));
	}

	/**
	 * HTMLPurifier set for the mails, loaded at the first call only (never when a mail is read)
	 *
	 * @return HTMLPurifier
	 */
	protected static function purifier()
	{
		global $conf;

		if (self::$purifier !== null) {
			return self::$purifier;
		}
		if (!class_exists('HTMLPurifier_Config', false)) {
			// TODO core: when inbox moves to the core, the library goes to htdocs/includes/ezyang/htmlpurifier/ and is
			// required from DOL_DOCUMENT_ROOT.'/includes/ezyang/htmlpurifier/library/'
			// The autoloader alone: HTMLPurifier.auto.php would change the include_path of Dolibarr
			require_once dirname(__DIR__).'/lib/vendor/htmlpurifier/library/HTMLPurifier/Bootstrap.php';
			require_once dirname(__DIR__).'/lib/vendor/htmlpurifier/library/HTMLPurifier.autoload.php';
		}
		$config = HTMLPurifier_Config::createDefault();
		$config->set('Core.Encoding', 'UTF-8');
		// Transitional: the old attributes of the mails (bgcolor, align, font) are understood
		$config->set('HTML.Doctype', 'XHTML 1.0 Transitional');
		$config->set('HTML.AllowedElements', array_fill_keys(explode(',', self::PURE_ELEMENTS), true));
		$config->set('HTML.AllowedAttributes', array_fill_keys(explode(',', self::PURE_ATTRIBUTES), true));
		$config->set('CSS.AllowedProperties', array_fill_keys(explode(',', self::PURE_CSS), true));
		// data: is checked by HTMLPurifier as a real picture (png, gif, jpeg), nothing else
		$config->set('URI.AllowedSchemes', array('http' => true, 'https' => true, 'mailto' => true, 'tel' => true, 'data' => true));
		$config->set('HTML.TargetBlank', true);
		$config->set('HTML.TargetNoopener', true);
		$config->set('HTML.TargetNoreferrer', true);
		$config->set('Attr.EnableID', false);
		$config->set('Output.Newline', "\n");
		// Definitions built once and kept: without cache each request rebuilds them
		$dir = (!empty($conf->inbox->dir_temp) ? $conf->inbox->dir_temp : DOL_DATA_ROOT.'/inbox/temp').'/htmlpurifier';
		if (!is_dir($dir)) {
			dol_mkdir($dir);
		}
		if (is_dir($dir) && is_writable($dir)) {
			$config->set('Cache.SerializerPath', $dir);
		} else {
			dol_syslog(__METHOD__.' cache folder '.$dir.' not writable, HTMLPurifier runs without cache', LOG_WARNING);
			$config->set('Cache.DefinitionImpl', null);
		}
		self::$purifier = new HTMLPurifier($config);

		return self::$purifier;
	}

	/**
	 * Value of one or several lines
	 *
	 * @param string $text  Value
	 * @param bool   $lines Keep the line breaks
	 * @param int    $max   Characters kept (0: all)
	 * @return string
	 */
	protected static function plain($text, $lines, $max)
	{
		$text = self::utf8($text);
		// A tag coded in entities is a tag too (audit INBX-072): decoded then removed until nothing changes
		for ($i = 0; $i < 5; $i++) {
			$before = $text;
			$text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
			// What a script or a style holds is not text of the value
			$text = strip_tags((string) preg_replace('#<(script|style)\b[^>]*>.*?</\1\s*>#is', ' ', $text));
			if ($text === $before) {
				break;
			}
		}
		// As the « alphanohtml » filter of the forms of the core: no double quote (fields printed raw in value="")
		$text = strtr($text, array('<' => "\u{2039}", '>' => "\u{203A}", '"' => "'", '`' => "'"));
		$text = self::controls($text, $lines);
		if ($lines) {
			$text = (string) preg_replace('/[ \t\x{00A0}]+/u', ' ', $text);
			$text = (string) preg_replace("/ *\n */", "\n", $text);
			$text = trim((string) preg_replace("/\n{3,}/", "\n\n", $text));
		} else {
			$text = trim((string) preg_replace('/\s+/u', ' ', $text));
		}

		return $max > 0 ? dol_trunc($text, $max, 'right', 'UTF-8', 1) : $text;
	}

	/**
	 * Valid UTF-8 in its composed form
	 *
	 * @param mixed $text Text
	 * @return string
	 */
	protected static function utf8($text)
	{
		$text = (string) $text;
		if (!mb_check_encoding($text, 'UTF-8')) {
			$text = mb_convert_encoding($text, 'UTF-8', 'ISO-8859-1');
		}
		if (class_exists('Normalizer') && !Normalizer::isNormalized($text, Normalizer::FORM_C)) {
			$text = (string) Normalizer::normalize($text, Normalizer::FORM_C);
		}

		return $text;
	}

	/**
	 * Control characters, line and paragraph separators, bidi overrides (a name written backwards) removed
	 *
	 * @param string $text  Text
	 * @param bool   $lines Keep the line breaks and the tabs
	 * @return string
	 */
	protected static function controls($text, $lines)
	{
		$text = str_replace(array("\r\n", "\r", "\u{2028}", "\u{2029}"), "\n", (string) $text);
		$text = (string) preg_replace('/[\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $text);

		return (string) preg_replace($lines ? '/[\x00-\x08\x0B-\x1F\x7F]/u' : '/[\x00-\x1F\x7F]/u', $lines ? '' : ' ', $text);
	}
}
