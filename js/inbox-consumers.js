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
 * a manager allows it; everything is written in the journal of the mailbox (tab Access). The mails are given to the
 * modules since 1.146.0: last activity, mails waiting and given up shown on each line.
 */
(function () {
	'use strict';

	const I = Dolibarr.tools.inbox;
	const { html, raw, t, ic, api, post, toast, dialog, confirmDialog, $, $$ } = I;
	const skeleton = lines => I.settingsUi.skeleton(lines);
	const errorBox = (err, retry) => I.settingsUi.errorBox(err, retry);
	/** Icon of the application, as HTML for html`` */
	const icon = (name, cls) => raw(ic(name, cls));
	/** A boolean attribute (checked, hidden...) when cond is true */
	const attr = (cond, name) => (cond ? raw(' ' + name) : '');

	/** Translation key of an event (mail.received → InboxConsumerEvent_mail_received) */
	const eventKey = e => 'InboxConsumerEvent_' + String(e).replace(/\./g, '_');
	/** Folders shown before the search field is offered */
	const FOLDER_SEARCH_FROM = 8;

	/** State of a module on this mailbox, as a badge */
	function stateBadge(c) {
		const g = c.grant;
		if (!g) {
			return html`<span class="ibx-badge">${t('InboxConsumerStateNone')}</span>`;
		}
		if (!c.declared) {
			return html`<span class="ibx-badge ibx-badge--warning">${t('InboxConsumerStateGone')}</span>`;
		}
		if (g.paused) {
			return html`<span class="ibx-badge ibx-badge--warning">${t(g.pause_reason === 'protected' ? 'InboxConsumerStatePausedProtected' : 'InboxConsumerStatePaused')}</span>`;
		}
		return g.mode === 'active' ? html`<span class="ibx-badge ibx-badge--accent">${t('InboxConsumerStateActive')}</span>`
			: html`<span class="ibx-badge">${t('InboxConsumerStateTest')}</span>`;
	}

	/** What is allowed, in one line */
	function grantSummary(c) {
		const g = c.grant;
		if (!g) {
			return '';
		}
		const what = g.events.map(e => t(eventKey(e))).concat(g.actions.map(a => t('InboxConsumerAction_' + a)));
		const where = g.folders.length ? t('InboxConsumerFoldersSome', g.folders.length) : t('InboxConsumerFoldersAll');
		return html`<div class="ibx-muted">${what.join(', ')} · ${where}</div>
			<div class="ibx-muted">${t('InboxConsumerGrantedBy', g.user || '-', new Date(g.date * 1000).toLocaleDateString(I.locale()))}</div>`;
	}

	/** What the module did lately (1.146.0): last mail handled, mails waiting, mails given up after the last try */
	function activity(g) {
		if (!g) {
			return '';
		}
		const q = g.queue || { waiting: 0, failed: 0 };
		return html`${g.last_ok && html`<div class="ibx-muted">${t('InboxConsumerLastOk', new Date(g.last_ok * 1000).toLocaleString(I.locale()))}</div>`}
			${q.waiting > 0 && html`<div class="ibx-muted">${t('InboxConsumerWaiting', q.waiting)}</div>`}
			${q.failed > 0 && html`<div><span class="ibx-badge ibx-badge--warning">${t('InboxConsumerFailed', q.failed)}</span></div>`}`;
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

	/** Why the actions are greyed (P23: the reason visible), on each button concerned */
	const whyAttrs = blocked => (blocked ? html` aria-disabled="true" title="${t(blocked)}"` : '');

	/** Buttons of a line: allow or change, pause or resume, remove */
	function rowButtons(c, i, blocked) {
		const g = c.grant;
		return html`${c.declared && html`<button type="button" class="ibx-btn ibx-btn--sm${attr(!g, 'ibx-btn--primary')}" data-edit="${i}"${whyAttrs(blocked)}>${icon(g ? 'edit' : 'plug')} ${t(g ? 'InboxConsumerChange' : 'InboxConsumerAllow')}</button>`}
			${g && c.declared && html`<button type="button" class="ibx-btn ibx-btn--sm" data-pause="${i}"${g.paused ? whyAttrs(blocked) : ''}>${t(g.paused ? 'InboxConsumerResume' : 'InboxConsumerPause')}</button>`}
			${g && html`<button type="button" class="ibx-btn ibx-btn--sm ibx-btn--ghost ibx-btn--icon" data-revoke="${i}" title="${t('InboxConsumerRevoke')}" aria-label="${t('InboxConsumerRevoke')}">${icon('close')}</button>`}`;
	}

	/** Notice above the list: switch of the instance off, mailbox that cannot have modules, protected with its password */
	function notice(data) {
		const blocked = data.blocked;
		if (blocked === 'InboxConsumersOff') {
			return html`<div class="ibx-notice ibx-notice--info">${icon('info')}<div class="ibx-notice__text">${t('InboxConsumersOff')}
				${data.setup_url ? html`<a href="${data.setup_url}">${t('InboxConsumersOpenSetup')}</a>` : t('InboxConsumersAskAdmin')}</div></div>`;
		}
		if (blocked) {
			return html`<div class="ibx-notice ibx-notice--warning">${icon('lock')}<div class="ibx-notice__text">${t(blocked)}</div></div>`;
		}
		if (data.locked_sync) {
			return html`<div class="ibx-notice ibx-notice--info">${icon('lock')}<div class="ibx-notice__text">${t('InboxConsumersLockedSync')}</div></div>`;
		}
		return '';
	}

	function paint(box, account, data) {
		const blocked = data.blocked;
		const intro = html`<p class="ibx-section__help">${t('InboxConsumersHelp')}</p>${notice(data)}`;
		if (!data.consumers.length) {
			box.innerHTML = html`${intro}<div class="ibx-empty" style="height:auto">${icon('plug', 'ibx-empty__icon')}<div class="ibx-empty__title">${t('InboxConsumersNone')}</div><div>${t('InboxConsumersNoneHelp')}</div></div>`;
			return;
		}
		box.innerHTML = html`${intro}<table class="ibx-table"><thead><tr><th>${t('InboxConsumerModule')}</th><th>${t('InboxConsumerState')}</th><th><span class="ibx-sr-only">${t('InboxConsumerRowActions')}</span></th></tr></thead><tbody>
			${data.consumers.map((c, i) => {
				const g = c.grant;
				return html`<tr>
					<td><b>${c.label}</b>${c.purpose && html`<div class="ibx-muted">${c.purpose}</div>`}${!c.declared && html`<div class="ibx-muted">${t('InboxConsumerGoneHelp')}</div>`}${grantSummary(c)}</td>
					<td>${stateBadge(c)}${g && hasPending(c) && html` <span class="ibx-badge ibx-badge--warning">${t('InboxConsumerPending')}</span>`}${activity(g)}${g && g.last_error && html`<div class="ibx-formrow__error">${g.last_error}</div>`}</td>
					<td class="ibx-table__actions">${rowButtons(c, i, blocked)}</td>
				</tr>`;
			})}</tbody></table>`;

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
		return html`<label class="ibx-check ibx-check--block"><input type="checkbox" name="${name}" value="${value}"${attr(checked, 'checked')}> <span><b>${label}${isNew && html` <span class="ibx-badge ibx-badge--warning">${t('InboxConsumerNew')}</span>`}</b>${help && html`<span class="ibx-formrow__help">${help}</span>`}</span></label>`;
	}

	/** Allow a module, or change what it is allowed: only what it asks, test mode proposed the first time */
	function edit(account, data, c, onSaved) {
		const g = c.grant;
		const isNewEvent = e => !!g && c.pending.events.includes(e);
		const isNewAction = a => !!g && c.pending.actions.includes(a);
		const limited = !!g && g.folders.length > 0;
		const mode = g ? g.mode : 'test';
		// Folders named as everywhere in inbox: the inbox by its role, the others without « INBOX / »
		const folders = data.folders.map(f => Object.assign({}, f, { name: f.role === 'inbox' ? t('InboxFolderRoleInbox') : f.name.replace(/^INBOX \/ /i, '') }));
		const body = html`${c.purpose && html`<p>${c.purpose}</p>`}
			<form class="ibx-js-consumer">
			<fieldset class="ibx-fieldset"><legend>${t('InboxConsumerEvents')}</legend>
				${c.events.map(e => check('events', e, t(eventKey(e)), t(eventKey(e) + '_help'), g ? g.events.includes(e) : true, isNewEvent(e)))}
			</fieldset>
			${c.actions.length > 0 && html`<fieldset class="ibx-fieldset"><legend>${t('InboxConsumerActions')}</legend><p class="ibx-formrow__help">${t('InboxConsumerActionsHelp')}</p>
				${c.actions.map(a => check('actions', a, t('InboxConsumerAction_' + a), t('InboxConsumerActionHelp_' + a), g ? g.actions.includes(a) : false, isNewAction(a)))}
			</fieldset>`}
			<fieldset class="ibx-fieldset"><legend>${t('InboxConsumerFolders')}</legend>
				<label class="ibx-radio"><input type="radio" name="scope" value="all"${attr(!limited, 'checked')}> ${t('InboxConsumerFoldersAll')}</label><br>
				<label class="ibx-radio"><input type="radio" name="scope" value="some"${attr(limited, 'checked')}${attr(!folders.length, 'disabled')}> ${t('InboxConsumerFoldersOnly')}</label>
				<div class="ibx-js-folders"${attr(!limited, 'hidden')}>
					${folders.length > FOLDER_SEARCH_FROM && html`<input type="search" class="ibx-field ibx-js-fsearch" autocomplete="off" placeholder="${t('InboxConsumerFoldersSearch')}" aria-label="${t('InboxConsumerFoldersSearch')}">`}
					<div class="ibx-picklist" role="group" aria-label="${t('InboxConsumerFolders')}">
						${folders.map(f => html`<label class="ibx-picklist__item ibx-check" data-name="${f.name.toLowerCase()}"><input type="checkbox" name="folders" value="${f.id}"${attr(!!g && g.folders.includes(f.id), 'checked')}> ${f.name}</label>`)}
					</div>
				</div>
			</fieldset>
			<fieldset class="ibx-fieldset"><legend>${t('InboxConsumerMode')}</legend>
				${['test', 'active'].map(m => html`<label class="ibx-check ibx-check--block"><input type="radio" name="mode" value="${m}"${attr(mode === m, 'checked')}> <span><b>${t('InboxConsumerMode_' + m)}</b><span class="ibx-formrow__help">${t('InboxConsumerModeHelp_' + m)}</span></span></label>`)}
			</fieldset></form>`;
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
