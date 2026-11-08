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
 * \file    inbox/class/inboxwhisper.class.php
 * \ingroup inbox
 * \brief   Voice typing by a transcription server (1.35.0): an OpenAI compatible address (Whisper installed on the
 *          network, or a service in the cloud), called by the server of Dolibarr only
 */

require_once __DIR__.'/inboxmeili.class.php';

/**
 * The address ends with the version of the API (http://127.0.0.1:8000/v1, https://api.openai.com/v1): inbox calls
 * GET /models (test) and POST /audio/transcriptions. The key is stored encrypted, never sent back to the browser nor
 * written in an error.
 */
class InboxWhisper
{
	/** Largest piece of voice sent at once (a sentence of 20 seconds is far below) */
	const MAX_BYTES = 8388608;

	/** Model when none is set: the one of the OpenAI API */
	const DEFAULT_MODEL = 'whisper-1';

	/** @var string Address, without the last / */
	public $url;

	/** @var string Key, '' when the server asks none */
	protected $key;

	/** @var int Seconds */
	public $timeout = 30;

	/** @var string Last error, never with the key */
	public $error = '';

	/** @var int Last HTTP code */
	public $code = 0;

	/**
	 * @param string $url Address
	 * @param string $key Key ('' for none)
	 */
	public function __construct($url, $key)
	{
		$this->url = rtrim(trim((string) $url), '/');
		$this->key = (string) $key;
	}

	/**
	 * @return bool The administrator chose the transcription server for the voice typing
	 */
	public static function chosen()
	{
		return getDolGlobalString('INBOX_DICTATION_ENGINE') === 'server' && getDolGlobalString('INBOX_WHISPER_URL') !== '';
	}

	/**
	 * @return InboxWhisper Client of the address set, with its key
	 */
	public static function fromConf()
	{
		$kept = getDolGlobalString('INBOX_WHISPER_KEY');

		return new self(getDolGlobalString('INBOX_WHISPER_URL'), $kept !== '' ? (string) dolDecrypt($kept) : '');
	}

	/**
	 * @return string Model set, else the one of the OpenAI API
	 */
	public static function model()
	{
		return getDolGlobalString('INBOX_WHISPER_MODEL', self::DEFAULT_MODEL);
	}

	/**
	 * @param string $url Address
	 * @return string Its host, as shown to the users who give their voice
	 */
	public static function hostOf($url)
	{
		return strtolower(trim((string) parse_url(trim((string) $url), PHP_URL_HOST), '[]'));
	}

	/**
	 * @return array<int,string>|null Models offered by the server, null on error (error, code)
	 */
	public function models()
	{
		$json = $this->send('GET', '/models', null);
		if ($json === null) {
			return null;
		}
		$out = array();
		foreach ((array) ($json['data'] ?? array()) as $m) {
			if (is_array($m) && isset($m['id']) && is_string($m['id'])) {
				$out[] = $m['id'];
			}
		}

		return $out;
	}

	/**
	 * @param string $file     Path of the recorded piece of voice
	 * @param string $mime     Its type (audio/webm, audio/ogg, audio/mp4...)
	 * @param string $language Two letters (fr, en...), '' to let the server find it
	 * @param string $model    Model
	 * @return string|null Text said, null on error (error, code)
	 */
	public function transcribe($file, $mime, $language, $model)
	{
		$ext = array('audio/webm' => 'webm', 'audio/ogg' => 'ogg', 'audio/mp4' => 'mp4', 'audio/mpeg' => 'mp3', 'audio/wav' => 'wav', 'audio/x-wav' => 'wav');
		$fields = array(
			'file' => new CURLFile($file, $mime, 'voice.'.($ext[$mime] ?? 'webm')),
			'model' => (string) $model,
			'response_format' => 'json',
			'temperature' => '0',
		);
		if (preg_match('/^[a-z]{2}$/', (string) $language)) {
			$fields['language'] = (string) $language;
		}
		$json = $this->send('POST', '/audio/transcriptions', $fields);
		if ($json === null) {
			return null;
		}
		if (!isset($json['text']) || !is_string($json['text'])) {
			$this->error = 'No text in the answer';
			return null;
		}

		return trim($json['text']);
	}

	/**
	 * @param string                  $method GET or POST
	 * @param string                  $path   Path after the address
	 * @param array<string,mixed>|null $fields Fields of a multipart POST
	 * @return array<mixed>|null Answer, null on error
	 */
	protected function send($method, $path, $fields)
	{
		$this->error = '';
		$this->code = 0;
		if (!InboxMeili::validUrl($this->url)) {
			$this->error = 'No address';
			return null;
		}
		$headers = array('Accept: application/json');
		if ($this->key !== '') {
			$headers[] = 'Authorization: Bearer '.$this->key;
		}
		$ch = curl_init($this->url.$path);
		$opts = array(
			CURLOPT_CUSTOMREQUEST => $method,
			CURLOPT_HTTPHEADER => $headers,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT => min(5, $this->timeout),
			CURLOPT_TIMEOUT => $this->timeout,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
		);
		if ($fields !== null) {
			$opts[CURLOPT_POSTFIELDS] = $fields;
		}
		curl_setopt_array($ch, $opts);
		$raw = curl_exec($ch);
		$this->code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		if ($raw === false) {
			$this->error = curl_error($ch) ?: 'No answer';
			return null;
		}
		$json = $raw === '' ? array() : json_decode((string) $raw, true);
		if ($this->code >= 400 || !is_array($json)) {
			$said = '';
			if (is_array($json)) {
				// OpenAI: error.message; FastAPI servers: detail (text or list); others: error or message
				$said = $json['error']['message'] ?? ($json['detail'] ?? ($json['error'] ?? ($json['message'] ?? '')));
				$said = is_string($said) ? $said : (string) json_encode($said);
			}
			$this->error = trim('HTTP '.$this->code.' '.dol_trunc($said, 200));
			if ($this->key !== '') {
				$this->error = str_replace($this->key, '***', $this->error);
			}
			return null;
		}

		return $json;
	}
}
