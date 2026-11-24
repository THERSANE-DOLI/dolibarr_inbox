# Development

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

## Branches and publication

| Branch | Receives | Role |
|---|---|---|
| `develop` | everything (NEW, FIX, QUAL...) | the next versions, version raised at each NEW / FIX |
| `X.Y` | **FIX only** | a series: created from develop at the freeze, release candidate, then published series |
| `stable`, `rc`, `edge` | nothing (moved by the CI) | highest published version; newest `X.Y`; build of develop without `dev/` nor `test/` |

1. **Freeze** (a candidate is wanted): `git push origin develop:1.135` (the version of develop at that time). `rc`
   moves on it (`.github/workflows/rc.yml`).
2. **Fixes of the candidate or of a published series**: on its branch `X.Y`, Z raised (1.135.1) with its ChangeLog
   entry, then merged into develop: `dev/merge-series.sh 1.135` (solves the line of the version, develop keeps its
   own, and the ChangeLog, both entries kept), `git push origin develop`. A fix that also concerns an older series
   still maintained goes on the oldest one, merged into the newer ones then develop.
3. **Publish**: `git tag v1.135.1 origin/1.135 && git push origin v1.135.1`. The tag must be the version of the
   descriptor and be on its `X.Y`. Zip, GitHub release (Latest when it is the highest version), `stable` moved
   (`.github/workflows/release.yml`, `.github/release.sh`).

Before a freeze, every series still maintained is merged into develop: `rc` and `stable` only move forward, they
refuse a series that does not hold the previous one (warning in the run, nothing forced).

Credits: icons [Tabler Icons](https://tabler.io/icons) (MIT), [DOMPurify](https://github.com/cure53/DOMPurify)
(Apache 2.0 or MPL 2.0), [HTML Purifier](http://htmlpurifier.org) (LGPL 2.1 or later).
