# Widgets of the Dolibarr panel

Since 1.154.0 inbox has a registry of widgets (`class/inboxwidgets.class.php`): small pieces of information about the
other side of a mail that each user lays out in the Dolibarr panel of a mail, per mailbox. Each widget is drawn by `js/inbox-widgets.js` from its data, the same way
for a widget placed in the panel and for its preview in the catalogue. The UI catalogue (`ux.php`, section Widgets)
shows every template at its default size.

## Templates and sizes

The grid of the panel has 12 columns, divided by 2, 3, 4 and 6 (4 before 1.159.0: a layout saved without `cols` is
read with its `x` and `w` scaled to 12); a row is half a line of list (`--ibx-row-h` / 2: 13 px compact, 15 px normal,
18 px comfortable), 4 px between two cells. A widget takes `w` × `h` cells. Each template declares
(`InboxWidgets::TEMPLATES`):

| Template | Default | Minimum width | Height min / max | Resize | Content |
|---|---|---|---|---|---|
| `mini` | 3 × 2 | 3.5 rem | 2 / 2 | horizontal | an icon, a number, a short label |
| `line` | 12 × 2 | 9 rem | 2 / 2 | horizontal | one piece of information |
| `figure` | 6 × 5 | 3.5 rem | 2 / 8 | both | a value, its label, its note, its change |
| `list` | 12 × 8 | 9 rem | 4 / 20 | both | the lines of a section |
| `chart` | 12 × 8 | 14 rem | 6 / 16 | both | bars by year or by month |
| `action` | 12 × 7 | 9 rem | 4 / 14 | both | a list and buttons to create |
| `thread` | 12 × 12 | 14 rem | 8 / 40 | vertical | the notes and replies (drawn by the panel) |
| `free` | 12 × 4 | 3.5 rem | 2 / 20 | both | HTML of a module |

The minimum width is in rem, not in cells: a cell is 25 to 55 px wide depending on the width of the panel, and the
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
  proposal » with the right to create proposals), `action_invoices` (« New invoice »), `action_orders` (« New
  order »), defined by `InboxWidgets::ACTIONS`; `mail_actions`: the buttons of the hook `inboxMailActions`
  (`local`: drawn by the panel from its own data, never computed by `ajax/widget.php`).
- Free: `panel:<id>`, a block of the hook `inboxMailPanels` (not in the catalogue of the server: the panel adds the
  blocks of the mail shown).
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
/ `widgets_model_mobile` with `fk_user` = 0 for the model of a mailbox. Value: `{items: [{id, x, y, w, h}], dock, off,
cols, at}` (`dock`: maximum height of the notes in pixels, 0 the default; `off`: the module widgets, `mail_actions` and `panel:*`,
the user took away; `cols`: columns of the grid, 12; `at`: date of the saving). Checked by `InboxWidgets::clean()`: known widgets (or `panel:` and an
id), each once, 40 at most, sizes and places kept inside the grid and the bounds of the template.

The module widgets not placed and not in `off` are drawn at the end of the grid (1.157.0); a placed one with nothing
for the mail shown (no action, no block) is left out of the panel and kept in the layout when it is edited.

`ajax/widget.php`: `action=layout&account=<mailbox>` gives `manager`, `preset` and, per version, `user` and `model`
(null when none); `save`, `reset` (the user's), `savemodel`, `removemodel` (a manager of the mailbox, written in its
journal) are POST with the token, `account`, `version` (`desktop`, `mobile`) and `layout`.

The panel (`js/inbox-panel-widgets.js`) draws the grid as a CSS grid outside the edit mode; GridStack.js
(`js/vendor/gridstack`, MIT) is loaded only when the user personalizes, and only through `Dolibarr.tools.inbox.grid`.

In JavaScript, `Dolibarr.tools.inbox.widgets`: `catalogue()` (cached for the page), `data(mail, ids)` and
`render(template, data)`, which returns the HTML of one widget.

## Widgets of a module (1.162.0)

Hook `inboxWidgets`, context `inbox`: your module declares widgets drawn with the templates of inbox. `$parameters`:
`user`, `capabilities`, `templates`. Answer `$this->results['widgets']`, a list of:

- `source` (your technical name, `[a-z0-9_]`) and `id` (`[a-z0-9_-]`): the widget is `mod:source:id` in the layouts;
- `template`: `mini`, `line`, `figure`, `list`, `chart`, `action` or `free` (its size and bounds, table above);
- `title`, `icon` (usage name), `rights` (rights of Dolibarr needed, each `array(module, permission[, sub])`);
- `url`: an address of this site the panel reads for each mail, markers `{socid}`, `{contactids}`, `{projectid}`,
  `{elements}`, `{ctx}` (a token of the mail, see [agenda.md](agenda.md)). It answers JSON in the format of the
  template (formats above); `free` takes the format of a block of the panel (`badges`, `lines`, `action`, `links`, see
  [extend-application.md](extend-application.md)). `{"hidden": true}`: nothing for this mail, the widget is left out;
- `preview`: sample data in the same format, shown in the catalogue (never computed);
- `auto`: true to show by itself at the end of the grid, as the blocks of the modules, until the user places it or takes
  it away;
- `replaces`: `panel:<id>` of a block your module declared with `inboxMailPanels`. The layouts that hold the block are
  read with your widget in its place (its size, or its removal), and the block is no longer offered.

Inbox announces the capability `widgets` to the hooks of a mail: declare your block with `inboxMailPanels` only when
the word is missing (an older inbox), your widget otherwise.

```php
public function inboxWidgets($parameters, &$object, &$action, $hookmanager)
{
	$this->results['widgets'][] = array('source' => 'mymodule', 'id' => 'meetings', 'template' => 'list', 'title' => 'Meetings',
		'icon' => 'agenda', 'auto' => true, 'replaces' => 'panel:mymodule_meetings', 'rights' => array(array('mymodule', 'read')),
		'url' => dol_buildpath('/mymodule/ajax/widget.php', 1).'?socid={socid}',
		'preview' => array('title' => 'Meetings', 'rows' => array(array('label' => 'Visit', 'value' => '02/12'))));
	return 0;
}
```

