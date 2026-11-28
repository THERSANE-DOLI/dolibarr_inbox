# Widgets of the Dolibarr panel

On a computer, the column Dolibarr of a mail has three parts: the fixed top (sender, pieces, actions), a grid of
widgets, and the notes docked at the bottom (drag the bar between them to change the height of the notes). The
widgets are small pieces of information about the other side of the mail: late invoices, open tickets, next event,
mails exchanged, key figures (revenue, left to pay, last order...), lists (invoices, orders, proposals...), charts,
and quick actions (open tickets and « New ticket », open proposals and « New proposal »). They follow the rights of
Dolibarr and the switches of the overview ([Overview of a mail](overview.md)): a widget the user may not read is
never offered nor computed. A widget with nothing to show for a mail keeps its place (a dash, or « Nothing to show »)
so that the layout does not move from a mail to the next one.

By default the panel shows the least: a few widgets chosen by the usage of the mailbox (Settings > Mailboxes >
Usage), or none. Each user builds what he needs.

## Personalize

Menu « ⋯ » of the head of the column > « Personalize the widgets »: the rest of the application goes under a veil,
the panel keeps its whole height and the catalogue of widgets opens on its left (previews with sample values,
nothing computed).

- Add: drag a preview into the panel, or click it (or Enter) to put it at the first free place.
- Move: drag a widget; resize it by its bottom right corner, within the bounds of its kind (a mini only in width, a
  chart only in height...).
- Remove: drag it back to the catalogue, or its cross; « Undo » in the message.
- Each change is saved at once. « Done » or Escape closes the edit mode.

The lists and the actions are as high as their content, up to their maximum.

## Layouts

- **Per user and per mailbox**: what a user lays out for a mailbox changes nothing for the others.
- **Model of the mailbox**: a manager of the mailbox (level « Manage ») saves his layout as the model, written in
  the journal of the mailbox. The users who have no layout of their own follow it; a user who has his own keeps it
  and sees « The model of the mailbox changed · Adopt ». There is no way to force a layout on everybody.
- **Back to the model of the mailbox** (menu « ⋯ ») removes the layout of the user: the model, else the preset of the
  usage.
- Kept on the server, so the same on every computer of the user. The version for a small screen (phone, tablet in
  portrait) comes with a next version.
