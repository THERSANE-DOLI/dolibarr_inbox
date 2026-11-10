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
 * \file    inbox/class/inboxcited.class.php
 * \ingroup inbox
 * \brief   Pieces cited in a mail (1.46.0): references found with the numbering models, checked against the thirdparty
 *          of the sender (a thirdparty under Dolibarr may number its own pieces like ours)
 */

require_once __DIR__.'/inboxelements.class.php';
require_once __DIR__.'/inboxcontactmatcher.class.php';

/**
 * Suggestions only, never a link made alone: the user links with one click.
 * match = same (a piece of the thirdparty of the sender, its parent or subsidiaries), other (another thirdparty: never
 * suggested alone, shown with a warning in « Link »), unknown (no thirdparty known for the sender).
 */
class InboxCited
{
	/** Type of inbox => constant of the numbering module in use and directory of the numbering modules */
	const NUMBERING = array(
		'propal' => array('PROPALE_ADDON', 'propale'),
		'commande' => array('COMMANDE_ADDON', 'commande'),
		'facture' => array('FACTURE_ADDON', 'facture'),
		'order_supplier' => array('COMMANDE_SUPPLIER_ADDON_NUMBER', 'supplier_order'),
		'invoice_supplier' => array('INVOICE_SUPPLIER_ADDON_NUMBER', 'supplier_invoice'),
		'supplier_proposal' => array('SUPPLIER_PROPOSAL_ADDON', 'supplier_proposal'),
		'project' => array('PROJECT_ADDON', 'project'),
		'ticket' => array('TICKET_ADDON', 'ticket'),
		'fichinter' => array('FICHEINTER_ADDON', 'fichinter'),
		'shipping' => array('EXPEDITION_ADDON_NUMBER', 'expedition'),
	);

	/** References of the other side kept on our pieces (« its ref. »), searched for the thirdparty of the sender only */
	const THEIR_REFS = array('propal' => 'ref_client', 'commande' => 'ref_client', 'facture' => 'ref_client', 'order_supplier' => 'ref_supplier',
		'invoice_supplier' => 'ref_supplier', 'shipping' => 'ref_customer');

	/** Text read: start of the body, enough for the request and the quoted history */
	const TEXT_MAX = 60000;

	/** References kept per mail */
	const MAX = 12;

	/** @var DoliDB */
	protected $db;

	/** @var array<string,string>|null Regex by type, for the request */
	protected static $patterns = null;

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct(DoliDB $db)
	{
		$this->db = $db;
	}

	/**
	 * Patterns read again (tests, setup changed in the request)
	 *
	 * @return void
	 */
	public static function reset()
	{
		self::$patterns = null;
	}

	/**
	 * Regex of a reference from an example of its numbering module: letters and separators as they are, each run of
	 * digits as digits of that length or up to 2 more (a counter grows). Null when the example has no letter to start
	 * with (too many false positives) or no digit.
	 *
	 * @param string $example Example (CF2699-00001, DEVIS2611001, FO-000001)
	 * @return string|null Regex without delimiters
	 */
	public static function patternOf($example)
	{
		$example = trim((string) $example);
		if (!preg_match('/^[A-Za-z]{2,}/', $example) || !preg_match('/\d/', $example) || !preg_match('/^[A-Za-z0-9\-_\/.]{4,40}$/', $example)) {
			return null;
		}
		$out = '';
		foreach (preg_split('/(\d+)/', $example, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) as $part) {
			if (ctype_digit($part)) {
				$n = strlen($part);
				$out .= '\d{'.$n.','.($n + 2).'}';
			} else {
				$out .= preg_quote($part, '~');
			}
		}

		return '(?<![A-Za-z0-9])'.$out.'(?![0-9])';
	}

	/**
	 * Regex by type, from the numbering modules in use (their example)
	 *
	 * @return array<string,string>
	 */
	public function patterns()
	{
		global $conf;

		if (self::$patterns !== null) {
			return self::$patterns;
		}
		self::$patterns = array();
		foreach (self::NUMBERING as $type => $numbering) {
			if (!InboxElements::enabled($type)) {
				continue;
			}
			$module = preg_replace('/\.php$/', '', getDolGlobalString($numbering[0]));
			if ($module === '' || !preg_match('/^[a-z0-9_]+$/i', $module)) {
				continue;
			}
			foreach (array_merge(array('/'), (array) ($conf->modules_parts['models'] ?? array())) as $reldir) {
				$file = dol_buildpath($reldir.'core/modules/'.$numbering[1].'/'.$module.'.php', 0);
				if (!file_exists($file)) {
					continue;
				}
				try {
					// A numbering module may print or warn while making its example
					ob_start();
					require_once $file;
					$example = '';
					if (class_exists($module)) {
						$obj = new $module($this->db);
						$example = method_exists($obj, 'getExample') ? (string) $obj->getExample() : '';
					}
					ob_end_clean();
					$regex = self::patternOf($example);
					if ($regex !== null) {
						self::$patterns[$type] = $regex;
					}
				} catch (Throwable $e) {
					ob_end_clean();
					dol_syslog(__METHOD__.' '.$module.': '.$e->getMessage(), LOG_WARNING);
				}
				break;
			}
		}

		return self::$patterns;
	}

	/**
	 * References found in a text, by type
	 *
	 * @param string $text Text
	 * @return array<string,string[]>
	 */
	public function references($text)
	{
		$out = array();
		foreach ($this->patterns() as $type => $regex) {
			$m = array();
			if (preg_match_all('~'.$regex.'~i', $text, $m)) {
				$out[$type] = array_slice(array_values(array_unique($m[0])), 0, self::MAX);
			}
		}

		return $out;
	}

	/**
	 * Domains of the mailboxes of inbox: a sender of these is one of us (an internal transfer)
	 *
	 * @return string[]
	 */
	public function ownDomains()
	{
		$out = array();
		$rows = $this->db->getRows("SELECT DISTINCT email FROM ".$this->db->prefix()."inbox_account WHERE email <> '' LIMIT 500");
		foreach (is_array($rows) ? $rows : array() as $r) {
			$at = strrpos((string) $r->email, '@');
			if ($at !== false) {
				$out[] = strtolower(substr((string) $r->email, $at + 1));
			}
		}

		return array_values(array_unique($out));
	}

	/**
	 * Thirdparties of the one who writes: the sender; when the sender is one of us (transfer), the sender of the mail
	 * transferred (« From: » in the body), else the other people of the mail; then the parents and subsidiaries
	 *
	 * @param string   $from   Address of the sender
	 * @param string   $text   Text of the body
	 * @param string[] $others Addresses of the recipients
	 * @return int[]
	 */
	public function writerSocs($from, $text, array $others)
	{
		$own = $this->ownDomains();
		$isOwn = function ($email) use ($own) {
			$at = strrpos($email, '@');
			return $at === false || in_array(strtolower(substr($email, $at + 1)), $own, true);
		};
		$candidates = array();
		$from = strtolower(trim((string) $from));
		if ($from !== '' && !$isOwn($from)) {
			$candidates[] = $from;
		} else {
			$m = array();
			if (preg_match_all('/^[>\s]*(?:De|From|Von|Da|Desde|Envoyé par)\s*:.*?([A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,})/mi', $text, $m)) {
				foreach ($m[1] as $email) {
					if (!$isOwn(strtolower($email))) {
						$candidates[] = strtolower($email);
						break;
					}
				}
			}
			if (!$candidates) {
				foreach ($others as $email) {
					$email = strtolower(trim($email));
					if ($email !== '' && !$isOwn($email)) {
						$candidates[] = $email;
					}
				}
			}
		}
		$matcher = new InboxContactMatcher($this->db);
		$socs = array();
		foreach (array_slice(array_unique($candidates), 0, 5) as $email) {
			$found = $matcher->match($email);
			$soc = (int) $found['fk_soc'] ?: $matcher->matchDomain($email);
			if ($soc > 0) {
				$socs[] = $soc;
			}
		}
		if (!$socs) {
			return array();
		}
		$ids = implode(',', array_map('intval', array_unique($socs)));
		$p = $this->db->prefix();
		$rows = $this->db->getRows("SELECT rowid FROM ".$p."societe WHERE parent IN (".$ids.") OR rowid IN (SELECT s.parent FROM ".$p."societe as s WHERE s.rowid IN (".$ids.") AND s.parent > 0) LIMIT 200");
		foreach (is_array($rows) ? $rows : array() as $r) {
			$socs[] = (int) $r->rowid;
		}

		return array_values(array_unique(array_map('intval', $socs)));
	}

	/**
	 * Shipments whose tracking number is in the text (1.70.0): words of 8 to 35 letters and digits with 6 digits at
	 * least, compared with the tracking numbers of the shipments (as written and in capitals)
	 *
	 * @param string $text Text of the mail
	 * @return array<int,string> Shipment => tracking number
	 */
	public function trackings($text)
	{
		$m = array();
		preg_match_all('/(?<![\p{L}\p{N}])[A-Za-z0-9]{8,35}(?![\p{L}\p{N}])/u', (string) $text, $m);
		$words = array();
		foreach ($m[0] as $w) {
			if (preg_match_all('/\d/', $w) >= 6) {
				$words[strtoupper($w)] = true;
				$words[$w] = true;
			}
			if (count($words) >= 400) {
				break;
			}
		}
		if (!$words) {
			return array();
		}
		$in = implode(',', array_map(function ($w) {
			return "'".$this->db->escape((string) $w)."'";
		}, array_keys($words)));
		$rows = $this->db->getRows("SELECT rowid, tracking_number FROM ".$this->db->prefix()."expedition WHERE tracking_number IN (".$in.") AND entity IN (".getEntity('expedition').") ORDER BY rowid DESC LIMIT ".self::MAX);
		$out = array();
		foreach (is_array($rows) ? $rows : array() as $r) {
			$out[(int) $r->rowid] = (string) $r->tracking_number;
		}

		return $out;
	}

	/**
	 * Pieces cited in the text: our references (numbering models), and the references of the other side kept on the
	 * pieces of its thirdparty; only the readable, with their match against the thirdparty of the writer
	 *
	 * @param User   $user User
	 * @param string $text Subject, body, names of the attachments
	 * @param int[]  $socs Thirdparties of the writer (writerSocs)
	 * @return array<int,array<string,mixed>> Pieces (InboxElements::describe) with cited (reference read), theirs (bool), match
	 */
	public function find(User $user, $text, array $socs)
	{
		$text = mb_substr((string) $text, 0, self::TEXT_MAX, 'UTF-8');
		$elements = new InboxElements($this->db);
		$p = $this->db->prefix();
		$found = array();
		foreach ($this->references($text) as $type => $refs) {
			if (!InboxElements::canReadType($user, $type)) {
				continue;
			}
			$t = InboxElements::TYPES[$type];
			// As written and in capitals (a column may compare with the case)
			$in = implode(',', array_map(function ($r) {
				return "'".$this->db->escape($r)."'";
			}, array_values(array_unique(array_merge($refs, array_map('strtoupper', $refs))))));
			$rows = $this->db->getRows("SELECT t.rowid, t.ref, t.".$t['soc']." as soc FROM ".$p.$t['table']." as t WHERE t.ref IN (".$in.") AND t.entity IN (".getEntity($t['entity']).") LIMIT ".self::MAX);
			foreach (is_array($rows) ? $rows : array() as $r) {
				$found[$type.':'.$r->rowid] = array('type' => $type, 'id' => (int) $r->rowid, 'soc' => (int) $r->soc, 'cited' => (string) $r->ref, 'theirs' => false);
			}
		}
		// Its reference (« CC00024342 ») kept on our piece: sure, the thirdparty is the one of the writer
		if ($socs) {
			$lower = mb_strtolower($text, 'UTF-8');
			$ids = implode(',', array_map('intval', $socs));
			foreach (self::THEIR_REFS as $type => $field) {
				if (!InboxElements::enabled($type) || !InboxElements::canReadType($user, $type)) {
					continue;
				}
				$t = InboxElements::TYPES[$type];
				$rows = $this->db->getRows("SELECT t.rowid, t.".$field." as theirs, t.".$t['soc']." as soc FROM ".$p.$t['table']." as t"
					." WHERE t.".$t['soc']." IN (".$ids.") AND t.".$field." IS NOT NULL AND t.".$field." <> '' AND t.entity IN (".getEntity($t['entity']).")"
					." ORDER BY t.rowid DESC LIMIT 300");
				foreach (is_array($rows) ? $rows : array() as $r) {
					$theirs = trim((string) $r->theirs);
					if (dol_strlen($theirs) < 4 || !preg_match('/\d/', $theirs)) {
						continue;
					}
					$needle = mb_strtolower($theirs, 'UTF-8');
					if (preg_match('~(?<![\p{L}\p{N}])'.preg_quote($needle, '~').'(?![\p{L}\p{N}])~u', $lower)) {
						$key = $type.':'.$r->rowid;
						$found[$key] = array('type' => $type, 'id' => (int) $r->rowid, 'soc' => (int) $r->soc, 'cited' => $theirs, 'theirs' => true);
					}
				}
			}
		}
		// Tracking numbers of our shipments (1.70.0): the carrier writes about a parcel; the shipment and its customer,
		// whoever writes
		if (InboxElements::canReadType($user, 'shipping')) {
			foreach ($this->trackings($text) as $id => $number) {
				$found['shipping:'.$id] = array('type' => 'shipping', 'id' => (int) $id, 'soc' => 0, 'cited' => $number, 'theirs' => false, 'tracking' => true);
			}
		}
		$out = array();
		foreach ($found as $f) {
			$d = $elements->describe($user, $f['type'], $f['id']);
			if (!$d || !$d['readable']) {
				continue;
			}
			$d['cited'] = $f['cited'];
			$d['theirs'] = $f['theirs'];
			$d['tracking'] = !empty($f['tracking']);
			$d['match'] = $d['tracking'] ? 'tracking' : (!$socs ? 'unknown' : (in_array($f['soc'], $socs, true) ? 'same' : 'other'));
			$out[] = $d;
			if (count($out) >= self::MAX) {
				break;
			}
		}
		// Its own reference first, then the pieces of the writer, the unknown, the others
		$rank = array('tracking' => 0, 'same' => 0, 'unknown' => 1, 'other' => 2);
		usort($out, function ($a, $b) use ($rank) {
			return array((int) !$a['theirs'], $rank[$a['match']]) <=> array((int) !$b['theirs'], $rank[$b['match']]);
		});

		return $out;
	}
}
