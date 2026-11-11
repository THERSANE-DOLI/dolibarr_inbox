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
 * \file    inbox/class/inboxspamrspamd.class.php
 * \ingroup inbox
 * \brief   Anti-spam engine of inbox (1.88.0): contract of the engines, client of Rspamd (HTTP API)
 */

/**
 * An anti-spam engine judges a mail (raw source) and learns from the users. Self-hosted: installed beside Dolibarr,
 * the mails never leave our infrastructure. Analysis: analyses/analyse-anti-spam.md §5.
 */
interface InboxSpamEngine
{
	/**
	 * Judge a mail
	 *
	 * @param string $raw Source of the mail
	 * @return array{spam:bool,action:string,score:float,required:float|null,symbols:string[]}|null Null on error ($this->error)
	 */
	public function check($raw);

	/**
	 * Learn a mail as spam or as legitimate
	 *
	 * @param string $raw  Source of the mail
	 * @param bool   $spam Spam (else legitimate)
	 * @return bool
	 */
	public function learn($raw, $spam);

	/**
	 * State of the engine: reachable, version, mails learned
	 *
	 * @return array{ok:bool,version:string,learned_spam:int,learned_ham:int,error:string}
	 */
	public function status();
}

/**
 * Rspamd: /checkv2 of the normal worker (11333), /learnspam, /learnham and /stat of the controller (11334, its
 * password in the header « Password »). The lists of reputation asked by DNS to outside services (Spamhaus,
 * SURBL...) are turned off request by request (header « Settings ») unless the administrator turned them on.
 */
class InboxSpamRspamd implements InboxSpamEngine
{
	/** Actions of Rspamd that mean spam */
	const SPAM_ACTIONS = array('reject', 'add header', 'rewrite subject');

	/** Groups of symbols that ask outside services by DNS (turned off unless INBOX_RSPAMD_DNSLISTS) */
	const OUTSIDE_GROUPS = array('rbl', 'surbl');

	/** @var string */
	public $error = '';

	/** @var string Normal worker */
	protected $url;

	/** @var string Controller */
	protected $controller;

	/** @var string */
	protected $password;

	/** @var bool Lists of reputation by DNS allowed */
	protected $dnsLists;

	/** @var int Seconds */
	protected $timeout;

	/**
	 * @param string $url        Normal worker (http://127.0.0.1:11333)
	 * @param string $controller Controller (http://127.0.0.1:11334)
	 * @param string $password   Password of the controller
	 * @param bool   $dnsLists   Lists of reputation by DNS allowed
	 * @param int    $timeout    Seconds
	 */
	public function __construct($url, $controller, $password, $dnsLists = false, $timeout = 5)
	{
		$this->url = rtrim((string) $url, '/');
		$this->controller = rtrim((string) $controller, '/');
		$this->password = (string) $password;
		$this->dnsLists = (bool) $dnsLists;
		$this->timeout = max(1, (int) $timeout);
	}

	/**
	 * The engine of the settings
	 *
	 * @return InboxSpamRspamd
	 */
	public static function fromSettings()
	{
		$password = getDolGlobalString('INBOX_RSPAMD_PASSWORD');
		if ($password !== '' && function_exists('dolDecrypt')) {
			$password = dolDecrypt($password);
		}

		return new self(getDolGlobalString('INBOX_RSPAMD_URL', 'http://127.0.0.1:11333'), getDolGlobalString('INBOX_RSPAMD_CONTROLLER_URL', 'http://127.0.0.1:11334'),
			$password, (bool) getDolGlobalInt('INBOX_RSPAMD_DNSLISTS', 0), getDolGlobalInt('INBOX_RSPAMD_TIMEOUT', 5));
	}

	/**
	 * A URL of the settings: http(s), a host, a port, no user nor password in it
	 *
	 * @param string $url URL
	 * @return bool
	 */
	public static function validUrl($url)
	{
		return (bool) preg_match('#^https?://[a-z0-9.\-\[\]:]{1,255}(:\d{1,5})?(/[a-z0-9._/\-]*)?$#i', (string) $url) && strpos((string) $url, '@') === false;
	}

	/**
	 * One HTTP call (tests replace it with recorded answers)
	 *
	 * @param string               $url     URL
	 * @param string               $body    Body (POST), '' for GET
	 * @param array<string,string> $headers Headers
	 * @return array{code:int,body:string}|null Null when unreachable ($this->error)
	 */
	protected function http($url, $body, array $headers)
	{
		if (!function_exists('curl_init')) {
			$this->error = 'curl missing';
			return null;
		}
		$ch = curl_init($url);
		$h = array();
		foreach ($headers as $k => $v) {
			$h[] = $k.': '.str_replace(array("\r", "\n"), '', $v);
		}
		curl_setopt_array($ch, array(
			CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $this->timeout, CURLOPT_CONNECTTIMEOUT => min(3, $this->timeout),
			CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_HTTPHEADER => $h,
		));
		if ($body !== '') {
			curl_setopt($ch, CURLOPT_POST, true);
			curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
		}
		$res = curl_exec($ch);
		$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$err = curl_error($ch);
		curl_close($ch);
		if ($res === false) {
			$this->error = $err !== '' ? $err : 'unreachable';
			return null;
		}

		return array('code' => $code, 'body' => (string) $res);
	}

	/**
	 * Headers of a check: no outside lists unless allowed
	 *
	 * @return array<string,string>
	 */
	protected function checkHeaders()
	{
		$h = array('Content-Type' => 'message/rfc822');
		if (!$this->dnsLists) {
			$h['Settings'] = (string) json_encode(array('groups_disabled' => self::OUTSIDE_GROUPS));
		}

		return $h;
	}

	/**
	 * @param string $raw Source of the mail
	 * @return array{spam:bool,action:string,score:float,required:float|null,symbols:string[]}|null
	 */
	public function check($raw)
	{
		$this->error = '';
		$res = $this->http($this->url.'/checkv2', (string) $raw, $this->checkHeaders());
		if ($res === null) {
			return null;
		}
		$json = json_decode($res['body'], true);
		if ($res['code'] !== 200 || !is_array($json) || !isset($json['action'])) {
			$this->error = 'HTTP '.$res['code'].' '.(is_array($json) && isset($json['error']) ? (string) $json['error'] : dol_trunc($res['body'], 120));
			return null;
		}
		// The symbols that weigh most, the reasons said in words by the reading
		$symbols = array();
		foreach ((array) ($json['symbols'] ?? array()) as $name => $s) {
			$score = is_array($s) && isset($s['score']) ? (float) $s['score'] : 0.0;
			if (preg_match('/^[A-Z0-9_]{2,64}$/', (string) $name) && $score != 0.0) {
				$symbols[(string) $name] = $score;
			}
		}
		uasort($symbols, function ($a, $b) {
			return abs($b) <=> abs($a);
		});
		$action = strtolower((string) $json['action']);

		return array(
			'spam' => in_array($action, self::SPAM_ACTIONS, true),
			'action' => $action,
			'score' => round((float) ($json['score'] ?? 0), 2),
			'required' => isset($json['required_score']) ? round((float) $json['required_score'], 2) : null,
			'symbols' => array_slice(array_keys($symbols), 0, 30),
		);
	}

	/**
	 * @param string $raw  Source of the mail
	 * @param bool   $spam Spam (else legitimate)
	 * @return bool
	 */
	public function learn($raw, $spam)
	{
		$this->error = '';
		$res = $this->http($this->controller.($spam ? '/learnspam' : '/learnham'), (string) $raw, array('Password' => $this->password, 'Content-Type' => 'message/rfc822'));
		if ($res === null) {
			return false;
		}
		$json = json_decode($res['body'], true);
		// 208: already learned this way, nothing to do
		if ($res['code'] === 200 || $res['code'] === 208) {
			return true;
		}
		$this->error = 'HTTP '.$res['code'].' '.(is_array($json) && isset($json['error']) ? (string) $json['error'] : dol_trunc($res['body'], 120));

		return false;
	}

	/**
	 * @return array{ok:bool,version:string,learned_spam:int,learned_ham:int,error:string}
	 */
	public function status()
	{
		$this->error = '';
		$out = array('ok' => false, 'version' => '', 'learned_spam' => 0, 'learned_ham' => 0, 'error' => '');
		$ping = $this->http($this->url.'/ping', '', array());
		if ($ping === null || $ping['code'] !== 200) {
			$out['error'] = $ping === null ? $this->error : 'HTTP '.$ping['code'];
			return $out;
		}
		$stat = $this->http($this->controller.'/stat', '', array('Password' => $this->password));
		if ($stat === null || $stat['code'] !== 200) {
			$out['error'] = $stat === null ? $this->error : ($stat['code'] === 403 ? 'controller password refused' : 'HTTP '.$stat['code']);
			return $out;
		}
		$json = json_decode($stat['body'], true);
		$out['ok'] = true;
		$out['version'] = is_array($json) && isset($json['version']) ? (string) $json['version'] : '';
		foreach ((array) ($json['statfiles'] ?? array()) as $f) {
			$symbol = strtoupper((string) ($f['symbol'] ?? ''));
			$n = (int) ($f['revision'] ?? 0);
			if (strpos($symbol, 'SPAM') !== false) {
				$out['learned_spam'] += $n;
			} elseif (strpos($symbol, 'HAM') !== false) {
				$out['learned_ham'] += $n;
			}
		}

		return $out;
	}
}
