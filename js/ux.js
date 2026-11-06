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
 * Catalogue of the components (ux.php): code shown under each example, colour swatches, sheet of the icons, themes.
 */
(function () {
	// Code blocks generated from the live examples, so the code shown is always the code rendered
	document.querySelectorAll('.ibx-doc-example').forEach(ex => {
		const pre = document.createElement('pre');
		pre.className = 'ibx-doc-code';
		pre.textContent = ex.innerHTML.replace(/^\s*\n/gm, '').replace(/\t/g, '  ').trim();
		ex.after(pre);
	});
	// Colour swatches read from the tokens
	const tokens = ['accent', 'accent-hover', 'accent-soft', 'on-accent', 'bg', 'bg-pane', 'bg-sunken', 'bg-hover', 'border', 'border-strong', 'text', 'text-muted', 'text-faint', 'danger', 'danger-soft', 'warning', 'warning-soft', 'success', 'success-soft', 'tag-1', 'tag-2', 'tag-3', 'tag-4', 'tag-5'];
	const sw = document.getElementById('swatches');
	sw.innerHTML = tokens.map(t => '<div class="ibx-doc-swatch"><span style="background:var(--ibx-' + t + ')"></span><code>--ibx-' + t + '</code></div>').join('');
	// Icon sheet read from icons.json (usage name + Tabler name)
	Promise.resolve(JSON.parse(document.getElementById('ibx-doc-icons').textContent)).then(conf => {
		document.getElementById('iconsheet').innerHTML = Object.entries(conf.icons).map(([usage, [name, style]]) =>
			'<div class="ibx-doc-iconcell" title="Tabler : ' + name + (style === 'filled' ? ' (plein)' : '') + '"><svg class="ibx-icon" aria-hidden="true"><use href="' + document.body.dataset.icons + '#ibx-i-' + usage + '"/></svg><code>' + usage + '</code><span>' + name + '</span></div>').join('');
	});
	const dlgBtn = document.querySelector('.ibx-js-doc-dialog');
	if (dlgBtn) {
		dlgBtn.addEventListener('click', () => {
			const d = document.createElement('dialog');
			d.className = 'ibx-dialog';
			d.innerHTML = '<div class="ibx-dialog__head"><h2 class="ibx-dialog__title">Supprimer devis@example.com ?</h2></div><div class="ibx-dialog__body"><p>La boîte est retirée d\'inbox. Les mails restent sur le serveur.</p><label class="ibx-formrow__label" for="d-type">Tapez devis@example.com pour confirmer</label><input class="ibx-field" id="d-type"></div><div class="ibx-dialog__foot"><button class="ibx-btn" value="cancel">Annuler</button><span class="ibx-formfoot__spacer"></span><button class="ibx-btn ibx-btn--danger">Supprimer la boîte</button></div>';
			document.body.appendChild(d);
			d.addEventListener('click', e => { if (e.target.closest('button')) d.close(); });
			d.addEventListener('close', () => d.remove());
			d.showModal();
		});
	}
	document.querySelectorAll('[data-theme]').forEach(b => b.addEventListener('click', () => {
		if (b.dataset.theme === 'auto') delete document.documentElement.dataset.ibxTheme; else document.documentElement.dataset.ibxTheme = b.dataset.theme;
		document.querySelectorAll('[data-theme]').forEach(x => x.setAttribute('aria-pressed', String(x === b)));
	}));
})();
