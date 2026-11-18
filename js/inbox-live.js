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
 * - Only when the administrator turned it on (INBOX_REACTIVITY = 'fast').
 * - Only the leader tab of the browser asks the server, by the light call live.php with a signed token (ajax/event.php),
 *   and passes what it gets to the other tabs (channel « live »).
 * - Rhythm: the active interval while the user is active (any tab), the idle interval after 2 minutes without activity
 *   (both set by the administrator), nothing after 30 minutes (started again by the next activity).
 * - What comes back is numbers only; each tab tells the hook inbox:live:events, the Mail space reads again what it shows
 *   through its usual calls (rights checked). The changes made by this tab itself are left out (X-Inbox-Origin).
 */
(function () {
	'use strict';

	const I = Dolibarr.tools.inbox;
	const ACTIVE_MS = 2 * 60 * 1000;
	const IDLE_MS = 30 * 60 * 1000;
	const L = { token: '', expires: 0, url: '', interval: 5, idle: 60, last: 0, timer: 0, busy: false, failures: 0, asleep: false, on: false, mine: Date.now() };

	if (I.ctx('INBOX_REACTIVITY', '') !== 'fast') {
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
		if (r.mode !== 'fast' || !r.token) {
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
		return true;
	}
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
			// Asleep: the next activity wakes it up
			L.asleep = true;
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
					setOn(false);
					return;
				}
			}
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
				if (r.reset || (r.events && r.events.length)) {
					const msg = { type: 'live', reset: !!r.reset, events: r.events || [], last: L.last };
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
			setOn(false);
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
		const events = (msg.events || []).filter(e => !e.origin || e.origin !== I.origin);
		if (events.some(e => e.type === 'access') && I.isLeader()) {
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
	if (I.isLeader()) {
		schedule(1000);
	}
})();
