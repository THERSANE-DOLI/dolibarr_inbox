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
 * Colours of the tags and colour blindness: tool Dolibarr.tools.inboxColors.
 * - simulation of the three colour blindnesses (Machado, Oliveira, Fernandes 2009, severity 1, in linear RGB);
 * - difference of two colours as a person sees them (CIEDE2000);
 * - risks: pairs of active tags closer than THRESHOLD for one vision (warning of the tab Tags);
 * - adapt: colours for one viewer, only the tags that collide for his vision get another colour (the most distinct one
 *   of the palettes), the others keep theirs; nothing is written, display only;
 * - badge: HTML of a tag (colour, border, dark text on a light colour).
 * THRESHOLD 10: the pairs of the usual colours that a colour blind person mixes up (red / green 7, orange / green 9)
 * are under it, the palettes below stay over it (11 at least, except one pair of Tol bright for the tritanopia).
 */
(function () {
	'use strict';

	const THRESHOLD = 10;
	const MACHADO = {
		protanopia: [[0.152286, 1.052583, -0.204868], [0.114503, 0.786281, 0.099216], [-0.003882, -0.048116, 1.051998]],
		deuteranopia: [[0.367322, 0.860646, -0.227968], [0.280085, 0.672501, 0.047413], [-0.011820, 0.042940, 0.968881]],
		tritanopia: [[1.255528, -0.076749, -0.178779], [-0.078411, 0.930809, 0.147602], [0.004733, 0.691367, 0.303900]],
	};
	const VISIONS = ['normal', 'protanopia', 'deuteranopia', 'tritanopia'];
	// Option MAIN_OPTIMIZEFORCOLORBLIND of the card of the user => visions to check (flashy: no type given, all of them)
	const SETTING_VISIONS = {
		flashy: ['protanopia', 'deuteranopia', 'tritanopia'],
		protanopia: ['protanopia'],
		deuteranopes: ['deuteranopia'],
		tritanopes: ['tritanopia'],
	};
	// Palettes made for the colour blind people, in the order they are applied to the tags
	const PALETTES = [
		{ id: 'okabe', name: 'Okabe-Ito', colors: ['#D55E00', '#E69F00', '#009E73', '#0072B2', '#CC79A7', '#56B4E9', '#F0E442', '#000000'] },
		{ id: 'tolbright', name: 'Tol bright', colors: ['#EE6677', '#CCBB44', '#228833', '#4477AA', '#AA3377', '#66CCEE', '#BBBBBB'] },
		{ id: 'tolmuted', name: 'Tol muted', colors: ['#CC6677', '#DDCC77', '#117733', '#332288', '#AA4499', '#88CCEE', '#44AA99', '#999933', '#882255'] },
	];
	const POOL = [...new Set(PALETTES.flatMap(p => p.colors))];
	const BORDERS = ['none', 'solid', 'dashed', 'dotted', 'double'];

	const isColor = c => /^#[0-9a-f]{6}$/i.test(String(c || ''));
	const toLin = v => (v /= 255) <= 0.04045 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
	const hexLin = hex => [1, 3, 5].map(i => toLin(parseInt(hex.substr(i, 2), 16)));

	function simulate(lin, vision) {
		const m = MACHADO[vision];
		if (!m) {
			return lin;
		}
		return m.map(r => Math.min(1, Math.max(0, r[0] * lin[0] + r[1] * lin[1] + r[2] * lin[2])));
	}

	function lab(lin) {
		const f = t => t > 0.008856 ? Math.cbrt(t) : 7.787 * t + 16 / 116;
		const [r, g, b] = lin;
		const x = f((0.4124564 * r + 0.3575761 * g + 0.1804375 * b) / 0.95047);
		const y = f(0.2126729 * r + 0.7151522 * g + 0.0721750 * b);
		const z = f((0.0193339 * r + 0.1191920 * g + 0.9503041 * b) / 1.08883);
		return [116 * y - 16, 500 * (x - y), 200 * (y - z)];
	}

	/** CIEDE2000 difference of two Lab colours */
	function de2000(p, q) {
		const rad = d => d * Math.PI / 180;
		const deg = r => r * 180 / Math.PI;
		const p7 = v => Math.pow(v, 7);
		const [L1, a1, b1] = p;
		const [L2, a2, b2] = q;
		const Cb = (Math.hypot(a1, b1) + Math.hypot(a2, b2)) / 2;
		const G = 0.5 * (1 - Math.sqrt(p7(Cb) / (p7(Cb) + p7(25))));
		const a1p = (1 + G) * a1;
		const a2p = (1 + G) * a2;
		const C1p = Math.hypot(a1p, b1);
		const C2p = Math.hypot(a2p, b2);
		const hue = (b, a) => {
			const v = deg(Math.atan2(b, a));
			return v < 0 ? v + 360 : v;
		};
		const h1p = hue(b1, a1p);
		const h2p = hue(b2, a2p);
		let dhp = 0;
		if (C1p * C2p !== 0) {
			dhp = h2p - h1p;
			dhp += dhp > 180 ? -360 : (dhp < -180 ? 360 : 0);
		}
		const dLp = L2 - L1;
		const dCp = C2p - C1p;
		const dHp = 2 * Math.sqrt(C1p * C2p) * Math.sin(rad(dhp / 2));
		const Lbp = (L1 + L2) / 2;
		const Cbp = (C1p + C2p) / 2;
		let hbp = h1p + h2p;
		if (C1p * C2p !== 0) {
			hbp = Math.abs(h1p - h2p) <= 180 ? hbp / 2 : (hbp < 360 ? (hbp + 360) / 2 : (hbp - 360) / 2);
		}
		const T = 1 - 0.17 * Math.cos(rad(hbp - 30)) + 0.24 * Math.cos(rad(2 * hbp)) + 0.32 * Math.cos(rad(3 * hbp + 6)) - 0.20 * Math.cos(rad(4 * hbp - 63));
		const dTheta = 30 * Math.exp(-Math.pow((hbp - 275) / 25, 2));
		const Rc = 2 * Math.sqrt(p7(Cbp) / (p7(Cbp) + p7(25)));
		const Sl = 1 + 0.015 * Math.pow(Lbp - 50, 2) / Math.sqrt(20 + Math.pow(Lbp - 50, 2));
		const Sc = 1 + 0.045 * Cbp;
		const Sh = 1 + 0.015 * Cbp * T;
		const Rt = -Math.sin(rad(2 * dTheta)) * Rc;
		return Math.sqrt(Math.pow(dLp / Sl, 2) + Math.pow(dCp / Sc, 2) + Math.pow(dHp / Sh, 2) + Rt * (dCp / Sc) * (dHp / Sh));
	}

	/** Difference of two #RRGGBB colours for one vision (normal, protanopia, deuteranopia, tritanopia) */
	function distance(c1, c2, vision) {
		if (!isColor(c1) || !isColor(c2)) {
			return Infinity;
		}
		return de2000(lab(simulate(hexLin(c1), vision)), lab(simulate(hexLin(c2), vision)));
	}

	/**
	 * Pairs of active tags too close for one vision at least
	 *
	 * @param {Array<{label:string,color:string,status?:number}>} tags Tags in their order
	 * @return {Array<{a:number,b:number,visions:string[]}>} Indexes in tags, visions where they collide
	 */
	function risks(tags) {
		const out = [];
		const active = tags.map((tg, i) => ({ tg, i })).filter(x => x.tg.status === undefined || +x.tg.status === 1);
		for (let x = 0; x < active.length; x++) {
			for (let y = x + 1; y < active.length; y++) {
				const visions = VISIONS.filter(v => distance(active[x].tg.color, active[y].tg.color, v) < THRESHOLD);
				if (visions.length) {
					out.push({ a: active[x].i, b: active[y].i, visions });
				}
			}
		}
		return out;
	}

	/** Visions of the user (Dolibarr option of his card), empty when none is declared */
	function userVisions() {
		const setting = Dolibarr.getContextVar('INBOX_COLORBLIND', '');
		return SETTING_VISIONS[setting] || [];
	}

	/**
	 * Colours for one viewer: in the order of the tags, a colour too close (for his visions) to one already kept is
	 * replaced by the colour of the palettes the most distinct from the ones kept
	 *
	 * @param {Array<{color:string}>} tags    Active tags in their order
	 * @param {string[]}              visions Visions of the viewer
	 * @return {string[]} Colour to show for each tag
	 */
	function adapt(tags, visions) {
		if (!visions.length) {
			return tags.map(tg => tg.color);
		}
		const kept = [];
		const minDist = c => kept.length ? Math.min(...kept.flatMap(k => visions.map(v => distance(c, k, v)))) : Infinity;
		return tags.map(tg => {
			let color = tg.color;
			if (isColor(color) && minDist(color) < THRESHOLD) {
				const best = POOL.filter(c => !kept.includes(c)).map(c => ({ c, d: minDist(c) })).sort((p, q) => q.d - p.d)[0];
				color = best ? best.c : color;
			}
			if (isColor(color)) {
				kept.push(color);
			}
			return color;
		});
	}

	/** Black gives more contrast than white on this colour (WCAG relative luminance) */
	function darkText(hex) {
		if (!isColor(hex)) {
			return false;
		}
		const [r, g, b] = hexLin(hex);
		const L = 0.2126 * r + 0.7152 * g + 0.0722 * b;
		return (L + 0.05) / 0.05 > 1.05 / (L + 0.05);
	}

	/** WCAG contrast ratio of two #RRGGBB colours */
	function contrast(c1, c2) {
		const lum = hex => {
			const [r, g, b] = hexLin(hex);
			return 0.2126 * r + 0.7152 * g + 0.0722 * b;
		};
		const a = lum(c1);
		const b = lum(c2);
		return (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);
	}

	// Background of the folders pane (--ibx-bg-pane), light and dark theme
	const PANE = { light: '#f7f7f8', dark: '#1f1f23' };

	/**
	 * A colour chosen for an icon, kept readable (3:1, WCAG 1.4.11) on the pane: mixed with black (light theme) or
	 * white (dark theme) until it is
	 *
	 * @param {string} hex   #RRGGBB
	 * @param {string} theme light or dark
	 * @return {string} #RRGGBB
	 */
	function readable(hex, theme) {
		if (!isColor(hex)) {
			return '';
		}
		const bg = PANE[theme] || PANE.light;
		const to = theme === 'dark' ? 255 : 0;
		const rgb = [1, 3, 5].map(i => parseInt(hex.substr(i, 2), 16));
		let out = hex;
		for (let k = 1; k <= 20 && contrast(out, bg) < 3; k++) {
			out = '#' + rgb.map(v => Math.round(v + (to - v) * k / 20).toString(16).padStart(2, '0')).join('');
		}
		return out;
	}

	/**
	 * HTML of a tag badge
	 *
	 * @param {{label:string,color:string,border?:string}} tag   Tag
	 * @param {string}                                      color Colour to show (adapted), else the one of the tag
	 * @return {string}
	 */
	function badge(tag, color) {
		const c = isColor(color) ? color : (isColor(tag.color) ? tag.color : '');
		const esc = Dolibarr.tools.inbox.esc;
		const border = BORDERS.includes(tag.border) && tag.border !== 'none' ? ' ibx-tag--b-' + tag.border : '';
		return '<span class="ibx-tag' + border + (darkText(c) ? ' ibx-tag--dark' : '') + '"' + (c ? ' style="--ibx-tag-color:' + c + '"' : '') + '>' + esc(tag.label) + '</span>';
	}

	/**
	 * HTML of a tag as a dot: its colour and the initial of its name (not the colour alone, P28), its border, the
	 * name in the title and for the screen readers
	 *
	 * @param {{label:string,color:string,border?:string}} tag   Tag
	 * @param {string}                                      color Colour to show (adapted), else the one of the tag
	 * @return {string}
	 */
	function dot(tag, color) {
		const c = isColor(color) ? color : (isColor(tag.color) ? tag.color : '');
		const esc = Dolibarr.tools.inbox.esc;
		const border = BORDERS.includes(tag.border) && tag.border !== 'none' ? ' ibx-tag--b-' + tag.border : '';
		// First grapheme (a flag or an emoji of several code points stays whole), upper case when it stays one
		const text = String(tag.label || '').trim().normalize('NFC');
		const first = (typeof Intl !== 'undefined' && Intl.Segmenter ? Array.from(new Intl.Segmenter(undefined, { granularity: 'grapheme' }).segment(text), x => x.segment)[0] : Array.from(text)[0]) || '?';
		const initial = first.toUpperCase().length === first.length ? first.toUpperCase() : first;
		return '<span class="ibx-tag ibx-tag--dot' + border + (darkText(c) ? ' ibx-tag--dark' : '') + '"' + (c ? ' style="--ibx-tag-color:' + c + '"' : '') + ' title="' + esc(tag.label) + '">'
			+ '<span aria-hidden="true">' + esc(initial) + '</span><span class="ibx-sr-only">' + esc(tag.label) + '</span></span>';
	}

	Dolibarr.defineTool('inboxColors', { THRESHOLD, PALETTES, BORDERS, VISIONS, SETTING_VISIONS, PANE, distance, risks, adapt, userVisions, darkText, badge, dot, isColor, contrast, readable });
})();
