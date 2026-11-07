# CHANGELOG INBOX FOR [DOLIBARR ERP CRM](https://www.dolibarr.org)

- FIX: editor of the writing: HTML, colours, tables :   1.16.1
  - HTML pasted as text (an answer prepared by an AI, a « ```html » block) is put in formatted; Ctrl+Z takes it back.
  - « HTML source » button: the text as HTML, to read, change or paste HTML; back to the formatted text.
  - text colour, highlight, alignment, simple tables (borders written in the cells for the mail software).
  - tables: styles in one click (grid, lines, coloured heading, no border), properties of the table (borders, spacing,
    width, alignment, shaded rows) and of the cells (background, alignment, borders), column widths by the mouse; all
    written as inline styles, the only ones the mail software read.
  - toolbar: flat icons, bigger, a small arrow on what opens a menu; colours: last colour in one click, palette, any
    colour, recent ones; bar over the selected text; right click in a table: menu of the table.
  - fields of the writing: the name of the field in the empty line, Cc and Bcc each removable; the window grows from
    its top and left edges; Ctrl+Enter can be turned off (My account); a mailbox without identity sends with its name.
- NEW: write and send mails from inbox :   1.16.0
  - new mail, reply, reply all, reply to the list, forward (in the text or as an attachment), from a floating window
    (several side by side, reduced at the bottom, full screen on a phone); keys C, R, Shift+R, Ctrl+L, Ctrl+Enter.
  - identity and signature of the mailbox, the one that got the mail first; recipients proposed from the contacts,
    thirdparties and users of Dolibarr and the correspondents of the mailboxes; attachments checked by the antivirus.
  - sent by the server of the mailbox; its exact copy put in Sent, the mail answered marked; nothing in the headers
    tells who wrote it in a shared mailbox (the journal of the mailbox does).
  - drafts saved on the mail server while writing (Drafts folder, seen by the other mail software) and reopened.
  - « is writing an answer » shown to the others reading the same mail of a shared mailbox.
  - text editor Tiptap (MIT) shipped in the module, loaded at the first writing; the HTML is cleaned by the server.
  - settings: size of the attachments of a mail, automatic copy of Dolibarr added or not (not by default).
- NEW: imitation of a known sender, junk probably legitimate :   1.15.0
  - a known sender (active customer or supplier, their contacts, users of Dolibarr) whose mail is not authenticated,
    or whose address is written in the display name over another one: red alert above the mail.
  - in the junk, a mail of a known and authenticated sender: orange mark on its line, counter beside the folder,
    banner with « Not junk »; checked by the synchronisation (the headers of these mails only).
  - the authentication (DKIM, SPF, DMARC) is read only in the headers of the receiving servers of the company:
    setting « Trusted authentication servers » (Settings > General); without it nothing is said.
- NEW: create a piece from a mail, files of a mail in the documents of a piece, actions of the other modules :   1.14.0
  - « Create ▾ » of the Dolibarr part: thirdparty, contact, proposal, order, price request, supplier order, event,
    ticket, project; the page of creation of Dolibarr opens in a new tab, filled from the mail (name, address, subject,
    thirdparty recognized); the piece saved there is linked to the mail, and gets the mail (.eml) in its documents when
    the box is ticked (not ticked by default).
  - a file of a mail goes in the documents of a piece: menu of the file, or the file dropped on a piece of the Dolibarr
    part; the antivirus checks it first; the piece is linked to the mail; written in the journal of the mailbox.
  - other modules add their actions with the hook inboxMailActions (an address inside Dolibarr).
  - the Dolibarr part: on the right of the body of the mail or above it (Automatic by default, My account); notes as a
    thread of messages with the picture of their author.
- NEW: Dolibarr beside each mail: sender known, pieces linked, internal notes, tab « Mails » of the cards :   1.13.0
  - bandeau under the head of the mail read (of the conversation): thirdparty and contact recognized by the address,
    else by its domain (never a public mail service), Dolibarr user; pieces linked as pills (link « + Link » with a
    search, the pieces of the thirdparty first; unlink with undo); internal notes (add, change, remove: their author
    or a manager of the mailbox); folded or not, kept per user.
  - a link or a note follows the mail in every move, and is seen from every mailbox where the mail is, by the people
    who can read it; a piece that one cannot read in Dolibarr shows « not accessible »; a piece removed loses its links.
  - tab « Mails » on the cards of thirdparties, contacts, proposals, orders, invoices, supplier orders and invoices,
    supplier proposals, projects, tickets, interventions, shipments: the mails linked and, for a thirdparty or a
    contact, the mails from and to its addresses, only in the mailboxes the user can read; « Open in inbox ».
  - mails without Message-ID: a print of the mail is their key (assigned, linked, noted as the others).
  - other modules: public classes InboxMailRef, InboxLink, InboxNote (origin, no double), triggers INBOX_LINK_CREATE,
    INBOX_LINK_DELETE, INBOX_NOTE_CREATE, _MODIFY, _DELETE, hooks inboxMailLinks and inboxMailAlerts.
- NEW: import and export of mails :   1.12.0
  - files dropped on a folder (or « Import mails… » of its menu): .eml files and mbox files (the local folders of
    most mail software), read by slices in the browser and sent by batches that fit the server; progress, « Stop »,
    a report. Read, answered, starred and tags written by the mail software kept; the mails it had removed, the mails
    already in the folder and the ones where the antivirus finds a virus are not imported. Level « manage ».
  - « Export the folder… »: one mbox file or a zip of .eml, read from the mail server; said when inbox does not know
    all the mails of the folder (limit of the synchronisation). Both written in the journal of the actions.
- NEW: unsubscribe from newsletters, threads ignored or watched :   1.11.0
  - a newsletter shows « Unsubscribe »: the one-click request (RFC 8058) is sent by the server of Dolibarr after a
    confirmation, never the link opened in the browser; refused when the header is not signed (DKIM) or aims at a
    local or private address; by mail when the writing of the mails comes. Level « manage », journaled.
  - K ignores a thread for the whole mailbox: its mails are read now and the answers that come later arrive read;
    W watches a thread for oneself: its lines are highlighted with an eye. Also in the menus of the mails.
- NEW: folders managed from inbox :   1.10.0
  - right click (or « ⋯ ») on a folder: new subfolder, rename, move to, remove, mark the folder read; on the trash
    and the junk, empty; on a mailbox, a new folder at the top. Written on the mail server and checked, then in the
    index: a folder renamed or moved keeps its mails, its colour and its icon.
  - a folder removed goes to the trash with its subfolders and mails; in the trash, it is removed for good after a
    confirmation that says how many mails; emptying the trash removes its subfolders too.
  - the special folders (inbox, sent, drafts, archives, junk, trash) keep their name and place: the actions are
    greyed with the reason; level « manage » of the mailbox, « mark the folder read » with the level « read ».
  - written in the journal of the actions; triggers INBOX_FOLDER_CREATE, _MOVE, _DELETE, _SEEN, _EMPTY.
- NEW: assignment of the mails and notifications (bell) :   1.9.0
  - Space (or the menus, the bulk bar, « Assigned to » in the reading) assigns me or removes me; « Assign a
    person… » picks among the people who can read the mailbox; several people per mail. Initials on the cards and in
    the column « Assigned » of the table, mine in the accent colour.
  - an assignment follows the mail in every move, even to another mailbox, and shows on each copy for the people who
    can read it; nothing is written on the mail server; written in the journal of the actions; undo 8 s.
  - quick filter « My mails »; out of a folder, the archived mails, the trash and the junk only if asked.
  - bell in the rail: « Marie Martin assigned a mail to you », the mail opened where it is now; Shift+N; kept 90 days.
  - a person who loses the access to a mailbox loses the assignments on its mails; a user deleted, all of them.
  - triggers INBOX_MAIL_ASSIGN, INBOX_MAIL_UNASSIGN, INBOX_MAILREF_CREATE; InboxNotification::add() for the other
    modules.
- NEW: colour and icon of the folders and mailboxes :   1.8.0
  - icons of the folders in the colour of their role (inbox, drafts, sent, archives, junk, trash, other folders),
    readable on the light and dark themes; an ordinary folder unfolded is drawn open.
  - right click on a folder or a mailbox > « Colour and icon… »: 12 colours or any other, kept readable on both
    themes, an icon among about 80 for a mailbox or an ordinary folder (a special folder keeps its own), also for the
    subfolders; shared by all the users of the mailbox, changed by the people who manage it.
  - « My account »: icons of the folders in colour or sober (all grey); high contrast mode without colours.
  - a user or a group deleted in Dolibarr: its settings, accesses and images allowed in inbox are removed; the folded
    state of the folders gone is forgotten.
- FIX: attachments not seen in the list for most mails (the ones of Outlook / Exchange above all) :   1.7.1
  - the structure of the mails is decoded by inbox: the sub parts written « NIL)( » were merged, the attachment was
    lost; the mails indexed before are read again once, the most recent first, by the synchronisation.
- NEW: tags readable by the colour blind people :   1.7.0
  - border per tag (none, solid, dashes, dots, double), drawn around the badge in the colour of the text: a mark
    that does not rely on the colour; none by default.
  - warning in the tab Tags when two active tags may be mixed up (protanopia, deuteranopia, tritanopia, or too close
    for everyone), with the pairs named and a mark on their rows; computed while the colour is picked.
  - palettes made for the colour blind people (Okabe-Ito, Tol bright, Tol muted) previewed on the tags, then applied.
  - a user who declared a colour blindness in his card (option of Dolibarr) sees another colour only for the tags
    that collide for his vision, the others keep theirs; « My account » says which ones and can turn it off.
  - dark text on the light colours of tags.
- NEW: actions on the mails, written on the mail server and checked :   1.6.0
  - starred, tags (the same keywords as the other mail clients), archive into Archives / year of the mail (created when
    missing), move, junk / not junk, delete to the trash then for good with a confirmation naming the number.
  - every action is seen at once, written on the server, checked, then in the index; refused = the mail comes back
    with the reason and « Retry »; « Undo » for 8 s puts the server back as it was.
  - rights per mailbox: « read » may mark read / unread and starred, « manage » is needed for the rest, checked by the
    server at each call; an action refused is greyed with its reason.
  - move to another mailbox: copy with its flags, tags and date, the source removed only once the copy is found on
    the target server; a copy refused leaves the source as it was.
  - only the mails aimed are expunged (MOVE, else COPY and UID EXPUNGE): a mail marked deleted by another client stays.
  - bulk bar from 2 mails, whole folder by batches of 200, drag and drop on the folders (folder opened after 600 ms,
    reason shown when the drop is refused), right-click menu, keys S A J Shift+J Del Shift+M 1 to 9 and 0, a phone
    card slid left or right (setting per user), mail selected next or previous after one leaves the list.
  - journal of the actions in the card of the mailbox (who, what, folders, number of mails, undone), filters by person,
    action, period, subject; kept 12 months by default (general settings).
  - tooltips of inbox on the bars of icons and in the context menus (below the actions of the mail read, none on the
    « ⋯ » buttons nor over an open menu), native titles elsewhere.
- NEW: conversations, context menus, pictures of the correspondents, presence :   1.5.0
  - conversation of each mail found by its references, its copies, the answers arrived before it, else the same
    subject and correspondent within 30 days; threads merged when a mail links them; mails already indexed done by
    small batches at each pass.
  - one line per conversation in the lists sorted by date (setting per user), the mails of the conversation (sent
    ones included) stacked in the reading pane, quotes of the previous mails folded.
  - context menus on the mails, folders, attachments and addresses; Shift + right click gives the menu of the
    browser, Menu key and Shift+F10 on the keyboard, long press on a touch screen, setting per user.
  - pictures of the correspondents looked for by the server: photos of Dolibarr, verified logos (BIMI) for the mails
    that passed DMARC, Gravatar off by default, else the initials; a switch per source in the general settings.
  - presence in the shared mailboxes: « Marie reads this mail » in the head of the mail.
  - new mails apart from unread ones: the dot shows the mails arrived since the user last left the folder (per user, in
    the list and in the folders, by order of arrival on the server), bold and the bar show the unread ones.
  - Shift + click in the list selects a range of mails, never the text of the rows.
  - conversation unfolded in the list (« N others » on the cards, triangle in the table, keys right and left), its
    mails opened alone.
  - mails sent by the mailbox in the conversations: « Me → recipient », tinted, on the right of the reading pane,
    never shown unread nor new (even without \Seen on the server); the conversation in the order the mails were written.
  - size of the antivirus taken from the general settings (it was read under another name).
- NEW: reading of the mails in the application (space Mail) :   1.4.0
  - mailboxes and folders from the index, unread counters, mailbox in error with the reason and « Try again ».
  - list of a folder as a table or as cards, sort, quick filters (unread, starred, attachment), pages loaded while
    scrolling; the open folder is synchronised every 20 s and the list follows without losing the selection.
  - search on the index: words, phrases, from: to: subject: before: after: tag: has:attachment (also translated),
    in the folder, the whole mailbox or every mailbox.
  - reading isolated: HTML cleaned, frame without any script, remote pictures blocked (trackers counted, always for
    a sender or a domain), misleading links and names imitating the company signalled, all the headers, source,
    print, report of non delivery, signed or encrypted mail.
  - attachments bar, preview windows (PDF, pictures, attached mails, text, CSV, zip listed) that move, minimise and
    stack; antivirus first: infected file blocked, file not scanned shown only after a warning.
  - read / unread on the server by the reading setting of the user and the mailbox, key M; keyboard, size of the
    text, density, phone (one screen at a time).
  - texts of the index in composed form (accents sent decomposed found by the search).
  - columns of the table chosen (menu, picker at the end of the header, right click on the header), sized and
    ordered by each user, column of the tags; the subject always keeps its room.
  - space of the mailbox (quota) in the tab Synchronisation, warning in the folders from 90 %.
  - mails with emojis (characters of 4 bytes) indexed on a database connected in utf8 (they were left out of the
    index without any error); an error of the database is shown on the folder.
  - synchronisation by the browser when the scheduled job does not run (no run for 3 min): one tab only does it
    for every tab of the browser, in calls of 10 s at most that never block the session; every automatic call
    stops after 30 min without activity, so that the session of Dolibarr can expire as on its other pages.
- NEW: synchronisation of the index of the mailboxes :   1.3.0
  - index in the database of the folders and of the headers of the mails (sender, recipients, subject, dates,
    size, attachments, flags, tags), never their body; the mail server stays the reference, read only.
  - scheduled job every minute: inbox and sent folders at each run, the others every 15 minutes, then the first
    indexing of the whole mailbox in the background, the recent mails first, within a time budget.
  - changes made elsewhere followed: new mails, flags and tags (CONDSTORE), mails removed or moved, folders
    created or removed; folder renumbered by the server indexed again.
  - mailbox in error (login refused, server unreachable): state with the reason, next tries more and more
    spaced, the other mailboxes go on.
  - thirdparty and contact recognised by the exact address of the sender, updated when a contact or a thirdparty
    changes.
  - tab Synchronisation of a mailbox: state, progress, folders followed, « Synchronise now », « Rebuild the
    index »; the caps of the indexing are optional (0 = everything by default).
  - switch in the head of the mailbox card to enable or disable it, updated in place.
  - translations of the application asked again after an update of the module (cache of the browser).
- NEW: mailboxes of inbox, inbox application (settings) :   1.2.0
  - mailboxes managed in inbox (IMAP connector, SMTP, Sieve): personal or shared, passwords encrypted and never
    sent to the browser, connection test step by step (read only), folder roles, enabled after a successful test.
  - accesses per mailbox for users and groups (read, send, manage), no implicit access for the admins of Dolibarr,
    journal of the changes; identities with their signature (copy from the sender profiles of Dolibarr); tags of
    Thunderbird (global list or own list of a mailbox); settings of each user.
  - inbox application in full page (index.php): own styles and icons (Tabler), JavaScript context of Dolibarr
    (backportdolcontext on Dolibarr 23), session expired handled without losing what was typed, installable.
  - reading by Message-ID (lot 0) on the mailboxes of inbox; other modules plug in through the hooks
    inboxResolveAccount, inboxAccountImportSources and inboxConnectors (inbox no longer reads another module).
  - setup page: master user of the mail server, timeouts, journal of the readings; tests PHPUnit of the module.
- NEW: cache emptied from the setup page :   1.1.0
  - number and size of the mails in the cache shown, « Empty the cache » button after a confirmation (the journal of
    the readings is kept).
- FIX: cache directory emptied by hand, README in it :   1.0.2
  - documents/inbox/raw can be emptied at any time (a missing mail is read again on the mail server): a README.txt
    in it says so, written at the activation and by the nightly job.
  - nightly job: lines of the cache without file and .eml files unknown to the module (older than a day) removed.
- FIX: tests of the setup page :   1.0.1
  - Message-ID pasted with its angle brackets was emptied by the HTML filter (« invalid Message-ID »).
  - line breaks of the text of the mail shown as « \n » and « <br> ».
- NEW: first version, mail foundation (Dolibarr 23+) :   1.0.0
  - one mail read in a mailbox declared in CRM Client Connector from its complete Message-ID, read only (BODY.PEEK,
    no flag, no move): inbox, sent, other folders, junk and trash last; exact Message-ID check after the IMAP search.
  - knowing the Message-ID is the access: right « read a mail from its Message-ID », no listing nor search by
    sender, subject or date, every reading written in a journal (user, mailbox, result, folder, cache).
  - raw mails cached on the server (30 days by default), scheduled purge of the cache and of the journal.
  - .eml files parsed: text and HTML bodies, attachments typed
    by their content, pictures of the body marked inline, attached mails (.eml and .msg) read with their attachments.
  - Outlook .msg files parsed by an own reader of compound files (no dependency): subject, sender (SMTP address),
    recipients, dates, Message-ID, bodies, attachments, attached mails; internet headers kept by Outlook used as a
    fallback. Body only stored as compressed RTF not read yet.
  - setup page: retention of the cache and of the journal, test of reading a Message-ID, test of parsing a file.
