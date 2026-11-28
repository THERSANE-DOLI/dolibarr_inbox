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
 * Unread counter of inbox in the top bar of the other pages of Dolibarr (1.21.0).
 * - The badge goes onto the icon of the entry Inbox of the top menu (the menu entries have no hook on the server);
 *   the envelope printed by the hook stays only when that entry is not in the page (menu hidden, other menu manager).
 * - The number comes with the page; the tabs of inbox open in the browser write the new one in the local storage,
 *   read here: no call to the server from the pages of Dolibarr.
 */
(function () {
	'use strict';

	function read(key) {
		try {
			const raw = localStorage.getItem(key);
			const v = raw ? JSON.parse(raw) : null;
			return v && typeof v.n === 'number' ? v : null;
		} catch (e) {
			return null;
		}
	}

	function init() {
		const link = document.querySelector('.inbox-topbar[data-inbox-key]');
		if (!link) {
			return;
		}
		const key = link.dataset.inboxKey;
		const badge = link.querySelector('.inbox-topbar__badge');
		// Links that tell the number on hover and to the screen readers
		let labelled = [link];
		const entry = document.querySelector('#mainmenutd_inbox .topmenuimage');
		if (entry && badge) {
			entry.style.position = 'relative';
			badge.style.position = 'absolute';
			badge.style.top = '-0.45em';
			badge.style.left = '55%';
			badge.style.fontSize = '0.6em';
			badge.style.pointerEvents = 'none';
			entry.appendChild(badge);
			labelled = Array.prototype.slice.call(document.querySelectorAll('#mainmenutd_inbox a'));
			const block = link.closest('.inbox-topbar-block');
			if (block) {
				block.remove();
			}
		}

		function paint(n) {
			if (!badge || !(n >= 0)) {
				return;
			}
			badge.style.display = n > 0 ? '' : 'none';
			badge.textContent = n > 99 ? '99+' : String(n);
			const label = n > 0 ? String(link.dataset.label || '').replace('%s', String(n)) : String(link.dataset.labelNone || '');
			labelled.forEach(a => {
				a.title = label;
				a.setAttribute('aria-label', label);
			});
		}

		paint(parseInt(badge ? badge.textContent : '0', 10) || 0);
		window.addEventListener('storage', e => {
			if (e.key === key) {
				const v = read(key);
				if (v) {
					paint(v.n);
				}
			}
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
