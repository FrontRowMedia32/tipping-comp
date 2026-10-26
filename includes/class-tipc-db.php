<?php
/**
 * Database tables and queries.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TIPC_DB {

	public static function games_table() {
		global $wpdb;
		return $wpdb->prefix . 'tipc_games';
	}

	public static function tips_table() {
		global $wpdb;
		return $wpdb->prefix . 'tipc_tips';
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$games   = self::games_table();
		$tips    = self::tips_table();

		dbDelta(
			"CREATE TABLE {$games} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  league varchar(10) NOT NULL,
  season smallint(5) unsigned NOT NULL,
  ext_id varchar(80) NOT NULL,
  round smallint(6) NOT NULL DEFAULT 0,
  round_label varchar(80) NOT NULL DEFAULT '',
  kickoff datetime NOT NULL,
  home varchar(100) NOT NULL DEFAULT '',
  away varchar(100) NOT NULL DEFAULT '',
  home_logo varchar(255) NOT NULL DEFAULT '',
  away_logo varchar(255) NOT NULL DEFAULT '',
  venue varchar(150) NOT NULL DEFAULT '',
  status varchar(12) NOT NULL DEFAULT 'scheduled',
  home_score smallint(6) DEFAULT NULL,
  away_score smallint(6) DEFAULT NULL,
  winner varchar(5) DEFAULT NULL,
  manual tinyint(1) NOT NULL DEFAULT 0,
  odds_id varchar(64) NOT NULL DEFAULT '',
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY league_ext (league,season,ext_id),
  KEY league_round (league,season,round),
  KEY kickoff (kickoff)
) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$tips} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  game_id bigint(20) unsigned NOT NULL,
  pick varchar(5) NOT NULL,
  margin smallint(6) DEFAULT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY user_game (user_id,game_id),
  KEY game_id (game_id)
) {$charset};"
		);

		update_option( 'tipc_db_version', TIPC_DB_VERSION );
	}

	/* ---------- Games ---------- */

	public static function get_game( $id ) {
		global $wpdb;
		$t = self::games_table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ) );
	}

	public static function find_game( $league, $season, $ext_id ) {
		global $wpdb;
		$t = self::games_table();
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$t} WHERE league = %s AND season = %d AND ext_id = %s", $league, $season, $ext_id )
		);
	}

	public static function games( $league, $season, $round = null ) {
		global $wpdb;
		$t = self::games_table();
		if ( null === $round ) {
			return $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM {$t} WHERE league = %s AND season = %d ORDER BY kickoff ASC, id ASC", $league, $season )
			);
		}
		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$t} WHERE league = %s AND season = %d AND round = %d ORDER BY kickoff ASC, id ASC", $league, $season, $round )
		);
	}

	public static function count_games( $league, $season ) {
		global $wpdb;
		$t = self::games_table();
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE league = %s AND season = %d", $league, $season ) );
	}

	/**
	 * Rounds with their label, first kickoff and game counts.
	 */
	public static function rounds( $league, $season ) {
		global $wpdb;
		$t    = self::games_table();
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT round, MAX(round_label) AS label, MIN(kickoff) AS first_kickoff, MAX(kickoff) AS last_kickoff,
				        COUNT(*) AS games, SUM(CASE WHEN status = 'final' THEN 1 ELSE 0 END) AS finals
				 FROM {$t} WHERE league = %s AND season = %d GROUP BY round ORDER BY round ASC",
				$league,
				$season
			)
		);
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$out[ (int) $r->round ] = array(
				'round'         => (int) $r->round,
				'label'         => '' !== (string) $r->label ? $r->label : 'Round ' . (int) $r->round,
				'first_kickoff' => $r->first_kickoff,
				'last_kickoff'  => $r->last_kickoff,
				'games'         => (int) $r->games,
				'finals'        => (int) $r->finals,
			);
		}
		return $out;
	}

	/**
	 * The round people should be tipping now: the round of the next game
	 * that hasn't finished, otherwise the last round.
	 */
	public static function current_round( $league, $season ) {
		global $wpdb;
		$t     = self::games_table();
		$round = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT round FROM {$t}
				 WHERE league = %s AND season = %d AND status IN ('scheduled','live')
				 ORDER BY kickoff ASC, id ASC LIMIT 1",
				$league,
				$season
			)
		);
		if ( null === $round ) {
			$round = $wpdb->get_var(
				$wpdb->prepare( "SELECT MAX(round) FROM {$t} WHERE league = %s AND season = %d", $league, $season )
			);
		}
		return null === $round ? null : (int) $round;
	}

	/**
	 * Rounds with games that are under way or awaiting a result.
	 */
	public static function active_rounds( $league, $season ) {
		global $wpdb;
		$t     = self::games_table();
		$now   = time();
		$from  = gmdate( 'Y-m-d H:i:s', $now - 3 * DAY_IN_SECONDS );
		$until = gmdate( 'Y-m-d H:i:s', $now + 20 * MINUTE_IN_SECONDS );
		$rows  = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT round FROM {$t}
				 WHERE league = %s AND season = %d AND status IN ('scheduled','live')
				   AND kickoff BETWEEN %s AND %s",
				$league,
				$season,
				$from,
				$until
			)
		);
		return array_map( 'intval', (array) $rows );
	}

	/**
	 * The first game of each round (used for the margin tiebreaker).
	 * Returns round => game id.
	 */
	public static function first_games( $league, $season ) {
		$first = array();
		foreach ( self::games( $league, $season ) as $g ) {
			if ( 'cancelled' === $g->status ) {
				continue;
			}
			$r = (int) $g->round;
			if ( ! isset( $first[ $r ] ) ) {
				$first[ $r ] = (int) $g->id; // games() is ordered by kickoff, id.
			}
		}
		return $first;
	}

	public static function delete_season( $league, $season ) {
		global $wpdb;
		$g = self::games_table();
		$t = self::tips_table();
		$wpdb->query(
			$wpdb->prepare( "DELETE FROM {$t} WHERE game_id IN (SELECT id FROM {$g} WHERE league = %s AND season = %d)", $league, $season )
		);
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$g} WHERE league = %s AND season = %d", $league, $season ) );
	}

	/* ---------- Tips ---------- */

	/**
	 * A user's tips for a list of game ids: game_id => row.
	 */
	public static function user_tips( $user_id, array $game_ids ) {
		global $wpdb;
		$game_ids = array_filter( array_map( 'intval', $game_ids ) );
		if ( ! $user_id || empty( $game_ids ) ) {
			return array();
		}
		$t    = self::tips_table();
		$in   = implode( ',', $game_ids );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE user_id = %d AND game_id IN ({$in})", $user_id ) );
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$out[ (int) $r->game_id ] = $r;
		}
		return $out;
	}

	public static function save_tip( $user_id, $game_id, $pick, $margin = null ) {
		global $wpdb;
		$t        = self::tips_table();
		$now      = current_time( 'mysql', true );
		$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE user_id = %d AND game_id = %d", $user_id, $game_id ) );
		$data     = array(
			'pick'       => $pick,
			'margin'     => $margin,
			'updated_at' => $now,
		);
		if ( $existing ) {
			return false !== $wpdb->update( $t, $data, array( 'id' => (int) $existing ) );
		}
		$data['user_id'] = (int) $user_id;
		$data['game_id'] = (int) $game_id;
		return false !== $wpdb->insert( $t, $data );
	}

	/**
	 * All tips for a league season, for scoring.
	 */
	public static function season_tips( $league, $season ) {
		global $wpdb;
		$g = self::games_table();
		$t = self::tips_table();
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT t.user_id, t.game_id, t.pick, t.margin
				 FROM {$t} t INNER JOIN {$g} g ON g.id = t.game_id
				 WHERE g.league = %s AND g.season = %d",
				$league,
				$season
			)
		);
	}

	public static function tip_counts( array $game_ids ) {
		global $wpdb;
		$game_ids = array_filter( array_map( 'intval', $game_ids ) );
		if ( empty( $game_ids ) ) {
			return array();
		}
		$t    = self::tips_table();
		$in   = implode( ',', $game_ids );
		$rows = $wpdb->get_results( "SELECT game_id, pick, COUNT(*) AS n FROM {$t} WHERE game_id IN ({$in}) GROUP BY game_id, pick" ); // phpcs:ignore
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$out[ (int) $r->game_id ][ $r->pick ] = (int) $r->n;
		}
		return $out;
	}
}
