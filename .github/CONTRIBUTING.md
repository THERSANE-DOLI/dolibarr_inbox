# Contributing to Inbox

Thank you for helping. Read [AGENTS.md](../AGENTS.md) first: the rules of every change, for people and coding agents
alike, and the checklist before a pull request.

## Which branch for my pull request?

| Your change | Base branch of the pull request |
|---|---|
| a new feature (NEW), an improvement of the code (QUAL, PERF), documentation (DOC) | `develop` |
| a fix (FIX, SEC) | `develop`, or the branch `X.Y` of the series it concerns (`1.135`...) when that series needs it |
| anything | never `stable`, `rc` nor `edge`: they are moved by the CI only |

| Branch | What it is |
|---|---|
| `develop` | the next versions, where the work happens |
| `X.Y` | a series: created from develop at the freeze, release candidate, then published versions. **Fixes only**; each fix is merged back into develop by the maintainers |
| `rc` | the newest series, for the instances that test it before it is published |
| `stable` | the last published version |
| `edge` | a build of develop for deployment, without the tools and the tests |

Not sure? Open it on `develop`. The base branch can be changed later on the pull request (Edit, next to the title).

## Title

`KEYWORD: short summary`, the keyword among `NEW`, `FIX`, `QUAL`, `SEC`, `DOC`, `PERF`. Example:
`FIX: buttons at the foot of a form wrap on a phone`. The keyword decides the version raised (Y for a NEW, Z for a
FIX, see AGENTS.md) and the screenshots asked.

## Screenshots

A pull request that changes what the user sees (`css/`, `js/`, `tpl/`, `img/`) shows it in its description, in the
section Screenshots of the template: **before and after** for a FIX, one at least for a NEW. The reviewer sees the
change without installing the branch. Computer and phone widths when the layout is concerned.

## Checks run on every pull request

| Check | What it asks |
|---|---|
| PR branch | title keyword; base branch as above |
| PR screenshots | screenshots of a visual change in the description |
| cli-guard | every PHP file of `dev/` and `test/` starts by refusing the web (`php dev/check-cli-guard.php`) |

The tests (PHPUnit, on a test database only) are run by you before the pull request, see
[doc/dev/development.md](../doc/dev/development.md).
