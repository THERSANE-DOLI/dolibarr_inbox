#!/bin/bash
# Takes DOMPurify from the npm registry into js/vendor/dompurify (purify.min.js and LICENSE, unchanged)
# Usage: dev/dompurify/update.sh 3.4.17
# TODO core: when inbox moves to the core, the library goes to htdocs/public/includes/dompurify/
set -euo pipefail
VERSION="${1:?version expected, e.g. 3.4.17}"
HERE="$(cd "$(dirname "$0")/../.." && pwd)"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
curl -fsSL "https://registry.npmjs.org/dompurify/-/dompurify-${VERSION}.tgz" -o "$TMP/p.tgz"
echo "Integrity expected (npm view dompurify@${VERSION} dist.integrity):"
npm view "dompurify@${VERSION}" dist.integrity
echo "sha512-$(openssl dgst -sha512 -binary "$TMP/p.tgz" | base64 -w0)  (downloaded)"
tar xzf "$TMP/p.tgz" -C "$TMP"
DEST="$HERE/js/vendor/dompurify"
cp "$TMP/package/dist/purify.min.js" "$TMP/package/LICENSE" "$DEST/"
sed -i "1s/.*/DOMPurify ${VERSION} (Apache-2.0 or MPL-2.0), taken by dev\/dompurify\/update.sh/" "$DEST/VERSION"
chmod 664 "$DEST"/*
sha256sum "$DEST/purify.min.js"
echo "Update dev/dompurify/README.md (version, date, integrity, sha256) and run the tests (test/phpunit/InboxSafeTest.php, dev/editor-tests)."
