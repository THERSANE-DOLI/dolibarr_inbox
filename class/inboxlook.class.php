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
 * \file    class/inboxlook.class.php
 * \ingroup inbox
 * \brief   Colour and icon of a mailbox and of its folders (analyse-interface §4 bis)
 *
 * Shared by all the users of the mailbox, kept on the row of the mailbox or of the folder: gone with it, nothing to
 * clean. Changed by the users who manage the mailbox. A special folder (inbox, sent, trash...) keeps its own icon,
 * only its colour changes.
 */

/**
 * Colour and icon of a mailbox and of its folders
 */
class InboxLook
{
	/** @var DoliDB */
	protected $db;

	/** @var string */
	public $error = '';

	/** @var string[]|null Icons offered, cache */
	protected static $choices;

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct(DoliDB $db)
	{
		$this->db = $db;
	}

	/**
	 * Icons offered for a mailbox or a folder (usage names of the sprite, built by dev/build-icons.php)
	 *
	 * @return string[]
	 */
	public static function choices()
	{
		if (self::$choices === null) {
			$list = json_decode((string) @file_get_contents(dirname(__DIR__).'/img/inbox-icon-choices.json'), true);
			self::$choices = is_array($list) ? array_values(array_filter($list, 'is_string')) : array();
		}

		return self::$choices;
	}

	/**
	 * Colour as kept: #rrggbb lower case, '' for the default, null if invalid (it ends in a style attribute)
	 *
	 * @param string $color Colour sent
	 * @return string|null
	 */
	public static function cleanColor($color)
	{
		$color = trim((string) $color);
		if ($color === '') {
			return '';
		}

		return preg_match('/^#[0-9a-f]{6}$/i', $color) ? strtolower($color) : null;
	}

	/**
	 * Icon as kept: one of choices(), '' for the default, null if unknown
	 *
	 * @param string $icon Icon sent
	 * @return string|null
	 */
	public static function cleanIcon($icon)
	{
		$icon = trim((string) $icon);
		if ($icon === '') {
			return '';
		}

		return in_array($icon, self::choices(), true) ? $icon : null;
	}

	/**
	 * @param string $value Value cleaned
	 * @return string SQL value
	 */
	protected function sqlValue($value)
	{
		return $value === '' ? 'NULL' : "'".$this->db->escape($value)."'";
	}

	/**
	 * Colour and icon of a folder, and of its subfolders on request (the rights are checked by the caller)
	 *
	 * @param int    $accountId  Mailbox
	 * @param int    $folderId   Folder
	 * @param string $color      #rrggbb, '' = by default
	 * @param string $icon       Icon, '' = by default (refused for a special folder)
	 * @param bool   $subfolders Same colour and icon for the folders under it
	 * @return int Folders changed, < 0 on error ($this->error = translation key)
	 */
	public function setFolder($accountId, $folderId, $color, $icon, $subfolders = false)
	{
		$color = self::cleanColor($color);
		$icon = self::cleanIcon($icon);
		if ($color === null) {
			$this->error = 'InboxErrorBadColor';
			return -1;
		}
		if ($icon === null) {
			$this->error = 'InboxErrorBadIcon';
			return -1;
		}
		$p = $this->db->prefix();
		$folder = $this->db->getRow("SELECT rowid, path, delimiter, role FROM ".$p."inbox_folder WHERE rowid = ".((int) $folderId)." AND fk_account = ".((int) $accountId));
		if (!is_object($folder)) {
			$this->error = 'InboxErrorFolderNotFound';
			return -2;
		}
		$ids = array((int) $folder->rowid);
		if ($subfolders && (string) $folder->delimiter !== '') {
			$prefix = $this->db->escape($this->db->escapeforlike((string) $folder->path.(string) $folder->delimiter));
			$rows = $this->db->getRows("SELECT rowid FROM ".$p."inbox_folder WHERE fk_account = ".((int) $accountId)." AND path LIKE '".$prefix."%' LIMIT 5000");
			foreach (is_array($rows) ? $rows : array() as $r) {
				$ids[] = (int) $r->rowid;
			}
		}
		$in = implode(',', $ids);
		if (!$this->db->query("UPDATE ".$p."inbox_folder SET color = ".$this->sqlValue($color)." WHERE rowid IN (".$in.")")) {
			$this->error = 'InboxErrorLookSave';
			return -3;
		}
		// The special folders keep their icon
		$this->db->query("UPDATE ".$p."inbox_folder SET icon = ".$this->sqlValue($icon)." WHERE rowid IN (".$in.") AND (role IS NULL OR role = '')");

		return count($ids);
	}

	/**
	 * Colour and icon of a mailbox (the rights are checked by the caller)
	 *
	 * @param int    $accountId Mailbox
	 * @param string $color     #rrggbb, '' = by default
	 * @param string $icon      Icon, '' = the at sign
	 * @return int 1, < 0 on error
	 */
	public function setAccount($accountId, $color, $icon)
	{
		$color = self::cleanColor($color);
		$icon = self::cleanIcon($icon);
		if ($color === null) {
			$this->error = 'InboxErrorBadColor';
			return -1;
		}
		if ($icon === null) {
			$this->error = 'InboxErrorBadIcon';
			return -1;
		}
		if (!$this->db->query("UPDATE ".$this->db->prefix()."inbox_account SET color = ".$this->sqlValue($color).", icon = ".$this->sqlValue($icon)." WHERE rowid = ".((int) $accountId))) {
			$this->error = 'InboxErrorLookSave';
			return -3;
		}

		return 1;
	}

	/**
	 * Back to the default colours and icons for all the folders of a mailbox
	 *
	 * @param int $accountId Mailbox
	 * @return int Folders changed, < 0 on error
	 */
	public function resetFolders($accountId)
	{
		$p = $this->db->prefix();
		$n = (int) $this->db->getRow("SELECT COUNT(*) AS n FROM ".$p."inbox_folder WHERE fk_account = ".((int) $accountId)." AND (color IS NOT NULL OR icon IS NOT NULL)")->n;
		if (!$this->db->query("UPDATE ".$p."inbox_folder SET color = NULL, icon = NULL WHERE fk_account = ".((int) $accountId))) {
			$this->error = 'InboxErrorLookSave';
			return -3;
		}

		return $n;
	}
}
