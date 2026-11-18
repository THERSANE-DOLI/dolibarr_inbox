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
 * \file    inbox/class/push/inboxpushdriverinterface.class.php
 * \ingroup inbox
 * \brief   A push service that delivers the events of the journal at once (instant refresh of the lists)
 */

/**
 * A push driver (1.124.0). The journal (llx_inbox_event) stays the reference: a driver only delivers its events
 * sooner, the light call live.php catches up whatever it missed. inbox ships Mercure; another module adds a driver
 * with the hook inboxPushDrivers (see InboxPush::drivers()).
 *
 * Rules every driver must keep:
 * - an event holds numbers only (the ones of the journal), never a subject, a text, an address or a name;
 * - a user receives the topics given to subscriber() only, and the service itself refuses the others (filter on the
 *   server, never in the browser);
 * - no secret of publication ever goes to the browser.
 */
interface InboxPushDriverInterface
{
	/**
	 * @return string Code (a-z, 0-9, _)
	 */
	public function code();

	/**
	 * @return string Translation key of its name
	 */
	public function label();

	/**
	 * Settings it needs, shown in the setup of inbox (stored as INBOX_PUSH_<CODE>_<KEY>, globally)
	 *
	 * @return array<string,array{type:string,label:string,help?:string,default?:string,required?:bool}> Key => field;
	 *                                                       type 'url', 'string' or 'secret' (stored encrypted, never shown again)
	 */
	public function fields();

	/**
	 * @param array<string,string> $values    Values of fields() (secrets decrypted)
	 * @param string               $namespace Mark of this instance of Dolibarr (several instances on one service)
	 * @return void
	 */
	public function configure(array $values, $namespace);

	/**
	 * @return bool The settings needed are filled
	 */
	public function isConfigured();

	/**
	 * Deliver events (called once at the end of the request, with all its events)
	 *
	 * @param array<int,array{topic:string,id:int,data:array<string,mixed>}> $messages Topic 'account/<id>' or 'user/<id>'
	 * @return bool  All delivered (false: error() says why, the light call catches up)
	 */
	public function publish(array $messages);

	/**
	 * What the browser needs to receive the given topics only, valid until $expires. No secret of publication.
	 *
	 * @param string[] $topics  Topics 'account/<id>', 'user/<id>'
	 * @param int      $userId  User
	 * @param int      $expires End of validity (timestamp)
	 * @return array<string,mixed>|null Given to the adapter of the browser; null if not configured
	 */
	public function subscriber(array $topics, $userId, $expires);

	/**
	 * @return string Script of the adapter of the browser, path for dol_buildpath() (e.g. /inbox/js/push/x.js)
	 */
	public function jsFile();

	/**
	 * Publish an event of test and receive it, for the button of the setup
	 *
	 * @return array{ok:bool,message:string,ms:int}
	 */
	public function test();

	/**
	 * @return string Last error, without any secret
	 */
	public function error();
}
