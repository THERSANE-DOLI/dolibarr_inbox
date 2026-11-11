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
 * \file    inbox/test/phpunit/InboxProtectTest.php
 * \ingroup inbox
 * \brief   Protected mailboxes (1.89.0, analyse-boites-protegees): keys, session, rights, nothing kept, no job
 */

dol_include_once('/inbox/class/inboxprotect.class.php');
dol_include_once('/inbox/class/inboxsync.class.php');
dol_include_once('/inbox/class/inboxlive.class.php');
dol_include_once('/inbox/class/inboxreader.class.php');
dol_include_once('/inbox/class/inboxactions.class.php');
dol_include_once('/inbox/class/inboxlivesend.class.php');
dol_include_once('/inbox/class/inboxmailbuilder.class.php');

/**
 * Tests of InboxProtect
 */
class InboxProtectTest extends InboxTestCase
{
	/**
	 * @return void
	 */
	protected function setUp(): void
	{
		parent::setUp();
		InboxProtect::lockAll();
		$_SESSION = array();
	}

	/**
	 * @return void
	 */
	protected function tearDown(): void
	{
		InboxProtect::lockAll();
		parent::tearDown();
	}

	/**
	 * Public key of a new P-256 key pair, as a browser gives it (JWK)
	 *
	 * @return array{kty:string,crv:string,x:string,y:string}
	 */
	protected static function jwk()
	{
		$key = openssl_pkey_new(array('curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC));
		$ec = openssl_pkey_get_details($key)['ec'];
		$b64u = function ($s) {
			return rtrim(strtr(base64_encode(str_pad($s, 32, "\0", STR_PAD_LEFT)), '+/', '-_'), '=');
		};

		return array('kty' => 'EC', 'crv' => 'P-256', 'x' => $b64u($ec['x']), 'y' => $b64u($ec['y']));
	}

	/**
	 * An encrypted copy of the right form (the server never opens it)
	 *
	 * @return array<string,mixed>
	 */
	protected static function blob()
	{
		$b64u = function ($s) {
			return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
		};

		return array('v' => 1, 'epk' => self::jwk(), 'iv' => $b64u(random_bytes(12)), 'ct' => $b64u(random_bytes(48)));
	}

	/**
	 * @return void
	 */
	public function testKeysAndCopies()
	{
		$jwk = self::jwk();
		$this->assertSame($jwk, InboxProtect::cleanJwk($jwk));
		$this->assertNull(InboxProtect::cleanJwk($jwk + array('d' => 'secret')), 'a private key is refused');
		$this->assertNull(InboxProtect::cleanJwk(array('crv' => 'P-384') + $jwk));
		$this->assertNull(InboxProtect::cleanJwk(array('x' => 'short') + $jwk));
		$this->assertMatchesRegularExpression('/^[0-9A-F]{4}(-[0-9A-F]{4}){3}$/', InboxProtect::fingerprint($jwk));
		$this->assertNotSame(InboxProtect::fingerprint($jwk), InboxProtect::fingerprint(self::jwk()));
		$this->assertNotNull(InboxProtect::cleanBlob(self::blob()));
		$this->assertNull(InboxProtect::cleanBlob(array('v' => 2) + self::blob()), 'other version');
		$this->assertNull(InboxProtect::cleanBlob(array('ct' => str_repeat('A', 4000)) + self::blob()), 'too big');
		$this->assertNull(InboxProtect::cleanBlob('not json'));

		// Same fingerprint as the browser (js/inbox-keys.js), when node is there
		$node = trim((string) shell_exec('command -v node 2>/dev/null'));
		if ($node !== '') {
			$js = 'const j=JSON.parse(process.argv[1]);const b=s=>Buffer.from(s.replace(/-/g,"+").replace(/_/g,"/"),"base64");'
				.'const r=Buffer.concat([Buffer.from([4]),b(j.x),b(j.y)]);'
				.'crypto.subtle.digest("SHA-256",r).then(h=>console.log(Buffer.from(h).toString("hex").slice(0,16).toUpperCase().match(/.{4}/g).join("-")));';
			$out = trim((string) shell_exec(escapeshellarg($node).' -e '.escapeshellarg($js).' '.escapeshellarg((string) json_encode($jwk)).' 2>/dev/null'));
			if ($out !== '') {
				$this->assertSame(InboxProtect::fingerprint($jwk), $out, 'fingerprint of the browser');
			}
		}
	}

	/**
	 * Audit of 2026-11-11: fingerprint computed from the key (INBX-063), master user (INBX-057), queue (INBX-062)
	 *
	 * @return void
	 */
	public function testAuditFixes()
	{
		global $conf;

		$p = $this->db->prefix();
		$protect = new InboxProtect($this->db);
		$device = $protect->registerDevice($this->admin, self::jwk(), 'ZzDevice audit');
		$other = self::jwk();
		$this->db->query("UPDATE ".$p."inbox_device SET public_key = '".$this->db->escape((string) json_encode($other))."' WHERE rowid = ".((int) $device['id']));
		$this->assertSame(InboxProtect::fingerprint($other), $protect->device($device['id'])['fingerprint'], 'a key changed in the database shows its own fingerprint');

		$box = $this->box(true, array('status' => InboxAccount::STATUS_ACTIVE));
		$this->assertSame('', InboxProtect::refusal($box));
		$saved = array(getDolGlobalString('INBOX_MASTER_USER'), getDolGlobalString('INBOX_MASTER_HOST'));
		$conf->global->INBOX_MASTER_USER = 'zzmaster';
		$conf->global->INBOX_MASTER_HOST = strtoupper((string) $box->imap_host);
		$this->assertSame('InboxProtectErrorMasterServer', InboxProtect::refusal($box), 'the master user opens every mailbox of its server');
		$conf->global->INBOX_MASTER_USER = $saved[0];
		$conf->global->INBOX_MASTER_HOST = $saved[1];

		$this->db->query("UPDATE ".$p."inbox_account SET protected = 1 WHERE rowid = ".((int) $box->id));
		$master = new InboxAccount($this->db);
		$master->email = $box->email;
		$master->type = InboxAccount::TYPE_SHARED;
		$master->connector = 'imap';
		$master->imap_auth_mode = InboxAccount::AUTH_MASTER;
		$master->imap_host = $box->imap_host;
		$master->check();
		$this->assertContains('InboxErrorAccountProtectedElsewhere', $master->errors, 'never opened by the master user');
		require_once __DIR__.'/../../class/inboxserver.class.php';
		$server = new InboxServer($this->db);
		$this->assertFalse($server->setMaster('zzmaster', (string) $box->imap_host, '*', ''), 'no master user on a server with protected mailboxes');
		$this->assertSame('InboxErrorMasterProtected', $server->error);
		$this->db->query("UPDATE ".$p."inbox_account SET protected = 0 WHERE rowid = ".((int) $box->id));
	}

	/**
	 * Files of a writing of a protected mailbox sealed by the key of the session (audit INBX-062)
	 *
	 * @return void
	 */
	public function testSealedWriting()
	{
		require_once __DIR__.'/../../class/inboxcomposer.class.php';
		$p = $this->db->prefix();
		$box = $this->box(true, array('status' => InboxAccount::STATUS_ACTIVE));
		(new InboxAccess($this->db))->grant($this->admin, $box->id, $this->admin->id, 0, 'manage');
		InboxAccess::clearCache();
		$i = new InboxIdentity($this->db);
		$i->fk_account = (int) $box->id;
		$i->email = self::BOX;
		$i->name = 'Sealed';
		$i->is_default = 1;
		$this->assertGreaterThan(0, $i->create($this->admin), implode(',', $i->errors));
		$c = new InboxComposer($this->db);

		// A normal mailbox: as before
		$open = $c->open($this->admin, 'new', 0, (int) $box->id);
		$this->assertNotNull($open, $c->errorCode.' '.$c->error);
		$dir = $c->held($this->admin, $open['token']);
		$this->assertFalse(InboxComposer::isSealed($dir));
		$c->close($this->admin, $open['token'], false);

		// Protected and unlocked: sealed, nothing in clear on the disk, read back in this session only
		$this->db->query("UPDATE ".$p."inbox_account SET protected = 1, protect_level = 'content', imap_password = NULL WHERE rowid = ".((int) $box->id));
		InboxProtect::unlock($box->id, array('imap' => self::BOX_PASSWORD));
		$open = $c->open($this->admin, 'new', 0, (int) $box->id);
		$this->assertNotNull($open, $c->errorCode.' '.$c->error);
		$dir = $c->held($this->admin, $open['token']);
		$this->assertTrue(InboxComposer::isSealed($dir));
		$secret = 'ZzSecretContent '.uniqid();
		$tmp = tempnam(sys_get_temp_dir(), 'ibx');
		file_put_contents($tmp, $secret);
		$this->assertNotNull($c->upload($this->admin, $open['token'], 'secret.txt', $tmp, 'text/plain'), $c->errorCode.' '.$c->error);
		@unlink($tmp);
		foreach ((array) glob($dir.'/*') as $f) {
			$this->assertStringNotContainsString($secret, (string) file_get_contents($f), basename($f).' in clear');
			$this->assertStringNotContainsString('"user"', (string) file_get_contents($f), basename($f).' state in clear');
		}
		$state = InboxComposer::readState($dir);
		$this->assertSame((int) $this->admin->id, (int) $state['user'], 'read back in the session');
		$file = $c->attachmentFile($this->admin, $open['token'], (string) $state['attachments'][0]['id']);
		$this->assertSame($secret, $file['content'] ?? '');
		// Another key (another session): nothing readable
		$cookie = $_COOKIE[InboxProtect::cookieName()];
		$_COOKIE[InboxProtect::cookieName()] = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
		$this->assertSame(array(), InboxComposer::readState($dir));
		$_COOKIE[InboxProtect::cookieName()] = $cookie;
		$c->close($this->admin, $open['token'], false);

		// Begun from a normal mailbox, the identity of the protected one chosen: sealed from then
		$other = new InboxAccount($this->db);
		$other->email = 'zzother@inbox-test.invalid';
		$other->imap_host = self::IMAP_HOST;
		$other->status = InboxAccount::STATUS_ACTIVE;
		$this->assertGreaterThan(0, $other->create($this->admin), implode(',', $other->errors));
		(new InboxAccess($this->db))->grant($this->admin, $other->id, $this->admin->id, 0, 'manage');
		InboxAccess::clearCache();
		$open = $c->open($this->admin, 'new', 0, (int) $other->id);
		$dir = $c->held($this->admin, $open['token']);
		$this->assertFalse(InboxComposer::isSealed($dir));
		$c->prepare($this->admin, $dir, InboxComposer::readState($dir), array('identity' => (int) $i->id, 'to' => array('dest@inbox-test.invalid'), 'subject' => 'x', 'html' => '<p>x</p>'), true);
		$this->assertTrue(InboxComposer::isSealed($dir));
		$this->assertStringNotContainsString('"user"', (string) file_get_contents($dir.'/state.json'));
		$this->assertSame((int) $this->admin->id, (int) InboxComposer::readState($dir)['user']);
		$c->close($this->admin, $open['token'], false);
		$this->db->query("DELETE FROM ".$p."inbox_account_access WHERE fk_account = ".((int) $other->id));
		$this->db->query("DELETE FROM ".$p."inbox_account WHERE rowid = ".((int) $other->id));

		// Locked (no key in the session): never opened in clear
		InboxProtect::lockAll();
		unset($_COOKIE[InboxProtect::cookieName()]);
		$this->assertNull($c->open($this->admin, 'new', 0, (int) $box->id));
	}

	/**
	 * @return void
	 */
	public function testSessionCopy()
	{
		$this->assertNull(InboxProtect::secrets(999001));
		$this->assertTrue(InboxProtect::unlock(999001, array('imap' => 'pass-1', 'smtp' => 'smtp-1'), 7));
		InboxProtect::forgetMemory();
		$this->assertSame(array('imap' => 'pass-1', 'smtp' => 'smtp-1'), InboxProtect::secrets(999001), 'rebuilt from the session and the cookie');
		$this->assertSame(7, InboxProtect::unlockedBy(999001));
		$raw = json_encode($_SESSION);
		$this->assertStringNotContainsString('pass-1', (string) $raw, 'never in clear in the session');
		// The session alone (another browser, a copy of the session files) opens nothing
		$cookie = $_COOKIE[InboxProtect::cookieName()];
		$_COOKIE[InboxProtect::cookieName()] = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
		InboxProtect::forgetMemory();
		$this->assertNull(InboxProtect::secrets(999001), 'other key: nothing');
		$_COOKIE[InboxProtect::cookieName()] = $cookie;
		InboxProtect::forgetMemory();
		$this->assertNotNull(InboxProtect::secrets(999001));
		// Copy of a mailbox put on another one: refused (associated data)
		$_SESSION[InboxProtect::SESSION_KEY][1][999002] = $_SESSION[InboxProtect::SESSION_KEY][1][999001];
		$this->assertNull(InboxProtect::secrets(999002));
		InboxProtect::lock(999001);
		$this->assertNull(InboxProtect::secrets(999001));
		InboxProtect::unlock(999001, array('imap' => 'x'));
		InboxProtect::lockAll();
		$this->assertNull(InboxProtect::secrets(999001), 'logout');
	}

	/**
	 * @return void
	 */
	public function testRightsAndCopies()
	{
		$alice = $this->user('alice');
		$bob = $this->user('bob');
		$carol = $this->user('carol');
		$protect = new InboxProtect($this->db);
		$access = new InboxAccess($this->db);

		$shared = $this->box(true, array('status' => InboxAccount::STATUS_ACTIVE));
		$perso = $this->box(true, array('email' => 'alice@inbox-test.invalid', 'type' => 'personal', 'fk_user_owner' => $alice->id, 'status' => InboxAccount::STATUS_ACTIVE));
		$access->grant($this->admin, $shared->id, $bob->id, 0, 'manage');
		$access->grant($this->admin, $shared->id, $carol->id, 0, 'read');
		$this->assertTrue(InboxProtect::canDecide($alice, $perso), 'owner of a personal mailbox');
		$this->assertFalse(InboxProtect::canDecide($bob, $shared), 'a manager only: never (D4)');
		$master = $this->box(false, array('imap_auth_mode' => InboxAccount::AUTH_MASTER));
		$this->assertSame('InboxProtectErrorAuthMode', InboxProtect::refusal($master), 'D5');

		$dAlice = $protect->registerDevice($alice, self::jwk(), 'ZzDevice alice');
		$dBob = $protect->registerDevice($bob, self::jwk(), 'ZzDevice bob');
		$dCarol = $protect->registerDevice($carol, $jwkCarol = self::jwk(), 'ZzDevice carol');
		$this->assertNotNull($dAlice);
		$this->assertSame($dCarol['id'], $protect->registerDevice($carol, $jwkCarol, 'other')['id'], 'same key, same device');

		// Protected by hand (the flow with the mail server is in testProtectWithServer)
		$this->db->query("UPDATE ".$this->db->prefix()."inbox_account SET protected = 1 WHERE rowid = ".((int) $shared->id));
		$shared->fetch($shared->id);
		$this->assertTrue($shared->isProtected());
		$this->assertSame('', $shared->getImapPassword(), 'locked: no password, even if a dolEncrypt one is left');

		$this->assertTrue($protect->storeBlob($bob, $shared, $bob->id, $dBob['id'], self::blob(), InboxProtect::ORIGIN_PASSWORD), $protect->error);
		$this->assertFalse($protect->storeBlob($bob, $shared, $alice->id, $dAlice['id'], self::blob(), InboxProtect::ORIGIN_GRANT), 'alice has no access');
		$this->assertSame('InboxProtectErrorNoAccess', $protect->error);
		$this->assertFalse($protect->storeBlob($bob, $shared, $carol->id, $dCarol['id'], self::blob(), InboxProtect::ORIGIN_GRANT), 'grant without the mailbox unlocked');
		$this->assertFalse($protect->storeBlob($bob, $shared, $carol->id, $dBob['id'], self::blob(), InboxProtect::ORIGIN_PASSWORD), 'device of another user');
		InboxProtect::unlock($shared->id, array('imap' => 'pw'));
		$this->assertFalse($protect->storeBlob($carol, $shared, $bob->id, $dBob['id'], self::blob(), InboxProtect::ORIGIN_GRANT), 'grant by a reader');
		$this->assertTrue($protect->storeBlob($bob, $shared, $carol->id, $dCarol['id'], self::blob(), InboxProtect::ORIGIN_GRANT), $protect->error);
		$this->assertFalse($protect->storeBlob($bob, $shared, $carol->id, $dCarol['id'], self::blob(), InboxProtect::ORIGIN_APPROVE), 'approve: own devices only');
		$this->assertNotNull($protect->blobFor($carol, $shared, $dCarol['id']));
		$this->assertNull($protect->blobFor($bob, $shared, $dCarol['id']), 'the copy of another user');

		// Another generation of the password: older copies obsolete
		$protect->newGeneration($shared);
		$this->assertNull($protect->blobFor($carol, $shared, $dCarol['id']));
		$states = array();
		foreach ($protect->accessesOf($shared) as $u) {
			foreach ($u['devices'] as $d) {
				$states[$u['id']] = $d['state'];
			}
		}
		$this->assertSame('obsolete', $states[$carol->id]);
		$this->assertTrue($protect->storeBlob($bob, $shared, $carol->id, $dCarol['id'], self::blob(), InboxProtect::ORIGIN_GRANT));

		// Access removed: its copies go
		$access->revoke($this->admin, $shared->id, $carol->id, 0);
		$this->assertSame(0, (int) $this->db->getRow("SELECT COUNT(*) AS n FROM ".$this->db->prefix()."inbox_account_key WHERE fk_user = ".((int) $carol->id))->n);
		// Device revoked: its copies go, the key is never taken again
		$this->assertFalse($protect->revokeDevice($carol, $dBob['id']), 'the device of another user');
		$this->assertTrue($protect->revokeDevice($bob, $dBob['id']));
		$this->assertNull($protect->blobFor($bob, $shared, $dBob['id']));
		$this->assertNull($protect->registerDevice($bob, $protect->device($dBob['id'])['public_key'], 'again'));
		$this->assertSame('InboxProtectErrorRevoked', $protect->error);
		$actions = array_column((new InboxAccountLog($this->db))->listForAccount($shared->id, 100), 'action');
		foreach (array('key_enrol', 'key_grant', 'key_revoke') as $a) {
			$this->assertContains($a, $actions, $a);
		}
		// Reset: every copy gone, still protected
		$this->assertFalse($protect->reset($bob, $shared), 'manager: no');
		$this->assertTrue($protect->reset($this->admin, $shared));
		$shared->fetch($shared->id);
		$this->assertTrue($shared->isProtected());
		$this->assertFalse(InboxProtect::isUnlocked($shared->id));
	}

	/**
	 * Every scheduled or background work leaves a protected mailbox aside (§5.8)
	 *
	 * @return void
	 */
	public function testNoWorkOutOfTheSession()
	{
		$box = $this->box(true, array('status' => InboxAccount::STATUS_ACTIVE));
		$this->db->query("UPDATE ".$this->db->prefix()."inbox_account SET protected = 1 WHERE rowid = ".((int) $box->id));
		$box->fetch($box->id);
		$sync = new InboxSync($this->db);
		$this->assertSame(0, $sync->run($box, microtime(true) + 10, array('force' => true)), 'never synchronised');
		$inbox = new Inbox($this->db);
		$this->assertNull($inbox->resolveAccount(self::BOX), 'not given to the other modules');
		$this->assertNull($inbox->fetchRaw(self::BOX, 'seed-1@inbox-test.invalid', $this->admin, 'phpunit', true));
		foreach (array('class/inboxsync.class.php', 'class/inboxretention.class.php', 'class/inboxfulltext.class.php', 'class/inboxblockedsender.class.php') as $file) {
			$this->assertMatchesRegularExpression('/protected = 0/', (string) file_get_contents(dirname(__DIR__, 2).'/'.$file), $file);
		}
		// The form of the server: no password typed, no other server
		$old = clone $box;
		$box->imap_host = 'other.invalid';
		$this->assertSame('InboxProtectErrorServerLocked', $box->guardSecrets($old, false, false, true));
		$box->imap_host = $old->imap_host;
		$this->assertSame('InboxProtectErrorServerLocked', $box->guardSecrets($old, true, false, true));
		$this->assertSame('', $box->guardSecrets($old, false, false, true));
	}

	/**
	 * Protect with the local test Dovecot: the password tried, the local data emptied, unlocked for the session,
	 * then back to the normal mode
	 *
	 * @return void
	 */
	public function testProtectWithServer()
	{
		$this->needDovecot();
		$p = $this->db->prefix();
		$protect = new InboxProtect($this->db);
		$box = $this->box(true, array('status' => InboxAccount::STATUS_ACTIVE));
		(new InboxAccess($this->db))->grant($this->admin, $box->id, $this->admin->id, 0, 'manage');
		$device = $protect->registerDevice($this->admin, self::jwk(), 'ZzDevice admin');
		$this->db->query("INSERT INTO ".$p."inbox_folder (fk_account, path, name) VALUES (".((int) $box->id).", 'INBOX', 'INBOX')");

		$this->assertFalse($protect->protect($this->admin, $box, array('imap' => 'wrong'), $device['id'], self::blob()), 'password refused by the server');
		$box->fetch($box->id);
		$this->assertFalse($box->isProtected());
		$this->assertTrue($protect->protect($this->admin, $box, array('imap' => self::BOX_PASSWORD), $device['id'], self::blob(), InboxAccount::PROTECT_CONTENT), $protect->error);
		$row = $this->db->getRow("SELECT protected, imap_password, smtp_password FROM ".$p."inbox_account WHERE rowid = ".((int) $box->id));
		$this->assertSame(1, (int) $row->protected);
		$this->assertNull($row->imap_password, 'no password in the database');
		$this->assertSame(0, (int) $this->db->getRow("SELECT COUNT(*) AS n FROM ".$p."inbox_folder WHERE fk_account = ".((int) $box->id))->n, 'local data emptied');
		$this->assertTrue(InboxProtect::isUnlocked($box->id));
		$fresh = new InboxAccount($this->db);
		$fresh->fetch($box->id);
		$this->assertSame(self::BOX_PASSWORD, $fresh->getImapPassword(), 'the copy of the session');
		$error = '';
		$connector = InboxConnectorFactory::forAccount($this->db, $fresh, $error);
		$this->assertTrue($connector->connect(), $connector->getError());
		$connector->disconnect();
		$this->assertSame(0, (new InboxSync($this->db))->run($fresh, microtime(true) + 10, array('force' => true)), 'unlocked or not: no synchronisation');

		InboxProtect::lock($box->id);
		$this->assertSame('', $fresh->getImapPassword());
		$this->assertFalse($protect->unprotect($this->admin, $fresh), 'locked: cannot go back to normal');
		InboxProtect::unlock($box->id, array('imap' => self::BOX_PASSWORD));
		$this->assertTrue($protect->unprotect($this->admin, $fresh), $protect->error);
		$fresh->fetch($box->id);
		$this->assertFalse($fresh->isProtected());
		$this->assertSame(self::BOX_PASSWORD, $fresh->getImapPassword(), 'back in the database');
		$this->assertSame(0, (int) $this->db->getRow("SELECT COUNT(*) AS n FROM ".$p."inbox_account_key WHERE fk_account = ".((int) $box->id))->n);
	}

	/**
	 * Numbers of the live reading (lot S2): negative, the mailbox found again, kept encrypted in the session
	 *
	 * @return void
	 */
	public function testLiveNumbers()
	{
		InboxProtect::unlock(424242, array('imap' => 'x'));
		$f = InboxLive::folderId(8635, 'INBOX');
		$this->assertLessThan(0, $f);
		$this->assertSame($f, InboxLive::folderId(8635, 'INBOX'), 'same folder, same number');
		$this->assertFalse(InboxLive::isMail($f));
		$this->assertSame(8635, InboxLive::accountOf($f));
		$this->assertSame('INBOX', InboxLive::folderPath($f));
		$ids = InboxLive::mailIds(8635, 'INBOX', array('17:3', '17:4'));
		$this->assertTrue(InboxLive::isMail($ids['17:3']));
		$this->assertSame(8635, InboxLive::accountOf($ids['17:4']));
		$this->assertSame(array('account' => 8635, 'path' => 'INBOX', 'remote_id' => '17:4'), InboxLive::mailOf($ids['17:4']));
		$this->assertSame($ids, InboxLive::mailIds(8635, 'INBOX', array('17:3', '17:4')));
		$this->assertLessThan(PHP_INT_MAX, -$ids['17:4']);
		$this->assertLessThan(9007199254740991, -$ids['17:4'], 'safe as a number of JavaScript');
		$this->assertStringNotContainsString('INBOX', (string) json_encode($_SESSION), 'the paths are not in clear in the session');
		// Criteria: words as literals, nothing the server cannot search
		$c = InboxLive::criteria(array('words' => array('a"b'), 'unread' => true, 'from' => array(), 'to' => array(), 'subject' => array(), 'tag' => array()));
		$this->assertSame(array('CHARSET', 'UTF-8', 'TEXT', array('{3}', 'a"b'), 'UNSEEN'), $c);
		$empty = false;
		InboxLive::criteria(array('soc' => 12), $empty);
		$this->assertTrue($empty, 'a thirdparty: nothing here (no link)');
		$this->assertSame(array('ALL'), InboxLive::criteria(array()));
	}

	/**
	 * Live reading of a protected mailbox on the local test Dovecot: tree, page, mail, actions, nothing written
	 *
	 * @return void
	 */
	public function testLiveReading()
	{
		$this->needDovecot();
		$p = $this->db->prefix();
		$box = $this->box(true, array('status' => InboxAccount::STATUS_ACTIVE));
		(new InboxAccess($this->db))->grant($this->admin, $box->id, $this->admin->id, 0, 'manage');
		$this->db->query("UPDATE ".$p."inbox_account SET protected = 1, protect_level = 'content', imap_password = NULL WHERE rowid = ".((int) $box->id));
		$box->fetch($box->id);
		$live = new InboxLive($this->db);
		$this->assertNull($live->tree($box), 'locked: nothing');
		InboxProtect::unlock($box->id, array('imap' => self::BOX_PASSWORD));
		$tree = $live->tree($box);
		$this->assertNotEmpty($tree, $live->error);
		$inbox = null;
		foreach ($tree as $f) {
			if ($f['role'] === 'inbox') {
				$inbox = $f;
			}
		}
		$this->assertNotNull($inbox);
		$this->assertLessThan(0, $inbox['id']);
		$page = $live->page($box, $inbox['id'], array('dir' => 'desc'), '', 2);
		$this->assertNotNull($page, $live->error);
		$this->assertGreaterThan(0, $page['total']);
		$this->assertLessThanOrEqual(2, count($page['rows']));
		$row = $page['rows'][0];
		$this->assertTrue(InboxLive::isMail($row['id']));
		$this->assertSame(1, $row['live']);
		if ($page['total'] > 2) {
			$this->assertSame('2', $page['next']);
		}
		$reader = new InboxReader($this->db);
		$this->assertTrue($reader->load($this->admin, $row['id']), $reader->error);
		$this->assertTrue($reader->isLive());
		$raw = $reader->raw($this->admin);
		$this->assertNotEmpty($raw, $reader->error);
		$out = $reader->describe($this->admin, $raw);
		$this->assertSame($row['id'], $out['id']);
		// Flags and a move inside the mailbox, then back; journal of the actions: nothing (it names the mails)
		$actions = new InboxActions($this->db);
		$res = $actions->run($this->admin, 'flag', array($row['id']), array('on' => true));
		$this->assertTrue($res['ok'], $res['error']);
		$res = $actions->run($this->admin, 'flag', array($row['id']), array('on' => false));
		$this->assertTrue($res['ok'], $res['error']);
		$this->assertSame(0, (int) $this->db->getRow("SELECT COUNT(*) AS n FROM ".$p."inbox_action_log WHERE fk_account = ".((int) $box->id))->n);
		$this->assertFalse((new InboxActions($this->db))->run($this->admin, 'move', array($row['id']), array('folder' => 999999999))['ok'], 'never out of the mailbox');
		// Nothing of the mailbox in the index, the cache, the readings
		foreach (array('inbox_mail' => 'fk_account', 'inbox_folder' => 'fk_account', 'inbox_action_log' => 'fk_account') as $t => $col) {
			$this->assertSame(0, (int) $this->db->getRow("SELECT COUNT(*) AS n FROM ".$p.$t." WHERE ".$col." = ".((int) $box->id))->n, $t);
		}
		foreach (array('inbox_message', 'inbox_readlog') as $t) {
			$this->assertSame(0, (int) $this->db->getRow("SELECT COUNT(*) AS n FROM ".$p.$t." WHERE mailbox = '".$this->db->escape(self::BOX)."'")->n, $t);
		}
		$live->close();
	}

	/**
	 * Sending of a protected mailbox (lot S3) on the local Dovecot: the delay kept in the session (never in the queue),
	 * the copy marked in Drafts, sent once; scheduled: waits in Drafts, sent with its marks removed
	 *
	 * @return void
	 */
	public function testLiveSending()
	{
		$this->needDovecot();
		$p = $this->db->prefix();
		$box = $this->box(true, array('status' => InboxAccount::STATUS_ACTIVE, 'smtp_host' => self::IMAP_HOST));
		(new InboxAccess($this->db))->grant($this->admin, $box->id, $this->admin->id, 0, 'manage');
		$this->db->query("UPDATE ".$p."inbox_account SET protected = 1, protect_level = 'content', imap_password = NULL WHERE rowid = ".((int) $box->id));
		$box->fetch($box->id);
		InboxProtect::unlock($box->id, array('imap' => self::BOX_PASSWORD));
		$b = new InboxMailBuilder();
		$b->from = array('email' => self::BOX, 'name' => '');
		$b->to = array(array('email' => 'dest@inbox-test.invalid', 'name' => ''));
		$b->bcc = array(array('email' => 'hidden@inbox-test.invalid', 'name' => ''));
		$b->subject = 'Protected sending '.uniqid();
		$b->html = '<p>Hello</p>';
		$built = $b->build();
		$this->assertNotEmpty($built, $b->error);
		$send = new InboxLiveSend($this->db);

		// Delay of the undo: in the session, a copy marked in Drafts, nothing in the queue of the server
		$id = $send->queue($this->admin, $box, array('id' => -$box->id), $b, $built, array('token' => '', 'typed' => array(), 'at' => dol_now() + 10));
		$this->assertLessThan(0, $id, $send->error);
		$this->assertLessThan(9007199254740991, -$id);
		$this->assertSame(0, (int) $this->db->getRow("SELECT COUNT(*) AS n FROM ".$p."inbox_outbox WHERE fk_account = ".((int) $box->id))->n);
		$this->assertCount(1, $send->listMine($this->admin));
		$this->assertStringNotContainsString($b->subject, (string) json_encode($_SESSION), 'nothing in clear in the session');
		$res = $send->deliver($this->admin, $id, true);
		$this->assertNotNull($res, $send->errorCode.' '.$send->error);
		$this->assertNull($send->deliver($this->admin, $id), 'once');
		$this->assertSame('already_sent', $send->errorCode);
		$this->assertCount(0, $send->listMine($this->admin));
		$this->assertCount(0, array_filter($send->waitingDrafts($this->admin), function ($d) use ($b) {
			return $d['subject'] === $b->subject;
		}), 'the copy marked left Drafts');

		// Scheduled: in Drafts with its date, found again, sent with its marks removed
		$b->subject = 'Protected scheduled '.uniqid();
		$b->messageId = '';
		$built = $b->build();
		$this->assertNotSame($res['message_id'], $built['message_id'], 'another mail');
		$rid = $send->schedule($this->admin, $box, $built, dol_now() - 3600, 'send');
		$this->assertNotNull($rid, $send->error);
		$found = array_values(array_filter($send->waitingDrafts($this->admin), function ($d) use ($b) {
			return $d['subject'] === $b->subject;
		}));
		$this->assertCount(1, $found);
		$this->assertSame('scheduled', $found[0]['kind']);
		$this->assertSame('send', $found[0]['catchup']);
		$this->assertTrue($found[0]['mine']);
		$this->assertEqualsWithDelta(dol_now() - 3600, $found[0]['at'], 2);
		$res = $send->sendDraft($this->admin, $found[0]['id']);
		$this->assertNotNull($res, $send->errorCode.' '.$send->error);
		$this->assertSame(1, $res['sent']);
		$this->assertCount(0, array_filter($send->waitingDrafts($this->admin), function ($d) use ($b) {
			return $d['subject'] === $b->subject;
		}), 'the draft left');

		// A failure while no page was open: a notice without content, told once
		$send->notice($this->admin, $box->id, array('folder' => 'Drafts', 'remote_id' => '1:5'), 'smtp_refused');
		$n = $send->notices($this->admin, true);
		$this->assertCount(1, $n);
		$this->assertSame('smtp_refused', $n[0]['code']);
		$this->assertCount(0, $send->notices($this->admin));
		$this->assertSame(0, (int) $this->db->getRow("SELECT COUNT(*) AS n FROM ".$p."inbox_action_log WHERE fk_account = ".((int) $box->id))->n);
	}

	/**
	 * Level password: only the password leaves the server, the index stays and is synchronised by the session that
	 * unlocked the mailbox, never without it (a scheduled job); the level can become content (emptied) and back
	 *
	 * @return void
	 */
	public function testPasswordLevel()
	{
		$this->needDovecot();
		$p = $this->db->prefix();
		$protect = new InboxProtect($this->db);
		$box = $this->box(true, array('status' => InboxAccount::STATUS_ACTIVE));
		(new InboxAccess($this->db))->grant($this->admin, $box->id, $this->admin->id, 0, 'manage');
		$device = $protect->registerDevice($this->admin, self::jwk(), 'ZzDevice admin');
		$this->db->query("INSERT INTO ".$p."inbox_folder (fk_account, path, name) VALUES (".((int) $box->id).", 'Kept', 'Kept')");
		$this->assertTrue($protect->protect($this->admin, $box, array('imap' => self::BOX_PASSWORD), $device['id'], self::blob()), $protect->error);
		$fresh = new InboxAccount($this->db);
		$fresh->fetch($box->id);
		$this->assertTrue($fresh->isProtected());
		$this->assertFalse($fresh->isLive());
		$this->assertSame('password', $fresh->protect_level);
		$this->assertNull($this->db->getRow("SELECT imap_password FROM ".$p."inbox_account WHERE rowid = ".((int) $box->id))->imap_password, 'no password in the database');
		$this->assertSame(1, (int) $this->db->getRow("SELECT COUNT(*) AS n FROM ".$p."inbox_folder WHERE fk_account = ".((int) $box->id)." AND path = 'Kept'")->n, 'the index stays');
		// Unlocked in this request: synchronised as the others; locked (a scheduled job): never
		$this->assertTrue($fresh->canWorkNow());
		$sync = new InboxSync($this->db);
		$this->assertSame(1, $sync->run($fresh, microtime(true) + 20, array('force' => true)), $sync->error);
		$this->assertGreaterThan(0, (int) $this->db->getRow("SELECT COUNT(*) AS n FROM ".$p."inbox_mail WHERE fk_account = ".((int) $box->id))->n, 'mails indexed in the session');
		InboxProtect::lock($box->id);
		$this->assertFalse($fresh->canWorkNow());
		$this->assertSame(0, $sync->run($fresh, microtime(true) + 10, array('force' => true)), 'no work without the session');
		$this->assertNull((new Inbox($this->db))->resolveAccount(self::BOX), 'not given to the other modules out of the session');
		// Locked: what the server keeps of it is never read, even with an access (audit INBX-055)
		$this->assertContains((int) $box->id, $protect->lockedIds());
		$mail = (int) $this->db->getRow("SELECT rowid FROM ".$p."inbox_mail WHERE fk_account = ".((int) $box->id)." ORDER BY rowid")->rowid;
		$reader = new InboxReader($this->db);
		$this->assertFalse($reader->load($this->admin, $mail), 'index and cache closed while locked');
		$this->assertSame('locked', $reader->errorCode);
		InboxProtect::unlock($box->id, array('imap' => self::BOX_PASSWORD));
		$this->assertNotContains((int) $box->id, $protect->lockedIds());
		$this->assertTrue($reader->load($this->admin, $mail), 'open again in the session that unlocked it');
		InboxProtect::lock($box->id);
		// To the level content: emptied; back to password: from a session that has the password only (INBX-055)
		$this->assertTrue($protect->setLevel($this->admin, $fresh, InboxAccount::PROTECT_CONTENT), $protect->error);
		$this->assertSame(0, (int) $this->db->getRow("SELECT COUNT(*) AS n FROM ".$p."inbox_mail WHERE fk_account = ".((int) $box->id))->n);
		$fresh->fetch($box->id);
		$this->assertTrue($fresh->isLive());
		$this->assertFalse($protect->setLevel($this->admin, $fresh, InboxAccount::PROTECT_PASSWORD), 'locked: never back to password');
		$this->assertSame('InboxProtectErrorLocked', $protect->error);
		InboxProtect::unlock($box->id, array('imap' => self::BOX_PASSWORD));
		$this->assertTrue($protect->setLevel($this->admin, $fresh, InboxAccount::PROTECT_PASSWORD), $protect->error);
		$fresh->fetch($box->id);
		$this->assertFalse($fresh->isLive());
		$actions = array_column((new InboxAccountLog($this->db))->listForAccount($box->id, 100), 'action');
		$this->assertContains('protect_level', $actions);
	}
}
