# Inbox for [Dolibarr ERP & CRM](https://www.dolibarr.org)

Mail foundation used by other modules (first one: quoteassist, customer price request prefill).

- `Inbox::getMessage($mailbox, $messageId, $user, $origin)`: one mail read in a mailbox of inbox (or given by another
  module through the hook `inboxResolveAccount`), from its complete Message-ID, read only, cached, journaled.
- `Inbox::fetchRaw(...)`: same, raw RFC 822 source (to attach it to a record).
- `Inbox::parseContent($content)` / `Inbox::getMessageFromFile($path)`: .eml or Outlook .msg given as a file.
- Result: `InboxMessage` (headers, text and HTML bodies, attachments, attached mails).

Cache: the raw mails read in the mailboxes are kept in `documents/inbox/raw/` (30 days by default, purged every night).
This directory can be emptied at any time: a missing mail is read again on the mail server when needed. It holds a
README.txt saying so.

Requirements: Dolibarr 23+, PHP 7.4+; on Dolibarr 23 the module backportdolcontext (JavaScript context of Dolibarr 24).

Other modules plug into inbox, never the reverse: hooks `inboxResolveAccount`, `inboxAccountImportSources`,
`inboxConnectors`, `addInboxHtmlHeader`, `inboxSituationIndicators` (context `inbox`), the module_parts `inboxcss` and
`inboxjs` (see « Extend the application ») and the triggers of its objects.

Licence: GPLv3, see COPYING.

## Installation

- Zip: `module_inbox-X.Y.Z.zip` of the [GitHub releases](https://github.com/THERSANE-DOLI/dolibarr_inbox/releases/latest),
  deployed in Setup > Modules > Deploy an external module.
- Git deployment (Plesk, `git pull`...): clone the repository in `htdocs/custom/inbox` and follow the branch that
  fits the instance:

| Branch | What it holds | Use it for |
|---|---|---|
| `stable` | the last published version (the one of the latest release) | **production** (recommended) |
| `X.Y` (`1.83`...) | one published series and its fixes only | a production that must stay on a series |
| `release` | every change of `develop` as soon as it is pushed, before any release, without `dev/` nor `test/` | a test or preproduction instance that follows the work in progress |
| `develop` | the work in progress, with the development tools and the tests | development only |

`stable`, `X.Y` and `release` only go forward, a `git pull` is always enough. `stable` and `X.Y` also hold `dev/` and
`test/` (left out of the zip and of `release`): not used by the module, they can be removed or refused by the web
server. As after any update of a module, disable and enable the module again when its version changed, so that its
database updates run.

## Inbox application

`index.php` opens the application (right « Use the webmail »): Settings > Mailboxes (right « Manage the mailboxes »),
General, My account. The reading of the mails comes with the next lots.

## Writing editor

The writing, the signatures and the templates use the editor of Inbox (Tiptap, in the module) by default. Settings >
General > Writing editor offers the CKEditor of Dolibarr instead, when the module « WYSIWYG editor » (FCKeditor) is
on with CKEditor as its editor (`FCKEDITOR_EDITORNAME` empty or `ckeditor`). It is loaded like in the pages of
Dolibarr: `includes/ckeditor/ckeditor/` or the external CKEditor 4 of `JS_CKEDITOR`, the configuration of the theme
(`theme/<theme>/ckeditor/config.js`), the skin `FCKEDITOR_SKIN`.

- Toolbar and context menu of Inbox (default): inline CKEditor without its bar nor menu, driven by the toolbar, the
  menus and the bubbles of Inbox; the same features as the Inbox editor (tables, pictures, variables of the templates
  as chips, AI, voice typing).
- Those of CKEditor (`INBOX_CKEDITOR_NATIVE`): the classic CKEditor of Dolibarr, toolbar `dolibarr_mailings` and its
  context menu; voice typing, AI and the variables of Inbox are the last group of that toolbar.

Small screens (`INBOX_EDITOR_SMALL`, Settings > General > Writing editor > « On a small screen », 1.101.0): under
the width of the phone layout (64rem, 1024 px) a writing opens with the Inbox editor (default), CKEditor driven by the
toolbar of Inbox (never its own rows of buttons, whatever `INBOX_CKEDITOR_NATIVE` says), or the same editor as on a
computer (`same`). Decided when the writing opens: turning the screen or resizing the window while typing keeps the
editor. The width of the writing window itself is not used (the window docked on a computer is narrow too).

When CKEditor cannot start (files missing, blocked by the browser), the writing opens with the Inbox editor. Another
module can register its own editor: event `inbox:editor:register`, interface described at the top of
`js/inbox-editor.js`.

Signature of the writing: by default it follows the identity (shown under the text, added by the server at the
sending, marked `div.inbox-signature`). Its pencil puts it in the text to change it for one mail only, marked
`div.inbox-signature-edited`: kept as it is in the drafts (never replaced by the one of the identity), sent once, the
« -- » delimiter of the text part written before it as for the other one.

## Extend the application

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

## Mails sent by a site: data sources

A quote form, a shop or an application sends its notifications from its own address: Settings > Data sources (admins
of Dolibarr) says which senders to trust (addresses or domains, a domain covers its subdomains) and which headers
name the real requester. Format advised for a module that sends such mails:

```
X-Data-Form-Email: jean.dupont@example.com
X-Data-Form: {"firstname":"Jean","lastname":"Dupont","company":"Dupont SARL","phone":"+33 1 23 45 67 89","siren":"123456789","vat":"FR12123456789","id":"D-1042"}
```

JSON on one line, 2000 characters at most, RFC 2047 when it holds accents, each header once. Usual key names are
understood (`email`, `firstname`, `lastname`, `company`, `phone`, `siren`, `vat`, `address`, `zip`, `town`, `id`),
others are mapped on the source; one header per field is possible too (`X-Form-Phone = phone`).

The headers are believed only for a trusted sender that is authenticated (DMARC, or DKIM of its domain, read on the
`Authentication-Results` of the receiving servers of Settings > Server). Then the thirdparty is looked for on the
address of the requester, the list shows him with the badge of the source, the creation pages are filled with his
data and « Reply » writes to him. Nothing is written in Dolibarr by the headers alone. The source given at the
installation (`X-Quotation-Mail`, `X-Quotation-Data`) is disabled and has no trusted sender.

## Public and shared domains

Settings > Public and shared domains (right « Manage the mailboxes »): the public mail services, the disposable addresses and the
domains shared by several of our thirdparties never designate a thirdparty by their domain; for a shared domain the
thirdparties that use it are offered to choose. The list shipped in `data/domains/` (`public.txt` kept by hand,
`disposable.txt` copied from a CC0 list, see `data/domains/LICENSES.md`) is read in its files, a domain looked for
as a whole line of the text; the database keeps only the domains added and the shipped ones disabled. inbox never
downloads the list: a new version of the module brings it. Before a version: `php dev/update-domains.php` (refreshes `disposable.txt` and
`sources.json`, shows what changed) or `--local` after a change of `public.txt` or `never.txt` (rewrites
`public.txt` normalized: lower case, sorted, LF; a test checks the format and the prints).

Other modules add their domains with the hook `inboxSharedDomains` (context `inbox`):
`$this->results['domains'] = array('example-group.com' => 'shared')` (categories `public`, `disposable`, `shared`);
they show with the origin « other module », read only.

## Blocked senders and anti-spam

Settings > Anti-spam (1.85.0): one list of blocked senders (addresses, or domains with their subdomains) for all the
mailboxes of the entity; their mails go to the junk, never to the trash. Blocked from a mail (menu ⋯ > Block the
sender…): an unknown sender with the level « manage » on the mailbox of the mail; a sender known to Dolibarr or a
domain with the right « Manage the blocked senders » (strong confirmation for a known sender). Never blockable: the
users of Dolibarr, the mailboxes of inbox and their domains, the recognized domains, the senders of the data sources,
the public mail services as domains.

How it is applied, and what changes on the mail server:

- **Sieve**: the scheduled job « Synchronise the mailboxes » writes a block at the top of the script `inbox` of each
  mailbox (before the rules and the out of office), marked `# Blocked senders of inbox`. It is never shown in the
  rules editor and is written again when the rules are saved. A mailbox whose script was changed by another tool,
  or whose active script belongs to another tool, is never written: Settings > Anti-spam says so.
- **Synchronisation**: on a mailbox without Sieve, with a script of another tool, or while the block is not written
  yet, the new mails of the inbox from a blocked sender are moved to the junk by the synchronisation (journal:
  « put in junk (blocked sender) »). A mail marked « not junk » ($NotJunk) is never moved.
- **Turn it off**: Settings > Anti-spam, switch « Blocked senders » (`INBOX_BLOCKED_SENDERS`): the block is removed
  from the scripts at the next pass, the list is kept.

Anti-spam engine of inbox (1.88.0, off by default): Rspamd installed **beside Dolibarr**, never on the mail server,
so that the mails do not leave our servers. inbox calls `/checkv2` (port 11333) for the new mails of the inbox and
the controller (port 11334, its password in Settings > Anti-spam > Settings) for `/learnspam`, `/learnham`, `/stat`.

- Docker (tests and production): `dev/rspamd/docker-compose.yml`, two services: Rspamd (ports on 127.0.0.1) and Redis
  (its learning, on an internal network: reached by Rspamd only, no way out). Start with
  `RSPAMD_PASSWORD='...' dev/rspamd/run.sh` (writes the hashed password of the controller, then `docker compose up
  -d`; restarted with the server); without `RSPAMD_PASSWORD`, a random password shown once. Type the same password
  in Settings > Anti-spam. `dev/rspamd/run.sh stop` stops both. Rspamd still asks the DNS for the SPF, DKIM and
  DMARC records of the senders of the mails it judges (the hour of arrival and the resolver of the server show).
- Without Docker: the Debian package of rspamd with redis-server, and the same `local.d/` and `override.d/` files.
  Publish the ports on 127.0.0.1 only. These files (checked on Rspamd 3.11.1, 2026-11-11):
  - turn off what asks outside: public fuzzy storage of rspamd.com, update of the rules from updates.rspamd.com,
    lists downloaded from maps.rspamd.com (multimap, whitelist; mid and mime_types read the copies of the image),
    phishing lists, ASN zone, following of short links, monitoring, lists of reputation by DNS (rbl);
  - turn off what is made for a mail server and ends the check without a score: greylist, ratelimit.
  `local.d/` merges its lists with the defaults (a remote list stays): `override.d/` replaces them. Check it once
  installed: no « https:// » in `docker logs`, and the connections of the container (`/proc/net/tcp`) go to Redis
  only, DNS apart (SPF, DKIM, DMARC of the senders).
- The lists of reputation by DNS (Spamhaus, SURBL...) need two keys: `enabled = true;` in `local.d/rbl.conf` and the
  switch of Settings > Anti-spam (inbox turns the groups rbl and surbl off at each check otherwise, header
  `Settings`). They refuse the public resolvers (8.8.8.8...):
  give Rspamd a local resolver (unbound) before turning them on; Spamhaus is free only for a low volume and non
  commercial use (else its DQS key in `local.d/rbl.conf`).

Living with another anti-spam tool: a tool that takes the known senders out of the junk (authenticated mails of the
customers and suppliers) does not know this list; a known sender blocked here may come back to the inbox through it.
Add the address to the blacklist of that tool as well, or turn its rescue off for that sender.

## Searchable lists (tool combo)

`js/dolibarr-combo.js` and `css/dolibarr-combo.css` define `Dolibarr.tools.combo`, a searchable list of choices
written to go into the core: no dependency (no jQuery, nothing of inbox), defined only when the core has none.
Opt-in: `<select data-combo>` (with `multiple`, `data-combo-clear="1"`, `data-combo-placeholder`,
`data-combo-source="url"` + `data-combo-min`, `<option data-sub>`; choices as chips `data-combo-chips`, colour
`<option data-color>`, tree `<option data-depth data-path>`), or `Dolibarr.tools.combo.attach(select, opts)`.
The native select stays in the page and stays the truth (value, options, native `change`, browser validation); see
the head of the file. Colours: the variables `--dol-combo-*`, set by inbox from its theme.

## New customer from a mail

On a mail of an unknown sender, the Dolibarr band offers « New customer »: a side panel on the left, the mail left
readable on the right. It shows what the mail says of its sender, each value with its source (data source headers,
vCard, display name, signature, « Label: value » lines, domain), the thirdparties that may be the same one, and
creates the thirdparty and its contact in one click (« Create ») or opens the page of Dolibarr filled (« Full
page… », the contact follows when the thirdparty is saved). The mail and its conversation are linked, no copy of the
mail is kept.

The panel asks what the page of creation asks: rules of the core, mandatory settings of the thirdparties, mandatory
extrafields and the mandatory fields per page (Setup > Default values, `societe/card.php` and `contact/card.php`),
checked again on the server. A mandatory field the panel cannot fill (an extrafield, another field of the page)
sends the user to the full page. The default values of the forms of creation are the first values of the panel.
The quick creation calls the classes of Dolibarr: the triggers of the modules run, the page of creation (and what a
module adds to it) does not.

A module adds values and fields with the hook `inboxLeadExtract` (context `inbox`):

```php
public function inboxLeadExtract($parameters, &$object, &$action, $hookmanager)
{
	// $parameters['mail'] (row of the index), $parameters['message'] (InboxMessage), $parameters['found'] (values found)
	$this->results['values'] = array(array('part' => 'soc', 'field' => 'idprof2', 'value' => '99910000100075', 'source' => 'My register'));
	// A field of the page of creation (an extrafield: options_xxx), shown in the panel and sent to the page
	$this->results['fields'] = array(array('part' => 'soc', 'field' => 'options_naf', 'label' => 'Activity code'));
	return 0;
}
```

In the browser (file of `inboxjs`), the event `inbox:lead:render` on `document` gives `{ panel, slot, mail, found,
get(part, field), set(part, field, value, source) }`: a module appends its block to `slot` (a search in a register of
companies from the SIRET found, for example) and fills the panel with `set()`; the panel is drawn again after a
`set()`, the event comes again.

## Development

- Icons: `dev/icons.json` (usage name => Tabler icon) and `dev/tabler/` (Tabler Icons 3.50.0, MIT license, see
  `dev/tabler/LICENSE`); `php dev/build-icons.php` builds `img/inbox-icons.svg` and the icons of the application.
  To choose new icons: put the npm package `@tabler/icons` of the same version (folders `icons/`, file `icons.json`)
  in `dev/bin/tabler-3.50.0` (ignored by git), run `php dev/build-icons.php --picker` and open
  `dev/bin/icon-picker.html`; the files of the icons added to `icons.json` are copied from the package at the build.
- Local test mail server: `dev/dovecot/run.sh` (Docker, IMAP 127.0.0.1:10143, Sieve 14190, SMTP submission 10587,
  test values only) then `python3 dev/dovecot/seed.py`.
- Tests: `INBOX_DOLIBARR_HTDOCS=/path/htdocs DOLI_TEST_DB=<test database> ~/bin/phpunit -c test/phpunit/phpunit.xml`,
  on a test database only (the bootstrap refuses the others). The HTTP tests need `INBOX_TEST_URL`,
  `INBOX_TEST_LOGIN`, `INBOX_TEST_PASSWORD` of a test instance.
- Catalogue of the components: `ux.php`.
- Copies of Dolibarr to keep in step at each major version of Dolibarr:
  - `css/inbox-doltip.css`: the classes of the theme eldy met in the tooltips of Dolibarr (`getTooltipContent`),
    redrawn with the variables of the application for the cards of the objects. A class renamed or added in the
    tooltips of Dolibarr is changed there.
  - the card of an object calls `core/ajax/ajaxtooltip.php` with the `data-params` read in `getNomUrl()`
    (`InboxElements::describe()`): to check if the core changes this attribute or this page.

Credits: icons [Tabler Icons](https://tabler.io/icons) (MIT).

