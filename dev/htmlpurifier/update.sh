#!/bin/bash
# Takes HTMLPurifier from its GitHub release into lib/vendor/htmlpurifier (folder library/ and LICENSE, unchanged)
# Usage: dev/htmlpurifier/update.sh 4.19.1
# TODO core: when inbox moves to the core, the library goes to htdocs/includes/ezyang/htmlpurifier/
set -euo pipefail
VERSION="${1:?version expected, e.g. 4.19.1}"
HERE="$(cd "$(dirname "$0")/../.." && pwd)"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
curl -fsSL "https://github.com/ezyang/htmlpurifier/archive/refs/tags/v${VERSION}.tar.gz" -o "$TMP/p.tgz"
echo "sha256 of the archive: $(sha256sum "$TMP/p.tgz" | cut -d' ' -f1)"
tar xzf "$TMP/p.tgz" -C "$TMP"
SRC="$TMP/htmlpurifier-${VERSION}"
DEST="$HERE/lib/vendor/htmlpurifier"
rm -rf "$DEST/library"
cp -r "$SRC/library" "$SRC/LICENSE" "$DEST/"
sed -i "1s/.*/HTMLPurifier ${VERSION} (LGPL-2.1-or-later), folder library\/ only, taken by dev\/htmlpurifier\/update.sh/" "$DEST/VERSION"
find "$DEST" -type f -exec chmod 664 {} \;
find "$DEST" -type d -exec chmod 2775 {} \;
sed -i "s/\"ezyang\/htmlpurifier\": \"[^\"]*\"/\"ezyang\/htmlpurifier\": \"${VERSION}\"/" "$HERE/dev/vendor-versions/composer.json"
echo "Update dev/htmlpurifier/README.md (version, date, sha256) and run the tests (test/phpunit/InboxSafeTest.php)."
