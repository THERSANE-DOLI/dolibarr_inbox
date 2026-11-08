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
		filters: { unread: false, flagged: false, attachment: false, mine: false, archived: false },
		// Search panel (lot W7 1.19.0): criteria chosen (pills), third party, tag, period, panel, deep search
		chips: [],
		soc: null,
		tag: '',
		period: '',
		sx: { open: false, active: -1, opts: [], sug: null, sugSeq: 0, text: '', timer: 0 },
		deep: null,
		// Meilisearch (lot W7 1.20.0): state given by the last list, crops of the text per row
		ft: null,
		crops: {},
		// Names of the people assigned seen in the lists (user => name), and the user (lot W4 1.9.0)
		people: {},
		me: +(I.ctx('INBOX_USER_ID', 0) || 0),
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
		// Conversations unfolded in the list (key of the thread) and their mails
		expanded: new Set(),
		threadCache: {},
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
	function avatar(name, email, cls, mailId, reading) {
		return '<span class="ibx-avatar' + (cls ? ' ' + cls : '') + '" style="--ibx-avatar-h:' + hue(email) + '" aria-hidden="true"'
			+ (mailId ? ' data-avatar="' + mailId + '"' + (reading ? ' data-reading="1"' : '') + ' data-email="' + esc((email || '').toLowerCase()) + '"' : '') + '>' + esc(initials(name, email)) + '</span>';
	}

	/**
	 * Pictures of the correspondents (looked for by the server, ajax/avatar.php): one request per address and per
	 * page, by the row of a mail (never the address in an URL); the initials stay when there is none
	 */
	const avatarCache = new Map();
	function hydrateAvatars(root) {
		// Photos of the users of Dolibarr named in the head of a mail
		hydrateUserPhotos(root);
		$$('[data-avatar]:not([data-done])', root).forEach(el => {
			el.dataset.done = '1';
			const key = el.dataset.email + (el.dataset.reading ? ':r' : '');
			let p = avatarCache.get(key);
			if (!p) {
				p = new Promise(resolve => {
					const img = new Image();
					img.onload = () => resolve(img.src);
					img.onerror = () => resolve('');
					img.src = I.ctx('INBOX_AJAX_URL', '') + 'avatar.php?id=' + el.dataset.avatar + (el.dataset.reading ? '&reading=1' : '');
				});
				avatarCache.set(key, p);
			}
			p.then(src => {
				if (src) {
					el.style.backgroundImage = 'url("' + src + '")';
					el.classList.add('has-picture');
				}
			});
		});
	}

	// ---------- Folders ----------
	const ROLE_ICON = { inbox: 'inbox', drafts: 'drafts', sent: 'sent', archive: 'archive', junk: 'junk', trash: 'trash-folder' };
	const ROLE_KEY = { inbox: 'InboxFolderRoleInbox', drafts: 'InboxFolderRoleDrafts', sent: 'InboxFolderRoleSent', archive: 'InboxFolderRoleArchive', junk: 'InboxFolderRoleJunk', trash: 'InboxFolderRoleTrash' };
	/** Name shown for a folder: the special ones in the language of the user at the first level */
	function folderName(f) {
		return f.role && ROLE_KEY[f.role] && !f.parent && f.primary !== false ? t(ROLE_KEY[f.role]) : f.name;
	}
	/**
	 * Icon of a folder or a mailbox in its colour (analyse-interface §4 bis): colour of the role or chosen for the
	 * mailbox (shared by its users), kept readable on the pane in light and dark theme; « sober » icons (My account) =
	 * no colour
	 */
	function lookIcon(usage, color, cls) {
		const svg = ic(usage, cls);
		if (params.folder_icons === 'plain' || !color) {
			return svg;
		}
		const C = Dolibarr.tools.inboxColors;
		let style;
		if (color.indexOf('var(--ibx-folder-') === 0) {
			style = '--ibx-ficon-l:' + color + ';--ibx-ficon-d:' + color;
		} else if (C.isColor(color)) {
			style = '--ibx-ficon-l:' + C.readable(color, 'light') + ';--ibx-ficon-d:' + C.readable(color, 'dark');
		} else {
			return svg;
		}
		return svg.replace('<svg class="ibx-icon', '<svg style="' + style + '" class="ibx-ficon ibx-icon');
	}
	/** Icon of a folder: the one of its role, else the one chosen, else open / closed; the years of Archives look like it */
	function folderIcon(f, open, cls) {
		const parent = f.parent ? S.folders.get(f.parent) : null;
		const role = ROLE_ICON[f.role] ? f.role : (parent && parent.folder.role === 'archive' && !f.icon ? 'archive' : '');
		const icon = ROLE_ICON[f.role] || f.icon || (open ? 'folder-open' : 'folder');
		return lookIcon(icon, f.color || 'var(--ibx-folder-' + (role || 'other') + ')', cls);
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
		S.marks = S.marks || {};
		S.accounts.forEach(a => {
			S.marks[a.id] = Object.assign({}, a.marks || {}, S.marks[a.id] || {});
		});
		S.folders.clear();
		S.accounts.forEach(a => a.folders.forEach(f => S.folders.set(f.id, { folder: f, account: a })));
		S.icons = res.icons || [];
		// Folded state of the folders and mailboxes that are gone: forgotten (the setting does not grow)
		const known = new Set(S.accounts.map(a => 'a' + a.id).concat(Array.from(S.folders.keys(), id => 'f' + id)));
		const stale = Array.from(S.collapsed).filter(k => !known.has(k));
		if (stale.length && S.accounts.length) {
			stale.forEach(k => S.collapsed.delete(k));
			saveParam('tree_collapsed', JSON.stringify(Array.from(S.collapsed)));
		}
		adaptTags();
	}

	/**
	 * Colour shown for each tag (d.view): the colours configured, or for a user who declared a colour blindness in his
	 * card (and kept « adapted » in My account), another colour for the tags that collide for his vision. Display only
	 */
	function adaptTags() {
		const C = Dolibarr.tools.inboxColors;
		const visions = String(params.tag_colors_adapt) === '0' ? [] : C.userVisions();
		S.accounts.forEach(a => {
			const defs = a.tags || [];
			const colors = C.adapt(defs, visions);
			defs.forEach((d, i) => {
				d.view = colors[i];
			});
		});
	}

	function renderTree() {
		// Unread counter of the tab (js/inbox-notify.js): summed again on the counters known here, at once
		Dolibarr.executeHook('inbox:folders:counts', S.folders);
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
			const quotaPct = a.quota && a.quota.limit ? Math.round(100 * a.quota.used / a.quota.limit) : 0;
			const inError = !!a.error;
			const progress = a.state === 'first_sync' && a.progress && a.progress.total ? Math.min(99, Math.floor(100 * a.progress.indexed / a.progress.total)) : -1;
			html += '<li role="treeitem" aria-expanded="' + open + '" aria-level="1"><div class="ibx-tree__row ibx-tree__row--account' + (inError ? ' has-error' : '') + '" style="--ibx-depth:0" data-account="' + a.id + '">'
				+ '<button type="button" class="ibx-tree__twisty" data-toggle="' + key + '" aria-label="' + th(open ? 'InboxCollapse' : 'InboxExpand') + '" tabindex="-1">' + ic(open ? 'chevron-down' : 'chevron-right') + '</button>'
				+ (inError ? ic('warning', 'ibx-tree__icon') : lookIcon(a.icon || 'account', a.color, 'ibx-tree__icon'))
				+ '<span class="ibx-tree__label ibx-ellipsis" title="' + esc(a.email) + '">' + esc(accountName(a)) + '</span>'
				+ (!open && unread ? '<span class="ibx-count">' + unread + '</span>' : '')
				+ (inError ? '<button type="button" class="ibx-btn ibx-btn--icon ibx-btn--sm ibx-js-accounterror" data-account="' + a.id + '" title="' + th('InboxSyncState_' + a.state) + '" aria-label="' + th('InboxSyncState_' + a.state) + '">' + ic('info') + '</button>' : '')
				+ '</div>'
				+ (progress >= 0 ? '<div class="ibx-tree__note">' + th('InboxIndexingPct', progress) + '</div>' : '')
				+ (quotaPct >= 90 ? '<div class="ibx-tree__note ibx-tree__note--warning" role="status">' + ic('warning') + ' ' + th('InboxQuotaAlmostFull', quotaPct) + '</div>' : '');
			if (open) {
				html += '<ul role="group">' + branch(a, 0, 1, needle) + '</ul>';
			}
			html += '</li>';
		});
		body.innerHTML = awayNotes() + '<ul class="ibx-tree" role="tree" aria-label="' + th('InboxFolders') + '">' + html + '</ul>';
	}

	/**
	 * Out of office of the mailboxes read (lot W8 1.23.0): said to every reader (they know the senders got an automatic
	 * answer); Turn off and Change for the managers
	 */
	function awayNotes() {
		const fmt = d => new Date(d + 'T12:00:00').toLocaleDateString(locale(), { day: 'numeric', month: 'long' });
		const today = new Date().toISOString().slice(0, 10);
		return S.accounts.filter(a => a.away && a.away.to >= today).map(a => '<div class="ibx-awaynote" role="status">' + ic('warning')
			+ '<span>' + (a.away.from > today ? th('InboxAwayNotePlanned', accountName(a), fmt(a.away.from), fmt(a.away.to)) : th('InboxAwayNoteActive', accountName(a), fmt(a.away.to))) + '</span>'
			+ (a.level === 'manage' ? '<span class="ibx-awaynote__acts"><button type="button" class="ibx-linkbtn ibx-js-awayoff" data-account="' + a.id + '">' + th('InboxAwayTurnOff') + '</button>'
				+ '<a class="ibx-linkbtn" href="#/settings/accounts/' + a.id + '/away">' + th('InboxEdit') + '</a></span>' : '') + '</div>').join('');
	}
	// Out of office changed in the settings: the banner follows
	Dolibarr.on('inbox:away:changed', () => {
		if (S.el) {
			refreshTree();
		}
	});
	async function awayOff(accountId) {
		const a = S.accounts.find(x => x.id === accountId);
		if (!a || !(await I.confirmDialog({ title: t('InboxAwayTurnOffTitle', accountName(a)), text: t('InboxAwayTurnOffText'), confirm: t('InboxAwayTurnOff') }))) {
			return;
		}
		try {
			await post('rules', 'away_off', { id: accountId });
			a.away = null;
			renderTree();
			toast(t('InboxAwayTurnedOff'));
		} catch (err) {
			toast(err.message, { error: true });
		}
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
			const selected = f.id === S.folderId && S.scope === 'folder';
			html += '<li role="treeitem" aria-level="' + (depth + 1) + '"' + (sub ? ' aria-expanded="' + open + '"' : '') + '>'
				+ '<div class="ibx-tree__row' + (f.unread && f.role !== 'sent' && f.role !== 'drafts' ? ' has-unread' : '') + (f.error ? ' has-error' : '') + (f.selectable ? '' : ' is-disabled') + '" style="--ibx-depth:' + depth + '"'
				+ ' data-folder="' + f.id + '" aria-selected="' + selected + '" tabindex="' + (selected ? 0 : -1) + '">'
				+ '<button type="button" class="ibx-tree__twisty"' + (sub ? ' data-toggle="' + key + '" aria-label="' + th(open ? 'InboxCollapse' : 'InboxExpand') + '"' : ' aria-hidden="true"') + ' tabindex="-1">' + (sub ? ic(open ? 'chevron-down' : 'chevron-right') : '') + '</button>'
				// An ordinary folder unfolded on its subfolders is drawn open
				+ folderIcon(f, open, 'ibx-tree__icon')
				+ '<span class="ibx-tree__label ibx-ellipsis">' + esc(folderName(f)) + '</span>'
				+ (f.new ? '<span class="ibx-newdot" title="' + th('InboxNewCount', f.new) + '"><span class="ibx-sr-only">' + th('InboxNewCount', f.new) + '</span></span>' : '')
				// Junk: mails probably legitimate (known sender, authenticated), seen without opening the folder
				+ (f.role === 'junk' && f.legit ? '<span class="ibx-tree__legit" title="' + th('InboxJunkLegitCount', f.legit) + '">' + ic('warning') + f.legit + '<span class="ibx-sr-only"> ' + th('InboxJunkLegitCount', f.legit) + '</span></span>' : '')
				+ (f.unread && f.role !== 'sent' && f.role !== 'drafts' ? '<span class="ibx-count" aria-label="' + th('InboxSyncUnread', f.unread) + '">' + f.unread + '</span>' : '')
				+ '</div>'
				+ (open ? '<ul role="group">' + subHtml + '</ul>' : '')
				+ '</li>';
		});
		return html;
	}

	function bindTree() {
		const pane = $('.ibx-pane--folders', S.el);
		pane.addEventListener('click', e => {
			const off = e.target.closest('.ibx-js-awayoff');
			if (off) {
				e.stopPropagation();
				awayOff(+off.dataset.account);
				return;
			}
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
							await post('list', 'refresh', { folder: inbox.id, retry: 1 });
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
		const q = { folder: S.folderId, scope: S.scope, q: S.q, sort: params.list_sort || 'date:desc', cursor: cursor || '', limit: 200, threads: params.conversation === '0' ? 0 : 1 };
		Object.keys(S.filters).forEach(k => {
			if (S.filters[k]) {
				q[k] = 1;
			}
		});
		const qx = sxExtra();
		if (qx) {
			q.qx = qx;
		}
		if (S.soc) {
			q.soc = S.soc.id;
		}
		if (S.tag) {
			q.tag = S.tag;
		}
		if (deepOn()) {
			q.text = 1;
		}
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
			// Meilisearch (lot W7 1.20.0): mailboxes fully indexed, error, order, crops of the text
			S.ft = res.fulltext || null;
			S.crops = Object.assign({}, res.crops || {});
			learnPeople(res.people);
			if (keep) {
				mergeRows(res.rows, res.next);
				if (S.expanded.size) {
					S.threadCache = {};
					const again = S.rows.filter(r => r.thread && S.expanded.has(r.thread.key));
					Promise.all(again.map(r => api('mail', 'thread', { id: r.id }).then(x => {
						learnPeople(x.people);
						S.threadCache[r.thread.key] = x.mails || [];
					}).catch(() => {}))).then(() => renderList());
				}
			} else {
				S.expanded.clear();
				S.threadCache = {};
				S.rows = res.rows;
				S.next = res.next;
			}
			S.total = res.total;
			if (res.stamp && S.scope === 'folder' && S.q === '') {
				S.stamp = res.stamp;
				S.folderMax = Math.max(S.folderMax || 0, parseInt(res.stamp.split(':')[1], 10) || 0);
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
				learnPeople(res.people);
				const known = new Set(S.rows.map(r => r.id));
				S.rows = S.rows.concat(res.rows.filter(r => !known.has(r.id)));
				Object.assign(S.crops, res.crops || {});
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
			return Dolibarr.tools.inboxColors.badge(d, d.view);
		}).join(' ');
	}

	/** Correspondent of a row: the recipient in Sent and Drafts */
	/**
	 * Mail sent by the mailbox (in Sent or Drafts, or its sender is the address of the mailbox): never shown unread,
	 * even when the server has no \\Seen on it (copy made by the server, another tool, an import). Display only: nothing
	 * is written on the server
	 */
	function isMine(m) {
		const e = S.folders.get(m.folder);
		if (!e) {
			return false;
		}
		const role = m.role !== undefined ? m.role : e.folder.role;
		return role === 'sent' || role === 'drafts' || (m.from_email || (m.from && m.from.email) || '').toLowerCase() === e.account.email.toLowerCase();
	}
	function seenOf(m) {
		return !!m.seen || isMine(m);
	}
	/** « Me → Client Bride »: the first recipient of a mail sent */
	function mineLabel(m) {
		const first = String(m.to || '').split(',')[0].trim();
		const name = first.replace(/\s*<[^>]*>\s*$/, '').replace(/^"|"$/g, '').trim() || first.replace(/[<>]/g, '');
		return t('InboxMe') + (name ? ' → ' + name : '');
	}

	/** New mail: arrived after the mark of the user in its folder (when he opened it) and still unread */
	function isNew(r) {
		if (seenOf(r)) {
			return false;
		}
		const e = S.folders.get(r.folder);
		const marks = e && S.shownMarks ? S.shownMarks : null;
		const mark = marks && marks[r.folder] !== undefined ? marks[r.folder] : null;
		return mark !== null && (r.arrival || 0) > mark;
	}

	/**
	 * Leaving a folder (another folder, the tab closed): its new mails are seen, the mark goes to its last mail
	 * (kept per user and per mailbox, InboxNewMarks)
	 */
	function leaveFolder(keepalive) {
		const id = S.folderId;
		const e = S.folders.get(id);
		if (!e || !S.folderMax) {
			return;
		}
		const marks = S.marks[e.account.id] || (S.marks[e.account.id] = {});
		if ((marks[id] || 0) >= S.folderMax) {
			return;
		}
		marks[id] = S.folderMax;
		e.folder.new = 0;
		const body = { action: 'set', token: I.ctx('INBOX_TOKEN', ''), id: e.account.id, param: 'seen_marks', value: JSON.stringify(marks) };
		if (keepalive) {
			fetch(I.ctx('INBOX_AJAX_URL', '') + 'userparam.php', { method: 'POST', credentials: 'same-origin', keepalive: true, headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) }).catch(() => {});
		} else {
			post('userparam', 'set', { id: e.account.id, param: 'seen_marks', value: JSON.stringify(marks) }).catch(() => {});
		}
	}

	/** Number of mails of the conversation of a line (none for a mail alone) */
	function threadBadge(r) {
		if (!r.thread || r.thread.count < 2) {
			return '';
		}
		const open = S.expanded.has(r.thread.key);
		return '<button type="button" class="ibx-threadtoggle ibx-js-threadtoggle" data-row="' + r.id + '" aria-expanded="' + open + '" tabindex="-1" title="' + th('InboxThreadCount', r.thread.count) + '">'
			+ ic('thread') + ' ' + th('InboxThreadReplies', r.thread.count - 1) + ' ' + ic(open ? 'chevron-up' : 'chevron-down') + '</button> ';
	}

	/** Other mails of the conversation of a line, unfolded under it (cards or table rows) */
	function threadChildren(r, view, cols) {
		if (!r.thread || !S.expanded.has(r.thread.key)) {
			return '';
		}
		const list = S.threadCache[r.thread.key];
		if (!list) {
			return view === 'table' ? '<tr class="ibx-mailrow ibx-mailrow--child"><td></td><td colspan="' + (cols.length + 1) + '"><span class="ibx-skeleton" style="width:40%"></span></td></tr>'
				: '<div class="ibx-thread"><span class="ibx-skeleton" style="width:50%"></span></div>';
		}
		const kids = list.filter(m => m.id !== r.id).slice().reverse();
		if (view === 'table') {
			return kids.map(m => '<tr class="ibx-mailrow ibx-mailrow--child' + (seenOf(m) ? '' : ' is-unread') + (isMine(m) ? ' is-mine' : '') + '" data-mail="' + m.id + '" data-child="1" aria-selected="' + S.selected.has(m.id) + '" tabindex="-1">'
				+ '<td></td>' + cols.map(c => {
					if (c.id === 'from') {
						return '<td title="' + esc(m.from_email) + '">' + (isMine(m) ? ic('sent', 'ibx-faint') + ' <span class="ibx-sr-only">' + th('InboxSent') + ' </span>' + esc(mineLabel(m)) : '<span class="ibx-sr-only">' + th('InboxReceived') + ' </span>' + esc(m.from_name || m.from_email || t('InboxNoSender'))) + '</td>';
					}
					if (c.id === 'subject') {
						return '<td class="ibx-mailrow__subject ibx-muted">' + esc(m.subject || t('InboxNoSubject')) + '</td>';
					}
					if (c.id === 'date') {
						return '<td class="ibx-mailrow__date" title="' + esc(longDate(m.date)) + '">' + esc(shortDate(m.date)) + '</td>';
					}
					if (c.id === 'att') {
						return '<td>' + (m.attachments ? ic('attachment', 'ibx-faint') : '') + '</td>';
					}
					if (c.id === 'assign') {
						return '<td class="ibx-mailrow__assign">' + assigneesHtml(m.assignees) + '</td>';
					}
					return '<td></td>';
				}).join('') + '<td></td></tr>').join('');
		}
		return '<div class="ibx-thread" role="group" aria-label="' + th('InboxThreadCount', list.length) + '">' + kids.map(m => '<div class="ibx-mailcard ibx-mailcard--child' + (seenOf(m) ? '' : ' is-unread') + (isMine(m) ? ' is-mine' : '') + '" role="option" data-mail="' + m.id + '" data-child="1" aria-selected="' + S.selected.has(m.id) + '" tabindex="-1">'
			+ '<span class="ibx-mailcard__dot" aria-hidden="true"></span>'
			+ '<span class="ibx-mailcard__from"><span class="ibx-ellipsis">' + (isMine(m) ? ic('sent', 'ibx-faint') + ' <span class="ibx-sr-only">' + th('InboxSent') + ' </span>' + esc(mineLabel(m))
				: '<span class="ibx-sr-only">' + th('InboxReceived') + ' </span>' + esc(m.from_name || m.from_email || t('InboxNoSender'))) + '</span></span>'
			+ '<span class="ibx-mailcard__meta">' + assigneesHtml(m.assignees) + (m.attachments ? ic('attachment') : '') + '<span title="' + esc(longDate(m.date)) + '">' + esc(shortDate(m.date)) + '</span></span>'
			+ '<span class="ibx-mailcard__subject ibx-ellipsis ibx-muted">' + esc(m.snippet || m.subject || '') + '</span></div>').join('') + '</div>';
	}

	/** Unfold or fold the conversation of a line in the list (its mails read once) */
	async function toggleThread(rowId, open) {
		const r = S.rows.find(x => x.id === rowId);
		if (!r || !r.thread || r.thread.count < 2) {
			return;
		}
		const key = r.thread.key;
		const want = open === undefined ? !S.expanded.has(key) : open;
		if (want === S.expanded.has(key)) {
			return;
		}
		if (want) {
			S.expanded.add(key);
		} else {
			S.expanded.delete(key);
		}
		renderList();
		if (want && !S.threadCache[key]) {
			try {
				const res = await api('mail', 'thread', { id: rowId });
				learnPeople(res.people);
				S.threadCache[key] = res.mails || [];
			} catch (err) {
				S.expanded.delete(key);
				toast(err.message, { error: true });
			}
			renderList();
		}
	}

	/** Row of Sent or Drafts: its correspondent is the recipient, no picture of the sender (that is us) */
	function sentLike(row) {
		const e = S.folders.get(row.folder);
		return !!e && (e.folder.role === 'sent' || e.folder.role === 'drafts');
	}

	function correspondent(row) {
		const entry = S.folders.get(row.folder);
		const role = entry ? entry.folder.role : '';
		if (role === 'sent' || role === 'drafts') {
			return t('InboxToShort') + ' ' + (row.to || '');
		}
		return row.from_name || row.from_email || t('InboxNoSender');
	}

	/**
	 * Columns of the table (interface §5): chosen, sized and ordered by the user (setting list_columns), the subject
	 * always shown. Each one: label, width by default, sort key, cell
	 */
	const COLUMNS = {
		flag: { label: 'InboxFlagged', icon: 'star', width: '1.625rem', cell: r => '<td>' + (r.flagged ? '<span class="ibx-star" aria-pressed="true" title="' + th('InboxFlagged') + '">' + ic('star-on') + '</span>' : '') + '</td>' },
		att: { label: 'InboxAttachments', icon: 'attachment', width: '1.625rem', cell: r => '<td>' + (r.attachments ? ic('attachment', 'ibx-faint') : '') + '</td>' },
		from: { label: 'InboxCorrespondent', sort: 'from', width: '32%', cell: r => '<td title="' + esc(r.from_email) + '">' + (isNew(r) ? '<span class="ibx-newdot ibx-newdot--row"><span class="ibx-sr-only">' + th('InboxNew') + ' </span></span>' : '') + (seenOf(r) ? '' : '<span class="ibx-sr-only">' + th('InboxUnread') + ' </span>') + esc(correspondent(r)) + '</td>' },
		subject: { label: 'InboxSubject', sort: 'subject', width: '', cell: r => '<td>' + foundHtml(r) + threadMarks(r) + threadBadge(r) + (r.answered ? ic('reply', 'ibx-faint') + ' ' : '') + esc(r.subject || t('InboxNoSubject')) + (S.tagsColumn ? '' : ' ' + tagsHtml(r)) + '</td>' },
		tags: { label: 'InboxTags', width: '9rem', cell: r => '<td class="ibx-mailrow__tags">' + tagsHtml(r) + '</td>' },
		assign: { label: 'InboxAssigned', width: '5rem', cell: r => '<td class="ibx-mailrow__assign">' + assigneesHtml(r.assignees) + '</td>' },
		soc: { label: 'InboxThirdparty', width: '20%', cell: r => '<td class="ibx-muted">' + (r.soc ? esc(r.soc.name) : '') + '</td>' },
		size: { label: 'InboxSize', sort: 'size', width: '4.5rem', cell: r => '<td class="ibx-mailrow__date">' + esc(size(r.size)) + '</td>' },
		box: { label: 'InboxMailboxFolder', width: '22%', cell: r => '<td class="ibx-muted">' + esc(boxLabel(r)) + '</td>' },
		date: { label: 'InboxDate', sort: 'date', width: '5.75rem', cell: r => '<td class="ibx-mailrow__date" title="' + esc(longDate(r.date)) + '">' + esc(shortDate(r.date)) + '</td>' },
	};
	const DEFAULT_COLUMNS = ['flag', 'att', 'from', 'subject', 'tags', 'assign', 'date'];

	/** Columns of the user: [{id, w (px, optional)}], subject always there; search in every mailbox: mailbox added */
	function tableColumns() {
		let cols = null;
		try {
			cols = JSON.parse(params.list_columns || 'null');
		} catch (e) {
			cols = null;
		}
		cols = Array.isArray(cols) ? cols.filter(c => c && COLUMNS[c.id]) : [];
		if (!cols.length) {
			cols = DEFAULT_COLUMNS.map(id => ({ id }));
		}
		if (!cols.some(c => c.id === 'subject')) {
			cols.splice(Math.min(cols.length, 3), 0, { id: 'subject' });
		}
		if (S.scope === 'all' && !cols.some(c => c.id === 'box')) {
			cols.splice(cols.findIndex(c => c.id === 'subject') + 1, 0, { id: 'box', auto: true });
		}
		return cols;
	}
	/**
	 * Width the table needs so that the subject keeps 12 rem: the other columns take their width (px, rem or % of the
	 * table); beyond the pane, the list scrolls sideways instead of hiding the subject
	 */
	function tableMinWidth(cols) {
		const rem = parseFloat(getComputedStyle(document.documentElement).fontSize) || 16;
		let fixed = 2 * 1.625 * rem + 12 * rem;
		let pct = 0;
		cols.forEach(c => {
			const w = c.w ? c.w + 'px' : COLUMNS[c.id].width;
			if (!w) {
				return;
			}
			if (w.endsWith('%')) {
				pct += parseFloat(w) / 100;
			} else if (w.endsWith('rem')) {
				fixed += parseFloat(w) * rem;
			} else {
				fixed += parseFloat(w);
			}
		});
		return Math.ceil(fixed / Math.max(0.2, 1 - pct));
	}
	function saveColumns(cols) {
		saveParam('list_columns', JSON.stringify(cols.filter(c => !c.auto).map(c => (c.w ? { id: c.id, w: c.w } : { id: c.id }))));
	}
	function colHead(id) {
		const c = COLUMNS[id];
		const label = id === 'from' && (S.folderRole === 'sent' || S.folderRole === 'drafts') ? 'InboxRecipient' : c.label;
		const inner = c.icon ? ic(c.icon) + '<span class="ibx-sr-only">' + th(label) + '</span>' : th(label);
		const [k, dir] = (params.list_sort || 'date:desc').split(':');
		const on = c.sort && k === c.sort;
		return '<th data-col="' + id + '" draggable="true" title="' + th(label) + '"' + (c.sort ? ' aria-sort="' + (on ? (dir === 'asc' ? 'ascending' : 'descending') : 'none') + '"' : '') + '>'
			+ (c.sort ? '<button type="button" class="ibx-sorthead" data-sort="' + c.sort + '">' + inner + (on ? ' ' + ic(dir === 'asc' ? 'chevron-up' : 'chevron-down') : '') + '</button>' : inner)
			+ (c.icon ? '' : '<span class="ibx-colresize" data-resize="' + id + '" aria-hidden="true"></span>') + '</th>';
	}

	/** Columns menu: one check per column, the subject cannot be removed */
	function columnsMenu(anchor, at) {
		const cols = tableColumns();
		menu(anchor, Object.keys(COLUMNS).map(id => ({
			label: t(COLUMNS[id].label), checked: cols.some(c => c.id === id && !c.auto), disabled: id === 'subject',
			run: () => {
				const now = tableColumns().filter(c => !c.auto);
				const idx = now.findIndex(c => c.id === id);
				if (idx >= 0) {
					now.splice(idx, 1);
				} else {
					// Back at its usual place: before the date, or at the end
					const at = now.findIndex(c => c.id === 'date');
					now.splice(at >= 0 && id !== 'date' ? at : now.length, 0, { id });
				}
				saveColumns(now);
				renderList();
			},
		})).concat([{ sep: true }, {
			label: t('InboxColumnsDefault'), run: () => {
				saveParam('list_columns', '');
				renderList();
			},
		}]), at);
	}

	/** Width by the edge of a header (double click = default), order by drag and drop of the headers */
	function bindTableHead(body) {
		const table = $('.ibx-mailtable', body);
		if (!table) {
			return;
		}
		const picker = $('.ibx-js-colpicker', table);
		picker.addEventListener('click', e => {
			e.stopPropagation();
			columnsMenu(picker);
		});
		$('thead', table).addEventListener('contextmenu', e => {
			// Browser menu kept with Shift (interface §6 bis)
			if (e.shiftKey) {
				return;
			}
			e.preventDefault();
			columnsMenu(picker, { x: e.clientX, y: e.clientY });
		});
		table.addEventListener('pointerdown', e => {
			const grip = e.target.closest('[data-resize]');
			if (!grip) {
				return;
			}
			e.preventDefault();
			e.stopPropagation();
			const id = grip.dataset.resize;
			const col = $('col[data-col="' + id + '"]', table);
			const thEl = grip.closest('th');
			const start = e.clientX;
			const base = thEl.getBoundingClientRect().width;
			grip.setPointerCapture(e.pointerId);
			const move = ev => {
				col.style.width = Math.max(48, Math.round(base + ev.clientX - start)) + 'px';
			};
			const up = () => {
				grip.removeEventListener('pointermove', move);
				grip.removeEventListener('pointerup', up);
				const cols = tableColumns();
				const c = cols.find(x => x.id === id);
				if (c) {
					c.w = Math.round(thEl.getBoundingClientRect().width);
					saveColumns(cols);
				}
			};
			grip.addEventListener('pointermove', move);
			grip.addEventListener('pointerup', up);
		});
		table.addEventListener('dblclick', e => {
			const grip = e.target.closest('[data-resize]');
			if (grip) {
				const cols = tableColumns();
				const c = cols.find(x => x.id === grip.dataset.resize);
				if (c) {
					delete c.w;
					saveColumns(cols);
					renderList();
				}
			}
		});
		let dragged = '';
		table.addEventListener('dragstart', e => {
			const thEl = e.target.closest('th[data-col]');
			if (!thEl) {
				return;
			}
			dragged = thEl.dataset.col;
			e.dataTransfer.effectAllowed = 'move';
			e.dataTransfer.setData('text/plain', dragged);
		});
		table.addEventListener('dragover', e => {
			const thEl = e.target.closest('th[data-col]');
			if (dragged && thEl && thEl.dataset.col !== dragged) {
				e.preventDefault();
				$$('th.is-droptarget', table).forEach(x => x.classList.remove('is-droptarget'));
				thEl.classList.add('is-droptarget');
			}
		});
		table.addEventListener('drop', e => {
			const thEl = e.target.closest('th[data-col]');
			if (!dragged || !thEl) {
				return;
			}
			e.preventDefault();
			const cols = tableColumns().filter(c => !c.auto);
			const from = cols.findIndex(c => c.id === dragged);
			const target = cols.findIndex(c => c.id === thEl.dataset.col);
			if (from >= 0 && target >= 0) {
				const moved = cols.splice(from, 1)[0];
				cols.splice(from < target ? target : target, 0, moved);
				saveColumns(cols);
			}
			dragged = '';
			renderList();
		});
		table.addEventListener('dragend', () => {
			dragged = '';
			$$('th.is-droptarget', table).forEach(x => x.classList.remove('is-droptarget'));
		});
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
			const cols = tableColumns();
			S.tagsColumn = cols.some(c => c.id === 'tags');
			html = '<table class="ibx-mailtable" role="grid" aria-label="' + th('InboxMails') + '" aria-multiselectable="true" style="min-width:' + tableMinWidth(cols) + 'px"><colgroup><col class="ibx-col--icon">'
				+ cols.map(c => '<col data-col="' + c.id + '"' + (c.w ? ' style="width:' + c.w + 'px"' : (COLUMNS[c.id].width ? ' style="width:' + COLUMNS[c.id].width + '"' : '')) + '>').join('')
				+ '<col class="ibx-col--icon"></colgroup><thead><tr><th><span class="ibx-sr-only">' + th('InboxSelect') + '</span></th>'
				+ cols.map(c => colHead(c.id)).join('')
				+ '<th class="ibx-colpicker"><button type="button" class="ibx-btn ibx-btn--icon ibx-btn--sm ibx-js-colpicker" aria-haspopup="menu" title="' + th('InboxChooseColumns') + '" aria-label="' + th('InboxChooseColumns') + '">' + ic('view-table') + '</button></th></tr></thead><tbody>';
			S.rows.forEach(r => {
				const sel = S.selected.has(r.id);
				html += '<tr class="ibx-mailrow' + (seenOf(r) ? '' : ' is-unread') + (r.watched ? ' is-watched' : '') + (r.ignored ? ' is-ignored' : '') + '" data-mail="' + r.id + '" aria-selected="' + sel + '" tabindex="' + (r.id === S.anchor ? 0 : -1) + '" draggable="true">'
					+ '<td><input type="checkbox" class="ibx-js-check" tabindex="-1"' + (sel ? ' checked' : '') + ' aria-label="' + th('InboxSelect') + '"></td>'
					+ cols.map(c => COLUMNS[c.id].cell(r)).join('') + '<td></td></tr>'
					+ threadChildren(r, 'table', cols);
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
				html += '<div class="ibx-mailcard' + (seenOf(r) ? '' : ' is-unread') + (isNew(r) ? ' is-new' : '') + (r.watched ? ' is-watched' : '') + (r.ignored ? ' is-ignored' : '') + '" role="option" data-mail="' + r.id + '" aria-selected="' + sel + '" tabindex="' + (r.id === S.anchor ? 0 : -1) + '"' + (isPhone() ? '' : ' draggable="true"') + '>'
					+ '<span class="ibx-mailcard__dot" aria-hidden="true"></span>'
					+ '<span class="ibx-mailcard__from" title="' + esc(r.from_email) + '">' + (sentLike(r) ? '' : avatar(r.from_name, r.from_email, 'ibx-avatar--xs', r.id))
					+ '<span class="ibx-ellipsis">' + (isNew(r) ? '<span class="ibx-sr-only">' + th('InboxNew') + ' </span>' : '') + (seenOf(r) ? '' : '<span class="ibx-sr-only">' + th('InboxUnread') + ' </span>') + esc(correspondent(r)) + '</span></span>'
					// Short marks beside the date (answered, attachment, starred): the bottom line is only for what needs width
					+ '<span class="ibx-mailcard__meta">' + threadMarks(r) + (r.answered ? ic('reply') : '') + (r.attachments ? ic('attachment', 'ibx-faint') : '')
					+ (r.flagged ? '<span class="ibx-star" aria-pressed="true" title="' + th('InboxFlagged') + '">' + ic('star-on') + '</span>' : '')
					+ '<span title="' + esc(longDate(r.date)) + '">' + esc(shortDate(r.date)) + '</span></span>'
					+ '<span class="ibx-mailcard__subject ibx-ellipsis">' + esc(r.subject || t('InboxNoSubject')) + '</span>'
					+ (S.crops[r.id] ? '<span class="ibx-mailcard__snippet ibx-mailcard__crop">' + cropHtml(S.crops[r.id]) + '</span>'
						: (r.snippet ? '<span class="ibx-mailcard__snippet ibx-ellipsis">' + esc(r.snippet) + '</span>' : ''))
					+ '<span class="ibx-mailcard__foot">' + foundHtml(r) + threadBadge(r) + tagsHtml(r) + assigneesHtml(r.assignees, 'ibx-mailcard__assign') + (r.soc ? ' <span class="ibx-muted ibx-ellipsis">' + ic('company') + ' ' + esc(r.soc.name) + '</span>' : '')
					+ (S.scope === 'all' ? ' <span class="ibx-muted">' + esc(boxLabel(r)) + '</span>' : '')
					+ '</span>'
					+ '</div>'
					+ threadChildren(r, 'cards', null);
			});
			html += '</div>';
		}
		if (S.next) {
			html += '<div class="ibx-js-more ibx-listmore">' + '<span class="ibx-skeleton" style="width:60%"></span>' + '</div>';
		}
		body.innerHTML = html;
		bindTableHead(body);
		hydrateAvatars(body);
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

	/**
	 * Scroll a pane so that an element is in view, without scrolling the page itself (scrollIntoView moves every
	 * scrolling ancestor, the document included, and the whole application went up)
	 */
	function scrollWithin(box, el, block) {
		if (!box || !el) {
			return;
		}
		const b = box.getBoundingClientRect();
		const r = el.getBoundingClientRect();
		const sticky = $('.ibx-readhead.ibx-convo__head', box);
		const top = b.top + (sticky && block !== 'nearest' ? sticky.offsetHeight : 0);
		if (block === 'start') {
			box.scrollTop += r.top - top - 8;
		} else if (block === 'end') {
			box.scrollTop += Math.min(r.top - top - 8, r.bottom - b.bottom + 8);
		} else if (r.top < b.top + 30) {
			box.scrollTop += r.top - b.top - 30;
		} else if (r.bottom > b.bottom) {
			box.scrollTop += r.bottom - b.bottom;
		}
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

	function hasFilter() {
		return S.q !== '' || Object.values(S.filters).some(Boolean) || S.chips.length > 0 || !!S.soc || S.tag !== '' || S.period !== '';
	}

	function emptyList() {
		const f = currentFolder();
		if (hasFilter()) {
			return '<div class="ibx-empty">' + ic('search', 'ibx-empty__icon') + '<div class="ibx-empty__title">' + th('InboxNoResult') + '</div>'
				+ (canDeep() ? '<div>' + th('InboxDeepNotSearched') + '</div><div><button type="button" class="ibx-btn ibx-btn--primary ibx-js-deep">' + ic('search') + ' ' + th('InboxDeepSearchRun', sxWords().join(' ')) + '</button></div>' : '')
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
			sxChanged();
			renderListHead();
		}));
		const deep = $('.ibx-js-deep', body);
		if (deep) {
			deep.addEventListener('click', startDeep);
		}
	}

	function clearFilters() {
		S.q = '';
		S.scope = 'folder';
		Object.keys(S.filters).forEach(k => {
			S.filters[k] = false;
		});
		S.chips = [];
		S.soc = null;
		S.tag = '';
		S.period = '';
		S.sx.text = '';
		stopDeep(true);
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
		const sortKey = { 'date:asc': 'InboxSortDateAsc', 'from:asc': 'InboxSortFrom', 'from:desc': 'InboxSortFrom', 'subject:asc': 'InboxSortSubject', 'subject:desc': 'InboxSortSubject', 'size:desc': 'InboxSortSize', 'size:asc': 'InboxSortSize' }[params.list_sort || 'date:desc'];
		const sortLabel = S.ft && S.ft.relevance ? t('InboxSortRelevance') : (sortKey ? t(sortKey) : '');
		$('.ibx-js-scopehint', S.el).textContent = (S.scope === 'folder' && f ? t('InboxSearchIn', folderName(f.folder)) : '') + (sortLabel ? ' · ' + t('InboxSortedBy', sortLabel) : '');
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
		$('.ibx-js-sxclear', S.el).hidden = !hasFilter();
		$('.ibx-searchinfo', S.el).hidden = box.hidden && $('.ibx-js-scopehint', S.el).textContent === '' && !hasFilter();
	}

	function renderFilters() {
		sxRenderChips();
		sxRenderPanel();
	}

	// ---------- Search panel and deep search (lot W7 1.19.0) ----------
	// The field opens into a panel: scope, suggestions, filters (shown as pills in the field), the deep search in the
	// text of the mails (asked, one mailbox after the other on its mail server), recent searches

	/** Words typed (the operators typed in the field are not words) */
	function sxWords() {
		return sxFreeWords(S.q);
	}
	/** Words of a search, without its operators (« de: » still waiting for its value is not a word either) */
	function sxFreeWords(q) {
		const typed = sxLastOp(q || '');
		const free = (typed ? typed.prefix : (q || '')).replace(/[^\s:"]+:"[^"]*"/g, ' ');
		return free.split(/\s+/).filter(w => w && !/^[^\s:"]+:\S/.test(w) && !sxLastOp(w));
	}

	/** Criteria of the panel as operators in English, sent beside the typed text (qx) */
	function sxExtra() {
		const out = S.chips.map(c => c.op + ':"' + String(c.v).replace(/"/g, '') + '"');
		if (S.period) {
			const d = new Date();
			d.setHours(0, 0, 0, 0);
			d.setDate(d.getDate() - ({ today: 0, d7: 7, d30: 30, d365: 365 }[S.period] || 0));
			out.push('after:' + d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'));
		}
		return out.join(' ');
	}

	function deepKey() {
		return JSON.stringify([S.q, sxExtra(), S.scope, S.scope === 'all' ? 0 : S.folderId]);
	}
	/** The list asks for the mails found in their text by the deep search of the same search */
	function deepOn() {
		return !!(S.deep && S.deep.key === deepKey() && S.deep.done > 0);
	}
	function scopeAccounts() {
		if (S.scope === 'all') {
			return S.accounts.map(a => a.id);
		}
		const f = currentFolder();
		return f ? [f.account.id] : [];
	}
	/** Mailboxes whose text is in Meilisearch (fully indexed) and answering: no deep search needed */
	function ftCovered() {
		if (S.ft && S.ft.error) {
			return [];
		}
		return scopeAccounts().filter(id => (accountById(id) || {}).fulltext === 'ready');
	}
	function deepAccounts() {
		const covered = ftCovered();
		return scopeAccounts().filter(id => !covered.includes(id));
	}
	function canDeep() {
		return sxWords().length > 0 && deepAccounts().length > 0 && !(S.deep && S.deep.key === deepKey());
	}
	function accountById(id) {
		return S.accounts.find(a => a.id === id) || null;
	}

	/** Tags of the mailboxes of the scope, without duplicates (keyword) */
	function sxTagDefs() {
		const seen = new Set();
		const out = [];
		scopeAccounts().map(accountById).filter(Boolean).forEach(a => (a.tags || []).forEach(d => {
			if (!seen.has(d.keyword.toLowerCase())) {
				seen.add(d.keyword.toLowerCase());
				out.push(d);
			}
		}));
		return out;
	}

	// Recent searches: per user, in this browser
	function sxRecentKey() {
		return 'inbox.search.recent.' + S.me;
	}
	function sxRecent() {
		try {
			const list = JSON.parse(localStorage.getItem(sxRecentKey()) || '[]');
			// Old entries kept while typing (« de: », « de:alice ») left out
			return Array.isArray(list) ? list.filter(x => x && (sxFreeWords(x.q).length || (x.chips || []).length || x.soc)) : [];
		} catch (e) {
			return [];
		}
	}
	function sxRemember() {
		const q = sxFreeWords(S.q).join(' ');
		if (q === '' && !S.chips.length && !S.soc) {
			return;
		}
		const item = { q, chips: S.chips, soc: S.soc };
		const label = sxRecentLabel(item);
		const list = [item].concat(sxRecent().filter(x => sxRecentLabel(x) !== label)).slice(0, 6);
		try {
			localStorage.setItem(sxRecentKey(), JSON.stringify(list));
		} catch (e) {
			// private window: nothing kept
		}
	}
	function sxRecentLabel(x) {
		return [x.q || ''].concat((x.chips || []).map(c => c.label || c.v), x.soc ? [x.soc.name] : []).filter(Boolean).join(' · ');
	}

	/** Pills in the field: criteria of the panel and filters */
	function sxRenderChips() {
		const box = $('.ibx-js-sxpills', S.el);
		if (!box) {
			return;
		}
		const chips = S.chips.map((c, i) => ({ html: esc(c.label || c.v), rm: () => S.chips.splice(i, 1), edit: SX_OPS.some(x => x.op === c.op) ? () => {
			S.chips.splice(i, 1);
			sxEditPill(sxPillText(c));
		} : null }));
		if (S.soc) {
			chips.push({ html: esc(t('InboxSxSoc', S.soc.name)), rm: () => {
				S.soc = null;
			} });
		}
		const names = { unread: 'InboxFilterUnread', flagged: 'InboxFilterFlagged', attachment: 'InboxFilterAttachment', mine: 'InboxFilterMine', archived: 'InboxFilterMineArchived' };
		Object.keys(names).filter(k => S.filters[k]).forEach(k => chips.push({ html: th(names[k]), rm: () => {
			S.filters[k] = false;
			if (k === 'mine') {
				S.filters.archived = false;
			}
		} }));
		if (S.period) {
			chips.push({ html: th('InboxSxPeriodPill', t('InboxSxPeriod_' + S.period)), rm: () => {
				S.period = '';
			} });
		}
		if (S.tag) {
			const d = sxTagDefs().find(x => x.keyword.toLowerCase() === S.tag.toLowerCase());
			chips.push({ html: th('InboxSxTag', d ? d.label : S.tag), rm: () => {
				S.tag = '';
			} });
		}
		box.innerHTML = chips.map((c, i) => '<span class="ibx-sx__pill">' + (c.edit ? '<button type="button" class="ibx-sx__pilltext" data-edit="' + i + '" title="' + th('InboxSxEditPill') + '">' + c.html + '</button>' : c.html)
			+ '<button type="button" data-rm="' + i + '" aria-label="' + th('InboxSxRemove') + '" title="' + th('InboxSxRemove') + '">' + ic('close') + '</button></span>').join('');
		$$('[data-edit]', box).forEach(b => b.addEventListener('click', e => {
			e.stopPropagation();
			chips[+b.dataset.edit].edit();
		}));
		$$('[data-rm]', box).forEach(b => b.addEventListener('click', e => {
			e.stopPropagation();
			chips[+b.dataset.rm].rm();
			sxChanged();
			$('.ibx-js-search', S.el).focus();
		}));
		$('.ibx-js-sxkbd', S.el).hidden = S.sx.open || chips.length > 0 || S.q !== '' || isPhone();
	}

	/** A criterion changed: pills, panel, list */
	function sxChanged() {
		if (S.deep && S.deep.key !== deepKey()) {
			stopDeep(true);
		}
		sxRenderChips();
		sxRenderPanel();
		loadList();
	}

	function sxOpen() {
		if (S.sx.open) {
			return;
		}
		S.sx.open = true;
		S.sx.active = -1;
		sxRenderPanel();
		sxRenderChips();
	}
	function sxClose() {
		if (!S.sx.open) {
			return;
		}
		S.sx.open = false;
		S.sx.active = -1;
		sxRemember();
		sxRenderPanel();
		sxRenderChips();
	}

	/**
	 * Operators of the field (InboxSearchQuery on the server): the English name always works, plus the one of the
	 * language of the user. Value operators (de:dupont) and flags (pj:oui), with an example of value.
	 */
	const SX_OPS = [
		{ op: 'from', key: 'InboxSearchOpFrom', ex: 'dupont' }, { op: 'to', key: 'InboxSearchOpTo', ex: 'compta' },
		{ op: 'subject', key: 'InboxSearchOpSubject', ex: 'devis' }, { op: 'after', key: 'InboxSearchOpAfter', ex: '01/10/2026' },
		{ op: 'before', key: 'InboxSearchOpBefore', ex: '31/10/2026' }, { op: 'tag', key: 'InboxSearchOpTag', ex: '' },
	];
	const SX_FLAGS = [{ op: 'attachment', key: 'InboxSearchOpAttachment', en: 'has:attachment' }, { op: 'unread', key: 'InboxSearchOpUnread', en: 'is:unread' }];
	/** Name of an operator as the user types it (its translation, else the English one) */
	function sxOpName(o) {
		const tr = t(o.key);
		return (tr && tr !== o.key ? tr : (o.en || o.op)).toLowerCase();
	}
	/** Last token typed when it is an operator: { op, value, prefix (the text before it) } */
	function sxLastOp(text) {
		const re = /(^|\s)([^\s:"]+):/g;
		let found = null;
		let m;
		while ((m = re.exec(text)) !== null) {
			const name = m[2].toLowerCase();
			const o = SX_OPS.find(x => x.op === name || sxOpName(x) === name);
			if (o) {
				found = { o, start: m.index + m[1].length, valueAt: m.index + m[0].length };
			}
		}
		if (!found) {
			return null;
		}
		let value = text.slice(found.valueAt);
		let closed = false;
		if (value.startsWith('"')) {
			// In quotes: closed by the second quote, nothing after it
			const end = value.indexOf('"', 1);
			if (end >= 0 && value.slice(end + 1).trim() !== '') {
				return null;
			}
			closed = end >= 0;
			value = value.slice(1, end >= 0 ? end : undefined);
		} else if (!SX_TEXT_OPS.includes(found.o.op) && /\s/.test(value)) {
			// A date has no space: the operator is behind, the words after are a search
			return null;
		} else if (['from', 'to'].includes(found.o.op) && /^[^\s@]+@[^\s@]+\.[^\s@]+\s/.test(value)) {
			// A whole address then a space: the address is the value, the words after are a search
			return null;
		}
		return { o: found.o, value, closed, prefix: text.slice(0, found.start) };
	}
	/** Operators whose value may hold spaces (until Enter or Tab) */
	const SX_TEXT_OPS = ['from', 'to', 'subject', 'tag'];
	/** Last word typed when it is a flag (pj:oui, non-lu): its filter */
	function sxLastFlag(text) {
		const m = /(^|\s)(\S+)$/.exec(text);
		if (!m) {
			return null;
		}
		const w = m[2].toLowerCase();
		const f = SX_FLAGS.find(x => w === sxOpName(x) || w === x.en || (x.op === 'attachment' && w === 'pj:oui'));
		return f ? { f, prefix: text.slice(0, m.index + m[1].length) } : null;
	}
	function sxValidDate(v) {
		return /^\d{4}(-\d{1,2}(-\d{1,2})?)?$/.test(v) || /^\d{1,2}\/\d{1,2}\/\d{4}$/.test(v);
	}
	const SX_LABELS = { from: 'InboxSxFrom', to: 'InboxSxTo', subject: 'InboxSxSubject', after: 'InboxSxAfter', before: 'InboxSxBefore' };
	/** What the operator typed becomes: a pill (or the tag filter); null when its value is not complete or valid */
	function sxPillOf(typed) {
		const v = typed.value.trim();
		if (!v) {
			return null;
		}
		const op = typed.o.op;
		if ((op === 'after' || op === 'before') && !sxValidDate(v)) {
			return null;
		}
		if (op === 'tag') {
			const d = sxTagDefs().find(x => x.label.toLowerCase() === v.toLowerCase() || x.keyword.toLowerCase() === v.toLowerCase());
			return d ? { tag: d.keyword } : null;
		}
		return { op, v, label: t(SX_LABELS[op], v) };
	}
	/** Operator typed turned into a pill (Enter, Tab, space after a date, closing quote) */
	function sxCommitOp(typed) {
		const pill = typed ? sxPillOf(typed) : null;
		if (!pill) {
			return false;
		}
		sxTakeOp(typed.prefix, () => {
			if (pill.tag) {
				S.tag = pill.tag;
			} else {
				S.chips.push(pill);
			}
		});
		return true;
	}
	/**
	 * Enter or Tab: every operator complete in the field becomes its pill (also a text pasted at once), the operator
	 * being typed included; what is left is the search
	 * @return {boolean} Something became a pill
	 */
	function sxCommitAll() {
		const input = $('.ibx-js-search', S.el);
		let text = input.value;
		const pills = [];
		const flags = [];
		const typed = sxLastOp(text);
		const last = typed ? sxPillOf(typed) : null;
		if (last) {
			pills.push(last);
			text = typed.prefix;
		}
		text = text.replace(/(^|\s)([^\s:"]+):(?:"([^"]*)"|(\S+))/g, (all, sp, name, quoted, raw) => {
			const o = SX_OPS.find(x => x.op === name.toLowerCase() || sxOpName(x) === name.toLowerCase());
			const pill = o ? sxPillOf({ o, value: quoted !== undefined ? quoted : raw }) : null;
			if (!pill) {
				return all;
			}
			pills.push(pill);
			return sp;
		});
		text = text.split(/\s+/).filter(w => {
			const f = w ? sxLastFlag(w) : null;
			if (f) {
				flags.push(f.f.op);
			}
			return w && !f;
		}).join(' ');
		if (!pills.length && !flags.length) {
			return false;
		}
		sxTakeOp(text, () => {
			pills.forEach(pill => {
				if (pill.tag) {
					S.tag = pill.tag;
				} else {
					S.chips.push(pill);
				}
			});
			flags.forEach(f => {
				S.filters[f] = true;
			});
		});
		return true;
	}
	function sxCommitFlag(flag) {
		if (!flag) {
			return false;
		}
		sxTakeOp(flag.prefix, () => {
			S.filters[flag.f.op] = true;
		});
		return true;
	}
	/** Text of a pill put back in the field to be corrected */
	function sxPillText(c) {
		const name = sxOpName(SX_OPS.find(x => x.op === c.op) || { op: c.op, key: '' });
		return name + ':' + (/\s/.test(String(c.v)) && !SX_TEXT_OPS.includes(c.op) ? '"' + c.v + '"' : c.v);
	}
	function sxEditPill(text) {
		const input = $('.ibx-js-search', S.el);
		const v = input.value.replace(/\s+$/, '');
		input.value = (v ? v + ' ' : '') + text;
		S.sx.text = input.value;
		S.q = sxQuery(input.value);
		sxChanged();
		input.focus();
		input.setSelectionRange(input.value.length, input.value.length);
	}
	/**
	 * Text sent to the server: an operator still being typed with spaces is sent in quotes, so that the list shows
	 * what the pill will be (« sujet:bon de commande » = the subject holds « bon de commande »)
	 */
	function sxQuery(value) {
		const typed = sxLastOp(value);
		if (typed && SX_TEXT_OPS.includes(typed.o.op) && !typed.closed && /\s/.test(typed.value.trim())) {
			return (typed.prefix + sxOpName(typed.o) + ':"' + typed.value.trim().replace(/"/g, '') + '"').trim();
		}
		return value.trim();
	}
	/** The operator typed replaced by a criterion of the panel (pill) */
	function sxTakeOp(prefix, apply) {
		const input = $('.ibx-js-search', S.el);
		input.value = prefix.replace(/\s+$/, '') + (prefix.trim() ? ' ' : '');
		S.q = sxQuery(input.value);
		S.sx.text = input.value;
		S.sx.active = -1;
		apply();
		sxChanged();
		input.focus();
	}
	/** An operator clicked in the panel goes into the field, ready for its value */
	function sxInsertOp(token) {
		const input = $('.ibx-js-search', S.el);
		const v = input.value.replace(/\s+$/, '');
		input.value = (v ? v + ' ' : '') + token;
		input.focus();
		input.dispatchEvent(new Event('input'));
	}

	/** The list of the operators unfolded or not, kept in this browser */
	function sxOpsShown() {
		try {
			return localStorage.getItem('inbox.search.ops') === '1';
		} catch (e) {
			return false;
		}
	}
	function sxOpsToggle() {
		try {
			localStorage.setItem('inbox.search.ops', sxOpsShown() ? '0' : '1');
		} catch (e) {
			// private window: folded again next time
		}
	}

	/** Senders and third parties of the readable mailboxes that match the text typed */
	let sxSugTimer = 0;
	function sxSuggest() {
		clearTimeout(sxSugTimer);
		// After de: or à:, the senders of the value only; after another operator, nothing to suggest
		const typed = sxLastOp(S.sx.text);
		const text = typed ? (['from', 'to'].includes(typed.o.op) ? typed.value.trim() : '') : S.sx.text.trim();
		if (text.length < 2) {
			S.sx.sug = null;
			return;
		}
		sxSugTimer = setTimeout(async () => {
			const seq = ++S.sx.sugSeq;
			try {
				const kind = typed && ['from', 'to'].includes(typed.o.op) ? typed.o.op : '';
				const res = await api('list', 'suggest', { q: text, kind }, { method: 'POST' });
				if (seq === S.sx.sugSeq) {
					S.sx.sug = { text, kind, senders: res.senders || [], socs: res.socs || [], people: res.people || [] };
					sxRenderPanel();
				}
			} catch (e) {
				// No suggestion: the search itself still works
			}
		}, 200);
	}

	function sxRenderPanel() {
		const sx = $('.ibx-js-sx', S.el);
		const panel = $('.ibx-js-sxpanel', S.el);
		const input = $('.ibx-js-search', S.el);
		if (!sx) {
			return;
		}
		sx.classList.toggle('is-open', S.sx.open);
		const backdrop = $('.ibx-js-sxbackdrop', S.el);
		if (backdrop) {
			backdrop.hidden = !S.sx.open;
		}
		input.setAttribute('aria-expanded', S.sx.open ? 'true' : 'false');
		panel.hidden = !S.sx.open;
		if (!S.sx.open) {
			input.removeAttribute('aria-activedescendant');
			return;
		}
		const opts = [];
		const opt = (icon, label, hint, run) => {
			const i = opts.length;
			opts.push(run);
			return '<li class="ibx-sx__opt' + (S.sx.active === i ? ' is-active' : '') + '" role="option" id="ibx-sxo' + i + '" data-opt="' + i + '" aria-selected="' + (S.sx.active === i) + '">' + ic(icon)
				+ '<span class="ibx-ellipsis">' + label + (hint ? ' <small>' + hint + '</small>' : '') + '</span></li>';
		};
		const text = S.sx.text.trim();
		const f = currentFolder();
		let h = '';

		// Where
		const scopes = [];
		if (f) {
			scopes.push(['folder', 'InboxScopeFolder'], ['account', 'InboxScopeAccount']);
		}
		if (S.accounts.length > 1 || !f) {
			scopes.push(['all', 'InboxScopeAll']);
		}
		h += '<div class="ibx-sx__sec"><p class="ibx-sx__label" id="ibx-sxwhere">' + th('InboxSxWhere') + '</p><div class="ibx-btngroup" role="group" aria-labelledby="ibx-sxwhere">'
			+ scopes.map(([v, k]) => '<button type="button" class="ibx-btn ibx-btn--sm ibx-btn--toggle" data-sxscope="' + v + '" aria-pressed="' + (S.scope === v) + '">' + th(k) + '</button>').join('') + '</div></div>';

		// Operator typed (de:, sujet:...): what to type after it, or the senders of the value
		const typed = sxLastOp(S.sx.text);
		if (typed) {
			const o = typed.o;
			const name = sxOpName(o);
			let sug = '';
			const value = typed.value.trim();
			const pill = sxPillOf(typed);
			if (pill && !pill.tag) {
				sug += opt(o.op === 'from' || o.op === 'to' ? 'user-circle' : (o.op === 'subject' ? 'fmt-heading' : 'agenda'), esc(pill.label), th('InboxSxEnterToAdd'), () => sxCommitOp(typed));
			}
			let waiting = '';
			if (['from', 'to'].includes(o.op) && value.length >= 2) {
				const s = S.sx.sug && S.sx.sug.text === value && S.sx.sug.kind === o.op ? S.sx.sug : null;
				const key = o.op === 'from' ? 'InboxSxFrom' : 'InboxSxTo';
				const pick = x => () => sxTakeOp(typed.prefix, () => S.chips.push({ op: o.op, v: x.email, label: t(key, x.name || x.email) }));
				if (!s) {
					waiting = '<p class="ibx-sx__note ibx-muted">' + th('InboxSxLooking') + '</p>';
				} else {
					// In the mails first (with their count), then the people of Dolibarr who never wrote yet
					s.senders.forEach(x => {
						sug += opt('user-circle', th(key, x.name || x.email), esc(x.email) + ' · ' + th('InboxSxMailsCount', x.n), pick(x));
					});
					s.people.forEach(x => {
						sug += opt('contact-card', th(key, x.name || x.email), esc(x.email + (x.detail ? ' · ' + x.detail : '')), pick(x));
					});
					if (!s.senders.length && !s.people.length) {
						waiting = '<p class="ibx-sx__note ibx-muted">' + th('InboxSxNoAddress', value) + '</p>';
					}
				}
			}
			if (o.op === 'tag') {
				sxTagDefs().filter(d => !value || d.label.toLowerCase().includes(value.toLowerCase()) || d.keyword.toLowerCase().includes(value.toLowerCase())).slice(0, 6).forEach(d => {
					sug += opt('tag', th('InboxSxTag', d.label), '', () => sxTakeOp(typed.prefix, () => {
						S.tag = d.keyword;
					}));
				});
			}
			const example = name + ':' + (o.op === 'tag' ? ((sxTagDefs()[0] || {}).label || '...') : o.ex);
			h += '<div class="ibx-sx__sec"><p class="ibx-sx__label">' + th('InboxSxOpsTyped', name + ':') + '</p>'
				+ '<p class="ibx-sx__note">' + ic('info') + '<span>' + th('InboxSxOpHelp_' + o.op, example) + '</span></p>'
				+ (sug ? '<ul class="ibx-sx__opts" role="listbox" aria-label="' + th('InboxSxSuggestions') + '">' + sug + '</ul>' : '') + waiting + '</div>';
		} else if (text.length >= 2) {
			let sug = '';
			const low = text.toLowerCase();
			const s = S.sx.sug && S.sx.sug.text === text ? S.sx.sug : { senders: [], socs: [] };
			s.senders.forEach(x => {
				sug += opt('user-circle', th('InboxSxFrom', x.name || x.email), esc(x.email), () => sxAddChip('from', x.email, t('InboxSxFrom', x.name || x.email)));
			});
			s.socs.forEach(x => {
				sug += opt('company', th('InboxSxSoc', x.name), th('InboxSxSocHint'), () => {
					S.soc = { id: x.id, name: x.name };
					sxClearText();
				});
			});
			sxTagDefs().filter(d => d.label.toLowerCase().includes(low) || d.keyword.toLowerCase().includes(low)).slice(0, 3).forEach(d => {
				sug += opt('tag', th('InboxSxTag', d.label), '', () => {
					S.tag = d.keyword;
					sxClearText();
				});
			});
			sug += opt('fmt-heading', th('InboxSxSubject', text), '', () => sxAddChip('subject', text, t('InboxSxSubject', text)));
			h += '<div class="ibx-sx__sec"><p class="ibx-sx__label">' + th('InboxSxSuggestions') + '</p><ul class="ibx-sx__opts" role="listbox" aria-label="' + th('InboxSxSuggestions') + '">' + sug + '</ul></div>';
		}

		// Filters
		const tb = (k, icon, key) => '<button type="button" class="ibx-btn ibx-btn--sm ibx-btn--toggle" data-sxfilter="' + k + '" aria-pressed="' + !!S.filters[k] + '">' + ic(icon) + ' ' + th(key) + '</button>';
		const tags = sxTagDefs();
		h += '<div class="ibx-sx__sec"><p class="ibx-sx__label">' + th('InboxSxFilters') + '</p><div class="ibx-sx__filters">'
			+ tb('unread', 'unread', 'InboxFilterUnread') + tb('flagged', 'star', 'InboxFilterFlagged') + tb('attachment', 'attachment', 'InboxFilterAttachment') + tb('mine', 'user-circle', 'InboxFilterMine')
			+ (S.filters.mine && S.scope !== 'folder' ? tb('archived', 'archive', 'InboxFilterMineArchived') : '')
			+ '<label class="ibx-sx__field">' + th('InboxSxPeriod') + ' <select class="ibx-sx__select' + (S.period ? ' is-set' : '') + '" data-sxsel="period"><option value="">' + th('InboxSxPeriodAll') + '</option>'
			+ ['today', 'd7', 'd30', 'd365'].map(p => '<option value="' + p + '"' + (S.period === p ? ' selected' : '') + '>' + th('InboxSxPeriod_' + p) + '</option>').join('') + '</select></label>'
			+ (tags.length ? '<label class="ibx-sx__field">' + th('InboxSxTagLabel') + ' <select class="ibx-sx__select' + (S.tag ? ' is-set' : '') + '" data-sxsel="tag"><option value="">' + th('InboxSxPeriodAll') + '</option>'
				+ tags.map(d => '<option value="' + esc(d.keyword) + '"' + (S.tag.toLowerCase() === d.keyword.toLowerCase() ? ' selected' : '') + '>' + esc(d.label) + '</option>').join('') + '</select></label>' : '')
			+ '</div></div>';

		// Text of the mails: already in Meilisearch for the mailboxes indexed, deep search for the others
		const words = sxWords();
		const covered = ftCovered().map(id => accountById(id)).filter(Boolean).map(accountName);
		const notes = (covered.length ? '<p class="ibx-sx__note">' + ic('check') + '<span>' + th('InboxSxTextIncluded', covered.join(', ')) + '</span></p>' : '')
			+ (S.ft && S.ft.error ? '<p class="ibx-sx__note ibx-sx__note--warn">' + ic('warning') + '<span>' + th('InboxSxFastDown') + '</span></p>' : '');
		if (words.length && notes && !canDeep()) {
			h += '<div class="ibx-sx__sec"><p class="ibx-sx__label">' + th('InboxSxText') + '</p>' + notes + '</div>';
		}
		if (words.length && canDeep()) {
			const i = opts.length;
			opts.push(startDeep);
			const names = deepAccounts().map(id => accountById(id)).filter(Boolean).map(accountName).join(', ');
			h += '<div class="ibx-sx__sec"><p class="ibx-sx__label">' + th('InboxSxText') + '</p>' + notes
				+ '<div class="ibx-sx__deep' + (S.sx.active === i ? ' is-active' : '') + '" role="option" id="ibx-sxo' + i + '" data-opt="' + i + '" aria-selected="' + (S.sx.active === i) + '">' + ic('search')
				+ '<strong>' + th('InboxDeepSearchRun', words.join(' ')) + '</strong>'
				+ '<small>' + th('InboxDeepSearchHint', names + (S.scope === 'folder' && f ? ' (' + folderName(f.folder) + ')' : '')) + '</small>'
				+ '<kbd class="ibx-kbd">Ctrl+' + th('InboxSxKeyEnter') + '</kbd></div></div>';
		}

		// Recent searches
		const recent = text === '' ? sxRecent() : [];
		if (recent.length) {
			h += '<div class="ibx-sx__sec"><p class="ibx-sx__label">' + th('InboxSxRecent') + '<span class="ibx-spacer"></span><button type="button" class="ibx-js-sxrecentclear">' + th('InboxSxRecentClear') + '</button></p>'
				+ '<ul class="ibx-sx__opts" role="listbox" aria-label="' + th('InboxSxRecent') + '">' + recent.map(x => opt('search', esc(sxRecentLabel(x)), '', () => sxApplyRecent(x))).join('') + '</ul></div>';
		}

		// The operators that work at the keyboard, unfolded by the link of the foot; a click puts one in the field
		const showOps = sxOpsShown();
		if (showOps && text === '') {
			h += '<div class="ibx-sx__sec" id="ibx-sxops"><p class="ibx-sx__label">' + th('InboxSxOps') + '</p><div class="ibx-sx__ops">'
				+ SX_OPS.map(o => '<button type="button" class="ibx-sx__op" data-sxop="' + esc(sxOpName(o)) + ':" title="' + th('InboxSxOpDesc_' + o.op) + '"><code>' + esc(sxOpName(o)) + ':</code> '
					+ th('InboxSxOpDesc_' + o.op) + '</button>').join('')
				+ SX_FLAGS.map(o => '<button type="button" class="ibx-sx__op" data-sxop="' + esc(sxOpName(o)) + ' " title="' + th('InboxSxOpDesc_' + o.op) + '"><code>' + esc(sxOpName(o)) + '</code> '
					+ th('InboxSxOpDesc_' + o.op) + '</button>').join('')
				+ '</div></div>';
		}

		h += '<div class="ibx-sx__foot ibx-desktoponly-phone"><span><kbd class="ibx-kbd">↑</kbd> <kbd class="ibx-kbd">↓</kbd> ' + th('InboxSxKeysChoose') + '</span>'
			+ '<span><kbd class="ibx-kbd">' + th('InboxSxKeyEnter') + '</kbd> ' + th('InboxSxKeysValidate') + '</span>'
			+ (words.length ? '<span><kbd class="ibx-kbd">Ctrl+' + th('InboxSxKeyEnter') + '</kbd> ' + th('InboxSxKeysDeep') + '</span>' : '')
			+ '<span><kbd class="ibx-kbd">' + th('InboxSxKeyEscape') + '</kbd> ' + th('InboxSxKeysClose') + '</span>'
			+ (text === '' ? '<button type="button" class="ibx-sx__opsbtn ibx-js-sxops" aria-expanded="' + showOps + '" aria-controls="ibx-sxops">' + ic(showOps ? 'chevron-down' : 'chevron-right') + ' ' + th('InboxSxOpsToggle') + '</button>' : '')
			+ '</div>';

		panel.innerHTML = h;
		S.sx.opts = opts;
		if (S.sx.active >= opts.length) {
			S.sx.active = -1;
		}
		if (S.sx.active >= 0) {
			input.setAttribute('aria-activedescendant', 'ibx-sxo' + S.sx.active);
		} else {
			input.removeAttribute('aria-activedescendant');
		}
	}

	function sxClearText() {
		const input = $('.ibx-js-search', S.el);
		input.value = '';
		S.q = '';
		S.sx.text = '';
		S.sx.active = -1;
		sxChanged();
		input.focus();
	}
	function sxAddChip(op, v, label) {
		S.chips.push({ op, v, label });
		sxClearText();
	}
	function sxApplyRecent(x) {
		const input = $('.ibx-js-search', S.el);
		S.q = x.q || '';
		S.sx.text = S.q;
		input.value = S.q;
		S.chips = (x.chips || []).slice();
		S.soc = x.soc || null;
		S.sx.active = -1;
		sxChanged();
		input.focus();
	}

	// Deep search: one mailbox after the other, the list read again after each one
	async function startDeep() {
		if (!canDeep()) {
			return;
		}
		clearTimeout(S.sx.timer);
		S.q = sxQuery($('.ibx-js-search', S.el).value);
		const accounts = deepAccounts();
		const deep = { key: deepKey(), accounts, done: 0, running: true, stopped: false, incomplete: [], unsupported: [], failed: [] };
		S.deep = deep;
		sxClose();
		renderDeepbar();
		for (const id of accounts) {
			if (S.deep !== deep || !deep.running) {
				return;
			}
			const name = accountById(id) ? accountName(accountById(id)) : String(id);
			try {
				const q = listQuery('');
				const res = await post('list', 'content', { account: id, scope: S.scope, folder: S.folderId, q: q.q, qx: q.qx || '' });
				if (res.unsupported) {
					deep.unsupported.push(name);
				} else if (!res.complete) {
					deep.incomplete.push(name);
				}
			} catch (err) {
				deep.failed.push(name + ' : ' + err.message);
			}
			if (S.deep !== deep || !deep.running) {
				return;
			}
			deep.done++;
			renderDeepbar();
			await loadList(true);
		}
		deep.running = false;
		renderDeepbar();
		renderList();
	}
	function stopDeep(reset) {
		if (S.deep) {
			S.deep.running = false;
			S.deep.stopped = !reset;
			if (reset) {
				S.deep = null;
			}
		}
		renderDeepbar();
	}
	function renderDeepbar() {
		const bar = $('.ibx-js-deepbar', S.el);
		if (!bar) {
			return;
		}
		const d = S.deep;
		if (!d || d.closed) {
			bar.hidden = true;
			return;
		}
		bar.hidden = false;
		bar.classList.toggle('is-done', !d.running);
		const names = d.accounts.map(id => accountById(id)).filter(Boolean).map(accountName);
		let html;
		if (d.running) {
			const cur = names[Math.min(d.done, names.length - 1)] || '';
			html = '<span>' + ic('search') + ' ' + th('InboxDeepRunning', cur, String(d.done + 1), String(d.accounts.length)) + '</span>'
				+ '<button type="button" class="ibx-btn ibx-btn--sm ibx-js-deepstop">' + th('InboxDeepStop') + '</button>'
				+ '<div class="ibx-sx-deepbar__track" role="progressbar" aria-valuemin="0" aria-valuemax="' + d.accounts.length + '" aria-valuenow="' + d.done + '"><div class="ibx-sx-deepbar__fill" style="width:' + Math.round(100 * (d.done + 0.5) / d.accounts.length) + '%"></div></div>';
		} else {
			const lines = [];
			lines.push(d.stopped ? th('InboxDeepStopped') : th('InboxDeepDone', names.join(', ')) + ' <span class="ibx-muted">' + th('InboxDeepExact') + '</span>');
			d.incomplete.forEach(n => lines.push(ic('warning') + ' ' + th('InboxDeepIncomplete', n)));
			d.unsupported.forEach(n => lines.push(ic('info') + ' ' + th('InboxDeepUnsupported', n)));
			d.failed.forEach(n => lines.push(ic('warning') + ' ' + esc(n)));
			html = '<span>' + (d.stopped ? ic('info') : ic('check')) + ' ' + lines.join('<br>') + '</span>'
				+ '<button type="button" class="ibx-btn ibx-btn--ghost ibx-btn--sm ibx-btn--icon ibx-js-deepclose" aria-label="' + th('InboxCloseWindow') + '" title="' + th('InboxCloseWindow') + '">' + ic('close') + '</button>';
		}
		bar.innerHTML = html;
		const stop = $('.ibx-js-deepstop', bar);
		if (stop) {
			stop.addEventListener('click', () => {
				stopDeep(false);
				renderList();
			});
		}
		const close = $('.ibx-js-deepclose', bar);
		if (close) {
			close.addEventListener('click', () => {
				d.closed = true;
				renderDeepbar();
			});
		}
	}

	/** Crop of the text given by Meilisearch: escaped, then the words found marked */
	function cropHtml(crop) {
		return esc(crop).replace(/\uE000/g, '<mark>').replace(/\uE001/g, '</mark>');
	}

	/** Mark of a row found only in its text */
	function foundHtml(r) {
		return r.in_text ? '<span class="ibx-found">' + ic('search') + ' ' + th('InboxFoundInText') + '</span> ' : '';
	}

	/** Events of the field and of its panel */
	function bindSearchPanel(el) {
		const sx = $('.ibx-js-sx', el);
		const input = $('.ibx-js-search', el);
		const panel = $('.ibx-js-sxpanel', el);
		S.sx.text = '';
		input.addEventListener('focus', sxOpen);
		$('.ibx-js-sxrow', el).addEventListener('click', e => {
			if (!e.target.closest('button')) {
				input.focus();
				sxOpen();
			}
		});
		input.addEventListener('input', () => {
			const v = input.value;
			if (input.selectionStart === v.length) {
				const typed = sxLastOp(v);
				if (typed && typed.closed && sxCommitOp(typed)) {
					return;
				}
				if (/\s$/.test(v)) {
					const before = v.replace(/\s+$/, '');
					const t2 = sxLastOp(before);
					// A date, or a whole address after de: / à: (an address has no space): the pill at once
					const address = t2 && ['from', 'to'].includes(t2.o.op) && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(t2.value);
					if (t2 && (!SX_TEXT_OPS.includes(t2.o.op) || address) && sxCommitOp(t2)) {
						return;
					}
					if (sxCommitFlag(sxLastFlag(before))) {
						return;
					}
				}
			}
			S.sx.text = input.value;
			S.sx.active = -1;
			sxSuggest();
			sxOpen();
			sxRenderPanel();
			sxRenderChips();
			clearTimeout(S.sx.timer);
			S.sx.timer = setTimeout(() => {
				S.q = sxQuery(input.value);
				if (S.q === '' && S.scope !== 'folder' && !hasFilter() && currentFolder()) {
					S.scope = 'folder';
				}
				if (S.deep && S.deep.key !== deepKey()) {
					stopDeep(true);
				}
				sxRenderPanel();
				loadList();
			}, 300);
		});
		input.addEventListener('keydown', e => {
			if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
				e.preventDefault();
				S.q = sxQuery(input.value);
				startDeep();
				return;
			}
			if ((e.key === 'ArrowDown' || e.key === 'ArrowUp') && S.sx.open) {
				e.preventDefault();
				const n = S.sx.opts.length;
				if (n) {
					S.sx.active = e.key === 'ArrowDown' ? (S.sx.active + 1) % n : (S.sx.active <= 0 ? n - 1 : S.sx.active - 1);
					sxRenderPanel();
					const cur = $('#ibx-sxo' + S.sx.active, panel);
					if (cur) {
						cur.scrollIntoView({ block: 'nearest' });
					}
				}
				return;
			}
			if (e.key === 'ArrowDown') {
				e.preventDefault();
				moveSelection(0);
				return;
			}
			if (e.key === 'Enter') {
				e.preventDefault();
				if (S.sx.open && S.sx.active >= 0 && S.sx.opts[S.sx.active]) {
					S.sx.opts[S.sx.active]();
					return;
				}
				// An operator being typed becomes its pill, the panel stays open for the rest of the search
				if (sxCommitAll()) {
					return;
				}
				clearTimeout(S.sx.timer);
				S.q = sxQuery(input.value);
				sxClose();
				loadList();
				return;
			}
			if (e.key === 'Tab' && !e.shiftKey && sxCommitAll()) {
				e.preventDefault();
				return;
			}
			if (e.key === 'Escape') {
				e.preventDefault();
				if (S.sx.open) {
					sxClose();
				} else if (input.value || hasFilter()) {
					clearFilters();
				}
				return;
			}
			if (e.key === 'Backspace' && input.value === '' && S.chips.length) {
				e.preventDefault();
				sxEditPill(sxPillText(S.chips.pop()));
			}
		});
		sx.addEventListener('keydown', e => {
			if (e.key === 'Escape' && e.target !== input) {
				e.preventDefault();
				sxClose();
				input.focus();
			}
		});
		panel.addEventListener('click', e => {
			const scope = e.target.closest('[data-sxscope]');
			const filter = e.target.closest('[data-sxfilter]');
			const o = e.target.closest('[data-opt]');
			if (scope) {
				S.scope = scope.dataset.sxscope;
				sxChanged();
				renderListHead();
			} else if (filter) {
				const k = filter.dataset.sxfilter;
				S.filters[k] = !S.filters[k];
				if (!S.filters.mine) {
					S.filters.archived = false;
				}
				sxChanged();
			} else if (o && S.sx.opts[+o.dataset.opt]) {
				S.sx.opts[+o.dataset.opt]();
			} else if (e.target.closest('.ibx-js-sxops')) {
				sxOpsToggle();
				sxRenderPanel();
				input.focus();
			} else if (e.target.closest('[data-sxop]')) {
				sxInsertOp(e.target.closest('[data-sxop]').dataset.sxop);
			} else if (e.target.closest('.ibx-js-sxrecentclear')) {
				try {
					localStorage.removeItem(sxRecentKey());
				} catch (err) {
					// nothing kept anyway
				}
				sxRenderPanel();
				input.focus();
			}
		});
		panel.addEventListener('change', e => {
			const sel = e.target.closest('[data-sxsel]');
			if (sel) {
				S[sel.dataset.sxsel] = sel.value;
				sxChanged();
			}
		});
		$('.ibx-js-sxclose', el).addEventListener('click', e => {
			e.stopPropagation();
			sxClose();
			input.blur();
		});
		document.addEventListener('mousedown', e => {
			if (S.sx.open && !sx.contains(e.target)) {
				sxClose();
			}
		});
		$('.ibx-js-sxclear', el).addEventListener('click', clearFilters);
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
		renderBulkBar();
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
			scrollWithin($('.ibx-pane--list .ibx-pane__body', S.el), el, 'nearest');
			el.focus({ preventScroll: true });
		}
		if (i > S.rows.length - 20) {
			loadMore();
		}
	}
	function nextUnread() {
		const start = rowIndex(S.anchor);
		const r = S.rows.find((x, i) => i > start && !seenOf(x)) || S.rows.find(x => !seenOf(x));
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
			+ '<div class="ibx-muted">' + th('InboxBulkHint') + '</div></div>';
		const all = $('.ibx-js-allfolder', readPane());
		if (all) {
			all.addEventListener('click', () => {
				S.allInFolder = true;
				all.outerHTML = '<p>' + th('InboxWholeFolderSelected', (S.total || 0).toLocaleString(locale())) + '</p>';
				const count = $('.ibx-bulkbar__count', S.el);
				if (count) {
					count.textContent = t('InboxSelectedCount', (S.total || 0).toLocaleString(locale()));
				}
			});
		}
	}

	async function openMail(id, single) {
		clearTimeout(S.readTimer);
		S.openId = id;
		S.mail = null;
		S.thread = null;
		S.blocks = {};
		S.mobile('read');
		const pane = readPane();
		const row = S.rows.find(r => r.id === id);
		if (!single && row && row.thread && row.thread.count > 1) {
			openThread(id, row);
			return;
		}
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
		learnPeople(mail.people);
		renderMail(mail);
		presenceSoon();
		Dolibarr.executeHook('inbox:mail:open', { account: mail.account, messageId: mail.message_id, subject: mail.subject, from: mail.from });
		autoRead(mail);
	}

	/** Mail marked read by the setting of the user and the mailbox (interface §5.3) */
	function autoRead(mail) {
		if (mail.seen || mail.read_mode === 'manual' || isMine({ folder: mail.folder, role: mail.folder_role, from: mail.from })) {
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
				el.classList.toggle('is-unread', !seenOf(r));
				el.classList.toggle('is-new', isNew(r));
				const dot = $('.ibx-newdot--row', el);
				if (dot && !isNew(r)) {
					dot.remove();
				}
			}
		});
	}

	/** Name worth showing beside an address: none when it only repeats the address (« x@y <x@y> ») */
	function nameOf(a) {
		const n = String((a && a.name) || '').replace(/^["'\s]+|["'\s]+$/g, '');
		return n && n.toLowerCase() !== String(a.email || '').toLowerCase() ? n : '';
	}
	/** Users of Dolibarr met in the mails read (address => card), for the card shown on hover */
	const peopleCache = new Map();
	function personOf(email, people) {
		const key = String(email || '').toLowerCase();
		const p = people && people[key];
		if (p) {
			peopleCache.set(key, p);
		}
		return p || null;
	}
	function userAvatar(p, cls) {
		return '<span class="ibx-avatar ' + (cls || 'ibx-avatar--xs') + (p.photo ? ' ibx-js-userphoto" data-photo="' + esc(p.photo) : '') + '" style="--ibx-avatar-h:' + hue('u' + p.id) + '" aria-hidden="true">'
			+ esc(initials(p.name, p.email)) + '</span>';
	}

	function addressHtml(a, people) {
		if (!a) {
			return '';
		}
		const p = personOf(a.email, people);
		if (p) {
			// A user of Dolibarr: his photo and his name, the card on hover
			return '<span class="ibx-addr ibx-addr--user" data-email="' + esc(a.email) + '" data-name="' + esc(p.name) + '" data-user="' + esc(p.email) + '" aria-haspopup="dialog">'
				+ userAvatar(p) + esc(p.name) + '</span>';
		}
		const name = nameOf(a);
		return '<span class="ibx-addr" data-email="' + esc(a.email) + '" data-name="' + esc(name) + '" title="' + esc(a.email) + '">' + esc(name || a.email) + (name ? ' <span class="ibx-muted">&lt;' + esc(a.email) + '&gt;</span>' : '') + '</span>';
	}

	// ---------- Card of a user of Dolibarr (hover or focus of his name, tap on a phone) ----------
	const UC = { el: null, timer: 0, for: null };
	function userCardHtml(p) {
		const line = (icon, html) => '<div class="ibx-usercard__line">' + ic(icon) + '<span>' + html + '</span></div>';
		return userAvatar(p, 'ibx-usercard__avatar') + '<div class="ibx-usercard__main"><div class="ibx-usercard__name">' + esc(p.name) + '</div>'
			+ (p.job ? '<div class="ibx-muted">' + esc(p.job) + '</div>' : '')
			+ line('mail', esc(p.email))
			+ (p.phone ? line('contact-card', '<a href="tel:' + esc(p.phone.replace(/[^\d+]/g, '')) + '">' + esc(p.phone) + '</a>') : '')
			+ (p.mobile ? line('contact-card', '<a href="tel:' + esc(p.mobile.replace(/[^\d+]/g, '')) + '">' + esc(p.mobile) + '</a>') : '')
			+ '<div class="ibx-usercard__acts">'
			+ (p.url ? '<a class="ibx-btn ibx-btn--sm" href="' + esc(p.url) + '" target="_blank" rel="noopener">' + ic('external') + ' ' + th('InboxUserCard') + '</a>' : '')
			+ '<button type="button" class="ibx-btn ibx-btn--sm ibx-btn--ghost ibx-js-ucsearch">' + ic('search') + ' ' + th('InboxMailsOf', p.name) + '</button></div></div>';
	}
	function userCardHide() {
		clearTimeout(UC.timer);
		if (UC.el) {
			UC.el.remove();
			UC.el = null;
			if (UC.for) {
				UC.for.setAttribute('aria-expanded', 'false');
			}
			UC.for = null;
		}
	}
	function userCardShow(anchor) {
		const p = peopleCache.get(String(anchor.dataset.user || '').toLowerCase());
		if (!p || UC.for === anchor) {
			return;
		}
		userCardHide();
		const el = document.createElement('div');
		el.className = 'ibx-usercard';
		el.setAttribute('role', 'dialog');
		el.setAttribute('aria-label', p.name);
		el.innerHTML = userCardHtml(p);
		document.body.appendChild(el);
		hydrateUserPhotos(el);
		const r = anchor.getBoundingClientRect();
		const w = el.offsetWidth;
		el.style.left = Math.max(8, Math.min(r.left, window.innerWidth - w - 8)) + 'px';
		el.style.top = (r.bottom + el.offsetHeight + 12 > window.innerHeight ? Math.max(8, r.top - el.offsetHeight - 6) : r.bottom + 6) + 'px';
		el.addEventListener('mouseenter', () => clearTimeout(UC.timer));
		el.addEventListener('mouseleave', () => {
			UC.timer = setTimeout(userCardHide, 200);
		});
		$('.ibx-js-ucsearch', el).addEventListener('click', () => {
			userCardHide();
			searchFrom(p.email);
		});
		el.addEventListener('keydown', e => {
			if (e.key === 'Escape') {
				const back = UC.for;
				userCardHide();
				if (back) {
					back.focus();
				}
			}
		});
		UC.el = el;
		UC.for = anchor;
		anchor.setAttribute('aria-expanded', 'true');
	}
	function bindUserCards(root) {
		root.addEventListener('mouseover', e => {
			const a = e.target.closest('.ibx-addr--user');
			if (a && root.contains(a)) {
				clearTimeout(UC.timer);
				UC.timer = setTimeout(() => userCardShow(a), 350);
			}
		});
		root.addEventListener('mouseout', e => {
			const a = e.target.closest('.ibx-addr--user');
			if (a && !(e.relatedTarget && a.contains(e.relatedTarget))) {
				clearTimeout(UC.timer);
				UC.timer = setTimeout(userCardHide, 200);
			}
		});
		root.addEventListener('focusin', e => {
			const a = e.target.closest('.ibx-addr--user');
			if (a) {
				userCardShow(a);
			}
		});
		root.addEventListener('click', e => {
			const a = e.target.closest('.ibx-addr--user');
			if (a) {
				// A tap on a phone (no hover): the card, a second tap closes it
				if (UC.for === a) {
					userCardHide();
				} else {
					userCardShow(a);
				}
			}
		});
		document.addEventListener('keydown', e => {
			if (e.key === 'Escape' && UC.el) {
				userCardHide();
			}
		});
		document.addEventListener('pointerdown', e => {
			if (UC.el && !UC.el.contains(e.target) && !(UC.for && UC.for.contains(e.target))) {
				userCardHide();
			}
		}, true);
	}

	function headHtml(m, partial, inThread) {
		const e = S.folders.get(m.folder);
		// In a conversation the mailbox is written once, in the head of the conversation
		const box = inThread ? '' : (e ? e.account.email : (m.mailbox || ''));
		const recipients = (m.to || []).concat(m.cc || []);
		const toLine = recipients.length ? th('InboxForShort') + ' ' + recipients.slice(0, 6).map(a => addressHtml(a, m.people)).join(', ') + (recipients.length > 6 ? ' ' + th('InboxAndMore', recipients.length - 6) : '') : '';
		// A mail sent by the mailbox: « Me » with the address of the mailbox in small
		const mine = m.id && isMine({ folder: m.folder, role: m.folder_role, from: m.from });
		// The sender: a user of Dolibarr with his photo, name and card; else the name only when it is not the address again
		const fromUser = mine ? null : personOf(m.from.email, m.people);
		const fromName = fromUser ? fromUser.name : nameOf(m.from);
		return '<div class="ibx-readhead__top">' + (mine ? '<span class="ibx-avatar ibx-avatar--me" aria-hidden="true">' + ic('sent') + '</span>'
			: (fromUser && fromUser.photo ? userAvatar(fromUser, '') : avatar(fromName, m.from.email, '', m.id, !partial || inThread)))
			+ '<div class="ibx-readhead__who"><div class="ibx-readhead__name ibx-addr' + (fromUser ? ' ibx-addr--user' : '') + '" data-email="' + esc(m.from.email) + '" data-name="' + esc(fromName) + '"'
			+ (fromUser ? ' data-user="' + esc(fromUser.email) + '" aria-haspopup="dialog"' : '') + '>'
			+ (mine ? esc(t('InboxMe')) + ' <span class="ibx-readhead__email">&lt;' + esc(m.from.email) + '&gt;</span>'
				: esc(fromName || m.from.email || t('InboxNoSender')) + (fromName ? ' <span class="ibx-readhead__email">&lt;' + esc(m.from.email) + '&gt;</span>' : '')) + '</div>'
			+ '<div class="ibx-readhead__line"><span title="' + esc(longDate(m.date)) + '">' + esc(longDate(m.date)) + '</span></div></div>'
			+ (partial ? '' : '<div class="ibx-readhead__actions">' + actionButtons(m) + '</div>') + '</div>'
			+ '<div class="ibx-readhead__line ibx-readhead__to">' + toLine + (box ? (toLine ? ' · ' : '') + th('InboxReceivedOn') + ' <b>' + esc(box) + '</b>' : '') + '</div>'
			+ (partial || inThread ? '' : assignLine([m.id], m.assignees || [], m.has_message_id !== false))
			+ '<h2 class="ibx-readhead__subject">' + esc(m.subject || t('InboxNoSubject')) + ' ' + tagsHtml({ folder: m.folder, tags: m.tags }) + '</h2>';
	}

	// ---------- Actions on mails (lot W4: write on the server, checked, undo 8 s) ----------
	/** Actions after which the mail leaves the folder */
	const LEAVING = new Set(['move', 'archive', 'delete', 'junk', 'notjunk']);
	/** Actions of the level « manage » (read / unread and starred: « read », lot W4 §10 point 2) */
	const MANAGING = new Set(['tag', 'move', 'archive', 'delete', 'junk', 'notjunk']);
	/** Mails per call (a larger selection goes by batches, decision W4 point 3) */
	const BATCH = 200;

	/** A mail known on screen: row of the list, mail of an unfolded conversation, of the conversation read, or the mail read */
	function rowOf(id) {
		let r = S.rows.find(x => x.id === id);
		if (r) {
			return r;
		}
		for (const k in S.threadCache) {
			r = (S.threadCache[k] || []).find(x => x.id === id);
			if (r) {
				return r;
			}
		}
		r = (S.thread || []).find(x => x.id === id);
		if (r) {
			return r;
		}
		return S.mail && S.mail.id === id ? S.mail : null;
	}
	function accountOfRow(r) {
		const e = r ? S.folders.get(r.folder) : null;
		return e ? e.account : null;
	}
	/** Why an action is refused on these mails ('' = allowed); the server checks it again */
	function refusal(what, ids) {
		if (!MANAGING.has(what)) {
			return '';
		}
		for (const id of ids) {
			const a = accountOfRow(rowOf(id));
			if (a && a.level !== 'manage') {
				return t('InboxReadOnlyOn', a.email);
			}
		}
		return '';
	}
	/** Folder of a role in the mailbox of a mail */
	function roleFolderOf(r, role) {
		const a = accountOfRow(r);
		if (!a) {
			return null;
		}
		return a.folders.find(f => f.role === role && f.primary !== false && f.selectable) || a.folders.find(f => f.role === role && f.selectable) || null;
	}
	/** A collapsed conversation of the list: the action takes all its mails of the folder */
	function foldedThreads(ids) {
		return ids.filter(id => {
			const r = S.rows.find(x => x.id === id);
			return r && r.thread && r.thread.count > 1 && !S.expanded.has(r.thread.key);
		});
	}

	/**
	 * Run an action: at once on the screen, then on the server by batches; refused = back as before with the reason
	 * and « Retry »; done = toast « Undo » 8 s
	 * @param {string} what seen, flag, tag, move, archive, delete, junk, notjunk
	 * @param {number[]} ids Mails
	 * @param {object} p on, add, remove, folder, confirm
	 * @param {object} o quiet (no toast), stay (no move of the selection), label (text of the toast)
	 */
	async function runAction(what, ids, p, o) {
		p = Object.assign({}, p || {});
		o = o || {};
		ids = Array.from(new Set(ids || [])).filter(Boolean);
		if (!ids.length) {
			return null;
		}
		const why = refusal(what, ids);
		if (why) {
			toast(why, { error: true });
			return null;
		}
		const rows = ids.map(rowOf).filter(Boolean);
		if (what === 'delete' && !p.confirm && rows.some(r => (S.folders.get(r.folder) || { folder: {} }).folder.role === 'trash')) {
			// In the trash: removed for good, the number named, no undo
			const ok = await confirmBox(t('InboxDestroyTitle'), th('InboxDestroyText', ids.length), t('InboxDestroyConfirm', ids.length));
			if (!ok) {
				return null;
			}
			p.confirm = 1;
		}
		const leaving = LEAVING.has(what) && !(what === 'move' && rows.every(r => r.folder === p.folder));
		const restore = leaving ? leaveRows(ids, o) : applyLocal(what, rows, p);
		const expand = foldedThreads(ids);
		if (expand.length) {
			p.expand = expand;
		}
		const res = await sendAction(what, ids, p);
		if (!res.count) {
			restore();
			toast(res.error || t('InboxErrorServer', ''), { error: true, action: t('InboxRetry'), onAction: () => runAction(what, ids, p, o) });
			return null;
		}
		if (res.error) {
			// Done in part: the list read again shows the truth, the part done can be undone
			loadList(true);
			toast(res.error, { error: true });
		}
		if (leaving) {
			refreshTree();
		}
		Dolibarr.executeHook(leaving ? 'inbox:mail:moved' : 'inbox:mail:flagged', { action: what, ids, folder: p.folder || 0, count: res.count });
		if (!o.quiet) {
			const text = o.label || actionLabel(what, res.count, p);
			if (res.undo.length) {
				toast(text, { action: t('InboxUndo'), onAction: () => undoAction(res) });
			} else {
				toast(text);
			}
		}
		return res;
	}

	/** Calls by batches; a move to another mailbox gives back what it had no time for: sent again */
	async function sendAction(what, ids, p, undoOf) {
		const out = { count: 0, undo: [], log: [], ids: {}, error: '' };
		let todo = ids.slice();
		let turns = 0;
		while (todo.length && turns++ < 500) {
			const chunk = todo.slice(0, BATCH);
			todo = todo.slice(BATCH);
			const body = Object.assign({ what, ids: chunk }, p);
			if (p.expand) {
				body.expand = p.expand.filter(x => chunk.includes(x));
			}
			if (undoOf) {
				body.undo_of = undoOf;
			}
			let res;
			try {
				res = await post('action', 'run', body);
			} catch (err) {
				out.error = err.message;
				break;
			}
			out.count += res.count || 0;
			out.undo = out.undo.concat(res.undo || []);
			out.log = out.log.concat(res.log || []);
			Object.assign(out.ids, res.ids || {});
			if (res.warning) {
				out.error = res.warning;
			}
			if (res.rest && res.rest.length) {
				if (!res.count) {
					out.error = out.error || res.warning;
					break;
				}
				todo = res.rest.concat(todo);
			}
		}
		return out;
	}

	async function undoAction(res) {
		let failed = '';
		for (const u of res.undo) {
			const r = await sendAction(u.action, u.ids, { on: u.on, add: u.add || [], remove: u.remove || [], folder: u.folder || 0 }, res.log);
			if (r.error && !r.count) {
				failed = r.error;
			}
		}
		await loadList(true);
		refreshTree();
		toast(failed ? failed : t('InboxUndone'), { error: !!failed });
	}

	/** The mails leave the list at once; the selection goes to the next one (or the previous one, setting) */
	function leaveRows(ids, o) {
		const set = new Set(ids);
		const before = { rows: S.rows.slice(), selected: new Set(S.selected), anchor: S.anchor, open: S.openId, total: S.total, cache: Object.assign({}, S.threadCache) };
		const touched = S.openId && set.has(S.openId);
		let next = 0;
		if (touched && !o.stay) {
			const idx = S.rows.map((r, i) => set.has(r.id) ? i : -1).filter(i => i >= 0);
			if (idx.length) {
				const prev = params.after_move === 'previous';
				const after = S.rows.slice(Math.max(...idx) + 1).find(r => !set.has(r.id));
				const above = S.rows.slice(0, Math.min(...idx)).reverse().find(r => !set.has(r.id));
				const pick = prev ? (above || after) : (after || above);
				next = pick ? pick.id : 0;
			}
		}
		S.rows.filter(r => set.has(r.id)).forEach(r => {
			const e = S.folders.get(r.folder);
			if (e) {
				e.folder.total = Math.max(0, (e.folder.total || 0) - 1);
				if (!seenOf(r)) {
					e.folder.unread = Math.max(0, (e.folder.unread || 0) - 1);
				}
			}
		});
		S.rows = S.rows.filter(r => !set.has(r.id));
		for (const k in S.threadCache) {
			S.threadCache[k] = (S.threadCache[k] || []).filter(m => !set.has(m.id));
		}
		S.total = Math.max(0, S.total - (before.rows.length - S.rows.length));
		ids.forEach(id => S.selected.delete(id));
		renderList();
		renderListHead();
		renderTree();
		if (next) {
			select(next);
		} else if (touched || !S.selected.size) {
			showNothing();
		} else {
			paintSelection();
			showMulti();
		}
		renderBulkBar();
		return () => {
			S.rows = before.rows;
			S.selected = before.selected;
			S.anchor = before.anchor;
			S.total = before.total;
			S.threadCache = before.cache;
			renderList();
			renderListHead();
			refreshTree();
			if (before.open && S.selected.size <= 1) {
				openMail(before.open);
			}
			renderBulkBar();
		};
	}

	/** Flags and tags changed on the screen at once; the function given back puts them back */
	function applyLocal(what, rows, p) {
		const saved = rows.map(r => ({ r, seen: r.seen, flagged: r.flagged, tags: (r.tags || []).slice() }));
		const lower = x => String(x).toLowerCase();
		rows.forEach(r => {
			if (what === 'seen') {
				if (!!r.seen !== !!p.on) {
					const e = S.folders.get(r.folder);
					if (e) {
						e.folder.unread = Math.max(0, (e.folder.unread || 0) + (p.on ? -1 : 1));
					}
				}
				r.seen = p.on ? 1 : 0;
			} else if (what === 'flag') {
				r.flagged = p.on ? 1 : 0;
			} else if (what === 'tag') {
				const rm = (p.remove || []).map(lower);
				r.tags = (r.tags || []).filter(k => !rm.includes(lower(k)));
				(p.add || []).forEach(k => {
					if (!r.tags.some(x => lower(x) === lower(k))) {
						r.tags.push(k);
					}
				});
			}
		});
		const paint = () => {
			if (what === 'seen') {
				repaintRows(rows);
				renderTree();
			} else {
				renderList();
			}
			if (S.mail && rows.some(r => r.id === S.mail.id || r === S.mail)) {
				const r = rows.find(x => x.id === S.mail.id) || S.mail;
				S.mail.seen = r.seen;
				S.mail.flagged = r.flagged;
				S.mail.tags = r.tags;
				repaintReadHead();
			}
		};
		paint();
		return () => {
			saved.forEach(s => {
				s.r.seen = s.seen;
				s.r.flagged = s.flagged;
				s.r.tags = s.tags;
			});
			paint();
			refreshTree();
		};
	}

	/** Head of the mail read after a change of its flags or tags (the body stays) */
	function repaintReadHead() {
		if (!S.mail) {
			return;
		}
		const root = mailRoot(S.mail);
		const acts = $('.ibx-readhead__actions', root);
		if (acts && !acts.classList.contains('ibx-js-convoactions')) {
			acts.innerHTML = actionButtons(S.mail);
			bindActions(acts);
		}
		const subject = $('.ibx-readhead__subject', root);
		if (subject) {
			subject.innerHTML = esc(S.mail.subject || t('InboxNoSubject')) + ' ' + tagsHtml({ folder: S.mail.folder, tags: S.mail.tags });
		}
	}

	function actionLabel(what, n, p) {
		if (what === 'move') {
			const e = S.folders.get(p.folder);
			return t('InboxActMoved', n, e ? folderName(e.folder) : '');
		}
		const keys = { seen: p.on ? 'InboxActSeen' : 'InboxActUnseen', flag: p.on ? 'InboxActFlagged' : 'InboxActUnflagged', tag: 'InboxActTagged', archive: 'InboxActArchived',
			delete: p.confirm ? 'InboxActDestroyed' : 'InboxActDeleted', junk: 'InboxActJunk', notjunk: 'InboxActNotJunk' };
		return t(keys[what] || 'InboxActDone', n);
	}

	function confirmBox(title, html, label) {
		return new Promise(resolve => {
			let done = false;
			const dlg = dialog({
				title,
				body: '<p>' + html + '</p>',
				actions: [{ label, danger: true, run: () => {
					done = true;
					resolve(true);
					return true;
				} }],
			});
			dlg.addEventListener('close', () => {
				if (!done) {
					resolve(false);
				}
			});
		});
	}

	/** Mails an action of the keyboard or of the bulk bar acts on: the selection, else the mail read */
	function targetIds() {
		if (S.selected.size) {
			return Array.from(S.selected);
		}
		return S.mail ? [S.mail.id] : [];
	}

	// Short forms used by the buttons, the menus, the keyboard, the gestures
	function doFlag(ids) {
		const rows = ids.map(rowOf).filter(Boolean);
		const on = rows.some(r => !r.flagged);
		return runAction('flag', ids, { on: on ? 1 : 0 }, { quiet: ids.length < 2 });
	}
	function doSeen(ids, seen) {
		const rows = ids.map(rowOf).filter(Boolean);
		const on = seen === undefined ? rows.some(r => !seenOf(r)) : seen;
		return runAction('seen', ids, { on: on ? 1 : 0 }, { quiet: ids.length < 2 });
	}
	function doArchive(ids, o) {
		return runAction('archive', ids, {}, o);
	}
	function doDelete(ids, o) {
		return runAction('delete', ids, {}, o);
	}
	function doJunk(ids, junk, o) {
		return runAction(junk ? 'junk' : 'notjunk', ids, {}, o);
	}
	/** Tag of the place n (keys 1 to 9) set or removed on the mails; 0 = all removed */
	function doTagIndex(ids, n) {
		const rows = ids.map(rowOf).filter(Boolean);
		const a = accountOfRow(rows[0]);
		if (!a) {
			return null;
		}
		if (n === 0) {
			const all = Array.from(new Set(rows.flatMap(r => r.tags || []))).filter(k => k.charAt(0) !== '$' || /^\$label\d$/i.test(k));
			return all.length ? runAction('tag', ids, { remove: all }, { quiet: ids.length < 2 }) : null;
		}
		const def = (a.tags || [])[n - 1];
		return def ? toggleTag(ids, def.keyword) : null;
	}
	function toggleTag(ids, keyword) {
		const rows = ids.map(rowOf).filter(Boolean);
		const has = r => (r.tags || []).some(k => k.toLowerCase() === keyword.toLowerCase());
		const all = rows.every(has);
		// One mail: the tag is seen on it and undone by the same key, no toast (several mails: « Undo »)
		return runAction('tag', ids, all ? { remove: [keyword] } : { add: [keyword] }, { quiet: ids.length < 2 });
	}

	/** Menu of the tags of the mailbox, checked when every mail has it */
	function tagMenu(anchor, ids, at) {
		const rows = ids.map(rowOf).filter(Boolean);
		const a = accountOfRow(rows[0]);
		const defs = a ? (a.tags || []) : [];
		const why = refusal('tag', ids);
		const items = defs.map((d, i) => ({ icon: 'tag', label: d.label, key: i < 9 ? String(i + 1) : '', checked: rows.every(r => (r.tags || []).some(k => k.toLowerCase() === d.keyword.toLowerCase())),
			disabled: !!why, run: () => toggleTag(ids, d.keyword) }));
		if (!items.length) {
			items.push({ note: t('InboxNoTags') });
		}
		items.push({ sep: true });
		items.push({ icon: 'tag-off', label: t('InboxRemoveAllTags'), key: '0', disabled: !!why, run: () => doTagIndex(ids, 0) });
		if (why) {
			items.push({ note: why });
		}
		menu(anchor, items, at);
	}

	/** Target folder picked in a list searched as you type; folders of the mailboxes without « manage » shown greyed */
	function moveDialog(ids) {
		const rows = ids.map(rowOf).filter(Boolean);
		const why = refusal('move', ids);
		if (why) {
			toast(why, { error: true });
			return;
		}
		const here = new Set(rows.map(r => r.folder));
		const options = [];
		S.accounts.forEach(a => a.folders.filter(f => f.selectable).forEach(f => options.push({ id: f.id, account: a, folder: f,
			path: (a.label || a.email) + ' / ' + folderPath(f), ok: a.level === 'manage' && !(here.size === 1 && here.has(f.id)) })));
		const dlg = dialog({
			title: t('InboxMoveTo', ids.length),
			body: '<input type="search" class="ibx-field ibx-js-movesearch" autocomplete="off" placeholder="' + th('InboxSearchFolder') + '" aria-label="' + th('InboxSearchFolder') + '">'
				+ '<div class="ibx-picklist ibx-js-movelist" role="listbox" aria-label="' + th('InboxFolders') + '"></div>',
			actions: [{ label: t('InboxMove'), primary: true, run: d => {
				const cur = $('[aria-selected="true"]', d);
				if (!cur) {
					return false;
				}
				runAction('move', ids, { folder: +cur.dataset.id });
				return true;
			} }],
		});
		const input = $('.ibx-js-movesearch', dlg);
		const list = $('.ibx-js-movelist', dlg);
		const paint = () => {
			const q = input.value.trim().toLowerCase();
			const shown = options.filter(o => !q || o.path.toLowerCase().includes(q) || folderName(o.folder).toLowerCase().includes(q));
			let html = '';
			let lastAcc = 0;
			let first = true;
			shown.forEach(o => {
				if (o.account.id !== lastAcc && S.accounts.length > 1) {
					html += '<div class="ibx-picklist__group" role="presentation">' + esc(accountName(o.account)) + (o.account.level !== 'manage' ? ' · ' + th('InboxReadOnly') : '') + '</div>';
					lastAcc = o.account.id;
				}
				html += '<div class="ibx-picklist__item" role="option" data-id="' + o.id + '"' + (o.ok ? '' : ' aria-disabled="true"') + ' aria-selected="' + (o.ok && first) + '" style="--ibx-depth:' + (o.folder.depth || 0) + '">'
					+ folderIcon(o.folder, false) + ' <span class="ibx-ellipsis">' + esc(folderName(o.folder)) + '</span></div>';
				if (o.ok && first) {
					first = false;
				}
			});
			list.innerHTML = html || '<div class="ibx-muted ibx-picklist__empty">' + th('InboxNoFolderFound') + '</div>';
		};
		const choose = el => {
			$$('[aria-selected="true"]', list).forEach(x => x.setAttribute('aria-selected', 'false'));
			el.setAttribute('aria-selected', 'true');
			scrollWithin(list, el, 'nearest');
		};
		input.addEventListener('input', paint);
		input.addEventListener('keydown', e => {
			const items = $$('.ibx-picklist__item:not([aria-disabled])', list);
			const i = items.findIndex(x => x.getAttribute('aria-selected') === 'true');
			if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
				e.preventDefault();
				if (items.length) {
					choose(items[(i + (e.key === 'ArrowDown' ? 1 : items.length - 1) + items.length) % items.length]);
				}
			} else if (e.key === 'Enter') {
				e.preventDefault();
				if (i >= 0) {
					$('.ibx-dialog__foot .ibx-btn--primary', dlg).click();
				}
			}
		});
		list.addEventListener('click', e => {
			const it = e.target.closest('.ibx-picklist__item');
			if (it && !it.hasAttribute('aria-disabled')) {
				choose(it);
			}
		});
		list.addEventListener('dblclick', e => {
			const it = e.target.closest('.ibx-picklist__item');
			if (it && !it.hasAttribute('aria-disabled')) {
				choose(it);
				$('.ibx-dialog__foot .ibx-btn--primary', dlg).click();
			}
		});
		paint();
		input.focus();
	}
	function folderDepth(f) {
		let d = 0;
		for (let p = f.parent; p && d < 20; p = (S.folders.get(p) || { folder: {} }).folder.parent) {
			d++;
		}
		return d;
	}
	/** « Clients / 2026 »: path of a folder by its parents in the tree */
	function folderPath(f) {
		const names = [folderName(f)];
		for (let p = f.parent; p; p = (S.folders.get(p) || { folder: {} }).folder.parent) {
			const e = S.folders.get(p);
			if (!e) {
				break;
			}
			names.unshift(folderName(e.folder));
		}
		return names.join(' / ');
	}

	/** Actions on a list of mails, shared by the menu of a row, the bulk bar and the reading pane */
	function mailActionItems(ids, anchor) {
		const rows = ids.map(rowOf).filter(Boolean);
		const first = rows[0];
		const manage = refusal('move', ids);
		const role = first ? (S.folders.get(first.folder) || { folder: {} }).folder.role : '';
		const inTrash = role === 'trash';
		const inJunk = role === 'junk';
		const anyUnflagged = rows.some(r => !r.flagged);
		const items = [
			{ id: 'flag', icon: anyUnflagged ? 'star' : 'star-on', label: t(anyUnflagged ? 'InboxFlag' : 'InboxUnflag'), key: 'S', run: () => doFlag(ids) },
			{ id: 'assign', icon: 'user-plus', label: t(rows.length && rows.every(r => (r.assignees || []).includes(S.me)) ? 'InboxUnassignMe' : 'InboxAssignMe'), key: t('InboxKeySpace'),
				disabled: !!assignRefusal(ids), reason: assignRefusal(ids), run: () => doAssignMe(ids) },
			{ id: 'assignto', icon: 'user-circle', label: t('InboxAssignPerson'), disabled: !!assignRefusal(ids), reason: assignRefusal(ids), run: () => assignDialog(ids) },
			{ id: 'watch', icon: 'watch', label: t(rows.length && rows.every(r => r.watched) ? 'InboxUnwatchThread' : 'InboxWatchThread'), key: 'W', run: () => doThreadMark(ids, 'watch') },
			{ id: 'ignore', icon: 'ignore', label: t(rows.length && rows.every(r => r.ignored) ? 'InboxUnignoreThread' : 'InboxIgnoreThread'), key: 'K', disabled: !!manage, reason: manage, run: () => doThreadMark(ids, 'ignore') },
			{ id: 'tag', icon: 'tag', label: t('InboxTagMenu'), key: '1…9', disabled: !!manage, reason: manage, run: () => tagMenu(anchor || S.el, ids) },
			{ sep: true },
			{ id: 'archive', icon: 'archive', label: t('InboxArchive'), key: 'A', disabled: !!manage || role === 'archive', reason: manage, run: () => doArchive(ids) },
			{ id: 'move', icon: 'folder-move', label: t('InboxMoveToMenu'), key: 'Maj+M', disabled: !!manage, reason: manage, run: () => moveDialog(ids) },
			inJunk ? { id: 'notjunk', icon: 'inbox', label: t('InboxNotJunk'), key: 'Maj+J', disabled: !!manage, reason: manage, run: () => doJunk(ids, false) }
				: { id: 'junk', icon: 'junk', label: t('InboxJunk'), key: 'J', disabled: !!manage, reason: manage, run: () => doJunk(ids, true) },
			{ id: 'delete', icon: 'delete', label: t(inTrash ? 'InboxDestroy' : 'InboxDelete'), key: 'Suppr', disabled: !!manage, reason: manage, danger: true, run: () => doDelete(ids) },
		];
		return items;
	}

	// ---------- Assignment (lot W4 1.9.0, webmail §5.4 quinquies): who handles a mail ----------
	function learnPeople(map) {
		Object.keys(map || {}).forEach(k => {
			S.people[+k] = map[k];
		});
	}
	function personName(id) {
		return id === S.me ? t('InboxMe') : (S.people[id] || '#' + id);
	}
	/** Initials of the people assigned (mine in the accent), their names in the title and for the screen readers */
	function assigneesHtml(list, cls) {
		list = list || [];
		if (!list.length) {
			return '';
		}
		const text = t('InboxAssignedTo', list.map(personName).join(', '));
		return ' <span class="ibx-assignees' + (cls ? ' ' + cls : '') + '" title="' + esc(text) + '">'
			+ list.slice(0, 3).map(id => avatar(S.people[id] || '', 'u' + id, 'ibx-avatar--xs' + (id === S.me ? ' is-me' : ''))).join('')
			+ (list.length > 3 ? '<span class="ibx-assignees__more" aria-hidden="true">+' + (list.length - 3) + '</span>' : '')
			+ '<span class="ibx-sr-only">' + esc(text) + '</span></span>';
	}
	/** Line « Assigned to » of the reading: full names, « Me », assign me / remove me, choose the people */
	function assignLine(ids, list, hasRef) {
		const mine = list.includes(S.me);
		return '<div class="ibx-readhead__line ibx-assignline ibx-js-assignline" data-ids="' + ids.join(',') + '">'
			// Nobody: « Not assigned » alone (« Assigned to Nobody » would read like a name)
			+ (list.length ? '<span class="ibx-assignline__label">' + th('InboxAssignedToLabel') + '</span>' : '')
			+ (list.length ? list.map(id => '<span class="ibx-assignline__person' + (id === S.me ? ' is-me' : '') + '">' + avatar(S.people[id] || '', 'u' + id, 'ibx-avatar--xs' + (id === S.me ? ' is-me' : ''))
				+ esc(personName(id)) + '</span>').join('') : '<span class="ibx-muted">' + th('InboxAssignedNobody') + '</span>')
			+ (hasRef ? '<span class="ibx-assignline__actions"><button type="button" class="ibx-btn ibx-btn--sm ibx-btn--ghost ibx-js-assignme" aria-keyshortcuts="Space">' + ic('user-plus') + ' ' + th(mine ? 'InboxUnassignMe' : 'InboxAssignMe') + '</button>'
				+ '<button type="button" class="ibx-btn ibx-btn--sm ibx-btn--ghost ibx-js-assignto" aria-haspopup="dialog">' + th('InboxAssignEdit') + '</button></span>'
				: '<span class="ibx-muted">' + th('InboxErrorNoMessageId') + '</span>')
			+ '</div>';
	}
	/** Why these mails cannot be assigned ('' = they can): no Message-ID, the key of an assignment */
	function assignRefusal(ids) {
		const rows = ids.map(rowOf).filter(Boolean);
		return rows.length && rows.every(r => r.has_mid === 0 || r.has_message_id === false) ? t('InboxErrorNoMessageId') : '';
	}
	/** The rows, the open mail and the line « Assigned to » painted again after a change */
	function paintAssign() {
		renderList();
		const line = $('.ibx-pane--read .ibx-js-assignline', S.el);
		if (line) {
			let fresh = '';
			if (S.thread) {
				const row = S.rows.find(x => x.id === S.openId);
				fresh = row ? assignLine([row.id], row.assignees || [], row.has_mid !== 0) : '';
			} else if (S.mail) {
				fresh = assignLine([S.mail.id], S.mail.assignees || [], S.mail.has_message_id !== false);
			}
			if (fresh) {
				line.outerHTML = fresh;
			}
		}
		renderBulkBar();
	}
	/** Space: assign me, or remove me when I am on all of them */
	function doAssignMe(ids) {
		const rows = ids.map(rowOf).filter(Boolean);
		if (!rows.length || !S.me) {
			return null;
		}
		const all = rows.every(r => (r.assignees || []).includes(S.me));
		return runAssign(ids, all ? [] : [S.me], all ? [S.me] : []);
	}
	function assignLabel(add, remove, n) {
		if (add.length === 1 && !remove.length) {
			return add[0] === S.me ? t('InboxActAssignedMe', n) : t('InboxActAssignedTo', n, personName(add[0]));
		}
		if (remove.length === 1 && !add.length) {
			return remove[0] === S.me ? t('InboxActUnassignedMe', n) : t('InboxActUnassignedFrom', personName(remove[0]), n);
		}
		return t('InboxActAssignChanged', n);
	}
	/**
	 * Assign or remove people: at once on the screen, then on the server (nothing on the mail server); refused =
	 * back as before; done = toast « Undo »
	 */
	async function runAssign(ids, add, remove, o) {
		o = o || {};
		ids = Array.from(new Set(ids || [])).filter(Boolean);
		const why = assignRefusal(ids);
		if (!ids.length || why) {
			if (why) {
				toast(why, { error: true });
			}
			return null;
		}
		const touched = new Set(ids.map(rowOf).filter(Boolean));
		if (S.mail && ids.includes(S.mail.id)) {
			touched.add(S.mail);
		}
		const saved = Array.from(touched).map(r => ({ r, list: (r.assignees || []).slice() }));
		touched.forEach(r => {
			const cur = (r.assignees || []).filter(u => !remove.includes(u));
			r.assignees = cur.concat(add.filter(u => !cur.includes(u)));
		});
		paintAssign();
		const expand = foldedThreads(ids);
		let res;
		try {
			res = await post('assign', 'set', { ids, add, remove, expand });
		} catch (err) {
			saved.forEach(x => {
				x.r.assignees = x.list;
			});
			paintAssign();
			toast(err.message, { error: true, action: t('InboxRetry'), onAction: () => runAssign(ids, add, remove, o) });
			return null;
		}
		learnPeople(res.people);
		// The truth of the server for the mails asked (a folded conversation shows the people of all its mails: list read again)
		touched.forEach(r => {
			if (!expand.includes(r.id)) {
				r.assignees = (res.assignees || {})[r.id] || [];
			}
		});
		if (expand.length) {
			await loadList(true);
		}
		paintAssign();
		Dolibarr.executeHook('inbox:mail:assign', { ids, add, remove, count: res.count });
		if (res.skipped) {
			toast(t('InboxAssignSkipped', res.skipped), { error: true });
		}
		if (!o.quiet && res.count) {
			toast(assignLabel(add, remove, res.count), { action: t('InboxUndo'), onAction: () => undoAssign(saved) });
		}
		return res;
	}
	/** Undo: each mail gets back its people (grouped by change, one call per group) */
	async function undoAssign(saved) {
		const groups = new Map();
		saved.filter(x => x.r !== S.mail || !saved.some(y => y !== x && y.r.id === x.r.id)).forEach(x => {
			const now = x.r.assignees || [];
			const add = x.list.filter(u => !now.includes(u));
			const remove = now.filter(u => !x.list.includes(u));
			if (!add.length && !remove.length) {
				return;
			}
			const k = add.join(',') + '|' + remove.join(',');
			if (!groups.has(k)) {
				groups.set(k, { ids: [], add, remove });
			}
			groups.get(k).ids.push(x.r.id);
		});
		for (const g of groups.values()) {
			await runAssign(g.ids, g.add, g.remove, { quiet: true });
		}
		toast(t('InboxUndone'));
	}
	/**
	 * « Assign a person »: the people who can read the mailbox (of every mailbox of the selection), searched as you
	 * type; a tick assigns, no tick removes, at once
	 */
	async function assignDialog(ids) {
		const rows = ids.map(rowOf).filter(Boolean);
		const why = assignRefusal(ids);
		if (!rows.length || why) {
			if (why) {
				toast(why, { error: true });
			}
			return;
		}
		const accounts = Array.from(new Set(rows.map(r => accountOfRow(r)).filter(Boolean).map(a => a.id)));
		let people;
		try {
			const lists = await Promise.all(accounts.map(id => api('assign', 'people', { id })));
			people = lists[0].people.filter(p => lists.every(l => l.people.some(x => x.id === p.id)));
		} catch (err) {
			toast(err.message, { error: true });
			return;
		}
		people.forEach(p => {
			S.people[p.id] = p.name;
		});
		// Me first, then by name
		people.sort((a, b) => (b.id === S.me) - (a.id === S.me) || a.name.localeCompare(b.name));
		const state = id => {
			const on = rows.filter(r => (r.assignees || []).includes(id)).length;
			return on === 0 ? 'false' : (on === rows.length ? 'true' : 'mixed');
		};
		const d = dialog({
			title: t('InboxAssignPerson'),
			noCancel: true,
			body: (rows.length > 1 ? '<p class="ibx-formrow__help">' + th('InboxSelectedCount', rows.length) + '</p>' : '')
				+ '<input type="search" class="ibx-field ibx-js-pq" placeholder="' + th('InboxAssignSearch') + '" aria-label="' + th('InboxAssignSearch') + '" autocomplete="off">'
				+ '<ul class="ibx-assignpick" role="list">' + people.map(p => '<li data-name="' + esc(p.name.toLowerCase()) + '"><label class="ibx-assignpick__row">'
					+ '<input type="checkbox" data-user="' + p.id + '"> ' + avatar(p.name, 'u' + p.id, 'ibx-avatar--xs' + (p.id === S.me ? ' is-me' : '')) + ' <span>' + esc(p.id === S.me ? t('InboxMe') + ' (' + p.name + ')' : p.name) + '</span></label></li>').join('')
				+ '</ul><p class="ibx-muted ibx-js-pnone" hidden>' + th('InboxAssignNoMatch') + '</p>',
			actions: [{ label: t('InboxCloseWindow'), primary: true, run: () => true }],
		});
		const paint = () => $$('[data-user]', d).forEach(cb => {
			const st = state(+cb.dataset.user);
			cb.checked = st === 'true';
			cb.indeterminate = st === 'mixed';
		});
		paint();
		d.addEventListener('change', async e => {
			const cb = e.target.closest('[data-user]');
			if (!cb) {
				return;
			}
			const id = +cb.dataset.user;
			await runAssign(ids, cb.checked ? [id] : [], cb.checked ? [] : [id]);
			paint();
		});
		// Every tick is saved at once: Escape only closes
		d.addEventListener('keydown', e => {
			if (e.key === 'Escape') {
				e.preventDefault();
				d.close();
			}
		});
		const q = $('.ibx-js-pq', d);
		q.addEventListener('input', () => {
			const v = q.value.trim().toLowerCase();
			let n = 0;
			$$('li[data-name]', d).forEach(li => {
				li.hidden = v !== '' && !li.dataset.name.includes(v);
				n += li.hidden ? 0 : 1;
			});
			$('.ibx-js-pnone', d).hidden = n > 0;
		});
		q.focus();
	}

	// ---------- Threads ignored or watched, newsletters (lot W4 1.11.0, B4, B8) ----------
	function threadMarks(r) {
		const e = S.folders.get(r.folder);
		const legit = r.junk_hint === 'legit' && e && e.folder.role === 'junk';
		return (legit ? '<span class="ibx-threadmark ibx-junklegit" title="' + th('InboxJunkLegitShort') + '">' + ic('warning') + '<span class="ibx-sr-only">' + th('InboxJunkLegitShort') + ' </span></span>' : '')
			+ (r.watched ? '<span class="ibx-threadmark ibx-threadmark--watch" title="' + th('InboxThreadWatched') + '">' + ic('watch') + '<span class="ibx-sr-only">' + th('InboxThreadWatched') + ' </span></span>' : '')
			+ (r.ignored ? '<span class="ibx-threadmark" title="' + th('InboxThreadIgnored') + '">' + ic('ignore') + '<span class="ibx-sr-only">' + th('InboxThreadIgnored') + ' </span></span>' : '');
	}
	/**
	 * W: watch the threads of these mails (for me), K: ignore them (the whole mailbox: the answers arrive read). On
	 * all of them, or off when all of them are already
	 */
	async function doThreadMark(ids, mode) {
		const rows = Array.from(new Set(ids)).map(rowOf).filter(Boolean);
		if (!rows.length) {
			return;
		}
		if (mode === 'ignore') {
			const why = refusal('tag', rows.map(r => r.id));
			if (why) {
				toast(why, { error: true });
				return;
			}
		}
		const field = mode === 'watch' ? 'watched' : 'ignored';
		const on = !rows.every(r => r[field]);
		// One call per thread
		const seen = new Set();
		const todo = rows.filter(r => {
			const k = r.account + '|' + (r.thread ? r.thread.key : (r.tkey || r.id));
			if (seen.has(k)) {
				return false;
			}
			seen.add(k);
			return true;
		}).slice(0, 50);
		let read = 0;
		let failed = '';
		for (const r of todo) {
			try {
				const res = await post('thread', 'mark', { id: r.id, mode, on: on ? 1 : 0 });
				read += res.read || 0;
			} catch (err) {
				failed = err.message;
			}
		}
		if (failed) {
			toast(failed, { error: true });
		} else {
			toast(t(mode === 'watch' ? (on ? 'InboxThreadWatchedDone' : 'InboxThreadUnwatchedDone') : (on ? 'InboxThreadIgnoredDone' : 'InboxThreadUnignoredDone'), todo.length)
				+ (read ? ' ' + t('InboxThreadMarkedRead', read) : ''), { action: t('InboxUndo'), onAction: () => doThreadMark(todo.map(x => x.id), mode) });
		}
		await loadList(true);
		if (read) {
			refreshTree();
		}
	}
	/** Unsubscribe: the sender learns that the address is read, said before (decision W4 point 5) */
	async function unsubscribe(m, btn) {
		if (btn.getAttribute('aria-disabled') === 'true') {
			toast(btn.title, { error: true });
			return;
		}
		const ok = await confirmBox(t('InboxUnsubscribeTitle'), th('InboxUnsubscribeText', m.from.name || m.from.email), t('InboxUnsubscribe'));
		if (!ok) {
			return;
		}
		btn.setAttribute('aria-disabled', 'true');
		try {
			await post('mail', 'unsubscribe', { id: m.id });
		} catch (err) {
			btn.removeAttribute('aria-disabled');
			toast(err.message, { error: true });
			return;
		}
		const bar = btn.closest('.ibx-js-unsubbar');
		if (bar) {
			$('.ibx-banner__text', bar).innerHTML = th('InboxUnsubscribed', m.from.name || m.from.email);
			btn.remove();
		}
		toast(t('InboxUnsubscribed', m.from.name || m.from.email), m.level === 'manage' ? { action: t('InboxDelete'), onAction: () => doDelete([m.id]) } : {});
	}

	// ---------- Bandeau Dolibarr of the reading (lot W5 1.13.0, analyse-interface §6 bloc 3) ----------
	function dolibarSlot(mailId, thread) {
		return '<section class="ibx-dolibar ibx-js-dolibar" data-mail="' + mailId + '" data-thread="' + (thread ? 1 : 0) + '" aria-label="' + th('InboxDolibarr') + '"></section>';
	}
	/**
	 * Where the bandeau goes: on the right of the body (a column of the mail, not a pane) or on top. Auto: on the
	 * right when the reading pane has 60 rem (a mail of 600 px and the column), never on a phone
	 */
	function dolibarRight() {
		const pane = S.el && $('.ibx-pane--read', S.el);
		if (!pane || isPhone()) {
			return false;
		}
		const pos = params.dolibar_position || 'auto';
		if (pos !== 'auto') {
			return pos === 'right';
		}
		const rem = parseFloat(getComputedStyle(document.documentElement).fontSize) || 16;
		return pane.clientWidth / rem >= 60;
	}
	function placeDolibar() {
		const pane = S.el && $('.ibx-pane--read', S.el);
		if (!pane) {
			return;
		}
		const right = dolibarRight();
		if (pane.dataset.dolibar !== (right ? 'right' : 'top')) {
			pane.dataset.dolibar = right ? 'right' : 'top';
			$$('.ibx-js-dolibar', pane).forEach(b => {
				if (b._data) {
					paintDolibar(b);
				}
			});
		}
		// In a conversation the column stays under the head of the thread while the mails scroll
		const head = $('.ibx-convo > .ibx-readhead', pane);
		if (head) {
			head.parentNode.style.setProperty('--ibx-head-h', head.offsetHeight + 'px');
		}
	}
	function setDolibarPosition(pos) {
		saveParam('dolibar_position', pos);
		placeDolibar();
	}
	async function loadDolibar(box) {
		if (!box) {
			return;
		}
		box.innerHTML = '<div class="ibx-dolibar__head"><span class="ibx-skeleton" style="width:40%"></span></div>';
		let res;
		try {
			res = await api('dolibarr', 'panel', { id: +box.dataset.mail, thread: +box.dataset.thread });
		} catch (err) {
			if (box.isConnected) {
				box.innerHTML = '<div class="ibx-dolibar__head ibx-muted">' + ic('warning') + ' ' + esc(err.message) + ' <button type="button" class="ibx-btn ibx-btn--sm ibx-js-dolretry">' + th('InboxRetry') + '</button></div>';
				$('.ibx-js-dolretry', box).addEventListener('click', () => loadDolibar(box));
			}
			return;
		}
		if (!box.isConnected) {
			return;
		}
		box._data = res;
		paintDolibar(box);
		if (!box._bound) {
			box._bound = true;
			box.addEventListener('click', e => dolibarClick(box, e));
		}
	}
	/** A piece as a pill: icon, ref, thirdparty, status, link to its card; « not accessible » without anything else */
	function pieceHtml(d, unlink) {
		const inner = d.readable
			? '<a class="ibx-piece__link" href="' + esc(d.url || '#') + '" target="_blank" rel="noopener" title="' + esc(d.kind + ' ' + (d.ref || '') + (d.status ? ' · ' + d.status : '')) + '">'
				+ ic(d.icon) + '<b>' + esc(d.ref || '') + '</b>' + (d.label ? ' <span class="ibx-muted">' + esc(d.label) + '</span>' : '') + (d.status ? ' <span class="ibx-piece__status">' + esc(d.status) + '</span>' : '') + '</a>'
			: '<span class="ibx-piece__link ibx-muted">' + ic(d.icon) + esc(d.kind) + ' · ' + th('InboxPieceNotReadable') + '</span>';
		return '<span class="ibx-piece"' + (d.readable && d.type ? ' data-type="' + esc(d.type) + '" data-id="' + d.id + '"' : '') + '>' + inner + (unlink ? '<button type="button" class="ibx-piece__x ibx-js-unlink" data-link="' + unlink + '" title="' + th('InboxUnlink') + '" aria-label="' + esc(t('InboxUnlinkPiece', d.ref || d.kind)) + '">' + ic('close') + '</button>' : '') + '</span>';
	}
	function linkify(text) {
		return esc(text).replace(/(https?:\/\/[^\s<]+)/g, '<a href="$1" target="_blank" rel="noopener noreferrer">$1</a>').replace(/\n/g, '<br>');
	}
	/**
	 * Alerts of the other modules (an imitation...) go with the banners of the mail, above its body, never in the
	 * column: the red one first
	 */
	function showDolAlerts(box, alerts) {
		const reading = box.closest('.ibx-reading');
		if (!reading) {
			return;
		}
		let holder = $(':scope > .ibx-readbanners', reading);
		$$('.ibx-js-dolalert', reading).forEach(x => x.remove());
		if (!alerts.length) {
			return;
		}
		if (!holder) {
			holder = document.createElement('div');
			holder.className = 'ibx-readbanners';
			$(':scope > .ibx-readhead', reading).after(holder);
		}
		const sorted = alerts.slice().sort((a, b) => (b.level === 'danger') - (a.level === 'danger'));
		holder.insertAdjacentHTML('afterbegin', sorted.map(a => banner(a.level, a.level === 'danger' ? 'spoof' : 'warning', esc(a.text)).replace('class="ibx-banner', 'class="ibx-js-dolalert ibx-banner')).join(''));
	}
	function paintDolibar(box) {
		const d = box._data;
		const folded = params.dolibar_folded === '1';
		const soc = d.sender.soc && d.sender.soc.readable ? d.sender.soc : null;
		const summary = [soc ? soc.ref : '', d.links.length + d.extra.length ? t('InboxPiecesCount', d.links.length + d.extra.length) : '', d.notes.length ? t('InboxNotesCount', d.notes.length) : '']
			.filter(Boolean).join(' · ');
		showDolAlerts(box, d.alerts);
		let html = '';
		const right = dolibarRight();
		html += '<button type="button" class="ibx-dolibar__head ibx-js-doltoggle" aria-expanded="' + !folded + '" title="' + th(folded ? 'InboxDolibarrUnfold' : 'InboxDolibarrFold') + '">'
			+ ic(right ? (folded ? 'chevron-left' : 'chevron-right') : (folded ? 'chevron-right' : 'chevron-down')) + ic('dolibarr')
			+ '<b>' + th('InboxDolibarr') + '</b>' + (summary ? ' <span class="ibx-muted ibx-ellipsis">' + esc(summary) + '</span>' : '') + '</button>';
		if (!folded) {
			const sender = [];
			if (d.sender.soc) {
				sender.push(pieceHtml(d.sender.soc, 0) + (d.sender.by === 'domain' ? ' <span class="ibx-muted">' + th('InboxKnownByDomain') + '</span>' : ''));
			}
			if (d.sender.contact) {
				sender.push(pieceHtml(d.sender.contact, 0));
			}
			if (d.sender.user) {
				sender.push('<span class="ibx-piece"><span class="ibx-piece__link">' + ic('account') + esc(t('InboxDolibarrUser', d.sender.user.name)) + '</span></span>');
			}
			const pieces = d.links.map(l => pieceHtml(l, l.link)).concat(d.extra.map(x => pieceHtml(x, 0)));
			const canWrite = !!d.level;
			html += '<div class="ibx-dolibar__body">'
				+ '<div class="ibx-dolibar__row"><span class="ibx-dolibar__label">' + th('InboxSender') + '</span><span class="ibx-dolibar__items">'
				+ (sender.length ? sender.join('') : '<span class="ibx-muted">' + th('InboxSenderUnknown') + '</span>') + '</span></div>'
				+ '<div class="ibx-dolibar__row"><span class="ibx-dolibar__label">' + th('InboxPiecesLinked') + '</span><span class="ibx-dolibar__items">'
				+ (pieces.length ? pieces.join('') : '<span class="ibx-muted">' + th('InboxNoPiece') + '</span>')
				+ (canWrite ? '<span class="ibx-dolibar__tools"><button type="button" class="ibx-btn ibx-btn--sm ibx-btn--ghost ibx-js-linkpiece" aria-haspopup="dialog">' + ic('plus') + ' ' + th('InboxLinkPiece') + '</button>'
					+ ((d.create || []).length ? '<button type="button" class="ibx-btn ibx-btn--sm ibx-btn--ghost ibx-js-createpiece" aria-haspopup="dialog">' + ic('compose') + ' ' + th('InboxCreatePiece') + ' ' + ic('chevron-down') + '</button>' : '') + '</span>' : '') + '</span></div>'
				+ ((d.actions || []).length ? '<div class="ibx-dolibar__row"><span class="ibx-dolibar__label">' + th('InboxModuleActions') + '</span><span class="ibx-dolibar__items">'
					+ d.actions.map(a => '<a class="ibx-btn ibx-btn--sm" href="' + esc(a.url) + '" target="_blank" rel="noopener">' + ic(a.icon) + ' ' + esc(a.label) + '</a>').join('') + '</span></div>' : '')
				+ '<div class="ibx-dolibar__row ibx-dolibar__notes"><span class="ibx-dolibar__label">' + th('InboxNotes') + '</span><div class="ibx-dolibar__items ibx-notes">'
				+ (d.notes.length ? d.notes.map((n, i) => noteHtml(n, n.mine, d.notes[i - 1])).join('') : '<p class="ibx-notes__empty">' + th('InboxNotesEmpty') + '</p>')
				+ '</div>'
				+ (canWrite ? '<div class="ibx-note__new">' + composerHtml('ibx-js-newnote', 'ibx-newnote-' + box.dataset.mail, '', th('InboxNotePlaceholder'), true) + '</div>' : '')
				+ '</div></div>';
		}
		box.innerHTML = html;
		hydrateUserPhotos(box);
		box.classList.toggle('is-folded', folded);
		const reading = box.closest('.ibx-reading');
		if (reading) {
			reading.classList.toggle('is-dolibar-folded', folded);
		}
		placeDolibar();
		// On the right, the notes read as a thread: the last one in view, the field to write under it
		const thread = $('.ibx-notes', box);
		if (thread && dolibarRight()) {
			thread.scrollTop = thread.scrollHeight;
		}
		if (!box._typing) {
			// The field grows with its text (up to 6 lines); the button says when there is nothing to save
			box._typing = true;
			box.addEventListener('input', e => {
				const el = e.target.closest('.ibx-composer__input');
				if (el) {
					growComposer(el);
				}
			});
			box.addEventListener('keydown', e => {
				const el = e.target.closest('.ibx-composer__input');
				if (el && e.key === 'Enter' && !e.shiftKey && !e.isComposing) {
					e.preventDefault();
					const btn = $('.ibx-composer__send', el.closest('.ibx-composer'));
					if (btn) {
						btn.click();
					}
				} else if (el && e.key === 'Escape' && el.classList.contains('ibx-js-noteedittext')) {
					e.preventDefault();
					paintDolibar(box);
				}
			});
		}
	}
	/**
	 * A note as a text message: the picture of its author on the left (its photo of Dolibarr, else its initials), a
	 * bubble on the whole width (mine in the accent), « ⋯ » in its corner, author and date under it. The next notes of
	 * the same author within the hour follow without the picture.
	 */
	function noteHtml(n, canChange, prev) {
		const cont = !!prev && prev.author === n.author && n.date - prev.date < 3600;
		const who = n.mine ? t('InboxMe') : (n.author_name || '?');
		return '<article class="ibx-msg' + (n.mine ? ' is-mine' : '') + (cont ? ' is-cont' : '') + '" data-note="' + n.id + '" aria-label="' + esc(who) + '">'
			+ '<span class="ibx-msg__avatar">' + (cont ? '' : '<span class="ibx-avatar' + (n.photo ? ' ibx-js-userphoto" data-photo="' + esc(n.photo) : '') + '" style="--ibx-avatar-h:' + hue('u' + n.author) + '"'
				+ ' title="' + esc(n.author_name || '') + '" aria-hidden="true">' + esc(initials(n.author_name || '?', '')) + '</span>') + '</span>'
			+ '<div class="ibx-msg__main">'
			+ '<div class="ibx-msg__bubble"><div class="ibx-note__text">' + linkify(n.text) + '</div>'
			+ (canChange ? '<button type="button" class="ibx-msg__more ibx-js-notemenu" aria-haspopup="menu" title="' + th('InboxNoteActions') + '" aria-label="' + th('InboxNoteActions') + '">' + ic('more') + '</button>' : '')
			+ '</div>'
			+ '<div class="ibx-msg__meta">' + (cont ? '' : '<span class="ibx-msg__who">' + esc(who) + '</span>')
			+ '<time datetime="' + new Date(n.date * 1000).toISOString() + '" title="' + esc(longDate(n.date)) + '">' + esc(longDate(n.date)) + '</time>'
			+ (n.edited ? '<span>' + th('InboxNoteEdited') + '</span>' : '')
			+ '</div></div></article>';
	}
	/** Photos of the users: loaded aside, shown once there (the initials stay when there is none) */
	function hydrateUserPhotos(root) {
		$$('.ibx-js-userphoto:not([data-done])', root).forEach(el => {
			el.dataset.done = '1';
			const img = new Image();
			img.onload = () => {
				el.style.backgroundImage = 'url("' + img.src + '")';
				el.classList.add('has-picture');
			};
			img.src = el.dataset.photo;
		});
	}
	/** One box to write: the field grows, the send button inside it, a short help under it */
	function composerHtml(cls, id, value, placeholder, hint) {
		return '<div class="ibx-composer"><label class="ibx-sr-only" for="' + id + '">' + th('InboxNoteAdd') + '</label>'
			+ '<textarea class="ibx-composer__input ' + cls + '" id="' + id + '" rows="1" maxlength="10000" placeholder="' + placeholder + '">' + esc(value) + '</textarea>'
			+ (hint ? '<button type="button" class="ibx-btn ibx-btn--icon ibx-composer__send ibx-js-noteadd"' + (value ? '' : ' aria-disabled="true"') + ' title="' + th('InboxNoteAdd') + '" aria-label="' + th('InboxNoteAdd') + '">' + ic('sent') + '</button>' : '')
			+ '</div>' + (hint ? '<p class="ibx-composer__hint" id="' + id + '-hint">' + th('InboxNoteHint') + '</p>' : '');
	}
	function growComposer(el) {
		el.style.height = 'auto';
		el.style.height = Math.min(el.scrollHeight + 2, parseFloat(getComputedStyle(el).lineHeight) * 6 + 16) + 'px';
		const btn = $('.ibx-composer__send', el.closest('.ibx-composer'));
		if (btn) {
			if (el.value.trim()) {
				btn.removeAttribute('aria-disabled');
			} else {
				btn.setAttribute('aria-disabled', 'true');
			}
		}
	}
	async function noteDelete(box, noteId) {
		const ok = await confirmBox(t('InboxNoteDelete'), th('InboxNoteDeleteText'), t('InboxNoteDelete'));
		if (!ok) {
			return;
		}
		try {
			await post('dolibarr', 'notedelete', { note: noteId });
		} catch (err) {
			toast(err.message, { error: true });
			return;
		}
		loadDolibar(box);
	}
	function noteEdit(box, noteEl, note) {
		const text = $('.ibx-note__text', noteEl);
		text.innerHTML = composerHtml('ibx-js-noteedittext', 'ibx-noteedit-' + note.id, note.text, '', false)
			+ '<div class="ibx-note__editbar"><button type="button" class="ibx-btn ibx-btn--sm ibx-js-notecancel">' + th('InboxCancel') + '</button>'
			+ '<button type="button" class="ibx-btn ibx-btn--sm ibx-btn--primary ibx-js-notesave">' + th('InboxSave') + '</button></div>';
		const area = $('.ibx-js-noteedittext', text);
		growComposer(area);
		area.focus();
		area.setSelectionRange(area.value.length, area.value.length);
	}
	async function dolibarClick(box, e) {
		const mailId = +box.dataset.mail;
		if (e.target.closest('.ibx-js-doltoggle')) {
			saveParam('dolibar_folded', params.dolibar_folded === '1' ? '0' : '1');
			paintDolibar(box);
			return;
		}
		if (e.target.closest('.ibx-js-linkpiece')) {
			linkDialog(box);
			return;
		}
		if (e.target.closest('.ibx-js-createpiece')) {
			createDialog(box);
			return;
		}
		const x = e.target.closest('.ibx-js-unlink');
		if (x) {
			const l = box._data.links.find(k => k.link === +x.dataset.link);
			try {
				await post('dolibarr', 'unlink', { link: +x.dataset.link });
			} catch (err) {
				toast(err.message, { error: true });
				return;
			}
			await loadDolibar(box);
			if (l) {
				toast(t('InboxUnlinked', l.ref || l.kind), { action: t('InboxUndo'), onAction: async () => {
					await post('dolibarr', 'link', { id: l.mail || mailId, type: l.type, element: l.id });
					loadDolibar(box);
				} });
			}
			return;
		}
		if (e.target.closest('.ibx-js-noteadd')) {
			const area = $('.ibx-js-newnote', box);
			const text = area.value.trim();
			if (!text) {
				area.focus();
				return;
			}
			// What was typed stays in the field when the save fails
			try {
				await post('dolibarr', 'note', { id: mailId, text });
			} catch (err) {
				toast(err.message, { error: true });
				return;
			}
			await loadDolibar(box);
			return;
		}
		const noteEl = e.target.closest('[data-note]');
		if (!noteEl) {
			return;
		}
		const noteId = +noteEl.dataset.note;
		const note = box._data.notes.find(n => n.id === noteId);
		const more = e.target.closest('.ibx-js-notemenu');
		if (more && note) {
			menu(more, [
				{ icon: 'compose', label: t('InboxNoteEdit'), run: () => noteEdit(box, noteEl, note) },
				{ sep: true },
				{ icon: 'delete', label: t('InboxNoteDelete'), danger: true, run: () => noteDelete(box, noteId) },
			]);
		} else if (e.target.closest('.ibx-js-notecancel')) {
			paintDolibar(box);
		} else if (e.target.closest('.ibx-js-notesave')) {
			try {
				await post('dolibarr', 'noteedit', { note: noteId, text: $('.ibx-js-noteedittext', noteEl).value });
			} catch (err) {
				toast(err.message, { error: true });
				return;
			}
			loadDolibar(box);
		}
	}
	/** The Dolibarr part of the mail of an element (reading pane, or the conversation) */
	function dolibarOf(el) {
		const reading = el && el.closest('.ibx-reading');
		return reading ? $('.ibx-js-dolibar', reading) : null;
	}
	/** A file of the mail in the documents of a piece (antivirus first, by the server) */
	async function attachToPiece(box, m, a, type, id) {
		let res;
		try {
			res = await post('dolibarr', 'attach', { id: m.id, part: a.part, type, element: id });
		} catch (err) {
			toast(err.message, { error: true });
			return;
		}
		toast(t(res.unscanned ? 'InboxAttachedUnscanned' : 'InboxAttached', res.name));
		loadDolibar(box);
	}
	/**
	 * « Create ▾ »: the page of creation of Dolibarr opens in a new tab, filled from the mail; the piece saved there is
	 * linked to the mail (and gets the mail in its documents when the box is ticked)
	 */
	function createDialog(box) {
		const d = box._data;
		const dlg = dialog({
			title: t('InboxCreateTitle'),
			noCancel: false,
			body: '<div class="ibx-creategrid">' + d.create.map(c => '<button type="button" class="ibx-createbtn ibx-js-createtype" data-type="' + esc(c.type) + '">' + ic(c.icon) + '<span>' + esc(c.label) + '</span></button>').join('') + '</div>'
				+ '<label class="ibx-inline ibx-createattach"><input type="checkbox" class="ibx-js-createattach"> ' + th('InboxCreateAttachMail') + '</label>'
				+ '<p class="ibx-formrow__help">' + th('InboxCreateHelp') + '</p>',
			actions: [],
		});
		dlg.addEventListener('click', async e => {
			const b = e.target.closest('.ibx-js-createtype');
			if (!b) {
				return;
			}
			// The tab is opened at the click (a tab opened after an answer is blocked by the browser)
			const tab = window.open('about:blank', '_blank');
			let res;
			try {
				res = await post('dolibarr', 'intent', { id: +box.dataset.mail, type: b.dataset.type, attach: $('.ibx-js-createattach', dlg).checked ? 1 : 0,
					soc: d.known ? d.known.soc : 0, contact: d.known ? d.known.contact : 0 });
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
			dlg.close();
			toast(t('InboxCreateOpened'));
			// Back in inbox: the piece created is shown linked
			window.addEventListener('focus', () => loadDolibar(box), { once: true });
		});
	}
	/** « + Link »: the pieces of the thirdparty recognized first, then searched as you type */
	function linkDialog(box, opts) {
		opts = opts || {};
		const d = box._data;
		const soc = d.sender.soc && d.sender.soc.readable ? d.sender.soc.id : 0;
		const dlg = dialog({
			title: opts.attach ? t('InboxAttachToTitle', opts.attach.a.name) : t('InboxLinkPieceTitle'),
			body: '<input type="search" class="ibx-field ibx-js-piecesearch" autocomplete="off" placeholder="' + th('InboxSearchPiece') + '" aria-label="' + th('InboxSearchPiece') + '">'
				+ '<div class="ibx-picklist ibx-js-piecelist" role="listbox" aria-label="' + th('InboxPiecesLinked') + '"></div>',
			actions: [{ label: opts.attach ? t('InboxAttach') : t('InboxLink'), primary: true, run: async dl => {
				const cur = $('[aria-selected="true"]', dl);
				if (!cur) {
					return false;
				}
				if (opts.attach) {
					await attachToPiece(box, opts.attach.m, opts.attach.a, cur.dataset.type, +cur.dataset.id);
					return true;
				}
				await post('dolibarr', 'link', { id: +box.dataset.mail, type: cur.dataset.type, element: +cur.dataset.id });
				loadDolibar(box);
				return true;
			} }],
		});
		const input = $('.ibx-js-piecesearch', dlg);
		const list = $('.ibx-js-piecelist', dlg);
		// To link: the pieces linked already are greyed; to put a file in a piece: every piece may receive it
		const linked = new Set(opts.attach ? [] : d.links.map(l => l.type + ':' + l.id));
		let seq = 0;
		const load = async () => {
			const mine = ++seq;
			list.setAttribute('aria-busy', 'true');
			let res;
			try {
				res = await api('dolibarr', 'search', { q: input.value.trim(), soc });
			} catch (err) {
				list.innerHTML = '<div class="ibx-muted ibx-picklist__empty">' + esc(err.message) + '</div>';
				return;
			}
			if (mine !== seq) {
				return;
			}
			list.removeAttribute('aria-busy');
			let first = true;
			list.innerHTML = res.results.map(x => {
				const done = linked.has(x.type + ':' + x.id);
				const sel = !done && first;
				if (sel) {
					first = false;
				}
				return '<div class="ibx-picklist__item" role="option" data-type="' + esc(x.type) + '" data-id="' + x.id + '"' + (done ? ' aria-disabled="true" title="' + th('InboxAlreadyLinked') + '"' : '') + ' aria-selected="' + sel + '">'
					+ ic(x.icon) + ' <span class="ibx-ellipsis"><b>' + esc(x.ref) + '</b> ' + (x.label ? '<span class="ibx-muted">' + esc(x.label) + '</span> ' : '') + '<span class="ibx-muted">· ' + esc(x.kind) + '</span></span></div>';
			}).join('') || '<div class="ibx-muted ibx-picklist__empty">' + th(input.value.trim() || soc ? 'InboxNoPieceFound' : 'InboxTypeToSearch') + '</div>';
		};
		let timer = 0;
		input.addEventListener('input', () => {
			clearTimeout(timer);
			timer = setTimeout(load, 250);
		});
		input.addEventListener('keydown', e => {
			const items = $$('.ibx-picklist__item:not([aria-disabled])', list);
			const i = items.findIndex(x => x.getAttribute('aria-selected') === 'true');
			if ((e.key === 'ArrowDown' || e.key === 'ArrowUp') && items.length) {
				e.preventDefault();
				items.forEach(x => x.setAttribute('aria-selected', 'false'));
				const n = items[(i + (e.key === 'ArrowDown' ? 1 : items.length - 1) + items.length) % items.length];
				n.setAttribute('aria-selected', 'true');
				scrollWithin(list, n, 'nearest');
			} else if (e.key === 'Enter' && i >= 0) {
				e.preventDefault();
				$('.ibx-dialog__foot .ibx-btn--primary', dlg).click();
			}
		});
		list.addEventListener('click', e => {
			const it = e.target.closest('.ibx-picklist__item');
			if (it && !it.hasAttribute('aria-disabled')) {
				$$('[aria-selected="true"]', list).forEach(x => x.setAttribute('aria-selected', 'false'));
				it.setAttribute('aria-selected', 'true');
			}
		});
		list.addEventListener('dblclick', e => {
			const it = e.target.closest('.ibx-picklist__item');
			if (it && !it.hasAttribute('aria-disabled')) {
				$('.ibx-dialog__foot .ibx-btn--primary', dlg).click();
			}
		});
		load();
		input.focus();
	}

	// ---------- Bulk bar (from 2 mails) ----------
	function renderBulkBar() {
		const bar = $('.ibx-js-bulkbar', S.el);
		if (!bar) {
			return;
		}
		const ids = Array.from(S.selected);
		if (ids.length < 2) {
			bar.hidden = true;
			bar.innerHTML = '';
			bar.parentNode.classList.remove('has-bulk');
			return;
		}
		const rows = ids.map(rowOf).filter(Boolean);
		const anyUnread = rows.some(r => !seenOf(r));
		const why = refusal('move', ids);
		// The key of the action in its bubble: learnt where it is used
		const btn = (cls, icon, label, disabled, extra, key) => '<button type="button" class="ibx-btn ibx-btn--sm ' + cls + '"' + (disabled ? ' aria-disabled="true" title="' + esc(why) + '"' : ' title="' + esc(label + (key ? ' (' + key + ')' : '')) + '"') + (extra || '') + (key && key.indexOf('…') < 0 ? ' aria-keyshortcuts="' + esc(key.replace('Maj', 'Shift').replace('Suppr', 'Delete')) + '"' : '') + '>'
			+ ic(icon) + '<span class="ibx-bulkbar__label">' + esc(label) + '</span></button>';
		const role = rows[0] ? (S.folders.get(rows[0].folder) || { folder: {} }).folder.role : '';
		bar.hidden = false;
		// Floating over the list: showing it never moves the mails
		bar.parentNode.classList.add('has-bulk');
		bar.innerHTML = '<span class="ibx-bulkbar__count" role="status">' + th('InboxSelectedCount', ids.length) + '</span>'
			+ btn('ibx-js-bulk', 'archive', t('InboxArchive'), !!why, ' data-act="archive"', 'A')
			+ btn('ibx-js-bulk', anyUnread ? 'mark-read' : 'unread', t(anyUnread ? 'InboxMarkRead' : 'InboxMarkUnread'), false, ' data-act="seen"', 'M')
			+ btn('ibx-js-bulk', 'user-plus', t(rows.length && rows.every(r => (r.assignees || []).includes(S.me)) ? 'InboxUnassignMe' : 'InboxAssignMe'), false, ' data-act="assign"', t('InboxKeySpace'))
			+ btn('ibx-js-bulk', 'tag', t('InboxTagMenu'), !!why, ' data-act="tag" aria-haspopup="menu"', '1…9')
			+ btn('ibx-js-bulk', 'folder-move', t('InboxMoveToMenu'), !!why, ' data-act="move"', 'Maj+M')
			+ btn('ibx-js-bulk', role === 'junk' ? 'inbox' : 'junk', t(role === 'junk' ? 'InboxNotJunk' : 'InboxJunk'), !!why, ' data-act="' + (role === 'junk' ? 'notjunk' : 'junk') + '"', role === 'junk' ? 'Maj+J' : 'J')
			+ '<span class="ibx-spacer"></span>'
			+ btn('ibx-js-bulk ibx-btn--danger-quiet', 'delete', t(role === 'trash' ? 'InboxDestroy' : 'InboxDelete'), !!why, ' data-act="delete"', 'Suppr')
			+ '<button type="button" class="ibx-btn ibx-btn--icon ibx-btn--sm ibx-js-bulkclose" title="' + th('InboxClearSelection') + ' (Échap)" aria-label="' + th('InboxClearSelection') + '">' + ic('close') + '</button>';
	}
	function bindBulkBar() {
		const bar = $('.ibx-js-bulkbar', S.el);
		bar.addEventListener('click', async e => {
			if (e.target.closest('.ibx-js-bulkclose')) {
				S.selected.clear();
				S.allInFolder = false;
				paintSelection();
				renderBulkBar();
				showNothing();
				return;
			}
			const b = e.target.closest('.ibx-js-bulk');
			if (!b) {
				return;
			}
			const act = b.dataset.act;
			if (b.getAttribute('aria-disabled') === 'true') {
				toast(b.getAttribute('data-title') || b.title, { error: true });
				return;
			}
			const ids = S.allInFolder ? await wholeFolderIds() : Array.from(S.selected);
			if (!ids) {
				return;
			}
			if (act === 'seen') {
				doSeen(ids);
			} else if (act === 'assign') {
				doAssignMe(ids);
			} else if (act === 'tag') {
				tagMenu(b, ids);
			} else if (act === 'move') {
				moveDialog(ids);
			} else if (act === 'archive') {
				doArchive(ids);
			} else if (act === 'junk' || act === 'notjunk') {
				doJunk(ids, act === 'junk');
			} else if (act === 'delete') {
				doDelete(ids);
			}
		});
	}
	/** « Select the 3 245 mails of the folder »: their ids asked to the server (sent after by batches of 200) */
	async function wholeFolderIds() {
		try {
			const res = await api('list', 'ids', listQuery(''), { method: 'POST' });
			return res.ids || [];
		} catch (err) {
			toast(err.message, { error: true });
			return null;
		}
	}

	// ---------- Drag and drop of mails on the folders ----------
	function dragIdsOf(row) {
		const id = +row.dataset.mail;
		return S.selected.has(id) ? Array.from(S.selected) : [id];
	}
	/** Why the mails dragged cannot go into this folder ('' = they can) */
	function dropRefusal(folderId) {
		const e = S.folders.get(folderId);
		if (!e || !e.folder.selectable) {
			return t('InboxDropNotHere');
		}
		if (e.account.level !== 'manage') {
			return t('InboxReadOnlyOn', e.account.email);
		}
		const why = refusal('move', S.drag || []);
		if (why) {
			return why;
		}
		const rows = (S.drag || []).map(rowOf).filter(Boolean);
		if (rows.length && rows.every(r => r.folder === folderId)) {
			return t('InboxDropSameFolder');
		}
		return '';
	}
	function dragTip(text, x, y) {
		let tip = $('.ibx-dragtip');
		if (!text) {
			if (tip) {
				tip.remove();
			}
			return;
		}
		if (!tip) {
			tip = document.createElement('div');
			tip.className = 'ibx-dragtip';
			tip.setAttribute('role', 'status');
			document.body.appendChild(tip);
		}
		tip.textContent = text;
		tip.style.left = (x + 14) + 'px';
		tip.style.top = (y + 14) + 'px';
	}
	function bindDragDrop() {
		const list = $('.ibx-pane--list .ibx-pane__body', S.el);
		const tree = $('.ibx-pane--folders', S.el);
		list.addEventListener('dragstart', e => {
			const row = e.target.closest && e.target.closest('[data-mail]');
			if (!row || e.target.closest('th')) {
				return;
			}
			const ids = dragIdsOf(row);
			S.drag = ids;
			e.dataTransfer.effectAllowed = 'move';
			e.dataTransfer.setData('text/plain', ids.length === 1 ? ((rowOf(ids[0]) || {}).subject || '') : t('InboxSelectedCount', ids.length));
			const ghost = document.createElement('div');
			ghost.className = 'ibx-dragghost';
			ghost.textContent = ids.length === 1 ? ((rowOf(ids[0]) || {}).subject || t('InboxNoSubject')) : t('InboxSelectedCount', ids.length);
			document.body.appendChild(ghost);
			e.dataTransfer.setDragImage(ghost, 10, 10);
			setTimeout(() => ghost.remove(), 0);
		});
		list.addEventListener('dragend', () => {
			S.drag = null;
			dragTip('');
			$$('.is-droptarget', tree).forEach(x => x.classList.remove('is-droptarget'));
		});
		let hover = { id: 0, timer: 0 };
		tree.addEventListener('dragover', e => {
			if (!S.drag) {
				return;
			}
			const row = e.target.closest('[data-folder], [data-account]');
			$$('.is-droptarget', tree).forEach(x => x !== row && x.classList.remove('is-droptarget'));
			if (!row) {
				dragTip('');
				return;
			}
			// A folder (or a mailbox) folded and hovered 600 ms opens
			const key = row.dataset.folder ? 'f' + row.dataset.folder : 'a' + row.dataset.account;
			if (hover.id !== key) {
				clearTimeout(hover.timer);
				hover = { id: key, timer: setTimeout(() => {
					if (S.collapsed.has(key)) {
						S.collapsed.delete(key);
						renderTree();
					}
				}, 600) };
			}
			if (!row.dataset.folder) {
				dragTip('');
				return;
			}
			const why = dropRefusal(+row.dataset.folder);
			if (why) {
				e.dataTransfer.dropEffect = 'none';
				row.classList.remove('is-droptarget');
				dragTip(why, e.clientX, e.clientY);
				return;
			}
			e.preventDefault();
			e.dataTransfer.dropEffect = 'move';
			row.classList.add('is-droptarget');
			dragTip('');
		});
		tree.addEventListener('dragleave', e => {
			if (!tree.contains(e.relatedTarget)) {
				$$('.is-droptarget', tree).forEach(x => x.classList.remove('is-droptarget'));
				dragTip('');
				clearTimeout(hover.timer);
				hover = { id: 0, timer: 0 };
			}
		});
		// Files (.eml, mbox of Thunderbird) dropped on a folder: imported into it (1.12.0)
		const hasFiles = e => !S.drag && e.dataTransfer && Array.from(e.dataTransfer.types || []).includes('Files');
		tree.addEventListener('dragover', e => {
			if (!hasFiles(e)) {
				return;
			}
			const row = e.target.closest('[data-folder]');
			$$('.is-droptarget', tree).forEach(x => x !== row && x.classList.remove('is-droptarget'));
			const entry = row ? S.folders.get(+row.dataset.folder) : null;
			const why = !entry ? '' : (entry.account.level !== 'manage' ? t('InboxReadOnlyOn', entry.account.email) : (entry.folder.selectable ? '' : t('InboxFolderNotSelectable')));
			// Never the file opened by the browser when it misses a folder
			e.preventDefault();
			if (!entry || why) {
				e.dataTransfer.dropEffect = 'none';
				if (row) {
					row.classList.remove('is-droptarget');
				}
				dragTip(why, e.clientX, e.clientY);
				return;
			}
			e.dataTransfer.dropEffect = 'copy';
			row.classList.add('is-droptarget');
			dragTip(t('InboxImportInto', folderName(entry.folder)), e.clientX, e.clientY);
		});
		tree.addEventListener('drop', e => {
			if (!hasFiles(e)) {
				return;
			}
			e.preventDefault();
			e.stopImmediatePropagation();
			dragTip('');
			$$('.is-droptarget', tree).forEach(x => x.classList.remove('is-droptarget'));
			const row = e.target.closest('[data-folder]');
			const entry = row ? S.folders.get(+row.dataset.folder) : null;
			if (entry && entry.account.level === 'manage' && entry.folder.selectable && e.dataTransfer.files.length) {
				importFiles(entry.account, entry.folder, Array.from(e.dataTransfer.files));
			}
		});
		// A file dropped elsewhere in the application is not opened by the browser either
		S.el.addEventListener('dragover', e => {
			if (hasFiles(e) && !tree.contains(e.target)) {
				e.preventDefault();
				e.dataTransfer.dropEffect = 'none';
			}
		});
		S.el.addEventListener('drop', e => {
			if (hasFiles(e) && !tree.contains(e.target)) {
				e.preventDefault();
			}
		});
		tree.addEventListener('drop', e => {
			const row = e.target.closest('[data-folder]');
			const ids = S.drag;
			S.drag = null;
			dragTip('');
			clearTimeout(hover.timer);
			$$('.is-droptarget', tree).forEach(x => x.classList.remove('is-droptarget'));
			if (!row || !ids) {
				return;
			}
			e.preventDefault();
			runAction('move', ids, { folder: +row.dataset.folder });
		});
	}

	// ---------- Import and export of mails (lot W4 1.12.0, B14): local folders of Thunderbird before the switch ----------
	function pickImport(account, f) {
		const input = document.createElement('input');
		input.type = 'file';
		input.multiple = true;
		// The files of the local folders of Thunderbird have no extension: any file, the content says what it is
		input.addEventListener('change', () => {
			if (input.files.length) {
				importFiles(account, f, Array.from(input.files));
			}
		});
		input.click();
	}
	function base64(bytes) {
		let bin = '';
		for (let i = 0; i < bytes.length; i += 0x8000) {
			bin += String.fromCharCode.apply(null, bytes.subarray(i, i + 0x8000));
		}
		return btoa(bin);
	}
	/** A file starting with « From » (and a space) is an mbox: many mails, each after its « From » line */
	async function isMbox(file) {
		const head = new Uint8Array(await file.slice(0, 5).arrayBuffer());
		return String.fromCharCode.apply(null, head) === 'From ';
	}
	/** The mails of an mbox, read by slices of 8 MB (a file of several GB never in memory at once) */
	async function* mboxMails(file, progress) {
		const SLICE = 8 * 1048576;
		let carry = new Uint8Array(0);
		let pos = 0;
		const starts = buf => {
			const out = [];
			for (let i = 0; i < buf.length; i = buf.indexOf(10, i) + 1 || buf.length) {
				if (buf[i] === 70 && buf[i + 1] === 114 && buf[i + 2] === 111 && buf[i + 3] === 109 && buf[i + 4] === 32) {
					out.push(i);
				}
			}
			return out;
		};
		const body = (buf, a, b) => {
			const eol = buf.indexOf(10, a);
			let end = b;
			// The empty line before the next « From » belongs to the mbox, not to the mail
			while (end > eol + 1 && (buf[end - 1] === 10 || buf[end - 1] === 13)) {
				end--;
			}
			return eol < 0 || eol + 1 >= end ? null : buf.slice(eol + 1, end);
		};
		while (pos < file.size) {
			const chunk = new Uint8Array(await file.slice(pos, pos + SLICE).arrayBuffer());
			pos += chunk.length;
			progress(pos);
			const buf = new Uint8Array(carry.length + chunk.length);
			buf.set(carry);
			buf.set(chunk, carry.length);
			const list = starts(buf);
			for (let k = 0; k + 1 < list.length; k++) {
				const m = body(buf, list[k], list[k + 1]);
				if (m) {
					yield m;
				}
			}
			carry = list.length ? buf.slice(list[list.length - 1]) : buf;
		}
		if (carry.length) {
			const m = body(carry, 0, carry.length);
			if (m) {
				yield m;
			}
		}
	}
	/**
	 * Files imported into a folder: .eml (one mail) or mbox (many), sent by batches that fit the server; progress,
	 * « Stop », and a report (imported, already there, removed in Thunderbird, virus, refused)
	 */
	async function importFiles(account, f, files) {
		let limits;
		try {
			limits = await api('mailtransfer', 'limits');
		} catch (err) {
			toast(err.message, { error: true });
			return;
		}
		const count = { imported: 0, duplicate: 0, removed: 0, infected: 0, failed: 0 };
		const failures = [];
		let stopped = false;
		let done = false;
		const d = dialog({
			title: t('InboxImportTitle', folderName(f)),
			noCancel: true,
			body: '<p class="ibx-js-impfile ibx-ellipsis"></p><progress class="ibx-progress ibx-js-impbar" max="1" value="0"></progress>'
				+ '<p class="ibx-js-impcount" role="status" aria-live="polite"></p><div class="ibx-js-impfails"></div>',
			actions: [{ label: t('InboxStop'), primary: true, run: () => {
				if (done) {
					return true;
				}
				stopped = true;
				return false;
			} }],
		});
		d.addEventListener('cancel', e => {
			if (!done) {
				e.preventDefault();
			}
		});
		const leave = e => {
			e.preventDefault();
			e.returnValue = '';
		};
		window.addEventListener('beforeunload', leave);
		const paint = () => {
			$('.ibx-js-impcount', d).textContent = t('InboxImportCount', count.imported, count.duplicate, count.removed + count.infected + count.failed);
		};
		let batch = [];
		let batchBytes = 0;
		let mbox = false;
		const send = async () => {
			if (!batch.length) {
				return;
			}
			const mails = batch;
			batch = [];
			batchBytes = 0;
			try {
				const res = await post('mailtransfer', 'import', { folder: f.id, mbox: mbox ? 1 : 0, mails });
				(res.results || []).forEach(x => {
					count[x.state] = (count[x.state] || 0) + 1;
					if (x.state === 'failed' || x.state === 'infected') {
						failures.push(x);
					}
				});
			} catch (err) {
				count.failed += mails.length;
				failures.push({ subject: t('InboxImportBatch', mails.length), reason: err.message });
				if (err.status === 403 || err.status === 404) {
					stopped = true;
				}
			}
			paint();
		};
		const add = async (bytes, name) => {
			if (bytes.length > Math.min(limits.mail, limits.batch)) {
				count.failed++;
				failures.push({ subject: name, reason: t('InboxImportTooBigFor', size(bytes.length)) });
				return;
			}
			if (batchBytes + bytes.length > limits.batch || batch.length >= 50) {
				await send();
			}
			batch.push(base64(bytes));
			batchBytes += bytes.length;
		};
		for (let i = 0; i < files.length && !stopped; i++) {
			const file = files[i];
			$('.ibx-js-impfile', d).textContent = t('InboxImportFile', i + 1, files.length, file.name);
			const bar = $('.ibx-js-impbar', d);
			await send();
			mbox = await isMbox(file);
			if (mbox) {
				for await (const m of mboxMails(file, pos => {
					bar.value = file.size ? pos / file.size : 1;
				})) {
					if (stopped) {
						break;
					}
					await add(m, file.name);
				}
			} else {
				await add(new Uint8Array(await file.arrayBuffer()), file.name);
				bar.value = 1;
			}
			await send();
		}
		done = true;
		window.removeEventListener('beforeunload', leave);
		paint();
		$('.ibx-js-impfile', d).textContent = stopped ? t('InboxImportStopped') : t('InboxMailImportDone');
		$('.ibx-dialog__foot .ibx-btn--primary', d).textContent = t('InboxCloseWindow');
		if (failures.length) {
			$('.ibx-js-impfails', d).innerHTML = '<details><summary>' + th('InboxImportFailures', failures.length) + '</summary><ul class="ibx-importfails">'
				+ failures.slice(0, 100).map(x => '<li><b>' + esc(x.subject || t('InboxNoSubject')) + '</b> ' + esc(x.state === 'infected' ? t('InboxImportVirus', x.reason || '') : (x.reason || '')) + '</li>').join('')
				+ '</ul></details>';
		}
		if (count.imported) {
			requestSync(f.id, true);
			await refreshTree();
			if (S.folderId === f.id) {
				setTimeout(() => loadList(true), 1500);
			}
		}
	}
	/** Export: mbox (Thunderbird opens it) or zip of .eml; said when inbox does not know all the mails of the folder */
	function exportDialog(f) {
		const partial = (f.indexed || 0) < (f.total || 0);
		dialog({
			title: t('InboxExportTitle', folderName(f)),
			body: '<fieldset class="ibx-look__set"><legend>' + th('InboxExportFormat') + '</legend>'
				+ '<label class="ibx-inline"><input type="radio" name="ibx-expfmt" value="mbox" checked> ' + th('InboxExportMbox') + '</label><br>'
				+ '<label class="ibx-inline"><input type="radio" name="ibx-expfmt" value="zip"> ' + th('InboxExportZip') + '</label></fieldset>'
				+ (partial ? banner('warning', 'warning', th('InboxExportPartial', f.indexed || 0, f.total)) : '')
				+ '<p class="ibx-formrow__help">' + th('InboxExportHelp') + '</p>',
			actions: [{ label: t('InboxExport'), primary: true, run: dlg => {
				const fmt = ($('input[name="ibx-expfmt"]:checked', dlg) || {}).value || 'mbox';
				download(I.ctx('INBOX_AJAX_URL', '') + 'mailtransfer.php?action=export&folder=' + f.id + '&format=' + fmt);
				toast(t('InboxExportStarted'));
				return true;
			} }],
		});
	}

	// ---------- Gestures of the phone: a card slid left or right ----------
	const SWIPES = { archive: ['archive', 'InboxArchive'], delete: ['delete', 'InboxDelete'], read: ['mark-read', 'InboxKeyRead'], flag: ['star', 'InboxFlag'],
		tag: ['tag', 'InboxTagMenu'], move: ['folder-move', 'InboxMove'] };
	function swipeAction(side) {
		const v = params[side === 'left' ? 'swipe_left' : 'swipe_right'];
		const def = side === 'left' ? 'archive' : 'read';
		return v === 'none' ? '' : (SWIPES[v] ? v : def);
	}
	function runSwipe(act, id, card) {
		const ids = [id];
		if (act === 'archive') {
			doArchive(ids, { stay: true });
		} else if (act === 'delete') {
			doDelete(ids, { stay: true });
		} else if (act === 'read') {
			const r = rowOf(id);
			// Undone by the same gesture or the key M: no toast (archive and the other moves keep « Undo »)
			runAction('seen', ids, { on: r && seenOf(r) ? 0 : 1 }, { quiet: true });
		} else if (act === 'flag') {
			const r = rowOf(id);
			runAction('flag', ids, { on: r && r.flagged ? 0 : 1 }, { quiet: true });
		} else if (act === 'tag') {
			tagMenu(card, ids);
		} else if (act === 'move') {
			moveDialog(ids);
		}
	}
	function bindSwipes() {
		const list = $('.ibx-pane--list .ibx-pane__body', S.el);
		let g = null;
		list.addEventListener('pointerdown', e => {
			if (e.pointerType !== 'touch') {
				return;
			}
			const card = e.target.closest('.ibx-mailcard[data-mail]');
			if (!card || card.dataset.child) {
				return;
			}
			g = { card, x: e.clientX, y: e.clientY, dx: 0, on: false };
		}, { passive: true });
		list.addEventListener('pointermove', e => {
			if (!g) {
				return;
			}
			const dx = e.clientX - g.x;
			const dy = e.clientY - g.y;
			if (!g.on) {
				if (Math.abs(dy) > 12) {
					g = null;
					return;
				}
				if (Math.abs(dx) < 12) {
					return;
				}
				const act = swipeAction(dx < 0 ? 'left' : 'right');
				if (!act) {
					g = null;
					return;
				}
				g.on = true;
				g.card.classList.add('is-swiping');
			}
			g.dx = dx;
			const side = dx < 0 ? 'left' : 'right';
			const act = swipeAction(side);
			const s = SWIPES[act] || ['', ''];
			// Read / unread: the state the mail will get (a short word, the card covers a long one)
			let label = t(s[1]);
			let icon = s[0];
			if (act === 'read') {
				const r = rowOf(+g.card.dataset.mail);
				const seen = r && seenOf(r);
				label = t(seen ? 'InboxSwipeUnseen' : 'InboxSwipeSeen');
				icon = seen ? 'unread' : 'mark-read';
			}
			// The action under the card (the card slides over it): big icon and word on the side it uncovers
			if (!g.bg) {
				g.bg = document.createElement('div');
				g.bg.className = 'ibx-swipebg';
				g.bg.setAttribute('aria-hidden', 'true');
				g.card.parentElement.insertBefore(g.bg, g.card);
			}
			const c = g.card;
			Object.assign(g.bg.style, { top: c.offsetTop + 'px', left: c.offsetLeft + 'px', width: c.offsetWidth + 'px', height: c.offsetHeight + 'px' });
			const key = side + ':' + act + ':' + label;
			if (g.bg.dataset.key !== key) {
				g.bg.dataset.key = key;
				g.bg.dataset.side = side;
				g.bg.dataset.act = act;
				g.bg.innerHTML = ic(icon, 'ibx-swipebg__icon') + '<span class="ibx-swipebg__label">' + esc(label) + '</span>';
			}
			g.bg.classList.toggle('is-armed', Math.abs(dx) > 80);
			c.style.transform = 'translateX(' + dx + 'px)';
		}, { passive: true });
		const end = () => {
			if (!g) {
				return;
			}
			const { card, dx, on, bg } = g;
			g = null;
			if (!on) {
				return;
			}
			card.classList.remove('is-swiping');
			card.style.transform = '';
			// The background goes once the card is back over it
			if (bg) {
				setTimeout(() => bg.remove(), 220);
			}
			// The click that may follow the gesture does not open the mail
			S.swipedAt = Date.now();
			if (Math.abs(dx) > 80) {
				runSwipe(swipeAction(dx < 0 ? 'left' : 'right'), +card.dataset.mail, card);
			}
		};
		list.addEventListener('pointerup', end);
		list.addEventListener('pointercancel', () => {
			if (g && g.card) {
				g.card.classList.remove('is-swiping');
				g.card.style.transform = '';
				if (g.bg) {
					const bg = g.bg;
					setTimeout(() => bg.remove(), 220);
				}
			}
			g = null;
		});
	}

	/** Redirect (B19): the mail goes as it is (its sender, its text, its files) to the addresses typed */
	function redirectDialog(m) {
		const body = '<form class="ibx-form" novalidate><p class="ibx-section__help">' + th('InboxRedirectHelp') + '</p>'
			+ '<div class="ibx-formrow"><label class="ibx-formrow__label" for="ibx-redirect-to">' + th('InboxTo') + '</label><div><input class="ibx-field" id="ibx-redirect-to" name="to" type="email" multiple inputmode="email" autocomplete="email" required></div></div></form>';
		dialog({
			title: t('InboxRedirectTitle', m.subject || t('InboxNoSubject')),
			body,
			actions: [{ label: t('InboxRedirectSend'), primary: true, run: async d => {
				const input = $('[name="to"]', d);
				const to = input.value.split(/[,;\s]+/).map(x => x.trim()).filter(Boolean);
				if (!to.length || !input.checkValidity()) {
					input.focus();
					toast(t('InboxComposeNoRecipient'), { error: true });
					return false;
				}
				try {
					await post('compose', 'redirect', { mail: m.id, to });
				} catch (err) {
					toast(err.message, { error: true });
					return false;
				}
				toast(t('InboxRedirected', to.join(', ')));
			} }],
		});
	}

	// ---------- Registry of actions (one definition: buttons, menu, keyboard; hook inbox:mail:actions) ----------
	function actions(m) {
		const list = [];
		// Lot W6: writing. A draft is edited; the other mails are answered or forwarded from any mailbox where the
		// user may send (the identity of the mailbox that got the mail chosen first, by the server)
		const noSend = I.ctx('INBOX_CAN_SEND', false) ? '' : t('InboxComposeNoIdentity');
		if (m.folder_role === 'drafts') {
			list.push({ id: 'editdraft', icon: 'compose', label: t('InboxEditDraft'), button: true, disabled: !!noSend, reason: noSend, run: () => I.compose({ mode: 'draft', mail: m.id }) });
		} else {
			const listPost = (m.headers || []).some(h => String(h[0]).toLowerCase() === 'list-post' && /mailto:/i.test(h[1]));
			list.push(
				{ id: 'reply', icon: 'reply', label: t('InboxReply'), key: 'R', button: true, disabled: !!noSend, reason: noSend, run: () => I.compose({ mode: 'reply', mail: m.id }) },
				{ id: 'replyall', icon: 'reply-all', label: t('InboxReplyAll'), key: 'Maj+R', button: true, disabled: !!noSend, reason: noSend, run: () => I.compose({ mode: 'replyall', mail: m.id }) },
				{ id: 'forward', icon: 'forward', label: t('InboxForward'), key: 'Ctrl+L', button: true, disabled: !!noSend, reason: noSend, run: () => I.compose({ mode: 'forward', mail: m.id }) },
			);
			if (listPost) {
				list.push({ id: 'replylist', icon: 'reply-all', label: t('InboxReplyList'), disabled: !!noSend, reason: noSend, run: () => I.compose({ mode: 'replylist', mail: m.id }) });
			}
			list.push({ id: 'forwardatt', icon: 'attachment', label: t('InboxForwardAttached'), disabled: !!noSend, reason: noSend, run: () => I.compose({ mode: 'forward_attach', mail: m.id }) });
			// B19 (lot W6 1.18.0): the very mail sent again to someone else; a mail written again as a new one
			list.push({ id: 'redirect', icon: 'redirect', label: t('InboxRedirect'), disabled: !!noSend, reason: noSend, run: () => redirectDialog(m) });
		}
		list.push({ id: 'editnew', icon: 'edit-new', label: t('InboxEditAsNew'), disabled: !!noSend, reason: noSend, run: () => I.compose({ mode: 'editnew', mail: m.id }) });
		// Rule made from this mail (lot W8 1.23.0): its sender and subject in the editor of the rules of the mailbox
		if (m.level === 'manage' && m.from && m.from.email && I.rules) {
			// The editor over the mail (the mail stays read); the rules of the mailbox in the panel on the right
			list.push({ id: 'rule', icon: 'folder-bolt', label: t('InboxRuleFromMail'), run: () => I.rules.fromMail(m) });
			list.push({ id: 'rules', icon: 'folders', label: t('InboxRulesPanelOpen'), run: () => I.rules.openPanel(m.account) });
		}
		list.push({ id: 'read', icon: m.seen ? 'unread' : 'mark-read', label: t(m.seen ? 'InboxMarkUnread' : 'InboxMarkRead'), key: 'M', button: true, run: () => setSeenMany([m.id], !m.seen) });
		// Actions of lot W4: starred, archive and remove as buttons, the others in the menu
		const anchor = () => $('.ibx-js-more', mailRoot(m)) || S.el;
		mailActionItems([m.id]).filter(a => !a.sep).forEach(a => {
			list.push(Object.assign({}, a, { button: ['flag', 'archive', 'delete'].includes(a.id), run: a.id === 'tag' ? () => tagMenu(anchor(), [m.id]) : a.run }));
		});
		list.push(
			{ sep: true },
			{ id: 'headers', icon: 'info', label: t('InboxAllHeaders'), run: () => headersDialog(m) },
			{ id: 'source', icon: 'source', label: t('InboxViewSource'), key: 'Ctrl+U', run: () => openViewer({ mail: m, source: true }) },
			{ id: 'eml', icon: 'download', label: t('InboxSaveEml'), run: () => download(I.ctx('INBOX_AJAX_URL', '') + 'body.php?id=' + m.id + '&part=source&download=1') },
			{ id: 'print', icon: 'print', label: t('InboxPrint'), key: 'Ctrl+P', run: () => printMail(m) },
			{ id: 'zoomin', icon: 'text-size', label: t('InboxZoomMailIn'), run: () => zoomMail(10) },
			{ id: 'zoomout', icon: 'text-size', label: t('InboxZoomMailOut'), run: () => zoomMail(-10) },
			{ id: 'dolibarpos', icon: 'dolibarr', label: t(dolibarRight() ? 'InboxDolibarrToTop' : 'InboxDolibarrToRight'), run: () => setDolibarPosition(dolibarRight() ? 'top' : 'right') },
		);
		list.forEach(a => {
			a.own = true;
		});
		Dolibarr.executeHook('inbox:mail:actions', { mail: m, actions: list });
		list.forEach(a => {
			a.module = !a.own;
		});
		return list;
	}
	function actionButtons(m) {
		return actions(m).filter(a => a.button).map(a => '<button type="button" class="ibx-btn ibx-btn--icon ibx-js-action' + (a.id === 'read' ? ' ibx-js-toggleread' : '') + '" data-action="' + esc(a.id) + '"'
			+ (a.disabled ? ' aria-disabled="true" title="' + esc(a.reason || a.label) + '"' : ' title="' + esc(a.label + (a.key ? ' (' + a.key + ')' : '')) + '"') + ' aria-label="' + esc(a.label) + '">' + ic(a.icon) + '</button>').join('')
			+ '<button type="button" class="ibx-btn ibx-btn--icon ibx-js-more" aria-haspopup="menu" aria-label="' + th('InboxMoreActions') + '">' + ic('more') + '</button>';
	}

	/** Small menu under a button; closes on Escape, outside click; arrows move */
	// The menus of inbox for the other spaces too (Settings: menu of a rule)
	I.menu = (anchor, items, at) => menu(anchor, items, at);
	// What the panel of the rules (js/inbox-rules.js) needs to know: the mailboxes, the mail read
	I.mailState = () => ({ accounts: S.accounts, mail: S.mail });
	function menu(anchor, items, at) {
		if (S.menu && S.menuAnchor === anchor && !at) {
			// Second click on the button of the open menu: it closes
			closeMenu();
			return;
		}
		closeMenu();
		const box = document.createElement('div');
		box.className = 'ibx-menu';
		box.setAttribute('role', 'menu');
		box.innerHTML = items.map((it, i) => it.sep ? '<div class="ibx-menu__sep" role="separator"></div>'
			: it.header ? '<div class="ibx-menu__header" role="presentation">' + esc(it.header) + '</div>'
			: it.note ? '<div class="ibx-menu__hint" role="note">' + esc(it.note) + '</div>'
			: '<button type="button" class="ibx-menu__item' + (it.danger ? ' ibx-menu__item--danger' : '') + '" role="menuitem" data-i="' + i + '"' + (it.disabled ? ' aria-disabled="true"' + (it.reason ? ' title="' + esc(it.reason) + '"' : '') : '') + '>' + (it.icon ? ic(it.icon, 'ibx-menu__icon') : '<span class="ibx-menu__icon"></span>')
				+ '<span class="ibx-menu__label">' + esc(it.label) + '</span>' + (it.checked !== undefined ? (it.checked ? ic('check') : '') : '') + (it.key ? '<span class="ibx-menu__key">' + esc(it.key) + '</span>' : '') + '</button>').join('');
		document.body.appendChild(box);
		const r = at ? { left: at.x, right: at.x, top: at.y, bottom: at.y } : anchor.getBoundingClientRect();
		const w = box.offsetWidth;
		const h = box.offsetHeight;
		box.style.position = 'fixed';
		// Under a button: right edges aligned; at the pointer: starts at the pointer
		box.style.left = Math.max(8, Math.min(at ? at.x : r.right - w, window.innerWidth - w - 8)) + 'px';
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
		S.menuAt = !!at;
		anchor.setAttribute('aria-expanded', 'true');
		S.menuAnchor = anchor;
		if (buttons[0]) {
			buttons[0].focus();
		}
		setTimeout(() => document.addEventListener('pointerdown', outside, true), 0);
	}
	function outside(e) {
		// The button of the menu toggles it itself (its click comes after this pointerdown)
		if (S.menu && !S.menu.contains(e.target) && !(S.menuAnchor && S.menuAnchor.contains(e.target) && !S.menuAt)) {
			closeMenu();
		}
	}
	// A click in the body of a mail (iframe: its events do not reach the page) moves the focus out of the page; a
	// scroll or a resize would leave the menu away from its button
	window.addEventListener('blur', () => closeMenu());
	window.addEventListener('resize', () => closeMenu());
	document.addEventListener('scroll', e => {
		if (S.menu && !S.menu.contains(e.target)) {
			closeMenu();
		}
	}, true);
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
	// ---------- Conversation (1.5.0) ----------
	/**
	 * The mails of the thread in the whole mailbox (sent mails included), the oldest first. Unfolded: the last one, the
	 * unread ones and the one clicked; the others on one line, unfolded by a click. Quotes of the previous mails
	 * folded in each body. My mails (sent from this mailbox) on another background.
	 */
	async function openThread(id, row) {
		const pane = readPane();
		pane.innerHTML = '<div class="ibx-reading" aria-busy="true"><div class="ibx-readhead"><h2 class="ibx-readhead__subject">' + esc(row.subject || t('InboxNoSubject')) + '</h2></div>'
			+ '<div class="ibx-readbody"><span class="ibx-skeleton"></span><span class="ibx-skeleton" style="width:70%"></span></div></div>';
		let res;
		try {
			res = await api('mail', 'thread', { id });
		} catch (err) {
			if (S.openId === id) {
				S.thread = null;
				openMail(id, true);
			}
			return;
		}
		if (S.openId !== id) {
			return;
		}
		const mails = res.mails || [];
		learnPeople(res.people);
		if (mails.length < 2) {
			openMail(id, true);
			return;
		}
		S.thread = mails;
		const entry = S.folders.get(row.folder);
		const box = entry ? entry.account.email.toLowerCase() : '';
		const last = mails[mails.length - 1];
		pane.innerHTML = '<article class="ibx-reading ibx-convo" aria-label="' + esc(row.subject || t('InboxNoSubject')) + '">'
			+ '<header class="ibx-readhead ibx-convo__head"><div class="ibx-readhead__top"><div class="ibx-readhead__who"><h2 class="ibx-readhead__subject">' + esc(last.subject || row.subject || t('InboxNoSubject'))
			+ ' ' + tagsHtml(row) + '</h2><div class="ibx-readhead__line">' + th('InboxThreadCount', mails.length) + (box ? ' · ' + th('InboxReceivedOn') + ' <b>' + esc(box) + '</b>' : '') + '</div>'
			+ assignLine([row.id], row.assignees || [], row.has_mid !== 0) + '</div>'
			+ '<div class="ibx-readhead__actions ibx-js-convoactions"></div></div></header>'
			+ dolibarSlot(id, true)
			+ mails.map(m => '<section class="ibx-convo__item' + (isMine(m) ? ' is-mine' : '') + (m.id === id ? ' is-current' : '') + '" data-mailblock="' + m.id + '">'
				+ '<button type="button" class="ibx-convo__line ibx-js-expand" aria-expanded="false">' + (isMine(m) ? '<span class="ibx-avatar ibx-avatar--sm ibx-avatar--me" aria-hidden="true">' + ic('sent') + '</span>' : avatar(m.from_name, m.from_email, 'ibx-avatar--sm', m.id))
				+ '<span class="ibx-sr-only">' + th(isMine(m) ? 'InboxSent' : 'InboxReceived') + ' </span>'
				+ '<span class="ibx-convo__who ibx-ellipsis' + (seenOf(m) ? '' : ' is-unread') + '">' + esc(isMine(m) ? mineLabel(m) : (m.from_name || m.from_email || t('InboxNoSender'))) + '</span>'
				+ '<span class="ibx-convo__snippet ibx-ellipsis ibx-muted">' + esc(m.snippet || m.subject || '') + '</span>'
				+ (m.attachments ? ic('attachment', 'ibx-faint') : '') + '<span class="ibx-convo__date ibx-muted" title="' + esc(longDate(m.date)) + '">' + esc(shortDate(m.date)) + '</span></button>'
				+ '<div class="ibx-convo__body" hidden></div></section>').join('')
			+ '</article>';
		hydrateAvatars(pane);
		loadDolibar($('.ibx-js-dolibar', pane));
		$$('.ibx-js-expand', pane).forEach(b => b.addEventListener('click', () => {
			const item = b.closest('[data-mailblock]');
			const open = b.getAttribute('aria-expanded') === 'true';
			if (open) {
				b.setAttribute('aria-expanded', 'false');
				$('.ibx-convo__body', item).hidden = true;
			} else {
				expandItem(item, +item.dataset.mailblock, mails);
			}
		}));
		const wanted = mails.filter((m, i) => m.id === id || i === mails.length - 1 || !seenOf(m)).slice(-6);
		await Promise.all(wanted.map(m => expandItem($('[data-mailblock="' + m.id + '"]', pane), m.id, mails)));
		const current = $('[data-mailblock="' + id + '"]', pane) || $('[data-mailblock="' + last.id + '"]', pane);
		if (current) {
			scrollWithin(pane, current, current === $('[data-mailblock="' + last.id + '"]', pane) ? 'end' : 'start');
		}
	}

	/** Unfold one mail of the conversation: its head, banners, body (quotes folded) and attachments */
	async function expandItem(item, mailId, mails) {
		if (!item) {
			return;
		}
		const btn = $('.ibx-js-expand', item);
		const body = $('.ibx-convo__body', item);
		btn.setAttribute('aria-expanded', 'true');
		body.hidden = false;
		if (body.dataset.loaded) {
			return;
		}
		body.innerHTML = '<span class="ibx-skeleton"></span><span class="ibx-skeleton" style="width:60%"></span>';
		let m;
		try {
			m = await api('mail', 'open', { id: mailId });
		} catch (err) {
			body.innerHTML = '<div class="ibx-muted">' + esc(err.message) + '</div>';
			return;
		}
		body.dataset.loaded = '1';
		S.blocks = S.blocks || {};
		S.blocks[mailId] = m;
		const first = mails[0] && mails[0].id === mailId;
		body.innerHTML = '<div class="ibx-readhead ibx-readhead--compact">' + headHtml(m, true, true) + '</div>' + mailBodyHtml(m, { fold: !first, inline: true });
		bindMail(m, item);
		hydrateAvatars(item);
		// A click on the head of an unfolded mail folds it again (the line comes back)
		const top = $('.ibx-readhead__top', body);
		top.setAttribute('role', 'button');
		top.setAttribute('tabindex', '0');
		top.setAttribute('aria-label', t('InboxCollapse'));
		const fold = () => {
			btn.setAttribute('aria-expanded', 'false');
			body.hidden = true;
			btn.focus();
		};
		top.addEventListener('click', e => {
			if (!e.target.closest('button, a, .ibx-addr')) {
				fold();
			}
		});
		top.addEventListener('keydown', e => {
			if (e.key === 'Enter' || e.key === ' ') {
				e.preventDefault();
				fold();
			}
		});
		if (mailId === S.openId) {
			S.mail = m;
			const head = $('.ibx-js-convoactions', readPane());
			if (head) {
				head.innerHTML = actionButtons(m);
				bindActions(head);
			}
			autoRead(m);
			presenceSoon();
			Dolibarr.executeHook('inbox:mail:open', { account: m.account, messageId: m.message_id, subject: m.subject, from: m.from });
		}
	}

	function renderMail(m) {
		const pane = readPane();
		S.blocks = { [m.id]: m };
		const signed = m.security && m.security.signed ? '<span class="ibx-badge" title="' + esc(m.security.signer || '') + '">' + ic(m.security.signed === 'invalid' ? 'warning' : 'check') + ' '
			+ th(m.security.signed === 'valid' ? 'InboxSigned' : (m.security.signed === 'invalid' ? 'InboxSignatureInvalid' : 'InboxSignedUnchecked')) + '</span>' : '';
		pane.innerHTML = '<article class="ibx-reading" aria-label="' + esc(m.subject || t('InboxNoSubject')) + '">'
			+ '<header class="ibx-readhead">' + headHtml(m) + (signed ? '<div class="ibx-readhead__line">' + signed + '</div>' : '') + '</header>'
			+ dolibarSlot(m.id, false)
			+ mailBodyHtml(m, {}) + '</article>';
		bindActions(pane);
		bindMail(m, pane);
		hydrateAvatars(pane);
		loadDolibar($('.ibx-js-dolibar', pane));
	}

	/**
	 * Banners, body and attachments of a mail (reading pane, or one mail of a conversation: inline = attachments
	 * not stuck at the bottom, fold = quotes of the previous mails folded)
	 */
	function mailBodyHtml(m, opts) {
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
		// A known sender imitated: red, above everything; a known and authenticated sender in the junk: orange
		const sc = m.sender_check;
		if (sc && sc.state === 'imitation') {
			banners = banner('danger', 'spoof', '<b>' + th('InboxImitationTitle') + '</b> ' + th('InboxImitationText', sc.name, sc.address)
				+ (sc.detail ? ' <span class="ibx-mono">' + esc(sc.detail) + '</span>' : '')) + banners;
		} else if (sc && sc.state === 'legit' && m.folder_role === 'junk' && !opts.inline) {
			banners += '<div class="ibx-banner ibx-banner--warning">' + ic('warning') + '<div class="ibx-banner__text">' + th('InboxJunkLegitText', sc.name) + '</div>'
				+ '<button type="button" class="ibx-btn ibx-btn--sm ibx-js-notjunk">' + ic('inbox') + ' ' + th('InboxNotJunk') + '</button></div>';
		}
		if (m.unsubscribe && !opts.inline) {
			// Newsletter (B4): one-click by the server, never the link opened; by mail when the composer comes (W6)
			const u = m.unsubscribe;
			const why = !u.oneclick ? t('InboxUnsubscribeByMailLater') : (!u.signed ? t('InboxErrorUnsubscribeNotSigned') : (m.level !== 'manage' ? t('InboxReadOnlyOn', m.mailbox) : ''));
			banners += '<div class="ibx-banner ibx-banner--info ibx-js-unsubbar">' + ic('unsubscribe') + '<div class="ibx-banner__text">' + th('InboxNewsletter')
				+ (why ? ' <span class="ibx-muted">' + esc(why) + '</span>' : '') + '</div>'
				+ '<button type="button" class="ibx-btn ibx-btn--sm ibx-js-unsubscribe"' + (why ? ' aria-disabled="true" title="' + esc(why) + '"' : '') + '>' + th('InboxUnsubscribe') + '</button>'
				+ (u.mailto && !u.oneclick ? '<button type="button" class="ibx-btn ibx-btn--sm ibx-btn--ghost ibx-js-unsubcopy">' + ic('copy') + ' ' + th('InboxCopyAddress', u.mailto) + '</button>' : '') + '</div>';
		}
		// Invitation and read receipt (lot W8 1.22.0, js/inbox-invite.js)
		if (I.mailExtrasHtml) {
			banners += I.mailExtrasHtml(m, opts);
		}
		if (m.body.remote && !m.body.images) {
			banners += '<div class="ibx-banner ibx-banner--info ibx-js-imagesbar">' + ic('remote-blocked') + '<div class="ibx-banner__text">' + th('InboxRemoteBlocked')
				+ (m.body.trackers ? ' ' + th('InboxTrackers', m.body.trackers) : '') + '</div>'
				+ '<button type="button" class="ibx-btn ibx-btn--sm ibx-js-showimages">' + th('InboxShowImages') + '</button>'
				+ (m.auth && m.auth.fail ? '' : '<button type="button" class="ibx-btn ibx-btn--sm ibx-js-allowimages" aria-haspopup="menu">' + th('InboxAlwaysShowImages') + ' ' + ic('chevron-down') + '</button>') + '</div>';
		}
		const files = att.length;
		const total = att.reduce((n, a) => n + a.size, 0);
		const open = params.attachments_open === '1';
		m.fold = !!opts.fold;
		return (banners ? '<div class="ibx-readbanners">' + banners + '</div>' : '')
			+ '<div class="ibx-readbody">' + (m.security && m.security.encrypted ? '' : '<iframe class="ibx-mailframe ibx-js-frame" title="' + th('InboxMailBody') + '"'
				+ ' sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox" referrerpolicy="no-referrer" loading="eager" src="' + bodyUrl(m, m.body.images) + '"></iframe>') + '</div>'
			+ (files ? '<section class="ibx-attbar' + (opts.inline ? ' ibx-attbar--inline' : '') + '" aria-label="' + th('InboxAttachments') + '"><div class="ibx-attbar__head">'
				+ '<button type="button" class="ibx-attbar__toggle ibx-js-atttoggle" aria-expanded="' + open + '">' + ic(open ? 'chevron-down' : 'chevron-right') + ic('attachment')
				+ '<b>' + th('InboxAttachmentsCount', files) + '</b> <span class="ibx-muted">' + esc(size(total)) + '</span>'
				+ '<span class="ibx-attbar__names ibx-ellipsis ibx-muted">' + esc(att.map(a => a.name).join(', ')) + '</span></button>'
				+ '<button type="button" class="ibx-btn ibx-btn--sm ibx-js-saveall">' + ic('download-all') + ' ' + th(files > 1 ? 'InboxSaveAllZip' : 'InboxSave') + '</button></div>'
				+ '<div class="ibx-attachments"' + (open ? '' : ' hidden') + '>' + att.map((a, i) => tile(a, i)).join('') + '</div></section>' : '');
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
		return I.ctx('INBOX_AJAX_URL', '') + 'body.php?id=' + m.id + (images ? '&images=1' : '') + (m.fold ? '&fold=1' : '');
	}

	const KIND_ICON = { pdf: 'file-pdf', image: 'file-image', zip: 'file-zip', mail: 'mail', csv: 'file-xls' };
	function tile(a, i) {
		const warn = (a.warnings || []).length;
		return '<div class="ibx-attachment' + (warn ? ' is-warning' : '') + '" data-att="' + i + '" draggable="true">'
			+ '<button type="button" class="ibx-attachment__open ibx-js-attopen" title="' + esc(a.name) + '">'
			+ ic(KIND_ICON[a.kind] || 'attachment', 'ibx-attachment__icon' + (a.kind === 'pdf' ? ' ibx-attachment__icon--pdf' : (a.kind === 'image' ? ' ibx-attachment__icon--img' : '')))
			+ '<span class="ibx-attachment__info"><span class="ibx-ellipsis">' + esc(a.name) + '</span><span class="ibx-attachment__size">' + esc(size(a.size))
			+ (warn ? ' · <span class="ibx-attachment__warn">' + esc(a.warnings.map(w => t(w)).join(', ')) + '</span>' : '') + '</span></span></button>'
			+ '<button type="button" class="ibx-btn ibx-btn--icon ibx-btn--sm ibx-js-attdownload" title="' + th('InboxDownload') + '" aria-label="' + th('InboxDownload') + ' ' + esc(a.name) + '">' + ic('download') + '</button></div>';
	}

	/** Buttons of the actions of the open mail (head of the reading pane or of the conversation) */
	function bindActions(root) {
		$$('.ibx-js-action', root).forEach(b => b.addEventListener('click', () => {
			const a = actions(S.mail).find(x => x.id === b.dataset.action);
			if (a && a.disabled) {
				toast(a.reason || a.label, { error: true });
			} else if (a) {
				a.run();
			}
		}));
		const more = $('.ibx-js-more', root);
		if (more) {
			more.addEventListener('click', () => menu(more, actions(S.mail).filter(a => !a.button).map(a => a.sep ? a : ({ icon: a.icon, label: a.label, key: a.key, run: a.run, disabled: a.disabled, reason: a.reason }))
				.filter((a, i, all) => !(a.sep && (i === 0 || all[i - 1].sep)))));
		}
	}

	/** Root of a mail on screen: its block in a conversation, else the reading pane */
	function mailRoot(m) {
		return $('[data-mailblock="' + m.id + '"]', readPane()) || readPane();
	}

	function bindMail(m, pane) {
		const frame = $('.ibx-js-frame', pane);
		if (frame) {
			frame.addEventListener('load', () => frameReady(frame, m));
		}
		if (I.bindMailExtras) {
			I.bindMailExtras(m, pane);
		}
		const notJunk = $('.ibx-js-notjunk', pane);
		if (notJunk) {
			notJunk.addEventListener('click', () => doJunk([m.id], false));
		}
		const unsub = $('.ibx-js-unsubscribe', pane);
		if (unsub) {
			unsub.addEventListener('click', () => unsubscribe(m, unsub));
		}
		const unsubCopy = $('.ibx-js-unsubcopy', pane);
		if (unsubCopy) {
			unsubCopy.addEventListener('click', () => copyText(m.unsubscribe.mailto));
		}
		const show = $('.ibx-js-showimages', pane);
		if (show) {
			show.addEventListener('click', () => {
				frame.src = bodyUrl(m, true);
				$('.ibx-js-imagesbar', pane).remove();
			});
		}
		$$('.ibx-addr', pane).forEach(el => el.setAttribute('tabindex', '0'));
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
			const frame = $('.ibx-js-frame', mailRoot(m));
			if (frame) {
				frame.src = bodyUrl(m, true);
			}
			const bar = $('.ibx-js-imagesbar', mailRoot(m));
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
		// Height of the content as drawn (zoom included): the box of the document, not its scrollHeight (never below
		// the height of the frame, so a frame never got smaller, and without the zoom)
		const fit = () => {
			frame.style.height = Math.max(120, Math.ceil(doc.documentElement.getBoundingClientRect().height) + 8) + 'px';
		};
		fit();
		// Called again at each change of zoom: the listeners are bound once per document
		if (doc.ibxBound) {
			return;
		}
		doc.ibxBound = true;
		if (typeof ResizeObserver === 'function' && doc.body) {
			const ro = new ResizeObserver(fit);
			ro.observe(doc.documentElement);
			ro.observe(doc.body);
		}
		doc.addEventListener('click', e => {
			const a = e.target.closest ? e.target.closest('a[href]') : null;
			if (!a) {
				return;
			}
			const href = a.getAttribute('href') || '';
			if (/^mailto:/i.test(href)) {
				// Written in inbox when the user may send, else the mail software of the computer
				if (I.ctx('INBOX_CAN_SEND', false) && I.composeMailto) {
					e.preventDefault();
					I.composeMailto(href);
				}
				return;
			}
			// Only web addresses leave the mail (never javascript:, data:, file:...: audit INBX-001)
			if (!/^(https?:\/\/|tel:|#)/i.test(href)) {
				e.preventDefault();
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
		doc.addEventListener('keydown', e => {
			activity();
			onKey(e, true);
		});
		doc.addEventListener('pointerdown', () => {
			activity();
			closeMenu();
		});
		doc.addEventListener('wheel', activity, { passive: true });
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
		$$('.ibx-js-frame', readPane()).forEach(frame => frameReady(frame, S.mail));
		toast(t('InboxMailZoom', z), { key: 'mail-zoom' });
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

	/**
	 * Print a mail: its body loaded again by body.php (print mode: head of the mail written by the server) in a hidden
	 * frame with the same protections as the reading (CSP without script, remote pictures as chosen, sandbox), never
	 * copied into a window of the application (audit INBX-001, INBX-007)
	 */
	function printMail(m) {
		const old = $('.ibx-js-printframe');
		if (old) {
			old.remove();
		}
		const frame = $('.ibx-js-frame', mailRoot(m));
		const images = !!(frame && /[?&]images=1/.test(frame.getAttribute('src') || ''));
		const f = document.createElement('iframe');
		f.className = 'ibx-js-printframe';
		f.setAttribute('sandbox', 'allow-same-origin allow-modals');
		f.setAttribute('referrerpolicy', 'no-referrer');
		f.setAttribute('aria-hidden', 'true');
		f.tabIndex = -1;
		f.style.cssText = 'position:fixed;left:-10000px;top:0;width:800px;height:600px;border:0';
		f.addEventListener('load', () => {
			try {
				f.contentWindow.focus();
				f.contentWindow.print();
			} catch (e) {
				toast(t('InboxPrintFailed'), { error: true });
			}
			setTimeout(() => f.remove(), 60000);
		}, { once: true });
		f.src = bodyUrl(m, images) + '&print=1';
		document.body.appendChild(f);
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

	// ---------- Context menus (1.5.0, interface §6 bis) ----------
	/*
	 * Menu of inbox where the user acts on objects of inbox (mails of the list, folders, attachments, addresses), menu
	 * of the browser where he reads or writes text (body of the mail: iframe, fields). Shift + right click gives the
	 * menu of the browser everywhere; the setting « Context menus of inbox » (My account) gives it back everywhere.
	 * Same menus by the Menu key or Shift+F10 on the item with the focus, and by a long press on a touch screen.
	 */
	const HINT = () => ({ note: t('InboxBrowserMenuHint') });

	function contextEnabled(e) {
		return params.context_menus !== '0' && !(e && e.shiftKey);
	}

	/** Mails a menu of a row acts on: the selection if the row is in it, else this row alone (it becomes the selection) */
	function contextMails(id) {
		if (!S.selected.has(id)) {
			select(id);
		}
		return Array.from(S.selected);
	}

	function mailMenu(id, at) {
		const ids = contextMails(id);
		const rows = S.rows.filter(r => ids.includes(r.id));
		const row = rows.find(r => r.id === id) || rows[0];
		if (!row) {
			return;
		}
		const one = ids.length === 1;
		const anyUnread = rows.some(r => !r.seen);
		const items = [];
		if (ids.length > 1) {
			items.push({ header: t('InboxSelectedCount', ids.length) });
		}
		if (one) {
			items.push({ icon: 'mail-opened', label: t('InboxOpen'), key: 'Entrée', run: () => openMail(row.id) });
			items.push({ sep: true });
		}
		items.push({ icon: anyUnread ? 'mark-read' : 'unread', label: t(anyUnread ? 'InboxMarkRead' : 'InboxMarkUnread'), key: 'M', run: () => doSeen(ids, anyUnread) });
		const rowEl = $('[data-mail="' + row.id + '"]', S.el);
		mailActionItems(ids, rowEl).forEach(a => items.push(a.id === 'tag' ? Object.assign({}, a, { run: () => tagMenu(rowEl || S.el, ids, at || rectPoint(rowEl)) }) : a));
		if (one) {
			items.push({ sep: true });
			items.push({ icon: 'download', label: t('InboxSaveEml'), run: () => download(I.ctx('INBOX_AJAX_URL', '') + 'body.php?id=' + row.id + '&part=source&download=1') });
			items.push({ icon: 'source', label: t('InboxViewSource'), key: 'Ctrl+U', run: () => openViewer({ mail: { id: row.id, subject: row.subject, attachments: [] }, source: true }) });
			if (row.from_email) {
				items.push({ sep: true });
				items.push({ icon: 'copy', label: t('InboxCopyAddress', row.from_email), run: () => copyText(row.from_email) });
				items.push({ icon: 'search', label: t('InboxMailsOf', row.from_name || row.from_email), run: () => searchFrom(row.from_email) });
			}
		}
		// Actions of the other modules (same registry as the buttons, hook inbox:mail:actions), the mail being known
		if (one && S.mail && S.mail.id === row.id) {
			const extra = actions(S.mail).filter(a => a.module);
			if (extra.length) {
				items.push({ sep: true });
				extra.forEach(a => items.push({ icon: a.icon, label: a.label, key: a.key, run: a.run }));
			}
		}
		items.push(HINT());
		const el = $('[data-mail="' + row.id + '"]', S.el);
		menu(el || S.el, items, at || rectPoint(el));
	}

	function folderMenu(folderId, at) {
		const e = S.folders.get(folderId);
		if (!e) {
			return;
		}
		const el = $('[data-folder="' + folderId + '"]', S.el);
		const items = [
			{ icon: 'folder', label: t('InboxOpen'), run: () => openFolder(folderId) },
			{ icon: 'mark-read', label: t('InboxSyncNow'), run: () => {
				requestSync(folderId, true);
				toast(t('InboxSyncRunning'));
			} },
			{ icon: 'search', label: t('InboxSearchInFolder'), run: () => {
				openFolder(folderId).then(() => $('.ibx-js-search', S.el).focus());
			} },
		];
		// Folders managed from inbox (1.10.0): greyed with the reason, never hidden
		const f = e.folder;
		const manage = e.account.level === 'manage' ? '' : t('InboxReadOnlyOn', e.account.email);
		const special = f.role || isInboxPath(f) ? t('InboxFolderSpecialReason') : '';
		items.push({ sep: true });
		items.push({ icon: 'mark-read', label: t('InboxFolderMarkRead'), disabled: !f.unread || !f.selectable, reason: f.unread ? '' : t('InboxFolderNothingUnread'), run: () => folderMarkRead(f) });
		items.push({ icon: 'download', label: t('InboxImportMails'), disabled: !!manage || !f.selectable, reason: manage, run: () => pickImport(e.account, f) });
		items.push({ icon: 'download-all', label: t('InboxExportFolder'), disabled: !f.selectable || !f.total, reason: f.total ? '' : t('InboxFolderEmptyReason'), run: () => exportDialog(f) });
		if (f.role === 'trash' || f.role === 'junk') {
			items.push({ icon: 'delete', label: t(f.role === 'trash' ? 'InboxEmptyTrash' : 'InboxEmptyJunk'), danger: true, disabled: !!manage, reason: manage, run: () => folderEmpty(e.account, f) });
		}
		items.push({ sep: true });
		items.push({ icon: 'folder-plus', label: t('InboxFolderNewSub'), disabled: !!manage, reason: manage, run: () => folderCreate(e.account, f) });
		items.push({ icon: 'rename', label: t('InboxFolderRename'), disabled: !!(manage || special), reason: manage || special, run: () => folderRename(f) });
		items.push({ icon: 'folder-move', label: t('InboxFolderMove'), disabled: !!(manage || special), reason: manage || special, run: () => folderMoveDialog(e.account, f) });
		items.push({ icon: 'delete', label: t('InboxFolderDelete'), danger: true, disabled: !!(manage || special), reason: manage || special, run: () => folderDelete(e.account, f) });
		if (e.account.level === 'manage' || S.admin) {
			items.push({ sep: true });
			// A special folder keeps its icon: only its colour is offered
			items.push({ icon: 'catalogue', label: t(ROLE_ICON[e.folder.role] ? 'InboxLookEditColor' : 'InboxLookEdit'), run: () => lookDialog(e.account, e.folder) });
			items.push({ icon: 'settings', label: t('InboxAccountSettings'), run: () => I.go('#/settings/accounts/' + e.account.id) });
		}
		items.push(HINT());
		menu(el || S.el, items, at || rectPoint(el));
	}

	// ---------- Folders managed from inbox (lot W4 1.10.0, B10, B11) ----------
	function isInboxPath(f) {
		return String(f.label || f.name || '').toUpperCase() === 'INBOX' && !f.parent;
	}
	/** A folder name asked in a small dialog; the server checks it again */
	function folderNameDialog(title, value, label, run) {
		const d = dialog({
			title,
			body: '<label class="ibx-formrow__label" for="ibx-foldername">' + th('InboxFolderName') + '</label>'
				+ '<input type="text" class="ibx-field ibx-js-foldername" id="ibx-foldername" maxlength="100" autocomplete="off" value="' + esc(value || '') + '">'
				+ '<p class="ibx-formrow__help">' + th('InboxFolderNameHelp') + '</p>',
			actions: [{ label, primary: true, run: async dlg => {
				const name = $('.ibx-js-foldername', dlg).value.trim();
				if (!name) {
					$('.ibx-js-foldername', dlg).focus();
					return false;
				}
				await run(name);
				return true;
			} }],
		});
		const input = $('.ibx-js-foldername', d);
		input.addEventListener('keydown', e => {
			if (e.key === 'Enter') {
				e.preventDefault();
				$('.ibx-dialog__foot .ibx-btn--primary', d).click();
			}
		});
		input.focus();
		input.select();
	}
	async function folderDone(text, folderId) {
		toast(text);
		await refreshTree();
		if (folderId && S.folders.has(folderId)) {
			const e = S.folders.get(folderId);
			// Its parents unfolded: the folder is seen where it is now
			for (let p = e.folder.parent; p; p = (S.folders.get(p) || { folder: {} }).folder.parent) {
				S.collapsed.delete('f' + p);
			}
			S.collapsed.delete('a' + e.account.id);
			renderTree();
		}
	}
	function folderCreate(account, parent) {
		folderNameDialog(parent ? t('InboxFolderNewSubIn', folderName(parent)) : t('InboxFolderNewIn', accountName(account)), '', t('InboxCreate'), async name => {
			const res = await post('folderadmin', 'create', { account: account.id, parent: parent ? parent.id : 0, name });
			await folderDone(t('InboxFolderCreated', name), res.result);
		});
	}
	function folderRename(f) {
		folderNameDialog(t('InboxFolderRenameTitle', folderName(f)), folderName(f), t('InboxRename'), async name => {
			await post('folderadmin', 'rename', { folder: f.id, name });
			await folderDone(t('InboxFolderRenamed', name), f.id);
		});
	}
	/** The new place of a folder: the top of the mailbox or one of its folders (not itself, not in itself) */
	function folderMoveDialog(account, f) {
		const inside = x => {
			for (let p = x.id; p; p = (S.folders.get(p) || { folder: {} }).folder.parent) {
				if (p === f.id) {
					return true;
				}
			}
			return false;
		};
		const options = [{ id: 0, path: accountName(account), top: true, ok: !!f.parent }].concat(account.folders.slice().sort((x, y) => folderPath(x).localeCompare(folderPath(y))).map(x => ({
			id: x.id, folder: x, path: folderPath(x), ok: !inside(x) && x.id !== f.parent,
		})));
		const dlg = dialog({
			title: t('InboxFolderMoveTitle', folderName(f)),
			body: '<input type="search" class="ibx-field ibx-js-fmsearch" autocomplete="off" placeholder="' + th('InboxSearchFolder') + '" aria-label="' + th('InboxSearchFolder') + '">'
				+ '<div class="ibx-picklist ibx-js-fmlist" role="listbox" aria-label="' + th('InboxFolders') + '"></div>',
			actions: [{ label: t('InboxMove'), primary: true, run: async d => {
				const cur = $('[aria-selected="true"]', d);
				if (!cur) {
					return false;
				}
				await post('folderadmin', 'move', { folder: f.id, target: +cur.dataset.id });
				await folderDone(t('InboxFolderMoved', folderName(f)), f.id);
				return true;
			} }],
		});
		const input = $('.ibx-js-fmsearch', dlg);
		const list = $('.ibx-js-fmlist', dlg);
		const paint = () => {
			const q = input.value.trim().toLowerCase();
			let first = true;
			list.innerHTML = options.filter(o => !q || o.path.toLowerCase().includes(q)).map(o => {
				const sel = o.ok && first;
				if (sel) {
					first = false;
				}
				return '<div class="ibx-picklist__item" role="option" data-id="' + o.id + '"' + (o.ok ? '' : ' aria-disabled="true"') + ' aria-selected="' + sel + '" style="--ibx-depth:' + (o.top ? 0 : folderDepth(o.folder) + 1) + '">'
					+ (o.top ? lookIcon(account.icon || 'account', account.color, '') : folderIcon(o.folder, false)) + ' <span class="ibx-ellipsis">' + esc(o.top ? t('InboxFolderTop', o.path) : folderName(o.folder)) + '</span></div>';
			}).join('') || '<div class="ibx-muted ibx-picklist__empty">' + th('InboxNoFolderFound') + '</div>';
		};
		input.addEventListener('input', paint);
		list.addEventListener('click', e => {
			const it = e.target.closest('.ibx-picklist__item');
			if (it && !it.hasAttribute('aria-disabled')) {
				$$('[aria-selected="true"]', list).forEach(x => x.setAttribute('aria-selected', 'false'));
				it.setAttribute('aria-selected', 'true');
			}
		});
		list.addEventListener('dblclick', e => {
			const it = e.target.closest('.ibx-picklist__item');
			if (it && !it.hasAttribute('aria-disabled')) {
				$('.ibx-dialog__foot .ibx-btn--primary', dlg).click();
			}
		});
		paint();
		input.focus();
	}
	/** Into the trash; in the trash (or without trash), for good after a confirmation that says how many mails */
	async function folderDelete(account, f) {
		const trash = account.folders.find(x => x.role === 'trash' && x.primary !== false);
		const inTrash = trash && (() => {
			for (let p = f.parent; p; p = (S.folders.get(p) || { folder: {} }).folder.parent) {
				if (p === trash.id) {
					return true;
				}
			}
			return false;
		})();
		if (trash && !inTrash) {
			const ok = await confirmBox(t('InboxFolderDeleteTitle', folderName(f)), th('InboxFolderToTrashText', folderName(f)), t('InboxFolderDelete'));
			if (!ok) {
				return;
			}
		}
		try {
			await post('folderadmin', 'delete', { folder: f.id });
		} catch (err) {
			if (err.code !== 'InboxErrorConfirmFolderDelete') {
				toast(err.message, { error: true });
				return;
			}
			const ok = await confirmBox(t('InboxFolderDestroyTitle', folderName(f)), th('InboxFolderDestroyText', folderName(f), err.data.count || 0), t('InboxDestroyForGood'));
			if (!ok) {
				return;
			}
			try {
				await post('folderadmin', 'delete', { folder: f.id, confirm: 1 });
			} catch (err2) {
				toast(err2.message, { error: true });
				refreshTree();
				return;
			}
		}
		if (S.folderId === f.id) {
			S.folderId = 0;
		}
		await folderDone(t('InboxFolderDeleted', folderName(f)), 0);
		if (!S.folderId) {
			const a = S.accounts.find(x => x.id === account.id);
			const inbox = a && a.folders.find(x => x.role === 'inbox');
			if (inbox) {
				openFolder(inbox.id);
			}
		}
	}
	async function folderMarkRead(f) {
		try {
			const res = await post('folderadmin', 'markread', { folder: f.id });
			toast(t('InboxFolderMarkedRead', res.count, folderName(f)));
		} catch (err) {
			toast(err.message, { error: true });
			return;
		}
		await refreshTree();
		if (S.folderId === f.id) {
			loadList(true);
		}
	}
	/** Trash or junk emptied for good, the number of mails said first */
	async function folderEmpty(account, f) {
		let count = f.total || 0;
		try {
			await post('folderadmin', 'empty', { folder: f.id });
		} catch (err) {
			if (err.code !== 'InboxErrorConfirmEmpty') {
				toast(err.message, { error: true });
				return;
			}
			count = err.data.count || 0;
		}
		if (!count) {
			toast(t('InboxFolderAlreadyEmpty', folderName(f)));
			return;
		}
		const ok = await confirmBox(t(f.role === 'trash' ? 'InboxEmptyTrash' : 'InboxEmptyJunk'), th('InboxEmptyText', folderName(f), count), t('InboxDestroyForGood'));
		if (!ok) {
			return;
		}
		try {
			const res = await post('folderadmin', 'empty', { folder: f.id, confirm: 1 });
			toast(t('InboxFolderEmptied', res.count, folderName(f)));
		} catch (err) {
			toast(err.message, { error: true });
		}
		await refreshTree();
		if (S.folderId === f.id) {
			loadList(true);
		}
	}

	function accountMenu(accountId, at) {
		const a = S.accounts.find(x => x.id === accountId);
		if (!a || !(a.level === 'manage' || S.admin)) {
			return;
		}
		const el = $('.ibx-tree__row--account[data-account="' + accountId + '"]', S.el);
		const custom = a.folders.filter(f => f.color || f.icon).length;
		const items = [
			{ icon: 'folder-plus', label: t('InboxFolderNew'), disabled: a.level !== 'manage', reason: a.level !== 'manage' ? t('InboxReadOnlyOn', a.email) : '', run: () => folderCreate(a, null) },
			{ sep: true },
			{ icon: 'catalogue', label: t('InboxLookEdit'), run: () => lookDialog(a, null) },
			{ icon: 'folders-show', label: t('InboxLookResetFolders', custom), disabled: !custom, reason: custom ? '' : t('InboxLookNoCustom'), run: async () => {
				try {
					await post('folder', 'lookreset', { id: a.id });
					toast(t('InboxLookResetDone'));
					refreshTree();
				} catch (err) {
					toast(err.message, { error: true });
				}
			} },
			{ sep: true },
			{ icon: 'folder-bolt', label: t('InboxRulesPanelOpen'), disabled: a.level !== 'manage' || !I.rules, reason: a.level !== 'manage' ? t('InboxRulesNoManage') : '', run: () => I.rules.openPanel(a.id) },
			{ icon: 'settings', label: t('InboxAccountSettings'), run: () => I.go('#/settings/accounts/' + a.id) },
			HINT(),
		];
		menu(el || S.el, items, at || rectPoint(el));
	}

	// Colours offered for a mailbox or a folder: the ones of the roles, then the missing ones of Okabe-Ito
	const LOOK_COLORS = [['blue', '#1d6a8a'], ['violet', '#7b4fb3'], ['green', '#2f855a'], ['brown', '#8a5a2b'], ['red', '#c0392b'], ['grey', '#5f6b70'],
		['amber', '#a86d00'], ['orange', '#e69f00'], ['sky', '#56b4e9'], ['pink', '#cc79a7'], ['yellow', '#f0e442'], ['black', '#000000']];

	/**
	 * Colour and icon of a mailbox (folder null) or of a folder, shared by the users of the mailbox (analyse-interface
	 * §4 bis). A special folder only changes its colour.
	 */
	function lookDialog(account, folder) {
		const C = Dolibarr.tools.inboxColors;
		const withIcon = !folder || !ROLE_ICON[folder.role];
		const hasSubs = !!folder && account.folders.some(f => f.parent === folder.id);
		const cur = { color: (folder ? folder.color : account.color) || '', icon: (folder ? folder.icon : account.icon) || '' };
		const name = folder ? folderName(folder) : accountName(account);
		const base = folder ? 'folder' : 'account';
		const preview = () => (folder ? folderIcon(Object.assign({}, folder, { color: cur.color, icon: cur.icon }), false) : lookIcon(cur.icon || 'account', cur.color, ''));
		const body = '<div class="ibx-look__preview" aria-hidden="true"><span class="ibx-js-lookprev"></span> <b>' + esc(name) + '</b></div>'
			+ '<fieldset class="ibx-look__set"><legend>' + th('InboxColor') + '</legend><div class="ibx-look__swatches">'
			+ '<button type="button" class="ibx-look__swatch ibx-look__swatch--default" data-color="" title="' + th('InboxLookDefault') + '"><span class="ibx-sr-only">' + th('InboxLookDefault') + '</span></button>'
			+ LOOK_COLORS.map(([k, c]) => '<button type="button" class="ibx-look__swatch" data-color="' + c + '" style="background:' + c + '" title="' + th('InboxColor_' + k) + '"><span class="ibx-sr-only">' + th('InboxColor_' + k) + '</span></button>').join('')
			+ '<label class="ibx-look__other">' + th('InboxLookOther') + ' <input type="color" class="ibx-color ibx-js-lookother" value="' + esc(cur.color || '#1d6a8a') + '"></label>'
			+ '</div><p class="ibx-formrow__help ibx-look__pale ibx-js-lookpale">' + th('InboxLookPale') + '</p></fieldset>'
			+ (withIcon ? '<fieldset class="ibx-look__set"><legend>' + th('InboxIcon') + '</legend>'
				+ '<input type="search" class="ibx-field ibx-js-lookq" placeholder="' + th('InboxLookIconSearch') + '" aria-label="' + th('InboxLookIconSearch') + '" autocomplete="off">'
				+ '<div class="ibx-look__icons">'
				+ '<button type="button" class="ibx-look__icon" data-icon="" title="' + th('InboxLookDefault') + '">' + ic(base) + '<span class="ibx-sr-only">' + th('InboxLookDefault') + '</span></button>'
				+ (S.icons || []).filter(u => u !== base).map(u => '<button type="button" class="ibx-look__icon" data-icon="' + esc(u) + '" title="' + esc(u) + '">' + ic(u) + '<span class="ibx-sr-only">' + esc(u) + '</span></button>').join('')
				+ '</div></fieldset>' : '<p class="ibx-formrow__help">' + th('InboxLookSpecialIcon') + '</p>')
			+ (hasSubs ? '<label class="ibx-inline"><input type="checkbox" class="ibx-js-looksubs"> ' + th('InboxLookSubfolders') + '</label>' : '');
		const d = dialog({
			title: t(withIcon ? 'InboxLookTitle' : 'InboxLookTitleColor', name),
			wide: withIcon,
			body,
			actions: [{ label: t('InboxApply'), primary: true, run: async dlg => {
				const subs = $('.ibx-js-looksubs', dlg);
				await post('folder', 'look', { id: account.id, folder: folder ? folder.id : 0, color: cur.color, icon: withIcon ? cur.icon : '', subfolders: subs && subs.checked ? 1 : 0 });
				toast(t('InboxLookSaved'));
				refreshTree();
				return true;
			} }],
		});
		const paint = () => {
			$('.ibx-js-lookprev', d).innerHTML = preview();
			$$('[data-color]', d).forEach(b => b.setAttribute('aria-pressed', String(b.dataset.color === cur.color)));
			$$('[data-icon]', d).forEach(b => b.setAttribute('aria-pressed', String(b.dataset.icon === cur.icon)));
			// The line keeps its place when hidden: the dialog must not move while the colour picker of the browser is
			// open on « Other colour » (it closes when its field moves)
			$('.ibx-js-lookpale', d).classList.toggle('is-off', !cur.color || (C.readable(cur.color, 'light') === cur.color && C.readable(cur.color, 'dark') === cur.color));
		};
		d.addEventListener('click', e => {
			const sw = e.target.closest('[data-color]');
			const b = e.target.closest('[data-icon]');
			if (sw) {
				cur.color = sw.dataset.color;
			} else if (b) {
				cur.icon = b.dataset.icon;
			} else {
				return;
			}
			paint();
		});
		$('.ibx-js-lookother', d).addEventListener('input', e => {
			cur.color = e.target.value.toLowerCase();
			paint();
		});
		const q = $('.ibx-js-lookq', d);
		if (q) {
			q.addEventListener('input', () => {
				const v = q.value.trim().toLowerCase();
				$$('[data-icon]', d).forEach(b => {
					b.hidden = v !== '' && b.dataset.icon !== '' && !b.dataset.icon.includes(v);
				});
			});
		}
		paint();
	}

	function attachmentMenu(m, index, el, at) {
		const a = m.attachments[index];
		if (!a) {
			return;
		}
		const box = dolibarOf(el);
		const pieces = box && box._data ? box._data.links.filter(l => l.readable) : [];
		menu(el, [
			{ icon: 'open', label: t('InboxOpen'), run: () => openViewer({ mail: m, index }) },
			{ icon: 'download', label: t('InboxDownload'), run: () => guardedDownload(m, a) },
		].concat(box ? [{ sep: true }].concat(pieces.map(l => ({ icon: l.icon, label: t('InboxAttachTo', l.ref || l.kind), run: () => attachToPiece(box, m, a, l.type, l.id) })),
			[{ icon: 'link', label: t('InboxAttachToOther'), run: () => linkDialog(box, { attach: { m, a } }) }]) : [], [HINT()]), at || rectPoint(el));
	}

	function addressMenu(email, name, el, at) {
		if (!email) {
			return;
		}
		menu(el, [
			{ icon: 'copy', label: t('InboxCopyAddress', email), run: () => copyText(email) },
			{ icon: 'search', label: t('InboxMailsOf', name || email), run: () => searchFrom(email) },
			HINT(),
		], at || rectPoint(el));
	}

	function rectPoint(el) {
		if (!el) {
			return { x: 200, y: 200 };
		}
		const r = el.getBoundingClientRect();
		return { x: Math.min(r.left + 40, r.right - 10), y: r.top + Math.min(r.height, 28) };
	}

	async function copyText(text) {
		try {
			await navigator.clipboard.writeText(text);
			toast(t('InboxCopied', text));
		} catch (e) {
			toast(text);
		}
	}

	/** Mails of a correspondent in the whole mailbox */
	function searchFrom(email) {
		$('.ibx-js-search', S.el).value = '';
		S.q = '';
		S.chips = [{ op: 'from', v: email, label: t('InboxSxFrom', email) }];
		S.scope = 'account';
		S.mobile('list');
		sxChanged();
		renderListHead();
	}

	/** Where a context menu applies: returns a function opening it, or null (the menu of the browser stays) */
	function contextTarget(target) {
		const row = target.closest('[data-mail]');
		if (row && S.el.contains(row)) {
			return at => mailMenu(+row.dataset.mail, at);
		}
		const folder = target.closest('[data-folder]');
		if (folder && S.el.contains(folder)) {
			return at => folderMenu(+folder.dataset.folder, at);
		}
		const box = target.closest('.ibx-tree__row--account[data-account]');
		if (box && S.el.contains(box)) {
			const a = S.accounts.find(x => x.id === +box.dataset.account);
			return a && (a.level === 'manage' || S.admin) ? at => accountMenu(a.id, at) : null;
		}
		const tileEl = target.closest('[data-att]');
		if (tileEl && S.el.contains(tileEl)) {
			const block = tileEl.closest('[data-mailblock]');
			const m = block && S.blocks ? S.blocks[+block.dataset.mailblock] : S.mail;
			return m ? at => attachmentMenu(m, +tileEl.dataset.att, tileEl, at) : null;
		}
		const addr = target.closest('.ibx-addr[data-email]');
		if (addr && S.el.contains(addr)) {
			return at => addressMenu(addr.dataset.email, addr.dataset.name, addr, at);
		}
		return null;
	}

	function bindContextMenus() {
		S.el.addEventListener('contextmenu', e => {
			if (!contextEnabled(e) || inField(e)) {
				return;
			}
			const open = contextTarget(e.target);
			if (open) {
				e.preventDefault();
				open({ x: e.clientX, y: e.clientY });
			}
		});
		// Keyboard: Menu key or Shift+F10 on the item with the focus (or the mail selected)
		S.el.addEventListener('keydown', e => {
			if (!(e.key === 'ContextMenu' || (e.shiftKey && e.key === 'F10')) || params.context_menus === '0') {
				return;
			}
			const target = document.activeElement && S.el.contains(document.activeElement) ? document.activeElement : $('[data-mail][aria-selected="true"]', S.el);
			const open = target ? contextTarget(target) : null;
			if (open) {
				e.preventDefault();
				open(null);
			}
		});
		// Touch: long press (500 ms) without moving
		let press = null;
		S.el.addEventListener('pointerdown', e => {
			if (e.pointerType !== 'touch' || params.context_menus === '0') {
				return;
			}
			const open = contextTarget(e.target);
			if (!open) {
				return;
			}
			const x = e.clientX;
			const y = e.clientY;
			press = { x, y, timer: setTimeout(() => {
				press = null;
				open({ x, y });
			}, 500) };
		});
		const cancel = e => {
			if (press && (!e || e.type !== 'pointermove' || Math.abs(e.clientX - press.x) > 8 || Math.abs(e.clientY - press.y) > 8)) {
				clearTimeout(press.timer);
				press = null;
			}
		};
		S.el.addEventListener('pointermove', cancel);
		S.el.addEventListener('pointerup', () => cancel());
		S.el.addEventListener('pointercancel', () => cancel());
	}

	// ---------- Folder opening, refresh ----------
	async function openFolder(id, keepRoute) {
		const entry = S.folders.get(id);
		if (!entry) {
			return;
		}
		if (S.folderId && S.folderId !== id) {
			leaveFolder(false);
		}
		// Marks used for the dots during this visit: the new mails stay new until the user leaves the folder
		S.shownMarks = Object.assign({}, S.marks[entry.account.id] || {});
		S.folderMax = 0;
		S.folderId = id;
		// Its new mails are marked in the list now, not in the tree
		if (entry.folder.new) {
			entry.folder.new = 0;
			renderTree();
		}
		S.folderRole = entry.folder.role;
		Dolibarr.executeHook('inbox:folder:open', { role: entry.folder.role, account: entry.account.id, el: S.el });
		// The mailbox (and the parents) of the folder opened are unfolded: the user sees where he is
		let unfolded = S.collapsed.delete('a' + entry.account.id);
		for (let p = entry.folder.parent; p; p = (S.folders.get(p) || { folder: {} }).folder.parent) {
			unfolded = S.collapsed.delete('f' + p) || unfolded;
		}
		if (unfolded) {
			saveParam('tree_collapsed', JSON.stringify(Array.from(S.collapsed)));
			renderTree();
		}
		S.scope = 'folder';
		// A deep search done elsewhere does not apply to this folder
		if (S.deep && S.deep.key !== deepKey()) {
			stopDeep(true);
		}
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
		// Synchronised now by the leader tab (unless done less than 15 s ago); its « synced » merges the changes
		requestSync(id, true);
	}

	// ---------- Synchronisation by the browser: one tab only, paused after 30 min without activity ----------
	/*
	 * - Only the leader tab of the browser (Web Locks, js/inbox.js) calls list.php?action=pump: the folder opened (in
	 *   any tab), and the whole mailboxes when the scheduled job does not run. The other tabs pass their requests to
	 *   it on the channel of the tabs and refresh when it says « synced ».
	 * - After 30 min without a click, a key or the wheel in any tab, every automatic call stops (synchronisation and
	 *   state): the session of Dolibarr can expire as on its other pages. Any activity starts them again.
	 */
	const IDLE_MS = 30 * 60 * 1000;
	const relay = { busy: false, queue: [], timer: 0, cron: true, paused: false, lastActive: Date.now(), lastShared: 0 };

	function lastSeen() {
		let shared = 0;
		try {
			shared = parseInt(localStorage.getItem(I.scope('inbox-activity')) || '0', 10) || 0;
		} catch (e) {
			shared = 0;
		}
		return Math.max(relay.lastActive, shared);
	}
	function isIdle() {
		return Date.now() - lastSeen() > IDLE_MS;
	}
	/** Click, key, wheel, back on the tab: activity (kept for the other tabs, at most every 10 s) */
	function activity() {
		const now = Date.now();
		relay.lastActive = now;
		if (now - relay.lastShared > 10000) {
			relay.lastShared = now;
			try {
				localStorage.setItem(I.scope('inbox-activity'), String(now));
			} catch (e) {
				// private window: this tab only
			}
		}
		if (relay.paused) {
			resume(true);
		}
	}
	function pauseIfIdle() {
		if (!isIdle()) {
			return false;
		}
		if (!relay.paused) {
			relay.paused = true;
			const bar = S.el && $('.ibx-js-paused', S.el);
			if (bar) {
				bar.hidden = false;
			}
		}
		return true;
	}
	function resume(tellOthers) {
		relay.paused = false;
		const bar = S.el && $('.ibx-js-paused', S.el);
		if (bar) {
			bar.hidden = true;
		}
		if (tellOthers) {
			I.channel.post({ type: 'active' });
		}
		poll();
		if (I.isLeader()) {
			pumpSoon(0);
		}
	}

	/**
	 * A tab asks a synchronisation of a folder: the leader does it now (opened by a click) or at its next pass, the
	 * other tabs pass the request
	 */
	function requestSync(folderId, now) {
		if (I.isLeader()) {
			if (folderId && !relay.queue.includes(folderId)) {
				relay.queue.push(folderId);
			}
			if (now) {
				pumpSoon(0);
			}
		} else {
			I.channel.post({ type: 'sync-request', folder: folderId || 0, now: !!now });
		}
	}
	function pumpSoon(ms) {
		clearTimeout(relay.timer);
		relay.timer = setTimeout(pump, ms);
	}
	async function pump() {
		clearTimeout(relay.timer);
		if (!I.isLeader() || relay.busy) {
			return;
		}
		if (pauseIfIdle()) {
			return;
		}
		relay.busy = true;
		let next = 20000;
		const folder = relay.queue.length ? relay.queue.shift() : (S.folderId || 0);
		try {
			const r = await post('list', 'pump', { folder });
			relay.cron = !!r.cron;
			const changed = r.stats && (r.stats.new || r.stats.changed || r.stats.deleted);
			if (changed || (r.done || []).some(x => x !== 'folder')) {
				I.channel.post({ type: 'synced' });
				poll();
			}
			if (r.more || relay.queue.length) {
				next = 2000;
			}
		} catch (err) {
			// Server or network down: try again later, never in a loop
			next = 30000;
		} finally {
			relay.busy = false;
		}
		pumpSoon(next);
	}
	Dolibarr.on('inbox:params:changed', p => {
		Object.assign(params, p || {});
		adaptTags();
		if (S.el) {
			renderTree();
			loadList(true);
			placeDolibar();
		}
	});
	I.channel.on(msg => {
		if (msg.type === 'sync-request' && I.isLeader()) {
			requestSync(msg.folder, msg.now);
		} else if (msg.type === 'synced') {
			poll();
		} else if (msg.type === 'active' && relay.paused) {
			resume(false);
		}
	});
	// A mail sent: the list (its Sent folder, Drafts) asked again now
	Dolibarr.on('inbox:compose:sent', () => {
		if (S.el) {
			setTimeout(() => poll(), 500);
		}
	});
	Dolibarr.on('inbox:app:leader', () => {
		// This tab is the leader now (the previous one was closed): it takes the synchronisation over
		if (S.el) {
			pumpSoon(1000);
		}
	});
	['pointerdown', 'keydown', 'wheel', 'touchstart'].forEach(ev => document.addEventListener(ev, activity, { passive: true, capture: true }));

	async function poll() {
		clearTimeout(S.pollTimer);
		if (pauseIfIdle()) {
			// Started again by the next activity (resume)
			return;
		}
		if (document.hidden || !S.el || S.el.closest('[hidden]')) {
			S.pollTimer = setTimeout(poll, 20000);
			return;
		}
		try {
			// The folder open here is synchronised by the leader tab (this one or another); the state (database only)
			// tells the list whether to merge the changes
			if (S.folderId && !I.isLeader()) {
				requestSync(S.folderId, false);
			}
			const st = await post('list', 'state', { folder: S.folderId || 0, mail: S.mail ? S.mail.id : 0, writing: I.composeWriting ? I.composeWriting() : 0 });
			showPresence(st.presence);
			if (st.notif) {
				Dolibarr.executeHook('inbox:notif:state', st.notif);
			}
			let treeChanged = false;
			(st.folders || []).forEach(([id, total, unread, fresh]) => {
				const e = S.folders.get(id);
				// The folder open: its new mails are shown in the list, not in the tree
				const n = id === S.folderId ? 0 : (fresh || 0);
				if (e && (e.folder.total !== total || e.folder.unread !== unread || (e.folder.new || 0) !== n)) {
					e.folder.total = total;
					e.folder.unread = unread;
					e.folder.new = n;
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
				S.folderMax = Math.max(S.folderMax || 0, parseInt(st.open.stamp.split(':')[1], 10) || 0);
			}
		} catch (err) {
			// Network lost: next try
		}
		S.pollTimer = setTimeout(poll, 20000);
	}

	/** « Marie reads this mail » in the head of the mail open (shared mailboxes) */
	function showPresence(p) {
		const head = $('.ibx-pane--read .ibx-readhead', S.el);
		if (!head) {
			return;
		}
		let box = $('.ibx-js-presence', head);
		const users = p && S.mail && p.mail === S.mail.id ? p.users : [];
		if (!users.length) {
			if (box) {
				box.remove();
			}
			return;
		}
		if (!box) {
			box = document.createElement('div');
			box.className = 'ibx-readhead__line ibx-presence ibx-js-presence';
			box.setAttribute('role', 'status');
			head.appendChild(box);
		}
		// « is writing an answer » first (lot W6): two people should not answer the same mail
		const writers = users.filter(u => u.writing);
		const readers = users.filter(u => !u.writing);
		box.innerHTML = '<span class="ibx-assignees">' + users.slice(0, 4).map(u => avatar(u.name, 'u' + u.id, 'ibx-avatar--xs')).join('') + '</span> '
			+ esc([writers.length ? (writers.length === 1 ? t('InboxPresenceWritingOne', writers[0].name) : t('InboxPresenceWritingMany', writers.map(u => u.name).join(', '))) : '',
				readers.length ? (readers.length === 1 ? t('InboxPresenceOne', readers[0].name) : t('InboxPresenceMany', readers.map(u => u.name).join(', '))) : ''].filter(Boolean).join(' · '));
		box.classList.toggle('ibx-presence--writing', writers.length > 0);
	}

	// ---------- Keyboard (interface §11, read only keys of lot W3) ----------
	/** Shared mailbox: the state (and so the presence) asked now, at most once per 2 s */
	let presenceTimer = 0;
	function presenceSoon() {
		const e = S.mail ? S.folders.get(S.mail.folder) : null;
		if (!e || e.account.personal) {
			return;
		}
		clearTimeout(presenceTimer);
		presenceTimer = setTimeout(() => poll(), 800);
	}

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
			toast(t('InboxTextSizeNow', next), { key: 'text-size' });
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
		if ((e.ctrlKey || e.metaKey) && (e.key === 'l' || e.key === 'L') && !e.shiftKey && !inField(e)) {
			const id = writeTarget();
			if (id) {
				e.preventDefault();
				I.compose({ mode: 'forward', mail: id });
			}
			return;
		}
		if (e.ctrlKey || e.metaKey || e.altKey || inField(e)) {
			return;
		}
		const key = e.key;
		if ((key === 'r' || key === 'R') && writeTarget()) {
			e.preventDefault();
			I.compose({ mode: e.shiftKey ? 'replyall' : 'reply', mail: writeTarget() });
			return;
		}
		if ((key === 'c' || key === 'C') && !e.shiftKey) {
			e.preventDefault();
			newMail();
			return;
		}
		if (key === 'F9') {
			e.preventDefault();
			toggleFolders();
		} else if (key === 'ArrowDown' || key === 'ArrowUp') {
			if (e.target.closest && e.target.closest('.ibx-pane--folders, .ibx-menu, dialog')) {
				return;
			}
			e.preventDefault();
			moveSelection(key === 'ArrowDown' ? 1 : -1);
		} else if ((key === 'ArrowRight' || key === 'ArrowLeft') && S.anchor && !(e.target.closest && e.target.closest('.ibx-pane--folders, .ibx-menu, dialog, .ibx-splitter'))) {
			const r = S.rows.find(x => x.id === S.anchor);
			if (r && r.thread && r.thread.count > 1) {
				e.preventDefault();
				toggleThread(r.id, key === 'ArrowRight');
			}
		} else if (key === ' ' && !(e.target.closest && e.target.closest('button, a, input, select, textarea, summary, [role="menu"], [role="menuitem"], dialog, .ibx-pane--folders'))) {
			// Space: assign me / remove me (webmail §5.4 quinquies); a button, a link or a menu keeps its own Space
			const ids = targetIds();
			if (ids.length) {
				e.preventDefault();
				doAssignMe(ids);
			}
		} else if ((key === 'n' || key === 'N') && !e.shiftKey) {
			// Shift+N: the bell (js/inbox-notify.js)
			e.preventDefault();
			nextUnread();
		} else if (key === 'M' && e.shiftKey) {
			e.preventDefault();
			moveDialog(targetIds());
		} else if (key === 's' || key === 'S') {
			e.preventDefault();
			doFlag(targetIds());
		} else if (key === 'w' || key === 'W') {
			e.preventDefault();
			doThreadMark(targetIds(), 'watch');
		} else if (key === 'k' || key === 'K') {
			e.preventDefault();
			doThreadMark(targetIds(), 'ignore');
		} else if (key === 'a' || key === 'A') {
			e.preventDefault();
			doArchive(targetIds());
		} else if (key === 'j' || key === 'J') {
			e.preventDefault();
			doJunk(targetIds(), !e.shiftKey);
		} else if (key === 'Delete') {
			e.preventDefault();
			doDelete(targetIds());
		} else if (/^[0-9]$/.test(key) && !e.shiftKey) {
			e.preventDefault();
			const ids = targetIds();
			if (ids.length) {
				const why = refusal('tag', ids);
				if (why) {
					toast(why, { error: true });
				} else {
					doTagIndex(ids, +key);
				}
			}
		} else if (key === 'm' || key === 'M') {
			e.preventDefault();
			const ids = S.selected.size ? Array.from(S.selected) : (S.mail ? [S.mail.id] : []);
			if (ids.length) {
				const anyUnread = S.rows.some(r => ids.includes(r.id) && !r.seen) || (S.mail && ids.includes(S.mail.id) && !S.mail.seen);
				doSeen(ids, anyUnread);
			}
		} else if (key === 'PageDown' || key === 'PageUp') {
			e.preventDefault();
			// A mail alone scrolls in its body (head and attachments stay), a conversation in the whole pane
			const pane = $('.ibx-pane--read .ibx-reading:not(.ibx-convo) > .ibx-readbody', S.el) || $('.ibx-pane--read .ibx-pane__body', S.el);
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
				S.allInFolder = false;
				paintSelection();
			}
		}
	}

	/** Mail answered by the keys: the one read, else the one under the cursor of the list */
	function writeTarget() {
		if (!I.ctx('INBOX_CAN_SEND', false) || !I.compose) {
			return 0;
		}
		if (S.mail && S.mail.folder_role !== 'drafts') {
			return S.mail.id;
		}
		const r = S.anchor ? rowOf(S.anchor) : null;
		const e = r ? S.folders.get(r.folder) : null;
		return r && !(e && e.folder.role === 'drafts') ? r.id : 0;
	}
	/** New mail, from the mailbox of the folder open (its default identity) */
	function newMail() {
		if (!I.compose) {
			return;
		}
		if (!I.ctx('INBOX_CAN_SEND', false)) {
			toast(t('InboxComposeNoIdentity'), { error: true });
			return;
		}
		const f = currentFolder();
		I.compose({ mode: 'new', account: f ? f.account.id : 0 });
	}

	function keysHelp() {
		const rows = [
			['C', 'InboxNewMail'], ['R', 'InboxReply'], ['Maj+R', 'InboxReplyAll'], ['Ctrl+L', 'InboxForward'], ['Ctrl+Entrée', 'InboxComposeSend'],
			['↑ ↓', 'InboxKeyNav'], ['N', 'InboxKeyNextUnread'], ['M', 'InboxKeyRead'], ['S', 'InboxFlag'], [t('InboxKeySpace'), 'InboxKeyAssign'], ['W', 'InboxWatchThread'], ['K', 'InboxIgnoreThread'], ['Maj+N', 'InboxKeyBell'], ['A', 'InboxArchive'], ['Maj+M', 'InboxMoveToMenu'], ['J / Maj+J', 'InboxKeyJunk'],
			['Suppr', 'InboxDelete'], ['1 … 9', 'InboxKeyTags'], ['0', 'InboxRemoveAllTags'], ['Ctrl+Maj+K', 'InboxKeySearch'], ['F9', 'InboxKeyFolders'],
			['Pg. suiv. / Pg. préc.', 'InboxKeyScroll'], ['Ctrl + / - / 0', 'InboxKeyTextSize'], ['Ctrl+U', 'InboxViewSource'], ['Ctrl+A', 'InboxKeySelectAll'], [t('InboxKeyCtrlSpace'), 'InboxKeyToggleSelect'], ['?', 'InboxKeyHelp'], ['Échap', 'InboxKeyEscape'],
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
			+ '<div class="ibx-pane__head"><span class="ibx-pane__title">' + th('InboxSpaceMail') + '</span><span class="ibx-spacer"></span>'
			+ (I.ctx('INBOX_CAN_SEND', false) ? '<button type="button" class="ibx-btn ibx-btn--primary ibx-btn--sm ibx-js-compose" title="' + th('InboxNewMail') + ' (C)">' + ic('compose') + ' ' + th('InboxNewMail') + '</button>' : '') + '</div>'
			+ '<div class="ibx-toolbar ibx-js-folderfilterbox" hidden><label class="ibx-search">' + ic('filter') + '<input type="search" class="ibx-field ibx-js-folderfilter" placeholder="' + th('InboxFilterFolders') + '" aria-label="' + th('InboxFilterFolders') + '" autocomplete="off"></label></div>'
			+ '<div class="ibx-pane__body"><ul class="ibx-tree" aria-busy="true"><li><span class="ibx-skeleton"></span></li><li><span class="ibx-skeleton" style="width:70%"></span></li><li><span class="ibx-skeleton" style="width:50%"></span></li></ul></div></section>'
			+ '<div class="ibx-splitter" data-split="1" role="separator" aria-orientation="vertical" tabindex="0" aria-label="' + th('InboxResizePane') + '"></div>'
			+ '<section class="ibx-pane ibx-pane--list" aria-label="' + th('InboxMails') + '">'
			+ '<div class="ibx-pane__head">'
			+ '<button type="button" class="ibx-btn ibx-btn--icon ibx-js-back ibx-mobileonly" data-to="folders" aria-label="' + th('InboxFolders') + '">' + ic('chevron-left') + '</button>'
			+ '<button type="button" class="ibx-btn ibx-btn--icon ibx-btn--toggle ibx-js-togglefolders ibx-desktoponly" aria-pressed="false">' + ic('folders-hide') + '</button>'
			+ '<span class="ibx-pane__title ibx-ellipsis ibx-js-listtitle" role="heading" aria-level="1"></span><span class="ibx-spacer"></span>'
			+ '<button type="button" class="ibx-btn ibx-btn--icon ibx-js-view ibx-desktoponly-phone"></button>'
			+ '<button type="button" class="ibx-btn ibx-btn--icon ibx-js-listmenu" aria-haspopup="menu" aria-label="' + th('InboxListOptions') + '">' + ic('more') + '</button></div>'
			// Search field opening into a panel (lot W7 1.19.0)
			+ '<div class="ibx-toolbar"><div class="ibx-sx-holder"><div class="ibx-sx-backdrop ibx-js-sxbackdrop" hidden></div><div class="ibx-sx ibx-js-sx"><div class="ibx-sx__row ibx-js-sxrow">' + ic('search')
			+ '<span class="ibx-sx__pills ibx-js-sxpills"></span>'
			+ '<input type="search" class="ibx-sx__input ibx-js-search" enterkeyhint="search" autocomplete="off" spellcheck="false" role="combobox" aria-expanded="false" aria-controls="ibx-sxpanel" aria-autocomplete="list"'
			+ ' placeholder="' + th('InboxSearchPlaceholder') + '" aria-label="' + th('InboxSearchPlaceholder') + '" aria-describedby="ibx-scopehint"><kbd class="ibx-kbd ibx-js-sxkbd">Ctrl+Maj+K</kbd>'
			+ '<button type="button" class="ibx-btn ibx-btn--ghost ibx-btn--icon ibx-btn--sm ibx-sx__close ibx-js-sxclose" aria-label="' + th('InboxSxClose') + '" title="' + th('InboxSxClose') + '">' + ic('close') + '</button></div>'
			+ '<div class="ibx-sx__panel ibx-js-sxpanel" id="ibx-sxpanel" hidden></div></div></div></div>'
			+ '<div class="ibx-searchinfo"><span id="ibx-scopehint" class="ibx-muted ibx-js-scopehint"></span> <span class="ibx-js-pills" hidden></span><span class="ibx-spacer"></span>'
			+ '<button type="button" class="ibx-btn ibx-btn--ghost ibx-btn--sm ibx-js-sxclear" hidden>' + th('InboxClearSearch') + '</button></div>'
			+ '<div class="ibx-sx-deepbar ibx-js-deepbar" role="status" hidden></div>'
			+ '<div class="ibx-banner ibx-banner--info ibx-paused ibx-js-paused" role="status" hidden>' + ic('info') + '<div class="ibx-banner__text">' + th('InboxSyncPaused') + '</div>'
			+ '<button type="button" class="ibx-btn ibx-btn--sm">' + th('InboxSyncResume') + '</button></div>'
			+ '<div class="ibx-banner ibx-banner--info ibx-js-outboxbar" role="status" hidden></div>'
			+ '<button type="button" class="ibx-newpill ibx-js-newpill" hidden></button>'
			+ '<div class="ibx-bulkbar ibx-js-bulkbar" role="toolbar" aria-label="' + th('InboxBulkActions') + '" hidden></div>'
			+ '<div class="ibx-pane__body" tabindex="-1"></div></section>'
			+ '<div class="ibx-splitter" data-split="2" role="separator" aria-orientation="vertical" tabindex="0" aria-label="' + th('InboxResizePane') + '"></div>'
			+ '<section class="ibx-pane ibx-pane--read" aria-label="' + th('InboxReadingPane') + '">'
			+ '<div class="ibx-pane__head ibx-mobileonly"><button type="button" class="ibx-btn ibx-btn--icon ibx-js-back" data-to="list" aria-label="' + th('InboxBackToList') + '">' + ic('chevron-left') + '</button></div>'
			+ '<div class="ibx-pane__body" tabindex="-1"></div></section>'
			+ (I.ctx('INBOX_CAN_SEND', false) ? '<button type="button" class="ibx-fab ibx-js-compose" aria-label="' + th('InboxNewMail') + '">' + ic('compose') + '</button>' : '')
			+ '</div>';
	}

	function bindLayout() {
		const el = S.el;
		$$('.ibx-js-compose', el).forEach(b => b.addEventListener('click', () => newMail()));

		const readPaneEl = $('.ibx-pane--read', el);
		readPaneEl.addEventListener('dragstart', e => {
			const tileEl = e.target.closest && e.target.closest('.ibx-attachment[data-att]');
			const reading = tileEl && tileEl.closest('.ibx-reading');
			const blockEl = tileEl && tileEl.closest('[data-mailblock]');
			const m = blockEl ? S.blocks[+blockEl.dataset.mailblock] : S.mail;
			if (!tileEl || !reading || !m) {
				return;
			}
			S.attDrag = { m, a: m.attachments[+tileEl.dataset.att] };
			e.dataTransfer.effectAllowed = 'copy';
			e.dataTransfer.setData('text/plain', S.attDrag.a ? S.attDrag.a.name : '');
		});
		readPaneEl.addEventListener('dragend', () => {
			S.attDrag = null;
			$$('.ibx-piece.is-droptarget', readPaneEl).forEach(x => x.classList.remove('is-droptarget'));
		});
		readPaneEl.addEventListener('dragover', e => {
			const piece = S.attDrag && e.target.closest('.ibx-piece[data-type]');
			$$('.ibx-piece.is-droptarget', readPaneEl).forEach(x => x !== piece && x.classList.remove('is-droptarget'));
			if (piece) {
				e.preventDefault();
				e.dataTransfer.dropEffect = 'copy';
				piece.classList.add('is-droptarget');
			}
		});
		readPaneEl.addEventListener('drop', e => {
			const piece = S.attDrag && e.target.closest('.ibx-piece[data-type]');
			if (!piece) {
				return;
			}
			e.preventDefault();
			const drag = S.attDrag;
			S.attDrag = null;
			piece.classList.remove('is-droptarget');
			attachToPiece(dolibarOf(piece), drag.m, drag.a, piece.dataset.type, +piece.dataset.id);
		});
		if (window.ResizeObserver) {
			new ResizeObserver(() => placeDolibar()).observe($('.ibx-pane--read', el));
		}
		$('.ibx-pane--read', el).addEventListener('click', e => {
			const line = e.target.closest('.ibx-js-assignline');
			if (!line) {
				return;
			}
			const ids = line.dataset.ids.split(',').map(Number).filter(Boolean);
			if (e.target.closest('.ibx-js-assignme')) {
				doAssignMe(ids);
			} else if (e.target.closest('.ibx-js-assignto')) {
				assignDialog(ids);
			}
		});
		bindTree();
		bindSplitters();
		bindContextMenus();
		bindUserCards(S.el);
		bindBulkBar();
		bindDragDrop();
		bindSwipes();
		// Ctrl + wheel on the reading pane, around the frame of the mail too: zoom of the mail, never of the browser
		$('.ibx-pane--read', el).addEventListener('wheel', e => {
			if (e.ctrlKey && S.mail) {
				e.preventDefault();
				zoomMail(e.deltaY < 0 ? 10 : -10);
			}
		}, { passive: false });
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
		$('.ibx-js-listmenu', el).addEventListener('click', e => {
			const cur = params.list_sort || 'date:desc';
			const opt = (v, k) => ({ label: t(k), checked: cur === v, run: () => setSort(v) });
			menu(e.currentTarget, [
				opt('date:desc', 'InboxSortDateDesc'), opt('date:asc', 'InboxSortDateAsc'), opt('from:asc', 'InboxSortFrom'), opt('subject:asc', 'InboxSortSubject'), opt('size:desc', 'InboxSortSize'),
				{ sep: true },
				{ label: t('InboxViewTable'), checked: params.view === 'table', run: () => setView('table') },
				{ label: t('InboxViewCards'), checked: params.view !== 'table', run: () => setView('cards') },
				{ label: t('InboxConversations'), checked: params.conversation !== '0', run: () => {
					saveParam('conversation', params.conversation === '0' ? '1' : '0');
					loadList();
				} },
				{ label: t('InboxColumns'), icon: 'view-table', disabled: params.view !== 'table' || isPhone(), run: () => columnsMenu($('.ibx-js-listmenu', el)) },
				{ sep: true },
				{ label: t('InboxDensity_compact'), checked: params.density === 'compact', run: () => setDensity('compact') },
				{ label: t('InboxDensity_normal'), checked: (params.density || 'normal') === 'normal', run: () => setDensity('normal') },
				{ label: t('InboxDensity_comfortable'), checked: params.density === 'comfortable', run: () => setDensity('comfortable') },
			]);
		});
		bindSearchPanel(el);
		const listBody = $('.ibx-pane--list .ibx-pane__body', el);
		// Shift + click: no text selection started by the browser (the range of mails is selected by the click)
		listBody.addEventListener('mousedown', e => {
			if (e.shiftKey && e.target.closest('[data-mail]')) {
				e.preventDefault();
			}
		});
		listBody.addEventListener('click', e => {
			const sortBtn = e.target.closest('[data-sort]');
			if (sortBtn) {
				const [k, dir] = (params.list_sort || 'date:desc').split(':');
				const key = sortBtn.dataset.sort;
				setSort(key + ':' + (k === key ? (dir === 'asc' ? 'desc' : 'asc') : (key === 'date' || key === 'size' ? 'desc' : 'asc')));
				return;
			}
			const tg = e.target.closest('.ibx-js-threadtoggle');
			if (tg) {
				e.stopPropagation();
				toggleThread(+tg.dataset.row);
				return;
			}
			const item = e.target.closest('[data-mail]');
			if (!item || Date.now() - (S.swipedAt || 0) < 400) {
				return;
			}
			const id = +item.dataset.mail;
			if (item.dataset.child) {
				// A mail of an unfolded conversation: opened alone
				S.selected.clear();
				S.selected.add(id);
				S.anchor = id;
				paintSelection();
				openMail(id, true);
				return;
			}
			if (e.target.closest('.ibx-js-check')) {
				select(id, 'toggle');
			} else {
				select(id, e.shiftKey ? 'range' : (e.ctrlKey || e.metaKey ? 'toggle' : ''));
			}
		});
		listBody.addEventListener('keydown', e => {
			// Enter opens; Ctrl+Space adds / removes the row from the selection. Space alone assigns me (onKey, 1.9.0)
			const toggle = e.key === ' ' && (e.ctrlKey || e.metaKey);
			if ((e.key === 'Enter' || toggle) && e.target.closest('[data-mail]')) {
				e.preventDefault();
				select(+e.target.closest('[data-mail]').dataset.mail, toggle ? 'toggle' : '');
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
	/** Open a mail in its folder (a notification of the bell): the folder first, then the mail */
	async function runGoto() {
		const g = S.goto;
		if (!g || !S.el || !S.folders.has(g.folder)) {
			return false;
		}
		S.goto = null;
		if (S.folderId !== g.folder || S.scope !== 'folder' || S.q !== '') {
			await openFolder(g.folder);
		}
		if (S.rows.some(r => r.id === g.mail)) {
			select(g.mail);
			scrollWithin($('.ibx-pane--list .ibx-pane__body', S.el), $('[data-mail="' + g.mail + '"]', S.el));
		} else {
			// Beyond the first page, or inside a folded conversation: opened alone
			S.selected.clear();
			paintSelection();
			openMail(g.mail, true);
		}
		return true;
	}
	Dolibarr.on('inbox:mail:goto', g => {
		S.goto = { folder: +g.folder, mail: +g.mail };
		if (S.el && !S.folders.has(S.goto.folder)) {
			// A folder not in the tree yet (created since): the tree again
			refreshTree().then(() => runGoto());
		} else {
			runGoto();
		}
	});

	I.registerSpace({
		id: 'mail',
		icon: 'mail',
		label: () => t('InboxSpaceMail'),
		async render(el, route) {
			const wanted = +(route.parts[0] || 0);
			// #/mail/<folder>/<mail>: a mail opened from another page (tab « Mails » of a card of Dolibarr)
			if (wanted && +(route.parts[1] || 0)) {
				S.goto = { folder: wanted, mail: +route.parts[1] };
				history.replaceState(null, '', '#/mail/' + wanted);
				if (S.el === el) {
					runGoto();
					return;
				}
			}
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
			if (S.goto && await runGoto()) {
				// Opened from a notification of the bell
			} else if (start) {
				openFolder(start, !!wanted);
			} else {
				renderList();
				showNothing();
			}
			document.addEventListener('keydown', e => onKey(e, false));
			window.addEventListener('pagehide', () => leaveFolder(true));
			// Crossing the phone width: table or cards again
			window.matchMedia('(max-width: 63.9375rem)').addEventListener('change', () => renderList());
			document.addEventListener('visibilitychange', () => {
				if (!document.hidden) {
					poll();
				}
			});
			S.pollTimer = setTimeout(poll, 20000);
			if (I.isLeader()) {
				pumpSoon(1500);
			}
		},
		refresh(msg) {
			if (msg && msg.entity === 'account') {
				refreshTree();
			}
		},
	});
})();
