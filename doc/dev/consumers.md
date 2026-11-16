# Subscribe a module to the mailboxes

A module that wants to react to the mails of a mailbox (create a ticket at the arrival of a request, sort, link to a
record) declares itself to Inbox. It never reads the mail server itself and never calls the internal classes of
Inbox for that: the managers of each mailbox decide what it receives and what it may do, and everything it does is
written in the journal of the mailbox. How it looks for them: [Modules subscribed to the mailboxes](../admin/module-subscriptions.md).

There is no trigger `INBOX_MAIL_RECEIVED`, on purpose: a trigger of Dolibarr reaches every module enabled, with no
way to limit it to the mailboxes where a module was allowed.

State: since 1.112.0 a module declares itself and is allowed; the mails are not given to it yet (next version).
What follows already holds for the delivery to come.

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
	public function handle(InboxConsumerEvent $event)
	{
		if (stripos($event->headers['subject'], 'request') === false) {
			return 0;	// not for this module
		}
		// ... create the ticket
		return 1;	// handled; -1: error, the event is given again later
	}
}
```

`InboxConsumerEvent`: `event`, `consumer` (the code), `accountId`, `accountEmail`, `messageId` (the same when the mail
is moved), `mailId`, `folder`, `headers` (`from`, `to`, `cc`, `subject`, `date`, `in_reply_to`), `test` (mode test:
the actions are only written in the journal). Reading the body and acting on the mail go through the event, within
what the managers ticked (methods given with the delivery).

The records your module creates in Dolibarr (tickets, thirdparties) are created with the user your module chooses,
usually one set in its own setup: Inbox gives no user. In the journal of the mailbox, the actions of the module show
under its name.

## What a module gets in return

- on each mailbox, a manager who knows it is there and what it does;
- the test mode to set it up without breaking anything;
- the pause, and the removal, without touching the module;
- a journal that says why a mail moved.

Reading the mailboxes directly (classes of Inbox, IMAP) still works and is not blocked: a module is PHP with every
right. The subscription is the supported way, the one the managers see and control.
