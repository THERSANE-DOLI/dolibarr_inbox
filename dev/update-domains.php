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
 * \file    inbox/dev/update-domains.php
 * \ingroup inbox
 * \brief   Development only: refresh data/domains/ before a version. Downloads the list of disposable
 *          addresses (CC0), keeps valid names, removes the public ones and the exceptions, writes disposable.txt and
 *          sources.json, prints what was added and removed. inbox itself never downloads anything (P16).
 *
 *          php dev/update-domains.php            refresh disposable.txt from the source, then sources.json
 *          php dev/update-domains.php --local    sources.json only (after a change of public.txt or never.txt)
 */

if (PHP_SAPI !== 'cli') {
	exit(1);
}

const SOURCE = 'https://raw.githubusercontent.com/disposable-email-domains/disposable-email-domains/main/disposable_email_blocklist.conf';
const SOURCE_PAGE = 'https://github.com/disposable-email-domains/disposable-email-domains';

$dir = dirname(__DIR__).'/data/domains';

/**
 * Domains of a file of the module: one per line, '#' comments
 *
 * @param string $file File
 * @return string[]
 */
function readList($file)
{
	$out = array();
	foreach (is_file($file) ? file($file, FILE_IGNORE_NEW_LINES) : array() as $l) {
		$l = strtolower(trim((string) preg_replace('/#.*$/', '', (string) $l)));
		if ($l !== '') {
			$out[$l] = $l;
		}
	}

	return array_values($out);
}

/**
 * @param string $d Domain
 * @return bool      A valid name, lower case, with a dot
 */
function validDomain($d)
{
	return (bool) preg_match('/^(?=.{4,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $d);
}

$public = readList($dir.'/public.txt');
// public.txt is written by hand: rewritten normalized (lower case, sorted, one per line, LF), its comments kept on
// top. inbox looks for « \nname\n » in the text: a CR or a space would hide a domain.
$comments = array();
foreach (is_file($dir.'/public.txt') ? file($dir.'/public.txt', FILE_IGNORE_NEW_LINES) : array() as $l) {
	if (strpos(ltrim($l), '#') === 0) {
		$comments[] = rtrim($l);
	}
}
$valid = array_values(array_filter($public, 'validDomain'));
foreach (array_diff($public, $valid) as $bad) {
	fwrite(STDERR, "public.txt: not a domain, left out: ".$bad."\n");
}
$public = $valid;
sort($public);
file_put_contents($dir.'/public.txt', implode("\n", $comments)."\n".implode("\n", $public)."\n");
$never = readList($dir.'/never.txt');
$old = readList($dir.'/disposable.txt');
$disposable = $old;
$copied = '';
if (!in_array('--local', $argv, true)) {
	$ctx = stream_context_create(array('http' => array('timeout' => 30, 'user_agent' => 'inbox-dev-update-domains')));
	$raw = @file_get_contents(SOURCE, false, $ctx);
	if ($raw === false || strlen($raw) < 1000) {
		fwrite(STDERR, "Download failed: ".SOURCE."\n");
		exit(2);
	}
	$disposable = array();
	foreach (preg_split('/\r?\n/', $raw) as $l) {
		$l = strtolower(trim($l));
		if ($l !== '' && $l[0] !== '#' && validDomain($l) && !in_array($l, $public, true) && !in_array($l, $never, true)) {
			$disposable[$l] = $l;
		}
	}
	$disposable = array_values($disposable);
	sort($disposable);
	$copied = date('Y-m-d');
	$head = "# Disposable addresses: never a thirdparty, warning in the panel (inbox).\n# Copied from ".SOURCE_PAGE." (CC0) on ".$copied." by dev/update-domains.php: do not edit, see never.txt.\n";
	file_put_contents($dir.'/disposable.txt', $head.implode("\n", $disposable)."\n");
	$added = array_diff($disposable, $old);
	$removed = array_diff($old, $disposable);
	echo count($disposable)." disposable domains, ".count($added)." added, ".count($removed)." removed\n";
	foreach (array_slice($added, 0, 30) as $d) {
		echo "+ ".$d."\n";
	}
	foreach (array_slice($removed, 0, 30) as $d) {
		echo "- ".$d."\n";
	}
}
$sources = is_file($dir.'/sources.json') ? (array) json_decode((string) file_get_contents($dir.'/sources.json'), true) : array();
$files = array(
	'public' => array('file' => 'public.txt', 'source' => 'inbox', 'licence' => 'GPL-3.0-or-later'),
	'disposable' => array('file' => 'disposable.txt', 'source' => SOURCE_PAGE, 'licence' => 'CC0-1.0'),
);
$out = array();
foreach ($files as $category => $f) {
	$path = $dir.'/'.$f['file'];
	if (!is_file($path)) {
		continue;
	}
	$out[$category] = $f + array(
		'copied' => $category === 'disposable' && $copied !== '' ? $copied : (string) ($sources[$category]['copied'] ?? date('Y-m-d')),
		'count' => count(readList($path)),
		'sha256' => hash_file('sha256', $path),
	);
}
file_put_contents($dir.'/sources.json', json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
echo "sources.json written\n";
