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
 * Setup page of Inbox (admin/setup.php), 1.135.0: are the folders dev/ and test/ of a Git deployment readable from the
 * web? Each present folder has a web-probe.txt; it is fetched without cookies, as anybody would. The warning (written
 * by the page, hidden) is shown only when one of them answers 200 with its marker: a server that answers 200 to any
 * address (page of the application, catch-all) is not taken for an exposure. A test from the server to its own address
 * would fail too often (proxy, internal DNS, certificate): the browser sees what an outsider sees.
 */
(function () {
	'use strict';

	const MARKER = 'inbox-web-probe';

	function probe(url) {
		return fetch(url, { cache: 'no-store', credentials: 'omit', redirect: 'manual' })
			.then((r) => (r.status === 200 ? r.text() : ''))
			.then((text) => text.indexOf(MARKER) === 0)
			.catch(() => false);
	}

	function run() {
		const box = document.getElementById('inbox-devfolders');
		if (!box || !window.fetch) {
			return;
		}
		let probes;
		try {
			probes = JSON.parse(box.getAttribute('data-probe') || '{}');
		} catch (e) {
			return;
		}
		const folders = Object.keys(probes);
		Promise.all(folders.map((folder) => probe(probes[folder]))).then((readable) => {
			const exposed = folders.filter((folder, i) => readable[i]);
			if (!exposed.length) {
				return;
			}
			const list = box.querySelector('[data-devfolders-list]');
			if (list) {
				list.textContent = exposed.map((folder) => folder + '/').join(', ');
			}
			box.hidden = false;
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', run);
	} else {
		run();
	}
})();
