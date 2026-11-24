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
 * \file    inbox/dev/check-cli-guard.php
 * \ingroup inbox
 * \brief   Check that every PHP file of dev/ and test/ refuses the web before running anything
 *
 * The folders dev/ and test/ stay in the Git branches (left out of the zip only): a Git deployment has them in the
 * web root, where their .htaccess is ignored by nginx. So the first statement of each PHP file there must be:
 *   if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
 * Only comments, declare, namespace and use may come before it.
 *
 * Usage (command line, from the module directory; also run by the CI):
 *   php dev/check-cli-guard.php            every PHP file of dev/ and test/
 *   php dev/check-cli-guard.php <files>    only these files (those outside dev/ and test/ are ignored)
 * Exit code 1 when a file has no guard, the list of these files on the error output.
 */

if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

$root = dirname(__DIR__);
chdir($root);

/**
 * Whether the first statement of a PHP source is the CLI guard
 *
 * @param	string	$code	Source of the file
 * @return	bool			True when the guard comes first
 */
function inboxHasCliGuard($code)
{
	$tokens = token_get_all($code);
	$skip = array(T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT);
	$n = count($tokens);
	for ($i = 0; $i < $n; $i++) {
		$t = $tokens[$i];
		if (is_array($t) && in_array($t[0], $skip, true)) {
			continue;
		}
		// declare(...); namespace X; use X;: allowed before the guard, skipped up to their ';'
		if (is_array($t) && in_array($t[0], array(T_DECLARE, T_NAMESPACE, T_USE), true)) {
			while ($i < $n && $tokens[$i] !== ';') {
				$i++;
			}
			continue;
		}
		// The first statement: the guard « if (PHP_SAPI !== 'cli') » followed by an exit in its block
		$rest = '';
		for ($j = $i; $j < $n && strlen($rest) < 200; $j++) {
			$rest .= is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];
		}
		$rest = preg_replace('/\s+/', ' ', $rest);
		return (bool) preg_match('/^if \( ?PHP_SAPI ?!== ?[\'"]cli[\'"] ?\) ?\{[^}]*\b(exit|die)\b/i', $rest);
	}
	return false;
}

$files = array_slice($argv, 1);
if (empty($files)) {
	$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator('.', FilesystemIterator::SKIP_DOTS));
	foreach ($it as $f) {
		$path = ltrim(substr($f->getPathname(), 1), '/');
		if (preg_match('#^(dev|test)/#', $path) && substr($path, -4) === '.php' && strpos($path, '/node_modules/') === false) {
			$files[] = $path;
		}
	}
	sort($files);
}

$missing = array();
$checked = 0;
foreach ($files as $path) {
	$path = ltrim(preg_replace('#^\./#', '', $path), '/');
	if (!preg_match('#^(dev|test)/.*\.php$#', $path) || !is_file($path)) {
		continue;
	}
	$checked++;
	if (!inboxHasCliGuard((string) file_get_contents($path))) {
		$missing[] = $path;
	}
}

if (!empty($missing)) {
	fwrite(STDERR, "PHP files of dev/ or test/ without the guard « if (PHP_SAPI !== 'cli') { http_response_code(404); exit; } » as first statement:\n");
	foreach ($missing as $path) {
		fwrite(STDERR, '  '.$path."\n");
	}
	exit(1);
}
echo $checked." PHP files of dev/ and test/ checked, all refuse the web.\n";
