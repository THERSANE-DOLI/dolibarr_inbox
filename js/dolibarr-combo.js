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
		minChars: 'Type at least %s characters', searching: 'Searching…', clear: 'Clear',
	};
	const CHEVRON = '<svg class="dol-combo__chevron" viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false"><path d="M6 9l6 6 6-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
	const CHECK = '<svg viewBox="0 0 24 24" width="14" height="14" aria-hidden="true" focusable="false"><path d="M5 12l5 5L20 7" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg>';
	let seq = 0;
	let current = null;

	const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', '\'': '&#39;' }[c]));
	const fold = s => String(s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
	const fmt = (s, v) => String(s).replace('%s', v);

	/** Items of an answer of a server, whatever its usual shape */
	function normalize(data) {
		const list = Array.isArray(data) ? data : (data && Array.isArray(data.results) ? data.results : null);
		if (list) {
			return list.map(x => (x && typeof x === 'object' ? { value: String(x.value ?? x.id ?? x.key ?? ''), label: String(x.label ?? x.text ?? x.value ?? ''), sub: x.sub ? String(x.sub) : '', group: x.group ? String(x.group) : '' }
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
		const n = ++seq;
		const wrap = document.createElement('span');
		wrap.className = 'dol-combo';
		const btn = document.createElement('button');
		btn.type = 'button';
		// The classes of style of the select: the button looks like the other fields of the page. Never the classes the
		// code looks for (js-..., x-js-...): a search by class must find the select, not the button
		btn.className = (Array.from(sel.classList).filter(k => !/(^|-)js(-|$)/.test(k)).join(' ') + ' dol-combo__btn').trim();
		btn.id = (sel.id || 'dol-combo' + n) + '-combo';
		btn.setAttribute('aria-haspopup', 'listbox');
		btn.setAttribute('aria-expanded', 'false');
		btn.innerHTML = '<span class="dol-combo__val"></span>' + CHEVRON;
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
		const c = { n, sel, btn, wrap, val: btn.firstChild, pop: null, abort: null };
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
			c.val.textContent = text;
			c.val.classList.toggle('is-empty', empty);
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
				const r = btn.getBoundingClientRect();
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
					html += '<li class="dol-combo__opt' + (it.disabled ? ' is-disabled' : '') + '" role="option" id="' + lid + '-' + i + '" data-i="' + i + '" aria-selected="' + (it.selected ? 'true' : 'false') + '"' + (it.disabled ? ' aria-disabled="true"' : '') + '>'
						+ (sel.multiple ? '<span class="dol-combo__check" aria-hidden="true">' + (it.selected ? CHECK : '') + '</span>' : '')
						+ '<span class="dol-combo__text"><span>' + esc(it.label || L.none) + '</span>' + (it.sub ? '<span class="dol-combo__sub">' + esc(it.sub) + '</span>' : '') + '</span></li>';
				});
				const extra = more + Math.max(0, items.length - shown.length);
				if (!shown.length) {
					html = '<li class="dol-combo__note" role="presentation">' + esc(L.noResult) + '</li>';
				} else if (extra > 0) {
					html += '<li class="dol-combo__note" role="presentation">' + esc(fmt(L.typeMore, extra)) + '</li>';
				}
				list.innerHTML = html;
				const cur = shown.findIndex(x => x.selected);
				setActive(input.value.trim() ? 0 : Math.max(0, cur));
			};
			const local = () => Array.from(sel.options).filter(x => !x.hidden && !(sel.multiple && x.value === '')).map(x => ({
				value: x.value, label: x.textContent.trim(), sub: x.dataset.sub || '', group: x.parentElement && x.parentElement.tagName === 'OPTGROUP' ? x.parentElement.label : '',
				selected: x.selected, disabled: x.disabled, opt: x,
			}));
			const filter = () => {
				const words = fold(input.value).split(/\s+/).filter(Boolean);
				if (!source) {
					draw(local().filter(it => words.every(w => fold(it.label + ' ' + it.sub).includes(w))), 0);
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
						draw(normalize(items).map(it => Object.assign(it, { selected: sel2.has(it.value), disabled: false })), 0);
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
