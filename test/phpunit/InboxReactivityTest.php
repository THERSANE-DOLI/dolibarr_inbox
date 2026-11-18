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
 * \file    test/phpunit/InboxReactivityTest.php
 * \ingroup inbox
 * \brief   Settings > Server (1.124.0): refresh of the lists and push service, global, secrets never given back
 */

require_once __DIR__.'/InboxTestCase.php';
require_once __DIR__.'/../../class/inboxreactivity.class.php';

/**
 * Settings of the refresh and of the push service
 */
class InboxReactivityTest extends InboxTestCase
{
	/** @var string[] Constants touched */
	const CONSTS = array('INBOX_REACTIVITY', 'INBOX_REACTIVITY_INTERVAL', 'INBOX_REACTIVITY_IDLE', 'INBOX_PUSH_DRIVER', 'INBOX_PUSH_MERCURE_HUB_URL',
		'INBOX_PUSH_MERCURE_PUBLISH_URL', 'INBOX_PUSH_MERCURE_ISSUER', 'INBOX_PUSH_MERCURE_PUBLISHER_KEY', 'INBOX_PUSH_MERCURE_SUBSCRIBER_KEY', 'INBOX_PUSH_TESTED');

	/** @var array<string,string|null> Values before the test */
	protected $before = array();

	/**
	 * @return void
	 */
	protected function setUp(): void
	{
		global $conf;

		parent::setUp();
		foreach (self::CONSTS as $c) {
			$this->before[$c] = isset($conf->global->$c) ? (string) $conf->global->$c : null;
		}
	}

	/**
	 * @return void
	 */
	protected function tearDown(): void
	{
		global $conf;

		foreach (self::CONSTS as $c) {
			dolibarr_del_const($this->db, $c, 0);
			unset($conf->global->$c);
			if ($this->before[$c] !== null) {
				dolibarr_set_const($this->db, $c, $this->before[$c], 'chaine', 0, '', 0);
			}
		}
		InboxPush::reset();
		parent::tearDown();
	}

	/**
	 * @param string $name Constant
	 * @return array{entity:int,value:string}|null
	 */
	protected function stored($name)
	{
		$row = $this->db->getRow("SELECT entity, value FROM ".$this->db->prefix()."const WHERE name = '".$this->db->escape($name)."'");

		return is_object($row) ? array('entity' => (int) $row->entity, 'value' => (string) $row->value) : null;
	}

	/**
	 * Mode and rhythm: global (entity 0), an unknown mode is the standard one, the idle interval follows
	 *
	 * @return void
	 */
	public function testSaveIsGlobalAndChecked()
	{
		$r = new InboxReactivity($this->db);
		$this->assertSame(array('mode' => 'fast', 'rhythm' => array('interval' => 40, 'idle' => 40)), $r->save('fast', 40, 10));
		$this->assertSame(array('entity' => 0, 'value' => 'fast'), $this->stored('INBOX_REACTIVITY'));
		$this->assertSame('40', $this->stored('INBOX_REACTIVITY_IDLE')['value']);
		$this->assertSame('standard', $r->save('everything', 5, 60)['mode']);
		$d = $r->data();
		$this->assertSame('standard', $d['mode']);
		$this->assertSame(InboxReactivity::LIGHT_MS, $d['light_ms']);
		$this->assertIsInt($d['users']);
	}

	/**
	 * Push service: a secret left empty is kept, never given back; a refused address saves nothing
	 *
	 * @return void
	 */
	public function testPushSecretsKeptAndNeverGivenBack()
	{
		$r = new InboxReactivity($this->db);
		$values = array('hub_url' => 'https://push.inbox-test.invalid/.well-known/mercure', 'publish_url' => '', 'issuer' => 'https://erp.inbox-test.invalid',
			'publisher_key' => 'publisher-secret-publisher-secret-1234', 'subscriber_key' => 'subscriber-secret-subscriber-secret-12');
		$this->assertTrue($r->savePush('mercure', $values));
		$this->assertStringStartsWith('dolcrypt:', $this->stored('INBOX_PUSH_MERCURE_PUBLISHER_KEY')['value'], 'stored encrypted');
		$this->assertSame(0, $this->stored('INBOX_PUSH_MERCURE_PUBLISHER_KEY')['entity']);
		$key = $this->stored('INBOX_PUSH_MERCURE_PUBLISHER_KEY')['value'];

		// Saved again with the keys left empty: kept
		$this->assertTrue($r->savePush('mercure', array('publisher_key' => '', 'subscriber_key' => '') + $values));
		$this->assertSame($key, $this->stored('INBOX_PUSH_MERCURE_PUBLISHER_KEY')['value']);

		$json = json_encode($r->pushData());
		$this->assertStringNotContainsString('publisher-secret', $json, 'no key, clear or encrypted, for the browser');
		$this->assertStringNotContainsString('subscriber-secret', $json);
		$this->assertStringNotContainsString('dolcrypt', $json);
		$mercure = $r->pushData()['drivers'][0];
		$this->assertTrue($mercure['configured']);
		$keys = array_column($mercure['fields'], 'saved', 'key');
		$this->assertTrue($keys['publisher_key']);

		// A refused address: nothing changes, the error names the field
		$this->assertFalse($r->savePush('mercure', array('hub_url' => 'https://u:p@evil.invalid/', 'issuer' => 'other') + $values));
		$this->assertNotSame('', $r->error);
		$this->assertSame('https://push.inbox-test.invalid/.well-known/mercure', $this->stored('INBOX_PUSH_MERCURE_HUB_URL')['value']);
		$this->assertSame('https://erp.inbox-test.invalid', $this->stored('INBOX_PUSH_MERCURE_ISSUER')['value'], 'nothing saved before the check');
		$this->assertFalse($r->savePush('nope', $values), 'unknown driver');
	}

	/**
	 * Instant only with a push service tested with its present settings; a setting changed afterwards: not used any
	 * more (the fast rhythm), until a new test passes
	 *
	 * @return void
	 */
	public function testInstantNeedsATestedService()
	{
		global $conf;

		$r = new InboxReactivity($this->db);
		$values = array('hub_url' => 'https://push.inbox-test.invalid/.well-known/mercure', 'publish_url' => '', 'issuer' => 'https://erp.inbox-test.invalid',
			'publisher_key' => 'publisher-secret-publisher-secret-1234', 'subscriber_key' => 'subscriber-secret-subscriber-secret-12');
		$this->assertTrue($r->savePush('mercure', $values));
		$this->assertNull(InboxPush::ready(), 'configured but never tested');
		$this->assertNull($r->save('instant', 5, 60), 'refused');
		$this->assertNotSame('', $r->error);
		$this->assertNull($this->stored('INBOX_REACTIVITY'));

		// What a passed test writes (InboxReactivity::testPush): a keyed hash of the settings, never the values
		$driver = InboxPush::make('mercure');
		$fp = InboxPush::fingerprint($driver, InboxPush::values($driver));
		$this->assertStringNotContainsString('secret', $fp);
		dolibarr_set_const($this->db, 'INBOX_PUSH_TESTED', $fp, 'chaine', 0, '', 0);
		$this->assertInstanceOf('InboxPushMercure', InboxPush::ready());
		$this->assertSame('instant', $r->save('instant', 5, 60)['mode']);
		InboxPush::reset();
		$this->assertInstanceOf('InboxPushMercure', InboxPush::active());
		$this->assertTrue($r->pushData()['drivers'][0]['tested']);

		// A key changed: the test does not hold any more, no driver used (the lists at the fast rhythm)
		$this->assertTrue($r->savePush('mercure', array('subscriber_key' => 'another-subscriber-key-another-key-123') + $values));
		InboxPush::reset();
		$this->assertNull(InboxPush::ready());
		$this->assertNull(InboxPush::active());
		$this->assertFalse($r->pushData()['drivers'][0]['tested']);
		$this->assertSame('instant', $r->save('instant', 10, 60)['mode'], 'already instant: the intervals can still change');
		$this->assertFalse($r->data()['push_ready']);
	}

	/**
	 * Settings of the whole instance: an administrator; with multicompany, the one of the instance only (audit INBX-093)
	 *
	 * @return void
	 */
	public function testCanSetIsTheAdministratorOfTheInstance()
	{
		$u = new User($this->db);
		$u->admin = 0;
		$u->entity = 0;
		$this->assertFalse(InboxReactivity::canSet($u), 'not an administrator');
		$u->admin = 1;
		$this->assertTrue(InboxReactivity::canSet($u));
		$u->entity = 1;
		$this->assertSame(!isModEnabled('multicompany'), InboxReactivity::canSet($u), 'administrator of one entity: only without multicompany');
	}
}
