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
 * \file    inbox/class/inboxidentity.class.php
 * \ingroup inbox
 * \brief   Sending addresses of a mailbox
 */

/**
 * An identity is a From of a mailbox: address (the one of the mailbox, or an alias of it), name, optional Reply-To and
 * Bcc, signature. The signature belongs to the identity, so to the mailbox, never to a user (decided 2026-11-06).
 * One identity per mailbox is the default one. A signature can be copied from a sender profile of Dolibarr
 * (llx_c_email_senderprofile): it is a copy, no permanent link.
 */
class InboxIdentity
{
	/** @var DoliDB */
	protected $db;

	/** @var int */
	public $id = 0;
	/** @var int */
	public $fk_account = 0;
	/** @var string */
	public $email = '';
	/** @var string */
	public $name = '';
	/** @var string */
	public $reply_to = '';
	/** @var string */
	public $bcc = '';
	/** @var string HTML, cleaned of any script on save */
	public $signature = '';
	/** @var int */
	public $is_default = 0;
	/** @var int */
	public $position = 0;
	/** @var int */
	public $status = 1;

	/** @var string */
	public $error = '';
	/** @var string[] */
	public $errors = array();

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct(DoliDB $db)
	{
		$this->db = $db;
	}

	/**
	 * @param int $id Id
	 * @return int     > 0 if found, 0 if not, < 0 if KO
	 */
	public function fetch($id)
	{
		$row = $this->db->getRow("SELECT * FROM ".$this->db->prefix()."inbox_identity WHERE rowid = ".((int) $id));
		if ($row === false) {
			return -1;
		}
		if (!is_object($row)) {
			return 0;
		}
		$this->setFromRow($row);

		return 1;
	}

	/**
	 * @param stdClass $row Row of the table
	 * @return void
	 */
	protected function setFromRow($row)
	{
		$this->id = (int) $row->rowid;
		$this->fk_account = (int) $row->fk_account;
		$this->email = (string) $row->email;
		$this->name = (string) $row->name;
		$this->reply_to = (string) $row->reply_to;
		$this->bcc = (string) $row->bcc;
		$this->signature = (string) $row->signature;
		$this->is_default = (int) $row->is_default;
		$this->position = (int) $row->position;
		$this->status = (int) $row->status;
	}

	/**
	 * Identities of a mailbox, default first then by position
	 *
	 * @param int  $accountId  Mailbox
	 * @param bool $activeOnly Only the active ones
	 * @return InboxIdentity[]
	 */
	public function listForAccount($accountId, $activeOnly = false)
	{
		$out = array();
		$sql = "SELECT * FROM ".$this->db->prefix()."inbox_identity WHERE fk_account = ".((int) $accountId);
		$sql .= $activeOnly ? " AND status = 1" : '';
		$sql .= " ORDER BY is_default DESC, position, rowid LIMIT 1000";
		$rows = $this->db->getRows($sql);
		foreach (is_array($rows) ? $rows : array() as $row) {
			$identity = new self($this->db);
			$identity->setFromRow($row);
			$out[] = $identity;
		}

		return $out;
	}

	/**
	 * Check and clean the values; errors are language keys in $this->errors
	 *
	 * @return bool
	 */
	public function check()
	{
		$this->errors = array();
		$this->email = strtolower(trim((string) $this->email));
		$this->name = trim(str_replace(array("\r", "\n"), ' ', (string) $this->name));
		$this->reply_to = strtolower(trim((string) $this->reply_to));
		$this->bcc = strtolower(trim((string) $this->bcc));
		if (!isValidEmail($this->email)) {
			$this->errors[] = 'InboxErrorIdentityEmail';
		}
		foreach (array($this->reply_to, $this->bcc) as $address) {
			if ($address !== '' && !isValidEmail($address)) {
				$this->errors[] = 'InboxErrorIdentityAddress';
			}
		}
		if ((int) $this->fk_account <= 0) {
			$this->errors[] = 'InboxErrorAccountNotFound';
		}
		// The signature is shown in the editor and sent in the mails: no script, no event attribute
		$this->signature = trim((string) $this->signature) === '' ? '' : (string) dol_htmlwithnojs((string) $this->signature);
		$this->errors = array_values(array_unique($this->errors));
		$this->error = implode(', ', $this->errors);

		return empty($this->errors);
	}

	/**
	 * @param User $user User
	 * @return int       Id, < 0 if KO
	 */
	public function create(User $user)
	{
		if (!$this->check()) {
			return -1;
		}
		$prefix = $this->db->prefix();
		// The first identity of a mailbox is its default one
		$count = $this->db->getRow("SELECT COUNT(rowid) AS n FROM ".$prefix."inbox_identity WHERE fk_account = ".((int) $this->fk_account));
		if (is_object($count) && (int) $count->n === 0) {
			$this->is_default = 1;
		}
		$this->db->begin();
		$sql = "INSERT INTO ".$prefix."inbox_identity (fk_account, email, name, reply_to, bcc, signature, is_default, position, status, date_creation, fk_user_creat)";
		$sql .= " VALUES (".((int) $this->fk_account).", '".$this->db->escape($this->email)."', '".$this->db->escape($this->name)."',";
		$sql .= " '".$this->db->escape($this->reply_to)."', '".$this->db->escape($this->bcc)."', '".$this->db->escape($this->signature)."',";
		$sql .= " ".($this->is_default ? 1 : 0).", ".((int) $this->position).", ".($this->status ? 1 : 0).", '".$this->db->idate(dol_now())."', ".((int) $user->id).")";
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			return -2;
		}
		$this->id = (int) $this->db->last_insert_id($prefix.'inbox_identity');
		if ($this->is_default && $this->clearOtherDefaults() < 0) {
			$this->db->rollback();
			return -3;
		}
		$this->db->commit();

		return $this->id;
	}

	/**
	 * @param User $user User
	 * @return int       > 0 if OK, < 0 if KO
	 */
	public function update(User $user)
	{
		if (!$this->check()) {
			return -1;
		}
		$this->db->begin();
		$sql = "UPDATE ".$this->db->prefix()."inbox_identity SET";
		$sql .= " email = '".$this->db->escape($this->email)."', name = '".$this->db->escape($this->name)."',";
		$sql .= " reply_to = '".$this->db->escape($this->reply_to)."', bcc = '".$this->db->escape($this->bcc)."',";
		$sql .= " signature = '".$this->db->escape($this->signature)."', is_default = ".($this->is_default ? 1 : 0).",";
		$sql .= " position = ".((int) $this->position).", status = ".($this->status ? 1 : 0).", fk_user_modif = ".((int) $user->id);
		$sql .= " WHERE rowid = ".((int) $this->id)." AND fk_account = ".((int) $this->fk_account);
		if (!$this->db->query($sql) || ($this->is_default && $this->clearOtherDefaults() < 0)) {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			return -2;
		}
		$this->db->commit();

		return 1;
	}

	/**
	 * Delete; the default identity passes to the next one
	 *
	 * @return int > 0 if OK, < 0 if KO
	 */
	public function delete()
	{
		$prefix = $this->db->prefix();
		$this->db->begin();
		if (!$this->db->query("DELETE FROM ".$prefix."inbox_identity WHERE rowid = ".((int) $this->id))) {
			$this->db->rollback();
			return -1;
		}
		if ($this->is_default) {
			$next = $this->db->getRow("SELECT rowid FROM ".$prefix."inbox_identity WHERE fk_account = ".((int) $this->fk_account)." ORDER BY status DESC, position, rowid");
			if (is_object($next)) {
				$this->db->query("UPDATE ".$prefix."inbox_identity SET is_default = 1 WHERE rowid = ".((int) $next->rowid));
			}
		}
		$this->db->commit();

		return 1;
	}

	/**
	 * @return int > 0 if OK
	 */
	protected function clearOtherDefaults()
	{
		$ok = $this->db->query("UPDATE ".$this->db->prefix()."inbox_identity SET is_default = 0 WHERE fk_account = ".((int) $this->fk_account)." AND rowid <> ".((int) $this->id));

		return $ok ? 1 : -1;
	}

	/**
	 * Sender profiles of Dolibarr that can be copied, the ones of the same address first
	 *
	 * @param string $email Address of the mailbox
	 * @return array<int,array{id:int,label:string,email:string,has_signature:bool,same:bool}>
	 */
	public function listSenderProfiles($email)
	{
		global $conf;

		$email = strtolower(trim((string) $email));
		$out = array();
		$rows = $this->db->getRows("SELECT rowid, label, email, signature FROM ".$this->db->prefix()."c_email_senderprofile"
			." WHERE active = 1 AND private = 0 AND entity IN (".getEntity('c_email_senderprofile').") ORDER BY position, label LIMIT 500");
		foreach (is_array($rows) ? $rows : array() as $row) {
			$out[] = array(
				'id' => (int) $row->rowid,
				'label' => (string) $row->label,
				'email' => strtolower(trim((string) $row->email)),
				'has_signature' => trim(strip_tags((string) $row->signature)) !== '',
				'same' => strtolower(trim((string) $row->email)) === $email,
			);
		}
		usort($out, function ($a, $b) {
			return (int) $b['same'] - (int) $a['same'];
		});

		return $out;
	}

	/**
	 * Copy the name and the signature of a sender profile of Dolibarr into this identity (not saved: the caller shows
	 * the preview, then saves)
	 *
	 * @param int $profileId Sender profile
	 * @return bool
	 */
	public function copyFromSenderProfile($profileId)
	{
		$row = $this->db->getRow("SELECT label, email, signature FROM ".$this->db->prefix()."c_email_senderprofile"
			." WHERE rowid = ".((int) $profileId)." AND active = 1 AND private = 0 AND entity IN (".getEntity('c_email_senderprofile').")");
		if (!is_object($row)) {
			$this->error = 'InboxErrorSenderProfile';
			return false;
		}
		$this->name = (string) $row->label;
		$this->signature = trim((string) $row->signature) === '' ? '' : (string) dol_htmlwithnojs((string) $row->signature);
		if ($this->email === '' && isValidEmail((string) $row->email)) {
			$this->email = strtolower(trim((string) $row->email));
		}

		return true;
	}

	/**
	 * @return array<string,mixed> For the browser
	 */
	public function toArray()
	{
		return array('id' => $this->id, 'fk_account' => $this->fk_account, 'email' => $this->email, 'name' => $this->name, 'reply_to' => $this->reply_to,
			'bcc' => $this->bcc, 'signature' => $this->signature, 'is_default' => $this->is_default, 'position' => $this->position, 'status' => $this->status);
	}
}
