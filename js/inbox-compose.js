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
 * Writing windows of inbox (lot W6 1.16.0, interface §8): new mail, reply, reply all, reply to the list, forward
 * (inline or as an attachment), draft reopened.
 * - Floating window at the bottom right (full screen on a phone), reduced into the dock, several side by side.
 * - The server keeps the writing (token): quote, headers of the answer, files of a forward and the signature are read
 *   there, never sent by this page. The page sends what the user typed only.
 * - Draft saved on the mail server 3 s after the last change, when the window is reduced or closed, when the tab
 *   goes to the background.
 * - Dolibarr.tools.inbox.compose({mode, mail, account, to, cc, subject}) opens one; hooks inbox:compose:open and
 *   inbox:compose:beforeSend (ctx.cancel = true and ctx.reason stop the sending).
 */
(function () {
	'use strict';

	const I = Dolibarr.tools.inbox;
	const { esc, t, th, ic, post, toast, dialog, confirmDialog, $, $$ } = I;
	const SAVE_MS = 3000;
	const params = Object.assign({}, I.ctxJson('INBOX_PARAMS_JSON', {}));
	Dolibarr.on('inbox:params:changed', p => Object.assign(params, p || {}));
	const writings = [];
	let seq = 0;

	// [command, icon, label, key, kind]: kind = split (colour tools), menu (an arrow opens a menu), toggle
	const TOOLS = [
		['undo', 'undo', 'InboxFmtUndo', 'Ctrl+Z'], ['redo', 'redo', 'InboxFmtRedo', 'Ctrl+Y'], null,
		['bold', 'fmt-bold', 'InboxFmtBold', 'Ctrl+B'], ['italic', 'fmt-italic', 'InboxFmtItalic', 'Ctrl+I'], ['underline', 'fmt-underline', 'InboxFmtUnderline', 'Ctrl+U'],
		['strike', 'fmt-strike', 'InboxFmtStrike', ''], null,
		['color', 'fmt-color', 'InboxFmtColor', '', 'split'], ['background', 'fmt-bg', 'InboxFmtBackground', '', 'split'], null,
		['align', 'fmt-align-left', 'InboxFmtAlign', '', 'menu'], ['bulletList', 'fmt-bullets', 'InboxFmtBullets', ''], ['orderedList', 'fmt-numbers', 'InboxFmtNumbers', ''],
		['quote', 'fmt-quote', 'InboxFmtQuote', ''], null,
		['link', 'link', 'InboxFmtLink', 'Ctrl+K'], ['table', 'fmt-table', 'InboxFmtTable', '', 'menu'], ['clearFormat', 'fmt-clear', 'InboxFmtClear', ''], null,
		['source', 'fmt-source', 'InboxFmtSource', '', 'toggle'], null,
		// Voice typing (1.28.0) and AI of the writing (1.27.0): shown only when allowed for the mailbox of the identity
		['dictate', 'mic', 'InboxDictate', '', 'toggle'], ['ai', 'magic', 'InboxAi', '', 'toggle'],
	];
	/** A button of the toolbar: flat icon, a small arrow when it opens a menu */
	function toolButton(x) {
		if (!x) {
			return '<span class="ibx-compose__sep" aria-hidden="true"></span>';
		}
		if (x[4] === 'split') {
			return splitButton(x);
		}
		const label = esc(t(x[2]) + (x[3] ? ' (' + x[3] + ')' : ''));
		const state = x[4] === 'menu' ? ' aria-haspopup="menu" aria-expanded="false"' : (['undo', 'redo', 'clearFormat', 'link'].includes(x[0]) ? '' : ' aria-pressed="false"');
		return '<button type="button" class="ibx-tbtn' + (x[4] === 'menu' ? ' ibx-tbtn--menu' : '') + '" data-cmd="' + x[0] + '"' + state + ' title="' + label + '" aria-label="' + esc(t(x[2])) + '">'
			+ '<span class="ibx-tbtn__icon">' + ic(x[1]) + '</span>' + (x[4] === 'menu' ? ic('chevron-down', 'ibx-tbtn__caret') : '') + '</button>';
	}
	/** Colours that every mail software shows the same (inline styles), readable on white */
	const COLORS = [['#1f2328', 'InboxColorBlack'], ['#5f6368', 'InboxColorGrey'], ['#b42318', 'InboxColorRed'], ['#b54708', 'InboxColorOrange'],
		['#027a48', 'InboxColorGreen'], ['#175cd3', 'InboxColorBlue'], ['#6941c6', 'InboxColorPurple']];
	const BACKGROUNDS = [['#fef08a', 'InboxColorYellow'], ['#bbf7d0', 'InboxColorGreen'], ['#bfdbfe', 'InboxColorBlue'], ['#fecdd3', 'InboxColorPink'], ['#e5e7eb', 'InboxColorGrey']];

	function size(n) {
		return n >= 1048576 ? (n / 1048576).toFixed(1).replace('.0', '') + ' Mo' : Math.max(1, Math.round(n / 1024)) + ' ko';
	}
	function addressText(a) {
		return a.name ? a.name + ' <' + a.email + '>' : a.email;
	}
	function validEmail(s) {
		return /^[^\s@<>(),;:"]+@[^\s@<>(),;:"]+\.[^\s@<>(),;:"]{2,}$/.test(s);
	}
	/** « Name <a@b.c>, d@e.f » => [{email, name}] */
	function parseList(text) {
		const out = [];
		String(text || '').split(/[,;\n](?=(?:[^"]*"[^"]*")*[^"]*$)/).forEach(part => {
			part = part.trim();
			if (!part) {
				return;
			}
			const m = part.match(/^(.*)<([^<>]+)>\s*$/);
			out.push(m ? { email: m[2].trim().toLowerCase(), name: m[1].trim().replace(/^["']|["']$/g, '') } : { email: part.toLowerCase(), name: '' });
		});
		return out;
	}
	/** « 2 tracking pictures taken out of the quote » (lot F1 1.78.0), nothing when none */
	function quoteRemovedHtml(r) {
		const parts = [];
		if (r && r.trackers) {
			parts.push(th('InboxQuoteTrackersRemoved', r.trackers));
		}
		if (r && r.images) {
			parts.push(th('InboxQuoteImagesRemoved', r.images));
		}
		return parts.length ? '<div class="ibx-compose__quotenote ibx-muted">' + ic('remote-blocked') + ' <span>' + parts.join(' ') + '</span></div>' : '';
	}

	function srcdoc(html, own) {
		// The quote holds the mail answered: same rules as the reading, no script, no remote picture (audit INBX-021);
		// a signature of the company (own) may show its remote logo
		const csp = own ? '' : '<meta http-equiv="Content-Security-Policy" content="default-src \'none\'; img-src data:; style-src \'unsafe-inline\'; font-src data:">';
		return '<!doctype html><html><head><meta charset="utf-8">' + csp + '<style>html{color-scheme:light}body{margin:8px;font:13px/1.45 Arial,Helvetica,sans-serif;color:#333;background:#fff;overflow-wrap:break-word}img{max-width:100%;height:auto}blockquote{margin:0 0 0 .8ex;border-left:2px solid #ccc;padding-left:1ex}</style></head><body>' + html + '</body></html>';
	}

	// ---------- Recipients field: chips + proposals (contacts, thirdparties, users, correspondents) ----------
	function recipients(w, kind, list) {
		const row = $('[data-kind="' + kind + '"]', w.dlg);
		const box = $('.ibx-rcpt', row);
		box.addEventListener('mousedown', e => {
			// A click on the empty part of the field puts the cursor in it (never on a chip)
			if (e.target === box) {
				e.preventDefault();
				input.focus();
			}
		});
		const input = $('.ibx-rcpt__input', box);
		const sug = $('.ibx-rcpt__sug', row);
		const items = list.slice();
		let found = [];
		let active = -1;
		let timer = 0;
		let qseq = 0;
		const filled = () => {
			row.toggleAttribute('data-filled', items.length > 0 || input.value !== '');
		};
		const paint = () => {
			filled();
			$$('.ibx-chip', box).forEach(c => c.remove());
			items.forEach((a, i) => {
				const c = document.createElement('span');
				const bad = !validEmail(a.email);
				c.className = 'ibx-chip' + (bad ? ' ibx-chip--bad' : '');
				c.title = a.email + (bad ? ' · ' + t('InboxComposeBadAddressOne') : '');
				c.innerHTML = '<span class="ibx-chip__label">' + esc(a.name || a.email) + '</span><button type="button" class="ibx-chip__x" data-i="' + i + '" aria-label="' + esc(t('InboxComposeRemoveRecipient', a.email)) + '">' + ic('close') + '</button>';
				box.insertBefore(c, input);
			});
		};
		const hide = () => {
			sug.hidden = true;
			sug.innerHTML = '';
			found = [];
			active = -1;
			input.setAttribute('aria-expanded', 'false');
		};
		const add = list2 => {
			list2.forEach(a => {
				if (a.email && !items.some(x => x.email === a.email)) {
					items.push(a);
				}
			});
			paint();
			w.changed();
		};
		const commit = () => {
			const v = input.value.trim();
			if (v) {
				add(parseList(v));
				input.value = '';
			}
			hide();
		};
		const showSug = () => {
			if (!found.length) {
				hide();
				return;
			}
			sug.innerHTML = found.map((f, i) => '<li role="option" id="' + w.id + '-' + kind + '-o' + i + '" class="ibx-rcpt__opt' + (i === active ? ' is-active' : '') + '" aria-selected="' + (i === active) + '" data-i="' + i + '">'
				+ '<span class="ibx-rcpt__name">' + esc(f.name || f.email) + '</span> <span class="ibx-muted">' + esc(f.email) + (f.detail ? ' · ' + esc(f.detail) : '') + '</span></li>').join('');
			sug.hidden = false;
			input.setAttribute('aria-expanded', 'true');
			input.setAttribute('aria-activedescendant', active >= 0 ? w.id + '-' + kind + '-o' + active : '');
		};
		input.addEventListener('input', () => {
			filled();
			clearTimeout(timer);
			const q = input.value.trim();
			if (/[,;]$/.test(input.value)) {
				commit();
				return;
			}
			if (q.length < 2) {
				hide();
				return;
			}
			timer = setTimeout(async () => {
				const mine = ++qseq;
				try {
					const res = await post('compose', 'recipients', { q });
					if (mine === qseq && input.value.trim() === q) {
						found = res.items || [];
						active = found.length ? 0 : -1;
						showSug();
					}
				} catch (e) {
					hide();
				}
			}, 200);
		});
		input.addEventListener('keydown', e => {
			if ((e.key === 'ArrowDown' || e.key === 'ArrowUp') && found.length) {
				e.preventDefault();
				active = (active + (e.key === 'ArrowDown' ? 1 : found.length - 1)) % found.length;
				showSug();
			} else if ((e.key === 'Enter' || e.key === 'Tab') && active >= 0 && found[active]) {
				e.preventDefault();
				add([{ email: found[active].email, name: found[active].name }]);
				input.value = '';
				hide();
			} else if (e.key === 'Enter' && input.value.trim()) {
				e.preventDefault();
				commit();
			} else if (e.key === 'Escape' && !sug.hidden) {
				e.preventDefault();
				e.stopPropagation();
				hide();
			} else if (e.key === 'Backspace' && !input.value && items.length) {
				items.pop();
				paint();
				w.changed();
			}
		});
		input.addEventListener('blur', () => setTimeout(() => {
			if (document.activeElement !== input) {
				commit();
			}
		}, 150));
		input.addEventListener('paste', e => {
			const text = (e.clipboardData || window.clipboardData).getData('text');
			if (/[,;\n]/.test(text)) {
				e.preventDefault();
				add(parseList(text));
			}
		});
		sug.addEventListener('mousedown', e => {
			const o = e.target.closest('[data-i]');
			if (o) {
				e.preventDefault();
				add([{ email: found[+o.dataset.i].email, name: found[+o.dataset.i].name }]);
				input.value = '';
				hide();
				input.focus();
			}
		});
		box.addEventListener('click', e => {
			const x = e.target.closest('.ibx-chip__x');
			if (x) {
				items.splice(+x.dataset.i, 1);
				paint();
				w.changed();
				input.focus();
			} else if (e.target === box) {
				input.focus();
			}
		});
		paint();
		return {
			get: () => {
				commit();
				return items.slice();
			},
			add,
			count: () => items.length + (input.value.trim() ? 1 : 0),
			clear: () => {
				const old = items.splice(0, items.length);
				input.value = '';
				paint();
				return old;
			},
			mark: bad => {
				$$('.ibx-chip', box).forEach((c, i) => c.classList.toggle('ibx-chip--bad', bad.includes(items[i].email) || !validEmail(items[i].email)));
			},
			focus: () => input.focus(),
		};
	}

	// ---------- The window ----------
	function titleOf(w) {
		const s = $('.ibx-js-subject', w.dlg).value.trim();
		return s || t(w.mode === 'new' || w.mode === 'draft' ? 'InboxNewMail' : (w.mode.indexOf('forward') === 0 ? 'InboxForward' : 'InboxReply'));
	}
	function identityOf(w) {
		return w.data.identities.find(i => i.id === +$('.ibx-js-from', w.dlg).value) || w.data.identities[0];
	}
	function paintSignature(w) {
		const box = $('.ibx-js-sig', w.dlg);
		const sig = identityOf(w).signature || '';
		box.hidden = !sig.trim() || !w.signature;
		$('.ibx-js-sigadd', w.dlg).hidden = !sig.trim() || w.signature;
		if (!box.hidden) {
			// No « -- » (1.53.5): only the text part of the mail writes it
			$('iframe', box).srcdoc = srcdoc(sig, true);
		}
	}
	/**
	 * Frame of the signature or of the quote at the height of its content (1.53.5): no scroll inside it, the writing
	 * scrolls as a whole. Same origin without scripts (as the reading): nothing of the content runs, the height is read
	 */
	function fitFrame(f) {
		f.addEventListener('load', () => {
			let doc;
			try {
				doc = f.contentDocument;
			} catch (e) {
				return;
			}
			if (!doc || !doc.documentElement) {
				return;
			}
			const fit = () => {
				if (!f.hidden) {
					f.style.height = Math.max(24, Math.ceil(doc.documentElement.getBoundingClientRect().height)) + 'px';
				}
			};
			fit();
			// Pictures (the logo of a signature) change the height when they arrive
			Array.from(doc.images).forEach(img => {
				if (!img.complete) {
					img.addEventListener('load', fit);
					img.addEventListener('error', fit);
				}
			});
			if (window.ResizeObserver) {
				if (f.ibxRo) {
					f.ibxRo.disconnect();
				}
				f.ibxRo = new ResizeObserver(fit);
				f.ibxRo.observe(doc.documentElement);
			}
		});
	}
	function paintAttachments(w) {
		const box = $('.ibx-js-atts', w.dlg);
		box.hidden = !w.attachments.length;
		box.innerHTML = w.attachments.map(a => '<li class="ibx-compose__att' + (a.scan === 'infected' ? ' is-bad' : '') + (a.pending ? ' is-pending' : '') + '">' + ic(a.pending ? 'send-later' : 'attachment')
			+ '<span class="ibx-ellipsis" title="' + esc(a.name) + '">' + esc(a.name) + '</span><span class="ibx-muted">' + esc(a.pending ? t('InboxComposeUploading') : size(a.size)) + '</span>'
			+ (a.pending ? '' : '<button type="button" class="ibx-btn ibx-btn--icon ibx-btn--sm ibx-js-unattach" data-id="' + esc(a.id) + '" aria-label="' + esc(t('InboxComposeRemoveFile', a.name)) + '">' + ic('close') + '</button>') + '</li>').join('');
	}
	function status(w, text, error) {
		const s = $('.ibx-js-status', w.dlg);
		s.textContent = text;
		s.classList.toggle('ibx-compose__status--error', !!error);
	}
	function front(w) {
		writings.forEach(x => x.dlg.classList.toggle('is-front', x === w));
	}
	function dockBox() {
		let d = $('.ibx-dock');
		if (!d) {
			d = document.createElement('div');
			d.className = 'ibx-dock';
			document.body.appendChild(d);
		}
		return d;
	}
	function minimize(w) {
		w.dlg.close();
		w.dock = document.createElement('button');
		w.dock.type = 'button';
		w.dock.className = 'ibx-dock__item ibx-dock__item--compose';
		w.dock.innerHTML = ic('compose') + '<span class="ibx-dock__label ibx-ellipsis">' + esc(titleOf(w)) + '</span>';
		w.dock.addEventListener('click', () => restore(w));
		dockBox().appendChild(w.dock);
		save(w);
	}
	function restore(w) {
		if (w.dock) {
			w.dock.remove();
			w.dock = null;
		}
		if (!w.dlg.open) {
			w.dlg.show();
		}
		front(w);
		w.editor.focus('end');
	}
	/** Windows side by side from the right; the older ones reduced when there is no room left */
	function savedSize() {
		let size = {};
		try {
			size = JSON.parse(params.compose_size || '{}') || {};
		} catch (e) {
			size = {};
		}
		return { w: +size.w || 560, h: +size.h || 576 };
	}
	function place() {
		const def = savedSize();
		const docked = writings.filter(w => w.dlg.open && !w.dlg.classList.contains('ibx-window--maximized') && !w.moved);
		let right = 16;
		// The newest on the right; an older one that has no room left goes to the dock
		docked.slice().reverse().forEach((w, i) => {
			const width = Math.min(w.width || def.w, window.innerWidth - 32);
			if (i > 0 && right + width > window.innerWidth - 64) {
				minimize(w);
				return;
			}
			w.dlg.style.width = width + 'px';
			w.dlg.style.height = Math.min(w.height || def.h, window.innerHeight - 16) + 'px';
			w.dlg.style.right = right + 'px';
			right += width + 12;
		});
	}

	function data(w) {
		return {
			compose: w.token,
			identity: +$('.ibx-js-from', w.dlg).value,
			to: w.to.get(),
			cc: w.cc.get(),
			bcc: w.bcc.get(),
			subject: $('.ibx-js-subject', w.dlg).value,
			html: w.sourceMode ? $('.ibx-compose__source', w.dlg).value : w.editor.getHtml(),
			quote: w.quote ? 1 : 0,
			signature: w.signature ? 1 : 0,
			receipt: w.receipt ? 1 : 0,
		};
	}
	function hasContent(w) {
		return w.to.count() + w.cc.count() + w.bcc.count() > 0 || $('.ibx-js-subject', w.dlg).value.trim() !== '' || (w.sourceMode ? $('.ibx-compose__source', w.dlg).value.trim() !== '' : !w.editor.isEmpty()) || w.attachments.length > 0;
	}

	async function save(w, force) {
		clearTimeout(w.saveTimer);
		if (w.closed || w.sending || (!w.dirty && !force) || (!hasContent(w) && !w.data.draft && !w.saved)) {
			return;
		}
		if (w.saving) {
			w.again = true;
			return;
		}
		w.saving = true;
		w.dirty = false;
		status(w, t('InboxComposeSaving'));
		try {
			await post('compose', 'save', data(w));
			w.saved = true;
			const now = new Date();
			status(w, t('InboxComposeSaved', String(now.getHours()).padStart(2, '0') + ':' + String(now.getMinutes()).padStart(2, '0')));
		} catch (err) {
			w.dirty = true;
			status(w, err.message, true);
		}
		w.saving = false;
		if (w.again) {
			w.again = false;
			save(w);
		}
	}

	function finish(w) {
		w.closed = true;
		dictateStop(w);
		clearTimeout(w.saveTimer);
		w.editor.destroy();
		w.dlg.remove();
		if (w.dock) {
			w.dock.remove();
		}
		writings.splice(writings.indexOf(w), 1);
		place();
	}

	/** Close: the draft kept on the server when something was typed */
	async function close(w) {
		if (w.dirty && hasContent(w)) {
			await save(w, true);
		}
		const empty = !hasContent(w) && !w.data.draft;
		try {
			await post('compose', 'close', { compose: w.token, discard: empty ? 1 : 0 });
		} catch (e) {
			// Already closed elsewhere: nothing to keep
		}
		if (!empty && w.saved) {
			toast(t('InboxComposeKeptInDrafts'));
		}
		finish(w);
	}

	async function discard(w) {
		if (hasContent(w) || w.data.draft) {
			const ok = await confirmDialog({ title: t('InboxComposeDiscardTitle'), text: t('InboxComposeDiscardText'), confirm: t('InboxComposeDiscard'), danger: true });
			if (!ok) {
				return;
			}
		}
		w.closed = true;
		dictateStop(w);
		try {
			await post('compose', 'close', { compose: w.token, discard: 1 });
		} catch (e) {
			// nothing to remove
		}
		finish(w);
		toast(t('InboxComposeDiscarded'));
	}

	async function send(w, opts) {
		opts = opts || {};
		if (w.sending) {
			return;
		}
		if (w.attachments.some(a => a.pending)) {
			status(w, t('InboxComposeWaitUpload'), true);
			return;
		}
		const d = data(w);
		if (!d.to.length && !d.cc.length && !d.bcc.length) {
			status(w, t('InboxComposeNoRecipient'), true);
			w.to.focus();
			return;
		}
		const bad = d.to.concat(d.cc, d.bcc).filter(a => !validEmail(a.email)).map(a => a.email);
		if (bad.length) {
			status(w, t('InboxComposeBadAddress', bad.join(', ')), true);
			[w.to, w.cc, w.bcc].forEach(r => r.mark(bad));
			return;
		}
		if (!d.subject.trim()) {
			const ok = await confirmDialog({ title: t('InboxComposeNoSubjectTitle'), text: t('InboxComposeNoSubjectText'), confirm: t('InboxComposeSendAnyway') });
			if (!ok) {
				$('.ibx-js-subject', w.dlg).focus();
				return;
			}
		}
		if (!await guards(w, d)) {
			return;
		}
		d.at = opts.at || 0;
		d.record = $$('.ibx-js-record:checked', w.dlg).map(c => c.value);
		const ctx = { writing: { mode: w.mode, source: w.source }, data: d, cancel: false, reason: '' };
		try {
			if (Dolibarr.executeHookAwait) {
				await Dolibarr.executeHookAwait('inbox:compose:beforeSend', ctx);
			} else {
				Dolibarr.executeHook('inbox:compose:beforeSend', ctx);
			}
		} catch (e) {
			ctx.cancel = true;
			ctx.reason = e.message;
		}
		if (ctx.cancel) {
			status(w, ctx.reason || t('InboxComposeRefused'), true);
			return;
		}
		w.sending = true;
		clearTimeout(w.saveTimer);
		const btn = $('.ibx-js-send', w.dlg);
		btn.setAttribute('aria-disabled', 'true');
		status(w, t('InboxComposeSending'));
		w.dlg.setAttribute('aria-busy', 'true');
		try {
			const res = await post('compose', 'send', Object.assign({}, ctx.data, opts.force ? { force: 1 } : {}));
			if (res.conflict) {
				// A colleague answered the same mail since this writing was opened (lot E2, 1.77.0): nothing sent
				w.sending = false;
				btn.removeAttribute('aria-disabled');
				w.dlg.removeAttribute('aria-busy');
				status(w, '');
				paintConflict(w, res.conflict, opts);
				return;
			}
			w.closed = true;
			dictateStop(w);
			finish(w);
			if (res.queued) {
				sendBar(res);
			} else {
				sentDone(res);
				Dolibarr.executeHook('inbox:compose:sent', { message_id: res.message_id, account: res.account, mode: w.mode, source: w.source });
			}
		} catch (err) {
			w.sending = false;
			btn.removeAttribute('aria-disabled');
			w.dlg.removeAttribute('aria-busy');
			status(w, err.message + (err.data && err.data.detail ? ' (' + err.data.detail + ')' : ''), true);
			if (err.code === 'bad_address' || err.code === 'smtp_recipient') {
				[w.to, w.cc, w.bcc].forEach(r => r.mark(String(err.data.detail || err.message).toLowerCase().match(/[^\s,()<>]+@[^\s,()<>]+/g) || []));
			}
			if (err.code === 'not_found') {
				// The writing is gone from the server (another session, purge): what was typed stays here to copy
				$('.ibx-js-send', w.dlg).setAttribute('aria-disabled', 'true');
			}
		}
	}

	/** « Paul answered at 10:42 »: see his answer, or send anyway (never blocking) */
	function paintConflict(w, c, opts) {
		const box = $('.ibx-js-conflict', w.dlg);
		const when = c.date ? new Date(c.date * 1000).toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' }) : '';
		const who = c.author_name ? t(c.queued ? 'InboxComposeConflictQueued' : 'InboxComposeConflict', c.author_name, when) : t('InboxComposeConflictOther', when);
		box.innerHTML = ic('warning') + '<span>' + esc(who) + '</span>'
			+ (c.mail ? '<button type="button" class="ibx-btn ibx-btn--sm ibx-js-conflictsee">' + th('InboxComposeConflictSee') + '</button>' : '')
			+ '<button type="button" class="ibx-btn ibx-btn--sm ibx-js-conflictsend">' + th('InboxComposeConflictSend') + '</button>';
		box.hidden = false;
		const see = $('.ibx-js-conflictsee', box);
		if (see) {
			see.addEventListener('click', () => Dolibarr.executeHook('inbox:mail:goto', { folder: c.folder, mail: c.mail }));
		}
		$('.ibx-js-conflictsend', box).addEventListener('click', () => {
			box.hidden = true;
			send(w, Object.assign({}, opts, { force: true }));
		});
		box.focus();
	}

	// ---------- Before the sending: the usual slips (lot W6 1.17.0, B16, B17, B18) ----------
	/** An answer from another mailbox than the one that got the mail: said in the window, never blocking */
	function paintBoxWarning(w) {
		const box = $('.ibx-js-boxwarn', w.dlg);
		const src = w.data.source;
		const id = identityOf(w);
		const other = src && src.account && id && id.account !== src.account && src.mailbox;
		box.hidden = !other;
		if (other) {
			box.innerHTML = ic('warning') + '<span>' + esc(t('InboxComposeOtherMailbox', id.mailbox, src.mailbox)) + '</span>';
		}
	}
	/** Attachment announced but none attached; many recipients that see each other. True: send. */
	async function guards(w, d) {
		// Variables of a template still without value: listed, sending possible after confirmation
		const empty = Array.from(new Set((w.editor.variables ? w.editor.variables() : []).filter(v => v.value === '').map(v => v.key)
			.concat((d.subject.match(/__([A-Z0-9_]+)__/g) || []).map(x => x.slice(2, -2)))));
		if (empty.length) {
			const ok = await confirmDialog({ title: t('InboxVarsEmptyTitle', empty.length), text: t('InboxVarsEmptyText', empty.join(', ')), confirm: t('InboxComposeSendAnyway') });
			if (!ok) {
				w.editor.focus();
				return false;
			}
		}
		const words = String(t('InboxAttachWords')).toLowerCase().split(',').map(x => x.trim()).filter(Boolean);
		const text = (w.sourceMode ? ($('.ibx-compose__source', w.dlg).value || '').replace(/<[^>]+>/g, ' ') : (w.editor.getText ? w.editor.getText() : '')).toLowerCase();
		if (!w.attachments.length && words.some(x => text.includes(x))) {
			const ok = await confirmDialog({ title: t('InboxGuardAttachTitle'), text: t('InboxGuardAttachText'), confirm: t('InboxComposeSendAnyway') });
			if (!ok) {
				$('.ibx-js-file', w.dlg).closest('label').focus();
				return false;
			}
		}
		const visible = d.to.length + d.cc.length;
		if (visible > 10) {
			const choice = await new Promise(resolve => {
				let done = false;
				const dlg = dialog({
					title: t('InboxGuardManyTitle', visible),
					body: '<p>' + esc(t('InboxGuardManyText', visible)) + '</p>',
					actions: [
						{ label: t('InboxGuardManyBcc'), run: () => {
							done = true;
							resolve('bcc');
						} },
						{ label: t('InboxComposeSendAnyway'), primary: true, run: () => {
							done = true;
							resolve('send');
						} },
					],
				});
				dlg.addEventListener('close', () => {
					if (!done) {
						resolve('');
					}
				});
			});
			if (!choice) {
				return false;
			}
			if (choice === 'bcc') {
				// Everyone in hidden copy, the mailbox itself as the visible recipient
				const me = identityOf(w);
				const all = w.to.clear().concat(w.cc.clear());
				w.bcc.add(all);
				w.to.add([{ email: me.email, name: me.name || '' }]);
				$('[data-kind="bcc"]', w.dlg).hidden = false;
				$('[data-show="bcc"]', w.dlg).hidden = true;
				d.to = w.to.get();
				d.cc = [];
				d.bcc = w.bcc.get();
			}
		}
		return true;
	}

	// ---------- Later: tomorrow 8:00, Monday 8:00, a date chosen ----------
	function laterMenu(w, anchor) {
		const at = (days, hour) => {
			const dt = new Date();
			dt.setDate(dt.getDate() + days);
			dt.setHours(hour, 0, 0, 0);
			return dt;
		};
		const tomorrow = at(1, 8);
		const monday = at(((8 - new Date().getDay()) % 7) || 7, 8);
		const fmt = dt => dt.toLocaleString(I.ctx('MAIN_LANG_DEFAULT', 'en_US').replace('_', '-'), { weekday: 'long', day: 'numeric', month: 'long', hour: '2-digit', minute: '2-digit' });
		popMenu(anchor, [
			{ header: t('InboxSendLater') },
			{ label: t('InboxSendTomorrow', fmt(tomorrow)), icon: 'send-later', run: () => send(w, { at: Math.floor(tomorrow.getTime() / 1000) }) },
			{ label: t('InboxSendMonday', fmt(monday)), icon: 'send-later', run: () => send(w, { at: Math.floor(monday.getTime() / 1000) }) },
			{ label: t('InboxSendChoose'), icon: 'agenda', run: () => chooseDate(w) },
		]);
	}
	function chooseDate(w) {
		const pad = n => String(n).padStart(2, '0');
		const local = dt => dt.getFullYear() + '-' + pad(dt.getMonth() + 1) + '-' + pad(dt.getDate()) + 'T' + pad(dt.getHours()) + ':' + pad(dt.getMinutes());
		const min = new Date(Date.now() + 5 * 60000);
		const start = new Date(Date.now() + 3600000);
		start.setMinutes(0, 0, 0);
		dialog({
			title: t('InboxSendChoose'),
			body: '<label class="ibx-formrow__label" for="ibx-send-at">' + th('InboxSendAt') + '</label><input type="datetime-local" class="ibx-field" id="ibx-send-at" min="' + local(min) + '" value="' + local(start) + '">',
			actions: [{ label: t('InboxSendSchedule'), primary: true, run: d => {
				const v = $('#ibx-send-at', d).value;
				const dt = v ? new Date(v) : null;
				if (!dt || isNaN(dt.getTime()) || dt.getTime() < Date.now() + 60000) {
					$('#ibx-send-at', d).focus();
					return false;
				}
				send(w, { at: Math.floor(dt.getTime() / 1000) });
			} }],
			onOpen: d => $('#ibx-send-at', d).focus(),
		});
	}

	// ---------- Bar of the sending: « Sending in 8 s · Undo · Send now · Record on PR… ? » ----------
	function sendBars() {
		let box = $('.ibx-sendbars');
		if (!box) {
			box = document.createElement('div');
			box.className = 'ibx-sendbars';
			box.setAttribute('role', 'status');
			box.setAttribute('aria-live', 'polite');
			// In the column of the toasts when there is one: stacked under them instead of drawn over them
			($('#ibx-toasts') || document.body).appendChild(box);
		}
		return box;
	}
	const bars = new Map();
	function sendBar(res) {
		const id = res.queued;
		if (bars.has(id)) {
			return;
		}
		const el = document.createElement('div');
		el.className = 'ibx-sendbar';
		sendBars().appendChild(el);
		const b = { id, el, at: res.send_at * 1000, scheduled: !!res.scheduled, pieces: res.pieces || [], recorded: (res.record || []).map(r => r.type + ':' + r.id), subject: res.subject || '', timer: 0 };
		bars.set(id, b);
		paintBar(b);
		if (!b.scheduled) {
			b.timer = setInterval(() => {
				if (Date.now() >= b.at) {
					clearInterval(b.timer);
					dispatch(b);
				} else {
					paintBar(b);
				}
			}, 500);
		} else {
			setTimeout(() => removeBar(b), 10000);
		}
	}
	function recordButton(b) {
		const left = b.pieces.filter(pc => !b.recorded.includes(pc.type + ':' + pc.id));
		if (!left.length) {
			return '';
		}
		return '<button type="button" class="ibx-btn ibx-btn--sm ibx-btn--ghost ibx-js-barrecord">' + ic('event-new') + ' ' + esc(left.length === 1 ? t('InboxRecordOnOne', left[0].kind + ' ' + left[0].ref) : t('InboxRecordOnMany', left.length)) + '</button>';
	}
	function paintBar(b) {
		const subject = '<span class="ibx-sendbar__subject ibx-ellipsis">' + esc(b.subject || t('InboxNoSubject')) + '</span>';
		if (b.state === 'sent') {
			b.el.innerHTML = ic('check') + '<span class="ibx-sendbar__text">' + esc(t(b.copy === 0 ? 'InboxComposeSentNoCopy' : 'InboxComposeSent')) + '</span>' + subject + recordButton(b);
		} else if (b.state === 'error') {
			b.el.innerHTML = ic('warning') + '<span class="ibx-sendbar__text">' + esc(b.error) + '</span>' + subject
				+ '<button type="button" class="ibx-btn ibx-btn--sm ibx-js-barundo">' + th('InboxOutboxReopen') + '</button>';
		} else if (b.scheduled) {
			b.el.innerHTML = ic('send-later') + '<span class="ibx-sendbar__text">' + esc(t('InboxSendScheduled', new Date(b.at).toLocaleString())) + '</span>' + subject
				+ '<button type="button" class="ibx-btn ibx-btn--sm ibx-js-barundo">' + th('InboxSendUnschedule') + '</button>';
		} else {
			const left = Math.max(0, Math.ceil((b.at - Date.now()) / 1000));
			b.el.innerHTML = ic('sent') + '<span class="ibx-sendbar__text">' + esc(b.sending ? t('InboxComposeSending') : t('InboxSendIn', left)) + '</span>' + subject
				+ (b.sending ? '' : '<button type="button" class="ibx-btn ibx-btn--sm ibx-js-barundo">' + th('InboxUndo') + '</button><button type="button" class="ibx-btn ibx-btn--sm ibx-btn--primary ibx-js-barnow">' + th('InboxSendNow') + '</button>') + recordButton(b);
		}
		// Closable once nothing is pending (the countdown drives the sending: no cross there)
		if (b.state === 'sent' || b.state === 'error' || b.scheduled) {
			b.el.insertAdjacentHTML('beforeend', '<button type="button" class="ibx-btn ibx-btn--sm ibx-btn--icon ibx-sendbar__close ibx-js-barclose" aria-label="' + th('InboxCloseWindow') + '" title="' + th('InboxCloseWindow') + '">' + ic('close') + '</button>');
		}
		b.el.onclick = e => {
			if (e.target.closest('.ibx-js-barclose')) {
				removeBar(b);
			} else if (e.target.closest('.ibx-js-barundo')) {
				undo(b);
			} else if (e.target.closest('.ibx-js-barnow')) {
				clearInterval(b.timer);
				dispatch(b);
			} else if (e.target.closest('.ibx-js-barrecord')) {
				recordFromBar(b, e.target.closest('.ibx-js-barrecord'));
			}
		};
	}
	function removeBar(b) {
		clearInterval(b.timer);
		b.el.remove();
		bars.delete(b.id);
	}
	async function dispatch(b) {
		b.sending = true;
		paintBar(b);
		try {
			const res = await post('compose', 'dispatch', { id: b.id });
			b.state = 'sent';
			b.copy = res.sent_copy;
			b.recorded = b.recorded.concat(res.recorded || []);
			Dolibarr.executeHook('inbox:compose:sent', { message_id: res.message_id, account: res.account });
		} catch (err) {
			if (err.code === 'already_sent') {
				// Sent by the scheduled job meanwhile
				b.state = 'sent';
			} else {
				b.state = 'error';
				b.error = err.message;
			}
		}
		b.sending = false;
		paintBar(b);
		if (b.state === 'sent') {
			setTimeout(() => removeBar(b), recordButton(b) ? 12000 : 5000);
		}
	}
	async function undo(b) {
		clearInterval(b.timer);
		try {
			const d = await post('compose', 'cancel', { id: b.id });
			removeBar(b);
			compose({ restore: d });
		} catch (err) {
			if (err.code === 'already_sent') {
				b.state = 'sent';
				paintBar(b);
				toast(t('InboxOutboxAlreadySent'), { error: true });
			} else {
				toast(err.message, { error: true });
			}
		}
	}
	async function recordFromBar(b, anchor) {
		const left = b.pieces.filter(pc => !b.recorded.includes(pc.type + ':' + pc.id));
		const go = async keys => {
			try {
				const res = await post('compose', 'record', { id: b.id, pieces: keys });
				b.recorded = b.recorded.concat(res.recorded || keys);
				toast(t('InboxRecorded', left.filter(pc => keys.includes(pc.type + ':' + pc.id)).map(pc => pc.ref).join(', ')));
				paintBar(b);
			} catch (err) {
				toast(err.message, { error: true });
			}
		};
		if (left.length === 1) {
			go([left[0].type + ':' + left[0].id]);
			return;
		}
		popMenu(anchor, left.map(pc => ({ label: pc.kind + ' ' + pc.ref, icon: 'event-new', run: () => go([pc.type + ':' + pc.id]) })).concat([{ sep: true }, { label: t('InboxRecordAll'), icon: 'event-new', run: () => go(left.map(pc => pc.type + ':' + pc.id)) }]));
	}
	/** Sent at once: said, and the record on a piece offered when there is one */
	function sentDone(res) {
		if ((res.pieces || []).length) {
			const b = { id: res.outbox, el: null, pieces: res.pieces, recorded: res.recorded || [], subject: res.subject || '', state: 'sent', copy: res.sent_copy };
			b.el = document.createElement('div');
			b.el.className = 'ibx-sendbar';
			sendBars().appendChild(b.el);
			bars.set(b.id, b);
			paintBar(b);
			setTimeout(() => removeBar(b), 12000);
			return;
		}
		toast(res.sent_copy ? t('InboxComposeSent') : t('InboxComposeSentNoCopy'), res.sent_copy ? {} : { error: true, duration: 10000 });
	}

	// ---------- Mails waiting (scheduled, failed): bar of the Drafts folder, list ----------
	async function outboxDialog() {
		let items = [];
		try {
			items = (await post('compose', 'outbox', {})).items || [];
		} catch (err) {
			toast(err.message, { error: true });
			return;
		}
		const row = it => '<tr data-id="' + it.id + '"><td><b class="ibx-ellipsis">' + esc(it.subject || t('InboxNoSubject')) + '</b><div class="ibx-muted ibx-ellipsis">' + esc(it.to) + '</div></td>'
			+ '<td>' + (it.status < 0 ? '<span class="ibx-text-danger">' + ic('warning') + ' ' + esc(t('InboxOutboxFailed')) + '</span><div class="ibx-muted">' + esc(it.error) + '</div>' : esc(new Date(it.send_at * 1000).toLocaleString())) + '</td>'
			+ '<td class="ibx-table__actions"><button type="button" class="ibx-btn ibx-btn--sm" data-act="edit">' + th('InboxOutboxReopen') + '</button> '
			+ (it.status === 0 ? '<button type="button" class="ibx-btn ibx-btn--sm" data-act="now">' + th('InboxSendNow') + '</button>' : '') + '</td></tr>';
		const dlg = dialog({
			title: t('InboxOutboxTitle'),
			wide: true,
			body: items.length ? '<table class="ibx-table"><thead><tr><th>' + th('InboxSubject') + '</th><th>' + th('InboxOutboxWhen') + '</th><th></th></tr></thead><tbody>' + items.map(row).join('') + '</tbody></table>'
				: '<div class="ibx-empty">' + ic('sent', 'ibx-empty__icon') + '<div>' + th('InboxOutboxEmpty') + '</div></div>',
			noCancel: true,
			actions: [{ label: t('InboxCloseWindow'), primary: true, run: () => true }],
		});
		dlg.addEventListener('click', async e => {
			const b = e.target.closest('[data-act]');
			if (!b) {
				return;
			}
			const id = +b.closest('[data-id]').dataset.id;
			try {
				if (b.dataset.act === 'edit') {
					const d = await post('compose', 'cancel', { id });
					dlg.close();
					compose({ restore: d });
				} else {
					await post('compose', 'dispatch', { id });
					b.closest('tr').remove();
					toast(t('InboxComposeSent'));
					Dolibarr.executeHook('inbox:compose:sent', {});
				}
				Dolibarr.executeHook('inbox:outbox:changed', {});
			} catch (err) {
				toast(err.message, { error: true });
			}
		});
	}
	async function outboxBar(ctx) {
		const bar = ctx && ctx.el ? $('.ibx-js-outboxbar', ctx.el) : null;
		if (!bar) {
			return;
		}
		bar.hidden = true;
		if (ctx.role !== 'drafts') {
			return;
		}
		let items = [];
		try {
			items = (await post('compose', 'outbox', {})).items || [];
		} catch (e) {
			return;
		}
		const waiting = items.filter(it => it.scheduled || it.status < 0);
		if (!waiting.length) {
			return;
		}
		const failed = waiting.filter(it => it.status < 0).length;
		bar.innerHTML = ic(failed ? 'warning' : 'send-later') + '<div class="ibx-banner__text">' + esc(failed ? t('InboxOutboxBarFailed', waiting.length, failed) : t('InboxOutboxBar', waiting.length)) + '</div>'
			+ '<button type="button" class="ibx-btn ibx-btn--sm ibx-js-outboxopen">' + th('InboxOutboxSee') + '</button>';
		bar.hidden = false;
		$('.ibx-js-outboxopen', bar).onclick = () => outboxDialog();
	}
	Dolibarr.on('inbox:folder:open', ctx => outboxBar(ctx));
	// After a reload: the mails still in their delay get their bar back
	Dolibarr.on('inbox:app:ready', async () => {
		// A mailto: link given to inbox by the browser (index.php?mailto=..., see My account): written here
		const mt = new URLSearchParams(location.search).get('mailto');
		if (mt && I.ctx('INBOX_CAN_SEND', false)) {
			history.replaceState(null, '', location.pathname + location.hash);
			I.composeMailto(/^mailto:/i.test(mt) ? mt : 'mailto:' + mt);
		}
		try {
			const items = (await post('compose', 'outbox', {})).items || [];
			items.filter(it => it.status === 0 && !it.scheduled).forEach(it => sendBar({ queued: it.id, send_at: it.send_at, subject: it.subject }));
		} catch (e) {
			// the bar only
		}
	});
	I.outboxDialog = outboxDialog;

	async function addFiles(w, files) {
		for (const file of Array.from(files || [])) {
			const used = w.attachments.reduce((n, a) => n + (a.size || 0), 0);
			if (used + file.size > w.data.max_size) {
				toast(t('InboxComposeTooBigFile', file.name, size(w.data.max_size)), { error: true });
				continue;
			}
			const tmp = { id: 'p' + (++seq), name: file.name, size: file.size, pending: true };
			w.attachments.push(tmp);
			paintAttachments(w);
			try {
				const res = await I.upload('compose', 'upload', { compose: w.token }, file);
				Object.assign(tmp, res.attachment, { pending: false });
				w.changed();
			} catch (err) {
				w.attachments.splice(w.attachments.indexOf(tmp), 1);
				toast(file.name + ' : ' + err.message, { error: true });
			}
			paintAttachments(w);
		}
	}

	function linkDialog(w) {
		const current = w.editor.linkAt ? w.editor.linkAt() : '';
		dialog({
			title: t('InboxFmtLink'),
			body: '<label class="ibx-formrow__label" for="ibx-link-url">' + th('InboxComposeLinkUrl') + '</label><input id="ibx-link-url" class="ibx-field" type="url" inputmode="url" autocomplete="off" spellcheck="false" placeholder="https://" value="' + esc(current) + '">',
			actions: (current ? [{ label: t('InboxFmtUnlink'), run: () => w.editor.exec('unlink') }] : []).concat([{ label: t('InboxSave'), primary: true, run: d => {
				const v = $('#ibx-link-url', d).value.trim();
				if (v && !/^(https?:\/\/|mailto:|tel:)/i.test(v)) {
					w.editor.exec('link', 'https://' + v);
				} else {
					w.editor.exec('link', v);
				}
			} }]),
			onOpen: d => $('#ibx-link-url', d).focus(),
		});
	}

	/**
	 * HTML source of the text, to read it, change it or paste HTML in it; back to the formatted text with the same
	 * button (the editor keeps what it knows how to show; the server cleans the HTML anyway)
	 */
	function toggleSource(w) {
		const dlg = w.dlg;
		const ed = $('.ibx-js-editor', dlg);
		const btn = $('[data-cmd="source"]', dlg);
		if (!w.sourceMode) {
			const ta = document.createElement('textarea');
			ta.className = 'ibx-compose__source';
			ta.spellcheck = false;
			ta.setAttribute('aria-label', t('InboxFmtSource'));
			// One block per line, to be read
			ta.value = w.editor.getHtml().replace(/(<\/(p|div|h[1-6]|ul|ol|li|blockquote|table|tr|thead|tbody)>)/g, m => m + '\n').replace(/(<(ul|ol|table|thead|tbody|tr)>)/g, m => m + '\n');
			ta.addEventListener('input', () => w.changed());
			ed.after(ta);
			ed.hidden = true;
			w.sourceMode = true;
			ta.focus();
		} else {
			const ta = $('.ibx-compose__source', dlg);
			w.editor.setHtml(ta.value);
			ta.remove();
			ed.hidden = false;
			w.sourceMode = false;
			w.editor.focus('end');
			w.changed();
		}
		btn.setAttribute('aria-pressed', String(w.sourceMode));
		$$('.ibx-compose__tools [data-cmd]', dlg).forEach(b => {
			if (b !== btn) {
				if (w.sourceMode) {
					b.setAttribute('aria-disabled', 'true');
				} else {
					b.removeAttribute('aria-disabled');
				}
			}
		});
	}

	// ---------- Colours: split buttons, palette, recent colours (kept in the settings of the user) ----------
	const COLOR_DEFAULT = { color: '#b42318', background: '#fef08a' };
	function colorState() {
		let c = {};
		try {
			c = JSON.parse(params.compose_colors || '{}') || {};
		} catch (e) {
			c = {};
		}
		return {
			color: c.color || COLOR_DEFAULT.color,
			background: c.background || COLOR_DEFAULT.background,
			recent_color: Array.isArray(c.recent_color) ? c.recent_color.slice(0, 5) : [],
			recent_background: Array.isArray(c.recent_background) ? c.recent_background.slice(0, 5) : [],
		};
	}
	function rememberColor(kind, value, custom) {
		const c = colorState();
		c[kind] = value;
		if (custom) {
			const key = 'recent_' + kind;
			c[key] = [value].concat(c[key].filter(v => v !== value)).slice(0, 5);
		}
		params.compose_colors = JSON.stringify(c);
		post('userparam', 'set', { param: 'compose_colors', value: params.compose_colors }).catch(() => {});
		writings.forEach(paintColorButtons);
	}
	function splitButton(x) {
		return '<span class="ibx-splitbtn" data-split="' + x[0] + '">'
			+ '<button type="button" class="ibx-tbtn ibx-splitbtn__main" data-cmd="' + x[0] + 'Apply" aria-pressed="false" title="' + esc(t(x[2])) + '" aria-label="' + esc(t(x[2])) + '">'
			+ ic(x[1]) + '<span class="ibx-splitbtn__bar" aria-hidden="true"></span></button>'
			+ '<button type="button" class="ibx-splitbtn__arrow" data-cmd="' + x[0] + '" aria-haspopup="menu" aria-expanded="false" title="' + esc(t(x[0] === 'color' ? 'InboxFmtColorMore' : 'InboxFmtBackgroundMore')) + '" aria-label="' + esc(t(x[0] === 'color' ? 'InboxFmtColorMore' : 'InboxFmtBackgroundMore')) + '">' + ic('chevron-down') + '</button></span>';
	}
	/** The bar shows the colour a click puts; the button is pressed when the selection has a colour already */
	function paintColorButtons(w) {
		if (!w.dlg) {
			return;
		}
		const c = colorState();
		['color', 'background'].forEach(kind => {
			const main = $('[data-split="' + kind + '"] .ibx-splitbtn__main', w.dlg);
			if (!main) {
				return;
			}
			$('.ibx-splitbtn__bar', main).style.background = c[kind];
			const current = w.editor && w.editor.styleOf ? w.editor.styleOf(kind) : '';
			main.setAttribute('aria-pressed', String(!!current));
			const label = t(kind === 'color' ? 'InboxFmtColor' : 'InboxFmtBackground');
			main.title = current ? t('InboxFmtColorRemove', label) : t('InboxFmtColorApply', label);
		});
	}
	/** Palette: automatic, colours of base, recent ones, any colour (picker of the system) */
	function colorMenu(w, anchor, kind) {
		const c = colorState();
		const base = kind === 'color' ? COLORS : BACKGROUNDS;
		const current = (w.editor.styleOf ? w.editor.styleOf(kind) : '') || '';
		const sw = (value, label) => '<button type="button" class="ibx-colorpick__sw' + (current && current.toLowerCase() === value.toLowerCase() ? ' is-current' : '') + '" data-color="' + esc(value) + '" title="' + esc(label) + '" aria-label="' + esc(label) + '"></button>';
		const box = document.createElement('div');
		box.className = 'ibx-menu ibx-colorpick';
		box.setAttribute('role', 'dialog');
		box.setAttribute('aria-label', t(kind === 'color' ? 'InboxFmtColor' : 'InboxFmtBackground'));
		// Three parts: automatic, colours (base, recent), any colour
		box.innerHTML = '<button type="button" class="ibx-menu__item" data-color=""><span class="ibx-menu__icon ibx-swatch ibx-swatch--none"></span><span class="ibx-menu__label">' + esc(t(kind === 'color' ? 'InboxColorAuto' : 'InboxColorNone')) + '</span></button>'
			+ '<div class="ibx-menu__sep" role="separator"></div><div class="ibx-colorpick__row">' + base.map(b => sw(b[0], t(b[1]))).join('') + '</div>'
			+ (c['recent_' + kind].length ? '<div class="ibx-colorpick__title">' + esc(t('InboxColorRecent')) + '</div><div class="ibx-colorpick__row">' + c['recent_' + kind].map(v => sw(v, v)).join('') + '</div>' : '')
			+ '<div class="ibx-menu__sep" role="separator"></div><label class="ibx-menu__item ibx-colorpick__other">' + ic('catalogue', 'ibx-menu__icon') + '<span class="ibx-menu__label">' + esc(t('InboxColorOther')) + '</span>'
			+ '<input type="color" class="ibx-sr-only" value="' + esc(current && /^#[0-9a-f]{6}$/i.test(current) ? current : c[kind]) + '"></label>';
		$$('.ibx-colorpick__sw', box).forEach(el => {
			el.style.background = el.dataset.color;
		});
		document.body.appendChild(box);
		const r0 = anchor.getBoundingClientRect();
		box.style.position = 'fixed';
		box.style.zIndex = 90;
		box.style.left = Math.max(8, Math.min(r0.left, window.innerWidth - box.offsetWidth - 8)) + 'px';
		box.style.top = (r0.bottom + box.offsetHeight + 8 > window.innerHeight ? Math.max(8, r0.top - box.offsetHeight - 4) : r0.bottom + 4) + 'px';
		const done = () => {
			box.remove();
			document.removeEventListener('pointerdown', off, true);
			anchor.setAttribute('aria-expanded', 'false');
		};
		const off = e => {
			if (!box.contains(e.target)) {
				done();
			}
		};
		const apply = (value, custom) => {
			w.editor.exec(kind, value);
			if (value) {
				rememberColor(kind, value, custom);
			}
			paintColorButtons(w);
		};
		anchor.setAttribute('aria-expanded', 'true');
		document.addEventListener('pointerdown', off, true);
		box.addEventListener('click', e => {
			const b = e.target.closest('button[data-color]');
			if (b) {
				done();
				apply(b.dataset.color, !base.some(x => x[0] === b.dataset.color) && b.dataset.color !== '');
			}
		});
		// Any colour: the picker of the system, applied when chosen
		$('input[type="color"]', box).addEventListener('change', e => {
			const v = e.target.value;
			done();
			apply(v, true);
		});
		box.addEventListener('keydown', e => {
			if (e.key === 'Escape') {
				e.preventDefault();
				e.stopPropagation();
				done();
				anchor.focus();
			}
		});
		$('button', box).focus();
	}

	// ---------- Context menu of the text (tables, keyboard) and bar over a selection (decided 2026-11-07) ----------
	/** An anchor at a point, for the menus opened from the pointer or the caret */
	function pointAnchor(w, x, y) {
		return { getBoundingClientRect: () => ({ left: x, right: x, top: y, bottom: y }), setAttribute() {}, focus: () => w.editor.focus() };
	}
	function formatItems(w, x, y) {
		const ed = w.editor;
		return [
			{ label: t('InboxFmtBold'), icon: 'fmt-bold', key: 'Ctrl+B', checked: ed.isActive('bold'), run: () => ed.exec('bold') },
			{ label: t('InboxFmtItalic'), icon: 'fmt-italic', key: 'Ctrl+I', checked: ed.isActive('italic'), run: () => ed.exec('italic') },
			{ label: t('InboxFmtUnderline'), icon: 'fmt-underline', key: 'Ctrl+U', checked: ed.isActive('underline'), run: () => ed.exec('underline') },
			{ label: t('InboxFmtStrike'), icon: 'fmt-strike', checked: ed.isActive('strike'), run: () => ed.exec('strike') },
			{ label: t('InboxFmtColor'), icon: 'fmt-color', sub: true, run: () => colorMenu(w, pointAnchor(w, x, y), 'color') },
			{ label: t('InboxFmtBackground'), icon: 'fmt-bg', sub: true, run: () => colorMenu(w, pointAnchor(w, x, y), 'background') },
			{ label: t('InboxFmtLink'), icon: 'link', key: 'Ctrl+K', run: () => linkDialog(w) },
			{ label: t('InboxFmtClear'), icon: 'fmt-clear', run: () => ed.exec('clearFormat') },
		];
	}
	function tableItems(w) {
		const ed = w.editor;
		return [
			{ header: t('InboxTableStyles') },
			{ label: t('InboxTableStyle_grid'), icon: 'table-style-grid', run: () => ed.tableStyle('grid') },
			{ label: t('InboxTableStyle_lines'), icon: 'table-style-lines', run: () => ed.tableStyle('lines') },
			{ label: t('InboxTableStyle_header'), icon: 'table-style-header', run: () => ed.tableStyle('header') },
			{ label: t('InboxTableStyle_none'), icon: 'table-style-none', run: () => ed.tableStyle('none') },
			{ sep: true },
			{ label: t('InboxTableProps'), icon: 'table-props', run: () => tablePropsDialog(w) },
			{ label: t('InboxCellProps'), icon: 'cell-props', run: () => cellPropsDialog(w) },
			{ sep: true },
			{ label: t('InboxFmtTableRowBefore'), icon: 'table-row', run: () => ed.exec('tableRowBefore') },
			{ label: t('InboxFmtTableRow'), icon: 'table-row', run: () => ed.exec('tableRow') },
			{ label: t('InboxFmtTableColumnBefore'), icon: 'table-col', run: () => ed.exec('tableColumnBefore') },
			{ label: t('InboxFmtTableColumn'), icon: 'table-col', run: () => ed.exec('tableColumn') },
			{ label: t('InboxFmtTableMerge'), icon: 'fmt-table', run: () => ed.exec('tableMerge') },
			{ label: t('InboxFmtTableHeader'), icon: 'fmt-bold', run: () => ed.exec('tableHeader') },
			{ label: t('InboxFmtTableHeaderColumn'), icon: 'fmt-bold', run: () => ed.exec('tableHeaderColumn') },
			{ sep: true },
			{ label: t('InboxFmtTableRowDelete'), icon: 'table-row-del', run: () => ed.exec('tableRowDelete') },
			{ label: t('InboxFmtTableColumnDelete'), icon: 'table-col-del', run: () => ed.exec('tableColumnDelete') },
			{ label: t('InboxFmtTableDelete'), icon: 'table-del', danger: true, run: () => ed.exec('tableDelete') },
		];
	}
	/** Table button: « Insert a table » out of a table (no arrow), the menu of the table in one */
	function paintTableButton(w) {
		const b = $('[data-cmd="table"]', w.dlg);
		if (!b || !w.editor || !w.editor.isActive) {
			return;
		}
		const inTable = w.editor.isActive('table');
		if (b.classList.contains('ibx-tbtn--menu') === inTable) {
			return;
		}
		b.classList.toggle('ibx-tbtn--menu', inTable);
		$('.ibx-tbtn__caret', b).hidden = !inTable;
		if (inTable) {
			b.setAttribute('aria-haspopup', 'menu');
			b.setAttribute('aria-expanded', 'false');
		} else {
			b.removeAttribute('aria-haspopup');
			b.removeAttribute('aria-expanded');
		}
		const label = t(inTable ? 'InboxFmtTable' : 'InboxFmtTableInsert');
		b.title = label;
		b.setAttribute('aria-label', label);
	}

	/** A mini table drawn with the settings (preview of the dialogs) */
	function previewTable(box, p) {
		const line = p.borderWidth + 'px solid ' + p.borderColor;
		const table = $('table', box);
		table.style.width = p.width === 'full' ? '100%' : 'auto';
		table.style.marginLeft = p.align === 'center' ? 'auto' : '0';
		table.style.marginRight = p.align === 'center' ? 'auto' : '0';
		$$('td', box).forEach((td, i) => {
			const row = Math.floor(i / 3);
			td.style.border = p.frame === 'all' ? line : '0';
			td.style.borderBottom = p.frame === 'none' ? '0' : line;
			td.style.padding = p.padding + 'px';
			td.style.background = p.stripes && row % 2 === 1 ? '#f6f8fa' : '';
		});
	}
	const choice = (name, value, options) => '<span class="ibx-seg" role="radiogroup">' + options.map(o => '<label class="ibx-seg__opt"><input type="radio" name="' + name + '" value="' + o[0] + '"' + (String(o[0]) === String(value) ? ' checked' : '') + '><span>' + esc(t(o[1])) + '</span></label>').join('') + '</span>';
	/** Properties of the table: borders, spacing, width, alignment, stripes; preview, applied at once on « Apply » */
	function tablePropsDialog(w) {
		const p = w.editor.tableInfo();
		if (!p) {
			return;
		}
		const row = (label, html) => '<div class="ibx-formrow"><span class="ibx-formrow__label">' + esc(t(label)) + '</span><div>' + html + '</div></div>';
		dialog({
			title: t('InboxTableProps'),
			body: '<div class="ibx-form ibx-tableprops">'
				+ row('InboxTableBorders', choice('frame', p.frame, [['all', 'InboxTableBorders_all'], ['h', 'InboxTableBorders_h'], ['none', 'InboxTableBorders_none']]))
				+ row('InboxTableBorderLine', choice('borderWidth', p.borderWidth, [[1, 'InboxTableBorderThin'], [2, 'InboxTableBorderThick']]) + ' <input type="color" name="borderColor" class="ibx-color" aria-label="' + th('InboxTableBorderColor') + '" value="' + esc(/^#[0-9a-f]{6}$/i.test(p.borderColor) ? p.borderColor : '#d0d5dd') + '">')
				+ row('InboxTableSpacing', choice('padding', p.padding, [[3, 'InboxTableSpacing_tight'], [6, 'InboxTableSpacing_normal'], [10, 'InboxTableSpacing_wide']]))
				+ row('InboxTableWidth', choice('width', p.width, [['auto', 'InboxTableWidth_auto'], ['full', 'InboxTableWidth_full']]))
				+ row('InboxTableAlign', choice('align', p.align, [['left', 'InboxFmtAlign_left'], ['center', 'InboxFmtAlign_center']]))
				+ row('InboxTableStripes', '<label class="ibx-check"><input type="checkbox" name="stripes"' + (p.stripes ? ' checked' : '') + '> ' + th('InboxTableStripesHelp') + '</label>')
				+ '<div class="ibx-tableprops__preview" aria-hidden="true"><table><tbody>' + '<tr><td>A</td><td>B</td><td>C</td></tr>'.repeat(3) + '</tbody></table></div></div>',
			actions: [{ label: t('InboxApply'), primary: true, run: d => w.editor.tableProps(read(d)) }],
			onOpen: d => {
				previewTable($('.ibx-tableprops__preview', d), read(d));
				d.addEventListener('input', () => previewTable($('.ibx-tableprops__preview', d), read(d)));
			},
		});
		function read(d) {
			const v = n => ($('[name="' + n + '"]:checked', d) || {}).value;
			return { frame: v('frame'), borderWidth: +v('borderWidth'), borderColor: $('[name="borderColor"]', d).value, padding: +v('padding'), width: v('width'), align: v('align'), stripes: $('[name="stripes"]', d).checked };
		}
	}
	/**
	 * Borders of a cell: a square whose sides are switched one by one (each side a button, aria-pressed), and three
	 * shortcuts under it. The sides chosen are in data-sides (letters t r b l), data-dirty once touched.
	 */
	function borderBox(sides) {
		const side = (k, label) => '<button type="button" class="ibx-borderbox__side ibx-borderbox__side--' + k + '" data-side="' + k + '" aria-pressed="' + sides.includes(k) + '" title="' + esc(t(label)) + '" aria-label="' + esc(t(label)) + '"></button>';
		return '<div class="ibx-borderbox__wrap"><div class="ibx-borderbox" role="group" aria-label="' + th('InboxTableBorders') + '" data-sides="' + esc(sides) + '">'
			+ side('t', 'InboxBorderTop') + side('r', 'InboxBorderRight') + side('b', 'InboxBorderBottom') + side('l', 'InboxBorderLeft')
			+ '<span class="ibx-borderbox__cell" aria-hidden="true">Aa</span></div>'
			+ '<div class="ibx-borderbox__quick">'
			+ [['trbl', 'InboxTableBorders_all'], ['', 'InboxTableBorders_none'], ['b', 'InboxTableBorders_bottom']].map(q => '<button type="button" class="ibx-btn ibx-btn--sm" data-quick="' + q[0] + '">' + th(q[1]) + '</button>').join('')
			+ '</div></div>';
	}
	function bindBorderBox(box) {
		const set = value => {
			box.dataset.sides = value;
			box.dataset.dirty = '1';
			$$('[data-side]', box).forEach(b => b.setAttribute('aria-pressed', String(value.includes(b.dataset.side))));
		};
		box.addEventListener('click', e => {
			const b = e.target.closest('[data-side]');
			if (b) {
				const cur = box.dataset.sides;
				const k = b.dataset.side;
				set('trbl'.split('').filter(x => (x === k ? !cur.includes(x) : cur.includes(x))).join(''));
			}
		});
		box.parentNode.addEventListener('click', e => {
			const q = e.target.closest('[data-quick]');
			if (q) {
				set(q.dataset.quick);
			}
		});
	}

	/** Properties of the cells selected (or the cell of the cursor): background, alignment, borders */
	function cellPropsDialog(w) {
		const c = w.editor.cellInfo();
		const bg = c.background || '';
		const row = (label, html) => '<div class="ibx-formrow"><span class="ibx-formrow__label">' + esc(t(label)) + '</span><div>' + html + '</div></div>';
		const swatches = [['', 'InboxColorNone']].concat(BACKGROUNDS, [['#eef2f7', 'InboxColorGrey'], ['#f6f8fa', 'InboxColorLight']]);
		dialog({
			title: t('InboxCellProps'),
			body: '<div class="ibx-form">'
				+ row('InboxCellBackground', '<span class="ibx-colorpick__row ibx-cellbg">' + swatches.map(s => '<button type="button" class="ibx-colorpick__sw' + (s[0] === '' ? ' ibx-swatch--none' : '') + (s[0].toLowerCase() === bg.toLowerCase() ? ' is-current' : '') + '" data-color="' + s[0] + '" title="' + esc(t(s[1])) + '" aria-label="' + esc(t(s[1])) + '" aria-pressed="' + (s[0].toLowerCase() === bg.toLowerCase()) + '"></button>').join('')
					+ '<input type="color" class="ibx-color" name="custom" aria-label="' + th('InboxColorOther') + '" value="' + esc(/^#[0-9a-f]{6}$/i.test(bg) ? bg : '#ffffff') + '"></span>')
				+ row('InboxCellType', choice('header', c.header ? 'header' : 'cell', [['cell', 'InboxCellType_cell'], ['header', 'InboxCellType_header']]))
				+ row('InboxCellAlign', choice('textAlign', c.textAlign || 'left', [['left', 'InboxFmtAlign_left'], ['center', 'InboxFmtAlign_center'], ['right', 'InboxFmtAlign_right']]))
				+ row('InboxCellVAlign', choice('verticalAlign', c.verticalAlign || 'top', [['top', 'InboxCellVAlign_top'], ['middle', 'InboxCellVAlign_middle'], ['bottom', 'InboxCellVAlign_bottom']]))
				+ row('InboxTableBorders', borderBox(c.frame))
				+ '</div>',
			actions: [{ label: t('InboxApply'), primary: true, run: d => {
				const v = n => ($('[name="' + n + '"]:checked', d) || {}).value;
				const pressed = $('.ibx-cellbg [aria-pressed="true"]', d);
				const p = { header: v('header') === 'header', background: d._custom || (pressed ? pressed.dataset.color || null : c.background || null), textAlign: v('textAlign'), verticalAlign: v('verticalAlign') };
				// The borders change only when the square was touched (several cells keep theirs otherwise)
				const bb = $('.ibx-borderbox', d);
				if (bb.dataset.dirty) {
					p.frame = bb.dataset.sides;
				}
				w.editor.cellProps(p);
			} }],
			onOpen: d => {
				bindBorderBox($('.ibx-borderbox', d));
				$$('.ibx-cellbg [data-color]', d).forEach(el => {
					if (el.dataset.color) {
						el.style.background = el.dataset.color;
					}
				});
				$('.ibx-cellbg', d).addEventListener('click', e => {
					const b = e.target.closest('[data-color]');
					if (b) {
						d._custom = '';
						$$('.ibx-cellbg [data-color]', d).forEach(x => {
							x.setAttribute('aria-pressed', String(x === b));
							x.classList.toggle('is-current', x === b);
						});
					}
				});
				$('[name="custom"]', d).addEventListener('input', e => {
					d._custom = e.target.value;
					$$('.ibx-cellbg [data-color]', d).forEach(x => {
						x.setAttribute('aria-pressed', 'false');
						x.classList.remove('is-current');
					});
				});
			},
		});
	}

	/** Menu of inbox in the text: the table first when the cursor is in one, then the formatting */
	function textMenu(w, x, y) {
		const items = (w.editor.isActive('table') ? tableItems(w).concat([{ sep: true }]) : []).concat(formatItems(w, x, y), [{ sep: true }, { note: t('InboxBrowserMenuSpellHint') }]);
		popMenu(pointAnchor(w, x, y), items, { x, y });
	}
	// ---------- Voice typing (1.28.0): js/inbox-dictation.js ----------
	function dictateStop(w) {
		if (I.dictation) {
			I.dictation.stop(w);
		}
	}
	function dictateToggle(w) {
		if (I.dictation) {
			I.dictation.toggle(w, identityOf(w).account);
		}
	}
	function paintDictateButton(w) {
		const b = $('[data-cmd="dictate"]', w.dlg);
		if (I.dictation) {
			I.dictation.paintButton(w, identityOf(w).account);
		} else if (b) {
			b.hidden = true;
		}
	}

	// ---------- AI of the writing (1.27.0, analyse-ia-redaction.md) ----------
	/** The button follows the mailbox of the identity: allowed there, else hidden (and its panel closed) */
	function paintAiButton(w) {
		const b = $('[data-cmd="ai"]', w.dlg);
		if (!b) {
			return;
		}
		const ok = !!(w.data.ai && w.data.ai.accounts.includes(identityOf(w).account));
		b.hidden = !ok;
		const sep = b.previousElementSibling;
		if (sep && sep.classList.contains('ibx-compose__sep')) {
			sep.hidden = !ok;
		}
		if (!ok && !$('.ibx-js-ai', w.dlg).hidden) {
			aiToggle(w, false);
		}
	}
	function aiToggle(w, on) {
		const box = $('.ibx-js-ai', w.dlg);
		const show = on === undefined ? box.hidden : on;
		box.hidden = !show;
		const b = $('[data-cmd="ai"]', w.dlg);
		if (b) {
			b.setAttribute('aria-pressed', String(show));
		}
		if (show) {
			if (!box.dataset.done) {
				aiPaint(w, box);
			}
			const first = $('.ibx-js-aiprompt', box);
			if (first && box.dataset.mode === 'write') {
				first.focus();
			}
		} else {
			w.editor.focus();
		}
	}
	function aiPaint(w, box) {
		box.dataset.done = '1';
		box.dataset.mode = box.dataset.mode || 'write';
		const withMail = !!(w.source && w.data.ai && w.data.ai.mail);
		const uiLang = (I.ctx('MAIN_LANG_DEFAULT', 'fr_FR') || 'fr_FR');
		const langs = ['fr_FR', 'en_US', 'de_DE', 'it_IT', 'es_ES', 'nl_NL', 'pt_PT'];
		let names = null;
		try {
			names = new Intl.DisplayNames([uiLang.replace('_', '-')], { type: 'language' });
		} catch (e) {
			names = null;
		}
		const langName = c => (names ? names.of(c.slice(0, 2)) : c);
		const defLang = uiLang.slice(0, 2) === 'fr' ? 'en_US' : 'fr_FR';
		const mode = (id, key) => '<label class="ibx-seg__opt"><input type="radio" name="' + w.id + '-aimode" value="' + id + '"' + (box.dataset.mode === id ? ' checked' : '') + '><span>' + th(key) + '</span></label>';
		box.innerHTML = '<div class="ibx-ai__head">' + ic('magic', 'ibx-ai__icon')
			+ '<div class="ibx-seg" role="radiogroup" aria-label="' + th('InboxAi') + '">' + mode('write', 'InboxAiWrite') + mode('rephrase', 'InboxAiRephrase') + mode('translate', 'InboxAiTranslate') + '</div>'
			+ '<span class="ibx-ai__spacer"></span><button type="button" class="ibx-btn ibx-btn--icon ibx-btn--sm ibx-btn--ghost ibx-js-aiclose" title="' + th('InboxAiClose') + '" aria-label="' + th('InboxAiClose') + '">' + ic('close') + '</button></div>'
			+ '<div class="ibx-ai__pane" data-pane="write"><label class="ibx-sr-only" for="' + w.id + '-aiprompt">' + th('InboxAiPrompt') + '</label>'
			+ '<textarea class="ibx-field ibx-ai__prompt ibx-js-aiprompt" id="' + w.id + '-aiprompt" rows="2" maxlength="2000" placeholder="' + th('InboxAiPromptPlaceholder') + '"></textarea>'
			+ '<div class="ibx-ai__row">' + (withMail ? '<label class="ibx-check"><input type="checkbox" class="ibx-js-aimail" checked> ' + th('InboxAiWithMail') + '</label>' : '')
			+ '<span class="ibx-ai__spacer"></span><button type="button" class="ibx-btn ibx-btn--primary ibx-btn--sm ibx-js-airun" data-kind="write">' + th('InboxAiGenerate') + '</button></div></div>'
			+ '<div class="ibx-ai__pane" data-pane="rephrase"><p class="ibx-ai__hint">' + th('InboxAiOnSelection') + '</p><div class="ibx-ai__row">'
			+ ['shorter', 'formal', 'warm', 'correct'].map(s => '<button type="button" class="ibx-btn ibx-btn--sm ibx-js-airun" data-kind="rephrase" data-style="' + s + '">' + th('InboxAiStyle_' + s) + '</button>').join('') + '</div></div>'
			+ '<div class="ibx-ai__pane" data-pane="translate"><p class="ibx-ai__hint">' + th('InboxAiOnSelection') + '</p><div class="ibx-ai__row">'
			+ '<label for="' + w.id + '-ailang">' + th('InboxAiLang') + '</label><select class="ibx-field ibx-field--medium ibx-js-ailang" data-combo id="' + w.id + '-ailang">'
			+ langs.map(c => '<option value="' + c + '"' + (c === defLang ? ' selected' : '') + '>' + esc(langName(c)) + '</option>').join('') + '</select>'
			+ '<button type="button" class="ibx-btn ibx-btn--primary ibx-btn--sm ibx-js-airun" data-kind="translate">' + th('InboxAiTranslate') + '</button></div></div>'
			+ '<div class="ibx-ai__result ibx-js-airesult" aria-live="polite" hidden></div>'
			+ '<p class="ibx-ai__note">' + th('InboxAiNote') + '</p>';
		const panes = () => $$('.ibx-ai__pane', box).forEach(p => {
			p.hidden = p.dataset.pane !== box.dataset.mode;
		});
		panes();
		$$('[name="' + w.id + '-aimode"]', box).forEach(r => r.addEventListener('change', () => {
			box.dataset.mode = r.value;
			panes();
		}));
		$('.ibx-js-aiclose', box).addEventListener('click', () => aiToggle(w, false));
		// The selection of the text stays: a click in the panel (out of its fields) never takes the focus from it
		box.addEventListener('mousedown', e => {
			if (e.target.closest('button') && !e.target.closest('.ibx-js-airesult')) {
				e.preventDefault();
			}
		});
		box.addEventListener('click', e => {
			const b = e.target.closest('.ibx-js-airun');
			if (b) {
				aiRun(w, box, { kind: b.dataset.kind, style: b.dataset.style || '' });
			}
		});
		$('.ibx-js-aiprompt', box).addEventListener('keydown', e => {
			if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
				e.preventDefault();
				aiRun(w, box, { kind: 'write' });
			}
		});
	}
	/** A request: on the selection, else the whole text; the answer shown as a proposal, never put in the mail by itself */
	async function aiRun(w, box, req) {
		const ed = w.editor;
		const range = ed.selectionRange ? ed.selectionRange() : null;
		const onSelection = !!(range && range.text.trim() !== '');
		const text = onSelection ? range.text : ed.getText();
		const data = { compose: w.token, identity: +$('.ibx-js-from', w.dlg).value, kind: req.kind, text };
		if (req.kind === 'write') {
			data.prompt = $('.ibx-js-aiprompt', box).value.trim();
			if (!data.prompt) {
				$('.ibx-js-aiprompt', box).focus();
				return;
			}
			const m = $('.ibx-js-aimail', box);
			data.with_mail = m && m.checked ? 1 : 0;
		} else if (req.kind === 'rephrase') {
			data.style = req.style;
		} else {
			data.lang = $('.ibx-js-ailang', box).value;
		}
		if (req.kind !== 'write' && !text.trim()) {
			toast(t('InboxAiError_empty'), { error: true });
			return;
		}
		w.aiLast = { req, range: onSelection ? { from: range.from, to: range.to } : null, cursor: range };
		const res = $('.ibx-js-airesult', box);
		res.hidden = false;
		res.innerHTML = '<p class="ibx-ai__working">' + ic('magic') + ' ' + th('InboxAiWorking') + '</p>';
		$$('.ibx-js-airun', box).forEach(b => b.setAttribute('aria-disabled', 'true'));
		let r;
		try {
			r = await post('compose', 'ai', data);
		} catch (err) {
			res.innerHTML = '<div class="ibx-notice ibx-notice--error">' + ic('warning') + '<div class="ibx-notice__text">' + esc(err.message) + '</div></div>'
				+ '<div class="ibx-ai__row"><button type="button" class="ibx-btn ibx-btn--sm ibx-js-airetry">' + th('InboxAiRetry') + '</button></div>';
			$('.ibx-js-airetry', res).addEventListener('click', () => aiRun(w, box, req));
			return;
		} finally {
			$$('.ibx-js-airun', box).forEach(b => b.removeAttribute('aria-disabled'));
		}
		// Cleaned by the allow list of the mails on the server (no script, no event, no picture from elsewhere)
		const replaceWhat = w.aiLast.range ? 'InboxAiReplace' : 'InboxAiReplaceAll';
		res.innerHTML = '<div class="ibx-ai__label">' + th('InboxAiProposal') + '</div><div class="ibx-ai__proposal"></div>'
			+ '<div class="ibx-ai__row"><button type="button" class="ibx-btn ibx-btn--primary ibx-btn--sm ibx-js-aiuse" data-use="' + (req.kind === 'write' ? 'insert' : 'replace') + '">' + th(req.kind === 'write' ? 'InboxAiInsert' : replaceWhat) + '</button>'
			+ '<button type="button" class="ibx-btn ibx-btn--sm ibx-js-aiuse" data-use="' + (req.kind === 'write' ? 'replace' : 'insert') + '">' + th(req.kind === 'write' ? replaceWhat : 'InboxAiInsert') + '</button>'
			+ '<button type="button" class="ibx-btn ibx-btn--sm ibx-btn--ghost ibx-js-airetry">' + th('InboxAiRetry') + '</button></div>';
		$('.ibx-ai__proposal', res).innerHTML = r.html;
		$('.ibx-js-airetry', res).addEventListener('click', () => aiRun(w, box, req));
		$$('.ibx-js-aiuse', res).forEach(b => b.addEventListener('click', () => {
			if (b.dataset.use === 'insert') {
				const c = w.aiLast.cursor;
				if (c) {
					ed.replaceRange(c.to, c.to, r.html);
				} else {
					ed.insertHtml(r.html);
				}
			} else {
				ed.replaceRange(w.aiLast.range ? w.aiLast.range.from : null, w.aiLast.range ? w.aiLast.range.to : null, r.html);
			}
			w.changed();
			res.hidden = true;
			res.innerHTML = '';
			toast(t('InboxAiDone'));
		}));
	}

	/** Text of a picture for who does not see it (screen readers, pictures blocked by the mail software) */
	function imageAltDialog(w) {
		const info = w.editor.imageInfo();
		if (!info) {
			return;
		}
		dialog({
			title: t('InboxImageAlt'),
			body: '<label class="ibx-formrow__label" for="ibx-img-alt">' + th('InboxImageAltLabel') + '</label><input id="ibx-img-alt" class="ibx-field" type="text" autocomplete="off" maxlength="250" value="' + esc(info.alt) + '">'
				+ '<p class="ibx-formrow__help">' + th('InboxImageAltHelp') + '</p>',
			actions: [{ label: t('InboxSave'), primary: true, run: d => w.editor.imageProps({ alt: $('#ibx-img-alt', d).value }) }],
			onOpen: d => $('#ibx-img-alt', d).focus(),
		});
	}
	/**
	 * Small bar over the picture selected (1.25.1): sizes from its own one, alignment of its line, text for who does
	 * not see it, removal; the corners of the picture resize it by the mouse
	 */
	function imageBubble(w) {
		const ed = w.editor;
		const info = !w.sourceMode && !w.closed && ed.imageInfo ? ed.imageInfo() : null;
		let bar = w.imgBubble;
		if (!info || !w.dlg.contains(document.activeElement)) {
			if (bar) {
				bar.hidden = true;
			}
			return false;
		}
		if (!bar) {
			bar = document.createElement('div');
			bar.className = 'ibx-bubble ibx-bubble--image';
			bar.setAttribute('role', 'toolbar');
			bar.setAttribute('aria-label', t('InboxImageToolbar'));
			bar.innerHTML = [25, 50, 75, 100].map(p => '<button type="button" class="ibx-tbtn ibx-tbtn--text" data-size="' + p + '" aria-pressed="false" title="' + esc(t('InboxImageSizeOf', p + ' %')) + '">' + p + ' %</button>').join('')
				+ '<span class="ibx-compose__sep" aria-hidden="true"></span>'
				+ [['left', 'fmt-align-left', 'InboxFmtAlign_left'], ['center', 'fmt-align-center', 'InboxFmtAlign_center'], ['right', 'fmt-align-right', 'InboxFmtAlign_right']]
					.map(x => '<button type="button" class="ibx-tbtn" data-align="' + x[0] + '" aria-pressed="false" title="' + esc(t(x[2])) + '" aria-label="' + esc(t(x[2])) + '">' + ic(x[1]) + '</button>').join('')
				+ '<span class="ibx-compose__sep" aria-hidden="true"></span>'
				+ '<button type="button" class="ibx-tbtn" data-act="alt" title="' + esc(t('InboxImageAlt')) + '" aria-label="' + esc(t('InboxImageAlt')) + '">' + ic('rename') + '</button>'
				+ '<button type="button" class="ibx-tbtn ibx-tbtn--danger" data-act="delete" title="' + esc(t('InboxImageDelete')) + '" aria-label="' + esc(t('InboxImageDelete')) + '">' + ic('delete') + '</button>';
			// The picture stays selected: a click on the bar never takes the focus from the text
			bar.addEventListener('mousedown', e => e.preventDefault());
			bar.addEventListener('click', e => {
				const b = e.target.closest('button');
				if (!b) {
					return;
				}
				const cur = ed.imageInfo();
				if (b.dataset.size && cur) {
					const p = +b.dataset.size;
					ed.imageProps({ width: p === 100 || !cur.naturalWidth ? 0 : Math.round(cur.naturalWidth * p / 100) });
				} else if (b.dataset.align) {
					ed.exec('align', b.dataset.align);
				} else if (b.dataset.act === 'alt') {
					imageAltDialog(w);
					return;
				} else if (b.dataset.act === 'delete') {
					ed.exec('imageDelete');
				}
				w.changed();
				setTimeout(() => imageBubble(w), 0);
			});
			w.dlg.appendChild(bar);
			w.imgBubble = bar;
		}
		// The size and the alignment of now pressed
		const pct = info.naturalWidth ? Math.round(info.width / info.naturalWidth * 100) : 0;
		$$('[data-size]', bar).forEach(b => b.setAttribute('aria-pressed', String(Math.abs(pct - +b.dataset.size) <= 1)));
		$$('[data-align]', bar).forEach(b => b.setAttribute('aria-pressed', String(ed.isActive('align', b.dataset.align))));
		bar.hidden = false;
		const box = ed.imageBox();
		if (!box) {
			bar.hidden = true;
			return false;
		}
		const win = w.dlg.getBoundingClientRect();
		const top = box.top - win.top - bar.offsetHeight - 6;
		bar.style.left = Math.max(4, Math.min((box.left + box.right) / 2 - win.left - bar.offsetWidth / 2, win.width - bar.offsetWidth - 4)) + 'px';
		bar.style.top = (top < 40 ? Math.min(box.bottom - win.top + 6, win.height - bar.offsetHeight - 60) : top) + 'px';
		return true;
	}

	/** Small bar over the selected text: the formatting at hand, the menu of the browser (spelling) stays */
	function bubble(w) {
		let bar = w.bubble;
		const ed = w.editor;
		// A picture selected has its own bar
		if (imageBubble(w)) {
			if (bar) {
				bar.hidden = true;
			}
			return;
		}
		const show = !w.sourceMode && !w.closed && ed.hasSelection && ed.hasSelection() && w.dlg.contains(document.activeElement) && document.activeElement.closest('.ibx-compose__editor');
		if (!show) {
			if (bar) {
				bar.hidden = true;
			}
			return;
		}
		if (!bar) {
			bar = document.createElement('div');
			bar.className = 'ibx-bubble';
			bar.setAttribute('role', 'toolbar');
			bar.setAttribute('aria-label', t('InboxFmtToolbar'));
			bar.innerHTML = [['bold', 'fmt-bold', 'InboxFmtBold'], ['italic', 'fmt-italic', 'InboxFmtItalic'], ['underline', 'fmt-underline', 'InboxFmtUnderline'], ['strike', 'fmt-strike', 'InboxFmtStrike'], null,
				['color', 'fmt-color', 'InboxFmtColor'], ['background', 'fmt-bg', 'InboxFmtBackground'], null, ['link', 'link', 'InboxFmtLink'], ['clearFormat', 'fmt-clear', 'InboxFmtClear']]
				.map(x => x ? '<button type="button" class="ibx-tbtn" data-cmd="' + x[0] + '"' + (['bold', 'italic', 'underline', 'strike'].includes(x[0]) ? ' aria-pressed="false"' : '') + ' title="' + esc(t(x[2])) + '" aria-label="' + esc(t(x[2])) + '">' + ic(x[1]) + '</button>'
					: '<span class="ibx-compose__sep" aria-hidden="true"></span>').join('');
			// The selection stays: a click on the bar never takes the focus from the text
			bar.addEventListener('mousedown', e => e.preventDefault());
			bar.addEventListener('click', e => {
				const b = e.target.closest('[data-cmd]');
				if (!b) {
					return;
				}
				const cmd = b.dataset.cmd;
				if (cmd === 'color' || cmd === 'background') {
					colorMenu(w, b, cmd);
				} else if (cmd === 'link') {
					linkDialog(w);
				} else {
					ed.exec(cmd);
					bubble(w);
				}
			});
			w.dlg.appendChild(bar);
			w.bubble = bar;
		}
		$$('[aria-pressed]', bar).forEach(b => b.setAttribute('aria-pressed', String(ed.isActive(b.dataset.cmd))));
		bar.hidden = false;
		const box = ed.selectionBox();
		const win = w.dlg.getBoundingClientRect();
		const top = box.top - win.top - bar.offsetHeight - 6;
		bar.style.left = Math.max(4, Math.min((box.left + box.right) / 2 - win.left - bar.offsetWidth / 2, win.width - bar.offsetWidth - 4)) + 'px';
		// Under the selection when there is no room above it (first line of the text)
		bar.style.top = (top < 40 ? box.bottom - win.top + 6 : top) + 'px';
	}

	function moreMenu(w, anchor) {
		popMenu(anchor, [
			{ label: t('InboxComposeReceipt'), icon: 'receipt', checked: w.receipt, run: () => {
				w.receipt = !w.receipt;
				w.changed();
			} },
			{ label: t('InboxComposeSaveNow'), icon: 'drafts', run: () => save(w, true) },
			{ label: t('InboxTemplateSaveAs'), icon: 'template', run: () => saveAsTemplate(w) },
			{ label: t('InboxComposeDiscard'), icon: 'delete', danger: true, run: () => discard(w) },
		]);
	}

	// ---------- Templates (lot W6 1.18.0): those of the mailbox, those of the linked pieces ----------
	function firstTo(w) {
		const to = w.to.get();
		return to.length ? to[0].email : '';
	}
	/** Recipients that may fill the variables: À then Cc, each address once */
	function varRecipients(w) {
		const seen = new Set();
		return w.to.get().concat(w.cc.get()).filter(r => r.email && !seen.has(r.email.toLowerCase()) && seen.add(r.email.toLowerCase()));
	}
	/** Recipient of the variables (1.26.0): the one chosen while still a recipient, else the first À */
	function refTo(w) {
		if (w.varTo && varRecipients(w).some(r => r.email.toLowerCase() === w.varTo.toLowerCase())) {
			return w.varTo;
		}
		return firstTo(w);
	}
	async function templateMenu(w, anchor) {
		const identity = +$('.ibx-js-from', w.dlg).value;
		if (!w.templates || w.templates.identity !== identity) {
			try {
				w.templates = { identity, groups: (await post('compose', 'templates', { compose: w.token, identity })).groups };
			} catch (err) {
				toast(err.message, { error: true });
				return;
			}
		}
		const items = [];
		w.templates.groups.forEach(g => {
			if (!g.templates.length) {
				return;
			}
			items.push({ header: g.type === 'inbox_send' ? t('InboxTemplatesOfMailbox') : (g.type === 'all' ? t('InboxTemplatesAllTypes') : t('InboxTemplatesOfPiece', g.kind)) });
			g.templates.forEach(tp => items.push({ label: tp.label + (tp.lang ? ' (' + tp.lang.slice(0, 2) + ')' : ''), icon: tp.private ? 'user-circle' : 'template', run: () => useTemplate(w, g, tp) }));
		});
		if (!items.length) {
			items.push({ note: t('InboxTemplatesNone') });
		}
		items.push({ sep: true }, { label: t('InboxTemplateSaveAs'), icon: 'plus', run: () => saveAsTemplate(w) });
		popMenu(anchor, items);
	}
	async function useTemplate(w, g, tp) {
		// The variables of a piece: the only one of the type, else the newest (« Variables of » lets choose)
		const piece = g.pieces.length ? g.pieces[0].type + ':' + g.pieces[0].id : '';
		let res;
		try {
			res = await post('compose', 'template', { compose: w.token, id: tp.id, identity: +$('.ibx-js-from', w.dlg).value, piece, to: refTo(w) });
		} catch (err) {
			toast(err.message, { error: true });
			return;
		}
		if (w.sourceMode) {
			toggleSource(w);
		}
		if (w.editor.isEmpty()) {
			w.editor.setHtml(res.html);
		} else {
			w.editor.insertHtml(res.html);
		}
		const subj = $('.ibx-js-subject', w.dlg);
		const old = subj.value.trim();
		if (res.subject && (old === '' || (w.tpl && old === w.tpl.subject))) {
			subj.value = res.subject;
			subj.closest('.ibx-cfield').toggleAttribute('data-filled', true);
			$('.ibx-js-wtitle', w.dlg).textContent = titleOf(w);
		}
		w.tpl = { id: res.id, lang: res.lang, piece: res.piece, pieces: g.pieces, kind: g.kind, subjectRaw: res.subject_raw, subject: subj.value, to: refTo(w), identity: +$('.ibx-js-from', w.dlg).value };
		if (res.attached) {
			w.attachments.push(res.attached);
			paintAttachments(w);
		}
		paintVars(w);
		w.changed();
		w.editor.focus();
	}
	/** Subject of the template with values: the keys known replaced, the others kept (warned at the sending) */
	function fillSubject(raw, values) {
		return String(raw || '').replace(/__([A-Z0-9_]+)__/g, (m, k) => (k === 'CHECK_READ' ? '' : (Object.prototype.hasOwnProperty.call(values, k) ? String(values[k]).replace(/<[^>]*>/g, '').trim() : m)));
	}
	/** « Variables of: PR2611-0042 ▾ »: shown when the template takes the variables of a piece among several */
	function paintVars(w) {
		const box = $('.ibx-js-vars', w.dlg);
		const tpl = w.tpl;
		const people = varRecipients(w);
		w.varsListKey = people.map(r => r.email).join(',');
		if (!tpl || !w.editor.variables || !w.editor.variables().length || (tpl.pieces.length < 2 && people.length < 2)) {
			box.hidden = true;
			box.innerHTML = '';
			return;
		}
		box.hidden = false;
		const current = refTo(w);
		// The piece and, with several recipients, the one whose name fills the variables (1.26.0)
		box.innerHTML = ic('variable')
			+ (tpl.pieces.length >= 2 ? '<label for="' + w.id + '-vars">' + th('InboxVarsOf') + '</label>'
				+ '<select class="ibx-field ibx-field--medium ibx-js-varpiece" id="' + w.id + '-vars">' + tpl.pieces.map(pc => '<option value="' + esc(pc.type + ':' + pc.id) + '"' + (pc.type + ':' + pc.id === tpl.piece ? ' selected' : '') + '>' + esc(pc.kind + ' ' + pc.ref) + '</option>').join('') + '</select>' : '')
			+ (people.length >= 2 ? '<label for="' + w.id + '-varto">' + th('InboxVarsRecipient') + '</label>'
				+ '<select class="ibx-field ibx-field--medium ibx-js-varto" id="' + w.id + '-varto">' + people.map(r => '<option value="' + esc(r.email) + '"' + (r.email === current ? ' selected' : '') + '>' + esc(r.name ? r.name + ' <' + r.email + '>' : r.email) + '</option>').join('') + '</select>' : '');
		const pieceSel = $('.ibx-js-varpiece', box);
		if (pieceSel) {
			pieceSel.addEventListener('change', e => {
				tpl.piece = e.target.value;
				varsFollow(w, true);
			});
		}
		const toSel = $('.ibx-js-varto', box);
		if (toSel) {
			toSel.addEventListener('change', e => {
				w.varTo = e.target.value;
				varsFollow(w, true);
			});
		}
	}
	/** The chips follow the first recipient, the piece chosen and the identity (asked again only when one changed) */
	function varsFollow(w, force) {
		const tpl = w.tpl;
		if (!tpl || !w.editor || !w.editor.variables) {
			return;
		}
		// The list of the recipients changed: the choice of the one of the variables follows
		if (varRecipients(w).map(r => r.email).join(',') !== w.varsListKey) {
			paintVars(w);
		}
		const to = refTo(w);
		const identity = +$('.ibx-js-from', w.dlg).value;
		if (!force && to === tpl.to && identity === tpl.identity) {
			return;
		}
		tpl.to = to;
		tpl.identity = identity;
		clearTimeout(w.varsTimer);
		w.varsTimer = setTimeout(async () => {
			let values;
			try {
				values = (await post('compose', 'values', { compose: w.token, identity, piece: tpl.piece, to, lang: tpl.lang })).values;
			} catch (err) {
				return;
			}
			w.editor.setVariableValues(values);
			const subj = $('.ibx-js-subject', w.dlg);
			if (subj.value === tpl.subject && tpl.subjectRaw) {
				subj.value = tpl.subject = fillSubject(tpl.subjectRaw, values);
				$('.ibx-js-wtitle', w.dlg).textContent = titleOf(w);
			}
		}, 300);
	}
	/** The writing kept as a template: mine, or shared in the mailbox of the identity (managers) */
	function saveAsTemplate(w) {
		const subject = $('.ibx-js-subject', w.dlg).value;
		const body = '<form class="ibx-form" novalidate>'
			+ '<div class="ibx-formrow"><label class="ibx-formrow__label" for="' + w.id + '-tplname">' + th('InboxTemplateLabel') + '</label><div><input class="ibx-field" id="' + w.id + '-tplname" name="label" required maxlength="180" autocomplete="off" value="' + esc(subject) + '"></div></div>'
			+ '<div class="ibx-formrow"><span class="ibx-formrow__label">' + th('InboxTemplateFor') + '</span><div class="ibx-seg" role="radiogroup">'
			+ '<label class="ibx-seg__opt"><input type="radio" name="private" value="1" checked><span>' + th('InboxTemplateMine') + '</span></label>'
			+ '<label class="ibx-seg__opt"><input type="radio" name="private" value="0"><span>' + th('InboxTemplateShared', identityOf(w).mailbox || identityOf(w).email) + '</span></label></div></div>'
			+ '<p class="ibx-formrow__help">' + th('InboxTemplateSaveHelp') + '</p></form>';
		dialog({
			title: t('InboxTemplateSaveAs'),
			body,
			actions: [{ label: t('InboxSave'), primary: true, run: async dd => {
				const f = $('form', dd);
				const label = f.elements.label.value.trim();
				if (!label) {
					f.elements.label.focus();
					return false;
				}
				const priv = f.querySelector('[name="private"]:checked').value === '1';
				try {
					await post('template', 'save', { label, subject, html: w.sourceMode ? $('.ibx-compose__source', w.dlg).value : w.editor.getHtml(), private: priv ? 1 : 0, accounts: priv ? [] : [identityOf(w).account] });
				} catch (err) {
					toast(err.message, { error: true });
					return false;
				}
				w.templates = null;
				toast(t('InboxTemplateSaved', label));
			} }],
		});
	}

	// ---------- Documents of the linked pieces (lot W6 1.18.0) ----------
	async function documentsDialog(w) {
		let pieces;
		try {
			pieces = (await post('compose', 'documents', { compose: w.token })).pieces;
		} catch (err) {
			toast(err.message, { error: true });
			return;
		}
		if (!pieces.length) {
			toast(t('InboxComposeNoDocs'));
			return;
		}
		const have = new Set(w.attachments.map(a => a.name));
		const body = '<div class="ibx-docs">' + pieces.map(pc => '<fieldset class="ibx-docs__piece"><legend>' + ic(pc.icon) + ' ' + esc(pc.kind + ' ' + pc.ref) + '</legend>'
			+ pc.files.map(f => '<label class="ibx-check ibx-docs__file"><input type="checkbox" data-piece="' + esc(pc.type + ':' + pc.id) + '" value="' + esc(f.name) + '"' + (have.has(f.name) ? ' disabled' : '') + '> '
				+ '<span class="ibx-ellipsis">' + esc(f.name) + '</span>' + (f.name === pc.main ? ' <span class="ibx-badge ibx-badge--accent">' + th('InboxComposeMainDoc') + '</span>' : '')
				+ '<span class="ibx-muted">' + esc(size(f.size)) + '</span></label>').join('') + '</fieldset>').join('') + '</div>';
		dialog({
			title: t('InboxComposeAttachDocs'),
			wide: true,
			body,
			actions: [{ label: t('InboxComposeAttachThem'), primary: true, run: async dd => {
				const picked = $$('input[data-piece]:checked', dd);
				if (!picked.length) {
					return false;
				}
				for (const c of picked) {
					const tmp = { id: 'p' + (++seq), name: c.value, size: 0, pending: true };
					w.attachments.push(tmp);
					paintAttachments(w);
					try {
						const res = await post('compose', 'attachdoc', { compose: w.token, piece: c.dataset.piece, name: c.value });
						Object.assign(tmp, res.attachment, { pending: false });
					} catch (err) {
						w.attachments.splice(w.attachments.indexOf(tmp), 1);
						toast(c.value + ' : ' + err.message, { error: true });
					}
					paintAttachments(w);
				}
				w.changed();
			} }],
		});
	}

	/**
	 * Small menu over a button (the window is at the bottom of the screen): arrows, Escape, outside click
	 * @param {HTMLElement} anchor
	 * @param {object[]} items {label, icon, swatch (colour), checked, danger, run}
	 */
	function popMenu(anchor, items, at, below) {
		const box = document.createElement('div');
		box.className = 'ibx-menu';
		box.setAttribute('role', 'menu');
		box.innerHTML = items.map((it, i) => it.sep ? '<div class="ibx-menu__sep" role="separator"></div>'
			: it.note ? '<div class="ibx-menu__hint" role="note">' + esc(it.note) + '</div>'
			: it.header ? '<div class="ibx-menu__header" role="presentation">' + esc(it.header) + '</div>'
			: '<button type="button" class="ibx-menu__item' + (it.danger ? ' ibx-menu__item--danger' : '') + '" role="menuitem" data-i="' + i + '"' + (it.checked !== undefined ? ' aria-checked="' + it.checked + '"' : '') + '>'
			+ (it.swatch !== undefined ? '<span class="ibx-menu__icon ibx-swatch" data-swatch="' + esc(it.swatch) + '"></span>' : ic(it.icon, 'ibx-menu__icon')) + '<span class="ibx-menu__label">' + esc(it.label) + '</span>' + (it.checked ? ic('check') : '') + (it.key ? '<span class="ibx-menu__key">' + esc(it.key) + '</span>' : '') + (it.sub ? ic('chevron-right') : '') + '</button>').join('');
		// Colours set by the DOM (no style attribute in the HTML: CSP of the page)
		$$('[data-swatch]', box).forEach(el => {
			if (el.dataset.swatch) {
				el.style.background = el.dataset.swatch;
			} else {
				el.classList.add('ibx-swatch--none');
			}
		});
		document.body.appendChild(box);
		const r = anchor.getBoundingClientRect();
		box.style.position = 'fixed';
		box.style.zIndex = 90;
		if (at) {
			// At the pointer, inside the window of the browser
			box.style.left = Math.max(8, Math.min(at.x, window.innerWidth - box.offsetWidth - 8)) + 'px';
			box.style.top = Math.max(8, Math.min(at.y, window.innerHeight - box.offsetHeight - 8)) + 'px';
		} else {
			box.style.left = Math.max(8, Math.min(r.right - box.offsetWidth, window.innerWidth - box.offsetWidth - 8)) + 'px';
			box.style.top = (below && r.bottom + box.offsetHeight + 8 <= window.innerHeight ? r.bottom + 4 : Math.max(8, r.top - box.offsetHeight - 4)) + 'px';
		}
		const off = e => {
			if (!box.contains(e.target)) {
				done();
			}
		};
		const done = () => {
			box.remove();
			document.removeEventListener('pointerdown', off, true);
			anchor.setAttribute('aria-expanded', 'false');
		};
		anchor.setAttribute('aria-expanded', 'true');
		document.addEventListener('pointerdown', off, true);
		box.addEventListener('click', e => {
			const b = e.target.closest('[data-i]');
			if (b) {
				done();
				anchor.focus();
				items[+b.dataset.i].run();
			}
		});
		box.addEventListener('keydown', e => {
			const buttons = $$('.ibx-menu__item', box);
			const i = buttons.indexOf(document.activeElement);
			if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
				e.preventDefault();
				buttons[(i + (e.key === 'ArrowDown' ? 1 : buttons.length - 1)) % buttons.length].focus();
			} else if (e.key === 'Escape' || e.key === 'Tab') {
				e.preventDefault();
				e.stopPropagation();
				done();
				anchor.focus();
			}
		});
		$('.ibx-menu__item', box).focus();
	}

	function html(w) {
		const d = w.data;
		const ids = d.identities;
		const byBox = {};
		ids.forEach(i => {
			(byBox[i.mailbox] = byBox[i.mailbox] || []).push(i);
		});
		const from = Object.keys(byBox).length > 1
			? Object.keys(byBox).map(b => '<optgroup label="' + esc(b) + '">' + byBox[b].map(i => '<option value="' + i.id + '"' + (i.id === d.identity ? ' selected' : '') + '>' + esc(addressText(i)) + '</option>').join('') + '</optgroup>').join('')
			: ids.map(i => '<option value="' + i.id + '"' + (i.id === d.identity ? ' selected' : '') + '>' + esc(addressText(i)) + '</option>').join('');
		// One field per line: its name written in the empty line, a short prefix once filled (label kept for the screen readers)
		const label = (id, long, short) => '<label class="ibx-cfield__label" for="' + id + '"><span class="ibx-cfield__long">' + th(long) + '</span><span class="ibx-cfield__short">' + th(short) + '</span></label>';
		const rcpt = (kind, long, short, hidden) => '<div class="ibx-cfield ibx-cfield--rcpt" data-kind="' + kind + '"' + (hidden ? ' hidden' : '') + '>'
			+ '<span class="ibx-cfield__lead">' + (kind === 'to' ? '' : '<button type="button" class="ibx-cfield__x ibx-js-hiderow" data-hide="' + kind + '" title="' + th(kind === 'cc' ? 'InboxComposeRemoveCc' : 'InboxComposeRemoveBcc') + '" aria-label="' + th(kind === 'cc' ? 'InboxComposeRemoveCc' : 'InboxComposeRemoveBcc') + '">' + ic('close') + '</button>') + '</span>'
			+ label(w.id + '-' + kind, long, short)
			+ '<div class="ibx-rcpt"><input class="ibx-rcpt__input" id="' + w.id + '-' + kind + '" type="email" multiple inputmode="email" autocomplete="off" spellcheck="false" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="' + w.id + '-' + kind + '-sug"></div>'
			+ (kind === 'to' ? '<span class="ibx-cfield__extra"><button type="button" class="ibx-cfield__toggle ibx-js-showrow" data-show="cc"' + (d.cc.length ? ' hidden' : '') + ' title="' + th('InboxComposeAddCc') + '">' + th('InboxComposeCc') + '</button>'
				+ '<button type="button" class="ibx-cfield__toggle ibx-js-showrow" data-show="bcc"' + (d.bcc.length ? ' hidden' : '') + ' title="' + th('InboxComposeAddBcc') + '">' + th('InboxComposeBcc') + '</button></span>' : '')
			+ '<ul class="ibx-rcpt__sug" id="' + w.id + '-' + kind + '-sug" role="listbox" hidden></ul></div>';
		return '<div class="ibx-compose__grip ibx-compose__grip--n" data-grip="n" aria-hidden="true"></div><div class="ibx-compose__grip ibx-compose__grip--w" data-grip="w" aria-hidden="true"></div>'
			+ '<div class="ibx-compose__grip ibx-compose__grip--nw" data-grip="nw" aria-hidden="true"></div>'
			+ '<div class="ibx-window__bar"><span class="ibx-window__title ibx-ellipsis ibx-js-wtitle" id="' + w.id + '-title"></span>'
			+ '<button type="button" class="ibx-btn ibx-btn--icon ibx-btn--sm ibx-js-cmin ibx-compose__wbtn" title="' + th('InboxMinimize') + '" aria-label="' + th('InboxMinimize') + '">' + ic('minimize') + '</button>'
			+ '<button type="button" class="ibx-btn ibx-btn--icon ibx-btn--sm ibx-js-cmax ibx-compose__wbtn" title="' + th('InboxMaximize') + '" aria-label="' + th('InboxMaximize') + '">' + ic('maximize') + '</button>'
			+ '<button type="button" class="ibx-btn ibx-btn--icon ibx-btn--sm ibx-js-cclose" title="' + th('InboxComposeClose') + '" aria-label="' + th('InboxComposeClose') + '">' + ic('close') + '</button></div>'
			+ '<div class="ibx-window__body ibx-compose__body">'
			+ '<div class="ibx-cfield ibx-cfield--select" data-filled><span class="ibx-cfield__lead"></span>' + label(w.id + '-from', 'InboxFrom', 'InboxComposeShortFrom') + '<select class="ibx-cfield__input ibx-js-from" id="' + w.id + '-from">' + from + '</select>' + ic('chevron-down', 'ibx-cfield__caret') + '</div>'
			+ '<div class="ibx-compose__warn ibx-js-boxwarn" role="status" hidden></div>'
			+ '<div class="ibx-compose__warn ibx-compose__conflict ibx-js-conflict" role="alert" tabindex="-1" hidden></div>'
			+ rcpt('to', 'InboxTo', 'InboxComposeShortTo', false) + rcpt('cc', 'InboxComposeCcLabel', 'InboxComposeCc', !d.cc.length) + rcpt('bcc', 'InboxComposeBccLabel', 'InboxComposeBcc', !d.bcc.length)
			+ '<div class="ibx-cfield ibx-cfield--subject"' + (d.subject ? ' data-filled' : '') + '><span class="ibx-cfield__lead"></span>' + label(w.id + '-subject', 'InboxSubject', 'InboxSubject') + '<input class="ibx-cfield__input ibx-js-subject" aria-label="' + th('InboxSubject') + '" id="' + w.id + '-subject" type="text" autocomplete="off" maxlength="998" value="' + esc(d.subject) + '"></div>'
			+ '<div class="ibx-compose__tools" role="toolbar" aria-label="' + th('InboxFmtToolbar') + '" aria-controls="' + w.id + '-editor">'
			+ TOOLS.map(toolButton).join('')
			+ '</div>'
			+ '<div class="ibx-compose__vars ibx-js-vars" role="group" hidden></div>'
			+ '<div class="ibx-compose__dictation ibx-js-dictbar" role="status" hidden></div>'
			+ '<div class="ibx-compose__ai ibx-js-ai" role="region" aria-label="' + th('InboxAi') + '" hidden></div>'
			+ '<div class="ibx-compose__editor ibx-js-editor" id="' + w.id + '-editor"></div>'
			+ '<div class="ibx-compose__sig ibx-js-sig" hidden><iframe sandbox="allow-same-origin" title="' + th('InboxComposeSignature') + '"></iframe><button type="button" class="ibx-btn ibx-btn--icon ibx-btn--sm ibx-js-sigoff" title="' + th('InboxComposeSignatureOff') + '" aria-label="' + th('InboxComposeSignatureOff') + '">' + ic('close') + '</button></div>'
			+ '<button type="button" class="ibx-btn ibx-btn--sm ibx-btn--ghost ibx-js-sigadd" hidden>' + ic('signature') + ' ' + th('InboxComposeSignatureOn') + '</button>'
			+ (d.quote ? '<div class="ibx-compose__quote ibx-js-quote"><div class="ibx-compose__quotebar"><button type="button" class="ibx-btn ibx-btn--sm ibx-btn--ghost ibx-js-quotetoggle" aria-expanded="false">··· ' + th(w.mode === 'forward' ? 'InboxComposeShowForward' : 'InboxComposeShowQuote') + '</button>'
				+ '<button type="button" class="ibx-btn ibx-btn--sm ibx-btn--ghost ibx-js-quoteoff">' + th(w.mode === 'forward' ? 'InboxComposeRemoveForward' : 'InboxComposeRemoveQuote') + '</button></div>'
				// Remote pictures taken out of the quote by the server (lot F1 1.78.0): said, never a choice here
				+ quoteRemovedHtml(d.quote_removed)
				+ '<iframe sandbox="allow-same-origin" hidden title="' + th('InboxComposeQuote') + '"></iframe></div>'
				+ '<button type="button" class="ibx-btn ibx-btn--sm ibx-btn--ghost ibx-js-quoteon" hidden>' + th('InboxComposePutQuoteBack') + '</button>' : '')
			+ '<ul class="ibx-compose__atts ibx-js-atts" hidden aria-label="' + th('InboxAttachments') + '"></ul>'
			+ ((d.pieces || []).length ? '<div class="ibx-compose__record" role="group" aria-label="' + th('InboxRecordOn') + '">' + ic('event-new') + '<span class="ibx-muted">' + th('InboxRecordOn') + '</span>'
				+ d.pieces.map(pc => '<label class="ibx-check"><input type="checkbox" class="ibx-js-record" value="' + esc(pc.type + ':' + pc.id) + '"> ' + esc(pc.kind + ' ' + pc.ref) + '</label>').join('') + '</div>' : '')
			+ '<div class="ibx-compose__drop" hidden>' + ic('attachment') + ' ' + th('InboxComposeDropHere') + '</div>'
			+ '</div>'
			+ '<div class="ibx-window__foot ibx-compose__foot">'
			+ '<span class="ibx-sendsplit"><button type="button" class="ibx-btn ibx-btn--primary ibx-js-send"' + (params.send_shortcut === 'off' ? '' : ' title="' + th('InboxComposeSendKey') + '"') + '>' + ic('sent') + ' ' + th('InboxComposeSend') + '</button>'
			+ '<button type="button" class="ibx-btn ibx-btn--primary ibx-sendsplit__arrow ibx-js-sendlater" aria-haspopup="menu" aria-expanded="false" title="' + th('InboxSendLater') + '" aria-label="' + th('InboxSendLater') + '">' + ic('chevron-down') + '</button></span>'
			+ '<label class="ibx-btn ibx-btn--icon" title="' + th('InboxComposeAttach') + '">' + ic('attachment') + '<span class="ibx-sr-only">' + th('InboxComposeAttach') + '</span><input type="file" multiple class="ibx-sr-only ibx-js-file"></label>'
			+ ((d.linked || []).length ? '<button type="button" class="ibx-btn ibx-btn--icon ibx-js-docs" title="' + th('InboxComposeAttachDocs') + '" aria-label="' + th('InboxComposeAttachDocs') + '">' + ic('document') + '</button>' : '')
			+ '<button type="button" class="ibx-btn ibx-btn--icon ibx-js-templates" aria-haspopup="menu" aria-expanded="false" title="' + th('InboxTemplates') + '" aria-label="' + th('InboxTemplates') + '">' + ic('template') + '</button>'
			+ '<span class="ibx-compose__status ibx-js-status" role="status" aria-live="polite"></span>'
			+ '<span class="ibx-spacer"></span>'
			+ '<button type="button" class="ibx-btn ibx-btn--icon ibx-js-cmore" aria-haspopup="menu" aria-expanded="false" aria-label="' + th('InboxMoreActions') + '">' + ic('more') + '</button>'
			+ '<button type="button" class="ibx-btn ibx-btn--icon ibx-js-cdiscard" title="' + th('InboxComposeDiscard') + '" aria-label="' + th('InboxComposeDiscard') + '">' + ic('delete') + '</button>'
			+ '</div>';
	}

	function bind(w) {
		const dlg = w.dlg;
		const paintTitle = () => {
			$('.ibx-js-wtitle', dlg).textContent = titleOf(w);
		};
		w.changed = () => {
			w.dirty = true;
			clearTimeout(w.saveTimer);
			w.saveTimer = setTimeout(() => save(w), SAVE_MS);
			varsFollow(w);
		};
		dlg.addEventListener('pointerdown', () => front(w));
		$('.ibx-js-subject', dlg).addEventListener('input', e => {
			e.target.closest('.ibx-cfield').toggleAttribute('data-filled', e.target.value !== '');
			paintTitle();
			w.changed();
		});
		$('.ibx-js-from', dlg).addEventListener('change', () => {
			paintAiButton(w);
			paintDictateButton(w);
			paintSignature(w);
			paintBoxWarning(w);
			w.changed();
			// The templates offered follow the mailbox of the identity; the variables its name and address
			w.templates = null;
			varsFollow(w, true);
		});
		dlg.addEventListener('click', e => {
			const show = e.target.closest('.ibx-js-showrow');
			const hide = e.target.closest('.ibx-js-hiderow');
			if (show) {
				$('[data-kind="' + show.dataset.show + '"]', dlg).hidden = false;
				show.hidden = true;
				w[show.dataset.show].focus();
			} else if (hide) {
				// The field goes, with its addresses; « Undo » puts them back
				const kind = hide.dataset.hide;
				const old = w[kind].clear();
				$('[data-kind="' + kind + '"]', dlg).hidden = true;
				$('[data-show="' + kind + '"]', dlg).hidden = false;
				w.to.focus();
				if (old.length) {
					w.changed();
					toast(t(kind === 'cc' ? 'InboxComposeCcRemoved' : 'InboxComposeBccRemoved', old.length), { action: t('InboxUndo'), onAction: () => {
						$('[data-kind="' + kind + '"]', dlg).hidden = false;
						$('[data-show="' + kind + '"]', dlg).hidden = true;
						w[kind].add(old);
					} });
				}
			}
		});
		$('.ibx-compose__tools', dlg).addEventListener('click', e => {
			const b = e.target.closest('[data-cmd]');
			if (!b || (w.sourceMode && b.dataset.cmd !== 'source')) {
				return;
			}
			const cmd = b.dataset.cmd;
			const ed = w.editor;
			if (cmd === 'link') {
				linkDialog(w);
			} else if (cmd === 'source') {
				toggleSource(w);
			} else if (cmd === 'ai') {
				aiToggle(w);
			} else if (cmd === 'dictate') {
				dictateToggle(w);
			} else if (cmd === 'colorApply' || cmd === 'backgroundApply') {
				const kind = cmd === 'colorApply' ? 'color' : 'background';
				ed.exec(kind, ed.styleOf && ed.styleOf(kind) ? '' : colorState()[kind]);
				paintColorButtons(w);
			} else if (cmd === 'color' || cmd === 'background') {
				colorMenu(w, b, cmd);
			} else if (cmd === 'align') {
				popMenu(b, ['left', 'center', 'right'].map(v => ({ label: t('InboxFmtAlign_' + v), icon: 'fmt-align-' + v, checked: ed.isActive('align', v), run: () => ed.exec('align', v) })), null, true);
			} else if (cmd === 'table') {
				// Out of a table the button inserts one at once; in a table it opens the menu of the table
				if (ed.isActive('table')) {
					popMenu(b, tableItems(w), null, true);
				} else {
					ed.exec('tableInsert');
				}
			} else {
				ed.exec(cmd);
			}
		});
		$('.ibx-js-sigoff', dlg).addEventListener('click', () => {
			w.signature = false;
			paintSignature(w);
			w.changed();
		});
		$('.ibx-js-sigadd', dlg).addEventListener('click', () => {
			w.signature = true;
			paintSignature(w);
			w.changed();
		});
		const q = $('.ibx-js-quote', dlg);
		if (q) {
			$('.ibx-js-quotetoggle', q).addEventListener('click', e => {
				const f = $('iframe', q);
				f.hidden = !f.hidden;
				if (!f.hidden && !f.srcdoc) {
					f.srcdoc = srcdoc(w.data.quote);
				}
				e.currentTarget.setAttribute('aria-expanded', String(!f.hidden));
			});
			$('.ibx-js-quoteoff', q).addEventListener('click', () => {
				w.quote = false;
				q.hidden = true;
				$('.ibx-js-quoteon', dlg).hidden = false;
				w.changed();
			});
			$('.ibx-js-quoteon', dlg).addEventListener('click', e => {
				w.quote = true;
				q.hidden = false;
				e.currentTarget.hidden = true;
				w.changed();
			});
		}
		$('.ibx-js-file', dlg).addEventListener('change', e => {
			addFiles(w, e.target.files);
			e.target.value = '';
		});
		$('.ibx-js-atts', dlg).addEventListener('click', async e => {
			const b = e.target.closest('.ibx-js-unattach');
			if (!b) {
				return;
			}
			try {
				await post('compose', 'unattach', { compose: w.token, id: b.dataset.id });
				w.attachments = w.attachments.filter(a => a.id !== b.dataset.id);
				paintAttachments(w);
				w.changed();
			} catch (err) {
				toast(err.message, { error: true });
			}
		});
		// Files dropped on the window: attachments
		let depth = 0;
		const drop = $('.ibx-compose__drop', dlg);
		dlg.addEventListener('dragenter', e => {
			if (e.dataTransfer && Array.from(e.dataTransfer.types || []).includes('Files')) {
				depth++;
				drop.hidden = false;
			}
		});
		dlg.addEventListener('dragleave', () => {
			depth = Math.max(0, depth - 1);
			if (!depth) {
				drop.hidden = true;
			}
		});
		dlg.addEventListener('dragover', e => {
			if (e.dataTransfer && Array.from(e.dataTransfer.types || []).includes('Files')) {
				e.preventDefault();
			}
		});
		dlg.addEventListener('drop', e => {
			if (e.dataTransfer && e.dataTransfer.files.length) {
				e.preventDefault();
				depth = 0;
				drop.hidden = true;
				addFiles(w, e.dataTransfer.files);
			}
		});
		$('.ibx-js-send', dlg).addEventListener('click', () => send(w));
		$('.ibx-js-sendlater', dlg).addEventListener('click', e => laterMenu(w, e.currentTarget));
		$('.ibx-js-cdiscard', dlg).addEventListener('click', () => discard(w));
		$('.ibx-js-cmore', dlg).addEventListener('click', e => moreMenu(w, e.currentTarget));
		$('.ibx-js-templates', dlg).addEventListener('click', e => templateMenu(w, e.currentTarget));
		const docs = $('.ibx-js-docs', dlg);
		if (docs) {
			docs.addEventListener('click', () => documentsDialog(w));
		}
		$('.ibx-js-cclose', dlg).addEventListener('click', () => close(w));
		$('.ibx-js-cmin', dlg).addEventListener('click', () => minimize(w));
		$('.ibx-js-cmax', dlg).addEventListener('click', () => {
			dlg.classList.toggle('ibx-window--maximized');
			place();
		});
		dlg.addEventListener('keydown', e => {
			if ((e.ctrlKey || e.metaKey) && e.key === 'Enter' && params.send_shortcut !== 'off') {
				e.preventDefault();
				send(w);
			} else if ((e.ctrlKey || e.metaKey) && (e.key === 'k' || e.key === 'K') && e.target.closest('.ibx-compose__editor')) {
				e.preventDefault();
				linkDialog(w);
			} else if ((e.ctrlKey || e.metaKey) && (e.key === 's' || e.key === 'S')) {
				e.preventDefault();
				save(w, true);
			}
			// Keys of the list never act from inside a writing
			e.stopPropagation();
		});
		dlg.addEventListener('cancel', e => {
			e.preventDefault();
		});
		// The bar moves the window (pointer); a moved window keeps its place
		const bar = $('.ibx-window__bar', dlg);
		bar.addEventListener('pointerdown', e => {
			if (e.target.closest('button') || dlg.classList.contains('ibx-window--maximized') || window.innerWidth < 640) {
				return;
			}
			const r = dlg.getBoundingClientRect();
			const dx = e.clientX - r.left;
			const dy = e.clientY - r.top;
			bar.setPointerCapture(e.pointerId);
			const move = ev => {
				w.moved = true;
				dlg.style.right = 'auto';
				dlg.style.bottom = 'auto';
				dlg.style.left = Math.max(0, Math.min(ev.clientX - dx, window.innerWidth - 120)) + 'px';
				dlg.style.top = Math.max(0, Math.min(ev.clientY - dy, window.innerHeight - 40)) + 'px';
			};
			const up = () => {
				bar.removeEventListener('pointermove', move);
				bar.removeEventListener('pointerup', up);
			};
			bar.addEventListener('pointermove', move);
			bar.addEventListener('pointerup', up);
		});
		paintTitle();
		// Size from the top, the left, or the top left corner; kept for the next writings
		$$('.ibx-compose__grip', dlg).forEach(g => g.addEventListener('pointerdown', e => {
			if (dlg.classList.contains('ibx-window--maximized')) {
				return;
			}
			e.preventDefault();
			const r0 = dlg.getBoundingClientRect();
			const x0 = e.clientX;
			const y0 = e.clientY;
			const dir = g.dataset.grip;
			g.setPointerCapture(e.pointerId);
			const move = ev => {
				if (dir.includes('w')) {
					const width = Math.max(320, Math.min(r0.right - 8, r0.width + x0 - ev.clientX));
					w.width = Math.round(width);
					dlg.style.width = w.width + 'px';
					if (w.moved) {
						dlg.style.left = (r0.right - width) + 'px';
					}
				}
				if (dir.includes('n')) {
					const height = Math.max(260, Math.min(r0.bottom - 8, r0.height + y0 - ev.clientY));
					w.height = Math.round(height);
					dlg.style.height = w.height + 'px';
					if (w.moved) {
						dlg.style.top = (r0.bottom - height) + 'px';
					}
				}
			};
			const up = () => {
				g.removeEventListener('pointermove', move);
				g.removeEventListener('pointerup', up);
				if (w.width || w.height) {
					const size = { w: w.width || Math.round(r0.width), h: w.height || Math.round(r0.height) };
					params.compose_size = JSON.stringify(size);
					post('userparam', 'set', { param: 'compose_size', value: params.compose_size }).catch(() => {});
				}
				place();
			};
			g.addEventListener('pointermove', move);
			g.addEventListener('pointerup', up);
		}));
	}

	/**
	 * Open a writing
	 * @param {object} o {mode: new|reply|replyall|replylist|forward|forward_attach|draft, mail, account, to, cc, subject}
	 * @returns {Promise<object|null>}
	 */
	async function compose(o) {
		o = o || {};
		const mode = o.mode || 'new';
		const same = o.mail ? writings.find(x => x.source === o.mail && x.mode === mode) : null;
		if (same) {
			restore(same);
			return same;
		}
		let d = o.restore || null;
		if (!d) {
			try {
				d = await post('compose', 'open', { mode, mail: o.mail || 0, account: o.account || 0 });
			} catch (err) {
				toast(err.message, { error: true });
				return null;
			}
		}
		if (o.to) {
			d.to = d.to.concat(parseList(o.to));
		}
		if (o.cc) {
			d.cc = d.cc.concat(parseList(o.cc));
		}
		if (o.bcc) {
			d.bcc = d.bcc.concat(parseList(o.bcc));
		}
		if (o.subject && !d.subject) {
			d.subject = o.subject;
		}
		if (o.html && !d.html) {
			d.html = o.html;
		} else if (o.body && !d.html) {
			d.html = String(o.body).split(/\r?\n/).map(l => '<p>' + esc(l) + '</p>').join('');
		}
		const w = { id: 'ibx-cw' + (++seq), token: d.compose || d.token, mode: d.mode || mode, source: o.mail || (d.source ? d.source.mail : 0), data: d, attachments: (d.attachments || []).slice(),
			quote: !!d.quote && !d.quote_off, signature: !d.signature_off, receipt: !!d.receipt, dirty: !!o.restore, saved: !!d.draft };
		w.token = d.token;
		const dlg = document.createElement('dialog');
		dlg.className = 'ibx-window ibx-compose';
		dlg.setAttribute('aria-labelledby', w.id + '-title');
		w.dlg = dlg;
		dlg.innerHTML = html(w);
		document.body.appendChild(dlg);
		I.mount(dlg);
		w.to = recipients(w, 'to', d.to);
		w.cc = recipients(w, 'cc', d.cc);
		w.bcc = recipients(w, 'bcc', d.bcc);
		[$('.ibx-js-sig iframe', dlg), $('.ibx-js-quote iframe', dlg)].filter(Boolean).forEach(fitFrame);
		bind(w);
		writings.push(w);
		dlg.show();
		front(w);
		place();
		paintSignature(w);
		paintAttachments(w);
		paintBoxWarning(w);
		if (d.quote && d.quote_off) {
			const q = $('.ibx-js-quote', dlg);
			q.hidden = true;
			$('.ibx-js-quoteon', dlg).hidden = false;
		}
		try {
			w.editor = await I.editors.create($('.ibx-js-editor', dlg), { html: d.html || '', placeholder: t('InboxComposePlaceholder'), label: t('InboxComposeBody'), varToText: true });
		} catch (e) {
			status(w, t('InboxComposeEditorFailed'), true);
			w.editor = { getHtml: () => '', isEmpty: () => true, destroy() {}, focus() {}, exec() {}, isActive: () => false, on() {} };
		}
		paintColorButtons(w);
		paintTableButton(w);
		// A draft with variables of a template: they follow the recipient again (piece: the newest linked one)
		if (w.editor.variables && w.editor.variables().length) {
			const linked = d.linked || [];
			w.tpl = { id: 0, lang: '', piece: linked.length ? linked[0].type + ':' + linked[0].id : '', pieces: linked, kind: '', subjectRaw: '', subject: null, to: refTo(w), identity: +$('.ibx-js-from', dlg).value };
			paintVars(w);
		}
		if (w.editor.on) {
			w.editor.on('notice', n => {
				if (n && n.code === 'picture_too_big') {
					toast(t('InboxComposePictureTooBig', n.name || '', '5 MB'), { error: true });
				}
			});
		}
		const edBox = $('.ibx-js-editor', dlg);
		edBox.addEventListener('contextmenu', e => {
			// Shift: the menu of the browser, always (interface §6 bis); outside a table too (spelling)
			if (e.shiftKey || !e.target.closest('td, th')) {
				return;
			}
			e.preventDefault();
			w.editor.placeAt(e.clientX, e.clientY);
			textMenu(w, e.clientX, e.clientY);
		});
		edBox.addEventListener('keydown', e => {
			if (e.key === 'ContextMenu' || (e.shiftKey && e.key === 'F10')) {
				e.preventDefault();
				const box = w.editor.selectionBox();
				textMenu(w, box ? box.left : 100, box ? box.bottom + 4 : 100);
			}
		});
		['mouseup', 'keyup'].forEach(ev => edBox.addEventListener(ev, () => setTimeout(() => bubble(w), 0)));
		w.editor.on('blur', () => setTimeout(() => bubble(w), 150));
		$('.ibx-compose__body', dlg).addEventListener('scroll', () => bubble(w), { passive: true });
		// A click in the empty space of the writing (under the signature): the cursor at the end of the text
		$('.ibx-compose__body', dlg).addEventListener('mousedown', e => {
			if (e.target === e.currentTarget) {
				e.preventDefault();
				w.editor.focus('end');
			}
		});
		paintAiButton(w);
		paintDictateButton(w);
		// Escape stops the voice typing
		dlg.addEventListener('keydown', e => {
			if (e.key === 'Escape' && w.rec) {
				e.stopPropagation();
				dictateStop(w);
			}
		}, true);
		w.editor.on('change', ev => {
			paintColorButtons(w);
			paintTableButton(w);
			const al = $('[data-cmd="align"] .ibx-tbtn__icon', dlg);
			const now = ['center', 'right'].find(v => w.editor.isActive('align', v)) || 'left';
			if (al && al.dataset.align !== now) {
				al.dataset.align = now;
				al.innerHTML = ic('fmt-align-' + now);
			}
			if ((w.bubble && !w.bubble.hidden) || (w.imgBubble && !w.imgBubble.hidden)) {
				bubble(w);
			}
			$$('.ibx-compose__tools [aria-pressed]:not([data-cmd="source"]):not(.ibx-splitbtn__main)', dlg).forEach(b => b.setAttribute('aria-pressed', String(w.editor.isActive(b.dataset.cmd))));
			if (!ev || !ev.selection) {
				w.changed();
			}
		});
		// The cursor where the user writes: the first empty field, else the text
		if (!d.to.length && mode !== 'draft' && mode !== 'editnew') {
			w.to.focus();
		} else {
			w.editor.focus('start');
		}
		Dolibarr.executeHook('inbox:compose:open', { mode, source: w.source, token: w.token });
		return w;
	}

	// A writing is never lost: saved when the tab goes to the background, a warning when the tab closes unsaved
	document.addEventListener('visibilitychange', () => {
		if (document.visibilityState === 'hidden') {
			writings.forEach(w => save(w));
		}
	});
	window.addEventListener('beforeunload', e => {
		if (writings.some(w => (w.dirty && hasContent(w)) || w.saving || w.sending)) {
			e.preventDefault();
			e.returnValue = '';
		}
	});
	window.addEventListener('resize', () => place());

	I.compose = compose;
	/**
	 * A mailto: link (RFC 6068) written in inbox: to, cc, bcc, subject, body
	 * @param {string} href
	 * @returns {Promise<object|null>}
	 */
	I.composeMailto = href => {
		const m = String(href || '').match(/^mailto:([^?]*)(?:\?(.*))?$/i);
		if (!m) {
			return Promise.resolve(null);
		}
		const o = { mode: 'new', to: decodeURIComponent(m[1] || '') };
		new URLSearchParams(m[2] || '').forEach((v, k) => {
			k = k.toLowerCase();
			if (k === 'to') {
				o.to = (o.to ? o.to + ',' : '') + v;
			} else if (['cc', 'subject', 'body'].includes(k)) {
				// No « bcc »: a link never adds a hidden recipient (audit INBX-029)
				o[k] = v;
			}
		});
		return compose(o);
	};
	/** Mail answered in the writing in front (presence « is writing an answer », lot W3 call of state) */
	I.composeWriting = () => {
		const w = writings.find(x => x.dlg.classList.contains('is-front') && x.source && x.mode !== 'draft' && x.mode !== 'unsubscribe' && !x.closed);
		return w ? w.source : 0;
	};
})();
