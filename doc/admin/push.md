# Instant refresh (push service)

With the refresh « Instant » (Inbox > Settings > Server, section « Refresh of the lists »), the changes made by
the other users show at once: a push service installed beside Dolibarr keeps the connections of the browsers open
and gives them each change as soon as Dolibarr writes it. Inbox ships one driver, for **Mercure**
(https://mercure.rocks, version 1); another module can add other ones.

Without a push service, or when it does not answer, the lists follow at the rhythm of the fast mode: nothing breaks.

## What the service receives

- **Numbers only**: the kind of change, the mailbox, the folder, the emails, the user who did it. Never a subject, a
  text, an address, a name of mailbox or of folder. A copy of the service, or its logs, tell nothing of the emails.
- **Each user receives his own mailboxes only**: Dolibarr gives each browser a token signed with the key of the
  subscribers, valid 15 minutes, that lists the mailboxes the user can read (and himself). The hub refuses to give
  him any other change. A right removed: his token is renewed at once, without the mailbox.
- The keys stay on the server: the browser only gets its own token, in a header (never in an address).
- Every change stays written in the journal of inbox first: the service only delivers it sooner, a browser that
  missed something catches up by the light call.

## Install Mercure

Mercure is free software (AGPL), not shipped with inbox: the administrator of the server installs it. The files of
`dev/mercure/` are examples.

Three settings are the same on both sides:

| Mercure | Inbox (section « Push service ») |
|---|---|
| `MERCURE_PUBLISHER_JWT_KEY` | Key of the publishers |
| `MERCURE_SUBSCRIBER_JWT_KEY` | Key of the subscribers |
| `MERCURE_TRUSTED_ISSUERS` | Trusted issuer (the address of Dolibarr, for example `https://erp.example.com`) |

The keys: random, at least 32 characters, different. Inbox keeps them encrypted and never shows them again.

### With Docker

```sh
DOLIBARR_ORIGIN=https://erp.example.com dev/mercure/run.sh
```

The first start writes `dev/mercure/.env` (keys drawn at random, readable by you only) and shows the values to type
in inbox once. The hub listens on `http://127.0.0.1:3001/.well-known/mercure`, restarted with the server.
`dev/mercure/run.sh stop` stops it.

### With systemd (no Docker, Plesk...)

`dev/mercure/mercure.service` and `dev/mercure/mercure.env.example`: the binary of a release of Mercure in
`/usr/local/bin/mercure`, its Caddyfile in `/etc/mercure/`, the settings in `/etc/mercure/mercure.env` (mode 600), a
system user `mercure`. The steps are at the top of the unit file.

### Reach the hub from the browsers

The browsers need an address in HTTPS. Two ways:

- **nginx in front** (`dev/mercure/nginx.conf.example`): a name of its own (`push.example.com`), the stream without
  buffer. Address for the browsers: `https://push.example.com/.well-known/mercure`; address for the server:
  `http://127.0.0.1:3001/.well-known/mercure`.
- **Mercure alone** on its name, with its automatic HTTPS: `SERVER_NAME=push.example.com` and the ports 80 and 443.

**Never behind the proxy of Apache** that serves Dolibarr: each open stream would hold a thread of Apache, 500
users connected = 500 threads.

`cors_origins` of the hub lists the address of Dolibarr (scheme and host). If Dolibarr sends a
Content-Security-Policy (Setup > Security), add the address of the hub to its `connect-src`.

## Set inbox

1. Inbox > Settings > Server, section « Push service »: the two addresses, the trusted issuer, the two keys; Save.
   The link « Installation guide of Mercure » gives the commands with the address of this Dolibarr already in them.
2. **Test**: inbox subscribes to a topic of test, publishes on it and waits for it. The message says what failed
   (address, key of the subscribers, key of the publishers), or the time it took.
3. Section « Refresh of the lists »: Instant (saved at once). The users get it at their next page.

« Instant » can be chosen only once the test passed **with the present settings**. A setting changed afterwards (an
address, a key): the test no longer holds, the lists follow at the rhythm of the fast mode (the page says so) until
the next test passes. Only a keyed hash of the settings tested is kept, never the values.

When the hub stops answering, inbox writes the last failure in the section « Push service » (at most every 10 minutes)
and the lists go on at the rhythm of the fast mode.

## Load

The hub keeps one connection per browser (the leader tab only), asleep after 30 minutes without activity. PHP works
only to publish, once at the end of each request that changed something. The light call and the full refresh of the
lists keep going once a minute, to catch up.

## Another driver

A module adds a driver with the hook `inboxPushDrivers` (context `inbox`): a class that implements
`InboxPushDriverInterface` (`class/push/`) and a script of the browser that calls
`Dolibarr.tools.inbox.push.register(code, { connect(config, on) })`. See [the page for developers](../dev/push.md).
