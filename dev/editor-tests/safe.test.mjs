// Tests of the one door of the HTML of a mail in the page (js/inbox-safe.js): DOMPurify then the Sanitizer
// API of the browser, in a DOM made by jsdom. Run: cd dev/editor-tests && npm test.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { JSDOM } from 'jsdom';

const root = new URL('../../', import.meta.url);
const purify = readFileSync(new URL('js/vendor/dompurify/purify.min.js', root), 'utf8');
const safe = readFileSync(new URL('js/inbox-safe.js', root), 'utf8');

/** Same attacks as test/phpunit/InboxSafeTest.php */
const ATTACKS = {
	'script': '<p>a<script>alert(1)</script></p>',
	'img onerror': '<img src=x onerror=alert(1)>',
	'svg onload': '<svg onload=alert(1)><circle r=1></circle></svg>',
	'math href': '<math><mi xlink:href="javascript:alert(1)">x</mi></math>',
	'a javascript': '<a href="javascript:alert(1)">x</a>',
	'a javascript entities': '<a href="&#106;&#97;&#118;&#97;&#115;&#99;&#114;&#105;&#112;&#116;&#58;alert(1)">x</a>',
	'a javascript tab': '<a href="jav&#x09;ascript:alert(1)">x</a>',
	'a data html': '<a href="data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==">x</a>',
	'iframe srcdoc': '<iframe srcdoc="<script>alert(1)</script>"></iframe>',
	'form': '<form action="https://x.y"><input name=a><button formaction="javascript:alert(1)">b</button></form>',
	'style block': '<style>@import "https://x.y/a.css";</style><p>x</p>',
	'style expression': '<p style="width:expression(alert(1))">x</p>',
	'noscript mxss': '<noscript><p title="</noscript><img src=x onerror=alert(1)>">',
	'template': '<template><img src=x onerror=alert(1)></template>',
	'comment': '<!--><img src=x onerror=alert(1)>-->',
	'textarea mxss': '<textarea><img title="</textarea><img src=x onerror=alert(1)>"></textarea>',
	'details ontoggle': '<details open ontoggle=alert(1)>x</details>',
	'srcset': '<img srcset="javascript:alert(1)">',
	'class of the page': '<p class="butAction ibx-btn">x</p>',
	'data attribute': '<p data-cmd="delete">x</p>',
	'variable on a div': '<div data-inbox-var="X">x</div>',
};

/**
 * A page with DOMPurify and inbox-safe.js, as index.php loads them
 * @param {object} o noPurify (script not loaded), sanitizer (fake Sanitizer API: 'ok', 'throws')
 */
function page(o = {}) {
	const dom = new JSDOM('<!DOCTYPE html><body></body>', { runScripts: 'outside-only' });
	const w = dom.window;
	const esc = s => String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
	w.Dolibarr = { tools: { inbox: { esc } } };
	const log = { sanitizer: [] };
	if (o.sanitizer) {
		w.Sanitizer = class {
			constructor(config) {
				if (o.sanitizer === 'throws') {
					throw new w.TypeError('unknown member');
				}
				this.config = config;
				log.sanitizer.push(config);
			}
		};
		// The fake keeps what it gets, with a mark: the result of the second pass is the one given
		w.Element.prototype.setHTML = function (html) {
			this.innerHTML = html + '<i>api</i>';
		};
	}
	if (!o.noPurify) {
		w.eval(purify);
	}
	w.eval(safe);
	return { w, I: w.Dolibarr.tools.inbox, log };
}

/** Parse what came out and check it */
function assertSafe(w, html, name) {
	const box = w.document.createElement('div');
	box.innerHTML = html;
	for (const el of box.querySelectorAll('*')) {
		assert.ok(!['SCRIPT', 'IFRAME', 'SVG', 'MATH', 'FORM', 'INPUT', 'BUTTON', 'STYLE', 'TEMPLATE', 'TEXTAREA', 'NOSCRIPT', 'DETAILS'].includes(el.nodeName.toUpperCase()), name + ': ' + el.nodeName);
		for (const a of el.attributes) {
			assert.doesNotMatch(a.name, /^on|^srcset$|^formaction$|^id$/i, name + ': ' + a.name);
			assert.doesNotMatch(a.value, /^\s*(javascript|vbscript|data:(?!image\/))/i, name + ': ' + a.value);
			if (a.name === 'class') {
				assert.match(a.value, /^(inbox-quote|inbox-signature|inbox-signature-edited)( |$)/, name + ': class ' + a.value);
			}
			if (a.name.startsWith('data-')) {
				assert.ok(['data-inbox-var', 'data-colwidth'].includes(a.name), name + ': ' + a.name);
			}
			if (a.name === 'style') {
				assert.doesNotMatch(a.value, /expression\s*\(/i, name + ': style');
			}
		}
	}
}

test('every attack is stopped by DOMPurify', () => {
	const { w, I } = page();
	for (const [name, html] of Object.entries(ATTACKS)) {
		assertSafe(w, I.safeHtml(html), name);
	}
});

test('the formatting of a mail and the marks of the editor are kept', () => {
	const { I } = page();
	const out = I.safeHtml('<div class="inbox-quote MsoNormal"><p style="color:#333;font-family:Arial">Bonjour <b>Paul</b>,'
		+ ' <a href="https://ex.com/a?b=c">lien</a> <a href="mailto:a@b.c">mail</a></p>'
		+ '<table width="600" bgcolor="#eee"><tr><td data-colwidth="120" valign="top">c</td></tr></table>'
		+ '<p>Cher <span data-inbox-var="CLIENT_NAME">client</span></p><div class="inbox-signature-edited">Sig</div></div>');
	assert.match(out, /class="inbox-quote"/, 'mark of inbox kept, class of the mail removed');
	assert.match(out, /style="color:#333;font-family:Arial"/);
	assert.match(out, /<b>Paul<\/b>/);
	assert.match(out, /href="https:\/\/ex\.com\/a\?b=c"/);
	assert.match(out, /rel="noopener noreferrer"/);
	assert.match(out, /href="mailto:a@b\.c"/);
	assert.match(out, /<table width="600" bgcolor="#eee">/);
	assert.match(out, /data-colwidth="120"/);
	assert.match(out, /<span data-inbox-var="CLIENT_NAME">client<\/span>/);
	assert.match(out, /class="inbox-signature-edited"/);
});

test('pictures: https and real data: pictures kept, the others lose their source', () => {
	const { I } = page();
	const png = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';
	assert.match(I.safeHtml('<img src="' + png + '" alt="a">'), /src="data:image\/png/);
	assert.match(I.safeHtml('<img src="https://x.y/i.png">'), /src="https:\/\/x\.y\/i\.png"/);
	assert.doesNotMatch(I.safeHtml('<img src="http://x.y/i.png">'), /src=/, 'http: never in the editor');
	assert.doesNotMatch(I.safeHtml('<img src="cid:logo">'), /src=/);
	assert.doesNotMatch(I.safeHtml('<img src="data:image/svg+xml;base64,PHN2Zz4=">'), /src=/, 'svg picture: may hold a script');
});

test('the Sanitizer API, when the browser has one, gets the result of DOMPurify and gives the final one', () => {
	const { I, log } = page({ sanitizer: 'ok' });
	const out = I.safeHtml('<p onclick="x()">a<script>b</script></p>');
	assert.equal(out, '<p>a</p><i>api</i>', 'second pass after DOMPurify');
	assert.ok(log.sanitizer[0].elements.includes('p'));
	assert.ok(!log.sanitizer[0].elements.includes('script'));
	assert.equal(log.sanitizer[0].dataAttributes, false, 'required by Chromium with data-* attributes listed');
	assert.equal(I.safeHtmlEngines().sanitizerApi, true);
	assert.match(I.safeHtmlEngines().dompurify, /^3\./);
});

test('a Sanitizer API that refuses the configuration: the result of DOMPurify alone', () => {
	const { I } = page({ sanitizer: 'throws' });
	assert.equal(I.safeHtml('<p onclick="x()">a</p>'), '<p>a</p>');
	assert.equal(I.safeHtmlEngines().sanitizerApi, false);
});

test('no DOMPurify and no Sanitizer API: the text only, escaped (fails closed)', () => {
	const { I } = page({ noPurify: true });
	assert.equal(I.safeHtml('<p>a <b>b</b></p><img src=x onerror=alert(1)>&lt;i&gt;'), 'a b&lt;i&gt;');
	assert.equal(I.safeHtml(''), '');
});

test('profile « tooltip » (card of an object of Dolibarr): its classes kept, no script', () => {
	const { w, I } = page();
	const out = I.safeHtml('<div class="centpercent tdoverflowmax150"><u>Contact</u><br><b>Nom :</b> <img src=x onerror=alert(1)>'
		+ '<a href="javascript:alert(1)" class="classfortooltip">x</a><svg onload=alert(1)></svg><form><input></form></div>', 'tooltip');
	assert.match(out, /class="centpercent tdoverflowmax150"/);
	assert.match(out, /class="classfortooltip"/);
	assertSafe(w, out.replace(/ class="[^"]*"/g, ''), 'tooltip');
	// The next call of a mail is not in the profile « tooltip » any more
	assert.doesNotMatch(I.safeHtml('<p class="centpercent">x</p>'), /class=/);
});
