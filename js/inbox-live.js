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
 * Quicker refresh of the lists (1.121.0): the changes made by the other users (assignment, tags, flags, moves, new
 * mails) show in a few seconds instead of 20.
 * - Only when the administrator turned it on (INBOX_REACTIVITY = 'fast' or 'instant').
 * - Only the leader tab of the browser asks the server, by the light call live.php with a signed token (ajax/event.php),
 *   and passes what it gets to the other tabs (channel « live »).
 * - Rhythm: the active interval while the user is active (any tab), the idle interval after 2 minutes without activity
 *   (both set by the administrator), nothing after 30 minutes (started again by the next activity).
 * - Instant (1.124.0): the leader also listens to the push driver chosen by the administrator (adapter registered by
 *   I.push.register(code, adapter), loaded by index.php). While it is connected, live.php is only asked once a
 *   minute to catch up; when it fails, back to the rhythm above. The journal stays the reference: the events of the
 *   driver never move the cursor, they are only applied sooner (and not twice).
 * - What comes back is numbers only; each tab tells the hook inbox:live:events, the Mail space reads again what it shows
 *   through its usual calls (rights checked). The changes made by this tab itself are left out (X-Inbox-Origin).
 *
 * Adapter of a push driver: { connect(config, on) } returns { close() }; on.open(), on.event(event), on.error(fatal).
 */
(function () {
	'use strict';

	const I = Dolibarr.tools.inbox;
	const ACTIVE_MS = 2 * 60 * 1000;
	const IDLE_MS = 30 * 60 * 1000;
	const CATCH_UP_MS = 60 * 1000;
	const mode = I.ctx('INBOX_REACTIVITY', '');
	const adapters = {};
	const L = {
		token: '', expires: 0, url: '', interval: 5, idle: 60, last: 0, timer: 0, busy: false, failures: 0, asleep: false, on: false, mine: Date.now(),
		// Push: settings given with the token, connection, connected, events already applied (by id)
		push: null, pushKey: '', conn: null, pushOn: false, seen: new Set(),
	};

	/** Adapters of the push drivers register here (js/push/*.js) */
	I.push = {
		register(code, adapter) {
			if (code && adapter && typeof adapter.connect === 'function') {
				adapters[code] = adapter;
			}
		},
	};

	if (mode !== 'fast' && mode !== 'instant') {
		return;
	}

	/** Last activity in any tab of this Dolibarr (kept by the Mail space, at most every 10 s); opening the page counts */
	function lastSeen() {
		let shared = 0;
		try {
			shared = parseInt(localStorage.getItem(I.scope('inbox-activity')) || '0', 10) || 0;
		} catch (e) {
			shared = 0;
		}
		return Math.max(L.mine, shared);
	}
	/** Time before the next call, 0 = asleep */
	function delay() {
		const quiet = Date.now() - lastSeen();
		if (quiet > IDLE_MS) {
			return 0;
		}
		if (L.pushOn) {
			return CATCH_UP_MS;
		}
		return (quiet > ACTIVE_MS ? L.idle : L.interval) * 1000;
	}
	/** The other tabs and the spaces know whether the quick refresh runs (the regular refresh goes slower then) */
	function setOn(on) {
		if (L.on !== on) {
			L.on = on;
			I.channel.post({ type: 'live-state', on });
			Dolibarr.executeHook('inbox:live:state', { on });
		}
	}
	async function newToken() {
		const r = await I.post('event', 'token', {});
		if ((r.mode !== 'fast' && r.mode !== 'instant') || !r.token) {
			return false;
		}
		L.token = r.token;
		L.expires = r.expires;
		L.url = r.url;
		L.interval = Math.max(3, parseInt(r.interval, 10) || 5);
		L.idle = Math.max(L.interval, parseInt(r.idle, 10) || 60);
		if (!L.last) {
			L.last = r.last;
		}
		setPush(r.push || null);
		return true;
	}

	// ---------- Push driver ----------

	/** New settings of the push (new token, mailboxes changed): connect again with them */
	function setPush(push) {
		const key = push ? JSON.stringify(push) : '';
		if (key === L.pushKey) {
			return;
		}
		L.pushKey = key;
		L.push = push;
		closePush();
		openPush();
	}
	function openPush() {
		if (L.conn || !L.push || !I.isLeader() || L.asleep) {
			return;
		}
		const adapter = adapters[L.push.driver];
		if (!adapter) {
			// Script of the adapter missing (blocked, not loaded): the light call alone
			return;
		}
		try {
			L.conn = adapter.connect(L.push.config || {}, {
				open() {
					L.pushOn = true;
					L.failures = 0;
					setOn(true);
					// Connected (again): what happened meanwhile comes from the journal
					schedule(0);
				},
				event: onPushEvent,
				error(fatal) {
					const was = L.pushOn;
					L.pushOn = false;
					if (fatal) {
						// Refused (token expired, hub down for good): a new token at the next call
						closePush();
						L.token = '';
					}
					if (was) {
						schedule(0);
					}
				},
			});
		} catch (e) {
			L.conn = null;
		}
	}
	function closePush() {
		if (L.conn) {
			try {
				L.conn.close();
			} catch (e) {
				// already closed
			}
		}
		L.conn = null;
		L.pushOn = false;
	}
	function onPushEvent(ev) {
		const id = ev ? parseInt(ev.id, 10) || 0 : 0;
		if (!id || id <= L.last || L.seen.has(id)) {
			return;
		}
		L.seen.add(id);
		const msg = { type: 'live', reset: false, events: [ev], last: L.last };
		I.channel.post(msg);
		apply(msg);
	}

	// ---------- Light call ----------

	function schedule(ms) {
		clearTimeout(L.timer);
		L.timer = setTimeout(tick, ms);
	}
	async function tick() {
		clearTimeout(L.timer);
		if (!I.isLeader() || L.busy) {
			return;
		}
		const wait = delay();
		if (!wait) {
			// Asleep: the next activity wakes it up; the connection of the push is closed too (the hub keeps none)
			L.asleep = true;
			closePush();
			setOn(false);
			return;
		}
		L.busy = true;
		let next = wait;
		try {
			if (!L.token || Date.now() / 1000 > L.expires - 60) {
				if (!await newToken()) {
					// Turned off meanwhile by the administrator: the regular refresh only
					L.busy = false;
					closePush();
					setOn(false);
					return;
				}
			}
			openPush();
			const resp = await fetch(L.url + '?since=' + encodeURIComponent(L.last), { credentials: 'same-origin', cache: 'no-store', headers: { 'Accept': 'application/json', 'X-Inbox-Live': L.token } });
			if (resp.status === 401) {
				// Token refused (key of the instance changed, clock): a new one next time, slower if it goes on
				L.token = '';
				throw new Error('token');
			}
			if (resp.status !== 204) {
				if (!resp.ok) {
					throw new Error('status ' + resp.status);
				}
				const r = await resp.json();
				L.last = Math.max(L.last, parseInt(r.last, 10) || 0);
				if (r.renew) {
					L.token = '';
				}
				// The events already given by the push are not applied twice
				const events = (r.events || []).filter(e => !L.seen.has(parseInt(e.id, 10)));
				L.seen.forEach(id => {
					if (id <= L.last) {
						L.seen.delete(id);
					}
				});
				if (r.reset || events.length) {
					const msg = { type: 'live', reset: !!r.reset, events, last: L.last };
					I.channel.post(msg);
					apply(msg);
				}
			}
			L.failures = 0;
			setOn(true);
		} catch (err) {
			// Network or server down: slower each time, never in a loop; the regular refresh still runs
			L.failures++;
			next = Math.min(60000, wait * Math.pow(2, L.failures));
			if (!L.pushOn) {
				setOn(false);
			}
		} finally {
			L.busy = false;
		}
		schedule(next);
	}
	/**
	 * Events received (by this tab or passed by the leader): the changes of this tab left out, the rest told to the
	 * spaces. An access changed: a new token (the mailboxes of the user changed)
	 */
	function apply(msg) {
		// Every tab keeps the cursor: the one that becomes the leader goes on from there
		L.last = Math.max(L.last, parseInt(msg.last, 10) || 0);
		// The bell of this user is told even when this tab caused it (another space, another tab may show it)
		const events = (msg.events || []).filter(e => e.type === 'notif' || !e.origin || e.origin !== I.origin);
		if ((msg.events || []).some(e => e.type === 'access') && I.isLeader()) {
			L.token = '';
			schedule(0);
		}
		if (msg.reset || events.length) {
			Dolibarr.executeHook('inbox:live:events', { reset: !!msg.reset, events });
		}
	}
	function wake() {
		L.mine = Date.now();
		if (L.asleep && I.isLeader()) {
			L.asleep = false;
			schedule(0);
		}
	}

	I.channel.on(msg => {
		if (msg.type === 'live') {
			apply(msg);
		} else if (msg.type === 'live-state') {
			Dolibarr.executeHook('inbox:live:state', { on: !!msg.on });
		} else if (msg.type === 'active') {
			wake();
		}
	});
	// This tab became the leader (the previous one was closed): it takes the calls over, from the cursor it had
	Dolibarr.on('inbox:app:leader', () => schedule(500));
	['pointerdown', 'keydown', 'wheel', 'touchstart'].forEach(ev => document.addEventListener(ev, wake, { passive: true, capture: true }));
	document.addEventListener('visibilitychange', () => {
		if (!document.hidden) {
			wake();
		}
	});
	window.addEventListener('pagehide', closePush);
	if (I.isLeader()) {
		schedule(1000);
	}
})();
