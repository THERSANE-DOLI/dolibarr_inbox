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
 * \file    inbox/class/inboxcreate.class.php
 * \ingroup inbox
 * \brief   Pieces created from a mail and files of a mail put in the documents of a piece (lot W5 1.14.0)
 */

require_once __DIR__.'/inboxelements.class.php';
require_once __DIR__.'/inboxlink.class.php';
require_once __DIR__.'/inboxactionlog.class.php';
require_once __DIR__.'/inboxaccess.class.php';
require_once __DIR__.'/inboxmailref.class.php';
require_once __DIR__.'/inboxmimeparser.class.php';
require_once __DIR__.'/inboxsafe.class.php';

/**
 * « Create ▾ » opens the page of creation of Dolibarr, filled from the mail (decided 2026-11-07: all the fields,
 * extrafields and rules of Dolibarr stay). inbox keeps an intent (type, mail, the .eml to join when asked) in the
 * session of the user for two hours, never where the browser could change it; the trigger of the creation in this
 * session links the new piece to the mail and puts the .eml in its documents. A file of a mail goes in the documents of a piece (decided 2026-11-07), after the antivirus.
 */
class InboxCreate
{
	/** Intents kept (s) */
	const INTENT_TTL = 7200;

	/**
	 * Pieces that can be created from a mail: page of creation, trigger of the creation, label of the menu
	 */
	const TYPES = array(
		'societe' => array('url' => '/societe/card.php?action=create', 'trigger' => 'COMPANY_CREATE', 'label' => 'InboxCreateThirdparty'),
		'contact' => array('url' => '/contact/card.php?action=create', 'trigger' => 'CONTACT_CREATE', 'label' => 'InboxCreateContact'),
		'propal' => array('url' => '/comm/propal/card.php?action=create', 'trigger' => 'PROPAL_CREATE', 'label' => 'InboxCreateProposal'),
		'commande' => array('url' => '/commande/card.php?action=create', 'trigger' => 'ORDER_CREATE', 'label' => 'InboxCreateOrder'),
		'supplier_proposal' => array('url' => '/supplier_proposal/card.php?action=create', 'trigger' => 'SUPPLIER_PROPOSAL_CREATE', 'label' => 'InboxCreateSupplierProposal'),
		'order_supplier' => array('url' => '/fourn/commande/card.php?action=create', 'trigger' => 'ORDER_SUPPLIER_CREATE', 'label' => 'InboxCreateSupplierOrder'),
		'action' => array('url' => '/comm/action/card.php?action=create', 'trigger' => 'ACTION_CREATE', 'label' => 'InboxCreateEvent'),
		'ticket' => array('url' => '/ticket/card.php?action=create', 'trigger' => 'TICKET_CREATE', 'label' => 'InboxCreateTicket'),
		'project' => array('url' => '/projet/card.php?action=create', 'trigger' => 'PROJECT_CREATE', 'label' => 'InboxCreateProject'),
	);

	/** Pieces whose page of creation takes the default contact (contactid) */
	const CONTACT_IN_FORM = array('propal', 'commande', 'ticket');

	/** Pieces whose page of creation has no contact: the contact of the sender added after the creation, with this type */
	const CONTACT_AFTER = array('order_supplier' => 'CUSTOMER');

	/** @var DoliDB */
	protected $db;

	/** @var string */
	public $error = '';

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct(DoliDB $db)
	{
		$this->db = $db;
	}

	/**
	 * Types the user may create from a mail
	 *
	 * @param User $user User
	 * @return array<int,array{type:string,label:string,icon:string}>
	 */
	public static function creatable(User $user)
	{
		global $langs;

		$out = array();
		foreach (self::TYPES as $type => $t) {
			if (InboxElements::canWriteType($user, $type)) {
				$out[] = array('type' => $type, 'label' => $langs->transnoentities($t['label']), 'icon' => InboxElements::TYPES[$type]['icon'] ?? 'plus');
			}
		}

		return $out;
	}

	/**
	 * Keep the intent and give the page of creation, filled from the mail
	 *
	 * @param User        $user   User
	 * @param InboxReader $reader Mail loaded (access checked)
	 * @param string      $type   Type to create
	 * @param bool        $attach Put the .eml in the documents of the piece
	 * @param array{soc:int,contact:int} $known Thirdparty and contact recognized
	 * @return string Address of the page, '' if refused
	 */
	public function intent(User $user, InboxReader $reader, $type, $attach, array $known)
	{
		if (!isset(self::TYPES[$type]) || !InboxElements::canWriteType($user, $type)) {
			$this->error = 'InboxErrorCannotCreate';
			return '';
		}
		$row = $reader->row;
		$refs = new InboxMailRef($this->db);
		$refId = $refs->fetchOrCreate($row, $user);
		if ($refId <= 0) {
			$this->error = $refs->error ?: 'InboxErrorNoMessageId';
			return '';
		}
		$file = '';
		if ($attach) {
			$raw = $reader->raw($user);
			if ($raw === null) {
				$this->error = $reader->error;
				return '';
			}
			$file = $this->tempFile($raw);
		}
		$intents = $this->intents($user);
		$contact = (int) ($known['contact'] ?? 0);
		$contactAfter = isset(self::CONTACT_AFTER[$type]) && $this->isContactOf($contact, (int) ($known['soc'] ?? 0)) ? $contact : 0;
		$intents[] = array('type' => $type, 'mailref' => $refId, 'mail' => (int) $row->rowid, 'subject' => (string) $row->subject, 'file' => $file, 'at' => dol_now(),
			'contactid' => $contactAfter);
		$this->saveIntents($user, array_slice($intents, -10));

		return $this->createUrl($type, $row, $known);
	}

	/**
	 * Full page of creation of a thirdparty from the panel « New customer » (lot A1, 1.79.0): the intent keeps the
	 * contact of the panel, created when the thirdparty is saved
	 *
	 * @param User                      $user    User
	 * @param InboxReader               $reader  Mail loaded (access checked)
	 * @param string                    $url     Page of creation, filled
	 * @param array<string,string>|null $contact Contact of the panel
	 * @return string Address of the page, '' if refused
	 */
	public function leadIntent(User $user, InboxReader $reader, $url, $contact)
	{
		if (!InboxElements::canWriteType($user, 'societe')) {
			$this->error = 'InboxErrorCannotCreate';
			return '';
		}
		$refId = (new InboxMailRef($this->db))->fetchOrCreate($reader->row, $user);
		if ($refId <= 0) {
			$this->error = 'InboxErrorNoMessageId';
			return '';
		}
		$intents = $this->intents($user);
		$intents[] = array('type' => 'societe', 'mailref' => $refId, 'mail' => (int) $reader->row->rowid, 'subject' => (string) $reader->row->subject, 'file' => '', 'at' => dol_now(),
			'lead' => 1, 'contact' => is_array($contact) ? $contact : null);
		$this->saveIntents($user, array_slice($intents, -10));

		return $url;
	}

	/**
	 * @param int $contactId Contact
	 * @param int $socId     Thirdparty
	 * @return bool Active contact of this thirdparty
	 */
	protected function isContactOf($contactId, $socId)
	{
		if ((int) $contactId <= 0 || (int) $socId <= 0) {
			return false;
		}
		$row = $this->db->getRow("SELECT fk_soc FROM ".$this->db->prefix()."socpeople WHERE rowid = ".((int) $contactId)." AND statut = 1 AND entity IN (".getEntity('contact').")");

		return is_object($row) && (int) $row->fk_soc === (int) $socId;
	}

	/**
	 * Address of the page of creation, filled from the mail
	 *
	 * @param string                     $type  Type
	 * @param object                     $row   Mail of the index
	 * @param array{soc:int,contact:int} $known Thirdparty and contact recognized
	 * @return string
	 */
	public function createUrl($type, $row, array $known)
	{
		$q = array();
		$email = strtolower((string) $row->from_email);
		$name = trim((string) $row->from_name);
		$subject = dol_trunc((string) $row->subject, 200, 'right', 'UTF-8', 1);
		$soc = (int) ($known['soc'] ?? 0);
		// Mail sent by a site for a requester (data sources, 1.72.0): the requester and what the site said of him,
		// nothing to read in the text
		$data = array();
		if ((string) ($row->requester_email ?? '') !== '') {
			$email = strtolower((string) $row->requester_email);
			$name = trim((string) $row->requester_name);
			$data = json_decode((string) ($row->source_data ?? ''), true);
			$data = is_array($data) ? array_map('strval', $data) : array();
		}
		$person = trim(($data['firstname'] ?? '').' '.($data['lastname'] ?? ''));
		switch ($type) {
			case 'societe':
				$q['name'] = ($data['company'] ?? '') !== '' ? $data['company'] : ($person !== '' ? $person : ($name !== '' ? $name : substr($email, (int) strpos($email, '@') + 1)));
				$q['email'] = $email;
				$map = array('phone' => 'phone', 'siren' => 'idprof1', 'vat' => 'tva_intra', 'address' => 'address', 'zip' => 'zipcode', 'town' => 'town');
				foreach ($map as $field => $param) {
					if (($data[$field] ?? '') !== '') {
						$q[$param] = $data[$field];
					}
				}
				break;
			case 'contact':
				if (($data['lastname'] ?? '') !== '') {
					$q['firstname'] = $data['firstname'] ?? '';
					$q['lastname'] = $data['lastname'];
				} else {
					$parts = preg_split('/\s+/', $name, 2);
					$q['firstname'] = count($parts) > 1 ? $parts[0] : '';
					$q['lastname'] = count($parts) > 1 ? $parts[1] : ($name !== '' ? $name : $email);
				}
				$q['email'] = $email;
				if (($data['phone'] ?? '') !== '') {
					$q['phone_pro'] = $data['phone'];
				}
				break;
			case 'action':
				$q['label'] = $subject;
				if (!empty($known['contact'])) {
					$q['contactid'] = (int) $known['contact'];
				}
				break;
			case 'ticket':
				$q['subject'] = $subject;
				break;
			case 'project':
				$q['title'] = $subject;
				break;
		}
		if ($soc > 0) {
			$q['socid'] = $soc;
			// Contact of the sender as default contact of the piece, only when he belongs to its thirdparty
			$contact = (int) ($known['contact'] ?? 0);
			if (in_array($type, self::CONTACT_IN_FORM, true) && $this->isContactOf($contact, $soc)) {
				$q['contactid'] = $contact;
			}
		}

		// Values of a mail in the address of a page of the core: the one door (lot S2), not only the filter of that page
		foreach ($q as $k => $v) {
			if (!is_int($v)) {
				$q[$k] = $k === 'email' ? InboxSafe::line($v, 128) : InboxSafe::line($v, 255);
			}
		}

		return DOL_URL_ROOT.self::TYPES[$type]['url'].($q ? '&'.http_build_query($q) : '');
	}

	/**
	 * Trigger of a creation: the last intent of this user for this type links the new piece to its mail (and puts the
	 * .eml in its documents when asked)
	 *
	 * @param User   $user   User who created
	 * @param string $code   Trigger
	 * @param object $object Piece created
	 * @return int 1 when an intent was used, 0 otherwise
	 */
	public function onCreate(User $user, $code, $object)
	{
		$type = '';
		foreach (self::TYPES as $t => $def) {
			if ($def['trigger'] === $code) {
				$type = $t;
			}
		}
		if ($type === '' || empty($object->id)) {
			return 0;
		}
		$intents = $this->intents($user);
		$found = null;
		foreach (array_reverse(array_keys($intents)) as $k) {
			if ($intents[$k]['type'] === $type) {
				$found = $intents[$k];
				unset($intents[$k]);
				break;
			}
		}
		if (!$found) {
			return 0;
		}
		$this->saveIntents($user, array_values($intents));
		$linker = new InboxLink($this->db);
		$linker->link((int) $found['mailref'], $type, (int) $object->id, $user, 'inbox');
		// Contact of the sender, for the pieces whose page of creation has no contact (still of the thirdparty chosen)
		$contact = (int) ($found['contactid'] ?? 0);
		if ($contact > 0 && isset(self::CONTACT_AFTER[$type]) && method_exists($object, 'add_contact') && $this->isContactOf($contact, (int) ($object->socid ?? 0))) {
			if ($object->add_contact($contact, self::CONTACT_AFTER[$type], 'external') < 0) {
				dol_syslog(__METHOD__.' contact '.$contact.' not added to '.$type.' '.$object->id.': '.$object->error, LOG_WARNING);
			}
		}
		// From the panel « New customer » (A1): its contact, the conversation
		if (!empty($found['lead'])) {
			require_once __DIR__.'/inboxlead.class.php';
			(new InboxLead($this->db))->afterFullPage($user, (int) $found['mail'], (int) $object->id, $found['contact'] ?? null);
		}
		if ($this->ownTempFile((string) $found['file'])) {
			$name = dol_sanitizeFileName(dol_trunc('mail '.dol_print_date(dol_now(), '%Y-%m-%d').' '.($found['subject'] ?: 'mail'), 120, 'right', 'UTF-8', 1)).'.eml';
			$this->putFile($type, $object, $name, (string) file_get_contents($found['file']));
			@unlink($found['file']);
		}

		return 1;
	}

	/**
	 * Put a file in the documents of a piece (the user may change it); a name already there gets a number
	 *
	 * @param User   $user    User
	 * @param string $type    Type
	 * @param int    $id      Piece
	 * @param string $name    File name
	 * @param string $content Content
	 * @return string Name written, '' if refused
	 */
	public function attach(User $user, $type, $id, $name, $content)
	{
		if (!InboxElements::canWriteType($user, $type) || !InboxElements::canRead($user, $type, $id)) {
			$this->error = 'InboxErrorCannotAttach';
			return '';
		}
		$object = fetchObjectByElement((int) $id, $type);
		if (!is_object($object) || empty($object->id)) {
			$this->error = 'InboxErrorPieceNotReadable';
			return '';
		}

		return $this->putFile($type, $object, $name, $content);
	}

	/**
	 * A file of a mail put in the documents of a piece, after the antivirus; the piece is linked to the mail too. Used by
	 * the panel of the mail and by the modules that offer the files of the mails linked to a piece (public API).
	 *
	 * @param User        $user    User
	 * @param InboxReader $reader  Mail loaded (access checked by InboxReader::load)
	 * @param int         $part    Index of the attachment in the parsed mail
	 * @param string      $type    Type of the piece
	 * @param int         $element Piece
	 * @return array{name:string,unscanned:bool}|null Null if refused ($this->error: a key of the langs of inbox)
	 */
	public function attachFromMail(User $user, InboxReader $reader, $part, $type, $element)
	{
		$raw = $reader->raw($user);
		if ($raw === null) {
			$this->error = 'InboxMailNotFound';
			return null;
		}
		$message = InboxMimeParser::parse($raw);
		$a = $message->attachments[(int) $part] ?? null;
		if (!is_array($a) || !empty($a['inline']) || !empty($a['embedded'])) {
			$this->error = 'InboxErrorAttachmentNotFound';
			return null;
		}
		require_once __DIR__.'/inboxattachmentscan.class.php';
		$verdict = (new InboxAttachmentScan($this->db))->check($a['content'], $a['name']);
		if ($verdict['state'] === InboxAttachmentScan::INFECTED) {
			$this->error = 'InboxErrorAttachmentInfected';
			return null;
		}
		$name = $this->attach($user, (string) $type, (int) $element, (string) $a['name'], (string) $a['content']);
		if ($name === '') {
			return null;
		}
		// The piece is linked to the mail too (a file of it is there now)
		$refId = (new InboxMailRef($this->db))->fetchOrCreate($reader->row, $user);
		if ($refId > 0) {
			(new InboxLink($this->db))->link($refId, (string) $type, (int) $element, $user);
		}
		InboxActionLog::add($this->db, $user, (int) $reader->row->fk_account, 'attach', array($reader->row), (string) $reader->row->folder_path, '', 0, $name);

		return array('name' => $name, 'unscanned' => $verdict['state'] !== InboxAttachmentScan::CLEAN && $verdict['state'] !== InboxAttachmentScan::OFF);
	}

	/**
	 * Files attached to the mails linked to a piece, in the mailboxes the user can read, newest mail first (public API:
	 * a module offers them to be put in the documents of the piece with attachFromMail). Mails read from the cache of
	 * inbox, else from their server: call it on demand, not on every display.
	 *
	 * @param User     $user       User
	 * @param string   $type       Type of the piece
	 * @param int      $element    Piece
	 * @param string[] $extensions Extensions kept (lower case), empty = all
	 * @param int      $maxMails   Mails read at most
	 * @return array<int,array{mail:int,part:int,name:string,size:int,sha1:string,from:string,subject:string,date:int}>
	 */
	public function mailFiles(User $user, $type, $element, array $extensions = array(), $maxMails = 10)
	{
		require_once __DIR__.'/inboxreader.class.php';
		$out = array();
		if (!InboxElements::canRead($user, (string) $type, (int) $element)) {
			return $out;
		}
		$accounts = array_map('intval', array_keys((new InboxAccess($this->db))->getAccounts($user)));
		$ids = (new InboxLink($this->db))->mailrefsOf((string) $type, (int) $element);
		if (empty($accounts) || empty($ids)) {
			return $out;
		}
		$rows = $this->db->getRows("SELECT rowid FROM ".$this->db->prefix()."inbox_mailref WHERE rowid IN (".implode(',', array_map('intval', $ids)).")"
			." ORDER BY date_mail DESC LIMIT ".((int) $maxMails));
		$refs = new InboxMailRef($this->db);
		foreach (is_array($rows) ? $rows : array() as $r) {
			$copy = $refs->locate((int) $r->rowid, $accounts);
			$reader = new InboxReader($this->db);
			if ($copy === null || !$reader->load($user, (int) $copy['mail'])) {
				continue;
			}
			$raw = $reader->raw($user);
			if ($raw === null) {
				continue;
			}
			foreach (InboxMimeParser::parse($raw)->attachments as $part => $a) {
				$ext = strtolower(pathinfo((string) $a['name'], PATHINFO_EXTENSION));
				if (!empty($a['inline']) || !empty($a['embedded']) || ($extensions && !in_array($ext, $extensions, true))) {
					continue;
				}
				$out[] = array('mail' => (int) $copy['mail'], 'part' => (int) $part, 'name' => (string) $a['name'], 'size' => (int) $a['size'], 'sha1' => (string) $a['sha1'],
					'from' => strtolower((string) $reader->row->from_email), 'subject' => (string) $reader->row->subject, 'date' => (int) $this->db->jdate($reader->row->date_mail));
			}
		}

		return $out;
	}

	/**
	 * @param string $type    Type
	 * @param object $object  Piece
	 * @param string $name    File name
	 * @param string $content Content
	 * @return string Name written, '' if KO
	 */
	protected function putFile($type, $object, $name, $content)
	{
		$dir = $this->documentsDir($type, $object);
		if ($dir === '' || dol_mkdir($dir) < 0) {
			$this->error = 'InboxErrorCannotAttach';
			return '';
		}
		// The one door first (no tag, no control nor bidi override character: « facture\u{202E}fdp.exe »), then Dolibarr
		$name = dol_sanitizeFileName(InboxSafe::line((string) $name, 200)) ?: 'file';
		// An executable file of a mail (.php, .phtml...) gets .noexe, as an upload of Dolibarr (dol_move_uploaded_file)
		if (isAFileWithExecutableContent($name) && !getDolGlobalString('MAIN_DOCUMENT_IS_OUTSIDE_WEBROOT_SO_NOEXE_NOT_REQUIRED')) {
			$name .= '.noexe';
		}
		$base = pathinfo($name, PATHINFO_FILENAME);
		$ext = pathinfo($name, PATHINFO_EXTENSION);
		for ($i = 2; file_exists($dir.'/'.$name) && $i < 100; $i++) {
			$name = $base.' ('.$i.')'.($ext !== '' ? '.'.$ext : '');
		}
		if (file_put_contents($dir.'/'.$name, $content) === false) {
			$this->error = 'InboxErrorCannotAttach';
			return '';
		}
		dolChmod($dir.'/'.$name);
		// Indexed like a file uploaded on the piece: listed by the ECM, and the modules that read the files given to a
		// piece hear of it (trigger ECMFILES_CREATE) at once, not at the next display of its documents
		require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
		if (addFileIntoDatabaseIndex($dir, $name, '', 'uploaded', 0, $object) <= 0) {
			dol_syslog(__METHOD__.' file '.$name.' written but not indexed', LOG_WARNING);
		}

		return $name;
	}

	/**
	 * Folder of the documents of a piece, as its tab Documents reads it
	 *
	 * @param string $type   Type
	 * @param object $object Piece
	 * @return string
	 */
	public function documentsDir($type, $object)
	{
		global $conf;

		$entity = (int) ($object->entity ?? 0) ?: (int) $conf->entity;
		switch ($type) {
			case 'societe':
				return $conf->societe->multidir_output[$entity].'/'.((int) $object->id);
			case 'contact':
				return $conf->societe->multidir_output[$entity].'/contact/'.((int) $object->id);
			case 'action':
				return $conf->agenda->dir_output.'/'.((int) $object->id);
		}
		$out = getMultidirOutput($object);

		return $out ? $out.'/'.dol_sanitizeFileName((string) $object->ref) : '';
	}

	/**
	 * Intents of the session, still valid for this user (the old ones and their files removed)
	 *
	 * @param User $user User
	 * @return array<int,array<string,mixed>>
	 */
	protected function intents(User $user)
	{
		$list = $_SESSION['inbox_create_intents'] ?? array();
		$out = array();
		foreach (is_array($list) ? $list : array() as $i) {
			if (is_array($i) && (int) ($i['user'] ?? 0) === (int) $user->id && (int) ($i['at'] ?? 0) > dol_now() - self::INTENT_TTL) {
				$out[] = $i;
			} elseif (is_array($i) && $this->ownTempFile((string) ($i['file'] ?? ''))) {
				@unlink((string) $i['file']);
			}
		}

		return $out;
	}

	/**
	 * @param User                           $user    User
	 * @param array<int,array<string,mixed>> $intents Intents
	 * @return void
	 */
	protected function saveIntents(User $user, array $intents)
	{
		foreach ($intents as $k => $i) {
			$intents[$k]['user'] = (int) $user->id;
		}
		$_SESSION['inbox_create_intents'] = array_values($intents);
	}

	/**
	 * A file inbox wrote for an intent (never any other file of the server is read or removed)
	 *
	 * @param string $file Path
	 * @return bool
	 */
	protected function ownTempFile($file)
	{
		return $file !== '' && preg_match('/^intent-[0-9a-f]{16}\.eml$/', basename($file)) && is_file($file);
	}

	/**
	 * The .eml kept aside until the piece is created
	 *
	 * @param string $raw Raw mail
	 * @return string Path, '' if KO
	 */
	protected function tempFile($raw)
	{
		global $conf;

		$dir = $conf->inbox->dir_temp ?? (DOL_DATA_ROOT.'/inbox/temp');
		dol_mkdir($dir);
		if (!is_writable($dir)) {
			$dir = sys_get_temp_dir();
		}
		$file = $dir.'/intent-'.bin2hex(random_bytes(8)).'.eml';

		return file_put_contents($file, $raw) === false ? '' : $file;
	}
}
