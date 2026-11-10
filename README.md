# Inbox for [Dolibarr ERP & CRM](https://www.dolibarr.org)

Mail foundation used by other modules (first one: quoteassist, customer price request prefill).

- `Inbox::getMessage($mailbox, $messageId, $user, $origin)`: one mail read in a mailbox of inbox (or given by another
  module through the hook `inboxResolveAccount`), from its complete Message-ID, read only, cached, journaled.
- `Inbox::fetchRaw(...)`: same, raw RFC 822 source (to attach it to a record).
- `Inbox::parseContent($content)` / `Inbox::getMessageFromFile($path)`: .eml or Outlook .msg given as a file.
- Result: `InboxMessage` (headers, text and HTML bodies, attachments, attached mails).

Cache: the raw mails read in the mailboxes are kept in `documents/inbox/raw/` (30 days by default, purged every night).
This directory can be emptied at any time: a missing mail is read again on the mail server when needed. It holds a
README.txt saying so.

Requirements: Dolibarr 23+, PHP 7.4+; on Dolibarr 23 the module backportdolcontext (JavaScript context of Dolibarr 24).

Other modules plug into inbox, never the reverse: hooks `inboxResolveAccount`, `inboxAccountImportSources`,
`inboxConnectors`, `addInboxHtmlHeader` (context `inbox`), the module_parts `inboxcss` and `inboxjs` (see « Extend the
application ») and the triggers of its objects.

Licence: GPLv3, see COPYING.

## Inbox application

`index.php` opens the application (right « Use the webmail »): Settings > Mailboxes (right « Manage the mailboxes »),
General, My account. The reading of the mails comes with the next lots.

## Extend the application

The page of the application has its own head: neither the theme of Dolibarr, nor jQuery, nor the parts `css` and
`js` of the modules (written for the pages of Dolibarr) are loaded there. A module adds its files the same way:

```php
// Descriptor of the module (deactivate and activate the module again after a change, as for any module_parts)
$this->module_parts = array(
	'inboxcss' => array('/mymodule/css/inbox.css'),	// after the styles of the application
	'inboxjs' => array('/mymodule/js/inbox.js'),	// after its scripts: Dolibarr.tools.inbox is ready
);
```

Dynamic header (CSS computed from a setting...): hook `addInboxHtmlHeader`, context `inbox`, printed before
`</head>` like `addHtmlHeader` of Dolibarr (`$this->resprints`, `getNonce()` on the `<style>` and `<script>`). Own
name on purpose: the modules on `addHtmlHeader` (often context `all`, code for jQuery) never enter the application.

Rules for the styles of a module:

- its own classes, prefixed by the name of the module; never restyle the `.ibx-*` classes of the application;
- the variables of the application, its public API: they follow the light, dark and high contrast themes, the accent
  colour and the density. Surfaces `--ibx-bg`, `--ibx-bg-pane`, `--ibx-bg-sunken`, `--ibx-bg-hover`; text
  `--ibx-text`, `--ibx-text-muted`, `--ibx-text-faint`; lines `--ibx-border`, `--ibx-border-strong`; accent
  `--ibx-accent`, `--ibx-accent-hover`, `--ibx-accent-soft`, `--ibx-accent-softer`; states `--ibx-success`,
  `--ibx-warning`, `--ibx-danger`, `--ibx-info` and their `-soft`; spaces `--ibx-s1` to `--ibx-s6`; text sizes
  `--ibx-fs-xs`, `-sm`, `-md`, `-body`, `-lg`, `-xl`; `--ibx-font`, `--ibx-font-mono`; corners `--ibx-radius-sm`,
  `--ibx-radius`, `--ibx-radius-lg`; `--ibx-shadow-float`.

## Development

- Icons: `dev/icons.json` (usage name => Tabler icon) and `dev/tabler/` (Tabler Icons 3.50.0, MIT license, see
  `dev/tabler/LICENSE`); `php dev/build-icons.php` builds `img/inbox-icons.svg` and the icons of the application.
  To choose new icons: put the npm package `@tabler/icons` of the same version (folders `icons/`, file `icons.json`)
  in `dev/bin/tabler-3.50.0` (ignored by git), run `php dev/build-icons.php --picker` and open
  `dev/bin/icon-picker.html`; the files of the icons added to `icons.json` are copied from the package at the build.
- Local test mail server: `dev/dovecot/run.sh` (Docker, IMAP 127.0.0.1:10143, Sieve 14190, SMTP submission 10587,
  test values only) then `python3 dev/dovecot/seed.py`.
- Tests: `INBOX_DOLIBARR_HTDOCS=/path/htdocs DOLI_TEST_DB=<test database> ~/bin/phpunit -c test/phpunit/phpunit.xml`,
  on a test database only (the bootstrap refuses the others). The HTTP tests need `INBOX_TEST_URL`,
  `INBOX_TEST_LOGIN`, `INBOX_TEST_PASSWORD` of a test instance.
- Catalogue of the components: `ux.php`.
- Copies of Dolibarr to keep in step at each major version of Dolibarr:
  - `css/inbox-doltip.css`: the classes of the theme eldy met in the tooltips of Dolibarr (`getTooltipContent`),
    redrawn with the variables of the application for the cards of the objects. A class renamed or added in the
    tooltips of Dolibarr is changed there.
  - the card of an object calls `core/ajax/ajaxtooltip.php` with the `data-params` read in `getNomUrl()`
    (`InboxElements::describe()`): to check if the core changes this attribute or this page.

Credits: icons [Tabler Icons](https://tabler.io/icons) (MIT).

