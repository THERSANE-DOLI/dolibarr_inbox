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
 * \file    inbox/test/phpunit/InboxTemplateDefaultsTest.php
 * \ingroup inbox
 * \brief   1.118.0: default templates on four levels, templates that are a complete mail
 */

require_once __DIR__.'/InboxTestCase.php';
dol_include_once('/inbox/class/inboxaccount.class.php');
dol_include_once('/inbox/class/inboxtemplates.class.php');
dol_include_once('/inbox/class/inboxtemplatedefaults.class.php');

/**
 * No mail server needed
 */
class InboxTemplateDefaultsTest extends InboxTestCase
{
	/**
	 * @return void
	 */
	public function testUsageOfMode()
	{
		$this->assertSame('new', InboxTemplateDefaults::usageOfMode('new'));
		foreach (array('reply', 'replyall', 'replylist') as $m) {
			$this->assertSame('reply', InboxTemplateDefaults::usageOfMode($m));
		}
		foreach (array('forward', 'forward_attach', 'draft', 'editnew', 'unsubscribe', 'bad') as $m) {
			$this->assertSame('', InboxTemplateDefaults::usageOfMode($m), $m);
		}
		$this->assertSame('user_box', InboxTemplateDefaults::levelOf(3, 4));
		$this->assertSame('box', InboxTemplateDefaults::levelOf(3, 0));
		$this->assertSame('user', InboxTemplateDefaults::levelOf(0, 4));
		$this->assertSame('all', InboxTemplateDefaults::levelOf(0, 0));
	}

	/**
	 * Most precise level first, the mailbox before the user, « no template » stops, a broken default is skipped
	 *
	 * @return void
	 */
	public function testLevels()
	{
		if (!$this->admin->hasRight('inbox', 'account', 'admin')) {
			$this->markTestSkipped('The user of the tests is no administrator of inbox');
		}
		$a = $this->box(true, array('status' => InboxAccount::STATUS_ACTIVE));
		$b = $this->box(true, array('email' => 'other@inbox-test.invalid', 'status' => InboxAccount::STATUS_ACTIVE));
		$writer = $this->user('tdefwriter');
		$access = new InboxAccess($this->db);
		$access->grant($this->admin, $a->id, $writer->id, 0, InboxAccess::LEVEL_SEND);
		$access->grant($this->admin, $b->id, $writer->id, 0, InboxAccess::LEVEL_SEND);
		InboxAccess::clearCache();
		$tpl = new InboxTemplates($this->db);
		$everywhere = $tpl->save($this->admin, array('label' => 'ZzTpl everywhere', 'html' => '<p>a</p>', 'accounts' => array()));
		$inA = $tpl->save($this->admin, array('label' => 'ZzTpl in A', 'html' => '<p>b</p>', 'accounts' => array((int) $a->id)));
		$mine = $tpl->save($writer, array('label' => 'ZzTpl mine', 'html' => '<p>c</p>', 'private' => 1));
		$inB = $tpl->save($this->admin, array('label' => 'ZzTpl in B', 'html' => '<p>d</p>', 'accounts' => array((int) $b->id)));
		$this->assertGreaterThan(0, $everywhere * $inA * $mine * $inB, $tpl->errorCode);
		$d = new InboxTemplateDefaults($this->db);

		$this->assertSame(array('id' => 0, 'level' => ''), $d->resolve($writer, (int) $a->id, 'new'), 'nothing set');
		$this->assertTrue($d->set($this->admin, (int) $a->id, 'all', 'new', $everywhere), $d->errorCode);
		$this->assertSame(array('id' => $everywhere, 'level' => 'all'), $d->resolve($writer, (int) $a->id, 'new'));
		$this->assertTrue($d->set($writer, (int) $a->id, 'user', 'new', $mine), $d->errorCode);
		$this->assertSame(array('id' => $mine, 'level' => 'user'), $d->resolve($writer, (int) $a->id, 'new'));
		// The mailbox before the user
		$this->assertTrue($d->set($this->admin, (int) $a->id, 'box', 'new', $inA), $d->errorCode);
		$this->assertSame(array('id' => $inA, 'level' => 'box'), $d->resolve($writer, (int) $a->id, 'new'));
		$this->assertSame(array('id' => $mine, 'level' => 'user'), $d->resolve($writer, (int) $b->id, 'new'), 'the default of A does not go to B');
		// « No template » for me in A stops there; cleared, the mailbox answers again
		$this->assertTrue($d->set($writer, (int) $a->id, 'user_box', 'new', 0), $d->errorCode);
		$this->assertSame(array('id' => 0, 'level' => 'user_box'), $d->resolve($writer, (int) $a->id, 'new'));
		$this->assertTrue($d->set($writer, (int) $a->id, 'user_box', 'new', null), $d->errorCode);
		$this->assertSame($inA, $d->resolve($writer, (int) $a->id, 'new')['id']);
		// Each kind of writing on its own
		$this->assertSame(array('id' => 0, 'level' => ''), $d->resolve($writer, (int) $a->id, 'reply'));
		$this->assertSame(array('id' => 0, 'level' => ''), $d->resolve($writer, (int) $a->id, 'forward'));
		// Another user has his own: the private template of the writer never reaches him
		$other = $this->user('tdefother');
		$access->grant($this->admin, $a->id, $other->id, 0, InboxAccess::LEVEL_SEND);
		$access->grant($this->admin, $b->id, $other->id, 0, InboxAccess::LEVEL_SEND);
		InboxAccess::clearCache();
		$this->assertSame($everywhere, $d->resolve($other, (int) $b->id, 'new')['id']);

		// A default no longer usable is skipped: inactive, then removed
		$this->db->query("UPDATE ".$this->db->prefix()."c_email_templates SET active = 0 WHERE rowid = ".$inA);
		$this->assertSame(array('id' => $mine, 'level' => 'user'), $d->resolve($writer, (int) $a->id, 'new'));
		$this->assertTrue($tpl->delete($writer, $mine));
		$this->assertSame(0, (int) $this->db->getRow("SELECT COUNT(*) as n FROM ".$this->db->prefix()."inbox_template_default WHERE fk_template = ".$mine)->n, 'its defaults go with it');
		$this->assertSame(array('id' => $everywhere, 'level' => 'all'), $d->resolve($writer, (int) $a->id, 'new'));

		// Rights and templates allowed at each level
		$this->assertFalse($d->set($writer, (int) $a->id, 'box', 'new', $everywhere));
		$this->assertSame('no_right', $d->errorCode);
		$this->assertFalse($d->set($writer, (int) $a->id, 'all', 'new', $everywhere));
		$this->assertSame('no_right', $d->errorCode);
		$this->assertFalse($d->set($writer, (int) $a->id, 'user', 'new', $inB), 'a template of one mailbox is no default of every mailbox');
		$this->assertSame('bad_template', $d->errorCode);
		$this->assertFalse($d->set($writer, (int) $a->id, 'user_box', 'new', $inB), 'not offered in A');
		$this->assertSame('bad_template', $d->errorCode);
		$this->assertFalse($d->set($writer, (int) $a->id, 'user_box', 'later', $everywhere));
		$this->assertSame('bad_value', $d->errorCode);
		$private = $tpl->save($writer, array('label' => 'ZzTpl private', 'html' => '<p>e</p>', 'private' => 1));
		$this->assertFalse($d->set($this->admin, (int) $a->id, 'all', 'new', $private), 'a private template is no default of everyone');
		$stranger = $this->user('tdefstranger');
		$this->assertFalse($d->set($stranger, (int) $a->id, 'user_box', 'new', $everywhere), 'no access to the mailbox');
		$this->assertFalse($d->view($stranger, (int) $a->id), 'a mailbox refused is not found');

		// View of the dialog: the four levels, the one used now
		$view = $d->view($writer, (int) $a->id);
		$this->assertSame(InboxTemplateDefaults::LEVELS, array_column($view['levels'], 'level'));
		$this->assertSame(array(1, 0, 1, 0), array_column($view['levels'], 'editable'));
		$this->assertSame($everywhere, $view['levels'][3]['values']['new'], 'shown to who may not change it');
		$this->assertSame(array(), $view['levels'][1]['choices'], 'no choice on a level he may not change');
		$this->assertSame('ZzTpl everywhere', ((array) $view['labels'])[$everywhere]);
		$this->assertSame(array('id' => $everywhere, 'level' => 'all'), $view['effective']['new']);

		// The mailbox removed: its rows go
		$this->db->query("UPDATE ".$this->db->prefix()."c_email_templates SET active = 1 WHERE rowid = ".$inA);
		$a->status = InboxAccount::STATUS_DISABLED;
		$a->update($this->admin);
		$this->assertGreaterThan(0, $a->delete($this->admin));
		$this->assertSame(0, (int) $this->db->getRow("SELECT COUNT(*) as n FROM ".$this->db->prefix()."inbox_template_default WHERE fk_account = ".((int) $a->id))->n);
	}

	/**
	 * Under the four levels, the template of Dolibarr marked « default for the type », for a new mail only
	 *
	 * @return void
	 */
	public function testCoreDefaultForType()
	{
		$a = $this->box(true, array('status' => InboxAccount::STATUS_ACTIVE));
		$writer = $this->user('tdefcore');
		(new InboxAccess($this->db))->grant($this->admin, $a->id, $writer->id, 0, InboxAccess::LEVEL_SEND);
		InboxAccess::clearCache();
		$tpl = new InboxTemplates($this->db);
		$id = $tpl->save($this->admin, array('label' => 'ZzTpl core default', 'html' => '<p>a</p>', 'accounts' => array((int) $a->id)));
		$this->assertGreaterThan(0, $id, $tpl->errorCode);
		$this->db->query("UPDATE ".$this->db->prefix()."c_email_templates SET defaultfortype = 1 WHERE rowid = ".$id);
		$d = new InboxTemplateDefaults($this->db);
		$this->assertSame(array('id' => $id, 'level' => 'core'), $d->resolve($writer, (int) $a->id, 'new'));
		$this->assertSame(0, $d->resolve($writer, (int) $a->id, 'reply')['id'], 'new mails only');
		// « No template » of a level wins over it
		$this->assertTrue($d->set($writer, (int) $a->id, 'user_box', 'new', 0), $d->errorCode);
		$this->assertSame(array('id' => 0, 'level' => 'user_box'), $d->resolve($writer, (int) $a->id, 'new'));
	}

	/**
	 * Complete mail or piece of text: kept by inbox beside the template of Dolibarr
	 *
	 * @return void
	 */
	public function testWhole()
	{
		$a = $this->box(true, array('status' => InboxAccount::STATUS_ACTIVE));
		$tpl = new InboxTemplates($this->db);
		$id = $tpl->save($this->admin, array('label' => 'ZzTpl whole', 'subject' => 'S', 'html' => '<p>a</p>', 'accounts' => array((int) $a->id), 'whole' => 1));
		$this->assertGreaterThan(0, $id, $tpl->errorCode);
		$this->assertSame(1, $tpl->get($this->admin, $id)['whole']);
		$this->assertSame(1, array_column($tpl->listForAccount($this->admin, (int) $a->id), 'whole', 'id')[$id]);
		// Not given: unchanged; given: changed
		$this->assertSame($id, $tpl->save($this->admin, array('id' => $id, 'label' => 'ZzTpl whole', 'html' => '<p>b</p>', 'accounts' => array((int) $a->id))));
		$this->assertSame(1, $tpl->get($this->admin, $id)['whole']);
		$this->assertSame($id, $tpl->save($this->admin, array('id' => $id, 'label' => 'ZzTpl whole', 'html' => '<p>b</p>', 'accounts' => array((int) $a->id), 'whole' => 0)));
		$this->assertSame(0, $tpl->get($this->admin, $id)['whole']);
		// A template without option row is a piece of text (written before 1.118.0 or in Dolibarr)
		$piece = $tpl->save($this->admin, array('label' => 'ZzTpl piece', 'html' => '<p>c</p>', 'accounts' => array((int) $a->id)));
		$this->assertSame(array($piece => 0), $tpl->wholeOf(array($piece)));
		$groups = $tpl->offered($this->admin, (int) $a->id, array());
		$this->assertSame(0, array_column($groups[0]['templates'], 'whole', 'id')[$piece]);
		$row = $tpl->usable($this->admin, $id, (int) $a->id, '');
		$this->assertSame(0, $tpl->render($row, array())['whole']);
		$this->assertTrue($tpl->delete($this->admin, $id));
		$this->assertSame(0, (int) $this->db->getRow("SELECT COUNT(*) as n FROM ".$this->db->prefix()."inbox_template_option WHERE fk_template = ".$id)->n);
	}
}
