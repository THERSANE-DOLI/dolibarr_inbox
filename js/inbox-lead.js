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
 * New customer from a mail (lot A1, 1.79.0): a side panel on the left, over the folders and the list, the mail left
 * readable on the right. Each value shows its source (its line of the mail lit on hover) and the other values found;
 * « Create » makes the thirdparty and its contact, « Full page… » opens the page of Dolibarr, filled.
 *
 * Modules: the event « inbox:lead:render » on document gives { panel, slot, mail, found, get(part, field),
 * set(part, field, value, source) }; a module appends its block to slot (values of a register of companies...).
 */
(function () {
	'use strict';

	const I = Dolibarr.tools.inbox;
	const { esc, t, th, ic, api, post, toast, $, $$ } = I;

	/** Fields of the panel: [part, field, label key, main (else under « More fields »)] */
	const FIELDS = [
		['soc', 'name', 'InboxLeadName', true], ['soc', 'phone', 'InboxLeadPhone', true], ['soc', 'address', 'InboxLeadAddress', true],
		['soc', 'zipcode', 'InboxLeadZip', true], ['soc', 'town', 'InboxLeadTown', true], ['soc', 'idprof2', '', true], ['soc', 'tva_intra', '', true], ['soc', 'url', 'InboxLeadUrl', true],
		['soc', 'email', 'InboxLeadEmail', false], ['soc', 'idprof1', '', false],
		['contact', 'firstname', 'InboxLeadFirstname', true], ['contact', 'lastname', 'InboxLeadLastname', true], ['contact', 'poste', 'InboxLeadJob', true],
		['contact', 'phone_mobile', 'InboxLeadMobile', true], ['contact', 'phone_pro', 'InboxLeadPhonePro', false], ['contact', 'email', 'InboxLeadEmail', true],
	];
	const SOURCES = ['header', 'vcard', 'name', 'signature', 'form', 'domain', 'sender', 'computed', 'dictionary'];

	let current = null;

	/** Source of a value, as a chip; a module gives its own word */
	function sourceLabel(s) {
		return SOURCES.includes(s) ? t('InboxLeadSource_' + s) : String(s || '');
	}

	/** The line of the mail a value comes from, lit in the reading (same origin frame) */
	function highlight(quote, on) {
		const frame = $('.ibx-pane--read .ibx-mailframe');
		const doc = frame && frame.contentDocument;
		if (!doc || !doc.body) {
			return;
		}
		$$('mark[data-ibx-lead]', doc).forEach(m => m.replaceWith(...m.childNodes));
		if (!on || !quote) {
			return;
		}
		const needle = quote.replace(/\s+/g, ' ').trim().slice(0, 40);
		const walker = doc.createTreeWalker(doc.body, NodeFilter.SHOW_TEXT);
		let n;
		while ((n = walker.nextNode())) {
			const text = n.nodeValue.replace(/\s+/g, ' ');
			const at = text.indexOf(needle.slice(0, Math.min(needle.length, 24)));
			if (at >= 0 && n.nodeValue.length >= at) {
				const mark = doc.createElement('mark');
				mark.setAttribute('data-ibx-lead', '1');
				mark.style.cssText = 'background:#fff1a8;box-shadow:0 0 0 2px #e8c84a;border-radius:2px';
				n.parentNode.insertBefore(mark, n);
				mark.appendChild(n);
				mark.scrollIntoView({ block: 'center', behavior: 'smooth' });
				return;
			}
		}
	}

	/** A field: its input, the source of its value, the other values found */
	function fieldHtml(st, part, field, label, required) {
		const cands = (st.found[part] || {})[field] || [];
		const first = cands[0];
		const id = 'ibx-lead-' + part + '-' + field;
		const value = st.values[part][field] || '';
		return '<label class="ibx-lead__label" for="' + id + '">' + esc(label) + (required ? ' <span class="ibx-req" aria-hidden="true">*</span>' : '') + '</label>'
			+ '<div class="ibx-lead__field">'
			+ '<input class="ibx-field" id="' + id + '" data-part="' + part + '" data-field="' + field + '" value="' + esc(value) + '"' + (required ? ' required aria-required="true"' : '') + ' autocomplete="off">'
			+ (field === 'firstname' ? '<button type="button" class="ibx-btn ibx-btn--sm ibx-js-leadswap" title="' + th('InboxLeadSwap') + '">' + ic('arrows-exchange') + ' ' + th('InboxLeadSwapShort') + '</button>' : '')
			+ (first && value === first.value ? '<span class="ibx-lead__src" tabindex="0" data-quote="' + esc(first.quote || '') + '" title="' + esc(first.quote || '') + '">' + esc(sourceLabel(first.source)) + '</span>' : '')
			+ (cands.length > 1 ? '<button type="button" class="ibx-lead__more ibx-js-leadalt" data-part="' + part + '" data-field="' + field + '" aria-haspopup="menu" aria-label="' + esc(t('InboxLeadOthers', cands.length - 1)) + '">+' + (cands.length - 1) + '</button>' : '')
			+ '</div>';
	}

	/** Label of a field: idprof of the country of the company */
	function labelOf(st, part, field, key) {
		if (part === 'soc' && (field === 'idprof1' || field === 'idprof2' || field === 'tva_intra')) {
			return st.form.idprof[field] || field;
		}
		return t(key);
	}

	/** Initial values: the first value found, else the default values of the page of creation */
	function initialValues(st) {
		const v = { soc: {}, contact: {} };
		['soc', 'contact'].forEach(part => {
			Object.keys(st.found[part] || {}).forEach(f => {
				if ((st.found[part][f] || []).length) {
					v[part][f] = st.found[part][f][0].value;
				}
			});
			Object.entries(st.form.defaults[part] || {}).forEach(([f, val]) => {
				if (v[part][f] === undefined || v[part][f] === '') {
					v[part][f] = val;
				}
			});
		});
		return v;
	}

	function render(st) {
		const f = st.form;
		const req = { soc: new Set(f.required.soc), contact: new Set(f.required.contact) };
		const last = f.last || {};
		const def = f.defaults.soc || {};
		const natures = [['prospect', 'InboxLeadProspect'], ['customer', 'InboxLeadCustomer'], ['supplier', 'InboxLeadSupplier']].filter(([k]) => f.natures[k]);
		if (!st.opts) {
			// Nature: the default values of the page of creation, else the usage of the mailbox (purchase: supplier),
			// else the last choice; nothing else is taken again in silence (categories, sales representatives, country)
			const sug = st.suggest || {};
			const fromDef = def.client !== undefined ? { prospect: +def.client === 2 || +def.client === 3, customer: +def.client === 1 || +def.client === 3, supplier: !!+def.fournisseur } : null;
			const fromBox = !fromDef && sug.nature ? { prospect: sug.nature === 'prospect', customer: sug.nature === 'customer', supplier: sug.nature === 'supplier' } : null;
			const nat = fromDef || fromBox || { prospect: last.prospect !== undefined ? !!last.prospect : true, customer: !!last.customer, supplier: !!last.supplier };
			const defCom = def.commercial !== undefined ? String(def.commercial).split(',').map(x => +x).filter(Boolean) : null;
			st.opts = Object.assign(nat, {
				private: !!st.found.private, commercial: defCom || (sug.commercial || []).slice(), commercialWhy: defCom ? '' : (sug.commercial_why || ''),
				// Subject to VAT ticked as on the page of Dolibarr, unless its default values say otherwise
				vat_subject: def.assujtva_value !== undefined ? !!+def.assujtva_value : true,
				categories: [], categories_supplier: [], contact: true, use_soc: 0,
			});
			if (!st.values.soc.typent_id && last.typent_id) {
				st.values.soc.typent_id = String(last.typent_id);
			}
			if (!st.values.soc.country_id) {
				st.values.soc.country_id = String(f.country_id || '');
			}
		}
		const o = st.opts;
		const useSoc = o.use_soc ? st.duplicates.find(d => d.id === o.use_soc) : null;
		const blockers = f.blockers.soc;
		// A private person: its name is the one of the contact
		const shown = x => !(o.private && x[0] === 'soc' && x[1] === 'name');
		const html = list => list.map(x => fieldHtml(st, x[0], x[1], labelOf(st, x[0], x[1], x[2]), req[x[0]].has(x[1]))).join('');
		const mainSoc = html(FIELDS.filter(x => x[0] === 'soc' && shown(x) && (x[3] || req.soc.has(x[1]))));
		const moreSoc = html(FIELDS.filter(x => x[0] === 'soc' && shown(x) && !x[3] && !req.soc.has(x[1])));
		const allContact = html(FIELDS.filter(x => x[0] === 'contact'));
		const moduleFields = part => (st.found.fields || []).filter(x => x.part === part).map(x => fieldHtml(st, part, x.field, x.label, false)).join('');
		const dups = st.duplicates.length && !useSoc ? '<div class="ibx-notice ibx-notice--warning ibx-lead__dups">' + ic('warning') + '<div class="ibx-notice__text"><b>' + th('InboxLeadDupTitle') + '</b>'
			+ st.duplicates.map(d => '<div class="ibx-lead__dup"><span>' + esc(d.name) + (d.town ? ' <span class="ibx-muted">' + esc(d.town) + '</span>' : '') + '<br><span class="ibx-muted">'
				+ d.why.map(w => esc(t('InboxLeadWhy_' + w))).join(', ') + '</span></span>'
				+ (f.can.contact ? '<button type="button" class="ibx-btn ibx-btn--sm ibx-js-leaduse" data-soc="' + d.id + '">' + th('InboxLeadUseThis') + '</button>' : '') + '</div>').join('')
			+ '</div></div>' : '';
		const socPart = useSoc ? '<div class="ibx-notice">' + ic('info') + '<div class="ibx-notice__text">' + esc(t('InboxLeadContactOf', useSoc.name))
			+ ' <button type="button" class="ibx-btn ibx-btn--sm ibx-btn--ghost ibx-js-leadunuse">' + th('InboxLeadNewInstead') + '</button></div></div>'
			: '<h3 class="ibx-lead__title">' + th('InboxLeadSoc') + '</h3><div class="ibx-lead__grid">'
				+ (natures.length ? '<span class="ibx-lead__label">' + th('InboxLeadNature') + ' <span class="ibx-req" aria-hidden="true">*</span></span><div class="ibx-lead__inline" role="group" aria-label="' + th('InboxLeadNature') + '">'
					// As the page of creation of Dolibarr: the label then its box, the colour of the nature once ticked
					+ natures.map(([k, key]) => '<label class="ibx-lead__nature ibx-lead__nature--' + k + '"><span>' + th(key) + '</span><input type="checkbox" class="ibx-js-leadopt" data-opt="' + k + '"' + (o[k] ? ' checked' : '') + '></label>').join('') + '</div>' : '')
				+ '<span class="ibx-lead__label">' + th('InboxLeadKind') + '</span><div class="ibx-lead__inline" role="radiogroup" aria-label="' + th('InboxLeadKind') + '">'
				+ '<label class="ibx-inline"><input type="radio" name="ibx-lead-kind" class="ibx-js-leadkind" value="0"' + (o.private ? '' : ' checked') + '> ' + th('InboxLeadCompany') + '</label>'
				+ '<label class="ibx-inline"><input type="radio" name="ibx-lead-kind" class="ibx-js-leadkind" value="1"' + (o.private ? ' checked' : '') + '> ' + th('InboxLeadPrivate') + '</label></div>'
				+ (f.types.length ? '<label class="ibx-lead__label" for="ibx-lead-typent">' + th('InboxLeadType') + '</label><div class="ibx-lead__field"><select class="ibx-field" id="ibx-lead-typent" data-combo data-part="soc" data-field="typent_id"><option value=""></option>'
					+ f.types.map(x => '<option value="' + x.id + '"' + (String(x.id) === String(st.values.soc.typent_id || '') ? ' selected' : '') + '>' + esc(x.label) + '</option>').join('') + '</select></div>' : '')
				+ (o.customer || o.prospect ? (f.code.auto ? '<span class="ibx-lead__label">' + th('InboxLeadCode') + '</span><div class="ibx-muted">' + th('InboxLeadCodeAuto') + '</div>'
					: (f.code.required ? fieldHtml(st, 'soc', 'customer_code', t('InboxLeadCode'), true) : '')) : '')
				+ mainSoc + moduleFields('soc')
				// Sales representatives: always in sight, nobody by default; the person the mail is assigned to or the owner
				// of a personal mailbox offered
				+ '<label class="ibx-lead__label" for="ibx-lead-com">' + th('InboxLeadSalesRep') + '</label><div class="ibx-lead__field"><select class="ibx-field" id="ibx-lead-com" multiple data-combo data-combo-chips'
				+ ' data-combo-placeholder="' + th('InboxLeadSalesRepNone') + '">' + f.users.map(u => '<option value="' + u.id + '"' + (o.commercial.includes(u.id) ? ' selected' : '') + '>' + esc(u.label) + '</option>').join('') + '</select>'
				+ (o.commercialWhy ? '<span class="ibx-lead__src" title="' + th('InboxLeadSalesRepWhy_' + o.commercialWhy) + '">' + th('InboxLeadSalesRepSrc_' + o.commercialWhy) + '</span>' : '') + '</div>'
				// Subject to VAT: where it matters most, for a supplier, in sight
				+ (o.supplier ? vatHtml(o) : '') + '</div>'
				+ '<details class="ibx-lead__moredet"' + (st.moreOpen ? ' open' : '') + '><summary class="ibx-js-moresum">' + esc(moreSummary(st)) + '</summary><div class="ibx-lead__grid">'
				+ moreSoc
				+ '<label class="ibx-lead__label" for="ibx-lead-country">' + th('InboxLeadCountry') + '</label><div class="ibx-lead__field"><select class="ibx-field" id="ibx-lead-country" data-combo data-part="soc" data-field="country_id"><option value=""></option>'
				+ f.countries.map(x => '<option value="' + x.id + '"' + (String(x.id) === String(st.values.soc.country_id || '') ? ' selected' : '') + '>' + esc(x.label) + '</option>').join('') + '</select></div>'
				+ (o.supplier ? '' : vatHtml(o))
				// Categories as Dolibarr shows them: colour, tree, chips; the supplier ones when « Supplier » is ticked
				+ catsField('ibx-lead-cats', 'InboxLeadCategories', f.categories, o.categories, o.prospect || o.customer)
				+ catsField('ibx-lead-catssup', 'InboxLeadCategoriesSupplier', f.categories_supplier || [], o.categories_supplier, o.supplier)
				+ '</div></details>';
		const contactPart = o.private && !useSoc ? '<p class="ibx-muted">' + th('InboxLeadPrivateHelp') + '</p>'
			: '<h3 class="ibx-lead__title"><label class="ibx-inline"><input type="checkbox" class="ibx-js-leadopt" data-opt="contact"' + (o.contact || useSoc ? ' checked' : '') + (useSoc ? ' disabled' : '') + '> ' + th('InboxLeadContactToo') + '</label></h3>'
				+ (o.contact || useSoc ? '<div class="ibx-lead__grid">' + allContact + moduleFields('contact') + '</div>'
					+ (f.blockers.contact.length ? '<p class="ibx-muted">' + esc(t('InboxLeadContactLater', f.blockers.contact.join(', '))) + '</p>' : '') : '');
		const privateNames = o.private && !useSoc ? '<div class="ibx-lead__grid">' + fieldHtml(st, 'contact', 'firstname', t('InboxLeadFirstname'), false) + fieldHtml(st, 'contact', 'lastname', t('InboxLeadLastname'), true) + '</div>' : '';
		const canQuick = useSoc ? f.can.contact : f.can.soc;
		const full = !useSoc && blockers.length;
		st.panel.innerHTML = '<div class="ibx-lead__head"><h2 class="ibx-lead__h">' + th('InboxLeadTitle') + '</h2>'
			+ '<button type="button" class="ibx-btn ibx-btn--icon ibx-js-leadclose" aria-label="' + th('InboxLeadClose') + '" title="' + th('InboxLeadClose') + '">' + ic('chevron-left') + '</button></div>'
			+ '<div class="ibx-lead__body">' + dups + (st.found.domain_category === 'disposable' ? '<div class="ibx-notice ibx-notice--warning">' + ic('warning') + '<div class="ibx-notice__text">' + th('InboxDomainsDisposableCreate', st.found.domain) + '</div></div>' : '')
			+ socPart + privateNames + '<div class="ibx-lead__modules ibx-js-leadmodules"></div>' + contactPart + '</div>'
			+ '<div class="ibx-lead__foot">'
			+ (!canQuick ? '<div class="ibx-notice">' + ic('info') + '<div class="ibx-notice__text">' + th('InboxLeadNoRight') + '</div></div>' : '')
			+ (full ? '<div class="ibx-notice">' + ic('info') + '<div class="ibx-notice__text">' + esc(t('InboxLeadNeedFull', blockers.join(', '))) + '</div></div>' : '<p class="ibx-formrow__help">' + th(useSoc ? 'InboxLeadHelpContact' : 'InboxLeadHelp') + '</p>')
			+ '<div class="ibx-lead__actions"><button type="button" class="ibx-btn ibx-js-leadclose">' + th('InboxCancel') + '</button><span class="ibx-spacer"></span>'
			+ (useSoc ? '' : '<button type="button" class="ibx-btn' + (full ? ' ibx-btn--primary' : '') + ' ibx-js-leadfull"' + (f.can.soc ? '' : ' disabled') + '>' + th(full ? 'InboxLeadOpenFull' : 'InboxLeadFull') + '</button>')
			+ (full ? '' : '<button type="button" class="ibx-btn ibx-btn--primary ibx-js-leadcreate"' + (canQuick ? '' : ' disabled') + '>' + th('InboxLeadCreate') + '</button>')
			+ '</div></div>';
		// Modules: their block in the panel, with the values at hand
		document.dispatchEvent(new CustomEvent('inbox:lead:render', { detail: {
			panel: st.panel, slot: $('.ibx-js-leadmodules', st.panel), mail: st.mail, found: st.found,
			get: (part, field) => st.values[part][field] || '',
			set: (part, field, value, source) => {
				st.values[part][field] = String(value);
				(st.found[part][field] = st.found[part][field] || []).unshift({ value: String(value), source: source || 'module' });
				render(st);
			},
		} }));
		checkReady(st);
	}

	function vatHtml(o) {
		return '<span class="ibx-lead__label">' + th('InboxLeadVat') + '</span><label class="ibx-inline"><input type="checkbox" class="ibx-js-leadopt" data-opt="vat_subject"' + (o.vat_subject ? ' checked' : '') + '> ' + th('InboxLeadVatSubject') + '</label>';
	}

	/**
	 * Line of « More fields » folded: every value it holds, so that nothing filled stays out of sight
	 */
	function moreSummary(st) {
		const f = st.form;
		const v = st.values.soc;
		const o = st.opts;
		const parts = [];
		const req = new Set(f.required.soc);
		FIELDS.filter(x => x[0] === 'soc' && !x[3] && !req.has(x[1])).forEach(x => {
			if ((v[x[1]] || '').trim()) {
				parts.push(v[x[1]].trim());
			}
		});
		const country = f.countries.find(c => String(c.id) === String(v.country_id || ''));
		if (country) {
			parts.push(country.label);
		}
		if (!o.supplier) {
			parts.push(t(o.vat_subject ? 'InboxLeadVatSubject' : 'InboxLeadVatNotSubject'));
		}
		const cats = (o.prospect || o.customer ? o.categories : []).concat(o.supplier ? o.categories_supplier : []);
		const names = cats.map(id => ((f.categories || []).concat(f.categories_supplier || []).find(c => c.id === id) || {}).label).filter(Boolean);
		if (names.length) {
			parts.push(names.length > 2 ? t('InboxLeadCategoriesCount', names.length) : names.join(', '));
		}
		return t('InboxLeadMore') + (parts.length ? ' : ' + parts.join(' · ') : '');
	}

	/** A field of categories: chips of their colour, the tree kept, searched on the whole path */
	function catsField(id, key, list, chosen, shown) {
		if (!list.length || !shown) {
			return '';
		}
		return '<label class="ibx-lead__label" for="' + id + '">' + th(key) + '</label><div class="ibx-lead__field"><select class="ibx-field" id="' + id + '" multiple data-combo data-combo-chips>'
			+ list.map(c => '<option value="' + c.id + '"' + (chosen.includes(c.id) ? ' selected' : '') + (c.color ? ' data-color="' + esc(c.color) + '"' : '')
				+ ' data-depth="' + (+c.depth || 0) + '" data-path="' + esc(c.path || c.label) + '">' + esc(c.label) + '</option>').join('') + '</select></div>';
	}

	/** « Create » greyed while a mandatory field is empty */
	function checkReady(st) {
		const btn = $('.ibx-js-leadcreate', st.panel);
		if (!btn) {
			return;
		}
		const o = st.opts;
		const empty = $$('input[required]', st.panel).some(i => !i.value.trim());
		const nature = st.opts.use_soc || o.prospect || o.customer || o.supplier;
		btn.disabled = empty || !nature || !(st.opts.use_soc ? st.form.can.contact : st.form.can.soc);
	}

	function payload(st) {
		const o = st.opts;
		const soc = Object.assign({}, st.values.soc);
		// A private person: its names go with the thirdparty (Dolibarr makes its contact)
		const contact = o.use_soc || o.private || o.contact ? Object.assign({}, st.values.contact) : null;
		return { id: st.mail, soc, contact, opts: { prospect: o.prospect ? 1 : 0, customer: o.customer ? 1 : 0, supplier: o.supplier ? 1 : 0, private: o.private ? 1 : 0,
			vat_subject: o.vat_subject ? 1 : 0, commercial: o.commercial, categories: o.prospect || o.customer ? o.categories : [], categories_supplier: o.supplier ? o.categories_supplier : [],
			use_soc: o.use_soc || 0 } };
	}

	async function create(st) {
		const btn = $('.ibx-js-leadcreate', st.panel);
		btn.disabled = true;
		let res;
		try {
			res = await post('lead', 'create', payload(st));
		} catch (err) {
			btn.disabled = false;
			if (err.code === 'need_full') {
				st.form.blockers.soc = String(err.message || '').split(', ').filter(Boolean);
				render(st);
			}
			toast(err.message, { error: true });
			return;
		}
		const socName = st.opts.use_soc ? (st.duplicates.find(d => d.id === st.opts.use_soc) || {}).name : (st.opts.private ? [st.values.contact.firstname, st.values.contact.lastname].filter(Boolean).join(' ') : st.values.soc.name);
		const who = [st.values.contact.firstname, st.values.contact.lastname].filter(Boolean).join(' ');
		const done = st.onDone;
		close();
		const undoSoc = st.opts.use_soc ? 0 : res.soc;
		toast(res.contact ? (st.opts.use_soc ? t('InboxLeadContactAdded', who, socName) : t('InboxLeadCreatedBoth', socName, who)) : t('InboxLeadCreated', socName), {
			duration: 8000, action: t('InboxLeadUndo'), onAction: async () => {
				try {
					await post('lead', 'undo', { soc: undoSoc, contact: res.contact });
					toast(t('InboxLeadUndone'));
				} catch (err) {
					toast(err.message, { error: true });
				}
				if (done) {
					done();
				}
			},
		});
		if (res.contact_error) {
			toast(t('InboxLeadContactLater', res.contact_error), { duration: 8000 });
		}
		if (done) {
			done();
		}
	}

	async function full(st) {
		// The tab is opened at the click (a tab opened after an answer is blocked by the browser)
		const tab = window.open('about:blank', '_blank');
		let res;
		try {
			res = await post('lead', 'full', payload(st));
		} catch (err) {
			if (tab) {
				tab.close();
			}
			toast(err.message, { error: true });
			return;
		}
		if (tab) {
			tab.opener = null;
			tab.location.href = res.url;
		} else {
			window.open(res.url, '_blank', 'noopener');
		}
		const done = st.onDone;
		close();
		toast(t('InboxLeadFullOpened'));
		if (done) {
			window.addEventListener('focus', () => done(), { once: true });
		}
	}

	function events(st) {
		const p = st.panel;
		p.addEventListener('input', e => {
			const el = e.target.closest('[data-part][data-field]');
			if (el) {
				st.values[el.dataset.part][el.dataset.field] = el.value;
				checkReady(st);
			}
		});
		p.addEventListener('change', e => {
			const el = e.target;
			if (el.matches('[data-part][data-field]')) {
				st.values[el.dataset.part][el.dataset.field] = el.value;
			} else if (el.matches('.ibx-js-leadopt')) {
				st.opts[el.dataset.opt] = el.checked;
				render(st);
			} else if (el.matches('.ibx-js-leadkind')) {
				st.opts.private = el.value === '1';
				render(st);
			} else if (el.id === 'ibx-lead-com') {
				st.opts.commercial = Array.from(el.selectedOptions).map(x => +x.value);
				st.opts.commercialWhy = '';
			} else if (el.id === 'ibx-lead-cats' || el.id === 'ibx-lead-catssup') {
				st.opts[el.id === 'ibx-lead-cats' ? 'categories' : 'categories_supplier'] = Array.from(el.selectedOptions).map(x => +x.value);
			}
		});
		p.addEventListener('toggle', e => {
			if (e.target.matches && e.target.matches('.ibx-lead__moredet')) {
				st.moreOpen = e.target.open;
				const sum = $('.ibx-js-moresum', p);
				if (sum) {
					sum.textContent = moreSummary(st);
				}
			}
		}, true);
		p.addEventListener('click', e => {
			const b = e.target.closest('button');
			if (!b) {
				return;
			}
			if (b.matches('.ibx-js-leadclose')) {
				close();
			} else if (b.matches('.ibx-js-leadcreate')) {
				create(st);
			} else if (b.matches('.ibx-js-leadfull')) {
				full(st);
			} else if (b.matches('.ibx-js-leaduse')) {
				st.opts.use_soc = +b.dataset.soc;
				render(st);
			} else if (b.matches('.ibx-js-leadunuse')) {
				st.opts.use_soc = 0;
				render(st);
			} else if (b.matches('.ibx-js-leadswap')) {
				const c = st.values.contact;
				[c.firstname, c.lastname] = [c.lastname || '', c.firstname || ''];
				render(st);
			} else if (b.matches('.ibx-js-leadalt')) {
				const part = b.dataset.part;
				const field = b.dataset.field;
				I.menu(b, (st.found[part][field] || []).map(c => ({ label: c.value + '  (' + sourceLabel(c.source) + ')', run: () => {
					st.values[part][field] = c.value;
					// The value chosen first: its source shown
					st.found[part][field] = [c].concat(st.found[part][field].filter(x => x !== c));
					render(st);
				} })));
			}
		});
		const lit = (e, on) => {
			const s = e.target.closest && e.target.closest('.ibx-lead__src');
			if (s) {
				highlight(s.dataset.quote, on);
			}
		};
		p.addEventListener('mouseover', e => lit(e, true));
		p.addEventListener('mouseout', e => lit(e, false));
		p.addEventListener('focusin', e => lit(e, true));
		p.addEventListener('focusout', e => lit(e, false));
		p.addEventListener('keydown', e => {
			if (e.key === 'Escape') {
				close();
			}
		});
	}

	/**
	 * The wheel stays in the panel: it scrolls the part under the pointer when it can, else nothing (never the list
	 * of the mails under the panel). Set once on the panel, whatever it shows.
	 */
	function holdWheel(panel) {
		panel.addEventListener('wheel', e => {
			if (e.ctrlKey) {
				return;
			}
			for (let el = e.target; el && el !== panel; el = el.parentElement) {
				const style = getComputedStyle(el);
				if (/(auto|scroll)/.test(style.overflowY) && el.scrollHeight > el.clientHeight + 1) {
					const down = e.deltaY > 0;
					if ((down && el.scrollTop + el.clientHeight < el.scrollHeight - 1) || (!down && el.scrollTop > 0)) {
						return;
					}
				}
			}
			e.preventDefault();
		}, { passive: false });
	}

	function close() {
		if (!current) {
			return;
		}
		highlight('', false);
		const app = current.app;
		current.panel.remove();
		app.removeAttribute('data-lead');
		const back = current.back;
		current = null;
		if (back && back.isConnected) {
			back.focus();
		}
	}

	/**
	 * Open the panel for a mail
	 * @param {{mail:number, app:HTMLElement, onDone?:Function, back?:HTMLElement}} o
	 */
	async function open(o) {
		close();
		const panel = document.createElement('aside');
		panel.className = 'ibx-lead';
		panel.setAttribute('aria-label', t('InboxLeadTitle'));
		panel.innerHTML = '<div class="ibx-lead__head"><h2 class="ibx-lead__h">' + th('InboxLeadTitle') + '</h2></div><div class="ibx-lead__body"><span class="ibx-skeleton"></span><span class="ibx-skeleton" style="width:70%"></span><span class="ibx-skeleton" style="width:85%"></span></div>';
		// Over the folders and the list: the reading stays whole on the right
		const read = $('.ibx-pane--read', o.app);
		const width = read ? read.getBoundingClientRect().left - o.app.getBoundingClientRect().left : 0;
		panel.style.width = Math.max(380, Math.min(width || 520, 640)) + 'px';
		o.app.setAttribute('data-lead', 'open');
		holdWheel(panel);
		o.app.appendChild(panel);
		const st = { mail: o.mail, app: o.app, panel, onDone: o.onDone, back: o.back, values: null, opts: null, moreOpen: false };
		current = st;
		let res;
		try {
			res = await api('lead', 'open', { id: o.mail });
		} catch (err) {
			if (current === st) {
				close();
			}
			toast(err.message, { error: true });
			return;
		}
		if (current !== st) {
			return;
		}
		st.found = res.found;
		st.duplicates = res.duplicates || [];
		st.form = res.form;
		st.suggest = res.suggest || {};
		st.values = initialValues(st);
		events(st);
		render(st);
		const first = $('input:not([type="checkbox"]):not([type="radio"])', panel);
		if (first) {
			first.focus();
		}
	}

	I.lead = { open, close };
})();
