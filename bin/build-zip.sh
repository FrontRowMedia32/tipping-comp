#!/usr/bin/env bash
# Builds dist/tipping-comp-<version>.zip containing a tipping-comp/ folder,
# ready for Plugins > Add New > Upload Plugin or a GitHub release asset.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
V="$(grep -oP "define\( 'TIPC_VERSION', '\K[0-9.]+" "$ROOT/tipping-comp.php")"
HEADER="$(grep -oP '^\s*\*\s*Version:\s*\K[0-9.]+' "$ROOT/tipping-comp.php")"
STABLE="$(grep -oP '^Stable tag:\s*\K[0-9.]+' "$ROOT/readme.txt")"
if [ "$V" != "$HEADER" ] || [ "$V" != "$STABLE" ]; then
	echo "Version mismatch: TIPC_VERSION=$V header=$HEADER readme=$STABLE" >&2
	exit 1
fi
TMP="$(mktemp -d)"
mkdir -p "$TMP/tipping-comp" "$ROOT/dist"
( cd "$ROOT" && git ls-files -z 2>/dev/null || find . -type f -print0 ) \
	| ( cd "$ROOT" && xargs -0 -I{} cp --parents {} "$TMP/tipping-comp/" )
rm -rf "$TMP/tipping-comp/tests" "$TMP/tipping-comp/bin" "$TMP/tipping-comp/dist" \
	"$TMP/tipping-comp/CLAUDE.md" "$TMP/tipping-comp/MAINTENANCE.md" "$TMP/tipping-comp/HANDOVER.md" "$TMP/tipping-comp/.gitattributes" "$TMP/tipping-comp/.gitignore"
rm -f "$ROOT/dist/tipping-comp-$V.zip"
( cd "$TMP" && zip -qr "$ROOT/dist/tipping-comp-$V.zip" tipping-comp )
rm -rf "$TMP"
echo "$ROOT/dist/tipping-comp-$V.zip"
