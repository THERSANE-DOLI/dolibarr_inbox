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
 * \file    inbox/class/inboxrules.class.php
 * \ingroup inbox
 * \brief   Rules and out of office of a mailbox, written as a Sieve script on its server (lot W8 1.23.0)
 */

require_once __DIR__.'/inboxaccount.class.php';
require_once __DIR__.'/inboxaccess.class.php';
require_once __DIR__.'/inboxactionlog.class.php';
require_once __DIR__.'/connector/inboxconnectorfactory.class.php';

/**
 * The script « inbox » of the mailbox is the only source (P6): its first lines hold the rules as inbox reads them
 * (JSON), then the Sieve made from them. A script changed by another tool is seen (print) and never overwritten
 * without a confirmation; a script active before inbox is kept and run after the rules (include). The previous
 * version stays as « inbox-prev » (back in one click).
 */
class InboxRules
{
	/** Name of the script of inbox, and of its previous version */
	const SCRIPT = 'inbox';
	const PREVIOUS = 'inbox-prev';

	/** Bounds of a set of rules */
	const MAX_RULES = 100;
	const MAX_ITEMS = 10;
	const MAX_VALUE = 500;

	const FIELDS = array('from', 'to', 'cc', 'tocc', 'subject', 'list', 'header');
	const OPS = array('contains', 'not', 'is', 'starts', 'ends');
	const ACTIONS = array('move', 'copy', 'tag', 'read', 'flag', 'delete', 'redirect');

	/** @var DoliDB */
	protected $db;

	/** @var string */
	public $error = '';

	/** @var string Code: not_found, no_manage, sieve_*, edited, invalid, server_refused, no_previous */
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
	 * @param string $detail Detail
	 * @return null
	 */
	protected function fail($code, $detail = '')
	{
		$this->errorCode = $code;
		$this->error = $detail !== '' ? $detail : $code;

		return null;
	}

	/**
	 * @return array<string,mixed> Out of office off, by default
	 */
	public static function defaultAway()
	{
		return array('on' => false, 'from' => '', 'to' => '', 'subject' => '', 'text' => '', 'days' => 1, 'addr' => array());
	}

	// ---------- Script ----------

	/**
	 * @param string $s Text
	 * @return string Quoted string of Sieve
	 */
	public static function q($s)
	{
		$s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', str_replace(array("\r\n", "\r"), "\n", (string) $s));

		return '"'.str_replace(array('\\', '"', "\n"), array('\\\\', '\\"', "\r\n"), $s).'"';
	}

	/**
	 * @param string $s Text of a :matches pattern (its * and ? are not wildcards)
	 * @return string
	 */
	protected static function literalPattern($s)
	{
		return str_replace(array('\\', '*', '?'), array('\\\\', '\\*', '\\?'), (string) $s);
	}

	/**
	 * @param string[] $list Strings
	 * @return string One string, or a list
	 */
	protected static function ql(array $list)
	{
		return count($list) === 1 ? self::q($list[0]) : '['.implode(', ', array_map(array(__CLASS__, 'q'), $list)).']';
	}

	/**
	 * Test of one condition
	 *
	 * @param array<string,string> $c Condition (f, op, v, h)
	 * @return string
	 */
	protected static function test(array $c)
	{
		$map = array('from' => array('from'), 'to' => array('to'), 'cc' => array('cc'), 'tocc' => array('to', 'cc'), 'subject' => array('subject'), 'list' => array('list-id'));
		$headers = $c['f'] === 'header' ? array(strtolower((string) $c['h'])) : $map[$c['f']];
		$address = in_array($c['f'], array('from', 'to', 'cc', 'tocc'), true);
		$v = (string) $c['v'];
		switch ($c['op']) {
			case 'is':
				$t = ($address ? 'address :is ' : 'header :is ').self::ql($headers).' '.self::q($v);
				break;
			case 'starts':
				$t = ($address ? 'address' : 'header').' :matches '.self::ql($headers).' '.self::q(self::literalPattern($v).'*');
				break;
			case 'ends':
				$t = ($address ? 'address' : 'header').' :matches '.self::ql($headers).' '.self::q('*'.self::literalPattern($v));
				break;
			default:
				// contains, not: in the whole header (the name and the address of a person)
				$t = 'header :contains '.self::ql($headers).' '.self::q($v);
		}

		return $c['op'] === 'not' ? 'not '.$t : $t;
	}

	/**
	 * The Sieve script of a set of rules
	 *
	 * @param array<int,array<string,mixed>> $rules      Rules (checked)
	 * @param array<string,mixed>            $away       Out of office (checked)
	 * @param array<string,string>           $folders    Real path of the folders: path => path (only those)
	 * @param string                         $trash      Path of the trash
	 * @param string[]                       $extensions Extensions of the server
	 * @param string                         $include    Script kept from before inbox, run after the rules ('' = none)
	 * @param array<string,mixed>            $meta       saved (timestamp), by (name)
	 * @return string
	 */
	public static function script(array $rules, array $away, array $folders, $trash, array $extensions, $include, array $meta)
	{
		$has = function ($e) use ($extensions) {
			return in_array($e, $extensions, true);
		};
		$req = array();
		$body = '';
		// Out of office first: it answers even to the mails the rules put away
		if (!empty($away['on']) && $has('vacation')) {
			$req['vacation'] = 1;
			$cond = array('not exists "list-id"', 'not header :contains "precedence" ["bulk", "list", "junk"]', 'not header :contains "auto-submitted" "auto-"',
				'not address :matches "from" ["*noreply*", "*no-reply*", "mailer-daemon@*"]');
			$dated = $has('date') && $has('relational');
			if ($dated) {
				$req['date'] = 1;
				$req['relational'] = 1;
				array_unshift($cond, 'currentdate :value "ge" "date" '.self::q($away['from']), 'currentdate :value "le" "date" '.self::q($away['to']));
			}
			$subject = str_replace('${', '$ {', (string) $away['subject']);
			$text = str_replace('${', '$ {', (string) $away['text']);
			$body .= "# Out of office\r\n";
			if ($has('variables')) {
				$req['variables'] = 1;
				$body .= "if header :matches \"subject\" \"*\" { set \"insubject\" \"\${1}\"; }\r\n";
				$subject .= ' : ${insubject}';
			}
			$body .= 'if allof('.implode(', ', $cond).") {\r\n\tvacation :days ".max(1, min(30, (int) $away['days']))
				.($away['addr'] ? ' :addresses '.self::ql(array_values($away['addr'])) : '').' :subject '.self::q($subject).' '.self::q($text).";\r\n}\r\n";
		}
		foreach ($rules as $r) {
			$body .= '# rule: '.str_replace(array("\r", "\n"), ' ', (string) $r['name']).(empty($r['on']) ? ' (off)' : '')."\r\n";
			if (empty($r['on'])) {
				continue;
			}
			$tests = array_map(array(__CLASS__, 'test'), $r['conds']);
			$if = count($tests) === 1 ? $tests[0] : ($r['all'] ? 'allof(' : 'anyof(').implode(', ', $tests).')';
			$flags = array();
			$lines = array();
			foreach ($r['acts'] as $a) {
				if ($a['a'] === 'read') {
					$flags[] = '\\Seen';
				} elseif ($a['a'] === 'flag') {
					$flags[] = '\\Flagged';
				} elseif ($a['a'] === 'tag') {
					$flags[] = (string) $a['v'];
				}
			}
			$flags = array_values(array_unique($flags));
			if ($flags) {
				$req['imap4flags'] = 1;
			}
			$filed = false;
			foreach ($r['acts'] as $a) {
				if (in_array($a['a'], array('move', 'copy', 'delete'), true)) {
					$req['fileinto'] = 1;
					$path = $a['a'] === 'delete' ? $trash : (string) $a['v'];
					$opts = '';
					if ($a['a'] === 'copy') {
						$req['copy'] = 1;
						$opts .= ' :copy';
					}
					if ($a['a'] !== 'delete' && $has('mailbox') && !isset($folders[$path])) {
						$req['mailbox'] = 1;
						$opts .= ' :create';
					}
					if ($flags) {
						$opts .= ' :flags '.self::ql($flags);
					}
					$lines[] = 'fileinto'.$opts.' '.self::q($path).';';
					$filed = $filed || $a['a'] !== 'copy';
				} elseif ($a['a'] === 'redirect') {
					if ($has('copy')) {
						$req['copy'] = 1;
						$lines[] = 'redirect :copy '.self::q($a['v']).';';
					} else {
						$lines[] = 'redirect '.self::q($a['v']).';';
						$lines[] = 'keep;';
					}
				}
			}
			if ($flags && !$filed) {
				// Not put away: the flags go with the mail kept in the inbox
				array_unshift($lines, 'addflag '.self::ql($flags).';');
			}
			if (!empty($r['stop'])) {
				$lines[] = 'stop;';
			}
			$body .= 'if '.$if." {\r\n\t".implode("\r\n\t", $lines)."\r\n}\r\n";
		}
		if ($include !== '' && $has('include')) {
			$req['include'] = 1;
			$body .= '# Rules kept from before inbox'."\r\ninclude :personal ".self::q($include).";\r\n";
		}
		$sieve = ($req ? 'require '.self::ql(array_keys($req)).";\r\n" : '').$body;
		$data = base64_encode((string) json_encode(array('rules' => array_values($rules), 'away' => $away, 'include' => $include), JSON_UNESCAPED_UNICODE));
		$head = "# inbox-rules v1: made by inbox for Dolibarr, change these rules in inbox\r\n"
			.'# data: '.$data."\r\n"
			.'# saved: '.((int) ($meta['saved'] ?? 0)).' '.base64_encode((string) ($meta['by'] ?? ''))."\r\n";

		return $head.'# sum: '.sha1($sieve)."\r\n".$sieve;
	}

	/**
	 * What inbox wrote in a script
	 *
	 * @param string|null $script Content
	 * @return array{rules:array<int,array<string,mixed>>,away:array<string,mixed>,include:string,saved:int,by:string,edited:bool}|null null when it is not a script of inbox
	 */
	public static function read($script)
	{
		$script = str_replace("\r\n", "\n", (string) $script);
		if (!preg_match('/^# inbox-rules v1[^\n]*\n# data: ([A-Za-z0-9+\/=]*)\n# saved: (\d+) ([A-Za-z0-9+\/=]*)\n# sum: ([0-9a-f]{40})\n/', $script, $m)) {
			return null;
		}
		$data = json_decode((string) base64_decode($m[1], true), true);
		if (!is_array($data)) {
			return null;
		}
		$sieve = str_replace("\n", "\r\n", substr($script, strlen($m[0])));

		return array('rules' => (array) ($data['rules'] ?? array()), 'away' => array_merge(self::defaultAway(), (array) ($data['away'] ?? array())),
			'include' => (string) ($data['include'] ?? ''), 'saved' => (int) $m[2], 'by' => (string) base64_decode($m[3]),
			// A change made outside inbox (another tool, by hand)
			'edited' => sha1($sieve) !== $m[4]);
	}

	// ---------- Checks ----------

	/**
	 * Rules sent by the screen, checked one by one: fields, operators, actions, folders and tags of the mailbox
	 *
	 * @param InboxAccount $account Mailbox
	 * @param mixed        $rules   Rules (decoded JSON)
	 * @return array<int,array<string,mixed>>|null
	 */
	public function cleanRules(InboxAccount $account, $rules)
	{
		if (!is_array($rules) || count($rules) > self::MAX_RULES) {
			return $this->fail('invalid', 'rules');
		}
		$tags = array();
		foreach ($this->tagsOf($account) as $t) {
			$tags[strtolower($t['keyword'])] = $t['keyword'];
		}
		$str = function ($v, $len = self::MAX_VALUE) {
			return mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]/', ' ', (string) $v)), 0, $len, 'UTF-8');
		};
		$out = array();
		foreach (array_values($rules) as $r) {
			if (!is_array($r)) {
				return $this->fail('invalid', 'rule');
			}
			$conds = array();
			foreach (array_slice((array) ($r['conds'] ?? array()), 0, self::MAX_ITEMS) as $c) {
				$f = (string) ($c['f'] ?? '');
				$op = (string) ($c['op'] ?? '');
				$v = $str($c['v'] ?? '');
				$h = strtolower((string) ($c['h'] ?? ''));
				if (!in_array($f, self::FIELDS, true) || !in_array($op, self::OPS, true) || $v === '' || ($f === 'header' && !preg_match('/^[a-z0-9][a-z0-9\-]{0,59}$/', $h))) {
					return $this->fail('invalid', 'condition');
				}
				$conds[] = array('f' => $f, 'op' => $op, 'v' => $v, 'h' => $f === 'header' ? $h : '');
			}
			$acts = array();
			foreach (array_slice((array) ($r['acts'] ?? array()), 0, self::MAX_ITEMS) as $a) {
				$type = (string) ($a['a'] ?? '');
				$v = $str($a['v'] ?? '', 255);
				if (!in_array($type, self::ACTIONS, true)) {
					return $this->fail('invalid', 'action');
				}
				if (($type === 'move' || $type === 'copy') && ($v === '' || !preg_match('/^[^\x00-\x1F"*%\\\\]+$/u', $v))) {
					return $this->fail('invalid', 'folder');
				}
				if ($type === 'tag') {
					if (!isset($tags[strtolower($v)])) {
						return $this->fail('invalid', 'tag');
					}
					$v = $tags[strtolower($v)];
				}
				if ($type === 'redirect' && !filter_var($v, FILTER_VALIDATE_EMAIL)) {
					return $this->fail('invalid', 'redirect');
				}
				$acts[] = in_array($type, array('read', 'flag', 'delete'), true) ? array('a' => $type) : array('a' => $type, 'v' => $type === 'redirect' ? strtolower($v) : $v);
			}
			if (!$conds || !$acts) {
				return $this->fail('invalid', 'empty');
			}
			$out[] = array('id' => max(1, (int) ($r['id'] ?? count($out) + 1)), 'name' => $str($r['name'] ?? '', 120), 'on' => !empty($r['on']), 'all' => !empty($r['all']),
				'conds' => $conds, 'acts' => $acts, 'stop' => !empty($r['stop']));
		}

		return $out;
	}

	/**
	 * @param InboxAccount $account Mailbox
	 * @param mixed        $away    Out of office (decoded JSON)
	 * @return array<string,mixed>|null
	 */
	public function cleanAway(InboxAccount $account, $away)
	{
		if (!is_array($away)) {
			return $this->fail('invalid', 'away');
		}
		$date = function ($d) {
			return preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $d) ? (string) $d : '';
		};
		$out = array('on' => !empty($away['on']), 'from' => $date($away['from'] ?? ''), 'to' => $date($away['to'] ?? ''),
			'subject' => mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]/', ' ', (string) ($away['subject'] ?? ''))), 0, 200, 'UTF-8'),
			'text' => mb_substr(str_replace("\r\n", "\n", (string) ($away['text'] ?? '')), 0, 5000, 'UTF-8'),
			'days' => max(1, min(30, (int) ($away['days'] ?? 1))), 'addr' => array());
		$mine = $this->addressesOf($account);
		foreach ((array) ($away['addr'] ?? array()) as $a) {
			if (in_array(strtolower((string) $a), $mine, true)) {
				$out['addr'][] = strtolower((string) $a);
			}
		}
		if ($out['on'] && ($out['from'] === '' || $out['to'] === '' || $out['to'] < $out['from'] || trim($out['text']) === '')) {
			return $this->fail('invalid', 'away');
		}

		return $out;
	}

	/**
	 * @param InboxAccount $account Mailbox
	 * @return string[] Its address and the ones of its identities, lower case
	 */
	public function addressesOf(InboxAccount $account)
	{
		$out = array(strtolower((string) $account->email));
		$rows = $this->db->getRows("SELECT email FROM ".$this->db->prefix()."inbox_identity WHERE fk_account = ".((int) $account->id)." LIMIT 200");
		foreach (is_array($rows) ? $rows : array() as $r) {
			$out[] = strtolower((string) $r->email);
		}

		return array_values(array_unique(array_filter($out)));
	}

	/**
	 * @param InboxAccount $account Mailbox
	 * @return array<int,array{path:string,name:string,role:string}> Folders of the index
	 */
	public function foldersOf(InboxAccount $account)
	{
		$rows = $this->db->getRows("SELECT path, name, role FROM ".$this->db->prefix()."inbox_folder WHERE fk_account = ".((int) $account->id)." AND status = 1 AND selectable = 1 ORDER BY path LIMIT 5000");

		return array_map(function ($r) {
			return array('path' => (string) $r->path, 'name' => (string) $r->name, 'role' => (string) $r->role);
		}, is_array($rows) ? $rows : array());
	}

	/**
	 * @param InboxAccount $account Mailbox
	 * @return array<int,array{keyword:string,label:string}>
	 */
	public function tagsOf(InboxAccount $account)
	{
		require_once __DIR__.'/inboxtag.class.php';
		$t = new InboxTag($this->db);

		return array_map(function ($x) {
			return array('keyword' => (string) $x['keyword'], 'label' => (string) $x['label']);
		}, $t->listForAccount($account, true));
	}

	// ---------- Server ----------

	/**
	 * @param User $user      User (level manage, or read for the banner only)
	 * @param int  $accountId Mailbox
	 * @param bool $manage    The level « manage » is needed
	 * @return InboxAccount|null
	 */
	public function account(User $user, $accountId, $manage = true)
	{
		$access = new InboxAccess($this->db);
		$level = $access->getLevel($user, (int) $accountId);
		if ($level === InboxAccess::LEVEL_NONE) {
			return $this->fail('not_found');
		}
		if ($manage && $level !== InboxAccess::LEVEL_MANAGE) {
			return $this->fail('no_manage');
		}
		$account = new InboxAccount($this->db);
		if ($account->fetch((int) $accountId) <= 0) {
			return $this->fail('not_found');
		}

		return $account;
	}

	/**
	 * @param InboxAccount $account Mailbox
	 * @return InboxManageSieve|null
	 */
	protected function session(InboxAccount $account)
	{
		$error = '';
		$connector = InboxConnectorFactory::forAccount($this->db, $account, $error);
		if (!$connector || !$connector->can(InboxConnectorInterface::CAP_RULES) || !method_exists($connector, 'sieveSession')) {
			return $this->fail('sieve_disabled', $error);
		}
		$code = '';
		$s = $connector->sieveSession($code);
		if (!$s) {
			return $this->fail($code !== '' ? $code : 'sieve_connect', $connector->getError());
		}

		return $s;
	}

	/**
	 * Rules and out of office of a mailbox, as the server holds them
	 *
	 * @param InboxAccount $account Mailbox
	 * @return array<string,mixed>|null state: ok (inbox, or nothing yet), edited (changed outside inbox: read only), foreign (another script active, kept)
	 */
	public function load(InboxAccount $account)
	{
		$s = $this->session($account);
		if (!$s) {
			return null;
		}
		try {
			$scripts = $s->listScripts();
			if ($scripts === null) {
				return $this->fail('server_refused', $s->error);
			}
			$active = '';
			$hasPrev = false;
			$hasInbox = false;
			foreach ($scripts as $x) {
				$active = $x['active'] ? $x['name'] : $active;
				$hasPrev = $hasPrev || $x['name'] === self::PREVIOUS;
				$hasInbox = $hasInbox || $x['name'] === self::SCRIPT;
			}
			$out = array('state' => 'ok', 'rules' => array(), 'away' => self::defaultAway(), 'include' => '', 'foreign' => '', 'saved' => 0, 'by' => '',
				'previous' => $hasPrev, 'extensions' => $s->extensions);
			if ($hasInbox) {
				$model = self::read($s->getScript(self::SCRIPT));
				if ($model === null) {
					// A script named « inbox » that inbox did not write
					$out['state'] = 'edited';
				} else {
					$out = array_merge($out, array_intersect_key($model, array_flip(array('rules', 'away', 'include', 'saved', 'by'))));
					$out['state'] = $model['edited'] ? 'edited' : 'ok';
				}
			}
			if ($active !== '' && $active !== self::SCRIPT) {
				// Active before inbox: kept, run after the rules of inbox once they are saved
				$out['foreign'] = $active;
				if ($out['state'] === 'ok' && !$hasInbox) {
					$out['state'] = 'foreign';
				}
			}

			return $out;
		} finally {
			$s->close();
		}
	}

	/**
	 * Content of a script of the server (to show it, read only)
	 *
	 * @param InboxAccount $account Mailbox
	 * @param string       $name    Script
	 * @return string|null
	 */
	public function scriptText(InboxAccount $account, $name)
	{
		$s = $this->session($account);
		if (!$s) {
			return null;
		}
		try {
			$text = $s->getScript((string) $name);
			return $text === null ? $this->fail('not_found', $s->error) : $text;
		} finally {
			$s->close();
		}
	}

	/**
	 * Save the rules and the out of office (both written in the same script)
	 *
	 * @param User                           $user     User (manage)
	 * @param InboxAccount                   $account  Mailbox
	 * @param array<int,array<string,mixed>> $rules    Rules (cleanRules)
	 * @param array<string,mixed>            $away     Out of office (cleanAway)
	 * @param bool                           $takeOver Replace a script changed outside inbox
	 * @param string                         $what     rules or away (journal)
	 * @return array<string,mixed>|null The new state (load)
	 */
	public function save(User $user, InboxAccount $account, array $rules, array $away, $takeOver, $what = 'rules')
	{
		$s = $this->session($account);
		if (!$s) {
			return null;
		}
		try {
			$scripts = $s->listScripts();
			if ($scripts === null) {
				return $this->fail('server_refused', $s->error);
			}
			$active = '';
			$current = null;
			foreach ($scripts as $x) {
				$active = $x['active'] ? $x['name'] : $active;
				if ($x['name'] === self::SCRIPT) {
					$current = $s->getScript(self::SCRIPT);
				}
			}
			$model = $current !== null ? self::read($current) : null;
			if ($current !== null && ($model === null || $model['edited']) && !$takeOver) {
				return $this->fail('edited');
			}
			$include = $model ? (string) $model['include'] : '';
			if ($active !== '' && $active !== self::SCRIPT && $active !== self::PREVIOUS) {
				$include = $active;
			}
			$folders = array();
			$trash = '';
			foreach ($this->foldersOf($account) as $f) {
				$folders[$f['path']] = $f['path'];
				if ($f['role'] === 'trash' && $trash === '') {
					$trash = $f['path'];
				}
			}
			if ($trash === '') {
				$trash = 'Trash';
			}
			$name = trim($user->firstname.' '.$user->lastname);
			$script = self::script($rules, $away, $folders, $trash, $s->extensions, $include, array('saved' => dol_now(), 'by' => $name !== '' ? $name : $user->login));
			if (!$s->checkScript($script)) {
				return $this->fail('server_refused', $s->error);
			}
			if ($current !== null && !$s->putScript(self::PREVIOUS, $current)) {
				return $this->fail('server_refused', $s->error);
			}
			if (!$s->putScript(self::SCRIPT, $script) || !$s->setActive(self::SCRIPT)) {
				return $this->fail('server_refused', $s->error);
			}
		} finally {
			$s->close();
		}
		$this->afterSave($user, $account, $rules, $away, $model, $what);

		return $this->load($account);
	}

	/**
	 * Back to the previous version (the current one becomes the previous)
	 *
	 * @param User         $user    User (manage)
	 * @param InboxAccount $account Mailbox
	 * @return array<string,mixed>|null
	 */
	public function undo(User $user, InboxAccount $account)
	{
		$s = $this->session($account);
		if (!$s) {
			return null;
		}
		try {
			$prev = $s->getScript(self::PREVIOUS);
			if ($prev === null) {
				return $this->fail('no_previous');
			}
			$current = $s->getScript(self::SCRIPT);
			if (!$s->putScript(self::SCRIPT, $prev) || !$s->setActive(self::SCRIPT) || ($current !== null && !$s->putScript(self::PREVIOUS, $current))) {
				return $this->fail('server_refused', $s->error);
			}
		} finally {
			$s->close();
		}
		$model = self::read($prev);
		$this->afterSave($user, $account, $model ? $model['rules'] : array(), $model ? $model['away'] : self::defaultAway(), null, 'rules_undo');

		return $this->load($account);
	}

	/**
	 * Out of office known by the index (banner of the application), journal, administrators told of a new
	 * redirection outside
	 *
	 * @param User                           $user    User
	 * @param InboxAccount                   $account Mailbox
	 * @param array<int,array<string,mixed>> $rules   Rules saved
	 * @param array<string,mixed>            $away    Out of office saved
	 * @param array<string,mixed>|null       $before  Previous model
	 * @param string                         $what    rules, away, rules_undo
	 * @return void
	 */
	protected function afterSave(User $user, InboxAccount $account, array $rules, array $away, $before, $what)
	{
		$p = $this->db->prefix();
		$this->db->query("UPDATE ".$p."inbox_account SET away_from = ".(!empty($away['on']) && $away['from'] !== '' ? "'".$this->db->escape($away['from'])."'" : 'NULL').","
			." away_to = ".(!empty($away['on']) && $away['to'] !== '' ? "'".$this->db->escape($away['to'])."'" : 'NULL')." WHERE rowid = ".((int) $account->id));
		$summary = $what === 'away' ? (!empty($away['on']) ? $away['from'].' > '.$away['to'] : 'off') : count($rules).' rule(s)';
		InboxActionLog::add($this->db, $user, (int) $account->id, $what === 'away' ? 'away' : 'rules', array((object) array('message_id' => '', 'subject' => '')), '', '', 0, $summary);
		// A redirection to an address outside the mailboxes: the administrators of inbox are told (risk of leak)
		$old = array();
		foreach ((array) ($before['rules'] ?? array()) as $r) {
			foreach ((array) $r['acts'] as $a) {
				if ($a['a'] === 'redirect') {
					$old[] = $a['v'];
				}
			}
		}
		$domains = array();
		$rows = $this->db->getRows("SELECT email FROM ".$p."inbox_account WHERE status = 1 LIMIT 1000");
		foreach (is_array($rows) ? $rows : array() as $r) {
			$domains[strtolower(substr(strrchr((string) $r->email, '@'), 1))] = 1;
		}
		foreach ($rules as $r) {
			foreach ($r['acts'] as $a) {
				if ($a['a'] === 'redirect' && !in_array($a['v'], $old, true) && !isset($domains[strtolower(substr(strrchr($a['v'], '@'), 1))])) {
					require_once __DIR__.'/inboxnotification.class.php';
					$notify = new InboxNotification($this->db);
					$admins = $this->db->getRows("SELECT rowid FROM ".$p."user WHERE admin = 1 AND statut = 1 LIMIT 50");
					foreach (is_array($admins) ? $admins : array() as $u) {
						$notify->add((int) $u->rowid, 'rule_redirect', array('account' => (int) $account->id, 'actor' => (int) $user->id,
							'url' => '#/settings/accounts/'.((int) $account->id).'/rules', 'payload' => array('subject' => $a['v'], 'text' => '')));
					}
				}
			}
		}
	}

	// ---------- The mails already received ----------

	/**
	 * Mails of the inbox of the last days that a rule concerns, from the index (a header the index does not hold:
	 * no preview)
	 *
	 * @param InboxAccount        $account Mailbox
	 * @param array<string,mixed> $rule    Rule (checked)
	 * @param int                 $days    Days back
	 * @param int                 $limit   Mails listed
	 * @return array{supported:bool,count:int,items:array<int,array<string,mixed>>,ids:int[]}
	 */
	public function preview(InboxAccount $account, array $rule, $days = 30, $limit = 5)
	{
		$p = $this->db->prefix();
		$parts = array();
		foreach ($rule['conds'] as $c) {
			if (in_array($c['f'], array('list', 'header'), true)) {
				return array('supported' => false, 'count' => 0, 'items' => array(), 'ids' => array());
			}
			$v = $this->db->escape($this->db->escapeforlike(mb_strtolower($c['v'], 'UTF-8')));
			$pattern = array('contains' => "'%".$v."%'", 'not' => "'%".$v."%'", 'is' => "'".$v."'", 'starts' => "'".$v."%'", 'ends' => "'%".$v."'")[$c['op']];
			if ($c['f'] === 'subject') {
				$t = "LOWER(m.subject) LIKE ".$pattern;
			} elseif ($c['f'] === 'from') {
				$t = $c['op'] === 'contains' || $c['op'] === 'not' ? "(LOWER(m.from_email) LIKE ".$pattern." OR LOWER(m.from_name) LIKE ".$pattern.")" : "LOWER(m.from_email) LIKE ".$pattern;
			} else {
				$types = $c['f'] === 'tocc' ? "'to', 'cc'" : "'".$c['f']."'";
				$t = "EXISTS (SELECT a.rowid FROM ".$p."inbox_mail_address as a WHERE a.fk_mail = m.rowid AND a.type IN (".$types.") AND (LOWER(a.email) LIKE ".$pattern
					.($c['op'] === 'contains' || $c['op'] === 'not' ? " OR LOWER(a.name) LIKE ".$pattern : '')."))";
			}
			$parts[] = $c['op'] === 'not' ? 'NOT '.$t : $t;
		}
		$where = " FROM ".$p."inbox_mail as m INNER JOIN ".$p."inbox_folder as f ON f.rowid = m.fk_folder AND f.role = 'inbox'"
			." WHERE m.fk_account = ".((int) $account->id)." AND m.date_received > '".$this->db->idate(dol_now() - $days * 86400)."'"
			." AND (".implode($rule['all'] ? ' AND ' : ' OR ', $parts).")";
		$count = $this->db->getRow("SELECT COUNT(m.rowid) as n".$where);
		$rows = $this->db->getRows("SELECT m.rowid, m.from_email, m.from_name, m.subject, m.date_received".$where." ORDER BY m.date_received DESC LIMIT 500");
		$items = array();
		$ids = array();
		foreach (is_array($rows) ? $rows : array() as $r) {
			$ids[] = (int) $r->rowid;
			if (count($items) < $limit) {
				$items[] = array('id' => (int) $r->rowid, 'from' => (string) ($r->from_name ?: $r->from_email), 'subject' => (string) $r->subject, 'date' => (int) $this->db->jdate($r->date_received));
			}
		}

		return array('supported' => true, 'count' => is_object($count) ? (int) $count->n : 0, 'items' => $items, 'ids' => $ids);
	}

	/**
	 * Apply a rule once to the mails already received (by the actions of inbox: written on the server, in the journal)
	 *
	 * @param User                $user    User (manage)
	 * @param InboxAccount        $account Mailbox
	 * @param array<string,mixed> $rule    Rule (checked)
	 * @return array{count:int}|null
	 */
	public function apply(User $user, InboxAccount $account, array $rule)
	{
		require_once __DIR__.'/inboxactions.class.php';
		$prev = $this->preview($account, $rule, 30, 0);
		if (!$prev['supported']) {
			return $this->fail('invalid', 'preview');
		}
		$ids = $prev['ids'];
		if (!$ids) {
			return array('count' => 0);
		}
		$actions = new InboxActions($this->db);
		$folders = array();
		$rows = $this->db->getRows("SELECT rowid, path, role FROM ".$this->db->prefix()."inbox_folder WHERE fk_account = ".((int) $account->id)." AND status = 1 LIMIT 5000");
		foreach (is_array($rows) ? $rows : array() as $r) {
			$folders[(string) $r->path] = (int) $r->rowid;
		}
		$tags = array();
		$move = 0;
		$delete = false;
		foreach ($rule['acts'] as $a) {
			if ($a['a'] === 'read' || $a['a'] === 'flag') {
				$actions->run($user, $a['a'] === 'read' ? 'seen' : 'flag', $ids, array('on' => 1));
			} elseif ($a['a'] === 'tag') {
				$tags[] = $a['v'];
			} elseif ($a['a'] === 'move' && isset($folders[$a['v']])) {
				$move = $folders[$a['v']];
			} elseif ($a['a'] === 'delete') {
				$delete = true;
			}
		}
		if ($tags) {
			$actions->run($user, 'tag', $ids, array('add' => $tags));
		}
		// Last: the mails leave the inbox
		if ($delete) {
			$res = $actions->run($user, 'delete', $ids, array());
		} elseif ($move) {
			$res = $actions->run($user, 'move', $ids, array('folder' => $move));
		}

		return array('count' => count($ids), 'error' => isset($res) && !$res['ok'] ? (string) $res['error'] : '');
	}
}
