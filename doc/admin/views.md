# Views: the mailboxes each one gathers

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

Only the mailboxes the user reads are ever listed; a protected mailbox is read on its server, one folder at a time,
and is never in a view.

## For a developer

User settings `unified_exclude` (common list, JSON list of mailboxes left out) and `views_scope` (JSON object,
`{"view": {"exclude": [ids]}}`, settable views: inbox, unread, sent, drafts, flagged). The rule lives in
`class/inboxunifiedview.class.php` (`InboxUnifiedView::accounts()`), applied by `ajax/list.php`; the browser applies
the same rule for the counters (`viewExcluded()` in `js/inbox-mail.js`).
