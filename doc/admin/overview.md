# Overview of a mail

For the modules that add their own column to the strip, see [Extend the application](../dev/extend-application.md).

On a computer, the strip of icons on the right of the mail space has a column « Overview » between Dolibarr and
Today. With a mail open, it says in one place who the mail is with: the thirdparty Inbox recognized for the mail (the
same as the Dolibarr part), or only the address when the sender is unknown of Dolibarr.

Nothing is computed while the column is closed: opening a mail costs nothing more. When the column opens, its head is
read: the tags of the thirdparty (customer, prospect, supplier, categories, closed, refuses mailings), the alerts that
are cheap to know (invoices late, outstanding limit over), four key figures (revenue of the year and its change against
the same period of the last year, left to pay, last order, previous mail; for a supplier what we bought this year and
what we have to pay) and the notes of the card. Each section below is computed only when it is unfolded; the sections
left unfolded open again by themselves for the next mails (choice kept in the browser).

The sections:

- **Revenue excl. tax**: chart by year (6 at most, the current one hatched), last 12 months, the years as figures,
  weight of the customer over 12 months (share and rank), seasonality, margin (module Margins), credit notes.
- **Purchases from this supplier**: this year and the last one, invoices to pay, orders in progress, late receptions,
  products bought most.
- **Mails exchanged**: mails per month, previous mail received and sent (and by whom), counts, since when, how long the
  conversation open waits for an answer, our usual reply time, who handles their mails, who writes on their side,
  mailboxes concerned, mails not delivered to the address, the last 5 mails (a click opens the mail).
- **Invoices and payments**, **Orders**, **Proposals**, **Tickets**, **Agenda**, **Projects**, **Contracts**,
  **Interventions**, **Products bought most** (24 months), **Card and terms**.

A section without data says so when unfolded. Their order follows the usage of the mailbox (Settings > Mailboxes >
Usage): Commerce puts revenue, invoices, orders and proposals first; Accounting the invoices; Support the tickets,
interventions and mails; Purchase the supplier; Transport the orders. « Automatic » takes Commerce for a customer and
Purchase for a supplier.

When the reading would keep less than 40 rem beside a column, the overview is a drawer over the reading, as the other
columns; a chip « Overview » under the head of the mail opens it. On a phone the chip opens the overview in a sheet.

## Rights

The overview never shows more than the user may see in Dolibarr:

- the mail must be in a mailbox the user can read, else nothing at all; a protected mailbox gives nothing;
- the thirdparty is the one Inbox finds for the mail (link of the mail, address, domain), never one given by the
  browser, and the user must be able to read it (right « Read thirdparties », restriction of the sales
  representatives included);
- each section needs its module enabled and its right: invoices for the revenue, the invoices and the products;
  margins; orders; shipments; proposals; tickets (with « Tickets assigned only », a user who is not an
  administrator sees the tickets assigned to him, as in the list of the tickets); agenda (without « read the events of the others », his own only); projects (without « read
  all projects », the public ones); contracts; interventions; categories; supplier invoices and orders;
- the weight and the rank of a customer read the invoices of every customer: computed only for a user who sees every
  thirdparty, and kept one hour per entity.

The notes of the card are shown to who may read the thirdparty, as on its card.

## Settings

Setup of the module (Home > Setup > Modules > Inbox, administrators of Dolibarr), block « Overview of a mail »:

- **Column « Overview » in the strip**: off, the icon leaves the strip of every user (constant `INBOX_OVERVIEW_OFF`).
- One switch per piece of information, in three groups (top, sections, details inside the sections). Everything is
  shown by default; what is switched off is not computed any more, for every user (constant
  `INBOX_OVERVIEW_HIDE_<key>`, keys in `InboxOverview::settings()`). These are not rights: among what is left on,
  the rights of Dolibarr still decide.

## Origin

The overview is taken from the module InboxInsight of Max (Air MKG), offered to Inbox in its issue 27. Inbox moved it
from the Dolibarr part into its own column and computes each part only when asked.
