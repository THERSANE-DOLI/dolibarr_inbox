# Inbox application

`index.php` opens the application (right « Use the webmail »): Settings > Mailboxes (right « Manage the mailboxes »),
General, My account. The reading of the mails comes with the next lots.

Cache: the raw mails read in the mailboxes are kept in `documents/inbox/raw/` (30 days by default, purged every night).
This directory can be emptied at any time: a missing mail is read again on the mail server when needed. It holds a
README.txt saying so.

## Several entities (multicompany)

- The mailboxes belong to one entity: Inbox shows the mailboxes of the entity the user is on in Dolibarr, and every
  call checks the rights in that entity. The same address may be a mailbox in two entities, each with its accesses.
- The entity is the one of the session of Dolibarr. Chosen again in another tab while Inbox is open, the mailboxes
  shown are no longer the ones of the session: Inbox notices it at its next call, reads and writes nothing more, stops
  its quick refresh, and asks to be reloaded (dialog that cannot be closed). After the reload, the mailboxes of the new
  entity are shown; to go back, choose the previous entity in Dolibarr and reload.
- The tabs of two entities never share their coordination (channel, leader tab, what the browser keeps).
- The personal settings of a user (display, sorting...) are common to all the entities.
