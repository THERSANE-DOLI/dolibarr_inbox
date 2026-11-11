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
 * \file    inbox/class/inboxmeili.class.php
 * \ingroup inbox
 * \brief   Small client of the HTTP API of Meilisearch (lot W7 1.20.0): keys, indexes, documents, federated search
 */

/**
 * Only what inbox needs, with curl. The key is never written in a log, an error or an answer to the browser.
 */
class InboxMeili
{
	/** Marks of the words found in the crops (private use characters: escaped text, then marked by the application) */
	const MARK_START = "\u{E000}";
	const MARK_END = "\u{E001}";

	/** @var string Base address, without the trailing slash */
	protected $url;

	/** @var string Key sent */
	protected $key;

	/** @var int Time limit of a call, in seconds */
	public $timeout = 10;

	/** @var string Last error, never with the key */
	public $error = '';

	/** @var int HTTP code of the last call (0: no answer) */
	public $code = 0;

	/**
	 * @param string $url Address of the instance (http://127.0.0.1:7700)
	 * @param string $key Key (master key or key of inbox)
	 */
	public function __construct($url, $key)
	{
		$this->url = rtrim(trim((string) $url), '/');
		$this->key = (string) $key;
	}

	/**
	 * @param string $url Address typed
	 * @return bool http(s) address with a host, no user or query in it
	 */
	public static function validUrl($url)
	{
		$p = parse_url(trim((string) $url));

		return is_array($p) && in_array(strtolower($p['scheme'] ?? ''), array('http', 'https'), true) && !empty($p['host'])
			&& !isset($p['user']) && !isset($p['pass']) && !isset($p['query']) && !isset($p['fragment']);
	}

	/**
	 * @param string $url Address of the instance
	 * @return bool The host is this server or a private network: the text of the mails stays inside
	 */
	public static function isLocal($url)
	{
		$host = strtolower(trim((string) parse_url(trim((string) $url), PHP_URL_HOST), '[]'));
		if ($host === 'localhost' || substr($host, -6) === '.local' || substr($host, -10) === '.localhost' || strpos($host, '.') === false && strpos($host, ':') === false) {
			return true;
		}
		$ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : gethostbyname($host);
		if (!filter_var($ip, FILTER_VALIDATE_IP)) {
			return false;
		}
		require_once __DIR__.'/inboxservertester.class.php';

		// An IPv4 written in an IPv6 is the IPv4 (audit INBX-051)
		return InboxServerTester::ipPrivate($ip);
	}

	/**
	 * @param string            $method GET, POST, PATCH, PUT, DELETE
	 * @param string            $path   Path (/indexes)
	 * @param array<mixed>|null $body   JSON body
	 * @return array<mixed>|null Answer, null on error (error, code)
	 */
	public function call($method, $path, $body = null)
	{
		$this->error = '';
		$this->code = 0;
		if ($this->url === '') {
			$this->error = 'No address';
			return null;
		}
		$ch = curl_init($this->url.$path);
		$headers = array('Accept: application/json', 'Authorization: Bearer '.$this->key);
		if ($body !== null) {
			$headers[] = 'Content-Type: application/json';
			curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
		}
		curl_setopt_array($ch, array(
			CURLOPT_CUSTOMREQUEST => $method,
			CURLOPT_HTTPHEADER => $headers,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT => min(5, $this->timeout),
			CURLOPT_TIMEOUT => $this->timeout,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
		));
		$raw = curl_exec($ch);
		$this->code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		if ($raw === false) {
			$this->error = curl_error($ch) ?: 'No answer';
			return null;
		}
		$json = $raw === '' ? array() : json_decode((string) $raw, true);
		if ($this->code >= 400 || !is_array($json)) {
			$this->error = is_array($json) && isset($json['message']) ? (string) $json['message'] : 'HTTP '.$this->code;
			$this->error = str_replace($this->key, '***', $this->error);
			return null;
		}

		return $json;
	}

	/**
	 * @return string Version of Meilisearch, '' if it does not answer with this key
	 */
	public function version()
	{
		$res = $this->call('GET', '/version');

		return is_array($res) ? (string) ($res['pkgVersion'] ?? '') : '';
	}

	/**
	 * Key of inbox, limited to the indexes of the prefix (needs the master key)
	 *
	 * @param string $prefix Prefix of the indexes
	 * @param string $name   Name of the key shown in Meilisearch
	 * @return array{key:string,uid:string}|null
	 */
	public function createKey($prefix, $name)
	{
		$res = $this->call('POST', '/keys', array(
			'name' => $name,
			'description' => 'inbox: indexes '.$prefix.'_*',
			'actions' => array('search', 'documents.add', 'documents.get', 'documents.delete', 'indexes.create', 'indexes.get', 'indexes.delete', 'settings.update', 'settings.get', 'tasks.get'),
			'indexes' => array($prefix.'_*'),
			'expiresAt' => null,
		));

		return is_array($res) && !empty($res['key']) ? array('key' => (string) $res['key'], 'uid' => (string) ($res['uid'] ?? '')) : null;
	}

	/**
	 * @param string $uid Key to remove (master key needed)
	 * @return bool
	 */
	public function deleteKey($uid)
	{
		return $this->call('DELETE', '/keys/'.rawurlencode((string) $uid)) !== null || $this->code === 404;
	}

	/**
	 * Index of a mailbox, created with its settings when missing
	 *
	 * @param string $uid Index
	 * @return bool
	 */
	public function ensureIndex($uid)
	{
		if ($this->call('GET', '/indexes/'.rawurlencode($uid)) === null) {
			if ($this->code !== 404) {
				return false;
			}
			if ($this->call('POST', '/indexes', array('uid' => $uid, 'primaryKey' => 'id')) === null) {
				return false;
			}
		}
		// Order of the fields: a word in the subject counts more than in the text
		return $this->call('PATCH', '/indexes/'.rawurlencode($uid).'/settings', array(
			'searchableAttributes' => array('subject', 'from', 'to', 'text', 'attachments'),
			'displayedAttributes' => array('id', 'text'),
		)) !== null;
	}

	/**
	 * @param string $prefix Prefix
	 * @return string[] Indexes of the prefix
	 */
	public function indexes($prefix)
	{
		$out = array();
		for ($offset = 0; $offset < 100000; $offset += 200) {
			$res = $this->call('GET', '/indexes?limit=200&offset='.$offset);
			if (!is_array($res)) {
				break;
			}
			foreach ((array) ($res['results'] ?? array()) as $i) {
				if (strpos((string) ($i['uid'] ?? ''), $prefix.'_') === 0) {
					$out[] = (string) $i['uid'];
				}
			}
			if (count((array) ($res['results'] ?? array())) < 200) {
				break;
			}
		}

		return $out;
	}

	/**
	 * @param string $uid Index
	 * @return bool Removed, or already gone
	 */
	public function deleteIndex($uid)
	{
		$res = $this->call('DELETE', '/indexes/'.rawurlencode($uid));
		if ($res === null) {
			return $this->code === 404;
		}
		// Applied in the background: waited for, so that the index is really gone when the screen says so
		if (!empty($res['taskUid'])) {
			$this->waitTask((int) $res['taskUid'], 10);
		}

		return true;
	}

	/**
	 * @param string                       $uid  Index
	 * @param array<int,array<string,mixed>> $docs Documents
	 * @return int Task, 0 on error
	 */
	public function addDocuments($uid, array $docs)
	{
		$res = $this->call('POST', '/indexes/'.rawurlencode($uid).'/documents', array_values($docs));

		return is_array($res) ? (int) ($res['taskUid'] ?? 0) : 0;
	}

	/**
	 * @param string $uid Index
	 * @param int[]  $ids Documents
	 * @return bool
	 */
	public function deleteDocuments($uid, array $ids)
	{
		return !$ids || $this->call('POST', '/indexes/'.rawurlencode($uid).'/documents/delete-batch', array_values(array_map('intval', $ids))) !== null;
	}

	/**
	 * Identifiers of the documents of an index, by pages
	 *
	 * @param string $uid    Index
	 * @param int    $offset First
	 * @param int    $limit  Count
	 * @return int[]|null
	 */
	public function documentIds($uid, $offset, $limit)
	{
		$res = $this->call('GET', '/indexes/'.rawurlencode($uid).'/documents?fields=id&limit='.((int) $limit).'&offset='.((int) $offset));
		if (!is_array($res)) {
			return null;
		}

		return array_map(function ($d) {
			return (int) ($d['id'] ?? 0);
		}, (array) ($res['results'] ?? array()));
	}

	/**
	 * Wait for a task (tests, and the removal before a check)
	 *
	 * @param int $task    Task
	 * @param int $seconds Time limit
	 * @return bool Succeeded
	 */
	public function waitTask($task, $seconds = 10)
	{
		$end = microtime(true) + $seconds;
		do {
			$res = $this->call('GET', '/tasks/'.((int) $task));
			if (is_array($res) && in_array($res['status'] ?? '', array('succeeded', 'failed', 'canceled'), true)) {
				return $res['status'] === 'succeeded';
			}
			usleep(100000);
		} while (microtime(true) < $end);

		return false;
	}

	/**
	 * One search in several indexes, results merged by relevance
	 *
	 * @param string[] $uids  Indexes (only those of the mailboxes the user may read)
	 * @param string   $q     Words
	 * @param int      $limit Hits
	 * @return array<int,array{id:int,index:string,crop:string}>|null Most relevant first
	 */
	public function federatedSearch(array $uids, $q, $limit = 1000)
	{
		if (!$uids) {
			return array();
		}
		$queries = array();
		foreach ($uids as $uid) {
			$queries[] = array(
				'indexUid' => $uid, 'q' => (string) $q, 'attributesToRetrieve' => array('id'),
				'attributesToCrop' => array('text'), 'cropLength' => 24, 'attributesToHighlight' => array('text'),
				'highlightPreTag' => self::MARK_START, 'highlightPostTag' => self::MARK_END,
			);
		}
		$res = $this->call('POST', '/multi-search', array('federation' => array('limit' => (int) $limit), 'queries' => $queries));
		if (!is_array($res)) {
			return null;
		}
		$out = array();
		foreach ((array) ($res['hits'] ?? array()) as $h) {
			$crop = (string) ($h['_formatted']['text'] ?? '');
			$out[] = array(
				'id' => (int) ($h['id'] ?? 0),
				'index' => (string) ($h['_federation']['indexUid'] ?? ''),
				// Only a crop that holds a word found is useful
				'crop' => strpos($crop, self::MARK_START) !== false ? trim(preg_replace('/\s+/u', ' ', $crop)) : '',
			);
		}

		return $out;
	}
}
