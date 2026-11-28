# Widgets of the Dolibarr panel

On a computer, the column Dolibarr of a mail has three parts: the fixed top (sender, pieces), a grid of
widgets, and the notes docked at the bottom. The notes are as high as their content: without notes they keep only
their head and the field to write one, and the widgets take the room; with more notes they grow up to a maximum, set by
dragging the bar between the widgets and the notes (or its arrows), then their list scrolls. On the bar, the room kept
for notes still to come shows dashed above the notes: the bar always moves the top of that room, even when the notes do
not need the room yet. Grabbed from the bar, the pointer jumps onto the top of the room (the browser locks it, Escape
gives it back) and its moves go on at the edge of the screen; the top edge of the dashed room can be dragged as well. The
widgets are small pieces of information about the other side of the mail: late invoices, open tickets, next event,
mails exchanged, key figures (revenue, left to pay, last order...), lists (invoices, orders, proposals...), charts,
and quick actions (open tickets and « New ticket », open proposals and « New proposal », invoices and « New
invoice », orders and « New order », each button only with the right to create). They follow the rights of
Dolibarr and the switches of the overview ([Overview of a mail](overview.md)): a widget the user may not read is
never offered nor computed. A widget with nothing to show for a mail keeps its place (a dash, or « Nothing to show »)
so that the layout does not move from a mail to the next one.

By default the panel shows the least: a few widgets chosen by the usage of the mailbox (Settings > Mailboxes >
Usage), or none. Each user builds what he needs.

## Actions and blocks of the other modules

Since 1.157.0 the buttons of the other modules (widget « Actions ») and the blocks they add to the panel of a mail
(for example the meetings with the third party) are widgets too. They show by themselves at the end of the grid, as
before, until the user moves them (they then keep their place) or removes them (they no longer come, the catalogue
gives them back). A block is in the catalogue only for a mail where its module shows it, and it is left out of the
panel for the mails where its module has nothing to say. Without the overview (switched off in the setup) they stay
in the fixed top as before.

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
- Kept on the server, so the same on every computer of the user.

## Small screen

Under 64 rem (a phone, a tablet in portrait, a narrow window) the Dolibarr part is a band on top of the mail or a
sheet: the widgets are one under the other, the minis side by side as chips, the lists as high as their content. The
small screen has a layout of its own, chosen by the width of the screen and never by the device: as long as none is
saved (the user's or the model of the mailbox), it takes the one of the large screen in one column. Personalizing on
a small screen saves the small layout only.

Menu « ⋯ » (in the head of the sheet on a phone, at the end of the widgets on a tablet) > « Personalize the widgets »:
the widgets become a list with a grip (drag it, or the arrows of the keyboard, to change the order) and a cross, and
the catalogue opens as a sheet (on top of the screen on a phone, the Dolibarr sheet below it): a tap on a widget adds
it at the end.
