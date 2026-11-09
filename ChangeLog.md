# CHANGELOG INBOX FOR [DOLIBARR ERP CRM](https://www.dolibarr.org)

- NEW: folders put in order and moved by drag and drop in the panel :   1.43.0
  - on the edge of a brother: put before or after it, in my own order (nothing changes on the server); in the middle
    of a folder or on the mailbox: moved into it on the server (its managers only), undone in the toast.
  - special folders stay first in their place; never into one of its subfolders nor into another mailbox; the
    reason told while dragging. Move up and Move down in the menu of a folder (keyboard, phone).
  - menu « ... » of the panel: folders back in alphabetical order.

- NEW: progress of an action on many mails :   1.42.0
  - from 20 mails (move, archive, delete, junk, tag): a card at the bottom with the count and a bar, by about ten
    batches (20 to 200 mails each).
  - Reduce to a pill (the work goes on, even in another folder); Stop after the batch running, the mails not reached
    back in the list, the part done undone in the toast; the page asks before being closed meanwhile.

- NEW: trash and junk emptied automatically after some days, per mailbox :   1.41.0
  - tab Folders of a mailbox: never (default), 7, 30, 60 or 90 days, for the trash and for the junk; turned on or
    shortened only after a confirmation (removal for good, for every user of the mailbox).
  - each night the mails there for longer are removed from the server, by batches, checked, written in the journal;
    the age counts from their arrival in the folder (new column of the index), never from their reception; nothing
    goes before the delay has passed since it was set; the mails followed (star) stay.
  - the list of the trash says it (« removed for good after 30 days »).
  - the column comes with the update SQL: until the module is enabled again, the indexing goes on as before.

- FIX: the folder marked by the server keeps its role over a folder only found by an older test :   1.40.1
  - the roles chosen by hand in the tab Folders are kept apart from those found by the test of the mailbox; only
    the first ones come before the mark of the server; the form keeps as chosen only what is changed.
  - a folder the server marks for a role (often created again by it) is never offered to the merge: made the main
    folder instead.

- NEW: size of the folders in the panel, an option of its menu :   1.40.0
  - menu « ... » of the panel of the folders: Show the size of the folders, kept for the user, off by default.
  - the size of the mails indexed of each folder (« ≈ » when not all are indexed), the space used by the mailbox on
    the server (quota); summed by the database, no call to the mail server; not on a phone.

- FIX: the panel of the folders follows the order of my mailboxes chosen in My account :   1.39.1
  - without an order chosen: my personal mailbox first, then by name, as before.

- NEW: folders with subfolders closed in the panel until opened :   1.39.0
  - those opened are kept; the mailbox and the parents of the folder opened unfold by themselves.
  - a closed folder tells the unread of its subfolders apart from its own (« +12 »): nothing hidden.
  - the filter of the folders opens what matches; a mail dragged over a closed folder opens it for a moment.

- NEW: one folder per role, duplicates of a special folder handled in the tab Folders of a mailbox :   1.38.0
  - several folders claiming a role (Spam marked by the server and Junk created by a mail software, Trash and
    Corbeille): the one chosen by the administrator keeps it, else the one marked by the server, else the usual
    name; the others are ordinary folders, with their mails.
  - each duplicate shown with its number of mails: keep as an ordinary folder, make it the main folder, or merge
    into the main folder (mails moved on the server, checked, then the empty folder removed; confirmed, journal).
  - a reminder to set the mail software too, or the folder comes back.

- NEW: My account, order of my mailboxes :   1.37.0
  - each one moved by its handle or by the arrows (keyboard, phone), kept at once; back to the order by name in one
    click.
  - the panel of the folders, the choice of a mailbox and the sender of a writing follow it; a mailbox given later
    goes at the end. The list of Settings > Mailboxes stays by name.

- NEW: Settings > Mailboxes, several mailboxes turned on or off from the list :   1.36.0
  - a box per line and « select all »; a bar of the selection: Turn on (counted), Turn off (confirmed), unselect.
  - after an import, the drafts tested well picked in one click; those not tested or failed are told, never sent.
  - one mailbox after the other with the checks of its card; those that could not change stay selected, with why.

- FIX: text of a field selectable again in the rows moved by drag and drop (global tags, templates) :   1.35.2
  - a row is dragged by its handle only.

- FIX: security audit of 2026-11-09 :   1.35.1
  - a delivery report forged by anyone could run a script in the agenda of Dolibarr: the note of the event « not
    delivered » is written as text, the older ones cleaned at the update; a report counts only in the mailbox the
    mail was sent from; its sentence is shown as the answer of the remote server, not checked.
  - proposal of the AI: no picture nor style able to load something from elsewhere, an unclosed tag included;
    links of a writing: full web addresses, mails and phone numbers only.
  - test of a mailbox, of Meilisearch or of the transcription server, import by QR codes: a server of the private
    network not used yet is chosen by the administrator of Dolibarr; the answer of the IMAP server to the test is
    said to who may see it; Meilisearch turned on with the same checks.
  - an undo goes outside the journal only when it gives back what was done; the pictures of a sender allowed load
    alone only for a mail proven by DMARC or by a DKIM signature of its domain.
  - voice typing by the server: 300 pieces per user and per hour at most (INBOX_WHISPER_MAX_PER_HOUR); its consent
    and its count never changed from My account.
  - shared views: the number of unread is the choice of each reader (off until asked, 20 views counted at most);
    groups of the entity only; removed with their author; the third party named with the rights of the reader; a
    reader allowed to change a view keeps the mailboxes of the author he does not read; logins never searched.
  - details of an error of the AI service for the administrators only; notifications of a mailbox not read any more
    without their addresses.

- NEW: voice typing by a transcription server (Whisper), besides the speech recognition of the browser :   1.35.0
  - Settings > General > Voice typing: the engine, the browser or a transcription server (OpenAI compatible address:
    a Whisper installed on the network, or an online service), its key (never sent back) and model; a test lists
    its models; a guide installs speaches with Docker (processor or NVIDIA card).
  - In the writing the voice is recorded and cut at each pause; each sentence goes through Dolibarr to the server and
    comes back as text, in the order said. Works in every browser, Firefox included.
  - The voice being a biometric data, the server is named before the first dictation and each user agrees, even
    for a server of the private network (then told it stays inside); the consent is for its host (another address
    asks it again), written in the journal, withdrawn in My account.

- NEW: my virtual views put in order by dragging them in the panel :   1.34.0
  - A line shows where the view goes; the order is kept. The views shared with me stay after mine.
  - On a phone and with the keyboard: « Move up » and « Move down » in the menu of the view.

- NEW: Settings > General > Search, a guide to install Meilisearch :   1.33.0
  - « How to install Meilisearch? » opens examples to adapt, after a warning: Docker Compose (advised), a quick try
    with docker run, without Docker (program and systemd service), on another machine; then what to fill in the page.
  - Each command copied by a button; the master key kept in a file only root can read, listening on 127.0.0.1.
  - Every dialog: its title and its buttons stay in view, only its content scrolls; a cross at the top right closes
    it (not on the dialogs that must be answered); a dialog only to read has one « Close » on the right.

- NEW: Settings > General and My account, their sections in the menu :   1.32.0
  - Under the page open in the menu, its sections, each with its icon (also before the title of its section).
    General: Look, Defaults, Pictures of the senders, AI, Voice typing, Search, Global tags. My account: Reading,
    My mailbox, Reading per mailbox, Unified views, Voice typing, Display, Actions (those the user has).
  - A click brings the section into view and lights it, the page stays as it is; the address points at it
    (#/settings/general/ai).
  - The section on screen is lit in the menu as the page scrolls. On a phone: a row of chips kept at the top.
  - On a large screen, the title of a long section stays at the top while it is read, pushed by the next one.

- NEW: virtual views, kept searches shared with users and groups :   1.31.0
  - « Save as a view » beside a search: name, icon, colour, every mailbox or some, optional unread counter. The view
    sits in the « Views » section and replays its words, pills, filters, thirdparty, tag and period.
  - Shared with users and groups: each one only sees the mails of the mailboxes he reads. The author chooses whether
    the others only use it or may change it too; sharing and removal stay with the author.
  - Right click on a view: open, edit, copy into my views, hide (shown again from the section menu), remove.
  - A view changed by the search shows « changed » and « Update the view ».
  - A cross in the search field clears the whole search (words, pills, filters), as « Clear the search » and Escape;
    the line under the field stays on one line (the scope is cut, « Save as a view » keeps its icon on a narrow list).

- NEW: one mailbox at a time in the panel of the folders :   1.30.0
  - My account > Mailboxes in the panel: automatic (every mailbox on a large screen, one at a time on a phone), every
    mailbox, or one at a time.
  - one at a time: a selector on top of the folders (icon, name, unread of the mailbox); open, the list of the
    mailboxes in the panel; the mailbox chosen is kept; its menu by a right click; the unified views stay above.
  - a mail dragged over the selector opens the list, over a mailbox of it shows that mailbox: the mail is dropped
    in one of its folders. A folder of another mailbox opened (link, notification) shows its mailbox.
- FIX: foot of a card in a unified view: the tags first (never cut nor on two lines), the name of the mailbox gives way down to its icon, its name in the title :   1.29.1
- NEW: unified views on top of the panel of the folders :   1.29.0
  - section « Views »: Unified inbox, Unread, Starred, Assigned to me; Sent and Drafts of every mailbox offered,
    hidden by default; the views shown chosen in its « ⋯ » menu or by a right click (on a view: open, hide it).
  - each mail of a view says its mailbox and is read, answered, archived or moved in it; the unread of the unified
    inbox and of the unread view from the counters already known; a view follows the mails that arrive.
  - My account > Unified views: every mailbox by default, each one can be left out (it stays in the panel).
  - address of a view (#/mail/view/inbox) kept on reload.
  - in a list of several mailboxes, each mail shows the icon of its mailbox in its colour, then « mailbox / folder ».
  - « Mailboxes in the views… » leads to the section of My account, brought into view and lit for a moment.
- FIX: HTML put in the writing (answer of the AI, template) without the line breaks between its blocks: no more empty paragraphs and empty bullets :   1.28.4
- FIX: voice typing goes where the cursor is (the instruction of the AI, the subject...), the microphone button keeps the cursor there; the bar says where the words go :   1.28.3
- FIX: voice typing: apostrophes no more written « &#39; » (text inserted as text, never as HTML), a space before a sentence that follows a word; voice typing in its own file js/inbox-dictation.js, tested in jsdom (dev/editor-tests) :   1.28.2
- FIX: bar of the selection never wider than the list (count shortened, less used actions in « ⋯ »), actions with their names in the reading pane :   1.28.1
- NEW: voice typing in the writing, with an informed consent :   1.28.0
  - microphone button in the toolbar of the writing (speech recognition of the browser): the text comes at the
    cursor while speaking, a red bar « Dictation in progress · Google » shows it, Stop or Escape ends it.
  - before the first dictation toward a receiver of the voice (Google for Chrome, Microsoft for Edge, Apple for
    Safari), a dialog names it and says what leaves; the consent is per user and per receiver, written in the
    journal, withdrawn in My account. No button in Firefox (no speech recognition) nor Brave.
  - off by default (Settings > General), a mailbox may forbid it (its card, apart from the AI).
- NEW: AI in the writing, through the AI module of Dolibarr :   1.27.0
  - button « AI help » in the toolbar of the writing: write from an instruction (with the text of the mail answered,
    untickable), rephrase (shorter, more formal, warmer, spelling) or translate the selection or the whole text.
  - the answer is a proposal shown in the panel: Insert / Replace / Try again; nothing enters the mail without a click,
    Ctrl+Z takes it back. Cleaned as a mail (no script, no picture from elsewhere).
  - what leaves: the instruction, the text, the mail answered as data (framed, never followed as an order); never the
    attachments, the signature or the Bcc. Dolibarr 24: personal data masked during the call (PrivacyGuard).
  - off by default (Settings > General), the mail answered may be forbidden, a mailbox may be excluded (its card);
    sending level on the mailbox and the right of the AI module (24); 30 requests per hour and per user; each request
    written in the journal of the actions, without its text.
  - tests of the editor in Node with jsdom (dev/editor-tests), the editor gives its Tiptap instance (raw()).
- NEW: gaps of the writing (lot W6) closed :   1.26.0
  - a mail forwarded gets the keyword $Forwarded on the server (read by the other mail software), shown in the list.
  - copy in Sent: automatic, by inbox or by the sending server (some servers file it themselves: no second copy).
  - templates of Dolibarr « for every type » offered in the writing, with or without a linked piece.
  - with several recipients, the one whose name fills the variables of a template is chosen.
  - editor of a template: language (values of the variables written in it) and preview for a recipient.
  - answer at hand at the bottom of the mail read and of a conversation (reply, reply to all, forward).
- FIX: a draft or « edit as new » reopened showed its pasted pictures no more (cid: put back as data:, size kept) :   1.25.3
- FIX: pictures of the writing: corners to resize them (proportions kept), bar on the picture selected (25 to 100 %
  of its size, alignment, alternative text, removal) :   1.25.2
- FIX: number of drafts in grey beside the Drafts folder (count of the server, sending later left out) :   1.25.1
- NEW: delivery reports of the mails sent (DSN) :   1.25.0
  - the standard report (multipart/report, delivery-status: Postfix, Exim, Gmail, Exchange, OVH) read per recipient:
    « Not delivered to x: this address does not exist », mailbox full, domain unknown, refused, too big, temporary;
    the sentence of the remote server in small; « Not delivered yet » (orange) for a delay. Other bounces left as
    ordinary mails (nothing guessed).
  - the synchronisation reads the start of the reports only (known from their Content-Type) and links them to the
    mail sent by its Message-ID (sent by inbox or by another software): red mark in the list, banner on the mail sent
    with the link to the report, and on the report the link to the mail sent.
  - bell « Mail not delivered to x » for the author of a sending made by inbox (new type, Settings > Notifications);
    event « Mail not delivered » added on the pieces where the mail was recorded (inbox or Dolibarr); a report older
    than 7 days (first indexing) is recorded, never told.
  - picture of a sender without one: empty answer (204) instead of a 404 in the console of the browser.
- NEW: import of the mailboxes from the QR codes « Export for mobile » of Thunderbird :   1.24.0
  - Settings > Import, source « Thunderbird (QR code) » after the sources of the modules (the first one stays the
    default), always offered to the administrators of the mailboxes: a capture pasted (Ctrl+V), dropped or chosen,
    or the camera; several codes followed (« code 1 of 3 »).
  - the image is decoded in the browser (BarcodeDetector, else jsQR 1.4.0 embedded) and never sent; the texts of the
    codes are checked by the server (format version 1, every value), kept encrypted in session 15 minutes, the
    passwords never sent back to the browser.
  - choice of the accounts (all checked except the ones already in inbox; POP3, NTLM... skipped with their reason),
    shared or personal (owner proposed: the user with the address, else me; any user searched), « give me the
    Manage access » checked.
  - mailboxes with a password tested then enabled; without password or with OAuth: created inactive, to complete;
    identities created; reminder to delete the capture and button to empty the clipboard.
- FIX: security audit of 2026-11-08 :   1.23.1
  - a received mail could run a script through « Print »: comments and raw text elements removed from the mails, no
    tag in their CSS, printing from a hidden frame with the protections of the reading; links: web addresses only.
  - a stored password, the master password or an OAuth token could be sent to another server from the test of a
    mailbox: a change of server, port, encryption or login asks the password again; master user and OAuth chosen by
    the administrator of Dolibarr, the master password only sent to its server (new setting); same for the key of
    Meilisearch.
  - journal of a mailbox: the level « manage » on it; drafts taken back or removed: the level « send »; notes and
    links: changed by their author (a note still follows the mail in every copy); an identity checked again when a
    mail leaves; the journal cannot be paused by another action.
  - BIMI off by default, only for a mail checked by the receiving server of the setup; IPv4 only for the calls to
    an address given by a mail; answers of the private network not shown to who is not administrator; optional list
    of the servers allowed (INBOX_ALLOWED_HOSTS).
  - forged Outlook .msg bounded; cache of the mails checked against the mail; lone CR normalised before sending;
    ${...} neutralised in the Sieve rules; quote of a writing without remote pictures; mailto: without bcc; calls of
    state and synchronisation in POST with the token; subjects hidden after the loss of an access; templates of a
    mailbox for its users; third parties of the suggestions with the right to read them; tests and dev never served
    by the web server; no company name in the public manifest; a self-given access is told to the administrators.
- NEW: rules and out of office on the mail server (Sieve) :   1.23.0
  - tab Rules of a mailbox (managers): rules run by the server when a mail arrives, even inbox closed; conditions on
    From, To, Cc, Subject, List-Id or a header; move, copy, tag, mark read, star, trash, forward (copy kept).
  - order by drag and drop, rules on or off, duplicate, preview on the mails of the last 30 days, applied once to the
    mails already received if asked; « Create a rule from this mail » in the menu of a mail.
  - script written by ManageSieve and checked by the server; previous version kept (back in one click); a script
    changed elsewhere is shown read only, one active before inbox is kept and run after the rules.
  - tab Out of office: period, subject, message, one answer per sender every N days, addresses; never to lists,
    automatic mails or noreply; a banner in the Mail space for every reader of the mailbox (Turn off, Change).
  - a forward to an outside address is written in the journal and told to the administrators.
- NEW: invitations and read receipts :   1.22.0
  - an invitation (Outlook, Google...) is shown as a card above the mail: when (in your time zone, and the one of the
    organizer), where, organizer, attendees, recurrence, events of your agenda at the same time.
  - Accept, Maybe, Decline: the answer goes to the organizer (unless unchecked, with an optional comment) and the
    event goes in your agenda of Dolibarr (unless unchecked), linked to the mail.
  - an update received offers to move the event of the agenda; a cancel offers to mark it cancelled (never removed).
  - read receipt asked by a mail: « Send the receipt » or « Ignore », never automatic; handled once for good (keyword
    $MDNSent, as Thunderbird); refused when asked to another address than the sender; My account: never send.
- NEW: unread counter and notifications of the browser :   1.21.0
  - unread mails of the inbox folders on the icon of the tab (badge up to 99+), in its title, on the icon of the
    installed application and in the top bar of the other pages of Dolibarr (envelope with the number).
  - Settings > Notifications: mailboxes counted (all, personal only, one left out), counter in Dolibarr, notifications
    of the browser (asked on a click only), new mails told per mailbox (personal ones by default), channels per type.
  - notifications of the browser: new mails (sender and subject only) and the notifications of the bell, by one tab
    only; a click opens the mail.
  - summary by mail of the notifications still unread after 15 minutes, for the types chosen (scheduled job).
  - new notification: a mailbox that keeps failing is told to its managers, once per episode.
- NEW: fast search in the text of the mails with Meilisearch (option) :   1.20.0
  - Settings > General > Search: address of Meilisearch, master key (never sent back), prefix of the indexes, test;
    turned on, inbox creates its own key, limited to the indexes of the prefix; turned off, every index is removed.
  - mailboxes chosen one by one (tab Synchronisation, admin): one index per mailbox, filled by a scheduled job
    (start of each mail only, attachments not downloaded), cleaned once a day; progress shown.
  - the search asks only the indexes of the mailboxes the user may read; typos and accents tolerated, sorted by
    relevance, crop of the text with the words marked; the mails not indexed yet are still found by the index.
  - Meilisearch not answering: the search goes on without it and the panel offers the search on the mail server.
- NEW: search panel and search in the text of the mails :   1.19.0
  - the search field opens into a panel: where to search (folder, mailbox, all mailboxes), suggestions (sender,
    third party, tag, subject), filters (unread, starred, attachment, my mails, period, tag), recent searches.
  - criteria and filters chosen are pills in the field, removable one by one; the old quick filter bar is gone.
  - « Search in the text of the mails » (Ctrl+Enter, or from an empty result): the mail server searches the text of
    each mailbox of the scope, one after the other, with a progress bar and Stop; mails found only by their text are
    marked. Exact search (case ignored, accents kept), at most 20 s per mailbox; nothing of the text is kept.
- NEW: mail templates, documents of the linked pieces, redirect, edit as new :   1.18.0
  - templates in the writing: those of the mailbox (tab Templates of a mailbox, or « Save as a template ») and those
    of Dolibarr for the pieces linked to the mail answered (proposal, order, invoice...), with their variables.
  - variables shown as chips that follow the recipient and the piece chosen (« Variables of »); orange when without
    value, listed before sending; the mail sent holds their text only; also in Setup > Emails > Templates.
  - documents of the linked pieces attached in one click; « Attach the main document » of a template followed.
  - redirect a mail as it is to other recipients; edit a mail as a new one; a picture pasted goes in the text.
  - mailto: links of a mail open a new mail in inbox; My account: open the mailto: links of the browser in inbox.
- NEW: queue of the sending, sending later, checks before sending, record on the piece :   1.17.0
  - a mail leaves after a delay (10 s by default, setup and My account): « Sending in 8 s · Undo · Send now »;
    undo gives the writing back as it was; a closed tab does not stop it (scheduled job every minute).
  - « Send later »: tomorrow 8:00, Monday 8:00 or a date chosen; the mails waiting are listed in the Drafts folder.
  - a mail leaves once (one lock); a failure is told in the bell and the mail is taken back from the list.
  - before sending: attachment announced but missing, more than 10 recipients who see each other (hidden copy offered),
    an answer from another mailbox than the one that got the mail.
  - record on the piece linked to the mail answered (box in the window, or question in the bar of the sending): event
    of the agenda, mail (.eml) in the documents of the piece, mail sent linked to the piece.
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
