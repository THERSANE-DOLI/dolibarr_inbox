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
 * \file    inbox/class/inboxbankcheck.class.php
 * \ingroup inbox
 * \brief   Bank details in a mail (1.75.0): change of IBAN, new bank details. Never « all is fine »: a mailbox
 *          whose password was stolen sends authenticated mails from the real address, in a real conversation.
 */

require_once __DIR__.'/inboxsituation.class.php';
require_once __DIR__.'/inboxelements.class.php';

/**
 * The mail talks of bank details (an IBAN in the text, words in 5 languages, an attachment named RIB / IBAN): the
 * reading shows a lesson, not a verdict. Red when the sender is not authenticated or imitates a known one, orange
 * otherwise, never green. The signals are listed one by one, each one said for what it is (a doubt, not a proof),
 * with the numbers of the cards of Dolibarr to call, never the ones of the mail.
 */
class InboxBankCheck
{
	/** Words of a change of bank details (lower case, without accents) */
	const WORDS = array(
		'coordonnees bancaires', 'changement de rib', 'nouveau rib', 'nouvel iban', 'nouvelle banque', 'releve d\'identite bancaire', 'changement de banque', 'changement de compte',
		'bank details', 'banking details', 'new bank account', 'change of bank', 'updated bank', 'new iban', 'account details have changed', 'remittance details',
		'bankverbindung', 'kontoanderung', 'neue iban', 'neues konto', 'bankdaten',
		'coordinate bancarie', 'nuovo iban', 'nuovo conto', 'cambio di banca', 'estremi bancari',
		'datos bancarios', 'nueva cuenta', 'nuevo iban', 'cambio de cuenta', 'cambio de banco', 'cuenta bancaria',
	);

	/** Words of pressure: urgency, secrecy, a phone call refused */
	const PRESSURE = array(
		'urgent', 'immediatement', 'des aujourd\'hui', 'avant ce soir', 'confidentiel', 'ne pas appeler', 'injoignable', 'en deplacement',
		'urgently', 'immediately', 'by today', 'confidential', 'do not call', 'unreachable', 'travelling',
		'dringend', 'sofort', 'noch heute', 'vertraulich', 'nicht anrufen', 'nicht erreichbar',
		'urgente', 'immediatamente', 'entro oggi', 'riservato', 'non chiamare', 'non raggiungibile',
		'inmediatamente', 'hoy mismo', 'confidencial', 'no llame', 'no disponible',
	);

	/** Names of attachments holding bank details */
	const FILE_NAMES = '/(^|[^a-z])(rib|iban|bic|bank|banque|banca|banco|bancaire|bancari)([^a-z]|$)/i';

	/** @var DoliDB */
	protected $db;

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct(DoliDB $db)
	{
		$this->db = $db;
	}

	/**
	 * @param string $text Text
	 * @return string       Lower case, without accents, one space between words
	 */
	protected static function norm($text)
	{
		// Invisible characters cutting a word (zero width, soft hyphen) never hide it (audit INBX-053)
		return (string) preg_replace('/\s+/u', ' ', strtolower(dol_string_unaccent((string) preg_replace('/[\p{Cf}\x{00AD}]/u', '', (string) $text))));
	}

	/**
	 * IBANs written in a text, checked by their key (ISO 13616, modulo 97)
	 *
	 * @param string $text Text
	 * @return string[]     Compact, upper case, without double
	 */
	public static function ibans($text)
	{
		$out = array();
		$m = array();
		// Groups separated by any space, dot or dash (audit INBX-053), invisible characters removed first
		$text = (string) preg_replace('/[\p{Cf}\x{00AD}]/u', '', (string) $text);
		if (preg_match_all('/\b([A-Z]{2}\d{2}(?:[\s.\-\x{00A0}\x{2007}\x{2009}\x{202F}]?[A-Z0-9]{4}){2,7}(?:[\s.\-\x{00A0}\x{2007}\x{2009}\x{202F}]?[A-Z0-9]{1,4})?)\b/u', strtoupper($text), $m)) {
			foreach ($m[1] as $raw) {
				$iban = (string) preg_replace('/[^A-Z0-9]/', '', $raw);
				if (strlen($iban) >= 15 && strlen($iban) <= 34 && self::validIban($iban)) {
					$out[$iban] = $iban;
				}
			}
		}

		return array_values($out);
	}

	/**
	 * @param string $iban Compact IBAN
	 * @return bool         Its key is right
	 */
	public static function validIban($iban)
	{
		$s = substr($iban, 4).substr($iban, 0, 4);
		$digits = '';
		foreach (str_split($s) as $c) {
			$digits .= ctype_alpha($c) ? (string) (ord($c) - 55) : $c;
		}
		$rest = 0;
		foreach (str_split($digits, 7) as $chunk) {
			$rest = (int) (($rest.$chunk) % 97);
		}

		return $rest === 1;
	}

	/**
	 * An IBAN as shown: its country and its last 4 characters only
	 *
	 * @param string $iban Compact IBAN
	 * @return string
	 */
	public static function mask($iban)
	{
		return substr($iban, 0, 4).' •••• '.substr($iban, -4);
	}

	/**
	 * Does the mail talk of bank details?
	 *
	 * @param string   $text  Subject and text of the mail
	 * @param string[] $files Names of its attachments
	 * @return array{ibans:string[],words:bool,files:string[]}|null Null when it does not
	 */
	public static function topic($text, array $files)
	{
		$ibans = self::ibans($text);
		$norm = self::norm($text);
		$words = false;
		foreach (self::WORDS as $w) {
			if (strpos($norm, $w) !== false) {
				$words = true;
				break;
			}
		}
		$named = array_values(array_filter($files, function ($f) {
			return (bool) preg_match(self::FILE_NAMES, (string) pathinfo((string) $f, PATHINFO_FILENAME));
		}));
		if (!$ibans && !$words && !$named) {
			return null;
		}

		return array('ibans' => $ibans, 'words' => $words, 'files' => $named);
	}

	/**
	 * The lesson of a mail that talks of bank details
	 *
	 * @param User                $user    Reader
	 * @param object              $row     Mail of the index (rowid, fk_account, from_email, fk_soc, fk_socpeople)
	 * @param string              $text    Subject and text of the mail (quoted mails included: a reply may carry it)
	 * @param string[]            $files   Names of the attachments
	 * @param bool                $passed  Sender authenticated (InboxReader::authPass)
	 * @param array<string,mixed>|null $sender Verdict of the known senders (InboxReader::senderCheck)
	 * @param string              $replyTo Reply-To of the mail
	 * @return array<string,mixed>|null   Null when the mail does not talk of bank details
	 */
	public function check(User $user, $row, $text, array $files, $passed, $sender, $replyTo)
	{
		$topic = self::topic($text, $files);
		if ($topic === null) {
			return null;
		}
		$from = strtolower((string) $row->from_email);
		$socId = (int) $row->fk_soc;
		$contactId = (int) $row->fk_socpeople;
		$canSoc = $socId > 0 && InboxElements::canRead($user, 'societe', $socId);
		$canContact = $contactId > 0 && InboxElements::canRead($user, 'contact', $contactId);
		$signals = array();
		$imitation = is_array($sender) && ($sender['state'] ?? '') === 'imitation';

		// 1. Authentication of the sender: a lack is serious, a success proves only the domain
		$signals[] = array('id' => $passed ? 'auth_ok' : 'auth_bad', 'state' => $passed ? 'ok' : 'bad');
		if ($imitation) {
			$signals[] = array('id' => 'imitation', 'state' => 'bad', 'args' => array((string) ($sender['name'] ?? '')));
		}
		// 2. The IBAN of the mail against the ones of the card of the thirdparty
		$known = array();
		if ($canSoc) {
			$rows = $this->db->getRows("SELECT iban_prefix FROM ".$this->db->prefix()."societe_rib WHERE fk_soc = ".$socId." AND type = 'ban' LIMIT 20");
			foreach (is_array($rows) ? $rows : array() as $r) {
				$k = (string) preg_replace('/[^A-Z0-9]/', '', strtoupper((string) $r->iban_prefix));
				if ($k !== '') {
					$known[] = $k;
				}
			}
		}
		// An IBAN already on the card, no word of a change, no file (the foot of an invoice): nothing to say
		if (!$topic['words'] && !$topic['files'] && $topic['ibans'] && !array_diff($topic['ibans'], $known)) {
			return null;
		}
		if ($topic['ibans']) {
			$other = array_values(array_diff($topic['ibans'], $known));
			if (!$canSoc) {
				$signals[] = array('id' => 'iban_nosoc', 'state' => 'warn', 'args' => array(self::mask($topic['ibans'][0])));
			} elseif (!$known) {
				$signals[] = array('id' => 'iban_none', 'state' => 'warn', 'args' => array(self::mask($topic['ibans'][0])));
			} elseif ($other) {
				$signals[] = array('id' => 'iban_differs', 'state' => 'warn', 'args' => array(self::mask($other[0]), self::mask($known[0])));
			} else {
				$signals[] = array('id' => 'iban_same', 'state' => 'ok', 'args' => array(self::mask($topic['ibans'][0])));
			}
		} elseif ($topic['files']) {
			$signals[] = array('id' => 'iban_file', 'state' => 'warn', 'args' => array(implode(', ', array_slice($topic['files'], 0, 3))));
		}
		// 3. The phones of the mail: never the ones to call; one not on the cards, a doubt more
		$reach = array();
		if ($canContact) {
			$r = (new InboxSituation($this->db))->reach('contact', $contactId);
			$name = $this->db->getRow("SELECT firstname, lastname FROM ".$this->db->prefix()."socpeople WHERE rowid = ".$contactId);
			foreach (array('phone', 'mobile') as $k) {
				if (is_array($r) && $r[$k] !== '') {
					$reach[] = array('who' => is_object($name) ? trim($name->firstname.' '.$name->lastname) : '', 'number' => $r[$k], 'mobile' => $k === 'mobile');
				}
			}
		}
		if ($canSoc) {
			$r = (new InboxSituation($this->db))->reach('societe', $socId);
			$name = $this->db->getRow("SELECT nom FROM ".$this->db->prefix()."societe WHERE rowid = ".$socId);
			foreach (array('phone', 'mobile') as $k) {
				if (is_array($r) && $r[$k] !== '') {
					$reach[] = array('who' => is_object($name) ? (string) $name->nom : '', 'number' => $r[$k], 'mobile' => $k === 'mobile');
				}
			}
		}
		$seen = array();
		$reach = array_values(array_filter($reach, function ($x) use (&$seen) {
			$k = InboxSituation::phoneKey($x['number']);
			if ($k === '' || isset($seen[$k])) {
				return false;
			}
			$seen[$k] = true;
			return true;
		}));
		$cardPhones = array_keys($seen);
		$mailPhones = InboxSituation::signaturePhones($text);
		$strange = array_values(array_filter($mailPhones, function ($x) use ($cardPhones) {
			return !in_array($x['key'], $cardPhones, true);
		}));
		if ($strange && $cardPhones) {
			$signals[] = array('id' => 'phone_differs', 'state' => 'warn', 'args' => array($strange[0]['number']));
		} elseif ($mailPhones && !$cardPhones) {
			$signals[] = array('id' => 'phone_unknown', 'state' => 'warn', 'args' => array($mailPhones[0]['number']));
		}
		// 4. An address that never wrote before
		if ($from !== '') {
			$before = $this->db->getRow("SELECT COUNT(rowid) AS n FROM ".$this->db->prefix()."inbox_mail WHERE from_email = '".$this->db->escape($from)."' AND rowid <> ".((int) $row->rowid)
				." AND fk_account = ".((int) $row->fk_account));
			if (is_object($before) && (int) $before->n === 0) {
				$signals[] = array('id' => 'first_time', 'state' => 'warn', 'args' => array($from));
			}
		}
		// 5. The answer goes elsewhere
		$replyTo = strtolower(trim((string) $replyTo));
		if ($replyTo !== '' && $replyTo !== $from) {
			$signals[] = array('id' => 'reply_to', 'state' => 'warn', 'args' => array($replyTo));
		}
		// 6. Pressure: urgency, secrecy, a call refused
		$norm = self::norm($text);
		foreach (self::PRESSURE as $w) {
			if (preg_match('/\b'.preg_quote($w, '/').'\b/u', $norm)) {
				$signals[] = array('id' => 'pressure', 'state' => 'warn', 'args' => array($w));
				break;
			}
		}

		return array(
			'level' => !$passed || $imitation ? 'danger' : 'warning',
			'ibans' => array_map(array(__CLASS__, 'mask'), $topic['ibans']),
			'signals' => $signals,
			'warnings' => count(array_filter($signals, function ($s) {
				return $s['state'] !== 'ok';
			})),
			// The numbers to call: the cards of Dolibarr, never the mail
			'call' => $reach,
			'known' => $canSoc || $canContact,
		);
	}
}
