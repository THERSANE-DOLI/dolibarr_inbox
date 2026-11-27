# Inbox application

`index.php` opens the application (right « Use the webmail »): Settings > Mailboxes (right « Manage the mailboxes »),
General, My account. The reading of the mails comes with the next lots.

Cache: the raw mails read in the mailboxes are kept in `documents/inbox/raw/` (30 days by default, purged every night).
This directory can be emptied at any time: a missing mail is read again on the mail server when needed. It holds a
README.txt saying so.

## Windows of their own (1.148.0)

Ctrl+click (Cmd on a Mac) or middle click opens a browser window of its own, without tabs nor menu, in a browser tab
as in the installed application. A plain click keeps everything in the page.

- **Attachment**: a PDF or a picture shown by the browser; an office document or any file another module can show
  (« Open with… »), shown by the first such viewer. What Inbox alone shows (text, CSV, zip, attached email) stays in
  its preview. The antivirus first: a file not scanned or infected opens in the preview, which says why. The button
  ⧉ of the preview does the same, then closes the preview. Never a download: only the Download button downloads.
- **Writing**: « New mail », « Reply », « Reply all », « Forward » and the other ways to write from the menu ⋯
  (`index.php?window=compose&mode=reply&mail=<row>`). The window holds the writing alone (no rail, never the leader
  tab: it neither synchronises nor refreshes), closes after the sending once its bar is done (« Undo » brings the
  writing back in it), and asks the browser to confirm when it is closed with text not saved.
- **⧉ of a writing**: the writing in progress moves to a window of its own, with its recipients, its text and its
  attachments (same writing on the server). The page and the window talk through a `BroadcastChannel`: nothing of the
  mail is written in the browser. Without an answer of the window in 8 seconds, the writing stays in the page.
- Size and place of the last window of each kind kept by the browser (localStorage `inbox-window:compose`); centred
  the first time. A window blocked by the browser is said (allow the pop-up windows for the site); the attachment
  then opens in the preview, the writing in the page. On a phone: as a click.

## Several entities (multicompany)

- The mailboxes belong to one entity: Inbox shows the mailboxes of the entity the user is on in Dolibarr, and every
  call checks the rights in that entity. The same address may be a mailbox in two entities, each with its accesses.
- The entity is the one of the session of Dolibarr. Chosen again in another tab while Inbox is open, the mailboxes
  shown are no longer the ones of the session: Inbox notices it at its next call, reads and writes nothing more, stops
  its quick refresh, and asks to be reloaded (dialog that cannot be closed). After the reload, the mailboxes of the new
  entity are shown; to go back, choose the previous entity in Dolibarr and reload.
- The tabs of two entities never share their coordination (channel, leader tab, what the browser keeps).
- The personal settings of a user (display, sorting...) are common to all the entities.
