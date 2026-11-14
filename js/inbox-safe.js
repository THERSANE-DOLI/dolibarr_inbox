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
 * The one door of the HTML of a mail in the page (lot S1, analysis analyse-assainissement-xss.md):
 * Dolibarr.tools.inbox.safeHtml(html).
 *
 * The reading frame (sandboxed iframe, CSP without script) protects the reading. Everything else runs in the page
 * itself, with the rights of the user: the editor (quote of an answer, draft reopened, template, paste, proposal of
 * the AI). Any HTML that goes there passes here first:
 * 1. DOMPurify, with the allow list of the sending (InboxMailBuilder::cleanHtml) plus the marks of the editor;
 * 2. then the Sanitizer API of the browser when it has one (Firefox 148, Chromium 146 behind a setting at the time of
 *    writing), never instead of DOMPurify; its configuration changed between the drafts of the specification: an
 *    error keeps the result of DOMPurify alone.
 * Without DOMPurify (script not loaded) and without Sanitizer API: the text only, escaped (fails closed).
 *
 * Both passes work in an inert document: a remote picture of a mail is never loaded by the cleaning.
 *
 * TODO core: when inbox moves to the core, DOMPurify goes to htdocs/public/includes/dompurify/ (loaded by the core) and
 * this function becomes Dolibarr.tools.safeHtml for every module.
 */
(function () {
	'use strict';

	const I = Dolibarr.tools.inbox;

	/** Tags of a mail written in inbox: same list as InboxMailBuilder::TAGS */
	const TAGS = ['p', 'br', 'div', 'span', 'b', 'strong', 'i', 'em', 'u', 's', 'strike', 'del', 'sub', 'sup', 'small',
		'a', 'ul', 'ol', 'li', 'blockquote', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'hr', 'pre', 'code', 'img',
		'table', 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th', 'caption', 'colgroup', 'col', 'center', 'font'];
	/** Attributes: InboxMailBuilder::ATTRIBUTES plus the marks of the editor (checked by the hook below) */
	const ATTRS = ['href', 'src', 'alt', 'title', 'style', 'width', 'height', 'align', 'valign', 'colspan', 'rowspan',
		'cellpadding', 'cellspacing', 'border', 'bgcolor', 'color', 'face', 'size', 'dir', 'lang', 'type', 'start', 'cite',
		'class', 'data-inbox-var', 'data-colwidth'];
	/** Marks of inbox kept in the class (quote and signature found again in a draft) */
	const CLASSES = ['inbox-quote', 'inbox-signature', 'inbox-signature-edited'];
	const HREF = /^(?:https?:\/\/|mailto:|tel:[^:]{1,64}$|#)/i;
	const SRC = /^(?:https:|data:image\/(?:png|jpeg|gif|webp);base64,)/i;
	const BAD_STYLE = /expression\s*\(|javascript:|vbscript:|behavior\s*:|-moz-binding|@import/i;

	let purify = null;

	/**
	 * DOMPurify set once: the allow list and the checks of the values (hook on each attribute)
	 *
	 * @return {object|null}
	 */
	function dompurify() {
		if (purify || typeof window.DOMPurify !== 'function' || !window.DOMPurify.isSupported) {
			return purify;
		}
		purify = window.DOMPurify(window);
		purify.addHook('uponSanitizeAttribute', (node, data) => {
			const name = data.attrName;
			const value = String(data.attrValue || '').trim();
			let keep = true;
			if (name === 'class') {
				const kept = value.split(/\s+/).filter(c => CLASSES.includes(c));
				data.attrValue = kept.join(' ');
				keep = kept.length > 0;
			} else if (name === 'data-inbox-var') {
				keep = node.nodeName === 'SPAN' && /^[A-Z0-9_]{1,64}$/.test(value);
			} else if (name === 'data-colwidth') {
				keep = (node.nodeName === 'TD' || node.nodeName === 'TH') && /^\d{1,4}(?:,\d{1,4})*$/.test(value);
			} else if (name === 'href') {
				keep = HREF.test(value);
			} else if (name === 'src') {
				keep = SRC.test(value);
			} else if (name === 'style') {
				keep = !BAD_STYLE.test(value);
			}
			if (!keep) {
				data.keepAttr = false;
			}
		});
		purify.addHook('afterSanitizeAttributes', node => {
			if (node.nodeName === 'A' && node.hasAttribute('href')) {
				node.setAttribute('rel', 'noopener noreferrer');
			}
		});
		return purify;
	}

	/**
	 * Second pass by the Sanitizer API of the browser, when it has one
	 *
	 * @param {string} html HTML cleaned by DOMPurify
	 * @return {string|null} null when the browser has none or refused the configuration
	 */
	function browserSanitizer(html) {
		if (typeof window.Sanitizer !== 'function' || typeof Element.prototype.setHTML !== 'function') {
			return null;
		}
		try {
			const sanitizer = new window.Sanitizer({ elements: TAGS, attributes: ATTRS, comments: false });
			// Inert document: nothing is loaded, nothing runs
			const box = document.implementation.createHTMLDocument('').createElement('div');
			box.setHTML(html, { sanitizer });
			return box.innerHTML;
		} catch (e) {
			return null;
		}
	}

	/**
	 * Text of an HTML, escaped: what is left when no cleaner is there
	 *
	 * @param {string} html HTML
	 * @return {string}
	 */
	function textOnly(html) {
		const doc = new DOMParser().parseFromString(String(html), 'text/html');
		return I.esc(doc.body ? doc.body.textContent : '').replace(/\n/g, '<br>');
	}

	/**
	 * HTML of a mail made safe for the page (editor, insertion)
	 *
	 * @param {string} html HTML
	 * @return {string}
	 */
	function safeHtml(html) {
		html = String(html || '');
		if (html.trim() === '') {
			return '';
		}
		const p = dompurify();
		const first = p ? p.sanitize(html, {
			ALLOWED_TAGS: TAGS,
			ALLOWED_ATTR: ATTRS,
			ALLOW_DATA_ATTR: false,
			ALLOW_ARIA_ATTR: false,
			ALLOW_UNKNOWN_PROTOCOLS: false,
			FORBID_TAGS: ['style', 'svg', 'math', 'form', 'template'],
			KEEP_CONTENT: true,
			RETURN_TRUSTED_TYPE: false,
		}) : null;
		const second = browserSanitizer(first !== null ? first : html);
		if (second !== null) {
			return second;
		}
		return first !== null ? first : textOnly(html);
	}

	/**
	 * Which cleaners this browser uses (shown by the tests, useful in a support request)
	 *
	 * @return {{dompurify:string, sanitizerApi:boolean}}
	 */
	function safeHtmlEngines() {
		const p = dompurify();
		return { dompurify: p ? String(p.version || '') : '', sanitizerApi: browserSanitizer('<p>x</p>') !== null };
	}

	I.safeHtml = safeHtml;
	I.safeHtmlEngines = safeHtmlEngines;
})();
