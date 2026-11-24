# Instructions for coding agents (and their humans)

Inbox is a module of Dolibarr ERP & CRM (`htdocs/custom/inbox`): a full webmail inside Dolibarr and a mail
foundation for other modules. Dolibarr 23 minimum (with the module backportdolcontext), PHP 7.4+.

Read before changing anything:

- [doc/dev/principles.md](doc/dev/principles.md): the rules of every change. They win over your habits.
- [doc/dev/README.md](doc/dev/README.md): API, hooks, and the page of the part you touch.
- The code around your change: copy its conventions rather than inventing new ones.

## Hard rules

- **Inbox stays neutral towards the external modules** (the ones outside the core of Dolibarr): never name, look for
  or adapt to one of them (no module name, no « is module X enabled » test, no context shaped for one module). They
  plug in through hooks (PHP `inbox…`, context `inbox`; JS `inbox:<object>:<action>`). Examples in the docs never cite
  a real external module. The modules of the core of Dolibarr (Agenda, Third parties, Tickets, Antivirus...) are part
  of the platform: Inbox may read them and test them with `isModEnabled()`.
- **No trigger broadcast for the mails received**: a module that acts on mails goes through the module
  subscriptions ([doc/dev/consumers.md](doc/dev/consumers.md)).
- **Every value of a mail goes through one door** before it is shown or written: `InboxSafe` in PHP,
  `Dolibarr.tools.inbox.safeHtml()` in JS ([doc/dev/mail-data.md](doc/dev/mail-data.md)). No `innerHTML` of a value
  of a mail outside `safeHtml()`.
- **Rights checked on the server** at every call: CSRF token on every write (`inboxAjaxStart(true)`), right of
  Dolibarr, level on the mailbox (`InboxAccess`); a refused mailbox answers 404. The administrator of Dolibarr has no
  implicit access to the mailboxes.
- **Secrets** never sent to the browser, never in a log or an error message.
- **Nothing leaves the server** without a decision: no CDN, no outside service for the content of the mails.
- **Never PHP inside JavaScript**: values through the JS context of Dolibarr, translations through
  `Dolibarr.tools.langs`. No jQuery in the application.
- **HTML built in JavaScript: prefer `I.html`** (`Dolibarr.tools.inbox.html`), a tagged template that escapes every
  value: ``I.html`<b>${name}</b>${I.raw(ic('mail'))}` ``. HTML passes only through `I.raw()` (never on a value of a
  mail, a user or the server) or another `I.html`. New code uses it; the concatenations with `esc()` of the existing
  files are rewritten when a file is reworked, not all at once. Example: `js/inbox-consumers.js`.
- **No file path from a request parameter**: a page chosen by a parameter is looked up in a declared index
  (example: `class/inboxdocumentation.class.php`).
- **Settings pages of Dolibarr** (`admin/*.php`) use `FormSetup`, never a hand made table of settings.

## Conventions

- Comments and documentation in English. No em dash (the long dash) anywhere: code, language files, docs.
- Every user text in `langs/*/inbox.lang`, in the five languages en_US, fr_FR, de_DE, it_IT, es_ES (same keys as
  en_US). Never a customer or company name in user texts.
- Styles of the application: classes `ibx-*`, variables `--ibx-*`, a new component goes into the catalogue
  (`ux.php`). Icons by usage name (`ic('name')`), see `dev/icons.json`.
- CSS class names: prefer the BEM naming (`block__element--modifier`, states as `is-*`), as in the application
  (`ibx-picklist__item`, `ibx-badge--warning`, `is-current`). A preference, not an obligation: follow the code
  around you, and the classes of Dolibarr on its own pages.
- Database: `sql/llx_*.sql` + `.key.sql`. The `.key.sql` files run **before** the `update_*.sql` files: an index on a
  column added later goes into the update file. Every step of `init()` must be replayable (disable and enable the
  module is always harmless).
- Versions X.Y.Z in `core/modules/modInbox.class.php` and at the top of `ChangeLog.md`:
  - Y (and Z back to 0) for a new feature, or for a fix that needs the module to be activated again (table, column,
    right, constant, menu);
  - Z for a fix that only replaces files, one Z per ChangeLog entry.
  - Entry: `- NEW: short summary :   X.Y.Z` (or `- FIX:`), then 1 to 3 short sub-items, wrapped at about 110
    characters, newest on top.
- Commit messages: what changes, no internal codes of plans or audits.

## Before you open a pull request

- [ ] Tests on a **test database only** (the bootstrap refuses the others), see [doc/dev/development.md](doc/dev/development.md):
      `INBOX_DOLIBARR_HTDOCS=/path/htdocs DOLI_TEST_DB=<test database> phpunit -c test/phpunit/phpunit.xml`
      (the IMAP tests need the local test mail server `dev/dovecot/run.sh`). New behaviour = new tests.
- [ ] Coding style of Dolibarr on the PHP files changed:
      `phpcs --standard=<dolibarr>/dev/setup/codesniffer/ruleset.xml <files>`; `php -l` on each.
- [ ] `node --check` on each JavaScript file changed.
- [ ] A PHP file added in `dev/` or `test/` starts with the guard `if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }`
      (these folders stay in the Git branches): `php dev/check-cli-guard.php`, also run by the CI.
- [ ] Icons changed: `php dev/build-icons.php`.
- [ ] Language keys added in the five files.
- [ ] Version and ChangeLog entry as above.
- [ ] A new feature has its page in `doc/admin/` and/or `doc/dev/`, registered in `class/inboxdocumentation.class.php`
      (key, title and description in the five languages, version).
- [ ] Checked in a browser on a computer **and** a phone width; no error in the console.
- [ ] Visual change (`css/`, `js/`, `tpl/`, `img/`): screenshots pasted in the description of the
      PR, **before and after** for a FIX, one at least for a NEW. Checked by the CI
      (`.github/workflows/pr-screenshots.yml`); the label `no-screenshot` skips it when nothing visible changes.

Never run tests, scripts or synchronisations against a real database or a real mailbox.
