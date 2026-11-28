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
 * \file    inbox/test/phpunit/InboxOverviewTestCase.php
 * \ingroup inbox
 * \brief   Helpers of the tests of the overview and of the widgets: a mailbox, mails, thirdparties, invoices, users
 */

if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

require_once __DIR__.'/InboxTestCase.php';
require_once dirname(__DIR__, 2).'/class/inboxoverview.class.php';

/**
 * Database only, no mail server
 */
abstract class InboxOverviewTestCase extends InboxTestCase
{
	/** Prefix of the invoices and tickets of the tests */
	const REF = 'ZZOV-';

	/**
	 * @return void
	 */
	protected function tearDown(): void
	{
		$p = $this->db->prefix();
		$this->db->query("DELETE FROM ".$p."facture WHERE ref LIKE '".self::REF."%'");
		if ($this->db->getRow("SHOW TABLES LIKE '".$p."ticket'")) {
			$this->db->query("DELETE FROM ".$p."ticket WHERE ref LIKE '".self::REF."%'");
		}
		parent::tearDown();
	}

	/**
	 * @param int    $accountId Mailbox
	 * @param string $from      Sender
	 * @return object Row of the index
	 */
	protected function mail($accountId, $from)
	{
		static $uid = 7000;
		$p = $this->db->prefix();
		$row = $this->db->getRow("SELECT rowid FROM ".$p."inbox_folder WHERE fk_account = ".((int) $accountId)." AND path = 'INBOX'");
		if (is_object($row)) {
			$folder = (int) $row->rowid;
		} else {
			$this->db->query("INSERT INTO ".$p."inbox_folder (fk_account, path, name, delimiter, role, status) VALUES (".((int) $accountId).", 'INBOX', 'INBOX', '.', 'inbox', 1)");
			$folder = (int) $this->db->last_insert_id($p.'inbox_folder');
		}
		$uid++;
		$this->db->query("INSERT INTO ".$p."inbox_mail (fk_account, fk_folder, remote_id, sort_key, message_id, date_mail, date_received, from_email, subject, size, date_index) VALUES ("
			.((int) $accountId).", ".$folder.", '".$uid."', ".$uid.", 'ov".$uid."@inbox-test.invalid', '2026-11-02 10:00:00', '2026-11-02 10:00:05',"
			." '".$this->db->escape($from)."', 'Relance', 1234, '".$this->db->idate(dol_now())."')");

		return $this->db->getRow("SELECT * FROM ".$p."inbox_mail WHERE rowid = ".((int) $this->db->last_insert_id($p.'inbox_mail')));
	}

	/**
	 * @param string $name  Name
	 * @param string $email Mail
	 * @return int
	 */
	protected function soc($name, $email)
	{
		$p = $this->db->prefix();
		$this->db->query("INSERT INTO ".$p."societe (nom, entity, status, client, email, datec) VALUES ('".$this->db->escape($name)."', 1, 1, 1, '".$this->db->escape($email)."', '".$this->db->idate(dol_now())."')");

		return (int) $this->db->last_insert_id($p.'societe');
	}

	/**
	 * A validated invoice not paid, its due date in the past or not
	 *
	 * @param int  $soc  Thirdparty
	 * @param bool $late Due date past
	 * @return void
	 */
	protected function invoice($soc, $late)
	{
		static $n = 0;
		$n++;
		$p = $this->db->prefix();
		$this->db->query("INSERT INTO ".$p."facture (ref, entity, fk_soc, datef, date_lim_reglement, fk_statut, paye, type, total_ht, total_tva, total_ttc, datec) VALUES ('".self::REF.$n."', 1, ".((int) $soc).","
			." '".$this->db->idate(dol_now() - 40 * 86400)."', '".$this->db->idate(dol_now() + ($late ? -10 : 20) * 86400)."', 1, 0, 0, 100, 20, 120, '".$this->db->idate(dol_now())."')");
	}

	/**
	 * A user with the webmail and the given rights only (in memory)
	 *
	 * @param string                       $login  Login
	 * @param array<int,array<int,string>> $rights Rights (module, permission[, sub])
	 * @return User
	 */
	protected function withRights($login, array $rights)
	{
		$u = $this->user($login);
		$u->rights = new stdClass();
		foreach (array_merge(array(array('inbox', 'webmail', 'use'), array('societe', 'lire'), array('societe', 'client', 'voir')), $rights) as $r) {
			if (!isset($u->rights->{$r[0]})) {
				$u->rights->{$r[0]} = new stdClass();
			}
			if (count($r) === 3) {
				if (!isset($u->rights->{$r[0]}->{$r[1]})) {
					$u->rights->{$r[0]}->{$r[1]} = new stdClass();
				}
				$u->rights->{$r[0]}->{$r[1]}->{$r[2]} = 1;
			} else {
				$u->rights->{$r[0]}->{$r[1]} = 1;
			}
		}
		$u->all_permissions_are_loaded = 1;

		return $u;
	}
}
