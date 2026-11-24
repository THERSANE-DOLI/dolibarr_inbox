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
 * \file    test/phpunit/InboxViewShareTest.php
 * \ingroup inbox
 * \brief   Virtual views (1.31.0): kept searches, shared with users and groups, changed by whom the author allows
 */

// From the command line only, never from the web: test/ stays in the Git branches
if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

require_once __DIR__.'/InboxTestCase.php';
dol_include_once('/inbox/class/inboxview.class.php');

/**
 * Tests of InboxView
 */
class InboxViewShareTest extends InboxTestCase
{
	/**
	 * @return void
	 */
	protected function tearDown(): void
	{
		$p = $this->db->prefix();
		$this->db->query("DELETE FROM ".$p."inbox_view_share WHERE fk_view IN (SELECT rowid FROM ".$p."inbox_view WHERE label LIKE 'ZzView%')");
		$this->db->query("DELETE FROM ".$p."inbox_view WHERE label LIKE 'ZzView%'");
		parent::tearDown();
	}

	/**
	 * @param string $name Group name (test prefix added)
	 * @param User[] $members Members
	 * @return int Group
	 */
	protected function group($name, array $members)
	{
		$p = $this->db->prefix();
		$this->db->query("INSERT INTO ".$p."usergroup (entity, nom, datec) VALUES (1, 'inboxtest_".$this->db->escape($name)."', '".$this->db->idate(dol_now())."')");
		$id = (int) $this->db->last_insert_id($p.'usergroup');
		foreach ($members as $u) {
			$this->db->query("INSERT INTO ".$p."usergroup_user (entity, fk_user, fk_usergroup) VALUES (1, ".((int) $u->id).", ".$id.")");
		}

		return $id;
	}

	/**
	 * @param InboxView $v    Helper
	 * @param User      $user User
	 * @return array<int,array<string,mixed>> His views by id
	 */
	protected function seen(InboxView $v, User $user)
	{
		$out = array();
		foreach ($v->listFor($user) as $view) {
			$out[$view['id']] = $view;
		}

		return $out;
	}

	/**
	 * @return void
	 */
	public function testShareAndRights()
	{
		$author = $this->user('vauthor');
		$bob = $this->user('vbob');
		$carol = $this->user('vcarol');
		$dave = $this->user('vdave');
		$group = $this->group('vteam', array($carol));
		$v = new InboxView($this->db);

		$id = $v->save($author, array('label' => 'ZzView devis', 'icon' => 'target', 'color' => '#C2410C',
			'search' => array('q' => 'devis', 'chips' => array(array('op' => 'from', 'v' => 'zzfournisseur.invalid', 'label' => 'De : zzfournisseur.invalid'), array('op' => 'drop', 'v' => 'x')),
				'filters' => array('unread' => true, 'evil' => true), 'period' => 'd30', 'tag' => 'urgent'),
			'accounts' => array(12, '15', -3), 'show_count' => 1,
			'shares' => array(array('kind' => 'user', 'id' => $bob->id), array('kind' => 'group', 'id' => $group), array('kind' => 'user', 'id' => $author->id))));
		$this->assertGreaterThan(0, $id, $v->errorCode);

		// The author: his view, its shares (never himself), the search kept as it may be replayed
		$mine = $this->seen($v, $author)[$id];
		$this->assertTrue($mine['mine']);
		$this->assertTrue($mine['editable']);
		$this->assertSame('#c2410c', $mine['color']);
		$this->assertSame(array(12, 15), $mine['accounts']);
		$this->assertSame(array('from'), array_column($mine['search']['chips'], 'op'), 'unknown operator dropped');
		$this->assertSame(array('unread' => true, 'flagged' => false, 'attachment' => false, 'mine' => false), $mine['search']['filters']);
		$this->assertSame(array('user:'.$bob->id, 'group:'.$group), array_map(function ($s) {
			return $s['kind'].':'.$s['id'];
		}, $mine['shares']));

		// Shared with Bob (directly) and Carol (her group), not with Dave
		$this->assertArrayHasKey($id, $this->seen($v, $bob));
		$this->assertArrayHasKey($id, $this->seen($v, $carol));
		$this->assertArrayNotHasKey($id, $this->seen($v, $dave));
		$forBob = $this->seen($v, $bob)[$id];
		$this->assertFalse($forBob['mine']);
		$this->assertFalse($forBob['editable'], 'use only by default');
		$this->assertArrayNotHasKey('shares', $forBob, 'the shares are the business of the author');
		$this->assertSame('T vauthor', $forBob['owner']);

		// Use only: Bob cannot change it nor remove it; Dave does not even see it
		$this->assertSame(0, $v->save($bob, array('id' => $id, 'label' => 'ZzView changed')));
		$this->assertSame('no_right', $v->errorCode);
		$this->assertFalse($v->delete($bob, $id));
		$this->assertSame(0, $v->save($dave, array('id' => $id, 'label' => 'ZzView changed')));
		$this->assertSame('not_found', $v->errorCode);

		// The author lets the others change it: Bob changes the search, never the sharing
		$this->assertSame($id, $v->save($author, array('id' => $id, 'label' => 'ZzView devis', 'shared_edit' => 1, 'search' => array('q' => 'devis'))));
		$this->assertTrue($this->seen($v, $bob)[$id]['editable']);
		$this->assertSame($id, $v->save($bob, array('id' => $id, 'label' => 'ZzView devis 2', 'search' => array('q' => 'offre'), 'shared_edit' => 0, 'shares' => array())));
		$after = $this->seen($v, $author)[$id];
		$this->assertSame('ZzView devis 2', $after['label']);
		$this->assertSame('offre', $after['search']['q']);
		$this->assertSame(1, $after['shared_edit'], 'the choice of the author stays');
		$this->assertCount(2, $after['shares'], 'the sharing stays');

		// Bob hides it, copies it into his own views (not shared)
		$this->assertTrue($v->hide($bob, $id, true));
		$this->assertTrue($this->seen($v, $bob)[$id]['hidden']);
		$copy = $v->copy($bob, $id);
		$this->assertGreaterThan(0, $copy);
		$c = $this->seen($v, $bob)[$copy];
		$this->assertTrue($c['mine']);
		$this->assertSame(array(), $c['shares']);
		$this->assertStringContainsString('ZzView devis 2', $c['label']);

		// Removed by its author: gone for everyone
		$this->assertTrue($v->delete($author, $id));
		$this->assertArrayNotHasKey($id, $this->seen($v, $bob));
		$this->assertArrayNotHasKey($id, $this->seen($v, $carol));
		$this->assertSame(0, $v->save($author, array('label' => '  ')));
		$this->assertSame('bad_label', $v->errorCode);
	}
}
