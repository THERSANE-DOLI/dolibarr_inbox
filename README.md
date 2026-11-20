# Inbox for [Dolibarr ERP & CRM](https://www.dolibarr.org)

The team webmail built into Dolibarr: the mailboxes of the whole team, shared or personal, read and written next to
your third parties and your documents.

Website, screenshots and services: [inbox.techsane.fr](https://inbox.techsane.fr/en/)
(also in [French](https://inbox.techsane.fr/), [German](https://inbox.techsane.fr/de/),
[Italian](https://inbox.techsane.fr/it/) and [Spanish](https://inbox.techsane.fr/es/)).

## Features

**Mail linked to Dolibarr**

- Sender recognised: the third party, the contact and their open proposals, orders and invoices next to the email.
- An email linked to a document, or a third party or a proposal created from it, in one click.
- Sent emails recorded on the documents, internal notes of the team kept on the email.

**Team work**

- Shared and personal mailboxes (IMAP), four access levels per mailbox: read, send, handle, manage.
- Assignments with notification: the shared mailbox becomes a work queue.
- Tags shared by the team, in your colours, with keyboard shortcuts, compatible with other mail clients.
- Unified views of several mailboxes, grouped conversations, table view, action log per mailbox.

**Writing**

- Rich editor (or CKEditor), signatures per identity, Dolibarr mail templates.
- Delayed sending to take a mail back, read receipts under control, optional voice typing.

**Sorting and search**

- Rules on the mail server (Sieve): sorting applies on arrival, even with Dolibarr closed, out of office reply included.
- Filters by tag, attachment, period, search in the text of the emails, optional fast search engine.
- Notifications in the bell: an email assigned, a sending that fails, a mailbox in error.

**Security**

- Impersonation unmasked: lookalike domains and misleading display names flagged above the email.
- Recognised domains: suppliers, bank, carriers declared, their authenticated emails recognised.
- Bank details under watch: an email announcing a change of IBAN reminds you to check by phone.
- Remote images and trackers blocked until you ask.
- Blocked senders shared by all mailboxes, optional anti-spam engine installed next to Dolibarr.
- Protected mailboxes: the password stays in the browser of the user, nobody else can open them.

**Everywhere**

- Installable application (phone and desktop), light or dark theme, mailto links of the system opened in Inbox.

Your emails stay on your mail server: IMAP, SMTP and Sieve, your other mail clients keep working. No outside
service by default, the features that use one stay off until you turn them on.

## Requirements

- Dolibarr 23 or later; on Dolibarr 23, the module backportdolcontext (JavaScript context of Dolibarr 24).
- PHP 7.4 or later.
- An IMAP mail server (Sieve recommended for the rules on the server).

## Installation

1. Download the zip `module_inbox-X.Y.Z.zip` of the [latest GitHub release](https://github.com/THERSANE-DOLI/dolibarr_inbox/releases/latest).
2. Deploy it in Setup > Modules > Deploy an external module, then enable Inbox.
3. Add your mailboxes: Inbox tests the connection and finds the special folders.
4. Choose who reads, sends, handles or manages each mailbox.

Inbox acts directly on your mailboxes (sorting, moves, rules): try it first on a test mailbox and back up your emails
before connecting production.

Git deployment and branches: [Installation](doc/admin/install.md).

## Documentation

- [For administrators](doc/admin/README.md): installation and branches, settings, anti-spam, instant refresh, agenda panel...
- [For developers](doc/dev/README.md): API, hooks, extending the application, development and tests
- Contributing, also with a coding agent: [AGENTS.md](AGENTS.md)

Other modules plug into Inbox through documented hooks, never the reverse.

## Licence

GPLv3, see [COPYING](COPYING).
