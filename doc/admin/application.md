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
  (« Open with… »), shown by the first such viewer. What Inbox alone shows (text, CSV, zip, attached email, and a
  spreadsheet when no module shows it) stays in its preview. The antivirus first: a file not scanned or infected opens in the preview, which says why. The button
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

## Spreadsheets in the preview (1.152.0)

An attached spreadsheet opens in the preview as the other files Inbox shows itself, without any other module nor
office suite on the server:

- **Formats**: `xlsx`, `xlsm`, `xltx`, `xltm` (Excel 2007 and later), `xls`, `xlt` (Excel 97-2003, and Excel 5/95),
  `ods`, `ots` (LibreOffice, OpenDocument). Not shown: `xlsb`, Numbers, a file protected by a password (the preview
  says so and offers the download).
- **What is shown**: the first 1000 rows and 100 columns of each sheet, one tab per sheet (the hidden ones too: a
  file received by mail has nothing to hide from its reader), the letters of the columns and the numbers of the rows.
  A note under the grid says when a sheet is cut: the whole file is one click away (Download).
- **Values kept in the file**: the result of each formula, never computed again; numbers, percentages, amounts and
  dates as the format of the cell asks, written for the language of the user (`1 234,50 €`, `27/11/2026`).
- **The look of the author**: bold, italic, underlined and struck texts, size and colour of the text,
  background of the cells, borders (thin, medium, thick, dashed, dotted, double), alignment, texts on several lines,
  widths of the columns, merged cells; a long text goes over the empty cells that follow it. The cells are a white
  sheet in the light and in the dark theme, as in a spreadsheet. Not shown: the fonts themselves, the heights of the
  rows, the conditional formats, the patterns and gradients (first colour only), the rotated texts, the pictures, the
  charts, the frozen panes. No macro: nothing of the file runs, and nothing of it is written into the page as it is
  (colours, sizes and lines are checked by the server, then again by the page).
- **Same guards as every attachment**: the antivirus first, the rights on the mailbox checked again by the server.
  The file is read in memory, never unpacked on disk, and the reading stops by itself on a file made to exhaust the
  server (bounded number of cells, of text and of time).
- **Another module shows spreadsheets** (an office server declared by the hook `inboxAttachmentViewers`): its
  viewer keeps the first place, in the preview and in a window of its own, as before; the preview of Inbox is used
  only when there is none. « Open with… » still lists every viewer.

Works the same for the files of a writing (checked before the sending).

## Several entities (multicompany)

- The mailboxes belong to one entity: Inbox shows the mailboxes of the entity the user is on in Dolibarr, and every
  call checks the rights in that entity. The same address may be a mailbox in two entities, each with its accesses.
- The entity is the one of the session of Dolibarr. Chosen again in another tab while Inbox is open, the mailboxes
  shown are no longer the ones of the session: Inbox notices it at its next call, reads and writes nothing more, stops
  its quick refresh, and asks to be reloaded (dialog that cannot be closed). After the reload, the mailboxes of the new
  entity are shown; to go back, choose the previous entity in Dolibarr and reload.
- The tabs of two entities never share their coordination (channel, leader tab, what the browser keeps).
- The personal settings of a user (display, sorting...) are common to all the entities.
