<?php
/**
 * English Premier League via football-data.org v4 (free key required).
 * Register at https://www.football-data.org/client/register and paste the
 * key into Tipping Comp > Settings. Season 2026 = the 2026/27 season.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TIPC_Provider_FootballData extends TIPC_Provider {

	const BASE = 'https://api.football-data.org/v4/competitions/PL/matches';

	private function headers() {
		return array( 'X-Auth-Token' => TIPC_Settings::api_key( 'footballdata' ) );
	}

	private function check_key() {
		if ( '' === TIPC_Settings::api_key( 'footballdata' ) ) {
			return new WP_Error( 'tipc_key', 'football-data.org needs a free API key. Add it under Tipping Comp > Settings.' );
		}
		return true;
	}

	public function fetch_season() {
		$ok = $this->check_key();
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		$data = $this->get_json( add_query_arg( 'season', $this->season, self::BASE ), $this->headers() );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$games = $this->parse( $data );
		if ( empty( $games ) ) {
			return new WP_Error( 'tipc_footballdata', 'football-data.org returned no Premier League matches for the ' . $this->season . '/' . ( $this->season + 1 ) . ' season.' );
		}
		return $games;
	}

	public function fetch_rounds( array $rounds ) {
		$ok = $this->check_key();
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		$rounds = array_unique( array_map( 'intval', $rounds ) );
		// Free tier allows 10 calls a minute; for lots of rounds one season call is cheaper.
		if ( count( $rounds ) > 3 ) {
			return $this->fetch_season();
		}
		$games = array();
		foreach ( $rounds as $round ) {
			$url  = add_query_arg(
				array(
					'season'   => $this->season,
					'matchday' => $round,
				),
				self::BASE
			);
			$data = $this->get_json( $url, $this->headers() );
			if ( is_wp_error( $data ) ) {
				return $data;
			}
			$games = array_merge( $games, $this->parse( $data ) );
		}
		return $games;
	}

	private function parse( $data ) {
		$games = array();
		if ( empty( $data['matches'] ) || ! is_array( $data['matches'] ) ) {
			return $games;
		}
		foreach ( $data['matches'] as $m ) {
			$s = isset( $m['status'] ) ? strtoupper( (string) $m['status'] ) : '';
			switch ( $s ) {
				case 'FINISHED':
				case 'AWARDED':
					$status = 'final';
					break;
				case 'IN_PLAY':
				case 'PAUSED':
				case 'LIVE':
				case 'EXTRA_TIME':
				case 'PENALTY_SHOOTOUT':
					$status = 'live';
					break;
				case 'POSTPONED':
				case 'SUSPENDED':
					$status = 'postponed';
					break;
				case 'CANCELLED':
					$status = 'cancelled';
					break;
				default:
					$status = 'scheduled';
			}

			$winner = null;
			if ( 'final' === $status && ! empty( $m['score']['winner'] ) ) {
				$map    = array(
					'HOME_TEAM' => 'home',
					'AWAY_TEAM' => 'away',
					'DRAW'      => 'draw',
				);
				$winner = isset( $map[ $m['score']['winner'] ] ) ? $map[ $m['score']['winner'] ] : null;
			}

			$round = isset( $m['matchday'] ) ? (int) $m['matchday'] : 0;

			$games[] = array(
				'ext_id'      => isset( $m['id'] ) ? (string) $m['id'] : '',
				'round'       => $round,
				'round_label' => 'Matchweek ' . $round,
				'kickoff'     => isset( $m['utcDate'] ) ? self::to_utc( $m['utcDate'] ) : null,
				'home'        => self::name( isset( $m['homeTeam'] ) ? $m['homeTeam'] : array() ),
				'away'        => self::name( isset( $m['awayTeam'] ) ? $m['awayTeam'] : array() ),
				'home_logo'   => isset( $m['homeTeam']['crest'] ) ? $m['homeTeam']['crest'] : '',
				'away_logo'   => isset( $m['awayTeam']['crest'] ) ? $m['awayTeam']['crest'] : '',
				'venue'       => isset( $m['venue'] ) && is_string( $m['venue'] ) ? $m['venue'] : '',
				'status'      => $status,
				'home_score'  => isset( $m['score']['fullTime']['home'] ) ? $m['score']['fullTime']['home'] : null,
				'away_score'  => isset( $m['score']['fullTime']['away'] ) ? $m['score']['fullTime']['away'] : null,
				'winner'      => $winner,
			);
		}
		return $this->clean( $games );
	}

	private static function name( $team ) {
		foreach ( array( 'shortName', 'name' ) as $k ) {
			if ( ! empty( $team[ $k ] ) ) {
				return $team[ $k ];
			}
		}
		return '';
	}
}
