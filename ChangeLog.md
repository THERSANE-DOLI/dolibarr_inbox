# CHANGELOG INBOX FOR [DOLIBARR ERP CRM](https://www.dolibarr.org)

- NEW: mailboxes of inbox, inbox application (settings) :   1.2.0
  - mailboxes managed in inbox (IMAP connector, SMTP, Sieve): personal or shared, passwords encrypted and never
    sent to the browser, connection test step by step (read only), folder roles, enabled after a successful test.
  - accesses per mailbox for users and groups (read, send, manage), no implicit access for the admins of Dolibarr,
    journal of the changes; identities with their signature (copy from the sender profiles of Dolibarr); tags of
    Thunderbird (global list or own list of a mailbox); settings of each user.
  - inbox application in full page (index.php): own styles and icons (Tabler), JavaScript context of Dolibarr
    (backportdolcontext on Dolibarr 23), session expired handled without losing what was typed, installable.
  - reading by Message-ID (lot 0) on the mailboxes of inbox; other modules plug in through the hooks
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
  - line breaks of the text of the mail shown as « \n » and « <br> ».
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
