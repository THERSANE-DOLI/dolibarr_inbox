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
 * \file    inbox/test/phpunit/InboxRulesTest.php
 * \ingroup inbox
 * \brief   Rules and out of office written by ManageSieve (lot W8 1.23.0)
 */

dol_include_once('/inbox/class/inboxrules.class.php');

/**
 * The script needs nothing; the server part needs dev/dovecot/run.sh (Dovecot with Pigeonhole and ManageSieve)
 */
class InboxRulesTest extends InboxImapTestCase
{
	/** @var InboxAccount|null Mailbox whose scripts are removed after the test */
	protected $sieveBox = null;

	/**
	 * @return void
	 */
	protected function tearDown(): void
	{
		if ($this->sieveBox) {
			$this->wipe($this->sieveBox);
		}
		parent::tearDown();
	}

	/**
	 * Every script of the test mailbox removed
	 *
	 * @param InboxAccount $a Mailbox
	 * @return void
	 */
	protected function wipe(InboxAccount $a)
	{
		$error = '';
		$c = InboxConnectorFactory::forAccount($this->db, $a, $error);
		$s = $c ? $c->sieveSession($error) : null;
		if (!$s) {
			return;
		}
		$s->setActive('');
		foreach ((array) $s->listScripts() as $x) {
			$s->deleteScript($x['name']);
		}
		$s->close();
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	protected static function sample()
	{
		return array(
			array('name' => 'Sauvegardes "nuit"', 'on' => 1, 'all' => 1, 'stop' => 1,
				'conds' => array(array('f' => 'from', 'op' => 'is', 'v' => 'backup@srv.example'), array('f' => 'subject', 'op' => 'starts', 'v' => '[OK*]')),
				'acts' => array(array('a' => 'move', 'v' => 'INBOX.ZzActB'), array('a' => 'read'))),
			array('name' => 'Alertes', 'on' => 1, 'all' => 0,
				'conds' => array(array('f' => 'tocc', 'op' => 'contains', 'v' => 'alerte'), array('f' => 'header', 'h' => 'X-Priority', 'op' => 'is', 'v' => '1')),
				'acts' => array(array('a' => 'flag'), array('a' => 'redirect', 'v' => 'Moi@Example.org'))),
			array('name' => 'Off', 'on' => 0, 'all' => 1, 'conds' => array(array('f' => 'subject', 'op' => 'not', 'v' => 'x')), 'acts' => array(array('a' => 'delete'))),
		);
	}

	/**
	 * @return void
	 */
	public function testScriptAndChecks()
	{
		$a = $this->box(true, array('status' => InboxAccount::STATUS_ACTIVE));
		$r = new InboxRules($this->db);
		$rules = $r->cleanRules($a, self::sample());
		$this->assertNotNull($rules, $r->errorCode.' '.$r->error);
		$this->assertSame('moi@example.org', $rules[1]['acts'][1]['v'], 'address in lower case');
		$this->assertSame('x-priority', $rules[1]['conds'][1]['h']);

		$ext = array('fileinto', 'imap4flags', 'mailbox', 'copy', 'vacation', 'date', 'relational', 'variables', 'include');
		$away = array('on' => true, 'from' => '2026-11-10', 'to' => '2026-11-14', 'subject' => 'Absence', 'text' => "Bonjour,\nabsent \"vraiment\" \${x}.", 'days' => 2, 'addr' => array(self::BOX));
		$script = InboxRules::script($rules, $away, array('INBOX' => 'INBOX'), 'INBOX.Trash', $ext, 'ancien', array('saved' => 1000, 'by' => 'Équipe'));
		$this->assertStringContainsString('address :is "from" "backup@srv.example"', $script);
		$this->assertStringContainsString('header :matches "subject" "[OK\\\\*]*"', $script, 'the * typed is not a wildcard');
		$this->assertStringContainsString('fileinto :create :flags "\\\\Seen" "INBOX.ZzActB";', $script, 'folder not known: created; flags with the mail filed');
		$this->assertStringContainsString('anyof(header :contains ["to", "cc"] "alerte", header :is "x-priority" "1")', $script);
		$this->assertStringContainsString('addflag "\\\\Flagged";', $script, 'not filed: the flag on the mail kept');
		$this->assertStringContainsString('redirect :copy "moi@example.org";', $script, 'a copy stays');
		$this->assertStringContainsString('# rule: Off (off)', $script);
		$this->assertStringNotContainsString('INBOX.Trash', $script, 'a rule off writes nothing');
		$this->assertStringContainsString('currentdate :value "ge" "date" "2026-11-10"', $script);
		$this->assertStringContainsString('absent \\"vraiment\\" $ {x}.', $script, 'quotes escaped, no variable of the user');
		$this->assertStringContainsString('include :personal "ancien";', $script);
		$this->assertLessThan(strpos($script, '# rule:'), strpos($script, 'vacation'), 'out of office first');

		$back = InboxRules::read($script);
		$this->assertNotNull($back);
		$this->assertFalse($back['edited']);
		$this->assertSame($rules, $back['rules']);
		$this->assertSame('ancien', $back['include']);
		$this->assertSame('Équipe', $back['by']);
		$this->assertTrue(InboxRules::read(str_replace('stop;', 'keep;', $script))['edited'], 'changed by hand: seen');
		$this->assertNull(InboxRules::read("require \"fileinto\";\r\nkeep;\r\n"), 'not a script of inbox');

		// Without the extensions of dates or copies
		$plain = InboxRules::script($rules, $away, array(), 'Trash', array('fileinto', 'imap4flags', 'vacation'), '', array());
		$this->assertStringNotContainsString('currentdate', $plain);
		$this->assertStringContainsString("redirect \"moi@example.org\";\r\n\tkeep;", $plain);
		$this->assertStringNotContainsString('include', $plain);

		foreach (array(
			array('conds' => array(array('f' => 'body', 'op' => 'is', 'v' => 'x')), 'acts' => array(array('a' => 'read'))),
			array('conds' => array(array('f' => 'from', 'op' => 'is', 'v' => '')), 'acts' => array(array('a' => 'read'))),
			array('conds' => array(array('f' => 'header', 'h' => 'Bad Header', 'op' => 'is', 'v' => 'x')), 'acts' => array(array('a' => 'read'))),
			array('conds' => array(array('f' => 'from', 'op' => 'is', 'v' => 'x')), 'acts' => array(array('a' => 'redirect', 'v' => 'pas une adresse'))),
			array('conds' => array(array('f' => 'from', 'op' => 'is', 'v' => 'x')), 'acts' => array(array('a' => 'tag', 'v' => 'zz_unknown'))),
			array('conds' => array(array('f' => 'from', 'op' => 'is', 'v' => 'x')), 'acts' => array(array('a' => 'move', 'v' => 'INBOX."x"'))),
			array('conds' => array(), 'acts' => array(array('a' => 'read'))),
		) as $i => $bad) {
			$this->assertNull($r->cleanRules($a, array($bad)), 'refused '.$i);
		}
		$this->assertNull($r->cleanAway($a, array('on' => 1, 'from' => '2026-11-14', 'to' => '2026-11-10', 'text' => 'x')), 'end before start');
		$this->assertNull($r->cleanAway($a, array('on' => 1, 'from' => '2026-11-10', 'to' => '2026-11-14', 'text' => ' ')), 'no text');
		$ok = $r->cleanAway($a, array('on' => 1, 'from' => '2026-11-10', 'to' => '2026-11-14', 'text' => 'x', 'days' => 99, 'addr' => array(self::BOX, 'evil@example.invalid')));
		$this->assertSame(array(self::BOX), $ok['addr'], 'only the addresses of the mailbox');
		$this->assertSame(30, $ok['days']);
	}

	/**
	 * @return void
	 */
	public function testServer()
	{
		$a = $this->ready();
		$this->sieveBox = $a;
		$this->wipe($a);
		$r = new InboxRules($this->db);
		$st = $r->load($a);
		$this->assertNotNull($st, $r->errorCode.' '.$r->error);
		$this->assertSame('ok', $st['state']);
		$this->assertSame(array(), $st['rules']);
		$this->assertContains('vacation', $st['extensions']);

		// A script active before inbox: kept, run after the rules of inbox
		$error = '';
		$c = InboxConnectorFactory::forAccount($this->db, $a, $error);
		$s = $c->sieveSession($error);
		$this->assertNotNull($s, $error);
		$this->assertTrue($s->putScript('zzancien', "require \"fileinto\";\r\nif header :contains \"subject\" \"zz\" { fileinto \"INBOX\"; }\r\n"), $s->error);
		$this->assertTrue($s->setActive('zzancien'));
		$s->close();
		$this->assertSame('foreign', $r->load($a)['state']);
		$this->assertSame('zzancien', $r->load($a)['foreign']);

		$rules = $r->cleanRules($a, self::sample());
		$away = $r->cleanAway($a, array('on' => 1, 'from' => '2026-11-10', 'to' => '2099-11-14', 'subject' => 'Absence', 'text' => 'Absent.', 'days' => 1, 'addr' => array(self::BOX)));
		$st = $r->save($this->admin, $a, $rules, $away, false, 'rules');
		$this->assertNotNull($st, $r->errorCode.' '.$r->error);
		$this->assertSame('ok', $st['state']);
		$this->assertSame(3, count($st['rules']));
		$this->assertTrue($st['away']['on']);
		$this->assertSame('zzancien', $st['include'], 'the former script is included');
		$this->assertStringContainsString('include :personal "zzancien";', $r->scriptText($a, 'inbox'));
		$row = $this->db->getRow("SELECT away_from, away_to FROM ".$this->db->prefix()."inbox_account WHERE rowid = ".((int) $a->id));
		$this->assertSame('2099-11-14', substr((string) $row->away_to, 0, 10), 'the index knows the out of office (banner)');
		$this->assertTrue(is_object($this->db->getRow("SELECT rowid FROM ".$this->db->prefix()."inbox_action_log WHERE action = 'rules' AND fk_account = ".((int) $a->id))));
		$this->assertTrue((bool) $this->db->getRow("SELECT rowid FROM ".$this->db->prefix()."inbox_notification WHERE type = 'rule_redirect' AND fk_account = ".((int) $a->id)),
			'a forward outside is told to the administrators');

		// A second save keeps the first one as previous version; undo brings it back
		$st2 = $r->save($this->admin, $a, array(), array_merge($away, array('on' => false)), false, 'away');
		$this->assertTrue($st2['previous']);
		$this->assertSame(array(), $st2['rules']);
		$this->assertNull($this->db->getRow("SELECT away_to FROM ".$this->db->prefix()."inbox_account WHERE rowid = ".((int) $a->id))->away_to, 'out of office off: no banner');
		$st3 = $r->undo($this->admin, $a);
		$this->assertNotNull($st3, $r->errorCode);
		$this->assertSame(3, count($st3['rules']));

		// Changed outside inbox: read only, never overwritten without « take over »
		$s = $c->sieveSession($error);
		$this->assertTrue($s->putScript('inbox', str_replace('stop;', 'keep;', (string) $s->getScript('inbox'))));
		$s->close();
		$this->assertSame('edited', $r->load($a)['state']);
		$this->assertNull($r->save($this->admin, $a, $rules, $away, false));
		$this->assertSame('edited', $r->errorCode);
		$this->assertNotNull($r->save($this->admin, $a, $rules, $away, true), 'replaced on demand');
		$this->assertSame('ok', $r->load($a)['state']);

		// The server checks the script: a value it refuses comes back as an error, nothing written
		$bob = $this->user('bob');
		$this->assertNull($r->account($bob, (int) $a->id), 'not a manager');
		$this->assertSame('not_found', $r->errorCode);
	}

	/**
	 * Preview and application on the mails of the index
	 *
	 * @return void
	 */
	public function testPreviewApply()
	{
		$a = $this->ready();
		$p = $this->db->prefix();
		// The test mails are in ZzActA: it plays the inbox here, received now
		$this->db->query("UPDATE ".$p."inbox_folder SET role = 'inbox' WHERE fk_account = ".((int) $a->id)." AND path = 'INBOX.ZzActA'");
		$this->db->query("UPDATE ".$p."inbox_mail SET date_received = '".$this->db->idate(dol_now() - 3600)."' WHERE fk_account = ".((int) $a->id));
		$r = new InboxRules($this->db);
		$rule = $r->cleanRules($a, array(array('name' => 'm1', 'on' => 1, 'all' => 1, 'conds' => array(array('f' => 'subject', 'op' => 'ends', 'v' => 'm1')),
			'acts' => array(array('a' => 'move', 'v' => 'INBOX.ZzActB'), array('a' => 'read')))))[0];
		$prev = $r->preview($a, $rule);
		$this->assertTrue($prev['supported']);
		$this->assertSame(1, $prev['count']);
		$this->assertSame('ZzAct m1', $prev['items'][0]['subject']);
		$all = $r->cleanRules($a, array(array('name' => 'x', 'on' => 1, 'all' => 1, 'conds' => array(array('f' => 'from', 'op' => 'contains', 'v' => 'CLIENT@')), 'acts' => array(array('a' => 'read')))))[0];
		$this->assertSame(3, $r->preview($a, $all)['count'], 'case ignored, name or address');
		$list = $r->cleanRules($a, array(array('name' => 'l', 'on' => 1, 'all' => 1, 'conds' => array(array('f' => 'list', 'op' => 'contains', 'v' => 'x')), 'acts' => array(array('a' => 'read')))))[0];
		$this->assertFalse($r->preview($a, $list)['supported'], 'the index does not hold the lists');

		$res = $r->apply($this->admin, $a, $rule);
		$this->assertSame(1, $res['count'], $res['error'] ?? '');
		$this->assertCount(1, $this->onServer('INBOX.ZzActB', 'm1'), 'moved on the server');
		$uids = $this->onServer('INBOX.ZzActB', 'm1');
		$this->assertStringContainsString('\\Seen', $this->flagsOnServer('INBOX.ZzActB', $uids[0]));
	}
}
