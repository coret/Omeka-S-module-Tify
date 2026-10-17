#!/usr/bin/env bash
#
# Replace the bundled TIFY build in asset/vendor/tify/ with a given release.
#
#   data/scripts/update-tify.sh            the latest release on npm
#   data/scripts/update-tify.sh 0.36.2     a specific release
#
# Only the runtime files are copied: tify.js, tify.css, the translations and
# TIFY's license. The demo page and its thumbnails in dist/ are left out. The
# version is written to asset/vendor/tify/VERSION, which the module appends to
# the asset URLs to bust browser caches after an upgrade.

set -euo pipefail

cd "$(dirname "$(readlink -f "$0")")/../.."

VERSION="${1:-}"
if [ -z "$VERSION" ]; then
    VERSION="$(curl -fsS https://registry.npmjs.org/tify/latest \
        | php -r 'echo json_decode(stream_get_contents(STDIN), true)["version"] ?? "";')"
fi
[ -n "$VERSION" ] || { echo 'Cannot determine the TIFY version.' >&2; exit 1; }

BASE="https://cdn.jsdelivr.net/npm/tify@$VERSION"
TARGET=asset/vendor/tify
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

mkdir -p "$STAGE/translations"
curl -fsS -o "$STAGE/tify.js" "$BASE/dist/tify.js"
curl -fsS -o "$STAGE/tify.css" "$BASE/dist/tify.css"
curl -fsS -o "$STAGE/LICENSE" "$BASE/LICENSE"

# The file listing comes from jsDelivr's package API.
curl -fsS "https://data.jsdelivr.com/v1/packages/npm/tify@$VERSION?structure=flat" \
    | php -r '
        $data = json_decode(stream_get_contents(STDIN), true);
        foreach ($data["files"] ?? [] as $file) {
            if (preg_match("~^/dist/translations/([a-z_-]+\.json)$~i", $file["name"], $m)) {
                echo $m[1], "\n";
            }
        }' \
    | while read -r file; do
        curl -fsS -o "$STAGE/translations/$file" "$BASE/dist/translations/$file"
    done

[ -s "$STAGE/tify.js" ] && [ -s "$STAGE/tify.css" ] || { echo 'Download failed.' >&2; exit 1; }
ls "$STAGE/translations/"*.json >/dev/null 2>&1 || { echo 'No translations downloaded.' >&2; exit 1; }

echo "$VERSION" > "$STAGE/VERSION"
rm -rf "$TARGET"
mkdir -p "$(dirname "$TARGET")"
mv "$STAGE" "$TARGET"
chmod 755 "$TARGET"
trap - EXIT

echo "TIFY $VERSION installed in $TARGET ($(ls "$TARGET/translations" | wc -l) translations)."
