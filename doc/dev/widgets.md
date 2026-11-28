# Widgets of the Dolibarr panel

Since 1.154.0 inbox has a registry of widgets (`class/inboxwidgets.class.php`): small pieces of information about the
other side of a mail that each user will lay out in the Dolibarr panel of a mail, per mailbox (the layout, the grid
and the edit mode come with the next lot). Each widget is drawn by `js/inbox-widgets.js` from its data, the same way
for a widget placed in the panel and for its preview in the catalogue. The UI catalogue (`ux.php`, section Widgets)
shows every template at its default size.

## Templates and sizes

The grid of the panel has 4 columns; a row is half a line of list (`--ibx-row-h` / 2: 13 px compact, 15 px normal,
18 px comfortable), 4 px between two cells. A widget takes `w` × `h` cells. Each template declares
(`InboxWidgets::TEMPLATES`):

| Template | Default | Minimum width | Height min / max | Resize | Content |
|---|---|---|---|---|---|
| `mini` | 1 × 2 | 3.5 rem | 2 / 2 | horizontal | an icon, a number, a short label |
| `line` | 4 × 2 | 9 rem | 2 / 2 | horizontal | one piece of information |
| `figure` | 2 × 5 | 3.5 rem | 2 / 8 | both | a value, its label, its note, its change |
| `list` | 4 × 8 | 9 rem | 4 / 20 | both | the lines of a section |
| `chart` | 4 × 8 | 14 rem | 6 / 16 | vertical | bars by year or by month |
| `action` | 4 × 7 | 9 rem | 4 / 14 | both | a list and buttons to create |
| `thread` | 4 × 12 | 14 rem | 8 / 40 | vertical | the notes and replies (drawn by the panel) |
| `free` | 4 × 4 | 3.5 rem | 2 / 20 | both | HTML of a module |

The minimum width is in rem, not in cells: a cell is 60 to 95 px wide depending on the width of the panel, and the
grid turns the rem into cells. The content adapts to the size obtained (container queries on `.ibx-wg-cell`): what
does not fit goes away (label of a mini, note of a figure, lines of a list), never a word cut in the middle.

## Widgets of inbox

`InboxWidgets::WIDGETS`: each one follows the right and the switch of a section of the overview
([Overview of a mail](../admin/overview.md)); a widget is offered in the catalogue only when the user may read its
section, and its data is computed only for the mails of a mailbox he can read.

- Mini: `invoices_late`, `tickets_open`, `event_next`, `mails_count`.
- Line: `customer_since`.
- Figure: `figure_revenue`, `figure_due`, `figure_last_order`, `figure_prev_mail`, `figure_purchases`, `figure_to_pay`.
- List: `list_invoices`, `list_orders`, `list_proposals`, `list_supplier`, `list_projects`, `list_contracts`,
  `list_interventions`, `list_card`.
- Chart: `chart_revenue`, `chart_mails`.
- Action: `action_tickets` (button « New ticket » with the right to create tickets), `action_proposals` (« New
  proposal » with the right to create proposals).
- Thread: `notes`.

## Data

`ajax/widget.php`, read only:

- `action=catalogue`: the widgets the user may place, each with `id`, `template`, `title`, `size` and `preview` (the
  template with sample values, never computed).
- `action=data&id=<row of the mail>&w=<ids, comma separated>`: `data` = id => the data of the widget, or `null` when
  the user may not read it or when there is nothing to show for this mail. 30 widgets at most per call; each
  section of the overview is computed once per call even when several widgets use it.

Formats (every text escaped when drawn, links kept inside Dolibarr):

- `mini`: `icon`, `value`, `label`, `title`, `level` (`ok`, `info`, `warning`, `danger`), `note`, `url`;
- `line`: `icon`, `text`, `note`, `url`;
- `figure`: `label`, `value`, `note`, `level`, `delta` (`text`, `level`, `dir`: up or down, `title`);
- `list`: `title`, `sum`, `sumLevel`, `url`, `rows` (`label`, `value`, `note`, `level`, `url`, `item`);
- `chart`: `title`, `chart` (`label`, `value`, `hint`, `current`, `bars`: `x`, `v`, `short`, `text`, `partial`),
  `small` (bars by month);
- `action`: the fields of `list` and `actions` (`label`, `icon`, `url`).

## Layouts (1.155.0)

Stored in `llx_inbox_userparam`: `widgets_desktop` / `widgets_mobile` for a user and a mailbox, `widgets_model_desktop`
/ `widgets_model_mobile` with `fk_user` = 0 for the model of a mailbox. Value: `{items: [{id, x, y, w, h}], dock, at}`
(`dock`: height of the notes in pixels, 0 the default; `at`: date of the saving). Checked by `InboxWidgets::clean()`:
known widgets, each once, 40 at most, sizes and places kept inside the grid and the bounds of the template.

`ajax/widget.php`: `action=layout&account=<mailbox>` gives `manager`, `preset` and, per version, `user` and `model`
(null when none); `save`, `reset` (the user's), `savemodel`, `removemodel` (a manager of the mailbox, written in its
journal) are POST with the token, `account`, `version` (`desktop`, `mobile`) and `layout`.

The panel (`js/inbox-panel-widgets.js`) draws the grid as a CSS grid outside the edit mode; GridStack.js
(`js/vendor/gridstack`, MIT) is loaded only when the user personalizes, and only through `Dolibarr.tools.inbox.grid`.

In JavaScript, `Dolibarr.tools.inbox.widgets`: `catalogue()` (cached for the page), `data(mail, ids)` and
`render(template, data)`, which returns the HTML of one widget.
