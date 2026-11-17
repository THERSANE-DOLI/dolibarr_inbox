# Emails removed by another software

Inbox keeps on the mail server only an index of the emails (their headers). When another software (Thunderbird, a
phone, another webmail) removes or moves an email, the next synchronisation takes it out of the list:

- an email removed for good, or moved to a folder that is not followed, leaves the index;
- an email only marked as deleted (it waits for the next « compact » or « expunge » of the folder, the other software
  hides it already) leaves the index too;
- every followed folder is compared mail by mail with the server at least once an hour.

## The links stay

The links to the records of Dolibarr, the internal notes and the assignments are not tied to the index but to the
email itself, found by its Message-ID. When the last copy of a linked email leaves the server, its record shows it
greyed in the tab « Emails », with the date it left the server.

If the email comes back with the same Message-ID (moved back into a followed folder, restored from a backup), its
links, notes and assignments come back with it at the next synchronisation, nothing to do.

An email is counted as removed only when inbox is sure it left the server. These cases never start the delay, as
the email is still on the server: index rebuilt, folder no longer followed, folder numbered again by the server.

## Forgotten after a delay

The scheduled job « Inbox: purge of the cache and of the journal » (every night) forgets the emails removed for long, with their links, notes,
assignments and notifications:

- linked, with a note or an assignment: after the delay of Settings > Server > Keeping, « Forget the links of the
  emails removed from the server after » (730 days by default, 0: kept for ever);
- without any of them: after 90 days, hidden constant `INBOX_GONE_BARE_DAYS` (days, 1 at least).

Each email is looked for again in the index before it is forgotten. The modules that follow the links receive
`INBOX_LINK_DELETE` for each link forgotten.
