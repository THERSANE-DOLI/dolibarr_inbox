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
 * Settings space of the inbox application (lot W1, analyse-lot-w1 §5):
 *   #/settings/accounts                list of the mailboxes
 *   #/settings/accounts/new            new mailbox (tab Server)
 *   #/settings/accounts/<id>/<section> card of a mailbox (1.89.0): 6 tabs (overview, connection, access, writing,
 *                                      reception, journal), their sections as chips (server, folders, sync...)
 *   #/settings/import                  import from the sources declared by other modules
 *   #/settings/general                 defaults of the webmail (admins of the mailboxes)
 *   #/settings/security                security: quote, pictures, unsubscribe, bank alert (1.78.0)
 *   #/settings/antispam                anti-spam: blocked senders, junk, trusted receiving servers (1.85.0)
 *   #/settings/domains                 shared domains: public, disposable, shared (managers of the settings)
 *   #/settings/sources                 data sources: mails sent by a site for a requester (admins of Dolibarr)
 *   #/settings/me                      settings of the current user
 * Every change is saved in ajax; a refused action is greyed with its reason, never hidden without a word.
 */
(function () {
	'use strict';

	const I = Dolibarr.tools.inbox;
	const { esc, t, th, ic, api, post, toast, dialog, confirmDialog, $, $$ } = I;
	const rights = I.rights;
	const isAdmin = () => !!rights().account_admin;
	/** Settings > Server (1.54.0): admins of Dolibarr only (master password of the mail server, scheduled jobs) */
	const isDolibarrAdmin = () => !!rights().dolibarr_admin;
	/** Settings > Recognized domains (1.59.0): right « Manage the recognized domains » */
	const isTrustManager = () => !!rights().trusted_domain;


	/** Skeleton while loading (state "loading", interface §9) */
	function skeleton(lines) {
		return '<div class="ibx-page" aria-busy="true">' + '<span class="ibx-skeleton" style="width:40%"></span>'.repeat(1) + '<span class="ibx-skeleton"></span>'.repeat(lines || 6) + '</div>';
	}
	/** Error that says what to do, with a retry */
	function errorBox(err, retry) {
		const box = document.createElement('div');
		box.className = 'ibx-page';
		box.innerHTML = '<div class="ibx-notice ibx-notice--error" role="alert">' + ic('warning') + '<div class="ibx-notice__text">' + esc(err.message) + '</div>'
			+ (retry ? '<button type="button" class="ibx-btn ibx-btn--sm">' + th('InboxRetry') + '</button>' : '') + '</div>';
		if (retry) {
			$('button', box).addEventListener('click', retry);
		}
		return box;
	}
	function statusBadge(status) {
		const map = { 0: ['warning', 'InboxAccountStatusDraft'], 1: ['ok', 'InboxAccountStatusActive'], 9: ['', 'InboxAccountStatusDisabled'] };
		const m = map[status] || map[0];
		return '<span class="ibx-status' + (m[0] ? ' ibx-status--' + m[0] : '') + '">' + th(m[1]) + '</span>';
	}
	function testBadge(result) {
		if (!result) {
			return '<span class="ibx-muted">' + th('InboxNeverTested') + '</span>';
		}
		return '<span class="ibx-status ibx-status--' + esc(result) + '">' + th('InboxTestResult_' + result) + '</span>';
	}
	/** State of the synchronisation, in words (never the colour alone) */
	function syncBadge(r) {
		if (r.status !== 1) {
			return '<span class="ibx-muted">-</span>';
		}
		const state = r.sync_state || '';
		if (!state) {
			return '<span class="ibx-status">' + th('InboxSyncWaiting') + '</span>';
		}
		const cls = { ok: 'ok', first_sync: 'warning', auth_failed: 'error', unreachable: 'error', error: 'error' }[state] || '';
		return '<span class="ibx-status' + (cls ? ' ibx-status--' + cls : '') + '" title="' + esc(r.sync_error || '') + '">' + th('InboxSyncState_' + state) + '</span>'
			+ (r.date_sync ? '<div class="ibx-muted">' + esc(ago(r.date_sync)) + '</div>' : '');
	}
	function ago(ts) {
		const sec = Math.max(0, Math.round(Date.now() / 1000 - ts));
		if (sec < 60) {
			return t('InboxAgoSeconds', sec);
		}
		if (sec < 3600) {
			return t('InboxAgoMinutes', Math.round(sec / 60));
		}
		return new Date(ts * 1000).toLocaleString(I.locale());
	}
	function levelLabel(level) {
		return t('InboxLevel_' + level);
	}

	// ---------- Space ----------
	const space = {
		id: 'settings',
		icon: 'settings',
		bottom: true,
		label: () => t('InboxSettings'),
		el: null,
		route: null,
		render(el, route) {
			if (!el.dataset.done) {
				el.dataset.done = '1';
				el.innerHTML = '<div class="ibx-settings"><nav class="ibx-settings__nav" aria-label="' + th('InboxSettings') + '"></nav><div class="ibx-settings__main" tabindex="-1"></div></div>';
			}
			this.route = route;
			const page = route.parts[0] || (window.matchMedia('(max-width: 39.9375rem)').matches ? '' : 'accounts');
			$('.ibx-settings', el).dataset.mobile = page ? 'main' : 'nav';
			renderNav(el, page);
			const main = $('.ibx-settings__main', el);
			main.onscroll = null;
			if (!el.dataset.goto) {
				// A section of the page already shown: brought into view in place, the page is not made again
				el.dataset.goto = '1';
				el.addEventListener('click', ev => {
					const a = ev.target.closest('a[data-goto]');
					const sec = a && $('[data-section="' + a.dataset.goto + '"]', main);
					if (!sec || ev.ctrlKey || ev.metaKey || ev.shiftKey) {
						return;
					}
					ev.preventDefault();
					history.replaceState(null, '', a.getAttribute('href'));
					pointSection(main, a.dataset.goto);
				});
			}
			const pages = { accounts: renderAccounts, import: renderImport, general: renderGeneral, security: renderSecurity, antispam: renderAntispam, domains: renderDomains, server: renderServer, sources: renderSources, trusted: renderTrusted, me: renderMe, notifications: renderNotifications };
			const fn = pages[page];
			if (!fn) {
				if (page) {
					I.go('#/settings/accounts');
				}
				return;
			}
			fn(main, route.parts.slice(1));
		},
		refresh(msg) {
			if (this.el && !this.el.hidden && this.route && ['account', 'access', 'identity', 'tag'].includes(msg.entity)) {
				// Changed elsewhere: the card is made again (its head too)
				card = null;
				this.render(this.el, this.route);
			}
		},
	};
	I.registerSpace(space);

	/** Sections of Settings > General (1.32.0): id of the route, icon, title; in the menu, on the phone, on the titles */
	const GENERAL_SECTIONS = [['look', 'theme', 'InboxSectionLook'], ['defaults', 'tools', 'InboxSectionDefaults'], ['editor', 'fmt-bold', 'InboxEditorSection'], ['avatars', 'user-circle', 'InboxAvatars'], ['newbox', 'mailbox', 'InboxAutoconfigSection'],
		['ai', 'magic', 'InboxAiSection'], ['dictation', 'mic', 'InboxDictationSection'], ['search', 'search', 'InboxSectionSearch'], ['tags', 'tag', 'InboxGlobalTags']];
	/** Sections of Settings > My account (1.32.0); the 4th value: shown only for some users, hidden until there */
	const ME_SECTIONS = [['reading', 'mail-opened', 'InboxSectionReading'], ['mybox', 'mailbox', 'InboxSectionMyBox', true], ['order', 'folders', 'InboxBoxOrderSection', true],
		['permailbox', 'mark-read', 'InboxReadPerMailbox', true], ['unified', 'multi', 'InboxUnifiedSection', true], ['dictation', 'mic', 'InboxDictationSection', true],
		['devices', 'lock', 'InboxProtectMyDevices', true], ['display', 'view-cards', 'InboxSectionDisplay'], ['actions', 'tools', 'InboxSectionActions']];
	/** Sections of Settings > Server (1.54.0) */
	const SERVER_SECTIONS = [['keep', 'keep', 'InboxServerKeep'], ['imap', 'plug', 'InboxServerImap'], ['refresh', 'reactivity', 'InboxReactivitySetupTitle'], ['push', 'push', 'InboxPushSetupTitle'], ['master', 'key', 'InboxMasterUser'], ['cron', 'cron', 'InboxServerCron']];
	const PAGE_SECTIONS = { general: GENERAL_SECTIONS, server: SERVER_SECTIONS, me: ME_SECTIONS };

	/** Links to the sections of a page: under its link in the menu, or the row of chips of the phone */
	function sectionLinks(page, sections, cls) {
		return sections.map(([id, icon, key, cond]) => '<a class="' + cls + '" href="#/settings/' + page + '/' + id + '" data-goto="' + id + '"' + (cond ? ' hidden' : '') + '>' + ic(icon) + ' <span>' + th(key) + '</span></a>').join('');
	}

	/** Links of the sections the page shows only (the others are not there for this user) */
	function syncSectionLinks(main) {
		$$('a[data-goto]', main.closest('.ibx-settings')).forEach(a => {
			const sec = $('[data-section="' + a.dataset.goto + '"]', main);
			a.hidden = !sec || sec.hidden;
		});
	}

	/** Title of a section with its icon */
	function sectionTitle(sections, id) {
		const s = sections.find(x => x[0] === id);
		return '<h2 class="ibx-section__title">' + ic(s[1]) + ' ' + th(s[2]) + '</h2>';
	}

	function renderNav(el, page) {
		const nav = $('.ibx-settings__nav', el);
		// The page open: its sections under its link (1.32.0)
		const link = (id, icon, key) => '<a class="ibx-navlink" href="#/settings/' + id + '"' + (page === id ? ' aria-current="page"' : '') + '>' + ic(icon) + ' ' + th(key) + '</a>'
			+ (page === id && PAGE_SECTIONS[id] ? '<div class="ibx-navsub">' + sectionLinks(id, PAGE_SECTIONS[id], 'ibx-navlink ibx-navlink--sub') + '</div>' : '');
		nav.innerHTML = '<div class="ibx-settings__navtitle">' + th('InboxSettings') + '</div>'
			+ link('accounts', 'inbox', 'InboxNavAccounts')
			+ (isAdmin() ? link('import', 'download', 'InboxNavImport') : '')
			+ (isAdmin() ? link('general', 'settings', 'InboxNavGeneral') : '')
			+ (isAdmin() ? link('security', 'lock', 'InboxNavSecurity') : '')
			+ link('antispam', 'junk', 'InboxNavAntispam')
			+ (isAdmin() ? link('domains', 'shield', 'InboxNavDomains') : '')
			+ (isDolibarrAdmin() ? link('server', 'server', 'InboxNavServer') : '')
			+ (isDolibarrAdmin() ? link('sources', 'redirect', 'InboxNavSources') : '')
			+ (isTrustManager() ? link('trusted', 'trusted', 'InboxNavTrustedDomains') : '')
			+ '<div class="ibx-settings__navgroup">' + th('InboxNavPersonal') + '</div>'
			+ link('me', 'account', 'InboxNavMe')
			+ link('notifications', 'bell', 'InboxNavNotifications');
	}

	/** My settings: the receivers of my voice I agreed to, withdrawn one by one (1.28.0) */
	/**
	 * My devices (1.89.0): the browsers that hold a key for the protected mailboxes, shown only when there is one; this
	 * one is marked, each one renamed or revoked (a lost computer then opens nothing)
	 */
	async function myDevices(box) {
		if (!box) {
			return;
		}
		const mine = I.protect.device();
		let r;
		try {
			r = await api('protect', 'devices', { device: mine ? mine.deviceId : 0 });
		} catch (err) {
			return;
		}
		if (!r.devices.length) {
			box.hidden = true;
			return;
		}
		const date = ts => ts ? new Date(ts * 1000).toLocaleDateString((I.ctx('MAIN_LANG_DEFAULT', 'fr_FR') || 'fr_FR').replace('_', '-')) : '-';
		box.hidden = false;
		box.innerHTML = sectionTitle(ME_SECTIONS, 'devices') + '<p class="ibx-section__help">' + th('InboxProtectMyDevicesHelp') + '</p>'
			+ '<table class="ibx-table"><thead><tr><th>' + th('InboxProtectDevice') + '</th><th>' + th('InboxProtectFingerprint') + '</th><th>' + th('InboxProtectLastUse') + '</th><th></th></tr></thead><tbody>'
			+ r.devices.map(d => '<tr><td><input class="ibx-field ibx-field--medium" data-rename="' + d.id + '" value="' + esc(d.label) + '" aria-label="' + th('InboxProtectDevice') + '">'
				+ (mine && mine.deviceId === d.id ? ' <span class="ibx-status ibx-status--ok">' + th('InboxProtectThisDevice') + '</span>' : '') + '</td>'
				+ '<td><code class="ibx-protect__fp">' + esc(d.fingerprint) + '</code></td><td class="ibx-muted">' + esc(date(d.date_last_use)) + '</td>'
				+ '<td class="ibx-table__actions"><button type="button" class="ibx-btn ibx-btn--sm" data-revoke="' + d.id + '" data-fp="' + esc(d.fingerprint) + '">' + th('InboxProtectRevokeDevice') + '</button></td></tr>').join('')
			+ '</tbody></table>';
		I.mount(box);
		$$('[data-rename]', box).forEach(f => f.addEventListener('change', async () => {
			try {
				await post('protect', 'rename', { device: f.dataset.rename, label: f.value });
				toast(t('InboxSaved'));
			} catch (err) {
				toast(err.message, { error: true });
			}
		}));
		$$('[data-revoke]', box).forEach(b => b.addEventListener('click', async () => {
			const ok = await confirmDialog({ title: t('InboxProtectRevokeDevice'), text: t('InboxProtectRevokeDeviceConfirm', b.dataset.fp), confirm: t('InboxProtectRevokeDevice'), danger: true });
			if (!ok) {
				return;
			}
			try {
				await post('protect', 'revoke', { device: b.dataset.revoke });
			} catch (err) {
				toast(err.message, { error: true });
				return;
			}
			toast(t('InboxProtectDeviceRevoked'));
			myDevices(box);
		}));
	}

	async function myDictation(box) {
		if (!box) {
			return;
		}
		let r;
		try {
			r = await api('dictation', 'get', {});
		} catch (err) {
			return;
		}
		const list = Object.entries(r.consents || {});
		if (!r.enabled && !list.length) {
			return;
		}
		const date = ts => new Date(ts * 1000).toLocaleDateString((I.ctx('MAIN_LANG_DEFAULT', 'fr_FR') || 'fr_FR').replace('_', '-'));
		box.hidden = false;
		box.innerHTML = sectionTitle(ME_SECTIONS, 'dictation')
			+ (list.length ? '<ul class="ibx-checklist">' + list.map(([p, ts]) => '<li class="ibx-checklist__item">' + ic('mic') + '<div>' + th('InboxDictationMineGiven', t(p === 'server' && r.local ? 'InboxDictateProvider_serverlocal' : 'InboxDictateProvider_' + p, r.host || ''), date(ts)) + '</div>'
				+ '<button type="button" class="ibx-btn ibx-btn--sm ibx-js-dictrevoke" data-provider="' + esc(p) + '">' + th('InboxDictationRevoke') + '</button></li>').join('') + '</ul>'
				: '<p class="ibx-section__help">' + th('InboxDictationMineNone') + '</p>');
		I.mount(box);
		$$('.ibx-js-dictrevoke', box).forEach(b => b.addEventListener('click', async () => {
			try {
				await post('dictation', 'revoke', { provider: b.dataset.provider });
			} catch (err) {
				toast(err.message, { error: true });
				return;
			}
			toast(t('InboxDictationRevoked'));
			myDictation(box);
		}));
	}

	// Helpers of the pages, for the shared modules (js/inbox-rules.js)
	I.settingsUi = { skeleton: lines => skeleton(lines), errorBox: (err, retry) => errorBox(err, retry) };

	// ---------- Mailboxes: list ----------
	async function renderAccounts(main, parts) {
		if (parts[0]) {
			return renderAccountCard(main, parts[0], parts[1] || 'overview');
		}
		main.innerHTML = skeleton();
		let data;
		try {
			data = await api('account', 'list');
		} catch (err) {
			main.replaceChildren(errorBox(err, () => renderAccounts(main, parts)));
			return;
		}
		const rows = data.accounts;
		let html = '<div class="ibx-page"><div class="ibx-page__head"><h1 class="ibx-page__title">' + th('InboxNavAccounts') + '</h1>'
			+ (isAdmin() ? '<a class="ibx-btn" href="#/settings/import">' + ic('download') + ' ' + th('InboxImportButton') + '</a>' : '')
			+ (isAdmin() ? '<a class="ibx-btn ibx-btn--primary" href="#/settings/accounts/new">' + ic('plus') + ' ' + th('InboxNewAccount') + '</a>' : '')
			+ '</div>';
		if (!isAdmin()) {
			html += '<p class="ibx-page__intro">' + th('InboxAccountsManagerIntro') + '</p>';
		}
		if (!rows.length) {
			html += '<div class="ibx-empty" style="height:auto">' + ic('inbox', 'ibx-empty__icon') + '<div class="ibx-empty__title">' + th('InboxNoAccount') + '</div><div>'
				+ th(isAdmin() ? 'InboxNoAccountAdmin' : 'InboxNoAccountManager') + '</div></div>';
		} else {
			const pick = isAdmin();
			// After an import: the drafts tested well, ready to be turned on, picked in one click
			const ready = rows.filter(r => accountCanEnable(r));
			if (pick && ready.length) {
				html += '<div class="ibx-notice ibx-notice--info ibx-acctready">' + ic('info') + '<div class="ibx-notice__text">' + th('InboxAccountsReady', ready.length) + '</div>'
					+ '<button type="button" class="ibx-btn ibx-btn--sm ibx-js-pickready">' + th('InboxAccountsPickReady') + '</button></div>';
			}
			if (pick) {
				html += '<div class="ibx-bulkbar ibx-acctbar ibx-js-acctbar" hidden></div>';
			}
			html += '<table class="ibx-table"><thead><tr>' + (pick ? '<th class="ibx-col-pick"><label class="ibx-pickcell"><input type="checkbox" class="ibx-js-pickall" aria-label="' + th('InboxSelectAll') + '"></label></th>' : '')
				+ '<th>' + th('InboxAccount') + '</th><th>' + th('InboxAccountType') + '</th><th>' + th('InboxStatus') + '</th><th>' + th('InboxLastTest') + '</th><th>' + th('InboxSync') + '</th><th>' + th('InboxAccesses') + '</th></tr></thead><tbody>';
			rows.forEach(r => {
				html += '<tr>' + (pick ? '<td class="ibx-col-pick"><label class="ibx-pickcell"><input type="checkbox" class="ibx-js-pick" value="' + r.id + '"' + (acctKeep.has(r.id) ? ' checked' : '')
					+ ' aria-label="' + esc(t('InboxSelectAccount', r.email)) + '"></label></td>' : '')
					+ '<td><a href="#/settings/accounts/' + r.id + '/server"><b>' + esc(r.label || r.email) + '</b></a><div class="ibx-muted">' + esc(r.email) + '</div></td>'
					+ '<td>' + th(r.type === 'personal' ? 'InboxTypePersonal' : 'InboxTypeShared') + (r.owner ? '<div class="ibx-muted">' + esc(r.owner) + '</div>' : '') + '</td>'
					+ '<td>' + statusBadge(r.status) + (r.sync_state && r.sync_state !== 'ok' ? '<div><span class="ibx-status ibx-status--error">' + th('InboxSyncError') + '</span></div>' : '') + '</td>'
					+ '<td>' + testBadge(r.last_test_result) + '</td>'
					+ '<td>' + syncBadge(r) + '</td>'
					+ '<td>' + (r.type === 'personal' ? th('InboxAccessOwnerPlus', r.accesses) : (r.accesses ? esc(r.accesses) : '<span class="ibx-status ibx-status--warning">' + th('InboxNoAccess') + '</span>')) + '</td></tr>';
			});
			html += '</tbody></table>';
		}
		main.innerHTML = html + '</div>';
		I.mount(main);
		acctKeep.clear();
		if (rows.length && isAdmin()) {
			bindAccountsPick(main, parts, rows);
		}
	}

	/** A mailbox in the list of My account > Order of my mailboxes: handle, name, up and down */
	function boxOrderItem(m) {
		const name = m.label && m.label !== m.email ? m.label : m.email;
		return '<li class="ibx-boxorder__item ibx-dragrow" data-id="' + m.id + '"><span class="ibx-draghandle" aria-hidden="true">' + ic('more-v') + '</span>'
			+ '<span class="ibx-boxorder__name"><b>' + esc(name) + '</b>' + (name !== m.email ? ' <span class="ibx-muted">' + esc(m.email) + '</span>' : '') + '</span>'
			+ '<button type="button" class="ibx-btn ibx-btn--icon ibx-btn--sm ibx-js-boxup" aria-label="' + esc(t('InboxMoveUp') + ' : ' + name) + '" title="' + th('InboxMoveUp') + '">' + ic('chevron-up') + '</button>'
			+ '<button type="button" class="ibx-btn ibx-btn--icon ibx-btn--sm ibx-js-boxdown" aria-label="' + esc(t('InboxMoveDown') + ' : ' + name) + '" title="' + th('InboxMoveDown') + '">' + ic('chevron-down') + '</button></li>';
	}
	/**
	 * Order of my mailboxes: dragged by the handle, or moved by the arrows (keyboard, phone); kept at once in my
	 * settings, every list of my mailboxes follows it; « Back to the order by name » forgets it
	 * @param {HTMLElement} main Page My account
	 */
	function bindBoxOrder(main) {
		const list = $('.ibx-js-boxorder', main);
		if (!list) {
			return;
		}
		const reset = $('.ibx-js-boxorderreset', main);
		const items = () => $$('.ibx-boxorder__item', list);
		const paintArrows = () => items().forEach((li, i, all) => {
			$('.ibx-js-boxup', li).disabled = i === 0;
			$('.ibx-js-boxdown', li).disabled = i === all.length - 1;
		});
		const save = async value => {
			try {
				const r = await post('userparam', 'set', { param: 'accounts_order', value: JSON.stringify(value) });
				Dolibarr.executeHook('inbox:params:changed', r.params);
				reset.hidden = !value.length;
				toast(t('InboxSaved'));
			} catch (err) {
				toast(err.message, { error: true });
			}
		};
		const keep = () => {
			paintArrows();
			save(items().map(li => +li.dataset.id));
		};
		list.addEventListener('click', e => {
			const btn = e.target.closest('.ibx-js-boxup, .ibx-js-boxdown');
			if (!btn) {
				return;
			}
			const li = btn.closest('li');
			const up = btn.classList.contains('ibx-js-boxup');
			const other = up ? li.previousElementSibling : li.nextElementSibling;
			if (!other) {
				return;
			}
			list.insertBefore(up ? li : other, up ? other : li);
			keep();
			// The focus stays on the arrow, on the mailbox that moved (several steps in a row with the keyboard)
			(up && !li.previousElementSibling ? $('.ibx-js-boxdown', li) : (!up && !li.nextElementSibling ? $('.ibx-js-boxup', li) : btn)).focus();
		});
		dragByHandle(list);
		let dragged = null;
		list.addEventListener('dragstart', e => {
			dragged = e.target.closest('.ibx-boxorder__item');
			if (dragged) {
				dragged.classList.add('is-dragging');
				e.dataTransfer.effectAllowed = 'move';
			}
		});
		list.addEventListener('dragover', e => {
			const over = e.target.closest('.ibx-boxorder__item');
			if (!dragged || !over || over === dragged) {
				return;
			}
			e.preventDefault();
			const r = over.getBoundingClientRect();
			list.insertBefore(dragged, e.clientY < r.top + r.height / 2 ? over : over.nextElementSibling);
		});
		list.addEventListener('dragend', () => {
			if (dragged) {
				dragged.classList.remove('is-dragging');
				dragged = null;
				keep();
			}
		});
		reset.addEventListener('click', async () => {
			const sorted = items().sort((a, b) => $('.ibx-boxorder__name b', a).textContent.localeCompare($('.ibx-boxorder__name b', b).textContent));
			sorted.forEach(li => list.appendChild(li));
			paintArrows();
			await save([]);
			// The button goes away: the focus on the list, at its first mailbox
			$('.ibx-js-boxdown', items()[0]).focus();
		});
		paintArrows();
	}

	/**
	 * Rows moved only by their handle: a row draggable as a whole starts a drag from its fields, and the text of a
	 * field cannot be selected any more. The row becomes draggable while its handle is pressed.
	 * @param {HTMLElement} box Container of the rows (.ibx-dragrow, handle .ibx-draghandle)
	 */
	function dragByHandle(box) {
		// Once per container: a tab drawn again (after a change) keeps its listeners
		if (box.dataset.dragHandle) {
			return;
		}
		box.dataset.dragHandle = '1';
		const off = () => $$('.ibx-dragrow[draggable="true"]', box).forEach(r => {
			r.draggable = false;
		});
		box.addEventListener('pointerdown', e => {
			const row = e.target.closest('.ibx-dragrow');
			if (row && e.target.closest('.ibx-draghandle')) {
				row.draggable = true;
			}
		});
		box.addEventListener('pointerup', off);
		box.addEventListener('dragend', off);
	}

	/** Mailboxes kept selected after a change of several (those that could not change) */
	const acctKeep = new Set();
	/** A mailbox that may be turned on: not active, its last test passed (the server refuses the others) */
	function accountCanEnable(r) {
		return r.status !== 1 && ['ok', 'warning'].includes(r.last_test_result);
	}
	/**
	 * Several mailboxes turned on or off from the list: a box per line, a bar of the selection with the actions that
	 * apply to it (counted), one call per mailbox (the checks of each one stay those of its card)
	 * @param {HTMLElement} main Page
	 * @param {string[]} parts Route
	 * @param {object[]} rows Mailboxes of the list
	 */
	function bindAccountsPick(main, parts, rows) {
		const bar = $('.ibx-js-acctbar', main);
		const all = $('.ibx-js-pickall', main);
		const boxes = $$('.ibx-js-pick', main);
		const byId = new Map(rows.map(r => [r.id, r]));
		let busy = false;
		const picked = () => boxes.filter(b => b.checked).map(b => byId.get(+b.value));
		const paint = () => {
			const sel = picked();
			all.checked = sel.length > 0 && sel.length === boxes.length;
			all.indeterminate = sel.length > 0 && sel.length < boxes.length;
			boxes.forEach(b => b.closest('tr').classList.toggle('is-picked', b.checked));
			bar.hidden = !sel.length;
			if (!sel.length || busy) {
				return;
			}
			const on = sel.filter(accountCanEnable);
			const off = sel.filter(r => r.status === 1);
			const skipped = sel.filter(r => r.status !== 1 && !accountCanEnable(r));
			bar.innerHTML = '<span class="ibx-bulkbar__count" role="status" aria-label="' + th('InboxAccountsSelectedCount', sel.length) + '"><b class="ibx-bulkbar__num">' + sel.length + '</b>'
				+ '<span class="ibx-bulkbar__word" aria-hidden="true">' + th('InboxSelectedWord') + '</span>'
				+ (skipped.length ? '<span class="ibx-muted ibx-acctbar__skip">' + th('InboxAccountsSkipped', skipped.length) + '</span>' : '') + '</span>'
				+ (off.length ? '<button type="button" class="ibx-btn ibx-btn--sm ibx-js-acctoff">' + th('InboxDisableCount', off.length) + '</button>' : '')
				+ '<button type="button" class="ibx-btn ibx-btn--primary ibx-btn--sm ibx-js-accton"' + (on.length ? '' : ' disabled title="' + th('InboxErrorEnableNeedsTest') + '"') + '>' + th('InboxEnableCount', on.length) + '</button>'
				+ '<button type="button" class="ibx-btn ibx-btn--icon ibx-btn--sm ibx-js-acctclear" title="' + th('InboxClearSelection') + '" aria-label="' + th('InboxClearSelection') + '">' + ic('close') + '</button>';
			$('.ibx-js-accton', bar).addEventListener('click', () => run(on, 1));
			const offBtn = $('.ibx-js-acctoff', bar);
			if (offBtn) {
				offBtn.addEventListener('click', async () => {
					if (await confirmDialog({ title: t('InboxDisableManyTitle', off.length), text: t('InboxDisableText'), confirm: t('InboxDisable'), danger: true })) {
						run(off, 9);
					}
				});
			}
			$('.ibx-js-acctclear', bar).addEventListener('click', () => {
				boxes.forEach(b => {
					b.checked = false;
				});
				paint();
				all.focus();
			});
		};
		/** One mailbox after the other; the list read again, those that failed still selected */
		const run = async (list, status) => {
			busy = true;
			$$('button', bar).forEach(b => {
				b.disabled = true;
			});
			const count = $('.ibx-bulkbar__count', bar);
			const failed = [];
			let done = 0;
			for (const r of list) {
				count.textContent = t('InboxAccountsWorking', done + failed.length + 1, list.length);
				try {
					await post('account', 'status', { id: r.id, status });
					I.changed('account', r.id);
					done++;
				} catch (err) {
					failed.push(r.email + ' (' + err.message + ')');
					acctKeep.add(r.id);
				}
			}
			if (done) {
				toast(t(status === 1 ? 'InboxAccountsEnabledCount' : 'InboxAccountsDisabledCount', done));
			}
			if (failed.length) {
				toast(t('InboxAccountsFailedCount', failed.length, failed.join(', ')), { error: true });
			}
			renderAccounts(main, parts);
		};
		all.addEventListener('change', () => {
			boxes.forEach(b => {
				b.checked = all.checked;
			});
			paint();
		});
		boxes.forEach(b => b.addEventListener('change', paint));
		const ready = $('.ibx-js-pickready', main);
		if (ready) {
			ready.addEventListener('click', () => {
				boxes.forEach(b => {
					b.checked = accountCanEnable(byId.get(+b.value));
				});
				paint();
				const go = $('.ibx-js-accton', bar);
				if (go) {
					go.focus();
				}
			});
		}
		paint();
	}

	/** Greys of the interface (pure): light, dark (same values as css/inbox.css and inboxLookTokens()) */
	const GREYS = {
		light: { 'bg-pane': '#f7f7f8', 'bg-sunken': '#efeff1', 'bg-hover': '#e9e9ec', border: '#dddde1', 'border-strong': '#c1c1c7', text: '#18181b', 'text-muted': '#53535a', 'text-faint': '#86868e' },
		dark: { bg: '#18181b', 'bg-pane': '#1f1f23', 'bg-sunken': '#131316', 'bg-hover': '#2a2a2f', border: '#34343a', 'border-strong': '#48484f', text: '#e7e7ea', 'text-muted': '#a5a5ad', 'text-faint': '#76767e' },
	};
	/** Colours offered for the accent: readable in light and in dark, far from the red of the errors */
	const ACCENTS = [['#8a2b6e', 'prune'], ['#6b46c1', 'violet'], ['#3f4fbf', 'indigo'], ['#1f63b5', 'blue'], ['#1d6a8a', 'teal'], ['#2f7d4f', 'green'], ['#995400', 'amber'], ['#4a5568', 'slate']];

	/**
	 * Look of the setup for each theme (same computation as inboxLookTokens() in lib/inboxapp.lib.php): the accent in
	 * light (darker under 4.5:1 on white), a lighter tone in dark (6:1 on its background) or the one chosen, the text
	 * on it, and the greys, pure or tinted with the hue of the accent
	 * @param {string} hex #rrggbb
	 * @param {string} darkHex accent chosen for the dark theme, '' = computed
	 * @param {string} greys neutral or accent
	 * @returns {object} {light: tokens, dark: tokens, auto (computed dark accent), darkContrast}
	 */
	function lookTokens(hex, darkHex, greys) {
		const rgb = h => [0, 2, 4].map(i => parseInt(h.slice(1 + i, 3 + i), 16));
		const lum = c => {
			const l = c.map(v => {
				v /= 255;
				return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
			});
			return 0.2126 * l[0] + 0.7152 * l[1] + 0.0722 * l[2];
		};
		const contrast = (a, b) => {
			const x = lum(a);
			const y = lum(b);
			return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05);
		};
		const mix = (a, b, t) => a.map((v, i) => Math.round(v + (b[i] - v) * t));
		const hexOf = c => '#' + c.map(v => v.toString(16).padStart(2, '0')).join('');
		const toHsl = c => {
			const [r, g, b] = c.map(v => v / 255);
			const max = Math.max(r, g, b);
			const min = Math.min(r, g, b);
			const l = (max + min) / 2;
			let h = 0;
			let sat = 0;
			if (max !== min) {
				const dd = max - min;
				sat = l > 0.5 ? dd / (2 - max - min) : dd / (max + min);
				h = (max === r ? (g - b) / dd + (g < b ? 6 : 0) : (max === g ? (b - r) / dd + 2 : (r - g) / dd + 4)) / 6;
			}
			return [h, sat, l];
		};
		const fromHsl = (h, sat, l) => {
			const f = (pp, q, tt) => {
				tt = tt < 0 ? tt + 1 : (tt > 1 ? tt - 1 : tt);
				return tt < 1 / 6 ? pp + (q - pp) * 6 * tt : (tt < 1 / 2 ? q : (tt < 2 / 3 ? pp + (q - pp) * (2 / 3 - tt) * 6 : pp));
			};
			const q = l < 0.5 ? l * (1 + sat) : l + sat - l * sat;
			const pp = 2 * l - q;
			return [f(pp, q, h + 1 / 3), f(pp, q, h), f(pp, q, h - 1 / 3)].map(v => Math.round(v * 255));
		};
		const white = [255, 255, 255];
		const black = [0, 0, 0];
		const base = rgb(/^#[0-9a-f]{6}$/i.test(hex) ? hex : '#8a2b6e');
		const hsl = toHsl(base);
		let light = base;
		for (let l = hsl[2]; contrast(light, white) < 4.5 && l > 0; l -= 0.02) {
			light = fromHsl(hsl[0], hsl[1], Math.max(0, l));
		}
		const greyLight = Object.assign({}, GREYS.light);
		const greyDark = Object.assign({}, GREYS.dark);
		if (greys === 'accent') {
			[[greyLight, 0.06], [greyDark, 0.10]].forEach(([g, sat]) => Object.keys(g).forEach(k => {
				g[k] = hexOf(fromHsl(hsl[0], sat, toHsl(rgb(g[k]))[2]));
			}));
		}
		const bg = rgb(greyDark.bg);
		let dark = base;
		for (let l = hsl[2]; contrast(dark, bg) < 6 && l < 1; l += 0.02) {
			dark = fromHsl(hsl[0], hsl[1], Math.min(1, l));
		}
		const auto = dark;
		if (/^#[0-9a-f]{6}$/i.test(darkHex || '')) {
			dark = rgb(darkHex);
		}
		const on = c => {
			const deep = mix(c, black, 0.8);
			return hexOf(contrast(c, white) >= contrast(c, deep) ? white : deep);
		};
		return {
			light: Object.assign({ accent: hexOf(light), 'on-accent': on(light) }, greyLight),
			dark: Object.assign({ accent: hexOf(dark), 'on-accent': on(dark) }, greyDark),
			auto: hexOf(auto),
			darkContrast: contrast(dark, bg),
		};
	}
	/** CSS of the look (the high contrast theme keeps its own) */
	function accentCss(hex, darkHex, greys) {
		const tk = lookTokens(hex, darkHex, greys);
		const css = set => Object.keys(set).map(k => '--ibx-' + k + ':' + set[k] + ';').join('');
		const d = css(tk.dark);
		return ':root:not([data-ibx-theme="contrast"]){' + css(tk.light) + '}@media (prefers-color-scheme: dark){:root:not([data-ibx-theme="light"]):not([data-ibx-theme="contrast"]){' + d + '}}:root[data-ibx-theme="dark"]{' + d + '}';
	}

	// ---------- Mailbox: card ----------
	const TABS = [
		{ id: 'overview', key: 'InboxTabOverview', admin: false },
		{ id: 'server', key: 'InboxTabServer', admin: true },
		{ id: 'folders', key: 'InboxTabFolders', admin: true },
		{ id: 'identities', key: 'InboxTabIdentities', admin: false },
		{ id: 'templates', key: 'InboxTabTemplates', admin: false },
		{ id: 'access', key: 'InboxTabAccess', admin: true },
		{ id: 'tags', key: 'InboxTabTags', admin: false },
		{ id: 'usage', key: 'InboxTabUsage', admin: false },
		{ id: 'sync', key: 'InboxTabSync', admin: false },
		{ id: 'rules', key: 'InboxTabRules', admin: false },
		{ id: 'consumers', key: 'InboxTabConsumers', admin: false },
		{ id: 'away', key: 'InboxTabAway', admin: false },
		{ id: 'protection', key: 'InboxTabProtection', admin: false },
		{ id: 'journal', key: 'InboxTabJournal', admin: false },
	];

	/**
	 * Tabs of the card (1.89.0): the sections grouped by intention, in the order of use (overview, then what is set
	 * once, then what moves often); a tab with one section has no chips. The address keeps the section
	 * (#/settings/accounts/<id>/rules): the links of the other screens stay good.
	 */
	const GROUPS = [
		{ id: 'overview', key: 'InboxTabOverview', sections: ['overview'] },
		{ id: 'connection', key: 'InboxTabConnection', sections: ['server', 'folders', 'sync'] },
		{ id: 'access', key: 'InboxTabAccess', sections: ['access', 'protection'] },
		// Outgoing: what is written (the out of office is a mail written in advance)
		{ id: 'writing', key: 'InboxTabWriting', sections: ['identities', 'templates', 'away'] },
		// Incoming: sorted at the arrival, classed, shown beside the mail
		{ id: 'reception', key: 'InboxTabReception', sections: ['rules', 'tags', 'usage', 'consumers'] },
		{ id: 'journal', key: 'InboxTabJournal', sections: ['journal'] },
	];
	/** Name of a section in its chip (the section « access » under the tab Access: the people) */
	const SECTION_KEYS = { access: 'InboxTabAccessPeople', usage: 'InboxTabUsageCard' };
	/** Tabs and chips of the card, the current section shown */
	function cardNav(id, tabs, tab, isNew) {
		const visible = GROUPS.map(g => Object.assign({}, g, { list: g.sections.map(sid => tabs.find(x => x.id === sid)).filter(Boolean) })).filter(g => g.list.length);
		const cur = visible.find(g => g.list.some(x => x.id === tab)) || visible[0];
		let html = '<div class="ibx-tabs" role="tablist">' + visible.map(g => {
			const first = g.list[0].id;
			const disabled = isNew && !g.list.some(x => x.id === 'server');
			return '<a class="ibx-tab" role="tab" href="' + (disabled ? '#' : '#/settings/accounts/' + id + '/' + (g === cur ? tab : first)) + '" aria-selected="' + (g === cur) + '"'
				+ (disabled ? ' aria-disabled="true" title="' + th('InboxSaveFirst') + '"' : '') + '>' + th(g.key) + '</a>';
		}).join('') + '</div>';
		if (cur && cur.list.length > 1) {
			html += '<nav class="ibx-sectabs" aria-label="' + th(cur.key) + '">' + cur.list.map(x => {
				const disabled = isNew && x.id !== 'server';
				return '<a class="ibx-sectab' + (x.id === tab ? ' is-on' : '') + '" href="' + (disabled ? '#' : '#/settings/accounts/' + id + '/' + x.id) + '"' + (x.id === tab ? ' aria-current="page"' : '')
					+ (disabled ? ' aria-disabled="true" title="' + th('InboxSaveFirst') + '"' : '') + '>' + th(SECTION_KEYS[x.id] || x.key) + '</a>';
			}).join('') + '</nav>';
		}
		return html;
	}
	/** Navigation of the card painted again for another section (the content is changed by showTab) */
	function paintCardNav(main, c, tab) {
		const nav = $('.ibx-js-cardnav', main);
		if (nav) {
			nav.innerHTML = cardNav(c.id, c.tabs, tab, false);
		}
	}

	/** Card on screen: the same mailbox in another tab keeps its head and tabs, only the content of the tab changes */
	let card = null;
	/** Mailbox whose test was asked from the head of the card (button beside the switch): run once its form is there */
	let testAsked = 0;
	const TAB_FNS = () => ({ overview: tabOverview, server: tabServer, folders: tabFolders, identities: tabIdentities, templates: tabTemplates, access: tabAccess, tags: tabTags, usage: tabUsage, sync: tabSync, rules: (b, a) => I.rules.tab(b, a), consumers: (b, a) => I.consumers.tab(b, a), away: (b, a) => I.rules.awayTab(b, a), protection: tabProtection, journal: tabJournal });

	/**
	 * Content of a tab, without flash: made aside and put in place once loaded (or after 300 ms, then with its
	 * skeleton), the height of the previous content kept meanwhile, a short fade in
	 */
	function showTab(main, account, tab) {
		$$('.ibx-tabs .ibx-tab', main).forEach(a => a.setAttribute('aria-selected', a.getAttribute('href').endsWith('/' + tab) ? 'true' : 'false'));
		const old = $('.ibx-js-tab', main);
		const stage = document.createElement('div');
		stage.className = 'ibx-js-tab';
		stage.style.minHeight = old.offsetHeight + 'px';
		let placed = false;
		let timer = 0;
		const place = () => {
			if (placed) {
				return;
			}
			placed = true;
			clearTimeout(timer);
			stage.classList.add('is-entering');
			old.replaceWith(stage);
		};
		const ready = () => stage.childNodes.length > 0 && !stage.querySelector('[aria-busy="true"]');
		const obs = new MutationObserver(() => {
			if (ready()) {
				place();
				stage.style.minHeight = '';
				obs.disconnect();
			}
		});
		timer = setTimeout(place, 300);
		obs.observe(stage, { childList: true, subtree: true });
		TAB_FNS()[tab](stage, account, main);
		if (ready()) {
			place();
			stage.style.minHeight = '';
			obs.disconnect();
		}
	}

	async function renderAccountCard(main, id, tab) {
		const isNew = id === 'new';
		if (isNew && !isAdmin()) {
			I.go('#/settings/accounts');
			return;
		}
		if (!isNew && card && card.id === String(id) && card.main === main && $('.ibx-js-tab', main) && card.tabs.some(x => x.id === tab)) {
			showTab(main, card.account, tab);
			paintCardNav(main, card, tab);
			return;
		}
		card = null;
		main.innerHTML = skeleton();
		let account = null;
		if (!isNew) {
			try {
				account = (await api('account', 'get', { id })).account;
			} catch (err) {
				main.replaceChildren(errorBox(err, () => renderAccountCard(main, id, tab)));
				return;
			}
		}
		const owner = account && account.type === 'personal' && +account.fk_user_owner === +I.ctx('INBOX_USER_ID', 0);
		const tabs = TABS.filter(x => (isAdmin() || !x.admin) && (x.id !== 'protection' || (account && (account.protected || (owner && !isAdmin())))));
		if (!tabs.find(x => x.id === tab)) {
			tab = tabs[0].id;
		}
		if (isNew) {
			tab = 'server';
		}
		const title = isNew ? t('InboxNewAccount') : (account.label || account.email);
		let html = '<div class="ibx-page"><a class="ibx-page__back" href="#/settings/accounts">' + ic('chevron-left') + ' ' + th('InboxNavAccounts') + '</a>'
			+ '<div class="ibx-page__head"><h1 class="ibx-page__title">' + esc(title) + '</h1>' + (account ? statusSwitch(account) : '') + '</div>';
		html += '<div class="ibx-js-cardnav">' + cardNav(id, isNew ? tabs.filter(x => x.id === 'server') : tabs.filter(x => x.id !== 'overview' || !isNew), tab, isNew) + '</div><div class="ibx-js-tab"></div></div>';
		main.innerHTML = html;
		I.mount(main);
		main.addEventListener('click', e => {
			const off = e.target.closest('.ibx-js-cardnav [aria-disabled="true"]');
			if (off) {
				e.preventDefault();
			}
		});
		if (account) {
			bindStatusSwitch(main, account, tab);
		}
		const box = $('.ibx-js-tab', main);
		if (!isNew) {
			card = { id: String(id), main, account, tabs };
		}
		TAB_FNS()[tab](box, account, main);
	}

	// ----- Tab Overview (1.89.0): only what asks for an action, each point with its link -----
	async function tabOverview(box, account) {
		const a = account;
		const go = section => '#/settings/accounts/' + a.id + '/' + section;
		const points = [];
		const add = (level, text, section, link) => points.push({ level, text, section, link });
		if (a.status === 0) {
			add('warning', t('InboxOverviewDraft'), 'server', t('InboxTabServer'));
		} else if (a.status === 9) {
			add('info', t('InboxOverviewDisabled'), 'server', t('InboxTabServer'));
		}
		if (a.last_test_result === 'error') {
			add('error', t('InboxOverviewTestFailed'), 'server', t('InboxTestConnection'));
		}
		if (a.status === 1 && a.protect_level !== 'content' && ['auth_failed', 'unreachable', 'error'].includes(a.sync_state)) {
			add('error', t('InboxOverviewSyncError', t('InboxSyncState_' + a.sync_state)) + (a.sync_error ? ' : ' + a.sync_error : ''), 'sync', t('InboxTabSync'));
		}
		if (isAdmin() && a.type === 'shared' && a.summary && !a.summary.accesses) {
			add('warning', t('InboxOverviewNoAccess'), 'access', t('InboxTabAccessPeople'));
		}
		const missing = (a.missing_roles || []).map(r => t('InboxFolderRole' + r[0].toUpperCase() + r.slice(1)));
		if (isAdmin() && missing.length) {
			add('warning', t('InboxOverviewRolesMissing', missing.join(', ')), 'folders', t('InboxTabFolders'));
		}
		box.innerHTML = skeleton(3);
		if (a.protected) {
			try {
				const d = await api('protect', 'accesses', { id: a.id });
				if (!d.unlocked) {
					add('info', t('InboxOverviewLocked'), 'protection', t('InboxTabProtection'));
				}
				const waiting = d.users.reduce((n, u) => n + u.devices.filter(x => x.state !== 'ok').length, 0);
				if (waiting) {
					add('warning', t('InboxOverviewDevicesWaiting', waiting), 'protection', t('InboxTabProtection'));
				}
			} catch (e) {
				// the other points stay
			}
		}
		const order = { error: 0, warning: 1, info: 2 };
		points.sort((x, y) => order[x.level] - order[y.level]);
		const when = a.date_sync ? new Date(a.date_sync * 1000).toLocaleString(I.locale()) : '';
		const state = a.status !== 1 ? '' : (a.protect_level === 'content' ? t('InboxOverviewProtectedState') : (when ? t('InboxOverviewSyncedAt', when) : ''));
		box.innerHTML = (points.length
			? '<ul class="ibx-overview">' + points.map(x => '<li class="ibx-overview__item ibx-overview__item--' + x.level + '">' + ic(x.level === 'info' ? 'info' : 'warning')
				+ '<span class="ibx-overview__text">' + esc(x.text) + '</span><a class="ibx-btn ibx-btn--sm" href="' + go(x.section) + '">' + esc(x.link) + '</a></li>').join('') + '</ul>'
			: '<div class="ibx-empty ibx-empty--inline">' + ic('done', 'ibx-empty__icon') + '<div class="ibx-empty__title">' + th('InboxOverviewNothing') + '</div></div>')
			+ (state ? '<p class="ibx-muted ibx-overview__state">' + esc(state) + '</p>' : '');
	}

	// ----- Tab Protection (1.89.0, analyse-boites-protegees §5): mode of the mailbox, encrypted accesses per device -----
	const PROTECT_STATE = { ok: ['ok', 'InboxProtectStateOk'], obsolete: ['warning', 'InboxProtectStateObsolete'], missing: ['', 'InboxProtectStateMissing'] };
	async function tabProtection(box, account) {
		box.innerHTML = skeleton(4);
		let data;
		try {
			data = await api('protect', 'accesses', { id: account.id });
		} catch (err) {
			box.replaceChildren(errorBox(err, () => tabProtection(box, account)));
			return;
		}
		const P = I.protect;
		const again = () => {
			I.changed('account', account.id);
			tabProtection(box, account);
		};
		let html = '';
		if (!data.protected) {
			html += '<p class="ibx-section__help">' + th('InboxProtectIntro') + '</p>'
				+ '<ul class="ibx-protect__list"><li>' + th('InboxProtectWhatYes') + '</li><li>' + th('InboxProtectWhatNo') + '</li></ul>';
			if (!data.can_decide) {
				html += '<div class="ibx-notice ibx-notice--info">' + ic('info') + '<div class="ibx-notice__text">' + th('InboxProtectWhoDecides') + '</div></div>';
			} else if (data.refusal) {
				html += '<div class="ibx-notice ibx-notice--info">' + ic('info') + '<div class="ibx-notice__text">' + esc(data.refusal) + '</div></div>';
			} else if (!data.own_access) {
				html += '<div class="ibx-notice ibx-notice--info">' + ic('info') + '<div class="ibx-notice__text">' + th('InboxProtectErrorOwnAccess') + '</div></div>';
			} else if (!P.supported()) {
				html += '<div class="ibx-notice ibx-notice--warning">' + ic('warning') + '<div class="ibx-notice__text">' + th('InboxProtectUnsupported') + '</div></div>';
			} else {
				html += '<button type="button" class="ibx-btn ibx-btn--primary ibx-js-protect">' + ic('lock') + ' ' + th('InboxProtectAction') + '</button>';
			}
			box.innerHTML = html;
			const btn = $('.ibx-js-protect', box);
			if (btn) {
				btn.addEventListener('click', () => protectDialog(account, data, again));
			}
			return;
		}

		// Protected: state of this session, then the devices of each user who has an access
		const mine = P.byId(account.id);
		const unlocked = data.unlocked;
		html += '<div class="ibx-notice ibx-notice--info">' + ic('lock') + '<div class="ibx-notice__text">' + th('InboxProtectIsOn') + '</div>'
			+ (unlocked ? '<button type="button" class="ibx-btn ibx-btn--sm ibx-js-lock">' + th('InboxProtectLockNow') + '</button>'
				: (mine ? '<button type="button" class="ibx-btn ibx-btn--sm ibx-btn--primary ibx-js-unlock">' + th('InboxProtectUnlock') + '</button>' : '')) + '</div>';
		// Level of the protection (password / content): said, changed by who decides the mode
		html += '<section class="ibx-section"><h2 class="ibx-section__title">' + th('InboxProtectLevelLegend') + '</h2>'
			+ '<p><b>' + th('InboxProtectLevel_' + (data.level || 'password')) + '</b> : ' + th('InboxProtectLevelHelp_' + (data.level || 'password')) + '</p>'
			+ (data.can_decide ? '<button type="button" class="ibx-btn ibx-btn--sm ibx-js-level" data-level="' + (data.level === 'content' ? 'password' : 'content') + '">'
				+ th(data.level === 'content' ? 'InboxProtectLevelToPassword' : 'InboxProtectLevelToContent') + '</button>' : '') + '</section>';
		html += '<section class="ibx-section"><h2 class="ibx-section__title">' + th('InboxProtectAccesses') + '</h2><p class="ibx-section__help">' + th('InboxProtectAccessesHelp') + '</p>';
		const waiting = [];
		html += '<table class="ibx-table"><thead><tr><th>' + th('InboxAccessWho') + '</th><th>' + th('InboxProtectDevice') + '</th><th>' + th('InboxProtectFingerprint') + '</th><th>' + th('InboxProtectState') + '</th><th></th></tr></thead><tbody>';
		data.users.forEach(u => {
			if (!u.devices.length) {
				html += '<tr><td>' + esc(u.name) + '</td><td colspan="4" class="ibx-muted">' + th('InboxProtectNoDevice') + '</td></tr>';
				return;
			}
			u.devices.forEach((d, i) => {
				const st = PROTECT_STATE[d.state] || PROTECT_STATE.missing;
				const can = unlocked && d.state !== 'ok';
				if (can) {
					waiting.push({ fk_user: u.id, device: d.id, public_key: d.public_key, fingerprint: d.fingerprint });
				}
				html += '<tr><td>' + (i === 0 ? esc(u.name) : '') + '</td><td>' + esc(d.label || '-') + '</td><td><code class="ibx-protect__fp">' + esc(d.fingerprint) + '</code></td>'
					+ '<td><span class="ibx-status' + (st[0] ? ' ibx-status--' + st[0] : '') + '">' + th(st[1]) + '</span></td>'
					+ '<td class="ibx-table__actions">' + (can ? '<label class="ibx-check"><input type="checkbox" data-give="' + u.id + ':' + d.id + '"> ' + th('InboxProtectGiveThis') + '</label>' : '')
					+ (isAdmin() ? ' <button type="button" class="ibx-btn ibx-btn--sm ibx-btn--ghost ibx-btn--icon" data-revoke-device="' + d.id + '" data-fp="' + esc(d.fingerprint) + '" title="' + th('InboxProtectRevokeDevice') + '" aria-label="' + th('InboxProtectRevokeDevice') + '">' + ic('close') + '</button>' : '')
					+ '</td></tr>';
			});
		});
		html += '</tbody></table>';
		if (waiting.length) {
			html += '<p class="ibx-section__help">' + th('InboxProtectGiveHelp') + '</p><button type="button" class="ibx-btn ibx-btn--primary ibx-js-give">' + ic('key') + ' ' + th('InboxProtectGive') + '</button>';
		} else if (!unlocked) {
			html += '<p class="ibx-muted">' + th('InboxProtectGiveNeedsUnlock') + '</p>';
		}
		html += '</section>';
		if (data.can_decide) {
			html += '<section class="ibx-section"><h2 class="ibx-section__title">' + th('InboxProtectMode') + '</h2>'
				+ '<div class="ibx-inline"><button type="button" class="ibx-btn ibx-js-unprotect"' + (unlocked ? '' : ' aria-disabled="true" title="' + th('InboxProtectGiveNeedsUnlock') + '"') + '>' + th('InboxProtectBackNormal') + '</button>'
				+ '<button type="button" class="ibx-btn ibx-btn--danger ibx-js-reset">' + th('InboxProtectReset') + '</button></div>'
				+ '<p class="ibx-section__help">' + th('InboxProtectResetHelp') + '</p></section>';
		}
		box.innerHTML = html;

		const on = (sel, fn) => {
			const el = $(sel, box);
			if (el) {
				el.addEventListener('click', async e => {
					if (el.getAttribute('aria-disabled') === 'true') {
						e.preventDefault();
						return;
					}
					try {
						await fn();
					} catch (err) {
						toast(err.message, { error: true });
					}
				});
			}
		};
		on('.ibx-js-level', async () => {
			const to = $('.ibx-js-level', box).dataset.level;
			const ok = await confirmDialog({ title: t(to === 'content' ? 'InboxProtectLevelToContent' : 'InboxProtectLevelToPassword'),
				text: t(to === 'content' ? 'InboxProtectLevelToContentConfirm' : 'InboxProtectLevelToPasswordConfirm'), confirm: t(to === 'content' ? 'InboxProtectLevelToContent' : 'InboxProtectLevelToPassword'),
				danger: to === 'content', typeToConfirm: to === 'content' ? account.email : '' });
			if (!ok) {
				return;
			}
			await post('protect', 'level', { id: account.id, level: to });
			await P.refresh();
			again();
		});
		on('.ibx-js-lock', async () => {
			await P.lock(account.id);
			again();
		});
		on('.ibx-js-unlock', async () => {
			if (await P.openLock(account.id)) {
				again();
			}
		});
		on('.ibx-js-give', async () => {
			const chosen = new Set($$('[data-give]:checked', box).map(c => c.dataset.give));
			const targets = waiting.filter(w => chosen.has(w.fk_user + ':' + w.device));
			if (!targets.length) {
				toast(t('InboxProtectChooseDevices'), { error: true });
				return;
			}
			const n = await P.grant(account.id, targets);
			toast(t('InboxProtectGiven', n));
			again();
		});
		$$('[data-revoke-device]', box).forEach(b => b.addEventListener('click', async () => {
			const ok = await confirmDialog({ title: t('InboxProtectRevokeDevice'), text: t('InboxProtectRevokeDeviceConfirm', b.dataset.fp), confirm: t('InboxProtectRevokeDevice'), danger: true });
			if (!ok) {
				return;
			}
			try {
				await post('protect', 'revoke', { device: b.dataset.revokeDevice });
				again();
			} catch (err) {
				toast(err.message, { error: true });
			}
		}));
		on('.ibx-js-unprotect', async () => {
			const ok = await confirmDialog({ title: t('InboxProtectBackNormal'), text: t('InboxProtectBackNormalConfirm'), confirm: t('InboxProtectBackNormal') });
			if (!ok) {
				return;
			}
			await post('protect', 'unprotect', { id: account.id });
			await P.refresh();
			toast(t('InboxProtectBackNormalDone'));
			again();
		});
		on('.ibx-js-reset', async () => {
			const ok = await confirmDialog({ title: t('InboxProtectReset'), text: t('InboxProtectResetConfirm'), confirm: t('InboxProtectReset'), danger: true, typeToConfirm: account.email });
			if (!ok) {
				return;
			}
			await post('protect', 'reset', { id: account.id, confirm_email: account.email });
			await P.refresh();
			again();
		});
	}

	/** Dialog « Protect this mailbox » (§5.1 and §8): what changes, the passwords, the address typed again */
	function protectDialog(account, data, done) {
		const P = I.protect;
		dialog({
			title: t('InboxProtectAction'),
			wide: true,
			body: '<fieldset class="ibx-fieldset"><legend>' + th('InboxProtectLevelLegend') + '</legend>'
				+ '<label class="ibx-check ibx-check--block"><input type="radio" name="ibx-prot-level" value="password" checked> <span><b>' + th('InboxProtectLevel_password') + '</b><span class="ibx-formrow__help">' + th('InboxProtectLevelHelp_password') + '</span></span></label>'
				+ '<label class="ibx-check ibx-check--block"><input type="radio" name="ibx-prot-level" value="content"> <span><b>' + th('InboxProtectLevel_content') + '</b><span class="ibx-formrow__help">' + th('InboxProtectLevelHelp_content') + '</span></span></label></fieldset>'
				+ '<p>' + th('InboxProtectDialogIntro') + '</p><ul class="ibx-protect__list">'
				+ ['InboxProtectSay1', 'InboxProtectSay3', 'InboxProtectSay4', 'InboxProtectSay5'].map(k => '<li>' + th(k) + '</li>').join('') + '</ul>'
				// Level content only: what is emptied now
				+ '<div class="ibx-notice ibx-notice--warning ibx-js-purgewarn" hidden>' + ic('warning') + '<div class="ibx-notice__text">' + th('InboxProtectPurgeWarning') + '</div></div>'
				+ '<div class="ibx-form">' + P.passwordFields(!!data.smtp_own, 'ibx-prot')
				+ '<div class="ibx-formrow"><label class="ibx-formrow__label" for="ibx-prot-confirm">' + th('InboxTypeToConfirm', account.email) + '</label><div><input id="ibx-prot-confirm" class="ibx-field" autocomplete="off" spellcheck="false"></div></div></div>',
			actions: [{ label: t('InboxProtectAction'), primary: true, run: async d => {
				const secrets = P.readPasswords(d, 'ibx-prot');
				if (!secrets.imap) {
					$('#ibx-prot-imap', d).focus();
					return false;
				}
				const confirm = $('#ibx-prot-confirm', d).value.trim();
				if (confirm.toLowerCase() !== String(account.email).toLowerCase()) {
					$('#ibx-prot-confirm', d).focus();
					return false;
				}
				const level = ($('input[name="ibx-prot-level"]:checked', d) || {}).value || 'password';
				await P.protect(account, secrets, confirm, level);
				toast(t('InboxProtectDone'));
				done();
			} }],
			onOpen: d => {
				$('#ibx-prot-imap', d).focus();
				$$('input[name="ibx-prot-level"]', d).forEach(r => r.addEventListener('change', () => {
					$('.ibx-js-purgewarn', d).hidden = ($('input[name="ibx-prot-level"]:checked', d) || {}).value !== 'content';
				}));
			},
		});
	}

	// ----- Tab Journal (lot W4 §4.2): who moved, archived, removed, tagged the mails; managers of the mailbox only -----
	const JOURNAL_ACTIONS = ['move', 'archive', 'delete', 'destroy', 'junk', 'notjunk', 'tag', 'assign', 'unassign', 'folder_create', 'folder_rename', 'folder_move', 'folder_delete', 'folder_destroy', 'empty', 'thread_ignore', 'thread_unignore', 'unsubscribe', 'import', 'export', 'attach', 'send', 'redirect', 'invite_reply', 'receipt', 'rules', 'away', 'resoc'];
	async function tabJournal(box, account) {
		const f = { user: 0, what: '', from: '', to: '', q: '' };
		let offset = 0;
		let lines = [];
		box.innerHTML = '<p class="ibx-section__help">' + th('InboxJournalHelp') + '</p>'
			+ '<div class="ibx-journal__filters" role="search">'
			+ '<label class="ibx-sr-only" for="j-q">' + th('InboxJournalSearch') + '</label><input type="search" class="ibx-field ibx-field--medium" id="j-q" autocomplete="off" placeholder="' + th('InboxJournalSearch') + '">'
			+ '<label class="ibx-sr-only" for="j-user">' + th('InboxJournalWho') + '</label><select class="ibx-field ibx-field--medium" id="j-user" data-combo><option value="0">' + th('InboxJournalEveryone') + '</option></select>'
			+ '<label class="ibx-sr-only" for="j-what">' + th('InboxJournalAction') + '</label><select class="ibx-field ibx-field--medium" id="j-what"><option value="">' + th('InboxJournalAllActions') + '</option>'
			+ JOURNAL_ACTIONS.map(a => '<option value="' + a + '">' + th('InboxJournal_' + a) + '</option>').join('') + '</select>'
			+ '<span class="ibx-inline"><label for="j-from">' + th('InboxJournalFrom') + '</label><input type="date" class="ibx-field" id="j-from"><label for="j-to">' + th('InboxJournalTo') + '</label><input type="date" class="ibx-field" id="j-to"></span>'
			+ '</div><div class="ibx-js-jlist" aria-live="polite"></div>';
		const list = $('.ibx-js-jlist', box);
		const day = v => v ? Math.floor(new Date(v + 'T00:00:00').getTime() / 1000) : 0;
		const paint = more => {
			if (!lines.length) {
				list.innerHTML = '<div class="ibx-empty">' + ic('info', 'ibx-empty__icon') + '<div>' + th(f.user || f.what || f.from || f.to || f.q ? 'InboxJournalNoMatch' : 'InboxActionsJournalEmpty') + '</div></div>';
				return;
			}
			const where = l => {
				if (l.action === 'tag') {
					return esc(l.from) + ' · <span class="ibx-mono">' + esc(l.detail) + '</span>';
				}
				if (l.action === 'assign' || l.action === 'unassign') {
					return esc(l.detail);
				}
				if (l.action === 'attach') {
					return esc(l.from) + ' · <span class="ibx-mono">' + esc(l.detail) + '</span>';
				}
				if (l.action === 'import') {
					return esc(l.to);
				}
				if (l.action === 'send') {
					// Folder of the copy, and the number of recipients (never their addresses)
					return esc(l.to) + ' · ' + th('InboxJournalRecipients', esc(l.detail));
				}
				if (l.action === 'export') {
					return esc(l.from) + ' · <span class="ibx-mono">' + esc(l.detail) + '</span>';
				}
				if (l.action === 'folder_create') {
					return esc(l.to);
				}
				if (l.action === 'unsubscribe') {
					return esc(l.from) + ' · <span class="ibx-mono">' + esc(l.detail) + '</span>';
				}
				if (l.action === 'thread_ignore' || l.action === 'thread_unignore') {
					return esc(l.from);
				}
				if (l.action === 'folder_destroy' || l.action === 'empty') {
					return esc(l.from);
				}
				if (l.action === 'destroy') {
					return esc(l.from);
				}
				return esc(l.from) + ' → ' + (l.account_to ? '<b>' + esc(l.account_to) + '</b> / ' : '') + esc(l.to);
			};
			list.innerHTML = '<table class="ibx-table ibx-journal"><thead><tr><th>' + th('InboxJournalDate') + '</th><th>' + th('InboxJournalWho') + '</th><th>' + th('InboxJournalAction') + '</th><th>' + th('InboxJournalMails') + '</th><th>' + th('InboxJournalWhere') + '</th></tr></thead><tbody>'
				+ lines.map(l => '<tr' + (l.undone ? ' class="is-undone"' : '') + '><td class="ibx-nowrap">' + esc(new Date(l.date * 1000).toLocaleString(I.locale())) + '</td><td>' + esc(l.user) + '</td>'
					+ '<td>' + th('InboxJournal_' + l.action) + (l.undone ? ' <span class="ibx-badge">' + th('InboxJournalUndone') + '</span>' : '') + (l.incoming ? ' <span class="ibx-badge">' + th('InboxJournalIncoming') + '</span>' : '') + '</td>'
					+ '<td>' + (l.nb > 1 ? '<b>' + th('InboxJournalNbMails', l.nb) + '</b>' + (l.subject ? '<div class="ibx-muted ibx-ellipsis">' + th('InboxJournalFirst', l.subject) + '</div>' : '')
						: '<span class="ibx-ellipsis" title="' + esc(l.message_id) + '">' + esc(l.subject || l.message_id || '') + '</span>') + '</td>'
					+ '<td>' + where(l) + '</td></tr>').join('')
				+ '</tbody></table>' + (more ? '<div class="ibx-formfoot"><button type="button" class="ibx-btn ibx-js-jmore">' + th('InboxJournalMore') + '</button></div>' : '');
			const btn = $('.ibx-js-jmore', list);
			if (btn) {
				btn.addEventListener('click', () => load(true));
			}
		};
		let seq = 0;
		const load = async append => {
			const mine = ++seq;
			offset = append ? offset + 50 : 0;
			if (!append) {
				list.innerHTML = skeleton(4);
			}
			let res;
			try {
				res = await api('action', 'journal', { id: account.id, user: f.user, what: f.what, from: day(f.from), to: f.to ? day(f.to) + 86400 : 0, q: f.q, offset }, { method: 'POST' });
			} catch (err) {
				if (mine === seq) {
					list.replaceChildren(errorBox(err, () => load(false)));
				}
				return;
			}
			if (mine !== seq) {
				return;
			}
			lines = append ? lines.concat(res.lines) : res.lines;
			const who = $('#j-user', box);
			if (who.options.length === 1) {
				res.authors.forEach(a => who.insertAdjacentHTML('beforeend', '<option value="' + a.id + '">' + esc(a.name) + '</option>'));
			}
			paint(res.more);
		};
		let timer = 0;
		$('#j-q', box).addEventListener('input', e => {
			clearTimeout(timer);
			timer = setTimeout(() => {
				f.q = e.target.value.trim();
				load(false);
			}, 300);
		});
		[['#j-user', 'user'], ['#j-what', 'what'], ['#j-from', 'from'], ['#j-to', 'to']].forEach(([sel, k]) => $(sel, box).addEventListener('change', e => {
			f[k] = k === 'user' ? +e.target.value : e.target.value;
			load(false);
		}));
		load(false);
	}

	// ----- Tab Server -----
	function field(name, label, value, o) {
		o = o || {};
		const id = 'f-' + name;
		let input;
		if (o.options) {
			input = '<select class="ibx-field' + (o.cls ? ' ' + o.cls : '') + '" id="' + id + '" name="' + name + '">' + o.options.map(([v, l]) => '<option value="' + esc(v) + '"' + (String(v) === String(value) ? ' selected' : '') + '>' + esc(l) + '</option>').join('') + '</select>';
		} else {
			input = '<input class="ibx-field' + (o.cls ? ' ' + o.cls : '') + '" id="' + id + '" name="' + name + '" type="' + (o.type || 'text') + '" value="' + esc(value === null || value === undefined ? '' : value) + '"'
				+ (o.placeholder ? ' placeholder="' + esc(o.placeholder) + '"' : '') + (o.autocomplete ? ' autocomplete="' + o.autocomplete + '"' : '') + (o.inputmode ? ' inputmode="' + o.inputmode + '"' : '')
				+ (o.required ? ' required' : '') + (o.min !== undefined ? ' min="' + o.min + '" max="' + o.max + '"' : '') + ' spellcheck="false">';
		}
		return '<div class="ibx-formrow" data-field="' + name + '"' + (o.hidden ? ' hidden' : '') + '><label class="ibx-formrow__label" for="' + id + '">' + esc(label) + (o.required ? ' <span class="ibx-req" aria-hidden="true">*</span>' : '') + '</label>'
			+ '<div>' + input + (o.help ? '<div class="ibx-formrow__help">' + esc(o.help) + '</div>' : '') + '<div class="ibx-formrow__error" hidden></div></div></div>';
	}
	function formValues(form) {
		const data = {};
		$$('[name]', form).forEach(f => {
			if (f.type === 'checkbox') {
				data[f.name] = f.checked ? 1 : 0;
			} else if (f.type === 'radio') {
				if (f.checked) {
					data[f.name] = f.value;
				}
			} else {
				data[f.name] = f.value;
			}
		});
		return data;
	}

	function tabServer(box, account, main) {
		const a = account || { type: 'shared', imap_port: 993, imap_encryption: 'ssl', imap_auth_mode: 'login', imap_validate_cert: 1, smtp_port: 465, smtp_encryption: 'ssl', smtp_same_credentials: 1, sieve_port: 4190, sync_max_days: 0, sync_max_mails: 0 };
		const enc = [['ssl', t('InboxEncSsl')], ['tls', t('InboxEncTls')], ['none', t('InboxEncNone')]];
		// Protected mailbox (1.89.0): no password here, the server and the login stay those of the encrypted copies
		const prot = !!(account && account.protected);
		// Why it cannot be protected now: login and password only, an active mailbox (its accesses count only then)
		// and who protects must open it himself: the names said (an admin of Dolibarr has no access of his own)
		const owner = a.summary && a.summary.owner ? a.summary.owner : '';
		const noAccess = account && !a.own_level ? (a.type === 'personal' && owner ? t('InboxProtectNotYoursPersonal', a.me || '', owner) : t('InboxProtectNotYoursShared', a.me || '')) : '';
		const protWhy = !account ? '' : (a.imap_auth_mode !== 'login' ? t('InboxProtectErrorAuthMode') : (a.status !== 1 ? t('InboxProtectNeedActive') : noAccess));
		const mailboxSection = '<section class="ibx-section"><h2 class="ibx-section__title">' + th('InboxSectionMailbox') + '</h2><div class="ibx-form">'
			+ field('email', t('InboxAccountEmail'), a.email, { type: 'email', required: true, autocomplete: 'off', placeholder: 'devis@example.com', cls: 'ibx-field--medium', help: account ? '' : t('InboxAutoconfigEmailHelp') })
			// New mailbox (lot H1): the servers found from the address, where they come from
			+ (account ? '' : '<div class="ibx-autoconf ibx-js-autoconf" aria-live="polite"></div>')
			+ field('label', t('InboxAccountLabel'), a.label, { help: t('InboxAccountLabelHelp'), cls: 'ibx-field--medium' })
			+ field('type', t('InboxAccountType'), a.type, { options: [['shared', t('InboxTypeShared')], ['personal', t('InboxTypePersonal')]], cls: 'ibx-field--medium' })
			+ '<div class="ibx-formrow" data-field="fk_user_owner"' + (a.type === 'personal' ? '' : ' hidden') + '><label class="ibx-formrow__label" for="f-owner-q">' + th('InboxAccountOwner') + ' <span class="ibx-req" aria-hidden="true">*</span></label><div>'
			+ '<select class="ibx-field ibx-field--medium" id="f-owner-q" name="fk_user_owner"><option value=""></option>'
			+ (+a.fk_user_owner ? '<option value="' + esc(a.fk_user_owner) + '" selected' + (a.owner_photo ? ' data-avatar-img="' + esc(a.owner_photo) + '"' : '')
				+ ' data-avatar-text="' + esc(initialsOf(a.owner_name || (account && account.summary ? account.summary.owner : ''))) + '" data-avatar-color="hsl(' + hueOf('u' + a.fk_user_owner) + ' 55% 90%)">'
				+ esc(a.owner_name || (account && account.summary ? account.summary.owner : '')) + '</option>' : '') + '</select>'
			+ '<div class="ibx-formrow__help">' + th('InboxAccountOwnerHelp') + '</div><div class="ibx-formrow__error" hidden></div></div></div>'
			+ '</div></section>';
		const imapHostRows = field('imap_host', t('InboxImapHost'), a.imap_host, { required: true, placeholder: 'mail.example.com', cls: 'ibx-field--medium', inputmode: 'url' })
			+ '<div class="ibx-formrow"><span class="ibx-formrow__label">' + th('InboxPortEncryption') + '</span><div class="ibx-inline">'
			+ '<input class="ibx-field ibx-field--narrow" name="imap_port" type="number" min="1" max="65535" inputmode="numeric" aria-label="' + th('InboxImapPort') + '" value="' + esc(a.imap_port) + '">'
			+ '<select class="ibx-field ibx-field--medium" name="imap_encryption" aria-label="' + th('InboxEncryption') + '">' + enc.map(([v, l]) => '<option value="' + v + '"' + (v === a.imap_encryption ? ' selected' : '') + '>' + esc(l) + '</option>').join('') + '</select></div></div>';
		const authRows = field('imap_auth_mode', t('InboxImapAuthMode'), a.imap_auth_mode, { options: [['login', t('InboxAuthLogin')], ['master', t('InboxAuthMaster')], ['oauth', t('InboxAuthOAuth')]], cls: 'ibx-field--medium' })
			+ field('oauth_service', t('InboxOAuthService'), a.oauth_service, { hidden: a.imap_auth_mode !== 'oauth', help: t('InboxOAuthServiceHelp'), cls: 'ibx-field--medium' })
			+ field('imap_login', t('InboxImapLogin'), a.imap_login, { placeholder: t('InboxLoginDefault'), autocomplete: 'off', cls: 'ibx-field--medium', hidden: a.imap_auth_mode === 'master' })
			+ (prot ? '<div class="ibx-formrow"><span class="ibx-formrow__label">' + th('InboxPassword') + '</span><div><div>' + ic('lock') + ' ' + th('InboxProtectPasswordOn') + '</div>'
				+ '<a class="ibx-btn ibx-btn--sm" href="#/settings/accounts/' + a.id + '/protection">' + ic('key') + ' ' + th('InboxProtectManage') + '</a></div></div>'
				: '<div class="ibx-formrow" data-field="imap_password"' + (a.imap_auth_mode === 'login' ? '' : ' hidden') + '><label class="ibx-formrow__label" for="f-imap_password">' + th('InboxPassword') + '</label><div>'
			+ '<div class="ibx-password"><input class="ibx-field ibx-field--medium" id="f-imap_password" name="imap_password" type="password" autocomplete="new-password" placeholder="' + (a.has_imap_password ? th('InboxPasswordKept') : '') + '"></div>'
			+ '<div class="ibx-formrow__help">' + th(a.has_imap_password ? 'InboxPasswordKeptHelp' : 'InboxPasswordHelp') + '</div><div class="ibx-formrow__error" hidden></div></div></div>');
		const certRow = '<div class="ibx-formrow"><span class="ibx-formrow__label">' + th('InboxCertificate') + '</span><div><label class="ibx-check"><input type="checkbox" name="imap_validate_cert"' + (a.imap_validate_cert ? ' checked' : '') + '> ' + th('InboxValidateCert') + '</label></div></div>';
		// Protection (1.89.0): what becomes of the password, beside it; the encrypted accesses in their own tab
		const protRows = (account && !prot ? '<div class="ibx-formrow" data-field="protection"><span class="ibx-formrow__label">' + th('InboxProtectRow') + '</span><div>'
				+ '<div class="ibx-formrow__help">' + th('InboxProtectNotOn') + '</div>'
				+ '<button type="button" class="ibx-btn ibx-btn--sm ibx-js-protect"' + (protWhy ? ' aria-disabled="true" title="' + esc(protWhy) + '"' : '') + '>' + ic('lock') + ' ' + th('InboxProtectAction') + '</button>'
				+ (protWhy ? '<div class="ibx-formrow__help">' + esc(protWhy) + '</div>' : '') + '</div></div>' : '')
			+ (prot ? '<p class="ibx-section__help">' + ic('lock') + ' ' + th('InboxProtectServerReadonly') + '</p>' : '');
		const smtpSection = '<section class="ibx-section"><h2 class="ibx-section__title">' + th('InboxSectionSmtp') + '</h2><div class="ibx-form">'
			+ field('smtp_host', t('InboxSmtpHost'), a.smtp_host, { placeholder: t('InboxSameAsImap'), cls: 'ibx-field--medium', inputmode: 'url' })
			+ '<div class="ibx-formrow"><span class="ibx-formrow__label">' + th('InboxPortEncryption') + '</span><div class="ibx-inline">'
			+ '<input class="ibx-field ibx-field--narrow" name="smtp_port" type="number" min="1" max="65535" inputmode="numeric" aria-label="' + th('InboxSmtpPort') + '" value="' + esc(a.smtp_port) + '">'
			+ '<select class="ibx-field ibx-field--medium" name="smtp_encryption" aria-label="' + th('InboxEncryption') + '">' + enc.map(([v, l]) => '<option value="' + v + '"' + (v === a.smtp_encryption ? ' selected' : '') + '>' + esc(l) + '</option>').join('') + '</select></div></div>'
			+ '<div class="ibx-formrow"><span class="ibx-formrow__label">' + th('InboxSmtpCredentials') + '</span><div><label class="ibx-check"><input type="checkbox" name="smtp_same_credentials"' + (a.smtp_same_credentials ? ' checked' : '') + '> ' + th('InboxSmtpSameCredentials') + '</label></div></div>'
			+ field('smtp_login', t('InboxSmtpLogin'), a.smtp_login, { hidden: !!a.smtp_same_credentials, autocomplete: 'off', placeholder: t('InboxLoginDefault'), cls: 'ibx-field--medium' })
			+ '<div class="ibx-formrow" data-field="smtp_password"' + (a.smtp_same_credentials ? ' hidden' : '') + '><label class="ibx-formrow__label" for="f-smtp_password">' + th('InboxPassword') + '</label><div><input class="ibx-field ibx-field--medium" id="f-smtp_password" name="smtp_password" type="password" autocomplete="new-password" placeholder="' + (a.has_smtp_password ? th('InboxPasswordKept') : '') + '"></div></div>'
			// Copy of the mails sent (1.26.0): some sending servers file it themselves
			+ field('sent_copy', t('InboxSentCopy'), (a.connector_config && a.connector_config.sent_copy) || 'auto', { options: [['auto', t('InboxSentCopy_auto')], ['inbox', t('InboxSentCopy_inbox')], ['server', t('InboxSentCopy_server')]], help: t('InboxSentCopyHelp'), cls: 'ibx-field--medium' })
			+ '</div></section>';
		const moreSection = '<section class="ibx-section"><h2 class="ibx-section__title">' + th('InboxSectionMore') + '</h2><div class="ibx-form">'
			+ field('sieve_port', t('InboxSievePort'), a.sieve_port, { type: 'number', min: 0, max: 65535, inputmode: 'numeric', help: t('InboxSievePortHelp'), cls: 'ibx-field--narrow' })
			+ field('sync_max_days', t('InboxSyncMaxDays'), a.sync_max_days, { type: 'number', min: 0, max: 36500, inputmode: 'numeric', help: t('InboxSyncLimitsHelp'), cls: 'ibx-field--narrow' })
			+ field('sync_max_mails', t('InboxSyncMaxMails'), a.sync_max_mails, { type: 'number', min: 0, max: 1000000, inputmode: 'numeric', cls: 'ibx-field--narrow' })
			// Never the AI for this mailbox (1.27.0)
			+ '<div class="ibx-formrow"><span class="ibx-formrow__label">' + th('InboxAiSection') + '</span><div><label class="ibx-check"><input type="checkbox" name="ai_off"' + (a.connector_config && a.connector_config.ai_off ? ' checked' : '') + '> ' + th('InboxAiOff') + '</label>'
			+ '<div class="ibx-formrow__help">' + th('InboxAiOffHelp') + '</div></div></div>'
			+ '<div class="ibx-formrow"><span class="ibx-formrow__label">' + th('InboxDictationSection') + '</span><div><label class="ibx-check"><input type="checkbox" name="dictation_off"' + (a.connector_config && a.connector_config.dictation_off ? ' checked' : '') + '> ' + th('InboxDictationOff') + '</label>'
			+ '<div class="ibx-formrow__help">' + th('InboxDictationOffHelp') + '</div></div></div>'
			+ '</div></section>';
		const imapTitle = '<section class="ibx-section"><h2 class="ibx-section__title">' + th('InboxSectionImap') + '</h2><div class="ibx-form">';
		let html = '<form class="ibx-form" novalidate autocomplete="off">' + mailboxSection
			+ (account ? imapTitle + imapHostRows + authRows + certRow + protRows + '</div></section>' + smtpSection + moreSection
				// New mailbox (lot H1): one field to start, the login beside it, the servers folded and filled from the address
				: '<section class="ibx-section"><h2 class="ibx-section__title">' + th('InboxSectionLogin') + '</h2><div class="ibx-form">' + authRows + '</div></section>'
				+ '<details class="ibx-servers ibx-js-servers"><summary><span class="ibx-servers__title">' + th('InboxAutoconfigServers') + '</span> <span class="ibx-servers__sum ibx-js-serverssum">' + th('InboxAutoconfigServersEmpty') + '</span></summary>'
				+ imapTitle + imapHostRows + certRow + '</div></section>' + smtpSection + moreSection + '</details>')
			+ '<div class="ibx-js-testresult"></div>'
			+ '<div class="ibx-formfoot"><button type="button" class="ibx-btn ibx-js-test">' + ic('check') + ' ' + th('InboxTestConnection') + '</button>'
			+ '<span class="ibx-formfoot__spacer"></span>'
			+ (account ? '<span class="ibx-js-statusactions">' + statusActions(account) + '</span>' : '')
			+ '<button type="submit" class="ibx-btn ibx-btn--primary">' + th(account ? 'InboxSave' : 'InboxCreate') + '</button></div>'
			+ '</form>';
		box.innerHTML = html;
		I.mount(box);
		const form = $('form', box);
		if (prot) {
			['imap_host', 'imap_port', 'imap_encryption', 'imap_auth_mode', 'oauth_service', 'imap_login', 'imap_validate_cert', 'smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_same_credentials', 'smtp_login', 'smtp_password'].forEach(n => {
				const el = form.elements[n];
				if (el) {
					el.disabled = true;
					el.title = t('InboxProtectServerReadonly');
				}
			});
		}
		const protBtn = $('.ibx-js-protect', form);
		if (protBtn) {
			protBtn.addEventListener('click', async () => {
				if (protBtn.getAttribute('aria-disabled') === 'true') {
					return;
				}
				let data;
				try {
					data = await api('protect', 'accesses', { id: account.id });
				} catch (err) {
					toast(err.message, { error: true });
					return;
				}
				const why = !I.protect.supported() ? t('InboxProtectUnsupported') : (data.refusal || (!data.own_access ? t('InboxProtectErrorOwnAccess') : ''));
				if (why) {
					toast(why, { error: true });
					return;
				}
				protectDialog(account, data, () => {
					card = null;
					I.changed('account', account.id);
					I.go('#/settings/accounts/' + account.id + '/protection');
				});
			});
		}
		// Fields that depend on others
		const sync = () => {
			const v = formValues(form);
			$('[data-field="fk_user_owner"]', form).hidden = v.type !== 'personal';
			$('[data-field="oauth_service"]', form).hidden = v.imap_auth_mode !== 'oauth';
			$('[data-field="imap_login"]', form).hidden = v.imap_auth_mode === 'master';
			$('[data-field="imap_password"]', form).hidden = v.imap_auth_mode !== 'login';
			$('[data-field="smtp_login"]', form).hidden = !!v.smtp_same_credentials;
			$('[data-field="smtp_password"]', form).hidden = !!v.smtp_same_credentials;
		};
		// A password kept is only used for the same server (audit INBX-002): a change of server, port, encryption,
		// login or mode asks it again, said before the test or the save
		const secretFields = { imap: ['imap_host', 'imap_port', 'imap_encryption', 'imap_auth_mode', 'oauth_service', 'imap_login', 'imap_validate_cert'],
			smtp: ['smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_login', 'smtp_same_credentials', 'imap_validate_cert'] };
		const initial = account ? formValues(form) : null;
		const askAgain = () => {
			if (!initial) {
				return;
			}
			const v = formValues(form);
			const moved = list => list.some(k => String(v[k] ?? '') !== String(initial[k] ?? ''));
			const sides = [['imap', a.has_imap_password, moved(secretFields.imap) || (!!v.smtp_same_credentials && moved(secretFields.smtp))],
				['smtp', a.has_smtp_password, !v.smtp_same_credentials && moved(secretFields.smtp)]];
			// What changed, in the words of the form: the explanation names it
			const names = { imap_host: 'InboxImapHost', imap_port: 'InboxImapPort', imap_encryption: 'InboxEncryption', imap_auth_mode: 'InboxImapAuthMode',
				oauth_service: 'InboxOAuthService', imap_login: 'InboxImapLogin', imap_validate_cert: 'InboxValidateCert', smtp_host: 'InboxSmtpHost',
				smtp_port: 'InboxSmtpPort', smtp_encryption: 'InboxEncryption', smtp_login: 'InboxSmtpLogin', smtp_same_credentials: 'InboxSmtpSameCredentials' };
			const changedOf = list => Array.from(new Set(list.filter(k => String(v[k] ?? '') !== String(initial[k] ?? '')).map(k => t(names[k]).toLowerCase())));
			sides.forEach(([side, kept, again]) => {
				const input = form[side + '_password'];
				if (!input || !kept) {
					return;
				}
				input.placeholder = again ? t('InboxPasswordAgain') : t('InboxPasswordKept');
				input.required = again;
				const row = input.closest('.ibx-formrow');
				row.classList.toggle('is-invalid', again && input.value === '');
				// Why the password is asked again: shown on an info icon beside the label (a reflex of security, not a bug)
				const label = $('.ibx-formrow__label', row);
				const tip = $('.ibx-infotip', label);
				if (tip) {
					tip.remove();
				}
				if (again) {
					const fields = changedOf(side === 'imap' ? secretFields.imap.concat(v.smtp_same_credentials ? secretFields.smtp : []) : secretFields.smtp);
					label.insertAdjacentHTML('beforeend', ' ' + I.infotip(t('InboxPasswordWhyTitle'), t('InboxPasswordWhyText', fields.join(', '))));
				}
			});
		};
		form.addEventListener('input', askAgain);
		form.addEventListener('change', e => {
			sync();
			askAgain();
			// Usual port of the encryption chosen, only when the port was the usual one of the other choice
			if (e.target.name === 'imap_encryption') {
				const p = form.imap_port;
				if (['993', '143'].includes(p.value)) {
					p.value = e.target.value === 'ssl' ? '993' : '143';
				}
			}
			if (e.target.name === 'smtp_encryption') {
				const p = form.smtp_port;
				if (['465', '587', '25'].includes(p.value)) {
					p.value = e.target.value === 'ssl' ? '465' : '587';
				}
			}
		});
		ownerPicker(form);
		autoconfigBind(form, sync);
		const showErrors = err => {
			$$('.ibx-formrow.is-invalid', form).forEach(r => r.classList.remove('is-invalid'));
			// New mailbox: a field to fix among the folded servers opens them
			const folded = $('.ibx-js-servers', form);
			if (folded && err.fields && Object.keys(err.fields).some(k => folded.querySelector('[name="' + k + '"]'))) {
				folded.open = true;
			}
			toast(err.message, { error: true });
		};
		form.addEventListener('submit', async e => {
			e.preventDefault();
			const values = formValues(form);
			if (account) {
				values.id = account.id;
			}
			try {
				const r = await post('account', 'save', values);
				I.changed('account', r.account.id);
				toast(t(account ? 'InboxSaved' : 'InboxAccountCreated'));
				if (!account) {
					I.go('#/settings/accounts/' + r.account.id + '/server');
				} else {
					card = null;
					renderAccountCard(main, account.id, 'server');
				}
			} catch (err) {
				showErrors(err);
			}
		});
		$('.ibx-js-test', form).addEventListener('click', async e => {
			const btn = e.currentTarget;
			const out = $('.ibx-js-testresult', form);
			btn.setAttribute('aria-disabled', 'true');
			out.innerHTML = '<div class="ibx-notice ibx-notice--info" role="status">' + ic('info') + '<div class="ibx-notice__text">' + th('InboxTesting') + '</div></div>';
			// The result in a window at once (1.89.0), wherever the button is: the steps to come, then their result; it
			// can be closed during the test (the result stays under the form)
			const steps = ['imap', 'capabilities', 'folders', 'smtp', 'sieve'];
			const win = dialog({
				title: t('InboxTestConnection'),
				wide: true,
				body: '<div class="ibx-js-testwin" aria-live="polite"><ul class="ibx-checklist">' + steps.map(st => '<li class="ibx-checklist__item ibx-checklist__item--pending">'
					+ '<span class="ibx-spinner" aria-hidden="true"></span><div><div class="ibx-checklist__step">' + th('InboxTestStep_' + st) + '</div><div class="ibx-muted">' + th('InboxTesting') + '</div></div></li>').join('') + '</ul></div>',
			});
			const paintWin = html => {
				const zone = win.isConnected && $('.ibx-js-testwin', win);
				if (zone) {
					zone.innerHTML = html;
				}
			};
			const values = formValues(form);
			if (account) {
				values.id = account.id;
				// The result is kept on the mailbox only when the form has no unsaved change
				values.save = form.dataset.dirty ? 0 : 1;
			}
			try {
				const r = await post('account', 'test', values);
				out.innerHTML = checklist(r.test);
				paintWin(checklist(r.test));
				if (account && values.save) {
					I.changed('account', account.id);
					// Tested (1.89.0): the switch of the head can be used at once, its hint goes
					if (['ok', 'warning'].includes(r.test.status)) {
						account.last_test_result = r.test.status;
						const sw = $('.ibx-js-statusswitch', main);
						if (sw) {
							sw.removeAttribute('aria-disabled');
							sw.removeAttribute('title');
						}
						const hint = $('.ibx-js-statustest', main);
						if (hint) {
							hint.remove();
						}
					}
				}
			} catch (err) {
				out.innerHTML = '';
				// A field to fix: the window goes, the form says which one; any other error is said in the window
				if (err.fields && Object.keys(err.fields).length) {
					if (win.isConnected) {
						win.close();
					}
				} else {
					paintWin('<div class="ibx-notice ibx-notice--error" role="alert">' + ic('warning') + '<div class="ibx-notice__text">' + esc(err.message) + '</div></div>');
				}
				showErrors(err);
			}
			btn.removeAttribute('aria-disabled');
		});
		form.addEventListener('input', () => {
			form.dataset.dirty = '1';
		});
		bindStatusActions(form, account, main);
		if (account && testAsked === account.id) {
			testAsked = 0;
			const run = $('.ibx-js-test', form);
			run.scrollIntoView({ block: 'center' });
			run.click();
		}
	}

	/**
	 * New mailbox (lot H1): the servers looked for by the server of Dolibarr from the address typed (a mailbox of the
	 * same domain, file published by the domain, Autodiscover, known providers, ISPDB of Thunderbird, DNS, usual
	 * names), put in the folded fields with where they come from. Servers changed by hand are never replaced: the
	 * result is then offered. The connection test checks what was found.
	 */
	function autoconfigBind(form, sync) {
		const zone = $('.ibx-js-autoconf', form);
		const servers = $('.ibx-js-servers', form);
		if (!zone || !servers) {
			return;
		}
		const sum = $('.ibx-js-serverssum', form);
		const FIELDS = ['imap_host', 'imap_port', 'imap_encryption', 'imap_auth_mode', 'oauth_service', 'imap_login', 'smtp_host', 'smtp_port', 'smtp_encryption'];
		const EMAIL = /^[^@\s]+@[^@\s]+\.[a-z]{2,}$/i;
		let touched = false;
		let filling = false;
		// The servers on screen were put by a previous search (not typed): cleared when another address finds nothing
		let filled = false;
		let searched = '';
		let seq = 0;
		let timer = 0;
		let result = null;
		const paintSum = () => {
			const v = formValues(form);
			sum.textContent = v.imap_host ? t('InboxAutoconfigServersSum', v.imap_host + ':' + v.imap_port, (v.smtp_host || v.imap_host) + ':' + v.smtp_port) : t('InboxAutoconfigServersEmpty');
		};
		const notice = (kind, icon, body) => '<div class="ibx-notice ibx-notice--' + kind + '"' + (kind === 'error' ? ' role="alert"' : '') + '>' + icon + '<div class="ibx-notice__text">' + body + '</div></div>';
		const apply = r => {
			filling = true;
			const set = (name, value) => {
				const el = form.elements[name];
				if (el && value !== undefined && value !== null) {
					el.value = String(value);
				}
			};
			const email = form.elements.email.value.trim();
			set('imap_host', r.imap.host);
			set('imap_port', r.imap.port);
			set('imap_encryption', r.imap.encryption);
			set('smtp_host', r.smtp ? r.smtp.host : '');
			set('smtp_port', r.smtp ? r.smtp.port : (r.imap.encryption === 'ssl' ? 465 : 587));
			set('smtp_encryption', r.smtp ? r.smtp.encryption : r.imap.encryption);
			set('imap_auth_mode', r.auth_mode || 'login');
			set('oauth_service', r.oauth_service || '');
			set('imap_login', r.login === 'localpart' ? email.slice(0, email.indexOf('@')) : '');
			sync();
			filling = false;
			touched = false;
			filled = true;
			paintSum();
		};
		const clear = () => {
			filling = true;
			['imap_host', 'smtp_host', 'oauth_service', 'imap_login'].forEach(n => {
				form.elements[n].value = '';
			});
			form.elements.imap_port.value = '993';
			form.elements.imap_encryption.value = 'ssl';
			form.elements.smtp_port.value = '465';
			form.elements.smtp_encryption.value = 'ssl';
			form.elements.imap_auth_mode.value = 'login';
			sync();
			filling = false;
			filled = false;
			paintSum();
		};
		const tried = r => !r.tried || !r.tried.length ? '' : '<details class="ibx-autoconf__tried"><summary>' + th('InboxAutoconfigTried') + '</summary><ul>'
			+ r.tried.map(x => '<li><span class="ibx-autoconf__src">' + th('InboxAutoconfigTriedSource_' + x.source) + '</span> <span class="ibx-muted">' + esc(x.detail) + '</span> '
				+ '<span class="ibx-status' + (x.result === 'found' ? ' ibx-status--ok' : '') + '">' + th('InboxAutoconfigTried_' + x.result) + '</span></li>').join('') + '</ul></details>';
		const ispdbOffer = (r, domain) => !r.ispdb_offer ? '' : '<div class="ibx-autoconf__ispdb"><button type="button" class="ibx-btn ibx-btn--sm ibx-js-ispdb">' + ic('search') + ' ' + th('InboxAutoconfigIspdbAsk') + '</button>'
			+ '<div class="ibx-formrow__help">' + th('InboxAutoconfigIspdbHelp', domain) + '</div></div>';
		const paint = (r, email) => {
			const domain = email.slice(email.indexOf('@') + 1);
			if (!r.found) {
				zone.innerHTML = notice('info', ic('info'), '<p>' + th('InboxAutoconfigNone', domain) + '</p>' + ispdbOffer(r, domain) + tried(r));
				servers.open = true;
				return;
			}
			const label = r.provider_label || '';
			const detail = r.source === 'provider' ? label : (r.source === 'guess' ? r.imap.host : r.detail);
			const lines = ['<b>' + th('InboxAutoconfigFound', r.imap.host + ':' + r.imap.port + (r.smtp ? ' · ' + r.smtp.host + ':' + r.smtp.port : '')) + '</b>',
				th('InboxAutoconfigFoundVia', t('InboxAutoconfigSource_' + r.source, detail)) + ' ' + th('InboxAutoconfigCheck')];
			let kind = 'info';
			if (r.source === 'guess') {
				lines.push(th('InboxAutoconfigGuessWarn'));
				kind = 'warning';
			}
			if (r.insecure) {
				lines.push(th('InboxAutoconfigInsecure'));
				kind = 'warning';
			}
			(r.notes || []).forEach(n => {
				if (n === 'oauth') {
					const s = r.oauth_services.find(x => x.key === r.oauth_service);
					lines.push(th('InboxAutoconfigOAuth', label, s ? s.label : r.oauth_service));
				} else if (n === 'oauth_needed') {
					lines.push(th('InboxAutoconfigOAuthNeeded', label));
					kind = 'warning';
				} else if (n === 'oauth_offered') {
					lines.push(th('InboxAutoconfigOAuthOffered', label));
				}
			});
			if (touched) {
				lines.push(th('InboxAutoconfigKept'));
			}
			zone.innerHTML = notice(kind, ic(kind === 'warning' ? 'warning' : 'info'), lines.map(l => '<p>' + l + '</p>').join('')
				+ '<div class="ibx-autoconf__actions">' + (touched ? '<button type="button" class="ibx-btn ibx-btn--sm ibx-js-use">' + th('InboxAutoconfigUse') + '</button>' : '')
				+ '<button type="button" class="ibx-btn ibx-btn--sm ibx-btn--ghost ibx-js-show">' + th('InboxAutoconfigShow') + '</button></div>'
				+ ispdbOffer(r, domain) + tried(r));
		};
		const search = async ispdb => {
			const email = form.elements.email.value.trim().toLowerCase();
			if (!EMAIL.test(email) || (email === searched && !ispdb)) {
				return;
			}
			searched = email;
			const mine = ++seq;
			zone.innerHTML = '<div class="ibx-notice ibx-notice--info" role="status"><span class="ibx-spinner" aria-hidden="true"></span><div class="ibx-notice__text">' + th('InboxAutoconfigSearching', email.slice(email.indexOf('@') + 1)) + '</div></div>';
			try {
				const r = (await post('account', 'discover', { email, ispdb: ispdb ? 1 : 0 })).discover;
				if (mine !== seq) {
					return;
				}
				result = r;
				if (r.found && !touched) {
					apply(r);
				} else if (!r.found && filled && !touched) {
					clear();
				}
				paint(r, email);
			} catch (err) {
				if (mine !== seq) {
					return;
				}
				searched = '';
				zone.innerHTML = notice('error', ic('warning'), th('InboxAutoconfigError', err.message));
				servers.open = true;
			}
		};
		const onEdit = e => {
			if (!filling && FIELDS.includes(e.target.name)) {
				touched = true;
			}
			paintSum();
		};
		form.addEventListener('input', e => {
			onEdit(e);
			if (e.target.name === 'email') {
				clearTimeout(timer);
				timer = setTimeout(() => search(false), 1000);
			}
		});
		form.addEventListener('change', e => {
			onEdit(e);
			if (e.target.name === 'email') {
				clearTimeout(timer);
				search(false);
			}
		});
		zone.addEventListener('click', e => {
			if (e.target.closest('.ibx-js-ispdb')) {
				search(true);
			} else if (e.target.closest('.ibx-js-use') && result && result.found) {
				apply(result);
				paint(result, searched);
			} else if (e.target.closest('.ibx-js-show')) {
				servers.open = true;
				servers.scrollIntoView({ block: 'start', behavior: 'smooth' });
			}
		});
		paintSum();
	}

	function ownerPicker(form) {
		// Same picker as the accesses (UX fix series), users only; the value is the id of the user
		whoPicker($('#f-owner-q', form), { groups: false, ids: true, placeholder: t('InboxSearchUser') });
	}

	function checklist(test) {
		const icon = { ok: 'check', warning: 'warning', error: 'close' };
		return '<section class="ibx-section"><h2 class="ibx-section__title">' + th('InboxTestResultTitle') + ' <span class="ibx-status ibx-status--' + esc(test.status) + '">' + th('InboxTestResult_' + test.status) + '</span> <span class="ibx-checklist__time">' + esc(test.duration) + ' s</span></h2>'
			+ '<ul class="ibx-checklist">' + test.steps.map(s => '<li class="ibx-checklist__item ibx-checklist__item--' + esc(s.status) + '">' + ic(icon[s.status] || 'info')
				+ '<div><div class="ibx-checklist__step">' + th('InboxTestStep_' + s.step) + '</div><div>' + esc(s.message) + '</div></div>'
				+ '<span class="ibx-checklist__time">' + esc(s.duration) + ' s</span></li>').join('') + '</ul></section>';
	}

	/**
	 * Switch Active / Disabled of the header of the card (admins of the mailboxes); others see the status only.
	 * Enabling needs a successful connection test (greyed with the reason); the change is shown in place, no message.
	 */
	function statusSwitch(account) {
		if (!isAdmin()) {
			return statusBadge(account.status);
		}
		const active = account.status === 1;
		const canEnable = active || ['ok', 'warning'].includes(account.last_test_result);
		const label = th(active ? 'InboxAccountStatusActive' : (account.status === 9 ? 'InboxAccountStatusDisabled' : 'InboxAccountStatusDraft'));
		// Not testable yet (1.89.0): why, and the way out, beside the switch (not only after a click on it)
		return '<span class="ibx-inline"><span class="ibx-js-statuslabel">' + label + '</span>'
			+ (canEnable ? '' : '<button type="button" class="ibx-btn ibx-btn--sm ibx-js-statustest" title="' + th('InboxErrorEnableNeedsTest') + '">' + ic('check') + ' ' + th('InboxTestConnection') + '</button>')
			+ '<button type="button" class="ibx-switch ibx-js-statusswitch" role="switch" aria-checked="' + (active ? 'true' : 'false') + '" aria-label="' + th('InboxAccountStatusActive') + '"'
			+ (canEnable ? '' : ' aria-disabled="true" title="' + th('InboxErrorEnableNeedsTest') + '"') + '></button></span>';
	}
	function bindStatusSwitch(main, account, tab) {
		const sw = $('.ibx-js-statusswitch', main);
		if (!sw) {
			return;
		}
		const setStatus = async status => {
			await post('account', 'status', { id: account.id, status });
			I.changed('account', account.id);
		};
		// Shown in place, at once: no reload of the card (it would flash), back to the old state on an error only
		const show = status => {
			account.status = status;
			sw.setAttribute('aria-checked', status === 1 ? 'true' : 'false');
			const label = $('.ibx-js-statuslabel', main);
			if (label) {
				label.textContent = t(status === 1 ? 'InboxAccountStatusActive' : (status === 9 ? 'InboxAccountStatusDisabled' : 'InboxAccountStatusDraft'));
			}
			const actions = $('.ibx-js-statusactions', main);
			if (actions) {
				actions.innerHTML = statusActions(account);
				bindStatusActions(actions, account);
			}
			// Row Protection of the Server tab (1.89.0): possible once the mailbox is active
			const protRow = $('[data-field="protection"]', main);
			const protBtn = protRow && $('.ibx-js-protect', protRow);
			if (protBtn && account.imap_auth_mode === 'login') {
				const why = status === 1 ? '' : t('InboxProtectNeedActive');
				if (why) {
					protBtn.setAttribute('aria-disabled', 'true');
				} else {
					protBtn.removeAttribute('aria-disabled');
				}
				protBtn.title = why;
				const helps = $$('.ibx-formrow__help', protRow);
				if (helps[1]) {
					helps[1].hidden = !why;
				} else if (why) {
					protBtn.insertAdjacentHTML('afterend', '<div class="ibx-formrow__help">' + esc(why) + '</div>');
				}
			}
		};
		// « Test the connection » beside the switch: the test of the form Server, on its section
		const asked = $('.ibx-js-statustest', main);
		if (asked) {
			asked.addEventListener('click', () => {
				const run = $('.ibx-js-test', main);
				if (run) {
					run.scrollIntoView({ block: 'center' });
					run.click();
				} else {
					testAsked = account.id;
					I.go('#/settings/accounts/' + account.id + '/server');
				}
			});
		}
		let busy = false;
		sw.addEventListener('click', async () => {
			if (sw.getAttribute('aria-disabled') === 'true') {
				toast(sw.title, { error: true });
				return;
			}
			if (busy) {
				return;
			}
			busy = true;
			const before = account.status;
			const status = sw.getAttribute('aria-checked') === 'true' ? 9 : 1;
			show(status);
			try {
				// The switch shows the new state by itself: no message (John 2026-11-06), only the errors
				await setStatus(status);
			} catch (err) {
				show(before);
				toast(err.message, { error: true });
			}
			busy = false;
		});
	}
	function statusActions(account) {
		if (!isAdmin()) {
			return '';
		}
		// Always shown (1.89.11): greyed with the reason while the mailbox is not disabled, else nobody finds it
		const why = account.status === 9 ? '' : th(account.status === 1 ? 'InboxDeleteNeedsDisable' : 'InboxErrorDeleteNotDisabled');
		return '<button type="button" class="ibx-btn ibx-btn--danger ibx-js-delete"' + (why ? ' aria-disabled="true" title="' + why + '"' : '') + '>' + ic('delete') + ' ' + th('InboxDeleteAccount') + '</button>';
	}
	function bindStatusActions(form, account) {
		if (!account) {
			return;
		}
		const on = (sel, fn) => {
			const b = $(sel, form);
			if (b) {
				b.addEventListener('click', fn);
			}
		};
		on('.ibx-js-delete', async e => {
			if (e.currentTarget.getAttribute('aria-disabled') === 'true') {
				toast(e.currentTarget.title, { error: true });
				return;
			}
			const ok = await confirmDialog({ title: t('InboxDeleteTitle', account.email), text: t('InboxDeleteText'), confirm: t('InboxDeleteAccount'), danger: true, typeToConfirm: account.email });
			if (!ok) {
				return;
			}
			try {
				await post('account', 'delete', { id: account.id, confirm_email: account.email });
				I.changed('account', account.id);
				toast(t('InboxAccountDeleted', account.email));
				I.go('#/settings/accounts');
			} catch (err) {
				toast(err.message, { error: true });
			}
		});
	}

	// ----- Tab Folders -----
	async function tabFolders(box, account) {
		box.innerHTML = '<div class="ibx-notice ibx-notice--info">' + ic('info') + '<div class="ibx-notice__text">' + th('InboxFoldersLoading') + '</div></div>';
		let data;
		try {
			data = await api('account', 'folders', { id: account.id });
		} catch (err) {
			box.innerHTML = '<div class="ibx-notice ibx-notice--error" role="alert">' + ic('warning') + '<div class="ibx-notice__text">' + esc(err.message) + '</div></div>';
			return;
		}
		const selectable = data.folders.filter(f => f.selectable);
		const roles = ['sent', 'drafts', 'trash', 'junk', 'archive'];
		const roleKey = r => 'InboxFolderRole' + r.charAt(0).toUpperCase() + r.slice(1);
		let html = '<p class="ibx-section__help">' + th('InboxFoldersHelp') + '</p><form class="ibx-form">';
		// The folder holding each role now (chosen by hand, or found): what the select shows, what « changed » means
		const chosen = data.chosen || [];
		const holder = r => (chosen.includes(r) ? data.roles[r] : ((data.folders.find(f => f.role === r) || {}).path || data.roles[r] || ''));
		const initial = {};
		roles.forEach(r => {
			const current = holder(r);
			initial[r] = current;
			html += '<div class="ibx-formrow"><label class="ibx-formrow__label" for="f-role-' + r + '">' + th(roleKey(r)) + '</label><div><select class="ibx-field ibx-field--medium" data-combo id="f-role-' + r + '" name="role_' + r + '">'
				+ '<option value="">' + th('InboxFolderNone') + '</option>'
				+ selectable.map(f => '<option value="' + esc(f.path) + '"' + (f.path === current ? ' selected' : '') + ' title="' + esc(f.path) + '">' + esc(f.label || f.name) + (f.role === r ? ' (' + th('InboxFolderFound') + ')' : '') + '</option>').join('')
				+ '</select>' + (current ? '' : '<div class="ibx-formrow__help">' + th('InboxFolderMissing') + '</div>') + '</div></div>';
		});
		html += '<div class="ibx-formfoot"><span class="ibx-muted">' + th('InboxFoldersCount', data.folders.length) + '</span><span class="ibx-formfoot__spacer"></span><button type="submit" class="ibx-btn ibx-btn--primary">' + th('InboxSave') + '</button></div></form>';
		// Trash and junk emptied after some days (1.41.0): removed for good on the server, a choice made here only
		const ret = data.retention || { trash: 0, junk: 0, choices: [0] };
		const delay = (name, r) => '<div class="ibx-formrow"><label class="ibx-formrow__label" for="f-ret-' + name + '">' + th(roleKey(r)) + '</label><div><select class="ibx-field ibx-field--medium" id="f-ret-' + name + '" name="' + name + '">'
			+ ret.choices.map(d => '<option value="' + d + '"' + (d === ret[name] ? ' selected' : '') + '>' + (d ? th('InboxRetentionDays', d) : th('InboxRetentionNever')) + '</option>').join('') + '</select></div></div>';
		html += '<h3 class="ibx-section__title ibx-twins__title">' + ic('delete') + ' ' + th('InboxRetentionTitle') + '</h3><p class="ibx-section__help">' + th('InboxRetentionHelp') + '</p>'
			+ '<form class="ibx-form ibx-js-retention">' + delay('trash', 'trash') + delay('junk', 'junk')
			+ '<div class="ibx-formfoot"><span class="ibx-formfoot__spacer"></span><button type="submit" class="ibx-btn">' + th('InboxSave') + '</button></div></form>';
		// Duplicates of a special folder (1.38.0): one folder per role, the others ordinary; the administrator chooses
		const kept = data.kept || [];
		const twins = data.folders.filter(f => f.twin && !kept.includes(f.path));
		const mainOf = role => data.folders.find(f => f.role === role);
		if (twins.length) {
			html += '<h3 class="ibx-section__title ibx-twins__title">' + ic('warning') + ' ' + th('InboxTwinsTitle') + '</h3><p class="ibx-section__help">' + th('InboxTwinsHelp') + '</p>'
				+ '<table class="ibx-table ibx-twins"><tbody>' + twins.map(f => {
					const main = mainOf(f.twin);
					return '<tr data-path="' + esc(f.path) + '"><td><b>' + esc(f.label || f.name) + '</b><div class="ibx-muted">' + esc(t('InboxTwinOf', t(roleKey(f.twin)), main ? (main.label || main.name) : '')) + '</div></td>'
						+ '<td class="ibx-muted">' + (f.total === null || f.total === undefined ? '' : th('InboxTwinMails', f.total)) + '</td>'
						+ '<td class="ibx-twins__actions"><button type="button" class="ibx-btn ibx-btn--sm ibx-js-twin" data-do="keep">' + th('InboxTwinKeep') + '</button>'
						+ '<button type="button" class="ibx-btn ibx-btn--sm ibx-js-twin" data-do="main">' + th('InboxTwinMain') + '</button>'
						// A folder the server marks for this role is its own: never merged away (removed), made main instead
						+ (main && !(f.flags || []).includes('\\' + f.twin) ? '<button type="button" class="ibx-btn ibx-btn--sm ibx-btn--danger ibx-js-twin" data-do="merge">' + th('InboxTwinMerge') + '</button>'
							: (main ? '<span class="ibx-muted ibx-twins__server">' + th('InboxTwinServerFolder') + '</span>' : '')) + '</td></tr>';
				}).join('') + '</tbody></table>';
		}
		box.innerHTML = html;
		I.mount(box);
		$('.ibx-js-retention', box).addEventListener('submit', async e => {
			e.preventDefault();
			const v = formValues(e.target);
			const more = ['trash', 'junk'].filter(k => +v[k] > 0 && (!ret[k] || +v[k] < ret[k]));
			// Turned on or shortened: said before, the removal is for good
			if (more.length && !await confirmDialog({ title: t('InboxRetentionConfirmTitle'), text: t('InboxRetentionConfirmText', Math.min(...more.map(k => +v[k]))), confirm: t('InboxSave'), danger: true })) {
				return;
			}
			try {
				const r = await post('account', 'retention', { id: account.id, trash: v.trash, junk: v.junk });
				Object.assign(ret, r);
				I.changed('account', account.id);
				toast(t('InboxSaved'));
			} catch (err) {
				toast(err.message, { error: true });
			}
		});
		$$('.ibx-js-twin', box).forEach(b => b.addEventListener('click', async () => {
			const tr = b.closest('tr');
			const f = twins.find(x => x.path === tr.dataset.path);
			const main = mainOf(f.twin);
			const req = { id: account.id, path: f.path, do: b.dataset.do };
			if (b.dataset.do === 'merge') {
				if (!await confirmDialog({ title: t('InboxTwinMergeTitle', f.label || f.name), text: t('InboxTwinMergeText', f.total || 0, main.label || main.name), confirm: t('InboxTwinMerge'), danger: true })) {
					return;
				}
				req.confirm = 1;
			}
			$$('button', tr).forEach(x => {
				x.disabled = true;
			});
			try {
				const r = await post('account', 'twin', req);
				I.changed('account', account.id);
				toast(b.dataset.do === 'merge' ? t('InboxTwinMerged', r.moved) : t('InboxSaved'));
				tabFolders(box, account);
			} catch (err) {
				toast(err.message, { error: true });
				$$('button', tr).forEach(x => {
					x.disabled = false;
				});
			}
		}));
		$('form', box).addEventListener('submit', async e => {
			e.preventDefault();
			try {
				const values = formValues(e.target);
				// Chosen by hand: a role changed here, or chosen before and kept; the others stay found by inbox
				values.chosen = roles.filter(r => chosen.includes(r) || (values['role_' + r] || '') !== initial[r]).join(',');
				await post('account', 'roles', Object.assign({ id: account.id }, values));
				I.changed('account', account.id);
				toast(t('InboxSaved'));
			} catch (err) {
				toast(err.message, { error: true });
			}
		});
	}

	// ----- Tab Identities -----
	async function tabIdentities(box, account) {
		box.innerHTML = skeleton(3);
		let list;
		try {
			list = (await api('identity', 'list', { id: account.id })).identities;
		} catch (err) {
			box.replaceChildren(errorBox(err, () => tabIdentities(box, account)));
			return;
		}
		let html = '<p class="ibx-section__help">' + th('InboxIdentitiesHelp') + '</p>';
		if (!list.length) {
			// No identity: the mailbox sends with its own name and address, without signature (lot W6)
			const label = account.label && account.label.toLowerCase() !== String(account.email).toLowerCase() ? account.label + ' <' + account.email + '>' : account.email;
			html += '<div class="ibx-notice ibx-notice--info">' + ic('info') + '<div class="ibx-notice__text">' + th('InboxNoIdentity', label) + '</div></div>';
		} else {
			html += '<table class="ibx-table"><thead><tr><th>' + th('InboxIdentityFrom') + '</th><th>' + th('InboxIdentitySignature') + '</th><th></th></tr></thead><tbody>'
				+ list.map(i => '<tr><td><b>' + esc(i.name || i.email) + '</b> ' + (i.is_default ? '<span class="ibx-badge ibx-badge--accent">' + th('InboxDefault') + '</span>' : '') + (i.status ? '' : ' <span class="ibx-status">' + th('InboxInactive') + '</span>')
					+ '<div class="ibx-muted">' + esc(i.email) + '</div></td><td class="ibx-muted">' + (i.signature ? th('InboxSignatureYes') : th('InboxSignatureNo')) + '</td>'
					+ '<td class="ibx-table__actions"><button type="button" class="ibx-btn ibx-btn--sm" data-edit="' + i.id + '">' + th('InboxEdit') + '</button> '
					+ '<button type="button" class="ibx-btn ibx-btn--sm ibx-btn--ghost ibx-btn--icon" data-del="' + i.id + '" title="' + th('InboxDelete') + '" aria-label="' + th('InboxDelete') + '">' + ic('delete') + '</button></td></tr>').join('')
				+ '</tbody></table>';
		}
		html += '<div class="ibx-formfoot"><button type="button" class="ibx-btn ibx-btn--primary ibx-js-add">' + ic('plus') + ' ' + th('InboxAddIdentity') + '</button></div>';
		box.innerHTML = html;
		I.mount(box);
		$('.ibx-js-add', box).addEventListener('click', () => identityDialog(box, account, { email: list.length ? '' : account.email, name: '', reply_to: '', bcc: '', signature: '', is_default: list.length ? 0 : 1, status: 1 }));
		box.onclick = async e => {
			const ed = e.target.closest('[data-edit]');
			if (ed) {
				identityDialog(box, account, list.find(i => i.id === +ed.dataset.edit));
				return;
			}
			const del = e.target.closest('[data-del]');
			if (del) {
				const i = list.find(x => x.id === +del.dataset.del);
				const ok = await confirmDialog({ title: t('InboxDeleteIdentityTitle', i.email), text: t('InboxDeleteIdentityText'), confirm: t('InboxDelete'), danger: true });
				if (ok) {
					try {
						await post('identity', 'delete', { id: account.id, identity_id: i.id });
						I.changed('identity', account.id);
						tabIdentities(box, account);
					} catch (err) {
						toast(err.message, { error: true });
					}
				}
			}
		};
	}

	function identityDialog(box, account, identity) {
		const body = '<form class="ibx-form" novalidate>'
			+ field('email', t('InboxIdentityEmail'), identity.email, { type: 'email', required: true, autocomplete: 'off' })
			+ field('name', t('InboxIdentityName'), identity.name, { autocomplete: 'off' })
			+ field('reply_to', t('InboxIdentityReplyTo'), identity.reply_to, { type: 'email', autocomplete: 'off' })
			+ field('bcc', t('InboxIdentityBcc'), identity.bcc, { type: 'email', autocomplete: 'off', help: t('InboxIdentityBccHelp') })
			// Signature (1.51.0): the editor of the writing, faithful to the inline styles; the textarea stays the value of
			// the form and the HTML source mode
			+ '<div class="ibx-formrow"><span class="ibx-formrow__label" id="f-signature-label">' + th('InboxIdentitySignature') + '</span><div>'
			+ '<div class="ibx-tpledit__tools ibx-js-sigtools" role="toolbar" aria-label="' + th('InboxFmtToolbar') + '">'
			+ [['bold', 'fmt-bold', 'InboxFmtBold'], ['italic', 'fmt-italic', 'InboxFmtItalic'], ['underline', 'fmt-underline', 'InboxFmtUnderline'], ['color', 'fmt-color', 'InboxFmtColor'], null,
				['link', 'link', 'InboxFmtLink'], ['image', 'file-image', 'InboxSignatureImage'], ['clearFormat', 'fmt-clear', 'InboxFmtClear'], null,
				['undo', 'undo', 'InboxFmtUndo'], ['redo', 'redo', 'InboxFmtRedo'], null, ['variable', 'variable', 'InboxVarInsert'], ['source', 'fmt-source', 'InboxFmtSource']]
				.map(x => (x ? '<button type="button" class="ibx-tbtn" data-cmd="' + x[0] + '" title="' + th(x[2]) + '" aria-label="' + th(x[2]) + '"' + (x[0] === 'source' ? ' aria-pressed="false"' : '') + (x[0] === 'variable' ? ' aria-haspopup="listbox" aria-expanded="false" disabled' : '') + '>' + ic(x[1], 'ibx-tbtn__icon') + '</button>' : '<span class="ibx-compose__sep" aria-hidden="true"></span>')).join('')
			+ '<input type="color" class="ibx-sigcolor ibx-js-sigcolor" tabindex="-1" aria-hidden="true" value="#d31b11">'
			+ '</div>'
			+ '<div class="ibx-tpledit__editor ibx-js-sigeditor" aria-labelledby="f-signature-label"></div>'
			+ '<textarea class="ibx-field ibx-sigsource" id="f-signature" name="signature" rows="12" spellcheck="false" aria-labelledby="f-signature-label" hidden>' + esc(identity.signature) + '</textarea>'
			+ '<div class="ibx-formrow__help">' + th('InboxSignatureHelp') + '</div><div class="ibx-inline" style="margin-top:.5rem"><button type="button" class="ibx-btn ibx-btn--sm ibx-js-profiles">' + ic('download') + ' ' + th('InboxImportFromDolibarr') + '</button>'
			+ '<button type="button" class="ibx-btn ibx-btn--sm ibx-js-sigfile">' + ic('source') + ' ' + th('InboxImportHtmlFile') + '</button><input type="file" class="ibx-js-sigfileinput" accept=".html,.htm,text/html" hidden>'
			+ '<button type="button" class="ibx-btn ibx-btn--sm ibx-js-sigprev">' + ic('open') + ' ' + th('InboxTemplatePreviewRun') + '</button></div>'
			+ '<div class="ibx-tpledit__preview ibx-js-sigprevbox" hidden></div></div></div>'
			+ '<div class="ibx-formrow"><span class="ibx-formrow__label">' + th('InboxOptions') + '</span><div><label class="ibx-check"><input type="checkbox" name="is_default"' + (identity.is_default ? ' checked' : '') + '> ' + th('InboxIdentityDefault') + '</label><br>'
			+ '<label class="ibx-check"><input type="checkbox" name="status"' + (identity.status ? ' checked' : '') + '> ' + th('InboxIdentityActive') + '</label></div></div>'
			+ '</form>';
		let sig = null;
		const dlg = dialog({
			title: identity.id ? t('InboxEditIdentity') : t('InboxAddIdentity'),
			wide: true,
			body,
			actions: [{ label: t('InboxSave'), primary: true, run: async d => {
				if (sig) {
					sig.sync();
				}
				const values = formValues($('form', d));
				try {
					await post('identity', 'save', Object.assign({ id: account.id, identity_id: identity.id || 0 }, values));
				} catch (err) {
					// The dialog stays open with what was typed (an error never empties the form)
					toast(err.message, { error: true });
					return false;
				}
				I.changed('identity', account.id);
				toast(t('InboxSaved'));
				tabIdentities(box, account);
			} }],
			onOpen: d => {
				$('.ibx-js-profiles', d).addEventListener('click', () => profilesDialog(account, d));
				$('.ibx-js-sigfile', d).addEventListener('click', () => $('.ibx-js-sigfileinput', d).click());
				$('.ibx-js-sigfileinput', d).addEventListener('change', e => {
					const file = e.target.files[0];
					e.target.value = '';
					if (file) {
						importSignatureFile(file, d);
					}
				});
			},
		});
		dlg.addEventListener('close', () => sig && sig.destroy());
		signatureEditor(dlg, account).then(ed => {
			sig = ed;
		});
	}

	/**
	 * Editor of the signature in the dialog of an identity: the textarea name="signature" holds the value (save, import
	 * from Dolibarr or from a file write into it and fire « change »), shown as it is in the HTML source mode
	 * @param {HTMLElement} d       Dialog
	 * @param {object}      account Mailbox of the identity
	 * @returns {Promise<object|null>} {sync(), destroy()}
	 */
	async function signatureEditor(d, account) {
		const ta = $('[name="signature"]', d);
		const box = $('.ibx-js-sigeditor', d);
		const tools = $('.ibx-js-sigtools', d);
		let editor;
		try {
			editor = await I.editors.create(box, { html: ta.value, label: t('InboxIdentitySignature'), nativeTools: tools });
		} catch (err) {
			// No editor: the HTML source, as before
			tools.hidden = true;
			box.hidden = true;
			ta.hidden = false;
			toast(t('InboxComposeEditorFailed'), { error: true });
			return null;
		}
		// CKEditor with its own toolbar (1.94.0): the bar of inbox keeps the variables only
		if (editor.getCapabilities().nativeToolbar) {
			tools.classList.add('ibx-tools--native');
		}
		let source = false;
		let varKeys = [];
		let varValues = null;
		const sync = () => {
			if (!source) {
				ta.value = editor.isEmpty() ? '' : editor.getHtml();
			}
		};
		const setSource = on => {
			sync();
			source = on;
			$('[data-cmd="source"]', tools).setAttribute('aria-pressed', on ? 'true' : 'false');
			$$('[data-cmd]:not([data-cmd="source"])', tools).forEach(b => {
				b.disabled = on || (b.dataset.cmd === 'variable' && !varKeys.length);
			});
			box.hidden = on;
			ta.hidden = !on;
			if (on) {
				ta.focus();
			} else {
				editor.setHtml(ta.value);
				editor.focus();
			}
		};
		const color = $('.ibx-js-sigcolor', tools);
		color.addEventListener('input', () => editor.exec('color', color.value));
		tools.addEventListener('click', e => {
			const b = e.target.closest('[data-cmd]');
			if (!b || b.disabled) {
				return;
			}
			const cmd = b.dataset.cmd;
			if (cmd === 'source') {
				setSource(!source);
			} else if (cmd === 'color') {
				color.click();
			} else if (cmd === 'link') {
				urlDialog(t('InboxFmtLink'), t('InboxComposeLinkUrl'), editor.linkAt ? editor.linkAt() : '', v => editor.exec('link', v && !/^(https?:\/\/|mailto:|tel:)/i.test(v) ? 'https://' + v : v));
			} else if (cmd === 'image') {
				urlDialog(t('InboxSignatureImage'), t('InboxSignatureImageUrl'), '', v => {
					if (v) {
						editor.insertImage(v);
					}
				}, true);
			} else if (cmd !== 'variable') {
				// The picker of the variables has its own listener
				editor.exec(cmd);
			}
		});
		// Written into the textarea from outside (import): shown at once
		ta.addEventListener('change', () => {
			if (!source) {
				editor.setHtml(ta.value);
			}
		});
		// Preview as the writing will show it (1.53.0): the variables replaced for the user, those nothing replaces named
		const form = $('form', d);
		const prevBox = $('.ibx-js-sigprevbox', d);
		const preview = async () => {
			sync();
			return post('identity', 'preview', { id: account.id, name: form.elements.name.value, email: form.elements.email.value, reply_to: form.elements.reply_to.value, signature: ta.value });
		};
		$('.ibx-js-sigprev', d).addEventListener('click', async () => {
			prevBox.hidden = false;
			prevBox.innerHTML = '<p class="ibx-muted">' + th('InboxTemplatePreviewLoading') + '</p>';
			let r;
			try {
				r = await preview();
			} catch (err) {
				prevBox.innerHTML = '<p class="ibx-formrow__error">' + esc(err.message) + '</p>';
				return;
			}
			// Cleaned by the allow list of the mails on the server (no script, no event)
			prevBox.innerHTML = (r.unknown.length ? '<p class="ibx-formrow__error">' + th('InboxSignatureUnknownVars', r.unknown.map(k => '__' + k + '__').join(', ')) + '</p>' : '')
				+ '<div class="ibx-tpledit__prevbody"></div>';
			$('.ibx-tpledit__prevbody', prevBox).innerHTML = r.html || '<p class="ibx-muted">' + th('InboxSignatureNo') + '</p>';
		});
		// The keys and what they give, from the server (those of the other modules too); read again at each opening
		// (name or address of the identity typed since)
		const pickBtn = $('[data-cmd="variable"]', tools);
		const loadVars = () => preview().then(r => {
			varKeys = r.keys || [];
			varValues = r.values || null;
			pickBtn.disabled = source || !varKeys.length;
		}).catch(() => {
			// No list: the variables can still be typed
		});
		loadVars();
		pickBtn.addEventListener('click', async () => {
			await loadVars();
			if (!varKeys.length) {
				return;
			}
			// Written as __KEY__, plain text, as Dolibarr and the other modules write their signatures
			varPicker(pickBtn, varKeys, varValues, k => editor.insertText('__' + k + '__'));
		});
		return { sync, destroy: () => editor.destroy() };
	}

	/** Groups of the picker of the variables, by the start of the key (the first that matches) */
	const VAR_GROUPS = [['INBOX_', 'InboxVarGroupInbox'], ['USER_', 'InboxVarGroupUser'], ['MYCOMPANY_', 'InboxVarGroupCompany'], ['THIRDPARTY_', 'InboxVarGroupThirdparty'],
		['CONTACT', 'InboxVarGroupThirdparty'], ['', 'InboxVarGroupOther']];

	/**
	 * Picker of the variables (1.53.3): a search on the key and on its value, grouped, the value each one gives shown
	 * (values hidden by the server for the keys that look like secrets); arrows, Enter, Escape
	 * @param {HTMLElement} anchor   Button of the toolbar
	 * @param {string[]}    keys     Keys without the __
	 * @param {object|null} values   KEY => value ('' empty, null hidden), null when the values are not known (templates)
	 * @param {Function}    onPick   Called with the key
	 */
	function varPicker(anchor, keys, values, onPick) {
		const open = document.querySelector('.ibx-varpick');
		if (open) {
			const same = open._anchor === anchor;
			open._close(false);
			if (same) {
				return;
			}
		}
		const groupOf = k => VAR_GROUPS.find(g => k.indexOf(g[0]) === 0)[1];
		const box = document.createElement('div');
		box.className = 'ibx-menu ibx-varpick';
		box._anchor = anchor;
		box.innerHTML = '<div class="ibx-varpick__search">' + ic('search', 'ibx-faint') + '<input type="search" class="ibx-varpick__input" autocomplete="off" spellcheck="false" placeholder="' + th('InboxVarSearch') + '" aria-label="' + th('InboxVarSearch') + '" aria-controls="ibx-varpick-list"></div>'
			+ '<div class="ibx-varpick__list" id="ibx-varpick-list" role="listbox" aria-label="' + th('InboxVarInsert') + '"></div>';
		(anchor.closest('dialog[open]') || document.body).appendChild(box);
		const input = $('.ibx-varpick__input', box);
		const list = $('.ibx-varpick__list', box);
		let shown = [];
		let active = 0;
		const valueHtml = k => {
			if (!values) {
				return '';
			}
			const v = values[k];
			return '<span class="ibx-varpick__value' + (v ? '' : ' is-empty') + '">' + (v === null || v === undefined ? th('InboxVarHidden') : (v === '' ? th('InboxVarEmpty') : esc(v))) + '</span>';
		};
		const paint = () => {
			const q = input.value.trim().toLowerCase().replace(/^_+|_+$/g, '');
			shown = keys.filter(k => !q || k.toLowerCase().indexOf(q) !== -1 || (values && values[k] && String(values[k]).toLowerCase().indexOf(q) !== -1));
			active = Math.min(active, Math.max(0, shown.length - 1));
			let last = '';
			const order = VAR_GROUPS.map(g => g[1]).filter((g, i, a) => a.indexOf(g) === i);
			shown.sort((a, b) => order.indexOf(groupOf(a)) - order.indexOf(groupOf(b)) || a.localeCompare(b));
			list.innerHTML = shown.length ? shown.map((k, i) => {
				const g = groupOf(k);
				const head = g !== last ? '<div class="ibx-menu__header" role="presentation">' + th(g) + '</div>' : '';
				last = g;
				return head + '<div class="ibx-varpick__item' + (i === active ? ' is-active' : '') + '" role="option" id="ibx-varpick-' + i + '" aria-selected="' + (i === active) + '" data-var="' + i + '">'
					+ '<span class="ibx-varpick__key">' + esc('__' + k + '__') + '</span>' + valueHtml(k) + '</div>';
			}).join('') : '<div class="ibx-menu__hint ibx-varpick__none">' + th('InboxVarNone') + '</div>';
			input.setAttribute('aria-activedescendant', shown.length ? 'ibx-varpick-' + active : '');
			const cur = $('.ibx-varpick__item.is-active', list);
			if (cur) {
				cur.scrollIntoView({ block: 'nearest' });
			}
		};
		const place = () => {
			const r = anchor.getBoundingClientRect();
			const w = box.offsetWidth;
			const h = box.offsetHeight;
			box.style.position = 'fixed';
			box.style.zIndex = 90;
			box.style.left = Math.max(8, Math.min(r.right - w, window.innerWidth - w - 8)) + 'px';
			box.style.top = (r.bottom + h + 8 > window.innerHeight ? Math.max(8, r.top - h - 4) : r.bottom + 4) + 'px';
		};
		const outside = e => {
			if (!box.contains(e.target) && !anchor.contains(e.target)) {
				close(false);
			}
		};
		const close = refocus => {
			document.removeEventListener('pointerdown', outside, true);
			box.remove();
			anchor.setAttribute('aria-expanded', 'false');
			if (refocus) {
				anchor.focus();
			}
		};
		box._close = close;
		const pick = i => {
			if (shown[i] !== undefined) {
				close(false);
				onPick(shown[i]);
			}
		};
		input.addEventListener('input', () => {
			active = 0;
			paint();
		});
		input.addEventListener('keydown', e => {
			if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
				e.preventDefault();
				if (shown.length) {
					active = (active + (e.key === 'ArrowDown' ? 1 : shown.length - 1)) % shown.length;
					paint();
				}
			} else if (e.key === 'Enter') {
				e.preventDefault();
				pick(active);
			} else if (e.key === 'Escape') {
				// The picker only, never the dialog under it
				e.preventDefault();
				e.stopPropagation();
				close(true);
			} else if (e.key === 'Tab') {
				close(false);
			}
		});
		list.addEventListener('pointerdown', e => e.preventDefault());
		list.addEventListener('click', e => {
			// data-var, never data-i: the dialog under the picker takes a [data-i] for one of its buttons and closes
			const it = e.target.closest('[data-var]');
			if (it) {
				e.stopPropagation();
				pick(+it.dataset.var);
			}
		});
		paint();
		place();
		anchor.setAttribute('aria-expanded', 'true');
		document.addEventListener('pointerdown', outside, true);
		input.focus();
	}

	/** A web address asked in a small dialog (link, picture of the signature); a picture needs https:// */
	function urlDialog(title, label, value, done, https) {
		dialog({
			title,
			body: '<label class="ibx-formrow__label" for="ibx-url-field">' + esc(label) + '</label><input id="ibx-url-field" class="ibx-field" type="url" inputmode="url" autocomplete="off" spellcheck="false" placeholder="https://" value="' + esc(value) + '">',
			actions: [{ label: t('InboxSave'), primary: true, run: d => {
				const v = $('#ibx-url-field', d).value.trim();
				if (https && v && !/^https:\/\//i.test(v)) {
					$('#ibx-url-field', d).focus();
					toast(t('InboxSignatureImageHttps'), { error: true });
					return false;
				}
				done(v);
			} }],
			onOpen: d => $('#ibx-url-field', d).focus(),
		});
	}

	/**
	 * Signature from an HTML file (made by a generator of signatures, exported from another mail software): its body
	 * only, read here in an inert document (nothing of it runs), cleaned by the server at the save
	 */
	async function importSignatureFile(file, d) {
		if (file.size > 1048576) {
			toast(t('InboxImportHtmlFileTooBig'), { error: true });
			return;
		}
		const doc = new DOMParser().parseFromString(await file.text(), 'text/html');
		doc.querySelectorAll('script, style, link, meta, title, base, iframe, object, embed, form').forEach(n => n.parentNode.removeChild(n));
		const html = doc.body.innerHTML.trim();
		if (!html) {
			toast(t('InboxImportHtmlFileEmpty'), { error: true });
			return;
		}
		const ta = $('[name="signature"]', d);
		if (ta.value.trim() !== '') {
			const ok = await confirmDialog({ title: t('InboxReplaceSignatureTitle'), text: t('InboxReplaceSignatureFileText'), confirm: t('InboxReplace') });
			if (!ok) {
				return;
			}
		}
		ta.value = html;
		ta.dispatchEvent(new Event('change'));
		// Pictures with a relative address (next to the file on the disk): no mail software can show them
		const relative = Array.from(doc.querySelectorAll('img')).filter(i => !/^(https?:|data:|cid:)/i.test(i.getAttribute('src') || '')).length;
		if (relative) {
			toast(t('InboxImportHtmlFileRelativeImages', relative), { error: true, duration: 12000 });
		}
	}

	async function profilesDialog(account, parent) {
		let profiles;
		try {
			profiles = (await api('identity', 'profiles', { id: account.id })).profiles;
		} catch (err) {
			toast(err.message, { error: true });
			return;
		}
		if (!profiles.length) {
			toast(t('InboxNoSenderProfile'));
			return;
		}
		const body = '<p>' + th('InboxProfilesHelp') + '</p><div class="ibx-form">' + profiles.map((p, n) => '<label class="ibx-radio"><input type="radio" name="profile" value="' + p.id + '"' + (n === 0 ? ' checked' : '') + '> <b>' + esc(p.label) + '</b> <span class="ibx-muted">' + esc(p.email) + (p.has_signature ? '' : ' · ' + th('InboxSignatureNo')) + '</span></label>').join('') + '</div><div class="ibx-js-preview"></div>';
		dialog({
			title: t('InboxImportFromDolibarr'),
			body,
			actions: [{ label: t('InboxCopy'), primary: true, run: async d => {
				const id = (formValues(d).profile) || '';
				const r = await api('identity', 'copy', { id: account.id, profile_id: id });
				const sig = $('[name="signature"]', parent);
				if (sig.value.trim() !== '' && r.signature !== sig.value) {
					const ok = await confirmDialog({ title: t('InboxReplaceSignatureTitle'), text: t('InboxReplaceSignatureText'), confirm: t('InboxReplace') });
					if (!ok) {
						return false;
					}
				}
				$('[name="name"]', parent).value = r.name;
				sig.value = r.signature;
				sig.dispatchEvent(new Event('change'));
				if (!$('[name="email"]', parent).value) {
					$('[name="email"]', parent).value = r.email;
				}
			} }],
		});
	}

	// ----- Tab Templates (lot W6 1.18.0): mail templates offered in the writings of this mailbox -----
	async function tabTemplates(box, account) {
		box.innerHTML = skeleton(3);
		let data;
		try {
			data = await api('template', 'list', { id: account.id });
		} catch (err) {
			box.replaceChildren(errorBox(err, () => tabTemplates(box, account)));
			return;
		}
		const names = {};
		data.accounts.forEach(a => {
			names[a.id] = a.label;
		});
		const forWho = tp => tp.private ? th('InboxTemplateMine') : (!tp.accounts.length ? th('InboxTemplateAllMailboxes') : esc(tp.accounts.map(id => names[id] || ('#' + id)).join(', ')));
		let html = '<p class="ibx-section__help">' + th('InboxTemplatesHelp') + '</p>';
		if (!data.templates.length) {
			html += '<div class="ibx-empty">' + ic('template') + '<p>' + th('InboxTemplatesEmpty') + '</p></div>';
		} else {
			html += '<table class="ibx-table ibx-js-tpls"><thead><tr><th></th><th>' + th('InboxTemplateLabel') + '</th><th>' + th('InboxTemplateFor') + '</th><th>' + th('InboxStatus') + '</th><th></th></tr></thead><tbody>'
				+ data.templates.map((tp, n) => '<tr class="' + (tp.editable ? 'ibx-dragrow' : '') + '" data-tpl="' + tp.id + '">'
					+ '<td>' + (tp.editable ? '<span class="ibx-draghandle" aria-hidden="true">' + ic('more-v') + '</span>' : '') + '</td>'
					+ '<td><b>' + esc(tp.label) + '</b>' + (tp.lang ? ' <span class="ibx-badge">' + esc(tp.lang) + '</span>' : '') + (tp.whole ? ' <span class="ibx-badge">' + th('InboxTemplateWholeShort') + '</span>' : '') + '<div class="ibx-muted ibx-ellipsis">' + (tp.subject ? esc(tp.subject) : '<i>' + th('InboxNoSubject') + '</i>') + '</div></td>'
					+ '<td class="ibx-muted">' + forWho(tp) + '</td>'
					+ '<td>' + (tp.active ? th('InboxAccountStatusActive') : '<span class="ibx-status">' + th('InboxInactive') + '</span>') + '</td>'
					+ '<td class="ibx-table__actions">' + (tp.editable ? '<button type="button" class="ibx-btn ibx-btn--sm ibx-btn--ghost ibx-btn--icon" data-move="-1" title="' + th('InboxMoveUp') + '" aria-label="' + th('InboxMoveUp') + '"' + (n === 0 ? ' aria-disabled="true"' : '') + '>' + ic('chevron-up') + '</button>'
						+ '<button type="button" class="ibx-btn ibx-btn--sm ibx-btn--ghost ibx-btn--icon" data-move="1" title="' + th('InboxMoveDown') + '" aria-label="' + th('InboxMoveDown') + '"' + (n === data.templates.length - 1 ? ' aria-disabled="true"' : '') + '>' + ic('chevron-down') + '</button>'
						+ '<button type="button" class="ibx-btn ibx-btn--sm" data-edit="' + tp.id + '">' + th('InboxEdit') + '</button> '
						+ '<button type="button" class="ibx-btn ibx-btn--sm ibx-btn--ghost ibx-btn--icon" data-del="' + tp.id + '" title="' + th('InboxDelete') + '" aria-label="' + th('InboxDelete') + '">' + ic('delete') + '</button>' : '') + '</td></tr>').join('')
				+ '</tbody></table>';
		}
		html += '<div class="ibx-formfoot"><button type="button" class="ibx-btn ibx-btn--primary ibx-js-add">' + ic('plus') + ' ' + th('InboxTemplateNew') + '</button>'
			// Default templates of the mailbox (1.118.0), the dialog of the writing
			+ (I.templateDefaults ? ' <button type="button" class="ibx-btn ibx-js-tdefaults">' + ic('star') + ' ' + th('InboxTemplateDefaults') + '</button>' : '')
			+ (data.core_url ? '<span class="ibx-formfoot__spacer"></span><a class="ibx-btn ibx-btn--ghost" href="' + esc(data.core_url) + '" target="_blank" rel="noopener">' + ic('external') + ' ' + th('InboxTemplatesInDolibarr') + '</a>' : '') + '</div>';
		box.innerHTML = html;
		const reload = () => tabTemplates(box, account);
		$('.ibx-js-add', box).addEventListener('click', () => templateDialog(account, data, null, reload));
		const defBtn = $('.ibx-js-tdefaults', box);
		if (defBtn) {
			defBtn.addEventListener('click', () => I.templateDefaults(account.id, account.label || account.email));
		}
		const order = () => $$('[data-tpl]', box).map(tr => +tr.dataset.tpl);
		const saveOrder = async ids => {
			try {
				await post('template', 'order', { ids });
			} catch (err) {
				toast(err.message, { error: true });
			}
			reload();
		};
		box.onclick = async e => {
			const ed = e.target.closest('[data-edit]');
			if (ed) {
				templateDialog(account, data, +ed.dataset.edit, reload);
				return;
			}
			const mv = e.target.closest('[data-move]');
			if (mv && mv.getAttribute('aria-disabled') !== 'true') {
				const ids = order();
				const i = ids.indexOf(+mv.closest('[data-tpl]').dataset.tpl);
				const j = i + (+mv.dataset.move);
				[ids[i], ids[j]] = [ids[j], ids[i]];
				saveOrder(ids);
				return;
			}
			const del = e.target.closest('[data-del]');
			if (del) {
				const tp = data.templates.find(x => x.id === +del.dataset.del);
				const ok = await confirmDialog({ title: t('InboxTemplateDeleteTitle', tp.label), text: t('InboxTemplateDeleteText'), confirm: t('InboxDelete'), danger: true });
				if (ok) {
					try {
						await post('template', 'delete', { template: tp.id });
						reload();
					} catch (err) {
						toast(err.message, { error: true });
					}
				}
			}
		};
		// Drag and drop of the rows (with the up / down buttons for the keyboard and the phone)
		dragByHandle(box);
		let dragged = null;
		box.ondragstart = e => {
			dragged = e.target.closest('[data-tpl]');
			if (dragged) {
				dragged.classList.add('is-dragging');
			}
		};
		box.ondragover = e => {
			const over = e.target.closest('[data-tpl]');
			if (!dragged || !over || over === dragged) {
				return;
			}
			e.preventDefault();
			const rect = over.getBoundingClientRect();
			over.parentNode.insertBefore(dragged, e.clientY < rect.top + rect.height / 2 ? over : over.nextSibling);
		};
		box.ondragend = () => {
			if (dragged) {
				dragged.classList.remove('is-dragging');
				dragged = null;
				saveOrder(order());
			}
		};
	}

	/**
	 * Editor of a template: name, for whom (me, mailboxes, every mailbox), subject, text with its variables (chips)
	 * @param {object} account Mailbox of the tab
	 * @param {object} list    Answer of template/list (accounts the user manages, admin)
	 * @param {number|null} id Template, null for a new one
	 * @param {Function} done
	 */
	async function templateDialog(account, list, id, done) {
		let tp = { id: 0, label: '', subject: '', html: '', private: 0, active: 1, accounts: [account.id], whole: 0 };
		let keys;
		let langs = [];
		try {
			const r = id ? await api('template', 'get', { template: id }) : await api('template', 'keys', {});
			keys = r.keys;
			langs = r.langs || [];
			if (id) {
				tp = r.template;
			}
		} catch (err) {
			toast(err.message, { error: true });
			return;
		}
		const who = tp.private ? 'me' : (tp.accounts.length ? 'boxes' : 'all');
		const opt = (v, label, disabled) => '<label class="ibx-seg__opt"><input type="radio" name="who" value="' + v + '"' + (who === v ? ' checked' : '') + (disabled ? ' disabled' : '') + '><span>' + label + '</span></label>';
		const tools = [['bold', 'fmt-bold', 'InboxFmtBold'], ['italic', 'fmt-italic', 'InboxFmtItalic'], ['underline', 'fmt-underline', 'InboxFmtUnderline'], ['bulletList', 'fmt-bullets', 'InboxFmtBullets'], ['orderedList', 'fmt-numbers', 'InboxFmtNumbers']];
		const body = '<form class="ibx-form ibx-tpledit" novalidate>'
			+ field('label', t('InboxTemplateLabel'), tp.label, { required: true, autocomplete: 'off' })
			+ '<div class="ibx-formrow"><span class="ibx-formrow__label">' + th('InboxTemplateFor') + '</span><div><div class="ibx-seg" role="radiogroup" aria-label="' + th('InboxTemplateFor') + '">'
			+ opt('me', th('InboxTemplateMine')) + opt('boxes', th('InboxTemplateSomeMailboxes')) + opt('all', th('InboxTemplateAllMailboxes'), !list.admin) + '</div>'
			+ '<div class="ibx-tpledit__boxes ibx-js-boxes"' + (who === 'boxes' ? '' : ' hidden') + '>' + list.accounts.map(a => '<label class="ibx-check"><input type="checkbox" name="acc" value="' + a.id + '"' + (tp.accounts.includes(a.id) ? ' checked' : '') + '> ' + esc(a.label) + '</label>').join('') + '</div></div></div>'
			+ field('subject', t('InboxSubject'), tp.subject, { autocomplete: 'off', help: t('InboxTemplateSubjectHelp') })
			+ (I.templateWholeRow ? I.templateWholeRow(tp.whole) : '')
			+ '<div class="ibx-formrow"><span class="ibx-formrow__label" id="ibx-tpl-body-label">' + th('InboxTemplateBody') + '</span><div>'
			+ '<div class="ibx-tpledit__tools" role="toolbar" aria-label="' + th('InboxFmtToolbar') + '">' + tools.map(x => '<button type="button" class="ibx-tbtn" data-cmd="' + x[0] + '" title="' + th(x[2]) + '" aria-label="' + th(x[2]) + '">' + ic(x[1], 'ibx-tbtn__icon') + '</button>').join('')
			+ '<span class="ibx-compose__sep" aria-hidden="true"></span><button type="button" class="ibx-tbtn ibx-js-var" title="' + th('InboxVarInsert') + '" aria-label="' + th('InboxVarInsert') + '" aria-haspopup="listbox" aria-expanded="false">' + ic('variable', 'ibx-tbtn__icon') + '</button></div>'
			+ '<div class="ibx-tpledit__editor ibx-js-tpleditor" aria-labelledby="ibx-tpl-body-label"></div>'
			+ '<div class="ibx-formrow__help">' + th('InboxTemplateBodyHelp') + '</div></div></div>'
			// Language (1.26.0): the values of the variables (dates, amounts, labels) are written in it
			+ field('lang', t('InboxTemplateLang'), tp.lang || '', { options: [['', t('InboxTemplateLangAny')]].concat(langs.map(l => [l.code, l.label])), help: t('InboxTemplateLangHelp'), cls: 'ibx-field--medium' })
			// Preview for a recipient (1.26.0): the subject and the text filled as the writing would fill them
			+ '<div class="ibx-formrow"><label class="ibx-formrow__label" for="ibx-tpl-prevto">' + th('InboxTemplatePreview') + '</label><div>'
			+ '<div class="ibx-inline"><input class="ibx-field ibx-field--medium" id="ibx-tpl-prevto" type="email" inputmode="email" autocomplete="off" spellcheck="false" placeholder="' + th('InboxTemplatePreviewTo') + '">'
			+ '<button type="button" class="ibx-btn ibx-js-tplprev">' + ic('open') + ' ' + th('InboxTemplatePreviewRun') + '</button></div>'
			+ '<div class="ibx-formrow__help">' + th('InboxTemplatePreviewHelp') + '</div>'
			+ '<div class="ibx-tpledit__preview ibx-js-tplprevbox" hidden></div></div></div>'
			+ '<div class="ibx-formrow"><span class="ibx-formrow__label">' + th('InboxOptions') + '</span><div><label class="ibx-check"><input type="checkbox" name="active"' + (tp.active ? ' checked' : '') + '> ' + th('InboxTemplateActive') + '</label></div></div>'
			+ '</form>';
		let editor = null;
		const dlg = dialog({
			title: id ? t('InboxTemplateEdit') : t('InboxTemplateNew'),
			wide: true,
			body,
			actions: [{ label: t('InboxSave'), primary: true, run: async d => {
				const f = $('form', d);
				const label = f.elements.label.value.trim();
				if (!label) {
					f.elements.label.focus();
					return false;
				}
				const w = f.querySelector('[name="who"]:checked').value;
				const accounts = w === 'boxes' ? $$('[name="acc"]:checked', f).map(c => +c.value) : [];
				if (w === 'boxes' && !accounts.length) {
					toast(t('InboxTemplateNeedMailbox'), { error: true });
					return false;
				}
				try {
					await post('template', 'save', { template: tp.id, label, subject: f.elements.subject.value, html: editor ? editor.getHtml() : tp.html, private: w === 'me' ? 1 : 0, accounts, active: f.elements.active.checked ? 1 : 0, lang: f.elements.lang.value,
						...(f.querySelector('[name="whole"]:checked') ? { whole: f.querySelector('[name="whole"]:checked').value === '1' ? 1 : 0 } : {}) });
				} catch (err) {
					toast(err.message, { error: true });
					return false;
				}
				toast(t('InboxSaved'));
				done();
			} }],
		});
		dlg.addEventListener('close', () => editor && editor.destroy());
		$$('[name="who"]', dlg).forEach(rb => rb.addEventListener('change', () => {
			$('.ibx-js-boxes', dlg).hidden = rb.value !== 'boxes' || !rb.checked;
		}));
		try {
			editor = await I.editors.create($('.ibx-js-tpleditor', dlg), { html: tp.html, placeholder: t('InboxTemplateBodyPlaceholder'), label: t('InboxTemplateBody'), nativeTools: $('.ibx-tpledit__tools', dlg) });
		} catch (err) {
			toast(t('InboxComposeEditorFailed'), { error: true });
			return;
		}
		if (editor.getCapabilities().nativeToolbar) {
			$('.ibx-tpledit__tools', dlg).classList.add('ibx-tools--native');
		}
		$('.ibx-tpledit__tools', dlg).addEventListener('click', e => {
			const b = e.target.closest('[data-cmd]');
			if (b) {
				editor.exec(b.dataset.cmd);
			}
		});
		// Values unknown here (they follow the recipient and the piece): the keys only
		const varBtn = $('.ibx-js-var', dlg);
		varBtn.addEventListener('click', () => varPicker(varBtn, keys, null, k => {
			editor.insertVariable(k, '');
			editor.focus();
		}));
		// Preview: in the mailbox of the tab, for the recipient typed (or none: the variables of the company and the user)
		const prevBox = $('.ibx-js-tplprevbox', dlg);
		const runPreview = async () => {
			const f = $('form', dlg);
			prevBox.hidden = false;
			prevBox.innerHTML = '<p class="ibx-muted">' + th('InboxTemplatePreviewLoading') + '</p>';
			let r;
			try {
				r = (await post('template', 'preview', { account: account.id, to: $('#ibx-tpl-prevto', dlg).value.trim(), lang: f.elements.lang.value, subject: f.elements.subject.value, html: editor.getHtml() })).preview;
			} catch (err) {
				prevBox.innerHTML = '<p class="ibx-formrow__error">' + esc(err.message) + '</p>';
				return;
			}
			// Cleaned by the allow list of the mails on the server (no script, no event, no remote style)
			prevBox.innerHTML = '<div class="ibx-tpledit__prevsubject"><span class="ibx-muted">' + th('InboxSubject') + ' :</span> ' + (r.subject ? '<b>' + esc(r.subject) + '</b>' : '<i class="ibx-muted">' + th('InboxNoSubject') + '</i>') + '</div><div class="ibx-tpledit__prevbody"></div>';
			$('.ibx-tpledit__prevbody', prevBox).innerHTML = r.html;
		};
		$('.ibx-js-tplprev', dlg).addEventListener('click', runPreview);
		$('#ibx-tpl-prevto', dlg).addEventListener('keydown', e => {
			if (e.key === 'Enter') {
				e.preventDefault();
				runPreview();
			}
		});
	}

	// ----- Tab Access -----
	// Journal of the changes under the accesses: its last lines, then pages asked on demand
	const LOG_FIRST = 10;
	const LOG_PAGE = 50;
	async function tabAccess(box, account) {
		box.innerHTML = skeleton(4);
		let data;
		let log;
		try {
			// The journal: its last lines, one more asked to know if there are others
			[data, log] = await Promise.all([api('access', 'list', { id: account.id }), api('account', 'log', { id: account.id, limit: LOG_FIRST + 1 })]);
		} catch (err) {
			box.replaceChildren(errorBox(err, () => tabAccess(box, account)));
			return;
		}
		const levels = ['read', 'send', 'handle', 'manage'];
		let html = '<p class="ibx-section__help">' + th('InboxAccessHelp') + '</p>'
			+ accessLevelsTable(!data.accesses.length)
			+ '<p class="ibx-section__help">' + th('InboxAccessAdminNone') + '</p>';
		if (data.owner) {
			html += '<div class="ibx-notice ibx-notice--info">' + ic('account') + '<div class="ibx-notice__text">' + th('InboxAccessOwner', data.owner.name) + '</div></div>';
		}
		if (!data.accesses.length) {
			html += '<div class="ibx-notice ibx-notice--warning">' + ic('warning') + '<div class="ibx-notice__text">' + th(data.owner ? 'InboxNoOtherAccess' : 'InboxNoAccessYet') + '</div></div>';
		} else {
			html += '<table class="ibx-table"><thead><tr><th>' + th('InboxAccessWho') + '</th><th>' + th('InboxAccessLevel') + '</th><th></th></tr></thead><tbody>'
				+ data.accesses.map(a => '<tr><td><span class="ibx-who">' + whoPicture(a.fk_usergroup ? { kind: 'group', id: a.fk_usergroup, name: a.name, color: a.color } : { kind: 'user', id: a.fk_user, name: a.name, photo: a.photo })
					+ '<span>' + esc(a.name) + (a.fk_usergroup ? ' <span class="ibx-muted">' + th('InboxGroupAllMembers') + '</span>' : '') + '</span></span></td>'
					+ '<td><select class="ibx-field ibx-field--medium" data-level="' + a.fk_user + ':' + a.fk_usergroup + '" aria-label="' + th('InboxAccessLevel') + '">' + levels.map(l => '<option value="' + l + '"' + (l === a.level ? ' selected' : '') + '>' + esc(levelLabel(l)) + '</option>').join('') + '</select></td>'
					+ '<td class="ibx-table__actions"><button type="button" class="ibx-btn ibx-btn--sm ibx-btn--ghost ibx-btn--icon" data-revoke="' + a.fk_user + ':' + a.fk_usergroup + ':' + a.level + '" title="' + th('InboxRemoveAccess') + '" aria-label="' + th('InboxRemoveAccess') + '">' + ic('close') + '</button></td></tr>').join('')
				+ '</tbody></table>';
		}
		html += '<form class="ibx-inline ibx-js-grant" style="margin-top:1rem"><select class="ibx-field ibx-field--medium" name="who" aria-label="' + th('InboxSearchUserOrGroup') + '"><option value=""></option></select>'
			+ '<select class="ibx-field ibx-field--medium" name="level" aria-label="' + th('InboxAccessLevel') + '">' + levels.map(l => '<option value="' + l + '">' + esc(levelLabel(l)) + '</option>').join('') + '</select>'
			+ '<button type="submit" class="ibx-btn ibx-btn--primary">' + ic('user-plus') + ' ' + th('InboxGiveAccess') + '</button></form>'
			// What the level chosen gives, and what it does not (1.91.0)
			+ '<p class="ibx-formrow__help ibx-js-levelhint" aria-live="polite">' + th('InboxAccessHint_read') + '</p>';
		html += '<section class="ibx-section" style="margin-top:2rem"><h2 class="ibx-section__title">' + th('InboxJournal') + '</h2><div class="ibx-js-acclog"></div></section>';
		box.innerHTML = html;
		// Journal of the changes (UX fix series): the last ones, then « More lines » asks the next page to the server
		const logBox = $('.ibx-js-acclog', box);
		let logLines = log.log.slice(0, LOG_FIRST);
		const paintLog = more => {
			logBox.innerHTML = journalHtml(logLines)
				+ (more ? '<div class="ibx-formfoot"><button type="button" class="ibx-btn ibx-js-logmore">' + th('InboxJournalMore') + '</button></div>' : '');
		};
		paintLog(log.log.length > LOG_FIRST);
		logBox.addEventListener('click', async e => {
			const btn = e.target.closest('.ibx-js-logmore');
			if (!btn || btn.getAttribute('aria-disabled') === 'true') {
				return;
			}
			btn.setAttribute('aria-disabled', 'true');
			try {
				const next = (await api('account', 'log', { id: account.id, offset: logLines.length, limit: LOG_PAGE + 1 })).log;
				logLines = logLines.concat(next.slice(0, LOG_PAGE));
				paintLog(next.length > LOG_PAGE);
			} catch (err) {
				btn.removeAttribute('aria-disabled');
				toast(err.message, { error: true });
			}
		});
		// A photo that does not load leaves the letters
		$$('.ibx-who__pic img', box).forEach(img => img.addEventListener('error', () => img.remove()));

		const refresh = () => {
			I.changed('access', account.id);
			tabAccess(box, account);
		};
		$$('[data-level]', box).forEach(sel => sel.addEventListener('change', async () => {
			const [u, g] = sel.dataset.level.split(':');
			try {
				await post('access', 'grant', { id: account.id, fk_user: u, fk_usergroup: g, level: sel.value });
				toast(t('InboxSaved'));
				refresh();
			} catch (err) {
				toast(err.message, { error: true });
			}
		}));
		$$('[data-revoke]', box).forEach(b => b.addEventListener('click', async () => {
			const [u, g, level] = b.dataset.revoke.split(':');
			try {
				await post('access', 'revoke', { id: account.id, fk_user: u, fk_usergroup: g });
				// Undoable rather than confirmed (principle P22)
				toast(t('InboxAccessRemoved'), { action: t('InboxUndo'), onAction: async () => {
					await post('access', 'grant', { id: account.id, fk_user: u, fk_usergroup: g, level });
					refresh();
				} });
				refresh();
			} catch (err) {
				toast(err.message, { error: true });
			}
		}));
		const grant = $('.ibx-js-grant', box);
		grant.level.addEventListener('change', () => {
			$('.ibx-js-levelhint', box).textContent = t('InboxAccessHint_' + grant.level.value);
		});
		// Searchable list of the people and groups (UX fix series): a picture, a second line that tells them apart, the
		// ones that already have an access greyed with their level
		const has = new Map(data.accesses.map(a => [(a.fk_usergroup ? 'g:' + a.fk_usergroup : 'u:' + a.fk_user), a.level]));
		if (data.owner) {
			has.set('u:' + data.owner.id, 'owner');
		}
		whoPicker(grant.who, { groups: true, has, placeholder: t('InboxSearchUserOrGroup') });
		grant.addEventListener('submit', async e => {
			e.preventDefault();
			const [kind, id] = grant.who.value.split(':');
			if (!id) {
				toast(t('InboxChooseUserOrGroup'), { error: true });
				const btn = grant.who._dolCombo ? grant.who._dolCombo.btn : grant.who;
				btn.focus();
				return;
			}
			const name = grant.who.selectedOptions[0].textContent.trim();
			try {
				await post('access', 'grant', { id: account.id, fk_user: kind === 'u' ? +id : 0, fk_usergroup: kind === 'g' ? +id : 0, level: grant.level.value });
				toast(t('InboxAccessGiven', name));
				refresh();
			} catch (err) {
				toast(err.message, { error: true });
			}
		});
	}

	/** Letters of a name (« John BOTELLA » → JB) */
	function initialsOf(name) {
		const parts = String(name || '?').trim().split(/[\s.@_-]+/).filter(Boolean);
		return ((parts[0] || '?')[0] + (parts.length > 1 ? parts[1][0] : '')).toUpperCase();
	}
	/** Hue drawn from a text: the same user keeps the same colour as in the reading (avatars of inbox-mail.js) */
	function hueOf(text) {
		let h = 0;
		for (const c of String(text || '')) {
			h = (h * 31 + c.charCodeAt(0)) % 360;
		}
		return h;
	}
	/** Picture of a user (photo, else letters) or of a group (its colour, a group icon), for the lists of the combo */
	function whoAvatar(x) {
		return x.kind === 'group' ? { icon: 'group', shape: 'square', color: x.color || '' }
			: { img: x.photo || '', text: initialsOf(x.name), color: 'hsl(' + hueOf('u' + x.id) + ' 55% 90%)' };
	}
	/** Same picture in a table of the page */
	function whoPicture(x) {
		if (x.kind === 'group') {
			const col = /^#?[0-9a-f]{6}$/i.test(x.color || '') ? '#' + x.color.replace('#', '') : '';
			// The icon dark on a light colour, white on a dark one
			const lum = col ? [1, 3, 5].map(i => parseInt(col.substr(i, 2), 16)).reduce((acc, v, i) => acc + v * [0.299, 0.587, 0.114][i], 0) : 255;
			return '<span class="ibx-who__pic ibx-who__pic--group"' + (col ? ' style="--ibx-who-bg:' + col + ';color:' + (lum > 160 ? '#1f2328' : '#fff') + '"' : '') + ' aria-hidden="true">' + ic('multi') + '</span>';
		}
		return '<span class="ibx-avatar ibx-avatar--sm ibx-who__pic" style="--ibx-avatar-h:' + hueOf('u' + x.id) + '" aria-hidden="true">' + esc(initialsOf(x.name))
			+ (x.photo ? '<img src="' + esc(x.photo) + '" alt="" loading="lazy">' : '') + '</span>';
	}
	/**
	 * Picker of a user (and of a group with o.groups) on a select: Dolibarr.tools.combo fed by the search of the server
	 * @param {HTMLSelectElement} sel Select (value u:id / g:id, or the id alone with o.ids)
	 * @param {object} o {groups, has: Map key => level, placeholder, ids}
	 */
	function whoPicker(sel, o) {
		if (!Dolibarr.tools.combo) {
			return;
		}
		Dolibarr.tools.combo.attach(sel, {
			minLength: 0,
			delay: 150,
			placeholder: o.placeholder,
			nativeOnTouch: false,
			source: async term => {
				const res = await api('access', 'search', { q: term });
				const results = res.results.filter(x => o.groups || x.kind === 'user').map(x => {
					const key = (x.kind === 'group' ? 'g:' : 'u:') + x.id;
					const level = o.has ? o.has.get(key) : '';
					const sub = x.kind === 'group' ? t('InboxGroupMembers', x.members) : (x.login || t('InboxUser'));
					return {
						value: o.ids ? String(x.id) : key, label: x.name, avatar: whoAvatar(x), disabled: !!level,
						sub: level ? sub + ' · ' + (level === 'owner' ? t('InboxAccessIsOwner') : t('InboxAccessAlready', levelLabel(level))) : sub,
					};
				});
				// Beyond the first ones given by the server: « n more, type to narrow » under the list
				return { results, more: (+res.more_users || 0) + (o.groups ? +res.more_groups || 0 : 0) };
			},
		});
	}

	/**
	 * What each level gives (1.91.0): the checks fill from one column to the next, each level holds the one before
	 * @param {boolean} open Open (a mailbox nobody uses yet)
	 * @returns {string}
	 */
	function accessLevelsTable(open) {
		// Level from which each capability is given (checked on the server: InboxActions::run, InboxAccess)
		const caps = [['InboxAccessCapRead', 0], ['InboxAccessCapSeen', 0], ['InboxAccessCapSend', 1], ['InboxAccessCapSort', 2], ['InboxAccessCapTrash', 2],
			['InboxAccessCapFolders', 2], ['InboxAccessCapAdmin', 3]];
		const levels = ['read', 'send', 'handle', 'manage'];
		return '<details class="ibx-levels"' + (open ? ' open' : '') + '><summary>' + ic('info') + ' ' + th('InboxAccessLevelsTitle') + '</summary>'
			+ '<table class="ibx-table ibx-levels__table"><thead><tr><th scope="col"><span class="ibx-sr-only">' + th('InboxAccessLevelsWhat') + '</span></th>'
			+ levels.map(l => '<th scope="col">' + esc(levelLabel(l)) + '</th>').join('') + '</tr></thead><tbody>'
			+ caps.map(([key, from]) => '<tr><th scope="row">' + th(key) + '</th>'
				+ levels.map((l, i) => '<td>' + (i >= from ? ic('check') + '<span class="ibx-sr-only">' + th('InboxAccessLevelsYes') + '</span>' : '<span class="ibx-sr-only">' + th('InboxAccessLevelsNo') + '</span>') + '</td>').join('') + '</tr>').join('')
			+ '</tbody></table></details>';
	}

	function journalHtml(lines) {
		if (!lines.length) {
			return '<p class="ibx-muted">' + th('InboxJournalEmpty') + '</p>';
		}
		const fmt = ts => new Date(ts * 1000).toLocaleString(I.locale());
		const describe = l => {
			const v = Object.assign({}, l.before, l.after);
			switch (l.action) {
				case 'access_add':
					return t('InboxLogAccessAdd', levelLabel(v.level));
				case 'access_change':
					return t('InboxLogAccessChange', levelLabel(l.before.level), levelLabel(l.after.level));
				case 'access_remove':
					return t('InboxLogAccessRemove', levelLabel(l.before.level));
				case 'status':
					return t('InboxLogStatus', t(['InboxAccountStatusDraft', 'InboxAccountStatusActive'][l.after.status] || 'InboxAccountStatusDisabled'));
				case 'server':
					return t('InboxLogServer', Object.keys(l.after).join(', '));
				case 'password':
					return t('InboxLogPassword');
				case 'create':
					return t('InboxLogCreate');
				case 'usage':
					return t('InboxLogUsage', t('InboxUsage_' + (l.before.usage || 'auto')), t('InboxUsage_' + (l.after.usage || 'auto')));
				case 'indicators':
					return t(l.after && l.after.ids ? 'InboxLogIndicatorsOwn' : 'InboxLogIndicatorsReset');
				case 'carriers':
					return t('InboxLogCarriers');
				// Modules subscribed (1.112.0): the name of the module, as it was when the line was written
				case 'consumer_grant':
				case 'consumer_change':
				case 'consumer_pause':
				case 'consumer_resume':
				case 'consumer_revoke':
					return t('InboxLog_' + l.action, (l.after && l.after.label) || (l.before && l.before.label) || '');
				case 'consumer_suspend':
					return t('InboxLog_consumer_suspend', ((l.after && l.after.labels) || []).join(', '));
				case 'protect':
				case 'protect_level':
				case 'unprotect':
				case 'protect_reset':
				case 'key_enrol':
				case 'key_grant':
				case 'key_approve':
				case 'key_revoke':
				case 'unlock':
				case 'unlock_failed':
					return t('InboxLog_' + l.action, (l.after && l.after.device) || '');
				default:
					return l.action;
			}
		};
		return '<table class="ibx-table"><thead><tr><th>' + th('InboxDate') + '</th><th>' + th('InboxBy') + '</th><th>' + th('InboxWhat') + '</th></tr></thead><tbody>'
			+ lines.map(l => '<tr><td class="ibx-muted">' + esc(fmt(l.date)) + '</td><td>' + esc(l.user || '-') + '</td><td>' + esc(describe(l)) + (l.target ? ' : <b>' + esc(l.target) + '</b>' : '') + '</td></tr>').join('') + '</tbody></table>';
	}

	/**
	 * My account: colours of the tags adapted to the colour blindness declared in the card of the user (Dolibarr), only
	 * when one is declared; says which tags show another colour
	 */
	function tagColorsRow(data, opt) {
		const setting = I.ctx('INBOX_COLORBLIND', '');
		if (!setting) {
			return '';
		}
		const visions = Dolibarr.tools.inboxColors.SETTING_VISIONS[setting] || [];
		const changed = new Set();
		data.mailboxes.forEach(m => {
			const tags = m.tags || [];
			Dolibarr.tools.inboxColors.adapt(tags, visions).forEach((c, i) => {
				if (c !== tags[i].color) {
					changed.add(tags[i].label);
				}
			});
		});
		const option = I.ctx('INBOX_COLORBLIND_OPTION', '');
		const value = I.ctx('INBOX_COLORBLIND_VALUE', setting);
		const help = changed.size ? t('InboxTagColorsAdaptSome', option, value, [...changed].join(', ')) : t('InboxTagColorsAdaptNone', option, value);
		return '<div class="ibx-formrow"><label class="ibx-formrow__label" for="me-tag_colors_adapt">' + th('InboxTagColorsAdapt') + '</label><div>' + opt('tag_colors_adapt', ['1', '0'], String(data.params.tag_colors_adapt))
			+ '<div class="ibx-formrow__help">' + esc(help) + '</div></div></div>';
	}

	// ----- Tab Tags (and the global list of Settings > General) -----
	const C = Dolibarr.tools.inboxColors;

	function borderSelect(current, attr) {
		return '<select class="ibx-field ibx-field--narrow" ' + attr + ' aria-label="' + th('InboxTagBorder') + '">'
			+ C.BORDERS.map(b => '<option value="' + b + '"' + (b === (current || 'none') ? ' selected' : '') + '>' + th('InboxTagBorder_' + b) + '</option>').join('') + '</select>';
	}

	/**
	 * Warning of the tags a colour blind person may mix up (computed for the three colour blindnesses, whoever looks:
	 * the person who sets the colours is seldom the one who needs it), with the palettes made for them
	 */
	function cvdWarning(box, tags, editable, applyPalette) {
		const zone = $('.ibx-js-cvd', box);
		const risks = C.risks(tags);
		const active = tags.filter(tg => +tg.status === 1);
		const risky = new Set(risks.flatMap(p => [tags[p.a].id, tags[p.b].id]));
		$$('[data-tag]', box).forEach(row => {
			$('[data-risk]', row).hidden = !risky.has(+row.dataset.tag);
		});
		let html = '';
		if (active.length > 8) {
			html = '<div class="ibx-notice ibx-notice--warning" style="margin-top:1rem">' + ic('warning') + '<div class="ibx-notice__text">' + th('InboxTagsCvdTooMany', active.length) + '</div></div>';
		} else if (risks.length) {
			html = '<div class="ibx-notice ibx-notice--warning" style="margin-top:1rem">' + ic('warning') + '<div class="ibx-notice__text"><b>' + th('InboxTagsCvdWarn') + '</b><ul class="ibx-notice__list">'
				+ risks.map(p => '<li>' + th('InboxTagsCvdPair', tags[p.a].label, tags[p.b].label, p.visions.map(v => t('InboxVision_' + v)).join(', ')) + '</li>').join('')
				+ '</ul>' + th('InboxTagsCvdHint') + '</div></div>';
		}
		if (editable && tags.length) {
			html += '<p style="margin-top:0.75rem"><button type="button" class="ibx-btn ibx-js-palettes">' + ic('theme') + ' ' + th('InboxTagsPalettes') + '</button></p>';
		}
		zone.innerHTML = html;
		const btn = $('.ibx-js-palettes', zone);
		if (btn) {
			btn.addEventListener('click', () => paletteDialog(tags, applyPalette));
		}
	}

	/** Palettes made for the colour blind people, previewed on the tags (propose, then apply what was chosen) */
	function paletteDialog(tags, applyPalette) {
		const options = C.PALETTES.map(p => {
			const list = tags.map((tg, i) => Object.assign({}, tg, { color: p.colors[i % p.colors.length] }));
			return { p, list, risks: C.risks(list).length };
		});
		const first = Math.max(0, options.findIndex(o => !o.risks));
		const dlg = I.dialog({
			title: t('InboxTagsPalettes'),
			wide: true,
			body: '<p class="ibx-section__help">' + th('InboxTagsPalettesHelp') + '</p><div class="ibx-palettes" role="radiogroup" aria-label="' + th('InboxTagsPalettes') + '">'
				+ options.map((o, k) => '<label class="ibx-palette"><input type="radio" name="palette" value="' + k + '"' + (k === first ? ' checked' : '') + '>'
					+ '<span class="ibx-palette__head"><b>' + esc(o.p.name) + '</b> <span class="ibx-muted">' + th('InboxPalette_' + o.p.id) + '</span></span>'
					+ '<span class="ibx-palette__swatches" aria-hidden="true">' + o.p.colors.map(c => '<span style="background:' + c + '"></span>').join('') + '</span>'
					+ '<span class="ibx-palette__tags">' + o.list.filter(tg => +tg.status === 1).map(tg => C.badge(tg)).join(' ') + '</span>'
					+ '<span class="ibx-muted">' + (o.risks ? th('InboxTagsPaletteRisks', o.risks) : th('InboxTagsPaletteNoRisk')) + '</span></label>').join('')
				+ '</div>',
			actions: [{ label: t('InboxTagsPaletteApply'), primary: true, run: async d => {
				const k = +($('[name="palette"]:checked', d) || { value: first }).value;
				await applyPalette(options[k].p.colors);
				return true;
			} }],
		});
		return dlg;
	}

	/**
	 * Same tags in Thunderbird: a tag is recognised by its keyword, never by its name. A tag created in Thunderbird
	 * gets a keyword made from its name (and keeps it when renamed): the lines of its configuration give it the keyword
	 * of the list, for the Config Editor or the file user.js of the profile
	 * @param {Array<Object>} tags Tags of the list
	 * @return {string} HTML
	 */
	function thunderbirdTags(tags) {
		const list = tags.filter(tg => tg.status);
		if (!list.length) {
			return '';
		}
		const lines = list.map((tg, n) => {
			const k = 'mailnews.tags.' + String(tg.keyword).toLowerCase();
			return 'user_pref(' + JSON.stringify(k + '.tag') + ', ' + JSON.stringify(tg.label) + ');\n'
				+ 'user_pref(' + JSON.stringify(k + '.color') + ', ' + JSON.stringify(String(tg.color || '#888888').toUpperCase()) + ');\n'
				+ 'user_pref(' + JSON.stringify(k + '.ordinal') + ', ' + JSON.stringify(String(n + 1).padStart(2, '0')) + ');';
		}).join('\n');
		return '<details class="ibx-guide__part" style="margin-top:1rem"><summary>' + th('InboxTagsThunderbird') + '</summary><div class="ibx-guide__body">'
			+ '<p>' + th('InboxTagsThunderbirdWhy') + '</p><p>' + th('InboxTagsThunderbirdHow') + '</p>' + guideCode(lines)
			+ '<p class="ibx-formrow__help">' + th('InboxTagsThunderbirdAfter') + '</p></div></details>';
	}

	// ---------- Mailbox: usage (1.67.0) and its own list of indicators (1.68.0) ----------
	const USAGE_ICONS = { auto: 'magic', sales: 'quote', accounting: 'calculator', support: 'tool', purchase: 'order', transport: 'truck', none: 'close' };
	/**
	 * What the card of the thirdparty of a mail shows in this mailbox: one usage, saved at once (undo in the toast);
	 * the indicators of a usage with a list may be added, removed and moved (drag and drop, arrows on the grip), saved
	 * at once too; « Back to the list of the usage » when the mailbox has its own
	 */
	function tabUsage(box, account) {
		const usages = Object.keys(account.usages || {});
		const cat = account.indicators || {};
		const label = id => (cat[id] ? cat[id].label : id);
		const plain = ids => '<ol class="ibx-usage__list">' + ids.map(id => '<li>' + esc(label(id)) + '</li>').join('') + '</ol>';
		const cur = () => account.usage_profile || 'auto';
		const list = () => (account.usage_own || account.usages[cur()] || []).slice();
		const listHtml = () => {
			const ids = list();
			return '<p class="ibx-section__help">' + th('InboxUsageListHelp') + (account.usage_own ? ' <b>' + th('InboxUsageOwnList') + '</b>' : '') + '</p>'
				+ (ids.length ? '<ol class="ibx-indlist">' + ids.map((id, n) => '<li class="ibx-indlist__item" data-id="' + esc(id) + '" draggable="true">'
					+ '<button type="button" class="ibx-indlist__grip ibx-js-indgrip" aria-label="' + esc(t('InboxIndMove', label(id))) + '" title="' + th('InboxIndMoveHelp') + '">' + ic('more-v') + '</button>'
					+ '<span class="ibx-indlist__label">' + esc(label(id)) + '</span>'
					+ (cat[id] && cat[id].rule ? '<span class="ibx-indlist__rule">' + esc(cat[id].rule) + '</span>' : '')
					+ '<button type="button" class="ibx-btn ibx-btn--ghost ibx-btn--icon ibx-btn--sm ibx-js-inddel" data-n="' + n + '" aria-label="' + esc(t('InboxIndRemove', label(id))) + '" title="' + th('InboxIndRemoveShort') + '">' + ic('close') + '</button>'
					+ '</li>').join('') + '</ol>' : '<p class="ibx-muted">' + th('InboxUsageListEmpty') + '</p>')
				+ '<div class="ibx-usage__tools"><button type="button" class="ibx-btn ibx-btn--sm ibx-js-indadd">' + ic('plus') + ' ' + th('InboxIndAdd') + '</button>'
				+ (account.usage_own ? '<button type="button" class="ibx-btn ibx-btn--sm ibx-btn--ghost ibx-js-indreset">' + th('InboxIndReset', t('InboxUsage_' + cur())) + '</button>' : '') + '</div>';
		};
		const paint = focusId => {
			const u = cur();
			let indicators;
			if (u === 'none') {
				indicators = '<p class="ibx-muted">' + th('InboxUsageNoneHelp') + '</p>';
			} else if (u === 'auto') {
				indicators = '<p class="ibx-section__help">' + th('InboxUsageAutoHelp') + '</p><div class="ibx-usage__auto">'
					+ '<div><h4>' + th('InboxUsageAutoCustomer') + '</h4>' + plain(account.usages.sales || []) + '</div>'
					+ '<div><h4>' + th('InboxUsageAutoSupplier') + '</h4>' + plain(account.usages.purchase || []) + '</div></div>';
			} else {
				indicators = listHtml();
			}
			// Usage Transport: the category of the suppliers that are carriers (1.70.0)
			if (u === 'transport' && (account.carrier_categories || []).length) {
				indicators += '<div class="ibx-formrow" style="margin-top:var(--ibx-s4)"><label class="ibx-formrow__label" for="ibx-carriers">' + th('InboxCarrierCategory') + '</label><div>'
					+ '<select class="ibx-field ibx-js-carriers" id="ibx-carriers" data-combo><option value="0">' + th('InboxCarrierNone') + '</option>'
					+ account.carrier_categories.map(c => '<option value="' + c.id + '"' + (c.id === account.carrier_category ? ' selected' : '') + '>' + esc(c.label) + '</option>').join('') + '</select>'
					+ '<div class="ibx-formrow__help">' + th('InboxCarrierHelp') + '</div></div></div>';
			}
			box.innerHTML = '<section class="ibx-section"><h2 class="ibx-section__title">' + th('InboxUsage') + '</h2>'
				+ '<p class="ibx-section__help">' + th('InboxUsageHelp') + '</p>'
				+ '<div class="ibx-usage" role="radiogroup" aria-label="' + th('InboxUsage') + '">'
				+ usages.map(x => '<label class="ibx-usage__opt"><input type="radio" name="usage" value="' + esc(x) + '"' + (x === u ? ' checked' : '') + '>' + ic(USAGE_ICONS[x] || 'magic')
					+ '<span><span class="ibx-usage__name">' + th('InboxUsage_' + x) + (x === 'auto' ? ' <em>' + th('InboxByDefault') + '</em>' : '') + '</span>'
					+ '<span class="ibx-usage__desc">' + th('InboxUsageDesc_' + x) + '</span></span></label>').join('')
				+ '</div></section>'
				+ '<section class="ibx-section"><h2 class="ibx-section__title">' + th('InboxUsageIndicators') + '</h2>' + indicators + '</section>';
			I.mount(box);
			if (focusId) {
				const g = $('.ibx-indlist__item[data-id="' + CSS.escape(focusId) + '"] .ibx-js-indgrip', box);
				if (g) {
					g.focus();
				}
			}
		};
		const saveUsage = async (usage, undo) => {
			const before = { usage: cur(), own: account.usage_own };
			try {
				const res = await post('account', 'usage', { id: account.id, usage });
				account.usage_profile = res.usage;
				account.usage_own = null;
			} catch (err) {
				toast(err.message, { error: true });
				paint();
				return;
			}
			paint();
			if (!undo) {
				toast(t('InboxUsageSaved', t('InboxUsage_' + usage)), { action: t('InboxUndo'), onAction: async () => {
					await saveUsage(before.usage, true);
					if (before.own) {
						saveList(before.own, '', true);
					}
				} });
			}
		};
		const saveList = async (ids, message, undo, focusId) => {
			const before = list();
			try {
				const res = await post('account', 'indicators', { id: account.id, ids: ids.join(',') });
				account.usage_own = res.usage_own;
			} catch (err) {
				toast(err.message, { error: true });
				paint();
				return;
			}
			paint(focusId);
			if (!undo && message) {
				toast(message, { action: t('InboxUndo'), onAction: () => saveList(before, '', true) });
			}
		};
		box.addEventListener('change', async e => {
			if (e.target.name === 'usage') {
				saveUsage(e.target.value, false);
				return;
			}
			if (e.target.classList.contains('ibx-js-carriers')) {
				try {
					const res = await post('account', 'carriers', { id: account.id, category: +e.target.value });
					account.carrier_category = res.carrier_category;
					toast(t('InboxCarrierSaved'));
				} catch (err) {
					toast(err.message, { error: true });
					paint();
				}
			}
		});
		box.addEventListener('click', e => {
			const del = e.target.closest('.ibx-js-inddel');
			if (del) {
				const ids = list();
				const [gone] = ids.splice(+del.dataset.n, 1);
				saveList(ids, t('InboxIndRemoved', label(gone)));
				return;
			}
			if (e.target.closest('.ibx-js-indreset')) {
				saveList(account.usages[cur()] || [], t('InboxIndResetDone'));
				return;
			}
			if (e.target.closest('.ibx-js-indadd')) {
				addIndicator();
			}
		});
		// Keyboard: arrows up and down on the grip move the indicator
		box.addEventListener('keydown', e => {
			const grip = e.target.closest('.ibx-js-indgrip');
			if (!grip || (e.key !== 'ArrowUp' && e.key !== 'ArrowDown')) {
				return;
			}
			e.preventDefault();
			const id = grip.closest('[data-id]').dataset.id;
			const ids = list();
			const i = ids.indexOf(id);
			const j = i + (e.key === 'ArrowUp' ? -1 : 1);
			if (j < 0 || j >= ids.length) {
				return;
			}
			ids.splice(i, 1);
			ids.splice(j, 0, id);
			saveList(ids, t('InboxIndOrderSaved'), false, id);
		});
		// Drag and drop: the indicator goes before the one it is dropped on
		let drag = '';
		box.addEventListener('dragstart', e => {
			const li = e.target.closest('.ibx-indlist__item');
			if (li) {
				drag = li.dataset.id;
				li.classList.add('is-dragging');
				e.dataTransfer.effectAllowed = 'move';
			}
		});
		box.addEventListener('dragend', () => $$('.ibx-indlist__item', box).forEach(x => x.classList.remove('is-dragging', 'is-over')));
		box.addEventListener('dragover', e => {
			const li = e.target.closest('.ibx-indlist__item');
			if (li && drag) {
				e.preventDefault();
				$$('.is-over', box).forEach(x => x !== li && x.classList.remove('is-over'));
				li.classList.add('is-over');
			}
		});
		box.addEventListener('drop', e => {
			const li = e.target.closest('.ibx-indlist__item');
			if (!li || !drag || li.dataset.id === drag) {
				return;
			}
			e.preventDefault();
			const ids = list().filter(x => x !== drag);
			ids.splice(ids.indexOf(li.dataset.id), 0, drag);
			drag = '';
			saveList(ids, t('InboxIndOrderSaved'));
		});
		/** Add an indicator: the ones not in the list, searched; added at the end */
		const addIndicator = () => {
			const current = list();
			const d = dialog({
				title: t('InboxIndAddTitle'),
				noCancel: false,
				body: '<input type="search" class="ibx-field ibx-js-indq" autocomplete="off" placeholder="' + th('InboxIndSearch') + '" aria-label="' + th('InboxIndSearch') + '">'
					+ '<div class="ibx-picklist ibx-js-indpick" role="listbox" aria-label="' + th('InboxUsageIndicators') + '"></div>'
					+ '<p class="ibx-formrow__help">' + th('InboxIndAddHelp') + '</p>',
			});
			const q = $('.ibx-js-indq', d);
			const pick = $('.ibx-js-indpick', d);
			const paintPick = () => {
				const s = q.value.trim().toLowerCase();
				const ids = Object.keys(cat).filter(id => !current.includes(id) && (!s || label(id).toLowerCase().includes(s)));
				pick.innerHTML = ids.length ? ids.map(id => '<div class="ibx-picklist__item" role="option" tabindex="-1" aria-selected="false" data-id="' + esc(id) + '"><span class="ibx-ellipsis" style="flex:1">' + esc(label(id)) + '</span>'
					+ (cat[id].rule ? '<span class="ibx-muted ibx-indpick__rule">' + esc(cat[id].rule) + '</span>' : '') + '</div>').join('')
					: '<div class="ibx-muted ibx-picklist__empty">' + th(current.length >= Object.keys(cat).length ? 'InboxIndAllIn' : 'InboxIndNoneFound') + '</div>';
			};
			const choose = it => {
				if (it) {
					d.close();
					saveList(current.concat(it.dataset.id), t('InboxIndAdded', label(it.dataset.id)));
				}
			};
			q.addEventListener('input', paintPick);
			pick.addEventListener('click', e => choose(e.target.closest('[data-id]')));
			q.addEventListener('keydown', e => {
				const items = $$('[data-id]', pick);
				if (!items.length) {
					return;
				}
				const i = items.findIndex(x => x.getAttribute('aria-selected') === 'true');
				if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
					e.preventDefault();
					items.forEach(x => x.setAttribute('aria-selected', 'false'));
					items[Math.max(0, Math.min(items.length - 1, i + (e.key === 'ArrowDown' ? 1 : -1)))].setAttribute('aria-selected', 'true');
				} else if (e.key === 'Enter' && i >= 0) {
					e.preventDefault();
					choose(items[i]);
				}
			});
			paintPick();
			q.focus();
		};
		paint();
	}

	async function tabTags(box, account) {
		box.innerHTML = skeleton(5);
		let data;
		try {
			data = await api('tag', 'list', account ? { id: account.id } : {});
		} catch (err) {
			box.replaceChildren(errorBox(err, () => tabTags(box, account)));
			return;
		}
		const own = data.mode === 'own';
		// From a mailbox, only its own list is changed: the global list, shared by every mailbox in global mode, is
		// read only here (admins included) and changed in Settings > General
		const editable = account ? own : isAdmin();
		let html = '';
		if (account) {
			html += '<p class="ibx-section__help">' + th('InboxTagsModeHelp') + '</p><div class="ibx-form" role="radiogroup" aria-label="' + th('InboxTagsMode') + '">'
				+ '<label class="ibx-radio"><input type="radio" name="tags_mode" value="global"' + (own ? '' : ' checked') + '> ' + th('InboxTagsModeGlobal') + '</label>'
				+ '<label class="ibx-radio"><input type="radio" name="tags_mode" value="own"' + (own ? ' checked' : '') + '> ' + th('InboxTagsModeOwn') + '</label></div>';
			if (!own) {
				html += '<div class="ibx-notice ibx-notice--info" style="margin-top:1rem">' + ic('info') + '<div class="ibx-notice__text">' + th('InboxTagsGlobalHere')
					+ '<div class="ibx-tagsglobal__actions">'
					+ '<button type="button" class="ibx-btn ibx-btn--sm ibx-js-makeown">' + ic('plus') + ' ' + th('InboxTagsMakeOwn') + '</button>'
					+ (isAdmin() ? '<a class="ibx-btn ibx-btn--sm ibx-btn--ghost" href="#/settings/general/tags">' + ic('settings') + ' ' + th('InboxTagsEditGlobal') + '</a>' : '')
					+ '</div></div></div>';
			}
		}
		html += '<table class="ibx-table ibx-js-tags" style="margin-top:1rem"><thead><tr><th>' + th('InboxTagKey') + '</th><th>' + th('InboxTagLabel') + '</th><th>' + th('InboxTagKeyword') + '</th><th>' + th('InboxTagColor') + '</th><th>' + th('InboxTagBorder') + '</th><th>' + th('InboxTagTint') + '</th><th>' + th('InboxStatus') + '</th><th></th></tr></thead><tbody>'
			+ data.tags.map((tg, n) => '<tr class="' + (editable ? 'ibx-dragrow' : '') + '" data-tag="' + tg.id + '">'
				// Drag handle and key in one cell, under the head « Key », to save a column
				+ '<td class="ibx-tagkey">' + (editable ? '<span class="ibx-draghandle" aria-hidden="true">' + ic('more-v') + '</span>' : '') + (tg.key ? '<span class="ibx-kbd">' + esc(tg.key) + '</span>' : '') + '</td>'
				+ '<td>' + (editable ? '<span class="ibx-tagprev-wrap"><input class="ibx-field" data-f="label" value="' + esc(tg.label) + '" aria-label="' + th('InboxTagLabel') + '"><span class="ibx-tagprev" aria-hidden="true" hidden></span></span>' : C.badge(tg)) + '</td>'
				+ '<td><code class="ibx-tagkeyword">' + esc(tg.keyword) + '</code></td>'
				+ '<td>' + (editable ? '<input type="color" class="ibx-color" data-f="color" value="' + esc(tg.color || '#888888') + '" aria-label="' + th('InboxTagColor') + '">' : '') + '<span class="ibx-tagrisk" data-risk hidden title="' + th('InboxTagsCvdRow') + '">' + ic('warning') + '<span class="ibx-sr-only">' + th('InboxTagsCvdRow') + '</span></span></td>'
				+ '<td>' + (editable ? borderSelect(tg.border, 'data-f="border"') : th('InboxTagBorder_' + (tg.border || 'none'))) + '</td>'
				+ '<td>' + (editable ? '<button type="button" class="ibx-switch" role="switch" data-f="tint" aria-checked="' + (tg.tint ? 'true' : 'false') + '" aria-label="' + th('InboxTagTint') + '"></button>' : (tg.tint ? ic('check') + '<span class="ibx-sr-only">' + th('InboxTagTint') + '</span>' : '')) + '</td>'
				+ '<td>' + (editable ? '<button type="button" class="ibx-switch" role="switch" data-f="status" aria-checked="' + (tg.status ? 'true' : 'false') + '" aria-label="' + th('InboxTagActive') + '"></button>' : (tg.status ? th('InboxAccountStatusActive') : th('InboxInactive'))) + '</td>'
				+ '<td class="ibx-table__actions">' + (editable ? '<button type="button" class="ibx-btn ibx-btn--sm ibx-btn--ghost ibx-btn--icon" data-move="-1" title="' + th('InboxMoveUp') + '" aria-label="' + th('InboxMoveUp') + '"' + (n === 0 ? ' aria-disabled="true"' : '') + '>' + ic('chevron-up') + '</button>'
					+ '<button type="button" class="ibx-btn ibx-btn--sm ibx-btn--ghost ibx-btn--icon" data-move="1" title="' + th('InboxMoveDown') + '" aria-label="' + th('InboxMoveDown') + '"' + (n === data.tags.length - 1 ? ' aria-disabled="true"' : '') + '>' + ic('chevron-down') + '</button>'
					+ '<button type="button" class="ibx-btn ibx-btn--sm ibx-btn--ghost ibx-btn--icon" data-deltag="1" title="' + th('InboxDelete') + '" aria-label="' + th('InboxDelete') + '">' + ic('delete') + '</button>' : '') + '</td></tr>').join('')
			+ '</tbody>';
		// New tag: a last row, each field under its column (a form cannot wrap a row: the fields join it by form="")
		const formId = 'ibx-addtag-' + (account ? account.id : 0);
		if (editable) {
			html += '<tfoot><tr class="ibx-tagadd"><td></td>'
				+ '<td><span class="ibx-tagprev-wrap"><input class="ibx-field" name="label" form="' + formId + '" placeholder="' + th('InboxNewTag') + '" aria-label="' + th('InboxNewTag') + '" maxlength="128"><span class="ibx-tagprev" aria-hidden="true" hidden></span></span></td>'
				+ '<td></td>'
				+ '<td><input type="color" class="ibx-color" name="color" form="' + formId + '" value="#0072b2" aria-label="' + th('InboxTagColor') + '"></td>'
				+ '<td>' + borderSelect('none', 'name="border" form="' + formId + '"') + '</td><td></td><td></td>'
				+ '<td class="ibx-table__actions"><button type="submit" form="' + formId + '" class="ibx-btn ibx-btn--primary ibx-btn--sm">' + ic('plus') + ' ' + th('InboxAddTag') + '</button></td></tr></tfoot>';
		}
		html += '</table>';
		if (editable) {
			html += '<form class="ibx-js-addtag" id="' + formId + '"></form>';
			html += '<p class="ibx-formrow__help">' + th('InboxTagsKeysHelp') + ' ' + th('InboxTagBorderHelp') + ' ' + th('InboxTagTintHelp') + ' ' + th('InboxTagsAliasHelp') + '</p>';
		}
		html += '<div class="ibx-js-cvd" aria-live="polite"></div>';
		html += thunderbirdTags(data.tags);
		box.innerHTML = html;
		copyCodeOn(box);
		const params = account ? { id: account.id } : {};
		const reload = () => {
			I.changed('tag', account ? account.id : 0);
			tabTags(box, account);
		};
		// Tags as shown now (the colour being picked included), in their order
		const current = () => $$('[data-tag]', box).map(row => {
			const tg = data.tags.find(x => x.id === +row.dataset.tag);
			const sw = $('[data-f="status"]', row);
			const col = $('[data-f="color"]', row);
			const lab = $('[data-f="label"]', row);
			const bor = $('[data-f="border"]', row);
			const tin = $('[data-f="tint"]', row);
			return Object.assign({}, tg, {
				label: lab ? lab.value : tg.label,
				color: col ? col.value.toUpperCase() : tg.color,
				border: bor ? bor.value : tg.border,
				tint: tin ? (tin.getAttribute('aria-checked') === 'true' ? 1 : 0) : tg.tint,
				status: sw ? (sw.getAttribute('aria-checked') === 'true' ? 1 : 0) : tg.status,
			});
		});
		const paintRisks = () => cvdWarning(box, current(), editable, applyPalette);
		const applyPalette = async colors => {
			const list = current();
			try {
				for (let i = 0; i < list.length; i++) {
					const tg = list[i];
					await post('tag', 'update', Object.assign({ tag_id: tg.id, label: tg.label, color: colors[i % colors.length], status: tg.status, border: tg.border || 'none', tint: tg.tint ? 1 : 0 }, params));
				}
				toast(t('InboxTagsPaletteApplied'));
			} catch (err) {
				toast(err.message, { error: true });
			}
			reload();
		};
		paintRisks();
		// Change of list confirmed first: going own copies the list (or takes the own one kept), going global leaves
		// the tags of this mailbox for the shared list
		const setMode = async mode => {
			const toOwn = mode === 'own';
			const ok = await confirmDialog({
				title: t(toOwn ? 'InboxTagsToOwnTitle' : 'InboxTagsToGlobalTitle'),
				text: t(toOwn ? (data.own_kept ? 'InboxTagsToOwnKeptText' : 'InboxTagsToOwnText') : 'InboxTagsToGlobalText'),
				confirm: t(toOwn ? 'InboxTagsToOwnConfirm' : 'InboxTagsToGlobalConfirm'),
			});
			if (!ok) {
				$$('[name="tags_mode"]', box).forEach(r => {
					r.checked = r.value === data.mode;
				});
				return;
			}
			try {
				await post('tag', 'mode', Object.assign({ mode }, params));
			} catch (err) {
				toast(err.message, { error: true });
			}
			reload();
		};
		$$('[name="tags_mode"]', box).forEach(r => r.addEventListener('change', () => setMode(r.value)));
		const makeOwn = $('.ibx-js-makeown', box);
		if (makeOwn) {
			makeOwn.addEventListener('click', () => setMode('own'));
		}
		const saveRow = async row => {
			const label = $('[data-f="label"]', row).value;
			const color = $('[data-f="color"]', row).value;
			const border = $('[data-f="border"]', row).value;
			const status = $('[data-f="status"]', row).getAttribute('aria-checked') === 'true' ? 1 : 0;
			const tint = $('[data-f="tint"]', row).getAttribute('aria-checked') === 'true' ? 1 : 0;
			try {
				await post('tag', 'update', Object.assign({ tag_id: row.dataset.tag, label, color, status, border, tint }, params));
				I.changed('tag', account ? account.id : 0);
			} catch (err) {
				toast(err.message, { error: true });
			}
		};
		// Result of the tag shown over its name while its colour or border is set (no column of its own), gone a
		// moment after the focus left them
		const previewOn = row => {
			const over = $('.ibx-tagprev', row);
			const lab = $('[data-f="label"], [name="label"]', row);
			const col = $('[data-f="color"], [name="color"]', row);
			const bor = $('[data-f="border"], [name="border"]', row);
			if (!over || !lab || !col || !bor) {
				return;
			}
			let timer = 0;
			const show = () => {
				clearTimeout(timer);
				const sw = $('[data-f="status"]', row);
				over.innerHTML = C.badge({ label: lab.value.trim() || lab.placeholder, color: col.value, border: bor.value });
				over.classList.toggle('is-off', !!sw && sw.getAttribute('aria-checked') !== 'true');
				over.classList.remove('is-leaving');
				over.hidden = false;
			};
			const hide = () => {
				clearTimeout(timer);
				over.hidden = true;
				over.classList.remove('is-leaving');
			};
			[col, bor].forEach(el => {
				el.addEventListener('focus', show);
				el.addEventListener(el === col ? 'input' : 'change', show);
				el.addEventListener('blur', e => {
					if (e.relatedTarget === col || e.relatedTarget === bor) {
						return;
					}
					clearTimeout(timer);
					timer = setTimeout(() => {
						over.classList.add('is-leaving');
						timer = setTimeout(hide, 300);
					}, 2500);
				});
			});
			lab.addEventListener('focus', hide);
		};
		if (editable) {
			$$('[data-tag], .ibx-tagadd', box).forEach(previewOn);
		}
		$$('[data-tag]', box).forEach(row => {
			if (!editable) {
				return;
			}
			$('[data-f="label"]', row).addEventListener('change', () => {
				saveRow(row);
				paintRisks();
			});
			$('[data-f="color"]', row).addEventListener('change', () => saveRow(row));
			// The warning follows the colour while it is being picked
			$('[data-f="color"]', row).addEventListener('input', paintRisks);
			$('[data-f="border"]', row).addEventListener('change', () => {
				saveRow(row);
				paintRisks();
			});
			$('[data-f="status"]', row).addEventListener('click', e => {
				const sw = e.currentTarget;
				sw.setAttribute('aria-checked', sw.getAttribute('aria-checked') === 'true' ? 'false' : 'true');
				saveRow(row).then(reload);
			});
			// Colour of the rows: saved at once (optimistic switch), the lists take it at their next display
			$('[data-f="tint"]', row).addEventListener('click', e => {
				const sw = e.currentTarget;
				sw.setAttribute('aria-checked', sw.getAttribute('aria-checked') === 'true' ? 'false' : 'true');
				saveRow(row);
			});
		});
		const order = () => $$('[data-tag]', box).map(r => +r.dataset.tag);
		const saveOrder = async ids => {
			try {
				await post('tag', 'reorder', Object.assign({ ids }, params));
				reload();
			} catch (err) {
				toast(err.message, { error: true });
			}
		};
		box.onclick = async e => {
			const mv = e.target.closest('[data-move]');
			if (mv && mv.getAttribute('aria-disabled') !== 'true') {
				const ids = order();
				const id = +mv.closest('[data-tag]').dataset.tag;
				const i = ids.indexOf(id);
				const j = i + (+mv.dataset.move);
				[ids[i], ids[j]] = [ids[j], ids[i]];
				saveOrder(ids);
				return;
			}
			const del = e.target.closest('[data-deltag]');
			if (del) {
				const row = del.closest('[data-tag]');
				const label = $('[data-f="label"]', row).value;
				const ok = await confirmDialog({ title: t('InboxDeleteTagTitle', label), text: t('InboxDeleteTagText'), confirm: t('InboxDelete'), danger: true });
				if (ok) {
					try {
						await post('tag', 'delete', Object.assign({ tag_id: row.dataset.tag }, params));
						reload();
					} catch (err) {
						toast(err.message, { error: true });
					}
				}
			}
		};
		// Drag and drop of the rows (with the up / down buttons for the keyboard and the phone)
		dragByHandle(box);
		let dragged = null;
		box.ondragstart = e => {
			dragged = e.target.closest('[data-tag]');
			if (dragged) {
				dragged.classList.add('is-dragging');
			}
		};
		box.ondragover = e => {
			const over = e.target.closest('[data-tag]');
			if (!dragged || !over || over === dragged) {
				return;
			}
			e.preventDefault();
			const r = over.getBoundingClientRect();
			over.parentNode.insertBefore(dragged, e.clientY < r.top + r.height / 2 ? over : over.nextSibling);
		};
		box.ondragend = () => {
			if (dragged) {
				dragged.classList.remove('is-dragging');
				dragged = null;
				saveOrder(order());
			}
		};
		const add = $('.ibx-js-addtag', box);
		if (add) {
			add.addEventListener('submit', async e => {
				e.preventDefault();
				try {
					await post('tag', 'create', Object.assign({ label: add.elements.label.value, color: add.elements.color.value, border: add.elements.border.value }, params));
					reload();
				} catch (err) {
					toast(err.message, { error: true });
					add.elements.label.focus();
				}
			});
		}
	}

	// ----- Tab Synchro -----
	let syncTimer = 0;
	async function tabSync(box, account) {
		clearTimeout(syncTimer);
		let st;
		try {
			st = await api('sync', 'status', { id: account.id });
		} catch (err) {
			box.replaceChildren(errorBox(err, () => tabSync(box, account)));
			return;
		}
		const pct = st.progress.total ? Math.min(100, Math.round(100 * st.progress.indexed / st.progress.total)) : 0;
		const stateCls = { ok: 'ok', first_sync: 'warning', auth_failed: 'error', unreachable: 'error', error: 'error' }[st.state] || '';
		const roleKey = r => 'InboxFolderRole' + r.charAt(0).toUpperCase() + r.slice(1);
		let html = '<p class="ibx-section__help">' + th('InboxSyncHelp') + '</p>';
		if (!st.active) {
			html += '<div class="ibx-notice ibx-notice--warning">' + ic('warning') + '<div class="ibx-notice__text">' + th('InboxSyncNeedsActive') + '</div></div>';
		}
		if (st.error && stateCls === 'error') {
			html += '<div class="ibx-notice ibx-notice--error" role="alert">' + ic('warning') + '<div class="ibx-notice__text"><b>' + th('InboxSyncState_' + st.state) + '</b> : ' + esc(st.error)
				+ (st.retry_at ? '<br>' + th('InboxSyncRetryAt', new Date(st.retry_at * 1000).toLocaleTimeString(I.locale())) : '') + '</div></div>';
		}
		html += '<div class="ibx-form"><div class="ibx-formrow"><span class="ibx-formrow__label">' + th('InboxStatus') + '</span><div>'
			+ (st.state ? '<span class="ibx-status' + (stateCls ? ' ibx-status--' + stateCls : '') + '">' + th('InboxSyncState_' + st.state) + '</span>' : '<span class="ibx-status">' + th('InboxSyncWaiting') + '</span>')
			+ (st.running ? ' · ' + th('InboxSyncRunning') : '') + (st.date_sync ? ' · ' + th('InboxSyncLast', ago(st.date_sync)) : '') + '</div></div>'
			+ '<div class="ibx-formrow"><span class="ibx-formrow__label">' + th('InboxSyncIndexed') + '</span><div>'
			+ '<progress max="100" value="' + pct + '" style="width:16rem;vertical-align:middle" aria-label="' + th('InboxSyncIndexed') + '"></progress> '
			+ th('InboxSyncIndexedOf', st.progress.indexed.toLocaleString(I.locale()), st.progress.total.toLocaleString(I.locale()))
			+ (st.progress.first_sync_left ? ' · ' + th('InboxSyncFoldersLeft', st.progress.first_sync_left) : '') + '</div></div>'
				+ (st.quota ? '<div class="ibx-formrow"><span class="ibx-formrow__label">' + th('InboxQuota') + '</span><div>'
					+ '<meter min="0" max="' + st.quota.limit + '" low="' + Math.round(st.quota.limit * 0.8) + '" high="' + Math.round(st.quota.limit * 0.9) + '" optimum="0" value="' + st.quota.used + '" style="width:16rem;vertical-align:middle" aria-label="' + th('InboxQuota') + '"></meter> '
					+ th('InboxQuotaUsed', (st.quota.used / 1024).toLocaleString(I.locale(), { maximumFractionDigits: 0 }), (st.quota.limit / 1024).toLocaleString(I.locale(), { maximumFractionDigits: 0 }), Math.round(100 * st.quota.used / st.quota.limit))
					+ (st.quota.used >= st.quota.limit * 0.9 ? ' <span class="ibx-status ibx-status--warning">' + th('InboxQuotaAlmostFull', Math.round(100 * st.quota.used / st.quota.limit)) + '</span>' : '')
					+ '</div></div>' : '')
				+ '</div>';
		html += '<div class="ibx-formfoot" style="border-top:0"><button type="button" class="ibx-btn ibx-btn--primary ibx-js-now"'
			+ (st.active ? '' : ' aria-disabled="true" title="' + th('InboxSyncNeedsActive') + '"') + '>' + ic('check') + ' ' + th('InboxSyncNow') + '</button>'
			+ '<span class="ibx-formfoot__spacer"></span>' + (isAdmin() ? '<button type="button" class="ibx-btn ibx-js-rebuild">' + th('InboxSyncRebuild') + '</button>' : '') + '</div>';
		html += '<section class="ibx-section"><h2 class="ibx-section__title">' + th('InboxSyncFolders') + '</h2><p class="ibx-section__help">' + th('InboxSyncFoldersHelp') + '</p>';
		if (!st.folders.length) {
			html += '<p class="ibx-muted">' + th('InboxSyncNoFolder') + '</p>';
		} else {
			html += '<table class="ibx-table"><thead><tr><th>' + th('InboxFolder') + '</th><th>' + th('InboxSyncFollowed') + '</th><th>' + th('InboxSyncMails') + '</th><th>'
				+ th('InboxSyncIndexed') + '</th><th>' + th('InboxSyncLastPass') + '</th></tr></thead><tbody>'
				+ st.folders.map(f => '<tr><td>' + (f.role === 'inbox' ? th('InboxFolderRoleInbox') : esc(f.label) + (f.role ? ' <span class="ibx-muted">(' + th(roleKey(f.role)) + ')</span>' : ''))
					+ (f.error ? '<div class="ibx-formrow__error">' + esc(f.error) + '</div>' : '') + '</td>'
					+ '<td>' + (f.selectable ? '<button type="button" class="ibx-switch" role="switch" data-folder="' + f.id + '" aria-checked="' + (f.sync_enabled ? 'true' : 'false')
						+ '" aria-label="' + th('InboxSyncFollowed') + ' ' + esc(f.label) + '"></button>' : '<span class="ibx-muted">-</span>') + '</td>'
					+ '<td>' + esc(f.total.toLocaleString(I.locale())) + (f.unread ? ' <span class="ibx-muted">(' + th('InboxSyncUnread', f.unread) + ')</span>' : '') + '</td>'
					+ '<td>' + (f.sync_enabled ? esc(f.indexed.toLocaleString(I.locale())) + (f.first_sync_done ? '' : ' <span class="ibx-status ibx-status--warning">' + th('InboxSyncInProgress') + '</span>') : '<span class="ibx-muted">-</span>') + '</td>'
					+ '<td class="ibx-muted">' + (f.date_sync ? esc(ago(f.date_sync)) : '-') + '</td></tr>').join('')
				+ '</tbody></table>';
		}
		html += '</section>';
		html += '<div class="ibx-js-ftbox-holder"></div>';
		box.innerHTML = html;
		if (isAdmin()) {
			tabSyncFulltext($('.ibx-js-ftbox-holder', box), account);
		}
		const now = $('.ibx-js-now', box);
		now.addEventListener('click', async () => {
			if (now.getAttribute('aria-disabled') === 'true') {
				toast(now.title, { error: true });
				return;
			}
			now.setAttribute('aria-disabled', 'true');
			now.innerHTML = ic('check') + ' ' + th('InboxSyncRunning');
			try {
				const r = await post('sync', 'now', { id: account.id });
				toast(t('InboxSyncDone', r.stats.new, r.stats.changed, r.stats.deleted));
				I.changed('account', account.id);
			} catch (err) {
				toast(err.message, { error: true });
			}
			tabSync(box, account);
		});
		const rebuild = $('.ibx-js-rebuild', box);
		if (rebuild) {
			rebuild.addEventListener('click', async () => {
				const ok = await confirmDialog({ title: t('InboxSyncRebuildTitle', account.email), text: t('InboxSyncRebuildText'), confirm: t('InboxSyncRebuild') });
				if (ok) {
					try {
						await post('sync', 'rebuild', { id: account.id });
						toast(t('InboxSyncRebuilt'));
					} catch (err) {
						toast(err.message, { error: true });
					}
					tabSync(box, account);
				}
			});
		}
		$$('[data-folder]', box).forEach(sw => sw.addEventListener('click', async () => {
			const on = sw.getAttribute('aria-checked') !== 'true';
			sw.setAttribute('aria-checked', on ? 'true' : 'false');
			try {
				await post('sync', 'folder', { id: account.id, folder_id: sw.dataset.folder, enabled: on ? 1 : 0 });
				toast(t(on ? 'InboxSyncFolderOn' : 'InboxSyncFolderOff'));
			} catch (err) {
				sw.setAttribute('aria-checked', on ? 'false' : 'true');
				toast(err.message, { error: true });
			}
		}));
		// Follow the first indexing while the tab is shown
		if (st.running || st.state === 'first_sync') {
			syncTimer = setTimeout(() => {
				if (box.isConnected && !document.hidden) {
					tabSync(box, account);
				}
			}, 5000);
		}
	}

	// ---------- Import ----------
	async function renderImport(main) {
		if (!isAdmin()) {
			I.go('#/settings/accounts');
			return;
		}
		main.innerHTML = skeleton();
		let sources;
		try {
			sources = (await api('import', 'sources')).sources;
		} catch (err) {
			main.replaceChildren(errorBox(err, () => renderImport(main)));
			return;
		}
		// The sources declared by modules first (the default one); Thunderbird (QR codes of « Export for mobile »)
		// always there, last
		const all = sources.concat([{ code: '', label: t('InboxImportSourceQr') }]);
		main.innerHTML = '<div class="ibx-page"><a class="ibx-page__back" href="#/settings/accounts">' + ic('chevron-left') + ' ' + th('InboxNavAccounts') + '</a><div class="ibx-page__head"><h1 class="ibx-page__title">' + th('InboxNavImport') + '</h1></div>'
			+ '<div class="ibx-formrow"' + (sources.length ? '' : ' hidden') + '><label class="ibx-formrow__label" for="f-source">' + th('InboxImportSource') + '</label><div><select class="ibx-field ibx-field--medium" id="f-source">' + all.map(s => '<option value="' + esc(s.code) + '">' + esc(s.label) + '</option>').join('') + '</select></div></div>'
			+ '<div class="ibx-js-importlist" style="margin-top:1rem"></div></div>';
		const sel = $('#f-source', main);
		const listBox = $('.ibx-js-importlist', main);
		const load = async () => {
			if (!sel.value) {
				I.importQr.render(listBox);
				return;
			}
			listBox.innerHTML = skeleton(3);
			let accounts;
			try {
				accounts = (await api('import', 'list', { source: sel.value })).accounts;
			} catch (err) {
				listBox.replaceChildren(errorBox(err, load));
				return;
			}
			if (!accounts.length) {
				listBox.innerHTML = '<p class="ibx-page__intro">' + th('InboxImportIntro') + '</p><div class="ibx-notice ibx-notice--info">' + ic('check') + '<div class="ibx-notice__text">' + th('InboxImportNothing') + '</div></div>';
				return;
			}
			listBox.innerHTML = '<p class="ibx-page__intro">' + th('InboxImportIntro') + '</p><table class="ibx-table"><thead><tr><th><input type="checkbox" class="ibx-js-all" checked aria-label="' + th('InboxSelectAll') + '"></th><th>' + th('InboxAccount') + '</th><th>' + th('InboxImportWhat') + '</th></tr></thead><tbody>'
				+ accounts.map(a => '<tr><td><input type="checkbox" name="email" value="' + esc(a.email) + '" checked aria-label="' + esc(a.email) + '"></td><td><b>' + esc(a.label || a.email) + '</b><div class="ibx-muted">' + esc(a.email) + '</div></td>'
					+ '<td>' + esc(t(a.type === 'personal' ? 'InboxTypePersonal' : 'InboxTypeShared')) + ' · ' + (a.imap_host ? esc(a.imap_host) : '<span class="ibx-status ibx-status--warning">' + th('InboxImportNoServer') + '</span>')
					+ ' · ' + esc(t(a.imap_auth_mode === 'master' ? 'InboxAuthMaster' : (a.has_password ? 'InboxImportWithPassword' : 'InboxImportNoPassword'))) + '</td></tr>').join('')
				+ '</tbody></table><p class="ibx-formrow__help">' + th('InboxImportAfter') + '</p><div class="ibx-formfoot"><span class="ibx-formfoot__spacer"></span><button type="button" class="ibx-btn ibx-btn--primary ibx-js-run">' + th('InboxImportRun') + '</button></div>';
			$('.ibx-js-all', listBox).addEventListener('change', e => $$('[name="email"]', listBox).forEach(c => {
				c.checked = e.target.checked;
			}));
			$('.ibx-js-run', listBox).addEventListener('click', async e => {
				const emails = $$('[name="email"]:checked', listBox).map(c => c.value);
				if (!emails.length) {
					toast(t('InboxImportChoose'), { error: true });
					return;
				}
				e.currentTarget.setAttribute('aria-disabled', 'true');
				listBox.insertAdjacentHTML('beforeend', '<div class="ibx-notice ibx-notice--info" role="status">' + ic('info') + '<div class="ibx-notice__text">' + th('InboxImportRunning', emails.length) + '</div></div>');
				try {
					const r = await post('import', 'run', { source: sel.value, emails });
					I.changed('account', 0);
					const ok = r.tests.filter(x => x.status !== 'error').length;
					const ko = r.tests.filter(x => x.status === 'error');
					listBox.innerHTML = '<div class="ibx-notice ' + (ko.length ? 'ibx-notice--warning' : 'ibx-notice--info') + '" role="status">' + ic(ko.length ? 'warning' : 'check') + '<div class="ibx-notice__text"><b>' + th('InboxImportDone', Object.keys(r.report.created).length, ok, ko.length) + '</b>'
						+ (ko.length ? '<ul>' + ko.map(x => '<li>' + esc(x.email) + ' : ' + esc(x.message) + '</li>').join('') + '</ul>' : '')
						+ (Object.keys(r.report.errors).length ? '<ul>' + Object.entries(r.report.errors).map(([m, x]) => '<li>' + esc(m) + ' : ' + esc(x) + '</li>').join('') + '</ul>' : '')
						+ '<p>' + th('InboxImportNext') + '</p></div></div><a class="ibx-btn ibx-btn--primary" href="#/settings/accounts">' + th('InboxNavAccounts') + '</a>';
				} catch (err) {
					toast(err.message, { error: true });
					load();
				}
			});
		};
		sel.addEventListener('change', load);
		load();
	}

	/**
	 * Section « Writing editor » of Settings > General (1.94.0): the editor of inbox or the CKEditor of Dolibarr, and for
	 * CKEditor the toolbar and menu of inbox or its own ones
	 * @param {object} s     Settings
	 * @param {object} info  {ckeditor: 1 when the module FCKeditor is on, module_label: its name in the setup}
	 * @param {string} head  Title of the section
	 * @returns {string}
	 */
	function editorSection(s, info, head) {
		const editor = info.ckeditor && s.INBOX_EDITOR === 'ckeditor' ? 'ckeditor' : 'tiptap';
		const radios = (name, current, options) => '<div class="ibx-inline" role="radiogroup" aria-labelledby="' + name + '-label">'
			+ options.map(o => '<label class="ibx-radio"><input type="radio" name="' + name + '" value="' + o[0] + '"' + (o[0] === current ? ' checked' : '') + (o[2] ? ' disabled' : '') + '> ' + th(o[1]) + '</label>').join(' ') + '</div>';
		return '<section class="ibx-section" data-section="editor">' + head
			+ (info.ckeditor ? '' : '<div class="ibx-notice">' + ic('info') + '<div class="ibx-notice__text">' + th('InboxEditorNoCkeditor', info.module_label) + '</div></div>')
			+ '<div class="ibx-form ibx-js-editorset">'
			+ '<div class="ibx-formrow"><span class="ibx-formrow__label" id="g-editor-label">' + th('InboxEditorEngine') + '</span><div>'
			+ radios('g-editor', editor, [['tiptap', 'InboxEditor_tiptap'], ['ckeditor', 'InboxEditor_ckeditor', !info.ckeditor]])
			+ '<div class="ibx-formrow__help">' + th('InboxEditorHelp') + '</div></div></div>'
			+ '<div class="ibx-formrow ibx-js-ckbar"' + (editor === 'ckeditor' ? '' : ' hidden') + '><span class="ibx-formrow__label" id="g-ckbar-label">' + th('InboxCkeditorBar') + '</span><div>'
			+ radios('g-ckbar', s.INBOX_CKEDITOR_NATIVE === '1' ? '1' : '0', [['0', 'InboxCkeditorBar_inbox'], ['1', 'InboxCkeditorBar_native']])
			+ '<div class="ibx-formrow__help">' + th('InboxCkeditorBarHelp') + '</div></div></div>'
			// Small screens (1.101.0): CKEditor offered only when available
			+ '<div class="ibx-formrow"><span class="ibx-formrow__label" id="g-small-label">' + th('InboxEditorSmall') + '</span><div>'
			+ radios('g-small', ['ckeditor', 'same'].includes(s.INBOX_EDITOR_SMALL) && info.ckeditor ? s.INBOX_EDITOR_SMALL : 'tiptap',
				[['tiptap', 'InboxEditorSmall_tiptap'], ['ckeditor', 'InboxEditorSmall_ckeditor', !info.ckeditor], ['same', 'InboxEditorSmall_same']])
			+ '<div class="ibx-formrow__help">' + th('InboxEditorSmallHelp') + '</div></div></div>'
			+ '</div></section>';
	}
	/** The choices of the editor saved at once; the writings opened next use them (no page to load again) */
	function bindEditorSection(main, s, info) {
		const box = $('.ibx-js-editorset', main);
		if (!box) {
			return;
		}
		const locked = s.locked || [];
		$$('[name="g-editor"]', box).forEach(rb => {
			rb.disabled = rb.disabled || locked.includes('INBOX_EDITOR');
		});
		$$('[name="g-ckbar"]', box).forEach(rb => {
			rb.disabled = locked.includes('INBOX_CKEDITOR_NATIVE');
		});
		$$('[name="g-small"]', box).forEach(rb => {
			rb.disabled = rb.disabled || locked.includes('INBOX_EDITOR_SMALL');
		});
		const save = async (name, value, ctxValue, back) => {
			try {
				await post('general', 'set', { name, value });
			} catch (err) {
				back();
				toast(err.message, { error: true });
				return;
			}
			s[name] = value;
			if (I.editors) {
				I.editors.choose(name, ctxValue);
			}
			toast(t('InboxEditorSaved'));
		};
		$$('[name="g-editor"]', box).forEach(rb => rb.addEventListener('change', () => {
			const before = s.INBOX_EDITOR === 'ckeditor' && info.ckeditor ? 'ckeditor' : 'tiptap';
			$('.ibx-js-ckbar', box).hidden = rb.value !== 'ckeditor';
			save('INBOX_EDITOR', rb.value, rb.value, () => {
				$('[name="g-editor"][value="' + before + '"]', box).checked = true;
				$('.ibx-js-ckbar', box).hidden = before !== 'ckeditor';
			});
		}));
		$$('[name="g-small"]', box).forEach(rb => rb.addEventListener('change', () => {
			const before = ['ckeditor', 'same'].includes(s.INBOX_EDITOR_SMALL) ? s.INBOX_EDITOR_SMALL : 'tiptap';
			save('INBOX_EDITOR_SMALL', rb.value, rb.value, () => {
				$('[name="g-small"][value="' + before + '"]', box).checked = true;
			});
		}));
		$$('[name="g-ckbar"]', box).forEach(rb => rb.addEventListener('change', () => {
			const before = s.INBOX_CKEDITOR_NATIVE === '1' ? '1' : '0';
			save('INBOX_CKEDITOR_NATIVE', rb.value, rb.value === '1', () => {
				$('[name="g-ckbar"][value="' + before + '"]', box).checked = true;
			});
		}));
	}

	/** Fields of the general settings (General, Security): a value saved when changed, a switch at once */
	/**
	 * @param {HTMLElement} main   Screen
	 * @param {string[]}    locked Settings this user sees without changing them (their right, ajax/general.php)
	 */
	function bindGeneralFields(main, locked) {
		const off = name => (locked || []).includes(name);
		$$('[data-name]', main).forEach(f => {
			if (off(f.dataset.name)) {
				f.readOnly = true;
				f.disabled = f.tagName === 'SELECT';
			}
		});
		$$('.ibx-js-gswitch', main).forEach(sw => {
			if (off(sw.dataset.switch)) {
				sw.disabled = true;
			}
		});
		$$('[data-name]', main).filter(f => !off(f.dataset.name)).forEach(f => f.addEventListener('change', async () => {
			try {
				await post('general', 'set', { name: f.dataset.name, value: f.value });
				toast(t('InboxSaved'));
			} catch (err) {
				toast(err.message, { error: true });
				f.focus();
			}
		}));
		// Switches: saved at once, back if the server refuses
		$$('.ibx-js-gswitch', main).filter(sw => !off(sw.dataset.switch)).forEach(sw => sw.addEventListener('click', async () => {
			const on = sw.getAttribute('aria-checked') !== 'true';
			sw.setAttribute('aria-checked', on ? 'true' : 'false');
			try {
				await post('general', 'set', { name: sw.dataset.switch, value: on ? '1' : '0' });
				if (sw.dataset.switch === 'INBOX_BANK_CHECK') {
					$('.ibx-js-bankoff', main).hidden = on;
				}
			} catch (err) {
				sw.setAttribute('aria-checked', on ? 'false' : 'true');
				toast(err.message, { error: true });
			}
		}));
	}

	/**
	 * Outside services (1.88.3): every function of inbox that asks a service out of our servers, what leaves and to
	 * whom, its state, the way to its setting. All off by default, turned on by the administrator.
	 */
	const OUTSIDE_SERVICES = [
		['INBOX_AVATAR_GRAVATAR', 'InboxAvatarGravatar', 'gravatar', '#/settings/general/avatars'],
		['INBOX_AVATAR_BIMI', 'InboxAvatarBimi', 'bimi', '#/settings/general/avatars'],
		['INBOX_AI_ENABLED', 'InboxAiEnabled', 'ai', '#/settings/general/ai'],
		['INBOX_DICTATION_ENABLED', 'InboxDictationEnabled', 'dictation', '#/settings/general/dictation'],
		['INBOX_RSPAMD_DNSLISTS', 'InboxRspamdDnsLists', 'dnslists', '#/settings/antispam/settings'],
		['INBOX_AUTOCONFIG_ISPDB', 'InboxAutoconfigIspdbSetting', 'ispdb', '#/settings/general/newbox'],
		// Nothing to turn on (1.111.0): the DNS of a DMARC report is asked only when the user clicks, said here
		['', 'InboxDmarcOutsideLabel', 'dmarcdns', ''],
	];
	function outsideServices(s) {
		return '<section class="ibx-section ibx-outside" data-section="outside"><h2 class="ibx-section__title">' + ic('shield') + ' ' + th('InboxOutsideTitle') + '</h2>'
			+ '<p class="ibx-muted">' + th('InboxOutsideHelp') + '</p>'
			+ '<table class="ibx-table"><thead><tr><th>' + th('InboxOutsideService') + '</th><th>' + th('InboxOutsideWhat') + '</th><th>' + th('InboxOutsideState') + '</th><th></th></tr></thead><tbody>'
			+ OUTSIDE_SERVICES.map(([k, label, id, href]) => {
				const on = k !== '' && s[k] === '1';
				// Gravatar and BIMI explained by the glossary (their labels are the ones of Settings > General)
				return '<tr><td data-label="' + th('InboxOutsideService') + '"><b>' + (['gravatar', 'bimi'].includes(id) ? I.term(id, t(label)) : th(label)) + '</b></td>'
					+ '<td data-label="' + th('InboxOutsideWhat') + '">' + I.thTerms('InboxOutsideWhat_' + id) + '</td>'
					+ '<td data-label="' + th('InboxOutsideState') + '"><span class="ibx-status' + (on ? ' ibx-status--warning' : '') + '">' + th(k === '' ? 'InboxOutsideOnClick' : (on ? 'InboxOutsideOn' : 'InboxOutsideOff')) + '</span></td>'
					+ '<td class="ibx-table__actions">' + (href ? '<a class="ibx-btn ibx-btn--sm ibx-btn--ghost" href="' + href + '">' + th('InboxOutsideSet') + '</a>' : '') + '</td></tr>';
			}).join('')
			+ '</tbody></table></section>';
	}

	// ---------- Security (lot F1 1.78.0, admins): what protects the users, each choice with what it protects from ----------
	async function renderSecurity(main) {
		if (!isAdmin()) {
			I.go('#/settings/accounts');
			return;
		}
		main.innerHTML = skeleton();
		let s;
		try {
			const g = await api('general', 'get');
			s = g.settings;
			s.locked = g.locked || [];
		} catch (err) {
			main.replaceChildren(errorBox(err, () => renderSecurity(main)));
			return;
		}
		main.innerHTML = '<div class="ibx-page"><div class="ibx-page__head"><h1 class="ibx-page__title">' + th('InboxNavSecurity') + '</h1></div><p class="ibx-page__intro">' + th('InboxSecurityHelp') + '</p>'
			+ outsideServices(s)
			+ '<div class="ibx-form ibx-js-general">'
			+ [['INBOX_QUOTE_REMOTE_IMAGES', 'g-quoteimg', 'InboxQuoteRemoteImages', ['keep', 'reply', 'all']], ['INBOX_IMAGES_ALWAYS', 'g-imgalways', 'InboxImagesAlways', ['domain', 'sender', 'never']],
				['INBOX_UNSUBSCRIBE', 'g-unsub', 'InboxUnsubscribeSetting', ['oneclick', 'mail', 'off']]].map(([k, id, label, values]) => '<div class="ibx-formrow"><label class="ibx-formrow__label" for="' + id + '">' + th(label) + '</label><div>'
				+ '<select class="ibx-field ibx-field--medium" id="' + id + '" data-name="' + k + '">' + values.map(v => '<option value="' + v + '"' + (v === s[k] ? ' selected' : '') + '>' + th(label + '_' + v) + '</option>').join('') + '</select>'
				+ '<div class="ibx-formrow__help">' + th(label + 'Help') + '</div></div></div>').join('')
			+ '<div class="ibx-formrow"><span class="ibx-formrow__label">' + th('InboxBankCheckSetting') + '</span><div>'
			+ '<button type="button" class="ibx-switch ibx-js-gswitch" role="switch" data-switch="INBOX_BANK_CHECK" aria-checked="' + (s.INBOX_BANK_CHECK === '0' ? 'false' : 'true') + '" aria-label="' + th('InboxBankCheckSetting') + '"></button>'
			+ '<div class="ibx-formrow__help">' + th('InboxBankCheckSettingHelp') + '</div>'
			+ '<div class="ibx-formrow__help ibx-formrow__warn ibx-js-bankoff"' + (s.INBOX_BANK_CHECK === '0' ? '' : ' hidden') + '>' + ic('warning') + ' ' + th('InboxBankCheckOffWarning') + '</div></div></div>'
			// Trusted receiving servers: in Anti-spam since 1.85.0, said here where they were
			+ '<div class="ibx-formrow"><span class="ibx-formrow__label">' + th('InboxAuthServers') + '</span><div><a href="#/settings/antispam/settings">' + th('InboxAuthServersMoved') + '</a></div></div>'
			+ '<div class="ibx-formrow"><label class="ibx-formrow__label" for="g-av">' + th('InboxAntivirusMax') + '</label><div><div class="ibx-inline"><input class="ibx-field ibx-field--narrow" type="number" min="1" max="200" inputmode="numeric" id="g-av" data-name="INBOX_ANTIVIRUS_MAX_MB" value="' + esc(s.INBOX_ANTIVIRUS_MAX_MB) + '"> ' + th('InboxMegabytes') + '</div><div class="ibx-formrow__help">' + th('InboxAntivirusMaxHelp') + '</div></div></div>'
			+ '</div></div>';
		bindGeneralFields(main, s.locked);
	}


	/**
	 * Settings > Anti-spam (1.85.0), in tabs: the blocked senders (every user of the webmail, a list as long as it
	 * needs, by pages), and for the administrators the state of the block on each mailbox and the settings (every
	 * function has its switch). Analyses: analyse-anti-spam.md, analyse-expediteurs-bloques.md
	 */
	const ANTISPAM_TABS = [['blocked', 'InboxAntispamBlocked'], ['boxes', 'InboxAntispamBoxes'], ['settings', 'InboxAntispamSettings']];
	async function renderAntispam(main, parts, state) {
		const admin = isAdmin();
		let tab = (parts && parts[0]) || 'blocked';
		// Old link of Security (servers of trust)
		tab = tab === 'server' ? 'settings' : tab;
		if (!admin || !ANTISPAM_TABS.some(x => x[0] === tab)) {
			tab = 'blocked';
		}
		state = state || { search: '', type: '', page: 0 };
		const listOnly = tab === 'blocked' && main.querySelector('.ibx-js-bslist') && main.dataset.antispam === 'blocked';
		if (!listOnly) {
			main.innerHTML = skeleton();
		}
		let r;
		let s = null;
		try {
			r = await api('blockedsender', 'list', state.type === 'known' ? Object.assign({}, state, { type: '', known: 1 }) : state);
			if (admin && tab === 'settings') {
				const g = await api('general', 'get');
				s = g.settings;
				// Settings seen without being changed (their right is in their definition, ajax/general.php)
				s.locked = g.locked || [];
				s.rspamd = await api('rspamd', 'state');
			}
		} catch (err) {
			main.replaceChildren(errorBox(err, () => renderAntispam(main, parts, state)));
			return;
		}
		const go = (st) => renderAntispam(main, [tab], st);
		if (listOnly) {
			// A search, a filter or a page: the list only, the field keeps the focus
			$('.ibx-js-bslist', main).innerHTML = blockedListHtml(r, state);
			main._bs = { r, state };
			return;
		}
		main.dataset.antispam = tab;
		const pending = r.update_pending;
		let html = '<div class="ibx-page ibx-js-antispam"><div class="ibx-page__head"><h1 class="ibx-page__title">' + th('InboxNavAntispam') + '</h1></div>'
			// An update not finished is told by the bar of the application (js/inbox.js); the tab of the mailboxes says why it is empty
			+ '<p class="ibx-page__intro">' + th('InboxAntispamHelp') + '</p>';
		if (admin) {
			html += '<div class="ibx-tabs" role="tablist">' + ANTISPAM_TABS.map(([id, key]) => '<a class="ibx-tab" role="tab" href="#/settings/antispam/' + id + '" aria-selected="' + (id === tab) + '">' + th(key)
				+ (id === 'blocked' && r.all ? ' <span class="ibx-badge">' + r.all + '</span>' : '') + '</a>').join('') + '</div>';
		}
		if (tab === 'blocked') {
			html += '<p class="ibx-muted">' + th('InboxAntispamBlockedHelp') + '</p>'
				+ (r.enabled ? '' : '<div class="ibx-notice ibx-notice--info">' + ic('info') + '<div class="ibx-notice__text">' + th('InboxBlockedOff') + '</div></div>')
				// Became known to Dolibarr since the blocking (nightly check, 1.87.0): the managers decide
				+ (r.known && r.manager && state.type !== 'known' ? '<div class="ibx-notice ibx-notice--warning">' + ic('warning') + '<div class="ibx-notice__text">' + th('InboxBlockedBecameKnownCount', r.known)
					+ ' <button type="button" class="ibx-linkbtn ibx-js-bsknown">' + th('InboxBlockedBecameKnownShow') + '</button></div></div>' : '')
				+ (r.all > r.many ? '<div class="ibx-notice ibx-notice--warning">' + ic('warning') + '<div class="ibx-notice__text">' + th('InboxBlockedMany', r.many) + '</div></div>' : '')
				+ '<div class="ibx-bstools"><input class="ibx-field ibx-field--medium ibx-js-bssearch" type="search" placeholder="' + th('InboxBlockedFilter') + '" aria-label="' + th('InboxBlockedFilter') + '" spellcheck="false" value="' + esc(state.search) + '">'
				+ '<select class="ibx-field ibx-field--small ibx-js-bstype" aria-label="' + th('InboxBlockedType') + '">' + [['', 'InboxBlockedTypeAll'], ['address', 'InboxBlockedTypeAddress'], ['domain', 'InboxBlockedTypeDomain'], ['known', 'InboxBlockedTypeKnown']]
					.map(([v, k]) => '<option value="' + v + '"' + (v === state.type ? ' selected' : '') + '>' + th(k) + '</option>').join('') + '</select>'
				+ (r.manager ? '<span class="ibx-spacer"></span><button type="button" class="ibx-btn ibx-js-bsadd">' + ic('plus') + ' ' + th('InboxBlockedAdd') + '</button>' : '') + '</div>'
				+ '<div class="ibx-js-bslist">' + blockedListHtml(r, state) + '</div>';
		} else if (tab === 'boxes') {
			const states = r.states || [];
			const badge = x => {
				const cls = { ok: 'ok', pending: 'warning', foreign: 'warning', nosieve: '', error: 'error' }[x.state] || '';
				return '<span class="ibx-status' + (cls ? ' ibx-status--' + cls : '') + '"' + (x.error ? ' title="' + esc(x.error) + '"' : '') + '>' + th('InboxBlockedState_' + x.state) + '</span>';
			};
			html += '<p class="ibx-muted">' + th('InboxAntispamBoxesHelp') + '</p>'
				+ (states.length ? '<table class="ibx-table"><thead><tr><th>' + th('InboxNavAccounts') + '</th><th>' + th('InboxAntispamBoxState') + '</th><th></th></tr></thead><tbody>'
					+ states.map(x => '<tr><td data-label="' + th('InboxNavAccounts') + '"><b>' + esc(x.label || x.email) + '</b>' + (x.label ? '<div class="ibx-muted">' + esc(x.email) + '</div>' : '') + '</td>'
						+ '<td data-label="' + th('InboxAntispamBoxState') + '">' + badge(x) + '<div class="ibx-muted">' + th('InboxBlockedStateHelp_' + x.state) + '</div></td>'
						+ '<td class="ibx-table__actions"><a class="ibx-btn ibx-btn--sm ibx-btn--ghost" href="#/settings/accounts/' + x.id + '/rules">' + th('InboxTabRules') + '</a></td></tr>').join('')
					+ '</tbody></table>' : '<p class="ibx-muted">' + th(pending ? 'InboxAntispamBoxesPending' : 'InboxNoAccount') + '</p>');
		} else {
			const sw = (name, label) => '<button type="button" class="ibx-switch ibx-js-gswitch" role="switch" data-switch="' + name + '" aria-checked="' + (s[name] !== '0' ? 'true' : 'false') + '" aria-label="' + esc(label) + '"></button>';
			html += '<div class="ibx-form">'
				+ '<section class="ibx-section" data-section="blockedsw"><h2 class="ibx-section__title">' + ic('block') + ' ' + th('InboxAntispamBlocked') + '</h2>'
				+ '<div class="ibx-formrow"><span class="ibx-formrow__label">' + th('InboxAntispamBlockedSwitch') + '</span><div>' + sw('INBOX_BLOCKED_SENDERS', t('InboxAntispamBlockedSwitch'))
				+ '<div class="ibx-formrow__help">' + th('InboxAntispamBlockedSwitchHelp') + '</div></div></div></section>'
				+ '<section class="ibx-section" data-section="junk"><h2 class="ibx-section__title">' + ic('junk') + ' ' + th('InboxAntispamJunk') + '</h2>'
				+ '<div class="ibx-formrow"><span class="ibx-formrow__label">' + th('InboxAntispamLegit') + '</span><div>' + sw('INBOX_JUNK_LEGIT_CHECK', t('InboxAntispamLegit'))
				+ '<div class="ibx-formrow__help">' + th('InboxAntispamLegitHelp') + '</div></div></div></section>'
				+ '<section class="ibx-section" data-section="server"><h2 class="ibx-section__title">' + ic('server') + ' ' + th('InboxAntispamServer') + '</h2>'
				+ '<div class="ibx-formrow"><span class="ibx-formrow__label">' + th('InboxAntispamServerVerdict') + '</span><div>' + sw('INBOX_SPAM_SERVER_VERDICT', t('InboxAntispamServerVerdict'))
				+ '<div class="ibx-formrow__help">' + th('InboxAntispamServerVerdictHelp') + '</div>'
				+ (s.INBOX_AUTH_SERVERS ? '' : '<div class="ibx-formrow__help ibx-formrow__warn">' + ic('warning') + ' ' + th('InboxAntispamServerUnverifiable') + '</div>') + '</div></div>'
				+ '<div class="ibx-formrow"><label class="ibx-formrow__label" for="g-auth">' + th('InboxAuthServers') + '</label><div><input class="ibx-field ibx-field--medium" type="text" id="g-auth" data-name="INBOX_AUTH_SERVERS" autocomplete="off" spellcheck="false" placeholder="mx.example.com" value="' + esc(s.INBOX_AUTH_SERVERS || '') + '"><div class="ibx-formrow__help">' + th('InboxAuthServersHelp') + '</div></div></div></section>'
				+ rspamdSection(s)
				+ '</div>';
		}
		html += '</div>';
		main.innerHTML = html;
		main._bs = { r, state };
		if (tab === 'settings') {
			bindGeneralFields(main, s.locked);
			bindRspamd(main, s);
		}
		if (tab !== 'blocked') {
			main.onclick = null;
			return;
		}
		// Search (after a pause), type, pages: the list only
		const search = $('.ibx-js-bssearch', main);
		let timer = 0;
		search.addEventListener('input', () => {
			clearTimeout(timer);
			timer = setTimeout(() => go(Object.assign({}, main._bs.state, { search: search.value.trim(), page: 0 })), 300);
		});
		$('.ibx-js-bstype', main).addEventListener('change', e => go(Object.assign({}, main._bs.state, { type: e.target.value, page: 0 })));
		const add = $('.ibx-js-bsadd', main);
		if (add) {
			add.addEventListener('click', () => blockedAddDialog(main));
		}
		main.onclick = async e => {
			const st = main._bs.state;
			if (e.target.closest('.ibx-js-bsprev')) {
				go(Object.assign({}, st, { page: Math.max(0, st.page - 1) }));
				return;
			}
			if (e.target.closest('.ibx-js-bsnext')) {
				go(Object.assign({}, st, { page: st.page + 1 }));
				return;
			}
			if (e.target.closest('.ibx-js-bsknown')) {
				$('.ibx-js-bstype', main).value = 'known';
				go(Object.assign({}, st, { type: 'known', page: 0 }));
				return;
			}
			const keep = e.target.closest('[data-keep]');
			if (keep) {
				try {
					await post('blockedsender', 'keep', { id: +keep.dataset.keep });
					toast(t('InboxBlockedKept'));
					// The whole tab again: the count of the ones became known changed
					main.dataset.antispam = '';
					renderAntispam(main, ['blocked'], st);
				} catch (err) {
					toast(err.message, { error: true });
				}
				return;
			}
			const b = e.target.closest('[data-unblock]');
			if (!b) {
				return;
			}
			const x = main._bs.r.entries.find(y => y.id === +b.dataset.unblock);
			if (x && await confirmDialog({ title: t('InboxUnblockTitle', x.value), text: t('InboxUnblockText'), confirm: t('InboxUnblock') })) {
				try {
					await post('blockedsender', 'delete', { id: x.id });
					toast(t('InboxUnblocked', x.value));
					go(st);
				} catch (err) {
					toast(err.message, { error: true });
				}
			}
		};
	}

	/**
	 * Anti-spam engine of inbox (1.88.0): Rspamd installed beside Dolibarr (self-hosted), its switches, the password of
	 * its controller (written only), the test of the connection, the learning and the training of the start. The lists
	 * of reputation by DNS ask outside services: off by default, a dialog says what leaves when turned on.
	 */
	function rspamdSection(s) {
		const st = s.rspamd || {};
		const sw = (name, label, def) => '<button type="button" class="ibx-switch ibx-js-gswitch" role="switch" data-switch="' + name + '" aria-checked="' + ((s[name] === undefined ? def : s[name] !== '0') ? 'true' : 'false') + '" aria-label="' + esc(label) + '"></button>';
		const row = (label, body, help) => '<div class="ibx-formrow"><span class="ibx-formrow__label">' + label + '</span><div>' + body + (help ? '<div class="ibx-formrow__help">' + help + '</div>' : '') + '</div></div>';
		const q = st.queue || {};
		// Addresses of the engine: an administrator of Dolibarr only, the mails and the password go there (INBX-049)
		const locked = name => (s.locked || []).includes(name);
		const urlLock = name => (locked(name) ? ' readonly aria-describedby="g-rsurlhelp"' : '');
		return '<section class="ibx-section" data-section="engine"><h2 class="ibx-section__title">' + ic('shield') + ' ' + I.thTerms('InboxRspamdSection') + '</h2>'
			+ '<p class="ibx-muted">' + th('InboxRspamdHelp') + '</p>'
			+ (st.error ? '<div class="ibx-notice ibx-notice--warning">' + ic('warning') + '<div class="ibx-notice__text">' + th('InboxRspamdError', new Date(st.error.since * 1000).toLocaleString(I.locale()), st.error.message) + '</div></div>' : '')
			+ row(th('InboxRspamdEnabled'), sw('INBOX_RSPAMD_ENABLED', t('InboxRspamdEnabled'), false), th('InboxRspamdEnabledHelp'))
			+ '<div class="ibx-formrow"><label class="ibx-formrow__label" for="g-rsurl">' + th('InboxRspamdUrl') + '</label><div><input class="ibx-field ibx-field--medium" type="text" id="g-rsurl" data-name="INBOX_RSPAMD_URL" autocomplete="off" spellcheck="false" value="' + esc(s.INBOX_RSPAMD_URL) + '"' + urlLock('INBOX_RSPAMD_URL') + '>'
				+ (locked('INBOX_RSPAMD_URL') ? '<div class="ibx-formrow__help" id="g-rsurlhelp">' + th('InboxRspamdUrlAdminHelp') + '</div>' : '') + '</div></div>'
			+ '<div class="ibx-formrow"><label class="ibx-formrow__label" for="g-rsctl">' + th('InboxRspamdController') + '</label><div><input class="ibx-field ibx-field--medium" type="text" id="g-rsctl" data-name="INBOX_RSPAMD_CONTROLLER_URL" autocomplete="off" spellcheck="false" value="' + esc(s.INBOX_RSPAMD_CONTROLLER_URL) + '"' + urlLock('INBOX_RSPAMD_CONTROLLER_URL') + '></div></div>'
			+ '<div class="ibx-formrow"><label class="ibx-formrow__label" for="g-rspw">' + th('InboxRspamdPassword') + '</label><div><div class="ibx-inline"><input class="ibx-field ibx-field--medium ibx-js-rspw" type="password" id="g-rspw" autocomplete="new-password" placeholder="' + th(st.has_password ? 'InboxRspamdPasswordSaved' : 'InboxRspamdPasswordNone') + '">'
				+ '<button type="button" class="ibx-btn ibx-btn--sm ibx-js-rspwsave">' + th('InboxSave') + '</button></div><div class="ibx-formrow__help">' + th('InboxRspamdPasswordHelp') + '</div></div></div>'
			+ row('', '<button type="button" class="ibx-btn ibx-btn--sm ibx-js-rstest">' + ic('plug') + ' ' + th('InboxRspamdTest') + '</button><div class="ibx-js-rstestres ibx-formrow__help" role="status"></div>')
			+ '<div class="ibx-formrow"><label class="ibx-formrow__label" for="g-rsscope">' + th('InboxRspamdScope') + '</label><div><select class="ibx-field ibx-field--medium" id="g-rsscope" data-name="INBOX_RSPAMD_SCOPE">'
				+ ['noverdict', 'all'].map(v => '<option value="' + v + '"' + (v === s.INBOX_RSPAMD_SCOPE ? ' selected' : '') + '>' + th('InboxRspamdScope_' + v) + '</option>').join('') + '</select><div class="ibx-formrow__help">' + th('InboxRspamdScopeHelp') + '</div></div></div>'
			+ row(th('InboxRspamdMove'), sw('INBOX_RSPAMD_MOVE', t('InboxRspamdMove'), true), th('InboxRspamdMoveHelp'))
			+ row(th('InboxRspamdLearn'), sw('INBOX_RSPAMD_LEARN', t('InboxRspamdLearn'), true), th('InboxRspamdLearnHelp'))
			+ row(th('InboxRspamdTrain'), '<button type="button" class="ibx-btn ibx-btn--sm ibx-js-rstrain">' + th('InboxRspamdTrainButton') + '</button>'
				+ '<div class="ibx-muted ibx-js-rsqueue">' + th('InboxRspamdQueue', q.waiting || 0, q.done || 0, q.failed || 0) + '</div>', th('InboxRspamdTrainHelp'))
			+ row(th('InboxRspamdDnsLists'), '<button type="button" class="ibx-switch ibx-js-rsoutside" role="switch" aria-checked="' + (s.INBOX_RSPAMD_DNSLISTS === '1' ? 'true' : 'false') + '" aria-label="' + th('InboxRspamdDnsLists') + '"></button>',
				th('InboxRspamdDnsListsHelp'))
			+ '</section>';
	}
	function bindRspamd(main, s) {
		const res = $('.ibx-js-rstestres', main);
		if (!res) {
			return;
		}
		$('.ibx-js-rspwsave', main).addEventListener('click', async () => {
			try {
				await post('rspamd', 'password', { value: $('.ibx-js-rspw', main).value });
				$('.ibx-js-rspw', main).value = '';
				toast(t('InboxSaved'));
			} catch (err) {
				toast(err.message, { error: true });
			}
		});
		$('.ibx-js-rstest', main).addEventListener('click', async () => {
			res.textContent = t('InboxRspamdTesting');
			try {
				const e = (await api('rspamd', 'state', { ask: 1 })).engine || {};
				res.textContent = e.ok ? t('InboxRspamdTestOk', e.version || '?', e.learned_spam, e.learned_ham) + (e.learned_spam < 200 || e.learned_ham < 200 ? ' ' + t('InboxRspamdTestFew') : '') : t('InboxRspamdTestFail', e.error || '?');
			} catch (err) {
				res.textContent = err.message;
			}
		});
		$('.ibx-js-rstrain', main).addEventListener('click', async () => {
			if (!await confirmDialog({ title: t('InboxRspamdTrain'), text: t('InboxRspamdTrainConfirm'), confirm: t('InboxRspamdTrainButton') })) {
				return;
			}
			try {
				const r = await post('rspamd', 'train', {});
				toast(t('InboxRspamdTrainQueued', r.queued.spam, r.queued.ham));
				$('.ibx-js-rsqueue', main).textContent = t('InboxRspamdQueue', r.queue.waiting, r.queue.done, r.queue.failed);
			} catch (err) {
				toast(err.message, { error: true });
			}
		});
		// Outside services: turned on after a dialog that says what leaves and to whom
		const out = $('.ibx-js-rsoutside', main);
		out.addEventListener('click', async () => {
			const on = out.getAttribute('aria-checked') !== 'true';
			if (on && !await confirmDialog({ title: t('InboxRspamdDnsLists'), text: t('InboxRspamdDnsListsConfirm'), confirm: t('InboxEnable') })) {
				return;
			}
			try {
				await post('general', 'set', { name: 'INBOX_RSPAMD_DNSLISTS', value: on ? '1' : '0' });
				out.setAttribute('aria-checked', on ? 'true' : 'false');
			} catch (err) {
				toast(err.message, { error: true });
			}
		});
	}

	/** One page of the blocked senders, with its pager */
	function blockedListHtml(r, state) {
		const date = ts => new Date(ts * 1000).toLocaleDateString((I.ctx('MAIN_LANG_DEFAULT', 'fr_FR') || 'fr_FR').replace('_', '-'));
		const list = r.entries || [];
		if (!list.length) {
			return state.search || state.type ? '<p class="ibx-muted">' + th('InboxBlockedNoMatch') + '</p>'
				: '<div class="ibx-empty" style="height:auto">' + ic('block', 'ibx-empty__icon') + '<div class="ibx-empty__title">' + th('InboxBlockedNone') + '</div><div>' + th('InboxBlockedNoneHelp') + '</div></div>';
		}
		const pages = Math.max(1, Math.ceil(r.total / r.per_page));
		return '<p class="ibx-muted">' + th('InboxBlockedCount', r.total) + '</p>'
			+ '<table class="ibx-table ibx-bstable"><thead><tr><th>' + th('InboxBlockedSender') + '</th><th>' + th('InboxBlockedBy') + '</th><th>' + th('InboxBlockedNoteCol') + '</th><th></th></tr></thead><tbody>'
			+ list.map(e => '<tr data-row="' + e.id + '">'
				+ '<td data-label="' + th('InboxBlockedSender') + '"><b>' + esc(e.value) + '</b>' + (e.type === 'domain' ? ' <span class="ibx-badge">' + th('InboxBlockedTypeDomain') + '</span>' : '')
				+ (e.known_reason ? '<div class="ibx-muted">' + th('InboxBlockedWasKnown', e.known_reason) + '</div>' : '')
				+ (e.known_since ? '<div class="ibx-bsknown">' + ic('warning') + ' ' + th('InboxBlockedBecameKnown', date(e.known_since), e.known_now) + '</div>' : '') + '</td>'
				+ '<td class="ibx-muted" data-label="' + th('InboxBlockedBy') + '">' + esc(e.user_creat || '-') + '<div>' + esc(date(e.date_creation)) + '</div></td>'
				+ '<td data-label="' + th('InboxBlockedNoteCol') + '">' + esc(e.note) + (e.mail_subject ? '<div class="ibx-muted">' + th('InboxBlockedFromMail', e.mail_subject) + '</div>' : '') + '</td>'
				+ '<td class="ibx-table__actions">' + (e.known_since && r.manager ? '<button type="button" class="ibx-btn ibx-btn--sm" data-keep="' + e.id + '">' + th('InboxBlockedKeep') + '</button> ' : '')
				+ (r.manager || e.user_id === r.me ? '<button type="button" class="ibx-btn ibx-btn--sm" data-unblock="' + e.id + '">' + th('InboxUnblock') + '</button>' : '') + '</td></tr>').join('')
			+ '</tbody></table>'
			+ (pages > 1 ? '<div class="ibx-dompager"><button type="button" class="ibx-btn ibx-btn--sm ibx-js-bsprev"' + (r.page ? '' : ' disabled') + '>' + ic('chevron-left') + ' ' + th('InboxDomainsPrev') + '</button>'
				+ '<span class="ibx-muted">' + th('InboxDomainsPage', r.page + 1, pages) + '</span>'
				+ '<button type="button" class="ibx-btn ibx-btn--sm ibx-js-bsnext"' + (r.page + 1 < pages ? '' : ' disabled') + '>' + th('InboxDomainsNext') + ' ' + ic('chevron-right') + '</button></div>' : '');
	}

	/** Add an address or a domain by hand (managers of the list); a known sender asks the value typed again */
	function blockedAddDialog(main) {
		const body = '<form class="ibx-form" novalidate>'
			+ field('type', t('InboxBlockedType'), 'address', { options: [['address', t('InboxBlockedTypeAddress')], ['domain', t('InboxBlockedTypeDomain')]] })
			+ field('value', t('InboxBlockedValue'), '', { required: true, autocomplete: 'off', placeholder: 'contact@example.com', help: t('InboxBlockedValueHelp') })
			+ field('note', t('InboxBlockNote'), '', { autocomplete: 'off', placeholder: t('InboxBlockNotePlaceholder') })
			+ '<div class="ibx-notice ibx-notice--warning ibx-js-bknown" hidden>' + ic('warning') + '<div class="ibx-notice__text"><b>' + th('InboxBlockKnownTitle') + '</b> <span class="ibx-js-bknownwhy"></span>'
			+ '<p>' + th('InboxBlockKnownOtherTools') + '</p>'
			+ '<label class="ibx-blockconfirm" for="ibx-bconfirm2">' + th('InboxBlockKnownType') + ' <b class="ibx-js-bknownval"></b></label>'
			+ '<input class="ibx-field" id="ibx-bconfirm2" name="confirm" type="text" autocomplete="off" spellcheck="false"></div></div>'
			+ '</form>';
		dialog({
			title: t('InboxBlockedAdd'),
			body,
			actions: [{ label: t('InboxBlockButton'), primary: true, run: async dlg => {
				const values = formValues($('form', dlg));
				try {
					await post('blockedsender', 'create', values);
				} catch (err) {
					if (err.fields && err.fields.confirm !== undefined) {
						// Known to Dolibarr: who he is, and the value to type again
						$('.ibx-js-bknown', dlg).hidden = false;
						$('.ibx-js-bknownwhy', dlg).textContent = err.fields.confirm;
						$('.ibx-js-bknownval', dlg).textContent = values.value.trim().toLowerCase();
						$('[name="confirm"]', dlg).focus();
						if (values.confirm) {
							toast(err.message, { error: true });
						}
						return false;
					}
					toast(err.message, { error: true });
					return false;
				}
				toast(t('InboxBlockedDone', values.value));
				renderAntispam(main, ['blocked'], main._bs ? main._bs.state : null);
			} }],
			onOpen: dlg => $('[name="value"]', dlg).focus(),
		});
	}

	// ---------- General (admins) ----------
	async function renderGeneral(main, parts) {
		if (!isAdmin()) {
			I.go('#/settings/accounts');
			return;
		}
		main.innerHTML = skeleton();
		let s;
		let aiModule = 0;
		let editorInfo = { ckeditor: 0, module_label: '' };
		try {
			const g = await api('general', 'get');
			s = g.settings;
			s.locked = g.locked || [];
			aiModule = g.ai_module;
			editorInfo = g.editor || editorInfo;
		} catch (err) {
			main.replaceChildren(errorBox(err, () => renderGeneral(main, parts)));
			return;
		}
		const title = id => sectionTitle(GENERAL_SECTIONS, id);
		main.innerHTML = '<div class="ibx-page"><div class="ibx-page__head"><h1 class="ibx-page__title">' + th('InboxNavGeneral') + '</h1></div><p class="ibx-page__intro">' + th('InboxGeneralIntro') + '</p>'
			+ '<nav class="ibx-subchips" aria-label="' + th('InboxNavGeneral') + '">' + sectionLinks('general', GENERAL_SECTIONS, 'ibx-subchip') + '</nav>'
			// Look (accent, accent of the dark theme, greys, preview): applied at once to the page
			+ '<section class="ibx-section" data-section="look">' + title('look') + '<div class="ibx-form ibx-js-look">'
			+ '<div class="ibx-formrow"><span class="ibx-formrow__label" id="g-accent-label">' + th('InboxAccent') + '</span><div><div class="ibx-swatches" role="radiogroup" aria-labelledby="g-accent-label">'
			+ ACCENTS.map(([c, k]) => '<label class="ibx-swatchpick" title="' + th('InboxAccent_' + k) + '"><input type="radio" name="g-accent" value="' + c + '" aria-label="' + th('InboxAccent_' + k) + '"><span class="ibx-swatchpick__dot" data-color="' + c + '"></span></label>').join('')
			+ '<label class="ibx-inline"><input type="radio" name="g-accent" value="other"> ' + th('InboxAccentOther') + ' <input type="color" class="ibx-color" id="g-accent" aria-label="' + th('InboxAccentOther') + '" value="' + esc(s.INBOX_ACCENT) + '"></label></div>'
			+ '<div class="ibx-formrow__help">' + th('InboxAccentHelp') + '</div></div></div>'
			// Accent of the dark theme: computed from the accent (default), or chosen
			+ '<div class="ibx-formrow"><span class="ibx-formrow__label" id="g-accentdark-label">' + th('InboxAccentDark') + '</span><div class="ibx-accentdark ibx-js-accentdark" role="radiogroup" aria-labelledby="g-accentdark-label">'
			+ '<label class="ibx-radio"><input type="radio" name="g-accentdark" value="auto"' + (s.INBOX_ACCENT_DARK ? '' : ' checked') + '> ' + th('InboxAccentDarkAuto') + ' <span class="ibx-accentdark__swatch ibx-js-darkauto" aria-hidden="true"></span><code class="ibx-js-darkautohex"></code></label>'
			+ '<label class="ibx-radio"><input type="radio" name="g-accentdark" value="own"' + (s.INBOX_ACCENT_DARK ? ' checked' : '') + '> ' + th('InboxAccentDarkOwn') + '</label>'
			+ '<input type="color" class="ibx-color ibx-js-darkown" aria-label="' + th('InboxAccentDark') + '" value="' + esc(s.INBOX_ACCENT_DARK || '#d881be') + '"' + (s.INBOX_ACCENT_DARK ? '' : ' disabled') + '>'
			+ '<div class="ibx-notice ibx-notice--warning ibx-js-darkwarn" hidden>' + ic('warning') + '<div class="ibx-notice__text"><span class="ibx-js-darkwarntext"></span> <button type="button" class="ibx-btn ibx-btn--sm ibx-js-darkuseauto">' + th('InboxAccentDarkUseAuto') + '</button></div></div>'
			+ '<div class="ibx-formrow__help">' + th('InboxAccentDarkHelp') + '</div></div></div>'
			+ '<div class="ibx-formrow"><span class="ibx-formrow__label" id="g-greys-label">' + th('InboxGreys') + '</span><div><div class="ibx-inline" role="radiogroup" aria-labelledby="g-greys-label">'
			+ ['neutral', 'accent'].map(v => '<label class="ibx-radio"><input type="radio" name="g-greys" value="' + v + '"' + ((s.INBOX_GREYS || 'neutral') === v ? ' checked' : '') + '> ' + th('InboxGreys_' + v) + '</label>').join(' ')
			+ '</div><div class="ibx-formrow__help">' + th('InboxGreysHelp') + '</div></div></div>'
			+ '<div class="ibx-formrow"><span class="ibx-formrow__label">' + th('InboxLookPreview') + '</span><div class="ibx-apreview" aria-hidden="true">'
			+ ['light', 'dark'].map(k => '<div class="ibx-apreview__theme" data-k="' + k + '"><div class="ibx-apreview__pane"><span class="ibx-apreview__row is-on">' + th('InboxFolderRoleInbox') + '</span><span class="ibx-apreview__row">' + th('InboxFolderRoleDrafts') + '</span><span class="ibx-apreview__row is-hover">' + th('InboxFolderRoleSent') + '</span></div>'
				+ '<div class="ibx-apreview__list"><span class="ibx-apreview__search">' + th('InboxSearchPlaceholder') + '</span><span class="ibx-apreview__mail">' + th('InboxLookPreviewMail') + '<span>' + th('InboxLookPreviewText') + '</span></span><span class="ibx-apreview__btn">' + th('InboxNewMail') + '</span></div></div>').join('')
			+ '</div></div>'
			+ '</div></section>'
			+ '<section class="ibx-section" data-section="defaults">' + title('defaults') + '<div class="ibx-form ibx-js-general">'
			+ '<div class="ibx-formrow"><label class="ibx-formrow__label" for="g-read">' + th('InboxReadMode') + '</label><div><select class="ibx-field ibx-field--medium" id="g-read" data-name="INBOX_DEFAULT_READ_MODE">' + ['immediate', 'delay', 'manual'].map(v => '<option value="' + v + '"' + (v === s.INBOX_DEFAULT_READ_MODE ? ' selected' : '') + '>' + th('InboxReadMode_' + v) + '</option>').join('') + '</select><div class="ibx-formrow__help">' + th('InboxReadModeDefaultHelp') + '</div></div></div>'
			+ '<div class="ibx-formrow"><label class="ibx-formrow__label" for="g-delay">' + th('InboxReadDelay') + '</label><div class="ibx-inline"><input class="ibx-field ibx-field--narrow" type="number" min="0" max="600" inputmode="numeric" id="g-delay" data-name="INBOX_DEFAULT_READ_DELAY" value="' + esc(s.INBOX_DEFAULT_READ_DELAY) + '"> ' + th('InboxSeconds') + '</div></div>'
			+ '<div class="ibx-formrow"><label class="ibx-formrow__label" for="g-send">' + th('InboxSendDelay') + '</label><div><div class="ibx-inline"><input class="ibx-field ibx-field--narrow" type="number" min="0" max="120" inputmode="numeric" id="g-send" data-name="INBOX_SEND_DELAY" value="' + esc(s.INBOX_SEND_DELAY) + '"> ' + th('InboxSeconds') + '</div><div class="ibx-formrow__help">' + th('InboxSendDelayHelp') + '</div></div></div>'
			+ '<div class="ibx-formrow"><label class="ibx-formrow__label" for="g-journal">' + th('InboxJournalKeep') + '</label><div><div class="ibx-inline"><input class="ibx-field ibx-field--narrow" type="number" min="0" max="120" inputmode="numeric" id="g-journal" data-name="INBOX_ACTION_LOG_MONTHS" value="' + esc(s.INBOX_ACTION_LOG_MONTHS) + '"> ' + th('InboxMonths') + '</div><div class="ibx-formrow__help">' + th('InboxJournalKeepHelp') + '</div></div></div>'
			+ '<div class="ibx-formrow"><label class="ibx-formrow__label" for="g-sendmax">' + th('InboxSendMax') + '</label><div><div class="ibx-inline"><input class="ibx-field ibx-field--narrow" type="number" min="1" max="100" inputmode="numeric" id="g-sendmax" data-name="INBOX_SEND_MAX_MB" value="' + esc(s.INBOX_SEND_MAX_MB) + '"> ' + th('InboxMegabytes') + '</div><div class="ibx-formrow__help">' + th('InboxSendMaxHelp') + '</div></div></div>'
			+ '<div class="ibx-formrow"><span class="ibx-formrow__label">' + th('InboxSendAutocopy') + '</span><div>'
			+ '<button type="button" class="ibx-switch ibx-js-gswitch" role="switch" data-switch="INBOX_SEND_AUTOCOPY" aria-checked="' + (s.INBOX_SEND_AUTOCOPY === '1' ? 'true' : 'false') + '" aria-label="' + th('InboxSendAutocopy') + '"></button>'
			+ '<div class="ibx-formrow__help">' + th('InboxSendAutocopyHelp') + '</div></div></div>'
			+ '</div></section>'
			// Editor of the writing (1.94.0): the one of inbox, or the CKEditor of Dolibarr (module FCKeditor on)
			+ editorSection(s, editorInfo, title('editor'))
			+ '<section class="ibx-section" data-section="avatars">' + title('avatars') + '<p class="ibx-section__help">' + th('InboxAvatarsHelp') + '</p><div class="ibx-form">'
			+ [['INBOX_AVATAR_DOLIBARR', 'InboxAvatarDolibarr'], ['INBOX_AVATAR_BIMI', 'InboxAvatarBimi'], ['INBOX_AVATAR_GRAVATAR', 'InboxAvatarGravatar']].map(([k, label]) => '<div class="ibx-formrow"><span class="ibx-formrow__label">' + th(label) + '</span><div>'
				+ '<button type="button" class="ibx-switch ibx-js-gswitch" role="switch" data-switch="' + k + '" aria-checked="' + (s[k] === '1' ? 'true' : 'false') + '" aria-label="' + th(label) + '"></button>'
				+ '<div class="ibx-formrow__help">' + th(label + 'Help') + '</div></div></div>').join('')
			+ '</div></section>'
			// New mailboxes (lot H1): the ISPDB of Thunderbird asked at each search, else offered by a click
			+ '<section class="ibx-section" data-section="newbox">' + title('newbox') + '<p class="ibx-section__help">' + th('InboxAutoconfigSectionHelp') + '</p><div class="ibx-form">'
			+ '<div class="ibx-formrow"><span class="ibx-formrow__label">' + th('InboxAutoconfigIspdbSetting') + '</span><div>'
			+ '<button type="button" class="ibx-switch ibx-js-gswitch" role="switch" data-switch="INBOX_AUTOCONFIG_ISPDB" aria-checked="' + (s.INBOX_AUTOCONFIG_ISPDB === '1' ? 'true' : 'false') + '" aria-label="' + th('InboxAutoconfigIspdbSetting') + '"></button>'
			+ '<div class="ibx-formrow__help">' + th('InboxAutoconfigIspdbSettingHelp') + '</div></div></div>'
			+ '</div></section>'
			// AI in the writing (1.27.0): through the AI module of Dolibarr, off by default
			+ '<section class="ibx-section" data-section="ai">' + title('ai')
			+ (aiModule ? '' : '<div class="ibx-notice">' + ic('info') + '<div class="ibx-notice__text">' + th('InboxAiNoModule') + '</div></div>')
			+ '<div class="ibx-form">'
			+ [['INBOX_AI_ENABLED', 'InboxAiEnabled'], ['INBOX_AI_WITH_MAIL', 'InboxAiWithMailSetting']].map(([k, label]) => '<div class="ibx-formrow"><span class="ibx-formrow__label">' + th(label) + '</span><div>'
				+ '<button type="button" class="ibx-switch ibx-js-gswitch" role="switch" data-switch="' + k + '" aria-checked="' + (s[k] === '1' ? 'true' : 'false') + '" aria-label="' + th(label) + '"></button>'
				+ '<div class="ibx-formrow__help">' + th(label + 'Help') + '</div></div></div>').join('')
			+ '</div></section>'
			// Voice typing (1.28.0): off by default, each user agrees once per receiver of the voice
			+ '<section class="ibx-section" data-section="dictation">' + title('dictation') + '<div class="ibx-form">'
			+ '<div class="ibx-formrow"><span class="ibx-formrow__label">' + th('InboxDictationEnabled') + '</span><div>'
			+ '<button type="button" class="ibx-switch ibx-js-gswitch" role="switch" data-switch="INBOX_DICTATION_ENABLED" aria-checked="' + (s.INBOX_DICTATION_ENABLED === '1' ? 'true' : 'false') + '" aria-label="' + th('InboxDictationEnabled') + '"></button>'
			+ '<div class="ibx-formrow__help">' + th('InboxDictationEnabledHelp') + '</div></div></div>'
			+ '</div><div class="ibx-whisper ibx-js-whisper"></div></section>'
			+ '<section class="ibx-section ibx-js-searchsettings" data-section="search">' + title('search') + '</section>'
			+ '<section class="ibx-section" data-section="tags">' + title('tags') + '<p class="ibx-section__help">' + th('InboxGlobalTagsHelp') + '</p><div class="ibx-js-tags"></div></section></div>';
		// Look: accent (offered colours or another one), accent of the dark theme, greys; preview and page at once
		const look = $('.ibx-js-look', main);
		const accentInput = $('#g-accent', look);
		const darkBox = $('.ibx-js-accentdark', look);
		const darkOwn = $('.ibx-js-darkown', darkBox);
		$$('[data-color]', look).forEach(el => {
			el.style.background = el.dataset.color;
		});
		const pick = $('[name="g-accent"][value="' + String(s.INBOX_ACCENT).toLowerCase() + '"]', look) || $('[name="g-accent"][value="other"]', look);
		pick.checked = true;
		const accentValue = () => {
			const v = $('[name="g-accent"]:checked', look).value;
			return v === 'other' ? accentInput.value : v;
		};
		const darkValue = () => ($('[name="g-accentdark"]:checked', darkBox).value === 'own' ? darkOwn.value : '');
		const greysValue = () => $('[name="g-greys"]:checked', look).value;
		const paintAccent = () => {
			const c = lookTokens(accentValue(), darkValue(), greysValue());
			$('.ibx-js-darkauto', darkBox).style.background = c.auto;
			$('.ibx-js-darkautohex', darkBox).textContent = c.auto;
			darkOwn.disabled = darkValue() === '';
			const weak = darkValue() !== '' && c.darkContrast < 4.5;
			$('.ibx-js-darkwarn', darkBox).hidden = !weak;
			if (weak) {
				$('.ibx-js-darkwarntext', darkBox).textContent = t('InboxAccentDarkWeak', c.darkContrast.toFixed(1));
			}
			// Preview: each theme with its own colours (the soft accent mixed with its own background)
			['light', 'dark'].forEach(k => {
				const box = $('.ibx-apreview__theme[data-k="' + k + '"]', look);
				const set = Object.assign({ bg: k === 'light' ? '#ffffff' : c.dark.bg }, c[k]);
				Object.keys(set).forEach(n => box.style.setProperty('--ibx-' + n, set[n]));
				box.style.setProperty('--ibx-accent-soft', 'color-mix(in srgb, ' + set.accent + ' 14%, ' + set.bg + ')');
			});
			const st = document.getElementById('ibx-accent-css');
			if (st) {
				st.textContent = accentCss(accentValue(), darkValue(), greysValue());
			}
		};
		const saveLook = async (name, value) => {
			try {
				await post('general', 'set', { name, value });
				toast(t('InboxSaved'));
			} catch (err) {
				toast(err.message, { error: true });
			}
			paintAccent();
		};
		paintAccent();
		$$('[name="g-accent"]', look).forEach(rb => rb.addEventListener('change', () => saveLook('INBOX_ACCENT', accentValue())));
		accentInput.addEventListener('input', () => {
			$('[name="g-accent"][value="other"]', look).checked = true;
			paintAccent();
		});
		accentInput.addEventListener('change', () => saveLook('INBOX_ACCENT', accentValue()));
		$$('[name="g-accentdark"]', darkBox).forEach(rb => rb.addEventListener('change', () => saveLook('INBOX_ACCENT_DARK', darkValue())));
		darkOwn.addEventListener('input', paintAccent);
		darkOwn.addEventListener('change', () => saveLook('INBOX_ACCENT_DARK', darkValue()));
		$('.ibx-js-darkuseauto', darkBox).addEventListener('click', () => {
			$('[name="g-accentdark"][value="auto"]', darkBox).checked = true;
			saveLook('INBOX_ACCENT_DARK', '');
		});
		$$('[name="g-greys"]', look).forEach(rb => rb.addEventListener('change', () => saveLook('INBOX_GREYS', greysValue())));
		// Saved field by field, at once (as the switches), with a toast
		bindGeneralFields(main, s.locked);
		bindEditorSection(main, s, editorInfo);
		tabTags($('.ibx-js-tags', main), null);
		renderWhisperSettings($('.ibx-js-whisper', main));
		const searchDone = renderSearchSettings($('.ibx-js-searchsettings', main));
		// The section on screen lit in the menu (and the chips); a section asked by the route brought into view once
		// the page is complete (the search settings come later and move what is below them)
		spySections(main, GENERAL_SECTIONS.map(x => x[0]));
		if (parts && parts[0]) {
			searchDone.then(() => requestAnimationFrame(() => pointSection(main, parts[0])));
		}
	}

	// ---------- Server (1.54.0, admins of Dolibarr) ----------
	/** « every minute », « every 5 minutes », « every day »... */
	function everyText(sec) {
		const units = [[86400, 'Day'], [3600, 'Hour'], [60, 'Min']];
		for (const [n, u] of units) {
			if (sec >= n && sec % n === 0) {
				return sec === n ? t('InboxCronEvery1' + u) : t('InboxCronEvery' + u, sec / n);
			}
		}
		return t('InboxCronEverySec', sec);
	}

	/**
	 * Settings > Recognized domains (1.59.0): the short list of domains of organisations shown on the senders
	 * (authenticated mails) and the links, and the reference of the lookalikes. Analysis: analyse-domaines-reconnus.md
	 */
	async function renderTrusted(main) {
		if (!isTrustManager()) {
			I.go('#/settings/accounts');
			return;
		}
		main.innerHTML = skeleton();
		let r;
		try {
			r = await api('trusteddomain', 'list');
		} catch (err) {
			main.replaceChildren(errorBox(err, () => renderTrusted(main)));
			return;
		}
		const date = ts => new Date(ts * 1000).toLocaleDateString((I.ctx('MAIN_LANG_DEFAULT', 'fr_FR') || 'fr_FR').replace('_', '-'));
		const list = r.domains || [];
		let html = '<div class="ibx-page"><div class="ibx-page__head"><h1 class="ibx-page__title">' + th('InboxNavTrustedDomains') + '</h1></div>'
			+ '<p class="ibx-page__intro">' + th('InboxTrustedDomainsHelp') + '</p>';
		if (list.length > r.many) {
			html += '<div class="ibx-notice ibx-notice--info">' + ic('info') + '<div class="ibx-notice__text">' + th('InboxTrustedManyDomains') + '</div></div>';
		}
		if (list.length > 10) {
			html += '<div class="ibx-formrow"><input class="ibx-field ibx-field--medium ibx-js-tdfilter" type="search" placeholder="' + th('InboxTrustedFilter') + '" aria-label="' + th('InboxTrustedFilter') + '" spellcheck="false"></div>';
		}
		if (!list.length) {
			html += '<div class="ibx-empty" style="height:auto">' + ic('trusted', 'ibx-empty__icon') + '<div class="ibx-empty__title">' + th('InboxTrustedNone') + '</div><div>' + th('InboxTrustedNoneHelp') + '</div></div>';
		} else {
			html += '<table class="ibx-table"><thead><tr><th>' + th('InboxTrustedDomain') + '</th><th>' + th('InboxTrustedLabel') + '</th><th>' + th('InboxTrustedAddedBy') + '</th><th></th></tr></thead><tbody>'
				+ list.map(d => '<tr data-row="' + d.id + '" data-search="' + esc((d.domain + ' ' + d.label + ' ' + d.note).toLowerCase()) + '"><td><b>' + esc(d.domain) + '</b>'
					+ (d.subdomains ? '<div class="ibx-muted">' + th('InboxTrustedWithSubdomains') + '</div>' : '') + '</td>'
					+ '<td>' + esc(d.label) + (d.note ? '<div class="ibx-muted">' + esc(d.note) + '</div>' : '') + '</td>'
					+ '<td class="ibx-muted">' + esc(d.user_creat || '-') + '<div>' + esc(date(d.date_creation)) + '</div></td>'
					+ '<td class="ibx-table__actions"><button type="button" class="ibx-btn ibx-btn--sm" data-edit="' + d.id + '">' + th('InboxEdit') + '</button> '
					+ '<button type="button" class="ibx-btn ibx-btn--sm ibx-btn--ghost ibx-btn--icon" data-del="' + d.id + '" title="' + th('InboxDelete') + '" aria-label="' + th('InboxDelete') + ' ' + esc(d.domain) + '">' + ic('delete') + '</button></td></tr>').join('')
				+ '</tbody></table>';
		}
		html += '<div class="ibx-formfoot"><button type="button" class="ibx-btn ibx-btn--primary ibx-js-tdadd">' + ic('plus') + ' ' + th('InboxTrustedAdd') + '</button></div></div>';
		main.innerHTML = html;
		$('.ibx-js-tdadd', main).addEventListener('click', () => trustedDialog(main, { id: 0, domain: '', label: '', subdomains: 1, note: '' }));
		const filter = $('.ibx-js-tdfilter', main);
		if (filter) {
			filter.addEventListener('input', () => {
				const q = filter.value.trim().toLowerCase();
				$$('[data-row]', main).forEach(tr => {
					tr.hidden = !!q && !tr.dataset.search.includes(q);
				});
			});
		}
		main.onclick = async e => {
			const ed = e.target.closest('[data-edit]');
			if (ed) {
				trustedDialog(main, list.find(d => d.id === +ed.dataset.edit));
				return;
			}
			const del = e.target.closest('[data-del]');
			if (!del) {
				return;
			}
			const d = list.find(x => x.id === +del.dataset.del);
			if (await confirmDialog({ title: t('InboxTrustedDeleteTitle', d.domain), text: t('InboxTrustedDeleteText', d.label), confirm: t('InboxDelete'), danger: true })) {
				try {
					await post('trusteddomain', 'delete', { domain_id: d.id });
					toast(t('InboxTrustedDeleted', d.domain));
					renderTrusted(main);
				} catch (err) {
					toast(err.message, { error: true });
				}
			}
		};
	}

	function trustedDialog(main, d) {
		const body = '<form class="ibx-form" novalidate>'
			+ field('domain', t('InboxTrustedDomain'), d.domain, { required: true, autocomplete: 'off', placeholder: 'exemple.com', help: t('InboxTrustedDomainFieldHelp') })
			+ '<div class="ibx-formrow"><span class="ibx-formrow__label"></span><div><label class="ibx-check"><input type="checkbox" name="subdomains"' + (d.subdomains ? ' checked' : '') + '> ' + th('InboxTrustedWithSubdomains') + '</label></div></div>'
			+ field('label', t('InboxTrustedLabel'), d.label, { required: true, autocomplete: 'off', help: t('InboxTrustedLabelHelp') })
			+ field('note', t('InboxTrustedNote'), d.note, { autocomplete: 'off', help: t('InboxTrustedNoteHelp') })
			+ '</form>';
		dialog({
			title: d.id ? t('InboxTrustedEdit', d.domain) : t('InboxTrustedAdd'),
			body,
			actions: [{ label: d.id ? t('InboxSave') : t('InboxTrustedAddButton'), primary: true, run: async dlg => {
				const values = formValues($('form', dlg));
				try {
					await post('trusteddomain', d.id ? 'update' : 'create', Object.assign({ domain_id: d.id }, values));
				} catch (err) {
					// The dialog stays open with what was typed
					toast(err.message, { error: true });
					return false;
				}
				toast(t('InboxSaved'));
				renderTrusted(main);
			} }],
			onOpen: dlg => $('[name="' + (d.id ? 'label' : 'domain') + '"]', dlg).focus(),
		});
	}


	/**
	 * Settings > Public and shared domains (lot A7, 1.76.0): the shipped list (never downloaded by inbox), the local entries, the ones of
	 * the other modules; search, filters, thirdparties and mails using each domain; every change at once, with undo
	 */
	const DOMAIN_CATS = ['public', 'disposable', 'shared'];
	async function renderDomains(main, parts, state) {
		if (!isAdmin()) {
			I.go('#/settings/accounts');
			return;
		}
		state = state || { search: '', category: '', origin: '', page: 0 };
		if (!main.querySelector('.ibx-js-domlist')) {
			main.innerHTML = skeleton();
		}
		let r;
		try {
			r = await api('domain', 'list', state);
		} catch (err) {
			main.replaceChildren(errorBox(err, () => renderDomains(main, parts, state)));
			return;
		}
		const catLabel = c => t('InboxDomainsCat_' + c);
		const origLabel = d => d.origin === 'shipped' ? (d.status ? t('InboxDomainsOriginShipped') : t('InboxDomainsOriginOff')) : t(d.origin === 'module' ? 'InboxDomainsOriginModule' : 'InboxDomainsOriginLocal');
		const sh = r.shipped || {};
		const copied = Object.values(sh).map(x => x.copied).filter(Boolean).sort().pop() || '';
		const rows = r.rows || [];
		const pages = Math.max(1, Math.ceil(r.total / 50));
		let list = '';
		if (!rows.length) {
			list = '<div class="ibx-empty" style="height:auto">' + ic('shield', 'ibx-empty__icon') + '<div class="ibx-empty__title">' + th('InboxDomainsNone') + '</div></div>';
		} else {
			list = '<table class="ibx-table ibx-domtable"><thead><tr><th>' + th('InboxDomainsDomain') + '</th><th>' + th('InboxDomainsCategory') + '</th><th>' + th('InboxDomainsOrigin') + '</th>'
				+ '<th class="ibx-num">' + th('InboxDomainsSocs') + '</th><th class="ibx-num">' + th('InboxDomainsMails') + '</th><th></th></tr></thead><tbody>'
				+ rows.map(d => '<tr data-id="' + d.id + '" data-domain="' + esc(d.domain) + '"' + (d.status ? '' : ' class="is-off"') + '><td data-label="' + th('InboxDomainsDomain') + '"><b>' + esc(d.domain) + '</b></td>'
					+ '<td data-label="' + th('InboxDomainsCategory') + '">' + (d.origin === 'local'
						? '<select class="ibx-field ibx-field--small ibx-js-domcat" aria-label="' + esc(t('InboxDomainsCategoryOf', d.domain)) + '">' + DOMAIN_CATS.map(c => '<option value="' + c + '"' + (c === d.category ? ' selected' : '') + '>' + esc(catLabel(c)) + '</option>').join('') + '</select>'
						: esc(catLabel(d.category))) + '</td>'
					+ '<td data-label="' + th('InboxDomainsOrigin') + '"><span class="ibx-badge">' + esc(origLabel(d)) + '</span></td>'
					+ '<td class="ibx-num" data-label="' + th('InboxDomainsSocs') + '">' + d.socs + '</td><td class="ibx-num" data-label="' + th('InboxDomainsMails') + '">' + d.mails + '</td>'
					+ '<td class="ibx-table__actions">' + (d.origin === 'local'
						? '<button type="button" class="ibx-btn ibx-btn--sm ibx-btn--ghost ibx-btn--icon ibx-js-domdel" title="' + th('InboxDelete') + '" aria-label="' + esc(t('InboxDelete') + ' ' + d.domain) + '">' + ic('delete') + '</button>'
						: (d.origin === 'shipped' ? '<button type="button" class="ibx-btn ibx-btn--sm ibx-js-domtoggle">' + th(d.status ? 'InboxDomainsDisable' : 'InboxDomainsEnable') + '</button>' : '')) + '</td></tr>').join('')
				+ '</tbody></table>'
				+ (pages > 1 ? '<div class="ibx-dompager"><button type="button" class="ibx-btn ibx-btn--sm ibx-js-domprev"' + (state.page ? '' : ' disabled') + '>' + ic('chevron-left') + ' ' + th('InboxDomainsPrev') + '</button>'
					+ '<span class="ibx-muted">' + th('InboxDomainsPage', state.page + 1, pages) + '</span>'
					+ '<button type="button" class="ibx-btn ibx-btn--sm ibx-js-domnext"' + (state.page + 1 < pages ? '' : ' disabled') + '>' + th('InboxDomainsNext') + ' ' + ic('chevron-right') + '</button></div>' : '');
		}
		if (main.querySelector('.ibx-js-domlist')) {
			// A search or a filter: the list only, the field keeps the focus
			$('.ibx-js-domlist', main).innerHTML = list;
			$('.ibx-js-domcount', main).textContent = t('InboxDomainsCount', r.total);
			main._dom = { state, rows };
			return;
		}
		main.innerHTML = '<div class="ibx-page"><div class="ibx-page__head"><h1 class="ibx-page__title">' + th('InboxNavDomains') + '</h1></div>'
			+ '<p class="ibx-page__intro">' + th('InboxDomainsHelp') + '</p>'
			// Recognition by the domain (on by default): off, the exact address only (a big database, slow searches)
			+ '<div class="ibx-formrow ibx-dommatch"><span class="ibx-formrow__label">' + th('InboxDomainsMatch') + '</span><div><label class="ibx-check"><input type="checkbox" class="ibx-js-dommatch"' + (r.match ? ' checked' : '') + '> ' + th('InboxDomainsMatchLabel') + '</label>'
			+ '<div class="ibx-formrow__help">' + th('InboxDomainsMatchHelp') + '</div></div></div>'
			+ '<details class="ibx-domwhy"><summary>' + ic('info') + ' ' + th('InboxDomainsWhy') + '</summary><dl>'
			+ DOMAIN_CATS.map(c => '<dt>' + esc(catLabel(c)) + '</dt><dd>' + th('InboxDomainsWhy_' + c) + '</dd>').join('') + '</dl></details>'
			+ '<p class="ibx-muted ibx-domshipped">' + th('InboxDomainsShipped', (sh.public || {}).count || 0, (sh.disposable || {}).count || 0, copied)
			+ (r.modules ? ' ' + th('InboxDomainsModules', r.modules) : '') + ' <span title="' + esc(Object.values(sh).map(x => x.source + ' (' + x.licence + ')').join(' · ')) + '">' + th('InboxDomainsSources') + '</span></p>'
			+ '<div class="ibx-domtools"><input class="ibx-field ibx-field--medium ibx-js-domsearch" type="search" placeholder="' + th('InboxDomainsSearch') + '" aria-label="' + th('InboxDomainsSearch') + '" spellcheck="false" value="' + esc(state.search) + '">'
			+ '<select class="ibx-field ibx-field--small ibx-js-domfcat" aria-label="' + th('InboxDomainsCategory') + '"><option value="">' + th('InboxDomainsAllCats') + '</option>' + DOMAIN_CATS.map(c => '<option value="' + c + '">' + esc(catLabel(c)) + '</option>').join('') + '</select>'
			+ '<select class="ibx-field ibx-field--small ibx-js-domforig" aria-label="' + th('InboxDomainsOrigin') + '"><option value="">' + th('InboxDomainsAllOrigins') + '</option>'
			+ ['shipped', 'local', 'off', 'module'].map(o => '<option value="' + o + '">' + th('InboxDomainsOrigin_' + o) + '</option>').join('') + '</select>'
			+ '<span class="ibx-muted ibx-js-domcount">' + esc(t('InboxDomainsCount', r.total)) + '</span></div>'
			+ '<div class="ibx-js-domlist">' + list + '</div>'
			+ '<div class="ibx-formfoot"><button type="button" class="ibx-btn ibx-btn--primary ibx-js-domadd">' + ic('plus') + ' ' + th('InboxDomainsAdd') + '</button>'
			+ '<button type="button" class="ibx-btn ibx-js-domrestore">' + ic('undo') + ' ' + th('InboxDomainsRestore') + '</button></div></div>';
		main._dom = { state, rows };
		const reload = () => renderDomains(main, parts, main._dom.state);
		let timer = null;
		$('.ibx-js-domsearch', main).addEventListener('input', e => {
			clearTimeout(timer);
			timer = setTimeout(() => renderDomains(main, parts, Object.assign({}, main._dom.state, { search: e.target.value.trim(), page: 0 })), 250);
		});
		$('.ibx-js-domfcat', main).addEventListener('change', e => renderDomains(main, parts, Object.assign({}, main._dom.state, { category: e.target.value, page: 0 })));
		$('.ibx-js-domforig', main).addEventListener('change', e => renderDomains(main, parts, Object.assign({}, main._dom.state, { origin: e.target.value, page: 0 })));
		$('.ibx-js-domadd', main).addEventListener('click', () => domainAddDialog(reload));
		$('.ibx-js-dommatch', main).addEventListener('change', async e => {
			const on = e.target.checked;
			try {
				await post('domain', 'match', { on: on ? 1 : 0 });
			} catch (err) {
				e.target.checked = !on;
				toast(err.message, { error: true });
				return;
			}
			toast(t(on ? 'InboxDomainsMatchOn' : 'InboxDomainsMatchOff'), { action: t('InboxUndo'), onAction: async () => {
				await post('domain', 'match', { on: on ? 0 : 1 });
				e.target.checked = !on;
			} });
		});
		$('.ibx-js-domrestore', main).addEventListener('click', async () => {
			let res;
			try {
				res = await post('domain', 'restore', {});
			} catch (err) {
				toast(err.message, { error: true });
				return;
			}
			reload();
			toast(t('InboxDomainsRestored', res.domains.length), res.domains.length ? { action: t('InboxUndo'), onAction: async () => {
				await post('domain', 'toggle', { domains: res.domains, on: 0 });
				reload();
			} } : {});
		});
		main.onchange = async e => {
			const sel = e.target.closest('.ibx-js-domcat');
			if (!sel) {
				return;
			}
			const id = +sel.closest('tr').dataset.id;
			try {
				const res = await post('domain', 'update', { domain_id: id, category: sel.value });
				toast(t('InboxSaved'), { action: t('InboxUndo'), onAction: async () => {
					await post('domain', 'update', { domain_id: id, category: res.before.category });
					reload();
				} });
			} catch (err) {
				toast(err.message, { error: true });
				reload();
			}
		};
		main.onclick = async e => {
			if (e.target.closest('.ibx-js-domprev, .ibx-js-domnext')) {
				const st = main._dom.state;
				renderDomains(main, parts, Object.assign({}, st, { page: Math.max(0, st.page + (e.target.closest('.ibx-js-domnext') ? 1 : -1)) }));
				return;
			}
			const tr = e.target.closest('tr[data-domain]');
			if (!tr) {
				return;
			}
			const d = main._dom.rows.find(x => x.domain === tr.dataset.domain);
			if (e.target.closest('.ibx-js-domtoggle')) {
				try {
					await post('domain', 'toggle', { domain: d.domain, on: d.status ? 0 : 1 });
				} catch (err) {
					toast(err.message, { error: true });
					return;
				}
				reload();
				toast(t(d.status ? 'InboxDomainsDisabled' : 'InboxDomainsEnabled', d.domain), { action: t('InboxUndo'), onAction: async () => {
					await post('domain', 'toggle', { domain: d.domain, on: d.status });
					reload();
				} });
				return;
			}
			if (e.target.closest('.ibx-js-domdel')) {
				let res;
				try {
					res = await post('domain', 'delete', { domain_id: d.id });
				} catch (err) {
					toast(err.message, { error: true });
					return;
				}
				reload();
				toast(t('InboxDomainsDeleted', d.domain), { action: t('InboxUndo'), onAction: async () => {
					await post('domain', 'add', { domains: res.removed.domain, category: res.removed.category });
					reload();
				} });
			}
		};
	}

	/** Add one or several domains (pasted in a list), in a category */
	function domainAddDialog(done) {
		dialog({
			title: t('InboxDomainsAdd'),
			body: '<form class="ibx-form" novalidate><div class="ibx-formrow"><label class="ibx-formrow__label" for="f-domlist">' + th('InboxDomainsAddList') + '</label><div>'
				+ '<textarea class="ibx-field" id="f-domlist" name="domains" rows="5" spellcheck="false" placeholder="groupe-exemple.fr&#10;yopmail.net"></textarea>'
				+ '<div class="ibx-formrow__help">' + th('InboxDomainsAddHelp') + '</div></div></div>'
				+ field('category', t('InboxDomainsCategory'), 'shared', { options: DOMAIN_CATS.map(c => [c, t('InboxDomainsCat_' + c)]) })
				+ '</form>',
			actions: [{ label: t('InboxDomainsAddButton'), primary: true, run: async dlg => {
				const v = formValues($('form', dlg));
				let res;
				try {
					res = await post('domain', 'add', v);
				} catch (err) {
					toast(err.message, { error: true });
					return false;
				}
				if (res.bad.length) {
					toast(t('InboxDomainsBad', res.bad.join(', ')), { error: true });
				}
				if (res.added.length) {
					toast(t('InboxDomainsAdded', res.added.length));
				}
				done();
			} }],
			onOpen: dlg => $('[name="domains"]', dlg).focus(),
		});
	}

	/**
	 * Settings > Data sources (lot A2 level 1, 1.72.0): mails sent by a site or an application from its own address,
	 * the requester in its headers; believed for its trusted senders only, authenticated. Admins of Dolibarr.
	 */
	/**
	 * Row of the list of the data sources (1.92.3): its switch first (saved at once), « Edit », the rest in « ⋯ »;
	 * « Without effect » only when active without trusted sender (the switch alone would say it works)
	 */
	function sourceRow(d) {
		const senders = d.senders ? d.senders.split('\n').filter(Boolean) : [];
		return '<tr data-src="' + d.id + '"' + (d.status ? '' : ' class="is-off"') + '>'
			+ '<td class="ibx-srctable__switch"><button type="button" class="ibx-switch" role="switch" data-toggle="' + d.id + '" aria-checked="' + (d.status ? 'true' : 'false') + '" aria-label="' + esc(t('InboxSourceToggle', d.label)) + '"></button></td>'
			+ '<td><b>' + esc(d.label) + '</b>' + (d.status && !senders.length ? ' <span class="ibx-badge ibx-badge--warning" title="' + th('InboxSourceNoSender') + '">' + ic('warning') + th('InboxSourceNoEffect') + '</span>' : '')
			+ '<div class="ibx-muted">' + esc([d.header_email, d.header_data].concat((d.header_fields ? d.header_fields.split('\n') : []).map(l => l.split('=')[0].trim())).filter(Boolean).join(', ')) + '</div></td>'
			+ '<td>' + (senders.length ? esc(senders.slice(0, 3).join(', ')) + (senders.length > 3 ? ' <span class="ibx-muted">+' + (senders.length - 3) + '</span>' : '') : '<span class="ibx-muted">' + th('InboxSourceNoSender') + '</span>') + '</td>'
			+ '<td>' + th('InboxSourceType_' + (d.type || 'none')) + '</td>'
			+ '<td class="ibx-table__actions"><button type="button" class="ibx-btn ibx-btn--sm" data-edit="' + d.id + '">' + th('InboxEdit') + '</button> '
			+ '<button type="button" class="ibx-btn ibx-btn--sm ibx-btn--ghost ibx-btn--icon" data-more="' + d.id + '" aria-label="' + esc(t('InboxMoreActions')) + '">' + ic('more') + '</button></td></tr>';
	}

	async function renderSources(main, parts) {
		if (!isDolibarrAdmin()) {
			I.go('#/settings/accounts');
			return;
		}
		main.innerHTML = skeleton();
		let r;
		try {
			r = await api('source', 'list');
		} catch (err) {
			main.replaceChildren(errorBox(err, () => renderSources(main)));
			return;
		}
		const list = r.sources || [];
		let html = '<div class="ibx-page"><div class="ibx-page__head"><h1 class="ibx-page__title">' + th('InboxNavSources') + '</h1></div>'
			+ '<p class="ibx-page__intro">' + th('InboxSourcesHelp') + '</p>';
		if (!r.auth_servers) {
			html += '<div class="ibx-notice ibx-notice--warning">' + ic('warning') + '<div class="ibx-notice__text">' + th('InboxSourcesNoAuthServer') + '</div></div>';
		}
		if (!list.length) {
			html += '<div class="ibx-empty" style="height:auto">' + ic('redirect', 'ibx-empty__icon') + '<div class="ibx-empty__title">' + th('InboxSourcesNone') + '</div></div>';
		} else {
			html += '<table class="ibx-table ibx-srctable"><thead><tr><th><span class="ibx-sr-only">' + th('InboxSourceOn') + '</span></th><th>' + th('InboxSourceLabel') + '</th><th>' + th('InboxSourceSenders') + '</th><th>' + th('InboxSourceType') + '</th><th></th></tr></thead><tbody>'
				+ list.map(sourceRow).join('')
				+ '</tbody></table>';
		}
		html += '<div class="ibx-formfoot"><button type="button" class="ibx-btn ibx-btn--primary ibx-js-srcadd">' + ic('plus') + ' ' + th('InboxSourceAdd') + '</button></div>'
			+ '<section class="ibx-section"><h2 class="ibx-section__title">' + ic('info') + ' ' + th('InboxSourceFormatTitle') + '</h2>'
			+ '<p>' + th('InboxSourceFormatHelp') + '</p>'
			+ guideCode('X-Data-Form-Email: jean.dupont@example.com\nX-Data-Form: {"firstname":"Jean","lastname":"Dupont","company":"Dupont SARL","phone":"+33 1 23 45 67 89","siren":"123456789","vat":"FR12123456789","id":"D-1042"}')
			+ '<p class="ibx-muted">' + th('InboxSourceFormatRules') + '</p></section></div>';
		main.innerHTML = html;
		copyCodeOn(main);
		const blank = { id: 0, label: '', status: 1, senders: '', header_email: '', header_data: '', header_fields: '', mapping: '', type: '' };
		$('.ibx-js-srcadd', main).addEventListener('click', () => sourceDialog(main, r, blank));
		// « Make a data source from this mail » (menu of a mail, 1.74.0): the form opens on its analysis, once
		if (parts && parts[0] === 'new' && +parts[1] > 0) {
			history.replaceState(null, '', '#/settings/sources');
			sourceDialog(main, r, blank, { mail: +parts[1] });
		}
		main.onclick = async e => {
			const ed = e.target.closest('[data-edit]');
			if (ed) {
				sourceDialog(main, r, list.find(d => d.id === +ed.dataset.edit));
				return;
			}
			// The switch (1.92.3): shown at once, saved by the save of the form (the conversations are made again when
			// it becomes active), back to its state with the error when refused; no message when saved
			const sw = e.target.closest('[data-toggle]');
			if (sw) {
				if (sw.dataset.busy) {
					return;
				}
				const d = list.find(x => x.id === +sw.dataset.toggle);
				const on = sw.getAttribute('aria-checked') !== 'true';
				const tr = sw.closest('tr');
				sw.dataset.busy = '1';
				sw.setAttribute('aria-checked', on ? 'true' : 'false');
				tr.classList.toggle('is-off', !on);
				try {
					await post('source', 'save', { source_id: d.id, label: d.label, status: on ? 1 : 0, senders: d.senders, header_email: d.header_email,
						header_data: d.header_data, header_fields: d.header_fields, mapping: d.mapping, type: d.type });
				} catch (err) {
					sw.setAttribute('aria-checked', on ? 'false' : 'true');
					tr.classList.toggle('is-off', on);
					delete sw.dataset.busy;
					toast(err.message, { error: true });
					return;
				}
				d.status = on ? 1 : 0;
				tr.outerHTML = sourceRow(d);
				$('[data-toggle="' + d.id + '"]', main).focus();
				return;
			}
			const more = e.target.closest('[data-more]');
			if (!more) {
				return;
			}
			const d = list.find(x => x.id === +more.dataset.more);
			I.menu(more, [
				{ icon: 'delete', label: t('InboxDelete'), danger: true, run: async () => {
					if (await confirmDialog({ title: t('InboxSourceDeleteTitle', d.label), text: t('InboxSourceDeleteText'), confirm: t('InboxDelete'), danger: true })) {
						try {
							await post('source', 'delete', { source_id: d.id });
							toast(t('InboxSourceDeleted', d.label));
							renderSources(main);
						} catch (err) {
							toast(err.message, { error: true });
						}
					}
				} },
			]);
		};
	}

	/**
	 * Form of a data source (1.74.0): a ready-made source, or the settings read on a mail of the site (checked and
	 * corrected here, saved only by the button); the pairs as rows with a list of fields; « ? » beside each setting
	 * opens its entry in the glossary at the bottom
	 */
	function sourceDialog(main, r, d, opts) {
		opts = opts || {};
		const FIELDS = r.fields || [];
		const fieldLabel = f => t('InboxSourceField_' + f);
		const fieldOptions = (cur, withIgnore) => (withIgnore ? '<option value="">' + th('InboxSourceIgnore') + '</option>' : '')
			+ FIELDS.map(f => '<option value="' + esc(f) + '"' + (f === cur ? ' selected' : '') + '>' + esc(fieldLabel(f)) + '</option>').join('')
			+ (cur === 'ignore' ? '<option value="ignore" selected>' + th('InboxSourceIgnore') + '</option>' : '');
		const pairs = text => String(text || '').split('\n').map(l => l.split(/[=:]/)).filter(p => p.length > 1 && p[0].trim()).map(p => [p[0].trim(), p[1].trim()]);
		const rowHtml = (name, key, field) => '<div class="ibx-srcrow"><input class="ibx-field" type="text" value="' + esc(key) + '" aria-label="' + th(name === 'header_fields' ? 'InboxSourceRowHeader' : 'InboxSourceRowKey') + '"'
			+ ' placeholder="' + (name === 'header_fields' ? 'X-Form-Phone' : 'raison_sociale') + '" spellcheck="false" autocomplete="off">'
			+ ic('chevron-right') + '<select class="ibx-field" aria-label="' + th('InboxSourceRowField') + '">' + fieldOptions(field, name === 'mapping') + '</select>'
			+ '<button type="button" class="ibx-btn ibx-btn--sm ibx-btn--ghost ibx-btn--icon ibx-js-rowdel" title="' + th('InboxDelete') + '" aria-label="' + th('InboxDelete') + '">' + ic('close') + '</button></div>';
		const rows = (name, label, value, help) => '<div class="ibx-formrow" data-field="' + name + '"><span class="ibx-formrow__label">' + esc(label) + '</span><div>'
			+ '<div class="ibx-srcrows" data-rows="' + name + '">' + pairs(value).map(p => rowHtml(name, p[0], p[1])).join('') + '</div>'
			+ '<button type="button" class="ibx-btn ibx-btn--sm ibx-btn--ghost ibx-js-rowadd" data-rows="' + name + '">' + ic('plus') + ' ' + th('InboxSourceRowAdd') + '</button>'
			+ '<div class="ibx-formrow__help">' + esc(help) + '</div></div></div>';
		const area = (name, label, value, help, placeholder, n) => '<div class="ibx-formrow" data-field="' + name + '"><label class="ibx-formrow__label" for="f-' + name + '">' + esc(label) + '</label><div>'
			+ '<textarea class="ibx-field" id="f-' + name + '" name="' + name + '" rows="' + (n || 3) + '" spellcheck="false"' + (placeholder ? ' placeholder="' + esc(placeholder) + '"' : '') + '>' + esc(value || '') + '</textarea>'
			+ (help ? '<div class="ibx-formrow__help">' + esc(help) + '</div>' : '') + '</div></div>';
		// Glossary: the words, then the fields with what they fill and the names understood without setting
		const TERMS = ['header', 'sender', 'auth', 'json', 'type'];
		const glossary = '<details class="ibx-srcgloss"><summary>' + ic('info') + ' ' + th('InboxSourceGlossary') + '</summary><dl class="ibx-srcgloss__terms">'
			+ TERMS.map(k => '<dt id="ibx-gloss-' + k + '">' + th('InboxSourceTerm_' + k) + '</dt><dd>' + th('InboxSourceTermHelp_' + k) + '</dd>').join('') + '</dl>'
			+ '<table class="ibx-table ibx-srcgloss__fields" id="ibx-gloss-fields"><thead><tr><th>' + th('InboxSourceGlossField') + '</th><th>' + th('InboxSourceGlossFills') + '</th><th>' + th('InboxSourceGlossNames') + '</th></tr></thead><tbody>'
			+ (r.glossary || []).map(g => '<tr><td><b>' + esc(fieldLabel(g.code)) + '</b><div class="ibx-muted">' + th('InboxSourceGlossExample', t('InboxSourceFieldExample_' + g.code)) + '</div></td>'
				+ '<td>' + th('InboxSourceFieldFills_' + g.code) + '</td><td><code>' + esc(g.names.join(', ')) + '</code></td></tr>').join('')
			+ '</tbody></table></details>';
		// Active first (1.92.3): a switch saved with the form (« Cancel » undoes it too), its hint says what it does
		const body = '<form class="ibx-form" novalidate>'
			+ '<div class="ibx-formrow"><span class="ibx-formrow__label" id="f-status-label">' + th('InboxSourceOn') + '</span><div class="ibx-inline" style="gap:var(--ibx-s3)">'
			+ '<button type="button" class="ibx-switch ibx-js-srcstatus" role="switch" aria-checked="' + (d.status ? 'true' : 'false') + '" aria-labelledby="f-status-label" aria-describedby="f-status-hint"></button>'
			+ '<span class="ibx-formrow__help ibx-srcstatus__hint" id="f-status-hint" aria-live="polite"></span></div></div>'
			// « Start from » (a ready-made source, a mail of the site) only to create: on a saved source it would overwrite it
			+ (d.id ? '' : '<div class="ibx-srcstart">'
			+ '<label class="ibx-inline">' + th('InboxSourceTemplate') + ' <select class="ibx-field ibx-js-srctpl"><option value="">' + th('InboxSourceTemplateNone') + '</option>'
			+ Object.keys(r.templates || {}).map(k => '<option value="' + esc(k) + '">' + th('InboxSourceTemplate_' + k) + '</option>').join('') + '</select></label>'
			+ '<button type="button" class="ibx-btn ibx-btn--sm ibx-js-srcfrommail" aria-expanded="false">' + ic('mail') + ' ' + th('InboxSourceFromMail') + '</button></div>'
			+ '<div class="ibx-srcfrom" hidden><p class="ibx-formrow__help">' + th('InboxSourceFromMailHelp') + '</p>'
			+ '<textarea class="ibx-field ibx-js-srcpaste" rows="6" spellcheck="false" aria-label="' + th('InboxSourceTestHeaders') + '"></textarea>'
			+ '<div><button type="button" class="ibx-btn ibx-btn--sm ibx-js-srcanalyze">' + ic('search') + ' ' + th('InboxSourceAnalyze') + '</button></div></div>'
			+ '<div class="ibx-srcan" aria-live="polite"></div>')
			+ field('label', t('InboxSourceLabel'), d.label, { required: true, autocomplete: 'off', placeholder: t('InboxSourceLabelPlaceholder'), help: t('InboxSourceLabelHelp') })
			+ area('senders', t('InboxSourceSenders'), d.senders, t('InboxSourceSendersHelp'), 'noreply@monsite.fr\nboutique.example.com')
			+ field('header_email', t('InboxSourceHeaderEmail'), d.header_email, { autocomplete: 'off', placeholder: 'X-Data-Form-Email', help: t('InboxSourceHeaderEmailHelp') })
			+ field('header_data', t('InboxSourceHeaderData'), d.header_data, { autocomplete: 'off', placeholder: 'X-Data-Form', help: t('InboxSourceHeaderDataHelp') })
			+ rows('header_fields', t('InboxSourceHeaderFields'), d.header_fields, t('InboxSourceHeaderFieldsRowsHelp'))
			+ rows('mapping', t('InboxSourceMapping'), d.mapping, t('InboxSourceMappingRowsHelp'))
			+ field('type', t('InboxSourceType'), d.type, { options: (r.types || ['']).map(v => [v, t('InboxSourceType_' + (v || 'none'))]), help: t('InboxSourceTypeHelp') })
			+ '<details class="ibx-srctest"><summary>' + th('InboxSourceTest') + '</summary>'
			+ area('test_headers', t('InboxSourceTestHeaders'), '', t('InboxSourceTestHelp'), '', 6)
			+ '<div class="ibx-formrow"><span class="ibx-formrow__label"></span><div><button type="button" class="ibx-btn ibx-btn--sm ibx-js-srctest">' + ic('check') + ' ' + th('InboxSourceTestRun') + '</button>'
			+ '<div class="ibx-srctest__out" aria-live="polite"></div></div></div></details>'
			+ glossary
			+ '</form>';
		const HELP = { label: 'type', senders: 'sender', header_email: 'header', header_data: 'json', header_fields: 'fields', mapping: 'fields', type: 'type' };
		const serialize = (dlg, name) => $$('[data-rows="' + name + '"] .ibx-srcrow', dlg).map(row => [$('input', row).value.trim(), $('select', row).value])
			.filter(p => p[0] && p[1]).map(p => p[0] + ' = ' + p[1]).join('\n');
		const values = dlg => {
			const v = formValues($('form', dlg));
			delete v.test_headers;
			v.status = $('.ibx-js-srcstatus', dlg).getAttribute('aria-checked') === 'true' ? 1 : 0;
			v.header_fields = serialize(dlg, 'header_fields');
			v.mapping = serialize(dlg, 'mapping');
			return v;
		};
		const setRows = (dlg, name, list) => {
			$('[data-rows="' + name + '"].ibx-srcrows', dlg).innerHTML = list.map(p => rowHtml(name, p[0], p[1])).join('');
		};
		const showGloss = (dlg, id) => {
			const g = $('.ibx-srcgloss', dlg);
			g.open = true;
			const target = $('#ibx-gloss-' + id, dlg);
			if (target) {
				target.scrollIntoView({ block: 'center', behavior: 'smooth' });
				target.classList.add('is-flash');
				setTimeout(() => target.classList.remove('is-flash'), 1600);
			}
		};
		// The analysis of a mail: each header of the site with its role, the keys of its JSON with their field
		const paintAnalysis = (dlg, an) => {
			const zone = $('.ibx-srcan', dlg);
			if (!an.headers.length) {
				zone.innerHTML = '<div class="ibx-notice ibx-notice--warning">' + ic('warning') + '<div class="ibx-notice__text">' + th('InboxSourceAnalyzeNone') + '</div></div>';
				return;
			}
			const roles = ['ignore', 'email', 'data', 'field'];
			zone.innerHTML = '<div class="ibx-srcan__box"><div class="ibx-srcan__head"><b>' + esc(t('InboxSourceAnalyzeFrom', an.from)) + '</b> '
				+ (an.authenticated ? '<span class="ibx-badge ibx-badge--accent">' + ic('check') + th('InboxSourceAnalyzeAuth') + '</span>' : '<span class="ibx-badge">' + ic('warning') + th('InboxSourceAnalyzeNoAuth') + '</span>') + '</div>'
				+ (an.authenticated ? '' : '<p class="ibx-formrow__help">' + th('InboxSourceAnalyzeNoAuthHelp') + '</p>')
				+ an.headers.map((h, i) => '<div class="ibx-srcan__item" data-i="' + i + '"><div class="ibx-srcan__line"><code>' + esc(h.name) + '</code>'
					+ '<select class="ibx-field ibx-js-anrole" aria-label="' + esc(t('InboxSourceAnalyzeRole', h.name)) + '">' + roles.map(ro => '<option value="' + ro + '"' + (ro === h.role ? ' selected' : '') + '>' + th('InboxSourceRole_' + ro) + '</option>').join('') + '</select>'
					+ '<select class="ibx-field ibx-js-anfield"' + (h.role === 'field' ? '' : ' hidden') + ' aria-label="' + th('InboxSourceRowField') + '">' + fieldOptions(h.field, false) + '</select></div>'
					+ (h.keys.length ? '<table class="ibx-table ibx-srcan__keys"' + (h.role === 'data' ? '' : ' hidden') + '><tbody>' + h.keys.map((k, j) => '<tr data-j="' + j + '"><td><code>' + esc(k.key) + '</code></td><td class="ibx-muted ibx-ellipsis">' + esc(k.value) + '</td>'
						+ '<td><select class="ibx-field ibx-js-ankey" aria-label="' + esc(t('InboxSourceAnalyzeKey', k.key)) + '">' + fieldOptions(k.field, true) + '</select></td></tr>').join('') + '</tbody></table>'
						: '<div class="ibx-muted ibx-ellipsis">' + esc(h.value) + '</div>')
					+ '</div>').join('')
				+ '<div class="ibx-srcan__foot"><button type="button" class="ibx-btn ibx-btn--sm ibx-btn--primary ibx-js-anapply">' + ic('check') + ' ' + th('InboxSourceAnalyzeApply') + '</button></div></div>';
			zone.onchange = e => {
				const item = e.target.closest('.ibx-srcan__item');
				if (item && e.target.classList.contains('ibx-js-anrole')) {
					$('.ibx-js-anfield', item).hidden = e.target.value !== 'field';
					const keys = $('.ibx-srcan__keys', item);
					if (keys) {
						keys.hidden = e.target.value !== 'data';
					}
				}
			};
			$('.ibx-js-anapply', zone).addEventListener('click', () => {
				const form = $('form', dlg);
				let email = '';
				let data = '';
				const fieldRows = [];
				const keyRows = [];
				$$('.ibx-srcan__item', zone).forEach(item => {
					const h = an.headers[+item.dataset.i];
					const role = $('.ibx-js-anrole', item).value;
					if (role === 'email' && !email) {
						email = h.name;
					} else if (role === 'data' && !data) {
						data = h.name;
						// Only what differs from the names understood without setting
						$$('tr[data-j]', item).forEach(tr => {
							const k = h.keys[+tr.dataset.j];
							const chosen = $('select', tr).value;
							if (chosen !== (k.usual ? k.field : '')) {
								keyRows.push([k.key, chosen || 'ignore']);
							}
						});
					} else if (role === 'field' && $('.ibx-js-anfield', item).value) {
						fieldRows.push([h.name, $('.ibx-js-anfield', item).value]);
					}
				});
				const set = (name, v) => {
					const f = $('[name="' + name + '"]', form);
					f.value = v;
				};
				if (an.from && !$('[name="senders"]', form).value.split(/\s+/).includes(an.from)) {
					set('senders', ($('[name="senders"]', form).value.trim() + '\n' + an.from).trim());
				}
				if (!$('[name="label"]', form).value.trim()) {
					set('label', an.domain);
				}
				set('header_email', email);
				set('header_data', data);
				setRows(dlg, 'header_fields', fieldRows);
				setRows(dlg, 'mapping', keyRows);
				zone.innerHTML = '<div class="ibx-notice ibx-notice--info">' + ic('check') + '<div class="ibx-notice__text">' + th('InboxSourceAnalyzeApplied') + '</div></div>';
				$('[name="label"]', form).focus();
			});
		};
		const analyze = async (dlg, data) => {
			const zone = $('.ibx-srcan', dlg);
			zone.innerHTML = '<span class="ibx-skeleton" style="width:60%"></span>';
			try {
				paintAnalysis(dlg, await post('source', 'analyze', data));
			} catch (err) {
				zone.innerHTML = '<div class="ibx-notice ibx-notice--error">' + ic('warning') + '<div class="ibx-notice__text">' + esc(err.message) + '</div></div>';
			}
		};
		dialog({
			title: d.id ? t('InboxSourceEdit', d.label) : t('InboxSourceAdd'),
			wide: true,
			body,
			actions: [{ label: d.id ? t('InboxSave') : t('InboxSourceAddButton'), primary: true, run: async dlg => {
				try {
					await post('source', 'save', Object.assign({ source_id: d.id }, values(dlg)));
				} catch (err) {
					// The dialog stays open with what was typed
					toast(err.message, { error: true });
					return false;
				}
				toast(t('InboxSaved'));
				renderSources(main);
			} }],
			onOpen: dlg => {
				const status = $('.ibx-js-srcstatus', dlg);
				const senders = $('[name="senders"]', dlg);
				const statusHint = () => {
					const on = status.getAttribute('aria-checked') === 'true';
					$('.ibx-srcstatus__hint', dlg).textContent = t(!on ? 'InboxSourceOffHint' : (senders.value.trim() ? 'InboxSourceOnHint' : 'InboxSourceNoEffectHint'));
				};
				status.addEventListener('click', () => {
					status.setAttribute('aria-checked', status.getAttribute('aria-checked') === 'true' ? 'false' : 'true');
					statusHint();
				});
				senders.addEventListener('input', statusHint);
				statusHint();
				// « ? » beside each setting: its entry in the glossary
				Object.keys(HELP).forEach(name => {
					const label = $('[data-field="' + name + '"] .ibx-formrow__label', dlg);
					if (label) {
						label.insertAdjacentHTML('beforeend', ' <button type="button" class="ibx-srchelp" data-gloss="' + HELP[name] + '" title="' + th('InboxSourceGlossaryOpen') + '" aria-label="' + esc(t('InboxSourceGlossaryOpen')) + '">?</button>');
					}
				});
				dlg.addEventListener('click', e => {
					const help = e.target.closest('.ibx-srchelp');
					if (help) {
						e.preventDefault();
						showGloss(dlg, help.dataset.gloss);
						return;
					}
					const add = e.target.closest('.ibx-js-rowadd');
					if (add) {
						$('[data-rows="' + add.dataset.rows + '"].ibx-srcrows', dlg).insertAdjacentHTML('beforeend', rowHtml(add.dataset.rows, '', add.dataset.rows === 'mapping' ? '' : FIELDS[0]));
						$('[data-rows="' + add.dataset.rows + '"].ibx-srcrows .ibx-srcrow:last-child input', dlg).focus();
						return;
					}
					const del = e.target.closest('.ibx-js-rowdel');
					if (del) {
						del.closest('.ibx-srcrow').remove();
						return;
					}
					const from = e.target.closest('.ibx-js-srcfrommail');
					if (from) {
						const zone = $('.ibx-srcfrom', dlg);
						zone.hidden = !zone.hidden;
						from.setAttribute('aria-expanded', String(!zone.hidden));
						if (!zone.hidden) {
							$('.ibx-js-srcpaste', dlg).focus();
						}
						return;
					}
					if (e.target.closest('.ibx-js-srcanalyze')) {
						analyze(dlg, { headers: $('.ibx-js-srcpaste', dlg).value });
					}
				});
				const tplSelect = $('.ibx-js-srctpl', dlg);
				if (tplSelect) {
					tplSelect.addEventListener('change', e => {
						const tpl = (r.templates || {})[e.target.value];
						if (!tpl) {
							return;
						}
						const form = $('form', dlg);
						$('[name="header_email"]', form).value = tpl.header_email || '';
						$('[name="header_data"]', form).value = tpl.header_data || '';
						$('[name="type"]', form).value = tpl.type || '';
						if (!$('[name="label"]', form).value.trim()) {
							$('[name="label"]', form).value = t('InboxSourceTemplate_' + e.target.value);
						}
						toast(t('InboxSourceTemplateApplied'));
						$('[name="senders"]', form).focus();
					});
				}
				$('.ibx-js-srctest', dlg).addEventListener('click', async () => {
					const out = $('.ibx-srctest__out', dlg);
					let res;
					try {
						res = await post('source', 'test', Object.assign({ headers: $('[name="test_headers"]', dlg).value }, values(dlg)));
					} catch (err) {
						out.innerHTML = '<div class="ibx-notice ibx-notice--error">' + ic('warning') + '<div class="ibx-notice__text">' + esc(err.message) + '</div></div>';
						return;
					}
					const f = res.result;
					if (!f) {
						out.innerHTML = '<div class="ibx-notice ibx-notice--warning">' + ic('warning') + '<div class="ibx-notice__text">' + esc(t('InboxSourceTestNotSender', res.from || '?')) + '</div></div>';
						return;
					}
					if (!f.ok) {
						out.innerHTML = '<div class="ibx-notice ibx-notice--warning">' + ic('warning') + '<div class="ibx-notice__text">' + th('InboxSourceRefused_' + f.why) + '</div></div>';
						return;
					}
					out.innerHTML = '<div class="ibx-notice ibx-notice--info">' + ic('check') + '<div class="ibx-notice__text">' + esc(t('InboxSourceTestOk', f.name || f.email, f.email)) + '</div></div>'
						+ '<table class="ibx-table"><tbody>' + Object.keys(f.data).map(k => '<tr><th>' + esc(fieldLabel(k)) + '</th><td>' + esc(f.data[k]) + '</td></tr>').join('') + '</tbody></table>';
				});
				if (opts.mail) {
					// Opened from a mail (menu of the list): its settings read at once
					analyze(dlg, { mail: opts.mail });
				} else {
					$('[name="label"]', dlg).focus();
				}
			},
		});
	}

	async function renderServer(main, parts) {
		if (!isDolibarrAdmin()) {
			I.go('#/settings/accounts');
			return;
		}
		main.innerHTML = skeleton();
		let s;
		try {
			s = await api('server', 'get');
		} catch (err) {
			main.replaceChildren(errorBox(err, () => renderServer(main, parts)));
			return;
		}
		const title = id => sectionTitle(SERVER_SECTIONS, id);
		const number = name => {
			const d = s.numbers[name];
			return '<div class="ibx-formrow"><label class="ibx-formrow__label" for="sv-' + name + '">' + th(name) + '</label><div><div class="ibx-inline">'
				+ '<input class="ibx-field ibx-field--narrow" type="number" inputmode="numeric" id="sv-' + name + '" data-number="' + name + '" min="' + d.min + '" max="' + d.max + '" value="' + esc(d.value) + '"> '
				+ th(d.unit === 'days' ? 'InboxDays' : 'InboxSeconds') + '</div><div class="ibx-formrow__help">' + th(name + 'Help') + '</div></div></div>';
		};
		const m = s.master;
		main.innerHTML = '<div class="ibx-page"><div class="ibx-page__head"><h1 class="ibx-page__title">' + th('InboxNavServer') + '</h1></div><p class="ibx-page__intro">' + th('InboxServerIntro') + '</p>'
			+ '<nav class="ibx-subchips" aria-label="' + th('InboxNavServer') + '">' + sectionLinks('server', SERVER_SECTIONS, 'ibx-subchip') + '</nav>'
			+ '<section class="ibx-section" data-section="keep">' + title('keep') + '<div class="ibx-form">' + ['INBOX_CACHE_DAYS', 'INBOX_LOG_DAYS', 'INBOX_ACCOUNT_LOG_DAYS', 'INBOX_GONE_LINKED_DAYS'].map(number).join('') + '</div></section>'
			+ '<section class="ibx-section" data-section="imap">' + title('imap') + '<div class="ibx-form">' + ['INBOX_IMAP_TIMEOUT', 'INBOX_TEST_TIMEOUT'].map(number).join('') + '</div></section>'
			// Refresh of the lists and push service (1.124.0): global settings, the load next to them
			+ '<section class="ibx-section" data-section="refresh">' + title('refresh') + '<p class="ibx-section__help">' + th('InboxReactivitySetupHelp') + '</p></section>'
			+ '<section class="ibx-section" data-section="push">' + title('push') + '<p class="ibx-section__help">' + th('InboxPushSetupHelp') + '</p></section>'
			// Master user: one form (the four values go together), the password never shown again
			+ '<section class="ibx-section" data-section="master">' + title('master') + '<p class="ibx-section__help">' + th('InboxMasterUserHelp') + '</p>'
			+ '<form class="ibx-form ibx-js-master" autocomplete="off">'
			+ '<div class="ibx-formrow"><label class="ibx-formrow__label" for="sv-muser">' + th('InboxMasterUserName') + '</label><div><input class="ibx-field ibx-field--medium" type="text" id="sv-muser" name="user" autocomplete="off" spellcheck="false" value="' + esc(m.user) + '"></div></div>'
			+ '<div class="ibx-formrow"><label class="ibx-formrow__label" for="sv-mhost">' + th('InboxMasterHost') + '</label><div><input class="ibx-field ibx-field--medium" type="text" id="sv-mhost" name="host" autocomplete="off" spellcheck="false" placeholder="mail.example.com" value="' + esc(m.host) + '"><div class="ibx-formrow__help">' + th('InboxMasterHostHelp') + '</div></div></div>'
			+ '<div class="ibx-formrow"><label class="ibx-formrow__label" for="sv-msep">' + th('InboxMasterSeparator') + '</label><div><input class="ibx-field ibx-field--narrow" type="text" id="sv-msep" name="separator" maxlength="2" autocomplete="off" spellcheck="false" value="' + esc(m.separator) + '"><div class="ibx-formrow__help">' + th('InboxMasterSeparatorHelp') + '</div></div></div>'
			+ '<div class="ibx-formrow"><label class="ibx-formrow__label" for="sv-mpass">' + th('InboxPassword') + '</label><div><input class="ibx-field ibx-field--medium" type="password" id="sv-mpass" name="password" autocomplete="new-password" placeholder="' + (m.password_kept ? th('InboxPasswordKept') : '') + '">'
			+ (m.password_kept ? '<div class="ibx-formrow__help">' + th('InboxPasswordKeptHelp') + '</div>' : '') + '</div></div>'
			+ '<div class="ibx-formrow"><span class="ibx-formrow__label"></span><div><button type="submit" class="ibx-btn ibx-btn--primary">' + th('InboxSave') + '</button></div></div>'
			+ '</form></section>'
			+ '<section class="ibx-section" data-section="cron">' + title('cron') + '<p class="ibx-section__help">' + th('InboxServerCronHelp') + '</p><div class="ibx-js-cron"></div></section></div>';
		// Numbers: saved field by field, at once; refused: the value of the server back
		$$('[data-number]', main).forEach(f => f.addEventListener('change', async () => {
			try {
				await post('server', 'set', { name: f.dataset.number, value: f.value });
				s.numbers[f.dataset.number].value = +f.value;
				toast(t('InboxSaved'));
			} catch (err) {
				f.value = s.numbers[f.dataset.number].value;
				toast(err.message, { error: true });
				f.focus();
			}
		}));
		const form = $('.ibx-js-master', main);
		form.addEventListener('submit', async e => {
			e.preventDefault();
			const btn = $('[type="submit"]', form);
			btn.disabled = true;
			try {
				const r = await post('server', 'master', { user: form.elements.user.value, host: form.elements.host.value, separator: form.elements.separator.value, password: form.elements.password.value });
				form.elements.password.value = '';
				form.elements.password.placeholder = r.master.password_kept ? t('InboxPasswordKept') : '';
				form.elements.host.value = r.master.host;
				toast(t('InboxSaved'));
			} catch (err) {
				// What was typed stays
				toast(err.message, { error: true });
			}
			btn.disabled = false;
		});
		paintCron($('.ibx-js-cron', main), s.cron);
		let refreshView = null;
		const pushView = paintPush($('[data-section="push"]', main), s.reactivity.push, s.reactivity.mode, ready => refreshView && refreshView.setReady(ready));
		refreshView = paintReactivity($('[data-section="refresh"]', main), s.reactivity, m2 => pushView.setMode(m2));
		spySections(main, SERVER_SECTIONS.map(x => x[0]));
		if (parts && parts[0]) {
			requestAnimationFrame(() => pointSection(main, parts[0]));
		}
	}

	/** Scheduled jobs of the module: what is wrong said first, then each job with its switch and its card */
	function paintCron(box, cron) {
		const jobs = cron.jobs || [];
		const off = jobs.filter(j => j.needed && !j.active);
		const late = jobs.filter(j => j.active && j.late);
		const failed = jobs.filter(j => j.active && j.last_ok === 0);
		let html = '';
		if (!cron.enabled) {
			html += '<div class="ibx-notice ibx-notice--error">' + ic('warning') + '<div class="ibx-notice__text">' + th('InboxServerCronModuleOff') + '</div></div>';
		}
		if (off.length) {
			html += '<div class="ibx-notice ibx-notice--error">' + ic('warning') + '<div class="ibx-notice__text">' + th('InboxServerCronNeededOff', off.map(j => j.label).join(', ')) + '</div></div>';
		}
		if (cron.enabled && late.length) {
			html += '<div class="ibx-notice ibx-notice--warning">' + ic('warning') + '<div class="ibx-notice__text">'
				+ th(late.length === jobs.filter(j => j.active).length ? 'InboxServerCronNotRunning' : 'InboxServerCronLate', late.map(j => j.label).join(', ')) + '</div></div>';
		}
		if (failed.length) {
			html += '<div class="ibx-notice ibx-notice--warning">' + ic('warning') + '<div class="ibx-notice__text">' + th('InboxServerCronFailed', failed.map(j => j.label).join(', ')) + '</div></div>';
		}
		if (!jobs.length) {
			box.innerHTML = html + '<p class="ibx-muted">' + th('InboxServerCronNone') + '</p>';
			return;
		}
		const when = ts => (ts ? '<span title="' + esc(new Date(ts * 1000).toLocaleString(I.locale())) + '">' + esc(ago(ts)) + '</span>' : '<span class="ibx-muted">' + th('InboxServerCronNever') + '</span>');
		html += '<div class="ibx-tablewrap"><table class="ibx-table ibx-crontable"><thead><tr><th>' + th('InboxServerCronJob') + '</th><th>' + th('InboxServerCronActive') + '</th><th>' + th('InboxServerCronEvery')
			+ '</th><th>' + th('InboxServerCronLast') + '</th><th>' + th('InboxServerCronNext') + '</th></tr></thead><tbody>'
			+ jobs.map(j => '<tr>'
				+ '<td><a href="' + esc(j.url) + '" target="_blank" rel="noopener">' + esc(j.label) + ' ' + ic('external', 'ibx-faint') + '</a>'
				+ (j.needed ? ' <span class="ibx-badge">' + th('InboxServerCronNeeded') + '</span>' : '') + '</td>'
				+ '<td><button type="button" class="ibx-switch ibx-js-cronswitch" role="switch" data-job="' + j.id + '" aria-checked="' + (j.active ? 'true' : 'false') + '" aria-label="' + th('InboxServerCronActive') + ' ' + esc(j.label) + '"></button></td>'
				+ '<td class="ibx-nowrap">' + esc(everyText(j.every)) + '</td>'
				+ '<td>' + (j.running ? '<span class="ibx-status ibx-status--warning">' + th('InboxServerCronRunning') + '</span>'
					: (j.last ? '<span class="ibx-status ibx-status--' + (j.last_ok ? 'ok' : 'error') + '">' + when(j.last) + '</span>' : when(0)))
				+ (j.last_output ? '<div class="ibx-formrow__error">' + esc(j.last_output) + '</div>' : '') + '</td>'
				+ '<td class="ibx-nowrap">' + (j.active ? when(j.next) : '<span class="ibx-muted">' + th('InboxServerCronOff') + '</span>') + '</td></tr>').join('')
			+ '</tbody></table></div>';
		box.innerHTML = html;
		// On or off at once; back if the server refuses
		$$('.ibx-js-cronswitch', box).forEach(sw => sw.addEventListener('click', async () => {
			const on = sw.getAttribute('aria-checked') !== 'true';
			sw.setAttribute('aria-checked', on ? 'true' : 'false');
			try {
				const r = await post('server', 'job', { id: +sw.dataset.job, active: on ? 1 : 0 });
				paintCron(box, r.cron);
			} catch (err) {
				sw.setAttribute('aria-checked', on ? 'false' : 'true');
				toast(err.message, { error: true });
			}
		}));
	}

	// ---------- Refresh of the lists (1.122.0) and push service (1.124.0): Settings > Server ----------

	/** Version of Mercure tested with inbox, in the examples of the installation guide */
	const MERCURE_VERSION = 'v1.1.6';

	/**
	 * Load of the server with the settings shown: each figure says which setting it comes from (the intervals typed
	 * are in its label), computed again as they are typed. Calls counted for every user reaching a mailbox, as if all
	 * were connected at once
	 * @param {HTMLElement} box Zone of the load
	 * @param {object} r Data of the server (users, light_ms, full_ms)
	 * @param {string} mode Mode chosen
	 * @param {{interval:number, idle:number}} rhythm Intervals typed
	 */
	function paintLoad(box, r, mode, rhythm) {
		const n = r.users;
		const num = (v, digits) => {
			const floor = Math.pow(10, -digits);
			return v > 0 && v < floor ? '< ' + floor.toLocaleString(I.locale()) : v.toLocaleString(I.locale(), { minimumFractionDigits: digits, maximumFractionDigits: digits });
		};
		const fig = (label, light, full, mod) => {
			const calls = light + full;
			const cores = (light * r.light_ms + full * r.full_ms) / 1000;
			return I.html`<div class="ibx-load__fig${mod ? ' ibx-load__fig--' + mod : ''}"><dt class="ibx-load__label">${label}</dt>`
				+ I.html`<dd class="ibx-load__value">${t(calls < 2 ? 'InboxReactivityLoadCall' : 'InboxReactivityLoadCalls', num(calls, 1))}</dd>`
				+ I.html`<dd class="ibx-load__cpu">${t(cores < 2 ? 'InboxReactivityLoadCore' : 'InboxReactivityLoadCores', num(cores, 2))}</dd></div>`;
		};
		const standard = (label, mod) => fig(label, 0, n / 20, mod);
		let figs;
		if (mode === 'fast') {
			figs = fig(t('InboxReactivityLoadActive', rhythm.interval), n / rhythm.interval, n / 60)
				+ fig(t('InboxReactivityLoadIdle', rhythm.idle), n / rhythm.idle, n / 300)
				+ standard(t('InboxReactivityLoadStandard'), 'ref');
		} else if (mode === 'instant') {
			figs = fig(t('InboxReactivityLoadPush'), n / 60, n / 60)
				+ fig(t('InboxReactivityLoadPushDown', rhythm.interval), n / rhythm.interval, n / 60)
				+ standard(t('InboxReactivityLoadStandard'), 'ref');
		} else {
			figs = standard(t('InboxReactivityLoadStandardOnly'));
		}
		box.innerHTML = I.html`<div class="ibx-load__head">${I.raw(ic('load'))} ${t('InboxReactivityLoadTitle')}</div>`
			+ I.html`<p class="ibx-load__who">${t('InboxReactivityLoadWho', n)}</p><dl class="ibx-load__figs">${I.raw(figs)}</dl>`
			+ I.html`<p class="ibx-load__note">${t('InboxReactivityLoadNote')}</p>`;
	}

	/**
	 * Section « Refresh of the lists »: the mode (saved at once), the two intervals (saved when left, the idle one
	 * follows the active one), the load just under them
	 * @param {HTMLElement} box Section
	 * @param {object} r Data of the server
	 * @param {Function} onMode Called with the mode saved (the section of the push service shows it)
	 */
	function paintReactivity(box, r, onMode) {
		const modes = ['standard', 'fast', 'instant'];
		const label = { standard: 'InboxReactivityStandard', fast: 'InboxReactivityFast', instant: 'InboxReactivityInstant' };
		const numberRow = (name, key, min, max, value) => I.html`<div class="ibx-formrow"><label class="ibx-formrow__label" for="rx-${name}">${t(key)}</label><div><div class="ibx-inline">`
			+ I.html`<input class="ibx-field ibx-field--narrow" type="number" inputmode="numeric" id="rx-${name}" name="${name}" min="${min}" max="${max}" value="${value}"> ${t('InboxSeconds')}</div>`
			+ I.html`<div class="ibx-formrow__help">${t(key + 'Help')}</div></div></div>`;
		box.insertAdjacentHTML('beforeend', I.html`<div class="ibx-form">`
			+ I.html`<div class="ibx-formrow"><span class="ibx-formrow__label" id="rx-mode-label">${t('InboxReactivityMode')}</span><div class="ibx-stack" role="radiogroup" aria-labelledby="rx-mode-label">`
			+ I.raw(modes.map(m => I.html`<label class="ibx-radio ibx-radio--block"><input type="radio" name="rx-mode" value="${m}"${I.raw(r.mode === m ? ' checked' : '')}> <span><b>${t(label[m])}</b><span class="ibx-formrow__help">${t(label[m] + 'Help')}</span>${I.raw(m === 'instant' ? '<span class="ibx-formrow__help ibx-js-rxneed"></span>' : '')}</span></label>`).join(''))
			+ '</div></div>'
			+ I.html`<div class="ibx-js-rxintervals">${I.raw(numberRow('interval', 'InboxReactivityInterval', 3, 60, r.rhythm.interval) + numberRow('idle', 'InboxReactivityIdle', 3, 300, r.rhythm.idle))}</div>`
			+ '</div><div class="ibx-js-rxstate"></div><div class="ibx-load ibx-js-rxload" aria-live="polite"></div>');
		const active = $('[name="interval"]', box);
		const idle = $('[name="idle"]', box);
		const intervals = $('.ibx-js-rxintervals', box);
		const load = $('.ibx-js-rxload', box);
		const clamp = (v, min, max, def) => Math.min(max, Math.max(min, parseInt(v, 10) || def));
		const mode = () => ($('[name="rx-mode"]:checked', box) || {}).value || 'standard';
		/** What is typed, the idle interval never quicker than the active one */
		const typed = fromActive => {
			const a = clamp(active.value, 3, 60, 5);
			idle.min = String(a);
			if (fromActive && (parseInt(idle.value, 10) || 0) < a) {
				idle.value = String(a);
			}
			return { interval: a, idle: clamp(idle.value, a, 300, 60) };
		};
		const instant = $('[name="rx-mode"][value="instant"]', box);
		const show = fromActive => {
			const m = mode();
			// Instant: only with a push service tested with its present settings (kept when already chosen: the
			// lists follow at the fast rhythm meanwhile, said just under)
			instant.disabled = !r.push_ready && r.mode !== 'instant';
			$('.ibx-js-rxneed', box).textContent = r.push_ready ? '' : t('InboxReactivityInstantNeedsTest');
			$('.ibx-js-rxneed', box).hidden = r.push_ready;
			$('.ibx-js-rxstate', box).innerHTML = m === 'instant' && !r.push_ready
				? I.html`<div class="ibx-notice ibx-notice--warning">${I.raw(ic('warning'))}<div class="ibx-notice__text">${t('InboxReactivityInstantNotTested')}</div></div>` : '';
			// The intervals: the fast mode, and the instant one when its service does not answer
			intervals.hidden = m === 'standard';
			$('.ibx-formrow__help', active.closest('.ibx-formrow')).textContent = t(m === 'instant' ? 'InboxReactivityIntervalInstantHelp' : 'InboxReactivityIntervalHelp');
			// Instant not usable: the lists follow at the fast rhythm, the load is the one of the fast mode
			paintLoad(load, r, m === 'instant' && !r.push_ready ? 'fast' : m, typed(fromActive));
		};
		const save = async () => {
			const rhythm = typed(false);
			try {
				const res = await post('server', 'reactivity', { mode: mode(), interval: rhythm.interval, idle: rhythm.idle });
				r.mode = res.mode;
				r.rhythm = res.rhythm;
				r.push_ready = !!res.push_ready;
				active.value = String(res.rhythm.interval);
				idle.value = String(res.rhythm.idle);
				show(false);
				onMode(res.mode);
				toast(t('InboxSaved'));
			} catch (err) {
				// Refused: the values of the server back
				$$('[name="rx-mode"]', box).forEach(x => {
					x.checked = x.value === r.mode;
				});
				active.value = String(r.rhythm.interval);
				idle.value = String(r.rhythm.idle);
				show(false);
				toast(err.message, { error: true });
			}
		};
		$$('[name="rx-mode"]', box).forEach(x => x.addEventListener('change', () => {
			show(false);
			save();
		}));
		active.addEventListener('input', () => show(true));
		idle.addEventListener('input', () => show(false));
		[active, idle].forEach(f => f.addEventListener('change', save));
		show(false);
		return {
			/** The push service passed (or failed) its test, or its settings changed */
			setReady(ready) {
				r.push_ready = !!ready;
				show(false);
			},
		};
	}

	/**
	 * Section « Push service »: the driver, its settings (one form: they go together; a secret is never shown again,
	 * left empty it is kept), the button Test, the last failure, the installation guide of Mercure
	 * @param {HTMLElement} box Section
	 * @param {object} push Data of the server
	 * @param {string} mode Mode of the refresh
	 */
	function paintPush(box, push, mode, onReady) {
		let current = push.driver;
		const driverOf = code => push.drivers.find(d => d.code === code) || push.drivers[0];
		const fieldRow = f => {
			const id = 'px-' + f.key;
			const help = f.help ? I.html`<div class="ibx-formrow__help">${f.help}</div>` : '';
			if (f.type === 'secret') {
				return I.html`<div class="ibx-formrow"><label class="ibx-formrow__label" for="${id}">${f.label}</label><div>`
					+ I.html`<input class="ibx-field ibx-field--medium" type="password" id="${id}" name="${f.key}" autocomplete="new-password" spellcheck="false" placeholder="${f.saved ? t('InboxPushSecretKept') : ''}"${I.raw(f.required && !f.saved ? ' required' : '')}>${I.raw(help)}</div></div>`;
			}
			return I.html`<div class="ibx-formrow"><label class="ibx-formrow__label" for="${id}">${f.label}</label><div>`
				+ I.html`<input class="ibx-field ibx-field--medium" type="${f.type === 'url' ? 'url' : 'text'}" id="${id}" name="${f.key}" autocomplete="off" spellcheck="false" value="${f.value}"${I.raw(f.required ? ' required' : '')}>${I.raw(help)}</div></div>`;
		};
		const paint = () => {
			const d = driverOf(current);
			const err = push.last_error;
			box.querySelector('.ibx-js-pxbody').innerHTML = (mode !== 'instant' ? I.html`<div class="ibx-notice ibx-notice--info">${I.raw(ic('info'))}<div class="ibx-notice__text">${t('InboxPushOnlyInstant')}</div></div>` : '')
				+ (err && mode === 'instant' ? I.html`<div class="ibx-notice ibx-notice--warning">${I.raw(ic('warning'))}<div class="ibx-notice__text">${t('InboxPushLastError', new Date(err.at * 1000).toLocaleString(I.locale()), err.message)}</div></div>` : '')
				+ I.html`<form class="ibx-form ibx-js-pxform" autocomplete="off">`
				// The driver is always a choice (one driver today, others from modules); its guide follows it: the one of
				// Mercure here, the documentation a module declares for its own
				+ I.html`<div class="ibx-formrow"><label class="ibx-formrow__label" for="px-driver">${t('InboxPushDriver')}</label><div><div class="ibx-inline">`
				+ I.html`<select class="ibx-field ibx-field--medium" id="px-driver" name="driver">${I.raw(push.drivers.map(x => I.html`<option value="${x.code}"${I.raw(x.code === d.code ? ' selected' : '')}>${x.label}</option>`).join(''))}</select>`
				+ (d.code === 'mercure' ? I.html` <button type="button" class="ibx-linkbtn ibx-js-pxguide">${t('InboxPushGuideLink')}</button>`
					: (d.doc ? I.html` <a class="ibx-linkbtn" href="${d.doc}" target="_blank" rel="noopener noreferrer">${t('InboxPushDriverDoc')} ${I.raw(ic('external', 'ibx-faint'))}</a>` : ''))
				+ I.html`</div><div class="ibx-formrow__help">${t('InboxPushDriverHelp')}</div></div></div>`
				+ I.raw(d.fields.map(fieldRow).join(''))
				+ I.html`<div class="ibx-formrow"><span class="ibx-formrow__label"></span><div class="ibx-inline"><button type="submit" class="ibx-btn ibx-btn--primary">${t('InboxSave')}</button>`
				+ I.html`<button type="button" class="ibx-btn ibx-js-pxtest"${I.raw(d.configured ? '' : ' disabled')}>${I.raw(ic('check'))} ${t('InboxPushTest')}</button></div></div>`
				// Where the test stands: needed to choose « Instant », lost when a setting changes
				+ I.html`<div class="ibx-formrow"><span class="ibx-formrow__label"></span><div>${I.raw(!d.configured
					? '<div class="ibx-formrow__help">' + th('InboxPushNotConfigured') + '</div>'
					: (d.tested ? '<span class="ibx-status ibx-status--ok">' + th('InboxPushTested') + '</span>' : '<span class="ibx-status ibx-status--warning">' + th('InboxPushNotTested') + '</span>'))}</div></div>`
				+ '</form>';
			const form = $('.ibx-js-pxform', box);
			const sel = $('[name="driver"]', form);
			if (sel) {
				sel.addEventListener('change', () => {
					current = sel.value;
					paint();
				});
			}
			const guide = $('.ibx-js-pxguide', box);
			if (guide) {
				guide.addEventListener('click', mercureGuide);
			}
			form.addEventListener('submit', async e => {
				e.preventDefault();
				const btn = $('[type="submit"]', form);
				btn.disabled = true;
				const values = {};
				d.fields.forEach(f => {
					values[f.key] = form.elements[f.key].value;
				});
				try {
					const res = await post('server', 'push', { driver: d.code, values });
					push = res.push;
					onReady(res.push_ready);
					current = push.driver;
					paint();
					toast(t('InboxSaved'));
				} catch (err) {
					// What was typed stays
					btn.disabled = false;
					toast(err.message, { error: true });
				}
			});
			$('.ibx-js-pxtest', form).addEventListener('click', async ev => {
				const btn = ev.currentTarget;
				btn.disabled = true;
				btn.textContent = t('InboxPushTesting');
				try {
					const res = await post('server', 'pushtest', {});
					push = res.push;
					onReady(res.push_ready);
					paint();
					toast(res.test.message, { error: !res.test.ok });
				} catch (err) {
					btn.disabled = false;
					btn.innerHTML = ic('check') + ' ' + th('InboxPushTest');
					toast(err.message, { error: true });
				}
			});
		};
		box.insertAdjacentHTML('beforeend', '<div class="ibx-js-pxbody"></div>');
		paint();
		return {
			setMode(m) {
				mode = m;
				paint();
			},
		};
	}

	/**
	 * Installation guide of Mercure (1.124.0): Docker Compose, binary and systemd, nginx in front; then what to type
	 * in this page. The address of this Dolibarr already in the commands (allowed origin, trusted issuer)
	 */
	function mercureGuide() {
		const v = MERCURE_VERSION;
		const origin = location.origin;
		const keys = 'PUB=$(openssl rand -base64 48 | tr -d \'/+=\\n\' | cut -c1-48)\nSUB=$(openssl rand -base64 48 | tr -d \'/+=\\n\' | cut -c1-48)';
		guideDialog(t('InboxPushGuideTitle'), I.html`<p>${t('InboxPushGuideIntro')}</p>`
			+ guidePart('InboxPushGuideDocker', true, '<ol class="ibx-guide__steps">'
				+ guideStep('InboxPushGuideStepKeys', 'sudo mkdir -p /opt/mercure && cd /opt/mercure\n' + keys + '\nprintf \'MERCURE_PUBLISHER_JWT_KEY=%s\\nMERCURE_SUBSCRIBER_JWT_KEY=%s\\nMERCURE_TRUSTED_ISSUERS=' + origin + '\\nDOLIBARR_ORIGIN=' + origin + '\\n\' "$PUB" "$SUB" | sudo tee .env >/dev/null\nsudo chmod 600 .env')
				+ guideStep('InboxPushGuideStepCompose', '# /opt/mercure/docker-compose.yml\nservices:\n  mercure:\n    image: dunglas/mercure:' + v + '\n    restart: unless-stopped\n    environment:\n      SERVER_NAME: ":80"\n      MERCURE_PUBLISHER_JWT_KEY: ${MERCURE_PUBLISHER_JWT_KEY:?}\n      MERCURE_SUBSCRIBER_JWT_KEY: ${MERCURE_SUBSCRIBER_JWT_KEY:?}\n      MERCURE_TRUSTED_ISSUERS: ${MERCURE_TRUSTED_ISSUERS:?}\n      MERCURE_EXTRA_DIRECTIVES: |\n        cors_origins ${DOLIBARR_ORIGIN:?}\n        transport local\n    ports:\n      - "127.0.0.1:3001:80"   # this server only, nginx in front')
				+ guideStep('InboxPushGuideStepStart', 'cd /opt/mercure\nsudo docker compose up -d\ncurl -s -o /dev/null -w \'%{http_code}\\n\' \'http://127.0.0.1:3001/.well-known/mercure?match=x\'   # 401: the hub answers\nsudo cat .env   # the keys, for this page')
				+ '</ol>' + I.html`<p class="ibx-guide__note">${t('InboxPushGuideVersion', v)}</p>`)
			+ guidePart('InboxPushGuideSystemd', false, I.html`<p>${t('InboxPushGuideSystemdText')}</p>` + '<ol class="ibx-guide__steps">'
				+ guideStep('InboxPushGuideStepUser', '# Binary of a release (Linux x86_64 or other): github.com/dunglas/mercure/releases\nsudo useradd --system --home-dir /var/lib/mercure --create-home mercure\nsudo install -m 755 mercure /usr/local/bin/mercure\nsudo mkdir -p /etc/mercure && sudo cp Caddyfile /etc/mercure/')
				+ guideStep('InboxPushGuideStepEnv', keys + '\nprintf \'MERCURE_PUBLISHER_JWT_KEY=%s\\nMERCURE_SUBSCRIBER_JWT_KEY=%s\\nMERCURE_TRUSTED_ISSUERS=' + origin + '\\nSERVER_NAME=127.0.0.1:3001\\nMERCURE_EXTRA_DIRECTIVES="cors_origins ' + origin + '\\ntransport local"\\n\' "$PUB" "$SUB" | sudo tee /etc/mercure/mercure.env >/dev/null\nsudo chmod 600 /etc/mercure/mercure.env')
				+ guideStep('InboxPushGuideStepService', '# /etc/systemd/system/mercure.service\n[Unit]\nDescription=Mercure hub\nAfter=network-online.target\n\n[Service]\nUser=mercure\nEnvironmentFile=/etc/mercure/mercure.env\nEnvironment=XDG_DATA_HOME=/var/lib/mercure XDG_CONFIG_HOME=/var/lib/mercure\nExecStart=/usr/local/bin/mercure run --config /etc/mercure/Caddyfile\nRestart=on-failure\nNoNewPrivileges=true\nProtectSystem=strict\nReadWritePaths=/var/lib/mercure\n\n[Install]\nWantedBy=multi-user.target')
				+ guideStep('InboxPushGuideStepEnable', 'sudo systemctl daemon-reload\nsudo systemctl enable --now mercure\nsudo cat /etc/mercure/mercure.env   # the keys, for this page')
				+ '</ol>')
			+ guidePart('InboxPushGuideNginx', false, I.html`<p>${t('InboxPushGuideNginxText')}</p>`
				+ guideCode('server {\n    listen 443 ssl;\n    http2 on;\n    server_name push.example.com;\n    ssl_certificate     /etc/letsencrypt/live/push.example.com/fullchain.pem;\n    ssl_certificate_key /etc/letsencrypt/live/push.example.com/privkey.pem;\n\n    location /.well-known/mercure {\n        proxy_pass http://127.0.0.1:3001;\n        proxy_http_version 1.1;\n        proxy_set_header Connection "";\n        proxy_set_header Host $host;\n        proxy_set_header X-Forwarded-Proto https;\n        proxy_buffering off;      # a stream stays open\n        proxy_cache off;\n        proxy_read_timeout 24h;\n    }\n}'))
			+ I.html`<h3 class="ibx-guide__then">${t('InboxMeiliGuideThen')}</h3><p>${t('InboxPushGuideThenText', origin)}</p>`);
	}

	// ---------- Search: Meilisearch (lot W7 1.20.0) ----------
	// ---------- Installation guides (1.33.0): parts to open, steps, commands copied by a button ----------
	const guideCode = text => '<div class="ibx-codeblock"><pre>' + esc(text) + '</pre><button type="button" class="ibx-btn ibx-btn--sm ibx-js-copycode">' + ic('copy') + ' ' + th('InboxCopy') + '</button></div>';
	const guideStep = (key, text) => '<li><p>' + th(key) + '</p>' + guideCode(text) + '</li>';
	const guidePart = (key, open, inner) => '<details class="ibx-guide__part"' + (open ? ' open' : '') + '><summary>' + th(key) + '</summary><div class="ibx-guide__body">' + inner + '</div></details>';

	/**
	 * The guide in a dialog: a warning first (examples to adapt), the copy said in the button (a toast would be under
	 * the dialog)
	 * @param {string} title Title
	 * @param {string} inner HTML after the warning
	 */
	function guideDialog(title, inner) {
		const dlg = dialog({ title, wide: true, body: '<div class="ibx-guide"><div class="ibx-notice ibx-notice--warning">' + ic('warning') + '<div class="ibx-notice__text">' + th('InboxMeiliGuideWarn') + '</div></div>' + inner + '</div>' });
		copyCodeOn(dlg);
	}

	/**
	 * Buttons « Copy » of the code blocks of a zone (guideCode): the copy said in the button (a toast would be under a
	 * dialog); outside https, the text selected for Ctrl+C
	 * @param {HTMLElement} zone Zone listened
	 */
	function copyCodeOn(zone) {
		const said = (b, key) => {
			b.innerHTML = ic('check') + ' ' + th(key);
			clearTimeout(b.again);
			b.again = setTimeout(() => {
				b.innerHTML = ic('copy') + ' ' + th('InboxCopy');
			}, 2000);
		};
		zone.addEventListener('click', async ev => {
			const b = ev.target.closest('.ibx-js-copycode');
			if (!b) {
				return;
			}
			const pre = $('pre', b.parentElement);
			try {
				await navigator.clipboard.writeText(pre.textContent);
			} catch (err) {
				// No clipboard API outside https: the text selected, for Ctrl+C
				const range = document.createRange();
				range.selectNodeContents(pre);
				const sel = window.getSelection();
				sel.removeAllRanges();
				sel.addRange(range);
				said(b, 'InboxCodeSelected');
				return;
			}
			said(b, 'InboxCodeCopied');
		});
	}

	/** Version of Meilisearch tested with inbox, in the examples of the installation guide */
	const MEILI_VERSION = 'v1.54.3';

	/**
	 * Installation guide of Meilisearch (1.32.0): examples to adapt (Docker Compose, a quick try, binary and systemd,
	 * another machine), then what to fill in this page; a warning first, each command copied by a button
	 */
	function meiliGuide() {
		const v = MEILI_VERSION;
		const code = guideCode;
		const step = guideStep;
		const part = guidePart;
		const keyLine = file => 'KEY=$(openssl rand -base64 32 | tr -d \'/+=\')\necho "MEILI_MASTER_KEY=$KEY" | sudo tee ' + file + ' >/dev/null\nsudo chmod 600 ' + file;
		guideDialog(t('InboxMeiliGuideTitle'), '<p>' + th('InboxMeiliGuideIntro') + '</p>'
			+ part('InboxMeiliGuideDocker', true, '<ol class="ibx-guide__steps">'
				+ step('InboxMeiliGuideStepKey', 'sudo mkdir -p /opt/meilisearch && cd /opt/meilisearch\n' + keyLine('.env'))
				+ step('InboxMeiliGuideStepCompose', '# /opt/meilisearch/docker-compose.yml\nservices:\n  meilisearch:\n    image: getmeili/meilisearch:' + v + '\n    restart: unless-stopped\n    environment:\n      MEILI_ENV: production\n      MEILI_MASTER_KEY: ${MEILI_MASTER_KEY:?set MEILI_MASTER_KEY in .env}\n      MEILI_NO_ANALYTICS: "true"\n    ports:\n      - "127.0.0.1:7700:7700"   # this server only, never Internet\n    volumes:\n      - meili_data:/meili_data\n\nvolumes:\n  meili_data:')
				+ step('InboxMeiliGuideStepStart', 'cd /opt/meilisearch\nsudo docker compose up -d\ncurl -s http://127.0.0.1:7700/health   # {"status":"available"}\nsudo cat .env                          # the master key, for this page')
				+ '</ol><p class="ibx-guide__note">' + th('InboxMeiliGuideVersion', v) + '</p>')
			+ part('InboxMeiliGuideTry', false, '<p>' + th('InboxMeiliGuideTryText') + '</p>'
				+ code('docker run -d --name meili-test -p 127.0.0.1:7700:7700 \\\n  -e MEILI_MASTER_KEY=a-test-key-of-at-least-16-bytes \\\n  -e MEILI_NO_ANALYTICS=true \\\n  getmeili/meilisearch:' + v + '\n\n# Afterwards\ndocker rm -f meili-test'))
			+ part('InboxMeiliGuideBinary', false, '<ol class="ibx-guide__steps">'
				+ step('InboxMeiliGuideStepBinary', '# Linux x86_64; other systems: github.com/meilisearch/meilisearch/releases\nsudo useradd --system --home-dir /var/lib/meilisearch --create-home meilisearch\nsudo curl -fL -o /usr/local/bin/meilisearch \\\n  https://github.com/meilisearch/meilisearch/releases/download/' + v + '/meilisearch-linux-amd64\nsudo chmod 755 /usr/local/bin/meilisearch')
				+ step('InboxMeiliGuideStepService', '# /etc/systemd/system/meilisearch.service\n[Unit]\nDescription=Meilisearch\nAfter=network.target\n\n[Service]\nUser=meilisearch\nWorkingDirectory=/var/lib/meilisearch\nEnvironmentFile=/etc/meilisearch.env\nExecStart=/usr/local/bin/meilisearch --env production --no-analytics \\\n  --http-addr 127.0.0.1:7700 --db-path /var/lib/meilisearch/data\nRestart=on-failure\n\n[Install]\nWantedBy=multi-user.target')
				+ step('InboxMeiliGuideStepKeyStart', keyLine('/etc/meilisearch.env') + '\nsudo systemctl daemon-reload\nsudo systemctl enable --now meilisearch\ncurl -s http://127.0.0.1:7700/health   # {"status":"available"}\nsudo cat /etc/meilisearch.env          # the master key, for this page')
				+ '</ol>')
			+ part('InboxMeiliGuideRemote', false, '<p>' + th('InboxMeiliGuideRemoteText') + '</p>')
			+ '<h3 class="ibx-guide__then">' + th('InboxMeiliGuideThen') + '</h3><p>' + th('InboxMeiliGuideThenText') + '</p>');
	}

	/**
	 * Installation guide of a transcription server (1.35.0): speaches (Whisper, OpenAI compatible) with Docker, on the
	 * processor or a NVIDIA card, a service in the cloud, another machine; then what to fill in this page
	 */
	function whisperGuide() {
		const keyLine = 'KEY=$(openssl rand -hex 24)\necho "API_KEY=$KEY" | sudo tee .env >/dev/null\nsudo chmod 600 .env';
		const compose = image => '# /opt/whisper/docker-compose.yml\nservices:\n  speaches:\n    image: ghcr.io/speaches-ai/speaches:' + image + '\n    restart: unless-stopped\n    environment:\n      API_KEY: ${API_KEY:?set API_KEY in .env}\n    ports:\n      - "127.0.0.1:8000:8000"   # this server only, never Internet\n    volumes:\n      - hf_cache:/home/ubuntu/.cache/huggingface/hub'
			+ (image.includes('cuda') ? '\n    deploy:\n      resources:\n        reservations:\n          devices:\n            - driver: nvidia\n              count: all\n              capabilities: [gpu]' : '') + '\n\nvolumes:\n  hf_cache:';
		const startLines = model => 'cd /opt/whisper\nsudo docker compose up -d\nKEY=$(sudo sed -n \'s/^API_KEY=//p\' .env)\n# The model, downloaded once\ncurl -s -X POST -H "Authorization: Bearer $KEY" \\\n  http://127.0.0.1:8000/v1/models/' + model + '\n# The model listed\ncurl -s -H "Authorization: Bearer $KEY" http://127.0.0.1:8000/v1/models\nsudo cat .env   # the key, for this page';
		guideDialog(t('InboxWhisperGuideTitle'), '<p>' + th('InboxWhisperGuideIntro') + '</p>'
			+ guidePart('InboxWhisperGuideCpu', true, '<ol class="ibx-guide__steps">'
				+ guideStep('InboxWhisperGuideStepKey', 'sudo mkdir -p /opt/whisper && cd /opt/whisper\n' + keyLine)
				+ guideStep('InboxWhisperGuideStepCompose', compose('latest-cpu'))
				+ guideStep('InboxWhisperGuideStepStart', startLines('Systran/faster-whisper-small'))
				+ '</ol><p class="ibx-guide__note">' + th('InboxWhisperGuideCpuNote') + '</p>')
			+ guidePart('InboxWhisperGuideGpu', false, '<p>' + th('InboxWhisperGuideGpuText') + '</p>' + guideCode(compose('latest-cuda')) + guideCode(startLines('deepdml/faster-whisper-large-v3-turbo-ct2')))
			+ guidePart('InboxWhisperGuideCloud', false, '<p>' + th('InboxWhisperGuideCloudText') + '</p>' + guideCode('# OpenAI\nhttps://api.openai.com/v1            model: whisper-1\n# Groq\nhttps://api.groq.com/openai/v1       model: whisper-large-v3-turbo'))
			+ guidePart('InboxWhisperGuideRemote', false, '<p>' + th('InboxWhisperGuideRemoteText') + '</p>')
			+ '<h3 class="ibx-guide__then">' + th('InboxMeiliGuideThen') + '</h3><p>' + th('InboxWhisperGuideThenText') + '</p>');
	}

	/**
	 * Section Voice typing of Settings > General (1.35.0): the engine (the browser, or a transcription server); for the
	 * server its address, key (never sent back), model; a test lists the models; saved only once the server answers
	 * @param {Element} box Holder
	 */
	async function renderWhisperSettings(box) {
		let s;
		try {
			s = await api('dictation', 'server_get');
		} catch (err) {
			box.replaceChildren(errorBox(err, () => renderWhisperSettings(box)));
			return;
		}
		const server = s.engine === 'server';
		box.innerHTML = '<div class="ibx-form">'
			+ '<div class="ibx-formrow"><span class="ibx-formrow__label" id="w-engine-label">' + th('InboxWhisperEngine') + '</span><div class="ibx-stack" role="radiogroup" aria-labelledby="w-engine-label">'
			+ ['browser', 'server'].map(v => '<label class="ibx-radio ibx-radio--block"><input type="radio" name="w-engine" value="' + v + '"' + ((server ? 'server' : 'browser') === v ? ' checked' : '') + '> <span><b>' + th('InboxWhisperEngine_' + v) + '</b><span class="ibx-formrow__help">' + th('InboxWhisperEngine_' + v + 'Help') + '</span></span></label>').join('')
			+ '</div></div>'
			+ '<div class="ibx-js-wserver"' + (server ? '' : ' hidden') + '><div class="ibx-form">'
			+ '<div class="ibx-formrow"><label class="ibx-formrow__label" for="w-url">' + th('InboxWhisperUrl') + '</label><div><input class="ibx-field ibx-field--medium" type="url" id="w-url" autocomplete="off" spellcheck="false" placeholder="http://127.0.0.1:8000/v1" value="' + esc(s.url) + '">'
			+ '<div class="ibx-formrow__help">' + th('InboxWhisperUrlHelp') + ' <button type="button" class="ibx-linkbtn ibx-js-wguide">' + th('InboxWhisperGuideLink') + '</button></div>'
			+ '<div class="ibx-notice ibx-notice--warning ibx-js-wremote"' + (s.local ? ' hidden' : '') + '>' + ic('warning') + '<div class="ibx-notice__text">' + th('InboxWhisperRemote') + '</div></div></div></div>'
			+ '<div class="ibx-formrow"><label class="ibx-formrow__label" for="w-key">' + th('InboxWhisperKey') + '</label><div><input class="ibx-field ibx-field--medium" type="password" id="w-key" autocomplete="new-password" spellcheck="false" placeholder="' + (s.has_key ? th('InboxMeiliKeyKept') : '') + '">'
			+ '<div class="ibx-formrow__help">' + th('InboxWhisperKeyHelp') + '</div></div></div>'
			+ '<div class="ibx-formrow"><label class="ibx-formrow__label" for="w-model">' + th('InboxWhisperModel') + '</label><div><input class="ibx-field ibx-field--medium" type="text" id="w-model" list="w-models" autocomplete="off" spellcheck="false" placeholder="whisper-1" value="' + esc(s.model) + '"><datalist id="w-models"></datalist>'
			+ '<div class="ibx-formrow__help">' + th('InboxWhisperModelHelp') + '</div></div></div>'
			+ '<div class="ibx-js-wresult" role="status"></div>'
			+ '</div><div class="ibx-formfoot" style="border-top:0"><button type="button" class="ibx-btn ibx-js-wtest">' + th('InboxMeiliTest') + '</button><span class="ibx-formfoot__spacer"></span><button type="button" class="ibx-btn ibx-btn--primary ibx-js-wsave">' + th('InboxSave') + '</button></div></div>'
			+ '</div>';
		const result = $('.ibx-js-wresult', box);
		const values = () => ({ url: $('#w-url', box).value.trim(), key: $('#w-key', box).value, model: $('#w-model', box).value.trim() });
		const showResult = (ok, text) => {
			result.innerHTML = '<div class="ibx-notice ibx-notice--' + (ok ? 'success' : 'error') + '">' + ic(ok ? 'check' : 'warning') + '<div class="ibx-notice__text">' + esc(text) + '</div></div>';
		};
		const busy = async (b, fn) => {
			if (b.getAttribute('aria-disabled') === 'true') {
				return;
			}
			b.setAttribute('aria-disabled', 'true');
			try {
				await fn();
			} finally {
				b.removeAttribute('aria-disabled');
			}
		};
		$('.ibx-js-wguide', box).addEventListener('click', whisperGuide);
		// Back to the browser: at once; the server: its fields, saved by the button
		$$('[name="w-engine"]', box).forEach(r => r.addEventListener('change', async () => {
			const toServer = r.value === 'server' && r.checked;
			$('.ibx-js-wserver', box).hidden = !toServer;
			if (!toServer && r.checked && server) {
				try {
					await post('dictation', 'server_save', { engine: 'browser' });
					toast(t('InboxWhisperBrowserSaved'));
					renderWhisperSettings(box);
				} catch (err) {
					toast(err.message, { error: true });
				}
			}
		}));
		$('.ibx-js-wtest', box).addEventListener('click', ev => busy(ev.currentTarget, async () => {
			try {
				const r = await post('dictation', 'server_test', values());
				$('.ibx-js-wremote', box).hidden = !!r.local;
				if (!r.ok) {
					showResult(false, r.error);
					return;
				}
				$('#w-models', box).innerHTML = (r.models || []).map(m => '<option value="' + esc(m) + '">').join('');
				showResult(true, r.models && r.models.length ? t('InboxWhisperTestOkModels', r.models.length) : t('InboxWhisperTestOk'));
			} catch (err) {
				showResult(false, err.message);
			}
		}));
		$('.ibx-js-wsave', box).addEventListener('click', ev => busy(ev.currentTarget, async () => {
			try {
				await post('dictation', 'server_save', Object.assign({ engine: 'server' }, values()));
				toast(t('InboxWhisperSaved'));
				renderWhisperSettings(box);
			} catch (err) {
				showResult(false, err.message);
			}
		}));
	}

	/**
	 * Section of Settings > General: off by default; address, master key (never sent back), prefix; test; on (the
	 * key of inbox is created, limited to the indexes of the prefix) or off (every index of the prefix removed)
	 */
	async function renderSearchSettings(sec) {
		let s;
		try {
			s = await api('fulltext', 'get');
		} catch (err) {
			sec.replaceChildren(errorBox(err, () => renderSearchSettings(sec)));
			return;
		}
		const on = !!s.enabled;
		sec.innerHTML = sectionTitle(GENERAL_SECTIONS, 'search') + '<p class="ibx-section__help">' + th('InboxMeiliHelp')
			+ ' <button type="button" class="ibx-linkbtn ibx-js-ftguide">' + th('InboxMeiliGuideLink') + '</button></p>'
			+ '<div class="ibx-form">'
			+ '<div class="ibx-formrow"><span class="ibx-formrow__label">' + th('InboxStatus') + '</span><div>' + (on ? '<span class="ibx-status ibx-status--ok">' + th('InboxMeiliOn') + '</span>' : '<span class="ibx-status">' + th('InboxMeiliOff') + '</span>')
			+ '<div class="ibx-formrow__help">' + th(on ? 'InboxMeiliOnHelp' : 'InboxMeiliOffHelp') + '</div></div></div>'
			+ '<div class="ibx-formrow"><label class="ibx-formrow__label" for="ft-url">' + th('InboxMeiliUrl') + '</label><div><input class="ibx-field ibx-field--medium" type="url" id="ft-url" autocomplete="off" spellcheck="false" placeholder="http://127.0.0.1:7700" value="' + esc(s.url) + '"' + (on ? ' readonly' : '') + '>'
			+ '<div class="ibx-formrow__help">' + th('InboxMeiliUrlHelp') + '</div><div class="ibx-notice ibx-notice--warning ibx-js-ftremote"' + (s.local ? ' hidden' : '') + '>' + ic('warning') + '<div class="ibx-notice__text">' + th('InboxMeiliRemote') + '</div></div></div></div>'
			+ '<div class="ibx-formrow"><label class="ibx-formrow__label" for="ft-key">' + th('InboxMeiliKey') + '</label><div><input class="ibx-field ibx-field--medium" type="password" id="ft-key" autocomplete="new-password" spellcheck="false" placeholder="' + (s.has_key ? th('InboxMeiliKeyKept') : '') + '"' + (on ? ' readonly' : '') + '>'
			+ '<div class="ibx-formrow__help">' + th('InboxMeiliKeyHelp') + '</div></div></div>'
			+ '<div class="ibx-formrow"><label class="ibx-formrow__label" for="ft-prefix">' + th('InboxMeiliPrefix') + '</label><div><input class="ibx-field ibx-field--narrow" type="text" id="ft-prefix" autocomplete="off" spellcheck="false" pattern="[a-z][a-z0-9]{1,29}" value="' + esc(s.prefix) + '"' + (on ? ' readonly' : '') + '>'
			+ '<div class="ibx-formrow__help">' + th('InboxMeiliPrefixHelp') + '</div></div></div>'
			+ '<div class="ibx-js-ftresult" role="status"></div>'
			+ '</div><div class="ibx-formfoot" style="border-top:0">'
			+ (on ? '<span class="ibx-formfoot__spacer"></span><button type="button" class="ibx-btn ibx-btn--danger ibx-js-ftoff">' + th('InboxMeiliDisable') + '</button>'
				: '<button type="button" class="ibx-btn ibx-js-fttest">' + th('InboxMeiliTest') + '</button><span class="ibx-formfoot__spacer"></span><button type="button" class="ibx-btn ibx-btn--primary ibx-js-fton">' + th('InboxMeiliEnable') + '</button>')
			+ '</div>';
		$('.ibx-js-ftguide', sec).addEventListener('click', meiliGuide);
		const result = $('.ibx-js-ftresult', sec);
		const values = () => ({ url: $('#ft-url', sec).value.trim(), key: $('#ft-key', sec).value, prefix: $('#ft-prefix', sec).value.trim() });
		// The key kept is only used for the address it was kept with (audit INBX-003): another address asks it again
		if (s.has_key && !on) {
			$('#ft-url', sec).addEventListener('input', () => {
				const again = $('#ft-url', sec).value.trim().replace(/\/+$/, '').toLowerCase() !== String(s.url || '').replace(/\/+$/, '').toLowerCase();
				$('#ft-key', sec).placeholder = again ? t('InboxPasswordAgain') : t('InboxMeiliKeyKept');
			});
		}
		const showResult = (ok, text) => {
			result.innerHTML = '<div class="ibx-notice ibx-notice--' + (ok ? 'success' : 'error') + '">' + ic(ok ? 'check' : 'warning') + '<div class="ibx-notice__text">' + esc(text) + '</div></div>';
		};
		const test = $('.ibx-js-fttest', sec);
		if (test) {
			test.addEventListener('click', async () => {
				test.setAttribute('aria-disabled', 'true');
				try {
					const r = await post('fulltext', 'test', values());
					$('.ibx-js-ftremote', sec).hidden = r.local !== false;
					showResult(r.ok, r.ok ? t('InboxMeiliTestOk', r.version) : r.error);
				} catch (err) {
					showResult(false, err.message);
				}
				test.removeAttribute('aria-disabled');
			});
		}
		const enable = $('.ibx-js-fton', sec);
		if (enable) {
			enable.addEventListener('click', async () => {
				enable.setAttribute('aria-disabled', 'true');
				try {
					await post('fulltext', 'enable', values());
					toast(t('InboxMeiliEnabled'));
					renderSearchSettings(sec);
				} catch (err) {
					showResult(false, err.message);
					enable.removeAttribute('aria-disabled');
				}
			});
		}
		const off = $('.ibx-js-ftoff', sec);
		if (off) {
			off.addEventListener('click', async () => {
				const ok = await confirmDialog({ title: t('InboxMeiliDisableTitle'), text: t('InboxMeiliDisableText', s.prefix), confirm: t('InboxMeiliDisable'), danger: true });
				if (!ok) {
					return;
				}
				try {
					await post('fulltext', 'disable', { confirm: 1 });
					toast(t('InboxMeiliDisabled'));
				} catch (err) {
					toast(err.message, { error: true });
				}
				renderSearchSettings(sec);
			});
		}
	}

	/** Sync tab of a mailbox, admin only, when Meilisearch is on: index this mailbox, progress, error */
	async function tabSyncFulltext(holder, account) {
		let st;
		try {
			st = await api('fulltext', 'account', { id: account.id });
		} catch (err) {
			holder.innerHTML = '';
			return;
		}
		if (!st.enabled) {
			holder.innerHTML = '';
			return;
		}
		const pct = st.total ? Math.min(100, Math.round(100 * st.sent / st.total)) : 0;
		holder.innerHTML = '<section class="ibx-section"><h2 class="ibx-section__title">' + th('InboxSectionSearch') + '</h2><div class="ibx-form">'
			+ '<div class="ibx-formrow"><span class="ibx-formrow__label">' + th('InboxMeiliIndexBox') + '</span><div>'
			+ '<button type="button" class="ibx-switch ibx-js-ftbox" role="switch" aria-checked="' + (st.on ? 'true' : 'false') + '" aria-label="' + th('InboxMeiliIndexBox') + '"></button>'
			+ '<div class="ibx-formrow__help">' + th('InboxMeiliIndexBoxHelp') + '</div></div></div>'
			+ (st.on ? '<div class="ibx-formrow"><span class="ibx-formrow__label">' + th('InboxMeiliProgress') + '</span><div>'
				+ '<progress max="100" value="' + pct + '" style="width:16rem;vertical-align:middle" aria-label="' + th('InboxMeiliProgress') + '"></progress> '
				+ th('InboxSyncIndexedOf', st.sent.toLocaleString(I.locale()), st.total.toLocaleString(I.locale()))
				+ ' <span class="ibx-status' + (st.state === 'ready' ? ' ibx-status--ok' : ' ibx-status--warning') + '">' + th('InboxMeiliState_' + (st.state || 'indexing')) + '</span>'
				+ (st.error ? '<div class="ibx-formrow__error">' + esc(st.error) + '</div>' : '') + '</div></div>' : '')
			+ '</div></section>';
		const sw = $('.ibx-js-ftbox', holder);
		sw.addEventListener('click', async () => {
			const on = sw.getAttribute('aria-checked') !== 'true';
			if (!on && !(await confirmDialog({ title: t('InboxMeiliIndexOffTitle'), text: t('InboxMeiliIndexOffText'), confirm: t('InboxMeiliIndexOff'), danger: true }))) {
				return;
			}
			sw.setAttribute('aria-checked', on ? 'true' : 'false');
			try {
				await post('fulltext', 'account_set', { id: account.id, on: on ? 1 : 0 });
				toast(t(on ? 'InboxMeiliIndexOnDone' : 'InboxMeiliIndexOffDone'));
			} catch (err) {
				toast(err.message, { error: true });
			}
			tabSyncFulltext(holder, account);
		});
	}

	/**
	 * A section pointed at (from a menu, a link, the route): brought into view, its background lit then fading, so
	 * the eye finds it; the focus on its first field (1.29.0)
	 */
	function pointSection(main, name) {
		const target = $('[data-section="' + String(name).replace(/[^a-z]/g, '') + '"]', main);
		if (!target) {
			return;
		}
		target.scrollIntoView({ block: 'start', behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
		target.classList.remove('is-flash');
		void target.offsetWidth;
		target.classList.add('is-flash');
		// Removed after the fading (also without animation: the outline of the reduced motion goes too)
		setTimeout(() => target.classList.remove('is-flash'), 2600);
		const first = $('input, select, button', target);
		if (first) {
			first.focus({ preventScroll: true });
		}
	}

	/**
	 * The section at the top of the page lit in its links (menu and chips), as the page scrolls; its title kept at
	 * the top marked (1.32.0)
	 */
	function spySections(main, ids) {
		const nav = main.closest('.ibx-settings');
		let last = '';
		const update = () => {
			const top = main.getBoundingClientRect().top + 96;
			let on = ids[0];
			// At the bottom of the page: the last sections, too short to reach the top, are lit when reached
			const atEnd = main.scrollTop + main.clientHeight >= main.scrollHeight - 4;
			ids.forEach(id => {
				const sec = $('[data-section="' + id + '"]', main);
				if (sec && !sec.hidden && sec.offsetHeight && (sec.getBoundingClientRect().top <= top || atEnd)) {
					on = id;
				}
			});
			// A title kept at the top of the page (large screens) marked so: its line under it
			const edge = main.getBoundingClientRect().top + 1;
			ids.forEach(id => {
				const sec = $('[data-section="' + id + '"]', main);
				const h = sec && $(':scope > .ibx-section__title', sec);
				if (h) {
					h.classList.toggle('is-stuck', sec.getBoundingClientRect().top < edge - 1 && h.getBoundingClientRect().top <= edge);
				}
			});
			if (on === last) {
				return;
			}
			last = on;
			$$('a[data-goto]', nav).forEach(a => {
				a.classList.toggle('is-on', a.dataset.goto === on);
				if (a.dataset.goto === on && a.classList.contains('ibx-subchip')) {
					// The chip brought into its row by hand: a scrollIntoView would stop the scrolling of the page
					const row = a.parentElement;
					if (a.offsetLeft < row.scrollLeft || a.offsetLeft + a.offsetWidth > row.scrollLeft + row.clientWidth) {
						row.scrollLeft = a.offsetLeft - 16;
					}
				}
			});
		};
		let queued = false;
		main.onscroll = () => {
			if (!queued) {
				queued = true;
				requestAnimationFrame(() => {
					queued = false;
					update();
				});
			}
		};
		update();
	}

	// ---------- My account ----------
	async function renderMe(main, parts) {
		main.innerHTML = skeleton();
		let data;
		try {
			data = await api('userparam', 'get');
		} catch (err) {
			main.replaceChildren(errorBox(err, () => renderMe(main, parts)));
			return;
		}
		const p = data.params;
		const opt = (name, values, current) => '<select class="ibx-field ibx-field--medium" data-param="' + name + '" id="me-' + name + '">' + values.map(v => '<option value="' + v + '"' + (v === current ? ' selected' : '') + '>' + th('Inbox_' + name + '_' + v) + '</option>').join('') + '</select>';
		const title = id => sectionTitle(ME_SECTIONS, id);
		let html = '<div class="ibx-page"><div class="ibx-page__head"><h1 class="ibx-page__title">' + th('InboxNavMe') + '</h1></div>'
			+ '<nav class="ibx-subchips" aria-label="' + th('InboxNavMe') + '">' + sectionLinks('me', ME_SECTIONS, 'ibx-subchip') + '</nav>'
			+ '<section class="ibx-section" data-section="reading">' + title('reading') + '<div class="ibx-form">'
			+ '<div class="ibx-formrow"><label class="ibx-formrow__label" for="me-read_mode">' + th('InboxReadMode') + '</label><div>' + opt('read_mode', ['immediate', 'delay', 'manual'], p.read_mode) + '<div class="ibx-formrow__help">' + th('InboxReadModeHelp') + '</div></div></div>'
			+ '<div class="ibx-formrow"><label class="ibx-formrow__label" for="me-read_delay">' + th('InboxReadDelay') + '</label><div class="ibx-inline"><input class="ibx-field ibx-field--narrow" type="number" min="0" max="600" inputmode="numeric" data-param="read_delay" id="me-read_delay" value="' + esc(p.read_delay) + '"> ' + th('InboxSeconds') + '</div></div>'
			// Trash and junk marked read (1.90.0): also with « at once », a mail can leave the list without being opened
			+ '<div class="ibx-formrow"><span class="ibx-formrow__label">' + th('InboxReadOnTrash') + '</span><div>'
			+ '<button type="button" class="ibx-switch ibx-js-readswitch" data-readparam="read_on_trash" role="switch" aria-checked="' + (p.read_on_trash === '0' ? 'false' : 'true') + '" aria-label="' + th('InboxReadOnTrash') + '"></button>'
			+ '<div class="ibx-formrow__help">' + th('InboxReadOnTrashHelp') + '</div></div></div>'
			// Archived marked read (1.96.0): a setting of its own
			+ '<div class="ibx-formrow"><span class="ibx-formrow__label">' + th('InboxReadOnArchive') + '</span><div>'
			+ '<button type="button" class="ibx-switch ibx-js-readswitch" data-readparam="read_on_archive" role="switch" aria-checked="' + (p.read_on_archive === '0' ? 'false' : 'true') + '" aria-label="' + th('InboxReadOnArchive') + '"></button>'
			+ '<div class="ibx-formrow__help">' + th('InboxReadOnArchiveHelp') + '</div></div></div>'
			// Read receipts asked (lot W8 1.22.0): never sent without a click
			+ '<div class="ibx-formrow"><label class="ibx-formrow__label" for="me-mdn_mode">' + th('InboxMdnMode') + '</label><div>' + opt('mdn_mode', ['ask', 'never'], p.mdn_mode) + '<div class="ibx-formrow__help">' + th('InboxMdnModeHelp') + '</div></div></div>'
			+ '</div></section>';
		// Rules and out of office of the personal mailboxes (lot W8 1.23.0): kept on the card of the mailbox
		const mine = data.mailboxes.filter(m => m.personal);
		if (mine.length) {
			html += '<section class="ibx-section" data-section="mybox">' + title('mybox') + '<p class="ibx-section__help">' + th('InboxMyBoxHelp') + '</p><div class="ibx-form">'
				+ mine.map(m => '<div class="ibx-formrow"><span class="ibx-formrow__label">' + esc(m.label || m.email) + '</span><div class="ibx-inline" style="gap:var(--ibx-s2)">'
					+ '<a class="ibx-btn ibx-btn--sm" href="#/settings/accounts/' + m.id + '/rules">' + ic('folder-bolt') + ' ' + th('InboxTabRules') + '</a>'
					+ '<a class="ibx-btn ibx-btn--sm" href="#/settings/accounts/' + m.id + '/away">' + ic('agenda') + ' ' + th('InboxTabAway') + '</a></div></div>').join('')
				+ '</div></section>';
		}
		// Order of my mailboxes (1.36.0): the panel of the folders, the choice of a mailbox, the writing follow it
		if (data.mailboxes.length > 1) {
			html += '<section class="ibx-section" data-section="order">' + title('order') + '<p class="ibx-section__help">' + th('InboxBoxOrderHelp') + '</p>'
				+ '<ol class="ibx-boxorder ibx-js-boxorder">' + data.mailboxes.map(m => boxOrderItem(m)).join('') + '</ol>'
				+ '<button type="button" class="ibx-btn ibx-btn--sm ibx-js-boxorderreset"' + (JSON.parse(p.accounts_order || '[]').length ? '' : ' hidden') + '>' + th('InboxBoxOrderReset') + '</button></section>';
		}
		if (data.mailboxes.length) {
			html += '<section class="ibx-section" data-section="permailbox">' + title('permailbox') + '<p class="ibx-section__help">' + th('InboxReadPerMailboxHelp') + '</p><table class="ibx-table"><tbody>'
				+ data.mailboxes.map(m => '<tr><td><b>' + esc(m.label || m.email) + '</b><div class="ibx-muted">' + esc(m.email) + '</div></td><td><select class="ibx-field ibx-field--medium" data-param="read_mode" data-account="' + m.id + '" aria-label="' + th('InboxReadMode') + '">'
					+ '<option value="">' + th('InboxSameAsAbove') + '</option>' + ['immediate', 'delay', 'manual'].map(v => '<option value="' + v + '"' + (m.params.read_mode === v && m.params.read_mode !== p.read_mode ? ' selected' : '') + '>' + th('Inbox_read_mode_' + v) + '</option>').join('') + '</select></td></tr>').join('')
				+ '</tbody></table></section>';
		}
		// Voice typing (1.28.0): the receivers of the voice agreed to, each one withdrawn here
		// Unified views (1.29.0): the mailboxes they gather, each one can be left out
		if (data.mailboxes.length > 1) {
			let out = [];
			try {
				out = JSON.parse(p.unified_exclude || '[]').map(Number);
			} catch (e) {
				out = [];
			}
			html += '<section class="ibx-section" data-section="unified">' + title('unified') + '<p class="ibx-section__help">' + th('InboxUnifiedHelp') + '</p>'
				+ '<div class="ibx-form ibx-js-unified">' + data.mailboxes.map(m => '<label class="ibx-check"><input type="checkbox" value="' + m.id + '"' + (out.includes(m.id) ? '' : ' checked') + '> '
					+ esc(m.label && m.label !== m.email ? m.label + ' <' + m.email + '>' : m.email) + '</label>').join('') + '</div></section>';
		}
		html += '<section class="ibx-section ibx-js-mydictation" data-section="dictation" hidden></section>';
		html += '<section class="ibx-section ibx-js-mydevices" data-section="devices" hidden></section>';
		html += '<section class="ibx-section" data-section="display">' + title('display') + '<div class="ibx-form">'
			+ '<div class="ibx-formrow"><label class="ibx-formrow__label" for="me-view">' + th('InboxView') + '</label><div>' + opt('view', ['cards', 'table'], p.view) + '<div class="ibx-formrow__help">' + th('InboxViewHelp') + '</div></div></div>'
			+ '<div class="ibx-formrow"><label class="ibx-formrow__label" for="me-conversation">' + th('InboxConversationsSetting') + '</label><div>' + opt('conversation', ['1', '0'], p.conversation) + '<div class="ibx-formrow__help">' + th('InboxConversationsHelp') + '</div></div></div>'
			+ '<div class="ibx-formrow"><label class="ibx-formrow__label" for="me-context_menus">' + th('InboxContextMenus') + '</label><div>' + opt('context_menus', ['1', '0'], p.context_menus) + '<div class="ibx-formrow__help">' + th('InboxContextMenusHelp') + '</div></div></div>'
			+ '<div class="ibx-formrow"><label class="ibx-formrow__label" for="me-theme">' + th('InboxTheme') + '</label><div>' + opt('theme', ['auto', 'light', 'dark', 'contrast'], p.theme) + '</div></div>'
			+ '<div class="ibx-formrow"><label class="ibx-formrow__label" for="me-density">' + th('InboxDensity') + '</label><div>' + opt('density', ['compact', 'normal', 'comfortable'], p.density) + '</div></div>'
			+ '<div class="ibx-formrow"><label class="ibx-formrow__label" for="me-text_size">' + th('InboxTextSize') + '</label><div class="ibx-inline"><input type="range" min="90" max="150" step="10" data-param="text_size" id="me-text_size" value="' + esc(p.text_size) + '" aria-valuetext="' + esc(p.text_size) + ' %"><output class="ibx-js-ts">' + esc(p.text_size) + ' %</output></div></div>'
			+ tagColorsRow(data, opt)
			+ '<div class="ibx-formrow"><label class="ibx-formrow__label" for="me-folder_icons">' + th('InboxFolderIcons') + '</label><div>' + opt('folder_icons', ['color', 'plain'], p.folder_icons) + '<div class="ibx-formrow__help">' + th('InboxFolderIconsHelp') + '</div></div></div>'
			// One mailbox at a time (1.30.0)
			+ '<div class="ibx-formrow"><label class="ibx-formrow__label" for="me-tree_mode">' + th('InboxTreeMode') + '</label><div>' + opt('tree_mode', ['auto', 'all', 'single'], p.tree_mode) + '<div class="ibx-formrow__help">' + th('InboxTreeModeHelp') + '</div></div></div>'
			+ '<div class="ibx-formrow"><label class="ibx-formrow__label" for="me-send_delay">' + th('InboxSendDelayMine') + '</label><div>' + opt('send_delay', ['-1', '0', '5', '10', '20', '30', '60'], String(p.send_delay)) + '<div class="ibx-formrow__help">' + th('InboxSendDelayMineHelp') + '</div></div></div>'
			+ '<div class="ibx-formrow"><label class="ibx-formrow__label" for="me-send_shortcut">' + th('InboxSendShortcut') + '</label><div>' + opt('send_shortcut', ['ctrl_enter', 'off'], p.send_shortcut) + '<div class="ibx-formrow__help">' + th('InboxSendShortcutHelp') + '</div></div></div>'
			// mailto: links of the browser opened in inbox (lot W6 1.18.0): asked by the browser, per computer
			+ (I.ctx('INBOX_CAN_SEND', false) && window.isSecureContext && navigator.registerProtocolHandler ? '<div class="ibx-formrow"><span class="ibx-formrow__label">' + th('InboxMailtoLinks') + '</span><div><button type="button" class="ibx-btn ibx-js-mailto">' + ic('mail') + ' ' + th('InboxMailtoRegister') + '</button><div class="ibx-formrow__help">' + th('InboxMailtoHelp') + '</div></div></div>' : '')
			+ '</div></section>'
			+ '<section class="ibx-section" data-section="actions">' + title('actions') + '<div class="ibx-form">'
			+ '<div class="ibx-formrow"><label class="ibx-formrow__label" for="me-after_move">' + th('InboxAfterMove') + '</label><div>' + opt('after_move', ['next', 'previous'], p.after_move) + '<div class="ibx-formrow__help">' + th('InboxAfterMoveHelp') + '</div></div></div>'
			+ ['swipe_left', 'swipe_right'].map(k => '<div class="ibx-formrow"><label class="ibx-formrow__label" for="me-' + k + '">' + th(k === 'swipe_left' ? 'InboxSwipeLeft' : 'InboxSwipeRight') + '</label><div>'
				+ '<select class="ibx-field ibx-field--medium" data-param="' + k + '" id="me-' + k + '">' + ['none', 'archive', 'delete', 'read', 'flag', 'tag', 'move'].map(v => '<option value="' + v + '"' + (v === p[k] ? ' selected' : '') + '>' + th('InboxSwipe_' + v) + '</option>').join('') + '</select>'
				+ (k === 'swipe_right' ? '<div class="ibx-formrow__help">' + th('InboxSwipeHelp') + '</div>' : '') + '</div></div>').join('')
			+ '</div></section></div>';
		main.innerHTML = html;
		syncSectionLinks(main);
		spySections(main, ME_SECTIONS.map(x => x[0]));
		// My dictation comes later (shown only when used): its link then, and a section asked by the route
		// (#/settings/me/unified, from a menu) brought into view once the page is complete
		Promise.all([myDictation($('.ibx-js-mydictation', main)), myDevices($('.ibx-js-mydevices', main))]).then(() => {
			syncSectionLinks(main);
			if (parts && parts[0]) {
				requestAnimationFrame(() => pointSection(main, parts[0]));
			}
		});
		bindBoxOrder(main);
		const unified = $('.ibx-js-unified', main);
		if (unified) {
			unified.addEventListener('change', async () => {
				const out = $$('input[type="checkbox"]', unified).filter(c => !c.checked).map(c => +c.value);
				try {
					const r = await post('userparam', 'set', { param: 'unified_exclude', value: JSON.stringify(out) });
					Dolibarr.executeHook('inbox:params:changed', r.params);
					toast(t('InboxSaved'));
				} catch (err) {
					toast(err.message, { error: true });
				}
			});
		}
		const mailto = $('.ibx-js-mailto', main);
		if (mailto) {
			mailto.addEventListener('click', () => {
				try {
					// The offer of the start (js/inbox-compose.js) is then not shown again
					I.registerMailto();
				} catch (err) {
					toast(err.message, { error: true });
				}
			});
		}
		$$('.ibx-js-readswitch', main).forEach(sw => sw.addEventListener('click', async () => {
			const on = sw.getAttribute('aria-checked') !== 'true';
			// Shown at once, put back if the server refuses
			sw.setAttribute('aria-checked', on ? 'true' : 'false');
			try {
				const r = await post('userparam', 'set', { id: 0, param: sw.dataset.readparam, value: on ? '1' : '0' });
				Dolibarr.executeHook('inbox:params:changed', r.params);
				toast(t('InboxSaved'));
			} catch (err) {
				sw.setAttribute('aria-checked', on ? 'false' : 'true');
				toast(err.message, { error: true });
			}
		}));
		$$('[data-param]', main).forEach(f => {
			const evt = f.type === 'range' ? 'change' : 'change';
			if (f.type === 'range') {
				f.addEventListener('input', () => {
					$('.ibx-js-ts', main).textContent = f.value + ' %';
					f.setAttribute('aria-valuetext', f.value + ' %');
					document.documentElement.style.fontSize = f.value + '%';
				});
			}
			f.addEventListener(evt, async () => {
				const accountId = f.dataset.account || 0;
				try {
					const r = (accountId && f.value === '')
						? await post('userparam', 'remove', { id: accountId, param: f.dataset.param })
						: await post('userparam', 'set', { id: accountId, param: f.dataset.param, value: f.value });
					if (!accountId) {
						I.applyDisplay(r.params);
						Dolibarr.executeHook('inbox:params:changed', r.params);
					}
					toast(t('InboxSaved'));
				} catch (err) {
					toast(err.message, { error: true });
				}
			});
		});
	}
	// ---------- Notifications (lot W8 1.21.0) ----------
	/**
	 * Settings > Notifications: what the unread counter counts, the counter in the top bar of Dolibarr, the
	 * notifications of the browser (permission asked on the click only), the new mails told per mailbox, the channels
	 * per type (the bell always: it is the list of reference)
	 */
	async function renderNotifications(main) {
		main.innerHTML = skeleton();
		let data;
		try {
			data = await api('userparam', 'get');
		} catch (err) {
			main.replaceChildren(errorBox(err, () => renderNotifications(main)));
			return;
		}
		const p = data.params;
		let channels = {};
		try {
			channels = JSON.parse(p.notif_channels || '{}') || {};
		} catch (e) {
			channels = {};
		}
		const chan = (type, c) => (channels[type] && channels[type][c] !== undefined ? +channels[type][c] === 1 : c === 'b');
		const sw = (attrs, on, label) => '<button type="button" class="ibx-switch" role="switch" aria-checked="' + (on ? 'true' : 'false') + '" aria-label="' + esc(label) + '" ' + attrs + '></button>';
		const newMail = m => (m.params.new_mail_notify === 'auto' ? !!m.personal : m.params.new_mail_notify === '1');
		const types = ['assign', 'send_failed', 'bounce', 'box_error', 'rule_redirect', 'access_self'];
		let html = '<div class="ibx-page"><div class="ibx-page__head"><h1 class="ibx-page__title">' + th('InboxNavNotifications') + '</h1></div>'
			+ '<section class="ibx-section"><h2 class="ibx-section__title">' + th('InboxSectionBrowserNotif') + '</h2><div class="ibx-js-browser"></div></section>'
			+ '<section class="ibx-section"><h2 class="ibx-section__title">' + th('InboxSectionNotifTypes') + '</h2><p class="ibx-section__help">' + th('InboxNotifTypesHelp') + '</p>'
			+ '<table class="ibx-table"><thead><tr><th scope="col">' + th('InboxNotifType') + '</th><th scope="col">' + th('InboxChannelBell') + '</th><th scope="col">' + th('InboxChannelBrowser') + '</th><th scope="col">' + th('InboxChannelMail') + '</th></tr></thead><tbody>'
			+ types.map(type => '<tr><td>' + th('InboxNotifType_' + type) + '</td>'
				+ '<td>' + sw('aria-disabled="true" title="' + th('InboxChannelBellAlways') + '"', true, t('InboxChannelBell')) + '</td>'
				+ '<td>' + sw('data-type="' + type + '" data-chan="b"', chan(type, 'b'), t('InboxChannelBrowser') + ' : ' + t('InboxNotifType_' + type)) + '</td>'
				+ '<td>' + sw('data-type="' + type + '" data-chan="m"', chan(type, 'm'), t('InboxChannelMail') + ' : ' + t('InboxNotifType_' + type)) + '</td></tr>').join('')
			+ '</tbody></table></section>'
			+ '<section class="ibx-section"><h2 class="ibx-section__title">' + th('InboxSectionCounter') + '</h2><p class="ibx-section__help">' + th('InboxCounterHelp') + '</p><div class="ibx-form">'
			+ '<div class="ibx-formrow"><label class="ibx-formrow__label" for="nt-count_scope">' + th('InboxCountScope') + '</label><div><select class="ibx-field ibx-field--medium" id="nt-count_scope">'
			+ ['all', 'personal'].map(v => '<option value="' + v + '"' + (p.count_scope === v ? ' selected' : '') + '>' + th('Inbox_count_scope_' + v) + '</option>').join('') + '</select></div></div>'
			+ '<div class="ibx-formrow"><span class="ibx-formrow__label">' + th('InboxCountInDolibarr') + '</span><div>' + sw('data-param="count_in_dolibarr"', p.count_in_dolibarr === '1', t('InboxCountInDolibarr'))
			+ '<div class="ibx-formrow__help">' + th('InboxCountInDolibarrHelp') + '</div></div></div></div></section>';
		if (data.mailboxes.length) {
			html += '<section class="ibx-section"><h2 class="ibx-section__title">' + th('InboxSectionNotifBoxes') + '</h2><p class="ibx-section__help">' + th('InboxNotifBoxesHelp') + '</p>'
				+ '<table class="ibx-table"><thead><tr><th scope="col">' + th('InboxMailbox') + '</th><th scope="col">' + th('InboxCountBox') + '</th><th scope="col">' + th('InboxNewMailNotify') + '</th></tr></thead><tbody>'
				+ data.mailboxes.map(m => '<tr><td><b>' + esc(m.label || m.email) + '</b><div class="ibx-muted">' + esc(m.email) + '</div></td>'
					+ '<td>' + sw('data-param="count_box" data-account="' + m.id + '"', m.params.count_box === '1', t('InboxCountBox') + ' : ' + (m.label || m.email)) + '</td>'
					+ '<td>' + sw('data-param="new_mail_notify" data-account="' + m.id + '"', newMail(m), t('InboxNewMailNotify') + ' : ' + (m.label || m.email)) + '</td></tr>').join('')
				+ '</tbody></table></section>';
		}
		html += '</div>';
		main.innerHTML = html;

		const recount = () => api('notification', 'count').then(c => Dolibarr.executeHook('inbox:notif:state', c)).catch(() => {});
		const save = async (accountId, param, value) => {
			const r = await post('userparam', 'set', { id: accountId, param, value });
			if (!accountId) {
				Dolibarr.executeHook('inbox:params:changed', r.params);
			}
			toast(t('InboxSaved'));
			return r;
		};

		// Notifications of the browser: state of the permission, asked on the click only
		const browser = $('.ibx-js-browser', main);
		const paintBrowser = () => {
			const supported = 'Notification' in window && window.isSecureContext;
			const perm = supported ? Notification.permission : 'unsupported';
			const on = p.browser_notify === '1' && perm === 'granted';
			let state;
			let button = '';
			if (!supported) {
				state = '<span class="ibx-status">' + th('InboxBrowserNotifUnsupported') + '</span>';
			} else if (perm === 'denied') {
				state = '<span class="ibx-status ibx-status--warning">' + th('InboxBrowserNotifDenied') + '</span><div class="ibx-formrow__help">' + th('InboxBrowserNotifDeniedHelp') + '</div>';
			} else if (on) {
				state = '<span class="ibx-status ibx-status--ok">' + th('InboxBrowserNotifOn') + '</span>';
				button = '<button type="button" class="ibx-btn ibx-js-browseroff">' + th('InboxBrowserNotifDisable') + '</button>';
			} else {
				state = '<span class="ibx-status">' + th('InboxBrowserNotifOff') + '</span>';
				button = '<button type="button" class="ibx-btn ibx-btn--primary ibx-js-browseron">' + ic('bell') + ' ' + th('InboxBrowserNotifEnable') + '</button>';
			}
			browser.innerHTML = '<div class="ibx-form"><div class="ibx-formrow"><span class="ibx-formrow__label">' + th('InboxStatus') + '</span><div>' + state
				+ '<div class="ibx-formrow__help">' + th('InboxBrowserNotifHelp') + '</div>' + (button ? '<div class="ibx-formrow__actions">' + button + '</div>' : '') + '</div></div></div>';
		};
		paintBrowser();
		browser.addEventListener('click', async e => {
			const enable = e.target.closest('.ibx-js-browseron');
			const disable = e.target.closest('.ibx-js-browseroff');
			if (!enable && !disable) {
				return;
			}
			try {
				if (enable) {
					const perm = Notification.permission === 'granted' ? 'granted' : await Notification.requestPermission();
					if (perm !== 'granted') {
						paintBrowser();
						return;
					}
				}
				const r = await save(0, 'browser_notify', enable ? '1' : '0');
				p.browser_notify = r.params.browser_notify;
			} catch (err) {
				toast(err.message, { error: true });
			}
			paintBrowser();
		});

		$('#nt-count_scope', main).addEventListener('change', async ev => {
			try {
				await save(0, 'count_scope', ev.target.value);
				recount();
			} catch (err) {
				toast(err.message, { error: true });
			}
		});
		$$('.ibx-switch:not([aria-disabled])', main).forEach(b => b.addEventListener('click', async () => {
			const on = b.getAttribute('aria-checked') !== 'true';
			// Shown at once, put back if the server refuses
			b.setAttribute('aria-checked', on ? 'true' : 'false');
			try {
				if (b.dataset.chan) {
					channels[b.dataset.type] = Object.assign({}, channels[b.dataset.type] || {}, { [b.dataset.chan]: on ? 1 : 0 });
					await save(0, 'notif_channels', JSON.stringify(channels));
				} else {
					await save(+b.dataset.account || 0, b.dataset.param, on ? '1' : '0');
					if (b.dataset.param !== 'new_mail_notify') {
						recount();
					}
				}
			} catch (err) {
				b.setAttribute('aria-checked', on ? 'false' : 'true');
				toast(err.message, { error: true });
			}
		}));
	}
})();
