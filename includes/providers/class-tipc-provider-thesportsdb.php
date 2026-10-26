<?php
/**
 * Any league via TheSportsDB v1 (premium key for full seasons).
 * https://www.thesportsdb.com/api/v1/json/{key}/eventsseason.php?id=4416&s=2026
 * Works as a backup provider for all four leagues.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TIPC_Provider_TheSportsDB extends TIPC_Provider {

	const BASE = 'https://www.thesportsdb.com/api/v1/json/';

	private static $league_ids = array(
		'nrl' => 4416,
		'afl' => 4456,
		'epl' => 4328,
		'nfl' => 4391,
	);

	private function key() {
		$key = TIPC_Settings::api_key( 'thesportsdb' );
		return '' === $key ? '123' : rawurlencode( $key ); // 123 = limited free test key.
	}

	private function season_string() {
		return 'epl' === $this->league ? $this->season . '-' . ( $this->season + 1 ) : (string) $this->season;
	}

	private function league_id() {
		return isset( self::$league_ids[ $this->league ] ) ? self::$league_ids[ $this->league ] : 0;
	}

	public function fetch_season() {
		$url  = self::BASE . $this->key() . '/eventsseason.php?id=' . $this->league_id() . '&s=' . rawurlencode( $this->season_string() );
		$data = $this->get_json( $url );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$games = $this->parse( $data );
		if ( empty( $games ) ) {
			$msg = 'TheSportsDB returned no games for ' . $this->season_string() . '.';
			if ( '' === TIPC_Settings::api_key( 'thesportsdb' ) ) {
				$msg .= ' Full seasons need a premium key (add it under Tipping Comp > Settings).';
			}
			return new WP_Error( 'tipc_tsdb', $msg );
		}
		return $games;
	}

	public function fetch_rounds( array $rounds ) {
		$games = array();
		foreach ( array_unique( array_map( 'intval', $rounds ) ) as $round ) {
			$url  = self::BASE . $this->key() . '/eventsround.php?id=' . $this->league_id() . '&r=' . $round . '&s=' . rawurlencode( $this->season_string() );
			$data = $this->get_json( $url );
			if ( is_wp_error( $data ) ) {
				return $data;
			}
			$games = array_merge( $games, $this->parse( $data ) );
		}
		return $games;
	}

	private function parse( $data ) {
		$games  = array();
		$events = isset( $data['events'] ) && is_array( $data['events'] ) ? $data['events'] : array();
		$now    = time();

		foreach ( $events as $e ) {
			$kickoff = null;
			if ( ! empty( $e['strTimestamp'] ) ) {
				$kickoff = self::to_utc( $e['strTimestamp'] );
			} elseif ( ! empty( $e['dateEvent'] ) ) {
				$kickoff = self::to_utc( $e['dateEvent'] . ' ' . ( ! empty( $e['strTime'] ) ? $e['strTime'] : '00:00:00' ) );
			}

			$hs  = isset( $e['intHomeScore'] ) && is_numeric( $e['intHomeScore'] ) ? (int) $e['intHomeScore'] : null;
			$as  = isset( $e['intAwayScore'] ) && is_numeric( $e['intAwayScore'] ) ? (int) $e['intAwayScore'] : null;
			$raw = strtolower( trim( isset( $e['strStatus'] ) ? (string) $e['strStatus'] : '' ) );

			if ( ( isset( $e['strPostponed'] ) && 'yes' === strtolower( (string) $e['strPostponed'] ) ) || false !== strpos( $raw, 'postp' ) ) {
				$status = 'postponed';
			} elseif ( false !== strpos( $raw, 'cancel' ) || false !== strpos( $raw, 'abandon' ) ) {
				$status = 'cancelled';
			} elseif ( in_array( $raw, array( 'match finished', 'ft', 'aet', 'aot', 'pen', 'finished', 'final', 'after over time', 'after extra time', 'awarded' ), true ) ) {
				$status = 'final';
			} elseif ( in_array( $raw, array( '', 'ns', 'not started', 'tbd', 'time to be defined' ), true ) ) {
				// No status but scores well after kickoff = finished.
				$status = ( null !== $hs && null !== $as && $kickoff && strtotime( $kickoff . ' UTC' ) < $now - 5 * HOUR_IN_SECONDS ) ? 'final' : 'scheduled';
			} else {
				$status = 'live';
			}

			$round = isset( $e['intRound'] ) ? (int) $e['intRound'] : 0;
			$label = $this->label( $round );

			$games[] = array(
				'ext_id'      => isset( $e['idEvent'] ) ? (string) $e['idEvent'] : '',
				'round'       => $round,
				'round_label' => $label,
				'kickoff'     => $kickoff,
				'home'        => isset( $e['strHomeTeam'] ) ? $e['strHomeTeam'] : '',
				'away'        => isset( $e['strAwayTeam'] ) ? $e['strAwayTeam'] : '',
				'home_logo'   => isset( $e['strHomeTeamBadge'] ) ? (string) $e['strHomeTeamBadge'] : '',
				'away_logo'   => isset( $e['strAwayTeamBadge'] ) ? (string) $e['strAwayTeamBadge'] : '',
				'venue'       => isset( $e['strVenue'] ) ? (string) $e['strVenue'] : '',
				'status'      => $status,
				'home_score'  => 'scheduled' === $status ? null : $hs,
				'away_score'  => 'scheduled' === $status ? null : $as,
			);
		}
		return $this->clean( $games );
	}

	private function label( $round ) {
		switch ( $this->league ) {
			case 'epl':
				return 'Matchweek ' . $round;
			case 'nfl':
				return $round <= 18 ? 'Week ' . $round : 'Playoffs';
			case 'afl':
				return 0 === $round ? 'Opening Round' : 'Round ' . $round;
		}
		return 'Round ' . $round;
	}
}
