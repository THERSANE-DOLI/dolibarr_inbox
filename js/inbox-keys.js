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
 * Protected mailboxes (1.89.0, analyse-boites-protegees §3 and §5): the key of this browser and the encrypted copies of
 * the passwords, as Dolibarr.tools.inbox.protect.
 *
 * - Key of the device: ECDH P-256 made by WebCrypto, private part NOT extractable, kept in IndexedDB (one per user of
 *   this Dolibarr). Made only when the user has a protected mailbox (or protects one): a private window would else
 *   leave a device behind at each opening.
 * - Copy of a password for a device: ephemeral ECDH key, HKDF-SHA256, AES-GCM 256, associated data
 *   entity | mailbox | user | device | version. The server keeps it and cannot open it.
 * - Unlocking: at the opening of inbox, the copy of this device is opened and the password given to the server for
 *   the session, without a word. Without a copy: the password is typed, or another device of the user approves.
 * Never: the password in the local storage, an encryption for a device that nobody saw.
 */
(function () {
	'use strict';

	const I = Dolibarr.tools.inbox;
	const { esc, t, th, ic, api, post, toast, dialog, $ } = I;
	const ctx = (k, f) => Dolibarr.getContextVar(k, f);
	const subtle = window.crypto && window.crypto.subtle;
	const enc = new TextEncoder();
	const dec = new TextDecoder();
	const VERSION = 1;
	const CURVE = { name: 'ECDH', namedCurve: 'P-256' };

	const b64u = buf => {
		let s = '';
		new Uint8Array(buf).forEach(b => {
			s += String.fromCharCode(b);
		});
		return btoa(s).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
	};
	const unb64u = str => {
		let s = String(str).replace(/-/g, '+').replace(/_/g, '/');
		while (s.length % 4) {
			s += '=';
		}
		return Uint8Array.from(atob(s), c => c.charCodeAt(0));
	};

	/** WebCrypto and IndexedDB: absent on plain HTTP (not a secure context) and in some old browsers */
	function supported() {
		return !!(subtle && window.indexedDB && window.isSecureContext !== false);
	}

	// ---------- IndexedDB ----------
	function idb() {
		return new Promise((resolve, reject) => {
			const r = indexedDB.open('inbox-keys', 1);
			r.onupgradeneeded = () => r.result.createObjectStore('keys');
			r.onsuccess = () => resolve(r.result);
			r.onerror = () => reject(r.error);
		});
	}
	async function idbDo(mode, fn) {
		const db = await idb();
		return new Promise((resolve, reject) => {
			const tx = db.transaction('keys', mode);
			const req = fn(tx.objectStore('keys'));
			tx.oncomplete = () => {
				db.close();
				resolve(req ? req.result : undefined);
			};
			tx.onerror = () => {
				db.close();
				reject(tx.error);
			};
		});
	}
	/** One key per user of this Dolibarr (two users on the same browser: two keys) */
	const slot = () => ctx('INBOX_URL', location.pathname) + '|' + ctx('INBOX_USER_ID', 0);

	// ---------- Cryptography ----------
	async function makeKey() {
		const kp = await subtle.generateKey(CURVE, false, ['deriveBits']);
		const jwk = await subtle.exportKey('jwk', kp.publicKey);
		return { priv: kp.privateKey, pub: { kty: 'EC', crv: 'P-256', x: jwk.x, y: jwk.y } };
	}
	function importPublic(jwk) {
		return subtle.importKey('jwk', { kty: 'EC', crv: 'P-256', x: jwk.x, y: jwk.y, ext: true }, CURVE, false, []);
	}
	async function aesKey(priv, pub, aad) {
		const bits = await subtle.deriveBits({ name: 'ECDH', public: pub }, priv, 256);
		const hk = await subtle.importKey('raw', bits, 'HKDF', false, ['deriveKey']);
		return subtle.deriveKey({ name: 'HKDF', hash: 'SHA-256', salt: new Uint8Array(32), info: enc.encode('inbox-protect|' + aad) }, hk, { name: 'AES-GCM', length: 256 }, false, ['encrypt', 'decrypt']);
	}
	/** Same fingerprint as the server (InboxProtect::fingerprint): SHA-256 of 0x04 | x | y, 16 hexadecimal characters */
	async function fingerprint(jwk) {
		const x = unb64u(jwk.x);
		const y = unb64u(jwk.y);
		const raw = new Uint8Array(65);
		raw[0] = 4;
		raw.set(x, 1);
		raw.set(y, 33);
		const hex = Array.from(new Uint8Array(await subtle.digest('SHA-256', raw))).map(b => b.toString(16).padStart(2, '0')).join('').slice(0, 16).toUpperCase();
		return hex.match(/.{4}/g).join('-');
	}
	/** Associated data of a copy: it does not open on another line (mailbox, user, device) */
	const aad = (accountId, userId, deviceId) => [ctx('INBOX_ENTITY', 1), accountId, userId, deviceId, VERSION].join('|');

	/**
	 * Encrypt the passwords for the public key of a device
	 * @param {object} pub     JWK of the device
	 * @param {string} data    Associated data
	 * @param {object} secrets {imap, smtp}
	 */
	async function wrap(pub, data, secrets) {
		const eph = await subtle.generateKey(CURVE, true, ['deriveBits']);
		const key = await aesKey(eph.privateKey, await importPublic(pub), data);
		const iv = crypto.getRandomValues(new Uint8Array(12));
		const plain = { imap: String(secrets.imap || '') };
		if (secrets.smtp) {
			plain.smtp = String(secrets.smtp);
		}
		const ct = await subtle.encrypt({ name: 'AES-GCM', iv, additionalData: enc.encode(data) }, key, enc.encode(JSON.stringify(plain)));
		const epk = await subtle.exportKey('jwk', eph.publicKey);
		return { v: VERSION, epk: { kty: 'EC', crv: 'P-256', x: epk.x, y: epk.y }, iv: b64u(iv), ct: b64u(ct) };
	}
	/**
	 * A copy for another device, only for the key whose fingerprint was shown: a key changed behind the fingerprint
	 * (written in the database) gets nothing (audit INBX-063)
	 * @param {object} pub    Public key of the device
	 * @param {string} shown  Fingerprint shown to the user
	 * @param {string} data   Associated data
	 * @param {object} secrets Passwords
	 * @returns {Promise<object>} Copy
	 */
	async function wrapFor(pub, shown, data, secrets) {
		if (!shown || await fingerprint(pub) !== shown) {
			throw new Error(t('InboxProtectFingerprintMismatch'));
		}
		return wrap(pub, data, secrets);
	}
	/** Open a copy with the private key of this device */
	async function unwrap(blob, data) {
		const b = typeof blob === 'string' ? JSON.parse(blob) : blob;
		const key = await aesKey(me.priv, await importPublic(b.epk), data);
		const plain = await subtle.decrypt({ name: 'AES-GCM', iv: unb64u(b.iv), additionalData: enc.encode(data) }, key, unb64u(b.ct));
		return JSON.parse(dec.decode(plain));
	}

	// ---------- Device ----------
	/** Browser and system, for the label of the device (can be changed) */
	function deviceLabel() {
		const ua = navigator.userAgent;
		const browser = /Edg\//.test(ua) ? 'Edge' : /OPR\//.test(ua) ? 'Opera' : /Firefox\//.test(ua) ? 'Firefox' : /Chrome\//.test(ua) ? 'Chrome' : /Safari\//.test(ua) ? 'Safari' : 'Browser';
		const os = /Android/.test(ua) ? 'Android' : /iPhone|iPad/.test(ua) ? 'iOS' : /Windows/.test(ua) ? 'Windows' : /Mac OS X/.test(ua) ? 'macOS' : /Linux/.test(ua) ? 'Linux' : '';
		return browser + (os ? ' / ' + os : '');
	}

	let me = null;
	let ensuring = null;
	/**
	 * The key of this browser, made and recorded on the server when missing (a revoked key is replaced)
	 * @returns {Promise<object|null>} {priv, pub, deviceId, fingerprint}, null when the browser cannot
	 */
	function ensure() {
		if (me) {
			return Promise.resolve(me);
		}
		if (!supported()) {
			return Promise.resolve(null);
		}
		ensuring = ensuring || (async () => {
			let rec = null;
			try {
				rec = await idbDo('readonly', s => s.get(slot()));
			} catch (e) {
				rec = null;
			}
			const register = async r => {
				const res = await post('protect', 'register', { public_key: r.pub, label: r.label || deviceLabel() });
				r.deviceId = res.device.id;
				r.fingerprint = res.device.fingerprint;
				r.label = res.device.label;
				return r;
			};
			if (!rec || !rec.priv) {
				rec = await makeKey();
			}
			try {
				rec = await register(rec);
			} catch (err) {
				if (err.status !== 409) {
					throw err;
				}
				// Key revoked: never taken again, a new one
				rec = await register(await makeKey());
			}
			await idbDo('readwrite', s => s.put(rec, slot()));
			me = rec;
			return me;
		})().finally(() => {
			ensuring = null;
		});
		return ensuring;
	}

	// ---------- State and unlocking ----------
	/** Protected mailboxes of the user: {id, label, email, unlocked, blob, smtp_own, failed, error} */
	let boxes = [];
	const listeners = new Set();
	function emit() {
		listeners.forEach(fn => {
			try {
				fn(boxes);
			} catch (e) {
				// a listener failing does not stop the others
			}
		});
		Dolibarr.executeHook('inbox:protect:state', { boxes });
	}

	/**
	 * Read the state, open the copies of this device and unlock in silence (§5.2)
	 * @returns {Promise<object[]>}
	 */
	async function refresh() {
		let res = await api('protect', 'state', { device: me ? me.deviceId : 0 });
		if (!res.accounts.length) {
			boxes = [];
			emit();
			return boxes;
		}
		if (!me) {
			try {
				await ensure();
			} catch (err) {
				toast(err.message, { error: true });
			}
			if (me) {
				res = await api('protect', 'state', { device: me.deviceId });
			}
		}
		boxes = res.accounts;
		for (const b of boxes) {
			if (b.unlocked || !b.blob || !me || b.failed) {
				continue;
			}
			try {
				const s = await unwrap(b.blob, aad(b.id, ctx('INBOX_USER_ID', 0), me.deviceId));
				await post('protect', 'unlock', { id: b.id, device: me.deviceId, imap_password: s.imap, smtp_password: s.smtp || '' });
				b.unlocked = true;
			} catch (err) {
				// auth: password changed at the host (typed again); else server unreachable, copy unreadable
				b.error = err.code === 'auth' ? 'auth' : (err.name === 'OperationError' ? 'copy' : 'server');
				b.failed = err.code === 'auth';
				b.message = err.message;
			}
		}
		emit();
		checkApprovals();
		checkPending();
		return boxes;
	}

	// ---------- Sendings left on the server (lot S3, §7.1 points 5 and 6, §7.2) ----------
	let pendingTimer = 0;
	const timers = new Map();
	const asked = new Set();
	/**
	 * After the unlocking, and every 10 minutes: the failures told once, the copies left in Drafts by a page gone
	 * during its delay (offered, never sent alone), the sendings scheduled: sent at their date by the leader tab of
	 * this browser; late, sent at once when asked so, else the author is asked
	 */
	async function checkPending() {
		clearTimeout(pendingTimer);
		if (!boxes.some(b => b.unlocked)) {
			return;
		}
		pendingTimer = setTimeout(checkPending, 600000);
		let res;
		try {
			res = await post('protect', 'pending', { take: 1 });
		} catch (e) {
			return;
		}
		(res.notices || []).forEach(n => {
			dialog({
				title: t('InboxProtectNotSentTitle'),
				body: '<p>' + th('InboxProtectNotSentText', n.mailbox, new Date(n.date * 1000).toLocaleString(I.locale())) + '</p>',
				actions: n.folder ? [{ label: t('InboxProtectSeeDrafts'), primary: true, run: () => I.go('#/mail/' + n.folder) }] : [],
			});
		});
		const skew = Date.now() / 1000 - res.now;
		(res.drafts || []).forEach(d => {
			if (d.kind === 'pending') {
				offer(d, t('InboxProtectPendingText', d.mailbox, d.subject || t('InboxNoSubject')));
				return;
			}
			const due = d.at + skew;
			const late = Date.now() / 1000 - due;
			if (late < 120) {
				// On time (or soon): sent at the date by one tab of this browser, by any user who may send from it
				if (!timers.has(d.id) && late > -86400) {
					timers.set(d.id, setTimeout(() => {
						timers.delete(d.id);
						if (!I.isLeader || I.isLeader()) {
							sendDraft(d, true);
						}
					}, Math.max(0, -late) * 1000));
				}
				return;
			}
			if (d.catchup === 'send') {
				if (!I.isLeader || I.isLeader()) {
					sendDraft(d, true);
				}
			} else if (d.mine) {
				const days = Math.floor(late / 86400);
				offer(d, t('InboxProtectLateText', d.subject || t('InboxNoSubject'), new Date(due * 1000).toLocaleString(I.locale()), days > 0 ? t('InboxProtectLateDays', days) : t('InboxProtectLateHours', Math.max(1, Math.round(late / 3600)))));
			}
		});
	}
	async function sendDraft(d, quiet) {
		try {
			const r = await post('protect', 'senddraft', { id: d.id });
			toast(r.already ? t('InboxProtectAlreadySent', d.subject || t('InboxNoSubject')) : t('InboxProtectDraftSent', d.subject || t('InboxNoSubject')));
		} catch (err) {
			if (!quiet || err.code !== 'not_found') {
				toast(err.message, { error: true });
			}
		}
	}
	/** « Send now / Edit / Delete » for a mail left in Drafts (asked once per page) */
	function offer(d, text) {
		if (asked.has(d.id)) {
			return;
		}
		asked.add(d.id);
		dialog({
			title: t('InboxProtectPendingTitle'),
			body: '<p>' + esc(text) + '</p>',
			actions: [
				{ label: t('InboxDelete'), danger: true, run: async () => {
					await post('action', 'run', { what: 'delete', ids: [d.id] });
				} },
				{ label: t('InboxEditDraft'), run: () => {
					if (I.compose) {
						I.compose({ mode: 'draft', mail: d.id });
					}
				} },
				{ label: t('InboxSendNow'), primary: true, run: () => sendDraft(d, false) },
			],
		});
	}

	const ready = new Promise(resolve => {
		Dolibarr.on('inbox:app:ready', () => {
			refresh().catch(() => []).then(resolve);
		});
	});

	function byId(id) {
		return boxes.find(b => b.id === +id) || null;
	}

	/**
	 * Lock a mailbox now (button « Lock now »)
	 * @param {number} id Mailbox
	 */
	async function lock(id) {
		await post('protect', 'lock', { id });
		const b = byId(id);
		if (b) {
			b.unlocked = false;
		}
		emit();
	}

	/** Fields of the passwords of a mailbox (the SMTP one when its server has its own) */
	function passwordFields(smtpOwn, prefix) {
		return '<div class="ibx-formrow"><label class="ibx-formrow__label" for="' + prefix + '-imap">' + th('InboxProtectPasswordImap') + '</label>'
			+ '<div><input type="password" class="ibx-field" id="' + prefix + '-imap" autocomplete="off" spellcheck="false"></div></div>'
			+ (smtpOwn ? '<div class="ibx-formrow"><label class="ibx-formrow__label" for="' + prefix + '-smtp">' + th('InboxProtectPasswordSmtp') + '</label>'
				+ '<div><input type="password" class="ibx-field" id="' + prefix + '-smtp" autocomplete="off" spellcheck="false"></div></div>' : '');
	}
	function readPasswords(dlg, prefix) {
		const imap = $('#' + prefix + '-imap', dlg).value;
		const smtpField = $('#' + prefix + '-smtp', dlg);
		return { imap, smtp: smtpField ? smtpField.value : '' };
	}

	/**
	 * A device without copy (or a copy refused by the mail server): type the password of the mailbox, or ask another
	 * device of the user to approve this one (§5.2, §5.3)
	 * @param {number} id Mailbox
	 * @returns {Promise<boolean>} unlocked
	 */
	function openLock(id) {
		return new Promise(resolve => {
			const b = byId(id);
			if (!b) {
				resolve(false);
				return;
			}
			if (!supported()) {
				dialog({ title: t('InboxProtectLockedTitle', b.label), body: '<p>' + th('InboxProtectUnsupported') + '</p>' });
				resolve(false);
				return;
			}
			let done = false;
			const reason = b.error === 'auth' || b.failed ? '<div class="ibx-notice ibx-notice--warning" role="status">' + ic('warning') + '<div class="ibx-notice__text">' + th('InboxProtectPasswordChanged') + '</div></div>'
				: (b.error === 'server' ? '<div class="ibx-notice ibx-notice--error" role="alert">' + ic('warning') + '<div class="ibx-notice__text">' + esc(b.message || '') + '</div></div>' : '');
			const dlg = dialog({
				title: t('InboxProtectLockedTitle', b.label),
				body: reason + '<p>' + th('InboxProtectLockedHelp') + '</p>' + passwordFields(!!b.smtp_own, 'ibx-unlock')
					+ '<p class="ibx-section__help">' + th('InboxProtectOrApprove') + ' <button type="button" class="ibx-btn ibx-btn--sm ibx-js-ask">' + th('InboxProtectAskApproval') + '</button></p>',
				actions: [{ label: t('InboxProtectUnlock'), primary: true, run: async d => {
					const secrets = readPasswords(d, 'ibx-unlock');
					if (!secrets.imap) {
						$('#ibx-unlock-imap', d).focus();
						return false;
					}
					await ensure();
					const blob = await wrap(me.pub, aad(b.id, ctx('INBOX_USER_ID', 0), me.deviceId), secrets);
					await post('protect', 'enrol', { id: b.id, device: me.deviceId, imap_password: secrets.imap, smtp_password: secrets.smtp, blob });
					b.unlocked = true;
					b.failed = false;
					b.error = '';
					done = true;
					emit();
					toast(t('InboxProtectUnlocked', b.label));
				} }],
				onOpen: d => {
					$('#ibx-unlock-imap', d).focus();
					$('.ibx-js-ask', d).addEventListener('click', () => {
						d.close();
						askApproval(b).then(resolve);
					});
				},
			});
			dlg.addEventListener('close', () => {
				if (done) {
					resolve(true);
				}
			});
		});
	}

	/**
	 * This device asks another device of the user to approve it: its fingerprint is shown, to compare (§5.3). Waits
	 * until a copy arrives, or the window is closed.
	 * @param {object} b Mailbox
	 * @returns {Promise<boolean>}
	 */
	async function askApproval(b) {
		const mine = await ensure();
		if (!mine) {
			return false;
		}
		await post('protect', 'ask', { device: mine.deviceId, ask: 1 });
		return new Promise(resolve => {
			let timer = 0;
			let done = false;
			const dlg = dialog({
				title: t('InboxProtectApproveWaitTitle'),
				body: '<p>' + th('InboxProtectApproveWaitHelp') + '</p><p class="ibx-protect__fp" aria-label="' + th('InboxProtectFingerprint') + '">' + esc(mine.fingerprint) + '</p>'
					+ '<p class="ibx-muted" role="status" aria-live="polite">' + th('InboxProtectApproveWaiting') + '</p>',
			});
			const poll = async () => {
				try {
					await refresh();
				} catch (e) {
					// try again at the next round
				}
				const now = byId(b.id);
				if (now && now.unlocked) {
					done = true;
					dlg.close();
					toast(t('InboxProtectUnlocked', b.label));
					return;
				}
				timer = setTimeout(poll, 4000);
			};
			timer = setTimeout(poll, 4000);
			dlg.addEventListener('close', () => {
				clearTimeout(timer);
				if (!done) {
					post('protect', 'ask', { device: mine.deviceId, ask: 0 }).catch(() => null);
				}
				resolve(done);
			});
		});
	}

	// ---------- Approval of a new device of the user (§5.3) ----------
	let approvalShown = new Set();
	let approvalTimer = 0;
	/**
	 * Another device of the user asks to be approved: its label and fingerprint, to compare with the one it shows.
	 * Checked at the opening and every 30 s while the user has protected mailboxes.
	 */
	async function checkApprovals() {
		clearTimeout(approvalTimer);
		if (!me || !boxes.length) {
			return;
		}
		approvalTimer = setTimeout(checkApprovals, 30000);
		let res;
		try {
			res = await api('protect', 'devices', { device: me.deviceId });
		} catch (e) {
			return;
		}
		const asking = res.devices.filter(d => d.id !== me.deviceId && d.missing.length && !approvalShown.has(d.id));
		asking.forEach(d => {
			approvalShown.add(d.id);
			showApproval(d);
		});
	}
	function showApproval(d) {
		dialog({
			title: t('InboxProtectApproveTitle'),
			body: '<p>' + th('InboxProtectApproveHelp', d.label || '-') + '</p><p class="ibx-protect__fp">' + esc(d.fingerprint) + '</p>'
				+ '<p>' + th('InboxProtectApproveBoxes') + ' ' + d.missing.map(m => '<b>' + esc(m.label) + '</b>').join(', ') + '</p>'
				+ '<p class="ibx-section__help">' + th('InboxProtectApproveWarning') + '</p>',
			actions: [
				{ label: t('InboxProtectNotMe'), danger: true, run: async () => {
					await post('protect', 'revoke', { device: d.id });
					toast(t('InboxProtectDeviceRevoked'));
				} },
				{ label: t('InboxProtectApprove'), primary: true, run: async () => {
					await approve(d);
				} },
			],
		});
	}
	/** Encrypt every protected mailbox of the user that this device can open, for the new device */
	async function approve(d) {
		const copies = [];
		for (const m of d.missing) {
			const b = byId(m.id);
			if (!b || !b.blob) {
				continue;
			}
			const s = await unwrap(b.blob, aad(b.id, ctx('INBOX_USER_ID', 0), me.deviceId));
			copies.push({ id: b.id, blob: await wrapFor(d.public_key, d.fingerprint, aad(b.id, ctx('INBOX_USER_ID', 0), d.id), s) });
		}
		if (!copies.length) {
			throw new Error(t('InboxProtectNothingToApprove'));
		}
		const res = await post('protect', 'approve', { target: d.id, copies });
		toast(t('InboxProtectApproved', res.approved));
	}

	/**
	 * Give the access of an unlocked mailbox to devices of colleagues, after their fingerprints were shown (§5.4)
	 * @param {number}   id      Mailbox
	 * @param {object[]} targets [{fk_user, device, public_key, fingerprint}]
	 * @returns {Promise<number>} copies made
	 */
	async function grant(id, targets) {
		const b = byId(id);
		if (!b || !b.blob || !me) {
			throw new Error(t('InboxProtectNeedUnlocked'));
		}
		const s = await unwrap(b.blob, aad(b.id, ctx('INBOX_USER_ID', 0), me.deviceId));
		let n = 0;
		for (const x of targets) {
			await post('protect', 'grant', { id, fk_user: x.fk_user, device: x.device, blob: await wrapFor(x.public_key, x.fingerprint, aad(id, x.fk_user, x.device), s) });
			n++;
		}
		return n;
	}

	/**
	 * Protect a mailbox (§5.1): the passwords typed, the copy for this device, the address typed again
	 * @param {object} account   Mailbox {id, email}
	 * @param {object} secrets   {imap, smtp}
	 * @param {string} confirm   Address typed again
	 */
	async function protect(account, secrets, confirm, level) {
		const mine = await ensure();
		if (!mine) {
			throw new Error(t('InboxProtectUnsupported'));
		}
		const blob = await wrap(mine.pub, aad(account.id, ctx('INBOX_USER_ID', 0), mine.deviceId), secrets);
		const res = await post('protect', 'protect', { id: account.id, device: mine.deviceId, imap_password: secrets.imap, smtp_password: secrets.smtp || '', blob, confirm_email: confirm, level: level || 'password' });
		await refresh();
		return res.account;
	}

	/**
	 * What the other scripts of the page know of this device: never its private key (audit INBX-056, a script of the
	 * page could open the copies with it)
	 * @param {object|null} rec Key of this device
	 * @returns {object|null} {deviceId, fingerprint, label}
	 */
	const publicView = rec => (rec ? { deviceId: rec.deviceId, fingerprint: rec.fingerprint, label: rec.label } : null);

	I.protect = {
		supported, refresh, ready, lock, openLock, askApproval, grant, protect, wrap, fingerprint, passwordFields, readPasswords,
		/** @returns {Promise<object|null>} this device, recorded on the server when missing */
		ensure: () => ensure().then(publicView),
		/** @returns {object|null} this device */
		device: () => publicView(me),
		/** @returns {object[]} protected mailboxes of the user */
		boxes: () => boxes,
		byId,
		/** @param {number} id Mailbox @returns {boolean} protected and not unlocked in this session */
		isLocked: id => {
			const b = byId(id);
			return !!b && !b.unlocked;
		},
		onChange: fn => listeners.add(fn),
	};
})();
