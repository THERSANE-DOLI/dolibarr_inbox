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
 * Build img/inbox-icons.svg (SVG sprite of the interface) from dev/icons.json and the Tabler files kept in
 * dev/tabler/<style>/<name>.svg (Tabler Icons, MIT license, version pinned in icons.json). Nothing is downloaded: to
 * add an icon, add its usage name in icons.json and run again. A file missing from dev/tabler is copied from the full
 * package when it is there (dev/bin/tabler-<version>, ignored by git, see --picker), else put it there by hand.
 * Also builds the icons of the installable application (img/app-*.png) when ImageMagick is there.
 *
 * Usage (command line, developer only):
 *   php dev/build-icons.php          build the sprite and the application icons
 *   php dev/build-icons.php --picker build dev/bin/icon-picker.html: every icon of the full package, to choose the
 *                                    ones to add (needs the package of the npm @tabler/icons of the same version,
 *                                    folders icons/ and file icons.json, in dev/bin/tabler-<version>)
 */

if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

$root = dirname(__DIR__);
$conf = json_decode((string) file_get_contents($root.'/dev/icons.json'), true);
if (!is_array($conf) || empty($conf['icons'])) {
	fwrite(STDERR, "dev/icons.json not readable\n");
	exit(1);
}
$package = $root.'/dev/bin/tabler-'.$conf['_version'];

if (in_array('--picker', $argv, true)) {
	exit(buildPicker($root, $package, $conf));
}

$symbols = array();
$missing = array();
foreach ($conf['icons'] as $usage => $def) {
	list($name, $style) = $def;
	if (!preg_match('/^[a-z0-9-]+$/', $usage) || !preg_match('/^[a-z0-9-]+$/', $name) || !in_array($style, array('outline', 'filled'), true)) {
		$missing[] = $usage.' (bad name)';
		continue;
	}
	$file = $root.'/dev/tabler/'.$style.'/'.$name.'.svg';
	if (!is_readable($file) && is_readable($package.'/icons/'.$style.'/'.$name.'.svg')) {
		copy($package.'/icons/'.$style.'/'.$name.'.svg', $file);
		echo 'copied from the package: '.$style.'/'.$name.".svg\n";
	}
	if (!is_readable($file)) {
		$missing[] = $usage.' ('.$style.'/'.$name.'.svg missing)';
		continue;
	}
	$svg = (string) file_get_contents($file);
	if (!preg_match('/<svg\b([^>]*)>/', $svg, $m)) {
		$missing[] = $usage.' (not an svg)';
		continue;
	}
	// Keep the drawing attributes of the root <svg> (stroke or fill) on the <symbol>. Not the width of the stroke: set
	// by the CSS (.ibx-icon), so that the large icons (attachments, empty states) can be drawn thinner
	$attrs = array();
	foreach (array('viewBox', 'fill', 'stroke', 'stroke-linecap', 'stroke-linejoin') as $a) {
		if (preg_match('/\b'.$a.'="([^"]*)"/', $m[1], $v)) {
			$attrs[] = $a.'="'.$v[1].'"';
		}
	}
	$inner = preg_replace('/^.*?<svg\b[^>]*>|<\/svg>\s*$/s', '', $svg);
	$inner = preg_replace('/<path stroke="none" d="M0 0h24v24H0z" fill="none"\s*\/>/', '', $inner); // invisible frame
	$inner = trim(preg_replace('/\s+/', ' ', $inner));
	$symbols[] = '<symbol id="ibx-i-'.$usage.'" '.implode(' ', $attrs).'>'.$inner.'</symbol>';
}
$out = '<svg xmlns="http://www.w3.org/2000/svg" style="display:none">'."\n"
	.'<!-- Inbox icons, built by dev/build-icons.php from dev/icons.json. Tabler Icons '.$conf['_version'].', MIT license, https://tabler.io/icons -->'."\n"
	.implode("\n", $symbols)."\n</svg>\n";
file_put_contents($root.'/img/inbox-icons.svg', $out);
echo 'symbols: '.count($symbols)."\n";
// Icons offered for a mailbox or a folder (usage names, all in the sprite): read by the server to check a choice and
// given to the page
$choices = array_values(array_filter(isset($conf['choices']) ? (array) $conf['choices'] : array(), function ($u) use ($conf) {
	return isset($conf['icons'][$u]);
}));
file_put_contents($root.'/img/inbox-icon-choices.json', json_encode($choices)."\n");
echo 'choices: '.count($choices)."\n";
echo 'missing: '.($missing ? implode(', ', $missing) : 'none')."\n";

// Icons of the installable application: the logo of the module (img/inbox.svg) in white on the accent colour
// (square for maskable, the safe zone keeps the drawing inside the inner 80 %)
$accent = '#8a2b6e';
$logo = (string) file_get_contents($root.'/img/inbox.svg');
// Only the drawing: no namedview nor metadata of Inkscape, every colour of the drawing white
$logo = preg_replace('/<sodipodi:namedview\b.*?\/>|<metadata\b.*?<\/metadata>|<defs\b[^>]*\/>/s', '', $logo);
$inner = preg_replace('/^.*?<svg\b[^>]*>|<\/svg>\s*$/s', '', $logo);
$inner = preg_replace('/\s(inkscape|sodipodi):[a-z-]+="[^"]*"/i', '', $inner);
$inner = preg_replace('/(fill|stroke):#[0-9a-f]{6}/i', '$1:#ffffff', $inner);
// Real box of the drawing (the page of Inkscape is larger): drawn on a large canvas and trimmed by ImageMagick
$box = array(0, 0, 512, 512);
$convert = trim((string) shell_exec('command -v convert 2>/dev/null'));
if ($convert !== '') {
	$big = sys_get_temp_dir().'/inbox-logo-box-'.getmypid().'.svg';
	file_put_contents($big, '<svg xmlns="http://www.w3.org/2000/svg" width="1024" height="1024" viewBox="-256 -256 1024 1024">'.$inner.'</svg>');
	$info = (string) shell_exec(escapeshellcmd($convert).' -background none -density 96 '.escapeshellarg($big).' -trim info:');
	unlink($big);
	if (preg_match('/ (\d+)x(\d+) \d+x\d+\+(\d+)\+(\d+) /', $info, $m)) {
		$box = array((int) $m[3] - 256, (int) $m[4] - 256, (int) $m[1], (int) $m[2]);
	}
}
// The drawing centred, its width (or height) at $scale of the icon
$make = function ($rounded, $scale) use ($accent, $inner, $box) {
	$k = 512 * $scale / max($box[2], $box[3]);
	$tx = 256 - ($box[0] + $box[2] / 2) * $k;
	$ty = 256 - ($box[1] + $box[3] / 2) * $k;
	return '<svg xmlns="http://www.w3.org/2000/svg" width="512" height="512" viewBox="0 0 512 512">'
		.'<rect width="512" height="512" rx="'.($rounded ? '107' : '0').'" fill="'.$accent.'"/>'
		.'<g transform="translate('.round($tx, 2).' '.round($ty, 2).') scale('.round($k, 4).')">'.trim($inner).'</g></svg>';
};
file_put_contents($root.'/img/app-icon.svg', $make(true, 0.78));
// Icon of the tab (favicon): too small for the bird, a white envelope of Tabler on the accent
$envelope = (string) file_get_contents($root.'/dev/tabler/outline/mail.svg');
$envelope = preg_replace('/^.*?<svg\b[^>]*>|<\/svg>\s*$/s', '', $envelope);
$envelope = preg_replace('/<path stroke="none" d="M0 0h24v24H0z" fill="none"\s*\/>/', '', $envelope);
file_put_contents($root.'/img/favicon.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="512" height="512" viewBox="0 0 24 24"><rect width="24" height="24" rx="5" fill="'.$accent.'"/>'
	.'<g transform="translate(3.36 3.36) scale(0.72)" fill="none" stroke="#ffffff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">'.trim($envelope).'</g></svg>'."\n");
if ($convert === '') {
	echo "ImageMagick not found: application icons not built\n";
	exit(0);
}
$tmp = sys_get_temp_dir().'/inbox-app-maskable-'.getmypid().'.svg';
file_put_contents($tmp, $make(false, 0.62));
foreach (array(192, 512) as $size) {
	shell_exec(escapeshellcmd($convert).' -background none -density 600 '.escapeshellarg($root.'/img/app-icon.svg').' -resize '.$size.'x'.$size.' '.escapeshellarg($root.'/img/app-'.$size.'.png'));
}
shell_exec(escapeshellcmd($convert).' -background none -density 600 '.escapeshellarg($tmp).' -resize 512x512 '.escapeshellarg($root.'/img/app-maskable-512.png'));
unlink($tmp);
echo "application icons: img/app-192.png, img/app-512.png, img/app-maskable-512.png\n";

/**
 * Inner drawing of a Tabler file (without the root <svg> nor the invisible frame)
 *
 * @param string $svg Content of the file
 * @return string
 */
function iconInner($svg)
{
	$inner = preg_replace('/^.*?<svg\b[^>]*>|<\/svg>\s*$/s', '', $svg);
	$inner = preg_replace('/<path stroke="none" d="M0 0h24v24H0z" fill="none"\s*\/>/', '', $inner);

	return trim(preg_replace('/\s+/', ' ', $inner));
}

/**
 * Developer page to choose icons: dev/bin/icon-picker.html (ignored by git, opened from the disk)
 *
 * @param string              $root    Root of the module
 * @param string              $package Full Tabler package
 * @param array<string,mixed> $conf    dev/icons.json
 * @return int Exit code
 */
function buildPicker($root, $package, $conf)
{
	$meta = json_decode((string) @file_get_contents($package.'/icons.json'), true);
	if (!is_array($meta) || !is_dir($package.'/icons/outline')) {
		fwrite(STDERR, "Full Tabler package missing: put the npm @tabler/icons ".$conf['_version']." (icons/, icons.json) in ".$package."\n");
		return 1;
	}
	$icons = array();
	foreach (array('outline' => 'o', 'filled' => 'f') as $style => $key) {
		foreach (glob($package.'/icons/'.$style.'/*.svg') as $file) {
			$name = basename($file, '.svg');
			if (!isset($icons[$name])) {
				$m = isset($meta[$name]) ? $meta[$name] : array();
				$icons[$name] = array('c' => isset($m['category']) ? (string) $m['category'] : '', 't' => isset($m['tags']) ? implode(' ', array_map('strval', (array) $m['tags'])) : '');
			}
			$icons[$name][$key] = iconInner((string) file_get_contents($file));
		}
	}
	ksort($icons);
	$used = array();
	foreach ($conf['icons'] as $usage => $def) {
		$used[$def[0].'|'.$def[1]][] = $usage;
	}
	$data = json_encode(array('version' => $conf['_version'], 'icons' => $icons, 'used' => $used), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
	$html = (string) file_get_contents(__DIR__.'/icon-picker.tpl.html');
	$html = str_replace('/*DATA*/null', $data, $html);
	file_put_contents($root.'/dev/bin/icon-picker.html', $html);
	echo 'icons: '.count($icons).', page: '.$root."/dev/bin/icon-picker.html\n";

	return 0;
}
