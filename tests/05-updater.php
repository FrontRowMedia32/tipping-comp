<?php
$_SERVER['HTTP_HOST'] = 'localhost:8088'; $_SERVER['REQUEST_URI'] = '/';
require $argv[1] . '/wp-load.php';
add_filter( 'pre_http_request', function ( $pre, $a, $url ) { return new WP_Error( 'x', 'offline' ); }, 1, 3 );
echo 'Default repo: ' . TIPC_Updater::repo() . "\n";
$c = TIPC_Updater::checker();
echo $c ? "Update checker loaded for " . get_class( $c ) . "\n" : "Update checker NOT loaded\n";
TIPC_Updater::init(); // Second call must be harmless.
$s = get_option( 'tipc_settings' ); $s['update_repo'] = 'https://github.com/example-org/other-repo/'; update_option( 'tipc_settings', $s ); TIPC_Settings::flush();
echo 'Override parsed: ' . TIPC_Updater::repo() . "\n";
if ( $c ) { $c->checkForUpdates(); }
// Every file the update checker needs at update time must ship with the plugin.
$need = array( 'vendor/PucReadmeParser.php', 'vendor/Parsedown.php', 'Puc/v5p7/Vcs/GitHubApi.php', 'Puc/v5p7/Plugin/UpdateChecker.php' );
$missing = array();
foreach ( $need as $f ) { if ( ! file_exists( TIPC_DIR . 'vendor/plugin-update-checker/' . $f ) ) { $missing[] = $f; } }
echo $missing ? 'Missing library files: ' . implode( ', ', $missing ) . "\n" : "Library complete\n";
echo ( $c && ! $missing && 'FrontRowMedia32/tipping-comp' === TIPC_Updater::DEFAULT_REPO ) ? "No fatal errors\n" : "Updater not configured\n";
