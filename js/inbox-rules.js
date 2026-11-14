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
 * Rules and out of office of a mailbox (lot W8 1.23.0), shared by the card of the mailbox (Settings, tabs Rules and
 * Out of office) and the Mail space (panel on the right, editor over the mail read).
 */
(function () {
	'use strict';

	const I = Dolibarr.tools.inbox;
	const { esc, t, th, ic, api, post, toast, dialog, confirmDialog, $, $$ } = I;
	const skeleton = lines => I.settingsUi.skeleton(lines);
	const errorBox = (err, retry) => I.settingsUi.errorBox(err, retry);

	// ---------- Rules and out of office (lot W8 1.23.0, maquette regles.html) ----------
	const RULE_FIELDS = ['from', 'to', 'cc', 'tocc', 'subject', 'list', 'header'];
	const RULE_OPS = ['contains', 'not', 'is', 'starts', 'ends'];
	const RULE_ACTIONS = ['move', 'copy', 'tag', 'read', 'flag', 'delete', 'redirect'];
	const RULE_NO_VALUE = ['read', 'flag', 'delete'];
	const rulesCache = {};
	const locale = I.locale;

	/** Name of a folder for a person: its path without INBOX, levels separated by « / » */
	function rulePathName(path, folders) {
		const f = (folders || []).find(x => x.path === path);
		if (f && f.role === 'inbox') {
			return t('InboxFolderRoleInbox');
		}
		return String(path).replace(/^INBOX[./]/i, '').replace(/[./]/g, ' / ');
	}

	function ruleSummary(r, st) {
		const tagLabel = k => ((st.tags || []).find(x => x.keyword.toLowerCase() === String(k).toLowerCase()) || { label: k }).label;
		const conds = r.conds.map(c => th('InboxRuleField_' + c.f) + (c.f === 'header' ? ' ' + esc(c.h) : '') + ' ' + th('InboxRuleOp_' + c.op) + ' <b>' + esc(c.v) + '</b>')
			.join(' ' + th(r.all ? 'InboxRuleAnd' : 'InboxRuleOr') + ' ');
		const acts = r.acts.map(a => {
			if (a.a === 'move' || a.a === 'copy') {
				return th('InboxRuleDo_' + a.a) + ' <b>' + esc(rulePathName(a.v, st.folders)) + '</b>';
			}
			if (a.a === 'tag') {
				return th('InboxRuleDo_tag') + ' <b>' + esc(tagLabel(a.v)) + '</b>';
			}
			if (a.a === 'redirect') {
				return th('InboxRuleDo_redirect') + ' <b>' + esc(a.v) + '</b>';
			}
			return th('InboxRuleDo_' + a.a);
		}).join(', ');
		return th('InboxRuleIf') + ' ' + conds + ' → ' + acts + (r.stop ? ', ' + th('InboxRuleThenStop') : '');
	}

	function ruleAutoName(r, st) {
		const c = r.conds.find(x => String(x.v || '').trim());
		const a = r.acts[0];
		return c ? t('InboxRuleField_' + c.f) + ' ' + c.v + (a && (a.a === 'move' || a.a === 'copy') && a.v ? ' → ' + rulePathName(a.v, st.folders) : '') : t('InboxRuleNew');
	}

	/** Notice of the state of the server: changed outside inbox (read only), or a script kept from before */
	function rulesNotice(st) {
		if (st.state === 'edited') {
			return '<div class="ibx-notice ibx-notice--warning">' + ic('warning') + '<div class="ibx-notice__text"><b>' + th('InboxRulesEditedTitle') + '</b> ' + th('InboxRulesEditedText')
				+ '<div class="ibx-formrow__actions" style="display:flex;gap:.5rem;flex-wrap:wrap"><button type="button" class="ibx-btn ibx-btn--sm ibx-js-rscript" data-name="inbox">' + th('InboxRulesSeeScript') + '</button>'
				+ '<button type="button" class="ibx-btn ibx-btn--sm ibx-btn--danger ibx-js-rtakeover">' + th('InboxRulesTakeOver') + '</button></div></div></div>';
		}
		if (st.foreign) {
			return '<div class="ibx-notice ibx-notice--info">' + ic('info') + '<div class="ibx-notice__text">' + th('InboxRulesForeign', st.foreign)
				+ ' <button type="button" class="ibx-linkbtn ibx-js-rscript" data-name="' + esc(st.foreign) + '">' + th('InboxRulesSeeIt') + '</button></div></div>';
		}
		return '';
	}

	async function loadRules(box, account, again) {
		box.innerHTML = skeleton(4);
		let st;
		try {
			st = await api('rules', 'get', { id: account.id });
		} catch (err) {
			box.replaceChildren(errorBox(err, () => again(box, account)));
			return null;
		}
		rulesCache[account.id] = st;
		return st;
	}

	async function rulesSave(account, action, data, box, again) {
		try {
			const st = await post('rules', action, Object.assign({ id: account.id }, data));
			rulesCache[account.id] = st;
			I.changed('account', account.id);
			return st;
		} catch (err) {
			toast(err.message + (err.data && err.data.detail ? ' : ' + err.data.detail : ''), { error: true });
			return null;
		}
	}

	async function tabRules(box, account) {
		const st = await loadRules(box, account, tabRules);
		if (!st) {
			return;
		}
		const ro = st.state === 'edited';
		const rules = st.rules || [];
		const savedLine = st.saved ? ic('check') + '<span>' + th('InboxRulesSavedOn', new Date(st.saved * 1000).toLocaleString(locale(), { dateStyle: 'short', timeStyle: 'short' }), st.by) + '</span>' : '';
		let h = '<p class="ibx-section__help">' + th('InboxRulesHelp') + '</p>' + rulesNotice(st)
			+ '<div class="ibx-rules-head"><h2 class="ibx-section__title">' + th('InboxTabRules') + ' <span class="ibx-muted">(' + rules.length + ')</span></h2><span class="ibx-spacer"></span>'
			// The rule of the anti-spam filter of the server, ready to use (1.44.0), when the mailbox has a junk folder
			+ (ro || !(st.folders || []).some(f => f.role === 'junk') ? '' : '<button type="button" class="ibx-btn ibx-js-rspam" title="' + th('InboxRuleSpamHelp') + '">' + ic('junk') + ' ' + th('InboxRuleSpam') + '</button>')
			+ (ro ? '' : '<button type="button" class="ibx-btn ibx-btn--primary ibx-js-rnew">' + ic('plus') + ' ' + th('InboxRuleNew') + '</button>') + '</div>';
		if (!rules.length) {
			h += '<div class="ibx-empty">' + ic('folder-bolt', 'ibx-empty__icon') + '<div class="ibx-empty__title">' + th('InboxRulesEmpty') + '</div><div>' + th('InboxRulesEmptyText') + '</div></div>';
		} else {
			h += '<ol class="ibx-rules">' + rules.map((r, i) => '<li class="ibx-rule' + (r.on ? '' : ' is-off') + '" data-i="' + i + '"' + (ro ? '' : ' draggable="true"') + '>'
				+ (ro ? '<span></span>' : '<button type="button" class="ibx-rule__grip" aria-label="' + th('InboxRuleMove', r.name) + '" title="' + th('InboxRuleMoveHelp') + '">⋮⋮</button>')
				+ '<span class="ibx-rule__num" aria-hidden="true">' + (i + 1) + '</span>'
				+ '<button type="button" class="ibx-switch" role="switch" aria-checked="' + !!r.on + '" aria-label="' + th('InboxRuleActive', r.name) + '"' + (ro ? ' aria-disabled="true"' : '') + ' data-toggle="' + i + '"></button>'
				+ '<div class="ibx-rule__main"><div class="ibx-rule__name">' + esc(r.name) + (r.on ? '' : ' <span class="ibx-status">' + th('InboxRuleOff') + '</span>') + '</div><div class="ibx-rule__sum">' + ruleSummary(r, st) + '</div></div>'
				+ '<div class="ibx-rule__acts"><button type="button" class="ibx-btn ibx-btn--sm" data-edit="' + i + '">' + th(ro ? 'InboxRuleSee' : 'InboxEdit') + '</button>'
				+ (ro ? '' : '<button type="button" class="ibx-btn ibx-btn--sm ibx-btn--icon" data-more="' + i + '" aria-label="' + th('InboxMoreActions') + '">' + ic('more') + '</button>') + '</div></li>').join('') + '</ol>';
		}
		h += '<div class="ibx-rules-foot">' + savedLine + '<span class="ibx-spacer"></span>'
			+ (!ro && st.previous ? '<button type="button" class="ibx-linkbtn ibx-js-rundo">' + th('InboxRulesUndo') + '</button>' : '') + '</div>';
		box.innerHTML = h;

		const save = async (list, text) => {
			const res = await rulesSave(account, 'save', { rules: JSON.stringify(list) });
			if (res) {
				toast(text);
				tabRules(box, account);
			}
			return !!res;
		};
		const ruleNew = $('.ibx-js-rnew', box);
		if (ruleNew) {
			ruleNew.addEventListener('click', () => ruleEditor(account, st, null, list => save(list, t('InboxRuleCreated'))));
		}
		// The mails marked spam by the filter of the server (SpamAssassin: X-Spam-Flag, Rspamd: X-Spam) into the junk,
		// on the server even when inbox is closed; the preview of the editor shows which mails it takes
		const ruleSpam = $('.ibx-js-rspam', box);
		if (ruleSpam) {
			ruleSpam.addEventListener('click', () => {
				const junk = st.folders.find(f => f.role === 'junk');
				ruleEditor(account, st, null, list => save(list, t('InboxRuleCreated')), false, {
					name: t('InboxRuleSpamName'), on: true, all: false, stop: true,
					conds: [{ f: 'header', h: 'X-Spam-Flag', op: 'contains', v: 'YES' }, { f: 'header', h: 'X-Spam', op: 'contains', v: 'Yes' }],
					acts: [{ a: 'move', v: junk.path }],
				});
			});
		}
		$$('[data-edit]', box).forEach(b => b.addEventListener('click', () => ruleEditor(account, st, +b.dataset.edit, list => save(list, t('InboxRuleSaved')), ro)));
		$$('[data-toggle]', box).forEach(b => b.addEventListener('click', () => {
			if (ro) {
				return;
			}
			const list = JSON.parse(JSON.stringify(rules));
			const r = list[+b.dataset.toggle];
			r.on = !r.on;
			b.setAttribute('aria-checked', r.on ? 'true' : 'false');
			save(list, t(r.on ? 'InboxRuleTurnedOn' : 'InboxRuleTurnedOff'));
		}));
		const move = (from, to) => {
			if (to < 0 || to >= rules.length || from === to) {
				return;
			}
			const list = JSON.parse(JSON.stringify(rules));
			const [r] = list.splice(from, 1);
			list.splice(to, 0, r);
			save(list, t('InboxRulesOrderSaved'));
		};
		$$('[data-more]', box).forEach(b => b.addEventListener('click', () => {
			const i = +b.dataset.more;
			const r = rules[i];
			I.menu(b, [
				{ icon: 'copy', label: t('InboxRuleDuplicate'), run: () => {
					const list = JSON.parse(JSON.stringify(rules));
					list.splice(i + 1, 0, Object.assign(JSON.parse(JSON.stringify(r)), { name: r.name + ' ' + t('InboxRuleCopySuffix'), on: false }));
					save(list, t('InboxRuleDuplicated'));
				} },
				{ icon: 'chevron-up', label: t('InboxRuleUp'), disabled: i === 0, run: () => move(i, i - 1) },
				{ icon: 'chevron-down', label: t('InboxRuleDown'), disabled: i === rules.length - 1, run: () => move(i, i + 1) },
				{ icon: 'done', label: t('InboxRuleApplyNow'), run: () => ruleApply(account, r) },
				{ icon: 'delete', label: t('InboxDelete'), danger: true, run: async () => {
					if (await confirmDialog({ title: t('InboxRuleDeleteTitle', r.name), text: t('InboxRuleDeleteText'), confirm: t('InboxDelete'), danger: true })) {
						const list = JSON.parse(JSON.stringify(rules));
						list.splice(i, 1);
						save(list, t('InboxRuleDeleted'));
					}
				} },
			]);
		}));
		// Order: drag and drop, or the arrows on the grip
		let dragFrom = -1;
		$$('.ibx-rule[draggable]', box).forEach(li => {
			li.addEventListener('dragstart', e => {
				dragFrom = +li.dataset.i;
				li.classList.add('is-dragging');
				e.dataTransfer.effectAllowed = 'move';
			});
			li.addEventListener('dragend', () => $$('.ibx-rule', box).forEach(x => x.classList.remove('is-dragging', 'is-over')));
			li.addEventListener('dragover', e => {
				e.preventDefault();
				$$('.ibx-rule', box).forEach(x => x.classList.toggle('is-over', x === li));
			});
			li.addEventListener('drop', e => {
				e.preventDefault();
				move(dragFrom, +li.dataset.i);
			});
		});
		$$('.ibx-rule__grip', box).forEach(g => g.addEventListener('keydown', e => {
			if (e.key === 'ArrowUp' || e.key === 'ArrowDown') {
				e.preventDefault();
				const i = +g.closest('.ibx-rule').dataset.i;
				move(i, i + (e.key === 'ArrowUp' ? -1 : 1));
			}
		}));
		const undo = $('.ibx-js-rundo', box);
		if (undo) {
			undo.addEventListener('click', async () => {
				if (await confirmDialog({ title: t('InboxRulesUndoTitle'), text: t('InboxRulesUndoText'), confirm: t('InboxRulesUndoConfirm') })) {
					try {
						rulesCache[account.id] = await post('rules', 'undo', { id: account.id });
						toast(t('InboxRulesUndone'));
					} catch (err) {
						toast(err.message, { error: true });
					}
					tabRules(box, account);
				}
			});
		}
		bindRulesNotice(box, account, st, () => tabRules(box, account));
		// A rule asked from a mail (menu of the mail): its editor opened, filled
		if (I.pendingRule && I.pendingRule.account === account.id && !ro) {
			const pre = I.pendingRule.rule;
			I.pendingRule = null;
			ruleEditor(account, st, null, list => save(list, t('InboxRuleCreated')), false, pre);
		}
	}

	function bindRulesNotice(box, account, st, again) {
		$$('.ibx-js-rscript', box).forEach(b => b.addEventListener('click', async () => {
			let text = '';
			try {
				text = (await api('rules', 'script', { id: account.id, name: b.dataset.name })).text;
			} catch (err) {
				toast(err.message, { error: true });
				return;
			}
			dialog({ title: t('InboxRulesScriptTitle', b.dataset.name), wide: true, body: '<pre class="ibx-rules-script">' + esc(text) + '</pre>', actions: [] });
		}));
		const take = $('.ibx-js-rtakeover', box);
		if (take) {
			take.addEventListener('click', async () => {
				if (!(await confirmDialog({ title: t('InboxRulesTakeOverTitle'), text: t('InboxRulesTakeOverText'), confirm: t('InboxRulesTakeOver'), danger: true }))) {
					return;
				}
				const res = await rulesSave(account, 'save', { rules: JSON.stringify(st.rules || []), take_over: 1 });
				if (res) {
					toast(t('InboxRulesTakenOver'));
					again();
				}
			});
		}
	}

	async function ruleApply(account, r) {
		let p;
		try {
			p = await post('rules', 'preview', { id: account.id, rule: JSON.stringify(r) });
		} catch (err) {
			toast(err.message, { error: true });
			return;
		}
		if (!p.supported || !p.count) {
			toast(t(p.supported ? 'InboxRulePreviewNone' : 'InboxRulePreviewUnsupported'));
			return;
		}
		if (await confirmDialog({ title: t('InboxRuleApplyNow'), text: t('InboxRuleApplyText', r.name, p.count), confirm: t('InboxRuleApplyConfirm', p.count) })) {
			try {
				const res = await post('rules', 'apply', { id: account.id, rule: JSON.stringify(r) });
				toast(res.error ? res.error : t('InboxRuleApplied', res.count));
			} catch (err) {
				toast(err.message, { error: true });
			}
		}
	}

	/** Editor of a rule: conditions, actions, live preview on the mails of the last 30 days */
	function ruleEditor(account, st, index, onSave, ro, prefill) {
		const base = index !== null && index !== undefined ? st.rules[index] : null;
		const r = JSON.parse(JSON.stringify(base || prefill || { name: '', on: true, all: true, conds: [{ f: 'from', op: 'contains', v: '' }], acts: [{ a: 'move', v: '' }], stop: false }));
		r.acts.forEach(a => {
			if ((a.a === 'move' || a.a === 'copy') && !a.v) {
				a.v = (st.folders.find(f => f.role !== 'inbox' && f.role !== 'trash') || st.folders[0] || { path: '' }).path;
			}
		});
		const domains = new Set((st.addresses || []).map(x => x.split('@')[1]));
		const opt = (list, cur, key) => list.map(k => '<option value="' + k + '"' + (k === cur ? ' selected' : '') + '>' + th(key + k) + '</option>').join('');
		let preview = { busy: true };
		const html = () => {
			const n = preview.count || 0;
			return '<div class="ibx-ruled">'
				+ '<div><label class="ibx-formrow__label" for="ruleName" style="display:block;margin-bottom:.25rem">' + th('InboxRuleName') + '</label>'
				+ '<input class="ibx-field" id="ruleName" maxlength="120" autocomplete="off" value="' + esc(r.name) + '" placeholder="' + esc(ruleAutoName(r, st)) + '"' + (ro ? ' readonly' : '') + '></div>'
				+ '<div class="ibx-ruled__block"><div class="ibx-ruled__title">' + th('InboxRuleIf') + ' <select class="ibx-field" id="ruleAll" style="width:auto" aria-label="' + th('InboxRuleCombine') + '"' + (ro ? ' disabled' : '') + '>'
				+ '<option value="1"' + (r.all ? ' selected' : '') + '>' + th('InboxRuleAll') + '</option><option value="0"' + (r.all ? '' : ' selected') + '>' + th('InboxRuleAny') + '</option></select> ' + th('InboxRuleAreMet') + '</div>'
				+ r.conds.map((c, i) => '<div class="ibx-ruled__row' + (c.f === 'header' ? ' ibx-ruled__row--header' : '') + '">'
					+ '<select class="ibx-field" data-c="' + i + '" data-k="f" aria-label="' + th('InboxRuleFieldLabel') + '"' + (ro ? ' disabled' : '') + '>' + opt(RULE_FIELDS, c.f, 'InboxRuleField_') + '</select>'
					+ (c.f === 'header' ? '<input class="ibx-field" data-c="' + i + '" data-k="h" value="' + esc(c.h || '') + '" placeholder="X-Mailer" aria-label="' + th('InboxRuleHeaderName') + '" autocomplete="off"' + (ro ? ' readonly' : '') + '>' : '')
					+ '<select class="ibx-field" data-c="' + i + '" data-k="op" aria-label="' + th('InboxRuleOpLabel') + '"' + (ro ? ' disabled' : '') + '>' + opt(RULE_OPS, c.op, 'InboxRuleOp_') + '</select>'
					+ '<input class="ibx-field" data-c="' + i + '" data-k="v" value="' + esc(c.v) + '" aria-label="' + th('InboxRuleValue') + '" placeholder="' + th(c.f === 'subject' ? 'InboxRuleValueSubject' : 'InboxRuleValueAddress') + '" autocomplete="off"' + (ro ? ' readonly' : '') + '>'
					+ (ro ? '' : '<button type="button" class="ibx-btn ibx-btn--icon ibx-btn--ghost" data-rmc="' + i + '" aria-label="' + th('InboxRuleRemoveCond') + '"' + (r.conds.length < 2 ? ' disabled' : '') + '>' + ic('close') + '</button>') + '</div>').join('')
				+ (ro || r.conds.length >= 10 ? '' : '<div><button type="button" class="ibx-btn ibx-btn--sm ibx-btn--ghost ibx-js-addcond">' + ic('plus') + ' ' + th('InboxRuleAddCond') + '</button></div>') + '</div>'
				+ '<div class="ibx-ruled__block"><div class="ibx-ruled__title">' + th('InboxRuleThen') + '</div>'
				+ r.acts.map((a, i) => '<div class="ibx-ruled__row ibx-ruled__row--act">'
					+ '<select class="ibx-field" data-a="' + i + '" data-k="a" aria-label="' + th('InboxRuleActionLabel') + '"' + (ro ? ' disabled' : '') + '>' + opt(RULE_ACTIONS, a.a, 'InboxRuleAct_') + '</select>'
					+ (a.a === 'move' || a.a === 'copy' ? '<select class="ibx-field" data-combo data-a="' + i + '" data-k="v" aria-label="' + th('InboxRuleFolder') + '"' + (ro ? ' disabled' : '') + '>'
						+ st.folders.filter(f => f.role !== 'trash').map(f => '<option value="' + esc(f.path) + '"' + (f.path === a.v ? ' selected' : '') + '>' + esc(rulePathName(f.path, st.folders)) + '</option>').join('') + '</select>'
						: a.a === 'tag' ? '<select class="ibx-field" data-combo data-a="' + i + '" data-k="v" aria-label="' + th('InboxRuleTag') + '"' + (ro ? ' disabled' : '') + '>'
							+ st.tags.map(x => '<option value="' + esc(x.keyword) + '"' + (x.keyword === a.v ? ' selected' : '') + '>' + esc(x.label) + '</option>').join('') + '</select>'
							: a.a === 'redirect' ? '<input class="ibx-field" type="email" data-a="' + i + '" data-k="v" value="' + esc(a.v || '') + '" placeholder="' + th('InboxRuleRedirectPlaceholder') + '" aria-label="' + th('InboxRuleRedirectTo') + '" autocomplete="off"' + (ro ? ' readonly' : '') + '>'
								: '<span class="ibx-muted">' + (a.a === 'delete' ? th('InboxRuleDeleteHelp') : '') + '</span>')
					+ (ro ? '' : '<button type="button" class="ibx-btn ibx-btn--icon ibx-btn--ghost" data-rma="' + i + '" aria-label="' + th('InboxRuleRemoveAct') + '"' + (r.acts.length < 2 ? ' disabled' : '') + '>' + ic('close') + '</button>')
					+ (a.a === 'redirect' && /@/.test(a.v || '') && !domains.has(String(a.v).split('@')[1].toLowerCase())
						? '<div class="ibx-ruled__warn ibx-notice ibx-notice--error">' + ic('warning') + '<div class="ibx-notice__text"><b>' + th('InboxRuleOutsideTitle') + '</b> ' + th('InboxRuleOutsideText') + '</div></div>' : '')
					+ '</div>').join('')
				+ (ro || r.acts.length >= 10 ? '' : '<div><button type="button" class="ibx-btn ibx-btn--sm ibx-btn--ghost ibx-js-addact">' + ic('plus') + ' ' + th('InboxRuleAddAct') + '</button></div>')
				+ '<label class="ibx-check"><input type="checkbox" id="ruleStop"' + (r.stop ? ' checked' : '') + (ro ? ' disabled' : '') + '> ' + th('InboxRuleStop') + '</label></div>'
				+ '<div class="ibx-ruled__preview" aria-live="polite">'
				+ (preview.busy ? '<span class="ibx-muted">' + th('InboxRulePreviewBusy') + '</span>'
					: !preview.supported ? '<span class="ibx-muted">' + th('InboxRulePreviewUnsupported') + '</span>'
						: '<div><span class="ibx-ruled__count">' + (n ? th('InboxRulePreviewCount', n) : th('InboxRulePreviewNone')) + '</span> <span class="ibx-muted">' + th('InboxRulePreviewWhere') + '</span></div>'
							+ (n ? '<ul>' + preview.items.map(x => '<li><span>' + esc(x.from) + '</span><span>' + esc(x.subject) + '</span><span class="ibx-muted">' + esc(new Date(x.date * 1000).toLocaleDateString(locale(), { day: '2-digit', month: '2-digit' })) + '</span></li>').join('') + '</ul>'
								+ (ro ? '' : '<label class="ibx-check"><input type="checkbox" id="ruleApply"> ' + th('InboxRuleApplyToo', n) + '</label>') : ''))
				+ '</div></div>';
		};
		const valid = () => r.conds.every(c => String(c.v).trim() && (c.f !== 'header' || /^[A-Za-z0-9][A-Za-z0-9-]*$/.test(c.h || '')))
			&& r.acts.every(a => RULE_NO_VALUE.includes(a.a) || (a.a === 'redirect' ? /^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(a.v || '') : !!a.v));
		const dlg = dialog({
			title: ro ? t('InboxRuleReadOnly') : (base ? t('InboxRuleEdit') : t('InboxRuleNew')),
			wide: true,
			body: html(),
			actions: ro ? [] : [{ label: t('InboxSave'), primary: true, run: async () => {
				if (!valid()) {
					toast(t('InboxRuleIncomplete'), { error: true });
					return false;
				}
				r.name = r.name.trim() || ruleAutoName(r, st);
				const list = JSON.parse(JSON.stringify(st.rules || []));
				if (base) {
					list[index] = r;
				} else {
					list.push(r);
				}
				const apply = $('#ruleApply', dlg) && $('#ruleApply', dlg).checked;
				if (!(await onSave(list))) {
					return false;
				}
				if (apply) {
					try {
						const res = await post('rules', 'apply', { id: account.id, rule: JSON.stringify(r) });
						toast(res.error ? res.error : t('InboxRuleApplied', res.count));
					} catch (err) {
						toast(err.message, { error: true });
					}
				}
			} }],
		});
		let timer = 0;
		let seq = 0;
		const askPreview = () => {
			clearTimeout(timer);
			if (!valid()) {
				preview = { supported: true, count: 0, items: [] };
				refresh();
				return;
			}
			timer = setTimeout(async () => {
				const mine = ++seq;
				try {
					const p = await post('rules', 'preview', { id: account.id, rule: JSON.stringify(r) });
					if (mine === seq) {
						preview = p;
						refresh();
					}
				} catch (err) {
					// No preview: the rule can still be saved
				}
			}, 400);
		};
		const refresh = () => {
			const body = $('.ibx-dialog__body', dlg);
			const a = document.activeElement;
			const key = a && body.contains(a) ? (a.id ? '#' + a.id : '[data-' + (a.dataset.c !== undefined ? 'c="' + a.dataset.c : 'a="' + a.dataset.a) + '"][data-k="' + a.dataset.k + '"]') : '';
			const pos = a && typeof a.selectionStart === 'number' && a.type !== 'email' ? a.selectionStart : null;
			body.innerHTML = html();
			bind();
			const back = key ? $(key, body) : null;
			if (back) {
				back.focus();
				if (pos !== null && back.setSelectionRange) {
					back.setSelectionRange(pos, pos);
				}
			}
		};
		const bind = () => {
			const body = $('.ibx-dialog__body', dlg);
			if (ro) {
				return;
			}
			$('#ruleName', body).addEventListener('input', e => {
				r.name = e.target.value;
			});
			$('#ruleAll', body).addEventListener('change', e => {
				r.all = e.target.value === '1';
				refresh();
				askPreview();
			});
			$$('[data-c]', body).forEach(el => el.addEventListener(el.tagName === 'INPUT' ? 'input' : 'change', () => {
				r.conds[+el.dataset.c][el.dataset.k] = el.value;
				if (el.tagName !== 'INPUT') {
					refresh();
				}
				askPreview();
			}));
			$$('[data-a]', body).forEach(el => el.addEventListener(el.tagName === 'INPUT' ? 'input' : 'change', () => {
				const a = r.acts[+el.dataset.a];
				a[el.dataset.k] = el.value;
				if (el.dataset.k === 'a') {
					a.v = a.a === 'move' || a.a === 'copy' ? (st.folders.find(f => f.role !== 'inbox' && f.role !== 'trash') || st.folders[0] || { path: '' }).path
						: a.a === 'tag' ? ((st.tags[0] || {}).keyword || '') : a.a === 'redirect' ? '' : undefined;
					refresh();
				} else if (a.a === 'redirect') {
					// The warning of an address outside follows the typing
					clearTimeout(el._t);
					el._t = setTimeout(refresh, 500);
				}
			}));
			$$('[data-rmc]', body).forEach(el => el.addEventListener('click', () => {
				r.conds.splice(+el.dataset.rmc, 1);
				refresh();
				askPreview();
			}));
			$$('[data-rma]', body).forEach(el => el.addEventListener('click', () => {
				r.acts.splice(+el.dataset.rma, 1);
				refresh();
			}));
			const ac = $('.ibx-js-addcond', body);
			if (ac) {
				ac.addEventListener('click', () => {
					r.conds.push({ f: 'subject', op: 'contains', v: '' });
					refresh();
				});
			}
			const aa = $('.ibx-js-addact', body);
			if (aa) {
				aa.addEventListener('click', () => {
					r.acts.push({ a: 'read' });
					refresh();
				});
			}
			const stop = $('#ruleStop', body);
			stop.addEventListener('change', () => {
				r.stop = stop.checked;
			});
		};
		bind();
		askPreview();
	}

	async function tabAway(box, account) {
		const st = await loadRules(box, account, tabAway);
		if (!st) {
			return;
		}
		const a = Object.assign({}, st.away || {});
		if (!a.from) {
			const d = new Date();
			a.from = d.toISOString().slice(0, 10);
			d.setDate(d.getDate() + 7);
			a.to = d.toISOString().slice(0, 10);
		}
		if (!a.addr || !a.addr.length) {
			a.addr = [account.email.toLowerCase()];
		}
		const ro = st.state === 'edited';
		const fmt = d => new Date(d + 'T12:00:00').toLocaleDateString(locale(), { day: 'numeric', month: 'long' });
		const today = new Date().toISOString().slice(0, 10);
		const status = !st.away.on ? '<span class="ibx-status">' + th('InboxAwayOff') + '</span>'
			: (today < st.away.from ? '<span class="ibx-status ibx-status--warning">' + th('InboxAwayPlanned', fmt(st.away.from), fmt(st.away.to)) + '</span>'
				: (today > st.away.to ? '<span class="ibx-status">' + th('InboxAwayOver', fmt(st.away.to)) + '</span>' : '<span class="ibx-status ibx-status--ok">' + th('InboxAwayActive', fmt(st.away.to)) + '</span>'));
		const dated = (st.extensions || []).includes('date');
		box.innerHTML = '<p class="ibx-section__help">' + th('InboxAwayHelp') + '</p>' + rulesNotice(st)
			+ '<div class="ibx-form">'
			+ '<div class="ibx-formrow"><span class="ibx-formrow__label">' + th('InboxAway') + '</span><div class="ibx-inline" style="gap:var(--ibx-s3);flex-wrap:wrap"><button type="button" class="ibx-switch ibx-js-awayon" role="switch" aria-checked="' + !!st.away.on + '" aria-label="' + th('InboxAway') + '"' + (ro ? ' aria-disabled="true"' : '') + '></button>' + status + '</div></div>'
			+ '<div class="ibx-formrow"><span class="ibx-formrow__label">' + th('InboxAwayPeriod') + '</span><div><div class="ibx-dates"><label>' + th('InboxAwayFrom') + ' <input class="ibx-field" style="width:auto" type="date" id="awayFrom" value="' + esc(a.from) + '"></label>'
			+ '<label>' + th('InboxAwayTo') + ' <input class="ibx-field" style="width:auto" type="date" id="awayTo" value="' + esc(a.to) + '"></label></div>'
			+ '<div class="ibx-formrow__help">' + th(dated ? 'InboxAwayPeriodHelp' : 'InboxAwayPeriodNoDate') + '</div></div></div>'
			+ '<div class="ibx-formrow"><label class="ibx-formrow__label" for="awaySubject">' + th('InboxAwaySubject') + '</label><div><input class="ibx-field" id="awaySubject" maxlength="200" autocomplete="off" value="' + esc(a.subject || t('InboxAwaySubjectDefault')) + '">'
			+ '<div class="ibx-formrow__help">' + th('InboxAwaySubjectHelp') + '</div></div></div>'
			+ '<div class="ibx-formrow"><label class="ibx-formrow__label" for="awayText">' + th('InboxAwayText') + '</label><div><textarea class="ibx-field" id="awayText" rows="7" maxlength="5000">' + esc(a.text || '') + '</textarea>'
			+ '<div class="ibx-formrow__help">' + th('InboxAwayTextHelp') + '</div></div></div>'
			+ '<div class="ibx-formrow"><label class="ibx-formrow__label" for="awayDays">' + th('InboxAwayDays') + '</label><div class="ibx-inline">' + th('InboxAwayDaysBefore') + ' <input class="ibx-field ibx-field--narrow" type="number" min="1" max="30" inputmode="numeric" id="awayDays" value="' + esc(a.days || 1) + '" style="width:4rem;margin:0 .375rem"> ' + th('InboxAwayDaysAfter') + '</div></div>'
			+ '<div class="ibx-formrow"><span class="ibx-formrow__label">' + th('InboxAwayAddresses') + '</span><div>' + (st.addresses || []).map(x => '<label class="ibx-check" style="display:flex"><input type="checkbox" data-addr="' + esc(x) + '"' + (a.addr.includes(x) ? ' checked' : '') + '> ' + esc(x) + '</label>').join('')
			+ '<div class="ibx-formrow__help">' + th('InboxAwayNever') + '</div></div></div>'
			+ '</div>' + (ro ? '' : '<div class="ibx-formfoot"><span class="ibx-formfoot__spacer"></span><button type="button" class="ibx-btn ibx-btn--primary ibx-js-awaysave">' + th('InboxSave') + '</button></div>');
		bindRulesNotice(box, account, st, () => tabAway(box, account));
		if (ro) {
			return;
		}
		const read = on => ({
			on, from: $('#awayFrom', box).value, to: $('#awayTo', box).value, subject: $('#awaySubject', box).value.trim(), text: $('#awayText', box).value,
			days: Math.max(1, Math.min(30, +$('#awayDays', box).value || 1)), addr: $$('[data-addr]', box).filter(x => x.checked).map(x => x.dataset.addr),
		});
		const save = async on => {
			const v = read(on);
			if (on && (!v.from || !v.to || v.to < v.from)) {
				toast(t('InboxAwayBadDates'), { error: true });
				return;
			}
			if (on && !v.text.trim()) {
				toast(t('InboxAwayNoText'), { error: true });
				$('#awayText', box).focus();
				return;
			}
			const res = await rulesSave(account, 'away', { away: JSON.stringify(v) });
			if (res) {
				toast(t(on ? 'InboxAwaySaved' : 'InboxAwayTurnedOff'));
				Dolibarr.executeHook('inbox:away:changed', { account: account.id });
				tabAway(box, account);
			}
		};
		$('.ibx-js-awayon', box).addEventListener('click', () => save(!st.away.on));
		$('.ibx-js-awaysave', box).addEventListener('click', () => save(!!st.away.on));
	}

	// ---------- Mail space: rules over the mail, panel on the right ----------

	/**
	 * Does a rule concern a mail (option 3 of the panel)? From what the reading knows: sender, recipients, subject.
	 * true / false, null when a condition is on a list or a header the reading does not hold.
	 */
	function ruleMatches(r, m) {
		if (!m || !r.on) {
			return false;
		}
		const people = list => (list || []).flatMap(p => [String(p.email || ''), String(p.name || '')]);
		const values = c => {
			switch (c.f) {
				case 'from':
					return people([m.from]);
				case 'to':
					return people(m.to);
				case 'cc':
					return people(m.cc);
				case 'tocc':
					return people((m.to || []).concat(m.cc || []));
				case 'subject':
					return [String(m.subject || '')];
				default:
					return null;
			}
		};
		const address = c => ['from', 'to', 'cc', 'tocc'].includes(c.f);
		const one = c => {
			const vals = values(c);
			if (vals === null) {
				return null;
			}
			const v = String(c.v).toLowerCase();
			// The comparisons of an address work on the address, the others (contains) on the name too
			const list = (address(c) && c.op !== 'contains' && c.op !== 'not' ? vals.filter((x, i) => i % 2 === 0) : vals).map(x => x.toLowerCase());
			switch (c.op) {
				case 'contains':
					return list.some(x => x.includes(v));
				case 'not':
					return !list.some(x => x.includes(v));
				case 'is':
					return list.some(x => x === v);
				case 'starts':
					return list.some(x => x.startsWith(v));
				case 'ends':
					return list.some(x => x.endsWith(v));
			}
			return null;
		};
		const res = r.conds.map(one);
		if (r.all) {
			return res.includes(false) ? false : (res.includes(null) ? null : true);
		}
		return res.includes(true) ? true : (res.includes(null) ? null : false);
	}

	const P = { el: null, account: 0, st: null, loading: false, error: null };

	function managedAccounts() {
		const ms = I.mailState ? I.mailState() : { accounts: [] };
		return (ms.accounts || []).filter(a => a.level === 'manage');
	}

	function closePanel() {
		if (P.el) {
			P.el.remove();
			P.el = null;
			document.removeEventListener('keydown', panelKey, true);
		}
	}
	function panelKey(e) {
		if (e.key === 'Escape' && P.el && !document.querySelector('dialog[open]') && !document.querySelector('.ibx-menu')) {
			e.preventDefault();
			closePanel();
		}
	}

	async function savePanel(list, text) {
		try {
			P.st = await post('rules', 'save', { id: P.account, rules: JSON.stringify(list) });
			rulesCache[P.account] = P.st;
			toast(text);
			renderPanel();
			return true;
		} catch (err) {
			toast(err.message + (err.data && err.data.detail ? ' : ' + err.data.detail : ''), { error: true });
			return false;
		}
	}

	function renderPanel() {
		if (!P.el) {
			return;
		}
		const body = $('.ibx-rp__body', P.el);
		const foot = $('.ibx-rp__foot', P.el);
		const boxes = managedAccounts();
		const a = boxes.find(x => x.id === P.account) || { id: P.account, email: '' };
		$('.ibx-rp__box', P.el).innerHTML = boxes.length > 1
			? '<select class="ibx-field ibx-js-rpbox" data-combo aria-label="' + th('InboxMailbox') + '">' + boxes.map(x => '<option value="' + x.id + '"' + (x.id === P.account ? ' selected' : '') + '>' + esc(x.label || x.email) + '</option>').join('') + '</select>'
			: '<span class="ibx-muted ibx-ellipsis">' + esc(a.label || a.email) + '</span>';
		const sel = $('.ibx-js-rpbox', P.el);
		if (sel) {
			sel.addEventListener('change', () => openPanel(+sel.value));
		}
		if (P.loading) {
			body.innerHTML = '<div aria-busy="true"><span class="ibx-skeleton"></span><span class="ibx-skeleton"></span><span class="ibx-skeleton" style="width:60%"></span></div>';
			foot.innerHTML = '';
			return;
		}
		if (P.error) {
			body.replaceChildren(errorBox(P.error, () => openPanel(P.account)));
			foot.innerHTML = '';
			return;
		}
		const st = P.st;
		const ro = st.state === 'edited';
		const ms = I.mailState ? I.mailState() : {};
		const mail = ms.mail && ms.mail.account === P.account ? ms.mail : null;
		$('.ibx-js-rpnew', P.el).hidden = ro;
		let h = ro ? '<div class="ibx-notice ibx-notice--warning">' + ic('warning') + '<div class="ibx-notice__text">' + th('InboxRulesEditedTitle') + ' '
			+ '<a href="#/settings/accounts/' + P.account + '/rules">' + th('InboxRulesPanelOpenSettings') + '</a></div></div>' : '';
		if (!st.rules.length) {
			h += '<div class="ibx-empty">' + ic('folder-bolt', 'ibx-empty__icon') + '<div class="ibx-empty__title">' + th('InboxRulesEmpty') + '</div><div>' + th('InboxRulesPanelEmpty') + '</div></div>';
		} else {
			h += (mail ? '<p class="ibx-rp__mail">' + ic('mail') + '<span class="ibx-ellipsis">' + th('InboxRulesPanelForMail', mail.subject || t('InboxNoSubject')) + '</span></p>' : '')
				+ '<ol class="ibx-rp__list">' + st.rules.map((r, i) => {
					const match = mail ? ruleMatches(r, mail) : false;
					return '<li class="ibx-rp__rule' + (r.on ? '' : ' is-off') + (match === true ? ' is-match' : '') + '" data-i="' + i + '"' + (ro ? '' : ' draggable="true"') + '>'
						+ '<button type="button" class="ibx-switch" role="switch" aria-checked="' + !!r.on + '" aria-label="' + th('InboxRuleActive', r.name) + '" data-toggle="' + i + '"' + (ro ? ' aria-disabled="true"' : '') + '></button>'
						+ '<button type="button" class="ibx-rp__main" data-edit="' + i + '"><span class="ibx-rp__name">' + (i + 1) + '. ' + esc(r.name)
						+ (match === true ? ' <span class="ibx-badge ibx-badge--accent">' + th('InboxRuleMatchesMail') + '</span>' : '') + '</span>'
						+ '<span class="ibx-rp__sum">' + ruleSummary(r, st) + '</span></button>'
						+ (ro ? '' : '<button type="button" class="ibx-btn ibx-btn--icon ibx-btn--sm ibx-btn--ghost" data-more="' + i + '" aria-label="' + th('InboxMoreActions') + '">' + ic('more') + '</button>') + '</li>';
				}).join('') + '</ol>'
				+ (mail && !st.rules.some(r => ruleMatches(r, mail) === true) ? '<p class="ibx-muted ibx-rp__none">' + th('InboxRulesPanelNoMatch') + '</p>' : '');
		}
		body.innerHTML = h;
		const fmt = d => new Date(d + 'T12:00:00').toLocaleDateString(locale(), { day: 'numeric', month: 'long' });
		const today = new Date().toISOString().slice(0, 10);
		const away = st.away && st.away.on && st.away.to >= today ? (st.away.from > today ? t('InboxAwayPlanned', fmt(st.away.from), fmt(st.away.to)) : t('InboxAwayActive', fmt(st.away.to))) : t('InboxAwayOff');
		foot.innerHTML = '<span>' + ic('agenda') + ' ' + th('InboxAway') + ' : ' + esc(away) + '</span>'
			+ '<a href="#/settings/accounts/' + P.account + '/away">' + th('InboxRulesPanelManage') + '</a>'
			+ '<a href="#/settings/accounts/' + P.account + '/rules">' + th('InboxRulesPanelOpenSettings') + '</a>';
		if (ro) {
			$$('[data-edit]', body).forEach(b => b.addEventListener('click', () => ruleEditor({ id: P.account }, st, +b.dataset.edit, () => false, true)));
			return;
		}
		const account = { id: P.account, email: a.email };
		$$('[data-edit]', body).forEach(b => b.addEventListener('click', () => ruleEditor(account, st, +b.dataset.edit, list => savePanel(list, t('InboxRuleSaved')))));
		$$('[data-toggle]', body).forEach(b => b.addEventListener('click', () => {
			const list = JSON.parse(JSON.stringify(st.rules));
			list[+b.dataset.toggle].on = !list[+b.dataset.toggle].on;
			b.setAttribute('aria-checked', list[+b.dataset.toggle].on ? 'true' : 'false');
			savePanel(list, t(list[+b.dataset.toggle].on ? 'InboxRuleTurnedOn' : 'InboxRuleTurnedOff'));
		}));
		const move = (from, to) => {
			if (to < 0 || to >= st.rules.length || from === to) {
				return;
			}
			const list = JSON.parse(JSON.stringify(st.rules));
			const [r] = list.splice(from, 1);
			list.splice(to, 0, r);
			savePanel(list, t('InboxRulesOrderSaved'));
		};
		$$('[data-more]', body).forEach(b => b.addEventListener('click', () => {
			const i = +b.dataset.more;
			const r = st.rules[i];
			I.menu(b, [
				{ icon: 'copy', label: t('InboxRuleDuplicate'), run: () => {
					const list = JSON.parse(JSON.stringify(st.rules));
					list.splice(i + 1, 0, Object.assign(JSON.parse(JSON.stringify(r)), { name: r.name + ' ' + t('InboxRuleCopySuffix'), on: false }));
					savePanel(list, t('InboxRuleDuplicated'));
				} },
				{ icon: 'chevron-up', label: t('InboxRuleUp'), disabled: i === 0, run: () => move(i, i - 1) },
				{ icon: 'chevron-down', label: t('InboxRuleDown'), disabled: i === st.rules.length - 1, run: () => move(i, i + 1) },
				{ icon: 'done', label: t('InboxRuleApplyNow'), run: () => ruleApply(account, r) },
				{ icon: 'delete', label: t('InboxDelete'), run: async () => {
					if (await confirmDialog({ title: t('InboxRuleDeleteTitle', r.name), text: t('InboxRuleDeleteText'), confirm: t('InboxDelete'), danger: true })) {
						const list = JSON.parse(JSON.stringify(st.rules));
						list.splice(i, 1);
						savePanel(list, t('InboxRuleDeleted'));
					}
				} },
			]);
		}));
		let dragFrom = -1;
		$$('.ibx-rp__rule[draggable]', body).forEach(li => {
			li.addEventListener('dragstart', e => {
				dragFrom = +li.dataset.i;
				li.classList.add('is-dragging');
				e.dataTransfer.effectAllowed = 'move';
			});
			li.addEventListener('dragend', () => $$('.ibx-rp__rule', body).forEach(x => x.classList.remove('is-dragging', 'is-over')));
			li.addEventListener('dragover', e => {
				e.preventDefault();
				$$('.ibx-rp__rule', body).forEach(x => x.classList.toggle('is-over', x === li));
			});
			li.addEventListener('drop', e => {
				e.preventDefault();
				move(dragFrom, +li.dataset.i);
			});
		});
	}

	/** Panel of the rules of a mailbox, on the right of the Mail space: the list stays usable beside it */
	async function openPanel(accountId) {
		if (!P.el) {
			const el = document.createElement('aside');
			el.className = 'ibx-rp';
			el.setAttribute('role', 'complementary');
			el.setAttribute('aria-label', t('InboxTabRules'));
			el.innerHTML = '<div class="ibx-rp__head"><h2 class="ibx-rp__title">' + ic('folder-bolt') + ' ' + th('InboxTabRules') + '</h2>'
				+ '<button type="button" class="ibx-btn ibx-btn--sm ibx-btn--primary ibx-js-rpnew">' + ic('plus') + ' ' + th('InboxRuleNew') + '</button>'
				+ '<button type="button" class="ibx-btn ibx-btn--icon ibx-btn--sm ibx-btn--ghost ibx-js-rpclose" aria-label="' + th('InboxCloseWindow') + '">' + ic('close') + '</button></div>'
				+ '<div class="ibx-rp__box"></div><div class="ibx-rp__body"></div><div class="ibx-rp__foot"></div>';
			(document.querySelector('.ibx-app') || document.body).appendChild(el);
			P.el = el;
			$('.ibx-js-rpclose', el).addEventListener('click', closePanel);
			$('.ibx-js-rpnew', el).addEventListener('click', () => {
				const a = managedAccounts().find(x => x.id === P.account) || { email: '' };
				ruleEditor({ id: P.account, email: a.email }, P.st, null, list => savePanel(list, t('InboxRuleCreated')));
			});
			// Links to the settings: the panel goes, the Mail space too
			el.addEventListener('click', e => {
				if (e.target.closest('a[href^="#/settings"]')) {
					closePanel();
				}
			});
			document.addEventListener('keydown', panelKey, true);
			$('.ibx-js-rpclose', el).focus();
		}
		P.account = accountId;
		P.loading = true;
		P.error = null;
		renderPanel();
		try {
			P.st = await api('rules', 'get', { id: accountId });
			rulesCache[accountId] = P.st;
		} catch (err) {
			P.error = err;
		}
		if (P.account === accountId) {
			P.loading = false;
			renderPanel();
		}
	}

	/** « Create a rule from this mail »: the editor over the mail, filled; the mail stays read */
	async function fromMail(m) {
		let st;
		try {
			st = await api('rules', 'get', { id: m.account });
		} catch (err) {
			toast(err.message, { error: true });
			return;
		}
		if (st.state === 'edited') {
			toast(t('InboxRulesEditedTitle'), { error: true, action: t('InboxRulesPanelOpenSettings'), onAction: () => I.go('#/settings/accounts/' + m.account + '/rules') });
			return;
		}
		const prefill = { name: '', on: true, all: true, stop: false,
			conds: [{ f: 'from', op: 'is', v: m.from.email }].concat(m.subject ? [{ f: 'subject', op: 'contains', v: m.subject.replace(/^((re|tr|fw|fwd)\s*:\s*)+/i, '').slice(0, 120) }] : []),
			acts: [{ a: 'move', v: '' }] };
		ruleEditor({ id: m.account, email: m.mailbox || '' }, st, null, async list => {
			try {
				const res = await post('rules', 'save', { id: m.account, rules: JSON.stringify(list) });
				rulesCache[m.account] = res;
				if (P.el && P.account === m.account) {
					P.st = res;
					renderPanel();
				}
				toast(t('InboxRuleCreated'), { action: t('InboxRulesPanelSee'), onAction: () => openPanel(m.account) });
				return true;
			} catch (err) {
				toast(err.message + (err.data && err.data.detail ? ' : ' + err.data.detail : ''), { error: true });
				return false;
			}
		}, false, prefill);
	}

	// Another mail read: the rules that concern it follow; leaving the Mail space closes the panel
	Dolibarr.on('inbox:mail:open', () => {
		if (P.el && P.st) {
			renderPanel();
		}
	});
	window.addEventListener('hashchange', () => {
		if (P.el && !/^#\/mail/.test(location.hash) && location.hash !== '' && location.hash !== '#') {
			closePanel();
		}
	});

	I.rules = { tab: tabRules, awayTab: tabAway, editor: ruleEditor, summary: ruleSummary, matches: ruleMatches, openPanel, closePanel, fromMail, cache: rulesCache };
})();
