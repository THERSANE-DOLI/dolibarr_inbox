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
 * \file    inbox/test/phpunit/InboxPiecePeopleTest.php
 * \ingroup inbox
 * \brief   Colleagues of a piece (1.158.0): owner and users invited to an event with their answer, user of a ticket
 */

if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

require_once __DIR__.'/InboxOverviewTestCase.php';
require_once dirname(__DIR__, 2).'/class/inboxelements.class.php';
require_once DOL_DOCUMENT_ROOT.'/comm/action/class/actioncomm.class.php';

/**
 * Colleagues of an event and of a ticket in the description of a piece
 */
class InboxPiecePeopleTest extends InboxOverviewTestCase
{
	/** @var int[] Events made by the test */
	protected $events = array();

	/**
	 * @return void
	 */
	protected function tearDown(): void
	{
		foreach ($this->events as $id) {
			$this->db->query("DELETE FROM ".$this->db->prefix()."actioncomm_resources WHERE fk_actioncomm = ".((int) $id));
			$this->db->query("DELETE FROM ".$this->db->prefix()."actioncomm WHERE id = ".((int) $id));
		}
		parent::tearDown();
	}

	/**
	 * An event: its owner first, then the users invited with their answer, the label as reference and the date
	 *
	 * @return void
	 */
	public function testEventPeople()
	{
		$alice = $this->user('ppalice');
		$bob = $this->user('ppbob');
		$event = new ActionComm($this->db);
		$event->type_code = 'AC_OTH';
		$event->label = 'inboxtest meeting';
		$event->datep = dol_now();
		$event->percentage = -1;
		$event->userownerid = (int) $this->admin->id;
		$event->userassigned = array(
			(int) $this->admin->id => array('id' => (int) $this->admin->id),
			$alice->id => array('id' => $alice->id, 'answer_status' => 1),
			$bob->id => array('id' => $bob->id, 'answer_status' => -1),
		);
		$id = $event->create($this->admin, 1);
		$this->assertGreaterThan(0, $id, (string) $event->error.implode(", ", (array) $event->errors));
		$this->events[] = $id;

		$d = (new InboxElements($this->db))->describe($this->admin, 'action', $id);
		$this->assertSame('inboxtest meeting', $d['ref'], 'the label, never the id');
		$this->assertNotSame('', $d['title'], 'the date');
		$this->assertCount(3, $d['people']);
		$this->assertSame((int) $this->admin->id, $d['people'][0]['id']);
		$this->assertSame('owner', $d['people'][0]['role']);
		$byId = array_column($d['people'], null, 'id');
		$this->assertSame('attendee', $byId[$alice->id]['role']);
		$this->assertSame('accepted', $byId[$alice->id]['answer']);
		$this->assertSame('declined', $byId[$bob->id]['answer']);
		$this->assertArrayHasKey('photo', $byId[$alice->id]);

		$this->db->query("UPDATE ".$this->db->prefix()."user SET statut = 0 WHERE rowid = ".((int) $bob->id));
		$d = (new InboxElements($this->db))->describe($this->admin, 'action', $id);
		$this->assertCount(2, $d['people'], 'a disabled user is left out');
	}

	/**
	 * A ticket: the user assigned
	 *
	 * @return void
	 */
	public function testTicketPeople()
	{
		if (!isModEnabled('ticket')) {
			$this->markTestSkipped('module Tickets disabled on the test database');
		}
		require_once DOL_DOCUMENT_ROOT.'/ticket/class/ticket.class.php';
		$carol = $this->user('ppcarol');
		$ticket = new Ticket($this->db);
		$ticket->ref = $ticket->getDefaultRef();
		$ticket->subject = 'inboxtest ticket';
		$ticket->message = 'x';
		$ticket->fk_user_assign = $carol->id;
		$id = $ticket->create($this->admin, 1);
		$this->assertGreaterThan(0, $id, (string) $ticket->error.implode(", ", (array) $ticket->errors));
		try {
			$d = (new InboxElements($this->db))->describe($this->admin, 'ticket', $id);
			$this->assertCount(1, $d['people']);
			$this->assertSame('assigned', $d['people'][0]['role']);
			$this->assertSame($carol->id, $d['people'][0]['id']);
		} finally {
			$this->db->query("DELETE FROM ".$this->db->prefix()."ticket WHERE rowid = ".((int) $id));
		}
	}
}
