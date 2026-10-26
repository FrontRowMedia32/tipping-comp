# Tipping Comp (WordPress plugin)

Footy tipping competitions for NRL, AFL, EPL and NFL, built for Front Row Media
(a not-for-profit) and their client sites, which run **Breakdance** on **SiteGround**.
The plugin lives at the repository root. Contact: Nick (Front Row Media).

## Layout
- `tipping-comp.php` – bootstrap; version in the header, `TIPC_VERSION` and `readme.txt` "Stable tag" (all three must match).
- `includes/` – settings, DB, sync/cron, scoring, front end (shortcodes), admin, Odds API, updater.
- `includes/providers/` – data feeds: Squiggle (AFL), nrl.com (NRL), football-data.org (EPL), ESPN (NFL, blocked from SiteGround), TheSportsDB, The Odds API (NFL draw).
- `vendor/plugin-update-checker/` – YahnisElsts Plugin Update Checker v5 (updates from this repo's GitHub releases). Don't edit; upgrade by replacing the folder.
- `tests/` – `tests/run.sh [latest|nightly|x.y.z]` builds WordPress + SQLite in a temp dir and runs everything with mocked feeds (no keys, no credits).
- `bin/build-zip.sh` – builds `dist/tipping-comp-<version>.zip`.

## House rules
- The zip is always named `tipping-comp-<version>.zip` and contains a `tipping-comp/` folder.
- Bump the version (header, `TIPC_VERSION`, readme Stable tag) and add a readme changelog entry for every release.
- **Never publish a release without Nick's approval.** Prepare a branch + pull request, show test results, ask.
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
3. If nothing affects the plugin: update `MAINTENANCE.md` (date, versions checked, "no changes needed"), commit to `main`, and send Nick a one-line all-clear.
4. If something needs a change: make it on a branch `maintenance/<date>`, bump the patch version, add a changelog entry, run the tests, open a pull request, build the zip, and message Nick with what changed, why, and the test results. Release only after he approves (see below).

## Releasing (after approval)
1. Merge the pull request to `main`.
2. `bin/build-zip.sh`.
3. Create a GitHub release with tag `v<version>` and attach `dist/tipping-comp-<version>.zip` (asset name must match `tipping-comp-x.y.z.zip`). Sites with auto-updates on install it within ~12 hours.
4. Send Nick the zip as well.
