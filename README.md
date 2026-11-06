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
`inboxConnectors` (context `inbox`) and the triggers of its objects.

Licence: GPLv3, see COPYING.

## Inbox application

`index.php` opens the application (right « Use the webmail »): Settings > Mailboxes (right « Manage the mailboxes »),
General, My account. The reading of the mails comes with the next lots.

## Development

- Icons: `dev/icons.json` (usage name => Tabler icon) and `dev/tabler/` (Tabler Icons 3.50.0, MIT license, see
  `dev/tabler/LICENSE`); `php dev/build-icons.php` builds `img/inbox-icons.svg` and the icons of the application.
- Local test mail server: `dev/dovecot/run.sh` (Docker, IMAP 127.0.0.1:10143, Sieve 14190, SMTP submission 10587,
  test values only) then `python3 dev/dovecot/seed.py`.
- Tests: `INBOX_DOLIBARR_HTDOCS=/path/htdocs DOLI_TEST_DB=<test database> ~/bin/phpunit -c test/phpunit/phpunit.xml`,
  on a test database only (the bootstrap refuses the others). The HTTP tests need `INBOX_TEST_URL`,
  `INBOX_TEST_LOGIN`, `INBOX_TEST_PASSWORD` of a test instance.
- Catalogue of the components: `ux.php`.

Credits: icons [Tabler Icons](https://tabler.io/icons) (MIT).

