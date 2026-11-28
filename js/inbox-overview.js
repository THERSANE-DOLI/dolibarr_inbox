/* Copyright (C) 2026 Air MKG
 * Copyright (C) 2026 THERSANE www.thersane.fr
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
 * Overview of a mail and columns of the modules (1.153.0), in the column .ibx-js-sidepane of the strip on the right
 * (js/inbox-mail.js chooses the column), or in a sheet on a phone.
 *
 * Overview: its head (tags, key figures, notes, the sections offered) is read when the column opens for a mail
 * (ajax/overview.php action head); each section only when it is unfolded (action section), then kept for the mail
 * while the page lives. The sections the user left unfolded open again by themselves. Taken from the module
 * InboxInsight of Max (Air MKG).
 *
 * Columns of the modules (hook inboxSidePanes): their address is read with ?mail=<row> while the column is shown;
 * the answer has the same format as the overview (tags, alerts, kpis, note, sections with their rows), drawn here.
 * A module that draws by itself listens to inbox:sidepane:render ({id, mail, el, data, handled}) and sets handled.
 *
 * Every value goes through I.html, which escapes; the addresses of the links stay inside Dolibarr.
 */
(function () {
	'use strict';

	if (typeof Dolibarr === 'undefined' || !Dolibarr.tools || !Dolibarr.tools.inbox) {
		return;
	}
	const I = Dolibarr.tools.inbox;
	const { html, raw, ic, t, api, $, $$ } = I;
	const LEVELS = ['ok', 'info', 'warning', 'danger', 'neutral'];
	const OPEN_KEY = 'inbox.overview.open';
	const CACHE_MAX = 20;
	const list = v => (Array.isArray(v) ? v : []);
	const level = v => (LEVELS.includes(v) ? v : 'neutral');
	let uid = 0;

	/** Column shown: its pane, its mail, its root; cache of the overview per mail (head and sections) */
	const P = { el: null, pane: '', mail: 0, seq: 0, cache: new Map() };

	// ---------- Choices of the user (this browser) ----------
	function choices() {
		try {
			const v = JSON.parse(localStorage.getItem(OPEN_KEY) || '{}');
			return v && typeof v === 'object' && !Array.isArray(v) ? v : {};
		} catch (e) {
			return {};
		}
	}
	function choose(id, open) {
		const c = choices();
		c[id] = open;
		try {
			localStorage.setItem(OPEN_KEY, JSON.stringify(c));
		} catch (e) {
			// Storage refused (private browsing): the choice holds for this page only
		}
	}

	// ---------- Drawing ----------
	/** Address inside Dolibarr only: one starting slash, no space nor quote */
	function inside(url) {
		return typeof url === 'string' && url.charAt(0) === '/' && url.charAt(1) !== '/' && url.charAt(1) !== '\\' && !/[\s<>"'\\]/.test(url);
	}
	/** Icon of a level: an alert is never told by its colour alone */
	function mark(lv) {
		return lv === 'danger' || lv === 'warning' ? raw(ic('warning')) : (lv === 'ok' ? raw(ic('check')) : '');
	}
	function chip(c, alert) {
		const lv = level(c.level);
		return html`<span class="ibx-mbadge ibx-mbadge--${lv} ibx-ov-chip">${alert && (lv === 'danger' || lv === 'warning') ? raw(ic('warning')) : ''}${c.label}</span>`;
	}
	function delta(d) {
		return d ? html`<span class="ibx-ov-delta ibx-ov-delta--${level(d.level)}" title="${d.title || ''}"><span aria-hidden="true">${d.dir === 'down' ? '\u25BC' : '\u25B2'}</span> ${d.text}</span>` : '';
	}
	function kpi(k) {
		const lv = LEVELS.includes(k.level) ? k.level : '';
		return html`<div class="ibx-ov-kpi${lv ? ' ibx-ov-kpi--' + lv : ''}">
			<dt class="ibx-ov-kpi__label">${k.label}</dt>
			<dd class="ibx-ov-kpi__value">${mark(lv)}${k.value}${delta(k.delta)}</dd>
			${k.note ? html`<dd class="ibx-ov-kpi__note">${k.note}</dd>` : ''}
		</div>`;
	}
	/** A line: label, value, detail under it; the link on the value, or on the name of a named thing */
	function row(r) {
		const lv = LEVELS.includes(r.level) ? r.level : '';
		const link = inside(r.url);
		const value = html`${mark(lv)}${r.value}`;
		const a = text => html`<a href="${r.url}" target="_blank" rel="noopener">${text}</a>`;
		return html`<div class="ibx-ov-row${lv ? ' ibx-ov-row--' + lv : ''}${r.item ? ' ibx-ov-row--item' : ''}${r.value === '' ? ' ibx-ov-row--wide' : ''}">
			<dt class="ibx-ov-row__label">${r.item && link ? a(r.label) : r.label}</dt>
			${r.value === '' ? '' : html`<dd class="ibx-ov-row__val">${link && !r.item ? a(value) : value}</dd>`}
			${r.note ? html`<dd class="ibx-ov-row__note">${r.note}</dd>` : ''}
		</div>`;
	}
	function hero(h) {
		const d = h.delta;
		return html`<div class="ibx-ov-hero"><span class="ibx-ov-hero__value">${h.value}</span>${delta(d)}
			<span class="ibx-ov-hero__label">${h.label}${d && d.title ? html`<span class="ibx-sr-only"> (${d.title})</span>` : ''}</span></div>`;
	}
	/** Bars: one per year (value on top) or per month (small); the detail of a bar shows under the chart */
	function bars(c, small) {
		const items = list(c.bars);
		if (!items.length) {
			return '';
		}
		const max = Math.max(0, ...items.map(b => +b.v || 0));
		const last = items.length - 1;
		const cur = small ? (Number.isInteger(c.current) ? c.current : last) : -1;
		return html`<figure class="ibx-ov-chart${small ? ' ibx-ov-chart--spark' : ''}">
			${small ? html`<figcaption class="ibx-ov-chart__cap"><span class="ibx-ov-readout" aria-hidden="true" data-default="${c.label}">${c.label}</span><b>${c.value || ''}</b></figcaption>` : ''}
			<div class="ibx-ov-bars" role="list" aria-label="${c.label}">
				${items.map((b, i) => {
					const v = Math.max(0, +b.v || 0);
					const h = max > 0 && v > 0 ? Math.max(3, Math.round(v / max * 100)) : 0;
					return html`<div class="ibx-ov-bar${b.partial || i === cur ? ' is-current' : ''}" role="listitem" tabindex="0" aria-label="${b.text}" data-tip="${b.text}">
						${small ? '' : html`<span class="ibx-ov-bar__val" aria-hidden="true">${b.short}</span>`}
						<span class="ibx-ov-bar__track" aria-hidden="true"><span class="ibx-ov-bar__fill" style="height:${h}%"></span></span>
						${small && i !== 0 && i !== last ? '' : html`<span class="ibx-ov-bar__x" aria-hidden="true">${b.x}</span>`}
					</div>`;
				})}
			</div>
			${small ? '' : html`<p class="ibx-ov-readout" aria-hidden="true" data-default="${c.hint || ''}">${c.hint || ''}</p>`}
		</figure>`;
	}
	/** Last mails exchanged: a click opens the mail in inbox (the one open is only marked) */
	function mails(s, labels) {
		const items = list(s.mails);
		if (!items.length) {
			return '';
		}
		return html`<div class="ibx-ov-sub">${s.mailsLabel || ''}</div>
		<ul class="ibx-ov-mails">${items.map(m => {
			const dir = m.out ? labels.sent : labels.received;
			const inner = html`<span class="ibx-ov-mail__dir" title="${dir || ''}">${raw(ic(m.out ? 'sent' : 'inbox'))}<span class="ibx-sr-only">${dir || ''}</span></span>
				<span class="ibx-ov-mail__subject">${m.subject}${m.current ? html`<span class="ibx-sr-only"> (${labels.current || ''})</span>` : ''}</span>
				<span class="ibx-ov-mail__date">${m.date}</span>`;
			return m.current || !(m.id > 0)
				? html`<li class="ibx-ov-mail${m.current ? ' is-current' : ''}"><span class="ibx-ov-mail__line">${inner}</span></li>`
				: html`<li class="ibx-ov-mail"><button type="button" class="ibx-ov-mail__line ibx-ov-mail__go" data-mail="${m.id}" data-folder="${m.folder}" title="${labels.open || ''}">${inner}</button></li>`;
		})}</ul>`;
	}
	/** Content of a section loaded: hero, charts, lines, mails */
	function sectionBody(s, labels) {
		const rows = list(s.rows);
		return html`${s.hero ? hero(s.hero) : ''}${s.chart ? bars(s.chart, false) : ''}${s.spark ? bars(s.spark, true) : ''}${s.season ? bars(s.season, true) : ''}
			${rows.length ? html`<dl class="ibx-ov-rows">${rows.map(row)}</dl>` : ''}${mails(s, labels)}`;
	}
	/**
	 * A section: a button (title, and once read its one-line summary) then its detail. state: {loaded, data, open}
	 */
	function section(sec, state, labels) {
		const s = state.data || {};
		const lv = LEVELS.includes(s.sumLevel) ? s.sumLevel : '';
		const bodyId = 'ibx-ov-sec-' + (++uid);
		const url = inside(s.url) ? s.url : (inside(sec.url) ? sec.url : '');
		let body = '';
		if (state.open) {
			if (state.error) {
				body = html`<p class="ibx-ov-empty">${t('InboxOvLoadError')} <button type="button" class="ibx-btn ibx-btn--sm ibx-btn--ghost ibx-ov-retry">${t('InboxRetry')}</button></p>`;
			} else if (!state.loaded) {
				body = raw('<span class="ibx-skeleton"></span><span class="ibx-skeleton" style="width:70%"></span><span class="ibx-skeleton" style="width:45%"></span>');
			} else if (!state.data) {
				body = html`<p class="ibx-ov-empty">${labels.empty || ''}</p>`;
			} else {
				body = sectionBody(s, labels);
			}
		}
		return html`<section class="ibx-ov-sec${state.open ? ' is-open' : ''}${state.loaded && !state.data ? ' is-empty' : ''}" data-sec="${sec.id}">
			<h4 class="ibx-ov-sec__head">
				<button type="button" class="ibx-ov-sec__toggle" aria-expanded="${state.open ? 'true' : 'false'}" aria-controls="${bodyId}">
					${raw(ic('chevron-right'))}
					<span class="ibx-ov-sec__text">
						<span class="ibx-ov-sec__title">${s.title || sec.title}${s.note ? html` <span class="ibx-ov-sec__note">${s.note}</span>` : ''}</span>
						${s.sum ? html`<span class="ibx-ov-sec__sum${lv ? ' ibx-ov-sec__sum--' + lv : ''}">${mark(lv)}${s.sum}</span>` : ''}
					</span>
				</button>
				${url ? html`<a class="ibx-ov-sec__link" href="${url}" target="_blank" rel="noopener" title="${t('InboxOvOpenList')}" aria-label="${t('InboxOvOpenList')}">${raw(ic('external'))}</a>` : ''}
			</h4>
			<div class="ibx-ov-sec__body" id="${bodyId}"${state.open ? '' : raw(' hidden')} aria-busy="${state.open && !state.loaded ? 'true' : 'false'}">${body}</div>
		</section>`;
	}
	/** The whole column for one mail: chips (alerts of the head and of the sections read), key figures, notes, sections */
	function paint(root, entry) {
		const d = entry.head;
		const labels = d.labels || {};
		const alerts = list(d.alerts).slice();
		Object.values(entry.secs).forEach(st => list(st.alerts).forEach(a => {
			if (!alerts.some(x => x.label === a.label)) {
				alerts.push(a);
			}
		}));
		const kpis = list(d.kpis);
		root.innerHTML = html`<div class="ibx-ov">
			<div class="ibx-ov__chips">${alerts.map(c => chip(c, true))}${list(d.tags).map(c => chip(c, false))}</div>
			${kpis.length ? html`<dl class="ibx-ov-kpis">${kpis.map(kpi)}</dl>` : ''}
			${d.note && d.note.text ? html`<p class="ibx-ov-note"><b>${d.note.label || ''}</b> ${d.note.text}</p>` : ''}
			<div class="ibx-ov__body">${list(d.sections).map(sec => section(sec, entry.secs[sec.id] || { open: false }, labels))}</div>
		</div>`;
	}

	// ---------- Reading ----------
	function remember(mail, entry) {
		P.cache.delete(mail);
		P.cache.set(mail, entry);
		while (P.cache.size > CACHE_MAX) {
			P.cache.delete(P.cache.keys().next().value);
		}
	}
	/** The overview of a mail in root: its head (once), then the sections the user leaves unfolded, one after the other */
	async function overview(root, mail) {
		const seq = ++P.seq;
		root.dataset.mail = String(mail);
		let entry = P.cache.get(mail);
		if (!entry) {
			root.innerHTML = '<div class="ibx-ov" aria-busy="true"><span class="ibx-skeleton"></span><span class="ibx-skeleton" style="width:80%"></span><span class="ibx-skeleton" style="width:60%"></span></div>';
			let head;
			try {
				head = await api('overview', 'head', { id: mail });
			} catch (err) {
				if (seq === P.seq || root._sheet) {
					root.innerHTML = html`<div class="ibx-ov"><p class="ibx-ov-empty">${err.message}</p></div>`;
				}
				return;
			}
			entry = { head, secs: {} };
			const c = choices();
			list(head.sections).forEach(sec => {
				if (c[sec.id] === true) {
					entry.secs[sec.id] = { open: true, loaded: false };
				}
			});
			remember(mail, entry);
		}
		if (!root._sheet && (seq !== P.seq || root.dataset.mail !== String(mail))) {
			return;
		}
		paint(root, entry);
		for (const sec of list(entry.head.sections)) {
			const st = entry.secs[sec.id];
			if (st && st.open && !st.loaded && !st.error) {
				await loadSection(root, mail, sec.id);
			}
		}
	}
	async function loadSection(root, mail, id) {
		const entry = P.cache.get(mail);
		if (!entry) {
			return;
		}
		const st = entry.secs[id] || (entry.secs[id] = { open: true });
		st.error = false;
		try {
			const res = await api('overview', 'section', { id: mail, sec: id });
			st.loaded = true;
			st.data = res.section || null;
			st.alerts = list(res.alerts);
		} catch (err) {
			st.error = true;
		}
		if (root.dataset.mail === String(mail) && root.isConnected) {
			paint(root, entry);
		}
	}
	/** A column of a module: its address with the mail, the same format drawn here unless the module draws it */
	async function modulePane(root, pane, mail) {
		const seq = ++P.seq;
		root.dataset.mail = String(mail);
		root.innerHTML = '<div class="ibx-ov" aria-busy="true"><span class="ibx-skeleton"></span><span class="ibx-skeleton" style="width:70%"></span></div>';
		let data = null;
		try {
			const url = pane.url + (pane.url.indexOf('?') >= 0 ? '&' : '?') + 'mail=' + encodeURIComponent(String(mail));
			const resp = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
			data = await resp.json();
		} catch (e) {
			data = { error: t('InboxOvLoadError') };
		}
		if (seq !== P.seq) {
			return;
		}
		const ctx = { id: pane.id, mail, el: root, data, handled: false };
		try {
			Dolibarr.executeHook('inbox:sidepane:render', ctx);
		} catch (e) {
			console.error('inbox inbox:sidepane:render', e);
			ctx.handled = false;
		}
		if (ctx.handled) {
			return;
		}
		if (!data || typeof data !== 'object' || data.error || data.hidden) {
			root.innerHTML = html`<div class="ibx-ov"><p class="ibx-ov-empty">${data && data.error ? String(data.error) : t('InboxOvSectionEmpty')}</p></div>`;
			return;
		}
		const secs = {};
		const sections = list(data.sections).filter(s => s && /^[\w-]{1,40}$/.test(String(s.id))).map(s => {
			secs[s.id] = { open: !!s.open, loaded: true, data: s };
			return { id: s.id, title: s.title, url: s.url };
		});
		paint(root, { head: { tags: list(data.tags), alerts: list(data.alerts), kpis: list(data.kpis), note: data.note, sections, labels: data.labels || {} }, secs });
	}

	// ---------- Column ----------
	function mount() {
		const el = $('.ibx-js-sidepane');
		if (!el) {
			return null;
		}
		if (el !== P.el) {
			P.el = el;
			el.innerHTML = '<div class="ibx-pane__head ibx-ov-head"><span class="ibx-pane__title ibx-js-ovtitle"></span><span class="ibx-ov-head__soc ibx-js-ovsoc"></span>'
				+ '<span class="ibx-spacer"></span><button type="button" class="ibx-btn ibx-btn--icon ibx-btn--sm ibx-js-ovclose" title="' + I.th('InboxCloseWindow') + '" aria-label="' + I.th('InboxCloseWindow') + '">' + ic('close') + '</button></div>'
				+ '<div class="ibx-pane__body ibx-ov-pane__body ibx-js-ovbody" aria-live="polite"></div>';
			$('.ibx-js-ovclose', el).addEventListener('click', () => {
				if (I.side && I.side.close) {
					I.side.close('button');
				}
			});
		}
		return el;
	}
	/**
	 * Called by the strip (js/inbox-mail.js) after each change: the column shown and the mail open; nothing is read
	 * while the column is hidden
	 * @param {string} paneId overview, mod:<id>, or '' (hidden)
	 * @param {number} mail   Row of the mail open, 0 if none
	 */
	function sync(paneId, mail) {
		const el = mount();
		if (!el || !paneId) {
			P.pane = '';
			return;
		}
		const pane = I.side && I.side.panes ? I.side.panes().find(x => x.id === paneId) : null;
		if (!pane) {
			return;
		}
		if (P.pane === paneId && P.mail === mail && $('.ibx-js-ovbody', el).dataset.mail === String(mail)) {
			return;
		}
		P.pane = paneId;
		P.mail = mail;
		$('.ibx-js-ovtitle', el).textContent = pane.title;
		el.setAttribute('aria-label', pane.title);
		const body = $('.ibx-js-ovbody', el);
		const soc = $('.ibx-js-ovsoc', el);
		soc.textContent = '';
		if (!mail && pane.needs_mail) {
			body.innerHTML = html`<p class="ibx-ov-empty">${paneId === 'overview' ? t('InboxOverviewNoMail') : pane.title}</p>`;
			body.dataset.mail = '0';
			return;
		}
		if (paneId === 'overview') {
			overview(body, mail).then(() => {
				const e = P.cache.get(mail);
				if (e && e.head && e.head.soc && P.mail === mail) {
					soc.innerHTML = html`<a href="${e.head.soc.url}" target="_blank" rel="noopener">${e.head.soc.name}</a>`;
				}
			});
		} else {
			modulePane(body, pane, mail);
		}
	}

	// ---------- Sheet on a phone ----------
	function closeSheet() {
		const sh = $('.ibx-ov-sheet');
		if (!sh) {
			return;
		}
		const from = sh._from;
		$$('.ibx-ov-sheetback').forEach(v => v.remove());
		sh.remove();
		if (from && from.isConnected) {
			from.focus({ preventScroll: true });
		}
	}
	/**
	 * The overview in a sheet over the mail (phone), closed by its veil, its button or Escape
	 * @param {string}           paneId overview
	 * @param {number}           mail   Row of the mail
	 * @param {HTMLElement|null} from   Chip to focus again once closed
	 */
	function sheet(paneId, mail, from) {
		closeSheet();
		const veil = document.createElement('div');
		veil.className = 'ibx-ov-sheetback';
		veil.addEventListener('click', closeSheet);
		const sh = document.createElement('section');
		sh.className = 'ibx-ov-sheet';
		sh.setAttribute('role', 'dialog');
		sh.setAttribute('aria-modal', 'true');
		sh.setAttribute('aria-label', t('InboxOverview'));
		sh._from = from || null;
		sh.innerHTML = '<div class="ibx-ov-sheet__head"><span class="ibx-pane__title">' + I.th('InboxOverview') + '</span><span class="ibx-spacer"></span>'
			+ '<button type="button" class="ibx-btn ibx-btn--icon ibx-js-ovsheetclose" aria-label="' + I.th('InboxCloseWindow') + '">' + ic('close') + '</button></div><div class="ibx-ov-sheet__body"></div>';
		document.body.append(veil, sh);
		$('.ibx-js-ovsheetclose', sh).addEventListener('click', closeSheet);
		sh.addEventListener('keydown', e => {
			if (e.key === 'Escape') {
				e.stopPropagation();
				closeSheet();
			}
		});
		const body = $('.ibx-ov-sheet__body', sh);
		body._sheet = true;
		$('.ibx-js-ovsheetclose', sh).focus({ preventScroll: true });
		if (paneId === 'overview') {
			overview(body, mail);
		}
	}

	// ---------- Events (column and sheet) ----------
	document.addEventListener('click', e => {
		const el = e.target.closest ? e.target : null;
		if (!el || !el.closest('.ibx-ov')) {
			return;
		}
		const root = el.closest('.ibx-js-ovbody, .ibx-ov-sheet__body');
		const mail = root ? +root.dataset.mail : 0;
		const toggle = el.closest('.ibx-ov-sec__toggle, .ibx-ov-retry');
		if (toggle && root) {
			const id = toggle.closest('.ibx-ov-sec').dataset.sec;
			const entry = P.cache.get(mail);
			if (!entry) {
				// A column of a module: its sections are already read, only folded or unfolded here
				const sec = toggle.closest('.ibx-ov-sec');
				const open = !sec.classList.contains('is-open');
				sec.classList.toggle('is-open', open);
				$('.ibx-ov-sec__toggle', sec).setAttribute('aria-expanded', open ? 'true' : 'false');
				$('.ibx-ov-sec__body', sec).hidden = !open;
				return;
			}
			const st = entry.secs[id] || (entry.secs[id] = { open: false });
			const retry = !!el.closest('.ibx-ov-retry');
			st.open = retry ? true : !st.open;
			choose(id, st.open);
			paint(root, entry);
			if (st.open && (!st.loaded || retry)) {
				loadSection(root, mail, id);
			}
			return;
		}
		// One of the last mails: the same jump as the links of inbox between two mails
		const go = el.closest('.ibx-ov-mail__go');
		if (go) {
			closeSheet();
			Dolibarr.executeHook('inbox:mail:goto', { folder: +go.dataset.folder, mail: +go.dataset.mail });
		}
	});
	// Detail of a bar under its chart: hover, keyboard focus, touch
	function readout(bar, on) {
		const chart = bar.closest('.ibx-ov-chart');
		const out = chart && $('.ibx-ov-readout', chart);
		if (out) {
			out.textContent = on ? bar.dataset.tip : (out.dataset.default || '');
			out.classList.toggle('is-on', on);
		}
		bar.classList.toggle('is-active', on);
	}
	['mouseover', 'focusin', 'click'].forEach(type => document.addEventListener(type, e => {
		const bar = e.target.closest ? e.target.closest('.ibx-ov-bar') : null;
		if (bar) {
			$$('.ibx-ov-bar.is-active', bar.closest('.ibx-ov-bars')).forEach(b => b !== bar && b.classList.remove('is-active'));
			readout(bar, true);
		}
	}));
	['mouseout', 'focusout'].forEach(type => document.addEventListener(type, e => {
		const bar = e.target.closest ? e.target.closest('.ibx-ov-bar') : null;
		if (bar && !bar.contains(e.relatedTarget)) {
			readout(bar, false);
		}
	}));

	I.sidePane = { sync, sheet };
	// Drawing shared with the widgets of the Dolibarr panel (js/inbox-widgets.js, 1.154.0)
	I.overviewDraw = { inside, mark, chip, delta, kpi, row, hero, bars, mails };
})();
