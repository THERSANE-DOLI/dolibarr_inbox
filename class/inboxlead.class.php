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
 * \file    inbox/class/inboxlead.class.php
 * \ingroup inbox
 * \brief   New customer from a mail (lot A1, 1.79.0): what the mail says of its sender, the thirdparties that may be
 *          the same, the form of the panel (what Dolibarr asks on its page of creation) and the quick creation
 */

require_once __DIR__.'/inboxreader.class.php';
require_once __DIR__.'/inboxsafe.class.php';
require_once __DIR__.'/inboxlink.class.php';
require_once __DIR__.'/inboxmailref.class.php';
require_once __DIR__.'/inboxthreadlink.class.php';
require_once __DIR__.'/inboxcontactmatcher.class.php';
require_once __DIR__.'/inboxdomains.class.php';
require_once __DIR__.'/inboxtrusteddomain.class.php';
require_once __DIR__.'/inboxelements.class.php';
require_once __DIR__.'/inboxuserparam.class.php';

/**
 * Nothing is created without the user: the panel shows each value with its source and the user creates. The values
 * come in this order, the first source of a field wins, the others stay offered: headers of a trusted data source
 * (A2), attached vCard, display name, signature (never the quoted text), « Label: value » lines, domain of the
 * address (never a public, disposable or shared one, A7). A module adds values and fields by the hook
 * inboxLeadExtract.
 *
 * Quick creation calls the classes of Dolibarr: its triggers run, the page of creation does not. What this page
 * would ask is read from the setup (rules of the core, mandatory settings of the thirdparties, mandatory extrafields,
 * mandatory fields per page of the default values) and checked again here, as Dolibarr checks the last ones only in
 * the browser; a field the panel cannot fill sends the user to the full page.
 */
class InboxLead
{
	/** Fields of the thirdparty: name of the field of the page of creation of Dolibarr */
	const SOC_FIELDS = array('name', 'phone', 'email', 'url', 'address', 'zipcode', 'town', 'country_id', 'idprof1', 'idprof2', 'idprof3', 'idprof4', 'idprof5', 'idprof6', 'tva_intra', 'typent_id', 'customer_code', 'supplier_code');

	/** Fields of the contact: name of the field of the page of creation of Dolibarr */
	const CONTACT_FIELDS = array('firstname', 'lastname', 'poste', 'phone_pro', 'phone_mobile', 'email');

	/** « Label: value » lines and fields of a vCard: normalized label => field (thirdparty « s: », contact « c: ») */
	const LABELS = array(
		'nom' => 'c:lastname', 'name' => 'c:lastname', 'last name' => 'c:lastname', 'lastname' => 'c:lastname', 'nachname' => 'c:lastname', 'cognome' => 'c:lastname', 'apellido' => 'c:lastname', 'apellidos' => 'c:lastname',
		'prenom' => 'c:firstname', 'first name' => 'c:firstname', 'firstname' => 'c:firstname', 'vorname' => 'c:firstname', 'nome' => 'c:firstname', 'nombre' => 'c:firstname',
		'societe' => 's:name', 'entreprise' => 's:name', 'raison sociale' => 's:name', 'company' => 's:name', 'organisation' => 's:name', 'organization' => 's:name', 'firma' => 's:name', 'unternehmen' => 's:name', 'azienda' => 's:name', 'ragione sociale' => 's:name', 'empresa' => 's:name',
		'telephone' => 's:phone', 'tel' => 's:phone', 'phone' => 's:phone', 'telefon' => 's:phone', 'telefono' => 's:phone',
		'mobile' => 'c:phone_mobile', 'portable' => 'c:phone_mobile', 'cell' => 'c:phone_mobile', 'gsm' => 'c:phone_mobile', 'handy' => 'c:phone_mobile', 'mobil' => 'c:phone_mobile', 'cellulare' => 'c:phone_mobile', 'movil' => 'c:phone_mobile',
		'email' => 'c:email', 'e-mail' => 'c:email', 'mail' => 'c:email', 'courriel' => 'c:email', 'correo' => 'c:email',
		'adresse' => 's:address', 'address' => 's:address', 'anschrift' => 's:address', 'indirizzo' => 's:address', 'direccion' => 's:address', 'strasse' => 's:address',
		'code postal' => 's:zipcode', 'cp' => 's:zipcode', 'zip' => 's:zipcode', 'zip code' => 's:zipcode', 'postcode' => 's:zipcode', 'postal code' => 's:zipcode', 'plz' => 's:zipcode', 'cap' => 's:zipcode', 'codigo postal' => 's:zipcode',
		'ville' => 's:town', 'city' => 's:town', 'town' => 's:town', 'stadt' => 's:town', 'ort' => 's:town', 'citta' => 's:town', 'ciudad' => 's:town', 'localidad' => 's:town',
		'siret' => 's:idprof2', 'siren' => 's:idprof1', 'tva' => 's:tva_intra', 'n tva' => 's:tva_intra', 'tva intracommunautaire' => 's:tva_intra', 'vat' => 's:tva_intra', 'vat number' => 's:tva_intra', 'ust-idnr' => 's:tva_intra', 'ustid' => 's:tva_intra', 'partita iva' => 's:tva_intra', 'nif' => 's:tva_intra', 'cif' => 's:tva_intra',
		'fonction' => 'c:poste', 'poste' => 'c:poste', 'job title' => 'c:poste', 'position' => 'c:poste', 'title' => 'c:poste', 'funktion' => 'c:poste', 'ruolo' => 'c:poste', 'cargo' => 'c:poste',
		'site' => 's:url', 'site web' => 's:url', 'site internet' => 's:url', 'website' => 's:url', 'web' => 's:url', 'webseite' => 's:url', 'sito' => 's:url', 'sitio web' => 's:url',
	);

	/** Words of a job in a signature (any of the 5 languages), lower case without accents */
	const JOBS = '(gerant|gerante|directeur|directrice|director|directora|direttore|manager|responsable|president|presidente|ceo|cto|cfo|coo|fondateur|fondatrice|founder|co-founder|assistant|assistante|assistente|commercial|commerciale|charge d|chargee d|acheteur|acheteuse|buyer|purchasing|comptable|accountant|ingenieur|engineer|technicien|technician|consultant|consultante|geschaftsfuhrer|geschaeftsfuehrer|leiter|leiterin|vertrieb|einkauf|amministratore|titolare|socio|gerente|jefe|jefa|administrateur|administrator|secretaire|secretary|office manager|account manager|sales)';

	/** @var DoliDB */
	protected $db;

	/** @var string */
	public $error = '';

	/** @var string Code of the error (need_full, rights, ...) */
	public $errorCode = '';

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct(DoliDB $db)
	{
		$this->db = $db;
	}

	// ---------------------------------------------------------------- Extraction

	/**
	 * What the mail says of its sender
	 *
	 * @param object       $row     Mail of the index (from_email, from_name, requester_*, source_data)
	 * @param InboxMessage $message Mail parsed
	 * @return array{soc:array<string,array<int,array{value:string,source:string,quote?:string}>>,contact:array<string,array<int,array{value:string,source:string,quote?:string}>>,private:bool,domain:string,domain_category:string}
	 */
	public function extract($row, $message)
	{
		$out = array('soc' => array(), 'contact' => array());
		$add = function ($part, $field, $value, $source, $quote = '') use (&$out) {
			$value = self::cleanValue($field, (string) $value);
			if ($value === '' || mb_strlen($value, 'UTF-8') > 255) {
				return;
			}
			$key = self::sameKey($field, $value);
			foreach ($out[$part][$field] ?? array() as $c) {
				if (self::sameKey($field, $c['value']) === $key) {
					return;
				}
			}
			$c = array('value' => $value, 'source' => $source);
			if ($quote !== '') {
				$c['quote'] = mb_substr($quote, 0, 300, 'UTF-8');
			}
			$out[$part][$field][] = $c;
		};
		// 1. Data source (A2): the requester and what the site said of him
		$email = strtolower((string) $row->from_email);
		$display = (string) $row->from_name;
		if ((string) ($row->requester_email ?? '') !== '') {
			$email = strtolower((string) $row->requester_email);
			$display = (string) $row->requester_name;
			$data = json_decode((string) ($row->source_data ?? ''), true);
			$map = array('company' => 's:name', 'firstname' => 'c:firstname', 'lastname' => 'c:lastname', 'phone' => 's:phone', 'siren' => 's:idprof1', 'vat' => 's:tva_intra',
				'address' => 's:address', 'zip' => 's:zipcode', 'town' => 's:town');
			foreach (is_array($data) ? $data : array() as $k => $v) {
				if (isset($map[$k]) && is_scalar($v)) {
					list($part, $field) = explode(':', $map[$k]);
					$add($part === 's' ? 'soc' : 'contact', $field, (string) $v, 'header');
				}
			}
		}
		// 2. vCard attached
		foreach ($message->attachments as $a) {
			if (preg_match('/\.vcf$/i', (string) $a['name']) || preg_match('#^text/(x-)?vcard#i', (string) $a['mime'])) {
				foreach (self::parseVcard((string) $a['content']) as $part => $fields) {
					foreach ($fields as $field => $values) {
						foreach ($values as $v) {
							$add($part, $field, $v, 'vcard');
						}
					}
				}
			}
		}
		// 3. Display name: « First LAST (Company) »
		$dn = self::splitDisplayName($display, $email);
		if ($dn['company'] !== '') {
			$add('soc', 'name', $dn['company'], 'name');
		}
		$add('contact', 'firstname', $dn['firstname'], 'name');
		$add('contact', 'lastname', $dn['lastname'], 'name');
		// 4. Signature of the mail (its own text, never the quoted one)
		// The text the user sees: the HTML converted when there is one (a tool of signatures may put the signature in the
		// HTML only, « Sent from ... as Plain Text » in the text part)
		$body = (string) $message->bodyHtml !== '' ? InboxMimeParser::htmlToText((string) $message->bodyHtml) : (string) $message->bodyText;
		$own = self::plainLines(self::ownText($body));
		$sig = self::signature($own, $dn);
		$email0 = strtolower((string) $row->from_email);
		$dn['domain_label'] = strpos($email0, '@') !== false ? explode('.', (string) InboxTrustedDomain::registrable(substr($email0, strrpos($email0, '@') + 1)))[0] : '';
		$dn['countries'] = $this->countryNames();
		foreach (self::parseSignature($sig, $dn) as $part => $fields) {
			foreach ($fields as $field => $values) {
				foreach ($values as $v) {
					$add($part, $field, $v[0], 'signature', $v[1]);
				}
			}
		}
		// 5. « Label: value » lines (forms of a site)
		foreach (self::labelPairs($own) as $p) {
			list($part, $field) = explode(':', $p[0]);
			$add($part === 's' ? 'soc' : 'contact', $field, $p[1], 'form', $p[2]);
		}
		// 6. Domain of the address: never a public, disposable or shared one
		$domain = (string) substr($email, (int) strrpos($email, '@') + 1);
		$category = strpos($email, '@') !== false ? (new InboxDomains($this->db))->categoryOf($domain) : '';
		if ($domain !== '' && $category === '') {
			$add('soc', 'name', self::nameOfDomain($domain), 'domain');
			$add('soc', 'url', $domain, 'domain');
		}
		if ($email !== '' && isValidEmail($email)) {
			$add('contact', 'email', $email, 'sender');
			if ($category === '') {
				$add('soc', 'email', $email, 'sender');
			}
		}
		// SIREN of a SIRET, VAT of a French SIREN: computed, the key checked
		foreach ($out['soc']['idprof2'] ?? array() as $c) {
			$add('soc', 'idprof1', substr(preg_replace('/\D/', '', $c['value']), 0, 9), $c['source']);
		}
		foreach ($out['soc']['idprof1'] ?? array() as $c) {
			$siren = preg_replace('/\D/', '', $c['value']);
			if (self::validLuhn($siren, 9)) {
				$add('soc', 'tva_intra', self::frenchVat($siren), 'computed');
			}
		}
		$this->zipTown($out);
		// A module adds its values and fields (hook inboxLeadExtract)
		$this->moduleValues($row, $message, $out);
		$out['private'] = $category === InboxDomains::PUBLIC && empty($out['soc']['name']);
		$out['domain'] = $domain;
		$out['domain_category'] = $category;

		return $out;
	}

	/**
	 * Hook inboxLeadExtract: resArray['values'] = [{part: soc|contact, field, value, source}], ['fields'] = extra fields
	 * of the panel [{part, field (name of the field of the page of creation, options_xxx for an extrafield), label}]
	 *
	 * @param object                $row     Mail of the index
	 * @param InboxMessage          $message Mail parsed
	 * @param array<string,mixed>   $out     Values, added to
	 * @return void
	 */
	protected function moduleValues($row, $message, array &$out)
	{
		global $hookmanager;

		if (!is_object($hookmanager)) {
			return;
		}
		$hookmanager->initHooks(array('inbox'));
		$parameters = array('mail' => $row, 'message' => $message, 'found' => $out);
		$object = null;
		$action = '';
		$out['fields'] = array();
		if ($hookmanager->executeHooks('inboxLeadExtract', $parameters, $object, $action) < 0) {
			return;
		}
		foreach ((array) ($hookmanager->resArray['values'] ?? array()) as $v) {
			$part = ($v['part'] ?? '') === 'contact' ? 'contact' : 'soc';
			$field = (string) ($v['field'] ?? '');
			if (!preg_match('/^[a-z0-9_]{1,64}$/', $field) || !is_scalar($v['value'] ?? null) || trim((string) $v['value']) === '') {
				continue;
			}
			$value = self::cleanValue($field, (string) $v['value']);
			if ($value !== '') {
				$out[$part][$field][] = array('value' => dol_trunc($value, 255, 'right', 'UTF-8', 1), 'source' => dol_trunc((string) ($v['source'] ?? 'module'), 30, 'right', 'UTF-8', 1));
			}
		}
		foreach ((array) ($hookmanager->resArray['fields'] ?? array()) as $f) {
			$field = (string) ($f['field'] ?? '');
			if (preg_match('/^[a-z0-9_]{1,64}$/', $field)) {
				$out['fields'][] = array('part' => ($f['part'] ?? '') === 'contact' ? 'contact' : 'soc', 'field' => $field, 'label' => dol_trunc((string) ($f['label'] ?? $field), 60, 'right', 'UTF-8', 1));
			}
		}
	}

	/**
	 * Countries of the dictionary by their names (English, the language of the user, codes): plain name => id
	 *
	 * @return array<string,int>
	 */
	protected function countryNames()
	{
		global $langs;

		static $cache = null;
		if ($cache !== null) {
			return $cache;
		}
		$langs->load('dict');
		$cache = array();
		$rows = $this->db->getRows("SELECT rowid, code, label FROM ".$this->db->prefix()."c_country WHERE active = 1 AND code IS NOT NULL AND code <> '' LIMIT 400");
		foreach (is_array($rows) ? $rows : array() as $r) {
			foreach (array((string) $r->label, $langs->transnoentities('Country'.$r->code)) as $n) {
				$n = self::plain($n);
				if ($n !== '' && strpos($n, 'country') !== 0) {
					$cache[$n] = (int) $r->rowid;
				}
			}
		}

		return $cache;
	}

	/**
	 * Zip code of a town or town of a zip code from the dictionary of Dolibarr (Setup > Dictionaries > Zip codes),
	 * when the answer is one
	 *
	 * @param array<string,mixed> $out Values found, completed
	 * @return void
	 */
	protected function zipTown(array &$out)
	{
		$town = (string) ($out['soc']['town'][0]['value'] ?? '');
		$zip = (string) ($out['soc']['zipcode'][0]['value'] ?? '');
		if (($town === '') === ($zip === '')) {
			return;
		}
		$p = $this->db->prefix();
		$country = (int) ($out['soc']['country_id'][0]['value'] ?? 0);
		$where = " WHERE active = 1".($country > 0 ? " AND fk_pays = ".$country : "");
		if ($zip === '') {
			$rows = $this->db->getRows("SELECT DISTINCT zip as v FROM ".$p."c_ziptown".$where." AND LOWER(town) = '".$this->db->escape(mb_strtolower($town, 'UTF-8'))."' LIMIT 2");
			$field = 'zipcode';
		} else {
			$rows = $this->db->getRows("SELECT DISTINCT town as v FROM ".$p."c_ziptown".$where." AND zip = '".$this->db->escape($zip)."' LIMIT 2");
			$field = 'town';
		}
		if (is_array($rows) && count($rows) === 1 && (string) $rows[0]->v !== '') {
			$out['soc'][$field][] = array('value' => (string) $rows[0]->v, 'source' => 'dictionary');
		}
	}

	/**
	 * Text of a mail ready to read: no target of a link (« Tel: 01 23<tel:0123> » of a HTML converted), no picture
	 * (« [cid:image001.png] »), no control character
	 *
	 * @param string $text Text
	 * @return string
	 */
	public static function plainLines($text)
	{
		$text = preg_replace('/<\s*(?:tel|mailto|https?|sip|callto|cid|ftp):[^>\n]*>/iu', ' ', (string) $text);
		$text = preg_replace('/\[(?:cid|image)[^\]\n]*\]/iu', ' ', $text);
		$text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);

		return preg_replace('/[ \t\x{00A0}]+/u', ' ', $text);
	}

	/**
	 * A value cleaned for its field, '' when it is not one: phones formatted, emails checked, site without its scheme,
	 * identifiers without spaces, no target of a link, no tag, single spaces
	 *
	 * @param string $field Field
	 * @param string $value Value
	 * @return string
	 */
	public static function cleanValue($field, $value)
	{
		// The one door (lot S1): no tag, tags coded in entities included (audit INBX-072), no double quote; no < > left
		$v = InboxSafe::line(self::plainLines(strip_tags((string) $value)), 0);
		$v = str_replace(array("\u{2039}", "\u{203A}"), '', $v);
		$v = trim(preg_replace('/\s+/u', ' ', $v), " \t,;:|*_-");
		if ($v === '') {
			return '';
		}
		switch ($field) {
			case 'phone':
			case 'phone_pro':
			case 'phone_mobile':
				return self::formatPhone($v);
			case 'email':
				$v = strtolower(preg_replace('/^mailto:/i', '', $v));
				return isValidEmail($v) ? $v : '';
			case 'url':
				$v = strtolower(preg_replace('#^https?://#i', '', $v));
				$v = rtrim(preg_replace('#[/?\#].*$#', '', $v), '.');
				return preg_match('/^[a-z0-9.-]+\.[a-z]{2,24}$/', $v) ? $v : '';
			case 'idprof1':
			case 'idprof2':
				return preg_replace('/\D/', '', $v);
			case 'tva_intra':
				return strtoupper(preg_replace('/[^0-9A-Za-z]/', '', $v));
			case 'zipcode':
				return strtoupper($v);
		}

		return $v;
	}

	/**
	 * A phone written plainly: « + 33 199 00 12 34 » => « +33 1 99 00 12 34 », « 01.99.00.12.34 » => « 01 99 00 12 34 »
	 *
	 * @param string $v Phone
	 * @return string    '' when it is not a phone
	 */
	public static function formatPhone($v)
	{
		$v = preg_replace('/^(?:tel|t[ée]l|phone)\s*[.:]?\s*/iu', '', trim((string) $v));
		$intl = (bool) preg_match('/^(\+|00)/', $v);
		$digits = preg_replace('/\D/', '', preg_replace('/\(0\)/', '', $v));
		if ($intl && strpos($v, '00') === 0) {
			$digits = substr($digits, 2);
		}
		if (strlen($digits) < 8 || strlen($digits) > 15) {
			return '';
		}
		if ($intl && strpos($digits, '33') === 0 && strlen($digits) === 11) {
			return '+33 '.substr($digits, 2, 1).' '.implode(' ', str_split(substr($digits, 3), 2));
		}
		if (!$intl && strlen($digits) === 10 && $digits[0] === '0') {
			return implode(' ', str_split($digits, 2));
		}
		// Elsewhere: the grouping of the writer kept (« +230 216 95 17 »), only digits, spaces and the « + »
		$w = preg_replace('/\(0\)/', ' ', $v);
		$w = preg_replace('/^00/', '+', trim($w));
		$w = trim(preg_replace('/\s+/', ' ', preg_replace('/[^\d+ ]/', ' ', $w)));

		return preg_replace('/^\+\s+/', '+', $w);
	}

	/**
	 * @param string $field Field
	 * @param string $value Value
	 * @return string Key of the value: digits of a phone or an identifier, lower case otherwise
	 */
	protected static function sameKey($field, $value)
	{
		if (preg_match('/^(phone|phone_pro|phone_mobile|idprof\d|tva_intra)$/', $field)) {
			return strtoupper(preg_replace('/[^0-9A-Za-z]/', '', preg_replace('/^(\+|00)33\s*\(?0?\)?/', '0', $value)));
		}

		return mb_strtolower($value, 'UTF-8');
	}

	/**
	 * Text written by the sender: before the quoted mail (« > » lines, « On ... wrote: », a header block)
	 *
	 * @param string $text Text body
	 * @return string
	 */
	public static function ownText($text)
	{
		$lines = preg_split('/\r\n|\r|\n/', (string) $text);
		$out = array();
		foreach ($lines as $l) {
			$t = trim($l);
			if ($t !== '' && ($t[0] === '>' || preg_match('/^(-{2,}\s*(original message|message d\'origine|ursprüngliche nachricht|messaggio originale|mensaje original)|_{8,}$)/iu', $t)
				|| preg_match('/^(le|on|am|il|el)\s.{4,120}\s(a écrit|wrote|schrieb|ha scritto|escribió)\s*:?$/iu', $t)
				|| preg_match('/^(de|from|von|da)\s*:\s*.+@/iu', $t))) {
				break;
			}
			$out[] = rtrim($l);
		}

		return trim(implode("\n", $out));
	}

	/**
	 * Signature: after « -- », else the last lines of the text
	 *
	 * @param string $own Text of the sender
	 * @return string
	 */
	public static function signature($own, array $dn = array())
	{
		// Lines of the text, without the footers (confidentiality, « Sent from », environment) nor the long lines
		$lines = array();
		foreach (preg_split('/\n/', (string) $own) as $l) {
			$t = trim($l);
			$p = self::plain($t);
			if (mb_strlen($t, 'UTF-8') > 160 || preg_match('/^(disclaimer|disclamer|please consider|avant d.imprimer|ce (message|courriel|mail)|this (e-?mail|message)|confidential|confidentialit|sent from|envoye (de|depuis)|gesendet von|inviato da|enviado desde|le contenu de ce)/', $p)) {
				continue;
			}
			$lines[] = $t;
		}
		$from = -1;
		foreach ($lines as $i => $l) {
			if (preg_match('/^--\s*$/', $l)) {
				$from = $i + 1;
			}
		}
		// The line of the name of the sender, else a closing formula
		$name = self::plain(trim(($dn['firstname'] ?? '').' '.($dn['lastname'] ?? '')));
		$last = self::plain((string) ($dn['lastname'] ?? ''));
		// The whole name first, then a short line holding the last name (never an address, a number, a list)
		foreach (array(true, false) as $exact) {
			for ($i = count($lines) - 1; $from < 0 && $name !== '' && $i >= 0; $i--) {
				$p = self::plain($lines[$i]);
				if ($exact ? $p === $name : (mb_strlen($p, 'UTF-8') <= 60 && mb_strlen($last, 'UTF-8') >= 3 && preg_match('/(^|\s)'.preg_quote($last, '/').'(\s|$)/u', $p)
					&& strpos($p, ' ') !== false && !preg_match('/[@|\d]|[.!?:]$/', $p))) {
					$from = $i;
				}
			}
		}
		for ($i = count($lines) - 1; $from < 0 && $i >= 0; $i--) {
			if (preg_match('/^(cordialement|bien (a vous|cordialement)|salutations|best regards|kind regards|regards|best|cheers|mit freundlichen gr|viele gr|freundliche gr|cordiali saluti|distinti saluti|saluti|saludos|un saludo|atentamente)\b/', self::plain($lines[$i]))) {
				$from = $i + 1;
			}
		}
		if ($from >= 0) {
			return implode("\n", array_slice(array_values(array_filter(array_slice($lines, $from), function ($l) {
				return $l !== '';
			})), 0, 15));
		}
		$lines = array_values(array_filter($lines, function ($l) {
			return $l !== '';
		}));

		return implode("\n", array_slice($lines, -8));
	}

	/**
	 * Values of a signature: phones (mobile or not), identifiers checked by their key, site, job, address, company
	 *
	 * @param string                                          $sig Signature
	 * @param array{firstname:string,lastname:string,company:string} $dn  Display name
	 * @return array<string,array<string,array<int,array{0:string,1:string}>>> part => field => [value, line]
	 */
	public static function parseSignature($sig, array $dn = array())
	{
		$out = array('soc' => array(), 'contact' => array());
		$put = function ($part, $field, $value, $line) use (&$out) {
			$out[$part][$field][] = array($value, $line);
		};
		$prev = '';
		foreach (preg_split('/\n/', (string) $sig) as $line) {
			$l = trim($line);
			if ($l === '') {
				$prev = '';
				continue;
			}
			$plain = self::plain($l);
			// Phones: the label of the line says mobile or fax; else a French 06 / 07 is a mobile
			if (preg_match_all('/(?:(?:\+\s?|\b00)\d{1,3}[\s.\-]?(?:\(0\)[\s.\-]?)?\d(?:[\s.\-]?\d){6,11}|\b0\d(?:[\s.\-]?\d{2}){4})\b/', $l, $m, PREG_OFFSET_CAPTURE)) {
				foreach ($m[0] as $p) {
					// The label of this piece of the line (« T : ... | M : ... »)
					$pieces = preg_split('/\s[|\/]\s|\s\|/', substr($l, 0, $p[1]));
					$before = self::plain(substr((string) end($pieces), -20));
					if (preg_match('/fax|telecopie|\bf\s*[.:]/', $before)) {
						continue;
					}
					$digits = preg_replace('/\D/', '', preg_replace('/^(\+\s?|00)33\s*(\(0\))?/', '0', $p[0]));
					$mobile = preg_match('/(mob|port|cell|gsm|handy|movil|\bm\s*[.:])/', $before) || preg_match('/^0[67]\d{8}$/', $digits);
					if ($mobile) {
						$put('contact', 'phone_mobile', trim($p[0]), $l);
					} else {
						$put('soc', 'phone', trim($p[0]), $l);
					}
				}
			}
			// SIRET / SIREN checked by their key
			if (preg_match('/\b(\d{3}\s?\d{3}\s?\d{3}\s?\d{5})\b/', $l, $m) && self::validLuhn(preg_replace('/\D/', '', $m[1]), 14)) {
				$put('soc', 'idprof2', $m[1], $l);
			} elseif (preg_match('/siren|rcs/i', $plain) && preg_match('/\b(\d{3}\s?\d{3}\s?\d{3})\b/', $l, $m) && self::validLuhn(preg_replace('/\D/', '', $m[1]), 9)) {
				$put('soc', 'idprof1', $m[1], $l);
			}
			// VAT: a French one checked by its key, another country taken as written after its label
			if (preg_match('/\bFR\s?([0-9A-Z]{2})\s?(\d{3}\s?\d{3}\s?\d{3})\b/', $l, $m)) {
				$siren = preg_replace('/\D/', '', $m[2]);
				if (self::frenchVat($siren) === 'FR'.$m[1].$siren) {
					$put('soc', 'tva_intra', 'FR'.$m[1].$siren, $l);
				}
			} elseif (preg_match('/(tva|vat|ust|iva|btw)\b/', $plain) && preg_match('/\b([A-Z]{2}\s?[0-9A-Z]{8,12})\b/', $l, $m)) {
				$put('soc', 'tva_intra', str_replace(' ', '', $m[1]), $l);
			}
			// Site
			$noMail = preg_replace('/\S+@\S+/', ' ', $l);
			if (preg_match('#\b((?:https?://)?(?:www\.)?[a-z0-9][a-z0-9-]{0,62}(?:\.[a-z0-9-]{1,63})*\.[a-z]{2,24})(/[^\s]*)?\b#i', $noMail, $m) && !preg_match('/^\d/', $m[1])) {
				$put('soc', 'url', preg_replace('#^https?://#i', '', $m[1]), $l);
			}
			// French address: « street, 69003 Lyon » or the street on the line before (a line in pieces « - » or with a
			// label of zip code is read below)
			$inPieces = (bool) preg_match('/\s-\s|\b(cp|c\.p\.|code postal|plz|zip|postcode|postal code)\b/iu', $l);
			if ($inPieces) {
				$m = array();
			} elseif (preg_match('/^(.*?)[,\s]+(\d{5})\s+([\p{L}\' \-]{2,60})$/u', $l, $m) && trim($m[1]) !== '' && !preg_match('/\d{6}/', $m[1])) {
				$put('soc', 'address', trim($m[1], " ,"), $l);
				$put('soc', 'zipcode', $m[2], $l);
				$put('soc', 'town', trim($m[3]), $l);
			} elseif (!$inPieces && preg_match('/^(\d{5})\s+([\p{L}\' \-]{2,60})$/u', $l, $m)) {
				$put('soc', 'zipcode', $m[1], $l);
				$put('soc', 'town', trim($m[2]), $l);
			}
			// A line of address in pieces: « Freeport Zone N°11 - Mer Rouge Port Louis CP 11609 - Ile Maurice »: a country
			// of the dictionary at the end, a zip code after its label, the town before it, the rest is the address
			$segs = array_values(array_filter(array_map('trim', preg_split('/\s+-\s+|\s*,\s*|\s+\|\s+/u', $l)), 'strlen'));
			$country = 0;
			if (count($segs) >= 2 && !preg_match('/@/', $l)) {
				$lastSeg = preg_replace('/^(ile|ile de|ile of|isle of|republic of|republique (de|du|d\')|the)\s+/', '', self::plain(end($segs)));
				$country = (int) ($dn['countries'][$lastSeg] ?? 0);
			}
			if ($country > 0 || preg_match('/\b(cp|c\.p\.|code postal|plz|zip|postcode|postal code|cap)\s*[.:]?\s*((?=[a-z0-9]*\d)[a-z0-9]{3,10}(?:\s[0-9a-z]{3})?)\b/iu', $l)) {
				if ($country > 0) {
					$put('soc', 'country_id', (string) $country, $l);
					array_pop($segs);
				}
				foreach ($segs as $k => $seg) {
					if (preg_match('/^(.*?)\s*\b(?:cp|c\.p\.|code postal|plz|zip|postcode|postal code|cap)\s*[.:]?\s*((?=[A-Za-z0-9]*\d)[A-Za-z0-9]{3,10}(?:\s[0-9A-Za-z]{3})?)\s*(.*)$/iu', $seg, $z)) {
						$put('soc', 'zipcode', $z[2], $l);
						$town = trim($z[3]) !== '' ? $z[3] : $z[1];
						if (trim($town) !== '') {
							$put('soc', 'town', trim($town), $l);
						}
						unset($segs[$k]);
					} elseif (preg_match('/^(\d{4,5})\s+(\D{2,60})$/u', $seg, $z)) {
						$put('soc', 'zipcode', $z[1], $l);
						$put('soc', 'town', trim($z[2]), $l);
						unset($segs[$k]);
					}
				}
				if ($segs && preg_match('/\d/', implode(' ', $segs))) {
					$put('soc', 'address', implode(', ', $segs), $l);
				}
			}
			// Street without its zip code on the line: « 56, rue des lilas »; a zone just before joins it
			if (preg_match('/^\d{1,4}\s*(bis|ter|[a-c])?\s*,?\s*(rue|avenue|av\.?|boulevard|bd|chemin|route|rte|place|all[ée]e|impasse|quai|cours|square|voie|lieu[- ]dit|chauss[ée]e|faubourg|street|road|lane|strasse|stra[ßs]e|str\.|weg|platz|via|viale|piazza|calle|avenida|plaza|carrer)\b/iu', $l) && !preg_match('/\d{5}/', $l)) {
				$zone = $prev !== '' && preg_match('/^(z\.?\s?a\.?\s?c\.?|z\.?\s?[aie]\.?|zae|zone|parc|p[ôo]le|technop[ôo]le|r[ée]sidence|b[âa]timent|bat\.?)\b/iu', $prev) ? ', '.$prev : '';
				$put('soc', 'address', $l.$zone, $l);
			}
			// Job: « Jean Dupont, Manager » or a short line of a job
			if (preg_match('/^[^,|\-–]{2,60}[,|\-–]\s*([^,|]{2,60})$/u', $l, $m) && preg_match('/\b'.self::JOBS.'/', self::plain($m[1]))) {
				$put('contact', 'poste', trim($m[1]), $l);
			} elseif (mb_strlen($l, 'UTF-8') <= 50 && !preg_match('/\d|@/', $l) && preg_match('/^'.self::JOBS.'\b/', $plain)) {
				$put('contact', 'poste', $l, $l);
			}
			// Company: a line naming the company of the display name or the domain, or ending by a legal form
			$label = (string) ($dn['domain_label'] ?? '');
			if (!preg_match('/\d|@|:/', $l) && mb_strlen($l, 'UTF-8') <= 60
				&& (($dn['company'] ?? '') !== '' && self::plain($dn['company']) === $plain || (strlen($label) >= 3 && preg_match('/\b'.preg_quote($label, '/').'\b/', $plain))
					|| preg_match('/\b(sarl|sas|sasu|eurl|sa|sci|scop|gmbh|ag|kg|ug|srl|spa|s\.?l\.?|ltd|llc|inc|bv|nv)\b\.?$/i', $l))) {
				$put('soc', 'name', $l, $l);
			}
			$prev = $l;
		}

		return $out;
	}

	/**
	 * « Label: value » lines (form of a site), the label known in the 5 languages
	 *
	 * @param string $text Text of the sender
	 * @return array<int,array{0:string,1:string,2:string}> [target, value, line]
	 */
	public static function labelPairs($text)
	{
		$out = array();
		foreach (preg_split('/\n/', (string) $text) as $line) {
			if (!preg_match('/^\s*([\p{L}][\p{L} .\'°\-]{0,30}?)\s*:\s*(.{1,200})$/u', $line, $m)) {
				continue;
			}
			$label = trim(preg_replace('/[^a-z \-]/', ' ', self::plain($m[1])));
			$label = preg_replace('/\s+/', ' ', $label);
			if (isset(self::LABELS[$label]) && trim($m[2]) !== '') {
				$out[] = array(self::LABELS[$label], trim($m[2]), trim($line));
			}
		}

		// A form has several fields: one « Address: » in a mail written by hand is no form
		return count(array_unique(array_column($out, 0))) >= 2 ? $out : array();
	}

	/**
	 * Display name split: « Jean DUPONT (Dupont SARL) », « Dupont, Jean »; the word in capitals is the last name
	 *
	 * @param string $display Display name
	 * @param string $email   Address (left out of the name)
	 * @return array{firstname:string,lastname:string,company:string}
	 */
	public static function splitDisplayName($display, $email = '')
	{
		$name = trim(str_replace(array('"', '<'.$email.'>', $email), ' ', (string) $display));
		$company = '';
		if (preg_match('/\(([^)@]{2,80})\)/u', $name, $m)) {
			$company = trim($m[1]);
			$name = trim(str_replace($m[0], ' ', $name));
		}
		$name = trim(preg_replace('/\s+/u', ' ', preg_replace('/[^\p{L}\p{M}\' ,.\-]/u', ' ', $name)), ' ,.');
		if ($name === '' || strpos($name, '@') !== false) {
			return array('firstname' => '', 'lastname' => '', 'company' => $company);
		}
		if (preg_match('/^([^,]+),\s*([^,]+)$/u', $name, $m)) {
			return array('firstname' => trim($m[2]), 'lastname' => trim($m[1]), 'company' => $company);
		}
		$words = explode(' ', $name);
		if (count($words) === 1) {
			return array('firstname' => '', 'lastname' => $words[0], 'company' => $company);
		}
		$upper = array();
		$other = array();
		foreach ($words as $w) {
			if (mb_strlen($w, 'UTF-8') > 1 && mb_strtoupper($w, 'UTF-8') === $w && preg_match('/\p{L}/u', $w)) {
				$upper[] = $w;
			} else {
				$other[] = $w;
			}
		}
		if ($upper && $other) {
			return array('firstname' => implode(' ', $other), 'lastname' => implode(' ', $upper), 'company' => $company);
		}

		return array('firstname' => $words[0], 'lastname' => implode(' ', array_slice($words, 1)), 'company' => $company);
	}

	/**
	 * vCard (2.1, 3.0, 4.0): names, organisation, job, phones by type, address, site, address
	 *
	 * @param string $text vCard
	 * @return array<string,array<string,string[]>>
	 */
	public static function parseVcard($text)
	{
		$out = array('soc' => array(), 'contact' => array());
		$text = preg_replace('/\r?\n[ \t]/', '', str_replace("\r\n", "\n", (string) $text));
		foreach (explode("\n", $text) as $line) {
			if (!preg_match('/^(?:item\d+\.)?([A-Z\-]+)((?:;[^:]*)?):(.*)$/i', trim($line), $m)) {
				continue;
			}
			$prop = strtoupper($m[1]);
			$params = strtoupper($m[2]);
			$value = str_replace(array('\\,', '\\;', '\\n', '\\N'), array(',', ';', ' ', ' '), $m[3]);
			if (strpos($params, 'QUOTED-PRINTABLE') !== false) {
				$value = quoted_printable_decode($value);
			}
			switch ($prop) {
				case 'N':
					$p = explode(';', $value);
					$out['contact']['lastname'][] = trim($p[0] ?? '');
					$out['contact']['firstname'][] = trim($p[1] ?? '');
					break;
				case 'ORG':
					$out['soc']['name'][] = trim(explode(';', $value)[0]);
					break;
				case 'TITLE':
				case 'ROLE':
					$out['contact']['poste'][] = trim($value);
					break;
				case 'TEL':
					$v = trim(preg_replace('/^tel:/i', '', $value));
					if (preg_match('/CELL|MOBILE/', $params)) {
						$out['contact']['phone_mobile'][] = $v;
					} elseif (strpos($params, 'FAX') === false) {
						$out[strpos($params, 'HOME') !== false ? 'contact' : 'soc'][strpos($params, 'HOME') !== false ? 'phone_pro' : 'phone'][] = $v;
					}
					break;
				case 'EMAIL':
					$out['contact']['email'][] = trim($value);
					break;
				case 'URL':
					$out['soc']['url'][] = preg_replace('#^https?://#i', '', trim($value));
					break;
				case 'ADR':
					$p = explode(';', $value);
					$out['soc']['address'][] = trim(($p[1] ?? '').' '.($p[2] ?? ''));
					$out['soc']['town'][] = trim($p[3] ?? '');
					$out['soc']['zipcode'][] = trim($p[5] ?? '');
					break;
			}
		}
		foreach ($out as $part => $fields) {
			foreach ($fields as $field => $values) {
				$out[$part][$field] = array_values(array_filter($values, function ($v) {
					return trim((string) $v) !== '';
				}));
			}
		}

		return $out;
	}

	/**
	 * Name of a company from its domain: « mail.dupont-menuiserie.co.uk » => « Dupont-menuiserie »
	 *
	 * @param string $domain Domain
	 * @return string
	 */
	public static function nameOfDomain($domain)
	{
		$reg = InboxTrustedDomain::registrable(strtolower((string) $domain));
		$label = explode('.', $reg !== '' ? $reg : strtolower((string) $domain))[0];

		return $label === '' ? '' : mb_strtoupper(mb_substr($label, 0, 1, 'UTF-8'), 'UTF-8').mb_substr($label, 1, null, 'UTF-8');
	}

	/**
	 * @param string $digits Digits
	 * @param int    $length Length expected
	 * @return bool          The key of Luhn checks (SIREN, SIRET)
	 */
	public static function validLuhn($digits, $length)
	{
		if (!preg_match('/^\d{'.((int) $length).'}$/', (string) $digits)) {
			return false;
		}
		$sum = 0;
		foreach (array_reverse(str_split($digits)) as $i => $d) {
			$d = (int) $d * ($i % 2 ? 2 : 1);
			$sum += $d > 9 ? $d - 9 : $d;
		}

		return $sum % 10 === 0;
	}

	/**
	 * @param string $siren SIREN (9 digits)
	 * @return string        French VAT number of the company
	 */
	public static function frenchVat($siren)
	{
		$key = (12 + 3 * ((int) $siren % 97)) % 97;

		return 'FR'.sprintf('%02d', $key).$siren;
	}

	/**
	 * @param string $s Text
	 * @return string    Lower case, no accents, single spaces
	 */
	protected static function plain($s)
	{
		$s = mb_strtolower((string) $s, 'UTF-8');
		$s = strtr($s, array('à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ç' => 'c', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ì' => 'i',
			'ñ' => 'n', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'ò' => 'o', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ù' => 'u', 'ß' => 'ss', '’' => "'"));

		return trim(preg_replace('/\s+/', ' ', $s));
	}

	// ---------------------------------------------------------------- Same thirdparty

	/**
	 * Thirdparties that may be this one: same domain (A7), same phone, same SIREN or VAT, near name; readable ones
	 *
	 * @param User                $user  User
	 * @param array<string,mixed> $found extract()
	 * @return array<int,array{id:int,name:string,town:string,why:string[]}>
	 */
	public function duplicates(User $user, array $found)
	{
		if (!$user->hasRight('societe', 'lire')) {
			return array();
		}
		$p = $this->db->prefix();
		$why = array();
		$note = function ($rows, $reason) use (&$why) {
			foreach (is_array($rows) ? $rows : array() as $r) {
				$why[(int) $r->rowid][$reason] = $reason;
			}
		};
		$email = (string) (($found['contact']['email'][0]['value'] ?? ''));
		if ($email !== '') {
			$matcher = new InboxContactMatcher($this->db);
			$one = $matcher->matchDomain($email);
			$socs = $one ? array($one) : $matcher->domainCandidates($email)['socs'];
			foreach ($socs as $id) {
				$why[(int) $id]['domain'] = 'domain';
			}
		}
		$base = "SELECT s.rowid FROM ".$p."societe as s WHERE s.entity IN (".getEntity('societe').") AND s.status = 1 AND ";
		foreach (array_merge($found['soc']['phone'] ?? array(), $found['contact']['phone_mobile'] ?? array()) as $c) {
			$digits = substr(preg_replace('/\D/', '', (string) $c['value']), -9);
			if (strlen($digits) === 9) {
				$col = "REPLACE(REPLACE(REPLACE(REPLACE(%s, ' ', ''), '.', ''), '-', ''), '+', '')";
				$like = "'%".$this->db->escape($digits)."'";
				$note($this->db->getRows($base."(".sprintf($col, 's.phone')." LIKE ".$like." OR s.rowid IN (SELECT c.fk_soc FROM ".$p."socpeople as c WHERE c.fk_soc > 0 AND (".sprintf($col, 'c.phone')." LIKE ".$like
					." OR ".sprintf($col, 'c.phone_mobile')." LIKE ".$like."))) LIMIT 5"), 'phone');
			}
		}
		foreach ($found['soc']['idprof1'] ?? array() as $c) {
			$siren = preg_replace('/\D/', '', (string) $c['value']);
			if (strlen($siren) === 9) {
				$note($this->db->getRows($base."(REPLACE(s.siren, ' ', '') = '".$siren."' OR REPLACE(s.siret, ' ', '') LIKE '".$siren."%') LIMIT 5"), 'siren');
			}
		}
		foreach ($found['soc']['tva_intra'] ?? array() as $c) {
			$vat = strtoupper(preg_replace('/[^0-9A-Za-z]/', '', (string) $c['value']));
			if (strlen($vat) >= 8) {
				$note($this->db->getRows($base."REPLACE(UPPER(s.tva_intra), ' ', '') = '".$this->db->escape($vat)."' LIMIT 5"), 'vat');
			}
		}
		foreach (array_slice($found['soc']['name'] ?? array(), 0, 2) as $c) {
			$n = trim((string) $c['value']);
			if (mb_strlen($n, 'UTF-8') >= 4) {
				$note($this->db->getRows($base."(LOWER(s.nom) LIKE '%".$this->db->escape($this->db->escapeforlike(mb_strtolower($n, 'UTF-8')))."%') LIMIT 5"), 'name');
			}
		}
		$out = array();
		foreach (array_slice($why, 0, 8, true) as $id => $reasons) {
			if (!InboxElements::canRead($user, 'societe', $id)) {
				continue;
			}
			$s = $this->db->getRow("SELECT nom, town FROM ".$p."societe WHERE rowid = ".((int) $id));
			if (is_object($s)) {
				$out[] = array('id' => (int) $id, 'name' => (string) $s->nom, 'town' => (string) $s->town, 'why' => array_values($reasons));
			}
		}

		return array_slice($out, 0, 5);
	}

	// ---------------------------------------------------------------- Form of the panel

	/**
	 * What the page of creation of Dolibarr would ask, for the panel: rights, natures, types, code, mandatory fields,
	 * fields the panel cannot fill (blockers), default values of the forms, last choices of the user
	 *
	 * @param User $user User
	 * @return array<string,mixed>
	 */
	public function form(User $user)
	{
		global $conf, $langs, $mysoc;

		$langs->loadLangs(array('companies', 'bills'));
		$canSoc = (bool) $user->hasRight('societe', 'creer');
		$canContact = (bool) $user->hasRight('societe', 'contact', 'creer');
		$code = $this->codeRule();
		$required = array('soc' => array('name' => true), 'contact' => array('lastname' => true));
		foreach (array('email' => 'SOCIETE_EMAIL_MANDATORY', 'tva_intra' => 'SOCIETE_VAT_INTRA_MANDATORY') as $f => $const) {
			if (getDolGlobalString($const)) {
				$required['soc'][$f] = true;
			}
		}
		for ($i = 1; $i <= 6; $i++) {
			if (getDolGlobalString('SOCIETE_IDPROF'.$i.'_MANDATORY')) {
				$required['soc']['idprof'.$i] = true;
			}
		}
		if ($code['required']) {
			$required['soc']['customer_code'] = true;
		}
		$blockers = array('soc' => array(), 'contact' => array());
		foreach (array('SOCIETE_EUID_MANDATORY' => 'EUID', 'SOCIETE_ACCOUNTANCY_CODE_CUSTOMER_MANDATORY' => 'CustomerAccountancyCodeShort', 'SOCIETE_ACCOUNTANCY_CODE_SUPPLIER_MANDATORY' => 'SupplierAccountancyCodeShort') as $const => $label) {
			if (getDolGlobalString($const)) {
				$blockers['soc'][] = $langs->transnoentities($label);
			}
		}
		// Mandatory fields per page (Setup > Default values), per user or for all
		$pageFields = array('soc' => self::SOC_FIELDS, 'contact' => self::CONTACT_FIELDS);
		$extra = array('soc' => $this->extrafieldLabels('societe'), 'contact' => $this->extrafieldLabels('socpeople'));
		foreach (array('soc' => 'societe/card.php', 'contact' => 'contact/card.php') as $part => $page) {
			foreach ($this->pageValues($user, $page, 'mandatory') as $field => $v) {
				if (in_array($field, $pageFields[$part], true) || ($part === 'soc' && in_array($field, array('client', 'fournisseur', 'customerprospect'), true))) {
					$required[$part][$field] = true;
				} else {
					$blockers[$part][] = $extra[$part][$field] ?? $field;
				}
			}
			// Mandatory extrafields shown on the page of creation, without a default value
			foreach ($this->mandatoryExtrafields($part === 'soc' ? 'societe' : 'socpeople') as $label) {
				$blockers[$part][] = $label;
			}
			$blockers[$part] = array_values(array_unique($blockers[$part]));
		}
		$defaults = array('soc' => $this->pageValues($user, 'societe/card.php', 'createform'), 'contact' => $this->pageValues($user, 'contact/card.php', 'createform'));
		$last = json_decode((string) (new InboxUserParam($this->db))->get($user, 'lead_last'), true);

		return array(
			'can' => array('soc' => $canSoc, 'contact' => $canContact),
			'natures' => array(
				'prospect' => !getDolGlobalString('SOCIETE_DISABLE_PROSPECTS'),
				'customer' => !getDolGlobalString('SOCIETE_DISABLE_CUSTOMERS'),
				'supplier' => (isModEnabled('supplier_order') || isModEnabled('supplier_invoice') || isModEnabled('supplier_proposal')) && $user->hasRight('fournisseur', 'lire'),
			),
			'types' => $this->types(),
			'countries' => $this->countries(),
			'country_id' => (int) ($mysoc->country_id ?? 0),
			'idprof' => $this->idprofLabels((string) ($mysoc->country_code ?? '')),
			'code' => $code,
			'required' => array('soc' => array_keys($required['soc']), 'contact' => array_keys($required['contact'])),
			'blockers' => $blockers,
			'defaults' => $defaults,
			'last' => is_array($last) ? $last : array(),
			'categories' => isModEnabled('category') && $user->hasRight('categorie', 'lire') ? $this->categories(2) : array(),
			'categories_supplier' => isModEnabled('category') && $user->hasRight('categorie', 'lire') ? $this->categories(1) : array(),
			'users' => $this->users(),
		);
	}

	/**
	 * What the mail suggests, never filled in silence: the nature by the usage of the mailbox (purchase, transport:
	 * supplier; sales, support: prospect), the sales representatives (the people the mail is assigned to, else the
	 * owner of a personal mailbox, else nobody: on a shared mailbox the user who clicks is not the one of the customer)
	 *
	 * @param object $row Mail of the index
	 * @return array{nature:string,commercial:int[],commercial_why:string}
	 */
	public function suggestions($row)
	{
		$p = $this->db->prefix();
		$acc = $this->db->getRow("SELECT usage_profile, fk_user_owner FROM ".$p."inbox_account WHERE rowid = ".((int) $row->fk_account));
		$usage = is_object($acc) ? (string) $acc->usage_profile : '';
		$nature = in_array($usage, array('purchase', 'transport'), true) ? 'supplier' : (in_array($usage, array('sales', 'support'), true) ? 'prospect' : '');
		$commercial = array();
		$why = '';
		require_once __DIR__.'/inboxassign.class.php';
		$assigned = (new InboxAssign($this->db))->forRows(array($row));
		if (!empty($assigned[(int) $row->rowid])) {
			$commercial = array_values(array_unique(array_map('intval', $assigned[(int) $row->rowid])));
			$why = 'assigned';
		} elseif (is_object($acc) && (int) $acc->fk_user_owner > 0) {
			$commercial = array((int) $acc->fk_user_owner);
			$why = 'owner';
		}

		return array('nature' => $nature, 'commercial' => $commercial, 'commercial_why' => $why);
	}

	/**
	 * @return array<int,array{id:int,label:string}> Active internal users, who may be sales representatives
	 */
	protected function users()
	{
		$rows = $this->db->getRows("SELECT u.rowid, u.firstname, u.lastname, u.login FROM ".$this->db->prefix()."user as u WHERE u.statut = 1 AND (u.fk_soc IS NULL OR u.fk_soc = 0)"
			." AND u.entity IN (".getEntity('user').") ORDER BY u.lastname, u.firstname LIMIT 1000");
		$out = array();
		foreach (is_array($rows) ? $rows : array() as $r) {
			$name = trim($r->firstname.' '.$r->lastname);
			$out[] = array('id' => (int) $r->rowid, 'label' => $name !== '' ? $name : (string) $r->login);
		}

		return $out;
	}

	/**
	 * Numbering of the customer code: automatic, free, asked
	 *
	 * @return array{auto:bool,required:bool}
	 */
	protected function codeRule()
	{
		$name = getDolGlobalString('SOCIETE_CODECLIENT_ADDON', 'mod_codeclient_leopard');
		$out = array('auto' => false, 'required' => false);
		foreach (array(DOL_DOCUMENT_ROOT.'/core/modules/societe/') as $dir) {
			if (preg_match('/^mod_codeclient_[a-z0-9_]+$/i', $name) && is_readable($dir.$name.'.php')) {
				require_once $dir.$name.'.php';
			}
		}
		if (!class_exists($name)) {
			// A numbering of a module: the code is left to it
			return array('auto' => true, 'required' => false);
		}
		$mod = new $name($this->db);
		$out['auto'] = !empty($mod->code_auto);
		$out['required'] = !$out['auto'] && (empty($mod->code_null) || getDolGlobalString('MAIN_COMPANY_CODE_ALWAYS_REQUIRED'));

		return $out;
	}

	/**
	 * Default values of the page of creation (Setup > Default values): mandatory fields or values of the form
	 *
	 * @param User   $user User (its default values are loaded with its rights)
	 * @param string $page Page, relative to the root
	 * @param string $type mandatory or createform
	 * @return array<string,string> Field of the page => value
	 */
	public function pageValues(User $user, $page, $type)
	{
		if (!getDolGlobalString('MAIN_ENABLE_DEFAULT_VALUES')) {
			return array();
		}
		if (!isset($user->default_values) || !is_array($user->default_values)) {
			$user->loadDefaultValues();
		}
		$out = array();
		foreach ((array) ($user->default_values[$page][$type] ?? array()) as $query => $values) {
			// No condition, or the condition of the creation only (action=create)
			if ($query !== '_noquery_' && !preg_match('/^(action=create)?$/', (string) $query)) {
				continue;
			}
			foreach ((array) $values as $field => $value) {
				$out[(string) $field] = (string) $value;
			}
		}

		return $out;
	}

	/**
	 * @param string $elementType societe or socpeople
	 * @return array<string,string> options_xxx => label
	 */
	protected function extrafieldLabels($elementType)
	{
		global $langs;

		require_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';
		$ef = new ExtraFields($this->db);
		$ef->fetch_name_optionals_label($elementType);
		$out = array();
		foreach ((array) ($ef->attributes[$elementType]['label'] ?? array()) as $key => $label) {
			$out['options_'.$key] = $langs->transnoentities((string) $label);
		}

		return $out;
	}

	/**
	 * Extrafields the page of creation would let the user fill (audit INBX-070): enabled, shown on creation, allowed
	 * by their permission, not computed
	 *
	 * @param string $elementType societe or socpeople
	 * @return array<string,array{type:string,size:string,param:array<string,mixed>}> options_xxx => definition
	 */
	protected function writableExtrafields($elementType)
	{
		require_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';
		$ef = new ExtraFields($this->db);
		$ef->fetch_name_optionals_label($elementType);
		$a = (array) ($ef->attributes[$elementType] ?? array());
		$ok = function ($expr) {
			$expr = trim((string) $expr);
			return $expr === '' || (bool) dol_eval($expr, 1, 1, '2');
		};
		$out = array();
		foreach (array_keys((array) ($a['label'] ?? array())) as $key) {
			$list = $a['list'][$key] ?? 1;
			$list = is_numeric($list) ? (int) $list : (int) dol_eval((string) $list, 1, 1, '2');
			if (!empty($a['computed'][$key]) || !in_array(abs($list), array(1, 3), true) || !$ok($a['enabled'][$key] ?? '') || !$ok($a['perms'][$key] ?? '')) {
				continue;
			}
			$out['options_'.$key] = array('type' => (string) ($a['type'][$key] ?? ''), 'size' => (string) ($a['size'][$key] ?? ''),
				'param' => (array) ($a['param'][$key] ?? array()));
		}

		return $out;
	}

	/**
	 * Value of the panel for an extrafield, as its type wants it (lot S2): a text field gets a plain line, an HTML
	 * field the text escaped, a list one of its keys only; the types the panel cannot fill (lists of the database,
	 * links, dates...) get nothing
	 *
	 * @param array{type:string,size:string,param:array<string,mixed>} $def   Extrafield (writableExtrafields())
	 * @param string                                                   $value Value of the panel (cleaned)
	 * @return string|int|float|null null: not written
	 */
	public static function extrafieldValue(array $def, $value)
	{
		$value = (string) $value;
		if ($value === '') {
			return null;
		}
		$size = (int) $def['size'];
		switch ($def['type']) {
			case 'varchar':
			case 'phone':
			case 'mail':
			case 'url':
			case 'ip':
				return InboxSafe::line($value, $size > 0 ? $size : 255);
			case 'text':
				return InboxSafe::lines($value);
			case 'html':
				return InboxSafe::textToHtml($value);
			case 'int':
				return preg_match('/^-?\d{1,18}$/', $value) ? (int) $value : null;
			case 'double':
			case 'price':
			case 'pricecy':
				$n = str_replace(array(' ', ','), array('', '.'), $value);
				return is_numeric($n) ? (float) $n : null;
			case 'boolean':
				return in_array($value, array('0', '1'), true) ? (int) $value : null;
			case 'select':
			case 'radio':
				$options = (array) ($def['param']['options'] ?? array());
				return array_key_exists($value, $options) ? $value : null;
		}

		return null;
	}

	/**
	 * Mandatory extrafields shown on the page of creation and without a default value: the panel cannot fill them
	 *
	 * @param string $elementType societe or socpeople
	 * @return string[] Labels
	 */
	public function mandatoryExtrafields($elementType)
	{
		global $langs;

		require_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';
		$ef = new ExtraFields($this->db);
		$ef->fetch_name_optionals_label($elementType);
		$a = $ef->attributes[$elementType] ?? array();
		$out = array();
		foreach ((array) ($a['required'] ?? array()) as $key => $req) {
			if (empty($req)) {
				continue;
			}
			$enabled = (string) ($a['enabled'][$key] ?? '1');
			$list = (string) ($a['list'][$key] ?? '1');
			// Visible on the page of creation: list 1 or 3 (or their negative « hidden by default »)
			if (($enabled !== '' && $enabled !== '1' && !verifCond($enabled)) || !in_array(ltrim($list, '-'), array('1', '3'), true)) {
				continue;
			}
			if ((string) ($a['default'][$key] ?? '') !== '') {
				continue;
			}
			$out[] = $langs->transnoentities((string) ($a['label'][$key] ?? $key));
		}

		return $out;
	}

	/**
	 * @return array<int,array{id:int,label:string}> Types of thirdparty of the country of the company
	 */
	protected function types()
	{
		global $langs, $mysoc;

		$rows = $this->db->getRows("SELECT id, code, libelle FROM ".$this->db->prefix()."c_typent WHERE active = 1 AND (fk_country IS NULL OR fk_country = ".((int) ($mysoc->country_id ?? 0)).")"
			." ORDER BY position, id LIMIT 100");
		$out = array();
		foreach (is_array($rows) ? $rows : array() as $r) {
			if ((string) $r->code === 'TE_UNKNOWN') {
				continue;
			}
			$t = $langs->transnoentities((string) $r->code);
			$out[] = array('id' => (int) $r->id, 'label' => $t !== (string) $r->code ? $t : (string) $r->libelle);
		}

		return $out;
	}

	/**
	 * @return array<int,array{id:int,label:string}> Countries, by name
	 */
	protected function countries()
	{
		global $langs;

		$langs->load('dict');
		$rows = $this->db->getRows("SELECT rowid, code, label FROM ".$this->db->prefix()."c_country WHERE active = 1 AND code IS NOT NULL AND code <> '' LIMIT 400");
		$out = array();
		foreach (is_array($rows) ? $rows : array() as $r) {
			$t = $langs->transnoentities('Country'.$r->code);
			$out[] = array('id' => (int) $r->rowid, 'label' => $t !== 'Country'.$r->code ? $t : (string) $r->label);
		}
		usort($out, function ($a, $b) {
			return strcoll($a['label'], $b['label']);
		});

		return $out;
	}

	/**
	 * @param string $countryCode Country of the company
	 * @return array<string,string> idprofN => label of the country (SIREN, SIRET...)
	 */
	protected function idprofLabels($countryCode)
	{
		global $langs;

		$out = array();
		for ($i = 1; $i <= 2; $i++) {
			$key = 'ProfId'.$i.$countryCode;
			$t = $langs->transnoentities($key);
			$out['idprof'.$i] = $t !== $key && $t !== '-' ? $t : $langs->transnoentities('ProfId'.$i);
		}
		$out['tva_intra'] = $langs->transnoentities('VATIntra');

		return $out;
	}

	/**
	 * Categories of a type in the order of their tree, as Dolibarr shows them: colour, depth, path « Parent > Child »
	 *
	 * @param int $type 2 customers and prospects, 1 suppliers
	 * @return array<int,array{id:int,label:string,color:string,depth:int,path:string}>
	 */
	protected function categories($type)
	{
		$rows = $this->db->getRows("SELECT rowid, label, fk_parent, color FROM ".$this->db->prefix()."categorie WHERE type = ".((int) $type)." AND entity IN (".getEntity('category').") ORDER BY label LIMIT 500");
		$byParent = array();
		$ids = array();
		foreach (is_array($rows) ? $rows : array() as $r) {
			$ids[(int) $r->rowid] = true;
		}
		foreach (is_array($rows) ? $rows : array() as $r) {
			// A parent out of the list (another entity): the category is a root
			$parent = isset($ids[(int) $r->fk_parent]) ? (int) $r->fk_parent : 0;
			$byParent[$parent][] = $r;
		}
		$out = array();
		$walk = function ($parent, $depth, $path) use (&$walk, &$out, $byParent) {
			foreach ($byParent[$parent] ?? array() as $r) {
				$p = ($path !== '' ? $path.' > ' : '').(string) $r->label;
				$color = preg_match('/^#?[0-9a-f]{6}$/i', (string) $r->color) ? '#'.ltrim((string) $r->color, '#') : '';
				$out[] = array('id' => (int) $r->rowid, 'label' => (string) $r->label, 'color' => $color, 'depth' => $depth, 'path' => $p);
				if ($depth < 10) {
					$walk((int) $r->rowid, $depth + 1, $p);
				}
			}
		};
		$walk(0, 0, '');

		return $out;
	}

	// ---------------------------------------------------------------- Creation

	/**
	 * Values typed in the panel, cleaned: only the known fields (and the fields of the modules, options_xxx)
	 *
	 * @param array<string,mixed> $data  Typed
	 * @param string[]            $allow Fields allowed
	 * @return array<string,string>
	 */
	public static function clean(array $data, array $allow)
	{
		$out = array();
		foreach ($data as $k => $v) {
			if ((in_array($k, $allow, true) || preg_match('/^options_[a-z0-9_]{1,60}$/', (string) $k)) && is_scalar($v)) {
				// Typed by the user: cleaned as the values found (a phone written as the user wants stays a phone)
				$c = in_array($k, array('typent_id', 'country_id'), true) ? (string) (int) $v : self::cleanValue((string) $k, (string) $v);
				$out[$k] = dol_trunc($c, 255, 'right', 'UTF-8', 1);
			}
		}

		return $out;
	}

	/**
	 * What still stops the quick creation: blockers of the setup, mandatory fields left empty
	 *
	 * @param array<string,mixed>  $form    form()
	 * @param array<string,string> $soc     Thirdparty typed
	 * @param array<string,string> $contact Contact typed ('' when no contact)
	 * @param array<string,mixed>  $opts    nature
	 * @return string[] Labels of what is missing ('' when the creation can go)
	 */
	public static function missing(array $form, array $soc, $contact, array $opts)
	{
		$out = $form['blockers']['soc'];
		foreach ($form['required']['soc'] as $f) {
			if (in_array($f, array('client', 'fournisseur', 'customerprospect'), true)) {
				continue;
			}
			if ($f === 'tva_intra' && empty($opts['vat_subject'])) {
				continue;
			}
			// A private person: its name is the one of the contact
			if ($f === 'name' && !empty($opts['private'])) {
				$f = 'contact:lastname';
				if (trim((string) ($contact['lastname'] ?? '')) === '') {
					$out[] = $f;
				}
				continue;
			}
			if (trim((string) ($soc[$f] ?? '')) === '') {
				$out[] = $f;
			}
		}
		if (empty($opts['prospect']) && empty($opts['customer']) && empty($opts['supplier'])) {
			$out[] = 'nature';
		}
		if (is_array($contact)) {
			foreach ($form['required']['contact'] as $f) {
				if (trim((string) ($contact[$f] ?? '')) === '') {
					$out[] = 'contact:'.$f;
				}
			}
		}

		return $out;
	}

	/**
	 * Quick creation: the thirdparty, its contact, the mail and its conversation linked to both. All or nothing.
	 *
	 * @param User                 $user    User
	 * @param object               $row     Mail of the index
	 * @param array<string,string> $soc     Thirdparty (fields of SOC_FIELDS, options_xxx)
	 * @param array<string,string>|null $contact Contact, null for none
	 * @param array<string,mixed>  $opts    prospect, customer, supplier, private, vat_subject, commercial, categories[], use_soc (contact only)
	 * @return array{soc:int,contact:int,contact_error:string}|null
	 */
	public function create(User $user, $row, array $soc, $contact, array $opts)
	{
		global $langs;

		require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
		require_once DOL_DOCUMENT_ROOT.'/contact/class/contact.class.php';
		$useSoc = (int) ($opts['use_soc'] ?? 0);
		$form = $this->form($user);
		if ($useSoc > 0) {
			// Contact of a thirdparty already in Dolibarr (« Use this one »)
			if (!$form['can']['contact'] || !InboxElements::canRead($user, 'societe', $useSoc) || !is_array($contact)) {
				return $this->fail('rights', 'InboxErrorCannotCreate');
			}
		} elseif (!$form['can']['soc'] || (is_array($contact) && empty($opts['private']) && !$form['can']['contact'])) {
			return $this->fail('rights', 'InboxErrorCannotCreate');
		}
		if ($useSoc <= 0) {
			$missing = self::missing($form, $soc, $contact, $opts);
			if ($missing) {
				$this->error = implode(', ', $missing);
				$this->errorCode = $form['blockers']['soc'] ? 'need_full' : 'missing';
				return null;
			}
		}
		$this->db->begin();
		$socId = $useSoc;
		if ($socId <= 0) {
			$s = new Societe($this->db);
			$private = !empty($opts['private']);
			$s->particulier = $private ? 1 : 0;
			$s->name = $private ? dolGetFirstLastname((string) ($contact['firstname'] ?? ''), (string) ($contact['lastname'] ?? $soc['name'] ?? '')) : (string) $soc['name'];
			if ($private) {
				$s->name_bis = (string) ($contact['lastname'] ?? $soc['name'] ?? '');
				$s->firstname = (string) ($contact['firstname'] ?? '');
			}
			$s->client = (!empty($opts['customer']) ? 1 : 0) + (!empty($opts['prospect']) ? 2 : 0);
			$s->fournisseur = !empty($opts['supplier']) ? 1 : 0;
			$code = $form['code'];
			$s->code_client = $s->client ? ($code['auto'] ? -1 : (string) ($soc['customer_code'] ?? '')) : '';
			$s->code_fournisseur = $s->fournisseur ? ($code['auto'] ? -1 : (string) ($soc['supplier_code'] ?? '')) : '';
			foreach (array('address', 'town', 'phone', 'email', 'url', 'idprof1', 'idprof2', 'idprof3', 'idprof4', 'idprof5', 'idprof6', 'tva_intra') as $f) {
				$s->$f = (string) ($soc[$f] ?? '');
			}
			$s->zip = (string) ($soc['zipcode'] ?? '');
			$s->country_id = (int) ($soc['country_id'] ?? 0);
			$s->typent_id = (int) ($soc['typent_id'] ?? 0);
			$s->tva_assuj = !empty($opts['vat_subject']) ? 1 : 0;
			$s->status = 1;
			// As the page of creation: the payment terms of the setup
			$s->cond_reglement_id = (int) ($form['defaults']['soc']['cond_reglement_id'] ?? getDolGlobalInt('MAIN_DEFAULT_PAYMENT_TERM_ID'));
			$s->mode_reglement_id = (int) ($form['defaults']['soc']['mode_reglement_id'] ?? getDolGlobalInt('MAIN_DEFAULT_PAYMENT_TYPE_ID'));
			$writable = $this->writableExtrafields('societe');
			foreach ($soc as $k => $v) {
				$v = isset($writable[$k]) ? self::extrafieldValue($writable[$k], $v) : null;
				if ($v !== null) {
					$s->array_options[$k] = $v;
				}
			}
			$socId = $s->create($user);
			if ($socId <= 0) {
				$this->db->rollback();
				return $this->fail('soc', $s->error ?: implode(', ', array_map(array($langs, 'transnoentities'), (array) $s->errors)));
			}
			if ($private && $s->create_individual($user) < 0) {
				$this->db->rollback();
				return $this->fail('soc', $s->error);
			}
			// Sales representatives chosen in the panel (nobody by default)
			foreach (array_unique(array_filter(array_map('intval', (array) ($opts['commercial'] ?? array())))) as $uid) {
				if (is_object($this->db->getRow("SELECT rowid FROM ".$this->db->prefix()."user WHERE rowid = ".$uid." AND statut = 1"))) {
					$s->add_commercial($user, $uid);
				}
			}
			$cats = array_filter(array_map('intval', (array) ($opts['categories'] ?? array())));
			if ($cats && $s->client && $user->hasRight('categorie', 'creer')) {
				$s->setCategories($cats, 'customer');
			}
			$sup = array_filter(array_map('intval', (array) ($opts['categories_supplier'] ?? array())));
			if ($sup && $s->fournisseur && $user->hasRight('categorie', 'creer')) {
				$s->setCategories($sup, 'supplier');
			}
		}
		$contactId = 0;
		$contactError = '';
		if (is_array($contact) && empty($opts['private'])) {
			$blocked = $form['blockers']['contact'];
			if ($blocked) {
				// The contact needs its page: the thirdparty stays, the bandeau offers the contact
				$contactError = implode(', ', $blocked);
			} else {
				$c = new Contact($this->db);
				$c->socid = $socId;
				$c->lastname = (string) ($contact['lastname'] ?? '');
				$c->firstname = (string) ($contact['firstname'] ?? '');
				$c->poste = (string) ($contact['poste'] ?? '');
				$c->phone_pro = (string) ($contact['phone_pro'] ?? '');
				$c->phone_mobile = (string) ($contact['phone_mobile'] ?? '');
				$c->email = (string) ($contact['email'] ?? '');
				$c->statut = 1;
				$writable = $this->writableExtrafields('socpeople');
				foreach ($contact as $k => $v) {
					$v = isset($writable[$k]) ? self::extrafieldValue($writable[$k], $v) : null;
					if ($v !== null) {
						$c->array_options[$k] = $v;
					}
				}
				$contactId = $c->create($user);
				if ($contactId <= 0) {
					$this->db->rollback();
					return $this->fail('contact', $c->error ?: implode(', ', (array) $c->errors));
				}
			}
		}
		if (!$this->linkMail($user, $row, $socId, $contactId)) {
			$this->db->rollback();
			return null;
		}
		$this->db->commit();
		// The mails of the address now know their thirdparty
		$matcher = new InboxContactMatcher($this->db);
		foreach (array_filter(array((string) ($contact['email'] ?? ''), (string) ($soc['email'] ?? ''))) as $e) {
			$matcher->refreshAddress($e);
		}
		$this->remember($user, $soc, $opts);
		// What this panel made, the only things its undo may remove (audit INBX-069)
		if ($useSoc <= 0) {
			$_SESSION['inbox_lead_made']['societe'][(int) $socId] = dol_now();
		}
		if ($contactId > 0) {
			$_SESSION['inbox_lead_made']['contact'][(int) $contactId] = dol_now();
		}

		return array('soc' => (int) $socId, 'contact' => (int) $contactId, 'contact_error' => $contactError);
	}

	/**
	 * The mail and its conversation (A3) linked to the thirdparty and the contact
	 *
	 * @param User   $user      User
	 * @param object $row       Mail of the index
	 * @param int    $socId     Thirdparty created (0: none)
	 * @param int    $contactId Contact created (0: none)
	 * @return bool
	 */
	public function linkMail(User $user, $row, $socId, $contactId)
	{
		$refs = new InboxMailRef($this->db);
		$ref = $refs->fetchOrCreate($row, $user);
		if ($ref <= 0) {
			$this->fail('link', $refs->error ?: 'InboxErrorNoMessageId');
			return false;
		}
		$linker = new InboxLink($this->db);
		$threads = new InboxThreadLink($this->db);
		foreach (array('societe' => $socId, 'contact' => $contactId) as $type => $id) {
			if ($id > 0) {
				if ($linker->link($ref, $type, $id, $user) < 0) {
					$this->fail('link', $linker->error);
					return false;
				}
				$threads->linkThread($user, $row, $type, $id);
			}
		}

		return true;
	}

	/**
	 * Undo a quick creation (toast « Undo »): the contact and the thirdparty, when nothing else uses them
	 *
	 * @param User $user      User who created
	 * @param int  $socId     Thirdparty (0: kept, a contact of a thirdparty already there)
	 * @param int  $contactId Contact
	 * @return bool
	 */
	public function undo(User $user, $socId, $contactId)
	{
		require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
		require_once DOL_DOCUMENT_ROOT.'/contact/class/contact.class.php';
		$recent = dol_now() - 600;
		$linker = new InboxLink($this->db);
		// Only what this panel made in this session, a few minutes ago (audit INBX-069)
		$made = function ($type, $id) use ($recent) {
			return (int) ($_SESSION['inbox_lead_made'][$type][(int) $id] ?? 0) >= $recent;
		};
		if (($contactId > 0 && !$made('contact', $contactId)) || ($socId > 0 && !$made('societe', $socId))) {
			return (bool) $this->fail('undo', 'InboxErrorCannotCreate');
		}
		if ($contactId > 0) {
			$c = new Contact($this->db);
			if ($c->fetch($contactId) <= 0 || (int) $c->user_creation_id !== (int) $user->id || $c->date_creation < $recent) {
				return (bool) $this->fail('undo', 'InboxErrorCannotCreate');
			}
			if ($c->delete($user) <= 0) {
				return (bool) $this->fail('undo', $c->error);
			}
			$linker->forgetElement('contact', $contactId);
			unset($_SESSION['inbox_lead_made']['contact'][(int) $contactId]);
		}
		if ($socId > 0) {
			$s = new Societe($this->db);
			if ($s->fetch($socId) <= 0 || (int) $s->user_creation_id !== (int) $user->id || $s->date_creation < $recent) {
				return (bool) $this->fail('undo', 'InboxErrorCannotCreate');
			}
			// Societe::delete() refuses a thirdparty used elsewhere
			if ($s->delete($socId, $user) <= 0) {
				return (bool) $this->fail('undo', $s->error);
			}
			$linker->forgetElement('societe', $socId);
			unset($_SESSION['inbox_lead_made']['societe'][(int) $socId]);
		}

		return true;
	}

	/**
	 * Last choices of the user (nature, type), the next panel starts with them
	 *
	 * @param User                $user User
	 * @param array<string,string> $soc  Thirdparty typed
	 * @param array<string,mixed> $opts Options
	 * @return void
	 */
	protected function remember(User $user, array $soc, array $opts)
	{
		// Only what varies little from a customer to the next and stays in sight: never the categories, the sales
		// representatives, the country or the VAT (taken again in silence they would be wrong for the next one)
		$last = array('prospect' => !empty($opts['prospect']) ? 1 : 0, 'customer' => !empty($opts['customer']) ? 1 : 0, 'supplier' => !empty($opts['supplier']) ? 1 : 0,
			'typent_id' => (int) ($soc['typent_id'] ?? 0));
		(new InboxUserParam($this->db))->set($user, 'lead_last', json_encode($last));
	}

	/**
	 * Address of the full page of creation, filled with what the panel holds
	 *
	 * @param array<string,string> $soc     Thirdparty
	 * @param array<string,string>|null $contact Contact (private: first and last names)
	 * @param array<string,mixed>  $opts    Options
	 * @return string
	 */
	public static function fullUrl(array $soc, $contact, array $opts)
	{
		$q = array();
		foreach ($soc as $k => $v) {
			if ($v !== '' && (in_array($k, self::SOC_FIELDS, true) || strpos($k, 'options_') === 0)) {
				$q[$k] = $v;
			}
		}
		if (!empty($opts['private'])) {
			$q['private'] = 1;
			$q['name'] = (string) ($contact['lastname'] ?? $soc['name'] ?? '');
			$q['firstname'] = (string) ($contact['firstname'] ?? '');
		}
		$q['type'] = !empty($opts['customer']) ? 'c' : (!empty($opts['prospect']) ? 'p' : (!empty($opts['supplier']) ? 'f' : ''));
		// The page of Dolibarr ticks the VAT and takes the user as sales representative when nothing is given: what the
		// panel says is sent, nobody included
		$q['assujtva_value'] = !empty($opts['vat_subject']) ? 1 : '';
		$com = array_values(array_filter(array_map('intval', (array) ($opts['commercial'] ?? array()))));
		$q['commercial'] = $com ? $com : array(0);

		$keep = array('assujtva_value');

		return DOL_URL_ROOT.'/societe/card.php?action=create&'.http_build_query(array_filter($q, function ($v, $k) use ($keep) {
			return in_array($k, $keep, true) || ($v !== '' && $v !== null);
		}, ARRAY_FILTER_USE_BOTH));
	}

	/**
	 * Address of the page of creation of a contact of a thirdparty, filled
	 *
	 * @param int                  $socId   Thirdparty
	 * @param array<string,string> $contact Contact
	 * @return string
	 */
	public static function contactUrl($socId, array $contact)
	{
		$q = array('socid' => (int) $socId);
		foreach ($contact as $k => $v) {
			if ($v !== '' && (in_array($k, self::CONTACT_FIELDS, true) || strpos($k, 'options_') === 0)) {
				$q[$k] = $v;
			}
		}

		return DOL_URL_ROOT.'/contact/card.php?action=create&'.http_build_query($q);
	}

	/**
	 * After the full page of creation (trigger of the thirdparty): the contact of the panel, the mail and its
	 * conversation linked to both; a contact that needs its own page is left for the bandeau
	 *
	 * @param User                 $user    User who saved the thirdparty
	 * @param int                  $mailId  Mail of the index
	 * @param int                  $socId   Thirdparty created
	 * @param array<string,string>|null $contact Contact of the panel
	 * @return int Contact created, 0 when none or left for later
	 */
	public function afterFullPage(User $user, $mailId, $socId, $contact)
	{
		require_once DOL_DOCUMENT_ROOT.'/contact/class/contact.class.php';
		$row = $this->db->getRow("SELECT * FROM ".$this->db->prefix()."inbox_mail WHERE rowid = ".((int) $mailId));
		$contactId = 0;
		if (is_array($contact) && trim((string) ($contact['lastname'] ?? '')) !== '' && $user->hasRight('societe', 'contact', 'creer')) {
			if ($this->form($user)['blockers']['contact']) {
				$this->pending($user, (int) $mailId, (int) $socId, $contact);
			} else {
				$c = new Contact($this->db);
				$c->socid = (int) $socId;
				foreach (array('lastname', 'firstname', 'poste', 'phone_pro', 'phone_mobile', 'email') as $f) {
					// Kept in the session since the panel: cleaned again where it is written
					$c->$f = self::cleanValue($f, (string) ($contact[$f] ?? ''));
				}
				$c->statut = 1;
				$contactId = $c->create($user);
				if ($contactId <= 0) {
					dol_syslog(__METHOD__.' contact not created: '.$c->error, LOG_WARNING);
					$this->pending($user, (int) $mailId, (int) $socId, $contact);
					$contactId = 0;
				}
			}
		}
		if (is_object($row)) {
			// The thirdparty itself is linked by the intent; its conversation and the contact here
			(new InboxThreadLink($this->db))->linkThread($user, $row, 'societe', (int) $socId);
			if ($contactId > 0) {
				$this->linkMail($user, $row, 0, $contactId);
			}
			if ((string) ($contact['email'] ?? '') !== '') {
				(new InboxContactMatcher($this->db))->refreshAddress((string) $contact['email']);
			}
		}

		return $contactId;
	}

	/**
	 * A contact left to create from its page, offered in the bandeau of the mail (kept 7 days, 20 at most)
	 *
	 * @param User                 $user    User
	 * @param int                  $mailId  Mail of the index
	 * @param int                  $socId   Thirdparty
	 * @param array<string,string> $contact Contact of the panel
	 * @return void
	 */
	public function pending(User $user, $mailId, $socId, array $contact)
	{
		$params = new InboxUserParam($this->db);
		$list = json_decode((string) $params->get($user, 'lead_pending'), true);
		$list = array_values(array_filter(is_array($list) ? $list : array(), function ($x) {
			return (int) ($x['at'] ?? 0) > dol_now() - 7 * 86400;
		}));
		$list[] = array('mail' => (int) $mailId, 'soc' => (int) $socId, 'contact' => self::clean($contact, self::CONTACT_FIELDS), 'at' => dol_now());
		$params->set($user, 'lead_pending', json_encode(array_slice($list, -20)));
	}

	/**
	 * Contacts left to create for a mail, still not there
	 *
	 * @param User $user   User
	 * @param int  $mailId Mail of the index
	 * @return array<int,array{soc:int,soc_name:string,name:string,url:string}>
	 */
	public function pendingFor(User $user, $mailId)
	{
		$list = json_decode((string) (new InboxUserParam($this->db))->get($user, 'lead_pending'), true);
		$out = array();
		foreach (is_array($list) ? $list : array() as $x) {
			if ((int) ($x['mail'] ?? 0) !== (int) $mailId || (int) ($x['at'] ?? 0) < dol_now() - 7 * 86400) {
				continue;
			}
			// The thirdparty read with the rights of the user, in his entity (audit INBX-058)
			if ((int) ($x['soc'] ?? 0) <= 0 || !InboxElements::canRead($user, 'societe', (int) $x['soc'])) {
				continue;
			}
			$c = (array) ($x['contact'] ?? array());
			$p = $this->db->prefix();
			$soc = $this->db->getRow("SELECT nom FROM ".$p."societe WHERE rowid = ".((int) $x['soc']));
			$done = $this->db->getRow("SELECT rowid FROM ".$p."socpeople WHERE fk_soc = ".((int) $x['soc'])." AND (LOWER(lastname) = '".$this->db->escape(mb_strtolower((string) ($c['lastname'] ?? ''), 'UTF-8'))."'"
				.((string) ($c['email'] ?? '') !== '' ? " OR LOWER(email) = '".$this->db->escape(strtolower((string) $c['email']))."'" : "").")");
			if (!is_object($soc) || is_object($done)) {
				continue;
			}
			$out[] = array('soc' => (int) $x['soc'], 'soc_name' => (string) $soc->nom, 'name' => dolGetFirstLastname((string) ($c['firstname'] ?? ''), (string) ($c['lastname'] ?? '')),
				'url' => self::contactUrl((int) $x['soc'], $c));
		}

		return $out;
	}

	/**
	 * The user does not want this contact: no more offered
	 *
	 * @param User $user   User
	 * @param int  $mailId Mail of the index
	 * @param int  $socId  Thirdparty
	 * @return void
	 */
	public function dismiss(User $user, $mailId, $socId)
	{
		$params = new InboxUserParam($this->db);
		$list = json_decode((string) $params->get($user, 'lead_pending'), true);
		$list = array_values(array_filter(is_array($list) ? $list : array(), function ($x) use ($mailId, $socId) {
			return !((int) ($x['mail'] ?? 0) === (int) $mailId && (int) ($x['soc'] ?? 0) === (int) $socId);
		}));
		$params->set($user, 'lead_pending', json_encode($list));
	}

	/**
	 * @param string $code  Code
	 * @param string $error Message or key
	 * @return null
	 */
	protected function fail($code, $error)
	{
		$this->errorCode = $code;
		$this->error = (string) $error;

		return null;
	}
}
