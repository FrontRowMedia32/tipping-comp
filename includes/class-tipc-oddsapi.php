<?php
/**
 * The Odds API (https://the-odds-api.com) as the results source.
 *
 * Each league's normal feed still loads the season draw and round numbers.
 * The Odds API then:
 *  - keeps kickoff times current from /events (free, uses no credits), and
 *  - supplies final scores from /scores?daysFrom=3 (2 credits per call).
 *
 * To stay inside the free 500 credits a month, scores are only requested
 * once a game should have finished, and at most every 30 minutes per league.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TIPC_OddsAPI {

	const BASE           = 'https://api.the-odds-api.com/v4/sports/';
	const CHECK_INTERVAL = 30 * MINUTE_IN_SECONDS;
	const USAGE_OPTION   = 'tipc_oddsapi_usage';

	private static $sports = array(
		'nrl' => 'rugbyleague_nrl',
		'afl' => 'aussierules_afl',
		'epl' => 'soccer_epl',
		'nfl' => 'americanfootball_nfl',
	);

	/** Roughly how long after kickoff a game is normally over. */
	private static $duration = array(
		'nrl' => 110,
		'afl' => 150,
		'epl' => 115,
		'nfl' => 200,
	);

	/** Words that don't help tell teams apart. */
	private static $stopwords = array( 'fc', 'afc', 'the', 'and', 'club', 'football', 'rlfc', 'sc', 'hove' );

	/** Short forms some feeds use. */
	private static $aliases = array(
		'man'    => 'manchester',
		'utd'    => 'united',
		'spurs'  => 'tottenham',
		'wolves' => 'wolverhampton',
		'nottm'  => 'nottingham',
		'gws'    => 'greater western sydney',
		'st'     => 'saint',
		'sth'    => 'south',
		'nz'     => 'new zealand',
	);

	public static function key() {
		return TIPC_Settings::api_key( 'oddsapi' );
	}

	public static function is_results_source( $league ) {
		$conf = TIPC_Settings::league( $league );
		if ( ! $conf || '' === self::key() ) {
			return false;
		}
		return 'oddsapi' === $conf['provider'] || ( isset( $conf['results_source'] ) && 'oddsapi' === $conf['results_source'] );
	}

	/**
	 * Upcoming events for a league (free).
	 */
	public static function events( $league ) {
		return self::get( $league, 'events' );
	}

	/* ---------- HTTP ---------- */

	private static function get( $league, $endpoint, array $args = array() ) {
		if ( ! isset( self::$sports[ $league ] ) ) {
			return new WP_Error( 'tipc_odds', 'The Odds API doesn\'t cover this league.' );
		}
		if ( '' === self::key() ) {
			return new WP_Error( 'tipc_odds', 'Add your Odds API key under Tipping Comp > Settings.' );
		}
		$url      = add_query_arg(
			array_merge(
				array(
					'apiKey'     => self::key(),
					'dateFormat' => 'iso',
				),
				$args
			),
			self::BASE . self::$sports[ $league ] . '/' . $endpoint
		);
		$response = wp_remote_get( $url, array( 'timeout' => 25 ) );
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'tipc_odds', 'Could not reach The Odds API: ' . $response->get_error_message() );
		}

		$remaining = wp_remote_retrieve_header( $response, 'x-requests-remaining' );
		$used      = wp_remote_retrieve_header( $response, 'x-requests-used' );
		if ( '' !== (string) $remaining ) {
			update_option(
				self::USAGE_OPTION,
				array(
					'remaining' => (int) $remaining,
					'used'      => (int) $used,
					'time'      => time(),
				),
				false
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 !== $code ) {
			$msg = is_array( $body ) && ! empty( $body['message'] ) ? $body['message'] : 'HTTP ' . $code;
			if ( 401 === $code ) {
				$msg = 'The Odds API rejected the key. Check it under Tipping Comp > Settings. (' . $msg . ')';
			} elseif ( 429 === $code ) {
				$msg = 'Out of Odds API credits for this month. Results will come from the backup feed. (' . $msg . ')';
			}
			return new WP_Error( 'tipc_odds_' . $code, $msg );
		}
		if ( ! is_array( $body ) ) {
			return new WP_Error( 'tipc_odds', 'Unexpected response from The Odds API.' );
		}
		return $body;
	}

	public static function usage() {
		$u = get_option( self::USAGE_OPTION );
		return is_array( $u ) ? $u : null;
	}

	/* ---------- Matching ---------- */

	public static function tokens( $name ) {
		$name = remove_accents( strtolower( (string) $name ) );
		$name = str_replace( array( '&', "'", '’', '.' ), array( ' and ', '', '', '' ), $name );
		$name = preg_replace( '/[^a-z0-9]+/', ' ', $name );
		$out  = array();
		foreach ( preg_split( '/\s+/', trim( $name ) ) as $t ) {
			if ( '' === $t || in_array( $t, self::$stopwords, true ) ) {
				continue;
			}
			if ( isset( self::$aliases[ $t ] ) ) {
				foreach ( explode( ' ', self::$aliases[ $t ] ) as $a ) {
					$out[ $a ] = true;
				}
				continue;
			}
			$out[ $t ] = true;
		}
		return array_keys( $out );
	}

	/**
	 * 0..1: share of the shorter name's words found in the longer one, so
	 * "Sea Eagles" matches "Manly Warringah Sea Eagles".
	 */
	public static function similarity( $a, $b ) {
		$ta = self::tokens( $a );
		$tb = self::tokens( $b );
		if ( ! $ta || ! $tb ) {
			return 0;
		}
		$common = count( array_intersect( $ta, $tb ) );
		return $common / min( count( $ta ), count( $tb ) );
	}

	/**
	 * Find our game for an Odds API event. Returns [game, swapped] or null.
	 */
	public static function match( $event, array $games, array $used = array() ) {
		$t    = strtotime( $event['commence_time'] );
		$best = null;
		foreach ( $games as $g ) {
			if ( isset( $used[ (int) $g->id ] ) ) {
				continue;
			}
			if ( '' !== (string) $g->odds_id ) {
				if ( $g->odds_id === $event['id'] ) {
					return array( $g, self::is_swapped( $event, $g ) );
				}
				continue;
			}
			$diff = abs( strtotime( $g->kickoff . ' UTC' ) - $t );
			if ( $diff > 2 * DAY_IN_SECONDS ) {
				continue;
			}
			foreach ( array( false, true ) as $swapped ) {
				$h = self::similarity( $swapped ? $g->away : $g->home, $event['home_team'] );
				$a = self::similarity( $swapped ? $g->home : $g->away, $event['away_team'] );
				if ( $h < 0.5 || $a < 0.5 ) {
					continue;
				}
				// Name fit matters most; kickoff closeness breaks ties.
				$score = $h + $a - ( $diff / ( 4 * DAY_IN_SECONDS ) ) - ( $swapped ? 0.25 : 0 );
				if ( ! $best || $score > $best[2] ) {
					$best = array( $g, $swapped, $score );
				}
			}
		}
		return $best ? array( $best[0], $best[1] ) : null;
	}

	private static function is_swapped( $event, $g ) {
		$straight = self::similarity( $g->home, $event['home_team'] ) + self::similarity( $g->away, $event['away_team'] );
		$swapped  = self::similarity( $g->away, $event['home_team'] ) + self::similarity( $g->home, $event['away_team'] );
		return $swapped > $straight;
	}

	/** Games of the current season close enough in time to match. */
	private static function candidates( $league, $season, array $events ) {
		global $wpdb;
		if ( empty( $events ) ) {
			return array();
		}
		$times = array_map(
			function ( $e ) {
				return strtotime( $e['commence_time'] );
			},
			$events
		);
		$from  = gmdate( 'Y-m-d H:i:s', min( $times ) - 3 * DAY_IN_SECONDS );
		$to    = gmdate( 'Y-m-d H:i:s', max( $times ) + 3 * DAY_IN_SECONDS );
		$t     = TIPC_DB::games_table();
		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$t} WHERE league = %s AND season = %d AND kickoff BETWEEN %s AND %s ORDER BY kickoff", $league, $season, $from, $to )
		);
	}

	/* ---------- Scheduling ---------- */

	/**
	 * Is a scores check worth spending credits on right now?
	 */
	public static function results_due( $league, $season ) {
		global $wpdb;
		if ( get_transient( 'tipc_odds_checked_' . $league ) ) {
			return false;
		}
		$t     = TIPC_DB::games_table();
		$after = isset( self::$duration[ $league ] ) ? self::$duration[ $league ] : 120;
		$from  = gmdate( 'Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS + HOUR_IN_SECONDS );
		$to    = gmdate( 'Y-m-d H:i:s', time() - $after * MINUTE_IN_SECONDS );
		$n     = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$t} WHERE league = %s AND season = %d AND status IN ('scheduled','live') AND manual = 0 AND kickoff BETWEEN %s AND %s",
				$league,
				$season,
				$from,
				$to
			)
		);
		return $n > 0;
	}

	/* ---------- Syncing ---------- */

	/**
	 * Free: match upcoming events and keep kickoff times current.
	 */
	public static function sync_events( $league ) {
		$conf   = TIPC_Settings::league( $league );
		$season = (int) $conf['season'];
		$events = self::get( $league, 'events' );
		if ( is_wp_error( $events ) ) {
			TIPC_Sync::log( $league, 'odds', false, $events->get_error_message() );
			return $events;
		}
		$out = self::apply( $league, $season, $events, false );
		TIPC_Sync::log( $league, 'odds', true, sprintf( 'Odds API fixtures: %d events, %d matched, %d kickoff times updated.', count( $events ), $out['matched'], $out['kickoffs'] ) );
		return $out;
	}

	/**
	 * 2 credits: live and recent results (last 3 days).
	 */
	public static function sync_scores( $league ) {
		$conf   = TIPC_Settings::league( $league );
		$season = (int) $conf['season'];
		set_transient( 'tipc_odds_checked_' . $league, 1, self::CHECK_INTERVAL - 60 );
		$events = self::get( $league, 'scores', array( 'daysFrom' => 3 ) );
		if ( is_wp_error( $events ) ) {
			TIPC_Sync::log( $league, 'odds', false, $events->get_error_message() );
			return $events;
		}
		$out = self::apply( $league, $season, $events, true );
		if ( $out['results_changed'] > 0 ) {
			TIPC_Sync::results_changed( $league, $season );
		}
		TIPC_Sync::log(
			$league,
			'odds',
			true,
			sprintf( 'Odds API results: %d games checked, %d matched, %d results changed.', count( $events ), $out['matched'], $out['results_changed'] )
				. ( $out['unmatched'] ? ' Unmatched: ' . implode( ', ', array_slice( $out['unmatched'], 0, 5 ) ) . '.' : '' )
		);
		return $out;
	}

	/**
	 * Match events to our games and apply kickoff times and (optionally) results.
	 */
	public static function apply( $league, $season, array $events, $with_scores ) {
		global $wpdb;
		$table = TIPC_DB::games_table();
		$games = self::candidates( $league, $season, $events );
		$used  = array();
		$out   = array(
			'matched'         => 0,
			'kickoffs'        => 0,
			'results_changed' => 0,
			'unmatched'       => array(),
		);

		foreach ( $events as $e ) {
			if ( empty( $e['id'] ) || empty( $e['home_team'] ) || empty( $e['away_team'] ) || empty( $e['commence_time'] ) ) {
				continue;
			}
			$m = self::match( $e, $games, $used );
			if ( ! $m ) {
				$out['unmatched'][] = $e['home_team'] . ' v ' . $e['away_team'];
				continue;
			}
			list( $g, $swapped ) = $m;
			$used[ (int) $g->id ] = true;
			$out['matched']++;

			$changes = array();
			if ( $g->odds_id !== $e['id'] ) {
				$changes['odds_id'] = substr( (string) $e['id'], 0, 64 );
			}

			$kick = TIPC_Provider::to_utc( $e['commence_time'] );
			if ( $kick && $kick !== $g->kickoff && 'scheduled' === $g->status ) {
				$changes['kickoff'] = $kick;
				$out['kickoffs']++;
			}

			if ( $with_scores && ! (int) $g->manual && ! empty( $e['scores'] ) && is_array( $e['scores'] ) ) {
				$hs = null;
				$as = null;
				foreach ( $e['scores'] as $sc ) {
					if ( ! isset( $sc['name'], $sc['score'] ) || ! is_numeric( $sc['score'] ) ) {
						continue;
					}
					if ( $sc['name'] === $e['home_team'] ) {
						$hs = (int) $sc['score'];
					} elseif ( $sc['name'] === $e['away_team'] ) {
						$as = (int) $sc['score'];
					}
				}
				if ( null !== $hs && null !== $as ) {
					if ( $swapped ) {
						list( $hs, $as ) = array( $as, $hs );
					}
					$status = ! empty( $e['completed'] ) ? 'final' : 'live';
					// Never turn a finished game back into a live one.
					if ( 'final' === $g->status && 'live' === $status ) {
						$status = null;
					}
					if ( $status ) {
						$norm = TIPC_Provider::normalise(
							array(
								'ext_id'     => $g->ext_id,
								'kickoff'    => $g->kickoff,
								'home'       => $g->home,
								'away'       => $g->away,
								'status'     => $status,
								'home_score' => $hs,
								'away_score' => $as,
							)
						);
						if ( $norm['status'] !== $g->status || (string) $norm['home_score'] !== (string) $g->home_score || (string) $norm['away_score'] !== (string) $g->away_score ) {
							$changes['status']     = $norm['status'];
							$changes['home_score'] = $norm['home_score'];
							$changes['away_score'] = $norm['away_score'];
							$changes['winner']     = $norm['winner'];
							if ( 'final' === $norm['status'] || 'final' === $g->status ) {
								$out['results_changed']++;
							}
						}
					}
				}
			}

			if ( $changes ) {
				$changes['updated_at'] = current_time( 'mysql', true );
				$wpdb->update( $table, $changes, array( 'id' => (int) $g->id ) );
			}
		}
		return $out;
	}
}
