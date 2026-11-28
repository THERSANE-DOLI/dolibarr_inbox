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
 * \file    inbox/class/inboxtemplates.class.php
 * \ingroup inbox
 * \brief   Mail templates in the writing (1.18.0, analyse-webmail.md §5.5 bis)
 *
 * The templates are the ones of Dolibarr (llx_c_email_templates): the type inbox_send, offered in the mailboxes it is
 * attached to (llx_inbox_template_account, none = every mailbox), and the types of the pieces linked to the mail
 * answered (propal_send for a proposal...). Their variables (__KEY__) are those of Dolibarr, computed for the piece
 * and for the first recipient, plus a few of inbox. In the editor a variable is a chip (span data-inbox-var) whose
 * value follows the recipient; the mail sent holds its text only.
 */

require_once DOL_DOCUMENT_ROOT.'/core/lib/functions2.lib.php';
require_once __DIR__.'/inboxelements.class.php';
require_once __DIR__.'/inboxmailbuilder.class.php';
require_once __DIR__.'/inboxmimeparser.class.php';
require_once __DIR__.'/inboxaccess.class.php';

/**
 * Mail templates of inbox and of the linked pieces
 */
class InboxTemplates
{
	/** Type of the templates of inbox */
	const TYPE = 'inbox_send';

	/** Templates of Dolibarr offered for a linked piece: type of inbox => type of template of its card */
	const PIECE_TYPES = array(
		'propal' => 'propal_send', 'commande' => 'order_send', 'facture' => 'facture_send', 'order_supplier' => 'order_supplier_send',
		'invoice_supplier' => 'invoice_supplier_send', 'supplier_proposal' => 'supplier_proposal_send', 'shipping' => 'shipping_send',
		'fichinter' => 'fichinter_send', 'ticket' => 'ticket_send', 'project' => 'project', 'societe' => 'thirdparty', 'contact' => 'contact',
	);

	/** Variables never offered: a tracking picture */
	const DROP = array('CHECK_READ');

	/** Variables of inbox (help of the editor of a template) */
	const OWN = array('INBOX_SUBJECT', 'INBOX_DATE', 'INBOX_FROM', 'INBOX_MAILBOX', 'INBOX_IDENTITY', 'CONTACT_FIRSTNAME', 'CONTACT_LASTNAME', 'CONTACT_NAME', 'CONTACTCIVNAME',
		'INBOX_IDENTITY_NAME', 'INBOX_IDENTITY_EMAIL', 'INBOX_IDENTITY_REPLYTO', 'INBOX_MAILBOX_EMAIL', 'INBOX_MAILBOX_LABEL');

	/** Variables of the identity, the only ones of inbox that a signature can hold (1.53.0) */
	const IDENTITY_KEYS = array('INBOX_IDENTITY_NAME', 'INBOX_IDENTITY_EMAIL', 'INBOX_IDENTITY_REPLYTO', 'INBOX_MAILBOX_EMAIL', 'INBOX_MAILBOX_LABEL');

	/** @var DoliDB */
	protected $db;

	/** @var string */
	public $error = '';

	/** @var string Code for the browser: not_found, no_right, bad_label */
	public $errorCode = '';

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct(DoliDB $db)
	{
		$this->db = $db;
	}

	/**
	 * @param string $code   Code
	 * @param string $detail Detail for the log
	 * @return null
	 */
	protected function fail($code, $detail = '')
	{
		$this->errorCode = $code;
		$this->error = $detail !== '' ? $detail : $code;

		return null;
	}

	/**
	 * Visible to the user: shared, or private and his
	 *
	 * @param User $user User
	 * @return string SQL
	 */
	protected function visibleSql(User $user)
	{
		return " AND t.entity IN (".getEntity('c_email_templates').") AND (t.private = 0 OR t.fk_user = ".((int) $user->id).")";
	}

	/**
	 * Templates offered in a writing: the ones of inbox for the mailbox of the sending, then those of the pieces linked
	 * to the mail answered, grouped by type
	 *
	 * @param User                           $user      User
	 * @param int                            $accountId Mailbox of the sending
	 * @param array<int,array<string,mixed>> $linked    Readable pieces linked to the mail answered (type, id, ref, kind)
	 * @return array<int,array<string,mixed>> Groups: type, kind, templates (id, label, lang, private), pieces
	 */
	public function offered(User $user, $accountId, array $linked)
	{
		global $langs;

		$p = $this->db->prefix();
		$groups = array();
		$rows = $this->db->getRows("SELECT t.rowid, t.label, t.lang, t.private FROM ".$p."c_email_templates as t WHERE t.type_template = '".self::TYPE."' AND t.active = 1".$this->visibleSql($user)
			." AND (NOT EXISTS (SELECT 1 FROM ".$p."inbox_template_account as ta WHERE ta.fk_template = t.rowid)"
			." OR EXISTS (SELECT 1 FROM ".$p."inbox_template_account as ta2 WHERE ta2.fk_template = t.rowid AND ta2.fk_account = ".((int) $accountId)."))"
			." ORDER BY t.private, t.position, t.label LIMIT 200");
		$whole = $this->wholeOf(array_map(function ($r) {
			return (int) $r->rowid;
		}, is_array($rows) ? $rows : array()));
		$groups[] = array('type' => self::TYPE, 'kind' => $langs->transnoentities('InboxTemplatesOfMailbox'), 'templates' => self::rowsView($rows, $whole), 'pieces' => array_values($linked));
		// Templates of Dolibarr for every type (1.26.0): offered in every writing, with the variables of a linked piece
		$rows = $this->db->getRows("SELECT t.rowid, t.label, t.lang, t.private, t.module FROM ".$p."c_email_templates as t WHERE t.type_template = 'all'"
			." AND t.active = 1".$this->visibleSql($user)." ORDER BY t.position, t.lang, t.label LIMIT 200");
		$rows = array_filter(is_array($rows) ? $rows : array(), function ($r) {
			return empty($r->module) || isModEnabled((string) $r->module);
		});
		if ($rows) {
			$groups[] = array('type' => 'all', 'kind' => $langs->transnoentities('InboxTemplatesAllTypes'), 'templates' => self::rowsView($rows), 'pieces' => array_values($linked));
		}
		$byType = array();
		foreach ($linked as $pc) {
			if (isset(self::PIECE_TYPES[$pc['type']])) {
				$byType[$pc['type']][] = $pc;
			}
		}
		foreach ($byType as $type => $pieces) {
			$rows = $this->db->getRows("SELECT t.rowid, t.label, t.lang, t.private, t.module FROM ".$p."c_email_templates as t WHERE t.type_template = '".$this->db->escape(self::PIECE_TYPES[$type])."'"
				." AND t.active = 1".$this->visibleSql($user)." ORDER BY t.position, t.lang, t.label LIMIT 200");
			$rows = array_filter(is_array($rows) ? $rows : array(), function ($r) {
				return empty($r->module) || isModEnabled((string) $r->module);
			});
			if ($rows) {
				$groups[] = array('type' => $type, 'kind' => (string) $pieces[0]['kind'], 'templates' => self::rowsView($rows), 'pieces' => $pieces);
			}
		}

		return $groups;
	}

	/**
	 * @param mixed         $rows  Rows
	 * @param array<int,int>|null $whole Complete mail by template (wholeOf()), null: every one is a complete mail (the
	 *                                   templates of Dolibarr for a piece take the whole text, as on the card of the piece)
	 * @return array<int,array{id:int,label:string,lang:string,private:int,whole:int}>
	 */
	protected static function rowsView($rows, $whole = null)
	{
		$out = array();
		foreach (is_array($rows) ? $rows : array() as $r) {
			$out[] = array('id' => (int) $r->rowid, 'label' => (string) $r->label, 'lang' => (string) $r->lang, 'private' => (int) $r->private,
				'whole' => $whole === null ? 1 : (int) ($whole[(int) $r->rowid] ?? 0));
		}

		return $out;
	}

	/**
	 * Templates of inbox that are a complete mail (1.118.0): it takes the place of the text, a piece of text goes at the
	 * cursor. No row = a piece of text (the templates written before 1.118.0, those written in Dolibarr)
	 *
	 * @param int[] $ids Templates
	 * @return array<int,int> Template => 1 complete mail, 0 piece of text
	 */
	public function wholeOf(array $ids)
	{
		$ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
		$out = array_fill_keys($ids, 0);
		if (!$ids) {
			return $out;
		}
		$rows = $this->db->getRows("SELECT fk_template, whole FROM ".$this->db->prefix()."inbox_template_option WHERE fk_template IN (".implode(',', $ids).") LIMIT ".(count($ids) + 1));
		foreach (is_array($rows) ? $rows : array() as $r) {
			$out[(int) $r->fk_template] = (int) $r->whole ? 1 : 0;
		}

		return $out;
	}

	/**
	 * @param int $id    Template
	 * @param int $whole 1 complete mail, 0 piece of text
	 * @return bool
	 */
	protected function setWhole($id, $whole)
	{
		$p = $this->db->prefix();
		$row = $this->db->getRow("SELECT rowid FROM ".$p."inbox_template_option WHERE fk_template = ".((int) $id));
		if (is_object($row)) {
			return (bool) $this->db->query("UPDATE ".$p."inbox_template_option SET whole = ".($whole ? 1 : 0)." WHERE rowid = ".((int) $row->rowid));
		}

		return (bool) $this->db->query("INSERT INTO ".$p."inbox_template_option (fk_template, whole) VALUES (".((int) $id).", ".($whole ? 1 : 0).")");
	}

	/**
	 * A template the user may use in this writing: visible, active, and of inbox (offered in the mailbox) or of the type
	 * of the piece given
	 *
	 * @param User   $user      User
	 * @param int    $id        Template
	 * @param int    $accountId Mailbox of the sending
	 * @param string $pieceType Type of the piece whose variables are used ('' none)
	 * @return object|null
	 */
	public function usable(User $user, $id, $accountId, $pieceType)
	{
		$p = $this->db->prefix();
		$t = $this->db->getRow("SELECT t.rowid, t.label, t.type_template, t.topic, t.content, t.lang, t.joinfiles, t.module FROM ".$p."c_email_templates as t WHERE t.rowid = ".((int) $id)
			." AND t.active = 1".$this->visibleSql($user));
		if (!is_object($t)) {
			return null;
		}
		if ((string) $t->type_template === self::TYPE) {
			$boxes = $this->accountsOf((int) $t->rowid);
			return !$boxes || in_array((int) $accountId, $boxes, true) ? $t : null;
		}
		if (!empty($t->module) && !isModEnabled((string) $t->module)) {
			return null;
		}
		// For every type: usable with or without a piece (1.26.0)
		if ((string) $t->type_template === 'all') {
			return $t;
		}

		return $pieceType !== '' && (self::PIECE_TYPES[$pieceType] ?? '') === (string) $t->type_template ? $t : null;
	}

	/**
	 * Mailboxes a template of inbox is attached to (none: every mailbox)
	 *
	 * @param int $templateId Template
	 * @return int[]
	 */
	public function accountsOf($templateId)
	{
		$rows = $this->db->getRows("SELECT fk_account FROM ".$this->db->prefix()."inbox_template_account WHERE fk_template = ".((int) $templateId)." LIMIT 1000");

		return array_map(function ($r) {
			return (int) $r->fk_account;
		}, is_array($rows) ? $rows : array());
	}

	/**
	 * Values of the variables for a writing: the piece (its thirdparty, its contacts), the first recipient (contact,
	 * thirdparty of the same address or domain), the user, the company, and the mail answered
	 *
	 * @param User                $user User
	 * @param array<string,mixed> $ctx  piece (type, id), to (address), identity (name, email, signature), mailbox, source (mail answered), lang
	 * @return array<string,string> KEY (without its underscores) => text or HTML
	 */
	public function values(User $user, array $ctx)
	{
		global $langs;

		$outputlangs = $langs;
		if (!empty($ctx['lang']) && $ctx['lang'] !== $langs->defaultlang) {
			$outputlangs = new Translate('', $GLOBALS['conf']);
			$outputlangs->setDefaultLang((string) $ctx['lang']);
			$outputlangs->loadLangs(array('main', 'bills', 'propal', 'orders', 'companies', 'inbox@inbox'));
		}
		$object = null;
		if (!empty($ctx['piece']['type']) && !empty($ctx['piece']['id']) && InboxElements::canRead($user, (string) $ctx['piece']['type'], (int) $ctx['piece']['id'])) {
			$object = fetchObjectByElement((int) $ctx['piece']['id'], (string) $ctx['piece']['type']);
			if (!is_object($object) || empty($object->id)) {
				$object = null;
			} elseif (method_exists($object, 'fetch_thirdparty') && empty($object->thirdparty)) {
				$object->fetch_thirdparty();
			}
		}
		// The first recipient: its contact, else the thirdparty of its address or of its domain
		$contact = null;
		$soc = null;
		$to = strtolower(trim((string) ($ctx['to'] ?? '')));
		if ($to !== '' && isValidEmail($to)) {
			require_once __DIR__.'/inboxcontactmatcher.class.php';
			$matcher = new InboxContactMatcher($this->db);
			$m = $matcher->match($to);
			if ($m['fk_socpeople'] > 0 && $user->hasRight('societe', 'contact', 'lire')) {
				require_once DOL_DOCUMENT_ROOT.'/contact/class/contact.class.php';
				$contact = new Contact($this->db);
				if ($contact->fetch($m['fk_socpeople']) <= 0) {
					$contact = null;
				}
			}
			$socId = $m['fk_soc'] > 0 ? $m['fk_soc'] : $matcher->matchDomain($to);
			if ($socId > 0 && $user->hasRight('societe', 'lire')) {
				require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
				$soc = new Societe($this->db);
				if ($soc->fetch($socId) <= 0) {
					$soc = null;
				}
			}
		}
		$base = $object ?: $soc;
		$arr = getCommonSubstitutionArray($outputlangs, 0, null, $base);
		// The identity before the other modules: a substitution of another module (a signature made in a module of
		// templates) that holds __INBOX_IDENTITY_NAME__ gets it
		foreach (self::identityValues((array) ($ctx['identity'] ?? array())) as $k => $v) {
			$arr['__'.$k.'__'] = $v;
		}
		complete_substitutions_array($arr, $outputlangs, $base, array('mode' => 'formemail'));
		// Contacts of the piece, as its card gives them (__CONTACT_NAME_BILLING__...)
		if ($object && method_exists($object, 'liste_contact')) {
			require_once DOL_DOCUMENT_ROOT.'/contact/class/contact.class.php';
			$list = $object->liste_contact(-1, 'external', 0, '', 1);
			foreach (is_array($list) ? array_slice($list, 0, 20) : array() as $c) {
				$cs = new Contact($this->db);
				if ($cs->fetch((int) $c['id']) > 0) {
					$arr['__CONTACT_NAME_'.$c['code'].'__'] = $cs->getFullName($outputlangs, 1);
					$arr['__CONTACT_LASTNAME_'.$c['code'].'__'] = (string) $cs->lastname;
					$arr['__CONTACT_FIRSTNAME_'.$c['code'].'__'] = (string) $cs->firstname;
					$arr['__CONTACT_TITLE_'.$c['code'].'__'] = (string) $cs->getCivilityLabel();
				}
			}
		}
		$arr['__CONTACT_FIRSTNAME__'] = $contact ? (string) $contact->firstname : '';
		$arr['__CONTACT_LASTNAME__'] = $contact ? (string) $contact->lastname : '';
		$arr['__CONTACT_NAME__'] = $contact ? trim($contact->getFullName($outputlangs)) : '';
		$arr['__CONTACTCIVNAME__'] = $contact ? trim($contact->getFullName($outputlangs, 1)) : '';
		$arr['__SENDEREMAIL_SIGNATURE__'] = (string) ($ctx['identity']['signature'] ?? '');
		// Mail answered
		$source = (array) ($ctx['source'] ?? array());
		$row = null;
		if (!empty($source['mail'])) {
			$row = $this->db->getRow("SELECT subject, date_mail, from_email, from_name FROM ".$this->db->prefix()."inbox_mail WHERE rowid = ".((int) $source['mail']));
		}
		$arr['__INBOX_SUBJECT__'] = is_object($row) ? (string) $row->subject : '';
		$arr['__INBOX_DATE__'] = is_object($row) && $row->date_mail ? dol_print_date($this->db->jdate($row->date_mail), 'dayhour', 'tzuserrel', $outputlangs) : '';
		$arr['__INBOX_FROM__'] = is_object($row) ? ((string) $row->from_name !== '' ? (string) $row->from_name : (string) $row->from_email) : '';
		$arr['__INBOX_MAILBOX__'] = (string) ($ctx['mailbox'] ?? '');
		$arr['__INBOX_IDENTITY__'] = (string) ($ctx['identity']['name'] ?? '');

		$out = array();
		foreach ($arr as $k => $v) {
			if (!preg_match('/^__([A-Z0-9_]+)__$/', (string) $k, $m) || in_array($m[1], self::DROP, true) || is_array($v) || is_object($v)) {
				continue;
			}
			$out[$m[1]] = (string) $v;
		}
		// The signature put by a template, its own variables replaced as the writing replaces them (1.53.0)
		if (strpos($out['SENDEREMAIL_SIGNATURE'], '__') !== false) {
			$out['SENDEREMAIL_SIGNATURE'] = self::fillHtml(self::fillHtml($out['SENDEREMAIL_SIGNATURE'], $out), $out);
		}

		return $out;
	}

	/**
	 * Values of the variables of an identity (1.53.0), as text
	 *
	 * @param array<string,mixed> $identity Identity (InboxComposer::identities()): name, email, reply_to, mailbox, mailbox_label
	 * @return array<string,string> KEY => text
	 */
	public static function identityValues(array $identity)
	{
		return array(
			'INBOX_IDENTITY_NAME' => (string) ($identity['name'] ?? ''),
			'INBOX_IDENTITY_EMAIL' => (string) ($identity['email'] ?? ''),
			'INBOX_IDENTITY_REPLYTO' => (string) ($identity['reply_to'] ?? ''),
			'INBOX_MAILBOX_EMAIL' => (string) ($identity['mailbox'] ?? ''),
			'INBOX_MAILBOX_LABEL' => (string) ($identity['mailbox_label'] ?? ''),
		);
	}

	/**
	 * Signatures with their variables replaced (1.53.0): those of the identity, of the user who sends, of the company
	 * and of the other modules (a signature kept in a module of templates, __MY_SIGNATURE__). Two passes: the value of
	 * a variable may hold variables (a signature of a module holding __INBOX_IDENTITY_NAME__). Never a recipient nor a
	 * piece: a signature is the same in every mail of the identity. An unknown variable stays as it is (seen in the
	 * preview).
	 *
	 * @param User                           $user       User who sends
	 * @param array<int,array<string,mixed>> $identities Identities (InboxComposer::identities())
	 * @return array<int,string> id of the identity => HTML
	 */
	public function signatures(User $user, array $identities)
	{
		$out = array();
		$base = null;
		foreach ($identities as $i) {
			$sig = (string) ($i['signature'] ?? '');
			if (trim($sig) === '' || strpos($sig, '__') === false) {
				$out[(int) $i['id']] = $sig;
				continue;
			}
			if ($base === null) {
				$base = self::signatureBase();
			}
			$values = array_merge($base, self::identityValues($i));
			$out[(int) $i['id']] = self::fillHtml(self::fillHtml($sig, $values), $values);
		}

		return $out;
	}

	/**
	 * Values a signature can use besides its identity: the user who sends, the company, the other modules (no piece,
	 * no recipient)
	 *
	 * @return array<string,string> KEY => value
	 */
	public static function signatureBase()
	{
		global $langs;

		$arr = getCommonSubstitutionArray($langs, 0, null, null);
		complete_substitutions_array($arr, $langs, null, array('mode' => 'formemail'));
		$base = array();
		foreach ($arr as $k => $v) {
			if (preg_match('/^__([A-Z0-9_]+)__$/', (string) $k, $m) && !in_array($m[1], self::DROP, true) && !is_array($v) && !is_object($v)) {
				$base[$m[1]] = (string) $v;
			}
		}

		return $base;
	}

	/**
	 * Preview of a signature being written in the dialog of an identity: as the writing will show it, and the
	 * variables it holds that nothing replaces (a mistyped key)
	 *
	 * @param User                $user     User
	 * @param array<string,mixed> $identity name, email, reply_to, mailbox, mailbox_label, signature
	 * @return array{html:string,unknown:string[],keys:string[],values:array<string,string|null>}
	 */
	public function signaturePreview(User $user, array $identity)
	{
		$base = self::signatureBase();
		$values = array_merge($base, self::identityValues($identity));
		$html = self::fillHtml(self::fillHtml((string) ($identity['signature'] ?? ''), $values), $values);
		preg_match_all('/__([A-Z0-9]+(?:_[A-Z0-9]+)*)__/', $html, $m);
		$keys = array_values(array_unique(array_merge(self::IDENTITY_KEYS, array_keys($base))));
		sort($keys);

		// What each key gives, for the picker of the variables (1.53.3): plain text, short; never the value of a key
		// that looks like a secret (a module may give a token or a password)
		$shown = array();
		foreach ($keys as $k) {
			$shown[$k] = self::secretKey($k) ? null : dol_trunc(trim((string) preg_replace('/\s+/u', ' ', dol_string_nohtmltag((string) ($values[$k] ?? ''), 1))), 80, 'right', 'UTF-8', 1);
		}

		return array('html' => InboxMailBuilder::cleanHtml($html), 'unknown' => array_values(array_unique($m[1])), 'keys' => $keys, 'values' => $shown);
	}

	/**
	 * A key whose value is never shown in the picker of the variables
	 *
	 * @param string $key Key without the __
	 * @return bool
	 */
	public static function secretKey($key)
	{
		return (bool) preg_match('/(^|_)(TOKEN|KEY|PASS|PASSWORD|PWD|SECRET|SALT|HASH|APIKEY)(_|$)|PASSWORD|SECRET|TOKEN/', strtoupper((string) $key));
	}

	/**
	 * HTML with its variables replaced: an HTML value (a signature of another module) put as it is, a text escaped
	 * (a name with « & » or « < » stays text); an unknown variable kept
	 *
	 * @param string               $html   HTML
	 * @param array<string,string> $values KEY => value
	 * @return string
	 */
	public static function fillHtml($html, array $values)
	{
		return (string) preg_replace_callback('/__([A-Z0-9]+(?:_[A-Z0-9]+)*)__/', function ($m) use ($values) {
			if (in_array($m[1], self::DROP, true)) {
				return '';
			}
			if (!array_key_exists($m[1], $values)) {
				return $m[0];
			}
			$v = (string) $values[$m[1]];
			// The identity is typed text, always escaped (a name « R&D <Ventes> » would look like HTML)
			$isText = in_array($m[1], self::IDENTITY_KEYS, true) || in_array($m[1], array('INBOX_IDENTITY', 'INBOX_MAILBOX'), true);

			return !$isText && dol_textishtml($v) ? $v : dol_escape_htmltag($v, 0, 1);
		}, (string) $html);
	}

	/**
	 * Preview of a template being written (1.26.0): its subject and its text filled for a recipient, in a mailbox,
	 * in a language, as the writing would fill them
	 *
	 * @param User   $user      User
	 * @param int    $accountId Mailbox (its default identity signs), 0 for none
	 * @param string $to        Recipient
	 * @param string $lang      Language ('' = the one of the user)
	 * @param string $subject   Subject of the template
	 * @param string $html      Text of the template (as the editor gives it)
	 * @return array{subject:string,html:string}
	 */
	public function preview(User $user, $accountId, $to, $lang, $subject, $html)
	{
		$ctx = array('to' => (string) $to, 'lang' => preg_match('/^[a-z]{2,3}_[A-Z]{2,3}$/', (string) $lang) ? (string) $lang : '');
		$access = new InboxAccess($this->db);
		if ($accountId > 0 && $access->getLevel($user, (int) $accountId) !== InboxAccess::LEVEL_NONE) {
			require_once __DIR__.'/inboxidentity.class.php';
			$account = new InboxAccount($this->db);
			if ($account->fetch((int) $accountId) > 0) {
				$ctx['mailbox'] = (string) $account->email;
				$ids = (new InboxIdentity($this->db))->listForAccount((int) $accountId, true);
				if ($ids) {
					$ctx['identity'] = array('name' => (string) $ids[0]->name, 'email' => (string) $ids[0]->email, 'reply_to' => (string) $ids[0]->reply_to, 'signature' => (string) $ids[0]->signature,
						'mailbox' => (string) $account->email, 'mailbox_label' => (string) $account->label);
				}
			}
		}
		$values = $this->values($user, $ctx);
		// As a writing shows it: the variables with their value (an HTML value, a signature, kept), then cleaned
		$content = self::toEditor(self::fromEditor((string) $html), $values);

		return array('subject' => self::fill(InboxMimeParser::clean((string) $subject), $values), 'html' => InboxMailBuilder::cleanHtml($content));
	}

	/**
	 * A template ready for the editor: the subject with its values, the body with its variables as chips
	 *
	 * @param object               $template Row of the template (usable())
	 * @param array<string,string> $values   Values (values())
	 * @return array{id:int,label:string,subject:string,subject_raw:string,html:string,joinfiles:int,whole:int}
	 */
	public function render($template, array $values)
	{
		$topic = (string) $template->topic;

		$whole = (string) $template->type_template === self::TYPE ? ($this->wholeOf(array((int) $template->rowid))[(int) $template->rowid] ?? 0) : 1;

		return array('id' => (int) $template->rowid, 'label' => (string) $template->label, 'subject_raw' => $topic, 'subject' => self::fill($topic, $values),
			'html' => self::toEditor((string) $template->content, $values), 'joinfiles' => (int) (!empty($template->joinfiles)), 'whole' => (int) $whole);
	}

	/**
	 * Text with its variables replaced (subject)
	 *
	 * @param string               $text   Text
	 * @param array<string,string> $values Values
	 * @return string
	 */
	public static function fill($text, array $values)
	{
		return (string) preg_replace_callback('/__([A-Z0-9]+(?:_[A-Z0-9]+)*)__/', function ($m) use ($values) {
			if (in_array($m[1], self::DROP, true)) {
				return '';
			}
			return array_key_exists($m[1], $values) ? trim(dol_string_nohtmltag($values[$m[1]], 1)) : $m[0];
		}, (string) $text);
	}

	/**
	 * Body of a template for the editor: the variables of the text become chips (span data-inbox-var, their value
	 * inside, empty when unknown), those of an attribute (a link) their value; an HTML value (signature) is put as is
	 *
	 * @param string                    $content Content of the template (HTML or text)
	 * @param array<string,string>|null $values  Values (null: chips without value, editing of a template)
	 * @return string
	 */
	public static function toEditor($content, $values)
	{
		$content = (string) $content;
		if (!dol_textishtml($content)) {
			$content = dol_nl2br(dol_escape_htmltag($content, 0, 1));
		}
		$doc = new DOMDocument();
		$previous = libxml_use_internal_errors(true);
		$doc->loadHTML('<?xml encoding="UTF-8"><html><body>'.$content.'</body></html>', LIBXML_NONET | LIBXML_HTML_NODEFDTD);
		libxml_clear_errors();
		libxml_use_internal_errors($previous);
		$body = $doc->getElementsByTagName('body')->item(0);
		if (!$body) {
			return '';
		}
		$xpath = new DOMXPath($doc);
		foreach (iterator_to_array($xpath->query('//@*')) as $attr) {
			if (strpos($attr->value, '__') !== false && $values !== null) {
				$attr->value = self::fill($attr->value, $values);
			}
		}
		foreach (iterator_to_array($xpath->query('//text()')) as $text) {
			$parts = preg_split('/__([A-Z0-9]+(?:_[A-Z0-9]+)*)__/', $text->nodeValue, -1, PREG_SPLIT_DELIM_CAPTURE);
			if (count($parts) < 2) {
				continue;
			}
			$frag = $doc->createDocumentFragment();
			foreach ($parts as $i => $part) {
				if ($i % 2 === 0) {
					if ($part !== '') {
						$frag->appendChild($doc->createTextNode($part));
					}
					continue;
				}
				if (in_array($part, self::DROP, true)) {
					continue;
				}
				$value = $values !== null && array_key_exists($part, $values) ? (string) $values[$part] : '';
				if ($value !== '' && dol_textishtml($value)) {
					// HTML value (signature, lines): put as is, cleaned, it is not a chip
					$tmp = new DOMDocument();
					$prev = libxml_use_internal_errors(true);
					$tmp->loadHTML('<?xml encoding="UTF-8"><html><body>'.InboxMailBuilder::cleanHtml($value).'</body></html>', LIBXML_NONET | LIBXML_HTML_NODEFDTD);
					libxml_clear_errors();
					libxml_use_internal_errors($prev);
					$tb = $tmp->getElementsByTagName('body')->item(0);
					foreach ($tb ? iterator_to_array($tb->childNodes) : array() as $n) {
						$frag->appendChild($doc->importNode($n, true));
					}
					continue;
				}
				$span = $doc->createElement('span');
				$span->setAttribute('data-inbox-var', $part);
				$span->appendChild($doc->createTextNode(trim(dol_string_nohtmltag($value, 1))));
				$frag->appendChild($span);
			}
			$text->parentNode->replaceChild($frag, $text);
		}
		$out = '';
		foreach ($body->childNodes as $child) {
			$out .= $doc->saveHTML($child);
		}

		return InboxMailBuilder::cleanHtml($out, true);
	}

	/**
	 * Body written in the editor of a template, back to the format of Dolibarr: each chip becomes __KEY__
	 *
	 * @param string $html HTML of the editor
	 * @return string
	 */
	public static function fromEditor($html)
	{
		$html = InboxMailBuilder::cleanHtml((string) $html, true);

		return (string) preg_replace('#<span\b[^>]*\bdata-inbox-var="([A-Z0-9_]+)"[^>]*>.*?</span>#s', '__$1__', $html);
	}

	/**
	 * Keys offered in the editor of a template: those of Dolibarr for a piece (with a thirdparty) and those of inbox
	 *
	 * @return string[]
	 */
	public static function keys()
	{
		global $langs;

		$arr = getCommonSubstitutionArray($langs, 2, null, null);
		complete_substitutions_array($arr, $langs, null, array('mode' => 'formemail'));
		$keys = array();
		foreach (array_keys($arr) as $k) {
			if (preg_match('/^__([A-Z0-9_]+)__$/', (string) $k, $m) && !in_array($m[1], self::DROP, true)) {
				$keys[] = $m[1];
			}
		}
		foreach (array('THIRDPARTY_NAME', 'THIRDPARTY_EMAIL', 'REF', 'REF_CLIENT', 'AMOUNT', 'AMOUNT_EXCL_TAX') as $k) {
			$keys[] = $k;
		}
		$keys = array_values(array_unique(array_merge(self::OWN, $keys)));
		sort($keys);

		return $keys;
	}

	// ---------- Templates of inbox written from inbox (tab Templates of a mailbox, « Save as a template ») ----------

	/**
	 * The user may change this template: his own private one; a shared one when he is admin of the mailboxes, or
	 * manages every mailbox it is attached to (a template of every mailbox: admins only)
	 *
	 * @param User  $user     User
	 * @param int   $private  Private
	 * @param int   $owner    Owner of a private one
	 * @param int[] $accounts Mailboxes it is attached to
	 * @return bool
	 */
	public function canChange(User $user, $private, $owner, array $accounts)
	{
		if ($private) {
			return (int) $owner === (int) $user->id;
		}
		if ($user->hasRight('inbox', 'account', 'admin')) {
			return true;
		}
		if (!$accounts) {
			return false;
		}
		require_once __DIR__.'/inboxaccess.class.php';
		$access = new InboxAccess($this->db);
		foreach ($accounts as $a) {
			if (!$access->check($user, (int) $a, InboxAccess::LEVEL_MANAGE)) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Shared templates of inbox offered in a mailbox (its own and those of every mailbox), and the private ones of the user
	 *
	 * @param User $user      User
	 * @param int  $accountId Mailbox
	 * @return array<int,array<string,mixed>>
	 */
	public function listForAccount(User $user, $accountId)
	{
		$p = $this->db->prefix();
		$rows = $this->db->getRows("SELECT t.rowid, t.label, t.topic, t.lang, t.private, t.fk_user, t.active, t.position FROM ".$p."c_email_templates as t WHERE t.type_template = '".self::TYPE."'".$this->visibleSql($user)
			." AND (NOT EXISTS (SELECT 1 FROM ".$p."inbox_template_account as ta WHERE ta.fk_template = t.rowid)"
			." OR EXISTS (SELECT 1 FROM ".$p."inbox_template_account as ta2 WHERE ta2.fk_template = t.rowid AND ta2.fk_account = ".((int) $accountId)."))"
			." ORDER BY t.private, t.position, t.label LIMIT 500");
		$out = array();
		$whole = $this->wholeOf(array_map(function ($r) {
			return (int) $r->rowid;
		}, is_array($rows) ? $rows : array()));
		foreach (is_array($rows) ? $rows : array() as $r) {
			$accounts = $this->accountsOf((int) $r->rowid);
			$out[] = array('id' => (int) $r->rowid, 'label' => (string) $r->label, 'subject' => (string) $r->topic, 'lang' => (string) $r->lang, 'private' => (int) $r->private,
				'active' => (int) $r->active, 'accounts' => $accounts, 'editable' => $this->canChange($user, (int) $r->private, (int) $r->fk_user, $accounts) ? 1 : 0,
				'whole' => (int) ($whole[(int) $r->rowid] ?? 0));
		}

		return $out;
	}

	/**
	 * A template of inbox for its editor
	 *
	 * @param User $user User
	 * @param int  $id   Template
	 * @return array<string,mixed>|null
	 */
	public function get(User $user, $id)
	{
		$t = $this->db->getRow("SELECT t.rowid, t.label, t.topic, t.content, t.lang, t.private, t.fk_user, t.active, t.joinfiles FROM ".$this->db->prefix()."c_email_templates as t"
			." WHERE t.rowid = ".((int) $id)." AND t.type_template = '".self::TYPE."'".$this->visibleSql($user));
		if (!is_object($t)) {
			return $this->fail('not_found');
		}
		$accounts = $this->accountsOf((int) $t->rowid);
		// A template of given mailboxes: read by their users only, or who manages the mailboxes (audit INBX-019)
		if ($accounts && !$user->hasRight('inbox', 'account', 'admin')) {
			$access = new InboxAccess($this->db);
			if (!array_intersect($accounts, array_keys($access->getLevels($user)))) {
				return $this->fail('not_found');
			}
		}

		return array('id' => (int) $t->rowid, 'label' => (string) $t->label, 'subject' => (string) $t->topic, 'html' => self::toEditor((string) $t->content, null), 'lang' => (string) $t->lang,
			'private' => (int) $t->private, 'active' => (int) $t->active, 'accounts' => $accounts, 'editable' => $this->canChange($user, (int) $t->private, (int) $t->fk_user, $accounts) ? 1 : 0,
			'whole' => (int) ($this->wholeOf(array((int) $t->rowid))[(int) $t->rowid] ?? 0));
	}

	/**
	 * Create or change a template of inbox
	 *
	 * @param User                $user User
	 * @param array<string,mixed> $data id, label, subject, html, private, accounts, active, whole (absent: unchanged)
	 * @return int Id, 0 on error
	 */
	public function save(User $user, array $data)
	{
		global $conf;

		$this->error = '';
		$this->errorCode = '';
		$id = (int) ($data['id'] ?? 0);
		$label = dol_trunc(trim((string) ($data['label'] ?? '')), 180, 'right', 'UTF-8', 1);
		if ($label === '') {
			$this->fail('bad_label');
			return 0;
		}
		$private = !empty($data['private']) ? 1 : 0;
		$accounts = $private ? array() : array_values(array_unique(array_filter(array_map('intval', (array) ($data['accounts'] ?? array())))));
		$p = $this->db->prefix();
		if ($id > 0) {
			$old = $this->db->getRow("SELECT rowid, private, fk_user FROM ".$p."c_email_templates as t WHERE t.rowid = ".$id." AND t.type_template = '".self::TYPE."'".$this->visibleSql($user));
			if (!is_object($old)) {
				$this->fail('not_found');
				return 0;
			}
			if (!$this->canChange($user, (int) $old->private, (int) $old->fk_user, $this->accountsOf($id))) {
				$this->fail('no_right');
				return 0;
			}
		}
		if (!$this->canChange($user, $private, (int) $user->id, $accounts)) {
			$this->fail('no_right');
			return 0;
		}
		$subject = dol_trunc(InboxMimeParser::clean((string) ($data['subject'] ?? '')), 998, 'right', 'UTF-8', 1);
		$content = self::fromEditor((string) ($data['html'] ?? ''));
		// Language of the template (1.26.0): '' = every language, else a code of Dolibarr (fr_FR)
		$lang = (string) ($data['lang'] ?? '');
		$lang = preg_match('/^[a-z]{2,3}_[A-Z]{2,3}$/', $lang) ? $lang : '';
		$active = array_key_exists('active', $data) ? (!empty($data['active']) ? 1 : 0) : 1;
		$this->db->begin();
		if ($id > 0) {
			$ok = $this->db->query("UPDATE ".$p."c_email_templates SET label = '".$this->db->escape($label)."', topic = '".$this->db->escape($subject)."', content = '".$this->db->escape($content)."',"
				." private = ".$private.", fk_user = ".($private ? (int) $user->id : "NULL").", active = ".$active.", lang = '".$this->db->escape($lang)."' WHERE rowid = ".$id);
		} else {
			$pos = (int) $this->db->getRow("SELECT MAX(position) as m FROM ".$p."c_email_templates WHERE type_template = '".self::TYPE."'")->m;
			$ok = $this->db->query("INSERT INTO ".$p."c_email_templates (entity, module, type_template, lang, private, fk_user, datec, label, position, active, enabled, topic, content)"
				." VALUES (".((int) $conf->entity).", 'inbox', '".self::TYPE."', '".$this->db->escape($lang)."', ".$private.", ".($private ? (int) $user->id : "NULL").", '".$this->db->idate(dol_now())."', '".$this->db->escape($label)."', ".($pos + 1).", ".$active.", '1',"
				." '".$this->db->escape($subject)."', '".$this->db->escape($content)."')");
			$id = $ok ? (int) $this->db->last_insert_id($p.'c_email_templates') : 0;
		}
		$ok = $ok && $this->db->query("DELETE FROM ".$p."inbox_template_account WHERE fk_template = ".$id);
		foreach ($accounts as $a) {
			$ok = $ok && $this->db->query("INSERT INTO ".$p."inbox_template_account (fk_template, fk_account) VALUES (".$id.", ".((int) $a).")");
		}
		if ($ok && array_key_exists('whole', $data)) {
			$ok = $this->setWhole($id, !empty($data['whole']) ? 1 : 0);
		}
		if (!$ok) {
			$this->db->rollback();
			$this->fail('error', $this->db->lasterror());
			return 0;
		}
		$this->db->commit();

		return $id;
	}

	/**
	 * @param User $user User
	 * @param int  $id   Template
	 * @return bool
	 */
	public function delete(User $user, $id)
	{
		$p = $this->db->prefix();
		$old = $this->db->getRow("SELECT rowid, private, fk_user FROM ".$p."c_email_templates as t WHERE t.rowid = ".((int) $id)." AND t.type_template = '".self::TYPE."'".$this->visibleSql($user));
		if (!is_object($old)) {
			return (bool) $this->fail('not_found');
		}
		if (!$this->canChange($user, (int) $old->private, (int) $old->fk_user, $this->accountsOf((int) $id))) {
			return (bool) $this->fail('no_right');
		}
		$this->db->query("DELETE FROM ".$p."inbox_template_account WHERE fk_template = ".((int) $id));
		$this->db->query("DELETE FROM ".$p."inbox_template_option WHERE fk_template = ".((int) $id));
		require_once __DIR__.'/inboxtemplatedefaults.class.php';
		(new InboxTemplateDefaults($this->db))->forgetTemplate((int) $id);

		return (bool) $this->db->query("DELETE FROM ".$p."c_email_templates WHERE rowid = ".((int) $id));
	}

	/**
	 * Order of the templates (drag and drop): the ones the user may change, the others keep their place
	 *
	 * @param User  $user User
	 * @param int[] $ids  Templates in their new order
	 * @return void
	 */
	public function order(User $user, array $ids)
	{
		$p = $this->db->prefix();
		$n = 0;
		foreach (array_slice(array_map('intval', $ids), 0, 500) as $id) {
			$n++;
			$t = $this->db->getRow("SELECT rowid, private, fk_user FROM ".$p."c_email_templates as t WHERE t.rowid = ".$id." AND t.type_template = '".self::TYPE."'".$this->visibleSql($user));
			if (is_object($t) && $this->canChange($user, (int) $t->private, (int) $t->fk_user, $this->accountsOf($id))) {
				$this->db->query("UPDATE ".$p."c_email_templates SET position = ".$n." WHERE rowid = ".$id);
			}
		}
	}
}
