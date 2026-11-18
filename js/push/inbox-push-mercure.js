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
 * Adapter of the push driver Mercure 1.x (1.124.0), loaded by index.php when it is the driver of the settings.
 * config: url (address of the hub, topics of this instance), token (his mailboxes only, 15 minutes), given by
 * ajax/event.php. The token goes in the header Authorization: Mercure 1 refuses it in the address, and EventSource
 * cannot send a header, so the stream is read with fetch. Leader, relay between the tabs, catch up and fallback stay
 * in js/inbox-live.js; a new token (every 15 minutes, mailboxes changed) comes with a new connect().
 */
(function () {
	'use strict';

	const I = Dolibarr.tools.inbox;
	if (!I.push || typeof fetch === 'undefined' || typeof AbortController === 'undefined' || typeof TextDecoder === 'undefined') {
		return;
	}
	I.push.register('mercure', {
		connect(config, on) {
			let ctrl = null;
			let closed = false;
			let retry = 0;
			let timer = 0;

			/** One message of the stream (lines « data: »; comments « : » are the heartbeats of the hub) */
			function message(block) {
				const data = block.split('\n').filter(l => l.startsWith('data:')).map(l => l.slice(5).replace(/^ /, '')).join('\n');
				if (!data) {
					return;
				}
				let ev = null;
				try {
					ev = JSON.parse(data);
				} catch (err) {
					return;
				}
				if (ev && typeof ev === 'object' && ev.type) {
					on.event(ev);
				}
			}
			async function run() {
				ctrl = new AbortController();
				let resp;
				try {
					resp = await fetch(config.url, { headers: { 'Accept': 'text/event-stream', 'Authorization': 'Bearer ' + config.token }, cache: 'no-store', credentials: 'omit', signal: ctrl.signal });
				} catch (err) {
					return again(false);
				}
				if (resp.status === 401 || resp.status === 403) {
					// Token refused (expired, wrong key): js/inbox-live.js asks a new one
					return closed ? null : on.error(true);
				}
				if (!resp.ok || !resp.body) {
					return again(false);
				}
				retry = 0;
				on.open();
				const reader = resp.body.getReader();
				const dec = new TextDecoder();
				let buf = '';
				try {
					for (;;) {
						const { value, done } = await reader.read();
						if (done) {
							break;
						}
						buf += dec.decode(value, { stream: true }).replace(/\r\n?/g, '\n');
						let cut;
						while ((cut = buf.indexOf('\n\n')) >= 0) {
							message(buf.slice(0, cut));
							buf = buf.slice(cut + 2);
						}
					}
				} catch (err) {
					// aborted or cut: tried again below
				}
				return again(false);
			}
			/** Cut (network, hub restarted): try again, slower each time (2 s to 60 s) */
			function again(fatal) {
				if (closed) {
					return null;
				}
				on.error(fatal);
				retry++;
				timer = setTimeout(run, Math.min(60000, 1000 * Math.pow(2, retry)));
				return null;
			}
			run();
			return {
				close() {
					closed = true;
					clearTimeout(timer);
					if (ctrl) {
						ctrl.abort();
					}
				},
			};
		},
	});
})();
