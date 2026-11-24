## What changes

<!-- For the users, then for the code. Link the issue if there is one. -->

## How it was checked

<!-- Tests run, browser checks (computer and phone), test database used. -->

## Screenshots

<!-- Required when the PR changes what the user sees (css/, js/, tpl/, img/), checked by the CI.
FIX: before and after, side by side. NEW: one screenshot at least (computer and/or phone).
Paste the images in the table, delete this section if nothing visible changes. -->

| Before | After |
| --- | --- |
|  |  |

## Checklist

See [AGENTS.md](../AGENTS.md) and [doc/dev/principles.md](../doc/dev/principles.md).

- [ ] Tests run on a test database only; new behaviour covered by new tests
- [ ] `phpcs` (ruleset of Dolibarr) and `php -l` on the PHP files changed, `node --check` on the JS files changed
- [ ] User texts in the five language files (en_US, fr_FR, de_DE, it_IT, es_ES), no customer name
- [ ] Every value of a mail through `InboxSafe` / `safeHtml()`; rights and CSRF checked on the server
- [ ] Inbox names no external module, outside the core of Dolibarr (hooks only)
- [ ] Version (`modInbox.class.php`) and ChangeLog entry; Y when the module must be activated again
- [ ] New feature: its page in `doc/` registered in `InboxDocumentation`
- [ ] Checked in a browser on a computer and a phone width
- [ ] Visual change: screenshots in the description (FIX: before and after, NEW: one at least)
