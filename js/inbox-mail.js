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
 * inbox application, space « Mail » (lot W3, analyse-lot-w3.md, interface §3 to §7):
 * - pane 1: mailboxes and folders from the index, unread counters, mailbox in error;
 * - pane 2: list of a folder as a table or as cards, sort, quick filters, search on the index, pages loaded while
 *   scrolling, refresh every 20 s without losing the selection;
 * - pane 3: reading in a sandboxed iframe (no script), remote pictures blocked, attachments bar, banners;
 * - floating preview windows for the attachments (antivirus first);
 * - one registry of actions for the buttons, the « ... » menu and the keyboard (hook inbox:mail:actions).
 * Every text of a mail is escaped here: nothing of a mail is trusted.
 */
(function () {
	'use strict';

	const I = Dolibarr.tools.inbox;
	const { esc, t, th, ic, api, post, toast, dialog, $, $$ } = I;

	// ---------- State ----------
	const params = Object.assign({}, I.ctxJson('INBOX_PARAMS_JSON', {}));
	const S = {
		el: null,
		accounts: [],
		folders: new Map(), // id => {folder, account}
		folderId: 0,
		scope: 'folder',
		q: '',
		filters: { unread: false, flagged: false, attachment: false },
		rows: [],
		next: '',
		total: 0,
		loading: false,
		pills: null,
		listSeq: 0,
		stamp: '',
		selected: new Set(),
		anchor: 0,
		openId: 0,
		mail: null,
		readTimer: 0,
		pollTimer: 0,
		collapsed: new Set(),
	};
	try {
		(JSON.parse(params.tree_collapsed || '[]') || []).forEach(k => S.collapsed.add(String(k)));
	} catch (e) {
		// default: everything open
	}

	/** Save a setting of the user (silent: a lost setting is not worth an error) */
	function saveParam(param, value) {
		params[param] = String(value);
		post('userparam', 'set', { param, value: String(value) }).catch(() => {});
	}

	// ---------- Formats ----------
	const locale = () => (I.ctx('MAIN_LANG_DEFAULT', 'en_US') || 'en_US').replace('_', '-');
	/** Date of a list: time today, « yesterday 17:02 », weekday within 7 days, else short date */
	function shortDate(ts) {
		if (!ts) {
			return '';
		}
		const d = new Date(ts * 1000);
		const now = new Date();
		const day = x => new Date(x.getFullYear(), x.getMonth(), x.getDate()).getTime();
		const diff = Math.round((day(now) - day(d)) / 86400000);
		const time = d.toLocaleTimeString(locale(), { hour: '2-digit', minute: '2-digit' });
		if (diff === 0) {
			return time;
		}
		if (diff === 1) {
			return t('InboxYesterday') + ' ' + time;
		}
		if (diff > 1 && diff < 7) {
			return d.toLocaleDateString(locale(), { weekday: 'short' }) + ' ' + time;
		}
		return d.toLocaleDateString(locale(), d.getFullYear() === now.getFullYear() ? { day: '2-digit', month: '2-digit' } : { day: '2-digit', month: '2-digit', year: '2-digit' });
	}
	function longDate(ts) {
		return ts ? new Date(ts * 1000).toLocaleString(locale(), { dateStyle: 'full', timeStyle: 'short' }) : '';
	}
	/** Period of the separators of the cards */
	function period(ts) {
		if (!ts) {
			return '';
		}
		const d = new Date(ts * 1000);
		const now = new Date();
		const day = x => new Date(x.getFullYear(), x.getMonth(), x.getDate()).getTime();
		const diff = Math.round((day(now) - day(d)) / 86400000);
		if (diff <= 0) {
			return t('InboxToday');
		}
		if (diff === 1) {
			return t('InboxYesterday');
		}
		if (diff < 7) {
			return t('InboxThisWeek');
		}
		if (d.getFullYear() === now.getFullYear()) {
			return d.toLocaleDateString(locale(), { month: 'long' });
		}
		return String(d.getFullYear());
	}
	function size(bytes) {
		if (bytes < 1024) {
			return bytes + ' o';
		}
		if (bytes < 1048576) {
			return Math.round(bytes / 1024) + ' Ko';
		}
		return (bytes / 1048576).toLocaleString(locale(), { maximumFractionDigits: 1 }) + ' Mo';
	}
	function initials(name, email) {
		const src = (name || email || '?').replace(/[<>"]/g, '').trim();
		const parts = src.split(/[\s.@_-]+/).filter(Boolean);
		return ((parts[0] || '?')[0] + (parts.length > 1 ? parts[1][0] : '')).toUpperCase();
	}
	/** Hue drawn from the address: the same correspondent keeps the same color */
	function hue(text) {
		let h = 0;
		for (const c of String(text || '')) {
			h = (h * 31 + c.charCodeAt(0)) % 360;
		}
		return h;
	}
	function avatar(name, email, cls) {
		return '<span class="ibx-avatar' + (cls ? ' ' + cls : '') + '" style="--ibx-avatar-h:' + hue(email) + '" aria-hidden="true">' + esc(initials(name, email)) + '</span>';
	}

	// ---------- Folders ----------
	const ROLE_ICON = { inbox: 'inbox', drafts: 'drafts', sent: 'sent', archive: 'archive', junk: 'junk', trash: 'trash-folder' };
	const ROLE_KEY = { inbox: 'InboxFolderRoleInbox', drafts: 'InboxFolderRoleDrafts', sent: 'InboxFolderRoleSent', archive: 'InboxFolderRoleArchive', junk: 'InboxFolderRoleJunk', trash: 'InboxFolderRoleTrash' };
	/** Name shown for a folder: the special ones in the language of the user at the first level */
	function folderName(f) {
		return f.role && ROLE_KEY[f.role] && !f.parent ? t(ROLE_KEY[f.role]) : f.name;
	}
	function accountName(a) {
		return a.label && a.label !== a.email ? a.label : a.email;
	}
	function currentFolder() {
		return S.folders.get(S.folderId) || null;
	}

	async function loadTree() {
		const res = await api('folder', 'tree');
		S.accounts = res.accounts || [];
		S.admin = !!res.admin;
		S.folders.clear();
		S.accounts.forEach(a => a.folders.forEach(f => S.folders.set(f.id, { folder: f, account: a })));
	}

	function renderTree() {
		const pane = $('.ibx-pane--folders', S.el);
		const body = $('.ibx-pane__body', pane);
		if (!S.accounts.length) {
			body.innerHTML = '<div class="ibx-empty">' + ic('mail', 'ibx-empty__icon') + '<div class="ibx-empty__title">' + th('InboxNoMailbox') + '</div><div>'
				+ th(S.admin ? 'InboxNoMailboxAdmin' : 'InboxNoMailboxText') + '</div>'
				+ (S.admin ? '<a class="ibx-btn ibx-btn--primary" href="#/settings/accounts">' + ic('plus') + ' ' + th('InboxAddAccount') + '</a>' : '') + '</div>';
			return;
		}
		const filter = ($('.ibx-js-folderfilter', pane) || {}).value || '';
		const needle = filter.trim().toLowerCase();
		const many = S.accounts.reduce((n, a) => n + a.folders.length, 0) > 15;
		const filterBox = $('.ibx-js-folderfilterbox', pane);
		filterBox.hidden = !many;
		let html = '';
		S.accounts.forEach(a => {
			const key = 'a' + a.id;
			const open = !S.collapsed.has(key) || needle !== '';
			const unread = a.folders.filter(f => f.role === 'inbox').reduce((n, f) => n + f.unread, 0);
			const inError = !!a.error;
			const progress = a.state === 'first_sync' && a.progress && a.progress.total ? Math.min(99, Math.floor(100 * a.progress.indexed / a.progress.total)) : -1;
			html += '<li role="treeitem" aria-expanded="' + open + '" aria-level="1"><div class="ibx-tree__row ibx-tree__row--account' + (inError ? ' has-error' : '') + '" style="--ibx-depth:0" data-account="' + a.id + '">'
				+ '<button type="button" class="ibx-tree__twisty" data-toggle="' + key + '" aria-label="' + th(open ? 'InboxCollapse' : 'InboxExpand') + '" tabindex="-1">' + ic(open ? 'chevron-down' : 'chevron-right') + '</button>'
				+ ic(inError ? 'warning' : 'account', 'ibx-tree__icon')
				+ '<span class="ibx-tree__label ibx-ellipsis" title="' + esc(a.email) + '">' + esc(accountName(a)) + '</span>'
				+ (!open && unread ? '<span class="ibx-count">' + unread + '</span>' : '')
				+ (inError ? '<button type="button" class="ibx-btn ibx-btn--icon ibx-btn--sm ibx-js-accounterror" data-account="' + a.id + '" title="' + th('InboxSyncState_' + a.state) + '" aria-label="' + th('InboxSyncState_' + a.state) + '">' + ic('info') + '</button>' : '')
				+ '</div>'
				+ (progress >= 0 ? '<div class="ibx-tree__note">' + th('InboxIndexingPct', progress) + '</div>' : '');
			if (open) {
				html += '<ul role="group">' + branch(a, 0, 1, needle) + '</ul>';
			}
			html += '</li>';
		});
		body.innerHTML = '<ul class="ibx-tree" role="tree" aria-label="' + th('InboxFolders') + '">' + html + '</ul>';
	}

	/** Folders under a parent (0 = first level), depth for the indent */
	function branch(a, parent, depth, needle) {
		const kids = a.folders.filter(f => f.parent === parent);
		let html = '';
		kids.forEach(f => {
			const sub = a.folders.some(x => x.parent === f.id);
			const key = 'f' + f.id;
			const matches = !needle || folderName(f).toLowerCase().includes(needle) || f.label.toLowerCase().includes(needle);
			const subHtml = sub ? branch(a, f.id, depth + 1, needle) : '';
			if (!matches && !subHtml) {
				return;
			}
			const open = sub && (!S.collapsed.has(key) || needle !== '');
			const icon = ROLE_ICON[f.role] || 'folder';
			const selected = f.id === S.folderId && S.scope === 'folder';
			html += '<li role="treeitem" aria-level="' + (depth + 1) + '"' + (sub ? ' aria-expanded="' + open + '"' : '') + '>'
				+ '<div class="ibx-tree__row' + (f.unread ? ' has-unread' : '') + (f.error ? ' has-error' : '') + (f.selectable ? '' : ' is-disabled') + '" style="--ibx-depth:' + depth + '"'
				+ ' data-folder="' + f.id + '" aria-selected="' + selected + '" tabindex="' + (selected ? 0 : -1) + '">'
				+ '<button type="button" class="ibx-tree__twisty"' + (sub ? ' data-toggle="' + key + '" aria-label="' + th(open ? 'InboxCollapse' : 'InboxExpand') + '"' : ' aria-hidden="true"') + ' tabindex="-1">' + (sub ? ic(open ? 'chevron-down' : 'chevron-right') : '') + '</button>'
				+ ic(icon, 'ibx-tree__icon')
				+ '<span class="ibx-tree__label ibx-ellipsis">' + esc(folderName(f)) + '</span>'
				+ (f.unread ? '<span class="ibx-count" aria-label="' + th('InboxSyncUnread', f.unread) + '">' + f.unread + '</span>' : '')
				+ '</div>'
				+ (open ? '<ul role="group">' + subHtml + '</ul>' : '')
				+ '</li>';
		});
		return html;
	}

	function bindTree() {
		const pane = $('.ibx-pane--folders', S.el);
		pane.addEventListener('click', e => {
			const toggle = e.target.closest('[data-toggle]');
			if (toggle) {
				e.stopPropagation();
				const key = toggle.dataset.toggle;
				if (S.collapsed.has(key)) {
					S.collapsed.delete(key);
				} else {
					S.collapsed.add(key);
				}
				saveParam('tree_collapsed', JSON.stringify(Array.from(S.collapsed)));
				renderTree();
				return;
			}
			const err = e.target.closest('.ibx-js-accounterror');
			if (err) {
				e.stopPropagation();
				accountErrorDialog(S.accounts.find(a => a.id === +err.dataset.account));
				return;
			}
			const row = e.target.closest('[data-folder]');
			if (row && !row.classList.contains('is-disabled')) {
				openFolder(+row.dataset.folder);
			} else if (e.target.closest('[data-account]')) {
				const id = +e.target.closest('[data-account]').dataset.account;
				const key = 'a' + id;
				if (S.collapsed.has(key)) {
					S.collapsed.delete(key);
					saveParam('tree_collapsed', JSON.stringify(Array.from(S.collapsed)));
					renderTree();
				}
			}
		});
		// Arrows in the tree (role tree): up / down between visible folders, left / right fold
		pane.addEventListener('keydown', e => {
			const row = e.target.closest('[data-folder]');
			if (!row) {
				return;
			}
			const rows = $$('[data-folder]', pane);
			const i = rows.indexOf(row);
			let target = null;
			if (e.key === 'ArrowDown') {
				target = rows[i + 1];
			} else if (e.key === 'ArrowUp') {
				target = rows[i - 1];
			} else if (e.key === 'Enter' || e.key === ' ') {
				openFolder(+row.dataset.folder);
				e.preventDefault();
				return;
			} else if (e.key === 'ArrowRight' || e.key === 'ArrowLeft') {
				const tw = $('[data-toggle]', row);
				if (tw && (row.parentElement.getAttribute('aria-expanded') === 'true') === (e.key === 'ArrowLeft')) {
					tw.click();
					requestAnimationFrame(() => {
						const again = $('[data-folder="' + row.dataset.folder + '"]', pane);
						if (again) {
							again.focus();
						}
					});
				}
				e.preventDefault();
				return;
			}
			if (target) {
				e.preventDefault();
				rows.forEach(r => r.setAttribute('tabindex', '-1'));
				target.setAttribute('tabindex', '0');
				target.focus();
			}
		});
		$('.ibx-js-folderfilter', pane).addEventListener('input', () => renderTree());
	}

	function accountErrorDialog(a) {
		if (!a) {
			return;
		}
		const retry = a.retry_at ? '<p class="ibx-muted">' + th('InboxSyncRetryAt', new Date(a.retry_at * 1000).toLocaleTimeString(locale())) + '</p>' : '';
		dialog({
			title: accountName(a),
			body: '<div class="ibx-notice ibx-notice--error">' + ic('warning') + '<div class="ibx-notice__text"><b>' + th('InboxSyncState_' + a.state) + '</b><br>' + esc(a.error) + '</div></div>' + retry,
			actions: [{
				label: t('InboxRetry'), primary: true, run: async () => {
					const inbox = a.folders.find(f => f.role === 'inbox') || a.folders[0];
					if (inbox) {
						try {
							await api('list', 'refresh', { folder: inbox.id, retry: 1 });
						} catch (err) {
							toast(err.message, { error: true });
						}
						await refreshTree();
					}
				},
			}],
		});
	}

	async function refreshTree() {
		try {
			await loadTree();
			renderTree();
		} catch (err) {
			// next poll
		}
	}

	// ---------- List ----------
	function listQuery(cursor) {
		const q = { folder: S.folderId, scope: S.scope, q: S.q, sort: params.list_sort || 'date:desc', cursor: cursor || '', limit: 200 };
		Object.keys(S.filters).forEach(k => {
			if (S.filters[k]) {
				q[k] = 1;
			}
		});
		return q;
	}

	/**
	 * First page of the list (the previous list stays shown until the answer, with its error if it fails). Sent in a
	 * POST body: the words searched never go in an address (logs of the web server, injection filter of Dolibarr
	 * on the quotes of a phrase)
	 */
	async function loadList(keep) {
		const seq = ++S.listSeq;
		const body = $('.ibx-pane--list .ibx-pane__body', S.el);
		if (!keep) {
			S.rows = [];
			S.next = '';
			body.innerHTML = skeletonList();
			body.setAttribute('aria-busy', 'true');
		}
		try {
			const res = await api('list', 'page', listQuery(''), { method: 'POST' });
			if (seq !== S.listSeq) {
				return;
			}
			S.pills = res.query;
			if (keep) {
				mergeRows(res.rows, res.next);
			} else {
				S.rows = res.rows;
				S.next = res.next;
			}
			S.total = res.total;
			if (res.stamp && S.scope === 'folder' && S.q === '') {
				S.stamp = res.stamp;
			}
			body.removeAttribute('aria-busy');
			renderList();
			renderListHead();
		} catch (err) {
			if (seq !== S.listSeq) {
				return;
			}
			body.removeAttribute('aria-busy');
			if (!keep) {
				body.innerHTML = '<div class="ibx-empty">' + ic('warning', 'ibx-empty__icon') + '<div>' + esc(err.message) + '</div><button type="button" class="ibx-btn ibx-js-retry">' + th('InboxRetry') + '</button></div>';
				$('.ibx-js-retry', body).addEventListener('click', () => loadList());
			}
		}
	}

	/** Next page when the end of the list comes into view */
	async function loadMore() {
		if (S.loading || !S.next) {
			return;
		}
		S.loading = true;
		const seq = S.listSeq;
		try {
			const res = await api('list', 'page', listQuery(S.next), { method: 'POST' });
			if (seq === S.listSeq) {
				const known = new Set(S.rows.map(r => r.id));
				S.rows = S.rows.concat(res.rows.filter(r => !known.has(r.id)));
				S.next = res.next;
				renderList();
			}
		} catch (err) {
			toast(err.message, { error: true });
		} finally {
			S.loading = false;
		}
	}

	/**
	 * Refresh: the first page again, merged in the list shown. Up to the last row of that page, the fresh rows win
	 * (flags changed, mails gone, new mails on top); the rows loaded beyond it are kept. The view does not jump.
	 */
	function mergeRows(fresh, next) {
		const oldIds = new Set(S.rows.map(r => r.id));
		const freshIds = new Set(fresh.map(r => r.id));
		S.newCount = fresh.filter(r => !oldIds.has(r.id)).length;
		let cut = -1;
		if (next && fresh.length) {
			// Last row of the fresh page found in the old list: what follows it is beyond the refreshed range
			for (let k = fresh.length - 1; k >= 0 && cut < 0; k--) {
				cut = S.rows.findIndex(r => r.id === fresh[k].id);
			}
		}
		if (cut < 0) {
			S.rows = fresh;
			S.next = next;
		} else {
			S.rows = fresh.concat(S.rows.slice(cut + 1).filter(r => !freshIds.has(r.id)));
		}
		if (S.openId && !S.rows.some(r => r.id === S.openId)) {
			S.openGone = true;
		}
	}

	function skeletonList() {
		let h = '<div class="ibx-maillist" aria-hidden="true">';
		for (let i = 0; i < 8; i++) {
			h += '<div class="ibx-mailcard"><span></span><span class="ibx-skeleton" style="width:' + (40 + (i * 17) % 40) + '%"></span><span></span><span class="ibx-skeleton" style="grid-column:2/4;width:' + (55 + (i * 23) % 35) + '%"></span></div>';
		}
		return h + '</div>';
	}

	function tagsHtml(row) {
		const entry = S.folders.get(row.folder);
		const defs = entry ? entry.account.tags : [];
		return (row.tags || []).map(k => {
			const d = defs.find(x => x.keyword.toLowerCase() === String(k).toLowerCase());
			if (!d) {
				return '';
			}
			return '<span class="ibx-tag" style="--ibx-tag-color:' + esc(d.color) + '">' + esc(d.label) + '</span>';
		}).join(' ');
	}

	/** Correspondent of a row: the recipient in Sent and Drafts */
	function correspondent(row) {
		const entry = S.folders.get(row.folder);
		const role = entry ? entry.folder.role : '';
		if (role === 'sent' || role === 'drafts') {
			return t('InboxToShort') + ' ' + (row.to || '');
		}
		return row.from_name || row.from_email || t('InboxNoSender');
	}

	function renderList() {
		const body = $('.ibx-pane--list .ibx-pane__body', S.el);
		const scroll = body.scrollTop;
		const anchorEl = body.querySelector('[data-mail]');
		const anchorId = S.newCount && anchorEl && scroll > 0 ? firstVisible(body) : 0;
		const anchorTop = anchorId ? body.querySelector('[data-mail="' + anchorId + '"]').getBoundingClientRect().top : 0;
		if (!S.rows.length) {
			body.innerHTML = emptyList();
			bindEmpty(body);
			return;
		}
		// Phone: cards only (a table has no room at 360 px, interface §10)
		const view = params.view === 'table' && !isPhone() ? 'table' : 'cards';
		let html = '';
		if (view === 'table') {
			html = '<table class="ibx-mailtable" role="grid" aria-label="' + th('InboxMails') + '" aria-multiselectable="true"><colgroup><col class="ibx-col--icon"><col class="ibx-col--icon"><col class="ibx-col--icon"><col class="ibx-col--from"><col><col class="ibx-col--date"></colgroup>'
				+ '<thead><tr><th><span class="ibx-sr-only">' + th('InboxSelect') + '</span></th><th>' + ic('star') + '<span class="ibx-sr-only">' + th('InboxFlagged') + '</span></th><th>' + ic('attachment') + '<span class="ibx-sr-only">' + th('InboxAttachments') + '</span></th>'
				+ sortHead('from', S.folderRole === 'sent' ? 'InboxRecipient' : 'InboxCorrespondent') + sortHead('subject', 'InboxSubject') + sortHead('date', 'InboxDate') + '</tr></thead><tbody>';
			S.rows.forEach(r => {
				const sel = S.selected.has(r.id);
				html += '<tr class="ibx-mailrow' + (r.seen ? '' : ' is-unread') + '" data-mail="' + r.id + '" aria-selected="' + sel + '" tabindex="' + (r.id === S.anchor ? 0 : -1) + '">'
					+ '<td><input type="checkbox" class="ibx-js-check" tabindex="-1"' + (sel ? ' checked' : '') + ' aria-label="' + th('InboxSelect') + '"></td>'
					+ '<td>' + (r.flagged ? '<span class="ibx-star" aria-pressed="true" title="' + th('InboxFlagged') + '">' + ic('star-on') + '</span>' : '') + '</td>'
					+ '<td>' + (r.attachments ? ic('attachment', 'ibx-faint') : '') + '</td>'
					+ '<td title="' + esc(r.from_email) + '">' + (r.seen ? '' : '<span class="ibx-sr-only">' + th('InboxUnread') + ' </span>') + esc(correspondent(r)) + '</td>'
					+ '<td>' + (r.answered ? ic('reply', 'ibx-faint') + ' ' : '') + esc(r.subject || t('InboxNoSubject')) + ' ' + tagsHtml(r) + (S.scope === 'all' ? ' <span class="ibx-muted">' + esc(boxLabel(r)) + '</span>' : '') + '</td>'
					+ '<td class="ibx-mailrow__date" title="' + esc(longDate(r.date)) + '">' + esc(shortDate(r.date)) + '</td></tr>';
			});
			html += '</tbody></table>';
		} else {
			html = '<div class="ibx-maillist" role="listbox" aria-label="' + th('InboxMails') + '" aria-multiselectable="true">';
			let last = null;
			const bydate = (params.list_sort || 'date:desc').indexOf('date') === 0;
			S.rows.forEach(r => {
				if (bydate) {
					const p = period(r.date);
					if (p !== last) {
						html += '<div class="ibx-maillist__group" role="presentation">' + esc(p) + '</div>';
						last = p;
					}
				}
				const sel = S.selected.has(r.id);
				html += '<div class="ibx-mailcard' + (r.seen ? '' : ' is-unread') + '" role="option" data-mail="' + r.id + '" aria-selected="' + sel + '" tabindex="' + (r.id === S.anchor ? 0 : -1) + '">'
					+ '<span class="ibx-mailcard__dot" aria-hidden="true"></span>'
					+ '<span class="ibx-mailcard__from ibx-ellipsis" title="' + esc(r.from_email) + '">' + (r.seen ? '' : '<span class="ibx-sr-only">' + th('InboxUnread') + ' </span>') + esc(correspondent(r)) + '</span>'
					+ '<span class="ibx-mailcard__meta">' + (r.answered ? ic('reply') : '') + '<span title="' + esc(longDate(r.date)) + '">' + esc(shortDate(r.date)) + '</span></span>'
					+ '<span class="ibx-mailcard__subject ibx-ellipsis">' + esc(r.subject || t('InboxNoSubject')) + '</span>'
					+ (r.snippet ? '<span class="ibx-mailcard__snippet ibx-ellipsis">' + esc(r.snippet) + '</span>' : '')
					+ '<span class="ibx-mailcard__foot">' + tagsHtml(r) + (r.soc ? ' <span class="ibx-muted ibx-ellipsis">' + ic('company') + ' ' + esc(r.soc.name) + '</span>' : '')
					+ (S.scope === 'all' ? ' <span class="ibx-muted">' + esc(boxLabel(r)) + '</span>' : '')
					+ '<span class="ibx-spacer"></span>' + (r.attachments ? ic('attachment', 'ibx-faint') : '') + (r.flagged ? '<span class="ibx-star" aria-pressed="true" title="' + th('InboxFlagged') + '">' + ic('star-on') + '</span>' : '') + '</span>'
					+ '</div>';
			});
			html += '</div>';
		}
		if (S.next) {
			html += '<div class="ibx-js-more ibx-listmore">' + '<span class="ibx-skeleton" style="width:60%"></span>' + '</div>';
		}
		body.innerHTML = html;
		if (anchorId && body.querySelector('[data-mail="' + anchorId + '"]')) {
			body.scrollTop += body.querySelector('[data-mail="' + anchorId + '"]').getBoundingClientRect().top - anchorTop;
		} else {
			body.scrollTop = scroll;
		}
		const more = $('.ibx-js-more', body);
		if (more) {
			const io = new IntersectionObserver(entries => {
				if (entries.some(en => en.isIntersecting)) {
					io.disconnect();
					loadMore();
				}
			}, { root: body, rootMargin: '600px' });
			io.observe(more);
		}
		const pill = $('.ibx-js-newpill', S.el);
		if (S.newCount && body.scrollTop > 40) {
			pill.hidden = false;
			pill.textContent = t('InboxNewMails', S.newCount);
		}
		S.newCount = 0;
	}

	function isPhone() {
		return window.matchMedia('(max-width: 63.9375rem)').matches;
	}

	function firstVisible(body) {
		const top = body.getBoundingClientRect().top;
		const el = Array.from(body.querySelectorAll('[data-mail]')).find(x => x.getBoundingClientRect().bottom > top);
		return el ? +el.dataset.mail : 0;
	}

	function boxLabel(r) {
		const e = S.folders.get(r.folder);
		return e ? accountName(e.account) + ' / ' + folderName(e.folder) : '';
	}

	function sortHead(key, label) {
		const [k, dir] = (params.list_sort || 'date:desc').split(':');
		const on = k === key;
		return '<th aria-sort="' + (on ? (dir === 'asc' ? 'ascending' : 'descending') : 'none') + '"><button type="button" class="ibx-sorthead" data-sort="' + key + '">' + th(label)
			+ (on ? ' ' + ic(dir === 'asc' ? 'chevron-up' : 'chevron-down') : '') + '</button></th>';
	}

	function hasFilter() {
		return S.q !== '' || Object.values(S.filters).some(Boolean);
	}

	function emptyList() {
		const f = currentFolder();
		if (hasFilter()) {
			return '<div class="ibx-empty">' + ic('search', 'ibx-empty__icon') + '<div class="ibx-empty__title">' + th('InboxNoResult') + '</div>'
				+ '<div class="ibx-inline"><button type="button" class="ibx-btn ibx-js-clear">' + th('InboxClearFilter') + '</button>'
				+ (S.scope === 'folder' ? '<button type="button" class="ibx-btn ibx-js-scope" data-scope="account">' + th('InboxSearchWholeBox') + '</button>' : '')
				+ (S.scope !== 'all' && S.accounts.length > 1 ? '<button type="button" class="ibx-btn ibx-js-scope" data-scope="all">' + th('InboxSearchAllBoxes') + '</button>' : '') + '</div></div>';
		}
		if (!f) {
			return '<div class="ibx-empty">' + ic('folder', 'ibx-empty__icon') + '<div>' + th('InboxPickFolder') + '</div></div>';
		}
		if (f.account.state === 'first_sync' && !f.folder.total) {
			return '<div class="ibx-empty">' + ic('inbox', 'ibx-empty__icon') + '<div class="ibx-empty__title">' + th('InboxSyncState_first_sync') + '</div></div>';
		}
		return '<div class="ibx-empty">' + ic(f.folder.role === 'inbox' ? 'done' : 'folder', 'ibx-empty__icon') + '<div class="ibx-empty__title">'
			+ (f.folder.role === 'inbox' ? th('InboxAllDone') : th('InboxFolderEmpty', folderName(f.folder))) + '</div></div>';
	}

	function bindEmpty(body) {
		const clear = $('.ibx-js-clear', body);
		if (clear) {
			clear.addEventListener('click', clearFilters);
		}
		$$('.ibx-js-scope', body).forEach(b => b.addEventListener('click', () => {
			S.scope = b.dataset.scope;
			loadList();
		}));
	}

	function clearFilters() {
		S.q = '';
		S.scope = 'folder';
		Object.keys(S.filters).forEach(k => {
			S.filters[k] = false;
		});
		$('.ibx-js-search', S.el).value = '';
		renderFilters();
		loadList();
	}

	function renderListHead() {
		const f = currentFolder();
		const title = $('.ibx-js-listtitle', S.el);
		let name = f ? folderName(f.folder) : '';
		if (S.scope === 'account' && f) {
			name = t('InboxWholeBox', accountName(f.account));
		} else if (S.scope === 'all') {
			name = t('InboxAllBoxes');
		}
		title.innerHTML = esc(name) + ' <span class="ibx-muted">· ' + th('InboxMailsCount', (S.total || 0).toLocaleString(locale())) + '</span>';
		$('.ibx-js-view', S.el).innerHTML = ic(params.view === 'table' ? 'view-cards' : 'view-table');
		$('.ibx-js-view', S.el).title = t(params.view === 'table' ? 'InboxViewCards' : 'InboxViewTable');
		$('.ibx-js-view', S.el).setAttribute('aria-label', $('.ibx-js-view', S.el).title);
		const qf = $('.ibx-js-quickfilter', S.el);
		qf.setAttribute('aria-pressed', params.quick_filter === '1' ? 'true' : 'false');
		$('.ibx-js-scopehint', S.el).textContent = S.scope === 'folder' && f ? t('InboxSearchIn', folderName(f.folder)) : '';
		renderPills();
	}

	/** Operators of the search shown as pills under the field */
	function renderPills() {
		const box = $('.ibx-js-pills', S.el);
		const q = S.pills || {};
		const pills = [];
		['from', 'to', 'subject', 'tag'].forEach(k => (q[k] || []).forEach(v => pills.push(t('InboxSearchOp_' + k) + ' ' + v)));
		if (q.before) {
			pills.push(t('InboxSearchOp_before') + ' ' + new Date(q.before * 1000).toLocaleDateString(locale()));
		}
		if (q.after) {
			pills.push(t('InboxSearchOp_after') + ' ' + new Date(q.after * 1000).toLocaleDateString(locale()));
		}
		if (q.attachment) {
			pills.push(t('InboxWithAttachment'));
		}
		box.innerHTML = pills.map(p => '<span class="ibx-badge">' + esc(p) + '</span>').join(' ');
		box.hidden = !pills.length || S.q === '';
		$('.ibx-searchinfo', S.el).hidden = box.hidden && $('.ibx-js-scopehint', S.el).textContent === '';
	}

	function renderFilters() {
		const bar = $('.ibx-js-filters', S.el);
		bar.hidden = params.quick_filter !== '1' && !Object.values(S.filters).some(Boolean);
		$$('[data-filter]', bar).forEach(b => b.setAttribute('aria-pressed', S.filters[b.dataset.filter] ? 'true' : 'false'));
		const active = Object.values(S.filters).some(Boolean);
		$('.ibx-js-clearfilters', bar).hidden = !active;
	}

	// ---------- Selection ----------
	function rowIndex(id) {
		return S.rows.findIndex(r => r.id === id);
	}
	function paintSelection() {
		$$('[data-mail]', S.el).forEach(el => {
			const id = +el.dataset.mail;
			const sel = S.selected.has(id);
			el.setAttribute('aria-selected', sel ? 'true' : 'false');
			el.setAttribute('tabindex', id === S.anchor ? '0' : '-1');
			const cb = $('.ibx-js-check', el);
			if (cb) {
				cb.checked = sel;
			}
		});
	}
	/** Click on a mail: alone (open it), Ctrl (add / remove), Shift (range from the anchor) */
	function select(id, mode) {
		if (mode === 'toggle') {
			if (S.selected.has(id)) {
				S.selected.delete(id);
			} else {
				S.selected.add(id);
			}
			S.anchor = id;
		} else if (mode === 'range' && S.anchor) {
			const a = rowIndex(S.anchor);
			const b = rowIndex(id);
			if (a >= 0 && b >= 0) {
				S.selected.clear();
				for (let i = Math.min(a, b); i <= Math.max(a, b); i++) {
					S.selected.add(S.rows[i].id);
				}
			}
		} else {
			S.selected.clear();
			S.selected.add(id);
			S.anchor = id;
		}
		S.allInFolder = false;
		paintSelection();
		if (S.selected.size === 1) {
			openMail(Array.from(S.selected)[0]);
		} else {
			showMulti();
		}
	}
	function moveSelection(delta) {
		if (!S.rows.length) {
			return;
		}
		let i = rowIndex(S.anchor);
		i = i < 0 ? 0 : Math.max(0, Math.min(S.rows.length - 1, i + delta));
		select(S.rows[i].id);
		const el = $('[data-mail="' + S.rows[i].id + '"]', S.el);
		if (el) {
			el.scrollIntoView({ block: 'nearest' });
			el.focus({ preventScroll: true });
		}
		if (i > S.rows.length - 20) {
			loadMore();
		}
	}
	function nextUnread() {
		const start = rowIndex(S.anchor);
		const r = S.rows.find((x, i) => i > start && !x.seen) || S.rows.find(x => !x.seen);
		if (r) {
			moveSelection(rowIndex(r.id) - rowIndex(S.anchor));
		} else {
			toast(t('InboxNoMoreUnread'));
		}
	}
	function selectAll() {
		S.rows.forEach(r => S.selected.add(r.id));
		paintSelection();
		showMulti();
	}

	// ---------- Reading pane ----------
	function readPane() {
		return $('.ibx-pane--read .ibx-pane__body', S.el);
	}

	function showNothing() {
		clearTimeout(S.readTimer);
		S.openId = 0;
		S.mail = null;
		const f = currentFolder();
		const pane = readPane();
		pane.innerHTML = '<div class="ibx-empty">' + ic('mail-opened', 'ibx-empty__icon')
			+ (f ? '<div class="ibx-empty__title">' + esc(folderName(f.folder)) + '</div><div>' + th('InboxSyncUnread', f.folder.unread) + '</div>' : '')
			+ '<div class="ibx-shortcuts"><span><kbd class="ibx-kbd">↑</kbd> <kbd class="ibx-kbd">↓</kbd> ' + th('InboxKeyNav') + '</span>'
			+ '<span><kbd class="ibx-kbd">N</kbd> ' + th('InboxKeyNextUnread') + '</span>'
			+ '<span><kbd class="ibx-kbd">?</kbd> ' + th('InboxKeyHelp') + '</span></div></div>';
	}

	function showMulti() {
		clearTimeout(S.readTimer);
		S.openId = 0;
		S.mail = null;
		const n = S.selected.size;
		readPane().innerHTML = '<div class="ibx-empty">' + ic('multi', 'ibx-empty__icon') + '<div class="ibx-empty__title">' + th('InboxSelectedCount', n) + '</div>'
			+ (S.next && n >= S.rows.length ? '<button type="button" class="ibx-btn ibx-js-allfolder">' + th('InboxSelectWholeFolder', (S.total || 0).toLocaleString(locale())) + '</button>' : '')
			+ '<div class="ibx-inline"><button type="button" class="ibx-btn ibx-js-markread" data-seen="1">' + ic('mark-read') + ' ' + th('InboxMarkRead') + '</button>'
			+ '<button type="button" class="ibx-btn ibx-js-markread" data-seen="0">' + ic('unread') + ' ' + th('InboxMarkUnread') + '</button></div></div>';
		$$('.ibx-js-markread', readPane()).forEach(b => b.addEventListener('click', () => setSeenMany(Array.from(S.selected), b.dataset.seen === '1')));
		const all = $('.ibx-js-allfolder', readPane());
		if (all) {
			all.addEventListener('click', () => {
				S.allInFolder = true;
				all.outerHTML = '<p>' + th('InboxWholeFolderSelected', (S.total || 0).toLocaleString(locale())) + '</p>';
			});
		}
	}

	async function openMail(id) {
		clearTimeout(S.readTimer);
		S.openId = id;
		S.mobile('read');
		const pane = readPane();
		const row = S.rows.find(r => r.id === id);
		// Headers of the index at once, the body after
		pane.innerHTML = '<div class="ibx-reading" aria-busy="true"><div class="ibx-readhead">' + (row ? headHtml({
			from: { name: row.from_name, email: row.from_email }, date: row.date, subject: row.subject, to: [], cc: [], tags: row.tags, folder: row.folder, account: row.account,
		}, true) : '') + '</div><div class="ibx-readbody"><span class="ibx-skeleton"></span><span class="ibx-skeleton" style="width:80%"></span><span class="ibx-skeleton" style="width:60%"></span></div></div>';
		let mail;
		try {
			mail = await api('mail', 'open', { id });
		} catch (err) {
			if (S.openId !== id) {
				return;
			}
			if (err.status === 410 || err.status === 404) {
				pane.innerHTML = '<div class="ibx-empty">' + ic('info', 'ibx-empty__icon') + '<div class="ibx-empty__title">' + esc(err.message) + '</div></div>';
				S.rows = S.rows.filter(r => r.id !== id);
				S.selected.delete(id);
				renderList();
			} else {
				pane.innerHTML = '<div class="ibx-empty">' + ic('warning', 'ibx-empty__icon') + '<div>' + esc(err.message) + '</div><button type="button" class="ibx-btn ibx-js-retry">' + th('InboxRetry') + '</button></div>';
				$('.ibx-js-retry', pane).addEventListener('click', () => openMail(id));
			}
			return;
		}
		if (S.openId !== id) {
			return;
		}
		S.mail = mail;
		renderMail(mail);
		Dolibarr.executeHook('inbox:mail:open', { account: mail.account, messageId: mail.message_id, subject: mail.subject, from: mail.from });
		autoRead(mail);
	}

	/** Mail marked read by the setting of the user and the mailbox (interface §5.3) */
	function autoRead(mail) {
		if (mail.seen || mail.read_mode === 'manual') {
			return;
		}
		const delay = mail.read_mode === 'immediate' ? 0 : Math.max(0, mail.read_delay || 0) * 1000;
		S.readTimer = setTimeout(() => {
			if (S.openId === mail.id) {
				setSeenMany([mail.id], true, true);
			}
		}, delay);
	}

	/** Read / unread, optimistic: the list and the counters change at once, back if the server refuses */
	async function setSeenMany(ids, seen, silent) {
		const rows = S.rows.filter(r => ids.includes(r.id) && !!r.seen !== seen);
		if (!rows.length && !(S.mail && ids.includes(S.mail.id) && !!S.mail.seen !== seen)) {
			return;
		}
		const apply = (list, value) => {
			list.forEach(r => {
				r.seen = value ? 1 : 0;
				const e = S.folders.get(r.folder);
				if (e) {
					e.folder.unread = Math.max(0, e.folder.unread + (value ? -1 : 1));
				}
			});
			if (S.mail && ids.includes(S.mail.id)) {
				S.mail.seen = value ? 1 : 0;
				const btn = $('.ibx-js-toggleread', S.el);
				if (btn) {
					btn.innerHTML = ic(value ? 'unread' : 'mark-read');
					btn.title = t(value ? 'InboxMarkUnread' : 'InboxMarkRead') + ' (M)';
				}
			}
			repaintRows(list);
			renderTree();
		};
		apply(rows, seen);
		const failed = [];
		for (const r of rows.length ? rows : [{ id: S.mail.id, folder: S.mail.folder }]) {
			try {
				await post('mail', 'seen', { id: r.id, seen: seen ? 1 : 0 });
			} catch (err) {
				failed.push(r);
				if (!silent || failed.length === 1) {
					toast(err.message, { error: true });
				}
			}
		}
		if (failed.length) {
			apply(failed.filter(r => r.seen !== undefined), !seen);
		}
	}

	function repaintRows(list) {
		list.forEach(r => {
			const el = $('[data-mail="' + r.id + '"]', S.el);
			if (el) {
				el.classList.toggle('is-unread', !r.seen);
			}
		});
	}

	function addressHtml(a) {
		if (!a) {
			return '';
		}
		return '<span class="ibx-addr" title="' + esc(a.email) + '">' + esc(a.name || a.email) + (a.name ? ' <span class="ibx-muted">&lt;' + esc(a.email) + '&gt;</span>' : '') + '</span>';
	}

	function headHtml(m, partial) {
		const e = S.folders.get(m.folder);
		const box = e ? e.account.email : (m.mailbox || '');
		const recipients = (m.to || []).concat(m.cc || []);
		const toLine = recipients.length ? th('InboxForShort') + ' ' + recipients.slice(0, 6).map(addressHtml).join(', ') + (recipients.length > 6 ? ' ' + th('InboxAndMore', recipients.length - 6) : '') : '';
		return '<div class="ibx-readhead__top">' + avatar(m.from.name, m.from.email)
			+ '<div class="ibx-readhead__who"><div class="ibx-readhead__name">' + esc(m.from.name || m.from.email || t('InboxNoSender'))
			+ (m.from.name ? ' <span class="ibx-readhead__email">&lt;' + esc(m.from.email) + '&gt;</span>' : '') + '</div>'
			+ '<div class="ibx-readhead__line"><span title="' + esc(longDate(m.date)) + '">' + esc(longDate(m.date)) + '</span></div></div>'
			+ (partial ? '' : '<div class="ibx-readhead__actions">' + actionButtons(m) + '</div>') + '</div>'
			+ '<div class="ibx-readhead__line ibx-readhead__to">' + toLine + (box ? (toLine ? ' · ' : '') + th('InboxReceivedOn') + ' <b>' + esc(box) + '</b>' : '') + '</div>'
			+ '<h2 class="ibx-readhead__subject">' + esc(m.subject || t('InboxNoSubject')) + ' ' + tagsHtml({ folder: m.folder, tags: m.tags }) + '</h2>';
	}

	// ---------- Registry of actions (one definition: buttons, menu, keyboard; hook inbox:mail:actions) ----------
	function actions(m) {
		const list = [
			{ id: 'read', icon: m.seen ? 'unread' : 'mark-read', label: t(m.seen ? 'InboxMarkUnread' : 'InboxMarkRead'), key: 'M', button: true, run: () => setSeenMany([m.id], !m.seen) },
			{ id: 'headers', icon: 'info', label: t('InboxAllHeaders'), run: () => headersDialog(m) },
			{ id: 'source', icon: 'source', label: t('InboxViewSource'), key: 'Ctrl+U', run: () => openViewer({ mail: m, source: true }) },
			{ id: 'eml', icon: 'download', label: t('InboxSaveEml'), run: () => download(I.ctx('INBOX_AJAX_URL', '') + 'body.php?id=' + m.id + '&part=source&download=1') },
			{ id: 'print', icon: 'print', label: t('InboxPrint'), key: 'Ctrl+P', run: () => printMail(m) },
			{ id: 'zoomin', icon: 'text-size', label: t('InboxZoomMailIn'), run: () => zoomMail(10) },
			{ id: 'zoomout', icon: 'text-size', label: t('InboxZoomMailOut'), run: () => zoomMail(-10) },
		];
		Dolibarr.executeHook('inbox:mail:actions', { mail: m, actions: list });
		return list;
	}
	function actionButtons(m) {
		return actions(m).filter(a => a.button).map(a => '<button type="button" class="ibx-btn ibx-btn--icon ibx-js-action' + (a.id === 'read' ? ' ibx-js-toggleread' : '') + '" data-action="' + esc(a.id) + '" title="' + esc(a.label + (a.key ? ' (' + a.key + ')' : '')) + '" aria-label="' + esc(a.label) + '">' + ic(a.icon) + '</button>').join('')
			+ '<button type="button" class="ibx-btn ibx-btn--icon ibx-js-more" aria-haspopup="menu" title="' + th('InboxMoreActions') + '" aria-label="' + th('InboxMoreActions') + '">' + ic('more') + '</button>';
	}

	/** Small menu under a button; closes on Escape, outside click; arrows move */
	function menu(anchor, items) {
		closeMenu();
		const box = document.createElement('div');
		box.className = 'ibx-menu';
		box.setAttribute('role', 'menu');
		box.innerHTML = items.map((it, i) => it.sep ? '<div class="ibx-menu__sep" role="separator"></div>'
			: '<button type="button" class="ibx-menu__item" role="menuitem" data-i="' + i + '"' + (it.disabled ? ' aria-disabled="true"' : '') + '>' + (it.icon ? ic(it.icon, 'ibx-menu__icon') : '<span class="ibx-menu__icon"></span>')
				+ '<span class="ibx-menu__label">' + esc(it.label) + '</span>' + (it.checked !== undefined ? (it.checked ? ic('check') : '') : '') + (it.key ? '<span class="ibx-menu__key">' + esc(it.key) + '</span>' : '') + '</button>').join('');
		document.body.appendChild(box);
		const r = anchor.getBoundingClientRect();
		const w = box.offsetWidth;
		const h = box.offsetHeight;
		box.style.position = 'fixed';
		box.style.left = Math.max(8, Math.min(r.right - w, window.innerWidth - w - 8)) + 'px';
		box.style.top = (r.bottom + h + 8 > window.innerHeight ? Math.max(8, r.top - h - 4) : r.bottom + 4) + 'px';
		box.style.zIndex = 90;
		const buttons = $$('.ibx-menu__item', box);
		box.addEventListener('click', e => {
			const b = e.target.closest('[data-i]');
			if (b && b.getAttribute('aria-disabled') !== 'true') {
				closeMenu();
				anchor.focus();
				items[+b.dataset.i].run();
			}
		});
		box.addEventListener('keydown', e => {
			const i = buttons.indexOf(document.activeElement);
			if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
				e.preventDefault();
				buttons[(i + (e.key === 'ArrowDown' ? 1 : buttons.length - 1)) % buttons.length].focus();
			} else if (e.key === 'Escape' || e.key === 'Tab') {
				e.preventDefault();
				closeMenu();
				anchor.focus();
			}
		});
		S.menu = box;
		anchor.setAttribute('aria-expanded', 'true');
		S.menuAnchor = anchor;
		if (buttons[0]) {
			buttons[0].focus();
		}
		setTimeout(() => document.addEventListener('pointerdown', outside, true), 0);
	}
	function outside(e) {
		if (S.menu && !S.menu.contains(e.target)) {
			closeMenu();
		}
	}
	function closeMenu() {
		if (S.menu) {
			S.menu.remove();
			S.menu = null;
			if (S.menuAnchor) {
				S.menuAnchor.setAttribute('aria-expanded', 'false');
			}
			document.removeEventListener('pointerdown', outside, true);
		}
	}

	// ---------- Mail ----------
	function renderMail(m) {
		const pane = readPane();
		const att = m.attachments || [];
		let banners = '';
		if (m.security && m.security.encrypted) {
			banners += banner('warning', 'lock', th('InboxEncrypted'));
		}
		if (m.report) {
			banners += banner('warning', 'warning', '<b>' + th('InboxNotDelivered', (m.report.recipients || []).join(', ')) + '</b>'
				+ (m.report.reason ? '<br>' + esc(m.report.reason) : (m.report.status ? ' (' + esc(m.report.status) + ')' : '')));
		}
		const spoofName = imitatesUs(m);
		if (spoofName) {
			banners += banner('warning', 'spoof', th('InboxNameImitation', spoofName, m.from.email));
		}
		if (m.body.remote && !m.body.images) {
			banners += '<div class="ibx-banner ibx-banner--info ibx-js-imagesbar">' + ic('remote-blocked') + '<div class="ibx-banner__text">' + th('InboxRemoteBlocked')
				+ (m.body.trackers ? ' ' + th('InboxTrackers', m.body.trackers) : '') + '</div>'
				+ '<button type="button" class="ibx-btn ibx-btn--sm ibx-js-showimages">' + th('InboxShowImages') + '</button>'
				+ (m.auth && m.auth.fail ? '' : '<button type="button" class="ibx-btn ibx-btn--sm ibx-js-allowimages" aria-haspopup="menu">' + th('InboxAlwaysShowImages') + ' ' + ic('chevron-down') + '</button>') + '</div>';
		}
		const signed = m.security && m.security.signed ? '<span class="ibx-badge" title="' + esc(m.security.signer || '') + '">' + ic(m.security.signed === 'invalid' ? 'warning' : 'check') + ' '
			+ th(m.security.signed === 'valid' ? 'InboxSigned' : (m.security.signed === 'invalid' ? 'InboxSignatureInvalid' : 'InboxSignedUnchecked')) + '</span>' : '';
		const files = att.length;
		const total = att.reduce((n, a) => n + a.size, 0);
		const open = params.attachments_open === '1';
		pane.innerHTML = '<article class="ibx-reading" aria-label="' + esc(m.subject || t('InboxNoSubject')) + '">'
			+ '<header class="ibx-readhead">' + headHtml(m) + (signed ? '<div class="ibx-readhead__line">' + signed + '</div>' : '') + '</header>'
			+ (banners ? '<div class="ibx-readbanners">' + banners + '</div>' : '')
			+ '<div class="ibx-readbody">' + (m.security && m.security.encrypted ? '' : '<iframe class="ibx-mailframe ibx-js-frame" title="' + th('InboxMailBody') + '"'
				+ ' sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox" referrerpolicy="no-referrer" loading="eager" src="' + bodyUrl(m, m.body.images) + '"></iframe>') + '</div>'
			+ (files ? '<section class="ibx-attbar" aria-label="' + th('InboxAttachments') + '"><div class="ibx-attbar__head">'
				+ '<button type="button" class="ibx-attbar__toggle ibx-js-atttoggle" aria-expanded="' + open + '">' + ic(open ? 'chevron-down' : 'chevron-right') + ic('attachment')
				+ '<b>' + th('InboxAttachmentsCount', files) + '</b> <span class="ibx-muted">' + esc(size(total)) + '</span>'
				+ '<span class="ibx-attbar__names ibx-ellipsis ibx-muted">' + esc(att.map(a => a.name).join(', ')) + '</span></button>'
				+ '<button type="button" class="ibx-btn ibx-btn--sm ibx-js-saveall">' + ic('download-all') + ' ' + th(files > 1 ? 'InboxSaveAllZip' : 'InboxSave') + '</button></div>'
				+ '<div class="ibx-attachments"' + (open ? '' : ' hidden') + '>' + att.map((a, i) => tile(a, i)).join('') + '</div></section>' : '')
			+ '</article>';
		bindMail(m);
	}

	function banner(type, icon, html) {
		return '<div class="ibx-banner ibx-banner--' + type + '" role="' + (type === 'danger' ? 'alert' : 'status') + '">' + ic(icon) + '<div class="ibx-banner__text">' + html + '</div></div>';
	}

	/** B2: a display name that names our company or one of our mailboxes, from an outside address */
	function imitatesUs(m) {
		const name = (m.from.name || '').toLowerCase();
		const email = (m.from.email || '').toLowerCase();
		if (!name || !email) {
			return '';
		}
		const domains = new Set(S.accounts.map(a => a.email.split('@')[1]));
		if (domains.has(email.split('@')[1])) {
			return '';
		}
		const company = (I.ctx('INBOX_COMPANY', '') || '').trim().toLowerCase();
		if (company.length >= 4 && name.includes(company)) {
			return m.from.name;
		}
		const box = S.accounts.find(a => name.includes(a.email.toLowerCase()));
		return box ? m.from.name : '';
	}

	function bodyUrl(m, images) {
		return I.ctx('INBOX_AJAX_URL', '') + 'body.php?id=' + m.id + (images ? '&images=1' : '');
	}

	const KIND_ICON = { pdf: 'file-pdf', image: 'file-image', zip: 'file-zip', mail: 'mail', csv: 'file-xls' };
	function tile(a, i) {
		const warn = (a.warnings || []).length;
		return '<div class="ibx-attachment' + (warn ? ' is-warning' : '') + '" data-att="' + i + '">'
			+ '<button type="button" class="ibx-attachment__open ibx-js-attopen" title="' + esc(a.name) + '">'
			+ ic(KIND_ICON[a.kind] || 'attachment', 'ibx-attachment__icon' + (a.kind === 'pdf' ? ' ibx-attachment__icon--pdf' : (a.kind === 'image' ? ' ibx-attachment__icon--img' : '')))
			+ '<span class="ibx-attachment__info"><span class="ibx-ellipsis">' + esc(a.name) + '</span><span class="ibx-attachment__size">' + esc(size(a.size))
			+ (warn ? ' · <span class="ibx-attachment__warn">' + esc(a.warnings.map(w => t(w)).join(', ')) + '</span>' : '') + '</span></span></button>'
			+ '<button type="button" class="ibx-btn ibx-btn--icon ibx-btn--sm ibx-js-attdownload" title="' + th('InboxDownload') + '" aria-label="' + th('InboxDownload') + ' ' + esc(a.name) + '">' + ic('download') + '</button></div>';
	}

	function bindMail(m) {
		const pane = readPane();
		$$('.ibx-js-action', pane).forEach(b => b.addEventListener('click', () => {
			const a = actions(S.mail).find(x => x.id === b.dataset.action);
			if (a) {
				a.run();
			}
		}));
		const more = $('.ibx-js-more', pane);
		if (more) {
			more.addEventListener('click', () => menu(more, actions(S.mail).filter(a => !a.button).map(a => ({ icon: a.icon, label: a.label, key: a.key, run: a.run }))));
		}
		const frame = $('.ibx-js-frame', pane);
		if (frame) {
			frame.addEventListener('load', () => frameReady(frame, m));
		}
		const show = $('.ibx-js-showimages', pane);
		if (show) {
			show.addEventListener('click', () => {
				frame.src = bodyUrl(m, true);
				$('.ibx-js-imagesbar', pane).remove();
			});
		}
		const allow = $('.ibx-js-allowimages', pane);
		if (allow) {
			const domain = '@' + (m.from.email.split('@')[1] || '');
			allow.addEventListener('click', () => menu(allow, [
				{ label: t('InboxAlwaysForSender', m.from.email), run: () => allowImages(m, 'sender') },
				{ label: t('InboxAlwaysForDomain', domain), run: () => allowImages(m, 'domain') },
			]));
		}
		const toggle = $('.ibx-js-atttoggle', pane);
		if (toggle) {
			toggle.addEventListener('click', () => {
				const open = toggle.getAttribute('aria-expanded') !== 'true';
				toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
				toggle.firstElementChild.outerHTML = ic(open ? 'chevron-down' : 'chevron-right');
				$('.ibx-attachments', pane).hidden = !open;
				saveParam('attachments_open', open ? '1' : '0');
			});
			$('.ibx-js-saveall', pane).addEventListener('click', () => saveAll(m));
			$('.ibx-attachments', pane).addEventListener('click', e => {
				const tileEl = e.target.closest('[data-att]');
				if (!tileEl) {
					return;
				}
				const a = m.attachments[+tileEl.dataset.att];
				if (e.target.closest('.ibx-js-attdownload')) {
					guardedDownload(m, a);
				} else {
					openViewer({ mail: m, index: +tileEl.dataset.att });
				}
			});
		}
	}

	async function allowImages(m, what) {
		try {
			await post('mail', 'images', { id: m.id, what });
			const frame = $('.ibx-js-frame', readPane());
			if (frame) {
				frame.src = bodyUrl(m, true);
			}
			const bar = $('.ibx-js-imagesbar', readPane());
			if (bar) {
				bar.remove();
			}
			toast(t('InboxImagesAllowed'));
		} catch (err) {
			toast(err.message, { error: true });
		}
	}

	/**
	 * Body loaded (same origin, no script inside): height adjusted to the content, zoom of the text, links checked
	 * (B1: the text shows a domain, the link goes elsewhere), Ctrl + wheel = zoom, the keys of inbox still work
	 */
	function frameReady(frame, m) {
		let doc;
		try {
			doc = frame.contentDocument;
		} catch (e) {
			return;
		}
		if (!doc || !doc.documentElement) {
			return;
		}
		doc.documentElement.style.zoom = (parseInt(params.mail_zoom, 10) || 100) + '%';
		const fit = () => {
			frame.style.height = Math.max(120, doc.documentElement.scrollHeight * (parseInt(params.mail_zoom, 10) || 100) / 100 + 8) + 'px';
		};
		fit();
		if (typeof ResizeObserver === 'function' && doc.body) {
			new ResizeObserver(fit).observe(doc.body);
		}
		doc.addEventListener('click', e => {
			const a = e.target.closest ? e.target.closest('a[href]') : null;
			if (!a) {
				return;
			}
			const href = a.getAttribute('href') || '';
			if (/^mailto:/i.test(href)) {
				return;
			}
			const shown = (a.textContent || '').trim();
			const real = hostOf(href);
			const claimed = hostOf(/^[a-z]+:\/\//i.test(shown) ? shown : (/^(www\.)?[a-z0-9-]+(\.[a-z0-9-]+)+(\/|$)/i.test(shown) ? 'http://' + shown : ''));
			if (real && claimed && !sameSite(real, claimed)) {
				e.preventDefault();
				dialog({
					title: t('InboxLinkWarningTitle'),
					body: '<div class="ibx-notice ibx-notice--warning">' + ic('warning') + '<div class="ibx-notice__text">' + th('InboxLinkWarning', claimed, real) + '</div></div>'
						+ '<p class="ibx-muted ibx-breakall">' + esc(href) + '</p>',
					actions: [{ label: t('InboxOpenAnyway'), run: () => window.open(href, '_blank', 'noopener,noreferrer') }],
				});
			}
		});
		doc.addEventListener('wheel', e => {
			if (e.ctrlKey) {
				e.preventDefault();
				zoomMail(e.deltaY < 0 ? 10 : -10);
			}
		}, { passive: false });
		doc.addEventListener('keydown', e => onKey(e, true));
		// Real address of a link at hover and focus (title), also read by the screen readers
		Array.from(doc.querySelectorAll('a[href]')).forEach(a => {
			if (!a.title) {
				a.title = a.getAttribute('href');
			}
		});
	}

	function hostOf(url) {
		try {
			return new URL(url).hostname.toLowerCase().replace(/^www\./, '');
		} catch (e) {
			return '';
		}
	}
	function sameSite(a, b) {
		const base = h => h.split('.').slice(-2).join('.');
		return a === b || base(a) === base(b);
	}

	function zoomMail(delta) {
		const z = Math.max(50, Math.min(200, (parseInt(params.mail_zoom, 10) || 100) + delta));
		saveParam('mail_zoom', z);
		const frame = $('.ibx-js-frame', readPane());
		if (frame && S.mail) {
			frameReady(frame, S.mail);
		}
		toast(t('InboxMailZoom', z));
	}

	function headersDialog(m) {
		const auth = m.auth || {};
		const res = v => v ? '<span class="ibx-status' + (v === 'pass' ? ' ibx-status--ok' : (v === 'fail' || v === 'softfail' ? ' ibx-status--error' : '')) + '">' + esc(v) + '</span>' : '<span class="ibx-muted">-</span>';
		dialog({
			title: t('InboxAllHeaders'),
			wide: true,
			body: '<p>SPF ' + res(auth.spf) + ' · DKIM ' + res(auth.dkim) + ' · DMARC ' + res(auth.dmarc) + '</p>'
				+ '<div class="ibx-headers"><table class="ibx-table"><tbody>' + (m.headers || []).map(h => '<tr><th>' + esc(h[0]) + '</th><td class="ibx-breakall">' + esc(h[1]) + '</td></tr>').join('') + '</tbody></table></div>',
			noCancel: true,
			actions: [{ label: t('InboxCloseWindow'), primary: true, run: () => true }],
		});
	}

	function printMail(m) {
		const frame = $('.ibx-js-frame', readPane());
		const w = window.open('', '_blank');
		if (!w) {
			toast(t('InboxPopupBlocked'), { error: true });
			return;
		}
		let body = '';
		try {
			body = frame && frame.contentDocument ? frame.contentDocument.documentElement.outerHTML : '';
		} catch (e) {
			body = '';
		}
		const head = '<div style="font:13px Arial,sans-serif;border-bottom:1px solid #999;margin:0 0 12px;padding:0 0 8px">'
			+ '<div><b>' + th('InboxFrom') + '</b> ' + esc((m.from.name ? m.from.name + ' ' : '') + '<' + m.from.email + '>') + '</div>'
			+ '<div><b>' + th('InboxTo') + '</b> ' + esc((m.to || []).map(a => a.email).join(', ')) + '</div>'
			+ '<div><b>' + th('InboxDate') + '</b> ' + esc(longDate(m.date)) + '</div>'
			+ '<div><b>' + th('InboxSubject') + '</b> ' + esc(m.subject) + '</div></div>';
		w.document.open();
		w.document.write(body.replace(/<body([^>]*)>/i, '<body$1>' + head) || head);
		w.document.close();
		w.focus();
		setTimeout(() => {
			w.print();
		}, 300);
	}

	function download(url) {
		const a = document.createElement('a');
		a.href = url;
		a.rel = 'noopener';
		a.download = '';
		document.body.appendChild(a);
		a.click();
		a.remove();
	}

	function attUrl(m, a, mode, force) {
		return I.ctx('INBOX_AJAX_URL', '') + 'attachment.php?id=' + m.id + '&part=' + a.part + '&mode=' + mode + (force ? '&force=1' : '');
	}

	/** Antivirus first: infected = never, not scanned = warning and « Download anyway » */
	async function guardedDownload(m, a) {
		let v;
		try {
			v = await api('attachment', 'check', { id: m.id, part: a.part, mode: 'check' });
		} catch (err) {
			toast(err.message, { error: true });
			return;
		}
		if (v.blocked) {
			dialog({ title: a.name, body: banner('danger', 'virus', th('InboxAttInfected', v.message)), noCancel: true, actions: [{ label: t('InboxCloseWindow'), primary: true, run: () => true }] });
			return;
		}
		if (v.warn) {
			dialog({
				title: a.name,
				body: banner('warning', 'virus-off', th(v.state === 'too_big' ? 'InboxAttTooBig' : 'InboxAttUnscanned')),
				actions: [{ label: t('InboxDownloadAnyway'), primary: true, run: () => download(attUrl(m, a, 'download', true)) }],
			});
			return;
		}
		download(attUrl(m, a, 'download', false));
	}

	async function saveAll(m) {
		const att = m.attachments || [];
		if (att.length === 1) {
			guardedDownload(m, att[0]);
			return;
		}
		let res;
		try {
			res = await api('attachment', 'check', { id: m.id, part: -1, mode: 'check' });
		} catch (err) {
			toast(err.message, { error: true });
			return;
		}
		const url = I.ctx('INBOX_AJAX_URL', '') + 'attachment.php?id=' + m.id + '&mode=zip';
		const infected = res.parts.filter(p => p.blocked).length;
		const warn = res.parts.some(p => p.warn);
		if (infected || warn) {
			dialog({
				title: t('InboxSaveAllZip'),
				body: (infected ? banner('danger', 'virus', th('InboxZipWithoutInfected', infected)) : '') + (warn ? banner('warning', 'virus-off', th('InboxAttUnscanned')) : ''),
				actions: [{ label: t(warn ? 'InboxDownloadAnyway' : 'InboxSaveAllZip'), primary: true, run: () => download(url + (warn ? '&force=1' : '')) }],
			});
			return;
		}
		download(url);
	}

	// ---------- Floating preview windows (interface §7) ----------
	const windows = [];
	function savedGeometry() {
		try {
			return JSON.parse(params.windows || '{}') || {};
		} catch (e) {
			return {};
		}
	}

	/**
	 * @param {object} o {mail, index (attachment), source (bool)}
	 */
	function openViewer(o) {
		const m = o.mail;
		const key = m.id + ':' + (o.source ? 'source' : o.index);
		const existing = windows.find(w => w.key === key);
		if (existing) {
			restore(existing);
			front(existing);
			return;
		}
		const dlg = document.createElement('dialog');
		dlg.className = 'ibx-window';
		dlg.setAttribute('aria-modal', 'false');
		const g = savedGeometry();
		const n = windows.length;
		dlg.style.width = (g.w || 720) + 'px';
		dlg.style.height = (g.h || 560) + 'px';
		dlg.style.left = Math.min(window.innerWidth - 300, (g.x || Math.round(window.innerWidth / 2 - (g.w || 720) / 2)) + n * 24) + 'px';
		dlg.style.top = Math.min(window.innerHeight - 200, (g.y || 60) + n * 24) + 'px';
		const w = { key, dlg, mail: m, index: o.index, source: !!o.source };
		windows.push(w);
		document.body.appendChild(dlg);
		dlg.show();
		fillViewer(w);
		front(w);
		bindWindow(w);
	}

	function viewerTitle(w) {
		if (w.source) {
			return [t('InboxViewSource'), w.mail.subject || ''];
		}
		const a = w.mail.attachments[w.index];
		return [a.name, size(a.size) + ' · ' + (w.mail.subject || '')];
	}

	async function fillViewer(w) {
		const m = w.mail;
		const list = m.attachments || [];
		const [title, sub] = viewerTitle(w);
		w.dlg.setAttribute('aria-label', title);
		w.dlg.innerHTML = '<div class="ibx-window__bar"><span class="ibx-window__title ibx-ellipsis">' + esc(title) + ' <span class="ibx-window__sub">' + esc(sub) + '</span></span>'
			+ (!w.source && list.length > 1 ? '<button type="button" class="ibx-btn ibx-btn--icon ibx-btn--sm ibx-js-wprev" aria-label="' + th('InboxPrevious') + '">' + ic('chevron-left') + '</button><span class="ibx-muted">' + (w.index + 1) + '/' + list.length + '</span>'
				+ '<button type="button" class="ibx-btn ibx-btn--icon ibx-btn--sm ibx-js-wnext" aria-label="' + th('InboxNext') + '">' + ic('chevron-right') + '</button>' : '')
			+ (!w.source ? '<button type="button" class="ibx-btn ibx-btn--icon ibx-btn--sm ibx-js-wdownload" title="' + th('InboxDownload') + '" aria-label="' + th('InboxDownload') + '">' + ic('download') + '</button>' : '')
			+ '<button type="button" class="ibx-btn ibx-btn--icon ibx-btn--sm ibx-js-wmin" title="' + th('InboxMinimize') + '" aria-label="' + th('InboxMinimize') + '">' + ic('minimize') + '</button>'
			+ '<button type="button" class="ibx-btn ibx-btn--icon ibx-btn--sm ibx-js-wmax" title="' + th('InboxMaximize') + '" aria-label="' + th('InboxMaximize') + '">' + ic('maximize') + '</button>'
			+ '<button type="button" class="ibx-btn ibx-btn--icon ibx-btn--sm ibx-js-wclose" title="' + th('InboxCloseWindow') + '" aria-label="' + th('InboxCloseWindow') + '">' + ic('close') + '</button></div>'
			+ '<div class="ibx-window__body ibx-viewer" aria-busy="true"><div class="ibx-empty">' + th('InboxScanning') + '</div></div>';
		const body = $('.ibx-window__body', w.dlg);
		if (w.source) {
			try {
				const res = await fetch(I.ctx('INBOX_AJAX_URL', '') + 'body.php?id=' + m.id + '&part=source', { credentials: 'same-origin' });
				if (!res.ok) {
					throw new Error(res.statusText);
				}
				body.innerHTML = '<pre class="ibx-viewer__text">' + esc(await res.text()) + '</pre>';
			} catch (err) {
				body.innerHTML = '<div class="ibx-empty">' + esc(err.message) + '</div>';
			}
			body.removeAttribute('aria-busy');
			return;
		}
		const a = list[w.index];
		let v;
		try {
			v = await api('attachment', 'check', { id: m.id, part: a.part, mode: 'check' });
		} catch (err) {
			body.innerHTML = '<div class="ibx-empty">' + ic('warning', 'ibx-empty__icon') + '<div>' + th('InboxCannotOpen') + '</div><div class="ibx-muted">' + esc(err.message) + '</div></div>';
			body.removeAttribute('aria-busy');
			return;
		}
		body.removeAttribute('aria-busy');
		if (v.blocked) {
			body.innerHTML = '<div class="ibx-viewer__pad">' + banner('danger', 'virus', th('InboxAttInfected', v.message)) + '</div>';
			const dl = $('.ibx-js-wdownload', w.dlg);
			if (dl) {
				dl.remove();
			}
			return;
		}
		const warnings = (v.warnings || []).map(k => banner('warning', 'warning', th(k))).join('');
		if (v.warn && !w.forced) {
			body.innerHTML = '<div class="ibx-viewer__pad">' + banner('warning', 'virus-off', th(v.state === 'too_big' ? 'InboxAttTooBig' : 'InboxAttUnscanned')) + warnings
				+ '<p><button type="button" class="ibx-btn ibx-js-wforce">' + th('InboxOpenAnyway') + '</button></p></div>';
			$('.ibx-js-wforce', body).addEventListener('click', () => {
				w.forced = true;
				showContent(w, a, body, '');
			});
			return;
		}
		showContent(w, a, body, warnings);
	}

	async function showContent(w, a, body, warnings) {
		const m = w.mail;
		const force = !!w.forced;
		const top = warnings ? '<div class="ibx-viewer__pad">' + warnings + '</div>' : '';
		if (a.kind === 'pdf') {
			body.innerHTML = top + '<iframe class="ibx-viewer__frame" title="' + esc(a.name) + '" src="' + attUrl(m, a, 'inline', force) + '"></iframe>';
		} else if (a.kind === 'image') {
			body.innerHTML = top + '<div class="ibx-viewer__canvas"><img class="ibx-viewer__img" alt="' + esc(a.name) + '" src="' + attUrl(m, a, 'inline', force) + '"></div>';
			const img = $('img', body);
			let scale = 0;
			img.addEventListener('dblclick', () => {
				scale = scale ? 0 : 1;
				img.classList.toggle('is-actual', scale === 1);
				img.style.transform = '';
			});
			$('.ibx-viewer__canvas', body).addEventListener('wheel', e => {
				if (!e.ctrlKey && !e.metaKey) {
					return;
				}
				e.preventDefault();
				scale = Math.max(0.2, Math.min(8, (scale || 1) * (e.deltaY < 0 ? 1.15 : 1 / 1.15)));
				img.classList.add('is-actual');
				img.style.transform = 'scale(' + scale + ')';
			}, { passive: false });
		} else if (a.kind === 'mail') {
			body.innerHTML = top + '<iframe class="ibx-viewer__frame ibx-viewer__frame--mail" title="' + esc(a.name) + '" sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox" referrerpolicy="no-referrer" src="'
				+ I.ctx('INBOX_AJAX_URL', '') + 'body.php?id=' + m.id + '&part=' + a.part + '"></iframe>';
		} else if (a.kind === 'text' || a.kind === 'csv') {
			try {
				const res = await api('attachment', 'text', { id: m.id, part: a.part, mode: 'text', force: force ? 1 : 0 });
				body.innerHTML = top + (a.kind === 'csv' ? csvTable(res.text) : '<pre class="ibx-viewer__text">' + esc(res.text) + '</pre>') + (res.truncated ? '<p class="ibx-muted ibx-viewer__pad">' + th('InboxTruncated') + '</p>' : '');
			} catch (err) {
				body.innerHTML = '<div class="ibx-empty">' + esc(err.message) + '</div>';
			}
		} else if (a.kind === 'zip') {
			try {
				const res = await api('attachment', 'list', { id: m.id, part: a.part, mode: 'list', force: force ? 1 : 0 });
				body.innerHTML = top + '<div class="ibx-viewer__pad"><p class="ibx-muted">' + th('InboxZipContent', res.files.length) + '</p><table class="ibx-table"><tbody>'
					+ res.files.map(f => '<tr><td class="ibx-breakall">' + esc(f.name) + '</td><td class="ibx-muted">' + esc(size(f.size)) + '</td></tr>').join('') + '</tbody></table></div>';
			} catch (err) {
				body.innerHTML = '<div class="ibx-empty">' + esc(err.message) + '</div>';
			}
		} else {
			body.innerHTML = top + '<div class="ibx-empty">' + ic(KIND_ICON[a.kind] || 'attachment', 'ibx-empty__icon') + '<div class="ibx-empty__title ibx-breakall">' + esc(a.name) + '</div><div>' + esc(size(a.size)) + ' · ' + th('InboxNoPreview') + '</div>'
				+ '<button type="button" class="ibx-btn ibx-btn--primary ibx-js-wdl2">' + ic('download') + ' ' + th('InboxDownload') + '</button></div>';
			$('.ibx-js-wdl2', body).addEventListener('click', () => download(attUrl(m, a, 'download', force)));
		}
	}

	/** CSV in a table, 500 lines (separator guessed: ; , or tab) */
	function csvTable(text) {
		const first = text.split(/\r?\n/, 1)[0] || '';
		const sep = [';', '\t', ','].sort((x, y) => first.split(y).length - first.split(x).length)[0];
		const rows = [];
		let row = [];
		let cell = '';
		let quoted = false;
		for (let i = 0; i < text.length && rows.length < 500; i++) {
			const c = text[i];
			if (quoted) {
				if (c === '"' && text[i + 1] === '"') {
					cell += '"';
					i++;
				} else if (c === '"') {
					quoted = false;
				} else {
					cell += c;
				}
			} else if (c === '"') {
				quoted = true;
			} else if (c === sep) {
				row.push(cell);
				cell = '';
			} else if (c === '\n' || c === '\r') {
				if (c === '\r' && text[i + 1] === '\n') {
					i++;
				}
				row.push(cell);
				rows.push(row);
				row = [];
				cell = '';
			} else {
				cell += c;
			}
		}
		if (cell !== '' || row.length) {
			row.push(cell);
			rows.push(row);
		}
		return '<div class="ibx-viewer__pad"><table class="ibx-table ibx-table--csv"><tbody>' + rows.map(r => '<tr>' + r.map(c => '<td>' + esc(c) + '</td>').join('') + '</tr>').join('') + '</tbody></table></div>';
	}

	function front(w) {
		windows.forEach(x => x.dlg.classList.toggle('is-front', x === w));
	}
	function closeWindow(w) {
		w.dlg.remove();
		if (w.dock) {
			w.dock.remove();
		}
		windows.splice(windows.indexOf(w), 1);
		const tileEl = !w.source && S.mail && S.mail.id === w.mail.id ? $('[data-att="' + w.index + '"] .ibx-js-attopen', readPane()) : null;
		if (tileEl) {
			tileEl.focus();
		}
	}
	function dockBox() {
		let d = $('.ibx-dock');
		if (!d) {
			d = document.createElement('div');
			d.className = 'ibx-dock';
			document.body.appendChild(d);
		}
		return d;
	}
	function minimize(w) {
		w.dlg.close();
		w.dock = document.createElement('button');
		w.dock.type = 'button';
		w.dock.className = 'ibx-dock__item';
		w.dock.innerHTML = ic(w.source ? 'source' : (KIND_ICON[(w.mail.attachments[w.index] || {}).kind] || 'attachment')) + '<span class="ibx-dock__label ibx-ellipsis">' + esc(viewerTitle(w)[0]) + '</span>';
		w.dock.addEventListener('click', () => restore(w));
		dockBox().appendChild(w.dock);
	}
	function restore(w) {
		if (w.dock) {
			w.dock.remove();
			w.dock = null;
		}
		if (!w.dlg.open) {
			w.dlg.show();
		}
		front(w);
	}
	function saveGeometry(w) {
		if (w.dlg.classList.contains('ibx-window--maximized')) {
			return;
		}
		const r = w.dlg.getBoundingClientRect();
		saveParam('windows', JSON.stringify({ x: Math.round(r.left), y: Math.round(r.top), w: Math.round(r.width), h: Math.round(r.height) }));
	}
	function bindWindow(w) {
		const dlg = w.dlg;
		dlg.addEventListener('pointerdown', () => front(w));
		dlg.addEventListener('click', e => {
			const b = e.target.closest('button');
			if (!b) {
				return;
			}
			if (b.classList.contains('ibx-js-wclose')) {
				closeWindow(w);
			} else if (b.classList.contains('ibx-js-wmin')) {
				minimize(w);
			} else if (b.classList.contains('ibx-js-wmax')) {
				dlg.classList.toggle('ibx-window--maximized');
			} else if (b.classList.contains('ibx-js-wdownload')) {
				guardedDownload(w.mail, w.mail.attachments[w.index]);
			} else if (b.classList.contains('ibx-js-wprev') || b.classList.contains('ibx-js-wnext')) {
				const n = w.mail.attachments.length;
				w.index = (w.index + (b.classList.contains('ibx-js-wnext') ? 1 : n - 1)) % n;
				w.key = w.mail.id + ':' + w.index;
				w.forced = false;
				fillViewer(w);
			}
		});
		dlg.addEventListener('keydown', e => {
			if (e.key === 'Escape') {
				e.preventDefault();
				e.stopPropagation();
				closeWindow(w);
			}
		});
		// Move by the title bar
		dlg.addEventListener('pointerdown', e => {
			const bar = e.target.closest('.ibx-window__bar');
			if (!bar || e.target.closest('button') || dlg.classList.contains('ibx-window--maximized')) {
				return;
			}
			const r = dlg.getBoundingClientRect();
			const dx = e.clientX - r.left;
			const dy = e.clientY - r.top;
			bar.setPointerCapture(e.pointerId);
			const move = ev => {
				dlg.style.left = Math.max(0, Math.min(window.innerWidth - 80, ev.clientX - dx)) + 'px';
				dlg.style.top = Math.max(0, Math.min(window.innerHeight - 36, ev.clientY - dy)) + 'px';
			};
			const up = () => {
				bar.removeEventListener('pointermove', move);
				bar.removeEventListener('pointerup', up);
				saveGeometry(w);
			};
			bar.addEventListener('pointermove', move);
			bar.addEventListener('pointerup', up);
		});
		dlg.addEventListener('dblclick', e => {
			if (e.target.closest('.ibx-window__bar') && !e.target.closest('button')) {
				dlg.classList.toggle('ibx-window--maximized');
			}
		});
		// Size changed by the corner (CSS resize): kept
		if (typeof ResizeObserver === 'function') {
			let timer = 0;
			new ResizeObserver(() => {
				clearTimeout(timer);
				timer = setTimeout(() => saveGeometry(w), 400);
			}).observe(dlg);
		}
	}

	// ---------- Folder opening, refresh ----------
	async function openFolder(id, keepRoute) {
		const entry = S.folders.get(id);
		if (!entry) {
			return;
		}
		S.folderId = id;
		S.folderRole = entry.folder.role;
		S.scope = 'folder';
		S.selected.clear();
		S.anchor = 0;
		S.stamp = '';
		$$('[data-folder]', S.el).forEach(r => {
			const on = +r.dataset.folder === id;
			r.setAttribute('aria-selected', on ? 'true' : 'false');
			r.setAttribute('tabindex', on ? '0' : '-1');
		});
		if (!keepRoute) {
			history.replaceState(null, '', '#/mail/' + id);
		}
		S.mobile('list');
		showNothing();
		renderListHead();
		await loadList();
		Dolibarr.executeHook('inbox:folder:open', { account: entry.account.id, folder: id });
		// Synchronised now in the background (unless done less than 15 s ago), then merged
		api('list', 'refresh', { folder: id }).then(r => {
			if (r.done && r.stats && (r.stats.new || r.stats.changed || r.stats.deleted) && S.folderId === id) {
				loadList(true);
				refreshTree();
			}
		}).catch(() => {});
	}

	async function poll() {
		clearTimeout(S.pollTimer);
		if (document.hidden || !S.el || S.el.closest('[hidden]')) {
			S.pollTimer = setTimeout(poll, 20000);
			return;
		}
		try {
			// The open folder is synchronised (skipped by the server if done less than 15 s ago, a STATUS only when
			// nothing changed), then the state tells the list whether to merge the changes
			if (S.folderId) {
				await api('list', 'refresh', { folder: S.folderId }).catch(() => {});
			}
			const st = await api('list', 'state', { folder: S.folderId || 0 });
			let treeChanged = false;
			(st.folders || []).forEach(([id, total, unread]) => {
				const e = S.folders.get(id);
				if (e && (e.folder.total !== total || e.folder.unread !== unread)) {
					e.folder.total = total;
					e.folder.unread = unread;
					treeChanged = true;
				}
			});
			(st.accounts || []).forEach(([id, state]) => {
				const a = S.accounts.find(x => x.id === id);
				if (a && a.state !== state) {
					treeChanged = true;
					refreshTree();
				}
			});
			if (treeChanged) {
				renderTree();
			}
			if (st.open && st.open.folder === S.folderId) {
				if (st.open.stamp !== S.stamp && S.scope === 'folder' && S.q === '') {
					await loadList(true);
				}
				S.stamp = st.open.stamp;
			}
		} catch (err) {
			// Network lost: next try
		}
		S.pollTimer = setTimeout(poll, 20000);
	}

	// ---------- Keyboard (interface §11, read only keys of lot W3) ----------
	function inField(e) {
		const el = e.target;
		return el && (el.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(el.tagName)) && !(el.classList && el.classList.contains('ibx-js-check'));
	}
	function onKey(e, fromFrame) {
		if (!S.el || S.el.closest('[hidden]') || e.defaultPrevented) {
			return;
		}
		if (e.ctrlKey && e.shiftKey && (e.key === 'K' || e.key === 'k')) {
			e.preventDefault();
			$('.ibx-js-search', S.el).focus();
			return;
		}
		if ((e.ctrlKey || e.metaKey) && !e.shiftKey && ['+', '=', '-', '0'].includes(e.key)) {
			e.preventDefault();
			const cur = parseInt(params.text_size, 10) || 100;
			const steps = [90, 100, 115, 130, 150];
			let i = steps.indexOf(cur);
			i = i < 0 ? 1 : i;
			const next = e.key === '0' ? 100 : steps[Math.max(0, Math.min(steps.length - 1, i + (e.key === '-' ? -1 : 1)))];
			saveParam('text_size', next);
			I.applyDisplay(params);
			toast(t('InboxTextSizeNow', next));
			return;
		}
		if ((e.ctrlKey || e.metaKey) && (e.key === 'u' || e.key === 'U') && S.mail) {
			e.preventDefault();
			openViewer({ mail: S.mail, source: true });
			return;
		}
		if ((e.ctrlKey || e.metaKey) && (e.key === 'a' || e.key === 'A') && !inField(e) && !fromFrame) {
			e.preventDefault();
			selectAll();
			return;
		}
		if (e.ctrlKey || e.metaKey || e.altKey || inField(e)) {
			return;
		}
		const key = e.key;
		if (key === 'F9') {
			e.preventDefault();
			toggleFolders();
		} else if (key === 'ArrowDown' || key === 'ArrowUp') {
			if (e.target.closest && e.target.closest('.ibx-pane--folders, .ibx-menu, dialog')) {
				return;
			}
			e.preventDefault();
			moveSelection(key === 'ArrowDown' ? 1 : -1);
		} else if (key === 'n' || key === 'N') {
			e.preventDefault();
			nextUnread();
		} else if (key === 'm' || key === 'M') {
			e.preventDefault();
			const ids = S.selected.size ? Array.from(S.selected) : (S.mail ? [S.mail.id] : []);
			if (ids.length) {
				const anyUnread = S.rows.some(r => ids.includes(r.id) && !r.seen) || (S.mail && ids.includes(S.mail.id) && !S.mail.seen);
				setSeenMany(ids, anyUnread);
			}
		} else if (key === 'PageDown' || key === 'PageUp') {
			e.preventDefault();
			const pane = $('.ibx-pane--read .ibx-pane__body', S.el);
			pane.scrollBy({ top: (key === 'PageDown' ? 1 : -1) * pane.clientHeight * 0.85 });
		} else if (key === '?') {
			e.preventDefault();
			keysHelp();
		} else if (key === 'Escape') {
			if (windows.length && windows.some(w => w.dlg.open)) {
				return;
			}
			if (S.selected.size > 1) {
				const keep = S.anchor;
				S.selected.clear();
				if (keep) {
					S.selected.add(keep);
				}
				paintSelection();
			}
		}
	}

	function keysHelp() {
		const rows = [
			['↑ ↓', 'InboxKeyNav'], ['N', 'InboxKeyNextUnread'], ['M', 'InboxKeyRead'], ['Ctrl+Maj+K', 'InboxKeySearch'], ['F9', 'InboxKeyFolders'],
			['Pg. suiv. / Pg. préc.', 'InboxKeyScroll'], ['Ctrl + / - / 0', 'InboxKeyTextSize'], ['Ctrl+U', 'InboxViewSource'], ['Ctrl+A', 'InboxKeySelectAll'], ['?', 'InboxKeyHelp'], ['Échap', 'InboxKeyEscape'],
		];
		dialog({
			title: t('InboxKeyHelpTitle'),
			body: '<table class="ibx-table"><tbody>' + rows.map(r => '<tr><td><kbd class="ibx-kbd">' + esc(r[0]) + '</kbd></td><td>' + th(r[1]) + '</td></tr>').join('') + '</tbody></table>',
			noCancel: true,
			actions: [{ label: t('InboxCloseWindow'), primary: true, run: () => true }],
		});
	}

	function toggleFolders() {
		const hidden = params.folders_hidden === '1' ? '0' : '1';
		saveParam('folders_hidden', hidden);
		applyFolders();
	}
	function applyFolders() {
		const hidden = params.folders_hidden === '1';
		S.el.querySelector('.ibx-mailapp').dataset.folders = hidden ? 'collapsed' : 'open';
		const b = $('.ibx-js-togglefolders', S.el);
		if (b) {
			b.setAttribute('aria-pressed', hidden ? 'true' : 'false');
			b.title = t(hidden ? 'InboxShowFolders' : 'InboxHideFolders') + ' (F9)';
			b.setAttribute('aria-label', b.title);
			b.innerHTML = ic(hidden ? 'folders-show' : 'folders-hide');
		}
	}

	// ---------- Splitters (widths kept per user, double click = default) ----------
	function applyWidths() {
		let w = {};
		try {
			w = JSON.parse(params.pane_widths || '{}') || {};
		} catch (e) {
			w = {};
		}
		const app = $('.ibx-mailapp', S.el);
		app.style.setProperty('--ibx-w1', w.w1 ? w.w1 + 'px' : '');
		app.style.setProperty('--ibx-w2', w.w2 ? w.w2 + 'px' : '');
		if (!w.w1) {
			app.style.removeProperty('--ibx-w1');
		}
		if (!w.w2) {
			app.style.removeProperty('--ibx-w2');
		}
	}
	function bindSplitters() {
		$$('.ibx-splitter', S.el).forEach(sp => {
			const which = sp.dataset.split === '1' ? 'w1' : 'w2';
			const pane = which === 'w1' ? $('.ibx-pane--folders', S.el) : $('.ibx-pane--list', S.el);
			const min = which === 'w1' ? 180 : 320;
			sp.addEventListener('pointerdown', e => {
				e.preventDefault();
				sp.setPointerCapture(e.pointerId);
				sp.classList.add('is-dragging');
				const start = e.clientX;
				const base = pane.getBoundingClientRect().width;
				const app = $('.ibx-mailapp', S.el);
				const move = ev => {
					app.style.setProperty('--ibx-' + which, Math.max(min, Math.min(window.innerWidth * 0.6, base + ev.clientX - start)) + 'px');
				};
				const up = () => {
					sp.classList.remove('is-dragging');
					sp.removeEventListener('pointermove', move);
					sp.removeEventListener('pointerup', up);
					let w = {};
					try {
						w = JSON.parse(params.pane_widths || '{}') || {};
					} catch (er) {
						w = {};
					}
					w[which] = Math.round(pane.getBoundingClientRect().width);
					saveParam('pane_widths', JSON.stringify(w));
				};
				sp.addEventListener('pointermove', move);
				sp.addEventListener('pointerup', up);
			});
			sp.addEventListener('dblclick', () => {
				let w = {};
				try {
					w = JSON.parse(params.pane_widths || '{}') || {};
				} catch (er) {
					w = {};
				}
				delete w[which];
				saveParam('pane_widths', JSON.stringify(w));
				applyWidths();
			});
			// Keyboard: arrows move the splitter (role separator)
			sp.addEventListener('keydown', e => {
				if (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight') {
					return;
				}
				e.preventDefault();
				const app = $('.ibx-mailapp', S.el);
				const cur = pane.getBoundingClientRect().width;
				app.style.setProperty('--ibx-' + which, Math.max(min, cur + (e.key === 'ArrowRight' ? 24 : -24)) + 'px');
			});
		});
	}

	// ---------- Layout and events of the space ----------
	function layout() {
		return '<div class="ibx-mailapp" data-folders="open" data-mobile="list">'
			+ '<section class="ibx-pane ibx-pane--folders" aria-label="' + th('InboxFolders') + '">'
			+ '<div class="ibx-pane__head"><span class="ibx-pane__title">' + th('InboxSpaceMail') + '</span></div>'
			+ '<div class="ibx-toolbar ibx-js-folderfilterbox" hidden><label class="ibx-search">' + ic('filter') + '<input type="search" class="ibx-field ibx-js-folderfilter" placeholder="' + th('InboxFilterFolders') + '" aria-label="' + th('InboxFilterFolders') + '" autocomplete="off"></label></div>'
			+ '<div class="ibx-pane__body"><ul class="ibx-tree" aria-busy="true"><li><span class="ibx-skeleton"></span></li><li><span class="ibx-skeleton" style="width:70%"></span></li><li><span class="ibx-skeleton" style="width:50%"></span></li></ul></div></section>'
			+ '<div class="ibx-splitter" data-split="1" role="separator" aria-orientation="vertical" tabindex="0" aria-label="' + th('InboxResizePane') + '"></div>'
			+ '<section class="ibx-pane ibx-pane--list" aria-label="' + th('InboxMails') + '">'
			+ '<div class="ibx-pane__head">'
			+ '<button type="button" class="ibx-btn ibx-btn--icon ibx-js-back ibx-mobileonly" data-to="folders" aria-label="' + th('InboxFolders') + '">' + ic('chevron-left') + '</button>'
			+ '<button type="button" class="ibx-btn ibx-btn--icon ibx-btn--toggle ibx-js-togglefolders ibx-desktoponly" aria-pressed="false">' + ic('folders-hide') + '</button>'
			+ '<span class="ibx-pane__title ibx-ellipsis ibx-js-listtitle" role="heading" aria-level="1"></span><span class="ibx-spacer"></span>'
			+ '<button type="button" class="ibx-btn ibx-btn--icon ibx-btn--toggle ibx-js-quickfilter" aria-pressed="false" title="' + th('InboxQuickFilter') + '" aria-label="' + th('InboxQuickFilter') + '">' + ic('quickfilter') + '</button>'
			+ '<button type="button" class="ibx-btn ibx-btn--icon ibx-js-view ibx-desktoponly-phone"></button>'
			+ '<button type="button" class="ibx-btn ibx-btn--icon ibx-js-listmenu" aria-haspopup="menu" title="' + th('InboxListOptions') + '" aria-label="' + th('InboxListOptions') + '">' + ic('more') + '</button></div>'
			+ '<div class="ibx-toolbar"><label class="ibx-search">' + ic('search') + '<input type="search" class="ibx-field ibx-js-search" enterkeyhint="search" autocomplete="off" spellcheck="false"'
			+ ' placeholder="' + th('InboxSearchPlaceholder') + '" aria-label="' + th('InboxSearchPlaceholder') + '" aria-describedby="ibx-scopehint"><kbd class="ibx-kbd">Ctrl+Maj+K</kbd></label></div>'
			+ '<div class="ibx-searchinfo"><span id="ibx-scopehint" class="ibx-muted ibx-js-scopehint"></span> <span class="ibx-js-pills" hidden></span></div>'
			+ '<div class="ibx-filters ibx-js-filters" hidden role="group" aria-label="' + th('InboxQuickFilter') + '">'
			+ '<button type="button" class="ibx-btn ibx-btn--sm ibx-btn--toggle" data-filter="unread" aria-pressed="false">' + ic('unread') + ' ' + th('InboxFilterUnread') + '</button>'
			+ '<button type="button" class="ibx-btn ibx-btn--sm ibx-btn--toggle" data-filter="flagged" aria-pressed="false">' + ic('star') + ' ' + th('InboxFilterFlagged') + '</button>'
			+ '<button type="button" class="ibx-btn ibx-btn--sm ibx-btn--toggle" data-filter="attachment" aria-pressed="false">' + ic('attachment') + ' ' + th('InboxFilterAttachment') + '</button>'
			+ '<button type="button" class="ibx-btn ibx-btn--sm ibx-btn--ghost ibx-js-clearfilters" hidden>' + th('InboxClearFilter') + '</button></div>'
			+ '<button type="button" class="ibx-newpill ibx-js-newpill" hidden></button>'
			+ '<div class="ibx-pane__body" tabindex="-1"></div></section>'
			+ '<div class="ibx-splitter" data-split="2" role="separator" aria-orientation="vertical" tabindex="0" aria-label="' + th('InboxResizePane') + '"></div>'
			+ '<section class="ibx-pane ibx-pane--read" aria-label="' + th('InboxReadingPane') + '">'
			+ '<div class="ibx-pane__head ibx-mobileonly"><button type="button" class="ibx-btn ibx-btn--icon ibx-js-back" data-to="list" aria-label="' + th('InboxBackToList') + '">' + ic('chevron-left') + '</button></div>'
			+ '<div class="ibx-pane__body" tabindex="-1"></div></section>'
			+ '</div>';
	}

	function bindLayout() {
		const el = S.el;
		bindTree();
		bindSplitters();
		S.mobile = to => {
			$('.ibx-mailapp', el).dataset.mobile = to;
		};
		$$('.ibx-js-back', el).forEach(b => b.addEventListener('click', () => S.mobile(b.dataset.to)));
		$('.ibx-js-togglefolders', el).addEventListener('click', toggleFolders);
		$('.ibx-js-view', el).addEventListener('click', () => {
			saveParam('view', params.view === 'table' ? 'cards' : 'table');
			renderList();
			renderListHead();
		});
		$('.ibx-js-quickfilter', el).addEventListener('click', () => {
			saveParam('quick_filter', params.quick_filter === '1' ? '0' : '1');
			renderFilters();
			renderListHead();
		});
		$('.ibx-js-listmenu', el).addEventListener('click', e => {
			const cur = params.list_sort || 'date:desc';
			const opt = (v, k) => ({ label: t(k), checked: cur === v, run: () => setSort(v) });
			menu(e.currentTarget, [
				opt('date:desc', 'InboxSortDateDesc'), opt('date:asc', 'InboxSortDateAsc'), opt('from:asc', 'InboxSortFrom'), opt('subject:asc', 'InboxSortSubject'), opt('size:desc', 'InboxSortSize'),
				{ sep: true },
				{ label: t('InboxViewTable'), checked: params.view === 'table', run: () => setView('table') },
				{ label: t('InboxViewCards'), checked: params.view !== 'table', run: () => setView('cards') },
				{ sep: true },
				{ label: t('InboxDensity_compact'), checked: params.density === 'compact', run: () => setDensity('compact') },
				{ label: t('InboxDensity_normal'), checked: (params.density || 'normal') === 'normal', run: () => setDensity('normal') },
				{ label: t('InboxDensity_comfortable'), checked: params.density === 'comfortable', run: () => setDensity('comfortable') },
			]);
		});
		const filters = $('.ibx-js-filters', el);
		filters.addEventListener('click', e => {
			const b = e.target.closest('[data-filter]');
			if (b) {
				S.filters[b.dataset.filter] = !S.filters[b.dataset.filter];
				renderFilters();
				loadList();
			} else if (e.target.closest('.ibx-js-clearfilters')) {
				Object.keys(S.filters).forEach(k => {
					S.filters[k] = false;
				});
				renderFilters();
				loadList();
			}
		});
		const search = $('.ibx-js-search', el);
		let timer = 0;
		search.addEventListener('input', () => {
			clearTimeout(timer);
			timer = setTimeout(() => {
				S.q = search.value.trim();
				if (S.q === '' && S.scope !== 'folder') {
					S.scope = 'folder';
				}
				loadList();
			}, 300);
		});
		search.addEventListener('keydown', e => {
			if (e.key === 'Escape' && search.value) {
				e.preventDefault();
				search.value = '';
				S.q = '';
				S.scope = 'folder';
				loadList();
			} else if (e.key === 'ArrowDown') {
				e.preventDefault();
				moveSelection(0);
			}
		});
		const listBody = $('.ibx-pane--list .ibx-pane__body', el);
		listBody.addEventListener('click', e => {
			const sortBtn = e.target.closest('[data-sort]');
			if (sortBtn) {
				const [k, dir] = (params.list_sort || 'date:desc').split(':');
				const key = sortBtn.dataset.sort;
				setSort(key + ':' + (k === key ? (dir === 'asc' ? 'desc' : 'asc') : (key === 'date' || key === 'size' ? 'desc' : 'asc')));
				return;
			}
			const item = e.target.closest('[data-mail]');
			if (!item) {
				return;
			}
			const id = +item.dataset.mail;
			if (e.target.closest('.ibx-js-check')) {
				select(id, 'toggle');
			} else {
				select(id, e.shiftKey ? 'range' : (e.ctrlKey || e.metaKey ? 'toggle' : ''));
			}
		});
		listBody.addEventListener('keydown', e => {
			if ((e.key === 'Enter' || e.key === ' ') && e.target.closest('[data-mail]')) {
				e.preventDefault();
				select(+e.target.closest('[data-mail]').dataset.mail, e.key === ' ' ? 'toggle' : '');
			}
		});
		$('.ibx-js-newpill', el).addEventListener('click', () => {
			listBody.scrollTo({ top: 0, behavior: 'smooth' });
			$('.ibx-js-newpill', el).hidden = true;
		});
		listBody.addEventListener('scroll', () => {
			if (listBody.scrollTop < 40) {
				$('.ibx-js-newpill', el).hidden = true;
			}
		}, { passive: true });
	}

	function setSort(v) {
		saveParam('list_sort', v);
		loadList();
	}
	function setView(v) {
		saveParam('view', v);
		renderList();
		renderListHead();
	}
	function setDensity(v) {
		saveParam('density', v);
		I.applyDisplay(params);
	}

	// ---------- Space ----------
	I.registerSpace({
		id: 'mail',
		icon: 'mail',
		label: () => t('InboxSpaceMail'),
		async render(el, route) {
			const wanted = +(route.parts[0] || 0);
			if (S.el === el) {
				if (wanted && wanted !== S.folderId && S.folders.has(wanted)) {
					openFolder(wanted, true);
				}
				return;
			}
			S.el = el;
			el.innerHTML = layout();
			bindLayout();
			applyFolders();
			applyWidths();
			renderFilters();
			try {
				await loadTree();
			} catch (err) {
				$('.ibx-pane--folders .ibx-pane__body', el).innerHTML = '<div class="ibx-empty">' + ic('warning', 'ibx-empty__icon') + '<div>' + esc(err.message) + '</div><button type="button" class="ibx-btn ibx-js-reload">' + th('InboxRetry') + '</button></div>';
				$('.ibx-js-reload', el).addEventListener('click', () => {
					S.el = null;
					this.render(el, route);
				});
				return;
			}
			renderTree();
			let start = S.folders.has(wanted) ? wanted : 0;
			if (!start && S.accounts.length) {
				const first = S.accounts[0].folders.find(f => f.role === 'inbox') || S.accounts[0].folders.find(f => f.selectable);
				start = first ? first.id : 0;
			}
			if (start) {
				openFolder(start, !!wanted);
			} else {
				renderList();
				showNothing();
			}
			document.addEventListener('keydown', e => onKey(e, false));
			// Crossing the phone width: table or cards again
			window.matchMedia('(max-width: 63.9375rem)').addEventListener('change', () => renderList());
			document.addEventListener('visibilitychange', () => {
				if (!document.hidden) {
					poll();
				}
			});
			S.pollTimer = setTimeout(poll, 20000);
		},
		refresh(msg) {
			if (msg && msg.entity === 'account') {
				refreshTree();
			}
		},
	});
})();
