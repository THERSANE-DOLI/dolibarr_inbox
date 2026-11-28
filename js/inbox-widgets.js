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
 * Widgets of the Dolibarr panel (1.154.0): drawing of the templates, catalogue of the user, data of the widgets of a
 * mail (ajax/widget.php). The layout of the panel (grid, edit mode, catalogue drawer) comes with lot WG2; this file
 * only draws one widget from its data, the same for a widget placed and for a preview of the catalogue.
 *
 * Templates: mini, line, figure, list, chart, action (thread: the notes, drawn by the panel; free: a module, WG4).
 * The content adapts to the size given by the grid through container queries (css/inbox.css, .ibx-wg).
 * Every value goes through I.html, which escapes; the links stay inside Dolibarr.
 */
(function () {
	'use strict';

	if (typeof Dolibarr === 'undefined' || !Dolibarr.tools || !Dolibarr.tools.inbox) {
		return;
	}
	const I = Dolibarr.tools.inbox;
	const { html, raw, ic, t, api } = I;
	const D = () => I.overviewDraw;
	const LEVELS = ['ok', 'info', 'warning', 'danger', 'neutral'];
	const level = v => (LEVELS.includes(v) ? v : '');
	const list = v => (Array.isArray(v) ? v : []);
	const ICON = /^[a-z0-9-]{1,40}$/;

	/** A link of Dolibarr opened in a new tab, or the text alone */
	function link(url, inner, cls) {
		return D().inside(url) ? html`<a class="${cls || ''}" href="${url}" target="_blank" rel="noopener">${inner}</a>` : inner;
	}
	function head(title, url, sum, sumLevel) {
		const lv = level(sumLevel);
		return html`<div class="ibx-wg__head"><span class="ibx-wg__title">${link(url, title)}</span>
			${sum ? html`<span class="ibx-wg__sum${lv ? ' ibx-wg__sum--' + lv : ''}">${D().mark(lv)}${sum}</span>` : ''}</div>`;
	}

	/** Bubble of a widget (the one of the application, never a bare title): its name, what it shows, where a click goes */
	function tip(title, lines, url) {
		if (!I.tip) {
			return '';
		}
		return I.tip(html`<div class="ibx-wgtip"><b>${title || ''}</b>${lines.filter(Boolean).map(l => html`<div>${l}</div>`)}${D().inside(url) ? html`<div class="ibx-muted">${t('InboxWgTipOpen')}</div>` : ''}</div>`);
	}

	const TEMPLATES = {
		mini: d => {
			const lv = level(d.level);
			const inner = html`${raw(ic(ICON.test(String(d.icon)) ? d.icon : 'info'))}<b class="ibx-wg__value">${d.value}</b><span class="ibx-wg__label">${d.label || ''}</span><span class="ibx-sr-only">${d.title || ''}</span>`;
			return html`<div class="ibx-wg ibx-wg--mini${lv ? ' ibx-wg--' + lv : ''}" data-objtip="${tip(d.title, [[d.value, d.label].filter(Boolean).join(' '), d.note], d.url)}">${link(d.url, inner, 'ibx-wg__mini')}</div>`;
		},
		line: d => html`<div class="ibx-wg ibx-wg--line">${raw(ic(ICON.test(String(d.icon)) ? d.icon : 'info'))}<span class="ibx-wg__text">${link(d.url, d.text)}</span>${d.note ? html`<span class="ibx-wg__note">${d.note}</span>` : ''}</div>`,
		figure: d => html`<div class="ibx-wg ibx-wg--figure"><dl class="ibx-ov-kpis">${D().kpi(d)}</dl></div>`,
		list: d => html`<div class="ibx-wg ibx-wg--list">${head(d.title, d.url, d.sum, d.sumLevel)}${list(d.rows).length ? html`<dl class="ibx-ov-rows">${list(d.rows).map(D().row)}</dl>` : ''}</div>`,
		chart: d => html`<div class="ibx-wg ibx-wg--chart">${head(d.title, '', '', '')}${d.chart ? D().bars(d.chart, !!d.small) : ''}</div>`,
		action: d => html`<div class="ibx-wg ibx-wg--action">${head(d.title, '', d.sum, d.sumLevel)}
			${list(d.rows).length ? html`<dl class="ibx-ov-rows">${list(d.rows).map(D().row)}</dl>` : ''}
			<div class="ibx-wg__actions">${list(d.actions).map(a => (D().inside(a.url)
				? html`<a class="ibx-btn ibx-btn--sm" href="${a.url}" target="_blank" rel="noopener">${raw(ic(ICON.test(String(a.icon)) ? a.icon : 'plus'))}${a.label}</a>`
				: html`<span class="ibx-btn ibx-btn--sm" aria-disabled="true">${raw(ic(ICON.test(String(a.icon)) ? a.icon : 'plus'))}${a.label}</span>`))}</div></div>`,
		thread: d => html`<div class="ibx-wg ibx-wg--thread">${head(d.title || t('InboxNotesAndReplies'), '', '', '')}</div>`,
	};

	/**
	 * One widget from its data
	 * @param {string} template Template
	 * @param {object|null} data Data (null: nothing to show for this mail)
	 * @param {object} [hint] Preview of the widget (icon, title), for a mini without data
	 * @returns {SafeHtml|string}
	 */
	function render(template, data, hint) {
		const fn = TEMPLATES[template];
		if (!fn || !D()) {
			return '';
		}
		if (!data) {
			// Nothing for this mail: a mini keeps its icon with a dash, the others say it (the widget keeps its place)
			if (template === 'mini') {
				const icon = hint && ICON.test(String(hint.icon)) ? hint.icon : 'info';
				return html`<div class="ibx-wg ibx-wg--mini ibx-wg--none" data-objtip="${tip(hint && hint.title, [t('InboxOvSectionEmpty')], '')}" tabindex="0"><span class="ibx-wg__mini">${raw(ic(icon))}<b class="ibx-wg__value">–</b><span class="ibx-sr-only">${(hint && hint.title) || ''} · ${t('InboxOvSectionEmpty')}</span></span></div>`;
			}
			return html`<div class="ibx-wg ibx-wg--empty">${t('InboxOvSectionEmpty')}</div>`;
		}
		return fn(data);
	}

	let catalogueCache = null;
	/** Catalogue of the user: widgets he may place, with their size and a preview (never computed) */
	async function catalogue() {
		if (!catalogueCache) {
			catalogueCache = api('widget', 'catalogue', {}).then(r => list(r.widgets)).catch(err => {
				catalogueCache = null;
				throw err;
			});
		}
		return catalogueCache;
	}
	/**
	 * Data of widgets for a mail, in one call
	 * @param {number} mail Row of the mail
	 * @param {string[]} ids Widgets
	 * @returns {Promise<object>} id => data or null
	 */
	async function data(mail, ids) {
		const res = await api('widget', 'data', { id: mail, w: ids.filter(id => /^[a-z0-9_]{1,40}$/.test(id)).join(',') });
		return res && res.data && typeof res.data === 'object' ? res.data : {};
	}

	I.widgets = { render, catalogue, data, templates: Object.keys(TEMPLATES) };
})();
