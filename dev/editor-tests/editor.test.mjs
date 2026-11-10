// Tests of the editor of the writing: js/inbox-editor.js with the frozen bundle js/vendor/tiptap, in a DOM made by
// jsdom. Run: cd dev/editor-tests && npm ci && npm test (never shipped, never served).
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { JSDOM } from 'jsdom';

const root = new URL('../../', import.meta.url);
const bundle = readFileSync(new URL('js/vendor/tiptap/tiptap.min.js', root), 'utf8');
const adapter = readFileSync(new URL('js/inbox-editor.js', root), 'utf8');
// A 1 x 1 PNG, as a pasted picture becomes
const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

/** A page with the editor loaded, as inbox gives it (Dolibarr.tools.inbox and the bundle) */
async function editorWith(html) {
	const dom = new JSDOM('<!DOCTYPE html><body><div id="ed"></div></body>', { runScripts: 'outside-only', pretendToBeVisual: true });
	const w = dom.window;
	// No layout in jsdom: what ProseMirror measures is given flat
	w.document.elementFromPoint = () => null;
	w.Range.prototype.getClientRects = () => [];
	w.Range.prototype.getBoundingClientRect = () => ({ left: 0, right: 0, top: 0, bottom: 0, width: 0, height: 0 });
	// A picture « loaded » at 400 x 200 (jsdom loads nothing)
	Object.defineProperty(w.HTMLImageElement.prototype, 'naturalWidth', { get: () => 400 });
	Object.defineProperty(w.HTMLImageElement.prototype, 'naturalHeight', { get: () => 200 });
	Object.defineProperty(w.HTMLImageElement.prototype, 'complete', { get: () => true });
	w.Dolibarr = { tools: { inbox: { ctx: (k, d) => d } }, executeHook() {} };
	w.eval(bundle + '\nwindow.InboxTiptap = InboxTiptap;');
	w.eval(adapter);
	const ed = await w.Dolibarr.tools.inbox.editors.create(w.document.getElementById('ed'), { html, label: 'Texte' });
	return { w, ed };
}

/** Select the n-th picture of the text */
function selectImage(ed, n = 0) {
	const t = ed.raw();
	let at = -1;
	let i = 0;
	t.state.doc.descendants((node, pos) => {
		if (node.type.name === 'image' && i++ === n) {
			at = pos;
		}
	});
	assert.ok(at >= 0, 'picture found');
	t.commands.setNodeSelection(at);
}

test('a pasted picture keeps its data: source and its size through the editor', async () => {
	const { ed } = await editorWith('<p>Bonjour</p><p><img src="' + PNG + '" width="120" height="60" alt="Logo"></p>');
	const html = ed.getHtml();
	assert.match(html, /src="data:image\/png;base64,/);
	assert.match(html, /width="120"/);
	assert.match(html, /height="60"/);
	assert.match(html, /alt="Logo"/);
});

test('the picture can be resized by its corners (node view with four handles)', async () => {
	const { w } = await editorWith('<p><img src="' + PNG + '"></p>');
	const box = w.document.querySelector('[data-resize-container]');
	assert.ok(box, 'resizable view');
	const handles = Array.from(box.querySelectorAll('[data-resize-handle]')).map(h => h.dataset.resizeHandle).sort();
	assert.deepEqual(handles, ['bottom-left', 'bottom-right', 'top-left', 'top-right']);
});

test('the bar of the picture: a size from its own one, proportions kept, then its own size again', async () => {
	const { ed } = await editorWith('<p><img src="' + PNG + '"></p>');
	selectImage(ed);
	const info = ed.imageInfo();
	assert.equal(info.naturalWidth, 400);
	ed.imageProps({ width: 200 });
	assert.match(ed.getHtml(), /width="200"/);
	assert.match(ed.getHtml(), /height="100"/, 'height from the proportions');
	selectImage(ed);
	ed.imageProps({ width: 0 });
	assert.doesNotMatch(ed.getHtml(), /width=/, 'own size: no size written');
});

test('alternative text and removal of the picture', async () => {
	const { ed } = await editorWith('<p>Avant <img src="' + PNG + '"> après</p>');
	selectImage(ed);
	ed.imageProps({ alt: '  Plan du chantier  ' });
	assert.match(ed.getHtml(), /alt="Plan du chantier"/);
	selectImage(ed);
	ed.exec('imageDelete');
	assert.doesNotMatch(ed.getHtml(), /<img/);
	assert.match(ed.getHtml(), /Avant/);
});

test('no picture selected: the bar has nothing to show', async () => {
	const { ed } = await editorWith('<p>Texte seul</p>');
	assert.equal(ed.imageInfo(), null);
	ed.imageProps({ width: 100 });
	assert.match(ed.getHtml(), /Texte seul/);
});

test('variables of a template: chips that follow their value', async () => {
	const { ed } = await editorWith('<p>Bonjour </p>');
	ed.focus('end');
	ed.insertVariable('CONTACT_FIRSTNAME', '');
	assert.deepEqual(Array.from(ed.variables(), v => String(v.key)), ['CONTACT_FIRSTNAME']);
	ed.setVariableValues({ CONTACT_FIRSTNAME: 'Jean' });
	assert.match(ed.getHtml(), /data-inbox-var="CONTACT_FIRSTNAME"[^>]*>Jean</);
	ed.setVariableValues({ CONTACT_FIRSTNAME: 'Marie' });
	assert.match(ed.getHtml(), />Marie</);
});

test('a table is written with inline styles (the only thing the mail software read)', async () => {
	const { ed } = await editorWith('<p>x</p>');
	ed.focus('end');
	ed.exec('tableInsert');
	assert.ok(ed.isActive('table'));
	ed.tableStyle('grid');
	const html = ed.getHtml();
	assert.match(html, /<table/);
	assert.match(html, /<td[^>]*style="[^"]*border/);
});

test('formatting commands and their state', async () => {
	const { ed } = await editorWith('<p>mot</p>');
	ed.raw().commands.selectAll();
	ed.exec('bold');
	assert.ok(ed.isActive('bold'));
	assert.match(ed.getHtml(), /<strong>mot<\/strong>/);
	ed.exec('align', 'center');
	assert.match(ed.getHtml(), /text-align: ?center/);
});

test('AI of the writing: the selection read, then a range or the whole text replaced (in the history)', async () => {
	const { ed } = await editorWith('<p>Bonjour Jean, merci pour votre retour.</p>');
	const t = ed.raw();
	t.commands.setTextSelection({ from: 9, to: 13 });
	const r = ed.selectionRange();
	assert.equal(r.text, 'Jean');
	ed.replaceRange(r.from, r.to, 'Marie');
	assert.match(ed.getHtml(), /Bonjour Marie, merci/);
	t.commands.undo();
	assert.match(ed.getHtml(), /Bonjour Jean, merci/, 'Ctrl+Z takes it back');
	ed.replaceRange(null, null, '<p>Tout nouveau texte</p>');
	assert.equal(ed.getText().trim(), 'Tout nouveau texte');
	ed.replaceRange(0, 99999, '<p>Bornes hors du texte</p>');
	assert.match(ed.getHtml(), /Bornes hors du texte/, 'positions bounded to the text');
});

test('voice typing: text inserted as text (apostrophe kept, a tag stays text)', async () => {
	const { ed } = await editorWith('<p>Début</p>');
	ed.focus('end');
	ed.insertText("c'est <b>vrai</b> & simple ", true);
	assert.match(ed.getText(), /Début c'est <b>vrai<\/b> & simple /, 'a space after the word before');
	ed.insertText('encore', true);
	assert.match(ed.getText(), /simple encore$/, 'no double space after a space');
	assert.doesNotMatch(ed.getHtml(), /<strong>|<b>vrai/, 'no formatting from what was said');
	assert.doesNotMatch(ed.getText(), /&#39;/);
});

test('HTML with line breaks between blocks (an answer of the AI): no empty paragraph, no empty bullet', async () => {
	const { ed } = await editorWith('<p></p>');
	ed.replaceRange(null, null, '<p>Bonjour,</p>\n\n<p>Je vous <strong>informe</strong> que :</p>\n<ul>\n  <li><p>un ;</p></li>\n  <li>deux.</li>\n</ul>\n\n<p>Cordialement,</p>\n');
	const html = ed.getHtml();
	assert.doesNotMatch(html, /<p><\/p>/, 'no empty paragraph');
	assert.doesNotMatch(html, /<li><p><\/p><\/li>/, 'no empty bullet');
	assert.equal((html.match(/<li>/g) || []).length, 2);
	assert.match(html, /Je vous <strong>informe<\/strong> que/, 'the spaces around an inline element kept');
	ed.focus('end');
	ed.insertHtml('\n<p>Encore</p>\n\n');
	assert.doesNotMatch(ed.getHtml(), /<p><\/p>/);
});


// ---------- Inline styles kept (1.51.0): signatures, templates, texts pasted from another software ----------
/** Declarations of a style attribute, normalised (property: value), whatever the serialisation of the DOM */
const styles = html => (html.match(/style="[^"]*"/g) || []).join(';').toLowerCase().replace(/\s+/g, '');

test('inline styles that no button makes are kept: size, font, line height, colour and decoration of a link', async () => {
	const { ed } = await editorWith('<div><span style="font-family:arial,helvetica,sans-serif"><span style="color:#d31b11"><span style="font-size:18px"><strong>Prénom NOM</strong></span></span></span><br>'
		+ '<a href="https://example.com/" style="color:#666;text-decoration:none;line-height:15px">example.com</a></div>');
	const s = styles(ed.getHtml());
	assert.match(s, /font-size:18px/);
	assert.match(s, /font-family:arial,helvetica,sans-serif/, 'the style of the spans around taken in');
	assert.match(s, /text-decoration:none/);
	assert.match(s, /line-height:15px/);
	assert.match(ed.getHtml(), /<strong>Prénom NOM<\/strong>/);
});

test('text outside any block goes in a <div> (no margin of <p>), an empty line « <br>&nbsp; » kept', async () => {
	const { ed } = await editorWith('<br />\nCordialement.<br />\n&nbsp;\n<table><tr><td>cellule</td></tr></table>\nAprès');
	const html = ed.getHtml();
	assert.match(html, /^<div><br>Cordialement\.<br>&nbsp;<\/div>/);
	assert.match(html, /<td[^>]*><div>cellule<\/div><\/td>/);
	assert.match(html, /<div>Après<\/div>$/);
	assert.equal(ed.getHtml(), (await editorWith(html)).ed.getHtml(), 'the same once more through the editor');
});

test('a paragraph typed stays a <p>, a <div> read stays a <div>', async () => {
	const { ed } = await editorWith('<p>Un</p><div style="text-align:left">Deux</div>');
	assert.match(ed.getHtml(), /^<p>Un<\/p><div style="text-align: ?left;?">Deux<\/div>$/);
});

test('old tables of mails: border, cellpadding, cellspacing, valign and a width in px of a cell kept', async () => {
	const { ed } = await editorWith('<table border="0" cellpadding="0" cellspacing="0" style="width:600px"><tr><td style="width:280px" valign="top">A</td><td>B</td></tr></table>');
	const html = ed.getHtml();
	assert.match(html, /border="0"/);
	assert.match(html, /cellpadding="0"/);
	assert.match(html, /cellspacing="0"/);
	assert.match(html, /valign="top"/);
	assert.match(styles(html), /width:600px/);
	assert.match(html, /<td[^>]*width="280"/, 'width of the column written for the mail software');
});

test('a div holding blocks gives its font to them (a text pasted from Outlook or Word)', async () => {
	const { ed } = await editorWith('<div style="font-family:Calibri,sans-serif;font-size:11pt"><p style="margin:0cm"><span style="color:#1F497D">Cordialement,</span></p><p style="margin:0cm;font-size:9pt">Nom</p></div>');
	const html = ed.getHtml();
	assert.equal((styles(html).match(/font-family:calibri,sans-serif/g) || []).length, 2, 'on both paragraphs');
	assert.match(styles(html), /font-size:9pt/, 'the own style of a block wins');
});

test('bold, italic and underline written as styles become marks, never doubled', async () => {
	const { ed } = await editorWith('<p><a href="https://example.com/" style="font-weight:bold;color:#d31b11">lien</a> <span style="font-style:italic;font-size:12px">it</span></p>');
	const html = ed.getHtml();
	assert.match(html, /<strong>lien<\/strong>/);
	assert.match(html, /<em>it<\/em>/);
	assert.doesNotMatch(styles(html), /font-weight|font-style/, 'the button can take them off');
	assert.match(styles(html), /font-size:12px/);
});

test('the colour button changes a colour read from a style (owned by the editor, not doubled)', async () => {
	const { ed } = await editorWith('<p><span style="color:#ff0000;font-size:14px">texte</span></p>');
	ed.raw().commands.selectAll();
	ed.exec('color', '#00ff00');
	const s = styles(ed.getHtml());
	assert.doesNotMatch(s, /#ff0000|rgb\(255,0,0\)/);
	assert.match(s, /font-size:14px/);
});
