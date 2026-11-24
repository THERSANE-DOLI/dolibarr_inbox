# Emails sent by Dolibarr in Sent

Dolibarr sends some emails itself, through its own sending server (Setup > Emails): the answers written on the card
of a ticket, the proposals, orders and invoices sent by email, the notifications, the emails of the scheduled jobs...
When such an email leaves from the address of a mailbox of Inbox, or from one of its identities, Inbox files a copy of
it, marked read, in the Sent folder of that mailbox, as if it had been written in Inbox:

- the conversation shows both sides: the answer of the customer joins the email it answers (same Message-ID);
- when Dolibarr answers an email of the mailbox (In-Reply-To), that email is marked « Answered »;
- the copy is the email as it left, without its hidden copies (Bcc), that the other users of the mailbox would see.

The emails written in Inbox are sent by Inbox, not by Dolibarr: they keep their own copy, never a second one.

## The setting

Settings > Mailboxes > the mailbox > Connection > Server, section « Outgoing server (SMTP) »: « Emails sent by
Dolibarr », « File in Sent the emails Dolibarr sends from this address ». Saved per mailbox, with its card.

On by default: a mail program keeps what it sends, and the answers of the customers need it to join their
conversation. Turn it off when the sending server of Dolibarr already files a copy in that mailbox and is not
recognised (a copy by Inbox would be a second one), or when the mailbox must not keep the emails of Dolibarr.

## Which mailbox

The sender chosen in Dolibarr (the user, the company, a sender profile of Setup > Emails > Senders) gives the From of
the email. Inbox looks for, in the active mailboxes of the current entity:

1. the mailbox whose address is this From;
2. else the mailbox that has this address as an active identity (Settings > Mailboxes > the mailbox > Identities).

So a sender profile files its emails in a mailbox when its address is the address of that mailbox or one of its
identities: add the address of the profile as an identity of the mailbox that must keep its emails. An address that
is an identity of several mailboxes files nothing (Inbox never guesses which one): it is written in the log of
Dolibarr (« InboxDolibarrSent ambiguous »). The address of a mailbox always wins over the identity of another one.

When `MAIN_MAIL_FORCE_FROM` is set, every email leaves with this forced From, and Dolibarr keeps the sender chosen as
Reply-To (when no other Reply-To was given). Inbox then looks for the mailbox of the Reply-To first, then for the one
of the forced From.

## When nothing is filed

Nothing is filed:

- when no mailbox is found as above, or several have the identity;

- for the mass emailing (module Emailing): one connection per recipient;
- for a new password, or the link to choose one (context « passwordreset » of Dolibarr): a secret has nothing to do
  in a mailbox read by several people;
- when the sending server used by Dolibarr files the copy itself: Gmail or Microsoft 365, or the sending server of
  the mailbox when its « Copy in Sent » says « Made by the sending server »;
- for a protected mailbox, unless the email is sent from a session where it is unlocked; never for a mailbox
  protected with its content;
- when the mailbox has no Sent folder known (tab Connection > Folders);
- when the setting above is off.

## Cost and errors

The copy is filed during the sending, in the same request: the copy is in Sent when the user opens Inbox, and a
protected mailbox can only be written from the session that unlocked it. One connection to the mail server of the
mailbox, kept for the other emails of the same request: a list of invoices sent by email at once logs in once.

A copy that fails never stops nor fails the sending, the email is gone anyway: it is written in the log of Dolibarr
(lines « InboxDolibarrSent failed », with the reason). The copy reaches the lists of Inbox at the next
synchronisation of the mailbox.

The old way to keep the emails of Dolibarr, `MAIN_MAIL_AUTOCOPY_TO` set to the address of the mailbox, is no longer
needed for this: it adds a hidden copy to every email, received unread in the Inbox folder.

## For developers

A module that sends with `CMailFile` from the address of a mailbox has nothing to do: the hook `sendMailAfter`
(context `mail`) of Inbox files the copy. A mail sent by another way than `CMailFile` is not seen.
