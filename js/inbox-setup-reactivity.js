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
 * Settings of Inbox, block « Refresh of the lists » (1.122.0): the load is computed again while the intervals are
 * typed, and the idle interval is never quicker than the active one. Same figures as admin/setup.php (data-*).
 */
(function () {
	'use strict';

	function start() {
		const table = document.getElementById('inbox-reactivity-load');
		const active = document.querySelector('input[name="INBOX_REACTIVITY_INTERVAL"]');
		const idle = document.querySelector('input[name="INBOX_REACTIVITY_IDLE"]');
		if (!table || !active || !idle) {
			return;
		}
		const users = parseInt(table.dataset.users, 10) || 0;
		const lightMs = parseFloat(table.dataset.lightMs) || 2;
		const fullMs = parseFloat(table.dataset.fullMs) || 20;
		const lang = document.documentElement.lang || undefined;
		const fmt = (v, digits) => {
			const floor = Math.pow(10, -digits);
			if (v > 0 && v < floor) {
				return '< ' + floor.toLocaleString(lang);
			}
			return v.toLocaleString(lang, { minimumFractionDigits: digits, maximumFractionDigits: digits });
		};
		const clamp = (v, min, max, def) => Math.min(max, Math.max(min, parseInt(v, 10) || def));

		function update(fromActive) {
			const a = clamp(active.value, 3, 60, 5);
			idle.min = String(a);
			// The idle interval follows when the active one goes above it
			if (fromActive && (parseInt(idle.value, 10) || 0) < a) {
				idle.value = String(a);
			}
			const i = clamp(idle.value, a, 300, 60);
			const cases = {
				active: [users / a, users / 60],
				idle: [users / i, users / 300],
				standard: [0, users / 20],
			};
			Object.keys(cases).forEach(k => {
				const row = table.querySelector('tr[data-case="' + k + '"]');
				if (!row) {
					return;
				}
				const [light, full] = cases[k];
				row.querySelector('[data-light]').textContent = k === 'standard' ? '0' : fmt(light, 1);
				row.querySelector('[data-full]').textContent = fmt(full, 1);
				row.querySelector('[data-cpu]').textContent = fmt((light * lightMs + full * fullMs) / 1000, 2);
			});
		}
		active.addEventListener('input', () => update(true));
		idle.addEventListener('input', () => update(false));
		idle.addEventListener('change', () => {
			// Below the active interval when leaving the field: back to it
			if ((parseInt(idle.value, 10) || 0) < clamp(active.value, 3, 60, 5)) {
				idle.value = String(clamp(active.value, 3, 60, 5));
				update(false);
			}
		});
		update(false);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', start);
	} else {
		start();
	}
})();
