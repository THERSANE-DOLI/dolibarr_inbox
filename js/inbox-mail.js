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
		// Unified view open (1.29.0): inbox, unread, flagged, mine, sent, drafts ('' = a folder); scope is then « view »
		view: '',
		// Virtual views (1.31.0): the list, the one open, its mailboxes, the counters asked
		vviews: [],
		vview: 0,
		vAccounts: [],
		vcounts: {},
		// One mailbox at a time (1.30.0): the mailbox shown, the list of the mailboxes open under the selector
		box: 0,
		pickOpen: false,
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
		// Names of the people assigned seen in the lists (user => name), their photo of Dolibarr (user => address,
		// '' when none), and the user (lot W4 1.9.0)
		people: {},
		photos: {},
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
		// Actions leaving a folder still running on the server (1.90.1): their mails stay out of the lists read
		// meanwhile, the counts of their folders are not taken from the server
		pending: new Set(),
	};
	try {
		(JSON.parse(params.tree_collapsed || '[]') || []).forEach(k => S.collapsed.add(String(k)));
	} catch (e) {
		// default: the mailboxes and the views open
	}
	// Folders with subfolders: closed until the user opens them (1.39.0), those he opened kept
	S.opened = new Set();
	try {
		(JSON.parse(params.tree_open || '[]') || []).forEach(k => S.opened.add(String(k)));
	} catch (e) {
		// default: closed
	}
	/** A row of the tree open: a folder when the user opened it, a mailbox or the views unless he closed them */
	function isOpen(key) {
		return key.charAt(0) === 'f' ? S.opened.has(key) : !S.collapsed.has(key);
	}
	/**
	 * A row of the tree opened or closed, kept in the settings of the user unless asked otherwise
	 * @param {string} key f<folder>, a<mailbox>, views
	 * @param {boolean} open Open
	 * @param {boolean} keep Kept (false: a drag hovering a folder)
	 * @returns {boolean} Changed
	 */
	function setOpen(key, open, keep = true) {
		if (isOpen(key) === open) {
			return false;
		}
		if (key.charAt(0) === 'f') {
			S.opened[open ? 'add' : 'delete'](key);
			if (keep) {
				saveParam('tree_open', JSON.stringify(Array.from(S.opened)));
			}
		} else {
			S.collapsed[open ? 'delete' : 'add'](key);
			if (keep) {
				saveParam('tree_collapsed', JSON.stringify(Array.from(S.collapsed)));
			}
		}
		return true;
	}
	/**
	 * The mailbox and the parents of a folder opened: the user sees where he is
	 * @param {number} folderId Folder
	 * @param {boolean} withBox The mailbox unfolded too (false: a folded mailbox stays folded, its folder open shown
	 *                          under it alone, 1.49.0)
	 */
	function openPathTo(folderId, withBox = true) {
		const e = S.folders.get(folderId);
		if (!e) {
			return false;
		}
		let changed = withBox ? setOpen('a' + e.account.id, true) : false;
		for (let p = e.folder.parent; p; p = (S.folders.get(p) || { folder: {} }).folder.parent) {
			changed = setOpen('f' + p, true) || changed;
		}
		return changed;
	}
	/** The Inbox of a mailbox: the one of the first level first */
	function boxInbox(a) {
		return a.folders.find(f => f.role === 'inbox' && !f.parent) || a.folders.find(f => f.role === 'inbox') || null;
	}
	/** Unread of a mailbox out of its Inbox (Sent, Drafts, Junk and Trash left out), told on it when it is folded */
	function unreadElsewhere(a) {
		const inbox = boxInbox(a);
		return a.folders.reduce((n, f) => n + (f !== inbox && !['sent', 'drafts', 'junk', 'trash'].includes(f.role) ? f.unread || 0 : 0), 0);
	}
	/** Unread of the subfolders of a folder (Sent and Drafts left out), told on it when it is closed */
	function unreadBelow(a, id) {
		return a.folders.filter(f => f.parent === id).reduce((n, f) => n + (f.role !== 'sent' && f.role !== 'drafts' ? f.unread || 0 : 0) + unreadBelow(a, f.id), 0);
	}

	/** Save a setting of the user (silent: a lost setting is not worth an error) */
	function saveParam(param, value) {
		params[param] = String(value);
		post('userparam', 'set', { param, value: String(value) }).catch(() => {});
	}

	// ---------- Formats ----------
	const locale = I.locale;
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
	/**
	 * Date of the head of a mail on a phone (UX fix series): as the list for the last days (time, « yesterday », weekday),
	 * then day and month with the time, the year only when it is not this one
	 */
	function headDate(ts) {
		if (!ts) {
			return '';
		}
		const d = new Date(ts * 1000);
		const now = new Date();
		const day = x => new Date(x.getFullYear(), x.getMonth(), x.getDate()).getTime();
		if (Math.round((day(now) - day(d)) / 86400000) < 7) {
			return shortDate(ts);
		}
		return d.getFullYear() === now.getFullYear()
			? d.toLocaleDateString(locale(), { day: 'numeric', month: 'short' }) + ' ' + d.toLocaleTimeString(locale(), { hour: '2-digit', minute: '2-digit' })
			: d.toLocaleDateString(locale(), { day: '2-digit', month: '2-digit', year: 'numeric' });
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
	const size = I.size;
	/** Size of a folder in the panel (option of the panel): its mails indexed, « ≈ » when not all are */
	function folderSize(f) {
		if (params.folder_sizes !== '1' || f.bytes === undefined) {
			return '';
		}
		const part = f.indexed < f.total;
		const text = (part ? '≈ ' : '') + size(f.bytes);
		return '<span class="ibx-tree__size"' + (part ? ' title="' + th('InboxFolderSizeIndexed', f.indexed, f.total) + '"' : '') + '>' + esc(text) + '</span>';
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
					img.src = I.ctx('INBOX_AJAX_URL', '') + 'avatar.php?id=' + el.dataset.avatar + (el.dataset.reading ? '&reading=1' : '') + (el.dataset.to ? '&to=1' : '');
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
		// The ordinary folders: their own inside colour (as the folders of the usual mail software)
		const folder = color === 'var(--ibx-folder-other)' ? ' ibx-ficon--folder' : '';
		return svg.replace('<svg class="ibx-icon', '<svg style="' + style + '" class="ibx-ficon' + folder + ' ibx-icon');
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

	/** My mailboxes in the order chosen in My account (as the server gives them), the others after by name */
	function orderAccounts() {
		let order = [];
		try {
			order = JSON.parse(params.accounts_order || '[]').map(Number);
		} catch (e) {
			order = [];
		}
		const at = a => (order.includes(a.id) ? order.indexOf(a.id) : Infinity);
		// Without an order: the personal mailbox first, then by name (as the server gives them)
		const mine = a => (order.length ? 0 : (a.personal ? 0 : 1));
		(S.accounts || []).sort((a, b) => (mine(a) - mine(b)) || (at(a) - at(b)) || String(a.label || a.email).localeCompare(String(b.label || b.email)));
	}
	async function loadTree() {
		// The virtual views with the tree (never blocking it)
		api('view', 'list').then(r => {
			S.vviews = r.views || [];
			renderTree();
			refreshVcounts();
		}).catch(() => {});
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
		const known = new Set(S.accounts.map(a => 'a' + a.id).concat(Array.from(S.folders.keys(), id => 'f' + id), ['views']));
		const stale = Array.from(S.collapsed).filter(k => !known.has(k));
		if (stale.length && S.accounts.length) {
			stale.forEach(k => S.collapsed.delete(k));
			saveParam('tree_collapsed', JSON.stringify(Array.from(S.collapsed)));
		}
		const staleOpen = Array.from(S.opened).filter(k => !known.has(k));
		if (staleOpen.length && S.accounts.length) {
			staleOpen.forEach(k => S.opened.delete(k));
			saveParam('tree_open', JSON.stringify(Array.from(S.opened)));
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
		if (singleBox()) {
			const a = shownBox();
			const manyOne = a.folders.length > 15;
			$('.ibx-js-folderfilterbox', pane).hidden = !manyOne;
			body.innerHTML = awayNotes() + '<ul class="ibx-tree" role="tree" aria-label="' + th('InboxFolders') + '">' + viewsHtml() + boxPickerHtml(a)
				+ branch(a, 0, 0, needle) + '</ul>';
			return;
		}
		const many = S.accounts.reduce((n, a) => n + a.folders.length, 0) > 15;
		const filterBox = $('.ibx-js-folderfilterbox', pane);
		filterBox.hidden = !many;
		let html = '';
		// Something of the current folder drawn on a folded mailbox (selected row, folder shown under it): drawn again
		// when the user opens another folder or a view
		S.peek = false;
		const cur = S.scope === 'folder' ? currentFolder() : null;
		S.accounts.forEach(a => {
			const key = 'a' + a.id;
			const open = !S.collapsed.has(key) || needle !== '';
			const unread = a.folders.filter(f => f.role === 'inbox').reduce((n, f) => n + f.unread, 0);
			// Folded (1.49.0): the row of the mailbox stands for its Inbox (selected when it is open), its other unread
			// told apart, the folder open (not the Inbox) shown alone under it
			const here = !open && cur && cur.account.id === a.id ? cur.folder : null;
			const inboxOn = !!here && here === boxInbox(a);
			const elsewhere = open ? 0 : unreadElsewhere(a);
			S.peek = S.peek || !!here;
			const quotaPct = a.quota && a.quota.limit ? Math.round(100 * a.quota.used / a.quota.limit) : 0;
			const inError = !!a.error;
			const progress = a.state === 'first_sync' && a.progress && a.progress.total ? Math.min(99, Math.floor(100 * a.progress.indexed / a.progress.total)) : -1;
			html += '<li role="treeitem" aria-expanded="' + open + '" aria-level="1"><div class="ibx-tree__row ibx-tree__row--account' + (inError ? ' has-error' : '') + '" style="--ibx-depth:0" data-account="' + a.id + '"'
				+ ' aria-selected="' + inboxOn + '" tabindex="' + (inboxOn ? 0 : -1) + '">'
				+ '<button type="button" class="ibx-tree__twisty" data-toggle="' + key + '" aria-label="' + th(open ? 'InboxCollapse' : 'InboxExpand') + '" tabindex="-1">' + ic(open ? 'chevron-down' : 'chevron-right') + '</button>'
				+ (inError ? ic('warning', 'ibx-tree__icon') : lookIcon(a.icon || 'account', a.color, 'ibx-tree__icon'))
				+ '<span class="ibx-tree__label ibx-ellipsis" title="' + esc(a.email) + '">' + esc(accountName(a)) + '</span>'
				+ (!open && unread ? '<span class="ibx-count" aria-label="' + th('InboxSyncUnread', unread) + '">' + unread + '</span>' : '')
				+ (elsewhere ? '<span class="ibx-count ibx-count--below" title="' + th('InboxUnreadElsewhere', elsewhere) + '" aria-label="' + th('InboxUnreadElsewhere', elsewhere) + '">+' + elsewhere + '</span>' : '')
				// Option of the panel: the space used on the server (quota, in KB)
				+ (params.folder_sizes === '1' && a.quota ? '<span class="ibx-tree__size" title="' + th('InboxQuotaUsed', size(a.quota.used * 1024), size(a.quota.limit * 1024)) + '">' + esc(size(a.quota.used * 1024)) + '</span>' : '')
				+ (inError ? '<button type="button" class="ibx-btn ibx-btn--icon ibx-btn--sm ibx-js-accounterror" data-account="' + a.id + '" title="' + th('InboxSyncState_' + a.state) + '" aria-label="' + th('InboxSyncState_' + a.state) + '">' + ic('info') + '</button>' : '')
				+ '</div>'
				+ (progress >= 0 ? '<div class="ibx-tree__note">' + th('InboxIndexingPct', progress) + '</div>' : '')
				+ (quotaPct >= 90 ? '<div class="ibx-tree__note ibx-tree__note--warning" role="status">' + ic('warning') + ' ' + th('InboxQuotaAlmostFull', quotaPct) + '</div>' : '')
				+ lockedNote(a);
			if (open) {
				html += '<ul role="group">' + branch(a, 0, 1, needle) + '</ul>';
			} else if (here && !inboxOn) {
				html += '<ul role="group">' + folderRow(a, here, 1, false, false, '') + '</ul>';
			}
			html += '</li>';
		});
		body.innerHTML = awayNotes() + '<ul class="ibx-tree" role="tree" aria-label="' + th('InboxFolders') + '">' + viewsHtml() + html + '</ul>';
	}

	/**
	 * Protected mailbox locked in this session (1.89.0): no folder shown, a button to unlock it (password of the
	 * mailbox or approval from another device of the user)
	 */
	function lockedNote(a) {
		if (!a.locked) {
			return '';
		}
		return '<div class="ibx-tree__note ibx-tree__note--locked">' + ic('lock') + ' ' + th('InboxProtectLockedShort')
			+ ' <button type="button" class="ibx-btn ibx-btn--sm ibx-js-unlockbox" data-account="' + a.id + '">' + th('InboxProtectUnlock') + '</button></div>';
	}

	// ---------- One mailbox at a time (1.30.0, analyse-navigation-vues.md §2) ----------
	/** The panel shows one mailbox: chosen in My account, or automatic (on a narrow screen) */
	function singleBox() {
		const mode = params.tree_mode || 'auto';
		return S.accounts.length > 1 && (mode === 'single' || (mode === 'auto' && isPhone()));
	}
	/** The mailbox shown: the one chosen before, else the one of the folder open, else the first */
	function shownBox() {
		let a = S.accounts.find(x => x.id === S.box);
		if (!a) {
			const e = currentFolder();
			a = (e && e.account) || S.accounts.find(x => x.id === +(params.tree_box || 0)) || S.accounts[0];
			S.box = a.id;
		}
		return a;
	}
	function boxUnread(a) {
		return a.folders.filter(f => f.role === 'inbox').reduce((n, f) => n + f.unread, 0);
	}
	/**
	 * Selector of the mailbox, on top of its folders: its icon, its name, its unread; open, the list of the mailboxes
	 * in the panel (not a floating menu: a mail dragged over a mailbox 600 ms opens it, to be dropped in a folder)
	 */
	function boxPickerHtml(a) {
		const inError = !!a.error;
		const progress = a.state === 'first_sync' && a.progress && a.progress.total ? Math.min(99, Math.floor(100 * a.progress.indexed / a.progress.total)) : -1;
		const quotaPct = a.quota && a.quota.limit ? Math.round(100 * a.quota.used / a.quota.limit) : 0;
		const n = boxUnread(a);
		let html = '<li class="ibx-boxpick__li"><div class="ibx-boxpick__wrap"><div class="ibx-boxpick' + (inError ? ' has-error' : '') + '" data-account="' + a.id + '">'
			+ '<button type="button" class="ibx-boxpick__btn" data-boxpick-toggle aria-expanded="' + S.pickOpen + '" aria-haspopup="true" title="' + esc(a.email) + '">'
			+ (inError ? ic('warning', 'ibx-tree__icon') : lookIcon(a.icon || 'account', a.color, 'ibx-tree__icon'))
			+ '<span class="ibx-boxpick__name ibx-ellipsis">' + esc(accountName(a)) + '</span>'
			+ (n ? '<span class="ibx-count" aria-label="' + th('InboxSyncUnread', n) + '">' + n + '</span>' : '')
			+ ic(S.pickOpen ? 'chevron-up' : 'chevron-down', 'ibx-boxpick__caret') + '<span class="ibx-sr-only"> ' + th('InboxBoxChange') + '</span></button>'
			+ (inError ? '<button type="button" class="ibx-btn ibx-btn--icon ibx-btn--sm ibx-js-accounterror" data-account="' + a.id + '" title="' + th('InboxSyncState_' + a.state) + '" aria-label="' + th('InboxSyncState_' + a.state) + '">' + ic('info') + '</button>' : '')
			+ '</div>';
		const notes = (progress >= 0 ? '<div class="ibx-tree__note">' + th('InboxIndexingPct', progress) + '</div>' : '')
			+ (quotaPct >= 90 ? '<div class="ibx-tree__note ibx-tree__note--warning" role="status">' + ic('warning') + ' ' + th('InboxQuotaAlmostFull', quotaPct) + '</div>' : '')
			+ lockedNote(a);
		if (S.pickOpen) {
			html += '<ul class="ibx-boxpick__list" aria-label="' + th('InboxBoxChange') + '">' + S.accounts.map(x => {
				const c = boxUnread(x);
				return '<li><button type="button" class="ibx-boxpick__item" data-boxpick="' + x.id + '"' + (x.id === a.id ? ' aria-current="true"' : '') + ' title="' + esc(x.email) + '">'
					+ (x.error ? ic('warning', 'ibx-tree__icon') : lookIcon(x.icon || 'account', x.color, 'ibx-tree__icon'))
					+ '<span class="ibx-ellipsis">' + esc(accountName(x)) + '</span>' + (c ? '<span class="ibx-count">' + c + '</span>' : '') + '</button></li>';
			}).join('') + '</ul>';
		}
		return html + '</div>' + notes + '</li>';
	}
	/**
	 * Show another mailbox; open its inbox unless a mail is being dragged (it is dropped in one of its folders)
	 * @param {number} id Mailbox
	 * @param {boolean} dragging A mail is dragged
	 */
	function chooseBox(id, dragging) {
		const a = S.accounts.find(x => x.id === id);
		if (!a) {
			return;
		}
		S.pickOpen = false;
		S.box = id;
		saveParam('tree_box', String(id));
		renderTree();
		const inbox = a.folders.find(f => f.role === 'inbox') || a.folders.find(f => f.selectable);
		if (!dragging && inbox && !(S.scope === 'folder' && (S.folders.get(S.folderId) || {}).account === a)) {
			openFolder(inbox.id);
		}
		const btn = $('[data-boxpick-toggle]', S.el);
		if (btn && !dragging) {
			btn.focus();
		}
	}

	// ---------- Unified views (1.29.0, analyse-navigation-vues.md) ----------
	const VIEWS = [['inbox', 'inbox'], ['unread', 'unread'], ['flagged', 'star'], ['mine', 'user-circle'], ['sent', 'sent'], ['drafts', 'drafts']];
	function viewsShown() {
		try {
			const v = JSON.parse(params.views_shown || '[]');
			return Array.isArray(v) ? v : [];
		} catch (e) {
			return ['inbox', 'unread', 'flagged', 'mine'];
		}
	}
	function viewsExcluded() {
		try {
			const v = JSON.parse(params.unified_exclude || '[]');
			return Array.isArray(v) ? v.map(Number) : [];
		} catch (e) {
			return [];
		}
	}
	/** Unread of a view, from the counters of the folders already known (no call): inbox and unread only */
	function viewCount(key) {
		const out = new Set(viewsExcluded());
		let n = 0;
		S.folders.forEach(({ folder: f, account: a }) => {
			if (out.has(a.id)) {
				return;
			}
			if ((key === 'inbox' && f.role === 'inbox') || (key === 'unread' && !['trash', 'junk', 'sent', 'drafts'].includes(f.role))) {
				n += f.unread || 0;
			}
		});
		return n;
	}
	function viewsHtml() {
		const shown = viewsShown();
		const list = VIEWS.filter(([k]) => shown.includes(k));
		const virtual = S.vviews.filter(v => !v.hidden);
		if (!list.length && !virtual.length) {
			return '';
		}
		const open = !S.collapsed.has('views');
		return '<li role="treeitem" aria-expanded="' + open + '" aria-level="1"><div class="ibx-tree__row ibx-tree__row--account ibx-tree__row--views" style="--ibx-depth:0" data-section="views">'
			+ '<button type="button" class="ibx-tree__twisty" data-toggle="views" aria-label="' + th(open ? 'InboxCollapse' : 'InboxExpand') + '" tabindex="-1">' + ic(open ? 'chevron-down' : 'chevron-right') + '</button>'
			+ ic('folders', 'ibx-tree__icon') + '<span class="ibx-tree__label ibx-ellipsis">' + th('InboxViews') + '</span>'
			+ '<button type="button" class="ibx-btn ibx-btn--icon ibx-btn--sm ibx-btn--ghost ibx-js-viewsmenu" title="' + th('InboxViewsChoose') + '" aria-label="' + th('InboxViewsChoose') + '" aria-haspopup="menu">' + ic('more') + '</button></div>'
			+ (open ? '<ul role="group">' + list.map(([k, icon]) => {
				const n = ['inbox', 'unread'].includes(k) ? viewCount(k) : 0;
				const sel = S.scope === 'view' && S.view === k;
				return '<li role="treeitem" aria-level="2"><div class="ibx-tree__row' + (n ? ' has-unread' : '') + '" style="--ibx-depth:1" data-view="' + k + '" aria-selected="' + sel + '" tabindex="' + (sel ? 0 : -1) + '">'
					+ '<span class="ibx-tree__twisty" aria-hidden="true"></span>' + ic(icon, 'ibx-tree__icon ibx-tree__icon--view')
					+ '<span class="ibx-tree__label ibx-ellipsis">' + th('InboxView_' + k) + '</span>'
					+ (n ? '<span class="ibx-count" aria-label="' + th('InboxSyncUnread', n) + '">' + n + '</span>' : '') + '</div></li>';
			}).join('') + virtual.map(vviewRowHtml).join('') + '</ul>' : '') + '</li>';
	}

	// ---------- Virtual views (1.31.0, analyse-navigation-vues.md §4, §8) ----------
	const VVIEW_ICONS = ['folder-heart', 'target', 'star', 'tag', 'bell', 'urgent', 'briefcase', 'coin-euro', 'truck', 'building-factory-2', 'folder-user', 'folder-bolt', 'crown', 'diamond'];
	const VVIEW_COLORS = ['', '#2a78d6', '#0f766e', '#15803d', '#a16207', '#c2410c', '#b91c1c', '#be185d', '#7e22ce', '#475569'];
	function vviewRowHtml(v) {
		const sel = S.vview === v.id;
		const n = v.show_count ? (S.vcounts[v.id] || 0) : 0;
		const shared = !v.mine ? '<span class="ibx-vview__shared" title="' + esc(t('InboxViewSharedBy', v.owner)) + '">' + ic('multi') + '<span class="ibx-sr-only"> ' + esc(t('InboxViewSharedBy', v.owner)) + '</span></span>'
			: (v.shares && v.shares.length ? '<span class="ibx-vview__shared" title="' + esc(t('InboxViewSharedWith', v.shares.map(s => s.name).join(', '))) + '">' + ic('multi') + '</span>' : '');
		// My views are put in order by dragging them (1.34.0); on a phone by their menu
		return '<li role="treeitem" aria-level="2"><div class="ibx-tree__row' + (n ? ' has-unread' : '') + '" style="--ibx-depth:1" data-vview="' + v.id + '" aria-selected="' + sel + '" tabindex="' + (sel ? 0 : -1) + '"' + (v.mine && !isPhone() ? ' draggable="true"' : '') + '>'
			+ '<span class="ibx-tree__twisty" aria-hidden="true"></span>' + lookIcon(v.icon || 'folder-heart', v.color || '', 'ibx-tree__icon')
			+ '<span class="ibx-tree__label ibx-ellipsis">' + esc(v.label) + '</span>' + shared
			+ (n ? '<span class="ibx-count" aria-label="' + th('InboxSyncUnread', n) + '">' + n + '</span>' : '') + '</div></li>';
	}
	/** The words, pills, filters, thirdparty, tag and period of the search shown: what a view keeps */
	function currentSearch() {
		return { q: S.q, chips: S.chips.map(c => ({ op: c.op, v: c.v, label: c.label })), filters: { unread: !!S.filters.unread, flagged: !!S.filters.flagged, attachment: !!S.filters.attachment, mine: !!S.filters.mine },
			soc: S.soc ? { id: S.soc.id, name: S.soc.name } : null, tag: S.tag || '', period: S.period || '' };
	}
	function sameSearch(a, b) {
		const norm = s => JSON.stringify([s.q || '', (s.chips || []).map(c => c.op + ':' + c.v), s.filters || {}, s.soc ? s.soc.id : 0, s.tag || '', s.period || '']);
		return norm(a) === norm(b);
	}
	/** Pills and period written as the search writes them (sxExtra), from a kept search */
	function extraOf(s) {
		const out = (s.chips || []).map(c => c.op + ':"' + String(c.v).replace(/"/g, '') + '"');
		if (s.period) {
			const d = new Date();
			d.setHours(0, 0, 0, 0);
			d.setDate(d.getDate() - ({ today: 0, d7: 7, d30: 30, d365: 365 }[s.period] || 0));
			out.push('after:' + d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'));
		}
		return out.join(' ');
	}
	/** Unread of the views that show a counter: their search with the unread only, one mail asked (the total counts) */
	async function refreshVcounts() {
		clearTimeout(S.vcountTimer);
		// 20 counters at most, each one a search on every mailbox (audit INBX-036)
		const asked = S.vviews.filter(v => v.show_count && !v.hidden).slice(0, 20);
		for (const v of asked) {
			const q = { scope: 'all', q: v.search.q, sort: 'date:desc', cursor: '', limit: 1, threads: 0, unread: 1, vcount: 1 };
			['flagged', 'attachment', 'mine'].forEach(k => {
				if (v.search.filters[k]) {
					q[k] = 1;
				}
			});
			const qx = extraOf(v.search);
			if (qx) {
				q.qx = qx;
			}
			if (v.search.soc) {
				q.soc = v.search.soc.id;
			}
			if (v.search.tag) {
				q.tag = v.search.tag;
			}
			if (v.accounts.length) {
				q.accounts = v.accounts;
			}
			try {
				S.vcounts[v.id] = (await api('list', 'page', q, { method: 'POST' })).total || 0;
			} catch (e) {
				// Kept as it was
			}
		}
		if (asked.length) {
			renderTree();
		}
		S.vcountTimer = setTimeout(refreshVcounts, 120000);
	}
	/** The search of the view applied (words, pills, filters...) on its mailboxes, every one the user reads */
	async function openVview(id, keepRoute) {
		const v = S.vviews.find(x => x.id === id);
		if (!v) {
			return;
		}
		if (S.folderId) {
			leaveFolder(false);
		}
		const s = v.search;
		S.q = s.q || '';
		$('.ibx-js-search', S.el).value = S.q;
		S.chips = (s.chips || []).map(c => ({ op: c.op, v: c.v, label: c.label || c.v }));
		Object.keys(S.filters).forEach(k => {
			S.filters[k] = !!(s.filters && s.filters[k]);
		});
		S.soc = s.soc;
		S.tag = s.tag || '';
		S.period = s.period || '';
		S.view = '';
		S.scope = 'all';
		S.vview = id;
		if (S.peek) {
			renderTree();
		}
		S.vAccounts = v.accounts || [];
		if (S.deep) {
			stopDeep(true);
		}
		S.selected.clear();
		S.anchor = 0;
		S.stamp = '';
		$$('[data-folder], [data-view], [data-vview]', S.el).forEach(r => {
			const on = +r.dataset.vview === id;
			r.setAttribute('aria-selected', on ? 'true' : 'false');
			r.setAttribute('tabindex', on ? '0' : '-1');
		});
		if (!keepRoute) {
			history.replaceState(null, '', '#/mail/vview/' + id);
		}
		S.mobile('list');
		showNothing();
		renderFilters();
		sxRenderChips();
		renderListHead();
		await loadList();
	}
	/** Out of a virtual view: its search goes too (it was the view, not a search of the user) */
	function leaveVview() {
		if (!S.vview) {
			return;
		}
		S.vview = 0;
		S.vAccounts = [];
		S.q = '';
		$('.ibx-js-search', S.el).value = '';
		S.chips = [];
		Object.keys(S.filters).forEach(k => {
			S.filters[k] = false;
		});
		S.soc = null;
		S.tag = '';
		S.period = '';
		renderFilters();
		sxRenderChips();
		$$('[data-vview]', S.el).forEach(r => {
			r.setAttribute('aria-selected', 'false');
			r.setAttribute('tabindex', '-1');
		});
	}
	/** Menu of a virtual view: open, change, copy, hide or remove; the hidden ones shown again from the section */
	function vviewMenu(anchor, id, at) {
		const v = S.vviews.find(x => x.id === id);
		if (!v) {
			return;
		}
		const items = [{ header: v.label }, { icon: 'mail-opened', label: t('InboxOpen'), run: () => openVview(id) }];
		if (v.editable) {
			items.push({ icon: 'rename', label: t('InboxViewEdit'), run: () => vviewDialog(v, false) });
		}
		// Order of my views (1.34.0): the same as by dragging them, for the keyboard and the phone
		const mine = S.vviews.filter(x => x.mine);
		const at2 = mine.indexOf(v);
		if (v.mine && at2 > 0) {
			items.push({ icon: 'chevron-up', label: t('InboxMoveUp'), run: () => moveVview(id, at2 - 1) });
		}
		if (v.mine && at2 >= 0 && at2 < mine.length - 1) {
			items.push({ icon: 'chevron-down', label: t('InboxMoveDown'), run: () => moveVview(id, at2 + 1) });
		}
		if (!v.mine) {
			items.push({ icon: 'copy', label: t('InboxViewCopy'), run: () => vviewCall('copy', { id }, t('InboxViewCopied')) });
			items.push({ icon: 'folders-hide', label: t('InboxViewHide'), run: () => vviewCall('hide', { id, hide: 1 }, '') });
			// Its number of unread: the choice of each reader, off until asked (audit INBX-036)
			items.push({ icon: 'unread', label: t(v.show_count ? 'InboxViewCountOff' : 'InboxViewCounterLabel'), run: () => vviewCall('count', { id, on: v.show_count ? 0 : 1 }, '') });
		} else {
			items.push({ sep: true }, { icon: 'delete', label: t('InboxViewDelete'), danger: true, run: async () => {
				if (await I.confirmDialog({ title: t('InboxViewDeleteTitle', v.label), text: v.shares && v.shares.length ? t('InboxViewDeleteShared') : t('InboxViewDeleteText'), confirm: t('InboxViewDelete'), danger: true })) {
					vviewCall('delete', { id }, t('InboxViewDeleted'));
				}
			} });
		}
		menu(anchor, items, at);
	}
	/** One of my views put at a place among my views (the views shared with me stay after them): shown at once, kept */
	function moveVview(id, to) {
		const mine = S.vviews.filter(x => x.mine);
		const from = mine.findIndex(x => x.id === id);
		if (from < 0 || from === to) {
			return;
		}
		const [v] = mine.splice(from, 1);
		mine.splice(Math.max(0, Math.min(to, mine.length)), 0, v);
		S.vviews = mine.concat(S.vviews.filter(x => !x.mine));
		renderTree();
		const row = $('[data-vview="' + id + '"]', S.el);
		if (row && document.activeElement && document.activeElement.closest('.ibx-pane--folders')) {
			row.focus();
		}
		vviewCall('order', { ids: mine.map(x => x.id) }, '');
	}
	async function vviewCall(action, data, done) {
		try {
			const r = await post('view', action, data);
			S.vviews = r.views || S.vviews;
			if (action === 'delete' || (action === 'hide' && data.hide)) {
				if (S.vview === data.id) {
					const e = currentFolder();
					leaveVview();
					if (e) {
						openFolder(e.folder.id);
					}
				}
			}
			renderTree();
			refreshVcounts();
			if (done) {
				toast(done);
			}
			return r;
		} catch (err) {
			toast(err.message, { error: true });
			return null;
		}
	}
	/**
	 * Keep the search shown as a view, or change a view: name, icon, colour, mailboxes, counter; for its author the
	 * sharing (users and groups) and whether the others may change it too
	 * @param {object|null} v View, null for a new one
	 * @param {boolean} withSearch Take the search shown (a new view, « Update the view »)
	 */
	function vviewDialog(v, withSearch) {
		const own = !v || v.mine;
		const cur = v || { label: '', icon: 'folder-heart', color: '', accounts: [], show_count: 0, shared_edit: 0, shares: [] };
		let shares = (cur.shares || []).slice();
		let icon = cur.icon || 'folder-heart';
		let color = cur.color || '';
		const changed = v && !sameSearch(v.search, currentSearch()) && S.vview === v.id;
		const body = '<form class="ibx-form ibx-vviewform" novalidate>'
			+ '<div class="ibx-formrow"><label class="ibx-formrow__label" for="vv-label">' + th('InboxViewName') + '</label><div><input class="ibx-field" id="vv-label" name="label" maxlength="128" required autocomplete="off" value="' + esc(cur.label) + '"></div></div>'
			+ '<div class="ibx-formrow"><span class="ibx-formrow__label">' + th('InboxViewIcon') + '</span><div class="ibx-vviewform__icons" role="radiogroup" aria-label="' + th('InboxViewIcon') + '">'
			+ VVIEW_ICONS.map(i => '<button type="button" class="ibx-btn ibx-btn--icon ibx-btn--sm" data-icon="' + i + '" aria-pressed="' + (i === icon) + '" aria-label="' + esc(i) + '">' + ic(i) + '</button>').join('') + '</div></div>'
			+ '<div class="ibx-formrow"><span class="ibx-formrow__label">' + th('InboxViewColor') + '</span><div class="ibx-vviewform__colors" role="radiogroup" aria-label="' + th('InboxViewColor') + '">'
			+ VVIEW_COLORS.map(c => '<button type="button" class="ibx-vviewform__swatch" data-color="' + c + '" aria-pressed="' + (c === color) + '" aria-label="' + esc(c || t('InboxViewNoColor')) + '"' + (c ? ' style="background:' + c + '"' : '') + '></button>').join('') + '</div></div>'
			+ '<div class="ibx-formrow"><span class="ibx-formrow__label">' + th('InboxViewBoxes') + '</span><div>'
			+ '<div class="ibx-vviewform__edit"><label class="ibx-radio"><input type="radio" name="boxes" value="all"' + (cur.accounts.length ? '' : ' checked') + '> ' + th('InboxViewBoxesAll') + '</label> '
			+ '<label class="ibx-radio"><input type="radio" name="boxes" value="some"' + (cur.accounts.length ? ' checked' : '') + '> ' + th('InboxViewBoxesSome') + '</label></div>'
			+ '<div class="ibx-vviewform__boxes"' + (cur.accounts.length ? '' : ' hidden') + '>' + S.accounts.map(a => '<label class="ibx-check"><input type="checkbox" name="acc" value="' + a.id + '"' + (cur.accounts.includes(a.id) ? ' checked' : '') + '> ' + esc(accountName(a)) + '</label>').join('') + '</div></div></div>'
			// The counter: the choice of the author for himself; a reader chooses his own from the menu of the view
			+ (own ? '<div class="ibx-formrow"><span class="ibx-formrow__label">' + th('InboxViewCounter') + '</span><div><label class="ibx-check"><input type="checkbox" name="show_count"' + (cur.show_count ? ' checked' : '') + '> ' + th('InboxViewCounterLabel') + '</label></div></div>' : '')
			+ (own ? '<div class="ibx-formrow"><label class="ibx-formrow__label" for="vv-share">' + th('InboxViewShare') + '</label><div>'
				+ '<input class="ibx-field" id="vv-share" list="vv-share-list" autocomplete="off" placeholder="' + th('InboxSearchUserOrGroup') + '"><datalist id="vv-share-list"></datalist>'
				+ '<div class="ibx-vviewform__shares ibx-js-vvshares"></div>'
				+ '<div class="ibx-formrow__help">' + th('InboxViewShareHelp') + '</div>'
				+ '<div class="ibx-vviewform__edit"><label class="ibx-radio"><input type="radio" name="shared_edit" value="0"' + (cur.shared_edit ? '' : ' checked') + '> ' + th('InboxViewOthersUse') + '</label> '
				+ '<label class="ibx-radio"><input type="radio" name="shared_edit" value="1"' + (cur.shared_edit ? ' checked' : '') + '> ' + th('InboxViewOthersEdit') + '</label></div></div></div>'
				: '<p class="ibx-formrow__help">' + th('InboxViewSharedBy', cur.owner || '') + '</p>')
			+ (changed ? '<div class="ibx-formrow"><span class="ibx-formrow__label"></span><div><label class="ibx-check"><input type="checkbox" name="take" checked> ' + th('InboxViewTakeSearch') + '</label></div></div>' : '')
			+ '</form>';
		const d = dialog({
			wide: true,
			title: v ? t('InboxViewEditTitle', v.label) : t('InboxViewNew'),
			body,
			actions: [{ label: t('InboxSave'), primary: true, run: async dd => {
				const f = $('form', dd);
				const label = f.elements.label.value.trim();
				if (!label) {
					f.elements.label.focus();
					return false;
				}
				const some = f.querySelector('[name="boxes"]:checked').value === 'some';
				const accounts = some ? $$('[name="acc"]:checked', f).map(c => +c.value) : [];
				if (some && !accounts.length) {
					toast(t('InboxViewNeedBox'), { error: true });
					return false;
				}
				const take = !v || withSearch || (f.elements.take && f.elements.take.checked);
				const data = { id: v ? v.id : 0, label, icon, color, accounts, show_count: f.elements.show_count && f.elements.show_count.checked ? 1 : 0, search: take ? currentSearch() : v.search };
				if (own) {
					data.shares = shares.map(s => ({ kind: s.kind, id: s.id }));
					data.shared_edit = +((f.querySelector('[name="shared_edit"]:checked') || {}).value || 0);
				}
				const r = await vviewCall('save', data, t('InboxViewSaved'));
				if (!r) {
					return false;
				}
				openVview(r.id);
				return true;
			} }],
		});
		const form = $('form', d);
		form.addEventListener('click', e => {
			const b = e.target.closest('[data-icon]');
			if (b) {
				icon = b.dataset.icon;
				$$('[data-icon]', form).forEach(x => x.setAttribute('aria-pressed', String(x === b)));
			}
			const c = e.target.closest('[data-color]');
			if (c) {
				color = c.dataset.color;
				$$('[data-color]', form).forEach(x => x.setAttribute('aria-pressed', String(x === c)));
			}
			const x = e.target.closest('[data-unshare]');
			if (x) {
				shares = shares.filter(s => s.kind + s.id !== x.dataset.unshare);
				paintShares();
			}
		});
		$$('[name="boxes"]', form).forEach(r => r.addEventListener('change', () => {
			$('.ibx-vviewform__boxes', form).hidden = form.querySelector('[name="boxes"]:checked').value !== 'some';
		}));
		function paintShares() {
			const box = $('.ibx-js-vvshares', form);
			if (box) {
				box.innerHTML = shares.map(s => '<span class="ibx-chip">' + ic(s.kind === 'group' ? 'multi' : 'user-circle') + ' ' + esc(s.name)
					+ ' <button type="button" class="ibx-chip__x" data-unshare="' + s.kind + s.id + '" aria-label="' + esc(t('InboxViewUnshare', s.name)) + '">' + ic('close') + '</button></span>').join(' ');
			}
		}
		paintShares();
		// People and groups searched as typed (the search of the accesses); a name chosen goes in the list
		const input = $('#vv-share', form);
		if (input) {
			const found = new Map();
			let timer = 0;
			input.addEventListener('input', () => {
				const hit = found.get(input.value);
				if (hit) {
					if (!shares.some(s => s.kind === hit.kind && s.id === hit.id)) {
						shares.push(hit);
						paintShares();
					}
					input.value = '';
					return;
				}
				clearTimeout(timer);
				timer = setTimeout(async () => {
					try {
						const res = (await api('view', 'people', { q: input.value })).results || [];
						res.forEach(x => found.set(x.name + (x.kind === 'group' ? ' (' + t('InboxGroup') + ')' : ''), { kind: x.kind, id: x.id, name: x.name }));
						$('#vv-share-list', form).innerHTML = res.map(x => '<option value="' + esc(x.name + (x.kind === 'group' ? ' (' + t('InboxGroup') + ')' : '')) + '"></option>').join('');
					} catch (err) {
						// The field stays usable with what it has
					}
				}, 200);
			});
		}
	}
	/** A view shown or hidden; the view open and hidden: back to the last folder */
	function setViewShown(k, on) {
		const shown = viewsShown();
		const next = on ? VIEWS.map(([x]) => x).filter(x => x === k || shown.includes(x)) : shown.filter(x => x !== k);
		saveParam('views_shown', JSON.stringify(next));
		if (S.scope === 'view' && !next.includes(S.view)) {
			const e = currentFolder();
			if (e) {
				openFolder(e.folder.id);
			}
		}
		renderTree();
	}
	/**
	 * Menu of the views (« ⋯ » of the section, right click on the section or on a view): on a view, open it and hide
	 * it first; then the views shown (check boxes) and the mailboxes included (My account)
	 * @param {HTMLElement} anchor Button or row
	 * @param {object} [at] Position of a right click
	 * @param {string} [key] The view right clicked
	 */
	function viewsMenu(anchor, at, key) {
		const shown = viewsShown();
		const own = key ? [
			{ header: t('InboxView_' + key) },
			{ icon: 'mail-opened', label: t('InboxOpen'), run: () => openView(key) },
			{ icon: 'folders-hide', label: t('InboxViewHide'), run: () => setViewShown(key, false) },
			{ sep: true },
		] : [];
		const hiddenShared = S.vviews.filter(v => v.hidden);
		menu(anchor, own.concat([{ header: t('InboxViewsChoose') }], VIEWS.map(([k, icon]) => ({ icon, label: t('InboxView_' + k), checked: shown.includes(k), run: () => setViewShown(k, !shown.includes(k)) })),
			hiddenShared.length ? [{ header: t('InboxViewsHiddenShared') }].concat(hiddenShared.map(v => ({ icon: v.icon || 'folder-heart', label: t('InboxViewShowAgain', v.label), run: () => vviewCall('hide', { id: v.id, hide: 0 }, '') }))) : [],
			[{ sep: true }, { icon: 'settings', label: t('InboxViewsMailboxes'), run: () => I.go('#/settings/me/unified') }]), at);
	}
	/** A unified view: every mailbox read (not left out), the folders of its role; each mail in its own mailbox */
	async function openView(key, keepRoute) {
		if (!VIEWS.some(([k]) => k === key)) {
			return;
		}
		if (S.folderId) {
			leaveFolder(false);
		}
		leaveVview();
		S.view = key;
		S.scope = 'view';
		if (S.peek) {
			renderTree();
		}
		if (S.deep) {
			stopDeep(true);
		}
		S.selected.clear();
		S.anchor = 0;
		S.stamp = '';
		$$('[data-folder]', S.el).forEach(r => {
			r.setAttribute('aria-selected', 'false');
			r.setAttribute('tabindex', '-1');
		});
		$$('[data-view]', S.el).forEach(r => {
			const on = r.dataset.view === key;
			r.setAttribute('aria-selected', on ? 'true' : 'false');
			r.setAttribute('tabindex', on ? '0' : '-1');
		});
		if (!keepRoute) {
			history.replaceState(null, '', '#/mail/view/' + key);
		}
		S.mobile('list');
		showNothing();
		renderListHead();
		await loadList();
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

	/** Order of my folders among their brothers (1.42.0): ids per mailbox, kept for me; the others by name */
	function folderOrderOf(accountId) {
		try {
			const o = JSON.parse(params.folder_order || '{}');
			return (o && Array.isArray(o[accountId]) ? o[accountId] : []).map(Number);
		} catch (e) {
			return [];
		}
	}
	/** Special folders first in their order (rank), then mine in the order I chose, then the others as given */
	function sortKids(a, kids) {
		const ord = folderOrderOf(a.id);
		const at = f => (ord.includes(f.id) ? ord.indexOf(f.id) : 1e9);
		return kids.slice().sort((x, y) => ((x.rank ?? 9) - (y.rank ?? 9)) || (at(x) - at(y)));
	}
	/** An ordinary folder: moved and ordered by hand (a special folder keeps its place) */
	function movableFolder(f) {
		return !f.role && !isInboxPath(f) && (f.rank ?? 9) >= 9;
	}
	/**
	 * A folder put before or after a brother, in my order (nothing changes on the server)
	 * @param {number} id Folder moved
	 * @param {number} targetId Brother
	 * @param {boolean} after After it
	 */
	function placeFolder(id, targetId, after) {
		const e = S.folders.get(id);
		if (!e || id === targetId) {
			return;
		}
		const a = e.account;
		const brothers = sortKids(a, a.folders.filter(f => f.parent === e.folder.parent && movableFolder(f))).map(f => f.id).filter(x => x !== id);
		const at = brothers.indexOf(targetId);
		if (at < 0) {
			return;
		}
		brothers.splice(at + (after ? 1 : 0), 0, id);
		let o = {};
		try {
			o = JSON.parse(params.folder_order || '{}') || {};
		} catch (err) {
			o = {};
		}
		const known = new Set(a.folders.map(f => f.id));
		o[a.id] = folderOrderOf(a.id).filter(x => known.has(x) && !brothers.includes(x)).concat(brothers);
		saveParam('folder_order', JSON.stringify(o));
		renderTree();
	}
	/** The brother before or after a folder in my order, for Move up / Move down */
	function brotherOf(id, step) {
		const e = S.folders.get(id);
		const brothers = e ? sortKids(e.account, e.account.folders.filter(f => f.parent === e.folder.parent && movableFolder(f))) : [];
		const i = brothers.findIndex(f => f.id === id);
		return i < 0 ? null : (brothers[i + step] || null);
	}
	/**
	 * A folder put into another one (or at the first level, target 0) on the server: its manager only, undone in the
	 * toast
	 * @param {object} f Folder
	 * @param {number} target Folder target, 0 for the first level
	 */
	async function folderMoveInto(f, target) {
		const from = f.parent || 0;
		try {
			await post('folderadmin', 'move', { folder: f.id, target });
		} catch (err) {
			toast(err.message, { error: true });
			return;
		}
		await refreshTree();
		openPathTo(f.id);
		renderTree();
		toast(t('InboxFolderMoved', folderName(f)), { action: t('InboxUndo'), onAction: async () => {
			try {
				await post('folderadmin', 'move', { folder: f.id, target: from });
				await folderDone(t('InboxUndone'), f.id);
			} catch (err) {
				toast(err.message, { error: true });
			}
		} });
	}

	/** Folders under a parent (0 = first level), depth for the indent */
	function branch(a, parent, depth, needle) {
		const kids = sortKids(a, a.folders.filter(f => f.parent === parent));
		let html = '';
		kids.forEach(f => {
			const sub = a.folders.some(x => x.parent === f.id);
			const key = 'f' + f.id;
			const matches = !needle || folderName(f).toLowerCase().includes(needle) || f.label.toLowerCase().includes(needle);
			const subHtml = sub ? branch(a, f.id, depth + 1, needle) : '';
			if (!matches && !subHtml) {
				return;
			}
			const open = sub && (isOpen(key) || needle !== '');
			html += folderRow(a, f, depth, sub, open, subHtml);
		});
		return html;
	}
	/**
	 * One folder of the tree (its subfolders already drawn)
	 * @param {Object} a Mailbox
	 * @param {Object} f Folder
	 * @param {number} depth Indent
	 * @param {boolean} sub It has subfolders
	 * @param {boolean} open Unfolded on them
	 * @param {string} subHtml Its subfolders
	 */
	function folderRow(a, f, depth, sub, open, subHtml) {
		const key = 'f' + f.id;
		// Closed: the unread of its subfolders told on it, apart from its own (nothing hidden)
		const below = sub && !open ? unreadBelow(a, f.id) : 0;
		const selected = f.id === S.folderId && S.scope === 'folder';
		return '<li role="treeitem" aria-level="' + (depth + 1) + '"' + (sub ? ' aria-expanded="' + open + '"' : '') + '>'
			+ '<div class="ibx-tree__row' + (f.unread && f.role !== 'sent' && f.role !== 'drafts' ? ' has-unread' : '') + (f.error ? ' has-error' : '') + (f.selectable ? '' : ' is-disabled') + '" style="--ibx-depth:' + depth + '"'
			+ ' data-folder="' + f.id + '" aria-selected="' + selected + '" tabindex="' + (selected ? 0 : -1) + '"' + (movableFolder(f) && !isPhone() ? ' draggable="true"' : '') + '>'
			+ '<button type="button" class="ibx-tree__twisty"' + (sub ? ' data-toggle="' + key + '" aria-label="' + th(open ? 'InboxCollapse' : 'InboxExpand') + '"' : ' aria-hidden="true"') + ' tabindex="-1">' + (sub ? ic(open ? 'chevron-down' : 'chevron-right') : '') + '</button>'
			// An ordinary folder unfolded on its subfolders is drawn open
			+ folderIcon(f, open, 'ibx-tree__icon')
			+ '<span class="ibx-tree__label ibx-ellipsis">' + esc(folderName(f)) + '</span>'
			// Mails on their way into it (1.90.1): the work goes on on the server
			+ (pendingTargets().has(f.id) ? '<span class="ibx-spinner ibx-tree__busy" role="img" title="' + th('InboxProgressMove', folderName(f)) + '" aria-label="' + th('InboxProgressMove', folderName(f)) + '"></span>' : '')
			+ (f.new ? '<span class="ibx-newdot" title="' + th('InboxNewCount', f.new) + '"><span class="ibx-sr-only">' + th('InboxNewCount', f.new) + '</span></span>' : '')
			// Junk: mails probably legitimate (known sender, authenticated), seen without opening the folder
			+ (f.role === 'junk' && f.legit ? '<span class="ibx-tree__legit" title="' + th('InboxJunkLegitCount', f.legit) + '">' + ic('warning') + f.legit + '<span class="ibx-sr-only"> ' + th('InboxJunkLegitCount', f.legit) + '</span></span>' : '')
			+ (f.unread && f.role !== 'sent' && f.role !== 'drafts' ? '<span class="ibx-count" aria-label="' + th('InboxSyncUnread', f.unread) + '">' + f.unread + '</span>' : '')
			// Drafts: how many wait, in grey (they are not unread); the count of the server, the mails sending
			// later (queue of inbox) left out (1.25.1)
			+ (f.role === 'drafts' && f.total > 0 ? '<span class="ibx-count ibx-count--muted" aria-label="' + th('InboxDraftsCount', f.total) + '">' + f.total + '</span>' : '')
			+ (below ? '<span class="ibx-count ibx-count--below" title="' + th('InboxUnreadBelow', below) + '" aria-label="' + th('InboxUnreadBelow', below) + '">+' + below + '</span>' : '')
			+ folderSize(f)
			+ '</div>'
			+ (open ? '<ul role="group">' + subHtml + '</ul>' : '')
			+ '</li>';
	}

	/** Rows of the tree reached with the arrows: folders, views, mailboxes (1.49.0) */
	const TREE_ROWS = '[data-folder], [data-view], [data-vview], .ibx-tree__row--account[data-account]';
	/** The Inbox of a mailbox opened, the mailbox left folded or not */
	function openBoxInbox(accountId) {
		const a = S.accounts.find(x => x.id === accountId);
		const inbox = a && boxInbox(a);
		if (inbox && inbox.selectable) {
			openFolder(inbox.id);
		} else if (a && setOpen('a' + a.id, true)) {
			// No Inbox to open (not synced yet): unfolded, as before
			renderTree();
		}
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
				setOpen(key, !isOpen(key));
				renderTree();
				return;
			}
			const unlock = e.target.closest('.ibx-js-unlockbox');
			if (unlock) {
				e.stopPropagation();
				if (I.protect) {
					I.protect.openLock(+unlock.dataset.account).then(ok => (ok ? refreshTree() : null));
				}
				return;
			}
			const err = e.target.closest('.ibx-js-accounterror');
			if (err) {
				e.stopPropagation();
				accountErrorDialog(S.accounts.find(a => a.id === +err.dataset.account));
				return;
			}
			const pickToggle = e.target.closest('[data-boxpick-toggle]');
			if (pickToggle) {
				S.pickOpen = !S.pickOpen;
				renderTree();
				const again = $('[data-boxpick-toggle]', S.el);
				if (again) {
					again.focus();
				}
				return;
			}
			const pick = e.target.closest('[data-boxpick]');
			if (pick) {
				chooseBox(+pick.dataset.boxpick, false);
				return;
			}
			const vmenu = e.target.closest('.ibx-js-viewsmenu');
			if (vmenu) {
				e.stopPropagation();
				viewsMenu(vmenu);
				return;
			}
			const vrow = e.target.closest('[data-view]');
			if (vrow) {
				openView(vrow.dataset.view);
				return;
			}
			const vvrow = e.target.closest('[data-vview]');
			if (vvrow) {
				openVview(+vvrow.dataset.vview);
				return;
			}
			const row = e.target.closest('[data-folder]');
			if (row && !row.classList.contains('is-disabled')) {
				openFolder(+row.dataset.folder);
			} else if (e.target.closest('[data-section="views"]')) {
				S.collapsed.delete('views');
				saveParam('tree_collapsed', JSON.stringify(Array.from(S.collapsed)));
				renderTree();
			} else if (e.target.closest('.ibx-tree__row--account[data-account]')) {
				// The mailbox clicked opens its Inbox, folded or not; only its arrow unfolds it (1.49.0)
				openBoxInbox(+e.target.closest('[data-account]').dataset.account);
			}
		});
		// Arrows in the tree (role tree): up / down between visible folders, left / right fold
		pane.addEventListener('keydown', e => {
			const row = e.target.closest(TREE_ROWS);
			if (!row) {
				return;
			}
			if (row.dataset.account && (e.key === 'Enter' || e.key === ' ')) {
				openBoxInbox(+row.dataset.account);
				e.preventDefault();
				return;
			}
			if ((row.dataset.view || row.dataset.vview) && (e.key === 'Enter' || e.key === ' ')) {
				if (row.dataset.view) {
					openView(row.dataset.view);
				} else {
					openVview(+row.dataset.vview);
				}
				e.preventDefault();
				return;
			}
			const rows = $$(TREE_ROWS, pane);
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
						const again = $(row.dataset.account ? '.ibx-tree__row--account[data-account="' + row.dataset.account + '"]' : '[data-folder="' + row.dataset.folder + '"]', pane);
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
		if (S.scope === 'view') {
			q.view = S.view;
		}
		if (S.vview && S.vAccounts.length) {
			q.accounts = S.vAccounts;
		}
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
			S.listLocked = 0;
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
						S.threadCache[r.thread.key] = pendingKeep(x.mails, true);
					}).catch(() => {}))).then(() => renderList());
				}
			} else {
				S.expanded.clear();
				S.threadCache = {};
				S.rows = pendingKeep(res.rows);
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
			// Protected mailbox not unlocked yet in this session (first load of a new session, the silent unlock
			// comes just after): told as locked, the list loaded again once unlocked (inbox:protect:state)
			S.listLocked = err.code === 'locked' ? +(err.data.account || 0) : 0;
			if (!keep && S.listLocked) {
				body.innerHTML = '<div class="ibx-empty">' + ic('lock', 'ibx-empty__icon') + '<div>' + esc(err.message) + '</div><button type="button" class="ibx-btn ibx-js-unlocklist">' + th('InboxProtectUnlock') + '</button></div>';
				$('.ibx-js-unlocklist', body).addEventListener('click', () => {
					if (I.protect) {
						I.protect.openLock(S.listLocked).then(ok => (ok ? refreshTree().then(() => loadList()) : null));
					}
				});
			} else if (!keep) {
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
		fresh = pendingKeep(fresh);
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

	/** Mark of a mail answered in the list: accent color, with its label on hover and for the screen readers */
	function answeredMark() {
		return '<span class="ibx-answered" title="' + th('InboxAnswered') + '">' + ic('reply') + '<span class="ibx-sr-only">' + th('InboxAnswered') + ' </span></span>';
	}

	/** Forwarded (keyword $Forwarded, set by inbox or by another software) */
	function forwarded(row) {
		return (row.tags || []).some(k => String(k).toLowerCase() === '$forwarded');
	}
	/**
	 * Tags beside the subject of a mail read (1.57.0): one button opening the menu of the tags, with a « + » to add one
	 * (click handled once for the whole space, the head is painted again after each change)
	 */
	function headTagsHtml(mailId, row) {
		return '<button type="button" class="ibx-readhead__tags ibx-js-tagedit" data-mail="' + (+mailId) + '" title="' + th('InboxTagMenu') + '" aria-label="' + th('InboxTagMenu') + '" aria-haspopup="menu">'
			+ tagsHtml(row) + '<span class="ibx-readhead__tagadd">' + ic('plus') + '</span></button>';
	}

	function tagsHtml(row, dots) {
		const entry = S.folders.get(row.folder);
		const defs = entry ? entry.account.tags : [];
		const shown = new Set();
		const out = (row.tags || []).map(k => {
			const d = tagOf(defs, k);
			if (!d) {
				if (!userKeyword(k)) {
					return '';
				}
				return dots ? '<span class="ibx-tag ibx-tag--unknown ibx-tag--dot"><span aria-hidden="true">?</span><span class="ibx-sr-only">' + esc(String(k)) + '</span></span>'
					: '<span class="ibx-tag ibx-tag--unknown" title="' + th('InboxTagUnknown', String(k)) + '">' + esc(String(k)) + '</span>';
			}
			// The tag and its alias $labelN on the same mail: one badge
			if (shown.has(d.keyword.toLowerCase())) {
				return '';
			}
			shown.add(d.keyword.toLowerCase());
			return dots ? Dolibarr.tools.inboxColors.dot(d, d.view) : Dolibarr.tools.inboxColors.badge(d, d.view);
		});
		if (!dots) {
			return out.join(' ');
		}
		// Dots: 4 at most, then « +n » (every tag with its name in the bubble over the dots, tagPop)
		const list = out.filter(Boolean);
		if (!list.length) {
			return '';
		}
		// The dots beyond 4 stay for the screen readers, hidden on screen behind « +n »
		const more = list.length > 4 ? '<span class="ibx-tagdots__more" aria-hidden="true">+' + (list.length - 4) + '</span>' : '';
		return '<span class="ibx-tagdots">' + list.slice(0, 4).join('') + (more ? '<span class="ibx-sr-only">' + list.slice(4).join('') + '</span>' : '') + more + '</span>';
	}
	/**
	 * Bubble over the dots of a row (pointer only, after 120 ms like the tooltips of inbox): every tag of the mail as
	 * its badge, the « +n » included. Fixed to the window, so the cell that clips its content does not cut it
	 */
	function tagPop(dots) {
		let pop = S.tagPop;
		if (!pop || !pop.isConnected) {
			pop = document.createElement('div');
			pop.className = 'ibx-tagpop';
			pop.setAttribute('aria-hidden', 'true');
			pop.hidden = true;
			document.body.appendChild(pop);
			S.tagPop = pop;
		}
		const item = dots.closest('[data-mail]');
		const row = item ? rowOf(+item.dataset.mail) : null;
		if (!row) {
			return;
		}
		pop.innerHTML = tagsHtml(row, false);
		pop.hidden = false;
		const r = dots.getBoundingClientRect();
		const w = pop.offsetWidth;
		const h = pop.offsetHeight;
		const top = r.top - h - 6 < 8 ? r.bottom + 6 : r.top - h - 6;
		pop.style.top = Math.round(top) + 'px';
		pop.style.left = Math.round(Math.max(8, Math.min(window.innerWidth - w - 8, r.left + r.width / 2 - w / 2))) + 'px';
	}
	function hideTagPop() {
		clearTimeout(S.tagPopTimer);
		S.tagPopFor = null;
		if (S.tagPop) {
			S.tagPop.hidden = true;
		}
	}
	/**
	 * Colour of the row of a mail: the one of its first tag (in the order of the list of the mailbox) that colours
	 * its rows, '' when none does
	 */
	function rowTint(row) {
		const entry = S.folders.get(row.folder);
		const defs = entry ? entry.account.tags : [];
		let best = -1;
		(row.tags || []).forEach(k => {
			const d = tagOf(defs, k);
			const i = d && d.tint ? defs.indexOf(d) : -1;
			if (i >= 0 && (best < 0 || i < best)) {
				best = i;
			}
		});
		if (best < 0) {
			return '';
		}
		const C = Dolibarr.tools.inboxColors;
		const c = C.isColor(defs[best].view) ? defs[best].view : defs[best].color;
		return C.isColor(c) ? c : '';
	}
	/** Class and style of a row coloured by a tag (nothing when no tag of the mail colours its rows) */
	function tintAttrs(row) {
		const c = rowTint(row);
		return c ? { cls: ' has-tint', style: ' style="--ibx-row-tint:' + c + '"' } : { cls: '', style: '' };
	}
	/**
	 * Tag of a keyword in the active tags of a mailbox (in their order): its keyword, else $labelN of Thunderbird as an
	 * alias of the tag of the key N when no tag has this keyword (a tag of Thunderbird with the same name as a tag of
	 * the list but another keyword). Same rule on the server for the filter (InboxTag::keywordAliases)
	 * @param {Array<Object>} defs Active tags of the mailbox, in order
	 * @param {string} k Keyword
	 * @return {Object|null}
	 */
	function tagOf(defs, k) {
		const l = String(k).toLowerCase();
		const d = defs.find(x => x.keyword.toLowerCase() === l);
		if (d) {
			return d;
		}
		const m = /^\$label([1-9])$/.exec(l);
		return m ? (defs[+m[1] - 1] || null) : null;
	}
	/** Keywords of a mail that are this tag (its keyword or its alias $labelN) */
	function keywordsOfTag(defs, row, keyword) {
		const l = keyword.toLowerCase();
		return (row.tags || []).filter(k => {
			const d = tagOf(defs, k);
			return d ? d.keyword.toLowerCase() === l : String(k).toLowerCase() === l;
		});
	}
	/**
	 * Keyword set by a person in another software (a tag of Thunderbird not in the list: other keyword than the tag of
	 * the same name), shown raw; never the system keywords ($Forwarded, $MDNSent, $Junk, Junk, NonJunk...)
	 */
	function userKeyword(k) {
		const l = String(k).toLowerCase();
		return /^\$label\d+$/.test(l) || (l !== '' && l[0] !== '$' && !['junk', 'nonjunk', 'notjunk'].includes(l));
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
			fetch(I.ctx('INBOX_AJAX_URL', '') + 'userparam.php', { method: 'POST', credentials: 'same-origin', keepalive: true, headers: Object.assign(I.headers(), { 'Content-Type': 'application/json' }), body: JSON.stringify(body) }).catch(() => {});
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
			return view === 'table' ? '<tr class="ibx-mailrow ibx-mailrow--child"><td></td><td colspan="' + cols.length + '"><span class="ibx-skeleton" style="width:40%"></span></td></tr>'
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
						return '<td class="ibx-mailrow__assign">' + assigneesHtml(m.assignees, '', 2) + '</td>';
					}
					if (c.id === 'marks') {
						return '<td class="ibx-mailrow__marks">' + marksHtml(m) + '</td>';
					}
					return '<td></td>';
				}).join('') + '</tr>').join('');
		}
		return '<div class="ibx-thread" role="group" aria-label="' + th('InboxThreadCount', list.length) + '">' + kids.map(m => '<div class="ibx-mailcard ibx-mailcard--child' + (seenOf(m) ? '' : ' is-unread') + (isMine(m) ? ' is-mine' : '') + '" role="option" data-mail="' + m.id + '" data-child="1" aria-selected="' + S.selected.has(m.id) + '" tabindex="-1">'
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
				S.threadCache[key] = pendingKeep(res.mails, true);
			} catch (err) {
				S.expanded.delete(key);
				toast(err.message, { error: true });
			}
			renderList();
		}
	}

	/** Row of Sent or Drafts: its correspondent is the recipient, no picture of the sender (that is us) */
	/**
	 * Picture of the first recipient on the card of a sent mail or a draft (UX fix series): the sender would be the
	 * mailbox itself on every card; its initials until the picture comes (avatar.php to=1)
	 */
	function recipientAvatar(row) {
		const first = String(row.to || '').split(',')[0];
		const email = (first.match(/[^\s<>,;"']+@[^\s<>,;"']+/) || [''])[0];
		if (!email) {
			return '';
		}
		const name = first.replace(/<[^>]*>/, '').replace(/^["'\s]+|["'\s]+$/g, '');
		return avatar(name, email, 'ibx-avatar--xs', row.id).replace('data-avatar=', 'data-to="1" data-avatar=');
	}
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
	/** Mail sent by a site for a requester (data sources, 1.72.0): the source beside the requester, the site in its tooltip */
	function viaHtml(row) {
		if (!row.via) {
			return '';
		}
		const label = row.via.source || row.via.email;
		return ' <span class="ibx-badge ibx-via" title="' + esc(t('InboxViaTitle', label, row.via.email)) + '">' + ic('redirect') + '<span class="ibx-sr-only">' + th('InboxVia') + ' </span>' + esc(label) + '</span>';
	}

	/**
	 * Columns of the table (interface §5): chosen, sized and ordered by the user (setting list_columns), the subject
	 * always shown. Each one: label, width by default, sort key, cell
	 */
	const COLUMNS = {
		marks: { label: 'InboxIndicators', icon: 'circles', width: '1.625rem', fit: true, cell: r => '<td class="ibx-mailrow__marks">' + marksHtml(r) + '</td>' },
		flag: { label: 'InboxFlagged', icon: 'star', width: '1.625rem', cell: r => '<td>' + (r.flagged ? '<span class="ibx-star" aria-pressed="true" title="' + th('InboxFlagged') + '">' + ic('star-on') + '</span>' : '') + '</td>' },
		att: { label: 'InboxAttachments', icon: 'attachment', width: '1.625rem', cell: r => '<td>' + (r.attachments ? ic('attachment', 'ibx-faint') : '') + '</td>' },
		from: { label: 'InboxCorrespondent', sort: 'from', width: '14rem', cell: r => '<td title="' + esc(r.from_email) + '">' + (isNew(r) ? '<span class="ibx-newdot ibx-newdot--row"><span class="ibx-sr-only">' + th('InboxNew') + ' </span></span>' : '') + (seenOf(r) ? '' : '<span class="ibx-sr-only">' + th('InboxUnread') + ' </span>') + esc(correspondent(r)) + viaHtml(r) + '</td>' },
		subject: { label: 'InboxSubject', sort: 'subject', width: '', cell: r => '<td>' + foundHtml(r) + threadMarks(r) + threadBadge(r) + (r.answered ? answeredMark() + ' ' : '') + (forwarded(r) ? '<span title="' + th('InboxForwarded') + '">' + ic('forward', 'ibx-faint') + '</span> ' : '') + esc(r.subject || t('InboxNoSubject')) + (S.tagsColumn ? '' : ' ' + tagsHtml(r, S.tagsDots)) + '</td>' },
		tags: { label: 'InboxTags', width: '9rem', cell: r => '<td class="ibx-mailrow__tags">' + tagsHtml(r, S.tagsDots) + '</td>' },
		assign: { label: 'InboxAssigned', icon: 'user-circle', width: '1.625rem', fit: true, cell: r => '<td class="ibx-mailrow__assign">' + assigneesHtml(r.assignees, '', 2) + '</td>' },
		soc: { label: 'InboxThirdparty', width: '10rem', cell: r => '<td class="ibx-muted">' + (r.soc ? esc(r.soc.name) : '') + '</td>' },
		size: { label: 'InboxSize', sort: 'size', width: '4.5rem', cell: r => '<td class="ibx-mailrow__date">' + esc(size(r.size)) + '</td>' },
		box: { label: 'InboxMailboxFolder', width: '12rem', cell: r => '<td class="ibx-muted">' + boxHtml(r) + '</td>' },
		date: { label: 'InboxDate', sort: 'date', width: '5.75rem', cell: r => '<td class="ibx-mailrow__date" title="' + esc(longDate(r.date)) + '">' + esc(shortDate(r.date)) + '</td>' },
	};
	const DEFAULT_COLUMNS = ['marks', 'from', 'subject', 'tags', 'date'];

	/**
	 * Indicators of a line in fixed slots (star, attachment, assigned people): an empty slot stays empty so that
	 * each indicator keeps its place from one line to the next
	 */
	function marksHtml(r) {
		const used = S.marksSlots || {};
		return '<span class="ibx-marks">'
			+ (used.flag || r.flagged ? '<span class="ibx-marks__slot">' + (r.flagged ? '<span class="ibx-star" aria-pressed="true" title="' + th('InboxFlagged') + '">' + ic('star-on') + '</span>' : '') + '</span>' : '')
			+ (used.att || r.attachments ? '<span class="ibx-marks__slot">' + (r.attachments ? '<span title="' + th('InboxAttachments') + '">' + ic('attachment', 'ibx-faint') + '<span class="ibx-sr-only">' + th('InboxAttachments') + '</span></span>' : '') + '</span>' : '')
			+ (used.assign || (r.assignees && r.assignees.length) ? '<span class="ibx-marks__slot ibx-marks__slot--assign">' + assigneesHtml(r.assignees) + '</span>' : '')
			+ '</span>';
	}
	/**
	 * Slots of the indicators column: only the indicators present in the lines shown (unfolded conversations
	 * included), so that the column takes no room for an indicator no line has
	 */
	function marksSlots() {
		const used = { flag: false, att: false, assign: false };
		const look = m => {
			used.flag = used.flag || !!m.flagged;
			used.att = used.att || !!m.attachments;
			used.assign = used.assign || !!(m.assignees && m.assignees.length);
		};
		S.rows.forEach(r => {
			look(r);
			if (r.thread && S.expanded.has(r.thread.key) && S.threadCache[r.thread.key]) {
				S.threadCache[r.thread.key].forEach(look);
			}
		});
		return used;
	}

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
		if ((S.scope === 'all' || S.scope === 'view') && !cols.some(c => c.id === 'box')) {
			cols.splice(cols.findIndex(c => c.id === 'subject') + 1, 0, { id: 'box', auto: true });
		}
		return cols;
	}
	/**
	 * Width the table needs so that the subject keeps its minimum (12 rem, or the width given by its edge): the other
	 * columns have a fixed width, the subject alone takes the rest; beyond the pane, the list scrolls sideways
	 */
	function tableMinWidth(cols) {
		const rem = parseFloat(getComputedStyle(document.documentElement).fontSize) || 16;
		const subject = cols.find(c => c.id === 'subject');
		let fixed = 1.625 * rem + (subject && subject.w ? subject.w : 12 * rem);
		let pct = 0;
		cols.forEach(c => {
			if (c.id === 'subject') {
				return;
			}
			const w = colWidth(c);
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
	/** Width of a column (the subject has none: it takes the room the other columns leave) */
	function colWidthStyle(c) {
		if (c.id === 'subject') {
			return '';
		}
		const w = colWidth(c);
		return w ? ' style="width:' + w + '"' : '';
	}
	/** Width of a column: the one given by its edge, else the fitted one (columns that fit their content), else the default */
	function colWidth(c) {
		if (c.w) {
			return c.w + 'px';
		}
		if (isFit(c)) {
			return S.fitWidths && S.fitWidths[c.id] ? S.fitWidths[c.id] + 'px' : fitMin(c) + 'rem';
		}
		return COLUMNS[c.id].width;
	}
	/** Column that fits its content: indicators, assigned, and the tags shown as dots */
	function isFit(c) {
		return !!COLUMNS[c.id].fit || (c.id === 'tags' && !!S.tagsDots);
	}
	/** Smallest width of a column that fits its content (rem): one icon for the dots */
	function fitMin(c) {
		return c.id === 'tags' ? 1.625 : parseFloat(COLUMNS[c.id].width);
	}
	/**
	 * Columns that fit their content (indicators, assigned): their default width is a minimum, they widen to the
	 * widest content of the lines shown (several people assigned...) and never move the other columns but the subject
	 */
	function fitColumns(body, cols) {
		const table = $('.ibx-mailtable', body);
		if (!table) {
			return;
		}
		const rem = parseFloat(getComputedStyle(document.documentElement).fontSize) || 16;
		S.fitWidths = S.fitWidths || {};
		let changed = false;
		cols.filter(c => isFit(c) && !c.w).forEach(c => {
			// +2: the column of the checkboxes first, nth-child counts from 1
			const idx = cols.indexOf(c) + 2;
			let need = Math.ceil(fitMin(c) * rem);
			$$('tbody tr > td:nth-child(' + idx + ')', table).forEach(td => {
				const inner = td.firstElementChild;
				if (inner) {
					const cs = getComputedStyle(td);
					need = Math.max(need, Math.ceil(inner.getBoundingClientRect().width + parseFloat(cs.paddingLeft) + parseFloat(cs.paddingRight)));
				}
			});
			if (S.fitWidths[c.id] !== need) {
				S.fitWidths[c.id] = need;
				$('col[data-col="' + c.id + '"]', table).style.width = need + 'px';
				changed = true;
			}
		});
		if (changed) {
			table.style.minWidth = tableMinWidth(cols) + 'px';
		}
	}
	function saveColumns(cols) {
		saveParam('list_columns', JSON.stringify(cols.filter(c => !c.auto).map(c => (c.w ? { id: c.id, w: c.w } : { id: c.id }))));
	}
	/** Order of a first click on a column: the newest / the biggest first, the others from A to Z */
	function naturalSort(key) {
		return key === 'date' || key === 'size' ? 'desc' : 'asc';
	}
	function colHead(id) {
		const c = COLUMNS[id];
		const label = id === 'from' && (S.folderRole === 'sent' || S.folderRole === 'drafts') ? 'InboxRecipient' : c.label;
		const inner = c.icon ? ic(c.icon) + '<span class="ibx-sr-only">' + th(label) + '</span>' : th(label);
		const [k, dir] = (params.list_sort || 'date:desc').split(':');
		const on = c.sort && k === c.sort;
		// The whole header sorts (data-sort on the cell, the button stays for the keyboard); a click never follows a
		// drag, so dragging the same header still moves the column. Faint arrow: the order a click would give
		const next = on ? (dir === 'asc' ? 'desc' : 'asc') : naturalSort(c.sort);
		return '<th data-col="' + id + '" draggable="true" title="' + th(label) + '&#10;' + th('InboxColumnsRightClickHint') + '"' + (c.sort ? ' class="is-sortable" data-sort="' + c.sort + '" aria-sort="' + (on ? (dir === 'asc' ? 'ascending' : 'descending') : 'none') + '"' : '') + '>'
			+ (c.sort ? '<button type="button" class="ibx-sorthead" data-sort="' + c.sort + '">' + inner
				+ (on ? ' ' + ic(dir === 'asc' ? 'chevron-up' : 'chevron-down') : '')
				+ '<span class="ibx-sorthead__next" aria-hidden="true">' + ic(next === 'asc' ? 'chevron-up' : 'chevron-down') + '</span></button>' : inner)
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
		})).concat([{ sep: true }, { header: t('InboxTags') }].concat(['names', 'dots'].map(v => ({
			label: t(v === 'dots' ? 'InboxTagsViewDots' : 'InboxTagsViewNames'), checked: (params.list_tags_view === 'dots' ? 'dots' : 'names') === v,
			run: () => {
				saveParam('list_tags_view', v);
				renderList();
			},
		}))), [{ sep: true }, {
			label: t('InboxColumnsDefault'), run: () => {
				saveParam('list_columns', '');
				renderList();
			},
		}, { note: t('InboxColumnsDragHint') }]), at);
	}

	/** Width by the edge of a header (double click = default), order by drag and drop of the headers */
	function bindTableHead(body) {
		const table = $('.ibx-mailtable', body);
		if (!table) {
			return;
		}
		$('thead', table).addEventListener('contextmenu', e => {
			// Browser menu kept with Shift (interface §6 bis)
			if (e.shiftKey) {
				return;
			}
			e.preventDefault();
			// Anchor that can take the focus back when the menu closes: the sort button of the header, else « ⋯ »
			const th = e.target.closest('th');
			columnsMenu((th && $('.ibx-sorthead', th)) || $('.ibx-js-listmenu', S.el), { x: e.clientX, y: e.clientY });
		});
		const all = $('.ibx-js-checkall', table);
		all.addEventListener('click', e => {
			e.stopPropagation();
			if (listSelectedCount() >= S.rows.length) {
				S.selected.clear();
				S.allInFolder = false;
				paintSelection();
				showNothing();
			} else {
				selectAll();
			}
			// Clicked with the mouse: the focus goes back to the list, else the box would keep it and the shortcuts
			// (Delete, archive, arrows...) would wait for another click; from the keyboard, it stays on the box
			if (e.detail > 0) {
				const row = $('[data-mail][tabindex="0"]', table);
				if (row) {
					row.focus({ preventScroll: true });
				} else {
					all.blur();
				}
			}
		});
		paintCheckAll();
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
			const cols = tableColumns();
			const c = cols.find(x => x.id === id);
			grip.setPointerCapture(e.pointerId);
			// Only the dragged column changes: the subject takes or gives the difference, the list scrolls sideways
			// when the columns no longer fit; the edge of the subject sets its minimum width
			const move = ev => {
				if (!c) {
					return;
				}
				c.w = Math.max(48, Math.round(base + ev.clientX - start));
				if (id !== 'subject') {
					col.style.width = c.w + 'px';
				}
				table.style.minWidth = tableMinWidth(cols) + 'px';
			};
			const up = () => {
				grip.removeEventListener('pointermove', move);
				grip.removeEventListener('pointerup', up);
				if (c && c.w) {
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
			S.tagsDots = params.list_tags_view === 'dots';
			S.marksSlots = marksSlots();
			// Columns chosen by « ⋯ > Columns » or a right click on a header: no column kept for a button
			html = '<table class="ibx-mailtable" role="grid" aria-label="' + th('InboxMails') + '" aria-multiselectable="true" style="min-width:' + tableMinWidth(cols) + 'px"><colgroup><col class="ibx-col--icon">'
				+ cols.map(c => '<col data-col="' + c.id + '"' + colWidthStyle(c) + '>').join('')
				+ '</colgroup><thead><tr><th class="ibx-mailtable__checkall"><input type="checkbox" class="ibx-js-checkall" title="' + th('InboxSelectAll') + '" aria-label="' + th('InboxSelectAll') + '"></th>'
				+ cols.map(c => colHead(c.id)).join('')
				+ '</tr></thead><tbody>';
			S.rows.forEach(r => {
				const sel = S.selected.has(r.id);
				const tint = tintAttrs(r);
				html += '<tr class="ibx-mailrow' + (seenOf(r) ? '' : ' is-unread') + (r.watched ? ' is-watched' : '') + (r.ignored ? ' is-ignored' : '') + tint.cls + '"' + tint.style + ' data-mail="' + r.id + '" aria-selected="' + sel + '" tabindex="' + (r.id === S.anchor ? 0 : -1) + '" draggable="true">'
					+ '<td><input type="checkbox" class="ibx-js-check" tabindex="-1"' + (sel ? ' checked' : '') + ' aria-label="' + th('InboxSelect') + '"></td>'
					+ cols.map(c => COLUMNS[c.id].cell(r)).join('') + '</tr>'
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
				const tint = tintAttrs(r);
				html += '<div class="ibx-mailcard' + (seenOf(r) ? '' : ' is-unread') + (isNew(r) ? ' is-new' : '') + (r.watched ? ' is-watched' : '') + (r.ignored ? ' is-ignored' : '') + tint.cls + '"' + tint.style + ' role="option" data-mail="' + r.id + '" aria-selected="' + sel + '" tabindex="' + (r.id === S.anchor ? 0 : -1) + '"' + (isPhone() ? '' : ' draggable="true"') + '>'
					+ '<span class="ibx-mailcard__from" title="' + esc(r.from_email) + '">' + (sentLike(r) ? recipientAvatar(r) : avatar(r.from_name, r.from_email, 'ibx-avatar--xs', r.id))
					+ '<span class="ibx-ellipsis">' + (isNew(r) ? '<span class="ibx-sr-only">' + th('InboxNew') + ' </span>' : '') + (seenOf(r) ? '' : '<span class="ibx-sr-only">' + th('InboxUnread') + ' </span>') + esc(correspondent(r)) + '</span>' + viaHtml(r) + '</span>'
					// Short marks beside the date (answered, attachment, starred): the bottom line is only for what needs width
					+ '<span class="ibx-mailcard__meta">' + threadMarks(r) + (r.answered ? answeredMark() : '') + (forwarded(r) ? '<span title="' + th('InboxForwarded') + '">' + ic('forward') + '</span>' : '') + (r.attachments ? ic('attachment', 'ibx-faint') : '')
					+ (r.flagged ? '<span class="ibx-star" aria-pressed="true" title="' + th('InboxFlagged') + '">' + ic('star-on') + '</span>' : '')
					+ '<span class="ibx-mailcard__dot" aria-hidden="true"></span><span title="' + esc(longDate(r.date)) + '">' + esc(shortDate(r.date)) + '</span></span>'
					+ '<span class="ibx-mailcard__subject ibx-ellipsis">' + esc(r.subject || t('InboxNoSubject')) + '</span>'
					+ (S.crops[r.id] ? '<span class="ibx-mailcard__snippet ibx-mailcard__crop">' + cropHtml(S.crops[r.id]) + '</span>'
						: (r.snippet ? '<span class="ibx-mailcard__snippet ibx-ellipsis">' + esc(r.snippet) + '</span>' : ''))
					// The people assigned last: pushed to the right (margin-left: auto), they never move the rest
					+ '<span class="ibx-mailcard__foot">' + foundHtml(r) + threadBadge(r) + tagsHtml(r) + (r.soc ? ' <span class="ibx-muted ibx-ellipsis">' + ic('company') + ' ' + esc(r.soc.name) + '</span>' : '')
					+ (S.scope === 'all' || S.scope === 'view' ? ' ' + boxHtml(r) : '')
					+ assigneesHtml(r.assignees, 'ibx-mailcard__assign')
					+ '</span>'
					+ '</div>'
					+ threadChildren(r, 'cards', null);
			});
			html += '</div>';
		}
		if (S.next) {
			html += '<div class="ibx-js-more ibx-listmore">' + '<span class="ibx-skeleton" style="width:60%"></span>' + '</div>';
		}
		hideTagPop();
		body.innerHTML = html;
		bindTableHead(body);
		hydrateAvatars(body);
		if (view === 'table') {
			fitColumns(body, tableColumns());
		}
		I.mount(body);
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
	/** Mailbox of a mail in a list of several mailboxes: its icon in its colour (as in the panel), then « mailbox / folder » */
	function boxHtml(r) {
		const e = S.folders.get(r.folder);
		if (!e) {
			return '';
		}
		// Its name in a title (shown after a moment of hover): the name gives way to the tags, the icon stays
		return '<span class="ibx-boxof ibx-muted" title="' + esc(boxLabel(r)) + '">' + lookIcon(e.account.icon || 'account', e.account.color, 'ibx-boxof__icon')
			+ '<span class="ibx-boxof__name ibx-ellipsis">' + esc(boxLabel(r)) + '</span></span>';
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
				+ (S.scope !== 'all' && S.scope !== 'view' && S.accounts.length > 1 ? '<button type="button" class="ibx-btn ibx-js-scope" data-scope="all">' + th('InboxSearchAllBoxes') + '</button>' : '') + '</div></div>';
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
		if (S.vview) {
			const e = currentFolder();
			leaveVview();
			if (e) {
				openFolder(e.folder.id);
				return;
			}
		}
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
		} else if (S.scope === 'view') {
			name = t('InboxView_' + S.view);
		}
		const vv = S.vview ? S.vviews.find(x => x.id === S.vview) : null;
		if (vv) {
			name = vv.label + (sameSearch(vv.search, currentSearch()) ? '' : ' · ' + t('InboxViewModified'));
		}
		title.innerHTML = esc(name) + ' <span class="ibx-muted">· ' + th('InboxMailsCount', (S.total || 0).toLocaleString(locale())) + '</span>';
		$('.ibx-js-view', S.el).innerHTML = ic(params.view === 'table' ? 'view-cards' : 'view-table');
		$('.ibx-js-view', S.el).title = t(params.view === 'table' ? 'InboxViewCards' : 'InboxViewTable');
		$('.ibx-js-view', S.el).setAttribute('aria-label', $('.ibx-js-view', S.el).title);
		const sort = params.list_sort || 'date:desc';
		const sortKey = { 'date:desc': 'InboxSortDateDesc', 'date:asc': 'InboxSortDateAsc', 'from:asc': 'InboxSortFromAsc', 'from:desc': 'InboxSortFromDesc',
			'subject:asc': 'InboxSortSubjectAsc', 'subject:desc': 'InboxSortSubjectDesc', 'size:desc': 'InboxSortSize', 'size:asc': 'InboxSortSizeAsc' }[sort];
		const relevance = !!(S.ft && S.ft.relevance);
		// Trash and junk emptied after some days (1.41.0): said where the mails are, nobody surprised
		const purge = S.scope === 'folder' && f && f.folder.purge ? ' · ' + t('InboxRetentionHint', f.folder.purge) : '';
		const where = S.scope === 'folder' && f ? t('InboxSearchIn', folderName(f.folder)) : '';
		$('.ibx-js-scopehint', S.el).textContent = where + (relevance ? ' · ' + t('InboxSortedBy', t('InboxSortRelevance')) : '') + purge;
		// The usual order (newest first) said only on a line already there, never a line of its own
		const chip = $('.ibx-js-sortchip', S.el);
		chip.hidden = relevance || !sortKey || (sort === 'date:desc' && !where);
		if (!chip.hidden) {
			const desc = sort.split(':')[1] === 'desc';
			// Two targets: the arrow turns the order, the words open the other orders (a right click too)
			chip.innerHTML = '<button type="button" class="ibx-sortchip__dir ibx-js-sortdir" title="' + th('InboxSortReverse') + '" aria-label="' + th('InboxSortReverse') + '">' + ic(desc ? 'sort-desc' : 'sort-asc') + '</button>'
				+ '<button type="button" class="ibx-sortchip__key ibx-js-sortkey" aria-haspopup="menu" title="' + th('InboxSortChange') + '" aria-label="' + esc(t('InboxSortedBy', t(sortKey)) + '. ' + t('InboxSortChange')) + '">'
				+ '<span>' + th(sortKey) + '</span>' + ic('chevron-down') + '</button>';
			chip.classList.toggle('is-custom', sort !== 'date:desc');
		}
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
		sxClearIn();
		// Keep the search as a view; in a view changed by the search: update it (who may change it)
		const vsave = $('.ibx-js-vsave', S.el);
		const vv = S.vview ? S.vviews.find(x => x.id === S.vview) : null;
		const update = vv && vv.editable && !sameSearch(vv.search, currentSearch());
		vsave.hidden = !(update || (!vv && hasFilter() && S.scope !== 'view'));
		$('.ibx-js-vsavelabel', vsave).textContent = t(update ? 'InboxViewUpdate' : 'InboxViewSaveAs');
		// The word goes on a narrow list: the button keeps its name for the tooltip and the screen readers
		vsave.title = t(update ? 'InboxViewUpdate' : 'InboxViewSaveAs');
		const hint = $('.ibx-js-scopehint', S.el);
		hint.title = hint.textContent;
		$('.ibx-searchinfo', S.el).hidden = box.hidden && $('.ibx-js-scopehint', S.el).textContent === '' && $('.ibx-js-sortchip', S.el).hidden && !hasFilter();
	}

	/** The cross in the search field: shown as soon as something is searched, typed or kept (pills, filters...) */
	function sxClearIn() {
		const b = $('.ibx-js-sxclearin', S.el);
		if (b) {
			b.hidden = !($('.ibx-js-search', S.el).value || hasFilter());
		}
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
		// The cross of the field: the same as « Clear the search » (and Escape), the cursor back in the field
		$('.ibx-js-sxclearin', el).addEventListener('click', e => {
			e.stopPropagation();
			if (hasFilter()) {
				clearFilters();
			} else {
				input.value = '';
				S.sx.text = '';
				sxRenderChips();
			}
			sxClearIn();
			input.focus();
		});
		input.addEventListener('input', sxClearIn);
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
		paintCheckAll();
		renderBulkBar();
	}
	/** Mails of the list selected (the mails of an unfolded conversation are not counted) */
	function listSelectedCount() {
		return S.rows.reduce((n, r) => n + (S.selected.has(r.id) ? 1 : 0), 0);
	}
	/** « Select all » box of the table: checked when every mail of the list is, partly checked when some are */
	function paintCheckAll() {
		const all = S.el && $('.ibx-js-checkall', S.el);
		if (!all) {
			return;
		}
		const n = listSelectedCount();
		all.checked = n > 0 && n >= S.rows.length;
		all.indeterminate = n > 0 && n < S.rows.length;
		const label = t(all.checked ? 'InboxClearSelection' : 'InboxSelectAll');
		all.title = label;
		all.setAttribute('aria-label', label);
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

	// ---------- Agenda panel (1.107.0): what it needs from the mail space ----------
	/** A mail is open (id) or none (0): the agenda shows itself or hides by its rule (js/inbox-agenda.js) */
	function agendaMail(id) {
		if (I.agenda && I.agenda.mailChanged) {
			I.agenda.mailChanged(id);
		}
		applySide('mail');
	}
	/**
	 * The mail open, for the agenda and the modules plugged into it: identifiers and short texts only, never its body.
	 * What the Dolibarr panel recognized (thirdparty, contact, pieces) once it is loaded.
	 * @returns {object|null}
	 */
	I.mailContext = () => {
		const m = S.mail;
		if (!m || !S.openId) {
			return null;
		}
		const box = dolibarIn(readPane());
		const d = box && box._data ? box._data : null;
		const sender = d && d.sender ? d.sender : {};
		const links = d && Array.isArray(d.links) ? d.links.filter(l => l && l.readable !== false && +l.id > 0) : [];
		const project = links.find(l => l.type === 'project');
		return {
			id: m.id,
			account: m.account,
			folder: m.folder,
			messageId: m.message_id || '',
			subject: m.subject || '',
			from: m.from ? { name: m.from.name || '', email: m.from.email || '' } : null,
			thirdparty: sender.soc && sender.soc.readable !== false && +sender.soc.id > 0 ? { id: +sender.soc.id, name: sender.soc.label || sender.soc.ref || '' } : null,
			contact: sender.contact && sender.contact.readable !== false && +sender.contact.id > 0 ? { id: +sender.contact.id, name: sender.contact.label || sender.contact.ref || '' } : null,
			user: sender.user ? { id: +sender.user.id, name: sender.user.name || '' } : null,
			project: project ? { id: +project.id, ref: project.ref || '' } : null,
			elements: links.filter(l => !['societe', 'contact', 'project'].includes(l.type)).map(l => ({ type: l.type, id: +l.id, ref: l.ref || '' })),
			// Addresses only (1.108.0); correspondents: the sender and the recipients, without the mailboxes of the user
			to: addressList(m.to),
			cc: addressList(m.cc),
			correspondents: correspondents(m),
		};
	};
	function addressList(list) {
		return (Array.isArray(list) ? list : []).map(a => String((a && a.email) || '').trim().toLowerCase()).filter(e => e.indexOf('@') > 0);
	}
	function correspondents(m) {
		const own = new Set();
		S.folders.forEach(e => {
			if (e && e.account && e.account.email) {
				own.add(String(e.account.email).toLowerCase());
			}
		});
		const all = addressList([m.from]).concat(addressList(m.to), addressList(m.cc));
		return all.filter((e, i) => !own.has(e) && all.indexOf(e) === i).slice(0, 20);
	}
	/**
	 * Address of a page of a module filled with the markers of inbox (1.108.0): {id}, {socid}, {contactids},
	 * {projectid}, {elements}, {start} anywhere; {subject}, {messageid}, {emails} only after the « # », a part that
	 * the browser never sends to the server (no mail address nor subject in its logs), emptied before it
	 * @param {string} url Address declared by the module
	 * @param {object|null} mail Context of the mail (I.mailContext())
	 * @param {object} extra Other values ({id, start})
	 * @returns {string}
	 */
	I.moduleUrl = (url, mail, extra) => {
		const m = mail || {};
		const values = Object.assign({
			id: '',
			socid: m.thirdparty ? m.thirdparty.id : '',
			contactids: m.contact ? m.contact.id : '',
			projectid: m.project ? m.project.id : '',
			elements: (m.elements || []).map(e => e.type + ':' + e.id).join(','),
			start: '',
			subject: m.subject || '',
			messageid: m.messageId || '',
			emails: (m.correspondents || []).join(','),
		}, extra || {});
		const safe = /\{(id|socid|contactids|projectid|elements|start)\}/g;
		const all = /\{(id|socid|contactids|projectid|elements|start|subject|messageid|emails)\}/g;
		const text = String(url);
		const cut = text.indexOf('#');
		const head = (cut < 0 ? text : text.slice(0, cut)).replace(safe, (x, k) => encodeURIComponent(String(values[k] ?? ''))).replace(/\{(subject|messageid|emails)\}/g, '');
		return cut < 0 ? head : head + '#' + text.slice(cut + 1).replace(all, (x, k) => encodeURIComponent(String(values[k] ?? '')));
	};
	/**
	 * A page of this site in a window of inbox, outside of a mail (the agenda panel): closed, onClose is called
	 * @param {object} o {key, url, title, onClose}
	 * @returns {boolean} Opened (false: not an address of this site)
	 */
	I.openPageWindow = o => {
		const url = sameOriginUrl(o.url);
		if (!url) {
			return false;
		}
		openModuleWindow({ mailId: 0, box: null, panel: 'page:' + (o.key || 'page'), url, title: String(o.title || '').slice(0, 200), onClose: o.onClose });
		return true;
	};

	function showNothing() {
		clearTimeout(S.readTimer);
		S.openId = 0;
		S.mail = null;
		routeList();
		syncPaneBar(null);
		agendaMail(0);
		const f = currentFolder();
		const pane = readPane();
		pane.innerHTML = '<div class="ibx-empty">' + ic('mail-opened', 'ibx-empty__icon')
			// A unified view: its name and, for the inbox and the unread, its unread mails (1.29.0)
			+ (S.scope === 'view' ? '<div class="ibx-empty__title">' + th('InboxView_' + S.view) + '</div>' + (['inbox', 'unread'].includes(S.view) ? '<div>' + th('InboxSyncUnread', viewCount(S.view)) + '</div>' : '')
				: (f ? '<div class="ibx-empty__title">' + esc(folderName(f.folder)) + '</div><div>' + th('InboxSyncUnread', f.folder.unread) + '</div>' : ''))
			+ '<div class="ibx-shortcuts"><span><kbd class="ibx-kbd">↑</kbd> <kbd class="ibx-kbd">↓</kbd> ' + th('InboxKeyNav') + '</span>'
			+ '<span><kbd class="ibx-kbd">N</kbd> ' + th('InboxKeyNextUnread') + '</span>'
			+ '<span><kbd class="ibx-kbd">?</kbd> ' + th('InboxKeyHelp') + '</span></div></div>';
	}

	function showMulti() {
		clearTimeout(S.readTimer);
		S.openId = 0;
		agendaMail(0);
		S.mail = null;
		routeList();
		syncPaneBar(null);
		const n = S.selected.size;
		// The actions with their names here, where there is room (the bar of the list keeps them short)
		readPane().innerHTML = '<div class="ibx-empty ibx-multi">' + ic('multi', 'ibx-empty__icon') + '<div class="ibx-empty__title">' + th('InboxSelectedCount', n) + '</div>'
			// « The whole folder »: a folder only (a unified view is not a folder)
			+ (S.next && n >= S.rows.length && S.scope !== 'view' ? '<button type="button" class="ibx-btn ibx-js-allfolder">' + th('InboxSelectWholeFolder', (S.total || 0).toLocaleString(locale())) + '</button>' : '')
			+ '<div class="ibx-multi__actions" role="toolbar" aria-label="' + th('InboxBulkActions') + '">' + bulkActions(Array.from(S.selected)).map(a => bulkButton(a, '', false)).join('') + '</div>'
			+ '<div class="ibx-muted">' + th('InboxBulkHint') + '</div></div>';
		$('.ibx-multi__actions', readPane()).addEventListener('click', onBulkClick);
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

	// ---------- The mail open in the address (UX fix series) ----------
	// #/mail/<folder>/<mail>, #/mail/view/<view>/<mail>, #/mail/vview/<id>/<mail>: a reload opens the mail again; the
	// back of the browser (and of a phone) goes from a mail to its list. Only numbers in the address.
	/** Address of the list shown (folder, unified view or virtual view) */
	function listHash() {
		if (S.vview) {
			return '#/mail/vview/' + S.vview;
		}
		if (S.scope === 'view' && S.view) {
			return '#/mail/view/' + S.view;
		}
		return '#/mail/' + (S.folderId || '');
	}
	/** Mail named by the address, 0 without one */
	function mailInHash() {
		const parts = location.hash.replace(/^#\/mail\/?/, '').split('/').filter(Boolean);
		return +((parts[0] === 'view' || parts[0] === 'vview') ? parts[2] : parts[1]) || 0;
	}
	/**
	 * A mail opened. The back of the browser goes where the user was, the back arrow of the page to the list:
	 * - from the list, or a jump from a mail to another one (« See the mail », the bell, a bounce...): a step of the
	 *   history, which counts how many mails away from the list it is (depth, for the back arrow);
	 * - the next mail by the keys, another card of the list: the address only (no step per mail glanced at);
	 * - while the address itself is followed (back, forward, reload): left as it is.
	 */
	function routeMail(id) {
		const target = listHash() + '/' + id;
		const jump = S.jump;
		S.jump = false;
		if (S.routing || location.hash === target) {
			return;
		}
		const depth = mailInHash() ? (history.state && history.state.ibxDepth) || 0 : 0;
		if (jump || !mailInHash()) {
			history.pushState({ ibxMail: id, ibxDepth: depth + 1 }, '', target);
		} else {
			history.replaceState(Object.assign({}, history.state, { ibxMail: id }), '', target);
		}
	}
	/** No mail open any more: the address of the list (never a step); not while a jump or the address is followed */
	function routeList() {
		if (S.routing || S.jump) {
			return;
		}
		if (mailInHash()) {
			history.replaceState(null, '', listHash());
		}
	}
	async function openMail(id, single) {
		clearTimeout(S.readTimer);
		S.openId = id;
		agendaMail(id);
		routeMail(id);
		S.mail = null;
		syncPaneBar(null);
		S.thread = null;
		S.blocks = {};
		$$('.ibx-sheetback', S.el).forEach(x => x.remove());
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
				routeList();
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
		learnPeople(mail.assignee_people);
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

	/**
	 * Card of a Dolibarr object on hover or keyboard focus of its link: the tooltip of Dolibarr (content of
	 * getTooltipContent, hooks of the modules included) in a light card of the application. The page loads neither the
	 * JS of the footer of Dolibarr nor its icons: the parameters of the ajax tooltip of getNomUrl (data-params) are sent
	 * to core/ajax/ajaxtooltip.php here (rights checked there), or its content is given whole when the ajax tooltip is
	 * off. Asked once per object and page. A phone opens the card of the object, no hover there.
	 */
	const OT = { src: new Map(), cache: new Map(), el: null, for: null, show: 0, hide: 0, styled: false };
	/** Key of the tooltip of a piece ('' when Dolibarr gives none), its source kept aside */
	function objTipKey(d) {
		if (!d.tip_params && !d.tip_html) {
			return '';
		}
		const key = d.type + ':' + d.id;
		OT.src.set(key, { params: d.tip_params || null, html: d.tip_html || '', title: d.kind + ' ' + (d.ref || '') });
		return key;
	}
	/** Styles of the content of the tooltips and icons of Dolibarr, added once at the first card */
	function objTipStyles() {
		if (OT.styled) {
			return;
		}
		OT.styled = true;
		['INBOX_FONTAWESOME_URL', 'INBOX_DOLTIP_CSS_URL'].forEach(k => {
			const href = I.ctx(k, '');
			if (href) {
				const link = document.createElement('link');
				link.rel = 'stylesheet';
				link.href = href;
				document.head.appendChild(link);
			}
		});
	}
	/**
	 * HTML of a card of Dolibarr kept to what a card shows (audit INBX-061): the core does not escape every value of
	 * its tooltips (subject of a ticket made from a mail, name of a contact), a tag or an event handler written there
	 * would run in the page of inbox. Read in an inert document, only simple tags, no event, links and pictures of
	 * Dolibarr only.
	 * @param {string} html HTML of core/ajax/ajaxtooltip.php
	 * @returns {string}
	 */
	const TIP_TAGS = new Set(['A', 'B', 'BR', 'DIV', 'EM', 'HR', 'I', 'IMG', 'LI', 'P', 'SMALL', 'SPAN', 'STRONG', 'TABLE', 'TBODY', 'TD', 'TH', 'THEAD', 'TR', 'U', 'UL']);
	const TIP_ATTRS = new Set(['class', 'title', 'alt', 'colspan', 'rowspan', 'width', 'height', 'style', 'href', 'src']);
	function tipHtml(html) {
		const doc = new DOMParser().parseFromString('<body>' + String(html || '') + '</body>', 'text/html');
		const walk = node => {
			for (const el of Array.from(node.children)) {
				if (!TIP_TAGS.has(el.tagName)) {
					el.replaceWith(doc.createTextNode(el.textContent || ''));
					continue;
				}
				for (const at of Array.from(el.attributes)) {
					const name = at.name.toLowerCase();
					const ok = TIP_ATTRS.has(name)
						&& (name !== 'style' || !/url\s*\(|expression|@import|position\s*:/i.test(at.value))
						&& ((name !== 'href' && name !== 'src') || sameOriginUrl(at.value) !== '');
					if (!ok) {
						el.removeAttribute(at.name);
					}
				}
				if (el.tagName === 'A') {
					el.setAttribute('rel', 'noopener noreferrer');
				}
				walk(el);
			}
		};
		walk(doc.body);
		// Then the one door (lot S3): DOMPurify and the Sanitizer API, the classes of Dolibarr kept
		return I.safeHtml(doc.body.innerHTML, 'tooltip');
	}
	function objTipLoad(key) {
		if (!OT.cache.has(key)) {
			const src = OT.src.get(key) || {};
			let p;
			if (src.params) {
				const url = I.ctx('INBOX_TOOLTIP_URL', '') + '?' + new URLSearchParams(Object.entries(src.params).map(([k, v]) => [k, String(v)])).toString();
				p = fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
					.then(r => (r.ok ? r.text() : ''))
					.catch(() => '');
			} else {
				p = Promise.resolve(src.html || '');
			}
			// A failure is asked again at the next hover
			p.then(html => {
				if (!html) {
					OT.cache.delete(key);
				}
			});
			OT.cache.set(key, p);
		}
		return OT.cache.get(key);
	}
	function objTipHide() {
		clearTimeout(OT.show);
		clearTimeout(OT.hide);
		if (OT.el) {
			OT.el.remove();
			OT.el = null;
		}
		if (OT.for) {
			OT.for.removeAttribute('aria-describedby');
			OT.for = null;
		}
	}
	function objTipPlace(anchor) {
		const el = OT.el;
		const r = anchor.getBoundingClientRect();
		const w = el.offsetWidth;
		const h = el.offsetHeight;
		el.style.left = Math.max(8, Math.min(r.left, window.innerWidth - w - 8)) + 'px';
		el.style.top = (r.bottom + h + 12 > window.innerHeight ? Math.max(8, r.top - h - 6) : r.bottom + 6) + 'px';
	}
	async function objTipShow(anchor) {
		const key = anchor.dataset.objtip;
		if (!key || OT.for === anchor) {
			return;
		}
		objTipHide();
		objTipStyles();
		OT.for = anchor;
		const el = document.createElement('div');
		el.className = 'ibx-objtip';
		el.id = 'ibx-objtip';
		el.setAttribute('role', 'tooltip');
		el.innerHTML = '<span class="ibx-muted"><span class="ibx-spinner" aria-hidden="true"></span> ' + th('InboxPanelLoading') + '</span>';
		document.body.appendChild(el);
		OT.el = el;
		anchor.setAttribute('aria-describedby', el.id);
		objTipPlace(anchor);
		el.addEventListener('pointerenter', () => clearTimeout(OT.hide));
		el.addEventListener('pointerleave', () => {
			OT.hide = setTimeout(objTipHide, 200);
		});
		const html = await objTipLoad(key);
		if (OT.for !== anchor || OT.el !== el) {
			return;
		}
		if (!html) {
			objTipHide();
			return;
		}
		// HTML of Dolibarr (same trust as its pages), drawn by css/inbox-doltip.css
		el.innerHTML = '<div class="ibx-objtip__body">' + tipHtml(html) + '</div>';
		I.mount(el);
		objTipPlace(anchor);
	}
	(() => {
		document.addEventListener('pointerover', e => {
			const a = e.target.closest && e.target.closest('[data-objtip]');
			if (!a || e.pointerType === 'touch') {
				return;
			}
			clearTimeout(OT.hide);
			if (OT.for !== a) {
				clearTimeout(OT.show);
				OT.show = setTimeout(() => objTipShow(a), 350);
			}
		});
		document.addEventListener('pointerout', e => {
			const a = e.target.closest && e.target.closest('[data-objtip]');
			if (!a || (e.relatedTarget && a.contains(e.relatedTarget))) {
				return;
			}
			clearTimeout(OT.show);
			OT.hide = setTimeout(objTipHide, 200);
		});
		document.addEventListener('focusin', e => {
			const a = e.target.closest && e.target.closest('[data-objtip]');
			if (a && a.matches(':focus-visible')) {
				objTipShow(a);
			} else if (OT.el && !(e.target.closest && e.target.closest('.ibx-objtip'))) {
				objTipHide();
			}
		});
		document.addEventListener('keydown', e => {
			if (e.key === 'Escape' && OT.el) {
				objTipHide();
			}
		});
		window.addEventListener('scroll', () => OT.el && objTipHide(), true);
		window.addEventListener('resize', () => OT.el && objTipHide());
	})();
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
		I.mount(el);
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
		// The mailbox written once (1.102.0): its address alone as recipient already says where the mail came
		const sameBox = recipients.length === 1 && !!box && String(recipients[0].email || '').toLowerCase() === box.toLowerCase()
			&& !nameOf(recipients[0]) && !personOf(recipients[0].email, m.people);
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
				: esc(fromName || m.from.email || t('InboxNoSender')) + (fromName ? ' <span class="ibx-readhead__email">&lt;' + esc(m.from.email) + '&gt;</span>' : '')
					+ (m.trusted && m.trusted.state === 'ok' ? ' ' + trustedBadge(m.trusted) : '')) + '</div>'
			+ '<div class="ibx-readhead__line ibx-readhead__date"><span title="' + esc(longDate(m.date)) + '">' + esc(longDate(m.date)) + '</span></div></div>'
			// Phone: the short date on the line of the name, the full one said to screen readers and as a tooltip
			+ (m.date ? '<time class="ibx-readhead__when" datetime="' + esc(new Date(m.date * 1000).toISOString()) + '" title="' + esc(longDate(m.date)) + '">'
				+ '<span aria-hidden="true">' + esc(headDate(m.date)) + '</span><span class="ibx-sr-only">' + esc(longDate(m.date)) + '</span></time>' : '')
			+ (partial ? '' : '<div class="ibx-readhead__actions">' + actionButtons(m) + '</div>') + '</div>'
			+ '<div class="ibx-readhead__line ibx-readhead__to">' + toLine + (box && !sameBox ? (toLine ? ' · ' : '') + th('InboxReceivedOn') + ' <b>' + esc(box) + '</b>' : '') + '</div>'
			// Protected mailbox (1.89.0): never assigned (nothing kept in Dolibarr)
			+ (partial || inThread || m.live ? '' : assignLine([m.id], m.assignees || [], m.has_message_id !== false))
			+ (partial ? '' : blockedLine(m))
			+ (partial ? '' : dolChipsSlot())
			+ '<h2 class="ibx-readhead__subject">' + esc(m.subject || t('InboxNoSubject')) + ' ' + (partial ? tagsHtml({ folder: m.folder, tags: m.tags }) : headTagsHtml(m.id, { folder: m.folder, tags: m.tags })) + '</h2>';
	}

	// ---------- Actions on mails (lot W4: write on the server, checked, undo 8 s) ----------
	/** Actions after which the mail leaves the folder */
	const LEAVING = new Set(['move', 'archive', 'delete', 'junk', 'notjunk']);
	/** Actions of the level « manage » (read / unread and starred: « read », lot W4 §10 point 2) */
	const MANAGING = new Set(['tag', 'move', 'archive', 'delete', 'junk', 'notjunk']);
	/** Mails per call (a larger selection goes by batches, decision W4 point 3) */
	const BATCH = 200;
	/** From this number of mails an action shows its progress (the user sees the work going on) */
	const PROGRESS_FROM = 20;

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
	/** Rank of the levels of access (1.92.0: « handle » between « send » and « manage ») */
	const LEVEL_RANK = { read: 1, send: 2, handle: 3, manage: 4 };
	/** A mailbox (or a mail, by its level) where the mails may be handled: tagged, moved, deleted, folders created */
	function canHandle(x) {
		return (LEVEL_RANK[x && x.level] || 0) >= LEVEL_RANK.handle;
	}
	/** Why an action is refused on these mails ('' = allowed); the server checks it again */
	function refusal(what, ids) {
		if (!MANAGING.has(what)) {
			return '';
		}
		for (const id of ids) {
			const a = accountOfRow(rowOf(id));
			if (a && !canHandle(a)) {
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

	// ---------- Actions still running on the server (1.90.1) ----------
	/**
	 * Rows of a list read from the server without the mails of the actions still running: they left the screen, a
	 * refresh (poll, synchronisation, another action) must not show them again before the server is done
	 * @param {object[]} list Rows or mails
	 * @param {boolean} mailsOnly Mails of a conversation: by id only (the thread key would take them all)
	 * @returns {object[]}
	 */
	function pendingKeep(list, mailsOnly) {
		list = list || [];
		if (!S.pending.size) {
			return list;
		}
		return list.filter(r => {
			for (const a of S.pending) {
				if (a.ids.has(r.id) || (!mailsOnly && r.thread && a.threads.has(r.thread.key) && a.from.has(r.folder))) {
					return false;
				}
			}
			return true;
		});
	}
	/** Folders left or reached by an action running: their counts stay the ones of the screen */
	function pendingFolders() {
		const out = new Set();
		S.pending.forEach(a => {
			a.from.forEach(id => out.add(id));
			a.to.forEach(id => out.add(id));
		});
		return out;
	}
	/** Folders reached by an action running (a spinner on them in the tree) */
	function pendingTargets() {
		const out = new Set();
		S.pending.forEach(a => a.to.forEach(id => out.add(id)));
		return out;
	}
	/** Folder where a leaving action puts the mail (null: none known, or removed for good) */
	function targetOf(what, r, p) {
		if (what === 'move') {
			const e = S.folders.get(p.folder);
			return e ? e.folder : null;
		}
		if (what === 'delete') {
			return p.confirm ? null : roleFolderOf(r, 'trash');
		}
		return roleFolderOf(r, { archive: 'archive', junk: 'junk', notjunk: 'inbox' }[what] || '');
	}
	/** The page asks before being closed while an action runs (a move to another mailbox is driven from here) */
	function leaveGuard(e) {
		e.preventDefault();
		e.returnValue = '';
	}
	/**
	 * An action leaving a folder registered while it runs; the folders reached count its mails at once
	 * @returns {object} Given to pendingDone
	 */
	function pendingAdd(what, rows, p, expand) {
		const a = { ids: new Set(rows.map(r => r.id)), threads: new Set(), from: new Set(), to: new Set(), added: [] };
		rows.forEach(r => {
			a.from.add(r.folder);
			const n = r.thread && expand.includes(r.id) ? Math.max(1, r.thread.count || 1) : 1;
			if (n > 1) {
				a.threads.add(r.thread.key);
			}
			const f = targetOf(what, r, p);
			if (!f || f.id === r.folder) {
				return;
			}
			a.to.add(f.id);
			// Trash and junk: the mail is marked read on the way (1.90.0, setting of the user); archive: own setting (1.96.0)
			const unread = seenOf(r) || ((f.role === 'trash' || f.role === 'junk') && params.read_on_trash !== '0') || (what === 'archive' && params.read_on_archive !== '0') ? 0 : (r.thread && n > 1 ? (r.thread.unread || 0) : 1);
			f.total = (f.total || 0) + n;
			f.unread = (f.unread || 0) + unread;
			a.added.push({ f, n, unread });
		});
		if (!S.pending.size) {
			window.addEventListener('beforeunload', leaveGuard);
		}
		S.pending.add(a);
		renderTree();
		return a;
	}
	/** The server answered: the mails are no longer kept out, the tree takes the counts of the server again */
	function pendingDone(a, failed) {
		S.pending.delete(a);
		if (!S.pending.size) {
			window.removeEventListener('beforeunload', leaveGuard);
		}
		if (failed) {
			a.added.forEach(x => {
				x.f.total = Math.max(0, (x.f.total || 0) - x.n);
				x.f.unread = Math.max(0, (x.f.unread || 0) - x.unread);
			});
		}
		renderTree();
	}
	/** Mails of an action told in its toast before the server counts them (a folded conversation: its mails) */
	function estimateCount(rows, expand) {
		return rows.reduce((n, r) => n + (r.thread && expand.includes(r.id) ? Math.max(1, r.thread.count || 1) : 1), 0);
	}
	/** What the list shows (folder, view, search): an answer coming late only touches the list it was given for */
	function listKey() {
		return JSON.stringify([S.scope, S.folderId, S.q, S.scope === 'view' ? listQuery('') : '']);
	}

	/**
	 * Run an action: at once on the screen, then on the server by batches; refused = back as before with the reason
	 * and « Retry »; done = toast « Undo » 8 s. A leaving action (1.90.1) tells its toast at once and keeps its mails
	 * out of the list until the server answers; an error alone is told after
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
		const expand = foldedThreads(ids);
		const restore = leaving ? leaveRows(ids, o) : applyLocal(what, rows, p);
		if (expand.length) {
			p.expand = expand;
		}
		const track = leaving ? pendingAdd(what, rows, p, expand) : null;
		// Under 20 mails no progress is shown: the toast comes at once, its « Undo » waits for the server if needed
		const early = leaving && !o.quiet && ids.length < PROGRESS_FROM;
		let settled = null;
		let undoAsked = false;
		let earlyToast = null;
		if (early) {
			earlyToast = toast(o.label || actionLabel(what, estimateCount(rows, expand), p), p.confirm ? {} : { action: t('InboxUndo'), onAction: () => {
				if (settled) {
					if (settled.undo.length) {
						undoAction(settled);
					}
					return;
				}
				undoAsked = true;
				toast(t('InboxUndoQueued'));
			} });
		}
		let res = null;
		try {
			res = await sendAction(what, ids, p);
		} finally {
			if (track) {
				pendingDone(track, !res || !res.count);
			}
		}
		settled = res;
		if (!res.count) {
			// The toast « Moved » told too early: only the error stays
			if (earlyToast) {
				clearTimeout(earlyToast._ibxTimer);
				earlyToast.remove();
			}
			restore();
			if (!res.stopped) {
				toast(failureText(what, rows, p, res.error), { error: true, action: t('InboxRetry'), onAction: () => runAction(what, ids, p, o) });
			}
			return null;
		}
		if (res.stopped) {
			// Stopped by the user: the mails not reached are back in the list, the part done can be undone
			loadList(true);
			if (leaving) {
				refreshTree();
			}
			toast(t('InboxProgressStopped', res.count, ids.length), res.undo.length ? { action: t('InboxUndo'), onAction: () => undoAction(res) } : {});
			return res;
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
		if (undoAsked) {
			if (res.undo.length) {
				undoAction(res);
			}
		} else if (!o.quiet && !early) {
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
	/**
	 * Progress of an action on many mails (from 20, 1.42.0): a card at the bottom with the count and a bar,
	 * « Reduce » to a pill (the work goes on, even in another folder), « Stop » after the batch running; the page asks
	 * before being closed meanwhile
	 * @param {string} label What is done (« Moving to Archives »)
	 * @param {number} total Mails asked
	 * @returns {{update: function(number), stopped: function(): boolean, close: function()}}
	 */
	function progressBox(label, total) {
		const el = document.createElement('div');
		el.className = 'ibx-progress';
		el.setAttribute('role', 'status');
		el.setAttribute('aria-live', 'polite');
		let done = 0;
		let small = false;
		let stop = false;
		const leave = e => {
			e.preventDefault();
			e.returnValue = '';
		};
		const paint = () => {
			const pct = total ? Math.min(100, Math.round(100 * done / total)) : 0;
			el.classList.toggle('is-small', small);
			el.innerHTML = small
				? '<button type="button" class="ibx-progress__pill ibx-js-progopen" title="' + esc(label) + '" aria-label="' + esc(label + ' : ' + t('InboxProgressCount', done, total)) + '">' + pct + ' %</button>'
				: '<div class="ibx-progress__head"><b class="ibx-ellipsis">' + esc(label) + '</b><button type="button" class="ibx-btn ibx-btn--icon ibx-btn--sm ibx-js-progmin" title="' + th('InboxProgressReduce') + '" aria-label="' + th('InboxProgressReduce') + '">' + ic('minimize') + '</button></div>'
				+ '<progress max="' + total + '" value="' + done + '"></progress>'
				+ '<div class="ibx-progress__foot"><span class="ibx-muted">' + (stop ? th('InboxProgressStopping') : th('InboxProgressCount', done.toLocaleString(locale()), total.toLocaleString(locale()))) + '</span>'
				+ '<button type="button" class="ibx-btn ibx-btn--sm ibx-js-progstop"' + (stop ? ' disabled' : '') + '>' + th('InboxProgressStop') + '</button></div>';
			const min = $('.ibx-js-progmin', el);
			if (min) {
				min.addEventListener('click', () => {
					small = true;
					paint();
					$('.ibx-js-progopen', el).focus();
				});
			}
			const open = $('.ibx-js-progopen', el);
			if (open) {
				open.addEventListener('click', () => {
					small = false;
					paint();
				});
			}
			const halt = $('.ibx-js-progstop', el);
			if (halt) {
				halt.addEventListener('click', () => {
					stop = true;
					paint();
				});
			}
		};
		paint();
		document.body.appendChild(el);
		window.addEventListener('beforeunload', leave);
		return {
			update(n) {
				done = n;
				paint();
			},
			stopped: () => stop,
			close() {
				window.removeEventListener('beforeunload', leave);
				el.remove();
			},
		};
	}
	/** Words of the progress of an action */
	function progressLabel(what, p) {
		if (what === 'move' && p.folder && S.folders.has(p.folder)) {
			return t('InboxProgressMove', folderName(S.folders.get(p.folder).folder));
		}
		return t({ archive: 'InboxProgressArchive', delete: 'InboxProgressDelete', junk: 'InboxProgressJunk', notjunk: 'InboxProgressNotJunk', tag: 'InboxProgressTag' }[what] || 'InboxProgressOther');
	}
	async function sendAction(what, ids, p, undoOf) {
		const out = { count: 0, undo: [], log: [], ids: {}, error: '', stopped: false };
		let todo = ids.slice();
		let turns = 0;
		// From 20 mails: the progress shown (not for an undo, it follows its action)
		const prog = ids.length >= PROGRESS_FROM && !undoOf ? progressBox(progressLabel(what, p), ids.length) : null;
		// With a progress: about ten steps (batches of 20 to 200), so that the bar moves
		const size = prog ? Math.min(BATCH, Math.max(PROGRESS_FROM, Math.ceil(ids.length / 10))) : BATCH;
		try {
			while (todo.length && turns++ < 500) {
				if (prog && prog.stopped()) {
					out.stopped = true;
					break;
				}
				await sendBatch();
				if (out.halt) {
					break;
				}
				if (prog) {
					prog.update(Math.min(ids.length, out.count));
				}
			}
		} finally {
			if (prog) {
				prog.close();
			}
		}
		delete out.halt;
		return out;
		/** One batch sent; out.halt set when the batches stop */
		async function sendBatch() {
			const chunk = todo.slice(0, size);
			todo = todo.slice(size);
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
				out.halt = true;
				return;
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
					out.halt = true;
					return;
				}
				todo = res.rest.concat(todo);
			}
		}
	}

	async function undoAction(res) {
		let failed = '';
		// A mail moved back may come with a new row (server without the new identifier of the mail: the folder read
		// again): the next steps (unread again, 1.90.0; keywords) follow it to its new row
		const moved = {};
		for (const u of res.undo) {
			const ids = (u.ids || []).map(id => moved[id] || id);
			const r = await sendAction(u.action, ids, { on: u.on, add: u.add || [], remove: u.remove || [], folder: u.folder || 0 }, res.log);
			if (r.error && !r.count) {
				failed = r.error;
			}
			Object.keys(r.ids || {}).forEach(from => {
				const to = +r.ids[from];
				Object.keys(moved).forEach(k => {
					if (moved[k] === +from) {
						moved[k] = to;
					}
				});
				moved[from] = to;
			});
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
		// Given back late (1.90.1): only to the list it left, the rest of the list as it is now (another action, a
		// refresh), the selection only when the user did not choose something else meanwhile
		const key = listKey();
		const openAfter = S.openId;
		return () => {
			refreshTree();
			if (listKey() !== key) {
				return;
			}
			const back = putBack(before.rows, S.rows, set);
			S.total += back.length - S.rows.length;
			S.rows = back;
			for (const k in before.cache) {
				if (S.threadCache[k]) {
					S.threadCache[k] = putBack(before.cache[k], S.threadCache[k], set);
				}
			}
			const untouched = S.openId === openAfter;
			if (untouched) {
				S.selected = new Set(Array.from(before.selected).filter(id => S.rows.some(r => r.id === id)));
				S.anchor = before.anchor;
			}
			renderList();
			renderListHead();
			if (untouched && before.open && S.selected.size <= 1) {
				openMail(before.open);
			}
			renderBulkBar();
		};
	}

	/**
	 * Mails given back to a list at their place of before, after the row before them that is still there
	 * @param {object[]} old List before the action
	 * @param {object[]} cur List now
	 * @param {Set<number>} set Mails given back
	 * @returns {object[]}
	 */
	function putBack(old, cur, set) {
		const out = cur.slice();
		const has = new Set(out.map(r => r.id));
		old.forEach((r, i) => {
			if (!set.has(r.id) || has.has(r.id)) {
				return;
			}
			let at = 0;
			for (let j = i - 1; j >= 0; j--) {
				const k = out.findIndex(x => x.id === old[j].id);
				if (k >= 0) {
					at = k + 1;
					break;
				}
			}
			out.splice(at, 0, r);
			has.add(r.id);
		});
		return out;
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
		syncPaneBar(S.mail);
		const subject = $('.ibx-readhead__subject', root);
		if (subject) {
			subject.innerHTML = esc(S.mail.subject || t('InboxNoSubject')) + ' ' + headTagsHtml(S.mail.id, { folder: S.mail.folder, tags: S.mail.tags });
		}
	}

	/**
	 * Error of an action refused: a move names the mail and the folder (the user may be elsewhere when it comes back)
	 * @param {string} what Action
	 * @param {object[]} rows Mails
	 * @param {object} p Parameters of the action
	 * @param {string} error Reason given by the server
	 * @returns {string}
	 */
	function failureText(what, rows, p, error) {
		const why = error || t('InboxErrorServer', '');
		const e = what === 'move' ? S.folders.get(p.folder) : null;
		if (!e) {
			return why;
		}
		return rows.length === 1
			? t('InboxMoveFailedOne', rows[0].subject || t('InboxNoSubject'), folderName(e.folder), why)
			: t('InboxMoveFailedMany', rows.length, folderName(e.folder), why);
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
		// « Not junk » on a mail of a blocked sender (1.85.0): its next mails would go back to the junk, said with the
		// way out
		const b = !junk && S.mail && S.mail.blocked && ids.includes(S.mail.id) ? S.mail.blocked : null;
		const res = runAction(junk ? 'junk' : 'notjunk', ids, {}, o);
		if (b) {
			res.then(r => {
				if (r) {
					toast(t('InboxBlockedStill', b.value), b.can_unblock ? { action: t('InboxUnblock'), onAction: () => unblockEntry(b) } : {});
				}
			});
		}
		return res;
	}

	// ---------- Blocked senders (1.85.0, analyses/analyse-expediteurs-bloques.md) ----------
	/** Date of a timestamp in the language of the user */
	function blockedDate(ts) {
		return new Date(ts * 1000).toLocaleDateString((I.ctx('MAIN_LANG_DEFAULT', 'fr_FR') || 'fr_FR').replace('_', '-'));
	}
	/** Line of the reading: the sender is blocked, by whom and when, « Unblock » when the user may */
	function blockedLine(m) {
		const b = m.blocked;
		if (!b) {
			return '';
		}
		return '<div class="ibx-readhead__line ibx-blockedline">' + ic('block') + ' <span>' + th(b.type === 'domain' ? 'InboxBlockedLineDomain' : 'InboxBlockedLine', b.value, b.by || '-', blockedDate(b.date)) + '</span>'
			+ (b.can_unblock ? ' <button type="button" class="ibx-linkbtn ibx-js-unblock">' + th('InboxUnblock') + '</button>' : '') + '</div>';
	}
	/** Unblock from the reading: undone by blocking again, no confirmation */
	async function unblockEntry(b) {
		try {
			await post('blockedsender', 'delete', { id: b.id });
		} catch (err) {
			toast(err.message, { error: true });
			return;
		}
		toast(t('InboxUnblocked', b.value));
		if (S.mail && S.mail.blocked && S.mail.blocked.id === b.id) {
			S.mail.blocked = null;
			$$('.ibx-blockedline', readPane()).forEach(line => line.parentNode.removeChild(line));
		}
	}
	/**
	 * « Block the sender… »: the server says who the sender is (unknown, known, protected) and what this user may
	 * block; a known sender asks the address typed again
	 */
	async function blockDialog(id) {
		let o;
		try {
			o = await api('blockedsender', 'offer', { mail: id });
		} catch (err) {
			toast(err.message, { error: true });
			return;
		}
		if (o.blocked && o.entry) {
			const e = o.entry;
			const can = o.manager || e.user_id === I.ctx('INBOX_USER_ID', 0);
			dialog({
				title: t('InboxBlockSender'),
				body: '<p>' + th(e.type === 'domain' ? 'InboxBlockedLineDomain' : 'InboxBlockedLine', e.value, e.user_creat || '-', blockedDate(e.date_creation)) + '</p>',
				actions: can ? [{ label: t('InboxUnblock'), run: () => unblockEntry({ id: e.id, value: e.value }) }] : [],
			});
			return;
		}
		const opts = [
			{ type: 'address', value: o.address, can: o.can_address, why: o.why_address, level: o.level, reason: o.reason, past: o.past_address },
			{ type: 'domain', value: o.domain, can: o.can_domain, why: o.why_domain, level: o.domain_level, reason: o.domain_reason, past: o.past_domain },
		];
		if (!opts.some(x => x.can)) {
			// Nothing this user may block: the reasons, and the junk for this mail
			dialog({
				title: t('InboxBlockSender'),
				body: '<div class="ibx-notice ibx-notice--info">' + ic('info') + '<div class="ibx-notice__text">' + esc(opts[0].why) + (opts[0].reason ? '<div class="ibx-muted">' + esc(opts[0].reason) + '</div>' : '') + '</div></div>'
					+ '<p class="ibx-muted">' + th('InboxBlockedJunkInstead') + '</p>',
			});
			return;
		}
		const first = opts.find(x => x.can);
		const radio = x => '<label class="ibx-check ibx-blockopt' + (x.can ? '' : ' is-disabled') + '"><input type="radio" name="btype" value="' + x.type + '"' + (x.can ? '' : ' disabled') + (x === first ? ' checked' : '') + '> '
			+ '<span>' + th(x.type === 'domain' ? 'InboxBlockWhatDomain' : 'InboxBlockWhatAddress', x.value) + (x.can ? '' : '<span class="ibx-muted ibx-blockopt__why">' + esc(x.why) + '</span>') + '</span></label>';
		const body = '<form class="ibx-form ibx-blockform" novalidate>'
			+ '<fieldset class="ibx-blockwhat"><legend class="ibx-formrow__label">' + th('InboxBlockWhat') + '</legend>' + opts.filter(x => x.value).map(radio).join('') + '</fieldset>'
			+ '<div class="ibx-notice ibx-notice--warning ibx-js-bknown" hidden>' + ic('warning') + '<div class="ibx-notice__text"><b>' + th('InboxBlockKnownTitle') + '</b> <span class="ibx-js-bknownwhy"></span>'
			+ '<p>' + th('InboxBlockKnownOtherTools') + '</p>'
			+ '<label class="ibx-blockconfirm" for="ibx-bconfirm">' + th('InboxBlockKnownType') + ' <b class="ibx-js-bknownval"></b></label>'
			+ '<input class="ibx-field" id="ibx-bconfirm" name="confirm" type="text" autocomplete="off" spellcheck="false"></div></div>'
			+ '<label class="ibx-check ibx-js-bpast"><input type="checkbox" name="past" checked> <span class="ibx-js-bpasttext"></span></label>'
			+ '<div class="ibx-formrow"><label class="ibx-formrow__label" for="ibx-bnote">' + th('InboxBlockNote') + '</label><div><input class="ibx-field" id="ibx-bnote" name="note" type="text" maxlength="255" autocomplete="off" placeholder="' + th('InboxBlockNotePlaceholder') + '"></div></div>'
			+ '<p class="ibx-muted">' + th('InboxBlockEffect') + '</p></form>';
		const current = dlg => opts.find(x => x.type === ($('input[name="btype"]:checked', dlg) || {}).value) || first;
		const paint = dlg => {
			const x = current(dlg);
			const known = x.level === 'known';
			$('.ibx-js-bknown', dlg).hidden = !known;
			$('.ibx-js-bknownwhy', dlg).textContent = x.reason || '';
			$('.ibx-js-bknownval', dlg).textContent = x.value;
			$('.ibx-js-bpast', dlg).hidden = !x.past.count;
			$('.ibx-js-bpasttext', dlg).textContent = t('InboxBlockPast', x.past.count, x.past.accounts);
			const go = $('.ibx-dialog__foot [data-i="0"]', dlg);
			go.textContent = t(known ? 'InboxBlockKnownButton' : 'InboxBlockButton');
			go.classList.toggle('ibx-btn--danger', known);
			go.classList.toggle('ibx-btn--primary', !known);
			if (!known || $('[name="confirm"]', dlg).value.trim().toLowerCase() === x.value) {
				go.removeAttribute('aria-disabled');
			} else {
				go.setAttribute('aria-disabled', 'true');
			}
		};
		dialog({
			title: t('InboxBlockSender'),
			body,
			actions: [{ label: t('InboxBlockButton'), primary: true, run: async dlg => {
				const x = current(dlg);
				const f = $('form', dlg);
				let r;
				try {
					r = await post('blockedsender', 'create', { mail: id, type: x.type, confirm: f.elements.confirm.value, note: f.elements.note.value,
						past: !$('.ibx-js-bpast', dlg).hidden && f.elements.past.checked ? 1 : 0 });
				} catch (err) {
					toast(err.message, { error: true });
					return false;
				}
				toast(r.moved ? t('InboxBlockedDoneMoved', x.value, r.moved) : t('InboxBlockedDone', x.value));
				if (S.mail && S.mail.id === id) {
					S.mail.blocked = { id: r.id, type: x.type, value: x.value, by: I.ctx('INBOX_USER_NAME', ''), date: Math.floor(Date.now() / 1000), can_unblock: true };
					const head = $('.ibx-readhead__subject', readPane());
					if (head && !$('.ibx-blockedline', readPane())) {
						head.insertAdjacentHTML('beforebegin', blockedLine(S.mail));
					}
				}
				if (r.moved) {
					// The open mail of the inbox went to the junk with the others
					if (S.mail && S.mail.id === id && S.mail.folder_role === 'inbox') {
						showNothing();
					}
					loadList(true);
					refreshTree();
				}
			} }],
			onOpen: dlg => {
				dlg.addEventListener('input', () => paint(dlg));
				dlg.addEventListener('change', () => paint(dlg));
				paint(dlg);
			},
		});
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
		const a = rows.length ? accountOfRow(rows[0]) : null;
		const defs = a ? (a.tags || []) : [];
		const all = rows.every(r => keywordsOfTag(defs, r, keyword).length > 0);
		// Removed: the keyword and its alias $labelN set by Thunderbird (else the tag would stay on the server)
		const remove = Array.from(new Set([keyword].concat(rows.flatMap(r => keywordsOfTag(defs, r, keyword)))));
		// One mail: the tag is seen on it and undone by the same key, no toast (several mails: « Undo »)
		return runAction('tag', ids, all ? { remove } : { add: [keyword] }, { quiet: ids.length < 2 });
	}

	/** Dot of a tag for the menus: its colour (adapted to the theme), its border style when it has one */
	function tagDot(d) {
		const C = Dolibarr.tools.inboxColors;
		const c = C.isColor(d.view) ? d.view : (C.isColor(d.color) ? d.color : '');
		const border = C.BORDERS.includes(d.border) && d.border !== 'none' ? ' ibx-tagdot--b-' + d.border : '';
		return '<span class="ibx-menu__icon ibx-tagdot' + border + '" aria-hidden="true"' + (c ? ' style="--ibx-tag-color:' + c + '"' : '') + '></span>';
	}

	/** Menu of the tags of the mailbox, checked when every mail has it */
	function tagMenu(anchor, ids, at) {
		const rows = ids.map(rowOf).filter(Boolean);
		const a = accountOfRow(rows[0]);
		const defs = a ? (a.tags || []) : [];
		const why = refusal('tag', ids);
		// Each tag with a dot of its colour and its border: the one to put is seen at once (the label stays written)
		const items = defs.map((d, i) => ({ swatch: tagDot(d), label: d.label, key: i < 9 ? String(i + 1) : '', checked: rows.every(r => keywordsOfTag(defs, r, d.keyword).length > 0),
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
			path: (a.label || a.email) + ' / ' + folderPath(f), ok: canHandle(a) && !(here.size === 1 && here.has(f.id)) })));
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
					html += '<div class="ibx-picklist__group" role="presentation">' + esc(accountName(o.account)) + (!canHandle(o.account) ? ' · ' + th('InboxReadOnly') : '') + '</div>';
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
			{ id: 'watch', icon: 'watch', label: t(rows.length && rows.every(r => r.watched) ? 'InboxUnwatchThread' : 'InboxWatchThread'), key: 'W', disabled: rows.some(r => r.live), reason: rows.some(r => r.live) ? t('InboxProtectNoDolibarr') : '', run: () => doThreadMark(ids, 'watch') },
			{ id: 'ignore', icon: 'ignore', label: t(rows.length && rows.every(r => r.ignored) ? 'InboxUnignoreThread' : 'InboxIgnoreThread'), key: 'K', disabled: !!manage || rows.some(r => r.live), reason: manage || (rows.some(r => r.live) ? t('InboxProtectNoDolibarr') : ''), run: () => doThreadMark(ids, 'ignore') },
			{ id: 'tag', icon: 'tag', label: t('InboxTagMenu'), key: '1…9', disabled: !!manage, reason: manage, run: () => tagMenu(anchor || S.el, ids) },
			{ sep: true },
			{ id: 'archive', icon: 'archive', label: t('InboxArchive'), key: 'A', disabled: !!manage || role === 'archive', reason: manage, run: () => doArchive(ids) },
			{ id: 'move', icon: 'folder-move', label: t('InboxMoveToMenu'), key: 'Maj+M', disabled: !!manage, reason: manage, run: () => moveDialog(ids) },
			inJunk ? { id: 'notjunk', icon: 'inbox', label: t('InboxNotJunk'), key: 'Maj+J', disabled: !!manage, reason: manage, run: () => doJunk(ids, false) }
				: { id: 'junk', icon: 'junk', label: t('InboxJunk'), key: 'J', disabled: !!manage, reason: manage, run: () => doJunk(ids, true) },
			// Blocked senders (1.85.0): one mail, never a mail of ours; the server says what may be blocked
			...(ids.length === 1 && I.ctx('INBOX_BLOCKED_SENDERS', false) && !['sent', 'drafts'].includes(role)
				? [{ id: 'block', icon: 'block', label: t('InboxBlockSender'), disabled: !!manage && !I.rights().blocked_sender, reason: manage, run: () => blockDialog(ids[0]) }] : []),
			{ id: 'delete', icon: 'delete', label: t(inTrash ? 'InboxDestroy' : 'InboxDelete'), key: 'Suppr', disabled: !!manage, reason: manage, danger: true, irreversible: inTrash, run: () => doDelete(ids) },
		];
		return items;
	}

	// ---------- Assignment (lot W4 1.9.0, webmail §5.4 quinquies): who handles a mail ----------
	function learnPeople(map) {
		Object.keys(map || {}).forEach(k => {
			const p = map[k];
			S.people[+k] = typeof p === 'string' ? p : (p.name || '');
			if (p && typeof p === 'object') {
				S.photos[+k] = p.photo || '';
			}
		});
	}
	/** Avatar of a user of Dolibarr: the photo of the card first, else the initials */
	function personAvatar(id) {
		return userAvatar({ id, name: S.people[id] || '', email: '', photo: S.photos[id] || '' }, 'ibx-avatar--xs' + (id === S.me ? ' is-me' : ''));
	}
	function personName(id) {
		return id === S.me ? t('InboxMe') : (S.people[id] || '#' + id);
	}
	/** Initials of the people assigned (mine in the accent), their names in the title and for the screen readers */
	function assigneesHtml(list, cls, max) {
		list = list || [];
		max = max || 3;
		if (!list.length) {
			return '';
		}
		const text = t('InboxAssignedTo', list.map(personName).join(', '));
		return ' <span class="ibx-assignees' + (cls ? ' ' + cls : '') + '" title="' + esc(text) + '">'
			+ list.slice(0, max).map(id => personAvatar(id)).join('')
			+ (list.length > max ? '<span class="ibx-assignees__more" aria-hidden="true">+' + (list.length - max) + '</span>' : '')
			+ '<span class="ibx-sr-only">' + esc(text) + '</span></span>';
	}
	/** Line « Assigned to » of the reading: full names, « Me », assign me / remove me, choose the people */
	function assignLine(ids, list, hasRef) {
		const mine = list.includes(S.me);
		return '<div class="ibx-readhead__line ibx-assignline ibx-js-assignline" data-ids="' + ids.join(',') + '">'
			// Nobody: « Not assigned » alone (« Assigned to Nobody » would read like a name)
			+ (list.length ? '<span class="ibx-assignline__label">' + th('InboxAssignedToLabel') + '</span>' : '')
			+ (list.length ? list.map(id => '<span class="ibx-assignline__person' + (id === S.me ? ' is-me' : '') + '">' + personAvatar(id)
				+ esc(personName(id)) + '</span>').join('') : '<span class="ibx-muted">' + th('InboxAssignedNobody') + '</span>')
			+ (hasRef ? '<span class="ibx-assignline__actions"><button type="button" class="ibx-btn ibx-btn--sm ibx-btn--ghost ibx-js-assignme" aria-keyshortcuts="Space">' + ic('user-plus') + ' ' + th(mine ? 'InboxUnassignMe' : 'InboxAssignMe') + '</button>'
				+ '<button type="button" class="ibx-btn ibx-btn--sm ibx-btn--ghost ibx-js-assignto" aria-haspopup="dialog">' + th('InboxAssignEdit') + '</button></span>'
				: '<span class="ibx-muted">' + th('InboxErrorNoMessageId') + '</span>')
			+ '</div>';
	}
	/** Why these mails cannot be assigned ('' = they can): no Message-ID, the key of an assignment */
	function assignRefusal(ids) {
		const rows = ids.map(rowOf).filter(Boolean);
		// Protected mailbox (1.89.0): never assigned, nothing of it kept in Dolibarr
		if (rows.some(r => r.live)) {
			return t('InboxProtectNoDolibarr');
		}
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
				hydrateUserPhotos($('.ibx-pane--read .ibx-js-assignline', S.el));
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
		if (rows.some(r => r.live)) {
			toast(t('InboxProtectNoDolibarr'), { error: true });
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
			S.photos[p.id] = p.photo || '';
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
					+ '<input type="checkbox" data-user="' + p.id + '"> ' + personAvatar(p.id) + ' <span>' + esc(p.id === S.me ? t('InboxMe') + ' (' + p.name + ')' : p.name) + '</span></label></li>').join('')
				+ '</ul><p class="ibx-muted ibx-js-pnone" hidden>' + th('InboxAssignNoMatch') + '</p>',
			actions: [{ label: t('InboxCloseWindow'), primary: true, run: () => true }],
		});
		hydrateUserPhotos(d);
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
			+ (r.bounced ? '<span class="ibx-threadmark ibx-threadmark--bounce" title="' + th('InboxBounced') + '">' + ic('warning') + '<span class="ibx-sr-only">' + th('InboxBounced') + ' </span></span>' : '')
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
		if (!m.unsubscribe.oneclick) {
			// By mail: the mail is prepared, read and sent by the user (the writing is the step before, nothing sent here)
			const okMail = await confirmBox(t('InboxUnsubscribeTitle'), th('InboxUnsubscribeByMailText', m.from.name || m.from.email), t('InboxUnsubscribePrepare'));
			if (okMail && I.compose) {
				I.compose({ mode: 'unsubscribe', mail: m.id });
			}
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
			// The site of the list refused the POST (often an anti-bot wall answering 403 to any server, a browser
			// passes): the mail of the list (RFC 2369 mailto) prepared and sent by the user, or its page opened by
			// the user in a new tab (the sender then sees his IP and browser, said in the text)
			const page = err.code === 'InboxErrorUnsubscribeFailed' ? String((err.data && err.data.page) || '') : '';
			const byMail = err.code === 'InboxErrorUnsubscribeFailed' && m.unsubscribe.mailto && I.compose;
			if (byMail || /^https:\/\//i.test(page)) {
				const actions = [];
				if (/^https:\/\//i.test(page)) {
					actions.push({ label: t('InboxUnsubscribeOpenPage'), primary: !byMail, run: () => {
						window.open(page, '_blank', 'noopener,noreferrer');
					} });
				}
				if (byMail) {
					actions.push({ label: t('InboxUnsubscribePrepare'), primary: true, run: () => {
						I.compose({ mode: 'unsubscribe', mail: m.id });
					} });
				}
				const key = byMail && actions.length > 1 ? 'InboxUnsubscribeFallbackText' : (byMail ? 'InboxUnsubscribeFallbackMailText' : 'InboxUnsubscribeFallbackPageText');
				dialog({ title: t('InboxUnsubscribeTitle'), body: '<p>' + th(key, err.message) + '</p>', actions });
				return;
			}
			toast(err.message, { error: true });
			return;
		}
		const bar = btn.closest('.ibx-js-unsubbar');
		if (bar) {
			$('.ibx-banner__text', bar).innerHTML = th('InboxUnsubscribed', m.from.name || m.from.email);
			btn.remove();
		}
		toast(t('InboxUnsubscribed', m.from.name || m.from.email), canHandle(m) ? { action: t('InboxDelete'), onAction: () => doDelete([m.id]) } : {});
	}

	// ---------- Bandeau Dolibarr of the reading (lot W5 1.13.0, analyse-interface §6 bloc 3) ----------
	/**
	 * Column Dolibarr of a computer (1.110.0): a pane of the mail space beside the reading, full height, at the place
	 * of the agenda panel (same splitter, same drawer when narrow). The bandeau of the mail open is moved into it; on a
	 * tablet or a phone it stays in the mail (on top, sheet on a phone). The bandeau keeps its mail (_reading) and the
	 * place it came from (_home).
	 */
	function dolSide() {
		return S.el ? $('.ibx-js-dolside', S.el) : null;
	}
	/** The mail (or conversation) of a bandeau, in the mail or in the column */
	function readingOf(box) {
		if (!box) {
			return null;
		}
		return box.closest('.ibx-reading') || (box._reading && box._reading.isConnected ? box._reading : null);
	}
	/** The bandeau of what is under root (the reading pane, a mail, a conversation), in it or moved into the column */
	function dolibarIn(root) {
		if (!root) {
			return null;
		}
		const inside = $('.ibx-js-dolibar', root);
		if (inside) {
			return inside;
		}
		const side = dolSide();
		return side ? Array.from(side.children).find(b => b._reading && b._reading.isConnected && root.contains(b._reading)) || null : null;
	}
	/** Each bandeau where it belongs: in the column on a computer, back in its mail on a tablet or a phone */
	function seatDolibar() {
		const side = dolSide();
		const pane = readPane();
		if (!side || !pane) {
			return;
		}
		// The bandeau of a mail no longer shown leaves the column
		Array.from(side.children).forEach(b => {
			if (!b._reading || !b._reading.isConnected) {
				b.remove();
			}
		});
		if (!isPhone()) {
			$$('.ibx-js-dolibar', pane).forEach(b => {
				const home = document.createElement('span');
				home.className = 'ibx-dolibar-home';
				home.hidden = true;
				b.before(home);
				b._home = home;
				b._reading = b.closest('.ibx-reading');
				side.appendChild(b);
			});
		} else {
			Array.from(side.children).forEach(b => {
				if (b._home && b._home.isConnected) {
					b._home.replaceWith(b);
				} else {
					b.remove();
				}
			});
		}
	}
	function dolibarSlot(mailId, thread) {
		return '<section class="ibx-dolibar ibx-js-dolibar" data-mail="' + mailId + '" data-thread="' + (thread ? 1 : 0) + '" aria-label="' + th('InboxDolibarr') + '"></section>';
	}
	/**
	 * Where the bandeau goes (1.109.0): on a computer, a column on the right of the mail when the reading has room
	 * (« right »), else a drawer over the reading (« drawer »), both chosen in the strip on the right; on a tablet or
	 * a phone, on top of the mail (and its sheet on a phone)
	 * @returns {string} right, drawer or top
	 */
	function dolibarMode() {
		if (!S.el || isPhone()) {
			return 'top';
		}
		return sideNarrow() ? 'drawer' : 'right';
	}
	/** The bandeau is a column (beside the mail or in its drawer), not a band on top */
	function dolibarRight() {
		return dolibarMode() !== 'top';
	}
	function placeDolibar() {
		const pane = S.el && $('.ibx-pane--read', S.el);
		if (!pane) {
			return;
		}
		const mode = dolibarMode();
		pane.dataset.dolibar = mode === 'top' ? 'top' : 'side';
		seatDolibar();
		if (pane._dolMode !== mode) {
			pane._dolMode = mode;
			const b = dolibarIn(readPane());
			if (b && b._data) {
				paintDolibar(b);
			}
		}
	}
	// ---------- Strip on the right (1.109.0): one column at a time beside the mail, Dolibarr or the agenda ----------
	/**
	 * Narrow: the reading would keep less than 40 rem beside a column of 20 rem. Measured as if no column of the
	 * application were docked (the agenda docked gives its width back), so that opening one never changes the answer
	 */
	/** The grid of the mail space: its data-side, data-narrow and data-agenda are read by the CSS */
	function sideApp() {
		return (S.el && $('.ibx-mailapp', S.el)) || S.el;
	}
	function sideNarrow() {
		const pane = S.el && $('.ibx-pane--read', S.el);
		if (!pane || isPhone()) {
			return false;
		}
		const app = sideApp();
		const docked = app.dataset.agenda === 'open' ? $('.ibx-pane--agenda', S.el) : (app.dataset.dolside === 'open' ? dolSide() : null);
		const width = pane.clientWidth + (docked ? docked.getBoundingClientRect().width + 5 : 0);
		const rem = parseFloat(getComputedStyle(document.documentElement).fontSize) || 16;
		return width / rem < 60;
	}
	function agendaOn() {
		return !!(I.agenda && I.agenda.enabled());
	}
	/** Choice kept for a mail open: the last column chosen (the agenda pinned before 1.109.0 stays the agenda) */
	function sideChoice() {
		const c = params.side_panel;
		if (['dolibarr', 'agenda', 'none'].includes(c)) {
			return c;
		}
		return params.agenda_mode === 'pinned' ? 'agenda' : 'dolibarr';
	}
	/**
	 * Column shown now: '' (none), dolibarr or agenda. Narrow: the drawer opened by hand only (never by itself, it
	 * would hide the mail). Else with a mail: the last choice; without a mail: the agenda unless closed by hand
	 */
	function sideCurrent() {
		if (!S.el || isPhone()) {
			return '';
		}
		let c;
		if (sideApp().dataset.narrow === '1') {
			c = S.sideDrawer || '';
		} else if (S.openId) {
			c = sideChoice();
		} else {
			c = params.agenda_mode === 'closed' ? '' : 'agenda';
		}
		if ((c === 'agenda' && !agendaOn()) || (c === 'dolibarr' && !S.openId) || c === 'none') {
			return '';
		}
		return c;
	}
	function applySide(reason) {
		if (!S.el) {
			return;
		}
		sideApp().dataset.narrow = sideNarrow() ? '1' : '0';
		const cur = sideCurrent();
		if (sideApp().dataset.side !== cur) {
			sideApp().dataset.side = cur;
		}
		placeDolibar();
		if (I.agenda && I.agenda.sync) {
			I.agenda.sync(reason || 'auto');
		}
		layoutDolside();
		paintStrip();
	}
	/** The column Dolibarr shown or not, docked or as a drawer; the splitter of the right column for the one docked */
	function layoutDolside() {
		const app = sideApp();
		const side = dolSide();
		if (!app || !side) {
			return;
		}
		const on = !isPhone() && app.dataset.side === 'dolibarr';
		side.hidden = !on;
		if (on) {
			app.dataset.dolside = app.dataset.narrow === '1' ? 'over' : 'open';
		} else {
			app.removeAttribute('data-dolside');
		}
		const split = $('.ibx-splitter--agenda', app);
		if (split) {
			split.hidden = !(app.dataset.dolside === 'open' || app.dataset.agenda === 'open');
		}
	}
	/**
	 * A click on an icon of the strip (or Shift+F9 for the agenda): its column, or none when it is already shown
	 * @param {string} what dolibarr or agenda
	 * @param {string} reason
	 */
	function toggleSide(what, reason) {
		if (!S.el || isPhone() || (what === 'dolibarr' && !S.openId) || (what === 'agenda' && !agendaOn())) {
			return;
		}
		const next = sideCurrent() === what ? '' : what;
		if (sideApp().dataset.narrow === '1') {
			S.sideDrawer = next;
		} else if (S.openId) {
			saveParam('side_panel', next || 'none');
		} else {
			saveParam('agenda_mode', next ? '' : 'closed');
		}
		applySide(reason || 'button');
		if (next) {
			const col = next === 'dolibarr' ? dolSide() : $('.ibx-pane--agenda', S.el);
			if (col && sideApp().dataset.narrow === '1') {
				col.setAttribute('tabindex', '-1');
				col.focus({ preventScroll: true });
			}
		}
	}
	function closeSide(reason) {
		const cur = sideCurrent();
		if (cur) {
			toggleSide(cur, reason);
		}
	}
	function stripHtml() {
		return '<nav class="ibx-sidestrip ibx-js-sidestrip" aria-label="' + th('InboxSideStrip') + '">'
			+ '<button type="button" class="ibx-sidestrip__btn ibx-js-sidedol" aria-pressed="false">' + ic('dolibarr') + '</button>'
			+ '<button type="button" class="ibx-sidestrip__btn ibx-js-sideagenda" aria-pressed="false" hidden>' + ic('today') + '<span class="ibx-sidestrip__day ibx-js-sideday" aria-hidden="true"></span></button>'
			+ '</nav>';
	}
	function paintStrip() {
		const strip = S.el && $('.ibx-js-sidestrip', S.el);
		if (!strip) {
			return;
		}
		const cur = sideApp().dataset.side || '';
		const dol = $('.ibx-js-sidedol', strip);
		const label = S.openId ? t('InboxDolibarr') : t('InboxSideDolibarrNoMail');
		dol.title = label;
		dol.setAttribute('aria-label', label);
		dol.setAttribute('aria-pressed', cur === 'dolibarr' ? 'true' : 'false');
		if (S.openId) {
			dol.removeAttribute('aria-disabled');
		} else {
			dol.setAttribute('aria-disabled', 'true');
		}
		const ag = $('.ibx-js-sideagenda', strip);
		ag.hidden = !agendaOn();
		ag.title = t('InboxAgendaPanel') + ' (Maj+F9)';
		ag.setAttribute('aria-label', t('InboxAgendaPanel'));
		ag.setAttribute('aria-pressed', cur === 'agenda' ? 'true' : 'false');
		$('.ibx-js-sideday', strip).textContent = String(new Date().getDate());
	}
	function bindStrip() {
		const strip = $('.ibx-js-sidestrip', S.el);
		if (!strip) {
			return;
		}
		$('.ibx-js-sidedol', strip).addEventListener('click', () => toggleSide('dolibarr'));
		$('.ibx-js-sideagenda', strip).addEventListener('click', () => toggleSide('agenda'));
		// A drawer closes by a click beside it (menus, dialogs and windows opened from it are not « beside »)
		document.addEventListener('pointerdown', e => {
			if (!S.el || sideApp().dataset.narrow !== '1' || !S.sideDrawer || !e.target.closest) {
				return;
			}
			if (e.target.closest('.ibx-dolibar, .ibx-pane--dolside, .ibx-pane--agenda, .ibx-sidestrip, .ibx-dolchips, .ibx-menu, dialog, .ibx-toast, .ibx-window, .ibx-backdrop, .ibx-popover')) {
				return;
			}
			closeSide('outside');
		});
		// Escape closes a drawer first (before the other uses of Escape), not when a dialog is open over it
		window.addEventListener('keydown', e => {
			if (e.key !== 'Escape' || !S.el || S.el.hidden || sideApp().dataset.narrow !== '1' || !S.sideDrawer || $('dialog[open]:not(.ibx-window)')) {
				return;
			}
			e.stopPropagation();
			e.preventDefault();
			closeSide('key');
		}, true);
		let frame = 0;
		const later = () => {
			cancelAnimationFrame(frame);
			frame = requestAnimationFrame(() => applySide('resize'));
		};
		window.addEventListener('resize', later);
		if (window.ResizeObserver) {
			new ResizeObserver(later).observe($('.ibx-pane--read', S.el));
		}
		applySide('init');
	}
	I.side = { current: sideCurrent, narrow: () => !!S.el && sideApp().dataset.narrow === '1', toggle: toggleSide, close: closeSide, apply: applySide };

	// ---------- Phone (1.102.0): the Dolibarr part as chips under the head, details in a bottom sheet ----------
	/** Slot of the chips in the head of a mail or of a conversation (filled by the panel once loaded) */
	function dolChipsSlot() {
		return '<div class="ibx-dolchips ibx-js-dolchips" role="group" aria-label="' + th('InboxDolibarr') + '" hidden></div>';
	}
	/**
	 * Chips of the panel: the thirdparty (green dot: known), or « New customer » when the sender is unknown; the
	 * requester of a data source; the pieces and the notes with their counts. Each one opens the sheet on its section.
	 * @param {HTMLElement} box   Panel
	 * @param {boolean}     sheet Phone
	 * @param {object}      x     {soc, canLead, canWrite, rq, pieces, notes}
	 */
	function dolChips(box, sheet, x) {
		const reading = readingOf(box);
		const slot = reading && $('.ibx-js-dolchips', reading);
		if (!slot) {
			return;
		}
		// A narrow computer (1.109.0): the same line under the title, it opens the drawer Dolibarr
		const drawer = !sheet && dolibarMode() === 'drawer';
		if (!sheet && !drawer) {
			slot.hidden = true;
			slot.innerHTML = '';
			return;
		}
		const chip = (sec, icon, label, opts) => '<button type="button" class="ibx-dolchip' + (opts && opts.cls ? ' ' + opts.cls : '') + '" data-sec="' + sec + '"'
			+ (opts && opts.aria ? ' aria-label="' + esc(opts.aria) + '"' : '') + '>' + ic(icon) + (label ? '<span class="ibx-dolchip__label">' + esc(label) + '</span>' : '')
			+ (opts && opts.badge !== undefined ? '<span class="ibx-dolchip__badge">' + esc(String(opts.badge)) + '</span>' : '') + (opts && opts.dot ? '<span class="ibx-dolchip__dot" aria-hidden="true"></span>' : '') + '</button>';
		let html = '';
		if (x.rq) {
			html += chip('sender', 'redirect', x.rq.name || x.rq.email || '', { cls: 'is-wide' });
		} else if (x.soc) {
			html += chip('sender', 'company', x.soc.ref || '', { cls: 'is-wide', dot: true });
		} else if (x.contact) {
			// A known contact without thirdparty (1.109.0): its name, as in the panel, not « New customer »
			html += chip('sender', 'contact-card', x.contact.label || x.contact.ref || '', { cls: 'is-wide', dot: true });
		} else if (x.user) {
			// A colleague (a user of Dolibarr), never « unknown »
			html += chip('sender', 'account', x.user.name || '', { cls: 'is-wide' });
		} else if (x.canLead) {
			html += chip('sender', 'user-plus', t('InboxLeadOpen'), { cls: 'is-wide is-accent' });
		} else {
			html += chip('sender', 'contact-card', t('InboxSenderUnknown'), { cls: 'is-wide is-muted' });
		}
		if (x.pieces || x.canWrite) {
			html += chip('pieces', 'piece', '', { badge: x.pieces || '+', aria: x.pieces ? t('InboxPiecesCount', x.pieces) : t('InboxPiecesLinked') });
		}
		if (x.notes || x.canWrite) {
			html += chip('notes', 'note', '', { badge: x.notes || '+', aria: x.notes ? t('InboxNotesCount', x.notes) : t('InboxNotes') });
		}
		slot.innerHTML = html;
		slot.hidden = false;
		slot.classList.toggle('is-line', drawer);
		$$('.ibx-dolchip', slot).forEach(b => b.addEventListener('click', () => {
			if (!drawer) {
				openDolSheet(box, b.dataset.sec, b);
				return;
			}
			if (sideCurrent() !== 'dolibarr') {
				toggleSide('dolibarr');
			}
			const row = $('[data-sec="' + b.dataset.sec + '"]', box);
			if (row) {
				row.scrollIntoView({ block: 'start' });
			}
		}));
	}
	/**
	 * The sheet open on a section, over a veil; closed by the veil, Escape, its button or a swipe down on its head
	 * @param {HTMLElement}      box  Panel
	 * @param {string}           sec  Section (sender, pieces, notes)
	 * @param {HTMLElement|null} from Chip to focus again once closed
	 */
	function openDolSheet(box, sec, from) {
		$$('.ibx-sheetback', S.el).forEach(v => v.remove());
		const veil = document.createElement('div');
		veil.className = 'ibx-sheetback';
		veil.addEventListener('click', () => closeDolSheet(box));
		box.before(veil);
		box._sheetFrom = from || null;
		box.setAttribute('role', 'dialog');
		box.setAttribute('aria-modal', 'true');
		box.classList.add('is-anim');
		box.classList.add('is-open');
		const body = $('.ibx-dolibar__body', box);
		const row = $('[data-sec="' + sec + '"]', box);
		if (body && row) {
			body.scrollTop = Math.max(0, row.offsetTop - body.offsetTop - 8);
		}
		const close = $('.ibx-js-dolsheetclose', box);
		if (close) {
			close.focus({ preventScroll: true });
		}
		if (!box._sheetKeys) {
			box._sheetKeys = true;
			box.addEventListener('keydown', e => {
				if (e.key === 'Escape' && box.classList.contains('is-open')) {
					e.stopPropagation();
					closeDolSheet(box);
				}
			});
			// Swipe down on the head: follows the finger, closes past 80 px
			box.addEventListener('pointerdown', e => {
				const head = e.target.closest('.ibx-js-dolsheethead');
				if (!head || e.target.closest('button') || !box.classList.contains('is-open')) {
					return;
				}
				const y0 = e.clientY;
				let dy = 0;
				box.style.transition = 'none';
				const move = ev => {
					dy = Math.max(0, ev.clientY - y0);
					box.style.transform = 'translateY(' + dy + 'px)';
				};
				const up = () => {
					window.removeEventListener('pointermove', move);
					window.removeEventListener('pointerup', up);
					box.style.transition = '';
					box.style.transform = '';
					if (dy > 80) {
						closeDolSheet(box);
					}
				};
				window.addEventListener('pointermove', move);
				window.addEventListener('pointerup', up);
			});
		}
	}
	/**
	 * A part of the page shown as a sheet rising from the bottom (phone): over a veil, closed by the veil, Escape, its
	 * close button or a swipe down on its head, the focus back on the button that opened it
	 * @param {HTMLElement} el        Part
	 * @param {HTMLElement} from      Button that opened it
	 * @param {string}      headSel   Its head (swipe)
	 * @param {string}      closeSel  Its close button (focused)
	 */
	function openSheet(el, from, headSel, closeSel) {
		$$('.ibx-sheetback', S.el).forEach(v => v.remove());
		const veil = document.createElement('div');
		veil.className = 'ibx-sheetback';
		veil.addEventListener('click', () => closeSheet(el));
		el.before(veil);
		el._sheetFrom = from || null;
		el.setAttribute('role', 'dialog');
		el.setAttribute('aria-modal', 'true');
		el.classList.add('is-sheet', 'is-anim', 'is-open');
		const close = $(closeSel, el);
		if (close) {
			close.focus({ preventScroll: true });
		}
		if (el._sheetBound) {
			return;
		}
		el._sheetBound = true;
		el.addEventListener('keydown', e => {
			if (e.key === 'Escape' && el.classList.contains('is-open')) {
				e.stopPropagation();
				closeSheet(el);
			}
		});
		el.addEventListener('pointerdown', e => {
			if (!e.target.closest(headSel) || e.target.closest('button') || !el.classList.contains('is-open')) {
				return;
			}
			const y0 = e.clientY;
			let dy = 0;
			el.style.transition = 'none';
			const move = ev => {
				dy = Math.max(0, ev.clientY - y0);
				el.style.transform = 'translateY(' + dy + 'px)';
			};
			const up = () => {
				window.removeEventListener('pointermove', move);
				window.removeEventListener('pointerup', up);
				el.style.transition = '';
				el.style.transform = '';
				if (dy > 80) {
					closeSheet(el);
				}
			};
			window.addEventListener('pointermove', move);
			window.addEventListener('pointerup', up);
		});
	}
	function closeSheet(el) {
		el.classList.remove('is-open');
		el.removeAttribute('role');
		el.removeAttribute('aria-modal');
		$$('.ibx-sheetback', S.el).forEach(v => v.remove());
		if (el._sheetFrom && el._sheetFrom.isConnected) {
			el._sheetFrom.focus({ preventScroll: true });
		}
		el._sheetFrom = null;
	}
	function closeDolSheet(box) {
		box.classList.remove('is-open');
		box.removeAttribute('role');
		box.removeAttribute('aria-modal');
		$$('.ibx-sheetback', S.el).forEach(v => v.remove());
		if (box._sheetFrom && box._sheetFrom.isConnected) {
			box._sheetFrom.focus({ preventScroll: true });
		}
		box._sheetFrom = null;
	}
	// The phone width crossed (turned, resized): the panels painted again, as a sheet or in place
	window.matchMedia('(max-width: 39.9375rem)').addEventListener('change', () => {
		if (S.el) {
			$$('.ibx-js-dolibar', S.el).forEach(b => {
				if (b._data) {
					paintDolibar(b);
				}
			});
		}
	});
	async function loadDolibar(box) {
		if (!box) {
			return;
		}
		// Mail of a protected mailbox (1.89.0): nothing linked to Dolibarr, said once in place of the panel
		if (+box.dataset.mail < 0) {
			box.innerHTML = '<div class="ibx-dolibar__head ibx-muted">' + ic('lock') + ' ' + th('InboxProtectReadingNote') + '</div>';
			return;
		}
		// Phone: a sheet from the start, hidden while it loads (it never shows in place to slide away after)
		box.classList.toggle('is-sheet', isSmallPhone());
		box.innerHTML = '<div class="ibx-dolibar__head"><span class="ibx-skeleton" style="width:40%"></span></div>';
		let res;
		try {
			res = await api('dolibarr', 'panel', { id: +box.dataset.mail, thread: +box.dataset.thread });
		} catch (err) {
			if (box.isConnected) {
				// The error in place, a hidden sheet would keep it unseen
				box.classList.remove('is-sheet');
				box.innerHTML = '<div class="ibx-dolibar__head ibx-muted">' + ic('warning') + ' ' + esc(err.message) + ' <button type="button" class="ibx-btn ibx-btn--sm ibx-js-dolretry">' + th('InboxRetry') + '</button></div>';
				$('.ibx-js-dolretry', box).addEventListener('click', () => loadDolibar(box));
			}
			return;
		}
		if (!box.isConnected) {
			return;
		}
		box._data = res;
		box._situation = null;
		box._phones = [];
		paintDolibar(box);
		if (!box._bound) {
			box._bound = true;
			box.addEventListener('click', e => dolibarClick(box, e));
		}
		loadSituation(box);
		loadCited(box);
		loadPanels(box);
	}
	/**
	 * Blocks of the other modules (1.47.0, hook inboxMailPanels): their summary is read after the panel, again while
	 * the module says it is still working (pending)
	 * @param {HTMLElement} box Panel
	 */
	function loadPanels(box) {
		const mail = box.dataset.mail;
		box._panels = box._panels && box._panelsMail === mail ? box._panels : {};
		box._panelsMail = mail;
		(box._data.panels || []).forEach(p => loadPanel(box, mail, p, 0));
	}
	async function loadPanel(box, mail, p, tries) {
		let data;
		try {
			const res = await fetch(p.url, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
			if (!res.ok) {
				throw new Error(res.statusText || String(res.status));
			}
			data = await res.json();
		} catch (err) {
			data = { error: err.message || 'Error' };
		}
		if (!box.isConnected || box.dataset.mail !== mail) {
			return;
		}
		box._panels[p.id] = data && typeof data === 'object' ? data : { error: 'Error' };
		const el = $('.ibx-mpanel[data-panel="' + CSS.escape(p.id) + '"] .ibx-mpanel__body', box);
		if (el) {
			el.innerHTML = panelBody(box._panels[p.id]);
		}
		if (data && data.pending && tries < 60) {
			setTimeout(() => loadPanel(box, mail, p, tries + 1), Math.min(30000, Math.max(2000, +data.retry_ms || 3000)));
		}
	}
	/** An address inside Dolibarr only (the module gives it) */
	function insideUrl(url) {
		// « /\host » is read as another host by the browsers (audit INBX-084)
		return typeof url === 'string' && url.charAt(0) === '/' && url.charAt(1) !== '/' && !/[\s<>"\\]/.test(url);
	}
	/** Summary of a block: badges, lines, one action (window), links; every text escaped */
	function panelBody(d) {
		if (!d) {
			return '<span class="ibx-muted"><span class="ibx-spinner" aria-hidden="true"></span> ' + th('InboxPanelLoading') + '</span>';
		}
		if (d.error) {
			return '<span class="ibx-muted">' + ic('warning') + ' ' + esc(d.error) + '</span>';
		}
		const levels = ['ok', 'warning', 'danger', 'info', 'neutral'];
		const badges = (Array.isArray(d.badges) ? d.badges : []).slice(0, 6).map(b => '<span class="ibx-mbadge ibx-mbadge--' + (levels.includes(b.level) ? b.level : 'neutral') + '">' + esc(b.label) + '</span>').join('');
		const lines = (Array.isArray(d.lines) ? d.lines : []).slice(0, 4).map(l => '<p class="ibx-mpanel__line">' + esc(l) + '</p>').join('');
		const a = d.action && insideUrl(d.action.url) ? d.action : null;
		const links = (Array.isArray(d.links) ? d.links : []).filter(l => insideUrl(l.url)).slice(0, 3)
			.map(l => '<a class="ibx-linkbtn" href="' + esc(l.url) + '" target="_blank" rel="noopener">' + esc(l.label) + '</a>').join(' ');
		return (d.pending ? '<p class="ibx-mpanel__line"><span class="ibx-spinner" aria-hidden="true"></span> ' + esc(d.pending_text || t('InboxPanelLoading')) + '</p>' : '')
			+ (badges ? '<div class="ibx-mpanel__badges">' + badges + '</div>' : '') + lines
			+ (a ? '<button type="button" class="ibx-btn ibx-btn--sm' + (a.primary === false ? '' : ' ibx-btn--primary') + ' ibx-js-mpanelopen" data-url="' + esc(a.url) + '" data-title="' + esc(a.title || a.label) + '">' + esc(a.label) + '</button>' : '')
			+ (links ? '<div class="ibx-mpanel__links">' + links + '</div>' : '');
	}
	/**
	 * Pieces cited in the mail (1.46.0), asked after the panel: the body is read on the server. The ones of another
	 * thirdparty are never suggested here, only in « Link » with a warning.
	 * @param {HTMLElement} box Panel
	 */
	async function loadCited(box) {
		const mail = box.dataset.mail;
		let res;
		try {
			res = await api('dolibarr', 'cited', { id: +mail });
		} catch (err) {
			return;
		}
		if (!box.isConnected || box.dataset.mail !== mail || !box._data) {
			return;
		}
		box._cited = res.cited || [];
		// Phones of the signature not on the cards (1.69.0)
		box._phones = res.phones || [];
		box._phoneEdit = !!res.phone_edit;
		if (box._cited.length || box._phones.length) {
			paintDolibar(box);
		}
	}
	/** Cited piece of another thirdparty, or of a writer not known: why it may not be the right one */
	function citedWarning(box, x) {
		const d = box._data;
		const writer = d && d.sender.soc && d.sender.soc.readable ? d.sender.soc.ref : '';
		if (x.match === 'other') {
			return writer ? t('InboxCitedOtherSoc', x.label || '?', writer) : t('InboxCitedOtherSocNoName', x.label || '?');
		}
		return x.match === 'unknown' ? t('InboxCitedUnknownSoc') : '';
	}
	function citedWarnHtml(text) {
		return text ? ' <span class="ibx-cited__warn" title="' + esc(text) + '">' + ic('warning') + '<span class="ibx-sr-only">' + esc(text) + '</span></span>' : '';
	}
	/**
	 * Status of a piece as the badge of Dolibarr (1.46.0): its short label, the colour of its kind (status0 to status9),
	 * the long label in the title; a plain grey label when the kind is unknown
	 * @param {object} d Piece {status, status_short, status_type}
	 * @returns {string} html
	 */
	function statusBadge(d) {
		const label = d.status_short || d.status;
		if (!label) {
			return '';
		}
		const type = Number.isInteger(d.status_type) && d.status_type >= 0 && d.status_type <= 9 ? d.status_type : -1;
		return ' <span class="ibx-st' + (type >= 0 ? ' ibx-st--' + type : '') + '"' + (d.status && d.status !== label ? ' title="' + esc(d.status) + '"' : '') + '>' + esc(label) + '</span>';
	}
	/**
	 * Expected status of a sender card (open thirdparty, active contact, 1.88.7): a discreet dot beside the name, its
	 * label in the title and for the screen readers; any other status (closed, inactive) keeps its badge, to be seen
	 * @param {object} d Thirdparty or contact {status, status_short, status_type, status_normal}
	 * @returns {string} html
	 */
	function senderDot(d) {
		if (!d.status_normal) {
			return '';
		}
		const label = d.status || d.status_short || '';
		return '<span class="ibx-stdot"' + (label ? ' title="' + esc(label) + '"' : '') + ' aria-hidden="true"></span>' + (label ? '<span class="ibx-sr-only">' + esc(label) + '</span>' : '');
	}
	/**
	 * Second line of a piece: its thirdparty, left out when it is the one of the sender (the title of a project or ticket
	 * stays); a piece of another thirdparty stands out by it
	 * @param {object} d     Piece {label, socid, title}
	 * @param {number} socId Thirdparty of the sender, 0 if none
	 * @returns {string} text
	 */
	function pieceSub(d, socId) {
		if (socId > 0 && d.socid === socId) {
			return d.title || '';
		}
		return d.label || '';
	}
	/**
	 * A piece as a pill: icon, ref (link to its card), thirdparty, status, unlink; « not accessible » without anything
	 * else. In the column on the right a card of two lines at most: ref, status and warning on the first, the
	 * thirdparty, « their ref » and the action on the second (no line for a lonely button)
	 * @param {object} d      Piece
	 * @param {number} unlink Link to remove (0 = no unlink button)
	 * @param {string} warn   Warning of a cited piece ('' = none)
	 * @param {number} socId  Thirdparty of the sender (left out of the second line)
	 * @param {object} [more] {theirs: text of the second line, action: html of a button}
	 * @returns {string} html
	 */
	function pieceHtml(d, unlink, warn, socId, more) {
		more = more || {};
		const attrs = d.readable && d.type ? ' data-type="' + esc(d.type) + '" data-id="' + d.id + '"' : '';
		if (!d.readable) {
			return '<span class="ibx-piece"' + attrs + '><span class="ibx-piece__link ibx-muted">' + ic(d.icon) + esc(d.kind) + ' · ' + th('InboxPieceNotReadable') + '</span></span>';
		}
		const sub = [pieceSub(d, socId || 0), more.theirs || ''].filter(Boolean).join(' · ');
		const foot = sub || more.action
			? '<span class="ibx-piece__foot">' + (sub ? '<span class="ibx-muted ibx-piece__sub" title="' + esc(sub) + '">' + esc(sub) + '</span>' : '') + (more.action || '') + '</span>'
			: '';
		// Tooltip of Dolibarr in a card of the application (objTip) when getNomUrl gives one; else a plain title
		const tip = objTipKey(d);
		return '<span class="ibx-piece"' + attrs + '>'
			+ '<a class="ibx-piece__link" href="' + esc(d.url || '#') + '" target="_blank" rel="noopener"'
			+ (tip ? ' data-objtip="' + esc(tip) + '"' : ' title="' + esc(d.kind + ' ' + (d.ref || '') + (d.label ? ' · ' + d.label : '') + (d.status ? ' · ' + d.status : '')) + '"') + '>'
			+ ic(d.icon) + '<b class="ibx-piece__ref">' + esc(d.ref || '') + '</b></a>'
			+ statusBadge(d) + citedWarnHtml(warn || '')
			+ (unlink ? '<button type="button" class="ibx-piece__x ibx-js-unlink" data-link="' + unlink + '" title="' + th('InboxUnlink') + '" aria-label="' + esc(t('InboxUnlinkPiece', d.ref || d.kind)) + '">' + ic('close') + '</button>' : '')
			+ foot + '</span>';
	}
	function linkify(text) {
		return esc(text).replace(/(https?:\/\/[^\s<]+)/g, '<a href="$1" target="_blank" rel="noopener noreferrer">$1</a>').replace(/\n/g, '<br>');
	}
	/**
	 * Alerts of the other modules (an imitation...) go with the banners of the mail, above its body, never in the
	 * column: the red one first
	 */
	function showDolAlerts(box, alerts) {
		const reading = readingOf(box);
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
	// ---------- Card of the thirdparty and of the contact (usage of the mailbox, 1.67.0) ----------
	/**
	 * Situation of the thirdparty by the usage of the mailbox, asked after the panel (nothing shown while it comes: the
	 * line of the signal may not exist)
	 * @param {HTMLElement} box Panel
	 */
	async function loadSituation(box) {
		const d = box._data;
		const soc = d && d.sender.soc && d.sender.soc.readable ? d.sender.soc : null;
		box._situation = null;
		if (!soc || d.usage === 'none') {
			return;
		}
		const mail = box.dataset.mail;
		let res;
		try {
			res = await api('dolibarr', 'situation', { id: +mail, soc: soc.id });
		} catch (err) {
			box._situation = { error: err.message, indicators: [] };
			return;
		}
		if (!box.isConnected || box.dataset.mail !== mail || box._data !== d) {
			return;
		}
		box._situation = res;
		paintDolibar(box);
	}
	/** Signals of the closed card: the levels above normal, alerts first then in the order of the list */
	function socSignals(box) {
		const s = box._situation;
		const list = s && !s.error ? s.indicators.filter(x => x.level !== 'normal') : [];
		return list.map((x, i) => ({ x, i })).sort((a, b) => (a.x.level === 'alert' ? 0 : 1) - (b.x.level === 'alert' ? 0 : 1) || a.i - b.i).map(o => o.x);
	}
	/**
	 * One way to reach: the value is the action (tel: link, new message, map); the copy icon floats after it on hover
	 * or keyboard focus of the line (always shown without hover)
	 */
	function reachHtml(icon, value, kind, extra, opts) {
		if (!value) {
			return '';
		}
		opts = opts || {};
		const href = kind === 'tel' ? 'tel:' + value.replace(/[^\d+]/g, '') : (kind === 'map' ? extra : '#');
		const title = t(kind === 'tel' ? 'InboxReachCall' : (kind === 'mail' ? 'InboxReachWrite' : 'InboxReachMap'), value);
		return '<div class="ibx-reach">' + ic(icon)
			+ '<span class="ibx-reach__wrap"><a class="ibx-reach__val' + (kind === 'mail' ? ' ibx-js-reachwrite' : '') + (opts.diff ? ' is-diff' : '') + '" href="' + esc(href) + '"'
			+ (kind === 'map' ? ' target="_blank" rel="noopener noreferrer"' : '') + ' data-val="' + esc(value) + '" title="' + esc(title) + '">' + esc(value + (opts.note ? ' ' + opts.note : '')) + '</a>'
			+ (kind !== 'map' ? '<button type="button" class="ibx-reach__copy ibx-js-reachcopy" data-val="' + esc(value) + '" aria-label="' + esc(t('InboxCopyValue', value)) + '" title="' + th('InboxCopy') + '">' + ic('copy') + '</button>' : '')
			+ '</span>' + (opts.action || '') + '</div>';
	}
	/** Customer (C), prospect (P), supplier (F) of a thirdparty, as in Dolibarr */
	function socKinds(r) {
		const out = [];
		if (r.client === 1 || r.client === 3) {
			out.push('<span class="ibx-soccard__kind ibx-soccard__kind--c" title="' + th('InboxKindCustomer') + '">' + th('InboxKindCustomerShort') + '</span>');
		}
		if (r.client === 2 || r.client === 3) {
			out.push('<span class="ibx-soccard__kind ibx-soccard__kind--p" title="' + th('InboxKindProspect') + '">' + th('InboxKindProspectShort') + '</span>');
		}
		if (r.supplier) {
			out.push('<span class="ibx-soccard__kind ibx-soccard__kind--f" title="' + th('InboxKindSupplier') + '">' + th('InboxKindSupplierShort') + '</span>');
		}
		return out.join('');
	}
	/**
	 * Card of the thirdparty (card = 'soc') or of the contact ('contact'): its name is the link to its card in Dolibarr
	 * (zone 2), the rest of its head folds and unfolds the detail (zone 1, the chevron for the keyboard); open, the whole
	 * card floats over the panel at its place (placeSocCard)
	 */
	function socCardHtml(box, x, card) {
		const d = box._data;
		const open = box._open === card;
		const socId = d.sender.soc && d.sender.soc.readable ? d.sender.soc.id : 0;
		const sub = card === 'contact' ? pieceSub(x, socId) : '';
		const sig = card === 'soc' ? socSignals(box)
			: (box._phones || []).length ? [{ level: 'attention', short: t((box._phones || []).length > 1 ? 'InboxSigPhonesN' : 'InboxSigPhones1') }] : [];
		const first = sig[0];
		const tip = objTipKey(x);
		const r = x.reach || {};
		let html = '<div class="ibx-soccard-slot"><div class="ibx-soccard' + (open ? ' is-open' : '') + '" data-card="' + card + '">'
			+ '<div class="ibx-soccard__top ibx-js-soczone" data-card="' + card + '" title="' + th(open ? 'InboxCardFold' : 'InboxCardUnfold') + '">'
			+ '<div class="ibx-soccard__head">' + ic(x.icon)
			+ '<span class="ibx-soccard__namecell"><a class="ibx-soccard__name" href="' + esc(x.url || '#') + '" target="_blank" rel="noopener"' + (tip ? ' data-objtip="' + esc(tip) + '"' : ' title="' + esc(x.kind + ' ' + (x.ref || '')) + '"') + '>' + esc(x.ref || '') + '</a>'
			+ senderDot(x) + (sub ? '<small>' + esc(sub) + '</small>' : '') + '</span>' + (x.status_normal ? '' : statusBadge(x))
			+ (first ? '<span class="ibx-soccard__sig ibx-soccard__sig--' + first.level + '">' + ic(first.level === 'alert' ? 'warning' : 'info') + '<span>' + esc(first.short) + '</span>'
				+ (sig.length > 1 ? '<span class="ibx-soccard__more" title="' + esc(sig.slice(1).map(s => s.short).join(' · ')) + '">+' + (sig.length - 1) + '</span>' : '') + '</span>' : '')
			+ '</div>'
			+ '<button type="button" class="ibx-soccard__chevbtn ibx-js-socfold" data-card="' + card + '" aria-expanded="' + open + '" aria-label="' + esc(t(open ? 'InboxCardFoldOf' : 'InboxCardUnfoldOf', x.ref || '')) + '">' + ic('chevron-down') + '</button>'
			+ '</div>';
		if (open) {
			html += '<div class="ibx-soccard__body">'
				+ '<div class="ibx-soccard__group">' + th('InboxReach') + (card === 'soc' ? ' ' + socKinds(r) : '') + '</div>'
				+ (r.phone || r.mobile || r.email || r.address
					? reachHtml('phone', r.phone, 'tel') + reachHtml('phone', r.mobile, 'tel') + reachHtml('mail', r.email, 'mail') + reachHtml('map-pin', r.address, 'map', r.map)
					: '<div class="ibx-soccard__none">' + th('InboxReachNone') + '</div>')
				// Phones of the signature not on the card (1.69.0): call, copy, replace the number of the card
				+ (card === 'contact' ? (box._phones || []).map(ph => reachHtml('phone', ph.number, 'tel', '', { diff: true, note: t('InboxSignatureNote'),
					action: box._phoneEdit ? '<button type="button" class="ibx-btn ibx-btn--ghost ibx-btn--icon ibx-btn--sm ibx-reach__act ibx-js-phoneset" data-number="' + esc(ph.number) + '" data-mobile="' + (ph.mobile ? 1 : 0) + '"'
						+ ' aria-label="' + esc(t('InboxPhoneReplace', ph.number)) + '" title="' + th('InboxPhoneReplaceShort') + '">' + ic('compose') + '</button>' : '' })).join('') : '');
			if (card === 'soc' && d.usage !== 'none') {
				const s = box._situation;
				const shown = s && s.usage === 'auto' ? t('InboxUsageAutoShown', (s.shown || []).map(u => t('InboxUsage_' + u)).join(' + ') || t('InboxUsage_none'))
					: t('InboxUsage_' + (s ? s.usage : d.usage));
				html += '<div class="ibx-soccard__group">' + th('InboxSituation') + ' <small>· ' + esc(t('InboxUsageOf', shown)) + '</small></div>';
				if (!s) {
					html += '<div class="ibx-soccard__none"><span class="ibx-spinner" aria-hidden="true"></span> ' + th('InboxPanelLoading') + '</div>';
				} else if (s.carrier) {
					html += '<div class="ibx-soccard__none">' + th('InboxSituationCarrier') + '</div>';
				} else if (s.error) {
					html += '<div class="ibx-soccard__none">' + ic('warning') + ' ' + esc(s.error) + '</div>';
				} else if (!s.indicators.length) {
					html += '<div class="ibx-soccard__none">' + th('InboxSituationNone') + '</div>';
				} else {
					html += s.indicators.map(i => '<a class="ibx-ind ibx-ind--' + i.level + '" href="' + esc(i.url) + '" target="_blank" rel="noopener" title="' + esc(t('InboxOpenList') + (i.detail ? ' · ' + i.detail : '')) + '">'
						+ '<span class="ibx-ind__label">' + esc(i.label) + '</span>' + (i.level === 'alert' ? '<span class="ibx-ind__lvl">' + ic('warning') + '<span class="ibx-sr-only">' + th('InboxAlert') + '</span></span>' : '')
						+ '<span class="ibx-ind__val">' + esc(i.value) + '</span>' + ic('external') + '</a>').join('');
				}
			}
			if (card === 'soc' && d.level) {
				html += '<div class="ibx-soccard__foot"><button type="button" class="ibx-btn ibx-btn--sm ibx-btn--ghost ibx-js-resoc">' + ic('arrows-exchange') + ' ' + th('InboxNotTheRightSoc') + '</button></div>';
			}
			html += '</div>';
		}
		return html + '</div></div>';
	}
	/**
	 * Open card: floats exactly over its slot (the slot keeps the height of the closed card), 20rem wide at least
	 * (grows to the left near the right edge); only its detail scrolls when the window is short
	 */
	function placeSocCard(box) {
		const card = $('.ibx-soccard.is-open', box);
		if (!card) {
			return;
		}
		const slot = card.parentElement;
		slot.style.height = ($('.ibx-soccard__top', card).offsetHeight + 2) + 'px';
		const r = slot.getBoundingClientRect();
		const w = Math.min(Math.max(r.width, 320), window.innerWidth - 16);
		card.style.width = w + 'px';
		card.style.left = Math.max(8, Math.min(r.left, window.innerWidth - w - 8)) + 'px';
		card.style.top = r.top + 'px';
		card.style.maxHeight = Math.max(160, window.innerHeight - r.top - 8) + 'px';
	}
	/** Open or close a card (one open at a time); focus back on its chevron when asked */
	function socCardToggle(box, card, focus) {
		box._open = box._open === card ? '' : card;
		paintDolibar(box);
		if (focus) {
			const b = $('.ibx-js-socfold[data-card="' + card + '"]', box);
			if (b) {
				b.focus();
			}
		}
	}
	function socCardCloseAll(focus) {
		$$('.ibx-js-dolibar').forEach(box => {
			if (box._open) {
				const card = box._open;
				box._open = '';
				paintDolibar(box);
				const b = focus && $('.ibx-js-socfold[data-card="' + card + '"]', box);
				if (b) {
					b.focus();
				}
			}
		});
	}
	// An open card closes on a click elsewhere, Escape, a scroll of the reading; follows the window when it changes
	(() => {
		const anyOpen = () => $$('.ibx-js-dolibar').some(b => b._open);
		document.addEventListener('pointerdown', e => {
			if (anyOpen() && !e.target.closest('.ibx-soccard.is-open, dialog, .ibx-toasts, .ibx-objtip')) {
				socCardCloseAll(false);
			}
		});
		document.addEventListener('keydown', e => {
			if (e.key === 'Escape' && anyOpen() && !document.querySelector('dialog[open]')) {
				socCardCloseAll(true);
			}
		});
		window.addEventListener('scroll', e => {
			if (anyOpen() && !(e.target.closest && e.target.closest('.ibx-soccard__body'))) {
				socCardCloseAll(false);
			}
		}, true);
		window.addEventListener('resize', () => $$('.ibx-js-dolibar').forEach(b => b._open && placeSocCard(b)));
	})();
	/** Copy a value; the icon shows a check for a moment (read by the screen readers) */
	async function reachCopy(btn) {
		try {
			await navigator.clipboard.writeText(btn.dataset.val);
		} catch (err) {
			toast(t('InboxCopyFailed'), { error: true });
			return;
		}
		btn.classList.add('is-done');
		btn.innerHTML = ic('check');
		btn.setAttribute('aria-label', t('InboxCopied'));
		setTimeout(() => {
			btn.classList.remove('is-done');
			btn.innerHTML = ic('copy');
			btn.setAttribute('aria-label', t('InboxCopyValue', btn.dataset.val));
		}, 1400);
	}
	/**
	 * « Replace the number of the card » (1.69.0): which number of the contact (mobile chosen for a mobile number), the
	 * number of the card before and after; nothing written before « Replace »; undo in the toast
	 * @param {HTMLElement} box    Panel
	 * @param {string}      number Number of the signature
	 * @param {boolean}     mobile A mobile number
	 */
	function phoneDialog(box, number, mobile) {
		const d = box._data;
		const c = d.sender.contact;
		if (!c || !c.readable) {
			return;
		}
		const r = c.reach || {};
		const cur = field => (field === 'phone_mobile' ? r.mobile : r.phone) || '';
		const row = (field, label) => '<label class="ibx-radio ibx-radio--block"><input type="radio" name="ibx-phonefield" value="' + field + '"' + ((field === 'phone_mobile') === mobile ? ' checked' : '') + '>'
			+ '<span>' + th(label) + '<span class="ibx-formrow__help">' + (cur(field) ? esc(t('InboxPhoneNow', cur(field))) : th('InboxPhoneEmpty')) + '</span></span></label>';
		dialog({
			title: t('InboxPhoneReplaceTitle'),
			body: '<p style="margin-top:0">' + th('InboxPhoneFound', number) + '</p><div role="radiogroup" aria-label="' + th('InboxPhoneWhich') + '">'
				+ row('phone_pro', 'InboxPhonePro') + row('phone_mobile', 'InboxPhoneMobile') + '</div>',
			actions: [{ label: t('InboxPhoneReplaceDo'), primary: true, run: async dl => {
				const field = ($('input[name="ibx-phonefield"]:checked', dl) || {}).value || 'phone_pro';
				const res = await post('dolibarr', 'phone', { id: +box.dataset.mail, contact: c.id, field, number });
				loadDolibar(box);
				toast(t('InboxPhoneReplaced'), { action: t('InboxUndo'), onAction: async () => {
					try {
						await post('dolibarr', 'phone', { id: +box.dataset.mail, contact: c.id, field, number: res.before || '' });
						loadDolibar(box);
					} catch (err) {
						toast(err.message, { error: true });
					}
				} });
				return true;
			} }],
		});
	}
	/**
	 * The thirdparty shown on the rows of the list after « Not the right thirdparty? »: the mail and its conversation,
	 * the other mails of the address when it was remembered (as the server did)
	 */
	function resocRows(mailId, soc, email) {
		const row = S.rows.find(r => r.id === mailId);
		const key = row && row.thread ? row.thread.key : '';
		const addr = String(email || '').toLowerCase();
		S.rows.forEach(r => {
			if (r.id === mailId || (key && r.thread && r.thread.key === key && r.account === row.account) || (addr && String(r.from_email || '').toLowerCase() === addr)) {
				r.soc = { id: soc.id, name: soc.ref };
			}
		});
		renderList();
	}
	/**
	 * « Not the right thirdparty? »: search, choice, nothing written before « Attach »; the conversation only, or the
	 * next mails of the address too
	 * @param {HTMLElement} box Panel
	 */
	function resocDialog(box) {
		const d = box._data;
		const before = d.sender.soc && d.sender.soc.readable ? d.sender.soc : null;
		// The address of the other side (1.92.2): the recipient of a mail written by us, none between us
		const email = String(d.from !== undefined ? d.from : ((box._mail && box._mail.from_email) || ''));
		let chosen = null;
		const dlg = dialog({
			title: t(before ? 'InboxResocTitle' : 'InboxResocTitleNone'),
			body: (before ? '<p class="ibx-formrow__help" style="margin-top:0">' + th('InboxResocNow') + ' <b>' + esc(before.ref) + '</b></p>' : '')
				+ '<input type="search" class="ibx-field ibx-js-resocq" autocomplete="off" placeholder="' + th('InboxResocSearch') + '" aria-label="' + th('InboxResocSearch') + '">'
				+ '<div class="ibx-picklist ibx-js-resoclist" role="listbox" aria-label="' + th('InboxThirdparties') + '"></div>'
				// Nothing to remember for a mail between us (1.92.2)
				+ (email || d.from === undefined ? '<label class="ibx-check" style="margin-top:var(--ibx-s3)"><input type="checkbox" class="ibx-js-resocremember"> ' + (email ? th('InboxResocRemember', email) : th('InboxResocRememberAddress')) + '</label>' : '')
				+ '<p class="ibx-formrow__help">' + th('InboxResocHelp') + '</p>',
			actions: [{ label: t('InboxResocDo'), primary: true, run: async dl => {
				if (!chosen) {
					return false;
				}
				const remember = !!($('.ibx-js-resocremember', dl) || {}).checked;
				await post('dolibarr', 'resoc', { id: +box.dataset.mail, soc: chosen.id, remember: remember ? 1 : 0 });
				box._open = '';
				loadDolibar(box);
				resocRows(+box.dataset.mail, chosen, remember ? email : '');
				toast(t(remember ? 'InboxResocDoneRemember' : 'InboxResocDone', chosen.ref, email), before && !remember ? { action: t('InboxUndo'), onAction: async () => {
					try {
						await post('dolibarr', 'resoc', { id: +box.dataset.mail, soc: before.id, remember: 0 });
						loadDolibar(box);
						resocRows(+box.dataset.mail, before, '');
					} catch (err) {
						toast(err.message, { error: true });
					}
				} } : {});
				return true;
			} }],
		});
		const input = $('.ibx-js-resocq', dlg);
		const list = $('.ibx-js-resoclist', dlg);
		const ok = () => {
			const b = $('.ibx-dialog__foot .ibx-btn--primary', dlg);
			if (b) {
				b.setAttribute('aria-disabled', chosen ? 'false' : 'true');
			}
		};
		let found = [];
		const paint = () => {
			list.innerHTML = found.length ? found.map(x => '<div class="ibx-picklist__item" role="option" data-id="' + x.id + '" aria-selected="' + (chosen && chosen.id === x.id) + '"' + (before && before.id === x.id ? ' aria-disabled="true"' : '') + '>'
				+ ic(x.icon || 'company') + ' <span class="ibx-ellipsis"><b>' + esc(x.ref) + '</b>' + (x.label ? ' <span class="ibx-muted">' + esc(x.label) + '</span>' : '') + '</span>' + statusBadge(x) + '</div>').join('')
				: '<div class="ibx-muted ibx-picklist__empty">' + th(input.value.trim() ? 'InboxResocNoneFound' : 'InboxTypeToSearch') + '</div>';
			ok();
		};
		let seq = 0;
		let timer = 0;
		const search = async () => {
			const mine = ++seq;
			const q = input.value.trim();
			if (q.length < 2) {
				found = [];
				paint();
				return;
			}
			let res;
			try {
				res = await api('dolibarr', 'search', { q, types: 'societe' });
			} catch (err) {
				list.innerHTML = '<div class="ibx-picklist__empty">' + esc(err.message) + '</div>';
				return;
			}
			if (mine === seq) {
				found = res.results || [];
				chosen = chosen && found.some(x => x.id === chosen.id) ? chosen : null;
				paint();
			}
		};
		input.addEventListener('input', () => {
			clearTimeout(timer);
			timer = setTimeout(search, 250);
		});
		const choose = it => {
			if (it && it.getAttribute('aria-disabled') !== 'true') {
				chosen = found.find(x => x.id === +it.dataset.id) || null;
				paint();
			}
		};
		list.addEventListener('click', e => choose(e.target.closest('[data-id]')));
		input.addEventListener('keydown', e => {
			const items = $$('[data-id]:not([aria-disabled="true"])', list);
			if (!items.length || (e.key !== 'ArrowDown' && e.key !== 'ArrowUp')) {
				return;
			}
			e.preventDefault();
			const i = items.findIndex(it => chosen && +it.dataset.id === chosen.id);
			choose(items[Math.max(0, Math.min(items.length - 1, i + (e.key === 'ArrowDown' ? 1 : -1)))]);
		});
		paint();
		input.focus();
	}
	/**
	 * Clicks in a card: the name (zone 2) and the links follow; the rest of the head folds and unfolds (zone 1); copy,
	 * write, « Not the right thirdparty? »
	 * @returns {boolean} The click was for a card
	 */
	function socCardClick(box, e) {
		if (e.target.closest('.ibx-js-resoc')) {
			resocDialog(box);
			return true;
		}
		const fix = e.target.closest('.ibx-js-domfix');
		if (fix) {
			domainFix(box, fix.dataset.cat);
			return true;
		}
		const cand = e.target.closest('.ibx-js-domcand');
		if (cand) {
			// A thirdparty chosen among the ones of the domain: the mail (and its conversation) attached to it
			post('dolibarr', 'resoc', { id: +box.dataset.mail, soc: +cand.dataset.soc, remember: 0 }).then(() => loadDolibar(box), err => toast(err.message, { error: true }));
			return true;
		}
		const phoneSet = e.target.closest('.ibx-js-phoneset');
		if (phoneSet) {
			phoneDialog(box, phoneSet.dataset.number, phoneSet.dataset.mobile === '1');
			return true;
		}
		const copy = e.target.closest('.ibx-js-reachcopy');
		if (copy) {
			reachCopy(copy);
			return true;
		}
		const write = e.target.closest('.ibx-js-reachwrite');
		if (write) {
			e.preventDefault();
			if (I.compose) {
				I.compose({ mode: 'new', to: write.dataset.val });
			}
			return true;
		}
		const zone = e.target.closest('.ibx-js-soczone');
		if (!zone) {
			return false;
		}
		if (e.target.closest('a, button:not(.ibx-js-socfold)')) {
			return true;
		}
		socCardToggle(box, zone.dataset.card, !!e.target.closest('.ibx-js-socfold'));
		return true;
	}
	/**
	 * A thirdparty found by the domain only: « this domain is a public mail service » or « several companies use it »;
	 * a manager of the settings marks it at once (undo), another user asks the managers (bell)
	 */
	function domainFixHtml(d) {
		if (!d.sender.domain || !d.level) {
			return '';
		}
		return '<span class="ibx-domfix">' + ['public', 'shared'].map(c => '<button type="button" class="ibx-linkbtn ibx-js-domfix" data-cat="' + c + '">' + th('InboxDomainsFix_' + c) + '</button>').join(' · ') + '</span>';
	}
	async function domainFix(box, category) {
		const d = box._data;
		const domain = d.sender.domain;
		try {
			if (d.sender.domain_admin) {
				const res = await post('domain', 'add', { domains: domain, category });
				toast(t('InboxDomainsMarked', domain, t('InboxDomainsCat_' + category)), { action: t('InboxUndo'), onAction: async () => {
					for (const id of res.added) {
						await post('domain', 'delete', { domain_id: id });
					}
					loadDolibar(box);
				} });
				loadDolibar(box);
			} else {
				const res = await post('domain', 'request', { domain, category });
				toast(res.told ? t('InboxDomainsRequested', domain) : t('InboxDomainsNoManager'));
			}
		} catch (err) {
			toast(err.message, { error: true });
		}
	}
	function paintDolibar(box) {
		const d = box._data;
		// Phone (1.102.0): a bottom sheet opened by the chips of the head, so always built whole
		const sheet = isSmallPhone();
		const right = dolibarRight();
		// Folded on top only (tablet); as a column, the strip on the right shows it or not (1.109.0)
		const folded = !sheet && !right && params.dolibar_folded === '1';
		const soc = d.sender.soc && d.sender.soc.readable ? d.sender.soc : null;
		const socId = soc ? soc.id : 0;
		const summary = [soc ? soc.ref : '', d.links.length + d.extra.length ? t('InboxPiecesCount', d.links.length + d.extra.length) : '', d.notes.length ? t('InboxNotesCount', d.notes.length) : '']
			.filter(Boolean).join(' · ');
		showDolAlerts(box, d.alerts);
		let html = '';
		html += sheet ? '<div class="ibx-dolibar__head ibx-dolsheet__head ibx-js-dolsheethead"><span class="ibx-dolsheet__grip" aria-hidden="true"></span>' + ic('dolibarr')
			+ '<b>' + th('InboxDolibarr') + '</b>' + (summary ? ' <span class="ibx-muted ibx-ellipsis">' + esc(summary) + '</span>' : '')
			+ '<button type="button" class="ibx-btn ibx-btn--icon ibx-js-dolsheetclose" aria-label="' + th('InboxDolibarrSheetClose') + '">' + ic('close') + '</button></div>'
			: (right ? '<div class="ibx-dolibar__head ibx-dolibar__head--col">' + ic('dolibarr') + '<b>' + th('InboxDolibarr') + '</b>'
				+ (summary ? ' <span class="ibx-muted ibx-ellipsis">' + esc(summary) + '</span>' : '')
				+ '<button type="button" class="ibx-btn ibx-btn--icon ibx-btn--sm ibx-js-sideclose" title="' + th('InboxSideClose') + '" aria-label="' + th('InboxSideClose') + '">' + ic('close') + '</button></div>'
			: '<button type="button" class="ibx-dolibar__head ibx-js-doltoggle" aria-expanded="' + !folded + '" title="' + th(folded ? 'InboxDolibarrUnfold' : 'InboxDolibarrFold') + '">'
			+ ic(folded ? 'chevron-right' : 'chevron-down') + ic('dolibarr')
			+ '<b>' + th('InboxDolibarr') + '</b>' + (summary ? ' <span class="ibx-muted ibx-ellipsis">' + esc(summary) + '</span>' : '') + '</button>');
		if (!folded) {
			// The thirdparty and the contact as cards (1.67.0): reach them, situation by the usage of the mailbox
			const sender = [];
			if (d.sender.soc) {
				sender.push((d.sender.soc.readable ? socCardHtml(box, d.sender.soc, 'soc') : pieceHtml(d.sender.soc, 0))
					+ (d.sender.by === 'domain' ? '<span class="ibx-muted ibx-soccard__by">' + th('InboxKnownByDomain') + '</span>' + domainFixHtml(d) : ''));
			}
			// Shared domains (lot A7, 1.76.0): a disposable address said; the thirdparties sharing the domain offered
			if (d.sender.domain_category === 'disposable') {
				sender.push('<span class="ibx-badge ibx-domwarn" title="' + th('InboxDomainsDisposableHelp') + '">' + ic('warning') + th('InboxDomainsDisposable') + '</span>');
			}
			if (!d.sender.soc && (d.sender.candidates || []).length) {
				sender.push('<span class="ibx-domcands"><span class="ibx-muted">' + th(d.sender.domain_category === 'shared' ? 'InboxDomainsSharedBy' : 'InboxDomainsSeveral', d.sender.candidates.length, d.sender.domain) + '</span>'
					+ d.sender.candidates.map(c => '<span class="ibx-domcand">' + pieceHtml(c, 0) + (d.level ? '<button type="button" class="ibx-btn ibx-btn--sm ibx-btn--ghost ibx-js-domcand" data-soc="' + c.id + '">' + ic('link') + ' ' + th('InboxDomainsThisOne') + '</button>' : '') + '</span>').join('')
					+ '</span>');
			}
			if (d.sender.contact) {
				sender.push(d.sender.contact.readable ? socCardHtml(box, d.sender.contact, 'contact') : pieceHtml(d.sender.contact, 0, '', socId));
			}
			if (d.sender.user) {
				sender.push('<span class="ibx-piece"><span class="ibx-piece__link">' + ic('account') + esc(t('InboxDolibarrUser', d.sender.user.name)) + '</span></span>');
			}
			const cited = box._cited || [];
			const citedOf = x => cited.find(c => c.type === x.type && c.id === x.id);
			const linkedKeys = new Set(d.links.map(l => l.type + ':' + l.id).concat(d.extra.map(x => x.type + ':' + x.id)));
			// A piece linked though cited for another thirdparty keeps its warning
			const pieces = d.links.map(l => {
				const c = citedOf(l);
				// Linked by its conversation (lot A3, 1.73.0): said under the piece
				return pieceHtml(l, l.link, c && c.match === 'other' ? citedWarning(box, c) : '', socId, l.origin === 'thread' ? { theirs: t('InboxLinkedByThread') } : {});
			}).concat(d.extra.map(x => pieceHtml(x, 0, '', socId)));
			const convo = d.conversation || { others: 0, detached: false };
			const canWrite = !!d.level;
			const suggest = cited.filter(c => c.match !== 'other' && !linkedKeys.has(c.type + ':' + c.id));
			// Mail sent by a site for a requester (data sources, 1.72.0): who asked, through which source, and the piece
			// the source offers to create
			// Never from a mail written by us (1.92.2): the lead reads the sender, so our own signature
			const canLead = !!I.lead && (d.sender.side || 'sender') === 'sender' && (d.create || []).some(c => c.type === 'societe');
			const rq = d.requester;
			const rqRef = rq && rq.data && rq.data.ref ? rq.data.ref : '';
			html += '<div class="ibx-dolibar__body">'
				+ (rq ? '<div class="ibx-dolibar__row ibx-requester"><span class="ibx-dolibar__label">' + th('InboxRequester') + '</span><span class="ibx-dolibar__items">'
					+ '<span class="ibx-badge ibx-via" title="' + esc(t('InboxRequesterHelp')) + '">' + ic('redirect') + esc(rq.label || t('InboxSourceRemoved')) + '</span>'
					+ '<span class="ibx-requester__who">' + esc(rq.name || rq.email) + (rq.name ? ' <span class="ibx-muted">' + esc(rq.email) + '</span>' : '') + '</span>'
					+ (rq.data && rq.data.company && rq.data.company !== rq.name ? '<span class="ibx-muted">' + esc(rq.data.company) + '</span>' : '')
					+ (rq.data && rq.data.phone ? '<span class="ibx-muted">' + ic('phone') + ' ' + esc(rq.data.phone) + '</span>' : '')
					+ (rqRef ? '<span class="ibx-muted">' + esc(t('InboxRequesterRef', rqRef)) + '</span>' : '')
					+ (canWrite && rq.create ? '<span class="ibx-dolibar__tools"><button type="button" class="ibx-btn ibx-btn--sm ibx-btn--primary ibx-js-createrequest" data-type="' + esc(rq.create) + '">' + ic('compose') + ' ' + th('InboxRequestCreate_' + rq.create) + '</button></span>' : '')
					+ '</span></div>' : '')
				// A mail written by us (1.92.2): the thirdparty is the one of its recipient
				+ '<div class="ibx-dolibar__row" data-sec="sender"><span class="ibx-dolibar__label">' + th(d.sender.side === 'recipient' ? 'InboxRecipient' : 'InboxSender') + '</span><span class="ibx-dolibar__items">'
				// New customer from this mail (lot A1, 1.79.0): its button says the sender is unknown, the text only without it
				+ (sender.length ? sender.join('') : (canLead ? '' : '<span class="ibx-muted">' + th('InboxSenderUnknown') + '</span>')
					+ '<span class="ibx-dolibar__tools">'
					+ (canLead ? '<button type="button" class="ibx-btn ibx-btn--sm ibx-btn--primary ibx-js-newcustomer" aria-haspopup="dialog">' + ic('user-plus') + ' ' + th('InboxLeadOpen') + '</button>' : '')
					// Beside « New customer »: a short label, so that both stay on one line in a narrow band
					+ (canWrite ? '<button type="button" class="ibx-btn ibx-btn--sm ibx-btn--ghost ibx-js-resoc"' + (canLead ? ' title="' + th('InboxResocAttach') + '"' : '') + '>' + ic('arrows-exchange') + ' ' + th(canLead ? 'InboxLeadExisting' : 'InboxResocAttach') + '</button>' : '') + '</span>') + '</span></div>'
				// Contacts left to create from their page (a mandatory field of the contact): one click, filled
				+ (d.lead_pending || []).map(x => '<div class="ibx-dolibar__row ibx-leadpending"><span class="ibx-dolibar__label">' + th('InboxLeadPendingLabel') + '</span><span class="ibx-dolibar__items">'
					+ '<span>' + esc(t('InboxLeadPending', x.name, x.soc_name)) + '</span><span class="ibx-dolibar__tools">'
					+ '<a class="ibx-btn ibx-btn--sm ibx-btn--primary" href="' + esc(x.url) + '" target="_blank" rel="noopener">' + ic('user-plus') + ' ' + esc(t('InboxLeadPendingCreate', x.name)) + '</a>'
					+ '<button type="button" class="ibx-btn ibx-btn--sm ibx-btn--icon ibx-btn--ghost ibx-js-leaddismiss" data-soc="' + x.soc + '" aria-label="' + th('InboxLeadPendingDismiss') + '" title="' + th('InboxLeadPendingDismiss') + '">' + ic('close') + '</button>'
					+ '</span></span></div>').join('')
				+ '<div class="ibx-dolibar__row" data-sec="pieces"><span class="ibx-dolibar__label">' + th('InboxPiecesLinked') + '</span><span class="ibx-dolibar__items">'
				+ (pieces.length ? pieces.join('') : '<span class="ibx-muted">' + th('InboxNoPiece') + '</span>')
				+ (canWrite ? '<span class="ibx-dolibar__tools"><button type="button" class="ibx-btn ibx-btn--sm ibx-btn--ghost ibx-js-linkpiece" aria-haspopup="dialog">' + ic('plus') + ' ' + th('InboxLinkPiece') + '</button>'
					+ ((d.create || []).length ? '<button type="button" class="ibx-btn ibx-btn--sm ibx-btn--ghost ibx-js-createpiece" aria-haspopup="dialog">' + ic('compose') + ' ' + th('InboxCreatePiece') + ' ' + ic('chevron-down') + '</button>' : '')
					+ (convo.detached ? '<button type="button" class="ibx-btn ibx-btn--sm ibx-btn--ghost ibx-js-threadattach" title="' + th('InboxThreadDetachedHelp') + '">' + ic('thread') + ' ' + th('InboxThreadAttach') + '</button>'
						: (convo.others && !+box.dataset.thread ? '<button type="button" class="ibx-btn ibx-btn--sm ibx-btn--ghost ibx-js-threaddetach" title="' + th('InboxThreadDetachHelp') + '">' + ic('unlink') + ' ' + th('InboxThreadDetach') + '</button>' : ''))
					+ '</span>' : '') + '</span></div>'
				+ (canWrite && suggest.length ? '<div class="ibx-dolibar__row ibx-cited"><span class="ibx-dolibar__label">' + th('InboxCitedInMail') + '</span><span class="ibx-dolibar__items">'
					+ suggest.map(c => pieceHtml(c, 0, c.match === 'unknown' ? citedWarning(box, c) : '', socId, {
						theirs: c.theirs ? t('InboxCitedTheirRef', c.cited) : (c.tracking ? t('InboxCitedTracking', c.cited) : ''),
						action: '<button type="button" class="ibx-btn ibx-btn--sm ibx-btn--ghost ibx-piece__act ibx-js-linkcited" data-type="' + esc(c.type) + '" data-id="' + c.id + '" aria-label="' + esc(t('InboxLink') + ' ' + (c.ref || '')) + '">' + ic('link') + ' ' + th('InboxLink') + '</button>',
					})).join('')
					+ '</span></div>' : '')
				+ ((d.actions || []).length ? '<div class="ibx-dolibar__row"><span class="ibx-dolibar__label">' + th('InboxModuleActions') + '</span><span class="ibx-dolibar__items">'
					// In a window of inbox (1.108.0) or a new tab; the markers are filled on click, with the mail as it is then
					+ d.actions.map((a, i) => a.window
						? '<button type="button" class="ibx-btn ibx-btn--sm ibx-js-modaction" data-i="' + i + '" aria-haspopup="dialog">' + ic(a.icon) + ' ' + esc(a.label) + '</button>'
						: '<a class="ibx-btn ibx-btn--sm ibx-js-modaction" data-i="' + i + '" href="' + esc(insideUrl(a.url) ? I.moduleUrl(a.url, null) : '#') + '" target="_blank" rel="noopener">' + ic(a.icon) + ' ' + esc(a.label) + '</a>').join('') + '</span></div>' : '')
				+ (d.panels || []).map(p => '<div class="ibx-dolibar__row ibx-mpanel" data-panel="' + esc(p.id) + '"><span class="ibx-dolibar__label">' + ic(p.icon) + ' ' + esc(p.title) + '</span>'
					+ '<div class="ibx-dolibar__items ibx-mpanel__body">' + panelBody(box._panelsMail === box.dataset.mail ? (box._panels || {})[p.id] : null) + '</div></div>').join('')
				+ '<div class="ibx-dolibar__row ibx-dolibar__notes" data-sec="notes"><span class="ibx-dolibar__label">' + th((d.activity || []).length ? 'InboxNotesAndReplies' : 'InboxNotes') + '</span><div class="ibx-dolibar__items ibx-notes">'
				+ threadHtml(d)
				+ '</div>'
				+ (canWrite ? '<div class="ibx-note__new">' + composerHtml('ibx-js-newnote', 'ibx-newnote-' + box.dataset.mail, '', th('InboxNotePlaceholder'), true) + '</div>' : '')
				+ '</div></div>';
		}
		const wasOpen = box.classList.contains('is-open');
		box.innerHTML = html;
		box.classList.toggle('is-sheet', sheet);
		if (!sheet && wasOpen) {
			closeDolSheet(box);
		}
		dolChips(box, sheet, { soc, contact: d.sender.contact && d.sender.contact.readable ? d.sender.contact : null, canLead: !!I.lead && (d.sender.side || 'sender') === 'sender' && (d.create || []).some(c => c.type === 'societe'), canWrite: !!d.level, rq: d.requester, user: d.sender.user, pieces: d.links.length + d.extra.length, notes: d.notes.length });
		placeSocCard(box);
		hydrateUserPhotos(box);
		I.mount(box);
		box.classList.toggle('is-folded', folded);
		const reading = readingOf(box);
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
	 * Fil of the mail (lot E2, 1.77.0): the internal notes and the answers sent from the mailbox in its conversation, by
	 * date. Only what is sure: an answer is tied to the mail by its headers or its conversation, never by its subject;
	 * the forwards are not there.
	 */
	function threadHtml(d) {
		const items = d.notes.map(n => ({ kind: 'note', date: n.date, n })).concat((d.activity || []).map(a => ({ kind: 'sent', date: a.date, a })))
			.sort((x, y) => x.date - y.date);
		if (!items.length) {
			return '<p class="ibx-notes__empty">' + th('InboxNotesEmpty') + '</p>';
		}
		return items.map((it, i) => {
			if (it.kind === 'note') {
				const prev = items[i - 1] && items[i - 1].kind === 'note' ? items[i - 1].n : null;
				return noteHtml(it.n, it.n.mine, prev);
			}
			// An answer sent from the mailbox (UX fix series): one discreet line (what, to whom, when), the details on a click
			const a = it.a;
			const by = a.mine ? t('InboxMe') : (a.author_name || '');
			const first = String(a.to || '').split(',')[0].trim();
			const more = String(a.to || '').split(',').filter(x => x.trim()).length - 1;
			const toName = (first.replace(/<[^>]*>/, '').replace(/^["'\s]+|["'\s]+$/g, '') || first.replace(/[<>]/g, '')) + (more > 0 ? ' +' + more : '');
			const line = toName
				? (by ? t(a.reply ? 'InboxActivityRepliedByTo' : 'InboxActivityWroteByTo', by, toName) : t(a.reply ? 'InboxActivityRepliedTo' : 'InboxActivitySentTo', toName))
				: (by ? t(a.reply ? 'InboxActivityReplied' : 'InboxActivityWrote', by) : t(a.reply ? 'InboxActivityRepliedShort' : 'InboxActivitySentShort'));
			const id = 'ibx-act-' + a.mail + '-' + i;
			const row = (label, value) => '<dt>' + th(label) + '</dt><dd>' + value + '</dd>';
			return '<div class="ibx-activity" role="note">'
				+ '<button type="button" class="ibx-activity__sum ibx-js-activitytoggle" aria-expanded="false" aria-controls="' + id + '">' + ic(a.reply ? 'reply' : 'sent')
				+ '<span class="ibx-activity__text"><span class="ibx-activity__line">' + esc(line) + '</span>'
				// Its first words (UX fix series): several answers to the same person told apart without unfolding them
				+ (a.snippet ? '<span class="ibx-activity__snippet">' + esc(a.snippet) + '</span>' : '') + '</span>'
				+ '<time class="ibx-activity__when" datetime="' + new Date(a.date * 1000).toISOString() + '">' + esc(headDate(a.date)) + '</time>'
				+ ic('chevron-right', 'ibx-activity__chev') + '</button>'
				+ '<div class="ibx-activity__more" id="' + id + '" hidden><dl class="ibx-activity__dl">'
				+ (a.subject ? row('InboxActivitySubject', esc(a.subject)) : '')
				+ (a.to ? row('InboxActivityToLabel', esc(a.to)) : '')
				+ row('InboxActivityFrom', esc(by ? t('InboxActivityFromInbox', by) : t('InboxActivityFromElsewhere')))
				+ row('InboxActivityOn', esc(longDate(a.date))) + '</dl>'
				+ '<button type="button" class="ibx-btn ibx-btn--sm ibx-js-activitysee" data-folder="' + a.folder + '" data-mail="' + a.mail + '">' + ic('mail') + ' ' + th('InboxActivitySeeMail') + '</button></div></div>';
		}).join('');
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
		const actToggle = e.target.closest('.ibx-js-activitytoggle');
		if (actToggle) {
			const open = actToggle.getAttribute('aria-expanded') !== 'true';
			actToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
			const more = document.getElementById(actToggle.getAttribute('aria-controls'));
			if (more) {
				more.hidden = !open;
			}
			return;
		}
		if (e.target.closest('.ibx-js-dolsheetclose')) {
			closeDolSheet(box);
			return;
		}
		if (socCardClick(box, e)) {
			return;
		}
		if (e.target.closest('.ibx-js-sideclose')) {
			closeSide('button');
			return;
		}
		if (e.target.closest('.ibx-js-doltoggle')) {
			saveParam('dolibar_folded', params.dolibar_folded === '1' ? '0' : '1');
			paintDolibar(box);
			return;
		}
		if (e.target.closest('.ibx-js-linkpiece')) {
			linkDialog(box);
			return;
		}
		const modAction = e.target.closest('.ibx-js-modaction');
		const action = modAction && box._data && (box._data.actions || [])[+modAction.dataset.i];
		if (action) {
			const url = I.moduleUrl(action.url, I.mailContext());
			if (!action.window) {
				// The new tab too stays inside Dolibarr: the subject and the correspondents are in its address (audit
				// INBX-101)
				if (!insideUrl(url)) {
					e.preventDefault();
					return;
				}
				modAction.href = url;
				return;
			}
			if (insideUrl(url)) {
				openModuleWindow({ mailId, box, url, title: action.label, panel: 'action:' + modAction.dataset.i });
			}
			return;
		}
		const panelBtn = e.target.closest('.ibx-js-mpanelopen');
		if (panelBtn && insideUrl(panelBtn.dataset.url)) {
			openModuleWindow({ mailId, box, url: panelBtn.dataset.url, title: panelBtn.dataset.title, panel: panelBtn.closest('[data-panel]').dataset.panel });
			return;
		}
		const citedBtn = e.target.closest('.ibx-js-linkcited');
		if (citedBtn) {
			// A piece cited in the mail, of the thirdparty of the writer: linked in one click
			citedBtn.disabled = true;
			try {
				const conv = box._data.conversation;
				const res = await post('dolibarr', 'link', { id: mailId, type: citedBtn.dataset.type, element: +citedBtn.dataset.id, thread: conv && conv.others && !conv.detached ? 1 : 0 });
				threadLinkedToast(res);
			} catch (err) {
				citedBtn.disabled = false;
				return;
			}
			loadDolibar(box);
			return;
		}
		if (e.target.closest('.ibx-js-threaddetach')) {
			// « Detach this mail from its conversation » (lot A3, 1.73.0): its links of the conversation go, undo
			let res;
			try {
				res = await post('dolibarr', 'detach', { id: mailId });
			} catch (err) {
				toast(err.message, { error: true });
				return;
			}
			await loadDolibar(box);
			toast(t('InboxThreadDetached', (res.removed || []).length), { action: t('InboxUndo'), onAction: async () => {
				await post('dolibarr', 'attach_thread', { id: mailId, links: res.removed || [] });
				loadDolibar(box);
			} });
			return;
		}
		if (e.target.closest('.ibx-js-threadattach')) {
			try {
				await post('dolibarr', 'attach_thread', { id: mailId, links: [] });
			} catch (err) {
				toast(err.message, { error: true });
				return;
			}
			toast(t('InboxThreadAttached'));
			loadDolibar(box);
			return;
		}
		if (e.target.closest('.ibx-js-createpiece')) {
			createDialog(box);
			return;
		}
		const lead = e.target.closest('.ibx-js-newcustomer');
		if (lead && I.lead) {
			I.lead.open({ mail: +box.dataset.mail, app: $('.ibx-mailapp', S.el), back: lead, onDone: () => loadDolibar(box) });
			return;
		}
		const dismiss = e.target.closest('.ibx-js-leaddismiss');
		if (dismiss) {
			post('lead', 'dismiss', { id: +box.dataset.mail, soc: +dismiss.dataset.soc }).then(() => loadDolibar(box), err => toast(err.message, { error: true }));
			return;
		}
		const seeSent = e.target.closest('.ibx-js-activitysee');
		if (seeSent) {
			// Wide screen (UX fix series): the answer seen over the mail being read, which stays; going to it is a
			// choice made in the dialog. A phone has no room for it: the answer opened instead
			if (isPhone()) {
				Dolibarr.executeHook('inbox:mail:goto', { folder: +seeSent.dataset.folder, mail: +seeSent.dataset.mail });
			} else {
				sentMailDialog(+seeSent.dataset.folder, +seeSent.dataset.mail);
			}
			return;
		}
		const rqBtn = e.target.closest('.ibx-js-createrequest');
		if (rqBtn) {
			createPiece(box, rqBtn.dataset.type, false, null);
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
		if (el && el.closest('.ibx-js-dolibar')) {
			return el.closest('.ibx-js-dolibar');
		}
		return dolibarIn(el && el.closest('.ibx-reading'));
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
	 * « Annotate and file… » (C2): antivirus first, then the piece is chosen, then the editor opens in a new tab on the
	 * attachment (read only); its saving puts a copy in the documents of the piece, the mail stays as it is
	 */
	async function annotateStart(box, m, a, editor) {
		let v;
		try {
			v = await attApi(m, a.part, 'check');
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
				actions: [{ label: t('InboxOpenAnyway'), primary: true, run: () => {
					linkDialog(box, { annotate: { m, a, editor, force: true } });
					return true;
				} }],
			});
			return;
		}
		linkDialog(box, { annotate: { m, a, editor, force: false } });
	}
	/** The editor on the attachment, with the piece that receives the copy (tab opened at the click, else blocked) */
	function annotateOpen(opts, type, id) {
		const url = opts.editor.url.replace(/([?&])force=0(&|$)/, '$1force=' + (opts.force ? 1 : 0) + '$2')
			+ '&record_type=' + encodeURIComponent(type) + '&record_id=' + encodeURIComponent(id);
		window.open(url, '_blank', 'noopener');
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
			// A disposable address (lot A7, 1.76.0): said before a thirdparty is made from it
			body: (d.sender.domain_category === 'disposable' ? '<div class="ibx-notice ibx-notice--warning">' + ic('warning') + '<div class="ibx-notice__text">' + th('InboxDomainsDisposableCreate', d.sender.domain) + '</div></div>' : '')
				+ '<div class="ibx-creategrid">' + d.create.map(c => '<button type="button" class="ibx-createbtn ibx-js-createtype" data-type="' + esc(c.type) + '">' + ic(c.icon) + '<span>' + esc(c.label) + '</span></button>').join('') + '</div>'
				+ '<label class="ibx-inline ibx-createattach"><input type="checkbox" class="ibx-js-createattach"> ' + th('InboxCreateAttachMail') + '</label>'
				+ '<p class="ibx-formrow__help">' + th('InboxCreateHelp') + '</p>',
			actions: [],
		});
		dlg.addEventListener('click', e => {
			const b = e.target.closest('.ibx-js-createtype');
			if (b) {
				createPiece(box, b.dataset.type, $('.ibx-js-createattach', dlg).checked, dlg);
			}
		});
	}
	/** The page of creation of a type, in a new tab; the piece saved there is linked to the mail */
	async function createPiece(box, type, attach, dlg) {
		const d = box._data;
		// The tab is opened at the click (a tab opened after an answer is blocked by the browser)
		const tab = window.open('about:blank', '_blank');
		let res;
		try {
			res = await post('dolibarr', 'intent', { id: +box.dataset.mail, type, attach: attach ? 1 : 0,
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
		if (dlg) {
			dlg.close();
		}
		toast(t('InboxCreateOpened'));
		// Back in inbox: the piece created is shown linked
		window.addEventListener('focus', () => loadDolibar(box), { once: true });
	}
	/** Toast of a link given to the other mails of the conversation too */
	function threadLinkedToast(res) {
		if (res && res.others) {
			toast(t('InboxLinkedThreadToo', res.others));
		}
	}
	/** « + Link »: the pieces of the thirdparty recognized first, then searched as you type */
	function linkDialog(box, opts) {
		opts = opts || {};
		const d = box._data;
		const soc = d.sender.soc && d.sender.soc.readable ? d.sender.soc.id : 0;
		const dlg = dialog({
			title: opts.annotate ? t('InboxAnnotateTitle', opts.annotate.a.name) : (opts.attach ? t('InboxAttachToTitle', opts.attach.a.name) : t('InboxLinkPieceTitle')),
			body: '<div class="ibx-linksearch">'
				+ '<span class="ibx-linksearch__field"><input type="search" class="ibx-field ibx-js-piecesearch" autocomplete="off" placeholder="' + th('InboxSearchPiece') + '" aria-label="' + th('InboxSearchPiece') + '">'
				+ '<span class="ibx-spinner ibx-linksearch__spin" aria-hidden="true" hidden></span></span>'
				+ '<button type="button" class="ibx-btn ibx-js-extend" aria-haspopup="menu">' + th('InboxSearchExtend') + ' ' + ic('chevron-down') + '</button></div>'
				+ '<div class="ibx-linksearch__state ibx-muted" role="status"></div>'
				+ '<div class="ibx-picklist ibx-js-piecelist" role="listbox" aria-label="' + th('InboxPiecesLinked') + '"></div>',
			actions: [{ label: opts.annotate ? t('InboxAnnotateOpen') : (opts.attach ? t('InboxAttach') : t('InboxLink')), primary: true, run: async dl => {
				const cur = $('[aria-selected="true"]', dl);
				if (!cur) {
					return false;
				}
				if (opts.annotate) {
					// Before any wait: a tab opened after an answer is blocked by the browser
					annotateOpen(opts.annotate, cur.dataset.type, +cur.dataset.id);
					toast(t('InboxAnnotateOpened'));
					return true;
				}
				if (opts.attach) {
					await attachToPiece(box, opts.attach.m, opts.attach.a, cur.dataset.type, +cur.dataset.id);
					return true;
				}
				const also = $('.ibx-js-linkthread', dl);
				threadLinkedToast(await post('dolibarr', 'link', { id: +box.dataset.mail, type: cur.dataset.type, element: +cur.dataset.id, thread: also && also.checked ? 1 : 0 }));
				loadDolibar(box);
				return true;
			} }],
		});
		// The link follows the conversation (lot A3, 1.73.0): ticked by default, the other mails of the conversation
		const conv = d.conversation;
		if (!opts.attach && !opts.annotate && conv && conv.others && !conv.detached) {
			$('.ibx-js-piecelist', dlg).insertAdjacentHTML('afterend', '<label class="ibx-inline ibx-linkthread"><input type="checkbox" class="ibx-js-linkthread" checked> ' + th('InboxLinkThreadToo', conv.others) + '</label>');
		}
		const input = $('.ibx-js-piecesearch', dlg);
		const list = $('.ibx-js-piecelist', dlg);
		// To link: the pieces linked already are greyed; to put a file in a piece: every piece may receive it
		const linked = new Set(opts.attach || opts.annotate ? [] : d.links.map(l => l.type + ':' + l.id));
		const state = $('.ibx-linksearch__state', dlg);
		const spin = $('.ibx-linksearch__spin', dlg);
		const extendBtn = $('.ibx-js-extend', dlg);
		// « Extend to »: types left out by default (the events), unchecked at each opening of the dialog (1.46.0)
		const extend = new Set();
		const EXTENDS = [{ type: 'action', icon: 'agenda', label: t('InboxSearchEvents') }];
		const paintExtend = () => {
			const on = EXTENDS.filter(x => extend.has(x.type));
			extendBtn.innerHTML = (on.length ? esc(on.map(x => '+ ' + x.label).join(' ')) : th('InboxSearchExtend')) + ' ' + ic('chevron-down');
			extendBtn.classList.toggle('is-active', on.length > 0);
		};
		// Cited in the mail: first when nothing is typed; the ones of another thirdparty at the bottom, with a warning
		const cited = box._cited || [];
		const warnOf = x => {
			const c = cited.find(y => y.type === x.type && y.id === x.id);
			return c && c.match !== 'same' ? citedWarning(box, c) : '';
		};
		let free = true;
		const item = x => {
			const done = linked.has(x.type + ':' + x.id);
			// Never chosen in advance when cited for another thirdparty: Enter does not link it by mistake
			const other = cited.some(c => c.type === x.type && c.id === x.id && c.match === 'other');
			const sel = !done && free && !other;
			if (sel) {
				free = false;
			}
			return '<div class="ibx-picklist__item" role="option" data-type="' + esc(x.type) + '" data-id="' + x.id + '"' + (done ? ' aria-disabled="true" title="' + th('InboxAlreadyLinked') + '"' : '') + ' aria-selected="' + sel + '">'
				+ ic(x.icon) + ' <span class="ibx-ellipsis"><b>' + esc(x.ref) + '</b> ' + (x.label ? '<span class="ibx-muted">' + esc(x.label) + '</span> ' : '') + '<span class="ibx-muted">· ' + esc(x.kind) + '</span></span>' + citedWarnHtml(warnOf(x)) + statusBadge(x) + '</div>';
		};
		/**
		 * @param {object[]} pieces Pieces found
		 * @param {object[]} events Events found (on demand)
		 * @param {boolean} eventsBusy Events still searched
		 */
		const render = (pieces, events, eventsBusy) => {
			const q = input.value.trim();
			free = true;
			let html = '';
			let last = '';
			if (q === '' && cited.length) {
				const first = cited.filter(c => c.match !== 'other');
				const others = cited.filter(c => c.match === 'other');
				const keys = new Set(cited.map(c => c.type + ':' + c.id));
				pieces = pieces.filter(x => !keys.has(x.type + ':' + x.id));
				if (first.length) {
					html += '<div class="ibx-picklist__group" role="presentation">' + th('InboxCitedInMail') + '</div>' + first.map(item).join('')
						+ (pieces.length ? '<div class="ibx-picklist__group" role="presentation">' + th('InboxPiecesOfSender') + '</div>' : '');
				}
				if (others.length) {
					last = '<div class="ibx-picklist__group" role="presentation">' + th('InboxCitedOtherGroup') + '</div>' + others.map(item).join('');
				}
			}
			html += pieces.map(item).join('');
			html += last;
			if (extend.has('action')) {
				html += '<div class="ibx-picklist__group" role="presentation">' + th('InboxSearchEvents') + '</div>'
					+ (eventsBusy ? '<div class="ibx-picklist__empty ibx-muted"><span class="ibx-spinner" aria-hidden="true"></span> ' + th('InboxSearchingEvents') + '</div>'
						: events.map(item).join('') || '<div class="ibx-picklist__empty ibx-muted">' + th('InboxNoEventFound') + '</div>');
			} else if (!pieces.length && !html) {
				html = '<div class="ibx-muted ibx-picklist__empty">' + (q ? th('InboxNoPieceFor', q) : th(soc ? 'InboxNoPieceFound' : 'InboxTypeToSearch'))
					+ (q ? ' <button type="button" class="ibx-linkbtn ibx-js-alsoevents">' + th('InboxSearchAlsoEvents') + '</button>' : '') + '</div>';
			}
			list.innerHTML = html;
		};
		let seq = 0;
		const load = async () => {
			const mine = ++seq;
			const q = input.value.trim();
			list.setAttribute('aria-busy', 'true');
			spin.hidden = false;
			state.textContent = t('InboxSearching');
			let pieces;
			try {
				pieces = (await api('dolibarr', 'search', { q, soc })).results;
			} catch (err) {
				if (mine !== seq) {
					return;
				}
				spin.hidden = true;
				list.removeAttribute('aria-busy');
				state.textContent = '';
				list.innerHTML = '<div class="ibx-picklist__empty">' + esc(err.message) + ' <button type="button" class="ibx-linkbtn ibx-js-retry">' + th('InboxAttRetry') + '</button></div>';
				return;
			}
			if (mine !== seq) {
				return;
			}
			// The pieces at once; the events (on demand) after, in their own group
			const withEvents = extend.has('action') && (q !== '' || soc > 0);
			render(pieces, [], withEvents);
			list.removeAttribute('aria-busy');
			if (!withEvents) {
				spin.hidden = true;
				state.textContent = '';
				return;
			}
			state.textContent = t('InboxSearchingEvents');
			let events = [];
			try {
				events = (await api('dolibarr', 'search', { q, soc, types: 'action' })).results;
			} catch (err) {
				events = [];
			}
			if (mine !== seq) {
				return;
			}
			spin.hidden = true;
			state.textContent = '';
			render(pieces, events, false);
		};
		extendBtn.addEventListener('click', () => {
			menu(extendBtn, EXTENDS.map(x => ({ icon: x.icon, label: x.label, checked: extend.has(x.type), run: () => {
				if (extend.has(x.type)) {
					extend.delete(x.type);
				} else {
					extend.add(x.type);
				}
				paintExtend();
				load();
				input.focus();
			} })));
		});
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
			if (e.target.closest('.ibx-js-alsoevents')) {
				extend.add('action');
				paintExtend();
				load();
				input.focus();
				return;
			}
			if (e.target.closest('.ibx-js-retry')) {
				load();
				return;
			}
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
		bar.hidden = false;
		// Floating over the list: showing it never moves the mails
		bar.parentNode.classList.add('has-bulk');
		// Never wider than the list: the count shortens, the less used actions go in « ⋯ » (container queries)
		bar.innerHTML = '<span class="ibx-bulkbar__count" role="status" aria-label="' + th('InboxSelectedCount', ids.length) + '"><b class="ibx-bulkbar__num">' + ids.length + '</b>'
			+ '<span class="ibx-bulkbar__word" aria-hidden="true">' + th('InboxSelectedWord') + '</span></span>'
			+ bulkActions(ids).map(a => bulkButton(a, 'ibx-btn--sm', true)).join('')
			+ '<button type="button" class="ibx-btn ibx-btn--sm ibx-bulkbar__more ibx-js-bulkmore" aria-haspopup="menu" title="' + th('InboxMoreActions') + '" aria-label="' + th('InboxMoreActions') + '">' + ic('more') + '</button>'
			+ '<button type="button" class="ibx-btn ibx-btn--icon ibx-btn--sm ibx-js-bulkclose" title="' + th('InboxClearSelection') + ' (Échap)" aria-label="' + th('InboxClearSelection') + '">' + ic('close') + '</button>';
	}
	/** Role of the folder of the selection (junk, trash: other words for some actions) */
	function bulkRole(ids) {
		const r = rowOf(ids[0]);
		return r ? (S.folders.get(r.folder) || { folder: {} }).folder.role : '';
	}
	/**
	 * Actions on several mails, shared by the bar of the list and the reading pane. prio: 1 always in the bar, 2 and
	 * 3 go in « ⋯ » when the list is narrow (2 last).
	 */
	function bulkActions(ids) {
		const rows = ids.map(rowOf).filter(Boolean);
		const anyUnread = rows.some(r => !seenOf(r));
		const why = refusal('move', ids);
		const role = bulkRole(ids);
		return [
			{ act: 'archive', icon: 'archive', label: t('InboxArchive'), disabled: why, key: 'A', prio: 1 },
			{ act: 'seen', icon: anyUnread ? 'mark-read' : 'unread', label: t(anyUnread ? 'InboxMarkRead' : 'InboxMarkUnread'), key: 'M', prio: 1 },
			{ act: 'assign', icon: 'user-plus', label: t(rows.length && rows.every(r => (r.assignees || []).includes(S.me)) ? 'InboxUnassignMe' : 'InboxAssignMe'), key: t('InboxKeySpace'), prio: 2 },
			{ act: 'tag', icon: 'tag', label: t('InboxTagMenu'), disabled: why, key: '1…9', prio: 2, menu: true },
			{ act: 'move', icon: 'folder-move', label: t('InboxMoveToMenu'), disabled: why, key: 'Maj+M', prio: 3 },
			{ act: role === 'junk' ? 'notjunk' : 'junk', icon: role === 'junk' ? 'inbox' : 'junk', label: t(role === 'junk' ? 'InboxNotJunk' : 'InboxJunk'), disabled: why, key: role === 'junk' ? 'Maj+J' : 'J', prio: 3 },
			{ act: 'delete', icon: 'delete', label: t(role === 'trash' ? 'InboxDestroy' : 'InboxDelete'), disabled: why, key: 'Suppr', prio: 1, danger: true },
		];
	}
	/** A button of an action on several mails; in the bar its name hides on a narrow list (kept as title and for the screen readers) */
	function bulkButton(a, size, inBar) {
		return (a.danger && inBar ? '<span class="ibx-spacer"></span>' : '')
			+ '<button type="button" class="ibx-btn ' + size + ' ibx-js-bulk' + (a.danger ? ' ibx-btn--danger-quiet' : '') + '" data-act="' + a.act + '" data-prio="' + a.prio + '" data-icon="' + a.icon + '"'
			+ (a.disabled ? ' aria-disabled="true" title="' + esc(a.disabled) + '"' : ' title="' + esc(a.label + (a.key ? ' (' + a.key + ')' : '')) + '"')
			+ (a.menu ? ' aria-haspopup="menu"' : '') + (a.key && a.key.indexOf('…') < 0 ? ' aria-keyshortcuts="' + esc(a.key.replace('Maj', 'Shift').replace('Suppr', 'Delete')) + '"' : '')
			+ ' aria-label="' + esc(a.label) + '">' + ic(a.icon) + '<span class="ibx-bulkbar__label">' + esc(a.label) + '</span></button>';
	}
	function bindBulkBar() {
		const bar = $('.ibx-js-bulkbar', S.el);
		bar.addEventListener('click', onBulkClick);
	}
	/** A click on an action on several mails: in the bar of the list, its « ⋯ » menu, or the reading pane */
	function onBulkClick(e) {
		const more = e.target.closest('.ibx-js-bulkmore');
		if (more) {
			// The actions hidden by the narrow list, with their names
			const hidden = $$('.ibx-js-bulk', $('.ibx-js-bulkbar', S.el)).filter(b => b.offsetParent === null);
			menu(more, hidden.map(b => ({ icon: b.dataset.icon, label: b.getAttribute('aria-label'), disabled: b.getAttribute('aria-disabled') === 'true', reason: b.title,
				run: () => runBulk(b.dataset.act, more, b) })));
			return;
		}
		if (e.target.closest('.ibx-js-bulkclose')) {
			S.selected.clear();
			S.allInFolder = false;
			paintSelection();
			renderBulkBar();
			showNothing();
			return;
		}
		const b = e.target.closest('.ibx-js-bulk');
		if (b) {
			runBulk(b.dataset.act, b, b);
		}
	}
	/**
	 * Run an action on the selection
	 * @param {string} act Action
	 * @param {HTMLElement} anchor Where a menu opens (the button, or « ⋯ » for a hidden one)
	 * @param {HTMLElement} b Button of the action (its refusal)
	 */
	async function runBulk(act, anchor, b) {
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
			tagMenu(anchor, ids);
		} else if (act === 'move') {
			moveDialog(ids);
		} else if (act === 'archive') {
			doArchive(ids);
		} else if (act === 'junk' || act === 'notjunk') {
			doJunk(ids, act === 'junk');
		} else if (act === 'delete') {
			doDelete(ids);
		}
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
		if (!canHandle(e.account)) {
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
		// My views dragged among my views (1.34.0): a line shows where it goes
		const vline = () => $$('.is-dropbefore, .is-dropafter', tree).forEach(x => x.classList.remove('is-dropbefore', 'is-dropafter'));
		const vplace = e => {
			const row = e.target.closest && e.target.closest('[data-vview]');
			const v = row && S.vviews.find(x => x.id === +row.dataset.vview);
			if (!v || !v.mine) {
				return null;
			}
			const r = row.getBoundingClientRect();
			return { row, id: v.id, after: e.clientY > r.top + r.height / 2 };
		};
		tree.addEventListener('dragstart', e => {
			const row = e.target.closest && e.target.closest('[data-vview][draggable="true"]');
			if (!row) {
				return;
			}
			S.vdrag = +row.dataset.vview;
			e.dataTransfer.effectAllowed = 'move';
			e.dataTransfer.setData('text/plain', $('.ibx-tree__label', row).textContent);
		});
		tree.addEventListener('dragover', e => {
			if (!S.vdrag) {
				return;
			}
			vline();
			const p = vplace(e);
			if (!p) {
				e.dataTransfer.dropEffect = 'none';
				return;
			}
			e.preventDefault();
			e.dataTransfer.dropEffect = 'move';
			if (p.id !== S.vdrag) {
				p.row.classList.add(p.after ? 'is-dropafter' : 'is-dropbefore');
			}
		});
		tree.addEventListener('drop', e => {
			if (!S.vdrag) {
				return;
			}
			e.preventDefault();
			e.stopImmediatePropagation();
			const id = S.vdrag;
			const p = vplace(e);
			S.vdrag = 0;
			vline();
			if (!p || p.id === id) {
				return;
			}
			const mine = S.vviews.filter(x => x.mine && x.id !== id);
			const to = mine.findIndex(x => x.id === p.id) + (p.after ? 1 : 0);
			moveVview(id, to);
		});
		tree.addEventListener('dragend', () => {
			S.vdrag = 0;
			vline();
		});
		// A folder dragged (1.42.0): on the edge of a brother, put before or after it (my order); in the middle of a
		// folder or on the mailbox, moved into it on the server (its manager only); never into itself nor elsewhere
		const fclear = () => $$('.is-dropbefore, .is-dropafter, .is-droptarget', tree).forEach(x => x.classList.remove('is-dropbefore', 'is-dropafter', 'is-droptarget'));
		const fplace = e => {
			const d = S.folders.get(S.fdrag);
			const row = e.target.closest && e.target.closest('[data-folder], [data-account]');
			if (!d || !row) {
				return null;
			}
			if (row.dataset.account && !row.dataset.folder) {
				if (+row.dataset.account !== d.account.id) {
					return { row, why: t('InboxFolderOtherBox') };
				}
				return !canHandle(d.account) ? { row, why: t('InboxReadOnlyOn', d.account.email) } : (d.folder.parent ? { row, into: 0 } : null);
			}
			const e2 = S.folders.get(+row.dataset.folder);
			if (!e2 || e2.folder.id === d.folder.id) {
				return null;
			}
			if (e2.account.id !== d.account.id) {
				return { row, why: t('InboxFolderOtherBox') };
			}
			const r = row.getBoundingClientRect();
			const y = (e.clientY - r.top) / r.height;
			if (e2.folder.parent === d.folder.parent && movableFolder(e2.folder) && (y < 0.25 || y > 0.75)) {
				return { row, place: e2.folder.id, after: y > 0.75 };
			}
			for (let p = e2.folder.id; p; p = (S.folders.get(p) || { folder: {} }).folder.parent) {
				if (p === d.folder.id) {
					return { row, why: t('InboxFolderIntoItself') };
				}
			}
			if (!canHandle(d.account)) {
				return { row, why: t('InboxReadOnlyOn', d.account.email) };
			}
			return e2.folder.id === d.folder.parent ? null : { row, into: e2.folder.id };
		};
		tree.addEventListener('dragstart', e => {
			const row = e.target.closest && e.target.closest('[data-folder][draggable="true"]');
			if (!row || S.vdrag) {
				return;
			}
			S.fdrag = +row.dataset.folder;
			e.dataTransfer.effectAllowed = 'move';
			e.dataTransfer.setData('text/plain', $('.ibx-tree__label', row).textContent);
		});
		tree.addEventListener('dragover', e => {
			if (!S.fdrag) {
				return;
			}
			fclear();
			const p = fplace(e);
			if (!p || p.why) {
				e.dataTransfer.dropEffect = 'none';
				dragTip(p && p.why ? p.why : '', e.clientX, e.clientY);
				return;
			}
			e.preventDefault();
			e.dataTransfer.dropEffect = 'move';
			dragTip('');
			p.row.classList.add(p.place ? (p.after ? 'is-dropafter' : 'is-dropbefore') : 'is-droptarget');
		});
		tree.addEventListener('drop', e => {
			if (!S.fdrag) {
				return;
			}
			e.preventDefault();
			e.stopImmediatePropagation();
			const d = S.folders.get(S.fdrag);
			const p = fplace(e);
			S.fdrag = 0;
			fclear();
			dragTip('');
			if (!d || !p || p.why) {
				return;
			}
			if (p.place) {
				placeFolder(d.folder.id, p.place, p.after);
			} else {
				folderMoveInto(d.folder, p.into);
			}
		});
		tree.addEventListener('dragend', () => {
			if (S.fdrag) {
				S.fdrag = 0;
				fclear();
				dragTip('');
			}
		});
		let hover = { id: 0, timer: 0 };
		tree.addEventListener('dragover', e => {
			if (!S.drag) {
				return;
			}
			// One mailbox at a time: hovering the selector opens the list, hovering a mailbox of it shows that mailbox
			const pick = e.target.closest('[data-boxpick], [data-boxpick-toggle]');
			if (pick) {
				const pkey = 'p' + (pick.dataset.boxpick || 'list');
				$$('.is-droptarget', tree).forEach(x => x.classList.remove('is-droptarget'));
				dragTip('');
				if (hover.id !== pkey) {
					clearTimeout(hover.timer);
					hover = { id: pkey, timer: setTimeout(() => {
						if (pick.dataset.boxpick) {
							chooseBox(+pick.dataset.boxpick, true);
						} else if (!S.pickOpen) {
							S.pickOpen = true;
							renderTree();
						}
					}, 600) };
				}
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
					if (setOpen(key, true, false)) {
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
			const why = !entry ? '' : (!canHandle(entry.account) ? t('InboxReadOnlyOn', entry.account.email) : (entry.folder.selectable ? '' : t('InboxFolderNotSelectable')));
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
			if (entry && canHandle(entry.account) && entry.folder.selectable && e.dataTransfer.files.length) {
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
		// Actions of lot W4: starred, archive and remove as buttons, the others in the menu; junk / not junk at hand too (1.84.1)
		const shown = sel => $$(sel, S.el).find(b => b.offsetParent !== null);
		const anchor = () => shown('.ibx-js-panebar .ibx-js-more') || $('.ibx-js-more', mailRoot(m)) || S.el;
		mailActionItems([m.id]).filter(a => !a.sep).forEach(a => {
			// Tags at hand (1.57.0): a button of the bar, its menu under it
			const tagAnchor = () => shown('.ibx-js-panebar .ibx-js-action[data-action="tag"]') || $('.ibx-js-action[data-action="tag"]', mailRoot(m)) || anchor();
			list.push(Object.assign({}, a, { button: ['flag', 'tag', 'archive', 'junk', 'notjunk', 'delete'].includes(a.id), run: a.id === 'tag' ? () => tagMenu(tagAnchor(), [m.id]) : a.run }));
		});
		// Recognized domains (1.59.0): with the right, the domain of the authenticated sender or of the links
		const offer = m.trust_offer;
		if (offer && (offer.sender || (offer.links || []).length)) {
			list.push({ sep: true }, { id: 'trust', icon: 'trusted', label: t('InboxTrustedDomainAdd'), run: () => trustDialog(m) });
		}
		list.push(
			{ sep: true },
			{ id: 'headers', icon: 'info', label: t('InboxAllHeaders'), run: () => headersDialog(m) },
			{ id: 'source', icon: 'source', label: t('InboxViewSource'), key: 'Ctrl+U', run: () => openViewer({ mail: m, source: true }) },
			{ id: 'eml', icon: 'download', label: t('InboxSaveEml'), run: () => download(I.ctx('INBOX_AJAX_URL', '') + 'body.php?id=' + m.id + '&part=source&download=1') },
			{ id: 'print', icon: 'print', label: t('InboxPrint'), key: 'Ctrl+P', run: () => printMail(m) },
			{ id: 'zoomin', icon: 'text-size', label: t('InboxZoomMailIn'), run: () => zoomMail(10) },
			{ id: 'zoomout', icon: 'text-size', label: t('InboxZoomMailOut'), run: () => zoomMail(-10) },
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
	// Phone (1.100.0): the bar of the mail keeps the core actions; replies are in the bar at the bottom of the mail, the
	// minor ones go to the menu « More »
	const ACT_REPLY = ['reply', 'replyall', 'forward'];
	const ACT_MINOR = ['read', 'flag', 'junk', 'notjunk'];
	const isSmallPhone = () => window.matchMedia('(max-width: 39.9375rem)').matches;
	function actionButtons(m) {
		return actions(m).filter(a => a.button).map(a => '<button type="button" class="ibx-btn ibx-btn--icon ibx-js-action' + (a.id === 'read' ? ' ibx-js-toggleread' : '')
			+ (ACT_REPLY.includes(a.id) ? ' ibx-act--reply' : (ACT_MINOR.includes(a.id) ? ' ibx-act--minor' : ''))
			+ (a.danger ? ' ibx-btn--danger-hover' + (a.irreversible ? ' is-irreversible' : '') : '') + '" data-action="' + esc(a.id) + '"'
			+ (a.disabled ? ' aria-disabled="true" title="' + esc(a.reason || a.label) + '"' : ' title="' + esc(a.label + (a.key ? ' (' + a.key + ')' : '')) + '"') + ' aria-label="' + esc(a.label) + '">' + ic(a.icon) + '</button>').join('')
			+ '<button type="button" class="ibx-btn ibx-btn--icon ibx-js-more" aria-haspopup="menu" aria-label="' + th('InboxMoreActions') + '">' + ic('more') + '</button>';
	}

	/** Small menu under a button; closes on Escape, outside click; arrows move */
	// The menus of inbox for the other spaces too (Settings: menu of a rule)
	I.menu = (anchor, items, at) => menu(anchor, items, at);
	/**
	 * The files of a writing in the viewer of inbox (lot A5, 1.82.0), to check them before sending
	 * @param {string} token Writing
	 * @param {string} subject Subject of the writing
	 * @param {Array<{id:string,name:string,size:number,mime:string}>} files Attachments
	 * @param {number} index File opened
	 */
	I.viewComposeFiles = (token, subject, files, index) => openViewer({ mail: { id: 'c-' + token, compose: token, subject,
		attachments: files.map(f => ({ part: f.id, name: f.name, size: f.size, kind: fileKind(f.name, f.mime) })) }, index });
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
			: '<button type="button" class="ibx-menu__item' + (it.danger ? ' ibx-menu__item--danger' : '') + '" role="' + (it.checked !== undefined ? 'menuitemcheckbox" aria-checked="' + !!it.checked : 'menuitem') + '" data-i="' + i + '"' + (it.disabled ? ' aria-disabled="true"' + (it.reason ? ' title="' + esc(it.reason) + '"' : '') : '') + '>' + (it.swatch ? it.swatch : it.icon ? ic(it.icon, 'ibx-menu__icon') : '<span class="ibx-menu__icon"></span>')
				+ '<span class="ibx-menu__label">' + esc(it.label) + '</span>' + (it.checked !== undefined ? (it.checked ? ic('check') : '') : '') + (it.key ? '<span class="ibx-menu__key">' + esc(it.key) + '</span>' : '') + '</button>').join('');
		// From a modal dialog (« Extend to » of « Link »): inside it, else the menu is under it and inert
		const host = anchor && anchor.closest ? anchor.closest('dialog[open]') : null;
		(host || document.body).appendChild(box);
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
			+ ' ' + headTagsHtml(row.id, row) + '</h2><div class="ibx-readhead__line">' + th('InboxThreadCount', mails.length) + (box ? ' · ' + th('InboxReceivedOn') + ' <b>' + esc(box) + '</b>' : '') + '</div>'
			+ assignLine([row.id], row.assignees || [], row.has_mid !== 0) + dolChipsSlot() + '</div>'
			+ '<div class="ibx-readhead__actions ibx-js-convoactions"></div></div></header>'
			+ dolibarSlot(id, true)
			// The mails of the thread: a box of their own, the one that scrolls when the column Dolibarr is on the right
			+ '<div class="ibx-convo__list ibx-js-convolist">' + mails.map(m => '<section class="ibx-convo__item' + (isMine(m) ? ' is-mine' : '') + (m.id === id ? ' is-current' : '') + '" data-mailblock="' + m.id + '">'
				+ '<button type="button" class="ibx-convo__line ibx-js-expand" aria-expanded="false">' + (isMine(m) ? '<span class="ibx-avatar ibx-avatar--sm ibx-avatar--me" aria-hidden="true">' + ic('sent') + '</span>' : avatar(m.from_name, m.from_email, 'ibx-avatar--sm', m.id))
				+ '<span class="ibx-sr-only">' + th(isMine(m) ? 'InboxSent' : 'InboxReceived') + ' </span>'
				+ '<span class="ibx-convo__who ibx-ellipsis' + (seenOf(m) ? '' : ' is-unread') + '">' + esc(isMine(m) ? mineLabel(m) : (m.from_name || m.from_email || t('InboxNoSender'))) + '</span>'
				+ '<span class="ibx-convo__snippet ibx-ellipsis ibx-muted">' + esc(m.snippet || m.subject || '') + '</span>'
				+ (m.attachments ? ic('attachment', 'ibx-faint') : '') + '<span class="ibx-convo__date ibx-muted" title="' + esc(longDate(m.date)) + '">' + esc(shortDate(m.date)) + '</span></button>'
				+ '<div class="ibx-convo__body" hidden></div></section>').join('') + '</div>'
			+ '<section class="ibx-attbar ibx-attbar--answers ibx-convo__foot" aria-label="' + th('InboxQuickReply') + '"><div class="ibx-attbar__head">' + quickReplyHtml(last.id, '') + '</div></section></article>';
		bindQuickReply(pane);
		hydrateAvatars(pane);
		I.mount(pane);
		const dolBox = $('.ibx-js-dolibar', pane);
		seatDolibar();
		loadDolibar(dolBox);
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
			scrollWithin(convoScroller(pane), current, current === $('[data-mailblock="' + last.id + '"]', pane) ? 'end' : 'start');
		}
	}

	/**
	 * What scrolls in a conversation: its list of mails when the column Dolibarr is on the right (head, column and
	 * answers fixed, as a mail alone), else the whole pane
	 */
	function convoScroller(pane) {
		const list = pane && $('.ibx-js-convolist', pane);

		return list && getComputedStyle(list).overflowY !== 'visible' ? list : pane;
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
		I.mount(item);
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
			syncPaneBar(m);
			autoRead(m);
			presenceSoon();
			Dolibarr.executeHook('inbox:mail:open', { account: m.account, messageId: m.message_id, subject: m.subject, from: m.from });
		}
	}

	/**
	 * Answer at hand at the bottom of the mail read (1.26.0): reply, reply to all, forward, without going back up.
	 * Not for a draft (it is edited), nor without a sending identity.
	 */
	function quickReplyHtml(mailId, role) {
		if (role === 'drafts' || !I.ctx('INBOX_CAN_SEND', false)) {
			return '';
		}
		// The names hide on a narrow pane (container query): the buttons keep them as title and accessible name
		return '<div class="ibx-quickreply" role="group" aria-label="' + th('InboxQuickReply') + '">'
			+ [['reply', 'reply', 'InboxReply'], ['replyall', 'reply-all', 'InboxReplyAll'], ['forward', 'forward', 'InboxForward']]
				.map(x => '<button type="button" class="ibx-btn ibx-btn--sm" data-qr="' + x[0] + '" data-mail="' + mailId + '" title="' + th(x[2]) + '" aria-label="' + th(x[2]) + '">'
					+ ic(x[1]) + '<span class="ibx-quickreply__label">' + th(x[2]) + '</span></button>').join('')
			+ '</div>';
	}
	function bindQuickReply(root) {
		$$('[data-qr]', root).forEach(b => b.addEventListener('click', () => I.compose({ mode: b.dataset.qr, mail: +b.dataset.mail })));
	}

	function renderMail(m) {
		const pane = readPane();
		S.blocks = { [m.id]: m };
		const signed = m.security && m.security.signed ? '<span class="ibx-badge" title="' + esc(m.security.signer || '') + '">' + ic(m.security.signed === 'invalid' ? 'warning' : 'check') + ' '
			+ th(m.security.signed === 'valid' ? 'InboxSigned' : (m.security.signed === 'invalid' ? 'InboxSignatureInvalid' : 'InboxSignedUnchecked')) + '</span>' : '';
		pane.innerHTML = '<article class="ibx-reading" aria-label="' + esc(m.subject || t('InboxNoSubject')) + '">'
			+ '<header class="ibx-readhead">' + headHtml(m) + (signed ? '<div class="ibx-readhead__line">' + signed + '</div>' : '') + '</header>'
			+ dolibarSlot(m.id, false)
			+ mailBodyHtml(m, { quick: true }) + '</article>';
		bindActions(pane);
		syncPaneBar(m);
		bindQuickReply(pane);
		bindMail(m, pane);
		hydrateAvatars(pane);
		I.mount(pane);
		const dolBox = $('.ibx-js-dolibar', pane);
		seatDolibar();
		loadDolibar(dolBox);
	}

	/** Tests of an anti-spam filter that weigh towards spam, in words (3 reasons at most; the others left) */
	const SPAM_REASONS = [
		[/^BAYES_(5[0-9]|[6-9]\d|SPAM)/, 'InboxSpamWhy_learnt'],
		[/^(URIBL|SURBL|URI_|DBL_|PHISHING|PH_)/, 'InboxSpamWhy_links'],
		[/^(RCVD_IN_|RBL_|DNSBL|SPAMHAUS|RECEIVED_SPAMHAUS|RWL_)/, 'InboxSpamWhy_blacklist'],
		[/^(SPF_FAIL|SPF_SOFTFAIL|R_SPF_FAIL|R_SPF_SOFTFAIL|DKIM_INVALID|R_DKIM_REJECT|DMARC_POLICY_|DMARC_NA|DMARC_REJECT|DMARC_QUAR)/, 'InboxSpamWhy_auth'],
		[/^(FORGED|FAKE_|SPOOF|HEADER_FROM_DIFFERENT|FROM_EXCESS|MISSING_|R_MISSING|FROM_NEQ_)/, 'InboxSpamWhy_headers'],
		[/^(HTML_|MIME_HTML|MPART_|TVD_|LOTS_OF_MONEY|ADVANCE_FEE|MONEY_|SUBJ_ALL_CAPS|UPPERCASE|FREEMAIL_FORGED|DEAR_)/, 'InboxSpamWhy_content'],
	];
	function spamReasons(tests) {
		const out = [];
		(tests || []).forEach(test => {
			const hit = SPAM_REASONS.find(([re]) => re.test(String(test).toUpperCase()));
			if (hit && !out.includes(t(hit[1])) && out.length < 3) {
				out.push(t(hit[1]));
			}
		});
		return out;
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
		// Delivery report (1.25.0): one line per recipient in words; the sentence of the server in small; link to the
		// mail sent. A mail sent: its failures, link to the report.
		const bounceLine = (r, key) => '<div class="ibx-bounce__line"><b>' + th(key, r.email) + '</b> : ' + th('InboxBounce_' + r.family)
			+ (r.status ? ' <span class="ibx-mono ibx-muted">' + esc(r.status) + '</span>' : '')
			+ (r.diagnostic ? '<div class="ibx-bounce__server ibx-muted">' + th('InboxBounceServerSaid', r.diagnostic) + '</div>' : '') + '</div>';
		const gotoLink = (where, key) => where ? '<div><button type="button" class="ibx-linkbtn ibx-js-bouncego" data-folder="' + where.folder + '" data-mail="' + where.mail + '">' + th(key) + '</button></div>' : '';
		if (m.report && m.report.recipients && m.report.recipients.length) {
			const failed = m.report.recipients.filter(r => r.action === 'failed');
			const delayed = m.report.recipients.filter(r => r.action !== 'failed');
			if (failed.length) {
				banners += banner('danger', 'warning', failed.map(r => bounceLine(r, 'InboxNotDeliveredTo')).join('') + gotoLink(m.report.sent, 'InboxBounceSeeSent'));
			}
			if (delayed.length) {
				banners += banner('warning', 'send-later', delayed.map(r => bounceLine(r, 'InboxDelayedTo')).join('') + (failed.length ? '' : gotoLink(m.report.sent, 'InboxBounceSeeSent')));
			}
		}
		if (m.bounces && m.bounces.length) {
			banners += banner('danger', 'warning', m.bounces.map(r => bounceLine(r, 'InboxNotDeliveredTo') + gotoLink(r.report, 'InboxBounceSeeReport')).join(''));
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
		// Bank details (lot A6, 1.75.0): red when not authenticated or imitated, orange otherwise, never green
		if (m.bank && !opts.inline) {
			const b = bankBanner(m);
			banners = b + banners;
		}
		// Recognized domains (1.59.0): a recognized domain whose authentication failed (red, above everything), a
		// lookalike of a recognized domain (red, orange when its name only contains the recognized one)
		const tr = m.trusted;
		if (tr && tr.state === 'unproven') {
			banners = banner('danger', 'spoof', '<b>' + th('InboxTrustedUnprovenTitle') + '</b> ' + th('InboxTrustedUnproven', tr.label, tr.host)) + banners;
		} else if (tr && tr.state === 'lookalike') {
			// Our own domain imitated (1.60.0): said as such
			const b = banner(tr.level === 'danger' ? 'danger' : 'warning', 'spoof', tr.own ? th('InboxLookalikeOwnSender', tr.domain, tr.host) : th('InboxLookalikeSender', tr.domain, tr.label, tr.host));
			banners = tr.level === 'danger' ? b + banners : banners + b;
		}
		// Verdict of the anti-spam filter of the server (1.44.0): a mail judged spam but not in the junk says it, with
		// the button; in the junk, the score and the reasons in words
		const sp = m.spam;
		if (sp && !opts.inline && (sp.spam || m.folder_role === 'junk')) {
			const score = sp.score === null ? '' : (sp.required === null ? th('InboxSpamScore', sp.score.toLocaleString(locale())) : th('InboxSpamScoreOf', sp.score.toLocaleString(locale()), sp.required.toLocaleString(locale())));
			const why = spamReasons(sp.tests);
			// The engine of inbox (1.88.0) is named as such, never as the filter of the server
			const text = (sp.local ? th(sp.spam ? 'InboxSpamJudgedLocal' : 'InboxSpamFilterLocal') : (sp.spam ? th('InboxSpamJudged') : th('InboxSpamFilter'))) + (score ? ' ' + score : '') + (why.length ? '<div class="ibx-muted">' + esc(why.join(' · ')) + '</div>' : '');
			if (sp.spam && m.folder_role !== 'junk') {
				banners += '<div class="ibx-banner ibx-banner--warning">' + ic('junk') + '<div class="ibx-banner__text"' + (sp.tests.length ? ' title="' + esc(sp.tests.join(', ')) + '"' : '') + '>' + text + '</div>'
					+ '<button type="button" class="ibx-btn ibx-btn--sm ibx-js-isjunk">' + ic('junk') + ' ' + th('InboxJunk') + '</button></div>';
			} else {
				banners += '<div class="ibx-banner ibx-banner--info">' + ic('junk') + '<div class="ibx-banner__text"' + (sp.tests.length ? ' title="' + esc(sp.tests.join(', ')) + '"' : '') + '>' + text + '</div></div>';
			}
		}
		if (m.unsubscribe && !opts.inline) {
			// Newsletter (B4): one-click by the server, never the link opened; by mail when the composer comes (W6)
			const u = m.unsubscribe;
			// By mail (1.53.0): a writing prepared from the address that got the mail, sent by the user
			const byMail = !u.oneclick && !!u.mailto;
			const why = !u.signed ? t('InboxErrorUnsubscribeNotSigned') : (m.level !== 'manage' ? t('InboxReadOnlyOn', m.mailbox)
				: (byMail && !I.ctx('INBOX_CAN_SEND', false) ? t('InboxComposeNoIdentity') : ''));
			banners += '<div class="ibx-banner ibx-banner--info ibx-js-unsubbar">' + ic('unsubscribe') + '<div class="ibx-banner__text">' + th(byMail ? 'InboxNewsletterByMail' : 'InboxNewsletter')
				+ (why ? '<div class="ibx-muted">' + esc(why) + '</div>' : '') + (byMail && why ? '<div class="ibx-muted ibx-banner__detail">' + esc(u.mailto) + '</div>' : '') + '</div>'
				+ '<button type="button" class="ibx-btn ibx-btn--sm ibx-js-unsubscribe"' + (why ? ' aria-disabled="true" title="' + esc(why) + '"' : '') + '>' + th('InboxUnsubscribe') + '</button>'
				// The address copied only when inbox cannot write the mail itself
				+ (byMail && why ? '<button type="button" class="ibx-btn ibx-btn--sm ibx-btn--ghost ibx-js-unsubcopy">' + ic('copy') + ' ' + th('InboxCopyUnsubscribeAddress') + '</button>' : '') + '</div>';
		}
		// Invitation and read receipt (lot W8 1.22.0, js/inbox-invite.js)
		if (I.mailExtrasHtml) {
			banners += I.mailExtrasHtml(m, opts);
		}
		if (m.body.remote && !m.body.images) {
			banners += '<div class="ibx-banner ibx-banner--info ibx-js-imagesbar">' + ic('remote-blocked') + '<div class="ibx-banner__text">' + th('InboxRemoteBlocked')
				+ (m.body.trackers ? ' ' + th('InboxTrackers', m.body.trackers) : '')
				// Sender not proven: a choice « Always » would be saved but never applied, so not offered
				+ (!(m.auth && m.auth.fail) && m.body.always !== 'never' && m.body.always_ok === false ? '<div class="ibx-muted">' + th('InboxImagesAlwaysUnproven') + '</div>' : '') + '</div>'
				+ '<button type="button" class="ibx-btn ibx-btn--sm ibx-js-showimages">' + th('InboxShowImages') + '</button>'
				// « Always » refused by the administrator (setting 1.78.0): the pictures of each mail on a click only
				+ (m.auth && m.auth.fail || m.body.always === 'never' || m.body.always_ok === false ? '' : '<button type="button" class="ibx-btn ibx-btn--sm ibx-js-allowimages" aria-haspopup="menu" aria-label="' + th('InboxAlwaysShowImages') + '"><span class="ibx-banner__lbl">' + th('InboxAlwaysShowImages') + '</span> ' + ic('chevron-down') + '</button>') + '</div>';
		}
		const files = att.length;
		const total = att.reduce((n, a) => n + a.size, 0);
		const open = params.attachments_open === '1';
		const quick = opts.quick ? quickReplyHtml(m.id, m.folder_role) : '';
		m.fold = !!opts.fold;
		return (banners ? '<div class="ibx-readbanners">' + banners + '</div>' : '')
			+ '<div class="ibx-readbody">' + (m.security && m.security.encrypted ? '' : '<iframe class="ibx-mailframe ibx-js-frame" title="' + th('InboxMailBody') + '"'
				+ ' sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox" referrerpolicy="no-referrer" loading="eager" src="' + bodyUrl(m, m.body.images) + '"></iframe>')
			+ '</div>'
			// Foot of the reading, stuck at the bottom (1.26.0): the answer at hand, then the attachments on the same line
			+ ((files || quick) ? '<section class="ibx-attbar' + (opts.inline ? ' ibx-attbar--inline' : '') + (files ? ' ibx-attbar--files' : ' ibx-attbar--answers') + '" aria-label="' + th(files ? 'InboxAttachments' : 'InboxQuickReply') + '"><div class="ibx-attbar__head">'
				+ quick
				// Phone (UX fix series): a paper clip with the count opens the list as a sheet rising from the bottom
				+ (files ? '<button type="button" class="ibx-btn ibx-attbar__clip ibx-js-attsheet" aria-haspopup="dialog" title="' + th('InboxAttachmentsCount', files) + '" aria-label="' + th('InboxAttachmentsCount', files) + '">'
					+ ic('attachment') + '<span class="ibx-attbar__count" aria-hidden="true">' + files + '</span></button>' : '')
				+ (files ? '<button type="button" class="ibx-attbar__toggle ibx-js-atttoggle" aria-expanded="' + open + '">' + ic(open ? 'chevron-down' : 'chevron-right') + ic('attachment')
				+ '<b>' + th('InboxAttachmentsCount', files) + '</b> <span class="ibx-muted">' + esc(size(total)) + '</span>'
				+ '<span class="ibx-attbar__names ibx-ellipsis ibx-muted">' + esc(att.map(a => a.name).join(', ')) + '</span></button>'
				// The antivirus: running, then its result (1.45.0), seen even when the attachments are folded
				+ '<span class="ibx-attbar__scan ibx-js-attscan" role="status"></span>'
				// Its name hides on a narrow pane: kept as title and accessible name
				+ '<button type="button" class="ibx-btn ibx-btn--sm ibx-js-saveall" title="' + th(files > 1 ? 'InboxSaveAllZip' : 'InboxSave') + '" aria-label="' + th(files > 1 ? 'InboxSaveAllZip' : 'InboxSave') + '">'
				+ ic('download-all') + '<span class="ibx-attbar__savelabel">' + th(files > 1 ? 'InboxSaveAllZip' : 'InboxSave') + '</span></button>' : '') + '</div>'
				+ (files ? '<div class="ibx-attachments"' + (open ? '' : ' hidden') + '>'
					+ '<div class="ibx-attsheet__head ibx-js-attsheethead"><span class="ibx-dolsheet__grip" aria-hidden="true"></span>' + ic('attachment')
					+ '<b>' + th('InboxAttachmentsCount', files) + '</b> <span class="ibx-muted">' + esc(size(total)) + '</span>'
					+ '<button type="button" class="ibx-btn ibx-btn--icon ibx-js-attsaveall" title="' + th(files > 1 ? 'InboxSaveAllZip' : 'InboxSave') + '" aria-label="' + th(files > 1 ? 'InboxSaveAllZip' : 'InboxSave') + '">' + ic('download-all') + '</button>'
					+ '<button type="button" class="ibx-btn ibx-btn--icon ibx-js-attsheetclose" aria-label="' + th('InboxCloseWindow') + '">' + ic('close') + '</button></div>'
					+ att.map((a, i) => tile(a, i)).join('') + '</div>' : '') + '</section>' : '');
	}

	/**
	 * Bank details in the mail (lot A6, 1.75.0): a lesson, not a verdict. What to do first (call with the numbers of
	 * Dolibarr, never the ones of the mail), what inbox saw (each signal a doubt, not a proof), why even a green mail
	 * may come from a mailbox whose password was stolen, and the check written as a note of the mail
	 */
	function bankBanner(m) {
		const b = m.bank;
		const mark = { ok: ['check', 'is-ok', 'InboxBankSignalOk'], warn: ['warning', 'is-warn', 'InboxBankSignalWarn'], bad: ['spoof', 'is-bad', 'InboxBankSignalBad'] };
		const signals = b.signals.map(x => {
			const k = mark[x.state] || mark.warn;
			return '<li class="ibx-bank__signal ' + k[1] + '">' + ic(k[0]) + '<span class="ibx-sr-only">' + th(k[2]) + ' </span><span>' + th('InboxBankSignal_' + x.id, ...(x.args || [])) + '</span></li>';
		}).join('');
		const call = b.call.length
			? '<ul class="ibx-bank__call">' + b.call.map(c => '<li><a class="ibx-btn ibx-btn--sm" href="tel:' + esc(c.number.replace(/[^\d+]/g, '')) + '">' + ic('phone') + ' ' + esc(c.number) + '</a> <span class="ibx-muted">' + esc(c.who) + (c.mobile ? ' · ' + th('InboxBankMobile') : '') + '</span></li>').join('') + '</ul>'
			: '<p>' + th(b.known ? 'InboxBankNoNumber' : 'InboxBankNoCard') + '</p>';
		// Folded once read (UX fix series): only the title line stays, the mail gets back its height; remembered per mail
		const folded = bankFolded(m.id);
		const bodyId = 'ibx-bank-body-' + m.id;
		return '<div class="ibx-banner ibx-banner--' + b.level + ' ibx-bank' + (folded ? ' is-folded' : '') + '" role="' + (b.level === 'danger' ? 'alert' : 'status') + '">' + ic(b.level === 'danger' ? 'spoof' : 'warning')
			+ '<div class="ibx-banner__text">'
			+ '<div class="ibx-bank__head"><span><b>' + th('InboxBankTitle') + '</b>' + (b.ibans.length ? ' <span class="ibx-mono">' + esc(b.ibans.join(', ')) + '</span>' : '') + '</span>'
			+ '<button type="button" class="ibx-btn ibx-btn--sm ibx-btn--ghost ibx-bank__fold ibx-js-bankfold" aria-expanded="' + !folded + '" aria-controls="' + bodyId + '">'
			+ ic(folded ? 'chevron-down' : 'chevron-up') + ' <span>' + th(folded ? 'InboxBankUnfold' : 'InboxBankFold') + '</span></button></div>'
			+ '<div class="ibx-bank__body" id="' + bodyId + '"' + (folded ? ' hidden' : '') + '>'
			+ '<p>' + th(b.level === 'danger' ? 'InboxBankLeadDanger' : 'InboxBankLead') + '</p>'
			+ '<div class="ibx-bank__block"><b>' + th('InboxBankCallTitle') + '</b>' + call + '<p class="ibx-muted">' + th('InboxBankCallWhy') + '</p></div>'
			+ '<details class="ibx-bank__block"' + (b.warnings ? ' open' : '') + '><summary>' + th('InboxBankSeen', b.warnings) + '</summary><ul class="ibx-bank__signals">' + signals + '</ul>'
			+ '<p class="ibx-bank__doubt">' + ic('info') + ' ' + th('InboxBankEvenGreen') + '</p></details>'
			+ '<details class="ibx-bank__block"><summary>' + th('InboxBankWhyTitle') + '</summary><p>' + th('InboxBankWhyText') + '</p><ol class="ibx-bank__steps">'
			+ ['1', '2', '3', '4', '5'].map(n => '<li>' + th('InboxBankStep' + n) + '</li>').join('') + '</ol></details>'
			+ '<div class="ibx-bank__foot"><button type="button" class="ibx-btn ibx-btn--sm ibx-js-bankchecked">' + ic('note') + ' ' + th('InboxBankChecked') + '</button></div>'
			+ '</div></div></div>';
	}
	/** Mails whose bank box was folded, per browser (a convenience: lost storage only shows the box again) */
	const BANK_FOLD_MAX = 300;
	function bankFoldList() {
		try {
			const list = JSON.parse(localStorage.getItem(I.scope('inbox-bankfold')) || '[]');
			return Array.isArray(list) ? list : [];
		} catch (e) {
			return [];
		}
	}
	function bankFolded(id) {
		return bankFoldList().includes(+id);
	}
	function setBankFolded(id, folded) {
		const list = bankFoldList().filter(x => x !== +id);
		if (folded) {
			list.push(+id);
		}
		try {
			localStorage.setItem(I.scope('inbox-bankfold'), JSON.stringify(list.slice(-BANK_FOLD_MAX)));
		} catch (e) {
			// Storage refused: folded for this display only
		}
	}
	/** Folds or unfolds the bank box of a reading pane, without rendering the mail again */
	function foldBank(box, m, folded) {
		const btn = $('.ibx-js-bankfold', box);
		box.classList.toggle('is-folded', folded);
		$('.ibx-bank__body', box).hidden = folded;
		btn.setAttribute('aria-expanded', String(!folded));
		btn.innerHTML = ic(folded ? 'chevron-down' : 'chevron-up') + ' <span>' + th(folded ? 'InboxBankUnfold' : 'InboxBankFold') + '</span>';
		setBankFolded(m.id, folded);
	}
	/** « I checked by phone »: the number called (one of the cards) and the person, written as a note of the mail */
	function bankCheckedDialog(m) {
		const nums = (m.bank.call || []);
		dialog({
			title: t('InboxBankChecked'),
			body: '<form class="ibx-form" novalidate><p>' + th('InboxBankCheckedHelp') + '</p>'
				+ (nums.length ? '<div class="ibx-formrow"><span class="ibx-formrow__label">' + th('InboxBankCheckedNumber') + '</span><div>'
					+ nums.map((c, i) => '<label class="ibx-radio"><input type="radio" name="number" value="' + esc(c.number) + '"' + (i ? '' : ' checked') + '> ' + esc(c.number) + ' <span class="ibx-muted">' + esc(c.who) + '</span></label>').join('') + '</div></div>'
					: '<div class="ibx-formrow"><label class="ibx-formrow__label" for="f-banknum">' + th('InboxBankCheckedNumber') + '</label><div><input class="ibx-field" id="f-banknum" name="number" type="text" autocomplete="off"><div class="ibx-formrow__help">' + th('InboxBankCheckedNumberHelp') + '</div></div></div>')
				+ '<div class="ibx-formrow"><label class="ibx-formrow__label" for="f-bankwho">' + th('InboxBankCheckedWho') + '</label><div><input class="ibx-field" id="f-bankwho" name="who" type="text" autocomplete="off" required></div></div>'
				+ '</form>',
			actions: [{ label: t('InboxBankCheckedSave'), primary: true, run: async dlg => {
				const f = $('form', dlg);
				const num = ($('[name="number"]:checked', f) || $('[name="number"]', f) || {}).value || '';
				const who = $('[name="who"]', f).value.trim();
				if (!num.trim() || !who) {
					toast(t('InboxBankCheckedMissing'), { error: true });
					return false;
				}
				try {
					await post('dolibarr', 'note', { id: m.id, text: t('InboxBankCheckedNote', num.trim(), who) });
				} catch (err) {
					toast(err.message, { error: true });
					return false;
				}
				toast(t('InboxBankCheckedDone'));
				// Checked: the box folds, the mail is what is left to read
				const bank = $('.ibx-bank', mailRoot(m));
				if (bank) {
					foldBank(bank, m, true);
				}
				const box = $('.ibx-dolibar', mailRoot(m));
				if (box) {
					loadDolibar(box);
				}
			} }],
		});
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
			+ (warn ? ' · <span class="ibx-attachment__warn">' + esc(a.warnings.map(w => t(w)).join(', ')) + '</span>' : '') + '</span>'
			// Verdict of the antivirus, asked when the mail opens (1.45.0)
			+ '<span class="ibx-attachment__verdict"></span></span></button>'
			+ '<button type="button" class="ibx-btn ibx-btn--icon ibx-btn--sm ibx-js-attdownload" title="' + th('InboxDownload') + '" aria-label="' + th('InboxDownload') + ' ' + esc(a.name) + '">' + ic('download') + '</button></div>';
	}

	/**
	 * Actions of the mail in the head row of the reading pane, beside the back button (narrow screens, 1.100.0): that row
	 * never scrolls, the buttons stay at hand; the bar of the head of the mail is hidden there by the CSS
	 * @param {object|null} m Mail open, null: no mail
	 */
	function syncPaneBar(m) {
		const bar = $('.ibx-js-panebar', S.el);
		if (!bar) {
			return;
		}
		bar.innerHTML = m ? actionButtons(m) : '';
		if (m) {
			bindActions(bar);
		}
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
			more.addEventListener('click', () => {
				const all = actions(S.mail);
				const minor = isSmallPhone() ? all.filter(a => a.button && ACT_MINOR.includes(a.id)) : [];
				const items = minor.concat(minor.length ? [{ sep: true }] : [], all.filter(a => !a.button && !(a.id === 'dolibarpos' && isPhone())));
				menu(more, items.map(a => a.sep ? a : ({ icon: a.icon, label: a.label, key: a.key, run: a.run, disabled: a.disabled, reason: a.reason }))
					.filter((a, i, list) => !(a.sep && (i === 0 || i === list.length - 1 || list[i - 1].sep))));
			});
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
		const bankChecked = $('.ibx-js-bankchecked', pane);
		if (bankChecked) {
			bankChecked.addEventListener('click', () => bankCheckedDialog(m));
		}
		const bankFold = $('.ibx-js-bankfold', pane);
		if (bankFold) {
			bankFold.addEventListener('click', () => foldBank(bankFold.closest('.ibx-bank'), m, bankFold.getAttribute('aria-expanded') === 'true'));
		}
		const notJunk = $('.ibx-js-notjunk', pane);
		if (notJunk) {
			notJunk.addEventListener('click', () => doJunk([m.id], false));
		}
		const isJunk = $('.ibx-js-isjunk', pane);
		if (isJunk) {
			isJunk.addEventListener('click', () => doJunk([m.id], true));
		}
		// Delivery report <-> mail sent (1.25.0)
		$$('.ibx-js-bouncego', pane).forEach(b => b.addEventListener('click', () => Dolibarr.executeHook('inbox:mail:goto', { folder: +b.dataset.folder, mail: +b.dataset.mail })));
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
			const items = [{ label: t('InboxAlwaysForSender', m.from.email), run: () => allowImages(m, 'sender') }];
			if (m.body.always !== 'sender') {
				items.push({ label: t('InboxAlwaysForDomain', domain), run: () => allowImages(m, 'domain') });
			}
			allow.addEventListener('click', () => menu(allow, items));
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
			const list = $('.ibx-attachments', pane);
			$('.ibx-js-attsaveall', pane).addEventListener('click', () => saveAll(m));
			$('.ibx-js-attsheetclose', pane).addEventListener('click', () => closeSheet(list));
			$('.ibx-js-attsheet', pane).addEventListener('click', e => openSheet(list, e.currentTarget, '.ibx-js-attsheethead', '.ibx-js-attsheetclose'));
			$('.ibx-attachments', pane).addEventListener('click', e => {
				const tileEl = e.target.closest('[data-att]');
				if (!tileEl) {
					return;
				}
				const a = m.attachments[+tileEl.dataset.att];
				// The verdict asked at the opening: waited for, never a second analysis meanwhile
				if (m.scan && m.scan.pending) {
					const ctrl = e.ctrlKey || e.metaKey;
					const down = !!e.target.closest('.ibx-js-attdownload');
					m.scan.promise.then(() => {
						if (down) {
							guardedDownload(m, a);
						} else if (ctrl) {
							openInTab(m, +tileEl.dataset.att);
						} else {
							openViewer({ mail: m, index: +tileEl.dataset.att });
						}
					});
					return;
				}
				if (e.target.closest('.ibx-js-attdownload')) {
					guardedDownload(m, a);
				} else if (e.ctrlKey || e.metaKey) {
					// Ctrl+click (Cmd on a Mac): in a new tab (1.45.0)
					openInTab(m, +tileEl.dataset.att);
				} else {
					openViewer({ mail: m, index: +tileEl.dataset.att });
				}
			});
			// An infected file is never dragged out (to a piece, the desktop)
			$('.ibx-attachments', pane).addEventListener('dragstart', e => {
				if (e.target.closest && e.target.closest('.ibx-attachment.is-infected')) {
					e.preventDefault();
					e.stopPropagation();
				}
			}, true);
			scanAttachments(m, pane);
			// Middle click: in a new tab too
			$('.ibx-attachments', pane).addEventListener('auxclick', e => {
				const tileEl = e.button === 1 && !e.target.closest('.ibx-js-attdownload') ? e.target.closest('[data-att]') : null;
				if (tileEl) {
					e.preventDefault();
					openInTab(m, +tileEl.dataset.att);
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
		// Many mail templates (Foundation for Emails: Nextcloud...) set html{min-height:100%}: inside a frame fitted to
		// its content, the document followed the frame, the frame followed the document + 8 px, forever (endless
		// scroll). The height of the document is made free of the frame, the margin is inside the document.
		if (!doc.getElementById('ibx-fit-style') && doc.head) {
			const st = doc.createElement('style');
			st.id = 'ibx-fit-style';
			st.textContent = 'html,body{min-height:0!important;height:auto!important}html{padding-bottom:8px!important}';
			doc.head.appendChild(st);
		}
		// Height of the content as drawn (zoom included): the box of the document, not its scrollHeight (never below
		// the height of the frame, so a frame never got smaller, and without the zoom). A height equal to the frame is
		// kept: a content still tied to the frame (100vh inside the mail) can no longer grow it step by step.
		const fit = () => {
			const h = Math.max(120, Math.ceil(doc.documentElement.getBoundingClientRect().height));
			if (h !== Math.round(parseFloat(frame.style.height) || 0)) {
				frame.style.height = h + 'px';
			}
		};
		fit();
		// Called again at each change of zoom: the listeners are bound once per document
		if (doc.ibxBound) {
			return;
		}
		doc.ibxBound = true;
		if (typeof ResizeObserver === 'function' && doc.body) {
			// Safety net: a frame growing at every frame drawn (content tied to the height of the frame) stops being
			// followed after 30 growths without a pause of 1 s; pictures loading never come so close together
			let grows = 0;
			let calm = 0;
			const ro = new ResizeObserver(() => {
				const before = parseFloat(frame.style.height) || 0;
				fit();
				if ((parseFloat(frame.style.height) || 0) > before) {
					clearTimeout(calm);
					calm = setTimeout(() => {
						grows = 0;
					}, 1000);
					if (++grows > 30) {
						ro.disconnect();
					}
				}
			});
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
			// Recognized domains (1.59.0): a lookalike of a recognized domain is asked first
			const verdicts = m.link_hosts || {};
			const vReal = verdicts[real];
			if (vReal && vReal.t === 'lookalike') {
				e.preventDefault();
				dialog({
					title: t('InboxLookalikeLinkTitle'),
					body: '<div class="ibx-notice ibx-notice--warning">' + ic('spoof') + '<div class="ibx-notice__text">' + (vReal.own ? th('InboxLookalikeOwnLink', vReal.domain, real) : th('InboxLookalikeLink', vReal.domain, vReal.label, real)) + '</div></div>'
						+ '<p class="ibx-muted ibx-breakall">' + esc(href) + '</p>',
					actions: [{ label: t('InboxOpenAnyway'), run: () => window.open(href, '_blank', 'noopener,noreferrer') }],
				});
				return;
			}
			const vClaimed = verdicts[claimed];
			const sameOrganisation = vReal && vClaimed && vReal.t === 'trusted' && vClaimed.t === 'trusted' && vReal.label === vClaimed.label;
			if (real && claimed && !sameSite(real, claimed) && !sameOrganisation) {
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
		// Real address of a link at hover and focus (1.59.0): a bar of inbox outside the mail (the HTML of a mail cannot
		// draw over it), with the recognized domain or the lookalike; on a phone, at a long press
		const linkOf = target => (target && target.closest ? target.closest('a[href]') : null);
		doc.addEventListener('mouseover', e => linkBar(frame, m, linkOf(e.target)));
		doc.addEventListener('focusin', e => linkBar(frame, m, linkOf(e.target)));
		doc.documentElement.addEventListener('mouseleave', () => linkBar(frame, m, null));
		let press = 0;
		doc.addEventListener('touchstart', e => {
			const a = linkOf(e.target);
			clearTimeout(press);
			if (a) {
				press = setTimeout(() => {
					linkBar(frame, m, a);
					press = setTimeout(() => linkBar(frame, m, null), 4000);
				}, 450);
			}
		}, { passive: true });
		doc.addEventListener('touchmove', () => clearTimeout(press), { passive: true });
	}

	/**
	 * Bar of the link under the pointer (1.59.0), in the reading pane outside the frame of the mail: host in bold,
	 * path cut, the organisation of a recognized domain or the warning of a lookalike. Null link: hidden.
	 */
	function linkBar(frame, m, a) {
		const host = frame.closest('.ibx-pane--read') || frame.closest('dialog') || document.body;
		let bar = host.querySelector(':scope > .ibx-linkbar');
		if (!a) {
			if (bar) {
				bar.hidden = true;
			}
			return;
		}
		if (!bar) {
			bar = document.createElement('div');
			bar.className = 'ibx-linkbar' + (host === document.body ? ' ibx-linkbar--fixed' : '');
			bar.setAttribute('role', 'status');
			bar.setAttribute('aria-live', 'polite');
			host.appendChild(bar);
		}
		const href = a.getAttribute('href') || '';
		let html = '';
		if (/^(mailto|tel):/i.test(href)) {
			html = '<b>' + esc(decodeURIComponent(href.replace(/^[a-z]+:/i, '').split('?')[0])) + '</b>';
		} else if (/^https?:\/\//i.test(href)) {
			let url = null;
			try {
				url = new URL(href);
			} catch (e) {
				url = null;
			}
			if (!url) {
				return;
			}
			const rest = (url.pathname === '/' ? '' : url.pathname) + url.search;
			html = '<span class="ibx-linkbar__url"><b>' + esc(url.hostname) + '</b>' + (rest ? '<span class="ibx-muted">' + esc(rest.length > 60 ? rest.slice(0, 59) + '…' : rest) + '</span>' : '') + '</span>';
			const v = (m.link_hosts || {})[hostOf(href)];
			if (v && v.t === 'trusted') {
				html += '<span class="ibx-linkbar__ok">' + ic('trusted') + ' ' + esc(v.label) + '</span>';
			} else if (v && v.t === 'lookalike') {
				html += '<span class="ibx-linkbar__warn">' + ic('warning') + ' ' + (v.own ? th('InboxLookalikeOwnShort', v.domain) : th('InboxLookalikeShort', v.label)) + '</span>';
			}
		} else {
			bar.hidden = true;
			return;
		}
		bar.innerHTML = html;
		bar.hidden = false;
	}

	/** Badge of the recognized domain of an authenticated sender (1.59.0); its details on click */
	function trustedBadge(tr) {
		return '<button type="button" class="ibx-trustbadge ibx-js-trustinfo" aria-haspopup="dialog" data-trust="' + esc(JSON.stringify(tr)) + '" title="' + th('InboxTrustedTooltip', tr.domain, tr.by) + '">'
			+ ic('trusted') + '<span>' + esc(tr.label) + '</span></button>';
	}

	function trustInfo(b) {
		let tr;
		try {
			tr = JSON.parse(b.dataset.trust || '{}');
		} catch (e) {
			return;
		}
		const day = ts => new Date(ts * 1000).toLocaleDateString(locale());
		dialog({
			title: tr.label,
			body: '<p>' + ic('trusted') + ' ' + th('InboxTrustedInfoDomain', tr.domain) + (tr.host !== tr.domain ? ' <span class="ibx-muted">(' + esc(tr.host) + ')</span>' : '') + '</p>'
				+ '<p>' + th('InboxTrustedInfoAuth', tr.by) + '</p>'
				+ (tr.reply_to ? '<div class="ibx-notice ibx-notice--warning">' + ic('warning') + '<div class="ibx-notice__text">' + th('InboxTrustedInfoReplyTo', tr.reply_to) + '</div></div>' : '')
				+ (tr.note ? '<p class="ibx-muted">' + esc(tr.note) + '</p>' : '')
				+ '<p class="ibx-muted">' + th('InboxTrustedInfoAdded', tr.user || '-', tr.date ? day(tr.date) : '-') + '</p>'
				+ '<p class="ibx-muted">' + th('InboxTrustedInfoScope') + '</p>',
		});
	}

	/**
	 * « Recognize this domain… » (1.59.0): proposed, then validated. The domain of the authenticated sender checked,
	 * the domains of the links offered unchecked; nothing written before « Recognize »
	 */
	function trustDialog(m) {
		const offer = m.trust_offer || {};
		const auth = m.auth || {};
		const res = v => '<span class="ibx-status' + (v === 'pass' ? ' ibx-status--ok' : (v === 'fail' || v === 'softfail' ? ' ibx-status--error' : '')) + '">' + esc(v || '-') + '</span>';
		// A lookalike of a recognized domain is offered unchecked, with the warning (never a font where 1 reads l)
		const likeNote = like => like ? '<div class="ibx-trustlike">' + ic('warning') + ' ' + th('InboxTrustDialogLookalike', like.domain, like.label) + '</div>' : '';
		const check = (value, checked, like, extra) => '<label class="ibx-check"><input type="checkbox" name="domains" value="' + esc(value) + '"' + (checked && !like ? ' checked' : '') + '> <b>' + esc(value) + '</b>' + (extra ? ' <span class="ibx-muted">' + extra + '</span>' : '') + '</label>' + likeNote(like);
		let body = '<p class="ibx-section__help">' + th('InboxTrustDialogHelp') + '</p><form class="ibx-form" novalidate>';
		if (!offer.sender && offer.sender_why) {
			// Not offered, and why (own domain, open service, not authenticated)
			body += '<div class="ibx-formrow"><span class="ibx-formrow__label">' + th('InboxSender') + '</span><div class="ibx-muted">' + th(offer.sender_why, (m.from.email || '').split('@')[1] || '') + '</div></div>';
		}
		if (offer.sender) {
			body += '<div class="ibx-formrow"><span class="ibx-formrow__label">' + th('InboxSender') + '</span><div>'
				+ check(offer.sender.domain, true, offer.sender.like, th('InboxTrustDialogAuth') + ' ' + I.term('spf', 'SPF') + ' ' + res(auth.spf) + ' · ' + I.term('dkim', 'DKIM') + ' ' + res(auth.dkim) + ' · ' + I.term('dmarc', 'DMARC') + ' ' + res(auth.dmarc))
				+ (offer.sender.parent ? '<div>' + check(offer.sender.parent, false, null, th('InboxTrustDialogParent')) + '</div>' : '') + '</div></div>';
		}
		if ((offer.links || []).length) {
			body += '<div class="ibx-formrow"><span class="ibx-formrow__label">' + th('InboxTrustDialogLinks') + '</span><div>'
				+ offer.links.map(l => '<div>' + check(l.domain, false, l.like, th('InboxTrustDialogLinkCount', l.links)) + '</div>').join('')
				+ '<div class="ibx-formrow__help">' + th('InboxTrustDialogLinksHelp') + '</div></div></div>';
		}
		body += '<div class="ibx-formrow"><span class="ibx-formrow__label"></span><div><label class="ibx-check"><input type="checkbox" name="subdomains" checked> ' + th('InboxTrustedWithSubdomains') + '</label></div></div>'
			+ '<div class="ibx-formrow"><label class="ibx-formrow__label" for="ibx-trust-label">' + th('InboxTrustedLabel') + ' <span class="ibx-req" aria-hidden="true">*</span></label><div>'
			+ '<input class="ibx-field" id="ibx-trust-label" name="label" required autocomplete="off" spellcheck="false" value="' + esc(m.from.name || '') + '"><div class="ibx-formrow__help">' + th('InboxTrustedLabelHelp') + '</div></div></div>'
			+ '<div class="ibx-formrow"><label class="ibx-formrow__label" for="ibx-trust-note">' + th('InboxTrustedNote') + '</label><div>'
			+ '<input class="ibx-field" id="ibx-trust-note" name="note" autocomplete="off" spellcheck="false"><div class="ibx-formrow__help">' + th('InboxTrustedNoteHelp') + '</div></div></div>'
			+ '</form>';
		dialog({
			title: t('InboxTrustDialogTitle'),
			wide: true,
			body,
			actions: [{ label: t('InboxTrustDialogButton'), primary: true, run: async dlg => {
				const form = $('form', dlg);
				const domains = $$('input[name="domains"]:checked', form).map(i => i.value);
				const label = form.elements.label.value.trim();
				if (!domains.length) {
					toast(t('InboxTrustDialogNone'), { error: true });
					return false;
				}
				if (!label) {
					form.elements.label.focus();
					toast(t('InboxTrustedErrorLabel'), { error: true });
					return false;
				}
				try {
					await post('trusteddomain', 'create', { domains, label, note: form.elements.note.value, subdomains: form.elements.subdomains.checked ? 1 : 0 });
				} catch (err) {
					toast(err.message, { error: true });
					return false;
				}
				toast(t('InboxTrustDialogDone', domains.join(', ')));
				// The mail read again: its sender and its links with the new list
				if (S.openId === m.id) {
					openMail(m.id, true);
				}
			} }],
			onOpen: dlg => $('#ibx-trust-label', dlg).select(),
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

	/**
	 * A mail of the fil (an answer sent) read in a dialog: its head and its body with the same protections as the
	 * reading (body.php, sandbox, remote pictures as chosen for its sender); « Go to the email » opens it for good
	 */
	async function sentMailDialog(folder, id) {
		let m;
		try {
			m = await api('mail', 'open', { id });
		} catch (err) {
			toast(err.message, { error: true });
			return;
		}
		const people = (m.to || []).concat(m.cc || []).map(a => a.name ? a.name + ' <' + a.email + '>' : a.email).join(', ');
		const files = (m.attachments || []).map(a => a.name).join(', ');
		const row = (label, value) => value ? '<dt>' + th(label) + '</dt><dd>' + esc(value) + '</dd>' : '';
		dialog({
			title: m.subject || t('InboxNoSubject'),
			wide: true,
			cancelLabel: t('InboxCloseWindow'),
			body: '<dl class="ibx-activity__dl ibx-sentview__head">' + row('InboxActivityToLabel', people) + row('InboxActivityOn', longDate(m.date))
				+ row('InboxAttachments', files) + '</dl>'
				+ (m.security && m.security.encrypted ? '<p class="ibx-muted">' + th('InboxEncrypted') + '</p>'
					: '<iframe class="ibx-sentview__frame" title="' + th('InboxMailBody') + '" sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox"'
						+ ' referrerpolicy="no-referrer" src="' + bodyUrl(m, m.body.images) + '"></iframe>'),
			actions: [{ label: t('InboxActivityGoToMail'), primary: true, run: () => {
				Dolibarr.executeHook('inbox:mail:goto', { folder, mail: id });
			} }],
		});
	}

	function headersDialog(m) {
		const auth = m.auth || {};
		const res = v => v ? '<span class="ibx-status' + (v === 'pass' ? ' ibx-status--ok' : (v === 'fail' || v === 'softfail' ? ' ibx-status--error' : '')) + '">' + esc(v) + '</span>' : '<span class="ibx-muted">-</span>';
		dialog({
			title: t('InboxAllHeaders'),
			wide: true,
			body: '<p>' + I.term('spf', 'SPF') + ' ' + res(auth.spf) + ' · ' + I.term('dkim', 'DKIM') + ' ' + res(auth.dkim) + ' · ' + I.term('dmarc', 'DMARC') + ' ' + res(auth.dmarc) + '</p>'
				// Anti-spam headers that came with the mail, below its entry into our servers (1.86.1): never believed
				+ ((m.spam_ignored || []).length ? '<div class="ibx-notice ibx-notice--info">' + ic('info') + '<div class="ibx-notice__text">' + th('InboxSpamIgnored', m.spam_ignored.join(', ')) + '</div></div>' : '')
				+ '<div class="ibx-headers"><table class="ibx-table"><tbody>' + (m.headers || []).map(h => '<tr><th>' + esc(h[0]) + '</th><td class="ibx-breakall">' + esc(h[1]) + '</td></tr>').join('') + '</tbody></table></div>',
			noCancel: true,
			actions: [{ label: t('InboxCloseWindow'), primary: true, run: () => true }],
			// The focus on « Close », never on the first term (its bubble would open by itself)
			onOpen: dlg => $('.ibx-dialog__foot .ibx-btn--primary', dlg).focus(),
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

	/**
	 * Drag of an attachment out of the browser (desktop, folder of the file manager), as a mail client does:
	 * value of DownloadURL (Chrome, Edge), « type:name:absolute url », the browser downloads it with the session.
	 * Only a file the antivirus found clean and without warning: the drag cannot wait for a verdict nor show the
	 * warning of a file not scanned or risky, the download button stays the way for those.
	 * @param {object} m Mail
	 * @param {object} a Attachment
	 * @returns {string} Value, '' when the file may not leave by a drag
	 */
	function dragOutUrl(m, a) {
		if (!a || m.compose || !m.scan || m.scan.pending || !m.scan.parts) {
			return '';
		}
		const v = m.scan.parts.find(p => +p.part === +a.part);
		if (!v || v.blocked || v.warn || (v.warnings || []).length || (a.warnings || []).length) {
			return '';
		}
		// The colon separates the three fields
		const name = String(a.name || 'file').replace(/[:\\/]/g, '_');
		return 'application/octet-stream:' + name + ':' + new URL(attUrl(m, a, 'download'), location.href).href;
	}

	function attUrl(m, a, mode, force) {
		// A file of a writing (lot A5, 1.82.0): the very file that leaves with the mail, same answers and protections
		if (m.compose) {
			return I.ctx('INBOX_AJAX_URL', '') + 'composefile.php?compose=' + encodeURIComponent(m.compose) + '&att=' + encodeURIComponent(a.part) + '&mode=' + mode + (force ? '&force=1' : '');
		}
		return I.ctx('INBOX_AJAX_URL', '') + 'attachment.php?id=' + m.id + '&part=' + a.part + '&mode=' + mode + (force ? '&force=1' : '');
	}
	/** Kind of a file by its name and type, as the server gives it for the attachments of a mail */
	function fileKind(name, mime) {
		const n = String(name || '').toLowerCase();
		const t = String(mime || '').toLowerCase();
		if (t === 'application/pdf' || /\.pdf$/.test(n)) {
			return 'pdf';
		}
		if (/^image\/(png|jpe?g|gif|webp)$/.test(t) || /\.(png|jpe?g|gif|webp)$/.test(n)) {
			return 'image';
		}
		if (/\.csv$/.test(n) || t === 'text/csv') {
			return 'csv';
		}
		if (/^text\//.test(t) || /\.(txt|log|md|json|xml)$/.test(n)) {
			return 'text';
		}
		if (/\.zip$/.test(n) || t === 'application/zip') {
			return 'zip';
		}
		return /\.eml$/.test(n) || t === 'message/rfc822' ? 'mail' : 'other';
	}
	/** Verdict, text or files of one attachment, of a mail or of a writing */
	function attApi(m, part, mode, force) {
		return m.compose ? api('composefile', mode, { compose: m.compose, att: part, mode, force: force ? 1 : 0 })
			: api('attachment', mode, { id: m.id, part, mode, force: force ? 1 : 0 });
	}

	/**
	 * Viewers of other modules for the attachments of a mail (hook inboxAttachmentViewers, C1): part => list of
	 * {code, label, url}, asked once per mail. Inbox does not know the modules, only what they declare.
	 * @param {object} m Mail
	 * @returns {Promise<object>}
	 */
	function viewersOf(m) {
		return openersOf(m).then(r => r.viewers);
	}
	/** Editors of other modules for an attachment, asked with the viewers (C2: annotate and file in a piece) */
	function editorsOf(m) {
		return openersOf(m).then(r => r.editors);
	}
	function openersOf(m) {
		if (!m._openers && m.compose) {
			// A file of a writing: the viewers of inbox only
			m._openers = Promise.resolve({ viewers: {}, editors: {} });
		}
		if (!m._openers) {
			m._openers = api('attachment', 'viewers', { id: m.id, part: -1, mode: 'viewers' })
				.then(r => ({ viewers: (r && r.viewers) || {}, editors: (r && r.editors) || {} }))
				.catch(() => ({ viewers: {}, editors: {} }));
		}
		return m._openers;
	}

	/** Time after which the summary says the analysis is still running */
	const SCAN_LONG = 5000;
	/**
	 * Antivirus of the attachments asked when the mail opens (1.45.0): every tile shows its verdict (virus, risky,
	 * not scanned), the head of the bar a summary; a click meanwhile waits for it (m.scan.promise).
	 * An antivirus turned off says nothing per file: the warnings of the name and content stay the only marks.
	 * @param {object} m Mail
	 * @param {HTMLElement} pane Reading pane
	 */
	function scanAttachments(m, pane) {
		const box = $('.ibx-attachments', pane);
		const status = $('.ibx-js-attscan', pane);
		if (!box || !status || !(m.attachments || []).length) {
			return;
		}
		const tiles = $$('.ibx-attachment', box);
		const say = (html, cls) => {
			status.className = 'ibx-attbar__scan ibx-js-attscan' + (cls ? ' ' + cls : '');
			status.innerHTML = html;
		};
		const scanning = on => tiles.forEach(el => {
			el.classList.toggle('is-scanning', on);
			const vd = $('.ibx-attachment__verdict', el);
			if (vd && on) {
				vd.innerHTML = '<span class="ibx-spinner" aria-hidden="true"></span>' + esc(t('InboxAttScanning'));
			}
		});
		const run = () => {
			scanning(true);
			say('<span class="ibx-spinner" aria-hidden="true"></span>' + esc(t('InboxAttScanning')), 'is-running');
			const long = setTimeout(() => {
				if (m.scan && m.scan.pending) {
					say('<span class="ibx-spinner" aria-hidden="true"></span>' + esc(t('InboxAttScanLong')), 'is-running');
				}
			}, SCAN_LONG);
			const scan = { pending: true };
			m.scan = scan;
			scan.promise = api('attachment', 'check', { id: m.id, part: -1, mode: 'check' }).then(res => {
				scan.parts = res.parts || [];
				markTiles(m, tiles, scan.parts, say);
			}, () => {
				scanning(false);
				tiles.forEach(el => {
					const vd = $('.ibx-attachment__verdict', el);
					if (vd) {
						vd.textContent = '';
					}
				});
				say(esc(t('InboxAttScanFailed')) + ' <button type="button" class="ibx-linkbtn ibx-js-attretry">' + esc(t('InboxAttRetry')) + '</button>', 'is-failed');
				const retry = $('.ibx-js-attretry', status);
				if (retry) {
					retry.addEventListener('click', e => {
						e.stopPropagation();
						run();
					});
				}
			}).finally(() => {
				clearTimeout(long);
				scan.pending = false;
			});
		};
		// The pane drawn again (same mail): the verdict already known, no second analysis
		if (m.scan && m.scan.parts) {
			markTiles(m, tiles, m.scan.parts, say);
			return;
		}
		run();
	}

	/**
	 * Marks of the tiles after the analysis, and the summary
	 * @param {object} m Mail
	 * @param {HTMLElement[]} tiles Tiles (data-att = index in m.attachments)
	 * @param {object[]} parts Verdicts {part, state, message, warnings, blocked, warn}
	 * @param {Function} say Writer of the summary (html, class)
	 */
	function markTiles(m, tiles, parts, say) {
		let infected = 0;
		let risky = 0;
		let off = false;
		tiles.forEach(el => {
			const a = m.attachments[+el.dataset.att];
			const v = a ? parts.find(p => +p.part === +a.part) : null;
			const vd = $('.ibx-attachment__verdict', el);
			el.classList.remove('is-scanning', 'is-infected', 'is-risky', 'is-unscanned');
			if (vd) {
				vd.textContent = '';
			}
			if (!v) {
				return;
			}
			a.verdict = v;
			const warnings = (v.warnings || []).length || (a.warnings || []).length;
			let label = '';
			if (v.blocked) {
				infected++;
				el.classList.add('is-infected');
				el.setAttribute('draggable', 'false');
				label = t('InboxAttVirus');
			} else if (warnings) {
				risky++;
				el.classList.add('is-risky');
				label = t('InboxAttRiskyTitle');
			} else if (v.warn) {
				risky++;
				el.classList.add('is-unscanned');
				label = t('InboxAttNotScanned');
			}
			if (v.state === 'off') {
				off = true;
			}
			if (vd && label) {
				vd.innerHTML = ic(v.blocked ? 'virus' : 'virus-off') + esc(label);
			}
			const open = $('.ibx-js-attopen', el);
			if (open) {
				open.title = a.name + (label ? ' · ' + label + (v.blocked && v.message ? ' (' + v.message + ')' : '') : '');
			}
		});
		const badges = (infected ? '<span class="ibx-attbadge ibx-attbadge--danger">' + ic('virus') + esc(t('InboxAttBadgeVirus', infected)) + '</span>' : '')
			+ (risky ? '<span class="ibx-attbadge ibx-attbadge--warning">' + ic('virus-off') + esc(t('InboxAttBadgeCheck', risky)) + '</span>' : '');
		if (badges) {
			say(badges, 'is-done');
		} else if (off) {
			// No antivirus on the server: nothing said, a neutral « not analysed » would be noise on every mail
			say('', '');
		} else {
			say(ic('check') + esc(t('InboxAttNoVirus')), 'is-clean');
			setTimeout(() => say('', ''), 4000);
		}
	}

	/** Antivirus first: infected = never, not scanned = warning and « Download anyway » */
	async function guardedDownload(m, a) {
		let v;
		try {
			v = await attApi(m, a.part, 'check');
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
		// A risky file (double extension, program, PDF with scripts): asked once more before it leaves the mailbox
		const warnings = (v.warnings && v.warnings.length ? v.warnings : a.warnings) || [];
		if (warnings.length && !(await I.confirmDialog({
			title: t('InboxAttRiskyTitle'),
			text: t('InboxAttRiskyText', a.name) + ' ' + warnings.map(w => t(w)).join(', '),
			confirm: t('InboxDownloadAnyway'),
		}))) {
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
	/**
	 * Size and place of a viewer, kept per kind (1.45.0): « doc » for a PDF or a picture, « other » for a text, a mail;
	 * a size kept before 1.45.0 (one for all) is the one of the « other »
	 * @param {string} kind doc or other
	 * @returns {object} {x, y, w, h}, {} when none
	 */
	function savedGeometry(kind) {
		let all = {};
		try {
			all = JSON.parse(params.windows || '{}') || {};
		} catch (e) {
			all = {};
		}
		if (all.w && !all.doc && !all.other) {
			all = { other: all };
		}
		return all[kind] || {};
	}
	/** A PDF or a picture: the kind of viewer that wants room */
	function viewerKind(m, o) {
		const a = !o.source && m.attachments ? m.attachments[o.index] : null;
		// The viewer of another module, or an office document (shown by such a viewer): the size of a PDF
		return o.viewer || (a && (a.kind === 'pdf' || a.kind === 'image' || /\.(docx?|xlsx?|pptx?|od[tsp]|rtf)$/i.test(a.name || ''))) ? 'doc' : 'other';
	}
	/** Opening of a PDF by the viewer of the browser: the width of the page, no panel of thumbnails when it obeys */
	const PDF_OPEN = '#view=FitH&zoom=page-width&pagemode=none&navpanes=0';
	/**
	 * A PDF or a picture in a new tab (Ctrl+click, middle click, menu, 1.45.0): the antivirus first, as for the preview;
	 * the tab is opened at once (a tab opened after waiting is blocked by the browser), closed if the file may not be
	 * shown, the warning then in the viewer. Another kind is downloaded (a browser shows nothing else).
	 * @param {object} m Mail
	 * @param {number} index Attachment
	 */
	async function openInTab(m, index) {
		const a = m.attachments[index];
		if (!a || (a.kind !== 'pdf' && a.kind !== 'image')) {
			if (a) {
				guardedDownload(m, a);
			}
			return;
		}
		const tab = window.open('about:blank', '_blank');
		if (!tab) {
			openViewer({ mail: m, index });
			return;
		}
		tab.opener = null;
		let v;
		try {
			v = await attApi(m, a.part, 'check');
		} catch (err) {
			tab.close();
			toast(err.message, { error: true });
			return;
		}
		if (v.blocked || v.warn) {
			tab.close();
			openViewer({ mail: m, index });
			return;
		}
		tab.location.href = attUrl(m, a, 'inline', false) + (a.kind === 'pdf' ? PDF_OPEN : '');
	}

	/**
	 * @param {object} o {mail, index (attachment), source (bool)}
	 */
	function openViewer(o) {
		const m = o.mail;
		const key = m.id + ':' + (o.source ? 'source' : o.index) + (o.viewer ? ':' + o.viewer.code : '');
		const existing = windows.find(w => w.key === key);
		if (existing) {
			restore(existing);
			front(existing);
			return;
		}
		const dlg = document.createElement('dialog');
		dlg.className = 'ibx-window';
		dlg.setAttribute('aria-modal', 'false');
		const kind = viewerKind(m, o);
		// A place kept without a size is the one of a closed window (before 1.48.1): not used
		const g = savedGeometry(kind).w ? savedGeometry(kind) : {};
		const n = windows.length;
		// A PDF or a picture: most of the screen the first time (an A4 page read in its width), else as before
		const dw = kind === 'doc' ? Math.round(Math.min(1100, window.innerWidth * 0.9)) : 720;
		const dh = kind === 'doc' ? Math.round(window.innerHeight * 0.9) : 560;
		const gw = g.w || dw;
		dlg.style.width = gw + 'px';
		dlg.style.height = (g.h || dh) + 'px';
		dlg.style.left = Math.max(0, Math.min(window.innerWidth - 300, (g.x ?? Math.round(window.innerWidth / 2 - gw / 2)) + n * 24)) + 'px';
		dlg.style.top = Math.max(0, Math.min(window.innerHeight - 200, (g.y ?? (kind === 'doc' ? Math.round(window.innerHeight * 0.05) : 60)) + n * 24)) + 'px';
		// A small screen: the whole screen at once
		if (isPhone()) {
			dlg.classList.add('ibx-window--maximized');
		}
		const w = { key, dlg, mail: m, index: o.index, source: !!o.source, kind, viewer: o.viewer || null };
		windows.push(w);
		document.body.appendChild(dlg);
		dlg.show();
		fillViewer(w);
		front(w);
		bindWindow(w);
	}

	/**
	 * Page of another module in a window of inbox (1.47.0): the mail stays seen behind. The page tells when it is done
	 * (postMessage, same origin): inbox then links the piece, sets the tags, archives the mail, with its own rights.
	 * Such a page may open one window of its own over it (1.48.0, « open »), modal: a dark backdrop puts what is under
	 * it out of reach; the two pages talk through inbox (« relay »).
	 * @param {object} o {mailId, box, url, title, panel, modal, opener (window that opens it)}
	 */
	function openModuleWindow(o) {
		const key = o.opener ? o.opener.key + ':child' : 'module:' + o.mailId + ':' + o.panel;
		const existing = windows.find(w => w.key === key);
		if (existing) {
			if ((o.opener || !o.mailId) && existing.frame.getAttribute('src') !== o.url) {
				existing.frame.src = o.url;
			}
			restore(existing);
			front(existing);
			return;
		}
		const kind = o.modal ? 'modal' : 'module';
		const dlg = document.createElement('dialog');
		dlg.className = 'ibx-window ibx-window--module' + (o.modal ? ' ibx-window--modal' : '');
		dlg.setAttribute('aria-modal', o.modal ? 'true' : 'false');
		dlg.setAttribute('aria-label', o.title);
		// Size kept, always opened in the middle of the screen (1.48.1)
		const g = savedGeometry(kind);
		const gw = Math.min(window.innerWidth - 16, g.w || Math.round(Math.min(o.modal ? 1100 : 1180, window.innerWidth * (o.modal ? 0.8 : 0.86))));
		const gh = Math.min(window.innerHeight - 16, g.h || Math.round(window.innerHeight * (o.modal ? 0.86 : 0.82)));
		dlg.style.width = gw + 'px';
		dlg.style.height = gh + 'px';
		dlg.style.left = Math.max(0, Math.round((window.innerWidth - gw) / 2)) + 'px';
		dlg.style.top = Math.max(0, Math.round((window.innerHeight - gh) / 2)) + 'px';
		if (isPhone()) {
			dlg.classList.add('ibx-window--maximized');
		}
		dlg.innerHTML = '<div class="ibx-window__bar"><span class="ibx-window__title ibx-ellipsis">' + esc(o.title) + '</span>'
			+ (o.modal ? '' : '<button type="button" class="ibx-btn ibx-btn--icon ibx-btn--sm ibx-js-wmin" title="' + th('InboxMinimize') + '" aria-label="' + th('InboxMinimize') + '">' + ic('minimize') + '</button>')
			+ '<button type="button" class="ibx-btn ibx-btn--icon ibx-btn--sm ibx-js-wmax" title="' + th('InboxMaximize') + '" aria-label="' + th('InboxMaximize') + '">' + ic('maximize') + '</button>'
			+ '<button type="button" class="ibx-btn ibx-btn--icon ibx-btn--sm ibx-js-wclose" title="' + th('InboxCloseWindow') + '" aria-label="' + th('InboxCloseWindow') + '">' + ic('close') + '</button></div>'
			+ '<div class="ibx-window__body"><iframe class="ibx-window__frame" title="' + esc(o.title) + '" src="' + esc(o.url) + '"></iframe></div>';
		const w = { key, dlg, module: true, title: o.title, mailId: o.mailId, box: o.box, panel: o.panel, kind, opener: o.opener || null, child: null, onClose: o.onClose || null };
		w.frame = $('iframe', dlg);
		if (o.modal) {
			w.backdrop = document.createElement('div');
			w.backdrop.className = 'ibx-backdrop';
			document.body.appendChild(w.backdrop);
		}
		if (o.opener) {
			o.opener.child = w;
		}
		windows.push(w);
		document.body.appendChild(dlg);
		dlg.show();
		front(w);
		bindWindow(w);
	}
	/** A URL of this site only (a page of a module), else '' */
	function sameOriginUrl(url) {
		try {
			const u = new URL(String(url || ''), window.location.href);
			return u.origin === window.location.origin ? u.href : '';
		} catch (e) {
			return '';
		}
	}
	function setWindowTitle(w, title) {
		w.title = title;
		const el = $('.ibx-window__title', w.dlg);
		if (el) {
			el.textContent = title;
		}
		w.dlg.setAttribute('aria-label', title);
	}
	/** What a module page in a window says (same origin, from its own frame only) */
	window.addEventListener('message', async e => {
		if (e.origin !== window.location.origin || !e.data || e.data.source !== 'inbox-panel') {
			return;
		}
		const w = windows.find(x => x.module && x.frame && x.frame.contentWindow === e.source);
		if (!w) {
			return;
		}
		const d = e.data;
		if (d.action === 'close') {
			closeWindow(w);
			return;
		}
		// Work not saved in the page: closing the window by its cross or Escape asks first (text given by the page)
		if (d.action === 'dirty') {
			w.dirty = !!d.on;
			w.dirtyText = typeof d.text === 'string' ? d.text.slice(0, 300) : '';
			return;
		}
		if (d.action === 'title') {
			if (typeof d.title === 'string' && d.title.trim() !== '') {
				setWindowTitle(w, d.title.slice(0, 200));
			}
			return;
		}
		// A window over this one (one level): its page and the page of its opener talk through inbox
		if (d.action === 'open') {
			const url = sameOriginUrl(d.url);
			if (url && !w.opener) {
				openModuleWindow({ mailId: w.mailId, box: w.box, panel: w.panel, url, title: String(d.title || w.title).slice(0, 200), modal: d.modal !== false, opener: w });
			}
			return;
		}
		if (d.action === 'relay') {
			const to = w.opener || w.child;
			if (to && to.frame && to.frame.contentWindow) {
				to.frame.contentWindow.postMessage({ source: 'inbox-panel', action: 'relay', data: d.data }, window.location.origin);
			}
			return;
		}
		if (d.action !== 'done') {
			return;
		}
		if (!w.mailId) {
			closeWindow(w);
			if (d.message) {
				toast(String(d.message).slice(0, 200));
			}
			return;
		}
		const ids = [w.mailId];
		const keyword = k => typeof k === 'string' && /^[\w$-]{1,40}$/.test(k);
		try {
			if (d.link && typeof d.link.type === 'string' && /^[a-z_]{1,30}$/.test(d.link.type) && +d.link.id > 0) {
				await post('dolibarr', 'link', { id: w.mailId, type: d.link.type, element: +d.link.id }).catch(() => null);
			}
			const add = (Array.isArray(d.tags && d.tags.add) ? d.tags.add : []).filter(keyword);
			const remove = (Array.isArray(d.tags && d.tags.remove) ? d.tags.remove : []).filter(keyword);
			if (add.length || remove.length) {
				await runAction('tag', ids, { add, remove }, { quiet: true });
			}
			if (d.archive === true) {
				await doArchive(ids, { quiet: true });
			}
		} finally {
			closeWindow(w);
			if (w.box && w.box.isConnected && +w.box.dataset.mail === w.mailId) {
				loadDolibar(w.box);
			}
			if (d.message) {
				toast(String(d.message).slice(0, 200));
			}
		}
	});

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
		addGrips(w.dlg);
		const body = $('.ibx-window__body', w.dlg);
		if (w.source) {
			try {
				const res = await fetch(I.ctx('INBOX_AJAX_URL', '') + 'body.php?id=' + m.id + '&part=source', { credentials: 'same-origin', headers: I.headers() });
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
			v = await attApi(m, a.part, 'check');
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
		// Viewer of another module: chosen in « Open with… », or the first one for a kind inbox does not show itself
		let other = w.viewer;
		if (!other && !['pdf', 'image', 'mail', 'text', 'csv', 'zip'].includes(a.kind)) {
			other = ((await viewersOf(m))[a.part] || [])[0] || null;
		}
		if (other) {
			// Same origin page (checked by the server of inbox), the warnings of the antivirus stay above it
			body.innerHTML = top + '<iframe class="ibx-viewer__frame" title="' + esc(a.name) + '" src="' + esc(other.url + (force ? '&force=1' : '')) + '"></iframe>';
			return;
		}
		if (a.kind === 'pdf') {
			body.innerHTML = top + '<iframe class="ibx-viewer__frame" title="' + esc(a.name) + '" src="' + attUrl(m, a, 'inline', force) + PDF_OPEN + '"></iframe>';
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
		} else if (a.kind === 'mail' && !m.compose) {
			body.innerHTML = top + '<iframe class="ibx-viewer__frame ibx-viewer__frame--mail" title="' + esc(a.name) + '" sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox" referrerpolicy="no-referrer" src="'
				+ I.ctx('INBOX_AJAX_URL', '') + 'body.php?id=' + m.id + '&part=' + a.part + '"></iframe>';
		} else if (a.kind === 'text' || a.kind === 'csv') {
			try {
				const res = await attApi(m, a.part, 'text', force);
				body.innerHTML = top + (a.kind === 'csv' ? csvTable(res.text) : '<pre class="ibx-viewer__text">' + esc(res.text) + '</pre>') + (res.truncated ? '<p class="ibx-muted ibx-viewer__pad">' + th('InboxTruncated') + '</p>' : '');
			} catch (err) {
				body.innerHTML = '<div class="ibx-empty">' + esc(err.message) + '</div>';
			}
		} else if (a.kind === 'zip') {
			try {
				const res = await attApi(m, a.part, 'list', force);
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
		const at = windows.indexOf(w);
		if (at < 0) {
			return;
		}
		if (w.child) {
			closeWindow(w.child);
		}
		w.dlg.remove();
		if (w.dock) {
			w.dock.remove();
		}
		if (w.backdrop) {
			w.backdrop.remove();
		}
		windows.splice(at, 1);
		if (w.onClose) {
			try {
				w.onClose();
			} catch (e) {
				// The page of the window is gone anyway
			}
		}
		if (w.opener) {
			w.opener.child = null;
			front(w.opener);
			w.opener.frame.focus();
			return;
		}
		// A page of a module opened from a mail (1.109.0): what it changed shows in the blocks of the modules
		if (w.module && w.mailId && w.box && w.box.isConnected && +w.box.dataset.mail === w.mailId && w.box._data) {
			loadPanels(w.box);
		}
		const tileEl = !w.source && !w.module && S.mail && S.mail.id === w.mail.id ? $('[data-att="' + w.index + '"] .ibx-js-attopen', readPane()) : null;
		if (tileEl) {
			tileEl.focus();
		}
	}
	/** Closed by the user (cross, Escape): a page with work not saved asks first */
	async function askCloseWindow(w) {
		if (w.dirty && !(await I.confirmDialog({ title: w.title, text: w.dirtyText || t('InboxWindowCloseDirty'), confirm: t('InboxCloseWindow') }))) {
			return;
		}
		closeWindow(w);
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
		w.dock.innerHTML = w.module ? ic('magic') + '<span class="ibx-dock__label ibx-ellipsis">' + esc(w.title) + '</span>'
			: ic(w.source ? 'source' : (KIND_ICON[(w.mail.attachments[w.index] || {}).kind] || 'attachment')) + '<span class="ibx-dock__label ibx-ellipsis">' + esc(viewerTitle(w)[0]) + '</span>';
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
		if (w.dlg.classList.contains('ibx-window--maximized') || !w.dlg.isConnected || !w.dlg.open) {
			return;
		}
		const r = w.dlg.getBoundingClientRect();
		// A window closed or reduced has no size: kept, it would open the next one at 0, 0 and empty
		if (r.width < 100 || r.height < 60) {
			return;
		}
		// Kept per kind (1.45.0): a PDF made big does not make every text window big
		const all = { doc: savedGeometry('doc'), other: savedGeometry('other'), module: savedGeometry('module'), modal: savedGeometry('modal') };
		all[w.kind || 'other'] = { x: Math.round(r.left), y: Math.round(r.top), w: Math.round(r.width), h: Math.round(r.height) };
		saveParam('windows', JSON.stringify(all));
	}
	function addGrips(dlg) {
		if ($(':scope > .ibx-wgrip', dlg)) {
			return;
		}
		['n', 's', 'e', 'w', 'nw', 'ne', 'sw', 'se'].forEach(dir => {
			const g = document.createElement('div');
			g.className = 'ibx-wgrip ibx-wgrip--' + dir;
			g.dataset.wgrip = dir;
			g.setAttribute('aria-hidden', 'true');
			dlg.appendChild(g);
		});
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
				askCloseWindow(w);
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
				askCloseWindow(w);
			}
		});
		// Size from every edge and corner (1.48.0); the grips are put back when the content is drawn again
		addGrips(dlg);
		dlg.addEventListener('pointerdown', e => {
			const g = e.target.closest('[data-wgrip]');
			if (!g || dlg.classList.contains('ibx-window--maximized')) {
				return;
			}
			e.preventDefault();
			e.stopPropagation();
			const dir = g.dataset.wgrip;
			const r0 = dlg.getBoundingClientRect();
			const x0 = e.clientX;
			const y0 = e.clientY;
			const cs = getComputedStyle(dlg);
			const minW = parseFloat(cs.minWidth) || 280;
			const minH = parseFloat(cs.minHeight) || 160;
			g.setPointerCapture(e.pointerId);
			const move = ev => {
				let left = r0.left;
				let top = r0.top;
				let width = r0.width;
				let height = r0.height;
				if (dir.includes('e')) {
					width = Math.min(window.innerWidth - r0.left, Math.max(minW, r0.width + ev.clientX - x0));
				}
				if (dir.includes('s')) {
					height = Math.min(window.innerHeight - r0.top, Math.max(minH, r0.height + ev.clientY - y0));
				}
				if (dir.includes('w')) {
					left = Math.max(0, Math.min(r0.right - minW, r0.left + ev.clientX - x0));
					width = r0.right - left;
				}
				if (dir.includes('n')) {
					top = Math.max(0, Math.min(r0.bottom - minH, r0.top + ev.clientY - y0));
					height = r0.bottom - top;
				}
				dlg.style.left = Math.round(left) + 'px';
				dlg.style.top = Math.round(top) + 'px';
				dlg.style.width = Math.round(width) + 'px';
				dlg.style.height = Math.round(height) + 'px';
			};
			const up = () => {
				g.removeEventListener('pointermove', move);
				g.removeEventListener('pointerup', up);
				saveGeometry(w);
			};
			g.addEventListener('pointermove', move);
			g.addEventListener('pointerup', up);
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
		// Size changed (grips, maximized): kept
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
			// A mail of a site: a data source made from it (1.74.0), admins of Dolibarr only (checked again by the server)
			if (I.rights().dolibarr_admin) {
				items.push({ icon: 'redirect', label: t('InboxSourceFromThisMail'), run: () => I.go('#/settings/sources/new/' + row.id) });
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
		// Protected mailbox (1.89.0): read live, its folders are managed on the mail server (the actions below work on the
		// index of inbox): greyed with the reason
		const prot = e.account.protected && e.account.protect_level === 'content' ? t('InboxProtectFolderReason') : '';
		// Import, empty, create, rename, move: « handle » (1.92.0); delete a folder: « manage »
		const handle = prot || (canHandle(e.account) ? '' : t('InboxReadOnlyOn', e.account.email));
		const manage = prot || (e.account.level === 'manage' ? '' : t('InboxReadOnlyOn', e.account.email));
		const special = f.role || isInboxPath(f) ? t('InboxFolderSpecialReason') : '';
		if (prot) {
			items[1] = { icon: 'mark-read', label: t('InboxSyncNow'), disabled: true, reason: prot, run: () => null };
		}
		items.push({ sep: true });
		items.push({ icon: 'mark-read', label: t('InboxFolderMarkRead'), disabled: !!prot || !f.unread || !f.selectable, reason: prot || (f.unread ? '' : t('InboxFolderNothingUnread')), run: () => folderMarkRead(f) });
		items.push({ icon: 'download', label: t('InboxImportMails'), disabled: !!handle || !f.selectable, reason: handle, run: () => pickImport(e.account, f) });
		items.push({ icon: 'download-all', label: t('InboxExportFolder'), disabled: !!prot || !f.selectable || !f.total, reason: prot || (f.total ? '' : t('InboxFolderEmptyReason')), run: () => exportDialog(f) });
		if (f.role === 'trash' || f.role === 'junk') {
			items.push({ icon: 'delete', label: t(f.role === 'trash' ? 'InboxEmptyTrash' : 'InboxEmptyJunk'), danger: true, disabled: !!handle, reason: handle, run: () => folderEmpty(e.account, f) });
		}
		items.push({ sep: true });
		items.push({ icon: 'folder-plus', label: t('InboxFolderNewSub'), disabled: !!handle, reason: handle, run: () => folderCreate(e.account, f) });
		items.push({ icon: 'rename', label: t('InboxFolderRename'), disabled: !!(handle || special), reason: handle || special, run: () => folderRename(f) });
		items.push({ icon: 'folder-move', label: t('InboxFolderMove'), disabled: !!(handle || special), reason: handle || special, run: () => folderMoveDialog(e.account, f) });
		// My order of the folders (1.42.0): for the keyboard and the phone, as by dragging
		if (movableFolder(f)) {
			const up = brotherOf(f.id, -1);
			const down = brotherOf(f.id, 1);
			items.push({ icon: 'chevron-up', label: t('InboxMoveUp'), disabled: !up, run: () => placeFolder(f.id, up.id, false) });
			items.push({ icon: 'chevron-down', label: t('InboxMoveDown'), disabled: !down, run: () => placeFolder(f.id, down.id, true) });
		}
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
			// Its parents unfolded: the folder is seen where it is now
			openPathTo(folderId);
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
		if (!a || !(canHandle(a) || S.admin)) {
			return;
		}
		const el = $('.ibx-tree__row--account[data-account="' + accountId + '"]', S.el);
		// « handle » (1.92.0) creates folders; the look of the folders and the rules stay to « manage »
		const lookWhy = a.level === 'manage' || S.admin ? '' : t('InboxReadOnlyOn', a.email);
		const custom = a.folders.filter(f => f.color || f.icon).length;
		const items = [
			{ icon: 'folder-plus', label: t('InboxFolderNew'), disabled: !canHandle(a), reason: canHandle(a) ? '' : t('InboxReadOnlyOn', a.email), run: () => folderCreate(a, null) },
			{ sep: true },
			{ icon: 'catalogue', label: t('InboxLookEdit'), disabled: !!lookWhy, reason: lookWhy, run: () => lookDialog(a, null) },
			{ icon: 'folders-show', label: t('InboxLookResetFolders', custom), disabled: !custom || !!lookWhy, reason: lookWhy || (custom ? '' : t('InboxLookNoCustom')), run: async () => {
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

	async function attachmentMenu(m, index, el, at) {
		const a = m.attachments[index];
		if (!a) {
			return;
		}
		const box = dolibarOf(el);
		const pieces = box && box._data ? box._data.links.filter(l => l.readable) : [];
		// « Open with… »: the viewers of other modules (office server, Office preview...), after the viewer of inbox
		const others = ((await viewersOf(m))[a.part] || []).map(v => ({ icon: 'open', label: t('InboxOpenWith', v.label), run: () => openViewer({ mail: m, index, viewer: v }) }));
		// « Annotate and file… »: the editor of another module, the copy goes in the documents of a piece (C2)
		const editor = ((await editorsOf(m))[a.part] || [])[0] || null;
		const annotate = box && editor ? [{ icon: 'compose', label: t('InboxAnnotateFile'), run: () => annotateStart(box, m, a, editor) }] : [];
		menu(el, [
			{ icon: 'open', label: t('InboxOpen'), run: () => openViewer({ mail: m, index }) },
		].concat(others, a.kind === 'pdf' || a.kind === 'image' ? [{ icon: 'external', label: t('InboxOpenNewTab'), key: 'Ctrl+clic', run: () => openInTab(m, index) }] : [], [
			{ icon: 'download', label: t('InboxDownload'), run: () => guardedDownload(m, a) },
		]).concat(box ? [{ sep: true }].concat(pieces.map(l => ({ icon: l.icon, label: t('InboxAttachTo', l.ref || l.kind), run: () => attachToPiece(box, m, a, l.type, l.id) })),
			[{ icon: 'link', label: t('InboxAttachToOther'), run: () => linkDialog(box, { attach: { m, a } }) }], annotate) : [], [HINT()]), at || rectPoint(el));
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
		// One mailbox at a time (1.30.0): the selector and the mailboxes of its list have the menu of the mailbox
		const picked = target.closest('[data-boxpick]') || target.closest('.ibx-boxpick[data-account]');
		if (picked && S.el.contains(picked)) {
			const pa = S.accounts.find(x => x.id === +(picked.dataset.boxpick || picked.dataset.account));
			return pa && (canHandle(pa) || S.admin) ? at => accountMenu(pa.id, at) : null;
		}
		const vv = target.closest('[data-vview]');
		if (vv && S.el.contains(vv)) {
			return at => vviewMenu(vv, +vv.dataset.vview, at);
		}
		// The unified views (1.29.0): the same menu as « ⋯ », with the view right clicked first
		const view = target.closest('[data-view]');
		if (view && S.el.contains(view)) {
			return at => viewsMenu(view, at, view.dataset.view);
		}
		const views = target.closest('[data-section="views"]');
		if (views && S.el.contains(views)) {
			return at => viewsMenu(views, at);
		}
		const folder = target.closest('[data-folder]');
		if (folder && S.el.contains(folder)) {
			return at => folderMenu(+folder.dataset.folder, at);
		}
		const box = target.closest('.ibx-tree__row--account[data-account]');
		if (box && S.el.contains(box)) {
			const a = S.accounts.find(x => x.id === +box.dataset.account);
			return a && (canHandle(a) || S.admin) ? at => accountMenu(a.id, at) : null;
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
		// Recognized domain of the sender (1.59.0): its details
		S.el.addEventListener('click', e => {
			const b = e.target.closest('.ibx-js-trustinfo');
			if (b && S.el.contains(b)) {
				e.stopPropagation();
				trustInfo(b);
			}
		});
		// Tags beside the subject of a mail read: the menu of the tags under them
		S.el.addEventListener('click', e => {
			const b = e.target.closest('.ibx-js-tagedit');
			if (!b || !S.el.contains(b)) {
				return;
			}
			const why = refusal('tag', [+b.dataset.mail]);
			if (why) {
				toast(why, { error: true });
				return;
			}
			tagMenu(b, [+b.dataset.mail]);
		});
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
		// One mailbox at a time: a folder of another mailbox (a link, a notification) shows its mailbox
		if (singleBox() && S.box !== entry.account.id) {
			S.box = entry.account.id;
			S.pickOpen = false;
			renderTree();
		}
		Dolibarr.executeHook('inbox:folder:open', { role: entry.folder.role, account: entry.account.id, el: S.el });
		// The parents of the folder opened are unfolded: the user sees where he is; a folded mailbox stays folded,
		// its row selected for its Inbox, another folder shown alone under it (1.49.0)
		const reveal = openPathTo(id, false);
		leaveVview();
		S.scope = 'folder';
		S.view = '';
		if (reveal || S.peek || S.collapsed.has('a' + entry.account.id)) {
			renderTree();
		}
		$$('[data-view]', S.el).forEach(r => {
			r.setAttribute('aria-selected', 'false');
			r.setAttribute('tabindex', '-1');
		});
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
	/**
	 * « Refresh » of the list (1.97.0): the folder open only, else (a view) the Inbox of each mailbox of the view,
	 * synchronised now by this tab, the button turning until they are done; protected mailboxes read live are only
	 * listed again
	 */
	async function refreshNow() {
		const btn = S.el && $('.ibx-js-refresh', S.el);
		if (!btn || btn.getAttribute('aria-busy') === 'true') {
			return;
		}
		btn.setAttribute('aria-busy', 'true');
		const started = Date.now();
		const live = e => e.account.protected && e.account.protect_level === 'content';
		const ids = [];
		const add = id => {
			const e = S.folders.get(id);
			if (e && !live(e) && !ids.includes(id)) {
				ids.push(id);
			}
		};
		if (S.scope === 'folder' && S.folderId) {
			// A folder open (the Inbox of a mailbox clicked included): this folder only
			add(S.folderId);
		} else {
			// A view over the mailboxes (unified inbox, unread, followed...): the Inbox of each of its mailboxes, never
			// all their folders (too long for a click; the others follow at the automatic passes)
			const only = S.vview && S.vAccounts.length ? S.vAccounts.map(Number) : null;
			S.accounts.filter(a => !only || only.includes(a.id)).forEach(a => {
				const f = boxInbox(a);
				if (f) {
					add(f.id);
				}
			});
		}
		// Called by this tab itself, never through the leader tab: the leader may be busy with an automatic pass (up to
		// 10 s) or slowed down by the browser in the background, the click waited for it (10 to 16 s measured for a
		// folder synchronised in 0.2 s). The lock per mailbox of the server keeps one pass at a time; a pass of the
		// leader on the same mailbox makes the folder skipped, asked again a moment later (3 tries)
		let changed = false;
		for (const folder of ids) {
			for (let i = 0; i < 3; i++) {
				try {
					const r = await post('list', 'pump', { folder, force: 1 });
					changed = changed || !!(r.stats && (r.stats.new || r.stats.changed || r.stats.deleted));
					if (r.skipped !== 'busy') {
						break;
					}
				} catch (err) {
					break;
				}
				await new Promise(resolve => setTimeout(resolve, 1000));
			}
		}
		if (changed) {
			I.channel.post({ type: 'synced' });
		}
		await poll();
		// Views over several mailboxes and folders read live: no stamp of the folder to follow, listed again
		if (S.q === '' && (S.scope !== 'folder' || (S.folderId && live(S.folders.get(S.folderId) || { account: {} })))) {
			await loadList(true);
		}
		// Seen turning at least a moment, else the click looks lost
		setTimeout(() => btn.removeAttribute('aria-busy'), Math.max(0, 600 - (Date.now() - started)));
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
		orderAccounts();
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

	/**
	 * Quicker refresh of the lists (1.121.0, js/inbox-live.js): while it runs, the regular refresh is only a safety net
	 * (60 s, 5 min after 2 minutes without activity); the changes of the others come as events, and the usual call of state is asked at once (gathered: at
	 * most one per second), so what is shown is always read through the calls that check the rights
	 */
	const live = { on: false, timer: 0, at: 0 };
	function pollDelay() {
		if (!live.on) {
			return 20000;
		}
		return Date.now() - lastSeen() > 2 * 60 * 1000 ? 300000 : 60000;
	}
	Dolibarr.on('inbox:live:state', p => {
		live.on = !!(p && p.on);
	});
	Dolibarr.on('inbox:live:events', p => {
		if (!S.el) {
			return;
		}
		// The bell has its own count (js/inbox-notify.js): nothing to read again in the list for it
		const events = ((p && p.events) || []).filter(e => e.type !== 'notif');
		if (!(p && p.reset) && !events.length) {
			return;
		}
		// Mailboxes given or taken, a whole folder to read again: the tree too
		if ((p && p.reset) || events.some(e => e.type === 'access')) {
			refreshTree();
		}
		clearTimeout(live.timer);
		live.timer = setTimeout(() => {
			live.at = Date.now();
			poll();
		}, Math.max(150, 1000 - (Date.now() - live.at)));
	});

	async function poll() {
		clearTimeout(S.pollTimer);
		if (pauseIfIdle()) {
			// Started again by the next activity (resume)
			return;
		}
		if (document.hidden || !S.el || S.el.closest('[hidden]')) {
			S.pollTimer = setTimeout(poll, pollDelay());
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
			const busy = pendingFolders();
			(st.folders || []).forEach(([id, total, unread, fresh]) => {
				const e = busy.has(id) ? null : S.folders.get(id);
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
				// A unified view follows the mails that arrive or change in its mailboxes
				if (S.scope === 'view' && S.q === '') {
					await loadList(true);
				}
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
		S.pollTimer = setTimeout(poll, pollDelay());
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
		box.innerHTML = '<span class="ibx-assignees">' + users.slice(0, 4).map(u => userAvatar(u, 'ibx-avatar--xs')).join('') + '</span> '
			+ esc([writers.length ? (writers.length === 1 ? t('InboxPresenceWritingOne', writers[0].name) : t('InboxPresenceWritingMany', writers.map(u => u.name).join(', '))) : '',
				readers.length ? (readers.length === 1 ? t('InboxPresenceOne', readers[0].name) : t('InboxPresenceMany', readers.map(u => u.name).join(', '))) : ''].filter(Boolean).join(' · '));
		box.classList.toggle('ibx-presence--writing', writers.length > 0);
		hydrateUserPhotos(box);
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
		if (key === 'F9' && e.shiftKey) {
			// Agenda panel (1.107.0)
			if (I.agenda && I.agenda.enabled()) {
				e.preventDefault();
				if (isPhone()) {
					I.agenda.toggle('key');
				} else {
					toggleSide('agenda', 'key');
				}
			}
		} else if (key === 'F9') {
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
			// A mail alone scrolls in its body (head and attachments stay), a conversation in its list or the whole pane
			const pane = $('.ibx-pane--read .ibx-reading:not(.ibx-convo) > .ibx-readbody', S.el) || convoScroller($('.ibx-pane--read .ibx-pane__body', S.el));
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
			['Suppr', 'InboxDelete'], ['1 … 9', 'InboxKeyTags'], ['0', 'InboxRemoveAllTags'], ['Ctrl+Maj+K', 'InboxKeySearch'], ['F9', 'InboxKeyFolders'], ['Maj+F9', 'InboxKeyAgenda'],
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
		if (w.w3) {
			app.style.setProperty('--ibx-w3', w.w3 + 'px');
		} else {
			app.style.removeProperty('--ibx-w3');
		}
	}
	function bindSplitters() {
		$$('.ibx-splitter', S.el).forEach(sp => {
			const which = 'w' + sp.dataset.split;
			const pane = $(which === 'w1' ? '.ibx-pane--folders' : (which === 'w2' ? '.ibx-pane--list' : '.ibx-pane--agenda'), S.el);
			const min = which === 'w1' ? 180 : (which === 'w2' ? 320 : 240);
			// The agenda is on the right of its splitter: it grows when the splitter goes left
			const dir = which === 'w3' ? -1 : 1;
			sp.addEventListener('pointerdown', e => {
				e.preventDefault();
				sp.setPointerCapture(e.pointerId);
				sp.classList.add('is-dragging');
				const start = e.clientX;
				// Right column: the agenda or Dolibarr, the one shown (1.110.0)
				const target = which === 'w3' && dolSide() && !dolSide().hidden ? dolSide() : pane;
				const base = target.getBoundingClientRect().width;
				const app = $('.ibx-mailapp', S.el);
				const move = ev => {
					app.style.setProperty('--ibx-' + which, Math.max(min, Math.min(window.innerWidth * (which === 'w3' ? 0.4 : 0.6), base + dir * (ev.clientX - start))) + 'px');
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
					w[which] = Math.round(target.getBoundingClientRect().width);
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
				app.style.setProperty('--ibx-' + which, Math.max(min, cur + dir * (e.key === 'ArrowRight' ? 24 : -24)) + 'px');
			});
		});
	}

	// ---------- Layout and events of the space ----------
	function layout() {
		return '<div class="ibx-mailapp" data-folders="open" data-mobile="list">'
			+ '<section class="ibx-pane ibx-pane--folders" aria-label="' + th('InboxFolders') + '">'
			+ '<div class="ibx-pane__head"><span class="ibx-pane__title">' + th('InboxSpaceMail') + '</span><span class="ibx-spacer"></span>'
			+ '<button type="button" class="ibx-btn ibx-btn--icon ibx-btn--sm ibx-js-treemenu" aria-haspopup="menu" title="' + th('InboxFoldersDisplay') + '" aria-label="' + th('InboxFoldersDisplay') + '">' + ic('more') + '</button>'
			+ (I.ctx('INBOX_CAN_SEND', false) ? '<button type="button" class="ibx-btn ibx-btn--primary ibx-btn--sm ibx-js-compose" title="' + th('InboxNewMail') + ' (C)">' + ic('compose') + ' ' + th('InboxNewMail') + '</button>' : '') + '</div>'
			+ '<div class="ibx-toolbar ibx-js-folderfilterbox" hidden><label class="ibx-search">' + ic('filter') + '<input type="search" class="ibx-field ibx-js-folderfilter" placeholder="' + th('InboxFilterFolders') + '" aria-label="' + th('InboxFilterFolders') + '" autocomplete="off"></label></div>'
			+ '<div class="ibx-pane__body"><ul class="ibx-tree" aria-busy="true"><li><span class="ibx-skeleton"></span></li><li><span class="ibx-skeleton" style="width:70%"></span></li><li><span class="ibx-skeleton" style="width:50%"></span></li></ul></div></section>'
			+ '<div class="ibx-splitter" data-split="1" role="separator" aria-orientation="vertical" tabindex="0" aria-label="' + th('InboxResizePane') + '"></div>'
			+ '<section class="ibx-pane ibx-pane--list" aria-label="' + th('InboxMails') + '">'
			+ '<div class="ibx-pane__head">'
			+ '<button type="button" class="ibx-btn ibx-btn--icon ibx-js-back ibx-mobileonly" data-to="folders" aria-label="' + th('InboxFolders') + '">' + ic('chevron-left') + '</button>'
			+ '<button type="button" class="ibx-btn ibx-btn--icon ibx-btn--toggle ibx-js-togglefolders ibx-desktoponly" aria-pressed="false">' + ic('folders-hide') + '</button>'
			+ '<span class="ibx-pane__title ibx-ellipsis ibx-js-listtitle" role="heading" aria-level="1"></span><span class="ibx-spacer"></span>'
			+ '<button type="button" class="ibx-btn ibx-btn--icon ibx-js-refresh" title="' + th('InboxRefreshMails') + '" aria-label="' + th('InboxRefreshMails') + '">' + ic('refresh') + '</button>'
			+ '<button type="button" class="ibx-btn ibx-btn--icon ibx-js-view ibx-desktoponly-phone"></button>'
			+ '<button type="button" class="ibx-btn ibx-btn--icon ibx-js-listmenu" aria-haspopup="menu" aria-label="' + th('InboxListOptions') + '">' + ic('more') + '</button></div>'
			// Search field opening into a panel (lot W7 1.19.0)
			+ '<div class="ibx-toolbar"><div class="ibx-sx-holder"><div class="ibx-sx-backdrop ibx-js-sxbackdrop" hidden></div><div class="ibx-sx ibx-js-sx"><div class="ibx-sx__row ibx-js-sxrow">' + ic('search')
			+ '<span class="ibx-sx__pills ibx-js-sxpills"></span>'
			+ '<input type="search" class="ibx-sx__input ibx-js-search" enterkeyhint="search" autocomplete="off" spellcheck="false" role="combobox" aria-expanded="false" aria-controls="ibx-sxpanel" aria-autocomplete="list"'
			+ ' placeholder="' + th('InboxSearchPlaceholder') + '" aria-label="' + th('InboxSearchPlaceholder') + '" aria-describedby="ibx-scopehint"><kbd class="ibx-kbd ibx-js-sxkbd">Ctrl+Maj+K</kbd>'
			+ '<button type="button" class="ibx-btn ibx-btn--ghost ibx-btn--icon ibx-btn--sm ibx-sx__clear ibx-js-sxclearin" aria-label="' + th('InboxClearSearch') + '" title="' + th('InboxClearSearch') + '" hidden>' + ic('close') + '</button>'
			+ '<button type="button" class="ibx-btn ibx-btn--ghost ibx-btn--icon ibx-btn--sm ibx-sx__close ibx-js-sxclose" aria-label="' + th('InboxSxClose') + '" title="' + th('InboxSxClose') + '">' + ic('close') + '</button></div>'
			+ '<div class="ibx-sx__panel ibx-js-sxpanel" id="ibx-sxpanel" hidden></div></div></div></div>'
			+ '<div class="ibx-searchinfo"><span id="ibx-scopehint" class="ibx-muted ibx-js-scopehint"></span>'
			// Order of the list (UX fix series): its arrow and its words, a click turns it the other way
			+ '<span class="ibx-sortchip ibx-js-sortchip" hidden></span> <span class="ibx-js-pills" hidden></span><span class="ibx-spacer"></span>'
			+ '<button type="button" class="ibx-btn ibx-btn--ghost ibx-btn--sm ibx-js-vsave" hidden>' + ic('folder-heart') + '<span class="ibx-searchinfo__word ibx-js-vsavelabel"></span></button>'
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
			+ '<div class="ibx-pane__head ibx-mobileonly"><button type="button" class="ibx-btn ibx-btn--icon ibx-js-back" data-to="list" aria-label="' + th('InboxBackToList') + '">' + ic('back') + '</button>'
			+ '<div class="ibx-panebar ibx-js-panebar" role="toolbar" aria-label="' + th('InboxMailActions') + '"></div></div>'
			+ '<div class="ibx-pane__body" tabindex="-1"></div></section>'
			// Agenda panel (1.107.0, js/inbox-agenda.js): its splitter and its column, filled by that script
			+ '<div class="ibx-splitter ibx-splitter--agenda" data-split="3" role="separator" aria-orientation="vertical" tabindex="0" aria-label="' + th('InboxResizePane') + '" hidden></div>'
			+ '<aside class="ibx-pane ibx-pane--agenda ibx-js-agenda" aria-label="' + th('InboxAgendaPanel') + '" hidden></aside>'
			// Column Dolibarr (1.110.0): the bandeau of the mail open, moved here on a computer
			+ '<aside class="ibx-pane ibx-pane--dolside ibx-js-dolside" aria-label="' + th('InboxDolibarr') + '" hidden></aside>'
			// Strip on the right (1.109.0): Dolibarr and the agenda, one column at a time
			+ stripHtml()
			+ (I.ctx('INBOX_CAN_SEND', false) ? '<button type="button" class="ibx-fab ibx-js-compose" aria-label="' + th('InboxNewMail') + '">' + ic('compose') + '</button>' : '')
			+ '</div>';
	}

	function bindLayout() {
		const el = S.el;
		$$('.ibx-js-compose', el).forEach(b => b.addEventListener('click', () => newMail()));
		// Display of the panel of the folders (1.40.0): the size of the folders, kept for the user
		$$('.ibx-js-treemenu', el).forEach(b => b.addEventListener('click', () => menu(b, [
			{ header: t('InboxFoldersDisplay') },
			{ icon: 'folders', label: t('InboxFolderSizesShow'), checked: params.folder_sizes === '1', run: async () => {
				const on = params.folder_sizes === '1' ? '0' : '1';
				params.folder_sizes = on;
				if (on === '0') {
					renderTree();
				}
				try {
					await post('userparam', 'set', { param: 'folder_sizes', value: on });
				} catch (err) {
					toast(err.message, { error: true });
					return;
				}
				// The sizes come with the tree, once the choice is kept on the server
				if (on === '1') {
					refreshTree();
				}
			} },
			{ icon: 'undo', label: t('InboxFolderOrderReset'), disabled: (params.folder_order || '{}') === '{}', run: () => {
				saveParam('folder_order', '{}');
				renderTree();
			} },
			// Mailboxes in the panel (1.50.0): the choice of My account, at hand
			{ header: t('InboxTreeMode') },
		].concat(['auto', 'all', 'single'].map(mode => ({ label: t('InboxTreeModeMenu_' + mode), checked: (params.tree_mode || 'auto') === mode, run: () => {
			if ((params.tree_mode || 'auto') === mode) {
				return;
			}
			saveParam('tree_mode', mode);
			S.pickOpen = false;
			renderTree();
		} }))))));

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
			// Private type, not text/plain: a file manager turns a text into a .txt file holding the name
			e.dataTransfer.setData('application/x-inbox-attachment', S.attDrag.a ? S.attDrag.a.name : '');
			const out = dragOutUrl(m, S.attDrag.a);
			if (out) {
				e.dataTransfer.setData('DownloadURL', out);
			}
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
		bindStrip();
		$('.ibx-pane--read', el).addEventListener('click', e => {
			const unblock = e.target.closest('.ibx-js-unblock');
			if (unblock && S.mail && S.mail.blocked) {
				unblockEntry(S.mail.blocked);
				return;
			}
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
		// Phone (UX fix series): the line of the recipients is cut on one line; a tap opens it whole, again closes it
		// (a tap on a name keeps opening its card)
		$('.ibx-pane--read .ibx-pane__body', el).addEventListener('click', e => {
			const line = e.target.closest('.ibx-readhead__to');
			if (line && isSmallPhone() && !e.target.closest('.ibx-addr, a, button')) {
				line.classList.toggle('is-open');
			}
		});
		S.mobile = to => {
			$('.ibx-mailapp', el).dataset.mobile = to;
		};
		$$('.ibx-js-back', el).forEach(b => b.addEventListener('click', () => {
			// The list, as many steps back as mails opened since it (the router closes the mail): the history stays right
			const depth = history.state && history.state.ibxDepth;
			if (b.dataset.to === 'list' && mailInHash() && depth > 0) {
				history.go(-depth);
				return;
			}
			if (b.dataset.to === 'list' && mailInHash()) {
				// Reached by an address (a reload, a link): no step to go back to, the list in place
				S.selected.clear();
				paintSelection();
				showNothing();
			}
			S.mobile(b.dataset.to);
		}));
		$('.ibx-js-togglefolders', el).addEventListener('click', toggleFolders);
		$('.ibx-js-view', el).addEventListener('click', () => {
			saveParam('view', params.view === 'table' ? 'cards' : 'table');
			renderList();
			renderListHead();
		});
		$('.ibx-js-refresh', el).addEventListener('click', () => refreshNow());
		const chip = $('.ibx-js-sortchip', el);
		chip.addEventListener('click', e => {
			if (e.target.closest('.ibx-js-sortdir')) {
				const [k, dir] = (params.list_sort || 'date:desc').split(':');
				setSort(k + ':' + (dir === 'asc' ? 'desc' : 'asc'));
			} else if (e.target.closest('.ibx-js-sortkey')) {
				menu($('.ibx-js-sortkey', chip), sortItems());
			}
		});
		chip.addEventListener('contextmenu', e => {
			// Browser menu kept with Shift (interface §6 bis)
			if (e.shiftKey) {
				return;
			}
			e.preventDefault();
			e.stopPropagation();
			menu($('.ibx-js-sortkey', chip), sortItems(), { x: e.clientX, y: e.clientY });
		});
		$('.ibx-js-listmenu', el).addEventListener('click', e => {
			menu(e.currentTarget, sortItems().concat([
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
			]));
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
			// The edge of a header resizes, it never sorts (its pointerup ends in a click on the header)
			if (sortBtn && !e.target.closest('[data-resize]')) {
				const [k, dir] = (params.list_sort || 'date:desc').split(':');
				const key = sortBtn.dataset.sort;
				setSort(key + ':' + (k === key ? (dir === 'asc' ? 'desc' : 'asc') : naturalSort(key)));
				return;
			}
			if (sortBtn) {
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
		// Dots of the tags: their names in a bubble (tagPop), never on a touch screen (a tap opens the mail)
		listBody.addEventListener('pointerover', e => {
			const dots = e.pointerType === 'touch' ? null : e.target.closest('.ibx-tagdots');
			if (!dots || dots === S.tagPopFor) {
				return;
			}
			hideTagPop();
			S.tagPopFor = dots;
			S.tagPopTimer = setTimeout(() => tagPop(dots), 120);
		});
		listBody.addEventListener('pointerout', e => {
			const dots = e.target.closest('.ibx-tagdots');
			if (dots && !dots.contains(e.relatedTarget)) {
				hideTagPop();
			}
		});
		listBody.addEventListener('scroll', hideTagPop, { passive: true });
		listBody.addEventListener('scroll', () => {
			if (listBody.scrollTop < 40) {
				$('.ibx-js-newpill', el).hidden = true;
			}
		}, { passive: true });
	}

	/**
	 * Orders of the list (menu « ⋯ » and the chip of the order): the key first (taken in its natural direction), then
	 * the two directions of this key in its own words (newest / oldest, A → Z / Z → A, biggest / smallest)
	 */
	function sortItems() {
		const [k, dir] = (params.list_sort || 'date:desc').split(':');
		const key = (v, label) => ({ label: t(label), checked: k === v, run: () => setSort(v + ':' + naturalSort(v)) });
		const words = { date: ['InboxSortDateDesc', 'InboxSortDateAsc'], size: ['InboxSortSize', 'InboxSortSizeAsc'] }[k] || ['InboxSortZA', 'InboxSortAZ'];
		const way = (d, label) => ({ label: t(label), checked: dir === d, run: () => setSort(k + ':' + d) });
		const ways = naturalSort(k) === 'desc' ? [way('desc', words[0]), way('asc', words[1])] : [way('asc', words[1]), way('desc', words[0])];
		return [key('date', 'InboxSortByDate'), key('from', 'InboxSortFrom'), key('subject', 'InboxSortSubject'), key('size', 'InboxSortBySize'), { sep: true }].concat(ways);
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
		try {
			if (S.folderId !== g.folder || S.scope !== 'folder' || S.q !== '') {
				await openFolder(g.folder, true);
			}
			gotoMail(g);
		} finally {
			S.jump = false;
		}
		return true;
	}
	function gotoMail(g) {
		if (S.rows.some(r => r.id === g.mail)) {
			select(g.mail);
			scrollWithin($('.ibx-pane--list .ibx-pane__body', S.el), $('[data-mail="' + g.mail + '"]', S.el));
		} else {
			// Beyond the first page, or inside a folded conversation: opened alone
			S.selected.clear();
			paintSelection();
			openMail(g.mail, true);
		}
	}
	/** A mail of the list shown selected, else opened alone (beyond the first page, in a folded conversation) */
	function openInList(id) {
		if (S.rows.some(r => r.id === id)) {
			select(id);
			scrollWithin($('.ibx-pane--list .ibx-pane__body', S.el), $('[data-mail="' + id + '"]', S.el));
		} else {
			openMail(id, true);
		}
	}
	Dolibarr.on('inbox:mail:goto', g => {
		// A jump asked by the user: a step of the history (the back of the browser comes back to the mail he was on)
		S.jump = true;
		S.goto = { folder: +g.folder, mail: +g.mail };
		if (S.el && !S.folders.has(S.goto.folder)) {
			// A folder not in the tree yet (created since): the tree again
			refreshTree().then(() => runGoto());
		} else {
			runGoto();
		}
	});

	// Protected mailboxes (1.89.0): unlocked in silence after the tree was read, or locked from the settings: the
	// tree follows (its folders are read live on the mail server while unlocked)
	Dolibarr.on('inbox:protect:state', () => {
		if (!S.el || !(S.accounts || []).length || !I.protect) {
			return;
		}
		const tree = S.accounts.some(a => a.protected && !!a.locked !== I.protect.isLocked(a.id)) ? refreshTree() : Promise.resolve();
		// The list refused as locked (first load of the session, before the silent unlock): loaded again
		if (S.listLocked && !I.protect.isLocked(S.listLocked)) {
			S.listLocked = 0;
			tree.then(() => loadList(), () => loadList());
		}
	});

	I.registerSpace({
		id: 'mail',
		icon: 'mail',
		label: () => t('InboxSpaceMail'),
		async render(el, route) {
			const wantedView = route.parts[0] === 'view' ? String(route.parts[1] || '') : '';
			const wantedVview = route.parts[0] === 'vview' ? +(route.parts[1] || 0) : 0;
			const wanted = wantedView || wantedVview ? 0 : +(route.parts[0] || 0);
			// The mail of the address (UX fix series): #/mail/<folder>/<mail> (also from another page, tab « Mails » of
			// a card of Dolibarr), #/mail/view/<view>/<mail>, #/mail/vview/<id>/<mail>
			const wantedMail = +(route.parts[wantedView || wantedVview ? 2 : 1] || 0);
			if (wanted && wantedMail) {
				S.goto = { folder: wanted, mail: wantedMail };
				if (S.el === el) {
					if (S.openId !== wantedMail) {
						S.routing = true;
						try {
							await runGoto();
						} finally {
							S.routing = false;
						}
					}
					return;
				}
			}
			if (S.el === el) {
				// Back of the browser from a mail: its list again (on a phone, the list screen)
				if (!wantedMail && S.openId) {
					S.selected.clear();
					paintSelection();
					showNothing();
					S.mobile('list');
				}
				if (wantedView && (S.scope !== 'view' || S.view !== wantedView)) {
					await openView(wantedView, true);
				} else if (wantedVview && S.vview !== wantedVview) {
					await openVview(wantedVview, true);
				} else if (wanted && wanted !== S.folderId && S.folders.has(wanted)) {
					openFolder(wanted, true);
				}
				if ((wantedView || wantedVview) && wantedMail && S.openId !== wantedMail) {
					S.routing = true;
					openInList(wantedMail);
					S.routing = false;
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
			S.routing = !!S.goto;
			const gone = S.goto && await runGoto();
			S.routing = false;
			if (gone) {
				// Opened from a notification of the bell
			} else if (wantedVview) {
				// A virtual view as start: once the views are known
				S.folderId = start;
				api('view', 'list').then(r => {
					S.vviews = r.views || [];
					renderTree();
					if (S.vviews.some(v => v.id === wantedVview)) {
						openVview(wantedVview, true).then(() => wantedMail && openInList(wantedMail));
					} else if (start) {
						openFolder(start);
					}
				}).catch(() => start && openFolder(start));
			} else if (wantedView && VIEWS.some(([k]) => k === wantedView)) {
				// A unified view as start (its address kept); the first folder known for the actions of the page
				S.folderId = start;
				await openView(wantedView, true);
				if (wantedMail) {
					S.routing = true;
					openInList(wantedMail);
					S.routing = false;
				}
			} else if (start) {
				openFolder(start, !!wanted);
			} else {
				renderList();
				showNothing();
			}
			document.addEventListener('keydown', e => onKey(e, false));
			window.addEventListener('pagehide', () => leaveFolder(true));
			// Crossing the phone width: table or cards again
			window.matchMedia('(max-width: 63.9375rem)').addEventListener('change', () => {
				renderList();
				// « Automatic »: one mailbox at a time on a narrow screen, all of them on a large one
				renderTree();
			});
			$('.ibx-js-vsave', el).addEventListener('click', () => {
				const vv = S.vview ? S.vviews.find(x => x.id === S.vview) : null;
				vviewDialog(vv && vv.editable ? vv : null, true);
			});
			document.addEventListener('click', e => {
				if (S.pickOpen && !(e.target.closest && e.target.closest('.ibx-boxpick__wrap'))) {
					S.pickOpen = false;
					renderTree();
				}
			});
			$('.ibx-pane--folders', el).addEventListener('keydown', e => {
				if (e.key === 'Escape' && S.pickOpen) {
					e.stopPropagation();
					S.pickOpen = false;
					renderTree();
					const b = $('[data-boxpick-toggle]', S.el);
					if (b) {
						b.focus();
					}
				}
			});
			document.addEventListener('visibilitychange', () => {
				if (!document.hidden) {
					poll();
				}
			});
			S.pollTimer = setTimeout(poll, pollDelay());
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
