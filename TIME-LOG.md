# Tipping Comp: time log

Time Claude spent on the Tipping Comp project, worked out from message times, file and commit
timestamps. All times are Sydney time (AEDT). Durations are approximate (to the nearest few minutes)
and are Claude's working time, not time Nick spent waiting or away.

## Summary

| | |
|---|---|
| **Claude working time** | **about 1 hour 20 minutes** |
| Session span (first message to last) | 9:40pm Mon 26 Oct to about 1:56am Tue 27 Oct (about 4 hours 15 minutes, including breaks) |
| Versions delivered | 1.0.0, 1.1.0, 1.2.0, 1.3.0, 1.3.1 |
| Also set up | GitHub repository, automatic updates, weekly maintenance task, handover pack |

## Breakdown

| # | When | Work | Time |
|---|---|---|---|
| 1 | Mon 9:40pm to 9:46pm | Research and advice: how hard a tipping comp is in WordPress, existing plugins, making it automatic, data feeds per league, SiteGround cron and caching | 6 min |
| 2 | 9:46pm to 10:05pm | **Built version 1.0.0**: data feeds for all four leagues, tipping form, tip locking, scoring (draws, missed tips, margin tiebreaker), leaderboards, admin screens, SiteGround cache clearing. Set up a test WordPress site, wrote 16 automated tests, tested logged-in and logged-out tipping, mobile layout and admin screens; fixed an overlapping "Save tips" bar | 20 min |
| 3 | 10:49pm to 11:07pm | Data provider research: paid all-in-one options (Goalserve, Sportradar, API-Sports, TheSportsDB), cheapest not-for-profit set-up, who the big tipping sites use (Champion Data), iTipFooty and its contact details, draft enquiry email, checked an AI summary about API-Sports (it doesn't cover NRL), assessed The Odds API | 18 min |
| 4 | 11:07pm to 11:12pm | **Version 1.1.0**: The Odds API as the results source for all four leagues, team-name matching, credit-saving checks, backup feeds; new tests | 5 min |
| 5 | 11:16pm to 11:19pm | **Version 1.2.0**: ESPN blocked SiteGround (HTTP 403), so the NFL draw now comes from The Odds API with week numbers from the NFL calendar; tests. Renamed zips to include the version | 3 min |
| 6 | 11:20pm to 11:36pm | Automatic updates and weekly check: bundled the update checker, guided GitHub account, repository and app set-up, published the code, fixed a crash the update test found, tested a real update from GitHub, wrote the project rulebook, created the Monday weekly maintenance task. **Version 1.3.0** | 16 min |
| 7 | 11:36pm to 11:39pm | **Version 1.3.1**: Front Row Media logo in white as the admin menu icon | 3 min |
| 8 | Tue 1:51am to 1:53am | Handover document and handover bundle | 3 min |
| 9 | 1:53am to 1:56am | This time log | 3 min |
| | | **Total** | **about 77 minutes** |

## Not included

- Nick's own time: creating the GitHub account and repository, connecting GitHub to Claude,
  The Odds API sign-up, installing the plugin on sites and setting up SiteGround cron.
- The weekly maintenance task's future runs (from Mon 2 Nov 2026). Each run should be logged in
  `MAINTENANCE.md`; add a row here too if you're tracking total time.
