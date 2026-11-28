# Inbox application

`index.php` opens the application (right « Use the webmail »): Settings > Mailboxes (right « Manage the mailboxes »),
General, My account.

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

## Windows snapped to the edges (1.151.0)

The windows of the page (preview of an attachment, source of an email, window of a module, writing) move by their
title bar as before, and now snap to the edges of the screen, as in Windows or Ubuntu:

- dragged against the **left** or **right** edge: the half of the screen, whole height (over the rail);
- dragged against the **top** edge: the whole screen, the rail kept seen (same as the Maximize button and a double click
  on the bar);
- a translucent zone shows where the window goes before the release; brought back to the middle, nothing changes.

A snapped or maximized window stays movable and resizable: dragged by its bar, it takes back its size of before under
the pointer; resized by an edge, it keeps the size given. The Maximize button becomes « Restore the size ». The size
kept for the next window of the same kind is the one of before the snapping. A writing maximized from the row at the
bottom right goes back to it. On a phone and in a window of its own, nothing changes (whole screen).

## Several entities (multicompany)

- The mailboxes belong to one entity: Inbox shows the mailboxes of the entity the user is on in Dolibarr, and every
  call checks the rights in that entity. The same address may be a mailbox in two entities, each with its accesses.
- The entity is the one of the session of Dolibarr. Chosen again in another tab while Inbox is open, the mailboxes
  shown are no longer the ones of the session: Inbox notices it at its next call, reads and writes nothing more, stops
  its quick refresh, and asks to be reloaded (dialog that cannot be closed). After the reload, the mailboxes of the new
  entity are shown; to go back, choose the previous entity in Dolibarr and reload.
- The tabs of two entities never share their coordination (channel, leader tab, what the browser keeps).
- The personal settings of a user (display, sorting...) are common to all the entities.

## Buttons of « Create from this mail » (1.161.0)

The dialog « Create » of the Dolibarr panel shows its buttons in titled groups: Contacts, Sales, Purchases, Follow-up,
Others. Other modules may add their own buttons (another way to create a proposal, for example) and take away buttons,
mailbox by mailbox. Settings > the mailbox > Reception > « Buttons of Create » lists the buttons of the mailbox: shown,
or removed with the name of the module that removed it. The administrator of inbox may keep a removed button in a
mailbox (« Keep this button »), or let the removal apply again; each change is written in the journal of the mailbox.
