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
 * \file    test/phpunit/InboxDictationTest.php
 * \ingroup inbox
 * \brief   Voice typing (1.28.0): allowed by the administrator, mailboxes excluded, consent per receiver, journal
 */

require_once __DIR__.'/InboxTestCase.php';
dol_include_once('/inbox/class/inboxdictation.class.php');

/**
 * Tests of InboxDictation
 */
class InboxDictationTest extends InboxTestCase
{
	/** @var string|null */
	protected $saved;

	/**
	 * @return void
	 */
	protected function setUp(): void
	{
		global $conf;

		parent::setUp();
		$this->saved = $conf->global->INBOX_DICTATION_ENABLED ?? null;
		$conf->global->INBOX_DICTATION_ENABLED = '1';
	}

	/**
	 * @return void
	 */
	protected function tearDown(): void
	{
		global $conf;

		if ($this->saved === null) {
			unset($conf->global->INBOX_DICTATION_ENABLED);
		} else {
			$conf->global->INBOX_DICTATION_ENABLED = $this->saved;
		}
		$this->db->query("DELETE FROM ".$this->db->prefix()."inbox_action_log WHERE action IN ('dictation_on', 'dictation_off')");
		parent::tearDown();
	}

	/**
	 * @return void
	 */
	public function testConsentAndExclusion()
	{
		global $conf;

		$a = $this->box(true, array('status' => InboxAccount::STATUS_ACTIVE));
		$writer = $this->user('dictwriter');
		$reader = $this->user('dictreader');
		$access = new InboxAccess($this->db);
		$access->grant($this->admin, $a->id, $writer->id, 0, InboxAccess::LEVEL_SEND);
		$access->grant($this->admin, $a->id, $reader->id, 0, InboxAccess::LEVEL_READ);
		InboxAccess::clearCache();
		$d = new InboxDictation($this->db);
		$this->assertContains((int) $a->id, $d->accountsFor($writer));
		$this->assertNotContains((int) $a->id, $d->accountsFor($reader), 'reading is not writing');

		// Consent: a known receiver, from a mailbox the user may dictate from; written in the journal
		$this->assertFalse($d->consent($writer, 'evil', (int) $a->id));
		$this->assertFalse($d->consent($reader, 'google', (int) $a->id));
		$this->assertTrue($d->consent($writer, 'google', (int) $a->id));
		$this->assertSame(array('google'), array_keys($d->consents($writer)));
		$this->assertTrue($d->consent($writer, 'microsoft', (int) $a->id));
		$log = $this->db->getRows("SELECT detail FROM ".$this->db->prefix()."inbox_action_log WHERE action = 'dictation_on' AND fk_user = ".((int) $writer->id)." ORDER BY rowid LIMIT 10");
		$this->assertSame(array('google', 'microsoft'), array_column($log, 'detail'));

		// Withdrawn one by one, then all of them; written in the journal too
		$d->revoke($writer, 'google');
		$this->assertSame(array('microsoft'), array_keys($d->consents($writer)));
		$d->revoke($writer, '');
		$this->assertSame(array(), $d->consents($writer));
		$this->assertCount(2, $this->db->getRows("SELECT rowid FROM ".$this->db->prefix()."inbox_action_log WHERE action = 'dictation_off' AND fk_user = ".((int) $writer->id)." LIMIT 10"));

		// A mailbox excluded, then the voice typing off: nowhere
		$a->setConnectorConfig(array('dictation_off' => 1));
		$a->update($this->admin);
		$this->assertNotContains((int) $a->id, $d->accountsFor($writer));
		$this->assertFalse($d->consent($writer, 'google', (int) $a->id));
		$a->setConnectorConfig(array());
		$a->update($this->admin);
		$conf->global->INBOX_DICTATION_ENABLED = '0';
		$this->assertSame(array(), $d->accountsFor($writer));
	}
}
