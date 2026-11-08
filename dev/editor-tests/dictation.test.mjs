// Tests of the voice typing (js/inbox-dictation.js) in a DOM made by jsdom, with a fake speech recognition that
// « says » sentences. Run: cd dev/editor-tests && npm test.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { JSDOM } from 'jsdom';

const code = readFileSync(new URL('../../js/inbox-dictation.js', import.meta.url), 'utf8');
const UA = {
	chrome: 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36',
	edge: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36 Edg/130.0.0.0',
	safari: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_5) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Safari/605.1.15',
	opera: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36 OPR/115.0.0.0',
	firefox: 'Mozilla/5.0 (X11; Linux x86_64; rv:132.0) Gecko/20100101 Firefox/132.0',
};

/**
 * A page with the module, a writing and a fake recognition
 * @param {object} o ua, speech (false: none), brave, consents, accounts, post (function)
 */
function page(o = {}) {
	const dom = new JSDOM('<!DOCTYPE html><body><div id="dlg"><button data-cmd="dictate" aria-pressed="false"></button><div class="ibx-js-dictbar" hidden></div>'
		+ '<textarea class="ibx-js-aiprompt" aria-label="Consigne"></textarea><div class="ibx-js-editor" tabindex="0"></div></div></body>',
		{ runScripts: 'outside-only' });
	const w = dom.window;
	// The identity of the browser as the page reads it
	Object.defineProperty(w.navigator, 'userAgent', { get: () => o.ua || UA.chrome });
	const log = { toasts: [], dialogs: [], posts: [], recs: [] };
	if (o.speech !== false) {
		w.webkitSpeechRecognition = class {
			constructor() {
				this.starts = 0;
				log.recs.push(this);
			}
			start() {
				if (o.startFails) {
					throw new Error('busy');
				}
				this.starts++;
			}
			stop() {
				if (this.onend) {
					this.onend();
				}
			}
		};
	}
	if (o.brave) {
		w.navigator.brave = {};
	}
	const esc = s => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
	const t = (k, ...a) => k + (a.length ? '(' + a.join('|') + ')' : '');
	w.Dolibarr = { tools: { inbox: {
		esc, t, th: (k, ...a) => esc(t(k, ...a)), ic: n => '<i data-icon="' + n + '"></i>',
		ctx: (k, d) => (k === 'MAIN_LANG_DEFAULT' ? 'fr_FR' : d),
		toast: (m, opt) => log.toasts.push({ m, error: !!(opt && opt.error) }),
		dialog: d => log.dialogs.push(d),
		post: async (ep, act, data) => {
			log.posts.push({ ep, act, data });
			return o.post ? o.post(ep, act, data) : { consents: { [data.provider]: 1 } };
		},
		$: (sel, root) => (root || w.document).querySelector(sel),
		$$: (sel, root) => Array.from((root || w.document).querySelectorAll(sel)),
	} } };
	// Recording for the transcription server: a fake microphone whose level the test sets, a fake recorder, a clock
	// and a 100 ms tick run by the test
	const sound = { level: 0, now: 1000, tick: null, tracks: 0, uploads: [] };
	if (o.record !== false) {
		w.navigator.mediaDevices = { getUserMedia: async () => {
			if (o.micRefused) {
				throw Object.assign(new Error('no'), { name: 'NotAllowedError' });
			}
			return { getTracks: () => [{ stop: () => sound.tracks++ }] };
		} };
		w.MediaRecorder = class {
			static isTypeSupported(m) {
				return m.startsWith('audio/webm');
			}
			constructor() {
				this.state = 'inactive';
				this.mimeType = 'audio/webm;codecs=opus';
			}
			start() {
				this.state = 'recording';
			}
			stop() {
				this.state = 'inactive';
				this.ondataavailable({ data: new w.Blob(['voice'], { type: this.mimeType }) });
				this.onstop();
			}
		};
		w.AudioContext = class {
			createAnalyser() {
				return { fftSize: 0, getFloatTimeDomainData: buf => buf.fill(sound.level) };
			}
			createMediaStreamSource() {
				return { connect() {} };
			}
			close() {
				return Promise.resolve();
			}
		};
	}
	w.Date.now = () => sound.now;
	w.setInterval = fn => {
		sound.tick = fn;
		return 1;
	};
	w.clearInterval = () => {
		sound.tick = null;
	};
	w.Dolibarr.tools.inbox.upload = (ep, act, fields, file) => {
		const call = { ep, act, fields, file };
		sound.uploads.push(call);
		return new Promise((resolve, reject) => {
			call.resolve = resolve;
			call.reject = reject;
		});
	};
	w.eval(code);
	const writing = {
		dlg: w.document.getElementById('dlg'),
		data: { dictation: Object.assign({ accounts: o.accounts || [5], consents: o.consents || {} }, o.server ? { engine: 'server', host: o.server.host, local: o.server.local } : {}) },
		html: '', changes: 0, closed: false,
		// The editor gets plain text (insertText): what is said is never read as HTML
		editor: { insertText(x, spaced) {
			writing.html += x;
			writing.spaced = spaced;
		}, insertHtml() {
			throw new Error('dictated text must not go in as HTML');
		}, focus() {} },
		changed() {
			writing.changes++;
		},
	};
	return { w, sound, D: w.Dolibarr.tools.inbox.dictation, writing, log, bar: () => writing.dlg.querySelector('.ibx-js-dictbar'), button: () => writing.dlg.querySelector('[data-cmd="dictate"]') };
}

/** The recognition « says » a sentence */
function say(rec, text, final) {
	rec.onresult({ resultIndex: 0, results: [Object.assign([{ transcript: text }], { isFinal: final })] });
}

test('the receiver of the voice is told from the browser; none without recognition, nor in Brave', () => {
	assert.equal(page({ ua: UA.chrome }).D.speech().provider, 'google');
	assert.equal(page({ ua: UA.edge }).D.speech().provider, 'microsoft');
	assert.equal(page({ ua: UA.safari }).D.speech().provider, 'apple');
	assert.equal(page({ ua: UA.opera }).D.speech().provider, 'browser', 'another browser: « the maker of your browser »');
	assert.equal(page({ ua: UA.firefox, speech: false }).D.speech(), null);
	assert.equal(page({ brave: true }).D.speech(), null);
});

test('the button only for a mailbox allowed, in a browser that can', () => {
	const p = page({ accounts: [5] });
	p.D.paintButton(p.writing, 5);
	assert.equal(p.button().hidden, false);
	p.D.paintButton(p.writing, 9);
	assert.equal(p.button().hidden, true, 'mailbox excluded or not allowed');
	const f = page({ speech: false });
	f.D.paintButton(f.writing, 5);
	assert.equal(f.button().hidden, true, 'no recognition in this browser');
});

test('first use: the receiver named, nothing heard before the consent, consent sent with the mailbox', async () => {
	const p = page({ ua: UA.edge });
	p.D.toggle(p.writing, 5);
	assert.equal(p.log.dialogs.length, 1);
	assert.match(p.log.dialogs[0].body, /InboxDictateConsentText\(InboxDictateProvider_microsoft\)/);
	assert.equal(p.log.recs.length, 0, 'no recognition before the consent');
	await p.log.dialogs[0].actions[0].run();
	assert.deepEqual(JSON.parse(JSON.stringify(p.log.posts[0])), { ep: 'dictation', act: 'consent', data: { provider: 'microsoft', account: 5 } });
	assert.equal(p.log.recs.length, 1);
	assert.equal(p.log.recs[0].starts, 1);
	assert.equal(p.log.recs[0].lang, 'fr-FR');
	// Already agreed: no question the next time
	p.D.stop(p.writing);
	p.D.toggle(p.writing, 5);
	assert.equal(p.log.dialogs.length, 1);
	assert.equal(p.log.recs.length, 2);
});

test('consent refused by the server: said, nothing started, the dialog stays', async () => {
	const p = page({ post: () => {
		throw new Error('La dictée n\'est pas permise pour cette boîte');
	} });
	p.D.toggle(p.writing, 5);
	const kept = await p.log.dialogs[0].actions[0].run();
	assert.equal(kept, false);
	assert.equal(p.log.recs.length, 0);
	assert.equal(p.log.toasts[0].error, true);
});

test('dictation: the bar names the receiver, the words come at the cursor as text, the draft is saved', () => {
	const p = page({ consents: { google: 1 } });
	p.D.toggle(p.writing, 5);
	assert.equal(p.bar().hidden, false);
	assert.match(p.bar().textContent, /InboxDictateOn/);
	assert.match(p.bar().textContent, /InboxDictateProvider_google/);
	assert.equal(p.button().getAttribute('aria-pressed'), 'true');
	const rec = p.log.recs[0];
	say(rec, 'bonjour mon', false);
	assert.equal(p.bar().querySelector('.ibx-js-dictinterim').textContent, 'bonjour mon', 'what is being heard, shown apart');
	assert.equal(p.writing.html, '');
	say(rec, ' C\'est <b>Jean</b> ', true);
	assert.equal(p.writing.html, 'C\'est <b>Jean</b> ', 'as said: apostrophe kept, the tag stays text (inserted as text)');
	assert.equal(p.writing.spaced, true, 'spaced from the word before');
	assert.equal(p.writing.changes, 1);
	say(rec, '   ', true);
	assert.equal(p.writing.changes, 1, 'nothing for a silence');
});

test('the browser stops after a silence: started again; the user stops: the bar goes', () => {
	const p = page({ consents: { google: 1 } });
	p.D.toggle(p.writing, 5);
	const rec = p.log.recs[0];
	rec.onend();
	assert.equal(rec.starts, 2, 'started again');
	assert.equal(p.writing.rec, rec);
	p.D.toggle(p.writing, 5);
	assert.equal(p.writing.rec, null);
	assert.equal(p.bar().hidden, true);
	assert.equal(p.button().getAttribute('aria-pressed'), 'false');
});

test('microphone refused, writing closed, start impossible: stopped and said', () => {
	const p = page({ consents: { google: 1 } });
	p.D.toggle(p.writing, 5);
	const rec = p.log.recs[0];
	rec.onerror({ error: 'not-allowed' });
	assert.equal(p.log.toasts[0].m, 'InboxDictateNoMic');
	rec.onend();
	assert.equal(rec.starts, 1, 'not started again after a refusal');
	assert.equal(p.writing.rec, null);

	const c = page({ consents: { google: 1 } });
	c.D.toggle(c.writing, 5);
	c.writing.closed = true;
	c.log.recs[0].onend();
	assert.equal(c.log.recs[0].starts, 1, 'a closed writing does not listen any more');

	const s = page({ consents: { google: 1 }, startFails: true });
	s.D.toggle(s.writing, 5);
	assert.equal(s.writing.rec, null);
	assert.equal(s.bar().hidden, true);
	assert.equal(s.log.toasts[0].m, 'InboxDictateNoMic');

	const n = page({ consents: { google: 1 } });
	n.D.toggle(n.writing, 5);
	n.log.recs[0].onerror({ error: 'no-speech' });
	assert.equal(n.log.toasts.length, 0, 'a silence is not an error');
});

test('a mailbox no longer allowed while dictating: stopped', () => {
	const p = page({ consents: { google: 1 }, accounts: [5] });
	p.D.toggle(p.writing, 5);
	p.D.paintButton(p.writing, 9);
	assert.equal(p.writing.rec, null);
	assert.equal(p.button().hidden, true);
});

test('the words go where the cursor is: the instruction of the AI, then back to the mail', () => {
	const p = page({ consents: { google: 1 } });
	p.D.paintButton(p.writing, 5);
	const prompt = p.writing.dlg.querySelector('.ibx-js-aiprompt');
	prompt.value = 'Réponds';
	prompt.focus();
	prompt.setSelectionRange(7, 7);
	// The click on the microphone keeps the cursor in the field
	const down = new p.w.MouseEvent('mousedown', { bubbles: true, cancelable: true });
	p.button().dispatchEvent(down);
	assert.equal(down.defaultPrevented, true);
	p.D.toggle(p.writing, 5);
	assert.match(p.bar().textContent, /InboxDictateIntoField\(Consigne\)/, 'the bar says where the words go');
	let typed = 0;
	prompt.addEventListener('input', () => typed++);
	say(p.log.recs[0], "que c'est d'accord", true);
	assert.equal(prompt.value, "Réponds que c'est d'accord ", 'in the instruction, spaced after the word, apostrophes kept');
	assert.equal(typed, 1, 'the field is told');
	assert.equal(p.writing.html, '', 'nothing in the mail');
	assert.equal(p.writing.changes, 0);
	// The cursor back in the text of the mail: the next words go there
	p.writing.dlg.querySelector('.ibx-js-editor').focus();
	say(p.log.recs[0], 'Bonjour', true);
	assert.equal(p.writing.html, 'Bonjour ');
	assert.equal(prompt.value, "Réponds que c'est d'accord ");
});


/** The voice for some ticks of 100 ms at a level (0.2: speech, 0: silence) */
function speak(p, level, ms) {
	p.sound.level = level;
	for (let i = 0; i < ms / 100; i++) {
		p.sound.now += 100;
		p.sound.tick();
	}
}
const settle = () => new Promise(r => setTimeout(r, 0));

test('server: shown in a browser without recognition (Firefox) when it can record; hidden when it cannot', () => {
	const p = page({ speech: false, ua: UA.firefox, server: { local: true, host: 'whisper.lan' } });
	p.D.paintButton(p.writing, 5);
	assert.equal(p.button().hidden, false);
	const q = page({ speech: false, record: false, server: { local: true, host: 'whisper.lan' } });
	q.D.paintButton(q.writing, 5);
	assert.equal(q.button().hidden, true);
});

test('server on the private network: agreed to all the same (biometric data), told it stays inside', async () => {
	const p = page({ server: { local: true, host: '127.0.0.1' } });
	p.D.toggle(p.writing, 5);
	assert.equal(p.log.dialogs.length, 1, 'the voice is a biometric data: asked even inside the network');
	assert.match(p.log.dialogs[0].body, /InboxDictateConsentServerLocalText\(127\.0\.0\.1\)/);
	assert.equal(p.sound.tick, null, 'nothing recorded before the consent');
	await p.log.dialogs[0].actions[0].run();
	assert.equal(p.log.posts[0].data.provider, 'server');
	await settle();
	assert.equal(typeof p.sound.tick, 'function');
});

test('server: a pause after speech sends the piece, the text comes as text', async () => {
	const p = page({ consents: { server: 1 }, server: { local: true, host: '127.0.0.1' } });
	p.D.toggle(p.writing, 5);
	await settle();
	assert.equal(p.log.dialogs.length, 0, 'agreed before');
	assert.match(p.bar().textContent, /InboxDictateProvider_serverlocal\(127\.0\.0\.1\)/);
	speak(p, 0, 2000);
	assert.equal(p.sound.uploads.length, 0, 'silence alone: nothing sent');
	speak(p, 0.2, 1500);
	speak(p, 0, 500);
	assert.equal(p.sound.uploads.length, 0, 'a short breath does not cut');
	speak(p, 0, 400);
	assert.equal(p.sound.uploads.length, 1, 'the pause cuts the sentence');
	const up = p.sound.uploads[0];
	assert.equal(up.ep + '/' + up.act, 'dictation/transcribe');
	assert.equal(up.fields.account, 5);
	assert.equal(up.file.type, 'audio/webm');
	assert.match(p.bar().textContent, /InboxDictateWriting/);
	up.resolve({ text: ' Bonjour <b>Jean</b>. ' });
	await settle();
	await settle();
	assert.equal(p.writing.html, 'Bonjour <b>Jean</b>. ', 'as text, never HTML');
	assert.doesNotMatch(p.bar().textContent, /InboxDictateWriting/);
});

test('server: the texts come back in the order they were said, a long sentence cut at 20 s', async () => {
	const p = page({ consents: { server: 1 }, server: { local: true, host: 'whisper.lan' } });
	p.D.toggle(p.writing, 5);
	await settle();
	speak(p, 0.2, 21000);
	assert.equal(p.sound.uploads.length, 1, 'cut at 20 s even without a pause');
	speak(p, 0.2, 1000);
	speak(p, 0, 900);
	assert.equal(p.sound.uploads.length, 2);
	p.sound.uploads[1].resolve({ text: 'deux' });
	await settle();
	assert.equal(p.writing.html, '', 'the second waits for the first');
	p.sound.uploads[0].resolve({ text: 'un' });
	await settle();
	await settle();
	await settle();
	assert.equal(p.writing.html, 'un deux ');
});

test('server outside the network: named before the first dictation, the consent kept for « server »', async () => {
	const p = page({ server: { local: false, host: 'api.openai.com' } });
	p.D.toggle(p.writing, 5);
	assert.equal(p.log.dialogs.length, 1);
	assert.match(p.log.dialogs[0].body, /InboxDictateConsentServerText\(InboxDictateProvider_server\(api\.openai\.com\)\)/);
	assert.equal(p.sound.tick, null, 'nothing recorded before the consent');
	await p.log.dialogs[0].actions[0].run();
	assert.equal(p.log.posts[0].data.provider + '/' + p.log.posts[0].data.account, 'server/5');
	await settle();
	assert.equal(typeof p.sound.tick, 'function', 'recording once agreed');
});

test('server: stop lets the microphone go, the last sentence still sent; an error said once', async () => {
	const p = page({ consents: { server: 1 }, server: { local: true, host: 'whisper.lan' } });
	p.D.toggle(p.writing, 5);
	await settle();
	speak(p, 0.2, 800);
	p.D.toggle(p.writing, 5);
	assert.equal(p.sound.tracks, 1, 'microphone released');
	assert.equal(p.writing.rec, null);
	assert.equal(p.button().getAttribute('aria-pressed'), 'false');
	assert.equal(p.sound.uploads.length, 1, 'what was said before the stop is sent');
	assert.equal(p.bar().hidden, false, 'the bar waits for the text');
	p.sound.uploads[0].reject(Object.assign(new Error('down'), { status: 502 }));
	await settle();
	await settle();
	await settle();
	assert.deepEqual(p.log.toasts, [{ m: 'down', error: true }]);
	assert.equal(p.bar().hidden, true);
});

test('server: microphone refused, said and nothing kept running', async () => {
	const p = page({ micRefused: true, consents: { server: 1 }, server: { local: true, host: 'whisper.lan' } });
	p.D.toggle(p.writing, 5);
	await settle();
	assert.equal(p.writing.rec, null);
	assert.deepEqual(p.log.toasts, [{ m: 'InboxDictateNoMic', error: true }]);
});
