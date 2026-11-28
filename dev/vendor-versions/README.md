# Versions of the shipped libraries

The libraries of lib/vendor and js/vendor are copied unchanged into the module, without Composer nor npm. The two
manifests of this folder only declare their versions so that Dependabot (.github/dependabot.yml) opens a PR and a
security alert when a new version comes out. Nothing is installed from them.

| Library | Folder | Update |
|---|---|---|
| ezyang/htmlpurifier | lib/vendor/htmlpurifier | dev/htmlpurifier/update.sh <version> |
| dompurify | js/vendor/dompurify | dev/dompurify/update.sh <version> |
| jsqr | js/vendor/jsqr | by hand (1.4.0 is the last release, the project is no longer maintained) |
| gridstack | js/vendor/gridstack | by hand: dist/gridstack-all.js and dist/gridstack.min.css of the npm package, VERSION (checksums) |

On a Dependabot PR: check out its branch, run the update script with the new version (it writes the same version
here), update the README of the library (version, date, checksums), run the tests, then merge.
