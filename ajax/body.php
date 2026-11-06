<?php
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
 * \file    inbox/ajax/body.php
 * \ingroup inbox
 * \brief   Body of a mail as a whole HTML document for the sandboxed iframe of the reading pane; source of a mail
 *
 * Three barriers: the HTML is cleaned by an allow list (InboxMimeParser::safeHtml), the CSP of this answer forbids
 * any script, form and remote resource (remote pictures only after « Show »), and the iframe has no allow-scripts.
 */

require __DIR__.'/inbox_ajax.inc.php';
dol_include_once('/inbox/class/inboxreader.class.php');

inboxAjaxStart(false);
$reader = new InboxReader($db);

/**
 * Error inside the iframe: short page, same protections
 *
 * @param int    $code    HTTP code
 * @param string $message Message
 * @return void
 */
function inboxBodyFail($code, $message)
{
	http_response_code($code);
	header('Content-Type: text/html; charset=utf-8');
	header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; frame-ancestors 'self'");
	print '<!doctype html><meta charset="utf-8"><body style="font:14px Arial,sans-serif;color:#555;margin:16px">'.dol_escape_htmltag($message).'</body>';
	exit;
}

if (!$reader->load($user, GETPOSTINT('id'))) {
	inboxBodyFail(404, $langs->transnoentities('InboxMailNotFound'));
}
$raw = $reader->raw($user);
if ($raw === null) {
	inboxBodyFail($reader->errorCode === 'gone' ? 410 : 502, $langs->transnoentities($reader->errorCode === 'gone' ? 'InboxMailGone' : 'InboxMailReadError', $reader->error));
}

header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

$part = GETPOST('part', 'aZ09');
if ($part === 'source') {
	// Source of the mail: text only, never interpreted
	header('Content-Type: text/plain; charset=utf-8');
	header("Content-Security-Policy: default-src 'none'; sandbox");
	if (GETPOST('download', 'int')) {
		$name = InboxMimeParser::fileName(($reader->row->subject ?: 'mail').'.eml');
		header('Content-Type: message/rfc822');
		header("Content-Disposition: attachment; filename=\"".str_replace('"', '', $name)."\"; filename*=UTF-8''".rawurlencode($name));
	}
	print $raw;
	exit;
}

$message = InboxMimeParser::parse($raw);
$intro = '';
if ($part !== '' && $part !== 'main') {
	// Mail attached to the mail (.eml, .msg), shown like the mail itself
	$i = (int) $part;
	$a = $message->attachments[$i] ?? null;
	if (!$a || InboxReader::previewKind($a) !== 'mail') {
		inboxBodyFail(404, $langs->transnoentities('InboxMailNotFound'));
	}
	if (InboxMimeParser::kind($a['content']) === 'ole') {
		require_once dirname(__DIR__).'/class/inboxmsgparser.class.php';
		$inner = InboxMsgParser::parse($a['content'], 1);
	} else {
		$inner = InboxMimeParser::parse($a['content'], 1);
	}
	if (!$inner) {
		inboxBodyFail(422, $langs->transnoentities('InboxMailReadError', ''));
	}
	$message = $inner;
	$rows = array(
		array($langs->transnoentities('InboxFrom'), trim($message->fromName.' <'.$message->fromEmail.'>')),
		array($langs->transnoentities('InboxTo'), implode(', ', array_map(function ($a) {
			return trim($a['name'].' <'.$a['email'].'>');
		}, $message->to))),
		array($langs->transnoentities('InboxDate'), $message->date ? dol_print_date($message->date, 'dayhour', 'tzuser') : ''),
		array($langs->transnoentities('InboxSubject'), $message->subject),
	);
	$intro = '<table style="border-collapse:collapse;margin:0 0 12px;font-size:13px;color:#444">';
	foreach ($rows as $r) {
		$intro .= '<tr><th style="text-align:left;padding:1px 12px 1px 0;font-weight:600">'.dol_escape_htmltag($r[0]).'</th><td>'.dol_escape_htmltag($r[1]).'</td></tr>';
	}
	$intro .= '</table><hr style="border:0;border-top:1px solid #ddd;margin:0 0 12px">';
}

$images = GETPOSTINT('images') === 1;
if ($message->bodyHtml !== '') {
	// Pictures of the mail (cid:) as data URIs, 5 MB at most
	$inline = array();
	$total = 0;
	foreach ($message->attachments as $a) {
		if ($a['cid'] === '' || !in_array(InboxMimeParser::kind($a['content']), array('png', 'jpeg', 'gif'), true) || ($total += $a['size']) > 5242880) {
			continue;
		}
		$mime = array('png' => 'image/png', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif')[InboxMimeParser::kind($a['content'])];
		$inline[$a['cid']] = 'data:'.$mime.';base64,'.base64_encode($a['content']);
	}
	$remote = false;
	$html = InboxMimeParser::safeHtml($message->bodyHtml, $inline, $remote);
} else {
	$html = InboxMimeParser::textDocument($message->bodyText);
	// Addresses of the text body become links
	$html = preg_replace_callback('#(?<![="\'>])\bhttps?://[^\s<>"\']{2,2000}#i', function ($m) {
		return '<a href="'.$m[0].'" target="_blank" rel="noopener noreferrer">'.$m[0].'</a>';
	}, $html);
}
if ($intro !== '') {
	$html = preg_replace('#<body>#', '<body>'.$intro, $html, 1);
}

header('Content-Type: text/html; charset=utf-8');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src data:".($images ? ' https: http:' : '')."; font-src data:; script-src 'none'; form-action 'none'; base-uri 'none'; frame-ancestors 'self'");
print $html;
