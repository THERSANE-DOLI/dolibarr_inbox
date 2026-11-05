# Inbox for [Dolibarr ERP & CRM](https://www.dolibarr.org)

Mail foundation used by other modules (first one: quoteassist, customer price request prefill).

- `Inbox::getMessage($mailbox, $messageId, $user, $origin)`: one mail read in a mailbox declared in CRM Client
  Connector, from its complete Message-ID, read only, cached, journaled.
- `Inbox::fetchRaw(...)`: same, raw RFC 822 source (to attach it to a record).
- `Inbox::parseContent($content)` / `Inbox::getMessageFromFile($path)`: .eml or Outlook .msg given as a file.
- Result: `InboxMessage` (headers, text and HTML bodies, attachments, attached mails).

Cache: the raw mails read in the mailboxes are kept in `documents/inbox/raw/` (30 days by default, purged every night).
This directory can be emptied at any time: a missing mail is read again on the mail server when needed. It holds a
README.txt saying so.

Requirements: Dolibarr 23+, PHP 7.4+, CRM Client Connector for the reading in mailboxes (IMAP access set on the
Anti-spam tab of the email accounts).

Licence: GPLv3, see COPYING.
