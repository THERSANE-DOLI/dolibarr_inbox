# Refresh of the lists

When a user assigns an email, changes its tags, flags it, moves it, or when new emails arrive, the other users who
have the same mailbox open see it at the next refresh of their list. This page sets how soon.

## Two modes

Home > Setup > Modules > Inbox > Settings, block « Refresh of the lists » (administrators of Dolibarr only):

- **Standard** (default): the lists ask the server every 20 seconds, as before. Nothing more is written on the
  server.
- **Fast**: the changes show within a few seconds. Two intervals:
  - while the user is active: 5 seconds by default, between 3 and 60;
  - after 2 minutes without activity: 60 seconds by default, from the active interval up to 300 (never quicker
    than the active one: it follows when the active one goes above it).

The setting is the same for every entity: it is about the load of the server.

## How the fast mode works

- Each change writes a line in a journal of the changes, kept 24 hours. The line holds **numbers only**: the kind of
  change, the mailbox, the folder, the emails, the user who did it. Never a subject, a text, an address or a name.
- In each browser, one tab only (the others ask it) calls `inbox/live.php` every few seconds. This light call does
  not start Dolibarr: it checks a token signed by the server, valid 15 minutes, that lists the mailboxes the user can
  read, and gives back the lines of these mailboxes only.
- When something changed in a mailbox the user reads, the list reads it again through its usual calls, which check
  the rights. The 20 second refresh goes on as a safety net, every 60 seconds (every 5 minutes after 2 minutes
  without activity).
- After 2 minutes without activity in Inbox the browser asks at the idle interval; after 30 minutes it stops, until
  the next click or key.
- An access given or removed, a mailbox disabled or given to another owner: the users concerned get a new token at
  once. A change of the members of a group of Dolibarr counts at the next token, within 15 minutes.

## Load on the server

Under the setting, a table shows the load for the users reaching a mailbox who logged in during the last 7 days,
counted as if they were all connected at once: all active, all without activity for 2 minutes, and the standard mode
for comparison. It gives the light calls and the full calls per second, and the processor they take. While the
intervals are typed, the table follows.

Measured on a development computer (12 cores):

| Call | Time | |
|---|---|---|
| `live.php` (fast mode) | about 2 ms | about 5,000 calls per second before saturation |
| usual refresh of the list (standard mode) | 20 ms and more | grows with the number of mailboxes and folders |

For 500 users active at once with an interval of 5 seconds: 100 light calls and about 8 full calls (the safety net)
per second, about 0.37 core; the standard mode takes about 0.5 core. Without activity, with 60 seconds: about
0.05 core.

## Check

- `inbox/live.php` must be reachable at the same address as the module. A proxy or a firewall that removes the
  header `X-Inbox-Live` makes the fast mode fall back to the standard refresh (nothing breaks).
- Protected mailboxes have nothing in the index of Dolibarr: they keep their own refresh.
- The journal is emptied by the scheduled job « Inbox: purge of the cache and of the journal » (every night).
