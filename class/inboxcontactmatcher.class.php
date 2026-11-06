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
 * \file    inbox/class/inboxcontactmatcher.class.php
 * \ingroup inbox
 * \brief   Thirdparty and contact of Dolibarr of an address (exact address; the domain comes with lot W5)
 */

/**
 * Exact address of the sender looked for in the contacts, then in the thirdparties of the entity. Cache for the pass.
 */
class InboxContactMatcher
{
	/** @var DoliDB */
	protected $db;

	/** @var array<string,array{fk_soc:int,fk_socpeople:int}> */
	protected $cache = array();

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct(DoliDB $db)
	{
		$this->db = $db;
	}

	/**
	 * @param string $email Address, lower case
	 * @return array{fk_soc:int,fk_socpeople:int} 0 when not found
	 */
	public function match($email)
	{
		$email = strtolower(trim((string) $email));
		if ($email === '' || strpos($email, '@') === false) {
			return array('fk_soc' => 0, 'fk_socpeople' => 0);
		}
		if (isset($this->cache[$email])) {
			return $this->cache[$email];
		}
		$p = $this->db->prefix();
		$e = $this->db->escape($email);
		$out = array('fk_soc' => 0, 'fk_socpeople' => 0);
		$contact = $this->db->getRow("SELECT rowid, fk_soc FROM ".$p."socpeople WHERE LOWER(email) = '".$e."' AND entity IN (".getEntity('contact').") AND statut = 1 ORDER BY rowid");
		if (is_object($contact)) {
			$out = array('fk_soc' => (int) $contact->fk_soc, 'fk_socpeople' => (int) $contact->rowid);
		} else {
			$soc = $this->db->getRow("SELECT rowid FROM ".$p."societe WHERE LOWER(email) = '".$e."' AND entity IN (".getEntity('societe').") AND status = 1 ORDER BY rowid");
			if (is_object($soc)) {
				$out['fk_soc'] = (int) $soc->rowid;
			}
		}
		if (count($this->cache) > 5000) {
			$this->cache = array();
		}
		$this->cache[$email] = $out;

		return $out;
	}

	/**
	 * Match again the mails of an address (a contact or a thirdparty was created or got this address)
	 *
	 * @param string $email Address
	 * @return int           Rows updated
	 */
	public function refreshAddress($email)
	{
		unset($this->cache[strtolower(trim((string) $email))]);
		$m = $this->match($email);
		$sql = "UPDATE ".$this->db->prefix()."inbox_mail SET fk_soc = ".($m['fk_soc'] ? (int) $m['fk_soc'] : 'NULL').", fk_socpeople = ".($m['fk_socpeople'] ? (int) $m['fk_socpeople'] : 'NULL');
		$sql .= " WHERE from_email = '".$this->db->escape(strtolower(trim((string) $email)))."'";

		return $this->db->query($sql) ? (int) $this->db->affected_rows(true) : 0;
	}
}
