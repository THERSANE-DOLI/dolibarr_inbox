# Public and shared domains

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
