<?php
/**
 * Settings: league definitions, defaults and accessors.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TIPC_Settings {

	const OPTION = 'tipc_settings';

	/**
	 * Static information about each supported league.
	 */
	public static function leagues() {
		return array(
			'nrl' => array(
				'name'      => 'NRL',
				'providers' => array(
					'nrlcom'      => 'NRL.com draw feed (free, unofficial)',
					'thesportsdb' => 'TheSportsDB (premium key)',
				),
				'provider'  => 'nrlcom',
				'draw_rule' => 'everyone',
			),
			'afl' => array(
				'name'      => 'AFL',
				'providers' => array(
					'squiggle'    => 'Squiggle (free)',
					'thesportsdb' => 'TheSportsDB (premium key)',
				),
				'provider'  => 'squiggle',
				'draw_rule' => 'everyone',
			),
			'epl' => array(
				'name'      => 'English Premier League',
				'providers' => array(
					'footballdata' => 'football-data.org (free key)',
					'thesportsdb'  => 'TheSportsDB (premium key)',
				),
				'provider'  => 'footballdata',
				'draw_rule' => 'draw_pick',
			),
			'nfl' => array(
				'name'      => 'NFL',
				'providers' => array(
					'oddsapi'     => 'The Odds API (draw and results)',
					'espn'        => 'ESPN scoreboard (free, unofficial)',
					'thesportsdb' => 'TheSportsDB (premium key)',
				),
				'provider'  => 'oddsapi',
				'draw_rule' => 'everyone',
			),
		);
	}

	/**
	 * Labels for draw rules.
	 */
	public static function draw_rules() {
		return array(
			'everyone'  => 'Draw: everyone who tipped the game gets the point',
			'nobody'    => 'Draw: nobody gets a point',
			'draw_pick' => 'Draw is a tip option: only "Draw" tips score',
		);
	}

	/**
	 * Sensible default season for a league as of today.
	 */
	public static function default_season( $league ) {
		$year  = (int) wp_date( 'Y' );
		$month = (int) wp_date( 'n' );
		if ( 'epl' === $league ) {
			return $month >= 7 ? $year : $year - 1; // 2026 = 2026/27 season.
		}
		if ( 'nfl' === $league ) {
			return $month >= 3 ? $year : $year - 1;
		}
		return $year;
	}

	public static function defaults() {
		$leagues = array();
		foreach ( self::leagues() as $key => $info ) {
			$leagues[ $key ] = array(
				'enabled'     => 0,
				'label'       => $info['name'] . ' Tipping',
				'season'      => self::default_season( $key ),
				'provider'    => $info['provider'],
				'draw_rule'   => $info['draw_rule'],
				'missed_tips' => 'none',
				'margin'         => 1,
				'points'         => 1,
				'results_source' => 'feed',
			);
		}
		return array(
			'leagues'             => $leagues,
			'keys'                => array(
				'oddsapi'      => '',
				'footballdata' => '',
				'thesportsdb'  => '',
				'github'       => '',
			),
			'update_repo'         => '',
			'contact_email'       => get_option( 'admin_email' ),
			'show_logos'          => 1,
			'purge_cache'         => 1,
			'cron_key'            => wp_generate_password( 24, false, false ),
			'delete_on_uninstall' => 0,
		);
	}

	public static function ensure_defaults() {
		$current = get_option( self::OPTION );
		if ( ! is_array( $current ) ) {
			add_option( self::OPTION, self::defaults() );
		} else {
			update_option( self::OPTION, self::merge( self::defaults(), $current ) );
		}
	}

	/**
	 * Recursive merge where saved values win over defaults.
	 */
	private static function merge( $defaults, $saved ) {
		foreach ( $defaults as $k => $v ) {
			if ( ! array_key_exists( $k, $saved ) ) {
				$saved[ $k ] = $v;
			} elseif ( is_array( $v ) && is_array( $saved[ $k ] ) ) {
				$saved[ $k ] = self::merge( $v, $saved[ $k ] );
			}
		}
		return $saved;
	}

	private static $cache = null;

	public static function flush() {
		self::$cache = null;
	}

	public static function all() {
		if ( null === self::$cache ) {
			$saved       = get_option( self::OPTION );
			self::$cache = self::merge( self::defaults(), is_array( $saved ) ? $saved : array() );
		}
		return self::$cache;
	}

	public static function get( $key, $fallback = null ) {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $fallback;
	}

	public static function league( $league ) {
		$all = self::all();
		return isset( $all['leagues'][ $league ] ) ? $all['leagues'][ $league ] : null;
	}

	public static function is_valid_league( $league ) {
		return array_key_exists( $league, self::leagues() );
	}

	public static function enabled_leagues() {
		$out = array();
		foreach ( self::all()['leagues'] as $key => $conf ) {
			if ( ! empty( $conf['enabled'] ) && self::is_valid_league( $key ) ) {
				$out[ $key ] = $conf;
			}
		}
		return $out;
	}

	public static function api_key( $provider ) {
		$keys = self::get( 'keys', array() );
		return isset( $keys[ $provider ] ) ? trim( (string) $keys[ $provider ] ) : '';
	}

	/**
	 * Sanitize the settings form submission.
	 */
	public static function sanitize( $input ) {
		$defaults = self::defaults();
		$current  = self::all();
		$out      = $current;
		$input    = is_array( $input ) ? $input : array();

		$out['contact_email']       = isset( $input['contact_email'] ) ? sanitize_email( $input['contact_email'] ) : $current['contact_email'];
		$out['show_logos']          = empty( $input['show_logos'] ) ? 0 : 1;
		$out['purge_cache']         = empty( $input['purge_cache'] ) ? 0 : 1;
		$out['delete_on_uninstall'] = empty( $input['delete_on_uninstall'] ) ? 0 : 1;
		if ( isset( $input['update_repo'] ) ) {
			$out['update_repo'] = sanitize_text_field( $input['update_repo'] );
		}

		foreach ( array_keys( $defaults['keys'] ) as $provider ) {
			if ( isset( $input['keys'][ $provider ] ) ) {
				$out['keys'][ $provider ] = sanitize_text_field( $input['keys'][ $provider ] );
			}
		}

		if ( ! empty( $input['regenerate_cron_key'] ) ) {
			$out['cron_key'] = wp_generate_password( 24, false, false );
		}

		$leagues = self::leagues();
		$rules   = self::draw_rules();
		foreach ( $leagues as $key => $info ) {
			$in = isset( $input['leagues'][ $key ] ) && is_array( $input['leagues'][ $key ] ) ? $input['leagues'][ $key ] : array();
			$l  = $current['leagues'][ $key ];

			$l['enabled']     = empty( $in['enabled'] ) ? 0 : 1;
			$l['label']       = isset( $in['label'] ) && '' !== trim( $in['label'] ) ? sanitize_text_field( $in['label'] ) : $defaults['leagues'][ $key ]['label'];
			$season           = isset( $in['season'] ) ? absint( $in['season'] ) : $l['season'];
			$l['season']      = ( $season >= 2000 && $season <= 2100 ) ? $season : $l['season'];
			$l['provider']    = isset( $in['provider'], $info['providers'][ $in['provider'] ] ) ? $in['provider'] : $l['provider'];
			$l['draw_rule']   = isset( $in['draw_rule'], $rules[ $in['draw_rule'] ] ) ? $in['draw_rule'] : $l['draw_rule'];
			$l['missed_tips'] = ( isset( $in['missed_tips'] ) && 'away' === $in['missed_tips'] ) ? 'away' : 'none';
			$l['margin']      = empty( $in['margin'] ) ? 0 : 1;
			$points           = isset( $in['points'] ) ? absint( $in['points'] ) : 1;
			$l['points']      = max( 1, min( 100, $points ) );
			$l['results_source'] = ( isset( $in['results_source'] ) && 'oddsapi' === $in['results_source'] ) ? 'oddsapi' : 'feed';

			$out['leagues'][ $key ] = $l;
		}

		return $out;
	}
}
