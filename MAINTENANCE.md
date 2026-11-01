# Maintenance log

| Date | WordPress checked | Breakdance checked | Result |
|---|---|---|---|
| 2026-10-26 | 7.1.3 (and 7.2 beta 1) | not yet checked | Initial release 1.3.0; all tests pass. |
| 2026-11-01 | 7.1.4 (security release) and 7.2-beta3 nightly | 3.0.1 (3.0 stable) | No changes needed. All tests pass on both, no notices. Checked: WP 7.2 wpdb drops NO_BACKSLASH_ESCAPES (plugin uses `$wpdb->prepare`, unaffected); Breakdance 3.0 Modern Normalize on new installs and selectors without `.breakdance` wrapper (`tipc.css` sets its own margins and uses `tipc-` prefixed classes, unaffected); PUC 5.7 still latest (vendored 5.7); no Odds API, Squiggle or football-data.org breaking notices; no SiteGround PHP change found (tests run on PHP 8.3). |
