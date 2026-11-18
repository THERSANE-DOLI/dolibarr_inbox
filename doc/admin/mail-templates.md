# Mail templates: complete mails and default templates

The templates of inbox are the mail templates of Dolibarr of the type `inbox_send`. They are written in the tab
« Templates » of a mailbox (managers), or from a writing with « Save as a template ».

## Piece of text or complete mail

Each template has a kind, chosen in its editor:

- **Piece of text** (the kind of the templates written before 1.118.0, and of those written in the setup of
  Dolibarr): put where the cursor is, the text around it stays.
- **Complete mail**: takes the place of the text of the writing, and its subject in a new mail (never the « Re: »
  of an answer). When the text is empty, or is still the complete mail put before and not changed, it is replaced
  without a question. When the user already wrote something, a dialog asks: « Replace » (the toast « Undo » gives the
  text back) or « Add after ». The quote, the signature of the identity and a signature changed for this mail stay.

The templates of Dolibarr for a linked piece (proposal, order, invoice...) and for every type count as complete
mails, as on the card of the piece.

## Template without subject

The subject of a template may stay empty: the subject of the writing is then never changed, whatever the kind of the
template. It suits a template for a new mail whose subject depends on each mail, or an answer. « Save as a template »
keeps the subject of a new mail by default, not the one of an answer or a forward (box « Also keep the subject »).
The list of the tab « Templates » shows « (no subject) » for them.

The setup page of Dolibarr (Setup > Emails > Email templates) may ask for a subject, depending on the version of
Dolibarr: write such a template in inbox.

## Default templates

A template may be put by itself in a **new mail** or in an **answer** (reply, reply to all, reply to the list) while
the text of the writing is empty. A forward, a draft or a mail written again never receives one. The dialog
« Default templates... » is in the menu of the templates of a writing, and in the tab « Templates » of a mailbox.

Four levels, the first one set wins:

| # | Level | Who sets it |
|---|---|---|
| 1 | Me in this mailbox | the user, when he may send from the mailbox |
| 2 | Everyone in this mailbox | the managers of the mailbox, the administrators of inbox |
| 3 | Me in every mailbox | the user |
| 4 | Everyone in every mailbox | the administrators of inbox |

The mailbox comes before the user: it carries the use of the mails (quotes, after sales), a personal default is a
habit. Who wants another template in one mailbox sets level 1.

Each level is « Not set » (the next level answers), « No template » (stops there: a user may refuse the template of
the mailbox), or a template. A template of everyone must be shared; a template of a level « every mailbox » must be
offered in every mailbox.

Under the four levels, a template of the type `inbox_send` marked « Default template for this type » in the setup of
Dolibarr (Setup > Emails > Email templates) is used for a new mail, as the form of mails of Dolibarr does.

A default that can no longer be used (removed, inactive, no longer offered in the mailbox, private to someone else)
is skipped: the next level answers. Removing a template removes its defaults.

Changing the identity of a writing whose text is still the default template puts the default of the new mailbox in
its place (or empties the text when the new mailbox has none). A writing whose only content is its untouched default
template is closed without keeping a draft.

The language of the default template is not chosen from the recipient: one template per level and kind of writing.
