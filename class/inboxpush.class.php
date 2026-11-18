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
 * \file    inbox/class/inboxpush.class.php
 * \ingroup inbox
 * \brief   Push drivers of the instant refresh: the list, the one chosen, the events of the request delivered at its end
 */

require_once __DIR__.'/push/inboxpushdriverinterface.class.php';

/**
 * Instant refresh of the lists (1.124.0), mode INBOX_REACTIVITY = 'instant':
 * - the events written in the journal (InboxEvent) are queued here and given to the chosen driver once, at the end
 *   of the request (an action of 200 mails: one delivery);
 * - nothing of the action waits for the service, a failure is only written for the administrator (the light call
 *   live.php keeps going, slower, and catches up by the cursor);
 * - inbox ships Mercure; another module adds a driver with the hook inboxPushDrivers (context "inbox"): its method
 *   puts in $this->results code => array('class' => ..., 'file' => path for dol_include_once, 'label' => key, 'doc' =>
 *   optional https address of its documentation). The
 *   class implements InboxPushDriverInterface. inbox knows no external module.
 */
class InboxPush
{
	/** Mode of INBOX_REACTIVITY that uses a driver */
	const MODE = 'instant';

	/** A failure is written again for the administrator at most every 10 minutes */
	const ERROR_EVERY = 600;

	/** @var array<string,array{class:string,file:string,label:string,doc:string}>|null */
	protected static $drivers = null;

	/** @var InboxPushDriverInterface|false|null Driver of the settings (false: none) */
	protected static $active = null;

	/** @var array<int,array{topic:string,id:int,data:array<string,mixed>}> */
	protected static $queue = array();

	/** @var bool */
	protected static $flushRegistered = false;

	/**
	 * @return array<string,array{class:string,file:string,label:string,doc:string}> Code => description
	 */
	public static function drivers()
	{
		global $hookmanager;

		if (self::$drivers !== null) {
			return self::$drivers;
		}
		$list = array(
			'mercure' => array('class' => 'InboxPushMercure', 'file' => '/inbox/class/push/inboxpushmercure.class.php', 'label' => 'InboxPushMercure', 'doc' => ''),
		);
		if (!is_object($hookmanager)) {
			include_once DOL_DOCUMENT_ROOT.'/core/class/hookmanager.class.php';
			$hookmanager = new HookManager($GLOBALS['db']);
		}
		$hookmanager->initHooks(array('inbox'));
		$parameters = array('drivers' => $list);
		$action = '';
		$object = null;
		$reshook = $hookmanager->executeHooks('inboxPushDrivers', $parameters, $object, $action);
		if ($reshook >= 0 && is_array($hookmanager->resArray)) {
			foreach ($hookmanager->resArray as $code => $desc) {
				if (isset($list[$code]) || !preg_match('/^[a-z0-9_]{1,32}$/', (string) $code) || !is_array($desc) || empty($desc['class']) || empty($desc['file'])) {
					continue;
				}
				// Optional: address of the documentation of the driver (https only), shown beside the choice
				$doc = isset($desc['doc']) ? self::cleanUrl((string) $desc['doc']) : '';
				$list[$code] = array('class' => (string) $desc['class'], 'file' => (string) $desc['file'], 'label' => isset($desc['label']) ? (string) $desc['label'] : (string) $code,
					'doc' => strpos($doc, 'https://') === 0 ? $doc : '');
			}
		}
		self::$drivers = $list;

		return $list;
	}

	/**
	 * A driver, not configured
	 *
	 * @param string $code Code
	 * @return InboxPushDriverInterface|null
	 */
	public static function make($code)
	{
		$drivers = self::drivers();
		if (!isset($drivers[$code])) {
			return null;
		}
		$desc = $drivers[$code];
		if (!class_exists($desc['class'])) {
			dol_include_once($desc['file']);
		}
		if (!class_exists($desc['class']) || !in_array('InboxPushDriverInterface', (array) class_implements($desc['class']), true)) {
			return null;
		}

		return new $desc['class']();
	}

	/**
	 * Name of the constant of a setting of a driver
	 *
	 * @param string $code Driver
	 * @param string $key  Field
	 * @return string
	 */
	public static function constName($code, $key)
	{
		return 'INBOX_PUSH_'.strtoupper($code).'_'.strtoupper($key);
	}

	/**
	 * Values of the settings of a driver (secrets decrypted)
	 *
	 * @param InboxPushDriverInterface $driver Driver
	 * @return array<string,string>
	 */
	public static function values(InboxPushDriverInterface $driver)
	{
		$out = array();
		foreach ($driver->fields() as $key => $f) {
			$v = getDolGlobalString(self::constName($driver->code(), $key), isset($f['default']) ? (string) $f['default'] : '');
			if ($f['type'] === 'secret' && $v !== '') {
				$v = (string) dolDecrypt($v);
			}
			$out[$key] = $v;
		}

		return $out;
	}

	/**
	 * An address of the settings of a driver: http(s), a host, no user, password, query nor fragment in it
	 *
	 * @param string $url Address
	 * @return string     '' if refused
	 */
	public static function cleanUrl($url)
	{
		$url = trim((string) $url);
		$p = parse_url($url);
		if ($url === '' || !is_array($p) || empty($p['host']) || !in_array(strtolower($p['scheme'] ?? ''), array('http', 'https'), true) || isset($p['user']) || isset($p['pass']) || isset($p['query']) || isset($p['fragment'])) {
			return '';
		}

		return $url;
	}

	/**
	 * Mark of this instance on a shared service: derived from its unique id, the id itself never leaves
	 *
	 * @return string
	 */
	public static function namespaceId()
	{
		global $conf;

		$id = (string) (!empty($conf->file->instance_unique_id) ? $conf->file->instance_unique_id : '');

		return substr(hash_hmac('sha256', 'inbox-push-namespace', $id !== '' ? $id : (string) DOL_DOCUMENT_ROOT), 0, 16);
	}

	/**
	 * Mark of the settings of a driver that passed its test: any change of a setting (or of a key) changes it. Only
	 * a keyed hash is stored, never the values
	 *
	 * @param InboxPushDriverInterface $driver Driver
	 * @param array<string,string>     $values Its values (secrets decrypted)
	 * @return string
	 */
	public static function fingerprint(InboxPushDriverInterface $driver, array $values)
	{
		global $conf;

		ksort($values);
		$key = (string) (!empty($conf->file->instance_unique_id) ? $conf->file->instance_unique_id : DOL_DOCUMENT_ROOT);

		return hash_hmac('sha256', $driver->code()."\n".json_encode($values), 'inbox-push-tested|'.$key);
	}

	/**
	 * @param InboxPushDriverInterface $driver Driver
	 * @param array<string,string>     $values Its values
	 * @return bool                    Its test passed with these very settings
	 */
	public static function isTested(InboxPushDriverInterface $driver, array $values)
	{
		$saved = getDolGlobalString('INBOX_PUSH_TESTED');

		return $saved !== '' && hash_equals($saved, self::fingerprint($driver, $values));
	}

	/**
	 * Driver of the settings (configured, and tested with these settings), whatever the mode
	 *
	 * @return InboxPushDriverInterface|null
	 */
	public static function ready()
	{
		$driver = self::make(getDolGlobalString('INBOX_PUSH_DRIVER', 'mercure'));
		if (!$driver) {
			return null;
		}
		$values = self::values($driver);
		$driver->configure($values, self::namespaceId());

		return $driver->isConfigured() && self::isTested($driver, $values) ? $driver : null;
	}

	/**
	 * Driver used when the mode is « instant »: configured and tested with its present settings. Otherwise none: the
	 * lists follow at the rhythm of the fast mode (the light call alone) until the service passes its test again
	 *
	 * @return InboxPushDriverInterface|null
	 */
	public static function active()
	{
		if (getDolGlobalString('INBOX_REACTIVITY') !== self::MODE) {
			return null;
		}
		if (self::$active === null) {
			self::$active = self::ready() ?: false;
		}

		return self::$active ?: null;
	}

	/**
	 * Forget the driver and the list (tests, settings saved during the request)
	 *
	 * @param InboxPushDriverInterface|null $driver A driver to use instead of the settings (tests)
	 * @return void
	 */
	public static function reset($driver = null)
	{
		self::$drivers = null;
		self::$active = $driver === null ? null : $driver;
		self::$queue = array();
	}

	/**
	 * Queue an event of the journal, delivered at the end of the request
	 *
	 * @param string               $topic 'account/<id>' or 'user/<id>'
	 * @param array<string,mixed>  $event Event, as live.php gives it (numbers only)
	 * @return bool                Queued (false: no driver)
	 */
	public static function queue($topic, array $event)
	{
		if (!self::active()) {
			return false;
		}
		self::$queue[] = array('topic' => (string) $topic, 'id' => (int) $event['id'], 'data' => $event);
		if (!self::$flushRegistered) {
			self::$flushRegistered = true;
			register_shutdown_function(array(__CLASS__, 'flush'));
		}

		return true;
	}

	/**
	 * @return array<int,array{topic:string,id:int,data:array<string,mixed>}> Events waiting (tests)
	 */
	public static function pending()
	{
		return self::$queue;
	}

	/**
	 * Deliver the events of the request (end of the request; tests call it)
	 *
	 * @return bool Delivered, or nothing to deliver
	 */
	public static function flush()
	{
		$messages = self::$queue;
		self::$queue = array();
		$driver = self::active();
		if (!$messages || !$driver) {
			return true;
		}
		$ok = $driver->publish($messages);
		self::noteResult($ok, $ok ? '' : $driver->error());

		return $ok;
	}

	/**
	 * File of the last failure: the delivery runs after Dolibarr closed its database at the end of the request, so the
	 * failure is kept in a small file (global, as the setting), never in the database
	 *
	 * @return string
	 */
	public static function errorFile()
	{
		return DOL_DATA_ROOT.'/inbox/push-last-error.json';
	}

	/**
	 * @return array{at:int,message:string}|null Last failure, null when the last delivery worked
	 */
	public static function lastError()
	{
		$file = self::errorFile();
		if (!is_file($file)) {
			return null;
		}
		$data = json_decode((string) @file_get_contents($file, false, null, 0, 4096), true);

		return is_array($data) && isset($data['at'], $data['message']) && is_scalar($data['message']) ? array('at' => (int) $data['at'], 'message' => self::safeText((string) $data['message'])) : null;
	}

	/**
	 * Text of an error shown to the administrator: letters, digits and a few signs only, nothing that can make HTML
	 * (an error may quote the answer of a service, the file may be written by something else). Applied when it is
	 * written and when it is read: the translation of Dolibarr keeps some tags of its parameters (<a, <span).
	 *
	 * @param string $text Text
	 * @return string
	 */
	public static function safeText($text)
	{
		$text = (string) preg_replace('/[^\p{L}\p{N} .,:;()\/_+-]/u', ' ', (string) $text);

		return dol_trunc(trim((string) preg_replace('/\s+/', ' ', $text)), 200, 'right', 'UTF-8', 1);
	}

	/**
	 * @return void
	 */
	public static function clearError()
	{
		if (is_file(self::errorFile())) {
			@unlink(self::errorFile());
		}
	}

	/**
	 * Failure kept for the administrator (setup page), written again at most every 10 minutes; cleared by the next
	 * success
	 *
	 * @param bool   $ok    Delivered
	 * @param string $error Error
	 * @return void
	 */
	protected static function noteResult($ok, $error)
	{
		if ($ok) {
			self::clearError();
			return;
		}
		dol_syslog(__METHOD__.' '.$error, LOG_WARNING);
		$last = self::lastError();
		if (!$last || dol_now() - $last['at'] >= self::ERROR_EVERY) {
			$dir = dirname(self::errorFile());
			if (!is_dir($dir)) {
				dol_mkdir($dir);
			}
			@file_put_contents(self::errorFile(), json_encode(array('at' => dol_now(), 'message' => self::safeText($error))));
		}
	}

	/**
	 * What the browser of a user needs to receive his topics: his mailboxes and himself
	 *
	 * @param int   $userId   User
	 * @param int[] $accounts Mailboxes he can read (the ones of his token of the light call)
	 * @param int   $expires  End of validity
	 * @return array{driver:string,js:string,config:array<string,mixed>}|null
	 */
	public static function subscriber($userId, array $accounts, $expires)
	{
		$driver = self::active();
		if (!$driver) {
			return null;
		}
		$topics = array('user/'.((int) $userId));
		foreach ($accounts as $a) {
			if ((int) $a > 0) {
				$topics[] = 'account/'.((int) $a);
			}
		}
		$config = $driver->subscriber($topics, (int) $userId, (int) $expires);
		if (!$config) {
			return null;
		}

		return array('driver' => $driver->code(), 'js' => dol_buildpath($driver->jsFile(), 1), 'config' => $config);
	}
}
