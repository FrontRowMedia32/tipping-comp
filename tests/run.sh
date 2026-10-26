#!/usr/bin/env bash
# Runs the Tipping Comp test suite against a real WordPress install on SQLite.
#
#   tests/run.sh            # latest stable WordPress
#   tests/run.sh 6.8.3      # a specific WordPress version
#   tests/run.sh nightly    # WordPress development version (trunk)
#
# Needs: php (with pdo_sqlite), git. No MySQL. Network access to github.com.
# All data feeds are mocked, so no API keys or credits are used.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
WORK="${TIPC_TEST_DIR:-$(mktemp -d)}"
WPV="${1:-latest}"

if [ "$WPV" = "latest" ]; then
	WPV="$(git ls-remote --tags https://github.com/WordPress/WordPress.git \
		| grep -oE 'refs/tags/[0-9]+\.[0-9]+(\.[0-9]+)?$' | sed 's#refs/tags/##' | sort -V | tail -1)"
fi

echo "== Tipping Comp tests: WordPress $WPV, PHP $(php -r 'echo PHP_VERSION;')"
echo "== Work dir: $WORK"

if [ ! -d "$WORK/wp" ]; then
	if [ "$WPV" = "nightly" ]; then
		git clone -q --depth 1 https://github.com/WordPress/WordPress.git "$WORK/wp"
	else
		git clone -q --depth 1 --branch "$WPV" https://github.com/WordPress/WordPress.git "$WORK/wp"
	fi
fi
if [ ! -d "$WORK/sqlite" ]; then
	git clone -q --depth 1 https://github.com/WordPress/sqlite-database-integration.git "$WORK/sqlite"
fi
SQL="$WORK/sqlite/packages/plugin-sqlite-database-integration"
[ -f "$SQL/db.copy" ] || SQL="$WORK/sqlite"

W="$WORK/wp"
sed "s#{SQLITE_IMPLEMENTATION_FOLDER_PATH}#$SQL#" "$SQL/db.copy" > "$W/wp-content/db.php"
ln -sfn "$ROOT" "$W/wp-content/plugins/tipping-comp"
mkdir -p "$W/wp-content/database"
cat > "$W/wp-config.php" <<'EOF'
<?php
define( 'DB_NAME', 'wp' ); define( 'DB_USER', '' ); define( 'DB_PASSWORD', '' ); define( 'DB_HOST', '' );
define( 'DB_CHARSET', 'utf8mb4' ); define( 'DB_COLLATE', '' );
define( 'DB_DIR', __DIR__ . '/wp-content/database/' ); define( 'DB_FILE', 'wp.sqlite' );
$table_prefix = 'wp_';
define( 'AUTH_KEY', 'a' ); define( 'SECURE_AUTH_KEY', 'b' ); define( 'LOGGED_IN_KEY', 'c' ); define( 'NONCE_KEY', 'd' );
define( 'AUTH_SALT', 'e' ); define( 'SECURE_AUTH_SALT', 'f' ); define( 'LOGGED_IN_SALT', 'g' ); define( 'NONCE_SALT', 'h' );
define( 'WP_DEBUG', true ); define( 'WP_DEBUG_LOG', __DIR__ . '/debug.log' ); define( 'WP_DEBUG_DISPLAY', false );
define( 'DISABLE_WP_CRON', true );
define( 'AUTOMATIC_UPDATER_DISABLED', true );
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ . '/' );
require_once ABSPATH . 'wp-settings.php';
EOF

FAILS=0

echo "== PHP lint"
while IFS= read -r f; do
	php -l "$f" >/dev/null || { echo "LINT FAIL $f"; FAILS=$((FAILS + 1)); }
done < <(find "$ROOT" -name '*.php' -not -path '*/vendor/*' -not -path '*/tests/*')

reset_site() {
	rm -f "$W/wp-content/database/wp.sqlite"
	php "$ROOT/tests/install.php" "$W" >/dev/null
	php "$ROOT/tests/01-sync.php" "$W" > "$WORK/01-sync.out" 2>&1
	if ! grep -q "^NFL: {" "$WORK/01-sync.out"; then
		echo "FAIL 01-sync: fixtures didn't load"; cat "$WORK/01-sync.out"; FAILS=$((FAILS + 1))
	fi
}

rm -f "$W/debug.log"
for t in 02-scoring 03-oddsapi 04-nfl-oddsapi; do
	reset_site
	echo "== $t"
	out="$(php "$ROOT/tests/$t.php" "$W" 2>&1)"
	echo "$out" | grep -E '^(PASS|FAIL)' || true
	if echo "$out" | grep -qE '^FAIL|Fatal error' || ! echo "$out" | grep -q '^0 failures'; then
		FAILS=$((FAILS + 1))
		echo "$out" | grep -vE '^PASS' | head -20
	fi
done

reset_site
echo "== 05-updater"
out="$(php "$ROOT/tests/05-updater.php" "$W" 2>&1)"
echo "$out"
echo "$out" | grep -q 'No fatal errors' || FAILS=$((FAILS + 1))

echo "== Plugin notices, warnings and deprecations in debug.log"
if [ -f "$W/debug.log" ] && grep -i 'tipping-comp' "$W/debug.log"; then
	FAILS=$((FAILS + 1))
else
	echo "none"
fi

echo
if [ "$FAILS" -gt 0 ]; then
	echo "RESULT: $FAILS problem(s) on WordPress $WPV"
	exit 1
fi
echo "RESULT: all tests passed on WordPress $WPV"
