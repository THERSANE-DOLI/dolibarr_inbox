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
 * \file    inbox/test/phpunit/InboxModuleTest.php
 * \ingroup inbox
 * \brief   Activation of the module replay safe (CA-21), languages (CA-20)
 */

// From the command line only, never from the web: test/ stays in the Git branches
if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

/**
 * Tests of the module
 */
class InboxModuleTest extends InboxTestCase
{
	/**
	 * CA-21: disable then enable loses nothing and creates nothing twice
	 *
	 * @return void
	 */
	public function testReactivation()
	{
		$p = $this->db->prefix();
		$this->box(true);
		$count = function () use ($p) {
			return array(
				(int) $this->db->getRow("SELECT COUNT(*) AS n FROM ".$p."inbox_tag WHERE fk_account = 0")->n,
				(int) $this->db->getRow("SELECT COUNT(*) AS n FROM ".$p."inbox_account WHERE email LIKE '%@inbox-test.invalid'")->n,
				(int) $this->db->getRow("SELECT COUNT(*) AS n FROM ".$p."rights_def WHERE module = 'inbox'")->n,
			);
		};
		$before = $count();
		unActivateModule('modInbox');
		// A new page reloads the setup: activateModule() skips a module it still sees enabled in memory
		unset($GLOBALS['conf']->global->MAIN_MODULE_INBOX);
		activateModule('modInbox');
		activateModule('modInbox');
		$this->assertSame($before, $count());
		// Rights: messages, webmail, mailboxes, recognized domains (1.59.0), blocked senders (1.85.0)
		$this->assertSame(5, $before[2]);
	}

	/**
	 * CA-20: the same keys in the 5 languages, no em dash
	 *
	 * @return void
	 */
	public function testLanguages()
	{
		$dir = dirname(__DIR__, 2).'/langs';
		$keys = function ($lang) use ($dir) {
			preg_match_all('/^([A-Za-z0-9_]+)\s*=/m', (string) file_get_contents($dir.'/'.$lang.'/inbox.lang'), $m);
			sort($m[1]);
			return $m[1];
		};
		$ref = $keys('en_US');
		$this->assertSame($ref, array_unique($ref), 'no duplicate key');
		foreach (array('en_US', 'fr_FR', 'de_DE', 'it_IT', 'es_ES') as $lang) {
			$this->assertSame($ref, $keys($lang), $lang);
			$this->assertStringNotContainsString("\u{2014}", (string) file_get_contents($dir.'/'.$lang.'/inbox.lang'));
			// Public module: no customer named in the texts shown to the users
			$this->assertDoesNotMatchRegularExpression('/thersane/i', (string) file_get_contents($dir.'/'.$lang.'/inbox.lang'), $lang);
			// Nor the products the interface is inspired by (« as Thunderbird »). Named where the user acts in it: the
			// import from its QR codes (InboxQr*, 1.24.0: where the export is found) and the tags kept in step with it
			// (InboxTagsThunderbird*, InboxTagsAliasHelp, 1.65.0 and 1.66.0: what to change in Thunderbird), and the
			// database of Thunderbird asked for the servers of a new mailbox (lot H1: the outside service named where the
			// domain goes, rule R4)
			$text = preg_replace('/^(Inbox(Qr|ImportSourceQr|TagsThunderbird|AutoconfigIspdb|AutoconfigSource_ispdb|AutoconfigTriedSource_ispdb)[A-Za-z0-9_]*|InboxTagsAliasHelp|InboxOutsideWhat_ispdb)\s*=.*$/m', '', (string) file_get_contents($dir.'/'.$lang.'/inbox.lang'));
			$this->assertDoesNotMatchRegularExpression('/thunderbird|gmail|trello|roundcube/i', $text, $lang);
		}
	}
}
