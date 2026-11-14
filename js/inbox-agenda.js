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
 * Agenda panel of the mail space (1.107.0): the events of the user, late to do, today and the coming days.
 * - Shown by itself when no mail is open; opened by hand (button of the rail, Shift+F9) beside a mail; pinned, it
 *   stays. Closed by hand, it stays closed until opened again. Pinned, closed, width: kept for the user.
 * - Docked as a column when the reading keeps its room, else over the reading (and as a sheet on a phone).
 * - inbox knows no other module. A module plugs itself (README, « Agenda panel »):
 *   inbox:agenda:render, inbox:agenda:action, inbox:agenda:toolbar, inbox:agenda:shown / hidden (JS hooks);
 *   inboxAgendaProvider and inboxAgendaEvents (PHP hooks); the API I.agenda (refresh, open, close, isVisible).
 * Every text of an event is escaped here.
 */
(function () {
	'use strict';

	const I = Dolibarr.tools.inbox;
	const { esc, t, th, ic, api, post, toast, $, $$ } = I;
	const CONF = I.ctxJson('INBOX_AGENDA_JSON', {}) || {};
	const params = Object.assign({}, I.ctxJson('INBOX_PARAMS_JSON', {}));
	const REFRESH_MS = 5 * 60 * 1000;
	/** Late lines shown before « Show the N others » */
	const LATE_SHOWN = 5;
	/** Width under which the reading would be squeezed: the panel goes over it */
	const READ_MIN = 420;
	const A = {
		el: null, app: null, visible: false, mailId: 0, data: null, seq: 0, timer: 0, loading: false,
		pastOpen: false, lateOpen: false, error: '',
	};
	// Choice of the user: '' (automatic), 'pinned', 'closed'
	let mode = ['pinned', 'closed'].includes(params.agenda_mode) ? params.agenda_mode : '';
	// Opened by hand beside a mail (not pinned): closed again when the mail changes
	let byHand = false;

	function enabled() {
		return !!CONF.enabled;
	}
	function isPhone() {
		return window.matchMedia('(max-width: 63.9375rem)').matches;
	}

	function saveParam(param, value) {
		params[param] = String(value);
		post('userparam', 'set', { param, value: String(value) }).catch(() => {});
	}

	/** A hook a module may make wait (executeHookAwait when the context has it); an error never stops inbox */
	/**
	 * A hook of the panel, for both kinds of listeners of the JS context: Dolibarr.on (sync, changes the context it
	 * gets) then Dolibarr.onAwait (async, in turn, each one gives back the context). A listener that throws is said in
	 * the console and inbox goes on with its own behaviour.
	 * @param {string} name
	 * @param {object} ctx
	 * @returns {Promise<object>} The context, changed by the listeners
	 */
	async function hook(name, ctx) {
		try {
			Dolibarr.executeHook(name, ctx);
			if (Dolibarr.executeHookAwait) {
				const res = await Dolibarr.executeHookAwait(name, ctx);
				if (res && typeof res === 'object' && res !== ctx) {
					Object.assign(ctx, res);
				}
			}
		} catch (e) {
			console.error('inbox ' + name, e);
			ctx.handled = false;
		}
		return ctx;
	}

	// ---------- Dates ----------
	const locale = I.locale;
	function dayStart(d) {
		return new Date(d.getFullYear(), d.getMonth(), d.getDate());
	}
	function todayStart() {
		return Math.floor(dayStart(new Date()).getTime() / 1000);
	}
	function dayKey(ts) {
		const d = new Date(ts * 1000);
		return d.getFullYear() + '-' + (d.getMonth() + 1) + '-' + d.getDate();
	}
	function hour(ts) {
		return new Date(ts * 1000).toLocaleTimeString(locale(), { hour: '2-digit', minute: '2-digit' });
	}
	function dayTitle(ts) {
		return new Date(ts * 1000).toLocaleDateString(locale(), { weekday: 'long', day: 'numeric', month: 'long' });
	}
	function shortDay(ts) {
		return new Date(ts * 1000).toLocaleDateString(locale(), { weekday: 'short', day: 'numeric', month: 'numeric' });
	}

	// ---------- Place: docked or over the reading ----------
	function place() {
		if (!A.app) {
			return;
		}
		if (!A.visible) {
			A.app.removeAttribute('data-agenda');
			return;
		}
		const read = $('.ibx-pane--read', A.app);
		const agendaW = A.el.getBoundingClientRect().width || 300;
		// Room of the reading with the panel docked: what the reading has now, less the panel when it is over it
		const docked = A.app.dataset.agenda === 'open';
		const readW = read ? read.getBoundingClientRect().width + (docked ? 0 : -agendaW - 5) : 0;
		A.app.dataset.agenda = !isPhone() && readW >= READ_MIN ? 'open' : 'over';
		const sp = $('.ibx-splitter--agenda', A.app);
		if (sp) {
			sp.hidden = A.app.dataset.agenda !== 'open';
		}
	}

	function setVisible(on, reason) {
		if (!A.el || on === A.visible) {
			paintRail();
			return;
		}
		A.visible = on;
		A.el.hidden = !on;
		const sp = $('.ibx-splitter--agenda', A.app);
		if (on) {
			// Docked first: place() measures the reading with the panel in its column
			A.app.dataset.agenda = 'open';
			place();
			load();
			clearInterval(A.timer);
			A.timer = setInterval(() => {
				if (!document.hidden) {
					load();
				}
			}, REFRESH_MS);
			Dolibarr.executeHook('inbox:agenda:shown', { pinned: mode === 'pinned', reason: reason || 'auto' });
		} else {
			sp.hidden = true;
			A.app.removeAttribute('data-agenda');
			clearInterval(A.timer);
			Dolibarr.executeHook('inbox:agenda:hidden', { pinned: mode === 'pinned', reason: reason || 'auto' });
		}
		paintRail();
	}

	/** What the rule says: pinned = always; closed by hand = never; else when no mail is open or opened by hand */
	function apply(reason) {
		if (!enabled() || !A.el) {
			return;
		}
		if (isPhone()) {
			// Phone: a sheet over the screen, never opened by itself (pinned included)
			setVisible(byHand, reason);
		} else if (mode === 'pinned') {
			setVisible(true, reason);
		} else if (mode === 'closed') {
			setVisible(byHand, reason);
		} else {
			setVisible(!A.mailId || byHand, reason);
		}
	}

	// ---------- Shell of the panel ----------
	function mount() {
		const el = $('.ibx-js-agenda');
		if (!el || el === A.el) {
			return !!A.el;
		}
		A.el = el;
		A.app = el.closest('.ibx-mailapp');
		el.innerHTML = '<div class="ibx-pane__head ibx-agenda__head"><div class="ibx-agenda__titles"><span class="ibx-pane__title ibx-js-agtitle">' + th('InboxAgendaPanel') + '</span>'
			+ '<span class="ibx-agenda__date ibx-js-agdate"></span></div><span class="ibx-spacer"></span><span class="ibx-agenda__tools ibx-js-agtools"></span></div>'
			+ '<div class="ibx-pane__body ibx-agenda__body ibx-js-agbody" aria-live="polite"></div>';
		el.addEventListener('click', onClick);
		el.addEventListener('change', onChange);
		window.addEventListener('resize', () => place());
		document.addEventListener('visibilitychange', () => {
			// Back on the tab: the day may have changed, the events too
			if (!document.hidden && A.visible && A.data && (Date.now() / 1000 - A.data.at > 60 || A.data.from !== todayStart())) {
				load();
			}
		});
		return true;
	}

	async function paintTools() {
		const ctx = { buttons: [], mail: I.mailContext ? I.mailContext() : null };
		if (CONF.native || (CONF.provider && CONF.provider.urls.create)) {
			ctx.buttons.push({ key: 'create', label: t('InboxAgendaNewEvent'), icon: 'event-new', rank: 10 });
		}
		ctx.buttons.push({ key: 'pin', label: t(mode === 'pinned' ? 'InboxAgendaUnpin' : 'InboxAgendaPin'), icon: 'pin', rank: 80, pressed: mode === 'pinned' });
		ctx.buttons.push({ key: 'close', label: t('InboxAgendaClose'), icon: 'close', rank: 90 });
		await hook('inbox:agenda:toolbar', ctx);
		const tools = $('.ibx-js-agtools', A.el);
		if (!tools) {
			return;
		}
		tools.innerHTML = (Array.isArray(ctx.buttons) ? ctx.buttons : []).filter(b => b && /^[\w:-]{1,40}$/.test(String(b.key)))
			.sort((a, b) => (+a.rank || 50) - (+b.rank || 50))
			.map(b => '<button type="button" class="ibx-btn ibx-btn--icon ibx-btn--sm ibx-js-agaction" data-action="' + esc(b.key) + '" title="' + esc(b.label || b.key) + '" aria-label="' + esc(b.label || b.key) + '"'
				+ (b.pressed !== undefined ? ' aria-pressed="' + (b.pressed ? 'true' : 'false') + '"' : '') + '>' + ic(/^[a-z0-9-]{1,40}$/.test(String(b.icon || '')) ? b.icon : 'agenda') + '</button>').join('');
	}

	// ---------- Data ----------
	async function load() {
		if (!A.el || !A.visible) {
			return;
		}
		const seq = ++A.seq;
		const from = todayStart();
		const body = $('.ibx-js-agbody', A.el);
		if (!A.data) {
			body.setAttribute('aria-busy', 'true');
			body.innerHTML = '<div class="ibx-agenda__skel"><span class="ibx-skeleton"></span><span class="ibx-skeleton" style="width:70%"></span><span class="ibx-skeleton" style="width:50%"></span></div>';
		}
		let res;
		try {
			res = await api('agenda', 'list', { from, days: +params.agenda_days || 7 });
			A.error = '';
		} catch (err) {
			if (seq !== A.seq) {
				return;
			}
			A.error = err.message;
			res = null;
		}
		if (seq !== A.seq) {
			return;
		}
		body.removeAttribute('aria-busy');
		if (res) {
			A.data = Object.assign(res, { at: Date.now() / 1000, from });
		}
		render();
	}

	async function render() {
		const body = $('.ibx-js-agbody', A.el);
		const now = Date.now() / 1000;
		$('.ibx-js-agdate', A.el).textContent = new Date().toLocaleDateString(locale(), { weekday: 'short', day: 'numeric', month: 'long' });
		paintTools();
		if (A.error && !A.data) {
			body.innerHTML = '<div class="ibx-empty">' + ic('warning', 'ibx-empty__icon') + '<div>' + esc(A.error) + '</div><button type="button" class="ibx-btn ibx-js-agaction" data-action="retry">' + th('InboxRetry') + '</button></div>';
			return;
		}
		if (!A.data) {
			return;
		}
		const ctx = {
			el: body,
			user: { id: +(I.ctx('INBOX_USER_ID', 0) || 0), timezone: Intl.DateTimeFormat().resolvedOptions().timeZone || '' },
			range: { from: A.data.from, to: A.data.to, days: +params.agenda_days || 7 },
			mail: I.mailContext ? I.mailContext() : null,
			events: A.data.events.slice(),
			late: A.data.late.slice(),
			handled: false,
		};
		await hook('inbox:agenda:render', ctx);
		// A module drew the content itself
		if (ctx.handled === true) {
			return;
		}
		const events = (Array.isArray(ctx.events) ? ctx.events : []).filter(e => e && +e.start > 0);
		const late = (Array.isArray(ctx.late) ? ctx.late : []).filter(e => e && +e.start > 0);
		const lateTotal = Math.max(late.length, +A.data.lateTotal || 0);
		let html = A.error ? '<div class="ibx-agenda__note ibx-agenda__note--error">' + ic('warning') + esc(A.error) + '</div>' : '';
		if (late.length) {
			const shown = A.lateOpen ? late : late.slice(0, LATE_SHOWN);
			html += '<section class="ibx-agenda__group ibx-agenda__group--late"><h3 class="ibx-agenda__day">' + th('InboxAgendaLate', lateTotal) + '</h3><ul class="ibx-agenda__list">'
				+ shown.map(e => line(e, { late: true })).join('') + '</ul>'
				+ (late.length > LATE_SHOWN ? '<button type="button" class="ibx-btn ibx-btn--ghost ibx-btn--sm ibx-js-agaction" data-action="toggleLate">'
					+ (A.lateOpen ? th('InboxAgendaShowLess') : th('InboxAgendaShowMore', late.length - LATE_SHOWN)) + '</button>' : '')
				+ (lateTotal > late.length && A.lateOpen ? '<div class="ibx-agenda__more">' + th('InboxAgendaLateMore', lateTotal - late.length) + '</div>' : '')
				+ '</section>';
		}
		// Days: today first (always, even empty), then the days that have events
		const today = A.data.from;
		const byDay = new Map([[dayKey(today), []]]);
		events.forEach(e => {
			// An event over several days shows on each day of the period from today
			const first = Math.max(+e.start, today);
			const last = Math.max(first, (+e.end || +e.start) - (e.allday ? 0 : 1));
			for (let d = dayStart(new Date(first * 1000)); d.getTime() / 1000 <= last && d.getTime() / 1000 < A.data.to; d.setDate(d.getDate() + 1)) {
				const k = dayKey(d.getTime() / 1000);
				if (!byDay.has(k)) {
					byDay.set(k, []);
				}
				byDay.get(k).push(e);
			}
		});
		let i = 0;
		byDay.forEach((list, k) => {
			const isToday = i++ === 0;
			list.sort((a, b) => (b.allday ? 1 : 0) - (a.allday ? 1 : 0) || a.start - b.start);
			const ts = isToday ? today : Math.floor(new Date(k.split('-')[0], k.split('-')[1] - 1, k.split('-')[2]).getTime() / 1000);
			html += '<section class="ibx-agenda__group' + (isToday ? ' ibx-agenda__group--today' : '') + '"><h3 class="ibx-agenda__day">' + (isToday ? th('InboxAgendaTodayTitle') : esc(dayTitle(ts))) + '</h3>';
			if (!isToday) {
				html += '<ul class="ibx-agenda__list">' + list.map(e => line(e, {})).join('') + '</ul></section>';
				return;
			}
			// Today: the events over (not to do) folded under « N earlier today », the line of the time now
			const past = list.filter(e => !e.allday && (+e.end || +e.start) <= now && !(e.percent >= 0 && e.percent < 100));
			const rest = list.filter(e => !past.includes(e));
			if (past.length) {
				html += '<button type="button" class="ibx-btn ibx-btn--ghost ibx-btn--sm ibx-agenda__past ibx-js-agaction" data-action="togglePast" aria-expanded="' + (A.pastOpen ? 'true' : 'false') + '">'
					+ ic(A.pastOpen ? 'chevron-down' : 'chevron-right') + th('InboxAgendaPast', past.length) + '</button>'
					+ (A.pastOpen ? '<ul class="ibx-agenda__list ibx-agenda__list--past">' + past.map(e => line(e, { past: true })).join('') + '</ul>' : '');
			}
			let nowShown = false;
			const items = rest.map(e => {
				let out = '';
				if (!nowShown && !e.allday && +e.start > now) {
					nowShown = true;
					out += nowLine();
				}
				return out + line(e, {});
			});
			if (!nowShown) {
				items.push(nowLine());
			}
			html += '<ul class="ibx-agenda__list">' + items.join('') + '</ul>'
				+ (!rest.length && !past.length ? '<div class="ibx-agenda__none">' + th('InboxAgendaNothingToday') + '</div>' : '') + '</section>';
		});
		if (byDay.size === 1 && !events.length && !late.length) {
			html += '<div class="ibx-agenda__none">' + th('InboxAgendaNothingComing', +params.agenda_days || 7) + '</div>';
		}
		if (CONF.native || (CONF.provider && CONF.provider.urls.openAll)) {
			html += '<div class="ibx-agenda__foot"><button type="button" class="ibx-btn ibx-btn--ghost ibx-btn--sm ibx-js-agaction" data-action="openAll">' + th('InboxAgendaOpenAll') + ic('external') + '</button></div>';
		}
		body.innerHTML = html;
	}

	function nowLine() {
		return '<li class="ibx-agenda__now" aria-label="' + th('InboxAgendaNow', hour(Date.now() / 1000)) + '"><span>' + esc(hour(Date.now() / 1000)) + '</span></li>';
	}

	function line(e, o) {
		const todo = +e.percent >= 0 && +e.percent < 100;
		const done = +e.percent === 100;
		const when = o.late ? shortDay(e.start) : (e.allday ? t('InboxAgendaAllDay') : hour(e.start));
		const key = esc(String(e.source || 'dolibarr')) + '|' + esc(String(e.id));
		const box = todo || done
			? '<input type="checkbox" class="ibx-agenda__check ibx-js-agdone"' + (done ? ' checked' : '') + (e.canDone ? '' : ' disabled') + ' aria-label="' + th(done ? 'InboxAgendaUndone' : 'InboxAgendaDone', e.label) + '">'
			: '<span class="ibx-agenda__dot" style="' + (e.color ? '--ibx-agenda-dot:' + esc(e.color) : '') + '" aria-hidden="true"></span>';
		const sub = [e.thirdparty && e.thirdparty.name ? esc(e.thirdparty.name) : '', e.location ? ic('map-pin') + esc(e.location) : ''].filter(Boolean).join(' · ');
		return '<li class="ibx-agenda__item' + (done ? ' is-done' : '') + (o.past ? ' is-past' : '') + (+e.answer === 2 ? ' is-maybe' : '') + '" data-key="' + key + '">'
			+ box + '<span class="ibx-agenda__when">' + esc(when) + '</span>'
			+ '<button type="button" class="ibx-agenda__label ibx-js-agaction" data-action="open" title="' + esc(e.label) + (+e.answer === 2 ? ' (' + th('InboxAgendaMaybe') + ')' : '') + '">' + esc(e.label) + '</button>'
			+ (e.mail && +e.mail.mail > 0 ? '<button type="button" class="ibx-btn ibx-btn--icon ibx-btn--sm ibx-agenda__mail ibx-js-agaction" data-action="openMail" title="' + th('InboxAgendaOpenMail') + '" aria-label="' + th('InboxAgendaOpenMail') + '">' + ic('mail') + '</button>' : '')
			+ (sub ? '<span class="ibx-agenda__sub">' + sub + '</span>' : '') + '</li>';
	}

	function findEvent(li) {
		if (!li || !A.data) {
			return null;
		}
		return A.data.events.concat(A.data.late).find(e => String(e.source || 'dolibarr') + '|' + String(e.id) === li.dataset.key) || null;
	}

	// ---------- Actions ----------
	async function onClick(ev) {
		const btn = ev.target.closest('.ibx-js-agaction');
		if (!btn || !A.el.contains(btn)) {
			return;
		}
		const action = btn.dataset.action;
		if (action === 'retry') {
			load();
			return;
		}
		if (action === 'togglePast') {
			A.pastOpen = !A.pastOpen;
			render();
			return;
		}
		if (action === 'toggleLate') {
			A.lateOpen = !A.lateOpen;
			render();
			return;
		}
		if (action === 'close') {
			close('button');
			return;
		}
		if (action === 'pin') {
			mode = mode === 'pinned' ? '' : 'pinned';
			byHand = false;
			saveParam('agenda_mode', mode);
			apply('button');
			paintTools();
			return;
		}
		run(action, findEvent(btn.closest('.ibx-agenda__item')));
	}

	/**
	 * An action of the panel: a module may take it over (hook inbox:agenda:action, then the page it declared), else
	 * inbox does it
	 * @param {string} action create, open, openAll, done, undone, openMail, or the key of a button of a module
	 * @param {object|null} event
	 */
	async function run(action, event) {
		const mail = I.mailContext ? I.mailContext() : null;
		const ctx = await hook('inbox:agenda:action', { action, event, mail, handled: false });
		if (ctx.handled === true) {
			return;
		}
		const urls = CONF.provider && CONF.provider.urls ? CONF.provider.urls : {};
		const native = !event || (event.source || 'dolibarr') === 'dolibarr';
		if (urls[action] && (action !== 'open' || native)) {
			openPage(action, fill(urls[action], event, mail), CONF.provider.label || t('InboxAgendaPanel'));
			return;
		}
		if (action === 'create' && CONF.native) {
			openPage('create', createUrl(mail), t('InboxAgendaNewEvent'));
		} else if (action === 'open' && event && event.url) {
			openPage('open', event.url, event.label);
		} else if (action === 'openAll' && CONF.native) {
			window.open(I.ctx('INBOX_DOLIBARR_URL', '/') + 'comm/action/index.php?mainmenu=agenda&filtert=' + encodeURIComponent(I.ctx('INBOX_USER_ID', 0)), '_blank', 'noopener');
		} else if (action === 'openMail' && event && event.mail) {
			Dolibarr.executeHook('inbox:mail:goto', { folder: +event.mail.folder, mail: +event.mail.mail });
		}
	}

	/** Neutral markers of an address declared by a module, filled from the event and the mail open */
	function fill(url, event, mail) {
		const m = mail || {};
		const values = {
			id: event ? event.id : '',
			socid: m.thirdparty ? m.thirdparty.id : '',
			contactids: m.contact ? m.contact.id : '',
			projectid: m.project ? m.project.id : '',
			elements: (m.elements || []).map(e => e.type + ':' + e.id).join(','),
			start: '',
		};
		return String(url).replace(/\{(id|socid|contactids|projectid|elements|start)\}/g, (x, k) => encodeURIComponent(String(values[k])));
	}

	/** Creation in the agenda of Dolibarr, filled from the mail open (its thirdparty, its contact, its project) */
	function createUrl(mail) {
		const q = new URLSearchParams({ action: 'create', dol_hide_topmenu: '1', dol_hide_leftmenu: '1' });
		if (mail && mail.thirdparty) {
			q.set('socid', mail.thirdparty.id);
		}
		if (mail && mail.contact) {
			q.set('contactid', mail.contact.id);
		}
		if (mail && mail.project) {
			q.set('projectid', mail.project.id);
		}
		return I.ctx('INBOX_DOLIBARR_URL', '/') + 'comm/action/card.php?' + q.toString();
	}

	function openPage(key, url, title) {
		let u = String(url);
		// A page of Dolibarr in a window: without its menus
		if (u.indexOf('/comm/action/') >= 0 && u.indexOf('dol_hide_topmenu') < 0) {
			u += (u.indexOf('?') >= 0 ? '&' : '?') + 'dol_hide_topmenu=1&dol_hide_leftmenu=1';
		}
		if (!I.openPageWindow || !I.openPageWindow({ key: 'agenda-' + key, url: u, title, onClose: () => load() })) {
			window.open(u, '_blank', 'noopener');
		}
	}

	async function onChange(ev) {
		const box = ev.target.closest('.ibx-js-agdone');
		if (!box) {
			return;
		}
		const li = box.closest('.ibx-agenda__item');
		const e = findEvent(li);
		if (!e) {
			return;
		}
		const action = box.checked ? 'done' : 'undone';
		const ctx = await hook('inbox:agenda:action', { action, event: e, mail: I.mailContext ? I.mailContext() : null, handled: false });
		if (ctx.handled === true) {
			load();
			return;
		}
		if (!e.canDone) {
			box.checked = !box.checked;
			return;
		}
		// Optimistic: the line changes at once, the server after; the undo gives the progress before
		const target = box.checked ? 100 : 0;
		const before = +e.percent;
		e.percent = target;
		li.classList.toggle('is-done', target === 100);
		try {
			const res = await post('agenda', 'percent', { id: e.id, percent: target });
			const was = +res.before;
			toast(t(target === 100 ? 'InboxAgendaDoneToast' : 'InboxAgendaUndoneToast', e.label), {
				action: t('InboxUndo'),
				onAction: async () => {
					try {
						await post('agenda', 'percent', { id: e.id, percent: was });
					} catch (err) {
						toast(err.message, { error: true });
					}
					load();
				},
			});
			// Done: it leaves the late ones at the next reading, not under the finger
			setTimeout(load, 1500);
		} catch (err) {
			e.percent = before;
			box.checked = !box.checked;
			li.classList.toggle('is-done', before === 100);
			toast(err.message, { error: true });
		}
	}

	// ---------- Button of the rail ----------
	function railButton() {
		return $('.ibx-rail .ibx-js-agendatoggle');
	}
	function addRailButton() {
		const rail = $('.ibx-rail');
		if (!rail || railButton()) {
			return;
		}
		const b = document.createElement('button');
		b.type = 'button';
		b.className = 'ibx-rail__item ibx-rail__agenda ibx-js-agendatoggle';
		b.title = t('InboxAgendaPanel') + ' (Maj+F9)';
		b.setAttribute('aria-label', t('InboxAgendaPanel'));
		b.setAttribute('aria-expanded', 'false');
		b.innerHTML = ic('agenda');
		const theme = $('.ibx-js-themeswitch', rail);
		rail.insertBefore(b, theme || null);
		b.addEventListener('click', () => {
			// From another space: back to the mail, the panel open
			if (I.route().space !== 'mail') {
				byHand = true;
				I.go('#/mail');
				setTimeout(() => {
					mount();
					apply('button');
				}, 0);
				return;
			}
			toggle('button');
		});
		paintRail();
	}
	function paintRail() {
		const b = railButton();
		if (b) {
			b.setAttribute('aria-expanded', A.visible && I.route().space === 'mail' ? 'true' : 'false');
		}
	}

	// ---------- API ----------
	function open(reason) {
		if (!enabled() || !mount()) {
			return;
		}
		if (mode === 'closed') {
			mode = '';
			saveParam('agenda_mode', '');
		}
		byHand = !!A.mailId || isPhone();
		apply(reason || 'api');
	}
	function close(reason) {
		if (!A.el) {
			return;
		}
		byHand = false;
		if (isPhone()) {
			// The sheet of a phone closes without changing the rule of the computer
			setVisible(false, reason || 'api');
			return;
		}
		// Closed by hand with no mail open: it stays closed. Closed when pinned beside a mail: unpinned, it comes back
		// by itself when no mail is open
		const next = !A.mailId ? 'closed' : (mode === 'pinned' ? '' : mode);
		if (next !== mode) {
			mode = next;
			saveParam('agenda_mode', mode);
		}
		setVisible(false, reason || 'api');
	}
	function toggle(reason) {
		if (!enabled() || !mount()) {
			return;
		}
		if (A.visible) {
			close(reason);
		} else {
			open(reason);
		}
	}

	I.agenda = {
		enabled,
		toggle,
		open: () => open('api'),
		close: () => close('api'),
		isVisible: () => A.visible,
		refresh: () => load(),
		/** Called by the mail space: a mail open (id) or none (0) */
		mailChanged(id) {
			if (!enabled() || !mount()) {
				return;
			}
			const changed = id !== A.mailId;
			A.mailId = id;
			// Phone: a mail opened (from the sheet or the list) takes the screen
			if (id && isPhone()) {
				byHand = false;
			}
			// Opened by hand, it stays while the user goes from mail to mail, until he closes it
			apply('auto');
			// The mail open changed: a module drawing with the mail redraws
			if (changed && A.visible && A.data) {
				render();
			}
		},
	};

	Dolibarr.on('inbox:app:ready', () => {
		if (enabled()) {
			addRailButton();
		}
	});
	window.addEventListener('hashchange', () => setTimeout(paintRail, 0));
})();
