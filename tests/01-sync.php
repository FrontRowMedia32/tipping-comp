<?php
$_SERVER['HTTP_HOST'] = 'localhost:8088'; $_SERVER['REQUEST_URI'] = '/';
require $argv[1] . '/wp-load.php';
require __DIR__ . '/mock.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
$r = activate_plugin( 'tipping-comp/tipping-comp.php' );
if ( is_wp_error( $r ) ) { die( 'activate: ' . $r->get_error_message() ); }
// Plugin init normally runs on plugins_loaded; it already ran if active before. Run manually if needed.
if ( ! has_action( 'tipc_tick' ) ) { TIPC_Plugin::init(); }
echo "Scheduled: " . ( wp_next_scheduled( 'tipc_tick' ) ? 'yes' : 'no' ) . "\n";

$s = get_option( 'tipc_settings' );
foreach ( array( 'nrl', 'afl', 'epl', 'nfl' ) as $l ) { $s['leagues'][ $l ]['enabled'] = 1; $s['leagues'][ $l ]['season'] = 2026; } $s['leagues']['nfl']['provider'] = 'espn';
$s['keys']['footballdata'] = 'testkey';
update_option( 'tipc_settings', $s );
TIPC_Settings::flush();

foreach ( array( 'nrl', 'afl', 'epl', 'nfl' ) as $l ) {
	$res = TIPC_Sync::sync_league( $l, 'full' );
	echo strtoupper( $l ) . ': ' . ( is_wp_error( $res ) ? 'ERROR ' . $res->get_error_message() : json_encode( $res ) ) . "\n";
	foreach ( TIPC_DB::games( $l, 2026 ) as $g ) {
		printf( "   r%-2d %-12s %s  %-18s v %-18s %-9s %s-%s winner=%s logo=%s\n", $g->round, $g->round_label, $g->kickoff, $g->home, $g->away, $g->status, $g->home_score, $g->away_score, $g->winner, $g->home_logo ? 'y' : 'n' );
	}
}
echo "UA: " . $GLOBALS['tipc_test_ua'] . "\n";
$fdh = array_filter( $GLOBALS['tipc_test_headers'], function( $h ) { return isset( $h['X-Auth-Token'] ); } );
echo "football-data auth header sent: " . ( $fdh ? 'yes' : 'no' ) . "\n";
echo "ESPN calls: " . count( array_filter( $GLOBALS['tipc_test_urls'], function( $u ) { return strpos( $u, 'espn' ); } ) ) . "\n";
echo "NRL calls: " . count( array_filter( $GLOBALS['tipc_test_urls'], function( $u ) { return strpos( $u, 'nrl.com' ); } ) ) . "\n";

// Re-sync: should be no changes.
$res = TIPC_Sync::sync_league( 'afl', 'full' );
echo "AFL resync: " . json_encode( $res ) . "\n";
echo "Current rounds: nrl=" . TIPC_DB::current_round( 'nrl', 2026 ) . " afl=" . TIPC_DB::current_round( 'afl', 2026 ) . " epl=" . TIPC_DB::current_round( 'epl', 2026 ) . " nfl=" . TIPC_DB::current_round( 'nfl', 2026 ) . "\n";
echo "Active rounds afl: " . json_encode( TIPC_DB::active_rounds( 'afl', 2026 ) ) . "\n";
echo "First games nrl: " . json_encode( TIPC_DB::first_games( 'nrl', 2026 ) ) . "\n";
