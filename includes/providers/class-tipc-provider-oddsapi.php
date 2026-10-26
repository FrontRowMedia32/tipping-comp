<?php
/**
 * NFL draw from The Odds API /events endpoint (free, uses no credits).
 *
 * The Odds API has no week numbers, so they're worked out from the NFL
 * calendar: Week 1 starts the Tuesday after Labor Day (US Eastern time).
 * Playoffs use the same rounds as the ESPN provider:
 * 19 Wild Card, 20 Divisional, 21 Conference Championships, 22 Super Bowl.
 * Results come from The Odds API /scores (see TIPC_OddsAPI).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TIPC_Provider_OddsAPI extends TIPC_Provider {

	private static $labels = array(
		19 => 'Wild Card',
		20 => 'Divisional Round',
		21 => 'Conference Championships',
		22 => 'Super Bowl',
	);

	public function fetch_season() {
		if ( 'nfl' !== $this->league ) {
			return new WP_Error( 'tipc_odds', 'The Odds API can only supply the draw for the NFL. Use it as the results source for other leagues.' );
		}
		$events = TIPC_OddsAPI::events( $this->league );
		if ( is_wp_error( $events ) ) {
			return $events;
		}
		$games = array();
		foreach ( $events as $e ) {
			if ( empty( $e['id'] ) || empty( $e['commence_time'] ) ) {
				continue;
			}
			$ts    = strtotime( $e['commence_time'] );
			$round = self::nfl_round( $ts, $this->season );
			if ( null === $round ) {
				continue; // Preseason or another season.
			}
			$games[] = array(
				'ext_id'      => 'odds:' . $e['id'],
				'round'       => $round,
				'round_label' => isset( self::$labels[ $round ] ) ? self::$labels[ $round ] : 'Week ' . $round,
				'kickoff'     => self::to_utc( $e['commence_time'] ),
				'home'        => isset( $e['home_team'] ) ? $e['home_team'] : '',
				'away'        => isset( $e['away_team'] ) ? $e['away_team'] : '',
				'status'      => 'scheduled',
			);
		}
		return $this->clean( $games );
	}

	/**
	 * NFL round for a kickoff time, or null if outside the season.
	 */
	public static function nfl_round( $ts, $season ) {
		$tz = new DateTimeZone( 'America/New_York' );
		try {
			$labor = new DateTime( 'first monday of september ' . (int) $season, $tz );
		} catch ( Exception $e ) {
			return null;
		}
		$labor->setTime( 0, 0 );
		$week_one = clone $labor;
		$week_one->modify( '+1 day' ); // Tuesday.

		$game = new DateTime( '@' . (int) $ts );
		$game->setTimezone( $tz );
		$game->setTime( 0, 0 );

		$days = (int) floor( ( $game->getTimestamp() - $week_one->getTimestamp() ) / DAY_IN_SECONDS );
		if ( $days < 0 ) {
			return null;
		}
		$week = intdiv( $days, 7 ) + 1;
		if ( $week > 24 ) {
			return null;
		}
		return min( $week, 22 ); // Super Bowl comes after the Pro Bowl bye week.
	}
}
