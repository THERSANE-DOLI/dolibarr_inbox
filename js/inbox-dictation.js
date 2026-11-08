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
 * w.editor (insertHtml, focus), w.data.dictation ({accounts, consents} of ajax/compose.php open), w.changed(),
 * w.closed. The module keeps w.rec (recognition running) and w.recStopped.
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
		const ok = !!(speech() && w.data.dictation && w.data.dictation.accounts.includes(account));
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
		const sp = speech();
		if (!sp) {
			return;
		}
		const consents = w.data.dictation.consents || {};
		if (consents[sp.provider]) {
			start(w, sp);
			return;
		}
		const who = t('InboxDictateProvider_' + sp.provider);
		dialog({
			title: t('InboxDictateConsentTitle'),
			body: '<p>' + th('InboxDictateConsentText', who) + '</p><p class="ibx-muted">' + th('InboxDictateConsentRevoke') + '</p>',
			actions: [{ label: t('InboxDictateConsentOk'), primary: true, run: async () => {
				try {
					const r = await post('dictation', 'consent', { provider: sp.provider, account });
					w.data.dictation.consents = r.consents;
				} catch (err) {
					toast(err.message, { error: true });
					return false;
				}
				start(w, sp);
			} }],
		});
	}

	/**
	 * @param {object} w Writing
	 * @param {{SR: Function, provider: string}} sp Recognition and receiver
	 */
	function start(w, sp) {
		const rec = new sp.SR();
		rec.lang = (I.ctx('MAIN_LANG_DEFAULT', 'fr_FR') || 'fr_FR').replace('_', '-');
		rec.continuous = true;
		rec.interimResults = true;
		const bar = $('.ibx-js-dictbar', w.dlg);
		const b = $('[data-cmd="dictate"]', w.dlg);
		bar.innerHTML = '<span class="ibx-dict__dot" aria-hidden="true"></span><b>' + th('InboxDictateOn') + '</b><span class="ibx-muted"> · ' + esc(t('InboxDictateProvider_' + sp.provider)) + '</span>'
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
					// At the cursor, as typed text: never read as HTML (Ctrl+Z takes it back)
					const clean = text.trim();
					if (clean) {
						w.editor.insertText(clean + ' ', true);
						w.changed();
					}
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
		w.editor.focus();
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

	I.dictation = { speech, paintButton, toggle, stop };
})();
