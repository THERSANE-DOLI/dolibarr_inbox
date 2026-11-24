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
 * \file    inbox/test/phpunit/InboxPieceInsertTest.php
 * \ingroup inbox
 * \brief   1.83.0 (lot A5): pieces of the thirdparties of the recipients, what a piece gives to a writing (recall, table
 *          of its lines with its totals, online links only when Dolibarr offers them)
 */

// From the command line only, never from the web: test/ stays in the Git branches
if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

require_once __DIR__.'/InboxTestCase.php';
dol_include_once('/inbox/class/inboxpieceinsert.class.php');
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT.'/commande/class/commande.class.php';

/**
 * Thirdparty and order « Zzpiece », removed after each test
 */
class InboxPieceInsertTest extends InboxTestCase
{
	/**
	 * @return void
	 */
	protected function tearDown(): void
	{
		$p = $this->db->prefix();
		$rows = $this->db->getRows("SELECT rowid FROM ".$p."societe WHERE nom LIKE 'Zzpiece%' LIMIT 20");
		foreach (is_array($rows) ? $rows : array() as $r) {
			$this->db->query("DELETE FROM ".$p."commandedet WHERE fk_commande IN (SELECT rowid FROM ".$p."commande WHERE fk_soc = ".((int) $r->rowid).")");
			$this->db->query("DELETE FROM ".$p."commande WHERE fk_soc = ".((int) $r->rowid));
			$this->db->query("DELETE FROM ".$p."societe WHERE rowid = ".((int) $r->rowid));
		}
		parent::tearDown();
	}

	/**
	 * @return void
	 */
	public function testPiecesOfRecipients()
	{
		$soc = new Societe($this->db);
		$soc->name = 'Zzpiece Atelier';
		$soc->email = 'achats@zzpiece.invalid';
		$soc->client = 1;
		$soc->code_client = -1;
		$this->assertGreaterThan(0, $soc->create($this->admin), (string) $soc->error);
		$o = new Commande($this->db);
		$o->socid = $soc->id;
		$o->date = dol_now();
		$this->assertGreaterThan(0, $o->create($this->admin), (string) $o->error.implode(",", (array) $o->errors));
		$o->addline('Tube inox 316L', 12.5, 4, 20, 0, 0, 0, 10);
		$o->addline('Bride', 30, 2, 20);
		$o->fetch($o->id);

		$ins = new InboxPieceInsert($this->db);
		$this->assertSame(array((int) $soc->id), $ins->socsOf(array('achats@zzpiece.invalid', 'nobody@elsewhere.invalid', 'not an address')));
		$found = $ins->search($this->admin, array((int) $soc->id), '', false);
		$this->assertSame(array((int) $o->id), array_column($found, 'id'), 'the orders of the thirdparty of the recipient');
		$this->assertSame('Zzpiece Atelier', $found[0]['soc']);
		$this->assertSame(array(), $ins->search($this->admin, array(), '', false), 'no thirdparty, nothing unless all');
		$this->assertSame(array(), $ins->search($this->admin, array((int) $soc->id), 'nothing-like-this', false));

		$loaded = $ins->load($this->admin, 'commande', (int) $o->id);
		$html = $ins->html($loaded, 'commande', array('summary' => true, 'lines' => true));
		$this->assertStringContainsString((string) $o->ref, $html, 'the line that recalls it');
		$this->assertStringContainsString('Tube inox 316L', $html);
		$this->assertStringContainsString('10 %', $html, 'the discount of the line');
		$this->assertStringContainsString('<table style="border-collapse:collapse', $html, 'styles in the table, for the mail software');
		$this->assertSame(3, substr_count($html, '<tfoot>') + substr_count($html, '</tr>', strpos($html, '<tfoot>')) - 1, 'three totals');
		// No online payment module: no link offered, even asked
		$opt = $ins->options($loaded, 'commande', '');
		$this->assertFalse($opt['pay']);
		$this->assertFalse($opt['sign']);
		$this->assertStringNotContainsString('<a href', $ins->html($loaded, 'commande', array('pay' => false, 'summary' => true)));
		// Another user without the right on the orders reads nothing
		$this->assertNull($ins->load($this->user('pieceins'), 'commande', (int) $o->id));
	}
}
