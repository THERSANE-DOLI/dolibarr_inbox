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
 * \file    inbox/test/phpunit/InboxDataSourceTest.php
 * \ingroup inbox
 * \brief   1.72.0 (lot A2 level 1): data sources, requester of a mail sent by a site (trust, authentication, data,
 *          thirdparty, creation, answer, list)
 */

// From the command line only, never from the web: test/ stays in the Git branches
if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

require_once __DIR__.'/InboxTestCase.php';
dol_include_once('/inbox/class/inboxdatasource.class.php');
dol_include_once('/inbox/class/inboxcreate.class.php');
dol_include_once('/inbox/class/inboxcomposer.class.php');
dol_include_once('/inbox/class/inboxsearchsql.class.php');
dol_include_once('/inbox/class/inboxsituation.class.php');

/**
 * The sources of the tests are labelled « ZzSource… » (removed by InboxTestCase::cleanup())
 */
class InboxDataSourceTest extends InboxTestCase
{
	const SITE = 'noreply@forms.inbox-test.invalid';
	const MX = 'mx.inbox-test.invalid';

	/** @var string Setting before the test */
	protected $servers = '';

	/**
	 * @return void
	 */
	protected function setUp(): void
	{
		global $conf;

		parent::setUp();
		$this->servers = getDolGlobalString('INBOX_AUTH_SERVERS');
		$conf->global->INBOX_AUTH_SERVERS = self::MX;
	}

	/**
	 * @return void
	 */
	protected function tearDown(): void
	{
		global $conf;

		$conf->global->INBOX_AUTH_SERVERS = $this->servers;
		parent::tearDown();
	}

	/**
	 * @param array<string,mixed> $set Values
	 * @return int Source
	 */
	protected function source(array $set = array())
	{
		$id = (new InboxDataSource($this->db))->save($this->admin, 0, $set + array('label' => 'ZzSource form', 'status' => 1, 'senders' => 'forms.inbox-test.invalid',
			'header_email' => 'X-Data-Form-Email', 'header_data' => 'X-Data-Form', 'header_fields' => 'X-Form-Town = town', 'mapping' => 'raison_sociale = company', 'type' => 'propal'));
		$this->assertGreaterThan(0, $id);

		return $id;
	}

	/**
	 * @param string $extra Headers of the source
	 * @param string $auth  Authentication-Results line, '' for none
	 * @param string $from  Sender
	 * @return string        Raw header
	 */
	protected static function head($extra, $auth = 'default', $from = self::SITE)
	{
		if ($auth === 'default') {
			$auth = 'Authentication-Results: '.self::MX.'; dkim=pass header.d=forms.inbox-test.invalid; dmarc=pass header.from=forms.inbox-test.invalid';
		}

		return ($auth !== '' ? $auth."\r\n" : '')."From: Site <".$from.">\r\nTo: devis@inbox-test.invalid\r\nSubject: Nouvelle demande\r\n"
			."Message-ID: <src-".bin2hex(random_bytes(4))."@inbox-test.invalid>\r\n".$extra;
	}

	/**
	 * @return void
	 */
	public function testParsing()
	{
		$this->assertSame(array('forms.example.com', 'shop@example.org'), InboxDataSource::parseSenders("@forms.example.com\nShop@Example.org, forms.example.com", $bad));
		$this->assertSame('', $bad);
		InboxDataSource::parseSenders('not a domain', $bad);
		$this->assertSame('not', $bad);
		$this->assertTrue(InboxDataSource::senderIn('noreply@mail.forms.example.com', array('forms.example.com')), 'subdomain');
		$this->assertFalse(InboxDataSource::senderIn('noreply@evilforms.example.com', array('forms.example.com')), 'never a suffix of another name');
		$this->assertFalse(InboxDataSource::senderIn('other@example.org', array('shop@example.org')));
		$this->assertSame(array('X-Form-Phone' => 'phone'), InboxDataSource::parsePairs("X-Form-Phone = phone\n\n", true, $bad));
		$this->assertSame(array('raisonsociale' => 'company'), InboxDataSource::parsePairs('Raison_Sociale: company', false, $bad));
		InboxDataSource::parsePairs('X Form = phone', true, $bad);
		$this->assertSame('X Form = phone', $bad, 'a space in a header name');
		$this->assertSame('vat', InboxDataSource::fieldOf('vat_number'));
		$this->assertSame('firstname', InboxDataSource::fieldOf('Prénom'));
		$this->assertSame('company', InboxDataSource::fieldOf('raison_sociale', array('raisonsociale' => 'company')));
		$this->assertSame('', InboxDataSource::fieldOf('message'));
	}

	/**
	 * @return void
	 */
	public function testSaveRefusals()
	{
		$sources = new InboxDataSource($this->db);
		$this->assertLessThan(0, $sources->save($this->admin, 0, array('label' => '', 'header_data' => 'X-A')));
		$this->assertLessThan(0, $sources->save($this->admin, 0, array('label' => 'ZzSource a', 'senders' => 'x y', 'header_data' => 'X-A')));
		$this->assertLessThan(0, $sources->save($this->admin, 0, array('label' => 'ZzSource a', 'header_data' => 'X A')));
		$this->assertLessThan(0, $sources->save($this->admin, 0, array('label' => 'ZzSource a', 'header_fields' => 'X-A = password')), 'not a field');
		$this->assertLessThan(0, $sources->save($this->admin, 0, array('label' => 'ZzSource a')), 'no header');
		$id = $sources->save($this->admin, 0, array('label' => 'ZzSource a', 'header_data' => 'X-A', 'type' => 'nope'));
		$all = array_column($sources->fetchAll(), null, 'id');
		$this->assertSame('', $all[$id]['type'], 'unknown kind: none');
		$this->assertSame(0, $all[$id]['status']);
		$this->assertSame(array(), $sources->syncHeaders(), 'inactive: nothing more asked to the server');
		$this->source();
		$this->assertEqualsCanonicalizing(array('X-DATA-FORM-EMAIL', 'X-DATA-FORM', 'X-FORM-TOWN', 'AUTHENTICATION-RESULTS', 'RECEIVED-SPF'), $sources->syncHeaders());
	}

	/**
	 * @return void
	 */
	public function testDetect()
	{
		$this->source();
		$sources = new InboxDataSource($this->db);
		$json = '{"firstname":"Jean","lastname":"Dupont","raison_sociale":"Dupont <b>SARL</b>","vat_number":"FR12123456789","customer":{"phone":"+33 1 23 45 67 89"},"id":"D-1042","password":"x"}';
		$found = $sources->detectRaw(self::head("X-Data-Form-Email: Jean Dupont <Jean.Dupont@client.inbox-test.invalid>\r\nX-Data-Form: ".$json."\r\nX-Form-Town: Lyon\r\n"), self::SITE);
		$this->assertTrue($found['ok']);
		$this->assertSame('jean.dupont@client.inbox-test.invalid', $found['email']);
		$this->assertSame('Jean Dupont', $found['name']);
		$this->assertSame('Dupont SARL', $found['data']['company'], 'mapping of the source, markup removed');
		$this->assertSame('FR12123456789', $found['data']['vat']);
		$this->assertSame('+33 1 23 45 67 89', $found['data']['phone'], 'second level of the JSON');
		$this->assertSame('D-1042', $found['data']['ref']);
		$this->assertSame('Lyon', $found['data']['town'], 'one header per field');
		$this->assertArrayNotHasKey('password', $found['data'], 'unknown keys left out');

		// The address of the requester in the JSON only
		$found = $sources->detectRaw(self::head('X-Data-Form: {"email":"jane@client.inbox-test.invalid","company":"Acme"}'."\r\n"), self::SITE);
		$this->assertSame('jane@client.inbox-test.invalid', $found['email']);
		$this->assertSame('Acme', $found['name']);

		// Another sender: no source; the same headers never believed
		$this->assertNull($sources->detectRaw(self::head("X-Data-Form-Email: x@client.inbox-test.invalid\r\n", 'default', 'someone@other.inbox-test.invalid'), 'someone@other.inbox-test.invalid'));
		// Not authenticated, or authenticated by another server than ours
		$this->assertSame('auth', $sources->detectRaw(self::head("X-Data-Form-Email: x@client.inbox-test.invalid\r\n", ''), self::SITE)['why']);
		$this->assertSame('auth', $sources->detectRaw(self::head("X-Data-Form-Email: x@client.inbox-test.invalid\r\n", 'Authentication-Results: other.example; dmarc=pass header.from=forms.inbox-test.invalid'), self::SITE)['why']);
		$this->assertSame('auth', $sources->detectRaw(self::head("X-Data-Form-Email: x@client.inbox-test.invalid\r\n", 'Authentication-Results: '.self::MX.'; dkim=fail; spf=fail; dmarc=fail'), self::SITE)['why']);
		// A header twice, an unreadable JSON, no address
		$this->assertSame('twice', $sources->detectRaw(self::head("X-Data-Form-Email: a@client.inbox-test.invalid\r\nX-Data-Form-Email: b@client.inbox-test.invalid\r\n"), self::SITE)['why']);
		$this->assertSame('json', $sources->detectRaw(self::head("X-Data-Form: {nope\r\n"), self::SITE)['why']);
		$this->assertSame('noemail', $sources->detectRaw(self::head("X-Data-Form-Email: not-an-address\r\n"), self::SITE)['why']);
		// Disabled: nothing
		$this->db->query("UPDATE ".$this->db->prefix()."inbox_source SET status = 0 WHERE label LIKE 'ZzSource%'");
		InboxDataSource::reset();
		$this->assertNull($sources->detectRaw(self::head("X-Data-Form-Email: x@client.inbox-test.invalid\r\n"), self::SITE));
	}

	/**
	 * 1.143.0: a signed source believes a right signature without the authentication of the sender
	 *
	 * @return void
	 */
	public function testSignedSource()
	{
		$key = str_repeat('ab12', 16);
		$sources = new InboxDataSource($this->db);
		$this->assertLessThan(0, $sources->save($this->admin, 0, array('label' => 'ZzSource short', 'header_data' => 'X-A', 'signature_key' => 'tooshort')), 'a key too short');
		$this->assertLessThan(0, $sources->save($this->admin, 0, array('label' => 'ZzSource name', 'header_data' => 'X-A', 'header_signature' => 'X Sig')), 'not a header name');
		$id = $this->source(array('signature_key' => $key));

		// Stored encrypted, never in the list of the settings
		$stored = $this->db->getRow("SELECT signature_key FROM ".$this->db->prefix()."inbox_source WHERE rowid = ".$id)->signature_key;
		$this->assertNotSame($key, $stored);
		$this->assertSame($key, $sources->signatureKeyOf($id));
		$listed = array_column($sources->fetchAll(), null, 'id');
		$this->assertTrue($listed[$id]['signed']);
		$this->assertArrayNotHasKey('signature_key', $listed[$id]);
		$this->assertStringNotContainsString($key, json_encode($sources->fetchAll()));
		$this->assertEqualsCanonicalizing(array('X-DATA-FORM-EMAIL', 'X-DATA-FORM', 'X-FORM-TOWN', 'X-DATA-FORM-SIGNATURE', 'AUTHENTICATION-RESULTS', 'RECEIVED-SPF'), $sources->syncHeaders(), 'no Date: the reception is our time');

		$email = 'jean.dupont@client.inbox-test.invalid';
		$data = '{"firstname":"Jean","lastname":"Dupont","company":"Dupont SARL"}';
		$now = (int) dol_now();
		$date = 'Date: '.date('r', $now)."\r\n";
		$signed = function ($sig, $auth = '') use ($email, $data, $date) {
			return self::head($date.'X-Data-Form-Email: '.$email."\r\nX-Data-Form: ".$data."\r\nX-Form-Town: Lyon\r\n".($sig !== '' ? 'X-Data-Form-Signature: '.$sig."\r\n" : ''), $auth);
		};

		// A right signature, without any authentication: believed; the header « one per field » is not signed, not read
		$found = $sources->detectRaw($signed(InboxDataSource::sign($key, $email, $data, $now)), self::SITE);
		$this->assertTrue($found['ok']);
		$this->assertSame($email, $found['email']);
		$this->assertSame('Dupont SARL', $found['data']['company']);
		$this->assertArrayNotHasKey('town', $found['data']);
		// Another key, data changed after the signature, too old, unreadable: refused
		$this->assertSame('signature', $sources->detectRaw($signed(InboxDataSource::sign(str_repeat('cd34', 16), $email, $data, $now)), self::SITE)['why']);
		$this->assertSame('signature', $sources->detectRaw($signed(InboxDataSource::sign($key, $email, '{"company":"Other"}', $now)), self::SITE)['why']);
		$this->assertSame('signature', $sources->detectRaw($signed(InboxDataSource::sign($key, $email, $data, $now - 3 * 86400)), self::SITE)['why']);
		$this->assertSame('signature', $sources->detectRaw($signed('t='.$now.'; s=nope'), self::SITE)['why']);
		// The signature header twice (a right one added after a wrong one...): refused
		$right = InboxDataSource::sign($key, $email, $data, $now);
		$this->assertSame('twice', $sources->detectRaw($signed('t='.$now.'; s='.str_repeat('0', 64)."\r\nX-Data-Form-Signature: ".$right), self::SITE)['why']);
		// A wrong signature on an authenticated mail: still refused, the authentication is never tried instead
		$this->assertSame('signature', $sources->detectRaw($signed('t='.$now.'; s='.str_repeat('0', 64), 'default'), self::SITE)['why']);
		// Without a signature: the rule of the unsigned sources
		$this->assertSame('auth', $sources->detectRaw($signed(''), self::SITE)['why']);
		$this->assertTrue($sources->detectRaw($signed('', 'default'), self::SITE)['ok']);
		$this->assertFalse($sources->detectRaw($signed('', 'default'), self::SITE)['signed'], 'believed on its authentication, not signed');

		// Review of the PR: measured from the reception, never from the Date header the sender writes. An old signed pair
		// put on a new mail whose Date says the time of the signature: refused once received more than 2 days later
		$old = $now - 3 * 86400;
		$replayed = self::head('Date: '.date('r', $old)."\r\nX-Data-Form-Email: ".$email."\r\nX-Data-Form: ".$data."\r\nX-Data-Form-Signature: ".InboxDataSource::sign($key, $email, $data, $old)."\r\n", '');
		$this->assertSame('signature', $sources->detectRaw($replayed, self::SITE, array('received' => $now, 'message_id' => 'new@inbox-test.invalid'))['why'], 'Date = t, received 3 days later');
		$this->assertSame('signature', $sources->detectRaw($replayed, self::SITE)['why'], 'a test of the settings: measured from now');
		$genuine = $sources->detectRaw($replayed, self::SITE, array('received' => $old + 60, 'message_id' => 'first@inbox-test.invalid'));
		$this->assertTrue($genuine['ok'], 'the genuine mail, read again later: its own reception ('.$genuine['why'].')');
		// A signature serves one mail: its readings again and its copies (received within SIGNATURE_COPY_SECONDS) are
		// believed; later, it is a replay, whatever its Message-ID (the sender writes it, as the Date header)
		$late = InboxDataSource::SIGNATURE_COPY_SECONDS + 1;
		$mail = array('received' => $now, 'message_id' => '<request1@forms.inbox-test.invalid>');
		$found = $sources->detectRaw($signed($right), self::SITE, $mail);
		$this->assertTrue($found['ok']);
		$this->assertTrue($found['signed']);
		$this->assertTrue($sources->detectRaw($signed($right), self::SITE, $mail)['ok'], 'read again');
		$this->assertTrue($sources->detectRaw($signed($right), self::SITE, array('received' => $now + 30, 'message_id' => 'request1@forms.inbox-test.invalid'))['ok'], 'a copy in another mailbox');
		$this->assertTrue($sources->detectRaw($signed($right), self::SITE, array('received' => $now + 20, 'message_id' => 'request1-bis@forms.inbox-test.invalid'))['ok'], 'the form sent to a second mailbox in the same second');
		$this->assertSame('replay', $sources->detectRaw($signed($right), self::SITE, array('received' => $now + $late, 'message_id' => 'request1@forms.inbox-test.invalid'))['why'], 'review of #19: the mail copied whole, Message-ID included, received later');
		$this->assertSame('replay', $sources->detectRaw($signed($right), self::SITE, array('received' => $now + $late, 'message_id' => 'other@evil.inbox-test.invalid'))['why'], 'same signature, another mail');
		$this->assertSame('replay', $sources->detectRaw($signed($right), self::SITE, array('received' => $now + $late, 'message_id' => ''))['why'], 'same signature, a mail without Message-ID');
		$this->assertSame('replay', $sources->detectRaw($signed($right), self::SITE, array('received' => $now - $late, 'message_id' => 'request1@forms.inbox-test.invalid'))['why'], 'received before the first one seen');
		$this->assertTrue($sources->detectRaw($signed($right), self::SITE, $mail)['ok'], 'the genuine mail, read again after a replay');
		$this->assertTrue($sources->detectRaw($signed($right), self::SITE)['ok'], 'a test of the settings neither checks nor keeps it');
		$row = $this->db->getRow("SELECT mail_key, date_received FROM ".$this->db->prefix()."inbox_source_signature WHERE fk_source = ".$id." AND signature = '".hash('sha256', InboxDataSource::signatureParts($right)['s'])."'");
		$this->assertSame(array('request1@forms.inbox-test.invalid', $now), array((string) $row->mail_key, (int) $this->db->jdate($row->date_received)), 'the first reception kept');
		// Without Message-ID: the same rule
		$lone = InboxDataSource::sign($key, $email, $data, $now - 5);
		$this->assertTrue($sources->detectRaw($signed($lone), self::SITE, array('received' => $now, 'message_id' => ''))['ok']);
		$this->assertTrue($sources->detectRaw($signed($lone), self::SITE, array('received' => $now, 'message_id' => ''))['ok'], 'the same mail read again');
		$this->assertTrue($sources->detectRaw($signed($lone), self::SITE, array('received' => $now + 1, 'message_id' => ''))['ok'], 'a copy');
		$this->assertSame('replay', $sources->detectRaw($signed($lone), self::SITE, array('received' => $now + $late, 'message_id' => ''))['why']);
		$this->assertSame(3, (int) $this->db->getRow("SELECT COUNT(*) as n FROM ".$this->db->prefix()."inbox_source_signature WHERE fk_source = ".$id)->n, 'one row per signed request: the genuine old one, request1, the lone one');
		// A row kept by 1.143.0, without its reception: measured from the time of the signature
		$old143 = InboxDataSource::sign($key, $email, $data, $now - 10);
		$this->db->query("INSERT INTO ".$this->db->prefix()."inbox_source_signature (fk_source, signature, mail_key, date_signature, date_creation) VALUES (".$id.", '"
			.hash('sha256', InboxDataSource::signatureParts($old143)['s'])."', 'old@forms.inbox-test.invalid', '".$this->db->idate($now - 10)."', '".$this->db->idate($now)."')");
		$this->assertTrue($sources->detectRaw($signed($old143), self::SITE, array('received' => $now, 'message_id' => 'old@forms.inbox-test.invalid'))['ok']);
		$this->assertSame('replay', $sources->detectRaw($signed($old143), self::SITE, array('received' => $now + $late, 'message_id' => 'old@forms.inbox-test.invalid'))['why']);

		// Saved again without a key: the key stays; another header name; removed on demand
		$base = array('label' => 'ZzSource form', 'status' => 1, 'senders' => 'forms.inbox-test.invalid', 'header_email' => 'X-Data-Form-Email', 'header_data' => 'X-Data-Form', 'type' => 'propal');
		$this->assertSame($id, $sources->save($this->admin, $id, $base + array('header_signature' => 'X-Site-Sig')));
		$this->assertSame($key, $sources->signatureKeyOf($id));
		$this->assertContains('X-SITE-SIG', $sources->syncHeaders());
		$this->assertTrue($sources->detectRaw(self::head($date.'X-Data-Form-Email: '.$email."\r\nX-Data-Form: ".$data."\r\nX-Site-Sig: ".InboxDataSource::sign($key, $email, $data, $now)."\r\n", ''), self::SITE)['ok']);
		$this->assertSame($id, $sources->save($this->admin, $id, $base + array('signature_clear' => 1)));
		$this->assertSame('', $sources->signatureKeyOf($id));
		$this->assertFalse(array_column($sources->fetchAll(), null, 'id')[$id]['signed']);
		$this->assertSame('auth', $sources->detectRaw($signed(InboxDataSource::sign($key, $email, $data, $now)), self::SITE)['why'], 'no key: a signature proves nothing');
		// The signatures seen go with their source
		$this->assertSame(1, $sources->delete($id));
		$this->assertSame(0, (int) $this->db->getRow("SELECT COUNT(*) as n FROM ".$this->db->prefix()."inbox_source_signature WHERE fk_source = ".$id)->n);
	}

	/**
	 * 1.74.0: the analysis of a mail of a site, the glossary, a usual key ignored on purpose
	 *
	 * @return void
	 */
	public function testAnalyzeAndGlossary()
	{
		$head = self::head("X-Mailer: PHPMailer\r\nX-Spam-Score: 0\r\nX-Form-Email: jean@client.inbox-test.invalid\r\nX-Form-Data: {\"prenom\":\"Jean\",\"nom_boite\":\"Acme\",\"tel\":\"0102\"}\r\nX-Form-Town: Lyon\r\nX-Form-Origin: web\r\n");
		$an = InboxDataSource::analyze(InboxReader::headers($head."\r\n"));
		$this->assertSame(self::SITE, $an['from']);
		$this->assertSame('forms.inbox-test.invalid', $an['domain']);
		$this->assertTrue($an['authenticated']);
		$byName = array_column($an['headers'], null, 'name');
		$this->assertSame(array('X-Form-Email', 'X-Form-Data', 'X-Form-Town', 'X-Form-Origin'), array_keys($byName), 'the headers of mail servers and filters left out');
		$this->assertSame('email', $byName['X-Form-Email']['role']);
		$this->assertSame('data', $byName['X-Form-Data']['role']);
		$keys = array_column($byName['X-Form-Data']['keys'], null, 'key');
		$this->assertSame('firstname', $keys['prenom']['field']);
		$this->assertSame('', $keys['nom_boite']['field'], 'not a usual name: to choose');
		$this->assertFalse($keys['nom_boite']['usual']);
		$this->assertSame('phone', $keys['tel']['field']);
		$this->assertSame(array('field', 'town'), array($byName['X-Form-Town']['role'], $byName['X-Form-Town']['field']));
		$this->assertSame('ignore', $byName['X-Form-Origin']['role']);
		$this->assertFalse(InboxDataSource::analyze(InboxReader::headers(self::head('', '')."\r\n"))['authenticated']);

		$glossary = array_column(InboxDataSource::glossary(), 'names', 'code');
		$this->assertSame(InboxDataSource::FIELDS, array_keys($glossary));
		$this->assertContains('telephone', $glossary['phone']);

		// A usual key left out on purpose
		$this->source(array('mapping' => "tel = ignore\nnom_boite = company"));
		$found = (new InboxDataSource($this->db))->detectRaw(self::head("X-Data-Form: {\"email\":\"a@client.inbox-test.invalid\",\"tel\":\"0102\",\"nom_boite\":\"Acme\"}\r\n"), self::SITE);
		$this->assertArrayNotHasKey('phone', $found['data']);
		$this->assertSame('Acme', $found['data']['company']);
	}

	/**
	 * Index, thirdparty, creation page, answer, list
	 *
	 * @return void
	 */
	public function testRequesterEverywhere()
	{
		$p = $this->db->prefix();
		$sourceId = $this->source();
		$requester = 'jean.dupont@client.inbox-test.invalid';
		$this->db->query("INSERT INTO ".$p."societe (nom, entity, status, client, email, datec) VALUES ('inboxtest_dupont', 1, 1, 2, '".$requester."', '".$this->db->idate(dol_now())."')");
		$socId = (int) $this->db->last_insert_id($p.'societe');
		$this->db->query("INSERT INTO ".$p."societe (nom, entity, status, client, email, datec) VALUES ('inboxtest_site', 1, 1, 0, '".self::SITE."', '".$this->db->idate(dol_now())."')");
		$siteId = (int) $this->db->last_insert_id($p.'societe');

		// Synchronisation: the headers asked, the mail matched on the requester
		$devis = $this->box(true, array('status' => InboxAccount::STATUS_ACTIVE));
		$this->db->query("INSERT INTO ".$p."inbox_folder (fk_account, path, name, delimiter, role, status) VALUES (".((int) $devis->id).", 'INBOX', 'INBOX', '.', 'inbox', 1)");
		$folder = (int) $this->db->last_insert_id($p.'inbox_folder');
		$head = self::head("X-Data-Form-Email: ".$requester."\r\nX-Data-Form: {\"firstname\":\"Jean\",\"lastname\":\"Dupont\",\"phone\":\"0102030405\",\"siren\":\"123456789\",\"id\":\"D-7\"}\r\n");
		$mail = InboxHeaderParser::parse($head) + array('remote_id' => '1:1', 'sort_key' => 1, 'date_received' => dol_now(), 'size' => 10, 'snippet' => '',
			'seen' => 0, 'answered' => 0, 'flagged' => 0, 'draft' => 0, 'keywords' => array(), 'source_header' => $head);
		$this->assertSame(1, (new InboxMail($this->db))->addMany($devis->id, $folder, array($mail)));
		$row = $this->db->getRow("SELECT * FROM ".$p."inbox_mail WHERE fk_folder = ".$folder);
		$this->assertSame($requester, $row->requester_email);
		$this->assertSame('Jean Dupont', $row->requester_name);
		$this->assertSame($sourceId, (int) $row->fk_source);
		$this->assertSame($socId, (int) $row->fk_soc, 'the thirdparty of the requester, not of the site');

		// The list shows the requester, the site in the badge
		$sql = new InboxSearchSql($this->db);
		$list = $sql->search(array($devis->id), $folder, array(), '', 10);
		$this->assertSame($requester, $list['rows'][0]['from_email']);
		$this->assertSame('Jean Dupont', $list['rows'][0]['from_name']);
		$this->assertSame(array('email' => self::SITE, 'source' => 'ZzSource form'), $list['rows'][0]['via']);

		// The panel: the requester with the piece to create
		$sources = new InboxDataSource($this->db);
		$of = $sources->ofRow($row);
		$this->assertSame('propal', $of['type']);
		$this->assertSame('D-7', $of['data']['ref']);

		// Page of creation filled with the data of the site
		$creator = new InboxCreate($this->db);
		$url = $creator->createUrl('societe', $row, array('soc' => 0));
		$this->assertStringContainsString('name=Jean+Dupont', $url);
		$this->assertStringContainsString('email='.rawurlencode($requester), $url);
		$this->assertStringContainsString('idprof1=123456789', $url);
		$this->assertStringContainsString('phone=0102030405', $url);
		$this->assertStringContainsString('phone_pro=0102030405', $creator->createUrl('contact', $row, array('soc' => 0)));

		// A contact created later with the address of the requester: its mails follow, the site's own mails too
		$this->db->query("UPDATE ".$p."inbox_mail SET fk_soc = NULL WHERE rowid = ".((int) $row->rowid));
		$this->assertSame(1, (new InboxContactMatcher($this->db))->refreshAddress($requester));
		$this->assertSame($socId, (int) $this->db->getRow("SELECT fk_soc FROM ".$p."inbox_mail WHERE rowid = ".((int) $row->rowid))->fk_soc);
		(new InboxContactMatcher($this->db))->refreshAddress(self::SITE);
		$this->assertSame($socId, (int) $this->db->getRow("SELECT fk_soc FROM ".$p."inbox_mail WHERE rowid = ".((int) $row->rowid))->fk_soc, 'the site never takes the mail back');

		// The answer goes to the requester
		$composer = new InboxComposer($this->db);
		$method = new ReflectionMethod($composer, 'recipientsOf');
		$method->setAccessible(true);
		$raw = $head."\r\nBonjour\r\n";
		$out = array('to' => array(), 'cc' => array());
		$method->invokeArgs($composer, array('reply', InboxMimeParser::parse($raw), InboxReader::headers($raw), array('devis@inbox-test.invalid'), &$out));
		$this->assertSame(array($requester), array_column($out['to'], 'email'));

		// No requester any more (source removed, mail read again): the thirdparty of the sender comes back
		$sources->delete($sourceId);
		$this->assertTrue($sources->store($row, null));
		$row = $this->db->getRow("SELECT * FROM ".$p."inbox_mail WHERE rowid = ".((int) $row->rowid));
		$this->assertNull($row->requester_email);
		$this->assertSame($siteId, (int) $row->fk_soc);
		$this->assertFalse($sources->store($row, null), 'nothing to change');

		$this->db->query("DELETE FROM ".$p."societe WHERE rowid IN (".$socId.", ".$siteId.")");
	}

	/**
	 * Conversations (1.92.1): the mails of a form, all from the site with the same subject, are one conversation per
	 * requester, never one for all; the threads merged before are made again when the source is saved
	 *
	 * @return void
	 */
	public function testThreadsPerRequester()
	{
		$p = $this->db->prefix();
		$this->source();
		$box = $this->box(true, array('status' => InboxAccount::STATUS_ACTIVE));
		$this->db->query("INSERT INTO ".$p."inbox_folder (fk_account, path, name, delimiter, role, status) VALUES (".((int) $box->id).", 'INBOX', 'INBOX', '.', 'inbox', 1)");
		$folder = (int) $this->db->last_insert_id($p.'inbox_folder');
		$auth = 'Authentication-Results: '.self::MX.'; dkim=pass header.d=forms.inbox-test.invalid; dmarc=pass header.from=forms.inbox-test.invalid';
		$t = dol_now() - 3600;
		$n = 0;
		$add = function ($msgid, $from, $subject, $extra, $time, $replyTo = '') use ($box, $folder, &$n) {
			$head = "From: <".$from.">\r\nTo: devis@inbox-test.invalid\r\nSubject: ".$subject."\r\nMessage-ID: <".$msgid.">\r\n"
				.($replyTo !== '' ? "In-Reply-To: <".$replyTo.">\r\nReferences: <".$replyTo.">\r\n" : '').$extra;
			$n++;
			$mail = InboxHeaderParser::parse($head) + array('remote_id' => '1:'.$n, 'sort_key' => $n, 'date_received' => $time, 'size' => 10, 'snippet' => '',
				'seen' => 0, 'answered' => 0, 'flagged' => 0, 'draft' => 0, 'keywords' => array(), 'source_header' => $head);
			$this->assertSame(1, (new InboxMail($this->db))->addMany($box->id, $folder, array($mail)));
		};
		$subject = 'Message depuis le formulaire de contact';
		$a = 'alice@client.inbox-test.invalid';
		$b = 'bruno@other.inbox-test.invalid';
		$add('form-a@forms.inbox-test.invalid', self::SITE, $subject, $auth."\r\nX-Data-Form-Email: ".$a."\r\n", $t);
		$add('form-b@forms.inbox-test.invalid', self::SITE, $subject, $auth."\r\nX-Data-Form-Email: ".$b."\r\n", $t + 60);
		// No requester readable: alone, never with the other mails of the form
		$add('form-c@forms.inbox-test.invalid', self::SITE, $subject, $auth."\r\n", $t + 90);
		// The requester writes again without reference: joins by the subject, on the requester
		$add('re-b@other.inbox-test.invalid', $b, 'RE: '.$subject, '', $t + 120);
		// The requester answers the mail of the form
		$add('re-a@client.inbox-test.invalid', $a, 'Re: '.$subject, '', $t + 180, 'form-a@forms.inbox-test.invalid');
		$key = function ($msgid) use ($p, $box) {
			return (string) $this->db->getRow("SELECT thread_key FROM ".$p."inbox_mail WHERE fk_account = ".((int) $box->id)." AND message_id = '".$this->db->escape($msgid)."'")->thread_key;
		};
		$check = function ($when) use ($key) {
			$ka = $key('form-a@forms.inbox-test.invalid');
			$kb = $key('form-b@forms.inbox-test.invalid');
			$kc = $key('form-c@forms.inbox-test.invalid');
			$this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $ka, $when);
			$this->assertNotSame($ka, $kb, $when.': two requesters, two conversations');
			$this->assertNotSame($ka, $kc, $when.': no requester, alone');
			$this->assertNotSame($kb, $kc, $when.': no requester, alone');
			$this->assertSame($ka, $key('re-a@client.inbox-test.invalid'), $when.': the answer in the conversation of its mail');
			return $kb;
		};
		$this->assertSame($check('new mails'), $key('re-b@other.inbox-test.invalid'), 'the requester joins by the subject');

		// Indexed before the source (no requester, one thread for all): made again when the source is saved
		$this->db->query("UPDATE ".$p."inbox_mail SET thread_key = 'merged', requester_email = NULL, fk_source = NULL WHERE fk_account = ".((int) $box->id));
		$this->assertSame(5, InboxDataSource::rethread($this->db, microtime(true) + 10));
		$check('made again');
		$this->assertSame(0, (int) $this->db->getRow("SELECT COUNT(rowid) AS n FROM ".$p."inbox_mail WHERE fk_account = ".((int) $box->id)." AND thread_key IS NULL")->n);
	}
}

