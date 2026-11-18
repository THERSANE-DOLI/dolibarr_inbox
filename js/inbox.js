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
 * inbox application: tool Dolibarr.tools.inbox of the JavaScript context of Dolibarr (principle P30).
 * - values from the context (Dolibarr.getContextVar), translations by Dolibarr.tools.langs, no PHP here, no jQuery;
 * - common ajax call: CSRF token, readable errors, session expired = reconnection dialog then the call again;
 * - toasts with "Undo", dialogs (<dialog>, Cancel on the left, main action on the right), menus;
 * - router: the address follows the screen (#/mail, #/settings/...), each space keeps its state (interface §2 ter);
 * - coordination of the tabs (BroadcastChannel): a change made in a tab is shown in the others;
 * - hooks of inbox for the other modules: Dolibarr.executeHook('inbox:<object>:<action>', data).
 */
(function () {
	'use strict';

	const ctx = (key, fallback) => Dolibarr.getContextVar(key, fallback);
	/** Context value holding JSON text (the context only takes strings, numbers, booleans) */
	const ctxJson = (key, fallback) => {
		try {
			return JSON.parse(ctx(key, '')) || fallback;
		} catch (e) {
			return fallback;
		}
	};
	/** Locale of the language of Dolibarr (fr-FR, en-US...): dates and numbers, never the language of the browser */
	const locale = () => (ctx('MAIN_LANG_DEFAULT', 'en_US') || 'en_US').replace('_', '-');
	/** Size of a file in the units of the language (o/Ko/Mo/Go in French, B/KB/MB/GB in English...) */
	const size = bytes => {
		const units = t('InboxSizeUnits');
		const u = (units && units !== 'InboxSizeUnits' ? units : 'B|KB|MB|GB|TB').split('|');
		let n = +bytes || 0, i = 0;
		while (n >= 1024 && i < u.length - 1) {
			n /= 1024;
			i++;
		}
		return (i < 2 ? Math.max(i ? 1 : 0, Math.round(n)).toLocaleString(locale()) : n.toLocaleString(locale(), { maximumFractionDigits: 1 })) + '\u00a0' + u[i];
	};
	/** Rights of the user, from the flat keys of the context */
	const rights = () => ({ webmail: !!ctx('INBOX_RIGHT_WEBMAIL', false), account_admin: !!ctx('INBOX_RIGHT_ACCOUNT_ADMIN', false), dolibarr_admin: !!ctx('INBOX_RIGHT_DOLIBARR_ADMIN', false), trusted_domain: !!ctx('INBOX_RIGHT_TRUSTED_DOMAIN', false), blocked_sender: !!ctx('INBOX_RIGHT_BLOCKED_SENDER', false) });
	const $ = (sel, root) => (root || document).querySelector(sel);
	const $$ = (sel, root) => Array.from((root || document).querySelectorAll(sel));

	/** Escape a text for HTML */
	function esc(text) {
		return String(text === null || text === undefined ? '' : text).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
	}

	/**
	 * HTML made by html`` or marked by raw(): put as it is in another html``; its string is the HTML (innerHTML,
	 * concatenation and insertAdjacentHTML take it as a string)
	 */
	class SafeHtml {
		constructor(text) {
			this.text = text;
		}
		toString() {
			return this.text;
		}
	}

	/**
	 * HTML already safe, put as it is in html`` (output of th(), ic(), of a function that builds escaped HTML). Never a
	 * value that comes from a mail, a user or the server without escaping
	 * @param {string} text HTML
	 * @returns {SafeHtml}
	 */
	function raw(text) {
		return new SafeHtml(text === null || text === undefined ? '' : String(text));
	}

	/** One value of html``: escaped, unless it is SafeHtml; a list joined; null, undefined, true and false give nothing */
	function htmlPart(value) {
		if (value instanceof SafeHtml) {
			return value.text;
		}
		if (Array.isArray(value)) {
			return value.map(htmlPart).join('');
		}
		if (value === null || value === undefined || typeof value === 'boolean') {
			return '';
		}
		return esc(value);
	}

	/**
	 * Tagged template that escapes every value: I.html`<b>${name}</b>${I.raw(ic('mail'))}`. Forgetting esc() is no
	 * longer possible; HTML is let through only with raw() or another html``. ${cond && I.html`...`} shows nothing
	 * when cond is false
	 * @param {TemplateStringsArray} strings Parts of the template
	 * @param {...*} values Values
	 * @returns {SafeHtml}
	 */
	function html(strings, ...values) {
		let out = strings[0];
		values.forEach((v, i) => {
			out += htmlPart(v) + strings[i + 1];
		});
		return new SafeHtml(out);
	}

	/** Translation (keys of langs/xx_XX/inbox.lang); escaped by default for HTML */
	function t(key, ...args) {
		const langs = Dolibarr.tools && Dolibarr.tools.langs;
		const text = langs ? langs.transNoEntities(key, ...args) : key;
		return text;
	}
	function th(key, ...args) {
		return esc(t(key, ...args));
	}

	/** Icon of the sprite by its usage name (never the Tabler name, principle P21) */
	function ic(usage, cls) {
		return '<svg class="ibx-icon' + (cls ? ' ' + cls : '') + '" aria-hidden="true"><use href="' + esc(ctx('INBOX_ICONS_URL', '')) + '#ibx-i-' + esc(usage) + '"/></svg>';
	}

	// ---------- Ajax ----------
	let token = ctx('INBOX_TOKEN', '');
	/**
	 * Number of this tab (1.121.0), sent with every call (header X-Inbox-Origin) and written with the changes it makes:
	 * the quicker refresh does not apply again in this tab what it has just shown
	 */
	const origin = (() => {
		try {
			return crypto.randomUUID().replace(/-/g, '').slice(0, 16);
		} catch (e) {
			return Math.random().toString(36).slice(2, 18);
		}
	})();
	let reconnecting = null;
	/**
	 * Entity of Dolibarr this page was loaded in (multicompany), sent with every call (header X-Inbox-Entity). The
	 * entity is the one of the session: changed in another tab of Dolibarr, the server answers 409 entity_changed and
	 * the page asks to be loaded again (entityChanged), nothing is read nor written meanwhile.
	 */
	const entity = String(ctx('INBOX_ENTITY', ''));
	let entityLost = false;
	/** Headers of every call of inbox */
	const headers = () => ({ 'Accept': 'application/json', 'X-Inbox-Origin': origin, 'X-Inbox-Entity': entity });

	/**
	 * Call an endpoint of inbox
	 * @param {string} endpoint  File of ajax/ without .php (account, access...)
	 * @param {string} action    Action
	 * @param {object} data      Values (GET: query, POST: JSON body with the token)
	 * @param {object} opts      {method: 'GET'|'POST'}
	 * @returns {Promise<object>} Answer; rejects with an Error having .status, .fields
	 */
	async function api(endpoint, action, data, opts) {
		if (entityLost) {
			// The page waits to be loaded again: no call, no error shown
			return new Promise(() => {});
		}
		const method = (opts && opts.method) || (data && data.__post ? 'POST' : 'GET');
		const payload = Object.assign({}, data || {});
		delete payload.__post;
		let url = ctx('INBOX_AJAX_URL', '') + endpoint + '.php';
		const init = { method, credentials: 'same-origin', headers: headers() };
		if (method === 'GET') {
			const qs = new URLSearchParams(Object.assign({ action }, payload));
			url += '?' + qs.toString();
		} else {
			init.headers['Content-Type'] = 'application/json';
			init.body = JSON.stringify(Object.assign({ action, token }, payload));
		}
		let resp;
		try {
			resp = await fetch(url, init);
		} catch (e) {
			const err = new Error(t('InboxErrorOffline'));
			err.status = 0;
			throw err;
		}
		checkVersion(resp.headers.get('X-Inbox-Version'));
		let json = null;
		try {
			json = await resp.json();
		} catch (e) {
			json = null;
		}
		if (resp.status === 409 && json && json.error === 'entity_changed') {
			return entityChanged(json);
		}
		if (resp.status === 401 || (resp.status === 403 && json && json.error === 'bad_token')) {
			// Session expired (or token renewed by a reconnection elsewhere): reconnect, then the same call again
			await reconnect(resp.status === 401);
			return api(endpoint, action, data, opts);
		}
		if (!resp.ok || !json || json.error) {
			const err = new Error((json && json.error) || t('InboxErrorServer', resp.status));
			err.status = resp.status;
			err.fields = (json && json.fields) || {};
			// Key of the error and the data that go with it (a confirmation asked, a count)
			err.code = (json && json.code) || '';
			err.data = json || {};
			throw err;
		}
		return json;
	}
	function post(endpoint, action, data) {
		return api(endpoint, action, Object.assign({ __post: 1 }, data || {}));
	}

	/**
	 * The entity of the session changed (another tab of Dolibarr): the mailboxes shown are no longer the ones of the
	 * session. Every call stops (the pending ones never end, nothing is shown twice), the quick refresh stops, a dialog
	 * that cannot be closed offers to load inbox again in the new entity.
	 * @param {object} json {entity, label}
	 * @returns {Promise<never>}
	 */
	function entityChanged(json) {
		if (!entityLost) {
			entityLost = true;
			Dolibarr.executeHook('inbox:entity:changed', { entity: json.entity || 0 });
			const name = String(json.label || '');
			dialog({
				title: t('InboxEntityChanged'),
				body: html`<p>${name ? t('InboxEntityChangedTo', name) : t('InboxEntityChangedText')}</p><p>${t('InboxEntityChangedBack')}</p>`,
				actions: [{ label: t('InboxReload'), primary: true, run: () => {
					window.location.reload();
					return false;
				} }],
				noCancel: true,
			});
		}
		return new Promise(() => {});
	}

	/**
	 * Inbox updated on the server while this page is open (1.58.0): a bar offers to reload, once. Never reloaded by
	 * itself: a mail being written stays (the writing asks before leaving when it is not saved)
	 * @param {string|null} server Version sent by the server (X-Inbox-Version)
	 */
	let updateShown = false;
	function checkVersion(server) {
		const loaded = String(ctx('INBOX_VERSION', ''));
		if (updateShown || !server || !loaded || server === loaded) {
			return;
		}
		updateShown = true;
		const bar = document.createElement('div');
		bar.className = 'ibx-updatebar';
		bar.setAttribute('role', 'status');
		bar.innerHTML = ic('info') + '<span>' + esc(t('InboxUpdated', server)) + '</span>'
			+ '<button type="button" class="ibx-btn ibx-btn--sm ibx-btn--primary ibx-js-updatereload">' + esc(t('InboxReload')) + '</button>'
			+ '<button type="button" class="ibx-btn ibx-btn--sm ibx-btn--icon ibx-js-updateclose" aria-label="' + esc(t('InboxLater')) + '" title="' + esc(t('InboxLater')) + '">' + ic('close') + '</button>';
		$('.ibx-js-updatereload', bar).addEventListener('click', () => window.location.reload());
		$('.ibx-js-updateclose', bar).addEventListener('click', () => bar.remove());
		document.body.appendChild(bar);
	}

	/**
	 * Inbox updated but not activated again (1.85.0): the administrators are told; the page of the modules of
	 * Dolibarr is given to the administrators of Dolibarr only (the others ask one). Never done from here. Closed for
	 * the session of the browser.
	 */
	function updatePendingBar() {
		const pending = String(ctx('INBOX_UPDATE_PENDING', '')).split(' ');
		if (pending.length !== 2) {
			return;
		}
		try {
			if (sessionStorage.getItem(scope('inbox-update-pending')) === pending[1]) {
				return;
			}
		} catch (e) {
			// private window: shown again, harmless
		}
		const url = String(ctx('INBOX_MODULES_URL', ''));
		const bar = document.createElement('div');
		bar.className = 'ibx-updatebar ibx-updatebar--warning';
		bar.setAttribute('role', 'alert');
		bar.innerHTML = ic('warning') + '<span>' + esc(t('InboxUpdatePending', pending[0], pending[1])) + ' ' + esc(t(url ? 'InboxUpdatePendingAdmin' : 'InboxUpdatePendingAsk')) + '</span>'
			+ (url ? '<a class="ibx-btn ibx-btn--sm ibx-btn--primary" href="' + esc(url) + '">' + esc(t('InboxUpdatePendingGo')) + '</a>' : '')
			+ '<button type="button" class="ibx-btn ibx-btn--sm ibx-btn--icon ibx-js-updateclose" aria-label="' + esc(t('InboxLater')) + '" title="' + esc(t('InboxLater')) + '">' + ic('close') + '</button>';
		$('.ibx-js-updateclose', bar).addEventListener('click', () => {
			try {
				sessionStorage.setItem(scope('inbox-update-pending'), pending[1]);
			} catch (e) {
				// not kept
			}
			bar.parentNode.removeChild(bar);
		});
		document.body.appendChild(bar);
	}

	/**
	 * Send a file (multipart POST, the token added): attachments of a writing (lot W6)
	 * @param {string} endpoint File of ajax/ without .php
	 * @param {string} action   Action
	 * @param {object} fields   Values
	 * @param {File}   file     File (field « file »)
	 * @returns {Promise<object>}
	 */
	async function upload(endpoint, action, fields, file) {
		if (entityLost) {
			return new Promise(() => {});
		}
		const form = new FormData();
		form.append('action', action);
		form.append('token', token);
		Object.keys(fields || {}).forEach(k => form.append(k, fields[k]));
		form.append('file', file, file.name);
		let resp;
		try {
			resp = await fetch(ctx('INBOX_AJAX_URL', '') + endpoint + '.php', { method: 'POST', credentials: 'same-origin', headers: headers(), body: form });
		} catch (e) {
			const err = new Error(t('InboxErrorOffline'));
			err.status = 0;
			throw err;
		}
		checkVersion(resp.headers.get('X-Inbox-Version'));
		let json = null;
		try {
			json = await resp.json();
		} catch (e) {
			json = null;
		}
		if (resp.status === 409 && json && json.error === 'entity_changed') {
			return entityChanged(json);
		}
		if (resp.status === 401 || (resp.status === 403 && json && json.error === 'bad_token')) {
			await reconnect(resp.status === 401);
			return upload(endpoint, action, fields, file);
		}
		if (!resp.ok || !json || json.error) {
			const err = new Error((json && json.error) || t('InboxErrorServer', resp.status));
			err.status = resp.status;
			err.code = (json && json.code) || '';
			err.data = json || {};
			throw err;
		}
		return json;
	}

	/**
	 * Reconnection after the session expired: dialog, the login page of Dolibarr in a window, then a new token.
	 * One dialog for all the calls waiting; the other tabs are told.
	 */
	function reconnect(expired) {
		if (reconnecting) {
			return reconnecting;
		}
		reconnecting = (async () => {
			if (expired) {
				channel.post({ type: 'session', state: 'expired' });
				await new Promise(resolve => {
					const dlg = dialog({
						title: t('InboxSessionExpired'),
						body: '<p>' + th('InboxSessionExpiredText') + '</p>',
						actions: [{ label: t('InboxReconnect'), primary: true, run: () => {
							window.open(ctx('INBOX_DOLIBARR_URL', '/'), 'inbox-login', 'width=520,height=640');
							waitSession().then(() => {
								dlg.close();
								resolve();
							});
							return false;
						} }],
						noCancel: true,
					});
				});
			}
			const json = await fetch(ctx('INBOX_AJAX_URL', '') + 'session.php', { credentials: 'same-origin' }).then(r => r.json());
			token = json.token;
			channel.post({ type: 'session', state: 'ok', token });
		})().finally(() => {
			reconnecting = null;
		});
		return reconnecting;
	}
	async function waitSession() {
		for (;;) {
			await new Promise(r => setTimeout(r, 2000));
			try {
				const r = await fetch(ctx('INBOX_AJAX_URL', '') + 'session.php', { credentials: 'same-origin' });
				if (r.ok) {
					return;
				}
			} catch (e) {
				// still offline
			}
		}
	}

	// ---------- Toasts ----------
	/**
	 * @param {string} text
	 * @param {object} o {action: label, onAction: fn, error: bool, duration: ms}
	 */
	function toast(text, o) {
		o = o || {};
		const box = $('#ibx-toasts');
		// A toast with a key (zoom, text size...) is updated in place: one toast, its delay started again
		if (o.key) {
			const same = $('[data-toast-key="' + o.key + '"]', box);
			if (same && same._ibxTimer) {
				$('span', same).textContent = text;
				clearTimeout(same._ibxTimer);
				same._ibxTimer = setTimeout(() => same.remove(), o.duration || 4000);
				return same;
			}
		}
		const el = document.createElement('div');
		el.className = 'ibx-toast' + (o.error ? ' ibx-toast--error' : '');
		el.setAttribute('role', o.error ? 'alert' : 'status');
		el.innerHTML = '<span>' + esc(text) + '</span>' + (o.action ? '<button type="button" class="ibx-btn ibx-btn--sm ibx-js-toastaction">' + esc(o.action) + '</button>' : '')
			+ '<button type="button" class="ibx-btn ibx-btn--sm ibx-btn--icon ibx-js-toastclose" aria-label="' + th('InboxCloseWindow') + '" title="' + th('InboxCloseWindow') + '">' + ic('close') + '</button>';
		$('.ibx-js-toastclose', el).addEventListener('click', () => {
			clearTimeout(el._ibxTimer);
			el.remove();
		});
		if (o.action) {
			$('.ibx-js-toastaction', el).addEventListener('click', () => {
				el.remove();
				if (o.onAction) {
					o.onAction();
				}
			});
		}
		if (o.key) {
			el.dataset.toastKey = o.key;
		}
		// One « Undo » at a time (1.90.4): a new one replaces the previous one, never two stacked (the one above would
		// look like the undo of the last action); the older actions stay in the journal
		const undo = o.action && o.action === t('InboxUndo');
		if (undo) {
			el.dataset.toastUndo = '1';
			box.querySelectorAll(':scope > [data-toast-undo]').forEach(x => {
				clearTimeout(x._ibxTimer);
				x.remove();
			});
		}
		box.appendChild(el);
		// The last two only: older ones are not worth stacking (their « Undo » stays in the journal)
		const shown = box.querySelectorAll(':scope > .ibx-toast');
		for (let i = 0; i < shown.length - 2; i++) {
			clearTimeout(shown[i]._ibxTimer);
			shown[i].remove();
		}
		el._ibxTimer = setTimeout(() => el.remove(), o.duration || (o.action ? 8000 : 4000));
		return el;
	}

	/**
	 * HTML injected by the application: the widgets of Dolibarr inside it started (tooltips of getNomUrl, ajax
	 * tooltips...). Dolibarr.initNewContent is native from Dolibarr 24, given by backportdolcontext before: nothing done
	 * without it (the title of the links stays)
	 * @param {Element} el Container of the new HTML
	 */
	function mount(el) {
		if (!el || typeof Dolibarr === 'undefined' || typeof Dolibarr.initNewContent !== 'function') {
			return;
		}
		try {
			Dolibarr.initNewContent(el);
		} catch (err) {
			console.error(err);
		}
	}

	// ---------- Dialogs ----------
	/**
	 * Native <dialog>, modal: Cancel on the left, main action on the right (principle P27)
	 * @param {object} o {title, body (HTML), actions: [{label, primary, danger, run: fn returning false to keep it open}], noCancel, wide, onOpen}
	 * @returns {HTMLDialogElement}
	 */
	function dialog(o) {
		const dlg = document.createElement('dialog');
		dlg.className = 'ibx-dialog' + (o.wide ? ' ibx-dialog--wide' : '');
		dlg.setAttribute('aria-labelledby', 'ibx-dlg-title');
		// Head and foot always in view, the body scrolls; a cross in the head unless it must be answered. Without
		// action (a dialog to read), one « Close » on the right instead of « Cancel » on the left
		const readOnly = !(o.actions || []).length;
		const cancel = '<button type="button" class="ibx-btn ibx-js-cancel' + (readOnly ? ' ibx-btn--primary' : '') + '">' + (o.cancelLabel ? esc(o.cancelLabel) : th(readOnly ? 'InboxCloseWindow' : 'InboxCancel')) + '</button>';
		dlg.innerHTML = '<div class="ibx-dialog__head"><h2 class="ibx-dialog__title" id="ibx-dlg-title">' + esc(o.title) + '</h2>'
			+ (o.noCancel ? '' : '<button type="button" class="ibx-btn ibx-btn--ghost ibx-btn--icon ibx-dialog__close ibx-js-cancel" aria-label="' + th('InboxCloseWindow') + '" title="' + th('InboxCloseWindow') + '">' + ic('close') + '</button>')
			+ '</div>'
			+ '<div class="ibx-dialog__body">' + (o.body || '') + '</div>'
			+ '<div class="ibx-dialog__foot">'
			+ (o.noCancel || readOnly ? '' : cancel)
			+ '<span class="ibx-formfoot__spacer"></span>'
			+ (!o.noCancel && readOnly ? cancel : '')
			+ (o.actions || []).map((a, i) => '<button type="button" class="ibx-btn' + (a.primary ? ' ibx-btn--primary' : '') + (a.danger ? ' ibx-btn--danger' : '') + '" data-i="' + i + '">' + esc(a.label) + '</button>').join('')
			+ '</div>';
		document.body.appendChild(dlg);
		mount(dlg);
		// A dialog that must be answered: Escape refused. Chrome still closes a modal opened without a click of the user
		// (close request without user activation, the cancel event cannot stop it): shown again at once. Only
		// dlg.close() (an action, or the code that opened it) really closes it
		let closing = !o.noCancel;
		if (o.noCancel) {
			const nativeClose = dlg.close.bind(dlg);
			dlg.close = value => {
				closing = true;
				nativeClose(value);
			};
		}
		dlg.addEventListener('close', () => {
			if (!closing && dlg.isConnected) {
				dlg.showModal();
				return;
			}
			dlg.remove();
		});
		dlg.addEventListener('cancel', e => {
			if (o.noCancel) {
				e.preventDefault();
			}
		});
		dlg.addEventListener('click', async e => {
			if (e.target.closest('.ibx-js-cancel')) {
				dlg.close();
				return;
			}
			// The buttons of the foot only: the options of a searchable list (combo) carry data-i too
			const b = e.target.closest('.ibx-dialog__foot [data-i]');
			if (!b || b.getAttribute('aria-disabled') === 'true') {
				return;
			}
			const a = o.actions[+b.dataset.i];
			b.setAttribute('aria-disabled', 'true');
			let keep = false;
			try {
				keep = (await a.run(dlg)) === false;
			} catch (err) {
				keep = true;
				toast(err.message, { error: true });
			}
			b.removeAttribute('aria-disabled');
			if (!keep) {
				dlg.close();
			}
		});
		dlg.showModal();
		if (o.onOpen) {
			o.onOpen(dlg);
		}
		return dlg;
	}

	/**
	 * Confirmation that names the object (definitive actions only; the others are undoable with a toast)
	 * @param {object} o {title, text, confirm (label), danger, typeToConfirm (text to type)}
	 * @returns {Promise<boolean>}
	 */
	function confirmDialog(o) {
		return new Promise(resolve => {
			let done = false;
			const body = '<p>' + esc(o.text) + '</p>' + (o.typeToConfirm ? '<label class="ibx-formrow__label" for="ibx-dlg-type">' + th('InboxTypeToConfirm', o.typeToConfirm) + '</label><input id="ibx-dlg-type" class="ibx-field" autocomplete="off" spellcheck="false">' : '');
			const dlg = dialog({
				title: o.title,
				body,
				actions: [{ label: o.confirm, primary: !o.danger, danger: !!o.danger, run: d => {
					if (o.typeToConfirm && $('#ibx-dlg-type', d).value.trim().toLowerCase() !== String(o.typeToConfirm).toLowerCase()) {
						$('#ibx-dlg-type', d).focus();
						return false;
					}
					done = true;
					resolve(true);
				} }],
				onOpen: d => {
					const f = $('#ibx-dlg-type', d);
					if (f) {
						f.focus();
					}
				},
			});
			dlg.addEventListener('close', () => {
				if (!done) {
					resolve(false);
				}
			});
		});
	}

	// ---------- Tabs of the browser: coordination ----------
	/**
	 * Names shared by the tabs (channel, leader lock, keys of the local storage) are scoped to this instance of
	 * Dolibarr: the browser shares them by origin, and two Dolibarr on the same domain (prod and test, several
	 * entities on one host) would otherwise elect one leader for both and mix their messages
	 */
	// Per entity too: the tabs of two entities never share their channel, their leader nor what they keep
	const scope = name => name + ':' + ctx('INBOX_URL', location.pathname) + ':' + ctx('INBOX_ENTITY', '');
	const channel = (() => {
		const listeners = [];
		let bc = null;
		try {
			bc = new BroadcastChannel(scope('inbox'));
			bc.onmessage = e => listeners.forEach(fn => fn(e.data || {}));
		} catch (e) {
			bc = null;
		}
		return {
			post(msg) {
				if (bc) {
					bc.postMessage(msg);
				}
			},
			on(fn) {
				listeners.push(fn);
			},
		};
	})();
	// Leader tab (the only one that will poll the server from lot W2): Web Locks, else every tab is its own leader
	let isLeader = false;
	if (navigator.locks && navigator.locks.request) {
		navigator.locks.request(scope('inbox-leader'), () => {
			isLeader = true;
			Dolibarr.executeHook('inbox:app:leader', {});
			return new Promise(() => {});
		});
	} else {
		isLeader = true;
	}
	channel.on(msg => {
		if (msg.type === 'session' && msg.token) {
			token = msg.token;
		}
		if (msg.type === 'changed') {
			Dolibarr.executeHook('inbox:data:changed', msg);
			router.refresh(msg);
		}
	});
	/** Tell the other tabs that something changed (they refresh the screen that shows it) */
	function changed(entity, id) {
		channel.post({ type: 'changed', entity, id: id || 0 });
	}

	// ---------- Display settings of the user ----------
	function applyDisplay(params) {
		const root = document.documentElement;
		if (params.theme && params.theme !== 'auto') {
			root.dataset.ibxTheme = params.theme;
		} else {
			delete root.dataset.ibxTheme;
		}
		root.dataset.ibxDensity = params.density || 'normal';
		root.style.fontSize = (parseInt(params.text_size, 10) || 100) + '%';
		// Styles computed again at once for the whole page (a change of theme could leave texts in the colors of
		// the previous one until a full reload)
		void root.offsetHeight;
	}

	// ---------- Spaces and router ----------
	/**
	 * A space: {id, icon, label, render(container, route), refresh(msg)}. Its container is created once and kept:
	 * coming back to a space shows it as it was left. Other modules may add spaces with registerSpace() (later lots).
	 */
	const spaces = [];
	function registerSpace(space) {
		spaces.push(space);
	}

	const router = {
		current: null,
		parse() {
			const parts = (location.hash.replace(/^#\/?/, '') || 'mail').split('/').filter(Boolean).map(decodeURIComponent);
			return { space: parts[0], parts: parts.slice(1) };
		},
		go(hash) {
			if (location.hash === hash) {
				this.render();
			} else {
				location.hash = hash;
			}
		},
		render() {
			const route = this.parse();
			let space = spaces.find(s => s.id === route.space);
			if (!space) {
				space = spaces[0];
				route.space = space.id;
				route.parts = [];
			}
			spaces.forEach(s => {
				if (s.el) {
					s.el.hidden = s !== space;
				}
			});
			if (!space.el) {
				space.el = document.createElement('main');
				space.el.className = 'ibx-space ibx-space--' + space.id;
				space.el.setAttribute('aria-label', space.label());
				$('#ibx-app').appendChild(space.el);
			}
			$$('.ibx-rail [data-space]').forEach(b => {
				if (b.dataset.space === space.id) {
					b.setAttribute('aria-current', 'page');
				} else {
					b.removeAttribute('aria-current');
				}
			});
			this.current = space;
			space.render(space.el, route);
			setTitle(space.label() + ' - ' + t('InboxAppName'));
		},
		refresh(msg) {
			if (this.current && this.current.refresh) {
				this.current.refresh(msg);
			}
		},
	};

	// ---------- Title of the tab: « (12) Mail - Inbox » with the unread counter (lot W8 1.21.0) ----------
	const title = { base: '', unread: 0 };
	function paintTitle() {
		document.title = (title.unread > 0 ? '(' + (title.unread > 99 ? '99+' : title.unread) + ') ' : '') + title.base;
	}
	function setTitle(base) {
		title.base = base;
		paintTitle();
	}
	function setTitleCount(n) {
		title.unread = Math.max(0, n | 0);
		paintTitle();
	}

	function renderRail() {
		const app = $('#ibx-app');
		const rail = document.createElement('nav');
		rail.className = 'ibx-rail';
		rail.setAttribute('aria-label', t('InboxSpaces'));
		rail.innerHTML = spaces.filter(s => !s.bottom).map(s => '<a class="ibx-rail__item" href="#/' + esc(s.id) + '" data-space="' + esc(s.id) + '" title="' + esc(s.label()) + '" aria-label="' + esc(s.label()) + '">' + ic(s.icon) + '</a>').join('')
			+ '<span class="ibx-rail__spacer"></span>'
			// Light / dark in one click (the choice of My account, saved), above the bell
			+ '<button type="button" class="ibx-rail__item ibx-js-themeswitch" role="switch" aria-checked="false">' + ic('theme-dark', 'ibx-rail__moon') + ic('theme-light', 'ibx-rail__sun') + '</button>'
			// The bell of the notifications (js/inbox-notify.js), above the buttons of the bottom
			+ '<button type="button" class="ibx-rail__item ibx-js-bell" aria-haspopup="dialog" aria-expanded="false" title="' + th('InboxNotifications') + '" aria-label="' + th('InboxNotifications') + '">'
			+ ic('bell') + '<span class="ibx-rail__badge" aria-hidden="true" hidden></span></button>'
			+ spaces.filter(s => s.bottom).map(s => '<a class="ibx-rail__item" href="#/' + esc(s.id) + '" data-space="' + esc(s.id) + '" title="' + esc(s.label()) + '" aria-label="' + esc(s.label()) + '">' + ic(s.icon) + '</a>').join('')
			+ '<a class="ibx-rail__item" href="' + esc(ctx('INBOX_DOLIBARR_URL', '/')) + '" title="' + th('InboxBackToDolibarr') + '" aria-label="' + th('InboxBackToDolibarr') + '">' + ic('logout') + '</a>';
		app.prepend(rail);
		bindThemeSwitch($('.ibx-js-themeswitch', rail));
	}

	/** The theme shown now is dark: forced dark, or « auto » with a dark system */
	function isDark() {
		const forced = document.documentElement.dataset.ibxTheme;
		return forced ? forced === 'dark' : !!(window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
	}

	/**
	 * Switch of the rail: light <-> dark, saved as the theme of My account. The new theme grows in a circle from the
	 * button (View Transitions API), else the colours cross-fade; at once when the user asks for reduced motion.
	 * @param {HTMLElement} btn
	 */
	function bindThemeSwitch(btn) {
		const root = document.documentElement;
		const paint = () => {
			const dark = isDark();
			btn.setAttribute('aria-checked', String(dark));
			const label = t(dark ? 'InboxThemeToLight' : 'InboxThemeToDark');
			btn.title = label;
			btn.setAttribute('aria-label', t('InboxDarkTheme'));
		};
		paint();
		if (window.matchMedia) {
			window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', paint);
		}
		Dolibarr.on('inbox:params:changed', () => setTimeout(paint, 0));
		btn.addEventListener('click', async () => {
			const theme = isDark() ? 'light' : 'dark';
			const apply = () => {
				root.dataset.ibxTheme = theme;
				void root.offsetHeight;
				paint();
			};
			const reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
			if (reduced) {
				apply();
			} else if (document.startViewTransition) {
				const box = btn.getBoundingClientRect();
				const x = box.left + box.width / 2;
				const y = box.top + box.height / 2;
				const radius = Math.hypot(Math.max(x, window.innerWidth - x), Math.max(y, window.innerHeight - y));
				const tr = document.startViewTransition(apply);
				tr.ready.then(() => {
					root.animate({ clipPath: ['circle(0 at ' + x + 'px ' + y + 'px)', 'circle(' + radius + 'px at ' + x + 'px ' + y + 'px)'] },
						{ duration: 550, easing: 'ease-in-out', pseudoElement: '::view-transition-new(root)' });
				}).catch(() => {
					// applied without animation
				});
			} else {
				root.classList.add('ibx-theme-fading');
				apply();
				setTimeout(() => root.classList.remove('ibx-theme-fading'), 400);
			}
			try {
				const r = await post('userparam', 'set', { id: 0, param: 'theme', value: theme });
				Dolibarr.executeHook('inbox:params:changed', r.params);
			} catch (err) {
				toast(err.message, { error: true });
			}
		});
	}

	// Mail space: js/inbox-mail.js (lot W3), registered first so that it is the default space

	// ---------- Tool of the JS context ----------
	const inbox = {
		ctx, ctxJson, locale, size, rights, esc, html, raw, t, th, ic, api, post, upload, toast, dialog, confirmDialog, mount, changed, applyDisplay, registerSpace, channel, scope, setTitleCount, infotip, term, thTerms,
		go: hash => router.go(hash),
		route: () => router.parse(),
		isLeader: () => isLeader,
		/** Number of this tab (X-Inbox-Origin) */
		origin,
		/** Current CSRF token (renewed by a reconnection): for a sendBeacon, that cannot go through post() */
		token: () => token,
		/** Headers of a call of inbox made without api() (X-Inbox-Origin, X-Inbox-Entity) */
		headers,
		$, $$,
	};
	Dolibarr.defineTool('inbox', inbox);
	// setEventMessage of the context shown as an inbox toast. Its tool is never redefined (the context makes every tool
	// non-configurable: a second definition throws); without jnotify, the tool of Dolibarr writes into
	// #alert-message-container when it exists (else a window.alert): the messages written there become toasts.
	const alerts = document.createElement('div');
	alerts.id = 'alert-message-container';
	alerts.hidden = true;
	document.body.appendChild(alerts);
	new MutationObserver(list => list.forEach(m => m.addedNodes.forEach(n => {
		if (n.nodeType === 1) {
			toast(String(n.textContent || ''), { error: n.classList.contains('error') });
			n.remove();
		}
	}))).observe(alerts, { childList: true });

	// ---------- Explanation on an info icon (popover) ----------
	/**
	 * A small « i » that explains (why a password is asked again...): the text opens on hover, keyboard focus or a
	 * click (a phone has no hover), closes on leaving, Escape or a click elsewhere; read by the screen readers
	 * (aria-describedby). infotip(title, text) gives the markup; the events are bound once for the whole page.
	 */
	let tipSeq = 0;
	/**
	 * Glossary of inbox (1.88.5): a term explained where it is named, its word underlined with dots (<dfn>) opening
	 * its definition. Key => language keys of its title and its definition. Used only by term() in the code and by
	 * the markers [[key:word]] of new language keys read with thTerms(): never by th() or t(), so a text read
	 * elsewhere (PHP, mails, other modules) never shows a marker.
	 */
	const GLOSSARY = {
		spf: ['InboxTerm_spf_title', 'InboxTerm_spf'],
		dkim: ['InboxTerm_dkim_title', 'InboxTerm_dkim'],
		dmarc: ['InboxTerm_dmarc_title', 'InboxTerm_dmarc'],
		gravatar: ['InboxTerm_gravatar_title', 'InboxTerm_gravatar'],
		bimi: ['InboxTerm_bimi_title', 'InboxTerm_bimi'],
		dnslists: ['InboxTerm_dnslists_title', 'InboxTerm_dnslists'],
		rspamd: ['InboxRspamdWhatTitle', 'InboxRspamdWhat'],
	};
	/**
	 * A term of the glossary: the word (not escaped) underlined with dots, its definition in a bubble
	 * @param {string} key  Key of the glossary
	 * @param {string} word Word shown
	 * @returns {string} HTML
	 */
	function term(key, word) {
		const g = GLOSSARY[key];
		return g ? infotip(t(g[0]), t(g[1]), word) : esc(word);
	}
	/**
	 * A language key whose text marks terms as [[key:word]]: escaped as th(), the markers become terms
	 * @param {string} key  Language key (a new one, made for this)
	 * @param {...*}   args Values of its %s
	 * @returns {string} HTML
	 */
	function thTerms(key, ...args) {
		const text = t(key, ...args);
		let out = '';
		let last = 0;
		text.replace(/\[\[([a-z0-9]+):([^\]]+)\]\]/g, (m, k, w, at) => {
			out += esc(text.slice(last, at)) + term(k, w);
			last = at + m.length;
			return m;
		});
		return out + esc(text.slice(last));
	}

	function infotip(title, text, term) {
		const id = 'ibx-tip' + (++tipSeq);
		// A term (1.88.4): the word itself, underlined with dots, opens the bubble (as an abbreviation, but by the
		// keyboard and the touch too); <dfn>, its definition being the bubble beside it
		return '<span class="ibx-infotip' + (term ? ' ibx-infotip--term' : '') + '"><button type="button" class="ibx-infotip__btn" aria-label="' + esc(term ? term + ' : ' + title : title) + '" aria-describedby="' + id + '" aria-expanded="false">'
			+ (term ? '<dfn>' + esc(term) + '</dfn>' : ic('info')) + '</button>'
			+ '<span class="ibx-infotip__pop" role="tooltip" id="' + id + '" hidden><b>' + esc(title) + '</b><span>' + esc(text) + '</span></span></span>';
	}
	(() => {
		const show = (tip, on) => {
			const pop = tip.querySelector('.ibx-infotip__pop');
			const btn = tip.querySelector('.ibx-infotip__btn');
			if (!pop || !btn) {
				return;
			}
			pop.hidden = !on;
			btn.setAttribute('aria-expanded', on ? 'true' : 'false');
			if (on) {
				// Kept inside the window: aligned on the right when the icon is near the right edge
				const r = btn.getBoundingClientRect();
				tip.classList.toggle('is-end', r.left > window.innerWidth - 300);
			}
		};
		const closeAll = except => document.querySelectorAll('.ibx-infotip').forEach(t => {
			if (t !== except) {
				show(t, false);
			}
		});
		document.addEventListener('pointerover', e => {
			const tip = e.target.closest && e.target.closest('.ibx-infotip');
			if (tip && e.pointerType !== 'touch') {
				closeAll(tip);
				show(tip, true);
			}
		});
		document.addEventListener('pointerout', e => {
			const tip = e.target.closest && e.target.closest('.ibx-infotip');
			if (tip && !(e.relatedTarget && tip.contains(e.relatedTarget)) && !tip.classList.contains('is-pinned')) {
				show(tip, false);
			}
		});
		document.addEventListener('focusin', e => {
			const tip = e.target.closest && e.target.closest('.ibx-infotip');
			closeAll(tip);
			if (tip) {
				show(tip, true);
			}
		});
		document.addEventListener('click', e => {
			const btn = e.target.closest && e.target.closest('.ibx-infotip__btn');
			if (!btn) {
				document.querySelectorAll('.ibx-infotip.is-pinned').forEach(t => t.classList.remove('is-pinned'));
				closeAll(null);
				return;
			}
			const tip = btn.closest('.ibx-infotip');
			const on = !tip.classList.contains('is-pinned');
			tip.classList.toggle('is-pinned', on);
			closeAll(tip);
			show(tip, on);
		});
		document.addEventListener('keydown', e => {
			if (e.key === 'Escape' && document.querySelector('.ibx-infotip__pop:not([hidden])')) {
				document.querySelectorAll('.ibx-infotip.is-pinned').forEach(t => t.classList.remove('is-pinned'));
				closeAll(null);
			}
		});
	})();

	// ---------- Tooltips ----------
	/**
	 * Tooltips of inbox (css/inbox.css, .ibx-tooltip: the look of the dark tooltips of Dolibarr 24, kept in inbox so
	 * that a change of the theme of Dolibarr never changes them), in measured places only:
	 * - bars of icons (bulk bar, actions of the mail read, heads of the panes) and the context menus: the bubble
	 *   comes at once, the icon alone does not say enough;
	 * - everywhere else (folders, rows of the list, rail) the native title stays: it comes with a delay and does not
	 *   hide what the pointer crosses;
	 * - a button that opens a menu: no bubble while its menu is open (aria-expanded), and none at all on the « ⋯ »
	 *   buttons (no title: their dots say it).
	 * The title moves to data-title at the first hover or focus, with the side that keeps the bubble inside the
	 * window. An element drawing its own ::before or ::after keeps the native title.
	 */
	function tooltips() {
		const SIDES = ['ibx-tooltip--top', 'ibx-tooltip--bottom', 'ibx-tooltip--left', 'ibx-tooltip--right'];
		const drawsItself = el => {
			if (el.classList.contains('ibx-tooltip')) {
				return false;
			}
			const b = getComputedStyle(el, '::before').content;
			const a = getComputedStyle(el, '::after').content;
			return (b && b !== 'none' && b !== 'normal') || (a && a !== 'none' && a !== 'normal');
		};
		const init = el => {
			const title = el.getAttribute('title');
			if (title) {
				if (drawsItself(el)) {
					return;
				}
				el.setAttribute('data-title', title);
				if (!el.hasAttribute('aria-label')) {
					el.setAttribute('aria-label', title);
				}
				el.removeAttribute('title');
			}
			if (!el.hasAttribute('data-title')) {
				return;
			}
			// Side of the bubble: above by default, below at the top of the window, aside near the edges
			const r = el.getBoundingClientRect();
			// Actions of the mail read: always below, a bubble beside would cover the next icons; near the right
			// edge it is aligned on the right of the button (never out of the window)
			// Narrow screens (1.100.1): the heads of the panes are under the top bar of the application, a bubble above
			// would go under it; the bar of the actions of the mail (row of the back arrow) too
			const below = !!el.closest('.ibx-readhead__actions, .ibx-panebar')
				|| (window.matchMedia('(max-width: 63.9375rem)').matches && !!el.closest('.ibx-pane__head'));
			let side = below || r.top < 56 ? 'ibx-tooltip--bottom' : 'ibx-tooltip--top';
			if (!below && r.right > window.innerWidth - 110) {
				side = 'ibx-tooltip--left';
			} else if (!below && r.left < 110) {
				side = 'ibx-tooltip--right';
			}
			el.classList.remove(...SIDES);
			el.classList.toggle('ibx-tooltip--end', below && r.right > window.innerWidth - 110);
			el.classList.add('ibx-tooltip', side);
		};
		const target = e => {
			const el = e.target && e.target.closest ? e.target.closest('button[title], a[title], [role="button"][title], button[data-title], a[data-title]') : null;
			return el && el.closest('.ibx-bulkbar, .ibx-readhead__actions, .ibx-pane__head, .ibx-menu') ? el : null;
		};
		['pointerover', 'focusin'].forEach(ev => document.addEventListener(ev, e => {
			if (e.pointerType === 'touch') {
				return;
			}
			const el = target(e);
			if (el) {
				init(el);
			}
		}, true));
	}

	async function start() {
		try {
			// The langs tool reads the language when its file loads, before the values of the page: set it here
			await Dolibarr.tools.langs.setLocale(ctx('MAIN_LANG_DEFAULT', 'en_US'), true);
			// The langs tool keeps the translations in the browser until Dolibarr itself changes version: after an
			// update of inbox, its new texts would show their keys. Cache cleared when the language file changed.
			const stamp = ctx('INBOX_LANG_STAMP', '') + '|' + ctx('DOL_LANG_INTERFACE_URL', '');
			let known = '';
			try {
				known = localStorage.getItem(scope('inbox-lang-stamp')) || '';
			} catch (e) {
				known = '';
			}
			if (stamp && stamp !== known && Dolibarr.tools.langs.clearCache) {
				await Dolibarr.tools.langs.clearCache(true);
				try {
					localStorage.setItem(scope('inbox-lang-stamp'), stamp);
				} catch (e) {
					// private window: cleared again next time, harmless
				}
			}
			await Dolibarr.tools.langs.load('inbox@inbox');
			// Searchable lists (tool combo, js/dolibarr-combo.js or the core): their words in the language of the user
			if (Dolibarr.tools.combo) {
				Dolibarr.tools.combo.labels({ search: t('InboxComboSearch'), none: t('InboxComboNone'), noResult: t('InboxComboNoResult'), typeMore: t('InboxComboTypeMore'),
					moreChosen: t('InboxComboMoreChosen'), minChars: t('InboxComboMinChars'), searching: t('InboxComboSearching'), clear: t('InboxComboClear'), remove: t('InboxComboRemove') });
			}
		} catch (e) {
			// Keys shown as they are: the application still works
		}
		applyDisplay(ctxJson('INBOX_PARAMS_JSON', {}));
		tooltips();
		Dolibarr.executeHook('inbox:app:init', { inbox });
		renderRail();
		$('#ibx-app').removeAttribute('data-loading');
		window.addEventListener('hashchange', () => router.render());
		router.render();
		updatePendingBar();
		Dolibarr.executeHook('inbox:app:ready', { inbox });
	}
	// Deferred scripts run while readyState is "interactive", before DOMContentLoaded: wait for it so that the
	// spaces of the other scripts (inbox-settings.js, other modules) are registered before the rail is built
	if (document.readyState === 'complete') {
		start();
	} else {
		document.addEventListener('DOMContentLoaded', start);
	}
})();
