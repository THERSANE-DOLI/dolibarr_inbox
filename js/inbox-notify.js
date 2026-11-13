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
 * Notification centre of inbox, the bell of the rail (lot W4 1.9.0, webmail §5.4 sexies).
 * - The count comes with the call of state of the Mail space (every 20 s, hook inbox:notif:state); in another space,
 *   or when that call is paused, the bell asks it every 60 s (never after 30 min without activity).
 * - A click on a notification opens its mail where it is now (found by its mailref), in any mailbox the user reads.
 * - Other modules: InboxNotification::add() on the server; inbox:notification:new tells them a new one came.
 */
(function () {
	'use strict';

	const I = Dolibarr.tools.inbox;
	const { esc, t, th, ic, api, post, toast, $, $$ } = I;
	const IDLE_MS = 30 * 60 * 1000;
	const N = { unread: 0, last: -1, at: 0, panel: null, items: [], more: false, loading: false, mails: -1, mailFolders: null };

	// ---------- Unread counter: tab, installed application, top bar of Dolibarr (lot W8 1.21.0) ----------
	const icons = new Map();
	let iconBase = null;

	/** Icon of the tab with a round badge of the count (1 to 99, then 99+), drawn once per number */
	function favicon(n) {
		const link = document.querySelector('link[rel="icon"]');
		if (!link) {
			return;
		}
		if (iconBase === null) {
			iconBase = link.href;
		}
		if (n <= 0) {
			link.href = iconBase;
			return;
		}
		const label = n > 99 ? '99+' : String(n);
		if (icons.has(label)) {
			link.href = icons.get(label);
			return;
		}
		const img = new Image();
		img.onload = () => {
			const c = document.createElement('canvas');
			c.width = c.height = 64;
			const g = c.getContext('2d');
			g.drawImage(img, 0, 0, 64, 64);
			const r = label.length > 2 ? 22 : 19;
			const x = 64 - r;
			const y = r;
			// Outline of the colour of the tab bar: readable on a light or dark one
			g.beginPath();
			g.arc(x, y, r + 3, 0, Math.PI * 2);
			g.fillStyle = window.matchMedia('(prefers-color-scheme: dark)').matches ? '#202124' : '#ffffff';
			g.fill();
			g.beginPath();
			g.arc(x, y, r, 0, Math.PI * 2);
			g.fillStyle = '#d93025';
			g.fill();
			g.fillStyle = '#ffffff';
			g.font = 'bold ' + (label.length > 2 ? 20 : 28) + 'px system-ui, sans-serif';
			g.textAlign = 'center';
			g.textBaseline = 'middle';
			g.fillText(label, x, y + 1);
			try {
				icons.set(label, c.toDataURL('image/png'));
				// Still the number to show (another may have come while drawing)
				if ((N.mails > 99 ? '99+' : String(N.mails)) === label) {
					link.href = icons.get(label);
				}
			} catch (e) {
				// Canvas refused: the icon stays plain, the title still tells the number
			}
		};
		img.src = iconBase;
	}

	function paintMails(n) {
		if (n === N.mails) {
			return;
		}
		N.mails = n;
		favicon(n);
		I.setTitleCount(n);
		if (navigator.setAppBadge) {
			(n > 0 ? navigator.setAppBadge(n) : navigator.clearAppBadge()).catch(() => {});
		}
		// The top bar of the other pages of Dolibarr reads it (js/inbox-topbar.js)
		try {
			localStorage.setItem(I.scope('inbox-unread'), JSON.stringify({ n, at: Date.now() }));
		} catch (e) {
			// Storage refused: the top bar keeps the number of its page
		}
	}

	// The tree of the Mail space knows the counters at once after an action (read, moved): summed again here
	Dolibarr.on('inbox:folders:counts', folders => {
		if (!N.mailFolders || !folders) {
			return;
		}
		let n = 0;
		let known = 0;
		N.mailFolders.forEach(id => {
			const e = folders.get(id);
			if (e) {
				n += e.folder.unread || 0;
				known++;
			}
		});
		if (known === N.mailFolders.size) {
			paintMails(n);
		}
	});

	// ---------- Notifications of the browser (lot W8 1.21.0) ----------
	/**
	 * Turned on by the user (Settings > Notifications, the permission asked then). Only the leader tab asks the server
	 * and tells: one notification even with five tabs. The sender and the subject only, never the text of the mail.
	 */
	const B = { on: false, mail: 0, notif: 0, first: true, timer: 0, busy: false };
	function browserReady() {
		return B.on && 'Notification' in window && Notification.permission === 'granted' && I.isLeader();
	}
	function setBrowser(params) {
		B.on = !!params && String(params.browser_notify) === '1';
		clearTimeout(B.timer);
		if (B.on) {
			B.timer = setTimeout(fresh, 3000);
		}
	}
	function show(title, body, tag, onclick) {
		try {
			const n = new Notification(title, { body, tag, icon: iconBase || undefined });
			n.onclick = () => {
				window.focus();
				n.close();
				onclick();
			};
		} catch (e) {
			// Notification refused by the browser (some phones need a service worker): the bell stays
		}
	}
	function openMail(m) {
		Dolibarr.executeHook('inbox:mail:goto', { folder: m.folder, mail: m.id });
		if (I.route().space !== 'mail') {
			I.go('#/mail');
		}
	}
	async function fresh() {
		clearTimeout(B.timer);
		if (!browserReady() || B.busy) {
			B.timer = setTimeout(fresh, 30000);
			return;
		}
		B.busy = true;
		try {
			const first = B.first;
			const r = await api('notification', 'fresh', { mail: B.mail, notif: B.notif, first: first ? 1 : 0 });
			B.first = false;
			B.mail = r.mail_last || B.mail;
			B.notif = r.notif_last || B.notif;
			if (!first) {
				// The mail shown in front in the open folder is not told: the user sees it already
				const front = !document.hidden && document.hasFocus();
				const mails = (r.mails || []).filter(m => !(front && I.route().space === 'mail'));
				if (mails.length > 3) {
					const byBox = mails[0].account;
					show(t('InboxBrowserNewMails', mails.length), mails.every(m => m.account === byBox) ? byBox : '', 'inbox-mails', () => openMail(mails[mails.length - 1]));
				} else {
					mails.forEach(m => show(m.from || t('InboxBrowserNewMail'), m.subject + (m.account ? '\n' + m.account : ''), 'inbox-mail-' + m.id, () => openMail(m)));
				}
				(r.notifs || []).forEach(n => show(textOf(n), n.subject || n.account || '', 'inbox-notif-' + n.id, async () => {
					try {
						const res = await post('notification', 'open', { id: n.id });
						apply(res);
						if (res.mail) {
							openMail({ folder: res.folder, id: res.mail });
						} else if (res.url) {
							I.go(res.url);
						}
					} catch (err) {
						toast(err.message, { error: true });
					}
				}));
			}
		} catch (e) {
			// Network lost: next time
		}
		B.busy = false;
		B.timer = setTimeout(fresh, 30000);
	}
	Dolibarr.on('inbox:params:changed', setBrowser);
	Dolibarr.on('inbox:app:leader', () => setBrowser({ browser_notify: B.on ? '1' : '0' }));

	function bell() {
		return $('.ibx-rail .ibx-js-bell');
	}

	function paintBadge() {
		const b = bell();
		if (!b) {
			return;
		}
		const badge = $('.ibx-rail__badge', b);
		badge.hidden = N.unread <= 0;
		// Reading on a phone hides the top bar: the back arrow carries a dot instead (css)
		document.documentElement.classList.toggle('ibx-has-notif', N.unread > 0);
		badge.textContent = N.unread > 99 ? '99+' : String(N.unread);
		const label = N.unread > 0 ? t('InboxNotificationsUnread', N.unread) : t('InboxNotifications');
		b.setAttribute('aria-label', label);
		b.title = label;
	}

	/** Counts from the server: a new last notification = a new one */
	function apply(c) {
		if (!c) {
			return;
		}
		const isNew = N.last >= 0 && (c.last || 0) > N.last && (c.unread || 0) > 0;
		N.unread = c.unread || 0;
		N.last = c.last || 0;
		N.at = Date.now();
		paintBadge();
		if (typeof c.mails === 'number') {
			N.mailFolders = new Set(c.mail_folders || []);
			paintMails(c.mails);
		}
		if (isNew && browserReady()) {
			// A new notification: told now rather than at the next pass
			fresh();
		}
		if (isNew) {
			Dolibarr.executeHook('inbox:notification:new', { unread: N.unread, last: N.last });
			if (N.panel) {
				load(false);
			}
		}
	}

	function idle() {
		let seen = 0;
		try {
			seen = parseInt(localStorage.getItem(I.scope('inbox-activity')) || '0', 10) || 0;
		} catch (e) {
			seen = 0;
		}
		return seen > 0 && Date.now() - seen > IDLE_MS;
	}

	async function refresh() {
		try {
			apply(await api('notification', 'count'));
		} catch (e) {
			// Network lost: next time
		}
	}

	function when(ts) {
		const d = new Date(ts * 1000);
		const today = new Date();
		const time = d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
		return d.toDateString() === today.toDateString() ? time : d.toLocaleDateString([], { day: 'numeric', month: 'short' }) + ' ' + time;
	}

	function initials(name) {
		const parts = String(name || '?').trim().split(/[\s.@_-]+/).filter(Boolean);
		return ((parts[0] || '?')[0] + (parts.length > 1 ? parts[1][0] : '')).toUpperCase();
	}
	function hue(text) {
		let h = 0;
		for (const c of String(text || '')) {
			h = (h * 31 + c.charCodeAt(0)) % 360;
		}
		return h;
	}

	/** What a notification says: its type, or the text given by the module that added it */
	function textOf(n) {
		const who = n.actor ? n.actor.name : '';
		if (n.type === 'assign') {
			return t('InboxNotifAssign', who);
		}
		if (n.type === 'send_failed') {
			return t('InboxNotifSendFailed');
		}
		if (n.type === 'box_error') {
			return t('InboxNotifBoxError');
		}
		if (n.type === 'bounce') {
			return t('InboxNotifBounce', n.detail || '');
		}
		if (n.type === 'access_self') {
			return t('InboxNotifAccessSelf', who);
		}
		if (n.type === 'rule_redirect') {
			return t('InboxNotifRuleRedirect', who);
		}
		return n.text || n.type;
	}

	function itemHtml(n) {
		const actor = n.actor ? '<span class="ibx-avatar ibx-avatar--sm" style="--ibx-avatar-h:' + hue('u' + n.actor.id) + '" aria-hidden="true">' + esc(initials(n.actor.name)) + '</span>'
			: '<span class="ibx-avatar ibx-avatar--sm ibx-avatar--me" aria-hidden="true">' + ic('bell') + '</span>';
		return '<li><button type="button" class="ibx-notif' + (n.read ? '' : ' is-unread') + '" data-notif="' + n.id + '">' + actor
			+ '<span class="ibx-notif__text"><span class="ibx-notif__what">' + (n.read ? '' : '<span class="ibx-sr-only">' + th('InboxUnread') + ' </span>') + esc(textOf(n)) + '</span>'
			+ (n.subject ? '<span class="ibx-notif__subject ibx-ellipsis">' + esc(n.subject) + '</span>' : '')
			+ '<span class="ibx-notif__meta ibx-muted">' + (n.account ? esc(n.account) + ' · ' : '') + '<time>' + esc(when(n.date)) + '</time></span></span>'
			+ (n.read ? '' : '<span class="ibx-notif__dot" aria-hidden="true"></span>') + '</button>'
			+ '<button type="button" class="ibx-btn ibx-btn--icon ibx-btn--sm ibx-btn--ghost ibx-notif__archive ibx-js-notifarchive" data-archive="' + n.id + '"'
			+ ' title="' + th('InboxArchive') + '" aria-label="' + th('InboxArchive') + '">' + ic('archive') + '</button></li>';
	}

	function paintPanel() {
		if (!N.panel) {
			return;
		}
		const list = $('.ibx-js-notiflist', N.panel);
		$('.ibx-js-notifreadall', N.panel).disabled = N.unread <= 0;
		$('.ibx-js-notifarchiveall', N.panel).disabled = !N.items.length;
		if (!N.items.length) {
			list.innerHTML = N.loading ? '<div class="ibx-notifpanel__empty"><span class="ibx-skeleton"></span><span class="ibx-skeleton" style="width:70%"></span></div>'
				: '<div class="ibx-empty ibx-notifpanel__empty">' + ic('bell', 'ibx-empty__icon') + '<div>' + th('InboxNotifEmpty') + '</div></div>';
			return;
		}
		list.innerHTML = '<ul class="ibx-notifpanel__list">' + N.items.map(itemHtml).join('') + '</ul>'
			+ (N.more ? '<div class="ibx-formfoot"><button type="button" class="ibx-btn ibx-btn--sm ibx-js-notifmore">' + th('InboxNotifMore') + '</button></div>' : '');
	}

	async function load(append) {
		N.loading = true;
		if (!append) {
			paintPanel();
		}
		try {
			const before = append && N.items.length ? N.items[N.items.length - 1].id : 0;
			const res = await api('notification', 'list', { before });
			N.items = append ? N.items.concat(res.items) : res.items;
			N.more = !!res.more;
			apply(res);
		} catch (err) {
			if (N.panel && !N.items.length) {
				$('.ibx-js-notiflist', N.panel).innerHTML = '<div class="ibx-empty">' + ic('warning', 'ibx-empty__icon') + '<div>' + esc(err.message) + '</div></div>';
				N.loading = false;
				return;
			}
		}
		N.loading = false;
		paintPanel();
	}

	function place() {
		const b = bell();
		if (!N.panel || !b) {
			return;
		}
		const r = b.getBoundingClientRect();
		const phone = window.matchMedia('(max-width: 63.9375rem)').matches;
		const p = N.panel;
		if (phone) {
			p.style.left = '0.5rem';
			p.style.right = '0.5rem';
			p.style.top = (r.bottom + 4) + 'px';
			p.style.bottom = 'auto';
		} else {
			p.style.left = (r.right + 8) + 'px';
			p.style.right = 'auto';
			p.style.top = 'auto';
			p.style.bottom = Math.max(8, window.innerHeight - r.bottom) + 'px';
		}
	}

	function outside(e) {
		// A toast (« Undo » of an archive) is not outside: the panel stays to show the notification back
		if (N.panel && !N.panel.contains(e.target) && !(bell() && bell().contains(e.target)) && !(e.target.closest && e.target.closest('#ibx-toasts'))) {
			close(false);
		}
	}

	function open() {
		if (N.panel) {
			return;
		}
		const p = document.createElement('div');
		p.className = 'ibx-notifpanel';
		p.setAttribute('role', 'dialog');
		p.setAttribute('aria-label', t('InboxNotifications'));
		p.innerHTML = '<div class="ibx-notifpanel__head"><h2 class="ibx-notifpanel__title">' + th('InboxNotifications') + '</h2>'
			+ '<button type="button" class="ibx-btn ibx-btn--sm ibx-btn--ghost ibx-js-notifreadall">' + ic('mark-read') + ' ' + th('InboxNotifReadAll') + '</button>'
			+ '<button type="button" class="ibx-btn ibx-btn--icon ibx-btn--sm ibx-btn--ghost ibx-js-notifarchiveall" title="' + th('InboxNotifArchiveAll') + '" aria-label="' + th('InboxNotifArchiveAll') + '">' + ic('archive') + '</button>'
			+ '<a class="ibx-btn ibx-btn--icon ibx-btn--sm ibx-js-notifsettings" href="#/settings/notifications" aria-label="' + th('InboxNotifSettings') + '" title="' + th('InboxNotifSettings') + '">' + ic('settings') + '</a>'
			+ '<button type="button" class="ibx-btn ibx-btn--icon ibx-btn--sm ibx-js-notifclose" aria-label="' + th('InboxCloseWindow') + '">' + ic('close') + '</button></div>'
			+ '<div class="ibx-notifpanel__body ibx-js-notiflist" aria-live="polite"></div>';
		document.body.appendChild(p);
		N.panel = p;
		N.items = [];
		place();
		bell().setAttribute('aria-expanded', 'true');
		p.addEventListener('click', onPanelClick);
		p.addEventListener('keydown', e => {
			if (e.key === 'Escape') {
				e.preventDefault();
				e.stopPropagation();
				close(true);
			} else if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
				const items = $$('[data-notif]', p);
				const i = items.indexOf(document.activeElement);
				if (items.length) {
					e.preventDefault();
					items[(i + (e.key === 'ArrowDown' ? 1 : items.length - 1)) % items.length].focus();
				}
			}
		});
		setTimeout(() => document.addEventListener('pointerdown', outside, true), 0);
		window.addEventListener('resize', place);
		load(false).then(() => {
			const first = N.panel && $('[data-notif]', N.panel);
			(first || $('.ibx-js-notifclose', p)).focus();
		});
	}

	function close(focusBell) {
		if (!N.panel) {
			return;
		}
		N.panel.remove();
		N.panel = null;
		document.removeEventListener('pointerdown', outside, true);
		window.removeEventListener('resize', place);
		const b = bell();
		if (b) {
			b.setAttribute('aria-expanded', 'false');
			if (focusBell) {
				b.focus();
			}
		}
	}

	/**
	 * Archive notifications (none given: all of the bell): out of the list at once, back with « Undo »
	 * @param {number[]} ids
	 */
	async function archive(ids) {
		const kept = N.items;
		const focusNext = ids.length === 1 ? (() => {
			const i = kept.findIndex(n => n.id === ids[0]);
			return kept[i + 1] || kept[i - 1] || null;
		})() : null;
		N.items = ids.length ? kept.filter(n => !ids.includes(n.id)) : [];
		paintPanel();
		let res;
		try {
			res = await post('notification', 'archive', { ids });
		} catch (err) {
			N.items = kept;
			paintPanel();
			toast(err.message, { error: true });
			return;
		}
		apply(res);
		if (N.panel) {
			const next = focusNext && $('[data-notif="' + focusNext.id + '"]', N.panel);
			(next || $('.ibx-js-notifclose', N.panel)).focus();
		}
		const done = res.archived || [];
		if (!done.length) {
			return;
		}
		toast(done.length > 1 ? t('InboxNotifArchivedMany', done.length) : t('InboxNotifArchived'), { action: t('InboxUndo'), onAction: async () => {
			try {
				apply(await post('notification', 'unarchive', { ids: done }));
			} catch (err) {
				toast(err.message, { error: true });
				return;
			}
			if (N.panel) {
				load(false);
			}
		} });
	}

	async function onPanelClick(e) {
		if (e.target.closest('.ibx-js-notifsettings')) {
			close(false);
			return;
		}
		if (e.target.closest('.ibx-js-notifclose')) {
			close(true);
			return;
		}
		if (e.target.closest('.ibx-js-notifmore')) {
			load(true);
			return;
		}
		if (e.target.closest('.ibx-js-notifreadall')) {
			try {
				apply(await post('notification', 'read', {}));
				N.items.forEach(n => {
					n.read = 1;
				});
				paintPanel();
			} catch (err) {
				toast(err.message, { error: true });
			}
			return;
		}
		const one = e.target.closest('.ibx-js-notifarchive');
		if (one || e.target.closest('.ibx-js-notifarchiveall')) {
			archive(one ? [+one.dataset.archive] : []);
			return;
		}
		const item = e.target.closest('[data-notif]');
		if (!item) {
			return;
		}
		let res;
		try {
			res = await post('notification', 'open', { id: +item.dataset.notif });
		} catch (err) {
			toast(err.message, { error: true });
			return;
		}
		apply(res);
		const n = N.items.find(x => x.id === +item.dataset.notif);
		if (n) {
			n.read = 1;
		}
		if (res.problem) {
			// Mail removed from the server, or mailbox not readable any more: said, nothing of the mail shown
			paintPanel();
			toast(res.message, { error: true });
			return;
		}
		close(false);
		if (res.mail) {
			Dolibarr.executeHook('inbox:mail:goto', { folder: res.folder, mail: res.mail });
			if (I.route().space !== 'mail') {
				I.go('#/mail');
			}
		} else if (res.url && res.url.indexOf('#/outbox') === 0 && I.outboxDialog) {
			// A sending failed: the list of the mails waiting, to take it back
			I.outboxDialog();
		} else if (res.url) {
			I.go(res.url);
		}
	}

	function toggle() {
		if (N.panel) {
			close(true);
		} else {
			open();
		}
	}

	Dolibarr.on('inbox:notif:state', c => apply(c));
	Dolibarr.on('inbox:app:ready', () => {
		setBrowser(I.ctxJson('INBOX_PARAMS_JSON', {}));
		const b = bell();
		if (!b) {
			return;
		}
		b.addEventListener('click', toggle);
		refresh();
		// The Mail space brings the count every 20 s; elsewhere (or paused), once a minute
		setInterval(() => {
			if (!document.hidden && Date.now() - N.at > 50000 && !idle()) {
				refresh();
			}
		}, 60000);
		document.addEventListener('visibilitychange', () => {
			if (!document.hidden && Date.now() - N.at > 50000) {
				refresh();
			}
		});
	});
	// Shift+N: the panel, from anywhere but a field
	document.addEventListener('keydown', e => {
		const el = e.target;
		if (e.key === 'N' && e.shiftKey && !e.ctrlKey && !e.metaKey && !e.altKey && !(el && (el.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(el.tagName)))) {
			e.preventDefault();
			toggle();
		}
	});
})();
