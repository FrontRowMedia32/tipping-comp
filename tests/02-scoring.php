<?php
$_SERVER['HTTP_HOST'] = 'localhost:8088'; $_SERVER['REQUEST_URI'] = '/';
require $argv[1] . '/wp-load.php';
require __DIR__ . '/mock.php';
function ok( $cond, $msg ) { echo ( $cond ? 'PASS ' : 'FAIL ' ) . $msg . "\n"; if ( ! $cond ) { $GLOBALS['fails'] = ( $GLOBALS['fails'] ?? 0 ) + 1; } }

$users = array();
foreach ( array( 'alice' => 'Alice', 'bob' => 'Bob', 'cara' => 'Cara', 'dan' => 'Dan' ) as $login => $name ) {
	$u = get_user_by( 'login', $login );
	$users[ $login ] = $u ? $u->ID : wp_insert_user( array( 'user_login' => $login, 'user_pass' => 'pass-123-abc', 'display_name' => $name, 'user_email' => "$login@example.com" ) );
}
$g = function ( $league, $home ) { foreach ( TIPC_DB::games( $league, 2026 ) as $x ) { if ( $x->home === $home ) return $x; } };

// NRL round 1: Sea Eagles won (home), Roosters won (away). First game of round 1 = Sea Eagles v Rabbitohs (36-24, margin 12).
$m1 = $g( 'nrl', 'Sea Eagles' ); $m2 = $g( 'nrl', 'Bulldogs' );
TIPC_DB::save_tip( $users['alice'], $m1->id, 'home', 12 ); TIPC_DB::save_tip( $users['alice'], $m2->id, 'away' );   // 2 correct, margin diff 0
TIPC_DB::save_tip( $users['bob'], $m1->id, 'home', 4 );    TIPC_DB::save_tip( $users['bob'], $m2->id, 'away' );     // 2 correct, diff 8
TIPC_DB::save_tip( $users['cara'], $m1->id, 'away', 6 );   TIPC_DB::save_tip( $users['cara'], $m2->id, 'away' );    // 1 correct, diff |12 - (-6)| = 18
TIPC_DB::save_tip( $users['dan'], $m2->id, 'home' );                                                                   // 0 correct, no tip on m1
TIPC_Scoring::flush( 'nrl', 2026 );
$st = TIPC_Scoring::standings( 'nrl', 2026 );
$names = wp_list_pluck( $st['rows'], 'name' ); $pts = wp_list_pluck( $st['rows'], 'points' ); $md = wp_list_pluck( $st['rows'], 'margin_diff' ); $rk = wp_list_pluck( $st['rows'], 'rank' );
echo "NRL: " . json_encode( array_map( null, $names, $pts, $md, $rk ) ) . "\n";
ok( $names === array( 'Alice', 'Bob', 'Cara', 'Dan' ), 'NRL order uses margin tiebreak' );
ok( $pts === array( 2, 2, 1, 0 ) && $md[0] === 0 && $md[1] === 8 && $md[2] === 18, 'NRL points and margin diffs' );
ok( $rk === array( 1, 2, 3, 4 ), 'NRL ranks' );

// Missed tips = away: Dan didn't tip m1 (home won) so no change; set rule and check Dan's tipped count.
$s = get_option( 'tipc_settings' ); $s['leagues']['nrl']['missed_tips'] = 'away'; update_option( 'tipc_settings', $s ); TIPC_Settings::flush();
TIPC_Scoring::flush( 'nrl', 2026 );
$dan = array_values( array_filter( TIPC_Scoring::standings( 'nrl', 2026 )['rows'], function( $r ) { return $r['name'] === 'Dan'; } ) )[0];
ok( 2 === $dan['tipped'] && 0 === $dan['points'], 'Missed tip auto-filled as away (wrong)' );
$s['leagues']['nrl']['missed_tips'] = 'none'; update_option( 'tipc_settings', $s ); TIPC_Settings::flush();

// AFL draw (Carlton v Collingwood 75-75): rule everyone -> anyone who tipped gets point.
$a1 = $g( 'afl', 'Adelaide' ); $a2 = $g( 'afl', 'Carlton' );
TIPC_DB::save_tip( $users['alice'], $a2->id, 'home' ); TIPC_DB::save_tip( $users['bob'], $a2->id, 'away' ); TIPC_DB::save_tip( $users['cara'], $a1->id, 'away' );
TIPC_Scoring::flush( 'afl', 2026 );
$rows = TIPC_Scoring::standings( 'afl', 2026 )['rows']; $by = array(); foreach ( $rows as $r ) { $by[ $r['name'] ] = $r['points']; }
ok( 1 === $by['Alice'] && 1 === $by['Bob'] && 0 === $by['Cara'], 'AFL draw: everyone who tipped scores' );
$s['leagues']['afl']['draw_rule'] = 'nobody'; update_option( 'tipc_settings', $s ); TIPC_Settings::flush();
$rows = TIPC_Scoring::standings( 'afl', 2026 )['rows']; $by = array(); foreach ( $rows as $r ) { $by[ $r['name'] ] = $r['points']; }
ok( 0 === $by['Alice'] && 0 === $by['Bob'], 'Settings save flushes leaderboard; draw = nobody scores' );
$s['leagues']['afl']['draw_rule'] = 'everyone'; update_option( 'tipc_settings', $s ); TIPC_Settings::flush();

// EPL draw_pick: Arsenal v Chelsea 1-1.
$e1 = $g( 'epl', 'Arsenal' ); $e2 = $g( 'epl', 'Liverpool' );
TIPC_DB::save_tip( $users['alice'], $e1->id, 'draw' ); TIPC_DB::save_tip( $users['alice'], $e2->id, 'home' );
TIPC_DB::save_tip( $users['bob'], $e1->id, 'home' );   TIPC_DB::save_tip( $users['bob'], $e2->id, 'away' );
TIPC_Scoring::flush( 'epl', 2026 );
$rows = TIPC_Scoring::standings( 'epl', 2026 )['rows']; $by = array(); foreach ( $rows as $r ) { $by[ $r['name'] ] = $r['points']; }
ok( 2 === $by['Alice'] && 0 === $by['Bob'], 'EPL: only Draw tips score on a draw' );

// Round standings.
$rs = TIPC_Scoring::round_standings( 'nrl', 2026, 1 );
ok( 'Alice' === $rs['rows'][0]['name'] && 2 === $rs['rows'][0]['points'], 'Round standings' );

// Tick: AFL round 2 has a live game -> rounds sync; others idle. Simulate result arriving.
add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
	if ( false !== strpos( $url, 'squiggle' ) && false !== strpos( $url, 'round=2' ) && ! empty( $GLOBALS['tipc_finish'] ) ) {
		$body = array( 'games' => array( array( 'id' => 103, 'round' => 2, 'roundname' => 'Round 2', 'hteam' => 'Brisbane Lions', 'hteamid' => 2, 'ateam' => 'Carlton', 'ateamid' => 3,
			'unixtime' => time() - 1 * 3600, 'complete' => 100, 'hscore' => 81, 'ascore' => 64, 'venue' => 'Gabba' ) ) );
		return array( 'headers' => array(), 'body' => wp_json_encode( $body ), 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null );
	}
	return $pre;
}, 5, 3 );
$GLOBALS['tipc_finish'] = 1;
TIPC_DB::save_tip( $users['alice'], $g( 'afl', 'Brisbane Lions' )->id, 'home' );
TIPC_Scoring::standings( 'afl', 2026 ); // warm cache
$GLOBALS['tipc_test_urls'] = array();
$sum = TIPC_Sync::tick();
echo "Tick: " . json_encode( $sum ) . "\n";
echo "URLs: " . implode( ' | ', $GLOBALS['tipc_test_urls'] ) . "\n";
ok( is_array( $sum['afl'] ) && 1 === $sum['afl']['results_changed'], 'Tick picked up AFL live result via round check' );
ok( 'idle' === $sum['nrl'] && 'idle' === $sum['epl'] && 'idle' === $sum['nfl'], 'Other leagues idle (nothing on)' );
$rows = TIPC_Scoring::standings( 'afl', 2026 )['rows']; $by = array(); foreach ( $rows as $r ) { $by[ $r['name'] ] = $r['points']; }
ok( 2 === $by['Alice'], 'Leaderboard cache refreshed after result' );

// Manual override: admin sets a result; feed should not overwrite.
global $wpdb;
$x = $g( 'afl', 'Brisbane Lions' );
$wpdb->update( TIPC_DB::games_table(), array( 'manual' => 1, 'home_score' => 50, 'away_score' => 90, 'winner' => 'away' ), array( 'id' => $x->id ) );
TIPC_Sync::sync_league( 'afl', 'rounds', array( 2 ) );
$x = TIPC_DB::get_game( $x->id );
ok( 'away' === $x->winner && 50 === (int) $x->home_score, 'Manual result not overwritten by feed' );

// Full-refresh backoff after error.
$s['leagues']['epl']['provider'] = 'footballdata'; $s['keys']['footballdata'] = ''; update_option( 'tipc_settings', $s ); TIPC_Settings::flush();
$r = TIPC_Sync::sync_league( 'epl', 'full' );
ok( is_wp_error( $r ) && false !== strpos( $r->get_error_message(), 'API key' ), 'Missing key gives a clear error: ' . ( is_wp_error( $r ) ? $r->get_error_message() : '' ) );
$s['keys']['footballdata'] = 'testkey'; update_option( 'tipc_settings', $s ); TIPC_Settings::flush();

// TheSportsDB provider parse.
$p = new TIPC_Provider_TheSportsDB( 'nrl', 2026 );
$tg = $p->fetch_season();
ok( is_array( $tg ) && 2 === count( $tg ) && 'final' === $tg[0]['status'] && 'away' === $tg[0]['winner'] && 'scheduled' === $tg[1]['status'], 'TheSportsDB parsing' );

// Secret cron URL key exists.
ok( strlen( TIPC_Settings::get( 'cron_key' ) ) === 24, 'Cron key generated' );
echo "Log entries: " . count( get_option( 'tipc_sync_log' ) ) . "\n";
echo ( $GLOBALS['fails'] ?? 0 ) . " failures\n";
