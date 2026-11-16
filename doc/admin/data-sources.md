# Mails sent by a site: data sources

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
