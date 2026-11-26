# Views: the mailboxes each one gathers, Spams

The section « Views » on top of the panel gathers emails of several mailboxes: Unified inbox, Unread, Starred,
Assigned to me, Sent and Drafts (each one shown or hidden with the menu ⋯ of the section). Each email stays in its
own mailbox: reading, answering, moving act on it there, and the answer leaves from the identity of its mailbox.

## Mailboxes of each view

My account > « Mailboxes of the views »: a grid, one row per mailbox, one column per view.

| View | Mailboxes by default | Can be changed |
|---|---|---|
| Unified inbox, Unread, Sent, Drafts | the **common list** | yes, mailbox by mailbox |
| Starred | every mailbox read | yes, mailbox by mailbox |
| Assigned to me | every mailbox read | no: an email assigned to the user is always there |
| Spams | every mailbox read | yes, mailbox by mailbox |

- **Common list**: the first column. The views that follow it (« Follows the common list » under their name) change
  with it. It is the list of the unified views of the versions before 1.141.0, kept as it was.
- Change a box in the column of a view: the view gets a list of its own and no longer follows the common list.
  « Follow the common list » under its name brings it back.
- Starred covers every mailbox by default because the star is the flag of the mail server, shared in the mailbox: on
  a shared mailbox, the emails starred by colleagues are there too. Leave such a mailbox out of Starred if needed.
- A mailbox left out of a view stays in the panel. A mailbox added later enters every view (the lists keep the
  mailboxes left out, not the ones included).
- Right click (or ⋯) on a view: « Mailboxes of this view… » opens the grid on its column (not on Assigned to me).
- « Starred » of a mailbox (in its line of icons) always lists that mailbox, even left out of the view.

## Spams

The view « Spams » (hidden by default: menu ⋯ of the section « Views ») lists the junk folders of its mailboxes, one
email per line, to see at a glance what arrived there and find an email that is not a spam.

- No unread badge (a spam is nearly always unread): a grey count of the spams arrived since the view was last opened,
  and the warning sign when some of them are probably legitimate.
- On each line, when Inbox knows it, why the email is there: the score of the anti-spam filter of Inbox against the
  score from which an email goes to the junk, or « Blocked sender ». The verdict of the mail server is shown in the
  reading of the email.
- « Probably legitimate »: the sender is known to Dolibarr and the email is authenticated (DKIM, SPF, DMARC). A doubt,
  not a certainty. The button « Probably legitimate » above the list keeps only those, in the view and in each junk
  folder.
- « Not junk » (Shift+J) puts the email back in the Inbox of its own mailbox and teaches the anti-spam filter (level
  « Handle » on that mailbox).

Only the mailboxes the user reads are ever listed; a protected mailbox is read on its server, one folder at a time,
and is never in a view.

## For a developer

User settings `unified_exclude` (common list, JSON list of mailboxes left out) and `views_scope` (JSON object,
`{"view": {"exclude": [ids]}}`, settable views: inbox, unread, sent, drafts, flagged, junk); `junk_seen_at` (date of
the last opening of the Spams view, written by `ajax/list.php`). The rule lives in
`class/inboxunifiedview.class.php` (`InboxUnifiedView::accounts()`), applied by `ajax/list.php`; the browser applies
the same rule for the counters (`viewExcluded()` in `js/inbox-mail.js`).
