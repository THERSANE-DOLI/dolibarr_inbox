# Principles

The rules that hold for every change of Inbox. When a choice is unclear while coding, this page decides. They come
from the decisions taken while building the module; a rule changes only by an explicit decision of the maintainer,
written here.

## Product

| # | Principle |
|---|---|
| P1 | **A full webmail inside Dolibarr**, personal and shared mailboxes, with Dolibarr beside each mail (thirdparty, documents, agenda). |
| P2 | **Familiar layout of a desktop mail client**: rail and three panes, several mailboxes in the same tree, keyboard shortcuts, tags, conversation view, table or cards. |
| P3 | **Standalone module**, published on its own or merged into the core of Dolibarr one day: it depends on no other module (backportdolcontext on Dolibarr 23 only). What comes from elsewhere is ported into Inbox in a generic form. |
| P4 | **Dolibarr 23 minimum**, compatible with 24 and later. |
| P5 | **Extensible, never coupled**: other modules add actions, panels, connectors through hooks (PHP `inbox…`, JS `inbox:<object>:<action>`). Inbox never names, looks for or adapts to an **external module** (outside the core of Dolibarr): no module name, no « is module X enabled » test, no context shaped for one module; examples in the documentation never cite a real external module. The modules of the core of Dolibarr (Agenda, Third parties, Tickets...) are part of the platform: Inbox may read them and test them with `isModEnabled()`. |
| P5 bis | **No permission system against modules**: a module is PHP with every right on the database, permissions would not stop it; the protection is the decision to install it and the review of its code. Inbox never gives passwords through its interfaces, its hooks and events give only what is needed, the readings done for another module are written in a journal. A module that **acts automatically** on a mailbox goes through the [module subscriptions](consumers.md): switch of the instance, declaration by the module, authorization by a manager of the mailbox, journal of each action. |

## Data and servers

| # | Principle |
|---|---|
| P6 | **The mail server is the reference**; the database is only an index that can be rebuilt. No full copy of the mails. |
| P7 | **Connectors**: everything goes through the common interface and the declared capabilities; ids (`remote_id`) and cursors are opaque text; IMAP is one connector; MIME is the pivot format of the mail family. |
| P8 | **Read only by default**: reading with `BODY.PEEK`; no write on the server without an action of the user; every write = write, **check**, then update the index; a move between mailboxes removes the source only after the copy is checked. |
| P9 | **External modules plug into Inbox, never the reverse**: Inbox publishes triggers, hooks and public classes. |
| P9 bis | **A link survives any move**: links and notes of a mail are keyed by its `Message-ID`, never by mailbox, folder or UID; a mail not found keeps its links. |
| P10 | **Reactivation without side effect**: SQL can be replayed, no data lost nor doubled; disabling and enabling the module is always harmless. |

## Rights and security

| # | Principle |
|---|---|
| P11 | **Rights per mailbox** (read, send, handle, manage), per user or group; the owner has manage; **the administrator of Dolibarr has no implicit access**; external users never; disabled mailbox = nobody; refused = 404; changes of accesses written in the journal. |
| P12 | **Secrets**: passwords encrypted with `dolEncrypt`, never sent back to the browser, never in a log nor an error message. |
| P13 | **Checked on the server** at every call: CSRF token on every write, right of Dolibarr, level on the mailbox. |
| P14 | **Bodies of the mails isolated**: `sandbox` iframe without `allow-scripts` nor `allow-same-origin`, CSP without script, allow list, remote images blocked by default; attachments served by a script that checks the rights again. Every value of a mail goes through [one door](mail-data.md) (`InboxSafe`, `safeHtml()`). |
| P15 | **Antivirus of Dolibarr** before opening, downloading, attaching or forwarding an attachment; virus = blocked; antivirus missing or file too large = warn without blocking. |
| P16 | **Nothing leaves the server of Dolibarr** without a decision: no CDN, no outside service for the content of the mails. |
| P17 | **Security tests with every change** (XSS, crafted MIME, header and IMAP injections, rights, CSRF, antivirus, isolation), on a test database only, never a real one; a failure blocks the version. |
| P18 | **Sending**: `MAIN_DISABLE_ALL_MAILS` respected; sending tests only to a local catcher (Mailpit). |

## Interface

| # | Principle |
|---|---|
| P19 | **Full screen application** without the menus of Dolibarr; **everything in ajax**, no reload once opened; the address follows the screen (Back, bookmark, F5 open the same screen). |
| P20 | **Own styles**: the application does not load the theme of Dolibarr; prefixes `ibx-` / `--ibx-`; values only through variables; **catalogue first** (`ux.php`): a new style exists only with its section in the catalogue. |
| P21 | **Tabler icons** shipped in the module, by **usage name** (`ibx-i-…`), never the Tabler name in the code. |
| P22 | **One primary action per area**; destructive actions apart and **undoable** (toast « Undo ») rather than confirmed; definitive only with a confirmation that names the object. |
| P23 | **Nothing on hover only**; a toggle button shows its state (`aria-pressed`); a forbidden action is **greyed with its reason visible**. |
| P24 | **One definition of the actions** (registry) for the `⋯` menu, the right click, the action bars, the bulk bar and the keyboard. |
| P25 | **Menus**: the menu of Inbox on the objects of Inbox, the menu of the browser in the text; Shift + right click = menu of the browser everywhere. |
| P26 | **Hover**: a variant never sets `color` / `background` directly; it sets its colours at rest and on hover, the common `:hover` rule only switches. |
| P27 | **Dialogs**: native `<dialog>`; confirmations: Cancel on the left, main action on the right; never `confirm()` / `prompt()`. |
| P28 | **Accessibility from the start**: sizes in `rem`, zoom 90 to 150 %, density, light / dark / high contrast (and `forced-colors`), never information by colour alone, everything with the keyboard, ARIA roles, contrast AA. |
| P29 | **Complete states**: loading (skeleton), useful empty state, error that says what to do, partial, offline; an error never empties what the user typed. |
| P36 | **Phone and tablet with every change**: same application and address, no separate mobile site; layouts by thresholds in `rem`; touch targets ≥ 44 px; every gesture has its button; only one fixed bar, at the top. |

## Code

| # | Principle |
|---|---|
| P30 | **JavaScript context of Dolibarr**: values through context variables, translations through `Dolibarr.tools.langs`, `Dolibarr.initNewContent()` after an injection; Inbox is a tool (`Dolibarr.tools.inbox`); **never PHP inside JavaScript**; no jQuery. |
| P31 | **Languages**: en, fr, de, it, es for every key (checked against en_US); texts of the user in the language files, never hard coded. |
| P32 | **Writing**: comments in English; no em dash anywhere (code, languages, docs); an option cited to the user by its label in the setup. |
| P33 | **Versions**: X.Y.Z. Y for a new feature or a change that needs the module to be activated again (table, column, right, constant, menu); Z for a fix that only replaces files. One ChangeLog entry per version: a short summary ending with the version, then 1 to 3 short sub-items. |
| P34 | **Every change ships complete**: tests, translations, ChangeLog entry, its page in `doc/` (registered in `InboxDocumentation`), checked in a browser on a computer **and a phone**. |
| P35 | **Replaceable libraries**: every important third party brick (mailbox connector, text editor, search engine) goes through an **interface of Inbox** with adapters, declared capabilities and contract tests; the data kept are in a format of Inbox, never the internal format of the library; libraries shipped and pinned, no CDN. |
| P37 | **Settings pages of Dolibarr** use `FormSetup`; a page chosen by a request parameter is only looked up in a declared index (as `InboxDocumentation`), a file path is never built from the parameter. |
