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
 * Widgets in the Dolibarr panel of a mail (1.155.0), on a computer (the column of the strip): a grid between the fixed
 * top (sender, pieces) and the notes docked at the bottom; laid out by each user per mailbox, else the model of the
 * mailbox (saved by its managers), else the preset of its usage (ajax/widget.php, InboxWidgets).
 *
 * Outside the edit mode the grid is a CSS grid (nothing to load): each widget at its column, in the order of its row,
 * the lists and actions as high as their content up to their maximum. « Personalize the widgets » (menu of the
 * panel) loads GridStack (behind I.grid), puts a veil over the rest of the application and opens the catalogue on the
 * left of the panel: previews dragged into the grid to add, widgets dragged back to the catalogue to remove, each
 * change saved at once. The data of the widgets of a mail are read in one call, kept for the mail.
 *
 * The module actions and the blocks of the other modules (1.157.0) are widgets too, drawn from the data of the panel
 * (I.dolPanel): they show by themselves at the end of the grid as long as the user did not take them away (« off » of
 * the layout), and a block placed is left out for a mail where its module declares nothing.
 */
(function () {
	'use strict';

	if (typeof Dolibarr === 'undefined' || !Dolibarr.tools || !Dolibarr.tools.inbox) {
		return;
	}
	const I = Dolibarr.tools.inbox;
	const { html, raw, ic, t, api, post, $, $$ } = I;
	/** Small screen: the width where inbox shows the Dolibarr part on top of the mail or in a sheet (64 rem) */
	const SMALL = window.matchMedia('(max-width: 63.9375rem)');
	/** Version of the layout by the width of the screen, never by the device */
	const version = () => (SMALL.matches ? 'mobile' : 'desktop');
	/** Columns of the grid (InboxWidgets::COLUMNS, 12 since 1.159.0: divided by 2, 3, 4 and 6) */
	const COLUMNS = 12;
	const GAP = 4;
	const AUTO = ['list', 'action', 'free'];
	/** Size of a block of a module: the template free (InboxWidgets::TEMPLATES) */
	const FREE = { w: 12, h: 4, min_rem: 3.5, min_h: 2, max_w: 12, max_h: 20, resize: 'both' };
	const PANEL = 'panel:';
	/** Module actions, blocks and widgets of modules: not computed by inbox; the auto ones may be taken away */
	const isLocal = id => id === 'mail_actions' || String(id).indexOf(PANEL) === 0 || String(id).indexOf('mod:') === 0;
	const list = v => (Array.isArray(v) ? v : []);

	/** layouts per mailbox, data per mail, the panel shown, the edit mode */
	const P = { layouts: new Map(), data: new Map(), mod: new Map(), box: null, zone: null, mail: 0, account: 0, edit: null, cat: null };

	// ---------- Units of the grid ----------
	function rowUnit() {
		const v = getComputedStyle(document.documentElement).getPropertyValue('--ibx-row-h').trim() || '1.875rem';
		const rem = parseFloat(getComputedStyle(document.documentElement).fontSize) || 16;
		return Math.round((v.endsWith('rem') ? parseFloat(v) * rem : parseFloat(v)) / 2);
	}
	function remPx() {
		return parseFloat(getComputedStyle(document.documentElement).fontSize) || 16;
	}
	/** Cells needed for a width in rem, in a grid whose cells are cellPx wide */
	function cellsFor(rem, cellPx) {
		return Math.max(1, Math.min(COLUMNS, Math.ceil((rem * remPx() + GAP) / (cellPx + GAP))));
	}

	// ---------- Layouts and data ----------
	async function layoutsOf(account) {
		if (!P.layouts.has(account)) {
			P.layouts.set(account, api('widget', 'layout', { account }).catch(err => {
				P.layouts.delete(account);
				throw err;
			}));
		}
		return P.layouts.get(account);
	}
	/**
	 * The layout used: the user's, else the model of the mailbox, else the preset; and where it comes from. On a small
	 * screen, as long as no small layout is saved (the user's or the model), the one of the large screen in one column
	 */
	function effective(l) {
		const v = l[version()] || {};
		if (v.user) {
			return { layout: v.user, from: 'user' };
		}
		if (v.model) {
			return { layout: v.model, from: 'model' };
		}
		if (version() === 'mobile') {
			const d = l.desktop || {};
			if (d.user || d.model) {
				return { layout: d.user || d.model, from: 'desktop' };
			}
		}
		return { layout: l.preset || { items: [] }, from: 'preset' };
	}
	async function dataOf(mail, ids) {
		const have = P.data.get(mail) || {};
		const missing = ids.filter(id => !(id in have) && !isLocal(id));
		if (missing.length) {
			Object.assign(have, await I.widgets.data(mail, missing));
			P.data.set(mail, have);
			while (P.data.size > 20) {
				P.data.delete(P.data.keys().next().value);
			}
		}
		return have;
	}
	/** Catalogue of the user, and the blocks the modules declare for the mail shown */
	async function catalogue() {
		const cat = await I.widgets.catalogue();
		// A block replaced by a widget of its module (« replaces », 1.162.0) is not offered any more
		const replaced = new Set(cat.map(w => w.replaces).filter(Boolean));
		const blocks = I.dolPanel ? I.dolPanel.panels(P.box).filter(p => !replaced.has(PANEL + p.id))
			.map(p => ({ id: PANEL + p.id, template: 'free', title: p.title, icon: p.icon, size: FREE, local: true, block: p.id })) : [];
		return cat.filter(w => w.template !== 'thread').concat(blocks);
	}
	/**
	 * Widgets to draw for a layout: the placed ones the user may read, then the module actions and blocks of this mail
	 * not placed nor taken away
	 * @returns {Array<{it:object, w:object}>}
	 */
	function arrange(layout, cat) {
		const items = list(layout.items).slice().sort((a, b) => (a.y - b.y) || (a.x - b.x));
		const placed = new Set(items.map(it => it.id));
		const off = list(layout.off);
		const out = items.map(it => ({ it, w: cat.find(c => c.id === it.id) })).filter(x => x.w);
		// Not placed yet: as high as their content (from one row in the static grid, the least of the template in the edit grid)
		cat.filter(w => (w.local || w.auto) && !placed.has(w.id) && !off.includes(w.id)).forEach(w => out.push({ it: { id: w.id, x: 0, y: 9999, w: w.size.w, h: w.size.min_h || 2 }, w, auto: true }));
		return out;
	}
	/** Something to show for this mail (no module action: the widget of the actions stays away; a module says hidden) */
	function present(w) {
		if (w.module) {
			const d = (P.mod.get(P.mail) || {})[w.id];
			return !(d && d.hidden === true);
		}
		return w.id !== 'mail_actions' || (I.dolPanel && I.dolPanel.actions(P.box).length > 0);
	}
	/**
	 * Data of the widgets of modules for a mail (1.162.0): read at their address, markers filled from the mail
	 * ({ctx}: a token of the mail), kept for the mail; a failure gives an error in the widget
	 */
	async function modData(mail, ws) {
		const have = P.mod.get(mail) || {};
		const missing = ws.filter(w => w.module && !(w.id in have));
		if (missing.length) {
			const ctx = I.mailContext ? I.mailContext() : null;
			await Promise.all(missing.map(async w => {
				try {
					const url = String(w.url).indexOf('{ctx}') >= 0 && I.moduleUrlCtx ? await I.moduleUrlCtx(w.url, ctx, {}) : I.moduleUrl(w.url, ctx, {});
					const res = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
					if (!res.ok) {
						throw new Error(res.statusText || String(res.status));
					}
					const d = await res.json();
					have[w.id] = d && typeof d === 'object' ? d : null;
				} catch (err) {
					have[w.id] = { error: err.message || 'Error' };
				}
			}));
			P.mod.set(mail, have);
			while (P.mod.size > 20) {
				P.mod.delete(P.mod.keys().next().value);
			}
		}
		return have;
	}
	/**
	 * One widget: from its data, or from the panel for the module actions and blocks
	 * @param {object} w Definition (catalogue)
	 * @param {object|null|undefined} data Data (undefined: not read yet, the preview)
	 * @returns {string}
	 */
	function widgetHtml(w, data) {
		if (w.module) {
			const d = (P.mod.get(P.mail) || {})[w.id];
			if (w.template === 'free') {
				return String(html`<div class="ibx-wg ibx-wg--free"><div class="ibx-wg__head"><span class="ibx-wg__title">${raw(ic(/^[a-z0-9-]{1,40}$/.test(String(w.icon)) ? w.icon : 'magic'))} ${w.title}</span></div>`)
					+ '<div class="ibx-mpanel__body ibx-wg__free">' + (I.dolPanel ? I.dolPanel.render(d === undefined ? w.preview : d) : '') + '</div></div>';
			}
			if (d && d.error) {
				return String(html`<div class="ibx-wg ibx-wg--empty">${raw(ic('warning'))} ${d.error}</div>`);
			}
			return String(I.widgets.render(w.template, d === undefined ? w.preview : d, w.preview));
		}
		if (w.block) {
			return String(html`<div class="ibx-wg ibx-wg--free ibx-mpanel" data-panel="${w.block}"><div class="ibx-wg__head"><span class="ibx-wg__title">${raw(ic(/^[a-z0-9-]{1,40}$/.test(String(w.icon)) ? w.icon : 'magic'))} ${w.title}</span></div>`)
				+ '<div class="ibx-mpanel__body ibx-wg__free">' + I.dolPanel.panelBody(P.box, w.block) + '</div></div>';
		}
		if (w.id === 'mail_actions' && present(w)) {
			return String(html`<div class="ibx-wg ibx-wg--action ibx-wg--modactions"><div class="ibx-wg__head"><span class="ibx-wg__title">${w.title}</span></div>`)
				+ '<div class="ibx-wg__actions">' + I.dolPanel.actionsHtml(P.box) + '</div></div>';
		}
		return String(I.widgets.render(w.template, data === undefined ? w.preview : data, w.preview));
	}
	/** Preview in the catalogue: sample values; a block of a module, its title and what it is */
	function previewHtml(w) {
		if (w.module && w.template === 'free') {
			return String(html`<div class="ibx-wg ibx-wg--free"><div class="ibx-wg__head"><span class="ibx-wg__title">${raw(ic(/^[a-z0-9-]{1,40}$/.test(String(w.icon)) ? w.icon : 'magic'))} ${w.title}</span></div>`)
				+ '<div class="ibx-mpanel__body ibx-wg__free">' + (I.dolPanel ? I.dolPanel.render(w.preview || {}) : '') + '</div></div>';
		}
		if (w.block) {
			return String(html`<div class="ibx-wg ibx-wg--free"><div class="ibx-wg__head"><span class="ibx-wg__title">${raw(ic(/^[a-z0-9-]{1,40}$/.test(String(w.icon)) ? w.icon : 'magic'))} ${w.title}</span></div><p class="ibx-wg__note">${t('InboxWgModuleBlockHint')}</p></div>`);
		}
		return String(I.widgets.render(w.template, w.preview));
	}

	// ---------- Static grid (outside the edit mode) ----------
	/**
	 * Called by the panel (js/inbox-mail.js) after each drawing of the column Dolibarr
	 * @param {HTMLElement} box     Panel
	 * @param {{mail:number,account:number}} ctx Mail shown, its mailbox
	 */
	async function mount(box, ctx) {
		const zone = $('.ibx-js-wgzone', box);
		if (!zone || !ctx.mail || !ctx.account || !I.widgets) {
			return;
		}
		P.box = box;
		P.zone = zone;
		P.mail = ctx.mail;
		P.account = ctx.account;
		bindSplitter(box);
		let l;
		try {
			l = await layoutsOf(ctx.account);
		} catch (e) {
			return;
		}
		if (P.zone !== zone) {
			return;
		}
		if (P.edit && P.edit.account === ctx.account) {
			// The panel was drawn again while personalizing: the grid of the edit mode comes back in the new zone
			startEdit(true);
			return;
		}
		await paintStatic(zone, ctx.mail, l);
	}
	async function paintStatic(zone, mail, l) {
		if (version() === 'mobile') {
			return paintFlow(zone, mail, l);
		}
		const eff = effective(l);
		const cat = await catalogue().catch(() => []);
		if (P.zone !== zone || P.mail !== mail) {
			return;
		}
		// A placed widget the user may no longer read is left out (rights changed), its place kept in the layout
		// Widgets of modules read first: one hidden for this mail is left out like a block (1.162.0)
		const arranged = arrange(eff.layout, cat);
		await modData(mail, arranged.map(x => x.w)).catch(() => ({}));
		if (P.zone !== zone || P.mail !== mail) {
			return;
		}
		const shown = arranged.filter(x => present(x.w));
		const body = zone.closest('.ibx-dolibar__body');
		if (body) {
			body.classList.toggle('has-widgets', shown.length > 0);
			if (eff.layout.dock > 0) {
				body.style.setProperty('--ibx-dock-h', eff.layout.dock + 'px');
			} else {
				body.style.removeProperty('--ibx-dock-h');
			}
		}
		zone.hidden = !shown.length && !banner(l, eff);
		if (!shown.length) {
			zone.innerHTML = banner(l, eff);
			return;
		}
		const data = await dataOf(mail, shown.map(x => x.it.id)).catch(() => ({}));
		if (P.zone !== zone || P.mail !== mail) {
			return;
		}
		const unit = rowUnit();
		zone.innerHTML = banner(l, eff) + '<div class="ibx-wggrid" style="--ibx-wg-unit:' + unit + 'px">'
			+ shown.map(({ it, w, auto }) => '<div class="ibx-wg-cell" data-wid="' + I.esc(it.id) + '" data-template="' + w.template + '" data-maxh="' + (w.size.max_h || it.h) + '"'
				+ ' style="grid-column:' + (it.x + 1) + ' / span ' + it.w + ';grid-row:span ' + (auto ? 1 : it.h) + '">'
				+ widgetHtml(w, data[it.id] === undefined ? null : data[it.id]) + '</div>').join('')
			+ '</div>';
		I.mount(zone);
		fit(zone);
	}
	/** Lists, actions and blocks as high as their content, up to their maximum (« auto » height) */
	function fit(zone) {
		zone = zone || P.zone;
		if (!zone || P.edit) {
			return;
		}
		const unit = rowUnit();
		$$('.ibx-wggrid > .ibx-wg-cell', zone).forEach(cell => {
			if (!AUTO.includes(cell.dataset.template)) {
				return;
			}
			const wg = $('.ibx-wg', cell);
			// Natural height of the content: the widget freed of its cell for the time of the measure
			const rows = $('.ibx-ov-rows', wg);
			wg.style.height = 'auto';
			if (rows) {
				rows.style.overflow = 'visible';
			}
			const natural = wg.getBoundingClientRect().height;
			wg.style.height = '';
			if (rows) {
				rows.style.overflow = '';
			}
			const need = Math.ceil((natural + GAP) / (unit + GAP));
			const span = +((cell.style.gridRow.match(/span (\d+)/) || [])[1] || 1);
			if (need > span) {
				cell.style.gridRow = 'span ' + Math.min(+cell.dataset.maxh || need, need);
			}
		});
	}
	/** Drawn again (a block of a module gone for this mail) */
	function refresh() {
		if (!P.edit && P.zone && P.zone.isConnected && P.account) {
			layoutsOf(P.account).then(l => paintStatic(P.zone, P.mail, l)).catch(() => {});
		}
	}
	/**
	 * Small screen: the widgets one under the other in the order of the layout, the minis side by side as chips,
	 * the lists as high as their content; the menu of the widgets at the end (the head of the band is a button)
	 */
	async function paintFlow(zone, mail, l) {
		const eff = effective(l);
		zone.hidden = false;
		const cat = await catalogue().catch(() => []);
		// Widgets of modules read first: one hidden for this mail is left out like a block (1.162.0)
		const arranged = arrange(eff.layout, cat);
		await modData(mail, arranged.map(x => x.w)).catch(() => ({}));
		if (P.zone !== zone || P.mail !== mail) {
			return;
		}
		const shown = arranged.filter(x => present(x.w));
		const data = shown.length ? await dataOf(mail, shown.map(x => x.it.id)).catch(() => ({})) : {};
		if (P.zone !== zone || P.mail !== mail) {
			return;
		}
		const unit = rowUnit();
		zone.innerHTML = banner(l, eff) + '<div class="ibx-wglist">'
			+ shown.map(({ it, w }) => flowCell(w, data[it.id] === undefined ? null : data[it.id], unit, false)).join('')
			+ '</div>' + String(html`<div class="ibx-wgflowfoot"><button type="button" class="ibx-btn ibx-btn--sm ibx-btn--ghost ibx-js-wgmenu" aria-haspopup="menu" title="${t('InboxWgMenu')}" aria-label="${t('InboxWgMenu')}">${raw(ic('more'))}</button></div>`);
		I.mount(zone);
	}
	/** One widget in the column of a small screen (edit: with its grip and its cross) */
	function flowCell(w, data, unit, edit) {
		const fixed = ['chart', 'figure'].includes(w.template) ? ' style="height:' + (w.size.h * unit + (w.size.h - 1) * GAP) + 'px"' : '';
		return '<div class="ibx-wg-cell ibx-wg-cell--flow ibx-wgflow--' + w.template + '" data-wid="' + I.esc(w.id) + '"' + fixed + '>'
			+ widgetHtml(w, data)
			+ (edit ? String(html`<div class="ibx-wgflowtools"><button type="button" class="ibx-btn ibx-btn--icon ibx-btn--sm ibx-js-wgmove" title="${t('InboxWgMove')}" aria-label="${t('InboxWgMove')} ${w.title}">${raw(ic('grip'))}</button>
				<button type="button" class="ibx-btn ibx-btn--icon ibx-btn--sm ibx-js-wgflowremove" data-wid="${w.id}" title="${t('InboxWgRemove')}" aria-label="${t('InboxWgRemove')} ${w.title}">${raw(ic('close'))}</button></div>`) : '')
			+ '</div>';
	}
	/** A line on top when the model of the mailbox changed after the user made his own layout */
	function banner(l, eff) {
		const v = l[version()] || {};
		if (eff.from !== 'user' || !v.model || !(v.model.at > v.user.at)) {
			return '';
		}
		const key = 'inbox.wg.seen.' + P.account;
		try {
			if (+localStorage.getItem(key) >= v.model.at) {
				return '';
			}
		} catch (e) {
			// no storage: shown
		}
		return String(html`<div class="ibx-wgbanner">${raw(ic('info'))}<span>${t('InboxWgModelChanged')}</span>
			<button type="button" class="ibx-btn ibx-btn--sm ibx-js-wgadopt">${t('InboxWgAdopt')}</button>
			<button type="button" class="ibx-btn ibx-btn--sm ibx-btn--icon ibx-btn--ghost ibx-js-wgseen" aria-label="${t('InboxCloseWindow')}" title="${t('InboxCloseWindow')}">${raw(ic('close'))}</button></div>`);
	}

	// ---------- Menu of the panel ----------
	async function openMenu(anchor) {
		let l = null;
		try {
			l = await layoutsOf(P.account);
		} catch (e) {
			return;
		}
		const items = [{ label: t('InboxWgPersonalize'), icon: 'edit', run: () => startEdit(false) }];
		if ((l[version()] || {}).user) {
			items.push({ label: t('InboxWgRevert'), icon: 'undo', run: () => revert() });
		}
		I.menu(anchor, items);
	}
	async function revert() {
		const before = ((await layoutsOf(P.account))[version()] || {}).user;
		await saveLayout('reset', null);
		I.toast(t('InboxWgReverted'), before ? { action: t('InboxUndo'), onAction: () => saveLayout('save', before) } : {});
	}
	/**
	 * Save (or reset, or save as the model) and draw again
	 * @param {string} action save, reset, savemodel
	 * @param {object|null} layout {items, dock}
	 */
	async function saveLayout(action, layout) {
		try {
			const res = await post('widget', action, { account: P.account, version: version(), layout: layout ? { items: layout.items, dock: layout.dock || 0, off: layout.off || [], cols: COLUMNS } : null });
			P.layouts.set(P.account, Promise.resolve(res));
			if (!P.edit && P.zone) {
				await paintStatic(P.zone, P.mail, res);
			}
			return res;
		} catch (err) {
			I.toast(err.message, { error: true });
			return null;
		}
	}

	// ---------- Splitter between the grid and the notes: the maximum height of the notes ----------
	/**
	 * The notes are as high as their content, up to a maximum (1.158.7). The splitter sets that maximum (1.159.1):
	 * on hover, focus and drag, the room kept for notes still to come shows dashed above the notes, its top edge a
	 * grip too; the notes stay as high as their content
	 */
	const DOCK_MIN = 144;
	function dockMax(body) {
		const v = parseFloat(getComputedStyle(body).getPropertyValue('--ibx-dock-h'));
		return v > 0 ? v : 16 * remPx();
	}
	function dockBound(body, h) {
		return Math.round(Math.max(DOCK_MIN, Math.min(body.clientHeight - 80, h)));
	}
	/** The dashed room above the notes: from the top of the splitter up to the maximum, or nothing when the notes fill it */
	function dockGhost(sp, on) {
		const body = sp.closest('.ibx-dolibar__body');
		const notes = body && $('.ibx-dolibar__notes', body);
		let ghost = body && $(':scope > .ibx-dockghost', body);
		const room = on && notes ? Math.round(dockMax(body) - notes.getBoundingClientRect().height) : 0;
		// One grip while dragging: the one of the edge that moves (top of the room), the bar keeps its own otherwise
		sp.classList.toggle('is-ghosted', room >= 8 && body.classList.contains('is-dockdrag'));
		if (room < 8) {
			if (ghost) {
				ghost.hidden = true;
			}
			return;
		}
		if (!ghost) {
			ghost = document.createElement('div');
			ghost.className = 'ibx-dockghost';
			ghost.setAttribute('aria-hidden', 'true');
			// Its top edge is a grip too: the pointer on what it moves
			ghost.innerHTML = String(html`<span class="ibx-dockghost__grip"></span><span class="ibx-dockghost__label">${t('InboxWgDockRoom')}</span>`);
			body.appendChild(ghost);
			const grip = $('.ibx-dockghost__grip', ghost);
			grip.addEventListener('pointerenter', () => clearTimeout(sp._hide));
			grip.addEventListener('pointerleave', () => {
				if (!body.classList.contains('is-dockdrag')) {
					sp._hide = setTimeout(() => dockGhost(sp, false), 150);
				}
			});
			grip.addEventListener('pointerdown', e => sp._drag && sp._drag(e, false));
		}
		ghost.hidden = false;
		ghost.style.top = (sp.offsetTop - room) + 'px';
		ghost.style.height = room + 'px';
	}
	async function saveDock(h) {
		const eff = effective(await layoutsOf(P.account));
		await saveLayout('save', { items: eff.layout.items, dock: h, off: eff.layout.off });
	}
	function bindSplitter(box) {
		const sp = $('.ibx-js-wgsplit', box);
		if (!sp || sp._wg) {
			return;
		}
		sp._wg = true;
		const body = () => sp.closest('.ibx-dolibar__body');
		let dragging = false;
		// Shown after a short stay on the bar (1.164.2): a pointer only passing by does not make it flash
		sp.addEventListener('pointerenter', () => {
			clearTimeout(sp._hide);
			clearTimeout(sp._show);
			sp._show = setTimeout(() => dockGhost(sp, true), 450);
		});
		sp.addEventListener('pointerleave', () => {
			clearTimeout(sp._show);
			if (!dragging) {
				// A short delay: the pointer may go up to the grip of the room
				sp._hide = setTimeout(() => dockGhost(sp, false), 150);
			}
		});
		sp.addEventListener('focus', () => dockGhost(sp, true));
		sp.addEventListener('blur', () => dockGhost(sp, false));
		/**
		 * A drag from the bar or from the grip of the room: the top of the room follows the moves of the pointer. From
		 * the bar, the pointer is locked (Pointer Lock): the real one hides, one drawn by inbox stands on the grip of the
		 * room, the one that moves, and the moves go on at the edge of the screen (a large room made smaller without
		 * taking the bar again). Without the lock (refused, Escape), the moves of the pointer as they are
		 */
		sp._drag = (e, fromBar) => {
			const b = body();
			if (!b || !$('.ibx-dolibar__notes', b) || e.button !== 0) {
				return;
			}
			e.preventDefault();
			clearTimeout(sp._hide);
			clearTimeout(sp._show);
			if (I.tipHide) {
				I.tipHide();
			}
			sp.setPointerCapture(e.pointerId);
			dragging = true;
			b.classList.add('is-dockdrag');
			const h0 = dockMax(b);
			let h = h0;
			let y0 = e.clientY;
			let moved = 0;
			let fake = null;
			const locked = () => document.pointerLockElement === sp;
			const place = () => {
				if (!fake) {
					return;
				}
				const ghost = $(':scope > .ibx-dockghost', b);
				const r = sp.getBoundingClientRect();
				fake.style.left = Math.round(r.left + r.width / 2) + 'px';
				fake.style.top = Math.round(ghost && !ghost.hidden ? ghost.getBoundingClientRect().top : r.top + r.height / 2) + 'px';
			};
			const apply = () => {
				h = dockBound(b, h0 - moved);
				b.style.setProperty('--ibx-dock-h', h + 'px');
				dockGhost(sp, true);
				place();
			};
			const move = ev => {
				if (locked()) {
					moved += ev.movementY || 0;
				} else {
					moved = ev.clientY - y0;
				}
				apply();
			};
			let up = null;
			const lockChange = () => {
				if (locked() && !fake) {
					fake = document.createElement('div');
					fake.className = 'ibx-dockcursor';
					fake.setAttribute('aria-hidden', 'true');
					document.body.appendChild(fake);
					place();
				} else if (!locked() && fake) {
					// Escape (or the browser) gave the pointer back: the drag ends there
					up();
				}
			};
			up = async () => {
				sp.removeEventListener('pointermove', move);
				sp.removeEventListener('pointerup', up);
				sp.removeEventListener('pointercancel', up);
				document.removeEventListener('pointerlockchange', lockChange);
				if (fake) {
					fake.remove();
					fake = null;
				}
				if (locked() && document.exitPointerLock) {
					document.exitPointerLock();
				}
				if (!dragging) {
					return;
				}
				dragging = false;
				b.classList.remove('is-dockdrag');
				const ghost = $(':scope > .ibx-dockghost', b);
				dockGhost(sp, sp.matches(':hover') || document.activeElement === sp || !!(ghost && $('.ibx-dockghost__grip', ghost).matches(':hover')));
				if (Math.abs(h - h0) >= 2) {
					await saveDock(h);
				}
			};
			sp.addEventListener('pointermove', move);
			sp.addEventListener('pointerup', up);
			sp.addEventListener('pointercancel', up);
			if (fromBar && sp.requestPointerLock) {
				document.addEventListener('pointerlockchange', lockChange);
				try {
					const asked = sp.requestPointerLock();
					if (asked && asked.catch) {
						asked.catch(() => {
							y0 = e.clientY;
						});
					}
				} catch (err) {
					// Refused: the moves of the pointer as they are
				}
			}
			dockGhost(sp, true);
		};
		sp.addEventListener('pointerdown', e => sp._drag(e, true));
		sp.addEventListener('keydown', async e => {
			if (e.key !== 'ArrowUp' && e.key !== 'ArrowDown') {
				return;
			}
			e.preventDefault();
			const b = body();
			const h = dockBound(b, dockMax(b) + (e.key === 'ArrowUp' ? 24 : -24));
			b.style.setProperty('--ibx-dock-h', h + 'px');
			dockGhost(sp, true);
			await saveDock(h);
		});
	}

	// ---------- Grid of the edit mode (GridStack behind I.grid) ----------
	let gridLoading = null;
	/** GridStack and its styles, loaded at the first personalization only */
	function loadGrid() {
		if (window.GridStack) {
			return Promise.resolve(window.GridStack);
		}
		if (!gridLoading) {
			gridLoading = new Promise((resolve, reject) => {
				const css = document.createElement('link');
				css.rel = 'stylesheet';
				css.href = I.ctx('INBOX_GRIDSTACK_CSS_URL', '');
				document.head.appendChild(css);
				const s = document.createElement('script');
				const any = document.querySelector('script[nonce]');
				if (any && any.nonce) {
					s.nonce = any.nonce;
				}
				s.src = I.ctx('INBOX_GRIDSTACK_URL', '');
				s.onload = () => (window.GridStack ? resolve(window.GridStack) : reject(new Error('gridstack')));
				s.onerror = () => {
					gridLoading = null;
					reject(new Error('gridstack'));
				};
				document.head.appendChild(s);
			});
		}
		return gridLoading;
	}
	/**
	 * The small layer between inbox and GridStack: only these calls are made to the library
	 */
	I.grid = {
		/**
		 * @param {HTMLElement} el Container
		 * @param {object} o {cellHeight, minRow, onChange, onRemoved, catalogue}
		 * @returns {object} grid
		 */
		create(el, o) {
			const g = window.GridStack.init({
				// As high as the zone: GridStack takes a dragged preview in only when it covers half of the grid
				column: COLUMNS, cellHeight: o.cellHeight, margin: GAP / 2, float: false, animate: true, minRow: o.minRow || 2,
				handle: '.grid-stack-item-content', resizable: { handles: 'se' }, removable: o.catalogue,
			}, el);
			g.on('change', () => o.onChange());
			g.on('removed', (e, nodes) => o.onRemoved(nodes));
			// A widget held: Escape belongs to GridStack then (it puts everything back), not to the edit mode
			g.on('dragstart resizestart', () => {
				g.held = true;
			});
			g.on('dragstop resizestop', () => {
				g.held = false;
			});
			return g;
		},
		/** A widget held by the pointer (moved, resized, or taken out of the grid) */
		held(g) {
			return !!g.held;
		},
		/** Positions of the widgets, to put them back */
		snapshot(g) {
			return g.engine.nodes.map(n => ({ el: n.el, x: n.x, y: n.y }));
		},
		restore(g, snap) {
			g.batchUpdate();
			snap.forEach(p => p.el && p.el.gridstackNode && g.update(p.el, { x: p.x, y: p.y }));
			g.batchUpdate(false);
		},
		add(g, it, opts) {
			return g.addWidget(Object.assign({ id: it.id, x: it.x, y: it.y, w: it.w, h: it.h, autoPosition: it.x === undefined }, opts));
		},
		remove(g, el) {
			g.removeWidget(el, true, false);
		},
		/** Move a widget to a cell (the others make room, as when it is dragged) */
		move(g, el, x, y) {
			g.update(el, { x, y });
		},
		items(g) {
			// The nodes themselves: save() leaves out a width or a height equal to the default of GridStack
			return g.engine.nodes.filter(n => n.id).map(n => ({ id: String(n.id), x: n.x, y: n.y, w: n.w || 1, h: n.h || 1 }));
		},
		destroy(g) {
			g.destroy(false);
		},
	};

	/** Bounds of a widget in the grid, from its template (width in rem turned into cells) */
	function bounds(w, cellPx) {
		const s = w.size || {};
		const minW = cellsFor(s.min_rem || 3.5, cellPx);
		const o = { minW: Math.min(minW, s.max_w || COLUMNS), maxW: s.max_w || COLUMNS, minH: s.min_h || 2, maxH: s.max_h || 20 };
		if (s.resize === 'horizontal') {
			o.minH = o.maxH = s.h;
		} else if (s.resize === 'vertical') {
			o.minW = o.maxW = s.w;
		} else if (s.resize === 'none') {
			o.noResize = true;
		}
		return o;
	}

	async function startEdit(again) {
		const zone = P.zone;
		if (!zone) {
			return;
		}
		if (version() === 'mobile') {
			return startEditFlow(again);
		}
		let GridStackLib;
		let l;
		let cat;
		try {
			[GridStackLib, l, cat] = await Promise.all([loadGrid(), layoutsOf(P.account), catalogue()]);
		} catch (err) {
			I.toast(err.message || t('InboxOvLoadError'), { error: true });
			return;
		}
		void GridStackLib;
		if (!P.edit || !again) {
			P.edit = { account: P.account, removedFrom: null };
		}
		const side = zone.closest('.ibx-pane') || P.box;
		document.body.classList.add('is-wgediting');
		side.classList.add('is-wgedit');
		const body = zone.closest('.ibx-dolibar__body');
		if (body) {
			body.classList.add('has-widgets');
		}
		// Heights reached in the static grid: the module widgets not placed yet keep them in the edit grid
		const spans = {};
		$$('.ibx-wggrid > .ibx-wg-cell', zone).forEach(c => {
			spans[c.dataset.wid] = +((c.style.gridRow.match(/span (\d+)/) || [])[1] || 0);
		});
		zone.hidden = false;
		zone.innerHTML = '<div class="grid-stack ibx-wgedit-grid"></div>';
		if (!$('.ibx-wgbackdrop')) {
			const veil = document.createElement('div');
			veil.className = 'ibx-wgbackdrop';
			document.body.appendChild(veil);
		}
		openCatalogue(side, l, cat);
		const el = $('.ibx-wgedit-grid', zone);
		// Height of a row for the lines of the background
		el.style.setProperty('--ibx-wg-unit', rowUnit() + 'px');
		const cellPx = (el.clientWidth - GAP * (COLUMNS - 1)) / COLUMNS;
		const g = I.grid.create(el, {
			cellHeight: rowUnit(), catalogue: '.ibx-wgcat', minRow: Math.max(8, Math.floor(zone.clientHeight / (rowUnit() + GAP))),
			onChange: () => queueSave(),
			onRemoved: nodes => {
				if (P.edit.silent) {
					return;
				}
				const n = nodes[0];
				if (n) {
					takeAway(String(n.id), true);
				}
				queueSave();
				paintCatalogue();
				if (n) {
					const w = cat.find(c => c.id === n.id);
					const was = { x: n.x, y: n.y, w: n.w, h: n.h };
					I.toast(t('InboxWgRemoved', w ? w.title : n.id), { action: t('InboxUndo'), onAction: () => {
						if (P.edit && w) {
							takeAway(w.id, false);
							addEdit(g, w, was, cellPx);
							queueSave();
							paintCatalogue();
						}
					} });
				}
			},
		});
		P.edit.grid = g;
		P.edit.cat = cat;
		P.edit.cellPx = cellPx;
		P.edit.silent = true;
		const eff = effective(l);
		keepAside(eff.layout, cat);
		arrange(eff.layout, cat).forEach(({ it, w, auto }) => addEdit(g, w, auto ? Object.assign({}, it, { h: Math.max(w.size.min_h || 2, Math.min(w.size.max_h || 20, spans[it.id] || w.size.h)) }) : it, cellPx));
		P.edit.silent = false;
		P.edit.dock = eff.layout.dock || 0;
		paintCatalogue();
		if (!again) {
			const search = $('.ibx-js-wgsearch');
			if (search) {
				search.focus();
			}
		}
	}
	function addEdit(g, w, it, cellPx) {
		const el = I.grid.add(g, Object.assign({}, it, { id: w.id }), bounds(w, cellPx));
		el.dataset.wid = w.id;
		const content = $('.grid-stack-item-content', el);
		const data = (P.data.get(P.mail) || {})[w.id];
		content.innerHTML = '<div class="ibx-wg-cell">' + (w.local && !present(w) ? previewHtml(w) : widgetHtml(w, data)) + '</div>'
			+ String(html`<div class="ibx-wgframe"><button type="button" class="ibx-btn ibx-btn--icon ibx-btn--sm ibx-js-wgremove" data-wid="${w.id}" title="${t('InboxWgRemove')}" aria-label="${t('InboxWgRemove')} ${w.title}">${raw(ic('close'))}</button></div>`);
		if (data === undefined && !w.local) {
			dataOf(P.mail, [w.id]).then(d => {
				const cell = $('.ibx-wg-cell', content);
				if (cell && P.edit) {
					cell.innerHTML = String(I.widgets.render(w.template, d[w.id] === undefined ? null : d[w.id], w.preview));
				}
			}).catch(() => {});
		}
		return el;
	}
	/**
	 * A widget of the catalogue put in the grid: where it was dropped, else at the first free place
	 * @param {string} id Widget
	 * @param {{x:number,y:number}|null} at Cell
	 */
	function addFromCatalogue(id, at) {
		if (!P.edit || (!P.edit.grid && !P.edit.flow)) {
			return;
		}
		const w = P.edit.cat.find(c => c.id === id);
		if (!w || placedIds().includes(id)) {
			return;
		}
		takeAway(id, false);
		if (P.edit.flow) {
			addFlow(w);
			return;
		}
		const it = { w: w.size.w, h: w.size.h };
		if (at) {
			it.x = Math.max(0, Math.min(COLUMNS - w.size.w, at.x));
			it.y = Math.max(0, at.y);
		}
		const el = addEdit(P.edit.grid, w, it, P.edit.cellPx);
		queueSave();
		paintCatalogue();
		if (el && el.scrollIntoView) {
			el.scrollIntoView({ block: 'nearest' });
		}
	}
	/**
	 * Drag of a preview of the catalogue (1.162.1): over the catalogue a ghost follows the pointer; over the grid the
	 * widget takes its real place, centred under the pointer, the others making room as when a widget is moved: what
	 * is seen is what is obtained. Released over the grid it stays there; out of the grid or Escape, it goes away
	 */
	function dragPreview(prev, e) {
		const grid = $('.ibx-wgedit-grid');
		const w = P.edit && P.edit.cat.find(c => c.id === prev.dataset.wid);
		if (!grid || !w || !P.edit.grid || placedIds().includes(w.id)) {
			return;
		}
		const g = P.edit.grid;
		const x0 = e.clientX;
		const y0 = e.clientY;
		let ghost = null;
		let placed = null;
		let cell = null;
		// Positions before the widget is proposed: the others go back there when it goes away
		let snap = null;
		const over = ev => {
			const r = grid.getBoundingClientRect();
			return ev.clientX >= r.left && ev.clientX <= r.right && ev.clientY >= r.top && ev.clientY <= r.bottom ? r : null;
		};
		/** The cell of the top left corner, the widget centred under the pointer and kept inside the grid */
		const cellAt = (ev, r) => {
			const unit = rowUnit();
			const col = Math.floor((ev.clientX - r.left) / (P.edit.cellPx + GAP));
			const row = Math.floor((ev.clientY - r.top) / (unit + GAP));
			return { x: Math.max(0, Math.min(COLUMNS - w.size.w, col - Math.floor(w.size.w / 2))), y: Math.max(0, row - Math.floor(w.size.h / 2)) };
		};
		const leaveGrid = () => {
			if (placed) {
				I.grid.remove(g, placed);
				placed = null;
				cell = null;
				if (snap) {
					I.grid.restore(g, snap);
				}
			}
		};
		const move = ev => {
			if (!ghost && !placed && Math.abs(ev.clientX - x0) + Math.abs(ev.clientY - y0) < 6) {
				return;
			}
			if (!ghost) {
				ghost = document.createElement('div');
				ghost.className = 'ibx-wgghost';
				ghost.innerHTML = $('.ibx-wgprev__box', prev).innerHTML;
				ghost.style.height = getComputedStyle(prev).getPropertyValue('--ibx-wgprev-h');
				document.body.appendChild(ghost);
				// While the widget is only proposed, nothing is saved (the moves of the others neither)
				P.edit.silent = true;
				P.edit.proposing = true;
				snap = I.grid.snapshot(g);
			}
			const r = over(ev);
			if (r) {
				const c = cellAt(ev, r);
				if (!placed) {
					placed = addEdit(g, w, Object.assign({ w: w.size.w, h: w.size.h }, c), P.edit.cellPx);
					placed.classList.add('is-dropping');
				} else if (!cell || c.x !== cell.x || c.y !== cell.y) {
					I.grid.move(g, placed, c.x, c.y);
				}
				cell = c;
				ghost.hidden = true;
			} else {
				leaveGrid();
				ghost.hidden = false;
				ghost.style.left = (ev.clientX + 8) + 'px';
				ghost.style.top = (ev.clientY + 8) + 'px';
			}
		};
		const end = ev => {
			document.removeEventListener('pointermove', move);
			document.removeEventListener('pointerup', end);
			document.removeEventListener('keydown', esc, true);
			if (!ghost) {
				// A click: at the first free place
				if (ev) {
					addFromCatalogue(prev.dataset.wid, null);
				}
				return;
			}
			ghost.remove();
			if (!ev) {
				leaveGrid();
			}
			if (P.edit) {
				P.edit.silent = false;
				P.edit.proposing = false;
			}
			if (placed) {
				placed.classList.remove('is-dropping');
				takeAway(w.id, false);
				queueSave();
				paintCatalogue();
			}
		};
		const esc = ev => {
			if (ev.key === 'Escape') {
				ev.stopPropagation();
				end(null);
			}
		};
		document.addEventListener('pointermove', move);
		document.addEventListener('pointerup', end);
		document.addEventListener('keydown', esc, true);
	}
	/** Widgets placed in the layout being edited */
	function placedIds() {
		if (!P.edit) {
			return [];
		}
		return P.edit.flow ? $$('.ibx-wglist .ibx-wg-cell', P.zone).map(c => c.dataset.wid) : I.grid.items(P.edit.grid).map(i => i.id);
	}
	/**
	 * Layout being edited: the grid, or the list of a small screen (one column, in its order); and the placed widgets
	 * that cannot be drawn here (a block of a module absent for this mail, a right withdrawn), kept at the end
	 */
	function editedItems() {
		let items;
		if (P.edit.flow) {
			items = placedIds().map((id, i) => {
				const w = P.edit.cat.find(c => c.id === id);
				return { id, x: 0, y: i, w: w ? w.size.w : COLUMNS, h: w ? w.size.h : 2 };
			});
		} else {
			items = I.grid.items(P.edit.grid);
		}
		const bottom = items.reduce((m, it) => Math.max(m, it.y + it.h), 0);
		return items.concat(list(P.edit.kept).filter(k => !items.some(it => it.id === k.id)).map((k, i) => Object.assign({}, k, { y: bottom + i })));
	}
	/** The edited layout, to save */
	function edited() {
		return { items: editedItems(), dock: P.edit.dock || 0, off: list(P.edit.off) };
	}
	/** Placed widgets not drawn while editing, and the module widgets taken away */
	function keepAside(layout, cat) {
		P.edit.kept = list(layout.items).filter(it => !cat.some(c => c.id === it.id));
		P.edit.off = list(layout.off).slice();
	}
	/** A module action or block taken away (it no longer comes by itself), or put back */
	function takeAway(id, away) {
		if (!P.edit || !isLocal(id)) {
			return;
		}
		P.edit.off = list(P.edit.off).filter(x => x !== id);
		if (away) {
			P.edit.off.push(id);
		}
	}
	let saveTimer = 0;
	function queueSave() {
		if (!P.edit || P.edit.silent) {
			return;
		}
		clearTimeout(saveTimer);
		saveTimer = setTimeout(() => {
			if (P.edit && (P.edit.grid || P.edit.flow)) {
				saveLayout('save', edited());
			}
		}, 400);
	}

	// ---------- Edit mode of a small screen: a list to order, the catalogue as a sheet ----------
	async function startEditFlow(again) {
		let l;
		let cat;
		try {
			[l, cat] = await Promise.all([layoutsOf(P.account), catalogue()]);
		} catch (err) {
			I.toast(err.message || t('InboxOvLoadError'), { error: true });
			return;
		}
		if (!P.edit || !again) {
			P.edit = { account: P.account, flow: true, cat };
		}
		P.edit.cat = cat;
		const zone = P.zone;
		const holder = zone.closest('.ibx-dolibar') || zone.closest('.ibx-pane') || P.box;
		document.body.classList.add('is-wgediting');
		holder.classList.add('is-wgedit');
		if (!$('.ibx-wgbackdrop')) {
			const veil = document.createElement('div');
			veil.className = 'ibx-wgbackdrop';
			document.body.appendChild(veil);
		}
		const eff = effective(l);
		keepAside(eff.layout, cat);
		const data = P.data.get(P.mail) || {};
		const unit = rowUnit();
		zone.hidden = false;
		zone.innerHTML = '<div class="ibx-wglist is-edit">' + arrange(eff.layout, cat).map(({ w }) => flowCell(w, data[w.id], unit, true)).join('') + '</div>';
		openCatalogue(holder, l, cat);
		// The Dolibarr part is itself a sheet at the bottom on a phone: the catalogue goes on top, both half high
		$('.ibx-wgcat').classList.add('is-sheet');
		$('.ibx-wgcat').classList.toggle('is-top', holder.classList.contains('is-sheet'));
		paintCatalogue();
		// A layout taken from the large screen becomes the one of the small screen once changed
		P.edit.dock = 0;
	}
	function addFlow(w) {
		const listEl = $('.ibx-wglist', P.zone);
		if (!listEl) {
			return;
		}
		const data = (P.data.get(P.mail) || {})[w.id];
		listEl.insertAdjacentHTML('beforeend', flowCell(w, data, rowUnit(), true));
		const cell = listEl.lastElementChild;
		if (data === undefined && !w.local) {
			dataOf(P.mail, [w.id]).then(d => {
				// The content only: the widget keeps its place, its focus and its handlers
				const wg = $(':scope > .ibx-wg', cell);
				if (P.edit && cell.isConnected && wg) {
					wg.outerHTML = String(I.widgets.render(w.template, d[w.id] === undefined ? null : d[w.id], w.preview));
				}
			}).catch(() => {});
		}
		cell.scrollIntoView({ block: 'nearest' });
		queueSave();
		paintCatalogue();
	}
	async function endEditFlow() {
		clearTimeout(saveTimer);
		const layout = Object.assign(edited(), { dock: 0 });
		P.edit = null;
		document.body.classList.remove('is-wgediting');
		$$('.is-wgedit').forEach(x => x.classList.remove('is-wgedit'));
		$$('.ibx-wgbackdrop, .ibx-wgcat').forEach(x => x.remove());
		const res = await saveLayout('save', layout);
		if (!res && P.zone) {
			await paintStatic(P.zone, P.mail, await layoutsOf(P.account));
		}
	}
	/** Order of the list: the grip dragged (pointer), or the arrows up and down on it */
	function moveFlow(cell, delta) {
		const sib = delta < 0 ? cell.previousElementSibling : cell.nextElementSibling;
		if (!sib) {
			return;
		}
		if (delta < 0) {
			sib.before(cell);
		} else {
			sib.after(cell);
		}
		const grip = $('.ibx-js-wgmove', cell);
		if (grip) {
			grip.focus();
		}
		queueSave();
	}
	function dragFlow(cell, e) {
		const listEl = cell.parentElement;
		cell.classList.add('is-dragging');
		const move = ev => {
			const over = document.elementFromPoint(ev.clientX, ev.clientY);
			const target = over && over.closest ? over.closest('.ibx-wglist.is-edit > .ibx-wg-cell') : null;
			if (!target || target === cell || target.parentElement !== listEl) {
				return;
			}
			const r = target.getBoundingClientRect();
			const after = target.classList.contains('ibx-wgflow--mini') ? ev.clientX > r.left + r.width / 2 : ev.clientY > r.top + r.height / 2;
			if (after) {
				target.after(cell);
			} else {
				target.before(cell);
			}
		};
		const up = () => {
			document.removeEventListener('pointermove', move);
			document.removeEventListener('pointerup', up);
			cell.classList.remove('is-dragging');
			queueSave();
		};
		document.addEventListener('pointermove', move);
		document.addEventListener('pointerup', up);
		void e;
	}
	async function endEdit() {
		if (!P.edit) {
			return;
		}
		if (P.edit.flow) {
			return endEditFlow();
		}
		clearTimeout(saveTimer);
		const g = P.edit.grid;
		const layout = g ? edited() : null;
		if (g) {
			I.grid.destroy(g);
		}
		P.edit = null;
		document.body.classList.remove('is-wgediting');
		$$('.is-wgedit').forEach(x => x.classList.remove('is-wgedit'));
		$$('.ibx-wgbackdrop, .ibx-wgcat').forEach(x => x.remove());
		const res = layout ? await saveLayout('save', layout) : null;
		if (!res && P.zone) {
			await paintStatic(P.zone, P.mail, await layoutsOf(P.account));
		}
	}

	// ---------- Catalogue: previews with sample values, never computed ----------
	function openCatalogue(side, l, cat) {
		let box = $('.ibx-wgcat');
		if (!box) {
			box = document.createElement('section');
			box.className = 'ibx-wgcat';
			box.setAttribute('aria-label', t('InboxWgCatalogue'));
			document.body.appendChild(box);
			box.addEventListener('input', e => {
				if (e.target.closest('.ibx-js-wgsearch')) {
					box._q = e.target.value;
					paintCatalogue(true);
				}
			});
			box.addEventListener('pointerdown', e => {
				const prev = e.target.closest('.ibx-wgprev:not(.is-placed)');
				if (prev && e.button === 0) {
					e.preventDefault();
					if (P.edit && P.edit.flow) {
						addFromCatalogue(prev.dataset.wid, null);
					} else {
						dragPreview(prev, e);
					}
				}
			});
			box.addEventListener('keydown', e => {
				const prev = e.target.closest('.ibx-wgprev:not(.is-placed)');
				if (prev && (e.key === 'Enter' || e.key === ' ')) {
					e.preventDefault();
					addFromCatalogue(prev.dataset.wid, null);
				}
			});
			box.addEventListener('click', e => {
				const b = e.target.closest('button');
				if (!b) {
					return;
				}
				if (b.classList.contains('ibx-js-wgdone')) {
					endEdit();
				} else if (b.classList.contains('ibx-js-wgrevert')) {
					endEdit().then(() => revert());
				} else if (b.classList.contains('ibx-js-wgmodel')) {
					saveModel();
				}
			});
		}
		if (version() === 'mobile') {
			box.style.right = '';
		} else {
			const r = side.getBoundingClientRect();
			box.style.right = Math.round(window.innerWidth - r.left + 8) + 'px';
		}
		box._manager = !!l.manager;
		box._own = !!(l[version()] || {}).user;
		void cat;
	}
	function paintCatalogue(keepFocus) {
		const box = $('.ibx-wgcat');
		if (!box || !P.edit) {
			return;
		}
		const placed = new Set(placedIds());
		const q = (box._q || '').toLowerCase();
		const groups = ['mini', 'line', 'figure', 'list', 'chart', 'action', 'free'];
		const unit = rowUnit();
		const body = groups.map(tpl => {
			const ws = P.edit.cat.filter(w => w.template === tpl && (w.title + ' ' + t('InboxWgTemplate_' + tpl)).toLowerCase().includes(q));
			if (!ws.length) {
				return '';
			}
			return String(html`<div class="ibx-wgcat__group">${t('InboxWgTemplate_' + tpl)}</div>`) + '<div class="ibx-wgcat__items">'
				+ ws.map(w => {
					const on = placed.has(w.id);
					return '<div class="ibx-wgprev' + (tpl === 'mini' ? ' is-mini' : '') + (on ? ' is-placed' : '') + '" data-wid="' + w.id + '"'
						+ (on ? ' aria-disabled="true"' : ' role="button" tabindex="0" aria-label="' + I.esc(w.title) + '"')
						+ ' style="--ibx-wgprev-h:' + (w.size.h * unit + (w.size.h - 1) * GAP) + 'px">'
						+ '<div class="ibx-wgprev__box"><div class="ibx-wg-cell">' + previewHtml(w) + '</div></div>'
						+ String(html`<span class="ibx-wgprev__name">${w.title}${on ? ' · ' + t('InboxWgPlaced') : ''}</span>`) + '</div>';
				}).join('') + '</div>';
		}).join('');
		const search = $('.ibx-js-wgsearch', box);
		const pos = search ? search.selectionStart : 0;
		box.innerHTML = String(html`<div class="ibx-wgcat__head"><b>${t('InboxWgCatalogue')}</b><span class="ibx-spacer"></span>
				<button type="button" class="ibx-btn ibx-btn--icon ibx-btn--sm ibx-btn--ghost ibx-js-wgdone" aria-label="${t('InboxWgDone')}" title="${t('InboxWgDone')}">${raw(ic('close'))}</button></div>
			<input type="search" class="ibx-field ibx-wgcat__search ibx-js-wgsearch" placeholder="${t('InboxWgCatalogueSearch')}" aria-label="${t('InboxWgCatalogueSearch')}" value="${box._q || ''}">
			<p class="ibx-wgcat__hint">${t(P.edit && P.edit.flow ? 'InboxWgCatalogueHelpSmall' : 'InboxWgCatalogueHelp')}</p>`)
			+ '<div class="ibx-wgcat__list">' + body + '</div>'
			+ String(html`<div class="ibx-wgcat__foot">
				${box._own ? html`<button type="button" class="ibx-btn ibx-btn--sm ibx-btn--ghost ibx-js-wgrevert">${t('InboxWgRevert')}</button>` : ''}
				${box._manager ? html`<button type="button" class="ibx-btn ibx-btn--sm ibx-btn--ghost ibx-js-wgmodel">${t('InboxWgSaveModel')}</button>` : ''}
				<span class="ibx-spacer"></span><button type="button" class="ibx-btn ibx-btn--sm ibx-btn--primary ibx-js-wgdone">${t('InboxWgDone')}</button></div>`);
		if (keepFocus) {
			const s = $('.ibx-js-wgsearch', box);
			s.focus();
			s.setSelectionRange(pos, pos);
		}
	}
	async function saveModel() {
		if (!P.edit || (!P.edit.grid && !P.edit.flow)) {
			return;
		}
		const res = await saveLayout('savemodel', edited());
		if (res) {
			I.toast(t('InboxWgModelSaved'));
		}
	}

	// ---------- Events ----------
	document.addEventListener('click', async e => {
		const el = e.target.closest ? e.target : null;
		if (!el) {
			return;
		}
		const menuBtn = el.closest('.ibx-js-wgmenu');
		if (menuBtn && P.zone && (menuBtn.closest('.ibx-dolibar') || menuBtn.closest('.ibx-pane'))) {
			openMenu(menuBtn);
			return;
		}
		const flowRm = el.closest('.ibx-js-wgflowremove');
		if (flowRm && P.edit && P.edit.flow) {
			const cell = flowRm.closest('.ibx-wg-cell');
			const next = cell.nextElementSibling;
			const parent = cell.parentElement;
			const w = P.edit.cat.find(c => c.id === cell.dataset.wid);
			cell.remove();
			takeAway(cell.dataset.wid, true);
			queueSave();
			paintCatalogue();
			I.toast(t('InboxWgRemoved', w ? w.title : ''), { action: t('InboxUndo'), onAction: () => {
				if (P.edit && P.edit.flow && parent.isConnected) {
					takeAway(cell.dataset.wid, false);
					parent.insertBefore(cell, next && next.parentElement === parent ? next : null);
					queueSave();
					paintCatalogue();
				}
			} });
			return;
		}
		const rm = el.closest('.ibx-js-wgremove');
		if (rm && P.edit && P.edit.grid) {
			const item = rm.closest('.grid-stack-item');
			if (item) {
				P.edit.grid.removeWidget(item);
			}
			return;
		}
		if (el.closest('.ibx-js-wgadopt')) {
			const before = ((await layoutsOf(P.account))[version()] || {}).user;
			await saveLayout('reset', null);
			I.toast(t('InboxWgAdopted'), before ? { action: t('InboxUndo'), onAction: () => saveLayout('save', before) } : {});
			return;
		}
		if (el.closest('.ibx-js-wgseen')) {
			const l = await layoutsOf(P.account);
			const m = (l[version()] || {}).model;
			try {
				localStorage.setItem('inbox.wg.seen.' + P.account, String(m ? m.at : 0));
			} catch (err) {
				// no storage
			}
			paintStatic(P.zone, P.mail, l);
		}
	});
	document.addEventListener('pointerdown', e => {
		const grip = e.target.closest ? e.target.closest('.ibx-js-wgmove') : null;
		if (grip && P.edit && P.edit.flow && e.button === 0) {
			e.preventDefault();
			dragFlow(grip.closest('.ibx-wg-cell'), e);
		}
	});
	document.addEventListener('keydown', e => {
		const grip = e.target.closest ? e.target.closest('.ibx-js-wgmove') : null;
		if (grip && P.edit && P.edit.flow && (e.key === 'ArrowUp' || e.key === 'ArrowDown' || e.key === 'ArrowLeft' || e.key === 'ArrowRight')) {
			e.preventDefault();
			moveFlow(grip.closest('.ibx-wg-cell'), e.key === 'ArrowUp' || e.key === 'ArrowLeft' ? -1 : 1);
		}
	});
	// Crossing 64 rem: the other version of the layout
	SMALL.addEventListener('change', () => {
		if (P.edit) {
			endEdit();
		} else if (P.zone && P.zone.isConnected && P.account) {
			layoutsOf(P.account).then(l => paintStatic(P.zone, P.mail, l)).catch(() => {});
		}
	});
	document.addEventListener('keydown', e => {
		// A widget held or proposed: Escape cancels that gesture only (GridStack, dragPreview), the edit mode stays
		if (e.key === 'Escape' && P.edit && !document.querySelector('dialog[open]') && !P.edit.proposing && !(P.edit.grid && I.grid.held(P.edit.grid))) {
			e.stopPropagation();
			endEdit();
		}
	}, true);
	window.addEventListener('resize', () => {
		const cat = $('.ibx-wgcat');
		const side = P.zone && (P.zone.closest('.ibx-pane') || P.box);
		if (cat && side) {
			cat.style.right = Math.round(window.innerWidth - side.getBoundingClientRect().left + 8) + 'px';
		}
	});

	I.panelWidgets = { mount, edit: () => startEdit(false), done: endEdit, fit: () => fit(), refresh };
})();
