# Tipping Comp (WordPress plugin)

Footy tipping competitions for NRL, AFL, EPL and NFL, built for Front Row Media
(a not-for-profit) and their client sites, which run **Breakdance** on **SiteGround**.
The plugin lives at the repository root. Contact: Nick (Front Row Media).
New to the project? Read `HANDOVER.md` first (history, decisions, open items).

## Layout
- `tipping-comp.php` – bootstrap; version in the header, `TIPC_VERSION` and `readme.txt` "Stable tag" (all three must match).
- `includes/` – settings, DB, sync/cron, scoring, front end (shortcodes), admin, Odds API, updater.
- `includes/providers/` – data feeds: Squiggle (AFL), nrl.com (NRL), football-data.org (EPL), ESPN (NFL, blocked from SiteGround), TheSportsDB, The Odds API (NFL draw).
- `vendor/plugin-update-checker/` – YahnisElsts Plugin Update Checker v5. Sites update from this repo's `main` branch (its version header). Don't edit; upgrade by replacing the folder.
- `tests/` – `tests/run.sh [latest|nightly|x.y.z]` builds WordPress + SQLite in a temp dir and runs everything with mocked feeds (no keys, no credits).
- `bin/build-zip.sh` – builds `dist/tipping-comp-<version>.zip`.

## House rules
- The zip is always named `tipping-comp-<version>.zip` and contains a `tipping-comp/` folder.
- Bump the version (header, `TIPC_VERSION`, readme Stable tag) and add a readme changelog entry for every release.
- **`main` is what's live on every site.** Anything merged to `main` with a higher version number is installed by sites with auto-updates on. Never push changes to `main` without Nick's approval: work on a branch, open a pull request (or give Nick the compare link), show test results, ask.
- This environment can't create GitHub releases or tags; that's fine, releases are just merges to `main`.
- PHP 7.4 compatible (no `match`, `str_contains`, enums, readonly, etc.). WordPress 6.0+.
- The front end is shortcodes (`[tipping]`, `[tipping_leaderboard]`) so it works in Breakdance's Shortcode element. Don't depend on Breakdance APIs.

## Weekly maintenance check
1. `git pull`, then run `tests/run.sh latest` and `tests/run.sh nightly`.
2. Check for new releases since the last check (see `MAINTENANCE.md` for the last versions checked):
   - WordPress: https://wordpress.org/news/category/releases/ and Make/Core dev notes (https://make.wordpress.org/core/) for changes to shortcodes, `admin-post.php`, WP-Cron, `wpdb`/dbDelta, `wp_remote_get`, options/transients, nonces, script loading, minimum PHP.
   - Breakdance: https://breakdance.com/changelog/ (or the Breakdance site's changelog) for changes to the Shortcode element, how shortcodes/scripts render in builder previews, or CSS resets that could restyle `.tipc`.
   - PHP: version changes SiteGround is rolling out.
   - Plugin Update Checker: https://github.com/YahnisElsts/plugin-update-checker/releases (security fixes).
   - Data sources: The Odds API changelog/docs; Squiggle, football-data.org API notices.
3. If nothing affects the plugin: update `MAINTENANCE.md` (date, versions checked, "no changes needed"), commit it to `main` (log-only commits with no version change are fine: they release nothing), and send Nick a one-line all-clear.
4. If something needs a change: make it on a branch `maintenance/<date>`, bump the patch version, add a changelog entry, run the tests, open a pull request, build the zip, and message Nick with what changed, why, and the test results. Release only after he approves (see below).

## Releasing (after approval)
1. Nick merges the pull request on GitHub, or says "approve" and you merge the branch into `main` and push.
2. `bin/build-zip.sh` and send Nick `dist/tipping-comp-<version>.zip` for his records / manual installs.
3. Sites with auto-updates on install it within about 12 hours (WordPress checks twice a day).
