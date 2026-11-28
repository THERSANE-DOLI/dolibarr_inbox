# Extend the application

The page of the application has its own head: neither the theme of Dolibarr, nor jQuery, nor the parts `css` and
`js` of the modules (written for the pages of Dolibarr) are loaded there. A module adds its files the same way:

```php
// Descriptor of the module (deactivate and activate the module again after a change, as for any module_parts)
$this->module_parts = array(
	'inboxcss' => array('/mymodule/css/inbox.css'),	// after the styles of the application
	'inboxjs' => array('/mymodule/js/inbox.js'),	// after its scripts: Dolibarr.tools.inbox is ready
);
```

Dynamic header (CSS computed from a setting...): hook `addInboxHtmlHeader`, context `inbox`, printed before
`</head>` like `addHtmlHeader` of Dolibarr (`$this->resprints`, `getNonce()` on the `<style>` and `<script>`). Own
name on purpose: the modules on `addHtmlHeader` (often context `all`, code for jQuery) never enter the application.

HTML built by a script of a module: `Dolibarr.tools.inbox.html` is a tagged template that escapes every value, so
that a value of a mail or of a user never becomes HTML by mistake; HTML already safe passes through
`Dolibarr.tools.inbox.raw()` or another `html` template:

```js
const { html, raw, ic, t } = Dolibarr.tools.inbox;
el.innerHTML = html`<span class="mymodule-badge">${raw(ic('tag'))} ${t('MyModuleLabel')}: ${value}</span>`;
```

Rules for the styles of a module:

- its own classes, prefixed by the name of the module; never restyle the `.ibx-*` classes of the application;
- the variables of the application, its public API: they follow the light, dark and high contrast themes, the accent
  colour and the density. Surfaces `--ibx-bg`, `--ibx-bg-pane`, `--ibx-bg-sunken`, `--ibx-bg-hover`; text
  `--ibx-text`, `--ibx-text-muted`, `--ibx-text-faint`; lines `--ibx-border`, `--ibx-border-strong`; accent
  `--ibx-accent`, `--ibx-accent-hover`, `--ibx-accent-soft`, `--ibx-accent-softer`; states `--ibx-success`,
  `--ibx-warning`, `--ibx-danger`, `--ibx-info` and their `-soft`; spaces `--ibx-s1` to `--ibx-s6`; text sizes
  `--ibx-fs-xs`, `-sm`, `-md`, `-body`, `-lg`, `-xl`; `--ibx-font`, `--ibx-font-mono`; corners `--ibx-radius-sm`,
  `--ibx-radius`, `--ibx-radius-lg`; `--ibx-shadow-float`.

Indicators of the card of the thirdparty (Settings > Mailboxes > Usage): hook `inboxSituationIndicators`, context
`inbox`, called twice with `$parameters['mode']`:

```php
public function inboxSituationIndicators($parameters, &$object, &$action, $hookmanager)
{
	if ($parameters['mode'] === 'catalogue') {
		// Id prefixed by the name of the module; usages whose list has it by default (sales, accounting, support,
		// purchase, transport)
		$this->results['indicators'] = array('mymodule_prepaid' => array('label' => 'Prepaid time left', 'rule' => 'alert at 0', 'usages' => array('support')));
	} elseif ($parameters['mode'] === 'compute' && in_array('mymodule_prepaid', $parameters['ids'], true)) {
		// $parameters['socid'], $parameters['user']: check the rights here, give nothing without them
		$this->results['values'] = array('mymodule_prepaid' => array('value' => '0 h', 'level' => 'alert', 'short' => 'No prepaid time left',
			'url' => dol_buildpath('/mymodule/list.php', 1).'?socid='.(int) $parameters['socid']));
	}
	return 0;
}
```

Level `alert`, `attention` or `normal` (only the first two show on the closed card, `short` is that text); the
address stays inside Dolibarr; values of other modules are never shown for an indicator the user cannot read.

## Capabilities of inbox

The hooks of a mail (`inboxMailLinks`, `inboxMailActions`, `inboxMailAlerts`, `inboxMailPanels`) receive
`$parameters['capabilities']` (1.160.0): the words of what this inbox knows how to do. A module switches by these words,
never by the version of inbox (branches and backports make a version number wrong); an older inbox gives none.

| Word | Since | Meaning |
|---|---|---|
| `agenda_create` | 1.160.0 | « Create > Event » opens the page of creation of the agenda provider (`inboxAgendaProvider`) and links the event saved there to the mail ([agenda.md](agenda.md)) |

```php
$caps = $parameters['capabilities'] ?? array();
if (!in_array('agenda_create', $caps, true)) {
	$this->results['actions'][] = array('label' => 'Plan a meeting', 'icon' => 'event-new', 'window' => true, 'url' => $url);
}
```

## Blocks of a module in the panel of a mail

Hook `inboxMailPanels`, context `inbox`: `$parameters` gives `mail` (row of the index), `account`, `message_id`,
`from`, `subject`, `mailref`, `socid`, `contactid`. The method adds to `$this->results['panels']` an `id`, a `title`,
an `icon` (usage name) and a `url` inside Dolibarr. The panel reads that address after it is shown and expects JSON:

- `badges` (6 at most, `label` and `level`: `ok`, `warning`, `danger`, `info`, `neutral`), `lines` (4 at most),
  one `action` (`label`, `url`, `title`, `primary`) opened in a window of inbox, `links` (3 at most);
- `pending` with `pending_text` and `retry_ms`: read again while the module is still working;
- `error`: shown instead of the summary;
- `hidden: true` (1.134.0): the block goes away. For a module that can only tell after reading the mail whether it
  has something to show; keep the answer so that the hook gives no block the next time.

Every text is escaped by the panel. Check the rights in the page: the hook runs for any mail the user opens.

With the widgets of the panel (1.157.0), each block is a widget of the template `free` (id `panel:` and the `id` of
the block): it comes by itself at the end of the grid, the user may move or remove it ([Widgets of the Dolibarr
panel](widgets.md)). Keep the `id` of a block stable: it is the key of its place in the layouts of the users. The
module actions (`inboxMailActions`) are the widget `mail_actions` in the same way.

A block of the panel is for a short summary. For a rich content (figures, charts, lists), prefer a column of the
strip (below): it is read only when the user opens it, never at each mail opened.

## Columns of a module in the strip on the right

Since 1.153.0 the strip on the right of the mail space (Dolibarr, Overview, Today) takes the columns of the modules,
three at most, after the overview of inbox. Hook `inboxSidePanes`, context `inbox`, called once when the application
loads (not for each mail): `$parameters` gives `user`. The method adds to `$this->results['panes']`, for each column:

- `id` (`[a-z0-9_-]`, 40 at most), `title`, `icon` (usage name of the sprite, `dev/icons.json`);
- `url`: an address inside Dolibarr (one starting slash, no space nor quote), read while the column is shown with
  `?mail=<row of the index>` added (`&mail=` when it already has a query), then again when the user opens another mail;
- `needs_mail` (default true): the icon is greyed without a mail open.

The column is docked beside the mail, a drawer when the window is narrow, as the other columns; the choice of the
column is kept (`side_panel` = `mod:<id>`). On a phone the columns of the modules are not offered yet.

The address answers JSON in the format of the overview, drawn by inbox (every text escaped, links kept inside
Dolibarr):

- `tags`, `alerts`: lists of `{label, level}` (`ok`, `info`, `warning`, `danger`, `neutral`), shown as chips;
- `kpis`: up to 4 `{label, value, note, level, delta}`, `delta` = `{text, level, dir: up|down, title}`;
- `note`: `{label, text}`;
- `sections`: `{id, title, sum, sumLevel, open, url, rows, hero, chart, spark, season, mails, mailsLabel}`, `rows` =
  `{label, value, note, level, url, item}`, `chart`/`spark`/`season` = `{label, value, hint, current, bars}` with
  `bars` = `{x, v, short, text, partial}`, `mails` = `{id, folder, out, date, subject, current}`;
- `labels`: `{open, received, sent, current, empty}` for the list of mails;
- `hidden: true` or `error`: a short text instead of the content.

The page reads the mail itself from `mail` and checks every right: the browser gives nothing else, and the user may
have been given a column for a mail he cannot read. A module that draws its column itself listens to the JavaScript
hook `inbox:sidepane:render` (context `id`, `mail`, `el`, `data`, `handled`), draws in `el` (escape what it shows) and
sets `handled = true`.

## Open a mail from a module

A module that lists mails (its column, its window) opens one in the mail space with the JavaScript hook
`inbox:mail:goto`: `Dolibarr.executeHook('inbox:mail:goto', { folder: <row of the folder>, mail: <row of the mail> })`,
the same jump as the links of inbox between two mails. Nothing happens for a mail the user cannot read.

## Viewers of the attachments

Inbox shows itself only what a mail needs to be read: PDF and pictures (by the browser), text, CSV, zip, attached
email. Every other format is shown by a module: never add a reader of a file format to Inbox, write or complete a
viewer module. DocStudio, the free viewer recommended by the setup page of Inbox, implements this hook for PDF,
pictures and office files: look at it before writing your own. DocStudio is the viewer code that once lived in
Inbox, taken out into a module of its own so that every document of Dolibarr (files of the cards, DMS) benefits from
it, not only the attachments of the mails.

Hook `inboxAttachmentViewers`, context `inboxattachment`, called when a mail with attachments is opened.
`$parameters` gives `mail` (row of the index) and `attachments`: part => `part`, `name`, `extension` (lower case),
`size`. The method answers, for the parts it can show, a list of viewers in `$this->results['inboxAttachmentViewers']`
and, for « Annotate and file », a list of editors in `$this->results['inboxAttachmentEditors']`:

```php
public function inboxAttachmentViewers($parameters, &$object, &$action, $hookmanager)
{
	$viewers = array();
	foreach ($parameters['attachments'] as $part => $a) {
		if ($a['extension'] === 'dwg') {
			$viewers[$part][] = array('code' => 'mymodule_dwg', 'label' => 'Plan viewer',
				'url' => dol_buildpath('/mymodule/view.php', 1).'?mail='.(int) $parameters['mail'].'&part='.(int) $part);
		}
	}
	$this->results['inboxAttachmentViewers'] = $viewers;
	return 0;
}
```

- `url` must be a relative address of this site (`/...`): any other one is dropped, as an entry without `label`.
  `code` keeps only `a-z`, `0-9` and `_`; `label` is cut at 80 characters. Inbox opens the first viewer of a part in
  its preview (« Open with… » lists the others).
- Inbox adds `force=1` to the address when the user opens an attachment not scanned by the antivirus anyway; to an
  editor address, it adds `record_type` and `record_id`, the piece chosen by the user.
- The page of the module loads the attachment with `InboxAttachmentFile` (`class/inboxattachmentfile.class.php`),
  with the user who asks: `load($user, $mail, $part, $force)` checks the access to the mailbox and the antivirus
  (an infected file is never given), then `getFileName()`, `getSize()`, `getContent()`. Read only: an editor puts
  its new file in the documents of the piece with `fileToRecord()` (same checks and link to the mail as « Put in the
  documents of »). Never a path nor an address of the mail server.
