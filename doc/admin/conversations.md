# Conversations: new subject, new conversation

Inbox groups in one conversation an email and the emails it answers (In-Reply-To, References), its copies in other
folders, and, for a client that writes no such headers, the emails of the same subject and correspondent. Some
correspondents never write a new email: for a new request they answer the previous one, so the headers tie
everything together. Two tools separate what does not belong together.

## A new subject opens a new conversation

An email that answers another one with **another subject** opens a conversation of its own, as most mail programs do.
Its answers join it, the answers to the older emails stay in the old conversation. What is not a change of subject:

- « Re: », « Fwd: » and their versions in other languages, the spaces and the punctuation (a long subject folded by a
  mail program gets spaces inside its words);
- a subject only completed: « URGENT - X » or « [Ticket #12] X » stays with « X »;
- a reference kept: « Demande de prix RQ2511-28231 » and « Offer - your enquiry no. RQ2511-28231 », « Devis
  DVV2603662 » and « Annule et remplace Devis DVV2603662 » (a word of 5 characters or more with 4 digits or more);
- a typo fixed, the numbers unchanged.

Another number is another subject: « ROND 304L » and « ROND 316L », two orders, two sites.

The first email of such a conversation says it: « New conversation: this email answers ... with another subject »,
with « Join the previous conversation » when it does belong there. A conversation joined back by hand is never
separated again automatically.

Setting: Settings > Default values > « New subject = new conversation », on by default (a manager of Inbox). It acts
on the emails that arrive from then on; the conversations already formed are not changed (separate them by hand).

## Start a new conversation by hand

When the correspondent keeps the subject, nothing in the headers tells the new request from the old one. In the menu
« More actions » of the email that starts the new subject: « Start a new conversation here ». The dialog shows, before
anything is done:

- the emails that leave: this one and its answers (and the answers to its answers);
- the emails that stay: the others, an answer to an older email included even when it arrived later. The separation
  follows the answers, not the dates;
- the pieces of Dolibarr linked by the old conversation to the emails that leave, unticked: they speak of the old
  subject. The ones left unticked are removed from these emails, the ticked ones stay.

The toast « Undo » puts everything back, links included. Later, « Join the previous conversation » on the first email
of the new conversation does the same.

The separation is a fact on the email, kept by its Message-ID: it holds in every mailbox of the entity that has this
email, and survives moves between folders. It needs the level « Handle » on the mailbox (it changes the conversations
of every user of the mailbox); it is written in the journal of the actions. An email without Message-ID cannot be
separated, nor the first email of a conversation.

## The links follow the subject

A piece linked to an email goes to the other emails of its conversation **of the same subject** (and to the ones
that arrive later with this subject), never to another request that happens to be in the same conversation. Links
made before version 1.138.2 may have reached every email: the panel Dolibarr of an email then shows « N link(s) from
another subject », which lists them, ticked, and removes the ones validated.
