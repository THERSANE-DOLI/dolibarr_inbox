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
 * Editors of the writing (lot W6 1.16.0, webmail §5.5 ter): the rest of inbox knows only this interface, so that
 * another editor is one adapter away.
 * - Registry: Dolibarr.tools.inbox.editors.register(name, factory); another module registers its own on the event
 *   inbox:editor:register. inbox registers « tiptap » (frozen bundle js/vendor/tiptap, loaded at the first writing) and
 *   « ckeditor » (the CKEditor of Dolibarr, 1.94.0, chosen in Settings > General when the module FCKeditor is on).
 * - An editor: create(el, {html, placeholder, label}), destroy(), getHtml(), setHtml(html), getText(), isEmpty(),
 *   exec(command, value), isActive(command), focus(), on(event, fn) (change, focus, blur), getCapabilities()
 *   (nativeToolbar: the editor shows its own toolbar and menu, inbox hides its formatting buttons), insertImage(src),
 *   setSignature(html), hasSignature(), removeSignature() (signature changed for one mail, div.inbox-signature-edited).
 * - Commands: bold, italic, underline, strike, link, unlink, bulletList, orderedList, quote, heading, clearFormat, undo,
 *   redo, color, background, align, tableInsert, tableRow, tableColumn, tableRowDelete, tableColumnDelete, tableDelete.
 * - HTML pasted as text (an answer prepared by an AI, a « ```html » block) is put in formatted, Ctrl+Z takes it back.
 * - Pivot format: simple HTML; the server cleans it anyway (InboxMailBuilder::cleanHtml), never trusting the editor.
 */
(function () {
	'use strict';

	const I = Dolibarr.tools.inbox;
	const factories = {};
	let tiptapLoading = null;
	/** Mark of a signature changed for one mail (1.95.0): it stays in the text (drafts, sending), kept by the server */
	const SIGNATURE_CLASS = 'inbox-signature-edited';

	function register(name, factory) {
		factories[name] = factory;
	}
	/** Choice of the editor changed in Settings > General since the page loaded (the JS context cannot be changed) */
	const choice = {};
	const setting = (key, fallback) => (Object.prototype.hasOwnProperty.call(choice, key) ? choice[key] : I.ctx(key, fallback));
	function choose(key, value) {
		choice[key] = value;
	}
	// CKEditor asked with the toolbar of inbox whatever INBOX_CKEDITOR_NATIVE says (small screen), read by its factory
	let inboxBarOnly = false;

	/** Declarations of a style attribute as written: [[property, value]] (a ; inside quotes or brackets kept) */
	const declarations = css => {
		const out = [];
		let cur = '';
		let depth = 0;
		let quote = '';
		for (const ch of String(css || '')) {
			if (quote) {
				quote = ch === quote ? '' : quote;
			} else if (ch === '"' || ch === '\'') {
				quote = ch;
			} else if (ch === '(') {
				depth++;
			} else if (ch === ')') {
				depth = Math.max(0, depth - 1);
			} else if (ch === ';' && !depth) {
				out.push(cur);
				cur = '';
				continue;
			}
			cur += ch;
		}
		out.push(cur);
		return out.map(d => {
			const i = d.indexOf(':');
			return i > 0 ? [d.slice(0, i).trim().toLowerCase(), d.slice(i + 1).trim()] : null;
		}).filter(d => d && d[0] && d[1] !== '');
	};
	/**
	 * Text of the clipboard that is HTML source (not a rich copy), to put in formatted: an answer prepared by an AI, a
	 * « ```html » block; '' when it is not
	 * @param {string} text Text of the clipboard
	 * @param {string} rich HTML of the clipboard
	 * @returns {string}
	 */
	function htmlSourceOf(text, rich) {
		text = text || '';
		rich = rich || '';
		// A rich copy is pasted as it is, except a copy of a block of code (the source shown by an AI)
		if (rich && !/<(pre|code)\b/i.test(rich)) {
			return '';
		}
		const fence = text.match(/^\s*```(?:html)?[ \t]*\r?\n([\s\S]*?)\r?\n```\s*$/i);
		if (fence) {
			text = fence[1];
		}
		return /^\s*</.test(text) && /<\/(p|div|ul|ol|li|h[1-6]|table|tr|td|blockquote|strong|b|em|i|u|a|span)>/i.test(text) ? text : '';
	}
	/** Line breaks between blocks removed: the editor would make empty paragraphs and bullets of them (1.28.3) */
	const tightBlocks = html => String(html || '').replace(/(<\/?(?:p|div|ul|ol|li|h[1-6]|blockquote|table|thead|tbody|tfoot|tr|td|th|br|hr)\b[^>]*>)\s+/gi, '$1')
		.replace(/\s+(<\/?(?:p|div|ul|ol|li|h[1-6]|blockquote|table|thead|tbody|tfoot|tr|td|th|br|hr)\b[^>]*>)/gi, '$1').trim();

	/** The bundle of Tiptap, once, with the nonce of the page (its CSP) */
	function loadTiptap() {
		if (window.InboxTiptap) {
			return Promise.resolve(window.InboxTiptap);
		}
		if (!tiptapLoading) {
			tiptapLoading = new Promise((resolve, reject) => {
				const s = document.createElement('script');
				const any = document.querySelector('script[nonce]');
				if (any && any.nonce) {
					s.nonce = any.nonce;
				}
				s.src = I.ctx('INBOX_EDITOR_URL', '');
				s.onload = () => (window.InboxTiptap ? resolve(window.InboxTiptap) : reject(new Error('editor')));
				s.onerror = () => {
					tiptapLoading = null;
					reject(new Error('editor'));
				};
				document.head.appendChild(s);
			});
		}
		return tiptapLoading;
	}

	/** Tiptap adapter */
	register('tiptap', async function () {
		const T = await loadTiptap();
		let editor = null;
		const listeners = { change: [], focus: [], blur: [], notice: [] };
		const fire = (ev, arg) => listeners[ev].forEach(fn => fn(arg));
		const chain = () => editor.chain().focus();
		const COMMANDS = {
			bold: () => chain().toggleBold().run(),
			italic: () => chain().toggleItalic().run(),
			underline: () => chain().toggleUnderline().run(),
			strike: () => chain().toggleStrike().run(),
			bulletList: () => chain().toggleBulletList().run(),
			orderedList: () => chain().toggleOrderedList().run(),
			quote: () => chain().toggleBlockquote().run(),
			heading: v => chain().toggleHeading({ level: v || 3 }).run(),
			clearFormat: () => chain().unsetAllMarks().clearNodes().run(),
			undo: () => chain().undo().run(),
			redo: () => chain().redo().run(),
			link: v => (v ? chain().extendMarkRange('link').setLink({ href: v }).run() : chain().extendMarkRange('link').unsetLink().run()),
			unlink: () => chain().extendMarkRange('link').unsetLink().run(),
			color: v => (v ? chain().setColor(v).run() : chain().unsetColor().run()),
			background: v => (v ? chain().setBackgroundColor(v).run() : chain().unsetBackgroundColor().run()),
			align: v => chain().setTextAlign(v || 'left').run(),
			tableInsert: () => chain().insertTable({ rows: 3, cols: 3, withHeaderRow: false }).run(),
			tableRow: () => chain().addRowAfter().run(),
			tableColumn: () => chain().addColumnAfter().run(),
			tableRowDelete: () => chain().deleteRow().run(),
			tableColumnDelete: () => chain().deleteColumn().run(),
			tableDelete: () => chain().deleteTable().run(),
			tableRowBefore: () => chain().addRowBefore().run(),
			tableColumnBefore: () => chain().addColumnBefore().run(),
			tableMerge: () => chain().mergeOrSplit().run(),
			tableHeader: () => chain().toggleHeaderRow().run(),
			tableHeaderColumn: () => chain().toggleHeaderColumn().run(),
			imageDelete: () => chain().deleteSelection().run(),
		};
		// ---------- Pictures (1.25.1): the one selected, its size kept as width / height (read by every mail software) ----------
		const currentImage = () => {
			const sel = editor ? editor.state.selection : null;
			return sel && sel.node && sel.node.type.name === 'image' ? { node: sel.node, pos: sel.from } : null;
		};
		const imageElement = pos => {
			const dom = editor.view.nodeDOM(pos);
			return dom ? (dom.tagName === 'IMG' ? dom : dom.querySelector('img')) : null;
		};
		/** The picture selected: alt, size, natural size; null when none */
		const imageInfo = () => {
			const im = currentImage();
			if (!im) {
				return null;
			}
			const el = imageElement(im.pos);
			return { alt: im.node.attrs.alt || '', width: im.node.attrs.width || (el ? el.offsetWidth : 0), naturalWidth: el ? el.naturalWidth : 0, naturalHeight: el ? el.naturalHeight : 0 };
		};
		/**
		 * Change the picture selected: {width} (px, the height follows the proportions; 0 = its own size), {alt}
		 * @param {object} p
		 */
		const imageProps = p => {
			const im = currentImage();
			if (!im) {
				return;
			}
			const attrs = {};
			const el = imageElement(im.pos);
			if (p.width !== undefined) {
				const w = Math.round(+p.width || 0);
				const ratio = el && el.naturalWidth ? el.naturalHeight / el.naturalWidth : 0;
				attrs.width = w > 0 ? w : null;
				attrs.height = w > 0 && ratio ? Math.round(w * ratio) : null;
				// The view of a resizable picture does not follow its size attributes: drawn here
				if (el) {
					el.style.width = attrs.width ? attrs.width + 'px' : '';
					el.style.height = attrs.height ? attrs.height + 'px' : '';
				}
			}
			if (p.alt !== undefined) {
				attrs.alt = String(p.alt).trim();
			}
			editor.chain().focus().setNodeSelection(im.pos).updateAttributes('image', attrs).run();
		};
		/** Box of the picture selected on the screen */
		const imageBox = () => {
			const im = currentImage();
			const el = im ? imageElement(im.pos) : null;
			return el ? el.getBoundingClientRect() : null;
		};
		// ---------- Tables: attributes written as inline styles ----------
		const px = v => (parseInt(v, 10) || null);
		/** Sides of a border: letters t r b l (the old values all, h, none read too) */
		const sides = f => ({ all: 'trbl', h: 'b', none: '' }[f] !== undefined ? { all: 'trbl', h: 'b', none: '' }[f] : String(f || ''));
		/**
		 * Borders and spacing of a cell the editor can own: the sides drawn of one width, one colour, solid; the same
		 * spacing on the four sides. Else (a signature made elsewhere: a thick coloured bar on one side, a spacing on the
		 * right only) they are kept as written (1.95.0), never made the grid of the editor.
		 */
		const SIDES = ['Top', 'Right', 'Bottom', 'Left'];
		const hasSide = (st, side) => st['border' + side + 'Style'] && st['border' + side + 'Style'] !== 'none' && st['border' + side + 'Width'] !== '0px';
		const drawnSides = el => SIDES.filter(s => hasSide(el.style, s));
		const borderOwned = el => {
			const st = el.style;
			const drawn = drawnSides(el);
			const one = prop => new Set(drawn.map(s => st['border' + s + prop])).size === 1;
			return !drawn.length || (one('Width') && one('Color') && one('Style') && st['border' + drawn[0] + 'Style'] === 'solid');
		};
		const paddingOwned = el => new Set(SIDES.map(s => el.style['padding' + s])).size === 1;
		const cellAttrs = {
			background: { default: null, parseHTML: el => el.style.backgroundColor || null, renderHTML: a => (a.background ? { style: 'background-color:' + a.background } : {}) },
			// Borders side by side: letters of the sides drawn (t r b l, '' = none, null = not chosen: the grid of the
			// server, or borders kept as written); with their width and colour
			frame: {
				default: null,
				parseHTML: el => {
					if (!(el.getAttribute('style') || '').includes('border') || !borderOwned(el)) {
						return null;
					}
					const st = el.style;
					const has = side => st['border' + side + 'Style'] && st['border' + side + 'Style'] !== 'none' && st['border' + side + 'Width'] !== '0px';
					return (has('Top') ? 't' : '') + (has('Right') ? 'r' : '') + (has('Bottom') ? 'b' : '') + (has('Left') ? 'l' : '');
				},
				renderHTML: a => {
					if (a.frame === null || a.frame === undefined) {
						return {};
					}
					const f = sides(a.frame);
					const line = (a.borderWidth || 1) + 'px solid ' + (a.borderColor || '#d0d5dd');
					return { style: [['top', 't'], ['right', 'r'], ['bottom', 'b'], ['left', 'l']].map(x => 'border-' + x[0] + ':' + (f.includes(x[1]) ? line : '0')).join(';') };
				},
			},
			// Read from a side drawn (a border on the right only has no bottom one)
			borderWidth: { default: null, parseHTML: el => (borderOwned(el) && drawnSides(el).length ? px(el.style['border' + drawnSides(el)[0] + 'Width']) : null), renderHTML: () => ({}) },
			borderColor: { default: null, parseHTML: el => (borderOwned(el) && drawnSides(el).length ? el.style['border' + drawnSides(el)[0] + 'Color'] || null : null), renderHTML: () => ({}) },
			padding: { default: null, parseHTML: el => (paddingOwned(el) ? px(el.style.paddingTop) : null), renderHTML: a => (a.padding ? { style: 'padding:' + a.padding + 'px' } : {}) },
			textAlign: { default: null, parseHTML: el => el.style.textAlign || null, renderHTML: a => (a.textAlign ? { style: 'text-align:' + a.textAlign } : {}) },
			verticalAlign: { default: null, parseHTML: el => el.style.verticalAlign || null, renderHTML: a => (a.verticalAlign ? { style: 'vertical-align:' + a.verticalAlign } : {}) },
		};
		const cellExt = base => base.extend({
			addAttributes() {
				const parent = this.parent ? this.parent() : {};
				return Object.assign({}, parent, cellAttrs, {
					// Width of the column: data-colwidth only in the editor (a width attribute on the cell would stop
					// the column from shrinking while it is dragged); the server writes width="" for Outlook, read back here
					colwidth: Object.assign({}, parent.colwidth || {}, {
						parseHTML: el => {
							const c = el.getAttribute('data-colwidth') || el.getAttribute('width');
							if (c && /^[\d,]+$/.test(c)) {
								return c.split(',').map(n => parseInt(n, 10));
							}
							// A width in px written as a style (a signature made elsewhere), on a cell of one column
							const w = /^(\d{1,4})px$/.exec(el.style.width || '');
							return w && (+el.getAttribute('colspan') || 1) === 1 ? [+w[1]] : null;
						},
						renderHTML: a => (a.colwidth ? { 'data-colwidth': a.colwidth.join(',') } : {}),
					}),
				});
			},
		});
		const tableExt = T.Table.extend({
			addAttributes() {
				return Object.assign({}, this.parent ? this.parent() : {}, {
					tableWidth: { default: null, parseHTML: el => (el.style.width === '100%' || el.getAttribute('width') === '100%' ? 'full' : null), renderHTML: a => (a.tableWidth === 'full' ? { style: 'width:100%', width: '100%' } : {}) },
					tableAlign: { default: null, parseHTML: el => (el.getAttribute('align') === 'center' || el.style.marginLeft === 'auto' ? 'center' : null), renderHTML: a => (a.tableAlign === 'center' ? { align: 'center', style: 'margin-left:auto;margin-right:auto' } : {}) },
				});
			},
		});
		// ---------- Inline styles kept (1.51.0) ----------
		// A signature copied from Dolibarr, a template, a text pasted from another software carry inline styles (size,
		// font, line height, colour of a link, width of a table) that no button of the editor makes: each node and mark
		// keeps its style as written, except the properties an attribute of the editor already owns (written by it, the
		// buttons change them). Kept as written (never el.style, which splits text-decoration into longhands Outlook
		// does not read).
		/** Properties owned by an attribute of the editor, by node or mark */
		const OWNED = {
			textStyle: /^(color|background-color|background)$/,
			paragraph: /^text-align$/,
			heading: /^text-align$/,
			tableCell: /^(background-color|background|border.*|padding.*|text-align|vertical-align|width)$/,
			tableHeader: /^(background-color|background|border.*|padding.*|text-align|vertical-align|width)$/,
			image: /^(width|height)$/,
		};
		/** Written by a mark of the editor (bold, italic, underline, strike): the button can take it off */
		const MARKED = d => (d[0] === 'font-weight' && /^(bold(er)?|[5-9]\d{2,})$/i.test(d[1])) || (d[0] === 'font-style' && /^italic$/i.test(d[1]))
			|| (d[0] === 'text-decoration' && /^(underline|line-through)$/i.test(d[1]));
		/**
		 * Never kept from a mail taken back in the editor (audit INBX-079): a block laid over the page (position,
		 * z-index...) or an address loaded by the page of inbox (url(), image-set)
		 */
		const UNSAFE_STYLE = d => /^(position|z-index|inset|top|right|bottom|left|transform|filter|cursor|pointer-events|behavior|-moz-binding|content)$/i.test(d[0])
			|| /url\s*\(|image-set|expression\s*\(|@import|javascript:/i.test(d[1]);
		const keptStyle = (type, el) => {
			let decl = declarations(el.getAttribute('style'));
			// Spans one inside the other make one mark: the styles of the spans around are taken in, the inner one wins
			if (type === 'textStyle') {
				for (let p = el.parentElement; p && p.tagName === 'SPAN'; p = p.parentElement) {
					decl = declarations(p.getAttribute('style')).concat(decl);
				}
			}
			let owned = OWNED[type];
			// A cell: its borders and spacing owned only when the editor can draw them (else kept as written)
			if (type === 'tableCell' || type === 'tableHeader') {
				owned = new RegExp('^(background-color|background|' + (borderOwned(el) ? 'border.*|' : '') + (paddingOwned(el) ? 'padding.*|' : '') + 'text-align|vertical-align|width)$');
			}
			const byName = new Map();
			decl.forEach(d => {
				if (UNSAFE_STYLE(d) || (owned && owned.test(d[0])) || (MARKS_STYLED.includes(type) && MARKED(d))) {
					return;
				}
				// The table: its full width and centring are attributes of the editor
				if (type === 'table' && ((d[0] === 'width' && d[1] === '100%') || (/^margin-(left|right)$/.test(d[0]) && d[1] === 'auto'))) {
					return;
				}
				byName.delete(d[0]);
				byName.set(d[0], d[1]);
			});
			return byName.size ? Array.from(byName, d => d[0] + ':' + d[1]).join(';') : null;
		};
		/** Marks whose own style is kept (a « <b style="color:red"> » of a signature, 1.95.0) less what the mark writes */
		const MARKS_STYLED = ['textStyle', 'link', 'bold', 'italic', 'underline', 'strike'];
		const STYLED = ['bold', 'italic', 'underline', 'strike', 'textStyle', 'link', 'paragraph', 'heading', 'blockquote', 'bulletList', 'orderedList', 'listItem', 'table', 'tableRow', 'tableCell', 'tableHeader', 'image'];
		const inlineStyles = T.Extension.create({
			name: 'inboxInlineStyles',
			addGlobalAttributes() {
				// HTML attributes of the old tables of mails (spacing and borders of the browser else)
				const html = name => ({ default: null, parseHTML: el => el.getAttribute(name), renderHTML: a => (a[name] !== null && a[name] !== undefined ? { [name]: a[name] } : {}) });
				return STYLED.map(type => ({
					types: [type],
					attributes: {
						inlineStyle: {
							default: null,
							parseHTML: el => keptStyle(type, el),
							renderHTML: a => (a.inlineStyle ? { style: a.inlineStyle } : {}),
						},
					},
				})).concat([
					{ types: ['table'], attributes: { border: html('border'), cellpadding: html('cellpadding'), cellspacing: html('cellspacing') } },
					{ types: ['tableCell', 'tableHeader'], attributes: { valign: html('valign'), height: html('height') } },
				]);
			},
		});
		/**
		 * Paragraph that stays a <div> when it was one: a signature or a template made of divs keeps its spacing (a <p>
		 * has margins in every mail software). A div holding blocks is not a paragraph: its content is read as it is.
		 */
		const paragraphNode = T.Node.create({
			name: 'paragraph',
			priority: 1000,
			group: 'block',
			content: 'inline*',
			addAttributes() {
				return { div: { default: false, parseHTML: el => el.tagName === 'DIV', renderHTML: () => ({}) } };
			},
			parseHTML() {
				return [{ tag: 'p' }, { tag: 'div', getAttrs: el => (el.querySelector(':scope > :is(div, p, table, ul, ol, blockquote, h1, h2, h3, h4, h5, h6, hr, pre)') ? false : null) }];
			},
			renderHTML({ node, HTMLAttributes }) {
				return [node.attrs.div ? 'div' : 'p', HTMLAttributes, 0];
			},
			addCommands() {
				return { setParagraph: () => ({ commands }) => commands.setNode(this.name) };
			},
			addKeyboardShortcuts() {
				return { 'Mod-Alt-0': () => this.editor.commands.setParagraph() };
			},
		});
		/**
		 * Text outside any block (« <br>Thanks.<br>Regards. », a cell, the text after a table) put in <div>: the editor
		 * would wrap it in <p>, whose margins move everything down
		 */
		const INHERITED = /^(font|font-family|font-size|font-style|font-weight|color|line-height|letter-spacing|text-align|text-transform)$/;
		const BLOCK = /^(DIV|P|TABLE|THEAD|TBODY|TFOOT|TR|TD|TH|UL|OL|LI|BLOCKQUOTE|H[1-6]|HR|PRE|COLGROUP|COL|CAPTION)$/;
		const looseInDivs = html => {
			const s = String(html || '');
			if (!s.includes('<')) {
				return s;
			}
			const doc = new DOMParser().parseFromString('<body>' + s + '</body>', 'text/html');
			// Ordinary blanks of a text made one space (the same for the browser): the editor drops a text « \n&nbsp;\n »
			// as if it were a line break between tags, and an empty line of the signature went with it
			const tw = doc.createTreeWalker(doc.body, NodeFilter.SHOW_TEXT);
			for (let n = tw.nextNode(); n; n = tw.nextNode()) {
				if (!n.parentElement.closest('pre') && /[\t\r\n]/.test(n.nodeValue)) {
					n.nodeValue = n.nodeValue.replace(/[ \t\r\n]+/g, ' ');
				}
			}
			const wrap = parent => {
				let run = [];
				const flush = before => {
					if (run.some(n => n.nodeType === 1 || n.textContent.trim() !== '')) {
						const div = doc.createElement('div');
						parent.insertBefore(div, before);
						run.forEach(n => div.appendChild(n));
					}
					run = [];
				};
				Array.from(parent.childNodes).forEach(n => {
					if (n.nodeType === 1 && BLOCK.test(n.tagName)) {
						flush(n);
					} else if (n.nodeType === 1 || n.nodeType === 3) {
						run.push(n);
					}
				});
				flush(null);
			};
			// Only where text and blocks can stand side by side (a <p> or a div of text is already a paragraph)
			[doc.body].concat(Array.from(doc.body.querySelectorAll('td, th, li, blockquote, div'))).forEach(el => {
				if (el.tagName !== 'DIV' || Array.from(el.children).some(c => BLOCK.test(c.tagName))) {
					wrap(el);
				}
			});
			// A div holding blocks is no node of the editor: what its style gives to its content (font, size, colour of a
			// text pasted from Outlook or Word) goes down to its blocks, theirs winning
			doc.body.querySelectorAll('div[style]').forEach(el => {
				if (!Array.from(el.children).some(c => BLOCK.test(c.tagName))) {
					return;
				}
				const inherited = declarations(el.getAttribute('style')).filter(d => INHERITED.test(d[0]));
				if (!inherited.length) {
					return;
				}
				Array.from(el.children).forEach(c => {
					const own = declarations(c.getAttribute('style'));
					const names = new Set(own.map(d => d[0]));
					c.setAttribute('style', inherited.filter(d => !names.has(d[0])).concat(own).map(d => d[0] + ':' + d[1]).join(';'));
				});
			});
			return doc.body.innerHTML;
		};
		const STRIPE = '#f6f8fa';
		const HEAD = '#eef2f7';
		/** Table around the cursor */
		const currentTable = () => {
			const $f = editor.state.selection.$from;
			for (let d = $f.depth; d > 0; d--) {
				if ($f.node(d).type.name === 'table') {
					return { node: $f.node(d), pos: $f.before(d) };
				}
			}
			return null;
		};
		/** Every cell of the table changed by fn(cell, row, column) => {type, attrs} | null, the table by its attrs */
		const editTable = (fn, tableAttrs) => {
			const tb = currentTable();
			if (!tb) {
				return;
			}
			const tr = editor.state.tr;
			tb.node.forEach((row, rOff, rIdx) => {
				row.forEach((cell, cOff, cIdx) => {
					const res = fn(cell, rIdx, cIdx);
					if (res) {
						tr.setNodeMarkup(tb.pos + 1 + rOff + 1 + cOff, res.type ? editor.schema.nodes[res.type] : null, Object.assign({}, cell.attrs, res.attrs || {}));
					}
				});
			});
			if (tableAttrs) {
				tr.setNodeMarkup(tb.pos, null, Object.assign({}, tb.node.attrs, tableAttrs));
			}
			editor.view.dispatch(tr);
			editor.commands.focus();
		};
		/** Styles in one click: grid, lines, none, header */
		const tableStyle = preset => {
			const line = { borderWidth: 1, borderColor: '#d0d5dd' };
			editTable((cell, rIdx) => {
				const header = cell.type.name === 'tableHeader';
				if (preset === 'header') {
					return { type: rIdx === 0 ? 'tableHeader' : 'tableCell', attrs: Object.assign({ frame: 'trbl', padding: 6, background: rIdx === 0 ? HEAD : null }, line) };
				}
				const attrs = Object.assign({ frame: preset === 'grid' ? 'trbl' : (preset === 'lines' ? 'b' : ''), padding: preset === 'none' ? 4 : 6 }, line);
				attrs.background = header && cell.attrs.background === HEAD ? HEAD : null;
				return { attrs };
			});
		};
		/** Properties of the table read from it: borders and spacing of a cell of the body, stripes, width, alignment */
		const tableInfo = () => {
			const tb = currentTable();
			if (!tb) {
				return null;
			}
			const rows = [];
			tb.node.forEach(row => rows.push(row));
			const body = rows.find(row => row.firstChild && row.firstChild.type.name === 'tableCell') || rows[0];
			const a = body.firstChild.attrs;
			const odd = rows.filter(row => row.firstChild && row.firstChild.type.name === 'tableCell')[1];
			return {
				frame: ({ trbl: 'all', b: 'h', '': 'none' })[a.frame === null ? 'trbl' : sides(a.frame)] || 'all', borderWidth: a.borderWidth || 1, borderColor: a.borderColor || '#d0d5dd', padding: a.padding || 6,
				stripes: !!(odd && odd.firstChild.attrs.background === STRIPE), width: tb.node.attrs.tableWidth || 'auto', align: tb.node.attrs.tableAlign || 'left',
			};
		};
		const tableProps = p => {
			let body = -1;
			editTable(() => {
				return { attrs: { frame: sides(p.frame), borderWidth: p.borderWidth, borderColor: p.borderColor, padding: p.padding } };
			}, { tableWidth: p.width === 'full' ? 'full' : null, tableAlign: p.align === 'center' ? 'center' : null });
			// Stripes: every other row of the body, the colour chosen by hand kept
			editTable((cell, rIdx, cIdx) => {
				if (cell.type.name === 'tableHeader') {
					return null;
				}
				if (cIdx === 0) {
					body++;
				}
				const striped = p.stripes && body % 2 === 1;
				if (striped) {
					return { attrs: { background: STRIPE } };
				}
				return cell.attrs.background === STRIPE ? { attrs: { background: null } } : null;
			});
		};
		const cellInfo = () => {
			const a = Object.assign({}, editor.getAttributes('tableCell'), editor.isActive('tableHeader') ? editor.getAttributes('tableHeader') : {}, { header: editor.isActive('tableHeader') });
			// Not chosen yet: the grid the server draws
			a.frame = a.frame === null || a.frame === undefined ? 'trbl' : sides(a.frame);
			return a;
		};
		const cellProps = p => {
			// Heading or not: the type of the selected cells changes first, their attributes follow
			if (p.header !== undefined && p.header !== editor.isActive('tableHeader')) {
				editor.chain().focus().toggleHeaderCell().run();
			}
			let c = editor.chain().focus();
			Object.keys(p).filter(k => k !== 'header').forEach(k => {
				c = c.setCellAttribute(k, p[k]);
			});
			c.run();
		};

		/**
		 * Variable of a template (lot W6 1.18.0): a chip, its value inside (empty: the key shown by the style, nothing
		 * written), read back from span[data-inbox-var]; the mail sent holds its text only (cleaned by the server)
		 */
		const varNode = T.Node.create({
			name: 'inboxVar',
			group: 'inline',
			inline: true,
			atom: true,
			selectable: true,
			addAttributes() {
				return {
					key: { default: '', parseHTML: el => el.getAttribute('data-inbox-var') || '', renderHTML: a => ({ 'data-inbox-var': a.key }) },
					value: { default: '', parseHTML: el => el.textContent || '', renderHTML: () => ({}) },
				};
			},
			parseHTML() {
				return [{ tag: 'span[data-inbox-var]' }];
			},
			renderHTML({ node, HTMLAttributes }) {
				return ['span', T.mergeAttributes(HTMLAttributes, { class: 'ibx-var' + (node.attrs.value === '' ? ' is-empty' : '') }), node.attrs.value];
			},
			renderText({ node }) {
				return node.attrs.value;
			},
		});
		/**
		 * Signature changed for this mail (1.95.0): a block of blocks, div.inbox-signature-edited (a div holding blocks is
		 * no node of the editor otherwise: the mark would be lost)
		 */
		const signatureNode = T.Node.create({
			name: 'inboxSignature',
			group: 'block',
			content: 'block+',
			defining: true,
			isolating: true,
			// Never the first block of the schema (it would fill an empty text, itself filled with it...): only its rule of
			// reading goes before the one of the paragraph read from a div
			parseHTML() {
				return [{ tag: 'div.' + SIGNATURE_CLASS, priority: 60 }];
			},
			renderHTML() {
				return ['div', { class: SIGNATURE_CLASS }, 0];
			},
		});
		const findSignature = () => {
			let found = null;
			editor.state.doc.forEach((node, pos) => {
				if (!found && node.type.name === 'inboxSignature') {
					found = { node, pos };
				}
			});
			return found;
		};
		/** Pictures pasted (a screenshot): put in the text, as data: (parts of the mail at the sending, B21) */
		const MAX_PICTURE = 5 * 1048576;
		const pastePictures = ev => {
			const files = Array.from((ev.clipboardData && ev.clipboardData.files) || []).filter(f => /^image\/(png|jpeg|gif|webp)$/.test(f.type));
			if (!files.length) {
				return false;
			}
			files.forEach(f => {
				if (f.size > MAX_PICTURE) {
					fire('notice', { code: 'picture_too_big', name: f.name });
					return;
				}
				const fr = new FileReader();
				fr.onload = () => editor && editor.chain().focus().setImage({ src: String(fr.result), alt: '' }).run();
				fr.readAsDataURL(f);
			});
			return true;
		};

		const htmlSource = ev => (ev.clipboardData ? htmlSourceOf(ev.clipboardData.getData('text/plain'), ev.clipboardData.getData('text/html')) : '');
		const ACTIVE = { bold: 'bold', italic: 'italic', underline: 'underline', strike: 'strike', bulletList: 'bulletList', orderedList: 'orderedList', quote: 'blockquote', link: 'link', heading: 'heading' };
		return {
			name: 'tiptap',
			create(el, o) {
				o = o || {};
				editor = new T.Editor({
					element: el,
					content: looseInDivs(o.html || ''),
					extensions: [
						T.StarterKit.configure({
							paragraph: false,
							heading: { levels: [2, 3, 4] },
							link: { openOnClick: false, autolink: true, protocols: ['mailto', 'tel'], HTMLAttributes: { rel: 'noopener noreferrer', target: null } },
						}),
						// Corners to resize, the proportions kept (1.25.1)
						T.Image.configure({ inline: true, allowBase64: true, resize: { enabled: true, directions: ['top-left', 'top-right', 'bottom-left', 'bottom-right'], minWidth: 24, minHeight: 16, alwaysPreserveAspectRatio: true } }),
						T.Placeholder.configure({ placeholder: o.placeholder || '' }),
						// What the mail software understand: inline colours, alignment, simple tables
						T.TextStyle, T.Color, T.BackgroundColor,
						T.TextAlign.configure({ types: ['heading', 'paragraph'] }),
						// Tables for mails: everything written as inline styles (the only thing the mail software read),
						// read back from them when a draft is reopened; column widths by the mouse
						tableExt.configure({ resizable: true, cellMinWidth: 40, lastColumnResizable: true }),
						T.TableRow,
						cellExt(T.TableCell),
						cellExt(T.TableHeader),
						varNode,
						signatureNode,
						paragraphNode,
						inlineStyles,
					],
					editorProps: {
						handlePaste: (view, ev) => {
							if (pastePictures(ev)) {
								return true;
							}
							const html = htmlSource(ev);
							if (!html) {
								return false;
							}
							editor.chain().focus().insertContent(html).run();
							return true;
						},
						// A click on a chip with a value makes it text: it will not follow the recipient any more
						handleClickOn: (view, pos, node) => {
							if (o.varToText && node.type.name === 'inboxVar' && node.attrs.value !== '') {
								editor.chain().focus().insertContentAt({ from: pos, to: pos + node.nodeSize }, node.attrs.value).run();
								return true;
							}
							return false;
						},
						attributes: { class: 'ibx-editor__content', role: 'textbox', 'aria-multiline': 'true', 'aria-label': o.label || '', spellcheck: 'true' },
					},
					onUpdate: () => fire('change'),
					onFocus: () => fire('focus'),
					onBlur: () => fire('blur'),
					onSelectionUpdate: () => fire('change', { selection: true }),
				});
				return this;
			},
			destroy() {
				if (editor) {
					editor.destroy();
					editor = null;
				}
			},
			getHtml() {
				if (!editor) {
					return '';
				}
				// The empty paragraph the editor keeps after a last block (to write after it) is not sent after a signature
				const html = editor.getHTML().replace(/(<div class="inbox-signature-edited">[\s\S]*<\/div>)(<p><\/p>)+$/, '$1');
				if (!html.includes('data-colwidth')) {
					return html;
				}
				// Width of a column chosen in the editor written as width="" too (read by every mail software, and by a
				// signature, which the server does not rewrite); data-colwidth stays for the editor reopened
				const box = new DOMParser().parseFromString('<body>' + html + '</body>', 'text/html').body;
				box.querySelectorAll('td[data-colwidth], th[data-colwidth]').forEach(c => {
					if (/^\d{1,4}$/.test(c.getAttribute('data-colwidth'))) {
						c.setAttribute('width', c.getAttribute('data-colwidth'));
					}
				});
				return box.innerHTML;
			},
			setHtml(html) {
				if (editor) {
					editor.commands.setContent(looseInDivs(html || ''), { emitUpdate: false });
				}
			},
			insertHtml(html) {
				if (editor) {
					chain().insertContent(looseInDivs(tightBlocks(html))).run();
				}
			},
			/** A picture put at the cursor (its address: https or data:) */
			insertImage(src) {
				if (editor && src) {
					chain().setImage({ src: String(src), alt: '' }).run();
				}
			},
			/**
			 * Signature changed for this mail (1.95.0): put at the end of the text, or in place of the one already there;
			 * in the history (Ctrl+Z takes it back)
			 */
			setSignature(html) {
				if (!editor) {
					return;
				}
				const content = looseInDivs('<div class="' + SIGNATURE_CLASS + '">' + tightBlocks(html) + '</div>');
				const s = findSignature();
				if (s) {
					editor.chain().insertContentAt({ from: s.pos, to: s.pos + s.node.nodeSize }, content).run();
				} else {
					editor.chain().insertContentAt(editor.state.doc.content.size, content).run();
				}
			},
			hasSignature() {
				return !!(editor && findSignature());
			},
			removeSignature() {
				const s = editor ? findSignature() : null;
				if (s) {
					// With the empty paragraph kept after it by the editor, when it ends the text
					let to = s.pos + s.node.nodeSize;
					const after = editor.state.doc.nodeAt(to);
					if (after && after.type.name === 'paragraph' && after.content.size === 0 && to + after.nodeSize === editor.state.doc.content.size) {
						to += after.nodeSize;
					}
					editor.chain().deleteRange({ from: s.pos, to }).run();
				}
			},
			/** A variable of a template put at the cursor */
			insertVariable(key, value) {
				if (editor) {
					chain().insertContent({ type: 'inboxVar', attrs: { key: String(key), value: String(value || '') } }).run();
				}
			},
			/** Values of the variables (key => text): every chip of these keys follows */
			setVariableValues(map) {
				if (!editor) {
					return;
				}
				const tr = editor.state.tr;
				let changed = false;
				editor.state.doc.descendants((node, pos) => {
					if (node.type.name === 'inboxVar' && Object.prototype.hasOwnProperty.call(map, node.attrs.key) && map[node.attrs.key] !== node.attrs.value) {
						tr.setNodeMarkup(pos, undefined, Object.assign({}, node.attrs, { value: String(map[node.attrs.key]) }));
						changed = true;
					}
				});
				if (changed) {
					editor.view.dispatch(tr);
				}
			},
			/** Variables in the text: [{key, value}] */
			variables() {
				const out = [];
				if (editor) {
					editor.state.doc.descendants(node => {
						if (node.type.name === 'inboxVar') {
							out.push({ key: node.attrs.key, value: node.attrs.value });
						}
					});
				}
				return out;
			},
			getText() {
				return editor ? editor.getText() : '';
			},
			isEmpty() {
				return !editor || editor.isEmpty;
			},
			exec(command, value) {
				if (editor && COMMANDS[command]) {
					COMMANDS[command](value);
				}
			},
			isActive(command, value) {
				if (!editor) {
					return false;
				}
				if (command === 'align') {
					return editor.isActive({ textAlign: value });
				}
				if (command === 'table') {
					return editor.isActive('table');
				}
				return !!(ACTIVE[command] && editor.isActive(ACTIVE[command]));
			},
			/** Some text is selected (not an empty cursor, not cells of a table) */
			hasSelection() {
				if (!editor || editor.state.selection.empty) {
					return false;
				}
				// Cells of a table selected (CellSelection, known by its anchor cell: class names are minified)
				return !editor.state.selection.$anchorCell && editor.state.doc.textBetween(editor.state.selection.from, editor.state.selection.to, ' ').trim() !== '';
			},
			/** Box of the selection on the screen ({left, right, top, bottom}) */
			selectionBox() {
				if (!editor) {
					return null;
				}
				const { from, to } = editor.state.selection;
				const a = editor.view.coordsAtPos(from);
				const b = editor.view.coordsAtPos(to);
				return { left: Math.min(a.left, b.left), right: Math.max(a.right, b.right), top: Math.min(a.top, b.top), bottom: Math.max(a.bottom, b.bottom) };
			},
			/** Cursor put under the pointer, unless the pointer is in the selection (right click on a selected word) */
			placeAt(x, y) {
				if (!editor) {
					return;
				}
				const hit = editor.view.posAtCoords({ left: x, top: y });
				const { from, to } = editor.state.selection;
				if (hit && (hit.pos < from || hit.pos > to)) {
					editor.commands.setTextSelection(hit.pos);
				}
			},
			/** Colour of the text or of its background at the cursor ('' when none) */
			styleOf(kind) {
				if (!editor) {
					return '';
				}
				const a = editor.getAttributes('textStyle');
				return (kind === 'background' ? a.backgroundColor : a.color) || '';
			},
			linkAt() {
				return editor ? (editor.getAttributes('link').href || '') : '';
			},
			/** Focus back in the text: the cursor stays where it was, unless « start » or « end » is asked */
			focus(where) {
				if (editor) {
					editor.commands.focus(where || null);
				}
			},
			on(ev, fn) {
				if (listeners[ev]) {
					listeners[ev].push(fn);
				}
			},
			tableStyle, tableInfo, tableProps, cellInfo, cellProps, imageInfo, imageProps, imageBox,
			/**
			 * Plain text at the cursor, never read as HTML (voice typing: « c'est » stays « c'est », « <b> » stays text);
			 * spaced: a space first when the cursor follows a word (dictated sentences one after the other)
			 */
			insertText(text, spaced) {
				if (!editor || !text) {
					return;
				}
				let value = String(text);
				if (spaced) {
					const { from } = editor.state.selection;
					const before = from > 0 ? editor.state.doc.textBetween(Math.max(0, from - 1), from, '\n', '\n') : '';
					if (before !== '' && !/[\s\u00a0]/.test(before)) {
						value = ' ' + value;
					}
				}
				chain().insertContent({ type: 'text', text: value }).run();
			},
			/** The selection: its bounds and its text ('' for a cursor) (AI of the writing, 1.27.0) */
			selectionRange() {
				if (!editor) {
					return null;
				}
				const { from, to } = editor.state.selection;
				return { from, to, text: from < to ? editor.state.doc.textBetween(from, to, '\n') : '' };
			},
			/** HTML put in place of a range (null: the whole text), in the history (Ctrl+Z takes it back) */
			replaceRange(from, to, html) {
				if (!editor) {
					return;
				}
				html = tightBlocks(html);
				if (from === null || from === undefined) {
					editor.chain().focus().selectAll().insertContent(html).run();
					return;
				}
				const size = editor.state.doc.content.size;
				const a = Math.max(0, Math.min(+from, size));
				const b = Math.max(a, Math.min(+to, size));
				editor.chain().focus().insertContentAt({ from: a, to: b }, html).run();
			},
			/** The Tiptap editor itself: for a module that adds to it, and for the tests of dev/editor-tests */
			raw() {
				return editor;
			},
			getCapabilities() {
				return { tables: true, images: true, headings: true, colors: true, align: true, variables: true, nativeToolbar: false };
			},
		};
	});

	// ---------- CKEditor of Dolibarr (1.94.0): the editor of the core, chosen in Settings > General ----------
	let ckLoading = null;
	/** Pictures pasted larger than this are refused (the same limit as the Tiptap editor) */
	const CK_MAX_PICTURE = 5 * 1048576;
	const CK_LINE = '#d0d5dd';
	const CK_STRIPE = '#f6f8fa';
	const CK_HEAD = '#eef2f7';

	/** A colour as #rrggbb when the browser gives rgb() ('' when none, as it is when another form) */
	function hexColor(v) {
		const m = /^rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*(?:,\s*([\d.]+)\s*)?\)$/i.exec(String(v || '').trim());
		if (!m) {
			return String(v || '').trim();
		}
		if (m[4] !== undefined && +m[4] === 0) {
			return '';
		}
		return '#' + [m[1], m[2], m[3]].map(n => (+n).toString(16).padStart(2, '0')).join('');
	}
	/**
	 * Style attribute changed as written (never through el.style, which splits text-decoration into longhands Outlook
	 * does not read): props {name: value}, null or '' removes it
	 */
	function setStyles(el, props) {
		const map = new Map(declarations(el.getAttribute('style')));
		Object.keys(props).forEach(k => {
			map.delete(k);
			if (props[k] !== null && props[k] !== undefined && props[k] !== '') {
				map.set(k, String(props[k]));
			}
		});
		if (map.size) {
			el.setAttribute('style', Array.from(map, d => d[0] + ':' + d[1]).join(';'));
		} else {
			el.removeAttribute('style');
		}
	}

	/** Variables of the templates as widgets: the same chip as with Tiptap, read from and written as span[data-inbox-var] */
	function defineCkPlugins(CK) {
		if (CK.plugins.registered.inboxvar) {
			return;
		}
		// A chip without value is an empty span: CKEditor would drop it
		delete CK.dtd.$removeEmpty.span;
		CK.plugins.add('inboxvar', {
			requires: 'widget',
			init(editor) {
				editor.widgets.add('inboxvar', {
					upcast: el => el.name === 'span' && el.attributes['data-inbox-var'] !== undefined,
					init() {
						this.setData({ key: this.element.getAttribute('data-inbox-var') || '', value: this.element.getText() });
					},
					data() {
						this.element.setAttribute('data-inbox-var', this.data.key);
						this.element.setText(this.data.value);
						this.element.addClass('ibx-var');
						if (this.data.value === '') {
							this.element.addClass('is-empty');
						} else {
							this.element.removeClass('is-empty');
						}
					},
					downcast(el) {
						el.attributes = { 'data-inbox-var': this.data.key, class: 'ibx-var' + (this.data.value === '' ? ' is-empty' : '') };
						el.setHtml(CK.tools.htmlEncode(this.data.value));
						return el;
					},
				});
			},
		});
		// The chips inside the frame of the classic editor (toolbar of CKEditor): the page styles do not reach it
		CK.addCss('.ibx-var{display:inline-block;padding:0 .3em;border-radius:3px;background:#f3e6ef;box-shadow:inset 0 0 0 1px #d6b3cb;cursor:pointer}'
			+ '.ibx-var.is-empty{background:#fff4d6;box-shadow:inset 0 0 0 1px #e8c46a;color:#8a5a00}'
			+ '.ibx-var.is-empty::before{content:attr(data-inbox-var);font-family:ui-monospace,monospace;font-size:.85em}');
	}

	/**
	 * CKEditor puts its panels (colours, styles, context menu) and its dialogs in the body: under a modal dialog of
	 * inbox (top layer: signature, template) they would stay behind it. While such a dialog holds a CKEditor with its
	 * own toolbar, the body CKEditor asks for is that dialog (CKEditor places its panels from it, css lets them out).
	 */
	function bridgeTopLayer(CK) {
		if (CK.inboxTopLayer) {
			return;
		}
		CK.inboxTopLayer = true;
		const getBody = CK.document.getBody;
		CK.document.getBody = function () {
			const open = Array.from(document.querySelectorAll('dialog[open]')).filter(d => d.matches(':modal') && d.querySelector('.ibx-editor--cknative'));
			return open.length ? new CK.dom.element(open[open.length - 1]) : getBody.call(this);
		};
	}

	/** CKEditor of the core, once, with the nonce of the page (its CSP) */
	function loadCkeditor() {
		if (window.CKEDITOR && window.CKEDITOR.inline) {
			defineCkPlugins(window.CKEDITOR);
			bridgeTopLayer(window.CKEDITOR);
			return Promise.resolve(window.CKEDITOR);
		}
		if (!ckLoading) {
			ckLoading = new Promise((resolve, reject) => {
				const base = I.ctx('INBOX_CKEDITOR_BASEPATH', '');
				if (!base) {
					ckLoading = null;
					reject(new Error('editor'));
					return;
				}
				window.CKEDITOR_BASEPATH = base;
				const s = document.createElement('script');
				const any = document.querySelector('script[nonce]');
				if (any && any.nonce) {
					s.nonce = any.nonce;
				}
				s.src = base + 'ckeditor.js?v=' + encodeURIComponent(I.ctx('INBOX_CKEDITOR_STAMP', ''));
				s.onload = () => {
					if (!window.CKEDITOR) {
						ckLoading = null;
						reject(new Error('editor'));
						return;
					}
					// No editor made by CKEditor itself on the contenteditable of the page
					window.CKEDITOR.disableAutoInline = true;
					defineCkPlugins(window.CKEDITOR);
					bridgeTopLayer(window.CKEDITOR);
					resolve(window.CKEDITOR);
				};
				s.onerror = () => {
					ckLoading = null;
					reject(new Error('editor'));
				};
				document.head.appendChild(s);
			});
		}
		return ckLoading;
	}

	/**
	 * CKEditor adapter. Two ways, chosen in Settings > General:
	 * - toolbar of inbox (default): inline CKEditor without its own bar nor menu, driven by the toolbar, the menus and the
	 *   bubbles of inbox, as Tiptap;
	 * - native: the classic CKEditor of Dolibarr (its toolbar, its context menu); inbox keeps voice typing, AI and the
	 *   variables, the bubbles of inbox are not shown.
	 */
	register('ckeditor', async function () {
		// Read before the wait: a second writing opened meanwhile has its own choice
		const native = !inboxBarOnly && !!setting('INBOX_CKEDITOR_NATIVE', false);
		const CK = await loadCkeditor();
		let editor = null;
		let host = null;
		let saved = null;
		let lastSel = null;
		const listeners = { change: [], focus: [], blur: [], notice: [] };
		const fire = (ev, arg) => listeners[ev].forEach(fn => fn(arg));
		const editable = () => (editor ? editor.editable() : null);
		const root = () => (editable() ? editable().$ : null);
		const doc = () => root().ownerDocument;
		/** First element of the path of the cursor matching the names (CKEditor element or null) */
		const pathHas = names => {
			const p = editor && editor.elementPath();
			return p ? p.contains(names) : null;
		};
		const state = name => {
			const c = editor.getCommand(name);
			return !!c && c.state === CK.TRISTATE_ON;
		};
		/** A change of the DOM in one step of the history */
		const snap = fn => {
			editor.fire('saveSnapshot');
			fn();
			editor.fire('saveSnapshot');
			editor.fire('change');
		};

		// ---------- Selection: kept when the focus leaves (toolbar, dialog), offsets in the text for the AI ----------
		const textOffset = (node, offset) => {
			const r = doc().createRange();
			r.setStart(root(), 0);
			r.setEnd(node, offset);
			return r.toString().length;
		};
		const pointAt = n => {
			const tw = doc().createTreeWalker(root(), NodeFilter.SHOW_TEXT);
			let acc = 0;
			let last = null;
			for (let t = tw.nextNode(); t; t = tw.nextNode()) {
				const len = t.nodeValue.length;
				if (acc + len >= n) {
					return { node: t, offset: n - acc };
				}
				acc += len;
				last = t;
			}
			return last ? { node: last, offset: last.nodeValue.length } : { node: root(), offset: root().childNodes.length };
		};
		const nativeRange = () => {
			const s = root() ? doc().getSelection() : null;
			if (!s || !s.rangeCount) {
				return null;
			}
			const r = s.getRangeAt(0);
			return root().contains(r.commonAncestorContainer) ? r : null;
		};
		/** Where the cursor is now: kept to come back to it and for the AI */
		const capture = () => {
			if (!editor || !editor.focusManager.hasFocus) {
				return;
			}
			const sel = editor.getSelection();
			if (sel) {
				try {
					saved = sel.createBookmarks2(true);
				} catch (e) {
					saved = null;
				}
			}
			const r = nativeRange();
			if (r) {
				lastSel = { from: textOffset(r.startContainer, r.startOffset), to: textOffset(r.endContainer, r.endOffset), text: r.toString() };
			}
		};
		/** Focus back in the text, at the place it had (a button or a dialog took it) */
		const ensureFocus = () => {
			if (editor.focusManager.hasFocus && nativeRange()) {
				return;
			}
			editor.focus();
			if (saved) {
				try {
					editor.getSelection().selectBookmarks(saved);
				} catch (e) {
					// The text changed since: the cursor stays where the browser put it
				}
			}
		};
		const selectOffsets = (from, to) => {
			const a = pointAt(from);
			const b = pointAt(to);
			const r = editor.createRange();
			r.setStart(new CK.dom.node(a.node), a.offset);
			r.setEnd(new CK.dom.node(b.node), b.offset);
			r.select();
		};

		// ---------- Formatting ----------
		const blockTo = tag => editor.applyStyle(new CK.style({ element: tag }));
		const colorStyle = (prop, v) => {
			const def = { element: 'span', styles: { [prop]: '#(c)' }, overrides: [{ element: 'font', attributes: { color: null } }] };
			editor.removeStyle(new CK.style(def, { c: 'inherit' }));
			if (v) {
				editor.applyStyle(new CK.style(def, { c: v }));
			}
		};
		/** Link at the cursor or around the selection (a selection made on the text of the link starts before it) */
		const linkEl = () => {
			const a = pathHas('a');
			if (a) {
				return a;
			}
			const sel = editor.getSelection();
			const r = sel && sel.getRanges()[0];
			if (!r) {
				return null;
			}
			const s2 = r.clone();
			s2.shrink(CK.SHRINK_TEXT);
			const n = s2.startContainer;
			const el = n.type === CK.NODE_TEXT ? n.getParent() : n;
			return el && el.getAscendant ? el.getAscendant('a', true) : null;
		};
		const setLink = v => {
			const a = linkEl();
			const sel = editor.getSelection();
			const ranges = sel ? sel.getRanges() : [];
			const collapsed = !ranges.length || ranges[0].collapsed;
			if (!v) {
				if (a && collapsed) {
					tableChange(() => a.remove(true));
				} else {
					editor.removeStyle(new CK.style({ element: 'a', type: CK.STYLE_INLINE, alwaysRemoveElement: 1 }));
				}
				return;
			}
			if (a) {
				tableChange(() => {
					a.setAttribute('href', v);
					a.data('cke-saved-href', v);
				});
			} else if (!collapsed) {
				// Inline: without the type, CKEditor takes <a> for an object style and applies nothing
				editor.applyStyle(new CK.style({ element: 'a', type: CK.STYLE_INLINE, attributes: { href: v, 'data-cke-saved-href': v } }));
			} else {
				const el = editor.document.createElement('a');
				el.setAttribute('href', v);
				el.data('cke-saved-href', v);
				el.setText(v.replace(/^mailto:/i, ''));
				editor.insertElement(el);
			}
		};

		// ---------- Tables: styles written inline, as the Tiptap editor writes them ----------
		const px = v => (parseInt(v, 10) || null);
		const sides = f => ({ all: 'trbl', h: 'b', none: '' }[f] !== undefined ? { all: 'trbl', h: 'b', none: '' }[f] : String(f || ''));
		const tableEl = () => {
			const t = pathHas('table');
			return t ? t.$ : null;
		};
		const rowsOf = t => Array.from(t.rows).filter(r => r.closest('table') === t);
		const readFrame = c => {
			if (!(c.getAttribute('style') || '').includes('border')) {
				return null;
			}
			const st = c.style;
			const has = side => st['border' + side + 'Style'] && st['border' + side + 'Style'] !== 'none' && st['border' + side + 'Width'] !== '0px';
			return (has('Top') ? 't' : '') + (has('Right') ? 'r' : '') + (has('Bottom') ? 'b' : '') + (has('Left') ? 'l' : '');
		};
		const readCell = c => ({
			frame: readFrame(c),
			borderWidth: px(c.style.borderBottomWidth) || px(c.style.borderTopWidth),
			borderColor: hexColor(c.style.borderBottomColor || c.style.borderTopColor) || null,
			padding: px(c.style.paddingTop),
			background: hexColor(c.style.backgroundColor) || null,
			textAlign: c.style.textAlign || null,
			verticalAlign: c.style.verticalAlign || null,
			header: c.tagName === 'TH',
		});
		/** A cell made th or td (the element replaced, its content and attributes kept) */
		const cellType = (c, header) => {
			if ((c.tagName === 'TH') === !!header) {
				return c;
			}
			const el = new CK.dom.element(c);
			el.renameNode(header ? 'th' : 'td');
			return el.$;
		};
		const writeCell = (c, a) => {
			const frame = a.frame !== undefined ? a.frame : undefined;
			if (frame !== undefined && frame !== null) {
				const f = sides(frame);
				const line = (a.borderWidth || 1) + 'px solid ' + (a.borderColor || CK_LINE);
				setStyles(c, { 'border-top': f.includes('t') ? line : '0', 'border-right': f.includes('r') ? line : '0', 'border-bottom': f.includes('b') ? line : '0', 'border-left': f.includes('l') ? line : '0' });
			}
			if (a.padding !== undefined) {
				setStyles(c, { padding: a.padding ? a.padding + 'px' : null, 'padding-top': null, 'padding-right': null, 'padding-bottom': null, 'padding-left': null });
			}
			if (a.background !== undefined) {
				setStyles(c, { 'background-color': a.background || null, background: null });
			}
			if (a.textAlign !== undefined) {
				setStyles(c, { 'text-align': a.textAlign && a.textAlign !== 'left' ? a.textAlign : null });
			}
			if (a.verticalAlign !== undefined) {
				setStyles(c, { 'vertical-align': a.verticalAlign || null });
			}
		};
		/**
		 * A change of the table in one step of the history, the cursor put back in the same cell after (a cell made th
		 * or td is a new element: the selection of the browser would be lost)
		 */
		const tableChange = fn => {
			const t = tableEl();
			const c = pathHas(['td', 'th']);
			const at = c ? [c.$.parentNode.rowIndex, c.$.cellIndex] : null;
			snap(fn);
			const row = at && t && t.rows[at[0]];
			const cell = row && row.cells[at[1]];
			if (cell) {
				const r = editor.createRange();
				r.moveToElementEditStart(new CK.dom.element(cell));
				r.select();
			}
		};
		const selectedCells = () => {
			const tt = CK.plugins.tabletools;
			let cells = [];
			if (tt && tt.getSelectedCells) {
				try {
					cells = tt.getSelectedCells(editor.getSelection()).map(c => c.$);
				} catch (e) {
					cells = [];
				}
			}
			if (!cells.length) {
				const c = pathHas(['td', 'th']);
				if (c) {
					cells = [c.$];
				}
			}
			return cells;
		};
		const tableStyle = preset => {
			const t = tableEl();
			if (!t) {
				return;
			}
			tableChange(() => {
				rowsOf(t).forEach((row, rIdx) => {
					Array.from(row.cells).forEach(cell => {
						if (preset === 'header') {
							cell = cellType(cell, rIdx === 0);
							writeCell(cell, { frame: 'trbl', borderWidth: 1, borderColor: CK_LINE, padding: 6, background: rIdx === 0 ? CK_HEAD : null });
							return;
						}
						const keepHead = cell.tagName === 'TH' && hexColor(cell.style.backgroundColor) === CK_HEAD;
						writeCell(cell, { frame: preset === 'grid' ? 'trbl' : (preset === 'lines' ? 'b' : ''), borderWidth: 1, borderColor: CK_LINE, padding: preset === 'none' ? 4 : 6, background: keepHead ? CK_HEAD : null });
					});
				});
			});
		};
		const tableInfo = () => {
			const t = tableEl();
			if (!t) {
				return null;
			}
			const rows = rowsOf(t);
			const bodyRows = rows.filter(r => r.cells[0] && r.cells[0].tagName === 'TD');
			const body = bodyRows[0] || rows[0];
			const a = body && body.cells[0] ? readCell(body.cells[0]) : readCell(t);
			const odd = bodyRows[1];
			return {
				frame: ({ trbl: 'all', b: 'h', '': 'none' })[a.frame === null ? 'trbl' : sides(a.frame)] || 'all', borderWidth: a.borderWidth || 1, borderColor: a.borderColor || CK_LINE, padding: a.padding || 6,
				stripes: !!(odd && hexColor(odd.cells[0].style.backgroundColor) === CK_STRIPE),
				width: t.style.width === '100%' || t.getAttribute('width') === '100%' ? 'full' : 'auto',
				align: t.getAttribute('align') === 'center' || t.style.marginLeft === 'auto' ? 'center' : 'left',
			};
		};
		const tableProps = p => {
			const t = tableEl();
			if (!t) {
				return;
			}
			tableChange(() => {
				let body = -1;
				rowsOf(t).forEach(row => {
					const isBody = row.cells[0] && row.cells[0].tagName === 'TD';
					if (isBody) {
						body++;
					}
					Array.from(row.cells).forEach(cell => {
						writeCell(cell, { frame: p.frame, borderWidth: p.borderWidth, borderColor: p.borderColor, padding: p.padding });
						if (cell.tagName === 'TH') {
							return;
						}
						const bg = hexColor(cell.style.backgroundColor);
						if (p.stripes && body % 2 === 1) {
							writeCell(cell, { background: CK_STRIPE });
						} else if (bg === CK_STRIPE) {
							writeCell(cell, { background: null });
						}
					});
				});
				if (p.width === 'full') {
					setStyles(t, { width: '100%' });
					t.setAttribute('width', '100%');
				} else {
					setStyles(t, { width: t.style.width === '100%' ? null : t.style.width || null });
					if (t.getAttribute('width') === '100%') {
						t.removeAttribute('width');
					}
				}
				if (p.align === 'center') {
					setStyles(t, { 'margin-left': 'auto', 'margin-right': 'auto' });
					t.setAttribute('align', 'center');
				} else {
					setStyles(t, { 'margin-left': null, 'margin-right': null });
					t.removeAttribute('align');
				}
			});
		};
		const cellInfo = () => {
			const c = selectedCells()[0];
			if (!c) {
				return { frame: 'trbl', header: false };
			}
			const a = readCell(c);
			a.frame = a.frame === null ? 'trbl' : a.frame;
			return a;
		};
		const cellProps = p => {
			const cells = selectedCells();
			if (!cells.length) {
				return;
			}
			tableChange(() => {
				cells.forEach(c => {
					if (p.header !== undefined) {
						c = cellType(c, p.header);
					}
					const cur = readCell(c);
					const a = Object.assign({}, p);
					delete a.header;
					if (a.frame !== undefined) {
						a.borderWidth = a.borderWidth || cur.borderWidth;
						a.borderColor = a.borderColor || cur.borderColor;
					}
					writeCell(c, a);
				});
			});
		};
		const toggleHeader = kind => {
			const t = tableEl();
			if (!t) {
				return;
			}
			const rows = rowsOf(t);
			const cells = kind === 'row' ? Array.from((rows[0] || { cells: [] }).cells) : rows.map(r => r.cells[0]).filter(Boolean);
			const allHead = cells.length && cells.every(c => c.tagName === 'TH');
			tableChange(() => cells.forEach(c => cellType(c, !allHead)));
		};
		const mergeOrSplit = () => {
			const cells = selectedCells();
			if (cells.length > 1) {
				editor.execCommand('cellMerge');
			} else if (cells[0] && cells[0].colSpan > 1) {
				editor.execCommand('cellVerticalSplit');
			} else if (cells[0] && cells[0].rowSpan > 1) {
				editor.execCommand('cellHorizontalSplit');
			}
		};

		// ---------- Pictures ----------
		const currentImage = () => {
			const sel = editor && editor.getSelection();
			const el = sel && sel.getSelectedElement();
			return el && el.is('img') && !el.hasClass('cke_widget_drag_handler') ? el : null;
		};
		const imageInfo = () => {
			const el = native ? null : currentImage();
			if (!el) {
				return null;
			}
			return { alt: el.getAttribute('alt') || '', width: +el.getAttribute('width') || el.$.offsetWidth, naturalWidth: el.$.naturalWidth, naturalHeight: el.$.naturalHeight };
		};
		const imageProps = p => {
			const el = currentImage();
			if (!el) {
				return;
			}
			snap(() => {
				if (p.width !== undefined) {
					const w = Math.round(+p.width || 0);
					const ratio = el.$.naturalWidth ? el.$.naturalHeight / el.$.naturalWidth : 0;
					if (w > 0) {
						el.setAttribute('width', w);
						if (ratio) {
							el.setAttribute('height', Math.round(w * ratio));
						}
					} else {
						el.removeAttribute('width');
						el.removeAttribute('height');
					}
					setStyles(el.$, { width: null, height: null });
				}
				if (p.alt !== undefined) {
					el.setAttribute('alt', String(p.alt).trim());
				}
			});
			editor.getSelection().selectElement(el);
		};
		const imageBox = () => {
			const el = currentImage();
			return el ? el.$.getBoundingClientRect() : null;
		};

		const cmd = name => () => editor.execCommand(name);
		const COMMANDS = {
			bold: cmd('bold'),
			italic: cmd('italic'),
			underline: cmd('underline'),
			strike: cmd('strike'),
			bulletList: cmd('bulletedlist'),
			orderedList: cmd('numberedlist'),
			quote: cmd('blockquote'),
			heading: v => blockTo(pathHas('h' + (v || 3)) ? 'p' : 'h' + (v || 3)),
			clearFormat: () => {
				editor.execCommand('removeFormat');
				if (pathHas(['h1', 'h2', 'h3', 'h4', 'h5', 'h6'])) {
					blockTo('p');
				}
			},
			undo: cmd('undo'),
			redo: cmd('redo'),
			link: v => setLink(v),
			unlink: () => setLink(''),
			color: v => colorStyle('color', v),
			background: v => colorStyle('background-color', v),
			align: v => editor.execCommand('justify' + (v || 'left')),
			tableInsert: () => {
				// 3 x 3, the cursor in its first cell (as the Tiptap editor); the grid is drawn by the server
				const table = CK.dom.element.createFromHtml('<table><tbody>' + '<tr><td><br></td><td><br></td><td><br></td></tr>'.repeat(3) + '</tbody></table>', editor.document);
				editor.insertElement(table);
				const r = editor.createRange();
				r.moveToElementEditStart(table.findOne('td'));
				r.select();
			},
			tableRow: cmd('rowInsertAfter'),
			tableColumn: cmd('columnInsertAfter'),
			tableRowDelete: cmd('rowDelete'),
			tableColumnDelete: cmd('columnDelete'),
			tableDelete: cmd('tableDelete'),
			tableRowBefore: cmd('rowInsertBefore'),
			tableColumnBefore: cmd('columnInsertBefore'),
			tableMerge: mergeOrSplit,
			tableHeader: () => toggleHeader('row'),
			tableHeaderColumn: () => toggleHeader('column'),
			imageDelete: () => {
				const el = currentImage();
				if (el) {
					snap(() => el.remove());
				}
			},
		};

		/** Config of the editor: no filter (the server cleans every HTML), paragraphs as in the Tiptap editor */
		const config = o => {
			const c = {
				customConfig: native ? I.ctx('INBOX_CKEDITOR_CONFIG', '') : '',
				language: I.ctx('INBOX_CKEDITOR_LANG', 'en'),
				versionCheck: false,
				allowedContent: true,
				enterMode: CK.ENTER_P,
				shiftEnterMode: CK.ENTER_BR,
				extraPlugins: 'inboxvar',
				// Upload of pictures: none (pasted ones go in as data:, sent as parts of the mail); spelling: the browser's
				removePlugins: 'elementspath,save,flash,div,anchor,exportpdf,wsc,scayt,uploadimage,uploadwidget,newpage,templates,forms,iframe,smiley,print,language,bidi'
					+ (native ? '' : ',floatingspace,resize,maximize,preview,sourcearea,sourcedialog,showblocks,find'),
				disableNativeSpellChecker: false,
				entities: false,
				entities_latin: false,
				editorplaceholder: o.placeholder || '',
				title: o.label || '',
				clipboard_handleImages: true,
			};
			if (native) {
				c.toolbar = I.ctx('INBOX_CKEDITOR_TOOLBAR', 'dolibarr_mailings');
				c.skin = I.ctx('INBOX_CKEDITOR_SKIN', 'moono-lisa');
				c.resize_enabled = false;
				c.height = o.height || 260;
				c.toolbarCanCollapse = false;
			} else {
				// The menus of inbox (and of the browser with Shift: spelling); no dialog of CKEditor either
				c.enableContextMenu = false;
				c.toolbar = [];
			}
			return c;
		};

		return {
			name: 'ckeditor',
			create(el, o) {
				o = o || {};
				host = el;
				host.classList.add('ibx-editor--ckeditor');
				if (native) {
					host.classList.add('ibx-editor--cknative');
				}
				return new Promise((resolve, reject) => {
					const done = () => {
						clearTimeout(timer);
						resolve(this);
					};
					const timer = setTimeout(() => reject(new Error('editor')), 20000);
					const cfg = config(o);
					cfg.on = {
						instanceReady: () => {
							// Toolbar of CKEditor: the tools of inbox kept (voice typing, AI, variables) as its last group;
							// the element itself is moved, its listeners, states and menus go with it
							const top = native && o.nativeTools ? editor.ui.space('top') : null;
							if (top) {
								const group = document.createElement('span');
								group.className = 'cke_toolbar ibx-cke-tools';
								group.setAttribute('role', 'presentation');
								o.nativeTools.classList.add('ibx-tools--native');
								group.appendChild(o.nativeTools);
								(top.$.querySelector('.cke_toolbox') || top.$).appendChild(group);
							}
							// The HTML set once the editor is ready: CKEditor protects it (events, scripts) before it is shown
							editor.setData(o.html || '', { callback: () => {
								editor.resetUndo();
								done();
							} });
						},
					};
					if (native) {
						editor = CK.appendTo(el, cfg);
					} else {
						const box = document.createElement('div');
						box.className = 'ibx-editor__content';
						box.setAttribute('contenteditable', 'true');
						box.setAttribute('role', 'textbox');
						box.setAttribute('aria-multiline', 'true');
						box.setAttribute('aria-label', o.label || '');
						box.setAttribute('spellcheck', 'true');
						el.appendChild(box);
						editor = CK.inline(box, cfg);
					}
					if (!editor) {
						clearTimeout(timer);
						reject(new Error('editor'));
						return;
					}
					editor.on('change', () => fire('change'));
					editor.on('selectionChange', () => {
						capture();
						fire('change', { selection: true });
					});
					editor.on('focus', () => fire('focus'));
					editor.on('blur', () => {
						capture();
						fire('blur');
					});
					// Pictures too big refused; HTML source (an answer of an AI) put in formatted
					editor.on('paste', ev => {
						const dt = ev.data.dataTransfer;
						if (dt && dt.getFilesCount()) {
							let big = false;
							for (let i = 0; i < dt.getFilesCount(); i++) {
								const f = dt.getFile(i);
								if (f && f.size > CK_MAX_PICTURE) {
									fire('notice', { code: 'picture_too_big', name: f.name });
									big = true;
								}
							}
							if (big) {
								ev.cancel();
								return;
							}
						}
						const html = dt ? htmlSourceOf(dt.getData('text/plain'), dt.getData('text/html')) : '';
						if (html) {
							ev.data.dataValue = tightBlocks(html);
							ev.data.type = 'html';
						}
					}, null, null, 1);
					if (!native) {
						// Double click on a link, a picture, a table: no dialog of CKEditor (the bars of inbox do it)
						editor.on('doubleclick', ev => ev.cancel(), null, null, 1);
						editor.setKeystroke(CK.CTRL + 76, false);
					}
					editor.on('contentDom', () => {
						const ed = editor.editable();
						// Frame of the page of CKEditor: the signature changed for this mail shown as in the editor of inbox
						if (native) {
							const st = editor.document.$.createElement('style');
							st.textContent = 'div.' + SIGNATURE_CLASS + '{margin-top:8px;padding:6px 8px;border:1px dashed #c8ccd2;border-radius:4px}'
								+ 'div.' + SIGNATURE_CLASS + '::before{content:' + JSON.stringify(o.signatureLabel || '') + ';display:block;margin-bottom:4px;font:11px sans-serif;color:#76767e}';
							editor.document.$.head.appendChild(st);
						}
						ed.attachListener(ed, 'keyup', capture);
						ed.attachListener(ed, 'mouseup', () => setTimeout(capture, 0));
						// A click on a chip with a value makes it text: it will not follow the recipient any more
						ed.attachListener(ed, 'click', ev => {
							if (!o.varToText) {
								return;
							}
							const target = ev.data.getTarget();
							const chip = target && target.$ && target.$.closest ? target.$.closest('[data-inbox-var]') : null;
							const w = chip ? editor.widgets.getByElement(new CK.dom.element(chip)) : null;
							if (w && w.data.value !== '') {
								const r = editor.createRange();
								r.setStartBefore(w.wrapper);
								r.setEndAfter(w.wrapper);
								r.select();
								editor.insertText(w.data.value);
							}
						});
					});
				});
			},
			destroy() {
				if (editor) {
					try {
						editor.destroy();
					} catch (e) {
						// Already gone with its window
					}
					editor = null;
				}
				if (host) {
					host.replaceChildren();
				}
			},
			getHtml() {
				return editor ? editor.getData() : '';
			},
			setHtml(html) {
				if (editor) {
					editor.setData(html || '');
				}
			},
			insertHtml(html) {
				if (editor) {
					ensureFocus();
					editor.insertHtml(tightBlocks(html));
				}
			},
			insertImage(src) {
				if (editor && src) {
					ensureFocus();
					const img = editor.document.createElement('img');
					img.setAttribute('src', String(src));
					img.data('cke-saved-src', String(src));
					img.setAttribute('alt', '');
					editor.insertElement(img);
				}
			},
			/** Signature changed for this mail (1.95.0): at the end of the text, or in place of the one there */
			setSignature(html) {
				if (!editor || !editable()) {
					return;
				}
				// Through the data processor: CKEditor protects the HTML (events, addresses) as for setData
				const inner = editor.dataProcessor.toHtml(tightBlocks(html));
				snap(() => {
					const el = root().querySelector('div.' + SIGNATURE_CLASS);
					if (el) {
						el.innerHTML = inner;
					} else {
						const div = editor.document.createElement('div');
						div.addClass(SIGNATURE_CLASS);
						div.setHtml(inner);
						editable().append(div);
					}
				});
			},
			hasSignature() {
				return !!(root() && root().querySelector('div.' + SIGNATURE_CLASS));
			},
			removeSignature() {
				const el = root() ? root().querySelector('div.' + SIGNATURE_CLASS) : null;
				if (el) {
					snap(() => el.remove());
				}
			},
			/** A variable of a template put at the cursor */
			insertVariable(key, value) {
				if (!editor) {
					return;
				}
				ensureFocus();
				const span = editor.document.createElement('span');
				span.setAttribute('data-inbox-var', String(key));
				span.addClass('ibx-var');
				span.setText(String(value || ''));
				editor.insertElement(span);
				editor.widgets.initOn(span, 'inboxvar');
			},
			setVariableValues(map) {
				if (!editor) {
					return;
				}
				Object.values(editor.widgets.instances).forEach(w => {
					if (w.name === 'inboxvar' && Object.prototype.hasOwnProperty.call(map, w.data.key) && String(map[w.data.key]) !== w.data.value) {
						w.setData('value', String(map[w.data.key]));
					}
				});
			},
			variables() {
				if (!editor || !root()) {
					return [];
				}
				return Array.from(root().querySelectorAll('[data-inbox-var]')).map(el => ({ key: el.getAttribute('data-inbox-var') || '', value: el.textContent || '' }));
			},
			getText() {
				const r = root();
				return r ? (r.innerText || r.textContent || '').replace(/\u200b/g, '') : '';
			},
			isEmpty() {
				const r = root();
				return !r || (r.textContent.replace(/[\s\u00a0\u200b]/g, '') === '' && !r.querySelector('img:not(.cke_widget_drag_handler), table, hr, [data-inbox-var]'));
			},
			exec(command, value) {
				if (editor && COMMANDS[command]) {
					ensureFocus();
					COMMANDS[command](value);
				}
			},
			isActive(command, value) {
				if (!editor || !editable()) {
					return false;
				}
				switch (command) {
					case 'bold':
					case 'italic':
					case 'underline':
					case 'strike':
						return state(command);
					case 'bulletList':
						return state('bulletedlist');
					case 'orderedList':
						return state('numberedlist');
					case 'quote':
						return state('blockquote');
					case 'link':
						return !!linkEl();
					case 'heading':
						return !!pathHas(['h2', 'h3', 'h4']);
					case 'table':
						return !!pathHas('table');
					case 'align':
						return state('justify' + (value || 'left'));
					default:
						return false;
				}
			},
			hasSelection() {
				if (native || !editor || !editor.focusManager.hasFocus) {
					return false;
				}
				const sel = editor.getSelection();
				const r = nativeRange();
				return !!(r && !r.collapsed && !(sel && sel.isFake) && r.toString().trim() !== '');
			},
			selectionBox() {
				const r = nativeRange();
				if (!r) {
					return null;
				}
				let b = r.getBoundingClientRect();
				if (!b.width && !b.height) {
					const rects = r.getClientRects();
					b = rects.length ? rects[0] : (r.startContainer.nodeType === 1 ? r.startContainer : r.startContainer.parentNode).getBoundingClientRect();
				}
				return { left: b.left, right: b.right, top: b.top, bottom: b.bottom };
			},
			placeAt(x, y) {
				if (native || !editor) {
					return;
				}
				const d = doc();
				let at = null;
				if (d.caretRangeFromPoint) {
					at = d.caretRangeFromPoint(x, y);
				} else if (d.caretPositionFromPoint) {
					const p = d.caretPositionFromPoint(x, y);
					if (p) {
						at = d.createRange();
						at.setStart(p.offsetNode, p.offset);
					}
				}
				if (!at || !root().contains(at.startContainer)) {
					return;
				}
				const cur = nativeRange();
				if (cur && !cur.collapsed && cur.isPointInRange(at.startContainer, at.startOffset)) {
					return;
				}
				const s = d.getSelection();
				s.removeAllRanges();
				s.addRange(at);
				editor.selectionChange(true);
				capture();
			},
			styleOf(kind) {
				if (!editor) {
					return '';
				}
				const p = editor.elementPath();
				const prop = kind === 'background' ? 'backgroundColor' : 'color';
				for (const el of p ? p.elements : []) {
					if (el.equals(p.block || p.blockLimit)) {
						break;
					}
					if (el.$.style && el.$.style[prop]) {
						return hexColor(el.$.style[prop]);
					}
				}
				return '';
			},
			linkAt() {
				const a = editor ? linkEl() : null;
				return a ? (a.data('cke-saved-href') || a.getAttribute('href') || '') : '';
			},
			focus(where) {
				if (!editor) {
					return;
				}
				if (where === 'start' || where === 'end') {
					editor.focus();
					const r = editor.createRange();
					r.moveToElementEditablePosition(editor.editable(), where === 'end');
					r.select();
					capture();
					return;
				}
				ensureFocus();
			},
			on(ev, fn) {
				if (listeners[ev]) {
					listeners[ev].push(fn);
				}
			},
			tableStyle, tableInfo, tableProps, cellInfo, cellProps, imageInfo, imageProps, imageBox,
			insertText(text, spaced) {
				if (!editor || !text) {
					return;
				}
				ensureFocus();
				let value = String(text);
				if (spaced) {
					// The character before the cursor in its paragraph (the cursor may be on the element, not in a text)
					const r = nativeRange();
					const p = editor.elementPath();
					const block = p && (p.block || p.blockLimit);
					if (r && block) {
						const x = doc().createRange();
						x.setStart(block.$, 0);
						x.setEnd(r.startContainer, r.startOffset);
						const before = x.toString().slice(-1);
						if (before !== '' && !/[\s\u00a0]/.test(before)) {
							value = ' ' + value;
						}
					}
				}
				editor.insertText(value);
			},
			/** The selection: its bounds as offsets in the text and its text ('' for a cursor) (AI of the writing) */
			selectionRange() {
				if (!editor) {
					return null;
				}
				if (editor.focusManager.hasFocus) {
					capture();
				}
				return lastSel ? { from: lastSel.from, to: lastSel.to, text: lastSel.from < lastSel.to ? lastSel.text : '' } : { from: 0, to: 0, text: '' };
			},
			/** HTML put in place of a range (null: the whole text), in the history (Ctrl+Z takes it back) */
			replaceRange(from, to, html) {
				if (!editor) {
					return;
				}
				html = tightBlocks(html);
				editor.focus();
				if (from === null || from === undefined) {
					editor.execCommand('selectAll');
				} else {
					selectOffsets(+from, Math.max(+from, +to));
				}
				editor.insertHtml(html);
			},
			/** The CKEditor instance itself: for a module that adds to it */
			raw() {
				return editor;
			},
			getCapabilities() {
				return { tables: true, images: true, headings: true, colors: true, align: true, variables: true, nativeToolbar: native };
			},
		};
	});

	/**
	 * An editor ready in el
	 * @param {HTMLElement} el
	 * @param {object} o {html, placeholder, label, varToText, signatureLabel, nativeTools: the bar of inbox put in the toolbar of CKEditor when it shows its own}
	 * @returns {Promise<object>}
	 */
	async function create(el, o) {
		const pick = pickEditor();
		const name = pick.name;
		const factory = factories[name] || factories.tiptap;
		try {
			inboxBarOnly = pick.inboxBar;
			const pending = factory();
			inboxBarOnly = false;
			const ed = await pending;
			return await ed.create(el, o);
		} catch (err) {
			if (factory === factories.tiptap) {
				throw err;
			}
			// The editor chosen does not start (CKEditor missing, blocked): the writing opens with the one of inbox
			console.warn('inbox: editor ' + name + ' failed, Tiptap used', err);
			el.replaceChildren();
			el.classList.remove('ibx-editor--ckeditor', 'ibx-editor--cknative');
			const ed = await factories.tiptap();
			return ed.create(el, o);
		}
	}

	/**
	 * Editor of a writing that opens (1.101.0): on a small screen (the phone layout of the application, under 64rem),
	 * the choice « On a small screen » of Settings > General; decided once, the editor stays if the screen turns or the
	 * window is resized (changing it while typing would lose the selection and the undo history). The width of the
	 * writing window itself is not used: the window docked on a computer is narrow too.
	 * @returns {{name: string, inboxBar: boolean}}
	 */
	function pickEditor() {
		const desktop = setting('INBOX_EDITOR', 'tiptap');
		if (!window.matchMedia('(max-width: 63.9375rem)').matches) {
			return { name: desktop, inboxBar: false };
		}
		const small = setting('INBOX_EDITOR_SMALL', 'tiptap');
		if (small === 'same') {
			return { name: desktop, inboxBar: false };
		}
		// CKEditor on a small screen: driven by the toolbar of inbox, never its own five rows of buttons
		return small === 'ckeditor' ? { name: 'ckeditor', inboxBar: true } : { name: 'tiptap', inboxBar: false };
	}

	I.editors = { register, create, choose, names: () => Object.keys(factories) };
	Dolibarr.executeHook('inbox:editor:register', { register });
})();
