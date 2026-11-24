# Installation

- Zip: `module_inbox-X.Y.Z.zip` of the [GitHub releases](https://github.com/THERSANE-DOLI/dolibarr_inbox/releases/latest),
  deployed in Setup > Modules > Deploy an external module.
- Git deployment (Plesk, `git pull`...): clone the repository in `htdocs/custom/inbox` and follow the branch that
  fits the instance:

| Branch | What it holds | Use it for |
|---|---|---|
| `stable` | the last published version (the one of the latest release) | **production** (recommended) |
| `X.Y` (`1.83`...) | one published series and its fixes only | a production that must stay on a series |
| `edge` | every change of `develop` as soon as it is pushed, before any release, without `dev/` nor `test/` | a test or preproduction instance that follows the work in progress |
| `develop` | the work in progress, with the development tools and the tests | development only |

`stable`, `X.Y` and `edge` only go forward, a `git pull` is always enough. As after any update of a module, disable
and enable the module again when its version changed, so that its database updates run.

## Folders dev/ and test/ of a Git deployment

`stable`, `X.Y` and `develop` also hold `dev/` and `test/` (left out of the zip and of `edge`): the development tools
and the tests, not used by the module. None of their PHP files runs from the web (each one refuses anything but the
command line), but their other files (configurations of the tests, trapped mails used by the tests) should not be
readable. The setup page of the module checks it from the browser and warns only when one of them can be read.

To take them away, pick one:

- **Git clone** (`git pull` in `htdocs/custom/inbox`): in the folder of the module,
  `git sparse-checkout set --no-cone '/*' '!/dev/' '!/test/'` (Git 2.25+). Git takes them off the disk and keeps them
  off at every `git pull`; `git sparse-checkout disable` puts them back. Never delete them by hand in a clone: the
  next `git pull` that changes them would fail.
- **Copy by a deployment tool** (no `.git` in the folder of the module, Plesk...): an action after each deployment,
  in the folder of the module: `rm -rf dev test`.
- **Refused by the web server**, see below.

Requirements: Dolibarr 23+, PHP 7.4+; on Dolibarr 23 the module backportdolcontext (JavaScript context of Dolibarr 24).
Advised: the PHP extension `intl` (package php-intl). Without it the domains written with accents or letters of other
alphabets, the most deceptive imitations of a known domain, are neither recognized nor told; the setup page of the
module says so.

Files never served: the `.htaccess` of the module refuses its texts (`ChangeLog.md`, notes, lists read by PHP: `md`,
`txt`, `json`, `sh`, `yml`, `lock`...) and the folders `dev/`, `test/`, `lib/vendor/` (Apache with `AllowOverride` that
allows `Require`, as `conf/.htaccess` of Dolibarr). With nginx, write the same in the server block:

```nginx
location ~ ^/custom/inbox/(dev|test|lib/vendor)/ { deny all; }
location ~ ^/custom/inbox/.*\.(md|txt|json|sh|yml|yaml|lock|dist|log)$ { deny all; }
```
