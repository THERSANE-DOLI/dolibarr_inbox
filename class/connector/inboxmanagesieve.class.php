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
 * \file    inbox/class/connector/inboxmanagesieve.class.php
 * \ingroup inbox
 * \brief   Session ManageSieve (RFC 5804) already opened and authenticated (lot W8 1.23.0)
 */

/**
 * Commands of the rules of a mailbox: list, read, check, write, activate, remove the scripts. Made by
 * InboxImapConnector::sieveSession(), which opens the socket (STARTTLS, AUTHENTICATE).
 */
class InboxManageSieve
{
	/** Largest script read or written */
	const MAX_SCRIPT = 262144;

	/** @var resource */
	protected $socket;

	/** @var string[] Extensions of the Sieve language announced by the server */
	public $extensions = array();

	/** @var string Last answer of the server when it refused (NO) */
	public $error = '';

	/**
	 * @param resource $socket     Socket authenticated
	 * @param string[] $extensions Extensions announced
	 */
	public function __construct($socket, array $extensions)
	{
		$this->socket = $socket;
		$this->extensions = array_map('strtolower', $extensions);
	}

	/**
	 * @param string $ext Extension (fileinto, imap4flags...)
	 * @return bool
	 */
	public function has($ext)
	{
		return in_array(strtolower($ext), $this->extensions, true);
	}

	/**
	 * @param string $s Text
	 * @return string Quoted string of the protocol
	 */
	protected static function quote($s)
	{
		return '"'.str_replace(array('\\', '"'), array('\\\\', '\\"'), preg_replace('/[\r\n]+/', ' ', (string) $s)).'"';
	}

	/**
	 * @param string $s Content
	 * @return string Literal (non synchronising), the content after it
	 */
	protected static function literal($s)
	{
		return '{'.strlen($s).'+}'."\r\n".$s;
	}

	/**
	 * One answer: the lines (literals read whole), then OK, NO or BYE
	 *
	 * @return array{status:string,lines:string[],text:string}
	 */
	protected function read()
	{
		$lines = array();
		while (($line = fgets($this->socket, 8192)) !== false) {
			$line = rtrim($line, "\r\n");
			if (preg_match('/\{(\d+)\+?\}$/', $line, $m)) {
				$n = (int) $m[1];
				if ($n > self::MAX_SCRIPT) {
					return array('status' => 'BYE', 'lines' => $lines, 'text' => 'Too large');
				}
				$data = '';
				while (strlen($data) < $n && !feof($this->socket)) {
					$chunk = fread($this->socket, $n - strlen($data));
					if ($chunk === false || $chunk === '') {
						break;
					}
					$data .= $chunk;
				}
				$head = substr($line, 0, -strlen($m[0]));
				if (preg_match('/^(OK|NO|BYE)\b/i', $head, $s)) {
					// Answer with its text as a literal (NO {n} errors of CHECKSCRIPT)
					fgets($this->socket, 8192);
					return array('status' => strtoupper($s[1]), 'lines' => $lines, 'text' => $data);
				}
				$lines[] = $data;
				continue;
			}
			if (preg_match('/^(OK|NO|BYE)\b\s*(?:\([^)]*\)\s*)?(.*)$/i', $line, $m)) {
				return array('status' => strtoupper($m[1]), 'lines' => $lines, 'text' => trim($m[2], ' "'));
			}
			$lines[] = $line;
		}

		return array('status' => 'BYE', 'lines' => $lines, 'text' => 'Connection lost');
	}

	/**
	 * @param string $command Command (with its arguments)
	 * @return array{status:string,lines:string[],text:string}
	 */
	protected function call($command)
	{
		$this->error = '';
		fwrite($this->socket, $command."\r\n");
		$res = $this->read();
		if ($res['status'] !== 'OK') {
			$this->error = $res['text'] !== '' ? $res['text'] : $res['status'];
		}

		return $res;
	}

	/**
	 * @return array<int,array{name:string,active:bool}>|null
	 */
	public function listScripts()
	{
		$res = $this->call('LISTSCRIPTS');
		if ($res['status'] !== 'OK') {
			return null;
		}
		$out = array();
		foreach ($res['lines'] as $line) {
			if (preg_match('/^"((?:[^"\\\\]|\\\\.)*)"(\s+ACTIVE)?/i', $line, $m)) {
				$out[] = array('name' => stripcslashes($m[1]), 'active' => !empty($m[2]));
			}
		}

		return $out;
	}

	/**
	 * @param string $name Script
	 * @return string|null Content, null when it does not exist
	 */
	public function getScript($name)
	{
		$res = $this->call('GETSCRIPT '.self::quote($name));

		return $res['status'] === 'OK' ? (string) ($res['lines'][0] ?? '') : null;
	}

	/**
	 * @param string $content Script
	 * @return bool Valid for this server (error in ->error otherwise)
	 */
	public function checkScript($content)
	{
		return $this->call('CHECKSCRIPT '.self::literal($content))['status'] === 'OK';
	}

	/**
	 * @param string $name    Script
	 * @param string $content Content
	 * @return bool
	 */
	public function putScript($name, $content)
	{
		return $this->call('PUTSCRIPT '.self::quote($name).' '.self::literal($content))['status'] === 'OK';
	}

	/**
	 * @param string $name Script to make active ('' = none)
	 * @return bool
	 */
	public function setActive($name)
	{
		return $this->call('SETACTIVE '.self::quote($name))['status'] === 'OK';
	}

	/**
	 * @param string $name Script
	 * @return bool
	 */
	public function deleteScript($name)
	{
		return $this->call('DELETESCRIPT '.self::quote($name))['status'] === 'OK';
	}

	/**
	 * @return void
	 */
	public function close()
	{
		if (is_resource($this->socket)) {
			@fwrite($this->socket, "LOGOUT\r\n");
			@fclose($this->socket);
		}
	}
}
