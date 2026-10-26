<?php
$_SERVER['HTTP_HOST'] = 'localhost:8088'; $_SERVER['REQUEST_URI'] = '/';
require $argv[1] . '/wp-load.php';
function ok( $c, $m ) { echo ( $c ? 'PASS ' : 'FAIL ' ) . $m . "\n"; if ( ! $c ) { $GLOBALS['fails'] = ( $GLOBALS['fails'] ?? 0 ) + 1; } }
$r = function ( $iso ) { return TIPC_Provider_OddsAPI::nfl_round( strtotime( $iso ), 2026 ); };
// 2026: Labor Day Mon 7 Sep. Season opener Thu 10 Sep (00:20 UTC Fri 11 Sep).
ok( 1 === $r( '2026-09-11T00:20:00Z' ), 'Opener (Thu night ET) = Week 1' );
ok( 1 === $r( '2026-09-15T00:15:00Z' ), 'Monday night (Mon 14 Sep ET) = Week 1' );
ok( 2 === $r( '2026-09-18T00:15:00Z' ), 'Thu 17 Sep = Week 2' );
ok( 18 === $r( '2027-01-10T18:00:00Z' ), 'Sun 10 Jan 2027 = Week 18' );
ok( 19 === $r( '2027-01-16T21:30:00Z' ), 'Wild Card weekend = 19' );
ok( 21 === $r( '2027-01-31T20:00:00Z' ), 'Conference Championships = 21' );
ok( 22 === $r( '2027-02-14T23:30:00Z' ), 'Super Bowl = 22' );
ok( null === $r( '2026-08-20T00:00:00Z' ), 'Preseason ignored' );
ok( null === $r( '2027-09-10T00:00:00Z' ), 'Next season ignored' );

// Full flow: NFL with Odds API as draw + results.
$now = time();
$GLOBALS['ev'] = array(
	array( 'id' => 'x1', 'commence_time' => gmdate( 'Y-m-d\TH:i:s\Z', $now - 5 * 3600 ), 'home_team' => 'Kansas City Chiefs', 'away_team' => 'Buffalo Bills' ),
	array( 'id' => 'x2', 'commence_time' => gmdate( 'Y-m-d\TH:i:s\Z', $now + 3 * 86400 ), 'home_team' => 'Dallas Cowboys', 'away_team' => 'New York Giants' ),
);
add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
	if ( false === strpos( $url, 'the-odds-api.com' ) ) { return new WP_Error( 'x', 'blocked ' . $url ); }
	$GLOBALS['calls'][] = $url;
	$ev = $GLOBALS['ev'];
	if ( strpos( $url, '/scores' ) ) {
		$ev[0]['completed'] = true; $ev[0]['scores'] = array( array( 'name' => 'Kansas City Chiefs', 'score' => '24' ), array( 'name' => 'Buffalo Bills', 'score' => '31' ) );
		$ev[1]['completed'] = false; $ev[1]['scores'] = null;
	}
	return array( 'headers' => array( 'x-requests-remaining' => '498', 'x-requests-used' => '2' ), 'body' => wp_json_encode( $ev ), 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null );
}, 1, 3 );
$s = get_option( 'tipc_settings' ); $s['keys']['oddsapi'] = 'K'; $s['leagues']['nfl']['provider'] = 'oddsapi'; $s['leagues']['nfl']['results_source'] = 'feed';
update_option( 'tipc_settings', $s ); TIPC_Settings::flush();
TIPC_DB::delete_season( 'nfl', 2026 );
ok( TIPC_OddsAPI::is_results_source( 'nfl' ), 'Odds API provider implies Odds API results' );
$res = TIPC_Sync::sync_league( 'nfl', 'full' );
echo 'Full: ' . json_encode( $res ) . "\n";
$games = TIPC_DB::games( 'nfl', 2026 );
ok( 2 === count( $games ), 'Draw loaded from Odds API events' );
echo "   " . $games[0]->round_label . ' ' . $games[0]->home . ' v ' . $games[0]->away . "\n";
ok( 1 === count( array_filter( $GLOBALS['calls'], function ( $u ) { return strpos( $u, '/events' ); } ) ), 'One free events call (no duplicate)' );
delete_transient( 'tipc_odds_checked_nfl' );
ok( TIPC_OddsAPI::results_due( 'nfl', 2026 ), 'Result due 5h after kickoff' );
$sum = TIPC_Sync::tick();
echo 'Tick: ' . json_encode( $sum ) . "\n";
$g = TIPC_DB::games( 'nfl', 2026 )[0];
ok( 'final' === $g->status && 'away' === $g->winner && 31 == $g->away_score, 'NFL result from Odds API' );
// Next full refresh (events, no scores) must not reset the final game.
TIPC_Sync::sync_league( 'nfl', 'full' );
$g = TIPC_DB::games( 'nfl', 2026 )[0];
ok( 'final' === $g->status, 'Refresh keeps final result' );
echo ( $GLOBALS['fails'] ?? 0 ) . " failures\n";
