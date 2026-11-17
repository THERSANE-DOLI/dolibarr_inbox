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
 * Searchable list of choices: tool « combo » of the JavaScript context of Dolibarr (Dolibarr.tools.combo).
 *
 * Written to go into the core later, with no dependency (no jQuery, nothing of the module that ships it): a module
 * uses the tool of the core when there is one, else this copy defines it. Opt-in: only the selects marked
 * data-combo, or given to attach().
 *
 * The native select stays the truth: kept in the page (visually hidden, still validated by the browser when it is
 * required), its value and options in sync, a native « change » on each choice (the listeners of jQuery get it too).
 * A value set by the code (sel.value = x, $(sel).val(x)) or options loaded later show on the button.
 *
 *   <select data-combo>                          searchable list
 *   <select data-combo multiple>                 several choices
 *   <select data-combo data-combo-clear="1" data-combo-placeholder="...">
 *   <select data-combo data-combo-source="/ajax/x.php" data-combo-min="2">
 *                                                 options asked to the server as one types: GET ?term=...,
 *                                                 answer [{value, label, sub?, group?}], {results: [...]} or {value: label}
 *   <option data-sub="Lyon">ACME</option>        second line of an option (text only)
 *   <select data-combo multiple data-combo-chips> the choices as chips in the field, each with its « × »
 *   <option data-color="#3b82f6">                colour of the option (a dot in the list, the colour of its chip)
 *   <option data-depth="1" data-path="Sales > Wholesale">
 *                                                 place in a tree: indented, searched on its whole path
 *   <option data-avatar-img="/viewimage.php?..." data-avatar-text="JB" data-avatar-color="#8a2b6e"
 *           data-avatar-shape="square" data-avatar-icon="group">
 *                                                 picture on the left of the option and of the choice: a photo, else
 *                                                 its letters, or a built-in icon (user, group); round, or square
 *                                                 (a group); a source gives it as avatar: {img, text, color, shape, icon}
 *   an item of a source with disabled: true       shown greyed, its sub says why
 *   a source answering {results: [...], more: n}  n items not given: « n more, type to narrow » under the list
 *
 *   Dolibarr.tools.combo.attach(select, {source: async (term, signal) => items, minLength, delay, placeholder,
 *                                        clear, nativeOnTouch})  => {open, close, refresh, destroy}
 *   Dolibarr.tools.combo.scan(root)              attach the marked selects under root (done for new nodes)
 *   Dolibarr.tools.combo.labels({search, none, noResult, typeMore, moreChosen, minChars, searching, clear})
 *
 * On a phone (coarse pointer) a single choice without source keeps the native list of the device.
 */
(function () {
	'use strict';

	if (!window.Dolibarr || typeof Dolibarr.defineTool !== 'function' || (Dolibarr.tools && Dolibarr.tools.combo)) {
		return;
	}

	const L = {
		search: 'Search…', none: 'None', noResult: 'No result', typeMore: '%s more, type to narrow', moreChosen: '+%s',
		minChars: 'Type at least %s characters', searching: 'Searching…', clear: 'Clear', remove: 'Remove %s',
	};
	const CHEVRON = '<svg class="dol-combo__chevron" viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false"><path d="M6 9l6 6 6-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
	const CHECK = '<svg viewBox="0 0 24 24" width="14" height="14" aria-hidden="true" focusable="false"><path d="M5 12l5 5L20 7" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg>';
	const ICONS = {
		user: '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false"><circle cx="12" cy="8" r="4" fill="none" stroke="currentColor" stroke-width="2"/><path d="M5 20c0-3.5 3.1-6 7-6s7 2.5 7 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>',
		group: '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false"><circle cx="9" cy="8" r="3.5" fill="none" stroke="currentColor" stroke-width="2"/><path d="M3 20c0-3.3 2.7-5.5 6-5.5s6 2.2 6 5.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><path d="M16 4.6a3.5 3.5 0 0 1 0 6.8M18 14.8c1.8.7 3 2.6 3 5.2" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>',
	};
	let seq = 0;
	let current = null;

	const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', '\'': '&#39;' }[c]));
	const fold = s => String(s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
	const fmt = (s, v) => String(s).replace('%s', v);
	/** A colour #rgb / #rrggbb (else ''), and the text that reads on it */
	const color = v => (/^#?([0-9a-f]{3}|[0-9a-f]{6})$/i.test(String(v || '').trim()) ? '#' + String(v).trim().replace(/^#/, '') : '');
	/** Colour of a picture: #rgb, #rrggbb or hsl(h s% l%) (a hue drawn from a name), else '' */
	const avatarColor = v => {
		const hsl = /^hsl\(\s*\d{1,3}(\.\d+)?(deg)?[\s,]+\d{1,3}(\.\d+)?%[\s,]+\d{1,3}(\.\d+)?%\s*\)$/i.test(String(v || '').trim());
		return hsl ? String(v).trim() : color(v);
	};
	const ink = hex => {
		if (hex.indexOf('hsl') === 0) {
			const l = +(hex.match(/(\d+(\.\d+)?)%\s*\)$/) || [0, 50])[1];
			return l > 60 ? '#1f2328' : '#ffffff';
		}
		let h = hex.slice(1);
		if (h.length === 3) {
			h = h.split('').map(x => x + x).join('');
		}
		const [r, g, b] = [0, 2, 4].map(i => parseInt(h.substr(i, 2), 16) / 255).map(x => (x <= 0.03928 ? x / 12.92 : ((x + 0.055) / 1.055) ** 2.4));
		return 0.2126 * r + 0.7152 * g + 0.0722 * b > 0.4 ? '#1f2328' : '#ffffff';
	};

	/** Picture of an item: {img, text, color, shape, icon} kept when it says something, else null */
	function avatarOf(a) {
		if (!a || typeof a !== 'object') {
			return null;
		}
		const img = typeof a.img === 'string' && /^(https?:\/\/|\/|\.{0,2}\/|[\w-]+\.php)/i.test(a.img) ? a.img : '';
		const out = { img, text: String(a.text || '').slice(0, 3), color: avatarColor(a.color), shape: a.shape === 'square' ? 'square' : 'circle', icon: ICONS[a.icon] ? a.icon : '' };
		return out.img || out.text || out.icon ? out : null;
	}
	function avatarHtml(a, cls) {
		if (!a) {
			return '';
		}
		return '<span class="dol-combo__avatar' + (a.shape === 'square' ? ' dol-combo__avatar--square' : '') + (cls ? ' ' + cls : '') + '"'
			+ (a.color ? ' style="background:' + esc(a.color) + ';color:' + ink(a.color) + '"' : '') + ' aria-hidden="true">'
			+ (a.icon ? ICONS[a.icon] : '<span>' + esc(a.text) + '</span>')
			// A photo that does not load leaves its letters (the listener is put after the drawing)
			+ (a.img ? '<img src="' + esc(a.img) + '" alt="" loading="lazy" decoding="async">' : '') + '</span>';
	}
	function avatarData(opt) {
		const d = opt.dataset;
		return avatarOf({ img: d.avatarImg, text: d.avatarText, color: d.avatarColor, shape: d.avatarShape, icon: d.avatarIcon });
	}
	function dropBroken(root) {
		root.querySelectorAll('.dol-combo__avatar img:not([data-watched])').forEach(img => {
			img.dataset.watched = '1';
			img.addEventListener('error', () => img.remove());
		});
	}

	/** Items of an answer of a server, whatever its usual shape */
	function normalize(data) {
		const list = Array.isArray(data) ? data : (data && Array.isArray(data.results) ? data.results : null);
		if (list) {
			return list.map(x => (x && typeof x === 'object' ? { value: String(x.value ?? x.id ?? x.key ?? ''), label: String(x.label ?? x.text ?? x.value ?? ''), sub: x.sub ? String(x.sub) : '', group: x.group ? String(x.group) : '',
				avatar: avatarOf(x.avatar), disabled: !!x.disabled }
				: { value: String(x), label: String(x), sub: '', group: '' })).filter(x => x.value !== '');
		}
		return data && typeof data === 'object' ? Object.keys(data).map(k => ({ value: k, label: String(data[k]), sub: '', group: '' })) : [];
	}

	function urlSource(url) {
		return async (term, signal) => {
			const r = await fetch(url + (url.indexOf('?') >= 0 ? '&' : '?') + 'term=' + encodeURIComponent(term), { credentials: 'same-origin', headers: { Accept: 'application/json' }, signal });
			if (!r.ok) {
				throw new Error(String(r.status));
			}
			return normalize(await r.json());
		};
	}

	function close(focusBack) {
		if (!current) {
			return;
		}
		const c = current;
		current = null;
		if (c.abort) {
			c.abort.abort();
		}
		c.pop.remove();
		c.pop = null;
		c.btn.setAttribute('aria-expanded', 'false');
		if (focusBack) {
			c.btn.focus();
		}
	}

	function attach(sel, o) {
		if (!sel || sel.tagName !== 'SELECT' || sel._dolCombo) {
			return sel && sel._dolCombo ? sel._dolCombo.api : null;
		}
		o = o || {};
		const d = sel.dataset;
		const source = typeof o.source === 'function' ? o.source : (o.source || d.comboSource ? urlSource(o.source || d.comboSource) : null);
		const nativeOnTouch = o.nativeOnTouch !== undefined ? !!o.nativeOnTouch : d.comboNativeTouch !== '0';
		if (nativeOnTouch && !sel.multiple && !source && window.matchMedia && window.matchMedia('(pointer: coarse)').matches) {
			return null;
		}
		const minLength = +(o.minLength ?? d.comboMin ?? (source ? 2 : 0));
		const delay = +(o.delay ?? 250);
		const placeholder = o.placeholder ?? d.comboPlaceholder ?? '';
		const clearable = !!(o.clear ?? (d.comboClear === '1')) && !sel.multiple;
		const chips = sel.multiple && !!(o.chips ?? (d.comboChips !== undefined && d.comboChips !== '0'));
		const n = ++seq;
		const wrap = document.createElement('span');
		wrap.className = 'dol-combo' + (chips ? ' dol-combo--chips' : '');
		const btn = document.createElement('button');
		btn.type = 'button';
		// The classes of style of the select: the button looks like the other fields of the page. Never the classes the
		// code looks for (js-..., x-js-...): a search by class must find the select, not the button
		const styles = Array.from(sel.classList).filter(k => !/(^|-)js(-|$)/.test(k)).join(' ');
		btn.className = ((chips ? '' : styles) + ' dol-combo__btn').trim();
		if (chips) {
			// The field is the frame: the chips, then the button that opens the list
			wrap.className += ' ' + styles;
		}
		btn.id = (sel.id || 'dol-combo' + n) + '-combo';
		btn.setAttribute('aria-haspopup', 'listbox');
		btn.setAttribute('aria-expanded', 'false');
		btn.innerHTML = '<span class="dol-combo__face"></span><span class="dol-combo__val"></span>' + CHEVRON;
		const label = sel.id ? document.querySelector('label[for="' + CSS.escape(sel.id) + '"]') : null;
		const name = sel.getAttribute('aria-label') || (label ? label.textContent.trim() : '');
		if (label) {
			label.htmlFor = btn.id;
		}
		if (name) {
			btn.setAttribute('aria-label', name);
		}
		if (sel.required) {
			btn.setAttribute('aria-required', 'true');
		}
		let clearBtn = null;
		sel.parentNode.insertBefore(wrap, sel);
		let chipBox = null;
		if (chips) {
			chipBox = document.createElement('span');
			chipBox.className = 'dol-combo__chips';
			wrap.appendChild(chipBox);
		}
		wrap.appendChild(btn);
		if (clearable) {
			clearBtn = document.createElement('button');
			clearBtn.type = 'button';
			clearBtn.className = 'dol-combo__clear';
			clearBtn.setAttribute('aria-label', L.clear);
			clearBtn.title = L.clear;
			clearBtn.innerHTML = '<svg viewBox="0 0 24 24" width="14" height="14" aria-hidden="true" focusable="false"><path d="M6 6l12 12M18 6L6 18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';
			wrap.appendChild(clearBtn);
		}
		wrap.appendChild(sel);
		sel.classList.add('dol-combo__native');
		const tabIndex = sel.getAttribute('tabindex');
		sel.tabIndex = -1;
		const c = { n, sel, btn, wrap, face: btn.firstChild, val: btn.children[1], pop: null, abort: null };
		sel._dolCombo = c;

		const paint = () => {
			const chosen = Array.from(sel.selectedOptions).filter(x => x.value !== '');
			let text;
			let empty = false;
			if (!chosen.length) {
				const blank = Array.from(sel.options).find(x => x.value === '');
				text = placeholder || (blank && blank.textContent.trim()) || L.none;
				empty = true;
			} else if (sel.multiple && chosen.length > 2) {
				text = chosen.slice(0, 2).map(x => x.textContent.trim()).join(', ') + ' ' + fmt(L.moreChosen, chosen.length - 2);
			} else {
				text = chosen.map(x => x.textContent.trim()).join(', ');
			}
			if (chipBox) {
				chipBox.innerHTML = chosen.map(x => {
					const col = color(x.dataset.color);
					const path = x.dataset.path || '';
					return '<span class="dol-combo__chip"' + (col ? ' style="background:' + col + ';color:' + ink(col) + '"' : '') + (path ? ' title="' + esc(path) + '"' : '') + '>'
						+ '<span>' + esc(x.textContent.trim()) + '</span>' + (sel.disabled ? '' : '<button type="button" class="dol-combo__unchip" data-v="' + esc(x.value) + '" aria-label="' + esc(fmt(L.remove, x.textContent.trim())) + '" tabindex="-1">'
						+ '<svg viewBox="0 0 24 24" width="12" height="12" aria-hidden="true" focusable="false"><path d="M6 6l12 12M18 6L6 18" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"/></svg></button>') + '</span>';
				}).join('');
				text = empty ? text : '';
			}
			c.val.textContent = text;
			c.val.classList.toggle('is-empty', empty);
			// The picture of the single choice, before its name
			c.face.innerHTML = !sel.multiple && chosen.length ? avatarHtml(avatarData(chosen[0]), 'dol-combo__avatar--sm') : '';
			dropBroken(c.face);
			btn.disabled = sel.disabled;
			if (clearBtn) {
				clearBtn.hidden = empty || sel.disabled;
			}
		};
		const fire = () => {
			sel.dispatchEvent(new Event('input', { bubbles: true }));
			sel.dispatchEvent(new Event('change', { bubbles: true }));
		};
		/** The option of a value, made when the server gave it */
		const optionOf = it => {
			let opt = Array.from(sel.options).find(x => x.value === it.value);
			if (!opt) {
				opt = new Option(it.label, it.value);
				if (it.sub) {
					opt.dataset.sub = it.sub;
				}
				if (it.avatar) {
					const a = it.avatar;
					Object.assign(opt.dataset, { avatarImg: a.img, avatarText: a.text, avatarColor: a.color, avatarShape: a.shape, avatarIcon: a.icon });
				}
				sel.appendChild(opt);
			}
			return opt;
		};

		function open(typed) {
			close(false);
			current = c;
			const lid = 'dol-combo-l' + n;
			const pop = document.createElement('div');
			pop.className = 'dol-combo__pop';
			pop.innerHTML = '<input type="search" class="dol-combo__search" role="combobox" aria-expanded="true" aria-autocomplete="list" aria-controls="' + lid + '" autocomplete="off" spellcheck="false"'
				+ ' placeholder="' + esc(L.search) + '" aria-label="' + esc(L.search) + '">'
				+ '<ul class="dol-combo__list" id="' + lid + '" role="listbox"' + (sel.multiple ? ' aria-multiselectable="true"' : '') + (name ? ' aria-label="' + esc(name) + '"' : '') + '></ul>';
			c.pop = pop;
			// In a modal dialog: inside it, the rest of the page is inert
			(btn.closest('dialog[open]') || document.body).appendChild(pop);
			const input = pop.querySelector('input');
			const list = pop.querySelector('ul');
			let shown = [];
			let active = -1;
			let timer = 0;
			const place = () => {
				const r = (chips ? wrap : btn).getBoundingClientRect();
				const w = Math.max(r.width, 240);
				const below = window.innerHeight - r.bottom;
				pop.style.left = Math.max(4, Math.min(r.left, window.innerWidth - w - 4)) + 'px';
				pop.style.width = w + 'px';
				if (below < 260 && r.top > below) {
					pop.style.top = '';
					pop.style.bottom = (window.innerHeight - r.top + 4) + 'px';
				} else {
					pop.style.bottom = '';
					pop.style.top = (r.bottom + 4) + 'px';
				}
			};
			const setActive = i => {
				active = shown.length ? Math.max(0, Math.min(i, shown.length - 1)) : -1;
				list.querySelectorAll('.is-active').forEach(x => x.classList.remove('is-active'));
				const el = active >= 0 ? list.querySelector('[data-i="' + active + '"]') : null;
				if (el) {
					el.classList.add('is-active');
					el.scrollIntoView({ block: 'nearest' });
					input.setAttribute('aria-activedescendant', el.id);
				} else {
					input.removeAttribute('aria-activedescendant');
				}
			};
			const note = text => {
				list.innerHTML = '<li class="dol-combo__note" role="presentation">' + esc(text) + '</li>';
				shown = [];
				setActive(-1);
			};
			/** Items: {value, label, sub, group, selected, disabled} */
			const draw = (items, more) => {
				shown = items.slice(0, 300);
				let group = null;
				let html = '';
				shown.forEach((it, i) => {
					if (it.group && it.group !== group) {
						html += '<li class="dol-combo__group" role="presentation">' + esc(it.group) + '</li>';
					}
					group = it.group || null;
					// The depth shows only on the whole tree: a search lists the paths
					const depth = input.value.trim() ? 0 : Math.min(it.depth || 0, 8);
					const sub = it.sub || (input.value.trim() && it.path && it.path !== it.label ? it.path : '');
					html += '<li class="dol-combo__opt' + (it.disabled ? ' is-disabled' : '') + '" role="option" id="' + lid + '-' + i + '" data-i="' + i + '" aria-selected="' + (it.selected ? 'true' : 'false') + '"' + (it.disabled ? ' aria-disabled="true"' : '')
						+ (depth ? ' style="padding-left:' + (0.5 + depth * 1.1) + 'rem"' : '') + (it.path ? ' title="' + esc(it.path) + '"' : '') + '>'
						+ (sel.multiple ? '<span class="dol-combo__check" aria-hidden="true">' + (it.selected ? CHECK : '') + '</span>' : '')
						+ (it.color ? '<span class="dol-combo__dot" style="background:' + it.color + '" aria-hidden="true"></span>' : '')
						+ avatarHtml(it.avatar, it.sub ? '' : 'dol-combo__avatar--sm')
						+ '<span class="dol-combo__text"><span>' + esc(it.label || L.none) + '</span>' + (sub ? '<span class="dol-combo__sub">' + esc(sub) + '</span>' : '') + '</span></li>';
				});
				const extra = more + Math.max(0, items.length - shown.length);
				if (!shown.length) {
					html = '<li class="dol-combo__note" role="presentation">' + esc(L.noResult) + '</li>';
				} else if (extra > 0) {
					html += '<li class="dol-combo__note" role="presentation">' + esc(fmt(L.typeMore, extra)) + '</li>';
				}
				list.innerHTML = html;
				dropBroken(list);
				const cur = shown.findIndex(x => x.selected && !x.disabled);
				setActive(input.value.trim() ? 0 : Math.max(0, cur));
			};
			const local = () => Array.from(sel.options).filter(x => !x.hidden && !(sel.multiple && x.value === '')).map(x => ({
				value: x.value, label: x.textContent.trim(), sub: x.dataset.sub || '', group: x.parentElement && x.parentElement.tagName === 'OPTGROUP' ? x.parentElement.label : '',
				selected: x.selected, disabled: x.disabled, opt: x, color: color(x.dataset.color), depth: +(x.dataset.depth || 0), path: x.dataset.path || '', avatar: avatarData(x),
			}));
			const filter = () => {
				const words = fold(input.value).split(/\s+/).filter(Boolean);
				if (!source) {
					// In a tree, the whole path is searched: the parent finds its children
					draw(local().filter(it => words.every(w => fold(it.label + ' ' + it.sub + ' ' + it.path).includes(w))), 0);
					return;
				}
				clearTimeout(timer);
				const term = input.value.trim();
				if (term.length < minLength) {
					// What is chosen, then how to search
					const chosen = local().filter(it => it.selected && it.value !== '');
					if (chosen.length) {
						draw(chosen, 0);
						list.insertAdjacentHTML('beforeend', '<li class="dol-combo__note" role="presentation">' + esc(fmt(L.minChars, minLength)) + '</li>');
					} else {
						note(fmt(L.minChars, minLength));
					}
					return;
				}
				note(L.searching);
				timer = setTimeout(async () => {
					if (c.abort) {
						c.abort.abort();
					}
					c.abort = new AbortController();
					try {
						const items = await source(term, c.abort.signal);
						if (current !== c) {
							return;
						}
						const sel2 = new Set(Array.from(sel.selectedOptions).map(x => x.value));
						const more = items && !Array.isArray(items) ? Math.max(0, +items.more || 0) : 0;
						draw(normalize(items).map(it => Object.assign(it, { selected: sel2.has(it.value), disabled: !!it.disabled })), more);
					} catch (err) {
						if (err && err.name !== 'AbortError' && current === c) {
							note(L.noResult);
						}
					}
				}, delay);
			};
			const choose = i => {
				const it = shown[i];
				if (!it || it.disabled) {
					return;
				}
				const opt = it.opt || optionOf(it);
				if (sel.multiple) {
					opt.selected = !opt.selected;
					it.selected = opt.selected;
				} else {
					sel.value = opt.value;
				}
				fire();
				paint();
				if (sel.multiple) {
					const li = list.querySelector('[data-i="' + i + '"]');
					if (li) {
						li.setAttribute('aria-selected', it.selected ? 'true' : 'false');
						li.querySelector('.dol-combo__check').innerHTML = it.selected ? CHECK : '';
					}
				} else {
					close(true);
				}
			};
			input.addEventListener('input', filter);
			input.addEventListener('keydown', e => {
				if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
					e.preventDefault();
					setActive(active + (e.key === 'ArrowDown' ? 1 : -1));
				} else if (e.key === 'PageDown' || e.key === 'PageUp') {
					e.preventDefault();
					setActive(active + (e.key === 'PageDown' ? 8 : -8));
				} else if (e.key === 'Enter') {
					e.preventDefault();
					choose(active);
				} else if (e.key === 'Escape') {
					e.preventDefault();
					e.stopPropagation();
					close(true);
				} else if (e.key === 'Tab') {
					close(false);
				} else if (e.key === 'Backspace' && chips && input.value === '') {
					// Backspace in an empty search: the last chip goes
					const last = Array.from(sel.selectedOptions).filter(x => x.value !== '').pop();
					if (last) {
						last.selected = false;
						fire();
						paint();
						filter();
					}
				}
			});
			list.addEventListener('mousedown', e => e.preventDefault());
			list.addEventListener('click', e => {
				const li = e.target.closest('[data-i]');
				if (li) {
					choose(+li.dataset.i);
				}
			});
			btn.setAttribute('aria-expanded', 'true');
			input.value = typed || '';
			filter();
			place();
			input.focus();
		}

		btn.addEventListener('click', () => (current === c ? close(true) : open('')));
		btn.addEventListener('keydown', e => {
			if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
				e.preventDefault();
				open('');
			} else if (e.key.length === 1 && e.key !== ' ' && !e.ctrlKey && !e.metaKey && !e.altKey) {
				e.preventDefault();
				open(e.key);
			}
		});
		if (clearBtn) {
			clearBtn.addEventListener('click', () => {
				sel.value = '';
				if (sel.value !== '') {
					sel.selectedIndex = -1;
				}
				fire();
				paint();
				btn.focus();
			});
		}
		if (chipBox) {
			chipBox.addEventListener('click', e => {
				const x = e.target.closest('.dol-combo__unchip');
				if (x) {
					const opt = Array.from(sel.options).find(op => op.value === x.dataset.v);
					if (opt) {
						opt.selected = false;
						fire();
						paint();
						if (current === c) {
							close(false);
						}
					}
					return;
				}
				open('');
			});
			wrap.addEventListener('mousedown', e => {
				if (e.target === wrap) {
					e.preventDefault();
					open('');
				}
			});
		}
		// The code that focuses the select (first field of a form, invalid field) reaches the button
		sel.addEventListener('focus', () => btn.focus());
		sel.addEventListener('change', paint);
		if (window.jQuery) {
			window.jQuery(sel).on('change.dolcombo', paint);
		}
		// A value set by the code without « change » (sel.value = x, $(sel).val(x)): the button follows
		['value', 'selectedIndex'].forEach(prop => {
			const desc = Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, prop);
			Object.defineProperty(sel, prop, { configurable: true, get() {
				return desc.get.call(this);
			}, set(v) {
				desc.set.call(this, v);
				paint();
			} });
		});
		// Options loaded later (Ajax), an option selected by the code, disabled: the button follows
		const watch = new MutationObserver(paint);
		watch.observe(sel, { childList: true, subtree: true, attributes: true, attributeFilter: ['disabled', 'selected'] });
		paint();

		c.api = {
			open: () => open(''),
			close: () => (current === c ? close(false) : null),
			refresh: paint,
			destroy() {
				if (current === c) {
					close(false);
				}
				watch.disconnect();
				if (window.jQuery) {
					window.jQuery(sel).off('change.dolcombo');
				}
				delete sel.value;
				delete sel.selectedIndex;
				sel.removeEventListener('change', paint);
				sel.classList.remove('dol-combo__native');
				if (tabIndex === null) {
					sel.removeAttribute('tabindex');
				} else {
					sel.setAttribute('tabindex', tabIndex);
				}
				if (label) {
					label.htmlFor = sel.id;
				}
				wrap.parentNode.insertBefore(sel, wrap);
				wrap.remove();
				delete sel._dolCombo;
			},
		};
		return c.api;
	}

	function scan(root) {
		root = root || document;
		const list = root.matches && root.matches('select[data-combo]') ? [root] : Array.from(root.querySelectorAll ? root.querySelectorAll('select[data-combo]') : []);
		list.forEach(s => {
			if (s.dataset.combo !== 'off') {
				attach(s);
			}
		});
	}

	document.addEventListener('mousedown', e => {
		if (current && !current.pop.contains(e.target) && !current.wrap.contains(e.target)) {
			close(false);
		}
	}, true);
	window.addEventListener('resize', () => close(false));
	document.addEventListener('scroll', e => {
		if (current && !current.pop.contains(e.target)) {
			close(false);
		}
	}, true);
	const start = () => {
		scan(document);
		new MutationObserver(records => records.forEach(r => r.addedNodes.forEach(nd => {
			if (nd.nodeType === 1 && !(nd.classList && nd.classList.contains('dol-combo__pop'))) {
				scan(nd);
			}
		}))).observe(document.body, { childList: true, subtree: true });
	};
	if (document.body) {
		start();
	} else {
		document.addEventListener('DOMContentLoaded', start);
	}

	Dolibarr.defineTool('combo', {
		attach,
		scan,
		labels: o => Object.assign(L, o || {}),
	});
})();
