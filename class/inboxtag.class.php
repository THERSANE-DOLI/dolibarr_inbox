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
 * \file    inbox/class/inboxtag.class.php
 * \ingroup inbox
 * \brief   Tags (IMAP keywords): global list of the entity, or own list of a mailbox
 */

require_once __DIR__.'/inboxaccount.class.php';

/**
 * A tag is an IMAP keyword with a label, a colour and a rank (keys 1 to 9 for the first nine, 0 removes them all,
 * as Thunderbird). The global list (fk_account = 0) serves every mailbox in mode "global"; a mailbox in mode "own"
 * has its own list, copied from the global one when it switches. The keyword never changes once created (renaming a
 * tag keeps the mails tagged).
 */
class InboxTag
{
	/**
	 * @var string[] Borders of a tag (none = no border)
	 */
	const BORDERS = array('none', 'solid', 'dashed', 'dotted', 'double');

	/** @var DoliDB */
	protected $db;

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
	 * Tags of a list
	 *
	 * @param int  $accountId  0 = global list
	 * @param bool $activeOnly Only the active tags
	 * @return array<int,array{id:int,fk_account:int,keyword:string,label:string,color:string,border:string,position:int,status:int,key:string}>
	 */
	public function listTags($accountId = 0, $activeOnly = false)
	{
		global $conf;

		$out = array();
		$sql = "SELECT rowid, fk_account, keyword, label, color, border, position, status FROM ".$this->db->prefix()."inbox_tag";
		$sql .= " WHERE entity = ".((int) $conf->entity)." AND fk_account = ".((int) $accountId);
		$sql .= $activeOnly ? " AND status = 1" : '';
		$sql .= " ORDER BY position, rowid LIMIT 500";
		$rows = $this->db->getRows($sql);
		$rank = 0;
		foreach (is_array($rows) ? $rows : array() as $row) {
			if ((int) $row->status === 1) {
				$rank++;
			}
			$out[] = array(
				'id' => (int) $row->rowid,
				'fk_account' => (int) $row->fk_account,
				'keyword' => (string) $row->keyword,
				'label' => (string) $row->label,
				'color' => (string) $row->color,
				'border' => self::isBorder((string) $row->border) ? (string) $row->border : 'none',
				'position' => (int) $row->position,
				'status' => (int) $row->status,
				// Key of the keyboard: the 9 first active tags
				'key' => ((int) $row->status === 1 && $rank <= 9) ? (string) $rank : '',
			);
		}

		return $out;
	}

	/**
	 * Effective list of a mailbox: its own list in mode "own", else the global one
	 *
	 * @param InboxAccount $account    Mailbox
	 * @param bool         $activeOnly Only the active tags
	 * @return array<int,array<string,mixed>>
	 */
	public function listForAccount(InboxAccount $account, $activeOnly = true)
	{
		return $this->listTags($account->tags_mode === 'own' ? (int) $account->id : 0, $activeOnly);
	}

	/**
	 * @param string $color Colour
	 * @return bool         #RRGGBB
	 */
	public static function isColor($color)
	{
		return (bool) preg_match('/^#[0-9a-f]{6}$/i', (string) $color);
	}

	/**
	 * Border of a tag: drawn around the badge in the colour of the text, a mark that does not rely on the colour
	 *
	 * @param string $border Border
	 * @return bool          none, solid, dashed, dotted or double
	 */
	public static function isBorder($border)
	{
		return in_array((string) $border, self::BORDERS, true);
	}

	/**
	 * IMAP keyword built from a label: atom characters only (no space, parenthesis, brace, %, *, quote, backslash),
	 * unique in the list
	 *
	 * @param string $label     Label
	 * @param int    $accountId List
	 * @return string
	 */
	public function makeKeyword($label, $accountId = 0)
	{
		global $conf;

		$base = strtolower(dol_string_unaccent((string) $label));
		$base = trim(preg_replace('/[^a-z0-9_\-]+/', '_', $base), '_');
		$base = $base === '' ? 'tag' : substr($base, 0, 40);
		$keyword = $base;
		for ($i = 2; $i < 1000; $i++) {
			$row = $this->db->getRow("SELECT rowid FROM ".$this->db->prefix()."inbox_tag WHERE entity = ".((int) $conf->entity)
				." AND fk_account = ".((int) $accountId)." AND keyword = '".$this->db->escape($keyword)."'");
			if (!is_object($row)) {
				return $keyword;
			}
			$keyword = $base.'_'.$i;
		}

		return $base.'_'.dol_now();
	}

	/**
	 * Add a tag at the end of a list
	 *
	 * @param User   $user      User
	 * @param string $label     Label
	 * @param string $color     #RRGGBB
	 * @param int    $accountId 0 = global list
	 * @param string $border    none, solid, dashed, dotted or double
	 * @return int               Id, < 0 if KO
	 */
	public function create(User $user, $label, $color, $accountId = 0, $border = 'none')
	{
		global $conf;

		$label = trim(str_replace(array("\r", "\n"), ' ', (string) $label));
		if ($label === '' || dol_strlen($label) > 128) {
			$this->error = 'InboxErrorTagLabel';
			return -1;
		}
		if (!self::isColor($color)) {
			$this->error = 'InboxErrorTagColor';
			return -1;
		}
		if (!self::isBorder($border)) {
			$this->error = 'InboxErrorTagBorder';
			return -1;
		}
		$prefix = $this->db->prefix();
		$max = $this->db->getRow("SELECT MAX(position) AS p FROM ".$prefix."inbox_tag WHERE entity = ".((int) $conf->entity)." AND fk_account = ".((int) $accountId));
		$sql = "INSERT INTO ".$prefix."inbox_tag (entity, fk_account, keyword, label, color, border, position, status, date_creation, fk_user_creat)";
		$sql .= " VALUES (".((int) $conf->entity).", ".((int) $accountId).", '".$this->db->escape($this->makeKeyword($label, $accountId))."',";
		$sql .= " '".$this->db->escape($label)."', '".$this->db->escape(strtoupper($color))."', ".($border === 'none' ? 'NULL' : "'".$this->db->escape($border)."'").", ".((is_object($max) ? (int) $max->p : 0) + 1).", 1,";
		$sql .= " '".$this->db->idate(dol_now())."', ".((int) $user->id).")";
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -2;
		}

		return (int) $this->db->last_insert_id($prefix.'inbox_tag');
	}

	/**
	 * Rename, recolour, enable or disable a tag (the keyword stays)
	 *
	 * @param User   $user   User
	 * @param int    $id     Tag
	 * @param string $label  Label
	 * @param string $color  #RRGGBB
	 * @param int    $status 1 active, 0 not
	 * @param string $border none, solid, dashed, dotted or double
	 * @return int            > 0 if OK, < 0 if KO
	 */
	public function update(User $user, $id, $label, $color, $status = 1, $border = 'none')
	{
		global $conf;

		$label = trim(str_replace(array("\r", "\n"), ' ', (string) $label));
		if ($label === '' || dol_strlen($label) > 128) {
			$this->error = 'InboxErrorTagLabel';
			return -1;
		}
		if (!self::isColor($color)) {
			$this->error = 'InboxErrorTagColor';
			return -1;
		}
		if (!self::isBorder($border)) {
			$this->error = 'InboxErrorTagBorder';
			return -1;
		}
		$sql = "UPDATE ".$this->db->prefix()."inbox_tag SET label = '".$this->db->escape($label)."', color = '".$this->db->escape(strtoupper($color))."',";
		$sql .= " border = ".($border === 'none' ? 'NULL' : "'".$this->db->escape($border)."'").",";
		$sql .= " status = ".($status ? 1 : 0).", fk_user_modif = ".((int) $user->id);
		$sql .= " WHERE rowid = ".((int) $id)." AND entity = ".((int) $conf->entity);

		return $this->db->query($sql) ? 1 : -2;
	}

	/**
	 * Delete a tag of a list (the keyword stays on the mails of the server, inbox shows it as an unknown keyword)
	 *
	 * @param int $id Tag
	 * @return int     > 0 if OK
	 */
	public function delete($id)
	{
		global $conf;

		return $this->db->query("DELETE FROM ".$this->db->prefix()."inbox_tag WHERE rowid = ".((int) $id)." AND entity = ".((int) $conf->entity)) ? 1 : -1;
	}

	/**
	 * New order of a list (drag and drop): ids in their new order, ids of another list ignored
	 *
	 * @param int   $accountId 0 = global list
	 * @param int[] $ids       Tags in their new order
	 * @return int              > 0 if OK
	 */
	public function reorder($accountId, array $ids)
	{
		global $conf;

		$this->db->begin();
		$position = 0;
		foreach ($ids as $id) {
			$position++;
			$sql = "UPDATE ".$this->db->prefix()."inbox_tag SET position = ".$position." WHERE rowid = ".((int) $id);
			$sql .= " AND entity = ".((int) $conf->entity)." AND fk_account = ".((int) $accountId);
			if (!$this->db->query($sql)) {
				$this->db->rollback();
				return -1;
			}
		}
		$this->db->commit();

		return 1;
	}

	/**
	 * Mode of the tags of a mailbox. Switching to "own" copies the global list into the mailbox, once (an own list
	 * already there is kept); switching back to "global" keeps the own list for a later return.
	 *
	 * @param User         $user    User
	 * @param InboxAccount $account Mailbox
	 * @param string       $mode    global or own
	 * @return int                   > 0 if OK, < 0 if KO
	 */
	public function setMode(User $user, InboxAccount $account, $mode)
	{
		global $conf;

		if (!in_array($mode, array('global', 'own'), true)) {
			$this->error = 'InboxErrorAccountTagsMode';
			return -1;
		}
		$prefix = $this->db->prefix();
		$this->db->begin();
		if ($mode === 'own' && !$this->listTags((int) $account->id)) {
			$sql = "INSERT INTO ".$prefix."inbox_tag (entity, fk_account, keyword, label, color, border, position, status, date_creation, fk_user_creat)";
			$sql .= " SELECT entity, ".((int) $account->id).", keyword, label, color, border, position, status, '".$this->db->idate(dol_now())."', ".((int) $user->id);
			$sql .= " FROM ".$prefix."inbox_tag WHERE entity = ".((int) $conf->entity)." AND fk_account = 0";
			if (!$this->db->query($sql)) {
				$this->error = $this->db->lasterror();
				$this->db->rollback();
				return -2;
			}
		}
		if (!$this->db->query("UPDATE ".$prefix."inbox_account SET tags_mode = '".$this->db->escape($mode)."' WHERE rowid = ".((int) $account->id))) {
			$this->db->rollback();
			return -3;
		}
		$this->db->commit();
		$account->tags_mode = $mode;

		return 1;
	}
}
