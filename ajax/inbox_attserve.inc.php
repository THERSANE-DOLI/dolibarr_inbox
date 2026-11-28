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
 * \file    inbox/ajax/inbox_attserve.inc.php
 * \ingroup inbox
 * \brief   One file shown or given, the same way for an attachment of a mail and a file of a writing (1.82.0):
 *          verdict of the antivirus, beginning of a text, files of a .zip, preview of a PDF or a picture, download
 */

/**
 * Verdict of an attachment for the application
 *
 * @param InboxAttachmentScan $scan Antivirus
 * @param array<string,mixed> $a    Attachment
 * @return array{state:string,message:string,warnings:string[],blocked:bool,warn:bool}
 */
function inboxAttVerdict(InboxAttachmentScan $scan, array $a)
{
	$v = $scan->check($a['content'], $a['name']);
	$v['warnings'] = InboxAttachmentScan::warnings($a['content'], $a['name']);
	$v['blocked'] = $v['state'] === InboxAttachmentScan::INFECTED;
	$v['warn'] = in_array($v['state'], array(InboxAttachmentScan::UNSCANNED, InboxAttachmentScan::TOO_BIG), true);

	return $v;
}

/**
 * Answer for one file and exit: mode check (JSON verdict), text, list, inline (PDF and pictures only), download.
 * An infected file is never given; a file not scanned only with force=1.
 *
 * @param InboxAttachmentScan $scan  Antivirus
 * @param array{name:string,content:string} $a File
 * @param string              $mode  Mode
 * @param array<string,mixed> $extra Added to the verdict (part...)
 * @return void
 */
function inboxAttServe(InboxAttachmentScan $scan, array $a, $mode, array $extra = array())
{
	global $langs;

	$v = inboxAttVerdict($scan, $a);
	if ($mode === 'check') {
		inboxAjaxReply($extra + $v);
	}
	if ($v['blocked']) {
		inboxAjaxError($langs->transnoentities('InboxAttInfected', $v['message']), 403, array('state' => 'infected'));
	}
	if ($v['warn'] && GETPOSTINT('force') !== 1) {
		inboxAjaxError($langs->transnoentities('InboxAttUnscanned'), 409, array('state' => $v['state']));
	}

	$kind = InboxMimeParser::kind($a['content']);
	if ($mode === 'text') {
		$text = substr($a['content'], 0, 204800);
		if (!mb_check_encoding($text, 'UTF-8')) {
			$text = mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
		}
		inboxAjaxReply(array('text' => $text, 'truncated' => strlen($a['content']) > 204800));
	}
	if ($mode === 'list') {
		$files = array();
		if ($kind === 'zip' && class_exists('ZipArchive')) {
			$tmp = tempnam(sys_get_temp_dir(), 'ibxl');
			file_put_contents($tmp, $a['content']);
			$zip = new ZipArchive();
			if ($zip->open($tmp) === true) {
				for ($i = 0; $i < min($zip->numFiles, 1000); $i++) {
					$s = $zip->statIndex($i);
					if ($s) {
						$files[] = array('name' => InboxMimeParser::clean((string) $s['name']), 'size' => (int) $s['size']);
					}
				}
				$zip->close();
			}
			@unlink($tmp);
		}
		inboxAjaxReply(array('files' => $files));
	}

	$types = array('pdf' => 'application/pdf', 'png' => 'image/png', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif');
	$inline = $mode === 'inline' && isset($types[$kind]) && !InboxAttachmentScan::isExecutable($a['content'], $a['name']);
	header('Content-Type: '.($inline ? $types[$kind] : 'application/octet-stream'));
	header('Content-Disposition: '.($inline ? 'inline' : 'attachment')."; filename=\"".str_replace(array('"', "\r", "\n"), '', $a['name'])."\"; filename*=UTF-8''".rawurlencode($a['name']));
	header('Content-Length: '.strlen($a['content']));
	// The PDF viewer of the browser needs its own resources: only the framing is limited for a PDF
	header("Content-Security-Policy: ".($inline && $kind === 'pdf' ? "frame-ancestors 'self'" : "default-src 'none'; img-src 'self'; frame-ancestors 'self'"));
	header('Cache-Control: private, no-store');
	header('X-Content-Type-Options: nosniff');
	print $a['content'];
	exit;
}
