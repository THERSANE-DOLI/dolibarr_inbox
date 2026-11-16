# Searchable lists (tool combo)

`js/dolibarr-combo.js` and `css/dolibarr-combo.css` define `Dolibarr.tools.combo`, a searchable list of choices
written to go into the core: no dependency (no jQuery, nothing of inbox), defined only when the core has none.
Opt-in: `<select data-combo>` (with `multiple`, `data-combo-clear="1"`, `data-combo-placeholder`,
`data-combo-source="url"` + `data-combo-min`, `<option data-sub>`; choices as chips `data-combo-chips`, colour
`<option data-color>`, tree `<option data-depth data-path>`), or `Dolibarr.tools.combo.attach(select, opts)`.
The native select stays in the page and stays the truth (value, options, native `change`, browser validation); see
the head of the file. Colours: the variables `--dol-combo-*`, set by inbox from its theme.
