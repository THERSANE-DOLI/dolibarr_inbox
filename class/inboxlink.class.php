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
 * \file    inbox/class/inboxlink.class.php
 * \ingroup inbox
 * \brief   Links between a mail (its mailref) and a piece of Dolibarr (lot W5 1.13.0), public for the other modules
 */

require_once __DIR__.'/inboxmailref.class.php';
require_once __DIR__.'/inboxelements.class.php';

/**
 * Public class (P9): another module (the bridge of a connector, a script of recovery) links with link() and its own
 * origin, without the checks of a user when it passes none. No double: the same mail and piece are one link.
 * Triggers INBOX_LINK_CREATE / INBOX_LINK_DELETE, unless $notrigger (a recovery that must not be copied back).
 */
class InboxLink
{
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
	 * Link a mail to a piece
	 *
	 * @param int       $mailrefId   Mailref
	 * @param string    $type        Type of the piece (InboxElements::TYPES, or the type of another module)
	 * @param int       $elementId   Piece
	 * @param User|null $user        Author (checked: access to the mail and right to read the piece); null = a module
	 * @param string    $origin      inbox, doliconnector...
	 * @param bool      $notrigger   No trigger
	 * @return int Link (existing one when already there), < 0 if KO
	 */
	public function link($mailrefId, $type, $elementId, $user = null, $origin = 'inbox', $notrigger = false)
	{
		global $conf;

		$this->error = '';
		$type = substr(preg_replace('/[^a-z0-9_@]/i', '', (string) $type), 0, 64);
		if ((int) $mailrefId <= 0 || $type === '' || (int) $elementId <= 0) {
			$this->error = 'Bad parameters';
			return -1;
		}
		$refs = new InboxMailRef($this->db);
		if ($user instanceof User) {
			if ($refs->levelOf($user, (int) $mailrefId) === '') {
				$this->error = 'InboxMailNotFound';
				return -1;
			}
			if (!InboxElements::canRead($user, $type, (int) $elementId)) {
				$this->error = 'InboxErrorPieceNotReadable';
				return -1;
			}
		}
		$p = $this->db->prefix();
		$where = " WHERE fk_mailref = ".((int) $mailrefId)." AND element_type = '".$this->db->escape($type)."' AND fk_element = ".((int) $elementId);
		$old = $this->db->getRow("SELECT rowid FROM ".$p."inbox_link".$where);
		if (is_object($old)) {
			return (int) $old->rowid;
		}
		$sql = "INSERT INTO ".$p."inbox_link (entity, fk_mailref, element_type, fk_element, origin, fk_user_creat, date_creation) VALUES (".((int) $conf->entity).", ".((int) $mailrefId).","
			." '".$this->db->escape($type)."', ".((int) $elementId).", '".$this->db->escape(substr((string) $origin, 0, 32))."', ".($user instanceof User ? (int) $user->id : 'NULL').","
			." '".$this->db->idate(dol_now())."')";
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$id = (int) $this->db->last_insert_id($p.'inbox_link');
		if (!$notrigger) {
			$this->trigger('INBOX_LINK_CREATE', $user, array('link' => $id, 'mailref' => (int) $mailrefId, 'element_type' => $type, 'fk_element' => (int) $elementId, 'origin' => (string) $origin));
		}

		return $id;
	}

	/**
	 * Remove a link
	 *
	 * @param int       $linkId    Link
	 * @param User|null $user      Author (checked: access to the mail); null = a module
	 * @param bool      $notrigger No trigger
	 * @return int 1 if removed, 0 if not there, < 0 if KO
	 */
	public function unlink($linkId, $user = null, $notrigger = false)
	{
		$p = $this->db->prefix();
		$l = $this->db->getRow("SELECT rowid, fk_mailref, element_type, fk_element, origin, fk_user_creat FROM ".$p."inbox_link WHERE rowid = ".((int) $linkId));
		if (!is_object($l)) {
			return 0;
		}
		if ($user instanceof User) {
			$refs = new InboxMailRef($this->db);
			$level = $refs->levelOf($user, (int) $l->fk_mailref);
			if ($level === '') {
				$this->error = 'InboxMailNotFound';
				return -1;
			}
			// Reading a copy is not enough to undo the work of others: its author, or the level « send » (audit INBX-006)
			if ((int) $l->fk_user_creat !== (int) $user->id && !in_array($level, array(InboxAccess::LEVEL_SEND, InboxAccess::LEVEL_MANAGE), true)) {
				$this->error = 'InboxErrorLinkNotYours';
				return -1;
			}
		}
		if (!$this->db->query("DELETE FROM ".$p."inbox_link WHERE rowid = ".((int) $l->rowid))) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		if (!$notrigger) {
			$this->trigger('INBOX_LINK_DELETE', $user, array('link' => (int) $l->rowid, 'mailref' => (int) $l->fk_mailref, 'element_type' => (string) $l->element_type,
				'fk_element' => (int) $l->fk_element, 'origin' => (string) $l->origin));
		}

		return 1;
	}

	/**
	 * Links of mails
	 *
	 * @param int[] $mailrefIds Mailrefs
	 * @return array<int,array{id:int,mailref:int,type:string,element:int,origin:string,author:int,date:int}>
	 */
	public function listFor(array $mailrefIds)
	{
		$mailrefIds = array_filter(array_map('intval', $mailrefIds));
		if (!$mailrefIds) {
			return array();
		}
		$rows = $this->db->getRows("SELECT rowid, fk_mailref, element_type, fk_element, origin, fk_user_creat, date_creation FROM ".$this->db->prefix()."inbox_link"
			." WHERE fk_mailref IN (".implode(',', $mailrefIds).") ORDER BY date_creation, rowid LIMIT 1000");
		$out = array();
		foreach (is_array($rows) ? $rows : array() as $r) {
			$out[] = array('id' => (int) $r->rowid, 'mailref' => (int) $r->fk_mailref, 'type' => (string) $r->element_type, 'element' => (int) $r->fk_element,
				'origin' => (string) $r->origin, 'author' => (int) $r->fk_user_creat, 'date' => (int) $this->db->jdate($r->date_creation));
		}

		return $out;
	}

	/**
	 * Mailrefs linked to a piece (and, for a thirdparty, to its pieces)
	 *
	 * @param string $type      Type
	 * @param int    $elementId Piece
	 * @return int[]
	 */
	public function mailrefsOf($type, $elementId)
	{
		$p = $this->db->prefix();
		$or = array("(element_type = '".$this->db->escape((string) $type)."' AND fk_element = ".((int) $elementId).")");
		if ($type === 'societe') {
			foreach (InboxElements::TYPES as $t => $def) {
				// Only the modules enabled: the table of a disabled one may not exist
				if ($t !== 'societe' && $def['soc'] === 'fk_soc' && InboxElements::enabled($t)) {
					$or[] = "(element_type = '".$t."' AND fk_element IN (SELECT ".($def['key'] ?? 'rowid')." FROM ".$p.$def['table']." WHERE fk_soc = ".((int) $elementId)."))";
				}
			}
		}
		$rows = $this->db->getRows("SELECT DISTINCT fk_mailref FROM ".$p."inbox_link WHERE ".implode(' OR ', $or)." LIMIT 50000");

		return array_map(function ($r) {
			return (int) $r->fk_mailref;
		}, is_array($rows) ? $rows : array());
	}

	/**
	 * A piece removed from Dolibarr: its links go (the mailref and its notes stay)
	 *
	 * @param string $type      Type
	 * @param int    $elementId Piece
	 * @return void
	 */
	public function forgetElement($type, $elementId)
	{
		$this->db->query("DELETE FROM ".$this->db->prefix()."inbox_link WHERE element_type = '".$this->db->escape((string) $type)."' AND fk_element = ".((int) $elementId));
	}

	/**
	 * @param string              $code Trigger
	 * @param User|null           $user User
	 * @param array<string,mixed> $data Data (context['inbox'] of an InboxAccount, as the other triggers of inbox)
	 * @return void
	 */
	protected function trigger($code, $user, array $data)
	{
		require_once __DIR__.'/inboxaccount.class.php';
		// A module calling without user: the user of the request (or of the scheduled job) is the author of the trigger
		$author = $user instanceof User ? $user : ($GLOBALS['user'] ?? null);
		if (!$author instanceof User) {
			return;
		}
		$account = new InboxAccount($this->db);
		$row = $this->db->getRow("SELECT last_account FROM ".$this->db->prefix()."inbox_mailref WHERE rowid = ".((int) $data['mailref']));
		if (is_object($row) && (int) $row->last_account > 0) {
			$account->fetch((int) $row->last_account);
		}
		$account->context['inbox'] = $data;
		$account->call_trigger($code, $author);
	}
}
