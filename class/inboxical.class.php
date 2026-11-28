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
 * \file    inbox/class/inboxical.class.php
 * \ingroup inbox
 * \brief   Small reader and writer of iCalendar (RFC 5545), what the invitations of inbox need (1.22.0)
 */

/**
 * Reads the events of an invitation (VEVENT: dates, time zones, organizer, attendees, recurrence shown, not
 * expanded) and writes the answer of an attendee (METHOD:REPLY, iMIP RFC 6047). Nothing in the text is trusted: size
 * and count bounded, texts cleaned, only mailto: addresses kept.
 */
class InboxIcal
{
	/** Larger invitations are not read */
	const MAX_SIZE = 1048576;

	/** Events read at most */
	const MAX_EVENTS = 200;

	/** Attendees kept at most per event */
	const MAX_ATTENDEES = 200;

	/** Names of zones of Windows (Outlook) that PHP does not know */
	const WINDOWS_ZONES = array(
		'romance standard time' => 'Europe/Paris', 'w. europe standard time' => 'Europe/Berlin', 'central europe standard time' => 'Europe/Budapest',
		'central european standard time' => 'Europe/Warsaw', 'gmt standard time' => 'Europe/London', 'greenwich standard time' => 'Atlantic/Reykjavik',
		'e. europe standard time' => 'Europe/Chisinau', 'fle standard time' => 'Europe/Kiev', 'gtb standard time' => 'Europe/Bucharest',
		'russian standard time' => 'Europe/Moscow', 'turkey standard time' => 'Europe/Istanbul', 'morocco standard time' => 'Africa/Casablanca',
		'w. central africa standard time' => 'Africa/Lagos', 'south africa standard time' => 'Africa/Johannesburg', 'israel standard time' => 'Asia/Jerusalem',
		'arabian standard time' => 'Asia/Dubai', 'india standard time' => 'Asia/Kolkata', 'china standard time' => 'Asia/Shanghai',
		'tokyo standard time' => 'Asia/Tokyo', 'singapore standard time' => 'Asia/Singapore', 'aus eastern standard time' => 'Australia/Sydney',
		'eastern standard time' => 'America/New_York', 'central standard time' => 'America/Chicago', 'mountain standard time' => 'America/Denver',
		'pacific standard time' => 'America/Los_Angeles', 'atlantic standard time' => 'America/Halifax', 'sa eastern standard time' => 'America/Cayenne',
		'e. south america standard time' => 'America/Sao_Paulo', 'canada central standard time' => 'America/Regina', 'utc' => 'UTC', 'coordinated universal time' => 'UTC',
	);

	/**
	 * @param string $text iCalendar text
	 * @return array{method:string,events:array<int,array<string,mixed>>}|null null when not an iCalendar or too large
	 */
	public static function parse($text)
	{
		$text = (string) $text;
		if ($text === '' || strlen($text) > self::MAX_SIZE || stripos($text, 'BEGIN:VCALENDAR') === false) {
			return null;
		}
		$text = str_replace(array("\r\n", "\r"), "\n", $text);
		// Long lines are folded: a line starting with a space or a tab goes on the previous one
		$text = preg_replace("/\n[ \t]/", '', $text);
		$method = '';
		$events = array();
		$stack = array();
		$cur = null;
		foreach (explode("\n", $text) as $line) {
			if (trim($line) === '') {
				continue;
			}
			$l = self::line($line);
			if ($l === null) {
				continue;
			}
			list($name, $params, $value) = $l;
			if ($name === 'BEGIN') {
				$stack[] = strtoupper(trim($value));
				if (strtoupper(trim($value)) === 'VEVENT') {
					$cur = array('uid' => '', 'sequence' => 0, 'summary' => '', 'location' => '', 'description' => '', 'status' => '', 'rrule' => '',
						'recurrence_id' => '', 'start' => 0, 'end' => 0, 'allday' => false, 'tz' => '', 'organizer' => null, 'attendees' => array(), 'raw' => array(),
						'duration' => '');
				}
				continue;
			}
			if ($name === 'END') {
				$closed = array_pop($stack);
				if ($closed === 'VEVENT' && $cur !== null) {
					$events[] = self::finish($cur);
					$cur = null;
					if (count($events) >= self::MAX_EVENTS) {
						break;
					}
				}
				continue;
			}
			$top = end($stack);
			if ($top === 'VCALENDAR' && $name === 'METHOD') {
				$method = strtoupper(preg_replace('/[^A-Za-z\-]/', '', $value));
				continue;
			}
			// The alarms inside an event are not read (their top is VALARM)
			if ($top !== 'VEVENT' || $cur === null) {
				continue;
			}
			switch ($name) {
				case 'UID':
					$cur['uid'] = mb_substr(self::clean($value), 0, 1000, 'UTF-8');
					break;
				case 'SEQUENCE':
					$cur['sequence'] = max(0, (int) $value);
					break;
				case 'SUMMARY':
					$cur['summary'] = mb_substr(self::text($value), 0, 500, 'UTF-8');
					break;
				case 'LOCATION':
					$cur['location'] = mb_substr(self::text($value), 0, 500, 'UTF-8');
					break;
				case 'DESCRIPTION':
					$cur['description'] = mb_substr(self::text($value), 0, 5000, 'UTF-8');
					break;
				case 'STATUS':
					$cur['status'] = strtoupper(self::clean($value));
					break;
				case 'RRULE':
					$cur['rrule'] = mb_substr(self::clean($value), 0, 500, 'UTF-8');
					break;
				case 'RECURRENCE-ID':
					$cur['recurrence_id'] = self::clean($value);
					$cur['raw']['RECURRENCE-ID'] = $line;
					break;
				case 'DTSTART':
				case 'DTEND':
					$d = self::date($value, $params);
					if ($d !== null) {
						$cur[$name === 'DTSTART' ? 'start' : 'end'] = $d['ts'];
						if ($name === 'DTSTART') {
							$cur['allday'] = $d['allday'];
							$cur['tz'] = $d['tz'];
							$cur['raw']['DTSTART'] = $line;
						} else {
							$cur['raw']['DTEND'] = $line;
						}
					}
					break;
				case 'DURATION':
					$cur['duration'] = self::clean($value);
					$cur['raw']['DURATION'] = $line;
					break;
				case 'ORGANIZER':
					$cur['organizer'] = self::person($value, $params);
					break;
				case 'ATTENDEE':
					$a = self::person($value, $params);
					if ($a !== null && count($cur['attendees']) < self::MAX_ATTENDEES) {
						$cur['attendees'][] = $a;
					}
					break;
			}
		}
		if (!$events) {
			return null;
		}

		return array('method' => $method !== '' ? $method : 'PUBLISH', 'events' => $events);
	}

	/**
	 * End of an event missing in the text: by its duration, else one hour (one day for a whole day)
	 *
	 * @param array<string,mixed> $e Event
	 * @return array<string,mixed>
	 */
	protected static function finish(array $e)
	{
		if ($e['start'] && !$e['end']) {
			$seconds = 0;
			if ($e['duration'] !== '' && preg_match('/^\+?P/', $e['duration'])) {
				try {
					$seconds = (new DateTimeImmutable('@0'))->add(new DateInterval(ltrim($e['duration'], '+')))->getTimestamp();
				} catch (Exception $ex) {
					$seconds = 0;
				}
			}
			$e['end'] = $e['start'] + ($seconds > 0 ? $seconds : ($e['allday'] ? 86400 : 3600));
		}
		unset($e['duration']);

		return $e;
	}

	/**
	 * One content line: name, parameters, value (the colon inside a quoted parameter is not the separator)
	 *
	 * @param string $line Line
	 * @return array{0:string,1:array<string,string>,2:string}|null
	 */
	protected static function line($line)
	{
		$quoted = false;
		$pos = -1;
		$len = strlen($line);
		for ($i = 0; $i < $len; $i++) {
			$c = $line[$i];
			if ($c === '"') {
				$quoted = !$quoted;
			} elseif ($c === ':' && !$quoted) {
				$pos = $i;
				break;
			}
		}
		if ($pos <= 0) {
			return null;
		}
		$head = substr($line, 0, $pos);
		$value = substr($line, $pos + 1);
		$semi = strpos($head, ';');
		$name = strtoupper(trim($semi === false ? $head : substr($head, 0, $semi)));
		$params = array();
		if ($semi !== false && preg_match_all('/;([A-Za-z0-9\-]+)=("[^"]*"|[^;]*)/', substr($head, $semi), $m, PREG_SET_ORDER)) {
			foreach ($m as $p) {
				$params[strtoupper($p[1])] = trim($p[2], '"');
			}
		}

		return array($name, $params, $value);
	}

	/**
	 * @param string $value Value
	 * @return string Control characters removed
	 */
	protected static function clean($value)
	{
		return trim(preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/', '', (string) $value));
	}

	/**
	 * @param string $value Text value (escaped)
	 * @return string
	 */
	protected static function text($value)
	{
		$v = strtr((string) $value, array('\\n' => "\n", '\\N' => "\n", '\\,' => ',', '\\;' => ';', '\\\\' => '\\'));

		return trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $v));
	}

	/**
	 * @param string               $value  mailto:address
	 * @param array<string,string> $params CN, PARTSTAT, ROLE
	 * @return array{email:string,name:string,partstat:string,role:string}|null
	 */
	protected static function person($value, array $params)
	{
		$email = strtolower(trim(preg_replace('/^mailto:/i', '', self::clean($value))));
		if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
			return null;
		}

		return array('email' => $email, 'name' => mb_substr(self::text($params['CN'] ?? ''), 0, 200, 'UTF-8'),
			'partstat' => strtoupper(preg_replace('/[^A-Za-z\-]/', '', $params['PARTSTAT'] ?? 'NEEDS-ACTION')), 'role' => strtoupper(preg_replace('/[^A-Za-z\-]/', '', $params['ROLE'] ?? '')));
	}

	/**
	 * @param string $tzid Zone of the invitation (IANA, Windows, or a label holding an IANA name)
	 * @return string IANA zone, '' when unknown
	 */
	public static function zone($tzid)
	{
		$tzid = trim((string) $tzid, " \"/");
		if ($tzid === '') {
			return '';
		}
		if (in_array($tzid, timezone_identifiers_list(), true)) {
			return $tzid;
		}
		$key = strtolower($tzid);
		if (isset(self::WINDOWS_ZONES[$key])) {
			return self::WINDOWS_ZONES[$key];
		}
		if (preg_match('#((?:Europe|America|Asia|Africa|Australia|Pacific|Atlantic|Indian)/[A-Za-z_]+(?:/[A-Za-z_]+)?)#', $tzid, $m) && in_array($m[1], timezone_identifiers_list(), true)) {
			return $m[1];
		}

		return '';
	}

	/**
	 * @param string               $value  20261110T100000, 20261110T100000Z or 20261110
	 * @param array<string,string> $params TZID, VALUE
	 * @return array{ts:int,allday:bool,tz:string}|null
	 */
	protected static function date($value, array $params)
	{
		$v = trim((string) $value);
		if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $v, $m) || (($params['VALUE'] ?? '') === 'DATE' && preg_match('/^(\d{4})(\d{2})(\d{2})/', $v, $m))) {
			// A whole day: kept as a date (midnight UTC), shown and recorded without a time zone
			return array('ts' => gmmktime(0, 0, 0, (int) $m[2], (int) $m[3], (int) $m[1]), 'allday' => true, 'tz' => '');
		}
		if (!preg_match('/^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})(\d{2})(Z?)$/', $v, $m)) {
			return null;
		}
		$tz = $m[7] === 'Z' ? 'UTC' : self::zone($params['TZID'] ?? '');
		try {
			$zone = new DateTimeZone($tz !== '' ? $tz : date_default_timezone_get());
			$d = new DateTimeImmutable($m[1].'-'.$m[2].'-'.$m[3].' '.$m[4].':'.$m[5].':'.$m[6], $zone);
		} catch (Exception $e) {
			return null;
		}

		return array('ts' => $d->getTimestamp(), 'allday' => false, 'tz' => $tz);
	}

	/**
	 * Recurrence in words (not expanded)
	 *
	 * @param string    $rrule RRULE value
	 * @param Translate $langs Language
	 * @return string '' when none
	 */
	public static function rruleText($rrule, $langs)
	{
		if (trim((string) $rrule) === '') {
			return '';
		}
		$r = array();
		foreach (explode(';', strtoupper((string) $rrule)) as $part) {
			$kv = explode('=', $part, 2);
			if (count($kv) === 2) {
				$r[$kv[0]] = $kv[1];
			}
		}
		$freq = $r['FREQ'] ?? '';
		if (!in_array($freq, array('DAILY', 'WEEKLY', 'MONTHLY', 'YEARLY'), true)) {
			return $langs->transnoentities('InboxRruleOther');
		}
		$interval = max(1, (int) ($r['INTERVAL'] ?? 1));
		$out = $interval > 1 ? $langs->transnoentities('InboxRruleEveryN_'.$freq, $interval) : $langs->transnoentities('InboxRrule_'.$freq);
		if (!empty($r['BYDAY'])) {
			$names = array('MO' => 'Monday', 'TU' => 'Tuesday', 'WE' => 'Wednesday', 'TH' => 'Thursday', 'FR' => 'Friday', 'SA' => 'Saturday', 'SU' => 'Sunday');
			$days = array();
			foreach (explode(',', $r['BYDAY']) as $d) {
				$code = substr(preg_replace('/[^A-Z]/', '', $d), -2);
				if (isset($names[$code])) {
					$days[] = $langs->transnoentities($names[$code]);
				}
			}
			if ($days) {
				$out .= ' ('.implode(', ', $days).')';
			}
		}
		if (!empty($r['COUNT'])) {
			$out .= ', '.$langs->transnoentities('InboxRruleCount', (int) $r['COUNT']);
		} elseif (!empty($r['UNTIL']) && preg_match('/^(\d{4})(\d{2})(\d{2})/', $r['UNTIL'], $m)) {
			$out .= ', '.$langs->transnoentities('InboxRruleUntil', dol_print_date(gmmktime(12, 0, 0, (int) $m[2], (int) $m[3], (int) $m[1]), 'day', 'gmt', $langs));
		}

		return $out;
	}

	/**
	 * @param string $value Text
	 * @return string Escaped for a text value
	 */
	protected static function escape($value)
	{
		$v = preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/', '', str_replace(array("\r\n", "\r"), "\n", (string) $value));

		return str_replace(array('\\', ';', ',', "\n"), array('\\\\', '\\;', '\\,', '\\n'), $v);
	}

	/**
	 * @param string $line Line
	 * @return string Folded at 75 octets, without cutting a UTF-8 character
	 */
	protected static function fold($line)
	{
		$out = '';
		while (strlen($line) > 75) {
			$cut = 75;
			while ($cut > 1 && (ord($line[$cut]) & 0xC0) === 0x80) {
				$cut--;
			}
			$out .= substr($line, 0, $cut)."\r\n ";
			$line = substr($line, $cut);
		}

		return $out.$line."\r\n";
	}

	/**
	 * Answer of an attendee (iMIP): the event identified as the organizer sent it, with the attendee and its choice
	 *
	 * @param array<string,mixed> $event    Event read by parse()
	 * @param string              $email    Address of the attendee
	 * @param string              $name     Name of the attendee
	 * @param string              $partstat ACCEPTED, TENTATIVE or DECLINED
	 * @param string              $comment  Comment (optional)
	 * @return string iCalendar text
	 */
	public static function reply(array $event, $email, $name, $partstat, $comment = '')
	{
		$lines = array('BEGIN:VCALENDAR', 'PRODID:-//Dolibarr//inbox//EN', 'VERSION:2.0', 'METHOD:REPLY', 'BEGIN:VEVENT', 'UID:'.self::escape($event['uid']),
			'SEQUENCE:'.((int) $event['sequence']), 'DTSTAMP:'.gmdate('Ymd\THis\Z'));
		foreach (array('RECURRENCE-ID', 'DTSTART', 'DTEND', 'DURATION') as $k) {
			if (!empty($event['raw'][$k])) {
				$lines[] = preg_replace('/[\r\n]+/', '', (string) $event['raw'][$k]);
			}
		}
		$org = $event['organizer'];
		if ($org) {
			$lines[] = 'ORGANIZER'.($org['name'] !== '' ? ';CN="'.str_replace('"', '', $org['name']).'"' : '').':mailto:'.$org['email'];
		}
		$lines[] = 'ATTENDEE;PARTSTAT='.$partstat.($name !== '' ? ';CN="'.str_replace(array('"', "\r", "\n"), '', $name).'"' : '').':mailto:'.strtolower($email);
		if ($event['summary'] !== '') {
			$lines[] = 'SUMMARY:'.self::escape($event['summary']);
		}
		if (trim((string) $comment) !== '') {
			$lines[] = 'COMMENT:'.self::escape(mb_substr((string) $comment, 0, 2000, 'UTF-8'));
		}
		$lines[] = 'END:VEVENT';
		$lines[] = 'END:VCALENDAR';

		return implode('', array_map(array(__CLASS__, 'fold'), $lines));
	}
}
