# Inbox for developers

Mail foundation for other modules: they plug into Inbox through the hooks below.

- `Inbox::getMessage($mailbox, $messageId, $user, $origin)`: one mail read in a mailbox of inbox (or given by another
  module through the hook `inboxResolveAccount`), from its complete Message-ID, read only, cached, journaled.
- `Inbox::fetchRaw(...)`: same, raw RFC 822 source (to attach it to a record).
- `Inbox::parseContent($content)` / `Inbox::getMessageFromFile($path)`: .eml or Outlook .msg given as a file.
- Result: `InboxMessage` (headers, text and HTML bodies, attachments, attached mails).

Other modules plug into inbox, never the reverse: hooks `inboxResolveAccount`, `inboxAccountImportSources`,
`inboxConnectors`, `addInboxHtmlHeader`, `inboxSituationIndicators`, `inboxMailActions`, `inboxAgendaProvider`,
`inboxInviteEvent`, `inboxConsumers` (context `inbox`), the module_parts `inboxcss` and
`inboxjs` (see « Extend the application ») and the triggers of its objects.

A mail sent with `CMailFile` from the address of a mailbox gets its copy in Sent without anything to do (hook
`sendMailAfter` of the core, context `mail`): see [Emails sent by Dolibarr in Sent](../admin/dolibarr-sent.md).

Pages:

- [Principles](principles.md): the rules of every change (read this first)
- [Extend the application](extend-application.md): files of a module in the application, header, styles, indicators of the card of the thirdparty
- [Agenda panel for modules](agenda.md): your agenda instead of the one of Dolibarr, actions of a mail, invitations, events of your own, JS hooks
- [New customer: hook for modules](new-customer.md)
- [Subscribe a module to the mailboxes](consumers.md): react to the mails of the mailboxes where the managers allow it
- [Searchable lists (tool combo)](combo.md)
- [Push drivers (instant refresh)](push.md)
- [Data of a mail: one door](mail-data.md): the cleaning of every value of a mail
- [Development](development.md): icons, test mail server, tests, catalogue, copies of Dolibarr

