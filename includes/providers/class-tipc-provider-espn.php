<?php
/**
 * NFL via ESPN's public scoreboard JSON (free, unofficial, no key).
 * https://site.api.espn.com/apis/site/v2/sports/football/nfl/scoreboard?dates=2026&seasontype=2&week=1
 *
 * Rounds: regular-season weeks 1-18 are rounds 1-18; the playoffs are
 * 19 Wild Card, 20 Divisional, 21 Conference Championships, 22 Super Bowl.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TIPC_Provider_ESPN extends TIPC_Provider {

	const BASE = 'https://site.api.espn.com/apis/site/v2/sports/football/nfl/scoreboard';

	/** Playoff ESPN week => our round and label. Week 4 (Pro Bowl) is skipped. */
	private static $playoffs = array(
		1 => array( 19, 'Wild Card' ),
		2 => array( 20, 'Divisional Round' ),
		3 => array( 21, 'Conference Championships' ),
		5 => array( 22, 'Super Bowl' ),
	);

	private function url( $seasontype, $week ) {
		return add_query_arg(
			array(
				'dates'      => $this->season,
				'seasontype' => (int) $seasontype,
				'week'       => (int) $week,
				'limit'      => 100,
			),
			self::BASE
		);
	}

	/**
	 * Map one of our rounds back to an ESPN season type + week.
	 */
	private static function espn_week( $round ) {
		$round = (int) $round;
		if ( $round >= 1 && $round <= 18 ) {
			return array( 2, $round );
		}
		foreach ( self::$playoffs as $week => $info ) {
			if ( $info[0] === $round ) {
				return array( 3, $week );
			}
		}
		return null;
	}

	public function fetch_season() {
		$games = array();
		for ( $week = 1; $week <= 18; $week++ ) {
			$data = $this->get_json( $this->url( 2, $week ) );
			if ( is_wp_error( $data ) ) {
				return $data;
			}
			$games = array_merge( $games, $this->parse( $data, $week, 'Week ' . $week ) );
		}
		foreach ( self::$playoffs as $week => $info ) {
			$data = $this->get_json( $this->url( 3, $week ) );
			if ( is_wp_error( $data ) ) {
				continue; // Playoff weeks may not exist yet.
			}
			$games = array_merge( $games, $this->parse( $data, $info[0], $info[1] ) );
		}
		$games = $this->clean( $games );
		if ( empty( $games ) ) {
			return new WP_Error( 'tipc_espn', 'ESPN returned no NFL games for the ' . $this->season . ' season.' );
		}
		return $games;
	}

	public function fetch_rounds( array $rounds ) {
		$games = array();
		foreach ( array_unique( array_map( 'intval', $rounds ) ) as $round ) {
			$map = self::espn_week( $round );
			if ( ! $map ) {
				continue;
			}
			$data = $this->get_json( $this->url( $map[0], $map[1] ) );
			if ( is_wp_error( $data ) ) {
				return $data;
			}
			$label = 2 === $map[0] ? 'Week ' . $round : self::$playoffs[ $map[1] ][1];
			$games = array_merge( $games, $this->parse( $data, $round, $label ) );
		}
		return $this->clean( $games );
	}

	private function parse( $data, $round, $label ) {
		$games = array();
		if ( empty( $data['events'] ) || ! is_array( $data['events'] ) ) {
			return $games;
		}
		foreach ( $data['events'] as $e ) {
			$comp = isset( $e['competitions'][0] ) ? $e['competitions'][0] : array();
			$home = array();
			$away = array();
			foreach ( isset( $comp['competitors'] ) ? (array) $comp['competitors'] : array() as $c ) {
				if ( isset( $c['homeAway'] ) && 'home' === $c['homeAway'] ) {
					$home = $c;
				} elseif ( isset( $c['homeAway'] ) && 'away' === $c['homeAway'] ) {
					$away = $c;
				}
			}
			if ( empty( $home ) || empty( $away ) ) {
				continue;
			}

			$type   = isset( $e['status']['type'] ) ? $e['status']['type'] : ( isset( $comp['status']['type'] ) ? $comp['status']['type'] : array() );
			$name   = strtoupper( isset( $type['name'] ) ? (string) $type['name'] : '' );
			$state  = isset( $type['state'] ) ? (string) $type['state'] : 'pre';
			$done   = ! empty( $type['completed'] );
			$status = 'scheduled';
			if ( false !== strpos( $name, 'POSTPONED' ) ) {
				$status = 'postponed';
			} elseif ( false !== strpos( $name, 'CANCEL' ) ) {
				$status = 'cancelled';
			} elseif ( 'post' === $state && $done ) {
				$status = 'final';
			} elseif ( 'in' === $state || 'post' === $state ) {
				$status = 'live';
			}

			$winner = null;
			if ( 'final' === $status ) {
				if ( ! empty( $home['winner'] ) ) {
					$winner = 'home';
				} elseif ( ! empty( $away['winner'] ) ) {
					$winner = 'away';
				}
			}

			$games[] = array(
				'ext_id'      => isset( $e['id'] ) ? (string) $e['id'] : '',
				'round'       => (int) $round,
				'round_label' => $label,
				'kickoff'     => isset( $e['date'] ) ? self::to_utc( $e['date'] ) : null,
				'home'        => self::team( $home, 'displayName' ),
				'away'        => self::team( $away, 'displayName' ),
				'home_logo'   => self::team( $home, 'logo' ),
				'away_logo'   => self::team( $away, 'logo' ),
				'venue'       => isset( $comp['venue']['fullName'] ) ? $comp['venue']['fullName'] : '',
				'status'      => $status,
				'home_score'  => 'scheduled' === $status ? null : self::score( $home ),
				'away_score'  => 'scheduled' === $status ? null : self::score( $away ),
				'winner'      => $winner,
			);
		}
		return $games;
	}

	private static function score( $c ) {
		if ( ! isset( $c['score'] ) ) {
			return null;
		}
		$s = $c['score'];
		if ( is_array( $s ) ) {
			$s = isset( $s['value'] ) ? $s['value'] : ( isset( $s['displayValue'] ) ? $s['displayValue'] : null );
		}
		return is_numeric( $s ) ? (int) $s : null;
	}

	private static function team( $c, $field ) {
		if ( isset( $c['team'][ $field ] ) && is_string( $c['team'][ $field ] ) ) {
			return $c['team'][ $field ];
		}
		if ( 'displayName' === $field && isset( $c['team']['name'] ) ) {
			return $c['team']['name'];
		}
		return '';
	}
}
