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
 * \file    inbox/test/phpunit/bootstrap.php
 * \ingroup inbox
 * \brief   Start of the tests of inbox: Dolibarr loaded on a TEST database only, never a real one (principle P17)
 *
 * Usage (from the module directory):
 *   INBOX_DOLIBARR_HTDOCS=/path/to/htdocs DOLI_TEST_DB=dolibarr_v24 ~/bin/phpunit -c test/phpunit/phpunit.xml
 * The database must be one of the known test databases below, and named in DOLI_TEST_DB. The tests of the IMAP
 * connector need the local test Dovecot (dev/dovecot/run.sh then dev/dovecot/seed.py), else they are skipped.
 */

// From the command line only, never from the web (audit INBX-024)
if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

$testDatabases = array('dolibarr_develop_vanillia', 'dolibarr_v24', 'dolibarr_v18', 'dolibarr_demo');
$wanted = (string) getenv('DOLI_TEST_DB');
if (!in_array($wanted, $testDatabases, true)) {
	fwrite(STDERR, "DOLI_TEST_DB must be one of the test databases: ".implode(', ', $testDatabases)."\n");
	exit(1);
}
$htdocs = (string) getenv('INBOX_DOLIBARR_HTDOCS');
if ($htdocs === '') {
	$htdocs = dirname(__DIR__, 4);
}
if (!is_file($htdocs.'/master.inc.php')) {
	fwrite(STDERR, "master.inc.php not found in $htdocs (set INBOX_DOLIBARR_HTDOCS)\n");
	exit(1);
}
if (!defined('NOSESSION')) {
	define('NOSESSION', '1');
}
if (!defined('NOCSRFCHECK')) {
	define('NOCSRFCHECK', '1');
}
if (!defined('NOREQUIREMENU')) {
	define('NOREQUIREMENU', '1');
}
if (!defined('NOREQUIREHTML')) {
	define('NOREQUIREHTML', '1');
}
$_SERVER['REQUEST_METHOD'] = 'GET';
// PHPUnit includes this file from a function: the variables of Dolibarr must be declared global first
global $conf, $user, $langs, $db, $mysoc, $hookmanager, $dolibarr_main_url_root, $dolibarr_main_document_root, $dolibarr_main_data_root;
global $dolibarr_main_db_host, $dolibarr_main_db_port, $dolibarr_main_db_name, $dolibarr_main_db_user, $dolibarr_main_db_pass, $dolibarr_main_db_type, $dolibarr_main_db_prefix;
global $dolibarr_main_instance_unique_id, $dolibarr_main_url_root_alt, $dolibarr_main_document_root_alt, $dolibarr_main_authentication;
require_once $htdocs.'/master.inc.php';
/** @var DoliDB $db */
if ($db->database_name !== $wanted) {
	// The conf.php of the instance must take the database from DOLI_TEST_DB, else the tests would write elsewhere
	fwrite(STDERR, "The instance uses the database ".$db->database_name.", not ".$wanted.": stop\n");
	exit(1);
}
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
$GLOBALS['inbox_test_db'] = $db;
// Admin of the test database (first admin user)
$row = $db->getRow("SELECT rowid FROM ".$db->prefix()."user WHERE admin = 1 AND statut = 1 ORDER BY rowid");
$user->fetch(is_object($row) ? (int) $row->rowid : 1);
$user->getrights();
$langs->setDefaultLang('fr_FR');
$langs->loadLangs(array('main', 'inbox@inbox'));
// The module, enabled on the test database (replay safe)
$res = activateModule('modInbox');
if (!empty($res['errors'])) {
	fwrite(STDERR, "Activation of inbox failed: ".implode(', ', $res['errors'])."\n");
	exit(1);
}
$conf->inbox = isset($conf->inbox) ? $conf->inbox : new stdClass();
$conf->inbox->enabled = 1;
$conf->inbox->dir_output = sys_get_temp_dir().'/inbox-phpunit-'.getmypid();

require_once __DIR__.'/InboxTestCase.php';
