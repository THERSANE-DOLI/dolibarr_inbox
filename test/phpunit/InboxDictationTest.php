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

	/**
	 * Transcription server (1.35.0): the engine told to the writing, the consent to a server bound to its host
	 *
	 * @return void
	 */
	public function testTranscriptionServer()
	{
		global $conf;

		$keep = array();
		foreach (array('INBOX_DICTATION_ENGINE', 'INBOX_WHISPER_URL', 'INBOX_WHISPER_STAMP') as $k) {
			$keep[$k] = $conf->global->$k ?? null;
		}
		try {
			unset($conf->global->INBOX_DICTATION_ENGINE, $conf->global->INBOX_WHISPER_URL, $conf->global->INBOX_WHISPER_STAMP);
			$this->assertSame('browser', InboxDictation::engine()['engine'], 'the browser by default');
			$conf->global->INBOX_DICTATION_ENGINE = 'server';
			$this->assertSame('browser', InboxDictation::engine()['engine'], 'the server without address: the browser');

			$conf->global->INBOX_WHISPER_URL = 'http://127.0.0.1:8000/v1';
			$this->assertSame(array('engine' => 'server', 'host' => '127.0.0.1', 'local' => true), InboxDictation::engine());
			$conf->global->INBOX_WHISPER_URL = 'https://10.0.0.5:8000/v1';
			$this->assertTrue(InboxDictation::engine()['local'], 'private network');
			$conf->global->INBOX_WHISPER_URL = 'https://8.8.8.8/v1';
			$this->assertFalse(InboxDictation::engine()['local'], 'the voice leaves the network');

			// The consent to the server counts for its host of then: another host (stamp) asks it again
			$a = $this->box(true, array('status' => InboxAccount::STATUS_ACTIVE));
			$writer = $this->user('dictserver');
			$access = new InboxAccess($this->db);
			$access->grant($this->admin, $a->id, $writer->id, 0, InboxAccess::LEVEL_SEND);
			InboxAccess::clearCache();
			$d = new InboxDictation($this->db);
			$this->assertTrue($d->consent($writer, 'server', (int) $a->id));
			$this->assertArrayHasKey('server', $d->consents($writer));
			$conf->global->INBOX_WHISPER_STAMP = (string) (dol_now() + 5);
			$this->assertArrayNotHasKey('server', $d->consents($writer), 'given to the former host');
			$this->assertTrue($d->consent($writer, 'google', (int) $a->id));
			$this->assertSame(array('google'), array_keys($d->consents($writer)), 'the other receivers are not touched');
		} finally {
			foreach ($keep as $k => $v) {
				if ($v === null) {
					unset($conf->global->$k);
				} else {
					$conf->global->$k = $v;
				}
			}
		}
	}

	/**
	 * The client of the server: the key never in an error, the models read from an OpenAI answer
	 *
	 * @return void
	 */
	public function testWhisperClient()
	{
		dol_include_once('/inbox/class/inboxwhisper.class.php');
		$this->assertSame('api.openai.com', InboxWhisper::hostOf('https://API.openai.com/v1'));
		$c = new InboxWhisper('ftp://x/v1', 'sk-secret');
		$this->assertNull($c->models());
		$this->assertSame('No address', $c->error);
		// Nothing listens there: an error, without the key
		$c = new InboxWhisper('http://127.0.0.1:9/v1', 'sk-secret');
		$c->timeout = 2;
		$this->assertNull($c->transcribe(__FILE__, 'audio/webm', 'fr', 'whisper-1'));
		$this->assertNotSame('', $c->error);
		$this->assertStringNotContainsString('sk-secret', $c->error);
	}
}
