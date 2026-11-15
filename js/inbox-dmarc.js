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
 * DMARC aggregate reports (1.111.0): a bar above the text of a mail that holds a report, « Analyze » opens a dialog
 * with the analysis made by the server (ajax/dmarc.php): our servers that fail, figures, sources, records, current DNS.
 * A report can be forged: every value of it is escaped (esc), none is ever used as HTML, a link or a style.
 * Chained after js/inbox-invite.js on I.mailExtrasHtml and I.bindMailExtras.
 */
(function () {
	'use strict';

	const I = Dolibarr.tools.inbox;
	const { esc, t, th, ic, api, toast, $, $$ } = I;

	/** Lines shown in a table (the server sends at most 2000; filters to go further) */
	const MAX_SHOWN = 500;

	function fmtDay(ts) {
		return ts ? new Date(ts * 1000).toLocaleDateString(I.locale(), { day: 'numeric', month: 'short', year: 'numeric' }) : '?';
	}
	function num(n) {
		return Number(n || 0).toLocaleString(I.locale());
	}
	function pct(part, total) {
		return total ? Math.round(part * 1000 / total) / 10 + ' %' : '-';
	}

	// ---------- Bar of the reading ----------

	function barHtml(m) {
		const d = m.dmarc;
		const period = d.begin ? t('InboxDmarcPeriod', fmtDay(d.begin), fmtDay(d.end || d.begin)) : '';
		return '<div class="ibx-banner ibx-banner--info ibx-js-dmarcbar">' + ic('shield')
			+ '<div class="ibx-banner__text"><b>' + th('InboxDmarcReport') + '</b> '
			+ esc([d.domain, d.submitter ? t('InboxDmarcBy', d.submitter) : '', period].filter(Boolean).join(' · ')) + '</div>'
			+ '<button type="button" class="ibx-btn ibx-btn--sm ibx-js-dmarcopen">' + th('InboxDmarcAnalyze') + '</button></div>';
	}

	const prevHtml = I.mailExtrasHtml;
	I.mailExtrasHtml = (m, opts) => {
		const before = prevHtml ? prevHtml(m, opts) : '';
		return (opts && opts.inline) || !m.dmarc ? before : before + barHtml(m);
	};

	const prevBind = I.bindMailExtras;
	I.bindMailExtras = (m, root) => {
		if (prevBind) {
			prevBind(m, root);
		}
		const b = m.dmarc ? $('.ibx-js-dmarcopen', root) : null;
		if (b) {
			b.addEventListener('click', () => open(m));
		}
	};

	// ---------- Dialog ----------

	function open(m) {
		const d = m.dmarc;
		const dlg = I.dialog({
			title: t('InboxDmarcTitle', d.domain || d.name),
			wide: true,
			body: '<div class="ibx-dmarc ibx-js-dmarc"><p class="ibx-muted">' + th('InboxDmarcLoading') + '</p></div>',
		});
		api('dmarc', 'analyze', { id: m.id, part: d.part }).then(r => {
			if (!dlg.isConnected) {
				return;
			}
			render($('.ibx-js-dmarc', dlg), r);
		}).catch(err => {
			const box = $('.ibx-js-dmarc', dlg);
			if (box) {
				box.innerHTML = '<div class="ibx-banner ibx-banner--warning">' + ic('warning') + '<div class="ibx-banner__text">' + esc(err.message) + '</div></div>';
			}
		});
	}

	function render(box, r) {
		const state = { tab: 'sources', failuresOnly: false, query: '', names: {}, dns: null };
		const domain = (r.reports[0] && r.reports[0].policy.domain) || '';
		const selectors = [...new Set(r.rows.flatMap(row => row.dkim.filter(k => k.selector && k.domain && aligned(k.domain, row.header_from || domain)).map(k => k.selector)))];
		box.innerHTML = alertsHtml(r) + headHtml(r) + kpisHtml(r.totals)
			+ '<div class="ibx-tabs" role="tablist">'
			+ [['sources', 'InboxDmarcTabSources'], ['records', 'InboxDmarcTabRecords'], ['dns', 'InboxDmarcTabDns']]
				.map(([id, key]) => '<button type="button" class="ibx-tab" role="tab" data-tab="' + id + '" aria-selected="' + (id === state.tab) + '">' + th(key) + '</button>').join('')
			+ '</div><div class="ibx-js-dmarcpane"></div>';
		const pane = $('.ibx-js-dmarcpane', box);
		const paint = () => {
			$$('.ibx-tab', box).forEach(b => b.setAttribute('aria-selected', String(b.dataset.tab === state.tab)));
			pane.innerHTML = state.tab === 'dns' ? dnsHtml(state, domain, selectors) : (filtersHtml(state) + (state.tab === 'sources' ? sourcesHtml(r, state) : recordsHtml(r, state)));
		};
		box.addEventListener('click', e => {
			const tab = e.target.closest('.ibx-tab');
			if (tab) {
				state.tab = tab.dataset.tab;
				paint();
				return;
			}
			if (e.target.closest('.ibx-js-dmarcgodns')) {
				state.tab = 'dns';
				paint();
				return;
			}
			const name = e.target.closest('.ibx-js-dmarcptr');
			if (name) {
				lookupName(name.dataset.ip, state, paint);
				return;
			}
			if (e.target.closest('.ibx-js-dmarcdns')) {
				runDns(domain, selectors, state, paint);
			}
		});
		box.addEventListener('change', e => {
			if (e.target.matches('.ibx-js-dmarcfail')) {
				state.failuresOnly = e.target.checked;
				paint();
			}
		});
		box.addEventListener('input', e => {
			if (e.target.matches('.ibx-js-dmarcq')) {
				state.query = e.target.value.trim().toLowerCase();
				const at = e.target.selectionStart;
				paint();
				const q = $('.ibx-js-dmarcq', box);
				q.focus();
				q.setSelectionRange(at, at);
			}
		});
		paint();
	}

	/** Same registrable domain, approximated (the server already decided the alignment of each record) */
	function aligned(a, b) {
		a = String(a || '').toLowerCase();
		b = String(b || '').toLowerCase();
		return a !== '' && b !== '' && (a === b || a.endsWith('.' + b) || b.endsWith('.' + a));
	}

	function banner(kind, icon, html, extra) {
		return '<div class="ibx-banner ibx-banner--' + kind + '">' + ic(icon) + '<div class="ibx-banner__text">' + html + '</div>' + (extra || '') + '</div>';
	}

	function alertsHtml(r) {
		let out = '';
		if (r.own_failures.length) {
			out += banner('danger', 'server', '<b>' + th('InboxDmarcOwnTitle') + '</b><ul class="ibx-dmarc__list">'
				+ r.own_failures.map(f => '<li>' + esc(t('InboxDmarcOwnFailure', f.ip, f.domain, num(f.count)))
					+ (f.causes.length ? ' <span class="ibx-muted">(' + esc(f.causes.map(c => t('InboxDmarcCause_' + c)).join(', ')) + ')</span>' : '') + '</li>').join('')
				+ '</ul><div class="ibx-banner__detail">' + I.thTerms('InboxDmarcOwnHelp') + '</div>',
			'<button type="button" class="ibx-btn ibx-btn--sm ibx-js-dmarcgodns">' + th('InboxDmarcCheckDns') + '</button>');
		}
		if (!r.proven) {
			out += banner('warning', 'warning', I.thTerms('InboxDmarcNotProven'));
		}
		if (r.foreign.length) {
			out += banner('warning', 'warning', esc(t('InboxDmarcForeign', r.foreign.join(', '))));
		}
		const spoofed = r.rows.filter(row => row.dmarc === 'fail' && !row.own).reduce((n, row) => n + row.count, 0);
		if (spoofed) {
			out += banner('info', 'info', esc(t('InboxDmarcSpoofInfo', num(spoofed))));
		}
		if (r.partial) {
			out += banner('info', 'info', th('InboxDmarcPartial'));
		}
		return out;
	}

	function headHtml(r) {
		return '<dl class="ibx-dmarc__head">' + r.reports.map(rep => {
			const p = rep.policy;
			const policy = p.p ? ['p=' + p.p, p.sp ? 'sp=' + p.sp : '', p.pct < 100 ? 'pct=' + p.pct : '', p.adkim ? 'adkim=' + p.adkim : '', p.aspf ? 'aspf=' + p.aspf : ''].filter(Boolean).join('; ') : '?';
			return '<div><dt>' + th('InboxDmarcReporter') + '</dt><dd>' + esc(rep.org || '?') + (rep.email ? ' <span class="ibx-muted">' + esc(rep.email) + '</span>' : '') + '</dd></div>'
				+ '<div><dt>' + th('InboxDmarcPeriodLabel') + '</dt><dd>' + esc(t('InboxDmarcPeriod', fmtDay(rep.begin), fmtDay(rep.end))) + '</dd></div>'
				+ '<div><dt>' + th('InboxDmarcDomain') + '</dt><dd>' + esc(p.domain || '?') + '</dd></div>'
				+ '<div><dt>' + th('InboxDmarcPolicy') + '</dt><dd><code>' + esc(policy) + '</code></dd></div>';
		}).join('') + '</dl>';
	}

	function kpisHtml(k) {
		const items = [
			['InboxDmarcKpiMessages', num(k.messages)],
			['InboxDmarcKpiSources', num(k.ips)],
			['InboxDmarcKpiCompliant', pct(k.dmarc_pass, k.messages), k.dmarc_pass < k.messages ? 'warning' : 'ok'],
			['InboxDmarcKpiDkim', pct(k.dkim_aligned, k.messages)],
			['InboxDmarcKpiSpf', pct(k.spf_aligned, k.messages)],
			['InboxDmarcKpiQuarantine', num(k.quarantine)],
			['InboxDmarcKpiReject', num(k.reject)],
		];
		return '<div class="ibx-dmarc__kpis">' + items.map(([key, value, tone]) => '<div class="ibx-dmarc__kpi' + (tone ? ' is-' + tone : '') + '"><span class="ibx-dmarc__kpival">' + esc(value) + '</span><span class="ibx-muted">' + th(key) + '</span></div>').join('') + '</div>';
	}

	function filtersHtml(state) {
		return '<div class="ibx-dmarc__filters"><input type="search" class="ibx-field ibx-js-dmarcq" placeholder="' + th('InboxDmarcSearch') + '" aria-label="' + th('InboxDmarcSearch') + '" value="' + esc(state.query) + '">'
			+ '<label class="ibx-check"><input type="checkbox" class="ibx-js-dmarcfail"' + (state.failuresOnly ? ' checked' : '') + '> ' + th('InboxDmarcFailuresOnly') + '</label></div>';
	}

	function matches(row, state) {
		if (state.failuresOnly && row.dmarc !== 'fail') {
			return false;
		}
		if (!state.query) {
			return true;
		}
		const hay = [row.ip, state.names[row.ip] || '', row.header_from, row.envelope_from, ...row.dkim.map(k => k.domain + ' ' + k.selector), ...row.spf.map(s => s.domain)].join(' ').toLowerCase();
		return hay.includes(state.query);
	}

	function resultChip(pass) {
		return '<span class="ibx-status ibx-status--' + (pass ? 'ok' : 'error') + '">' + th(pass ? 'InboxDmarcPass' : 'InboxDmarcFail') + '</span>';
	}

	function nameCell(ip, state) {
		if (!ip) {
			return '';
		}
		if (state.names[ip] !== undefined) {
			return state.names[ip] ? esc(state.names[ip]) : '<span class="ibx-muted">' + th('InboxDmarcNoName') + '</span>';
		}
		return '<button type="button" class="ibx-btn ibx-btn--sm ibx-btn--ghost ibx-js-dmarcptr" data-ip="' + esc(ip) + '">' + th('InboxDmarcShowName') + '</button>';
	}

	function causesText(causes) {
		return causes.map(c => t('InboxDmarcCause_' + c)).join(', ');
	}

	function sourcesHtml(r, state) {
		const by = new Map();
		r.rows.filter(row => matches(row, state)).forEach(row => {
			const key = row.ip || '?';
			const s = by.get(key) || { ip: row.ip, count: 0, pass: 0, own: false, ownFailure: false, signers: new Set(), causes: new Set() };
			s.count += row.count;
			s.pass += row.dmarc === 'pass' ? row.count : 0;
			s.own = s.own || row.own;
			s.ownFailure = s.ownFailure || row.own_failure;
			row.dkim.forEach(k => k.domain && s.signers.add(k.domain));
			row.causes.forEach(c => s.causes.add(c));
			by.set(key, s);
		});
		const list = [...by.values()].sort((a, b) => (b.ownFailure - a.ownFailure) || ((b.count - b.pass) - (a.count - a.pass)) || (b.count - a.count));
		if (!list.length) {
			return '<p class="ibx-muted">' + th('InboxDmarcNoRow') + '</p>';
		}
		return '<div class="ibx-tablewrap"><table class="ibx-table ibx-dmarc__table"><thead><tr><th>' + th('InboxDmarcColIp') + '</th><th>' + th('InboxDmarcColName') + '</th><th class="ibx-num">' + th('InboxDmarcColMessages') + '</th>'
			+ '<th>' + th('InboxDmarcColResult') + '</th><th>' + th('InboxDmarcColSigners') + '</th><th>' + th('InboxDmarcColCause') + '</th></tr></thead><tbody>'
			+ list.slice(0, MAX_SHOWN).map(s => '<tr' + (s.ownFailure ? ' class="is-own-failure"' : '') + '><td><code>' + esc(s.ip || '?') + '</code>' + (s.own ? ' <span class="ibx-badge">' + th('InboxDmarcOurs') + '</span>' : '') + '</td>'
				+ '<td>' + nameCell(s.ip, state) + '</td><td class="ibx-num">' + esc(num(s.count)) + '</td>'
				+ '<td>' + resultChip(s.pass === s.count) + (s.pass && s.pass < s.count ? ' <span class="ibx-muted">' + esc(pct(s.pass, s.count)) + '</span>' : '') + '</td>'
				+ '<td>' + esc([...s.signers].join(', ')) + '</td><td>' + esc(causesText([...s.causes])) + '</td></tr>').join('')
			+ '</tbody></table></div>' + cutNote(list.length, r);
	}

	function recordsHtml(r, state) {
		const rows = r.rows.filter(row => matches(row, state));
		if (!rows.length) {
			return '<p class="ibx-muted">' + th('InboxDmarcNoRow') + '</p>';
		}
		return '<div class="ibx-tablewrap"><table class="ibx-table ibx-dmarc__table"><thead><tr><th>' + th('InboxDmarcColIp') + '</th><th class="ibx-num">' + th('InboxDmarcColMessages') + '</th>'
			+ '<th>' + th('InboxDmarcColResult') + '</th><th>' + th('InboxDmarcColDisposition') + '</th><th>' + th('InboxDmarcColFrom') + '</th><th>' + th('InboxDmarcColDkim') + '</th><th>' + th('InboxDmarcColSpf') + '</th></tr></thead><tbody>'
			+ rows.slice(0, MAX_SHOWN).map(row => '<tr><td><code>' + esc(row.ip || '?') + '</code></td><td class="ibx-num">' + esc(num(row.count)) + '</td>'
				+ '<td>' + resultChip(row.dmarc === 'pass') + (row.causes.length ? '<div class="ibx-muted">' + esc(causesText(row.causes)) + '</div>' : '') + '</td>'
				+ '<td>' + (row.disposition && row.disposition !== '?' ? th('InboxDmarcDisposition_' + row.disposition) : '?') + '</td>'
				+ '<td>' + esc(row.header_from || '?') + (row.envelope_from && row.envelope_from !== row.header_from ? '<div class="ibx-muted">' + esc(t('InboxDmarcEnvelope', row.envelope_from)) + '</div>' : '') + '</td>'
				+ '<td>' + authList(row.dkim, k => k.domain + (k.selector ? ' (' + k.selector + ')' : ''), row.dkim_aligned) + '</td>'
				+ '<td>' + authList(row.spf, s => s.domain + (s.scope === 'helo' ? ' (helo)' : ''), row.spf_aligned) + '</td></tr>').join('')
			+ '</tbody></table></div>' + cutNote(rows.length, r);
	}

	function authList(items, label, alignedResult) {
		if (!items.length) {
			return '<span class="ibx-muted">' + th('InboxDmarcNone') + '</span>';
		}
		return items.map(i => '<div><span class="ibx-status ibx-status--' + (i.result === 'pass' ? 'ok' : 'error') + '">' + esc(i.result) + '</span> ' + esc(label(i)) + '</div>').join('')
			+ '<div class="ibx-muted">' + th(alignedResult === 'pass' ? 'InboxDmarcAligned' : 'InboxDmarcNotAligned') + '</div>';
	}

	function cutNote(count, r) {
		return count > MAX_SHOWN || r.rows_cut ? '<p class="ibx-muted">' + th('InboxDmarcRowsCut', num(Math.min(count, MAX_SHOWN))) + '</p>' : '';
	}

	// ---------- Names of the IP and DNS ----------

	async function lookupName(ip, state, paint) {
		try {
			const r = await api('dmarc', 'ptr', { ip });
			state.names[ip] = r.name;
		} catch (err) {
			toast(err.message, { error: true });
			return;
		}
		paint();
	}

	async function runDns(domain, selectors, state, paint) {
		state.dns = 'loading';
		paint();
		try {
			state.dns = await api('dmarc', 'dns', { domain, selectors: selectors.join(',') });
		} catch (err) {
			state.dns = null;
			toast(err.message, { error: true });
		}
		paint();
	}

	function checksHtml(checks) {
		return '<ul class="ibx-dmarc__checks">' + checks.map(([level, key, args]) => {
			const tone = level === 'ok' ? 'ok' : (level === 'ko' ? 'error' : (level === 'warn' ? 'warning' : ''));
			return '<li><span class="ibx-status' + (tone ? ' ibx-status--' + tone : '') + '"></span>' + esc(t('InboxDmarcCheck_' + key, ...(args || []))) + '</li>';
		}).join('') + '</ul>';
	}

	function recordBlock(title, res) {
		return '<section class="ibx-dmarc__dns"><h3>' + esc(title) + ' <code class="ibx-muted">' + esc(res.name) + '</code></h3>'
			+ res.records.map(rec => '<pre class="ibx-dmarc__record">' + esc(rec) + '</pre>').join('') + checksHtml(res.checks) + '</section>';
	}

	function dnsHtml(state, domain, selectors) {
		let out = '<p class="ibx-muted">' + th('InboxDmarcDnsNote') + '</p>';
		if (!domain) {
			return out;
		}
		if (state.dns === 'loading') {
			return out + '<p class="ibx-muted">' + th('InboxDmarcLoading') + '</p>';
		}
		if (!state.dns) {
			return out + '<button type="button" class="ibx-btn ibx-btn--primary ibx-js-dmarcdns">' + th('InboxDmarcDnsRun', domain) + '</button>';
		}
		const d = state.dns;
		out += recordBlock(t('InboxDmarcDnsDmarc'), d.dmarc) + recordBlock(t('InboxDmarcDnsSpf'), d.spf);
		if (!d.dkim.length) {
			out += '<section class="ibx-dmarc__dns"><h3>' + th('InboxDmarcDnsDkimTitle') + '</h3><p class="ibx-muted">' + th('InboxDmarcDnsNoSelector') + '</p></section>';
		}
		d.dkim.forEach(k => {
			out += '<section class="ibx-dmarc__dns"><h3>' + esc(t('InboxDmarcDnsDkim', k.selector)) + ' <code class="ibx-muted">' + esc(k.name) + '</code></h3>'
				+ (k.bits ? '<p class="ibx-muted">' + esc(t('InboxDmarcDnsKey', k.type || 'rsa', k.bits)) + '</p>' : '') + checksHtml(k.checks) + '</section>';
		});
		return out + '<button type="button" class="ibx-btn ibx-btn--sm ibx-js-dmarcdns">' + th('InboxDmarcDnsAgain') + '</button>';
	}
})();
