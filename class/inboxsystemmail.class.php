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
 * \file    inbox/class/inboxsystemmail.class.php
 * \ingroup inbox
 * \brief   Small mails made by inbox on a click of the user (1.22.0): answer to an invitation, read receipt
 */

require_once __DIR__.'/inboxmailbuilder.class.php';
require_once __DIR__.'/connector/inboxconnectorfactory.class.php';

/**
 * Sent at once by the server of the mailbox, never copied in Sent (as the mail software do for these answers), never
 * through the queue (nothing to cancel: the click is the decision)
 */
class InboxSystemMail
{
	/** @var string */
	public $error = '';

	/** @var string Code of the error (smtp_..., mails_disabled, smtp_no_server) */
	public $errorCode = '';

	/**
	 * @param string $text Text
	 * @return string Encoded word when not ASCII
	 */
	public static function header($text)
	{
		$text = preg_replace('/[\r\n]+/', ' ', (string) $text);

		return preg_match('/[^\x20-\x7E]/', $text) ? mb_encode_mimeheader($text, 'UTF-8', 'B', "\r\n") : $text;
	}

	/**
	 * @param array{email:string,name:string} $a Address
	 * @return string
	 */
	public static function address(array $a)
	{
		$name = trim((string) ($a['name'] ?? ''));
		if ($name === '') {
			return $a['email'];
		}

		return (preg_match('/[^\x20-\x7E]/', $name) ? self::header($name) : '"'.addcslashes($name, '"\\').'"').' <'.$a['email'].'>';
	}

	/**
	 * One MIME part
	 *
	 * @param string $type    Content-Type (with its parameters)
	 * @param string $content Content
	 * @param bool   $base64  Encoded in base64 (else 7bit, ASCII only)
	 * @return string
	 */
	public static function part($type, $content, $base64 = true)
	{
		return 'Content-Type: '.$type."\r\n".($base64 ? "Content-Transfer-Encoding: base64\r\n\r\n".rtrim(chunk_split(base64_encode((string) $content), 76, "\r\n"))
			: "Content-Transfer-Encoding: 7bit\r\n\r\n".str_replace(array("\r\n", "\n"), array("\n", "\r\n"), rtrim((string) $content)))."\r\n";
	}

	/**
	 * @param string   $type     multipart/... with its parameters, without the boundary
	 * @param string[] $parts    Parts (part())
	 * @param string   $boundary Boundary
	 * @return array{0:string,1:string} Content-Type, body
	 */
	public static function multipart($type, array $parts, $boundary)
	{
		$body = '';
		foreach ($parts as $p) {
			$body .= '--'.$boundary."\r\n".$p;
		}

		return array($type.'; boundary="'.$boundary.'"', $body.'--'.$boundary."--\r\n");
	}

	/**
	 * Build and send
	 *
	 * @param DoliDB                          $db          Database handler
	 * @param InboxAccount                    $account     Mailbox (its server sends)
	 * @param array{email:string,name:string} $from        Sender
	 * @param string                          $to          Recipient address
	 * @param string                          $subject     Subject
	 * @param string                          $contentType Content-Type of the body
	 * @param string                          $body        Body
	 * @param array<string,string>            $headers     More headers (In-Reply-To, References, Auto-Submitted...)
	 * @return bool
	 */
	public function send($db, InboxAccount $account, array $from, $to, $subject, $contentType, $body, array $headers = array())
	{
		$this->error = '';
		$this->errorCode = '';
		if (getDolGlobalString('MAIN_DISABLE_ALL_MAILS')) {
			$this->errorCode = 'mails_disabled';
			return false;
		}
		if (!filter_var($to, FILTER_VALIDATE_EMAIL) || !filter_var($from['email'], FILTER_VALIDATE_EMAIL)) {
			$this->errorCode = 'bad_address';
			return false;
		}
		$head = 'From: '.self::address($from)."\r\n".'To: '.$to."\r\n".'Subject: '.self::header($subject)."\r\n"
			.'Date: '.date('r', dol_now())."\r\n".'Message-ID: <'.InboxMailBuilder::newMessageId($from['email']).">\r\n";
		foreach ($headers as $name => $value) {
			if (preg_match('/^[A-Za-z][A-Za-z0-9\-]{1,60}$/', $name) && (string) $value !== '') {
				$head .= $name.': '.preg_replace('/[\r\n]+/', ' ', (string) $value)."\r\n";
			}
		}
		$head .= "MIME-Version: 1.0\r\nContent-Type: ".$contentType."\r\n";
		$error = '';
		$connector = InboxConnectorFactory::forAccount($db, $account, $error);
		if (!$connector) {
			$this->error = $error;
			$this->errorCode = 'error';
			return false;
		}
		if (!$connector->can(InboxConnectorInterface::CAP_SEND)) {
			$connector->disconnect();
			$this->errorCode = 'smtp_no_server';
			return false;
		}
		$ok = $connector->send($head."\r\n".$body, array('from' => $from['email'], 'recipients' => array($to)));
		if (!$ok) {
			$code = method_exists($connector, 'getErrorCode') ? (string) $connector->getErrorCode() : '';
			$this->error = $connector->getError();
			$this->errorCode = $code !== '' ? $code : 'smtp_refused';
		}
		$connector->disconnect();

		return $ok;
	}

	/**
	 * Address the user writes with in a mailbox: its default identity there, else the mailbox
	 *
	 * @param DoliDB       $db      Database handler
	 * @param User         $user    User
	 * @param InboxAccount $account Mailbox
	 * @return array{email:string,name:string}
	 */
	public static function fromOf($db, User $user, InboxAccount $account)
	{
		require_once __DIR__.'/inboxcomposer.class.php';
		$composer = new InboxComposer($db);
		$from = array('email' => (string) $account->email, 'name' => (string) $account->label);
		foreach ($composer->identities($user) as $i) {
			if ($i['account'] === (int) $account->id && ($from['email'] === (string) $account->email || $i['default'])) {
				$from = array('email' => (string) $i['email'], 'name' => (string) $i['name']);
			}
		}

		return $from;
	}
}
