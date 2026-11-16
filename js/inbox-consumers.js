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
 * Modules subscribed to a mailbox (1.112.0), section Modules of the card (tab Reception): the modules installed that
 * want to react to the mails of this mailbox, what the managers allow each one, pause and removal. Nothing runs until
 * a manager allows it; everything is written in the journal of the mailbox (tab Access).
 */
(function () {
	'use strict';

	const I = Dolibarr.tools.inbox;
	const { esc, t, th, ic, api, post, toast, dialog, confirmDialog, $, $$ } = I;
	const skeleton = lines => I.settingsUi.skeleton(lines);
	const errorBox = (err, retry) => I.settingsUi.errorBox(err, retry);

	/** Translation key of an event (mail.received → InboxConsumerEvent_mail_received) */
	const eventKey = e => 'InboxConsumerEvent_' + String(e).replace(/\./g, '_');
	/** Folders shown before the search field is offered */
	const FOLDER_SEARCH_FROM = 8;

	/** State of a module on this mailbox, as a badge */
	function stateBadge(c) {
		const g = c.grant;
		if (!g) {
			return '<span class="ibx-badge">' + th('InboxConsumerStateNone') + '</span>';
		}
		if (!c.declared) {
			return '<span class="ibx-badge ibx-badge--warning">' + th('InboxConsumerStateGone') + '</span>';
		}
		if (g.paused) {
			return '<span class="ibx-badge ibx-badge--warning">' + th(g.pause_reason === 'protected' ? 'InboxConsumerStatePausedProtected' : 'InboxConsumerStatePaused') + '</span>';
		}
		return g.mode === 'active' ? '<span class="ibx-badge ibx-badge--accent">' + th('InboxConsumerStateActive') + '</span>'
			: '<span class="ibx-badge">' + th('InboxConsumerStateTest') + '</span>';
	}

	/** What is allowed, in one line */
	function grantSummary(c) {
		const g = c.grant;
		if (!g) {
			return '';
		}
		const what = g.events.map(e => t(eventKey(e))).concat(g.actions.map(a => t('InboxConsumerAction_' + a)));
		const where = g.folders.length ? t('InboxConsumerFoldersSome', g.folders.length) : t('InboxConsumerFoldersAll');
		return '<div class="ibx-muted">' + esc(what.join(', ')) + ' · ' + esc(where) + '</div>'
			+ '<div class="ibx-muted">' + esc(t('InboxConsumerGrantedBy', g.user || '-', new Date(g.date * 1000).toLocaleDateString(I.locale()))) + '</div>';
	}

	/** Something the module asks since the decision of the managers */
	const hasPending = c => c.pending.events.length + c.pending.actions.length > 0;

	async function tab(box, account) {
		box.innerHTML = skeleton(4);
		let data;
		try {
			data = await api('consumer', 'list', { id: account.id });
		} catch (err) {
			box.replaceChildren(errorBox(err, () => tab(box, account)));
			return;
		}
		paint(box, account, data);
	}

	function paint(box, account, data) {
		const blocked = data.blocked;
		let html = '<p class="ibx-section__help">' + th('InboxConsumersHelp') + '</p>';
		if (blocked === 'InboxConsumersOff') {
			html += '<div class="ibx-notice ibx-notice--info">' + ic('info') + '<div class="ibx-notice__text">' + th('InboxConsumersOff')
				+ (data.setup_url ? ' <a href="' + esc(data.setup_url) + '">' + th('InboxConsumersOpenSetup') + '</a>' : ' ' + th('InboxConsumersAskAdmin')) + '</div></div>';
		} else if (blocked) {
			html += '<div class="ibx-notice ibx-notice--warning">' + ic('lock') + '<div class="ibx-notice__text">' + th(blocked) + '</div></div>';
		} else if (data.locked_sync) {
			html += '<div class="ibx-notice ibx-notice--info">' + ic('lock') + '<div class="ibx-notice__text">' + th('InboxConsumersLockedSync') + '</div></div>';
		}
		if (!data.consumers.length) {
			html += '<div class="ibx-empty" style="height:auto">' + ic('plug', 'ibx-empty__icon') + '<div class="ibx-empty__title">' + th('InboxConsumersNone') + '</div><div>' + th('InboxConsumersNoneHelp') + '</div></div>';
			box.innerHTML = html;
			return;
		}
		const why = blocked ? ' aria-disabled="true" title="' + th(blocked) + '"' : '';
		html += '<table class="ibx-table"><thead><tr><th>' + th('InboxConsumerModule') + '</th><th>' + th('InboxConsumerState') + '</th><th><span class="ibx-sr-only">' + th('InboxConsumerRowActions') + '</span></th></tr></thead><tbody>'
			+ data.consumers.map((c, i) => {
				const g = c.grant;
				let btns = '';
				if (c.declared) {
					btns += '<button type="button" class="ibx-btn ibx-btn--sm' + (g ? '' : ' ibx-btn--primary') + '" data-edit="' + i + '"' + why + '>' + ic(g ? 'edit' : 'plug') + ' ' + th(g ? 'InboxConsumerChange' : 'InboxConsumerAllow') + '</button>';
				}
				if (g && c.declared) {
					btns += ' <button type="button" class="ibx-btn ibx-btn--sm" data-pause="' + i + '"' + (g.paused && blocked ? why : '') + '>' + th(g.paused ? 'InboxConsumerResume' : 'InboxConsumerPause') + '</button>';
				}
				if (g) {
					btns += ' <button type="button" class="ibx-btn ibx-btn--sm ibx-btn--ghost ibx-btn--icon" data-revoke="' + i + '" title="' + th('InboxConsumerRevoke') + '" aria-label="' + th('InboxConsumerRevoke') + '">' + ic('close') + '</button>';
				}
				return '<tr><td><b>' + esc(c.label) + '</b>' + (c.purpose ? '<div class="ibx-muted">' + esc(c.purpose) + '</div>' : '')
					+ (!c.declared ? '<div class="ibx-muted">' + th('InboxConsumerGoneHelp') + '</div>' : '') + grantSummary(c) + '</td>'
					+ '<td>' + stateBadge(c) + (g && hasPending(c) ? ' <span class="ibx-badge ibx-badge--warning">' + th('InboxConsumerPending') + '</span>' : '')
					+ (g && g.last_error ? '<div class="ibx-formrow__error">' + esc(g.last_error) + '</div>' : '') + '</td>'
					+ '<td class="ibx-table__actions">' + btns + '</td></tr>';
			}).join('') + '</tbody></table>';
		box.innerHTML = html;

		const done = (res, msg) => {
			toast(msg);
			paint(box, account, res);
		};
		$$('[data-edit]', box).forEach(b => b.addEventListener('click', () => {
			if (b.getAttribute('aria-disabled') !== 'true') {
				edit(account, data, data.consumers[+b.dataset.edit], res => done(res, t('InboxSaved')));
			}
		}));
		$$('[data-pause]', box).forEach(b => b.addEventListener('click', async () => {
			if (b.getAttribute('aria-disabled') === 'true') {
				return;
			}
			const c = data.consumers[+b.dataset.pause];
			try {
				const paused = c.grant.paused;
				done(await post('consumer', paused ? 'resume' : 'pause', { id: account.id, code: c.code }), t(paused ? 'InboxConsumerResumed' : 'InboxConsumerPaused', c.label));
			} catch (err) {
				toast(err.message, { error: true });
			}
		}));
		$$('[data-revoke]', box).forEach(b => b.addEventListener('click', async () => {
			const c = data.consumers[+b.dataset.revoke];
			// Confirmed, naming the module and the mailbox (principle P22): what was ticked is lost
			const ok = await confirmDialog({ title: t('InboxConsumerRevokeTitle'), text: t('InboxConsumerRevokeText', c.label, account.email), confirm: t('InboxConsumerRevoke'), danger: true });
			if (!ok) {
				return;
			}
			try {
				done(await post('consumer', 'revoke', { id: account.id, code: c.code }), t('InboxConsumerRevoked', c.label));
			} catch (err) {
				toast(err.message, { error: true });
			}
		}));
	}

	/** One checkbox of the dialog; « new » when the module asks it since the decision of the managers */
	function check(name, value, label, help, checked, isNew) {
		return '<label class="ibx-check ibx-check--block"><input type="checkbox" name="' + name + '" value="' + esc(value) + '"' + (checked ? ' checked' : '') + '> <span><b>' + esc(label)
			+ (isNew ? ' <span class="ibx-badge ibx-badge--warning">' + th('InboxConsumerNew') + '</span>' : '') + '</b>'
			+ (help ? '<span class="ibx-formrow__help">' + esc(help) + '</span>' : '') + '</span></label>';
	}

	/** Allow a module, or change what it is allowed: only what it asks, test mode proposed the first time */
	function edit(account, data, c, onSaved) {
		const g = c.grant;
		const isNewEvent = e => !!g && c.pending.events.includes(e);
		const isNewAction = a => !!g && c.pending.actions.includes(a);
		const limited = !!g && g.folders.length > 0;
		// Folders named as everywhere in inbox: the inbox by its role, the others without « INBOX / »
		const folders = data.folders.map(f => Object.assign({}, f, { name: f.role === 'inbox' ? t('InboxFolderRoleInbox') : f.name.replace(/^INBOX \/ /i, '') }));
		const body = (c.purpose ? '<p>' + esc(c.purpose) + '</p>' : '')
			+ '<form class="ibx-js-consumer">'
			+ '<fieldset class="ibx-fieldset"><legend>' + th('InboxConsumerEvents') + '</legend>'
			+ c.events.map(e => check('events', e, t(eventKey(e)), t(eventKey(e) + '_help'), g ? g.events.includes(e) : true, isNewEvent(e))).join('') + '</fieldset>'
			+ (c.actions.length ? '<fieldset class="ibx-fieldset"><legend>' + th('InboxConsumerActions') + '</legend><p class="ibx-formrow__help">' + th('InboxConsumerActionsHelp') + '</p>'
				+ c.actions.map(a => check('actions', a, t('InboxConsumerAction_' + a), t('InboxConsumerActionHelp_' + a), g ? g.actions.includes(a) : false, isNewAction(a))).join('') + '</fieldset>' : '')
			+ '<fieldset class="ibx-fieldset"><legend>' + th('InboxConsumerFolders') + '</legend>'
			+ '<label class="ibx-radio"><input type="radio" name="scope" value="all"' + (limited ? '' : ' checked') + '> ' + th('InboxConsumerFoldersAll') + '</label><br>'
			+ '<label class="ibx-radio"><input type="radio" name="scope" value="some"' + (limited ? ' checked' : '') + (folders.length ? '' : ' disabled') + '> ' + th('InboxConsumerFoldersOnly') + '</label>'
			+ '<div class="ibx-js-folders"' + (limited ? '' : ' hidden') + '>'
			+ (folders.length > FOLDER_SEARCH_FROM ? '<input type="search" class="ibx-field ibx-js-fsearch" autocomplete="off" placeholder="' + th('InboxConsumerFoldersSearch') + '" aria-label="' + th('InboxConsumerFoldersSearch') + '">' : '')
			+ '<div class="ibx-picklist" role="group" aria-label="' + th('InboxConsumerFolders') + '">'
			+ folders.map(f => '<label class="ibx-picklist__item ibx-check" data-name="' + esc(f.name.toLowerCase()) + '"><input type="checkbox" name="folders" value="' + f.id + '"' + (g && g.folders.includes(f.id) ? ' checked' : '') + '> ' + esc(f.name) + '</label>').join('')
			+ '</div></div></fieldset>'
			+ '<fieldset class="ibx-fieldset"><legend>' + th('InboxConsumerMode') + '</legend>'
			+ ['test', 'active'].map(m => '<label class="ibx-check ibx-check--block"><input type="radio" name="mode" value="' + m + '"' + ((g ? g.mode : 'test') === m ? ' checked' : '') + '> <span><b>' + th('InboxConsumerMode_' + m) + '</b>'
				+ '<span class="ibx-formrow__help">' + th('InboxConsumerModeHelp_' + m) + '</span></span></label>').join('')
			+ '</fieldset></form>';
		const dlg = dialog({
			title: t(g ? 'InboxConsumerChangeTitle' : 'InboxConsumerAllowTitle', c.label),
			body,
			wide: true,
			actions: [{ label: t(g ? 'InboxSave' : 'InboxConsumerAllowButton'), primary: true, run: async d => {
				const f = $('.ibx-js-consumer', d);
				const values = name => $$('input[name="' + name + '"]:checked', f).map(x => x.value);
				const some = $('input[name="scope"]:checked', f).value === 'some';
				const chosen = some ? values('folders').map(Number) : [];
				if (!values('events').length) {
					toast(t('InboxConsumerNoEvent'), { error: true });
					return false;
				}
				if (some && !chosen.length) {
					toast(t('InboxConsumerNoFolder'), { error: true });
					return false;
				}
				const res = await post('consumer', 'save', { id: account.id, code: c.code, events: values('events'), actions: values('actions'), folders: chosen, mode: $('input[name="mode"]:checked', f).value });
				onSaved(res);
			} }],
		});
		// The first time, the button says the mode chosen (« Allow in test mode » or « Allow »)
		if (!g) {
			const btn = $('.ibx-dialog__foot [data-i="0"]', dlg);
			$$('input[name="mode"]', dlg).forEach(r => r.addEventListener('change', () => {
				btn.textContent = t(r.value === 'active' ? 'InboxConsumerAllowActive' : 'InboxConsumerAllowButton');
			}));
		}
		const list = $('.ibx-js-folders', dlg);
		$$('input[name="scope"]', dlg).forEach(r => r.addEventListener('change', () => {
			list.hidden = $('input[name="scope"]:checked', dlg).value !== 'some';
		}));
		const search = $('.ibx-js-fsearch', dlg);
		if (search) {
			search.addEventListener('input', () => {
				const q = search.value.trim().toLowerCase();
				$$('.ibx-picklist__item', list).forEach(it => {
					it.hidden = q !== '' && !it.dataset.name.includes(q);
				});
			});
		}
	}

	I.consumers = { tab };
})();
