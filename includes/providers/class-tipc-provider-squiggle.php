<?php
/**
 * AFL via the Squiggle API (free, no key). https://api.squiggle.com.au/
 * Squiggle asks API users to send a User-Agent with contact details, which
 * the base class does using the contact email from settings.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TIPC_Provider_Squiggle extends TIPC_Provider {

	const BASE = 'https://api.squiggle.com.au/';

	public function fetch_season() {
		$data = $this->get_json( self::BASE . '?q=games;year=' . $this->season );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		return $this->parse( $data );
	}

	public function fetch_rounds( array $rounds ) {
		$all = array();
		foreach ( array_unique( array_map( 'intval', $rounds ) ) as $round ) {
			$data = $this->get_json( self::BASE . '?q=games;year=' . $this->season . ';round=' . $round );
			if ( is_wp_error( $data ) ) {
				return $data;
			}
			$parsed = $this->parse( $data );
			if ( is_wp_error( $parsed ) ) {
				return $parsed;
			}
			$all = array_merge( $all, $parsed );
		}
		return $all;
	}

	/**
	 * Team lookup (id => name/logo), cached for a week.
	 */
	private function teams() {
		$cached = get_transient( 'tipc_squiggle_teams' );
		if ( is_array( $cached ) && ! empty( $cached ) ) {
			return $cached;
		}
		$data  = $this->get_json( self::BASE . '?q=teams' );
		$teams = array();
		if ( ! is_wp_error( $data ) && ! empty( $data['teams'] ) ) {
			foreach ( $data['teams'] as $t ) {
				if ( empty( $t['id'] ) ) {
					continue;
				}
				$logo = isset( $t['logo'] ) ? (string) $t['logo'] : '';
				if ( '' !== $logo && 0 === strpos( $logo, '/' ) ) {
					$logo = 'https://squiggle.com.au' . $logo;
				}
				$teams[ (int) $t['id'] ] = array(
					'name' => isset( $t['name'] ) ? $t['name'] : '',
					'logo' => $logo,
				);
			}
			set_transient( 'tipc_squiggle_teams', $teams, WEEK_IN_SECONDS );
		}
		return $teams;
	}

	private function parse( $data ) {
		if ( ! isset( $data['games'] ) || ! is_array( $data['games'] ) ) {
			return new WP_Error( 'tipc_squiggle', 'Squiggle response had no games list.' );
		}
		$teams = $this->teams();
		$games = array();

		foreach ( $data['games'] as $g ) {
			$hid  = isset( $g['hteamid'] ) ? (int) $g['hteamid'] : ( isset( $g['hteam'] ) && is_numeric( $g['hteam'] ) ? (int) $g['hteam'] : 0 );
			$aid  = isset( $g['ateamid'] ) ? (int) $g['ateamid'] : ( isset( $g['ateam'] ) && is_numeric( $g['ateam'] ) ? (int) $g['ateam'] : 0 );
			$home = ( isset( $g['hteam'] ) && ! is_numeric( $g['hteam'] ) ) ? $g['hteam'] : ( isset( $teams[ $hid ] ) ? $teams[ $hid ]['name'] : '' );
			$away = ( isset( $g['ateam'] ) && ! is_numeric( $g['ateam'] ) ) ? $g['ateam'] : ( isset( $teams[ $aid ] ) ? $teams[ $aid ]['name'] : '' );

			// Kickoff: unixtime is cleanest; otherwise local date + tz offset.
			$kickoff = null;
			if ( ! empty( $g['unixtime'] ) ) {
				$kickoff = self::to_utc( (int) $g['unixtime'] );
			} elseif ( ! empty( $g['date'] ) ) {
				$tz = 'UTC';
				if ( ! empty( $g['tz'] ) && preg_match( '/^[+-]\d{2}:\d{2}$/', $g['tz'] ) && ! preg_match( '/(Z|[+-]\d{2}:?\d{2})$/', $g['date'] ) ) {
					$tz = $g['tz'];
				}
				$kickoff = self::to_utc( $g['date'], $tz );
			}

			$complete = isset( $g['complete'] ) ? (int) $g['complete'] : 0;
			$status   = 'scheduled';
			if ( $complete >= 100 ) {
				$status = 'final';
			} elseif ( $complete > 0 ) {
				$status = 'live';
			}

			$round = isset( $g['round'] ) ? (int) $g['round'] : 0;
			$label = ! empty( $g['roundname'] ) ? $g['roundname'] : ( 0 === $round ? 'Opening Round' : 'Round ' . $round );

			$games[] = array(
				'ext_id'      => isset( $g['id'] ) ? (string) $g['id'] : '',
				'round'       => $round,
				'round_label' => $label,
				'kickoff'     => $kickoff,
				'home'        => $home,
				'away'        => $away,
				'home_logo'   => isset( $teams[ $hid ] ) ? $teams[ $hid ]['logo'] : '',
				'away_logo'   => isset( $teams[ $aid ] ) ? $teams[ $aid ]['logo'] : '',
				'venue'       => isset( $g['venue'] ) ? $g['venue'] : '',
				'status'      => $status,
				'home_score'  => 'scheduled' === $status ? null : ( isset( $g['hscore'] ) ? $g['hscore'] : null ),
				'away_score'  => 'scheduled' === $status ? null : ( isset( $g['ascore'] ) ? $g['ascore'] : null ),
			);
		}

		return $this->clean( $games );
	}
}
