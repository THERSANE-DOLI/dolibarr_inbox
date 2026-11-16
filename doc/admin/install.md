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

`stable`, `X.Y` and `edge` only go forward, a `git pull` is always enough. `stable` and `X.Y` also hold `dev/` and
`test/` (left out of the zip and of `edge`): not used by the module, they can be removed or refused by the web
server. As after any update of a module, disable and enable the module again when its version changed, so that its
database updates run.

Requirements: Dolibarr 23+, PHP 7.4+; on Dolibarr 23 the module backportdolcontext (JavaScript context of Dolibarr 24).
