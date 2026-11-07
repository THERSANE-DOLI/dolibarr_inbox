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
 *   inbox:editor:register. inbox registers « tiptap » (frozen bundle js/vendor/tiptap, loaded at the first writing).
 * - An editor: create(el, {html, placeholder, label}), destroy(), getHtml(), setHtml(html), getText(), isEmpty(),
 *   exec(command, value), isActive(command), focus(), on(event, fn) (change, focus, blur), getCapabilities().
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

	function register(name, factory) {
		factories[name] = factory;
	}

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
		const listeners = { change: [], focus: [], blur: [] };
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
		};
		// ---------- Tables: attributes written as inline styles ----------
		const px = v => (parseInt(v, 10) || null);
		/** Sides of a border: letters t r b l (the old values all, h, none read too) */
		const sides = f => ({ all: 'trbl', h: 'b', none: '' }[f] !== undefined ? { all: 'trbl', h: 'b', none: '' }[f] : String(f || ''));
		const cellAttrs = {
			background: { default: null, parseHTML: el => el.style.backgroundColor || null, renderHTML: a => (a.background ? { style: 'background-color:' + a.background } : {}) },
			// Borders side by side: letters of the sides drawn (t r b l, '' = none, null = not chosen: the grid of the
			// server); with their width and colour
			frame: {
				default: null,
				parseHTML: el => {
					if (!(el.getAttribute('style') || '').includes('border')) {
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
			borderWidth: { default: null, parseHTML: el => px(el.style.borderBottomWidth) || px(el.style.borderTopWidth), renderHTML: () => ({}) },
			borderColor: { default: null, parseHTML: el => el.style.borderBottomColor || el.style.borderTopColor || null, renderHTML: () => ({}) },
			padding: { default: null, parseHTML: el => px(el.style.paddingTop), renderHTML: a => (a.padding ? { style: 'padding:' + a.padding + 'px' } : {}) },
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
							return c && /^[\d,]+$/.test(c) ? c.split(',').map(n => parseInt(n, 10)) : null;
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

		/** Text of the clipboard that is HTML source (not a rich copy): put in formatted */
		const htmlSource = ev => {
			const dt = ev.clipboardData;
			if (!dt) {
				return '';
			}
			let text = dt.getData('text/plain') || '';
			const rich = dt.getData('text/html') || '';
			// A rich copy is pasted as it is, except a copy of a block of code (the source shown by an AI)
			if (rich && !/<(pre|code)\b/i.test(rich)) {
				return '';
			}
			const fence = text.match(/^\s*```(?:html)?[ \t]*\r?\n([\s\S]*?)\r?\n```\s*$/i);
			if (fence) {
				text = fence[1];
			}
			return /^\s*</.test(text) && /<\/(p|div|ul|ol|li|h[1-6]|table|tr|td|blockquote|strong|b|em|i|u|a|span)>/i.test(text) ? text : '';
		};
		const ACTIVE = { bold: 'bold', italic: 'italic', underline: 'underline', strike: 'strike', bulletList: 'bulletList', orderedList: 'orderedList', quote: 'blockquote', link: 'link', heading: 'heading' };
		return {
			name: 'tiptap',
			create(el, o) {
				o = o || {};
				editor = new T.Editor({
					element: el,
					content: o.html || '',
					extensions: [
						T.StarterKit.configure({
							heading: { levels: [2, 3, 4] },
							link: { openOnClick: false, autolink: true, protocols: ['mailto', 'tel'], HTMLAttributes: { rel: 'noopener noreferrer', target: null } },
						}),
						T.Image.configure({ inline: true, allowBase64: true }),
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
					],
					editorProps: {
						handlePaste: (view, ev) => {
							const html = htmlSource(ev);
							if (!html) {
								return false;
							}
							editor.chain().focus().insertContent(html).run();
							return true;
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
				return editor ? editor.getHTML() : '';
			},
			setHtml(html) {
				if (editor) {
					editor.commands.setContent(html || '', { emitUpdate: false });
				}
			},
			insertHtml(html) {
				if (editor) {
					chain().insertContent(html).run();
				}
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
			tableStyle, tableInfo, tableProps, cellInfo, cellProps,
			getCapabilities() {
				return { tables: true, images: true, headings: true, colors: true, align: true };
			},
		};
	});

	/**
	 * An editor ready in el
	 * @param {HTMLElement} el
	 * @param {object} o {html, placeholder, label}
	 * @returns {Promise<object>}
	 */
	async function create(el, o) {
		const name = I.ctx('INBOX_EDITOR', 'tiptap');
		const factory = factories[name] || factories.tiptap;
		const ed = await factory();
		return ed.create(el, o);
	}

	I.editors = { register, create, names: () => Object.keys(factories) };
	Dolibarr.executeHook('inbox:editor:register', { register });
})();
