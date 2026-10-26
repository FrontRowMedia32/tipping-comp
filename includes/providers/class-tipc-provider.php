<?php
/**
 * Base class for fixture/result data providers.
 *
 * Every provider returns games in one normalised shape:
 *   ext_id, round, round_label, kickoff (UTC 'Y-m-d H:i:s'), home, away,
 *   home_logo, away_logo, venue, status (scheduled|live|final|postponed|cancelled),
 *   home_score, away_score, winner (home|away|draw|null)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class TIPC_Provider {

	/** @var string */
	protected $league;

	/** @var int */
	protected $season;

	public function __construct( $league, $season ) {
		$this->league = $league;
		$this->season = (int) $season;
	}

	/**
	 * Fetch the whole season. Returns array of games or WP_Error.
	 */
	abstract public function fetch_season();

	/**
	 * Fetch only some rounds (used for frequent result checks).
	 * Providers that can't do this cheaply fall back to the whole season.
	 */
	public function fetch_rounds( array $rounds ) {
		return $this->fetch_season();
	}

	/**
	 * Build a provider for a league using its settings.
	 */
	public static function make( $league, $season = null ) {
		$conf = TIPC_Settings::league( $league );
		if ( ! $conf ) {
			return new WP_Error( 'tipc_league', 'Unknown league.' );
		}
		$season = null === $season ? (int) $conf['season'] : (int) $season;
		switch ( $conf['provider'] ) {
			case 'squiggle':
				return new TIPC_Provider_Squiggle( $league, $season );
			case 'nrlcom':
				return new TIPC_Provider_NRLcom( $league, $season );
			case 'espn':
				return new TIPC_Provider_ESPN( $league, $season );
			case 'footballdata':
				return new TIPC_Provider_FootballData( $league, $season );
			case 'thesportsdb':
				return new TIPC_Provider_TheSportsDB( $league, $season );
			case 'oddsapi':
				return new TIPC_Provider_OddsAPI( $league, $season );
		}
		return new WP_Error( 'tipc_provider', 'Unknown data provider.' );
	}

	/* ---------- helpers ---------- */

	protected function user_agent() {
		$email = TIPC_Settings::get( 'contact_email' );
		return 'TippingComp/' . TIPC_VERSION . ' (+' . home_url( '/' ) . ( $email ? '; ' . $email : '' ) . ')';
	}

	/**
	 * GET a URL and decode JSON. Returns array or WP_Error.
	 */
	protected function get_json( $url, array $headers = array() ) {
		$response = wp_remote_get(
			$url,
			array(
				'timeout'    => 25,
				'user-agent' => $this->user_agent(),
				'headers'    => array_merge( array( 'Accept' => 'application/json' ), $headers ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'tipc_http', 'Could not reach ' . wp_parse_url( $url, PHP_URL_HOST ) . ': ' . $response->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		if ( $code < 200 || $code >= 300 ) {
			$snippet = trim( wp_strip_all_tags( substr( (string) $body, 0, 200 ) ) );
			return new WP_Error( 'tipc_http_' . $code, sprintf( '%s returned HTTP %d. %s', wp_parse_url( $url, PHP_URL_HOST ), $code, $snippet ) );
		}
		$data = json_decode( $body, true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'tipc_json', 'Unexpected (non-JSON) response from ' . wp_parse_url( $url, PHP_URL_HOST ) . '.' );
		}
		return $data;
	}

	/**
	 * Parse a date string to UTC 'Y-m-d H:i:s'. Strings without a zone are
	 * treated as $assume_tz (default UTC).
	 */
	public static function to_utc( $value, $assume_tz = 'UTC' ) {
		if ( is_numeric( $value ) && (int) $value > 100000000 ) {
			return gmdate( 'Y-m-d H:i:s', (int) $value );
		}
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return null;
		}
		try {
			$tz = ( $assume_tz instanceof DateTimeZone ) ? $assume_tz : new DateTimeZone( $assume_tz );
			$dt = new DateTime( $value, $tz );
			$dt->setTimezone( new DateTimeZone( 'UTC' ) );
			return $dt->format( 'Y-m-d H:i:s' );
		} catch ( Exception $e ) {
			return null;
		}
	}

	/**
	 * Fill in defaults, tidy values and work out the winner.
	 */
	public static function normalise( array $g ) {
		$g = array_merge(
			array(
				'ext_id'      => '',
				'round'       => 0,
				'round_label' => '',
				'kickoff'     => null,
				'home'        => '',
				'away'        => '',
				'home_logo'   => '',
				'away_logo'   => '',
				'venue'       => '',
				'status'      => 'scheduled',
				'home_score'  => null,
				'away_score'  => null,
				'winner'      => null,
			),
			$g
		);

		$g['ext_id']      = substr( (string) $g['ext_id'], 0, 80 );
		$g['round']       = (int) $g['round'];
		$g['round_label'] = substr( trim( (string) $g['round_label'] ), 0, 80 );
		if ( '' === $g['round_label'] ) {
			$g['round_label'] = 'Round ' . $g['round'];
		}
		$g['home']  = substr( trim( wp_strip_all_tags( (string) $g['home'] ) ), 0, 100 );
		$g['away']  = substr( trim( wp_strip_all_tags( (string) $g['away'] ) ), 0, 100 );
		$g['venue'] = substr( trim( wp_strip_all_tags( (string) $g['venue'] ) ), 0, 150 );

		foreach ( array( 'home_logo', 'away_logo' ) as $k ) {
			$url     = (string) $g[ $k ];
			$g[ $k ] = ( 0 === strpos( $url, 'https://' ) || 0 === strpos( $url, 'http://' ) ) ? substr( esc_url_raw( $url ), 0, 255 ) : '';
		}

		$valid = array( 'scheduled', 'live', 'final', 'postponed', 'cancelled' );
		if ( ! in_array( $g['status'], $valid, true ) ) {
			$g['status'] = 'scheduled';
		}

		foreach ( array( 'home_score', 'away_score' ) as $k ) {
			$g[ $k ] = ( null === $g[ $k ] || '' === $g[ $k ] || ! is_numeric( $g[ $k ] ) ) ? null : (int) $g[ $k ];
		}

		if ( 'final' === $g['status'] ) {
			if ( ! in_array( $g['winner'], array( 'home', 'away', 'draw' ), true ) ) {
				if ( null !== $g['home_score'] && null !== $g['away_score'] ) {
					if ( $g['home_score'] > $g['away_score'] ) {
						$g['winner'] = 'home';
					} elseif ( $g['away_score'] > $g['home_score'] ) {
						$g['winner'] = 'away';
					} else {
						$g['winner'] = 'draw';
					}
				} else {
					// Final without scores or a winner: can't score it yet.
					$g['status'] = 'live';
					$g['winner'] = null;
				}
			}
		} else {
			$g['winner'] = null;
		}

		return $g;
	}

	/**
	 * Keep only games with the essentials.
	 */
	protected function clean( array $games ) {
		$out = array();
		foreach ( $games as $g ) {
			$g = self::normalise( $g );
			if ( '' === $g['ext_id'] || '' === $g['home'] || '' === $g['away'] || empty( $g['kickoff'] ) ) {
				continue;
			}
			$out[ $g['ext_id'] ] = $g;
		}
		return array_values( $out );
	}
}
