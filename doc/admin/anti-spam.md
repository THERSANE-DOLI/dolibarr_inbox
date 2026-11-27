# Blocked senders and anti-spam

Settings > Anti-spam (1.85.0): one list of blocked senders (addresses, or domains with their subdomains) for all the
mailboxes of the entity; their mails go to the junk, never to the trash. Blocked from a mail (menu ⋯ > Block the
sender…): an unknown sender with the level « manage » on the mailbox of the mail; a sender known to Dolibarr or a
domain with the right « Manage the blocked senders » (strong confirmation for a known sender). Never blockable: the
users of Dolibarr, the mailboxes of inbox and their domains, the recognized domains, the senders of the data sources,
the public mail services as domains.

How it is applied, and what changes on the mail server:

- **Sieve**: the scheduled job « Synchronise the mailboxes » writes a block at the top of the script `inbox` of each
  mailbox (before the rules and the out of office), marked `# Blocked senders of inbox`. It is never shown in the
  rules editor and is written again when the rules are saved. A mailbox whose script was changed by another tool,
  or whose active script belongs to another tool, is never written: Settings > Anti-spam says so.
- **Synchronisation**: on a mailbox without Sieve, with a script of another tool, or while the block is not written
  yet, the new mails of the inbox from a blocked sender are moved to the junk by the synchronisation (journal:
  « put in junk (blocked sender) »). A mail marked « not junk » ($NotJunk) is never moved.
- **Turn it off**: Settings > Anti-spam, switch « Blocked senders » (`INBOX_BLOCKED_SENDERS`): the block is removed
  from the scripts at the next pass, the list is kept.

Anti-spam engine of inbox (1.88.0, off by default): Rspamd installed **beside Dolibarr**, never on the mail server,
so that the mails do not leave our servers. inbox calls `/checkv2` (port 11333) for the new mails of the inbox and
the controller (port 11334, its password in Settings > Anti-spam > Settings) for `/learnspam`, `/learnham`, `/stat`.

- Docker (tests and production): `dev/rspamd/docker-compose.yml`, two services: Rspamd (ports on 127.0.0.1) and Redis
  (its learning, on an internal network: reached by Rspamd only, no way out). Start with
  `RSPAMD_PASSWORD='...' dev/rspamd/run.sh` (writes the hashed password of the controller, then `docker compose up
  -d`; restarted with the server); without `RSPAMD_PASSWORD`, a random password shown once. Type the same password
  in Settings > Anti-spam. `dev/rspamd/run.sh stop` stops both. Rspamd still asks the DNS for the SPF, DKIM and
  DMARC records of the senders of the mails it judges (the hour of arrival and the resolver of the server show).
- Without Docker: the Debian package of rspamd with redis-server, and the same `local.d/` and `override.d/` files.
  Publish the ports on 127.0.0.1 only. These files (checked on Rspamd 4.3.0, 2026-11-19; 3.11.1 accepts them too):
  - turn off what asks outside: public fuzzy storage of rspamd.com, update of the rules from updates.rspamd.com,
    lists downloaded from maps.rspamd.com (multimap, whitelist; mid, mime_types and the equivalent domains of
    forged_recipients read the copies of the image), phishing lists, ASN zone, following of short links,
    monitoring, lists of reputation by DNS (rbl);
  - turn off what is made for a mail server and ends the check without a score: greylist, ratelimit;
  - refuse a path of a file to read in place of the mail (`allow_file_and_shm_inputs = false` on every worker).
  `local.d/` merges its lists with the defaults (a remote list stays): `override.d/` replaces them. Check it once
  installed: no « https:// » in `docker logs`, and the connections of the container (`/proc/net/tcp`) go to Redis
  only, DNS apart (SPF, DKIM, DMARC of the senders).
- The lists of reputation by DNS (Spamhaus, SURBL...) need two keys: `enabled = true;` in `local.d/rbl.conf` and the
  switch of Settings > Anti-spam (inbox turns the groups rbl and surbl off at each check otherwise, header
  `Settings`). They refuse the public resolvers (8.8.8.8...):
  give Rspamd a local resolver (unbound) before turning them on; Spamhaus is free only for a low volume and non
  commercial use (else its DQS key in `local.d/rbl.conf`).

Known senders taken out of the junk (1.147.0, off by default): Settings > Anti-spam > Settings, switch « Take the
known senders out of the junk » (`INBOX_JUNK_RESCUE`). At the synchronisation, an email of the junk whose sender is
known to Dolibarr (exact address of an active customer or supplier, of one of their active contacts, of an active
user) and authenticated by a trusted receiving server goes back to the inbox with the keyword `$NotJunk`, as « Not
junk » would do. Journal of the mailbox: « Taken out of the junk (known sender, authenticated) ».

- It uses the check « Point out the emails probably legitimate » (`INBOX_JUNK_LEGIT_CHECK`) and the trusted
  receiving servers (`INBOX_AUTH_SERVERS`): without them, nothing moves. A known sender not authenticated (probable
  imitation) is never moved.
- Only the emails arrived since the switch was turned on (`INBOX_JUNK_RESCUE_SINCE`, written at that moment) and in
  the last 7 days: the past of the junk, or of a mailbox added later, is left as it is (the chip « Probably
  legitimate » and the view « Spams » show it).
- Never a blocked sender, never an email with the keyword `$Junk`: marked as junk by someone, in inbox or in another
  mail program. An email moved by hand into the junk folder without that keyword is taken out again: use « Junk ».
- Not learned by the anti-spam engine of inbox (nobody decided it). The modules subscribed to the mailbox get it as a
  new email of the inbox.
- Protected mailboxes: done by the session that unlocked the mailbox, as its synchronisation; never with the level
  « content ».

Living with another anti-spam tool: a tool that takes the known senders out of the junk does not know the list of
the blocked senders; a known sender blocked here may come back to the inbox through it. Add the address to the
blacklist of that tool as well, or let inbox take the known senders out of the junk and turn that tool's own rescue
off.

The spams of every mailbox in one list: view « Spams », see [Views](views.md).
