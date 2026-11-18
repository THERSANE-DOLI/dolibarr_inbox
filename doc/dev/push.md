# Push drivers (instant refresh)

The instant refresh of the lists (1.124.0) delivers the events of the journal of inbox through a push service chosen
by the administrator. Inbox ships the driver for Mercure; a module adds another one without touching inbox.

## What stays common

- The **journal** (`llx_inbox_event`, class `InboxEvent`) is the reference: an event is written there first, the
  driver only delivers it sooner. The light call `live.php` catches up whatever the driver missed.
- The **leader tab**, the relay between the tabs, the echo of the own actions, the catch up and the fallback to the
  light call are in `js/inbox-live.js` and do not know which driver runs.
- The **format of an event**: `{id, type, account, folder, ids, author, origin, mine}`, numbers only
  (`InboxEvent::format()`).
- The **topics**: `account/<id>` (every user who can read the mailbox) and `user/<id>` (one user). The driver turns
  them into its own names.

## Rules a driver must keep

1. An event holds numbers only: never send a subject, a text, an address or a name, even if the service is yours.
2. **Filter on the server**: a user must receive the topics given to `subscriber()` only, and the service itself
   must refuse the others (signed token, private channels...). A driver that sends everything and lets the browser
   sort is not accepted.
3. No secret of publication ever goes to the browser. Secrets of the settings are fields of type `secret`: stored
   with `dolEncrypt()`, never shown again.
4. `publish()` never blocks long (a few seconds at most): it runs at the end of the request of the user.

## PHP side

Declare the driver with the hook `inboxPushDrivers` (context `inbox`):

```php
public function inboxPushDrivers($parameters, &$object, &$action, $hookmanager)
{
	$this->results = array(
		'mypush' => array('class' => 'MyPushDriver', 'file' => '/mymodule/class/mypushdriver.class.php', 'label' => 'MyPushDriverName',
			// Optional: its documentation (https), linked beside the choice of the service in Settings > Server
			'doc' => 'https://example.com/mymodule/push'),
	);
	return 0;
}
```

The class implements `InboxPushDriverInterface` (`class/push/inboxpushdriverinterface.class.php`):

| Method | Role |
|---|---|
| `code()`, `label()` | identifier, translation key of the name |
| `fields()` | settings shown in the setup of inbox (`url`, `string`, `secret`), stored as `INBOX_PUSH_<CODE>_<KEY>` |
| `configure($values, $namespace)` | values (secrets decrypted) and the mark of the instance (several Dolibarr on one service) |
| `isConfigured()` | the settings needed are there |
| `publish($messages)` | deliver the events of the request (`topic`, `id`, `data`) |
| `subscriber($topics, $userId, $expires)` | what the browser needs for these topics only, until `$expires` |
| `jsFile()` | the adapter of the browser, path for `dol_buildpath()` |
| `test()` | publish and receive an event of test, for the button of the setup |
| `error()` | last error, without any secret |

Example: `class/push/inboxpushmercure.class.php`. In Settings > Server the service is always a choice (a list,
even with one driver); choosing another one shows its fields, each driver keeps its own settings.

## Browser side

`jsFile()` is loaded by `index.php` when the driver is the one of the settings. It registers an adapter:

```js
Dolibarr.tools.inbox.push.register('mypush', {
	connect(config, on) {
		// config: what subscriber() returned
		// on.open() connected; on.event(event) one event; on.error(fatal) cut (fatal: refused, a new token is asked)
		return { close() {} };
	},
});
```

A new token (every 15 minutes, or when the mailboxes of the user change) closes the connection and calls `connect()`
again with the new `config`.

## Tests

`test/phpunit/InboxPushTest.php` checks the common part with a fake driver (nothing outside the driver depends on
Mercure) and the tokens of Mercure.
