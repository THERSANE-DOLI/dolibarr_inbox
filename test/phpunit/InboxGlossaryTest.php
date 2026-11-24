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
 * \file    inbox/test/phpunit/InboxGlossaryTest.php
 * \ingroup inbox
 * \brief   1.88.5: glossary of the terms explained where they are named, markers [[key:word]] of the language files
 */

// From the command line only, never from the web: test/ stays in the Git branches
if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

require_once __DIR__.'/InboxTestCase.php';

/**
 * Reads js/inbox.js (GLOSSARY, thTerms) and the 5 language files
 */
class InboxGlossaryTest extends InboxTestCase
{
	const LANGS = array('en_US', 'fr_FR', 'de_DE', 'it_IT', 'es_ES');

	/**
	 * @param string $lang Language
	 * @return array<string,string> Key => text
	 */
	protected static function lang($lang)
	{
		$out = array();
		foreach (file(dirname(__DIR__, 2).'/langs/'.$lang.'/inbox.lang') as $line) {
			if (preg_match('/^([A-Za-z0-9_]+)\s*=\s*(.*)$/', rtrim($line, "\r\n"), $m)) {
				$out[$m[1]] = $m[2];
			}
		}

		return $out;
	}

	/**
	 * @return void
	 */
	public function testMarkersAndGlossary()
	{
		$root = dirname(__DIR__, 2);
		$js = (string) file_get_contents($root.'/js/inbox.js');
		$this->assertSame(1, preg_match('/const GLOSSARY = \{(.*?)\n\t\};/s', $js, $g), 'GLOSSARY in js/inbox.js');
		preg_match_all("/^\t\t([a-z0-9]+): \['([A-Za-z0-9_]+)', '([A-Za-z0-9_]+)'\],/m", $g[1], $entries, PREG_SET_ORDER);
		$glossary = array();
		foreach ($entries as $e) {
			$glossary[$e[1]] = array($e[2], $e[3]);
		}
		$this->assertNotEmpty($glossary);
		// The keys read with thTerms(): exact, or by a prefix (thTerms('InboxOutsideWhat_' + id))
		$code = '';
		foreach (glob($root.'/js/*.js') as $f) {
			$code .= (string) file_get_contents($f);
		}
		preg_match_all("/thTerms\('([A-Za-z0-9_]+)'(\s*\+)?/", $code, $calls, PREG_SET_ORDER);
		$allowed = function ($key) use ($calls) {
			foreach ($calls as $c) {
				if ($key === $c[1] || (!empty($c[2]) && strpos($key, $c[1]) === 0)) {
					return true;
				}
			}
			return false;
		};
		$markers = array();
		foreach (self::LANGS as $lang) {
			$texts = self::lang($lang);
			foreach ($glossary as $k => $pair) {
				foreach ($pair as $key) {
					$this->assertNotEmpty($texts[$key] ?? '', $lang.': '.$key.' of the term '.$k);
				}
			}
			foreach ($texts as $key => $text) {
				if (!preg_match_all('/\[\[([a-z0-9]+):([^\]]+)\]\]/', $text, $m)) {
					$this->assertStringNotContainsString('[[', $text, $lang.': '.$key.' has a broken marker');
					continue;
				}
				$this->assertTrue($allowed($key), $lang.': '.$key.' has markers but is never read with thTerms() (a text read elsewhere would show them)');
				foreach ($m[1] as $i => $k) {
					$this->assertArrayHasKey($k, $glossary, $lang.': '.$key.' marks an unknown term '.$k);
					$this->assertNotSame('', trim($m[2][$i]), $lang.': '.$key.' marks an empty word');
				}
				$markers[$key][$lang] = $m[1];
			}
		}
		// The same terms in every language, in the same order
		foreach ($markers as $key => $byLang) {
			$this->assertCount(count(self::LANGS), $byLang, $key.': markers in some languages only');
			$this->assertCount(1, array_unique(array_map('json_encode', $byLang)), $key.': the terms differ between the languages');
		}
		$this->assertArrayHasKey('InboxRspamdSection', $markers);
	}
}
