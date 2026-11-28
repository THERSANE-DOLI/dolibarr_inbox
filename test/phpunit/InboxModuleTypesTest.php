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
 * \file    inbox/test/phpunit/InboxModuleTypesTest.php
 * \ingroup inbox
 * \brief   Objects of modules as pieces (1.164.0): declarations checked, registry, rights, search, creation
 */

if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

require_once __DIR__.'/InboxTestCase.php';
require_once dirname(__DIR__, 2).'/class/inboxelements.class.php';
require_once dirname(__DIR__, 2).'/class/inboxcreate.class.php';

/**
 * A module declares its object as a type of piece
 */
class InboxModuleTypesTest extends InboxTestCase
{
	/**
	 * @return void
	 */
	protected function tearDown(): void
	{
		InboxElements::reset();
		$this->db->query("DELETE FROM ".$this->db->prefix()."societe WHERE nom LIKE 'inboxtest_modtype%'");
		parent::tearDown();
	}

	/**
	 * A valid declaration, as a module would give it (here on the table of the thirdparties, for the test)
	 *
	 * @param array<string,mixed> $over Fields changed
	 * @return array<string,mixed>
	 */
	protected function declaration(array $over = array())
	{
		return array_merge(array('source' => 'mymodule', 'name' => 'myobject', 'element' => 'societe', 'module' => 'societe', 'label' => 'My <b>object</b>',
			'rights' => array(array('societe', 'lire')), 'write' => array(array('societe', 'creer')), 'access' => array('societe', '&societe'),
			'table' => 'societe', 'search' => array('nom'), 'soc' => 'rowid', 'entity' => 'societe', 'icon' => 'briefcase',
			'create' => array('url' => '/custom/mymodule/card.php?action=create&socid={socid}', 'trigger' => 'MYOBJECT_CREATE'), 'delete' => 'MYOBJECT_DELETE'), $over);
	}

	/**
	 * Declarations checked: the type is « source_name », never one of the core; fields of the database, rights and
	 * addresses by strict patterns
	 *
	 * @return void
	 */
	public function testDeclarationsChecked()
	{
		$types = InboxElements::normalizeTypes(array(
			$this->declaration(),
			$this->declaration(array('name' => 'badtable', 'table' => 'societe; DROP TABLE x')),
			$this->declaration(array('name' => 'badsearch', 'search' => array('nom OR 1=1'))),
			$this->declaration(array('name' => 'norights', 'rights' => array())),
			$this->declaration(array('name' => 'badright', 'rights' => array(array('societe', 'lire', 'x', 'y')))),
			$this->declaration(array('name' => 'badelement', 'element' => '../x')),
			$this->declaration(array('name' => 'farurl', 'create' => array('url' => '//evil.example/x', 'trigger' => 'X_CREATE'))),
		));
		$this->assertSame(array('mymodule_myobject', 'mymodule_farurl'), array_keys($types));
		$t = $types['mymodule_myobject'];
		$this->assertSame('My object', $t['label'], 'no HTML in the label');
		$this->assertSame('MYOBJECT_CREATE', $t['create']['trigger']);
		$this->assertSame('MYOBJECT_DELETE', $t['delete']);
		$this->assertArrayNotHasKey('create', $types['mymodule_farurl'], 'a page of another site is not kept');
		$this->assertSame(array(), InboxElements::normalizeTypes(array($this->declaration(array('source' => 'order', 'name' => 'supplier')))), 'never a type of the core');
	}

	/**
	 * Registered, the type follows the rights it declares, is searched and described, and can be created from a mail
	 *
	 * @return void
	 */
	public function testRegistry()
	{
		$this->db->query("INSERT INTO ".$this->db->prefix()."societe (nom, entity, status, client, datec) VALUES ('inboxtest_modtype Alpha', 1, 1, 1, '".$this->db->idate(dol_now())."')");
		$id = (int) $this->db->last_insert_id($this->db->prefix().'societe');
		InboxElements::reset(array_merge(InboxElements::TYPES, InboxElements::normalizeTypes(array($this->declaration(),
			$this->declaration(array('name' => 'hijack', 'create' => array('url' => '/x.php', 'trigger' => 'COMPANY_CREATE')))))));
		$reader = $this->user('modtypereader');
		$this->assertTrue(InboxElements::canReadType($this->admin, 'mymodule_myobject'));
		$this->assertFalse(InboxElements::canReadType($reader, 'mymodule_myobject'), 'without the right it declares');
		$this->assertTrue(InboxElements::canWriteType($this->admin, 'mymodule_myobject'));
		$this->assertTrue(InboxElements::canRead($this->admin, 'mymodule_myobject', $id));

		$d = (new InboxElements($this->db))->describe($this->admin, 'mymodule_myobject', $id);
		$this->assertSame('My object', $d['kind']);
		$this->assertSame('briefcase', $d['icon']);
		$this->assertTrue($d['readable']);
		$found = array_filter((new InboxElements($this->db))->search($this->admin, 'inboxtest_modtype Alpha', 0, 20), function ($r) {
			return $r['type'] === 'mymodule_myobject';
		});
		$this->assertCount(1, $found, 'searched as the pieces of the core');

		$create = InboxCreate::types();
		$this->assertSame('MYOBJECT_CREATE', $create['mymodule_myobject']['trigger']);
		$this->assertArrayNotHasKey('mymodule_hijack', $create, 'never the trigger of a type of the core');
		$this->assertSame('COMPANY_CREATE', InboxCreate::types()['societe']['trigger']);
	}
}
