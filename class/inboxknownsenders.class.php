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
 * \file    inbox/class/inboxknownsenders.class.php
 * \ingroup inbox
 * \brief   Senders known to Dolibarr and the checks of a sender: imitation, junk probably legitimate (lot W5 1.15.0)
 */

require_once __DIR__.'/inboxsenderauth.class.php';

/**
 * Known senders: exact addresses (never a domain) of the active customers (prospects alone excluded) and suppliers,
 * of the active contacts of these thirdparties, and of the active users of Dolibarr. A mail of a known sender:
 * - authenticated (DKIM, SPF, DMARC read in the headers of the trusted servers) and in the junk: probably legitimate;
 * - not authenticated: probable imitation (red alert), as a display name that writes the address of a known sender
 *   over another address.
 * Without trusted server in the setup, nothing is said (« not verifiable »).
 */
class InboxKnownSenders
{
	/** @var DoliDB */
	protected $db;

	/** @var array<string,array{socid:int,contactid:int,userid:int}|null> Address => sender (null = not known), read once per request */
	protected $cache = array();

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct(DoliDB $db)
	{
		$this->db = $db;
	}

	/**
	 * @return string[] Ids of the trusted receiving servers (setup INBOX_AUTH_SERVERS)
	 */
	public static function trustedServers()
	{
		return array_values(array_filter(array_map('trim', explode(',', strtolower(getDolGlobalString('INBOX_AUTH_SERVERS'))))));
	}

	/**
	 * Known sender of an address
	 *
	 * @param string $email Address
	 * @return array{socid:int,contactid:int,userid:int}|null The thirdparty is preferred to a contact
	 */
	public function find($email)
	{
		$email = strtolower(trim((string) $email));
		if ($email === '' || strpos($email, '@') === false) {
			return null;
		}
		if (array_key_exists($email, $this->cache)) {
			return $this->cache[$email];
		}
		$p = $this->db->prefix();
		// An email field may hold several addresses: rough filter in SQL, exact address checked here
		$like = $this->db->escape($this->db->escapeforlike($email));
		$found = null;
		$rows = $this->db->getRows("SELECT s.rowid, s.email FROM ".$p."societe as s WHERE s.entity IN (".getEntity('societe').") AND s.status = 1"
			." AND (s.client IN (1, 3) OR s.fournisseur = 1) AND s.email LIKE '%".$like."%' LIMIT 50");
		foreach (is_array($rows) ? $rows : array() as $r) {
			if (self::holds((string) $r->email, $email)) {
				$found = array('socid' => (int) $r->rowid, 'contactid' => 0, 'userid' => 0);
				break;
			}
		}
		if (!$found) {
			$rows = $this->db->getRows("SELECT c.rowid, c.fk_soc, c.email FROM ".$p."socpeople as c INNER JOIN ".$p."societe as s ON s.rowid = c.fk_soc"
				." WHERE c.entity IN (".getEntity('contact').") AND c.statut = 1 AND s.status = 1 AND (s.client IN (1, 3) OR s.fournisseur = 1) AND c.email LIKE '%".$like."%' LIMIT 50");
			foreach (is_array($rows) ? $rows : array() as $r) {
				if (self::holds((string) $r->email, $email)) {
					$found = array('socid' => (int) $r->fk_soc, 'contactid' => (int) $r->rowid, 'userid' => 0);
					break;
				}
			}
		}
		if (!$found) {
			$u = $this->db->getRow("SELECT rowid FROM ".$p."user WHERE LOWER(email) = '".$this->db->escape($email)."' AND statut = 1");
			if (is_object($u)) {
				$found = array('socid' => 0, 'contactid' => 0, 'userid' => (int) $u->rowid);
			}
		}
		if (count($this->cache) > 5000) {
			$this->cache = array();
		}
		$this->cache[$email] = $found;

		return $found;
	}

	/**
	 * @param string $field Email field (maybe several addresses)
	 * @param string $email Address
	 * @return bool
	 */
	protected static function holds($field, $email)
	{
		foreach (preg_split('/[\s,;]+/', strtolower($field)) as $e) {
			if (trim($e, " \t<>\"'") === $email) {
				return true;
			}
		}

		return false;
	}

	/**
	 * What the headers of a mail say of its sender
	 *
	 * @param string $rawHeaders Raw headers
	 * @return array{state:string,sender:array{socid:int,contactid:int,userid:int}|null,address:string,detail:string}
	 *         state: imitation, legit (known and authenticated), unknown (not known, or not verifiable)
	 */
	public function check($rawHeaders)
	{
		$out = array('state' => 'unknown', 'sender' => null, 'address' => '', 'detail' => '');
		$servers = self::trustedServers();
		if (!$servers) {
			return $out;
		}
		$headers = InboxSenderAuth::parseHeaders($rawHeaders);
		$from = InboxSenderAuth::analyzeFrom($headers);
		// A display name that writes the address of a known sender over another address
		foreach ($from['displayAddresses'] as $shown) {
			if (!in_array($shown, $from['addresses'], true) && ($known = $this->find($shown))) {
				return array('state' => 'imitation', 'sender' => $known, 'address' => $shown, 'detail' => 'display name');
			}
		}
		if ($from['ambiguous']) {
			return $out;
		}
		$known = $this->find($from['address']);
		if (!$known) {
			return $out;
		}
		$auth = InboxSenderAuth::check($headers, $from['address'], $servers);
		$out['sender'] = $known;
		$out['address'] = $from['address'];
		$out['detail'] = (string) $auth['detail'];
		if ($auth['result'] === InboxSenderAuth::RESULT_PASS) {
			$out['state'] = 'legit';
		} elseif ($auth['result'] === InboxSenderAuth::RESULT_FAIL) {
			$out['state'] = 'imitation';
		}

		return $out;
	}

	/**
	 * Synchronisation: the mails of the junk not checked yet (a known sender, authenticated: probably legitimate);
	 * only the headers are read, and only for the mails of a known sender
	 *
	 * @param InboxConnectorInterface $connector Connected
	 * @param int                     $folderId  Folder of the junk
	 * @param string                  $path      Its path
	 * @param float                   $deadline  Microtime limit
	 * @return int Mails probably legitimate found
	 */
	public function checkJunk($connector, $folderId, $path, $deadline)
	{
		$p = $this->db->prefix();
		$rows = $this->db->getRows("SELECT rowid, remote_id, from_email FROM ".$p."inbox_mail WHERE fk_folder = ".((int) $folderId)." AND junk_check = 0 ORDER BY rowid DESC LIMIT 100");
		$found = 0;
		require_once __DIR__.'/inboxblockedsender.class.php';
		$blocked = new InboxBlockedSender($this->db);
		foreach (is_array($rows) ? $rows : array() as $r) {
			if (microtime(true) > $deadline) {
				break;
			}
			$hint = 'NULL';
			$detail = 'NULL';
			// A blocked sender (1.85.0) is never « probably legitimate », even known and authenticated
			if (self::trustedServers() && !$blocked->isBlocked((string) $r->from_email) && $this->find((string) $r->from_email) && method_exists($connector, 'fetchHeaders')) {
				$raw = $connector->fetchHeaders($path, (string) $r->remote_id);
				if ($raw === null) {
					continue;
				}
				$res = $this->check($raw);
				if ($res['state'] === 'legit') {
					$hint = "'legit'";
					$found++;
				} elseif ($res['state'] === 'imitation') {
					$hint = "'imitation'";
				}
				$detail = $res['detail'] !== '' ? "'".$this->db->escape(dol_trunc($res['detail'], 250, 'right', 'UTF-8', 1))."'" : 'NULL';
			}
			$this->db->query("UPDATE ".$p."inbox_mail SET junk_hint = ".$hint.", junk_detail = ".$detail.", junk_check = 1 WHERE rowid = ".((int) $r->rowid));
		}

		return $found;
	}
}
