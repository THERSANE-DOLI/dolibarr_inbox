# CHANGELOG INBOX FOR [DOLIBARR ERP CRM](https://www.dolibarr.org)

- NEW: fast refresh of the lists, the changes made by the other users show within a few seconds :   1.122.0
  - setting « Refresh of the lists » in the setup of the module: standard (20 s) or fast, an interval while the user
    is active and one after 2 minutes without activity, with the load they bring computed as they are typed.
  - one tab per browser asks, stopped after 30 minutes without activity.

- NEW: journal of the changes, so that the lists open by the other users can follow at once :   1.121.0
  - assignments, tags, flags, moves and new mails written by numbers only, never a subject, a text or an address.
  - light call live.php: a signed token, each user reads only the mailboxes he can read, Dolibarr not started.
  - written only when the quicker refresh is turned on (setting INBOX_REACTIVITY, its page comes next).

- FIX: the box about bank details took the reading pane, the mail was left a few lines to read :   1.120.3
  - « Collapse » leaves only its title line, remembered per mail; it folds itself once the check is written.
  - red box: only the title in red, the advice in the text colour to be readable.

- FIX: a recipient chosen from the proposals also left the text typed, as a red chip and in the field :   1.120.2
  - the field is emptied and the list closed before the chip is added.
  - the automatic save of the draft keeps the address being typed without making a chip of it.

- FIX: the journal of the changes of a mailbox stopped at 100 lines without saying it :   1.120.1
  - tab Access: the last 10 changes, then « More lines » loads the next ones.

- NEW: people and groups chosen from a real list, with their picture :   1.120.0
  - accesses and owner of a mailbox: photo or initials of a user, a group in its colour with its members.
  - who already has an access is greyed with its level; « n more, type to narrow » when the list is cut.

- NEW: order of the list shown and changed at hand, an answer of the fil read without leaving the mail :   1.119.0
  - the order is a chip with its arrow: the arrow turns it, its words (or a right click) choose another order.
  - orders chosen as a key (date, correspondent, subject, size) then a direction in its own words.
  - « See the email » under an answer opens it in a dialog, « Go to the email » to open it for good.

- FIX: the answers of the fil said who they went to only once one was unfolded :   1.118.2
  - « Replied to … » always written, with the first words of the answer; section « Notes and answers ».

- FIX: « Always show » the pictures had no effect on own sent mails :   1.118.1
  - applied in the sent and drafts folders to the mailbox and its identities.
  - an unauthenticated sender no longer offers a choice that would not be remembered.

- NEW: default templates on four levels, and templates that are a complete mail :   1.118.0
  - a new mail or an answer receives its template by itself: me in the mailbox, the mailbox, me, everyone.
  - « No template » at a level stops there; a level not set lets the next one answer.
  - a complete mail takes the place of the text: when a text is written, « Replace » (with Undo) or « Add after ».

- FIX: the dots of the tags show their names in a clean bubble :   1.117.1
  - on hover, every tag of the mail as its badge (colour, border, full name), instead of the native title.

- NEW: tags as dots in the list, and a tag can colour the rows of its mails :   1.117.0
  - columns menu: tags shown with their names or as dots (colour and initial, the name on hover).
  - tag settings: « Row colour » gives its mails a light background of its colour in the list.

- NEW: « Select all » box in the corner of the list, no more column for the columns button :   1.116.0
  - the box selects or unselects every mail of the list, partly checked when some are selected.
  - the columns are chosen from « ⋯ > Columns » or a right click on a header, the subject gets the room back.

- NEW: « Indicators » column in the list, columns of fixed width, the whole header sorts :   1.115.0
  - star, attachment and assigned people in one column by default, as narrow as its content allows.
  - resizing a column no longer moves the others: only the subject takes or gives the room.
  - a click anywhere on a header sorts, dragging it still moves the column.

- FIX: an assignment shows in the lists open by the other users :   1.114.1
  - added or removed, it now appears at their next refresh, without reloading the page.

- NEW: the links of an email removed from the server wait for it, then are forgotten after a delay :   1.114.0
  - a linked email removed or moved by another software shows « Removed from the server on » on its record.
  - its links, notes and assignments come back with it (moved back, restored from a backup).
  - forgotten after 2 years by default, setting in Settings > Server > Keeping (0: kept).

- FIX: emails removed or moved by another software no longer stay in the list :   1.113.2
  - an email marked as deleted (Thunderbird, a phone) leaves the list at the next synchronisation.
  - the folders are compared with the server at least every hour, even when the counts match.

- FIX: anti-spam engine on Rspamd 4 :   1.113.1
  - dev/rspamd moves to Rspamd 4.2.3 and Redis 8; verdicts, learning and counters unchanged for inbox.
  - its new list of equivalent domains read from the image, never from maps.rspamd.com.
  - no worker reads a file path given in a request; run dev/rspamd/run.sh again to apply it.

- NEW: documentation of the module in its setup, tab Technical documentation :   1.113.0
  - the pages of doc/ by theme, the same as in the repository: administrators, then developers of modules.

- NEW: modules allowed mailbox by mailbox before they react to the mails :   1.112.0
  - a module declares what it wants; a manager allows it in Reception > Modules, only what is ticked, test mode first.
  - off by default, switch in the setup of the module; every authorization written in the journal of the mailbox.
  - nothing is given to the modules yet: this version prepares the authorizations.

- FIX: the last version published is known from Setup > Modules :   1.111.1
  - « Check the last version » asks the site of inbox, which follows the latest GitHub release.

- NEW: DMARC aggregate reports read in one click from the email that holds them :   1.111.0
  - a bar « DMARC report » above the email, « Analyze »: figures, sources by IP, records, current DNS on a click.
  - « Your servers fail »: a server allowed by the SPF whose DKIM does not pass; spoofing counted, no alert.
  - hostile until proven: ceilings on unpacking, XML without DTD, values typed, nothing stored.

- FIX: editor of the writing on Tiptap 3.33.1 :   1.110.2
  - bundle of Tiptap shipped in the module (js/vendor/tiptap) rebuilt from 3.32.0 to 3.33.1, fixes of the editor
    upstream; nothing changes in the use.

- FIX: a long call of inbox no longer makes the other pages of the user wait :   1.110.1
  - a read lets the PHP session go at once: a mail read on the server or an archive checked by the antivirus kept
    the session, and every other call of the user (the agenda, the notifications of Dolibarr) waited behind it.
    Kept for the writes and while a protected mailbox is unlocked.

- NEW: the Dolibarr part as a column of its own, full height, at the place of the agenda :   1.110.0
  - on a computer, the Dolibarr part of the email open leaves the email for a column beside the reading, as high as
    the screen, as the panel Today: same width and splitter, same drawer when the reading is narrow.
  - tablet and phone unchanged (on top of the email, a sheet on a phone).

- FIX: agenda panel slow on a large agenda :   1.109.1
  - the events of today and of the coming days were read from the whole history of the agenda (7 s for 800 000
    events, the other calls of the page waiting behind); an event started more than 31 days before is left out.
  - the panel Today as a drawer no longer covers the strip on the right.

- NEW: strip on the right of the emails, space Agenda in the rail, invitations already in the agenda :   1.109.0
  - on a computer, a strip of icons on the right edge holds the Dolibarr part and the panel Today, one column at a
    time; when the reading is narrow, a drawer opened by hand only, and a line of chips under the head of the email.
    The setting « Dolibarr part above / on the right » and the pin of the agenda are gone. Tablet and phone unchanged.
  - space Agenda in the rail: the whole agenda in a frame (the page of an agenda module, else the agenda of
    Dolibarr); « Open the whole agenda » goes there.
  - an invitation an agenda module of this Dolibarr sent itself (hook inboxInviteEvent) says « Already in your
    agenda »: never added twice, the answer recorded in Dolibarr without email.
  - the choice of the agenda panel closed by hand with no email open is kept again (setting not declared before).
  - a page of a module opened from an email: the blocks of the modules read again when its window closes.

- NEW: actions of other modules in a window of inbox, with the subject and the correspondents of the email :   1.108.0
  - an action of a module (hook inboxMailActions) may open its page in a window of inbox instead of a new tab, and
    receives the thirdparty and the contact recognized.
  - its address, as the ones of the agenda panel, may ask the subject, the Message-ID and the correspondents of the
    email: given after the « # » only, never sent to the web server nor written in its logs (README).

- NEW: agenda panel on the right of the emails, the events of the user late, today and coming :   1.107.0
  - shown by itself when no email is open; beside an email, opened by the button of the rail or Shift+F9; pinned, it
    always stays; closed by hand, it stays closed. Docked as a column when the reading keeps its room, over it
    otherwise, as a sheet on a phone (never opened by itself there).
  - the events of the agenda of Dolibarr the user owns or attends (not declined), late ones to do first, today with
    the time now and the ones already over folded, then the next 7 days; neither the automatic events nor the
    records of emails sent or received. « Maybe » shown dashed.
  - case « done » on the events with a progress, with « Undo »; an event opens in a window of inbox, the email it
    was made from opens in the list; a new event takes the thirdparty, the contact and the project of the email open.
  - other agenda modules plug themselves: their own pages for « create », « open » and « open all », their own
    events, or the whole content of the panel (PHP hooks inboxAgendaProvider, inboxAgendaEvents; JS hooks
    inbox:agenda:render, action, toolbar, shown, hidden; README « Agenda panel »). inbox depends on none of them.

- FIX: « update not finished » only when the module must be activated again :   1.106.5
  - shown for a new X.Y only; a fix (Z) replaces the files and asks nothing.

- FIX: long name of an attachment keeps its extension :   1.106.4
  - a name longer than 150 characters was cut with its extension (the file was put in the documents without its
    type); the base is cut now, the extension kept.

- FIX: quick creation of a customer, values cut to the length of their column :   1.106.3
  - a long value of a site or of a signature (name of a contact over 50 characters) made the creation fail with the
    error of the database shown as it was; each value is cut to the length of its column.

- FIX: sender read again when a semicolon is in its quoted name :   1.106.2
  - `"Service ; Compta" <x@y>`: the reader of the mails cut the list of addresses on the semicolon, the mail came
    « without sender » and the checks made on the address (blocked senders, known senders, banners) did not run.
    A reader of our own takes over when the library misses an address of the header (From, Reply-To, To, Cc), with
    quoted names, escapes, comments and groups.

- FIX: proposal of the AI shown through the one door too :   1.106.1
  - the preview of a proposal of the AI (that may have read the mail) goes through Dolibarr.tools.inbox.safeHtml
    as well, not only through the filter of the server (audit 2026-11-14).

- NEW: editor and cards of objects through the one door in the page :   1.106.0
  - editor of the writing (Tiptap, CKEditor, or the one of another module): the HTML given at its opening (quote,
    draft reopened, template), inserted (AI, piece, signature) or put in place of a range goes through
    Dolibarr.tools.inbox.safeHtml; a rich paste too (a mail shown, a page, Word).
  - the value of a variable clicked in the text becomes text, never HTML.
  - card of an object on hover: after its own allow list, DOMPurify and the Sanitizer API (classes of Dolibarr kept).
  - Sanitizer API of Chromium: configuration accepted (data attributes declared), checked in Chrome 156.
  - README: rules of the one door.

- NEW: values of a mail written in Dolibarr through the one door :   1.105.0
  - quote of an answer or a forward: HTML Purifier before the filter of the sending; a text mail quoted keeps a
    « `<b>` » written in it (shown, no longer removed).
  - quick creation of a thirdparty and its contact: values cleaned by the one door, extrafields by their type (an
    HTML extrafield gets the text escaped, a list one of its keys only, the lists of the database nothing).
  - « Create ▾ »: values of the mail (sender, subject, data of a site) cleaned before they go in the address of the
    page of creation of Dolibarr, not only by the filter of that page.
  - agenda (invitation, failed sending, sending recorded on a piece): titles and notes through the one door; an
    address between angle brackets stays (‹paul@ex.fr›), a « `<b>` » of a note is shown instead of removed.
  - data of a site (X-Data-Form, X-Quotation-Data), number of a signature put on a contact, file of a mail put in
    the documents of a piece (no bidi override character in its name): through the one door.

- NEW: one door for what comes from a mail, DOMPurify and HTML Purifier :   1.104.0
  - InboxSafe: the HTML body for the reading frame (as before), the HTML body that leaves it (HTML Purifier, strict
    list: no style block, no class, no id, no script scheme), plain values for the fields of Dolibarr (no tag, tags
    written in entities included, no double quote, no control nor bidi override character), plain text escaped for
    an HTML field.
  - Dolibarr.tools.inbox.safeHtml in the page: DOMPurify, then the Sanitizer API of the browser when it has one;
    without both, the text only.
  - libraries shipped in the module (DOMPurify 3.4.17, HTML Purifier 4.19.1, loaded only when needed), update
    scripts in dev/, marks « TODO core: » for the day inbox moves to the core.
  - tests: corpus of attacks on both sides (PHPUnit, jsdom); tests of the editor run again (matchMedia of jsdom).

- NEW: servers of a new mailbox found from its address :   1.103.0
  - the address typed, the server of Dolibarr looks for the servers in the order of Thunderbird: a mailbox of the
    same domain already in inbox, the configuration file published by the domain (autoconfig), its Autodiscover
    service (the address only, never a password), the providers recognized by the domain or its receiving server
    (Google, Microsoft 365, OVHcloud...), the database of Thunderbird, the DNS records (SRV), the usual names.
  - the form of a new mailbox: the address and the login first, the servers folded with their summary; the result
    says where it comes from and what was looked for; servers typed by hand are never replaced (« Use these
    settings » offered). The connection test checks what was found.
  - Google and Microsoft: the OAuth service set up in Dolibarr chosen, else what to do (needed for Microsoft 365).
  - the database of Thunderbird (Mozilla) gets the domain name only: off by default (Settings > General > New
    mailboxes, listed in the outside services), else offered by a click when nothing is found.

- FIX: dates, numbers and sizes in the language of Dolibarr, English texts, option of Dolibarr named :   1.102.18
  - dates, times and numbers shown in the language of Dolibarr everywhere (some followed the browser); sizes of files
    in the units of the language (o/Ko/Mo in French, B/KB/MB in English).
  - English texts say « email » for a message (« mail » stays for the mail server and the mailbox).
  - sending disabled: the option of Dolibarr cited by its own label, in every language.
  - panel of the notifications: a long « Mark all as read » (German) goes on two lines, the buttons stay in the panel.

- FIX: protected mailbox at the login, « locked » instead of « mailbox not found » :   1.102.17
  - the first list of a new session (PWA) came before the silent unlock of a protected mailbox and was refused as
    « mailbox not found », only a retry button; it is now told as locked (unlock button) and the list is loaded
    again by itself once the silent unlock is done.

- FIX: writing on a phone, formatting strip right above the keyboard :   1.102.16
  - the bubble of a selection was set near it, where the menu of Android (translate, cut, copy) also goes and covered
    it: it is now a strip of the whole width right above the keyboard, above the foot once the keyboard is closed.
  - the whole toolbar sticks above the keyboard too (no strip over it then); the text scrolls above both, a selection
    near the bottom is kept in view. The foot and the signature stay under the keyboard, seen again once it is closed.

- FIX: writing on a phone, light foot and signature as one line :   1.102.15
  - the foot keeps send, attach and « More »; insert a piece, linked documents and templates open first in that
    menu, discard set apart at its end. Round buttons of 48 px, well apart.
  - the signature as one line « Signature: name », its preview on a tap.

- FIX: writing on a phone, formatting on demand and a bubble on a selection :   1.102.14
  - no permanent toolbar, the text takes the room. The whole toolbar on demand (menu « More » or the bubble), one
    scrolling row with a close button; the native CKEditor keeps its own toolbar.
  - a selection (finger or mouse) shows a bubble under it: « Formatting », the AI, « More » (link, clear the
    formatting, whole toolbar); « Formatting » turns it into a short bar (close, bold, italic, underline, colours,
    link) which stays after a colour, a background or a link. Never over the foot nor the whole toolbar.
  - the AI and voice typing in the menu « More » (not in the source mode, where they would write in the hidden text).

- FIX: name of a colleague as sender, head of a conversation, chip of a colleague :   1.102.13
  - a user of Dolibarr as sender: his name on one line with the address under it on a phone (they were two columns,
    the name on two lines), after it on a computer.
  - head of a conversation: « 2 mails in the conversation · received on » and the mailbox read as one sentence.
  - chip of the Dolibarr part (phone): a colleague is shown by his name, never « unknown to Dolibarr ».

- FIX: encoded words left in subjects and names decoded :   1.102.12
  - « l'expé=?UTF-8?Q?dition?= », « de =?UTF-8?Q?l=E2=80=99UE…?= »: the reader of the library leaves them after raw
    UTF-8 or glued to a word (sent by some mailers after a forward); adjacent words joined before decoding, other
    charsets converted. The mails already indexed are repaired once (subjects, names, recipients, references).
  - a word longer than the screen in the subject of the reading breaks instead of going out of it.

- FIX: back of the browser to the mail you were on, back arrow to the list :   1.102.11
  - a jump from a mail to another one (« See the mail », the bell, after a sending, a bounce) is a step of the
    history: the back of the browser or of the phone comes back to the mail you were on. The back arrow of the page
    goes straight to the list, however many mails were jumped through (each step keeps its distance to the list).
  - while the address is followed (back, forward, reload) it is no longer rewritten (each back made a new step).

- FIX: the mail open kept in the address, back of the browser to the list :   1.102.10
  - `#/mail/<folder>/<mail>` (and `#/mail/view/<view>/<mail>`, `#/mail/vview/<id>/<mail>`): a reload opens the mail again,
    a mail moved or removed meanwhile says so and gives back its list. Only numbers in the address.
  - opening a mail from its list is a step of the history: the back of the browser, or of a phone, closes it and
    shows the list; the back arrow of a phone is a real back. Going from a mail to another (keys, a click, the next
    one after an archiving) changes the address only, without a step.

- FIX: picture of the recipient on the cards of the sent mails and drafts :   1.102.9
  - the first recipient instead of nothing (the sender would be the mailbox on every card); avatar.php to=1 reads it
    from the row of the mail, with the rights of the user, never a brand logo.

- FIX: an answer sent from the mailbox as one line in the notes :   1.102.8
  - « Replied to Sophie Renaud · Yesterday 17:18 », discreet, cut with « … » in a narrow column; a click opens the
    subject, the recipients, from where (Inbox and who, or another mail software), the full date and « See the mail ».
    It was a block of several lines that widened the column of the notes.

- FIX: label « Notes » hidden on phones and tablets :   1.102.7
  - the box to write a note made a hidden second column in the Dolibarr part, the label shrank to nothing under the
    first note.

- FIX: attachments of a mail in a sheet on a phone :   1.102.6
  - the bar at the bottom keeps one line: the answers (the icon above its name, the same width) and a paper clip with
    the count of the files; the list of the files rises from the bottom as a sheet, as the Dolibarr part (save all,
    close, a tap on the veil, Escape or a swipe down). Unfolded in the page, it took about 180 px of the reading.

- FIX: a mail scrolls as one page on phones and tablets :   1.102.5
  - its head, the chips, the subject, the banners, the Dolibarr part in place (tablet) and the body move up
    together under the row « back, actions »; the bar of the answers and attachments stays at the bottom. Only the
    body scrolled before, in a slit of about 130 px on a phone. A computer keeps the head fixed.

- FIX: line of the recipients on one line on a phone :   1.102.4
  - « To … · received on … » cut with « … » on one line; a tap opens it whole, a second one closes it (a tap on a
    name still opens its card).

- FIX: short date beside the name in the head of a mail on a phone :   1.102.3
  - « 09:12 », « Yesterday 15:10 », « Wed 11:00 », then the day and month with the time, the year only when it is not
    this one; the full date stays as tooltip and for screen readers. It took a line of its own.

- FIX: top bar of the application hidden while a mail is read on phones and tablets :   1.102.2
  - reading is a task screen: the bar (theme, bell, settings) shows on the folders and the list only, the row
    « back, actions » becomes the first line. Unread notifications put a dot on the back arrow meanwhile.

- FIX: Dolibarr sheet hidden at once when a mail opens on a phone :   1.102.1
  - the part showed in place while it loaded, then slid down to hide; it is now a hidden sheet from the start, the
    slide kept for the openings and closings asked by the user. A loading error stays shown in place.

- NEW: the Dolibarr part as chips and a bottom sheet on a phone :   1.102.0
  - under the head of the mail, before its subject: a chip for the thirdparty (green dot when known), « New
    customer » when the sender is unknown, or the requester of a data source; a chip for the pieces and one for the
    notes with their counts (« + » when there is none yet). The body of the mail comes back in the first screen.
  - a chip opens the whole Dolibarr part in a sheet rising from the bottom, on its section; closed by its button, a
    tap on the veil, Escape or a swipe down on its head, the focus back on the chip. Tablets and computers keep the
    part in place; turning the screen paints it again the right way.
  - images blocked: one line, « Always show » as its arrow; « received on » not written again when the only
    recipient is the mailbox itself; the bar « update not finished » readable on a phone (its text was a column of a
    few letters beside the button).

- FIX: answer buttons at the bottom of a mail on a phone :   1.101.2
  - « Reply to all » did not fit and « Forward » went out of the screen: under about 480 px the three buttons share
    the width, the icon above its name in small, as a bottom navigation bar.

- FIX: writing area filling the window, toolbar at the bottom on a phone, discard set apart :   1.101.1
  - the text area of a writing takes the free height of its window: on a phone the formatting toolbar, under it,
    stays at the bottom above the send bar instead of floating in the middle of the screen with an empty area below;
    a tap anywhere in the empty area puts the caret. Same on a computer when the window is enlarged.
  - the button « Discard » stands apart from « More actions » and turns red on hover.

- NEW: editor of the writing on a small screen :   1.101.0
  - Settings > General > Writing editor, « On a small screen » (phones and small tablets, under 1024 px): the Inbox
    editor (default), CKEditor with the toolbar of Inbox, or the same as on a computer. The native toolbar of
    CKEditor (five rows of buttons) no longer takes half of a phone screen.
  - chosen when the writing opens, kept if the screen turns or the window is resized while typing (no lost selection
    nor undo history); the docked writing window of a computer, narrow too, keeps the editor of the computer.

- FIX: tooltips of the heads of the panes below the button on phones and tablets :   1.100.1
  - the bubble of a button of a pane head (« ⋯ », « New mail », the actions of the mail read) opened above it and
    went under the top bar of the application; on a narrow screen it now opens below, aligned on the right near the
    edge.

- NEW: actions of the mail at the top of the screen on phones and tablets :   1.100.0
  - narrow screens: the buttons of the mail move to the row of the back arrow, which never scrolls, so they stay at
    hand while reading; the sender, his address and the date get the whole width again (they were squeezed into a
    narrow column, the date on five lines).
  - phone: Tag, Archive and Delete stay as buttons, Delete set apart; Mark as unread, Star and Junk open first in the
    menu « More actions ». Reply, Reply to all and Forward stay in the bar at the bottom of the mail only.
  - round icon buttons without border, 44 px touch targets, a real back arrow; « Dolibarr on top / on the right »
    is no longer offered on a narrow screen, where it has no effect.

- NEW: recipients proposed ranked by context and exchanges :   1.99.0
  - while typing a recipient, all the sources (contacts, thirdparties, users, correspondents of the mailboxes) are
    ranked together instead of one after the other in alphabetical order: first the contacts of the thirdparty of the
    mail answered and of the recipients already given (then those of the other thirdparties such a contact is linked
    to), then the addresses written to often and lately, then those read from, then how well the words match.
  - several words in any order (« jean dup », « dupont acme »), the name of the thirdparty searched too; a word found
    only inside a name or in the domain counts little.
  - a click in an empty field (Cc...) proposes the contacts of these thirdparties at once, without typing.

- NEW: result of a tag seen while its colour is set :   1.98.0
  - list of the tags: while the colour or the border of a tag is set, the tag as it will show on the mails (its
    colour, black or white text, border) covers its name, then fades a few seconds after the focus left them; a click
    on the name gives it back at once. The line « New tag » too.
  - the drag handle and the key share one column, under the head « Key ».

- NEW: mails got now from the list :   1.97.0
  - a « Get mails now » button (round arrow) in the head of the list, instead of waiting for the next automatic pass
    (about 20 s): the folder open only (the Inbox of a mailbox clicked included), else, in a view (unified inbox,
    unread...), the Inbox of each mailbox of the view; called by the tab clicked itself (under a second), the other
    tabs of the browser updated after.
  - a folder got again 3 s after its last synchronisation at least, never at each click.

- FIX: unsubscribe refused by the site of the list, the mail or the page of the list offered :   1.96.1
  - when the site of the list refuses the one-click request (an anti-bot wall answering HTTP 403 to any server), a
    dialog offers instead of an error only: the unsubscribe mail to the address of the list (mailto), prepared,
    checked and sent by the user, and the unsubscribe page of the sender opened in a new tab (a browser passes the
    wall; the sender then sees the IP address and the browser, said in the dialog).

- NEW: archived mails marked read :   1.96.0
  - My account > Reading: « Mark as read what I archive », a setting of its own beside the one of the trash and junk
    (on by default): an archived mail no longer counts among the unread mails of the archives.
  - « Undo » in the toast gives the mail back to its folder, unread again.

- NEW: signature changed for one mail :   1.95.0
  - a pencil beside the ✕ of the signature in the writing: the signature goes into the text, framed and named
    « Signature changed for this mail », to change a phone number, remove a line...; the signature of the identity
    stays as it is. « Undo » in the toast brings back the signature that follows the identity.
  - the identity changed after: the changed signature stays (never lost), a bar offers to replace it by the one of
    the new identity (Ctrl+Z takes it back), or to remove it when the new identity has none.
  - kept in the drafts as changed, sent once, with the « -- » delimiter of the text part; same with the Inbox editor
    and CKEditor (both modes).
  - Inbox editor: the cells keep a border on one side with its own width and colour (the bar of a signature) and a
    spacing on one side only as written, and a bold or an italic its own style (colour), instead of the grid of the
    editor.

- NEW: the CKEditor of Dolibarr as writing editor :   1.94.0
  - Settings > General > Writing editor: the Inbox editor (default) or the CKEditor of Dolibarr, offered when the
    module « WYSIWYG editor » is on with CKEditor; for the writing, the signatures and the templates.
  - CKEditor with the toolbar, menus and bubbles of Inbox (default): tables and their properties, pictures and their
    sizes, colours, links, variables of the templates as chips, AI and voice typing as with the Inbox editor.
  - option « Toolbar and context menu: those of CKEditor »: the classic CKEditor of Dolibarr, its panels and dialogs
    shown above the dialogs of Inbox; voice typing, AI and the variables are buttons of its toolbar (last group).
  - the choice applies to the writings opened next, without loading the page again; when CKEditor cannot start, the
    writing opens with the Inbox editor.
  - the bars over a selection or a picture go under it when above they would cover the toolbar (text on the first
    lines), whatever the editor.

- NEW: archive notifications of the bell :   1.93.0
  - an « Archive » button on each notification (on hover or focus, always on touch screens), and « Archive all » in
    the head of the panel.
  - an archived notification leaves the bell at once, read at the same time; « Undo » in the toast brings it back.
  - archived notifications are kept until the purge (INBOX_NOTIFICATION_DAYS): a mailbox in error archived is not
    told again for the same episode.

- FIX: tags of a mailbox in global mode, read only, the global list no longer changed from a mailbox :   1.92.5
  - an admin could change the global list from the Tags tab of a mailbox: the change looked local to the mailbox
    while it applied to every mailbox in global mode.
  - in global mode, the tab shows the tags without fields, for everyone; a notice offers « Create a list for this
    mailbox only » and, for admins, « Change the global list » (Settings > General).
  - the server refuses create, change, delete and reorder of tags sent with a mailbox in global mode, admins
    included.
  - switching the list (global or own) asks first: copy of the tags (or the own list kept from before), or back to
    the global list with the keywords missing from it no longer shown as tags.

- FIX: mail drawn higher and higher, endless scroll :   1.92.4
  - some mail templates (Nextcloud notifications...) make the page of the mail at least as high as its frame: the frame
    grew to the mail + 8 px, the mail followed, at every frame drawn.
  - the height of the mail no longer depends on its frame, the margin under the mail is inside it.
  - safety net: a frame growing without a pause stops being fitted after 30 growths.

- FIX: data sources, active or not with a switch :   1.92.3
  - the list shows a switch in front of each source, saved at once (back to its state with the error if refused);
    the badge « Active » goes, a source turned off is greyed.
  - « Without effect » beside a source active without trusted sender (it reads no mail).
  - « Remove » moves to the « ⋯ » menu of the row, no longer beside « Edit ».
  - in the form, « Active » is the first setting, a switch saved with the form, its hint says what the source does.
  - « Start from » (ready-made source, mail of the site) only when the source is created.

- FIX: mail written by the mailbox, the thirdparty of its recipient instead of the ones of our domain :   1.92.2
  - a mail sent by the mailbox (or by another address of our domains) looked for its thirdparty by its own address,
    then offered every thirdparty using our domain (« 5 thirdparties use the domain … : which one? »).
  - the panel Dolibarr now looks at the recipients outside of us (to, then cc), the first known in Dolibarr first,
    under the label « Recipient »; the user of Dolibarr who wrote stays shown.
  - our domains are never offered as a shared domain; a mail between us offers nothing.
  - « Not the right thirdparty? » remembers the address of the recipient for such a mail, nothing for a mail between
    us.
  - « New customer » is no longer offered from such a mail (it would read our own signature).

- FIX: mails of a site form, one conversation per requester instead of one for all :   1.92.1
  - a form sends every request from the same address with the same subject: the fallback « same subject, same
    correspondent » put them all in one conversation, with every answer to every customer.
  - for a mail of a data source, the correspondent is now the requester read in its headers; without a requester
    known, the mail never joins by the subject (only by its references: In-Reply-To, References).
  - conversations made again when a data source is saved active, and once at the update: the mails already merged
    are split (the links to Dolibarr stay on each mail).

- NEW: access level « Handle », between « Read and send » and « Manage all » :   1.92.0
  - « Handle » looks after the mails: tag, move (also to another mailbox handled), archive, trash and junk, delete
    for good, empty the trash or the junk, ignore a conversation, create, rename and move folders, import mails.
  - « Manage all » keeps the mailbox itself: deleting a folder, identities, templates, rules, blocked senders,
    unsubscribe, accesses, journal.
  - existing accesses unchanged: a person in « Read and send » stays there until raised.
  - the checks « at least send » compare the ranks of the levels, so « Handle » sends too; error « this action needs
    the level Handle » for the actions it opens.

- NEW: access of a mailbox, what each level gives shown :   1.91.0
  - levels named so that each one holds the one before: « Read », « Read and send », « Manage all » (also in the
    messages that name them).
  - « What each level allows »: a table of checks per level, open while nobody uses the mailbox, folded after.
  - under the level chosen to give an access: what the person will do, and what she will not.
  - the help no longer says that « Read » tags: tagging needs « Manage all », as moving or deleting.

- FIX: one « Undo » toast at a time :   1.90.4
  - a new toast with « Undo » replaces the previous one instead of stacking under it: the one above looked like the
    undo of the last action. The older actions can still be undone from the journal.

- FIX: undo of a move into or out of a subfolder, no more « Bad undo » :   1.90.3
  - the journal writes the folders as people read them (« INBOX / Trash » for INBOX.Trash), the check of the undo
    compared them with the path of the server: every undo touching a subfolder was refused (since 1.35.1).
  - the path of the server is now compared in the form of the journal too.

- FIX: undo of a mail put in the trash or in junk, the mail followed to its new row :   1.90.2
  - a mail moved back may get a new row (mail server that does not give its new identifier: the folder is read
    again); « unread again » (1.90.0) and the keywords now follow it to that row instead of the old one.

- FIX: a mail moved, at once on the screen until the server is done :   1.90.1
  - the mail no longer comes back in the list while the server moves it (refresh of the list, synchronisation,
    another tab): it stays out of the lists read meanwhile.
  - toast « Moved to … » with « Undo » as soon as the mail is dropped (under 20 mails); an undo asked before the end
    is done once the server has finished.
  - the folder reached counts the mail at once, with a small spinner while the server works; the counts of the
    folders concerned are no longer taken from the server meanwhile.
  - an error alone is told, naming the mail and the folder; the mail goes back to its place without replacing the
    list shown now (another folder opened, another action done meanwhile).
  - the page asks before being closed while a move runs.
  - same behaviour for archive, delete and junk.

- NEW: a mail put in the trash or in junk is marked read :   1.90.0
  - « Delete », « Junk » and a move by hand to the trash or to junk mark the mail read first: it no longer counts
    as unread there.
  - the undo gives the mail back to its folder, unread again.
  - My account, Reading: switch « Mark as read what I delete or put in junk » (on by default), shown whatever the
    marking mode: a mail can leave the list without being opened.
  - mails moved to junk by inbox itself (blocked sender, judged spam) stay unread, so that a mistake is seen.

- FIX: list of the mails, card reached with the keyboard drawn like a selected card :   1.89.14
  - a 2px border of the accent color instead of a second outline inside the card (a double line with a gap).

- FIX: attachment dragged out of the browser, no more text file holding its name :   1.89.13
  - the name travels in a private type, no longer as text: a file manager made a .txt file of it.
  - a file found clean by the antivirus, without warning, is given to the browser to download (Chrome, Edge on
    Windows and macOS); a file not scanned, risky or infected leaves only by the download button and its warning.
  - Linux: Nautilus (GTK 4) does not take such a drop, the download button stays the way there.

- FIX: writing of a protected mailbox, nothing in clear on the disk :   1.89.12
  - its files (state, mail quoted, attachments) sealed by the key of the session (AES-256-GCM, the key in the cookie
    of the browser): readable by a request of this browser in this session only, a copy of the disk shows nothing.
  - a writing begun from another mailbox is sealed as soon as an identity of a protected mailbox is chosen; never
    opened when the key of the session is missing; the writings of the other mailboxes as before.

- FIX: settings of the mailboxes, actions easier to find :   1.89.11
  - card of a mailbox: « Delete the mailbox » always shown, greyed while the mailbox is not disabled, with the
    reason on hover and on click (it only appeared once the mailbox was disabled).
  - list of the mailboxes: buttons of the bar of the selection readable again (white on a light background: the
    colors of the bar of the list of mails were applied there too).

- FIX: settings of the application, who may change each one written in its definition :   1.89.10
  - each setting declares who may change it: manager (managers of the mailboxes) or dolibarr_admin; a right missing
    or mistyped is read as dolibarr_admin: a setting added later is never opened to more people than meant.
  - the constants that go with a change (password of the controller of Rspamd when its server changes, last error)
    declared with the setting, no longer written apart.
  - the screens read the settings this user sees without changing them from the server: fields and switches
    frozen, nothing written by hand in the script.

- FIX: security audit of 2026-11-11, remarks :   1.89.9
  - protected mailbox: its port of Sieve kept as its servers (the password goes there); its encrypted copies and its
    notices removed with the mailbox.
  - an attachment filed in the documents of a piece: an executable file gets « .noexe », as an upload of Dolibarr.

- FIX: security audit of 2026-11-11, anti-spam and unsubscribing :   1.89.8
  - blocked senders: from a mail whose sender is not proven, the block for every mailbox by the managers of the
    list; a blocked address become the one of a user of Dolibarr told to the managers, no longer unblocked alone
    (our own domains still are).
  - learning of the anti-spam engine: 200 mails per user and per day (INBOX_RSPAMD_LEARN_MAX_PER_DAY), the base
    being common to every mailbox.
  - unsubscribing by mail: the address asked must belong to who signed the mail or to its sender, never a text of
    the sender to a third party from the address of the company.

- FIX: security audit of 2026-11-11, links, imitations and pieces :   1.89.7
  - the link follows the conversation for a correspondent of it only (sender or recipient of its mails): a
    Message-ID or a subject copied by someone else never gives him its pieces.
  - links of a mail: the host of a link read before the address in its query (an imitation was missed); a
    recognized domain written to the left of another one (thersane.fr.secure-pay.io) said as an imitation.
  - blocked senders: the names of the thirdparties and contacts for who may read the thirdparties, the subject of
    the mail of a block for the managers of the list and its author; « sender of a mail linked to a piece » found
    again (its query never answered).
  - « Edit as new » and a draft taken back: no style that lays a block over the page or loads an address, tracking
    pictures removed and remote pictures as for a quote (Settings > Security).
  - « New customer »: the extrafields that the page of creation lets the user fill only.

- FIX: security audit of 2026-11-11, hardenings :   1.89.6
  - « Detach from the conversation » and its undo: by who may send from the mailbox, the links being shared by every
    copy of the mail.
  - undo of « New customer »: only what the panel made in the session; values of the panel: a tag written in
    entities removed as well.
  - undo of an action outside the journal: the mails named by the lines undone only, never more mails than they did.
  - brand logos (SVG): refused when a DOCTYPE comes out once read (encoding UTF-7) or when not in UTF-8.
  - Settings > Server: the password of the master user typed again when its server changes.
  - dev/rspamd/run.sh: a random password of the controller when none is given, never a password known in advance;
    the DNS asked by Rspamd for the senders said in the README.

- FIX: security audit of 2026-11-11, thirdparties of the mails and private network :   1.89.5
  - contacts left to create after « New customer »: the thirdparty named only with the rights of the user; this
    memory written by the panel only, never from My account.
  - « Not the right thirdparty? » remembered for the address: by who may send from the mailbox, the mails of the
    mailboxes of the entity only.
  - card of a piece of Dolibarr on hover: its HTML kept to simple tags, no event, links and pictures of Dolibarr
    only (the core does not escape every value of its cards, the subject of a ticket made from a mail for one).
  - test of a server by a manager who is not an administrator of Dolibarr: an IPv4 written in an IPv6 (::ffff:..,
    NAT64, 6to4) and the shared ranges of the operators counted as private; address of a window of a module
    without a backslash.

- FIX: security audit of 2026-11-11, authentication of the senders and alerts against frauds :   1.89.4
  - Authentication-Results read result by result: a « dmarc=pass » or « dkim=pass » put by the sender in its
    address of the envelope or in a comment is no longer taken for the verdict; DMARC proves the domain it was
    evaluated on only (header.from = the sender shown); the domain of a DKIM signature read after the @ of header.i.
  - without a receiving server declared in Settings > Anti-spam, no sender is proven (pictures shown alone, badge
    of a recognized domain, requester of a data source, brand logo): the header may be written by the sender.
  - entry of the mail into our servers: the block of the top only, a false « Received ... by `<our server>` » put
    lower by the sender no longer makes its anti-spam headers believed; without servers declared, the anti-spam
    engine of inbox no longer leaves aside a mail that brings its own verdict.
  - bank details: the text and the HTML of the mail read (a mail could show its IBAN in HTML only); groups of an IBAN
    separated by dots, dashes or narrow spaces, invisible characters inside the words or the numbers, still found.
  - « Replace the number of the card »: offered and accepted for a proven sender only (no Reply-To elsewhere, never
    the requester of a data source).

- FIX: security audit of 2026-11-11, protected mailboxes keep their promise :   1.89.3
  - level « The password and the content »: the index of Meilisearch removed before the local data (it was left
    there), refused while Meilisearch does not answer or while mails wait in the queue; the files of the cache and
    of the queue and the journal of the actions removed with the rest; the sources left by a session that died
    removed after a day; the answer of the mail server to a sending refused no longer written in the log.
  - level « The password »: the index, the cache and the search of a mailbox not unlocked in the session are never
    read, an access given without a copy of the password opens nothing; back to this level from a session that
    unlocked the mailbox only.
  - master user of the mail server: a mailbox of its server cannot be protected, a master user cannot be set for a
    server with protected mailboxes, nor a mailbox in master mode made for the address of a protected one.
  - the other scripts of the page never get the private key of the device; the fingerprint of a device computed
    from its key, a copy given only to the key whose fingerprint was shown.
  - 5 passwords refused per user and per mailbox in 15 minutes; the older copies of the colleagues made obsolete by
    who decides or manages the mailbox only; a copy found in Drafts sent only if marked by a page; the notices told
    once forgotten by a POST.
  - « Does not protect against » also names a script run in the page and a user who keeps the password he knew.

- FIX: security audit of 2026-11-11, addresses of the anti-spam engine chosen by an administrator of Dolibarr :   1.89.2
  - the mails and the password of the controller are sent to these addresses: a manager of the mailboxes who is
    not an administrator of Dolibarr sees them without changing them (as the master user of the mail server).
  - the saved password of the controller is removed when its server changes, never sent to another one.

- FIX: security audit of 2026-11-11, what inbox writes in the agenda of Dolibarr :   1.89.1
  - an invitation could run a script in the agenda: its description and its organizer are written in the note of
    the event as text; its title and its place on one line, without tag nor double quote (the form of the event
    prints them as they are).
  - a mail recorded on a piece: the text written is put in the note of the event as text, a tag typed stays a text.
  - delivery reports: the recipient read in the report must be an address; the title of the event « not delivered »
    and of a mail sent cleaned the same way.

- NEW: protected mailboxes, opened only by the browsers of their users, nothing kept in Dolibarr :   1.89.0
  - Card of the mailbox > Server, row Protection under the password (admin of the mailboxes; the owner of a personal
    mailbox in the tab Encrypted accesses), login and password only, the password tried on the mail server. Two
    levels: « The password » (default: it leaves the database, the mailbox works as the others while a session
    unlocked it, synchronised by the pages of its users, never by a scheduled job) or « The password and the
    content » (the local data of the mailbox emptied, read live); the level can be changed afterwards.
  - Each browser keeps its own key (not extractable); the password is encrypted for each device allowed. Unlocked
    in silence at the opening on an allowed device, else by the password of the mailbox or the approval from
    another device of the user (fingerprint compared); unlocked for the session only.
  - Encrypted accesses: a manager who has the mailbox unlocked gives the access to the devices of his colleagues,
    fingerprints shown, never automatic; devices revoked in My account > My devices or by the admin, copies of who
    loses the access deleted; back to the normal mode, reset when every device is lost; journal without content.
  - Level content, read live on the mail server: folders, list, search by the server, reading, attachments,
    flags, moves, archives, trash; no index, cache, link, assignment nor journal of the mails.
  - Sending: the delay of the undo kept in the session (encrypted), closing the page sends at once; a copy marked
    in Drafts offered at the next opening if the browser died; scheduled sendings wait in Drafts, sent while a page
    is open (catch-up asked by default); a failure while no page was open is told once.
  - Card of a mailbox in 6 tabs instead of 12: Overview (only what asks for an action: not active, test failed,
    synchronisation in error, nobody with an access, special folders not found, devices waiting), Connection, Access,
    Writing (identities, templates, out of office), Reception (rules, tags, card of the thirdparty), Journal; their
    sections as chips, the addresses of the sections kept; « Incoming / Outgoing server » in the tab Server.
  - A mailbox not tested yet: « Test the connection » beside the switch of its status, the switch usable as soon as
    the test passed; the result of the test in a window (its steps shown at once), also kept under the form.
  - Writing: a table written elsewhere (signature with border="0") keeps its own borders, no grid added in its cells.

- FIX: known senders of the company no longer flagged « Probable imitation » when Plesk repeats its results :   1.88.11
  - Plesk writes its dmarc and dkim results twice in its Authentication-Results header (mails between colleagues,
    forwarded or written on the server): a result repeated as is now counts once.
  - Two spf or dmarc results that differ (value or domain) in the header of the server stay refused, the detail
    tells « conflicting results ».

- FIX: mails answered easier to see in the list :   1.88.10
  - The reply mark takes the accent color, a little bigger and thicker, in the cards and in the table.
  - Its label « Answered » on hover and for the screen readers (5 languages).

- FIX: glossary of the terms explained where they are named, their word underlined with dots (`<dfn>`) opening the
  definition :   1.88.9
  - SPF, DKIM, DMARC in « All the headers » and in « Recognize this domain »; Gravatar, BIMI, lists of reputation
    and Rspamd in the « Outside services » and the section of the engine.
  - One definition per term (js/inbox.js, 5 languages); in the code by term(), in the texts only by markers
    [[key:word]] of new language keys read with thTerms(), never by th() or t(): an existing text read elsewhere
    never shows a marker. A test checks the markers (known terms, the same in the 5 languages, read with
    thTerms() only).

- FIX: cards of the list of mails wider :   1.88.8
  - The dot of a new mail goes before the date: no more empty column on the left of the cards.
  - Comfortable density: a bigger picture of the correspondent (32 px) beside the name and the subject, the
    snippet and the bottom line on the whole width.
  - The bottom line of a card is hidden when empty in every density, comfortable included.

- FIX: sender cards lighter :   1.88.7
  - Open thirdparty or active contact (the expected status): a small green dot beside the name, its label on
    hover and for the screen readers, instead of the « Open » badge.
  - Any other status (closed thirdparty, inactive contact) keeps its badge, to be seen; the pieces keep theirs.

- FIX: BIMI logos safer :   1.88.6
  - Shown only for a known sender (domain recognized or address linked to a thirdparty), besides DMARC: a
    lookalike domain may publish the logo of another company.
  - SVG rebuilt from an allow list (InboxSvg, pure PHP): no script, event, style sheet, foreignObject, outer
    reference nor entity; the logos already kept are cleaned when read.

- FIX: risks said in Settings > General > Pictures of the correspondents :   1.88.5
  - Gravatar not recommended: anyone can attach any picture to their address, it proves nothing about the sender.
  - BIMI renamed « Brand logos »: the logo confirms the sending domain, not the company (a lookalike domain may
    publish the logo of another one); no more promise that a forged mail never shows it.

- FIX: « Rspamd » explained where it is named (Outside services, section of the engine in Settings > Anti-spam): the
  word underlined with dots (`<dfn>`) opens a bubble on hover, keyboard focus or touch; the infotips of inbox take a
  term as well as their icon :   1.88.4

- FIX: Settings > Security opens on the « Outside services »: every function of inbox that asks a service out of
  our servers (Gravatar, BIMI logos, AI of the writing, voice typing, lists of reputation by DNS of Rspamd), what
  leaves and to whom, its state and the way to its setting :   1.88.3

- FIX: Rspamd started with dev/rspamd/docker-compose.yml (Rspamd and Redis, Redis on an internal network without way
  out, restarted with the server); run.sh writes the hashed password of the controller (RSPAMD_PASSWORD) :   1.88.2

- FIX: anti-spam engine checked on a real Rspamd 3.11.1 :   1.88.1
  - Spam decided by the threshold « add header » that Rspamd sends back (its action depends on modules made for a
    mail server), shown as the threshold of the mail; a check ended by such a module (greylist) is an error said
    in the settings, never a verdict; « nothing to learn » (HTTP 204) is not an error; wrong password of the
    controller said as such (HTTP 401).
  - dev/rspamd: greylist and ratelimit off; the lists of maps.rspamd.com and the update of the rules off for good
    (override.d: local.d merges its lists with the defaults); lists of reputation by DNS off in the configuration
    too. Checked: the container connects to Redis only.

- NEW: anti-spam engine of inbox, Rspamd installed beside Dolibarr (self-hosted, off by default) :   1.88.0
  - The new mails of the inbox are judged at the synchronisation (after the blocked senders, 20 per pass, never
    the old mails nor the other folders): those without verdict of the filter of the server, or all of them. A mail
    judged spam goes to the junk (journal « put in junk, judged spam »), never when its sender is known to Dolibarr
    or protected, never a mail marked « not junk »; the reading says « the anti-spam engine of inbox judges... ».
  - « Junk » (J) and « Not junk » (Shift+J) are learned by the scheduled job (queue, the click stays instant; an
    engine stopped fails nothing); training of the start: the junk of the last 6 months as spam, the mails answered
    as legitimate.
  - Settings > Anti-spam > Settings: switch, addresses of Rspamd and of its controller, password of the controller
    (encrypted, never sent back), test of the connection (version, mails learned), mails checked, put away, learn;
    the lists of reputation by DNS (Spamhaus, SURBL...) ask outside services: off by default, turned off request
    by request, a dialog says what leaves when turned on. Nothing is ever sent to rspamd.com.
  - dev/rspamd/run.sh: Rspamd and Redis of the tests (Docker), configuration without any call to rspamd.com.

- NEW: the blocked senders are checked again every night :   1.87.0
  - Scheduled job « Check the blocked senders again » (every night, 2 minutes at most, the entries checked longest
    ago first): a sender blocked while unknown that became known to Dolibarr (a new customer, a contact created)
    stays blocked and is shown to the managers of the list in Settings > Anti-spam (warning, filter « became
    known », reason and date on the row), who unblock it or « keep blocked »; a sender that became protected (a
    user, our domain, a recognized domain, a data source) is unblocked. Journal: unblocked (became protected), kept
    blocked (became known).

- FIX: the verdict of the anti-spam filter of the server (SpamAssassin, Rspamd) is believed only when written by our
  servers: with trusted receiving servers declared, the anti-spam headers below the entry of the mail into them
  (a « Received: ... by » or « Authentication-Results » of these servers) are ignored and named in the window of
  all the headers; when our filter is off, a « No » written by the sender is no longer believed. A mail that never
  came through these servers keeps the topmost header. Switch « Use the verdict of the filter of the server » in
  Settings > Anti-spam > Settings :   1.86.1

- NEW: an update of inbox not finished is told to the administrators :   1.86.0
  - The version of the files newer than the one of the last activation (written by Dolibarr in the constant of the
    module): the new tables, columns and rights are not there yet. A bar at the top of the application and a
    warning on the setup page; the list of the modules for the administrators of Dolibarr, « ask an administrator
    of Dolibarr » for the other administrators of inbox, nothing for the users. Never activated again from inbox.

- FIX: Settings > Anti-spam in tabs (blocked senders, mailboxes, settings): the list of the blocked senders alone in
  its tab, by pages of 50 with the search (address, domain, note, author) and the type done by the server :   1.85.1

- NEW: blocked senders, one list for all the mailboxes, and a page Settings > Anti-spam :   1.85.0
  - « Block the sender… » in the menu ⋯ of a mail: the address, or the whole domain (and its subdomains); its mails
    go to the junk of every mailbox, never to the trash. The mails already received go too, in the mailboxes where
    the user has the level « manage ».
  - Who may block is worked out by the server: an unknown sender by the level « manage » on the mailbox of the mail;
    a sender known to Dolibarr (thirdparty or contact, prospects and inactive ones too, domain of a thirdparty, an
    address we wrote to, the sender of a mail linked to a piece) and a domain by the new right « Manage the blocked
    senders », a known sender after the address typed again; never a user of Dolibarr, our mailboxes and their
    domains, a recognized domain, the sender of a data source, a public mail service.
  - Applied by a block of the Sieve script of each mailbox (scheduled job, before any rule and the out of office),
    never shown as a rule; a mailbox without Sieve or whose script was changed by another tool: the synchronisation
    puts the new mails of a blocked sender away (never a mail marked « not junk »).
  - The reading says that the sender is blocked (by whom, when, « Unblock »); « Not junk » on such a mail offers to
    unblock. A blocked sender is never « probably legitimate » in the junk.
  - Settings > Anti-spam: the list for every user of the webmail (unblock: its author or a manager), for the
    administrators the switches of the blocked senders and of « probably legitimate », the trusted receiving servers
    (moved from Security) and the state of the block on each mailbox. Journal: block, unblock, put away.

- FIX: « Junk » (« Not junk » in the junk folder) is a button of the reading header, between archive and delete,
  no longer only in the « ⋯ » menu :   1.84.1

- NEW: contact of the sender on the pieces created from a mail, files of the linked mails for the other modules :   1.84.0
  - « Create ▾ » a quote, an order or a ticket: the known contact of the sender is the default contact of the page of
    creation; a supplier order (no contact on its page) gets him as supplier contact for the follow-up once created.
    Only a contact of the thirdparty of the piece (not when the thirdparty is found by the domain only).
  - public API of InboxCreate: mailFiles() lists the files of the mails linked to a piece (mailboxes the user can
    read), attachFromMail() puts one in the documents of the piece (antivirus, link, journal), also used by the panel.

- FIX: a file of a mail put in the documents of a piece (and the .eml of a piece created from a mail) is indexed
  like a file uploaded on the piece: listed by the ECM, and the modules reading the files given to a piece start at
  once (trigger ECMFILES_CREATE) :   1.83.4

- FIX: the delete button of the reading header turns red on hover and keyboard focus, as a destructive action;
  in the trash (destroy for good) its icon is red at rest too :   1.83.3

- FIX: « Record the sending on » a piece ticked at first only when Dolibarr records this sending in the agenda by
  itself (Agenda > Automatic events, « sent by mail » of the type of the piece), for the linked and the inserted
  pieces; the event is made only when the box is ticked :   1.83.2

- FIX: sending recorded on a piece, the agenda keeps what was written only and no copy of the mail goes in the
  documents of the piece: no signature, no quoted or forwarded mail, the attachments listed by name and size; the
  mail stays in the mailbox, linked to the piece :   1.83.1

- NEW: insert a piece of Dolibarr in a writing :   1.83.0
  - « Insert a piece » in the bar of the writing: proposals, orders and invoices of the thirdparties of the
    recipients and of the pieces linked to the mail answered (« All thirdparties » to widen), searched by reference
    or thirdparty, the readable ones only.
  - for the piece chosen: a line that recalls it (reference, date, amounts), the table of its lines (a table of
    the editor, styles in the cells for the mail software, totals under it), its PDF attached (seen in Dolibarr
    before; a piece without PDF is said), the link to sign a proposal online and to pay an order or an invoice
    online, only when Dolibarr offers them. Written in the language of the thirdparty, as its PDF.
  - the piece joins « Record the sending on », ticked: the sending noted on it and the mail sent linked to it.

- NEW: the files of a writing open in the viewer, to check them before sending :   1.82.0
  - a click on the name of an attachment of a writing (file sent, document of a piece, attachment of a forward)
    opens it in the viewer of inbox, the writing seen behind: the very file that leaves with the mail, read in the
    folder of the writing (not a copy of the draft). Same answers and protections as the attachments of a mail
    (antivirus, CSP, an infected file never opened), shared code (ajax/inbox_attserve.inc.php); only the author of
    the writing, in its session.

- FIX: panel « New customer », nothing filled in silence :   1.81.1
  - « More fields » folded shows every value it holds on its line; the categories, the country, the sales
    representatives and the VAT are never taken again from a former creation (only the nature and the type are).
  - sales representatives: nobody by default (on a shared mailbox the user who clicks is not the one of the
    customer); the person the mail is assigned to, else the owner of a personal mailbox, offered and said; a list
    with search, several possible, always in sight; sent to the full page too (nobody included).
  - nature by the usage of the mailbox (purchase, transport: supplier; sales, support: prospect) after the default
    values of the page of creation; subject to VAT ticked as in Dolibarr, in sight when « Supplier » is ticked, sent
    to the full page ticked or not.

- NEW: categories as Dolibarr shows them, in the tool combo and the panel « New customer » :   1.81.0
  - combo: choices as chips in the field (data-combo-chips, « × » on each, Backspace removes the last one), colour of
    an option (data-color: a dot in the list, the colour of its chip, text black or white by contrast), tree
    (data-depth, data-path: indented, searched on the whole path, the path in the title of the chip).
  - panel: customer categories in the order of their tree with their colours; supplier categories in their own
    field when « Supplier » is ticked; each set only on the nature it belongs to.

- NEW: searchable lists, tool « combo » of the JavaScript context, written for the core :   1.80.0
  - js/dolibarr-combo.js and css/dolibarr-combo.css, no dependency (no jQuery, nothing of inbox): Dolibarr.tools.combo
    is defined only when the core has none. Opt-in: the selects marked data-combo, or attach(). Search with case
    and accents ignored, single or multiple, groups, a second line per option, clear button, options asked to the
    server as one types (function or URL), keyboard and ARIA combobox; the native list kept on a phone for a single
    choice.
  - the native select stays the truth: visually hidden (a required field is still checked by the browser), in sync,
    a native « change » on each choice; a value set by the code or options loaded later show on the button; a focus
    on the select goes to the button; the classes the code looks for (js-) are never given to the button.
  - in inbox: country, type and categories of the panel « New customer », folder, tag and mailbox of the rules,
    user of the journal, folders per role, carriers, language of the AI. Colours from the theme of inbox.

- FIX: panel « New customer », signature found and read better :   1.79.3
  - the HTML of the mail is read (a tool of signatures may leave « Sent from ... as Plain Text » in the text part);
    the signature found after « -- », else from the line of the name of the sender, else after a closing formula,
    never in the footers (confidentiality, environment, « Sent from »).
  - short labels « T : » (phone) and « M : » (mobile) per piece of a line; the site of a line holding an email; a
    line of address in pieces (« street - town CP 11609 - country »): zip code after its label, town, country of the
    dictionary; zip code or town from the dictionary of zip codes when it gives one answer; phones of other
    countries keep their grouping.
  - « Label: value » lines kept only when the mail has two known labels at least (a form), never one « Address: »
    of a mail written by hand.

- FIX: panel « New customer », values cleaned before they are offered and when they are received: no target of a
  link (`Tel: + 33 199 00 12 34<tel:...>` of a HTML mail), phones written plainly (+33 1 99 00 12 34), emails
  checked, site without its scheme, identifiers without spaces; a street without its zip code and the company named
  like the domain read in the signature :   1.79.2

- FIX: a mail whose body part says « Content-Disposition: inline » was shown empty, its body listed as a file
  « piece-jointe » (the IMAP library takes any part with a disposition for an attachment) :   1.79.1

- NEW: new customer from a mail (level 1) :   1.79.0
  - on a mail of an unknown sender, « New customer » in the Dolibarr band opens a side panel on the left, the mail
    left readable on the right. Values read in the mail, each with its source and its line of the mail lit on hover,
    the other values offered: data source headers, attached vCard, display name (« Swap first and last name »),
    signature (phones sorted mobile or not, SIRET / SIREN checked by their key, VAT computed, address, job, site;
    never the quoted mail), « Label: value » lines in 5 languages, domain (never a public, disposable or shared one).
  - thirdparties that may be the same one (domain, phone, SIREN, VAT, near name) with « Use this one » (the contact
    is added to it).
  - « Create »: the thirdparty (nature, form, type, automatic code, coordinates, identifiers; sales representative,
    categories, VAT under « More fields »), its contact, the mail and its conversation linked to both, all or
    nothing; « Undo » in the toast. « Full page… »: the page of Dolibarr filled, the contact created when the
    thirdparty is saved; a contact that needs its own page is offered in the band. No copy of the mail is kept.
  - the panel asks what the page of creation asks (rules of the core, mandatory settings, mandatory extrafields,
    mandatory fields per page of Setup > Default values), checked again on the server; a field it cannot fill sends
    to the full page. The default values of the forms are its first values, the last choices are kept.
  - modules: hook inboxLeadExtract (values and fields) and the browser event inbox:lead:render (a block of the
    module in the panel), see README.

- NEW: remote pictures of a quote and security settings :   1.78.0
  - the quote of an answer or a forward no longer carries the tracking pictures of the mail quoted (1 x 1 pictures,
    known tracking addresses): kept, the reader of the answer told the first sender that it was answered, and the
    copy in the Sent folder told it again to whoever showed its pictures. « 2 trackers taken out of the quote » is
    said under it. The preview of the quote in the writing already blocked every remote picture.
  - Settings > Security, new page (the trusted authentication servers and the size scanned by the
    antivirus move there): remote pictures of the quote (keep, take out of the answers, of the answers and forwards;
    a picture taken out leaves its alternative text), « always show the pictures » (for a sender or a domain, a
    sender only, never), unsubscribe (one click or by mail, by mail only, off), alert on bank details (on by
    default, a warning says what is lost when off). Each choice says what it protects from.

- NEW: fil of a mail, the answers sent beside the internal notes :   1.77.0
  - the block of the notes in the panel becomes a fil by date: the notes and the mails sent from the mailbox in the
    conversation (tied by In-Reply-To, References or the conversation, never by the subject): « Paul answered »
    when inbox sent it (journal of the mailbox), « Answer sent from the mailbox (other software) » otherwise; « See »
    opens it. Forwards are left out: nothing ties them to their mail.
  - before sending an answer: when a colleague answered the same mail since the writing was opened (queue of inbox,
    even still cancellable, or Sent folder read again), nothing is sent and the writing says « Paul answered this mail
    at 10:42 » with « See the answer » and « Send anyway ».

- NEW: shared domains, public mail services and disposable addresses :   1.76.0
  - list shipped in data/domains/ (public.txt: 162 public mail services and access providers kept by hand;
    disposable.txt: 10 181 domains copied from a CC0 list on 2026-11-10, refreshed before a version by
    dev/update-domains.php), read in its files (a domain looked for as a line of the text, the text kept for the
    process), never copied in the database nor downloaded by inbox; the database keeps only the domains added and the
    shipped ones disabled. The old hidden setting INBOX_PUBLIC_DOMAINS becomes local entries.
  - a public or disposable domain never designates a thirdparty (subdomains too: mail.acme.com is acme.com, by the
    public suffixes); a domain shared by several thirdparties, or marked shared, offers them in the panel (« This
    one », never linked alone); a disposable address is said in the panel and before a thirdparty is made from it.
  - Settings > Public and shared domains (right « Manage the mailboxes »): search, filters by category and origin (shipped, added,
    disabled, other module), thirdparties and mails using each domain, add a list, change the category of an added
    one, disable a shipped one, restore the shipped list, all at once with undo; « Why? » explains each category;
    cards on the phone.
  - from a mail whose thirdparty was found by its domain: « Public mail service? » / « Several companies use it? »:
    marked at once for a manager of the settings (undo), a request in the bell of the managers for the others.
  - « Recognize the sender by the domain of his address » (Settings > Public and shared domains, on by default): off, the exact address
    only, for a big database whose searches by domain are slow; the log of Dolibarr says their duration when it
    passes 200 ms.
  - hook inboxSharedDomains: the other modules give their domains (origin « other module »). README.

- NEW: bank details in a mail, a lesson rather than a verdict :   1.75.0
  - a mail that talks of bank details (an IBAN with a right key, words of a change in 5 languages, an attachment
    named RIB / IBAN) gets a banner above its body: red when the sender is not authenticated or imitates a known one,
    orange otherwise, never green. An IBAN of the card at the foot of an invoice says nothing.
  - « Call with the numbers of your Dolibarr card »: the phones of the contact and of the thirdparty (click to call),
    never the ones of the mail; without a card, where to find a safe number.
  - what inbox noticed, each signal said as a doubt and not a proof: authentication (which proves the domain only),
    imitation, IBAN of the mail against the ones of the card (shown masked), a number of the mail not on the cards,
    first mail of the address, answer going elsewhere, pressure (urgency, secrecy); « even when everything is green »
    a mailbox whose password was stolen sends real authenticated mails.
  - « Why so much caution? » and five steps; « I checked by phone » writes the number called and the person reached
    as an internal note of the mail.

- NEW: form of a data source guided, for an admin who does not know the headers :   1.74.0
  - « From a mail of the site »: the source of a mail pasted, or « Make a data source from this mail » in the menu
    of a mail (admins of Dolibarr only): its sender, whether it is authenticated, its own headers (X-...) with a role
    guessed (address of the requester, data in JSON, one field, ignore) and the keys of its JSON with their value and
    field guessed; « Use these settings » fills the form, saved only by its button.
  - « Start from »: advised format X-Data-Form or quote request X-Quotation.
  - headers per field and keys of the JSON as lines with a list of the fields by their name (a usual key may be
    ignored on purpose); « ? » beside each setting opens its entry in the glossary: header, trusted sender,
    authenticated, data (JSON), kind of request, and each field with what it fills in Dolibarr, an example and the key
    names understood without setting.

- NEW: the link follows the conversation :   1.73.0
  - linking a mail to a piece offers « Link also the N other mails of the conversation », ticked by default (the
    cited pieces linked in one click too); the mails of the trash and the junk are left out.
  - the mails that come in a linked conversation (answers sent included) are linked at the synchronisation; these
    links say « linked by the conversation » under the piece.
  - « Detach from the conversation » when it moves to another customer: the mail loses the links of the conversation,
    gets no new one and gives none, kept by its Message-ID; undo in the toast, « Back in the conversation » later.

- NEW: mails sent by a site or an application for a requester (data sources) :   1.72.0
  - Settings > Data sources (admins of Dolibarr): trusted senders (addresses or domains), header of the address of
    the requester, header of the data in JSON, one header per field, keys of the JSON mapped to the fields, kind of
    request (quote, order, thirdparty, ticket); test on the pasted headers of a mail. An example source
    X-Quotation-Mail / X-Quotation-Data is installed disabled, without trusted sender.
  - believed only for a trusted sender that is authenticated (DMARC or DKIM of its domain), each header once, JSON of
    2000 characters at most, values cleaned; the synchronisation asks these headers only when a source is active,
    the reading reads them again (mails indexed before the source).
  - the thirdparty is looked for on the requester (never the site again, even when its contacts change); the list
    shows the requester with the badge of the source (the site in its tooltip); the panel shows the requester, his
    data, the number of the request and « Create the quote » (or order, thirdparty, ticket); the creation pages are
    filled with his data (name, phone, SIREN, VAT, address); « Reply » writes to the requester.

- FIX: panel of the rules, nothing opened any more after a change :   1.71.1
  - after a rule saved from the panel (created, applied, turned on or off, moved), « New rule » and a click on a rule
    opened nothing: the reply of the save had no folders, tags nor addresses of the mailbox. Every reply that gives
    the state of the rules (get, save, undo) has them now.

- NEW: new logo, bolder and readable at small size :   1.71.0
  - the messenger bird on the envelope redrawn in solid shapes (the thin line vanished under 32 px), centred on the
    envelope; on the plum background of the application icons the wing is translucent white.
  - icon of the tab drawn on a 16 px grid (envelope, wing and head); dev/build-icons.php keeps the wing and also
    builds the pictos img/inbox.png and img/object_inbox.png from img/inbox.svg.

- NEW: tracking numbers of the shipments, carriers of a mailbox Transport :   1.70.0
  - a tracking number of one of our shipments written in the mail: the shipment offered in « Cited in the mail »
    with its customer and « tracking no. ... », whoever writes (the carrier about a parcel of a customer).
  - usage Transport: category of the suppliers that are carriers (Settings > Mailboxes > Usage, written in the
    journal); a carrier has no situation on its card, a note says where the shipment is.

- NEW: phones of the signature compared with the card of the contact :   1.69.0
  - the numbers written in the signature (text before the quoted mails; never a reference, a date nor a fax) that the
    cards of the contact and of the thirdparty do not have: « Other phone in the signature » on the card of the
    contact, the number in its ways to reach (call, copy).
  - pencil « Replace the number of the card by this one »: professional or mobile number (mobile chosen for a mobile
    number), the number of the card shown before; written through the class of Dolibarr (its triggers), right to
    change the contacts; undo in the toast.

- NEW: own list of indicators per mailbox, indicators of the other modules :   1.68.0
  - Settings > Mailboxes > Usage: the indicators of a usage with a list may be added (search), removed and moved
    (drag and drop, arrows up and down on the grip), saved at once with undo, written in the journal of the mailbox;
    « Back to the list » when the mailbox has its own. A new usage starts again from its own list.
  - hook inboxSituationIndicators (context inbox): the other modules add indicators (catalogue: label, rule, usages
    where they are by default; compute: value, level, short text, address inside Dolibarr, their rights). README.

- NEW: usage of a mailbox and card of the thirdparty of the sender :   1.67.0
  - Settings > Mailboxes > tab « Usage » (managers of the mailbox): Automatic (by default: Sales for a customer,
    Purchasing for a supplier), Sales, Accounting, Support, Purchasing, Transport, None; saved at once, undo, written in
    the journal of the mailbox.
  - the thirdparty and the contact of the sender become cards: the name is the link to the card of Dolibarr, the rest
    of the head unfolds the detail over the panel (one block, nothing moves below); closed, one line of signal at most
    (the most serious, « +N »), only when something is not normal.
  - detail: ways to reach (the phone calls, the email writes, the address opens a map; copy next to the value on
    hover), situation of the thirdparty by the usage (12 indicators: late invoices, outstanding over the limit, next due
    date, open proposals, orders in progress, tickets, interventions, supplier orders, supplier invoices to pay, orders
    to ship, shipments and receptions late), each one shown only with its right and module, opening its list.
  - « Not the right thirdparty? » (and « Attach to a thirdparty… » for an unknown sender): the conversation attached by
    hand to another thirdparty, kept when the contacts change; « Remember for the next mails of this address » first
    in the recognition (table llx_inbox_address_soc); undo, journal.

- NEW: keyword $labelN of Thunderbird read as the tag of the key N when no tag of the list has it :   1.66.0
  - a tag of Thunderbird with the name of a tag of the list but the keyword $labelN (e.g. $label8 for the tag of
    the key 8, keyword demande_fournisseur_ok) is shown as this tag, found by its filter, and removed with it (both
    keywords removed on the server). One badge when a mail has both.
  - list of each mailbox (own or global): its active tags in order. Help of the settings: changing the order of
    the list changes the tag of these mails.

- NEW: tags of Thunderbird with another keyword than the tag of the same name, seen and aligned :   1.65.0
  - a keyword set by another software and absent from the list of the tags is shown on the mail as a dashed badge
    with the raw keyword (it was hidden: a tag created in Thunderbird gets a keyword made from its name, the mail
    looked untagged). System keywords ($Forwarded, $MDNSent, Junk...) stay hidden.
  - settings of the tags: column "Keyword", and a part "Same tags in Thunderbird" with the lines user_pref of the
    active tags (keyword, name, colour, order) to paste in user.js or the Config Editor, copied by a button.

- FIX: assignment, the user saw a grey circle instead of his own photo :   1.64.1
  - his own avatar (marked "me", in the colour of the application) reset the size and the position of the photo:
    only the top left corner of the image was seen. The colour of "me" is now kept for the initials only.

- NEW: content of the cards of the Dolibarr objects drawn like in Dolibarr :   1.64.0
  - css/inbox-doltip.css: the classes of Dolibarr met in its tooltips (logo, status badges, customer / supplier,
    categories in their colour, icons aligned), drawn with the variables of the application (light, dark, contrast).
  - the icons of Dolibarr (Font Awesome of its directory) shown again; this file and Font Awesome loaded at the first
    card only.

- NEW: the other modules add their styles and scripts to the application :   1.63.0
  - module_parts `inboxcss` and `inboxjs` in their descriptor, loaded after the styles and the scripts of the
    application (like the parts css and js of Dolibarr, own keys: those are written for the pages of Dolibarr).
  - hook `addInboxHtmlHeader` (context inbox) for a dynamic header, printed before `</head>`; own name so that the
    modules on addHtmlHeader never enter the application.
  - README « Extend the application »: example, rules, the variables of the application as public API.

- NEW: card of a Dolibarr object on its link, widgets of Dolibarr started in the application :   1.62.0
  - hover (or keyboard focus) on the ref of a piece: the tooltip of Dolibarr (its content and what the other modules
    add) in a light card of the application; asked to core/ajax/ajaxtooltip.php with the parameters given by
    getNomUrl, or given whole when the ajax tooltip is off; once per object and page. Works without the JS of the
    footer of Dolibarr nor its icons (not loaded by the application, hidden in the card).
  - Dolibarr.initNewContent called on the zones the application draws (list, reading, conversation, Dolibarr panel,
    user card, dialogs, compose window, Settings pages) when Dolibarr gives it.

- FIX: the cards of the Dolibarr column on the right were wider than it (status and « Link » cut) :   1.61.1
  - its rows could not be narrower than their content.

- NEW: cards of the pieces in the Dolibarr column on the right :   1.61.0
  - ref and status on the first line, the status never cut (the ref is shortened), the thirdparty on a second line;
    the card no more wider than the column.
  - the thirdparty is left out when it is the one of the sender (the title of a project or ticket stays): a piece of
    another thirdparty stands out; the full label stays in the title of the card.
  - cited pieces: « their ref » joins the thirdparty on the second line, « Link » at its end (never a line for the
    button alone); a card is two lines at most.

- FIX: the users of Dolibarr named in the head of a mail lost their photo and card :   1.60.2
  - the people assigned to the mail replaced them in the answer of the reading; they now have their own key.

- FIX: the people assigned showed their initials, never the photo of their Dolibarr card :   1.60.1
  - list, line « Assigned to », choice of the people and « is reading this mail » show the photo of the user card,
    the initials when there is none.
  - picture of a sender in the list: the photo of the user of the address comes before the photo of a contact with
    the same address, as in the head of the reading.

- NEW: lookalikes of our own domain :   1.60.0
  - the domains of the mailboxes are references of the lookalikes too, beside the recognized domains: a sender
    imitating our domain gets a banner (red, orange when its name only contains ours), a link a warning in the bar of
    the links and before opening it.
  - never an open service (a mailbox on gmail.com) nor a name shorter than 5 characters; a domain of ours elsewhere
    (other extension) is recognized in Settings > Recognized domains and is no more a lookalike, the banner says so.

- FIX: « Recognize this domain… » offered the social networks of a signature, and the sender left out silently :   1.59.1
  - social networks and public platforms (facebook.com, linkedin.com, x.com, youtube.com, google.com...) are open
    services: refused, never offered.
  - the dialog says why the sender is not offered: domain of your mailboxes, open service, or mail not authenticated.

- NEW: recognized domains and lookalike domains :   1.59.0
  - Settings > Recognized domains (new right « Manage the recognized domains »): a short list of domains of
    organisations with their name and the source of the check; public suffixes, free mail, emailing, hosting and
    short link services refused (Public Suffix List embedded, dev/build-publicsuffix.php), and the own domains.
  - an authenticated sender of a recognized domain (DMARC, or DKIM of its domain) gets a badge with the name of the
    organisation, its details on click (answers to another domain said); a recognized domain whose authentication
    failed gets a red banner; nothing is said when the mail cannot be verified.
  - lookalikes of the recognized domains (same name with another extension, confusable letters, typing mistake,
    and for the sender a name that contains the recognized one): banner on the sender, warning before opening a link.
  - links: a bar of inbox outside the mail shows the real address under the pointer, with the organisation of a
    recognized domain or the warning of a lookalike (long press on a phone); replaces the title of each link. The
    misleading link warning is not shown when the text and the link belong to the same recognized organisation.
  - « Recognize this domain… » in the menu of a mail: the domain of the authenticated sender checked, the domains of
    the links offered unchecked, a lookalike never checked; nothing written before « Recognize ».
  - matching is exact (the domain or its subdomains), never « contains »; verdicts computed by the server.

- NEW: a page left open while inbox is updated offers to reload :   1.58.0
  - every ajax answer gives the version of inbox on the server (header X-Inbox-Version); when it differs from the one
    of the page, a bar « Inbox was updated: Reload » shows once (closable, never shown again on that page).
  - never reloaded by itself: a mail being written stays, the writing asks before leaving when it is not saved.

- NEW: tags at hand in the mail read, and their colour in the menu of the tags :   1.57.0
  - button « Tag » in the bar of the mail (between the star and Archive), its menu under it.
  - the tags beside the subject open the same menu (checked ones removed in one click), with a « + » to add one;
    in the head of a conversation too.
  - in the menu, a dot of the colour of each tag with its border style before its name: the tag to put is seen at
    once (the name stays written, the colour is never the only sign).

- NEW: « Annotate and file in a piece… » for the attachments, with the editors of other modules (C2) :   1.56.0
  - in the menu of an attachment when a module declares an editor for its type (hook inboxAttachmentViewers, results
    inboxAttachmentEditors): antivirus first, then the piece is chosen like « Put in the documents of », then the
    editor opens in a new tab on the attachment, read only
  - its saving puts a copy in the documents of the piece by inbox (InboxAttachmentFile::fileToRecord: write rights on
    the piece, never over an existing file, piece linked to the mail, journal of the mailbox); the mail stays as it is

- NEW: « Open with… » for the attachments, with the viewers of other modules (C1) :   1.55.0
  - hook inboxAttachmentViewers: a module says what it can open (office server, Office preview, PDF tools...), inbox
    shows it in the menu of the attachment and in its own viewer window; inbox never knows the name of the module.
  - an attachment inbox does not show itself (Word, Excel, PowerPoint, OpenDocument...) opens in the first viewer of
    a module instead of « No preview », in a window of the size of a document.
  - read-only access for these modules (class InboxAttachmentFile): rights of the user on the mailbox, antivirus first
    (an infected attachment is never given, a not scanned one only after « Open anyway »), nothing written; only
    addresses of this site are opened.

- FIX: button « N other(s) » of a conversation on one line in the foot of a card :   1.54.1
  - it shrank with the piece and the mailbox beside it and its text went on two lines: it keeps its width, the
    names of the piece and of the mailbox give way.

- NEW: Settings > Server in the application, with the scheduled jobs of the module :   1.54.0
  - a page of its own beside General, for the admins of Dolibarr: keeping of the cache and of the journals, timeouts
    of the mail servers, master user of the mail server (password encrypted, never shown again), each number saved
    when the field is left.
  - the scheduled jobs of inbox: on or off at once, frequency, last run (its error when it failed) and next one, a
    link to their card; said first: the module of the jobs off, a needed job off (synchronisation, sendings), jobs
    late (the scheduler of Dolibarr probably not started), last run failed.
  - hook inboxCronJobUrl (context inbox): another interface gives its own address of the card of a job.
  - the tab Settings of the setup page of Dolibarr points there (the cache and the tests of reading stay).
  - label of the synchronisation job repaired in the five languages (its end had gone into its comment).
  - setup page of Dolibarr: while DocStudio is not enabled, a link to its last release on GitHub (viewing and
    editing of the attachments).

- FIX: signature seen whole in the writing, right under the text, without « -- » in the HTML :   1.53.5
  - the signature (and the quote when unfolded) took a frame of a fixed height with its own scroll: its frame takes
    the height of its content (pictures included), the writing scrolls as a whole; same origin without scripts, as
    the reading, nothing of the content runs.
  - the text no longer stretches to the bottom of the window: the signature comes right under it, a click in the
    space below puts the cursor at the end of the text; the cross still removes the signature of this mail.
  - the delimiter « -- » is no longer written in the HTML part (nor in the writing): the text part keeps it,
    « -- » and its space alone on a line (RFC 3676), as the mail software read it.

- FIX: « Server error (500) » after a mail sent, though it left :   1.53.4
  - closing the writing after the sending removed its folder with a function of Dolibarr not loaded (files.lib):
    the mail was sent, the closing failed. Loaded by the writing and by the queue of the sendings (its cleaning
    after a delayed or scheduled sending, by the scheduled job too).

- FIX: variables picked from a button with a search, the value each one gives shown :   1.53.3
  - the long « Insert a variable » list of the signature and of the templates becomes a button of the toolbar: a
    search on the name and on the value, the keys grouped (mail and identity, user, company, third party, others).
  - in a signature, each key shows what it gives for the user and the identity typed, « (empty) » said; the value
    of a key that looks like a secret (token, key, password, secret) is never sent to the browser.

- FIX: activation stopped on the real cause when the tables cannot be created :   1.53.3
  - the activation went on after a failed SQL file and stopped on the first missing table (« llx_inbox_tag does
    not exist »), hiding the SQL error: it now stops at once and shows it.
  - module installed in a folder not named « inbox » (git clone gives dolibarr_inbox): no table was created, said
    clearly with the folder to rename.

- FIX: conversation laid out as a mail alone when the column Dolibarr is on the right :   1.53.2
  - the column took the height of its content and the answer bar (Reply, Reply all, Forward) sat under the last
    mail: head on top, the column on the whole height, the answer bar at the bottom, only the mails scroll.
  - the column above the mails keeps the scroll of the whole pane.

- FIX: conversations no longer joined by the subject alone when the only common address is the mailbox :   1.53.1
  - the fallback on the subject (same subject, same correspondent, 30 days) took the mailbox itself for the
    correspondent: a mail sent matched every mail received with the same subject (all written to the mailbox), and
    a mail written to oneself (« test ») joined, even merged, unrelated conversations.
  - the correspondent is now the other side: the sender of a mail received, the recipients of a mail sent, never
    an address of the mailbox (account, identities); a mail to oneself never joins by the subject.
  - conversations already joined stay as they are.

- NEW: unsubscribing by mail, when the newsletter offers no one-click :   1.53.0
  - « Unsubscribe » prepares a writing to the address of List-Unsubscribe (mailto), with the subject and text the
    list asks for, from the address that got the newsletter, without signature: the user checks it and sends it.
  - same rules as the one-click: level « manage » on the mailbox, header signed by the sender (DKIM), said before
    that the sender will know the address is read; never a cc/bcc of the mailto, subject on one line.
  - logged as « Unsubscribed » in the journal of the mailbox once sent; the newsletter is not marked answered.
  - the address is copied only when inbox cannot write the mail (no right to send, unsigned header).

- NEW: variables in the signatures (identity, user who sends, company, other modules) :   1.52.0
  - the signature of an identity is filled in the writing and at the sending: `__INBOX_IDENTITY_NAME__`,
    `__INBOX_IDENTITY_EMAIL__`, `__INBOX_IDENTITY_REPLYTO__`, `__INBOX_MAILBOX_EMAIL__`, `__INBOX_MAILBOX_LABEL__` (the old
    `__INBOX_IDENTITY__` and `__INBOX_MAILBOX__` kept), those of the user who sends, of the company and of the other
    modules (a signature kept in a module of mail templates); a variable holding variables filled too.
  - the identity is known before the other modules complete the variables: a substitution of another module holding
    `__INBOX_IDENTITY_NAME__` gets it, in the templates too; `__SENDEREMAIL_SIGNATURE__` of a template comes filled.
  - values of the identity always escaped (a name `R&D <Sales>` stays text); an unknown variable kept as it is.
  - two variables side by side read as two (« `__A____B__` » was one unknown key), in the templates too.
  - dialog of an identity: « Insert a variable » (identity first) and « Preview » of the signature as it will be
    sent, naming the variables that nothing replaces.

- FIX: newsletter banner readable when the sender only offers unsubscribing by mail :   1.51.1
  - the text was squeezed into a one-word column by the copy button holding the whole address: the buttons go
    under the text when space is short (every reading banner), the address is shown on its own line.
  - no more greyed « Unsubscribe » button that could do nothing, the banner says it is by mail only and offers
    « Copy the unsubscribe address ».

- NEW: signature edited formatted, inline styles kept by the editor, signature imported from an HTML file :   1.51.0
  - the signature of an identity is edited with the editor of the writing (bold, italic, underline, colour, link,
    picture by its web address, undo), its HTML source one button away.
  - « Import an HTML file » next to « Import from Dolibarr »: the body of the file only, scripts and style sheets
    left out; the pictures with a local address are named (they would not show in the mails).
  - the editor keeps the inline styles that no button makes (size, font, line height, colour and decoration of a
    link, width of a table) in signatures, templates and writings: a signature copied from Dolibarr is drawn as
    before, checked on the signatures and mail templates of a real database.
  - text outside any block kept in `<div>` instead of `<p>` (no margins added), an empty line « `<br>&nbsp;` » kept,
    border/cellpadding/cellspacing/valign of the old tables kept, a cell width in px kept and written for the mail
    software, the font of a div holding blocks given to them (a text pasted from Outlook or Word).

- FIX: toasts and sending bars can be closed :   1.50.1
  - a cross closes every toast, and the bar of a sent, failed or scheduled mail (not the countdown before sending).
  - the sending bar takes the font of the inbox (it showed in the browser serif font) and is stacked under the
    toasts instead of hiding them.
  - the page of the inbox gets its font on the body: no element added to the page in the font of the browser
    (Times New Roman under Windows).

- NEW: mailboxes in the panel chosen from the menu of the folders panel :   1.50.0
  - the « ⋯ » menu of the panel offers the choice of My account: automatic (by screen size), all the mailboxes,
    one mailbox at a time; the panel is drawn again at once, the choice kept for the user.

- NEW: a mailbox clicked opens its Inbox, a folded mailbox stands for its Inbox :   1.49.0
  - only the arrow of a mailbox folds or unfolds it; a click on its name opens its Inbox without unfolding it.
  - folded, its row shows the unread of its Inbox, « +N » for the unread of its other folders (Sent, Drafts,
    Junk and Trash left out), and is selected while its Inbox is open.
  - a folder of a folded mailbox opened (search, link, notification) no longer unfolds it: shown alone under the
    mailbox until another folder or view is opened.
  - mailboxes reached with the arrow keys of the tree, Enter opens the Inbox.

- FIX: window of a module opened in the middle of the screen, a closed window no longer kept at 0, 0 :   1.48.1
  - closing or reducing a window gave it no size, kept as its last place: the next one opened in the top left
    corner (empty size thrown away); the window of a module keeps its size and opens centered.

- NEW: windows of inbox with a neutral title bar, sized from every edge, a module window able to open another over it :   1.48.0
  - title bar white with framed buttons (the cross red on hover); the window in front told by its shadow, the
    others by their title in grey.
  - every window (viewer, page of a module) sized from its 4 edges and 4 corners, size kept as before.
  - a page of a module may open one window over it, modal: a dark backdrop puts what is under it out of reach;
    the two pages talk through inbox (« relay »), the page may set the title of its window.
  - a page of a module may say it holds work not saved (« dirty »): its cross and Escape then ask before closing.

- FIX: what the other modules give to the Dolibarr panel of a mail shown again (blocks, links, actions, alerts) :   1.47.1
  - the hooks inboxMailPanels, inboxMailLinks, inboxMailActions and inboxMailAlerts were read from
    $hookmanager->results instead of $hookmanager->resArray, where Dolibarr puts them: nothing was ever shown.

- NEW: blocks of other modules in the Dolibarr panel of a mail, their page in a window of inbox :   1.47.0
  - hook inboxMailPanels: a module declares a block (title, icon, address of its JSON summary inside Dolibarr);
    the summary is read after the panel (badges, lines, one action, links), again while the module is working.
  - the action opens the page of the module in a floating window (moved, maximized, reduced, size kept); the page
    tells inbox when it is done (postMessage, same origin): inbox links the piece, sets or removes tags and
    archives the mail with its own rights, then refreshes the panel.

- NEW: linking a mail to a piece: status badge, faster search, pieces cited in the mail :   1.46.0
  - status of the pieces as the badge of Dolibarr (short label, colour of its kind, long label in the title), in
    the panel and in « Link ».
  - « Link » search: references by their start (index), other fields « contain » from 3 characters, only the
    pieces kept read in full; exact reference first, then the thirdparty of the sender; running wheel, empty state
    and « Retry »; the events left out (12 s on a big agenda), « Extend to… » > Events, searched apart, after.
  - references cited in the subject, body and attachment names, found with the numbering models in use, checked
    against the thirdparty of the writer (its parent and subsidiaries; the original sender of an internal
    transfer): the pieces of the writer suggested in the panel with « Link », the ones of another thirdparty only
    in « Link », at the bottom, with a warning (its own numbering may match ours); « their ref. » kept on our pieces
    found too.

- NEW: viewer of the attachments bigger, Ctrl+click in a new tab, antivirus shown on the tiles :   1.45.0
  - a PDF or a picture opens in a large window (90 % of the screen height, full screen on a phone), the PDF at the
    width of the page; its size and place are kept apart from those of the other viewers.
  - Ctrl+click, middle click or menu « Open in a new tab » for a PDF or a picture, after the antivirus as for the
    preview; another kind of file is downloaded.
  - antivirus run when the mail opens: « Antivirus scan… » on each tile and in the head of the bar, « still
    running » after 5 s, « Retry » when it fails; a click meanwhile waits for the verdict.
  - virus: red border and hatching, never dragged; risky file: orange border and hatching, the download asked once
    more with the reasons; not scanned: dashed border; badges « 1 dangerous », « 2 to check » in the head.

- NEW: verdict of the anti-spam filter of the server shown on the mail :   1.44.0
  - SpamAssassin and Rspamd headers read (the topmost only, added by the server): banner « judged as junk » with
    the score, the threshold and up to 3 reasons, button to move the mail to the junk.
  - rules: « Anti-spam rule » prefilled to move into the junk the mails marked as spam by the server.

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
- NEW: gaps of the writing closed :   1.26.0
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
    INBOX_LINK_DELETE, INBOX_NOTE_CREATE, `_MODIFY`, `_DELETE`, hooks inboxMailLinks and inboxMailAlerts.
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
  - written in the journal of the actions; triggers INBOX_FOLDER_CREATE, `_MOVE`, `_DELETE`, `_SEEN`, `_EMPTY`.
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
  - reading by Message-ID on the mailboxes of inbox; other modules plug in through the hooks
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
  - line breaks of the text of the mail shown as « \n » and « `<br>` ».
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
