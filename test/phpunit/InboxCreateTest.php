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
 * \file    inbox/test/phpunit/InboxCreateTest.php
 * \ingroup inbox
 * \brief   1.14.0: a piece created from a mail is linked to it (and gets the .eml), files put in a piece
 */

// From the command line only, never from the web: test/ stays in the Git branches
if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

require_once dirname(__DIR__, 2).'/class/inboxcreate.class.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once dirname(__DIR__, 2).'/class/inboxreader.class.php';
require_once dirname(__DIR__, 2).'/class/inboxagendapanel.class.php';

/**
 * Database and documents folder of the test instance, no mail server
 */
class InboxCreateTest extends InboxTestCase
{
	/** @var string[] Folders of documents made by the test */
	protected $dirs = array();

	/**
	 * @return void
	 */
	protected function tearDown(): void
	{
		foreach ($this->dirs as $dir) {
			dol_delete_dir_recursive($dir);
		}
		unset($_SESSION['inbox_create_intents']);
		parent::tearDown();
	}

	/**
	 * A creation uses the intent of the page of creation opened from the mail only (its token in the Referer), never
	 * the creation of a thirdparty made elsewhere meanwhile
	 *
	 * @return void
	 */
	public function testIntentOfItsPageOnly()
	{
		$base = 'https://erp.inbox-test.invalid/societe/card.php';
		$intents = array(
			array('type' => 'societe', 'token' => 'aaaa1111aaaa1111', 'path' => '/societe/card.php', 'at' => dol_now() - 60),
			array('type' => 'propal', 'token' => 'bbbb2222bbbb2222', 'path' => '/comm/propal/card.php', 'at' => dol_now() - 30),
			array('type' => 'societe', 'token' => 'cccc3333cccc3333', 'path' => '/societe/card.php', 'at' => dol_now() - 10),
		);
		$this->assertSame(0, InboxCreate::intentOf($intents, 'societe', $base.'?action=create&inboxintent=aaaa1111aaaa1111'), 'its token, even an older intent');
		$this->assertSame(2, InboxCreate::intentOf($intents, 'societe', $base.'?action=create&name=x&inboxintent=cccc3333cccc3333'));
		$this->assertNull(InboxCreate::intentOf($intents, 'societe', $base.'?action=create&inboxintent=bbbb2222bbbb2222'), 'the token of another type');
		$this->assertNull(InboxCreate::intentOf($intents, 'societe', $base.'?action=create'), 'a thirdparty created from the menu of Dolibarr');
		$this->assertNull(InboxCreate::intentOf($intents, 'societe', 'https://erp.inbox-test.invalid/custom/inbox/index.php?mainmenu=home'), 'created by the panel or a module');
		$this->assertSame(2, InboxCreate::intentOf($intents, 'societe', $base), 'the page shown again after an error: the last one');
		$this->assertSame(2, InboxCreate::intentOf($intents, 'societe', ''), 'no Referer: the last one');
		$intents[2]['at'] = dol_now() - InboxCreate::INTENT_UNTOKEN_TTL - 1;
		$this->assertNull(InboxCreate::intentOf($intents, 'societe', $base), 'without token: the last minutes only');
		$this->assertNull(InboxCreate::intentOf($intents, 'societe', ''), 'never an older one instead');
		$this->assertSame(2, InboxCreate::intentOf($intents, 'societe', $base.'?inboxintent=cccc3333cccc3333'), 'its token: the whole life of the intent');
	}

	/**
	 * @return void
	 */
	public function testCreateLinkAttach()
	{
		$p = $this->db->prefix();
		$devis = $this->box(true, array('status' => InboxAccount::STATUS_ACTIVE));
		(new InboxAccess($this->db))->grant($this->admin, $devis->id, $this->admin->id, 0, 'manage');
		InboxAccess::clearCache();
		$this->db->query("INSERT INTO ".$p."inbox_folder (fk_account, path, name, delimiter, role, status) VALUES (".((int) $devis->id).", 'INBOX', 'INBOX', '.', 'inbox', 1)");
		$folder = (int) $this->db->last_insert_id($p.'inbox_folder');
		$this->db->query("INSERT INTO ".$p."inbox_mail (fk_account, fk_folder, remote_id, sort_key, message_id, date_mail, from_email, from_name, subject, size, date_index) VALUES ("
			.((int) $devis->id).", ".$folder.", '1', 1, 'c1@inbox-test.invalid', '2026-11-02 10:00:00', 'jane@client.inbox-test.invalid', 'Jane Martin', 'Demande de prix inox', 10,"
			." '".$this->db->idate(dol_now())."')");
		$row = $this->db->getRow("SELECT * FROM ".$p."inbox_mail WHERE message_id = 'c1@inbox-test.invalid'");
		$refs = new InboxMailRef($this->db);
		$ref = $refs->fetchOrCreate($row, $this->admin);

		// Page of creation filled from the mail
		$creator = new InboxCreate($this->db);
		$url = $creator->createUrl('contact', $row, array('soc' => 12, 'contact' => 0));
		$this->assertStringContainsString('/contact/card.php?action=create', $url);
		$this->assertStringContainsString('firstname=Jane', $url);
		$this->assertStringContainsString('lastname=Martin', $url);
		$this->assertStringContainsString('socid=12', $url);
		$this->assertStringContainsString('title=Demande+de+prix+inox', $creator->createUrl('project', $row, array('soc' => 0)));
		$types = array_column(InboxCreate::creatable($this->admin), 'type');
		$this->assertContains('societe', $types);
		$this->assertSame(array(), InboxCreate::creatable($this->user('nobody')), 'no right, nothing to create');

		// The creation in the same session: linked, the .eml in its documents
		$this->db->query("INSERT INTO ".$p."societe (nom, entity, status, client, datec) VALUES ('inboxtest_created', 1, 1, 1, '".$this->db->idate(dol_now())."')");
		$soc = new Societe($this->db);
		$soc->fetch((int) $this->db->last_insert_id($p.'societe'));
		$tmp = sys_get_temp_dir().'/intent-'.bin2hex(random_bytes(8)).'.eml';
		file_put_contents($tmp, "Subject: Demande de prix inox\r\n\r\nBonjour\r\n");
		$_SESSION['inbox_create_intents'] = array(array('type' => 'societe', 'mailref' => $ref, 'mail' => (int) $row->rowid, 'subject' => 'Demande de prix inox',
			'file' => $tmp, 'at' => dol_now(), 'user' => $this->admin->id));
		$this->assertSame(1, $creator->onCreate($this->admin, 'COMPANY_CREATE', $soc));
		$dir = $creator->documentsDir('societe', $soc);
		$this->dirs[] = $dir;
		$this->assertSame(array('societe'), array_column((new InboxLink($this->db))->listFor(array($ref)), 'type'));
		$files = glob($dir.'/*.eml');
		$this->assertCount(1, $files, 'the mail in the documents of the thirdparty');
		$this->assertFileDoesNotExist($tmp, 'the file aside is removed');
		$this->assertSame(0, $creator->onCreate($this->admin, 'COMPANY_CREATE', $soc), 'one intent, one piece');

		// An intent naming any other file of the server: never read, never removed
		$other = sys_get_temp_dir().'/inbox-test-other-'.bin2hex(random_bytes(4)).'.txt';
		file_put_contents($other, 'secret');
		$_SESSION['inbox_create_intents'] = array(array('type' => 'societe', 'mailref' => $ref, 'mail' => 0, 'subject' => 'x', 'file' => $other, 'at' => dol_now(), 'user' => $this->admin->id));
		$creator->onCreate($this->admin, 'COMPANY_CREATE', $soc);
		$this->assertFileExists($other);
		$this->assertCount(1, glob($dir.'/*.eml'));
		@unlink($other);
		// The intent of another user of the session is not used
		$_SESSION['inbox_create_intents'] = array(array('type' => 'societe', 'mailref' => $ref, 'mail' => 0, 'subject' => 'x', 'file' => '', 'at' => dol_now(), 'user' => 999999));
		$this->assertSame(0, $creator->onCreate($this->admin, 'COMPANY_CREATE', $soc));

		// A file of a mail put in the documents of a piece; a name already there gets a number
		$this->assertSame('devis client.pdf', $creator->attach($this->admin, 'societe', $soc->id, 'devis client.pdf', '%PDF-1.4 test'));
		$this->assertSame('devis client (2).pdf', $creator->attach($this->admin, 'societe', $soc->id, 'devis client.pdf', '%PDF-1.4 test'));
		$this->assertSame('', $creator->attach($this->user('reader'), 'societe', $soc->id, 'x.pdf', 'x'));
		$this->assertSame('InboxErrorCannotAttach', $creator->error);
		$name = $creator->attach($this->admin, 'societe', $soc->id, '../../evil.php', 'x');
		$this->assertFileDoesNotExist(dirname($dir, 2).'/evil.php', 'a name never leaves the folder of the piece');
		if ($name !== '') {
			$this->assertFileExists($dir.'/'.$name);
		}
	}

	/**
	 * An event from a mail goes to the page of creation of the agenda module when it declares one (1.160.0): its
	 * address from the hook only, the token of the intent kept, the page of Dolibarr otherwise
	 *
	 * @return void
	 */
	public function testEventByAgendaProvider()
	{
		$p = $this->db->prefix();
		$devis = $this->box(true, array('status' => InboxAccount::STATUS_ACTIVE));
		(new InboxAccess($this->db))->grant($this->admin, $devis->id, $this->admin->id, 0, 'manage');
		InboxAccess::clearCache();
		$this->db->query("INSERT INTO ".$p."inbox_folder (fk_account, path, name, delimiter, role, status) VALUES (".((int) $devis->id).", 'INBOX', 'INBOX', '.', 'inbox', 1)");
		$folder = (int) $this->db->last_insert_id($p.'inbox_folder');
		$this->db->query("INSERT INTO ".$p."inbox_mail (fk_account, fk_folder, remote_id, sort_key, message_id, date_mail, from_email, from_name, subject, size, date_index) VALUES ("
			.((int) $devis->id).", ".$folder.", '1', 1, 'ev1@inbox-test.invalid', '2026-11-02 10:00:00', 'jane@client.inbox-test.invalid', 'Jane Martin', 'Visite', 10,"
			." '".$this->db->idate(dol_now())."')");
		$reader = new InboxReader($this->db);
		$reader->row = $this->db->getRow("SELECT * FROM ".$p."inbox_mail WHERE message_id = 'ev1@inbox-test.invalid'");
		$cache = new ReflectionProperty('InboxAgendaPanel', 'provider');
		$cache->setAccessible(true);
		$creator = new InboxCreate($this->db);
		try {
			$cache->setValue(null, array('label' => 'Calendar', 'source' => 'test', 'urls' => array('create' => '/custom/agendatest/event.php?action=create&socid={socid}#x')));
			$url = $creator->intent($this->admin, $reader, 'action', false, array('soc' => 0, 'contact' => 0));
			$this->assertStringStartsWith('/custom/agendatest/event.php?action=create&socid={socid}&inboxintent=', $url, 'the page of the module, its markers left to the browser');
			$this->assertStringEndsWith('#x', $url, 'the token before the fragment');
			$this->assertSame(array('label' => 'Calendar'), $creator->provider);
			$intent = end($_SESSION['inbox_create_intents']);
			$this->assertSame('action', $intent['type']);
			$this->assertSame('/custom/agendatest/event.php', $intent['path']);
			$this->assertNotNull(InboxCreate::intentOf($_SESSION['inbox_create_intents'], 'action', 'https://erp.inbox-test.invalid'.$url), 'its own token found back');

			// Another type, or no page of creation declared: the page of Dolibarr
			$this->assertStringContainsString('/societe/card.php', $creator->intent($this->admin, $reader, 'societe', false, array('soc' => 0, 'contact' => 0)));
			$this->assertNull($creator->provider);
			$cache->setValue(null, array());
			$this->assertStringContainsString('/comm/action/card.php?action=create', $creator->intent($this->admin, $reader, 'action', false, array('soc' => 0, 'contact' => 0)));
			$this->assertNull($creator->provider);
		} finally {
			$cache->setValue(null, null);
		}
		$this->assertContains('agenda_create', InboxCreate::CAPABILITIES, 'announced to the hooks of a mail');
	}

	/**
	 * Buttons of « Create » (1.161.0): the modules add buttons on the types of the core (several for a type), remove
	 * any button for the mailbox of the mail, the admin keeps one back; groups in their order
	 *
	 * @return void
	 */
	public function testButtonsOfModules()
	{
		$core = array();
		foreach (InboxCreate::creatable($this->admin) as $c) {
			$core[] = array_merge($c, array('id' => $c['type'], 'group' => InboxCreate::TYPE_GROUPS[$c['type']], 'source' => '', 'url' => '', 'window' => false));
		}
		$add = array(
			array('source' => 'quotes', 'id' => 'express', 'type' => 'propal', 'label' => 'Express quote', 'url' => '/custom/quotes/new.php?socid={socid}', 'group' => 'sales', 'window' => true),
			array('source' => 'quotes', 'id' => 'bad', 'type' => 'propal', 'label' => 'Far', 'url' => '//evil.example/x'),
			array('source' => 'quotes', 'id' => 'unknown', 'type' => 'nothing', 'label' => 'X', 'url' => '/x.php'),
			array('source' => 'quotes', 'id' => 'norights', 'type' => 'propal', 'label' => 'Y', 'url' => '/y.php', 'rights' => array(array('nomodule', 'noright'))),
			array('source' => 'other', 'id' => 'misc', 'type' => 'societe', 'label' => 'Misc', 'url' => '/m.php', 'group' => 'nogroup'),
		);
		// A module called first removes the button of a module called after it: both phases merged
		$remove = array(array('id' => 'propal', 'source' => 'quotes'), array('id' => 'other:misc', 'source' => 'quotes'));
		$shown = InboxCreate::assemble($this->admin, $core, $add, $remove, array(), false);
		$ids = array_column($shown, 'id');
		$this->assertContains('quotes:express', $ids, 'a second way to create a proposal');
		$this->assertNotContains('propal', $ids, 'removed by a module for this mailbox');
		$this->assertNotContains('other:misc', $ids, 'removed whatever the order of the modules');
		$this->assertNotContains('quotes:bad', $ids, 'an address of another site');
		$this->assertNotContains('quotes:unknown', $ids, 'a type inbox does not know');
		$this->assertNotContains('quotes:norights', $ids, 'without the rights the module asks');
		$groups = array_values(array_unique(array_column($shown, 'group')));
		$this->assertSame(array_values(array_intersect(InboxCreate::GROUPS, $groups)), $groups, 'groups in their order');

		$all = InboxCreate::assemble($this->admin, $core, $add, $remove, array('propal'), true);
		$byId = array_column($all, null, 'id');
		$this->assertSame('quotes', $byId['propal']['removed_by']);
		$this->assertTrue($byId['propal']['kept']);
		$this->assertSame('other', $byId['other:misc']['group'], 'an unknown group: Others');
		$this->assertContains('propal', array_column(InboxCreate::assemble($this->admin, $core, $add, $remove, array('propal'), false), 'id'), 'kept by the admin: shown');

		// Kept buttons of a mailbox, written in its journal
		$box = $this->box(true, array('status' => InboxAccount::STATUS_ACTIVE));
		$creator = new InboxCreate($this->db);
		$this->assertSame(1, $creator->keep($this->admin, (int) $box->id, 'propal', true));
		$this->assertSame(0, $creator->keep($this->admin, (int) $box->id, 'propal', true), 'already kept');
		$this->assertSame(array('propal'), $creator->kept((int) $box->id));
		$this->assertSame(-1, $creator->keep($this->admin, (int) $box->id, 'bad id!', true));
		$this->assertSame(1, $creator->keep($this->admin, (int) $box->id, 'propal', false));
		$this->assertSame(array(), $creator->kept((int) $box->id));
		$this->assertSame(array('propal', 'propal'), array_values(array_map(function ($l) {
			return json_decode((string) $l->value_after, true)['button'] ?? '';
		}, $this->db->getRows("SELECT value_after FROM ".$this->db->prefix()."inbox_account_log WHERE fk_account = ".((int) $box->id)." AND action = 'create_keep' ORDER BY rowid LIMIT 10") ?: array())));
		$this->assertContains('create_buttons', InboxCreate::CAPABILITIES);
	}
}
