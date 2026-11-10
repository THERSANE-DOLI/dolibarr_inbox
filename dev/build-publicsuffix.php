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
 * Builds class/data/inboxpublicsuffix.php from the Public Suffix List (https://publicsuffix.org/list/, MPL 2.0):
 *   php dev/build-publicsuffix.php /usr/share/publicsuffix/public_suffix_list.dat
 * Rules in punycode (the domains are compared in punycode), 1 = ICANN section, 2 = PRIVATE section (shared platforms:
 * anyone gets a name under them). Run again at each release of inbox.
 */

if (PHP_SAPI !== 'cli') {
	exit(1);
}
$src = $argv[1] ?? '/usr/share/publicsuffix/public_suffix_list.dat';
$lines = @file($src, FILE_IGNORE_NEW_LINES);
if (!$lines) {
	fwrite(STDERR, "Cannot read $src\n");
	exit(1);
}
$rules = array();
$section = 0;
$version = '';
foreach ($lines as $line) {
	$line = trim($line);
	if (preg_match('/^\/\/ VERSION: (.+)$/', $line, $m)) {
		$version = trim($m[1]);
	}
	if (strpos($line, '===BEGIN ICANN DOMAINS===') !== false) {
		$section = 1;
	} elseif (strpos($line, '===BEGIN PRIVATE DOMAINS===') !== false) {
		$section = 2;
	} elseif (strpos($line, '===END') !== false) {
		$section = 0;
	}
	if ($section === 0 || $line === '' || strpos($line, '//') === 0) {
		continue;
	}
	$prefix = '';
	if ($line[0] === '!') {
		$prefix = '!';
		$line = substr($line, 1);
	}
	$labels = array();
	foreach (explode('.', mb_strtolower($line, 'UTF-8')) as $label) {
		if ($label === '*' || preg_match('/^[a-z0-9-]+$/', $label)) {
			$labels[] = $label;
			continue;
		}
		$ascii = idn_to_ascii($label, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
		if ($ascii === false) {
			continue 2;
		}
		$labels[] = $ascii;
	}
	$rules[$prefix.implode('.', $labels)] = $section;
}
ksort($rules);
$out = "<?php\n/* Public Suffix List (https://publicsuffix.org/list/), Mozilla Public License 2.0, https://mozilla.org/MPL/2.0/\n"
	." * Built by dev/build-publicsuffix.php on ".date('Y-m-d').($version !== '' ? ' from version '.$version : '').": rule in punycode => 1 ICANN, 2 PRIVATE.\n */\n\nreturn array(\n";
foreach ($rules as $rule => $type) {
	$out .= "\t'".$rule."' => ".$type.",\n";
}
$out .= ");\n";
$dest = dirname(__DIR__).'/class/data/inboxpublicsuffix.php';
if (!is_dir(dirname($dest))) {
	mkdir(dirname($dest), 0755, true);
}
file_put_contents($dest, $out);
chmod($dest, 0644);
print count($rules)." rules written to $dest\n";
