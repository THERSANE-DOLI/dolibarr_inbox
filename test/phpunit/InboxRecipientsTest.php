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
 * \file    inbox/test/phpunit/InboxRecipientsTest.php
 * \ingroup inbox
 * \brief   1.99.0: recipients proposed ranked by context (thirdparty of the mail answered, of the recipients given),
 *          exchanges and match of the words
 */

require_once dirname(__DIR__, 2).'/class/inboxcomposer.class.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT.'/contact/class/contact.class.php';

/**
 * Database only (no mail server)
 */
class InboxRecipientsTest extends InboxTestCase
{
	/**
	 * Words: 2 characters at least in all, 4 words at most, separators of addresses ignored
	 *
	 * @return void
	 */
	public function testWords()
	{
		$this->assertSame(array(), InboxComposer::recipientWords('a'));
		$this->assertSame(array('jean', 'dup'), InboxComposer::recipientWords('  jean   dup '));
		$this->assertSame(array('a', 'b', 'c', 'd'), InboxComposer::recipientWords('a b c d e'));
		$this->assertSame(array('Dupont', 'acme'), InboxComposer::recipientWords('"Dupont" <acme'));
	}

	/**
	 * Score: context first, then exchanges (fully only for a word starting a name), then match; old exchanges fade
	 *
	 * @return void
	 */
	public function testScore()
	{
		$now = dol_now();
		$base = array('email' => 'jean.dupont@acme.invalid', 'name' => 'Jean Dupont', 'detail' => 'Acme', 'socs' => array());
		$plain = InboxComposer::recipientScore($base, array('dup'), array(), array(), $now);
		$this->assertGreaterThan(0, $plain);
		$this->assertGreaterThan($plain, InboxComposer::recipientScore($base, array('dupont'), array(), array(), $now), 'whole word above prefix');
		$this->assertGreaterThan(InboxComposer::recipientScore($base, array('upon'), array(), array(), $now), $plain, 'prefix above inside');
		$this->assertSame(0.0, InboxComposer::recipientScore($base, array('zzz'), array(), array(), $now));
		$this->assertGreaterThan(0, InboxComposer::recipientScore($base, array('acme'), array(), array(), $now), 'thirdparty name counts');
		$this->assertGreaterThan(InboxComposer::recipientScore($base, array('nvalid'), array(), array(), $now), InboxComposer::recipientScore($base, array('jean'), array(), array(), $now), 'domain is a weak match');
		$this->assertSame(InboxComposer::recipientScore(array('name' => 'Jéan Dupont') + $base, array('jean'), array(), array(), $now), InboxComposer::recipientScore($base, array('jéan'), array(), array(), $now), 'accents ignored');
		$this->assertSame(InboxComposer::recipientScore($base, array('jean', 'dupont'), array(), array(), $now), InboxComposer::recipientScore($base, array('dupont', 'jean'), array(), array(), $now), 'words in any order');

		// Exchanges: recent above old, sent above received, capped
		$sent = $base + array('nb_sent' => 10, 'last_sent' => $now - 86400);
		$old = $base + array('nb_sent' => 10, 'last_sent' => $now - 3 * 365 * 86400);
		$recv = $base + array('nb_recv' => 10, 'last_recv' => $now - 86400);
		$this->assertGreaterThan(InboxComposer::recipientScore($old, array('dup'), array(), array(), $now), InboxComposer::recipientScore($sent, array('dup'), array(), array(), $now));
		$this->assertGreaterThan(InboxComposer::recipientScore($recv, array('dup'), array(), array(), $now), InboxComposer::recipientScore($sent, array('dup'), array(), array(), $now));
		$many = $base + array('nb_sent' => 5000, 'last_sent' => $now, 'nb_recv' => 5000, 'last_recv' => $now);
		$ctx = array('socs' => array(7 => true)) + $base;
		$this->assertGreaterThan(InboxComposer::recipientScore($many, array('dup'), array(), array(), $now), InboxComposer::recipientScore($ctx, array('dup'), array(7), array(), $now), 'context above any exchange');
		$this->assertGreaterThan(InboxComposer::recipientScore($ctx, array('dup'), array(), array(7), $now), InboxComposer::recipientScore($ctx, array('dup'), array(7), array(), $now), 'linked thirdparty counts less');
		// Inside match only: the exchanges count a quarter
		$inside = array('email' => 'x@gmail.invalid', 'name' => 'Xavier', 'detail' => '', 'socs' => array(), 'nb_sent' => 30, 'last_sent' => $now);
		$start = array('email' => 'maxime@b.invalid', 'name' => 'Maxime', 'detail' => '', 'socs' => array(), 'nb_sent' => 3, 'last_sent' => $now);
		$this->assertGreaterThan(InboxComposer::recipientScore($inside, array('ma'), array(), array(), $now), InboxComposer::recipientScore($start, array('ma'), array(), array(), $now));
	}

	/**
	 * Context of the recipients given: colleagues of the thirdparty first, then contacts of a linked thirdparty;
	 * nothing typed gives these contacts only; the addresses given never proposed again
	 *
	 * @return void
	 */
	public function testContext()
	{
		$p = $this->db->prefix();
		$a = $this->thirdparty('inboxtest_RcptAcme');
		$b = $this->thirdparty('inboxtest_RcptBeta');
		$given = $this->contact($a, 'Given', 'given@rcpt-test.invalid');
		$this->contact($a, 'Zorro', 'zorro.acme@rcpt-test.invalid');
		$this->contact($b, 'Zoe', 'zoe.beta@rcpt-test.invalid');
		$this->contact($this->thirdparty('inboxtest_RcptOther'), 'Zola', 'zola.other@rcpt-test.invalid');
		$type = $this->db->getRow("SELECT rowid FROM ".$p."c_type_contact WHERE element = 'societe' AND source = 'external' LIMIT 1");
		if (is_object($type)) {
			$this->db->query("INSERT INTO ".$p."societe_contacts (entity, date_creation, fk_soc, fk_c_type_contact, fk_socpeople) VALUES (1, '".$this->db->idate(dol_now())."', ".$b.", ".((int) $type->rowid).", ".$given.")");
		}
		$c = new InboxComposer($this->db);
		try {
			$emails = array_column($c->recipients($this->admin, 'inboxtest_Z', '', array('given@rcpt-test.invalid')), 'email');
			$this->assertSame('zorro.acme@rcpt-test.invalid', $emails[0] ?? '', 'colleague of the thirdparty first');
			if (is_object($type)) {
				$this->assertSame('zoe.beta@rcpt-test.invalid', $emails[1] ?? '', 'contact of a thirdparty the given contact is linked to');
			}
			$this->assertContains('zola.other@rcpt-test.invalid', $emails);
			$this->assertNotContains('given@rcpt-test.invalid', $emails, 'given address not proposed again');

			$empty = array_column($c->recipients($this->admin, '', '', array('given@rcpt-test.invalid')), 'email');
			$this->assertContains('zorro.acme@rcpt-test.invalid', $empty);
			$this->assertNotContains('zola.other@rcpt-test.invalid', $empty, 'nothing typed: the context only');
			$this->assertSame(array(), $c->recipients($this->admin, '', '', array()), 'nothing typed, no context: nothing');
			$this->assertSame(array(), $c->recipients($this->admin, 'z', '', array()), 'one character: nothing');

			$plain = array_column($c->recipients($this->admin, 'inboxtest_', '', array()), 'email');
			$this->assertCount(4, array_intersect($plain, array('given@rcpt-test.invalid', 'zorro.acme@rcpt-test.invalid', 'zoe.beta@rcpt-test.invalid', 'zola.other@rcpt-test.invalid')), 'no context: all found');
			$this->assertContains('zorro.acme@rcpt-test.invalid', array_column($c->recipients($this->admin, 'zorro inboxtest', '', array()), 'email'), 'two words in any order');
		} finally {
			$this->db->query("DELETE FROM ".$p."societe_contacts WHERE fk_socpeople = ".$given);
		}
	}

	/**
	 * @param string $name Name (inboxtest_: removed by the cleanup)
	 * @return int Id
	 */
	protected function thirdparty($name)
	{
		$soc = new Societe($this->db);
		$soc->name = $name;
		$soc->client = 1;
		$soc->code_client = -1;
		$this->assertGreaterThan(0, $soc->create($this->admin), (string) $soc->error.' '.implode(', ', (array) $soc->errors));

		return (int) $soc->id;
	}

	/**
	 * @param int    $socid     Thirdparty
	 * @param string $firstname First name
	 * @param string $email     Address
	 * @return int Id
	 */
	protected function contact($socid, $firstname, $email)
	{
		$ct = new Contact($this->db);
		$ct->socid = $socid;
		$ct->firstname = $firstname;
		$ct->lastname = 'inboxtest_'.$firstname;
		$ct->email = $email;
		$ct->statut = 1;
		$this->assertGreaterThan(0, $ct->create($this->admin), (string) $ct->error.' '.implode(', ', (array) $ct->errors));

		return (int) $ct->id;
	}
}
