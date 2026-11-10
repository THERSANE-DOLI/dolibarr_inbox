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
 * \file    inbox/class/inboxdatasource.class.php
 * \ingroup inbox
 * \brief   Data sources (lot A2 level 1, 1.72.0): mails sent by a site or an application (quote form, shop, ticket
 *          tool) from its own address, the real requester written in structured headers
 */

require_once __DIR__.'/inboxreader.class.php';

/**
 * A source declares its trusted senders (addresses or domains; none = the source does nothing), the headers it reads
 * (address of the requester, data in JSON on one line, one header per field), the keys of its JSON mapped to the
 * fields of the thirdparty and the contact, and the kind of request (piece offered to create).
 *
 * The headers are believed only when the sender is one of the source AND authenticated (same rule as the remote
 * pictures, InboxReader::authPass): a header written by anybody else never changes the requester. Nothing is ever
 * written in Dolibarr from them: the thirdparty is looked for on the address of the requester, the creation pages are
 * filled, the answer goes to the requester; the user validates.
 */
class InboxDataSource
{
	/** Fields the data may fill */
	const FIELDS = array('email', 'firstname', 'lastname', 'company', 'phone', 'siren', 'vat', 'address', 'zip', 'town', 'ref');

	/** Kinds of request: the piece offered to create ('' = none) */
	const TYPES = array('', 'propal', 'commande', 'societe', 'ticket');

	/** Usual names of the keys (lower case, letters and digits only) */
	const KEYS = array(
		'email' => 'email', 'mail' => 'email', 'courriel' => 'email', 'emailaddress' => 'email', 'adressemail' => 'email', 'correo' => 'email',
		'firstname' => 'firstname', 'prenom' => 'firstname', 'givenname' => 'firstname', 'vorname' => 'firstname', 'nombre' => 'firstname',
		'lastname' => 'lastname', 'nom' => 'lastname', 'name' => 'lastname', 'surname' => 'lastname', 'familyname' => 'lastname', 'nachname' => 'lastname',
		'cognome' => 'lastname', 'apellido' => 'lastname',
		'company' => 'company', 'societe' => 'company', 'companyname' => 'company', 'organization' => 'company', 'organisation' => 'company',
		'raisonsociale' => 'company', 'entreprise' => 'company', 'firma' => 'company', 'azienda' => 'company', 'empresa' => 'company',
		'phone' => 'phone', 'telephone' => 'phone', 'tel' => 'phone', 'mobile' => 'phone', 'phonenumber' => 'phone', 'telefon' => 'phone', 'telefono' => 'phone',
		'siren' => 'siren', 'siret' => 'siren',
		'vat' => 'vat', 'tva' => 'vat', 'vatnumber' => 'vat', 'tvaintra' => 'vat', 'vatid' => 'vat', 'ustid' => 'vat', 'partitaiva' => 'vat',
		'address' => 'address', 'adresse' => 'address', 'street' => 'address', 'strasse' => 'address', 'indirizzo' => 'address', 'direccion' => 'address',
		'zip' => 'zip', 'zipcode' => 'zip', 'postcode' => 'zip', 'postalcode' => 'zip', 'cp' => 'zip', 'codepostal' => 'zip', 'plz' => 'zip', 'cap' => 'zip',
		'town' => 'town', 'city' => 'town', 'ville' => 'town', 'ort' => 'town', 'stadt' => 'town', 'citta' => 'town', 'ciudad' => 'town',
		'ref' => 'ref', 'id' => 'ref', 'reference' => 'ref', 'number' => 'ref', 'requestid' => 'ref', 'quotationid' => 'ref', 'orderid' => 'ref', 'ticketid' => 'ref',
	);

	/**
	 * Ready-made sources (1.74.0): the format advised by the documentation, and the X-Quotation-* headers written by
	 * a quote module. Only the headers and the kind; the trusted senders stay to give.
	 */
	const TEMPLATES = array(
		'dataform' => array('header_email' => 'X-Data-Form-Email', 'header_data' => 'X-Data-Form', 'type' => 'propal'),
		'quotation' => array('header_email' => 'X-Quotation-Mail', 'header_data' => 'X-Quotation-Data', 'type' => 'propal'),
	);

	/** Headers never offered by the analysis of a mail: written by mail servers, filters and mailing tools */
	const TECHNICAL_HEADERS = '/^x-(mailer|spam|rspamd|virus|originating|ms-|microsoft|google|gm-|received|forwarded|priority|msmail|mimeole|ses-|mailgun|sg-|mc-|feedback|report|auto-response|autoreply|php-originating|envelope|original|apple|proofpoint|ironport|barracuda|sender-id|antiabuse|authenticated|source|get-message|mime-autoconverted|csa|campaign|abuse|ovh|vr-|ovhremote|halon|pm-|postmark|mailjet|sib-|mid|pobox|smtpapi|ymail|yahoo|aol|binding|list|beenthere|mailman|uid|keywords|ham|bogosity|dspam|dkim|amp|accept|has-attach|tnef|ms-has|ms-tnef|ms-exchange|exchange)/i';

	/** Longest JSON read (the headers of the reading are cut at 2000 characters) */
	const MAX_JSON = 2000;

	/** Sources of one entity */
	const MAX_SOURCES = 50;

	/** @var DoliDB */
	protected $db;

	/** @var string */
	public $error = '';

	/** @var array<int,array<int,array<string,mixed>>> Active sources per entity, for the pass */
	protected static $active = array();

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct(DoliDB $db)
	{
		$this->db = $db;
	}

	/**
	 * Sources of the entity, for the settings
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function fetchAll()
	{
		global $conf;

		$rows = $this->db->getRows("SELECT rowid, code, label, status, senders, header_email, header_data, header_fields, mapping, request_type FROM ".$this->db->prefix()."inbox_source"
			." WHERE entity = ".((int) $conf->entity)." ORDER BY label, rowid LIMIT ".self::MAX_SOURCES);
		$out = array();
		foreach (is_array($rows) ? $rows : array() as $r) {
			$out[] = self::shape($r);
		}

		return $out;
	}

	/**
	 * @param object $r Row
	 * @return array<string,mixed>
	 */
	protected static function shape($r)
	{
		return array(
			'id' => (int) $r->rowid, 'code' => (string) $r->code, 'label' => (string) $r->label, 'status' => (int) $r->status,
			'senders' => (string) $r->senders, 'header_email' => (string) $r->header_email, 'header_data' => (string) $r->header_data,
			'header_fields' => (string) $r->header_fields, 'mapping' => (string) $r->mapping, 'type' => (string) $r->request_type,
		);
	}

	/**
	 * Active sources with at least one trusted sender, read once per pass
	 *
	 * @return array<int,array<string,mixed>> Shaped, with the parsed lists (senders, fields, keys)
	 */
	public function active()
	{
		global $conf;

		$entity = (int) $conf->entity;
		if (isset(self::$active[$entity])) {
			return self::$active[$entity];
		}
		$out = array();
		// Module updated but not reactivated yet: no table, no source
		$rows = $this->db->getRows("SELECT rowid, code, label, status, senders, header_email, header_data, header_fields, mapping, request_type FROM ".$this->db->prefix()."inbox_source"
			." WHERE entity = ".$entity." AND status = 1 ORDER BY rowid LIMIT ".self::MAX_SOURCES);
		foreach (is_array($rows) ? $rows : array() as $r) {
			$s = self::shape($r);
			$s['sender_list'] = self::parseSenders($s['senders']);
			$s['field_list'] = self::parsePairs($s['header_fields'], true);
			$s['key_list'] = self::parsePairs($s['mapping']);
			if ($s['sender_list']) {
				$out[] = $s;
			}
		}
		self::$active[$entity] = $out;

		return $out;
	}

	/**
	 * Forget the sources read (after a change, and between the tests)
	 *
	 * @return void
	 */
	public static function reset()
	{
		self::$active = array();
	}

	/**
	 * Headers the synchronisation asks the server in addition, when a source is active: the ones of the sources and
	 * the results of the authentication
	 *
	 * @return string[] Names, upper case
	 */
	public function syncHeaders()
	{
		$names = array();
		foreach ($this->active() as $s) {
			foreach (array_merge(array($s['header_email'], $s['header_data']), array_keys($s['field_list'])) as $h) {
				if ($h !== '' && self::validHeaderName($h)) {
					$names[strtoupper($h)] = true;
				}
			}
		}
		if ($names) {
			$names['AUTHENTICATION-RESULTS'] = true;
			$names['RECEIVED-SPF'] = true;
		}

		return array_keys($names);
	}

	/**
	 * Requester of a mail, from its headers
	 *
	 * @param array<int,array{0:string,1:string}> $headers   Headers (InboxReader::headers)
	 * @param string                              $fromEmail Address of the sender
	 * @param array<int,array<string,mixed>>|null $sources   Sources to try (null: the active ones)
	 * @return array{ok:bool,why:string,source:int,label:string,type:string,email:string,name:string,data:array<string,string>}|null
	 *         Null when no source has this sender; ok false with why: auth (not authenticated), json (data unreadable),
	 *         twice (a header given twice), noemail (no valid address of the requester)
	 */
	public function detect(array $headers, $fromEmail, $sources = null)
	{
		$from = strtolower(trim((string) $fromEmail));
		$source = null;
		foreach ($sources === null ? $this->active() : $sources as $s) {
			if (self::senderIn($from, $s['sender_list'] ?? self::parseSenders((string) $s['senders']))) {
				$source = $s;
				break;
			}
		}
		if ($source === null) {
			return null;
		}
		$out = array('ok' => false, 'why' => '', 'source' => (int) $source['id'], 'label' => (string) $source['label'], 'type' => (string) $source['type'],
			'email' => '', 'name' => '', 'data' => array());
		if (!InboxReader::authPass(InboxReader::authResults($headers), $from)) {
			$out['why'] = 'auth';
			return $out;
		}
		$keys = $source['key_list'] ?? self::parsePairs((string) $source['mapping']);
		$data = array();
		if ((string) $source['header_data'] !== '') {
			$values = self::values($headers, (string) $source['header_data']);
			if (count($values) > 1) {
				$out['why'] = 'twice';
				return $out;
			}
			if ($values) {
				$json = trim($values[0]);
				$decoded = strlen($json) <= self::MAX_JSON ? json_decode($json, true, 4) : null;
				if (!is_array($decoded)) {
					$out['why'] = 'json';
					return $out;
				}
				foreach (self::flatten($decoded) as $k => $v) {
					$field = self::fieldOf($k, $keys);
					if ($field !== '' && !isset($data[$field])) {
						$data[$field] = $v;
					}
				}
			}
		}
		foreach ($source['field_list'] ?? self::parsePairs((string) $source['header_fields'], true) as $h => $field) {
			$values = self::values($headers, $h);
			if (count($values) > 1) {
				$out['why'] = 'twice';
				return $out;
			}
			if ($values && in_array($field, self::FIELDS, true)) {
				$data[$field] = $values[0];
			}
		}
		$email = '';
		if ((string) $source['header_email'] !== '') {
			$values = self::values($headers, (string) $source['header_email']);
			if (count($values) > 1) {
				$out['why'] = 'twice';
				return $out;
			}
			$email = $values ? $values[0] : '';
		}
		if ($email === '' && isset($data['email'])) {
			$email = $data['email'];
		}
		$clean = array();
		foreach ($data as $field => $v) {
			$v = self::clean($v);
			if ($v !== '') {
				$clean[$field] = $v;
			}
		}
		$email = self::address($email);
		if ($email === '') {
			$out['why'] = 'noemail';
			$out['data'] = $clean;
			return $out;
		}
		$clean['email'] = $email;
		$out['ok'] = true;
		$out['email'] = $email;
		$out['name'] = trim(($clean['firstname'] ?? '').' '.($clean['lastname'] ?? '')) ?: ($clean['company'] ?? '');
		$out['data'] = $clean;

		return $out;
	}

	/**
	 * The fields with the names understood for each, for the glossary of the settings
	 *
	 * @return array<int,array{code:string,names:string[]}>
	 */
	public static function glossary()
	{
		$out = array();
		foreach (self::FIELDS as $f) {
			$out[] = array('code' => $f, 'names' => array_values(array_keys(array_filter(self::KEYS, function ($v) use ($f) {
				return $v === $f;
			}))));
		}

		return $out;
	}

	/**
	 * What a mail of a site offers to make a source (1.74.0): its sender, whether it is authenticated, its own headers
	 * (X-...) with a guessed role, and for a JSON its keys with their value and guessed field. Nothing is saved: the
	 * admin checks and corrects in the form.
	 *
	 * @param array<int,array{0:string,1:string}> $headers Headers (InboxReader::headers)
	 * @return array{from:string,domain:string,authenticated:bool,headers:array<int,array<string,mixed>>}
	 */
	public static function analyze(array $headers)
	{
		require_once __DIR__.'/inboxsenderauth.class.php';
		$from = InboxSenderAuth::analyzeFrom(InboxSenderAuth::parseHeaders(implode("\r\n", array_map(function ($h) {
			return $h[0].': '.$h[1];
		}, $headers))));
		$address = strtolower((string) $from['address']);
		$out = array('from' => $address, 'domain' => (string) substr((string) strrchr($address, '@'), 1),
			'authenticated' => $address !== '' && InboxReader::authPass(InboxReader::authResults($headers), $address), 'headers' => array());
		$seen = array();
		foreach ($headers as $h) {
			$name = (string) $h[0];
			if (!preg_match('/^x-/i', $name) || preg_match(self::TECHNICAL_HEADERS, $name) || !self::validHeaderName($name) || isset($seen[strtolower($name)])) {
				continue;
			}
			$seen[strtolower($name)] = true;
			$value = trim((string) $h[1]);
			$item = array('name' => $name, 'value' => mb_substr($value, 0, 300, 'UTF-8'), 'role' => 'field', 'field' => '', 'keys' => array());
			$json = strlen($value) <= self::MAX_JSON && $value !== '' && $value[0] === '{' ? json_decode($value, true, 4) : null;
			if (is_array($json)) {
				$item['role'] = 'data';
				foreach (self::flatten($json) as $k => $v) {
					$item['keys'][] = array('key' => (string) $k, 'value' => mb_substr(self::clean($v), 0, 120, 'UTF-8'), 'field' => self::fieldOf($k), 'usual' => self::fieldOf($k) !== '');
				}
			} else {
				// The end of the name says the field: X-Form-Phone, X-Quotation-Mail
				$parts = explode('-', $name);
				$item['field'] = self::fieldOf((string) end($parts));
				if (self::address($value) !== '' && ($item['field'] === 'email' || $item['field'] === '')) {
					$item['role'] = 'email';
					$item['field'] = '';
				} elseif ($item['field'] === '') {
					$item['role'] = 'ignore';
				}
			}
			$out['headers'][] = $item;
			if (count($out['headers']) >= 30) {
				break;
			}
		}

		return $out;
	}

	/**
	 * Requester of a raw header (synchronisation: the headers asked only)
	 *
	 * @param string $rawHeader Raw header
	 * @param string $fromEmail Sender
	 * @return array<string,mixed>|null See detect()
	 */
	public function detectRaw($rawHeader, $fromEmail)
	{
		if (!$this->active()) {
			return null;
		}

		return $this->detect(InboxReader::headers(rtrim((string) $rawHeader)."\n\n"), $fromEmail);
	}

	/**
	 * Write the requester on a mail of the index, and its thirdparty (unless chosen by hand). A mail without a
	 * requester (any more) gets back the thirdparty of its sender.
	 *
	 * @param object                    $row    Mail (rowid, from_email, soc_manual, requester_email)
	 * @param array<string,mixed>|null  $found  detect()
	 * @return bool                              Changed
	 */
	public function store($row, $found)
	{
		$ok = is_array($found) && !empty($found['ok']);
		$email = $ok ? (string) $found['email'] : '';
		if ($email === (string) ($row->requester_email ?? '') && (!$ok || (int) ($row->fk_source ?? 0) === (int) $found['source'])) {
			return false;
		}
		require_once __DIR__.'/inboxcontactmatcher.class.php';
		$matcher = new InboxContactMatcher($this->db);
		$m = $matcher->match($ok ? $email : (string) $row->from_email);
		$str = function ($v, $max) {
			$v = InboxHeaderParser::forDb($this->db, (string) $v);
			return $v === '' ? 'NULL' : "'".$this->db->escape(dol_trunc($v, $max, 'right', 'UTF-8', 1))."'";
		};
		$sql = "UPDATE ".$this->db->prefix()."inbox_mail SET requester_email = ".$str($email, 255).", requester_name = ".$str($ok ? $found['name'] : '', 255).",";
		$sql .= " fk_source = ".($ok ? (int) $found['source'] : 'NULL').", source_data = ".$str($ok ? json_encode($found['data']) : '', 4000);
		if (empty($row->soc_manual)) {
			$sql .= ", fk_soc = ".($m['fk_soc'] ? (int) $m['fk_soc'] : 'NULL').", fk_socpeople = ".($m['fk_socpeople'] ? (int) $m['fk_socpeople'] : 'NULL');
		}
		$sql .= " WHERE rowid = ".((int) $row->rowid);

		return (bool) $this->db->query($sql);
	}

	/**
	 * Requester kept on a mail of the index, for the reading and the panel
	 *
	 * @param object $row Mail (requester_email, requester_name, fk_source, source_data)
	 * @return array{email:string,name:string,source:int,label:string,type:string,data:array<string,string>}|null
	 */
	public function ofRow($row)
	{
		if ((string) ($row->requester_email ?? '') === '') {
			return null;
		}
		$label = '';
		$type = '';
		foreach ($this->active() as $s) {
			if ((int) $s['id'] === (int) $row->fk_source) {
				$label = (string) $s['label'];
				$type = (string) $s['type'];
			}
		}
		if ($label === '') {
			// Source disabled or removed since: the requester stays known, as such
			$r = $this->db->getRow("SELECT label, request_type FROM ".$this->db->prefix()."inbox_source WHERE rowid = ".((int) $row->fk_source));
			$label = is_object($r) ? (string) $r->label : '';
			$type = is_object($r) ? (string) $r->request_type : '';
		}
		$data = json_decode((string) ($row->source_data ?? ''), true);

		return array('email' => (string) $row->requester_email, 'name' => (string) $row->requester_name, 'source' => (int) $row->fk_source,
			'label' => $label, 'type' => $type, 'data' => is_array($data) ? array_map('strval', $data) : array());
	}

	/**
	 * Add or change a source
	 *
	 * @param User                $user User
	 * @param int                 $id   0 to add
	 * @param array<string,mixed> $in   label, status, senders, header_email, header_data, header_fields, mapping, type
	 * @return int                       Id, < 0 KO ($this->error: key of the language file, with the line in error)
	 */
	public function save(User $user, $id, array $in)
	{
		global $conf, $langs;

		$this->error = '';
		$label = dol_trunc(trim((string) ($in['label'] ?? '')), 128, 'right', 'UTF-8', 1);
		if ($label === '') {
			$this->error = $langs->transnoentities('InboxSourceErrorLabel');
			return -1;
		}
		$senders = self::parseSenders((string) ($in['senders'] ?? ''), $bad);
		if ($bad !== '') {
			$this->error = $langs->transnoentities('InboxSourceErrorSender', $bad);
			return -1;
		}
		$headerEmail = trim((string) ($in['header_email'] ?? ''));
		$headerData = trim((string) ($in['header_data'] ?? ''));
		foreach (array($headerEmail, $headerData) as $h) {
			if ($h !== '' && !self::validHeaderName($h)) {
				$this->error = $langs->transnoentities('InboxSourceErrorHeader', $h);
				return -1;
			}
		}
		$fields = self::parsePairs((string) ($in['header_fields'] ?? ''), true, $badLine);
		foreach ($fields as $h => $f) {
			if (!self::validHeaderName($h) || !in_array($f, self::FIELDS, true)) {
				$badLine = $h.' = '.$f;
			}
		}
		if ($badLine !== '') {
			$this->error = $langs->transnoentities('InboxSourceErrorLine', $badLine);
			return -1;
		}
		$keys = self::parsePairs((string) ($in['mapping'] ?? ''), false, $badLine);
		foreach ($keys as $k => $f) {
			// « ignore »: a key of usual name left out on purpose (1.74.0)
			if (!in_array($f, self::FIELDS, true) && $f !== 'ignore') {
				$badLine = $k.' = '.$f;
			}
		}
		if ($badLine !== '') {
			$this->error = $langs->transnoentities('InboxSourceErrorLine', $badLine);
			return -1;
		}
		if ($headerEmail === '' && $headerData === '' && !$fields) {
			$this->error = $langs->transnoentities('InboxSourceErrorNoHeader');
			return -1;
		}
		$type = (string) ($in['type'] ?? '');
		if (!in_array($type, self::TYPES, true)) {
			$type = '';
		}
		$e = function ($v) {
			return $v === '' ? 'NULL' : "'".$this->db->escape($v)."'";
		};
		$sendersText = implode("\n", $senders);
		$fieldsText = implode("\n", array_map(function ($h, $f) {
			return $h.' = '.$f;
		}, array_keys($fields), $fields));
		$keysText = implode("\n", array_map(function ($k, $f) {
			return $k.' = '.$f;
		}, array_keys($keys), $keys));
		$status = !empty($in['status']) ? 1 : 0;
		$p = $this->db->prefix();
		if ($id > 0) {
			$sql = "UPDATE ".$p."inbox_source SET label = ".$e($label).", status = ".$status.", senders = ".$e($sendersText).", header_email = ".$e($headerEmail).",";
			$sql .= " header_data = ".$e($headerData).", header_fields = ".$e($fieldsText).", mapping = ".$e($keysText).", request_type = ".$e($type).", fk_user_modif = ".((int) $user->id);
			$sql .= " WHERE rowid = ".((int) $id)." AND entity = ".((int) $conf->entity);
			if (!$this->db->query($sql)) {
				$this->error = $this->db->lasterror();
				return -2;
			}
			self::reset();
			return (int) $id;
		}
		$n = $this->db->getRow("SELECT COUNT(rowid) AS n FROM ".$p."inbox_source WHERE entity = ".((int) $conf->entity));
		if (is_object($n) && (int) $n->n >= self::MAX_SOURCES) {
			$this->error = $langs->transnoentities('InboxSourceErrorMany', self::MAX_SOURCES);
			return -1;
		}
		$sql = "INSERT INTO ".$p."inbox_source (entity, code, label, status, senders, header_email, header_data, header_fields, mapping, request_type, date_creation, fk_user_creat)";
		$sql .= " VALUES (".((int) $conf->entity).", ".$e((string) ($in['code'] ?? '')).", ".$e($label).", ".$status.", ".$e($sendersText).", ".$e($headerEmail).", ".$e($headerData).",";
		$sql .= " ".$e($fieldsText).", ".$e($keysText).", ".$e($type).", '".$this->db->idate(dol_now())."', ".((int) $user->id).")";
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -2;
		}
		self::reset();

		return (int) $this->db->last_insert_id($p.'inbox_source');
	}

	/**
	 * Remove a source; its mails keep their requester until read again
	 *
	 * @param int $id Source
	 * @return int 1 OK, < 0 KO
	 */
	public function delete($id)
	{
		global $conf;

		self::reset();

		return $this->db->query("DELETE FROM ".$this->db->prefix()."inbox_source WHERE rowid = ".((int) $id)." AND entity = ".((int) $conf->entity)) ? 1 : -1;
	}

	/**
	 * Values of one header, in the order of the mail
	 *
	 * @param array<int,array{0:string,1:string}> $headers Headers
	 * @param string                              $name    Name
	 * @return string[]
	 */
	protected static function values(array $headers, $name)
	{
		$out = array();
		foreach ($headers as $h) {
			if (strcasecmp((string) $h[0], (string) $name) === 0) {
				$out[] = (string) $h[1];
			}
		}

		return $out;
	}

	/**
	 * Scalars of the JSON: the first level, then the second one (« customer »: {...}) for the keys not seen yet
	 *
	 * @param array<mixed> $data Decoded
	 * @return array<string,string>
	 */
	protected static function flatten(array $data)
	{
		$out = array();
		$nested = array();
		foreach (array_slice($data, 0, 100, true) as $k => $v) {
			if (is_scalar($v)) {
				$out[(string) $k] = is_bool($v) ? ($v ? '1' : '0') : (string) $v;
			} elseif (is_array($v)) {
				$nested[] = $v;
			}
		}
		foreach ($nested as $n) {
			foreach (array_slice($n, 0, 100, true) as $k => $v) {
				if (is_scalar($v) && !isset($out[(string) $k])) {
					$out[(string) $k] = (string) $v;
				}
			}
		}

		return $out;
	}

	/**
	 * Field of a key of the JSON: the mapping of the source, else the usual names
	 *
	 * @param string               $key  Key
	 * @param array<string,string> $keys Mapping of the source (normalized key => field)
	 * @return string                     '' when unknown
	 */
	public static function fieldOf($key, array $keys = array())
	{
		$k = self::normKey($key);
		if (isset($keys[$k])) {
			return in_array($keys[$k], self::FIELDS, true) ? $keys[$k] : '';
		}

		return self::KEYS[$k] ?? '';
	}

	/**
	 * @param string $key Key
	 * @return string      Lower case, without accents, letters and digits only
	 */
	protected static function normKey($key)
	{
		return (string) preg_replace('/[^a-z0-9]/', '', strtolower(dol_string_unaccent((string) $key)));
	}

	/**
	 * Lines « name = field » (header or key); a key is normalized, a header kept as written
	 *
	 * @param string $text    Text
	 * @param bool   $headers Names of headers (kept as written), else keys of the JSON (normalized)
	 * @param string $bad     First line not understood
	 * @return array<string,string>
	 */
	public static function parsePairs($text, $headers = false, &$bad = '')
	{
		$bad = '';
		$out = array();
		foreach (preg_split('/\r?\n/', (string) $text) as $line) {
			$line = trim($line);
			if ($line === '') {
				continue;
			}
			if (!preg_match('/^([^=:]{1,64})[=:]\s*([a-z]{1,16})$/i', $line, $m)) {
				$bad = $bad === '' ? $line : $bad;
				continue;
			}
			$name = trim($m[1]);
			if ($headers && !self::validHeaderName($name)) {
				$bad = $bad === '' ? $line : $bad;
				continue;
			}
			$out[$headers ? $name : self::normKey($name)] = strtolower($m[2]);
		}

		return array_slice($out, 0, 50, true);
	}

	/**
	 * Trusted senders: addresses or domains, one per line (or separated by commas)
	 *
	 * @param string $text Text
	 * @param string $bad  First one not understood
	 * @return string[]     Lower case
	 */
	public static function parseSenders($text, &$bad = '')
	{
		$bad = '';
		$out = array();
		foreach (preg_split('/[\s,;]+/', strtolower((string) $text)) as $s) {
			$s = trim($s, " \t<>\"'");
			if ($s === '') {
				continue;
			}
			$s = ltrim($s, '@');
			if (strpos($s, '@') !== false ? self::address($s) === '' : !preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $s)) {
				$bad = $bad === '' ? $s : $bad;
				continue;
			}
			$out[$s] = $s;
		}

		return array_slice(array_values($out), 0, 50);
	}

	/**
	 * @param string   $from    Sender
	 * @param string[] $senders Addresses or domains
	 * @return bool              The address, or its domain or a parent domain of it
	 */
	public static function senderIn($from, array $senders)
	{
		$at = strrpos((string) $from, '@');
		if ($at === false) {
			return false;
		}
		$domain = substr($from, $at + 1);
		foreach ($senders as $s) {
			if (strpos($s, '@') !== false ? $s === $from : ($domain === $s || substr($domain, -strlen('.'.$s)) === '.'.$s)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param string $name Name of a header
	 * @return bool         Letters, digits and dashes (an atom of IMAP too)
	 */
	public static function validHeaderName($name)
	{
		return (bool) preg_match('/^[A-Za-z][A-Za-z0-9-]{0,63}$/', (string) $name);
	}

	/**
	 * @param string $value Value
	 * @return string        Text without markup nor control characters, 255 characters at most
	 */
	protected static function clean($value)
	{
		$value = (string) preg_replace('/[\x00-\x1f\x7f]+/u', ' ', strip_tags(html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8')));

		return trim(mb_substr(trim((string) preg_replace('/\s+/u', ' ', $value)), 0, 255, 'UTF-8'));
	}

	/**
	 * @param string $value « Name <address> » or address
	 * @return string        Lower case address, '' when not one
	 */
	protected static function address($value)
	{
		$value = trim((string) $value);
		if (preg_match('/<([^<>\s]+)>/', $value, $m)) {
			$value = $m[1];
		}
		$value = strtolower(trim($value, " \t\"'"));

		return strlen($value) <= 255 && filter_var($value, FILTER_VALIDATE_EMAIL) ? $value : '';
	}
}
