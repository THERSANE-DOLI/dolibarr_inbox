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
 * \file    inbox/class/inboxconsumerinterface.class.php
 * \ingroup inbox
 * \brief   What the class of a module subscribed to the mailboxes implements (1.112.0), and the event it receives
 */

/**
 * The class a module declares with the hook inboxConsumers (see InboxConsumer). inbox calls handle() once per event,
 * only for the mailboxes where a manager allowed the module, never twice for the same event.
 */
interface InboxConsumerInterface
{
	/**
	 * React to an event of a mailbox
	 *
	 * @param InboxConsumerEvent $event Event
	 * @return int 1 handled, 0 left (not for this module), -1 error (the event is given again later)
	 */
	public function handle(InboxConsumerEvent $event);
}

/**
 * An event given to a module subscribed: the mailbox, the mail (its Message-ID stays the same when it is moved), its
 * useful headers. Reading the body and acting on the mail go through inbox, within what the manager allowed.
 */
class InboxConsumerEvent
{
	/** @var string Event (mail.received) */
	public $event = '';

	/** @var string Code of the declaration of the module */
	public $consumer = '';

	/** @var int Mailbox */
	public $accountId = 0;

	/** @var string Address of the mailbox */
	public $accountEmail = '';

	/** @var string Message-ID of the mail */
	public $messageId = '';

	/** @var int Row of the mail in the index of inbox when the event was made */
	public $mailId = 0;

	/** @var string Folder of the mail when the event was made */
	public $folder = '';

	/** @var array{from:string,to:string,cc:string,subject:string,date:int,in_reply_to:string} Useful headers */
	public $headers = array('from' => '', 'to' => '', 'cc' => '', 'subject' => '', 'date' => 0, 'in_reply_to' => '');

	/** @var bool Mode test: the actions are only written in the journal of the mailbox */
	public $test = true;
}
