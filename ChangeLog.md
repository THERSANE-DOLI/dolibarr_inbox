# CHANGELOG INBOX FOR [DOLIBARR ERP CRM](https://www.dolibarr.org)

- NEW: first version, mail foundation (Dolibarr 23+) :   1.0.0
  - one mail read in a mailbox declared in CRM Client Connector from its complete Message-ID, read only (BODY.PEEK,
    no flag, no move): inbox, sent, other folders, junk and trash last; exact Message-ID check after the IMAP search.
  - knowing the Message-ID is the access: right « read a mail from its Message-ID », no listing nor search by
    sender, subject or date, every reading written in a journal (user, mailbox, result, folder, cache).
  - raw mails cached on the server (30 days by default), scheduled purge of the cache and of the journal.
  - .eml files parsed (generic MIME parser): text and HTML bodies, attachments typed
    by their content, pictures of the body marked inline, attached mails (.eml and .msg) read with their attachments.
  - Outlook .msg files parsed by an own reader of compound files (no dependency): subject, sender (SMTP address),
    recipients, dates, Message-ID, bodies, attachments, attached mails; internet headers kept by Outlook used as a
    fallback. Body only stored as compressed RTF not read yet.
  - setup page: retention of the cache and of the journal, test of reading a Message-ID, test of parsing a file.
