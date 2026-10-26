# Tipping Comp: handover

Written 27 October 2026 for whoever (person or AI agent) picks this project up next.
Read this first, then `CLAUDE.md` (the house rules) and `MAINTENANCE.md` (the weekly log).

## 1. What this is

A WordPress plugin that runs **footy tipping competitions** for **NRL, AFL, English Premier League and NFL**.
Members tip winners each round on the club's own website; fixtures and results load themselves from
data feeds, tips lock at each game's kickoff, and leaderboards update when results come in.

- **Client / owner:** Nick, Front Row Media (frontrowmedia.com.au), a Sydney web agency working for a
  **not-for-profit**, so cheap or free data sources were a deliberate choice (see section 6).
- **Sites:** WordPress built with **Breakdance**, hosted on **SiteGround**. Nick's connected sites include
  Front Row Media, Kings Rugby (Kobweb), Belfield Bowling Club, FAQ Support and Start Up Mortgage Brokers.
  **Which site(s) the comp is installed on has not been confirmed:** ask Nick.
- **Current version:** 1.3.1 (`main` branch).
- **Code:** https://github.com/FrontRowMedia32/tipping-comp (public). GitHub account `FrontRowMedia32`,
  email webmaster@frontrowmedia.com.au. The Claude GitHub app is installed on this one repository with write access.

## 2. How the sites get updates

- Sites update from the **`main` branch** using the bundled Plugin Update Checker
  (`vendor/plugin-update-checker`, YahnisElsts v5.7). When `main` has a higher version number than the
  installed one, WordPress shows "update available"; sites with **Enable auto-updates** turned on install it
  within about 12 hours.
- GitHub **releases and tags can't be created** from Claude's environment (HTTP 403), which is why updates
  come from the branch instead. Don't spend time on releases.
- **Anything pushed to `main` with a version bump goes live on every site.** Never do that without Nick's
  approval. Doc-only or log-only commits (no version change) are fine.
- Tested end to end on 26 Oct: a 1.2.0 test site found 1.3.0 on GitHub, updated itself, and the update
  left out the development files (`tests/`, `bin/`, `CLAUDE.md`, etc. are `export-ignore`d).

## 3. Weekly scheduled task

- **Name:** "Tipping Comp weekly check" (id `trig_01F4vDEHVftCWfakY3gc6wf8`), every **Monday 8:34am Sydney**,
  cloud only, auto-approve mode, push notification to Nick. First run: Mon 2 Nov 2026.
- It clones this repo, runs `tests/run.sh latest` and `nightly`, checks WordPress, Breakdance, PHP,
  Plugin Update Checker and the data sources for changes, then either logs an all-clear in `MAINTENANCE.md`
  or prepares a fix on a `maintenance/YYYY-MM-DD` branch and asks Nick to approve (merge on GitHub, or
  reply "approve" in that run's conversation).
- `MAINTENANCE.md` still says Breakdance was **not yet checked**: the first weekly run should fill that in.

## 4. How it works (code map)

| File | What it does |
|---|---|
| `tipping-comp.php` | Bootstrap. Version in header + `TIPC_VERSION` (must match readme "Stable tag"). `TIPC_DB_VERSION` = 2. |
| `includes/class-tipc-settings.php` | All settings in one option `tipc_settings`: per-league enabled/label/season/provider/results_source/draw rule/missed tips/margin/points, API keys, update repo, cron key. |
| `includes/class-tipc-db.php` | Tables `{prefix}tipc_games` (one row per game, incl. `odds_id`, `manual`) and `{prefix}tipc_tips` (user, game, pick home/away/draw, margin). |
| `includes/providers/*` | One class per data feed, all returning the same normalised game array. |
| `includes/class-tipc-oddsapi.php` | The Odds API: free `/events` matching (kickoff times), `/scores` results (2 credits), team-name matching with aliases, credit throttling. |
| `includes/class-tipc-sync.php` | WP-Cron job `tipc_tick` every 15 min. Full draw refresh every 6 h; result checks only for rounds with games under way. Clears SiteGround cache when results change. Secret URL `/?tipc_cron=KEY` runs a check. |
| `includes/class-tipc-scoring.php` | Points, draw rules, "missed tip = away team", margin tiebreaker (first game of each round), overall and per-round standings (cached in a transient, flushed on result or settings change). |
| `includes/class-tipc-frontend.php` | Shortcodes `[tipping league="nrl"]` and `[tipping_leaderboard league="nrl"]` (works in Breakdance's Shortcode element). Tip saving via `admin-post.php` with nonce; server re-checks kickoff lock. |
| `includes/class-tipc-admin.php` | Admin menu "Tipping Comp" (icon = Front Row Media logo in white, `assets/menu-icon.svg`): Settings, and Fixtures & Results (sync, test feed, Odds API buttons, manual result override, sync log, delete season). |
| `includes/class-tipc-updater.php` | GitHub updates (default repo `FrontRowMedia32/tipping-comp`, branch `main`). |
| `assets/tipc.css`, `tipc.js` | Front-end styles (theme with CSS variables, e.g. `.tipc { --tipc-accent: #c8102e; }`) and the round dropdown / unsaved-changes prompt. |

**Result priority:** a game marked **Manual** (admin edited it) is never changed by a feed. When the Odds API
is a league's results source, the league's own feed only fills in results the Odds API hasn't, and never
overwrites them. No feed can turn a finished game back into an unfinished one.

## 5. Data sources and settings (recommended set-up)

| League | Draw (fixtures + round numbers) | Results | Notes |
|---|---|---|---|
| NRL | nrl.com draw feed (free, unofficial) | The Odds API | nrl.com parser written from documentation, **not yet tested against the live feed**. Backup: TheSportsDB (US$9/month premium). |
| AFL | Squiggle (free, no key) | The Odds API | Squiggle asks for a contact email in the User-Agent (setting "Contact email for data feeds"). |
| EPL | football-data.org (free key needed) | The Odds API | Season "2026" means 2026/27. Draw is a tip option for EPL. |
| NFL | **The Odds API** (draw and results) | The Odds API | ESPN returned **HTTP 403 from SiteGround** (blocked), so NFL was switched to The Odds API in 1.2.0. Week numbers are worked out from the NFL calendar (Week 1 = Tuesday after Labor Day; 19 Wild Card, 20 Divisional, 21 Conf. Champs, 22 Super Bowl). |

- **The Odds API:** Nick has an account (key entered in Tipping Comp > Settings, not stored anywhere else).
  Free plan = 500 credits/month; `/events` is free, `/scores?daysFrom=3` costs 2. Checks only run once a game
  should be over, at most every 30 min per league, so a normal season should fit the free plan. Credits left are
  shown under the key field. It probably only lists games bookmakers have priced (usually the next week or two),
  so the NFL draw won't show the whole season ahead.
- **SiteGround set-up (per site):** Site Tools > Devs > Cron Jobs, every 15 minutes, the `php -q .../wp-cron.php`
  command shown on the plugin's Settings page; then `define( 'DISABLE_WP_CRON', true );` in wp-config.php
  (in that order). SiteGround blocks external cron services, so use its own cron.

## 6. Decisions already made (don't redo the research)

- **Paid all-in-one providers:** Goalserve lists all four leagues but the full package is US$800/month and it's
  unclear which tier includes NRL. Sportradar / Champion Data / Stats Perform are enterprise contracts.
  **API-Sports does not cover the NRL** (its rugby API is union only), despite what some AI summaries say.
  TheSportsDB is cheap but community-edited (its NRL page showed round "200").
- **The Odds API** was chosen (Nick's choice, 26 Oct): it covers all four leagues including NRL, US$0-30/month.
- **iTipFooty** (Dundon Group Pty Ltd, ACN 162 117 642, contact form only) is a free hosted alternative;
  Nick was given a draft email asking which data provider they use. No reply known.
- Champion Data (official AFL data) has a developer portal; a not-for-profit request was suggested, not sent as far as known.
- Nick asked that **every zip is named with its version**: `tipping-comp-<version>.zip` (see `bin/build-zip.sh`).

## 7. Testing

- `tests/run.sh [latest|nightly|x.y.z]` downloads WordPress and the SQLite database plugin into a temp
  folder, installs a site, symlinks this plugin, and runs `tests/0*.php` with **all feeds mocked**
  (`tests/mock.php`): no API keys, no credits. Last result: **all tests passed on WordPress 7.1.3** (PHP 8.3).
- Coverage: sync for all four leagues, scoring rules, margin tiebreak, draw rules, missed tips, tip locking,
  result priority and manual overrides, Odds API matching/aliases/credits/fallback, NFL week numbers,
  and that the update checker loads with all its files.
- Also checked by hand on 26 Oct (Playwright screenshots): tipping page desktop and mobile (no sideways
  scroll at 390px), logged-out view, form saves (bad nonce 403, locked games skipped, open redirect blocked),
  admin screens, menu icon.
- **Not yet tested against live feeds from a real site.** That's the main open risk (section 8).

## 8. Open items / next steps

1. **Confirm which site(s) have the plugin** and that they're on 1.3.0+ with auto-updates on (1.3.0 was the
   last manual install; Nick was told to upload it and enable auto-updates).
2. **Live feed check on that site:** Tipping Comp > Fixtures & Results > "Test feed" for each league, then
   "Match Odds API fixtures (free)". If any feed fails, read the sync log at the bottom of that page.
   Most likely to need work: the **nrl.com** parser and Odds API **team-name aliases**
   (`TIPC_OddsAPI::$aliases`) once real names are seen.
3. NFL: if a half-loaded ESPN season exists on a site, delete it ("Danger zone" on the NFL tab) and sync again
   with the provider set to The Odds API.
4. 2027 seasons: NRL and AFL 2026 are finished; set Season to 2027 in Settings when the draws are released
   (around November-December). EPL 2026/27 and NFL 2026 are in progress.
5. First weekly check (Mon 2 Nov) should record the Breakdance version checked in `MAINTENANCE.md`.
6. Optional: a not-for-profit request to Champion Data, or the iTipFooty data question.

## 9. Gotchas

- `TIPC_Updater::init()` must only build the checker once (the library fatals on a duplicate slug).
  It's guarded; keep it that way.
- The update library needs its `vendor/` folder (`PucReadmeParser.php`, `Parsedown*.php`); without it update
  checks crash. `tests/05-updater.php` checks for these files.
- `.gitattributes` `export-ignore` controls what sites download in an update; `bin/build-zip.sh` controls the
  manual zip. Keep both in step when adding top-level dev files.
- Keep PHP 7.4 compatibility; WordPress 6.0+.
- Don't work around blocks by feeds (e.g. ESPN's 403) by disguising requests: switch provider instead.
- In Claude's cloud workspace, outbound HTTP goes through a proxy with an allowlist: live feeds
  (nrl.com, ESPN, Squiggle, the-odds-api.com) are **not reachable from the workspace**, only from the sites.
  GitHub and the GitHub API are reachable.
- Earlier copies of the plugin outside this repo (an older `tipping-comp` folder from the first session) are
  out of date. **This repository is the only source of truth.**
