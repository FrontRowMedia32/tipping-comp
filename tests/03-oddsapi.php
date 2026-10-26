<?php
$_SERVER['HTTP_HOST'] = 'localhost:8088'; $_SERVER['REQUEST_URI'] = '/';
require $argv[1] . '/wp-load.php';
require __DIR__ . '/mock.php';
function ok( $c, $m ) { echo ( $c ? 'PASS ' : 'FAIL ' ) . $m . "\n"; if ( ! $c ) { $GLOBALS['fails'] = ( $GLOBALS['fails'] ?? 0 ) + 1; } }
global $wpdb;
TIPC_DB::install();
$cols = $wpdb->get_col( "SHOW COLUMNS FROM wp_tipc_games" );
ok( in_array( 'odds_id', $cols, true ), 'DB upgraded with odds_id column' );

// Find the feed kickoff of a few games so odds events line up.
$wpdb->query( $wpdb->prepare( "UPDATE wp_tipc_games SET kickoff = %s WHERE league = 'afl' AND home = 'Brisbane Lions'", gmdate( 'Y-m-d H:i:s', time() - 3 * 3600 ) ) );
$k = array();
foreach ( array( 'nrl', 'afl', 'epl', 'nfl' ) as $l ) { foreach ( TIPC_DB::games( $l, 2026 ) as $g ) { $k[ $l ][ $g->home . '|' . $g->away ] = $g; } }
$iso = function ( $g, $shift = 0 ) { return gmdate( 'Y-m-d\TH:i:s\Z', strtotime( $g->kickoff . ' UTC' ) + $shift ); };

$GLOBALS['odds'] = array(
	'rugbyleague_nrl' => array(
		array( 'id' => 'n1', 'commence_time' => $iso( $k['nrl']['Sea Eagles|Rabbitohs'] ), 'home_team' => 'Manly Warringah Sea Eagles', 'away_team' => 'South Sydney Rabbitohs', 'completed' => true,
			'scores' => array( array( 'name' => 'Manly Warringah Sea Eagles', 'score' => '36' ), array( 'name' => 'South Sydney Rabbitohs', 'score' => '24' ) ) ),
		array( 'id' => 'n2', 'commence_time' => $iso( $k['nrl']['Bulldogs|Roosters'] ), 'home_team' => 'Canterbury Bulldogs', 'away_team' => 'Sydney Roosters', 'completed' => true,
			'scores' => array( array( 'name' => 'Canterbury Bulldogs', 'score' => '12' ), array( 'name' => 'Sydney Roosters', 'score' => '22' ) ) ),
		array( 'id' => 'n3', 'commence_time' => $iso( $k['nrl']['Sea Eagles|Bulldogs'], 3600 ), 'home_team' => 'Manly Warringah Sea Eagles', 'away_team' => 'Canterbury Bulldogs', 'completed' => false, 'scores' => null ),
		array( 'id' => 'n4', 'commence_time' => $iso( $k['nrl']['Roosters|Rabbitohs'] ), 'home_team' => 'Sydney Roosters', 'away_team' => 'South Sydney Rabbitohs', 'completed' => false, 'scores' => null ),
	),
	'aussierules_afl' => array(
		array( 'id' => 'a1', 'commence_time' => $iso( $k['afl']['Adelaide|Brisbane Lions'] ), 'home_team' => 'Adelaide Crows', 'away_team' => 'Brisbane Lions', 'completed' => true,
			'scores' => array( array( 'name' => 'Adelaide Crows', 'score' => '88' ), array( 'name' => 'Brisbane Lions', 'score' => '70' ) ) ),
		array( 'id' => 'a3', 'commence_time' => $iso( $k['afl']['Brisbane Lions|Carlton'] ), 'home_team' => 'Brisbane Lions', 'away_team' => 'Carlton Blues', 'completed' => true,
			'scores' => array( array( 'name' => 'Brisbane Lions', 'score' => '81' ), array( 'name' => 'Carlton Blues', 'score' => '64' ) ) ),
		array( 'id' => 'a4', 'commence_time' => $iso( $k['afl']['Collingwood|Adelaide'] ), 'home_team' => 'Collingwood Magpies', 'away_team' => 'Adelaide Crows', 'completed' => false, 'scores' => null ),
	),
	'soccer_epl' => array(
		array( 'id' => 'e1', 'commence_time' => $iso( $k['epl']['Arsenal|Chelsea'] ), 'home_team' => 'Arsenal', 'away_team' => 'Chelsea', 'completed' => true,
			'scores' => array( array( 'name' => 'Arsenal', 'score' => '1' ), array( 'name' => 'Chelsea', 'score' => '1' ) ) ),
		array( 'id' => 'e3', 'commence_time' => $iso( $k['epl']['Man City|Spurs'] ), 'home_team' => 'Manchester City', 'away_team' => 'Tottenham Hotspur', 'completed' => false, 'scores' => null ),
	),
	'americanfootball_nfl' => array(
		// Swapped home/away (neutral site style) to test swap handling.
		array( 'id' => 'f1', 'commence_time' => $iso( $k['nfl']['Kansas City Chiefs|Baltimore Ravens'] ), 'home_team' => 'Baltimore Ravens', 'away_team' => 'Kansas City Chiefs', 'completed' => true,
			'scores' => array( array( 'name' => 'Baltimore Ravens', 'score' => '20' ), array( 'name' => 'Kansas City Chiefs', 'score' => '27' ) ) ),
		array( 'id' => 'f9', 'commence_time' => $iso( $k['nfl']['Buffalo Bills|Miami Dolphins'], 5 * DAY_IN_SECONDS ), 'home_team' => 'New York Jets', 'away_team' => 'New England Patriots', 'completed' => false, 'scores' => null ),
	),
);
$GLOBALS['odds_calls'] = array();
add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
	if ( false === strpos( $url, 'the-odds-api.com' ) || false !== $pre ) { return $pre; }
	$GLOBALS['odds_calls'][] = $url;
	preg_match( '#/sports/([a-z_]+)/(events|scores)#', $url, $m );
	$ev = $GLOBALS['odds'][ $m[1] ];
	if ( 'events' === $m[2] ) { foreach ( $ev as &$e ) { unset( $e['scores'], $e['completed'] ); } unset( $e ); }
	$GLOBALS['credits_used'] = ( $GLOBALS['credits_used'] ?? 0 ) + ( 'scores' === $m[2] ? 2 : 0 );
	return array( 'headers' => array( 'x-requests-remaining' => 500 - $GLOBALS['credits_used'], 'x-requests-used' => $GLOBALS['credits_used'] ),
		'body' => wp_json_encode( $ev ), 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null );
}, 5, 3 );

// Name similarity sanity.
ok( 1.0 == TIPC_OddsAPI::similarity( 'Sea Eagles', 'Manly Warringah Sea Eagles' ), 'Sea Eagles ~ Manly Warringah Sea Eagles' );
ok( TIPC_OddsAPI::similarity( 'Rabbitohs', 'Sydney Roosters' ) < 0.5, 'Rabbitohs !~ Sydney Roosters' );
ok( 1.0 == TIPC_OddsAPI::similarity( 'Man United', 'Manchester United' ) && TIPC_OddsAPI::similarity( 'Man United', 'Manchester City' ) < 1, 'Man United aliases' );
ok( 1.0 == TIPC_OddsAPI::similarity( 'Wolves', 'Wolverhampton Wanderers' ) && 1.0 == TIPC_OddsAPI::similarity( 'Brighton Hove', 'Brighton and Hove Albion' ), 'Wolves / Brighton aliases' );
ok( 1.0 == TIPC_OddsAPI::similarity( 'Greater Western Sydney', 'GWS Giants' ) || 1.0 == TIPC_OddsAPI::similarity( 'GWS', 'Greater Western Sydney Giants' ), 'GWS alias' );

// Switch every league to Odds API results. Reset the AFL game 103 to live (the earlier tests set it manual).
$wpdb->query( $wpdb->prepare( "UPDATE wp_tipc_games SET manual = 0, status = 'live', winner = NULL, home_score = 30, away_score = 22, kickoff = %s WHERE league = 'afl' AND home = 'Brisbane Lions'", gmdate( 'Y-m-d H:i:s', time() - 3 * 3600 ) ) );
$wpdb->query( "UPDATE wp_tipc_games SET home_score = 10, away_score = 22 WHERE league = 'nrl' AND home = 'Bulldogs'" );
$s = get_option( 'tipc_settings' ); $s['keys']['oddsapi'] = 'ODDSKEY';
foreach ( array( 'nrl', 'afl', 'epl', 'nfl' ) as $l ) { $s['leagues'][ $l ]['results_source'] = 'oddsapi'; }
update_option( 'tipc_settings', $s ); TIPC_Settings::flush();
ok( TIPC_OddsAPI::is_results_source( 'nrl' ), 'NRL results source = Odds API' );

// Free events sync: matches games, updates the rescheduled kickoff.
$before = $k['nrl']['Sea Eagles|Bulldogs']->kickoff;
$r = TIPC_OddsAPI::sync_events( 'nrl' );
echo 'NRL events: ' . json_encode( $r ) . "\n";
ok( 4 === $r['matched'] && 1 === $r['kickoffs'], 'NRL: all 4 matched, 1 kickoff moved' );
$after = TIPC_DB::get_game( $k['nrl']['Sea Eagles|Bulldogs']->id )->kickoff;
ok( strtotime( $after ) - strtotime( $before ) === 3600, 'Kickoff moved by 1 hour' );
ok( 0 === ( $GLOBALS['credits_used'] ?? 0 ), 'Events used no credits' );

// Full feed refresh must not undo the Odds API kickoff.
TIPC_Sync::sync_league( 'nrl', 'full' );
ok( TIPC_DB::get_game( $k['nrl']['Sea Eagles|Bulldogs']->id )->kickoff === $after, 'Feed refresh keeps Odds API kickoff' );

// Scores: AFL live game finishes via Odds API.
foreach ( array( 'nrl', 'afl', 'epl', 'nfl' ) as $l ) { delete_transient( 'tipc_odds_checked_' . $l ); }
ok( TIPC_OddsAPI::results_due( 'afl', 2026 ), 'AFL results due (live game past its usual length)' );
$r = TIPC_OddsAPI::sync_scores( 'afl' );
echo 'AFL scores: ' . json_encode( $r ) . "\n";
$g = TIPC_DB::get_game( $k['afl']['Brisbane Lions|Carlton']->id );
ok( 'final' === $g->status && 81 == $g->home_score && 'home' === $g->winner, 'AFL result applied from Odds API' );
ok( ! TIPC_OddsAPI::results_due( 'afl', 2026 ), 'No more AFL checks needed' );

// NRL score correction from Odds API (feed had 10-22, odds says 12-22).
$r = TIPC_OddsAPI::sync_scores( 'nrl' );
$g = TIPC_DB::get_game( $k['nrl']['Bulldogs|Roosters']->id );
ok( 12 == $g->home_score && 'away' === $g->winner, 'Odds API corrects a final score' );
// Backup feed can't overwrite the Odds API result.
TIPC_Sync::sync_league( 'nrl', 'full' );
$g = TIPC_DB::get_game( $k['nrl']['Bulldogs|Roosters']->id );
ok( 12 == $g->home_score, 'Backup feed does not overwrite Odds API result' );

// NFL swapped home/away.
TIPC_OddsAPI::sync_scores( 'nfl' );
$g = TIPC_DB::get_game( $k['nfl']['Kansas City Chiefs|Baltimore Ravens']->id );
ok( 27 == $g->home_score && 20 == $g->away_score && 'home' === $g->winner, 'Swapped home/away handled' );

// EPL draw + match with full club names.
$r = TIPC_OddsAPI::sync_events( 'epl' );
ok( 2 === $r['matched'], 'EPL: Man City v Spurs matched to Manchester City v Tottenham Hotspur' );

// Throttle and tick behaviour.
$GLOBALS['odds_calls'] = array();
$sum = TIPC_Sync::tick();
echo 'Tick: ' . json_encode( $sum ) . "\n";
$scores_calls = array_filter( $GLOBALS['odds_calls'], function ( $u ) { return strpos( $u, '/scores' ); } );
ok( 0 === count( $scores_calls ), 'Tick spends no credits when nothing is due' );
$u = TIPC_OddsAPI::usage();
ok( $u && $u['remaining'] < 500, 'Credits remaining recorded: ' . ( $u ? $u['remaining'] : '?' ) );

// Odds API failure: falls back to the feed.
$s['keys']['oddsapi'] = 'BAD'; update_option( 'tipc_settings', $s ); TIPC_Settings::flush();
add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
	if ( false !== strpos( $url, 'apiKey=BAD' ) ) {
		return array( 'headers' => array(), 'body' => '{"message":"API key is not valid"}', 'response' => array( 'code' => 401, 'message' => 'Unauthorized' ), 'cookies' => array(), 'filename' => null );
	}
	return $pre;
}, 1, 3 );
$e = TIPC_OddsAPI::sync_events( 'nrl' );
ok( is_wp_error( $e ) && false !== strpos( $e->get_error_message(), 'rejected the key' ), 'Bad key gives clear error' );
echo ( $GLOBALS['fails'] ?? 0 ) . " failures\n";
