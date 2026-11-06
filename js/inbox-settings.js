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
 *   #/settings/accounts/<id>/<tab>     card of a mailbox: server, folders, identities, access, tags
 *   #/settings/import                  import from the sources declared by other modules
 *   #/settings/general                 defaults of the webmail (admins of the mailboxes)
 *   #/settings/me                      settings of the current user
 * Every change is saved in ajax; a refused action is greyed with its reason, never hidden without a word.
 */
(function () {
	'use strict';

	const I = Dolibarr.tools.inbox;
	const { esc, t, th, ic, api, post, toast, dialog, confirmDialog, $, $$ } = I;
	const rights = I.rights;
	const isAdmin = () => !!rights().account_admin;

	let importSources = null;

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
		return new Date(ts * 1000).toLocaleString();
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
			const pages = { accounts: renderAccounts, import: renderImport, general: renderGeneral, me: renderMe };
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
				this.render(this.el, this.route);
			}
		},
	};
	I.registerSpace(space);

	function renderNav(el, page) {
		const nav = $('.ibx-settings__nav', el);
		const link = (id, icon, key) => '<a class="ibx-navlink" href="#/settings/' + id + '"' + (page === id ? ' aria-current="page"' : '') + '>' + ic(icon) + ' ' + th(key) + '</a>';
		nav.innerHTML = '<div class="ibx-settings__navtitle">' + th('InboxSettings') + '</div>'
			+ link('accounts', 'inbox', 'InboxNavAccounts')
			+ (isAdmin() && importSources && importSources.length ? link('import', 'download', 'InboxNavImport') : '')
			+ (isAdmin() ? link('general', 'settings', 'InboxNavGeneral') : '')
			+ '<div class="ibx-settings__navgroup">' + th('InboxNavPersonal') + '</div>'
			+ link('me', 'account', 'InboxNavMe');
		if (isAdmin() && importSources === null) {
			importSources = [];
			api('import', 'sources').then(r => {
				importSources = r.sources;
				if (importSources.length) {
					renderNav(el, page);
				}
			}).catch(() => {});
		}
	}

	// ---------- Mailboxes: list ----------
	async function renderAccounts(main, parts) {
		if (parts[0]) {
			return renderAccountCard(main, parts[0], parts[1] || 'server');
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
			+ (isAdmin() && importSources && importSources.length ? '<a class="ibx-btn" href="#/settings/import">' + ic('download') + ' ' + th('InboxImportButton') + '</a>' : '')
			+ (isAdmin() ? '<a class="ibx-btn ibx-btn--primary" href="#/settings/accounts/new">' + ic('plus') + ' ' + th('InboxNewAccount') + '</a>' : '')
			+ '</div>';
		if (!isAdmin()) {
			html += '<p class="ibx-page__intro">' + th('InboxAccountsManagerIntro') + '</p>';
		}
		if (!rows.length) {
			html += '<div class="ibx-empty" style="height:auto">' + ic('inbox', 'ibx-empty__icon') + '<div class="ibx-empty__title">' + th('InboxNoAccount') + '</div><div>'
				+ th(isAdmin() ? 'InboxNoAccountAdmin' : 'InboxNoAccountManager') + '</div></div>';
		} else {
			html += '<table class="ibx-table"><thead><tr><th>' + th('InboxAccount') + '</th><th>' + th('InboxAccountType') + '</th><th>' + th('InboxStatus') + '</th><th>' + th('InboxLastTest') + '</th><th>' + th('InboxSync') + '</th><th>' + th('InboxAccesses') + '</th></tr></thead><tbody>';
			rows.forEach(r => {
				html += '<tr><td><a href="#/settings/accounts/' + r.id + '/server"><b>' + esc(r.label || r.email) + '</b></a><div class="ibx-muted">' + esc(r.email) + '</div></td>'
					+ '<td>' + th(r.type === 'personal' ? 'InboxTypePersonal' : 'InboxTypeShared') + (r.owner ? '<div class="ibx-muted">' + esc(r.owner) + '</div>' : '') + '</td>'
					+ '<td>' + statusBadge(r.status) + (r.sync_state && r.sync_state !== 'ok' ? '<div><span class="ibx-status ibx-status--error">' + th('InboxSyncError') + '</span></div>' : '') + '</td>'
					+ '<td>' + testBadge(r.last_test_result) + '</td>'
					+ '<td>' + syncBadge(r) + '</td>'
					+ '<td>' + (r.type === 'personal' ? th('InboxAccessOwnerPlus', r.accesses) : (r.accesses ? esc(r.accesses) : '<span class="ibx-status ibx-status--warning">' + th('InboxNoAccess') + '</span>')) + '</td></tr>';
			});
			html += '</tbody></table>';
		}
		main.innerHTML = html + '</div>';
	}

	// ---------- Mailbox: card ----------
	const TABS = [
		{ id: 'server', key: 'InboxTabServer', admin: true },
		{ id: 'folders', key: 'InboxTabFolders', admin: true },
		{ id: 'identities', key: 'InboxTabIdentities', admin: false },
		{ id: 'access', key: 'InboxTabAccess', admin: true },
		{ id: 'tags', key: 'InboxTabTags', admin: false },
		{ id: 'sync', key: 'InboxTabSync', admin: false },
	];

	async function renderAccountCard(main, id, tab) {
		const isNew = id === 'new';
		if (isNew && !isAdmin()) {
			I.go('#/settings/accounts');
			return;
		}
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
		const tabs = TABS.filter(x => isAdmin() || !x.admin);
		if (!tabs.find(x => x.id === tab)) {
			tab = tabs[0].id;
		}
		if (isNew) {
			tab = 'server';
		}
		const title = isNew ? t('InboxNewAccount') : (account.label || account.email);
		let html = '<div class="ibx-page"><a class="ibx-page__back" href="#/settings/accounts">' + ic('chevron-left') + ' ' + th('InboxNavAccounts') + '</a>'
			+ '<div class="ibx-page__head"><h1 class="ibx-page__title">' + esc(title) + '</h1>' + (account ? statusSwitch(account) : '') + '</div>';
		html += '<div class="ibx-tabs" role="tablist">' + tabs.map(x => {
			const disabled = isNew && x.id !== 'server';
			return '<a class="ibx-tab" role="tab" href="' + (disabled ? '#' : '#/settings/accounts/' + id + '/' + x.id) + '" aria-selected="' + (x.id === tab) + '"'
				+ (disabled ? ' aria-disabled="true" title="' + th('InboxSaveFirst') + '"' : '') + '>' + th(x.key) + '</a>';
		}).join('') + '</div><div class="ibx-js-tab"></div></div>';
		main.innerHTML = html;
		$$('.ibx-tab[aria-disabled="true"]', main).forEach(a => a.addEventListener('click', e => e.preventDefault()));
		if (account) {
			bindStatusSwitch(main, account, tab);
		}
		const box = $('.ibx-js-tab', main);
		const fns = { server: tabServer, folders: tabFolders, identities: tabIdentities, access: tabAccess, tags: tabTags, sync: tabSync };
		fns[tab](box, account, main);
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
		let html = '<form class="ibx-form" novalidate autocomplete="off">'
			+ '<section class="ibx-section"><h2 class="ibx-section__title">' + th('InboxSectionMailbox') + '</h2><div class="ibx-form">'
			+ field('email', t('InboxAccountEmail'), a.email, { type: 'email', required: true, autocomplete: 'off', placeholder: 'devis@example.com', cls: 'ibx-field--medium' })
			+ field('label', t('InboxAccountLabel'), a.label, { help: t('InboxAccountLabelHelp'), cls: 'ibx-field--medium' })
			+ field('type', t('InboxAccountType'), a.type, { options: [['shared', t('InboxTypeShared')], ['personal', t('InboxTypePersonal')]], cls: 'ibx-field--medium' })
			+ '<div class="ibx-formrow" data-field="fk_user_owner"' + (a.type === 'personal' ? '' : ' hidden') + '><label class="ibx-formrow__label" for="f-owner-q">' + th('InboxAccountOwner') + ' <span class="ibx-req" aria-hidden="true">*</span></label><div>'
			+ '<input type="hidden" name="fk_user_owner" value="' + esc(a.fk_user_owner || '') + '"><input class="ibx-field ibx-field--medium" id="f-owner-q" list="ibx-owner-list" autocomplete="off" placeholder="' + th('InboxSearchUser') + '" value="' + esc(a.owner_name || (account && account.summary ? account.summary.owner : '')) + '"><datalist id="ibx-owner-list"></datalist>'
			+ '<div class="ibx-formrow__help">' + th('InboxAccountOwnerHelp') + '</div><div class="ibx-formrow__error" hidden></div></div></div>'
			+ '</div></section>'
			+ '<section class="ibx-section"><h2 class="ibx-section__title">' + th('InboxSectionImap') + '</h2><div class="ibx-form">'
			+ field('imap_host', t('InboxImapHost'), a.imap_host, { required: true, placeholder: 'mail.example.com', cls: 'ibx-field--medium', inputmode: 'url' })
			+ '<div class="ibx-formrow"><span class="ibx-formrow__label">' + th('InboxPortEncryption') + '</span><div class="ibx-inline">'
			+ '<input class="ibx-field ibx-field--narrow" name="imap_port" type="number" min="1" max="65535" inputmode="numeric" aria-label="' + th('InboxImapPort') + '" value="' + esc(a.imap_port) + '">'
			+ '<select class="ibx-field ibx-field--medium" name="imap_encryption" aria-label="' + th('InboxEncryption') + '">' + enc.map(([v, l]) => '<option value="' + v + '"' + (v === a.imap_encryption ? ' selected' : '') + '>' + esc(l) + '</option>').join('') + '</select></div></div>'
			+ field('imap_auth_mode', t('InboxImapAuthMode'), a.imap_auth_mode, { options: [['login', t('InboxAuthLogin')], ['master', t('InboxAuthMaster')], ['oauth', t('InboxAuthOAuth')]], cls: 'ibx-field--medium' })
			+ field('oauth_service', t('InboxOAuthService'), a.oauth_service, { hidden: a.imap_auth_mode !== 'oauth', help: t('InboxOAuthServiceHelp'), cls: 'ibx-field--medium' })
			+ field('imap_login', t('InboxImapLogin'), a.imap_login, { placeholder: t('InboxLoginDefault'), autocomplete: 'off', cls: 'ibx-field--medium', hidden: a.imap_auth_mode === 'master' })
			+ '<div class="ibx-formrow" data-field="imap_password"' + (a.imap_auth_mode === 'login' ? '' : ' hidden') + '><label class="ibx-formrow__label" for="f-imap_password">' + th('InboxPassword') + '</label><div>'
			+ '<div class="ibx-password"><input class="ibx-field ibx-field--medium" id="f-imap_password" name="imap_password" type="password" autocomplete="new-password" placeholder="' + (a.has_imap_password ? th('InboxPasswordKept') : '') + '"></div>'
			+ '<div class="ibx-formrow__help">' + th(a.has_imap_password ? 'InboxPasswordKeptHelp' : 'InboxPasswordHelp') + '</div><div class="ibx-formrow__error" hidden></div></div></div>'
			+ '<div class="ibx-formrow"><span class="ibx-formrow__label">' + th('InboxCertificate') + '</span><div><label class="ibx-check"><input type="checkbox" name="imap_validate_cert"' + (a.imap_validate_cert ? ' checked' : '') + '> ' + th('InboxValidateCert') + '</label></div></div>'
			+ '</div></section>'
			+ '<section class="ibx-section"><h2 class="ibx-section__title">' + th('InboxSectionSmtp') + '</h2><div class="ibx-form">'
			+ field('smtp_host', t('InboxSmtpHost'), a.smtp_host, { placeholder: t('InboxSameAsImap'), cls: 'ibx-field--medium', inputmode: 'url' })
			+ '<div class="ibx-formrow"><span class="ibx-formrow__label">' + th('InboxPortEncryption') + '</span><div class="ibx-inline">'
			+ '<input class="ibx-field ibx-field--narrow" name="smtp_port" type="number" min="1" max="65535" inputmode="numeric" aria-label="' + th('InboxSmtpPort') + '" value="' + esc(a.smtp_port) + '">'
			+ '<select class="ibx-field ibx-field--medium" name="smtp_encryption" aria-label="' + th('InboxEncryption') + '">' + enc.map(([v, l]) => '<option value="' + v + '"' + (v === a.smtp_encryption ? ' selected' : '') + '>' + esc(l) + '</option>').join('') + '</select></div></div>'
			+ '<div class="ibx-formrow"><span class="ibx-formrow__label">' + th('InboxSmtpCredentials') + '</span><div><label class="ibx-check"><input type="checkbox" name="smtp_same_credentials"' + (a.smtp_same_credentials ? ' checked' : '') + '> ' + th('InboxSmtpSameCredentials') + '</label></div></div>'
			+ field('smtp_login', t('InboxSmtpLogin'), a.smtp_login, { hidden: !!a.smtp_same_credentials, autocomplete: 'off', placeholder: t('InboxLoginDefault'), cls: 'ibx-field--medium' })
			+ '<div class="ibx-formrow" data-field="smtp_password"' + (a.smtp_same_credentials ? ' hidden' : '') + '><label class="ibx-formrow__label" for="f-smtp_password">' + th('InboxPassword') + '</label><div><input class="ibx-field ibx-field--medium" id="f-smtp_password" name="smtp_password" type="password" autocomplete="new-password" placeholder="' + (a.has_smtp_password ? th('InboxPasswordKept') : '') + '"></div></div>'
			+ '</div></section>'
			+ '<section class="ibx-section"><h2 class="ibx-section__title">' + th('InboxSectionMore') + '</h2><div class="ibx-form">'
			+ field('sieve_port', t('InboxSievePort'), a.sieve_port, { type: 'number', min: 0, max: 65535, inputmode: 'numeric', help: t('InboxSievePortHelp'), cls: 'ibx-field--narrow' })
			+ field('sync_max_days', t('InboxSyncMaxDays'), a.sync_max_days, { type: 'number', min: 0, max: 36500, inputmode: 'numeric', help: t('InboxSyncLimitsHelp'), cls: 'ibx-field--narrow' })
			+ field('sync_max_mails', t('InboxSyncMaxMails'), a.sync_max_mails, { type: 'number', min: 0, max: 1000000, inputmode: 'numeric', cls: 'ibx-field--narrow' })
			+ '</div></section>'
			+ '<div class="ibx-js-testresult"></div>'
			+ '<div class="ibx-formfoot"><button type="button" class="ibx-btn ibx-js-test">' + ic('check') + ' ' + th('InboxTestConnection') + '</button>'
			+ '<span class="ibx-formfoot__spacer"></span>'
			+ (account ? '<span class="ibx-js-statusactions">' + statusActions(account) + '</span>' : '')
			+ '<button type="submit" class="ibx-btn ibx-btn--primary">' + th(account ? 'InboxSave' : 'InboxCreate') + '</button></div>'
			+ '</form>';
		box.innerHTML = html;
		const form = $('form', box);
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
		form.addEventListener('change', e => {
			sync();
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
		const showErrors = err => {
			$$('.ibx-formrow.is-invalid', form).forEach(r => r.classList.remove('is-invalid'));
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
			const values = formValues(form);
			if (account) {
				values.id = account.id;
				// The result is kept on the mailbox only when the form has no unsaved change
				values.save = form.dataset.dirty ? 0 : 1;
			}
			try {
				const r = await post('account', 'test', values);
				out.innerHTML = checklist(r.test);
				if (account && values.save) {
					I.changed('account', account.id);
				}
			} catch (err) {
				out.innerHTML = '';
				showErrors(err);
			}
			btn.removeAttribute('aria-disabled');
		});
		form.addEventListener('input', () => {
			form.dataset.dirty = '1';
		});
		bindStatusActions(form, account, main);
	}

	function ownerPicker(form) {
		const q = $('#f-owner-q', form);
		const list = $('#ibx-owner-list', form);
		const hidden = $('[name="fk_user_owner"]', form);
		// Users seen, by the name shown (same reason as the access picker)
		const seen = new Map();
		let timer = 0;
		q.addEventListener('input', () => {
			const hit = seen.get(q.value);
			hidden.value = hit ? hit.id : '';
			clearTimeout(timer);
			if (hit) {
				return;
			}
			timer = setTimeout(async () => {
				try {
					const users = (await api('access', 'search', { q: q.value })).results.filter(x => x.kind === 'user');
					users.forEach(u => {
						if (!seen.has(u.name)) {
							seen.set(u.name, u);
						}
					});
					if (users.length) {
						list.innerHTML = users.map(u => '<option value="' + esc(u.name) + '"></option>').join('');
					}
					const again = seen.get(q.value);
					hidden.value = again ? again.id : '';
				} catch (e) {
					// The picker stays usable with what it has
				}
			}, 200);
		});
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
		return '<span class="ibx-inline"><span class="ibx-js-statuslabel">' + label + '</span>'
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
		};
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
		return account.status === 9 ? '<button type="button" class="ibx-btn ibx-btn--danger ibx-js-delete">' + ic('delete') + ' ' + th('InboxDeleteAccount') + '</button>' : '';
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
		on('.ibx-js-delete', async () => {
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
		roles.forEach(r => {
			const current = data.roles[r] || '';
			html += '<div class="ibx-formrow"><label class="ibx-formrow__label" for="f-role-' + r + '">' + th(roleKey(r)) + '</label><div><select class="ibx-field ibx-field--medium" id="f-role-' + r + '" name="role_' + r + '">'
				+ '<option value="">' + th('InboxFolderNone') + '</option>'
				+ selectable.map(f => '<option value="' + esc(f.path) + '"' + (f.path === current ? ' selected' : '') + ' title="' + esc(f.path) + '">' + esc(f.label || f.name) + (f.role === r ? ' (' + th('InboxFolderFound') + ')' : '') + '</option>').join('')
				+ '</select>' + (current ? '' : '<div class="ibx-formrow__help">' + th('InboxFolderMissing') + '</div>') + '</div></div>';
		});
		html += '<div class="ibx-formfoot"><span class="ibx-muted">' + th('InboxFoldersCount', data.folders.length) + '</span><span class="ibx-formfoot__spacer"></span><button type="submit" class="ibx-btn ibx-btn--primary">' + th('InboxSave') + '</button></div></form>';
		box.innerHTML = html;
		$('form', box).addEventListener('submit', async e => {
			e.preventDefault();
			try {
				await post('account', 'roles', Object.assign({ id: account.id }, formValues(e.target)));
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
			html += '<div class="ibx-notice ibx-notice--warning">' + ic('warning') + '<div class="ibx-notice__text">' + th('InboxNoIdentity') + '</div></div>';
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
			+ '<div class="ibx-formrow"><label class="ibx-formrow__label" for="f-signature">' + th('InboxIdentitySignature') + '</label><div><textarea class="ibx-field" id="f-signature" name="signature" rows="6">' + esc(identity.signature) + '</textarea>'
			+ '<div class="ibx-formrow__help">' + th('InboxSignatureHelp') + '</div><div class="ibx-inline" style="margin-top:.5rem"><button type="button" class="ibx-btn ibx-btn--sm ibx-js-profiles">' + ic('download') + ' ' + th('InboxImportFromDolibarr') + '</button></div></div></div>'
			+ '<div class="ibx-formrow"><span class="ibx-formrow__label">' + th('InboxOptions') + '</span><div><label class="ibx-check"><input type="checkbox" name="is_default"' + (identity.is_default ? ' checked' : '') + '> ' + th('InboxIdentityDefault') + '</label><br>'
			+ '<label class="ibx-check"><input type="checkbox" name="status"' + (identity.status ? ' checked' : '') + '> ' + th('InboxIdentityActive') + '</label></div></div>'
			+ '</form>';
		dialog({
			title: identity.id ? t('InboxEditIdentity') : t('InboxAddIdentity'),
			wide: true,
			body,
			actions: [{ label: t('InboxSave'), primary: true, run: async d => {
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
			},
		});
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
				if (!$('[name="email"]', parent).value) {
					$('[name="email"]', parent).value = r.email;
				}
			} }],
		});
	}

	// ----- Tab Access -----
	async function tabAccess(box, account) {
		box.innerHTML = skeleton(4);
		let data;
		let log;
		try {
			[data, log] = await Promise.all([api('access', 'list', { id: account.id }), api('account', 'log', { id: account.id })]);
		} catch (err) {
			box.replaceChildren(errorBox(err, () => tabAccess(box, account)));
			return;
		}
		const levels = ['read', 'send', 'manage'];
		let html = '<p class="ibx-section__help">' + th('InboxAccessHelp') + '</p>';
		if (data.owner) {
			html += '<div class="ibx-notice ibx-notice--info">' + ic('account') + '<div class="ibx-notice__text">' + th('InboxAccessOwner', data.owner.name) + '</div></div>';
		}
		if (!data.accesses.length) {
			html += '<div class="ibx-notice ibx-notice--warning">' + ic('warning') + '<div class="ibx-notice__text">' + th(data.owner ? 'InboxNoOtherAccess' : 'InboxNoAccessYet') + '</div></div>';
		} else {
			html += '<table class="ibx-table"><thead><tr><th>' + th('InboxAccessWho') + '</th><th>' + th('InboxAccessLevel') + '</th><th></th></tr></thead><tbody>'
				+ data.accesses.map(a => '<tr><td>' + ic(a.fk_usergroup ? 'multi' : 'account') + ' ' + esc(a.name) + (a.fk_usergroup ? ' <span class="ibx-muted">' + th('InboxGroupAllMembers') + '</span>' : '') + '</td>'
					+ '<td><select class="ibx-field ibx-field--medium" data-level="' + a.fk_user + ':' + a.fk_usergroup + '" aria-label="' + th('InboxAccessLevel') + '">' + levels.map(l => '<option value="' + l + '"' + (l === a.level ? ' selected' : '') + '>' + esc(levelLabel(l)) + '</option>').join('') + '</select></td>'
					+ '<td class="ibx-table__actions"><button type="button" class="ibx-btn ibx-btn--sm ibx-btn--ghost ibx-btn--icon" data-revoke="' + a.fk_user + ':' + a.fk_usergroup + ':' + a.level + '" title="' + th('InboxRemoveAccess') + '" aria-label="' + th('InboxRemoveAccess') + '">' + ic('close') + '</button></td></tr>').join('')
				+ '</tbody></table>';
		}
		html += '<form class="ibx-inline ibx-js-grant" style="margin-top:1rem"><input class="ibx-field ibx-field--medium" list="ibx-access-list" placeholder="' + th('InboxSearchUserOrGroup') + '" aria-label="' + th('InboxSearchUserOrGroup') + '" autocomplete="off"><datalist id="ibx-access-list"></datalist>'
			+ '<select class="ibx-field ibx-field--medium" name="level" aria-label="' + th('InboxAccessLevel') + '">' + levels.map(l => '<option value="' + l + '">' + esc(levelLabel(l)) + '</option>').join('') + '</select>'
			+ '<button type="submit" class="ibx-btn ibx-btn--primary">' + ic('user-plus') + ' ' + th('InboxGiveAccess') + '</button></form>';
		html += '<section class="ibx-section" style="margin-top:2rem"><h2 class="ibx-section__title">' + th('InboxJournal') + '</h2>' + journalHtml(log.log) + '</section>';
		box.innerHTML = html;

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
		const q = $('input', grant);
		// Every result seen, by the name shown: choosing an entry of the list fires a new search on the full name,
		// whose answer must not make the choice unknown
		const seen = new Map();
		let timer = 0;
		const search = async () => {
			try {
				const results = (await api('access', 'search', { q: q.value })).results;
				results.forEach(x => {
					if (!seen.has(x.name)) {
						seen.set(x.name, x);
					}
				});
				if (results.length) {
					$('#ibx-access-list', box).innerHTML = results.map(x => '<option value="' + esc(x.name) + '">' + esc(t(x.kind === 'group' ? 'InboxGroup' : 'InboxUser')) + '</option>').join('');
				}
			} catch (e) {
				// keep the last list
			}
		};
		q.addEventListener('input', () => {
			clearTimeout(timer);
			if (!seen.has(q.value)) {
				timer = setTimeout(search, 200);
			}
		});
		grant.addEventListener('submit', async e => {
			e.preventDefault();
			clearTimeout(timer);
			if (!seen.has(q.value) && q.value.trim() !== '') {
				await search();
			}
			const hit = seen.get(q.value);
			if (!hit) {
				toast(t('InboxChooseUserOrGroup'), { error: true });
				q.focus();
				return;
			}
			try {
				await post('access', 'grant', { id: account.id, fk_user: hit.kind === 'user' ? hit.id : 0, fk_usergroup: hit.kind === 'group' ? hit.id : 0, level: grant.level.value });
				toast(t('InboxAccessGiven', hit.name));
				refresh();
			} catch (err) {
				toast(err.message, { error: true });
			}
		});
	}

	function journalHtml(lines) {
		if (!lines.length) {
			return '<p class="ibx-muted">' + th('InboxJournalEmpty') + '</p>';
		}
		const fmt = ts => new Date(ts * 1000).toLocaleString();
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
				default:
					return l.action;
			}
		};
		return '<table class="ibx-table"><thead><tr><th>' + th('InboxDate') + '</th><th>' + th('InboxBy') + '</th><th>' + th('InboxWhat') + '</th></tr></thead><tbody>'
			+ lines.map(l => '<tr><td class="ibx-muted">' + esc(fmt(l.date)) + '</td><td>' + esc(l.user || '-') + '</td><td>' + esc(describe(l)) + (l.target ? ' : <b>' + esc(l.target) + '</b>' : '') + '</td></tr>').join('') + '</tbody></table>';
	}

	// ----- Tab Tags (and the global list of Settings > General) -----
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
		const editable = account ? (own || isAdmin()) : isAdmin();
		let html = '';
		if (account) {
			html += '<p class="ibx-section__help">' + th('InboxTagsModeHelp') + '</p><div class="ibx-form" role="radiogroup" aria-label="' + th('InboxTagsMode') + '">'
				+ '<label class="ibx-radio"><input type="radio" name="tags_mode" value="global"' + (own ? '' : ' checked') + '> ' + th('InboxTagsModeGlobal') + '</label>'
				+ '<label class="ibx-radio"><input type="radio" name="tags_mode" value="own"' + (own ? ' checked' : '') + '> ' + th('InboxTagsModeOwn') + '</label></div>';
			if (!own) {
				html += '<div class="ibx-notice ibx-notice--info" style="margin-top:1rem">' + ic('info') + '<div class="ibx-notice__text">' + th(isAdmin() ? 'InboxTagsGlobalHereAdmin' : 'InboxTagsGlobalHere') + '</div></div>';
			}
		}
		html += '<table class="ibx-table ibx-js-tags" style="margin-top:1rem"><thead><tr><th></th><th>' + th('InboxTagKey') + '</th><th>' + th('InboxTagLabel') + '</th><th>' + th('InboxTagColor') + '</th><th>' + th('InboxStatus') + '</th><th></th></tr></thead><tbody>'
			+ data.tags.map((tg, n) => '<tr class="' + (editable ? 'ibx-dragrow' : '') + '" draggable="' + editable + '" data-tag="' + tg.id + '">'
				+ '<td>' + (editable ? '<span class="ibx-draghandle" aria-hidden="true">' + ic('more-v') + '</span>' : '') + '</td>'
				+ '<td>' + (tg.key ? '<span class="ibx-kbd">' + esc(tg.key) + '</span>' : '') + '</td>'
				+ '<td>' + (editable ? '<input class="ibx-field" data-f="label" value="' + esc(tg.label) + '" aria-label="' + th('InboxTagLabel') + '">' : '<span class="ibx-tag" style="--ibx-tag-color:' + esc(tg.color) + '">' + esc(tg.label) + '</span>') + '</td>'
				+ '<td>' + (editable ? '<input type="color" class="ibx-color" data-f="color" value="' + esc(tg.color || '#888888') + '" aria-label="' + th('InboxTagColor') + '">' : '') + '</td>'
				+ '<td>' + (editable ? '<button type="button" class="ibx-switch" role="switch" data-f="status" aria-checked="' + (tg.status ? 'true' : 'false') + '" aria-label="' + th('InboxTagActive') + '"></button>' : (tg.status ? th('InboxAccountStatusActive') : th('InboxInactive'))) + '</td>'
				+ '<td class="ibx-table__actions">' + (editable ? '<button type="button" class="ibx-btn ibx-btn--sm ibx-btn--ghost ibx-btn--icon" data-move="-1" title="' + th('InboxMoveUp') + '" aria-label="' + th('InboxMoveUp') + '"' + (n === 0 ? ' aria-disabled="true"' : '') + '>' + ic('chevron-up') + '</button>'
					+ '<button type="button" class="ibx-btn ibx-btn--sm ibx-btn--ghost ibx-btn--icon" data-move="1" title="' + th('InboxMoveDown') + '" aria-label="' + th('InboxMoveDown') + '"' + (n === data.tags.length - 1 ? ' aria-disabled="true"' : '') + '>' + ic('chevron-down') + '</button>'
					+ '<button type="button" class="ibx-btn ibx-btn--sm ibx-btn--ghost ibx-btn--icon" data-deltag="1" title="' + th('InboxDelete') + '" aria-label="' + th('InboxDelete') + '">' + ic('delete') + '</button>' : '') + '</td></tr>').join('')
			+ '</tbody></table>';
		if (editable) {
			html += '<form class="ibx-inline ibx-js-addtag" style="margin-top:1rem"><input class="ibx-field ibx-field--medium" name="label" placeholder="' + th('InboxNewTag') + '" aria-label="' + th('InboxNewTag') + '" maxlength="128"><input type="color" class="ibx-color" name="color" value="#2a6fd6" aria-label="' + th('InboxTagColor') + '"><button type="submit" class="ibx-btn ibx-btn--primary">' + ic('plus') + ' ' + th('InboxAddTag') + '</button></form>';
			html += '<p class="ibx-formrow__help">' + th('InboxTagsKeysHelp') + '</p>';
		}
		box.innerHTML = html;
		const params = account ? { id: account.id } : {};
		const reload = () => {
			I.changed('tag', account ? account.id : 0);
			tabTags(box, account);
		};
		$$('[name="tags_mode"]', box).forEach(r => r.addEventListener('change', async () => {
			try {
				await post('tag', 'mode', Object.assign({ mode: r.value }, params));
				reload();
			} catch (err) {
				toast(err.message, { error: true });
			}
		}));
		const saveRow = async row => {
			const label = $('[data-f="label"]', row).value;
			const color = $('[data-f="color"]', row).value;
			const status = $('[data-f="status"]', row).getAttribute('aria-checked') === 'true' ? 1 : 0;
			try {
				await post('tag', 'update', Object.assign({ tag_id: row.dataset.tag, label, color, status }, params));
				I.changed('tag', account ? account.id : 0);
			} catch (err) {
				toast(err.message, { error: true });
			}
		};
		$$('[data-tag]', box).forEach(row => {
			if (!editable) {
				return;
			}
			$('[data-f="label"]', row).addEventListener('change', () => saveRow(row));
			$('[data-f="color"]', row).addEventListener('change', () => saveRow(row));
			$('[data-f="status"]', row).addEventListener('click', e => {
				const sw = e.currentTarget;
				sw.setAttribute('aria-checked', sw.getAttribute('aria-checked') === 'true' ? 'false' : 'true');
				saveRow(row).then(reload);
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
					await post('tag', 'create', Object.assign({ label: add.label.value, color: add.color.value }, params));
					reload();
				} catch (err) {
					toast(err.message, { error: true });
					add.label.focus();
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
				+ (st.retry_at ? '<br>' + th('InboxSyncRetryAt', new Date(st.retry_at * 1000).toLocaleTimeString()) : '') + '</div></div>';
		}
		html += '<div class="ibx-form"><div class="ibx-formrow"><span class="ibx-formrow__label">' + th('InboxStatus') + '</span><div>'
			+ (st.state ? '<span class="ibx-status' + (stateCls ? ' ibx-status--' + stateCls : '') + '">' + th('InboxSyncState_' + st.state) + '</span>' : '<span class="ibx-status">' + th('InboxSyncWaiting') + '</span>')
			+ (st.running ? ' · ' + th('InboxSyncRunning') : '') + (st.date_sync ? ' · ' + th('InboxSyncLast', ago(st.date_sync)) : '') + '</div></div>'
			+ '<div class="ibx-formrow"><span class="ibx-formrow__label">' + th('InboxSyncIndexed') + '</span><div>'
			+ '<progress max="100" value="' + pct + '" style="width:16rem;vertical-align:middle" aria-label="' + th('InboxSyncIndexed') + '"></progress> '
			+ th('InboxSyncIndexedOf', st.progress.indexed.toLocaleString(), st.progress.total.toLocaleString())
			+ (st.progress.first_sync_left ? ' · ' + th('InboxSyncFoldersLeft', st.progress.first_sync_left) : '') + '</div></div></div>';
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
					+ '<td>' + esc(f.total.toLocaleString()) + (f.unread ? ' <span class="ibx-muted">(' + th('InboxSyncUnread', f.unread) + ')</span>' : '') + '</td>'
					+ '<td>' + (f.sync_enabled ? esc(f.indexed.toLocaleString()) + (f.first_sync_done ? '' : ' <span class="ibx-status ibx-status--warning">' + th('InboxSyncInProgress') + '</span>') : '<span class="ibx-muted">-</span>') + '</td>'
					+ '<td class="ibx-muted">' + (f.date_sync ? esc(ago(f.date_sync)) : '-') + '</td></tr>').join('')
				+ '</tbody></table>';
		}
		html += '</section>';
		box.innerHTML = html;
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
		let html = '<div class="ibx-page"><a class="ibx-page__back" href="#/settings/accounts">' + ic('chevron-left') + ' ' + th('InboxNavAccounts') + '</a><div class="ibx-page__head"><h1 class="ibx-page__title">' + th('InboxNavImport') + '</h1></div>'
			+ '<p class="ibx-page__intro">' + th('InboxImportIntro') + '</p>';
		if (!sources.length) {
			html += '<div class="ibx-notice">' + ic('info') + '<div class="ibx-notice__text">' + th('InboxImportNoSource') + '</div></div></div>';
			main.innerHTML = html;
			return;
		}
		html += '<div class="ibx-formrow"><label class="ibx-formrow__label" for="f-source">' + th('InboxImportSource') + '</label><div><select class="ibx-field ibx-field--medium" id="f-source">' + sources.map(s => '<option value="' + esc(s.code) + '">' + esc(s.label) + '</option>').join('') + '</select></div></div>'
			+ '<div class="ibx-js-importlist" style="margin-top:1rem"></div></div>';
		main.innerHTML = html;
		const sel = $('#f-source', main);
		const listBox = $('.ibx-js-importlist', main);
		const load = async () => {
			listBox.innerHTML = skeleton(3);
			let accounts;
			try {
				accounts = (await api('import', 'list', { source: sel.value })).accounts;
			} catch (err) {
				listBox.replaceChildren(errorBox(err, load));
				return;
			}
			if (!accounts.length) {
				listBox.innerHTML = '<div class="ibx-notice ibx-notice--info">' + ic('check') + '<div class="ibx-notice__text">' + th('InboxImportNothing') + '</div></div>';
				return;
			}
			listBox.innerHTML = '<table class="ibx-table"><thead><tr><th><input type="checkbox" class="ibx-js-all" checked aria-label="' + th('InboxSelectAll') + '"></th><th>' + th('InboxAccount') + '</th><th>' + th('InboxImportWhat') + '</th></tr></thead><tbody>'
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

	// ---------- General (admins) ----------
	async function renderGeneral(main) {
		if (!isAdmin()) {
			I.go('#/settings/accounts');
			return;
		}
		main.innerHTML = skeleton();
		let s;
		try {
			s = (await api('general', 'get')).settings;
		} catch (err) {
			main.replaceChildren(errorBox(err, () => renderGeneral(main)));
			return;
		}
		main.innerHTML = '<div class="ibx-page"><div class="ibx-page__head"><h1 class="ibx-page__title">' + th('InboxNavGeneral') + '</h1></div><p class="ibx-page__intro">' + th('InboxGeneralIntro') + '</p>'
			+ '<section class="ibx-section"><h2 class="ibx-section__title">' + th('InboxSectionDefaults') + '</h2><div class="ibx-form ibx-js-general">'
			+ '<div class="ibx-formrow"><label class="ibx-formrow__label" for="g-accent">' + th('InboxAccent') + '</label><div class="ibx-inline"><input type="color" class="ibx-color" id="g-accent" data-name="INBOX_ACCENT" value="' + esc(s.INBOX_ACCENT) + '"><span class="ibx-formrow__help">' + th('InboxAccentHelp') + '</span></div></div>'
			+ '<div class="ibx-formrow"><label class="ibx-formrow__label" for="g-read">' + th('InboxReadMode') + '</label><div><select class="ibx-field ibx-field--medium" id="g-read" data-name="INBOX_DEFAULT_READ_MODE">' + ['immediate', 'delay', 'manual'].map(v => '<option value="' + v + '"' + (v === s.INBOX_DEFAULT_READ_MODE ? ' selected' : '') + '>' + th('InboxReadMode_' + v) + '</option>').join('') + '</select><div class="ibx-formrow__help">' + th('InboxReadModeDefaultHelp') + '</div></div></div>'
			+ '<div class="ibx-formrow"><label class="ibx-formrow__label" for="g-delay">' + th('InboxReadDelay') + '</label><div class="ibx-inline"><input class="ibx-field ibx-field--narrow" type="number" min="0" max="600" inputmode="numeric" id="g-delay" data-name="INBOX_DEFAULT_READ_DELAY" value="' + esc(s.INBOX_DEFAULT_READ_DELAY) + '"> ' + th('InboxSeconds') + '</div></div>'
			+ '<div class="ibx-formrow"><label class="ibx-formrow__label" for="g-send">' + th('InboxSendDelay') + '</label><div><div class="ibx-inline"><input class="ibx-field ibx-field--narrow" type="number" min="0" max="120" inputmode="numeric" id="g-send" data-name="INBOX_SEND_DELAY" value="' + esc(s.INBOX_SEND_DELAY) + '"> ' + th('InboxSeconds') + '</div><div class="ibx-formrow__help">' + th('InboxSendDelayHelp') + '</div></div></div>'
			+ '<div class="ibx-formrow"><label class="ibx-formrow__label" for="g-av">' + th('InboxAntivirusMax') + '</label><div><div class="ibx-inline"><input class="ibx-field ibx-field--narrow" type="number" min="1" max="200" inputmode="numeric" id="g-av" data-name="INBOX_ANTIVIRUS_MAX_MB" value="' + esc(s.INBOX_ANTIVIRUS_MAX_MB) + '"> ' + th('InboxMegabytes') + '</div><div class="ibx-formrow__help">' + th('InboxAntivirusMaxHelp') + '</div></div></div>'
			+ '</div></section><section class="ibx-section"><h2 class="ibx-section__title">' + th('InboxGlobalTags') + '</h2><p class="ibx-section__help">' + th('InboxGlobalTagsHelp') + '</p><div class="ibx-js-tags"></div></section></div>';
		// Saved field by field, at once (as the switches), with a toast
		$$('[data-name]', main).forEach(f => f.addEventListener('change', async () => {
			try {
				await post('general', 'set', { name: f.dataset.name, value: f.value });
				toast(t('InboxSaved'));
				if (f.dataset.name === 'INBOX_ACCENT') {
					document.documentElement.style.setProperty('--ibx-accent', f.value);
				}
			} catch (err) {
				toast(err.message, { error: true });
				f.focus();
			}
		}));
		tabTags($('.ibx-js-tags', main), null);
	}

	// ---------- My account ----------
	async function renderMe(main) {
		main.innerHTML = skeleton();
		let data;
		try {
			data = await api('userparam', 'get');
		} catch (err) {
			main.replaceChildren(errorBox(err, () => renderMe(main)));
			return;
		}
		const p = data.params;
		const opt = (name, values, current) => '<select class="ibx-field ibx-field--medium" data-param="' + name + '" id="me-' + name + '">' + values.map(v => '<option value="' + v + '"' + (v === current ? ' selected' : '') + '>' + th('Inbox_' + name + '_' + v) + '</option>').join('') + '</select>';
		let html = '<div class="ibx-page"><div class="ibx-page__head"><h1 class="ibx-page__title">' + th('InboxNavMe') + '</h1></div>'
			+ '<section class="ibx-section"><h2 class="ibx-section__title">' + th('InboxSectionReading') + '</h2><div class="ibx-form">'
			+ '<div class="ibx-formrow"><label class="ibx-formrow__label" for="me-read_mode">' + th('InboxReadMode') + '</label><div>' + opt('read_mode', ['immediate', 'delay', 'manual'], p.read_mode) + '<div class="ibx-formrow__help">' + th('InboxReadModeHelp') + '</div></div></div>'
			+ '<div class="ibx-formrow"><label class="ibx-formrow__label" for="me-read_delay">' + th('InboxReadDelay') + '</label><div class="ibx-inline"><input class="ibx-field ibx-field--narrow" type="number" min="0" max="600" inputmode="numeric" data-param="read_delay" id="me-read_delay" value="' + esc(p.read_delay) + '"> ' + th('InboxSeconds') + '</div></div>'
			+ '</div></section>';
		if (data.mailboxes.length) {
			html += '<section class="ibx-section"><h2 class="ibx-section__title">' + th('InboxReadPerMailbox') + '</h2><p class="ibx-section__help">' + th('InboxReadPerMailboxHelp') + '</p><table class="ibx-table"><tbody>'
				+ data.mailboxes.map(m => '<tr><td><b>' + esc(m.label || m.email) + '</b><div class="ibx-muted">' + esc(m.email) + '</div></td><td><select class="ibx-field ibx-field--medium" data-param="read_mode" data-account="' + m.id + '" aria-label="' + th('InboxReadMode') + '">'
					+ '<option value="">' + th('InboxSameAsAbove') + '</option>' + ['immediate', 'delay', 'manual'].map(v => '<option value="' + v + '"' + (m.params.read_mode === v && m.params.read_mode !== p.read_mode ? ' selected' : '') + '>' + th('Inbox_read_mode_' + v) + '</option>').join('') + '</select></td></tr>').join('')
				+ '</tbody></table></section>';
		}
		html += '<section class="ibx-section"><h2 class="ibx-section__title">' + th('InboxSectionDisplay') + '</h2><div class="ibx-form">'
			+ '<div class="ibx-formrow"><label class="ibx-formrow__label" for="me-view">' + th('InboxView') + '</label><div>' + opt('view', ['cards', 'table'], p.view) + '<div class="ibx-formrow__help">' + th('InboxViewHelp') + '</div></div></div>'
			+ '<div class="ibx-formrow"><label class="ibx-formrow__label" for="me-theme">' + th('InboxTheme') + '</label><div>' + opt('theme', ['auto', 'light', 'dark', 'contrast'], p.theme) + '</div></div>'
			+ '<div class="ibx-formrow"><label class="ibx-formrow__label" for="me-density">' + th('InboxDensity') + '</label><div>' + opt('density', ['compact', 'normal', 'comfortable'], p.density) + '</div></div>'
			+ '<div class="ibx-formrow"><label class="ibx-formrow__label" for="me-text_size">' + th('InboxTextSize') + '</label><div class="ibx-inline"><input type="range" min="90" max="150" step="10" data-param="text_size" id="me-text_size" value="' + esc(p.text_size) + '" aria-valuetext="' + esc(p.text_size) + ' %"><output class="ibx-js-ts">' + esc(p.text_size) + ' %</output></div></div>'
			+ '</div></section></div>';
		main.innerHTML = html;
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
					}
					toast(t('InboxSaved'));
				} catch (err) {
					toast(err.message, { error: true });
				}
			});
		});
	}
})();
