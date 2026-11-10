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
 * \file    inbox/test/phpunit/InboxTestCase.php
 * \ingroup inbox
 * \brief   Base of the tests of inbox: test data with addresses @inbox-test.invalid, removed before and after
 */

use PHPUnit\Framework\TestCase;

dol_include_once('/inbox/class/inboxaccess.class.php');
dol_include_once('/inbox/class/inboxidentity.class.php');
dol_include_once('/inbox/class/inboxtag.class.php');
dol_include_once('/inbox/class/inboxuserparam.class.php');
dol_include_once('/inbox/class/inboxservertester.class.php');
dol_include_once('/inbox/class/inboxaccountimport.class.php');
dol_include_once('/inbox/class/inbox.class.php');

/**
 * Base class
 */
abstract class InboxTestCase extends TestCase
{
	/** Local test Dovecot (dev/dovecot/run.sh) */
	const IMAP_HOST = '127.0.0.1';
	const IMAP_PORT = 10143;
	const SMTP_PORT = 10587;
	const SIEVE_PORT = 14190;
	const BOX = 'devis@inbox-test.invalid';
	const BOX_PASSWORD = 'devis-test-pass';

	/** @var DoliDB */
	protected $db;

	/** @var User */
	protected $admin;

	/**
	 * @return void
	 */
	protected function setUp(): void
	{
		global $user;

		$this->db = $GLOBALS['inbox_test_db'];
		$this->admin = $user;
		self::cleanup($this->db);
		InboxAccess::clearCache();
		if (class_exists('InboxTrustedDomain')) {
			InboxTrustedDomain::clearCache();
		}
	}

	/**
	 * @return void
	 */
	protected function tearDown(): void
	{
		self::cleanup($this->db);
	}

	/**
	 * Remove every test row (addresses and logins of the tests only)
	 *
	 * @param DoliDB $db Database
	 * @return void
	 */
	public static function cleanup($db)
	{
		$p = $db->prefix();
		$rows = $db->getRows("SELECT rowid FROM ".$p."inbox_account WHERE email LIKE '%@inbox-test.invalid' LIMIT 10000");
		foreach (is_array($rows) ? $rows : array() as $r) {
			$db->query("DELETE FROM ".$p."inbox_mail_address WHERE fk_mail IN (SELECT rowid FROM ".$p."inbox_mail WHERE fk_account = ".((int) $r->rowid).")");
			foreach (array('inbox_mail', 'inbox_folder', 'inbox_account_access', 'inbox_account_log', 'inbox_identity', 'inbox_tag', 'inbox_userparam', 'inbox_action_log', 'inbox_outbox', 'inbox_notification', 'inbox_template_account') as $t) {
				$db->query("DELETE FROM ".$p.$t." WHERE fk_account = ".((int) $r->rowid));
			}
		}
		// Assignments and notifications (1.9.0): test mails have a Message-ID of the test domain
		$refs = "SELECT rowid FROM ".$p."inbox_mailref WHERE message_id LIKE '%@inbox-test.invalid%'";
		$db->query("DELETE FROM ".$p."inbox_mail_assign WHERE fk_mailref IN (".$refs.") OR fk_user IN (SELECT rowid FROM ".$p."user WHERE login LIKE 'inboxtest_%')");
		$db->query("DELETE FROM ".$p."inbox_notification WHERE fk_mailref IN (".$refs.") OR fk_user IN (SELECT rowid FROM ".$p."user WHERE login LIKE 'inboxtest_%')");
		$db->query("DELETE FROM ".$p."inbox_link WHERE fk_mailref IN (".$refs.")");
		// Events recorded on pieces by the sending tests (1.17.0)
		$db->query("DELETE FROM ".$p."actioncomm_resources WHERE fk_actioncomm IN (SELECT id FROM ".$p."actioncomm WHERE email_msgid LIKE '%@inbox-test.invalid')");
		$db->query("DELETE FROM ".$p."actioncomm WHERE email_msgid LIKE '%@inbox-test.invalid'");
		$db->query("DELETE FROM ".$p."inbox_note WHERE fk_mailref IN (".$refs.")");
		// Mails without Message-ID of the tests: their key is a print, the mailref keeps the sender
		$db->query("DELETE FROM ".$p."inbox_link WHERE fk_mailref IN (SELECT rowid FROM ".$p."inbox_mailref WHERE from_email LIKE '%inbox-test.invalid')");
		$db->query("DELETE FROM ".$p."inbox_note WHERE fk_mailref IN (SELECT rowid FROM ".$p."inbox_mailref WHERE from_email LIKE '%inbox-test.invalid')");
		$db->query("DELETE FROM ".$p."inbox_mail_assign WHERE fk_mailref IN (SELECT rowid FROM ".$p."inbox_mailref WHERE from_email LIKE '%inbox-test.invalid')");
		$db->query("DELETE FROM ".$p."inbox_mailref WHERE message_id LIKE '%@inbox-test.invalid%' OR from_email LIKE '%inbox-test.invalid'");
		// Templates (1.18.0): named ZzTpl, contacts of the tests
		$db->query("DELETE FROM ".$p."inbox_template_account WHERE fk_template IN (SELECT rowid FROM ".$p."c_email_templates WHERE label LIKE 'ZzTpl%')");
		$db->query("DELETE FROM ".$p."c_email_templates WHERE label LIKE 'ZzTpl%'");
		$db->query("DELETE FROM ".$p."socpeople WHERE lastname LIKE 'inboxtest_%'");
		$db->query("DELETE FROM ".$p."societe WHERE nom LIKE 'inboxtest_%'");
		// Delivery reports (1.25.0) of the test mails, and the events they added on pieces
		$db->query("DELETE FROM ".$p."inbox_bounce WHERE message_id LIKE '%inbox-test.invalid%' OR recipient LIKE '%.invalid'");
		$db->query("DELETE FROM ".$p."actioncomm WHERE code = 'AC_INBOX_BOUNCE' AND label LIKE '%.invalid%'");
		$db->query("DELETE FROM ".$p."inbox_account WHERE email LIKE '%@inbox-test.invalid'");
		$db->query("DELETE FROM ".$p."inbox_message WHERE mailbox LIKE '%@inbox-test.invalid'");
		$db->query("DELETE FROM ".$p."inbox_readlog WHERE mailbox LIKE '%@inbox-test.invalid'");
		$db->query("DELETE FROM ".$p."inbox_tag WHERE fk_account = 0 AND keyword LIKE 'zz_test%'");
		// Recognized domains (1.59.0) of the tests
		$db->query("DELETE FROM ".$p."inbox_trusted_domain WHERE label LIKE 'ZzTrusted%'");
		// Shared domains (1.76.0) of the tests
		$db->query("DELETE FROM ".$p."inbox_domain WHERE origin = 'local' AND domain LIKE '%inbox-test.invalid'");
		if (class_exists('InboxDomains')) {
			InboxDomains::reset();
		}
		// Data sources (1.72.0) of the tests
		$db->query("DELETE FROM ".$p."inbox_source WHERE label LIKE 'ZzSource%'");
		if (class_exists('InboxDataSource')) {
			InboxDataSource::reset();
		}
		// Virtual views (1.31.0) of the test users and their shares
		$views = "SELECT rowid FROM ".$p."inbox_view WHERE fk_user IN (SELECT rowid FROM ".$p."user WHERE login LIKE 'inboxtest_%') OR label LIKE 'ZzView%'";
		$db->query("DELETE FROM ".$p."inbox_view_share WHERE fk_view IN (".$views.") OR fk_user IN (SELECT rowid FROM ".$p."user WHERE login LIKE 'inboxtest_%')");
		$db->query("DELETE FROM ".$p."inbox_view WHERE fk_user IN (SELECT rowid FROM ".$p."user WHERE login LIKE 'inboxtest_%') OR label LIKE 'ZzView%'");
		$db->query("DELETE FROM ".$p."usergroup_user WHERE fk_user IN (SELECT rowid FROM ".$p."user WHERE login LIKE 'inboxtest_%')");
		$db->query("DELETE FROM ".$p."inbox_userparam WHERE fk_user IN (SELECT rowid FROM ".$p."user WHERE login LIKE 'inboxtest_%')");
		$db->query("DELETE FROM ".$p."inbox_image_allow WHERE fk_user IN (SELECT rowid FROM ".$p."user WHERE login LIKE 'inboxtest_%')");
		$db->query("DELETE FROM ".$p."user WHERE login LIKE 'inboxtest_%'");
		$db->query("DELETE FROM ".$p."usergroup WHERE nom LIKE 'inboxtest_%'");
	}

	/**
	 * @return bool The local test Dovecot answers
	 */
	protected static function dovecotUp()
	{
		$s = @fsockopen(self::IMAP_HOST, self::IMAP_PORT, $errno, $errstr, 1);
		if ($s) {
			fclose($s);
			return true;
		}

		return false;
	}

	/**
	 * @return void
	 */
	protected function needDovecot()
	{
		if (!self::dovecotUp()) {
			$this->markTestSkipped('Local test Dovecot not running (dev/dovecot/run.sh)');
		}
	}

	/**
	 * Mailbox on the local test Dovecot, not saved unless asked
	 *
	 * @param bool  $save  Create it
	 * @param array<string,mixed> $set Values to change
	 * @return InboxAccount
	 */
	protected function box($save = false, $set = array())
	{
		$a = new InboxAccount($this->db);
		$a->email = self::BOX;
		$a->imap_host = self::IMAP_HOST;
		$a->imap_port = self::IMAP_PORT;
		$a->imap_encryption = 'none';
		$a->smtp_port = self::SMTP_PORT;
		$a->smtp_encryption = 'none';
		$a->sieve_port = self::SIEVE_PORT;
		$a->setImapPassword(self::BOX_PASSWORD);
		foreach ($set as $k => $v) {
			$a->$k = $v;
		}
		if ($save) {
			$this->assertGreaterThan(0, $a->create($this->admin), implode(',', $a->errors));
		}

		return $a;
	}

	/**
	 * Internal (or external) test user
	 *
	 * @param string $login Login (prefix inboxtest_ added)
	 * @param int    $socid Thirdparty for an external user
	 * @return User
	 */
	protected function user($login, $socid = 0)
	{
		$p = $this->db->prefix();
		$this->db->query("INSERT INTO ".$p."user (entity, login, lastname, firstname, statut, fk_soc, datec) VALUES (1, 'inboxtest_".$this->db->escape($login)."', '".$this->db->escape($login)."', 'T', 1, ".($socid ? (int) $socid : 'NULL').", '".$this->db->idate(dol_now())."')");
		$u = new User($this->db);
		$u->fetch((int) $this->db->last_insert_id($p.'user'));
		$u->id = (int) $u->id;

		return $u;
	}
}
