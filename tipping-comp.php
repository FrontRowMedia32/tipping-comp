<?php
/**
 * Plugin Name:       Tipping Comp
 * Description:       Automatic footy tipping competitions for NRL, AFL, English Premier League and NFL. Fixtures and results sync themselves from live data feeds, tips lock at kickoff and leaderboards update on their own.
 * Version:           1.3.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Front Row Media
 * Author URI:        https://frontrowmedia.com.au
 * License:           GPL-2.0-or-later
 * Text Domain:       tipping-comp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TIPC_VERSION', '1.3.0' );
define( 'TIPC_DB_VERSION', '2' );
define( 'TIPC_FILE', __FILE__ );
define( 'TIPC_DIR', plugin_dir_path( __FILE__ ) );
define( 'TIPC_URL', plugin_dir_url( __FILE__ ) );

require_once TIPC_DIR . 'includes/class-tipc-settings.php';
require_once TIPC_DIR . 'includes/class-tipc-db.php';
require_once TIPC_DIR . 'includes/providers/class-tipc-provider.php';
require_once TIPC_DIR . 'includes/providers/class-tipc-provider-squiggle.php';
require_once TIPC_DIR . 'includes/providers/class-tipc-provider-nrlcom.php';
require_once TIPC_DIR . 'includes/providers/class-tipc-provider-espn.php';
require_once TIPC_DIR . 'includes/providers/class-tipc-provider-footballdata.php';
require_once TIPC_DIR . 'includes/providers/class-tipc-provider-thesportsdb.php';
require_once TIPC_DIR . 'includes/providers/class-tipc-provider-oddsapi.php';
require_once TIPC_DIR . 'includes/class-tipc-oddsapi.php';
require_once TIPC_DIR . 'includes/class-tipc-sync.php';
require_once TIPC_DIR . 'includes/class-tipc-scoring.php';
require_once TIPC_DIR . 'includes/class-tipc-frontend.php';
require_once TIPC_DIR . 'includes/class-tipc-admin.php';
require_once TIPC_DIR . 'includes/class-tipc-updater.php';

register_activation_hook( __FILE__, array( 'TIPC_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'TIPC_Plugin', 'deactivate' ) );

/**
 * Plugin bootstrap.
 */
class TIPC_Plugin {

	public static function activate() {
		TIPC_DB::install();
		TIPC_Settings::ensure_defaults();
		TIPC_Sync::schedule();
	}

	public static function deactivate() {
		TIPC_Sync::unschedule();
	}

	/**
	 * Settings changed: rules may affect scores, so rebuild leaderboards.
	 */
	public static function settings_saved( $old, $new ) {
		TIPC_Settings::flush();
		if ( ! is_array( $new ) || empty( $new['leagues'] ) ) {
			return;
		}
		foreach ( $new['leagues'] as $league => $conf ) {
			TIPC_Scoring::flush( $league, (int) $conf['season'] );
			if ( isset( $old['leagues'][ $league ]['season'] ) ) {
				TIPC_Scoring::flush( $league, (int) $old['leagues'][ $league ]['season'] );
			}
		}
	}

	public static function init() {
		if ( get_option( 'tipc_db_version' ) !== TIPC_DB_VERSION ) {
			TIPC_DB::install();
		}
		add_action( 'update_option_' . TIPC_Settings::OPTION, array( __CLASS__, 'settings_saved' ), 10, 2 );
		add_action( 'add_option_' . TIPC_Settings::OPTION, array( 'TIPC_Settings', 'flush' ) );
		TIPC_Sync::init();
		TIPC_Updater::init();
		TIPC_Frontend::init();
		if ( is_admin() ) {
			TIPC_Admin::init();
		}
	}
}

add_action( 'plugins_loaded', array( 'TIPC_Plugin', 'init' ) );
