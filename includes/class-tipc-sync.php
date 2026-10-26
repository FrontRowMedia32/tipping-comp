<?php
/**
 * Fixture/result syncing, scheduling, logging and cache purging.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TIPC_Sync {

	const HOOK          = 'tipc_tick';
	const SCHEDULE      = 'tipc_15min';
	const FULL_INTERVAL = 6 * HOUR_IN_SECONDS; // Full season refresh (catches reschedules).
	const STATE_OPTION  = 'tipc_sync_state';
	const LOG_OPTION    = 'tipc_sync_log';

	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) );
		add_action( self::HOOK, array( __CLASS__, 'tick' ) );
		add_action( 'init', array( __CLASS__, 'maybe_secret_cron' ), 1 );
		add_action( 'init', array( __CLASS__, 'ensure_scheduled' ), 20 );
	}

	public static function cron_schedules( $schedules ) {
		$schedules[ self::SCHEDULE ] = array(
			'interval' => 15 * MINUTE_IN_SECONDS,
			'display'  => 'Every 15 minutes (Tipping Comp)',
		);
		return $schedules;
	}

	public static function schedule() {
		add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) );
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, self::SCHEDULE, self::HOOK );
		}
	}

	public static function unschedule() {
		wp_clear_scheduled_hook( self::HOOK );
	}

	public static function ensure_scheduled() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, self::SCHEDULE, self::HOOK );
		}
	}

	/**
	 * Optional direct trigger: https://site/?tipc_cron=SECRET
	 * Handy if WP-Cron is disabled and you want a dedicated server cron.
	 */
	public static function maybe_secret_cron() {
		if ( empty( $_GET['tipc_cron'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$given = sanitize_text_field( wp_unslash( $_GET['tipc_cron'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$key   = (string) TIPC_Settings::get( 'cron_key' );
		if ( '' === $key || ! hash_equals( $key, $given ) ) {
			status_header( 403 );
			exit( 'Forbidden' );
		}
		nocache_headers();
		$summary = self::tick();
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo 'OK ' . esc_html( wp_json_encode( $summary ) );
		exit;
	}

	/**
	 * The scheduled job. Decides per league whether a full refresh or a
	 * quick result check is due, and does nothing when nothing is on.
	 */
	public static function tick() {
		if ( get_transient( 'tipc_sync_lock' ) ) {
			return array( 'skipped' => 'locked' );
		}
		set_transient( 'tipc_sync_lock', 1, 10 * MINUTE_IN_SECONDS );

		$summary = array();
		try {
			foreach ( TIPC_Settings::enabled_leagues() as $league => $conf ) {
				$season = (int) $conf['season'];
				$last   = self::last_full( $league, $season );

				$failed = self::state( $league, $season, 'last_fail' );

				$odds   = TIPC_OddsAPI::is_results_source( $league );

				if ( 0 === TIPC_DB::count_games( $league, $season ) || time() - $last > self::FULL_INTERVAL ) {
					// After a failed full sync, wait an hour before trying again.
					$res = ( time() - $failed < HOUR_IN_SECONDS ) ? 'waiting after error' : self::sync_league( $league, 'full' );
				} elseif ( $odds ) {
					// The Odds API owns results: check only when games should be over.
					$res = TIPC_OddsAPI::results_due( $league, $season ) ? TIPC_OddsAPI::sync_scores( $league ) : 'idle';
					if ( is_wp_error( $res ) ) {
						// Fall back to the free feed for this check.
						$rounds = TIPC_DB::active_rounds( $league, $season );
						if ( $rounds ) {
							self::sync_league( $league, 'rounds', $rounds );
						}
					}
				} else {
					$rounds = TIPC_DB::active_rounds( $league, $season );
					$res    = empty( $rounds ) ? 'idle' : self::sync_league( $league, 'rounds', $rounds );
				}
				$summary[ $league ] = is_wp_error( $res ) ? 'error: ' . $res->get_error_message() : $res;
			}
		} finally {
			delete_transient( 'tipc_sync_lock' );
		}
		return $summary;
	}

	/**
	 * Fetch and store one league. $mode = full|rounds.
	 * Returns a summary array or WP_Error.
	 */
	public static function sync_league( $league, $mode = 'full', array $rounds = array() ) {
		$conf = TIPC_Settings::league( $league );
		if ( ! $conf ) {
			return new WP_Error( 'tipc_league', 'Unknown league.' );
		}
		$season   = (int) $conf['season'];
		$provider = TIPC_Provider::make( $league, $season );
		if ( is_wp_error( $provider ) ) {
			self::log( $league, $mode, false, $provider->get_error_message() );
			return $provider;
		}

		$games = ( 'rounds' === $mode && ! empty( $rounds ) ) ? $provider->fetch_rounds( $rounds ) : $provider->fetch_season();
		if ( is_wp_error( $games ) ) {
			if ( 'full' === $mode ) {
				self::set_state( $league, $season, 'last_fail', time() );
			}
			self::log( $league, $mode, false, $games->get_error_message() );
			return $games;
		}

		$odds   = TIPC_OddsAPI::is_results_source( $league );
		// When the Odds API is the draw source too, it also owns results.
		$result = self::store( $league, $season, $games, ( $odds && 'oddsapi' !== $conf['provider'] ) ? 'backup' : 'primary' );

		if ( 'full' === $mode ) {
			self::set_state( $league, $season, 'last_full', time() );
			self::set_state( $league, $season, 'last_fail', 0 );
			if ( $odds && 'oddsapi' !== $conf['provider'] ) {
				TIPC_OddsAPI::sync_events( $league ); // Free: matches games and keeps kickoff times current.
			}
		}

		if ( $result['results_changed'] > 0 ) {
			self::results_changed( $league, $season );
		}

		self::log(
			$league,
			$mode,
			true,
			sprintf(
				'%d games fetched: %d new, %d updated, %d results changed.',
				count( $games ),
				$result['inserted'],
				$result['updated'],
				$result['results_changed']
			)
		);

		return $result;
	}

	/**
	 * Upsert normalised games. Games marked "manual" keep their admin-entered
	 * result; fixture details still refresh. $results_mode 'backup' means
	 * another source (The Odds API) owns results and this feed only fills gaps.
	 */
	public static function store( $league, $season, array $games, $results_mode = 'primary' ) {
		global $wpdb;
		$table = TIPC_DB::games_table();
		$now   = current_time( 'mysql', true );
		$out   = array(
			'inserted'        => 0,
			'updated'         => 0,
			'results_changed' => 0,
		);

		foreach ( $games as $g ) {
			$g        = TIPC_Provider::normalise( $g );
			$existing = TIPC_DB::find_game( $league, $season, $g['ext_id'] );

			$fixture = array(
				'round'       => $g['round'],
				'round_label' => $g['round_label'],
				'kickoff'     => $g['kickoff'],
				'home'        => $g['home'],
				'away'        => $g['away'],
				'home_logo'   => $g['home_logo'],
				'away_logo'   => $g['away_logo'],
				'venue'       => $g['venue'],
			);
			$result  = array(
				'status'     => $g['status'],
				'home_score' => $g['home_score'],
				'away_score' => $g['away_score'],
				'winner'     => $g['winner'],
			);

			if ( ! $existing ) {
				$wpdb->insert(
					$table,
					array_merge(
						array(
							'league'     => $league,
							'season'     => $season,
							'ext_id'     => $g['ext_id'],
							'manual'     => 0,
							'updated_at' => $now,
						),
						$fixture,
						$result
					)
				);
				$out['inserted']++;
				if ( 'final' === $g['status'] ) {
					$out['results_changed']++;
				}
				continue;
			}

			// Once The Odds API has matched a game, its kickoff time wins.
			if ( 'backup' === $results_mode && '' !== (string) $existing->odds_id ) {
				unset( $fixture['kickoff'] );
			}

			$changes = array();
			foreach ( $fixture as $k => $v ) {
				if ( (string) $existing->$k !== (string) $v ) {
					$changes[ $k ] = $v;
				}
			}
			$result_changed = false;
			$apply_result   = ! (int) $existing->manual;
			// Feeds never turn a finished game back into an unfinished one.
			if ( 'final' === $existing->status && 'final' !== $g['status'] ) {
				$apply_result = false;
			}
			// As a backup source, only fill in results the main source hasn't.
			if ( 'backup' === $results_mode && ( 'final' === $existing->status || 'final' !== $g['status'] ) ) {
				$apply_result = false;
			}
			if ( $apply_result ) {
				foreach ( $result as $k => $v ) {
					$old = $existing->$k;
					if ( ( null === $old ) !== ( null === $v ) || (string) $old !== (string) $v ) {
						$changes[ $k ]  = $v;
						$result_changed = true;
					}
				}
			}

			if ( $changes ) {
				$changes['updated_at'] = $now;
				$wpdb->update( $table, $changes, array( 'id' => (int) $existing->id ) );
				$out['updated']++;
				if ( $result_changed ) {
					$out['results_changed']++;
				}
			}
		}

		return $out;
	}

	/**
	 * Called whenever results change: refresh leaderboards and page caches.
	 */
	public static function results_changed( $league, $season ) {
		TIPC_Scoring::flush( $league, $season );

		if ( TIPC_Settings::get( 'purge_cache' ) ) {
			self::purge_page_caches();
		}

		do_action( 'tipc_results_updated', $league, $season );
	}

	/**
	 * Clear full-page caches so public leaderboards show new results.
	 */
	public static function purge_page_caches() {
		// SiteGround Speed Optimizer (Dynamic Cache, File-based cache).
		if ( function_exists( 'sg_cachepress_purge_everything' ) ) {
			sg_cachepress_purge_everything();
		} elseif ( function_exists( 'sg_cachepress_purge_cache' ) ) {
			sg_cachepress_purge_cache();
		}
		// Other common caching plugins, in case the site moves host.
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
		}
		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			wp_cache_clear_cache();
		}
		if ( function_exists( 'w3tc_flush_all' ) ) {
			w3tc_flush_all();
		}
		do_action( 'litespeed_purge_all' );
		do_action( 'breeze_clear_all_cache' );
	}

	public static function log( $league, $mode, $ok, $message ) {
		$log = get_option( self::LOG_OPTION, array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}
		array_unshift(
			$log,
			array(
				'time'    => time(),
				'league'  => $league,
				'mode'    => $mode,
				'ok'      => $ok ? 1 : 0,
				'message' => substr( (string) $message, 0, 500 ),
			)
		);
		update_option( self::LOG_OPTION, array_slice( $log, 0, 40 ), false );
	}

	public static function last_full( $league, $season ) {
		return self::state( $league, $season, 'last_full' );
	}

	private static function state( $league, $season, $field ) {
		$state = get_option( self::STATE_OPTION, array() );
		$key   = $league . '_' . $season;
		return isset( $state[ $key ][ $field ] ) ? (int) $state[ $key ][ $field ] : 0;
	}

	private static function set_state( $league, $season, $field, $value ) {
		$state = get_option( self::STATE_OPTION, array() );
		if ( ! is_array( $state ) ) {
			$state = array();
		}
		$state[ $league . '_' . $season ][ $field ] = (int) $value;
		update_option( self::STATE_OPTION, $state, false );
	}
}
