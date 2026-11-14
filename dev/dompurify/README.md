# DOMPurify (HTML of a mail cleaned in the browser, Dolibarr.tools.inbox.safeHtml)

- Package: dompurify 3.4.17, licence Apache-2.0 or MPL-2.0 (js/vendor/dompurify/LICENSE), https://github.com/cure53/DOMPurify
- Taken on 2026-11-14 from https://registry.npmjs.org/dompurify/-/dompurify-3.4.17.tgz
- Integrity of the archive (npm): sha512-B/pSlYVHQ58kqISD/yDjuJ1yfzI34m1gF4lAod2bwSRhiN4K5Cl3H8Af7hqB2PmTL911tdEudnnXn5FnhRoz0w==
- File kept: package/dist/purify.min.js, unchanged, sha256 cd37898f9782adf54820c413191b5691803f0f137c799d6edcfd38523b4c2c31
- Loaded by index.php before js/inbox-safe.js; update with dev/dompurify/update.sh <version>
- TODO core: when inbox moves to the core, the library goes to htdocs/public/includes/dompurify/ (+ line in COPYRIGHT),
  loaded by the core, and safeHtml() becomes Dolibarr.tools.safeHtml
