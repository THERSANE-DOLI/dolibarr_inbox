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
 * \file    inbox/test/phpunit/InboxAgendaPanelTest.php
 * \ingroup inbox
 * \brief   Agenda panel (1.107.0): events of the user, filters, case « done », what a module may give
 */

require_once __DIR__.'/InboxTestCase.php';
dol_include_once('/inbox/class/inboxagendapanel.class.php');

/**
 * Agenda panel
 */
class InboxAgendaPanelTest extends InboxTestCase
{
	const LABEL = 'ZzAgenda ';

	/** @var int Start of today */
	private $today;

	/**
	 * @return void
	 */
	protected function setUp(): void
	{
		parent::setUp();
		$this->clean();
		$this->today = (int) dol_mktime(0, 0, 0, (int) date('n'), (int) date('j'), (int) date('Y'));
	}

	/**
	 * @return void
	 */
	protected function tearDown(): void
	{
		$this->clean();
		parent::tearDown();
	}

	/**
	 * @return void
	 */
	private function clean()
	{
		$p = $this->db->prefix();
		$this->db->query("DELETE FROM ".$p."actioncomm_resources WHERE fk_actioncomm IN (SELECT id FROM ".$p."actioncomm WHERE label LIKE '".self::LABEL."%')");
		$this->db->query("DELETE FROM ".$p."actioncomm WHERE label LIKE '".self::LABEL."%'");
	}

	/**
	 * An event written as Dolibarr keeps it
	 *
	 * @param string $label   Label (after the prefix)
	 * @param string $code    Type
	 * @param int    $start   Start
	 * @param int    $percent Progress (-1 none)
	 * @param int    $owner   Owner
	 * @param array<int,int> $attendees User => answer
	 * @param string $msgid   Message-ID
	 * @return int
	 */
	private function event($label, $code, $start, $percent, $owner, $attendees = array(), $msgid = '')
	{
		$p = $this->db->prefix();
		// A code out of the dictionary (AC_INBOX_BOUNCE) is kept on the type « other », as inbox writes it
		$row = $this->db->getRow("SELECT id FROM ".$p."c_actioncomm WHERE code IN ('".$this->db->escape($code)."', 'AC_OTH') ORDER BY code = 'AC_OTH'");
		$type = (int) $row->id;
		$this->db->query("INSERT INTO ".$p."actioncomm (ref, entity, datep, datep2, fk_action, code, label, percent, fk_user_action, fk_user_author, datec, email_msgid)"
			." VALUES ('(PROV)', 1, '".$this->db->idate($start)."', '".$this->db->idate($start + 3600)."', ".$type.", '".$this->db->escape($code)."', '".$this->db->escape(self::LABEL.$label)."', "
			.((int) $percent).", ".((int) $owner).", ".((int) $owner).", '".$this->db->idate(dol_now())."', ".($msgid !== '' ? "'".$this->db->escape($msgid)."'" : 'NULL').")");
		$id = (int) $this->db->last_insert_id($p.'actioncomm');
		$this->db->query("UPDATE ".$p."actioncomm SET ref = '".$id."' WHERE id = ".$id);
		foreach ($attendees as $u => $answer) {
			$this->db->query("INSERT INTO ".$p."actioncomm_resources (fk_actioncomm, element_type, fk_element, mandatory, transparency, answer_status) VALUES (".$id.", 'user', ".((int) $u).", 0, 0, ".((int) $answer).")");
		}

		return $id;
	}

	/**
	 * @param array<string,mixed> $res Result of events()
	 * @return string[] Labels without the prefix
	 */
	private static function labels($list)
	{
		return array_values(array_map(function ($e) {
			return substr($e['label'], strlen(self::LABEL));
		}, array_filter($list, function ($e) {
			return strpos($e['label'], self::LABEL) === 0;
		})));
	}

	/**
	 * Mine, the ones I attend (not declined), the late ones to do; never the automatic events, the records of mails,
	 * the events of others
	 *
	 * @return void
	 */
	public function testWhatIsShown()
	{
		$me = (int) $this->admin->id;
		$other = (int) $this->db->getRow("SELECT rowid FROM ".$this->db->prefix()."user WHERE rowid <> ".$me." AND statut = 1 ORDER BY rowid")->rowid;
		$at = $this->today + 15 * 3600;
		$this->event('rdv', 'AC_RDV', $at, -1, $me);
		$this->event('call tomorrow', 'AC_TEL', $at + 86400, 0, $me);
		$this->event('auto', 'AC_OTH_AUTO', $at, -1, $me);
		$this->event('mail sent', 'AC_EMAIL', $at, 0, $me);
		$this->event('bounce', 'AC_INBOX_BOUNCE', $at, -1, $me);
		$this->event('of another', 'AC_RDV', $at, -1, $other);
		$this->event('invited', 'AC_RDV', $at + 60, -1, $other, array($me => 1));
		$this->event('maybe', 'AC_RDV', $at + 120, -1, $other, array($me => 2));
		$this->event('declined', 'AC_RDV', $at, -1, $other, array($me => -1));
		$this->event('late todo', 'AC_TEL', $this->today - 3 * 86400, 0, $me);
		$this->event('late done', 'AC_TEL', $this->today - 3 * 86400, 100, $me);
		$this->event('late no progress', 'AC_RDV', $this->today - 3 * 86400, -1, $me);
		$this->event('far', 'AC_RDV', $this->today + 20 * 86400, -1, $me);

		$res = (new InboxAgendaPanel($this->db))->events($this->admin, $this->today, 7);
		$this->assertSame(array('rdv', 'invited', 'maybe', 'call tomorrow'), self::labels($res['events']));
		$this->assertSame(array('late todo'), self::labels($res['late']));
		$this->assertGreaterThanOrEqual(1, $res['lateTotal']);

		$byLabel = array();
		foreach ($res['events'] as $e) {
			$byLabel[substr($e['label'], strlen(self::LABEL))] = $e;
		}
		$this->assertSame(2, $byLabel['maybe']['answer']);
		$this->assertSame(1, $byLabel['rdv']['answer']);
		$this->assertFalse($byLabel['rdv']['canDone'], 'no progress: no case');
		$this->assertTrue($byLabel['call tomorrow']['canDone']);
		$this->assertSame('dolibarr', $byLabel['rdv']['source']);
		$this->assertStringContainsString('/comm/action/card.php?id=', $byLabel['rdv']['url']);
	}

	/**
	 * The mail of an event is found by its Message-ID, brackets or not; nothing when no mailbox of the user has it
	 *
	 * @return void
	 */
	public function testMailOfAnEvent()
	{
		$this->box(true, array('type' => InboxAccount::TYPE_PERSONAL, 'fk_user_owner' => (int) $this->admin->id, 'status' => InboxAccount::STATUS_ACTIVE));
		InboxAccess::clearCache();
		$mailbox = (int) $this->db->getRow("SELECT rowid FROM ".$this->db->prefix()."inbox_account WHERE email = '".self::BOX."'")->rowid;
		$this->db->query("INSERT INTO ".$this->db->prefix()."inbox_folder (fk_account, path, name, role) VALUES (".$mailbox.", 'INBOX', 'INBOX', 'inbox')");
		$folder = (int) $this->db->getRow("SELECT rowid FROM ".$this->db->prefix()."inbox_folder WHERE fk_account = ".$mailbox)->rowid;
		$this->assertGreaterThan(0, $folder);
		$this->assertTrue((bool) $this->db->query("INSERT INTO ".$this->db->prefix()."inbox_mail (fk_account, fk_folder, remote_id, message_id, subject, sort_key, date_index) VALUES (".$mailbox.", ".$folder.", 999001, 'zzagenda-1@inbox-test.invalid', 'x', 1, '".$this->db->idate(dol_now())."')"), (string) $this->db->lasterror());
		$mail = (int) $this->db->getRow("SELECT rowid FROM ".$this->db->prefix()."inbox_mail WHERE fk_account = ".$mailbox." AND remote_id = 999001")->rowid;
		$at = $this->today + 16 * 3600;
		$this->event('with mail', 'AC_RDV', $at, -1, (int) $this->admin->id, array(), '<zzagenda-1@inbox-test.invalid>');
		$this->event('mail elsewhere', 'AC_RDV', $at, -1, (int) $this->admin->id, array(), 'zzagenda-2@inbox-test.invalid');
		$res = (new InboxAgendaPanel($this->db))->events($this->admin, $this->today, 1);
		$found = array();
		foreach ($res['events'] as $e) {
			$found[substr($e['label'], strlen(self::LABEL))] = $e['mail'];
		}
		$this->assertSame(array('folder' => $folder, 'mail' => $mail), $found['with mail']);
		$this->assertNull($found['mail elsewhere']);
	}

	/**
	 * Case « done » and its undo on an event of mine; refused on an event of another, or without progress
	 *
	 * @return void
	 */
	public function testSetPercent()
	{
		$me = (int) $this->admin->id;
		$other = (int) $this->db->getRow("SELECT rowid FROM ".$this->db->prefix()."user WHERE rowid <> ".$me." AND statut = 1 ORDER BY rowid")->rowid;
		$mine = $this->event('to do', 'AC_TEL', $this->today + 10 * 3600, 30, $me, array($other => 1));
		$hers = $this->event('hers', 'AC_TEL', $this->today + 10 * 3600, 0, $other);
		$none = $this->event('no progress', 'AC_RDV', $this->today + 10 * 3600, -1, $me);
		$panel = new InboxAgendaPanel($this->db);

		$this->assertSame(30, $panel->setPercent($this->admin, $mine, 100));
		$this->assertSame(100, (int) $this->db->getRow("SELECT percent FROM ".$this->db->prefix()."actioncomm WHERE id = ".$mine)->percent);
		// The attendee and its answer kept by the update of the core
		$this->assertSame('1', (string) $this->db->getRow("SELECT answer_status FROM ".$this->db->prefix()."actioncomm_resources WHERE fk_actioncomm = ".$mine." AND fk_element = ".$other)->answer_status);
		$this->assertSame(100, $panel->setPercent($this->admin, $mine, 30));
		$this->assertSame(30, (int) $this->db->getRow("SELECT percent FROM ".$this->db->prefix()."actioncomm WHERE id = ".$mine)->percent);

		$this->assertSame(-1, $panel->setPercent($this->admin, $hers, 100));
		$this->assertSame(-1, $panel->setPercent($this->admin, $none, 100));
		$this->assertSame(-1, $panel->setPercent($this->admin, $mine, 101));
	}

	/**
	 * Who may change an event (audit INBX-094): as Dolibarr, never an attendee only
	 *
	 * @return void
	 */
	public function testMayChangeAsDolibarr()
	{
		$u = new class ($this->db) extends User {
			/** @var string[] */
			public $granted = array();
			/**
			 * @param string $module Module
			 * @param string $perm   Permission
			 * @param string $sub    Sub permission
			 * @return int
			 */
			public function hasRight($module, $perm, $sub = '')
			{
				return in_array($module.'.'.$perm.'.'.$sub, $this->granted, true) ? 1 : 0;
			}
		};
		$u->id = 42;
		$owner = (object) array('fk_user_action' => 42, 'fk_user_author' => 7);
		$author = (object) array('fk_user_action' => 7, 'fk_user_author' => 42);
		$attendee = (object) array('fk_user_action' => 7, 'fk_user_author' => 7);
		$this->assertFalse(InboxAgendaPanel::mayChange($owner, $u), 'no right');
		$u->granted = array('agenda.myactions.create');
		$this->assertTrue(InboxAgendaPanel::mayChange($owner, $u));
		$this->assertTrue(InboxAgendaPanel::mayChange($author, $u));
		$this->assertFalse(InboxAgendaPanel::mayChange($attendee, $u), 'attendee only');
		$u->granted = array('agenda.allactions.create');
		$this->assertTrue(InboxAgendaPanel::mayChange($attendee, $u), 'every event');
	}

	/**
	 * What a module gives: known fields only, an address of this site only, never ticked by inbox
	 *
	 * @return void
	 */
	public function testModuleValues()
	{
		$this->assertSame('/custom/x/page.php?event={id}', InboxAgendaPanel::localUrl('/custom/x/page.php?event={id}'));
		foreach (array('https://evil.example/x', '//evil.example/x', '/\\evil.example', 'javascript:alert(1)', "/x\ny", 'x.php', '') as $bad) {
			$this->assertSame('', InboxAgendaPanel::localUrl($bad), $bad);
		}
		$panel = new InboxAgendaPanel($this->db);
		$e = $panel->clean(array('id' => 'ev:12', 'source' => 'dolibarr', 'label' => '<b>Meeting</b>', 'start' => 1000, 'end' => 500, 'percent' => 250,
			'color' => 'red', 'url' => 'https://evil.example', 'thirdparty' => array('id' => 3, 'name' => '<i>ACME</i>'), 'canDone' => true, 'answer' => 7, 'extra' => 'x'));
		$this->assertSame('module', $e['source'], 'a module never says it is the agenda of Dolibarr');
		$this->assertSame('Meeting', $e['label']);
		$this->assertSame(1000, $e['end']);
		$this->assertSame(100, $e['percent']);
		$this->assertSame('', $e['color']);
		$this->assertSame('', $e['url']);
		$this->assertSame('ACME', $e['thirdparty']['name']);
		$this->assertFalse($e['canDone']);
		$this->assertSame(1, $e['answer']);
		$this->assertArrayNotHasKey('extra', $e);
		$this->assertNull($panel->clean(array('label' => 'no date')));
	}
}
