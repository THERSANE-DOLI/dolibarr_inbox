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
 * \file    inbox/class/push/inboxpushmercure.class.php
 * \ingroup inbox
 * \brief   Push driver for a Mercure hub 1.x (https://mercure.rocks), installed by the administrator beside Dolibarr
 */

require_once __DIR__.'/inboxpushdriverinterface.class.php';
require_once dirname(__DIR__).'/inboxpush.class.php';

/**
 * Mercure 1.x (1.124.0): the hub keeps the connections of the browsers (Server-Sent Events), PHP only posts.
 * - Every update is private: the hub gives it only to a subscriber whose token lists its topic. The token of a user
 *   lists his mailboxes and himself (exact topics), signed here with the key of the subscribers, 15 minutes.
 * - Topics https://inbox.invalid/<instance>/account/<id> and …/user/<id> (« .invalid » never resolves: a name only).
 * - Tokens: OAuth 2.0 access tokens of Mercure 1 (typ at+jwt, iss = the trusted issuer of the hub, aud = the
 *   addresses of the hub, authorization_details). The key of the publishers signs a short token for each delivery;
 *   neither key goes to the browser.
 * - The browser sends its token in the header Authorization (never in the address, refused by Mercure 1).
 * - No history needed on the hub: the light call of inbox catches up.
 */
class InboxPushMercure implements InboxPushDriverInterface
{
	/** Seconds to reach the hub when publishing: the end of the request never waits long */
	const TIMEOUT = 2;

	/** Type of the grants of Mercure 1 */
	const GRANT = 'https://mercure.rocks/authorization-detail';

	/** @var string Address of the hub for the browsers (…/.well-known/mercure) */
	protected $hubUrl = '';

	/** @var string Address of the hub for the server (same as hubUrl when empty) */
	protected $publishUrl = '';

	/** @var string Trusted issuer of the hub (MERCURE_TRUSTED_ISSUERS) */
	protected $issuer = '';

	/** @var string */
	protected $publisherKey = '';

	/** @var string */
	protected $subscriberKey = '';

	/** @var string */
	protected $namespace = '';

	/** @var string */
	protected $error = '';

	/**
	 * @return string
	 */
	public function code()
	{
		return 'mercure';
	}

	/**
	 * @return string
	 */
	public function label()
	{
		return 'InboxPushMercure';
	}

	/**
	 * @return array<string,array{type:string,label:string,help?:string,default?:string,required?:bool}>
	 */
	public function fields()
	{
		return array(
			'hub_url' => array('type' => 'url', 'label' => 'InboxPushMercureHubUrl', 'help' => 'InboxPushMercureHubUrlHelp', 'required' => true, 'public' => true),
			'publish_url' => array('type' => 'url', 'label' => 'InboxPushMercurePublishUrl', 'help' => 'InboxPushMercurePublishUrlHelp'),
			'issuer' => array('type' => 'string', 'label' => 'InboxPushMercureIssuer', 'help' => 'InboxPushMercureIssuerHelp', 'required' => true),
			'publisher_key' => array('type' => 'secret', 'label' => 'InboxPushMercurePublisherKey', 'help' => 'InboxPushMercurePublisherKeyHelp', 'required' => true),
			'subscriber_key' => array('type' => 'secret', 'label' => 'InboxPushMercureSubscriberKey', 'help' => 'InboxPushMercureSubscriberKeyHelp', 'required' => true),
		);
	}

	/**
	 * @param array<string,string> $values    Values
	 * @param string               $namespace Mark of the instance
	 * @return void
	 */
	public function configure(array $values, $namespace)
	{
		$this->hubUrl = InboxPush::cleanUrl(isset($values['hub_url']) ? $values['hub_url'] : '', true);
		$this->publishUrl = InboxPush::cleanUrl(isset($values['publish_url']) ? $values['publish_url'] : '');
		$this->issuer = trim(isset($values['issuer']) ? (string) $values['issuer'] : '');
		$this->publisherKey = isset($values['publisher_key']) ? (string) $values['publisher_key'] : '';
		$this->subscriberKey = isset($values['subscriber_key']) ? (string) $values['subscriber_key'] : '';
		$this->namespace = preg_replace('/[^a-z0-9]/', '', strtolower((string) $namespace));
	}

	/**
	 * @return bool
	 */
	public function isConfigured()
	{
		return $this->hubUrl !== '' && $this->issuer !== '' && $this->publisherKey !== '' && $this->subscriberKey !== '' && $this->namespace !== '';
	}

	/**
	 * Topic of the hub for a topic of inbox
	 *
	 * @param string $topic 'account/<id>', 'user/<id>' (or 'test/<mark>')
	 * @return string       '' if not one of them
	 */
	public function topic($topic)
	{
		if (!preg_match('#^(account|user|test)/([0-9a-z]{1,32})$#', (string) $topic, $m)) {
			return '';
		}

		return $this->topicBase().$m[1].'/'.$m[2];
	}

	/**
	 * @return string Start of every topic of this instance
	 */
	protected function topicBase()
	{
		return 'https://inbox.invalid/'.$this->namespace.'/';
	}

	/**
	 * @param array<int,array{topic:string,id:int,data:array<string,mixed>}> $messages Messages
	 * @return bool
	 */
	public function publish(array $messages)
	{
		$this->error = '';
		if (!$this->isConfigured()) {
			$this->error = 'not configured';
			return false;
		}
		$bodies = array();
		foreach ($messages as $m) {
			$topic = $this->topic($m['topic']);
			if ($topic !== '') {
				$bodies[] = $this->publishBody($topic, $m['data']);
			}
		}
		if (!$bodies) {
			return true;
		}
		$jwt = $this->publisherToken(array(array('match' => $this->topicBase().'*', 'match_type' => 'urlpattern')), dol_now() + 300);
		foreach ($this->send($this->serverUrl(), $jwt, $bodies) as $r) {
			if ($r['code'] < 200 || $r['code'] >= 300) {
				$this->error = 'Mercure: '.($r['code'] ? 'HTTP '.$r['code'] : $r['error']);
				return false;
			}
		}

		return true;
	}

	/**
	 * @return string Address the server publishes to
	 */
	protected function serverUrl()
	{
		return $this->publishUrl !== '' ? $this->publishUrl : $this->hubUrl;
	}

	/**
	 * Body of one update: private (never given without a token that lists its topic)
	 *
	 * @param string              $topic Topic of the hub
	 * @param array<string,mixed> $data  Event
	 * @return string                    application/x-www-form-urlencoded
	 */
	public function publishBody($topic, array $data)
	{
		return http_build_query(array('topic' => $topic, 'data' => json_encode($data), 'private' => 'on'), '', '&', PHP_QUERY_RFC3986);
	}

	/**
	 * POST the updates, side by side (curl multi)
	 *
	 * @param string   $url    Hub
	 * @param string   $jwt    Token of the publisher
	 * @param string[] $bodies Bodies
	 * @return array<int,array{code:int,error:string}>
	 */
	protected function send($url, $jwt, array $bodies)
	{
		$mh = curl_multi_init();
		$handles = array();
		foreach ($bodies as $body) {
			$ch = curl_init($url);
			curl_setopt_array($ch, array(
				CURLOPT_POST => true,
				CURLOPT_POSTFIELDS => $body,
				CURLOPT_HTTPHEADER => array('Authorization: Bearer '.$jwt, 'Content-Type: application/x-www-form-urlencoded'),
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_CONNECTTIMEOUT => self::TIMEOUT,
				CURLOPT_TIMEOUT => self::TIMEOUT,
				CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
				CURLOPT_FOLLOWLOCATION => false,
			));
			curl_multi_add_handle($mh, $ch);
			$handles[] = $ch;
		}
		do {
			$status = curl_multi_exec($mh, $running);
			if ($running) {
				curl_multi_select($mh, 0.2);
			}
		} while ($running && $status === CURLM_OK);
		// The error of each transfer (curl_error() stays empty with the multi interface)
		$errors = array();
		while ($info = curl_multi_info_read($mh)) {
			if ($info['result'] !== CURLE_OK) {
				$errors[(int) array_search($info['handle'], $handles, true)] = curl_strerror($info['result']);
			}
		}
		$out = array();
		foreach ($handles as $i => $ch) {
			$out[] = array('code' => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'error' => isset($errors[$i]) ? $errors[$i] : (string) curl_error($ch));
			curl_multi_remove_handle($mh, $ch);
			curl_close($ch);
		}
		curl_multi_close($mh);

		return $out;
	}

	/**
	 * @param string[] $topics  Topics of inbox
	 * @param int      $userId  User
	 * @param int      $expires End of validity
	 * @return array{url:string,token:string,expires:int}|null
	 */
	public function subscriber(array $topics, $userId, $expires)
	{
		if (!$this->isConfigured()) {
			return null;
		}
		$list = array();
		foreach ($topics as $t) {
			$h = $this->topic($t);
			if ($h !== '') {
				$list[] = array('match' => $h);
			}
		}
		if (!$list) {
			return null;
		}

		return array(
			// The query asks for the topics of this instance; the token narrows to his own (the narrower applies)
			'url' => $this->hubUrl.(strpos($this->hubUrl, '?') === false ? '?' : '&').'match_urlpattern='.rawurlencode($this->topicBase().'*'),
			// Audience: the address the browsers use only (the inner address of the server is not theirs to know)
			'token' => $this->token($this->subscriberKey, 'subscribe', $list, (int) $expires, 'user:'.((int) $userId), array($this->hubUrl)),
			'expires' => (int) $expires,
		);
	}

	/**
	 * @return string
	 */
	public function jsFile()
	{
		return '/inbox/js/push/inbox-push-mercure.js';
	}

	/**
	 * Subscribe to a topic of test through the address of the server, publish on it, wait for it (6 s at most); then
	 * the address of the browsers answers as a hub
	 *
	 * @return array{ok:bool,message:string,ms:int}
	 */
	public function test()
	{
		global $langs;

		$start = microtime(true);
		if (!$this->isConfigured()) {
			return array('ok' => false, 'message' => $langs->trans('InboxPushNotConfigured'), 'ms' => 0);
		}
		$mark = bin2hex(random_bytes(6));
		$topic = $this->topic('test/'.$mark);
		$base = $this->serverUrl();
		$subJwt = $this->token($this->subscriberKey, 'subscribe', array(array('match' => $topic)), dol_now() + 60, 'test');
		$received = '';
		$opened = false;
		$sub = curl_init($base.(strpos($base, '?') === false ? '?' : '&').'match='.rawurlencode($topic));
		curl_setopt_array($sub, array(
			CURLOPT_HTTPHEADER => array('Authorization: Bearer '.$subJwt, 'Accept: text/event-stream'),
			CURLOPT_CONNECTTIMEOUT => 3,
			CURLOPT_TIMEOUT => 6,
			CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
			CURLOPT_HEADERFUNCTION => function ($ch, $header) use (&$opened) {
				$opened = true;
				return strlen($header);
			},
			CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$received, $mark) {
				$received .= $chunk;
				// Stop reading as soon as the test came back
				return strpos($received, $mark) !== false ? -1 : strlen($chunk);
			},
		));
		$mh = curl_multi_init();
		curl_multi_add_handle($mh, $sub);
		$published = null;
		do {
			curl_multi_exec($mh, $running);
			if ($opened && $published === null && (int) curl_getinfo($sub, CURLINFO_RESPONSE_CODE) > 0) {
				if ((int) curl_getinfo($sub, CURLINFO_RESPONSE_CODE) !== 200) {
					break;
				}
				// Subscribed: publish the test (numbers only, as every event)
				usleep(150000);
				$res = $this->send($base, $this->publisherToken(array(array('match' => $topic)), dol_now() + 60), array($this->publishBody($topic, array('type' => 'test', 'mark' => $mark))));
				$published = $res[0];
			}
			if ($running) {
				curl_multi_select($mh, 0.1);
			}
		} while ($running && strpos($received, $mark) === false && microtime(true) - $start < 6);
		$subCode = (int) curl_getinfo($sub, CURLINFO_RESPONSE_CODE);
		$subError = (string) curl_error($sub);
		curl_multi_remove_handle($mh, $sub);
		curl_close($sub);
		curl_multi_close($mh);
		$ms = (int) round((microtime(true) - $start) * 1000);
		if ($subCode !== 200) {
			return array('ok' => false, 'message' => $langs->trans('InboxPushTestSubscribeFailed', $subCode ? 'HTTP '.$subCode : $subError), 'ms' => $ms);
		}
		if (!$published || $published['code'] < 200 || $published['code'] >= 300) {
			return array('ok' => false, 'message' => $langs->trans('InboxPushTestPublishFailed', $published && $published['code'] ? 'HTTP '.$published['code'] : ($published ? $published['error'] : '-')), 'ms' => $ms);
		}
		if (strpos($received, $mark) === false) {
			return array('ok' => false, 'message' => $langs->trans('InboxPushTestNotReceived'), 'ms' => $ms);
		}
		// The address of the browsers, when it is another one: a hub refuses a subscription without token (401)
		if ($this->publishUrl !== '' && $this->publishUrl !== $this->hubUrl) {
			$ch = curl_init($this->hubUrl.(strpos($this->hubUrl, '?') === false ? '?' : '&').'match='.rawurlencode($topic));
			curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 4, CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS));
			curl_exec($ch);
			$publicCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
			curl_close($ch);
			if (!in_array($publicCode, array(400, 401), true)) {
				return array('ok' => false, 'message' => $langs->trans('InboxPushTestPublicUrl', $publicCode ? 'HTTP '.$publicCode : '-'), 'ms' => $ms);
			}
		}

		return array('ok' => true, 'message' => $langs->trans('InboxPushTestOk', $ms), 'ms' => $ms);
	}

	/**
	 * @return string
	 */
	public function error()
	{
		return $this->error;
	}

	/**
	 * @param array<int,array<string,string>> $topics  Topic selectors
	 * @param int                             $expires End
	 * @return string
	 */
	protected function publisherToken(array $topics, $expires)
	{
		return $this->token($this->publisherKey, 'publish', $topics, $expires, 'dolibarr');
	}

	/**
	 * Access token of Mercure 1 (RFC 9068, HS256): iss, aud (both addresses of the hub by default), exp, one grant
	 *
	 * @param string                          $key     Key
	 * @param string                          $action  publish or subscribe
	 * @param array<int,array<string,string>> $topics  Topic selectors {match, match_type?}
	 * @param int                             $expires End of validity
	 * @param string                          $sub     Subject (numbers only)
	 * @param string[]                        $aud     Audience, empty = both addresses of the hub
	 * @return string
	 */
	public function token($key, $action, array $topics, $expires, $sub, array $aud = array())
	{
		$now = dol_now();
		$aud = array_values(array_unique(array_filter($aud ?: array($this->hubUrl, $this->publishUrl))));
		$h = self::b64(json_encode(array('alg' => 'HS256', 'typ' => 'at+jwt')));
		$p = self::b64(json_encode(array(
			'iss' => $this->issuer,
			'aud' => count($aud) === 1 ? $aud[0] : $aud,
			'sub' => (string) $sub,
			'client_id' => 'dolibarr-inbox',
			'iat' => $now,
			'exp' => (int) $expires,
			'jti' => bin2hex(random_bytes(8)),
			'authorization_details' => array(array('type' => self::GRANT, 'actions' => array($action), 'topics' => array_values($topics))),
		), JSON_UNESCAPED_SLASHES));

		return $h.'.'.$p.'.'.self::b64(hash_hmac('sha256', $h.'.'.$p, (string) $key, true));
	}

	/**
	 * @param string $bin Text
	 * @return string     Base64 for an URL, without padding
	 */
	protected static function b64($bin)
	{
		return rtrim(strtr(base64_encode((string) $bin), '+/', '-_'), '=');
	}
}
