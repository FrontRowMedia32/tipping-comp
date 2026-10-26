<?php
/**
 * Scoring and leaderboards.
 *
 * Rules (per league, from settings):
 *  - Correct tip = N points (default 1).
 *  - Draws: everyone who tipped scores / nobody scores / only "Draw" tips score.
 *  - Missed tips: no score, or automatically count as the away team (common
 *    office-comp rule) once a tipper has joined that season.
 *  - Margin tiebreaker: tippers predict the winning margin of the first game
 *    each round; the season total of how far off they were breaks ties
 *    (lower is better).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TIPC_Scoring {

	private static function cache_key( $league, $season ) {
		return 'tipc_lb_' . $league . '_' . (int) $season;
	}

	public static function flush( $league, $season ) {
		delete_transient( self::cache_key( $league, $season ) );
	}

	/**
	 * Is a pick correct for a finished game under this league's rules?
	 */
	public static function is_correct( $pick, $winner, $draw_rule ) {
		if ( null === $pick || '' === $pick || null === $winner ) {
			return false;
		}
		if ( 'draw' === $winner ) {
			if ( 'draw_pick' === $draw_rule ) {
				return 'draw' === $pick;
			}
			return 'everyone' === $draw_rule;
		}
		return $pick === $winner;
	}

	/**
	 * How far a margin prediction was from the result (for the tiebreaker).
	 * The prediction is the margin of the tipped team; a draw tip predicts 0.
	 */
	public static function margin_diff( $pick, $margin, $home_score, $away_score ) {
		$actual_home = (int) $home_score - (int) $away_score;
		if ( 'home' === $pick ) {
			$predicted_home = (int) $margin;
		} elseif ( 'away' === $pick ) {
			$predicted_home = -1 * (int) $margin;
		} else {
			$predicted_home = 0;
		}
		return abs( $actual_home - $predicted_home );
	}

	/**
	 * Full season standings. Cached until results change.
	 */
	public static function standings( $league, $season ) {
		$key    = self::cache_key( $league, $season );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$data = self::compute( $league, $season );
		set_transient( $key, $data, 12 * HOUR_IN_SECONDS );
		return $data;
	}

	public static function compute( $league, $season ) {
		$conf      = TIPC_Settings::league( $league );
		$draw_rule = $conf ? $conf['draw_rule'] : 'everyone';
		$missed    = $conf ? $conf['missed_tips'] : 'none';
		$use_margin = $conf ? ! empty( $conf['margin'] ) : false;
		$points_per = $conf ? max( 1, (int) $conf['points'] ) : 1;

		$games  = TIPC_DB::games( $league, $season );
		$first  = $use_margin ? array_flip( TIPC_DB::first_games( $league, $season ) ) : array(); // game_id => round
		$finals = array();
		foreach ( $games as $g ) {
			if ( 'final' === $g->status && null !== $g->winner ) {
				$finals[ (int) $g->id ] = $g;
			}
		}

		$tips = array();
		foreach ( TIPC_DB::season_tips( $league, $season ) as $t ) {
			$tips[ (int) $t->user_id ][ (int) $t->game_id ] = $t;
		}

		$rows   = array();
		$rounds = array();
		foreach ( $finals as $g ) {
			$rounds[ (int) $g->round ] = true;
		}
		ksort( $rounds );

		foreach ( $tips as $user_id => $user_tips ) {
			$row = array(
				'user_id'      => $user_id,
				'name'         => '',
				'points'       => 0,
				'correct'      => 0,
				'tipped'       => 0,
				'margin_diff'  => 0,
				'round_points' => array(),
				'round_margin' => array(),
			);

			foreach ( $finals as $game_id => $g ) {
				$tip  = isset( $user_tips[ $game_id ] ) ? $user_tips[ $game_id ] : null;
				$pick = $tip ? $tip->pick : ( 'away' === $missed ? 'away' : null );
				$r    = (int) $g->round;

				if ( ! isset( $row['round_points'][ $r ] ) ) {
					$row['round_points'][ $r ] = 0;
				}

				if ( null !== $pick ) {
					$row['tipped']++;
					if ( self::is_correct( $pick, $g->winner, $draw_rule ) ) {
						$row['points']            += $points_per;
						$row['correct']++;
						$row['round_points'][ $r ] += $points_per;
					}
				}

				if ( $use_margin && isset( $first[ $game_id ] ) ) {
					$m    = ( $tip && null !== $tip->margin ) ? (int) $tip->margin : 0;
					$diff = self::margin_diff( $tip ? $tip->pick : null, $m, $g->home_score, $g->away_score );
					$row['margin_diff']         += $diff;
					$row['round_margin'][ $r ] = $diff;
				}
			}

			$user        = get_userdata( $user_id );
			$row['name'] = $user ? $user->display_name : 'Tipper #' . $user_id;
			$rows[]      = $row;
		}

		usort( $rows, array( __CLASS__, 'sort_overall' ) );
		self::rank( $rows, 'points', 'margin_diff' );

		return array(
			'rows'        => $rows,
			'rounds'      => array_keys( $rounds ),
			'use_margin'  => $use_margin,
			'computed_at' => time(),
		);
	}

	public static function sort_overall( $a, $b ) {
		if ( $a['points'] !== $b['points'] ) {
			return $b['points'] - $a['points'];
		}
		if ( $a['margin_diff'] !== $b['margin_diff'] ) {
			return $a['margin_diff'] - $b['margin_diff'];
		}
		return strcasecmp( $a['name'], $b['name'] );
	}

	/**
	 * Standings for a single round.
	 */
	public static function round_standings( $league, $season, $round ) {
		$data = self::standings( $league, $season );
		$rows = array();
		foreach ( $data['rows'] as $row ) {
			if ( ! isset( $row['round_points'][ $round ] ) ) {
				continue;
			}
			$row['points']      = $row['round_points'][ $round ];
			$row['margin_diff'] = isset( $row['round_margin'][ $round ] ) ? $row['round_margin'][ $round ] : 0;
			$rows[]             = $row;
		}
		usort( $rows, array( __CLASS__, 'sort_overall' ) );
		self::rank( $rows, 'points', 'margin_diff' );
		$data['rows'] = $rows;
		return $data;
	}

	/**
	 * Add 'rank' with ties sharing a position (1, 2, 2, 4).
	 */
	private static function rank( array &$rows, $a, $b ) {
		$prev = null;
		$rank = 0;
		foreach ( $rows as $i => &$row ) {
			$sig = $row[ $a ] . '|' . $row[ $b ];
			if ( $sig !== $prev ) {
				$rank = $i + 1;
				$prev = $sig;
			}
			$row['rank'] = $rank;
		}
		unset( $row );
	}
}
