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
 * \brief   Pieces created from a mail and files of a mail put in the documents of a piece (1.14.0)
 */

require_once __DIR__.'/inboxelements.class.php';
require_once __DIR__.'/inboxlink.class.php';
require_once __DIR__.'/inboxactionlog.class.php';
require_once __DIR__.'/inboxaccess.class.php';
require_once __DIR__.'/inboxaccount.class.php';
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

	/** Seconds an intent may be used without its token (the page of creation shown again after an error) */
	const INTENT_UNTOKEN_TTL = 900;

	/** Parameter of the address of the page of creation that carries the token of its intent */
	const INTENT_PARAM = 'inboxintent';

	/**
	 * Capabilities announced to the hooks of a mail ($parameters['capabilities'], 1.160.0), a word only once it is
	 * delivered: agenda_create = « Create > Event » opens the page of creation of the agenda provider
	 */
	const CAPABILITIES = array('agenda_create', 'create_buttons', 'widgets', 'agenda_todo', 'element_types');

	/** Groups of the dialog « Create from this mail » (1.161.0), in their order; a module never makes one */
	const GROUPS = array('contacts', 'sales', 'purchases', 'followup', 'other');

	/** Group of each type of the core */
	const TYPE_GROUPS = array('societe' => 'contacts', 'contact' => 'contacts', 'propal' => 'sales', 'commande' => 'sales',
		'supplier_proposal' => 'purchases', 'order_supplier' => 'purchases', 'action' => 'followup', 'ticket' => 'followup', 'project' => 'followup');

	/** Name of the setting of a mailbox (llx_inbox_userparam, fk_user = 0) holding the buttons the admin kept */
	const KEEP_PARAM = 'create_keep';

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
	 * Page of a module the last intent opens (1.160.0): an event goes to the page of creation of the agenda provider
	 * (hook inboxAgendaProvider) when it declares one, in a window of inbox; null for a page of Dolibarr
	 *
	 * @var array{label:string}|null
	 */
	public $provider = null;

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
	 * Types that can be created from a mail: those of the core, then the objects of the modules that give a page of
	 * creation and its trigger (hook inboxElementTypes, 1.164.0)
	 *
	 * @return array<string,array{url:string,trigger:string,label:string,module?:bool}>
	 */
	public static function types()
	{
		$out = self::TYPES;
		$core = array_column(self::TYPES, 'trigger');
		foreach (InboxElements::types() as $t => $def) {
			// Never the trigger of a type of the core: its pieces stay linked as pieces of the core
			if (!isset($out[$t]) && !empty($def['create']) && !in_array($def['create']['trigger'], $core, true)) {
				$out[$t] = array('url' => $def['create']['url'], 'trigger' => $def['create']['trigger'], 'label' => $def['label'], 'module' => true);
			}
		}

		return $out;
	}

	/** @var bool The page of the last intent is the one of a module: its markers filled by the browser */
	public $moduleUrl = false;

	/**
	 * Buttons of « Create from this mail » for a mailbox (1.161.0): those of the core the user may create, then the
	 * ones the modules add and remove (hook inboxCreateButtons, for this mailbox), the buttons the admin kept back
	 *
	 * @param User                $user      User
	 * @param int                 $accountId Mailbox
	 * @param array<string,mixed> $params    Parameters of the hooks of the mail (mail 0: the mailbox alone)
	 * @param bool                $all       With the buttons removed (list of the setup), else the ones shown only
	 * @return list<array<string,mixed>>
	 */
	public function buttons(User $user, $accountId, array $params, $all = false)
	{
		global $hookmanager;

		$core = array();
		foreach (self::creatable($user) as $c) {
			$core[] = array_merge($c, array('id' => $c['type'], 'group' => self::TYPE_GROUPS[$c['type']] ?? 'other', 'source' => '', 'url' => '', 'window' => false));
		}
		$add = array();
		$remove = array();
		if (!is_object($hookmanager)) {
			include_once DOL_DOCUMENT_ROOT.'/core/class/hookmanager.class.php';
			$hookmanager = new HookManager($this->db);
		}
		$hookmanager->initHooks(array('inbox'));
		$account = new InboxAccount($this->db);
		$mailbox = $account->fetch((int) $accountId) > 0
			? array('id' => (int) $account->id, 'email' => (string) $account->email, 'label' => (string) $account->label, 'usage' => (string) ($account->usage_profile ?: 'auto'))
			: array('id' => (int) $accountId, 'email' => '', 'label' => '', 'usage' => 'auto');
		$parameters = array_merge($params, array('user' => $user, 'mailbox' => $mailbox, 'capabilities' => self::CAPABILITIES,
			'buttons' => array_map(function ($b) {
				return array('id' => $b['id'], 'type' => $b['type'], 'label' => $b['label'], 'group' => $b['group'], 'source' => $b['source']);
			}, $core)));
		$object = null;
		$action = '';
		if ($hookmanager->executeHooks('inboxCreateButtons', $parameters, $object, $action) >= 0) {
			$add = is_array($hookmanager->resArray['add'] ?? null) ? $hookmanager->resArray['add'] : array();
			$remove = is_array($hookmanager->resArray['remove'] ?? null) ? $hookmanager->resArray['remove'] : array();
		}

		return self::assemble($user, $core, $add, $remove, $this->kept((int) $accountId), $all);
	}

	/**
	 * The buttons of a mailbox from those of the core, the ones added and removed by the modules (all of them merged:
	 * the removals apply to the whole list, whatever the order of the modules), the ones the admin kept
	 *
	 * @param User                           $user   User
	 * @param list<array<string,mixed>>      $core   Buttons of the core
	 * @param array<int|string,mixed>        $add    Added: source, id, type, label, icon, url, window, rights, group
	 * @param array<int|string,mixed>        $remove Removed: id (string) or {id, source}
	 * @param string[]                       $keep   Kept by the admin of inbox (removals ignored)
	 * @param bool                           $all    With the removed buttons
	 * @return list<array<string,mixed>>
	 */
	public static function assemble(User $user, array $core, array $add, array $remove, array $keep, $all)
	{
		$list = $core;
		$seen = array_flip(array_column($core, 'id'));
		foreach ($add as $b) {
			$source = is_array($b) ? (string) ($b['source'] ?? '') : '';
			$id = is_array($b) ? (string) ($b['id'] ?? '') : '';
			$type = is_array($b) ? (string) ($b['type'] ?? '') : '';
			$url = is_array($b) ? (string) ($b['url'] ?? '') : '';
			if (!preg_match('/^[a-z0-9_]{1,40}$/', $source) || !preg_match('/^[a-z0-9_-]{1,40}$/', $id) || !isset(self::types()[$type])
				|| empty($b['label']) || !self::localUrl($url) || !InboxElements::canWriteType($user, $type) || !self::allowed($user, $b['rights'] ?? array())) {
				continue;
			}
			$key = $source.':'.$id;
			if (isset($seen[$key])) {
				continue;
			}
			$seen[$key] = 1;
			$group = in_array($b['group'] ?? '', self::GROUPS, true) ? (string) $b['group'] : 'other';
			$icon = preg_match('/^[a-z0-9-]{1,40}$/', (string) ($b['icon'] ?? '')) ? (string) $b['icon'] : (InboxElements::TYPES[$type]['icon'] ?? 'plus');
			$list[] = array('id' => $key, 'type' => $type, 'label' => dol_trunc((string) $b['label'], 60, 'right', 'UTF-8', 1), 'icon' => $icon, 'group' => $group,
				'source' => $source, 'url' => $url, 'window' => !empty($b['window']));
		}
		$removedBy = array();
		foreach ($remove as $r) {
			$id = is_array($r) ? (string) ($r['id'] ?? '') : (string) $r;
			$source = is_array($r) && preg_match('/^[a-z0-9_]{1,40}$/', (string) ($r['source'] ?? '')) ? (string) $r['source'] : '';
			if ($id !== '' && !isset($removedBy[$id])) {
				$removedBy[$id] = $source;
			}
		}
		$out = array();
		foreach ($list as $b) {
			$removed = isset($removedBy[$b['id']]);
			$kept = $removed && in_array($b['id'], $keep, true);
			$b['removed_by'] = $removed ? $removedBy[$b['id']] : null;
			$b['kept'] = $kept;
			if ($all || !$removed || $kept) {
				$out[] = $b;
			}
		}
		// Groups in their order, the buttons of the core first in each group
		$rank = array_flip(self::GROUPS);
		usort($out, function ($a, $b) use ($rank) {
			return ($rank[$a['group']] <=> $rank[$b['group']]) ?: (($a['source'] !== '') <=> ($b['source'] !== ''));
		});

		return $out;
	}

	/**
	 * Rights a module asks for its button, each a list (module, permission[, sub permission]); all needed
	 *
	 * @param User  $user   User
	 * @param mixed $rights Rights
	 * @return bool
	 */
	public static function allowed(User $user, $rights)
	{
		foreach (is_array($rights) ? $rights : array() as $r) {
			if (!is_array($r) || count($r) < 2 || count($r) > 3) {
				return false;
			}
			$r = array_map('strval', array_values($r));
			if (!(count($r) === 3 ? $user->hasRight($r[0], $r[1], $r[2]) : $user->hasRight($r[0], $r[1]))) {
				return false;
			}
		}

		return true;
	}

	/**
	 * An address inside Dolibarr: one starting slash, never « // » nor « /\ » (another host for the browsers), no space,
	 * control character, backslash nor quote (audit INBX-101, as the actions of the modules)
	 *
	 * @param string $u Address
	 * @return bool
	 */
	public static function localUrl($u)
	{
		return strlen($u) <= 1000 && $u !== '' && $u[0] === '/' && !(isset($u[1]) && ($u[1] === '/' || $u[1] === '\\')) && !preg_match('/[\s\x00-\x1f\\\\<>"\']/', $u);
	}

	/**
	 * Buttons the admin of inbox kept for a mailbox although a module removes them
	 *
	 * @param int $accountId Mailbox
	 * @return string[]
	 */
	public function kept($accountId)
	{
		$row = $this->db->getRow("SELECT value FROM ".$this->db->prefix()."inbox_userparam WHERE fk_user = 0 AND fk_account = ".((int) $accountId)." AND param = '".self::KEEP_PARAM."'");
		$v = is_object($row) ? json_decode((string) $row->value, true) : null;

		return is_array($v) ? array_values(array_filter(array_map('strval', $v), function ($id) {
			return (bool) preg_match('/^([a-z0-9_]{1,40}:)?[a-z0-9_-]{1,40}$/', $id);
		})) : array();
	}

	/**
	 * Keep a button removed by a module for a mailbox, or let the removal apply again (admin of inbox, journal)
	 *
	 * @param User   $user      User
	 * @param int    $accountId Mailbox
	 * @param string $id        Button
	 * @param bool   $keep      Keep it
	 * @return int 1 done, 0 nothing to do, -1 invalid, -3 error of the database
	 */
	public function keep(User $user, $accountId, $id, $keep)
	{
		if (!preg_match('/^([a-z0-9_]{1,40}:)?[a-z0-9_-]{1,40}$/', (string) $id)) {
			return -1;
		}
		$list = $this->kept((int) $accountId);
		$has = in_array($id, $list, true);
		if ($has === (bool) $keep) {
			return 0;
		}
		$list = $keep ? array_merge($list, array((string) $id)) : array_values(array_diff($list, array((string) $id)));
		$p = $this->db->prefix();
		$where = " WHERE fk_user = 0 AND fk_account = ".((int) $accountId)." AND param = '".self::KEEP_PARAM."'";
		$this->db->query("DELETE FROM ".$p."inbox_userparam".$where);
		if ($list && !$this->db->query("INSERT INTO ".$p."inbox_userparam (fk_user, fk_account, param, value) VALUES (0, ".((int) $accountId).", '".self::KEEP_PARAM."', '".$this->db->escape((string) json_encode($list))."')")) {
			return -3;
		}
		require_once __DIR__.'/inboxaccountlog.class.php';
		(new InboxAccountLog($this->db))->add((int) $accountId, $user, 'create_keep', array('button' => (string) $id, 'keep' => !$keep), array('button' => (string) $id, 'keep' => (bool) $keep));

		return 1;
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
	public function intent(User $user, InboxReader $reader, $type, $attach, array $known, $button = null)
	{
		$types = self::types();
		if (!isset($types[$type]) || !InboxElements::canWriteType($user, $type)) {
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
		// An event: the page of creation of the agenda module of the user when there is one (its markers filled by the
		// browser, the address taken from the hook, never from the request); the piece saved there is linked the same way
		$this->provider = null;
		$url = '';
		if (is_array($button) && !empty($button['source'])) {
			// A button of a module (1.161.0): its page, taken from the hook on the server, in a window or a tab
			$url = (string) $button['url'];
			$this->provider = $button['window'] ? array('label' => (string) $button['label']) : null;
		} elseif ($type === 'action') {
			require_once __DIR__.'/inboxagendapanel.class.php';
			$p = (new InboxAgendaPanel($this->db))->provider($user);
			if ($p && !empty($p['urls']['create'])) {
				$url = (string) $p['urls']['create'];
				$this->provider = array('label' => (string) $p['label']);
			}
		}
		$this->moduleUrl = $url !== '';
		if ($url === '' && !empty($types[$type]['module'])) {
			// An object of a module (1.164.0): its page of creation, its markers filled by the browser
			$url = $types[$type]['url'];
			$this->moduleUrl = true;
		}
		if ($url === '') {
			$url = $this->createUrl($type, $row, $known);
		}
		$token = bin2hex(random_bytes(8));
		$intents[] = array('type' => $type, 'mailref' => $refId, 'mail' => (int) $row->rowid, 'subject' => (string) $row->subject, 'file' => $file, 'at' => dol_now(),
			'contactid' => $contactAfter, 'token' => $token, 'path' => (string) parse_url($url, PHP_URL_PATH));
		$this->saveIntents($user, array_slice($intents, -10));

		return self::withToken($url, $token);
	}

	/**
	 * Full page of creation of a thirdparty from the panel « New customer » (1.79.0): the intent keeps the
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
		$token = bin2hex(random_bytes(8));
		$intents[] = array('type' => 'societe', 'mailref' => $refId, 'mail' => (int) $reader->row->rowid, 'subject' => (string) $reader->row->subject, 'file' => '', 'at' => dol_now(),
			'lead' => 1, 'contact' => is_array($contact) ? $contact : null, 'token' => $token, 'path' => (string) parse_url($url, PHP_URL_PATH));
		$this->saveIntents($user, array_slice($intents, -10));

		return self::withToken($url, $token);
	}

	/**
	 * @param string $url   Page of creation
	 * @param string $token Token of its intent
	 * @return string
	 */
	protected static function withToken($url, $token)
	{
		$cut = strpos($url, '#');
		$hash = $cut === false ? '' : substr($url, $cut);
		$url = $cut === false ? $url : substr($url, 0, $cut);

		return $url.(strpos($url, '?') === false ? '?' : '&').self::INTENT_PARAM.'='.$token.$hash;
	}

	/**
	 * The intent a creation belongs to: the one whose token is in the page that sent the form (Referer: the page of
	 * creation opened from the mail). A creation made elsewhere (another page, the panel, a module) uses none. Without
	 * the token: the page shown again after an error of the form (its address without parameters) or a browser that
	 * sends no Referer, the last intent of this type and this page, of the last minutes only.
	 *
	 * @param array<int,array<string,mixed>> $intents Intents of the user
	 * @param string                         $type    Type created
	 * @param string                         $referer Page that sent the form
	 * @return int|null                               Key of the intent
	 */
	public static function intentOf(array $intents, $type, $referer)
	{
		$referer = (string) $referer;
		$query = array();
		parse_str((string) parse_url($referer, PHP_URL_QUERY), $query);
		$token = isset($query[self::INTENT_PARAM]) && is_string($query[self::INTENT_PARAM]) ? $query[self::INTENT_PARAM] : '';
		$path = (string) parse_url($referer, PHP_URL_PATH);
		foreach (array_reverse(array_keys($intents)) as $k) {
			$i = $intents[$k];
			if ($i['type'] !== $type) {
				continue;
			}
			if ($token !== '') {
				if (hash_equals((string) ($i['token'] ?? ''), $token)) {
					return $k;
				}
				continue;
			}
			$fresh = (int) ($i['at'] ?? 0) > dol_now() - self::INTENT_UNTOKEN_TTL;
			$shownAgain = $referer !== '' && (string) parse_url($referer, PHP_URL_QUERY) === '' && $path !== '' && $path === (string) ($i['path'] ?? '');
			if ($fresh && ($referer === '' || $shownAgain)) {
				return $k;
			}
			// The newest intent of this type only, never an older one
			return null;
		}

		return null;
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

		// Values of a mail in the address of a page of the core: the one door, not only the filter of that page
		foreach ($q as $k => $v) {
			if (!is_int($v)) {
				$q[$k] = $k === 'email' ? InboxSafe::line($v, 128) : InboxSafe::line($v, 255);
			}
		}

		return DOL_URL_ROOT.self::TYPES[$type]['url'].($q ? '&'.http_build_query($q) : '');
	}

	/**
	 * Trigger of a creation: the intent of the page that sent the form (intentOf) links the new piece to its mail (and
	 * puts the .eml in its documents when asked)
	 *
	 * @param User   $user   User who created
	 * @param string $code   Trigger
	 * @param object $object Piece created
	 * @return int 1 when an intent was used, 0 otherwise
	 */
	public function onCreate(User $user, $code, $object)
	{
		$type = '';
		foreach (self::types() as $t => $def) {
			if ($def['trigger'] === $code) {
				$type = $t;
				break;
			}
		}
		if ($type === '' || empty($object->id)) {
			return 0;
		}
		$intents = $this->intents($user);
		$k = self::intentOf($intents, $type, (string) ($_SERVER['HTTP_REFERER'] ?? ''));
		if ($k === null) {
			return 0;
		}
		$found = $intents[$k];
		unset($intents[$k]);
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
		$object = InboxElements::fetch((int) $id, $type);
		if (!$object) {
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
		$name = dol_sanitizeFileName(InboxMimeParser::fileName(InboxSafe::line((string) $name, 0))) ?: 'file';
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
