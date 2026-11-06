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
 * \file    inbox/ajax/attachment.php
 * \ingroup inbox
 * \brief   Attachments of a mail: verdict of the antivirus, preview, download, all in a .zip
 *
 * mode = check (JSON: verdict and warnings; part = -1 for all), inline (PDF and pictures only, for the preview),
 * download, text (JSON: beginning of a text file), list (JSON: files of a .zip, never unpacked on disk), zip (every
 * attachment except the infected ones). A file « not scanned » (antivirus off or file too big) is served only with
 * force=1, after the user read the warning; an infected file is never served.
 */

require __DIR__.'/inbox_ajax.inc.php';
dol_include_once('/inbox/class/inboxreader.class.php');

inboxAjaxStart(false);
$reader = new InboxReader($db);
if (!$reader->load($user, GETPOSTINT('id'))) {
	inboxAjaxError($langs->transnoentities('InboxMailNotFound'), 404);
}
$raw = $reader->raw($user);
if ($raw === null) {
	inboxAjaxError($langs->transnoentities($reader->errorCode === 'gone' ? 'InboxMailGone' : 'InboxMailReadError', $reader->error), $reader->errorCode === 'gone' ? 410 : 502);
}
$message = InboxMimeParser::parse($raw);
$scan = new InboxAttachmentScan($db);
$mode = GETPOST('mode', 'aZ09') ?: 'download';
$part = GETPOSTINT('part');

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
 * Attachments of the mail the reader works on (pictures of the body and attachments of attached mails apart)
 *
 * @param InboxMessage $message Mail
 * @return array<int,array<string,mixed>> Index in the mail => attachment
 */
function inboxAttList(InboxMessage $message)
{
	$out = array();
	foreach ($message->attachments as $i => $a) {
		if (empty($a['inline']) && empty($a['embedded'])) {
			$out[$i] = $a;
		}
	}

	return $out;
}

if ($mode === 'check' && $part === -1) {
	$out = array();
	foreach (inboxAttList($message) as $i => $a) {
		$out[] = array('part' => $i) + inboxAttVerdict($scan, $a);
	}
	inboxAjaxReply(array('parts' => $out));
}

if ($mode === 'zip') {
	if (!class_exists('ZipArchive')) {
		inboxAjaxError('ZipArchive missing', 501);
	}
	$tmp = tempnam(sys_get_temp_dir(), 'ibxz');
	$zip = new ZipArchive();
	$zip->open($tmp, ZipArchive::OVERWRITE);
	$names = array();
	$warn = false;
	foreach (inboxAttList($message) as $a) {
		$v = inboxAttVerdict($scan, $a);
		if ($v['blocked']) {
			continue;
		}
		$warn = $warn || $v['warn'];
		$name = $a['name'];
		for ($n = 2; isset($names[strtolower($name)]); $n++) {
			$name = preg_replace('/(\.[^.]*)?$/', ' ('.$n.')$1', $a['name'], 1);
		}
		$names[strtolower($name)] = true;
		$zip->addFromString($name, $a['content']);
	}
	if (!$names) {
		// Every attachment infected: nothing to save (an empty archive is not even written by ZipArchive)
		$zip->close();
		@unlink($tmp);
		inboxAjaxError($langs->transnoentities('InboxZipNothing'), 403, array('state' => 'infected'));
	}
	if ($warn && GETPOSTINT('force') !== 1) {
		$zip->close();
		@unlink($tmp);
		inboxAjaxError($langs->transnoentities('InboxAttUnscanned'), 409, array('state' => 'unscanned'));
	}
	$zip->close();
	$file = InboxMimeParser::fileName(($reader->row->subject ?: 'mail').'.zip');
	header('Content-Type: application/zip');
	header("Content-Disposition: attachment; filename=\"".str_replace('"', '', $file)."\"; filename*=UTF-8''".rawurlencode($file));
	header('Content-Length: '.filesize($tmp));
	readfile($tmp);
	@unlink($tmp);
	exit;
}

$list = inboxAttList($message);
if (!isset($list[$part])) {
	inboxAjaxError($langs->transnoentities('InboxAttNotFound'), 404);
}
$a = $list[$part];
$v = inboxAttVerdict($scan, $a);
if ($mode === 'check') {
	inboxAjaxReply(array('part' => $part) + $v);
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
