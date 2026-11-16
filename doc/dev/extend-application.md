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
