<?php
/**
 * NRL via the JSON feed behind the nrl.com draw page (free, unofficial).
 * https://www.nrl.com/draw/data?competition=111&season=2026&round=1
 *
 * This feed is not a documented API and can change without notice, so the
 * parser accepts several field-name variants and fails loudly if nothing
 * usable comes back. TheSportsDB is the fallback provider for NRL.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TIPC_Provider_NRLcom extends TIPC_Provider {

	const BASE        = 'https://www.nrl.com/draw/data';
	const COMPETITION = 111; // NRL Telstra Premiership.

	private function url( $round = null ) {
		$args = array(
			'competition' => self::COMPETITION,
			'season'      => $this->season,
		);
		if ( null !== $round ) {
			$args['round'] = (int) $round;
		}
		return add_query_arg( $args, self::BASE );
	}

	public function fetch_season() {
		$first = $this->get_json( $this->url( 1 ) );
		if ( is_wp_error( $first ) ) {
			return $first;
		}

		$rounds = array();
		if ( ! empty( $first['filterRounds'] ) && is_array( $first['filterRounds'] ) ) {
			foreach ( $first['filterRounds'] as $r ) {
				$v = isset( $r['value'] ) ? $r['value'] : ( isset( $r['roundNumber'] ) ? $r['roundNumber'] : null );
				if ( is_numeric( $v ) ) {
					$rounds[] = (int) $v;
				}
			}
		}
		if ( empty( $rounds ) ) {
			$rounds = range( 1, 31 ); // 27 rounds + 4 finals weeks.
		}

		$games = $this->parse( $first, 1 );
		foreach ( $rounds as $round ) {
			if ( 1 === $round ) {
				continue;
			}
			$data = $this->get_json( $this->url( $round ) );
			if ( is_wp_error( $data ) ) {
				// A missing finals round early in the season isn't fatal.
				if ( ! empty( $games ) && $round > 27 ) {
					continue;
				}
				return $data;
			}
			$games = array_merge( $games, $this->parse( $data, $round ) );
		}

		$games = $this->clean( $games );
		if ( empty( $games ) ) {
			return new WP_Error( 'tipc_nrlcom', 'NRL.com returned no fixtures for ' . $this->season . '. The feed may have changed: try TheSportsDB as the NRL provider.' );
		}
		return $games;
	}

	public function fetch_rounds( array $rounds ) {
		$games = array();
		foreach ( array_unique( array_map( 'intval', $rounds ) ) as $round ) {
			$data = $this->get_json( $this->url( $round ) );
			if ( is_wp_error( $data ) ) {
				return $data;
			}
			$games = array_merge( $games, $this->parse( $data, $round ) );
		}
		return $this->clean( $games );
	}

	private static function pick( array $arr, array $keys ) {
		foreach ( $keys as $k ) {
			if ( isset( $arr[ $k ] ) && '' !== $arr[ $k ] && null !== $arr[ $k ] ) {
				return $arr[ $k ];
			}
		}
		return null;
	}

	private static function team_name( $team ) {
		if ( ! is_array( $team ) ) {
			return '';
		}
		$name = self::pick( $team, array( 'nickName', 'nickname', 'name', 'teamName', 'displayName' ) );
		return $name ? (string) $name : '';
	}

	private static function team_logo( $team ) {
		if ( ! is_array( $team ) ) {
			return '';
		}
		$logo = '';
		if ( ! empty( $team['logo'] ) && is_string( $team['logo'] ) ) {
			$logo = $team['logo'];
		} elseif ( ! empty( $team['theme']['logos'] ) && is_array( $team['theme']['logos'] ) ) {
			$logos = $team['theme']['logos'];
			foreach ( array( 'badge.svg', 'badge.png', 'badge-basic24.svg' ) as $pref ) {
				if ( ! empty( $logos[ $pref ] ) && is_string( $logos[ $pref ] ) ) {
					$logo = $logos[ $pref ];
					break;
				}
			}
			if ( '' === $logo ) {
				foreach ( $logos as $l ) {
					if ( is_string( $l ) ) {
						$logo = $l;
						break;
					}
				}
			}
		}
		if ( '' !== $logo && 0 === strpos( $logo, '/' ) ) {
			$logo = 'https://www.nrl.com' . $logo;
		}
		return $logo;
	}

	private function parse( $data, $round ) {
		$fixtures = array();
		if ( isset( $data['fixtures'] ) && is_array( $data['fixtures'] ) ) {
			$fixtures = $data['fixtures'];
		} elseif ( isset( $data['matches'] ) && is_array( $data['matches'] ) ) {
			$fixtures = $data['matches'];
		}

		$games = array();
		foreach ( $fixtures as $f ) {
			if ( ! is_array( $f ) || ( isset( $f['type'] ) && 'Match' !== $f['type'] ) ) {
				continue; // Byes etc.
			}
			$home = isset( $f['homeTeam'] ) ? $f['homeTeam'] : array();
			$away = isset( $f['awayTeam'] ) ? $f['awayTeam'] : array();

			$kick = null;
			if ( isset( $f['clock'] ) && is_array( $f['clock'] ) ) {
				$kick = self::pick( $f['clock'], array( 'kickOffTimeLong', 'kickOffTime' ) );
			}
			if ( ! $kick ) {
				$kick = self::pick( $f, array( 'kickOffTimeLong', 'kickOffTime', 'startTime', 'matchStartTime' ) );
			}

			$mode  = strtolower( (string) self::pick( $f, array( 'matchMode' ) ) );
			$state = strtolower( str_replace( array( ' ', '_', '-' ), '', (string) self::pick( $f, array( 'matchState', 'status' ) ) ) );

			$status = 'scheduled';
			if ( false !== strpos( $state, 'postpon' ) ) {
				$status = 'postponed';
			} elseif ( false !== strpos( $state, 'cancel' ) || false !== strpos( $state, 'abandon' ) ) {
				$status = 'cancelled';
			} elseif ( 'post' === $mode || in_array( $state, array( 'fulltime', 'final', 'complete', 'completed', 'fulltimeextratime' ), true ) ) {
				$status = 'final';
			} elseif ( in_array( $mode, array( 'live', 'current' ), true ) || ( '' !== $state && ! in_array( $state, array( 'upcoming', 'pre', 'prematch', 'scheduled' ), true ) ) ) {
				$status = 'live';
			}

			$ext = self::pick( $f, array( 'matchId', 'id' ) );
			if ( ! $ext ) {
				$url = self::pick( $f, array( 'matchCentreUrl', 'url' ) );
				$ext = $url ? 'u' . md5( (string) $url ) : '';
			}

			$games[] = array(
				'ext_id'      => (string) $ext,
				'round'       => (int) $round,
				'round_label' => (string) self::pick( $f, array( 'roundTitle', 'roundName' ) ),
				'kickoff'     => $kick ? self::to_utc( $kick ) : null,
				'home'        => self::team_name( $home ),
				'away'        => self::team_name( $away ),
				'home_logo'   => self::team_logo( $home ),
				'away_logo'   => self::team_logo( $away ),
				'venue'       => (string) self::pick( $f, array( 'venue', 'venueName' ) ),
				'status'      => $status,
				'home_score'  => 'scheduled' === $status ? null : ( is_array( $home ) && isset( $home['score'] ) ? $home['score'] : null ),
				'away_score'  => 'scheduled' === $status ? null : ( is_array( $away ) && isset( $away['score'] ) ? $away['score'] : null ),
			);
		}
		return $games;
	}
}
