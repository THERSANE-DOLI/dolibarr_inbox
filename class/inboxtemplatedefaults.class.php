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
 * \file    inbox/class/inboxtemplatedefaults.class.php
 * \ingroup inbox
 * \brief   Default template of a writing (1.118.0), on four levels
 *
 * A template of inbox (type inbox_send) may be put by itself in a new mail or in an answer. Four levels, the most
 * precise wins: the user in this mailbox, the mailbox for every user, the user in every mailbox, every one. The
 * mailbox comes before the user: it carries the use of the mail (quotes, after sales), a personal default is a
 * habit; who wants another one in a mailbox sets the first level. A level may say « no template » on purpose: the
 * levels under it are then not used. Under the four levels, the template of Dolibarr marked « default for the
 * type » (defaultfortype, new mails only), as the form of mails of Dolibarr does.
 *
 * A default no longer usable (removed, inactive, no longer offered in the mailbox, private to someone else) is
 * skipped: the next level answers.
 */

require_once __DIR__.'/inboxaccess.class.php';
require_once __DIR__.'/inboxtemplates.class.php';

/**
 * Default templates of the writings
 */
class InboxTemplateDefaults
{
	/** Kinds of writing that may receive a default template */
	const USAGES = array('new', 'reply');

	/** Levels, the most precise first */
	const LEVELS = array('user_box', 'box', 'user', 'all');

	/** @var DoliDB */
	protected $db;

	/** @var string Code for the browser: not_found, no_right, bad_template, bad_value */
	public $errorCode = '';

	/** @var string */
	public $error = '';

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct(DoliDB $db)
	{
		$this->db = $db;
	}

	/**
	 * @param string $code   Code
	 * @param string $detail Detail for the log
	 * @return bool false
	 */
	protected function fail($code, $detail = '')
	{
		$this->errorCode = $code;
		$this->error = $detail !== '' ? $detail : $code;

		return false;
	}

	/**
	 * Mailbox and user of a level
	 *
	 * @param string $level     One of LEVELS
	 * @param User   $user      User
	 * @param int    $accountId Mailbox
	 * @return array{0:int,1:int} fk_account, fk_user
	 */
	public static function keysOf($level, User $user, $accountId)
	{
		return array(in_array($level, array('user_box', 'box'), true) ? (int) $accountId : 0, in_array($level, array('user_box', 'user'), true) ? (int) $user->id : 0);
	}

	/**
	 * Level of a row
	 *
	 * @param int $fkAccount Mailbox, 0 every one
	 * @param int $fkUser    User, 0 every one
	 * @return string
	 */
	public static function levelOf($fkAccount, $fkUser)
	{
		if ($fkAccount > 0) {
			return $fkUser > 0 ? 'user_box' : 'box';
		}

		return $fkUser > 0 ? 'user' : 'all';
	}

	/**
	 * Usage of a mode of writing: new, reply, or '' (no default template: forward, draft...)
	 *
	 * @param string $mode Mode of InboxComposer
	 * @return string
	 */
	public static function usageOfMode($mode)
	{
		if ($mode === 'new') {
			return 'new';
		}

		return in_array($mode, array('reply', 'replyall', 'replylist'), true) ? 'reply' : '';
	}

	/**
	 * The user may set this level in this mailbox
	 *
	 * @param User   $user      User
	 * @param string $level     One of LEVELS
	 * @param int    $accountId Mailbox
	 * @return bool
	 */
	public function canSet(User $user, $level, $accountId)
	{
		$admin = $user->hasRight('inbox', 'account', 'admin');
		$access = new InboxAccess($this->db);
		switch ($level) {
			case 'user_box':
				return $access->check($user, (int) $accountId, InboxAccess::LEVEL_SEND);
			case 'box':
				return $admin || $access->check($user, (int) $accountId, InboxAccess::LEVEL_MANAGE);
			case 'user':
				return true;
			case 'all':
				return (bool) $admin;
		}

		return false;
	}

	/**
	 * Templates that may be the default of a level: active templates of inbox seen by the user; shared ones only for
	 * the levels of every user; offered in the mailbox for the levels of a mailbox, offered everywhere for the others
	 *
	 * @param User   $user      User
	 * @param string $level     One of LEVELS
	 * @param int    $accountId Mailbox
	 * @return array<int,array{id:int,label:string,lang:string,private:int}>
	 */
	public function choices(User $user, $level, $accountId)
	{
		$p = $this->db->prefix();
		$sql = "SELECT t.rowid, t.label, t.lang, t.private FROM ".$p."c_email_templates as t WHERE t.type_template = '".InboxTemplates::TYPE."' AND t.active = 1"
			." AND t.entity IN (".getEntity('c_email_templates').")";
		$sql .= in_array($level, array('box', 'all'), true) ? " AND t.private = 0" : " AND (t.private = 0 OR t.fk_user = ".((int) $user->id).")";
		if (in_array($level, array('user_box', 'box'), true)) {
			$sql .= " AND (NOT EXISTS (SELECT 1 FROM ".$p."inbox_template_account as ta WHERE ta.fk_template = t.rowid)"
				." OR EXISTS (SELECT 1 FROM ".$p."inbox_template_account as ta2 WHERE ta2.fk_template = t.rowid AND ta2.fk_account = ".((int) $accountId)."))";
		} else {
			$sql .= " AND NOT EXISTS (SELECT 1 FROM ".$p."inbox_template_account as ta WHERE ta.fk_template = t.rowid)";
		}
		$rows = $this->db->getRows($sql." ORDER BY t.private, t.position, t.label LIMIT 500");
		$out = array();
		foreach (is_array($rows) ? $rows : array() as $r) {
			$out[] = array('id' => (int) $r->rowid, 'label' => (string) $r->label, 'lang' => (string) $r->lang, 'private' => (int) $r->private);
		}

		return $out;
	}

	/**
	 * Rows that apply to this user in this mailbox, the most precise first
	 *
	 * @param User   $user      User
	 * @param int    $accountId Mailbox
	 * @param string $usage     One of USAGES, '' all
	 * @return array<int,object> fk_account, fk_user, usage_type, fk_template
	 */
	protected function rows(User $user, $accountId, $usage = '')
	{
		global $conf;

		$rows = $this->db->getRows("SELECT fk_account, fk_user, usage_type, fk_template FROM ".$this->db->prefix()."inbox_template_default WHERE entity = ".((int) $conf->entity)
			." AND fk_account IN (0, ".((int) $accountId).") AND fk_user IN (0, ".((int) $user->id).")".($usage !== '' ? " AND usage_type = '".$this->db->escape($usage)."'" : "")
			." ORDER BY (fk_account > 0) DESC, (fk_user > 0) DESC LIMIT 20");

		return is_array($rows) ? $rows : array();
	}

	/**
	 * Default template of a writing of this user in this mailbox
	 *
	 * @param User   $user      User
	 * @param int    $accountId Mailbox of the sending
	 * @param string $usage     One of USAGES
	 * @return array{id:int,level:string} id 0 = no template, level '' when nothing is set
	 */
	public function resolve(User $user, $accountId, $usage)
	{
		if (!in_array($usage, self::USAGES, true)) {
			return array('id' => 0, 'level' => '');
		}
		$templates = new InboxTemplates($this->db);
		foreach ($this->rows($user, $accountId, $usage) as $r) {
			$level = self::levelOf((int) $r->fk_account, (int) $r->fk_user);
			if ((int) $r->fk_template === 0) {
				return array('id' => 0, 'level' => $level);
			}
			if ($this->valid($user, (int) $r->fk_template, $accountId, $templates)) {
				return array('id' => (int) $r->fk_template, 'level' => $level);
			}
		}
		// The template of Dolibarr « default for the type », as its form of mails: the private one of the user first
		if ($usage === 'new') {
			$rows = $this->db->getRows("SELECT t.rowid FROM ".$this->db->prefix()."c_email_templates as t WHERE t.type_template = '".InboxTemplates::TYPE."' AND t.defaultfortype = 1 AND t.active = 1"
				." AND t.entity IN (".getEntity('c_email_templates').") AND (t.private = 0 OR t.fk_user = ".((int) $user->id).") ORDER BY t.private DESC, t.position, t.rowid LIMIT 10");
			foreach (is_array($rows) ? $rows : array() as $r) {
				if ($this->valid($user, (int) $r->rowid, $accountId, $templates)) {
					return array('id' => (int) $r->rowid, 'level' => 'core');
				}
			}
		}

		return array('id' => 0, 'level' => '');
	}

	/**
	 * A template of inbox usable by the user in this mailbox
	 *
	 * @param User           $user      User
	 * @param int            $id        Template
	 * @param int            $accountId Mailbox
	 * @param InboxTemplates $templates Helper
	 * @return bool
	 */
	protected function valid(User $user, $id, $accountId, InboxTemplates $templates)
	{
		$t = $templates->usable($user, (int) $id, (int) $accountId, '');

		return is_object($t) && (string) $t->type_template === InboxTemplates::TYPE;
	}

	/**
	 * The four levels for the dialog « Default templates » of a mailbox: value of each one (null not set, 0 no
	 * template), whether the user may change it, the templates it may take; and the template used for each usage
	 *
	 * @param User $user      User
	 * @param int  $accountId Mailbox
	 * @return array<string,mixed>|false
	 */
	public function view(User $user, $accountId)
	{
		$access = new InboxAccess($this->db);
		if (!$access->check($user, (int) $accountId, InboxAccess::LEVEL_SEND) && !$this->canSet($user, 'box', $accountId)) {
			return $this->fail('not_found');
		}
		$set = array();
		foreach ($this->rows($user, $accountId) as $r) {
			$set[self::levelOf((int) $r->fk_account, (int) $r->fk_user)][(string) $r->usage_type] = (int) $r->fk_template;
		}
		$labels = array();
		$levels = array();
		foreach (self::LEVELS as $level) {
			$choices = $this->canSet($user, $level, $accountId) ? $this->choices($user, $level, $accountId) : array();
			foreach ($choices as $c) {
				$labels[$c['id']] = $c['label'];
			}
			$values = array();
			foreach (self::USAGES as $usage) {
				$values[$usage] = $set[$level][$usage] ?? null;
			}
			$levels[] = array('level' => $level, 'editable' => $this->canSet($user, $level, $accountId) ? 1 : 0, 'values' => $values, 'choices' => $choices);
		}
		// Names of the templates set but not offered to this user (shown, never changed by him)
		$ids = array();
		foreach ($set as $byUsage) {
			foreach ($byUsage as $id) {
				if ($id > 0 && !isset($labels[$id])) {
					$ids[] = (int) $id;
				}
			}
		}
		if ($ids) {
			$rows = $this->db->getRows("SELECT rowid, label, private, fk_user FROM ".$this->db->prefix()."c_email_templates WHERE rowid IN (".implode(',', array_unique($ids)).") LIMIT 50");
			foreach (is_array($rows) ? $rows : array() as $r) {
				// The name of a private template of someone else is not his to see
				if (empty($r->private) || (int) $r->fk_user === (int) $user->id) {
					$labels[(int) $r->rowid] = (string) $r->label;
				}
			}
		}
		$effective = array();
		foreach (self::USAGES as $usage) {
			$effective[$usage] = $this->resolve($user, $accountId, $usage);
			if ($effective[$usage]['id'] > 0 && !isset($labels[$effective[$usage]['id']])) {
				$t = $this->db->getRow("SELECT label FROM ".$this->db->prefix()."c_email_templates WHERE rowid = ".((int) $effective[$usage]['id']));
				$labels[$effective[$usage]['id']] = is_object($t) ? (string) $t->label : '';
			}
		}

		return array('levels' => $levels, 'labels' => (object) $labels, 'effective' => $effective);
	}

	/**
	 * Set or clear the default of a level
	 *
	 * @param User     $user      User
	 * @param int      $accountId Mailbox
	 * @param string   $level     One of LEVELS
	 * @param string   $usage     One of USAGES
	 * @param int|null $template  Template, 0 no template on purpose, null not set (the next level answers)
	 * @return bool
	 */
	public function set(User $user, $accountId, $level, $usage, $template)
	{
		global $conf;

		$this->errorCode = '';
		if (!in_array($level, self::LEVELS, true) || !in_array($usage, self::USAGES, true)) {
			return $this->fail('bad_value');
		}
		if (!$this->canSet($user, $level, $accountId)) {
			return $this->fail('no_right');
		}
		if ($template !== null && (int) $template > 0 && !in_array((int) $template, array_column($this->choices($user, $level, $accountId), 'id'), true)) {
			return $this->fail('bad_template');
		}
		list($fkAccount, $fkUser) = self::keysOf($level, $user, $accountId);
		$p = $this->db->prefix();
		$where = " WHERE entity = ".((int) $conf->entity)." AND fk_account = ".$fkAccount." AND fk_user = ".$fkUser." AND usage_type = '".$this->db->escape($usage)."'";
		if ($template === null) {
			return (bool) $this->db->query("DELETE FROM ".$p."inbox_template_default".$where) || $this->fail('error', $this->db->lasterror());
		}
		$row = $this->db->getRow("SELECT rowid FROM ".$p."inbox_template_default".$where);
		if (is_object($row)) {
			$ok = $this->db->query("UPDATE ".$p."inbox_template_default SET fk_template = ".((int) $template).", fk_user_modif = ".((int) $user->id)." WHERE rowid = ".((int) $row->rowid));
		} else {
			$ok = $this->db->query("INSERT INTO ".$p."inbox_template_default (entity, fk_account, fk_user, usage_type, fk_template, fk_user_modif) VALUES (".((int) $conf->entity).", ".$fkAccount.", ".$fkUser.", '"
				.$this->db->escape($usage)."', ".((int) $template).", ".((int) $user->id).")");
		}

		return (bool) $ok || $this->fail('error', $this->db->lasterror());
	}

	/**
	 * A template removed: its rows go (the next level answers)
	 *
	 * @param int $templateId Template
	 * @return void
	 */
	public function forgetTemplate($templateId)
	{
		if ((int) $templateId > 0) {
			$this->db->query("DELETE FROM ".$this->db->prefix()."inbox_template_default WHERE fk_template = ".((int) $templateId));
		}
	}
}
