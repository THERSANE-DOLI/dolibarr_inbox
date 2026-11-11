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
require_once __DIR__.'/inbox_attserve.inc.php';
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
// Protected mailbox (1.89.0): no verdict read from or kept in the cache (the print of the file would stay)
$scan->keep = !$reader->isLive();
$mode = GETPOST('mode', 'aZ09') ?: 'download';
$part = GETPOSTINT('part');


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

// Viewers of other modules for the attachments (hook inboxAttachmentViewers, C1). A module answers in its results:
// array('inboxAttachmentViewers' => array(part => array(array('code' => ..., 'label' => ..., 'url' => '/relative?...'))))
// and, for « Annotate and file » (C2), 'inboxAttachmentEditors' in the same form: inbox adds record_type and record_id
// (the piece chosen by the user) to the address; the module saves its copy with InboxAttachmentFile::fileToRecord().
// Only relative addresses of this site are kept; inbox adds « force=1 » itself when the user opens an attachment not
// scanned by the antivirus anyway. The module loads the attachment with InboxAttachmentFile (read only).
if ($mode === 'viewers') {
	$atts = array();
	foreach (inboxAttList($message) as $i => $a) {
		$pos = strrpos($a['name'], '.');
		$atts[$i] = array('part' => $i, 'name' => $a['name'], 'extension' => $pos === false ? '' : strtolower(substr($a['name'], $pos + 1)), 'size' => strlen($a['content']));
	}
	$viewers = array();
	$editors = array();
	if ($atts) {
		$hookmanager->initHooks(array('inboxattachment'));
		$parameters = array('mail' => (int) $reader->row->rowid, 'attachments' => $atts);
		$hookAction = 'viewers';
		$hookObject = null;
		$hookmanager->executeHooks('inboxAttachmentViewers', $parameters, $hookObject, $hookAction);
		foreach (array('inboxAttachmentViewers', 'inboxAttachmentEditors') as $key) {
			$found = isset($hookmanager->resArray[$key]) && is_array($hookmanager->resArray[$key]) ? $hookmanager->resArray[$key] : array();
			foreach ($found as $p => $list) {
				if (!isset($atts[$p]) || !is_array($list)) {
					continue;
				}
				foreach ($list as $v) {
					$url = is_array($v) && isset($v['url']) ? (string) $v['url'] : '';
					if ($url === '' || $url[0] !== '/' || (isset($url[1]) && ($url[1] === '/' || $url[1] === '\\')) || preg_match('/[\r\n]/', $url) || empty($v['label'])) {
						continue;
					}
					$entry = array('code' => preg_replace('/[^a-z0-9_]/', '', (string) ($v['code'] ?? '')), 'label' => dol_trunc((string) $v['label'], 80), 'url' => $url);
					if ($key === 'inboxAttachmentViewers') {
						$viewers[$p][] = $entry;
					} else {
						$editors[$p][] = $entry;
					}
				}
			}
		}
	}
	inboxAjaxReply(array('viewers' => (object) $viewers, 'editors' => (object) $editors));
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
inboxAttServe($scan, $a, $mode, array('part' => $part));
