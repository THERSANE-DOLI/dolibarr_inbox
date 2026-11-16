# New customer from a mail

For the modules that add values and fields, see [New customer: hook for modules](../dev/new-customer.md).

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
