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
 * \file    inbox/class/inboxdictation.class.php
 * \ingroup inbox
 * \brief   Voice typing in the writing (1.28.0): who may use it, consent of each user per receiver of the voice
 */

require_once __DIR__.'/inboxaccount.class.php';
require_once __DIR__.'/inboxaccess.class.php';
require_once __DIR__.'/inboxuserparam.class.php';
require_once __DIR__.'/inboxactionlog.class.php';

/**
 * The speech recognition of the browser sends the voice to the servers of its maker: the administrator allows the
 * voice typing (INBOX_DICTATION_ENABLED, off by default), a mailbox may forbid it, and each user agrees once per
 * receiver of the voice (Google, Microsoft, Apple...), told by name. Consents and withdrawals go in the journal.
 */
class InboxDictation
{
	/**
	 * Receivers of the voice known from the browser ('browser': not known, « the maker of your browser »), and the
	 * transcription server chosen by the administrator ('server', 1.35.0)
	 */
	const PROVIDERS = array('google', 'microsoft', 'apple', 'browser', 'server');

	/** Parameter of the user holding the consents: provider => date */
	const PARAM = 'dictation_consent';

	/** Parameter of the user counting the pieces of voice sent to the transcription server in the hour: {h, n} */
	const USAGE = 'dictation_usage';

	/** Pieces of voice sent to the transcription server per user and per hour, at most (INBOX_WHISPER_MAX_PER_HOUR) */
	const MAX_PER_HOUR = 300;

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
	 * @return bool The administrator allows the voice typing
	 */
	public static function enabled()
	{
		return getDolGlobalInt('INBOX_DICTATION_ENABLED') === 1;
	}

	/**
	 * Mailboxes the user may dictate from: level « send », mailbox not excluded
	 *
	 * @param User $user User
	 * @return int[]
	 */
	public function accountsFor(User $user)
	{
		if (!self::enabled()) {
			return array();
		}
		$out = array();
		$access = new InboxAccess($this->db);
		foreach ($access->getAccounts($user, InboxAccess::LEVEL_SEND) as $id => $account) {
			$a = new InboxAccount($this->db);
			if ($a->fetch((int) $id) > 0) {
				$config = $a->getConnectorConfig();
				if (empty($config['dictation_off'])) {
					$out[] = (int) $id;
				}
			}
		}

		return $out;
	}

	/**
	 * @param User $user User
	 * @return array<string,int> Receivers agreed to => date of the consent
	 */
	public function consents(User $user)
	{
		$params = new InboxUserParam($this->db);
		$list = json_decode((string) $params->get($user, self::PARAM), true);
		$out = array();
		// A consent to the transcription server is for its address of then: another host asks it again
		$stamp = getDolGlobalInt('INBOX_WHISPER_STAMP');
		foreach (is_array($list) ? $list : array() as $provider => $date) {
			if (in_array($provider, self::PROVIDERS, true) && ($provider !== 'server' || (int) $date >= $stamp)) {
				$out[$provider] = (int) $date;
			}
		}

		return $out;
	}

	/**
	 * What the writing needs to know of the engine (1.35.0): the browser, or the transcription server, its host and
	 * whether it is on the private network (then no consent: the voice stays inside)
	 *
	 * @return array{engine:string,host:string,local:bool}
	 */
	public static function engine()
	{
		dol_include_once('/inbox/class/inboxwhisper.class.php');
		if (!InboxWhisper::chosen()) {
			return array('engine' => 'browser', 'host' => '', 'local' => false);
		}
		$url = getDolGlobalString('INBOX_WHISPER_URL');

		return array('engine' => 'server', 'host' => InboxWhisper::hostOf($url), 'local' => InboxMeili::isLocal($url));
	}

	/**
	 * The user agrees that his voice goes to this receiver (written in the journal of the mailbox)
	 *
	 * @param User   $user      User
	 * @param string $provider  Receiver
	 * @param int    $accountId Mailbox of the writing (one he may dictate from)
	 * @return bool
	 */
	public function consent(User $user, $provider, $accountId)
	{
		if (!in_array($provider, self::PROVIDERS, true) || !in_array((int) $accountId, $this->accountsFor($user), true)) {
			return false;
		}
		$list = $this->consents($user);
		$list[$provider] = dol_now();
		$params = new InboxUserParam($this->db);
		if ($params->set($user, self::PARAM, (string) json_encode($list)) < 0) {
			return false;
		}
		InboxActionLog::add($this->db, $user, (int) $accountId, 'dictation_on', array((object) array('subject' => '')), '', '', 0, $provider);

		return true;
	}

	/**
	 * The user withdraws his consent (one receiver, or all of them with '')
	 *
	 * @param User   $user     User
	 * @param string $provider Receiver, '' for all
	 * @return void
	 */
	public function revoke(User $user, $provider)
	{
		$list = $this->consents($user);
		$gone = $provider === '' ? array_keys($list) : array_intersect(array($provider), array_keys($list));
		foreach ($gone as $p) {
			unset($list[$p]);
		}
		$params = new InboxUserParam($this->db);
		if ($list) {
			$params->set($user, self::PARAM, (string) json_encode($list));
		} else {
			$params->remove($user, self::PARAM);
		}
		foreach ($gone as $p) {
			InboxActionLog::add($this->db, $user, 0, 'dictation_off', array((object) array('subject' => '')), '', '', 0, $p);
		}
	}

	/**
	 * One more piece of voice for the transcription server, within the cap of the hour of the user: the server of
	 * Dolibarr does not relay without end to a service maybe paid or shared (audit INBX-033)
	 *
	 * @param User $user User
	 * @return bool false when the cap of the hour is reached
	 */
	public function countPiece(User $user)
	{
		$params = new InboxUserParam($this->db);
		$hour = (int) floor(dol_now() / 3600);
		$usage = json_decode((string) $params->get($user, self::USAGE), true);
		$n = is_array($usage) && (int) ($usage['h'] ?? 0) === $hour ? (int) ($usage['n'] ?? 0) : 0;
		if ($n >= max(1, getDolGlobalInt('INBOX_WHISPER_MAX_PER_HOUR', self::MAX_PER_HOUR))) {
			return false;
		}

		return $params->set($user, self::USAGE, (string) json_encode(array('h' => $hour, 'n' => $n + 1))) > 0;
	}
}
