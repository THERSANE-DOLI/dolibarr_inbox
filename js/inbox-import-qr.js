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
 * Import of mailboxes from the QR codes « Export for mobile » of Thunderbird (1.24.0, analyse-import-thunderbird.md).
 * The image (pasted, dropped, chosen or seen by the camera) is decoded here and never leaves the browser; only the
 * texts of the codes go to the server (ajax/import.php qr_read), which checks them and keeps the passwords. The
 * reader is the BarcodeDetector of the browser, else jsQR (js/vendor/jsqr, loaded at the first image).
 */
(function () {
	'use strict';

	const I = Dolibarr.tools.inbox;
	const { esc, t, th, ic, post, toast, $, $$ } = I;

	let jsQrLoading = null;

	/** jsQR, once, with the nonce of the page (its CSP) */
	function loadJsQr() {
		if (window.jsQR) {
			return Promise.resolve(window.jsQR);
		}
		if (!jsQrLoading) {
			jsQrLoading = new Promise((resolve, reject) => {
				const s = document.createElement('script');
				const any = document.querySelector('script[nonce]');
				if (any && any.nonce) {
					s.nonce = any.nonce;
				}
				s.src = I.ctx('INBOX_JSQR_URL', '');
				s.onload = () => (window.jsQR ? resolve(window.jsQR) : reject(new Error('jsqr')));
				s.onerror = () => {
					jsQrLoading = null;
					reject(new Error('jsqr'));
				};
				document.head.appendChild(s);
			});
		}
		return jsQrLoading;
	}

	let detector;
	/** BarcodeDetector for the QR codes when the browser has one, else null */
	async function nativeDetector() {
		if (detector !== undefined) {
			return detector;
		}
		detector = null;
		if ('BarcodeDetector' in window) {
			try {
				const formats = await window.BarcodeDetector.getSupportedFormats();
				if (formats.includes('qr_code')) {
					detector = new window.BarcodeDetector({ formats: ['qr_code'] });
				}
			} catch (e) {
				detector = null;
			}
		}
		return detector;
	}

	/**
	 * Texts of the QR codes of a picture (image, video frame)
	 * @param {CanvasImageSource} source Picture
	 * @param {number} w Width
	 * @param {number} h Height
	 * @param {boolean} quick One try only (camera)
	 * @returns {Promise<string[]>}
	 */
	async function decode(source, w, h, quick) {
		const native = await nativeDetector();
		if (native) {
			try {
				const found = await native.detect(source);
				if (found.length) {
					return found.map(f => f.rawValue);
				}
			} catch (e) {
				// Read by jsQR below
			}
		}
		let jsQR;
		try {
			jsQR = await loadJsQr();
		} catch (e) {
			const err = new Error('reader');
			err.reader = true;
			throw err;
		}
		// jsQR misses a dense code whose modules are not a whole number of pixels (a capture of a scaled screen):
		// measured on a real export (version 18, 4.6 px per module), it reads at 0.6, 0.7, 1.2 or 1.5 only. So several
		// scales are tried, on a base of 2000 px at most, each with a white margin (a capture cut at the edge of the
		// code has no quiet zone). The camera tries one size per frame.
		const base = Math.min(1, 2000 / Math.max(w, h));
		const tries = quick ? [[Math.min(1, 800 / Math.max(w, h)) / base, true]]
			: [[1, true], [0.7, true], [1.2, true], [1.5, false], [0.6, true], [0.85, true], [1.35, true], [0.5, true], [2, false]];
		const canvas = document.createElement('canvas');
		const c2d = canvas.getContext('2d', { willReadFrequently: true });
		for (const [factor, smooth] of tries) {
			const cw = Math.round(w * base * factor);
			const ch = Math.round(h * base * factor);
			if (Math.max(cw, ch) > 3000 || Math.min(cw, ch) < 100) {
				continue;
			}
			const pad = quick ? 0 : Math.round(Math.max(cw, ch) * 0.06);
			canvas.width = cw + 2 * pad;
			canvas.height = ch + 2 * pad;
			c2d.fillStyle = '#fff';
			c2d.fillRect(0, 0, canvas.width, canvas.height);
			c2d.imageSmoothingEnabled = smooth;
			c2d.drawImage(source, pad, pad, cw, ch);
			const found = jsQR(c2d.getImageData(0, 0, canvas.width, canvas.height).data, canvas.width, canvas.height, { inversionAttempts: quick ? 'dontInvert' : 'attemptBoth' });
			if (found && found.binaryData) {
				// JSON in UTF-8 (names with accents)
				return [new TextDecoder('utf-8').decode(new Uint8Array(found.binaryData))];
			}
			if (!quick) {
				// The page stays alive between two tries
				await new Promise(resolve => setTimeout(resolve, 0));
			}
		}
		return [];
	}

	/**
	 * Head of a code of the export: [1, [number, end], ...], null for another QR code (the server checks the rest)
	 * @param {string} text
	 * @returns {{number:number,end:number,accounts:number}|null}
	 */
	function headOf(text) {
		let data;
		try {
			data = JSON.parse(text);
		} catch (e) {
			return null;
		}
		if (!Array.isArray(data) || data.length < 2 || !Array.isArray(data[1]) || data[1].length !== 2
			|| !Number.isInteger(data[1][0]) || !Number.isInteger(data[1][1]) || data[1][0] < 1 || data[1][1] < data[1][0]) {
			return null;
		}
		return { version: data[0], number: data[1][0], end: data[1][1], accounts: Math.floor((data.length - 2) / 2) };
	}

	/**
	 * Screen of the import by QR codes
	 * @param {HTMLElement} box Place of the screen
	 */
	function render(box) {
		// Texts of the codes read (they hold the passwords: forgotten once sent to the server)
		let codes = new Map();
		let end = 0;
		let stream = null;
		let busy = false;

		box.innerHTML = '<p class="ibx-page__intro">' + th('InboxQrIntro') + '</p>'
			+ '<div class="ibx-qr__zone" tabindex="0" role="group" aria-label="' + th('InboxQrDrop') + '">'
			+ ic('qrcode', 'ibx-qr__icon')
			+ '<div class="ibx-qr__title">' + th('InboxQrDrop') + '</div>'
			+ '<div class="ibx-muted">' + th('InboxQrDropHelp') + '</div>'
			+ '<div class="ibx-qr__actions"><label class="ibx-btn">' + ic('file-image') + ' ' + th('InboxQrChoose') + '<input type="file" accept="image/*" class="ibx-js-qrfile" hidden></label>'
			+ (navigator.mediaDevices && navigator.mediaDevices.getUserMedia && window.isSecureContext ? '<button type="button" class="ibx-btn ibx-js-qrcam">' + ic('camera') + ' ' + th('InboxQrCamera') + '</button>' : '')
			+ '</div><video class="ibx-qr__video" playsinline muted hidden></video></div>'
			+ '<ul class="ibx-qr__codes" aria-live="polite"></ul>'
			+ '<div class="ibx-js-qrstate" role="status" aria-live="polite"></div>'
			+ '<div class="ibx-js-qrlist"></div>';
		const zone = $('.ibx-qr__zone', box);
		const state = $('.ibx-js-qrstate', box);
		const codesList = $('.ibx-qr__codes', box);
		const listBox = $('.ibx-js-qrlist', box);
		const video = $('.ibx-qr__video', box);
		const camBtn = $('.ibx-js-qrcam', box);

		const say = (key, kind, ...args) => {
			state.innerHTML = key ? '<div class="ibx-notice ibx-notice--' + (kind || 'info') + '">' + ic(kind === 'error' ? 'warning' : 'info') + '<div class="ibx-notice__text">' + th(key, ...args) + '</div></div>' : '';
		};
		const restart = () => {
			codes = new Map();
			end = 0;
			codesList.innerHTML = '';
			listBox.innerHTML = '';
			zone.hidden = false;
			say('');
		};

		/** Texts found in a picture: kept, followed, sent when the export is complete */
		const take = async texts => {
			let added = false;
			for (const text of texts) {
				const head = headOf(text);
				if (!head || head.version !== 1) {
					say(head ? 'InboxQrErrorVersion' : 'InboxQrNotExport', 'error');
					continue;
				}
				if (end && head.end !== end) {
					say('InboxQrOtherExport', 'error');
					state.insertAdjacentHTML('beforeend', '<button type="button" class="ibx-btn ibx-js-qrrestart">' + th('InboxQrRestart') + '</button>');
					$('.ibx-js-qrrestart', state).addEventListener('click', restart);
					return;
				}
				if (codes.has(head.number)) {
					say('InboxQrAlready', 'info', head.number);
					continue;
				}
				end = head.end;
				codes.set(head.number, text);
				codesList.insertAdjacentHTML('beforeend', '<li>' + ic('check') + ' ' + th('InboxQrCodeRead', head.number, head.end, head.accounts) + '</li>');
				added = true;
			}
			if (!added) {
				return;
			}
			if (codes.size < end) {
				let next = 1;
				while (codes.has(next)) {
					next++;
				}
				say('InboxQrNext', 'info', next, end);
				return;
			}
			await check();
		};

		/** One picture: decoded here, never sent */
		const readBlob = async blob => {
			if (busy || !blob || !/^image\//.test(blob.type)) {
				return;
			}
			busy = true;
			say('InboxQrReading');
			let bmp = null;
			try {
				bmp = await createImageBitmap(blob);
				const texts = await decode(bmp, bmp.width, bmp.height, false);
				if (!texts.length) {
					say('InboxQrNone', 'error');
				} else {
					await take(texts);
				}
			} catch (err) {
				say(err.reader ? 'InboxQrReaderError' : 'InboxQrNone', 'error');
			} finally {
				if (bmp && bmp.close) {
					bmp.close();
				}
				busy = false;
			}
		};

		// Ctrl+V of a capture, anywhere on the page while the screen is shown
		const onPaste = e => {
			if (!zone.isConnected) {
				document.removeEventListener('paste', onPaste);
				stopCamera();
				return;
			}
			const item = Array.from((e.clipboardData && e.clipboardData.items) || []).find(i => i.kind === 'file' && /^image\//.test(i.type));
			if (item) {
				e.preventDefault();
				readBlob(item.getAsFile());
			}
		};
		document.addEventListener('paste', onPaste);
		zone.addEventListener('dragover', e => {
			if (e.dataTransfer && Array.from(e.dataTransfer.types).includes('Files')) {
				e.preventDefault();
				zone.classList.add('is-over');
			}
		});
		zone.addEventListener('dragleave', e => {
			if (!zone.contains(e.relatedTarget)) {
				zone.classList.remove('is-over');
			}
		});
		zone.addEventListener('drop', e => {
			e.preventDefault();
			zone.classList.remove('is-over');
			readBlob(e.dataTransfer.files[0]);
		});
		$('.ibx-js-qrfile', box).addEventListener('change', e => {
			readBlob(e.target.files[0]);
			e.target.value = '';
		});

		// Camera (phone, laptop): a frame read about 4 times a second until the export is complete
		function stopCamera() {
			if (stream) {
				stream.getTracks().forEach(tr => tr.stop());
				stream = null;
			}
			video.hidden = true;
			video.srcObject = null;
			if (camBtn) {
				camBtn.innerHTML = ic('camera') + ' ' + th('InboxQrCamera');
			}
		}
		const scan = async () => {
			if (!stream || !video.isConnected) {
				stopCamera();
				return;
			}
			if (!busy && video.readyState >= 2 && video.videoWidth) {
				busy = true;
				try {
					const texts = await decode(video, video.videoWidth, video.videoHeight, true);
					busy = false;
					if (texts.length) {
						await take(texts);
					}
				} catch (e) {
					busy = false;
				}
				if (end && codes.size === end) {
					stopCamera();
					return;
				}
			}
			setTimeout(scan, 250);
		};
		if (camBtn) {
			camBtn.addEventListener('click', async () => {
				if (stream) {
					stopCamera();
					return;
				}
				try {
					stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' }, audio: false });
				} catch (err) {
					say('InboxQrCameraError', 'error', err.message || err.name);
					return;
				}
				video.srcObject = stream;
				video.hidden = false;
				await video.play().catch(() => {});
				camBtn.innerHTML = ic('close') + ' ' + th('InboxQrCameraStop');
				scan();
			});
		}

		/** All the codes read: checked by the server, then the choice */
		async function check() {
			stopCamera();
			say('InboxQrChecking');
			const texts = Array.from(codes.values());
			let r;
			try {
				r = await post('import', 'qr_read', { codes: texts });
			} catch (err) {
				say('');
				state.innerHTML = '<div class="ibx-notice ibx-notice--error">' + ic('warning') + '<div class="ibx-notice__text">' + esc(err.message) + '</div></div>'
					+ '<button type="button" class="ibx-btn ibx-js-qrrestart">' + th('InboxQrRestart') + '</button>';
				$('.ibx-js-qrrestart', state).addEventListener('click', restart);
				return;
			}
			// The passwords are on the server now: nothing kept here
			codes = new Map();
			say('');
			zone.hidden = true;
			choose(r);
		}

		/** Table of the accounts found: all checked except the ones inbox cannot take or already has */
		function choose(r) {
			const canTake = a => !a.skip && !a.exists;
			const reason = a => a.exists ? t('InboxQrExists') : t('InboxQrSkip_' + a.skip);
			// Owner of a personal mailbox: the user with the address of the mailbox, else me; any user can be searched
			const me = { id: +(I.ctx('INBOX_USER_ID', 0) || 0), name: I.ctx('INBOX_USER_NAME', '') };
			const seen = new Map([[me.name, me]]);
			r.accounts.forEach(a => {
				if (a.owner) {
					seen.set(a.owner.name, a.owner);
				}
			});
			const typeCell = a => {
				const owner = a.owner || me;
				return '<select class="ibx-field" name="type-' + a.index + '" aria-label="' + th('InboxQrColType') + '"><option value="shared"' + (a.owner ? '' : ' selected') + '>' + th('InboxTypeShared') + '</option>'
					+ '<option value="personal"' + (a.owner ? ' selected' : '') + '>' + th('InboxTypePersonal') + '</option></select>'
					+ '<div class="ibx-qr__owner"' + (a.owner ? '' : ' hidden') + '><input class="ibx-field" name="owner-q-' + a.index + '" list="ibx-qr-owners" autocomplete="off" placeholder="' + th('InboxSearchUser') + '" aria-label="' + th('InboxAccountOwner') + '" value="' + esc(owner.name) + '">'
					+ '<input type="hidden" name="owner-' + a.index + '" value="' + owner.id + '"></div>';
			};
			const pass = a => a.oauth ? '<span class="ibx-status ibx-status--warning">' + th('InboxQrOAuth') + '</span>'
				: (a.password ? th('InboxQrPasswordIn') : '<span class="ibx-status ibx-status--warning">' + th('InboxQrPasswordOut') + '</span>');
			listBox.innerHTML = '<label class="ibx-check ibx-qr__manage"><input type="checkbox" class="ibx-js-manage" checked> ' + th('InboxQrGiveManage') + '</label>'
				+ '<div class="ibx-qr__tablewrap"><table class="ibx-table ibx-qr__table"><thead><tr><th><input type="checkbox" class="ibx-js-all" aria-label="' + th('InboxSelectAll') + '"></th><th>' + th('InboxAccount') + '</th><th>' + th('InboxQrColServer') + '</th><th>' + th('InboxQrColPassword') + '</th><th>' + th('InboxQrColType') + '</th></tr></thead><tbody>'
				+ r.accounts.map(a => '<tr' + (canTake(a) ? '' : ' class="is-off"') + '><td><input type="checkbox" name="qr" value="' + a.index + '"' + (canTake(a) ? ' checked' : ' disabled') + ' aria-label="' + esc(a.email || a.label) + '"></td>'
					+ '<td><b>' + esc(a.label || a.email) + '</b>' + (a.email && a.email !== a.label ? '<div class="ibx-muted">' + esc(a.email) + '</div>' : '') + '</td>'
					+ '<td>' + esc(a.imap_host) + '<div class="ibx-muted">' + th('InboxQrIdentities', a.identities) + '</div></td>'
					+ '<td>' + (canTake(a) ? pass(a) : '<span class="ibx-muted">' + esc(reason(a)) + '</span>') + '</td>'
					+ '<td>' + (canTake(a) ? typeCell(a) : '') + '</td></tr>').join('')
				+ '</tbody></table></div><datalist id="ibx-qr-owners">' + Array.from(seen.values()).map(u => '<option value="' + esc(u.name) + '"></option>').join('') + '</datalist>'
				+ '<p class="ibx-formrow__help">' + th('InboxQrAfter') + '</p>'
				+ '<div class="ibx-formfoot"><button type="button" class="ibx-btn ibx-js-qrrestart">' + th('InboxQrRestart') + '</button><span class="ibx-formfoot__spacer"></span><button type="button" class="ibx-btn ibx-btn--primary ibx-js-run"></button></div>';
			const boxes = () => $$('[name="qr"]:not(:disabled)', listBox);
			const runBtn = $('.ibx-js-run', listBox);
			const all = $('.ibx-js-all', listBox);
			const count = () => {
				const n = boxes().filter(c => c.checked).length;
				runBtn.textContent = t('InboxQrRun', n);
				runBtn.setAttribute('aria-disabled', n ? 'false' : 'true');
				all.checked = n > 0 && n === boxes().length;
				all.indeterminate = n > 0 && n < boxes().length;
				all.disabled = !boxes().length;
			};
			count();
			// The owner field shown with « Personal »; the users searched as typed (same search as the card of a mailbox)
			let timer = 0;
			listBox.addEventListener('input', e => {
				const q = e.target.closest('[name^="owner-q-"]');
				if (!q) {
					return;
				}
				const hidden = $('[name="' + q.name.replace('owner-q-', 'owner-') + '"]', listBox);
				const hit = seen.get(q.value);
				hidden.value = hit ? hit.id : '';
				q.closest('td').classList.toggle('is-invalid', !hit);
				clearTimeout(timer);
				if (hit) {
					return;
				}
				timer = setTimeout(async () => {
					try {
						const users = (await I.api('access', 'search', { q: q.value })).results.filter(x => x.kind === 'user');
						users.forEach(u => {
							if (!seen.has(u.name)) {
								seen.set(u.name, u);
							}
						});
						if (users.length) {
							$('#ibx-qr-owners', listBox).innerHTML = users.map(u => '<option value="' + esc(u.name) + '"></option>').join('');
						}
						const again = seen.get(q.value);
						hidden.value = again ? again.id : '';
						q.closest('td').classList.toggle('is-invalid', !again);
					} catch (err) {
						// The field stays usable with the users already seen
					}
				}, 200);
			});
			listBox.addEventListener('change', e => {
				const type = e.target.closest('[name^="type-"]');
				if (type) {
					$('.ibx-qr__owner', type.closest('td')).hidden = type.value !== 'personal';
				}
				if (e.target === all) {
					boxes().forEach(c => {
						c.checked = all.checked;
					});
				}
				count();
			});
			$('.ibx-js-qrrestart', listBox).addEventListener('click', restart);
			runBtn.addEventListener('click', async () => {
				const chosen = boxes().filter(c => c.checked).map(c => {
					const sel = $('[name="type-' + c.value + '"]', listBox);
					const personal = !!sel && sel.value === 'personal';
					return { index: Number(c.value), type: personal ? 'personal' : 'shared', owner: personal ? Number($('[name="owner-' + c.value + '"]', listBox).value || 0) : 0 };
				});
				if (!chosen.length) {
					toast(t('InboxImportChoose'), { error: true });
					return;
				}
				// A personal mailbox needs its owner
				const noOwner = chosen.find(c => c.type === 'personal' && !c.owner);
				if (noOwner) {
					toast(t('InboxErrorAccountOwner'), { error: true });
					$('[name="owner-q-' + noOwner.index + '"]', listBox).focus();
					return;
				}
				runBtn.setAttribute('aria-disabled', 'true');
				say('InboxQrRunning', 'info', chosen.length);
				let res;
				try {
					res = await post('import', 'qr_import', { qr_token: r.token, choices: chosen, give_manage: $('.ibx-js-manage', listBox).checked ? 1 : 0 });
				} catch (err) {
					say('');
					toast(err.message, { error: true });
					// A reading is used once: an expired or used one is read again
					restart();
					return;
				}
				I.changed('account', 0);
				say('');
				done(res.results);
			});
		}

		/** Result per mailbox, and the reminder of the passwords in clear */
		function done(results) {
			const icon = { active: 'check', password: 'lock', oauth: 'lock', test: 'warning', exists: 'info', error: 'warning' };
			listBox.innerHTML = '<div class="ibx-notice ibx-notice--warning">' + ic('lock') + '<div class="ibx-notice__text">' + th('InboxQrSecurity')
				+ '<div class="ibx-qr__actions"><button type="button" class="ibx-btn ibx-js-clip">' + th('InboxQrClearClipboard') + '</button></div></div></div>'
				+ '<h2 class="ibx-section__title">' + th('InboxQrDone') + '</h2>'
				+ '<ul class="ibx-checklist">' + results.map(x => '<li class="ibx-checklist__item ibx-checklist__item--' + (x.state === 'active' ? 'ok' : 'warning') + '">' + ic(icon[x.state] || 'info')
					+ '<div><b>' + esc(x.email) + '</b><div class="ibx-muted">' + th('InboxQrState_' + x.state) + (x.message ? ' : ' + esc(x.message) : '') + '</div></div>'
					+ (x.id ? '<a class="ibx-btn ibx-btn--sm" href="#/settings/accounts/' + x.id + '">' + th('InboxQrOpen') + '</a>' : '<span></span>') + '</li>').join('') + '</ul>'
				+ '<div class="ibx-formfoot"><span class="ibx-formfoot__spacer"></span><a class="ibx-btn ibx-btn--primary" href="#/settings/accounts">' + th('InboxNavAccounts') + '</a></div>';
			$('.ibx-js-clip', listBox).addEventListener('click', async () => {
				try {
					await navigator.clipboard.writeText('');
					toast(t('InboxQrClipboardCleared'));
				} catch (e) {
					toast(t('InboxQrClipboardError'), { error: true });
				}
			});
		}
	}

	I.importQr = { render, headOf };
})();
