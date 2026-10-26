=== Tipping Comp ===
Contributors: frontrowmedia
Tags: tipping, footy tipping, nrl, afl, nfl
Requires at least: 6.0
Requires PHP: 7.4
Stable tag: 1.3.0
License: GPLv2 or later

Automatic tipping competitions for NRL, AFL, the English Premier League and the NFL.

== Description ==

* Fixtures and results load automatically from live data feeds.
* Tips lock at each game's kickoff; people can change tips until then.
* Leaderboards (overall and per round) update as soon as results land.
* Margin tiebreaker, draw rules and "missed tip = away team" option per competition.
* Clears the SiteGround Speed Optimizer cache when results change.
* Manual override for any result, plus a feed test button.

== Data sources ==

Recommended: The Odds API (https://the-odds-api.com) for results in all four
leagues. Add the key in Settings and set "Results from" to The Odds API on each
competition. The league's normal feed still loads the draw and round numbers and
acts as a backup. Fixture matching is free; each results check costs 2 credits
and only runs once a game should be over (at most every 30 minutes per league),
which keeps a typical season inside the free 500 credits a month.

Draw / backup feeds:

* NRL: NRL.com draw feed (free, unofficial). Backup: TheSportsDB.
* AFL: Squiggle API (free).
* EPL: football-data.org (free key: https://www.football-data.org/client/register). Backup: TheSportsDB.
* NFL: The Odds API (draw and results; week numbers worked out from the NFL calendar). Alternatives: ESPN scoreboard (free, unofficial; blocks some hosting servers), TheSportsDB.

Unofficial feeds can change without notice. If one stops working, the sync log on
Tipping Comp > Fixtures & Results shows an error; switch that league to TheSportsDB
or enter results by hand.

== Setup ==

1. Upload the zip under Plugins > Add New > Upload Plugin, then activate.
2. Tipping Comp > Settings: tick "Run this competition" for each league, check the season, add a football-data.org key for EPL. Save.
3. Tipping Comp > Fixtures & Results: click "Test feed" for each league, then "Sync fixtures & results now".
4. Add shortcodes to a page (Breakdance: use the Shortcode element):
   [tipping league="nrl"]
   [tipping league="nrl" show_leaderboard="yes"]
   [tipping_leaderboard league="nrl" limit="50"]
   League values: nrl, afl, epl, nfl.
5. SiteGround: Site Tools > Devs > Cron Jobs, add the command shown on the Settings page every 15 minutes, then add define( 'DISABLE_WP_CRON', true ); to wp-config.php.
6. Allow registrations (Settings > General > Anyone can register) if tippers sign themselves up.

== Updates ==

New versions are published as releases on https://github.com/FrontRowMedia32/tipping-comp.
WordPress shows them like any other plugin update; turn on "Enable auto-updates" on the
Plugins screen to install them automatically.

== Styling ==

Override the CSS variables in your site CSS, for example:
.tipc { --tipc-accent: #c8102e; --tipc-accent-ink: #fff; }

== Changelog ==

= 1.3.0 =
* Automatic updates from GitHub (FrontRowMedia32/tipping-comp). Turn on "Enable auto-updates" for Tipping Comp on the Plugins screen.

= 1.2.0 =
* NFL draw can now come from The Odds API, with week numbers from the NFL calendar (ESPN blocks requests from some hosts). This is the default for new installs.

= 1.1.0 =
* The Odds API as the results source for NRL, AFL, EPL and NFL, with free kickoff-time updates, team-name matching and credit-saving checks.
* Feeds never turn a finished game back into an unfinished one.

= 1.0.0 =
* First release.

== Developer hooks ==

* tipc_results_updated ( $league, $season ) fires after results change.
* tipc_tips_saved ( $user_id, $league, $season ) fires after a tipper saves.
