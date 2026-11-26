# Subscribe a module to the mailboxes

A module that wants to react to the mails of a mailbox (create a ticket at the arrival of a request, sort, link to a
record) declares itself to Inbox. It never reads the mail server itself and never calls the internal classes of
Inbox for that: the managers of each mailbox decide what it receives and what it may do, and everything it does is
written in the journal of the mailbox. How it looks for them: [Modules subscribed to the mailboxes](../admin/module-subscriptions.md).

There is no trigger `INBOX_MAIL_RECEIVED`, on purpose: a trigger of Dolibarr reaches every module enabled, with no
way to limit it to the mailboxes where a module was allowed.

State: since 1.112.0 a module declares itself and is allowed; since 1.146.0 the mails are given to it. The guards
of the next versions (automatic pause after errors, limits per hour, sending by the module) are not there yet.

## Declare the module

1. The context `inbox` in the hooks of the descriptor:

```php
$this->module_parts = array(
	'hooks' => array('data' => array('inbox'), 'entity' => '0'),
);
```

2. The hook `inboxConsumers` in the class of the actions of the module. The declaration goes in `$this->results`,
**keyed by its code**: the hook manager merges the results of the modules, a common key would be overwritten by the
next module.

```php
public function inboxConsumers($parameters, &$object, &$action, $hookmanager)
{
	$this->results['mymodule_tickets'] = array(
		'module' => 'mymodule',                         // rights class of the module
		'langs' => 'mymodule@mymodule',                 // loaded before translating label and purpose
		'label' => 'MyModuleInboxConsumer',             // translation key: the name shown to the managers
		'purpose' => 'MyModuleInboxConsumerPurpose',    // translation key: one sentence, what it does with the mails
		'class' => 'MyModuleInboxConsumer',             // implements InboxConsumerInterface
		'file' => '/mymodule/class/mymoduleinboxconsumer.class.php', // for dol_include_once
		'events' => array('mail.received'),
		'actions' => array('read', 'link'),
	);
	return 0;
}
```

| Key | Rule |
|---|---|
| code (the key) | `[a-z][a-z0-9_]{1,63}`, prefixed by the name of the module; it identifies the authorizations, keep it stable |
| `module`, `class`, `file` | mandatory; a declaration with a wrong one is left out (a warning in the log of Dolibarr) |
| `events` | at least one known event, else the declaration is left out |
| `actions` | the unknown ones are dropped |

Events: `mail.received` (a new mail in the mailbox, never the mails already there when the module was allowed).

Actions a module may ask:

| Action | What it allows |
|---|---|
| `read` | the text and the attachments of the mail (sender, recipients and subject are always given) |
| `seen` | mark as read or unread |
| `tag` | add or remove a tag |
| `move` | move, archive, put in the junk or the trash |
| `link` | link the mail to a record of Dolibarr |
| `draft` | prepare a reply in the drafts, sent by a person |

Never for a module: delete for good, empty the trash, folders, accesses, rules, identities. Sending a mail by the
module itself (`send`) comes later, with the guards against loops between automatic answers.

Ask only what the module needs: the managers see the list before they allow it. A new version that asks more does
not get it by itself: the managers see a « New request » and tick it, or not.

## The class

```php
dol_include_once('/inbox/class/inboxconsumerinterface.class.php');

class MyModuleInboxConsumer implements InboxConsumerInterface
{
	public function __construct($db)
	{
		$this->db = $db;
	}

	public function handle(InboxConsumerEvent $event)
	{
		if (stripos($event->headers['subject'], 'request') === false) {
			return 0;	// not for this module
		}
		$body = $event->body();	// null when « read » is not ticked: $event->error says why
		// ... create the ticket, with the user chosen in the setup of your module
		$event->link('ticket', $ticketId);
		$event->act('archive');
		return 1;	// handled; -1 (or an exception): error, the event is given again later
	}
}
```

The class is built once per delivery with `new MyModuleInboxConsumer($db)`.

### The event

`InboxConsumerEvent`: `event`, `consumer` (the code), `accountId`, `accountEmail`, `messageId` (the same when the mail
is moved), `key` (see below), `mailId`, `folder`, `headers` (`from`, `to`, `cc`, `subject`, `date`, `in_reply_to`),
`test` (mode test: the actions are only written in the journal).

### Handle a mail once: `$event->key`

`key` identifies the mail whatever its place: its Message-ID, or for a mail without one (some automats) `fp:` and a
print of its date, sender, subject and size. It is the same in every copy of the mail and after a move.

Inbox gives a mail once per subscription, but it keeps that memory 30 days: a mail moved later by another mail
program into a folder of the subscription is given again. Keep the key with what your module made, and leave a mail
already handled:

```php
if ($this->ticketOfMail($event->key)) {
	return 0;	// already handled
}
```

What the module may do goes through the event, within what the managers ticked. A method refused returns `null` or
`false` and says why in `$event->error`:

| Method | Needs | Gives / does |
|---|---|---|
| `can($action)` | | the managers ticked this action |
| `body()` | `read` | `array('text' => ..., 'html' => ...)`, the HTML cleaned by `InboxSafe::html()` |
| `attachments()` | `read` | list of `array('name', 'mime', 'size', 'content')`, the pictures inside the text left out |
| `folders()` | | the folders of the mailbox by row: `path`, `name`, `role` (to choose a target) |
| `act('seen', array('on' => false))` | `seen` | read (default) or unread |
| `act('tag', array('add' => array(...), 'remove' => array(...)))` | `tag` | keywords of the mail |
| `act('move', array('folder' => $rowid))` | `move` | to a folder of this mailbox only |
| `act('archive')`, `act('junk')`, `act('trash')`, `act('inbox')` | `move` | the folders of these roles; never a deletion for good |
| `link($type, $id)` | `link` | link to a record of Dolibarr (`societe`, `ticket`, `commande`...) |
| `draft($html, $subject = '')` | `draft` | a reply to the sender in the Drafts folder, never sent: a person reads it and sends it |

The reading of the content is written once per event in the journal, every action too, in the name of the module.
In test mode, `act()`, `link()` and `draft()` do nothing and return `true`: the journal shows what the module would
have done (« test, not done »).

The values of the mail are data from outside: what your module writes in Dolibarr goes through `InboxSafe`
([Data of a mail: one door](mail-data.md)).

### When the module is called

- At the end of the synchronisation pass that found the mail (every minute, or at once when someone has the mailbox
  open), after the blocked senders and the spams went to the junk: a mail in the junk or the trash is never given.
- A mail still to be judged by the anti-spam engine of Inbox waits for its judgement.
- Once per mail and subscription: the same mail seen in another folder is not given twice.
- `handle()` returns 1 (handled) or 0 (not for this module): done. -1, an exception or a PHP error: given again
  after 1 min, 5 min, 15 min, 1 h, then given up (« failed », counted in the section Modules of the mailbox). The
  synchronisation is never stopped by a module, and what it prints is dropped.
- What the pass had no time for is given at the next pass, or by the scheduled job « Inbox: mails given to the
  modules subscribed » (every 5 minutes), which also gives again the events in error.
- Paused: the events wait and are given at the resume. Authorization removed: its events are dropped. Module turned
  off: its events wait for it. Switch of the instance off: no event, the queue is emptied.
- Mailbox protected with its password: given only while someone has it unlocked (the scheduled job cannot read it).

## What a module gets in return

- on each mailbox, a manager who knows it is there and what it does;
- the test mode to set it up without breaking anything;
- the pause, and the removal, without touching the module;
- a journal that says why a mail moved.

Reading the mailboxes directly (classes of Inbox, IMAP) still works and is not blocked: a module is PHP with every
right. The subscription is the supported way, the one the managers see and control.
