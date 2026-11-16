# New customer: hook for modules

What the panel does: [New customer from a mail](../admin/new-customer.md).

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
