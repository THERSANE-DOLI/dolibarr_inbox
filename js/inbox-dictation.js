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
 * Voice typing in the writing (1.28.0): the speech recognition of the browser, after the consent of the user for the
 * receiver of his voice, named. Used by js/inbox-compose.js through Dolibarr.tools.inbox.dictation, tested alone in
 * dev/editor-tests (a fake recognition).
 *
 * A writing w gives: w.dlg (its window, with the button [data-cmd="dictate"] and the bar .ibx-js-dictbar),
 * w.editor (insertText, focus), w.data.dictation ({accounts, consents} of ajax/compose.php open), w.changed(),
 * w.closed. The module keeps w.rec (recognition running), w.recStopped and w.dictField.
 *
 * The words go where the cursor is (1.28.3): a text field of the writing (the instruction of the AI...) when it
 * has the focus, else the text of the mail. The microphone button never takes the focus from that field.
 */
(function () {
	'use strict';

	const I = Dolibarr.tools.inbox;
	const { esc, t, th, ic, post, toast, dialog, $ } = I;

	/**
	 * Speech recognition of the browser and who gets the voice, told from the browser (its interface does not say it):
	 * Chrome sends it to Google, Edge to Microsoft, Safari to Apple; another one: « the maker of your browser ».
	 * Firefox has none, Brave refuses it: no button there.
	 * @returns {{SR: Function, provider: string}|null}
	 */
	function speech() {
		const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
		if (!SR || navigator.brave) {
			return null;
		}
		const ua = navigator.userAgent || '';
		let provider = 'browser';
		if (/\bEdg\//.test(ua)) {
			provider = 'microsoft';
		} else if (/\bOPR\/|\bVivaldi\b|\bYaBrowser\b|\bSamsungBrowser\b/.test(ua)) {
			provider = 'browser';
		} else if (/\bChrome\/|\bCriOS\//.test(ua)) {
			provider = 'google';
		} else if (/\bSafari\//.test(ua) && /\bVersion\//.test(ua)) {
			provider = 'apple';
		}
		return { SR, provider };
	}

	/**
	 * The button follows the browser and the mailbox of the identity
	 * @param {object} w Writing
	 * @param {number} account Mailbox of the identity chosen
	 */
	function paintButton(w, account) {
		const b = $('[data-cmd="dictate"]', w.dlg);
		if (!b) {
			return;
		}
		if (!b.dataset.keepFocus) {
			// A click on the microphone leaves the cursor in the field where it is (where the words will go)
			b.dataset.keepFocus = '1';
			b.addEventListener('mousedown', e => e.preventDefault());
		}
		const ok = !!(w.data.dictation && (byServer(w) ? canRecord() : speech()) && w.data.dictation.accounts.includes(account));
		b.hidden = !ok;
		if (!ok && w.rec) {
			stop(w);
		}
	}

	/**
	 * Start or stop; the first time toward a receiver, its name and what leaves, then the choice of the user
	 * @param {object} w Writing
	 * @param {number} account Mailbox of the identity chosen
	 */
	function toggle(w, account) {
		if (w.rec) {
			stop(w);
			return;
		}
		const consents = w.data.dictation.consents || {};
		if (byServer(w)) {
			// The voice is a biometric data: always agreed to once, even when it stays on the private network (then
			// told so); the server named
			if (consents.server) {
				startServer(w, account);
				return;
			}
			const local = !!w.data.dictation.local;
			askConsent(w, account, 'server', local ? w.data.dictation.host || '' : serverName(w), local ? 'InboxDictateConsentServerLocalText' : 'InboxDictateConsentServerText', () => startServer(w, account));
			return;
		}
		const sp = speech();
		if (!sp) {
			return;
		}
		if (consents[sp.provider]) {
			start(w, sp);
			return;
		}
		askConsent(w, account, sp.provider, t('InboxDictateProvider_' + sp.provider), 'InboxDictateConsentText', () => start(w, sp));
	}

	/**
	 * The receiver named, what leaves told; the consent kept (with the mailbox, for the journal), then started
	 * @param {object} w Writing
	 * @param {number} account Mailbox
	 * @param {string} provider Receiver
	 * @param {string} who Its name
	 * @param {string} key Text of the dialog
	 * @param {Function} go Start
	 */
	function askConsent(w, account, provider, who, key, go) {
		dialog({
			title: t('InboxDictateConsentTitle'),
			body: '<p>' + th(key, who) + '</p><p class="ibx-muted">' + th('InboxDictateConsentRevoke') + '</p>',
			actions: [{ label: t('InboxDictateConsentOk'), primary: true, run: async () => {
				try {
					const r = await post('dictation', 'consent', { provider, account });
					w.data.dictation.consents = r.consents;
				} catch (err) {
					toast(err.message, { error: true });
					return false;
				}
				go();
			} }],
		});
	}

	/**
	 * A text field of the writing that can get the words (textarea, text input), else null (the text of the mail)
	 * @param {object} w Writing
	 * @param {Element|null} el Element
	 * @returns {HTMLInputElement|HTMLTextAreaElement|null}
	 */
	function fieldOf(w, el) {
		if (!el || !w.dlg.contains(el) || el.disabled || el.readOnly) {
			return null;
		}
		if (el.tagName === 'TEXTAREA' || (el.tagName === 'INPUT' && ['text', 'search', ''].includes((el.getAttribute('type') || '').toLowerCase()))) {
			return el;
		}
		return null;
	}

	/**
	 * Words put in a text field at its caret, a space first after a word; the field told (its own listeners)
	 * @param {HTMLInputElement|HTMLTextAreaElement} f Field
	 * @param {string} text Text
	 */
	function intoField(f, text) {
		const startPos = f.selectionStart ?? f.value.length;
		const endPos = f.selectionEnd ?? startPos;
		const before = f.value.slice(Math.max(0, startPos - 1), startPos);
		const value = (before !== '' && !/\s/.test(before) ? ' ' : '') + text;
		f.setRangeText(value, startPos, endPos, 'end');
		f.dispatchEvent(new Event('input', { bubbles: true }));
	}

	/** The bar says where the words go */
	function paintTarget(w) {
		const where = $('.ibx-js-dictwhere', w.dlg);
		if (where) {
			where.textContent = w.dictField ? ' · ' + t('InboxDictateIntoField', w.dictField.getAttribute('aria-label') || (w.dictField.labels && w.dictField.labels[0] ? w.dictField.labels[0].textContent : '') || w.dictField.placeholder || '') : '';
		}
	}

	/**
	 * The field that gets the words follows the cursor while a dictation runs
	 * @param {object} w Writing
	 */
	function follow(w) {
		if (!w.dictFollow) {
			w.dictFollow = e => {
				if (!w.rec) {
					return;
				}
				if (e.target.closest && e.target.closest('.ibx-js-editor, .ibx-compose__editor')) {
					w.dictField = null;
				} else if (fieldOf(w, e.target)) {
					w.dictField = e.target;
				}
				paintTarget(w);
			};
			w.dlg.addEventListener('focusin', w.dictFollow);
		}
	}

	/**
	 * @param {object} w Writing
	 * @param {{SR: Function, provider: string}} sp Recognition and receiver
	 */
	function start(w, sp) {
		// Where the words go: the field that has the cursor, else the text of the mail; it follows the cursor after
		w.dictField = fieldOf(w, document.activeElement);
		follow(w);
		const rec = new sp.SR();
		rec.lang = (I.ctx('MAIN_LANG_DEFAULT', 'fr_FR') || 'fr_FR').replace('_', '-');
		rec.continuous = true;
		rec.interimResults = true;
		const bar = $('.ibx-js-dictbar', w.dlg);
		const b = $('[data-cmd="dictate"]', w.dlg);
		bar.innerHTML = '<span class="ibx-dict__dot" aria-hidden="true"></span><b>' + th('InboxDictateOn') + '</b><span class="ibx-muted"> · ' + esc(t('InboxDictateProvider_' + sp.provider)) + '<span class="ibx-js-dictwhere"></span></span>'
			+ '<span class="ibx-dict__interim ibx-ellipsis ibx-js-dictinterim"></span>'
			+ '<button type="button" class="ibx-btn ibx-btn--sm ibx-js-dictstop">' + ic('close') + ' ' + th('InboxDictateStop') + '</button>';
		$('.ibx-js-dictstop', bar).addEventListener('click', () => stop(w));
		bar.hidden = false;
		if (b) {
			b.setAttribute('aria-pressed', 'true');
		}
		rec.onresult = e => {
			let interim = '';
			for (let i = e.resultIndex; i < e.results.length; i++) {
				const text = e.results[i][0].transcript;
				if (e.results[i].isFinal) {
					// At the cursor, as typed text: never read as HTML (Ctrl+Z takes it back); in the field that has it
					put(w, text);
				} else {
					interim += text;
				}
			}
			const box = $('.ibx-js-dictinterim', bar);
			if (box) {
				box.textContent = interim;
			}
		};
		rec.onerror = e => {
			const known = { 'not-allowed': 'InboxDictateNoMic', 'service-not-allowed': 'InboxDictateNoMic', 'audio-capture': 'InboxDictateNoMicDevice', network: 'InboxDictateNetwork' };
			if (known[e.error]) {
				toast(t(known[e.error]), { error: true });
				w.recStopped = true;
			}
		};
		rec.onend = () => {
			// The browser stops after a silence: started again while the user did not stop it
			if (w.rec === rec && !w.recStopped && !w.closed) {
				try {
					rec.start();
					return;
				} catch (err) {
					// ended below
				}
			}
			if (w.rec === rec) {
				w.rec = null;
				bar.hidden = true;
				bar.innerHTML = '';
				if (b) {
					b.setAttribute('aria-pressed', 'false');
				}
			}
		};
		w.rec = rec;
		w.recStopped = false;
		try {
			rec.start();
		} catch (err) {
			w.rec = null;
			bar.hidden = true;
			toast(t('InboxDictateNoMic'), { error: true });
			return;
		}
		paintTarget(w);
		if (w.dictField) {
			w.dictField.focus();
		} else {
			w.editor.focus();
		}
	}

	/**
	 * Words said put where the cursor is: the field followed, else the text of the mail (as text, never HTML)
	 * @param {object} w Writing
	 * @param {string} text Text
	 */
	function put(w, text) {
		const clean = String(text).trim();
		if (clean && w.dictField && w.dictField.isConnected) {
			intoField(w.dictField, clean + ' ');
		} else if (clean) {
			w.editor.insertText(clean + ' ', true);
			w.changed();
		}
	}

	// ---------- Transcription server (1.35.0): the voice recorded here, cut at the pauses, turned into text by the
	// server chosen by the administrator (through Dolibarr, never straight from the browser) ----------

	/** Silence (ms) that ends a sentence, longest piece (ms), piece without speech dropped after (ms) */
	const PAUSE = 700;
	const LONGEST = 20000;
	const EMPTY = 10000;

	/** @returns {boolean} The browser can record the microphone (any recent browser, Firefox too) */
	function canRecord() {
		return !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia && window.MediaRecorder && (window.AudioContext || window.webkitAudioContext));
	}

	/** @param {object} w Writing @returns {boolean} The administrator chose the transcription server */
	function byServer(w) {
		return !!(w.data.dictation && w.data.dictation.engine === 'server');
	}

	/** Who gets the voice, as the bar and the consent name it */
	function serverName(w) {
		return t(w.data.dictation.local ? 'InboxDictateProvider_serverlocal' : 'InboxDictateProvider_server', w.data.dictation.host || '');
	}

	/**
	 * Start with the server: the microphone asked, the voice measured every 100 ms; a pause after speech (or 20 s)
	 * closes a piece, sent at once; the texts come back in the order they were said
	 * @param {object} w Writing
	 * @param {number} account Mailbox of the identity chosen
	 */
	async function startServer(w, account) {
		const bar = $('.ibx-js-dictbar', w.dlg);
		const b = $('[data-cmd="dictate"]', w.dlg);
		const s = { stopped: false, pending: 0, queue: Promise.resolve(), failed: false, piece: null, floor: 0.01 };
		// Taken at once: a second click while the microphone is asked stops it
		w.rec = { server: true, stop: () => finish() };
		w.recStopped = false;
		w.dictField = fieldOf(w, document.activeElement);
		follow(w);
		try {
			s.stream = await navigator.mediaDevices.getUserMedia({ audio: { echoCancellation: true, noiseSuppression: true } });
		} catch (err) {
			w.rec = null;
			toast(t(err && err.name === 'NotFoundError' ? 'InboxDictateNoMicDevice' : 'InboxDictateNoMic'), { error: true });
			return;
		}
		if (s.stopped || w.closed) {
			s.stream.getTracks().forEach(tr => tr.stop());
			w.rec = null;
			return;
		}
		const AC = window.AudioContext || window.webkitAudioContext;
		s.ctx = new AC();
		const an = s.ctx.createAnalyser();
		an.fftSize = 2048;
		s.ctx.createMediaStreamSource(s.stream).connect(an);
		const buf = new Float32Array(an.fftSize);
		const mime = ['audio/webm;codecs=opus', 'audio/ogg;codecs=opus', 'audio/mp4'].find(m => window.MediaRecorder.isTypeSupported && window.MediaRecorder.isTypeSupported(m)) || '';

		const paint = () => {
			const box = $('.ibx-js-dictinterim', bar);
			if (box) {
				box.textContent = s.pending ? t('InboxDictateWriting') : '';
			}
		};
		const send = blob => {
			s.pending++;
			paint();
			const type = (blob.type || 'audio/webm').split(';')[0];
			const asked = I.upload('dictation', 'transcribe', { account }, new File([blob], 'voice.' + (type.split('/')[1] || 'webm'), { type }));
			s.queue = s.queue.then(() => asked).then(r => {
				if (r && r.text && !w.closed) {
					put(w, r.text);
				}
			}, err => {
				// Said once per dictation; a consent missing, a mailbox refused or the cap of the hour stops it
				if (!s.failed) {
					s.failed = true;
					toast(err.message, { error: true });
				}
				if (err.status === 403 || err.status === 429) {
					stop(w);
				}
			}).finally(() => {
				s.pending--;
				paint();
				if (s.stopped && !s.pending) {
					close();
				}
			});
		};
		const newPiece = () => {
			const rec = new window.MediaRecorder(s.stream, mime ? { mimeType: mime } : undefined);
			const piece = { rec, chunks: [], spoke: false, at: Date.now(), quiet: 0 };
			rec.ondataavailable = e => {
				if (e.data && e.data.size) {
					piece.chunks.push(e.data);
				}
			};
			rec.onstop = () => {
				if (piece.spoke && piece.chunks.length) {
					send(new Blob(piece.chunks, { type: rec.mimeType || mime || 'audio/webm' }));
				}
			};
			rec.start();
			s.piece = piece;
		};
		const cut = () => {
			const piece = s.piece;
			s.piece = null;
			if (piece && piece.rec.state !== 'inactive') {
				piece.rec.stop();
			}
			if (!s.stopped) {
				newPiece();
			}
		};
		s.timer = setInterval(() => {
			const piece = s.piece;
			if (!piece) {
				return;
			}
			an.getFloatTimeDomainData(buf);
			let sum = 0;
			for (let i = 0; i < buf.length; i++) {
				sum += buf[i] * buf[i];
			}
			const rms = Math.sqrt(sum / buf.length);
			const now = Date.now();
			// Speech: well above the noise of the room, learnt while nobody speaks
			if (rms > Math.max(0.012, s.floor * 2.5)) {
				piece.spoke = true;
				piece.quiet = 0;
			} else {
				s.floor = s.floor * 0.95 + rms * 0.05;
				piece.quiet = piece.quiet || now;
			}
			if ((piece.spoke && piece.quiet && now - piece.quiet > PAUSE) || (piece.spoke && now - piece.at > LONGEST) || (!piece.spoke && now - piece.at > EMPTY)) {
				cut();
			}
		}, 100);

		// The bar, as with the browser: who gets the voice, where the words go, « writing... » while a piece is away
		bar.innerHTML = '<span class="ibx-dict__dot" aria-hidden="true"></span><b>' + th('InboxDictateOn') + '</b><span class="ibx-muted"> · ' + esc(serverName(w)) + '<span class="ibx-js-dictwhere"></span></span>'
			+ '<span class="ibx-dict__interim ibx-ellipsis ibx-js-dictinterim" aria-live="polite"></span>'
			+ '<button type="button" class="ibx-btn ibx-btn--sm ibx-js-dictstop">' + ic('close') + ' ' + th('InboxDictateStop') + '</button>';
		$('.ibx-js-dictstop', bar).addEventListener('click', () => stop(w));
		bar.hidden = false;
		if (b) {
			b.setAttribute('aria-pressed', 'true');
		}
		newPiece();
		paintTarget(w);
		if (w.dictField) {
			w.dictField.focus();
		} else {
			w.editor.focus();
		}

		/** The microphone let go at once; the last piece still sent, the bar kept until its text is back */
		function finish() {
			if (s.stopped) {
				return;
			}
			s.stopped = true;
			clearInterval(s.timer);
			if (s.piece && s.piece.rec.state !== 'inactive') {
				s.piece.rec.stop();
			}
			s.piece = null;
			if (s.stream) {
				s.stream.getTracks().forEach(tr => tr.stop());
			}
			if (s.ctx) {
				s.ctx.close().catch(() => {});
			}
			if (b) {
				b.setAttribute('aria-pressed', 'false');
			}
			w.rec = null;
			// The last piece is handed over after its stop event: the bar goes when nothing is away any more
			setTimeout(() => {
				if (!s.pending) {
					close();
				}
			}, 0);
		}
		function close() {
			if (!w.rec) {
				bar.hidden = true;
				bar.innerHTML = '';
			}
		}
	}

	/**
	 * Stop by the user (button, Escape, closing of the writing)
	 * @param {object} w Writing
	 */
	function stop(w) {
		if (!w.rec) {
			return;
		}
		w.recStopped = true;
		try {
			w.rec.stop();
		} catch (err) {
			// already stopped
		}
	}

	I.dictation = { speech, canRecord, paintButton, toggle, stop };
})();
