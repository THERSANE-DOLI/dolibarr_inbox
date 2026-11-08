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
 * \file    inbox/class/inboxview.class.php
 * \ingroup inbox
 * \brief   Virtual views (1.31.0, analyse-navigation-vues.md §4, §8): searches kept, shared with users and groups
 */

require_once __DIR__.'/inboxuserparam.class.php';

/**
 * A view keeps a search (its words, its pills, its filters) and the mailboxes it covers; it holds no mail. Its
 * author shares it with users and groups and chooses whether they may change it too; the sharing and the removal
 * stay his. Whoever opens a view only sees the mails of the mailboxes he reads himself (the list checks it).
 */
class InboxView
{
	/** Operators of the pills a view may keep (those of the search) */
	const CHIP_OPS = array('from', 'to', 'subject', 'before', 'after', 'tag');

	/** Filters a view may keep */
	const FILTERS = array('unread', 'flagged', 'attachment', 'mine');

	/** Periods a view may keep */
	const PERIODS = array('', 'today', 'd7', 'd30', 'd365');

	/** Views of one user at most (his own) */
	const MAX_OWN = 100;

	/** @var DoliDB */
	protected $db;

	/** @var string Code of the last refusal: not_found, no_right, bad_label, too_many */
	public $errorCode = '';

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct(DoliDB $db)
	{
		$this->db = $db;
	}

	/**
	 * Views of the user: his own, then those shared with him (directly or by one of his groups)
	 *
	 * @param User $user User
	 * @return array<int,array<string,mixed>>
	 */
	public function listFor(User $user)
	{
		global $conf, $langs;

		$p = $this->db->prefix();
		$groups = $this->groupsOf($user);
		$rows = $this->db->getRows("SELECT v.rowid, v.fk_user, v.label, v.icon, v.color, v.search, v.accounts, v.shared_edit, v.show_count, v.position,"
			." u.firstname, u.lastname, u.login FROM ".$p."inbox_view as v LEFT JOIN ".$p."user as u ON u.rowid = v.fk_user"
			." WHERE v.entity = ".((int) $conf->entity)." AND (v.fk_user = ".((int) $user->id)
			." OR EXISTS (SELECT 1 FROM ".$p."inbox_view_share as s WHERE s.fk_view = v.rowid AND (s.fk_user = ".((int) $user->id)
			.($groups ? " OR s.fk_usergroup IN (".implode(',', $groups).")" : '')."))) ORDER BY CASE WHEN v.fk_user = ".((int) $user->id)." THEN 0 ELSE 1 END, v.position, v.label LIMIT 500");
		$params = new InboxUserParam($this->db);
		$hidden = json_decode((string) $params->get($user, 'views_hidden'), true);
		$hidden = array_map('intval', is_array($hidden) ? $hidden : array());
		$out = array();
		foreach (is_array($rows) ? $rows : array() as $r) {
			$mine = (int) $r->fk_user === (int) $user->id;
			$view = array(
				'id' => (int) $r->rowid,
				'label' => (string) $r->label,
				'icon' => (string) $r->icon,
				'color' => (string) $r->color,
				'search' => self::cleanSearch(json_decode((string) $r->search, true)),
				'accounts' => self::ids(json_decode((string) $r->accounts, true)),
				'show_count' => (int) $r->show_count,
				'mine' => $mine,
				'editable' => $mine || (int) $r->shared_edit === 1,
				'shared_edit' => (int) $r->shared_edit,
				'owner' => $mine ? '' : (trim($r->firstname.' '.$r->lastname) !== '' ? trim($r->firstname.' '.$r->lastname) : (string) $r->login),
				'hidden' => !$mine && in_array((int) $r->rowid, $hidden, true),
			);
			if ($mine) {
				$view['shares'] = $this->sharesOf((int) $r->rowid);
			}
			$out[] = $view;
		}

		return $out;
	}

	/**
	 * Create or change a view
	 *
	 * @param User                $user User
	 * @param array<string,mixed> $data id, label, icon, color, search, accounts, show_count; for the author: shared_edit, shares (users, groups)
	 * @return int Id, 0 if refused ($this->errorCode)
	 */
	public function save(User $user, array $data)
	{
		global $conf;

		$this->errorCode = '';
		$id = (int) ($data['id'] ?? 0);
		$old = null;
		if ($id > 0) {
			$old = $this->visible($user, $id);
			if (!$old) {
				return $this->fail('not_found');
			}
			if ((int) $old->fk_user !== (int) $user->id && (int) $old->shared_edit !== 1) {
				return $this->fail('no_right');
			}
		} else {
			$n = $this->db->getRow("SELECT COUNT(rowid) AS n FROM ".$this->db->prefix()."inbox_view WHERE entity = ".((int) $conf->entity)." AND fk_user = ".((int) $user->id));
			if (is_object($n) && (int) $n->n >= self::MAX_OWN) {
				return $this->fail('too_many');
			}
		}
		$label = trim(dol_trunc(str_replace(array("\r", "\n", "\t"), ' ', (string) ($data['label'] ?? '')), 128, 'right', 'UTF-8', 1));
		if ($label === '') {
			return $this->fail('bad_label');
		}
		$icon = preg_match('/^[a-z0-9-]{1,48}$/', (string) ($data['icon'] ?? '')) ? (string) $data['icon'] : '';
		$color = preg_match('/^#[0-9a-f]{6}$/i', (string) ($data['color'] ?? '')) ? strtolower((string) $data['color']) : '';
		$search = self::cleanSearch(is_array($data['search'] ?? null) ? $data['search'] : array());
		$accounts = self::ids(is_array($data['accounts'] ?? null) ? $data['accounts'] : array());
		$showCount = !empty($data['show_count']) ? 1 : 0;
		$p = $this->db->prefix();
		$s = function ($v) {
			return $v === '' ? 'NULL' : "'".$this->db->escape($v)."'";
		};
		$isOwner = !$old || (int) $old->fk_user === (int) $user->id;
		$this->db->begin();
		if ($old) {
			$sql = "UPDATE ".$p."inbox_view SET label = '".$this->db->escape($label)."', icon = ".$s($icon).", color = ".$s($color).","
				." search = '".$this->db->escape((string) json_encode($search))."', accounts = '".$this->db->escape((string) json_encode($accounts))."',"
				." show_count = ".$showCount.", fk_user_modif = ".((int) $user->id)
				.($isOwner ? ", shared_edit = ".(!empty($data['shared_edit']) ? 1 : 0) : '')
				." WHERE rowid = ".$id;
			$ok = (bool) $this->db->query($sql);
		} else {
			$pos = $this->db->getRow("SELECT MAX(position) AS m FROM ".$p."inbox_view WHERE entity = ".((int) $conf->entity)." AND fk_user = ".((int) $user->id));
			$ok = (bool) $this->db->query("INSERT INTO ".$p."inbox_view (entity, fk_user, label, icon, color, search, accounts, shared_edit, show_count, position, date_creation) VALUES ("
				.((int) $conf->entity).", ".((int) $user->id).", '".$this->db->escape($label)."', ".$s($icon).", ".$s($color).","
				." '".$this->db->escape((string) json_encode($search))."', '".$this->db->escape((string) json_encode($accounts))."',"
				." ".(!empty($data['shared_edit']) ? 1 : 0).", ".$showCount.", ".((is_object($pos) ? (int) $pos->m : 0) + 1).", '".$this->db->idate(dol_now())."')");
			$id = $ok ? (int) $this->db->last_insert_id($p.'inbox_view') : 0;
		}
		// The sharing: the author only
		if ($ok && $isOwner && array_key_exists('shares', $data)) {
			$ok = $this->setShares($id, $user, is_array($data['shares']) ? $data['shares'] : array());
		}
		if (!$ok) {
			$this->db->rollback();
			return $this->fail('error');
		}
		$this->db->commit();

		return $id;
	}

	/**
	 * Remove a view (its author only)
	 *
	 * @param User $user User
	 * @param int  $id   View
	 * @return bool
	 */
	public function delete(User $user, $id)
	{
		$v = $this->visible($user, $id);
		if (!$v) {
			return (bool) $this->fail('not_found');
		}
		if ((int) $v->fk_user !== (int) $user->id) {
			return (bool) $this->fail('no_right');
		}
		$p = $this->db->prefix();
		$this->db->query("DELETE FROM ".$p."inbox_view_share WHERE fk_view = ".((int) $id));

		return (bool) $this->db->query("DELETE FROM ".$p."inbox_view WHERE rowid = ".((int) $id));
	}

	/**
	 * A view shared with the user copied into his own views (not shared)
	 *
	 * @param User $user User
	 * @param int  $id   View
	 * @return int Id of the copy, 0 if refused
	 */
	public function copy(User $user, $id)
	{
		global $langs;

		$v = $this->visible($user, $id);
		if (!$v) {
			return $this->fail('not_found');
		}

		return $this->save($user, array('label' => $langs->transnoentities('InboxViewCopyOf', (string) $v->label), 'icon' => (string) $v->icon, 'color' => (string) $v->color,
			'search' => json_decode((string) $v->search, true), 'accounts' => json_decode((string) $v->accounts, true), 'show_count' => (int) $v->show_count));
	}

	/**
	 * A view shared with the user hidden from his section « Views », or shown again
	 *
	 * @param User $user User
	 * @param int  $id   View
	 * @param bool $hide Hide it
	 * @return bool
	 */
	public function hide(User $user, $id, $hide)
	{
		if (!$this->visible($user, $id)) {
			return (bool) $this->fail('not_found');
		}
		$params = new InboxUserParam($this->db);
		$list = json_decode((string) $params->get($user, 'views_hidden'), true);
		$list = array_map('intval', is_array($list) ? $list : array());
		$list = $hide ? array_values(array_unique(array_merge($list, array((int) $id)))) : array_values(array_diff($list, array((int) $id)));

		return $params->set($user, 'views_hidden', (string) json_encode($list)) > 0;
	}

	/**
	 * Order of the views of the user (his own)
	 *
	 * @param User  $user User
	 * @param int[] $ids  Views in their new order
	 * @return void
	 */
	public function order(User $user, array $ids)
	{
		global $conf;

		$pos = 1;
		foreach (array_map('intval', $ids) as $id) {
			$this->db->query("UPDATE ".$this->db->prefix()."inbox_view SET position = ".($pos++)." WHERE rowid = ".$id." AND fk_user = ".((int) $user->id)." AND entity = ".((int) $conf->entity));
		}
	}

	/**
	 * The view, if the user sees it (his own or shared with him)
	 *
	 * @param User $user User
	 * @param int  $id   View
	 * @return object|null
	 */
	public function visible(User $user, $id)
	{
		global $conf;

		$p = $this->db->prefix();
		$groups = $this->groupsOf($user);
		$row = $this->db->getRow("SELECT v.* FROM ".$p."inbox_view as v WHERE v.rowid = ".((int) $id)." AND v.entity = ".((int) $conf->entity)
			." AND (v.fk_user = ".((int) $user->id)." OR EXISTS (SELECT 1 FROM ".$p."inbox_view_share as s WHERE s.fk_view = v.rowid AND (s.fk_user = ".((int) $user->id)
			.($groups ? " OR s.fk_usergroup IN (".implode(',', $groups).")" : '').")))");

		return is_object($row) ? $row : null;
	}

	/**
	 * The search of a view kept as it may be replayed: words, pills of known operators, filters, thirdparty, tag,
	 * period; every value bounded
	 *
	 * @param mixed $s Search
	 * @return array{q:string,chips:array<int,array{op:string,v:string,label:string}>,filters:array<string,bool>,soc:array{id:int,name:string}|null,tag:string,period:string}
	 */
	public static function cleanSearch($s)
	{
		$s = is_array($s) ? $s : array();
		$chips = array();
		foreach (array_slice(is_array($s['chips'] ?? null) ? $s['chips'] : array(), 0, 20) as $c) {
			if (is_array($c) && in_array((string) ($c['op'] ?? ''), self::CHIP_OPS, true) && trim((string) ($c['v'] ?? '')) !== '') {
				$chips[] = array('op' => (string) $c['op'], 'v' => mb_substr(trim((string) $c['v']), 0, 200, 'UTF-8'), 'label' => mb_substr((string) ($c['label'] ?? ''), 0, 250, 'UTF-8'));
			}
		}
		$filters = array();
		foreach (self::FILTERS as $f) {
			$filters[$f] = !empty($s['filters'][$f]);
		}
		$soc = null;
		if (is_array($s['soc'] ?? null) && (int) ($s['soc']['id'] ?? 0) > 0) {
			$soc = array('id' => (int) $s['soc']['id'], 'name' => mb_substr((string) ($s['soc']['name'] ?? ''), 0, 128, 'UTF-8'));
		}

		return array(
			'q' => mb_substr(trim(str_replace(array("\r", "\n"), ' ', (string) ($s['q'] ?? ''))), 0, 500, 'UTF-8'),
			'chips' => $chips,
			'filters' => $filters,
			'soc' => $soc,
			'tag' => mb_substr((string) ($s['tag'] ?? ''), 0, 64, 'UTF-8'),
			'period' => in_array((string) ($s['period'] ?? ''), self::PERIODS, true) ? (string) ($s['period'] ?? '') : '',
		);
	}

	/**
	 * Shares of a view: users and groups, with their names
	 *
	 * @param int $id View
	 * @return array<int,array{kind:string,id:int,name:string}>
	 */
	protected function sharesOf($id)
	{
		$p = $this->db->prefix();
		$rows = $this->db->getRows("SELECT s.fk_user, s.fk_usergroup, u.firstname, u.lastname, u.login, g.nom FROM ".$p."inbox_view_share as s"
			." LEFT JOIN ".$p."user as u ON u.rowid = s.fk_user LEFT JOIN ".$p."usergroup as g ON g.rowid = s.fk_usergroup WHERE s.fk_view = ".((int) $id)." ORDER BY s.rowid LIMIT 500");
		$out = array();
		foreach (is_array($rows) ? $rows : array() as $r) {
			if ((int) $r->fk_user > 0) {
				$name = trim($r->firstname.' '.$r->lastname);
				$out[] = array('kind' => 'user', 'id' => (int) $r->fk_user, 'name' => $name !== '' ? $name : (string) $r->login);
			} elseif ((int) $r->fk_usergroup > 0) {
				$out[] = array('kind' => 'group', 'id' => (int) $r->fk_usergroup, 'name' => (string) $r->nom);
			}
		}

		return $out;
	}

	/**
	 * Shares replaced: active internal users and groups of the entity only, never the author himself
	 *
	 * @param int                            $id     View
	 * @param User                           $author Author
	 * @param array<int,array<string,mixed>> $shares kind (user|group), id
	 * @return bool
	 */
	protected function setShares($id, User $author, array $shares)
	{
		global $conf;

		$p = $this->db->prefix();
		if (!$this->db->query("DELETE FROM ".$p."inbox_view_share WHERE fk_view = ".((int) $id))) {
			return false;
		}
		$seen = array();
		foreach (array_slice($shares, 0, 200) as $sh) {
			$kind = (string) ($sh['kind'] ?? '');
			$target = (int) ($sh['id'] ?? 0);
			if ($target <= 0 || isset($seen[$kind.$target])) {
				continue;
			}
			$seen[$kind.$target] = true;
			if ($kind === 'user') {
				$ok = $target !== (int) $author->id && is_object($this->db->getRow("SELECT rowid FROM ".$p."user WHERE rowid = ".$target." AND statut = 1 AND (fk_soc IS NULL OR fk_soc = 0)"
					." AND entity IN (0, ".((int) $conf->entity).")"));
				$cols = $target.", 0";
			} elseif ($kind === 'group') {
				$ok = is_object($this->db->getRow("SELECT rowid FROM ".$p."usergroup WHERE rowid = ".$target." AND entity IN (0, ".((int) $conf->entity).")"));
				$cols = "0, ".$target;
			} else {
				continue;
			}
			if ($ok && !$this->db->query("INSERT INTO ".$p."inbox_view_share (fk_view, fk_user, fk_usergroup) VALUES (".((int) $id).", ".$cols.")")) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @param User $user User
	 * @return int[] His groups
	 */
	protected function groupsOf(User $user)
	{
		$rows = $this->db->getRows("SELECT fk_usergroup FROM ".$this->db->prefix()."usergroup_user WHERE fk_user = ".((int) $user->id)." LIMIT 1000");

		return array_map(function ($r) {
			return (int) $r->fk_usergroup;
		}, is_array($rows) ? $rows : array());
	}

	/**
	 * @param mixed $list List
	 * @return int[] Positive ids, at most 500
	 */
	protected static function ids($list)
	{
		return array_values(array_unique(array_filter(array_map('intval', array_slice(is_array($list) ? $list : array(), 0, 500)), function ($i) {
			return $i > 0;
		})));
	}

	/**
	 * @param string $code Code
	 * @return int 0
	 */
	protected function fail($code)
	{
		$this->errorCode = $code;

		return 0;
	}
}
