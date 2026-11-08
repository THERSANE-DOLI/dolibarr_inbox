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
 * Invitations and read receipts of the mail read (lot W8 1.22.0), above the text of the mail:
 * - card of an invitation: when, where, organizer, attendees, conflicts with the agenda; Accept / Maybe / Decline
 *   (answer to the organizer unless unchecked, event in the agenda of Dolibarr if checked); update and cancel received;
 * - read receipt asked: « Send the receipt » / « Ignore », never automatic.
 * Called by js/inbox-mail.js (I.mailExtrasHtml, I.bindMailExtras).
 */
(function () {
	'use strict';

	const I = Dolibarr.tools.inbox;
	const { esc, t, th, ic, post, toast, $, $$ } = I;
	const locale = () => (I.ctx('MAIN_LANG_DEFAULT', 'en_US') || 'en_US').replace('_', '-');
	const PARTSTATS = ['ACCEPTED', 'TENTATIVE', 'DECLINED'];

	function fmtDay(ts, utc) {
		return new Date(ts * 1000).toLocaleDateString(locale(), { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric', timeZone: utc ? 'UTC' : undefined });
	}
	function fmtTime(ts, zone) {
		return new Date(ts * 1000).toLocaleTimeString(locale(), { hour: '2-digit', minute: '2-digit', timeZone: zone || undefined });
	}

	/** When, in the time zone of the user; the hour of the organizer added when its zone is another one */
	function whenHtml(inv) {
		if (inv.allday) {
			const last = Math.max(inv.start, inv.end - 86400);
			return esc(fmtDay(inv.start, true) + (last > inv.start ? ' - ' + fmtDay(last, true) : '')) + ' <span class="ibx-muted">(' + th('InboxInviteAllDay') + ')</span>';
		}
		const sameDay = new Date(inv.start * 1000).toDateString() === new Date(inv.end * 1000).toDateString();
		let out = esc(fmtDay(inv.start) + ', ' + fmtTime(inv.start) + ' - ' + (sameDay ? '' : fmtDay(inv.end) + ', ') + fmtTime(inv.end));
		let mine = '';
		try {
			mine = Intl.DateTimeFormat().resolvedOptions().timeZone;
		} catch (e) {
			mine = '';
		}
		if (inv.tz && mine && inv.tz !== mine && inv.tz !== 'UTC') {
			try {
				out += ' <span class="ibx-muted">(' + esc(t('InboxInviteTheirTime', fmtTime(inv.start, inv.tz) + ' - ' + fmtTime(inv.end, inv.tz), inv.tz)) + ')</span>';
			} catch (e) {
				// Zone unknown to the browser: the time of the user only
			}
		}
		return out;
	}

	function person(p) {
		return p.name ? esc(p.name) + ' <span class="ibx-muted">&lt;' + esc(p.email) + '&gt;</span>' : esc(p.email);
	}

	function stateChip(partstat) {
		const cls = partstat === 'ACCEPTED' ? 'ok' : (partstat === 'DECLINED' ? 'danger' : 'warning');
		return '<span class="ibx-status ibx-status--' + cls + '">' + th('InboxPartstat_' + (PARTSTATS.includes(partstat) ? partstat : 'NEEDS-ACTION')) + '</span>';
	}

	function cardHtml(m) {
		const inv = m.invite;
		const d = new Date((inv.allday ? inv.start : inv.start) * 1000);
		const tz = inv.allday ? 'UTC' : undefined;
		const month = d.toLocaleDateString(locale(), { month: 'short', timeZone: tz });
		const day = d.toLocaleDateString(locale(), { day: 'numeric', timeZone: tz });
		let html = '<section class="ibx-invite' + (inv.cancelled ? ' is-cancelled' : '') + ' ibx-js-invite" aria-label="' + th('InboxInvite') + '">'
			+ '<div class="ibx-invite__date" aria-hidden="true"><span>' + esc(month) + '</span><b>' + esc(day) + '</b></div><div class="ibx-invite__main">'
			+ '<div class="ibx-invite__kind">' + ic('agenda') + ' ' + th(inv.cancelled ? 'InboxInviteCancelled' : (inv.method === 'REPLY' ? 'InboxInviteAnswer' : (inv.reply && inv.agenda && inv.agenda.outdated ? 'InboxInviteUpdated' : 'InboxInvite'))) + '</div>'
			+ '<div class="ibx-invite__title">' + esc(inv.summary || t('InboxInviteNoTitle')) + '</div>'
			+ '<div class="ibx-invite__line">' + whenHtml(inv) + '</div>'
			+ (inv.rrule ? '<div class="ibx-invite__line ibx-muted">' + esc(inv.rrule) + '</div>' : '')
			+ (inv.location ? '<div class="ibx-invite__line"><span class="ibx-muted">' + th('InboxInviteWhere') + ' :</span> ' + esc(inv.location) + '</div>' : '')
			+ (inv.organizer ? '<div class="ibx-invite__line"><span class="ibx-muted">' + th('InboxInviteOrganizer') + ' :</span> ' + person(inv.organizer) + '</div>' : '');
		if (inv.attendees.length) {
			html += '<details class="ibx-invite__people"><summary>' + th('InboxInviteAttendees', inv.attendees_count) + '</summary><ul>'
				+ inv.attendees.map(a => '<li>' + person(a) + ' ' + stateChip(a.partstat) + '</li>').join('')
				+ (inv.attendees_count > inv.attendees.length ? '<li class="ibx-muted">...</li>' : '') + '</ul></details>';
		}
		if (inv.method === 'REPLY') {
			// Answer of an attendee to an invitation sent from here
			const a = inv.attendees[0];
			if (a) {
				html += '<div class="ibx-invite__line">' + th('InboxInviteTheyAnswered', a.name || a.email) + ' ' + stateChip(a.partstat) + '</div>';
			}
			return html + '</div></section>';
		}
		if (inv.conflicts.length && !inv.cancelled) {
			html += '<div class="ibx-invite__line ibx-invite__conflict">' + ic('warning') + ' ' + th('InboxInviteConflict', inv.conflicts.map(c => c.label).join(', ')) + '</div>';
		}
		if (inv.reply) {
			html += '<div class="ibx-invite__line">' + th('InboxInviteYouAnswered') + ' ' + stateChip(inv.reply.partstat)
				+ ' <span class="ibx-muted">' + esc(new Date(inv.reply.date * 1000).toLocaleString(locale(), { dateStyle: 'medium', timeStyle: 'short' })) + '</span></div>';
		}
		// Agenda of Dolibarr: in it (link), to bring up to date, to mark cancelled, or to add
		let agenda = '';
		if (inv.agenda) {
			agenda += '<a class="ibx-btn ibx-btn--sm ibx-btn--ghost" href="' + esc(inv.agenda.url) + '" target="_blank" rel="noopener">' + ic('agenda') + ' ' + th('InboxInviteInAgenda') + '</a>';
			if (inv.cancelled) {
				agenda += '<button type="button" class="ibx-btn ibx-btn--sm ibx-js-invagenda" data-what="cancel">' + th('InboxInviteAgendaCancel') + '</button>';
			} else if (inv.agenda.outdated) {
				agenda += '<button type="button" class="ibx-btn ibx-btn--sm ibx-btn--primary ibx-js-invagenda" data-what="add">' + th('InboxInviteAgendaUpdate') + '</button>';
			}
		} else if (inv.can_agenda && !inv.cancelled && (inv.method !== 'REQUEST' || (inv.reply && inv.reply.partstat !== 'DECLINED'))) {
			agenda += '<button type="button" class="ibx-btn ibx-btn--sm ibx-js-invagenda" data-what="add">' + ic('event-new') + ' ' + th('InboxInviteAgendaAdd') + '</button>';
		}
		if (inv.can_reply) {
			html += '<div class="ibx-invite__actions" role="group" aria-label="' + th('InboxInviteYourAnswer') + '">'
				+ PARTSTATS.map(p => '<button type="button" class="ibx-btn ibx-btn--sm' + (inv.reply && inv.reply.partstat === p ? ' ibx-btn--primary' : '') + ' ibx-js-invreply" data-partstat="' + p + '"'
					+ (inv.reply && inv.reply.partstat === p ? ' aria-pressed="true"' : '') + '>' + th('InboxInviteDo_' + p) + '</button>').join('')
				+ '</div><div class="ibx-invite__options">'
				+ '<label class="ibx-check"><input type="checkbox" class="ibx-js-invsend" checked> ' + th('InboxInviteSendAnswer') + '</label>'
				+ (inv.can_agenda && !inv.agenda ? '<label class="ibx-check"><input type="checkbox" class="ibx-js-invagendaopt" checked> ' + th('InboxInviteAddToAgenda') + '</label>' : '')
				+ '<input type="text" class="ibx-field ibx-js-invcomment" maxlength="500" autocomplete="off" placeholder="' + th('InboxInviteComment') + '" aria-label="' + th('InboxInviteComment') + '">'
				+ '</div>';
		} else if (inv.method === 'REQUEST' && !inv.cancelled && !inv.can_send) {
			html += '<div class="ibx-invite__line ibx-muted">' + th('InboxInviteReadOnly', m.mailbox) + '</div>';
		}
		if (agenda) {
			html += '<div class="ibx-invite__actions">' + agenda + '</div>';
		}

		return html + '</div></section>';
	}

	function receiptHtml(m) {
		const r = m.receipt;
		if (r.abuse) {
			// RFC 8098: an address other than the return path; only said, no button
			return '<div class="ibx-banner ibx-js-receipt">' + ic('receipt') + '<div class="ibx-banner__text ibx-muted">' + th('InboxReceiptAbuseText', r.to) + '</div>'
				+ '<button type="button" class="ibx-btn ibx-btn--sm ibx-btn--ghost ibx-js-receiptno">' + th('InboxReceiptIgnore') + '</button></div>';
		}
		return '<div class="ibx-banner ibx-banner--info ibx-js-receipt">' + ic('receipt') + '<div class="ibx-banner__text">' + th('InboxReceiptAsked', r.to) + '</div>'
			+ '<button type="button" class="ibx-btn ibx-btn--sm ibx-js-receiptyes">' + th('InboxReceiptSend') + '</button>'
			+ '<button type="button" class="ibx-btn ibx-btn--sm ibx-btn--ghost ibx-js-receiptno">' + th('InboxReceiptIgnore') + '</button></div>';
	}

	/** Banners and card above the text of the mail (not in the mails of a conversation folded inline) */
	I.mailExtrasHtml = (m, opts) => {
		if (opts && opts.inline) {
			return '';
		}
		return (m.invite ? cardHtml(m) : '') + (m.receipt ? receiptHtml(m) : '');
	};

	function repaint(m, root) {
		const card = $('.ibx-js-invite', root);
		if (card) {
			const tmp = document.createElement('div');
			tmp.innerHTML = cardHtml(m);
			card.replaceWith(tmp.firstElementChild);
			bindInvite(m, root);
		}
	}

	function busy(root, on) {
		$$('.ibx-js-invite button', root).forEach(b => {
			if (on) {
				b.setAttribute('aria-disabled', 'true');
			} else {
				b.removeAttribute('aria-disabled');
			}
		});
	}

	function bindInvite(m, root) {
		const card = $('.ibx-js-invite', root);
		if (!card) {
			return;
		}
		$$('.ibx-js-invreply', card).forEach(b => b.addEventListener('click', async () => {
			if (b.getAttribute('aria-disabled') === 'true') {
				return;
			}
			const send = $('.ibx-js-invsend', card);
			const agenda = $('.ibx-js-invagendaopt', card);
			const comment = $('.ibx-js-invcomment', card);
			busy(root, true);
			try {
				const r = await post('invite', 'reply', { mail: m.id, partstat: b.dataset.partstat, send: send && send.checked ? 1 : 0,
					agenda: agenda && agenda.checked ? 1 : 0, comment: comment ? comment.value : '' });
				m.invite.reply = { partstat: b.dataset.partstat, date: Math.floor(Date.now() / 1000), sequence: m.invite.sequence };
				if (r.agenda) {
					m.invite.agenda = { id: r.agenda, url: r.agenda_url, outdated: false };
				}
				toast(t(r.sent ? 'InboxInviteSent' : 'InboxInviteNoted'));
				repaint(m, root);
			} catch (err) {
				busy(root, false);
				toast(err.message, { error: true });
			}
		}));
		$$('.ibx-js-invagenda', card).forEach(b => b.addEventListener('click', async () => {
			if (b.getAttribute('aria-disabled') === 'true') {
				return;
			}
			busy(root, true);
			try {
				const r = await post('invite', 'agenda', { mail: m.id, what: b.dataset.what });
				m.invite.agenda = { id: r.agenda, url: r.agenda_url, outdated: false };
				toast(t(b.dataset.what === 'cancel' ? 'InboxInviteAgendaCancelled' : 'InboxInviteAgendaDone'));
				repaint(m, root);
			} catch (err) {
				busy(root, false);
				toast(err.message, { error: true });
			}
		}));
	}

	function bindReceipt(m, root) {
		const bar = $('.ibx-js-receipt', root);
		if (!bar) {
			return;
		}
		const answer = async send => {
			$$('button', bar).forEach(b => b.setAttribute('aria-disabled', 'true'));
			try {
				await post('invite', 'receipt', { mail: m.id, send: send ? 1 : 0 });
				m.receipt = null;
				bar.remove();
				toast(t(send ? 'InboxReceiptSent' : 'InboxReceiptIgnored'));
			} catch (err) {
				$$('button', bar).forEach(b => b.removeAttribute('aria-disabled'));
				toast(err.message, { error: true });
			}
		};
		const yes = $('.ibx-js-receiptyes', bar);
		if (yes) {
			yes.addEventListener('click', () => answer(true));
		}
		$('.ibx-js-receiptno', bar).addEventListener('click', () => answer(false));
	}

	I.bindMailExtras = (m, root) => {
		bindInvite(m, root);
		bindReceipt(m, root);
	};
})();
